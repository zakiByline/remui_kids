<?php
/**
 * Student Report - Assignment Students Page
 * Shows all students assigned to a specific assignment with their submission performance
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

$assignment_id = optional_param('assignment_id', 0, PARAM_INT);
$assignment_name = optional_param('assignment_name', '', PARAM_TEXT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$per_page = optional_param('per_page', 10, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_INT);

// Validate per_page
if (!in_array($per_page, [10, 25, 50, 100])) {
    $per_page = 10;
}

if (!$assignment_id) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Assignment ID is required.', null, \core\output\notification::NOTIFY_ERROR);
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

// Get assignment information
$assignment = $DB->get_record('assign', ['id' => $assignment_id]);
if (!$assignment) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Assignment not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get course information
$course = $DB->get_record('course', ['id' => $assignment->course]);
if (!$course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Course not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Verify assignment belongs to company
$company_course = $DB->get_record('company_course', ['courseid' => $course->id, 'companyid' => $company_info->id]);
if (!$company_course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Access denied. Assignment does not belong to your school.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get all students enrolled in the course
$students_data = [];
try {
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                uifd.data as grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND e.courseid = ?
         AND e.status = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id, $course->id]
    );
    
    foreach ($students as $student) {
        // Get all submission attempts for this student and assignment
        $all_submissions = $DB->get_records('assign_submission', [
            'assignment' => $assignment_id,
            'userid' => $student->id
        ]);
        
        // Get latest submission
        $submission = null;
        if (!empty($all_submissions)) {
            // Get the latest submission
            $submission = $DB->get_record_sql(
                "SELECT * FROM {assign_submission}
                 WHERE assignment = ? AND userid = ?
                 ORDER BY timemodified DESC
                 LIMIT 1",
                [$assignment_id, $student->id]
            );
        }
        
        // Get grade
        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assignment_id,
            'userid' => $student->id
        ]);
        
        // Calculate total attempts (count all submissions)
        $total_attempts = count($all_submissions);
        
        $is_submitted = false;
        $is_late = false;
        $assignment_score = 0;
        $is_ungraded = false;
        
        if ($submission && in_array($submission->status, ['submitted', 'draft'])) {
            $is_submitted = true;
            
            // Check if late
            if ($assignment->duedate > 0 && $submission->timemodified > $assignment->duedate) {
                $is_late = true;
            }
        }
        
        // Calculate score percentage
        if ($grade && $grade->grade !== null && $assignment->grade > 0) {
            $assignment_score = round(($grade->grade / $assignment->grade) * 100, 1);
            } else {
            // If submitted but no grade, mark as ungraded
            if ($is_submitted) {
                $is_ungraded = true;
            }
        }
        
        // Calculate completion rate (100% if submitted, 0% if not)
        $completion_rate = $is_submitted ? 100 : 0;
        
        $students_data[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'grade_level' => $student->grade_level ? $student->grade_level : 'N/A',
            'total_attempts' => $total_attempts,
            'assignment_score' => $assignment_score,
            'is_ungraded' => $is_ungraded,
            'completion_rate' => $completion_rate,
            'is_submitted' => $is_submitted,
            'is_late' => $is_late
        ];
    }
} catch (Exception $e) {
    error_log("Error fetching assignment students: " . $e->getMessage());
    if (method_exists($e, 'getDebugInfo')) {
        error_log("Debug info: " . print_r($e->getDebugInfo(), true));
    }
}

// Filter by search term
if ($search) {
    $search_lower = strtolower($search);
    $students_data = array_filter($students_data, function($student) use ($search_lower) {
        return strpos(strtolower($student['name']), $search_lower) !== false ||
               strpos(strtolower($student['email']), $search_lower) !== false;
    });
    $students_data = array_values($students_data); // Re-index array
}

$total_students = count($students_data);
$total_pages = ceil($total_students / $per_page);
// Don't slice - render all students and let JavaScript handle pagination

// If AJAX request, return only table HTML
if ($ajax) {
    $offset = ($page - 1) * $per_page;
    $paginated_students = array_slice($students_data, $offset, $per_page);
    
    ob_start();
    ?>
    <div id="assignment-students-table-container">
        <div class="quiz-reports-table-wrapper">
            <table class="quiz-reports-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Student Name</th>
                        <th class="center" style="min-width: 120px;">Total Attempt</th>
                        <th class="center" style="min-width: 120px;">Assignment Score</th>
                        <th class="center" style="min-width: 120px;">Un-graded</th>
                        <th class="center" style="min-width: 120px;">Completion Rate</th>
                    </tr>
                </thead>
                <tbody id="assignmentStudentsTableBody">
                    <?php if (!empty($paginated_students)): ?>
                        <?php foreach ($paginated_students as $student): ?>
                        <tr class="quiz-reports-row" 
                            data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                            data-email="<?php echo strtolower(htmlspecialchars($student['email'])); ?>">
                            <td>
                                <div style="font-weight: 600; color: #1f2937;">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: #6b7280; margin-top: 2px;">
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </div>
                            </td>
                            <td class="center">
                                <span style="display:inline-block;padding:6px 12px;background:#dbeafe;color:#1e40af;border-radius:8px;font-weight:700;">
                                    <?php echo $student['total_attempts']; ?>
                                </span>
                            </td>
                            <td class="center">
                                <?php if ($student['assignment_score'] > 0): ?>
                                    <?php
                                    $score_class = '';
                                    if ($student['assignment_score'] >= 90) $score_class = 'excellent';
                                    elseif ($student['assignment_score'] >= 70) $score_class = 'good';
                                    elseif ($student['assignment_score'] >= 50) $score_class = 'average';
                                    else $score_class = 'poor';
                                    ?>
                                    <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                        <?php echo $student['assignment_score']; ?>%
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;font-weight:600;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?php if ($student['is_ungraded']): ?>
                                    <span style="display: inline-block; padding: 6px 12px; background: #fef3c7; color: #92400e; border-radius: 8px; font-weight: 700;">
                                        Yes
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-block; padding: 6px 12px; background: #d1fae5; color: #065f46; border-radius: 8px; font-weight: 700;">
                                        No
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                    if ($student['completion_rate'] >= 80) echo '#d1fae5';
                                    elseif ($student['completion_rate'] >= 50) echo '#fef3c7';
                                    else echo '#fee2e2';
                                ?>; color: <?php 
                                    if ($student['completion_rate'] >= 80) echo '#065f46';
                                    elseif ($student['completion_rate'] >= 50) echo '#92400e';
                                    else echo '#991b1b';
                                ?>; border-radius: 8px; font-weight: 700;">
                                    <?php echo $student['completion_rate']; ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="center" style="padding: 40px; color: #9ca3af;">
                                <i class="fa fa-info-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                No students found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div id="assignmentStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <?php if ($total_students > 0): ?>
        <div class="quiz-reports-pagination">
            <div class="quiz-reports-show-entries">
                <span>Show:</span>
                <select id="assignmentStudentsEntriesPerPage">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                </select>
            </div>
            <div id="assignmentStudentsPaginationInfo" class="quiz-reports-pagination-info">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_students); ?> of <?php echo $total_students; ?> entries
            </div>
            <div class="quiz-reports-pagination-controls">
                <button type="button" id="assignmentStudentsPrev" class="quiz-reports-pagination-btn" <?php echo $page <= 1 ? 'disabled' : ''; ?>>&lt; Previous</button>
                <div id="assignmentStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                <button type="button" id="assignmentStudentsNext" class="quiz-reports-pagination-btn" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>Next &gt;</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit;
}

// Set up page
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/student_report_assignment_students.php', ['assignment_id' => $assignment_id, 'assignment_name' => $assignment_name]));
$PAGE->set_title(htmlspecialchars($assignment_name ? $assignment_name . ' - Student Performance Report' : 'Assignment Student Performance Report'));
$PAGE->set_heading(htmlspecialchars($assignment_name ? $assignment_name . ' - Student Performance Report' : 'Assignment Student Performance Report'));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

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

// Render sidebar
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

.assignment-students-container {
    padding: 0;
}

.assignment-students-header {
    background: white;
    border-radius: 12px;
    padding: 25px 30px;
    margin-bottom: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.assignment-students-header-content h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.assignment-students-header-content .student-count {
    font-size: 0.95rem;
    color: #6b7280;
    font-weight: 500;
}

.assignment-students-back-btn {
    padding: 10px 20px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    font-size: 0.9rem;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.assignment-students-back-btn:hover {
    background: #2563eb;
    box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
    transform: translateY(-1px);
}

/* Use the same CSS classes as Student Quiz Reports */
.quiz-reports-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.quiz-reports-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.quiz-reports-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.quiz-reports-table-title i { color: #3b82f6; }

.quiz-reports-search-container {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.quiz-reports-search-input {
    width: 100%;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
}

.quiz-reports-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.quiz-reports-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.quiz-reports-clear-btn {
    padding: 8px 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: 6px;
}

.quiz-reports-clear-btn:hover {
    background: #dc2626;
}

.quiz-reports-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.quiz-reports-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    min-width: 1000px;
}

.quiz-reports-table thead {
    background: #f9fafb;
}

.quiz-reports-table th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    color: #374151;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.quiz-reports-table th.center {
    text-align: center;
}

