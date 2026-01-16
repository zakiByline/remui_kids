<?php
/**
 * Check if events match teacher's courses
 */

require_once('../../../config.php');
require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/check_event_match.php');
$PAGE->set_title('Event Matching Check');

echo $OUTPUT->header();

echo "<h2>🔍 Event & Teacher Course Matching</h2>";

// Get teacher's courses
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
$roleids = array_keys($teacherroles);

echo "<h3>Step 1: Your Teacher Courses</h3>";

if (empty($roleids)) {
    echo "<p class='alert alert-danger'>❌ No teacher roles found in system</p>";
} else {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
         FROM {course} c
         JOIN {context} ctx ON c.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
         JOIN {role_assignments} ra ON ctx.id = ra.contextid AND ra.userid = :userid AND ra.roleid {$insql}
         WHERE c.id > 1
         ORDER BY c.fullname",
        $params
    );
    
    if (empty($teacher_courses)) {
        echo "<p class='alert alert-danger'>❌ You are not a teacher in any courses</p>";
    } else {
        echo "<p class='alert alert-success'>✅ You are a teacher in " . count($teacher_courses) . " course(s):</p>";
        echo "<ul>";
        foreach ($teacher_courses as $c) {
            echo "<li><strong>Course ID {$c->id}:</strong> {$c->fullname} ({$c->shortname})</li>";
        }
        echo "</ul>";
        
        $course_ids = array_keys($teacher_courses);
        
        // Get events this week
        echo "<h3>Step 2: Events This Week</h3>";
        
        $start_of_week = strtotime("monday this week");
        $end_of_week = $start_of_week + (7 * 24 * 60 * 60);
        
        // Also check next week
        $next_week_start = $end_of_week;
        $next_week_end = $next_week_start + (7 * 24 * 60 * 60);
        
        echo "<p>📅 Checking THIS week: " . date('Y-m-d', $start_of_week) . " to " . date('Y-m-d', $end_of_week) . "</p>";
        echo "<p>📅 Also checking NEXT week: " . date('Y-m-d', $next_week_start) . " to " . date('Y-m-d', $next_week_end) . "</p>";
        
        $all_events = $DB->get_records_sql(
            "SELECT e.*, c.fullname as coursename
             FROM {event} e
             LEFT JOIN {course} c ON e.courseid = c.id
             WHERE e.timestart >= :start
             AND e.timestart < :end
             AND e.visible = 1
             ORDER BY e.timestart",
            ['start' => $start_of_week, 'end' => $next_week_end]
        );
        
        if (empty($all_events)) {
            echo "<p class='alert alert-danger'>❌ No visible events found in this week OR next week</p>";
        } else {
            $this_week_events = array_filter($all_events, function($e) use ($start_of_week, $end_of_week) {
                return $e->timestart >= $start_of_week && $e->timestart < $end_of_week;
            });
            $next_week_events = array_filter($all_events, function($e) use ($next_week_start, $next_week_end) {
                return $e->timestart >= $next_week_start && $e->timestart < $next_week_end;
            });
            
            echo "<p class='alert alert-info'>Found " . count($all_events) . " total events: " . count($this_week_events) . " this week, " . count($next_week_events) . " next week</p>";
            
            if (empty($this_week_events) && !empty($next_week_events)) {
                echo "<div class='alert alert-warning'>";
                echo "<h4>⚠️ Events are in NEXT week, not this week!</h4>";
                echo "<p>The schedule will automatically show next week's events if current week is empty.</p>";
                echo "</div>";
            }
            
            echo "<table class='table table-bordered'>";
            echo "<tr><th>Event Name</th><th>Type</th><th>Course ID</th><th>Course Name</th><th>User ID</th><th>Date</th><th>✓ Match?</th></tr>";
            
            $matched = 0;
            
            foreach ($all_events as $e) {
                $is_match = false;
                $reason = '';
                
                // Check if event matches teacher's criteria
                if ($e->eventtype == 'site' || in_array($e->eventtype, ['open','close','due','expectcompletionon'])) {
                    $is_match = true;
                    $reason = "Site/System event";
                } else if ($e->eventtype == 'user' && $e->userid == $USER->id) {
                    $is_match = true;
                    $reason = "Your personal event";
                } else if (($e->eventtype == 'course' || $e->eventtype == 'group') && in_array($e->courseid, $course_ids)) {
                    $is_match = true;
                    $reason = "Event in your teacher course";
                } else {
                    $reason = "NOT in your teacher courses";
                }
                
                if ($is_match) $matched++;
                
                $row_class = $is_match ? "table-success" : "table-danger";
                $check = $is_match ? "✅" : "❌";
                
                echo "<tr class='$row_class'>";
                echo "<td>" . htmlspecialchars($e->name) . "</td>";
                echo "<td>{$e->eventtype}</td>";
                echo "<td>{$e->courseid}</td>";
                echo "<td>" . ($e->coursename ?? 'N/A') . "</td>";
                echo "<td>{$e->userid}</td>";
                echo "<td>" . date('D M j, H:i', $e->timestart) . "</td>";
                echo "<td>$check $reason</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            if ($matched == 0) {
                echo "<div class='alert alert-danger'>";
                echo "<h4>❌ PROBLEM FOUND: No events match your teacher courses!</h4>";
                echo "<p><strong>The 3 events exist, but they are NOT linked to courses where you are a teacher.</strong></p>";
                echo "<h5>Solutions:</h5>";
                echo "<ol>";
                echo "<li><strong>Option A:</strong> Create new events in your teacher courses</li>";
                echo "<li><strong>Option B:</strong> Add yourself as teacher to courses that have events (Course IDs shown above)</li>";
                echo "<li><strong>Option C:</strong> Use our tool: <a href='create_sample_events.php'>Create Sample Events</a> - it will create events in YOUR courses</li>";
                echo "</ol>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-success'>";
                echo "<h4>✅ Found $matched matching event(s)!</h4>";
                echo "<p>These events should appear in your schedule. If not, try:</p>";
                echo "<ol>";
                echo "<li>Clear cache: Admin → Development → Purge all caches</li>";
                echo "<li>Refresh dashboard: <a href='/my/'>Go to /my/</a></li>";
                echo "<li>Check browser console for JavaScript errors</li>";
                echo "</ol>";
                echo "</div>";
            }
        }
    }
}

echo "<hr>";
echo "<h3>Quick Actions</h3>";
echo "<a href='create_sample_events.php' class='btn btn-primary'>Create Sample Events in MY Courses</a> ";
echo "<a href='debug_schedule.php' class='btn btn-info'>Run Full Diagnostic</a> ";
echo "<a href='/my/' class='btn btn-success'>Go to Dashboard</a>";

echo $OUTPUT->footer();


