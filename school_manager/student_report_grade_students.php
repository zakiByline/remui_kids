<?php
/**
 * Student Report - Grade Students Page
 * Shows all students for a specific grade level with sidebar, pagination, and search
 */

// Set error handling to catch any issues early
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Try to load config with error handling
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR: Configuration file not found at: ' . $config_path);
}

try {
    require_once($config_path);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR: Failed to load configuration: ' . $e->getMessage());
}

// Check if Moodle functions are available
if (!function_exists('require_login')) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR: Moodle not properly initialized. require_login function not found.');
}

try {
    require_login();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR: Login required: ' . $e->getMessage());
}

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$grade = optional_param('grade', '', PARAM_TEXT);
$grade = trim($grade); // Clean the grade parameter
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$per_page = optional_param('per_page', 10, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_BOOL);

// Validate per_page
if (!in_array($per_page, [10, 25, 50, 100])) {
    $per_page = 10;
}

$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get all students for the specified grade (for filtering and pagination)
// Use the same approach as academic report: get all students, then filter by grade in PHP
// This ensures we match exactly how the academic report groups students
$all_students_data = [];
$where_conditions = [
    "cu.companyid = ?",
    "r.shortname = 'student'",
    "u.deleted = 0",
    "u.suspended = 0"
];
$params = [$company_info->id];

// Normalize the grade parameter for matching (same as academic report uses trim())
$grade_normalized = $grade ? trim($grade) : '';

$where_sql = implode(' AND ', $where_conditions);

// Validate grade parameter - allow empty for now, will filter later
// if (empty($grade)) {
//     redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/student_report.php?tab=academic', 'Grade parameter is required.', null, \core\output\notification::NOTIFY_ERROR);
// }

// Apply search filter if provided
if ($search) {
    $search_where = " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
    $search_param = '%' . $search . '%';
    $search_params = [$search_param, $search_param, $search_param];
} else {
    $search_where = '';
    $search_params = [];
}

// Get all students first (same query as academic report), then filter by grade in PHP
// This ensures we match exactly how the academic report groups students
try {
    // Get all students with grade data (same as academic report)
    $all_students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, uifd.data as grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE " . $where_sql . $search_where . "
         ORDER BY u.lastname ASC, u.firstname ASC",
        array_merge($params, $search_params)
    );
    
    // Filter by grade in PHP (same logic as academic report)
    // Use exact match to avoid "Grade 1" matching "Grade 10" or "Grade 11"
    $filtered_students = [];
    if ($grade && $grade_normalized) {
        foreach ($all_students as $student) {
            $student_grade = trim($student->grade_level ?? '');
            // Use exact match (case-insensitive) to ensure "Grade 1" doesn't match "Grade 10" or "Grade 11"
            // This matches how the academic report groups students by grade_key (trim comparison)
            if ($student_grade && strcasecmp(trim($student_grade), $grade_normalized) === 0) {
                $filtered_students[] = $student;
            }
        }
    } else {
        $filtered_students = $all_students;
    }
    
    $total_count = count($filtered_students);
    
    error_log("=== Grade Students Query Debug ===");
    error_log("Company ID: " . $company_info->id);
    error_log("Grade parameter: '" . $grade . "' (normalized: '" . $grade_normalized . "')");
    error_log("Total students before grade filter: " . count($all_students));
    error_log("Total students after grade filter: " . $total_count);
    
    // Debug: show sample grades and verify filtering
    if ($total_count > 0) {
        $sample_grades_in_results = [];
        foreach (array_slice($filtered_students, 0, 10) as $s) {
            $g = trim($s->grade_level ?? '');
            if ($g && !in_array($g, $sample_grades_in_results)) {
                $sample_grades_in_results[] = $g;
            }
        }
        error_log("Sample grade values in filtered results: " . print_r($sample_grades_in_results, true));
    } elseif ($grade) {
        // If no students found, show all available grades for debugging
        $sample_grades = [];
        foreach ($all_students as $s) {
            $g = trim($s->grade_level ?? '');
            if ($g && !in_array($g, $sample_grades)) {
                $sample_grades[] = $g;
            }
        }
        error_log("Sample grade values found in all students: " . print_r($sample_grades, true));
    }
} catch (dml_exception $e) {
    error_log("Error counting students: " . $e->getMessage());
    if (method_exists($e, 'getDebugInfo')) {
        error_log("SQL Error: " . $e->getDebugInfo());
    }
    $total_count = 0;
    $filtered_students = [];
} catch (Exception $e) {
    error_log("Unexpected error counting students: " . $e->getMessage());
    $total_count = 0;
    $filtered_students = [];
}

