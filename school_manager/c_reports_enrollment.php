<?php
/**
 * Course Enrollment Report tab (AJAX fragment)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

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

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'enrollment']);
    redirect($target);
}

$course_rows = [];
$bar_labels = [];
$bar_values = [];
$total_courses = 0;
$courses_with_students = 0;
$total_enrollments = 0;
$active_enrollments = 0;
$inactive_enrollments = 0;
if ($company_info) {
    // Get all courses for this company
    $courses_list = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
          WHERE c.visible = 1 AND c.id > 1
       ORDER BY c.fullname ASC",
        ['companyid' => $company_info->id]
    );

    $total_courses = count($courses_list);

    foreach ($courses_list as $course) {
        // Get total enrolled students (only students with student role, active enrollments)
        $total_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             WHERE e.courseid = :courseid3
               AND ue.status = 0
               AND e.status = 0
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Get completed students count
        $completed_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             WHERE cc.course = :courseid3
               AND cc.timecompleted IS NOT NULL
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Get in-progress students (enrolled, accessed course, but not completed)
        $in_progress_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :courseid3
             LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = :courseid4
             WHERE e.courseid = :courseid5
               AND ue.status = 0
               AND e.status = 0
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0
               AND (cc.timecompleted IS NULL OR cc.timecompleted = 0)
               AND ula.timeaccess IS NOT NULL
               AND ula.timeaccess > 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'courseid4' => $course->id,
                'courseid5' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Calculate not started students (enrolled but never accessed)
        $not_started_students = max(0, $total_students - $completed_students - $in_progress_students);

        $status = $total_students > 0 ? 'Active' : 'Empty';

        $courses_with_students += $total_students > 0 ? 1 : 0;
        $total_enrollments += $total_students;

        if ($total_students > 0) {
            $bar_labels[] = format_string($course->fullname);
            $bar_values[] = $total_students;
        }

        $completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;

        $course_rows[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'total_students' => $total_students,
            'completed_students' => $completed_students,
            'in_progress_students' => $in_progress_students,
            'not_started_students' => $not_started_students,
            'completion_rate' => $completion_rate,
            'status' => $status
        ];
    }
}

$empty_courses = max(0, $total_courses - $courses_with_students);
$average_students = $total_courses > 0 ? round($total_enrollments / $total_courses, 1) : 0;
$bar_labels_json = json_encode($bar_labels);
$bar_values_json = json_encode($bar_values);
$pie_values_json = json_encode([$courses_with_students, $empty_courses]);

?>

<div class="enrollment-report-wrapper">
    <div class="enrollment-summary-grid">
        <div class="summary-card primary">
            <p>Total courses</p>
            <h3><?php echo number_format($total_courses); ?></h3>
        </div>
        <div class="summary-card success">
            <p>Courses with students</p>
            <h3><?php echo number_format($courses_with_students); ?></h3>
        </div>
        <div class="summary-card warning">
            <p>Empty courses</p>
            <h3><?php echo number_format($empty_courses); ?></h3>
        </div>
        <div class="summary-card">
            <p>Total enrollments</p>
            <h3><?php echo number_format($total_enrollments); ?></h3>
        </div>
        <div class="summary-card accent">
            <p>Avg students/course</p>
            <h3><?php echo number_format($average_students, 1); ?></h3>
        </div>
    </div>

    <div class="chart-card status-chart" id="courseEnrollmentStatusCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-pie-chart"></i> Course Enrollment Status</h3>
                <p>Distribution of courses with students versus empty courses.</p>
            </div>
        </div>
        <div class="pie-flex-layout">
            <div class="status-chart-canvas">
                <canvas id="enrollmentPieChart"></canvas>
            </div>
            <div class="status-chart-legend">
                <div class="status-legend-item">
                    <span class="legend-dot green"></span>
                    <div>
                        <div class="legend-label">Courses with students</div>
                        <div class="legend-value"><?php echo number_format($courses_with_students); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($courses_with_students / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
                <div class="status-legend-item">
                    <span class="legend-dot red"></span>
                    <div>
                        <div class="legend-label">Empty courses</div>
                        <div class="legend-value"><?php echo number_format($empty_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($empty_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="enrollment-table-card">
        <div class="table-header">
            <div>
                <h3><i class="fa fa-table"></i> Course Enrolled Detail List</h3>
            </div>
        </div>

        <?php if (!empty($course_rows)): ?>
            <div class="table-responsive">
                <table class="course-enrollment-table" id="courseEnrollmentTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Course name</th>
                            <th>Total students</th>
                            <th>Completed</th>
                            <th>In progress</th>
                            <th>Not started</th>
                            <th>Completion rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_rows as $index => $row): ?>
                            <tr>
                                <td class="row-number"><?php echo $index + 1; ?></td>
                                <td class="course-name-cell">
                                    <div class="course-title"><?php echo format_string($row['fullname']); ?></div>
                                    <span class="course-shortname"><?php echo format_string($row['shortname']); ?></span>
                                </td>
                                <td class="total-students-cell">
                                    <span class="highlight-green"><?php echo number_format($row['total_students']); ?></span>
                                </td>
                                <td class="completed-cell">
                                    <?php echo number_format($row['completed_students']); ?>
                                </td>
                                <td class="in-progress-cell">
                                    <span class="highlight-blue"><?php echo number_format($row['in_progress_students']); ?></span>
                                </td>
                                <td class="not-started-cell">
                                    <span class="highlight-red"><?php echo number_format($row['not_started_students']); ?></span>
                                </td>
                                <td class="completion-rate-cell">
                                    <div class="completion-progress">
                                        <div class="completion-progress-bar">
                                            <div class="completion-progress-fill <?php echo $row['completion_rate'] >= 80 ? 'high' : ($row['completion_rate'] >= 40 ? 'medium' : 'low'); ?>" style="width: <?php echo min(100, $row['completion_rate']); ?>%;"></div>
                                        </div>
                                        <span class="completion-progress-value"><?php echo number_format($row['completion_rate'], 1); ?>%</span>
                                    </div>
                                </td>
                                <td class="status-cell">
                                    <span class="status-pill <?php echo $row['status'] === 'Active' ? 'active' : 'empty'; ?>">
                                        <?php if ($row['status'] === 'Active'): ?>
                                            <i class="fa fa-check"></i>
                                        <?php endif; ?>
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-pagination">
                <button id="coursePrevPage" disabled>&laquo; Previous</button>
                <div class="page-number-list" id="coursePageNumbers"></div>
                <button id="courseNextPage">Next &raquo;</button>
            </div>
        <?php else: ?>
            <div class="student-empty-state">
                <i class="fa fa-table"></i>
                <p style="color: #6b7280; font-weight: 600; margin: 0;">No courses found for this school.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.enrollment-report-wrapper {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.enrollment-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
}

.summary-card {
    background: #fff;
    border-radius: 10px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    min-height: auto;
}

.summary-card p {
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.7rem;
    color: #94a3b8;
    margin: 0 0 6px;
    font-weight: 600;
    line-height: 1.2;
}

.summary-card h3 {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.2;
}

.summary-card.primary { border-left: 3px solid #3b82f6; }
.summary-card.success { border-left: 3px solid #10b981; }
.summary-card.warning { border-left: 3px solid #f59e0b; }
.summary-card.accent { border-left: 3px solid #8b5cf6; }


@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
}

.chart-card {
    background: #fff;
    border-radius: 14px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-header h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: #1f2937;
}

.chart-header p {
    margin: 4px 0 0;
    color: #6b7280;
    font-size: 0.9rem;
}

.chart-container {
    width: 100%;
    min-height: 360px;
}

.status-chart {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    padding: 28px 32px;
}

.pie-flex-layout {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    flex-wrap: wrap;
}

.status-chart-canvas {
    flex: 0 1 420px;
    min-height: 280px;
}

.status-chart-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.status-legend-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.legend-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
}

.legend-dot.green {
    background: linear-gradient(45deg, #34d399, #10b981);
}

.legend-dot.red {
    background: linear-gradient(45deg, #fb7185, #ef4444);
}

.legend-label {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    font-weight: 700;
}

.legend-value {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
}

.status-legend-item p {
    margin: 4px 0 0;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
}

.enrollment-table-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
    padding: 24px;
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.table-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header h3 i {
    color: #3b82f6;
    font-size: 1.1rem;
}

.table-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.search-box i {
    position: absolute;
    left: 12px;
    color: #9ca3af;
}

.search-box input {
    padding: 10px 14px 10px 34px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    min-width: 240px;
    transition: all 0.2s ease;
}

.search-box input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.page-size {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #475569;
}

.page-size select {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    font-size: 0.9rem;
}

.table-responsive {
    overflow-x: auto;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.course-enrollment-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 960px;
    font-size: 0.92rem;
    background: #fff;
}

.course-enrollment-table thead th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    font-size: 0.8rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

.course-enrollment-table thead th:first-child {
    border-top-left-radius: 8px;
}

.course-enrollment-table thead th:last-child {
    border-top-right-radius: 8px;
}

.course-enrollment-table tbody tr {
    transition: background-color 0.2s ease;
}

.course-enrollment-table tbody tr:hover {
    background-color: #f8fafc;
}

.course-enrollment-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}

.course-enrollment-table tbody tr:last-child td {
    border-bottom: none;
}

.row-number {
    font-weight: 600;
    color: #64748b;
    text-align: center;
    width: 50px;
}

.course-name-cell {
    min-width: 200px;
}

.course-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.95rem;
    margin-bottom: 4px;
}

.course-shortname {
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 400;
}

.total-students-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-green {
    color: #10b981;
    font-size: 0.95rem;
    font-weight: 700;
}

.completed-cell {
    text-align: center;
    color: #64748b;
    font-weight: 500;
}

.in-progress-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-blue {
    color: #3b82f6;
    font-size: 0.95rem;
    font-weight: 700;
}

.not-started-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-red {
    color: #ef4444;
    font-size: 0.95rem;
    font-weight: 700;
}

.completion-rate-cell {
    min-width: 150px;
}

.status-cell {
    text-align: center;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border: none;
}

.status-pill i {
    font-size: 0.75rem;
}

.status-pill.active {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
}

.status-pill.empty {
    background: rgba(248, 113, 113, 0.15);
    color: #b91c1c;
}

.table-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.table-pagination button {
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.table-pagination button:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
}

.table-pagination button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: #f8fafc;
}

.page-number-list {
    display: flex;
    gap: 8px;
    align-items: center;
}

.page-number-list button {
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 8px;
    padding: 10px 14px;
    min-width: 40px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.page-number-list button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
}

.page-number-list button.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.page-number-list button.active:hover {
    background: #2563eb;
    border-color: #2563eb;
}

.completion-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 140px;
}

.completion-progress-bar {
    flex: 1;
    height: 10px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    min-width: 80px;
}

.completion-progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.completion-progress-fill.high { 
    background: linear-gradient(90deg, #22c55e, #16a34a);
    box-shadow: 0 0 4px rgba(34, 197, 94, 0.3);
}
.completion-progress-fill.medium { 
    background: linear-gradient(90deg, #f97316, #ea580c);
    box-shadow: 0 0 4px rgba(249, 115, 22, 0.3);
}
.completion-progress-fill.low { 
    background: linear-gradient(90deg, #f87171, #ef4444);
    box-shadow: 0 0 4px rgba(248, 113, 113, 0.3);
}

.completion-progress-value {
    font-weight: 600;
    font-size: 0.9rem;
    min-width: 45px;
    text-align: right;
    color: #64748b;
}

@media (max-width: 1024px) {
    .pie-flex-layout {
        flex-direction: column;
        align-items: flex-start;
    }
    .status-chart-canvas {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .table-header {
        flex-direction: column;
    }
    .table-actions {
        width: 100%;
        justify-content: space-between;
        gap: 10px;
    }
    .search-box {
        flex: 1;
    }
    .search-box input {
        width: 100%;
    }
}
</style>

<script>
(function() {
    const barLabels = <?php echo $bar_labels_json ?: '[]'; ?>;
    const barValues = <?php echo $bar_values_json ?: '[]'; ?>;
    const pieValues = <?php echo $pie_values_json ?: '[0,0]'; ?>;

    if (typeof Chart !== 'undefined' && document.getElementById('enrollmentBarChart') && barLabels.length) {
        new Chart(document.getElementById('enrollmentBarChart'), {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{
                    label: 'Total enrollments',
                    data: barValues,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 60,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    if (typeof Chart !== 'undefined' && document.getElementById('enrollmentPieChart')) {
        new Chart(document.getElementById('enrollmentPieChart'), {
            type: 'pie',
            data: {
                labels: ['Courses with students', 'Empty courses'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    const tableEl = document.getElementById('courseEnrollmentTable');
    if (tableEl) {
        const tbody = tableEl.querySelector('tbody');
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        const prevBtn = document.getElementById('coursePrevPage');
        const nextBtn = document.getElementById('courseNextPage');
        const pageNumbersEl = document.getElementById('coursePageNumbers');

        let filteredRows = [...allRows];
        let currentPage = 1;
        const pageSize = 10; // Fixed page size

        function renderPage(page = 1) {
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentPage = Math.min(Math.max(page, 1), totalPages);

            allRows.forEach(row => { row.style.display = 'none'; });

            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            filteredRows.slice(start, end).forEach(row => row.style.display = '');

            if (prevBtn) prevBtn.disabled = currentPage === 1 || filteredRows.length === 0;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages || filteredRows.length === 0;

            if (pageNumbersEl) {
                pageNumbersEl.innerHTML = '';
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    if (i === currentPage) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => renderPage(i));
                    pageNumbersEl.appendChild(btn);
                }
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => currentPage > 1 && renderPage(currentPage - 1));
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => renderPage(currentPage + 1));
        }

        renderPage(1);
    }

})();
</script>
 * Course Enrollment Report tab (AJAX fragment)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

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

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'enrollment']);
    redirect($target);
}

$course_rows = [];
$bar_labels = [];
$bar_values = [];
$total_courses = 0;
$courses_with_students = 0;
$total_enrollments = 0;
$active_enrollments = 0;
$inactive_enrollments = 0;
if ($company_info) {
    // Get all courses for this company
    $courses_list = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
          WHERE c.visible = 1 AND c.id > 1
       ORDER BY c.fullname ASC",
        ['companyid' => $company_info->id]
    );

    $total_courses = count($courses_list);

    foreach ($courses_list as $course) {
        // Get total enrolled students (only students with student role, active enrollments)
        $total_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             WHERE e.courseid = :courseid3
               AND ue.status = 0
               AND e.status = 0
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Get completed students count
        $completed_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             WHERE cc.course = :courseid3
               AND cc.timecompleted IS NOT NULL
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Get in-progress students (enrolled, accessed course, but not completed)
        $in_progress_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid 
                AND ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid2
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :courseid3
             LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = :courseid4
             WHERE e.courseid = :courseid5
               AND ue.status = 0
               AND e.status = 0
               AND u.deleted = 0
               AND u.suspended = 0
               AND COALESCE(cu.educator, 0) = 0
               AND (cc.timecompleted IS NULL OR cc.timecompleted = 0)
               AND ula.timeaccess IS NOT NULL
               AND ula.timeaccess > 0",
            [
                'courseid' => $course->id,
                'courseid2' => $course->id,
                'courseid3' => $course->id,
                'courseid4' => $course->id,
                'courseid5' => $course->id,
                'companyid' => $company_info->id
            ]
        );

        // Calculate not started students (enrolled but never accessed)
        $not_started_students = max(0, $total_students - $completed_students - $in_progress_students);

        $status = $total_students > 0 ? 'Active' : 'Empty';

        $courses_with_students += $total_students > 0 ? 1 : 0;
        $total_enrollments += $total_students;

        if ($total_students > 0) {
            $bar_labels[] = format_string($course->fullname);
            $bar_values[] = $total_students;
        }

        $completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;

        $course_rows[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'total_students' => $total_students,
            'completed_students' => $completed_students,
            'in_progress_students' => $in_progress_students,
            'not_started_students' => $not_started_students,
            'completion_rate' => $completion_rate,
            'status' => $status
        ];
    }
}

$empty_courses = max(0, $total_courses - $courses_with_students);
$average_students = $total_courses > 0 ? round($total_enrollments / $total_courses, 1) : 0;
$bar_labels_json = json_encode($bar_labels);
$bar_values_json = json_encode($bar_values);
$pie_values_json = json_encode([$courses_with_students, $empty_courses]);

?>

<div class="enrollment-report-wrapper">
    <div class="enrollment-summary-grid">
        <div class="summary-card primary">
            <p>Total courses</p>
            <h3><?php echo number_format($total_courses); ?></h3>
        </div>
        <div class="summary-card success">
            <p>Courses with students</p>
            <h3><?php echo number_format($courses_with_students); ?></h3>
        </div>
        <div class="summary-card warning">
            <p>Empty courses</p>
            <h3><?php echo number_format($empty_courses); ?></h3>
        </div>
        <div class="summary-card">
            <p>Total enrollments</p>
            <h3><?php echo number_format($total_enrollments); ?></h3>
        </div>
        <div class="summary-card accent">
            <p>Avg students/course</p>
            <h3><?php echo number_format($average_students, 1); ?></h3>
        </div>
    </div>

    <div class="chart-card status-chart" id="courseEnrollmentStatusCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-pie-chart"></i> Course Enrollment Status</h3>
                <p>Distribution of courses with students versus empty courses.</p>
            </div>
        </div>
        <div class="pie-flex-layout">
            <div class="status-chart-canvas">
                <canvas id="enrollmentPieChart"></canvas>
            </div>
            <div class="status-chart-legend">
                <div class="status-legend-item">
                    <span class="legend-dot green"></span>
                    <div>
                        <div class="legend-label">Courses with students</div>
                        <div class="legend-value"><?php echo number_format($courses_with_students); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($courses_with_students / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
                <div class="status-legend-item">
                    <span class="legend-dot red"></span>
                    <div>
                        <div class="legend-label">Empty courses</div>
                        <div class="legend-value"><?php echo number_format($empty_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($empty_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="enrollment-table-card">
        <div class="table-header">
            <div>
                <h3><i class="fa fa-table"></i> Course Enrolled Detail List</h3>
            </div>
        </div>

        <?php if (!empty($course_rows)): ?>
            <div class="table-responsive">
                <table class="course-enrollment-table" id="courseEnrollmentTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Course name</th>
                            <th>Total students</th>
                            <th>Completed</th>
                            <th>In progress</th>
                            <th>Not started</th>
                            <th>Completion rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_rows as $index => $row): ?>
                            <tr>
                                <td class="row-number"><?php echo $index + 1; ?></td>
                                <td class="course-name-cell">
                                    <div class="course-title"><?php echo format_string($row['fullname']); ?></div>
                                    <span class="course-shortname"><?php echo format_string($row['shortname']); ?></span>
                                </td>
                                <td class="total-students-cell">
                                    <span class="highlight-green"><?php echo number_format($row['total_students']); ?></span>
                                </td>
                                <td class="completed-cell">
                                    <?php echo number_format($row['completed_students']); ?>
                                </td>
                                <td class="in-progress-cell">
                                    <span class="highlight-blue"><?php echo number_format($row['in_progress_students']); ?></span>
                                </td>
                                <td class="not-started-cell">
                                    <span class="highlight-red"><?php echo number_format($row['not_started_students']); ?></span>
                                </td>
                                <td class="completion-rate-cell">
                                    <div class="completion-progress">
                                        <div class="completion-progress-bar">
                                            <div class="completion-progress-fill <?php echo $row['completion_rate'] >= 80 ? 'high' : ($row['completion_rate'] >= 40 ? 'medium' : 'low'); ?>" style="width: <?php echo min(100, $row['completion_rate']); ?>%;"></div>
                                        </div>
                                        <span class="completion-progress-value"><?php echo number_format($row['completion_rate'], 1); ?>%</span>
                                    </div>
                                </td>
                                <td class="status-cell">
                                    <span class="status-pill <?php echo $row['status'] === 'Active' ? 'active' : 'empty'; ?>">
                                        <?php if ($row['status'] === 'Active'): ?>
                                            <i class="fa fa-check"></i>
                                        <?php endif; ?>
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-pagination">
                <button id="coursePrevPage" disabled>&laquo; Previous</button>
                <div class="page-number-list" id="coursePageNumbers"></div>
                <button id="courseNextPage">Next &raquo;</button>
            </div>
        <?php else: ?>
            <div class="student-empty-state">
                <i class="fa fa-table"></i>
                <p style="color: #6b7280; font-weight: 600; margin: 0;">No courses found for this school.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.enrollment-report-wrapper {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.enrollment-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
}

.summary-card {
    background: #fff;
    border-radius: 10px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    min-height: auto;
}

.summary-card p {
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.7rem;
    color: #94a3b8;
    margin: 0 0 6px;
    font-weight: 600;
    line-height: 1.2;
}

.summary-card h3 {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.2;
}

.summary-card.primary { border-left: 3px solid #3b82f6; }
.summary-card.success { border-left: 3px solid #10b981; }
.summary-card.warning { border-left: 3px solid #f59e0b; }
.summary-card.accent { border-left: 3px solid #8b5cf6; }


@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
}

.chart-card {
    background: #fff;
    border-radius: 14px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-header h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: #1f2937;
}

.chart-header p {
    margin: 4px 0 0;
    color: #6b7280;
    font-size: 0.9rem;
}

.chart-container {
    width: 100%;
    min-height: 360px;
}

.status-chart {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    padding: 28px 32px;
}

.pie-flex-layout {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    flex-wrap: wrap;
}

.status-chart-canvas {
    flex: 0 1 420px;
    min-height: 280px;
}

.status-chart-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.status-legend-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.legend-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
}

.legend-dot.green {
    background: linear-gradient(45deg, #34d399, #10b981);
}

.legend-dot.red {
    background: linear-gradient(45deg, #fb7185, #ef4444);
}

.legend-label {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    font-weight: 700;
}

.legend-value {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
}

.status-legend-item p {
    margin: 4px 0 0;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
}

.enrollment-table-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
    padding: 24px;
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.table-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header h3 i {
    color: #3b82f6;
    font-size: 1.1rem;
}

.table-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.search-box i {
    position: absolute;
    left: 12px;
    color: #9ca3af;
}

.search-box input {
    padding: 10px 14px 10px 34px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    min-width: 240px;
    transition: all 0.2s ease;
}

.search-box input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.page-size {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #475569;
}

.page-size select {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    font-size: 0.9rem;
}

.table-responsive {
    overflow-x: auto;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.course-enrollment-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 960px;
    font-size: 0.92rem;
    background: #fff;
}

.course-enrollment-table thead th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    font-size: 0.8rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

.course-enrollment-table thead th:first-child {
    border-top-left-radius: 8px;
}

.course-enrollment-table thead th:last-child {
    border-top-right-radius: 8px;
}

.course-enrollment-table tbody tr {
    transition: background-color 0.2s ease;
}

.course-enrollment-table tbody tr:hover {
    background-color: #f8fafc;
}

.course-enrollment-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}

.course-enrollment-table tbody tr:last-child td {
    border-bottom: none;
}

.row-number {
    font-weight: 600;
    color: #64748b;
    text-align: center;
    width: 50px;
}

.course-name-cell {
    min-width: 200px;
}

.course-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.95rem;
    margin-bottom: 4px;
}

.course-shortname {
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 400;
}

.total-students-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-green {
    color: #10b981;
    font-size: 0.95rem;
    font-weight: 700;
}

.completed-cell {
    text-align: center;
    color: #64748b;
    font-weight: 500;
}

.in-progress-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-blue {
    color: #3b82f6;
    font-size: 0.95rem;
    font-weight: 700;
}

.not-started-cell {
    text-align: center;
    font-weight: 600;
}

.highlight-red {
    color: #ef4444;
    font-size: 0.95rem;
    font-weight: 700;
}

.completion-rate-cell {
    min-width: 150px;
}

.status-cell {
    text-align: center;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border: none;
}

.status-pill i {
    font-size: 0.75rem;
}

.status-pill.active {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
}

.status-pill.empty {
    background: rgba(248, 113, 113, 0.15);
    color: #b91c1c;
}

.table-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.table-pagination button {
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.table-pagination button:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
}

.table-pagination button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: #f8fafc;
}

.page-number-list {
    display: flex;
    gap: 8px;
    align-items: center;
}

.page-number-list button {
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 8px;
    padding: 10px 14px;
    min-width: 40px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.page-number-list button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
}

.page-number-list button.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.page-number-list button.active:hover {
    background: #2563eb;
    border-color: #2563eb;
}

.completion-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 140px;
}

.completion-progress-bar {
    flex: 1;
    height: 10px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    min-width: 80px;
}

.completion-progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.completion-progress-fill.high { 
    background: linear-gradient(90deg, #22c55e, #16a34a);
    box-shadow: 0 0 4px rgba(34, 197, 94, 0.3);
}
.completion-progress-fill.medium { 
    background: linear-gradient(90deg, #f97316, #ea580c);
    box-shadow: 0 0 4px rgba(249, 115, 22, 0.3);
}
.completion-progress-fill.low { 
    background: linear-gradient(90deg, #f87171, #ef4444);
    box-shadow: 0 0 4px rgba(248, 113, 113, 0.3);
}

.completion-progress-value {
    font-weight: 600;
    font-size: 0.9rem;
    min-width: 45px;
    text-align: right;
    color: #64748b;
}

@media (max-width: 1024px) {
    .pie-flex-layout {
        flex-direction: column;
        align-items: flex-start;
    }
    .status-chart-canvas {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .table-header {
        flex-direction: column;
    }
    .table-actions {
        width: 100%;
        justify-content: space-between;
        gap: 10px;
    }
    .search-box {
        flex: 1;
    }
    .search-box input {
        width: 100%;
    }
}
</style>

<script>
(function() {
    const barLabels = <?php echo $bar_labels_json ?: '[]'; ?>;
    const barValues = <?php echo $bar_values_json ?: '[]'; ?>;
    const pieValues = <?php echo $pie_values_json ?: '[0,0]'; ?>;

    if (typeof Chart !== 'undefined' && document.getElementById('enrollmentBarChart') && barLabels.length) {
        new Chart(document.getElementById('enrollmentBarChart'), {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{
                    label: 'Total enrollments',
                    data: barValues,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 60,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    if (typeof Chart !== 'undefined' && document.getElementById('enrollmentPieChart')) {
        new Chart(document.getElementById('enrollmentPieChart'), {
            type: 'pie',
            data: {
                labels: ['Courses with students', 'Empty courses'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    const tableEl = document.getElementById('courseEnrollmentTable');
    if (tableEl) {
        const tbody = tableEl.querySelector('tbody');
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        const prevBtn = document.getElementById('coursePrevPage');
        const nextBtn = document.getElementById('courseNextPage');
        const pageNumbersEl = document.getElementById('coursePageNumbers');

        let filteredRows = [...allRows];
        let currentPage = 1;
        const pageSize = 10; // Fixed page size

        function renderPage(page = 1) {
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentPage = Math.min(Math.max(page, 1), totalPages);

            allRows.forEach(row => { row.style.display = 'none'; });

            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            filteredRows.slice(start, end).forEach(row => row.style.display = '');

            if (prevBtn) prevBtn.disabled = currentPage === 1 || filteredRows.length === 0;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages || filteredRows.length === 0;

            if (pageNumbersEl) {
                pageNumbersEl.innerHTML = '';
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    if (i === currentPage) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => renderPage(i));
                    pageNumbersEl.appendChild(btn);
                }
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => currentPage > 1 && renderPage(currentPage - 1));
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => renderPage(currentPage + 1));
        }

        renderPage(1);
    }

})();
</script>