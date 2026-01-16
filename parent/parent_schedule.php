<?php
/**
 * Parent Dashboard - Weekly Timetable with Monthly Calendar
 * Professional schedule with monthly view and day-wise breakdown
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $SESSION;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

// ========================================
// PARENT ACCESS CONTROL
// ========================================
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
$system_context = context_system::instance();

$is_parent = false;
if ($parent_role) {
    $is_parent = user_has_role_assignment($USER->id, $parent_role->id, $system_context->id);
    
    if (!$is_parent) {
        $parent_assignments = $DB->get_records_sql(
            "SELECT ra.id 
             FROM {role_assignments} ra
             JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid = ?
             AND ra.roleid = ?
             AND ctx.contextlevel = ?",
            [$USER->id, $parent_role->id, CONTEXT_USER]
        );
        $is_parent = !empty($parent_assignments);
    }
}

if (!$is_parent) {
    redirect(
        new moodle_url('/my/'),
        'You do not have permission to access the parent dashboard.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_schedule.php');
$PAGE->set_title('Weekly Timetable');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get view, month and year from URL parameters
$view = optional_param('view', 'weekly', PARAM_ALPHA); // Default to weekly
$month = optional_param('month', date('n'), PARAM_INT);
$year = optional_param('year', date('Y'), PARAM_INT);
$week_offset = optional_param('week_offset', 0, PARAM_INT); // Week offset from current week
$embedded = optional_param('embedded', 0, PARAM_BOOL);

// Calculate week start date for weekly view (Monday of the week)
$current_week_start = strtotime('monday this week');
$week_start = strtotime(($week_offset > 0 ? '+' : '') . ($week_offset * 7) . ' days', $current_week_start);
$week_end = strtotime('+6 days', $week_start);

if ($embedded) {
    $PAGE->set_pagelayout('embedded');
    $PAGE->add_body_class('schedule-embedded');
}

// Get selected child info
$selected_child_name = '';
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    foreach ($children as $child) {
        if ($child['id'] == $selected_child) {
            $selected_child_name = $child['name'];
            break;
        }
    }
}

// Get all events and activities for the month
$events_by_date = [];
$total_events = 0;
$assignments_count = 0;
$quizzes_count = 0;
$lessons_count = 0;
$other_count = 0;

if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    // Get date range - use wider range to cover both month and week views
    $month_start = mktime(0, 0, 0, $month, 1, $year);
    $month_end = mktime(23, 59, 59, $month, date('t', $month_start), $year);
    
    // For weekly view, ensure we fetch events for the week range
    if ($view === 'weekly') {
        $query_start = min($month_start, $week_start);
        $query_end = max($month_end, $week_end);
    } else {
        $query_start = $month_start;
        $query_end = $month_end;
    }
    
    // Get enrolled courses
    $courses = enrol_get_users_courses($selected_child, true);
    
    foreach ($courses as $course) {
        // Get calendar events
        try {
            $sql_events = "SELECT e.*, c.fullname as coursename
                          FROM {event} e
                          LEFT JOIN {course} c ON c.id = e.courseid
                          WHERE e.courseid = :courseid
                          AND e.timestart BETWEEN :start AND :end
                          AND e.visible = 1
                          ORDER BY e.timestart ASC";
            
            $calendar_events = $DB->get_records_sql($sql_events, [
                'courseid' => $course->id,
                'start' => $query_start ?? $month_start,
                'end' => $query_end ?? $month_end
            ]);
            
            foreach ($calendar_events as $event) {
                $date_key = date('Y-m-d', $event->timestart);
                if (!isset($events_by_date[$date_key])) {
                    $events_by_date[$date_key] = [];
                }
                
                $url = '';
                if (!empty($event->id)) {
                    $url = (new moodle_url('/calendar/view.php', ['event' => $event->id]))->out(false);
                }
                
                $events_by_date[$date_key][] = [
                    'id' => $event->id,
                    'name' => $event->name,
                    'type' => 'event',
                    'time' => $event->timestart,
                    'duration' => $event->timeduration,
                    'course' => $course->fullname,
                    'description' => !empty($event->description) ? strip_tags($event->description) : '',
                    'url' => $url,
                    'icon' => 'calendar-day',
                    'color' => '#14b8a6'
                ];
                $total_events++;
                $other_count++;
            }
        } catch (Exception $e) {}
        
        // Get assignments
        try {
            $sql_assign = "SELECT a.id, a.name, a.duedate, a.intro, c.fullname as coursename, cm.id as cmid
                          FROM {assign} a
                          JOIN {course} c ON c.id = a.course
                          JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                          WHERE a.course = :courseid
                          AND a.duedate BETWEEN :start AND :end
                          AND a.duedate > 0
                          ORDER BY a.duedate ASC";
            
            $assignments = $DB->get_records_sql($sql_assign, [
                'courseid' => $course->id,
                'start' => $query_start ?? $month_start,
                'end' => $query_end ?? $month_end
            ]);
            
            foreach ($assignments as $assign) {
                $date_key = date('Y-m-d', $assign->duedate);
                if (!isset($events_by_date[$date_key])) {
                    $events_by_date[$date_key] = [];
                }
                
                $url = '';
                if (!empty($assign->cmid) && !empty($course->id) && !empty($selected_child)) {
                    $url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                        'cmid' => $assign->cmid,
                        'child' => $selected_child,
                        'courseid' => $course->id
                    ]))->out(false);
                }
                
                $events_by_date[$date_key][] = [
                    'id' => $assign->id,
                    'name' => $assign->name,
                    'type' => 'assignment',
                    'time' => $assign->duedate,
                    'course' => $course->fullname,
                    'description' => !empty($assign->intro) ? strip_tags($assign->intro) : '',
                    'url' => $url,
                    'icon' => 'file-alt',
                    'color' => '#f59e0b'
                ];
                $total_events++;
                $assignments_count++;
            }
        } catch (Exception $e) {}
        
        // Get quizzes
        try {
            $sql_quiz = "SELECT q.id, q.name, q.timeclose, q.intro, c.fullname as coursename, cm.id as cmid
                        FROM {quiz} q
                        JOIN {course} c ON c.id = q.course
                        JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')
                        WHERE q.course = :courseid
                        AND q.timeclose BETWEEN :start AND :end
                        AND q.timeclose > 0
                        ORDER BY q.timeclose ASC";
            
            $quizzes = $DB->get_records_sql($sql_quiz, [
                'courseid' => $course->id,
                'start' => $query_start ?? $month_start,
                'end' => $query_end ?? $month_end
            ]);
            
            foreach ($quizzes as $quiz) {
                $date_key = date('Y-m-d', $quiz->timeclose);
                if (!isset($events_by_date[$date_key])) {
                    $events_by_date[$date_key] = [];
                }
                
                $url = '';
                if (!empty($quiz->cmid) && !empty($course->id) && !empty($selected_child)) {
                    $url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                        'cmid' => $quiz->cmid,
                        'child' => $selected_child,
                        'courseid' => $course->id
                    ]))->out(false);
                }
                
                $events_by_date[$date_key][] = [
                    'id' => $quiz->id,
                    'name' => $quiz->name,
                    'type' => 'quiz',
                    'time' => $quiz->timeclose,
                    'course' => $course->fullname,
                    'description' => !empty($quiz->intro) ? strip_tags($quiz->intro) : '',
                    'url' => $url,
                    'icon' => 'clipboard-check',
                    'color' => '#8b5cf6'
                ];
                $total_events++;
                $quizzes_count++;
            }
        } catch (Exception $e) {}
        
        // Get lessons
        try {
            $sql_lesson = "SELECT l.id, l.name, l.deadline, l.intro, c.fullname as coursename, cm.id as cmid
                          FROM {lesson} l
                          JOIN {course} c ON c.id = l.course
                          JOIN {course_modules} cm ON cm.instance = l.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'lesson')
                          WHERE l.course = :courseid
                          AND l.deadline BETWEEN :start AND :end
                          AND l.deadline > 0
                          ORDER BY l.deadline ASC";
            
            $lessons = $DB->get_records_sql($sql_lesson, [
                'courseid' => $course->id,
                'start' => $month_start,
                'end' => $month_end
            ]);
            
            foreach ($lessons as $lesson) {
                $date_key = date('Y-m-d', $lesson->deadline);
                if (!isset($events_by_date[$date_key])) {
                    $events_by_date[$date_key] = [];
                }
                
                $url = '';
                if (!empty($lesson->cmid) && !empty($course->id) && !empty($selected_child)) {
                    $url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                        'cmid' => $lesson->cmid,
                        'child' => $selected_child,
                        'courseid' => $course->id
                    ]))->out(false);
                }
                
                $events_by_date[$date_key][] = [
                    'id' => $lesson->id,
                    'name' => $lesson->name,
                    'type' => 'lesson',
                    'time' => $lesson->deadline,
                    'course' => $course->fullname,
                    'description' => !empty($lesson->intro) ? strip_tags($lesson->intro) : '',
                    'url' => $url,
                    'icon' => 'book-reader',
                    'color' => '#10b981'
                ];
                $total_events++;
                $lessons_count++;
            }
        } catch (Exception $e) {}
    }
}

// Sort events by time within each date
foreach ($events_by_date as $date => &$events) {
    usort($events, function($a, $b) {
        return $a['time'] - $b['time'];
    });
}

echo $OUTPUT->header();

if (!$embedded) {
    include_once(__DIR__ . '/../components/parent_sidebar.php');
}
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
    --schedule-primary: #2563eb;
    --schedule-primary-dark: #1d4ed8;
    --schedule-surface: #ffffff;
    --schedule-muted: #64748b;
    --schedule-border: rgba(148, 163, 184, 0.16);
    --schedule-shadow: 0 18px 34px rgba(23, 37, 84, 0.12);
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

/* Enhanced Modern Schedule Page */
.parent-main-content.schedule-page {
    margin-left: 280px;
    padding: 30px 35px;
    min-height: 100vh;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Comprehensive Responsive Design */
@media (max-width: 1024px) {
    .parent-main-content.schedule-page {
        margin-left: 260px;
        width: calc(100% - 260px);
        padding: 24px 28px;
    }
}

@media (max-width: 768px) {
    .parent-main-content.schedule-page {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 16px !important;
    }
    
    /* Make all grids single column */
    [style*="grid-template-columns"],
    [style*="display: grid"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Stack flex containers */
    [style*="display: flex"] {
        flex-direction: column !important;
    }
    
    /* Make tables scrollable */
    table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust font sizes */
    h1, h2, h3 {
        font-size: 1.2em !important;
    }
}

@media (max-width: 480px) {
    .parent-main-content.schedule-page {
        padding: 12px !important;
    }
    
    body {
        font-size: 14px !important;
    }
}
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
    display: flex;
    flex-direction: column;
    gap: 24px;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    position: relative;
}

.parent-main-content.schedule-page::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 15% 25%, rgba(59, 130, 246, 0.04) 0%, transparent 50%),
        radial-gradient(circle at 85% 75%, rgba(139, 92, 246, 0.03) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

.schedule-embed-container {
    margin: 0;
    padding: 20px 0 24px;
    background: transparent;
    min-height: auto;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.schedule-embedded #page {
    background: transparent;
}

.schedule-embedded .schedule-hero {
    border-radius: 20px;
}

.schedule-embedded .schedule-hero::after {
    opacity: 0.25;
}

.schedule-embedded .schedule-calendar {
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}

/* Enhanced Hero Section */
.schedule-hero {
    display: flex;
    gap: 24px;
    align-items: center;
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    border-radius: 24px;
    padding: 36px 40px;
    position: relative;
    overflow: hidden;
    color: #ffffff;
    box-shadow: 0 20px 60px rgba(59, 130, 246, 0.3), 0 8px 24px rgba(0, 0, 0, 0.15);
    z-index: 1;
}

.schedule-hero::before {
    content: "";
    position: absolute;
    top: -50%;
    right: -50%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(60px);
}

.schedule-hero::after {
    content: "";
    position: absolute;
    inset: -40% auto auto 55%;
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    transform: rotate(18deg);
    filter: blur(40px);
}

.schedule-hero__icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    display: grid;
    place-items: center;
    font-size: 36px;
    z-index: 1;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.schedule-hero__text {
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.schedule-hero__title {
    margin: 0;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.schedule-hero__subtitle {
    margin: 0;
    font-size: 16px;
    color: rgba(255, 255, 255, 0.95);
    font-weight: 500;
    line-height: 1.6;
    max-width: 600px;
}

/* Enhanced Context Badge */
.schedule-context {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border-radius: 16px;
    padding: 12px 22px;
    border: 2px solid rgba(59, 130, 246, 0.3);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
    width: fit-content;
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
}

.schedule-context:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: rgba(59, 130, 246, 0.5);
}

.schedule-context__label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    font-weight: 700;
    color: #3b82f6;
}

.schedule-context__value {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.2px;
}

.schedule-context__switch {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 10px;
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--schedule-primary);
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
}

