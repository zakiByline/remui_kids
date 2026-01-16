<?php
/**
 * Course Completion Report by Month - School Manager
 * Shows monthly completion statistics for a specific course
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
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
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

// Get course ID from URL
$courseid = required_param('courseid', PARAM_INT);

// Verify course belongs to this company
$course_in_company = $DB->record_exists('company_course', ['courseid' => $courseid, 'companyid' => $company_info->id]);

if (!$course_in_company) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/course_reports.php', 
        'Course not found in your school.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get course details
$course = $DB->get_record('course', ['id' => $courseid]);

if (!$course) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/course_reports.php', 
        'Course not found.');
}

// Get completion data by month for the last 12 months (students only)
// Track both full course completions AND module/activity completions
$monthly_data = [];
$monthly_module_completions = [];
$months_labels = [];

for ($i = 11; $i >= 0; $i--) {
    $month_start = strtotime("-$i months", strtotime('first day of this month'));
    $month_end = strtotime('last day of this month', $month_start);
    
    $month_label = date('F Y', $month_start);
    $months_labels[] = date('F', $month_start);
    
    // Get FULL course completions for this month (students only from this school)
    $completions = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cc.userid)
         FROM {course_completions} cc
         INNER JOIN {user} u ON u.id = cc.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = cc.course
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cc.course = ?
         AND cc.timecompleted IS NOT NULL
         AND cc.timecompleted >= ?
         AND cc.timecompleted <= ?
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$courseid, $month_start, $month_end, $company_info->id]
    );
    
    // Get module/activity completions for this month
    $module_completions = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cmc.id)
         FROM {course_modules_completion} cmc
         INNER JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
         INNER JOIN {user} u ON u.id = cmc.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = cm.course
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cm.course = ?
         AND cm.visible = 1
         AND cm.deletioninprogress = 0
         AND cm.completion > 0
         AND (cmc.completionstate = 1 OR cmc.completionstate = 2 OR cmc.completionstate = 3)
         AND cmc.timemodified >= ?
         AND cmc.timemodified <= ?
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$courseid, $month_start, $month_end, $company_info->id]
    );
    
    $monthly_data[] = $completions;
    $monthly_module_completions[] = $module_completions;
}

// Get total enrolled students
$total_enrolled = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id) 
     FROM {user} u
     INNER JOIN {user_enrolments} ue ON ue.userid = u.id
     INNER JOIN {enrol} e ON e.id = ue.enrolid
     INNER JOIN {company_users} cu ON cu.userid = u.id
     INNER JOIN {role_assignments} ra ON ra.userid = u.id
     INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
     INNER JOIN {role} r ON r.id = ra.roleid
     WHERE e.courseid = ? 
     AND ue.status = 0
     AND cu.companyid = ?
     AND r.shortname = 'student'
     AND u.deleted = 0
     AND u.suspended = 0",
    [$courseid, $company_info->id]
);

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/school_manager/course_completion_monthly.php', ['courseid' => $courseid]);
$PAGE->set_title('Completion report by month - ' . $course->fullname);
$PAGE->set_heading('Completion report by month');

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'course_reports',
    'course_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

// Render sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* CRITICAL: Force sidebar to always be visible */
.school-manager-sidebar,
body .school-manager-sidebar,
html .school-manager-sidebar {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    position: fixed !important;
    top: 55px !important;
    left: 0 !important;
    width: 280px !important;
    height: calc(100vh - 55px) !important;
    z-index: 100000 !important;
    pointer-events: auto !important;
    transform: translateX(0) !important;
}

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    font-family: 'Poppins', sans-serif;
    padding: 25px;
}

.main-content {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 25px;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    margin: 0 0 10px 0;
    color: #1f2937;
}

.back-button {
    padding: 10px 20px;
    background: #ffffff;
    color: #4b5563;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
}

.back-button:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.info-section {
    background: white;
    border-radius: 12px;
    padding: 20px 25px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
}

.info-label {
    font-weight: 600;
    color: #374151;
    font-size: 0.95rem;
    margin-bottom: 8px;
    display: block;
}

.info-field {
    padding: 10px 15px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.9rem;
    color: #6b7280;
    display: block;
    width: 100%;
    max-width: 400px;
}

.search-options {
    display: flex;
    gap: 20px;
    margin-top: 15px;
}

.search-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: color 0.2s ease;
}

.search-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
}

.chart-container {
    position: relative;
    height: 400px;
    margin-bottom: 20px;
}

.show-data-link {
    color: #3b82f6;
    font-size: 0.9rem;
    cursor: pointer;
    text-decoration: none;
    font-weight: 500;
    display: inline-block;
}

.show-data-link:hover {
    text-decoration: underline;
}

.chart-data-table {
    margin-top: 20px;
    padding: 20px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 10px;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 10px;
    color: #6b7280;
    border-bottom: 1px solid #f3f4f6;
}

