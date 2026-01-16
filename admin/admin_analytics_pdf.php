<?php
/**
 * PDF Generation for School Analytics
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Start output buffering early to catch any output
if (!ob_get_level()) {
    ob_start();
} else {
    ob_clean();
    ob_start();
}

require_once('../../../config.php');

// Suppress any output from Moodle
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', false);

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $USER, $PAGE, $OUTPUT;

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/admin_analytics_pdf.php');

// Prevent Moodle from outputting HTML headers
$PAGE->set_pagelayout('embedded');

$schoolid = required_param('school', PARAM_INT);
$academicview = optional_param('academicview', 'month', PARAM_ALPHA);
$academicyear = optional_param('academicyear', (int)date('Y'), PARAM_INT);
$chartimagesjson = optional_param('chart_images', '', PARAM_RAW);

$academicview = in_array($academicview, ['month', 'year'], true) ? $academicview : 'month';
$academicyear = ($academicyear < 2000 || $academicyear > ((int)date('Y') + 1)) ? (int)date('Y') : $academicyear;

$chartimages = [];
if (!empty($chartimagesjson)) {
    $decoded = json_decode($chartimagesjson, true);
    if (is_array($decoded)) {
        $chartimages = $decoded;
    }
}

require_once($CFG->libdir . '/pdflib.php');
require_once(__DIR__ . '/admin_analytics.php');

$tables = remuikids_admin_get_iomad_tables();
$school = $DB->get_record($tables['company'], ['id' => $schoolid], 'id, name');
if (!$school) {
    throw new moodle_exception('invalidschool', 'theme_remui_kids');
}

$dashboard = remuikids_admin_collect_school_dashboard($schoolid, $tables, $academicview, $academicyear);

$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetHeaderData('', 0, 'School Performance Analytics', format_string($school->name));
$pdf->setHeaderFont(['helvetica', '', 10]);
$pdf->setFooterFont(['helvetica', '', 8]);
$pdf->SetMargins(15, 25, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetFont('helvetica', '', 9);

// Title page
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 20);
$pdf->Cell(0, 15, 'School Performance Analytics Report', 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, format_string($school->name), 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 8, 'Generated: ' . userdate(time(), get_string('strftimedatefullshort', 'langconfig')), 0, 1, 'C');
$pdf->Cell(0, 8, 'Academic Year: ' . $academicyear . ' | View: ' . ucfirst($academicview), 0, 1, 'C');
$pdf->Ln(10);

// Overview Section
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '1. School Overview', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$overview = $dashboard['overview'] ?? [];
$totalStudents = $overview['totalstudents'] ?? $overview['total_students'] ?? 0;
$totalTeachers = $overview['totalteachers'] ?? $overview['total_teachers'] ?? 0;
$avgGrade = $overview['averagegrade'] ?? $overview['avg_grade'] ?? 0;
$completionRate = $overview['completionrate'] ?? $overview['completion_rate'] ?? 0;
$pdf->Cell(90, 8, 'Total Students: ' . $totalStudents, 1, 0, 'L');
$pdf->Cell(90, 8, 'Total Teachers: ' . $totalTeachers, 1, 1, 'L');
$pdf->Cell(90, 8, 'Average School Grade: ' . $avgGrade . '%', 1, 0, 'L');
$pdf->Cell(90, 8, 'Completion Rate: ' . $completionRate . '%', 1, 1, 'L');
$pdf->Ln(5);

// Academic Trend
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '2. Academic Trend Over Time', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['academicTrendChart'])) {
    $imageData = $chartimages['academicTrendChart'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 60);
    $pdf->Ln(65);
    unlink($tempFile);
}

$academictrend = $dashboard['academictrend']['series'] ?? [];
if (!empty($academictrend)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(40, 8, 'Period', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Assignment %', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Quiz %', 1, 0, 'C');
    $pdf->Cell(50, 8, 'Completion %', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    foreach (array_slice($academictrend, 0, 12) as $trend) {
        $pdf->Cell(40, 7, $trend['label'] ?? '', 1, 0, 'L');
        $pdf->Cell(30, 7, ($trend['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(40, 7, ($trend['assignment_completion'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 7, ($trend['quiz_score'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(50, 7, ($trend['course_completion'] ?? 0) . '%', 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Grade Level Performance
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '3. Performance by Grade Level', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['gradeLevelChart'])) {
    $imageData = $chartimages['gradeLevelChart'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
    $pdf->Ln(55);
    unlink($tempFile);
}

$gradelevels = $dashboard['gradelevels'] ?? [];
if (!empty($gradelevels)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(40, 8, 'Grade', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Participation', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Completion', 1, 0, 'C');
    $pdf->Cell(60, 8, 'Assessments', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    foreach ($gradelevels as $grade) {
        $pdf->Cell(40, 7, $grade['label'] ?? '', 1, 0, 'L');
        $pdf->Cell(30, 7, ($grade['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 7, ($grade['participation'] ?? 0), 1, 0, 'C');
        $pdf->Cell(30, 7, ($grade['completion_rate'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(60, 7, ($grade['assessment_count'] ?? 0), 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Course Insights
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '4. Course Insights', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['courseInsightsChart'])) {
    $imageData = $chartimages['courseInsightsChart'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
    $pdf->Ln(55);
    unlink($tempFile);
}

$courseinsights = $dashboard['courseinsights'] ?? [];
if (!empty($courseinsights)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(80, 8, 'Course', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Views', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Time (min)', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Quiz %', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($courseinsights as $course) {
        $coursename = mb_substr($course['course_name'] ?? '', 0, 35);
        $pdf->Cell(80, 7, $coursename, 1, 0, 'L');
        $pdf->Cell(30, 7, ($course['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 7, ($course['resource_views'] ?? 0), 1, 0, 'C');
        $pdf->Cell(30, 7, ($course['time_spent'] ?? 0), 1, 0, 'C');
        $pdf->Cell(30, 7, ($course['quiz_performance'] ?? 0) . '%', 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Top & Bottom Students
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '6. Student Performance', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 8, 'Top 10 Performing Students', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$topstudents = $dashboard['students']['top'] ?? [];
if (!empty($topstudents)) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(70, 7, 'Name', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Engagement', 1, 0, 'C');
    $pdf->Cell(60, 7, 'Progress', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($topstudents as $student) {
        $pdf->Cell(70, 6, mb_substr($student['name'] ?? '', 0, 30), 1, 0, 'L');
        $pdf->Cell(30, 6, ($student['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 6, ($student['engagement'] ?? 0), 1, 0, 'C');
        $pdf->Cell(60, 6, ($student['course_progress'] ?? 0) . '%', 1, 1, 'C');
    }
}
$pdf->Ln(3);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 8, 'Lowest 10 Performing Students', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$bottomstudents = $dashboard['students']['bottom'] ?? [];
if (!empty($bottomstudents)) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(70, 7, 'Name', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Engagement', 1, 0, 'C');
    $pdf->Cell(60, 7, 'Progress', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($bottomstudents as $student) {
        $pdf->Cell(70, 6, mb_substr($student['name'] ?? '', 0, 30), 1, 0, 'L');
        $pdf->Cell(30, 6, ($student['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 6, ($student['engagement'] ?? 0), 1, 0, 'C');
        $pdf->Cell(60, 6, ($student['course_progress'] ?? 0) . '%', 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Resource Utilization
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '5a. Resource Utilization', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['resourceBarChart'])) {
    $imageData = $chartimages['resourceBarChart'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
    $pdf->Ln(55);
    unlink($tempFile);
}

$resources = $dashboard['resources'] ?? [];
$allTypes = $resources['all_types'] ?? [];
if (!empty($allTypes)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(100, 8, 'Resource Type', 1, 0, 'C');
    $pdf->Cell(90, 8, 'View Count', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    foreach ($allTypes as $resource) {
        $pdf->Cell(100, 7, $resource['type'] ?? '', 1, 0, 'L');
        $pdf->Cell(90, 7, ($resource['count'] ?? 0), 1, 1, 'C');
    }
}
$pdf->Ln(3);

$topResources = $resources['top'] ?? [];
if (!empty($topResources)) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Top 3 Resources', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(100, 7, 'Resource', 1, 0, 'C');
    $pdf->Cell(50, 7, 'Views', 1, 0, 'C');
    $pdf->Cell(40, 7, 'Users', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($topResources as $resource) {
        $resourceName = mb_substr(($resource['type'] ?? '') . ' - ' . ($resource['course'] ?? ''), 0, 45);
        $pdf->Cell(100, 6, $resourceName, 1, 0, 'L');
        $pdf->Cell(50, 6, ($resource['views'] ?? 0), 1, 0, 'C');
        $pdf->Cell(40, 6, ($resource['users'] ?? 0), 1, 1, 'C');
    }
}
$pdf->Ln(3);

$leastResources = $resources['least'] ?? [];
if (!empty($leastResources)) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Least 3 Resources', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(100, 7, 'Resource', 1, 0, 'C');
    $pdf->Cell(50, 7, 'Views', 1, 0, 'C');
    $pdf->Cell(40, 7, 'Course', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($leastResources as $resource) {
        $resourceName = mb_substr(($resource['type'] ?? ''), 0, 45);
        $courseName = mb_substr(($resource['course'] ?? ''), 0, 35);
        $pdf->Cell(100, 6, $resourceName, 1, 0, 'L');
        $pdf->Cell(50, 6, ($resource['views'] ?? 0), 1, 0, 'C');
        $pdf->Cell(40, 6, $courseName, 1, 1, 'L');
    }
}
$pdf->Ln(5);

// Teacher Effectiveness
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '5b. Teacher Effectiveness', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['teacherRadar'])) {
    $imageData = $chartimages['teacherRadar'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
    $pdf->Ln(55);
    unlink($tempFile);
}

$teachers = $dashboard['teachers'] ?? [];
$teacherLeaders = $teachers['leaders'] ?? [];
if (!empty($teacherLeaders)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(80, 8, 'Teacher', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Score', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Student Perf', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Activity', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($teacherLeaders as $teacher) {
        $teacherName = mb_substr($teacher['name'] ?? '', 0, 35);
        $pdf->Cell(80, 6, $teacherName, 1, 0, 'L');
        $pdf->Cell(30, 6, ($teacher['score'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(40, 6, ($teacher['student_performance'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(40, 6, ($teacher['activity_creation'] ?? 0), 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Course Completion
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '7. Course Completion Trends', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Add chart image if available
if (!empty($chartimages['courseCompletionChart'])) {
    $imageData = $chartimages['courseCompletionChart'];
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
    file_put_contents($tempFile, base64_decode($imageData));
    $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
    $pdf->Ln(55);
    unlink($tempFile);
}

$coursecompletions = $dashboard['coursecompletions']['percourse'] ?? [];
if (!empty($coursecompletions)) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(70, 7, 'Course', 1, 0, 'C');
    $pdf->Cell(50, 7, 'Teachers', 1, 0, 'C');
    $pdf->Cell(25, 7, 'Completed', 1, 0, 'C');
    $pdf->Cell(25, 7, 'Total', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Rate', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 6);
    foreach ($coursecompletions as $completion) {
        $coursename = mb_substr($completion['name'] ?? '', 0, 35);
        $teachers = $completion['teachers'] ?? [];
        $teacherList = !empty($teachers) ? implode(', ', array_slice($teachers, 0, 2)) : 'No teachers';
        if (count($teachers) > 2) {
            $teacherList .= ' +' . (count($teachers) - 2) . ' more';
        }
        $pdf->Cell(70, 6, $coursename, 1, 0, 'L');
        $pdf->Cell(50, 6, mb_substr($teacherList, 0, 30), 1, 0, 'L');
        $pdf->Cell(25, 6, ($completion['completed'] ?? 0), 1, 0, 'C');
        $pdf->Cell(25, 6, ($completion['total'] ?? 0), 1, 0, 'C');
        $pdf->Cell(30, 6, ($completion['rate'] ?? 0) . '%', 1, 1, 'C');
    }
}
$pdf->Ln(5);

// Competencies
$competencies = $dashboard['competencies'] ?? [];
if (!empty($competencies) && ($competencies['enabled'] ?? false)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, '8. Competencies Details', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    
    // Add competency charts if available
    if (!empty($chartimages['competenciesStatusChart'])) {
        $imageData = $chartimages['competenciesStatusChart'];
        if (strpos($imageData, 'data:image') === 0) {
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
        file_put_contents($tempFile, base64_decode($imageData));
        $pdf->Image($tempFile, 15, $pdf->GetY(), 85, 50);
        unlink($tempFile);
    }
    
    if (!empty($chartimages['competenciesFrameworkChart'])) {
        $imageData = $chartimages['competenciesFrameworkChart'];
        if (strpos($imageData, 'data:image') === 0) {
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
        file_put_contents($tempFile, base64_decode($imageData));
        $pdf->Image($tempFile, 105, $pdf->GetY(), 85, 50);
        $pdf->Ln(55);
        unlink($tempFile);
    } else {
        $pdf->Ln(5);
    }
    
    if (!empty($chartimages['competenciesCourseChart'])) {
        $imageData = $chartimages['competenciesCourseChart'];
        if (strpos($imageData, 'data:image') === 0) {
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
        file_put_contents($tempFile, base64_decode($imageData));
        $pdf->Image($tempFile, 15, $pdf->GetY(), 180, 50);
        $pdf->Ln(55);
        unlink($tempFile);
    }
    $summary = $competencies['summary'] ?? [];
    $pdf->Cell(90, 8, 'Total Frameworks: ' . ($summary['total_frameworks'] ?? 0), 1, 0, 'L');
    $pdf->Cell(90, 8, 'Total Assigned: ' . ($summary['total_assigned'] ?? 0), 1, 1, 'L');
    $pdf->Cell(90, 8, 'Total Achieved: ' . ($summary['total_achieved'] ?? 0), 1, 0, 'L');
    $pdf->Cell(90, 8, 'Total Pending: ' . ($summary['total_pending'] ?? 0), 1, 1, 'L');
    $pdf->Cell(0, 8, 'Achievement Rate: ' . ($summary['overall_achievement_rate'] ?? 0) . '%', 1, 1, 'L');
    $pdf->Ln(3);
    $frameworks = $competencies['frameworks'] ?? [];
    if (!empty($frameworks)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(100, 7, 'Framework Name', 1, 0, 'C');
        $pdf->Cell(80, 7, 'ID Number', 1, 1, 'C');
        $pdf->SetFont('helvetica', '', 8);
        foreach ($frameworks as $framework) {
            $pdf->Cell(100, 6, mb_substr($framework['name'] ?? '', 0, 40), 1, 0, 'L');
            $pdf->Cell(80, 6, mb_substr($framework['idnumber'] ?? '-', 0, 30), 1, 1, 'L');
        }
        $pdf->Ln(3);
    }
    $percourse = $competencies['percourse'] ?? [];
    if (!empty($percourse)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(60, 7, 'Course', 1, 0, 'C');
        $pdf->Cell(50, 7, 'Frameworks', 1, 0, 'C');
        $pdf->Cell(20, 7, 'Assigned', 1, 0, 'C');
        $pdf->Cell(20, 7, 'Achieved', 1, 0, 'C');
        $pdf->Cell(20, 7, 'Pending', 1, 0, 'C');
        $pdf->Cell(20, 7, 'Rate', 1, 1, 'C');
        $pdf->SetFont('helvetica', '', 7);
        foreach ($percourse as $comp) {
            $coursename = mb_substr($comp['course_name'] ?? '', 0, 60);
            $frameworkLines = [];
            foreach ($comp['frameworks'] ?? [] as $fw) {
                $count = count($fw['competencies'] ?? []);
                $frameworkLines[] = format_string($fw['framework_name'] ?? '') . ' (' . $count . ')';
            }
            $frameworkText = !empty($frameworkLines) ? implode("\n", $frameworkLines) : '-';
            $lineCount = max(1, count($frameworkLines));
            $rowHeight = 6 * $lineCount;
            $pdf->MultiCell(60, $rowHeight, $coursename, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(50, $rowHeight, $frameworkText, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(20, $rowHeight, ($comp['total_assigned'] ?? 0), 1, 'C', 0, 0, '', '', true);
            $pdf->MultiCell(20, $rowHeight, ($comp['total_achieved'] ?? 0), 1, 'C', 0, 0, '', '', true);
            $pdf->MultiCell(20, $rowHeight, ($comp['total_pending'] ?? 0), 1, 'C', 0, 0, '', '', true);
            $pdf->MultiCell(20, $rowHeight, ($comp['achievement_rate'] ?? 0) . '%', 1, 'C', 0, 1, '', '', true);
        }
    }
    $pdf->Ln(5);
}

// System Usage Statistics
$systemusage = $dashboard['systemusage'] ?? [];
if (!empty($systemusage)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, '9. System Usage Statistics', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);

    $usageCharts = [
        'systemUsageOverallChart' => ['width' => 180, 'height' => 45],
        'systemUsageCohortChart' => ['width' => 180, 'height' => 45],
        'systemUsagePeakHoursChart' => ['width' => 180, 'height' => 45],
        'systemUsagePeakDaysChart' => ['width' => 180, 'height' => 45],
    ];

    foreach ($usageCharts as $chartId => $dimensions) {
        if (!empty($chartimages[$chartId])) {
            $imageData = $chartimages[$chartId];
            if (strpos($imageData, 'data:image') === 0) {
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
            }
            $tempFile = tempnam(sys_get_temp_dir(), 'chart_');
            file_put_contents($tempFile, base64_decode($imageData));
            $pdf->Image($tempFile, 15, $pdf->GetY(), $dimensions['width'], $dimensions['height']);
            $pdf->Ln($dimensions['height'] + 5);
            unlink($tempFile);
        }
    }

    $overall = $systemusage['overall'] ?? [];
    if (!empty($overall)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Overall Usage Metrics', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(95, 8, 'Total Logins: ' . ($overall['total_logins'] ?? 0), 1, 0, 'L');
        $pdf->Cell(95, 8, 'Total Page Views: ' . ($overall['total_page_views'] ?? 0), 1, 1, 'L');
        $pdf->Cell(95, 8, 'Active Users: ' . ($overall['unique_active_users'] ?? 0), 1, 0, 'L');
        $pdf->Cell(95, 8, 'Avg Logins/User: ' . ($overall['avg_logins_per_user'] ?? 0), 1, 1, 'L');
        $pdf->Cell(95, 8, 'Time Spent (hrs): ' . ($overall['total_time_spent'] ?? 0), 1, 0, 'L');
        $pdf->Cell(95, 8, 'Avg Session Duration (mins): ' . ($overall['avg_session_duration'] ?? 0), 1, 1, 'L');
        $pdf->Cell(95, 8, 'Active Days: ' . ($overall['active_days'] ?? 0), 1, 0, 'L');
        $pdf->Cell(95, 8, '', 1, 1, 'L');
        $pdf->Ln(3);
    }

    $cohorts = $systemusage['cohort_wise'] ?? [];
    if (!empty($cohorts)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Cohort-wise Usage', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(60, 7, 'Cohort', 1, 0, 'C');
        $pdf->Cell(25, 7, 'Students', 1, 0, 'C');
        $pdf->Cell(25, 7, 'Active Users', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Views', 1, 0, 'C');
        $pdf->Cell(25, 7, 'Active Days', 1, 0, 'C');
        $pdf->Cell(25, 7, 'Views/User', 1, 1, 'C');
        $pdf->SetFont('helvetica', '', 7);
        foreach ($cohorts as $cohort) {
            $pdf->Cell(60, 6, mb_substr($cohort['cohort_name'] ?? '', 0, 30), 1, 0, 'L');
            $pdf->Cell(25, 6, ($cohort['student_count'] ?? 0), 1, 0, 'C');
            $pdf->Cell(25, 6, ($cohort['active_users'] ?? 0), 1, 0, 'C');
            $pdf->Cell(30, 6, ($cohort['total_views'] ?? 0), 1, 0, 'C');
            $pdf->Cell(25, 6, ($cohort['active_days'] ?? 0), 1, 0, 'C');
            $pdf->Cell(25, 6, ($cohort['avg_views_per_user'] ?? 0), 1, 1, 'C');
        }
        $pdf->Ln(3);
    }

    $performance = $systemusage['performance'] ?? [];
    if (!empty($performance)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Technical Performance', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(63, 8, 'Total Requests: ' . ($performance['total_requests'] ?? 0), 1, 0, 'L');
        $pdf->Cell(63, 8, 'Error Rate: ' . ($performance['error_rate'] ?? 0) . '%', 1, 0, 'L');
        $pdf->Cell(64, 8, 'Avg Response Time: ' . ($performance['avg_response_time'] ?? 0) . 'ms', 1, 1, 'L');
        $pdf->Ln(5);
    }
}

// Early Warning System
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '10. Early Warning System', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$earlywarnings = $dashboard['earlywarnings'] ?? [];
if (!empty($earlywarnings)) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(50, 7, 'Student', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Avg Grade', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Engagement', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Progress', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Risk', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Flags', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 7);
    foreach ($earlywarnings as $warning) {
        $pdf->Cell(50, 6, mb_substr($warning['name'] ?? '', 0, 20), 1, 0, 'L');
        $pdf->Cell(30, 6, ($warning['avg_grade'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 6, ($warning['engagement'] ?? 0), 1, 0, 'C');
        $pdf->Cell(30, 6, ($warning['course_progress'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 6, ($warning['risk'] ?? 0) . '%', 1, 0, 'C');
        $pdf->Cell(30, 6, mb_substr($warning['flags'] ?? '', 0, 15), 1, 1, 'L');
    }
}

// Clear all output buffers
while (ob_get_level()) {
    ob_end_clean();
}

$filename = 'school-analytics-' . format_string($school->name, true, ['context' => $context]) . '-' . date('Y-m-d') . '.pdf';
$filename = clean_filename($filename);

// Set proper headers for PDF download (must be before any output)
if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Transfer-Encoding: binary');
}

// Output PDF directly - 'D' forces download
$pdf->Output($filename, 'D');
exit;

