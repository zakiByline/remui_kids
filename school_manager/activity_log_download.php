<?php
/**
 * Activity Log - Download (Excel/PDF)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$format = optional_param('format', 'excel', PARAM_ALPHA);
$filter_type = optional_param('filter_type', 'all', PARAM_ALPHA);
$filter_days = optional_param('filter_days', 30, PARAM_INT);
$search_query = optional_param('search', '', PARAM_TEXT);

$allowedformats = ['excel', 'pdf'];

if (!in_array($format, $allowedformats, true)) {
    $format = 'excel';
}

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    throw new moodle_exception('Access denied. School manager role required.');
}

$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    throw new moodle_exception('Unable to determine company information.');
}

// Calculate date range
$date_from = time() - ($filter_days * 24 * 60 * 60);

// Initialize activity data
$activities = [];

// Check if log table exists
$log_table_exists = $DB->get_manager()->table_exists('logstore_standard_log');

if ($company_info && $log_table_exists) {
    // Build WHERE clause for filtering
    $action_filter = '';
    if ($filter_type !== 'all') {
        switch ($filter_type) {
            case 'logins':
                $action_filter = "AND l.action = 'loggedin'";
                break;
            case 'enrollments':
                $action_filter = "AND l.eventname LIKE '%enrol%'";
                break;
            case 'user_changes':
                $action_filter = "AND l.eventname LIKE '%user%' AND l.action IN ('created', 'updated', 'deleted')";
                break;
            case 'course_changes':
                $action_filter = "AND l.eventname LIKE '%course%' AND l.action IN ('created', 'updated', 'deleted')";
                break;
        }
    }
    
    // Get all users from this company
    $company_users = $DB->get_records_sql(
        "SELECT DISTINCT userid 
         FROM {company_users} 
         WHERE companyid = ?",
        [$company_info->id]
    );
    
    $user_ids = array_keys($company_users);
    
    if (!empty($user_ids)) {
        list($user_sql, $user_params) = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED);
        
        // Fetch recent activities
        $sql = "SELECT l.id, l.eventname, l.component, l.action, l.target, l.objecttable, 
                       l.objectid, l.courseid, l.timecreated, l.userid,
                       u.firstname, u.lastname, u.email,
                       c.fullname as coursename
                FROM {logstore_standard_log} l
                LEFT JOIN {user} u ON l.userid = u.id
                LEFT JOIN {course} c ON l.courseid = c.id
                WHERE l.timecreated >= :timefrom
                AND l.userid $user_sql
                $action_filter
                ORDER BY l.timecreated DESC
                LIMIT 1000";
        
        $all_params = array_merge(['timefrom' => $date_from], $user_params);
        $log_records = $DB->get_records_sql($sql, $all_params);
        
        // Process activities
        foreach ($log_records as $log) {
            // Skip system and guest user activities
            if ($log->userid <= 2) continue;
            
            // Parse activity type
            $activity_type = 'Other';
            $activity_description = '';
            
            if ($log->action === 'loggedin') {
                $activity_type = 'Login';
                $activity_description = 'Logged in to the system';
            } else if (strpos($log->eventname, 'enrol') !== false) {
                $activity_type = 'Enrollment';
                if ($log->action === 'created') {
                    $activity_description = 'Enrolled in course: ' . ($log->coursename ?: 'Unknown');
                } else if ($log->action === 'deleted') {
                    $activity_description = 'Unenrolled from course: ' . ($log->coursename ?: 'Unknown');
                } else {
                    $activity_description = 'Enrollment modified';
                }
            } else if (strpos($log->eventname, 'user') !== false && in_array($log->action, ['created', 'updated', 'deleted'])) {
                $activity_type = 'User Change';
                $activity_description = ucfirst($log->action) . ' user profile';
            } else if (strpos($log->eventname, 'course') !== false && in_array($log->action, ['created', 'updated', 'deleted', 'viewed'])) {
                $activity_type = 'Course Activity';
                if ($log->action === 'viewed') {
                    $activity_description = 'Viewed course: ' . ($log->coursename ?: 'Unknown');
                } else {
                    $activity_description = ucfirst($log->action) . ' course: ' . ($log->coursename ?: 'Unknown');
                }
            } else if (strpos($log->eventname, 'grade') !== false) {
                $activity_type = 'Grade';
                $activity_description = 'Grade activity in ' . ($log->coursename ?: 'course');
            } else if (strpos($log->eventname, 'quiz') !== false || strpos($log->eventname, 'assign') !== false) {
                $activity_type = 'Assessment';
                $activity_description = ucfirst($log->target) . ' activity in ' . ($log->coursename ?: 'course');
            } else {
                $activity_description = ucfirst($log->action) . ' ' . ($log->target ?: 'activity');
                if ($log->coursename) {
                    $activity_description .= ' in ' . $log->coursename;
                }
            }
            
            // Apply search filter
            if (!empty($search_query)) {
                $search_lower = strtolower($search_query);
                $searchable = strtolower(
                    $log->firstname . ' ' . $log->lastname . ' ' . 
                    $log->email . ' ' . 
                    $activity_description . ' ' . 
                    ($log->coursename ?: '')
                );
                
                if (strpos($searchable, $search_lower) === false) {
                    continue;
                }
            }
            
            $activities[] = [
                'type' => $activity_type,
                'user_name' => $log->firstname . ' ' . $log->lastname,
                'user_email' => $log->email,
                'description' => $activity_description,
                'course' => $log->coursename ?: 'N/A',
                'time' => userdate($log->timecreated, get_string('strftimedatetime', 'langconfig'))
            ];
        }
    }
}

// Prepare data for download
$columns = [
    'type' => 'Activity Type',
    'user_name' => 'User Name',
    'user_email' => 'User Email',
    'description' => 'Description',
    'course' => 'Course',
    'time' => 'Timestamp'
];

$rows = $activities;
$title = 'Activity Log Report';
$filename = format_string($company_info->name) . ' - Activity Log';
$school_name = format_string($company_info->name);
$generated_on = userdate(time(), get_string('strftimedatetime', 'langconfig'));

// Summary cards
$summarycards = [
    [
        'title' => 'Report Period',
        'value' => $filter_days . ' days'
    ],
    [
        'title' => 'Total Activities',
        'value' => count($activities)
    ],
    [
        'title' => 'Filter Type',
        'value' => ucwords(str_replace('_', ' ', $filter_type))
    ]
];

// Use the same output function as other reports
if (file_exists(__DIR__ . '/c_reports_download.php')) {
    require_once(__DIR__ . '/c_reports_download.php');
    if (function_exists('c_reports_output_download')) {
        c_reports_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on, false);
    }
}

exit;
?>


























