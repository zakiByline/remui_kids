<?php
/**
 * Student Report - Quiz Students Page
 * Shows all students assigned to a specific quiz with their quiz performance
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

$quiz_id = optional_param('quiz_id', 0, PARAM_INT);
$quiz_name = optional_param('quiz_name', '', PARAM_TEXT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$per_page = optional_param('per_page', 10, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_INT);

// Validate per_page
if (!in_array($per_page, [10, 25, 50, 100])) {
    $per_page = 10;
}

if (!$quiz_id) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']), 'Quiz ID is required.', null, \core\output\notification::NOTIFY_ERROR);
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

// Get quiz information
$quiz = $DB->get_record('quiz', ['id' => $quiz_id]);
if (!$quiz) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']), 'Quiz not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get course information
$course = $DB->get_record('course', ['id' => $quiz->course]);
if (!$course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']), 'Course not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Verify quiz belongs to company
$company_course = $DB->get_record('company_course', ['courseid' => $course->id, 'companyid' => $company_info->id]);
if (!$company_course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']), 'Access denied. Quiz does not belong to your school.', null, \core\output\notification::NOTIFY_ERROR);
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
        // Get quiz attempts for this student and quiz
        $attempts = $DB->get_records_sql(
            "SELECT qa.id, qa.attempt, qa.timestart, qa.timefinish, qa.state,
                    qa.sumgrades, q.sumgrades AS maxgrade
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             WHERE qa.userid = ?
             AND qa.quiz = ?
             AND qa.preview = 0
             ORDER BY qa.timestart DESC",
            [$student->id, $quiz_id]
        );
        
        $total_attempts = count($attempts);
        $completed_attempts = 0;
        $best_score = 0;
        $total_time_seconds = 0;
        
        foreach ($attempts as $attempt) {
            if ($attempt->state === 'finished' && $attempt->timefinish > 0) {
                $completed_attempts++;
                
                // Calculate score percentage
                if ($attempt->sumgrades !== null && $attempt->maxgrade > 0) {
                    $score = ($attempt->sumgrades / $attempt->maxgrade) * 100;
                    if ($score > $best_score) {
                        $best_score = $score;
                    }
                }
                
                // Calculate time spent
                if ($attempt->timestart > 0 && $attempt->timefinish > 0) {
                    $total_time_seconds += ($attempt->timefinish - $attempt->timestart);
                }
            }
        }
        
        // Calculate completion rate
        $completion_rate = $total_attempts > 0 ? round(($completed_attempts / $total_attempts) * 100, 1) : 0;
        
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
            'total_attempts' => $total_attempts,
            'quiz_score' => $best_score > 0 ? round($best_score, 1) : 0,
            'total_time' => $total_time_formatted,
            'completion_rate' => $completion_rate
        ];
    }
} catch (Exception $e) {
    error_log("Error fetching quiz students: " . $e->getMessage());
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
$offset = ($page - 1) * $per_page;
$paginated_students = array_slice($students_data, $offset, $per_page);

// If AJAX request, return only table HTML
if ($ajax) {
    ob_start();
    ?>
    <div id="quiz-students-table-container">
        <div class="quiz-reports-table-wrapper">
            <table class="quiz-reports-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Student Name</th>
                        <th class="center" style="min-width: 120px;">Total Attempt</th>
                        <th class="center" style="min-width: 120px;">Quiz Score</th>
                        <th class="center" style="min-width: 120px;">Total Time</th>
                        <th class="center" style="min-width: 120px;">Completion Rate</th>
                    </tr>
                </thead>
                <tbody id="quizStudentsTableBody">
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
                                <?php if ($student['quiz_score'] > 0): ?>
                                    <?php
                                    $score_class = '';
                                    if ($student['quiz_score'] >= 90) $score_class = 'excellent';
                                    elseif ($student['quiz_score'] >= 70) $score_class = 'good';
                                    elseif ($student['quiz_score'] >= 50) $score_class = 'average';
                                    else $score_class = 'poor';
                                    ?>
                                    <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                        <?php echo $student['quiz_score']; ?>%
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
        
        <div id="quizStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <?php if ($total_students > 0): ?>
        <div class="quiz-reports-pagination">
            <div class="quiz-reports-show-entries">
                <span>Show:</span>
                <select id="quizStudentsEntriesPerPage">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                </select>
            </div>
            <div id="quizStudentsPaginationInfo" class="quiz-reports-pagination-info">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_students); ?> of <?php echo $total_students; ?> entries
            </div>
            <div class="quiz-reports-pagination-controls">
                <button type="button" id="quizStudentsPrev" class="quiz-reports-pagination-btn" <?php echo $page <= 1 ? 'disabled' : ''; ?>>&lt; Previous</button>
                <div id="quizStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                <button type="button" id="quizStudentsNext" class="quiz-reports-pagination-btn" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>Next &gt;</button>
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
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/student_report_quiz_students.php', ['quiz_id' => $quiz_id, 'quiz_name' => $quiz_name]));
$PAGE->set_title(htmlspecialchars($quiz_name ? $quiz_name . ' - Student Performance Report' : 'Quiz Student Performance Report'));
$PAGE->set_heading(htmlspecialchars($quiz_name ? $quiz_name . ' - Student Performance Report' : 'Quiz Student Performance Report'));
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

.quiz-students-container {
    padding: 0;
}

.quiz-students-header {
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

.quiz-students-header-content h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.quiz-students-header-content .student-count {
    font-size: 0.95rem;
    color: #6b7280;
    font-weight: 500;
}

.quiz-students-back-btn {
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

.quiz-students-back-btn:hover {
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

.quiz-reports-table-wrapper { overflow-x: auto; margin-bottom: 20px; }
.quiz-reports-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 900px; }
.quiz-reports-table thead { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.quiz-reports-table th { padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
.quiz-reports-table th.center, .quiz-reports-table td.center { text-align: center; }
.quiz-reports-table tbody tr { border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; }
.quiz-reports-table tbody tr:hover { background: #f9fafb; }
.quiz-reports-table td { padding: 12px; color: #1f2937; }

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

.quiz-reports-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.quiz-reports-pagination-info { font-size: 0.9rem; color: #6b7280; }
.quiz-reports-pagination-controls { display: flex; align-items: center; gap: 10px; }
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
    background: #f3f4f6;
    border-color: #9ca3af;
}

.quiz-reports-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quiz-reports-page-numbers {
    display: flex;
    gap: 6px;
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
    transition: all 0.2s;
    min-width: 40px;
    text-align: center;
}

.quiz-reports-page-number:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.quiz-reports-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
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

.quiz-reports-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.quiz-reports-empty-state i {
    font-size: 3rem;
    color: #d1d5db;
    margin-bottom: 15px;
    display: block;
}

@media (max-width: 768px) {
    .quiz-students-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .quiz-reports-pagination {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="quiz-students-container">
    <div class="quiz-students-header">
        <div class="quiz-students-header-content">
            <h1><?php echo htmlspecialchars($quiz_name ? $quiz_name : 'Quiz'); ?> - Student Performance Report</h1>
            <div class="student-count"><?php echo $total_students; ?> student<?php echo $total_students != 1 ? 's' : ''; ?> assigned</div>
        </div>
        <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']))->out(false); ?>" class="quiz-students-back-btn">
            <i class="fa fa-arrow-left"></i> Back to Quiz Reports
        </a>
    </div>
    
    <div id="quiz-students-table-container">
        <div class="quiz-reports-table-card">
            <div class="quiz-reports-table-header">
                <h4 class="quiz-reports-table-title">
                    <i class="fa fa-table"></i> Student Quiz Reports
                </h4>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div class="quiz-reports-search-container">
                        <i class="fa fa-search quiz-reports-search-icon"></i>
                        <input type="text" id="quizStudentsSearch" class="quiz-reports-search-input" placeholder="Search students by name or email..." autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" />
                        <button type="button" id="quizStudentsClear" class="quiz-reports-clear-btn">
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
                            <th class="center" style="min-width: 120px;">Quiz Score</th>
                            <th class="center" style="min-width: 120px;">Total Time</th>
                            <th class="center" style="min-width: 120px;">Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody id="quizStudentsTableBody">
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
                                <?php if ($student['quiz_score'] > 0): ?>
                                    <?php
                                    $score_class = '';
                                    if ($student['quiz_score'] >= 90) $score_class = 'excellent';
                                    elseif ($student['quiz_score'] >= 70) $score_class = 'good';
                                    elseif ($student['quiz_score'] >= 50) $score_class = 'average';
                                    else $score_class = 'poor';
                                    ?>
                                    <span class="quiz-reports-score-badge <?php echo $score_class; ?>">
                                        <?php echo $student['quiz_score']; ?>%
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
                    </tbody>
                </table>
            </div>
            
            <div id="quizStudentsEmpty" class="quiz-reports-empty-state" style="display: none;">
                <i class="fa fa-info-circle"></i>
                <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
            </div>
            
            <div class="quiz-reports-pagination">
                <div class="quiz-reports-show-entries">
                    <span>Show:</span>
                    <select id="quizStudentsEntriesPerPage">
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                    </select>
                </div>
                <div id="quizStudentsPaginationInfo" class="quiz-reports-pagination-info">
                    Showing 1 to <?php echo min($per_page, $total_students); ?> of <?php echo $total_students; ?> entries
                </div>
                <div class="quiz-reports-pagination-controls">
                    <button type="button" id="quizStudentsPrev" class="quiz-reports-pagination-btn" disabled>&lt; Previous</button>
                    <div id="quizStudentsPageNumbers" class="quiz-reports-page-numbers"></div>
                    <button type="button" id="quizStudentsNext" class="quiz-reports-pagination-btn" <?php echo $total_students > $per_page ? '' : 'disabled'; ?>>Next &gt;</button>
                </div>
            </div>
            <?php else: ?>
            <div class="quiz-reports-empty-state">
                <i class="fa fa-info-circle"></i>
                <p style="font-weight: 600; margin: 0;">No students assigned to this quiz.</p>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>

<?php if (!empty($students_data)): ?>
<script>
(function() {
    const searchInput = document.getElementById('quizStudentsSearch');
    const clearBtn = document.getElementById('quizStudentsClear');
    const tableBody = document.getElementById('quizStudentsTableBody');
    const emptyState = document.getElementById('quizStudentsEmpty');
    const paginationInfo = document.getElementById('quizStudentsPaginationInfo');
    const prevBtn = document.getElementById('quizStudentsPrev');
    const nextBtn = document.getElementById('quizStudentsNext');
    const pageNumbers = document.getElementById('quizStudentsPageNumbers');
    const entriesSelect = document.getElementById('quizStudentsEntriesPerPage');
    
    let allRows = Array.from(tableBody.querySelectorAll('.quiz-reports-row'));
    let filteredRows = allRows;
    let currentPage = 1; // Always start at page 1 when page loads
    let entriesPerPage = <?php echo $per_page; ?>;
    const totalRows = <?php echo $total_students; ?>;
    
    function updateDisplay() {
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        const rowsToShow = filteredRows.slice(startIndex, endIndex);
        
        // Hide all rows
        allRows.forEach(row => row.style.display = 'none');
        
        // Show filtered rows for current page
        rowsToShow.forEach(row => row.style.display = '');
        
        // Show/hide empty state
        if (filteredRows.length === 0) {
            tableBody.style.display = 'none';
            emptyState.style.display = 'block';
        } else {
            tableBody.style.display = '';
            emptyState.style.display = 'none';
        }
        
        // Update pagination info
        const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
        const startRecord = filteredRows.length > 0 ? startIndex + 1 : 0;
        const endRecord = Math.min(endIndex, filteredRows.length);
        
        paginationInfo.textContent = `Showing ${startRecord} to ${endRecord} of ${filteredRows.length} entries`;
        
        // Update pagination buttons
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
        
        // Update page numbers
        updatePageNumbers(totalPages);
        
        // Show/hide clear button
        if (searchInput && searchInput.value.trim()) {
            clearBtn.style.display = 'flex';
        } else {
            clearBtn.style.display = 'none';
        }
    }
    
    function updatePageNumbers(totalPages) {
        if (totalPages <= 1) {
            pageNumbers.innerHTML = '';
            return;
        }
        
        let html = '';
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button class="quiz-reports-page-number" data-page="1">1</button>`;
            if (startPage > 2) {
                html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="quiz-reports-page-number ${activeClass}" data-page="${i}">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            html += `<button class="quiz-reports-page-number" data-page="${totalPages}">${totalPages}</button>`;
        }
        
        pageNumbers.innerHTML = html;
        
        // Attach click handlers
        pageNumbers.querySelectorAll('.quiz-reports-page-number').forEach(btn => {
            btn.addEventListener('click', function() {
                currentPage = parseInt(this.getAttribute('data-page'));
                updateDisplay();
            });
        });
    }
    
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                
                if (searchTerm === '') {
                    filteredRows = allRows;
                } else {
                    filteredRows = allRows.filter(row => {
                        const name = row.getAttribute('data-name') || '';
                        const email = row.getAttribute('data-email') || '';
                        return name.includes(searchTerm) || email.includes(searchTerm);
                    });
                }
                
                currentPage = 1;
                updateDisplay();
            }, 300);
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                filteredRows = allRows;
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
<?php endif; ?>

<?php
echo $OUTPUT->footer();
?>
