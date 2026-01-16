<?php
/**
 * Elementary Schedule Page - DEPRECATED
 * This file is deprecated. Please use elementary_calendar.php instead.
 * All users will be automatically redirected to the new calendar page.
 *
 * @package    theme_remui_kids
 * @deprecated Use elementary_calendar.php instead
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// REDIRECT TO NEW CALENDAR PAGE
redirect(new moodle_url('/theme/remui_kids/elementary_calendar.php'));

// Reuse schedule data logic from schedule.php
// Get enrolled courses
$courses = enrol_get_all_users_courses($USER->id, true);
$courseids = array_keys($courses);

$scheduleactivities = [];
$now = time();
$futuredate = strtotime('+30 days');

if (!empty($courseids)) {
    $courseids_sql = implode(',', $courseids);

    global $DB;
    // Assignments
    $assignments = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, a.course, a.intro, c.fullname coursename, cm.id cmid
         FROM {assign} a
         JOIN {course} c ON a.course = c.id
         JOIN {course_modules} cm ON cm.instance = a.id
         JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
         WHERE a.course IN ($courseids_sql) AND a.duedate > ? AND a.duedate <= ? AND cm.visible = 1 AND cm.deletioninprogress = 0
         ORDER BY a.duedate ASC",
        [$now, $futuredate]
    );
    foreach ($assignments as $a) {
        $scheduleactivities[] = [
            'type' => 'assignment',
            'icon' => 'fa-file-text',
            'name' => $a->name,
            'coursename' => $a->coursename,
            'date' => $a->duedate,
            'dateformatted' => userdate($a->duedate, '%A, %d %B %Y'),
            'timeformatted' => userdate($a->duedate, '%I:%M %p'),
            'dayname' => userdate($a->duedate, '%A'),
            'daynum' => userdate($a->duedate, '%d'),
            'monthname' => userdate($a->duedate, '%B'),
            'url' => (new moodle_url('/mod/assign/view.php', ['id' => $a->cmid]))->out(),
            'description' => strip_tags($a->intro),
        ];
    }

    // Quizzes
    $quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, q.timeclose, q.course, q.intro, c.fullname coursename, cm.id cmid
         FROM {quiz} q
         JOIN {course} c ON q.course = c.id
         JOIN {course_modules} cm ON cm.instance = q.id
         JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
         WHERE q.course IN ($courseids_sql) AND q.timeclose > ? AND q.timeclose <= ? AND cm.visible = 1 AND cm.deletioninprogress = 0
         ORDER BY q.timeclose ASC",
        [$now, $futuredate]
    );
    foreach ($quizzes as $q) {
        $scheduleactivities[] = [
            'type' => 'quiz',
            'icon' => 'fa-question-circle',
            'name' => $q->name,
            'coursename' => $q->coursename,
            'date' => $q->timeclose,
            'dateformatted' => userdate($q->timeclose, '%A, %d %B %Y'),
            'timeformatted' => userdate($q->timeclose, '%I:%M %p'),
            'dayname' => userdate($q->timeclose, '%A'),
            'daynum' => userdate($q->timeclose, '%d'),
            'monthname' => userdate($q->timeclose, '%B'),
            'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $q->cmid]))->out(),
            'description' => strip_tags($q->intro),
        ];
    }
}

usort($scheduleactivities, function($a, $b) { return $a['date'] - $b['date']; });

$grouped = [];
foreach ($scheduleactivities as $act) {
    $key = date('Y-m-d', $act['date']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'date' => $act['date'],
            'dateformatted' => $act['dateformatted'],
            'dayname' => $act['dayname'],
            'daynum' => $act['daynum'],
            'monthname' => $act['monthname'],
            'activities' => []
        ];
    }
    $grouped[$key]['activities'][] = $act;
}

$scheduledata = array_values($grouped);

$templatecontext = [
    'custom_schedule' => true,
    'is_elementary_only' => true,
    'schedule_data' => $scheduledata,
    'has_activities' => !empty($scheduleactivities),
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_schedule.php'))->out(),
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
];

echo $OUTPUT->render_from_template('theme_remui_kids/schedule_page', $templatecontext);



