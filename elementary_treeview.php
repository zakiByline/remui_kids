<?php
/**
 * Elementary Tree View Page - Learning Path Explorer for Elementary Students (Grades 1-3)
 * Displays Courses → Lessons → Activities in a hierarchical tree format
 * This is a dedicated page for elementary students only.
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
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Must set URL before require_login to prevent redirects
$PAGE->set_url(new moodle_url('/theme/remui_kids/elementary_treeview.php'));

// Set page configuration BEFORE require_login
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('sitename') . ' - Learning Path Explorer');
$PAGE->set_heading('Learning Path Explorer');

// Require login without course
require_login(null, false);

// Determine user's cohort - ELEMENTARY ONLY
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'elementary';

$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;
    
    // Verify this is an elementary student (Grades 1-3)
    if (!preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        // Redirect to general treeview if not elementary student
        redirect(new moodle_url('/theme/remui_kids/treeview.php'));
        exit;
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
    
    // Get lessons (treating lessons as "sections" in the new design)
    $lessons = [];
    $lessonnumber = 1;
    
    foreach ($sections as $sectionnum => $section) {
        if ($sectionnum == 0) continue; // Skip section 0
        
        $sectionname = $section->name ?: "Lesson " . $lessonnumber;
        $sectionactivities = [];
        $activitycount = 0;
        $completedactivities = 0;
        
        // Get activities in this section - OPTIMIZED: only get modules in this specific section
        if (!empty($section->sequence)) {
            $sequencemodids = explode(',', $section->sequence);
            foreach ($sequencemodids as $modid) {
                $cm = $modinfo->get_cm($modid);
                if ($cm->uservisible) {
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

// Prepare template context - ELEMENTARY SPECIFIC
$templatecontext = [
    'custom_treeview' => false,
    'student_name' => $USER->firstname ?: $USER->username,
    'student_fullname' => fullname($USER),
    'usercohortname' => $usercohortname,
    'dashboardtype' => 'elementary',
    'dashboard_type' => 'elementary',
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'is_elementary_grade' => true,
    'is_middle_grade' => false,
    'is_high_grade' => false,
    
    // Tree data
    'total_courses' => $totalcourses,
    'total_lessons' => $total_lessons,
    'total_activities' => $total_activities,
    'overall_progress' => $overall_progress,
    'has_courses' => !empty($coursedata),
    'courses' => $coursedata,
    'courses_data' => $coursedata,
    
    // Elementary-specific URLs for navigation (sidebar support)
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'calendarurl' => (new moodle_url('/calendar/view.php'))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => (new moodle_url('/message/index.php'))->out(),
    
    // Page identification flags for sidebar highlighting
    'is_treeview_page' => true,
    'is_treeview_active' => true,
    'is_dashboard_page' => false,
    'is_mycourses_page' => false,
    'is_lessons_page' => false,
    'is_activities_page' => false,
    'is_schedule_page' => false,
    
    // User data for sidebar
    'userid' => $USER->id,
    'username' => fullname($USER),
    'useremail' => $USER->email,
    'userprofileimageurl' => $OUTPUT->user_picture($USER, ['size' => 100]),
    
    // Additional context
    'wwwroot' => $CFG->wwwroot,
    'currentpage' => [
        'treeview' => true
    ],
    
    // Help button
    'help_button_html' => $help_button_html,
];

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_treeview_page', $templatecontext);
echo $OUTPUT->footer();