<?php
/**
 * Parent My Courses Page
 *
 * Role-aware course overview page for parents. Displays all courses attached
 * * to their linked children with detailed progress, access, and grade data.
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
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

/**
 * Determine the next due date for a course module if one exists.
 *
 * The function first inspects cached module data exposed via {@see cm_info::$customdata}
 * to avoid repeat database lookups. If no due metadata is available it falls back to
 * pulling module specific records (assign, quiz, lesson, workshop, h5pactivity).
 *
 * @param \cm_info $cm Course module info object.
 * @return int|null Unix timestamp for the due date or null when none is scheduled.
 */
function theme_remui_kids_parent_get_module_due_date(\cm_info $cm): ?int {
    global $DB;

    static $duedatecache = [];

    if (array_key_exists($cm->id, $duedatecache)) {
        return $duedatecache[$cm->id];
    }

    $duedate = null;
    $customdata = (array) $cm->customdata;
    foreach (['duedate', 'timedue', 'timeclose', 'deadline', 'cutoffdate', 'submissionend'] as $key) {
        if (!empty($customdata[$key])) {
            $duedate = (int) $customdata[$key];
            break;
        }
    }

    if ($duedate === null) {
        switch ($cm->modname) {
            case 'assign':
                if ($assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, duedate, cutoffdate')) {
                    if (!empty($assign->duedate)) {
                        $duedate = (int) $assign->duedate;
                    } elseif (!empty($assign->cutoffdate)) {
                        $duedate = (int) $assign->cutoffdate;
                    }
                }
                break;
            case 'quiz':
                $duedate = (int) ($DB->get_field('quiz', 'timeclose', ['id' => $cm->instance]) ?: 0);
                break;
            case 'lesson':
                $duedate = (int) ($DB->get_field('lesson', 'deadline', ['id' => $cm->instance]) ?: 0);
                break;
            case 'workshop':
                $duedate = (int) ($DB->get_field('workshop', 'submissionend', ['id' => $cm->instance]) ?: 0);
                break;
            case 'h5pactivity':
                $duedate = (int) ($DB->get_field('h5pactivity', 'duedate', ['id' => $cm->instance]) ?: 0);
                break;
        }
    }

    $duedatecache[$cm->id] = $duedate ?: null;

    return $duedatecache[$cm->id];
}

function theme_remui_kids_parent_get_course_contacts(context_course $coursecontext): array {
    if (function_exists('enrol_get_course_contacts')) {
        return enrol_get_course_contacts($coursecontext->instanceid);
    }

    $fallback = [];
    $users = get_enrolled_users(
        $coursecontext,
        'moodle/course:update',
        0,
        'u.id, u.firstname, u.lastname',
        'u.lastname ASC'
    );

    foreach ($users as $user) {
        $fallback[] = (object) [
            'user' => $user,
        ];
    }

    return $fallback;
}

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url('/theme/remui_kids/parent/parent_my_courses.php');
$PAGE->set_title(get_string('parent_mycourses_title', 'theme_remui_kids'));
$PAGE->set_heading(get_string('parent_mycourses_title', 'theme_remui_kids'));
$PAGE->set_pagelayout('base');

