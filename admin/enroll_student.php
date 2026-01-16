<?php
/**
 * Enroll Student Page - Dedicated page for enrolling new students
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/enroll_student.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Enroll New User');
$PAGE->set_heading('Enroll New User');
$PAGE->set_pagelayout('base');

// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_user'])) {
    $user_id = intval($_POST['user_id']);
    $course_id = intval($_POST['course_id']);
    $user_role = !empty($_POST['user_role']) ? s($_POST['user_role']) : '';
    $cohort_id = !empty($_POST['cohort_id']) ? intval($_POST['cohort_id']) : 0;
    $enrollment_method = s($_POST['enrollment_method']);
    $enrollment_duration = intval($_POST['enrollment_duration']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // Variables to track enrollment results
    $success_messages = [];
    $cohort_enrolled = false;
    $individual_enrolled = false;
    
    // Get the enrollment instance for this course
    $enrol_instance = $DB->get_record('enrol', [
        'courseid' => $course_id,
        'enrol' => 'manual',
        'status' => 0
    ]);
    
    if (!$enrol_instance) {
        $success_message = "No manual enrollment method found for this course.";
        $message_type = "error";
    } else {
        // First: Handle cohort enrollment if cohort is selected
        if ($cohort_id > 0 && $course_id) {
            // Get all members of the cohort
            $cohort_members = $DB->get_records('cohort_members', ['cohortid' => $cohort_id]);
            
            if (empty($cohort_members)) {
                $success_messages[] = "Selected cohort has no members.";
            } else {
                $enrolled_count = 0;
                $already_enrolled_count = 0;
                $students_enrolled = 0;
                $teachers_enrolled = 0;
                $course_context = context_course::instance($course_id);
                
                foreach ($cohort_members as $member) {
                    // Check if already enrolled
                    $existing = $DB->get_record('user_enrolments', [
                        'userid' => $member->userid,
                        'enrolid' => $enrol_instance->id
                    ]);
                    
                    if ($existing) {
                        $already_enrolled_count++;
                        continue;
                    }
                    
                    // Get user's role (check if student or teacher)
                    $user_roles = $DB->get_records_sql("
                        SELECT DISTINCT r.shortname
                        FROM {role_assignments} ra
                        JOIN {role} r ON ra.roleid = r.id
                        WHERE ra.userid = ?
                        AND r.shortname IN ('student', 'editingteacher')
                    ", [$member->userid]);
                    
                    // Determine the primary role (prefer editingteacher if user has both)
                    $member_role = null;
                    foreach ($user_roles as $ur) {
                        if ($ur->shortname === 'editingteacher') {
                            $member_role = 'editingteacher';
                            break;
                        } elseif ($ur->shortname === 'student') {
                            $member_role = 'student';
                        }
                    }
                    
                    // Skip if user has no valid role
                    if (!$member_role) {
                        continue;
                    }
                    
                    // Create enrollment
                    $enrollment = new stdClass();
                    $enrollment->userid = $member->userid;
                    $enrollment->enrolid = $enrol_instance->id;
                    $enrollment->status = 0; // Active
                    $enrollment->timestart = strtotime($start_date);
                    $enrollment->timeend = !empty($end_date) ? strtotime($end_date) : 0;
                    $enrollment->modifierid = $USER->id;
                    $enrollment->timecreated = time();
                    $enrollment->timemodified = time();
                    
                    if ($DB->insert_record('user_enrolments', $enrollment)) {
                        // Assign their existing role to the course
                        $role_record = $DB->get_record('role', ['shortname' => $member_role]);
                        if ($role_record) {
                            role_assign($role_record->id, $member->userid, $course_context->id);
                        }
                        
                        $enrolled_count++;
                        if ($member_role === 'editingteacher') {
                            $teachers_enrolled++;
                        } else {
                            $students_enrolled++;
                        }
                    }
                }
                
                $cohort_info = $DB->get_record('cohort', ['id' => $cohort_id]);
                $cohort_name = $cohort_info ? s($cohort_info->name) : 'Selected cohort';
                
                if ($enrolled_count > 0) {
                    $role_breakdown = [];
                    if ($students_enrolled > 0) {
                        $role_breakdown[] = "{$students_enrolled} Student" . ($students_enrolled > 1 ? 's' : '');
                    }
                    if ($teachers_enrolled > 0) {
                        $role_breakdown[] = "{$teachers_enrolled} Teacher" . ($teachers_enrolled > 1 ? 's' : '');
                    }
                    $breakdown_text = implode(' and ', $role_breakdown);
                    
                    $cohort_msg = "{$enrolled_count} users ({$breakdown_text}) from cohort '{$cohort_name}' enrolled";
                    if ($already_enrolled_count > 0) {
                        $cohort_msg .= " ({$already_enrolled_count} already enrolled)";
                    }
                    $success_messages[] = $cohort_msg;
                    $cohort_enrolled = true;
                } else {
                    if ($already_enrolled_count > 0) {
                        $success_messages[] = "All cohort members are already enrolled";
                    }
                }
            }
        }
        
        // Second: Handle individual user enrollment if user is selected
        if ($user_id && $course_id && $user_role) {
            // Check if enrollment already exists
            $existing = $DB->get_record('user_enrolments', [
                'userid' => $user_id,
                'enrolid' => $enrol_instance->id
            ]);
            
            if ($existing) {
                $success_messages[] = "Selected individual user is already enrolled";
            } else {
                // Create new enrollment
                $enrollment = new stdClass();
                $enrollment->userid = $user_id;
                $enrollment->enrolid = $enrol_instance->id;
                $enrollment->status = 0; // Active
                $enrollment->timestart = strtotime($start_date);
                $enrollment->timeend = !empty($end_date) ? strtotime($end_date) : 0;
                $enrollment->modifierid = $USER->id;
                $enrollment->timecreated = time();
                $enrollment->timemodified = time();
                
                if ($DB->insert_record('user_enrolments', $enrollment)) {
                    // Assign the role to the user in the course context
                    $course_context = context_course::instance($course_id);
                    $role = $DB->get_record('role', ['shortname' => $user_role]);
                    
                    if ($role) {
                        role_assign($role->id, $user_id, $course_context->id);
                    }
                    
                    $role_name = $user_role === 'editingteacher' ? 'Teacher' : 'Student';
                    $user_info = $DB->get_record('user', ['id' => $user_id]);
                    $user_fullname = $user_info ? fullname($user_info) : 'User';
                    $success_messages[] = "{$user_fullname} enrolled as {$role_name}";
                    $individual_enrolled = true;
                } else {
                    $success_messages[] = "Failed to enroll individual user";
                }
            }
        }
        
        // Combine success messages
        if (!empty($success_messages)) {
            $success_message = implode('. ', $success_messages) . '.';
            if ($cohort_enrolled || $individual_enrolled) {
                $message_type = "success";
            } else {
                $message_type = "warning";
            }
        } else {
            $success_message = "Please select either a cohort or an individual user with role.";
            $message_type = "error";
        }
    }
}

echo $OUTPUT->header();
 
// Add custom CSS for the enrollment page
 echo <<<CSS
<style>
    /* Hide Moodle default page title and tabs on this page */
    .secondary-navigation { display: none !important; }
    /* Remove theme's extra top/bottom spacing for this page */
    #page.drawers .main-inner { margin-top: 0 !important; margin-bottom: 0 !important; margin-right: 0 !important; margin-left: 0 !important; }
    body {
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
    }
    
    /* Admin Sidebar Navigation - Sticky on all pages */
    .admin-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        will-change: transform;
        backface-visibility: hidden;
    }
    
    .admin-sidebar .sidebar-content {
        padding: 7rem 0 2rem 0;
    }
    
    .admin-sidebar .sidebar-section {
        margin-bottom: 2rem;
    }
    
    .admin-sidebar .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .admin-sidebar .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .admin-sidebar .sidebar-item {
        margin-bottom: 0.25rem;
    }
    
    .admin-sidebar .sidebar-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background-color: #f8f9fa;
        color: #2c3e50;
        text-decoration: none;
        border-left-color: #667eea;
    }
    
    .admin-sidebar .sidebar-icon {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        font-size: 1rem;
        color: #6c757d;
        text-align: center;
    }
    
    .admin-sidebar .sidebar-text {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    /* Scrollbar styling */
    .admin-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .admin-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Main content area with sidebar - FLOW LAYOUT */
    .admin-main-content {
        position: relative;
        margin-left: 280px;
        /* sit to the right of fixed sidebar */
        padding-left: 0;
        padding-right: 0;
        padding-top: 0; /* header spacing handled inside container */
        width: auto;
        min-height: calc(100vh - 60px);
        background-color: transparent;
    }
    
    /* Mobile Toggle Button */
    .sidebar-toggle {
        display: none;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .sidebar-toggle {
            display: block !important;
        }
        
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
        }
        
        .admin-sidebar.sidebar-open {
            transform: translateX(0);
        }
        
        .admin-main-content {
            position: relative;
            margin-left: 0;
            width: 100%;
            height: auto;
            min-height: calc(100vh - 60px);
            padding-top: 80px;
        }
        
        .enrollment-container {
            max-width: 100%;
            width: 100%;
            margin: 0 auto;
            padding: 10px;
            margin-top: 60px;
        }
        
        .page-header {
            margin-top: 20px;
        }
    }
    
    .enrollment-container {
        max-width: none;
        width: 100%;
        margin: 0 auto;
        box-sizing: border-box;
        padding: 20px 20% 20px 20%; /* 20% left/right padding as requested */
        padding-top: 20px; /* compact top spacing */
        animation: fadeInUp 0.8s ease-out;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 25px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin: 0 0 16px 0; /* tighter spacing under header */
        animation: slideInDown 1s ease-out 0.2s both;
    }
    
    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .header-background {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        padding: 40px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .header-background::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 20s linear infinite;
    }
    
    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .breadcrumb {
        color: #0369a1;
        font-size: 0.9rem;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
        opacity: 0.8;
    }
    
    .breadcrumb a {
        color: #0369a1;
        text-decoration: none;
        transition: color 0.3s ease;
    }
    
    .breadcrumb a:hover {
        color: #0284c7;
    }
    
    .page-title {
        font-size: 3rem;
        font-weight: 800;
        color: #0369a1;
        margin: 0;
        text-shadow: 0 2px 4px rgba(3, 105, 161, 0.2);
        position: relative;
        z-index: 1;
        animation: titleGlow 2s ease-in-out infinite alternate;
    }
    
    @keyframes titleGlow {
        from { text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3); }
        to { text-shadow: 0 4px 20px rgba(255, 255, 255, 0.5); }
    }
    
    .page-subtitle {
        font-size: 1.3rem;
        color: #0369a1;
        margin: 10px 0 0 0;
        position: relative;
        z-index: 1;
        opacity: 0.8;
    }
    
    .field-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        backdrop-filter: blur(10px);
        margin: 0 0 20px 0;
        padding: 20px;
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #0369a1;
        margin: 0 0 16px 0;
    }
    
    .field-group {
        margin-bottom: 16px;
    }
    
    .field-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .field-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 6px;
    }
    
    .field-label.optional::after {
        content: " (Optional)";
        color: #6b7280;
        font-weight: 400;
    }
    
    .field-select, .field-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: #fff;
        transition: border-color 0.2s ease;
    }
    
    .field-select:focus, .field-input:focus {
        outline: none;
        border-color: #0369a1;
        box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    }
    
    .action-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        backdrop-filter: blur(10px);
        margin: 0 0 20px 0;
        padding: 20px;
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .form-header {
        background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
        padding: 30px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .form-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #28a745 0%, #20c997 50%, #28a745 100%);
        background-size: 200% 100%;
        animation: shimmer 2s ease-in-out infinite;
    }
    
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    
    .form-title {
        font-size: 2rem;
        font-weight: 700;
        color: #166534;
        margin: 0;
        text-shadow: 0 1px 2px rgba(22, 101, 52, 0.2);
    }
    
    .form-subtitle {
        font-size: 1.1rem;
        color: #166534;
        margin: 10px 0 0 0;
        opacity: 0.8;
    }
    
    .form-content {
        padding: 40px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease-out;
    }
    
    .form-row.single {
        grid-template-columns: 1fr;
    }
    
    .form-group {
        position: relative;
        animation: slideInLeft 0.8s ease-out;
    }
    
    .form-group:nth-child(2) {
        animation-delay: 0.1s;
    }
    
    .form-group:nth-child(3) {
        animation-delay: 0.2s;
    }
    
    .form-group:nth-child(4) {
        animation-delay: 0.3s;
    }
    
    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .form-label {
        display: block;
        margin-bottom: 12px;
        font-weight: 600;
        color: #374151;
        font-size: 1rem;
        position: relative;
    }
    
    .form-label::after {
        content: '*';
        color: #ef4444;
        margin-left: 4px;
    }
    
    .form-label.optional::after {
        display: none;
    }
    
    .form-input, .form-select {
        width: 100%;
        padding: 15px 20px;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        font-size: 1rem;
        background: white;
        transition: all 0.3s ease;
        box-sizing: border-box;
        position: relative;
    }
    
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        transform: translateY(-2px);
    }
    
    .form-input:hover, .form-select:hover {
        border-color: #d1d5db;
        transform: translateY(-1px);
    }
    
    .form-input::placeholder {
        color: #9ca3af;
        transition: color 0.3s ease;
    }
    
    .form-input:focus::placeholder {
        color: #d1d5db;
    }
    
    .form-actions {
        display: flex;
        gap: 20px;
        justify-content: center;
        margin-top: 40px;
        animation: fadeInUp 0.8s ease-out 0.6s both;
    }
    
    .btn {
        padding: 15px 40px;
        border: none;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        position: relative;
        overflow: hidden;
    }
    
    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }
    
    .btn:hover::before {
        left: 100%;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        color: #0369a1;
        box-shadow: 0 8px 25px rgba(224, 242, 254, 0.4);
    }
    
    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(224, 242, 254, 0.6);
    }
    
    .btn-secondary {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        color: #374151;
        box-shadow: 0 8px 25px rgba(243, 244, 246, 0.4);
    }
    
    .btn-secondary:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(243, 244, 246, 0.6);
    }
    
    .btn:active {
        transform: translateY(-1px);
    }
    
    .alert {
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 30px;
        font-weight: 500;
        animation: slideInDown 0.5s ease-out;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border: 2px solid #10b981;
    }
    
    .alert-warning {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        color: #92400e;
        border: 2px solid #f59e0b;
    }
    
    .alert-error {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border: 2px solid #ef4444;
    }
    
    .floating-elements {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: -1;
    }
    
    .floating-circle {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        animation: float 6s ease-in-out infinite;
    }
    
    .floating-circle:nth-child(1) {
        width: 80px;
        height: 80px;
        top: 20%;
        left: 10%;
        animation-delay: 0s;
    }
    
    .floating-circle:nth-child(2) {
        width: 120px;
        height: 120px;
        top: 60%;
        right: 15%;
        animation-delay: 2s;
    }
    
    .floating-circle:nth-child(3) {
        width: 60px;
        height: 60px;
        bottom: 20%;
        left: 20%;
        animation-delay: 4s;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-20px) rotate(180deg); }
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .page-title {
            font-size: 2rem;
        }
        
        .enrollment-container {
            padding: 10px;
        }
}
</style>
CSS;

