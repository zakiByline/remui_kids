<?php
/**
 * Teacher Report - Tab-specific downloads (Excel/PDF)
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
        // Teacher Summary Tab - Teacher Load Distribution
        $teachers_courses = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email,
                    COUNT(DISTINCT CASE WHEN c.visible = 1 AND c.id > 1 THEN ctx.instanceid END) AS course_count
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {context} ctx ON ctx.id = ra.contextid
             LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = 50
             LEFT JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = cu.companyid
             WHERE cu.companyid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND u.deleted = 0
             AND u.suspended = 0
             GROUP BY u.id, u.firstname, u.lastname, u.email
             ORDER BY course_count DESC, u.lastname ASC, u.firstname ASC",
            [$company_info->id]
        );
        
        $no_load = 0;
        $low_load = 0;
        $medium_load = 0;
        $high_load = 0;
        $total_teachers = count($teachers_courses);
        
        $rows = [];
        foreach ($teachers_courses as $teacher) {
            $course_count = (int)$teacher->course_count;
            
            if ($course_count === 0) {
                $no_load++;
                $load_category = '0 Courses (Not Assigned)';
            } elseif ($course_count >= 1 && $course_count <= 2) {
                $low_load++;
                $load_category = '1-2 Courses';
            } elseif ($course_count >= 3 && $course_count <= 5) {
                $medium_load++;
                $load_category = '3-5 Courses';
            } else {
                $high_load++;
                $load_category = 'More than 5 Courses';
            }
            
            $rows[] = [
                'teacher_name' => fullname($teacher),
                'email' => $teacher->email,
                'courses_assigned' => $course_count,
                'load_category' => $load_category
            ];
        }
        
        $no_load_pct = $total_teachers > 0 ? round(($no_load / $total_teachers) * 100, 1) : 0;
        $low_load_pct = $total_teachers > 0 ? round(($low_load / $total_teachers) * 100, 1) : 0;
        $medium_load_pct = $total_teachers > 0 ? round(($medium_load / $total_teachers) * 100, 1) : 0;
        $high_load_pct = $total_teachers > 0 ? round(($high_load / $total_teachers) * 100, 1) : 0;
        $assigned_pct = $total_teachers > 0 ? round((($low_load + $medium_load + $high_load) / $total_teachers) * 100, 1) : 0;
        
        $columns = [
            'teacher_name' => 'Teacher Name',
            'email' => 'Email',
            'courses_assigned' => 'Courses Assigned',
            'load_category' => 'Load Category'
        ];
        
        $summarycards = [
            ['label' => 'Total Teachers', 'value' => $total_teachers, 'color' => '#2563eb'],
            ['label' => '0 Courses (Not Assigned)', 'value' => $no_load . ' (' . $no_load_pct . '%)', 'color' => '#8b5cf6'],
            ['label' => '1-2 Courses', 'value' => $low_load . ' (' . $low_load_pct . '%)', 'color' => '#6366f1'],
            ['label' => '3-5 Courses', 'value' => $medium_load . ' (' . $medium_load_pct . '%)', 'color' => '#3b82f6'],
            ['label' => 'More than 5 Courses', 'value' => $high_load . ' (' . $high_load_pct . '%)', 'color' => '#ec4899']
        ];
        $title = 'Teacher Summary Report';
        $filename = $company_info->name . ' teacher summary report';
        break;
        
    case 'performance':
    case 'overview':
    case 'activitylog':
    case 'assessment':
    case 'coursewise':
        // For other tabs, return basic placeholder data
        $columns = [
            'teacher' => 'Teacher',
            'data' => 'Data'
        ];
        $rows = [['teacher' => 'Data not available', 'data' => 'This tab download is not yet implemented.']];
        $summarycards = [];
        $title = 'Teacher Report - ' . ucwords(str_replace('_', ' ', $tab));
        $filename = $company_info->name . ' teacher ' . $tab . ' report';
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
        teacher_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on);
    }
} else {
    teacher_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on);
}

exit;

/**
 * Fallback download output function for teacher reports
 */
function teacher_report_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on) {
    if ($format === 'pdf') {
        // PDF generation would go here
        header('Content-Type: text/plain');
        echo "PDF download for teacher reports is not yet fully implemented.\n";
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

































