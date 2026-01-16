<?php
/**
 * Scratch Emulator Page
 * A global Scratch emulator that can be accessed from all dashboards
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');

// Require login
require_login();

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/scratch_emulator.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('sitename') . ' - Scratch Emulator');
$PAGE->set_heading('Scratch Emulator');

// Determine user's cohort and dashboard type
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'default';

$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;
    
    // Determine dashboard type based on cohort name
    if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    } elseif (preg_match('/grade\s*[4-6]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[7-9]/i', $usercohortname)) {
        $dashboardtype = 'high';
    }
}

// Prepare template context
$templatecontext = [
    'custom_scratch_emulator' => true,
    'dashboard_type' => $dashboardtype,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_lastname' => $USER->lastname,
    'student_fullname' => fullname($USER),
    
    // URLs for navigation based on dashboard type
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/mycourses.php'))->out() : 
        (new moodle_url('/my/courses.php'))->out(),
    'lessonsurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out() : 
        (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out() : 
        (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/calendar/view.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out(),
    
    // Sidebar flags
    'show_elementary_sidebar' => $dashboardtype === 'elementary',
    'show_middle_sidebar' => $dashboardtype === 'middle',
    'show_high_sidebar' => $dashboardtype === 'high',
    'hide_default_navbar' => true,
    
    // Active state for navigation
    'is_scratch_active' => true,
    
    // Body attributes for styling
    'bodyattributes' => $OUTPUT->body_attributes(['class' => 'scratch-emulator-page ' . $dashboardtype . '-dashboard']),
];

// Include the G4G7 Sidebar Component for Grade 4-7 students
if ($dashboardtype === 'middle') {
    include_once('components/g4g7_sidebar.php');
}

// Render the template
echo $OUTPUT->render_from_template('theme_remui_kids/scratch_emulator_page', $templatecontext);