// Floating background elements
echo "<div class='floating-elements'>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "</div>";

// Admin Sidebar Navigation
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');



// Main content area with sidebar

// Page Header
echo "<div class='page-header'>";
echo "<div class='header-background'>";
echo "<div class='breadcrumb'>";

echo "</div>";
echo "<h1 class='page-title'>Enroll New User</h1>";
echo "<p class='page-subtitle'>Add a new user to a course with advanced options</p>";
echo "</div>";
echo "</div>";

// Show success/error message
if (isset($success_message)) {
    echo "<div class='alert alert-{$message_type}'>";
    echo "<i class='fa fa-" . ($message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'times-circle')) . "'></i> ";
    echo $success_message;
    echo "</div>";
}

// Start the form
echo "<form method='POST' action=''>";

// Role Selection Section (only for individual enrollment)
echo "<div class='field-section' id='role-selection-section'>";
echo "<h3 class='section-title'>Select Role (For Individual Enrollment Only)</h3>";
echo "<div class='field-group'>";
echo "<label class='field-label optional' for='user_role'>User Role</label>";
echo "<select class='field-select' id='user_role' name='user_role' onchange='updateUserList()'>";
echo "<option value=''>Choose a role...</option>";
echo "<option value='student'>Student</option>";
echo "<option value='editingteacher'>Teacher</option>";
echo "</select>";
echo "<p style='font-size: 0.85rem; color: #6b7280; margin-top: 8px;'>
    <i class='fa fa-info-circle'></i> Role is only required for individual user enrollment. When enrolling a cohort, each member's existing role will be used automatically. You can select both a cohort and an individual user to enroll them together.
