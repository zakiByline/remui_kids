<?php
/**
 * High School Calendar Page (Grade 9-12)
 * Displays academic calendar for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/highSchool_Calendar/calendar_logic.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_calendar.php');
$PAGE->set_title('Academic Calendar');
$PAGE->set_heading('Academic Calendar');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

// Check if user has access
if (!remui_kids_check_calendar_access($context, $USER->id) && !isloggedin()) {
    redirect(new moodle_url('/'));
}

// Get user grade information
$grade_info = remui_kids_get_user_grade_info($USER->id);
$user_grade = $grade_info['user_grade'];
$is_highschool = $grade_info['is_highschool'];

// More flexible verification - allow access if user has high school grade OR is in grades 9-12
$valid_grades = array('Grade 9', 'Grade 10', 'Grade 11', 'Grade 12', '9', '10', '11', '12');
$has_valid_grade = false;

foreach ($valid_grades as $grade) {
    if (stripos($user_grade, $grade) !== false) {
        $has_valid_grade = true;
        break;
    }
}

// Only redirect if NOT high school and NOT valid grade
if (!$is_highschool && !$has_valid_grade) {
    // For debugging: comment out redirect temporarily
    // redirect(new moodle_url('/my/'));
}

// Get calendar events data using logic function
$calendar_data = remui_kids_get_highschool_calendar_data($USER->id);

// Build sidebar context
$sidebar_context = remui_kids_build_highschool_sidebar_context('calendar', $USER);

// Check if user is a student
$is_student = false;
$user_roles = get_user_roles($context, $USER->id);
foreach ($user_roles as $role) {
    if ($role->shortname === 'student') {
        $is_student = true;
        break;
    }
}

// Prepare events for template (convert event_type to slug for CSS classes and ensure edit URLs exist)
// Also JSON encode strings for safe JavaScript usage
$events_for_template = array();
foreach ($calendar_data['events'] as $event) {
    $event['event_type_slug'] = str_replace(' ', '-', $event['event_type']);
    // Ensure edit_event_url exists
    if (!isset($event['edit_event_url'])) {
        $event['edit_event_url'] = (new moodle_url('/calendar/event.php', array('action' => 'edit', 'id' => $event['id'])))->out(false);
    }
    // Ensure activity_url exists
    if (!isset($event['activity_url'])) {
        $event['activity_url'] = '';
    }
    // Ensure has_activity exists
    if (!isset($event['has_activity'])) {
        $event['has_activity'] = !empty($event['activity_url']);
    }
    // Ensure boolean values are properly formatted for data attributes
    $event['can_edit'] = isset($event['can_edit']) && $event['can_edit'] ? '1' : '0';
    $event['is_created_by_staff'] = isset($event['is_created_by_staff']) && $event['is_created_by_staff'] ? '1' : '0';
    $event['has_activity'] = $event['has_activity'] ? '1' : '0';
    
    // JSON encode for safe JavaScript usage (kept for backward compatibility)
    $event['name_json'] = json_encode($event['name']);
    $event['description_json'] = json_encode($event['description']);
    $event['course_name_json'] = json_encode($event['course_name']);
    $event['date_formatted_json'] = json_encode($event['date_formatted']);
    $event['time_formatted_json'] = json_encode($event['time_formatted']);
    $event['event_type_json'] = json_encode($event['event_type']);
    $event['priority_json'] = json_encode($event['priority']);
    $event['status_json'] = json_encode($event['status']);
    $event['instructor_json'] = json_encode($event['instructor']);
    $event['event_url_json'] = json_encode($event['event_url']);
    $event['edit_event_url_json'] = json_encode($event['edit_event_url'] ?? '');
    $event['activity_url_json'] = json_encode($event['activity_url'] ?? '');
    $event['can_edit_json'] = json_encode($event['can_edit'] ?? false);
    $event['is_created_by_staff_json'] = json_encode($event['is_created_by_staff'] ?? false);
    $event['has_activity_json'] = json_encode($event['has_activity'] ?? false);
    $events_for_template[] = $event;
}

$upcoming_events_for_template = array();
foreach ($calendar_data['upcoming_events'] as $event) {
    $event['event_type_slug'] = str_replace(' ', '-', $event['event_type']);
    // Ensure edit_event_url exists
    if (!isset($event['edit_event_url'])) {
        $event['edit_event_url'] = (new moodle_url('/calendar/event.php', array('action' => 'edit', 'id' => $event['id'])))->out(false);
    }
    // Ensure activity_url exists
    if (!isset($event['activity_url'])) {
        $event['activity_url'] = '';
    }
    // Ensure has_activity exists
    if (!isset($event['has_activity'])) {
        $event['has_activity'] = !empty($event['activity_url']);
    }
    // Ensure boolean values are properly formatted for data attributes
    $event['can_edit'] = isset($event['can_edit']) && $event['can_edit'] ? '1' : '0';
    $event['is_created_by_staff'] = isset($event['is_created_by_staff']) && $event['is_created_by_staff'] ? '1' : '0';
    $event['has_activity'] = $event['has_activity'] ? '1' : '0';
    
    // JSON encode for safe JavaScript usage (kept for backward compatibility)
    $event['name_json'] = json_encode($event['name']);
    $event['description_json'] = json_encode($event['description']);
    $event['course_name_json'] = json_encode($event['course_name']);
    $event['date_formatted_json'] = json_encode($event['date_formatted']);
    $event['time_formatted_json'] = json_encode($event['time_formatted']);
    $event['event_type_json'] = json_encode($event['event_type']);
    $event['priority_json'] = json_encode($event['priority']);
    $event['status_json'] = json_encode($event['status']);
    $event['instructor_json'] = json_encode($event['instructor']);
    $event['event_url_json'] = json_encode($event['event_url']);
    $event['edit_event_url_json'] = json_encode($event['edit_event_url'] ?? '');
    $event['activity_url_json'] = json_encode($event['activity_url'] ?? '');
    $event['can_edit_json'] = json_encode($event['can_edit'] ?? false);
    $event['is_created_by_staff_json'] = json_encode($event['is_created_by_staff'] ?? false);
    $event['has_activity_json'] = json_encode($event['has_activity'] ?? false);
    $upcoming_events_for_template[] = $event;
}

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'events' => $events_for_template,
    'upcoming_events' => $upcoming_events_for_template,
    'total_events' => $calendar_data['total_events'],
    'today_events' => $calendar_data['today_events'],
    'overdue_events' => $calendar_data['overdue_events'],
    'upcoming_count' => $calendar_data['upcoming_count'],
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'courses_url' => $sidebar_context['mycoursesurl'],
    'profile_url' => $sidebar_context['profileurl'],
    'messages_url' => $sidebar_context['messagesurl'],
    'logout_url' => $sidebar_context['logouturl'],
    'add_event_url' => (new moodle_url('/calendar/event.php', array('action' => 'new')))->out(),
    'is_highschool' => true,
    'is_student' => $is_student,
    'config' => [
        'wwwroot' => $CFG->wwwroot,
    ]
));

// Output page header with Moodle navigation
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_calendar', $template_data);
echo $OUTPUT->footer();