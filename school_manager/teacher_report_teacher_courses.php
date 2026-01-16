<?php
/**
 * Teacher Courses List Page - Full Page View
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Get teacher ID from request
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

// First, get all courses assigned to this teacher
$teacher_courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
     FROM {course} c
     INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
     INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
     INNER JOIN {role} r ON r.id = ra.roleid
     INNER JOIN {company_course} cc ON cc.courseid = c.id
     WHERE ra.userid = ?
     AND r.shortname IN ('teacher', 'editingteacher')
     AND cc.companyid = ?
     AND c.visible = 1
     AND c.id > 1
     ORDER BY c.fullname ASC",
    [$teacherid, $company_info->id]
);

// Calculate completion rates for each course
$courses_data = [];
foreach ($teacher_courses as $course) {
    $course_id = $course->id;
    
    // Get total students enrolled in this course
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid)
         FROM {user_enrolments} ue
         INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
         INNER JOIN {user} u ON u.id = ue.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE ue.status = 0
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$course_id, $company_info->id]
    );
    
    // Get completed students for this course
    $completed_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cc.userid)
         FROM {course_completions} cc
         INNER JOIN {user} u ON u.id = cc.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = cc.course
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cc.course = ?
         AND cc.timecompleted IS NOT NULL
         AND ue.status = 0
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$company_info->id, $course_id]
    );
    
    // Calculate completion rate
    $completion_rate = $total_students > 0 
        ? round(($completed_students / $total_students) * 100, 1) 
        : 0;
    
    $courses_data[] = [
        'id' => (int)$course->id,
        'fullname' => $course->fullname ? $course->fullname : 'Unnamed Course',
        'shortname' => $course->shortname ? $course->shortname : '',
        'total_students' => (int)$total_students,
        'completed_students' => (int)$completed_students,
        'completion_rate' => $completion_rate
    ];
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php', ['teacherid' => $teacherid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Teacher Courses - ' . fullname($teacher));
$PAGE->set_heading('Teacher Courses');

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

$backurl = new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'overview']);
$teachername = fullname($teacher);

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

.courses-container {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.courses-header {
    background: #f8fafc;
    padding: 24px 30px;
    border-bottom: 2px solid #e2e8f0;
}

.courses-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 12px;
}

.courses-header h3 i {
    color: #3b82f6;
}

.courses-table {
    width: 100%;
    border-collapse: collapse;
}

.courses-table thead th {
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

.courses-table thead th:first-child {
    text-align: left;
}

.courses-table thead th.student-count-cell,
.courses-table thead th.completion-rate-cell {
    text-align: center;
}

.courses-table tbody td {
    padding: 22px 30px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
    font-size: 0.95rem;
    vertical-align: middle;
}

.courses-table tbody tr:last-child td {
    border-bottom: none;
}

.courses-table tbody tr:hover {
    background: #f9fafb;
}

.course-row {
    cursor: pointer;
    transition: all 0.2s ease;
}

.course-row:hover {
    background: #f0f9ff !important;
    transform: translateX(2px);
}

.course-name {
    font-weight: 600;
    color: #1f2937;
}

.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border: 2px solid rgba(16, 185, 129, 0.3);
    color: #065f46;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.15);
    white-space: nowrap;
}

.count-pill.success {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-color: rgba(16, 185, 129, 0.3);
    color: #065f46;
}

/* Make circular for single digit numbers */
.count-pill[data-single-digit="true"] {
    width: 40px;
    padding: 0;
    border-radius: 50%;
}

.student-count-cell,
.completion-rate-cell {
    text-align: center;
}

.completion-progress-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.completion-progress-bar {
    width: 100%;
    max-width: 200px;
    height: 28px;
    background: #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    position: relative;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
}

.completion-progress-fill {
    height: 100%;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: width 0.6s ease;
    position: relative;
    min-width: 0;
}

