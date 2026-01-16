<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Custom My Courses page for remui_kids theme
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');


require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/mycourses.php');
$PAGE->set_pagelayout('base'); // Use base layout like dashboard
$PAGE->set_title('My Courses', false); // Remove site name from title
$PAGE->set_heading('My Courses');

// Get user's cohort information
$usercohorts = $DB->get_records_sql(
    "SELECT c.name, c.id 
     FROM {cohort} c 
     JOIN {cohort_members} cm ON c.id = cm.cohortid 
     WHERE cm.userid = ?",
    [$USER->id]
);

$usercohortname = '';
$usercohortid = 0;
$dashboardtype = 'default';

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Determine dashboard type based on cohort
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $dashboardtype = 'highschool';
    } elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    }
}

// Get student's courses based on dashboard type
$studentcourses = [];
if ($dashboardtype === 'elementary') {
    $studentcourses = theme_remui_kids_get_elementary_courses($USER->id);
} elseif ($dashboardtype === 'middle') {
    $studentcourses = theme_remui_kids_get_elementary_courses($USER->id); // Reuse same function
} elseif ($dashboardtype === 'highschool') {
    $studentcourses = theme_remui_kids_get_highschool_courses($USER->id);
} else {
    // Default: get all enrolled courses
    $courses = enrol_get_all_users_courses($USER->id, true);
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id);
        
        // Get course image
        $courseimage = '';
        $fs = get_file_storage();
        $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'timemodified DESC', false);
        
        if (!empty($files)) {
            $file = reset($files);
            $courseimage = moodle_url::make_pluginfile_url(
                $coursecontext->id,
                'course',
                'overviewfiles',
                null,
                '/',
                $file->get_filename()
            )->out();
        }
        
        // Get course category
        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $categoryname = $category ? $category->name : 'General';
        
        // Get progress
        $progress = theme_remui_kids_get_course_progress($USER->id, $course->id);
        
        $studentcourses[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => $course->summary,
            'courseimage' => $courseimage,
            'categoryname' => $categoryname,
            'progress_percentage' => $progress['percentage'],
            'completed_activities' => $progress['completed'],
            'total_activities' => $progress['total'],
            'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
            'completed' => $progress['percentage'] >= 100,
            'in_progress' => $progress['percentage'] > 0 && $progress['percentage'] < 100,
            'not_started' => $progress['percentage'] == 0,
            'grade_level' => $categoryname
        ];
    }
}

// Prepare template context for dashboard template
$templatecontext = [
    // Dashboard type flags
    'elementary' => ($dashboardtype === 'elementary'),
    'middle' => ($dashboardtype === 'middle'),
    'highschool' => ($dashboardtype === 'highschool'),
    'default' => ($dashboardtype === 'default'),
    
    // User information
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'dashboard_type' => $dashboardtype,
    
    // Course data
    'student_courses' => $studentcourses,
    'has_student_courses' => !empty($studentcourses),
    'total_courses_count' => count($studentcourses),
    
    // Page type flags
    'is_mycourses_page' => true,
    'is_dashboard_page' => false,
    
    // URLs for sidebar navigation (conditional based on dashboard type)
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => ($dashboardtype === 'elementary') ? 
        (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out() : 
        (new moodle_url('/theme/remui_kids/mycourses.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => ($dashboardtype === 'elementary') ? 
        (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out() : 
        (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => ($dashboardtype === 'elementary') ? 
        (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out() : 
        (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => ($dashboardtype === 'elementary') ? 
        (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out() : 
        (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => ($dashboardtype === 'elementary') ? 
        (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out() : 
        (new moodle_url('/calendar/view.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    
    // Quick action URLs
    'ebooksurl' => (new moodle_url('/mod/book/index.php'))->out(),
    'askteacherurl' => (new moodle_url('/message/index.php'))->out(),
    'shareclassurl' => (new moodle_url('/mod/forum/index.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
];



// Add body class for styling
$templatecontext['bodyattributes'] = 'class="has-student-sidebar elementary-dashboard mycourses-page"';

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/dashboard', $templatecontext);
echo $OUTPUT->footer();
