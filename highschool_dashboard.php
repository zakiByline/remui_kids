<?php
/**
 * High School Dashboard (Grades 8-12)
 * Separate dashboard entry point for high school students
 * Redirects students in Grades 8-12 to this dedicated dashboard
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once(__DIR__ . '/lib.php');
require_login();

// Set page context
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_dashboard.php');
$PAGE->set_title('High School Dashboard');
$PAGE->set_heading('High School Dashboard');
$PAGE->set_pagelayout('mydashboard');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');

// Get user's cohort information
$usercohorts = cohort_get_user_cohorts($USER->id);
$usercohortname = '';
$usercohortid = 0;
$is_highschool = false;

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;

    // Check if user is in Grade 8-12 (High School)
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $is_highschool = true;
    }
}

// Check user profile custom field for grade as fallback
if (!$is_highschool) {
    $user_profile_fields = profile_user_record($USER->id);
    if (isset($user_profile_fields->grade)) {
        $user_grade = $user_profile_fields->grade;
        if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $user_grade)) {
            $is_highschool = true;
        }
    }
}

// Redirect non-high school students to their appropriate dashboard
if (!$is_highschool) {
    // Check if elementary or middle school
    if (!empty($usercohortname)) {
        if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
            // Elementary student - redirect to main dashboard
            redirect(new moodle_url('/my/'));
        } elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
            // Middle school student - redirect to main dashboard
            redirect(new moodle_url('/my/'));
        }
    }
    // Default redirect for users not in high school cohorts
    redirect(new moodle_url('/my/'));
}

// Build template context for high school dashboard
$templatecontext = [];
$templatecontext['custom_dashboard'] = true;
$templatecontext['dashboard_type'] = 'highschool';
$templatecontext['user_cohort_name'] = $usercohortname;
$templatecontext['user_cohort_id'] = $usercohortid;
$templatecontext['student_name'] = $USER->firstname;
$templatecontext['hello_message'] = "Hello " . $USER->firstname . "!";

// Set URLs for high school dashboard
$templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/highschool_courses.php'))->out();
$templatecontext['assignmentsurl'] = (new moodle_url('/theme/remui_kids/highschool_assignments.php'))->out();
$templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/highschool_profile.php'))->out();
$templatecontext['messagesurl'] = (new moodle_url('/theme/remui_kids/highschool_messages.php'))->out();
$templatecontext['gradesurl'] = (new moodle_url('/theme/remui_kids/highschool_grades.php'))->out();
$templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/highschool_calendar.php'))->out();
$templatecontext['reportsurl'] = (new moodle_url('/theme/remui_kids/highschool_myreports.php'))->out();
$templatecontext['treeviewurl'] = (new moodle_url('/theme/remui_kids/highschool_treeview.php'))->out();
$templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/lessons.php'))->out();
$templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
$templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/achievements.php'))->out();
$templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/competencies.php'))->out();
$templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
$templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
$templatecontext['ebooksurl'] = (new moodle_url('/theme/remui_kids/ebooks.php'))->out();
$templatecontext['askteacherurl'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out();
$templatecontext['certificatesurl'] = (new moodle_url('/local/certificate_approval/index.php'))->out();

// Add Study Partner context for high school dashboard (check config and capability)
$showstudypartnercta = get_config('local_studypartner', 'showstudentnav');
if ($showstudypartnercta === null) {
    $showstudypartnercta = true; // Default to visible
} else {
    $showstudypartnercta = (bool)$showstudypartnercta;
}
// Check if user has the capability to view Study Partner
$context = context_system::instance();
$hasstudypartnercapability = has_capability('local/studypartner:view', $context);
// Only show if both config is enabled AND user has capability
$templatecontext['showstudypartnercta'] = $showstudypartnercta && $hasstudypartnercapability;
$templatecontext['studypartnerurl'] = (new moodle_url('/local/studypartner/index.php'))->out();

$templatecontext['logouturl'] = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out();
$templatecontext['dashboardurl'] = (new moodle_url('/theme/remui_kids/highschool_dashboard.php'))->out();

// Sidebar access permissions (based on user's cohort)
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
$templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
$templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);

// AI Learning Assistant availability (visible for all logged-in users)
$learningassistantpluginpath = $CFG->dirroot . '/local/learningassistant/lib.php';
$learningassistantexists = file_exists($learningassistantpluginpath);
$canuseaiassistant = $learningassistantexists && !isguestuser() && isloggedin();

$templatecontext['show_aiassistant'] = $canuseaiassistant;
if ($canuseaiassistant) {
    $templatecontext['aiassistanturl'] = (new moodle_url('/local/learningassistant/learning_assistant.php'))->out();
} else {
    $templatecontext['aiassistanturl'] = '';
}
$templatecontext['currentpage'] = ['dashboard' => true];
$templatecontext['wwwroot'] = $CFG->wwwroot;
$templatecontext['config'] = ['wwwroot' => $CFG->wwwroot];

// Get high school dashboard data
if (function_exists('theme_remui_kids_get_highschool_dashboard_stats')) {
    $templatecontext['highschool_stats'] = theme_remui_kids_get_highschool_dashboard_stats($USER->id);
}

if (function_exists('theme_remui_kids_get_highschool_dashboard_metrics')) {
    $templatecontext['highschool_metrics'] = theme_remui_kids_get_highschool_dashboard_metrics($USER->id);
}

if (function_exists('theme_remui_kids_get_highschool_courses')) {
    $courses = theme_remui_kids_get_highschool_courses($USER->id);
    $templatecontext['highschool_courses'] = $courses;
    $templatecontext['has_highschool_courses'] = !empty($courses);
    $templatecontext['total_courses_count'] = count($courses);
    $templatecontext['show_view_all_button'] = count($courses) > 0;
}

// Get course overview with sections and subsections
if (function_exists('theme_remui_kids_get_highschool_course_overview')) {
    $course_overview = theme_remui_kids_get_highschool_course_overview($USER->id);
    $templatecontext['course_overview'] = $course_overview;
    $templatecontext['has_course_overview'] = !empty($course_overview);
    $templatecontext['course_overview_count'] = count($course_overview);
}

if (function_exists('theme_remui_kids_get_highschool_performance_trend')) {
    $trenddata = theme_remui_kids_get_highschool_performance_trend($USER->id);
    $templatecontext['highschool_performance_trend'] = $trenddata;
    $templatecontext['has_highschool_performance_trend'] = !empty($trenddata);
    $templatecontext['highschool_performance_trend_json'] = !empty($trenddata)
        ? json_encode($trenddata, JSON_UNESCAPED_UNICODE)
        : null;
}

// Get active sections
if (function_exists('theme_remui_kids_get_highschool_active_sections')) {
    $activesections = theme_remui_kids_get_highschool_active_sections($USER->id);
    $templatecontext['highschool_active_sections'] = array_slice($activesections, 0, 3);
    $templatecontext['has_highschool_active_sections'] = !empty($activesections);
    $templatecontext['highschool_active_sections_count'] = count($activesections);
}

// Get active lessons separately
if (function_exists('theme_remui_kids_get_highschool_active_lesson_sections')) {
    $active_lessons = theme_remui_kids_get_highschool_active_lesson_sections($USER->id, 6);
    $templatecontext['highschool_active_lessons'] = $active_lessons;
    $templatecontext['has_highschool_active_lessons'] = !empty($active_lessons);
    $templatecontext['highschool_active_lessons_count'] = count($active_lessons);
}

// Get active activities separately
if (function_exists('theme_remui_kids_get_highschool_active_activities')) {
    $active_activities = theme_remui_kids_get_highschool_active_activities($USER->id, 8);
    $templatecontext['highschool_active_activities'] = $active_activities;
    $templatecontext['has_highschool_active_activities'] = !empty($active_activities);
    $templatecontext['highschool_active_activities_count'] = count($active_activities);
}

// Get available courses for enrollment
if (function_exists('theme_remui_kids_get_highschool_available_courses')) {
    $available_courses = theme_remui_kids_get_highschool_available_courses($USER->id, 5);
    $templatecontext['highschool_available_courses'] = $available_courses;
    $templatecontext['has_highschool_available_courses'] = !empty($available_courses);
    $templatecontext['highschool_available_courses_count'] = count($available_courses);
}

// Get calendar and sidebar data
if (function_exists('theme_remui_kids_get_calendar_week_data')) {
    $templatecontext['calendar_week'] = theme_remui_kids_get_calendar_week_data($USER->id);
}

if (function_exists('theme_remui_kids_get_upcoming_events')) {
    $templatecontext['upcoming_events'] = theme_remui_kids_get_upcoming_events($USER->id);
}

if (function_exists('theme_remui_kids_get_learning_progress_stats')) {
    $templatecontext['learning_stats'] = theme_remui_kids_get_learning_progress_stats($USER->id);
}

if (function_exists('theme_remui_kids_get_achievements_data')) {
    $templatecontext['achievements'] = theme_remui_kids_get_achievements_data($USER->id);
}

// Get high school learning progress statistics
$thirty_days_ago = time() - (30 * 24 * 60 * 60);

// Get active courses (courses with recent access or with completed activities)
try {
    // Try to get courses with recent access from user_lastaccess table
    $active_courses = $DB->get_field_sql(
        "SELECT COUNT(DISTINCT ula.courseid)
         FROM {user_lastaccess} ula
         JOIN {enrol} e ON ula.courseid = e.courseid
         JOIN {user_enrolments} ue ON e.id = ue.enrolid
         WHERE ula.userid = ? 
         AND ue.userid = ?
         AND ula.timeaccess > ?
         AND ula.courseid > 1",
        [$USER->id, $USER->id, $thirty_days_ago]
    ) ?: 0;
    
    // Also count courses with recent completion activity (last 30 days)
    $courses_with_activity = $DB->get_field_sql(
        "SELECT COUNT(DISTINCT cm.course)
         FROM {course_modules_completion} cmc
         JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
         JOIN {enrol} e ON cm.course = e.courseid
         JOIN {user_enrolments} ue ON e.id = ue.enrolid
         WHERE cmc.userid = ?
         AND ue.userid = ?
         AND cmc.timemodified > ?
         AND cm.course > 1",
        [$USER->id, $USER->id, $thirty_days_ago]
    ) ?: 0;
    
    // Use the maximum of the two counts
    $active_courses = max($active_courses, $courses_with_activity);
    
} catch (Exception $e) {
    // Fallback: Just count enrolled courses with any activity
    try {
        $active_courses = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             JOIN {enrol} e ON c.id = e.courseid
             JOIN {user_enrolments} ue ON e.id = ue.enrolid
             WHERE ue.userid = ? 
             AND c.visible = 1
             AND c.id > 1",
            [$USER->id]
        ) ?: 0;
    } catch (Exception $e2) {
        $active_courses = 0;
    }
}

// Get completed activities count
try {
    $completed_activities = $DB->get_field_sql(
        "SELECT COUNT(*)
         FROM {course_modules_completion} cmc
         WHERE cmc.userid = ? 
         AND cmc.completionstate IN (1, 2)",
        [$USER->id]
    ) ?: 0;
} catch (Exception $e) {
    $completed_activities = 0;
}

// Calculate study time (estimate: 15 minutes per completed activity)
$study_time_minutes = $completed_activities * 15;
$study_time_hours = round($study_time_minutes / 60, 1);
$study_time_display = $study_time_hours >= 24 
    ? round($study_time_hours / 24, 1) . 'd' 
    : ($study_time_hours >= 1 
        ? round($study_time_hours, 1) . 'h' 
        : round($study_time_minutes) . 'm');

// Calculate overall progress percentage
try {
    $total_activities = $DB->get_field_sql(
        "SELECT COUNT(*)
         FROM {course_modules} cm
         JOIN {enrol} e ON cm.course = e.courseid
         JOIN {user_enrolments} ue ON e.id = ue.enrolid
         WHERE ue.userid = ? 
         AND cm.completion > 0",
        [$USER->id]
    ) ?: 1;
    
    $overall_progress = $total_activities > 0 
        ? round(($completed_activities / $total_activities) * 100) 
        : 0;
} catch (Exception $e) {
    $total_activities = 1;
    $overall_progress = 0;
}

$templatecontext['highschool_learning_progress'] = [
    'active_courses' => (int)$active_courses,
    'completed_activities' => (int)$completed_activities,
    'study_time' => $study_time_display,
    'study_time_hours' => $study_time_hours,
    'overall_progress' => $overall_progress
];

// Add body class for dashboard styling
$templatecontext['bodyattributes'] = 'class="custom-dashboard-page has-student-sidebar"';

// Render the high school dashboard template
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_dashboard', $templatecontext);