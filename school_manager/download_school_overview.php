<?php
/**
 * Download School Overview Report - School Manager
 * Export comprehensive school performance metrics to CSV or Excel
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

// Get all courses
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname 
     FROM {course} c
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     ORDER BY c.fullname ASC",
    [$company_info->id]
);

$total_courses = count($courses);
$courses_with_students = 0;
$total_enrollments = 0;
$total_active = 0;

foreach ($courses as $course) {
    // Count students
    $enrolled = $DB->count_records_sql(
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
    
    if ($enrolled > 0) {
        $courses_with_students++;
    }
    $total_enrollments += $enrolled;
    
    // Count active students
    $active = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
         WHERE e.courseid = ? 
         AND ue.status = 0
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ula.timeaccess > ?",
        [$course->id, $company_info->id, strtotime('-30 days')]
    );
    
    $total_active += $active;
}

// Count total students and teachers
$total_students = $DB->count_records_sql(
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

$total_teachers = $DB->count_records_sql(
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

// Calculate metrics
$course_utilization = $total_courses > 0 ? round(($courses_with_students / $total_courses) * 100, 1) : 0;
$avg_students_per_course = $courses_with_students > 0 ? round($total_enrollments / $courses_with_students, 1) : 0;
$student_teacher_ratio = $total_teachers > 0 ? round($total_students / $total_teachers, 1) : 0;
$activity_rate = $total_enrollments > 0 ? round(($total_active / $total_enrollments) * 100, 1) : 0;

// Prepare filename
$clean_company_name = preg_replace('/[^a-zA-Z0-9\s-]/', '', $company_info->name);
$clean_company_name = preg_replace('/\s+/', ' ', $clean_company_name);
$filename = $clean_company_name . ' school overview.csv';

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['School Performance Overview']);
    fputcsv($output, ['School: ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['School-Wide Metrics']);
    fputcsv($output, []);
    
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Courses', $total_courses]);
    fputcsv($output, ['Active Courses (with students)', $courses_with_students]);
    fputcsv($output, ['Empty Courses', $total_courses - $courses_with_students]);
    fputcsv($output, ['Course Utilization Rate', $course_utilization . '%']);
    fputcsv($output, []);
    fputcsv($output, ['Total Students', $total_students]);
    fputcsv($output, ['Total Enrollments', $total_enrollments]);
    fputcsv($output, ['Active Students (30 days)', $total_active]);
    fputcsv($output, ['Overall Activity Rate', $activity_rate . '%']);
    fputcsv($output, []);
    fputcsv($output, ['Total Teachers', $total_teachers]);
    fputcsv($output, ['Student-Teacher Ratio', $student_teacher_ratio . ':1']);
    fputcsv($output, ['Avg Students per Course', $avg_students_per_course]);
    fputcsv($output, ['Avg Students per Teacher', $student_teacher_ratio]);
    
    fclose($output);
    
} else if ($format === 'excel') {
    $excel_filename = str_replace('.csv', '.xlsx', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $excel_filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['School Performance Overview']);
    fputcsv($output, ['School: ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['School-Wide Metrics']);
    fputcsv($output, []);
    
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Courses', $total_courses]);
    fputcsv($output, ['Active Courses (with students)', $courses_with_students]);
    fputcsv($output, ['Empty Courses', $total_courses - $courses_with_students]);
    fputcsv($output, ['Course Utilization Rate', $course_utilization . '%']);
    fputcsv($output, []);
    fputcsv($output, ['Total Students', $total_students]);
    fputcsv($output, ['Total Enrollments', $total_enrollments]);
    fputcsv($output, ['Active Students (30 days)', $total_active]);
    fputcsv($output, ['Overall Activity Rate', $activity_rate . '%']);
    fputcsv($output, []);
    fputcsv($output, ['Total Teachers', $total_teachers]);
    fputcsv($output, ['Student-Teacher Ratio', $student_teacher_ratio . ':1']);
    fputcsv($output, ['Avg Students per Course', $avg_students_per_course]);
    fputcsv($output, ['Avg Students per Teacher', $student_teacher_ratio]);
    
    fclose($output);
}

exit;
?>


