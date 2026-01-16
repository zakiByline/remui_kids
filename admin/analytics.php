<?php
/**
 * Analytics Dashboard Page
 * Provides high-level insights for admins across courses, users, and enrollments.
 */

require_once('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $CFG, $OUTPUT;

// Aggregate counters.
$totalcourses = $DB->count_records_select('course', 'visible = 1 AND id > 1');
$totalcompanies = $DB->count_records('company');

$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
$totalstudents = 0;
if ($studentroleid) {
    $totalstudents = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid)
           FROM {role_assignments} ra
           JOIN {context} ctx ON ra.contextid = ctx.id
          WHERE ra.roleid = :roleid AND ctx.contextlevel = :ctxcourse",
        ['roleid' => $studentroleid, 'ctxcourse' => CONTEXT_COURSE]
    );
}

$teacherroles = $DB->get_records_select('role', "shortname IN ('teacher', 'editingteacher', 'manager')");
$teacherroleids = array_keys($teacherroles);
$totalteachers = 0;
if (!empty($teacherroleids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($teacherroleids, SQL_PARAMS_NAMED, 'tr');
    $teacherparams = $inparams + ['ctxcourse' => CONTEXT_COURSE];
    $totalteachers = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid)
           FROM {role_assignments} ra
           JOIN {context} ctx ON ra.contextid = ctx.id
          WHERE ra.roleid {$insql} AND ctx.contextlevel = :ctxcourse",
        $teacherparams
    );
}

$totalenrollments = $DB->count_records('user_enrolments');

// Completion rate (global).
$completed = $DB->count_records_select('course_completions', 'timecompleted IS NOT NULL');
$completionrate = $totalstudents > 0 ? round(($completed / max($totalstudents, 1)) * 100) : 0;

// Monthly enrollment counts for last 6 months.
$months = [];
$startmonth = strtotime('first day of -5 month');
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -{$i} month"));
    $months[$key] = [
        'label' => date('M Y', strtotime($key . '-01')),
        'count' => 0
    ];
}

$monthlyrecords = $DB->get_records_sql(
    "SELECT FROM_UNIXTIME(ue.timecreated, '%Y-%m') AS ym, COUNT(ue.id) AS total
       FROM {user_enrolments} ue
      WHERE ue.timecreated >= :starttime
   GROUP BY ym",
    ['starttime' => $startmonth]
);

foreach ($monthlyrecords as $record) {
    if (isset($months[$record->ym])) {
        $months[$record->ym]['count'] = (int)$record->total;
    }
}

$monthlylabels = array_column($months, 'label');
$monthlyvalues = array_column($months, 'count');

// Top courses by enrollment.
$topcourses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, COUNT(ue.id) AS enrollments
       FROM {course} c
       LEFT JOIN {enrol} e ON e.courseid = c.id
       LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
      WHERE c.visible = 1 AND c.id > 1
   GROUP BY c.id, c.fullname
   ORDER BY enrollments DESC
      LIMIT 5"
);

// Category distribution.
$categorydistribution = $DB->get_records_sql(
    "SELECT cat.name, COUNT(c.id) AS total
       FROM {course_categories} cat
  LEFT JOIN {course} c ON c.category = cat.id AND c.visible = 1 AND c.id > 1
   GROUP BY cat.id, cat.name
   ORDER BY total DESC
      LIMIT 6"
);

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/analytics.php');
$PAGE->set_title('Analytics');
$PAGE->set_heading('Analytics Dashboard');

echo $OUTPUT->header();

require_once(__DIR__ . '/includes/admin_sidebar.php');

echo "<div class='admin-main-content'>";
?>
<style>
.analytics-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 30px;
    background: #f8fafc;
    min-height: 100vh;
}

.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.analytics-header h1 {
    font-size: 2rem;
    color: #0f172a;
    margin: 0;
}

.timeframe-badge {
    background: #e0f2fe;
    color: #0369a1;
    padding: 10px 16px;
    border-radius: 30px;
    font-weight: 600;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    position: relative;
    overflow: hidden;
}

.metric-card h3 {
    margin: 0;
    font-size: 0.9rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.metric-value {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 10px 0;
}

.metric-trend {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    font-weight: 600;
}

.trend-up {
    color: #15803d;
}

.trend-down {
    color: #b91c1c;
}

.analytics-panels {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.panel {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
}

.panel h2 {
    margin: 0 0 10px 0;
    font-size: 1.2rem;
    color: #0f172a;
}

.panel p.description {
    margin: 0 0 20px 0;
    color: #64748b;
    font-size: 0.9rem;
}

.top-courses-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.top-course-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border-radius: 12px;
    background: #f8fafc;
}

.course-name {
    font-weight: 600;
    color: #0f172a;
}

.course-enrollments {
    font-weight: 700;
    color: #2563eb;
}

.category-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.category-pill {
    padding: 10px 14px;
    border-radius: 12px;
    background: #f1f5f9;
    display: inline-flex;
    flex-direction: column;
}

.category-pill span.label {
    font-size: 0.85rem;
    color: #0f172a;
    font-weight: 600;
}

.category-pill span.value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2563eb;
}