</p>";
echo "</div>";
echo "</div>";

// User Selection Section
echo "<div class='field-section' id='user-selection-section' style='display:none;'>";
echo "<h3 class='section-title' id='user-section-title'>Select User</h3>";
echo "<div class='field-group'>";
echo "<label class='field-label optional' for='user_id' id='user-label'>User (For Individual Enrollment)</label>";
echo "<select class='field-select' id='user_id' name='user_id'>";
echo "<option value=''>Choose a user...</option>";
echo "</select>";
echo "</div>";
echo "</div>";

// Hidden data for students and teachers
echo "<script>";
echo "const studentsData = " . json_encode(array_values($DB->get_records_sql("
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email 
    FROM {user} u 
    JOIN {role_assignments} ra ON u.id = ra.userid 
    JOIN {role} r ON ra.roleid = r.id 
    WHERE r.shortname = 'student'
    AND u.deleted = 0 
    AND u.suspended = 0
    AND u.id > 1
    ORDER BY u.firstname, u.lastname
"))) . ";";

echo "const teachersData = " . json_encode(array_values($DB->get_records_sql("
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email 
    FROM {user} u 
    JOIN {role_assignments} ra ON u.id = ra.userid 
    JOIN {role} r ON ra.roleid = r.id 
    WHERE r.shortname = 'editingteacher'
    AND u.deleted = 0 
    AND u.suspended = 0
    AND u.id > 1
    ORDER BY u.firstname, u.lastname
"))) . ";";
echo "</script>";

// Course Selection Section
echo "<div class='field-section'>";
echo "<h3 class='section-title'>Select Course</h3>";
echo "<div class='field-group'>";
echo "<label class='field-label' for='course_id'>Course</label>";
echo "<select class='field-select' id='course_id' name='course_id' required>";
echo "<option value=''>Choose a course...</option>";

// Get all visible courses
$courses = $DB->get_records_select('course', 'id > 1 AND visible = 1', null, 'fullname ASC');
foreach ($courses as $course) {
    echo "<option value='{$course->id}'>{$course->fullname}</option>";
}

echo "</select>";
echo "</div>";
echo "</div>";

// Cohort Selection Section
echo "<div class='field-section'>";
echo "<h3 class='section-title'>Select Cohort (Optional)</h3>";
echo "<div class='field-group'>";
echo "<label class='field-label optional' for='cohort_id'>Cohort (Bulk Enrollment)</label>";
echo "<select class='field-select' id='cohort_id' name='cohort_id'>";
echo "<option value=''>None - Individual enrollment only</option>";

// Get all cohorts with member count
$cohorts = $DB->get_records('cohort', null, 'name ASC');
foreach ($cohorts as $cohort) {
    $member_count = $DB->count_records('cohort_members', ['cohortid' => $cohort->id]);
    echo "<option value='{$cohort->id}'>" . s($cohort->name) . " ({$member_count} members)</option>";
}

echo "</select>";
echo "<p style='font-size: 0.85rem; color: #6b7280; margin-top: 8px;'>
    <i class='fa fa-info-circle'></i> When a cohort is selected, ALL members (students and teachers) will be enrolled in the course with their existing roles automatically. You can also select an individual user to enroll them alongside the cohort.
</p>";
echo "</div>";
echo "</div>";

// Enrollment Settings Section
echo "<div class='field-section'>";
echo "<h3 class='section-title'>Enrollment Settings</h3>";
echo "<div class='field-row'>";
echo "<div class='field-group'>";
echo "<label class='field-label' for='enrollment_method'>Method</label>";
echo "<select class='field-select' id='enrollment_method' name='enrollment_method' required>";
echo "<option value='manual'>Manual Enrollment</option>";
echo "<option value='self'>Self Enrollment</option>";
echo "<option value='cohort'>Cohort Enrollment</option>";
echo "<option value='guest'>Guest Access</option>";
echo "</select>";
echo "</div>";

echo "<div class='field-group'>";
echo "<label class='field-label optional' for='enrollment_duration'>Duration (Days)</label>";
echo "<input type='number' class='field-input' id='enrollment_duration' name='enrollment_duration' placeholder='Leave empty for unlimited' min='1' max='3650'>";
echo "</div>";
echo "</div>";

echo "<div class='field-row'>";
echo "<div class='field-group'>";
echo "<label class='field-label' for='start_date'>Start Date</label>";
echo "<input type='date' class='field-input' id='start_date' name='start_date' value='" . date('Y-m-d') . "' required>";
echo "</div>";

echo "<div class='field-group'>";
echo "<label class='field-label optional' for='end_date'>End Date</label>";
echo "<input type='date' class='field-input' id='end_date' name='end_date'>";
echo "</div>";
echo "</div>";
echo "</div>";

// Action Buttons
echo "<div class='action-section'>";
echo "<button type='submit' name='enroll_user' class='btn btn-primary'>";
echo "<i class='fa fa-plus'></i> Enroll User";
echo "</button>";
echo "</form>";
echo "<a href='enrollments.php' class='btn btn-secondary'>";
echo "<i class='fa fa-arrow-left'></i> Back to Enrollments";
echo "</a>";
echo "</div>";


// Add JavaScript for form enhancements
echo "<script>
// Update user list based on role selection
function updateUserList() {
    const roleSelect = document.getElementById('user_role');
    const userSelect = document.getElementById('user_id');
    const userSection = document.getElementById('user-selection-section');
    const userSectionTitle = document.getElementById('user-section-title');
    const userLabel = document.getElementById('user-label');
    const selectedRole = roleSelect.value;
    
    // Clear current options
    userSelect.innerHTML = '<option value=\"\">Choose a user...</option>';
    
    if (selectedRole) {
        // Show user selection section
        userSection.style.display = 'block';
        
        // Update labels based on role
        if (selectedRole === 'student') {
            userSectionTitle.textContent = 'Select Student';
            userLabel.textContent = 'Student';
            
            // Populate with students
            studentsData.forEach(function(student) {
                const option = document.createElement('option');
                option.value = student.id;
                option.textContent = student.firstname + ' ' + student.lastname + ' (' + student.email + ')';
                userSelect.appendChild(option);
            });
        } else if (selectedRole === 'editingteacher') {
            userSectionTitle.textContent = 'Select Teacher';
            userLabel.textContent = 'Teacher';
            
            // Populate with teachers
            teachersData.forEach(function(teacher) {
                const option = document.createElement('option');
                option.value = teacher.id;
                option.textContent = teacher.firstname + ' ' + teacher.lastname + ' (' + teacher.email + ')';
                userSelect.appendChild(option);
            });
        }
    } else {
        userSection.style.display = 'none';
    }
}

// Sidebar toggle function
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleButton = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleButton.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Auto-calculate end date based on duration
    const durationInput = document.getElementById('enrollment_duration');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    function calculateEndDate() {
        if (durationInput.value && startDateInput.value) {
            const startDate = new Date(startDateInput.value);
            const duration = parseInt(durationInput.value);
            const endDate = new Date(startDate);
            endDate.setDate(startDate.getDate() + duration);
            endDateInput.value = endDate.toISOString().split('T')[0];
        }
    }
    
    durationInput.addEventListener('input', calculateEndDate);
    startDateInput.addEventListener('change', calculateEndDate);
    
    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const roleSelect = document.getElementById('user_role');
        const userSelect = document.getElementById('user_id');
        const courseSelect = document.getElementById('course_id');
        const cohortSelect = document.getElementById('cohort_id');
        
        // Check course selection
        if (!courseSelect.value) {
            e.preventDefault();
            alert('Please select a course.');
            return false;
        }
        
        // If cohort is selected, no need for role or user
        if (cohortSelect.value) {
            return true; // Valid - cohort enrollment
        }
        
        // If no cohort, require both role and user for individual enrollment
        if (!userSelect.value || !roleSelect.value) {
            e.preventDefault();
            alert('For individual enrollment, please select both role and user, or select a cohort for bulk enrollment.');
            return false;
        }
    });
    
    // Add loading state to submit button
    form.addEventListener('submit', function() {
        const submitBtn = document.querySelector('button[type=\\\"submit\\\"]');
        submitBtn.innerHTML = '<i class=\\\"fa fa-spinner fa-spin\\\"></i> Enrolling...';
        submitBtn.disabled = true;
    });
});
</script>";

echo $OUTPUT->footer();
?>
