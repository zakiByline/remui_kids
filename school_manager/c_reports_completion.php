<?php
/**
 * Course Completion Report tab (AJAX fragment)
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
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'completion']);
    redirect($target);
}

$total_courses = 0;
$completed_courses = 0;
$incomplete_courses = 0;
$total_students = 0;
$completed_students = 0;
$incomplete_students = 0;
$completion_rate = 0;

if ($company_info) {
    // Get total courses for this company
    $total_courses = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id)
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
          WHERE c.visible = 1 AND c.id > 1",
        ['companyid' => $company_info->id]
    );

    // Get completed courses (courses where at least one student has completed)
    $completed_courses = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id)
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
           JOIN {course_completions} comp ON comp.course = c.id
           JOIN {user} u ON u.id = comp.userid AND u.deleted = 0 AND u.suspended = 0
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid2
          WHERE c.visible = 1 
            AND c.id > 1
            AND comp.timecompleted IS NOT NULL",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    $incomplete_courses = max(0, $total_courses - $completed_courses);

    // Get total enrolled students
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {enrol} e ON e.courseid IN (
               SELECT courseid FROM {company_course} WHERE companyid = :companyid2
           )
           JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id
          WHERE u.deleted = 0 
            AND u.suspended = 0
            AND COALESCE(cu.educator, 0) = 0",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    // Get completed students
    $completed_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {course_completions} comp ON comp.userid = u.id
           JOIN {course} c ON c.id = comp.course
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid2
          WHERE u.deleted = 0 
            AND u.suspended = 0
            AND COALESCE(cu.educator, 0) = 0
            AND comp.timecompleted IS NOT NULL
            AND c.visible = 1
            AND c.id > 1",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    $incomplete_students = max(0, $total_students - $completed_students);

    // Calculate completion rate
    $completion_rate = $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0;

    // Get course-level completion data
    $course_completion_data = $DB->get_records_sql(
        "SELECT c.id,
                c.fullname,
                c.shortname,
                COALESCE(enrolled_stats.total_enrolled, 0) AS total_enrolled,
                COALESCE(completed_stats.completed_count, 0) AS completed_count,
                CASE 
                    WHEN COALESCE(enrolled_stats.total_enrolled, 0) > 0 
                    THEN ROUND((COALESCE(completed_stats.completed_count, 0) / COALESCE(enrolled_stats.total_enrolled, 1)) * 100, 1)
                    ELSE 0.0 
                END AS completion_rate
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
      LEFT JOIN (
                SELECT e.courseid,
                       COUNT(DISTINCT ue.userid) AS total_enrolled
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid_enrolled
                 WHERE e.status = 0
                   AND COALESCE(cu.educator, 0) = 0
              GROUP BY e.courseid
              ) enrolled_stats ON enrolled_stats.courseid = c.id
      LEFT JOIN (
                SELECT comp.course,
                       COUNT(DISTINCT comp.userid) AS completed_count
                  FROM {course_completions} comp
                  JOIN {user} u ON u.id = comp.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid_completed
                 WHERE comp.timecompleted IS NOT NULL
                   AND COALESCE(cu.educator, 0) = 0
              GROUP BY comp.course
              ) completed_stats ON completed_stats.course = c.id
          WHERE c.visible = 1
            AND c.id > 1
       ORDER BY completion_rate DESC, c.fullname ASC",
        [
            'companyid' => $company_info->id,
            'companyid_enrolled' => $company_info->id,
            'companyid_completed' => $company_info->id
        ]
    );
} else {
    $course_completion_data = [];
}

$pie_values_json = json_encode([$completed_courses, $incomplete_courses]);

// Prepare data for horizontal bar chart
$bar_labels = [];
$bar_values = [];
foreach ($course_completion_data as $course) {
    $bar_labels[] = format_string($course->fullname);
    $bar_values[] = (float)$course->completion_rate;
}
$bar_labels_json = json_encode($bar_labels);
$bar_values_json = json_encode($bar_values);

?>

<div class="completion-report-wrapper">
    <div class="completion-summary-grid">
        <div class="summary-card primary">
            <p>Total courses</p>
            <h3><?php echo number_format($total_courses); ?></h3>
        </div>
        <div class="summary-card success">
            <p>Completed courses</p>
            <h3><?php echo number_format($completed_courses); ?></h3>
        </div>
        <div class="summary-card warning">
            <p>Incomplete courses</p>
            <h3><?php echo number_format($incomplete_courses); ?></h3>
        </div>
        <div class="summary-card">
            <p>Completion rate</p>
            <h3><?php echo number_format($completion_rate, 1); ?>%</h3>
        </div>
        <div class="summary-card accent">
            <p>Total students</p>
            <h3><?php echo number_format($total_students); ?></h3>
        </div>
        <div class="summary-card info">
            <p>Completed students</p>
            <h3><?php echo number_format($completed_students); ?></h3>
        </div>
    </div>

    <div class="chart-card completion-chart" id="courseCompletionChartCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-pie-chart"></i> Course Completion Overview</h3>
                <p>Distribution of completed versus incomplete courses for <?php echo format_string($company_info ? $company_info->name : 'this school'); ?>.</p>
            </div>
        </div>
        <div class="pie-flex-layout">
            <div class="completion-chart-canvas">
                <canvas id="completionPieChart"></canvas>
            </div>
            <div class="completion-chart-legend">
                <div class="completion-legend-item completed">
                    <span class="legend-dot green"></span>
                    <div>
                        <div class="legend-label">Total completed courses</div>
                        <div class="legend-value"><?php echo number_format($completed_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
                <div class="completion-legend-item incomplete">
                    <span class="legend-dot red"></span>
                    <div>
                        <div class="legend-label">Total incomplete courses</div>
                        <div class="legend-value"><?php echo number_format($incomplete_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($incomplete_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card course-completion-bar-chart" id="courseCompletionBarChartCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-bar-chart"></i> Course-wise Completion Rate</h3>
                <p>Individual course completion rates for <?php echo format_string($company_info ? $company_info->name : 'this school'); ?>.</p>
            </div>
        </div>
        <div class="chart-search-container">
            <div class="search-box-wrapper">
                <i class="fa fa-search search-icon"></i>
                <input type="text" id="courseCompletionSearch" placeholder="Search courses by name..." autocomplete="off" />
                <button type="button" id="clearSearchBtn" class="clear-search-btn" style="display: none;">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        </div>
        <div class="bar-chart-container">
            <div class="bar-chart-scroll">
                <canvas id="courseCompletionBarChart"></canvas>
            </div>
        </div>
    </div>

    <div class="course-details-table-card">
        <div class="table-header-section">
            <h3><i class="fa fa-list"></i> Course Details</h3>
        </div>
        <div class="table-search-container">
            <div class="table-search-wrapper">
                <i class="fa fa-search table-search-icon"></i>
                <input type="text" id="courseDetailsSearch" placeholder="Search courses by name..." autocomplete="off" />
                <button type="button" id="clearTableSearchBtn" class="clear-table-search-btn" style="display: none;">
                    <span>X Clear</span>
                </button>
            </div>
        </div>
        <?php if (!empty($course_completion_data)): ?>
            <div class="table-responsive-wrapper">
                <table class="course-details-table" id="courseDetailsTable">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Total Students</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_completion_data as $course): ?>
                            <tr data-course-name="<?php echo strtolower(format_string($course->fullname)); ?>">
                                <td class="course-name-cell">
                                    <a href="#" class="course-link"><?php echo format_string($course->fullname); ?></a>
                                </td>
                                <td class="total-students-cell">
                                    <span class="badge badge-blue"><?php echo number_format((int)$course->total_enrolled); ?></span>
                                </td>
                                <td class="completed-cell">
                                    <span class="badge badge-green"><?php echo number_format((int)$course->completed_count); ?></span>
                                </td>
                                <td class="completion-rate-cell">
                                    <span class="completion-rate-value"><?php echo number_format((float)$course->completion_rate, 1); ?>%</span>
                                </td>
                                <td class="actions-cell">
                                    <a href="#" class="view-report-link">View Report →</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="table-footer-left">
                    <label for="courseDetailsPageSize">Show:</label>
                    <select id="courseDetailsPageSize">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>entries</span>
                </div>
                <div class="table-footer-right">
                    <span id="courseDetailsPageInfo">Showing 1 to 10 of <?php echo count($course_completion_data); ?> entries</span>
                </div>
            </div>
            <div class="table-pagination-controls">
                <button id="courseDetailsPrevPage" class="pagination-btn" disabled>&lt; Previous</button>
                <div class="page-numbers" id="courseDetailsPageNumbers"></div>
                <button id="courseDetailsNextPage" class="pagination-btn">Next &gt;</button>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa fa-inbox"></i>
                <p>No course data available.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.completion-report-wrapper {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.completion-summary-grid {
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
.summary-card.info { border-left: 3px solid #06b6d4; }

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
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e293b;
}

.chart-header p {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 1rem;
    font-weight: 500;
}

.completion-chart {
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

.completion-chart-canvas {
    flex: 0 1 420px;
    min-height: 280px;
}

.completion-chart-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.completion-legend-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.completion-legend-item.completed {
    border-left: 4px solid #10b981;
}

.completion-legend-item.incomplete {
    border-left: 4px solid #ef4444;
}

.legend-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
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
    margin: 4px 0;
}

.completion-legend-item p {
    margin: 4px 0 0;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
}

.course-completion-bar-chart {
    margin-top: 0;
}

.chart-search-container {
    margin-bottom: 20px;
    padding: 0 4px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.search-box-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 500px;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 1rem;
    z-index: 1;
}

#courseCompletionSearch {
    width: 100%;
    padding: 12px 45px 12px 42px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #1e293b;
    background: #fff;
    transition: all 0.3s ease;
    outline: none;
}

#courseCompletionSearch:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

#courseCompletionSearch::placeholder {
    color: #94a3b8;
}

.clear-search-btn {
    position: absolute;
    right: 10px;
    background: #e2e8f0;
    border: none;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 1;
}

.clear-search-btn:hover {
    background: #cbd5e1;
    transform: scale(1.1);
}

.clear-search-btn i {
    color: #64748b;
    font-size: 0.85rem;
}

.bar-chart-container {
    width: 100%;
    min-height: 520px;
    position: relative;
    padding: 25px 15px 20px;
    overflow-x: auto;
    overflow-y: hidden;
    background: #fafbfc;
    border-radius: 8px;
    -webkit-overflow-scrolling: touch;
}

.bar-chart-container::-webkit-scrollbar {
    height: 12px;
}

.bar-chart-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
}

.bar-chart-container::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 999px;
    border: 2px solid #f1f5f9;
}

.bar-chart-container::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* Firefox scrollbar */
.bar-chart-container {
    scrollbar-width: auto;
    scrollbar-color: #94a3b8 #f1f5f9;
}

