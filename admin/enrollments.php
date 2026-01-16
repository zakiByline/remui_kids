<?php
/**
 * Enrolments Management Page - Display and manage all course enrolments
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE;

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/enrollments.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Enrolments Management');


// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

$normalize_role_shortname = function(string $shortname): string {
    return $shortname === 'teachers' ? 'teacher' : $shortname;
};

$perpage = 10;
$page = optional_param('page', 0, PARAM_INT);
$page = max(0, $page);
$offset = $page * $perpage;

$searchquery = optional_param('search', '', PARAM_RAW_TRIMMED);
$coursefilter = optional_param('course', 'all', PARAM_RAW_TRIMMED);
$rolefilter = optional_param('role', 'all', PARAM_ALPHA);
$statusfilter = optional_param('status', 'all', PARAM_ALPHA);

$filtersql = '';
$filterparams = [];

if ($searchquery !== '') {
    $searchlike = '%' . core_text::strtolower($searchquery) . '%';
    $filtersql .= " AND (
        LOWER(u.firstname) LIKE :searchfirstname
        OR LOWER(u.lastname) LIKE :searchlastname
        OR LOWER(u.email) LIKE :searchemail
        OR LOWER(c.fullname) LIKE :searchcourse
        OR LOWER(c.shortname) LIKE :searchshortname
    )";
    $filterparams['searchfirstname'] = $searchlike;
    $filterparams['searchlastname'] = $searchlike;
    $filterparams['searchemail'] = $searchlike;
    $filterparams['searchcourse'] = $searchlike;
    $filterparams['searchshortname'] = $searchlike;
}

if ($coursefilter !== 'all' && preg_match('/^\d+$/', (string)$coursefilter)) {
    $filtersql .= " AND c.id = :coursefilter";
    $filterparams['coursefilter'] = (int)$coursefilter;
} else {
    $coursefilter = 'all';
}

$validroles = ['student', 'teacher', 'editingteacher'];
if (in_array($rolefilter, $validroles, true)) {
    $filtersql .= " AND r.shortname = :rolefilter";
    $filterparams['rolefilter'] = $rolefilter;
} else {
    $rolefilter = 'all';
}

if ($statusfilter === 'active') {
    $filtersql .= " AND ue.status = 0";
} else if ($statusfilter === 'suspended') {
    $filtersql .= " AND ue.status = 1";
} else {
    $statusfilter = 'all';
}

$enrollmentbaseparams = [];
if ($searchquery !== '') {
    $enrollmentbaseparams['search'] = $searchquery;
}
if ($coursefilter !== 'all') {
    $enrollmentbaseparams['course'] = $coursefilter;
}
if ($rolefilter !== 'all') {
    $enrollmentbaseparams['role'] = $rolefilter;
}
if ($statusfilter !== 'all') {
    $enrollmentbaseparams['status'] = $statusfilter;
}
$siteadminids = array_filter(array_map('intval', explode(',', (string)$CFG->siteadmins)));
$adminexclusion = '';
if (!empty($siteadminids)) {
    $adminidsql = implode(',', $siteadminids);
    $adminexclusion = " AND u.id NOT IN ($adminidsql)";
}

// Handle enrolment status toggle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'toggle_enrollment_status') {
    header('Content-Type: application/json');
    
    $enrollment_id = intval($_POST['enrollment_id']);
    if ($enrollment_id) {
        $enrollment = $DB->get_record('user_enrolments', ['id' => $enrollment_id]);
        if ($enrollment) {
            $enrollment->status = $enrollment->status ? 0 : 1;
            if ($DB->update_record('user_enrolments', $enrollment)) {
                $status = $enrollment->status ? 'suspended' : 'activated';
                echo json_encode(['status' => 'success', 'message' => "Enrolment $status successfully"]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update enrollment status']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Enrolment not found']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid enrollment ID']);
    }
    exit;
}

// Handle GET AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_students':
            // Get all students (users with trainee role)
            $students = $DB->get_records_sql("
                SELECT DISTINCT u.id, u.firstname, u.lastname, u.email 
                FROM {user} u 
                JOIN {role_assignments} ra ON u.id = ra.userid 
                JOIN {role} r ON ra.roleid = r.id 
                WHERE r.shortname = 'student' 
                AND u.deleted = 0 
                AND u.suspended = 0
                ORDER BY u.firstname, u.lastname
            ");
            
            echo json_encode([
                'status' => 'success',
                'students' => array_values($students)
            ]);
            exit;
            
        case 'get_courses':
            // Get all visible courses (excluding site course)
            $courses = $DB->get_records_select('course', 'id > 1 AND visible = 1', null, 'fullname ASC');
            
            echo json_encode([
                'status' => 'success',
                'courses' => array_values($courses)
            ]);
            exit;

        case 'get_enrollments':
            $requestedpage = optional_param('page', 0, PARAM_INT);
            $requestedpage = max(0, $requestedpage);
            $requestedoffset = $requestedpage * $perpage;

    $countsqlbase = "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {role} r ON ra.roleid = r.id
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1
         AND r.shortname IN ('student', 'teacher', 'editingteacher')"
         . $adminexclusion;

    $total_enrollments_ajax = $DB->count_records_sql(
        $countsqlbase . $filtersql,
        $filterparams
    );

            $totalpages_ajax = $total_enrollments_ajax > 0 ? (int)ceil($total_enrollments_ajax / $perpage) : 1;
            if ($requestedpage >= $totalpages_ajax) {
                $requestedpage = max(0, $totalpages_ajax - 1);
                $requestedoffset = $requestedpage * $perpage;
            }

            $data_sql = "SELECT DISTINCT
                    ue.id as enrollment_id,
                    ue.userid,
                    ue.enrolid,
                    ue.status,
                    ue.timestart,
                    ue.timeend,
                    ue.timecreated,
                    ue.timemodified,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.username,
                    c.id as courseid,
                    c.fullname as course_name,
                    c.shortname as course_shortname,
                    r.shortname as role_shortname,
                    r.name as role_name
                 FROM {user_enrolments} ue
                 JOIN {user} u ON ue.userid = u.id
                 JOIN {enrol} e ON ue.enrolid = e.id
                 JOIN {course} c ON e.courseid = c.id
                 JOIN {role_assignments} ra ON u.id = ra.userid
                 JOIN {role} r ON ra.roleid = r.id
                 JOIN {context} ctx ON ra.contextid = ctx.id
                 WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1
                AND r.shortname IN ('student', 'teacher', 'editingteacher')"
                . $adminexclusion .
                $filtersql .
                "
                 ORDER BY ue.timecreated DESC";

            $enrollment_records = $DB->get_records_sql(
                $data_sql,
                $filterparams,
                $requestedoffset,
                $perpage
            );

            $enrollment_payload = [];
            foreach ($enrollment_records as $record) {
                $statusclass = $record->status ? 'status-suspended' : 'status-active';
                $statustext = $record->status ? 'Suspended' : 'Active';
                $normalizedrole = $normalize_role_shortname($record->role_shortname);
                switch ($normalizedrole) {
                    case 'teacher':
                    case 'teachers':
                        $roledisplay = 'Teacher';
                        break;
                    case 'editingteacher':
                        $roledisplay = 'Editing Teacher';
                        break;
                    default:
                        $roledisplay = 'Student';
                }
                $roleclass = $normalizedrole === 'student' ? 'status-active' : 'status-completed';
                $avatar = strtoupper(substr(format_string($record->firstname), 0, 1));

                $enrollment_payload[] = [
                    'enrollment_id' => (int)$record->enrollment_id,
                    'fullname' => format_string($record->firstname . ' ' . $record->lastname),
                    'firstname' => format_string($record->firstname),
                    'lastname' => format_string($record->lastname),
                    'email' => htmlspecialchars($record->email, ENT_QUOTES),
                    'course_name' => format_string($record->course_name),
                    'course_shortname' => format_string($record->course_shortname),
                    'role_shortname' => $normalizedrole,
                    'role_display' => $roledisplay,
                    'role_class' => $roleclass,
                    'status' => (int)$record->status,
                    'status_class' => $statusclass,
                    'status_text' => $statustext,
                    'enrolled_date' => date('M j, Y g:i A', $record->timecreated),
                    'avatar_letter' => $avatar ?: 'N'
                ];
            }

            $startcount_ajax = $total_enrollments_ajax ? $requestedoffset + 1 : 0;
            $endcount_ajax = min($requestedoffset + $perpage, $total_enrollments_ajax);

            $pageparamsajax = $enrollmentbaseparams;
            $pageparamsajax['page'] = $requestedpage;
            $pageurl_ajax = (new moodle_url($PAGE->url, $pageparamsajax))->out(false);

            echo json_encode([
                'status' => 'success',
                'page' => $requestedpage,
                'perpage' => $perpage,
                'totalpages' => $totalpages_ajax,
                'total' => (int)$total_enrollments_ajax,
                'startcount' => $startcount_ajax,
                'endcount' => $endcount_ajax,
                'enrollments' => array_values($enrollment_payload),
                'pageurl' => $pageurl_ajax
            ]);
            exit;
    }
}

// Handle POST AJAX requests for enrollment
if (isset($_POST['action']) && $_POST['action'] === 'enroll_student') {
    header('Content-Type: application/json');
    
    $student_id = intval($_POST['student_id']);
    $course_id = intval($_POST['course_id']);
    
    if ($student_id && $course_id) {
        // Get the enrollment instance for this course
        $enrol_instance = $DB->get_record('enrol', [
            'courseid' => $course_id,
            'enrol' => 'manual',
            'status' => 0
        ]);
        
        if (!$enrol_instance) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No manual enrollment method found for this course'
            ]);
        } else {
            // Check if enrollment already exists
            $existing = $DB->get_record('user_enrolments', [
                'userid' => $student_id,
                'enrolid' => $enrol_instance->id
            ]);
            
            if ($existing) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Student is already enrolled in this course'
                ]);
            } else {
                // Create new enrollment
                $enrollment = new stdClass();
                $enrollment->userid = $student_id;
                $enrollment->enrolid = $enrol_instance->id;
                $enrollment->status = 0; // Active
                $enrollment->timestart = time();
                $enrollment->timeend = 0;
                $enrollment->modifierid = $USER->id;
                $enrollment->timecreated = time();
                $enrollment->timemodified = time();
                
                if ($DB->insert_record('user_enrolments', $enrollment)) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Student enrolled successfully'
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to enroll student'
                    ]);
                }
            }
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid student or course ID'
        ]);
    }
    exit;
}

echo $OUTPUT->header();

// Add custom CSS for the enrollments page with admin sidebar
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Ensure AI assistant stays fixed at bottom-right on this page */
    #ai-assistant-chatbot,
    #ai-assistant-chatbot.ai-assistant-collapsed,
    #ai-assistant-chatbot.ai-assistant-expanded {
        position: fixed !important;
        bottom: 80px !important;
        right: 16px !important;
        z-index: 9999999 !important;
    }
    
    #ai-assistant-chatbot .ai-chat-window {
        bottom: 20px !important;
        right: 90px !important;
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
        padding: 6rem 0 2rem 0;
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

    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px; /* Add padding to account for topbar */
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
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
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }
    
    .enrollments-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin-bottom: 30px;
        position: relative;
    }
    
    .header-background {
        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        min-height: 140px;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 30px 40px;
        gap: 20px;
    }
    
    .header-content {
        position: relative;
        z-index: 2;
        color: #0f4c81;
        text-align: center;
        flex: 1;
    }
    
    .header-action {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }
    
    .header-background::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    }
    
    .page-content {
        padding: 24px 30px 40px;
        position: relative;
    }
    
    .breadcrumb {
        background: rgba(255, 255, 255, 0.1);
        padding: 15px 30px;
        border-radius: 12px;
        margin-bottom: 20px;
        backdrop-filter: blur(10px);
    }
    
    .breadcrumb a {
        color: rgba(255, 255, 255, 0.8);
        text-decoration: none;
        transition: color 0.3s ease;
    }
    
    .breadcrumb a:hover {
        color: white;
    }
    
    .breadcrumb-item {
        color: rgba(255, 255, 255, 0.9);
    }
    
    .page-title-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .title-content {
        flex: 1;
        min-width: 300px;
    }
    
    .title-actions {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    
    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: #0369a1;
        margin-bottom: 8px;
    }
    
    .btn-enroll {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #ffffff;
        border: none;
        padding: 16px 34px;
        border-radius: 999px;
        font-size: 1.05rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 15px 30px rgba(37, 99, 235, 0.25);
        display: inline-flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
    }
    
    .btn-enroll i {
        font-size: 0.95rem;
    }
    
    .btn-enroll:hover {
        transform: translateY(-2px) scale(1.01);
        box-shadow: 0 20px 35px rgba(37, 99, 235, 0.35);
    }
    
    .btn-enroll:active {
        transform: translateY(0);
    }

    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #374151;
        font-size: 0.95rem;
    }
    
    .form-group select {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 1rem;
        background: white;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }
    
    .form-group select:focus {
        outline: none;
        border-color: #0369a1;
        box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    }
    
    .form-group select:hover {
        border-color: #d1d5db;
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

    .page-subtitle {
        font-size: 1.05rem;
        color: #0369a1;
        margin: 0;
        font-weight: 500;
        opacity: 0.9;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 16px;
        padding: 20px 18px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 15px 32px rgba(0, 0, 0, 0.12);
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #e0f2fe;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #0369a1;
        font-size: 1.2rem;
        margin: 0 auto 12px;
    }
    
    /* Pastel color variations for different stat types */
    .stat-card:nth-child(1) .stat-icon {
        background: #e0f2fe;
        color: #0369a1;
    }
    
    .stat-card:nth-child(2) .stat-icon {
        background: #dcfce7;
        color: #166534;
    }
    
    .stat-card:nth-child(3) .stat-icon {
        background: #f3e8ff;
        color: #7c3aed;
    }
    
    .stat-card:nth-child(4) .stat-icon {
        background: #fed7aa;
        color: #ea580c;
    }
    
    .stat-card:nth-child(5) .stat-icon {
        background: #e0f2fe;
        color: #0369a1;
    }
    
    .stat-card:nth-child(6) .stat-icon {
        background: #fce7f3;
        color: #be185d;
    }
    
    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
        40% { transform: translateY(-10px); }
        60% { transform: translateY(-5px); }
    }
    
    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: #2d3748;
        margin-bottom: 10px;
    }
    
    .stat-label {
        font-size: 1rem;
        color: #6b7280;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .filters-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 24px 28px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        margin-bottom: 24px;
    }
    
    .filters-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-label {
        font-size: 0.9rem;
        color: #6b7280;
        font-weight: 600;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .filter-input {
        padding: 12px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: white;
        width: 100%;
    }

    .filter-group--search {
        max-width: 260px;
    }
    
    .filter-input:focus {
        outline: none;
        border-color: #0369a1;
        box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    }
    
    .filter-select {
        padding: 12px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 1rem;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .filter-select:focus {
        outline: none;
        border-color: #0369a1;
        box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    }
    
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        justify-content: center;
    }
    
    .btn-primary {
        background: #e0f2fe;
        color: #0369a1;
        box-shadow: 0 4px 15px rgba(224, 242, 254, 0.4);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }
    
    .btn-secondary {
        background: #f7fafc;
        color: #4a5568;
        border: 2px solid #e2e8f0;
    }
    
    .btn-secondary:hover {
        background: #edf2f7;
        transform: translateY(-2px);
    }
    
    .enrollments-table-container {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
    }
    
    .enrollments-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .enrollments-table th {
        background: #e0f2fe;
        color: #0369a1;
        padding: 20px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .enrollments-table td {
        padding: 20px 16px;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
        text-align: left;
    }
    
    .enrollments-table tr:hover {
        background: #f8f9fa;
    }

    .pagination-container {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 25px;
        flex-wrap: wrap;
    }

    .pagination-info {
        font-size: 0.9rem;
        color: #6b7280;
        margin-bottom: 10px;
        text-align: center;
        width: 100%;
    }

    .pagination-button {
        padding: 10px 16px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #0369a1;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        min-width: 40px;
        text-align: center;
    }

    .pagination-button:hover {
        background: #e0f2fe;
        border-color: #bae6fd;
    }

    .pagination-button.active {
        background: #0369a1;
        color: #fff;
        border-color: #0369a1;
        box-shadow: 0 8px 20px rgba(3, 105, 161, 0.2);
    }

    .pagination-button.disabled {
        color: #a0aec0;
        background: #f8fafc;
        pointer-events: none;
    }
    
    .enrollment-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .enrollment-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #e0f2fe;
        color: #0369a1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
    }
    
    .enrollment-details h4 {
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 5px 0;
        font-size: 1rem;
    }
    
    .enrollment-details p {
        color: #6c757d;
        font-size: 0.9rem;
        margin: 0;
    }
    
    .status-badge {
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-active {
        background: #dcfce7;
        color: #166534;
    }
    
    .status-suspended {
        background: #fef2f2;
        color: #991b1b;
    }
    
    .status-completed {
        background: #e0f2fe;
        color: #0369a1;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 0.8rem;
        border-radius: 8px;
    }
    
    .btn-success {
        background: #dcfce7;
        color: #166534;
        box-shadow: 0 2px 8px rgba(220, 252, 231, 0.4);
    }
    
    .btn-danger {
        background: #fef2f2;
        color: #dc2626;
        box-shadow: 0 2px 8px rgba(254, 242, 242, 0.4);
    }
    
    .btn-info {
        background: #e0f2fe;
        color: #0369a1;
        box-shadow: 0 2px 8px rgba(224, 242, 254, 0.4);
    }
    
    .no-enrollments {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    
    .no-enrollments i {
        font-size: 48px;
        margin-bottom: 20px;
        color: #dee2e6;
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
        width: 100px;
        height: 100px;
        top: 10%;
        left: 10%;
        animation-delay: 0s;
    }
    
    .floating-circle:nth-child(2) {
        width: 80px;
        height: 80px;
        top: 60%;
        right: 10%;
        animation-delay: 2s;
    }
    
    .floating-circle:nth-child(3) {
        width: 60px;
        height: 60px;
        bottom: 20%;
        left: 20%;
        animation-delay: 4s;
    }
    
    .floating-circle:nth-child(4) {
        width: 120px;
        height: 120px;
        top: 30%;
        right: 30%;
        animation-delay: 1s;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-20px) rotate(180deg); }
    }
    
    .confirmation-modal {
        position: fixed;
        inset: 0;
        display: none;
        z-index: 99999;
        background: rgba(0, 0, 0, 0.7);
        padding: 20px;
        box-sizing: border-box;
        opacity: 1;
        visibility: visible;
        align-items: center;
        justify-content: center;
    }
    .confirmation-modal.open {
        display: flex;
    }
    .modal-content {
        background: #ffffff;
        margin: 0 auto;
        padding: 0;
        border: 3px solid #dc3545;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        min-height: 200px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        position: relative;
    }
    .modal-content::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #667eea 100%);
        background-size: 200% 100%;
        animation: shimmer 2s ease-in-out infinite;
    }
    @keyframes modalFadeIn {
        0% { 
            opacity: 0;
            backdrop-filter: blur(0px);
        }
        100% { 
            opacity: 1;
            backdrop-filter: blur(10px);
        }
    }
    @keyframes modalSlideIn {
        0% {
            opacity: 0;
            transform: translateY(-100px) scale(0.8) rotateX(20deg);
        }
        50% {
            opacity: 0.8;
            transform: translateY(10px) scale(1.02) rotateX(-5deg);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1) rotateX(0deg);
        }
    }
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    .modal-body {
        padding: 30px;
        text-align: center;
        position: relative;
    }
    .modal-message {
        font-size: 1.1rem;
        color: #333;
        margin-bottom: 0;
        line-height: 1.6;
        font-weight: 500;
        position: relative;
    }
    .modal-message::before {
        content: '⚠️';
        display: block;
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    .modal-footer {
        padding: 0 30px 30px;
        display: flex;
        gap: 15px;
        justify-content: center;
    }
    .modal-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 120px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        position: relative;
        overflow: hidden;
    }
    .modal-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: left 0.6s;
    }
    .modal-btn:hover::before {
        left: 100%;
    }
    .modal-btn-secondary {
        background: #f8f9fa;
        color: #6c757d;
        border: 1px solid #dee2e6;
    }
    .modal-btn-secondary:hover {
        background: #e9ecef;
        color: #495057;
        transform: translateY(-2px);
    }
    .modal-btn-danger {
        background: #dc3545;
        color: white;
        border: 1px solid #dc3545;
    }
    .modal-btn-danger:hover {
        background: #c82333;
        border-color: #bd2130;
        transform: translateY(-2px);
    }
    .modal-btn-success {
        background: #28a745;
        color: white;
        border: 1px solid #28a745;
    }
    .modal-btn-success:hover {
        background: #218838;
        border-color: #1e7e34;
        transform: translateY(-2px);
    }
    @keyframes bodySlideIn {
        0% {
            opacity: 0;
            transform: translateY(30px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    @keyframes messageFadeIn {
        0% {
            opacity: 0;
            transform: scale(0.8);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }
    @keyframes footerSlideUp {
        0% {
            opacity: 0;
            transform: translateY(20px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @media (max-width: 768px) {
        .header-background {
            flex-direction: column;
            text-align: center;
            padding: 24px;
            gap: 16px;
        }
        
        .header-action {
            width: 100%;
            justify-content: center;
        }
        
        .btn-enroll {
            width: 100%;
            justify-content: center;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .filters-row {
            grid-template-columns: 1fr;
        }
        
        .enrollments-table {
            font-size: 0.9rem;
        }
        
        .enrollments-table th,
        .enrollments-table td {
            padding: 12px 8px;
        }
    }
</style>";

// Floating background elements
echo "<div class='floating-elements'>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "</div>";

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='header-background'>";
echo "<div class='breadcrumb'>";

echo "</div>";
echo "<div class='header-content'>";
echo "<h1 class='page-title'>Enrollments Management</h1>";
echo "<p class='page-subtitle'>Manage and view all course enrollments in your system</p>";
echo "</div>";
echo "<div class='header-action'>";
echo "<a href='enroll_student.php' class='btn btn-primary btn-enroll'>";
echo "<i class='fa fa-plus'></i> Enroll User";
echo "</a>";
echo "</div>";
echo "</div>";
echo "<br>";

try {
    // Get enrollment statistics (students and editingteachers only)
    $total_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {role} r ON ra.roleid = r.id
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1
         AND r.shortname IN ('student', 'teacher', 'editingteacher')"
         . $adminexclusion
    );
    
    $active_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {role} r ON ra.roleid = r.id
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1 AND ue.status = 0
         AND r.shortname IN ('student', 'teacher', 'editingteacher')"
         . $adminexclusion
    );
    
    $suspended_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {role} r ON ra.roleid = r.id
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1 AND ue.status = 1
         AND r.shortname IN ('student', 'teacher', 'editingteacher')"
         . $adminexclusion
    );
    
    $total_courses = $DB->count_records_select('course', 'id > 1 AND visible = 1');
    
    $filtered_total_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {role} r ON ra.roleid = r.id
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1
         AND ctx.contextlevel = 50 
         AND ctx.instanceid = c.id
         AND r.shortname IN ('student', 'teacher', 'editingteacher')"
         . $adminexclusion .
         $filtersql,
         $filterparams
    );

    $totalpages = $filtered_total_enrollments > 0 ? (int)ceil($filtered_total_enrollments / $perpage) : 1;
    if ($page >= $totalpages) {
        $page = max(0, $totalpages - 1);
        $offset = $page * $perpage;
    }

    // Statistics Grid
    echo "<div class='stats-grid'>";
    echo "<div class='stat-card'>";
    echo "<div class='stat-icon'><i class='fa fa-users'></i></div>";
    echo "<div class='stat-number'>$total_enrollments</div>";
    echo "<div class='stat-label'>Total Enrollments</div>";
    echo "</div>";
    
    echo "<div class='stat-card'>";
    echo "<div class='stat-icon'><i class='fa fa-check-circle'></i></div>";
    echo "<div class='stat-number'>$active_enrollments</div>";
    echo "<div class='stat-label'>Active Enrollments</div>";
    echo "</div>";
    
    echo "<div class='stat-card'>";
    echo "<div class='stat-icon'><i class='fa fa-pause-circle'></i></div>";
    echo "<div class='stat-number'>$suspended_enrollments</div>";
    echo "<div class='stat-label'>Suspended Enrollments</div>";
    echo "</div>";
    
    echo "<div class='stat-card'>";
    echo "<div class='stat-icon'><i class='fa fa-book'></i></div>";
    echo "<div class='stat-number'>$total_courses</div>";
    echo "<div class='stat-label'>Available Courses</div>";
    echo "</div>";
    echo "</div>";
    
    // Filters Section
    echo "<div class='filters-section'>";
    echo "<form class='filters-row' id='enrollmentsFilterForm' method='get'>";
    echo "<input type='hidden' name='page' value='" . $page . "'>";
    
    echo "<div class='filter-group filter-group--search'>";
    echo "<label class='filter-label'>Search User</label>";
    echo "<input type='text' class='filter-input' placeholder='Search by name or email...' id='student-search' name='search' value='" . s($searchquery) . "'>";
    echo "</div>";
    
    echo "<div class='filter-group'>";
    echo "<label class='filter-label'>Course</label>";
    echo "<select class='filter-select' id='course-filter' name='course'>";
    $selected = ($coursefilter === 'all') ? 'selected' : '';
    echo "<option value='all' $selected>All Courses</option>";
    $courses = $DB->get_records_select('course', 'id > 1 AND visible = 1', null, 'fullname ASC');
    foreach ($courses as $course) {
        $sel = ((string)$coursefilter === (string)$course->id) ? 'selected' : '';
        echo "<option value='{$course->id}' $sel>" . s($course->fullname) . "</option>";
    }
    echo "</select>";
    echo "</div>";
    
    echo "<div class='filter-group'>";
    echo "<label class='filter-label'>Role</label>";
    echo "<select class='filter-select' id='role-filter' name='role'>";
    $roleselect = ($rolefilter === 'all') ? 'selected' : '';
    echo "<option value='all' $roleselect>All</option>";
    $roleselect = ($rolefilter === 'student') ? 'selected' : '';
    echo "<option value='student' $roleselect>Student</option>";
    $roleselect = ($rolefilter === 'teacher') ? 'selected' : '';
    echo "<option value='teacher' $roleselect>Teacher</option>";
    $roleselect = ($rolefilter === 'editingteacher') ? 'selected' : '';
    echo "<option value='editingteacher' $roleselect>Editing Teacher</option>";
    echo "</select>";
    echo "</div>";
    
    echo "<div class='filter-group'>";
    echo "<label class='filter-label'>Status</label>";
    echo "<select class='filter-select' id='status-filter' name='status'>";
    $statssel = ($statusfilter === 'all') ? 'selected' : '';
    echo "<option value='all' $statssel>All Status</option>";
    $statssel = ($statusfilter === 'active') ? 'selected' : '';
    echo "<option value='active' $statssel>Active</option>";
    $statssel = ($statusfilter === 'suspended') ? 'selected' : '';
    echo "<option value='suspended' $statssel>Suspended</option>";
    echo "</select>";
    echo "</div>";
    
    echo "</form>";
    echo "</div>";
    
    // Get enrollments with user and course details (students and editingteachers only)
    // Using DISTINCT to avoid duplicates when users have multiple roles
    $enrollment_sql = "SELECT DISTINCT
            ue.id as enrollment_id,
            ue.userid,
            ue.enrolid,
            ue.status,
            ue.timestart,
            ue.timeend,
            ue.timecreated,
            ue.timemodified,
            u.firstname,
            u.lastname,
            u.email,
            u.username,
            c.id as courseid,
                    c.fullname as course_name,
                    c.shortname as course_shortname,
            r.shortname as role_shortname,
            r.name as role_name
         FROM {user_enrolments} ue
         JOIN {user} u ON ue.userid = u.id
         JOIN {enrol} e ON ue.enrolid = e.id
        JOIN {course} c ON e.courseid = c.id
        JOIN {role_assignments} ra ON u.id = ra.userid
        JOIN {role} r ON ra.roleid = r.id
        JOIN {context} ctx ON ra.contextid = ctx.id
        WHERE u.deleted = 0 AND c.id > 1 AND c.visible = 1
        AND r.shortname IN ('student', 'teacher', 'editingteacher')"
        . $adminexclusion .
        $filtersql .
       "
        ORDER BY ue.timecreated DESC";

    $enrollments = $DB->get_records_sql(
        $enrollment_sql,
        $filterparams,
        $offset,
        $perpage
   );
    
    // Enrollments Table
    echo "<div class='enrollments-table-container'>";
    
    if (count($enrollments) > 0) {
        echo "<table class='enrollments-table' id='enrollments-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>User</th>";
        echo "<th>Course</th>";
        echo "<th>Role</th>";
        echo "<th>Status</th>";
        echo "<th>Enrolled Date</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        foreach ($enrollments as $enrollment) {
            $status_class = $enrollment->status ? 'status-suspended' : 'status-active';
            $status_text = $enrollment->status ? 'Suspended' : 'Active';
            
            $enrolled_date = date('M j, Y g:i A', $enrollment->timecreated);
            
            // Get first letter of first name for avatar
            $avatar_letter = strtoupper(substr($enrollment->firstname, 0, 1));
            
            // Determine role display text
            $roleshort = $normalize_role_shortname($enrollment->role_shortname);
            switch ($roleshort) {
                case 'teacher':
                case 'teachers':
                    $role_display = 'Teacher';
                    break;
                case 'editingteacher':
                    $role_display = 'Editing Teacher';
                    break;
                default:
                    $role_display = 'Student';
            }
            $role_class = $roleshort === 'student' ? 'status-active' : 'status-completed';
            
            echo "<tr data-role='{$roleshort}'>";
            echo "<td>";
            echo "<div class='enrollment-info'>";
            echo "<div class='enrollment-avatar'>$avatar_letter</div>";
            echo "<div class='enrollment-details'>";
            echo "<h4>{$enrollment->firstname} {$enrollment->lastname}</h4>";
            echo "<p>{$enrollment->email}</p>";
            echo "</div>";
            echo "</div>";
            echo "</td>";
            echo "<td>";
            echo "<div class='enrollment-details'>";
            echo "<h4>" . s($enrollment->course_name) . "</h4>";
            echo "<p>" . s($enrollment->course_shortname) . "</p>";
            echo "</div>";
            echo "</td>";
            echo "<td><span class='status-badge $role_class'>$role_display</span></td>";
            echo "<td><span class='status-badge $status_class'>$status_text</span></td>";
            echo "<td>$enrolled_date</td>";
            echo "<td>";
            echo "<div class='action-buttons'>";
            if (!$enrollment->status) {
                echo "<button class='btn btn-sm btn-danger' title='Suspend Enrollment' onclick='toggleEnrollmentStatus({$enrollment->enrollment_id}, true, this)'>";
                echo "<i class='fa fa-pause'></i>";
                echo "</button>";
            } else {
                echo "<button class='btn btn-sm btn-success' title='Activate Enrollment' onclick='toggleEnrollmentStatus({$enrollment->enrollment_id}, false, this)'>";
                echo "<i class='fa fa-play'></i>";
                echo "</button>";
            }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";

        $startcount = $filtered_total_enrollments ? ($offset + 1) : 0;
        $endcount = min($offset + $perpage, $filtered_total_enrollments);
        if ($filtered_total_enrollments === 0) {
            $startcount = 0;
            $endcount = 0;
        }

        echo "<div class='pagination-container'>";
        echo "<div class='pagination-info'>Showing $startcount-$endcount of $filtered_total_enrollments enrollments</div>";

        $prevdisabled = $page === 0 ? 'disabled' : '';
        $nextdisabled = ($page + 1) >= $totalpages ? 'disabled' : '';

        $prevtarget = max(0, $page - 1);
        $nexttarget = min($totalpages - 1, $page + 1);
        $prevparams = $enrollmentbaseparams;
        $prevparams['page'] = $prevtarget;
        $nextparams = $enrollmentbaseparams;
        $nextparams['page'] = $nexttarget;
        $prevurl = new moodle_url($PAGE->url, $prevparams);
        $nexturl = new moodle_url($PAGE->url, $nextparams);

        echo "<a class='pagination-button $prevdisabled' data-page='$prevtarget' href='" . $prevurl->out(false) . "'>Prev</a>";

        $initialblock = min(5, $totalpages);
        $pages_to_show = [];
        for ($i = 0; $i < $initialblock; $i++) {
            $pages_to_show[$i] = true;
        }

        if ($totalpages > $initialblock && $page >= $initialblock) {
            $windowstart = $page;
            $windowend = min($page + 3, $totalpages - 1);
            for ($i = $windowstart; $i <= $windowend; $i++) {
                $pages_to_show[$i] = true;
            }
        }

        if ($totalpages > 0) {
            $pages_to_show[$totalpages - 1] = true;
        }

        ksort($pages_to_show);
        $previous_index = null;
        foreach (array_keys($pages_to_show) as $index) {
            if ($previous_index !== null && $index - $previous_index > 1) {
                echo "<span class='pagination-button disabled'>...</span>";
            }
            $pageparams = $enrollmentbaseparams;
            $pageparams['page'] = $index;
            $pageurl = new moodle_url($PAGE->url, $pageparams);
            $active = $index === $page ? 'active' : '';
            $label = $index + 1;
            echo "<a class='pagination-button $active' data-page='$index' href='" . $pageurl->out(false) . "'>$label</a>";
            $previous_index = $index;
        }

        echo "<a class='pagination-button $nextdisabled' data-page='$nexttarget' href='" . $nexturl->out(false) . "'>Next</a>";
        echo "</div>";
        
    } else {
        // No enrollments found
        echo "<div class='no-enrollments'>";
        echo "<i class='fa fa-graduation-cap'></i>";
        echo "<h3>No Enrollments Found</h3>";
        echo "<p>There are no enrollments to display at the moment.</p>";
        echo "</div>";
    }
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "</div>"; // End admin-main-content

// Confirmation Modal
echo "<div id='confirmationModal' class='confirmation-modal'>";
echo "<div class='modal-content'>";
echo "<div class='modal-body'>";
echo "<p class='modal-message' id='modalMessage'>Are you sure you want to perform this action?</p>";
echo "</div>";
echo "<div class='modal-footer'>";
echo "<button class='modal-btn modal-btn-secondary' onclick='closeConfirmationModal()'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</button>";
echo "<button class='modal-btn modal-btn-danger' id='confirmBtn' onclick='confirmAction()'>";
echo "<i class='fa fa-check'></i> Confirm";
echo "</button>";
echo "</div>";
echo "</div>";
echo "</div>";

// Enrollment Modal
echo "<div id='enrollModal' class='confirmation-modal'>";
echo "<div class='modal-content'>";
echo "<div class='modal-body'>";
echo "<h3 style='margin-bottom: 20px; color: #2d3748;'>Enroll New Student</h3>";
echo "<form id='enrollForm'>";
echo "<div class='form-group'>";
echo "<label for='studentSelect'>Select Student:</label>";
echo "<select id='studentSelect' name='student_id' required>";
echo "<option value=''>Choose a student...</option>";
echo "</select>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label for='courseSelect'>Select Course:</label>";
echo "<select id='courseSelect' name='course_id' required>";
echo "<option value=''>Choose a course...</option>";
echo "</select>";
echo "</div>";
echo "</form>";
echo "</div>";
echo "<div class='modal-footer'>";
echo "<button class='modal-btn modal-btn-secondary' onclick='closeEnrollModal()'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</button>";
echo "<button class='modal-btn modal-btn-success' onclick='processEnrollment()'>";
echo "<i class='fa fa-plus'></i> Enroll Student";
echo "</button>";
echo "</div>";
echo "</div>";
echo "</div>";

// Ensure AI assistant is moved to body for global positioning
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    var assistant = document.getElementById('ai-assistant-chatbot');
    if (assistant && assistant.parentNode !== document.body) {
        document.body.appendChild(assistant);
    }
});
</script>";

// Add JavaScript for filtering functionality
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    initEnrollmentFilterAutoSubmit();
});

const enrollmentPagination = {
    perPage: {$perpage},
    currentPage: {$page},
    isLoading: false
};

function appendFiltersToParams(params, includeDefaults) {
    const form = document.getElementById('enrollmentsFilterForm');
    if (!form) {
        return params;
    }
    const formData = new FormData(form);
    formData.forEach((value, key) => {
        if (key === 'page') {
            return;
        }
        if (!includeDefaults) {
            if (key === 'search' && (!value || value.trim() === '')) {
                return;
            }
            if ((key === 'course' || key === 'role' || key === 'status') && (value === 'all' || value === '')) {
                return;
            }
        }
        params.set(key, value);
    });
    return params;
}

function buildEnrollmentsRequestUrl(pageNumber) {
    const params = new URLSearchParams();
    params.set('action', 'get_enrollments');
    params.set('page', pageNumber);
    appendFiltersToParams(params, true);
    return window.location.pathname + '?' + params.toString();
}

function buildBrowserPageUrl(pageNumber) {
    const params = new URLSearchParams();
    params.set('page', pageNumber);
    appendFiltersToParams(params, false);
    return window.location.pathname + '?' + params.toString();
}

function updateFilterPageField(pageNumber) {
    const form = document.getElementById('enrollmentsFilterForm');
    if (!form) {
        return;
    }
    const pageField = form.querySelector('input[name=\"page\"]');
    if (pageField) {
        pageField.value = String(pageNumber);
    }
}

function submitEnrollmentFiltersAjax() {
    loadEnrollments(0);
}

function initEnrollmentFilterAutoSubmit() {
    const form = document.getElementById('enrollmentsFilterForm');
    if (!form) {
        return;
    }
    const searchInput = form.querySelector('input[name=\"search\"]');
    const selects = form.querySelectorAll('select');
    const resetPage = () => updateFilterPageField(0);
    let debounceTimer = null;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                resetPage();
                submitEnrollmentFiltersAjax();
            }, 400);
        });
    }

    selects.forEach(select => {
        select.addEventListener('change', function() {
            resetPage();
            submitEnrollmentFiltersAjax();
        });
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        resetPage();
        submitEnrollmentFiltersAjax();
    });
}

