<?php
/**
 * Download Login Reports - School Manager
 * Handles CSV and Excel downloads for login trend data
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get format parameter
$format = optional_param('format', 'csv', PARAM_ALPHA);

// Get login trend data for students and teachers (last 30 days)
$login_trend_data = [
    'student_logins' => [],
    'teacher_logins' => [],
    'dates' => []
];

// Generate last 30 days dates
$dates = [];
for ($i = 29; $i >= 0; $i--) {
    $dates[] = date('Y-m-d', strtotime("-$i days"));
}
$login_trend_data['dates'] = $dates;

// Calculate the timestamp for 30 days ago
$thirty_days_ago = strtotime("-30 days");

// Get all student logins in the last 30 days
$student_login_records = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.lastaccess
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {context} ctx ON ctx.id = ra.contextid
     INNER JOIN {role} r ON r.id = ra.roleid
     WHERE cu.companyid = ?
     AND r.shortname = 'student'
     AND u.deleted = 0
     AND u.suspended = 0
     AND u.lastaccess >= ?",
    [$company_info->id, $thirty_days_ago]
);

// Get all teacher logins in the last 30 days
$teacher_login_records = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.lastaccess
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {context} ctx ON ctx.id = ra.contextid
     INNER JOIN {role} r ON r.id = ra.roleid
     WHERE cu.companyid = ?
     AND r.shortname IN ('teacher', 'editingteacher', 'manager')
     AND u.deleted = 0
     AND u.suspended = 0
     AND u.lastaccess >= ?",
    [$company_info->id, $thirty_days_ago]
);

// Count logins per day for students
foreach ($dates as $date) {
    $count = 0;
    $date_start = strtotime($date . ' 00:00:00');
    $date_end = strtotime($date . ' 23:59:59');
    
    foreach ($student_login_records as $record) {
        if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
            $count++;
        }
    }
    $login_trend_data['student_logins'][] = $count;
}

// Count logins per day for teachers
foreach ($dates as $date) {
    $count = 0;
    $date_start = strtotime($date . ' 00:00:00');
    $date_end = strtotime($date . ' 23:59:59');
    
    foreach ($teacher_login_records as $record) {
        if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
            $count++;
        }
    }
    $login_trend_data['teacher_logins'][] = $count;
}

// Calculate summary statistics
$total_student_logins = array_sum($login_trend_data['student_logins']);
$total_teacher_logins = array_sum($login_trend_data['teacher_logins']);
$average_daily_logins = round(($total_student_logins + $total_teacher_logins) / 30, 1);

// Prepare filename
$filename = 'login_reports_' . $company_info->shortname . '_' . date('Y-m-d_H-i-s');

if ($format === 'excel') {
    // Set headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // For Excel, we'll create a simple CSV that can be opened in Excel
    // In a real implementation, you might want to use a library like PhpSpreadsheet
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    fputcsv($output, ['Login Reports - ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, ['']);
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Total Student Logins', $total_student_logins]);
    fputcsv($output, ['Total Teacher Logins', $total_teacher_logins]);
    fputcsv($output, ['Average Daily Logins', $average_daily_logins]);
    fputcsv($output, ['']);
    fputcsv($output, ['Daily Login Data']);
    fputcsv($output, ['Date', 'Student Logins', 'Teacher Logins', 'Total Logins']);
    
    // Write daily data
    for ($i = 0; $i < count($dates); $i++) {
        $total_daily = $login_trend_data['student_logins'][$i] + $login_trend_data['teacher_logins'][$i];
        fputcsv($output, [
            $dates[$i],
            $login_trend_data['student_logins'][$i],
            $login_trend_data['teacher_logins'][$i],
            $total_daily
        ]);
    }
    
    fclose($output);
} else {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    fputcsv($output, ['Login Reports - ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, ['']);
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Total Student Logins', $total_student_logins]);
    fputcsv($output, ['Total Teacher Logins', $total_teacher_logins]);
    fputcsv($output, ['Average Daily Logins', $average_daily_logins]);
    fputcsv($output, ['']);
    fputcsv($output, ['Daily Login Data']);
    fputcsv($output, ['Date', 'Student Logins', 'Teacher Logins', 'Total Logins']);
    
    // Write daily data
    for ($i = 0; $i < count($dates); $i++) {
        $total_daily = $login_trend_data['student_logins'][$i] + $login_trend_data['teacher_logins'][$i];
        fputcsv($output, [
            $dates[$i],
            $login_trend_data['student_logins'][$i],
            $login_trend_data['teacher_logins'][$i],
            $total_daily
        ]);
    }
    
    fclose($output);
}

exit;
?>