.bar-chart-scroll {
    min-width: 100%;
    width: max-content;
    display: inline-block;
}

/* Course Details Table Styles */
.course-details-table-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    padding: 24px;
    margin-top: 0;
}

.table-header-section {
    margin-bottom: 20px;
}

.table-header-section h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header-section h3 i {
    color: #3b82f6;
}

.table-search-container {
    margin-bottom: 20px;
    display: flex;
    justify-content: flex-start;
}

.table-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 500px;
    width: 100%;
}

.table-search-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 1rem;
    z-index: 1;
}

#courseDetailsSearch {
    width: 100%;
    padding: 12px 120px 12px 42px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #1e293b;
    background: #fff;
    transition: all 0.3s ease;
    outline: none;
}

#courseDetailsSearch:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.clear-table-search-btn {
    position: absolute;
    right: 10px;
    background: #ef4444;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 1;
}

.clear-table-search-btn:hover {
    background: #dc2626;
    transform: scale(1.05);
}

.table-responsive-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.course-details-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.course-details-table thead th {
    background: #f8fafc;
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    font-size: 0.85rem;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.course-details-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s ease;
}

.course-details-table tbody tr:hover {
    background-color: #f8fafc;
}

.course-details-table tbody tr.highlighted {
    background-color: #fef3c7;
}

.course-details-table tbody td {
    padding: 16px;
    vertical-align: middle;
}

.course-name-cell {
    font-weight: 600;
}

.course-link {
    color: #3b82f6;
    text-decoration: none;
    transition: color 0.2s ease;
}

