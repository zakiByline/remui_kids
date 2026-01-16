<?php
/**
 * School Performance Analytics Dashboard
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $PAGE, $OUTPUT, $USER;

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/admin_analytics.php');
$PAGE->set_title('School Performance Analytics');
$PAGE->set_heading('School Performance Analytics');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('admin-analytics-page school-performance-dashboard');
$PAGE->set_cacheable(false);

$action = optional_param('action', '', PARAM_ALPHA);
$selectedschool = optional_param('school', 0, PARAM_INT);
$academicview = optional_param('academicview', 'month', PARAM_ALPHA);
$academicyear = optional_param('academicyear', (int)date('Y'), PARAM_INT);

$academicview = in_array($academicview, ['month', 'year'], true) ? $academicview : 'month';
$academicyear = ($academicyear < 2000 || $academicyear > ((int)date('Y') + 1)) ? (int)date('Y') : $academicyear;

// Handle PDF download
if ($action === 'downloadpdf' && $selectedschool > 0) {
    require_once($CFG->libdir . '/pdflib.php');
    remuikids_admin_generate_pdf_report($selectedschool, $tables, $academicview, $academicyear);
    exit;
}

$tables = remuikids_admin_get_iomad_tables();
$schools = remuikids_admin_get_accessible_schools((int)$USER->id, $tables);
$schoolselected = $selectedschool > 0 && array_key_exists($selectedschool, $schools);

$dashboard = [];
if ($schoolselected) {
    $dashboard = remuikids_admin_collect_school_dashboard($selectedschool, $tables, $academicview, $academicyear);
}

$chartpayloadjson = !empty($dashboard['charts'] ?? [])
    ? json_encode($dashboard['charts'], JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK)
    : '{}';

echo $OUTPUT->header();

require_once(__DIR__ . '/includes/admin_sidebar.php');
include(__DIR__ . '/admin_analytics_styles.php');

$templatecontext = [
    'stepone' => [
        'has_schools' => !empty($schools),
        'schools' => remuikids_admin_format_school_options($schools, $selectedschool),
    ],
    'selectedschoolid' => $selectedschool,
    'selectedschoolname' => $schoolselected ? format_string($schools[$selectedschool]->name) : '',
    'showanalytics' => $schoolselected,
    'overview' => $dashboard['overview'] ?? [],
    'viewoptions' => [
        ['value' => 'month', 'label' => get_string('months', 'moodle'), 'selected' => $academicview === 'month'],
        ['value' => 'year', 'label' => get_string('year', 'moodle'), 'selected' => $academicview === 'year'],
    ],
    'academicview' => $academicview,
    'academicyear' => $academicyear,
    'academictrend' => $dashboard['academictrend']['series'] ?? [],
    'gradelevels' => $dashboard['gradelevels'] ?? [],
    'courseinsights' => $dashboard['courseinsights'] ?? [],
    'studentperformance' => $dashboard['students'] ?? ['top' => [], 'bottom' => []],
    'resourceanalytics' => $dashboard['resources'] ?? [],
    'teacheranalytics' => $dashboard['teachers'] ?? [],
    'coursecompletions' => $dashboard['coursecompletions'] ?? [],
    'attendance' => $dashboard['attendance'] ?? ['enabled' => false],
    'earlywarnings' => $dashboard['earlywarnings'] ?? [],
    'earlywarning_filters' => [
        'students' => remuikids_admin_get_unique_students($dashboard['earlywarnings'] ?? []),
        'grades' => remuikids_admin_get_unique_grades($dashboard['earlywarnings'] ?? []),
    ],
    'competencies' => $dashboard['competencies'] ?? ['enabled' => false],
    'systemusage' => $dashboard['systemusage'] ?? [],
    'chartpayload' => $chartpayloadjson,
    'pdf_url' => $CFG->wwwroot . '/theme/remui_kids/admin/admin_analytics_pdf.php',
];

echo $OUTPUT->render_from_template('theme_remui_kids/admin_school_analytics', $templatecontext);

echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
include(__DIR__ . '/admin_analytics_scripts.php');

    echo $OUTPUT->footer();

// -----------------------------------------------------------------------------
// Helper functions (data access + transformations)
// -----------------------------------------------------------------------------

function remuikids_admin_get_iomad_tables(): array {
global $DB;

    $preferred = [
        'company' => 'iomad_company',
        'company_users' => 'iomad_company_users',
        'company_course' => 'iomad_company_course',
    ];

    $fallback = [
        'company' => 'company',
        'company_users' => 'company_users',
        'company_course' => 'company_course',
    ];

    $tables = [];
    foreach ($preferred as $key => $tablename) {
        $tables[$key] = $DB->get_manager()->table_exists($tablename)
            ? $tablename
            : $fallback[$key];
    }

    return $tables;
}

function remuikids_admin_get_accessible_schools(int $userid, array $tables): array {
global $DB;

    $companytable = $tables['company'];
    $companyuserstable = $tables['company_users'];

    if (is_siteadmin($userid)) {
        return $DB->get_records($companytable, null, 'name ASC', 'id, name');
    }

    if (!$DB->get_manager()->table_exists($companyuserstable)) {
        return [];
    }

    $sql = "SELECT DISTINCT c.id, c.name
            FROM {" . $companytable . "} c
            JOIN {" . $companyuserstable . "} cu ON cu.companyid = c.id
            WHERE cu.userid = :userid
            ORDER BY c.name ASC";

    return $DB->get_records_sql($sql, ['userid' => $userid]);
}

function remuikids_admin_collect_school_dashboard(
    int $schoolid,
    array $tables,
    string $academicview,
    int $academicyear
): array {
    $courseids = remuikids_admin_get_school_course_ids($schoolid, $tables);
    $students = remuikids_admin_get_school_user_ids($schoolid, $tables, 'students');
    $teachers = remuikids_admin_get_school_user_ids($schoolid, $tables, 'teachers');
    $timeframe = remuikids_admin_get_timeframe($academicview, $academicyear);

    $overview = remuikids_admin_overview_cards($courseids, $students, $teachers, $timeframe);
    $academictrend = remuikids_admin_academic_trend($courseids, $students, $timeframe);
    $gradelevels = remuikids_admin_grade_level_performance($schoolid, $courseids, $students);
    $courseInsights = remuikids_admin_course_insights($courseids, $students, $timeframe);
    $studentsperf = remuikids_admin_student_performance_tables($schoolid, $courseids, $students, $timeframe);
    $resources = remuikids_admin_resource_utilization($courseids, $students, $timeframe);
    $teachersperf = remuikids_admin_teacher_effectiveness($schoolid, $courseids, $teachers, $timeframe);
    $coursecompletions = remuikids_admin_course_completion_trends($schoolid, $courseids, $teachers, $timeframe);
    $attendance = remuikids_admin_attendance_summary($courseids, $students, $timeframe);
    $earlywarnings = remuikids_admin_early_warning_system($studentsperf['raw'] ?? []);
    $competencies = remuikids_admin_competencies_details($courseids, $students);
    $systemusage = remuikids_admin_system_usage_statistics($schoolid, $courseids, $students, $timeframe);

    return [
        'overview' => $overview,
        'academictrend' => $academictrend,
        'gradelevels' => $gradelevels,
        'courseinsights' => $courseInsights,
        'students' => [
            'top' => $studentsperf['top'] ?? [],
            'bottom' => $studentsperf['bottom'] ?? [],
        ],
        'resources' => $resources,
        'teachers' => $teachersperf,
        'coursecompletions' => $coursecompletions,
        'attendance' => $attendance,
        'earlywarnings' => $earlywarnings,
        'competencies' => $competencies,
        'systemusage' => $systemusage,
        'charts' => [
            'academicTrend' => $academictrend['chart'] ?? [],
            'gradeLevels' => $gradelevels,
            'courseInsights' => $courseInsights,
            'resources' => $resources['chart'] ?? [],
            'teachers' => $teachersperf['radar'] ?? [],
            'courseCompletions' => $coursecompletions['chart'] ?? [],
            'attendance' => $attendance['chart'] ?? [],
            'competencies' => $competencies['chart'] ?? [],
            'systemusage' => $systemusage['chart'] ?? [],
        ],
    ];
}

function remuikids_admin_system_usage_statistics(
    int $schoolid,
    array $courseids,
    array $students,
    array $timeframe
): array {
    global $DB;

    if (empty($students)) {
        return [
            'overall' => [
            'total_logins' => 0,
                'total_page_views' => 0,
                'total_time_spent' => 0,
                'avg_session_duration' => 0,
                'unique_active_users' => 0,
                'avg_logins_per_user' => 0,
            ],
            'cohort_wise' => [],
            'peak_times' => [
                'hourly' => ['labels' => [], 'values' => []],
                'daily' => ['labels' => [], 'values' => []],
            ],
            'performance' => [
                'avg_response_time' => 0,
                'total_requests' => 0,
                'error_rate' => 0,
            ],
            'chart' => [
                'overall_usage' => ['labels' => [], 'datasets' => []],
                'cohort_comparison' => ['labels' => [], 'datasets' => []],
                'peak_hours' => ['labels' => [], 'values' => []],
                'peak_days' => ['labels' => [], 'values' => []],
            ],
        ];
    }

    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    // Overall Statistics
    $overallSql = "SELECT 
                    COUNT(DISTINCT l.userid) AS unique_users,
                    COUNT(*) AS total_views
                   FROM {logstore_standard_log} l
                   WHERE l.userid $studentSql
                     AND l.timecreated BETWEEN :start AND :end
                     AND l.action = 'viewed'";
    $overall = $DB->get_record_sql($overallSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    // Calculate active days in PHP
    $activeDaysSql = "SELECT DISTINCT DATE(FROM_UNIXTIME(l.timecreated)) AS day
                       FROM {logstore_standard_log} l
                       WHERE l.userid $studentSql
                         AND l.timecreated BETWEEN :start AND :end
                         AND l.action = 'viewed'";
    $activeDays = $DB->get_records_sql($activeDaysSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));
    $activeDaysCount = count($activeDays);

    // Login statistics (simplified)
    $loginSql = "SELECT 
                  COUNT(*) AS total_logins,
                  COUNT(DISTINCT l.userid) AS unique_logins
                 FROM {logstore_standard_log} l
                 WHERE l.userid $studentSql
                   AND l.action = 'loggedin'
                   AND l.timecreated BETWEEN :start AND :end";
    $loginStats = $DB->get_record_sql($loginSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    // Get cohort-wise statistics
    $cohortSql = "SELECT 
                    c.id AS cohortid,
                    c.name AS cohortname,
                    COUNT(DISTINCT cm.userid) AS student_count,
                    COUNT(DISTINCT l.userid) AS active_users,
                    COUNT(*) AS total_views
                   FROM {cohort} c
                   JOIN {cohort_members} cm ON cm.cohortid = c.id
                   LEFT JOIN {logstore_standard_log} l ON l.userid = cm.userid
                     AND l.timecreated BETWEEN :start AND :end
                     AND l.action = 'viewed'
                   WHERE cm.userid $studentSql
                   GROUP BY c.id, c.name
                   ORDER BY c.name";
    $cohortStats = $DB->get_records_sql($cohortSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));
    
    // Calculate active days per cohort in PHP
    foreach ($cohortStats as $cohort) {
        $cohortDaysSql = "SELECT DISTINCT DATE(FROM_UNIXTIME(l.timecreated)) AS day
                          FROM {logstore_standard_log} l
                          JOIN {cohort_members} cm ON cm.userid = l.userid
                          WHERE cm.cohortid = :cohortid
                            AND l.userid $studentSql
                            AND l.timecreated BETWEEN :start AND :end
                            AND l.action = 'viewed'";
        $cohortDays = $DB->get_records_sql($cohortDaysSql, array_merge($studentParams, [
            'cohortid' => $cohort->cohortid,
            'start' => $timeframe['start'],
            'end' => $timeframe['end'],
        ]));
        $cohort->active_days = count($cohortDays);
    }

    // Peak times - Hourly
    $hourlySql = "SELECT 
                    HOUR(FROM_UNIXTIME(l.timecreated)) AS hour,
                    COUNT(*) AS views
                   FROM {logstore_standard_log} l
                   WHERE l.userid $studentSql
                     AND l.timecreated BETWEEN :start AND :end
                     AND l.action = 'viewed'
                   GROUP BY HOUR(FROM_UNIXTIME(l.timecreated))
                   ORDER BY hour";
    $hourlyData = $DB->get_records_sql($hourlySql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    // Peak times - Daily (day of week)
    $dailySql = "SELECT 
                   DAYOFWEEK(FROM_UNIXTIME(l.timecreated)) AS dayofweek,
                   DAYNAME(FROM_UNIXTIME(l.timecreated)) AS dayname,
                   COUNT(*) AS views
                  FROM {logstore_standard_log} l
                  WHERE l.userid $studentSql
                    AND l.timecreated BETWEEN :start AND :end
                    AND l.action = 'viewed'
                  GROUP BY DAYOFWEEK(FROM_UNIXTIME(l.timecreated)), DAYNAME(FROM_UNIXTIME(l.timecreated))
                  ORDER BY dayofweek";
    $dailyData = $DB->get_records_sql($dailySql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    // Time spent (approximate - count views * 15 seconds per view)
    $timeSpentSql = "SELECT 
                      COUNT(*) AS total_views
                     FROM {logstore_standard_log} l
                     WHERE l.userid $studentSql
                       AND l.action = 'viewed'
                       AND l.timecreated BETWEEN :start AND :end";
    $timeSpent = $DB->get_record_sql($timeSpentSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));
    $totalTimeSpentSeconds = (int)($timeSpent->total_views ?? 0) * 15; // 15 seconds per view estimate

    // Format overall statistics
    $totalLogins = (int)($loginStats->total_logins ?? 0);
    $uniqueLogins = (int)($loginStats->unique_logins ?? 0);
    $totalPageViews = (int)($overall->total_views ?? 0);
    $totalTimeSpent = $totalTimeSpentSeconds;
    $uniqueActiveUsers = (int)($overall->unique_users ?? 0);
    $avgLoginsPerUser = $uniqueActiveUsers > 0 ? round($totalLogins / $uniqueActiveUsers, 2) : 0;
    $avgSessionDuration = $totalLogins > 0 ? round(($totalTimeSpent / 60) / $totalLogins, 2) : 0; // Minutes

    // Format cohort-wise data
    $cohortWise = [];
    $cohortChartLabels = [];
    $cohortChartLogins = [];
    $cohortChartViews = [];
    $cohortChartActiveUsers = [];

    foreach ($cohortStats as $cohort) {
        $cohortWise[] = [
            'cohort_id' => (int)$cohort->cohortid,
            'cohort_name' => format_string($cohort->cohortname),
            'student_count' => (int)$cohort->student_count,
            'active_users' => (int)$cohort->active_users,
            'total_views' => (int)$cohort->total_views,
            'active_days' => (int)$cohort->active_days,
            'avg_views_per_user' => (int)$cohort->active_users > 0 
                ? round((int)$cohort->total_views / (int)$cohort->active_users, 2) 
                : 0,
        ];
        $cohortChartLabels[] = format_string($cohort->cohortname);
        $cohortChartLogins[] = (int)$cohort->active_users; // Using active users as proxy
        $cohortChartViews[] = (int)$cohort->total_views;
        $cohortChartActiveUsers[] = (int)$cohort->active_users;
    }

    // Format peak times - Hourly
    $hourlyLabels = [];
    $hourlyValues = [];
    for ($h = 0; $h < 24; $h++) {
        $hourlyLabels[] = sprintf('%02d:00', $h);
        $hourlyValues[] = 0;
    }
    foreach ($hourlyData as $hour) {
        $hourIndex = (int)$hour->hour;
        if ($hourIndex >= 0 && $hourIndex < 24) {
            $hourlyValues[$hourIndex] = (int)$hour->views;
        }
    }

    // Format peak times - Daily
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $dailyLabels = [];
    $dailyValues = [];
    $dailyDataMap = [];
    foreach ($dailyData as $day) {
        $dayIndex = (int)$day->dayofweek - 1; // MySQL DAYOFWEEK is 1-7, array is 0-6
        if ($dayIndex >= 0 && $dayIndex < 7) {
            $dailyDataMap[$dayIndex] = (int)$day->views;
        }
    }
    for ($d = 0; $d < 7; $d++) {
        $dailyLabels[] = $dayNames[$d];
        $dailyValues[] = $dailyDataMap[$d] ?? 0;
    }

    // Performance metrics (if available from logs)
    $performanceSql = "SELECT 
                        COUNT(*) AS total_requests,
                        SUM(CASE WHEN l.action = 'error' THEN 1 ELSE 0 END) AS error_count
                       FROM {logstore_standard_log} l
                       WHERE l.userid $studentSql
                         AND l.timecreated BETWEEN :start AND :end";
    $performance = $DB->get_record_sql($performanceSql, array_merge($studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));
    
    $totalRequests = (int)($performance->total_requests ?? 0);
    $errorCount = (int)($performance->error_count ?? 0);
    $errorRate = $totalRequests > 0 ? round(($errorCount / $totalRequests) * 100, 2) : 0;

    // Prepare chart data
    $overallUsageChart = [
        'labels' => ['Logins', 'Page Views', 'Active Users', 'Time Spent (hrs)'],
        'datasets' => [
            [
                'label' => 'Overall Usage',
                'data' => [
                    $totalLogins,
                    $totalPageViews,
                    $uniqueActiveUsers,
                    round($totalTimeSpent / 3600, 1), // Convert seconds to hours
                ],
                'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'],
            ],
        ],
    ];

    $cohortComparisonChart = [
        'labels' => $cohortChartLabels,
        'datasets' => [
            [
                'label' => 'Active Users',
                'data' => $cohortChartActiveUsers,
                'backgroundColor' => '#3b82f6',
            ],
            [
                'label' => 'Total Views',
                'data' => $cohortChartViews,
                'backgroundColor' => '#10b981',
            ],
        ],
    ];

    return [
        'overall' => [
            'total_logins' => $totalLogins,
            'total_page_views' => $totalPageViews,
            'total_time_spent' => round($totalTimeSpent / 3600, 2), // Hours
            'avg_session_duration' => round($avgSessionDuration, 2), // Minutes
            'unique_active_users' => $uniqueActiveUsers,
            'avg_logins_per_user' => $avgLoginsPerUser,
            'active_days' => $activeDaysCount,
        ],
        'cohort_wise' => $cohortWise,
        'peak_times' => [
            'hourly' => [
                'labels' => $hourlyLabels,
                'values' => $hourlyValues,
            ],
            'daily' => [
                'labels' => $dailyLabels,
                'values' => $dailyValues,
            ],
        ],
        'performance' => [
            'avg_response_time' => 0, // Not directly available from standard logs
            'total_requests' => $totalRequests,
            'error_rate' => $errorRate,
        ],
        'chart' => [
            'overall_usage' => $overallUsageChart,
            'cohort_comparison' => $cohortComparisonChart,
            'peak_hours' => [
                'labels' => $hourlyLabels,
                'values' => $hourlyValues,
            ],
            'peak_days' => [
                'labels' => $dailyLabels,
                'values' => $dailyValues,
            ],
        ],
    ];
}

function remuikids_admin_get_school_course_ids(int $schoolid, array $tables): array {
    global $DB;

    $table = $tables['company_course'];
    if (!$DB->get_manager()->table_exists($table)) {
        return [];
    }

    $sql = "SELECT DISTINCT courseid FROM {" . $table . "} WHERE companyid = :companyid";
    $courses = $DB->get_fieldset_sql($sql, ['companyid' => $schoolid]);

    return array_map('intval', $courses);
}

function remuikids_admin_get_school_user_ids(int $schoolid, array $tables, string $type): array {
    global $DB;

    $table = $tables['company_users'];
    if (!$DB->get_manager()->table_exists($table)) {
        return [];
    }

    $where = 'companyid = :companyid';
    if ($type === 'students') {
        $where .= ' AND COALESCE(educator, 0) = 0';
    } elseif ($type === 'teachers') {
        $where .= ' AND COALESCE(educator, 0) = 1';
    }

    $sql = "SELECT DISTINCT userid FROM {" . $table . "} WHERE $where";

    return array_map('intval', $DB->get_fieldset_sql($sql, ['companyid' => $schoolid]));
}

function remuikids_admin_get_timeframe(string $view, int $year): array {
    if ($view === 'year') {
        $start = strtotime('first day of January ' . $year . ' 00:00:00');
        $end = strtotime('last day of December ' . $year . ' 23:59:59');
    } else {
        $end = time();
        $start = strtotime('-11 months', strtotime(date('Y-m-01', $end)));
    }

    $periods = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $periods[] = [
            'key' => date('Y-m', $cursor),
            'label' => date('M Y', $cursor),
        ];
        $cursor = strtotime('+1 month', $cursor);
    }

    return [
        'view' => $view,
        'year' => $year,
        'start' => $start,
        'end' => $end,
        'periods' => $periods,
    ];
}

function remuikids_admin_overview_cards(array $courseids, array $students, array $teachers, array $timeframe): array {
    global $DB;

    $overview = [
        'totalstudents' => count($students),
        'totalteachers' => count($teachers),
        'averagegrade' => 0,
        'completionrate' => 0,
    ];

    if (empty($courseids) || empty($students)) {
        return $overview;
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $gradeSql = "SELECT AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100) AS avggrade
                        FROM {grade_grades} gg
                        JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.itemtype = 'course'
                   AND gi.courseid $courseSql
                   AND gg.userid $studentSql
                   AND gg.finalgrade IS NOT NULL
                   AND gg.timemodified BETWEEN :start AND :end";

    $avggrade = $DB->get_field_sql($gradeSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $overview['averagegrade'] = $avggrade !== false ? round((float)$avggrade, 2) : 0;

    $completionSql = "SELECT
            SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) AS completed,
            COUNT(*) AS total
        FROM {course_completions} cc
        WHERE cc.course $courseSql
          AND cc.userid $studentSql";

    $completion = $DB->get_record_sql($completionSql, array_merge($courseParams, $studentParams));
    if ($completion && (int)$completion->total > 0) {
        $overview['completionrate'] = round(((int)$completion->completed / (int)$completion->total) * 100, 2);
    }

    return $overview;
}

function remuikids_admin_academic_trend(array $courseids, array $students, array $timeframe): array {
    global $DB;

    $result = [
        'series' => [],
        'chart' => [
            'labels' => [],
            'avgGrade' => [],
            'assignmentCompletion' => [],
            'quizScore' => [],
            'completionRate' => [],
        ],
    ];

    if (empty($courseids) || empty($students)) {
        return $result;
    }

    $buckets = [];
    foreach ($timeframe['periods'] as $period) {
        $buckets[$period['key']] = [
            'label' => $period['label'],
            'avg_grade' => [],
            'assignment_total' => 0,
            'assignment_done' => 0,
            'quiz_grades' => [],
            'completion_total' => 0,
            'completion_done' => 0,
        ];
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $gradeSql = "SELECT gg.timemodified, (gg.finalgrade / NULLIF(gi.grademax, 0)) * 100 AS pct
                             FROM {grade_grades} gg
                             JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.itemtype = 'course'
                   AND gi.courseid $courseSql
                   AND gg.userid $studentSql
                   AND gg.finalgrade IS NOT NULL
                   AND gg.timemodified BETWEEN :start AND :end";

    $gradeRecords = $DB->get_records_sql($gradeSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    foreach ($gradeRecords as $record) {
        $bucket = date('Y-m', (int)$record->timemodified);
        if (isset($buckets[$bucket])) {
            $buckets[$bucket]['avg_grade'][] = max(0, min(100, (float)$record->pct));
        }
    }

    $assignSql = "SELECT asb.timemodified, asb.status
                  FROM {assign_submission} asb
                  JOIN {assign} a ON a.id = asb.assignment
                  WHERE a.course $courseSql
                    AND asb.userid $studentSql
                    AND asb.timemodified BETWEEN :start AND :end";

    $assignRecords = $DB->get_records_sql($assignSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    foreach ($assignRecords as $record) {
        $bucket = date('Y-m', (int)$record->timemodified);
        if (!isset($buckets[$bucket])) {
            continue;
        }
        $buckets[$bucket]['assignment_total']++;
        if (in_array($record->status, ['submitted', 'graded', 'reopened'], true)) {
            $buckets[$bucket]['assignment_done']++;
        }
    }

    $quizSql = "SELECT qa.timefinish, (qa.sumgrades / NULLIF(q.sumgrades, 0)) * 100 AS pct
                FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course $courseSql
                  AND qa.userid $studentSql
                  AND qa.timefinish > 0
                  AND qa.timefinish BETWEEN :start AND :end";

    $quizRecords = $DB->get_records_sql($quizSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    foreach ($quizRecords as $record) {
        $bucket = date('Y-m', (int)$record->timefinish);
        if (isset($buckets[$bucket])) {
            $buckets[$bucket]['quiz_grades'][] = max(0, min(100, (float)$record->pct));
        }
    }

    $completionSql = "SELECT COALESCE(cc.timecompleted, cc.timestarted) AS ref, cc.timecompleted
                              FROM {course_completions} cc
                      WHERE cc.course $courseSql
                        AND cc.userid $studentSql";

    $completionRecords = $DB->get_records_sql($completionSql, array_merge($courseParams, $studentParams));

    foreach ($completionRecords as $record) {
        $timestamp = (int)$record->ref;
        if ($timestamp <= 0) {
            continue;
        }
        $bucket = date('Y-m', $timestamp);
        if (!isset($buckets[$bucket])) {
            continue;
        }
        $buckets[$bucket]['completion_total']++;
        if (!empty($record->timecompleted)) {
            $buckets[$bucket]['completion_done']++;
        }
    }

    foreach ($buckets as $bucket) {
        $avgGrade = !empty($bucket['avg_grade'])
            ? array_sum($bucket['avg_grade']) / count($bucket['avg_grade'])
            : 0;
        $assignmentRate = $bucket['assignment_total'] > 0
            ? ($bucket['assignment_done'] / $bucket['assignment_total']) * 100
            : 0;
        $quizScore = !empty($bucket['quiz_grades'])
            ? array_sum($bucket['quiz_grades']) / count($bucket['quiz_grades'])
            : 0;
        $completionRate = $bucket['completion_total'] > 0
            ? ($bucket['completion_done'] / $bucket['completion_total']) * 100
            : 0;

        $result['series'][] = [
            'label' => $bucket['label'],
            'avg_grade' => round($avgGrade, 2),
            'assignment_completion' => round($assignmentRate, 2),
            'quiz_score' => round($quizScore, 2),
            'course_completion' => round($completionRate, 2),
        ];

        $result['chart']['labels'][] = $bucket['label'];
        $result['chart']['avgGrade'][] = round($avgGrade, 2);
        $result['chart']['assignmentCompletion'][] = round($assignmentRate, 2);
        $result['chart']['quizScore'][] = round($quizScore, 2);
        $result['chart']['completionRate'][] = round($completionRate, 2);
    }

    return $result;
}

function remuikids_admin_grade_level_performance(int $schoolid, array $courseids, array $students): array {
    global $DB;

    if (empty($students)) {
        return [];
    }

    $gradeField = $DB->get_record('user_info_field', ['shortname' => 'gradelevel'], '*', IGNORE_MISSING);
    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $gradeSql = "SELECT u.id, COALESCE(uifd.data, '') AS gradevalue
                         FROM {user} u
                 LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id";

    if ($gradeField) {
        $gradeSql .= " AND uifd.fieldid = :fieldid";
        $gradeParams = array_merge($studentParams, ['fieldid' => $gradeField->id]);
                } else {
        $gradeParams = $studentParams;
    }

    $gradeSql .= " WHERE u.id $studentSql";
    $gradeRecords = $DB->get_records_sql($gradeSql, $gradeParams);

    $buckets = [];
    foreach ($gradeRecords as $record) {
        $label = remuikids_admin_normalize_grade_label($record->gradevalue ?? '');
        $buckets[$label] = $buckets[$label] ?? [
            'label' => $label,
            'avg_grade_sum' => 0,
            'avg_grade_count' => 0,
            'participation' => 0,
            'completion_total' => 0,
            'completion_done' => 0,
            'assessments' => 0,
        ];
    }

    if (empty($buckets)) {
        $buckets['Unassigned'] = [
            'label' => 'Unassigned',
            'avg_grade_sum' => 0,
            'avg_grade_count' => 0,
            'participation' => 0,
            'completion_total' => 0,
            'completion_done' => 0,
            'assessments' => 0,
        ];
    }

    if (!empty($courseids)) {
        list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
                } else {
        $courseSql = '>= 0';
        $courseParams = [];
                }
                
    $gradeAvgSql = "SELECT gg.userid, AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100) AS avggrade
                     FROM {grade_grades} gg
                    JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                    WHERE gg.userid $studentSql
                      AND gi.courseid $courseSql
                      AND gg.finalgrade IS NOT NULL
                    GROUP BY gg.userid";
    $gradeAvgs = $DB->get_records_sql($gradeAvgSql, array_merge($studentParams, $courseParams));

    foreach ($gradeAvgs as $record) {
        $label = remuikids_admin_normalize_grade_label($gradeRecords[$record->userid]->gradevalue ?? '');
        $buckets[$label]['avg_grade_sum'] += (float)$record->avggrade;
        $buckets[$label]['avg_grade_count']++;
    }

    $participationSql = "SELECT ue.userid, COUNT(DISTINCT ue.id) AS enrols
                         FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE ue.userid $studentSql
                           AND e.courseid $courseSql
                         GROUP BY ue.userid";
    $participation = $DB->get_records_sql($participationSql, array_merge($studentParams, $courseParams));

    foreach ($participation as $record) {
        $label = remuikids_admin_normalize_grade_label($gradeRecords[$record->userid]->gradevalue ?? '');
        $buckets[$label]['participation'] += (int)$record->enrols;
    }

    $completionSql = "SELECT cc.userid,
                             COUNT(*) AS total,
                             SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) AS completed
                      FROM {course_completions} cc
                      WHERE cc.userid $studentSql
                        AND cc.course $courseSql
                      GROUP BY cc.userid";
    $completionStats = $DB->get_records_sql($completionSql, array_merge($studentParams, $courseParams));

    foreach ($completionStats as $record) {
        $label = remuikids_admin_normalize_grade_label($gradeRecords[$record->userid]->gradevalue ?? '');
        $buckets[$label]['completion_total'] += (int)$record->total;
        $buckets[$label]['completion_done'] += (int)$record->completed;
    }

    $assessmentSql = "SELECT qa.userid, COUNT(*) AS attempts
                      FROM {quiz_attempts} qa
                      JOIN {quiz} q ON q.id = qa.quiz
                      WHERE qa.userid $studentSql
                        AND q.course $courseSql
                      GROUP BY qa.userid";
    $assessmentStats = $DB->get_records_sql($assessmentSql, array_merge($studentParams, $courseParams));

    foreach ($assessmentStats as $record) {
        $label = remuikids_admin_normalize_grade_label($gradeRecords[$record->userid]->gradevalue ?? '');
        $buckets[$label]['assessments'] += (int)$record->attempts;
    }

    $ordered = [];
    for ($i = 1; $i <= 12; $i++) {
        $label = 'Grade ' . $i;
        if (isset($buckets[$label])) {
            $ordered[] = remuikids_admin_finalize_grade_bucket($buckets[$label]);
            unset($buckets[$label]);
        }
    }

    foreach ($buckets as $bucket) {
        $ordered[] = remuikids_admin_finalize_grade_bucket($bucket);
    }

    return $ordered;
}

function remuikids_admin_finalize_grade_bucket(array $bucket): array {
    $avg = $bucket['avg_grade_count'] > 0
        ? $bucket['avg_grade_sum'] / $bucket['avg_grade_count']
        : 0;
    $completionRate = $bucket['completion_total'] > 0
        ? ($bucket['completion_done'] / $bucket['completion_total']) * 100
        : 0;

    return [
        'label' => $bucket['label'],
        'avg_grade' => round($avg, 2),
        'participation' => (int)$bucket['participation'],
        'completion_rate' => round($completionRate, 2),
        'assessment_count' => (int)$bucket['assessments'],
    ];
}

function remuikids_admin_normalize_grade_label(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'Unassigned';
    }

    if (preg_match('/(\d{1,2})/', $value, $matches)) {
        $grade = (int)$matches[1];
        $grade = max(1, min(12, $grade));
        return 'Grade ' . $grade;
    }

    $value = strtolower($value);
    if (strpos($value, 'kindergarten') !== false || strpos($value, 'kg') !== false) {
        return 'Grade 1';
    }

    return ucfirst($value);
}

function remuikids_admin_course_insights(array $courseids, array $students, array $timeframe): array {
    global $DB;

    if (empty($courseids) || empty($students)) {
        return [];
    }

    $limitedCourses = array_slice($courseids, 0, 6);
    list($courseSql, $courseParams) = $DB->get_in_or_equal($limitedCourses, SQL_PARAMS_NAMED, 'crs');
    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $courses = $DB->get_records_list('course', 'id', $limitedCourses, '', 'id, fullname');

    $gradeSql = "SELECT gi.courseid, AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100) AS avggrade
                 FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                 WHERE gi.courseid $courseSql
                   AND gg.userid $studentSql
                   AND gg.finalgrade IS NOT NULL
                 GROUP BY gi.courseid";
    $gradeRecords = $DB->get_records_sql($gradeSql, array_merge($courseParams, $studentParams));

    $resourceSql = "SELECT courseid, COUNT(*) AS views
                    FROM {logstore_standard_log}
                    WHERE courseid $courseSql
                      AND userid $studentSql
                      AND timecreated BETWEEN :start AND :end
                      AND action = 'viewed'
                    GROUP BY courseid";
    $resourceRecords = $DB->get_records_sql($resourceSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $timeSql = "SELECT courseid, SUM(span) AS totals
                FROM (
                    SELECT courseid,
                           userid,
                           MAX(timecreated) - MIN(timecreated) AS span
                    FROM {logstore_standard_log}
                    WHERE courseid $courseSql
                      AND userid $studentSql
                      AND timecreated BETWEEN :start AND :end
                    GROUP BY courseid, userid
                ) course_spans
                GROUP BY courseid";
    $timeRecords = $DB->get_records_sql($timeSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $quizSql = "SELECT q.course AS courseid,
                       AVG((qa.sumgrades / NULLIF(q.sumgrades, 0)) * 100) AS quizavg
                FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course $courseSql
                  AND qa.userid $studentSql
                  AND qa.timefinish BETWEEN :start AND :end
                GROUP BY q.course";
    $quizRecords = $DB->get_records_sql($quizSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $grades = [];
    foreach ($gradeRecords as $record) {
        $grades[(int)$record->courseid] = (float)$record->avggrade;
    }

    $resources = [];
    foreach ($resourceRecords as $record) {
        $resources[(int)$record->courseid] = (int)$record->views;
    }

    $times = [];
    foreach ($timeRecords as $record) {
        $times[(int)$record->courseid] = (int)$record->totals;
    }

    $quizzes = [];
    foreach ($quizRecords as $record) {
        $quizzes[(int)$record->courseid] = (float)$record->quizavg;
    }

    $insights = [];
    foreach ($limitedCourses as $courseid) {
        if (!isset($courses[$courseid])) {
            continue;
        }
        $course = $courses[$courseid];
        $insights[] = [
            'course_id' => (int)$courseid,
            'course_name' => format_string($course->fullname),
            'avg_grade' => round($grades[$courseid] ?? 0, 2),
            'resource_views' => $resources[$courseid] ?? 0,
            'time_spent' => isset($times[$courseid]) ? max(0, round($times[$courseid] / 60)) : 0,
            'quiz_performance' => round($quizzes[$courseid] ?? 0, 2),
        ];
    }

    return $insights;
}

function remuikids_admin_student_performance_tables(
    int $schoolid,
    array $courseids,
    array $students,
    array $timeframe
): array {
    global $DB;

    if (empty($students)) {
        return ['top' => [], 'bottom' => [], 'raw' => []];
    }

    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $baseUsers = $DB->get_records_sql("SELECT id, firstname, lastname, lastaccess FROM {user} WHERE id $studentSql", $studentParams);
    if (empty($baseUsers)) {
        return ['top' => [], 'bottom' => [], 'raw' => []];
    }

    if (!empty($courseids)) {
        list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    } else {
        $courseSql = '>= 0';
        $courseParams = [];
    }

    $gradeSql = "SELECT gg.userid, AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100) AS avggrade
                 FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                 WHERE gg.userid $studentSql
                   AND gi.courseid $courseSql
                   AND gg.finalgrade IS NOT NULL
                 GROUP BY gg.userid";
    $gradeStats = $DB->get_records_sql($gradeSql, array_merge($studentParams, $courseParams));

    $resourceSql = "SELECT userid, COUNT(*) AS views
                    FROM {logstore_standard_log}
                    WHERE userid $studentSql
                      AND courseid $courseSql
                      AND timecreated BETWEEN :start AND :end
                      AND action = 'viewed'
                    GROUP BY userid";
    $resourceStats = $DB->get_records_sql($resourceSql, array_merge($studentParams, $courseParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $loginSql = "SELECT userid, COUNT(*) AS logins
                 FROM {logstore_standard_log}
                 WHERE userid $studentSql
                   AND action = 'loggedin'
                   AND timecreated BETWEEN :start AND :end
                 GROUP BY userid";
    $loginStats = $DB->get_records_sql($loginSql, array_merge($studentParams, [
        'start' => max($timeframe['start'], strtotime('-30 days')),
        'end' => $timeframe['end'],
    ]));

    $completionSql = "SELECT cc.userid,
                             COUNT(*) AS total,
                             SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) AS completed
                      FROM {course_completions} cc
                      WHERE cc.userid $studentSql
                        AND cc.course $courseSql
                      GROUP BY cc.userid";
    $completionStats = $DB->get_records_sql($completionSql, array_merge($studentParams, $courseParams));

    $cohortSql = "SELECT cm.userid, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS cohortnames
                  FROM {cohort_members} cm
                  JOIN {cohort} c ON c.id = cm.cohortid
                  WHERE cm.userid $studentSql
                  GROUP BY cm.userid";
    $cohortData = $DB->get_records_sql($cohortSql, $studentParams);

    $dataset = [];
    foreach ($baseUsers as $user) {
        $avggrade = $gradeStats[$user->id]->avggrade ?? null;
        $views = $resourceStats[$user->id]->views ?? 0;
        $logins = $loginStats[$user->id]->logins ?? 0;
        $completed = $completionStats[$user->id]->completed ?? 0;
        $total = $completionStats[$user->id]->total ?? 0;
        $progress = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        $cohort = $cohortData[$user->id]->cohortnames ?? get_string('notassigned', 'moodle');

        $dataset[] = [
            'userid' => (int)$user->id,
            'name' => fullname($user),
            'cohort' => $cohort,
            'avg_grade' => $avggrade !== null ? round((float)$avggrade, 2) : 0,
            'engagement' => (int)$views,
            'login_count' => (int)$logins,
            'course_progress' => $progress,
            'lastaccess' => $user->lastaccess ? userdate($user->lastaccess) : get_string('never'),
            'resource_views' => (int)$views,
        ];
    }

    usort($dataset, static function ($a, $b) {
        return $b['avg_grade'] <=> $a['avg_grade'];
    });

    $top = array_slice($dataset, 0, 10);
    $bottom = array_slice(array_reverse($dataset), 0, 10);

    return [
        'top' => $top,
        'bottom' => $bottom,
        'raw' => $dataset,
    ];
}

function remuikids_admin_resource_utilization(array $courseids, array $students, array $timeframe): array {
    global $DB;

    if (empty($courseids)) {
        return [
            'donut' => ['video' => 0, 'pdf' => 0, 'quiz' => 0, 'assignment' => 0],
            'all_types' => [],
            'top' => [],
            'least' => [],
            'chart' => [],
        ];
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

    $logSql = "SELECT m.name AS modname, COUNT(*) AS views
                        FROM {logstore_standard_log} l
                        JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                        JOIN {modules} m ON m.id = cm.module
               WHERE l.courseid $courseSql
                 AND l.timecreated BETWEEN :start AND :end
                        AND l.action = 'viewed'
               GROUP BY m.name
               ORDER BY views DESC";
    $logStats = $DB->get_records_sql($logSql, array_merge($courseParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $allResourceTypes = [];
    foreach ($logStats as $stat) {
        $allResourceTypes[] = [
            'type' => ucfirst($stat->modname),
            'count' => (int)$stat->views,
        ];
    }

    $categories = [
        'video' => ['videofile', 'url', 'page', 'hvp'],
        'pdf' => ['resource'],
        'quiz' => ['quiz'],
        'assignment' => ['assign'],
    ];

    $donut = ['video' => 0, 'pdf' => 0, 'quiz' => 0, 'assignment' => 0];
    foreach ($logStats as $stat) {
        foreach ($categories as $category => $mods) {
            if (in_array($stat->modname, $mods, true)) {
                $donut[$category] += (int)$stat->views;
                break;
            }
        }
    }

    $topSql = "SELECT cm.id, m.name AS modname, COUNT(*) AS views, COUNT(DISTINCT l.userid) AS uniqueusers, c.fullname AS coursename
               FROM {logstore_standard_log} l
               JOIN {course_modules} cm ON cm.id = l.contextinstanceid
               JOIN {modules} m ON m.id = cm.module
               JOIN {course} c ON c.id = cm.course
               WHERE l.courseid $courseSql
                 AND l.timecreated BETWEEN :start AND :end
                 AND l.action = 'viewed'
               GROUP BY cm.id, m.name, c.fullname
               ORDER BY views DESC
               LIMIT 3";
    $topResources = $DB->get_records_sql($topSql, array_merge($courseParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $leastSql = "SELECT cm.id, m.name AS modname, COUNT(*) AS views, c.fullname AS coursename
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module
                          JOIN {course} c ON c.id = cm.course
                          LEFT JOIN {logstore_standard_log} l ON l.contextinstanceid = cm.id
                   AND l.timecreated BETWEEN :start AND :end
                          AND l.action = 'viewed'
                 WHERE c.id $courseSql
                   AND cm.deletioninprogress = 0
                 GROUP BY cm.id, m.name, c.fullname
                 HAVING COUNT(l.id) > 0
                 ORDER BY views ASC
                 LIMIT 3";
    $leastResources = $DB->get_records_sql($leastSql, array_merge($courseParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $topFormatted = array_map(static function ($item) {
        return [
                'id' => (int)$item->id,
            'type' => ucfirst($item->modname),
            'name' => get_string('module', 'moodle') . ' #' . $item->id,
            'course' => $item->coursename,
            'views' => (int)$item->views,
            'users' => (int)$item->uniqueusers,
        ];
    }, $topResources);

    $leastFormatted = array_map(static function ($item) {
        return [
            'id' => (int)$item->id,
            'type' => ucfirst($item->modname),
            'course' => $item->coursename,
            'views' => isset($item->views) ? (int)$item->views : 0,
        ];
    }, $leastResources);

    $chartLabels = [];
    $chartValues = [];
    foreach ($allResourceTypes as $resource) {
        $chartLabels[] = $resource['type'];
        $chartValues[] = $resource['count'];
    }

    return [
        'donut' => $donut,
        'all_types' => $allResourceTypes,
        'top' => $topFormatted,
        'least' => $leastFormatted,
        'chart' => [
            'labels' => $chartLabels,
            'values' => $chartValues,
        ],
    ];
}

function remuikids_admin_teacher_effectiveness(
    int $schoolid,
    array $courseids,
    array $teachers,
    array $timeframe
): array {
    global $DB;

    if (empty($teachers)) {
        return ['radar' => [], 'leaders' => []];
    }

    list($teacherSql, $teacherParams) = $DB->get_in_or_equal($teachers, SQL_PARAMS_NAMED, 'tch');

    $courseMap = [];
    if (!empty($courseids)) {
        list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

        $assignmentSql = "SELECT ra.userid, ctx.instanceid AS courseid
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid
                          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :contextcourse
                          WHERE ra.userid $teacherSql
                            AND r.shortname = 'editingteacher'
                            AND ctx.instanceid $courseSql";
        $assignments = $DB->get_records_sql($assignmentSql, array_merge($teacherParams, $courseParams, [
            'contextcourse' => CONTEXT_COURSE,
        ]));

        foreach ($assignments as $assignment) {
            $courseMap[$assignment->userid][] = (int)$assignment->courseid;
        }
    }

    $metrics = [];
    foreach ($teachers as $teacherid) {
        $courses = $courseMap[$teacherid] ?? $courseids;
        if (empty($courses)) {
            continue;
        }

        list($teacherCourseSql, $teacherCourseParams) = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, 'tc');

        $engagement = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM {logstore_standard_log}
             WHERE userid = :userid
               AND courseid $teacherCourseSql
               AND timecreated BETWEEN :start AND :end",
            array_merge(['userid' => $teacherid], $teacherCourseParams, [
                'start' => $timeframe['start'],
                'end' => $timeframe['end'],
            ])
        );

        $loginFrequency = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {logstore_standard_log}
                 WHERE userid = :userid AND action = 'loggedin'
               AND timecreated BETWEEN :start AND :end",
            ['userid' => $teacherid, 'start' => $timeframe['start'], 'end' => $timeframe['end']]
        );

        $feedbackSql = "SELECT ag.timemodified - asub.timemodified AS diff
                                FROM {assign_grades} ag
                                JOIN {assign_submission} asub ON asub.assignment = ag.assignment AND asub.userid = ag.userid
                                JOIN {assign} a ON a.id = ag.assignment
                        WHERE ag.grader = :userid
                          AND a.course $teacherCourseSql
                          AND ag.timemodified BETWEEN :start AND :end";
        $feedbackTimes = $DB->get_fieldset_sql($feedbackSql, array_merge(['userid' => $teacherid], $teacherCourseParams, [
            'start' => $timeframe['start'],
            'end' => $timeframe['end'],
        ]));
        $gradingSpeed = !empty($feedbackTimes)
            ? round(array_sum($feedbackTimes) / count($feedbackTimes) / 3600, 2)
            : 0;

        $feedbackCount = count($feedbackTimes);

        $activityCreation = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM {logstore_standard_log}
             WHERE userid = :userid
               AND crud = 'c'
               AND component LIKE 'mod_%'
               AND courseid $teacherCourseSql
               AND timecreated BETWEEN :start AND :end",
            array_merge(['userid' => $teacherid], $teacherCourseParams, [
                'start' => $timeframe['start'],
                'end' => $timeframe['end'],
            ])
        );

        $studentPerfSql = "SELECT AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100) AS avggrade
                           FROM {grade_grades} gg
                           JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                           WHERE gi.courseid $teacherCourseSql";
        $studentPerf = $DB->get_field_sql($studentPerfSql, $teacherCourseParams);

        $metrics[] = [
            'userid' => $teacherid,
            'name' => fullname($DB->get_record('user', ['id' => $teacherid], 'id, firstname, lastname')),
            'engagement' => $engagement,
            'grading_speed' => $gradingSpeed,
            'feedback_frequency' => $feedbackCount,
            'activity_creation' => $activityCreation,
            'login_frequency' => $loginFrequency,
            'student_performance' => $studentPerf !== false ? round((float)$studentPerf, 2) : 0,
        ];
    }

    if (empty($metrics)) {
        return ['radar' => [], 'leaders' => []];
    }

    $maxValues = [
        'engagement' => max(array_column($metrics, 'engagement')) ?: 1,
        'grading_speed' => max(array_column($metrics, 'grading_speed')) ?: 1,
        'feedback_frequency' => max(array_column($metrics, 'feedback_frequency')) ?: 1,
        'activity_creation' => max(array_column($metrics, 'activity_creation')) ?: 1,
        'login_frequency' => max(array_column($metrics, 'login_frequency')) ?: 1,
        'student_performance' => max(array_column($metrics, 'student_performance')) ?: 1,
    ];

    $leaders = [];
    foreach ($metrics as $metric) {
        $normalized = [
            'engagement' => round(($metric['engagement'] / $maxValues['engagement']) * 100, 2),
            'grading_speed' => $maxValues['grading_speed'] > 0
                ? round((1 - ($metric['grading_speed'] / $maxValues['grading_speed'])) * 100, 2)
                : 100,
            'feedback_frequency' => round(($metric['feedback_frequency'] / $maxValues['feedback_frequency']) * 100, 2),
            'activity_creation' => round(($metric['activity_creation'] / $maxValues['activity_creation']) * 100, 2),
            'login_frequency' => round(($metric['login_frequency'] / $maxValues['login_frequency']) * 100, 2),
            'student_performance' => round(($metric['student_performance'] / $maxValues['student_performance']) * 100, 2),
        ];

        $overall = round(array_sum($normalized) / count($normalized), 2);

        $leaders[] = array_merge($metric, [
            'score' => $overall,
            'normalized' => $normalized,
        ]);
    }

    usort($leaders, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $radar = [
        'labels' => ['Engagement', 'Grading Speed', 'Feedback', 'Activity', 'Logins', 'Student Perf'],
        'datasets' => [],
    ];

    foreach (array_slice($leaders, 0, 5) as $leader) {
        $radar['datasets'][] = [
            'label' => $leader['name'],
            'data' => [
                $leader['normalized']['engagement'],
                $leader['normalized']['grading_speed'],
                $leader['normalized']['feedback_frequency'],
                $leader['normalized']['activity_creation'],
                $leader['normalized']['login_frequency'],
                $leader['normalized']['student_performance'],
            ],
        ];
    }

    return [
        'radar' => $radar,
        'leaders' => array_slice($leaders, 0, 10),
    ];
}

function remuikids_admin_course_completion_trends(
    int $schoolid,
    array $courseids,
    array $teachers,
    array $timeframe
): array {
    global $DB;

    if (empty($courseids)) {
        return ['chart' => [], 'percourse' => [], 'filters' => ['teachers' => []]];
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

    $chartBuckets = [];
    foreach ($timeframe['periods'] as $period) {
        $chartBuckets[$period['key']] = [
            'label' => $period['label'],
            'completed' => 0,
            'total' => 0,
        ];
    }

    $chartSql = "SELECT cc.course, cc.timecompleted, cc.timestarted
                         FROM {course_completions} cc
                 WHERE cc.course $courseSql";
    $chartRecords = $DB->get_records_sql($chartSql, $courseParams);

    foreach ($chartRecords as $record) {
        $timestamp = $record->timecompleted ?: $record->timestarted;
        if (!$timestamp) {
            continue;
        }
        $bucket = date('Y-m', (int)$timestamp);
        if (!isset($chartBuckets[$bucket])) {
            continue;
        }
        $chartBuckets[$bucket]['total']++;
        if (!empty($record->timecompleted)) {
            $chartBuckets[$bucket]['completed']++;
        }
    }

    $chart = [
        'labels' => [],
        'completed' => [],
        'completionRate' => [],
    ];

    foreach ($chartBuckets as $bucket) {
        $chart['labels'][] = $bucket['label'];
        $chart['completed'][] = (int)$bucket['completed'];
        $rate = $bucket['total'] > 0 ? ($bucket['completed'] / $bucket['total']) * 100 : 0;
        $chart['completionRate'][] = round($rate, 2);
    }

    $courses = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $courseSql", $courseParams);

    $teacherNames = [];
    if (!empty($courseids)) {
        list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
        $teacherSqlStr = "SELECT DISTINCT ctx.instanceid AS courseid, u.id, u.firstname, u.lastname
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'editingteacher'
                          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :contextcourse
                          JOIN {user} u ON u.id = ra.userid
                          WHERE ctx.instanceid $courseSql
                            AND u.deleted = 0";
        $teacherRecords = $DB->get_records_sql($teacherSqlStr, array_merge($courseParams, ['contextcourse' => CONTEXT_COURSE]));
        foreach ($teacherRecords as $record) {
            $teacherNames[$record->courseid][] = fullname($record);
        }
    }

    $perCourse = [];
    $teacherFilter = [];
    $courseFilter = [];

    foreach ($courses as $course) {
        $stats = array_filter($chartRecords, static function ($record) use ($course) {
            return (int)$record->course === (int)$course->id;
        });
        $completed = 0;
        $total = 0;
        foreach ($stats as $stat) {
            $total++;
            if (!empty($stat->timecompleted)) {
                $completed++;
            }
        }
        $rate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        $teacherlist = $teacherNames[$course->id] ?? [];
        $courseName = format_string($course->fullname);

        foreach ($teacherlist as $name) {
            $teacherFilter[$name] = true;
        }
        
        $courseFilter[$courseName] = true;

        $perCourse[] = [
            'name' => $courseName,
            'completed' => $completed,
            'total' => $total,
            'rate' => $rate,
            'teachers' => $teacherlist,
        ];
    }

    return [
        'chart' => $chart,
        'percourse' => $perCourse,
        'filters' => [
            'courses' => array_keys($courseFilter),
            'teachers' => array_keys($teacherFilter),
        ],
    ];
}

function remuikids_admin_attendance_summary(array $courseids, array $students, array $timeframe): array {
    global $DB;

    $required = ['attendance', 'attendance_sessions', 'attendance_log', 'attendance_statuses'];
    foreach ($required as $table) {
        if (!$DB->get_manager()->table_exists($table)) {
            return ['enabled' => false];
        }
    }

    if (empty($courseids) || empty($students)) {
        return ['enabled' => false];
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

    $statusSql = "SELECT ast.acronym, COUNT(*) AS total
                  FROM {attendance_log} al
                  JOIN {attendance_sessions} s ON s.id = al.sessionid
                  JOIN {attendance} a ON a.id = s.attendanceid
                  JOIN {attendance_statuses} ast ON ast.id = al.statusid
                  WHERE a.course $courseSql
                    AND al.studentid $studentSql
                    AND s.sessdate BETWEEN :start AND :end
                  GROUP BY ast.acronym";
    $statuses = $DB->get_records_sql($statusSql, array_merge($courseParams, $studentParams, [
        'start' => $timeframe['start'],
        'end' => $timeframe['end'],
    ]));

    $summary = [];
    foreach ($statuses as $status) {
        $label = strtoupper($status->acronym);
        $summary[] = [
            'label' => $label,
            'value' => (int)$status->total,
        ];
    }

    return [
        'enabled' => true,
        'summary' => $summary,
        'chart' => [
            'labels' => array_column($summary, 'label'),
            'values' => array_column($summary, 'value'),
        ],
    ];
}

function remuikids_admin_early_warning_system(array $students): array {
    if (empty($students)) {
        return [];
    }

    $warnings = [];
    foreach ($students as $student) {
        $flags = [];
        if ($student['avg_grade'] < 60) {
            $flags[] = 'Low grades';
        }
        if ($student['login_count'] < 3) {
            $flags[] = 'Low logins';
        }
        if ($student['resource_views'] < 20) {
            $flags[] = 'Low engagement';
        }
        if ($student['course_progress'] < 40) {
            $flags[] = 'Behind on courses';
        }
        if (empty($flags)) {
            continue;
        }

        $risk = min(100, count($flags) * 25);
        $warnings[] = [
            'name' => $student['name'],
            'cohort' => $student['cohort'] ?? get_string('notassigned', 'moodle'),
            'avg_grade' => $student['avg_grade'],
            'engagement' => $student['engagement'],
            'course_progress' => $student['course_progress'],
            'flags' => implode(', ', $flags),
            'risk' => $risk,
        ];
    }

    usort($warnings, static function ($a, $b) {
        return $b['risk'] <=> $a['risk'];
    });

    return array_slice($warnings, 0, 15);
}

function remuikids_admin_format_school_options(array $schools, int $selected): array {
    $options = [];
    foreach ($schools as $school) {
        $options[] = [
            'id' => (int)$school->id,
            'name' => format_string($school->name),
            'selected' => (int)$school->id === $selected,
        ];
    }
    return $options;
}

function remuikids_admin_get_unique_students(array $earlywarnings): array {
    $students = [];
    foreach ($earlywarnings as $warning) {
        $name = $warning['name'] ?? '';
        if ($name && !in_array($name, $students, true)) {
            $students[] = $name;
        }
    }
    sort($students);
    return $students;
}

function remuikids_admin_get_unique_grades(array $earlywarnings): array {
    $grades = [];
    foreach ($earlywarnings as $warning) {
        $grade = $warning['cohort'] ?? '';
        if ($grade && !in_array($grade, $grades, true)) {
            $grades[] = $grade;
        }
    }
    sort($grades);
    return $grades;
}

function remuikids_admin_competencies_details(array $courseids, array $students): array {
    global $DB;

    if (empty($courseids)) {
        return [
            'enabled' => false,
            'frameworks' => [],
            'percourse' => [],
            'summary' => [
                'total_frameworks' => 0,
                'total_competencies' => 0,
                'total_assigned' => 0,
                'total_achieved' => 0,
                'total_pending' => 0,
            ],
            'chart' => [
                'status' => ['labels' => [], 'values' => [], 'colors' => []],
                'frameworks' => ['labels' => [], 'values' => []],
                'courses' => ['labels' => [], 'achievement_rates' => [], 'assigned' => [], 'achieved' => [], 'pending' => []],
            ],
        ];
    }

    // Check if competency tables exist
    $tables = $DB->get_tables();
    $hasCompetencies = in_array('competency_framework', $tables, true) &&
                       in_array('competency', $tables, true) &&
                       in_array('competency_coursecomp', $tables, true);

    if (!$hasCompetencies) {
        return [
            'enabled' => false,
            'frameworks' => [],
            'percourse' => [],
            'summary' => [
                'total_frameworks' => 0,
                'total_competencies' => 0,
                'total_assigned' => 0,
                'total_achieved' => 0,
                'total_pending' => 0,
            ],
            'chart' => [
                'status' => ['labels' => [], 'values' => [], 'colors' => []],
                'frameworks' => ['labels' => [], 'values' => []],
                'courses' => ['labels' => [], 'achievement_rates' => [], 'assigned' => [], 'achieved' => [], 'pending' => []],
            ],
        ];
    }

    list($courseSql, $courseParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

    // Get frameworks assigned to courses
    $frameworkSql = "SELECT DISTINCT cf.id, cf.shortname, cf.idnumber, cf.description
                     FROM {competency_framework} cf
                     JOIN {competency} c ON c.competencyframeworkid = cf.id
                     JOIN {competency_coursecomp} ccc ON ccc.competencyid = c.id
                     WHERE ccc.courseid $courseSql
                     ORDER BY cf.shortname";
    $frameworks = $DB->get_records_sql($frameworkSql, $courseParams);

    $frameworkList = [];
    foreach ($frameworks as $framework) {
        $frameworkList[] = [
            'id' => (int)$framework->id,
            'name' => format_string($framework->shortname),
            'idnumber' => $framework->idnumber ?? '',
            'description' => $framework->description ?? '',
        ];
    }

    // Get course details with competencies
    $courses = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $courseSql", $courseParams);

    $perCourse = [];
    $totalCompetencies = 0;
    $totalAssigned = 0;
    $totalAchieved = 0;

    foreach ($courses as $course) {
        // Get competencies assigned to this course
        $competenciesSql = "SELECT c.id, c.shortname, c.idnumber, cf.shortname AS framework_name, cf.id AS framework_id
                           FROM {competency_coursecomp} ccc
                           JOIN {competency} c ON c.id = ccc.competencyid
                           JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
                           WHERE ccc.courseid = :courseid
                           ORDER BY cf.shortname, c.shortname";
        $assignedCompetencies = $DB->get_records_sql($competenciesSql, ['courseid' => $course->id]);

        $assignedCount = count($assignedCompetencies);
        $totalAssigned += $assignedCount;

        // Get achieved competencies for students in this course
        $achievedCount = 0;
        if (!empty($students) && !empty($assignedCompetencies)) {
            $competencyIds = array_keys($assignedCompetencies);
            list($compSql, $compParams) = $DB->get_in_or_equal($competencyIds, SQL_PARAMS_NAMED, 'comp');
            list($studentSql, $studentParams) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');

            $achievedSql = "SELECT COUNT(DISTINCT ucc.competencyid) AS achieved_count
                           FROM {competency_usercompcourse} ucc
                           WHERE ucc.courseid = :courseid
                             AND ucc.competencyid $compSql
                             AND ucc.userid $studentSql
                             AND ucc.proficiency = 1";
            $achieved = $DB->get_record_sql($achievedSql, array_merge(
                ['courseid' => $course->id],
                $compParams,
                $studentParams
            ));
            $achievedCount = (int)($achieved->achieved_count ?? 0);
        }

        $totalAchieved += $achievedCount;
        $pendingCount = $assignedCount - $achievedCount;

        // Group competencies by framework
        $byFramework = [];
        foreach ($assignedCompetencies as $comp) {
            $frameworkId = (int)$comp->framework_id;
            if (!isset($byFramework[$frameworkId])) {
                $byFramework[$frameworkId] = [
                    'framework_name' => format_string($comp->framework_name),
                    'competencies' => [],
                ];
            }
            $byFramework[$frameworkId]['competencies'][] = [
                'id' => (int)$comp->id,
                'name' => format_string($comp->shortname),
                'idnumber' => $comp->idnumber ?? '',
            ];
        }

        $perCourse[] = [
            'course_id' => (int)$course->id,
            'course_name' => format_string($course->fullname),
            'frameworks' => array_values($byFramework),
            'total_assigned' => $assignedCount,
            'total_achieved' => $achievedCount,
            'total_pending' => $pendingCount,
            'achievement_rate' => $assignedCount > 0 ? round(($achievedCount / $assignedCount) * 100, 2) : 0,
        ];

        $totalCompetencies += $assignedCount;
    }

    $totalPending = $totalAssigned - $totalAchieved;

    // Prepare chart data
    $statusChart = [
        'labels' => ['Achieved', 'Pending'],
        'values' => [$totalAchieved, $totalPending],
        'colors' => ['#22c55e', '#f97316'],
    ];

    // Framework distribution chart
    $frameworkChart = [
        'labels' => [],
        'values' => [],
    ];
    $frameworkCounts = [];
    foreach ($perCourse as $course) {
        foreach ($course['frameworks'] as $framework) {
            $frameworkName = $framework['framework_name'];
            if (!isset($frameworkCounts[$frameworkName])) {
                $frameworkCounts[$frameworkName] = 0;
            }
            $frameworkCounts[$frameworkName] += count($framework['competencies']);
        }
    }
    foreach ($frameworkCounts as $name => $count) {
        $frameworkChart['labels'][] = $name;
        $frameworkChart['values'][] = $count;
    }

    // Course achievement rate chart
    $courseChart = [
        'labels' => [],
        'achievement_rates' => [],
        'assigned' => [],
        'achieved' => [],
        'pending' => [],
    ];
    foreach ($perCourse as $course) {
        $courseChart['labels'][] = $course['course_name'];
        $courseChart['achievement_rates'][] = $course['achievement_rate'];
        $courseChart['assigned'][] = $course['total_assigned'];
        $courseChart['achieved'][] = $course['total_achieved'];
        $courseChart['pending'][] = $course['total_pending'];
    }

    return [
        'enabled' => true,
        'frameworks' => $frameworkList,
        'percourse' => $perCourse,
        'summary' => [
            'total_frameworks' => count($frameworkList),
            'total_competencies' => $totalCompetencies,
            'total_assigned' => $totalAssigned,
            'total_achieved' => $totalAchieved,
            'total_pending' => $totalPending,
            'overall_achievement_rate' => $totalAssigned > 0 ? round(($totalAchieved / $totalAssigned) * 100, 2) : 0,
        ],
        'chart' => [
            'status' => $statusChart,
            'frameworks' => $frameworkChart,
            'courses' => $courseChart,
        ],
    ];
}