.quiz-reports-table td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #4b5563;
}

.quiz-reports-table td.center {
    text-align: center;
}

.quiz-reports-table tbody tr:hover {
    background: #f9fafb;
}

.quiz-reports-score-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.quiz-reports-score-badge.excellent { background: #d1fae5; color: #065f46; }
.quiz-reports-score-badge.good { background: #dbeafe; color: #1e40af; }
.quiz-reports-score-badge.average { background: #fef3c7; color: #92400e; }
.quiz-reports-score-badge.poor { background: #fee2e2; color: #991b1b; }

.quiz-reports-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.quiz-reports-empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
    display: block;
}

.quiz-reports-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.quiz-reports-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-reports-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

.quiz-reports-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-reports-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.quiz-reports-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.quiz-reports-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quiz-reports-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.quiz-reports-page-number {
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
}

.quiz-reports-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="assignment-students-container">
            <!-- Header -->
            <div class="assignment-students-header">
                <div class="assignment-students-header-content">
                    <h1><?php echo htmlspecialchars($assignment_name ? $assignment_name : 'Assignment'); ?> - Student Performance Report</h1>
                    <div class="student-count"><?php echo $total_students; ?> student<?php echo $total_students != 1 ? 's' : ''; ?> assigned</div>
                </div>
                <a href="<?php 
                    $back_url = new moodle_url('/theme/remui_kids/school_manager/student_report.php');
                    $back_url->param('tab', 'quizassignmentreports');
                    $back_url->param('subtab', 'assignment');
                    echo $back_url->out(false); 
                ?>" class="assignment-students-back-btn">
                    <i class="fa fa-arrow-left"></i> Back to Assignment Reports
                </a>
            </div>
            
            <!-- Student Table -->
            <div class="quiz-reports-table-card">
                <div class="quiz-reports-table-header">
                    <h4 class="quiz-reports-table-title">
                        <i class="fa fa-table"></i> Student Assignment Reports
                    </h4>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="quiz-reports-search-container">
                            <i class="fa fa-search quiz-reports-search-icon"></i>
                            <input type="text" id="assignmentStudentsSearch" class="quiz-reports-search-input" placeholder="Search students by name or email..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off" />
                            <button type="button" id="assignmentStudentsClear" class="quiz-reports-clear-btn">
                                <i class="fa fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($students_data)): ?>
                <div class="quiz-reports-table-wrapper">
                    <table class="quiz-reports-table">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Student Name</th>
                                <th class="center" style="min-width: 120px;">Total Attempt</th>
                                <th class="center" style="min-width: 120px;">Assignment Score</th>
                                <th class="center" style="min-width: 120px;">Un-graded</th>
                                <th class="center" style="min-width: 120px;">Completion Rate</th>
                            </tr>
                        </thead>
                        <tbody id="assignmentStudentsTableBody">
                            <?php if (!empty($students_data)): ?>
                                <?php foreach ($students_data as $student): ?>
                            <tr class="quiz-reports-row" 
                                data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                                data-email="<?php echo strtolower(htmlspecialchars($student['email'])); ?>">
                                <td>
                                    <div style="font-weight: 600; color: #1f2937;">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 2px;">
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    </div>
                                </td>
                                <td class="center">
                                    <span style="display:inline-block;padding:6px 12px;background:#dbeafe;color:#1e40af;border-radius:8px;font-weight:700;">
                                        <?php echo $student['total_attempts']; ?>
                                    </span>
                                </td>
                                <td class="center">
                                    <?php if ($student['assignment_score'] > 0): ?>
                                        <?php
                                        $score_class = '';
                                        if ($student['assignment_score'] >= 90) $score_class = 'excellent';
                                        elseif ($student['assignment_score'] >= 70) $score_class = 'good';
                                        elseif ($student['assignment_score'] >= 50) $score_class = 'average';
                                        else $score_class = 'poor';
                                        ?>
                                        <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                            <?php echo $student['assignment_score']; ?>%
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-weight:600;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($student['is_ungraded']): ?>
                                        <span style="display: inline-block; padding: 6px 12px; background: #fef3c7; color: #92400e; border-radius: 8px; font-weight: 700;">
                                            Yes
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 6px 12px; background: #d1fae5; color: #065f46; border-radius: 8px; font-weight: 700;">
                                            No
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                        if ($student['completion_rate'] >= 80) echo '#d1fae5';
                                        elseif ($student['completion_rate'] >= 50) echo '#fef3c7';
                                        else echo '#fee2e2';
                                    ?>; color: <?php 
                                        if ($student['completion_rate'] >= 80) echo '#065f46';
                                        elseif ($student['completion_rate'] >= 50) echo '#92400e';
                                        else echo '#991b1b';
                                    ?>; border-radius: 8px; font-weight: 700;">
                                        <?php echo $student['completion_rate']; ?>%
                                    </span>
                                </td>
                            </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="center" style="padding: 40px; color: #9ca3af;">
                                        <i class="fa fa-info-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No students found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div id="assignmentStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
                    <i class="fa fa-info-circle"></i>
                    <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
                </div>
                
                <div class="quiz-reports-pagination">
                    <div class="quiz-reports-show-entries">
                        <span>Show:</span>
                        <select id="assignmentStudentsEntriesPerPage">
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                        </select>
                    </div>
                    <div id="assignmentStudentsPaginationInfo" class="quiz-reports-pagination-info">
                        Showing 1 to <?php echo min($per_page, $total_students); ?> of <?php echo $total_students; ?> entries
                    </div>
                    <div class="quiz-reports-pagination-controls">
                        <button type="button" id="assignmentStudentsPrev" class="quiz-reports-pagination-btn" disabled>&lt; Previous</button>
                        <div id="assignmentStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                        <button type="button" id="assignmentStudentsNext" class="quiz-reports-pagination-btn" <?php echo $total_students > $per_page ? '' : 'disabled'; ?>>Next &gt;</button>
                    </div>
                </div>
                <?php else: ?>
                <div class="quiz-reports-empty-state">
                    <i class="fa fa-info-circle"></i>
                    <p style="font-weight: 600; margin: 0;">No students found for this assignment.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Search and Pagination (with AJAX support)
(function() {
    const allRows = document.querySelectorAll('.quiz-reports-row');
    let entriesPerPage = <?php echo $per_page; ?>;
    let currentPage = <?php echo $page; ?>;
    let filteredRows = Array.from(allRows);
    
    const searchInput = document.getElementById('assignmentStudentsSearch');
    const clearBtn = document.getElementById('assignmentStudentsClear');
    const prevBtn = document.getElementById('assignmentStudentsPrev');
    const nextBtn = document.getElementById('assignmentStudentsNext');
    const entriesSelect = document.getElementById('assignmentStudentsEntriesPerPage');
    const paginationInfo = document.getElementById('assignmentStudentsPaginationInfo');
    const pageNumbers = document.getElementById('assignmentStudentsPageNumbers');
    const emptyState = document.getElementById('assignmentStudentsEmpty');
    const tableBody = document.getElementById('assignmentStudentsTableBody');
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    function filterRows() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        filteredRows = Array.from(allRows).filter(row => {
            if (!searchTerm) return true;
            const name = row.getAttribute('data-name') || '';
            const email = row.getAttribute('data-email') || '';
            return name.includes(searchTerm) || email.includes(searchTerm);
        });
        
        currentPage = 1;
        updateDisplay();
    }
    
    function updateDisplay() {
        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        
        // Show/hide rows
        allRows.forEach((row, index) => {
            const isVisible = filteredRows.includes(row) && index >= startIndex && index < endIndex;
            row.style.display = isVisible ? '' : 'none';
        });
        
        // Update pagination info
        if (paginationInfo) {
            const start = totalRows > 0 ? startIndex + 1 : 0;
            const end = Math.min(endIndex, totalRows);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${totalRows} entries`;
        }
        
        // Update pagination buttons
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        
        // Update page numbers
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = 'quiz-reports-page-number' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.addEventListener('click', () => {
                    currentPage = i;
                    updateDisplay();
                });
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Show/hide empty state
        if (emptyState) {
            emptyState.style.display = totalRows === 0 ? 'block' : 'none';
        }
        if (tableBody) {
            tableBody.style.display = totalRows === 0 ? 'none' : '';
        }
        
        // Show/hide clear button
        if (clearBtn && searchInput) {
            clearBtn.style.display = searchInput.value.trim() ? 'flex' : 'none';
        }
    }
    
    // Search input with debounce
    if (searchInput) {
        const debouncedFilter = debounce(filterRows, 500);
        searchInput.addEventListener('input', debouncedFilter);
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterRows();
            }
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                currentPage = 1;
                updateDisplay();
            }
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateDisplay();
        });
    }
    
    updateDisplay();
})();
</script>

<?php
echo $OUTPUT->footer();
?>


<?php
/**
 * Student Report - Assignment Students Page
 * Shows all students assigned to a specific assignment with their submission performance
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

$assignment_id = optional_param('assignment_id', 0, PARAM_INT);
$assignment_name = optional_param('assignment_name', '', PARAM_TEXT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$per_page = optional_param('per_page', 10, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_INT);

// Validate per_page
if (!in_array($per_page, [10, 25, 50, 100])) {
    $per_page = 10;
}

if (!$assignment_id) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Assignment ID is required.', null, \core\output\notification::NOTIFY_ERROR);
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

// Get assignment information
$assignment = $DB->get_record('assign', ['id' => $assignment_id]);
if (!$assignment) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Assignment not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get course information
$course = $DB->get_record('course', ['id' => $assignment->course]);
if (!$course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Course not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Verify assignment belongs to company
$company_course = $DB->get_record('company_course', ['courseid' => $course->id, 'companyid' => $company_info->id]);
if (!$company_course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']), 'Access denied. Assignment does not belong to your school.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get all students enrolled in the course
$students_data = [];
try {
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                uifd.data as grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND e.courseid = ?
         AND e.status = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id, $course->id]
    );
    
    foreach ($students as $student) {
        // Get submissions for this student and assignment
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignment_id,
            'userid' => $student->id
        ]);
        
        // Get grade
        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assignment_id,
            'userid' => $student->id
        ]);
        
        $total_submissions = 0;
        $is_submitted = false;
        $is_late = false;
        $assignment_score = 0;
        $total_time_seconds = 0;
        
        if ($submission && in_array($submission->status, ['submitted', 'draft'])) {
            $total_submissions = 1;
            $is_submitted = true;
            
            // Check if late
            if ($assignment->duedate > 0 && $submission->timemodified > $assignment->duedate) {
                $is_late = true;
            }
            
            // Calculate time spent (from submission timestamps)
            if ($submission->timestarted > 0 && $submission->timemodified > 0) {
                $total_time_seconds = $submission->timemodified - $submission->timestarted;
            }
        }
        
        // Calculate score percentage
        if ($grade && $grade->grade !== null && $assignment->grade > 0) {
            $assignment_score = round(($grade->grade / $assignment->grade) * 100, 1);
        }
        
        // Calculate completion rate (1 if submitted, 0 if not)
        $completion_rate = $is_submitted ? 100 : 0;
        
        // Format total time
        $total_time_formatted = '';
        if ($total_time_seconds > 0) {
            $hours = floor($total_time_seconds / 3600);
            $minutes = floor(($total_time_seconds % 3600) / 60);
            $seconds = $total_time_seconds % 60;
            
            if ($hours > 0) {
                $total_time_formatted = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
            } elseif ($minutes > 0) {
                $total_time_formatted = sprintf('%dm %ds', $minutes, $seconds);
            } else {
                $total_time_formatted = sprintf('%ds', $seconds);
            }
        } else {
            $total_time_formatted = 'N/A';
        }
        
        $students_data[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'grade_level' => $student->grade_level ? $student->grade_level : 'N/A',
            'total_submissions' => $total_submissions,
            'assignment_score' => $assignment_score,
            'total_time' => $total_time_formatted,
            'completion_rate' => $completion_rate,
            'is_submitted' => $is_submitted,
            'is_late' => $is_late
        ];
    }
} catch (Exception $e) {
    error_log("Error fetching assignment students: " . $e->getMessage());
    if (method_exists($e, 'getDebugInfo')) {
        error_log("Debug info: " . print_r($e->getDebugInfo(), true));
    }
}

// Filter by search term
if ($search) {
    $search_lower = strtolower($search);
    $students_data = array_filter($students_data, function($student) use ($search_lower) {
        return strpos(strtolower($student['name']), $search_lower) !== false ||
               strpos(strtolower($student['email']), $search_lower) !== false;
    });
    $students_data = array_values($students_data); // Re-index array
}

$total_students = count($students_data);
$total_pages = ceil($total_students / $per_page);
// Don't slice - render all students and let JavaScript handle pagination

// If AJAX request, return only table HTML
if ($ajax) {
    $offset = ($page - 1) * $per_page;
    $paginated_students = array_slice($students_data, $offset, $per_page);
    
    ob_start();
    ?>
    <div id="assignment-students-table-container">
        <div class="quiz-reports-table-wrapper">
            <table class="quiz-reports-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Student Name</th>
                        <th class="center" style="min-width: 120px;">Total Submission</th>
                        <th class="center" style="min-width: 120px;">Assignment Score</th>
                        <th class="center" style="min-width: 120px;">Total Time</th>
                        <th class="center" style="min-width: 120px;">Completion Rate</th>
                    </tr>
                </thead>
                <tbody id="assignmentStudentsTableBody">
                    <?php if (!empty($paginated_students)): ?>
                        <?php foreach ($paginated_students as $student): ?>
                        <tr class="quiz-reports-row" 
                            data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                            data-email="<?php echo strtolower(htmlspecialchars($student['email'])); ?>">
                            <td>
                                <div style="font-weight: 600; color: #1f2937;">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: #6b7280; margin-top: 2px;">
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </div>
                            </td>
                            <td class="center">
                                <span style="display:inline-block;padding:6px 12px;background:#dbeafe;color:#1e40af;border-radius:8px;font-weight:700;">
                                    <?php echo $student['total_submissions']; ?>
                                </span>
                            </td>
                            <td class="center">
                                <?php if ($student['assignment_score'] > 0): ?>
                                    <?php
                                    $score_class = '';
                                    if ($student['assignment_score'] >= 90) $score_class = 'excellent';
                                    elseif ($student['assignment_score'] >= 70) $score_class = 'good';
                                    elseif ($student['assignment_score'] >= 50) $score_class = 'average';
                                    else $score_class = 'poor';
                                    ?>
                                    <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                        <?php echo $student['assignment_score']; ?>%
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;font-weight:600;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="center" style="color:#4b5563;font-weight:500;">
                                <?php echo htmlspecialchars($student['total_time']); ?>
                            </td>
                            <td class="center">
                                <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                    if ($student['completion_rate'] >= 80) echo '#d1fae5';
                                    elseif ($student['completion_rate'] >= 50) echo '#fef3c7';
                                    else echo '#fee2e2';
                                ?>; color: <?php 
                                    if ($student['completion_rate'] >= 80) echo '#065f46';
                                    elseif ($student['completion_rate'] >= 50) echo '#92400e';
                                    else echo '#991b1b';
                                ?>; border-radius: 8px; font-weight: 700;">
                                    <?php echo $student['completion_rate']; ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="center" style="padding: 40px; color: #9ca3af;">
                                <i class="fa fa-info-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                No students found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div id="assignmentStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <?php if ($total_students > 0): ?>
        <div class="quiz-reports-pagination">
            <div class="quiz-reports-show-entries">
                <span>Show:</span>
                <select id="assignmentStudentsEntriesPerPage">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                </select>
            </div>
            <div id="assignmentStudentsPaginationInfo" class="quiz-reports-pagination-info">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_students); ?> of <?php echo $total_students; ?> entries
            </div>
            <div class="quiz-reports-pagination-controls">
                <button type="button" id="assignmentStudentsPrev" class="quiz-reports-pagination-btn" <?php echo $page <= 1 ? 'disabled' : ''; ?>>&lt; Previous</button>
                <div id="assignmentStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                <button type="button" id="assignmentStudentsNext" class="quiz-reports-pagination-btn" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>Next &gt;</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit;
}

// Set up page
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/student_report_assignment_students.php', ['assignment_id' => $assignment_id, 'assignment_name' => $assignment_name]));
$PAGE->set_title(htmlspecialchars($assignment_name ? $assignment_name . ' - Student Performance Report' : 'Assignment Student Performance Report'));
$PAGE->set_heading(htmlspecialchars($assignment_name ? $assignment_name . ' - Student Performance Report' : 'Assignment Student Performance Report'));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

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

// Render sidebar
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

.assignment-students-container {
    padding: 0;
}

.assignment-students-header {
    background: white;
    border-radius: 12px;
    padding: 25px 30px;
    margin-bottom: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.assignment-students-header-content h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.assignment-students-header-content .student-count {
    font-size: 0.95rem;
    color: #6b7280;
    font-weight: 500;
}

.assignment-students-back-btn {
    padding: 10px 20px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    font-size: 0.9rem;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.assignment-students-back-btn:hover {
    background: #2563eb;
    box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
    transform: translateY(-1px);
}

/* Use the same CSS classes as Student Quiz Reports */
.quiz-reports-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.quiz-reports-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.quiz-reports-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.quiz-reports-table-title i { color: #3b82f6; }

.quiz-reports-search-container {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.quiz-reports-search-input {
    width: 100%;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
}

.quiz-reports-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.quiz-reports-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.quiz-reports-clear-btn {
    padding: 8px 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: 6px;
}

.quiz-reports-clear-btn:hover {
    background: #dc2626;
}

.quiz-reports-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.quiz-reports-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    min-width: 1000px;
}

.quiz-reports-table thead {
    background: #f9fafb;
}

.quiz-reports-table th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    color: #374151;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.quiz-reports-table th.center {
    text-align: center;
}

.quiz-reports-table td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #4b5563;
}

.quiz-reports-table td.center {
    text-align: center;
}

.quiz-reports-table tbody tr:hover {
    background: #f9fafb;
}

.quiz-reports-score-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.quiz-reports-score-badge.excellent { background: #d1fae5; color: #065f46; }
.quiz-reports-score-badge.good { background: #dbeafe; color: #1e40af; }
.quiz-reports-score-badge.average { background: #fef3c7; color: #92400e; }
.quiz-reports-score-badge.poor { background: #fee2e2; color: #991b1b; }

.quiz-reports-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.quiz-reports-empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
    display: block;
}

.quiz-reports-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.quiz-reports-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-reports-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

.quiz-reports-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-reports-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.quiz-reports-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.quiz-reports-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quiz-reports-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.quiz-reports-page-number {
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
}

.quiz-reports-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="assignment-students-container">
            <!-- Header -->
            <div class="assignment-students-header">
                <div class="assignment-students-header-content">
                    <h1><?php echo htmlspecialchars($assignment_name ? $assignment_name : 'Assignment'); ?> - Student Performance Report</h1>
                    <div class="student-count"><?php echo $total_students; ?> student<?php echo $total_students != 1 ? 's' : ''; ?> assigned</div>
                </div>
                <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'assignment']))->out(false); ?>" class="assignment-students-back-btn">
                    <i class="fa fa-arrow-left"></i> Back to Assignment Reports
                </a>
            </div>
            
            <!-- Student Table -->
            <div class="quiz-reports-table-card">
                <div class="quiz-reports-table-header">
                    <h4 class="quiz-reports-table-title">
                        <i class="fa fa-table"></i> Student Assignment Reports
                    </h4>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="quiz-reports-search-container">
                            <i class="fa fa-search quiz-reports-search-icon"></i>
                            <input type="text" id="assignmentStudentsSearch" class="quiz-reports-search-input" placeholder="Search students by name or email..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off" />
                            <button type="button" id="assignmentStudentsClear" class="quiz-reports-clear-btn">
                                <i class="fa fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($students_data)): ?>
                <div class="quiz-reports-table-wrapper">
                    <table class="quiz-reports-table">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Student Name</th>
                                <th class="center" style="min-width: 120px;">Total Submission</th>
                                <th class="center" style="min-width: 120px;">Assignment Score</th>
                                <th class="center" style="min-width: 120px;">Total Time</th>
                                <th class="center" style="min-width: 120px;">Completion Rate</th>
                            </tr>
                        </thead>
                        <tbody id="assignmentStudentsTableBody">
                            <?php if (!empty($students_data)): ?>
                                <?php foreach ($students_data as $student): ?>
                            <tr class="quiz-reports-row" 
                                data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                                data-email="<?php echo strtolower(htmlspecialchars($student['email'])); ?>">
                                <td>
                                    <div style="font-weight: 600; color: #1f2937;">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 2px;">
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    </div>
                                </td>
                                <td class="center">
                                    <span style="display:inline-block;padding:6px 12px;background:#dbeafe;color:#1e40af;border-radius:8px;font-weight:700;">
                                        <?php echo $student['total_submissions']; ?>
                                    </span>
                                </td>
                                <td class="center">
                                    <?php if ($student['assignment_score'] > 0): ?>
                                        <?php
                                        $score_class = '';
                                        if ($student['assignment_score'] >= 90) $score_class = 'excellent';
                                        elseif ($student['assignment_score'] >= 70) $score_class = 'good';
                                        elseif ($student['assignment_score'] >= 50) $score_class = 'average';
                                        else $score_class = 'poor';
                                        ?>
                                        <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                            <?php echo $student['assignment_score']; ?>%
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-weight:600;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center" style="color:#4b5563;font-weight:500;">
                                    <?php echo htmlspecialchars($student['total_time']); ?>
                                </td>
                                <td class="center">
                                    <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                        if ($student['completion_rate'] >= 80) echo '#d1fae5';
                                        elseif ($student['completion_rate'] >= 50) echo '#fef3c7';
                                        else echo '#fee2e2';
                                    ?>; color: <?php 
                                        if ($student['completion_rate'] >= 80) echo '#065f46';
                                        elseif ($student['completion_rate'] >= 50) echo '#92400e';
                                        else echo '#991b1b';
                                    ?>; border-radius: 8px; font-weight: 700;">
                                        <?php echo $student['completion_rate']; ?>%
                                    </span>
                                </td>
                            </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="center" style="padding: 40px; color: #9ca3af;">
                                        <i class="fa fa-info-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No students found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div id="assignmentStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
                    <i class="fa fa-info-circle"></i>
                    <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
                </div>
                
                <div class="quiz-reports-pagination">
                    <div class="quiz-reports-show-entries">
                        <span>Show:</span>
                        <select id="assignmentStudentsEntriesPerPage">
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                        </select>
                    </div>
                    <div id="assignmentStudentsPaginationInfo" class="quiz-reports-pagination-info">
                        Showing 1 to <?php echo min($per_page, $total_students); ?> of <?php echo $total_students; ?> entries
                    </div>
                    <div class="quiz-reports-pagination-controls">
                        <button type="button" id="assignmentStudentsPrev" class="quiz-reports-pagination-btn" disabled>&lt; Previous</button>
                        <div id="assignmentStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                        <button type="button" id="assignmentStudentsNext" class="quiz-reports-pagination-btn" <?php echo $total_students > $per_page ? '' : 'disabled'; ?>>Next &gt;</button>
                    </div>
                </div>
                <?php else: ?>
                <div class="quiz-reports-empty-state">
                    <i class="fa fa-info-circle"></i>
                    <p style="font-weight: 600; margin: 0;">No students found for this assignment.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Search and Pagination (with AJAX support)
(function() {
    const allRows = document.querySelectorAll('.quiz-reports-row');
    let entriesPerPage = <?php echo $per_page; ?>;
    let currentPage = <?php echo $page; ?>;
    let filteredRows = Array.from(allRows);
    
    const searchInput = document.getElementById('assignmentStudentsSearch');
    const clearBtn = document.getElementById('assignmentStudentsClear');
    const prevBtn = document.getElementById('assignmentStudentsPrev');
    const nextBtn = document.getElementById('assignmentStudentsNext');
    const entriesSelect = document.getElementById('assignmentStudentsEntriesPerPage');
    const paginationInfo = document.getElementById('assignmentStudentsPaginationInfo');
    const pageNumbers = document.getElementById('assignmentStudentsPageNumbers');
    const emptyState = document.getElementById('assignmentStudentsEmpty');
    const tableBody = document.getElementById('assignmentStudentsTableBody');
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    function filterRows() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        filteredRows = Array.from(allRows).filter(row => {
            if (!searchTerm) return true;
            const name = row.getAttribute('data-name') || '';
            const email = row.getAttribute('data-email') || '';
            return name.includes(searchTerm) || email.includes(searchTerm);
        });
        
        currentPage = 1;
        updateDisplay();
    }
    
    function updateDisplay() {
        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        
        // Show/hide rows
        allRows.forEach((row, index) => {
            const isVisible = filteredRows.includes(row) && index >= startIndex && index < endIndex;
            row.style.display = isVisible ? '' : 'none';
        });
        
        // Update pagination info
        if (paginationInfo) {
            const start = totalRows > 0 ? startIndex + 1 : 0;
            const end = Math.min(endIndex, totalRows);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${totalRows} entries`;
        }
        
        // Update pagination buttons
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        
        // Update page numbers
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = 'quiz-reports-page-number' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.addEventListener('click', () => {
                    currentPage = i;
                    updateDisplay();
                });
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Show/hide empty state
        if (emptyState) {
            emptyState.style.display = totalRows === 0 ? 'block' : 'none';
        }
        if (tableBody) {
            tableBody.style.display = totalRows === 0 ? 'none' : '';
        }
        
        // Show/hide clear button
        if (clearBtn && searchInput) {
            clearBtn.style.display = searchInput.value.trim() ? 'flex' : 'none';
        }
    }
    
    // Search input with debounce
    if (searchInput) {
        const debouncedFilter = debounce(filterRows, 500);
        searchInput.addEventListener('input', debouncedFilter);
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterRows();
            }
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                currentPage = 1;
                updateDisplay();
            }
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateDisplay();
        });
    }
    
    updateDisplay();
})();
</script>

<?php
echo $OUTPUT->footer();
?>