.course-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
    min-width: 40px;
    text-align: center;
}

.badge-blue {
    background: #dbeafe;
    color: #1e40af;
}

.badge-green {
    background: #d1fae5;
    color: #065f46;
}

.completion-rate-value {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.95rem;
}

.view-report-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s ease;
}

.view-report-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    flex-wrap: wrap;
    gap: 12px;
}

.table-footer-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #64748b;
}

.table-footer-left label {
    font-weight: 600;
}

.table-footer-left select {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    background: #fff;
    cursor: pointer;
}

.table-footer-right {
    font-size: 0.9rem;
    color: #64748b;
    font-weight: 600;
}

.table-pagination-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
}

.pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.page-numbers {
    display: flex;
    gap: 6px;
    align-items: center;
}

.page-numbers button {
    padding: 8px 14px;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 40px;
}

.page-numbers button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.page-numbers button.active {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    color: #cbd5e1;
}

.empty-state p {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

@media (max-width: 1024px) {
    .pie-flex-layout {
        flex-direction: column;
        align-items: flex-start;
    }
    .completion-chart-canvas {
        width: 100%;
    }
    .bar-chart-container {
        min-height: 420px;
    }
}
}
</style>

<script>
(function() {
    const pieValues = <?php echo $pie_values_json ?: '[0,0]'; ?>;

    if (typeof Chart !== 'undefined' && document.getElementById('completionPieChart')) {
        const ctx = document.getElementById('completionPieChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Total completed courses', 'Total incomplete courses'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 4
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
                },
                animation: {
                    animateRotate: true,
                    animateScale: true
                }
            }
        });
    } else if (document.getElementById('completionPieChart')) {
        // Chart.js not loaded yet, wait for it
        const checkChart = setInterval(() => {
            if (typeof Chart !== 'undefined') {
                clearInterval(checkChart);
                const ctx = document.getElementById('completionPieChart').getContext('2d');
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: ['Total completed courses', 'Total incomplete courses'],
                        datasets: [{
                            data: pieValues,
                            backgroundColor: ['#10b981', '#ef4444'],
                            borderWidth: 0,
                            hoverOffset: 4
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
                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true
                        }
                    }
                });
            }
        }, 100);
        
        // Clear interval after 10 seconds to prevent infinite checking
        setTimeout(() => clearInterval(checkChart), 10000);
    }

    // Horizontal Bar Chart for Course-wise Completion Rates
    const barLabels = <?php echo $bar_labels_json ?: '[]'; ?>;
    const barValues = <?php echo $bar_values_json ?: '[]'; ?>;
    let completionBarChart = null;
    let searchTerm = '';

    function getBarColor(value, label, isHighlighted) {
        if (isHighlighted) {
            // Highlighted bars - bright yellow/gold
            return 'rgba(251, 191, 36, 1)';
        }
        if (value >= 80) return 'rgba(16, 185, 129, 1)'; // Bright green
        if (value >= 40) return 'rgba(245, 158, 11, 1)'; // Bright orange
        return 'rgba(239, 68, 68, 1)'; // Bright red
    }

    function getBarBorderColor(value, label, isHighlighted) {
        if (isHighlighted) {
            return '#d97706'; // Darker gold border
        }
        if (value >= 80) return '#047857';
        if (value >= 40) return '#b45309';
        return '#b91c1c';
    }

    function updateChartHighlight() {
        if (!completionBarChart) return;

        const searchLower = searchTerm.toLowerCase().trim();
        const isHighlighted = searchLower.length > 0;

        completionBarChart.data.datasets[0].backgroundColor = barLabels.map((label, index) => {
            const value = barValues[index] || 0;
            const matches = label.toLowerCase().includes(searchLower);
            return getBarColor(value, label, matches && isHighlighted);
        });

        completionBarChart.data.datasets[0].borderColor = barLabels.map((label, index) => {
            const value = barValues[index] || 0;
            const matches = label.toLowerCase().includes(searchLower);
            return getBarBorderColor(value, label, matches && isHighlighted);
        });

        completionBarChart.data.datasets[0].borderWidth = barLabels.map((label) => {
            const matches = label.toLowerCase().includes(searchLower);
            return matches && isHighlighted ? 4 : 2;
        });

        completionBarChart.update('none');

        // Scroll to first highlighted course
        if (isHighlighted) {
            const firstMatchIndex = barLabels.findIndex(label => 
                label.toLowerCase().includes(searchLower)
            );
            if (firstMatchIndex >= 0) {
                scrollToCourse(firstMatchIndex);
            }
        }
    }

    function scrollToCourse(index) {
        const container = document.querySelector('.bar-chart-container');
        if (!container) return;

        const canvas = document.getElementById('courseCompletionBarChart');
        if (!canvas) return;

        // Calculate approximate position (150px per course for proper spacing)
        const scrollPosition = index * 150;
        container.scrollTo({
            left: Math.max(0, scrollPosition - 200),
            behavior: 'smooth'
        });
    }

    function initBarChart() {
        const barChartCanvas = document.getElementById('courseCompletionBarChart');
        if (!barChartCanvas) return;

        if (typeof Chart !== 'undefined') {
            const ctx = barChartCanvas.getContext('2d');
            // Calculate width with proper spacing: 150px per course to give adequate spacing
            const baseWidth = Math.max(barLabels.length * 150, barChartCanvas.parentElement.offsetWidth || 0);
            barChartCanvas.width = baseWidth;
            barChartCanvas.height = 480;
            
            completionBarChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: barLabels,
                    datasets: [{
                        label: 'Completion Rate (%)',
                        data: barValues,
                        backgroundColor: function(context) {
                            const value = context.parsed.y || 0;
                            const label = barLabels[context.dataIndex] || '';
                            const matches = searchTerm.length > 0 && label.toLowerCase().includes(searchTerm.toLowerCase().trim());
                            return getBarColor(value, label, matches);
                        },
                        borderColor: function(context) {
                            const value = context.parsed.y || 0;
                            const label = barLabels[context.dataIndex] || '';
                            const matches = searchTerm.length > 0 && label.toLowerCase().includes(searchTerm.toLowerCase().trim());
                            return getBarBorderColor(value, label, matches);
                        },
                        borderWidth: 2,
                        borderRadius: 8,
                        barThickness: 45,
                        maxBarThickness: 50,
                        minBarLength: 3,
                        shadowOffsetX: 2,
                        shadowOffsetY: 2,
                        shadowBlur: 4,
                        shadowColor: 'rgba(0, 0, 0, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    onHover: function(event, activeElements) {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    },
                    layout: {
                        padding: {
                            left: 20,
                            right: 30,
                            top: 15,
                            bottom: 15
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#1e293b',
                            borderWidth: 2,
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 14,
                                weight: '600'
                            },
                            callbacks: {
                                title: function(context) {
                                    return context[0].label;
                                },
                                label: function(context) {
                                    return 'Completion Rate: ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                stepSize: 20,
                                font: {
                                    size: 14,
                                    weight: '700',
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                padding: 10
                            },
                            grid: {
                                color: '#e5e7eb',
                                lineWidth: 1
                            },
                            title: {
                                display: true,
                                text: 'Completion Rate (%)',
                                font: {
                                    weight: 'bold',
                                    size: 16,
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                padding: {
                                    top: 10,
                                    bottom: 10
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 13,
                                    weight: '600',
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                maxRotation: 45,
                                minRotation: 45,
                                padding: 15,
                                autoSkip: false
                            },
                            grid: {
                                display: false
                            },
                            categoryPercentage: 0.5,
                            barPercentage: 0.6
                        }
                    },
                    animation: {
                        duration: 1000
                    }
                }
            });
        } else if (barChartCanvas) {
            // Chart.js not loaded yet, wait for it
            const checkBarChart = setInterval(() => {
                if (typeof Chart !== 'undefined') {
                    clearInterval(checkBarChart);
                    initBarChart();
                }
            }, 100);
            
            // Clear interval after 10 seconds
            setTimeout(() => clearInterval(checkBarChart), 10000);
        }
    }

    // Initialize bar chart
    if (barLabels.length > 0 && barValues.length > 0) {
        initBarChart();
    }

    // Search functionality
    const searchInput = document.getElementById('courseCompletionSearch');
    const clearBtn = document.getElementById('clearSearchBtn');

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

    function handleSearch() {
        searchTerm = searchInput ? searchInput.value : '';
        if (clearBtn) {
            clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
        }
        updateChartHighlight();
    }

    function clearSearch() {
        if (searchInput) {
            searchInput.value = '';
            searchTerm = '';
        }
        if (clearBtn) {
            clearBtn.style.display = 'none';
        }
        updateChartHighlight();
    }

    if (searchInput) {
        searchInput.addEventListener('input', debounce(handleSearch, 300));
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Escape') {
                clearSearch();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearSearch);
    }

    // Course Details Table Search and Pagination
    const courseDetailsTable = document.getElementById('courseDetailsTable');
    if (courseDetailsTable) {
        const tableSearchInput = document.getElementById('courseDetailsSearch');
        const clearTableSearchBtn = document.getElementById('clearTableSearchBtn');
        const tableRows = Array.from(courseDetailsTable.querySelectorAll('tbody tr'));
        const pageSizeSelect = document.getElementById('courseDetailsPageSize');
        const prevBtn = document.getElementById('courseDetailsPrevPage');
        const nextBtn = document.getElementById('courseDetailsNextPage');
        const pageInfo = document.getElementById('courseDetailsPageInfo');
        const pageNumbers = document.getElementById('courseDetailsPageNumbers');

        let filteredRows = [...tableRows];
        let currentPage = 1;
        let pageSize = 10;

        function filterTable() {
            const searchTerm = tableSearchInput ? tableSearchInput.value.toLowerCase().trim() : '';
            
            filteredRows = tableRows.filter(row => {
                const courseName = row.dataset.courseName || '';
                return courseName.includes(searchTerm);
            });

            // Highlight matching rows
            tableRows.forEach(row => {
                const courseName = row.dataset.courseName || '';
                if (searchTerm && courseName.includes(searchTerm)) {
                    row.classList.add('highlighted');
                } else {
                    row.classList.remove('highlighted');
                }
            });

            if (clearTableSearchBtn) {
                clearTableSearchBtn.style.display = searchTerm.length > 0 ? 'block' : 'none';
            }

            currentPage = 1;
            renderTable();
        }

        function renderTable() {
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentPage = Math.min(Math.max(currentPage, 1), totalPages);

            // Hide all rows
            tableRows.forEach(row => row.style.display = 'none');

            // Show rows for current page
            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            filteredRows.slice(start, end).forEach(row => row.style.display = '');

            // Update page info
            if (pageInfo) {
                const startNum = filteredRows.length === 0 ? 0 : start + 1;
                const endNum = Math.min(end, filteredRows.length);
                const total = filteredRows.length;
                pageInfo.textContent = `Showing ${startNum} to ${endNum} of ${total} entries`;
            }

            // Update pagination buttons
            if (prevBtn) prevBtn.disabled = currentPage === 1 || filteredRows.length === 0;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages || filteredRows.length === 0;

            // Update page numbers
            if (pageNumbers) {
                pageNumbers.innerHTML = '';
                const maxPages = Math.min(totalPages, 10);
                let startPage = Math.max(1, currentPage - 4);
                let endPage = Math.min(totalPages, startPage + maxPages - 1);
                
                if (endPage - startPage < maxPages - 1) {
                    startPage = Math.max(1, endPage - maxPages + 1);
                }

                for (let i = startPage; i <= endPage; i++) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    if (i === currentPage) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => {
                        currentPage = i;
                        renderTable();
                    });
                    pageNumbers.appendChild(btn);
                }
            }
        }

        if (tableSearchInput) {
            tableSearchInput.addEventListener('input', debounce(filterTable, 300));
        }

        if (clearTableSearchBtn) {
            clearTableSearchBtn.addEventListener('click', () => {
                if (tableSearchInput) tableSearchInput.value = '';
                filterTable();
            });
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', () => {
                pageSize = parseInt(pageSizeSelect.value, 10) || 10;
                currentPage = 1;
                renderTable();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        }

        // Initial render
        renderTable();
    }
})();
</script>
 * Course Completion Report tab (AJAX fragment)
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
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'completion']);
    redirect($target);
}

