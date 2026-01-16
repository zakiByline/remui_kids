<?php
/**
 * Dashboard Statistics Function for remui_kids theme
 * This function fetches real data from the database for dashboard statistics cards
 */

/**
 * Get real dashboard statistics for a user
 * 
 * @param int $userid User ID
 * @return array Array containing dashboard statistics
 */
function get_real_dashboard_stats($userid) {
    global $DB;
    
    try {
        // 1. TOTAL COURSES ENROLLED
        $totalcourses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id) 
             FROM {course} c 
             JOIN {enrol} e ON c.id = e.courseid 
             JOIN {user_enrolments} ue ON e.id = ue.enrolid 
             WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1",
            [$userid]
        );
        
        // 2. LESSONS COMPLETED (Course Modules Completed)
        $lessonscompleted = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cmc.coursemoduleid) 
             FROM {course_modules_completion} cmc 
             JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id 
             JOIN {course} c ON cm.course = c.id 
             WHERE cmc.userid = ? AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1",
            [$userid]
        );
        
        // 3. ACTIVITIES COMPLETED (All Completion Records)
        $activitiescompleted = $DB->count_records_sql(
            "SELECT COUNT(*) 
             FROM {course_modules_completion} cmc 
             JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id 
             JOIN {course} c ON cm.course = c.id 
             WHERE cmc.userid = ? AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1",
            [$userid]
        );
        
        // 4. OVERALL PROGRESS CALCULATION
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
        
        return [
            'total_courses' => $totalcourses,
            'lessons_completed' => $lessonscompleted,
            'activities_completed' => $activitiescompleted,
            'overall_progress' => $overallprogress,
            'total_activities' => $totalactivities
        ];
        
    } catch (Exception $e) {
        // Return default values if there's an error
        return [
            'total_courses' => 0,
            'lessons_completed' => 0,
            'activities_completed' => 0,
            'overall_progress' => 0,
            'total_activities' => 0
        ];
    }
}

/**
 * Get detailed course statistics for a user
 * 
 * @param int $userid User ID
 * @return array Array containing detailed course statistics
 */
function get_detailed_course_stats($userid) {
    global $DB;
    
    try {
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
        
        return $coursedetails;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get statistics by activity type
 * 
 * @param int $userid User ID
 * @return array Array containing statistics by activity type
 */
function get_activity_type_stats($userid) {
    global $DB;
    
    try {
        $activitystats = $DB->get_records_sql(
            "SELECT m.name as modulename, m.displayname,
                    COUNT(DISTINCT cm.id) as total_activities,
                    COUNT(DISTINCT CASE WHEN cmc.completionstate > 0 THEN cmc.coursemoduleid END) as completed_activities,
                    ROUND(COUNT(DISTINCT CASE WHEN cmc.completionstate > 0 THEN cmc.coursemoduleid END) * 100.0 / COUNT(DISTINCT cm.id), 2) as completion_rate
             FROM {course_modules} cm 
             JOIN {modules} m ON cm.module = m.id
             JOIN {course} c ON cm.course = c.id 
             JOIN {enrol} e ON c.id = e.courseid 
             JOIN {user_enrolments} ue ON e.id = ue.enrolid 
             LEFT JOIN {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid AND cmc.userid = ?
             WHERE ue.userid = ? AND c.visible = 1 AND c.id > 1 AND cm.completion > 0
             GROUP BY m.id, m.name, m.displayname
             ORDER BY completion_rate DESC",
            [$userid, $userid]
        );
        
        return $activitystats;
        
    } catch (Exception $e) {
        return [];
    }
}

// Example usage:
if (isset($USER) && $USER->id) {
    $stats = get_real_dashboard_stats($USER->id);
    echo "Dashboard Stats for User " . $USER->id . ":" . PHP_EOL;
    echo "Courses: " . $stats['total_courses'] . PHP_EOL;
    echo "Lessons Done: " . $stats['lessons_completed'] . PHP_EOL;
    echo "Activities Done: " . $stats['activities_completed'] . PHP_EOL;
    echo "Overall Progress: " . $stats['overall_progress'] . "%" . PHP_EOL;
}
?>