// Apply pagination to filtered students
$offset = ($page - 1) * $per_page;
$students = array_slice($filtered_students, $offset, $per_page);

error_log("Students after pagination: " . count($students) . " (offset: " . $offset . ", per_page: " . $per_page . ")");
if (count($students) > 0) {
    $first_student = reset($students);
    error_log("First student: " . $first_student->firstname . " " . $first_student->lastname . ", grade_level: " . ($first_student->grade_level ?? 'NULL'));
}

// Process each student's data
foreach ($students as $student) {
    $grade_level = $student->grade_level ?? 'Grade Level';
    
    // Get average course grade
    try {
        $avg_grade = $DB->get_record_sql(
            "SELECT AVG(CASE WHEN gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND gi.grademax > 0
                THEN (gg.finalgrade / gi.grademax * 100) ELSE NULL END) as avg_grade
             FROM {user_enrolments} ue
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {course} c ON c.id = e.courseid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             LEFT JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ue.userid
             WHERE ue.userid = ? AND cc.companyid = ? AND ue.status = 0 AND c.visible = 1 AND c.id > 1",
            [$student->id, $company_info->id]
        );
    } catch (Exception $e) {
        error_log("Error getting avg grade for student {$student->id}: " . $e->getMessage());
        $avg_grade = null;
    }

    // Get quiz scores
    try {
        $quiz_scores = $DB->get_records_sql(
            "SELECT qa.sumgrades, q.sumgrades as maxgrade
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE qa.userid = ? AND cc.companyid = ? AND qa.preview = 0 
             AND qa.state = 'finished' AND qa.timefinish > 0 AND q.sumgrades > 0",
            [$student->id, $company_info->id]
        );
    } catch (Exception $e) {
        error_log("Error getting quiz scores for student {$student->id}: " . $e->getMessage());
        $quiz_scores = [];
    }

    // Get assignment scores
    try {
        $assignment_scores = $DB->get_records_sql(
            "SELECT ag.grade, a.grade as maxgrade
             FROM {assign_grades} ag
             INNER JOIN {assign} a ON a.id = ag.assignment
             INNER JOIN {course} c ON c.id = a.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ag.userid = ? AND cc.companyid = ? AND ag.grade IS NOT NULL AND a.grade > 0",
            [$student->id, $company_info->id]
        );
    } catch (Exception $e) {
        error_log("Error getting assignment scores for student {$student->id}: " . $e->getMessage());
        $assignment_scores = [];
    }

    // Calculate performance score from all sources
    $all_scores = [];
    if ($avg_grade && $avg_grade->avg_grade !== null) {
        $all_scores[] = (float)$avg_grade->avg_grade;
    }
    foreach ($quiz_scores as $qs) {
        if ($qs->maxgrade > 0) {
            $all_scores[] = ($qs->sumgrades / $qs->maxgrade) * 100;
        }
    }
    foreach ($assignment_scores as $as) {
        if ($as->maxgrade > 0) {
            $all_scores[] = ($as->grade / $as->maxgrade) * 100;
        }
    }

    $performance_score = !empty($all_scores) ? round(array_sum($all_scores) / count($all_scores), 1) : null;
    $highest_score = !empty($all_scores) ? round(max($all_scores), 1) : 0;
    $lowest_score = !empty($all_scores) ? round(min($all_scores), 1) : 0;

    // Get course completion rate
    try {
        $total_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {user_enrolments} ue ON ue.userid = ?
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ? AND ue.status = 0 AND c.visible = 1 AND c.id > 1",
            [$student->id, $company_info->id]
        );
    } catch (Exception $e) {
        error_log("Error getting total courses for student {$student->id}: " . $e->getMessage());
        $total_courses = 0;
    }

    try {
        $completed_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.course)
             FROM {course_completions} cc
             INNER JOIN {user_enrolments} ue ON ue.userid = cc.userid
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
             INNER JOIN {company_course} cc_link ON cc_link.courseid = cc.course
             WHERE cc.userid = ? AND cc_link.companyid = ? AND cc.timecompleted IS NOT NULL AND ue.status = 0",
            [$student->id, $company_info->id]
        );
    } catch (Exception $e) {
        error_log("Error getting completed courses for student {$student->id}: " . $e->getMessage());
        $completed_courses = 0;
    }

    $completion_rate = $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0;

    $all_students_data[] = [
        'id' => $student->id,
        'name' => fullname($student),
        'email' => $student->email,
        'grade_level' => $grade_level,
        'avg_grade' => $performance_score,
        'highest_score' => $highest_score,
        'lowest_score' => $lowest_score,
        'completion_rate' => $completion_rate,
        'total_courses' => $total_courses,
        'completed_courses' => $completed_courses
    ];
}

