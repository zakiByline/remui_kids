<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Super Admin Reports - Export functionality
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/admin/superreports/lib.php');

require_login();
require_sesskey();

global $DB, $CFG;

// Verify admin access
if (!is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'access super admin reports');
}

// Get parameters
$tab = required_param('tab', PARAM_ALPHANUMEXT); // Allow hyphens in tab names
$format = required_param('format', PARAM_ALPHA);
$schoolid = optional_param('school', 0, PARAM_INT);
$daterange = optional_param('daterange', 'month', PARAM_ALPHA);
$startdate = optional_param('startdate', '', PARAM_TEXT);
$enddate = optional_param('enddate', '', PARAM_TEXT);

// Get data based on tab
$data = [];
$headers = [];
$gradeid = optional_param('grade', '', PARAM_TEXT);
$filename = 'superadmin_report_' . $tab . '_' . date('Y-m-d');

switch ($tab) {
    case 'overview':
        $stats = superreports_get_overview_stats($schoolid, $daterange, $startdate, $enddate, $gradeid);
        $headers = ['Metric', 'Value'];
        $data = [
            ['Total Schools', $stats['total_schools']],
            ['Total Teachers', $stats['total_teachers']],
            ['Total Students', $stats['total_students']],
            ['Avg Course Completion', $stats['avg_completion'] . '%'],
            ['Total Courses', $stats['total_courses']],
            ['Active Users', $stats['active_users']]
        ];
        break;
        
    case 'teachers':
        $teachers = superreports_get_teacher_report($schoolid, $daterange, $startdate, $enddate, $gradeid);
        $headers = ['Name', 'Email', 'Courses', 'Avg Grade', 'Activities', 'Last Login'];
        foreach ($teachers as $teacher) {
            $data[] = [
                $teacher['name'],
                $teacher['email'],
                $teacher['courses'],
                $teacher['avg_grade'] . '%',
                $teacher['activities'],
                $teacher['last_login']
            ];
        }
        break;
        
    case 'students':
        $students = superreports_get_student_report($schoolid, $daterange, $startdate, $enddate, $gradeid);
        $headers = ['Name', 'Email', 'Enrolled', 'Avg Grade', 'Completion', 'Status'];
        foreach ($students as $student) {
            $data[] = [
                $student['name'],
                $student['email'],
                $student['enrolled'],
                $student['avg_grade'] . '%',
                $student['completion'] . '%',
                $student['status']
            ];
        }
        break;
        
    case 'assignments':
        $assignments = superreports_get_assignments_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
        $headers = ['Metric', 'Value'];
        $data = [
            ['Total Assignments', $assignments['total_assignments']],
            ['Completion Rate', $assignments['completion_rate'] . '%'],
            ['Average Grade', $assignments['avg_grade'] . '%'],
            ['Total Submissions', $assignments['total_submissions']]
        ];
        break;
        
    case 'quizzes':
        $quizzes = superreports_get_quizzes_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
        $headers = ['Metric', 'Value'];
        $data = [
            ['Total Quizzes', $quizzes['total_quizzes']],
            ['Average Score', $quizzes['avg_score'] . '%'],
            ['Avg Attempts/Student', $quizzes['avg_attempts_per_student']],
            ['Total Attempts', $quizzes['total_attempts']]
        ];
        break;
        
    case 'overall-grades':
        $grades = superreports_get_overall_grades($schoolid, $gradeid, $daterange, $startdate, $enddate);
        $headers = ['Rank', 'Student', 'Avg Grade', 'Courses Completed'];
        foreach ($grades['top_students'] as $student) {
            $data[] = [
                $student['rank'],
                $student['name'],
                $student['avg_grade'] . '%',
                $student['completed']
            ];
        }
        break;
        
    case 'teacher-performance':
        $teachers = superreports_get_teacher_performance($schoolid, $daterange, $startdate, $enddate);
        $headers = ['Teacher', 'Courses', 'Current Engagement', 'Previous Period', 'Change (%)'];
        foreach ($teachers as $teacher) {
            $data[] = [
                $teacher['name'],
                $teacher['courses'],
                $teacher['engagement'],
                $teacher['prev_engagement'],
                $teacher['change'] . '%'
            ];
        }
        break;
        
    case 'student-performance':
        $students = superreports_get_student_performance_detailed($schoolid, $gradeid, $daterange, $startdate, $enddate);
        $headers = ['Student', 'Enrolled', 'Avg Grade', 'Completion', 'Status'];
        foreach ($students as $student) {
            $data[] = [
                $student['name'],
                $student['enrolled'],
                $student['avg_grade'] . '%',
                $student['completion'] . '%',
                $student['status']
            ];
        }
        break;
        
    case 'courses':
        $courses = superreports_get_course_report($schoolid);
        $headers = ['Course Name', 'Short Name', 'Enrolled', 'Completion', 'Avg Grade', 'Last Update'];
        foreach ($courses as $course) {
            $data[] = [
                $course['name'],
                $course['shortname'],
                $course['enrolled'],
                $course['completion'] . '%',
                $course['avg_grade'] . '%',
                $course['last_update']
            ];
        }
        break;
        
    default:
        $headers = ['Info'];
        $data = [['No data available for this tab']];
}

// Export based on format
switch ($format) {
    case 'csv':
        export_csv($filename, $headers, $data);
        break;
        
    case 'excel':
        export_excel($filename, $headers, $data);
        break;
        
    case 'pdf':
        export_pdf($filename, $headers, $data, $tab);
        break;
        
    default:
        throw new moodle_exception('invalidformat', 'error');
}

/**
 * Export data as CSV
 */
function export_csv($filename, $headers, $data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

/**
 * Export data as Excel (HTML table that Excel can read)
 */
function export_excel($filename, $headers, $data) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    
    // Headers
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr></thead>';
    
    // Data
    echo '<tbody>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

/**
 * Export data as PDF
 */
function export_pdf($filename, $headers, $data, $tab) {
    // Basic HTML to PDF conversion
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // For now, we'll create a simple HTML page
    // In production, you might want to use a proper PDF library like TCPDF or mPDF
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            h1 { color: #2c3e50; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #3498db; color: white; padding: 10px; text-align: left; }
            td { padding: 8px; border-bottom: 1px solid #ddd; }
        </style>
    </head>
    <body>
        <h1>Super Admin Report: ' . ucfirst($tab) . '</h1>
        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
        <table>
            <thead><tr>';
    
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    
    $html .= '</tr></thead><tbody>';
    
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table></body></html>';
    
    // For simple PDF generation, we'll output HTML
    // A proper implementation would use a PDF library
    header('Content-Type: text/html');
    header('Content-Disposition: inline; filename="' . $filename . '.html"');
    echo $html;
    exit;
}