// ------------------------------------------------------------
// Access control: only users with the parent role may proceed.
// ------------------------------------------------------------
if (!theme_remui_kids_user_is_parent($USER->id)) {
    redirect(
        new moodle_url('/'),
        get_string('nopermissions', 'error', get_string('access', 'moodle')), // generic fallback message
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// ------------------------------------------------------------
// Build child selection context + normalize selected child store
// ------------------------------------------------------------
$children = get_parent_children($USER->id);
$selectedchildid = get_selected_child();

if ($selectedchildid === 0 && !empty($children)) {
    $selectedchildid = 'all';
    set_selected_child('all');
}

$childoptions = [];
if (count($children) > 1) {
    $childoptions[] = [
        'value' => 'all',
        'label' => 'All Children',
        'selected' => ($selectedchildid === 'all' || $selectedchildid === 0),
    ];
}

$selectedchildren = [];
foreach ($children as $child) {
    $iscurrent = ((int)$child['id'] === (int)$selectedchildid);
    $childoptions[] = [
        'value' => $child['id'],
        'label' => $child['name'],
        'selected' => $iscurrent,
    ];

    if ($selectedchildid === 'all' || $selectedchildid === 0 || $iscurrent) {
        $selectedchildren[] = $child;
    }
}

// If we didn't match any child (e.g. stale selection), fall back to all.
if (empty($selectedchildren) && !empty($children)) {
    $selectedchildren = $children;
    $selectedchildid = 'all';
    set_selected_child('all');
}

// ------------------------------------------------------------
// Aggregate course + progress data for each child.
// ------------------------------------------------------------
$aggregatedstats = [
    'total_courses' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'tracking_disabled' => 0,
    'outstanding_activities' => 0,
    'average_progress' => null,
];

$totalprogresssum = 0;
$totalprogresscount = 0;
$childrenrecords = [];

$coursecache = [];
$categorycache = [];
$teachercache = [];
$modinfocache = [];

if (!function_exists('theme_remui_kids_parent_course_initials')) {
    /**
     * Build a short set of initials for a course display.
     *
     * @param string $name Course name.
     * @return string Initials (1â€“3 characters).
     */
    function theme_remui_kids_parent_course_initials(string $name): string {
        $cleanname = trim(strip_tags($name));
        if ($cleanname === '') {
            return 'C';
        }

        $parts = preg_split('/[\s\-]+/u', $cleanname);
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_substr($part, 0, 1, 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 3) {
                break;
            }
        }

        return mb_strtoupper(mb_substr($initials, 0, 3, 'UTF-8'), 'UTF-8');
    }
}

$coursepalettes = [
    ['gradient' => 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)', 'text' => '#ffffff'],
    ['gradient' => 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)', 'text' => '#0f172a'],
    ['gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0f766e 100%)', 'text' => '#ffffff'],
    ['gradient' => 'linear-gradient(135deg, #38bdf8 0%, #3b82f6 100%)', 'text' => '#0f172a'],
    ['gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%)', 'text' => '#f8fafc'],
    ['gradient' => 'linear-gradient(135deg, #f97316 0%, #ea580c 100%)', 'text' => '#ffffff'],
    ['gradient' => 'linear-gradient(135deg, #ec4899 0%, #c026d3 100%)', 'text' => '#ffffff'],
    ['gradient' => 'linear-gradient(135deg, #2dd4bf 0%, #14b8a6 100%)', 'text' => '#0f172a'],
];

foreach ($selectedchildren as $child) {
    $childcourses = enrol_get_users_courses($child['id'], true, '*', 'visible DESC, fullname ASC');

    $childstats = [
        'total' => count($childcourses),
        'completed' => 0,
        'in_progress' => 0,
        'not_started' => 0,
        'tracking_disabled' => 0,
        'average_progress' => null,
        'outstanding_activities' => 0,
    ];

    $childprogresssum = 0;
    $childprogresscount = 0;
    $coursecards = [];

    foreach ($childcourses as $course) {
        if (!array_key_exists($course->id, $coursecache)) {
            $coursecache[$course->id] = $DB->get_record('course', ['id' => $course->id]);
        }

        $courserecord = $coursecache[$course->id];
        if (!$courserecord) {
            continue;
        }

        $coursecontext = context_course::instance($courserecord->id);
        $courseimage = theme_remui_kids_get_course_image($courserecord);

        $progresspercent = null;
        $progressstatus = 'notstarted';
        $paletteindex = $courserecord->id % count($coursepalettes);
        $coursepalette = $coursepalettes[$paletteindex];
        $courseinitials = theme_remui_kids_parent_course_initials($courserecord->shortname ?: $courserecord->fullname);

        $completioninfo = new completion_info($courserecord);
        if ($completioninfo->is_enabled()) {
            $rawprogress = \core_completion\progress::get_course_progress_percentage($courserecord, $child['id']);
            if ($rawprogress !== null && $rawprogress !== false) {
                $progresspercent = (int) round($rawprogress);
                $childprogresssum += $progresspercent;
                $childprogresscount++;
                $totalprogresssum += $progresspercent;
                $totalprogresscount++;

                if ($progresspercent >= 100) {
                    $progressstatus = 'completed';
                    $childstats['completed']++;
                } elseif ($progresspercent > 0) {
                    $progressstatus = 'inprogress';
                    $childstats['in_progress']++;
                } else {
                    $progressstatus = 'notstarted';
                    $childstats['not_started']++;
                }
            } else {
                $progressstatus = 'trackingdisabled';
                $childstats['tracking_disabled']++;
            }
        } else {
            $progressstatus = 'trackingdisabled';
            $childstats['tracking_disabled']++;
        }

        $categoryname = '';
        if (!empty($courserecord->category)) {
            if (!array_key_exists($courserecord->category, $categorycache)) {
                $categorycache[$courserecord->category] = $DB->get_field('course_categories', 'name', ['id' => $courserecord->category]) ?: '';
            }
            $categoryname = $categorycache[$courserecord->category];
        }
        if ($categoryname === '') {
            $categoryname = get_string('uncategorised', 'moodle');
        }

        $modinfokey = $courserecord->id . ':' . $child['id'];
        if (!array_key_exists($modinfokey, $modinfocache)) {
            $modinfocache[$modinfokey] = get_fast_modinfo($courserecord, $child['id']);
        }
        $modinfo = $modinfocache[$modinfokey];

        $totalactivities = 0;
        $completedactivities = 0;
        $remainingactivities = 0;
        $nextdue = null;

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }

            $totalactivities++;

            $iscomplete = false;
            if ($completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                $completiondata = $completioninfo->get_data($cm, false, $child['id']);
                $completionstate = (int) $completiondata->completionstate;
                if (in_array($completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true)) {
                    $completedactivities++;
                    $iscomplete = true;
                }
            }

            if (!$iscomplete) {
                $remainingactivities++;
                $duedate = theme_remui_kids_parent_get_module_due_date($cm);
                if ($duedate && $duedate > time()) {
                    if ($nextdue === null || $duedate < $nextdue['time']) {
                        $nextdue = [
                            'time' => $duedate,
                            'name' => format_string($cm->name, true, ['context' => $cm->context]),
                        ];
                    }
                }
            }
        }

        if ($remainingactivities > 0) {
            $childstats['outstanding_activities'] += $remainingactivities;
        }

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

        if (!array_key_exists($courserecord->id, $teachercache)) {
            $contacts = theme_remui_kids_parent_get_course_contacts($coursecontext);
            $names = [];
            foreach ($contacts as $contact) {
                if (!empty($contact->user)) {
                    $names[] = fullname($contact->user);
                } else {
                    $names[] = fullname($contact);
                }
            }
            $teachercache[$courserecord->id] = implode(', ', array_unique($names));
        }
        $teacherlabel = $teachercache[$courserecord->id];

        $activitiesremaininglabel = get_string('parent_course_activity_remaining', 'theme_remui_kids', $remainingactivities);
        $activitiestotallabel = get_string('parent_course_activity_total', 'theme_remui_kids', $totalactivities);
        $activitiescompletelabel = get_string('parent_course_activity_complete', 'theme_remui_kids', $completedactivities);

        $nextdueformatted = $nextdue
            ? get_string('parent_course_next_due', 'theme_remui_kids', (object) [
                'name' => $nextdue['name'],
                'date' => userdate($nextdue['time'], get_string('strftimedatetimeshort', 'langconfig')),
            ])
            : get_string('parent_course_next_due_none', 'theme_remui_kids');

        $coursecards[] = [
            'id' => $courserecord->id,
            'fullname' => format_string($courserecord->fullname),
            'shortname' => format_string($courserecord->shortname),
            'summary' => format_text($courserecord->summary, FORMAT_HTML, ['context' => $coursecontext]),
            'image' => $courseimage ?: null,
            'url' => (new moodle_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $courserecord->id, 'child' => $child['id']]))->out(),
            'category' => $categoryname,
            'progress' => $progresspercent,
            'progress_display' => $progresspercent === null ? get_string('notapplicable', 'moodle') : $progresspercent . '%',
            'progress_status' => $progressstatus,
            'progress_tracking_enabled' => $progresspercent !== null,
            'startdate' => $courserecord->startdate ? userdate($courserecord->startdate, get_string('strftimedatetimeshort', 'langconfig')) : null,
            'enddate' => $courserecord->enddate ? userdate($courserecord->enddate, get_string('strftimedatetimeshort', 'langconfig')) : null,
            'lastaccess' => $lastaccess,
            'grade' => $gradevalue,
            'teachers' => $teacherlabel,
            'viewurl' => (new moodle_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $courserecord->id, 'child' => $child['id']]))->out(),
            'childid' => $child['id'],
            'activities_total' => $totalactivities,
            'activities_completed' => $completedactivities,
            'activities_remaining' => $remainingactivities,
            'activities_total_label' => $activitiestotallabel,
            'activities_completed_label' => $activitiescompletelabel,
            'activities_remaining_label' => $activitiesremaininglabel,
            'next_due_label' => $nextdueformatted,
            'next_due_time' => $nextdue['time'] ?? null,
            'next_due_has_deadline' => $nextdue !== null,
            'palette_gradient' => $coursepalette['gradient'],
            'palette_text' => $coursepalette['text'],
            'initials' => $courseinitials,
        ];
    }

    if ($childprogresscount > 0) {
        $childstats['average_progress'] = round($childprogresssum / $childprogresscount);
    }

    $aggregatedstats['total_courses'] += $childstats['total'];
    $aggregatedstats['completed'] += $childstats['completed'];
    $aggregatedstats['in_progress'] += $childstats['in_progress'];
    $aggregatedstats['not_started'] += $childstats['not_started'];
    $aggregatedstats['tracking_disabled'] += $childstats['tracking_disabled'];
    $aggregatedstats['outstanding_activities'] += $childstats['outstanding_activities'];

    $childrenrecords[] = [
        'details' => $child,
        'stats' => $childstats,
        'courses' => $coursecards,
    ];
}