function renderEnrollmentRows(enrollments) {
    const table = document.getElementById('enrollments-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    if (!enrollments.length) {
        tbody.innerHTML = '<tr><td colspan=\"6\" style=\"text-align:center; padding: 20px;\">No enrollments found.</td></tr>';
        return;
    }

    let rowsHtml = '';
    enrollments.forEach(enrollment => {
        rowsHtml += '<tr data-role=\"' + enrollment.role_shortname + '\">';
        rowsHtml += '<td><div class=\"enrollment-info\">';
        rowsHtml += '<div class=\"enrollment-avatar\">' + enrollment.avatar_letter + '</div>';
        rowsHtml += '<div class=\"enrollment-details\"><h4>' + enrollment.fullname + '</h4>';
        rowsHtml += '<p>' + enrollment.email + '</p></div></div></td>';
        rowsHtml += '<td><div class=\"enrollment-details\"><h4>' + enrollment.course_name + '</h4>';
        rowsHtml += '<p>' + enrollment.course_shortname + '</p></div></td>';
        rowsHtml += '<td><span class=\"status-badge ' + enrollment.role_class + '\">' + enrollment.role_display + '</span></td>';
        rowsHtml += '<td><span class=\"status-badge ' + enrollment.status_class + '\">' + enrollment.status_text + '</span></td>';
        rowsHtml += '<td>' + enrollment.enrolled_date + '</td>';
        rowsHtml += '<td><div class=\"action-buttons\">';
        if (enrollment.status === 0) {
            rowsHtml += '<button class=\"btn btn-sm btn-danger\" title=\"Suspend Enrollment\" onclick=\"toggleEnrollmentStatus(' + enrollment.enrollment_id + ', true, this)\"><i class=\"fa fa-pause\"></i></button>';
        } else {
            rowsHtml += '<button class=\"btn btn-sm btn-success\" title=\"Activate Enrollment\" onclick=\"toggleEnrollmentStatus(' + enrollment.enrollment_id + ', false, this)\"><i class=\"fa fa-play\"></i></button>';
        }
        rowsHtml += '</div></td></tr>';
    });

    tbody.innerHTML = rowsHtml;
}

