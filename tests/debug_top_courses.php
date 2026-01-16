<?php
// Debug script to test top courses data
require_once('../../../config.php');
require_login();

// Check if user is teacher
$isteacher = false;
$context = context_system::instance();

// Check for teacher roles in any course context
$teacherroles = $DB->get_records_sql(
    "SELECT DISTINCT r.shortname 
     FROM {role} r 
     JOIN {role_assignments} ra ON r.id = ra.roleid 
     JOIN {context} ctx ON ra.contextid = ctx.id 
     WHERE ra.userid = ? 
     AND ctx.contextlevel = ? 
     AND r.shortname IN ('editingteacher', 'teacher')",
    [$USER->id, CONTEXT_COURSE]
);

if (!empty($teacherroles)) {
    $isteacher = true;
}

// Also check for teacher capabilities in system context
if (!$isteacher && (has_capability('moodle/course:create', $context, $USER) || 
                   has_capability('moodle/course:manageactivities', $context, $USER))) {
    $isteacher = true;
}

echo "<h2>Debug Top Courses Data</h2>";
echo "<p>User ID: " . $USER->id . "</p>";
echo "<p>Is Teacher: " . ($isteacher ? 'Yes' : 'No') . "</p>";

if ($isteacher) {
    echo "<h3>Top Courses Data:</h3>";
    $top_courses = theme_remui_kids_get_top_courses_by_enrollment(5);
    echo "<pre>" . print_r($top_courses, true) . "</pre>";
    
    echo "<h3>Teacher Courses:</h3>";
    $teacher_courses = theme_remui_kids_get_teacher_courses();
    echo "<pre>" . print_r($teacher_courses, true) . "</pre>";
} else {
    echo "<p>User is not a teacher, so no top courses data available.</p>";
}
?>






