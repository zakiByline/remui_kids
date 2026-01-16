<?php
/**
 * Download Course Completion Report - School Manager
 * Export course completion statistics to CSV or Excel
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    die('Access denied. School manager role required.');
}

// Get company information
$company_info = $DB->get_record_sql(
    "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    die('Company information not found.');
}

// Get parameters
$format = optional_param('format', 'csv', PARAM_ALPHA);

// Get all courses for this company
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname 
     FROM {course} c
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     ORDER BY c.fullname ASC",
    [$company_info->id]
);

$completion_report_data = [];

foreach ($courses as $course) {
    // Get total enrolled students (students only)
    $total_enrolled = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id) 
         FROM {user} u
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE e.courseid = ? 
         AND ue.status = 0
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$course->id, $company_info->id]
    );
    
    // Get completed count
    $completed = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {course_completions} cc ON cc.userid = u.id
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = cc.course
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cc.course = ? 
         AND cc.timecompleted IS NOT NULL
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$course->id, $company_info->id]
    );
    
    // Calculate completion rate
    $completion_rate = $total_enrolled > 0 ? round(($completed / $total_enrolled) * 100, 1) : 0;
    
    $completion_report_data[] = [
        'course_name' => $course->fullname,
        'short_name' => $course->shortname,
        'total_students' => $total_enrolled,
        'completed' => $completed,
        'completion_rate' => $completion_rate
    ];
}

// Prepare filename
$clean_company_name = preg_replace('/[^a-zA-Z0-9\s-]/', '', $company_info->name);
$clean_company_name = preg_replace('/\s+/', ' ', $clean_company_name);
$filename = $clean_company_name . ' course completion report.csv';

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'Course Name',
        'Short Name',
        'Total Students',
        'Completed',
        'Completion Rate (%)',
        'School/Department'
    ]);
    
    foreach ($completion_report_data as $row) {
        fputcsv($output, [
            $row['course_name'],
            $row['short_name'],
            $row['total_students'],
            $row['completed'],
            $row['completion_rate'] . '%',
            $company_info->name
        ]);
    }
    
    fclose($output);
    
} else if ($format === 'excel') {
    $excel_filename = str_replace('.csv', '.xlsx', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $excel_filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'Course Name',
        'Short Name',
        'Total Students',
        'Completed',
        'Completion Rate (%)',
        'School/Department'
    ]);
    
    foreach ($completion_report_data as $row) {
        fputcsv($output, [
            $row['course_name'],
            $row['short_name'],
            $row['total_students'],
            $row['completed'],
            $row['completion_rate'] . '%',
            $company_info->name
        ]);
    }
    
    fclose($output);
}

exit;
?>