.schedule-context__switch:hover {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    transform: rotate(90deg);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

/* Enhanced Statistics Grid */
.schedule-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    position: relative;
    z-index: 1;
}

.schedule-stat-card {
    --accent-color: var(--schedule-primary);
    --accent-soft: rgba(37, 99, 235, 0.12);
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 24px 22px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 18px;
    position: relative;
    isolation: isolate;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.schedule-stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--accent-color), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.schedule-stat-card::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: -1;
}

.schedule-stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
}

.schedule-stat-card:hover::before,
.schedule-stat-card:hover::after {
    opacity: 1;
}

.schedule-stat-card__icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--accent-soft), rgba(37, 99, 235, 0.08));
    display: grid;
    place-items: center;
    font-size: 24px;
    color: var(--accent-color);
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.schedule-stat-card:hover .schedule-stat-card__icon {
    transform: scale(1.1);
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(37, 99, 235, 0.12));
}

.schedule-stat-card__value {
    font-size: 32px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -1px;
    line-height: 1;
}

.schedule-stat-card__label {
    margin: 6px 0 0;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #64748b;
    font-weight: 700;
}

.schedule-month {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.schedule-month__title {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.schedule-month__actions {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

/* Enhanced Month Navigation Buttons */
.month-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border: 2px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    padding: 12px 20px;
    color: #0f172a;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.month-nav-btn:hover {
    transform: translateY(-3px);
    border-color: rgba(59, 130, 246, 0.5);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.2), 0 2px 8px rgba(0, 0, 0, 0.1);
    background: linear-gradient(135deg, #ffffff, #eff6ff);
}

/* Enhanced Calendar */
.schedule-calendar {
    position: relative;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08), 0 2px 8px rgba(0, 0, 0, 0.06);
    padding: 32px 36px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    overflow: hidden;
    z-index: 1;
}

.schedule-calendar::before {
    content: "";
    position: absolute;
    inset: -20% 45% auto -15%;
    height: 320px;
    background: radial-gradient(circle at center, rgba(59, 130, 246, 0.16) 0%, transparent 70%);
    filter: blur(6px);
}

.schedule-calendar::after {
    content: "";
    position: absolute;
    inset: auto -30% -60% 60%;
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, transparent 70%);
}

.calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.calendar-legend__item {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
}

.calendar-legend__swatch {
    width: 34px;
    height: 34px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    background: rgba(37, 99, 235, 0.12);
    color: var(--schedule-primary);
    font-weight: 700;
    border: 1px solid rgba(15, 23, 42, 0.06);
}

.calendar-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 14px;
    position: relative;
    z-index: 1;
}

.calendar-weekday {
    text-align: center;
    font-weight: 700;
    color: var(--schedule-muted);
    letter-spacing: 0.1em;
    font-size: 12px;
    text-transform: uppercase;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 14px;
    position: relative;
    z-index: 1;
}

/* Enhanced Calendar Cells */
.calendar-cell,
.calendar-cell--muted {
    position: relative;
    border-radius: 16px;
    border: 2px solid rgba(226, 232, 240, 0.8);
    padding: 16px 14px 18px;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.calendar-cell {
    cursor: pointer;
}

.calendar-cell:hover {
    transform: translateY(-6px);
    border-color: rgba(59, 130, 246, 0.5);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
    background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%);
}

.calendar-cell--today {
    border: 3px solid #3b82f6;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(255, 255, 255, 0.95));
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.25), inset 0 0 0 1px rgba(59, 130, 246, 0.1);
}

.calendar-cell--active::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(150deg, rgba(37, 99, 235, 0.06), transparent 65%);
    pointer-events: none;
}