if ($totalprogresscount > 0) {
    $aggregatedstats['average_progress'] = round($totalprogresssum / $totalprogresscount);
}

$PAGE->requires->css('/theme/remui_kids/style/parent_dashboard.css');
$PAGE->requires->css(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'));

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

.parent-main-content.parent-courses-page {
    margin-left: 280px;
    padding: 32px 20px 48px;
    background: #f8fafc;
    min-height: 100vh;
    animation: fadeIn 0.4s ease;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
}

/* Responsive Design for All Screen Sizes */
@media (max-width: 1024px) {
    .parent-main-content.parent-courses-page {
        margin-left: 260px;
        width: calc(100% - 260px);
        padding: 28px 16px 40px;
    }
}

@media (max-width: 768px) {
    .parent-main-content.parent-courses-page {
        margin-left: 0;
        width: 100%;
        padding: 20px 16px 32px;
    }
    
    .parent-page-header {
        padding: 20px 20px;
        border-radius: 16px;
    }
    
    .parent-page-header h1 {
        font-size: 24px;
    }
    
    .parent-page-header p {
        font-size: 14px;
    }
    
    .parent-header-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .parent-header-actions form,
    .parent-header-actions .back-link {
        width: 100%;
    }
    
    .parent-overview-stats {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)) !important;
        gap: 12px !important;
    }
    
    .overview-stat-card {
        padding: 16px !important;
    }
    
    .overview-stat-card h3 {
        font-size: 12px !important;
    }
    
    .stat-value {
        font-size: 24px !important;
    }
    
    .parent-courses-grid {
        grid-template-columns: 1fr !important;
        gap: 16px !important;
    }
    
    .course-card {
        padding: 20px !important;
    }
}