// Calculate pagination
$total_pages = ceil($total_count / $per_page);
$start_record = ($page - 1) * $per_page + 1;
$end_record = min($start_record + $per_page - 1, $total_count);

// Sidebar context
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'student_repo',
    'student_report_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Student Performance Report - ' . htmlspecialchars($grade));
$PAGE->set_heading('Student Performance Report');

if ($ajax) {
    ob_start();
    include(__DIR__ . '/student_report_grade_students_table.php');
    $table_html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $table_html,
        'total' => $total_count,
        'page' => $page,
        'per_page' => $per_page,
        'start' => $start_record,
        'end' => $end_record
    ]);
    exit;
}

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

html, body {
    overflow: hidden;
    margin: 0;
    padding: 0;
    height: 100vh;
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
}

.school-manager-sidebar {
    position: fixed !important;
    top: 55px !important;
    left: 0 !important;
    width: 280px !important;
    height: calc(100vh - 55px) !important;
    background: linear-gradient(180deg, #2C3E50 0%, #34495E 100%) !important;
    z-index: 5000 !important;
    overflow-y: auto !important;
    visibility: visible !important;
    display: block !important;
}

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    overflow-x: hidden;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
    padding: 20px;
    box-sizing: border-box;
}

.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 0 20px;
    overflow-x: hidden;
}

.page-header {
    background: linear-gradient(135deg, #e0bbe4 0%, #a7dbd8 100%);
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(167, 219, 216, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 30px;
}

.page-header-text {
    flex: 1;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 10px 0;
    color: #36454f;
}

.page-subtitle {
    font-size: 1.1rem;
    margin: 0;
    color: #696969;
}

.grade-students-page-header {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.grade-students-page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.grade-students-page-title i {
    color: #3b82f6;
}

.grade-students-back-btn {
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    color: #1f2937;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.grade-students-back-btn:hover {
    background: #e5e7eb;
    color: #1f2937;
    text-decoration: none;
}

.grade-students-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.grade-students-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.grade-students-search-container {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.grade-students-search-input {
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    min-width: 300px;
}

.grade-students-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.grade-students-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.grade-students-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.grade-students-search-btn {
    padding: 10px 20px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.grade-students-search-btn:hover {
    background: #2563eb;
}

.grade-students-clear-btn {
    padding: 10px 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: 6px;
}

.grade-students-clear-btn:hover {
    background: #dc2626;
}

.grade-students-clear-btn.visible {
    display: flex;
}

.grade-students-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.grade-students-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    min-width: 1000px;
}

.grade-students-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.grade-students-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.grade-students-table th.center {
    text-align: center;
}

.grade-students-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
}

.grade-students-table tbody tr:hover {
    background: #f9fafb;
}

.grade-students-table td {
    padding: 12px;
    color: #1f2937;
}

.grade-students-table td.center {
    text-align: center;
}

.grade-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.grade-badge.success {
    background: #d1fae5;
    color: #065f46;
}

.grade-badge.warning {
    background: #fee2e2;
    color: #991b1b;
}

.grade-students-empty {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.grade-students-empty i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
    display: block;
}

.grade-students-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.grade-students-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.grade-students-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.grade-students-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}

.grade-students-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
    text-decoration: none;
    color: #1f2937;
}

.grade-students-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.grade-students-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.grade-students-page-number {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 40px;
    text-align: center;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}

.grade-students-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
    text-decoration: none;
    color: #1f2937;
}

