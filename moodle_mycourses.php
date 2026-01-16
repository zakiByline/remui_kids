<?php
/**
 * Moodle-integrated My Courses page for remui_kids theme
 * This page is properly integrated within Moodle and will inherit favicon and all settings
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/moodle_mycourses.php');
$PAGE->set_pagelayout('base'); // Use Moodle's base layout to inherit favicon
$PAGE->set_title('My Courses', false); // Set to false to prevent site name concatenation


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
    $studentcourses = theme_remui_kids_get_elementary_courses($USER->id);
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

// Get tree view data for Grade 4-7 students
$treeviewdata = [];
$totallessons = 0;
$totalactivities = 0;

if ($dashboardtype === 'middle' || $dashboardtype === 'highschool') {
    $courses = enrol_get_all_users_courses($USER->id, true);
    
    foreach ($courses as $course) {
        // Get course completion info
        $completion = new completion_info($course);
        $coursecompletion = $completion->get_completion($USER->id, COMPLETION_CRITERIA_TYPE_COURSE);
        $courseprogress = $coursecompletion ? $coursecompletion->completionstate : 0;
        
        // Get all course modules
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        
        // Get lessons (treating sections as lessons)
        $lessons = [];
        $lessonnumber = 1;
        
        foreach ($sections as $sectionnum => $section) {
            if ($sectionnum == 0) continue; // Skip section 0
            
            $sectionname = $section->name ?: "Lesson " . $lessonnumber;
            $sectionactivities = [];
            $activitycount = 0;
            $completedactivities = 0;
            
            // Get activities in this section
            $cms = $modinfo->get_cms();
            foreach ($cms as $cm) {
                if ($cm->sectionnum == $sectionnum && $cm->uservisible) {
                    $completiondata = $completion->get_data($cm, false, $USER->id);
                    $iscompleted = $completiondata->completionstate == COMPLETION_COMPLETE;
                    
                    if ($iscompleted) {
                        $completedactivities++;
                    }
                    $activitycount++;
                    
                    // Get estimated time for activity
                    $estimatedtime = theme_remui_kids_get_activity_estimated_time($cm->modname);
                    
                    $sectionactivities[] = [
                        'activity_number' => $activitycount,
                        'id' => $cm->id,
                        'name' => $cm->name,
                        'type' => $cm->modname,
                        'duration' => $estimatedtime,
                        'points' => 100,
                        'icon' => theme_remui_kids_get_activity_icon($cm->modname),
                        'url' => $cm->url ? $cm->url->out() : '',
                        'completed' => $iscompleted
                    ];
                }
            }
            
            if ($activitycount > 0) {
                $sectionprogress = $activitycount > 0 ? round(($completedactivities / $activitycount) * 100) : 0;
                
                $lessons[] = [
                    'id' => $sectionnum,
                    'name' => $sectionname,
                    'activity_count' => $activitycount,
                    'progress_percentage' => $sectionprogress,
                    'has_activities' => !empty($sectionactivities),
                    'activities' => $sectionactivities,
                    'url' => (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $sectionnum]))->out()
                ];
            }
            
            $lessonnumber++;
        }
        
        // Calculate course progress
        $totalactivitiesincourse = array_sum(array_column($lessons, 'activity_count'));
        $totalcompletedincourse = 0;
        foreach ($lessons as $lesson) {
            $totalcompletedincourse += round(($lesson['progress_percentage'] / 100) * $lesson['activity_count']);
        }
        $courseprogresspercentage = $totalactivitiesincourse > 0 ? round(($totalcompletedincourse / $totalactivitiesincourse) * 100) : 0;
        
        $treeviewdata[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'total_sections' => count($lessons),
            'progress_percentage' => $courseprogresspercentage,
            'has_lessons' => !empty($lessons),
            'lessons' => $lessons,
            'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out()
        ];
        
        $totallessons += count($lessons);
        $totalactivities += $totalactivitiesincourse;
    }
}

// Prepare template context for the My Courses page
$templatecontext = [
    'custom_mycourses' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'has_courses' => !empty($studentcourses),
    'courses' => $studentcourses,
    
    // Tree view data
    'treeview_courses' => $treeviewdata,
    'has_treeview_courses' => !empty($treeviewdata),
    'total_lessons' => $totallessons,
    'total_activities' => $totalactivities,
    
    // Page identification flags for sidebar
    'is_mycourses_page' => true,
    'is_dashboard_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'mycoursesurl' => new moodle_url('/theme/remui_kids/moodle_mycourses.php'),
    'dashboardurl' => new moodle_url('/my/'),
    'assignmentsurl' => !empty($courses) ? (new moodle_url('/mod/assign/index.php', ['id' => reset($courses)->id]))->out() : (new moodle_url('/my/courses.php'))->out(),
    'lessonsurl' => new moodle_url('/theme/remui_kids/lessons.php'),
    'activitiesurl' => new moodle_url('/mod/quiz/index.php'),
    'achievementsurl' => new moodle_url('/theme/remui_kids/achievements.php'),
    'competenciesurl' => new moodle_url('/theme/remui_kids/competencies.php'),
    'gradesurl' => new moodle_url('/theme/remui_kids/grades.php'),
    'badgesurl' => new moodle_url('/theme/remui_kids/badges.php'),
    'scheduleurl' => new moodle_url('/theme/remui_kids/schedule.php'),
    'calendarurl' => new moodle_url('/calendar/view.php'),
    'settingsurl' => new moodle_url('/user/preferences.php'),
    'treeviewurl' => new moodle_url('/theme/remui_kids/treeview.php'),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => new moodle_url('/message/index.php'),
    'profileurl' => new moodle_url('/user/profile.php', ['id' => $USER->id]),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'config' => ['wwwroot' => $CFG->wwwroot],
    
    'currentpage' => [
        'mycourses' => true
    ]
];

// Purge all caches to ensure changes take effect


// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/moodle_mycourses_template', $templatecontext);
echo $OUTPUT->footer();
