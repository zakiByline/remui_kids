<?php
require_once('../../../config.php');

global $DB, $USER;

echo "=== UPDATING COURSE CARD DATA ===" . PHP_EOL;

// Get current user ID
$userid = $USER->id;
echo "Current User: " . fullname($USER) . " (ID: $userid)" . PHP_EOL . PHP_EOL;

// 1. Get the current course count for this user
$current_course_count = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id) 
     FROM {course} c 
     JOIN {enrol} e ON c.id = e.courseid 
     JOIN {user_enrolments} ue ON e.id = ue.enrolid 
     WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1",
    [$userid]
);

echo "Current Course Count for User $userid: $current_course_count" . PHP_EOL . PHP_EOL;

// 2. Get detailed course information
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname, c.visible, c.category
     FROM {course} c 
     JOIN {enrol} e ON c.id = e.courseid 
     JOIN {user_enrolments} ue ON e.id = ue.enrolid 
     WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1
     ORDER BY c.fullname",
    [$userid]
);

echo "=== ENROLLED COURSES ===" . PHP_EOL;
foreach ($courses as $course) {
    echo "ID: " . $course->id . " | Name: " . $course->fullname . " | Short: " . $course->shortname . PHP_EOL;
}

echo PHP_EOL . "=== COURSE CARD DATA ===" . PHP_EOL;
echo "The 'Courses' card should display: $current_course_count" . PHP_EOL . PHP_EOL;

// 3. If you want to change the displayed number, here are options:

echo "=== OPTIONS TO UPDATE THE CARD ===" . PHP_EOL;
echo "1. To show ALL courses in system (including not enrolled):" . PHP_EOL;
$total_system_courses = $DB->count_records_sql("SELECT COUNT(*) FROM {course} WHERE visible = 1 AND id > 1");
echo "   Total System Courses: $total_system_courses" . PHP_EOL . PHP_EOL;

echo "2. To show courses in a specific category:" . PHP_EOL;
$categories = $DB->get_records_sql("SELECT id, name FROM {course_categories} ORDER BY name");
foreach ($categories as $cat) {
    $cat_count = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id) 
         FROM {course} c 
         JOIN {enrol} e ON c.id = e.courseid 
         JOIN {user_enrolments} ue ON e.id = ue.enrolid 
         WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1 AND c.category = ?",
        [$userid, $cat->id]
    );
    if ($cat_count > 0) {
        echo "   Category '{$cat->name}': $cat_count courses" . PHP_EOL;
    }
}

echo PHP_EOL . "3. To manually set a specific number:" . PHP_EOL;
echo "   You can modify the theme function to return a specific number" . PHP_EOL . PHP_EOL;

// 4. Show the current dashboard data structure
echo "=== CURRENT DASHBOARD DATA STRUCTURE ===" . PHP_EOL;
$dashboard_data = [
    'total_courses' => $current_course_count,
    'lessons_completed' => 0, // Will be calculated by the theme function
    'activities_completed' => 0, // Will be calculated by the theme function
    'overall_progress' => 0 // Will be calculated by the theme function
];

echo json_encode($dashboard_data, JSON_PRETTY_PRINT) . PHP_EOL;

echo PHP_EOL . "=== TO UPDATE THE CARD ===" . PHP_EOL;
echo "The course count is controlled by the function:" . PHP_EOL;
echo "theme_remui_kids_get_elementary_dashboard_stats() in theme/remui_kids/lib.php" . PHP_EOL;
echo "Line 581-588 contains the course count query." . PHP_EOL;
?>