function rebuildPagination(data) {
    const container = document.querySelector('.pagination-container');
    if (!container) return;

    let html = '<div class=\"pagination-info\">Showing ' + data.startcount + '-' + data.endcount + ' of ' + data.total + ' enrollments</div>';
    const prevTarget = Math.max(0, data.page - 1);
    const nextTarget = Math.min(data.totalpages - 1, data.page + 1);
    const prevDisabled = data.page === 0 ? 'disabled' : '';
    const nextDisabled = (data.page + 1) >= data.totalpages ? 'disabled' : '';

    const prevHref = buildBrowserPageUrl(prevTarget);
    html += '<a class=\"pagination-button ' + prevDisabled + '\" data-page=\"' + prevTarget + '\" href=\"' + prevHref + '\">Prev</a>';

    const pagesToShow = new Map();
    const initialBlock = Math.min(5, data.totalpages);
    for (let i = 0; i < initialBlock; i++) {
        pagesToShow.set(i, true);
    }
    if (data.totalpages > initialBlock && data.page >= initialBlock) {
        const windowEnd = Math.min(data.page + 3, data.totalpages - 1);
        for (let i = data.page; i <= windowEnd; i++) {
            pagesToShow.set(i, true);
        }
    }
    if (data.totalpages > 0) {
        pagesToShow.set(data.totalpages - 1, true);
    }

    const sortedPages = Array.from(pagesToShow.keys()).sort((a, b) => a - b);
    let previousIndex = null;
    sortedPages.forEach(index => {
        if (previousIndex !== null && index - previousIndex > 1) {
            html += '<span class=\"pagination-button disabled\">...</span>';
        }
        const active = index === data.page ? 'active' : '';
        const pageHref = buildBrowserPageUrl(index);
        html += '<a class=\"pagination-button ' + active + '\" data-page=\"' + index + '\" href=\"' + pageHref + '\">' + (index + 1) + '</a>';
        previousIndex = index;
    });

    const nextHref = buildBrowserPageUrl(nextTarget);
    html += '<a class=\"pagination-button ' + nextDisabled + '\" data-page=\"' + nextTarget + '\" href=\"' + nextHref + '\">Next</a>';

    container.innerHTML = html;
}

