<?php
/**
 * C Reports - Course Distribution Reports Tab (AJAX fragment)
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
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'distribution']);
    redirect($target);
}

$distribution_data = [];
if ($company_info) {
    // Get course distribution by category
    $distributions = $DB->get_records_sql(
        "SELECT cat.id, cat.name as category_name,
                COUNT(DISTINCT c.id) as course_count,
                COUNT(DISTINCT ue.userid) as total_enrollments
         FROM {course_categories} cat
         LEFT JOIN {course} c ON c.category = cat.id AND c.visible = 1 AND c.id > 1
         LEFT JOIN {company_course} cc_link ON cc_link.courseid = c.id AND cc_link.companyid = ?
         LEFT JOIN {user_enrolments} ue ON ue.enrolid IN (
             SELECT e.id FROM {enrol} e WHERE e.courseid = c.id AND e.status = 0
         )
         WHERE (cc_link.companyid = ? OR cc_link.companyid IS NULL)
         GROUP BY cat.id, cat.name
         HAVING course_count > 0
         ORDER BY course_count DESC, cat.name ASC",
        [$company_info->id, $company_info->id]
    );
    
    foreach ($distributions as $dist) {
        $distribution_data[] = [
            'category_id' => $dist->id,
            'category_name' => $dist->category_name ?: 'Uncategorized',
            'course_count' => (int)$dist->course_count,
            'total_enrollments' => (int)$dist->total_enrollments
        ];
    }
}

?>
<div class="report-table-container">
    <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
        <i class="fa fa-chart-pie" style="color: #8b5cf6;"></i> Course Distribution Reports
    </h3>
    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">Course distribution by category for <?php echo htmlspecialchars($company_info ? $company_info->name : 'the school'); ?>.</p>

    <?php if (!empty($distribution_data)): ?>
    <div style="overflow-x: auto;">
        <table class="student-performance-table">
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th style="text-align:center;">Number of Courses</th>
                    <th style="text-align:center;">Total Enrollments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($distribution_data as $dist): ?>
                <tr>
                    <td>
                        <div style="font-weight: 600; color: #1f2937;">
                            <?php echo htmlspecialchars($dist['category_name']); ?>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <span class="average-grade-badge grade-na" style="background: rgba(99,102,241,0.15); color:#3730a3;">
                            <?php echo number_format($dist['course_count']); ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <span class="average-grade-badge grade-high" style="background: rgba(16,185,129,0.15); color:#047857;">
                            <?php echo number_format($dist['total_enrollments']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="student-empty-state">
        <i class="fa fa-chart-pie"></i>
        <p style="color: #6b7280; font-weight: 600; margin: 0;">No distribution data available yet.</p>
    </div>
    <?php endif; ?>
</div>

<style>
.report-table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.student-performance-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    min-width: 600px;
}

.student-performance-table thead th {
    padding: 14px;
    text-align: left;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    border-bottom: 2px solid #e5e7eb;
}

.student-performance-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
}

.average-grade-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 999px;
    font-weight: 700;
    min-width: 70px;
}

.grade-high { background: rgba(16, 185, 129, 0.15); color: #047857; }
.grade-na { background: #f1f5f9; color: #94a3b8; }

.student-empty-state {
    background: #f8fafc;
    border-radius: 12px;
    padding: 40px 30px;
    text-align: center;
    border: 1px dashed #cbd5f5;
}

.student-empty-state i {
    font-size: 3rem;
    color: #cbd5f5;
    margin-bottom: 12px;
    display: block;
}
</style>




