.completion-progress-fill.progress-high {
    background: linear-gradient(90deg, #10b981, #34d399);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.completion-progress-fill.progress-medium {
    background: linear-gradient(90deg, #f59e0b, #fbbf24);
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.completion-progress-fill.progress-low {
    background: linear-gradient(90deg, #ef4444, #f87171);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.progress-text {
    font-size: 0.75rem;
    font-weight: 700;
    color: #ffffff;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
    padding: 0 8px;
    z-index: 1;
}

/* Show percentage outside bar if too small */
.completion-progress-fill[style*="width: 0%"] .progress-text,
.completion-progress-fill[style*="width: 1%"] .progress-text,
.completion-progress-fill[style*="width: 2%"] .progress-text,
.completion-progress-fill[style*="width: 3%"] .progress-text,
.completion-progress-fill[style*="width: 4%"] .progress-text,
.completion-progress-fill[style*="width: 5%"] .progress-text {
    position: absolute;
    left: 100%;
    margin-left: 8px;
    color: #6b7280;
    text-shadow: none;
    font-size: 0.85rem;
}

.completion-progress-fill[style*="width: 0%"],
.completion-progress-fill[style*="width: 1%"],
.completion-progress-fill[style*="width: 2%"],
.completion-progress-fill[style*="width: 3%"],
.completion-progress-fill[style*="width: 4%"],
.completion-progress-fill[style*="width: 5%"] {
    min-width: 0;
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
    
    .courses-header {
        padding: 20px;
    }
    
    .courses-table {
        font-size: 0.85rem;
    }
    
    .courses-table thead th,
    .courses-table tbody td {
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
                    <i class="fa fa-book" style="color: #3b82f6;"></i>
                    Courses - <?php echo htmlspecialchars($teachername); ?>
                </h1>
                <p class="page-subtitle">View all courses assigned to this teacher, student enrollment counts, and completion rates.</p>
            </div>
            <a href="<?php echo $backurl->out(false); ?>" class="back-button">
                <i class="fa fa-arrow-left"></i>
                Back to Teacher Overview
            </a>
        </div>

        <div class="courses-container">
            <div class="courses-header">
                <h3>
                    <i class="fa fa-list"></i>
                    Course List
                </h3>
            </div>
            
            <?php if (!empty($courses_data)): ?>
                <table class="courses-table">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th class="student-count-cell">Total Students</th>
                            <th class="completion-rate-cell">Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses_data as $course): ?>
                            <?php 
                            $courseStudentsUrl = new moodle_url('/theme/remui_kids/school_manager/teacher_report_course_students.php', [
                                'courseid' => $course['id'],
                                'teacherid' => $teacherid
                            ]);
                            ?>
                            <tr class="course-row" style="cursor: pointer;" onclick="window.location.href='<?php echo $courseStudentsUrl->out(false); ?>'">
                                <td class="course-name"><?php echo htmlspecialchars($course['fullname']); ?></td>
                                <td class="student-count-cell">
                                    <?php 
                                    $studentCount = (int)($course['total_students'] ?? 0);
                                    $isSingleDigit = $studentCount >= 0 && $studentCount <= 9;
                                    ?>
                                    <span class="count-pill success" <?php echo $isSingleDigit ? 'data-single-digit="true"' : ''; ?>><?php echo number_format($studentCount, 0); ?></span>
                                </td>
                                <td class="completion-rate-cell">
                                    <div class="completion-progress-wrapper">
                                        <div class="completion-progress-bar">
                                            <?php 
                                            $rate = $course['completion_rate'];
                                            $rateClass = 'completion-progress-fill';
                                            if ($rate >= 70) {
                                                $rateClass .= ' progress-high';
                                            } elseif ($rate >= 50) {
                                                $rateClass .= ' progress-medium';
                                            } else {
                                                $rateClass .= ' progress-low';
                                            }
                                            ?>
                                            <div class="<?php echo $rateClass; ?>" style="width: <?php echo min(100, max(0, $rate)); ?>%;">
                                                <span class="progress-text"><?php echo number_format($rate, 1); ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa fa-info-circle"></i>
                    <p>No courses assigned to this teacher.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Ensure sidebar is visible
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }
});
</script>

<?php echo $OUTPUT->footer(); ?>