.calendar-cell--muted {
    background: rgba(241, 245, 249, 0.75);
    color: #cbd5f5;
    border-style: dashed;
}

.calendar-cell__day {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
}

.calendar-cell--muted .calendar-cell__day {
    color: rgba(15, 23, 42, 0.35);
}

.calendar-cell__badges {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.calendar-badge {
    width: 26px;
    height: 26px;
    border-radius: 10px;
    background: var(--badge-color, rgba(37, 99, 235, 0.16));
    display: grid;
    place-items: center;
    color: rgba(15, 23, 42, 0.85);
    font-size: 12px;
    box-shadow: 0 3px 8px rgba(15, 23, 42, 0.12);
}

.calendar-cell__count {
    position: absolute;
    right: 16px;
    bottom: 16px;
    font-size: 12px;
    font-weight: 700;
    color: #ffffff;
    background: var(--badge-color, var(--schedule-primary));
    border-radius: 999px;
    padding: 5px 12px;
    letter-spacing: 0.06em;
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.22);
}

.day-details-panel {
    position: fixed;
    top: 0;
    right: -420px;
    width: 380px;
    height: 100vh;
    background: #f4f6fb;
    box-shadow: -24px 0 48px rgba(15, 23, 42, 0.18);
    border-left: 1px solid var(--schedule-border);
    transition: right 0.35s ease;
    display: flex;
    flex-direction: column;
    z-index: 20;
}

.day-details-panel.active {
    right: 0;
}

.day-details-header {
    margin-top: 20px;
    padding: 24px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
}

.day-details-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    gap: 10px;
    align-items: center;
}

.close-details-btn {
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: #ffffff;
    border-radius: 10px;
    padding: 6px 12px;
    color: #475569;
    font-weight: 600;
    display: inline-flex;
    gap: 6px;
    align-items: center;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
}

.close-details-btn:hover {
    background: rgba(37, 99, 235, 0.08);
    color: var(--schedule-primary);
}

.activity-list {
    padding: 20px 24px 28px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* Enhanced Activity Items */
.activity-item {
    position: relative;
    border-radius: 20px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 24px 24px 26px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08), 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.activity-item::before {
    content: "";
    position: absolute;
    inset: 20px auto 20px 0;
    width: 5px;
    border-radius: 999px;
    background: linear-gradient(180deg, var(--accent, var(--schedule-primary)), rgba(59, 130, 246, 0.6));
    opacity: 0.9;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.activity-item:hover {
    transform: translateY(-6px);
    border-color: rgba(59, 130, 246, 0.5);
    box-shadow: 0 16px 48px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
}

.activity-item:hover::before {
    width: 6px;
    opacity: 1;
}

.activity-header {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

/* Enhanced Activity Icon Badge */
.activity-icon-badge {
    width: 60px;
    height: 60px;
    border-radius: 18px;
    display: grid;
    place-items: center;
    color: #ffffff;
    font-size: 24px;
    background: linear-gradient(135deg, var(--accent, var(--schedule-primary)), rgba(59, 130, 246, 0.8));
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3), 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.activity-item:hover .activity-icon-badge {
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.4), 0 4px 12px rgba(0, 0, 0, 0.2);
}

.activity-info h4 {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    color: #0f172a;
}

.activity-info .activity-meta {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.activity-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: rgba(15, 23, 42, 0.02);
    font-weight: 600;
    font-size: 13px;
    color: #1f2937;
    letter-spacing: 0.01em;
}

.activity-pill--accent {
    border-color: var(--accent, var(--schedule-primary));
    background: rgba(37, 99, 235, 0.08);
    color: var(--accent, var(--schedule-primary));
}

.activity-description {
    padding: 16px;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: rgba(15, 23, 42, 0.03);
    color: #475569;
    line-height: 1.6;
    font-size: 14px;
}

.activity-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
}

.activity-actions a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    color: #ffffff;
    background: var(--accent, var(--schedule-primary));
    box-shadow: 0 22px 32px rgba(37, 99, 235, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.activity-actions a:hover {
    transform: translateY(-2px);
    box-shadow: 0 28px 40px rgba(37, 99, 235, 0.28);
}

.schedule-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.28);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.35s ease;
    z-index: 15;
}

.schedule-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

.week-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 18px;
}

/* Enhanced Week Cards */
.week-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 18px;
    border-left: 4px solid rgba(148, 163, 184, 0.25);
    padding: 20px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.1);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.week-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.5), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.week-card:hover {
    transform: translateY(-6px);
    border-left-color: rgba(59, 130, 246, 0.6);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
}

.week-card:hover::before {
    opacity: 1;
}

.week-card__name {
    font-weight: 700;
    color: #0f172a;
}

