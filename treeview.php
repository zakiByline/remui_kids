<?php
/**
 * Tree View Page - Learning Path Explorer
 * Displays Courses → Lessons → Activities in a hierarchical tree format
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');

// Must set URL before require_login to prevent redirects
$PAGE->set_url(new moodle_url('/theme/remui_kids/treeview.php'));

// Set page configuration BEFORE require_login
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('sitename') . ' - Learning Path Explorer');
$PAGE->set_heading('Learning Path Explorer');

// Require login without course
require_login(null, false);

// Determine user's cohort and dashboard type
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'default';

$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;
    
    // DEBUG: Log cohort information
    error_log("TREEVIEW DEBUG: User ID: " . $USER->id . ", Cohort: " . $usercohortname);
    
    // Determine dashboard type based on cohort name (must match layout/drawers.php patterns)
    // Check for Grade 8-12 (High School) - Check this first to avoid conflicts
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $dashboardtype = 'highschool';
        error_log("TREEVIEW DEBUG: Detected as HIGH SCHOOL student");
    }
    // Check for Grade 4-7 (Middle)
    elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
        $dashboardtype = 'middle';
        error_log("TREEVIEW DEBUG: Detected as MIDDLE SCHOOL student (Grades 4-7)");
    }
    // Check for Grade 1-3 (Elementary) - Check this last and redirect
    elseif (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
        error_log("TREEVIEW DEBUG: Detected as ELEMENTARY student - REDIRECTING to elementary_treeview.php");
        // Redirect elementary students (Grades 1-3) to their dedicated treeview page
        redirect(new moodle_url('/theme/remui_kids/elementary_treeview.php'));
        exit;
    }
    else {
        error_log("TREEVIEW DEBUG: No grade match found, defaulting to: " . $dashboardtype);
    }
}

// Get all enrolled courses for the user
$courses = enrol_get_all_users_courses($USER->id, true);
$coursedata = [];
$totalcourses = 0;
$total_lessons = 0;
$total_activities = 0;
$overall_progress = 0;

foreach ($courses as $course) {
    $totalcourses++;
    
    // Get course completion info
    $completion = new completion_info($course);
    $coursecompletion = $completion->get_completion($USER->id, COMPLETION_CRITERIA_TYPE_COURSE);
    $courseprogress = $coursecompletion ? $coursecompletion->completionstate : 0;
    
    // Get all course modules
    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    
    // Count sections
    $sectioncount = count($sections);
    
    // Get lessons (treating lessons as "sections" in the new design)
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
                    'points' => 100, // Default points
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
    $totalactivities = array_sum(array_column($lessons, 'activity_count'));
    $totalcompleted = 0;
    foreach ($lessons as $lesson) {
        $totalcompleted += round(($lesson['progress_percentage'] / 100) * $lesson['activity_count']);
    }
    $courseprogresspercentage = $totalactivities > 0 ? round(($totalcompleted / $totalactivities) * 100) : 0;
    
    $coursedata[] = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'total_sections' => count($lessons),
        'progress_percentage' => $courseprogresspercentage,
        'has_lessons' => !empty($lessons),
        'lessons' => $lessons,
        'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out()
    ];
    
    // Add to totals
    $total_lessons += count($lessons);
    $total_activities += $totalactivities;
    $overall_progress += $courseprogresspercentage;
}

// Calculate overall progress percentage
$overall_progress = $totalcourses > 0 ? round($overall_progress / $totalcourses) : 0;

// Prepare template context
$templatecontext = [
    'custom_treeview' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'courses_data' => $coursedata,
    'has_courses' => !empty($coursedata),
    'total_courses' => count($coursedata),
    'custom_treeview' => false, // Don't render full HTML - we're using Moodle's header/footer
    'dashboard_type' => $dashboardtype,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_fullname' => fullname($USER),
    
    // Tree data
    'total_courses' => $totalcourses,
    'total_lessons' => $total_lessons,
    'total_activities' => $total_activities,
    'overall_progress' => $overall_progress,
    'has_courses' => !empty($coursedata),
    'courses' => $coursedata,
    
    // URLs for navigation - Grade 4+ students only (elementary redirected above)
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/my/courses.php'))->out(),
    'lessonsurl' => (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/admin/tool/lp/index.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/schedule.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor.php'))->out(),
    'calendarurl' => (new moodle_url('/calendar/view.php'))->out(),
    
    // Page identification flags for sidebar
    'is_treeview_page' => true,
    'is_dashboard_page' => false,
    
    // Additional URLs for G4G7 sidebar
    'wwwroot' => $CFG->wwwroot,
    'assignmentsurl' => new moodle_url('/mod/assign/index.php'),
    'gradesurl' => new moodle_url('/theme/remui_kids/grades.php'),
    'badgesurl' => new moodle_url('/theme/remui_kids/badges.php'),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => new moodle_url('/message/index.php'),
    'profileurl' => new moodle_url('/user/profile.php', ['id' => $USER->id]),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
    'currentpage' => [
        'treeview' => true
    ],
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
];

// Debug: Log that we're rendering treeview
error_log("TREEVIEW DEBUG: About to render treeview template for dashboard type: " . $dashboardtype);
error_log("TREEVIEW DEBUG: URL = " . $PAGE->url->get_path());
error_log("TREEVIEW DEBUG: Has courses = " . (!empty($coursedata) ? 'YES' : 'NO'));
error_log("TREEVIEW DEBUG: Total courses = " . $totalcourses);

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/treeview_page', $templatecontext);
echo $OUTPUT->footer();

// Debug: Log after rendering
error_log("TREEVIEW DEBUG: Template rendered successfully");