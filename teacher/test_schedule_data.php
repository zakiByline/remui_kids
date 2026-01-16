<?php
/**
 * Test what data is being sent to template
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

header('Content-Type: text/html; charset=utf-8');

echo "<h2>🔍 Schedule Data Test</h2>";
echo "<p>This shows exactly what data the dashboard is getting.</p>";
echo "<hr>";

// Test the functions directly
echo "<h3>1. Testing theme_remui_kids_get_teacher_schedule(0)</h3>";
$schedule = theme_remui_kids_get_teacher_schedule(0);

echo "<pre>";
echo "Result:\n";
print_r($schedule);
echo "</pre>";

if (empty($schedule)) {
    echo "<p class='alert alert-danger'>❌ Function returned EMPTY</p>";
} else if (isset($schedule['days']) && !empty($schedule['days'])) {
    echo "<p class='alert alert-success'>✅ Function returned data with " . count($schedule['days']) . " days</p>";
    
    $has_events = false;
    foreach ($schedule['days'] as $day) {
        if (!empty($day['events'])) {
            $has_events = true;
            break;
        }
    }
    
    if ($has_events) {
        echo "<p class='alert alert-success'>✅ Days contain events</p>";
    } else {
        echo "<p class='alert alert-warning'>⚠️ Days exist but all are empty (no events)</p>";
    }
} else {
    echo "<p class='alert alert-warning'>⚠️ Function returned data but 'days' is empty</p>";
}

echo "<hr>";
echo "<h3>2. Testing theme_remui_kids_get_teacher_upcoming_sessions(4)</h3>";
$sessions = theme_remui_kids_get_teacher_upcoming_sessions(4);

echo "<pre>";
echo "Result:\n";
print_r($sessions);
echo "</pre>";

if (empty($sessions)) {
    echo "<p class='alert alert-danger'>❌ Function returned EMPTY</p>";
} else {
    echo "<p class='alert alert-success'>✅ Function returned " . count($sessions) . " session(s)</p>";
}

echo "<hr>";
echo "<h3>3. Testing Mustache Template Variables</h3>";

// Simulate what the template gets
$templatecontext = [];
$templatecontext['teacher_schedule'] = $schedule;
$templatecontext['teacher_upcoming_sessions'] = $sessions;

echo "<h4>Template will receive:</h4>";
echo "<pre>";
print_r($templatecontext);
echo "</pre>";

echo "<h4>Mustache Conditionals:</h4>";
if (!empty($templatecontext['teacher_schedule'])) {
    echo "<p>✅ {{#teacher_schedule}} will be TRUE (section will show)</p>";
} else {
    echo "<p>❌ {{#teacher_schedule}} will be FALSE (section will be hidden!)</p>";
}

if (!empty($templatecontext['teacher_upcoming_sessions'])) {
    echo "<p>✅ {{#teacher_upcoming_sessions}} will be TRUE (section will show)</p>";
} else {
    echo "<p>❌ {{#teacher_upcoming_sessions}} will be FALSE (section will be hidden!)</p>";
}

echo "<hr>";
echo "<h3>4. Direct Database Check</h3>";

// Check events directly
$start = strtotime("monday this week");
$end = $start + (14 * 24 * 60 * 60); // 2 weeks

$events = $DB->get_records_sql(
    "SELECT id, name, eventtype, courseid, timestart, userid
     FROM {event}
     WHERE timestart >= :start
     AND timestart < :end
     AND visible = 1
     ORDER BY timestart",
    ['start' => $start, 'end' => $end]
);

echo "<p>Events in database (next 2 weeks): " . count($events) . "</p>";
if (!empty($events)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Course ID</th><th>User ID</th><th>Date</th></tr>";
    foreach ($events as $e) {
        echo "<tr>";
        echo "<td>{$e->id}</td>";
        echo "<td>{$e->name}</td>";
        echo "<td>{$e->eventtype}</td>";
        echo "<td>{$e->courseid}</td>";
        echo "<td>{$e->userid}</td>";
        echo "<td>" . date('D M j, Y H:i', $e->timestart) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h3>5. Check Error Logs</h3>";
echo "<p>Check your PHP error log for messages starting with 'Teacher Schedule:'</p>";
echo "<p>You should see lines like:</p>";
echo "<pre>";
echo "Teacher Schedule: Found X events for week offset 0\n";
echo "Teacher Schedule: Week range from YYYY-MM-DD to YYYY-MM-DD\n";
if (empty($schedule['days'][0]['events'] ?? [])) {
    echo "Teacher Schedule: Current week is empty, checking next week...\n";
}
echo "</pre>";

echo "<hr>";
echo "<h3>Actions</h3>";
echo "<a href='/my/' class='btn btn-primary'>Go to Dashboard</a> ";
echo "<a href='check_event_match.php' class='btn btn-info'>Check Event Match</a> ";
echo "<a href='create_sample_events.php' class='btn btn-success'>Create Sample Events</a>";