.week-card__date {
    font-size: 22px;
    font-weight: 800;
    color: var(--schedule-primary);
}

.week-card__count {
    font-size: 12px;
    font-weight: 600;
    color: var(--schedule-muted);
}

.week-card__today {
    margin-top: 6px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.12);
    color: var(--schedule-primary);
    font-size: 11px;
    font-weight: 700;
}

.week-card__swatches {
    display: inline-flex;
    justify-content: center;
    gap: 4px;
}

.week-card__swatch {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(15, 23, 42, 0.2);
}

.empty-state {
    background: var(--schedule-surface);
    border-radius: 26px;
    padding: 80px 40px;
    text-align: center;
    border: 1px solid var(--schedule-border);
    box-shadow: var(--schedule-shadow);
}

.empty-state__icon {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: rgba(37, 99, 235, 0.12);
    color: var(--schedule-primary);
    display: grid;
    place-items: center;
    font-size: 28px;
    margin: 0 auto 18px;
}

.empty-state__title {
    margin: 0 0 10px;
    font-size: 22px;
    font-weight: 800;
}

.empty-state__text {
    margin: 0;
    color: var(--schedule-muted);
}

.empty-state__cta {
    margin-top: 28px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    background: linear-gradient(135deg, var(--schedule-primary), var(--schedule-primary-dark));
    border-radius: 14px;
    color: #ffffff;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 18px 28px rgba(37, 99, 235, 0.28);
}

/* Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.schedule-hero {
    animation: fadeIn 0.6s ease-out;
}

.schedule-stat-card {
    animation: scaleIn 0.5s ease-out;
}

.activity-item {
    animation: slideIn 0.4s ease-out;
}

.calendar-cell {
    animation: scaleIn 0.3s ease-out;
}

@media (max-width: 1024px) {
    .parent-main-content.schedule-page {
        margin-left: 0;
        margin-top: 52px;
        padding: 28px 22px 48px;
    }

    .schedule-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .schedule-context {
        border-radius: 18px;
    }
}
</style>

<?php $schedulewrapperclass = $embedded ? 'schedule-embed-container' : 'parent-main-content schedule-page'; ?>

<div class="<?php echo $schedulewrapperclass; ?>">
    <?php if (!$embedded): ?>
    <section class="schedule-hero">
        <div class="schedule-hero__icon">
            <i class="fas fa-calendar-week"></i>
        </div>
        <div class="schedule-hero__text">
            <h1 class="schedule-hero__title">Weekly Timetable</h1>
            <p class="schedule-hero__subtitle">
                <?php echo $view === 'weekly' ? 'Weekly' : 'Monthly'; ?> calendar view with day-wise activity breakdown for <?php echo htmlspecialchars($selected_child_name ?: 'your child'); ?>
            </p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($selected_child && $selected_child !== 'all' && $selected_child != 0): ?>
        <?php if (!$embedded): ?>
        <?php
            $statcards = [
                [
                    'label' => 'Total Events',
                    'value' => $total_events,
                    'icon' => 'calendar-alt',
                    'accent' => '#2563eb',
                    'soft' => 'rgba(37, 99, 235, 0.15)'
                ],
                [
                    'label' => 'Assignments',
                    'value' => $assignments_count,
                    'icon' => 'file-alt',
                    'accent' => '#f59e0b',
                    'soft' => 'rgba(245, 158, 11, 0.15)'
                ],
                [
                    'label' => 'Quizzes',
                    'value' => $quizzes_count,
                    'icon' => 'clipboard-check',
                    'accent' => '#8b5cf6',
                    'soft' => 'rgba(139, 92, 246, 0.15)'
                ],
                [
                    'label' => 'Lessons',
                    'value' => $lessons_count,
                    'icon' => 'book-reader',
                    'accent' => '#10b981',
                    'soft' => 'rgba(16, 185, 129, 0.15)'
                ],
                [
                    'label' => 'Other Events',
                    'value' => $other_count,
                    'icon' => 'calendar-day',
                    'accent' => '#14b8a6',
                    'soft' => 'rgba(20, 184, 166, 0.15)'
                ],
            ];
        ?>

        <div class="schedule-context">
            <span class="schedule-context__label">Viewing</span>
            <span class="schedule-context__value"><?php echo htmlspecialchars($selected_child_name); ?></span>
            <a class="schedule-context__switch" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" title="Change child">
                <i class="fas fa-sync-alt"></i>
            </a>
        </div>

        <div class="schedule-stats">
            <?php foreach ($statcards as $card): ?>
                <article class="schedule-stat-card" style="--accent-color: <?php echo $card['accent']; ?>; --accent-soft: <?php echo $card['soft']; ?>;">
                    <div class="schedule-stat-card__icon">
                        <i class="fas fa-<?php echo $card['icon']; ?>"></i>
                    </div>
                    <div>
                        <div class="schedule-stat-card__value"><?php echo number_format($card['value']); ?></div>
                        <p class="schedule-stat-card__label"><?php echo $card['label']; ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="schedule-month">
            <h2 class="schedule-month__title">
                <i class="fas fa-<?php echo $view === 'weekly' ? 'calendar-week' : 'calendar'; ?>"></i>
                <?php if ($view === 'weekly'): ?>
                    Week of <?php echo date('M d', $week_start); ?> - <?php echo date('M d, Y', $week_end); ?>
                <?php else: ?>
                <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                <?php endif; ?>
            </h2>
            <div class="schedule-month__actions">
                <!-- View Toggle Buttons -->
                <div style="display: inline-flex; gap: 6px; margin-right: 8px; border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 6px; padding: 2px; background: #f8fafc;">
                    <a href="?view=weekly&week_offset=0" 
                       class="month-nav-btn" 
                       style="padding: 6px 12px; <?php echo $view === 'weekly' ? 'background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border-color: #3b82f6;' : ''; ?>">
                        <i class="fas fa-calendar-week"></i> Weekly
                    </a>
                    <a href="?view=monthly&month=<?php echo $month; ?>&year=<?php echo $year; ?>" 
                       class="month-nav-btn" 
                       style="padding: 6px 12px; <?php echo $view === 'monthly' ? 'background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border-color: #3b82f6;' : ''; ?>">
                        <i class="fas fa-calendar"></i> Monthly
                    </a>
                </div>
                
                <?php if ($view === 'weekly'): ?>
                    <a href="?view=weekly&week_offset=<?php echo $week_offset - 1; ?>" class="month-nav-btn">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                    <a href="?view=weekly&week_offset=0" class="month-nav-btn">
                        <i class="fas fa-calendar-day"></i> This Week
                    </a>
                    <a href="?view=weekly&week_offset=<?php echo $week_offset + 1; ?>" class="month-nav-btn">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <a href="?view=monthly&month=<?php echo ($month == 1 ? 12 : $month - 1); ?>&year=<?php echo ($month == 1 ? $year - 1 : $year); ?>" class="month-nav-btn">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <a href="?view=monthly&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="month-nav-btn">
                    <i class="fas fa-calendar-day"></i> Today
                </a>
                    <a href="?view=monthly&month=<?php echo ($month == 12 ? 1 : $month + 1); ?>&year=<?php echo ($month == 12 ? $year + 1 : $year); ?>" class="month-nav-btn">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php
            $legend = [
                ['icon' => 'calendar-alt', 'label' => 'Calendar event', 'bg' => 'rgba(20, 184, 166, 0.15)', 'color' => '#0f766e'],
                ['icon' => 'tasks', 'label' => 'Assignment due', 'bg' => 'rgba(245, 158, 11, 0.15)', 'color' => '#b45309'],
                ['icon' => 'clipboard-list', 'label' => 'Quiz closing', 'bg' => 'rgba(139, 92, 246, 0.18)', 'color' => '#6d28d9'],
                ['icon' => 'book', 'label' => 'Lesson deadline', 'bg' => 'rgba(16, 185, 129, 0.17)', 'color' => '#047857'],
            ];
        ?>

        <!-- Weekly View -->
        <?php if ($view === 'weekly'): ?>
        <section class="schedule-calendar" aria-label="Weekly calendar" style="display: block;">
            <div class="calendar-legend">
                <?php foreach ($legend as $item): ?>
                    <span class="calendar-legend__item">
                        <span class="calendar-legend__swatch" style="background: <?php echo $item['bg']; ?>; color: <?php echo $item['color']; ?>;">
                            <i class="fas fa-<?php echo $item['icon']; ?>"></i>
                        </span>
                        <?php echo $item['label']; ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="calendar-header">
                <?php $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; ?>
                <?php foreach ($day_names as $day_name): ?>
                    <span class="calendar-weekday"><?php echo $day_name; ?></span>
                <?php endforeach; ?>
            </div>

            <div class="calendar-grid">
                <?php
                    for ($i = 0; $i < 7; $i++) {
                        $current_day = strtotime("+$i days", $week_start);
                        $date_key = date('Y-m-d', $current_day);
                        $is_today = (date('Y-m-d') === $date_key);
                        $has_events = isset($events_by_date[$date_key]);
                        $event_count = $has_events ? count($events_by_date[$date_key]) : 0;

                        $cell_classes = ['calendar-cell'];
                        if ($is_today) {
                            $cell_classes[] = 'calendar-cell--today';
                        }
                        if ($has_events) {
                            $cell_classes[] = 'calendar-cell--active';
                        }
                        $cell_class_attr = implode(' ', $cell_classes);

                        echo '<button type="button" class="' . $cell_class_attr . '" onclick="showDayDetails(\'' . $date_key . '\')">';
                        echo '<span class="calendar-cell__day">' . date('d', $current_day) . '</span>';
                        echo '<span style="font-size: 10px; color: #64748b; margin-bottom: 4px;">' . date('M', $current_day) . '</span>';

                        if ($has_events) {
                            echo '<span class="calendar-cell__badges">';
                            $types_shown = [];
                            foreach ($events_by_date[$date_key] as $evt) {
                                if (count($types_shown) >= 6) {
                                    break;
                                }
                                if (!in_array($evt['type'], $types_shown)) {
                                    $types_shown[] = $evt['type'];
                                    $badge_color = $evt['color'] ?? '#3b82f6';
                                    echo '<span class="calendar-badge" style="--badge-color: ' . $badge_color . '; background: ' . $badge_color . '20; color: ' . $badge_color . ';" title="' . htmlspecialchars($evt['name']) . '">';
                                    echo '<i class="fas fa-' . $evt['icon'] . '"></i>';
                                    echo '</span>';
                                }
                            }
                            echo '</span>';
                        }

                        if ($event_count > 0) {
                            echo '<span class="calendar-cell__count" style="--badge-color: ' . ($has_events ? ($events_by_date[$date_key][0]['color'] ?? '#3b82f6') : '#3b82f6') . ';">' . $event_count . '</span>';
                        }

                        echo '</button>';
                    }
                ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Monthly View -->
        <section class="schedule-calendar" aria-label="Monthly calendar" style="display: <?php echo $view === 'monthly' ? 'block' : 'none'; ?>;">
            <div class="calendar-legend">
                <?php foreach ($legend as $item): ?>
                    <span class="calendar-legend__item">
                        <span class="calendar-legend__swatch" style="background: <?php echo $item['bg']; ?>; color: <?php echo $item['color']; ?>;">
                            <i class="fas fa-<?php echo $item['icon']; ?>"></i>
                        </span>
                        <?php echo $item['label']; ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="calendar-header">
                <?php $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; ?>
                <?php foreach ($day_names as $day_name): ?>
                    <span class="calendar-weekday"><?php echo $day_name; ?></span>
                <?php endforeach; ?>
            </div>

            <div class="calendar-grid">
                <?php
                    $first_day = mktime(0, 0, 0, $month, 1, $year);
                    $days_in_month = date('t', $first_day);
                    $first_day_of_week = date('w', $first_day);
                    $prev_month_days = date('t', mktime(0, 0, 0, $month - 1, 1, $year));

                    for ($i = $first_day_of_week - 1; $i >= 0; $i--) {
                        $day_num = $prev_month_days - $i;
                        echo '<div class="calendar-cell--muted"><span class="calendar-cell__day">' . $day_num . '</span></div>';
                    }

                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $date_key = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = (date('Y-m-d') === $date_key);
                        $has_events = isset($events_by_date[$date_key]);
                        $event_count = $has_events ? count($events_by_date[$date_key]) : 0;

                        $cell_classes = ['calendar-cell'];
                        if ($is_today) {
                            $cell_classes[] = 'calendar-cell--today';
                        }
                        if ($has_events) {
                            $cell_classes[] = 'calendar-cell--active';
                        }
                        $cell_class_attr = implode(' ', $cell_classes);

                        echo '<button type="button" class="' . $cell_class_attr . '" onclick="showDayDetails(\'' . $date_key . '\')">';
                        echo '<span class="calendar-cell__day">' . $day . '</span>';

                        if ($has_events) {
                            echo '<span class="calendar-cell__badges">';
                            $types_shown = [];
                            foreach ($events_by_date[$date_key] as $evt) {
                                if (count($types_shown) >= 4) {
                                    break;
                                }
                                if (!in_array($evt['type'], $types_shown)) {
                                    $icon_map = [
                                        'assignment' => 'tasks',
                                        'quiz' => 'clipboard-list',
                                        'lesson' => 'book',
                                        'event' => 'calendar-alt',
                                    ];
                                    $icon = $icon_map[$evt['type']] ?? 'circle';
                                    echo '<span class="calendar-badge" style="--badge-color: ' . $evt['color'] . '; border: 1px solid rgba(15,23,42,0.08);"><i class="fas fa-' . $icon . '"></i></span>';
                                    $types_shown[] = $evt['type'];
                                }
                            }
                            echo '</span>';
                        }

                        if ($event_count > 0) {
                            $primary_color = '#2563eb';
                            if (!empty($events_by_date[$date_key])) {
                                $primary_color = $events_by_date[$date_key][0]['color'];
                            }
                            echo '<span class="calendar-cell__count" style="--badge-color: ' . $primary_color . ';">' . $event_count . '</span>';
                        }

                        echo '</button>';
                    }

                    $remaining_days = 42 - ($first_day_of_week + $days_in_month);
                    for ($i = 1; $i <= $remaining_days; $i++) {
                        echo '<div class="calendar-cell--muted"><span class="calendar-cell__day">' . $i . '</span></div>';
                    }
                ?>
            </div>
        </section>

        <div id="dayDetailsPanel" class="day-details-panel" aria-live="polite">
            <div class="day-details-header">
                <h3 class="day-details-title" id="dayDetailsTitle">
                    <i class="fas fa-calendar-day"></i>
                    <span id="selectedDate"></span>
                </h3>
                <div style="display:flex; gap:8px;">
                    <button onclick="closeDayDetails()" class="close-details-btn" title="Close panel">
                        <i class="fas fa-times"></i>
                        Close
                    </button>
                    <button onclick="closeDayDetails(true)" class="close-details-btn" title="Return to calendar">
                        <i class="fas fa-undo"></i>
                        Undo
                    </button>
                </div>
            </div>
            <div id="dayActivitiesList" class="activity-list"></div>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <span class="empty-state__icon"><i class="fas fa-calendar-times"></i></span>
            <h3 class="empty-state__title">No child selected</h3>
            <p class="empty-state__text">Choose a linked student to explore their schedule and upcoming activities.</p>
            <a class="empty-state__cta" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php">
                <i class="fas fa-user-check"></i>
                Select child
            </a>
        </div>
    <?php endif; ?>
</div>
<div id="scheduleOverlay" class="schedule-overlay"></div>

<script>
// Store events data for JavaScript access
const eventsData = <?php echo json_encode($events_by_date); ?>;

function showDayDetails(dateKey) {
    const panel = document.getElementById('dayDetailsPanel');
    const overlay = document.getElementById('scheduleOverlay');
    const dateTitle = document.getElementById('selectedDate');
    const activitiesList = document.getElementById('dayActivitiesList');
    
    // Parse date
    const dateParts = dateKey.split('-');
    const dateObj = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
    const dateFormatted = dateObj.toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    dateTitle.textContent = dateFormatted;
    
    // Load activities
    if (eventsData[dateKey] && eventsData[dateKey].length > 0) {
        activitiesList.innerHTML = '';
        
        eventsData[dateKey].forEach(activity => {
            const time = new Date(activity.time * 1000);
            const timeStr = time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            const dateStr = time.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            
            // Format duration for events
            let durationStr = '';
            if (activity.type === 'event' && activity.duration) {
                const hours = Math.floor(activity.duration / 3600);
                const minutes = Math.floor((activity.duration % 3600) / 60);
                if (hours > 0) {
                    durationStr = `${hours}h ${minutes > 0 ? minutes + 'm' : ''}`;
                } else if (minutes > 0) {
                    durationStr = `${minutes}m`;
                }
            }
            
            // Determine label based on type
            let timeLabel = activity.type === 'assignment' || activity.type === 'quiz' || activity.type === 'lesson' ? 'Due' : 'Time';
            
            // Create color with opacity for background
            const colorRgba = hexToRgba(activity.color, 0.08);
            const colorRgbaHover = hexToRgba(activity.color, 0.15);
            
            const tagPill = `<span class="activity-pill activity-pill--accent" style="color:${activity.color}; border-color:${activity.color}; background:${hexToRgba(activity.color, 0.12)};"><i class="fas fa-${activity.icon}"></i>${activity.type}</span>`;
            const timePill = `<span class="activity-pill"><i class="fas fa-clock"></i> ${timeLabel}: ${timeStr}</span>`;
            const datePill = `<span class="activity-pill"><i class="fas fa-calendar"></i> ${dateStr}</span>`;
            const coursePill = `<span class="activity-pill"><i class="fas fa-book"></i> ${escapeHtml(activity.course)}</span>`;
            const durationPill = durationStr ? `<span class="activity-pill"><i class="fas fa-hourglass-half"></i> ${durationStr}</span>` : '';
            const descriptionBlock = activity.description
                ? `<div class="activity-description"><strong style="display:flex;align-items:center;gap:6px;color:${activity.color};font-size:12px;text-transform:uppercase;letter-spacing:0.08em;"><i class="fas fa-info-circle"></i>Details</strong><p style="margin:8px 0 0;">${escapeHtml(activity.description.substring(0, 220))}${activity.description.length > 220 ? 'â€¦' : ''}</p></div>`
                : '';
            const actionLink = activity.url
                ? `<div class="activity-actions"><a href="${activity.url}" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i>${activity.type === 'assignment' ? 'View assignment' : (activity.type === 'quiz' ? 'Open quiz' : 'View event')}</a></div>`
                : '';

            activitiesList.innerHTML += `
                <article class="activity-item" style="--accent:${activity.color};">
                    <div class="activity-header">
                        <span class="activity-icon-badge" style="background:${activity.color};"><i class="fas fa-${activity.icon}"></i></span>
                        <div class="activity-info">
                            <h4>${escapeHtml(activity.name)}</h4>
                            <div class="activity-meta">
                                ${tagPill}
                                ${timePill}
                                ${datePill}
                                ${durationPill}
                                ${coursePill}
                            </div>
                            ${descriptionBlock}
                            ${actionLink}
                        </div>
                    </div>
                </article>
            `;
        });
    } else {
        activitiesList.innerHTML = `
            <div style="text-align: center; padding: 60px 20px; color: #9ca3af;">
                <i class="fas fa-calendar-times" style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;"></i>
                <h3 style="color: #6b7280; margin: 0 0 10px 0;">No Activities Scheduled</h3>
                <p style="margin: 0; font-size: 15px;">This day has no scheduled activities or deadlines.</p>
            </div>
        `;
    }
    
    panel.classList.add('active');
    overlay.classList.add('active');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeDayDetails(undo = false) {
    const panel = document.getElementById('dayDetailsPanel');
    const overlay = document.getElementById('scheduleOverlay');
    panel.classList.remove('active');
    overlay.classList.remove('active');
    if (undo) {
        document.querySelector('.schedule-calendar').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to convert hex color to rgba
function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

// Helper function to adjust color brightness
function adjustColor(color, amount) {
    const num = parseInt(color.replace('#', ''), 16);
    const r = Math.max(0, Math.min(255, (num >> 16) + amount));
    const g = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amount));
    const b = Math.max(0, Math.min(255, (num & 0x0000FF) + amount));
    return '#' + (0x1000000 + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

// Add print styles
const printStyles = `
    @media print {
        .parent-sidebar, .month-nav-buttons, .close-details-btn, .timetable-header p { 
            display: none !important; 
        }
        .parent-main-content { 
            margin-left: 0 !important; 
        }
        .calendar-day:hover {
            transform: none !important;
    }
}
`;
const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeDayDetails();
    }
});

document.addEventListener('click', (event) => {
    const panel = document.getElementById('dayDetailsPanel');
    if (!panel.classList.contains('active')) {
        return;
    }
    const clickedInsidePanel = panel.contains(event.target);
    const clickedCalendarCell = event.target.closest('.calendar-cell');
    if (!clickedInsidePanel && !clickedCalendarCell) {
        closeDayDetails();
    }
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

<?php echo $OUTPUT->footer(); ?>




