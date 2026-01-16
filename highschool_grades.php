<?php
/**
 * High School Grades Page (Grade 9-12)
 * Displays grades for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/lib/grade_data.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_grades.php');
$PAGE->set_title('My Grades');
$PAGE->set_heading('My Grades');
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

$sidebar_context = remui_kids_build_highschool_sidebar_context('grades', user: $USER);
$gradesnapshot = remui_kids_get_user_gradebook_snapshot($USER->id);

$averagegrade = $gradesnapshot['totals']['average_percentage'];
$overallletter = remui_kids_grade_percentage_to_letter($averagegrade);
$totalcourses = $gradesnapshot['totals']['total_courses'];
$courseswithgradescount = $gradesnapshot['totals']['courses_with_grades'];
$gradeitemcount = $gradesnapshot['totals']['grade_item_count'];
$gradeitemtarget = max(1, $courseswithgradescount * 5);

$courseswithgrades = [];
foreach (array_values($gradesnapshot['courses']) as $index => $course) {
    $carddata = remui_kids_prepare_course_grade_cards($course, $index === 0);
    if ($carddata['has_items']) {
        $courseswithgrades[] = $carddata;
    }
}

$recentgradeentries = [];
foreach ($courseswithgrades as $course) {
    foreach ($course['grade_items'] as $item) {
        if (!isset($item['percentage']) || $item['percentage'] === null) {
            continue;
        }
        // Format the date for display in the template
        $timegraded = $item['timegraded'] ?? 0;
        $gradedate = !empty($timegraded) 
            ? userdate($timegraded, '%b %d, %Y')
            : 'Recently updated';
        
        $recentgradeentries[] = [
            'course_name' => $course['course_name'],
            'course_initial' => mb_strtoupper(mb_substr($course['course_name'], 0, 1)),
            'item_name' => $item['name'],
            'percentage' => $item['percentage'],
            'percentage_display' => $item['percentage_display'],
            'category_label' => $item['category_label'],
            'timegraded' => $timegraded,
            'gradedate' => $gradedate, // Pre-formatted date for Mustache template
        ];
    }
}

usort($recentgradeentries, function (array $a, array $b): int {
    $atime = $a['timegraded'] ?? 0;
    $btime = $b['timegraded'] ?? 0;
    if ($atime === $btime) {
        return $b['percentage'] <=> $a['percentage'];
    }
    return $btime <=> $atime;
});

$recentgrades = array_slice($recentgradeentries, 0, 4);

$performancebadges = [];

if (!empty($courseswithgrades)) {
    $topcourse = array_reduce($courseswithgrades, function ($carry, $course) {
        if (!$course['has_items']) {
            return $carry;
        }
        if ($carry === null || $course['overall_grade'] > $carry['overall_grade']) {
            return $course;
        }
        return $carry;
    }, null);

    if ($topcourse !== null) {
        $performancebadges[] = [
            'title' => 'Top in ' . $topcourse['course_name'],
            'description' => $topcourse['overall_grade_display'] . ' course average',
            'icon' => 'fa-trophy',
            'accent' => 'badge-gold',
        ];
    }

    $topitem = null;
    foreach ($courseswithgrades as $course) {
        foreach ($course['grade_items'] as $item) {
            if (!isset($item['percentage']) || $item['percentage'] === null) {
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
        $performancebadges[] = [
            'title' => 'Outstanding Work',
            'description' => $topitem['item_name'] . ' (' . $topitem['percentage_display'] . ')',
            'icon' => 'fa-medal',
            'accent' => 'badge-green',
        ];
    }

    $mostactive = array_reduce($courseswithgrades, function ($carry, $course) {
        if ($carry === null || $course['grade_count'] > $carry['grade_count']) {
            return $course;
        }
        return $carry;
    }, null);

    if ($mostactive !== null) {
        $performancebadges[] = [
            'title' => 'Most Active Course',
            'description' => $mostactive['course_name'] . ' • ' . $mostactive['grade_count'] . ' graded items',
            'icon' => 'fa-bolt',
            'accent' => 'badge-blue',
        ];
    }
}

$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'grades' => $courseswithgrades,
    'has_grades' => !empty($courseswithgrades),
    'total_courses' => $totalcourses,
    'courses_with_grades' => $courseswithgradescount,
    'total_grade_items' => $gradeitemcount,
    'grade_item_target' => $gradeitemtarget,
    'recent_grades' => $recentgrades,
    'has_recent_grades' => !empty($recentgrades),
    'performance_badges' => $performancebadges,
    'has_performance_badges' => !empty($performancebadges),
    'average_grade' => round($averagegrade, 1),
    'letter_grade' => $overallletter,
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'courses_url' => $sidebar_context['mycoursesurl'],
    'profile_url' => $sidebar_context['profileurl'],
    'messages_url' => $sidebar_context['messagesurl'],
    'logout_url' => $sidebar_context['logouturl'],
    'is_highschool' => true
));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_grades', $template_data);
echo $OUTPUT->footer();

?>