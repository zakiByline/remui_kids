<?php
/**
 * High School Messages Page (Grade 9-12)
 * Displays messages for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_login();

if (!function_exists('theme_remui_kids_highschool_messages_collect_uploads')) {
    /**
     * Normalize uploaded files array for multi-file inputs.
     *
     * @param string $key
     * @return array<int,array<string,mixed>>
     */
    function theme_remui_kids_highschool_messages_collect_uploads(string $key): array
    {
        if (empty($_FILES[$key])) {
            return [];
        }

        $uploads = [];
        $filedata = $_FILES[$key];

        if (is_array($filedata['name'])) {
            foreach ($filedata['name'] as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $uploads[] = [
                    'name' => $name,
                    'tmp_name' => $filedata['tmp_name'][$idx],
                    'type' => $filedata['type'][$idx],
                    'error' => $filedata['error'][$idx],
                    'size' => $filedata['size'][$idx],
                ];
            }
        } else if (!empty($filedata['name'])) {
            $uploads[] = [
                'name' => $filedata['name'],
                'tmp_name' => $filedata['tmp_name'],
                'type' => $filedata['type'],
                'error' => $filedata['error'],
                'size' => $filedata['size'],
            ];
        }

        return $uploads;
    }
}

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_messages.php');
$PAGE->set_title('Messages');
$PAGE->set_heading('Messages');
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

// Initialize student-teacher query service.
$studentqueryservice = new \theme_remui_kids\local\doubts\student_service();
$studentqueryaction = optional_param('queryaction', '', PARAM_ALPHA);
$studentqueryfiltercourse = optional_param('querycourse', 0, PARAM_INT);
$selectedstudentquery = optional_param('studentquery', 0, PARAM_INT);

