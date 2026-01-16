<?php
/**
 * Download Course Students Data - School Manager
 * Export enrolled students data to CSV or Excel
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
$courseid = required_param('courseid', PARAM_INT);
$format = optional_param('format', 'csv', PARAM_ALPHA);

// Verify course belongs to this company
$course_in_company = $DB->record_exists('company_course', ['courseid' => $courseid, 'companyid' => $company_info->id]);

if (!$course_in_company) {
    die('Course not found in your school.');
}

// Get course details
$course = $DB->get_record('course', ['id' => $courseid]);

if (!$course) {
    die('Course not found.');
}

// Get all enrolled students from this company for this course (students only, no teachers/managers)
$enrolled_students = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
           ue.timecreated as date_started,
           cc.timecompleted as date_completed,
           gg.finalgrade, gg.rawgrademax
    FROM {user} u
    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
    INNER JOIN {enrol} e ON e.id = ue.enrolid
    INNER JOIN {company_users} cu ON cu.userid = u.id
    INNER JOIN {role_assignments} ra ON ra.userid = u.id
    INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
    INNER JOIN {role} r ON r.id = ra.roleid
    LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
    LEFT JOIN {grade_grades} gg ON gg.userid = u.id 
    LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = e.courseid AND gi.itemtype = 'course'
    WHERE e.courseid = ?
    AND cu.companyid = ?
    AND u.deleted = 0
    AND u.suspended = 0
    AND ue.status = 0
    AND r.shortname = 'student'
    ORDER BY u.firstname ASC, u.lastname ASC",
    [$courseid, $company_info->id]
);

// Prepare filename
$filename = clean_filename($course->shortname . '_students_' . date('Y-m-d')) . '.' . $format;

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, [
        'First Name',
        'Last Name',
        'Full Name',
        'Department',
        'Email Address',
        'Status',
        'Date Started',
        'Date Completed',
        'Grade (%)',
        'Course Name'
    ]);
    
    // Write data rows
    foreach ($enrolled_students as $student) {
        $date_started = $student->date_started ? date('Y-m-d', $student->date_started) : '-';
        $date_completed = $student->date_completed ? date('Y-m-d', $student->date_completed) : '-';
        
        // Calculate grade percentage
        $grade_percent = 0;
        if ($student->finalgrade && $student->rawgrademax > 0) {
            $grade_percent = round(($student->finalgrade / $student->rawgrademax) * 100);
        }
        
        // Determine status
        $status = 'Not Started';
        if ($student->date_completed) {
            $status = 'Completed';
        } elseif ($student->date_started) {
            $status = 'In Progress';
        }
        
        fputcsv($output, [
            $student->firstname,
            $student->lastname,
            fullname($student),
            $company_info->name,
            $student->email,
            $status,
            $date_started,
            $date_completed,
            $grade_percent . '%',
            $course->fullname
        ]);
    }
    
    fclose($output);
    
} else if ($format === 'excel') {
    // For Excel, we'll output as CSV but with .xlsx extension
    // In a real implementation, you'd use a library like PHPSpreadsheet
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // For now, output CSV (you can enhance this with PHPSpreadsheet later)
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, [
        'First Name',
        'Last Name',
        'Full Name',
        'Department',
        'Email Address',
        'Status',
        'Date Started',
        'Date Completed',
        'Grade (%)',
        'Course Name'
    ]);
    
    // Write data rows
    foreach ($enrolled_students as $student) {
        $date_started = $student->date_started ? date('Y-m-d', $student->date_started) : '-';
        $date_completed = $student->date_completed ? date('Y-m-d', $student->date_completed) : '-';
        
        // Calculate grade percentage
        $grade_percent = 0;
        if ($student->finalgrade && $student->rawgrademax > 0) {
            $grade_percent = round(($student->finalgrade / $student->rawgrademax) * 100);
        }
        
        // Determine status
        $status = 'Not Started';
        if ($student->date_completed) {
            $status = 'Completed';
        } elseif ($student->date_started) {
            $status = 'In Progress';
        }
        
        fputcsv($output, [
            $student->firstname,
            $student->lastname,
            fullname($student),
            $company_info->name,
            $student->email,
            $status,
            $date_started,
            $date_completed,
            $grade_percent . '%',
            $course->fullname
        ]);
    }
    
    fclose($output);
}

exit;
?>

