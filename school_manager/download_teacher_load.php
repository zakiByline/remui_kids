<?php
/**
 * Download Teacher Load Report - School Manager
 * Export teacher course load distribution to CSV or Excel
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

// Get all teachers with their course counts
$teachers_data = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email,
            COUNT(DISTINCT ctx.instanceid) as course_count,
            GROUP_CONCAT(DISTINCT c.fullname SEPARATOR ', ') as courses
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {role} r ON r.id = ra.roleid
     INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
     INNER JOIN {course} c ON c.id = ctx.instanceid
     INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = cu.companyid
     WHERE cu.companyid = ?
     AND r.shortname IN ('teacher', 'editingteacher')
     AND u.deleted = 0
     AND u.suspended = 0
     AND c.visible = 1
     GROUP BY u.id, u.firstname, u.lastname, u.email
     ORDER BY course_count DESC, u.lastname ASC",
    [$company_info->id]
);

$teacher_report_data = [];

foreach ($teachers_data as $teacher) {
    // Categorize teacher by load
    $load_category = '';
    if ($teacher->course_count >= 1 && $teacher->course_count <= 2) {
        $load_category = '1-2 Courses (Low Load)';
    } elseif ($teacher->course_count >= 3 && $teacher->course_count <= 5) {
        $load_category = '3-5 Courses (Medium Load)';
    } elseif ($teacher->course_count > 5) {
        $load_category = 'More than 5 Courses (High Load)';
    }
    
    // Calculate performance metrics
    // Get courses for this teacher
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id
         FROM {course} c
         INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE ra.userid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND cc.companyid = ?
         AND c.visible = 1
         AND c.id > 1",
        [$teacher->id, $company_info->id]
    );
    
    // Get course IDs for IN clause
    $course_ids = array_keys($teacher_courses);
    
    if (!empty($course_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
        $params['companyid'] = $company_info->id;
        
        // Count UNIQUE students across ALL courses taught by this teacher (no duplicates)
        $total_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid $insql
             AND cu.companyid = :companyid
             AND ue.status = 0
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            $params
        );
        
        // Count UNIQUE completed students across ALL courses taught by this teacher
        $completed_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {course_completions} cc ON cc.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = cc.course
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cc.course $insql
             AND cu.companyid = :companyid
             AND cc.timecompleted IS NOT NULL
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            $params
        );
    } else {
        $total_students = 0;
        $completed_students = 0;
    }
    
    // Calculate performance score
    $courses_score = min(($teacher->course_count / 5) * 30, 30);
    $completion_rate = $total_students > 0 ? ($completed_students / $total_students) : 0;
    $completion_score = $completion_rate * 40;
    $engagement_score = min(($total_students / 20) * 30, 30);
    $performance_score = round($courses_score + $completion_score + $engagement_score, 1);
    
    $teacher_report_data[] = [
        'name' => fullname($teacher),
        'email' => $teacher->email,
        'course_count' => $teacher->course_count,
        'load_category' => $load_category,
        'courses' => $teacher->courses,
        'total_students' => $total_students,
        'completed_students' => $completed_students,
        'completion_rate' => round($completion_rate * 100, 1),
        'performance_score' => $performance_score
    ];
}

// Prepare filename
$clean_company_name = preg_replace('/[^a-zA-Z0-9\s-]/', '', $company_info->name);
$clean_company_name = preg_replace('/\s+/', ' ', $clean_company_name);
$filename = $clean_company_name . ' teacher load report.csv';

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Teacher Load & Performance Report']);
    fputcsv($output, ['School: ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['Teacher workload and performance metrics']);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Teacher Name',
        'Email',
        'Number of Courses',
        'Load Category',
        'Total Students',
        'Completed Students',
        'Completion Rate (%)',
        'Performance Score',
        'Courses Assigned'
    ]);
    
    foreach ($teacher_report_data as $row) {
        fputcsv($output, [
            $row['name'],
            $row['email'],
            $row['course_count'],
            $row['load_category'],
            $row['total_students'],
            $row['completed_students'],
            $row['completion_rate'],
            $row['performance_score'],
            $row['courses']
        ]);
    }
    
    fclose($output);
    
} else if ($format === 'excel') {
    $excel_filename = str_replace('.csv', '.xlsx', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $excel_filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Teacher Load & Performance Report']);
    fputcsv($output, ['School: ' . $company_info->name]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['Teacher workload and performance metrics']);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Teacher Name',
        'Email',
        'Number of Courses',
        'Load Category',
        'Total Students',
        'Completed Students',
        'Completion Rate (%)',
        'Performance Score',
        'Courses Assigned'
    ]);
    
    foreach ($teacher_report_data as $row) {
        fputcsv($output, [
            $row['name'],
            $row['email'],
            $row['course_count'],
            $row['load_category'],
            $row['total_students'],
            $row['completed_students'],
            $row['completion_rate'],
            $row['performance_score'],
            $row['courses']
        ]);
    }
    
    fclose($output);
}

exit;
?>

