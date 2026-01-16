<?php
/**
 * High School Assignments Page (Grade 9-12)
 * Displays assignments for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_assignments.php');
$PAGE->set_title('My Assignments');
$PAGE->set_heading('My Assignments');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

// Check if user is a student (has student role)
$user_roles = get_user_roles($context, $USER->id);
$is_student = false;
foreach ($user_roles as $role) {
    if ($role->shortname === 'student') {
        $is_student = true;
        break;
    }
}

// Also check for editingteacher and teacher roles as they might be testing the page
foreach ($user_roles as $role) {
    if ($role->shortname === 'editingteacher' || $role->shortname === 'teacher' || $role->shortname === 'manager') {
        $is_student = true; // Allow teachers/managers to view the page
        break;
    }
}

// Redirect if not a student and not logged in
if (!$is_student && !isloggedin()) {
    redirect(new moodle_url('/'));
}

// Get user's grade level from profile or cohort
$user_grade = 'Grade 11'; // Default grade for testing
$is_highschool = false;
$user_cohorts = cohort_get_user_cohorts($USER->id);

// Check user profile custom field for grade
$user_profile_fields = profile_user_record($USER->id);
if (isset($user_profile_fields->grade)) {
    $user_grade = $user_profile_fields->grade;
    // If profile has a high school grade, mark as high school
    if (preg_match('/grade\s*(?:9|10|11|12)/i', $user_grade)) {
        $is_highschool = true;
    }
} else {
    // Fallback to cohort-based detection
    foreach ($user_cohorts as $cohort) {
        $cohort_name = strtolower($cohort->name);
        // Use regex for better matching
        if (preg_match('/grade\s*(?:9|10|11|12)/i', $cohort_name)) {
            // Extract grade number
            if (preg_match('/grade\s*9/i', $cohort_name)) {
                $user_grade = 'Grade 9';
            } elseif (preg_match('/grade\s*10/i', $cohort_name)) {
                $user_grade = 'Grade 10';
            } elseif (preg_match('/grade\s*11/i', $cohort_name)) {
                $user_grade = 'Grade 11';
            } elseif (preg_match('/grade\s*12/i', $cohort_name)) {
                $user_grade = 'Grade 12';
            }
            $is_highschool = true;
            break;
        }
    }
}

// More flexible verification - allow access if user has high school grade OR is in grades 9-12
// Don't redirect if user is a teacher/manager testing the page
$valid_grades = array('Grade 9', 'Grade 10', 'Grade 11', 'Grade 12', '9', '10', '11', '12');
$has_valid_grade = false;

foreach ($valid_grades as $grade) {
    if (stripos($user_grade, $grade) !== false) {
        $has_valid_grade = true;
        break;
    }
}

// Only redirect if NOT high school and NOT valid grade
// This is more permissive to avoid blocking legitimate users
if (!$is_highschool && !$has_valid_grade) {
    // For debugging: comment out redirect temporarily
    // redirect(new moodle_url('/my/'));
    // Instead, just show a warning and continue (for testing)
    // You can re-enable the redirect once everything is working
}

// Get assignments for the student
$assignments_data = array();

// Get all courses the user is enrolled in
$enrolled_courses = enrol_get_users_courses($USER->id, true, array('id', 'fullname', 'shortname', 'summary', 'category'));

// Get assignments from enrolled courses
foreach ($enrolled_courses as $course) {
    if ($course->id == 1)
        continue; // Skip site course

    try {
        $course_context = context_course::instance($course->id);

        // Get course module info to check visibility
        $modinfo = get_fast_modinfo($course, $USER->id);
        $assignments_instances = $modinfo->get_instances_of('assign');

        foreach ($assignments_instances as $cm) {
            // Skip hidden or unavailable assignments
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }

            // Get assignment instance data
            $assignment = $DB->get_record('assign', array('id' => $cm->instance), '*', MUST_EXIST);

            // Get assignment submission info
            $submission = $DB->get_record(
                'assign_submission',
                array('assignment' => $assignment->id, 'userid' => $USER->id)
            );

            // Get assignment grade
            $grade = $DB->get_record(
                'assign_grades',
                array('assignment' => $assignment->id, 'userid' => $USER->id)
            );

            // Determine assignment status
            $status = 'not_started';
            $progress = 0;

            if ($submission) {
                if ($submission->status == 'submitted') {
                    $status = 'submitted';
                    $progress = 100;
                } elseif ($submission->status == 'draft') {
                    $status = 'in_progress';
                    $progress = 50;
                }
            }

            // Check if assignment is overdue
            $is_overdue = false;
            if ($assignment->duedate > 0 && $assignment->duedate < time() && $status != 'submitted') {
                $is_overdue = true;
                $status = 'overdue';
            }

            // Get course URL
            $course_url = new moodle_url('/course/view.php', array('id' => $course->id));
            $assignment_url = new moodle_url('/mod/assign/view.php', array('id' => $cm->id));

            // Add status flags for Mustache template
            $is_submitted = ($status == 'submitted');
            $is_in_progress = ($status == 'in_progress');
            $is_overdue_flag = ($status == 'overdue');
            $is_not_started = ($status == 'not_started');

            $assignment_data = array(
                'id' => $assignment->id,
                'name' => $assignment->name,
                'description' => format_text($assignment->intro, FORMAT_HTML),
                'course_name' => $course->fullname,
                'course_shortname' => $course->shortname,
                'course_url' => $course_url->out(),
                'assignment_url' => $assignment_url->out(),
                'duedate' => $assignment->duedate,
                'duedate_formatted' => $assignment->duedate > 0 ? date('M j, Y g:i A', $assignment->duedate) : 'No due date',
                'time_remaining' => $assignment->duedate > 0 ? ($assignment->duedate - time()) : 0,
                'status' => $status,
                'progress' => $progress,
                'grade' => $grade ? $grade->grade : null,
                'submission_status' => $submission ? $submission->status : 'not_started',
                // Status flags for Mustache template
                'is_submitted' => $is_submitted,
                'is_in_progress' => $is_in_progress,
                'is_overdue' => $is_overdue_flag,
                'is_not_started' => $is_not_started
            );

            $assignments_data[] = $assignment_data;
        }

    } catch (Exception $e) {
        // Skip courses that don't exist or have permission issues
        continue;
    }
}

// Sort assignments by due date
usort($assignments_data, function ($a, $b) {
    if ($a['duedate'] == $b['duedate'])
        return 0;
    return ($a['duedate'] < $b['duedate']) ? -1 : 1;
});

// Calculate statistics
$total_assignments = count($assignments_data);
$submitted_assignments = 0;
$in_progress_assignments = 0;
$overdue_assignments = 0;
$not_started_assignments = 0;

foreach ($assignments_data as $assignment) {
    if ($assignment['status'] == 'submitted') {
        $submitted_assignments++;
    } elseif ($assignment['status'] == 'in_progress') {
        $in_progress_assignments++;
    } elseif ($assignment['status'] == 'overdue') {
        $overdue_assignments++;
    } else {
        $not_started_assignments++;
    }
}

$sidebar_context = remui_kids_build_highschool_sidebar_context('assignments', $USER);

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'assignments' => $assignments_data,
    'has_assignments' => !empty($assignments_data),
    'total_assignments' => $total_assignments,
    'submitted_assignments' => $submitted_assignments,
    'in_progress_assignments' => $in_progress_assignments,
    'overdue_assignments' => $overdue_assignments,
    'not_started_assignments' => $not_started_assignments,
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'messages_url' => (new moodle_url('/message/index.php'))->out(),
    'profile_url' => (new moodle_url('/user/profile.php', array('id' => $USER->id)))->out(),
    'logout_url' => (new moodle_url('/login/logout.php', array('sesskey' => sesskey())))->out(),
    'is_highschool' => true
));

// Output page header with Moodle navigation
echo $OUTPUT->header();

// Render shared highschool sidebar
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $template_data);

// Render assignments view template
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_assignments_view', $template_data);

// Add custom CSS for the assignments page
?>
<style>
    /* Hide Moodle default page heading to avoid duplicate titles */
    .highschool-assignments-page .page-header,
    .highschool-assignments-page #page-header,
    .highschool-assignments-page .page-context-header {
        display: none !important;
    }
    .footer-copyright-wrapper ,.footer-mainsection-wrapper{
        display: none !important;
     } 
    /* Custom styles for High School Assignments Page */
    .highschool-assignments-page {
        position: relative;
        min-height: 100vh;
        padding-left: 2rem;
        padding-top: 1rem;
    }

    .assignments-main-content {
        padding: 0;
        width: 100%;
    }

    /* Remove all padding for full-width feel */
    .container-fluid {
        padding-left: 0;
        padding-right: 0;
    }

    /* Remove all padding from main content */
    .assignments-main-content {
        padding: 0 !important;
    }

    /* Remove padding from page wrapper */
    #page-wrapper {
        padding: 0 !important;
    }

    /* Remove padding from page content */
    #page-content {
        padding: 0 !important;
    }

    /* Remove all margins and padding from main content areas */
    .main-content,
    .content,
    .region-main,
    .region-main-content {
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Remove padding from row and column classes */
    .row {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }

    .col-lg-3,
    .col-md-6,
    .col-12 {
        padding-left: 0.5rem !important;
        padding-right: 0.5rem !important;
    }

    .assignments-page-header {
        background: #ffffff;
        color: #1e293b;
        padding: 2rem;
        margin-bottom: 1.5rem;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 2px solid #e0f2fe;
    }

    .assignments-page-header .page-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .stat-card {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 20px;
        padding: 1.75rem;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        box-shadow: 0 4px 20px rgba(125, 211, 252, 0.15);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid #e0f2fe;
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #7dd3fc 0%, #38bdf8 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(125, 211, 252, 0.25);
        border-color: #7dd3fc;
    }

    .stat-card:hover::before {
        opacity: 1;
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: white;
    }

    .stat-icon.total {
        background: linear-gradient(135deg, #7dd3fc 0%, #38bdf8 100%);
    }

    .stat-icon.progress {
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    }

    .stat-icon.completed {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .stat-icon.overdue {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #1a202c;
    }

    .assignments-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 1rem;
        padding: 0;
        margin: 0;
    }

    .assignment-card {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(125, 211, 252, 0.12);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid #e0f2fe;
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 400px;
    }

    .assignment-card::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(125, 211, 252, 0.05) 0%, rgba(186, 230, 253, 0.05) 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }

    .assignment-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 12px 40px rgba(125, 211, 252, 0.25);
        border-color: #7dd3fc;
    }

    .assignment-card:hover::after {
        opacity: 1;
    }

    .assignment-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        flex-shrink: 0;
    }

    .assignment-status-badge {
        display: inline-block;
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        color: white;
        margin-bottom: 1rem;
    }

    .assignment-status-badge.submitted {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
    }

    .assignment-status-badge.in_progress {
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        color: white;
    }

    .assignment-status-badge.overdue {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
    }

    .assignment-status-badge.not_started {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
        color: white;
    }

    .assignment-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: #1a202c;
    }

    .assignment-course {
        font-size: 0.9rem;
        color: #0284c7;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .assignment-due-date {
        font-size: 0.9rem;
        color: #718096;
    }

    .assignment-content {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .assignment-description {
        color: #4a5568;
        margin-bottom: 1rem;
        line-height: 1.6;
        min-height: 40px;
        max-height: 60px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .assignment-description:empty {
        min-height: 40px;
        margin-bottom: 1rem;
    }

    .assignment-progress {
        margin-bottom: 1rem;
        flex-shrink: 0;
    }

    .progress-bar-container {
        width: 100%;
        height: 10px;
        background: linear-gradient(90deg, #e2e8f0 0%, #f7fafc 100%);
        border-radius: 10px;
        overflow: hidden;
        margin: 0.75rem 0;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        position: relative;
    }

    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #7dd3fc 0%, #38bdf8 100%);
        border-radius: 10px;
        position: relative;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 8px rgba(125, 211, 252, 0.3);
    }

    .progress-bar-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.3) 0%, transparent 100%);
        border-radius: 10px 10px 0 0;
    }

    .btn-primary {
        background: linear-gradient(135deg, #7dd3fc 0%, #38bdf8 100%);
        border: none;
        color: #1e293b;
        padding: 0.875rem 1.5rem;
        border-radius: 12px;
        font-weight: 600;
        width: 100%;
        text-decoration: none;
        display: block;
        text-align: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 15px rgba(125, 211, 252, 0.3);
        position: relative;
        overflow: hidden;
        margin-top: auto;
    }

    .btn-primary::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(125, 211, 252, 0.4);
    }

    .btn-primary:hover::before {
        width: 300px;
        height: 300px;
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    /* Enhanced Assignment Features */
    .assignment-priority-indicator {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10b981;
    }

    .assignment-priority-indicator.high {
        background: #ef4444;
        animation: pulse 2s infinite;
    }

    .assignment-priority-indicator.medium {
        background: #fbbf24;
    }

    .assignment-priority-indicator.low {
        background: #6b7280;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    .assignment-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #7dd3fc 0%, #38bdf8 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        margin-right: 1rem;
        flex-shrink: 0;
    }

    .assignment-meta {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }

    .assignment-instructor {
        font-weight: 600;
        color: #1e293b;
    }

    .assignment-time {
        font-size: 0.875rem;
        color: #64748b;
    }

    .assignment-course-badge {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        color: #0284c7;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid #7dd3fc;
    }

    .assignment-due-countdown {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 1px solid #f59e0b;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #92400e;
        margin-top: 0.5rem;
    }

    .assignment-filters {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .filter-btn {
        padding: 0.5rem 1rem;
        border: 2px solid #e2e8f0;
        background: white;
        color: #64748b;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 500;
    }

    .filter-btn.active {
        background: linear-gradient(135deg, #7dd3fc 0%, #38bdf8 100%);
        color: #1e293b;
        border-color: #38bdf8;
    }

    .filter-btn:hover:not(.active) {
        border-color: #7dd3fc;
        color: #0284c7;
    }

    .assignment-search {
        margin-bottom: 1rem;
    }

    .search-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .search-input:focus {
        outline: none;
        border-color: #7dd3fc;
        box-shadow: 0 0 0 4px rgba(125, 211, 252, 0.1);
    }

    .btn-outline-light {
        background: transparent;
        border: 2px solid #e2e8f0;
        color: #64748b;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-outline-light:hover {
        background: #f8fafc;
        border-color: #7dd3fc;
        color: #0284c7;
        transform: translateY(-2px);
        text-decoration: none;
    }

    .assignment-grade {
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        border: 1px solid #bbf7d0;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #166534;
        margin-top: 0.5rem;
    }

    .assignment-feedback {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 1px solid #f59e0b;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
        color: #92400e;
        margin-top: 0.5rem;
    }

    .assignment-attachments {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .attachment-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        color: #64748b;
    }
    @media (max-width: 768px) {
        .highschool-assignments-page {
            margin-left: 0 !important;
            padding-left: 1rem !important;
        }

        .assignments-page-header {
            margin-left: -1rem !important;
            width: calc(100% + 1rem) !important;
        }

        .assignments-page-header .page-title {
            font-size: 1.8rem;
        }

        .assignments-main-content {
            padding: 0.5rem;
        }

        .container-fluid {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
    }
</style>

<script>
    // Initialize enhanced sidebar

    document.addEventListener('DOMContentLoaded', function () {
        const enhancedSidebar = document.querySelector('.enhanced-sidebar');
        if (enhancedSidebar) {
            document.body.classList.add('has-student-sidebar', 'has-enhanced-sidebar');
            console.log('Enhanced sidebar initialized for high school assignments page');
        }

        // Handle sidebar navigation - set active state
        const currentUrl = window.location.href;
        const navLinks = document.querySelectorAll('.student-sidebar .nav-link');
        navLinks.forEach(link => {
            if (link.href === currentUrl) {
                link.classList.add('active');
            }
        });

        // Mobile sidebar toggle (if you add a toggle button in the future)
        const sidebarToggle = document.getElementById('sidebar-toggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function () {
                enhancedSidebar.classList.toggle('show');
            });
        }

        // Add assignment count animation
        function animateAssignmentCount() {
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const finalValue = parseInt(stat.textContent);
                let currentValue = 0;
                const increment = finalValue / 20;

                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        stat.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        stat.textContent = Math.floor(currentValue);
                    }
                }, 50);
            });
        }

        // Initialize animations
        setTimeout(animateAssignmentCount, 500);

        // Add assignment card hover effects
        const assignmentCards = document.querySelectorAll('.assignment-card');
        assignmentCards.forEach(card => {
            card.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });

            card.addEventListener('mouseleave', function () {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add progress bar animation
        const progressBars = document.querySelectorAll('.progress-bar-fill');
        progressBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = width;
            }, 1000);
        });

        // Add assignment filter functionality
        const filterButtons = document.querySelectorAll('.filter-btn');
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                filterButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const filter = this.textContent.toLowerCase();
                const assignmentCards = document.querySelectorAll('.assignment-card');

                assignmentCards.forEach(card => {
                    const status = card.querySelector('.assignment-status-badge').className;
                    if (filter === 'all' || status.includes(filter.replace(' ', '_'))) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });

        // Add assignment search functionality
        const searchInput = document.getElementById('assignmentSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();
                const assignmentCards = document.querySelectorAll('.assignment-card');

                assignmentCards.forEach(card => {
                    const assignmentTitle = card.querySelector('.assignment-title').textContent.toLowerCase();
                    const assignmentDescription = card.querySelector('.assignment-description').textContent.toLowerCase();
                    const courseName = card.querySelector('.assignment-course').textContent.toLowerCase();

                    if (assignmentTitle.includes(searchTerm) ||
                        assignmentDescription.includes(searchTerm) ||
                        courseName.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        // Add keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('assignmentSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }

            if (e.key === 'Escape') {
                const searchInput = document.getElementById('assignmentSearch');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                }
            }
        });

        // Add assignment card click animation
        assignmentCards.forEach(card => {
            card.addEventListener('click', function (e) {
                if (e.target.closest('.btn-primary') || e.target.closest('a')) {
                    return;
                }
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                }, 150);
            });
        });

        // Add due date countdown
        function updateDueDateCountdowns() {
            const dueDates = document.querySelectorAll('.assignment-due-date');
            dueDates.forEach(dueDate => {
                const dateText = dueDate.textContent;
                if (dateText.includes('Due:')) {
                    const dateStr = dateText.split('Due: ')[1];
                    const dueDateObj = new Date(dateStr);
                    const now = new Date();
                    const diff = dueDateObj - now;

                    if (diff > 0) {
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

                        if (days > 0) {
                            dueDate.innerHTML = `<i class="fa fa-calendar"></i> Due: ${dateStr} <span style="color: #059669;">(${days} days left)</span>`;
                        } else if (hours > 0) {
                            dueDate.innerHTML = `<i class="fa fa-calendar"></i> Due: ${dateStr} <span style="color: #d97706;">(${hours} hours left)</span>`;
                        } else {
                            dueDate.innerHTML = `<i class="fa fa-calendar"></i> Due: ${dateStr} <span style="color: #dc2626;">(Due soon!)</span>`;
                        }
                    }
                }
            });
        }

        updateDueDateCountdowns();
        setInterval(updateDueDateCountdowns, 60000);
    });
</script>
<?php
echo $OUTPUT->footer();
?>