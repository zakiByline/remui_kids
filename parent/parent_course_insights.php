<?php
/**
 * Parent Course Insights Page
 *
 * Provides a visual overview of all courses associated with a parent's linked
 * children, including cohort groupings, status breakdowns, and per-child cards.
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $PAGE, $OUTPUT;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');

if (!function_exists('theme_remui_kids_parent_number_format')) {
    function theme_remui_kids_parent_number_format($value, int $decimals = 0): string {
        if (function_exists('format_float')) {
            return format_float($value, $decimals);
        }

        $floatvalue = is_numeric($value) ? (float)$value : 0.0;
        return number_format($floatvalue, $decimals);
    }
}

if (!theme_remui_kids_user_is_parent($USER->id)) {
    redirect(
        new moodle_url('/'),
        get_string('nopermissions', 'error', get_string('access', 'moodle')),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_course_insights.php');
$PAGE->set_title(get_string('parent_course_insights_title', 'theme_remui_kids'));
$PAGE->set_heading(get_string('parent_course_insights_title', 'theme_remui_kids'));
$PAGE->set_pagelayout('base');

$children = get_parent_children($USER->id);

$overallstats = [
    'children' => count($children),
    'courses' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'tracking_disabled' => 0,
    'average_progress' => null,
    'category_count' => 0,
];

$childcards = [];
$cohortgroups = [];
$chartlabels = [];
$chartcompleted = [];
$chartinprogress = [];
$chartnotstarted = [];
$charttrackingdisabled = [];

$uniqueCourseIds = [];
$uniqueCategoryMap = [];
$progresssum = 0;
$progresscount = 0;

foreach ($children as $child) {
    $childcourses = enrol_get_users_courses($child['id'], true, '*', 'visible DESC, fullname ASC');

    $stats = [
        'total' => 0,
        'completed' => 0,
        'in_progress' => 0,
        'not_started' => 0,
        'tracking_disabled' => 0,
    ];

    $categorybreakdown = [];
    $coursecards = [];

    foreach ($childcourses as $course) {
        $courserecord = $DB->get_record('course', ['id' => $course->id]);
        if (!$courserecord) {
            continue;
        }

        $stats['total']++;
        $overallstats['courses']++;
        $uniqueCourseIds[$courserecord->id] = true;

        $coursecontext = context_course::instance($courserecord->id);
        $courseimage = theme_remui_kids_get_course_image($courserecord);

        $completioninfo = new completion_info($courserecord);
        $progresspercent = null;
        $status = 'notstarted';

        if ($completioninfo->is_enabled()) {
            $rawprogress = \core_completion\progress::get_course_progress_percentage($courserecord, $child['id']);
            if ($rawprogress !== null && $rawprogress !== false) {
                $progresspercent = (int) round($rawprogress);
                $progresssum += $progresspercent;
                $progresscount++;
                if ($progresspercent >= 100) {
                    $status = 'completed';
                    $stats['completed']++;
                    $overallstats['completed']++;
                } elseif ($progresspercent > 0) {
                    $status = 'inprogress';
                    $stats['in_progress']++;
                    $overallstats['in_progress']++;
                } else {
                    $status = 'notstarted';
                    $stats['not_started']++;
                    $overallstats['not_started']++;
                }
            } else {
                $status = 'trackingdisabled';
                $stats['tracking_disabled']++;
                $overallstats['tracking_disabled']++;
            }
        } else {
            $status = 'trackingdisabled';
            $stats['tracking_disabled']++;
            $overallstats['tracking_disabled']++;
        }

        $categoryname = '';
        if (!empty($courserecord->category)) {
            $categoryname = $DB->get_field('course_categories', 'name', ['id' => $courserecord->category]) ?: '';
        }
        if ($categoryname === '') {
            $categoryname = get_string('uncategorised', 'moodle');
        }

        $uniqueCategoryMap[strtolower($categoryname)] = $categoryname;

        if (!isset($categorybreakdown[$categoryname])) {
            $categorybreakdown[$categoryname] = 0;
        }
        $categorybreakdown[$categoryname]++;

        $lastaccessrecord = $DB->get_record('user_lastaccess', [
            'userid' => $child['id'],
            'courseid' => $courserecord->id,
        ]);
        $lastaccess = $lastaccessrecord
            ? userdate($lastaccessrecord->timeaccess, get_string('strftimedatetimeshort', 'langconfig'))
            : get_string('never');

        $gradevalue = null;
        $coursegrades = grade_get_course_grades($courserecord->id, $child['id']);
        if ($coursegrades && !empty($coursegrades->grades)) {
            $gradeobject = $coursegrades->grades[$child['id']] ?? reset($coursegrades->grades);
            if ($gradeobject) {
                if (isset($gradeobject->percentage) && $gradeobject->percentage !== null) {
                    $gradevalue = round($gradeobject->percentage) . '%';
                } elseif (isset($gradeobject->grade) && $gradeobject->grade !== null) {
                    $gradevalue = round($gradeobject->grade, 2);
                } elseif (!empty($gradeobject->formatted_grade)) {
                    $gradevalue = $gradeobject->formatted_grade;
                }
            }
        }

        $teacherusers = get_enrolled_users(
            $coursecontext,
            'moodle/course:update',
            0,
            'u.id, u.firstname, u.lastname',
            'u.lastname ASC'
        );
        $teachernames = [];
        if (!empty($teacherusers)) {
            foreach ($teacherusers as $teacheruser) {
                $teachernames[] = fullname($teacheruser);
            }
        }
        $teacherlabel = !empty($teachernames) ? implode(', ', $teachernames) : get_string('notavailable', 'moodle');

        $startdatelabel = $courserecord->startdate
            ? userdate($courserecord->startdate, get_string('strftimedatetimeshort', 'langconfig'))
            : get_string('notavailable', 'moodle');

        $coursecards[] = [
            'id' => $courserecord->id,
            'fullname' => format_string($courserecord->fullname),
            'shortname' => format_string($courserecord->shortname),
            'category' => $categoryname,
            'image' => $courseimage ?: $CFG->wwwroot . '/theme/remui_kids/pix/default_course.jpg',
            'url' => (new moodle_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $courserecord->id, 'child' => $child['id']]))->out(),
            'progress' => $progresspercent,
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'lastaccess' => $lastaccess,
            'grade' => $gradevalue,
            'teacher' => $teacherlabel,
            'startdate' => $startdatelabel,
        ];
    }

    $chartlabels[] = $child['name'];
    $chartcompleted[] = $stats['completed'];
    $chartinprogress[] = $stats['in_progress'];
    $chartnotstarted[] = $stats['not_started'];
    $charttrackingdisabled[] = $stats['tracking_disabled'];

    $cohortname = $child['cohortname'] ?: get_string('none');
    if (!isset($cohortgroups[$cohortname])) {
        $cohortgroups[$cohortname] = [
            'name' => $cohortname,
            'children' => [],
        ];
    }

    $childcards[] = [
        'details' => $child,
        'stats' => $stats,
        'categories' => $categorybreakdown,
        'courses' => $coursecards,
    ];

    $cohortgroups[$cohortname]['children'][] = end($childcards);
}

$overallstats['category_count'] = count($uniqueCategoryMap);
if ($progresscount > 0) {
    $overallstats['average_progress'] = round($progresssum / $progresscount);
}
$overallstats['courses'] = count($uniqueCourseIds);
$overallstats['completion_rate'] = $overallstats['courses']
    ? round(($overallstats['completed'] / $overallstats['courses']) * 100)
    : 0;
$overallstats['in_progress_rate'] = $overallstats['courses']
    ? round(($overallstats['in_progress'] / $overallstats['courses']) * 100)
    : 0;
$overallstats['not_started_rate'] = $overallstats['courses']
    ? round(($overallstats['not_started'] / $overallstats['courses']) * 100)
    : 0;

$chartdata = [
    'labels' => $chartlabels,
    'datasets' => [
        [
            'label' => 'Completed',
            'data' => $chartcompleted,
            'backgroundColor' => '#22c55e',
        ],
        [
            'label' => 'In Progress',
            'data' => $chartinprogress,
            'backgroundColor' => '#facc15',
        ],
        [
            'label' => 'Not Started',
            'data' => $chartnotstarted,
            'backgroundColor' => '#94a3b8',
        ],
        [
            'label' => 'Tracking Disabled',
            'data' => $charttrackingdisabled,
            'backgroundColor' => '#60a5fa',
        ],
    ],
];

$statuschartdata = [
    'labels' => ['Completed', 'In Progress', 'Not Started', 'No Tracking'],
    'datasets' => [[
        'data' => [
            (int) $overallstats['completed'],
            (int) $overallstats['in_progress'],
            (int) $overallstats['not_started'],
            (int) $overallstats['tracking_disabled'],
        ],
        'backgroundColor' => ['#22c55e', '#facc15', '#94a3b8', '#60a5fa'],
        'borderWidth' => 0,
        'hoverOffset' => 6,
    ]],
];

$chartdatajson = json_encode($chartdata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$statuschartdatajson = json_encode($statuschartdata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$cohortgroupsjson = json_encode(array_values($cohortgroups), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$cohortcount = count($cohortgroups);
$averageprogressdisplay = $overallstats['average_progress'] !== null
    ? theme_remui_kids_parent_number_format($overallstats['average_progress']) . '%'
    : get_string('notapplicable', 'moodle');
$completionratedisplay = theme_remui_kids_parent_number_format($overallstats['completion_rate']) . '%';
$childrencountdisplay = theme_remui_kids_parent_number_format($overallstats['children']);
$coursescountdisplay = theme_remui_kids_parent_number_format($overallstats['courses']);
$lastupdated = userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
$hascourseinsights = $overallstats['courses'] > 0;
$mainclasses = 'parent-main-content course-insights-page with-sidebar';

$PAGE->requires->css('/theme/remui_kids/style/parent_dashboard.css');
$PAGE->requires->css(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'));

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<style>
/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

:root {
    --insights-blue: #1d4ed8;
    --insights-slate: #475569;
    --insights-surface: #ffffff;
}

.parent-content-wrapper {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.container,
.container-fluid,
#region-main,
#region-main-box {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
}

.parent-main-content.course-insights-page {
    margin-left: 0;
    padding: 24px 26px 48px;
    background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
    min-height: 100vh;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.parent-main-content.course-insights-page.with-sidebar {
    margin-left: 280px;
    width: calc(100% - 280px);
}

.insights-container {
    width: 100%;
    max-width: 100%;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.insights-hero {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    padding: 28px 30px;
    border-radius: 24px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 24px 44px rgba(30, 64, 175, 0.16);
}

.insights-hero::after {
    content: '';
    position: absolute;
    right: -70px;
    top: -60px;
    width: 210px;
    height: 210px;
    background: rgba(255, 255, 255, 0.35);
    border-radius: 50%;
}

.insights-hero .hero-kicker {
    text-transform: uppercase;
    letter-spacing: 0.16em;
    font-size: 11px;
    font-weight: 700;
    color: #1d4ed8;
}

.insights-hero h1 {
    margin: 6px 0 0;
    font-size: 30px;
    font-weight: 800;
    color: #0f172a;
}

.insights-hero p {
    margin: 12px 0 0;
    font-size: 14px;
    color: #1f2937;
    max-width: 640px;
    line-height: 1.58;
}

.insights-hero .hero-meta {
    margin-top: 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #1d4ed8;
    letter-spacing: 0.08em;
}

.insights-hero .hero-meta i {
    font-size: 14px;
}

.insights-summary-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.summary-card {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--insights-surface);
    border-radius: 20px;
    padding: 18px 20px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 18px 32px rgba(15, 23, 42, 0.08);
}

.summary-icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #ffffff;
}

.summary-icon.blue {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
}

.summary-icon.green {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
}

.summary-icon.amber {
    background: linear-gradient(135deg, #facc15 0%, #f59e0b 100%);
    color: #1f2937;
}

.summary-icon.purple {
    background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);
}

.summary-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 700;
}

.summary-value {
    display: block;
    margin-top: 3px;
    font-size: 28px;
    font-weight: 700;
    color: #0f172a;
}

.summary-subtitle {
    margin-top: 6px;
    font-size: 13px;
    color: #64748b;
}

.insights-controls {
    background: var(--insights-surface);
    border-radius: 24px;
    padding: 22px 24px;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.16);
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    align-items: start;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.filter-group label,
.filter-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #64748b;
    font-weight: 700;
}

.search-control {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f1f5f9;
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 14px;
    padding: 10px 14px;
}

.search-control i {
    color: #94a3b8;
}

.search-control input {
    border: 0;
    background: transparent;
    flex: 1;
    font-size: 14px;
    color: #0f172a;
    outline: none;
}

.search-control input::placeholder {
    color: #94a3b8;
}

.ghost-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid rgba(29, 78, 216, 0.35);
    background: transparent;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ghost-button:hover {
    background: rgba(29, 78, 216, 0.08);
}

.ghost-button.is-hidden {
    display: none;
}

.status-toggle-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.status-toggle {
    position: relative;
    display: inline-flex;
}

.status-toggle input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.status-toggle span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.4);
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.status-toggle.completed span i {
    color: #22c55e;
}

.status-toggle.inprogress span i {
    color: #f97316;
}

.status-toggle.notstarted span i {
    color: #64748b;
}

.status-toggle.trackingdisabled span i {
    color: #0ea5e9;
}

.status-toggle input:checked + span {
    border-color: rgba(29, 78, 216, 0.6);
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.2);
}

.status-toggle input:checked + span i {
    color: inherit;
}

.actions-group .actions-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.action-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 14px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 14px 26px rgba(37, 99, 235, 0.22);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.action-button.secondary {
    background: #eef2ff;
    color: #1d4ed8;
    box-shadow: none;
}

.action-button:hover {
    transform: translateY(-1px);
}

.action-button.secondary:hover {
    background: #e0e7ff;
}

.insights-visuals {
    display: grid;
    gap: 20px;
    grid-template-columns: minmax(320px, 2fr) minmax(240px, 1fr);
}

.chart-card {
    background: var(--insights-surface);
    border-radius: 22px;
    border: 1px solid rgba(148, 163, 184, 0.15);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.chart-card .chart-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}

.chart-card .chart-header p {
    margin: 6px 0 0;
    font-size: 13px;
    color: #64748b;
}

.chart-wrapper {
    position: relative;
    min-height: 220px;
}

.chart-wrapper canvas {
    width: 100%;
    height: 100%;
}

.chart-empty {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 10px;
    text-align: center;
    color: #64748b;
    background: repeating-linear-gradient(135deg, rgba(191, 219, 254, 0.18), rgba(191, 219, 254, 0.18) 20px, rgba(255, 255, 255, 0.6) 20px, rgba(255, 255, 255, 0.6) 40px);
    border-radius: 18px;
    padding: 20px;
}

.chart-empty i {
    font-size: 28px;
    color: #3b82f6;
}

.chart-insight {
    border-radius: 18px;
    background: #f8fafc;
    border: 1px dashed rgba(148, 163, 184, 0.32);
    padding: 16px 18px;
}

.chart-insight-title {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
}

.chart-insight-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 6px;
    font-size: 13px;
    color: #0f172a;
}

.chart-insight-list li {
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-insight-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-flex;
}

.chart-insight-dot.completed { background: #22c55e; }
.chart-insight-dot.inprogress { background: #facc15; }
.chart-insight-dot.notstarted { background: #94a3b8; }
.chart-insight-dot.trackingdisabled { background: #60a5fa; }

.cohort-grid {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.cohort-section {
    background: var(--insights-surface);
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.1);
    padding: 24px 26px;
}

.cohort-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    align-items: flex-end;
}

.cohort-header h3 {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    color: #1d4ed8;
}

.cohort-subtitle {
    margin: 6px 0 0;
    font-size: 13px;
    color: #64748b;
}

.cohort-metrics {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    background: #eef2ff;
    border-radius: 999px;
    padding: 6px 14px;
}

.insights-cohorts {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.cohort-intro {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.cohort-intro h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
}

.cohort-intro p {
    margin: 0;
    font-size: 14px;
    color: #475569;
}

.cohort-cards-root {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.insights-controls {
    background: var(--insights-surface);
    border-radius: 24px;
    padding: 22px 24px;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.16);
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    align-items: start;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.filter-group label,
.filter-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #64748b;
    font-weight: 700;
}

.search-control {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f1f5f9;
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 14px;
    padding: 10px 14px;
}

.search-control i {
    color: #94a3b8;
}

.search-control input {
    border: 0;
    background: transparent;
    flex: 1;
    font-size: 14px;
    color: #0f172a;
    outline: none;
}

.search-control input::placeholder {
    color: #94a3b8;
}

.ghost-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid rgba(29, 78, 216, 0.35);
    background: transparent;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ghost-button:hover {
    background: rgba(29, 78, 216, 0.08);
}

.ghost-button.is-hidden {
    display: none;
}

.status-toggle-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.status-toggle {
    position: relative;
    display: inline-flex;
}

.status-toggle input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.status-toggle span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.4);
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.status-toggle.completed span i {
    color: #22c55e;
}

.status-toggle.inprogress span i {
    color: #f97316;
}

.status-toggle.notstarted span i {
    color: #64748b;
}

.status-toggle.trackingdisabled span i {
    color: #0ea5e9;
}

.status-toggle input:checked + span {
    border-color: rgba(29, 78, 216, 0.6);
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.2);
}

.status-toggle input:checked + span i {
    color: inherit;
}

.actions-group .actions-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.action-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 14px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 14px 26px rgba(37, 99, 235, 0.22);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.action-button.secondary {
    background: #eef2ff;
    color: #1d4ed8;
    box-shadow: none;
}

.action-button:hover {
    transform: translateY(-1px);
}

.action-button.secondary:hover {
    background: #e0e7ff;
}

.insights-visuals {
    display: grid;
    gap: 20px;
    grid-template-columns: minmax(320px, 2fr) minmax(240px, 1fr);
}

.chart-card {
    background: var(--insights-surface);
    border-radius: 22px;
    border: 1px solid rgba(148, 163, 184, 0.15);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.chart-card .chart-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}

.chart-card .chart-header p {
    margin: 6px 0 0;
    font-size: 13px;
    color: #64748b;
}

.chart-wrapper {
    position: relative;
    min-height: 220px;
}

.chart-wrapper canvas {
    width: 100%;
    height: 100%;
}

.chart-empty {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 10px;
    text-align: center;
    color: #64748b;
    background: repeating-linear-gradient(135deg, rgba(191, 219, 254, 0.18), rgba(191, 219, 254, 0.18) 20px, rgba(255, 255, 255, 0.6) 20px, rgba(255, 255, 255, 0.6) 40px);
    border-radius: 18px;
    padding: 20px;
}

.chart-empty i {
    font-size: 28px;
    color: #3b82f6;
}

.chart-insight {
    border-radius: 18px;
    background: #f8fafc;
    border: 1px dashed rgba(148, 163, 184, 0.32);
    padding: 16px 18px;
}

.chart-insight-title {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
}

.chart-insight-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 6px;
    font-size: 13px;
    color: #0f172a;
}

.chart-insight-list li {
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-insight-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-flex;
}

.chart-insight-dot.completed { background: #22c55e; }
.chart-insight-dot.inprogress { background: #facc15; }
.chart-insight-dot.notstarted { background: #94a3b8; }
.chart-insight-dot.trackingdisabled { background: #60a5fa; }

.cohort-grid {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.cohort-section {
    background: var(--insights-surface);
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.1);
    padding: 24px 26px;
}

.cohort-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    align-items: flex-end;
}

.cohort-header h3 {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    color: #1d4ed8;
}

.cohort-subtitle {
    margin: 6px 0 0;
    font-size: 13px;
    color: #64748b;
}

.cohort-metrics {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    background: #eef2ff;
    border-radius: 999px;
    padding: 6px 14px;
}


.child-card-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    align-items: stretch;
}


.child-course-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 20px;
    background: var(--insights-surface);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 380px;
}


.child-course-card summary {
    list-style: none;
    cursor: pointer;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    background: #ffffff;
    min-height: 110px;
}

.child-course-card summary::-webkit-details-marker {
    display: none;
}

.child-summary {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    width: 100%;
}

.child-summary-text h4 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
}

.child-summary-text .meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.child-summary-text .meta span {
    background: #eef2ff;
    color: #1d4ed8;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.summary-chips {
    display: flex;
    gap: 8px;
    align-items: center;
}

.summary-chip {
    min-width: 40px;
    height: 40px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    background: #f8fafc;
}

.summary-chip.completed { background: rgba(34, 197, 94, 0.12); color: #15803d; }
.summary-chip.inprogress { background: rgba(250, 204, 21, 0.18); color: #a16207; }
.summary-chip.notstarted { background: rgba(148, 163, 184, 0.15); color: #475569; }
.summary-chip.trackingdisabled { background: rgba(96, 165, 250, 0.18); color: #1e40af; }

.summary-expander {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #f1f5f9;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #1d4ed8;
    transition: transform 0.25s ease;
}

.child-course-card[open] .summary-expander i {
    transform: rotate(180deg);
}

.child-course-card[open] summary {
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}

.child-card-body {
    padding: 22px 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    background: #f9fbff;
    flex: 1;
}

.status-breakdown {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.status-breakdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 6px 12px rgba(15, 23, 42, 0.05);
}

.status-breakdown-item .status-icon {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.status-breakdown-item.completed .status-icon { background: rgba(34, 197, 94, 0.12); color: #15803d; }
.status-breakdown-item.inprogress .status-icon { background: rgba(250, 204, 21, 0.18); color: #a16207; }
.status-breakdown-item.notstarted .status-icon { background: rgba(148, 163, 184, 0.18); color: #475569; }
.status-breakdown-item.trackingdisabled .status-icon { background: rgba(96, 165, 250, 0.18); color: #1e40af; }

.status-breakdown-item .status-count {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
}

.status-breakdown-item .status-label {
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.category-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.category-chip {
    background: #ffffff;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.24);
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    display: inline-flex;
    gap: 6px;
}

.category-chip strong {
    color: #1d4ed8;
}

.child-course-list {
    display: grid;
    gap: 16px;
}


.course-item {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    display: flex;
    flex-direction: column;
    padding: 0;
    box-shadow: 0 10px 18px rgba(15, 23, 42, 0.06);
    min-height: 240px;
    overflow: hidden;
}

.course-media {
    position: relative;
    height: 140px;
    background: #e2e8f0;
}

.course-media-img {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}

.course-media-chip {
    position: absolute;
    top: 12px;
    left: 12px;
    background: rgba(15, 23, 42, 0.75);
    color: #ffffff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    letter-spacing: 0.05em;
}

.course-media-progress {
    position: absolute;
    bottom: 12px;
    right: 12px;
    background: rgba(37, 99, 235, 0.85);
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
}

.course-content {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px 20px 20px;
}

.course-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.course-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.course-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 12px;
    color: #475569;
}

.course-meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.course-footer {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.progress-block {
    display: grid;
    gap: 8px;
}

.progress-meta {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.progress-meta span:last-child {
    color: #0f172a;
}

.progress-bar-track {
    height: 8px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.24);
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb 0%, #60a5fa 100%);
}

.child-course-empty,
.child-course-empty-alt {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 10px;
    text-align: center;
    padding: 16px;
    border-radius: 16px;
    border: 1px dashed rgba(148, 163, 184, 0.35);
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
    flex-direction: column;
}

.child-course-empty-alt {
    display: flex;
}

.insights-empty-state {
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 12px;
    text-align: center;
    padding: 40px 24px;
    background: #ffffff;
    border: 2px dashed rgba(148, 163, 184, 0.35);
    border-radius: 24px;
    color: #475569;
}

.insights-empty-state i {
    font-size: 32px;
    color: #1d4ed8;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.parent-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
    color: #475569;
}

.parent-empty-state i {
    font-size: 32px;
    color: #1d4ed8;
}

.table-insights {
    width: 100%;
    border-collapse: collapse;
    background: #ffffff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.16);
}

.table-insights thead {
    background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%);
}

.table-insights th,
.table-insights td {
    padding: 14px 16px;
    text-align: left;
    font-size: 14px;
    color: #0f172a;
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    white-space: nowrap;
}

.table-insights th:first-child,
.table-insights td:first-child {
    width: 22%;
}

.table-insights tbody tr:last-child td {
    border-bottom: none;
}

.table-insights tbody tr:nth-child(even) {
    background: rgba(248, 250, 252, 0.75);
}

.table-insights .status-number {
    font-weight: 700;
    color: #1d4ed8;
}

.table-insights .status-number.zero {
    color: #94a3b8;
}

.insights-table-wrapper {
    background: #ffffff;
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.insights-table-wrapper h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
}

.insights-table-wrapper p {
    margin: 0;
    font-size: 14px;
    color: #475569;
}

@media (max-width: 1200px) {
    .insights-visuals {
        grid-template-columns: 1fr;
    }

    .course-header {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .course-status-badge {
        justify-content: flex-start;
    }

    .course-link {
        align-self: flex-start;
    }
}

@media (max-width: 992px) {
    .parent-main-content.course-insights-page {
        padding: 20px 16px 44px;
    }

    .insights-hero {
        padding: 22px;
    }

    .insights-hero h1 {
        font-size: 24px;
    }

    .summary-value {
        font-size: 24px;
    }

    .status-breakdown {
        grid-template-columns: 1fr 1fr;
    }

    .child-summary {
        flex-direction: column;
        align-items: flex-start;
    }

    .summary-chips {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}
</style>

<div class="<?php echo s($mainclasses); ?>">
    <div class="insights-container">
        <section class="insights-hero" aria-labelledby="insights-hero-heading">
            <span class="hero-kicker"><?php echo s(get_string('parent_insights_kicker', 'theme_remui_kids')); ?></span>
            <h1 id="insights-hero-heading"><?php echo s(get_string('parent_insights_heading', 'theme_remui_kids')); ?></h1>
            <p><?php echo s(get_string('parent_insights_subheading', 'theme_remui_kids')); ?></p>
            <div class="hero-meta">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <span><?php echo s(get_string('parent_insights_last_updated', 'theme_remui_kids', $lastupdated)); ?></span>
            </div>
        </section>

        <section class="insights-summary-grid" aria-label="<?php echo s(get_string('course_overview', 'theme_remui_kids')); ?>">
            <article class="summary-card" role="group" aria-label="<?php echo s(get_string('parent_insights_children', 'theme_remui_kids')); ?>">
                <div class="summary-icon blue"><i class="fas fa-children" aria-hidden="true"></i></div>
                <div>
                    <span class="summary-label"><?php echo s(get_string('parent_insights_children', 'theme_remui_kids')); ?></span>
                    <span class="summary-value"><?php echo s($childrencountdisplay); ?></span>
                    <span class="summary-subtitle"><?php echo s(get_string('parent_insights_children_helper', 'theme_remui_kids')); ?></span>
                </div>
            </article>
            <article class="summary-card" role="group" aria-label="<?php echo s(get_string('parent_insights_courses', 'theme_remui_kids')); ?>">
                <div class="summary-icon green"><i class="fas fa-book-open" aria-hidden="true"></i></div>
                <div>
                    <span class="summary-label"><?php echo s(get_string('parent_insights_courses', 'theme_remui_kids')); ?></span>
                    <span class="summary-value"><?php echo s($coursescountdisplay); ?></span>
                    <span class="summary-subtitle"><?php echo s(get_string('parent_insights_courses_helper', 'theme_remui_kids')); ?></span>
                </div>
            </article>
            <article class="summary-card" role="group" aria-label="<?php echo s(get_string('parent_insights_average_progress', 'theme_remui_kids')); ?>">
                <div class="summary-icon amber"><i class="fas fa-gauge-high" aria-hidden="true"></i></div>
                <div>
                    <span class="summary-label"><?php echo s(get_string('parent_insights_average_progress', 'theme_remui_kids')); ?></span>
                    <span class="summary-value"><?php echo s($averageprogressdisplay); ?></span>
                    <span class="summary-subtitle"><?php echo s(get_string('parent_insights_average_helper', 'theme_remui_kids')); ?></span>
                </div>
            </article>
            <article class="summary-card" role="group" aria-label="<?php echo s(get_string('parent_insights_completion_rate', 'theme_remui_kids')); ?>">
                <div class="summary-icon purple"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                <div>
                    <span class="summary-label"><?php echo s(get_string('parent_insights_completion_rate', 'theme_remui_kids')); ?></span>
                    <span class="summary-value"><?php echo s($completionratedisplay); ?></span>
                    <span class="summary-subtitle"><?php echo s(get_string('parent_insights_completion_helper', 'theme_remui_kids')); ?></span>
                </div>
            </article>
        </section>

        <section class="insights-visuals" aria-label="<?php echo s(get_string('course_overview', 'theme_remui_kids')); ?> charts">
            <div class="chart-card">
                <div class="chart-header">
                    <h2><?php echo s(get_string('parent_insights_chart_child_status', 'theme_remui_kids')); ?></h2>
                    <p><?php echo s(get_string('parent_insights_chart_child_helper', 'theme_remui_kids')); ?></p>
                </div>
                <div class="chart-wrapper">
                    <canvas id="childCourseStatusChart" role="img" aria-label="<?php echo s(get_string('parent_insights_chart_child_status', 'theme_remui_kids')); ?>"></canvas>
                    <div class="chart-empty" id="childCourseStatusChartEmpty" data-empty-text="<?php echo s(get_string('parent_insights_chart_child_empty', 'theme_remui_kids')); ?>" style="<?php echo $hascourseinsights ? '' : 'display:flex;'; ?>">
                        <i class="fas fa-chart-column" aria-hidden="true"></i>
                        <p><?php echo s(get_string('parent_insights_chart_child_empty', 'theme_remui_kids')); ?></p>
                    </div>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h2><?php echo s(get_string('parent_insights_chart_overall_status', 'theme_remui_kids')); ?></h2>
                    <p><?php echo s(get_string('parent_insights_chart_overall_helper', 'theme_remui_kids')); ?></p>
                </div>
                <div class="chart-wrapper">
                    <canvas id="overallStatusChart" role="img" aria-label="<?php echo s(get_string('parent_insights_chart_overall_status', 'theme_remui_kids')); ?>"></canvas>
                    <div class="chart-empty" id="overallStatusChartEmpty" data-empty-text="<?php echo s(get_string('parent_insights_chart_overall_empty', 'theme_remui_kids')); ?>" style="<?php echo $hascourseinsights ? '' : 'display:flex;'; ?>">
                        <i class="fas fa-chart-pie" aria-hidden="true"></i>
                        <p><?php echo s(get_string('parent_insights_chart_overall_empty', 'theme_remui_kids')); ?></p>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!empty($childcards)) : ?>
        <section class="insights-table-wrapper" aria-labelledby="insights-table-heading">
            <div>
                <h2 id="insights-table-heading"><?php echo s(get_string('parent_insights_table_heading', 'theme_remui_kids')); ?></h2>
                <p><?php echo s(get_string('parent_insights_table_subheading', 'theme_remui_kids')); ?></p>
            </div>
            <div class="table-responsive">
                <table class="table-insights" role="table">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_child', 'theme_remui_kids')); ?></th>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_courses', 'theme_remui_kids')); ?></th>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_completed', 'theme_remui_kids')); ?></th>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_inprogress', 'theme_remui_kids')); ?></th>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_notstarted', 'theme_remui_kids')); ?></th>
                            <th scope="col"><?php echo s(get_string('parent_insights_table_notracking', 'theme_remui_kids')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($childcards as $childcard) :
                            $details = $childcard['details'];
                            $stats = $childcard['stats'];
                        ?>
                        <tr>
                            <td><?php echo s($details['name']); ?></td>
                            <td><span class="status-number<?php echo $stats['total'] ? '' : ' zero'; ?>"><?php echo theme_remui_kids_parent_number_format($stats['total']); ?></span></td>
                            <td><span class="status-number<?php echo $stats['completed'] ? '' : ' zero'; ?>"><?php echo theme_remui_kids_parent_number_format($stats['completed']); ?></span></td>
                            <td><span class="status-number<?php echo $stats['in_progress'] ? '' : ' zero'; ?>"><?php echo theme_remui_kids_parent_number_format($stats['in_progress']); ?></span></td>
                            <td><span class="status-number<?php echo $stats['not_started'] ? '' : ' zero'; ?>"><?php echo theme_remui_kids_parent_number_format($stats['not_started']); ?></span></td>
                            <td><span class="status-number<?php echo $stats['tracking_disabled'] ? '' : ' zero'; ?>"><?php echo theme_remui_kids_parent_number_format($stats['tracking_disabled']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="insights-cohorts" aria-labelledby="cohort-breakdown-heading">
            <div class="cohort-intro">
                <h2 id="cohort-breakdown-heading"><?php echo s(get_string('parent_insights_cohort_heading', 'theme_remui_kids')); ?></h2>
                <p><?php echo s(get_string('parent_insights_cohort_subheading', 'theme_remui_kids')); ?></p>
            </div>
            <div id="insightsEmptyState" class="insights-empty-state" style="<?php echo $hascourseinsights ? 'display:none;' : 'display:flex;'; ?>">
                <i class="fas fa-chart-simple" aria-hidden="true"></i>
                <strong><?php echo s(get_string('parent_insights_empty_state_title', 'theme_remui_kids')); ?></strong>
                <p><?php echo s(get_string('parent_insights_empty_state_body', 'theme_remui_kids')); ?></p>
            </div>
            <div id="cohortCardsRoot" class="cohort-cards-root" role="region" aria-live="polite"></div>
        </section>
    </div>
</div>

<script>
const chartConfig = <?php echo $chartdatajson; ?>;
const statusChartConfig = <?php echo $statuschartdatajson; ?>;

const numberFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const escapeHTML = (str) => {
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
};

const buildDatasetTotals = (datasets = []) => {
    return datasets.map(dataset => {
        const total = Array.isArray(dataset.data)
            ? dataset.data.reduce((sum, value) => sum + Number(value || 0), 0)
            : 0;
        return {
            label: dataset.label || '',
            total,
            color: dataset.backgroundColor || '#94a3b8',
        };
    });
};

const renderEmptyChartSummary = (container, totals) => {
    if (!container) {
        return;
    }
    const emptyText = container.dataset.emptyText || container.textContent || '';
    const summaryMarkup = totals.map(item => `
        <li>
            <span>${escapeHTML(item.label)}</span>
            <span>${numberFormatter.format(item.total)}</span>
        </li>
    `).join('');

    container.innerHTML = `
        <i class="fas fa-chart-column" aria-hidden="true"></i>
        <p>${escapeHTML(emptyText)}</p>
        <ul class="chart-empty-list">${summaryMarkup}</ul>
    `;
};

const setupCharts = () => {
    const stackedCanvas = document.getElementById('childCourseStatusChart');
    const stackedEmpty = document.getElementById('childCourseStatusChartEmpty');
    const donutCanvas = document.getElementById('overallStatusChart');
    const donutEmpty = document.getElementById('overallStatusChartEmpty');

    if (stackedCanvas) {
        const hasLabels = chartConfig && Array.isArray(chartConfig.labels) && chartConfig.labels.length > 0;
        const datasets = chartConfig?.datasets || [];
        const hasProgress = hasLabels && datasets.some(dataset =>
            Array.isArray(dataset.data) && dataset.data.some(value => Number(value) > 0)
        );

        if (hasLabels) {
            const maxDatasetValue = Math.max(0, ...datasets.flatMap(dataset =>
                Array.isArray(dataset.data) ? dataset.data.map(value => Number(value) || 0) : []
            ));

            stackedCanvas.style.display = 'block';
            stackedEmpty.style.display = 'none';

            new Chart(stackedCanvas, {
                type: 'bar',
                data: chartConfig,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, ticks: { color: '#475569' } },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            suggestedMax: maxDatasetValue > 0 ? undefined : 1,
                            ticks: {
                                color: '#475569',
                                callback: value => numberFormatter.format(value)
                            }
                        }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true } },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: context => `${context.dataset.label}: ${numberFormatter.format(context.parsed.y || 0)}`
                            }
                        }
                    }
                }
            });

            if (!hasProgress) {
                const totals = buildDatasetTotals(datasets);
                renderEmptyChartSummary(stackedEmpty, totals);
                stackedEmpty.style.display = 'flex';
            }
        } else {
            stackedCanvas.style.display = 'none';
            const totals = buildDatasetTotals(datasets);
            renderEmptyChartSummary(stackedEmpty, totals);
            stackedEmpty.style.display = 'flex';
        }
    }

    if (donutCanvas) {
        const sum = statusChartConfig && statusChartConfig.datasets && statusChartConfig.datasets[0]
            ? statusChartConfig.datasets[0].data.reduce((total, value) => total + Number(value || 0), 0)
            : 0;

        if (sum > 0) {
            donutEmpty.style.display = 'none';
            donutCanvas.style.display = 'block';
            new Chart(donutCanvas, {
                type: 'doughnut',
                data: statusChartConfig,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true } }
                    }
                }
            });
        } else {
            const totals = buildDatasetTotals(statusChartConfig?.datasets || []);
            renderEmptyChartSummary(donutEmpty, totals);
            donutCanvas.style.display = 'block';
            new Chart(donutCanvas, {
                type: 'doughnut',
                data: {
                    labels: statusChartConfig.labels || ['Completed', 'In Progress', 'Not Started', 'No Tracking'],
                    datasets: [{
                        data: (statusChartConfig.labels || ['A']).map(() => 1),
                        backgroundColor: statusChartConfig.datasets && statusChartConfig.datasets[0]
                            ? statusChartConfig.datasets[0].backgroundColor
                            : ['#22c55e', '#facc15', '#94a3b8', '#60a5fa'],
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true } },
                        tooltip: {
                            callbacks: {
                                label: context => `${context.label || ''}: 0`
                            }
                        }
                    }
                }
            });
        }
    }
};

document.addEventListener('DOMContentLoaded', setupCharts);
</script>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php
echo $OUTPUT->footer();
?>