$total_courses = 0;
$completed_courses = 0;
$incomplete_courses = 0;
$total_students = 0;
$completed_students = 0;
$incomplete_students = 0;
$completion_rate = 0;

if ($company_info) {
    // Get total courses for this company
    $total_courses = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id)
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
          WHERE c.visible = 1 AND c.id > 1",
        ['companyid' => $company_info->id]
    );

    // Get completed courses (courses where at least one student has completed)
    $completed_courses = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id)
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
           JOIN {course_completions} comp ON comp.course = c.id
           JOIN {user} u ON u.id = comp.userid AND u.deleted = 0 AND u.suspended = 0
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid2
          WHERE c.visible = 1 
            AND c.id > 1
            AND comp.timecompleted IS NOT NULL",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    $incomplete_courses = max(0, $total_courses - $completed_courses);

    // Get total enrolled students
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {enrol} e ON e.courseid IN (
               SELECT courseid FROM {company_course} WHERE companyid = :companyid2
           )
           JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id
          WHERE u.deleted = 0 
            AND u.suspended = 0
            AND COALESCE(cu.educator, 0) = 0",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    // Get completed students
    $completed_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {course_completions} comp ON comp.userid = u.id
           JOIN {course} c ON c.id = comp.course
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid2
          WHERE u.deleted = 0 
            AND u.suspended = 0
            AND COALESCE(cu.educator, 0) = 0
            AND comp.timecompleted IS NOT NULL
            AND c.visible = 1
            AND c.id > 1",
        ['companyid' => $company_info->id, 'companyid2' => $company_info->id]
    );

    $incomplete_students = max(0, $total_students - $completed_students);

    // Calculate completion rate
    $completion_rate = $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0;

    // Get course-level completion data
    $course_completion_data = $DB->get_records_sql(
        "SELECT c.id,
                c.fullname,
                c.shortname,
                COALESCE(enrolled_stats.total_enrolled, 0) AS total_enrolled,
                COALESCE(completed_stats.completed_count, 0) AS completed_count,
                CASE 
                    WHEN COALESCE(enrolled_stats.total_enrolled, 0) > 0 
                    THEN ROUND((COALESCE(completed_stats.completed_count, 0) / COALESCE(enrolled_stats.total_enrolled, 1)) * 100, 1)
                    ELSE 0.0 
                END AS completion_rate
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
      LEFT JOIN (
                SELECT e.courseid,
                       COUNT(DISTINCT ue.userid) AS total_enrolled
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid_enrolled
                 WHERE e.status = 0
                   AND COALESCE(cu.educator, 0) = 0
              GROUP BY e.courseid
              ) enrolled_stats ON enrolled_stats.courseid = c.id
      LEFT JOIN (
                SELECT comp.course,
                       COUNT(DISTINCT comp.userid) AS completed_count
                  FROM {course_completions} comp
                  JOIN {user} u ON u.id = comp.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid_completed
                 WHERE comp.timecompleted IS NOT NULL
                   AND COALESCE(cu.educator, 0) = 0
              GROUP BY comp.course
              ) completed_stats ON completed_stats.course = c.id
          WHERE c.visible = 1
            AND c.id > 1
       ORDER BY completion_rate DESC, c.fullname ASC",
        [
            'companyid' => $company_info->id,
            'companyid_enrolled' => $company_info->id,
            'companyid_completed' => $company_info->id
        ]
    );
} else {
    $course_completion_data = [];
}

