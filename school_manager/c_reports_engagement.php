<?php
/**
 * C Reports - Course Engagement Tab (AJAX fragment)
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
$inline_request = !empty($c_reports_engagement_inline);

if (!$ajax && !$inline_request) {
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'engagement']);
    redirect($target);
}

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

$engagement_data = [];
$engagement_summary = [
    'total_logins' => 0,
    'total_hours' => 0,
    'total_forum_posts' => 0,
    'average_score' => 0
];

if ($company_info) {
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
           AND r.shortname = 'student'
           AND u.deleted = 0
           AND u.suspended = 0
         ORDER BY u.lastname, u.firstname",
        [$company_info->id]
    );

    $thirty_days_ago = strtotime('-30 days');
    foreach ($students as $student) {
        // Distinct login days (better signal than just count of entries)
        $login_days = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
             FROM {logstore_standard_log}
             WHERE userid = ?
               AND action = 'loggedin'
               AND timecreated > ?",
            [$student->id, $thirty_days_ago]
        );

        // Estimate active time based on session gaps < 30 mins
        $logs = $DB->get_records_sql(
            "SELECT timecreated
             FROM {logstore_standard_log}
             WHERE userid = ?
               AND timecreated > ?
             ORDER BY timecreated ASC",
            [$student->id, $thirty_days_ago]
        );

        $total_minutes = 0;
        $prev = null;
        foreach ($logs as $log) {
            if ($prev !== null) {
                $diff = $log->timecreated - $prev;
                if ($diff > 0 && $diff < 1800) {
                    $total_minutes += ($diff / 60);
                }
            }
            $prev = $log->timecreated;
        }
        $time_hours = round($total_minutes / 60, 1);

        // Forum activity constrained to company courses
        $forum_posts = $DB->count_records_sql(
            "SELECT COUNT(*)
             FROM {forum_posts} fp
             INNER JOIN {forum_discussions} fd ON fd.id = fp.discussion
             INNER JOIN {forum} f ON f.id = fd.forum
             INNER JOIN {course} c ON c.id = f.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE fp.userid = ?
               AND cc.companyid = ?
               AND fp.created > ?",
            [$student->id, $company_info->id, $thirty_days_ago]
        );

        // Engagement score weighting logins, time, posts
        $engagement_score = min(
            100,
            ($login_days * 3) + (min(12, $time_hours) * 4) + ($forum_posts * 5)
        );

        $engagement_data[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'login_days' => $login_days,
            'time_hours' => $time_hours,
            'forum_posts' => $forum_posts,
            'engagement_score' => round($engagement_score, 1)
        ];

        $engagement_summary['total_logins'] += $login_days;
        $engagement_summary['total_hours'] += $time_hours;
        $engagement_summary['total_forum_posts'] += $forum_posts;
    }

    if (!empty($engagement_data)) {
        $engagement_summary['average_score'] = round(
            array_sum(array_column($engagement_data, 'engagement_score')) / count($engagement_data),
            1
        );
    }
}

if (empty($engagement_data)) {
    $engagement_distribution = ['high' => 0, 'medium' => 0, 'low' => 0];
    $top_students = [];
    $at_risk_students = [];
    $chart_dataset = [];
} else {
    usort($engagement_data, function($a, $b) {
        return $b['engagement_score'] <=> $a['engagement_score'];
    });

    $top_students = array_slice($engagement_data, 0, 8);
    $at_risk_students = array_slice(array_reverse($engagement_data), 0, 8);

    $engagement_distribution = [
        'high' => count(array_filter($engagement_data, fn($s) => $s['engagement_score'] >= 70)),
        'medium' => count(array_filter($engagement_data, fn($s) => $s['engagement_score'] >= 40 && $s['engagement_score'] < 70)),
        'low' => count(array_filter($engagement_data, fn($s) => $s['engagement_score'] < 40))
    ];

    $chart_dataset = array_slice($engagement_data, 0, 15);
}

$engagement_chart_payload = [
    'labels' => array_column($chart_dataset, 'name'),
    'scores' => array_column($chart_dataset, 'engagement_score'),
    'logins' => array_column($chart_dataset, 'login_days'),
    'hours' => array_column($chart_dataset, 'time_hours'),
    'posts' => array_column($chart_dataset, 'forum_posts')
];

// Course-level enrollment + time datasets.
$course_enrollment_dataset = [
    'courses' => [],
    'total_courses' => 0,
    'total_students_sum' => 0,
    'active_students_sum' => 0,
    'total_teachers' => 0
];
$course_top_courses = [];
$course_distribution_payload = ['labels' => [], 'values' => []];
$course_time_data = ['course_names' => [], 'average_times' => []];

if ($company_info) {
    $course_records = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname
         FROM {course} c
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ?
           AND c.visible = 1
           AND c.id > 1
         ORDER BY c.fullname ASC",
        [$company_info->id]
    );

    $teacher_id_pool = [];

    foreach ($course_records as $course_record) {
        $course_name = format_string($course_record->fullname);

        $total_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid = ?
               AND ue.status = 0
               AND cu.companyid = ?
               AND r.shortname = 'student'
               AND u.deleted = 0
               AND u.suspended = 0",
            [CONTEXT_COURSE, $course_record->id, $company_info->id]
        );

        $active_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
             WHERE e.courseid = ?
               AND ue.status = 0
               AND cu.companyid = ?
               AND r.shortname = 'student'
               AND u.deleted = 0
               AND u.suspended = 0
               AND ula.timeaccess > ?",
            [CONTEXT_COURSE, $course_record->id, $company_info->id, strtotime('-30 days')]
        );

        $course_teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE ctx.contextlevel = ?
               AND ctx.instanceid = ?
               AND r.shortname IN ('teacher', 'editingteacher')
               AND cu.companyid = ?
               AND u.deleted = 0
               AND u.suspended = 0",
            [CONTEXT_COURSE, $course_record->id, $company_info->id]
        );

        foreach ($course_teachers as $teacher) {
            $teacher_id_pool[$teacher->id] = true;
        }

        $course_enrollment_dataset['courses'][] = [
            'name' => $course_name,
            'total_students' => $total_students,
            'active_students' => $active_students
        ];

        $course_enrollment_dataset['total_students_sum'] += $total_students;
        $course_enrollment_dataset['active_students_sum'] += $active_students;

        // Build time spent dataset per course (same logic as Course Reports)
        $time_records = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.timecreated
             FROM {logstore_standard_log} l
             INNER JOIN {company_users} cu ON cu.userid = l.userid
             INNER JOIN {role_assignments} ra ON ra.userid = l.userid
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = l.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE l.courseid = ?
               AND cu.companyid = ?
               AND r.shortname = 'student'
               AND l.timecreated >= (UNIX_TIMESTAMP() - (90 * 24 * 3600))
             ORDER BY l.userid, l.timecreated",
            [$course_record->id, $company_info->id]
        );

        // Calculate average time spent
        $total_time = 0;
        $session_count = 0;
        $user_sessions = [];
        
        foreach ($time_records as $record) {
            // Initialize user session tracking if not exists
            if (!isset($user_sessions[$record->userid])) {
                $user_sessions[$record->userid] = [
                    'last_time' => 0,
                    'session_time' => 0
                ];
            }
            
            // If there's a previous log entry within 30 minutes, add the time difference
            if ($user_sessions[$record->userid]['last_time'] > 0) {
                $time_diff = $record->timecreated - $user_sessions[$record->userid]['last_time'];
                
                // Only count if time difference is reasonable (less than 30 minutes = 1800 seconds)
                if ($time_diff > 0 && $time_diff < 1800) {
                    $user_sessions[$record->userid]['session_time'] += $time_diff;
                }
            }
            
            $user_sessions[$record->userid]['last_time'] = $record->timecreated;
        }

        // Sum up all session times
        foreach ($user_sessions as $session) {
            if ($session['session_time'] > 0) {
                $total_time += $session['session_time'];
                $session_count++;
            }
        }
        
        // Calculate average time in hours (same as course_reports.php)
        $avg_time_hours = 0;
        if ($session_count > 0) {
            $avg_time_hours = round($total_time / $session_count / 3600, 1); // Convert seconds to hours
        }

        $course_time_data['course_names'][] = $course_name;
        $course_time_data['average_times'][] = $avg_time_hours;
    }

    $course_enrollment_dataset['total_courses'] = count($course_enrollment_dataset['courses']);
    $course_enrollment_dataset['total_teachers'] = count($teacher_id_pool);

    $course_distribution_payload['labels'] = array_column($course_enrollment_dataset['courses'], 'name');
    $course_distribution_payload['values'] = array_column($course_enrollment_dataset['courses'], 'total_students');

    $course_top_courses = $course_enrollment_dataset['courses'];
    usort($course_top_courses, function($a, $b) {
        return $b['total_students'] <=> $a['total_students'];
    });
    $course_top_courses = array_slice($course_top_courses, 0, 5);
}

$enrollment_chart_payload = [
    'labels' => array_column($course_enrollment_dataset['courses'], 'name'),
    'total' => array_column($course_enrollment_dataset['courses'], 'total_students'),
    'active' => array_column($course_enrollment_dataset['courses'], 'active_students')
];

?>

<style>
.c-reports-engagement {
    font-family: 'Inter', sans-serif;
    color: #1f2937;
}

.engagement-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.engagement-header h2 {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 700;
}

.engagement-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.engagement-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.08);
    border-left: 4px solid #3b82f6;
}

.engagement-card:nth-child(2) { border-left-color: #10b981; }
.engagement-card:nth-child(3) { border-left-color: #f59e0b; }
.engagement-card:nth-child(4) { border-left-color: #8b5cf6; }

.engagement-card .value {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
}

.engagement-card .label {
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #6b7280;
    font-weight: 600;
}

.no-engagement-data {
    text-align: center;
    padding: 60px 20px;
    border-radius: 18px;
    background: #ffffff;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.08);
    color: #6b7280;
}

.engagement-analytics-block {
    background: #ffffff;
    border-radius: 20px;
    padding: 24px 26px;
    box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
    margin-top: 35px;
}

.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
}

.analytics-header h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
}

.analytics-header p {
    margin: 6px 0 0;
    color: #6b7280;
    font-size: 0.9rem;
}

.analytics-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin: 25px 0;
}

.summary-pill {
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: #f8fafc;
}

.summary-pill span {
    font-size: 0.78rem;
    text-transform: uppercase;
    color: #6b7280;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.summary-pill strong {
    font-size: 1.4rem;
    color: #111827;
}

.chart-search {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.chart-search input {
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    min-width: 240px;
    font-size: 0.95rem;
}

.chart-search button {
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    background: #ef4444;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}

.chart-wrapper {
    position: relative;
    height: 360px;
}

.top-course-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.top-course-card {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 16px;
    background: #fafbff;
}

.top-course-card h4 {
    margin: 0;
    font-size: 0.95rem;
    color: #111827;
}

.top-course-card span {
    font-weight: 700;
    font-size: 1.5rem;
    color: #3b82f6;
}

.time-series-wrapper {
    position: relative;
    height: 320px;
}

@media (max-width: 768px) {
    .engagement-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="c-reports-engagement">
    <div class="engagement-header">
        <div>
            <h2>Course Engagement</h2>
            <p style="margin: 4px 0 0; color: #6b7280; font-size: 0.95rem;">
                Engagement metrics for students across all active courses (last 30 days)
            </p>
        </div>
    </div>

    <?php if (empty($engagement_data)): ?>
        <div class="no-engagement-data">
            <i class="fa fa-users" style="font-size: 3rem; color: #d1d5db; margin-bottom: 15px;"></i>
            <p>No engagement activity recorded for this school in the last 30 days.</p>
            <p style="font-size: 0.9rem;">Once students start logging in and participating in courses, their engagement insights will appear here.</p>
        </div>
    <?php else: ?>
        <div class="engagement-card-grid">
            <div class="engagement-card">
                <div class="value"><?php echo number_format($engagement_summary['total_logins']); ?></div>
                <div class="label">Login Days (30d)</div>
            </div>
            <div class="engagement-card">
                <div class="value"><?php echo round($engagement_summary['total_hours'], 1); ?>h</div>
                <div class="label">Active Learning Hours</div>
            </div>
            <div class="engagement-card">
                <div class="value"><?php echo number_format($engagement_summary['total_forum_posts']); ?></div>
                <div class="label">Forum Contributions</div>
            </div>
            <div class="engagement-card">
                <div class="value"><?php echo $engagement_summary['average_score']; ?>%</div>
                <div class="label">Average Engagement Score</div>
            </div>
        </div>

        <?php if (!empty($course_enrollment_dataset['courses'])): ?>
        <div class="engagement-analytics-block">
            <div class="analytics-header">
                <div>
                    <h3><i class="fa fa-chart-column" style="color:#2563eb;"></i> Student Enrollment by Course</h3>
                    <p>Compare total vs active students for each course and filter quickly.</p>
                </div>
                <div class="chart-search">
                    <input type="text" id="enrollmentChartSearch" placeholder="Search courses in graph..." />
                    <button type="button" id="enrollmentChartClear"><i class="fa fa-times"></i> Clear</button>
                </div>
            </div>

            <div class="analytics-summary-grid">
                <div class="summary-pill">
                    <span>Total Courses</span>
                    <strong><?php echo $course_enrollment_dataset['total_courses']; ?></strong>
                </div>
                <div class="summary-pill">
                    <span>Total Students</span>
                    <strong><?php echo $course_enrollment_dataset['total_students_sum']; ?></strong>
                </div>
                <div class="summary-pill">
                    <span>Active Students (30d)</span>
                    <strong><?php echo $course_enrollment_dataset['active_students_sum']; ?></strong>
                </div>
                <div class="summary-pill">
                    <span>Total Teachers</span>
                    <strong><?php echo $course_enrollment_dataset['total_teachers']; ?></strong>
                </div>
            </div>

            <div class="chart-wrapper">
                <canvas id="engagementEnrollmentChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($course_distribution_payload['values'])): ?>
        <div class="engagement-analytics-block">
            <div class="analytics-header">
                <div>
                    <h3><i class="fa fa-chart-pie" style="color:#10b981;"></i> Course Statistics</h3>
                    <p>Top enrolled courses and enrollment share across all courses.</p>
                </div>
            </div>

            <div class="top-course-grid">
                <?php foreach ($course_top_courses as $index => $top_course): ?>
                    <div class="top-course-card">
                        <h4>#<?php echo $index + 1; ?> <?php echo htmlspecialchars($top_course['name']); ?></h4>
                        <span><?php echo $top_course['total_students']; ?></span>
                        <div style="font-size:0.85rem; color:#6b7280;">Students</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chart-wrapper" style="height:320px;">
                <canvas id="courseDistributionChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($course_time_data['course_names'])): ?>
        <div class="engagement-analytics-block">
            <div class="analytics-header">
                <div>
                    <h3><i class="fa fa-clock" style="color:#f59e0b;"></i> Average Time Spent per Course</h3>
                    <p>Track student engagement by analysing average learning hours per course.</p>
                </div>
            </div>

            <div class="analytics-summary-grid">
                <div class="summary-pill" style="border-left: 4px solid #3b82f6;">
                    <span>TOTAL COURSES</span>
                    <strong style="color: #3b82f6;"><?php echo count($course_time_data['course_names']); ?></strong>
                </div>
                <div class="summary-pill" style="border-left: 4px solid #10b981;">
                    <span>AVG TIME (ALL COURSES)</span>
                    <strong style="color: #10b981;">
                        <?php
                        $overall_time = count($course_time_data['average_times']) > 0 ? round(array_sum($course_time_data['average_times']) / count($course_time_data['average_times']), 1) : 0;
                        echo $overall_time;
                        ?> hrs
                    </strong>
                </div>
                <div class="summary-pill" style="border-left: 4px solid #f59e0b;">
                    <span>HIGHEST ENGAGEMENT</span>
                    <strong style="color: #f59e0b;"><?php echo !empty($course_time_data['average_times']) ? round(max($course_time_data['average_times']), 1) : 0; ?> hrs</strong>
                </div>
                <div class="summary-pill" style="border-left: 4px solid #ec4899;">
                    <span>LOWEST ENGAGEMENT</span>
                    <strong style="color: #ec4899;"><?php echo !empty($course_time_data['average_times']) ? round(min($course_time_data['average_times']), 1) : 0; ?> hrs</strong>
                </div>
            </div>

            <div class="time-series-wrapper">
                <canvas id="courseTimeSpentChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!empty($engagement_data)): ?>
<script>
(function() {
    const enrollmentDataset = <?php echo json_encode($enrollment_chart_payload, JSON_UNESCAPED_UNICODE); ?>;
    const courseDistributionPayload = <?php echo json_encode($course_distribution_payload, JSON_UNESCAPED_UNICODE); ?>;
    const courseTimePayload = <?php echo json_encode($course_time_data, JSON_UNESCAPED_UNICODE); ?>;

    let enrollmentChartInstance = null;
    function initEnrollmentChart() {
        const ctx = document.getElementById('engagementEnrollmentChart');
        if (!ctx || !window.Chart || !enrollmentDataset.labels.length) {
            return;
        }
        enrollmentChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: enrollmentDataset.labels,
                datasets: [
                    {
                        label: 'Total Students',
                        data: enrollmentDataset.total,
                        backgroundColor: '#3b82f6',
                        borderRadius: 6
                    },
                    {
                        label: 'Active Students',
                        data: enrollmentDataset.active,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0 }
                    },
                    x: {
                        ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 }
                    }
                }
            }
        });

        const searchInput = document.getElementById('enrollmentChartSearch');
        const clearBtn = document.getElementById('enrollmentChartClear');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                applyEnrollmentFilter(this.value || '');
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                if (searchInput) {
                    searchInput.value = '';
                }
                applyEnrollmentFilter('');
            });
        }
    }

    function applyEnrollmentFilter(term) {
        if (!enrollmentChartInstance) {
            return;
        }
        const lowered = term.trim().toLowerCase();
        const filteredLabels = [];
        const filteredTotal = [];
        const filteredActive = [];

        enrollmentDataset.labels.forEach((label, index) => {
            if (!lowered || label.toLowerCase().includes(lowered)) {
                filteredLabels.push(label);
                filteredTotal.push(enrollmentDataset.total[index]);
                filteredActive.push(enrollmentDataset.active[index]);
            }
        });

        enrollmentChartInstance.data.labels = filteredLabels;
        enrollmentChartInstance.data.datasets[0].data = filteredTotal;
        enrollmentChartInstance.data.datasets[1].data = filteredActive;
        enrollmentChartInstance.update();
    }

    function renderCourseDistribution() {
        const ctx = document.getElementById('courseDistributionChart');
        if (!ctx || !window.Chart || !courseDistributionPayload.values.length) {
            return;
        }
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: courseDistributionPayload.labels,
                datasets: [{
                    data: courseDistributionPayload.values,
                    backgroundColor: courseDistributionPayload.labels.map((_, idx) => {
                        const colors = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#f472b6','#22d3ee','#a855f7','#14b8a6'];
                        return colors[idx % colors.length];
                    })
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12 }
                    }
                }
            }
        });
    }

    function renderTimeSpentChart() {
        const ctx = document.getElementById('courseTimeSpentChart');
        if (!ctx || !window.Chart || !courseTimePayload.course_names.length) {
            return;
        }
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: courseTimePayload.course_names,
                datasets: [{
                    label: 'Average Time Spent (hours)',
                    data: courseTimePayload.average_times,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 3,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointHoverBackgroundColor: '#3b82f6',
                    pointHoverBorderColor: '#ffffff',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 0
                },
                animations: {
                    colors: false,
                    x: false,
                    y: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 15,
                        titleFont: {
                            size: 14,
                            family: 'Inter',
                            weight: '700'
                        },
                        bodyFont: {
                            size: 13,
                            family: 'Inter'
                        },
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                const hours = context.parsed.y;
                                const minutes = Math.round((hours % 1) * 60);
                                const wholeHours = Math.floor(hours);
                                if (wholeHours > 0) {
                                    return 'Avg Time: ' + wholeHours + 'h ' + minutes + 'm';
                                } else {
                                    return 'Avg Time: ' + minutes + 'm';
                                }
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12,
                                family: 'Inter',
                                weight: '600'
                            },
                            color: '#6b7280',
                            callback: function(value) {
                                return value + ' hrs';
                            }
                        },
                        grid: {
                            color: '#f3f4f6',
                            drawBorder: false
                        },
                        title: {
                            display: true,
                            text: 'Average Time Spent',
                            font: {
                                size: 13,
                                family: 'Inter',
                                weight: '700'
                            },
                            color: '#374151'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 11,
                                family: 'Inter',
                                weight: '600'
                            },
                            color: '#6b7280',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        title: {
                            display: true,
                            text: 'Courses',
                            font: {
                                size: 13,
                                family: 'Inter',
                                weight: '700'
                            },
                            color: '#374151'
                        }
                    }
                }
            }
        });
    }

    initEnrollmentChart();
    renderCourseDistribution();
    renderTimeSpentChart();
})();
</script>
<?php endif; ?>