@media (max-width: 480px) {
    .parent-main-content.parent-courses-page {
        padding: 16px 12px 24px;
    }
    
    .parent-page-header {
        padding: 16px;
        border-radius: 12px;
    }
    
    .parent-page-header h1 {
        font-size: 20px;
    }
    
    .parent-page-header p {
        font-size: 13px;
    }
    
    .parent-overview-stats {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    
    .overview-stat-card {
        padding: 14px !important;
    }
    
    .stat-value {
        font-size: 20px !important;
    }
    
    .course-card {
        padding: 16px !important;
    }
    
    .course-card-actions {
        flex-direction: column;
        gap: 8px;
    }
    
    .course-card-actions a {
        width: 100%;
        text-align: center;
    }
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

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.parent-courses-wrapper {
    max-width: none;
    width: 100%;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.parent-page-header {
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: linear-gradient(135deg, #ebf4ff 0%, #e0f2fe 100%);
    border-radius: 20px;
    padding: 28px 32px;
    position: relative;
    overflow: hidden;
}

.parent-page-header::after {
    content: '';
    position: absolute;
    top: -40px;
    right: -40px;
    width: 160px;
    height: 160px;
    background: rgba(59, 130, 246, 0.15);
    border-radius: 50%;
}

.parent-page-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0;
}

.parent-page-header p {
    max-width: 640px;
    margin: 6px 0 0;
    color: #475569;
    font-size: 15px;
    line-height: 1.5;
}

.parent-header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    z-index: 2;
}

.child-selector-form {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 12px;
    padding: 10px 14px;
    box-shadow: 0 10px 25px rgba(30, 64, 175, 0.1);
}

.child-selector-form label {
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #2563eb;
    margin: 0;
}

.child-selector-form select {
    border: none;
    background: transparent;
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
    padding-right: 8px;
    outline: none;
}

.parent-header-actions .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #2563eb;
    color: #ffffff;
    border-radius: 12px;
    padding: 10px 16px;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
    transition: all 0.2s ease;
}

.parent-header-actions .back-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 24px rgba(37, 99, 235, 0.22);
}

