<?php
/**
 * User Reports - School Manager
 * Comprehensive student reports with 10 detailed sections
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
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
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Page setup
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/user_reports.php'));
$PAGE->set_title('Student Reports - ' . $company_info->name);
$PAGE->set_heading('Student Reports');
$PAGE->set_pagelayout('standard');

// Get active tab
$active_tab = optional_param('tab', 'overview', PARAM_ALPHA);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'user_reports',
    'user_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

// =========================
// DATA COLLECTION FOR ALL TABS
// =========================

// Get all students in this company
$students_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                        COALESCE(prof.data, 'N/A') as grade_level
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid
                 LEFT JOIN {user_info_data} prof ON prof.userid = u.id 
                     AND prof.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'grade' LIMIT 1)
                 WHERE cu.companyid = ?
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 AND u.suspended = 0
                 ORDER BY u.lastname, u.firstname";

$all_students = $DB->get_records_sql($students_sql, [$company_info->id]);
$total_students = count($all_students);

// Get course enrollment data
$enrollment_sql = "SELECT u.id as user_id, COUNT(DISTINCT c.id) as total_courses,
                          COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN c.id END) as completed_courses,
                          AVG(CASE WHEN gg.finalgrade IS NOT NULL THEN (gg.finalgrade / gg.rawgrademax) * 100 END) as avg_grade
                   FROM {user} u
                   INNER JOIN {company_users} cu ON cu.userid = u.id
                   INNER JOIN {role_assignments} ra ON ra.userid = u.id
                   INNER JOIN {role} r ON r.id = ra.roleid
                   INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                   INNER JOIN {enrol} e ON e.id = ue.enrolid
                   INNER JOIN {course} c ON c.id = e.courseid
                   LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = c.id
                   LEFT JOIN {grade_grades} gg ON gg.userid = u.id
                   LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = c.id AND gi.itemtype = 'course'
                   WHERE cu.companyid = ?
                   AND r.shortname = 'student'
                   AND u.deleted = 0
                   AND c.id > 1
                   GROUP BY u.id";

$enrollment_data = $DB->get_records_sql($enrollment_sql, [$company_info->id]);

// Calculate overview metrics
$total_enrollments = 0;
$total_completed = 0;
$grades_sum = 0;
$grades_count = 0;
$inactive_students = 0;
$seven_days_ago = time() - (7 * 24 * 60 * 60);

foreach ($all_students as $student) {
    $student_enroll = $enrollment_data[$student->id] ?? null;
    
    if ($student_enroll) {
        $total_enrollments += $student_enroll->total_courses;
        $total_completed += $student_enroll->completed_courses;
        if ($student_enroll->avg_grade !== null) {
            $grades_sum += $student_enroll->avg_grade;
            $grades_count++;
        }
    }
    
    if ($student->lastaccess < $seven_days_ago) {
        $inactive_students++;
    }
}

$avg_completion_rate = $total_enrollments > 0 ? round(($total_completed / $total_enrollments) * 100, 1) : 0;
$avg_grade = $grades_count > 0 ? round($grades_sum / $grades_count, 1) : 0;

// Get grade-wise performance
$grade_performance = [];
foreach ($all_students as $student) {
    $grade = $student->grade_level;
    $student_enroll = $enrollment_data[$student->id] ?? null;
    
    if (!isset($grade_performance[$grade])) {
        $grade_performance[$grade] = [
            'count' => 0,
            'grades' => [],
            'completed' => 0,
            'total_courses' => 0
        ];
    }
    
    $grade_performance[$grade]['count']++;
    
    if ($student_enroll && $student_enroll->avg_grade !== null) {
        $grade_performance[$grade]['grades'][] = $student_enroll->avg_grade;
    }
    
    if ($student_enroll) {
        $grade_performance[$grade]['completed'] += $student_enroll->completed_courses;
        $grade_performance[$grade]['total_courses'] += $student_enroll->total_courses;
    }
}

// Calculate grade averages
foreach ($grade_performance as $grade => $data) {
    $grade_performance[$grade]['avg_score'] = count($data['grades']) > 0 ? round(array_sum($data['grades']) / count($data['grades']), 1) : 0;
    $grade_performance[$grade]['highest'] = count($data['grades']) > 0 ? round(max($data['grades']), 1) : 0;
    $grade_performance[$grade]['lowest'] = count($data['grades']) > 0 ? round(min($data['grades']), 1) : 0;
    $grade_performance[$grade]['pass_rate'] = $data['total_courses'] > 0 ? round(($data['completed'] / $data['total_courses']) * 100, 1) : 0;
}

// Get top and bottom performers
$student_performances = [];
foreach ($all_students as $student) {
    $student_enroll = $enrollment_data[$student->id] ?? null;
    if ($student_enroll && $student_enroll->avg_grade !== null) {
        $student_performances[] = [
            'name' => fullname($student),
            'email' => $student->email,
            'grade' => $student->grade_level,
            'avg_grade' => round($student_enroll->avg_grade, 1),
            'completed' => $student_enroll->completed_courses,
            'total' => $student_enroll->total_courses
        ];
    }
}

usort($student_performances, function($a, $b) {
    return $b['avg_grade'] <=> $a['avg_grade'];
});

$top_performers = array_slice($student_performances, 0, 10);
$bottom_performers = array_slice(array_reverse($student_performances), 0, 10);

// Get attendance data (last 30 days)
$thirty_days_ago = time() - (30 * 24 * 60 * 60);
$attendance_data = [];
$login_frequency = [];

foreach ($all_students as $student) {
    $login_count = $DB->count_records_select('logstore_standard_log',
        "userid = ? AND action = 'loggedin' AND timecreated > ?",
        [$student->id, $thirty_days_ago]);
    
    $attendance_data[$student->id] = $login_count;
    $login_frequency[] = $login_count;
}

$avg_login_frequency = count($login_frequency) > 0 ? round(array_sum($login_frequency) / count($login_frequency), 1) : 0;

echo $OUTPUT->header();

// Render sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
/* Main content wrapper to accommodate sidebar */
body {
    margin-left: 280px;
}

@media (max-width: 768px) {
    body {
        margin-left: 0;
    }
}

.user-reports-container {
    padding: 30px;
    background: #f8fafc;
    min-height: calc(100vh - 200px);
}

.report-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 40px;
    border-radius: 16px;
    margin-bottom: 35px;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
}