$pie_values_json = json_encode([$completed_courses, $incomplete_courses]);

// Prepare data for horizontal bar chart
$bar_labels = [];
$bar_values = [];
foreach ($course_completion_data as $course) {
    $bar_labels[] = format_string($course->fullname);
    $bar_values[] = (float)$course->completion_rate;
}
$bar_labels_json = json_encode($bar_labels);
$bar_values_json = json_encode($bar_values);

?>

<div class="completion-report-wrapper">
    <div class="completion-summary-grid">
        <div class="summary-card primary">
            <p>Total courses</p>
            <h3><?php echo number_format($total_courses); ?></h3>
        </div>
        <div class="summary-card success">
            <p>Completed courses</p>
            <h3><?php echo number_format($completed_courses); ?></h3>
        </div>
        <div class="summary-card warning">
            <p>Incomplete courses</p>
            <h3><?php echo number_format($incomplete_courses); ?></h3>
        </div>
        <div class="summary-card">
            <p>Completion rate</p>
            <h3><?php echo number_format($completion_rate, 1); ?>%</h3>
        </div>
        <div class="summary-card accent">
            <p>Total students</p>
            <h3><?php echo number_format($total_students); ?></h3>
        </div>
        <div class="summary-card info">
            <p>Completed students</p>
            <h3><?php echo number_format($completed_students); ?></h3>
        </div>
    </div>

    <div class="chart-card completion-chart" id="courseCompletionChartCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-pie-chart"></i> Course Completion Overview</h3>
                <p>Distribution of completed versus incomplete courses for <?php echo format_string($company_info ? $company_info->name : 'this school'); ?>.</p>
            </div>
        </div>
        <div class="pie-flex-layout">
            <div class="completion-chart-canvas">
                <canvas id="completionPieChart"></canvas>
            </div>
            <div class="completion-chart-legend">
                <div class="completion-legend-item completed">
                    <span class="legend-dot green"></span>
                    <div>
                        <div class="legend-label">Total completed courses</div>
                        <div class="legend-value"><?php echo number_format($completed_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
                <div class="completion-legend-item incomplete">
                    <span class="legend-dot red"></span>
                    <div>
                        <div class="legend-label">Total incomplete courses</div>
                        <div class="legend-value"><?php echo number_format($incomplete_courses); ?></div>
                        <p><?php echo $total_courses > 0 ? round(($incomplete_courses / $total_courses) * 100, 1) : 0; ?>% of total courses</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card course-completion-bar-chart" id="courseCompletionBarChartCard">
        <div class="chart-header">
            <div>
                <h3><i class="fa fa-bar-chart"></i> Course-wise Completion Rate</h3>
                <p>Individual course completion rates for <?php echo format_string($company_info ? $company_info->name : 'this school'); ?>.</p>
            </div>
        </div>
        <div class="chart-search-container">
            <div class="search-box-wrapper">
                <i class="fa fa-search search-icon"></i>
                <input type="text" id="courseCompletionSearch" placeholder="Search courses by name..." autocomplete="off" />
                <button type="button" id="clearSearchBtn" class="clear-search-btn" style="display: none;">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        </div>
        <div class="bar-chart-container">
            <div class="bar-chart-scroll">
                <canvas id="courseCompletionBarChart"></canvas>
            </div>
        </div>
    </div>

    <div class="course-details-table-card">
        <div class="table-header-section">
            <h3><i class="fa fa-list"></i> Course Details</h3>
        </div>
        <div class="table-search-container">
            <div class="table-search-wrapper">
                <i class="fa fa-search table-search-icon"></i>
                <input type="text" id="courseDetailsSearch" placeholder="Search courses by name..." autocomplete="off" />
                <button type="button" id="clearTableSearchBtn" class="clear-table-search-btn" style="display: none;">
                    <span>X Clear</span>
                </button>
            </div>
        </div>
        <?php if (!empty($course_completion_data)): ?>
            <div class="table-responsive-wrapper">
                <table class="course-details-table" id="courseDetailsTable">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Total Students</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_completion_data as $course): ?>
                            <tr data-course-name="<?php echo strtolower(format_string($course->fullname)); ?>">
                                <td class="course-name-cell">
                                    <a href="#" class="course-link"><?php echo format_string($course->fullname); ?></a>
                                </td>
                                <td class="total-students-cell">
                                    <span class="badge badge-blue"><?php echo number_format((int)$course->total_enrolled); ?></span>
                                </td>
                                <td class="completed-cell">
                                    <span class="badge badge-green"><?php echo number_format((int)$course->completed_count); ?></span>
                                </td>
                                <td class="completion-rate-cell">
                                    <span class="completion-rate-value"><?php echo number_format((float)$course->completion_rate, 1); ?>%</span>
                                </td>
                                <td class="actions-cell">
                                    <a href="#" class="view-report-link">View Report →</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="table-footer-left">
                    <label for="courseDetailsPageSize">Show:</label>
                    <select id="courseDetailsPageSize">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>entries</span>
                </div>
                <div class="table-footer-right">
                    <span id="courseDetailsPageInfo">Showing 1 to 10 of <?php echo count($course_completion_data); ?> entries</span>
                </div>
            </div>
            <div class="table-pagination-controls">
                <button id="courseDetailsPrevPage" class="pagination-btn" disabled>&lt; Previous</button>
                <div class="page-numbers" id="courseDetailsPageNumbers"></div>
                <button id="courseDetailsNextPage" class="pagination-btn">Next &gt;</button>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa fa-inbox"></i>
                <p>No course data available.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.completion-report-wrapper {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.completion-summary-grid {
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
.summary-card.info { border-left: 3px solid #06b6d4; }

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
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e293b;
}

.chart-header p {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 1rem;
    font-weight: 500;
}

.completion-chart {
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

.completion-chart-canvas {
    flex: 0 1 420px;
    min-height: 280px;
}

.completion-chart-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.completion-legend-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.completion-legend-item.completed {
    border-left: 4px solid #10b981;
}

.completion-legend-item.incomplete {
    border-left: 4px solid #ef4444;
}

.legend-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
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
    margin: 4px 0;
}

.completion-legend-item p {
    margin: 4px 0 0;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
}

.course-completion-bar-chart {
    margin-top: 0;
}

.chart-search-container {
    margin-bottom: 20px;
    padding: 0 4px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.search-box-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 500px;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 1rem;
    z-index: 1;
}

#courseCompletionSearch {
    width: 100%;
    padding: 12px 45px 12px 42px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #1e293b;
    background: #fff;
    transition: all 0.3s ease;
    outline: none;
}

#courseCompletionSearch:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

#courseCompletionSearch::placeholder {
    color: #94a3b8;
}