if ($studentqueryaction === 'reply' && confirm_sesskey()) {
    require_sesskey();
    $replydoubtid = required_param('doubtid', PARAM_INT);
    $replymessage = optional_param('message', '', PARAM_RAW);
    $replyuploads = theme_remui_kids_highschool_messages_collect_uploads('queryreplyattachments');

    try {
        $studentqueryservice->reply($replydoubtid, $USER->id, $replymessage, $replyuploads);
        $redirecturl = new moodle_url('/theme/remui_kids/highschool_messages.php', ['studentquery' => $replydoubtid]);
        if ($studentqueryfiltercourse) {
            $redirecturl->param('querycourse', $studentqueryfiltercourse);
        }
        redirect($redirecturl, get_string('student_doubt_reply_sent', 'theme_remui_kids'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $ex) {
        \core\notification::add(get_string($ex->errorcode, $ex->module, $ex->a ?? null), \core\output\notification::NOTIFY_ERROR);
        $selectedstudentquery = $replydoubtid;
    }
}

if ($studentqueryaction === 'create' && confirm_sesskey()) {
    require_sesskey();
    $subject = optional_param('querysubject', '', PARAM_TEXT);
    $details = optional_param('querydetails', '', PARAM_RAW);
    $courseid = optional_param('querycourseid', 0, PARAM_INT);
    $priority = optional_param('querypriority', \theme_remui_kids\local\doubts\constants::PRIORITY_NORMAL, PARAM_ALPHA);
    $createuploads = theme_remui_kids_highschool_messages_collect_uploads('queryattachments');

    try {
        $newdoubtid = $studentqueryservice->create($USER->id, $courseid, $subject, $details, $priority, $createuploads);
        $redirecturl = new moodle_url('/theme/remui_kids/highschool_messages.php', ['studentquery' => $newdoubtid]);
        if ($studentqueryfiltercourse) {
            $redirecturl->param('querycourse', $studentqueryfiltercourse);
        }
        redirect($redirecturl, get_string('student_doubt_created', 'theme_remui_kids'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $ex) {
        \core\notification::add(get_string($ex->errorcode, $ex->module, $ex->a ?? null), \core\output\notification::NOTIFY_ERROR);
    }
}

$selectedquerycourseid = $studentqueryfiltercourse ?: null;
$studentqueries = $studentqueryservice->list($USER->id, $selectedquerycourseid);

if (!$selectedstudentquery && !empty($studentqueries)) {
    $firstquery = reset($studentqueries);
    $selectedstudentquery = $firstquery['id'];
    reset($studentqueries);
}

$studentquerydetail = null;
if ($selectedstudentquery) {
    try {
        $studentquerydetail = $studentqueryservice->get_detail($selectedstudentquery, $USER->id);
    } catch (\moodle_exception $ex) {
        debugging($ex->getMessage(), DEBUG_DEVELOPER);
    }
}

$enrolledcourses = enrol_get_users_courses($USER->id, true, 'id, fullname');
$courseoptions = [];
$courseoptions[] = [
    'id' => 0,
    'name' => get_string('student_doubts_filter_allcourses', 'theme_remui_kids'),
    'selected' => empty($studentqueryfiltercourse),
];

$querydefaultcourseid = 0;
if (!empty($enrolledcourses)) {
    if ($studentqueryfiltercourse && isset($enrolledcourses[$studentqueryfiltercourse])) {
        $querydefaultcourseid = $studentqueryfiltercourse;
    } else {
        $firstcourse = reset($enrolledcourses);
        $querydefaultcourseid = $firstcourse->id;
    }
    reset($enrolledcourses);
}

foreach ($enrolledcourses as $course) {
    $courseoptions[] = [
        'id' => (int) $course->id,
        'name' => format_string($course->fullname, true),
        'selected' => ((int) $course->id === (int) $studentqueryfiltercourse),
    ];
}

$priorityoptions = array_map(static function (string $priority) {
    return [
        'value' => $priority,
        'label' => get_string('doubtpriority:' . $priority, 'theme_remui_kids'),
        'selected' => $priority === \theme_remui_kids\local\doubts\constants::PRIORITY_NORMAL,
    ];
}, \theme_remui_kids\local\doubts\constants::priorities());

$querybaseurl = new moodle_url('/theme/remui_kids/highschool_messages.php');
if ($studentqueryfiltercourse) {
    $querybaseurl->param('querycourse', $studentqueryfiltercourse);
}

foreach ($studentqueries as &$queryrow) {
    $detailurl = clone $querybaseurl;
    $detailurl->param('studentquery', $queryrow['id']);
    $queryrow['url'] = $detailurl->out(false);
    $queryrow['iscurrent'] = ((int) $queryrow['id'] === (int) $selectedstudentquery);
}
unset($queryrow);

$studentquerystrings = [
    'heading' => get_string('teacher_doubts', 'theme_remui_kids'),
    'subheading' => get_string('teacher_doubts_subheading', 'theme_remui_kids'),
    'listheading' => get_string('student_doubts_list_heading', 'theme_remui_kids'),
    'noqueries' => get_string('student_doubts_none', 'theme_remui_kids'),
    'create' => get_string('student_doubts_create', 'theme_remui_kids'),
    'subject' => get_string('student_doubts_subject', 'theme_remui_kids'),
    'course' => get_string('student_doubts_course', 'theme_remui_kids'),
    'priority' => get_string('student_doubts_priority', 'theme_remui_kids'),
    'details' => get_string('student_doubts_details', 'theme_remui_kids'),
    'submit' => get_string('student_doubts_submit', 'theme_remui_kids'),
    'replyheading' => get_string('student_doubts_reply_heading', 'theme_remui_kids'),
    'replyplaceholder' => get_string('student_doubts_reply_placeholder', 'theme_remui_kids'),
    'replysubmit' => get_string('student_doubts_reply_submit', 'theme_remui_kids'),
    'replyattachments' => get_string('student_doubts_reply_attachments', 'theme_remui_kids'),
    'attachments' => get_string('student_doubts_attachments', 'theme_remui_kids'),
    'attachmentshelp' => get_string('student_doubts_attachments_help', 'theme_remui_kids'),
    'nomessages' => get_string('student_doubts_no_messages', 'theme_remui_kids'),
];

// Get messages data - Enhanced to match the image design
$messages_data = array();

// Get recent messages with more details
try {
    // Get individual messages instead of conversations
    $messages = $DB->get_records_sql("
        SELECT m.*, 
               uf.firstname as from_firstname, 
               uf.lastname as from_lastname,
               uf.email as from_email,
               c.fullname as course_name,
               c.shortname as course_shortname
        FROM {messages} m
        LEFT JOIN {user} uf ON m.useridfrom = uf.id
        LEFT JOIN {course} c ON m.courseid = c.id
        WHERE m.useridto = ? AND m.timeuserfromdeleted = 0
        ORDER BY m.timecreated DESC
        LIMIT 50
    ", array($USER->id));

    foreach ($messages as $message) {
        $from_user = $DB->get_record('user', array('id' => $message->useridfrom));
        $is_read = $message->timeuserfromdeleted == 0 && $message->timecreated < time() - 3600; // Consider read if older than 1 hour

        // Determine message type and priority
        $message_type = 'message';
        $priority = 'medium';

        if (strpos(strtolower($message->subject), 'announcement') !== false) {
            $message_type = 'announcement';
        } elseif (strpos(strtolower($message->subject), 'urgent') !== false || strpos(strtolower($message->subject), 'important') !== false) {
            $priority = 'high';
        } elseif (strpos(strtolower($message->subject), 'reminder') !== false) {
            $priority = 'low';
        }

        // Get user role
        $user_role = 'Student';
        if ($from_user) {
            $user_roles = get_user_roles(context_system::instance(), $from_user->id);
            foreach ($user_roles as $role) {
                if ($role->shortname === 'editingteacher' || $role->shortname === 'teacher') {
                    $user_role = 'Instructor';
                    break;
                } elseif ($role->shortname === 'manager') {
                    $user_role = 'Administrator';
                    break;
                }
            }
        }

        $message_data = array(
            'id' => $message->id,
            'subject' => $message->subject ?: 'No Subject',
            'content' => $message->fullmessage,
            'content_preview' => substr(strip_tags($message->fullmessage), 0, 150) . '...',
            'from_name' => $from_user ? fullname($from_user) : 'Unknown User',
            'from_role' => $user_role,
            'course_name' => $message->course_name ?: 'General',
            'course_shortname' => $message->course_shortname ?: 'GEN',
            'date_created' => $message->timecreated,
            'date_formatted' => date('n/j/Y', $message->timecreated),
            'is_read' => $is_read,
            'message_type' => $message_type,
            'priority' => $priority,
            'conversation_url' => new moodle_url('/message/index.php', array('id' => $message->useridfrom))
        );

        $messages_data[] = $message_data;
    }
} catch (Exception $e) {
    // If there's an error, show empty messages
    error_log("Messages fetch error: " . $e->getMessage());
}

// Calculate statistics
$total_messages = count($messages_data);
$unread_messages = 0;
$high_priority = 0;
$today_messages = 0;
$today_start = strtotime('today');

foreach ($messages_data as $message) {
    if (!$message['is_read']) {
        $unread_messages++;
    }
    if ($message['priority'] === 'high') {
        $high_priority++;
    }
    if ($message['date_created'] >= $today_start) {
        $today_messages++;
    }
}

$sidebar_context = remui_kids_build_highschool_sidebar_context('messages', $USER);

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'messages' => $messages_data,
    'total_messages' => $total_messages,
    'unread_messages' => $unread_messages,
    'high_priority' => $high_priority,
    'today_messages' => $today_messages,
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'courses_url' => $sidebar_context['mycoursesurl'],
    'profile_url' => $sidebar_context['profileurl'],
    'messages_url' => (new moodle_url('/message/index.php'))->out(),
    'logout_url' => $sidebar_context['logouturl'],
    'is_highschool' => true,
    'student_queries' => $studentqueries,
    'student_query_detail' => $studentquerydetail,
    'student_query_courseoptions' => $courseoptions,
    'student_query_priorityoptions' => $priorityoptions,
    'student_query_strings' => $studentquerystrings,
    'student_query_cancreate' => !empty($enrolledcourses),
    'student_query_default_course' => $querydefaultcourseid,
    'student_query_filter_course' => $studentqueryfiltercourse,
    'student_query_selected_id' => $selectedstudentquery,
    'student_query_action_url' => $PAGE->url->out(false),
    'student_query_hasdetail' => !empty($studentquerydetail),
));

// Output page header with Moodle navigation
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $template_data);

// Add custom CSS for the messages page
?>
<style>
    /* Enhanced Sidebar Styles */
    .student-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 320px;
        height: 100vh;
        background: #ffffff;
        color: #1f2937;
        overflow-y: auto;
        z-index: 1000;
        padding: 2rem 0;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    }

    .student-sidebar.enhanced-sidebar {
        padding: 1.5rem 0;
    }

    .sidebar-nav {
        padding: 0 1rem;
    }

    .nav-section {
        margin-bottom: 2rem;
    }

    .section-title {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 1px;
        color: rgba(15, 23, 42, 0.6);
        margin-bottom: 0.75rem;
        padding: 0 0.75rem;
    }

    .nav-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .nav-item {
        margin-bottom: 0.25rem;
    }

    .nav-link {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        color: rgba(30, 41, 59, 0.9);
        text-decoration: none;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .nav-link:hover {
        background: rgba(59, 130, 246, 0.12);
        color: #1d4ed8;
        transform: translateX(5px);
    }

    .nav-link.active {
        background: rgba(59, 130, 246, 0.18);
        color: #1d4ed8;
        font-weight: 600;
    }

    .nav-link i {
        width: 24px;
        margin-right: 0.75rem;
        font-size: 1.1rem;
    }

    .quick-actions {
        padding: 0 0.75rem;
    }

    .quick-action-buttons {
        display: grid;
        gap: 0.75rem;
    }

    .quick-action-btn {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        background: rgba(148, 163, 184, 0.08);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .quick-action-btn:hover {
        background: rgba(59, 130, 246, 0.12);
        transform: translateY(-2px);
    }

    .action-icon {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        margin-right: 0.75rem;
    }

    .quick-action-btn.purple .action-icon {
        background: rgba(147, 51, 234, 0.3);
    }

    .quick-action-btn.blue .action-icon {
        background: rgba(59, 130, 246, 0.3);
    }

    .quick-action-btn.green .action-icon {
        background: rgba(34, 197, 94, 0.3);
    }

    .quick-action-btn.orange .action-icon {
        background: rgba(249, 115, 22, 0.3);
    }

    .action-content {
        flex: 1;
    }

    .action-title {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .action-desc {
        font-size: 0.75rem;
        color: rgba(30, 41, 59, 0.65);
    }

    .sidebar-footer {
        padding: 1rem;
        margin-top: 2rem;
        border-top: 1px solid rgba(148, 163, 184, 0.2);
    }

    .user-info {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        background: rgba(148, 163, 184, 0.08);
        border-radius: 8px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: rgba(59, 130, 246, 0.12);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.75rem;
    }

    .user-details {
        flex: 1;
    }

    .user-name {
        font-weight: 600;
        font-size: 0.9rem;
    }

    .user-role {
        font-size: 0.75rem;
        color: rgba(100, 116, 139, 0.9);
    }

    /* Hide Moodle default page heading to avoid duplicate titles */
    .highschool-messages-page .page-header,
    .highschool-messages-page #page-header,
    .highschool-messages-page .page-context-header {
        display: none !important;
    }

    /* Custom styles for High School Messages Page */
    .highschool-messages-page {
        position: relative;
        min-height: 100vh;
        margin-left: 320px;
        margin-right: 300px;
        padding: 0;
        width: calc(100% - 340px);
    }

    .messages-main-content {
        padding: 0;
        width: 100%;
    }

    /* Container fluid padding */
    .container-fluid {
        padding-left: 2rem;
        padding-right: 2rem;
    }

    /* Remove all padding from main content */
    .messages-main-content {
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

    /* Full width navbar and page adjustments */
    body.has-student-sidebar #page,
    body.has-enhanced-sidebar #page {
        margin-left: 0;
        width: 100%;
    }

    body.has-student-sidebar #page-wrapper,
    body.has-enhanced-sidebar #page-wrapper {
        margin-left: 0;
        width: 100%;
    }

    /* Make navbar span full width and sticky */
    body.has-student-sidebar .navbar,
    body.has-enhanced-sidebar .navbar,
    body.has-student-sidebar .navbar-expand,
    body.has-enhanced-sidebar .navbar-expand {
        width: 100% !important;
        margin-left: 0 !important;
        left: 0 !important;
        right: 0 !important;
        position: fixed !important;
        top: 0 !important;
        z-index: 1030 !important;
        background: white !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1) !important;
    }


    /* Adjust main content area to account for sidebar */
    body.has-student-sidebar .main-content,
    body.has-enhanced-sidebar .main-content,
    body.has-student-sidebar .content,
    body.has-enhanced-sidebar .content {
        margin-left: 320px;
    }

    /* Ensure page header spans full width */
    body.has-student-sidebar .page-header,
    body.has-enhanced-sidebar .page-header {
        width: 100%;
        margin-left: 0;
    }

    .messages-page-header {
        background: #ffffff;
        color: #1e293b;
        padding: 2rem;
        margin-bottom: 1.5rem;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 2px solid #e0f2fe;
    }

    .messages-page-header .page-title {
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

    .stat-icon.unread {
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    }

    .stat-icon.priority {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }

    .stat-icon.today {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #1a202c;
    }

    .search-filters {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 20px;
        padding: 1.75rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 20px rgba(125, 211, 252, 0.12);
        border: 2px solid #e0f2fe;
        transition: all 0.3s ease;
    }

    .search-filters:hover {
        box-shadow: 0 8px 30px rgba(125, 211, 252, 0.2);
        border-color: #bae6fd;
    }

    .search-filters h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 1rem;
    }

    .search-bar {
        display: flex;
        gap: 1rem;
        align-items: center;
    }

    .search-input {
        flex: 1;
        position: relative;
    }

    .search-input input {
        width: 100%;
        padding: 0.875rem 1.25rem 0.875rem 2.75rem;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 0.95rem;
        background: #f8fafc;
        transition: all 0.3s ease;
    }

    .search-input input:focus {
        outline: none;
        border-color: #38bdf8;
        background: white;
        box-shadow: 0 0 0 4px rgba(125, 211, 252, 0.1);
    }

    .search-input i {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #718096;
    }

    .filter-dropdowns {
        display: flex;
        gap: 0.75rem;
    }

    .filter-dropdown {
        padding: 0.75rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: white;
        font-size: 0.9rem;
        color: #4a5568;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .messages-list {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(125, 211, 252, 0.12);
        overflow: hidden;
        border: 2px solid #e0f2fe;
    }

    .teacher-queries-section {
        margin-top: 3rem;
    }

    .teacher-queries-header {
        margin-bottom: 1.5rem;
    }

    .teacher-queries-header h2 {
        margin: 0;
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
    }

    .teacher-queries-header p {
        margin: 0.35rem 0 0;
        color: #475569;
    }

    .query-list-card,
    .query-detail-card,
    .query-create-card {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 20px;
        padding: 1.5rem;
        border: 2px solid #e0f2fe;
        box-shadow: 0 4px 20px rgba(125, 211, 252, 0.12);
    }

    .query-list-card {
        height: 100%;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .query-list-filters select {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        background: #f8fafc;
        font-size: 0.95rem;
        color: #334155;
    }

    .query-list {
        max-height: 480px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .query-list-empty {
        text-align: center;
        padding: 2rem 1rem;
        color: #64748b;
    }

    .query-list-item {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: block;
        background: #ffffff;
    }

    .query-list-item:hover {
        border-color: #7dd3fc;
        box-shadow: 0 8px 25px rgba(125, 211, 252, 0.2);
        transform: translateX(6px);
    }

    .query-list-item.active {
        border-color: #38bdf8;
        background: #ecfeff;
    }

    .query-list-title {
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 0.35rem;
    }

    .query-list-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.8rem;
        color: #475569;
    }

    .query-status-badge,
    .query-priority-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.75rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .query-status-badge {
        background: #eef2ff;
        color: #312e81;
        border-color: #c7d2fe;
    }

    .query-priority-high {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }

    .query-priority-urgent {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .query-priority-normal {
        background: #dcfce7;
        color: #166534;
        border-color: #bbf7d0;
    }

    .query-priority-low {
        background: #e0f2fe;
        color: #0c4a6e;
        border-color: #bae6fd;
    }

    .query-detail-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 1rem;
        border-bottom: 1px solid #eef2ff;
        padding-bottom: 1rem;
        margin-bottom: 1.25rem;
    }

    .query-detail-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .query-detail-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .query-detail-stats {
        display: flex;
        gap: 1.5rem;
        color: #475569;
        font-size: 0.9rem;
    }

    .query-chat-thread {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        max-height: 380px;
        overflow-y: auto;
        margin-bottom: 1.5rem;
    }

    .query-chat-bubble {
        border-radius: 16px;
        padding: 1rem 1.25rem;
        max-width: 90%;
        position: relative;
        background: #f8fafc;
    }

    .query-chat-bubble.from-student {
        background: #ecfeff;
        border: 1px solid #bae6fd;
        margin-left: auto;
    }

    .query-chat-bubble.from-teacher {
        background: #fdf2f8;
        border: 1px solid #fbcfe8;
        margin-right: auto;
    }

    .query-chat-meta {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        color: #475569;
        margin-bottom: 0.35rem;
        font-weight: 600;
    }

    .query-chat-body {
        color: #0f172a;
    }

    .query-chat-attachments {
        margin-top: 0.75rem;
        font-size: 0.85rem;
    }

    .query-chat-attachments a {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: #e0f2fe;
        padding: 0.3rem 0.75rem;
        border-radius: 999px;
        text-decoration: none;
        color: #0c4a6e;
        font-weight: 600;
    }

    .query-reply-form textarea {
        width: 100%;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 1rem;
        min-height: 120px;
        resize: vertical;
        margin-bottom: 0.75rem;
        background: #f8fafc;
    }

    .query-reply-form input[type="file"] {
        width: 100%;
        margin-bottom: 1rem;
    }

    .query-create-card h4 {
        margin-bottom: 1rem;
        font-size: 1.2rem;
        font-weight: 700;
    }

    .query-create-card form input,
    .query-create-card form select,
    .query-create-card form textarea {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin-bottom: 0.75rem;
        background: #f8fafc;
    }

    .query-create-card form textarea {
        min-height: 140px;
        resize: vertical;
    }

    .query-create-card form input[type="file"] {
        padding: 0.5rem;
        background: transparent;
    }

    .message-item {
        padding: 1.75rem;
        border-bottom: 1px solid #e2e8f0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
    }

    .message-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: linear-gradient(180deg, #7dd3fc 0%, #38bdf8 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .message-item:hover {
        background: linear-gradient(90deg, rgba(125, 211, 252, 0.08) 0%, rgba(248, 250, 252, 1) 15%);
        transform: translateX(4px);
    }

    .message-item:hover::before {
        opacity: 1;
    }

    .message-item:last-child {
        border-bottom: none;
    }

    .message-item.unread {
        border-left: 4px solid #e53e3e;
    }

    .message-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .message-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 0.5rem;
    }

    .message-tags {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .message-tag {
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .tag-announcement {
        background: #e6f3ff;
        color: #0066cc;
    }

    .tag-unread {
        background: #e53e3e;
        color: white;
    }

    .tag-read {
        background: #c6f6d5;
        color: #22543d;
    }

    .tag-high-priority {
        background: #fed7d7;
        color: #c53030;
    }

    .tag-medium-priority {
        background: #fef5e7;
        color: #c05621;
    }

    .tag-low-priority {
        background: #e6fffa;
        color: #234e52;
    }

    .message-content {
        color: #4a5568;
        font-size: 0.9rem;
        line-height: 1.5;
        margin-bottom: 1rem;
    }

    .message-details {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
    }

    .detail-label {
        font-size: 0.75rem;
        color: #718096;
        margin-bottom: 0.25rem;
    }

    .detail-value {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1a202c;
    }

    .message-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        position: absolute;
        right: 1.5rem;
        top: 1.5rem;
    }

    .action-btn {
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: white;
        color: #4a5568;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
    }

    .action-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e0;
    }

    .no-messages {
        text-align: center;
        padding: 4rem 2rem;
        color: #718096;
    }

    .no-messages i {
        font-size: 4rem;
        color: #cbd5e0;
        margin-bottom: 1rem;
    }

    .no-messages h3 {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 0.5rem;
    }

    .no-messages p {
        font-size: 1rem;
        color: #718096;
    }

    /* Enhanced Message Features */
    .message-priority-indicator {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10b981;
    }

    .message-priority-indicator.high {
        background: #ef4444;
        animation: pulse 2s infinite;
    }

    .message-priority-indicator.medium {
        background: #fbbf24;
    }

    .message-priority-indicator.low {
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

    .message-avatar {
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

    .message-meta {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }

    .message-sender {
        font-weight: 600;
        color: #1e293b;
    }

    .message-time {
        font-size: 0.875rem;
        color: #64748b;
    }

    .message-course-badge {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        color: #0284c7;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid #7dd3fc;
    }

    .message-actions {
        display: flex;
        gap: 0.5rem;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .message-item:hover .message-actions {
        opacity: 1;
    }

    .action-btn {
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: white;
        color: #64748b;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
    }

    .action-btn:hover {
        background: #f8fafc;
        border-color: #7dd3fc;
        color: #0284c7;
        transform: translateY(-2px);
    }

    .message-content-preview {
        color: #64748b;
        font-size: 0.9rem;
        line-height: 1.5;
        margin: 0.75rem 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .message-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .status-unread {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    .status-read {
        background: #f0fdf4;
        color: #16a34a;
        border: 1px solid #bbf7d0;
    }

    .status-urgent {
        background: #fef3c7;
        color: #d97706;
        border: 1px solid #fed7aa;
        animation: urgentPulse 1.5s infinite;
    }

    @keyframes urgentPulse {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }
    }

    .filter-dropdown {
        position: relative;
        cursor: pointer;
    }

    .filter-dropdown:hover {
        border-color: #7dd3fc;
        background: rgba(125, 211, 252, 0.05);
    }

    .filter-dropdown.active {
        border-color: #38bdf8;
        background: rgba(125, 211, 252, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, #7dd3fc 0%, #38bdf8 100%);
        border: none;
        color: #1e293b;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(125, 211, 252, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(125, 211, 252, 0.4);
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
    }

    .btn-outline-light:hover {
        background: #f8fafc;
        border-color: #7dd3fc;
        color: #0284c7;
        transform: translateY(-2px);
    }
    .footer-copyright-wrapper ,.footer-mainsection-wrapper{
        display: none !important;
     }
    @media (max-width: 768px) {
        .student-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .student-sidebar.show {
            transform: translateX(0);
        }

        .highschool-messages-page {
            margin-left: 0 !important;
            padding: 0 !important;
        }

        .messages-page-header {
            margin-left: 0 !important;
            width: 100% !important;
        }

        body.has-student-sidebar #page,
        body.has-enhanced-sidebar #page,
        body.has-student-sidebar #page-wrapper,
        body.has-enhanced-sidebar #page-wrapper {
            margin-left: 0 !important;
        }

        .messages-page-header .page-title {
            font-size: 1.8rem;
        }

        .messages-main-content {
            padding: 0;
        }

        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }
</style>

<div class="highschool-messages-page">
    <!-- Page Header -->
    <div class="messages-page-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-12">
                    <h1 class="page-title">My Doubts</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Message Statistics -->
    <div class="container-fluid">
        <div class="teacher-queries-section" id="teacher-queries">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="query-list-card">
                        <div class="query-list-filters">
                            <form method="get" action="<?php echo $template_data['student_query_action_url']; ?>">
                                <input type="hidden" name="studentquery"
                                    value="<?php echo (int) $template_data['student_query_selected_id']; ?>">
                                <select name="querycourse" onchange="this.form.submit()">
                                    <?php foreach ($template_data['student_query_courseoptions'] as $option): ?>
                                        <option value="<?php echo (int) $option['id']; ?>" <?php echo !empty($option['selected']) ? 'selected' : ''; ?>>
                                            <?php echo format_string($option['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="query-list">
                            <?php if (!empty($template_data['student_queries'])): ?>
                                <?php foreach ($template_data['student_queries'] as $query): ?>
                                    <a href="<?php echo $query['url']; ?>"
                                        class="query-list-item <?php echo !empty($query['iscurrent']) ? 'active' : ''; ?>">
                                        <div class="query-list-title"><?php echo format_string($query['subject']); ?></div>
                                        <div class="query-list-meta">
                                            <span><i
                                                    class="fa fa-book me-1"></i><?php echo format_string($query['course']); ?></span>
                                            <span><?php echo format_string($query['timemodifiedhuman']); ?></span>
                                        </div>
                                        <div class="query-list-meta mt-2">
                                            <span class="query-status-badge">
                                                <i class="fa fa-circle"></i><?php echo format_string($query['statuslabel']); ?>
                                            </span>
                                            <span
                                                class="query-status-badge query-priority-<?php echo htmlspecialchars($query['priority']); ?>">
                                                <i class="fa fa-flag"></i><?php echo format_string($query['prioritylabel']); ?>
                                            </span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="query-list-empty">
                                    <i class="fa fa-comments mb-2"></i>
                                    <p><?php echo s($template_data['student_query_strings']['noqueries']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($template_data['student_query_cancreate']): ?>
                            <div class="query-create-card">
                                <h4><i
                                        class="fa fa-plus-circle me-2"></i><?php echo s($template_data['student_query_strings']['create']); ?>
                                </h4>
                                <form method="post" enctype="multipart/form-data"
                                    action="<?php echo $template_data['student_query_action_url']; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="queryaction" value="create">
                                    <input type="hidden" name="querycourse"
                                        value="<?php echo (int) $template_data['student_query_filter_course']; ?>">
                                    <input type="text" name="querysubject"
                                        placeholder="<?php echo s($template_data['student_query_strings']['subject']); ?>"
                                        required>
                                    <select name="querycourseid" required>
                                        <?php foreach ($template_data['student_query_courseoptions'] as $option): ?>
                                            <?php if ((int) $option['id'] === 0) {
                                                continue;
                                            } ?>
                                            <option value="<?php echo (int) $option['id']; ?>" <?php echo ((int) $option['id'] === (int) $template_data['student_query_default_course']) ? 'selected' : ''; ?>>
                                                <?php echo format_string($option['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="querypriority">
                                        <?php foreach ($template_data['student_query_priorityoptions'] as $priority): ?>
                                            <option value="<?php echo $priority['value']; ?>" <?php echo !empty($priority['selected']) ? 'selected' : ''; ?>>
                                                <?php echo s($priority['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="querydetails"
                                        placeholder="<?php echo s($template_data['student_query_strings']['details']); ?>"
                                        required></textarea>
                                    <label class="form-label w-100">
                                        <span
                                            class="d-block mb-1"><?php echo s($template_data['student_query_strings']['attachments']); ?></span>
                                        <input type="file" name="queryattachments[]" multiple>
                                        <small
                                            class="text-muted"><?php echo s($template_data['student_query_strings']['attachmentshelp']); ?></small>
                                    </label>
                                    <button type="submit" class="btn btn-primary w-100 mt-2">
                                        <i
                                            class="fa fa-paper-plane me-2"></i><?php echo s($template_data['student_query_strings']['submit']); ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="query-detail-card">
                        <?php if ($template_data['student_query_hasdetail']): ?>
                            <?php $detail = $template_data['student_query_detail']; ?>
                            <div class="query-detail-header">
                                <div>
                                    <h3 class="query-detail-title"><?php echo format_string($detail['doubt']['subject']); ?>
                                    </h3>
                                    <div class="query-detail-stats">
                                        <span><i
                                                class="fa fa-graduation-cap me-1"></i><?php echo format_string($detail['doubt']['course']); ?></span>
                                        <span><i
                                                class="fa fa-clock me-1"></i><?php echo format_string($detail['doubt']['timemodifiedhuman']); ?></span>
                                    </div>
                                </div>
                                <div class="query-detail-meta">
                                    <span class="query-status-badge"><i
                                            class="fa fa-circle"></i><?php echo format_string($detail['doubt']['statuslabel']); ?></span>
                                    <span
                                        class="query-status-badge query-priority-<?php echo htmlspecialchars($detail['doubt']['priority']); ?>">
                                        <i
                                            class="fa fa-flag"></i><?php echo format_string($detail['doubt']['prioritylabel']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="query-chat-thread">
                                <?php if (!empty($detail['messages'])): ?>
                                    <?php foreach ($detail['messages'] as $message): ?>
                                        <div
                                            class="query-chat-bubble <?php echo !empty($message['isstudent']) ? 'from-student' : 'from-teacher'; ?>">
                                            <div class="query-chat-meta">
                                                <span><?php echo format_string($message['fullname']); ?></span>
                                                <span><?php echo format_string($message['timehuman']); ?></span>
                                            </div>
                                            <div class="query-chat-body"><?php echo $message['message']; ?></div>
                                            <?php if (!empty($message['hasattachments'])): ?>
                                                <div class="query-chat-attachments">
                                                    <?php foreach ($message['attachments'] as $attachment): ?>
                                                        <a href="<?php echo $attachment['url']; ?>" target="_blank">
                                                            <i
                                                                class="fa fa-paperclip"></i><?php echo format_string($attachment['filename']); ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="query-list-empty">
                                        <p><?php echo s($template_data['student_query_strings']['nomessages']); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="query-reply-form">
                                <h4><?php echo s($template_data['student_query_strings']['replyheading']); ?>
                                </h4>
                                <form method="post" enctype="multipart/form-data"
                                    action="<?php echo $template_data['student_query_action_url']; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="queryaction" value="reply">
                                    <input type="hidden" name="doubtid" value="<?php echo (int) $detail['doubt']['id']; ?>">
                                    <input type="hidden" name="querycourse"
                                        value="<?php echo (int) $template_data['student_query_filter_course']; ?>">
                                    <textarea name="message"
                                        placeholder="<?php echo s($template_data['student_query_strings']['replyplaceholder']); ?>"
                                        required></textarea>
                                    <label class="form-label w-100">
                                        <span
                                            class="d-block mb-1"><?php echo s($template_data['student_query_strings']['replyattachments']); ?></span>
                                        <input type="file" name="queryreplyattachments[]" multiple>
                                    </label>
                                    <button type="submit" class="btn btn-primary">
                                        <i
                                            class="fa fa-paper-plane me-2"></i><?php echo s($template_data['student_query_strings']['replysubmit']); ?>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="query-list-empty">
                                <i class="fa fa-graduation-cap mb-2"></i>
                                <p><?php echo s($template_data['student_query_strings']['noqueries']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize enhanced sidebar
    document.addEventListener('DOMContentLoaded', function () {
        const enhancedSidebar = document.querySelector('.enhanced-sidebar');
        if (enhancedSidebar) {
            document.body.classList.add('has-student-sidebar', 'has-enhanced-sidebar');
            console.log('Enhanced sidebar initialized for high school messages page');
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

        // Message search functionality
        const searchInput = document.getElementById('messageSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();
                const messageItems = document.querySelectorAll('.message-item');

                messageItems.forEach(item => {
                    const title = item.querySelector('.message-title').textContent.toLowerCase();
                    const content = item.querySelector('.message-content').textContent.toLowerCase();
                    const fromName = item.querySelector('.detail-value').textContent.toLowerCase();

                    if (title.includes(searchTerm) || content.includes(searchTerm) || fromName.includes(searchTerm)) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Enhanced message interactions
        const messageItems = document.querySelectorAll('.message-item');
        messageItems.forEach(item => {
            // Add hover effects
            item.addEventListener('mouseenter', function () {
                this.style.transform = 'translateX(8px)';
            });

            item.addEventListener('mouseleave', function () {
                this.style.transform = 'translateX(0)';
            });

            // Add click animation
            item.addEventListener('click', function () {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = 'translateX(8px)';
                }, 150);
            });
        });

        // Filter dropdown functionality
        const filterDropdowns = document.querySelectorAll('.filter-dropdown');
        filterDropdowns.forEach(dropdown => {
            dropdown.addEventListener('click', function () {
                this.classList.toggle('active');
                // Add filter logic here
            });
        });

        // Add smooth scroll to messages
        const messagesList = document.querySelector('.messages-list');
        if (messagesList) {
            messagesList.style.scrollBehavior = 'smooth';
        }

        // Add loading animation to refresh button
        const refreshBtn = document.querySelector('button[onclick="refreshMessages()"]');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                const icon = this.querySelector('i');
                icon.classList.add('fa-spin');
                this.disabled = true;

                setTimeout(() => {
                    icon.classList.remove('fa-spin');
                    this.disabled = false;
                }, 1000);
            });
        }
    });

    // Refresh messages function
    function refreshMessages() {
        const refreshBtn = document.querySelector('button[onclick="refreshMessages()"]');
        const icon = refreshBtn.querySelector('i');

        // Add spinning animation
        icon.classList.add('fa-spin');
        refreshBtn.disabled = true;

        // Simulate refresh (in real implementation, this would reload the page or fetch new data)
        setTimeout(() => {
            icon.classList.remove('fa-spin');
            refreshBtn.disabled = false;
            // Reload the page to get fresh data
            window.location.reload();
        }, 1000);
    }

    // Enhanced message action functions
    function viewMessage(messageId) {
        // Show message in modal or new tab
        console.log('Viewing message:', messageId);
        // In real implementation, this would open a modal or navigate to message detail
        alert('Opening message ' + messageId + ' in new window...');
    }

    function replyToMessage(messageId) {
        // Open reply dialog
        console.log('Replying to message:', messageId);
        // In real implementation, this would open a reply form
        alert('Opening reply dialog for message ' + messageId + '...');
    }

    function archiveMessage(messageId) {
        // Archive message with confirmation
        if (confirm('Are you sure you want to archive this message?')) {
            console.log('Archiving message:', messageId);
            // In real implementation, this would make an API call to archive the message
            const messageItem = document.querySelector(`[onclick*="${messageId}"]`);
            if (messageItem) {
                messageItem.style.opacity = '0.5';
                messageItem.style.transform = 'translateX(-100%)';
                setTimeout(() => {
                    messageItem.remove();
                }, 300);
            }
        }
    }

    // Add keyboard shortcuts
    document.addEventListener('keydown', function (e) {
        // Ctrl/Cmd + R to refresh messages
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            refreshMessages();
        }

        // Escape to clear search
        if (e.key === 'Escape') {
            const searchInput = document.getElementById('messageSearch');
            if (searchInput) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            }
        }
    });

    // Add message count animation
    function animateMessageCount() {
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

    // Initialize animations on page load
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(animateMessageCount, 500);
    });
</script>
<?php
echo $OUTPUT->footer();
?>