.secondary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
}

.stat-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.stat-list li {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
}

.stat-list li:last-child {
    border-bottom: none;
}

.stat-label {
    color: #64748b;
}

.stat-value {
    font-weight: 700;
    color: #0f172a;
}

@media (max-width: 1200px) {
    .analytics-panels {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="analytics-container">
    <div class="analytics-header">
        <h1>Analytics Overview</h1>
        <span class="timeframe-badge">Last 6 Months</span>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <h3>Total Students</h3>
            <div class="metric-value"><?php echo number_format($totalstudents); ?></div>
            <span class="metric-trend trend-up">
                <i class="fa fa-arrow-up"></i>
                <?php echo $completionrate; ?>% completion rate
            </span>
        </div>
        <div class="metric-card">
            <h3>Total Teachers</h3>
            <div class="metric-value"><?php echo number_format($totalteachers); ?></div>
            <span class="metric-trend trend-up">
                <i class="fa fa-arrow-up"></i>
                <?php echo $totalcourses > 0 ? round($totalteachers / $totalcourses, 1) : 0; ?> / course
            </span>
        </div>
        <div class="metric-card">
            <h3>Courses</h3>
            <div class="metric-value"><?php echo number_format($totalcourses); ?></div>
            <span class="metric-trend trend-up">
                <i class="fa fa-arrow-up"></i>
                <?php echo number_format($totalcompanies); ?> companies
            </span>
        </div>
        <div class="metric-card">
            <h3>Total Enrollments</h3>
            <div class="metric-value"><?php echo number_format($totalenrollments); ?></div>
            <span class="metric-trend trend-up">
                <i class="fa fa-arrow-up"></i>
                <?php echo array_sum($monthlyvalues); ?> in last 6 months
            </span>
        </div>
    </div>

    <div class="analytics-panels">
        <div class="panel">
            <h2>Enrollment Trend</h2>
            <p class="description">Monthly enrollments across all courses.</p>
            <canvas id="enrollmentChart" height="220"></canvas>
        </div>
        <div class="panel">
            <h2>Top Categories</h2>
            <p class="description">Where most of your courses live.</p>
            <div class="category-pills">
                <?php if (!empty($categorydistribution)): ?>
                    <?php foreach ($categorydistribution as $category): ?>
                        <div class="category-pill">
                            <span class="label"><?php echo htmlspecialchars($category->name ?: 'Uncategorized'); ?></span>
                            <span class="value"><?php echo (int)$category->total; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No category data available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="secondary-grid">
        <div class="panel">
            <h2>Top Courses by Enrollment</h2>
            <p class="description">Most popular courses in your catalog.</p>
            <ul class="top-courses-list">
                <?php if (!empty($topcourses)): ?>
                    <?php foreach ($topcourses as $course): ?>
                        <li class="top-course-item">
                            <span class="course-name"><?php echo htmlspecialchars($course->fullname); ?></span>
                            <span class="course-enrollments"><?php echo number_format((int)$course->enrollments); ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>No enrollment data available.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="panel">
            <h2>Key Ratios</h2>
            <p class="description">Operational metrics at a glance.</p>
            <ul class="stat-list">
                <li>
                    <span class="stat-label">Students per Teacher</span>
                    <span class="stat-value">
                        <?php echo $totalteachers > 0 ? round($totalstudents / max($totalteachers, 1), 1) : '0'; ?>
                    </span>
                </li>
                <li>
                    <span class="stat-label">Students per Course</span>
                    <span class="stat-value">
                        <?php echo $totalcourses > 0 ? round($totalstudents / max($totalcourses, 1), 1) : '0'; ?>
                    </span>
                </li>
                <li>
                    <span class="stat-label">Enrollments per Course</span>
                    <span class="stat-value">
                        <?php echo $totalcourses > 0 ? round($totalenrollments / max($totalcourses, 1), 1) : '0'; ?>
                    </span>
                </li>
                <li>
                    <span class="stat-label">Completion Rate</span>
                    <span class="stat-value"><?php echo $completionrate; ?>%</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('enrollmentChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthlylabels); ?>,
                datasets: [{
                    label: 'Enrollments',
                    data: <?php echo json_encode($monthlyvalues); ?>,
                    fill: true,
                    tension: 0.4,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.15)',
                    borderWidth: 3,
                    pointBackgroundColor: '#1d4ed8',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }
});
</script>

<?php
echo "</div>"; // admin-main-content
echo $OUTPUT->footer();







