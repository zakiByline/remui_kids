<?php
/**
 * Student Report - Engagements Tab (AJAX fragment)
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

    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.*
             FROM {company} c
             JOIN {company_users} cu ON c.id = cu.companyid
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'engagements']);
    redirect($target);
}

$thirty_days_ago = strtotime('-30 days');
$two_weeks_ago = strtotime('-14 days');
$now = time();

$engagement_details = [];
$inactive_students = [];
$engagement_distribution = [
    'high' => 0,
    'moderate' => 0,
    'low' => 0
];

$summary = [
    'total_students' => 0,
    'active_students' => 0,
    'at_risk_students' => 0,
    'total_time_spent' => 0,
    'total_course_visits' => 0
];

$daily_engagement = [];
$days_labels = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily_engagement[$day] = 0;
    $days_labels[] = $day;
}

if ($company_info) {
    // Log stats (interactions + course visits)
    $log_stats_records = $DB->get_records_sql(
        "SELECT l.userid,
                COUNT(*) AS total_interactions,
                COUNT(DISTINCT CASE WHEN l.courseid IS NOT NULL AND l.courseid > 1 THEN l.courseid END) AS course_visits
         FROM {logstore_standard_log} l
         JOIN {company_users} cu ON cu.userid = l.userid
         WHERE cu.companyid = ?
           AND l.userid > 0
           AND l.timecreated >= ?
         GROUP BY l.userid",
        [$company_info->id, $thirty_days_ago]
    );

    $log_stats = [];
    foreach ($log_stats_records as $record) {
        $log_stats[$record->userid] = $record;
    }

    // Activities completed
    $completion_records = $DB->get_records_sql(
        "SELECT cmc.userid, COUNT(*) AS activities_completed
         FROM {course_modules_completion} cmc
         JOIN {company_users} cu ON cu.userid = cmc.userid
         WHERE cmc.completionstate = 1
           AND cu.companyid = ?
         GROUP BY cmc.userid",
        [$company_info->id]
    );

    $completion_stats = [];
    foreach ($completion_records as $record) {
        $completion_stats[$record->userid] = (int)$record->activities_completed;
    }

    // Daily engagement (unique students per day)
    $daily_records = $DB->get_records_sql(
        "SELECT FLOOR(l.timecreated / 86400) AS daybucket,
                COUNT(DISTINCT l.userid) AS unique_students
         FROM {logstore_standard_log} l
         JOIN {company_users} cu ON cu.userid = l.userid
         WHERE cu.companyid = ?
           AND l.userid > 0
           AND l.timecreated >= ?
         GROUP BY FLOOR(l.timecreated / 86400)",
        [$company_info->id, $two_weeks_ago]
    );

    foreach ($daily_records as $record) {
        $day_ts = ((int)$record->daybucket) * 86400;
        $day_key = date('Y-m-d', $day_ts);
        if (array_key_exists($day_key, $daily_engagement)) {
            $daily_engagement[$day_key] = (int)$record->unique_students;
        }
    }

    // Student list with base info
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
           AND r.shortname = 'student'
           AND u.deleted = 0
           AND u.suspended = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id]
    );

    foreach ($students as $student) {
        $summary['total_students']++;
        $log_stat = $log_stats[$student->id] ?? null;
        $total_interactions = $log_stat ? (int)$log_stat->total_interactions : 0;
        $course_visits = $log_stat ? (int)$log_stat->course_visits : 0;
        $activities_completed = $completion_stats[$student->id] ?? 0;
        $estimated_time_spent = (int)round($total_interactions * 2.5); // minutes

        $summary['total_time_spent'] += $estimated_time_spent;
        $summary['total_course_visits'] += $course_visits;

        $last_login = $student->lastaccess ? userdate($student->lastaccess, get_string('strftimedatetime')) : 'Never';
        $days_since_login = $student->lastaccess ? floor(($now - $student->lastaccess) / DAYSECS) : 999;

        if ($days_since_login <= 7 || $total_interactions >= 40) {
            $summary['active_students']++;
        }

        $engagement_level = 'low';
        if ($estimated_time_spent >= 180 || $total_interactions >= 80) {
            $engagement_level = 'high';
        } elseif ($estimated_time_spent >= 90 || $total_interactions >= 40) {
            $engagement_level = 'moderate';
        }

        $engagement_distribution[$engagement_level]++;

        $at_risk = ($days_since_login >= 14 || $total_interactions <= 5);
        if ($at_risk) {
            $summary['at_risk_students']++;
        }

        if ($days_since_login >= 14) {
            $inactive_students[] = [
                'name' => fullname($student),
                'email' => $student->email,
                'last_login' => $last_login,
                'days_since_login' => $days_since_login,
                'course_visits' => $course_visits,
                'activities_completed' => $activities_completed
            ];
        }

        $engagement_details[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'last_login' => $last_login,
            'time_spent' => $estimated_time_spent,
            'course_visits' => $course_visits,
            'activities_completed' => $activities_completed,
            'total_interactions' => $total_interactions,
            'engagement_level' => $engagement_level,
            'days_since_login' => $days_since_login
        ];
    }
}

$average_time_spent = $summary['total_students'] > 0 ? round($summary['total_time_spent'] / $summary['total_students']) : 0;
$average_course_visits = $summary['total_students'] > 0 ? round($summary['total_course_visits'] / $summary['total_students'], 1) : 0;

// Limit inactive students table
$inactive_students = array_slice($inactive_students, 0, 10);

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.engagement-container {
    padding: 0;
}

.engagement-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}

.engagement-summary-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    position: relative;
}

.engagement-summary-card h5 {
    margin: 0 0 8px 0;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
}

.engagement-summary-card .value {
    font-size: 2.2rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.engagement-summary-card .subtext {
    margin-top: 6px;
    font-size: 0.9rem;
    color: #64748b;
}

.engagement-chart-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 28px;
}

.engagement-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}

.engagement-card h4 {
    margin: 0 0 16px 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.engagement-card h4 i {
    color: #3b82f6;
}

.engagement-table-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    margin-bottom: 28px;
}

.engagement-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}

.engagement-search-container {
    position: relative;
    flex: 1;
    min-width: 260px;
    max-width: 380px;
}

.engagement-search-input {
    width: 100%;
    padding: 10px 44px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #1f2937;
    background: #ffffff;
}

.engagement-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.engagement-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.engagement-table-wrapper {
    overflow-x: auto;
}

.engagement-table {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
    font-size: 0.92rem;
}

.engagement-table thead {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
}

.engagement-table th {
    padding: 12px;
    text-align: left;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
}

.engagement-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #e5e7eb;
    color: #0f172a;
}

.engagement-level {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.engagement-level.high {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}

.engagement-level.moderate {
    background: rgba(249, 115, 22, 0.15);
    color: #c2410c;
}

.engagement-level.low {
    background: rgba(248, 113, 113, 0.2);
    color: #b91c1c;
}

.engagement-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}

.engagement-pagination-info {
    font-size: 0.9rem;
    color: #64748b;
}

.engagement-pagination-controls {
    display: flex;
    gap: 10px;
    align-items: center;
}

.engagement-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #cbd5f5;
    background: #ffffff;
    color: #1f2937;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.engagement-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.engagement-page-numbers {
    display: flex;
    gap: 6px;
}

.engagement-page-number {
    padding: 8px 12px;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    min-width: 36px;
    text-align: center;
    transition: all 0.2s;
}

.engagement-page-number.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
}

.engagement-inactive-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}

.engagement-table-note {
    margin-top: 16px;
    padding: 12px 16px;
    background: #f8fafc;
    border-left: 4px solid #3b82f6;
    color: #475569;
    font-size: 0.9rem;
}

@media (max-width: 1024px) {
    .engagement-chart-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="engagement-container">
    <div class="engagement-summary-grid">
        <div class="engagement-summary-card">
            <h5>Active Students</h5>
            <p class="value"><?php echo number_format($summary['active_students']); ?></p>
            <p class="subtext">Logged in or interacted in the last 7 days</p>
        </div>
        <div class="engagement-summary-card">
            <h5>At-Risk Students</h5>
            <p class="value" style="color: #ef4444;"><?php echo number_format($summary['at_risk_students']); ?></p>
            <p class="subtext">No recent logins or very low activity</p>
        </div>
        <div class="engagement-summary-card">
            <h5>Avg. Time Spent</h5>
            <p class="value"><?php echo $average_time_spent; ?> <span style="font-size: 1rem; font-weight: 500;">mins</span></p>
            <p class="subtext">Per student over the last 30 days</p>
        </div>
        <div class="engagement-summary-card">
            <h5>Avg. Course Visits</h5>
            <p class="value"><?php echo $average_course_visits; ?></p>
            <p class="subtext">Unique courses accessed per student</p>
        </div>
    </div>

    <div class="engagement-chart-grid">
        <div class="engagement-card">
            <h4><i class="fa fa-chart-line"></i> Daily Platform Engagement</h4>
            <div style="position: relative; height: 320px;">
                <canvas id="dailyEngagementChart"></canvas>
            </div>
        </div>
        <div class="engagement-card">
            <h4><i class="fa fa-chart-pie"></i> Engagement Distribution</h4>
            <div style="position: relative; height: 320px;">
                <canvas id="engagementPieChart"></canvas>
            </div>
        </div>
    </div>

    <?php if (!empty($engagement_details)): ?>
    <div class="engagement-table-card">
        <div class="engagement-table-header">
            <div>
                <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #1f2937;">
                    <i class="fa fa-bolt" style="color: #f59e0b;"></i> Student Engagement Details
                </h4>
                <p style="color: #94a3b8; margin: 4px 0 0 0; font-size: 0.9rem;">Engagement signals across the past 30 days</p>
            </div>
            <div class="engagement-search-container">
                <i class="fa fa-search engagement-search-icon"></i>
                <input type="search" id="engagementSearchInput" class="engagement-search-input" placeholder="Search students by name, email, or grade..." autocomplete="off">
            </div>
        </div>

        <div class="engagement-table-wrapper">
            <table class="engagement-table" id="engagementTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Last Login</th>
                        <th>Time Spent (mins)</th>
                        <th>Course Visits</th>
                        <th>Activities Completed</th>
                        <th>Interactions</th>
                        <th>Engagement Level</th>
                    </tr>
                </thead>
                <tbody id="engagementTableBody">
                    <?php foreach ($engagement_details as $detail): ?>
                    <tr class="engagement-row"
                        data-name="<?php echo strtolower(htmlspecialchars($detail['name'])); ?>"
                        data-email="<?php echo strtolower(htmlspecialchars($detail['email'])); ?>"
                        data-level="<?php echo $detail['engagement_level']; ?>">
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($detail['name']); ?></div>
                            <div style="color: #94a3b8; font-size: 0.8rem;"><?php echo htmlspecialchars($detail['email']); ?></div>
                        </td>
                        <td style="color: #64748b;"><?php echo $detail['last_login']; ?></td>
                        <td><?php echo number_format($detail['time_spent']); ?></td>
                        <td><?php echo number_format($detail['course_visits']); ?></td>
                        <td><?php echo number_format($detail['activities_completed']); ?></td>
                        <td><?php echo number_format($detail['total_interactions']); ?></td>
                        <td>
                            <span class="engagement-level <?php echo $detail['engagement_level']; ?>">
                                <?php echo ucfirst($detail['engagement_level']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="engagement-pagination">
            <div class="engagement-pagination-info" id="engagementPaginationInfo">
                Showing 0 to 0 of <?php echo count($engagement_details); ?> entries
            </div>
            <div class="engagement-pagination-controls">
                <button type="button" class="engagement-pagination-btn" id="engagementPrevBtn">&lt; Prev</button>
                <div class="engagement-page-numbers" id="engagementPageNumbers"></div>
                <button type="button" class="engagement-pagination-btn" id="engagementNextBtn">Next &gt;</button>
            </div>
        </div>

        <div class="engagement-table-note">
            Engagement levels are based on total interactions, time spent, and unique course visits over the past 30 days.
        </div>
    </div>
    <?php else: ?>
    <div class="engagement-card">
        <div style="text-align: center; padding: 40px;">
            <i class="fa fa-info-circle" style="font-size: 2rem; color: #cbd5f5;"></i>
            <p style="margin-top: 12px; color: #94a3b8;">No engagement data available for the selected period.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="engagement-inactive-card">
        <h4 style="margin: 0 0 16px 0; font-size: 1.1rem; font-weight: 700; color: #1f2937;">
            <i class="fa fa-user-clock" style="color: #ef4444;"></i> Inactive Students (14+ days)
        </h4>
        <?php if (!empty($inactive_students)): ?>
        <div class="engagement-table-wrapper">
            <table class="engagement-table" style="min-width: 600px;">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Last Login</th>
                        <th>Days Inactive</th>
                        <th>Course Visits</th>
                        <th>Activities Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inactive_students as $inactive): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($inactive['name']); ?></div>
                            <div style="color: #94a3b8; font-size: 0.8rem;"><?php echo htmlspecialchars($inactive['email']); ?></div>
                        </td>
                        <td style="color: #64748b;"><?php echo $inactive['last_login']; ?></td>
                        <td><span class="engagement-level low"><?php echo $inactive['days_since_login']; ?> days</span></td>
                        <td><?php echo number_format($inactive['course_visits']); ?></td>
                        <td><?php echo number_format($inactive['activities_completed']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align: center; color: #94a3b8;">Great news! No inactive students found.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($engagement_details)): ?>
<script>
(function() {
    const dailyLabels = <?php echo json_encode($days_labels); ?>;
    const dailyValues = <?php echo json_encode(array_values($daily_engagement)); ?>;
    const pieValues = <?php echo json_encode(array_values($engagement_distribution)); ?>;

    function initDailyChart() {
        const ctx = document.getElementById('dailyEngagementChart');
        if (!ctx || typeof Chart === 'undefined') {
            return;
        }

        if (window.dailyEngagementChartInstance) {
            window.dailyEngagementChartInstance.destroy();
        }

        window.dailyEngagementChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [{
                    label: 'Unique Students',
                    data: dailyValues,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' students engaged';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#64748b',
                            callback: function(value, index) {
                                const date = new Date(dailyLabels[index] + 'T00:00:00');
                                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            }
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#64748b', stepSize: 1 },
                        grid: { color: '#e2e8f0' }
                    }
                }
            }
        });
    }

    function initPieChart() {
        const ctx = document.getElementById('engagementPieChart');
        if (!ctx || typeof Chart === 'undefined') {
            return;
        }

        if (window.engagementPieChartInstance) {
            window.engagementPieChartInstance.destroy();
        }

        window.engagementPieChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Highly Engaged', 'Moderately Engaged', 'Low Engagement'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#22c55e', '#f97316', '#ef4444'],
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            color: '#475569'
                        }
                    }
                }
            }
        });
    }

    function initEngagementTable() {
        const rows = document.querySelectorAll('.engagement-row');
        if (!rows.length) {
            return;
        }

        const searchInput = document.getElementById('engagementSearchInput');
        const paginationInfo = document.getElementById('engagementPaginationInfo');
        const prevBtn = document.getElementById('engagementPrevBtn');
        const nextBtn = document.getElementById('engagementNextBtn');
        const pageNumbers = document.getElementById('engagementPageNumbers');

        let filteredRows = Array.from(rows);
        let currentPage = 1;
        const perPage = 10;

        function renderTable() {
            const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
            filteredRows = Array.from(rows).filter(row => {
                if (!searchTerm) return true;
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                return name.includes(searchTerm) || email.includes(searchTerm);
            });

            rows.forEach(row => row.style.display = 'none');

            const totalPages = Math.ceil(filteredRows.length / perPage);
            currentPage = Math.min(currentPage, Math.max(totalPages, 1));

            const startIndex = (currentPage - 1) * perPage;
            const endIndex = startIndex + perPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);

            pageRows.forEach(row => row.style.display = '');

            if (paginationInfo) {
                const start = filteredRows.length === 0 ? 0 : startIndex + 1;
                const end = Math.min(endIndex, filteredRows.length);
                paginationInfo.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
            }

            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages === 0;

            if (pageNumbers) {
                pageNumbers.innerHTML = '';
                const maxButtons = 5;
                let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
                let endPage = Math.min(startPage + maxButtons - 1, totalPages);

                if (endPage - startPage < maxButtons - 1) {
                    startPage = Math.max(1, endPage - maxButtons + 1);
                }

                for (let i = startPage; i <= endPage; i++) {
                    const btn = document.createElement('button');
                    btn.className = 'engagement-page-number' + (i === currentPage ? ' active' : '');
                    btn.textContent = i;
                    btn.addEventListener('click', () => {
                        currentPage = i;
                        renderTable();
                    });
                    pageNumbers.appendChild(btn);
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
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
                const totalPages = Math.ceil(filteredRows.length / perPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        }

        renderTable();
    }

    function initAll() {
        initDailyChart();
        initPieChart();
        initEngagementTable();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
</script>
<?php endif; ?>

<?php
echo ob_get_clean();
exit;
?>