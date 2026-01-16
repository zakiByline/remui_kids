<?php
/**
 * High School Tree View (Grades 9-12)
 * Dedicated learning path explorer for high school cohorts.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');

// Must set URL before require_login to prevent redirects.
$PAGE->set_url(new moodle_url('/theme/remui_kids/highschool_treeview.php'));

// Configure page before login.
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('High School Tree View');
$PAGE->set_heading('Learning Path Explorer – High School');
$PAGE->add_body_class('highschool-treeview-page');
$PAGE->add_body_class('has-student-sidebar');

require_login(null, false);

$usercohortname = '';
$usercohortid = 0;
$is_highschool_student = false;

// Determine cohort information.
$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;

    if (preg_match('/grade\s*(?:9|1[0-2])/i', $usercohortname)) {
        $is_highschool_student = true;
    }
}

// Fallback to profile field detection if needed.
if (!$is_highschool_student) {
    $userprofile = profile_user_record($USER->id);
    if (!empty($userprofile->grade) && preg_match('/grade\s*(?:9|1[0-2])/i', $userprofile->grade)) {
        $is_highschool_student = true;
    }
}

// Allow staff/teachers to access even without cohort match.
$userroles = get_user_roles($context, $USER->id, true);
$has_staff_role = false;
foreach ($userroles as $role) {
    if (in_array($role->shortname, ['editingteacher', 'teacher', 'manager', 'coursecreator', 'admin'])) {
        $has_staff_role = true;
        break;
    }
}

if (!$is_highschool_student && !$has_staff_role && !is_siteadmin()) {
    // Redirect other grades back to the generic tree view.
    redirect(new moodle_url('/theme/remui_kids/treeview.php'));
}

// Get all enrolled courses for the user.
$courses = enrol_get_all_users_courses($USER->id, true);
$coursedata = [];
$totalcourses = 0;
$total_lessons = 0;
$total_activities = 0;
$overall_progress = 0;

foreach ($courses as $course) {
    $totalcourses++;

    $completion = new completion_info($course);
    $coursecompletion = $completion->get_completion($USER->id, COMPLETION_CRITERIA_TYPE_COURSE);
    $courseprogress = ($coursecompletion && isset($coursecompletion->completionstate)) ? $coursecompletion->completionstate : 0;

    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();

    $lessons = [];
    $lessonnumber = 1;

    foreach ($sections as $sectionnum => $section) {
        if ($sectionnum == 0) {
            continue;
        }

        $sectionname = $section->name ?: "Lesson " . $lessonnumber;
        $sectionactivities = [];
        $activitycount = 0;
        $completedactivities = 0;

        $cms = $modinfo->get_cms();
        foreach ($cms as $cm) {
            if ($cm->sectionnum == $sectionnum && $cm->uservisible) {
                $completiondata = $completion->get_data($cm, false, $USER->id);
                $iscompleted = !empty($completiondata) && $completiondata->completionstate == COMPLETION_COMPLETE;

                if ($iscompleted) {
                    $completedactivities++;
                }
                $activitycount++;

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

    $course_total_activities = array_sum(array_column($lessons, 'activity_count'));
    $course_total_completed = 0;
    foreach ($lessons as $lesson) {
        $course_total_completed += round(($lesson['progress_percentage'] / 100) * $lesson['activity_count']);
    }
    $courseprogresspercentage = $course_total_activities > 0 ? round(($course_total_completed / $course_total_activities) * 100) : 0;

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

    $total_lessons += count($lessons);
    $total_activities += $course_total_activities;
    $overall_progress += $courseprogresspercentage;
}

$overall_progress = $totalcourses > 0 ? round($overall_progress / $totalcourses) : 0;

// Build sidebar/nav context for high school students.
$sidebarcontext = [];
if (function_exists('remui_kids_build_highschool_sidebar_context')) {
    $sidebarcontext = remui_kids_build_highschool_sidebar_context('treeview', $USER, [
        'treeviewurl' => (new moodle_url('/theme/remui_kids/highschool_treeview.php'))->out()
    ]);
}

// Prepare template context.
$templatecontext = array_merge([
    'custom_treeview' => true,
    'dashboardtype' => 'highschool',
    'dashboard_type' => 'highschool',
    'is_highschool_grade' => true,
    'is_highschool_treeview' => true,
    'usercohortname' => $usercohortname,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname ?: $USER->username,
    'student_fullname' => fullname($USER),
    'courses_data' => $coursedata,
    'courses' => $coursedata,
    'has_courses' => !empty($coursedata),
    'total_courses' => $totalcourses,
    'total_lessons' => $total_lessons,
    'total_activities' => $total_activities,
    'overall_progress' => $overall_progress,
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/highschool_courses.php'))->out(),
    'lessonsurl' => (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/schedule.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/highschool_treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/highschool_profile.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor.php'))->out(),
    'calendarurl' => (new moodle_url('/theme/remui_kids/highschool_calendar.php'))->out(),
    'assignmentsurl' => (new moodle_url('/theme/remui_kids/highschool_assignments.php'))->out(),
    'gradesurl' => (new moodle_url('/theme/remui_kids/highschool_grades.php'))->out(),
    'badgesurl' => (new moodle_url('/theme/remui_kids/badges.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => (new moodle_url('/theme/remui_kids/highschool_messages.php'))->out(),
    'communityurl' => (new moodle_url('/theme/remui_kids/community.php'))->out(),
    'currentpage' => [
        'treeview' => true
    ],
    'is_treeview_page' => true,
    'is_dashboard_page' => false,
], $sidebarcontext);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_treeview_page', $templatecontext);
echo $OUTPUT->footer();

