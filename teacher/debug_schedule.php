<?php
/**
 * Debug Teacher Schedule Data
 * 
 * This page helps diagnose why schedule data is not showing up
 * 
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/debug_schedule.php');
$PAGE->set_title('Debug Teacher Schedule');
$PAGE->set_heading('Debug Teacher Schedule');

echo $OUTPUT->header();

echo html_writer::start_div('container-fluid', ['style' => 'max-width: 1200px;']);
echo html_writer::tag('h2', '🔍 Teacher Schedule Debugging Tool');

// Check 1: Is user a teacher?
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-header bg-primary text-white');
echo html_writer::tag('h4', 'Step 1: Checking Teacher Role', ['class' => 'm-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');

$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}",
        $params
    );
    
    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
    echo html_writer::tag('p', '✅ You are a site administrator', ['class' => 'alert alert-success']);
}

if ($isteacher) {
    echo html_writer::tag('p', '✅ You have teacher role assigned', ['class' => 'alert alert-success']);
    echo html_writer::tag('p', 'Teacher roles found: ' . implode(', ', array_column($teacherroles, 'shortname')));
} else {
    echo html_writer::tag('p', '❌ You do not have teacher role. You need editingteacher or teacher role to see schedule.', ['class' => 'alert alert-danger']);
}

echo html_writer::end_div();
echo html_writer::end_div();

// Check 2: Get teacher's courses
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-header bg-info text-white');
echo html_writer::tag('h4', 'Step 2: Checking Teacher\'s Courses', ['class' => 'm-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');

if ($isteacher) {
    $courseids = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid, c.fullname
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         JOIN {course} c ON c.id = ctx.instanceid
         WHERE ra.userid = :userid 
         AND ctx.contextlevel = :ctxlevel 
         AND ra.roleid {$insql}",
        $params
    );
    
    if (!empty($courseids)) {
        echo html_writer::tag('p', '✅ Found ' . count($courseids) . ' course(s) where you are a teacher:', ['class' => 'alert alert-success']);
        echo html_writer::start_tag('ul');
        foreach ($courseids as $c) {
            echo html_writer::tag('li', "Course ID: {$c->courseid} - {$c->fullname}");
        }
        echo html_writer::end_tag('ul');
    } else {
        echo html_writer::tag('p', '❌ No courses found where you are a teacher. Please enroll as a teacher in at least one course.', ['class' => 'alert alert-danger']);
    }
} else {
    echo html_writer::tag('p', 'Skipped - You are not a teacher', ['class' => 'alert alert-warning']);
}

echo html_writer::end_div();
echo html_writer::end_div();

// Check 3: Calendar events
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-header bg-success text-white');
echo html_writer::tag('h4', 'Step 3: Checking Calendar Events', ['class' => 'm-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');

if ($isteacher && !empty($courseids)) {
    $ids = array_keys($courseids);
    list($coursesql, $courseparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
    
    // Get this week's date range
    $start_of_week = strtotime("monday this week");
    $end_of_week = $start_of_week + (7 * 24 * 60 * 60);
    
    echo html_writer::tag('p', '📅 Checking events from ' . date('Y-m-d', $start_of_week) . ' to ' . date('Y-m-d', $end_of_week));
    
    // Check all events
    $sql = "SELECT e.*, c.fullname as coursename
            FROM {event} e
            LEFT JOIN {course} c ON e.courseid = c.id
            WHERE e.timestart >= :starttime
            AND e.timestart < :endtime
            AND e.visible = 1
            AND (e.eventtype IN ('site', 'open', 'close', 'due', 'expectcompletionon')
                 OR (e.eventtype = 'user' AND e.userid = :userid)
                 OR (e.eventtype = 'course' AND e.courseid {$coursesql})
                 OR (e.eventtype = 'group' AND e.courseid {$coursesql}))
            ORDER BY e.timestart ASC";
    
    $params = array_merge([
        'starttime' => $start_of_week,
        'endtime' => $end_of_week,
        'userid' => $USER->id
    ], $courseparams);
    
    $events = $DB->get_records_sql($sql, $params);
    
    if (!empty($events)) {
        echo html_writer::tag('p', '✅ Found ' . count($events) . ' event(s) this week:', ['class' => 'alert alert-success']);
        echo html_writer::start_tag('table', ['class' => 'table table-striped']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Event Name');
        echo html_writer::tag('th', 'Date/Time');
        echo html_writer::tag('th', 'Type');
        echo html_writer::tag('th', 'Course');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($events as $event) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', $event->name);
            echo html_writer::tag('td', date('D, M j, Y H:i', $event->timestart));
            echo html_writer::tag('td', $event->eventtype);
            echo html_writer::tag('td', $event->coursename ?? 'N/A');
            echo html_writer::end_tag('tr');
        }
        
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::tag('p', '❌ No calendar events found for this week. This is why you see "No schedule data available".', ['class' => 'alert alert-danger']);
        echo html_writer::start_div('alert alert-info');
        echo html_writer::tag('h5', '💡 How to fix this:');
        echo html_writer::start_tag('ol');
        echo html_writer::tag('li', 'Create calendar events manually via Moodle Calendar');
        echo html_writer::tag('li', 'Add assignments with due dates');
        echo html_writer::tag('li', 'Add quizzes with open/close times');
        echo html_writer::tag('li', html_writer::link(
            new moodle_url('/theme/remui_kids/teacher/create_sample_events.php'),
            'Use our Sample Event Creator Tool'
        ));
        echo html_writer::end_tag('ol');
        echo html_writer::end_div();
    }
} else {
    echo html_writer::tag('p', 'Skipped - Prerequisites not met', ['class' => 'alert alert-warning']);
}

echo html_writer::end_div();
echo html_writer::end_div();

// Check 4: Test the function directly
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-header bg-warning text-dark');
echo html_writer::tag('h4', 'Step 4: Testing Schedule Function', ['class' => 'm-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');

if ($isteacher) {
    $schedule = theme_remui_kids_get_teacher_schedule(0);
    
    if (!empty($schedule) && !empty($schedule['days'])) {
        echo html_writer::tag('p', '✅ Schedule function returned data successfully!', ['class' => 'alert alert-success']);
        echo html_writer::tag('pre', print_r($schedule, true), ['style' => 'background: #f5f5f5; padding: 15px; border-radius: 5px;']);
    } else {
        echo html_writer::tag('p', '❌ Schedule function returned empty data', ['class' => 'alert alert-danger']);
    }
    
    // Test upcoming sessions
    $sessions = theme_remui_kids_get_teacher_upcoming_sessions(4);
    
    if (!empty($sessions)) {
        echo html_writer::tag('p', '✅ Found ' . count($sessions) . ' upcoming session(s):', ['class' => 'alert alert-success']);
        echo html_writer::tag('pre', print_r($sessions, true), ['style' => 'background: #f5f5f5; padding: 15px; border-radius: 5px;']);
    } else {
        echo html_writer::tag('p', '❌ No upcoming sessions found in next 30 days', ['class' => 'alert alert-danger']);
    }
} else {
    echo html_writer::tag('p', 'Skipped - You are not a teacher', ['class' => 'alert alert-warning']);
}

echo html_writer::end_div();
echo html_writer::end_div();

// Action buttons
echo html_writer::start_div('card');
echo html_writer::start_div('card-header bg-dark text-white');
echo html_writer::tag('h4', 'Next Steps', ['class' => 'm-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');

echo html_writer::tag('h5', 'Quick Actions:');
echo html_writer::start_div('btn-group', ['role' => 'group', 'style' => 'gap: 10px; display: flex; flex-wrap: wrap;']);

echo html_writer::link(
    new moodle_url('/theme/remui_kids/teacher/create_sample_events.php'),
    '➕ Create Sample Events',
    ['class' => 'btn btn-primary']
);

echo html_writer::link(
    new moodle_url('/calendar/view.php?view=month'),
    '📅 View Calendar',
    ['class' => 'btn btn-info']
);

echo html_writer::link(
    new moodle_url('/calendar/managesubscriptions.php'),
    '⚙️ Manage Calendar',
    ['class' => 'btn btn-secondary']
);

echo html_writer::link(
    new moodle_url('/my/'),
    '🏠 Go to Dashboard',
    ['class' => 'btn btn-success']
);

echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // container

echo $OUTPUT->footer();


