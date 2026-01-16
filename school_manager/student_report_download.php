<?php
/**
 * Student Report - Tab-specific downloads (Excel/PDF)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$tab = required_param('tab', PARAM_ALPHANUMEXT);
$format = optional_param('format', 'excel', PARAM_ALPHA);
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

$summarycards = [];
$cardlayout = false;
$school_name = format_string($company_info->name);
$generated_on = userdate(time(), get_string('strftimedatetime', 'langconfig'));

switch ($tab) {
    case 'summary':
        // Student Report Summary Tab
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
        
        $active_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0
             AND u.lastaccess >= ?",
            [$company_info->id, strtotime('-30 days')]
        );
        
        $enrolled_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ?
             AND ue.status = 0
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [$company_info->id]
        );
        
        $completed_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.id)
             FROM {course_completions} cc
             INNER JOIN {user} u ON u.id = cc.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0
             AND cc.timecompleted IS NOT NULL",
            [$company_info->id]
        );
        
        $columns = [
            'student_name' => 'Student Name',
            'email' => 'Email',
            'grade_level' => 'Grade Level',
            'courses_enrolled' => 'Courses Enrolled',
            'courses_completed' => 'Courses Completed',
            'completion_rate' => 'Completion Rate (%)',
            'average_grade' => 'Average Grade (%)',
            'performance' => 'Performance'
        ];
        
        $students = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                uifd.data AS grade_level
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid
             LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
             LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
             WHERE cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0
             ORDER BY u.lastname ASC, u.firstname ASC",
            [$company_info->id]
        );
        
        $rows = [];
        foreach ($students as $student) {
            $course_count = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT c.id)
                 FROM {course} c
                 INNER JOIN {user_enrolments} ue ON ue.userid = ?
                 INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE cc.companyid = ?
                 AND ue.status = 0
                 AND c.visible = 1
                 AND c.id > 1",
                [$student->id, $company_info->id]
            );
            
            $completed_count = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cc.course)
                 FROM {course_completions} cc
                 INNER JOIN {user_enrolments} ue ON ue.userid = cc.userid
                 INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
                 INNER JOIN {company_course} cc_link ON cc_link.courseid = cc.course
                 WHERE cc.userid = ?
                 AND cc_link.companyid = ?
                 AND cc.timecompleted IS NOT NULL
                 AND ue.status = 0",
                [$student->id, $company_info->id]
            );
            
            $avg_grade_record = $DB->get_record_sql(
                "SELECT AVG((gg.finalgrade / gi.grademax) * 100) AS avg_grade
                 FROM {grade_grades} gg
                 INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                 INNER JOIN {course} c ON c.id = gi.courseid
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE gg.userid = ?
                 AND cc.companyid = ?
                 AND gi.itemtype = 'course'
                 AND gg.finalgrade IS NOT NULL
                 AND gi.grademax > 0",
                [$student->id, $company_info->id]
            );
            
            $average_grade = ($avg_grade_record && $avg_grade_record->avg_grade !== null)
                ? round((float)$avg_grade_record->avg_grade, 1)
                : null;
            
            $completion_rate = $course_count > 0
                ? round(($completed_count / $course_count) * 100, 1)
                : 0;
            
            if ($course_count === 0) {
                $performance_label = 'N/A';
            } elseif ($completion_rate >= 80) {
                $performance_label = 'Excellent';
            } elseif ($completion_rate >= 60) {
                $performance_label = 'Good';
            } elseif ($completion_rate >= 40) {
                $performance_label = 'Average';
            } else {
                $performance_label = 'Needs Improvement';
            }
            
            $rows[] = [
                'student_name' => fullname($student),
                'email' => $student->email,
                'grade_level' => $student->grade_level ?? '—',
                'courses_enrolled' => $course_count,
                'courses_completed' => $completed_count,
                'completion_rate' => $completion_rate,
                'average_grade' => $average_grade !== null ? $average_grade : '—',
                'performance' => $performance_label
            ];
        }
        
        $summarycards = [
            ['label' => 'Total Students', 'value' => $total_students, 'color' => '#2563eb'],
            ['label' => 'Active Students', 'value' => $active_students, 'color' => '#0ea5e9'],
            ['label' => 'Enrolled Students', 'value' => $enrolled_students, 'color' => '#8b5cf6'],
            ['label' => 'Completed Courses', 'value' => $completed_courses, 'color' => '#22c55e']
        ];
        $title = 'Student Report';
        $filename = $company_info->name . ' student report';
        break;
        
    case 'academic':
    case 'engagements':
    case 'inactive':
    case 'quizassignmentreports':
    case 'progress':
    case 'activitylog':
        // For other tabs, return basic placeholder data
        $columns = [
            'student' => 'Student',
            'data' => 'Data'
        ];
        $rows = [['student' => 'Data not available', 'data' => 'This tab download is not yet implemented.']];
        $summarycards = [];
        $title = 'Student Report - ' . ucwords(str_replace('_', ' ', $tab));
        $filename = $company_info->name . ' student ' . $tab . ' report';
        break;
        
    default:
        throw new moodle_exception('Invalid tab supplied.');
}

// Use the same output function as c_reports
if (file_exists(__DIR__ . '/c_reports_download.php')) {
    require_once(__DIR__ . '/c_reports_download.php');
    // Check if the function exists (it should be in c_reports_download.php)
    if (function_exists('c_reports_output_download')) {
        c_reports_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on, $cardlayout);
    } else {
        // Fallback if function doesn't exist
        student_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on);
    }
} else {
    student_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on);
}

exit;

/**
 * Fallback download output function for student reports
 */
function student_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on) {
    if ($format === 'pdf') {
        // PDF generation would go here
        header('Content-Type: text/plain');
        echo "PDF download for student reports is not yet fully implemented.\n";
        echo "Title: $title\n";
        echo "School: $school_name\n";
        echo "Generated: $generated_on\n";
    } else {
        // CSV/Excel output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add header information
        fputcsv($output, [$title]);
        fputcsv($output, ['School: ' . $school_name]);
        fputcsv($output, ['Generated: ' . $generated_on]);
        fputcsv($output, []);
        
        // Add summary cards
        if (!empty($summarycards)) {
            fputcsv($output, ['Summary']);
            foreach ($summarycards as $card) {
                fputcsv($output, [$card['label'] . ': ' . $card['value']]);
            }
            fputcsv($output, []);
        }
        
        // Add column headers
        fputcsv($output, array_values($columns));
        
        // Add data rows
        foreach ($rows as $row) {
            $csv_row = [];
            foreach (array_keys($columns) as $key) {
                $csv_row[] = isset($row[$key]) ? $row[$key] : '';
            }
            fputcsv($output, $csv_row);
        }
        
        fclose($output);
    }
}

































