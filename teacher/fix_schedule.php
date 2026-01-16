<?php
require_once('../../../config.php');
require_login();

$PAGE->set_context(context_system::instance());

echo $OUTPUT->header();
echo "<h2>🔧 Schedule Fix Tool</h2>";

// Get teacher's courses
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
$roleids = array_keys($teacherroles);

echo "<h3>Your Teacher Courses:</h3>";

if (empty($roleids)) {
    echo "<p class='alert alert-danger'>❌ No teacher roles found</p>";
} else {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname
         FROM {course} c
         JOIN {context} ctx ON c.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
         JOIN {role_assignments} ra ON ctx.id = ra.contextid AND ra.userid = :userid AND ra.roleid {$insql}
         WHERE c.id > 1",
        $params
    );
    
    if (empty($teacher_courses)) {
        echo "<p class='alert alert-danger'>❌ You are NOT a teacher in ANY courses!</p>";
        echo "<p><strong>Solution:</strong> You must be assigned as teacher in at least one course.</p>";
    } else {
        echo "<p class='alert alert-success'>✅ You are teacher in " . count($teacher_courses) . " course(s):</p>";
        echo "<ul>";
        $course_ids = [];
        foreach ($teacher_courses as $c) {
            echo "<li>Course ID {$c->id}: {$c->fullname}</li>";
            $course_ids[] = $c->id;
        }
        echo "</ul>";
        
        echo "<hr>";
        echo "<h3>Events Check:</h3>";
        
        // Check if course 45 is in teacher courses
        if (in_array(45, $course_ids)) {
            echo "<p class='alert alert-success'>✅ You ARE a teacher in Course 45 (where events exist)</p>";
        } else {
            echo "<div class='alert alert-danger'>";
            echo "<h4>❌ PROBLEM FOUND: You are NOT a teacher in Course ID 45</h4>";
            echo "<p>The 2 events in the database are in Course 45, but you're not a teacher there.</p>";
            echo "<h5>Solutions:</h5>";
            echo "<ol>";
            echo "<li><strong>Add yourself as teacher to Course 45:</strong>";
            echo "<ul><li>Go to Course ID 45</li>";
            echo "<li>Participants → Enrol users</li>";
            echo "<li>Add yourself with 'Teacher' role</li></ul></li>";
            echo "<li><strong>OR Create events in YOUR courses:</strong>";
            echo "<ul><li><a href='create_sample_events.php' class='btn btn-primary'>Use Sample Event Creator</a></li>";
            echo "<li>It will create events in one of your teacher courses</li></ul></li>";
            echo "</ol>";
            echo "</div>";
        }
    }
}

echo "<hr>";
echo "<h3>Quick Fix Options:</h3>";
echo "<div class='btn-group' style='gap: 10px; display: flex; flex-wrap: wrap;'>";
echo "<a href='create_sample_events.php' class='btn btn-primary'>➕ Create Events in MY Courses</a>";
echo "<a href='/course/view.php?id=45' class='btn btn-info'>📚 Go to Course 45</a>";
echo "<a href='test_schedule_data.php' class='btn btn-secondary'>🔍 Re-test Data</a>";
echo "<a href='/my/' class='btn btn-success'>🏠 Dashboard</a>";
echo "</div>";

echo $OUTPUT->footer();