function loadEnrollments(targetPage) {
    if (enrollmentPagination.isLoading) {
        return;
    }
    enrollmentPagination.isLoading = true;
    updateFilterPageField(targetPage);
    const tableBody = document.querySelector('#enrollments-table tbody');
    if (tableBody) {
        tableBody.style.opacity = '0.5';
    }

    fetch(buildEnrollmentsRequestUrl(targetPage))
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                enrollmentPagination.currentPage = data.page;
                updateFilterPageField(data.page);
                renderEnrollmentRows(data.enrollments);
                rebuildPagination(data);
                if (data.pageurl) {
                    history.replaceState(null, '', data.pageurl);
                } else {
                    history.replaceState(null, '', buildBrowserPageUrl(data.page));
                }
            }
        })
        .catch(error => {
            console.error('Error loading enrollments:', error);
        })
        .finally(() => {
            enrollmentPagination.isLoading = false;
            if (tableBody) {
                tableBody.style.opacity = '';
            }
        });
}

document.addEventListener('click', function(event) {
    const target = event.target.closest('.pagination-button');
    if (!target || target.classList.contains('disabled')) {
        return;
    }
    const pageValue = target.getAttribute('data-page');
    if (pageValue === null) {
        return;
    }
    const pageNumber = parseInt(pageValue, 10);
    if (isNaN(pageNumber) || pageNumber === enrollmentPagination.currentPage) {
        return;
    }
    event.preventDefault();
    loadEnrollments(pageNumber);
});