.grade-students-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.grade-students-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.grade-students-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
    }
    
    .grade-students-table-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .grade-students-search-container {
        width: 100%;
    }
    
    .grade-students-search-input {
        min-width: 100%;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-text">
                <h1 class="page-title">Student Report</h1>
                <p class="page-subtitle">Comprehensive overview of student statistics and performance for <?php echo htmlspecialchars($company_info ? $company_info->name : 'the school'); ?>.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; background: rgba(255, 255, 255, 0.9); padding: 12px 18px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                <span style="font-size: 0.85rem; white-space: nowrap;">Download as</span>
                <select style="min-width: 140px; font-size: 0.85rem; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: white;">
                    <option>CSV</option>
                    <option>PDF</option>
                    <option>Excel</option>
                </select>
                <button style="font-size: 0.85rem; padding: 6px 14px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
        </div>

        <!-- Grade Students Header -->
        <div class="grade-students-page-header">
            <h1 class="grade-students-page-title">
                <i class="fa fa-users"></i> <?php echo htmlspecialchars($grade); ?> - Student Performance Report
            </h1>
            <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'academic']))->out(false); ?>" class="grade-students-back-btn">
                <i class="fa fa-arrow-left"></i> Back to Academic Report
            </a>
        </div>

        <!-- Table Card -->
        <div class="grade-students-table-card" id="grade-students-content-wrapper">
        <div class="grade-students-table-card" id="grade-students-content-wrapper">
            <div class="grade-students-table-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h3 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa fa-table" style="color: #3b82f6;"></i> Student List
                    </h3>
                    <span id="grade-students-count" style="font-size: 0.9rem; color: #6b7280;">(<?php echo $total_count; ?> students)</span>
                </div>
                <div class="grade-students-search-container">
                    <form method="get" action="" id="grade-students-search-form" style="display: flex; gap: 10px; align-items: center;">
                        <input type="hidden" name="grade" value="<?php echo htmlspecialchars($grade); ?>">
                        <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
                        <div class="grade-students-search-wrapper">
                            <i class="fa fa-search grade-students-search-icon"></i>
                            <input type="text" 
                                   name="search" 
                                   id="grade-students-search-input"
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search by name or email..." 
                                   class="grade-students-search-input"
                                   autocomplete="off">
                        </div>
                        <?php if ($search): ?>
                        <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'per_page' => $per_page]))->out(false); ?>" 
                           class="grade-students-clear-btn visible">
                            <i class="fa fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div id="grade-students-table-container">
                <?php if (!empty($all_students_data)): ?>
                <?php include(__DIR__ . '/student_report_grade_students_table.php'); ?>
                <?php else: ?>
                <div class="grade-students-empty">
                    <i class="fa fa-info-circle"></i>
                    <h4>No students found</h4>
                    <p><?php echo $search ? 'No students found matching your search criteria.' : 'No students found for grade: ' . htmlspecialchars($grade); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // Auto-search functionality with AJAX (no page refresh)
    const searchInput = document.getElementById('grade-students-search-input');
    const searchForm = document.getElementById('grade-students-search-form');
    const tableContainer = document.getElementById('grade-students-table-container');
    const studentCount = document.getElementById('grade-students-count');
    const contentWrapper = document.getElementById('grade-students-content-wrapper');
    const gradeParam = <?php echo json_encode($grade); ?>;
    let currentPerPage = <?php echo (int)$per_page; ?>;
    let currentPage = <?php echo (int)$page; ?>;
    let searchTimeout = null;
    let isLoading = false;
    
    function renderLoadingState() {
        if (tableContainer) {
            tableContainer.innerHTML = '<div class="grade-students-loading" style="text-align:center;padding:40px;color:#6b7280;"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
        }
    }
    
    function updateContent(searchTerm, page = 1, perPage = currentPerPage) {
        if (isLoading || !tableContainer) {
            return;
        }
        
        isLoading = true;
        currentPage = page;
        currentPerPage = perPage;
        
        renderLoadingState();
        
        const url = new URL(window.location.href);
        url.searchParams.set('search', searchTerm);
        url.searchParams.set('page', page);
        url.searchParams.set('per_page', perPage);
        url.searchParams.set('grade', gradeParam);
        url.searchParams.set('ajax', '1');
        
        fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error('Failed to load data');
                }
                
                tableContainer.innerHTML = data.html;
                
                if (studentCount) {
                    studentCount.textContent = `(${data.total} students)`;
                }
                
                attachPaginationListeners();
                attachPerPageListener();
                
                const newUrl = new URL(window.location.href);
                newUrl.searchParams.set('search', searchTerm);
                newUrl.searchParams.set('page', page);
                newUrl.searchParams.set('per_page', perPage);
                newUrl.searchParams.set('grade', gradeParam);
                window.history.pushState({}, '', newUrl.toString());
                
                updateClearButton(searchTerm);
                isLoading = false;
            })
            .catch(error => {
                console.error('Error loading content:', error);
                tableContainer.innerHTML = '<div class="grade-students-empty"><i class="fa fa-info-circle"></i><h4>Error loading data</h4><p>Please try again in a moment.</p></div>';
                isLoading = false;
            });
    }
    
    function updateClearButton(searchTerm) {
        const clearBtn = document.querySelector('.grade-students-clear-btn');
        if (searchTerm && searchTerm.trim() !== '') {
            if (!clearBtn) {
                // Create clear button if it doesn't exist
                const clearLink = document.createElement('a');
                clearLink.href = '<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'per_page' => $per_page]))->out(false); ?>';
                clearLink.className = 'grade-students-clear-btn visible';
                clearLink.innerHTML = '<i class="fa fa-times"></i> Clear';
                clearLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    searchInput.value = '';
                    updateContent('', 1);
                });
                searchForm.appendChild(clearLink);
            }
        } else {
            if (clearBtn) {
                clearBtn.remove();
            }
        }
    }
    
    if (searchInput && searchForm) {
        // Auto-search when user types (with debounce)
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            // Set new timeout to search after 500ms of no typing
            searchTimeout = setTimeout(function() {
                updateContent(searchTerm, 1);
            }, 500);
        });
        
        // Also search on Enter key press (immediate)
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Clear timeout if exists
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                const searchTerm = this.value.trim();
                updateContent(searchTerm, 1);
            }
        });
    }
    
    function attachPaginationListeners() {
        const pagination = document.getElementById('grade-students-pagination');
        if (!pagination) {
            return;
        }
        pagination.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const href = this.getAttribute('href');
                if (!href) {
                    return;
                }
                const url = new URL(href, window.location.origin);
                const targetPage = parseInt(url.searchParams.get('page') || '1', 10);
                const perPage = parseInt(url.searchParams.get('per_page') || currentPerPage, 10);
                const searchTerm = searchInput ? searchInput.value.trim() : '';
                updateContent(searchTerm, targetPage, perPage);
            });
        });
    }
    
    function attachPerPageListener() {
        const perPageSelect = document.getElementById('grade-students-per-page');
        if (!perPageSelect) {
            return;
        }
        perPageSelect.value = currentPerPage;
        perPageSelect.addEventListener('change', function() {
            const newPerPage = parseInt(this.value, 10) || 10;
            const searchTerm = searchInput ? searchInput.value.trim() : '';
            updateContent(searchTerm, 1, newPerPage);
        });
    }
    
    // Attach pagination listeners on page load
    attachPaginationListeners();
    attachPerPageListener();
})();
</script>

<?php
echo $OUTPUT->footer();
?>