.parent-overview-stats {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    margin: 28px 0 36px;
}

.overview-stat-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 18px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.2);
}

.overview-stat-card h3 {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin: 0 0 12px;
}

.overview-stat-card .stat-value {
    font-size: 30px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
}

.overview-stat-card .stat-helper {
    font-size: 14px;
    color: #475569;
}

.parent-child-section {
    background: #ffffff;
    border-radius: 22px;
    box-shadow: 0 20px 36px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.18);
    margin-bottom: 40px;
    overflow: hidden;
}

.child-section-header {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 28px 32px 24px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}

.child-header-main {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-start;
    justify-content: space-between;
}

.child-header-main h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #1d4ed8;
}

.child-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    color: #475569;
    font-size: 14px;
}

.child-meta span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(37, 99, 235, 0.1);
    color: #1d4ed8;
    padding: 6px 12px;
    border-radius: 24px;
    font-weight: 600;
}

.child-stat-band {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.child-stat-card {
    background: rgba(15, 23, 42, 0.04);
    border-radius: 16px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.child-stat-card strong {
    font-size: 22px;
    color: #0f172a;
    line-height: 1;
}

.child-stat-card span {
    font-size: 13px;
    color: #64748b;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.status-filter {
    display: inline-flex;
    gap: 8px;
    background: rgba(15, 23, 42, 0.04);
    padding: 4px;
    border-radius: 999px;
}

.status-filter button {
    border: none;
    background: transparent;
    color: #475569;
    font-weight: 600;
    font-size: 13px;
    padding: 8px 16px;
    border-radius: 999px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.status-filter button.active {
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.22);
}

.parent-course-grid {
    display: grid;
    gap: 24px;
    padding: 28px 20px 36px;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    align-items: stretch;
}

.parent-course-card {
    display: flex;
    flex-direction: column;
    gap: 18px;
    background: #ffffff;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 16px 30px rgba(30, 64, 175, 0.08);
    min-height: 360px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.parent-course-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 34px rgba(30, 64, 175, 0.16);
}

.course-card-media {
    position: relative;
    padding-top: 56%;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}

.course-card-media::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.05) 15%, rgba(15, 23, 42, 0.65) 90%);
    opacity: 0.9;
    pointer-events: none;
}

.course-media-title {
    position: absolute;
    left: 16px;
    right: 16px;
    bottom: 16px;
    color: #ffffff;
    font-weight: 700;
    font-size: 16px;
    line-height: 1.35;
    z-index: 2;
    text-shadow: 0 6px 16px rgba(15, 23, 42, 0.35);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.course-category-pill {
    position: absolute;
    left: 16px;
    top: 16px;
    background: rgba(15, 23, 42, 0.65);
    color: #ffffff;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.05em;
}

.course-progress-pill {
    position: absolute;
    right: 16px;
    top: 16px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
}

.course-progress-pill.completed {
    background: rgba(22, 163, 74, 0.88);
    color: #ffffff;
}

.course-progress-pill.inprogress {
    background: rgba(251, 191, 36, 0.92);
    color: #1f2937;
}

.course-progress-pill.notstarted {
    background: rgba(226, 232, 240, 0.95);
    color: #0f172a;
}

.course-progress-pill.trackingdisabled {
    background: rgba(148, 163, 184, 0.85);
    color: #0f172a;
}

.course-card-body {
    padding: 0 22px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.course-card-body h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}

.course-card-body h3 a {
    color: inherit;
    text-decoration: none;
}

.course-card-body h3 a:hover {
    color: #2563eb;
}

.course-shortname {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    margin: -6px 0 4px;
}

.course-summary {
    color: #475569;
    font-size: 14px;
    line-height: 1.55;
    max-height: 120px;
    overflow: hidden;
}

.course-progress {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.progress-bar-track {
    height: 8px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.25);
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #2563eb 0%, #60a5fa 100%);
    transition: width 0.4s ease;
}

.course-meta-grid {
    display: grid;
    gap: 10px 16px;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    font-size: 13px;
    color: #475569;
}

.course-meta-grid span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.course-card-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.course-card-actions a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 10px;
    padding: 8px 14px;
    text-decoration: none;
    transition: background 0.2s ease, transform 0.2s ease;
}