.clear-search-btn {
    position: absolute;
    right: 10px;
    background: #e2e8f0;
    border: none;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 1;
}

.clear-search-btn:hover {
    background: #cbd5e1;
    transform: scale(1.1);
}

.clear-search-btn i {
    color: #64748b;
    font-size: 0.85rem;
}

.bar-chart-container {
    width: 100%;
    min-height: 520px;
    position: relative;
    padding: 25px 15px 20px;
    overflow-x: auto;
    overflow-y: hidden;
    background: #fafbfc;
    border-radius: 8px;
    -webkit-overflow-scrolling: touch;
}

.bar-chart-container::-webkit-scrollbar {
    height: 12px;
}

.bar-chart-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
}

.bar-chart-container::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 999px;
    border: 2px solid #f1f5f9;
}

.bar-chart-container::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* Firefox scrollbar */
.bar-chart-container {
    scrollbar-width: auto;
    scrollbar-color: #94a3b8 #f1f5f9;
}

.bar-chart-scroll {
    min-width: 100%;
    width: max-content;
    display: inline-block;
}

/* Course Details Table Styles */
.course-details-table-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    padding: 24px;
    margin-top: 0;
}

.table-header-section {
    margin-bottom: 20px;
}

.table-header-section h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header-section h3 i {
    color: #3b82f6;
}

.table-search-container {
    margin-bottom: 20px;
    display: flex;
    justify-content: flex-start;
}

.table-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 500px;
    width: 100%;
}

.table-search-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 1rem;
    z-index: 1;
}

#courseDetailsSearch {
    width: 100%;
    padding: 12px 120px 12px 42px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #1e293b;
    background: #fff;
    transition: all 0.3s ease;
    outline: none;
}

#courseDetailsSearch:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.clear-table-search-btn {
    position: absolute;
    right: 10px;
    background: #ef4444;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 1;
}

.clear-table-search-btn:hover {
    background: #dc2626;
    transform: scale(1.05);
}

.table-responsive-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.course-details-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.course-details-table thead th {
    background: #f8fafc;
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    font-size: 0.85rem;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.course-details-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s ease;
}

.course-details-table tbody tr:hover {
    background-color: #f8fafc;
}

.course-details-table tbody tr.highlighted {
    background-color: #fef3c7;
}

.course-details-table tbody td {
    padding: 16px;
    vertical-align: middle;
}

.course-name-cell {
    font-weight: 600;
}