// Global variables for modal
let currentEnrollmentId = null;
let currentAction = null;

function toggleEnrollmentStatus(enrollmentId, suspend) {
    currentEnrollmentId = enrollmentId;
    currentAction = suspend ? 'suspend' : 'activate';

    const modal = document.getElementById('confirmationModal');
    if (modal) {
        // Update modal content based on action
        const modalMessage = document.getElementById('modalMessage');
        const confirmBtn = document.getElementById('confirmBtn');
        
        if (suspend) {
            modalMessage.innerHTML = 'Are you sure you want to suspend this enrollment?<br>The student will lose access to the course until reactivated.';
            confirmBtn.className = 'modal-btn modal-btn-danger';
            confirmBtn.innerHTML = '<i class=\"fa fa-pause\"></i> Suspend';
        } else {
            modalMessage.innerHTML = 'Are you sure you want to activate this enrollment?<br>The student will regain access to the course.';
            confirmBtn.className = 'modal-btn modal-btn-success';
            confirmBtn.innerHTML = '<i class=\"fa fa-play\"></i> Activate';
        }
        
        // Show modal immediately
        modal.style.display = 'block';
        
        // Add click outside to close
        modal.onclick = function(event) {
            if (event.target === modal) {
                closeConfirmationModal();
            }
        };
    } else {
        // Fallback to simple confirm dialog
        const message = suspend ? 
            'Are you sure you want to suspend this enrollment? The student will lose access to the course until reactivated.' :
            'Are you sure you want to activate this enrollment? The student will regain access to the course.';
        
        if (confirm(message)) {
            confirmAction();
        }
    }
}