.course-view-btn {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.18);
}

.course-view-btn:hover {
    transform: translateY(-1px);
}

.course-child-view-btn {
    background: rgba(37, 99, 235, 0.08);
    color: #1d4ed8;
}

.course-child-view-btn:hover {
    background: rgba(37, 99, 235, 0.16);
}

.course-meta-grid i {
    color: #2563eb;
}

.parent-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    padding: 80px 32px;
    text-align: center;
    color: #475569;
}

.parent-empty-state i {
    font-size: 46px;
    color: #60a5fa;
}

.parent-empty-state strong {
    font-size: 20px;
    color: #0f172a;
}

@media (max-width: 992px) {
    .parent-main-content.parent-courses-page {
        margin-left: 0;
        padding: 20px 20px 40px;
    }

    .parent-page-header {
        padding: 24px;
    }

    .child-section-header {
        padding: 24px;
    }

    .parent-course-grid {
        padding: 24px 20px;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
}

@media (max-width: 768px) {
    .parent-page-header h1 {
        font-size: 24px;
    }
    
    .parent-page-header p {
        font-size: 14px;
    }

    .parent-header-actions {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .parent-header-actions form,
    .parent-header-actions .back-link {
        width: 100%;
    }

    .parent-course-grid {
        padding: 16px;
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .course-card {
        padding: 20px !important;
    }
    
    .course-card-body {
        padding: 0 16px 20px !important;
    }
    
    .course-meta-grid {
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }
    
    .course-card-actions {
        flex-direction: column;
        gap: 10px;
    }
    
    .course-card-actions a {
        width: 100%;
        text-align: center;
    }
    
    .child-section-header {
        padding: 20px 16px !important;
    }
    
    .child-header-main {
        flex-direction: column;
    }
    
    .child-stat-band {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
    }
    
    .parent-overview-stats {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 12px !important;
    }
}

@media (max-width: 480px) {
    .parent-page-header {
        padding: 16px !important;
    }
    
    .parent-page-header h1 {
        font-size: 20px !important;
    }
    
    .parent-page-header p {
        font-size: 13px !important;
    }
    
    .parent-course-grid {
        padding: 12px !important;
        gap: 12px !important;
    }
    
    .course-card {
        padding: 16px !important;
    }
    
    .course-card-body h3 {
        font-size: 18px !important;
    }
    
    .child-section-header {
        padding: 16px !important;
    }
    
    .child-header-main h2 {
        font-size: 20px !important;
    }
    
    .child-stat-band {
        grid-template-columns: 1fr !important;
    }
    
    .parent-overview-stats {
        grid-template-columns: 1fr !important;
    }
    
    .overview-stat-card {
        padding: 16px !important;
    }
    
    .stat-value {
        font-size: 24px !important;
    }
}
</style>

<div class="parent-main-content parent-courses-page">
    <div class="parent-courses-wrapper">
        <div class="parent-page-header">
            <div>
                <h1>My Child Courses</h1>
                <p>
                    Monitor every course your children are enrolled in, track their progress,
                    recent activity, grades, and stay informed about where they might need
                    extra support.
                </p>
            </div>

            <div class="parent-header-actions">
                <?php if (!empty($childoptions)) : ?>
                    <form method="get" class="child-selector-form">
                        <label for="parent-child-selector">Viewing</label>
                        <select id="parent-child-selector" name="child" onchange="this.form.submit()">
                            <?php foreach ($childoptions as $option) : ?>
                                <option value="<?php echo s($option['value']); ?>" <?php echo !empty($option['selected']) ? 'selected' : ''; ?>>
                                    <?php echo s($option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

                <a class="back-link" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Parent Hub
                </a>
            </div>
        </div>

        <?php if (empty($children)) : ?>
            <div class="parent-child-section" style="margin-top: 32px;">
                <div class="parent-empty-state">
                    <i class="fas fa-user-friends"></i>
                    <strong>No linked students yet</strong>
                    <p>
                        Once your parent account is linked to students, their courses will
                        appear here instantly. Contact the school administrator if you need
                        help linking to your child.
                    </p>
                </div>
            </div>
        <?php else : ?>
            <div class="parent-overview-stats">
                <div class="overview-stat-card">
                    <h3>Total Courses</h3>
                    <div class="stat-value"><?php echo (int) $aggregatedstats['total_courses']; ?></div>
                    <div class="stat-helper">Across selected children</div>
                </div>
                <div class="overview-stat-card">
                    <h3>Completed</h3>
                    <div class="stat-value"><?php echo (int) $aggregatedstats['completed']; ?></div>
                    <div class="stat-helper">Finished with 100% progress</div>
                </div>
                <div class="overview-stat-card">
                    <h3>In Progress</h3>
                    <div class="stat-value"><?php echo (int) $aggregatedstats['in_progress']; ?></div>
                    <div class="stat-helper">Currently being worked on</div>
                </div>
                <div class="overview-stat-card">
                    <h3>Average Progress</h3>
                    <div class="stat-value">
                        <?php echo $aggregatedstats['average_progress'] !== null ? $aggregatedstats['average_progress'] . '%' : 'N/A'; ?>
                    </div>
                    <div class="stat-helper">Mean completion across tracked courses</div>
                </div>
            </div>

            <?php foreach ($childrenrecords as $record) :
                $child = $record['details'];
                $stats = $record['stats'];
                $courses = $record['courses'];
            ?>
                <section class="parent-child-section" id="child-<?php echo (int) $child['id']; ?>">
                    <div class="child-section-header">
                        <div class="child-header-main">
                            <div>
                                <h2><?php echo s($child['name']); ?></h2>
                                <div class="child-meta">
                                    <?php if (!empty($child['class'])) : ?>
                                        <span><i class="fas fa-layer-group"></i> Grade <?php echo s($child['class']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($child['section'])) : ?>
                                        <span><i class="fas fa-users"></i> Section <?php echo s($child['section']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($child['cohortname'])) : ?>
                                        <span><i class="fas fa-tag"></i> <?php echo s($child['cohortname']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="status-filter" data-target="child-<?php echo (int) $child['id']; ?>">
                                <button type="button" class="active" data-filter="all">All</button>
                                <button type="button" data-filter="completed">Completed</button>
                                <button type="button" data-filter="inprogress">In Progress</button>
                                <button type="button" data-filter="notstarted">Not Started</button>
                                <button type="button" data-filter="trackingdisabled">No Tracking</button>
                            </div>
                        </div>

                        <div class="child-stat-band">
                            <div class="child-stat-card">
                                <strong><?php echo (int) $stats['total']; ?></strong>
                                <span>Total Courses</span>
                            </div>
                            <div class="child-stat-card">
                                <strong><?php echo (int) $stats['completed']; ?></strong>
                                <span>Completed</span>
                            </div>
                            <div class="child-stat-card">
                                <strong><?php echo (int) $stats['in_progress']; ?></strong>
                                <span>In Progress</span>
                            </div>
                            <div class="child-stat-card">
                                <strong><?php echo (int) $stats['not_started']; ?></strong>
                                <span>Not Started</span>
                            </div>
                            <div class="child-stat-card">
                                <strong><?php echo $stats['average_progress'] !== null ? $stats['average_progress'] . '%' : 'N/A'; ?></strong>
                                <span>Average Progress</span>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($courses)) : ?>
                        <div class="parent-empty-state" style="padding: 60px 24px;">
                            <i class="fas fa-book"></i>
                            <strong>No courses yet</strong>
                            <p>
                                This student is not enrolled in any courses right now. Once
                                the school assigns courses, they will appear here automatically.
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="parent-course-grid" data-child="child-<?php echo (int) $child['id']; ?>">
                            <?php foreach ($courses as $coursecard) : ?>
                                <article class="parent-course-card" data-status="<?php echo s($coursecard['progress_status']); ?>">
                                    <?php
                                        $hascourseimage = !empty($coursecard['image']);
                                        $mediaclasses = 'course-card-media' . ($hascourseimage ? ' has-image' : ' no-image');
                                        $mediastyle = $hascourseimage
                                            ? "background-image: url('" . htmlspecialchars($coursecard['image'], ENT_QUOTES) . "');"
                                            : "background: " . htmlspecialchars($coursecard['palette_gradient'], ENT_QUOTES) . ";";
                                    ?>
                                    <div class="<?php echo $mediaclasses; ?>" style="<?php echo $mediastyle; ?>">
                                        <span class="course-category-pill"><?php echo s($coursecard['category']); ?></span>
                                        <span class="course-progress-pill <?php echo s($coursecard['progress_status']); ?>">
                                            <?php echo s($coursecard['progress_display']); ?>
                                        </span>
                                        <?php if (!$hascourseimage): ?>
                                            <span class="course-fallback-symbol" style="color: <?php echo htmlspecialchars($coursecard['palette_text'], ENT_QUOTES); ?>;">
                                                <?php echo s($coursecard['initials']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="course-media-title">
                                            <?php echo s($coursecard['fullname']); ?>
                                        </span>
                                    </div>

                                    <div class="course-card-body">
                                        <div>
                                            <div class="course-shortname"><?php echo s($coursecard['shortname']); ?></div>
                                            <h3>
                                                <a href="<?php echo $coursecard['viewurl']; ?>">
                                                    <?php echo s($coursecard['fullname']); ?>
                                                </a>
                                            </h3>
                                        </div>

                                        <?php if (!empty($coursecard['summary'])) : ?>
                                            <div class="course-summary"><?php echo $coursecard['summary']; ?></div>
                                        <?php endif; ?>

                                        <div class="course-progress">
                                            <div class="progress-heading" style="display:flex;justify-content:space-between;align-items:center;">
                                                <span style="font-size:13px;font-weight:600;color:#475569;">Progress</span>
                                                <span style="font-size:13px;color:#0f172a;font-weight:600;">
                                                    <?php echo s($coursecard['progress_display']); ?>
                                                </span>
                                            </div>
                                            <?php if ($coursecard['progress'] !== null) : ?>
                                                <div class="progress-bar-track">
                                                    <div class="progress-bar-fill" style="width: <?php echo (int) $coursecard['progress']; ?>%;"></div>
                                                </div>
                                            <?php else : ?>
                                                <div class="progress-bar-track" style="background: rgba(148,163,184,0.35); position: relative;">
                                                    <span style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);font-size:12px;color:#475569;">
                                                        Tracking not enabled
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="course-meta-grid">
                                            <span><i class="fas fa-chalkboard-teacher"></i><?php echo !empty($coursecard['teachers']) ? s($coursecard['teachers']) : 'Teacher update pending'; ?></span>
                                            <span><i class="fas fa-calendar-plus"></i><?php echo $coursecard['startdate'] ? s($coursecard['startdate']) : 'No start date'; ?></span>
                                            <span><i class="fas fa-flag-checkered"></i><?php echo $coursecard['enddate'] ? s($coursecard['enddate']) : 'No end date'; ?></span>
                                            <span><i class="fas fa-history"></i>Last access: <?php echo s($coursecard['lastaccess']); ?></span>
                                            <span><i class="fas fa-star"></i><?php echo $coursecard['grade'] ? 'Grade: ' . s($coursecard['grade']) : 'Grade not available'; ?></span>
                                        </div>

                                        <div class="course-card-actions">
                                            <a class="course-view-btn" href="<?php echo $coursecard['viewurl']; ?>">
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                                View Course & Activities
                                            </a>
                                            <a class="course-child-view-btn" href="<?php echo $coursecard['url']; ?>" target="_blank" rel="noopener">
                                                <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                                                Open Student View
                                            </a>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.status-filter').forEach(filterGroup => {
        const buttons = filterGroup.querySelectorAll('button');
        const targetId = filterGroup.getAttribute('data-target');
        const grid = document.querySelector(`.parent-course-grid[data-child="${targetId}"]`);

        if (!grid) {
            return;
        }

        buttons.forEach(button => {
            button.addEventListener('click', () => {
                buttons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');

                const filter = button.getAttribute('data-filter');
                grid.querySelectorAll('.parent-course-card').forEach(card => {
                    const status = card.getAttribute('data-status');
                    const shouldShow = filter === 'all' || status === filter;
                    card.style.display = shouldShow ? 'flex' : 'none';
                });
            });
        });
    });
});
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