.course-link {
    color: #3b82f6;
    text-decoration: none;
    transition: color 0.2s ease;
}

.course-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
    min-width: 40px;
    text-align: center;
}

.badge-blue {
    background: #dbeafe;
    color: #1e40af;
}

.badge-green {
    background: #d1fae5;
    color: #065f46;
}

.completion-rate-value {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.95rem;
}

.view-report-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s ease;
}

.view-report-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    flex-wrap: wrap;
    gap: 12px;
}

.table-footer-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #64748b;
}

.table-footer-left label {
    font-weight: 600;
}

.table-footer-left select {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    background: #fff;
    cursor: pointer;
}

.table-footer-right {
    font-size: 0.9rem;
    color: #64748b;
    font-weight: 600;
}

.table-pagination-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
}

.pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.page-numbers {
    display: flex;
    gap: 6px;
    align-items: center;
}

.page-numbers button {
    padding: 8px 14px;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 40px;
}

.page-numbers button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.page-numbers button.active {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    color: #cbd5e1;
}

.empty-state p {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

@media (max-width: 1024px) {
    .pie-flex-layout {
        flex-direction: column;
        align-items: flex-start;
    }
    .completion-chart-canvas {
        width: 100%;
    }
    .bar-chart-container {
        min-height: 420px;
    }
}
}
</style>

