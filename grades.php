<?php
/**
 * Grades Page - Student Grades and Performance
 * Displays student grades, performance metrics, and academic progress
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
require_once(__DIR__ . '/lib/grade_data.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/grades.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Grades', false);

// Get user's cohort information
try {
    $usercohorts = $DB->get_records_sql(
        "SELECT c.name, c.id 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$USER->id]
    );
} catch (Exception $e) {
    // If there's an error, set empty array and continue
    $usercohorts = [];
    error_log("Error fetching user cohorts: " . $e->getMessage());
}

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

$gradesnapshot = remui_kids_get_user_gradebook_snapshot($USER->id);

$total_courses = $gradesnapshot['totals']['total_courses'];
$completed_courses = count(array_filter($gradesnapshot['courses'], function(array $course): bool {
    return !empty($course['is_completed']);
}));
$average_grade = $gradesnapshot['totals']['average_percentage'];
$grade_distribution = $gradesnapshot['distribution'];

$grades_data = [];
$has_grades = false;

foreach (array_values($gradesnapshot['courses']) as $index => $course) {
    $carddata = remui_kids_prepare_course_grade_cards($course, $index === 0);
    $grades_data[] = $carddata;
    if ($carddata['has_items']) {
        $has_grades = true;
    }
}

$recent_grade_entries = [];
foreach ($grades_data as $course) {
    if (empty($course['grade_items'])) {
        continue;
    }
    foreach ($course['grade_items'] as $item) {
        if ($item['percentage'] === null) {
            continue;
        }
        $recent_grade_entries[] = [
            'course_name' => $course['course_name'],
            'course_shortname' => $course['course_shortname'],
            'item_name' => $item['name'],
            'category_label' => $item['category_label'],
            'percentage' => $item['percentage'],
            'percentage_display' => $item['percentage_display'],
            'timegraded' => $item['timegraded'] ?? 0,
        ];
    }
}

usort($recent_grade_entries, function(array $a, array $b): int {
    $atime = $a['timegraded'] ?? 0;
    $btime = $b['timegraded'] ?? 0;
    if ($atime === $btime) {
        return $b['percentage'] <=> $a['percentage'];
    }
    return $btime <=> $atime;
});

$recent_grades = array_map(function(array $entry): array {
    return [
        'course_name' => $entry['course_name'],
        'course_initial' => mb_strtoupper(mb_substr($entry['course_name'], 0, 1)),
        'item_name' => $entry['item_name'],
        'percentage_display' => $entry['percentage_display'],
        'category_label' => $entry['category_label'],
    ];
}, array_slice($recent_grade_entries, 0, 4));

$performance_badges = [];

if (!empty($grades_data)) {
    $topcourse = array_reduce($grades_data, function($carry, $course) {
        if (!$course['has_items']) {
            return $carry;
        }
        if ($carry === null || $course['overall_grade'] > $carry['overall_grade']) {
            return $course;
        }
        return $carry;
    }, null);

    if ($topcourse !== null) {
        $performance_badges[] = [
            'title' => 'Top in ' . $topcourse['course_name'],
            'description' => $topcourse['overall_grade_display'] . ' course average',
            'icon' => 'fa-trophy',
            'accent' => 'badge-gold',
        ];
    }

    $topitem = null;
    foreach ($grades_data as $course) {
        foreach ($course['grade_items'] as $item) {
            if ($item['percentage'] === null) {
                continue;
            }
            if ($topitem === null || $item['percentage'] > $topitem['percentage']) {
                $topitem = [
                    'course_name' => $course['course_name'],
                    'item_name' => $item['name'],
                    'percentage' => $item['percentage'],
                    'percentage_display' => $item['percentage_display'],
                ];
            }
        }
    }

    if ($topitem !== null) {
        $performance_badges[] = [
            'title' => 'Outstanding Work',
            'description' => $topitem['item_name'] . ' (' . $topitem['percentage_display'] . ')',
            'icon' => 'fa-medal',
            'accent' => 'badge-green',
        ];
    }

    $mostactive = array_reduce($grades_data, function($carry, $course) {
        if ($carry === null || $course['grade_count'] > $carry['grade_count']) {
            return $course;
        }
        return $carry;
    }, null);

    if ($mostactive !== null) {
        $performance_badges[] = [
            'title' => 'Most Active Course',
            'description' => $mostactive['course_name'] . ' • ' . $mostactive['grade_count'] . ' graded items',
            'icon' => 'fa-bolt',
            'accent' => 'badge-blue',
        ];
    }
}

// Prepare template context for the Grades page
$templatecontext = [
    'custom_grades' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'grades_data' => $grades_data,
    'has_grades' => $has_grades,
    'recent_grades' => $recent_grades,
    'has_recent_grades' => !empty($recent_grades),
    'performance_badges' => $performance_badges,
    'has_performance_badges' => !empty($performance_badges),
    'total_courses' => $total_courses,
    'completed_courses' => $completed_courses,
    'average_grade' => $average_grade,
    'grade_distribution' => $grade_distribution,
    
    // Page identification flags for sidebar
    'is_grades_page' => true,
    'is_dashboard_page' => false,
];

// Get first enrolled course for assignments URL
$enrolledcourses = enrol_get_users_courses($USER->id, true, ['id']);
$firstcourseid = !empty($enrolledcourses) ? reset($enrolledcourses)->id : null;

// Add navigation URLs
$templatecontext = array_merge($templatecontext, [
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'mycoursesurl' => new moodle_url('/theme/remui_kids/moodle_mycourses.php'),
    'dashboardurl' => new moodle_url('/my/'),
    'assignmentsurl' => $firstcourseid ? (new moodle_url('/mod/assign/index.php', ['id' => $firstcourseid]))->out() : (new moodle_url('/my/courses.php'))->out(),
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
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => new moodle_url('/message/index.php'),
    'profileurl' => new moodle_url('/user/profile.php', ['id' => $USER->id]),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
    'currentpage' => [
        'grades' => true
    ],
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out()
]);

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/grades_page', $templatecontext);
echo $OUTPUT->footer();