function closeConfirmationModal() {
    const modal = document.getElementById('confirmationModal');
    if (modal) {
        modal.style.display = 'none';
    }
    currentEnrollmentId = null;
    currentAction = null;
}

function loadStudents() {
    const studentSelect = document.getElementById('studentSelect');
    if (!studentSelect) return;
    
    // Clear existing options except the first one
    studentSelect.innerHTML = '<option value=\"\">Choose a student...</option>';
    
    // Fetch students via AJAX
    fetch('?action=get_students')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                data.students.forEach(student => {
                    const option = document.createElement('option');
                    option.value = student.id;
                    option.textContent = student.firstname + ' ' + student.lastname + ' (' + student.email + ')';
                    studentSelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading students:', error);
        });
}

function loadCourses() {
    const courseSelect = document.getElementById('courseSelect');
    if (!courseSelect) return;
    
    // Clear existing options except the first one
    courseSelect.innerHTML = '<option value=\"\">Choose a course...</option>';
    
    // Fetch courses via AJAX
    fetch('?action=get_courses')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                data.courses.forEach(course => {
                    const option = document.createElement('option');
                    option.value = course.id;
                    option.textContent = course.fullname;
                    courseSelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading courses:', error);
        });
}

function processEnrollment() {
    const form = document.getElementById('enrollForm');
    const formData = new FormData(form);
    
    // Validate form
    const studentId = formData.get('student_id');
    const courseId = formData.get('course_id');
    
    if (!studentId || !courseId) {
        alert('Please fill in all fields');
        return;
    }
    
    // Add action
    formData.append('action', 'enroll_student');
    
    // Show loading state
    const enrollBtn = document.querySelector('#enrollModal .modal-btn-success');
    const originalText = enrollBtn.innerHTML;
    enrollBtn.innerHTML = '<i class=\"fa fa-spinner fa-spin\"></i> Enrolling...';
    enrollBtn.disabled = true;
    
    // Submit enrollment
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            enrollBtn.innerHTML = '<i class=\"fa fa-check\"></i> Enrolled!';
            enrollBtn.className = 'modal-btn modal-btn-success';
            setTimeout(() => {
                closeEnrollModal();
                location.reload();
            }, 1500);
        } else {
            enrollBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
            enrollBtn.className = 'modal-btn modal-btn-danger';
            alert('Error: ' + data.message);
            setTimeout(() => {
                enrollBtn.innerHTML = originalText;
                enrollBtn.disabled = false;
                enrollBtn.className = 'modal-btn modal-btn-success';
            }, 2000);
        }
    })
    .catch(error => {
        enrollBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
        enrollBtn.className = 'modal-btn modal-btn-danger';
        alert('Error enrolling student');
        setTimeout(() => {
            enrollBtn.innerHTML = originalText;
            enrollBtn.disabled = false;
            enrollBtn.className = 'modal-btn modal-btn-success';
        }, 2000);
    });
}

function confirmAction() {
    if (!currentEnrollmentId) return;
    
    const formData = new FormData();
    formData.append('action', 'toggle_enrollment_status');
    formData.append('enrollment_id', currentEnrollmentId);
    
    const confirmBtn = document.getElementById('confirmBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class=\"fa fa-spinner fa-spin\"></i> Processing...';
    confirmBtn.disabled = true;
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            // Show success message briefly
            confirmBtn.innerHTML = '<i class=\"fa fa-check\"></i> Success!';
            confirmBtn.className = 'modal-btn modal-btn-success';
            
            setTimeout(() => {
                closeConfirmationModal();
                location.reload();
            }, 1500);
        } else {
            // Show error
            confirmBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
            confirmBtn.className = 'modal-btn modal-btn-danger';
            setTimeout(() => {
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            }, 2000);
        }
    })
    .catch(error => {
        confirmBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
        confirmBtn.className = 'modal-btn modal-btn-danger';
        setTimeout(() => {
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }, 2000);
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('confirmationModal');
    if (event.target === modal) {
        closeConfirmationModal();
    }
}
</script>";

echo $OUTPUT->footer();
?>