<script>
(function() {
    const pieValues = <?php echo $pie_values_json ?: '[0,0]'; ?>;

    if (typeof Chart !== 'undefined' && document.getElementById('completionPieChart')) {
        const ctx = document.getElementById('completionPieChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Total completed courses', 'Total incomplete courses'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 4
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
                },
                animation: {
                    animateRotate: true,
                    animateScale: true
                }
            }
        });
    } else if (document.getElementById('completionPieChart')) {
        // Chart.js not loaded yet, wait for it
        const checkChart = setInterval(() => {
            if (typeof Chart !== 'undefined') {
                clearInterval(checkChart);
                const ctx = document.getElementById('completionPieChart').getContext('2d');
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: ['Total completed courses', 'Total incomplete courses'],
                        datasets: [{
                            data: pieValues,
                            backgroundColor: ['#10b981', '#ef4444'],
                            borderWidth: 0,
                            hoverOffset: 4
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
                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true
                        }
                    }
                });
            }
        }, 100);
        
        // Clear interval after 10 seconds to prevent infinite checking
        setTimeout(() => clearInterval(checkChart), 10000);
    }

    // Horizontal Bar Chart for Course-wise Completion Rates
    const barLabels = <?php echo $bar_labels_json ?: '[]'; ?>;
    const barValues = <?php echo $bar_values_json ?: '[]'; ?>;
    let completionBarChart = null;
    let searchTerm = '';

    function getBarColor(value, label, isHighlighted) {
        if (isHighlighted) {
            // Highlighted bars - bright yellow/gold
            return 'rgba(251, 191, 36, 1)';
        }
        if (value >= 80) return 'rgba(16, 185, 129, 1)'; // Bright green
        if (value >= 40) return 'rgba(245, 158, 11, 1)'; // Bright orange
        return 'rgba(239, 68, 68, 1)'; // Bright red
    }

    function getBarBorderColor(value, label, isHighlighted) {
        if (isHighlighted) {
            return '#d97706'; // Darker gold border
        }
        if (value >= 80) return '#047857';
        if (value >= 40) return '#b45309';
        return '#b91c1c';
    }

    function updateChartHighlight() {
        if (!completionBarChart) return;

        const searchLower = searchTerm.toLowerCase().trim();
        const isHighlighted = searchLower.length > 0;

        completionBarChart.data.datasets[0].backgroundColor = barLabels.map((label, index) => {
            const value = barValues[index] || 0;
            const matches = label.toLowerCase().includes(searchLower);
            return getBarColor(value, label, matches && isHighlighted);
        });

        completionBarChart.data.datasets[0].borderColor = barLabels.map((label, index) => {
            const value = barValues[index] || 0;
            const matches = label.toLowerCase().includes(searchLower);
            return getBarBorderColor(value, label, matches && isHighlighted);
        });

        completionBarChart.data.datasets[0].borderWidth = barLabels.map((label) => {
            const matches = label.toLowerCase().includes(searchLower);
            return matches && isHighlighted ? 4 : 2;
        });

        completionBarChart.update('none');

        // Scroll to first highlighted course
        if (isHighlighted) {
            const firstMatchIndex = barLabels.findIndex(label => 
                label.toLowerCase().includes(searchLower)
            );
            if (firstMatchIndex >= 0) {
                scrollToCourse(firstMatchIndex);
            }
        }
    }

    function scrollToCourse(index) {
        const container = document.querySelector('.bar-chart-container');
        if (!container) return;

        const canvas = document.getElementById('courseCompletionBarChart');
        if (!canvas) return;

        // Calculate approximate position (150px per course for proper spacing)
        const scrollPosition = index * 150;
        container.scrollTo({
            left: Math.max(0, scrollPosition - 200),
            behavior: 'smooth'
        });
    }

    function initBarChart() {
        const barChartCanvas = document.getElementById('courseCompletionBarChart');
        if (!barChartCanvas) return;

        if (typeof Chart !== 'undefined') {
            const ctx = barChartCanvas.getContext('2d');
            // Calculate width with proper spacing: 150px per course to give adequate spacing
            const baseWidth = Math.max(barLabels.length * 150, barChartCanvas.parentElement.offsetWidth || 0);
            barChartCanvas.width = baseWidth;
            barChartCanvas.height = 480;
            
            completionBarChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: barLabels,
                    datasets: [{
                        label: 'Completion Rate (%)',
                        data: barValues,
                        backgroundColor: function(context) {
                            const value = context.parsed.y || 0;
                            const label = barLabels[context.dataIndex] || '';
                            const matches = searchTerm.length > 0 && label.toLowerCase().includes(searchTerm.toLowerCase().trim());
                            return getBarColor(value, label, matches);
                        },
                        borderColor: function(context) {
                            const value = context.parsed.y || 0;
                            const label = barLabels[context.dataIndex] || '';
                            const matches = searchTerm.length > 0 && label.toLowerCase().includes(searchTerm.toLowerCase().trim());
                            return getBarBorderColor(value, label, matches);
                        },
                        borderWidth: 2,
                        borderRadius: 8,
                        barThickness: 45,
                        maxBarThickness: 50,
                        minBarLength: 3,
                        shadowOffsetX: 2,
                        shadowOffsetY: 2,
                        shadowBlur: 4,
                        shadowColor: 'rgba(0, 0, 0, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    onHover: function(event, activeElements) {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    },
                    layout: {
                        padding: {
                            left: 20,
                            right: 30,
                            top: 15,
                            bottom: 15
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#1e293b',
                            borderWidth: 2,
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 14,
                                weight: '600'
                            },
                            callbacks: {
                                title: function(context) {
                                    return context[0].label;
                                },
                                label: function(context) {
                                    return 'Completion Rate: ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                stepSize: 20,
                                font: {
                                    size: 14,
                                    weight: '700',
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                padding: 10
                            },
                            grid: {
                                color: '#e5e7eb',
                                lineWidth: 1
                            },
                            title: {
                                display: true,
                                text: 'Completion Rate (%)',
                                font: {
                                    weight: 'bold',
                                    size: 16,
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                padding: {
                                    top: 10,
                                    bottom: 10
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 13,
                                    weight: '600',
                                    family: "'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif"
                                },
                                color: '#1e293b',
                                maxRotation: 45,
                                minRotation: 45,
                                padding: 15,
                                autoSkip: false
                            },
                            grid: {
                                display: false
                            },
                            categoryPercentage: 0.5,
                            barPercentage: 0.6
                        }
                    },
                    animation: {
                        duration: 1000
                    }
                }
            });
        } else if (barChartCanvas) {
            // Chart.js not loaded yet, wait for it
            const checkBarChart = setInterval(() => {
                if (typeof Chart !== 'undefined') {
                    clearInterval(checkBarChart);
                    initBarChart();
                }
            }, 100);
            
            // Clear interval after 10 seconds
            setTimeout(() => clearInterval(checkBarChart), 10000);
        }
    }

    // Initialize bar chart
    if (barLabels.length > 0 && barValues.length > 0) {
        initBarChart();
    }

    // Search functionality
    const searchInput = document.getElementById('courseCompletionSearch');
    const clearBtn = document.getElementById('clearSearchBtn');

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

    function handleSearch() {
        searchTerm = searchInput ? searchInput.value : '';
        if (clearBtn) {
            clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
        }
        updateChartHighlight();
    }

    function clearSearch() {
        if (searchInput) {
            searchInput.value = '';
            searchTerm = '';
        }
        if (clearBtn) {
            clearBtn.style.display = 'none';
        }
        updateChartHighlight();
    }

    if (searchInput) {
        searchInput.addEventListener('input', debounce(handleSearch, 300));
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Escape') {
                clearSearch();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearSearch);
    }

    // Course Details Table Search and Pagination
    const courseDetailsTable = document.getElementById('courseDetailsTable');
    if (courseDetailsTable) {
        const tableSearchInput = document.getElementById('courseDetailsSearch');
        const clearTableSearchBtn = document.getElementById('clearTableSearchBtn');
        const tableRows = Array.from(courseDetailsTable.querySelectorAll('tbody tr'));
        const pageSizeSelect = document.getElementById('courseDetailsPageSize');
        const prevBtn = document.getElementById('courseDetailsPrevPage');
        const nextBtn = document.getElementById('courseDetailsNextPage');
        const pageInfo = document.getElementById('courseDetailsPageInfo');
        const pageNumbers = document.getElementById('courseDetailsPageNumbers');

        let filteredRows = [...tableRows];
        let currentPage = 1;
        let pageSize = 10;

        function filterTable() {
            const searchTerm = tableSearchInput ? tableSearchInput.value.toLowerCase().trim() : '';
            
            filteredRows = tableRows.filter(row => {
                const courseName = row.dataset.courseName || '';
                return courseName.includes(searchTerm);
            });

            // Highlight matching rows
            tableRows.forEach(row => {
                const courseName = row.dataset.courseName || '';
                if (searchTerm && courseName.includes(searchTerm)) {
                    row.classList.add('highlighted');
                } else {
                    row.classList.remove('highlighted');
                }
            });

            if (clearTableSearchBtn) {
                clearTableSearchBtn.style.display = searchTerm.length > 0 ? 'block' : 'none';
            }

            currentPage = 1;
            renderTable();
        }

        function renderTable() {
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentPage = Math.min(Math.max(currentPage, 1), totalPages);

            // Hide all rows
            tableRows.forEach(row => row.style.display = 'none');

            // Show rows for current page
            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            filteredRows.slice(start, end).forEach(row => row.style.display = '');

            // Update page info
            if (pageInfo) {
                const startNum = filteredRows.length === 0 ? 0 : start + 1;
                const endNum = Math.min(end, filteredRows.length);
                const total = filteredRows.length;
                pageInfo.textContent = `Showing ${startNum} to ${endNum} of ${total} entries`;
            }

            // Update pagination buttons
            if (prevBtn) prevBtn.disabled = currentPage === 1 || filteredRows.length === 0;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages || filteredRows.length === 0;

            // Update page numbers
            if (pageNumbers) {
                pageNumbers.innerHTML = '';
                const maxPages = Math.min(totalPages, 10);
                let startPage = Math.max(1, currentPage - 4);
                let endPage = Math.min(totalPages, startPage + maxPages - 1);
                
                if (endPage - startPage < maxPages - 1) {
                    startPage = Math.max(1, endPage - maxPages + 1);
                }

                for (let i = startPage; i <= endPage; i++) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    if (i === currentPage) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => {
                        currentPage = i;
                        renderTable();
                    });
                    pageNumbers.appendChild(btn);
                }
            }
        }

        if (tableSearchInput) {
            tableSearchInput.addEventListener('input', debounce(filterTable, 300));
        }

        if (clearTableSearchBtn) {
            clearTableSearchBtn.addEventListener('click', () => {
                if (tableSearchInput) tableSearchInput.value = '';
                filterTable();
            });
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', () => {
                pageSize = parseInt(pageSizeSelect.value, 10) || 10;
                currentPage = 1;
                renderTable();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        }

        // Initial render
        renderTable();
    }
})();
</script>