<?php
require_once('../../../config.php');

global $DB, $USER;

// Get current user ID (you can change this to any user ID)
$userid = $USER->id;

echo "=== DASHBOARD STATISTICS FOR USER ID: $userid ===" . PHP_EOL . PHP_EOL;

// 1. TOTAL COURSES ENROLLED
echo "1. TOTAL COURSES ENROLLED:" . PHP_EOL;
$totalcourses = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id) 
     FROM {course} c 
     JOIN {enrol} e ON c.id = e.courseid 
     JOIN {user_enrolments} ue ON e.id = ue.enrolid 
     WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1",
    [$userid]
);
echo "Total Courses: $totalcourses" . PHP_EOL . PHP_EOL;

// 2. LESSONS COMPLETED (Course Modules Completed)
echo "2. LESSONS COMPLETED:" . PHP_EOL;
$lessonscompleted = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT cmc.coursemoduleid) 
     FROM {course_modules_completion} cmc 
     JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id 
     JOIN {course} c ON cm.course = c.id 
     WHERE cmc.userid = ? AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1",
    [$userid]
);
echo "Lessons Completed: $lessonscompleted" . PHP_EOL . PHP_EOL;

// 3. ACTIVITIES COMPLETED (All Completion Records)
echo "3. ACTIVITIES COMPLETED:" . PHP_EOL;
$activitiescompleted = $DB->count_records_sql(
    "SELECT COUNT(*) 
     FROM {course_modules_completion} cmc 
     JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id 
     JOIN {course} c ON cm.course = c.id 
     WHERE cmc.userid = ? AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1",
    [$userid]
);
echo "Activities Completed: $activitiescompleted" . PHP_EOL . PHP_EOL;

// 4. OVERALL PROGRESS CALCULATION
echo "4. OVERALL PROGRESS CALCULATION:" . PHP_EOL;

// Get total activities available to user
$totalactivities = $DB->count_records_sql(
    "SELECT COUNT(*) 
     FROM {course_modules} cm 
     JOIN {course} c ON cm.course = c.id 
     JOIN {enrol} e ON c.id = e.courseid 
     JOIN {user_enrolments} ue ON e.id = ue.enrolid 
     WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1 AND cm.completion > 0",
    [$userid]
);

$overallprogress = 0;
if ($totalactivities > 0) {
    $overallprogress = round(($activitiescompleted / $totalactivities) * 100);
}

echo "Total Activities Available: $totalactivities" . PHP_EOL;
echo "Activities Completed: $activitiescompleted" . PHP_EOL;
echo "Overall Progress: $overallprogress%" . PHP_EOL . PHP_EOL;

// 5. DETAILED BREAKDOWN BY COURSE
echo "5. DETAILED BREAKDOWN BY COURSE:" . PHP_EOL;
$coursedetails = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname,
            COUNT(DISTINCT cm.id) as total_activities,
            COUNT(DISTINCT CASE WHEN cmc.completionstate > 0 THEN cmc.coursemoduleid END) as completed_activities,
            ROUND(COUNT(DISTINCT CASE WHEN cmc.completionstate > 0 THEN cmc.coursemoduleid END) * 100.0 / COUNT(DISTINCT cm.id), 2) as progress_percentage
     FROM {course} c 
     JOIN {enrol} e ON c.id = e.courseid 
     JOIN {user_enrolments} ue ON e.id = ue.enrolid 
     LEFT JOIN {course_modules} cm ON c.id = cm.course AND cm.completion > 0
     LEFT JOIN {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid AND cmc.userid = ?
     WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1
     GROUP BY c.id, c.fullname, c.shortname
     ORDER BY c.fullname",
    [$userid, $userid]
);

foreach ($coursedetails as $course) {
    echo "Course: " . $course->fullname . PHP_EOL;
    echo "  - Total Activities: " . $course->total_activities . PHP_EOL;
    echo "  - Completed: " . $course->completed_activities . PHP_EOL;
    echo "  - Progress: " . $course->progress_percentage . "%" . PHP_EOL . PHP_EOL;
}

// 6. SUMMARY FOR DASHBOARD CARDS
echo "=== DASHBOARD CARDS SUMMARY ===" . PHP_EOL;
echo "Courses Card: $totalcourses" . PHP_EOL;
echo "Lessons Done Card: $lessonscompleted" . PHP_EOL;
echo "Activities Done Card: $activitiescompleted" . PHP_EOL;
echo "Overall Progress Card: $overallprogress%" . PHP_EOL;

// 7. JSON OUTPUT FOR AJAX/Frontend Use
$dashboard_stats = [
    'total_courses' => $totalcourses,
    'lessons_completed' => $lessonscompleted,
    'activities_completed' => $activitiescompleted,
    'overall_progress' => $overallprogress,
    'total_activities' => $totalactivities
];

echo PHP_EOL . "=== JSON OUTPUT ===" . PHP_EOL;
echo json_encode($dashboard_stats, JSON_PRETTY_PRINT) . PHP_EOL;
?>
