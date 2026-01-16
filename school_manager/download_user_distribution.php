<?php
/**
 * Download User Distribution Report - School Manager
 * Export user role distribution data to CSV or Excel
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

// Count students
$students_count = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {role} r ON r.id = ra.roleid
     WHERE cu.companyid = ?
     AND r.shortname = 'student'
     AND u.deleted = 0
     AND u.suspended = 0",
    [$company_info->id]
);

// Count teachers
$teachers_count = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {role} r ON r.id = ra.roleid
     WHERE cu.companyid = ?
     AND r.shortname IN ('teacher', 'editingteacher')
     AND u.deleted = 0
     AND u.suspended = 0",
    [$company_info->id]
);

// Count managers
$managers_count = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     WHERE cu.companyid = ?
     AND cu.managertype = 1
     AND u.deleted = 0
     AND u.suspended = 0",
    [$company_info->id]
);

$total_users = $students_count + $teachers_count + $managers_count;

// Calculate percentages
$students_pct = $total_users > 0 ? round(($students_count / $total_users) * 100, 1) : 0;
$teachers_pct = $total_users > 0 ? round(($teachers_count / $total_users) * 100, 1) : 0;
$managers_pct = $total_users > 0 ? round(($managers_count / $total_users) * 100, 1) : 0;

// Prepare filename
$clean_company_name = preg_replace('/[^a-zA-Z0-9\s-]/', '', $company_info->name);
$clean_company_name = preg_replace('/\s+/', ' ', $clean_company_name);
$filename = $clean_company_name . ' user distribution report.csv';

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'User Role',
        'Count',
        'Percentage (%)',
        'School/Department'
    ]);
    
    fputcsv($output, ['Students', $students_count, $students_pct . '%', $company_info->name]);
    fputcsv($output, ['Teachers', $teachers_count, $teachers_pct . '%', $company_info->name]);
    fputcsv($output, ['Managers', $managers_count, $managers_pct . '%', $company_info->name]);
    fputcsv($output, ['Total Users', $total_users, '100%', $company_info->name]);
    
    fclose($output);
    
} else if ($format === 'excel') {
    $excel_filename = str_replace('.csv', '.xlsx', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $excel_filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'User Role',
        'Count',
        'Percentage (%)',
        'School/Department'
    ]);
    
    fputcsv($output, ['Students', $students_count, $students_pct . '%', $company_info->name]);
    fputcsv($output, ['Teachers', $teachers_count, $teachers_pct . '%', $company_info->name]);
    fputcsv($output, ['Managers', $managers_count, $managers_pct . '%', $company_info->name]);
    fputcsv($output, ['Total Users', $total_users, '100%', $company_info->name]);
    
    fclose($output);
}

exit;
?>


