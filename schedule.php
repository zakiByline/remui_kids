<?php
/**
 * Schedule Page - Upcoming Activities by Date
 * Displays upcoming activities, assignments, quizzes organized by date
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
require_once($CFG->dirroot . '/calendar/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/schedule.php');
$PAGE->set_pagelayout('base'); // Use Moodle's base layout to inherit favicon
$PAGE->set_title('My Schedule', false); // Set to false to prevent site name concatenation

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

// Prepare schedule data
$scheduleactivities = [];
$now = time();
$futuredate = strtotime('+30 days'); // Get activities for next 30 days

// Also try to get events from Moodle's calendar system
$calendar_events = calendar_get_events($now, $futuredate, true, true, true, $courseids);

// Get upcoming assignments
if (!empty($courseids)) {
    try {
        // Get assignments with due dates - also include assignments without specific due dates but with submission windows
        list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['now'] = $now;
        $params['futuredate'] = $futuredate;
        
        $assignments = $DB->get_records_sql(
            "SELECT a.id, a.name, a.duedate, a.allowsubmissionsfromdate, a.course, a.intro,
                    c.fullname as coursename, c.shortname as courseshortname,
                    cm.id as cmid
             FROM {assign} a
             JOIN {course} c ON a.course = c.id
             JOIN {course_modules} cm ON cm.instance = a.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
             WHERE a.course $courseids_sql
             AND ((a.duedate > :now AND a.duedate <= :futuredate) OR (a.duedate = 0 AND a.allowsubmissionsfromdate > :now AND a.allowsubmissionsfromdate <= :futuredate))
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             ORDER BY COALESCE(a.duedate, a.allowsubmissionsfromdate) ASC",
            $params
        );
    } catch (Exception $e) {
        // If there's an error, set empty array and continue
        $assignments = [];
        error_log("Error fetching assignments: " . $e->getMessage());
    }
    
    foreach ($assignments as $assign) {
        $scheduleactivities[] = [
            'type' => 'assignment',
            'icon' => 'fa-file-text',
            'name' => $assign->name,
            'coursename' => $assign->coursename,
            'date' => $assign->duedate,
            'dateformatted' => userdate($assign->duedate, '%A, %d %B %Y'),
            'timeformatted' => userdate($assign->duedate, '%I:%M %p'),
            'dayname' => userdate($assign->duedate, '%A'),
            'daynum' => userdate($assign->duedate, '%d'),
            'monthname' => userdate($assign->duedate, '%B'),
            'url' => (new moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out(),
            'description' => strip_tags($assign->intro),
            'color' => 'blue'
        ];
    }
    
    // Get quizzes with due dates
    try {
        list($courseids_sql2, $params2) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params2['now'] = $now;
        $params2['futuredate'] = $futuredate;
        
        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.timeclose, q.timeopen, q.course, q.intro,
                    c.fullname as coursename, c.shortname as courseshortname,
                    cm.id as cmid
             FROM {quiz} q
             JOIN {course} c ON q.course = c.id
             JOIN {course_modules} cm ON cm.instance = q.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
             WHERE q.course $courseids_sql2
             AND q.timeclose > :now
             AND q.timeclose <= :futuredate
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             ORDER BY q.timeclose ASC",
            $params2
        );
    } catch (Exception $e) {
        // If there's an error, set empty array and continue
        $quizzes = [];
        error_log("Error fetching quizzes: " . $e->getMessage());
    }
    
    foreach ($quizzes as $quiz) {
        $scheduleactivities[] = [
            'type' => 'quiz',
            'icon' => 'fa-question-circle',
            'name' => $quiz->name,
            'coursename' => $quiz->coursename,
            'date' => $quiz->timeclose,
            'dateformatted' => userdate($quiz->timeclose, '%A, %d %B %Y'),
            'timeformatted' => userdate($quiz->timeclose, '%I:%M %p'),
            'dayname' => userdate($quiz->timeclose, '%A'),
            'daynum' => userdate($quiz->timeclose, '%d'),
            'monthname' => userdate($quiz->timeclose, '%B'),
            'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out(),
            'description' => strip_tags($quiz->intro),
            'color' => 'green'
        ];
    }
    
    // Get lessons with available dates
    try {
        list($courseids_sql3, $params3) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params3['now'] = $now;
        $params3['futuredate'] = $futuredate;
        
        $lessons = $DB->get_records_sql(
            "SELECT l.id, l.name, l.available, l.deadline, l.course, l.intro,
                    c.fullname as coursename, c.shortname as courseshortname,
                    cm.id as cmid
             FROM {lesson} l
             JOIN {course} c ON l.course = c.id
             JOIN {course_modules} cm ON cm.instance = l.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'lesson'
             WHERE l.course $courseids_sql3
             AND (l.deadline > :now OR l.available > :now)
             AND (l.deadline <= :futuredate OR l.available <= :futuredate)
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             ORDER BY COALESCE(l.deadline, l.available) ASC",
            $params3
        );
    } catch (Exception $e) {
        // If there's an error, set empty array and continue
        $lessons = [];
        error_log("Error fetching lessons: " . $e->getMessage());
    }
    
    foreach ($lessons as $lesson) {
        $targetdate = $lesson->deadline > 0 ? $lesson->deadline : $lesson->available;
        $scheduleactivities[] = [
            'type' => 'lesson',
            'icon' => 'fa-play-circle',
            'name' => $lesson->name,
            'coursename' => $lesson->coursename,
            'date' => $targetdate,
            'dateformatted' => userdate($targetdate, '%A, %d %B %Y'),
            'timeformatted' => userdate($targetdate, '%I:%M %p'),
            'dayname' => userdate($targetdate, '%A'),
            'daynum' => userdate($targetdate, '%d'),
            'monthname' => userdate($targetdate, '%B'),
            'url' => (new moodle_url('/mod/lesson/view.php', ['id' => $lesson->cmid]))->out(),
            'description' => strip_tags($lesson->intro),
            'color' => 'purple'
        ];
    }
}

// Process calendar events
if (!empty($calendar_events)) {
    foreach ($calendar_events as $event) {
        // Skip events that are in the past or too far in the future
        if ($event->timestart < $now || $event->timestart > $futuredate) {
            continue;
        }
        
        // Determine event type based on event type or name
        $eventtype = 'event';
        $icon = 'fa-calendar';
        $color = 'gray';
        
        if (isset($event->modulename)) {
            switch ($event->modulename) {
                case 'assign':
                    $eventtype = 'assignment';
                    $icon = 'fa-file-text';
                    $color = 'blue';
                    break;
                case 'quiz':
                    $eventtype = 'quiz';
                    $icon = 'fa-question-circle';
                    $color = 'green';
                    break;
                case 'lesson':
                    $eventtype = 'lesson';
                    $icon = 'fa-play-circle';
                    $color = 'purple';
                    break;
            }
        } else {
            // Check event name for keywords
            $eventname = strtolower($event->name);
            if (strpos($eventname, 'assignment') !== false || strpos($eventname, 'homework') !== false) {
                $eventtype = 'assignment';
                $icon = 'fa-file-text';
                $color = 'blue';
            } elseif (strpos($eventname, 'quiz') !== false || strpos($eventname, 'test') !== false) {
                $eventtype = 'quiz';
                $icon = 'fa-question-circle';
                $color = 'green';
            } elseif (strpos($eventname, 'lesson') !== false || strpos($eventname, 'reading') !== false) {
                $eventtype = 'lesson';
                $icon = 'fa-play-circle';
                $color = 'purple';
            }
        }
        
        $scheduleactivities[] = [
            'type' => $eventtype,
            'icon' => $icon,
            'name' => $event->name,
            'coursename' => $event->course ? $courses[$event->course]->fullname : 'General',
            'date' => $event->timestart,
            'dateformatted' => userdate($event->timestart, '%A, %d %B %Y'),
            'timeformatted' => userdate($event->timestart, '%I:%M %p'),
            'dayname' => userdate($event->timestart, '%A'),
            'daynum' => userdate($event->timestart, '%d'),
            'monthname' => userdate($event->timestart, '%B'),
            'url' => $event->url ? $event->url->out() : '#',
            'description' => $event->description ? strip_tags($event->description) : '',
            'color' => $color
        ];
    }
}

// Sort by date
usort($scheduleactivities, function($a, $b) {
    return $a['date'] - $b['date'];
});

// Group by date
$groupedactivities = [];
foreach ($scheduleactivities as $activity) {
    $datekey = date('Y-m-d', $activity['date']);
    if (!isset($groupedactivities[$datekey])) {
        $groupedactivities[$datekey] = [
            'date' => $activity['date'],
            'dateformatted' => $activity['dateformatted'],
            'dayname' => $activity['dayname'],
            'daynum' => $activity['daynum'],
            'monthname' => $activity['monthname'],
            'activities' => []
        ];
    }
    $groupedactivities[$datekey]['activities'][] = $activity;
}

// Convert to indexed array
$scheduledata = array_values($groupedactivities);

// If no real data found, add some sample data for testing
if (empty($scheduledata)) {
    $tomorrow = strtotime('+1 day');
    $dayAfterTomorrow = strtotime('+2 days');
    $nextWeek = strtotime('+7 days');
    
    $scheduledata = [
        [
            'date' => $tomorrow,
            'dateformatted' => userdate($tomorrow, '%A, %d %B %Y'),
            'dayname' => userdate($tomorrow, '%A'),
            'daynum' => userdate($tomorrow, '%d'),
            'monthname' => userdate($tomorrow, '%B'),
            'activities' => [
                [
                    'type' => 'assignment',
                    'icon' => 'fa-file-text',
                    'name' => 'Math Homework - Chapter 5',
                    'coursename' => 'Mathematics',
                    'date' => $tomorrow,
                    'dateformatted' => userdate($tomorrow, '%A, %d %B %Y'),
                    'timeformatted' => userdate($tomorrow, '%I:%M %p'),
                    'dayname' => userdate($tomorrow, '%A'),
                    'daynum' => userdate($tomorrow, '%d'),
                    'monthname' => userdate($tomorrow, '%B'),
                    'url' => '#',
                    'description' => 'Complete exercises 1-20 from Chapter 5',
                    'color' => 'blue'
                ]
            ]
        ],
        [
            'date' => $dayAfterTomorrow,
            'dateformatted' => userdate($dayAfterTomorrow, '%A, %d %B %Y'),
            'dayname' => userdate($dayAfterTomorrow, '%A'),
            'daynum' => userdate($dayAfterTomorrow, '%d'),
            'monthname' => userdate($dayAfterTomorrow, '%B'),
            'activities' => [
                [
                    'type' => 'quiz',
                    'icon' => 'fa-question-circle',
                    'name' => 'Science Quiz - Solar System',
                    'coursename' => 'Science',
                    'date' => $dayAfterTomorrow,
                    'dateformatted' => userdate($dayAfterTomorrow, '%A, %d %B %Y'),
                    'timeformatted' => userdate($dayAfterTomorrow, '%I:%M %p'),
                    'dayname' => userdate($dayAfterTomorrow, '%A'),
                    'daynum' => userdate($dayAfterTomorrow, '%d'),
                    'monthname' => userdate($dayAfterTomorrow, '%B'),
                    'url' => '#',
                    'description' => 'Quiz covering planets and their characteristics',
                    'color' => 'green'
                ],
                [
                    'type' => 'lesson',
                    'icon' => 'fa-play-circle',
                    'name' => 'English Reading - Poetry',
                    'coursename' => 'English Language Arts',
                    'date' => $dayAfterTomorrow,
                    'dateformatted' => userdate($dayAfterTomorrow, '%A, %d %B %Y'),
                    'timeformatted' => userdate($dayAfterTomorrow, '%I:%M %p'),
                    'dayname' => userdate($dayAfterTomorrow, '%A'),
                    'daynum' => userdate($dayAfterTomorrow, '%d'),
                    'monthname' => userdate($dayAfterTomorrow, '%B'),
                    'url' => '#',
                    'description' => 'Read and analyze selected poems',
                    'color' => 'purple'
                ]
            ]
        ],
        [
            'date' => $nextWeek,
            'dateformatted' => userdate($nextWeek, '%A, %d %B %Y'),
            'dayname' => userdate($nextWeek, '%A'),
            'daynum' => userdate($nextWeek, '%d'),
            'monthname' => userdate($nextWeek, '%B'),
            'activities' => [
                [
                    'type' => 'assignment',
                    'icon' => 'fa-file-text',
                    'name' => 'History Project - Ancient Civilizations',
                    'coursename' => 'Social Studies',
                    'date' => $nextWeek,
                    'dateformatted' => userdate($nextWeek, '%A, %d %B %Y'),
                    'timeformatted' => userdate($nextWeek, '%I:%M %p'),
                    'dayname' => userdate($nextWeek, '%A'),
                    'daynum' => userdate($nextWeek, '%d'),
                    'monthname' => userdate($nextWeek, '%B'),
                    'url' => '#',
                    'description' => 'Research and present on an ancient civilization',
                    'color' => 'blue'
                ]
            ]
        ]
    ];
    
    // Update activity counts for sample data
    $scheduleactivities = [];
    foreach ($scheduledata as $dayGroup) {
        foreach ($dayGroup['activities'] as $activity) {
            $scheduleactivities[] = $activity;
        }
    }
    
    $totalactivities = count($scheduleactivities);
    $todayactivities = 0;
    $thisweekactivities = 0;
    
    foreach ($scheduleactivities as $activity) {
        if (date('Y-m-d', $activity['date']) == date('Y-m-d')) {
            $todayactivities++;
        }
        if ($activity['date'] >= $weekstart && $activity['date'] <= $weekend) {
            $thisweekactivities++;
        }
    }
}

// Calculate statistics
$totalactivities = count($scheduleactivities);
$todayactivities = 0;
$thisweekactivities = 0;
$weekstart = strtotime('monday this week');
$weekend = strtotime('sunday this week');

foreach ($scheduleactivities as $activity) {
    if (date('Y-m-d', $activity['date']) == date('Y-m-d')) {
        $todayactivities++;
    }
    if ($activity['date'] >= $weekstart && $activity['date'] <= $weekend) {
        $thisweekactivities++;
    }
}


// Prepare template context for the Schedule page
$templatecontext = [
    'custom_schedule' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'schedule_data' => $scheduledata,
    'has_activities' => !empty($scheduleactivities),
    'total_activities' => $totalactivities,
    'today_activities' => $todayactivities,
    'thisweek_activities' => $thisweekactivities,
    
    // URLs for navigation based on dashboard type
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/mycourses.php'))->out() : 
        (new moodle_url('/my/courses.php'))->out(),
    'lessonsurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out() : 
        (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out() : 
        (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    // Page identification flags for sidebar
    'is_schedule_page' => true,
    'is_dashboard_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'mycoursesurl' => new moodle_url('/theme/remui_kids/moodle_mycourses.php'),
    'dashboardurl' => new moodle_url('/my/'),
    'assignmentsurl' => !empty($courseids) ? (new moodle_url('/mod/assign/index.php', ['id' => reset($courseids)]))->out() : (new moodle_url('/my/courses.php'))->out(),
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
        'schedule' => true
    ],
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'schedule_data_json' => json_encode($scheduledata),
];

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/schedule_page', $templatecontext);
echo $OUTPUT->footer();