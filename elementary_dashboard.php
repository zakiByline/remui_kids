<?php
/**
 * Elementary Dashboard Page for Grades 1-3
 * 
 * Child-friendly dashboard with colorful summary cards and engaging interface
 * 
 * @package    theme_remui_kids
 * @copyright  2025 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php');
require_once($CFG->libdir.'/adminlib.php');
require_once(__DIR__ . '/lib/sidebar_helper.php');

require_login();

global $USER, $PAGE, $OUTPUT, $DB;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_dashboard.php');
$PAGE->set_title('My Dashboard - Elementary');
$PAGE->set_heading('Elementary Dashboard');
$PAGE->set_pagelayout('mydashboard');

// Check if user is elementary student (Grades 1-3)
$user_cohorts = [];
try {
    $user_cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name, c.idnumber 
         FROM {cohort} c
         JOIN {cohort_members} cm ON c.id = cm.cohortid
         WHERE cm.userid = ? AND (c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ?)",
        [$USER->id, 'grade1%', 'grade2%', 'grade3%', 'elementary%']
    );
} catch (Exception $e) {
    $user_cohorts = [];
}

// Get user's enrolled courses
$courses = enrol_get_my_courses(['id', 'fullname', 'summary', 'startdate', 'enddate'], 'fullname ASC');

// Calculate statistics
$courses_count = count($courses);
$lessons_completed = 0;
$activities_done = 0;
$total_progress = 0;
$grade_sum = 0;
$grade_count = 0;

$course_data = [];
foreach ($courses as $course) {
    // Get course completion
    $completion = new completion_info($course);
    $progress = 0;
    
    if ($completion->is_enabled()) {
        $percentage = progress::get_course_progress_percentage($course, $USER->id);
        $progress = $percentage ? round($percentage) : 0;
    }
    
    $total_progress += $progress;
    
    // Get course grade
    $grade_item = grade_item::fetch_course_item($course->id);
    $grade = '';
    if ($grade_item) {
        $grade_grade = new grade_grade(array('itemid' => $grade_item->id, 'userid' => $USER->id));
        if ($grade_grade->finalgrade) {
            $grade = round($grade_grade->finalgrade);
            $grade_sum += $grade;
            $grade_count++;
        }
    }
    
    // Determine status
    $status_class = 'not-started';
    $status_icon = 'fa-play';
    if ($progress > 80) {
        $status_class = 'completed';
        $status_icon = 'fa-check';
    } elseif ($progress > 0) {
        $status_class = 'in-progress';
        $status_icon = 'fa-clock';
    }
    
    // Get course activities count
    $modinfo = get_fast_modinfo($course);
    $activities_done += count($modinfo->get_cms());
    
    $course_data[] = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'summary' => strip_tags($course->summary),
        'progress' => $progress,
        'grade' => $grade ? $grade . '%' : '',
        'course_level' => 'Elementary',
        'status_class' => $status_class,
        'status_icon' => $status_icon,
        'course_image' => null // You can add course image logic here
    ];
}

// Calculate averages
$grade_average = $grade_count > 0 ? round($grade_sum / $grade_count) : 0;
$overall_progress = $courses_count > 0 ? round($total_progress / $courses_count) : 0;
$lessons_average = rand(75, 95); // Mock data - replace with actual calculation
$activities_percentage = rand(80, 100); // Mock data
$progress_average = $grade_average;

// Get upcoming events (mock data - replace with actual calendar events)
$upcoming_events = [
    [
        'day' => '15',
        'month' => 'Jan',
        'title' => 'Math Quiz',
        'description' => 'Addition and Subtraction practice',
        'time' => '10:00 AM'
    ],
    [
        'day' => '18',
        'month' => 'Jan',
        'title' => 'Science Project',
        'description' => 'Show and tell about animals',
        'time' => '2:00 PM'
    ]
];

// Get leaderboard data - Top 5 performers in elementary courses
$leaderboard_users = [];
try {
    // Get users from elementary cohorts
    $elementary_users = $DB->get_records_sql("
        SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
        FROM {user} u
        JOIN {cohort_members} cm ON u.id = cm.userid
        JOIN {cohort} c ON cm.cohortid = c.id
        WHERE c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ?
        AND u.deleted = 0 AND u.suspended = 0
        LIMIT 100
    ", ['grade1%', 'grade2%', 'grade3%', 'elementary%']);

    $user_scores = [];
    foreach ($elementary_users as $user) {
        $total_score = 0;
        $course_count = 0;

        // Get user's enrolled courses
        $user_courses = enrol_get_my_courses(['id'], null, 0, [], false, 0, [$user->id]);

        foreach ($user_courses as $course) {
            // Calculate progress score (0-100)
            $completion = new completion_info($course);
            $progress = 0;

            if ($completion->is_enabled()) {
                $percentage = progress::get_course_progress_percentage($course, $user->id);
                $progress = $percentage ? round($percentage) : 0;
            }

            // Get course grade
            $grade_item = grade_item::fetch_course_item($course->id);
            $grade = 0;
            if ($grade_item) {
                $grade_grade = new grade_grade(array('itemid' => $grade_item->id, 'userid' => $user->id));
                if ($grade_grade->finalgrade) {
                    $grade = round($grade_grade->finalgrade);
                }
            }

            // Combine progress and grade for total score
            $course_score = ($progress + $grade) / 2;
            $total_score += $course_score;
            $course_count++;
        }

        if ($course_count > 0) {
            $average_score = round($total_score / $course_count);
            $user_scores[] = [
                'id' => $user->id,
                'name' => $user->firstname . ' ' . $user->lastname,
                'score' => $average_score,
                'courses' => $course_count
            ];
        }
    }

    // Sort by score descending and get top 5
    usort($user_scores, function($a, $b) {
        return $b['score'] - $a['score'];
    });

    $leaderboard_users = array_slice($user_scores, 0, 5);

    // Add rank and format score
    foreach ($leaderboard_users as $index => &$user) {
        $user['rank'] = $index + 1;
        $user['display_score'] = $user['score'] . '%';
        $user['is_current_user'] = ($user['id'] == $USER->id);
        $user['is_rank_1'] = ($index + 1 == 1);
        $user['is_rank_2'] = ($index + 1 == 2);
        $user['is_rank_3'] = ($index + 1 == 3);
    }

} catch (Exception $e) {
    // Fallback to empty leaderboard
    $leaderboard_users = [];
}

// Get sidebar context
$sidebar_context = theme_remui_kids_get_elementary_sidebar_context('dashboard', $USER);

// Prepare template context
$template_context = array_merge($sidebar_context, [
    'student_name' => $USER->firstname,
    'courses_count' => $courses_count,
    'lessons_completed' => $lessons_completed,
    'activities_done' => $activities_done,
    'overall_progress' => $overall_progress,
    'grade_average' => $grade_average,
    'lessons_average' => $lessons_average,
    'activities_percentage' => $activities_percentage,
    'progress_average' => $progress_average,
    'courses' => $course_data,
    'upcoming_events' => $upcoming_events,
    'leaderboard_users' => $leaderboard_users,
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/my/'))->out(),
    'activitiesurl' => (new moodle_url('/my/'))->out(),
    'myreportsurl' => (new moodle_url('/my/'))->out(),
]);

echo $OUTPUT->header();

// Render sidebar
echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_sidebar', $sidebar_context);

// Render main dashboard content
echo '<div class="elementary-dashboard">';
echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_dashboard', $template_context);
echo '</div>';

echo $OUTPUT->footer();
?>