.data-table tr:last-child td {
    border-bottom: none;
}

.no-data-message {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
    font-size: 1.1rem;
}

@media (max-width: 1024px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
    }
}

@media (max-width: 768px) {
    .school-manager-main-content {
        padding: 20px;
    }
    
    .page-title {
        font-size: 1.6rem;
    }
    
    .search-options {
        flex-direction: column;
        gap: 10px;
    }
    
    .chart-container {
        height: 300px;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        
        <!-- Back Button -->
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/course_reports.php" class="back-button">
            <i class="fa fa-arrow-left"></i> Back to Course Reports
        </a>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Completion report by month</h1>
        </div>
        
        <!-- Course Info Section -->
        <div class="info-section">
            <label class="info-label">Department: <?php echo htmlspecialchars($company_info->name); ?></label>
            <div class="info-field"><?php echo htmlspecialchars($company_info->name); ?></div>
            
            <div class="search-options">
                <a href="#" class="search-link" onclick="showCourseSearch(); return false;">
                    Course search <i class="fa fa-chevron-right"></i>
                </a>
                <a href="#" class="search-link" onclick="showDateSearch(); return false;">
                    Date search <i class="fa fa-chevron-right"></i>
                </a>
            </div>
        </div>
        
        <!-- Course Information -->
        <div class="info-section">
            <label class="info-label">Course:</label>
            <div class="info-field"><?php echo htmlspecialchars($course->fullname); ?></div>
        </div>
        
        <!-- Chart Section -->
        <div class="chart-card">
            <?php if ($total_enrolled > 0 && (array_sum($monthly_data) > 0 || array_sum($monthly_module_completions) > 0)): ?>
                <div class="chart-container">
                    <canvas id="completionChart"></canvas>
                </div>
                
                <a href="#" class="show-data-link" onclick="toggleChartData(); return false;">
                    Show chart data
                </a>
                
                <div id="chart-data" class="chart-data-table" style="display: none;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Module Completions</th>
                                <th>Full Course Completions</th>
                                <th>Cumulative Modules</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cumulative = 0;
                            $cumulative_modules = 0;
                            for ($i = 0; $i < count($months_labels); $i++) {
                                $cumulative += $monthly_data[$i];
                                $cumulative_modules += $monthly_module_completions[$i];
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($months_labels[$i]) . "</td>";
                                echo "<td>" . $monthly_module_completions[$i] . "</td>";
                                echo "<td>" . $monthly_data[$i] . "</td>";
                                echo "<td>" . $cumulative_modules . "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data-message">
                    <i class="fa fa-chart-line" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                    <p>No completion data available for this course yet.</p>
                    <p style="font-size: 0.9rem; margin-top: 10px;">Students need to start completing activities or modules for data to appear.</p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

<script>
// Monthly completion chart
<?php if ($total_enrolled > 0 && (array_sum($monthly_data) > 0 || array_sum($monthly_module_completions) > 0)): ?>
    const ctx = document.getElementById('completionChart');
    const monthlyData = <?php echo json_encode($monthly_data); ?>;
    const monthlyModuleData = <?php echo json_encode($monthly_module_completions); ?>;
    const monthLabels = <?php echo json_encode($months_labels); ?>;
    
    // Calculate cumulative data for full completions
    let cumulative = 0;
    const cumulativeData = monthlyData.map(val => {
        cumulative += val;
        return cumulative;
    });
    
    // Calculate cumulative data for module completions
    let cumulativeModules = 0;
    const cumulativeModuleData = monthlyModuleData.map(val => {
        cumulativeModules += val;
        return cumulativeModules;
    });
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Module Completions',
                data: monthlyModuleData,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverRadius: 7
            }, {
                label: 'Full Course Completions',
                data: monthlyData,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverRadius: 7
            }, {
                label: 'Cumulative Module Completions',
                data: cumulativeModuleData,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#f59e0b',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 12,
                            family: 'Poppins'
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        family: 'Poppins'
                    },
                    titleFont: {
                        family: 'Poppins',
                        weight: 'bold'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: {
                            family: 'Poppins'
                        }
                    },
                    grid: {
                        color: '#f3f4f6'
                    }
                },
                x: {
                    ticks: {
                        font: {
                            family: 'Poppins'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
<?php endif; ?>

// Toggle chart data visibility
function toggleChartData() {
    const dataDiv = document.getElementById('chart-data');
    const link = event.target;
    
    if (dataDiv.style.display === 'none') {
        dataDiv.style.display = 'block';
        link.textContent = 'Hide chart data';
    } else {
        dataDiv.style.display = 'none';
        link.textContent = 'Show chart data';
    }
}

// Placeholder functions for search
function showCourseSearch() {
    alert('Course search functionality - Navigate to course selection');
}

function showDateSearch() {
    alert('Date search functionality - Filter by custom date range');
}
</script>

<?php
echo $OUTPUT->footer();
?>