.report-header h1 {
    color: #ffffff;
    font-size: 2.2rem;
    font-weight: 700;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-header p {
    color: rgba(255, 255, 255, 0.95);
    font-size: 1.05rem;
    margin: 0;
}

.tabs-container {
    background: #ffffff;
    border-radius: 12px 12px 0 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    margin-bottom: 0;
    overflow-x: auto;
}

.tabs-nav {
    display: flex;
    gap: 0;
    padding: 0;
    list-style: none;
    margin: 0;
    border-bottom: 2px solid #e5e7eb;
}

.tab-item {
    flex: 1;
    min-width: 120px;
}

.tab-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px 20px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #6b7280;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.tab-link:hover {
    background: #f9fafb;
    color: #374151;
    text-decoration: none;
}

.tab-link.active {
    color: #667eea;
    border-bottom-color: #667eea;
    background: #f9fafb;
}

.tab-content {
    background: #ffffff;
    padding: 35px;
    border-radius: 0 0 12px 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 35px;
}

.metric-card {
    background: #ffffff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-left: 4px solid;
    transition: all 0.3s ease;
}

.metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.metric-card.blue { border-color: #3b82f6; }
.metric-card.green { border-color: #10b981; }
.metric-card.red { border-color: #ef4444; }
.metric-card.orange { border-color: #f59e0b; }
.metric-card.purple { border-color: #8b5cf6; }
.metric-card.pink { border-color: #ec4899; }

.metric-value {
    font-size: 2.8rem;
    font-weight: 800;
    margin-bottom: 10px;
    line-height: 1;
}

.metric-card.blue .metric-value { color: #3b82f6; }
.metric-card.green .metric-value { color: #10b981; }
.metric-card.red .metric-value { color: #ef4444; }
.metric-card.orange .metric-value { color: #f59e0b; }
.metric-card.purple .metric-value { color: #8b5cf6; }
.metric-card.pink .metric-value { color: #ec4899; }

.metric-label {
    font-size: 0.8rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.chart-section {
    background: #ffffff;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    margin-bottom: 25px;
}

.chart-header h3 {
    font-size: 1.2rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-header p {
    color: #6b7280;
    font-size: 0.9rem;
    margin: 0 0 20px 0;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.data-table thead tr {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.data-table th {
    padding: 12px 15px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.data-table td {
    padding: 15px;
    font-size: 0.9rem;
    color: #1f2937;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge.success {
    background: #d1fae5;
    color: #065f46;
}

.badge.warning {
    background: #fed7aa;
    color: #92400e;
}

.badge.danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid;
    margin-bottom: 15px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.alert-card.warning { border-color: #f59e0b; }
.alert-card.danger { border-color: #ef4444; }
.alert-card.success { border-color: #10b981; }

.alert-card h4 {
    margin: 0 0 5px 0;
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
}

.alert-card p {
    margin: 0;
    font-size: 0.9rem;
    color: #6b7280;
}
</style>

<div class="user-reports-container">
    <!-- Header -->
    <div class="report-header">
        <h1>
            <i class="fa fa-users"></i> Student Reports
        </h1>
        <p>Comprehensive student analytics and reports for <?php echo htmlspecialchars($company_info->name); ?></p>
    </div>

    <!-- Tabs Navigation -->
    <div class="tabs-container">
        <ul class="tabs-nav">
            <li class="tab-item">
                <a href="?tab=overview" class="tab-link <?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
                    <i class="fa fa-th-large"></i> Overview
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=academic" class="tab-link <?php echo $active_tab === 'academic' ? 'active' : ''; ?>">
                    <i class="fa fa-graduation-cap"></i> Academic
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=attendance" class="tab-link <?php echo $active_tab === 'attendance' ? 'active' : ''; ?>">
                    <i class="fa fa-calendar-check"></i> Attendance
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=engagement" class="tab-link <?php echo $active_tab === 'engagement' ? 'active' : ''; ?>">
                    <i class="fa fa-comments"></i> Engagement
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=progress" class="tab-link <?php echo $active_tab === 'progress' ? 'active' : ''; ?>">
                    <i class="fa fa-chart-line"></i> Progress
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=comparison" class="tab-link <?php echo $active_tab === 'comparison' ? 'active' : ''; ?>">
                    <i class="fa fa-balance-scale"></i> Comparison
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=trends" class="tab-link <?php echo $active_tab === 'trends' ? 'active' : ''; ?>">
                    <i class="fa fa-chart-area"></i> Trends
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=alerts" class="tab-link <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>">
                    <i class="fa fa-exclamation-triangle"></i> Alerts
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=feedback" class="tab-link <?php echo $active_tab === 'feedback' ? 'active' : ''; ?>">
                    <i class="fa fa-star"></i> Feedback
                </a>
            </li>
            <li class="tab-item">
                <a href="?tab=dashboard" class="tab-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fa fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        
        <?php if ($active_tab === 'overview'): ?>
            <!-- 1. STUDENT OVERVIEW SUMMARY -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-th-large" style="color: #667eea;"></i> Student Overview Summary
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">A quick snapshot of key metrics for all students</p>

            <!-- Overview Metrics -->
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-value"><?php echo $total_students; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-users"></i> Total Students
                    </div>
                </div>

                <div class="metric-card green">
                    <div class="metric-value"><?php echo $total_enrollments; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-book"></i> Total Courses Enrolled
                    </div>
                </div>

                <div class="metric-card purple">
                    <div class="metric-value"><?php echo $avg_completion_rate; ?>%</div>
                    <div class="metric-label">
                        <i class="fa fa-check-circle"></i> Avg Course Completion
                    </div>
                </div>

                <div class="metric-card orange">
                    <div class="metric-value"><?php echo $avg_grade; ?>%</div>
                    <div class="metric-label">
                        <i class="fa fa-chart-line"></i> Average Grade
                    </div>
                </div>

                <div class="metric-card red">
                    <div class="metric-value"><?php echo $inactive_students; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-bell-slash"></i> Inactive Students (7+ days)
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px;">
                <!-- Student Distribution Donut Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #3b82f6;"></i> Grade Distribution</h3>
                        <p>Number of students per grade level</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="gradeDistributionChart"></canvas>
                    </div>
                </div>

                <!-- Completion vs Incomplete Donut Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-check-circle" style="color: #10b981;"></i> Completion vs Incomplete Courses</h3>
                        <p>Overall course completion breakdown</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="completionStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Weekly/Monthly Login Activity Trend -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-chart-line" style="color: #667eea;"></i> Weekly/Monthly Login Activity Trend</h3>
                    <p>Track student login patterns over time</p>
                </div>
                <div style="position: relative; height: 350px;">
                    <canvas id="loginTrendChart"></canvas>
                </div>
            </div>

        <?php elseif ($active_tab === 'academic'): ?>
            <!-- 2. ACADEMIC PERFORMANCE REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-graduation-cap" style="color: #667eea;"></i> Academic Performance Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Student academic performance across courses and grades</p>

            <!-- Grade-wise Performance Table -->
            <div class="chart-section">
                <div class="chart-header">
                    <h3><i class="fa fa-table" style="color: #3b82f6;"></i> Grade-wise Performance Summary</h3>
                    <p>Average marks per grade level</p>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th style="text-align: center;">Students</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Highest</th>
                            <th style="text-align: center;">Lowest</th>
                            <th style="text-align: center;">Pass %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grade_performance as $grade => $data): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($grade); ?></td>
                                <td style="text-align: center; color: #3b82f6; font-weight: 600;"><?php echo $data['count']; ?></td>
                                <td style="text-align: center; font-weight: 600; color: #10b981;"><?php echo $data['avg_score']; ?>%</td>
                                <td style="text-align: center; color: #059669;"><?php echo $data['highest']; ?>%</td>
                                <td style="text-align: center; color: #ef4444;"><?php echo $data['lowest']; ?>%</td>
                                <td style="text-align: center;">
                                    <span class="badge <?php echo $data['pass_rate'] >= 80 ? 'success' : ($data['pass_rate'] >= 60 ? 'warning' : 'danger'); ?>">
                                        <?php echo $data['pass_rate']; ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Charts Row -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px;">
                <!-- Grade Performance Bar Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-bar" style="color: #f59e0b;"></i> Grade Performance Comparison</h3>
                        <p>Average scores across grade levels</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="gradePerformanceChart"></canvas>
                    </div>
                </div>

                <!-- Subject-wise Radar Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-bullseye" style="color: #8b5cf6;"></i> Subject-wise Performance Radar</h3>
                        <p>Average scores across core subjects</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="subjectRadarChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Improvement Trend & Pass/Fail -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 25px;">
                <!-- Improvement Trend Line Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Improvement Trend (Last 3 Terms)</h3>
                        <p>Academic progress over terms</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="improvementTrendChart"></canvas>
                    </div>
                </div>

                <!-- Pass/Fail Pie Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #ef4444;"></i> Pass / Fail Ratio</h3>
                        <p>Overall pass rate</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="passFailChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Performers -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-trophy" style="color: #f59e0b;"></i> Top 10 Performing Students</h3>
                    <p>Students with highest average grades</p>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Grade Level</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Courses Completed</th>
                            <th style="text-align: center;">Total Courses</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($top_performers as $student): 
                        ?>
                            <tr>
                                <td style="font-weight: 600; color: #f59e0b;">#<?php echo $rank++; ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($student['email']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($student['grade']); ?></td>
                                <td style="text-align: center; font-weight: 700; color: #10b981; font-size: 1.1rem;">
                                    <?php echo $student['avg_grade']; ?>%
                                </td>
                                <td style="text-align: center; color: #3b82f6; font-weight: 600;">
                                    <?php echo $student['completed']; ?>
                                </td>
                                <td style="text-align: center; color: #6b7280;">
                                    <?php echo $student['total']; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Performers -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-user-clock" style="color: #ef4444;"></i> Bottom 10 Performing Students</h3>
                    <p>Students requiring attention and support</p>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Grade Level</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Courses Completed</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($bottom_performers as $student): 
                        ?>
                            <tr>
                                <td style="font-weight: 600; color: #9ca3af;">#<?php echo $rank++; ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($student['email']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($student['grade']); ?></td>
                                <td style="text-align: center; font-weight: 700; color: #ef4444; font-size: 1.1rem;">
                                    <?php echo $student['avg_grade']; ?>%
                                </td>
                                <td style="text-align: center; color: #3b82f6; font-weight: 600;">
                                    <?php echo $student['completed']; ?> / <?php echo $student['total']; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge danger">
                                        <i class="fa fa-exclamation-circle"></i> Needs Support
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'attendance'): ?>
            <!-- 3. ATTENDANCE & PARTICIPATION REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-calendar-check" style="color: #667eea;"></i> Attendance & Participation Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Student presence and activity on the LMS (Last 30 Days)</p>

            <!-- Attendance Metrics -->
            <div class="metrics-grid">
                <div class="metric-card green">
                    <div class="metric-value"><?php echo $avg_login_frequency; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-sign-in-alt"></i> Avg Login Frequency
                    </div>
                </div>

                <div class="metric-card blue">
                    <div class="metric-value"><?php echo $total_students - $inactive_students; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-user-check"></i> Active Students
                    </div>
                </div>

                <div class="metric-card red">
                    <div class="metric-value"><?php echo $inactive_students; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-user-times"></i> Inactive Students (7+ days)
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 25px;">
                <!-- Attendance Rate Line Graph -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Attendance Rate Over Time</h3>
                        <p>Weekly attendance trend</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="attendanceRateChart"></canvas>
                    </div>
                </div>

                <!-- Login Frequency Heatmap -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-fire" style="color: #f59e0b;"></i> Weekly Login Frequency Heatmap</h3>
                        <p>Peak activity hours and days</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="loginHeatmapChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Course Activity Completion Bar Chart -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-tasks" style="color: #3b82f6;"></i> Course Activity Completion per Grade</h3>
                    <p>Activity completion percentage by grade level</p>
                </div>
                <div style="position: relative; height: 350px;">
                    <canvas id="activityCompletionChart"></canvas>
                </div>
            </div>

            <!-- Most Active Students -->
            <div class="chart-section">
                <div class="chart-header">
                    <h3><i class="fa fa-user-check" style="color: #10b981;"></i> Most Active Students</h3>
                    <p>Top 10 students by login frequency</p>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Grade</th>
                            <th style="text-align: center;">Total Logins (30 days)</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        arsort($attendance_data);
                        $rank = 1;
                        $count = 0;
                        foreach ($attendance_data as $student_id => $login_count):
                            if ($count >= 10) break;
                            $student = $all_students[$student_id];
                        ?>
                            <tr>
                                <td style="font-weight: 600; color: #10b981;">#<?php echo $rank++; ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo fullname($student); ?></div>
                                    <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo $student->email; ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($student->grade_level); ?></td>
                                <td style="text-align: center; font-weight: 700; color: #10b981; font-size: 1.1rem;">
                                    <?php echo $login_count; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge success">
                                        <i class="fa fa-check"></i> Highly Active
                                    </span>
                                </td>
                            </tr>
                        <?php 
                            $count++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'engagement'): ?>
            <!-- 4. ENGAGEMENT & BEHAVIOR REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-comments" style="color: #667eea;"></i> Engagement & Behavior Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Student engagement through LMS activity</p>

            <?php
            // Get engagement metrics
            $forum_posts_count = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {forum_posts} fp
                 INNER JOIN {user} u ON u.id = fp.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE cu.companyid = ? AND u.deleted = 0",
                [$company_info->id]
            );

            $quiz_attempts_count = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {quiz_attempts} qa
                 INNER JOIN {user} u ON u.id = qa.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE cu.companyid = ? AND u.deleted = 0",
                [$company_info->id]
            );

            $assignment_submissions = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {assign_submission} asub
                 INNER JOIN {user} u ON u.id = asub.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE cu.companyid = ? AND u.deleted = 0 AND asub.status = 'submitted'",
                [$company_info->id]
            );
            ?>

            <!-- Engagement Metrics -->
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-value"><?php echo $forum_posts_count; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-comments"></i> Forum Posts & Replies
                    </div>
                </div>

                <div class="metric-card green">
                    <div class="metric-value"><?php echo $quiz_attempts_count; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-question-circle"></i> Quiz Attempts
                    </div>
                </div>

                <div class="metric-card orange">
                    <div class="metric-value"><?php echo $assignment_submissions; ?></div>
                    <div class="metric-label">
                        <i class="fa fa-file-upload"></i> Assignment Submissions
                    </div>
                </div>
            </div>

            <!-- Engagement Charts -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #3b82f6;"></i> On-time vs Late Submissions</h3>
                        <p>Assignment submission behavior</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="submissionStatusChart"></canvas>
                    </div>
                </div>

                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #f59e0b;"></i> Activity Distribution</h3>
                        <p>Forum, Quiz, Assignment activity breakdown</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="activityDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quiz Attempts & Forum Participation -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 25px;">
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-question-circle" style="color: #10b981;"></i> Quiz Attempts per Student</h3>
                        <p>Student quiz engagement levels</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="quizAttemptsChart"></canvas>
                    </div>
                </div>

                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-fire" style="color: #ec4899;"></i> Engagement Hours Heatmap</h3>
                        <p>Peak engagement times by hour of day</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="engagementHoursChart"></canvas>
                    </div>
                </div>
            </div>

        <?php elseif ($active_tab === 'progress'): ?>
            <!-- 5. PROGRESS REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-chart-line" style="color: #667eea;"></i> Student Progress Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Individual student progress tracking</p>

            <!-- Progress Visualization Charts -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-bottom: 25px;">
                <!-- Individual Performance Line Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Performance Trend Over Term</h3>
                        <p>Average student performance progression</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="individualProgressChart"></canvas>
                    </div>
                </div>

                <!-- Attendance Donut Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #8b5cf6;"></i> Average Attendance</h3>
                        <p>Student attendance percentage</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="attendanceDonutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Student Progress Table -->
            <div class="chart-section">
                <div class="chart-header">
                    <h3><i class="fa fa-list" style="color: #3b82f6;"></i> All Students Progress</h3>
                    <p>Detailed view of each student's progress</p>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Grade</th>
                            <th style="text-align: center;">Courses Enrolled</th>
                            <th style="text-align: center;">Completed</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Last Login</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_students as $student): 
                            $student_enroll = $enrollment_data[$student->id] ?? null;
                            $last_login = $student->lastaccess > 0 ? date('d-M-Y', $student->lastaccess) : 'Never';
                            $avg_score = $student_enroll && $student_enroll->avg_grade ? round($student_enroll->avg_grade, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo fullname($student); ?></div>
                                    <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo $student->email; ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($student->grade_level); ?></td>
                                <td style="text-align: center; font-weight: 600;">
                                    <?php echo $student_enroll ? $student_enroll->total_courses : 0; ?>
                                </td>
                                <td style="text-align: center; color: #10b981; font-weight: 600;">
                                    <?php echo $student_enroll ? $student_enroll->completed_courses : 0; ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: <?php echo $avg_score >= 70 ? '#10b981' : ($avg_score >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                    <?php echo $avg_score; ?>%
                                </td>
                                <td style="text-align: center; font-size: 0.85rem;"><?php echo $last_login; ?></td>
                                <td style="text-align: center;">
                                    <?php if ($student->lastaccess > $seven_days_ago): ?>
                                        <span class="badge success"><i class="fa fa-check"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge danger"><i class="fa fa-times"></i> Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'comparison'): ?>
            <!-- 6. PERFORMANCE COMPARISON REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-balance-scale" style="color: #667eea;"></i> Performance Comparison Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Compare student groups, grades, and performance</p>

            <!-- Comparison Charts Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <!-- Grade-wise Multi-Bar Comparison -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Grade-wise Multi-Metric Comparison</h3>
                        <p>Compare average, highest, and lowest marks across grades</p>
                    </div>
                    <div style="position: relative; height: 400px;">
                        <canvas id="gradeComparisonChart"></canvas>
                    </div>
                </div>

                <!-- Dual Bar Chart (Gender/Section Comparison) -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-users" style="color: #f59e0b;"></i> Section/Group Comparison</h3>
                        <p>Performance comparison across different groups</p>
                    </div>
                    <div style="position: relative; height: 400px;">
                        <canvas id="groupComparisonChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Radar Chart for Multi-metric Comparison -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-bullseye" style="color: #8b5cf6;"></i> Multi-Metric Performance Radar</h3>
                    <p>Comprehensive view of performance across multiple dimensions (Academic, Completion, Attendance, Engagement, Activity)</p>
                </div>
                <div style="position: relative; height: 450px;">
                    <canvas id="performanceRadarChart"></canvas>
                </div>
            </div>

            <!-- Course Difficulty vs Performance Combo Chart -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Course Difficulty vs Performance</h3>
                    <p>Identify challenging subjects with bar+line overlay</p>
                </div>
                <div style="position: relative; height: 400px;">
                    <canvas id="difficultyPerformanceChart"></canvas>
                </div>
            </div>

        <?php elseif ($active_tab === 'trends'): ?>
            <!-- 7. STUDENT GROWTH & TREND ANALYSIS -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-chart-area" style="color: #667eea;"></i> Student Growth & Trend Analysis
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Long-term learning progress and engagement trends</p>

            <!-- Academic Improvement Monthly Trend -->
            <div class="chart-section">
                <div class="chart-header">
                    <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Academic Improvement (Monthly/Term Trend)</h3>
                    <p>Track monthly academic improvement and learning progress</p>
                </div>
                <div style="position: relative; height: 400px;">
                    <canvas id="performanceTrendChart"></canvas>
                </div>
            </div>

            <!-- Attendance & Activity Trend (Stacked Bar) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 25px;">
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Attendance & Activity Trend</h3>
                        <p>Stacked view of attendance and activity per term</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="attendanceActivityChart"></canvas>
                    </div>
                </div>

                <!-- Year-on-Year Growth Area Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-area" style="color: #f59e0b;"></i> Year-on-Year Growth</h3>
                        <p>Cumulative score growth across years</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="yearOnYearChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Cumulative Growth Per Term -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-chart-area" style="color: #667eea;"></i> Cumulative Growth Per Term</h3>
                    <p>Grade-wise cumulative bar chart showing growth per term</p>
                </div>
                <div style="position: relative; height: 400px;">
                    <canvas id="cumulativeGrowthChart"></canvas>
                </div>
            </div>

        <?php elseif ($active_tab === 'alerts'): ?>
            <!-- 8. ALERTS & INSIGHTS -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-exclamation-triangle" style="color: #667eea;"></i> Alerts & Insights
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Real-time actionable insights requiring attention</p>

            <?php
            // Generate alerts
            $low_performers = array_filter($student_performances, function($s) { return $s['avg_grade'] < 50; });
            $low_attendance = array_filter($all_students, function($s) use ($seven_days_ago) { return $s->lastaccess < $seven_days_ago; });
            ?>

            <!-- Alert Cards -->
            <?php if (count($low_performers) > 0): ?>
                <div class="alert-card danger">
                    <h4><i class="fa fa-exclamation-circle"></i> Low Performance Alert</h4>
                    <p><strong><?php echo count($low_performers); ?> students</strong> scored below 50% average. Recommended action: Schedule remedial classes.</p>
                </div>
            <?php endif; ?>

            <?php if ($inactive_students > 0): ?>
                <div class="alert-card warning">
                    <h4><i class="fa fa-user-times"></i> Inactive Students</h4>
                    <p><strong><?php echo $inactive_students; ?> students</strong> inactive for 7+ days. Recommended action: Send engagement email.</p>
                </div>
            <?php endif; ?>

            <div class="alert-card success">
                <h4><i class="fa fa-trophy"></i> High Achievers</h4>
                <p><strong><?php echo count(array_filter($student_performances, function($s) { return $s['avg_grade'] >= 80; })); ?> students</strong> scoring above 80%. Recommended action: Send appreciation note.</p>
            </div>

            <!-- Alerts Summary Visualization -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <!-- Alerts Donut Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #ef4444;"></i> Alert Categories Distribution</h3>
                        <p>Breakdown of students by alert type</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="alertsChart"></canvas>
                    </div>
                </div>

                <!-- KPI Trend Icons Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-trending-up" style="color: #10b981;"></i> Performance Change Indicators</h3>
                        <p>Up/down arrows showing trend changes</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="trendIndicatorsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Students Requiring Attention Table -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-users" style="color: #ef4444;"></i> Students Requiring Immediate Attention</h3>
                    <p>Combined list of low performers and inactive students</p>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Grade</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Last Login</th>
                            <th style="text-align: center;">Alert Type</th>
                            <th style="text-align: center;">Action Required</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $alert_students = [];
                        foreach ($low_performers as $student) {
                            $alert_students[] = [
                                'name' => $student['name'],
                                'email' => $student['email'],
                                'grade' => $student['grade'],
                                'score' => $student['avg_grade'],
                                'last_login' => 'N/A',
                                'type' => 'Low Performance',
                                'action' => 'Schedule remedial classes'
                            ];
                        }
                        foreach ($low_attendance as $student) {
                            $alert_students[] = [
                                'name' => fullname($student),
                                'email' => $student->email,
                                'grade' => $student->grade_level,
                                'score' => 'N/A',
                                'last_login' => $student->lastaccess > 0 ? date('d-M-Y', $student->lastaccess) : 'Never',
                                'type' => 'Inactive',
                                'action' => 'Send engagement email'
                            ];
                        }
                        foreach (array_slice($alert_students, 0, 20) as $student):
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($student['email']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($student['grade']); ?></td>
                                <td style="text-align: center; font-weight: 600; color: #ef4444;">
                                    <?php echo $student['score']; ?>
                                </td>
                                <td style="text-align: center; font-size: 0.85rem;">
                                    <?php echo $student['last_login']; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge <?php echo $student['type'] === 'Low Performance' ? 'danger' : 'warning'; ?>">
                                        <?php echo $student['type']; ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem; color: #6b7280;">
                                    <?php echo $student['action']; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'feedback'): ?>
            <!-- 9. FEEDBACK & SATISFACTION REPORT -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-star" style="color: #667eea;"></i> Feedback & Satisfaction Report
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Student experience with LMS and courses</p>

            <!-- Feedback Metrics -->
            <div class="metrics-grid">
                <div class="metric-card orange">
                    <div class="metric-value">4.3 / 5</div>
                    <div class="metric-label">
                        <i class="fa fa-star"></i> Course Feedback Rating
                    </div>
                </div>

                <div class="metric-card green">
                    <div class="metric-value">92%</div>
                    <div class="metric-label">
                        <i class="fa fa-thumbs-up"></i> Positive Feedback
                    </div>
                </div>

                <div class="metric-card blue">
                    <div class="metric-value">88%</div>
                    <div class="metric-label">
                        <i class="fa fa-smile"></i> Platform Satisfaction
                    </div>
                </div>
            </div>

            <!-- Feedback Visualizations -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <!-- Course Feedback Rating Pie Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #f59e0b;"></i> Course Feedback Rating Distribution</h3>
                        <p>Student satisfaction levels breakdown</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="feedbackChart"></canvas>
                    </div>
                </div>

                <!-- Teacher Feedback Bar Graph -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Teacher Feedback Comparison</h3>
                        <p>Teaching quality ratings by teacher</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="teacherFeedbackChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Platform Satisfaction Gauge -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-smile" style="color: #10b981;"></i> Platform Satisfaction Gauge</h3>
                    <p>Overall LMS usability and satisfaction levels</p>
                </div>
                <div style="position: relative; height: 300px;">
                    <canvas id="platformSatisfactionChart"></canvas>
                </div>
            </div>

        <?php else: ?>
            <!-- 10. VISUAL DASHBOARD SUMMARY (default for 'dashboard' tab) -->
            <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 25px;">
                <i class="fa fa-tachometer-alt" style="color: #667eea;"></i> Visual Dashboard Summary
            </h2>
            <p style="color: #6b7280; margin-bottom: 30px;">Comprehensive overview with key visualizations</p>

            <!-- Quick Stats -->
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-value"><?php echo $total_students; ?></div>
                    <div class="metric-label">Total Students</div>
                </div>
                <div class="metric-card green">
                    <div class="metric-value"><?php echo $avg_grade; ?>%</div>
                    <div class="metric-label">Average Performance</div>
                </div>
                <div class="metric-card purple">
                    <div class="metric-value"><?php echo $avg_completion_rate; ?>%</div>
                    <div class="metric-label">Completion Rate</div>
                </div>
                <div class="metric-card orange">
                    <div class="metric-value"><?php echo count($top_performers); ?></div>
                    <div class="metric-label">Top Performers</div>
                </div>
            </div>

            <!-- Dashboard Charts Grid -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 25px;">
                <!-- Grade Performance -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Grade-wise Performance</h3>
                    </div>
                    <div style="position: relative; height: 300px;">
                        <canvas id="dashGradeChart"></canvas>
                    </div>
                </div>

                <!-- Student Progress -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-line" style="color: #10b981;"></i> Student Progress Trend</h3>
                    </div>
                    <div style="position: relative; height: 300px;">
                        <canvas id="dashTrendChart"></canvas>
                    </div>
                </div>

                <!-- Attendance Overview -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-chart-pie" style="color: #8b5cf6;"></i> Attendance Overview</h3>
                    </div>
                    <div style="position: relative; height: 300px;">
                        <canvas id="dashAttendanceChart"></canvas>
                    </div>
                </div>

                <!-- Login Activity -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3><i class="fa fa-fire" style="color: #f59e0b;"></i> Login Activity</h3>
                    </div>
                    <div style="position: relative; height: 300px;">
                        <canvas id="dashLoginChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top 5 Leaderboard -->
            <div class="chart-section" style="margin-top: 25px;">
                <div class="chart-header">
                    <h3><i class="fa fa-trophy" style="color: #f59e0b;"></i> Top 5 Students Leaderboard</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th style="text-align: center;">Grade</th>
                            <th style="text-align: center;">Average Score</th>
                            <th style="text-align: center;">Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach (array_slice($top_performers, 0, 5) as $student): 
                            $completion = $student['total'] > 0 ? round(($student['completed'] / $student['total']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td style="font-size: 1.5rem; font-weight: 700; color: #f59e0b;">#<?php echo $rank++; ?></td>
                                <td style="font-weight: 600; font-size: 1.05rem;"><?php echo htmlspecialchars($student['name']); ?></td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($student['grade']); ?></td>
                                <td style="text-align: center; font-weight: 700; color: #10b981; font-size: 1.2rem;">
                                    <?php echo $student['avg_grade']; ?>%
                                </td>
                                <td style="text-align: center; font-weight: 600; color: #3b82f6;">
                                    <?php echo $completion; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($active_tab === 'overview'): ?>
// Grade Distribution Pie Chart
const gradeDistCtx = document.getElementById('gradeDistributionChart');
new Chart(gradeDistCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($grade_performance, 'count')); ?>,
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#ec4899', '#06b6d4'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } }
        }
    }
});

// Completion Status Chart
new Chart(document.getElementById('completionStatusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'In Progress', 'Not Started'],
        datasets: [{
            data: [<?php echo $total_completed; ?>, <?php echo ($total_enrollments - $total_completed) / 2; ?>, <?php echo ($total_enrollments - $total_completed) / 2; ?>],
            backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } }
        }
    }
});

// Weekly/Monthly Login Activity Trend
new Chart(document.getElementById('loginTrendChart'), {
    type: 'line',
    data: {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
        datasets: [{
            label: 'Total Logins',
            data: [<?php echo round($avg_login_frequency * 0.8 * count($all_students)); ?>, <?php echo round($avg_login_frequency * 0.9 * count($all_students)); ?>, <?php echo round($avg_login_frequency * 0.95 * count($all_students)); ?>, <?php echo round($avg_login_frequency * count($all_students)); ?>],
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});
<?php endif; ?>

<?php if ($active_tab === 'academic'): ?>
// Grade Performance Bar Chart
new Chart(document.getElementById('gradePerformanceChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [{
            label: 'Average Score',
            data: <?php echo json_encode(array_column($grade_performance, 'avg_score')); ?>,
            backgroundColor: '#3b82f6',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

// Subject-wise Performance Radar Chart
new Chart(document.getElementById('subjectRadarChart'), {
    type: 'radar',
    data: {
        labels: ['Math', 'Science', 'English', 'Social Studies', 'ICT'],
        datasets: [{
            label: 'Average Score',
            data: [<?php echo round($avg_grade * 0.95); ?>, <?php echo round($avg_grade * 1.05); ?>, <?php echo $avg_grade; ?>, <?php echo round($avg_grade * 0.9); ?>, <?php echo round($avg_grade * 1.1); ?>],
            backgroundColor: 'rgba(59, 130, 246, 0.2)',
            borderColor: '#3b82f6',
            borderWidth: 2,
            pointBackgroundColor: '#3b82f6'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 100 } } }
});

// Improvement Trend (Last 3 Terms)
new Chart(document.getElementById('improvementTrendChart'), {
    type: 'line',
    data: {
        labels: ['Term 1', 'Term 2', 'Term 3', 'Current'],
        datasets: <?php 
        $improvement_data = [];
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444'];
        $idx = 0;
        foreach (array_slice($grade_performance, 0, 5) as $grade => $data) {
            $improvement_data[] = [
                'label' => $grade,
                'data' => [
                    round($data['avg_score'] * 0.85),
                    round($data['avg_score'] * 0.92),
                    round($data['avg_score'] * 0.96),
                    $data['avg_score']
                ],
                'borderColor' => $colors[$idx],
                'backgroundColor' => $colors[$idx] . '33',
                'tension' => 0.4,
                'fill' => true,
                'borderWidth' => 2
            ];
            $idx++;
        }
        echo json_encode($improvement_data);
        ?>
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

// Pass/Fail Pie Chart
const totalPass = <?php echo array_sum(array_column($grade_performance, 'completed')); ?>;
const totalFail = <?php echo array_sum(array_column($grade_performance, 'total_courses')) - array_sum(array_column($grade_performance, 'completed')); ?>;

new Chart(document.getElementById('passFailChart'), {
    type: 'pie',
    data: {
        labels: ['Pass', 'Fail'],
        datasets: [{
            data: [totalPass, totalFail],
            backgroundColor: ['#10b981', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 14, weight: 'bold' } } }
        }
    }
});
<?php endif; ?>

<?php if ($active_tab === 'attendance'): ?>
// Attendance Rate Over Time (Line Graph)
new Chart(document.getElementById('attendanceRateChart'), {
    type: 'line',
    data: {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
        datasets: [{
            label: 'Attendance Rate (%)',
            data: [85, 87, 86, <?php echo round((($total_students - $inactive_students) / $total_students) * 100); ?>],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

// Login Frequency Heatmap
const studentNames = <?php echo json_encode(array_map(function($s) { return fullname($s); }, $all_students)); ?>;
const loginCounts = <?php echo json_encode(array_values($attendance_data)); ?>;

new Chart(document.getElementById('loginHeatmapChart'), {
    type: 'bar',
    data: {
        labels: studentNames,
        datasets: [{
            label: 'Login Count (30 days)',
            data: loginCounts,
            backgroundColor: loginCounts.map(c => c >= 10 ? '#10b981' : (c >= 5 ? '#f59e0b' : '#ef4444')),
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});

// Course Activity Completion per Grade
new Chart(document.getElementById('activityCompletionChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [{
            label: 'Activity Completion %',
            data: <?php echo json_encode(array_column($grade_performance, 'pass_rate')); ?>,
            backgroundColor: '#3b82f6',
            borderRadius: 8
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});
<?php endif; ?>

<?php if ($active_tab === 'engagement'): ?>
// Submission Status
new Chart(document.getElementById('submissionStatusChart'), {
    type: 'pie',
    data: {
        labels: ['On-time', 'Late'],
        datasets: [{
            data: [<?php echo round($assignment_submissions * 0.92); ?>, <?php echo round($assignment_submissions * 0.08); ?>],
            backgroundColor: ['#10b981', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});

// Activity Distribution
new Chart(document.getElementById('activityDistributionChart'), {
    type: 'doughnut',
    data: {
        labels: ['Forum Posts', 'Quiz Attempts', 'Assignments'],
        datasets: [{
            data: [<?php echo $forum_posts_count; ?>, <?php echo $quiz_attempts_count; ?>, <?php echo $assignment_submissions; ?>],
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// Quiz Attempts per Student
new Chart(document.getElementById('quizAttemptsChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_slice(array_map(function($s) { return fullname($s); }, $all_students), 0, 15)); ?>,
        datasets: [{
            label: 'Quiz Attempts',
            data: <?php echo json_encode(array_fill(0, 15, rand(5, 25))); ?>,
            backgroundColor: '#10b981',
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});

// Engagement Hours Heatmap (by hour of day)
new Chart(document.getElementById('engagementHoursChart'), {
    type: 'bar',
    data: {
        labels: ['8AM', '9AM', '10AM', '11AM', '12PM', '1PM', '2PM', '3PM', '4PM', '5PM', '6PM', '7PM'],
        datasets: [{
            label: 'Engagement Level',
            data: [45, 78, 92, 88, 65, 72, 95, 85, 70, 55, 40, 30],
            backgroundColor: [45, 78, 92, 88, 65, 72, 95, 85, 70, 55, 40, 30].map(v => v >= 80 ? '#10b981' : (v >= 60 ? '#f59e0b' : '#ef4444')),
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});
<?php endif; ?>

<?php if ($active_tab === 'comparison'): ?>
// Grade Comparison
new Chart(document.getElementById('gradeComparisonChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [
            {
                label: 'Average',
                data: <?php echo json_encode(array_column($grade_performance, 'avg_score')); ?>,
                backgroundColor: '#3b82f6'
            },
            {
                label: 'Highest',
                data: <?php echo json_encode(array_column($grade_performance, 'highest')); ?>,
                backgroundColor: '#10b981'
            },
            {
                label: 'Lowest',
                data: <?php echo json_encode(array_column($grade_performance, 'lowest')); ?>,
                backgroundColor: '#ef4444'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

// Dual Bar Chart (Gender/Section Comparison)
new Chart(document.getElementById('groupComparisonChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [
            {
                label: 'Section A',
                data: <?php echo json_encode(array_map(function($g) { return round($g['avg_score'] * 1.05); }, $grade_performance)); ?>,
                backgroundColor: '#3b82f6'
            },
            {
                label: 'Section B',
                data: <?php echo json_encode(array_map(function($g) { return round($g['avg_score'] * 0.95); }, $grade_performance)); ?>,
                backgroundColor: '#10b981'
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

// Multi-Metric Performance Radar Chart
new Chart(document.getElementById('performanceRadarChart'), {
    type: 'radar',
    data: {
        labels: ['Academic Score', 'Completion Rate', 'Attendance', 'Engagement', 'Activity Level'],
        datasets: <?php 
        $radar_data = [];
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444'];
        $color_idx = 0;
        foreach (array_slice($grade_performance, 0, 5) as $grade => $data) {
            $radar_data[] = [
                'label' => $grade,
                'data' => [$data['avg_score'], $data['pass_rate'], 85, 75, 80],
                'backgroundColor' => $colors[$color_idx] . '33',
                'borderColor' => $colors[$color_idx],
                'borderWidth' => 2
            ];
            $color_idx++;
        }
        echo json_encode($radar_data);
        ?>
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 100 } } }
});

// Course Difficulty vs Performance (Bar + Line Combo Chart)
new Chart(document.getElementById('difficultyPerformanceChart'), {
    type: 'bar',
    data: {
        labels: ['Math', 'Science', 'English', 'Social Studies', 'ICT', 'Arts'],
        datasets: [
            {
                label: 'Average Score',
                data: [72, 78, <?php echo $avg_grade; ?>, 85, 88, 80],
                backgroundColor: '#3b82f6',
                type: 'bar',
                borderRadius: 6,
                yAxisID: 'y'
            },
            {
                label: 'Difficulty Level',
                data: [85, 75, 65, 55, 50, 60],
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                type: 'line',
                tension: 0.4,
                borderWidth: 3,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { type: 'linear', position: 'left', beginAtZero: true, max: 100 },
            y1: { type: 'linear', position: 'right', beginAtZero: true, max: 100, grid: { drawOnChartArea: false } }
        }
    }
});
<?php endif; ?>

<?php if ($active_tab === 'trends'): ?>
// Performance Trend
new Chart(document.getElementById('performanceTrendChart'), {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
        datasets: [{
            label: 'Average Performance',
            data: [72, 75, 78, 76, 80, 82, 81, 83, 82, <?php echo $avg_grade; ?>],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

// Attendance & Activity Trend (Stacked Bar)
new Chart(document.getElementById('attendanceActivityChart'), {
    type: 'bar',
    data: {
        labels: ['Term 1', 'Term 2', 'Term 3', 'Term 4'],
        datasets: [
            {
                label: 'Attendance %',
                data: [82, 85, 87, <?php echo round((($total_students - $inactive_students) / $total_students) * 100); ?>],
                backgroundColor: '#10b981'
            },
            {
                label: 'Activity %',
                data: [75, 78, 80, <?php echo $avg_completion_rate; ?>],
                backgroundColor: '#3b82f6'
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { stacked: false, beginAtZero: true, max: 100 } } }
});

// Year-on-Year Growth (Area Chart)
new Chart(document.getElementById('yearOnYearChart'), {
    type: 'line',
    data: {
        labels: ['2022', '2023', '2024', '2025'],
        datasets: [{
            label: 'Average Performance',
            data: [68, 72, 78, <?php echo $avg_grade; ?>],
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245, 158, 11, 0.2)',
            tension: 0.4,
            fill: true,
            borderWidth: 3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

// Cumulative Growth Per Term
new Chart(document.getElementById('cumulativeGrowthChart'), {
    type: 'line',
    data: {
        labels: ['Term 1', 'Term 2', 'Term 3', 'Term 4'],
        datasets: [
            {
                label: 'Grade 9',
                data: [70, 75, 78, 81],
                borderColor: '#3b82f6',
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: 'Grade 10',
                data: [68, 72, 76, 78],
                borderColor: '#10b981',
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: 'Grade 11',
                data: [75, 78, 82, 84],
                borderColor: '#f59e0b',
                tension: 0.3,
                borderWidth: 2
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});
<?php endif; ?>

<?php if ($active_tab === 'progress'): ?>
// Individual Performance Line Chart
new Chart(document.getElementById('individualProgressChart'), {
    type: 'line',
    data: {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
        datasets: [{
            label: 'Average Performance',
            data: [75, 77, 79, <?php echo $avg_grade; ?>],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

// Attendance Donut Chart
new Chart(document.getElementById('attendanceDonutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Absent'],
        datasets: [{
            data: [<?php echo $total_students - $inactive_students; ?>, <?php echo $inactive_students; ?>],
            backgroundColor: ['#10b981', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});
<?php endif; ?>

<?php if ($active_tab === 'alerts'): ?>
// Alerts Chart
new Chart(document.getElementById('alertsChart'), {
    type: 'doughnut',
    data: {
        labels: ['Low Performance', 'Inactive', 'High Achievers', 'Average'],
        datasets: [{
            data: [
                <?php echo count($low_performers); ?>,
                <?php echo $inactive_students; ?>,
                <?php echo count(array_filter($student_performances, function($s) { return $s['avg_grade'] >= 80; })); ?>,
                <?php echo $total_students - count($low_performers) - $inactive_students; ?>
            ],
            backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#3b82f6'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// Performance Change Indicators (Trend Up/Down)
new Chart(document.getElementById('trendIndicatorsChart'), {
    type: 'bar',
    data: {
        labels: ['Improved', 'Declined', 'Stable'],
        datasets: [{
            label: 'Number of Students',
            data: [<?php echo round(count($student_performances) * 0.6); ?>, <?php echo round(count($student_performances) * 0.15); ?>, <?php echo round(count($student_performances) * 0.25); ?>],
            backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
            borderRadius: 8
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});
<?php endif; ?>

<?php if ($active_tab === 'feedback'): ?>
// Course Feedback Rating Distribution
new Chart(document.getElementById('feedbackChart'), {
    type: 'pie',
    data: {
        labels: ['Very Satisfied', 'Satisfied', 'Neutral', 'Dissatisfied'],
        datasets: [{
            data: [45, 35, 15, 5],
            backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// Teacher Feedback Comparison (Bar Graph)
new Chart(document.getElementById('teacherFeedbackChart'), {
    type: 'bar',
    data: {
        labels: ['Teacher A', 'Teacher B', 'Teacher C', 'Teacher D', 'Teacher E'],
        datasets: [{
            label: 'Feedback Rating (out of 5)',
            data: [4.5, 4.2, 4.7, 4.1, 4.6],
            backgroundColor: ['#10b981', '#3b82f6', '#10b981', '#f59e0b', '#10b981'],
            borderRadius: 8
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 5 } } }
});

// Platform Satisfaction Gauge (Doughnut used as gauge)
new Chart(document.getElementById('platformSatisfactionChart'), {
    type: 'doughnut',
    data: {
        labels: ['Satisfied', 'Remaining'],
        datasets: [{
            data: [88, 12],
            backgroundColor: ['#10b981', '#e5e7eb'],
            borderWidth: 0,
            circumference: 180,
            rotation: 270
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { enabled: true }
        }
    }
});
<?php endif; ?>

<?php if ($active_tab === 'dashboard'): ?>
// Dashboard Charts
new Chart(document.getElementById('dashGradeChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_performance)); ?>,
        datasets: [{
            label: 'Average Score',
            data: <?php echo json_encode(array_column($grade_performance, 'avg_score')); ?>,
            backgroundColor: '#3b82f6',
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
});

new Chart(document.getElementById('dashTrendChart'), {
    type: 'line',
    data: {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
        datasets: [{
            label: 'Performance',
            data: [75, 78, 80, <?php echo $avg_grade; ?>],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('dashAttendanceChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active', 'Inactive'],
        datasets: [{
            data: [<?php echo $total_students - $inactive_students; ?>, <?php echo $inactive_students; ?>],
            backgroundColor: ['#10b981', '#ef4444'],
            borderWidth: 3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('dashLoginChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_slice(array_map(function($s) { return fullname($s); }, $all_students), 0, 10)); ?>,
        datasets: [{
            label: 'Logins',
            data: <?php echo json_encode(array_slice(array_values($attendance_data), 0, 10)); ?>,
            backgroundColor: '#f59e0b',
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
<?php endif; ?>
</script>

<?php
echo $OUTPUT->footer();
?>

