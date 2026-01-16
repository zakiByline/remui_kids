<?php
/**
 * Course Activity Report - School Manager
 * Shows activity and engagement statistics for all courses
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied.');
}

// Get company information
$company_info = $DB->get_record_sql(
    "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.');
}

// Get all courses with activity statistics
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname, c.startdate, c.visible, c.timecreated,
            (SELECT COUNT(DISTINCT ue.userid) 
             FROM {user_enrolments} ue 
             JOIN {enrol} e ON ue.enrolid = e.id 
             WHERE e.courseid = c.id AND ue.status = 0) as total_enrolled,
            (SELECT COUNT(DISTINCT l.userid)
             FROM {logstore_standard_log} l
             JOIN {user_enrolments} ue ON ue.userid = l.userid
             JOIN {enrol} e ON ue.enrolid = e.id
             WHERE e.courseid = c.id 
             AND l.courseid = c.id
             AND l.timecreated >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))) as active_users_30d,
            (SELECT COUNT(*)
             FROM {logstore_standard_log} l
             WHERE l.courseid = c.id
             AND l.timecreated >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))) as total_views_7d
     FROM {course} c
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     ORDER BY c.fullname ASC",
    [$company_info->id]
);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => ['fullname' => fullname($USER)],
    'current_page' => 'course_reports',
    'course_reports_active' => true,
    'config' => ['wwwroot' => $CFG->wwwroot]
];

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/school_manager/course_activity_report.php');
$PAGE->set_title('Course Activity Report - ' . $company_info->name);

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
    padding: 20px;
}

.main-content {
    max-width: 1600px;
    margin: 0 auto;
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

.back-btn {
    background: #b2dfdb;
    color: #36454f;
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.back-btn:hover {
    background: #a0cfc9;
    transform: translateY(-2px);
}

.report-card {
    background: white;
    border-radius: 16px;
    padding: 35px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.report-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 25px 0;
}

.download-section {
    padding: 20px;
    background: #f9fafb;
    border-radius: 10px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.download-label {
    font-weight: 600;
    color: #4b5563;
}

.download-select {
    padding: 10px 15px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    background: white;
}

.download-btn {
    padding: 10px 24px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead {
    background: white;
    border-bottom: 2px solid #e5e7eb;
}

.report-table thead th {
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.report-table tbody tr {
    background: white;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f0f2f5;
}

.report-table tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.report-table tbody td {
    padding: 12px 15px;
    color: #1f2937;
    font-size: 0.9rem;
}

.course-name-link {
    color: #3b82f6;
    font-weight: 600;
    text-decoration: none;
}

.course-name-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.activity-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 12px;
    font-weight: 700;
}

.activity-badge.high {
    background: #dcfce7;
    color: #166534;
}

.activity-badge.medium {
    background: #fef3c7;
    color: #b45309;
}

.activity-badge.low {
    background: #fee2e2;
    color: #991b1b;
}

.engagement-bar {
    display: flex;
    align-items: center;
    gap: 10px;
}

.engagement-progress {
    flex: 1;
    height: 8px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
}

.engagement-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.engagement-fill.high {
    background: linear-gradient(90deg, #10b981, #059669);
}

.engagement-fill.medium {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.engagement-fill.low {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

.engagement-percent {
    font-weight: 700;
    min-width: 45px;
}

.engagement-percent.high {
    color: #059669;
}

.engagement-percent.medium {
    color: #d97706;
}

.engagement-percent.low {
    color: #dc2626;
}

.views-count {
    display: inline-block;
    padding: 5px 12px;
    background: #e0e7ff;
    color: #4f46e5;
    border-radius: 12px;
    font-weight: 700;
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Course Activity Report</h1>
                <p class="page-subtitle">Activity and engagement statistics for <?php echo htmlspecialchars($company_info->name); ?></p>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/course_reports.php" class="back-btn">
                <i class="fa fa-arrow-left"></i> Back to Course Reports
            </a>
        </div>
        
        <!-- Report Card -->
        <div class="report-card">
            <h2 class="report-title">Course Activity Statistics</h2>
            
            <!-- Download Section -->
            <div class="download-section">
                <span class="download-label">Download table data as</span>
                <select class="download-select">
                    <option>Comma separated values (.csv)</option>
                </select>
                <button class="download-btn" onclick="downloadReport()">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
            
            <!-- Courses Table -->
            <table class="report-table" id="activity_table">
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Total Enrolled</th>
                        <th>Active Users (30 days)</th>
                        <th>Engagement Rate</th>
                        <th>Total Views (7 days)</th>
                        <th>Activity Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($courses)) {
                        echo "<tr><td colspan='6' style='text-align: center; padding: 40px; color: #9ca3af;'>No courses found.</td></tr>";
                    } else {
                        foreach ($courses as $course) {
                            $total_enrolled = $course->total_enrolled ?? 0;
                            $active_users = $course->active_users_30d ?? 0;
                            $total_views = $course->total_views_7d ?? 0;
                            
                            // Calculate engagement rate
                            $engagement_rate = $total_enrolled > 0 ? round(($active_users / $total_enrolled) * 100) : 0;
                            
                            // Determine activity level
                            $activity_level = 'Low';
                            $activity_class = 'low';
                            $engagement_class = 'low';
                            
                            if ($engagement_rate >= 70) {
                                $activity_level = 'High';
                                $activity_class = 'high';
                                $engagement_class = 'high';
                            } elseif ($engagement_rate >= 40) {
                                $activity_level = 'Medium';
                                $activity_class = 'medium';
                                $engagement_class = 'medium';
                            }
                            
                            echo "<tr>";
                            echo "<td><a href='{$CFG->wwwroot}/course/view.php?id={$course->id}' class='course-name-link'>" . htmlspecialchars($course->fullname) . "</a></td>";
                            echo "<td>" . $total_enrolled . "</td>";
                            echo "<td>" . $active_users . "</td>";
                            echo "<td>";
                            echo "<div class='engagement-bar'>";
                            echo "<div class='engagement-progress'><div class='engagement-fill {$engagement_class}' style='width: {$engagement_rate}%'></div></div>";
                            echo "<span class='engagement-percent {$engagement_class}'>{$engagement_rate}%</span>";
                            echo "</div>";
                            echo "</td>";
                            echo "<td><span class='views-count'>" . $total_views . "</span></td>";
                            echo "<td><span class='activity-badge {$activity_class}'>" . $activity_level . "</span></td>";
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
    </div>
</div>

<script>
function downloadReport() {
    const table = document.getElementById('activity_table');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            let text = col.innerText.replace(/\n/g, ' ').trim();
            text = text.replace(/"/g, '""');
            if (text.includes(',')) {
                text = '"' + text + '"';
            }
            rowData.push(text);
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'course_activity_report_<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
echo $OUTPUT->footer();
?>



