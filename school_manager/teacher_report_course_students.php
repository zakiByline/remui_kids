<?php
/**
 * Course Students List Page - Shows students enrolled in a specific course with performance data
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Get course ID and teacher ID from request
$courseid = required_param('courseid', PARAM_INT);
$teacherid = required_param('teacherid', PARAM_INT);

// Ensure the current user has the school manager role.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company information for the current manager.
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
         FROM {company} c
         JOIN {company_users} cu ON c.id = cu.companyid
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Company not found', null, \core\output\notification::NOTIFY_ERROR);
}

// Verify teacher belongs to the company
$teacher = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     WHERE u.id = ? AND cu.companyid = ? AND u.deleted = 0",
    [$teacherid, $company_info->id]
);

if (!$teacher) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'overview']), 'Teacher not found', null, \core\output\notification::NOTIFY_ERROR);
}

// Verify course exists and teacher is assigned to it
$course = $DB->get_record_sql(
    "SELECT c.id, c.fullname, c.shortname
     FROM {course} c
     INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
     INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
     INNER JOIN {role} r ON r.id = ra.roleid
     INNER JOIN {company_course} cc ON cc.courseid = c.id
     WHERE c.id = ?
     AND ra.userid = ?
     AND r.shortname IN ('teacher', 'editingteacher')
     AND cc.companyid = ?
     AND c.visible = 1",
    [$courseid, $teacherid, $company_info->id]
);

if (!$course) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php', ['teacherid' => $teacherid]), 'Course not found or not accessible', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch students enrolled in this course
$students_list = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
            CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END AS is_completed,
            cc.timecompleted AS completion_date
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
     INNER JOIN {user_enrolments} ue ON ue.userid = u.id
     INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
     INNER JOIN {role} r ON r.id = ra.roleid
     LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
     WHERE ue.status = 0
     AND r.shortname = 'student'
     AND u.deleted = 0
     AND u.suspended = 0
     ORDER BY u.lastname ASC, u.firstname ASC",
    [$company_info->id, $courseid]
);

// Process student data and fetch performance metrics
$students_data = [];
foreach ($students_list as $student) {
    $student_id = $student->id;
    
    // Get course grade
    $course_grade_record = $DB->get_record_sql(
        "SELECT gg.finalgrade / gg.rawgrademax * 100 AS grade
         FROM {grade_grades} gg
         INNER JOIN {grade_items} gi ON gi.id = gg.itemid
         WHERE gi.courseid = ?
         AND gi.itemtype = 'course'
         AND gg.userid = ?
         AND gg.finalgrade IS NOT NULL
         AND gg.rawgrademax > 0
         LIMIT 1",
        [$courseid, $student_id]
    );
    $course_grade = $course_grade_record ? round((float)$course_grade_record->grade, 1) : null;
    
    // Get average quiz score
    $quiz_avg_record = $DB->get_record_sql(
        "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_score
         FROM {grade_grades} gg
         INNER JOIN {grade_items} gi ON gi.id = gg.itemid
         WHERE gi.courseid = ?
         AND gi.itemtype = 'mod'
         AND gi.itemmodule = 'quiz'
         AND gg.userid = ?
         AND gg.finalgrade IS NOT NULL
         AND gg.rawgrademax > 0",
        [$courseid, $student_id]
    );
    $avg_quiz = $quiz_avg_record && $quiz_avg_record->avg_score !== null ? round((float)$quiz_avg_record->avg_score, 1) : null;
    
    // Get average assignment grade
    $assign_avg_record = $DB->get_record_sql(
        "SELECT AVG((ag.grade / a.grade) * 100) AS avg_grade
         FROM {assign_grades} ag
         INNER JOIN {assign} a ON a.id = ag.assignment
         WHERE a.course = ?
         AND ag.userid = ?
         AND ag.grade IS NOT NULL
         AND ag.grade >= 0
         AND a.grade > 0",
        [$courseid, $student_id]
    );
    $avg_assignment = $assign_avg_record && $assign_avg_record->avg_grade !== null ? round((float)$assign_avg_record->avg_grade, 1) : null;
    
    $is_completed = (bool)($student->is_completed ?? 0);
    
    $students_data[] = [
        'id' => (int)$student->id,
        'name' => fullname($student),
        'firstname' => $student->firstname,
        'lastname' => $student->lastname,
        'email' => $student->email,
        'course_grade' => $course_grade,
        'avg_quiz_score' => $avg_quiz,
        'avg_assignment_grade' => $avg_assignment,
        'is_completed' => $is_completed,
        'completion_date' => $student->completion_date ? userdate($student->completion_date, get_string('strftimedatefullshort')) : null
    ];
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/teacher_report_course_students.php', ['courseid' => $courseid, 'teacherid' => $teacherid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Course Students - ' . $course->fullname);
$PAGE->set_heading('Course Students');

$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'teacher_report',
    'teacher_report_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

$backurl = new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php', ['teacherid' => $teacherid]);
$teachername = fullname($teacher);
$coursename = $course->fullname;

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

html, body {
    margin: 0;
    padding: 0;
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
    padding: 50px 40px 30px 40px;
    box-sizing: border-box;
}

.main-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

.page-header {
    background: linear-gradient(135deg, #e0bbe4 0%, #a7dbd8 100%);
    border-radius: 16px;
    padding: 35px 45px;
    margin-top: 20px;
    margin-bottom: 35px;
    box-shadow: 0 5px 20px rgba(167, 219, 216, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 30px;
}

.page-header-content {
    flex: 1;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 12px 0;
}

.page-subtitle {
    font-size: 1rem;
    color: #6b7280;
    margin: 0;
    line-height: 1.5;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.9);
    color: #1f2937;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    font-size: 0.95rem;
}

.back-button:hover {
    background: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    text-decoration: none;
    color: #1f2937;
}

.students-container {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.students-header {
    background: #f8fafc;
    padding: 24px 30px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.students-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 12px;
}

.students-header h3 i {
    color: #3b82f6;
}

.students-count {
    font-size: 0.95rem;
    color: #6b7280;
    font-weight: 600;
}

.students-table {
    width: 100%;
    border-collapse: collapse;
}

.students-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #475569;
    padding: 18px 30px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
    font-weight: 600;
}

.students-table thead th:first-child {
    text-align: left;
}

.students-table thead th.text-center {
    text-align: center;
}

.students-table tbody td {
    padding: 20px 30px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
    font-size: 0.95rem;
    vertical-align: middle;
}

.students-table tbody tr:last-child td {
    border-bottom: none;
}

.students-table tbody tr:hover {
    background: #f9fafb;
}

.student-name {
    font-weight: 600;
    color: #1f2937;
}

.student-email {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 4px;
}

.grade-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 50px;
    height: 32px;
    padding: 0 12px;
    border-radius: 16px;
    font-weight: 600;
    font-size: 0.9rem;
}

.grade-badge.high {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border: 2px solid rgba(16, 185, 129, 0.3);
    color: #065f46;
}

.grade-badge.medium {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 2px solid rgba(245, 158, 11, 0.3);
    color: #92400e;
}

.grade-badge.low {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border: 2px solid rgba(239, 68, 68, 0.3);
    color: #991b1b;
}

.grade-badge.none {
    background: #f3f4f6;
    border: 2px solid #e5e7eb;
    color: #6b7280;
}

.completion-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}

.completion-status.completed {
    background: #dcfce7;
    color: #065f46;
}

.completion-status.incomplete {
    background: #fee2e2;
    color: #991b1b;
}

.text-center {
    text-align: center;
}

.empty-state {
    text-align: center;
    padding: 80px 30px;
    color: #6b7280;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    display: block;
    color: #d1d5db;
}

.empty-state p {
    font-size: 1.1rem;
    margin: 0;
    font-weight: 500;
}

.students-pagination-controls {
    padding: 24px 30px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: center;
    align-items: center;
}

.pagination-buttons {
    display: flex;
    align-items: center;
    gap: 12px;
}

.pagination-btn {
    padding: 10px 18px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #374151;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.pagination-btn:hover:not(:disabled) {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.pagination-btn:disabled {
    background: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
    cursor: not-allowed;
    opacity: 0.6;
}

.pagination-page-numbers {
    display: flex;
    align-items: center;
    gap: 6px;
}

.pagination-page-numbers button {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    color: #374151;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 40px;
}

.pagination-page-numbers button:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

.pagination-page-numbers button.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        padding: 40px 15px 20px 15px;
    }
    
    .main-content {
        padding: 0;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
        padding: 25px 20px;
        margin-bottom: 25px;
    }
    
    .students-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 20px;
    }
    
    .students-table {
        font-size: 0.85rem;
    }
    
    .students-table thead th,
    .students-table tbody td {
        padding: 16px 20px;
    }
    
    .empty-state {
        padding: 60px 20px;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fa fa-users" style="color: #3b82f6;"></i>
                    <?php echo htmlspecialchars($coursename); ?> - Students
                </h1>
                <p class="page-subtitle">View all students enrolled in this course and their performance metrics.</p>
            </div>
            <a href="<?php echo $backurl->out(false); ?>" class="back-button">
                <i class="fa fa-arrow-left"></i>
                Back to Courses
            </a>
        </div>

        <div class="students-container">
            <div class="students-header">
                <h3>
                    <i class="fa fa-list"></i>
                    Student List
                </h3>
                <div class="students-count" id="studentsPaginationInfo">
                    Total: <?php echo count($students_data); ?> student(s)
                </div>
            </div>
            
            <?php if (!empty($students_data)): ?>
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th class="text-center">Course Grade</th>
                            <th class="text-center">Quiz Average</th>
                            <th class="text-center">Assignment Average</th>
                            <th class="text-center">Completion Status</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTableBody">
                        <?php foreach ($students_data as $index => $student): ?>
                            <?php 
                            $studentsPerPage = 10;
                            $pageNumber = floor($index / $studentsPerPage) + 1;
                            $displayStyle = $pageNumber === 1 ? '' : 'display: none;';
                            ?>
                            <tr class="student-row" data-page="<?php echo $pageNumber; ?>" style="<?php echo $displayStyle; ?>">
                                <td>
                                    <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['course_grade'] !== null): ?>
                                        <?php 
                                        $grade = $student['course_grade'];
                                        $gradeClass = 'grade-badge';
                                        if ($grade >= 70) {
                                            $gradeClass .= ' high';
                                        } elseif ($grade >= 50) {
                                            $gradeClass .= ' medium';
                                        } else {
                                            $gradeClass .= ' low';
                                        }
                                        ?>
                                        <span class="<?php echo $gradeClass; ?>"><?php echo number_format($grade, 1); ?>%</span>
                                    <?php else: ?>
                                        <span class="grade-badge none">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['avg_quiz_score'] !== null): ?>
                                        <?php 
                                        $quiz = $student['avg_quiz_score'];
                                        $quizClass = 'grade-badge';
                                        if ($quiz >= 70) {
                                            $quizClass .= ' high';
                                        } elseif ($quiz >= 50) {
                                            $quizClass .= ' medium';
                                        } else {
                                            $quizClass .= ' low';
                                        }
                                        ?>
                                        <span class="<?php echo $quizClass; ?>"><?php echo number_format($quiz, 1); ?>%</span>
                                    <?php else: ?>
                                        <span class="grade-badge none">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['avg_assignment_grade'] !== null): ?>
                                        <?php 
                                        $assign = $student['avg_assignment_grade'];
                                        $assignClass = 'grade-badge';
                                        if ($assign >= 70) {
                                            $assignClass .= ' high';
                                        } elseif ($assign >= 50) {
                                            $assignClass .= ' medium';
                                        } else {
                                            $assignClass .= ' low';
                                        }
                                        ?>
                                        <span class="<?php echo $assignClass; ?>"><?php echo number_format($assign, 1); ?>%</span>
                                    <?php else: ?>
                                        <span class="grade-badge none">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['is_completed']): ?>
                                        <span class="completion-status completed">
                                            <i class="fa fa-check-circle"></i>
                                            Completed
                                        </span>
                                        <?php if ($student['completion_date']): ?>
                                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 4px;">
                                                <?php echo $student['completion_date']; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="completion-status incomplete">
                                            <i class="fa fa-clock"></i>
                                            In Progress
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php 
                $totalStudents = count($students_data);
                $studentsPerPage = 10;
                $totalPages = ceil($totalStudents / $studentsPerPage);
                ?>
                <?php if ($totalPages > 1): ?>
                    <div class="students-pagination-controls">
                        <div class="pagination-buttons">
                            <button type="button" id="studentsPrevBtn" class="pagination-btn" disabled>
                                <i class="fa fa-chevron-left"></i> Previous
                            </button>
                            <div class="pagination-page-numbers" id="studentsPageNumbers"></div>
                            <button type="button" id="studentsNextBtn" class="pagination-btn">
                                Next <i class="fa fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa fa-info-circle"></i>
                    <p>No students enrolled in this course.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Pagination variables
const studentsPerPage = 10;
const totalStudents = <?php echo count($students_data); ?>;
let currentStudentsPage = 1;
const totalStudentsPages = Math.ceil(totalStudents / studentsPerPage);

// Ensure sidebar is visible
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }
    
    // Initialize pagination
    if (totalStudentsPages > 1) {
        initStudentsPagination();
        updateStudentsPagination();
    }
});

function initStudentsPagination() {
    const prevBtn = document.getElementById('studentsPrevBtn');
    const nextBtn = document.getElementById('studentsNextBtn');
    const pageNumbers = document.getElementById('studentsPageNumbers');
    
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (currentStudentsPage > 1) {
                currentStudentsPage--;
                updateStudentsPagination();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (currentStudentsPage < totalStudentsPages) {
                currentStudentsPage++;
                updateStudentsPagination();
            }
        });
    }
    
    if (pageNumbers) {
        updatePageNumbers(pageNumbers);
    }
}

function updateStudentsPagination() {
    // Hide all rows
    const allRows = document.querySelectorAll('.student-row');
    allRows.forEach(row => {
        const rowPage = parseInt(row.getAttribute('data-page'));
        row.style.display = rowPage === currentStudentsPage ? '' : 'none';
    });
    
    // Update pagination info
    const start = (currentStudentsPage - 1) * studentsPerPage + 1;
    const end = Math.min(currentStudentsPage * studentsPerPage, totalStudents);
    const paginationInfo = document.getElementById('studentsPaginationInfo');
    if (paginationInfo) {
        paginationInfo.textContent = `Showing ${start} - ${end} of ${totalStudents} student(s)`;
    }
    
    // Update buttons
    const prevBtn = document.getElementById('studentsPrevBtn');
    const nextBtn = document.getElementById('studentsNextBtn');
    
    if (prevBtn) {
        prevBtn.disabled = currentStudentsPage <= 1;
    }
    
    if (nextBtn) {
        nextBtn.disabled = currentStudentsPage >= totalStudentsPages;
    }
    
    // Update page numbers
    const pageNumbers = document.getElementById('studentsPageNumbers');
    if (pageNumbers) {
        updatePageNumbers(pageNumbers);
    }
}

function updatePageNumbers(container) {
    const maxButtons = 5;
    let startPage = Math.max(1, currentStudentsPage - Math.floor(maxButtons / 2));
    let endPage = startPage + maxButtons - 1;
    
    if (endPage > totalStudentsPages) {
        endPage = totalStudentsPages;
        startPage = Math.max(1, endPage - maxButtons + 1);
    }
    
    let html = '';
    if (startPage > 1) {
        html += `<button type="button" data-page="1">1</button>`;
        if (startPage > 2) {
            html += `<span style="padding: 8px 4px; color: #9ca3af;">...</span>`;
        }
    }
    
    for (let page = startPage; page <= endPage; page++) {
        const activeClass = page === currentStudentsPage ? 'active' : '';
        html += `<button type="button" class="${activeClass}" data-page="${page}">${page}</button>`;
    }
    
    if (endPage < totalStudentsPages) {
        if (endPage < totalStudentsPages - 1) {
            html += `<span style="padding: 8px 4px; color: #9ca3af;">...</span>`;
        }
        html += `<button type="button" data-page="${totalStudentsPages}">${totalStudentsPages}</button>`;
    }
    
    container.innerHTML = html;
    
    // Add event listeners to page number buttons
    const pageButtons = container.querySelectorAll('button[data-page]');
    pageButtons.forEach(button => {
        button.addEventListener('click', () => {
            const page = parseInt(button.getAttribute('data-page'));
            if (!isNaN(page) && page >= 1 && page <= totalStudentsPages) {
                currentStudentsPage = page;
                updateStudentsPagination();
            }
        });
    });
}
</script>

<?php echo $OUTPUT->footer(); ?>

