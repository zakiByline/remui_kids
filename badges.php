<?php
/**
 * Badges Page - Student Badges and Certifications
 * Displays student badges, certifications, and recognition awards
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/badges.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Badges', false);

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

// Get enrolled courses
$courses = enrol_get_all_users_courses($USER->id, true);
$courseids = array_keys($courses);

// Get badges data
$badges = [];
$total_badges = 0;
$recent_badges = [];

// Define badge categories
$badge_categories = [
    'academic' => [
        'name' => 'Academic Excellence',
        'icon' => 'fa-graduation-cap',
        'color' => 'blue',
        'badges' => [
            'perfect_score' => ['name' => 'Perfect Score', 'description' => 'Achieved 100% on an assignment'],
            'quiz_master' => ['name' => 'Quiz Master', 'description' => 'Scored 90%+ on 5 quizzes'],
            'course_completer' => ['name' => 'Course Completer', 'description' => 'Completed a full course'],
            'homework_hero' => ['name' => 'Homework Hero', 'description' => 'Submitted 10 assignments on time']
        ]
    ],
    'participation' => [
        'name' => 'Active Participation',
        'icon' => 'fa-users',
        'color' => 'green',
        'badges' => [
            'discussion_leader' => ['name' => 'Discussion Leader', 'description' => 'Active in course discussions'],
            'helpful_peer' => ['name' => 'Helpful Peer', 'description' => 'Helped classmates with questions'],
            'early_bird' => ['name' => 'Early Bird', 'description' => 'Always submits work early'],
            'team_player' => ['name' => 'Team Player', 'description' => 'Excellent collaboration skills']
        ]
    ],
    'creativity' => [
        'name' => 'Creativity & Innovation',
        'icon' => 'fa-lightbulb',
        'color' => 'purple',
        'badges' => [
            'creative_thinker' => ['name' => 'Creative Thinker', 'description' => 'Shows innovative problem-solving'],
            'artistic_talent' => ['name' => 'Artistic Talent', 'description' => 'Demonstrates artistic abilities'],
            'storyteller' => ['name' => 'Storyteller', 'description' => 'Creates engaging stories'],
            'inventor' => ['name' => 'Inventor', 'description' => 'Designs original solutions']
        ]
    ],
    'special' => [
        'name' => 'Special Achievements',
        'icon' => 'fa-star',
        'color' => 'gold',
        'badges' => [
            'perfect_attendance' => ['name' => 'Perfect Attendance', 'description' => 'Never missed a class'],
            'improvement_champion' => ['name' => 'Improvement Champion', 'description' => 'Showed significant improvement'],
            'mentor' => ['name' => 'Mentor', 'description' => 'Helped others learn'],
            'leader' => ['name' => 'Leader', 'description' => 'Demonstrated leadership skills']
        ]
    ]
];

// Simulate earned badges based on user activity
$earned_badges = [];

// Academic badges
if (rand(1, 3) == 1) {
    $earned_badges[] = [
        'category' => 'academic',
        'key' => 'perfect_score',
        'name' => 'Perfect Score',
        'description' => 'Achieved 100% on an assignment',
        'icon' => 'fa-graduation-cap',
        'color' => 'blue',
        'date_earned' => time() - rand(1, 30) * 24 * 3600,
        'course_name' => 'Mathematics'
    ];
}

if (rand(1, 2) == 1) {
    $earned_badges[] = [
        'category' => 'academic',
        'key' => 'quiz_master',
        'name' => 'Quiz Master',
        'description' => 'Scored 90%+ on 5 quizzes',
        'icon' => 'fa-trophy',
        'color' => 'blue',
        'date_earned' => time() - rand(1, 20) * 24 * 3600,
        'course_name' => 'Science'
    ];
}

// Participation badges
if (rand(1, 3) == 1) {
    $earned_badges[] = [
        'category' => 'participation',
        'key' => 'discussion_leader',
        'name' => 'Discussion Leader',
        'description' => 'Active in course discussions',
        'icon' => 'fa-users',
        'color' => 'green',
        'date_earned' => time() - rand(1, 15) * 24 * 3600,
        'course_name' => 'English'
    ];
}

// Creativity badges
if (rand(1, 4) == 1) {
    $earned_badges[] = [
        'category' => 'creativity',
        'key' => 'creative_thinker',
        'name' => 'Creative Thinker',
        'description' => 'Shows innovative problem-solving',
        'icon' => 'fa-lightbulb',
        'color' => 'purple',
        'date_earned' => time() - rand(1, 25) * 24 * 3600,
        'course_name' => 'Art'
    ];
}

// Special badges
if (rand(1, 5) == 1) {
    $earned_badges[] = [
        'category' => 'special',
        'key' => 'improvement_champion',
        'name' => 'Improvement Champion',
        'description' => 'Showed significant improvement',
        'icon' => 'fa-star',
        'color' => 'gold',
        'date_earned' => time() - rand(1, 10) * 24 * 3600,
        'course_name' => 'All Subjects'
    ];
}

// Sort badges by date earned
usort($earned_badges, function($a, $b) {
    return $b['date_earned'] - $a['date_earned'];
});

$total_badges = count($earned_badges);
$recent_badges = array_slice($earned_badges, 0, 3);

// Calculate category counts
$category_counts = [
    'academic' => count(array_filter($earned_badges, function($b) { return $b['category'] === 'academic'; })),
    'participation' => count(array_filter($earned_badges, function($b) { return $b['category'] === 'participation'; })),
    'creativity' => count(array_filter($earned_badges, function($b) { return $b['category'] === 'creativity'; })),
    'special' => count(array_filter($earned_badges, function($b) { return $b['category'] === 'special'; }))
];

// Prepare template context for the Badges page
$templatecontext = [
    'custom_badges' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'badges' => $earned_badges,
    'recent_badges' => $recent_badges,
    'badge_categories' => $badge_categories,
    'has_badges' => !empty($earned_badges),
    'total_badges' => $total_badges,
    'category_counts' => $category_counts,
    
    // Page identification flags for sidebar
    'is_badges_page' => true,
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
'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => new moodle_url('/message/index.php'),
    'profileurl' => new moodle_url('/user/profile.php', ['id' => $USER->id]),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
'currentpage' => [
        'badges' => true
    ],
'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out()
];

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/badges_page', $templatecontext);
echo $OUTPUT->footer();
