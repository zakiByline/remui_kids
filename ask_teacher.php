<?php
/**
 * Ask Teacher Page - Student-Teacher Communication
 * Allows students to ask questions and get help from teachers
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

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/ask_teacher.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('Ask Teacher', false);

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

// Get enrolled courses
try {
    $courses = enrol_get_all_users_courses($USER->id, true);
    $courseids = array_keys($courses);
} catch (Exception $e) {
    $courses = [];
    $courseids = [];
    error_log("Error fetching enrolled courses: " . $e->getMessage());
}

// Get teachers from enrolled courses
$teachers_data = [];
$total_teachers = 0;

if (!empty($courseids)) {
    try {
        // Get teachers from courses
        list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        
        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.picture,
                    c.id as courseid, c.fullname as coursename,
                    r.shortname as role
             FROM {user} u
             JOIN {role_assignments} ra ON u.id = ra.userid
             JOIN {role} r ON ra.roleid = r.id
             JOIN {context} ctx ON ra.contextid = ctx.id
             JOIN {course} c ON ctx.instanceid = c.id
             WHERE c.id $courseids_sql
             AND ctx.contextlevel = 50
             AND r.shortname IN ('teacher', 'editingteacher')
             AND u.deleted = 0
             AND u.suspended = 0
             ORDER BY c.fullname ASC, u.firstname ASC",
            $params
        );
        
        foreach ($teachers as $teacher) {
            $teachers_data[] = [
                'id' => $teacher->id,
                'name' => $teacher->firstname . ' ' . $teacher->lastname,
                'email' => $teacher->email,
                'course_name' => $teacher->coursename,
                'course_id' => $teacher->courseid,
                'role' => $teacher->role,
                'profile_url' => (new moodle_url('/user/profile.php', ['id' => $teacher->id]))->out(),
                'message_url' => (new moodle_url('/message/index.php', ['id' => $teacher->id]))->out(),
                'avatar' => $teacher->picture ? '1' : '0'
            ];
        }
        
        $total_teachers = count($teachers_data);
        
    } catch (Exception $e) {
        error_log("Error fetching teachers: " . $e->getMessage());
    }
}

// If no real teachers found, add some sample data
if (empty($teachers_data)) {
    $teachers_data = [
        [
            'id' => 1,
            'name' => 'Ms. Sarah Johnson',
            'email' => 'sarah.johnson@school.edu',
            'course_name' => 'Mathematics',
            'course_id' => 1,
            'role' => 'teacher',
            'profile_url' => '#',
            'message_url' => '#',
            'avatar' => '0'
        ],
        [
            'id' => 2,
            'name' => 'Mr. David Chen',
            'email' => 'david.chen@school.edu',
            'course_name' => 'Science',
            'course_id' => 2,
            'role' => 'teacher',
            'profile_url' => '#',
            'message_url' => '#',
            'avatar' => '0'
        ],
        [
            'id' => 3,
            'name' => 'Ms. Emily Rodriguez',
            'email' => 'emily.rodriguez@school.edu',
            'course_name' => 'English Language Arts',
            'course_id' => 3,
            'role' => 'teacher',
            'profile_url' => '#',
            'message_url' => '#',
            'avatar' => '0'
        ],
        [
            'id' => 4,
            'name' => 'Mr. Michael Thompson',
            'email' => 'michael.thompson@school.edu',
            'course_name' => 'Social Studies',
            'course_id' => 4,
            'role' => 'teacher',
            'profile_url' => '#',
            'message_url' => '#',
            'avatar' => '0'
        ]
    ];
    
    $total_teachers = count($teachers_data);
}

// Prepare template context for the Ask Teacher page
$templatecontext = [
    'custom_ask_teacher' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'teachers_data' => $teachers_data,
    'has_teachers' => !empty($teachers_data),
    'total_teachers' => $total_teachers,
    
    // Page identification flags for sidebar
    'is_ask_teacher_page' => true,
    'is_dashboard_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'mycoursesurl' => new moodle_url('/theme/remui_kids/moodle_mycourses.php'),
    'dashboardurl' => new moodle_url('/my/'),
    'assignmentsurl' => new moodle_url('/mod/assign/index.php'),
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
    'currentpage' => [
        'ask_teacher' => true
    ]
];

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/ask_teacher_page', $templatecontext);
echo $OUTPUT->footer();
