<?php
/**
 * Teacher Schedule Page - Full Calendar View
 * Displays teacher's schedule with calendar events, assignments, quizzes, and sessions
 * 
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

// Require login
require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/schedule.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Schedule');
$PAGE->set_heading('My Schedule');

// Set active page for sidebar highlighting
$currentpage = ['schedule' => true];

// Get view parameter (week, month, list)
$view = optional_param('view', 'week', PARAM_ALPHA);
$week_offset = optional_param('weekoffset', 0, PARAM_INT);

// Debug - log what we received
error_log("SCHEDULE PAGE LOAD: view=$view, weekoffset=$week_offset");

// Get user's enrolled courses
$courses = enrol_get_all_users_courses($USER->id, true);
$courseids = array_keys($courses);

if (empty($courseids)) {
    // No courses, redirect to dashboard
    redirect(new moodle_url('/my/'), 'You are not enrolled in any courses.', null, \core\output\notification::NOTIFY_INFO);
}

// Calculate date ranges based on view
$now = time();

if ($view === 'week') {
    // Week view - offset by weeks
    $start_date = strtotime("monday this week", $now) + ($week_offset * 7 * 24 * 60 * 60);
    $end_date = $start_date + (7 * 24 * 60 * 60);
} elseif ($view === 'month') {
    // Month view - offset by months
    $base_date = strtotime("first day of this month", $now);
    if ($week_offset != 0) {
        $base_date = strtotime($week_offset . " months", $base_date);
    }
    $start_date = $base_date;
    $end_date = strtotime("last day of this month", $base_date) + (24 * 60 * 60);
} else {
    // List view - offset by weeks (same as week view time range)
    $base_date = $now + ($week_offset * 7 * 24 * 60 * 60);
    $start_date = $base_date;
    $end_date = $base_date + (30 * 24 * 60 * 60);
}

// Get calendar events using Moodle's API
$calendar_events = calendar_get_events($start_date, $end_date, true, true, true, $courseids);

// Get school admin calendar events for this teacher
$admin_events = theme_remui_kids_get_school_admin_calendar_events($USER->id, $start_date, $end_date);

// Get teacher's company
$teacher_company_id = 0;
if ($DB->get_manager()->table_exists('company_users')) {
    $company_user = $DB->get_record('company_users', ['userid' => $USER->id], 'companyid');
    if ($company_user) {
        $teacher_company_id = $company_user->companyid;
    }
}

// Get lecture sessions for this teacher (only from their company)
$lecture_sessions = [];
if ($DB->get_manager()->table_exists('theme_remui_kids_lecture_sessions') && $teacher_company_id > 0) {
    $session_end = $end_date + (24 * 60 * 60); // Include end date
    
    $lecture_sessions = $DB->get_records_sql(
        "SELECT ls.*, c.fullname as course_name
         FROM {theme_remui_kids_lecture_sessions} ls
         JOIN {course} c ON ls.courseid = c.id
         INNER JOIN {theme_remui_kids_lecture_schedules} s ON ls.scheduleid = s.id
         WHERE ls.teacherid = :teacherid
         AND s.companyid = :companyid
         AND ls.sessiondate >= :start_date
         AND ls.sessiondate <= :end_date
         ORDER BY ls.sessiondate ASC, ls.starttime ASC",
        [
            'teacherid' => $USER->id,
            'companyid' => $teacher_company_id,
            'start_date' => $start_date,
            'end_date' => $session_end
        ]
    );
}

// Get assignments
$assignments = [];
if (!empty($courseids)) {
    list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $params['start'] = $start_date;
    $params['end'] = $end_date;
    
    $assignments = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, a.course, a.intro,
                c.fullname as coursename, cm.id as cmid
         FROM {assign} a
         JOIN {course} c ON a.course = c.id
         JOIN {course_modules} cm ON cm.instance = a.id
         JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
         WHERE a.course $courseids_sql
         AND a.duedate > :start
         AND a.duedate <= :end
         AND cm.visible = 1
         AND cm.deletioninprogress = 0
         ORDER BY a.duedate ASC",
        $params
    );
}

// Get quizzes
$quizzes = [];
if (!empty($courseids)) {
    list($courseids_sql2, $params2) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $params2['start'] = $start_date;
    $params2['end'] = $end_date;
    
    $quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, q.timeclose, q.course, q.intro,
                c.fullname as coursename, cm.id as cmid
         FROM {quiz} q
         JOIN {course} c ON q.course = c.id
         JOIN {course_modules} cm ON cm.instance = q.id
         JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
         WHERE q.course $courseids_sql2
         AND q.timeclose > :start
         AND q.timeclose <= :end
         AND cm.visible = 1
         AND cm.deletioninprogress = 0
         ORDER BY q.timeclose ASC",
        $params2
    );
}

// Combine all events
$all_events = [];

// Add school admin calendar events first
foreach ($admin_events as $event) {
    // Get icon based on event type
    $event_type = $event->eventtype ?? 'meeting';
    $event_icon = 'fa-calendar-check';
    switch ($event_type) {
        case 'meeting':
            $event_icon = 'fa-users';
            break;
        case 'lecture':
            $event_icon = 'fa-chalkboard-teacher';
            break;
        case 'exam':
            $event_icon = 'fa-file-alt';
            break;
        case 'activity':
            $event_icon = 'fa-running';
            break;
        default:
            $event_icon = 'fa-calendar-check';
    }
    
    // Map color from database to display color
    $color_value = $event->color ?? 'blue';
    $color_map = [
        'red' => '#ef4444',
        'green' => '#10b981',
        'blue' => '#3b82f6',
        'yellow' => '#fbbf24',
        'orange' => '#f59e0b',
        'purple' => '#8b5cf6'
    ];
    $display_color = isset($color_map[strtolower($color_value)]) ? $color_map[strtolower($color_value)] : $color_value;
    
    // Format time in 12-hour format directly from stored time string (not from timestamp)
    // This ensures the exact time entered by the user is displayed, regardless of timezone
    $time_formatted = '';
    $time_end_formatted = '';
    if (isset($event->starttime) && !empty($event->starttime)) {
        // Use helper function to convert 24-hour time string to 12-hour format
        // lib.php is already required at the top of this file
        if (function_exists('theme_remui_kids_convert24To12Hour')) {
            $time_formatted = theme_remui_kids_convert24To12Hour($event->starttime);
            if (isset($event->endtime) && !empty($event->endtime)) {
                $time_end_formatted = theme_remui_kids_convert24To12Hour($event->endtime);
            }
        } else {
            // Fallback if function not available
            $time_formatted = date('h:i A', $event->timestart);
            if (isset($event->timeduration) && $event->timeduration > 0) {
                $time_end_formatted = date('h:i A', $event->timestart + $event->timeduration);
            }
        }
    } else {
        // Fallback to timestamp formatting if time strings not available
        $time_formatted = date('h:i A', $event->timestart);
        if (isset($event->timeduration) && $event->timeduration > 0) {
            $time_end_formatted = date('h:i A', $event->timestart + $event->timeduration);
        }
    }
    
    $all_events[] = [
        'id' => $event->id,
        'type' => 'admin_event',
        'icon' => $event_icon,
        'name' => format_string($event->name),
        'coursename' => $event->coursename ?? 'School Event',
        'timestart' => $event->timestart,
        'timeduration' => $event->timeduration ?? 0,
        'time' => $time_formatted, // Pre-formatted time in 12-hour format
        'time_end' => $time_end_formatted, // Pre-formatted end time
        'description' => strip_tags($event->description ?? ''),
        'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out(),
        'color' => $display_color, // Use mapped color for display
        'color_name' => strtolower($color_value), // Keep color name for reference
        'eventtype' => $event_type,
        'admin_event' => true
    ];
}

// Add calendar events
foreach ($calendar_events as $event) {
    $course_name = 'General';
    if (isset($event->courseid) && $event->courseid > 0 && isset($courses[$event->courseid])) {
        $course_name = $courses[$event->courseid]->fullname;
    }
    
    // Format time in 12-hour format using server timezone (consistent with backend)
    $time_formatted = date('h:i A', $event->timestart);
    $time_end_formatted = '';
    if (isset($event->timeduration) && $event->timeduration > 0) {
        $time_end_formatted = date('h:i A', $event->timestart + $event->timeduration);
    }
    
    $all_events[] = [
        'id' => $event->id,
        'type' => 'event',
        'icon' => 'fa-calendar',
        'name' => format_string($event->name),
        'coursename' => $course_name,
        'timestart' => $event->timestart,
        'timeduration' => $event->timeduration ?? 0,
        'time' => $time_formatted, // Pre-formatted time in 12-hour format
        'time_end' => $time_end_formatted, // Pre-formatted end time
        'description' => strip_tags($event->description ?? ''),
        'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out(),
        'color' => '#3b82f6', // Blue
        'eventtype' => $event->eventtype ?? 'course'
    ];
}

// Add assignments
foreach ($assignments as $assign) {
    $all_events[] = [
        'id' => $assign->id,
        'type' => 'assignment',
        'icon' => 'fa-file-text',
        'name' => format_string($assign->name),
        'coursename' => $assign->coursename,
        'timestart' => $assign->duedate,
        'timeduration' => 0,
        'description' => strip_tags($assign->intro),
        'url' => (new moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out(),
        'color' => '#ef4444', // Red
        'eventtype' => 'due'
    ];
}

// Add quizzes
foreach ($quizzes as $quiz) {
    $all_events[] = [
        'id' => $quiz->id,
        'type' => 'quiz',
        'icon' => 'fa-question-circle',
        'name' => format_string($quiz->name),
        'coursename' => $quiz->coursename,
        'timestart' => $quiz->timeclose,
        'timeduration' => 0,
        'description' => strip_tags($quiz->intro),
        'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out(),
        'color' => '#10b981', // Green
        'eventtype' => 'close'
    ];
}

// Add lecture sessions
foreach ($lecture_sessions as $session) {
    // Calculate timestart from sessiondate + starttime
    $start_time_parts = explode(':', $session->starttime);
    $start_hour = isset($start_time_parts[0]) ? (int)$start_time_parts[0] : 0;
    $start_minute = isset($start_time_parts[1]) ? (int)$start_time_parts[1] : 0;
    $timestart = $session->sessiondate + ($start_hour * 3600) + ($start_minute * 60);
    
    // Calculate timeduration from endtime - starttime
    $end_time_parts = explode(':', $session->endtime);
    $end_hour = isset($end_time_parts[0]) ? (int)$end_time_parts[0] : 0;
    $end_minute = isset($end_time_parts[1]) ? (int)$end_time_parts[1] : 0;
    $timeend = $session->sessiondate + ($end_hour * 3600) + ($end_minute * 60);
    $timeduration = max(0, $timeend - $timestart);
    
    $color_map = [
        'blue' => '#3b82f6',
        'green' => '#10b981',
        'red' => '#ef4444',
        'orange' => '#f59e0b',
        'purple' => '#8b5cf6',
        'yellow' => '#fbbf24',
        'pink' => '#ec4899'
    ];
    $session_color = isset($color_map[$session->color]) ? $color_map[$session->color] : $color_map['green'];
    
    // Format time in 12-hour format directly from stored time string (not from timestamp)
    // This ensures the exact time entered by the user is displayed, regardless of timezone
    // lib.php is already required at the top of this file
    if (function_exists('theme_remui_kids_convert24To12Hour')) {
        $time_formatted = theme_remui_kids_convert24To12Hour($session->starttime);
        $time_end_formatted = '';
        if (!empty($session->endtime)) {
            $time_end_formatted = theme_remui_kids_convert24To12Hour($session->endtime);
        }
    } else {
        // Fallback if function not available
        $time_formatted = date('h:i A', $timestart);
        $time_end_formatted = '';
        if ($timeduration > 0) {
            $time_end_formatted = date('h:i A', $timestart + $timeduration);
        }
    }
    
    $all_events[] = [
        'id' => $session->id,
        'type' => 'lecture',
        'icon' => 'fa-chalkboard-teacher',
        'name' => format_string($session->course_name ?? 'Lecture'),
        'coursename' => format_string($session->course_name ?? 'Unknown Course'),
        'timestart' => $timestart,
        'timeduration' => $timeduration,
        'time' => $time_formatted, // Pre-formatted time in 12-hour format
        'time_end' => $time_end_formatted, // Pre-formatted end time
        'description' => $session->title ?? '',
        'url' => (new moodle_url('/course/view.php', ['id' => $session->courseid]))->out(),
        'color' => $session_color,
        'eventtype' => 'lecture',
        'lecture_session' => true,
        'schedule_id' => $session->scheduleid,
        'courseid' => $session->courseid,
        'teacher_available' => isset($session->teacher_available) ? (int)$session->teacher_available : 1
    ];
}

// Sort by date
usort($all_events, function($a, $b) {
    return $a['timestart'] - $b['timestart'];
});

// Organize events based on view
$schedule_data = [];

if ($view === 'week') {
    // Week view - organize by days
    for ($i = 0; $i < 7; $i++) {
        $day_timestamp = $start_date + ($i * 24 * 60 * 60);
        $day_key = date('Y-m-d', $day_timestamp);
        
        $schedule_data[$day_key] = [
            'date' => $day_timestamp,
            'day_name' => date('l', $day_timestamp),
            'day_num' => date('j', $day_timestamp),
            'month_name' => date('F', $day_timestamp),
            'is_today' => (date('Y-m-d', $day_timestamp) === date('Y-m-d')),
            'events' => []
        ];
    }
    
    // Add events to days
    foreach ($all_events as $event) {
        $event_day = date('Y-m-d', $event['timestart']);
        if (isset($schedule_data[$event_day])) {
            // Use pre-formatted time if available, otherwise format from timestamp
            // This preserves the exact time entered by the user for admin events and lecture sessions
            if (!isset($event['time']) || empty($event['time'])) {
                $event['time'] = date('h:i A', $event['timestart']);
            }
            if (!isset($event['time_end']) || empty($event['time_end'])) {
                $event['time_end'] = $event['timeduration'] > 0 ? date('h:i A', $event['timestart'] + $event['timeduration']) : '';
            }
            $schedule_data[$event_day]['events'][] = $event;
        }
    }
    
    $schedule_data = array_values($schedule_data);
    
} elseif ($view === 'month') {
    // Month view - organize by days of month
    $days_in_month = date('t', $start_date);
    $first_day_of_week = date('w', $start_date); // 0 = Sunday
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_timestamp = strtotime(date('Y-m', $start_date) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT));
        $day_key = date('Y-m-d', $day_timestamp);
        
        $schedule_data[$day_key] = [
            'date' => $day_timestamp,
            'day_num' => $day,
            'is_today' => (date('Y-m-d', $day_timestamp) === date('Y-m-d')),
            'events' => [],
            'visible_events' => [],
            'has_more_events' => false,
            'total_event_count' => 0,
            'remaining_count' => 0
        ];
    }
    
    foreach ($all_events as $event) {
        $event_day = date('Y-m-d', $event['timestart']);
        if (isset($schedule_data[$event_day])) {
            $schedule_data[$event_day]['events'][] = $event;
        }
    }
    
    // Mark first 3 events for full display, rest as dots
    // Also mark admin events for dot display
    foreach ($schedule_data as $day_key => &$day_data) {
        $event_count = count($day_data['events']);
        $day_data['total_event_count'] = $event_count;
        
        // Mark events for display type
        foreach ($day_data['events'] as $index => &$event) {
            // Lecture sessions always show as dots
            if (isset($event['lecture_session']) && $event['lecture_session']) {
                // Already marked, keep as is
            } elseif (isset($event['admin_event']) && $event['admin_event']) {
                // Admin events always show as dots
                // Already marked, keep as is
            } else {
                // Regular events: first 3 show as full items, rest as dots
                $event['is_first_three'] = ($index < 3);
            }
        }
        unset($event);
        
        if ($event_count > 3) {
            $day_data['has_more_events'] = true;
            $day_data['remaining_count'] = $event_count - 3;
        } else {
            $day_data['has_more_events'] = false;
        }
    }
    
    $schedule_data = array_values($schedule_data);
    
} else {
    // List view - group by date
    $grouped = [];
    foreach ($all_events as $event) {
        $event_day = date('Y-m-d', $event['timestart']);
        if (!isset($grouped[$event_day])) {
            $grouped[$event_day] = [
                'date' => $event['timestart'],
                'day_name' => date('l', $event['timestart']),
                'day_num' => date('j', $event['timestart']),
                'month_name' => date('F', $event['timestart']),
                'year' => date('Y', $event['timestart']),
                'is_today' => ($event_day === date('Y-m-d')),
                'events' => [],
                'event_count' => 0
            ];
        }
        // Use pre-formatted time if available, otherwise format from timestamp
        // This preserves the exact time entered by the user for admin events and lecture sessions
        if (!isset($event['time']) || empty($event['time'])) {
            $event['time'] = date('h:i A', $event['timestart']);
        }
        if (!isset($event['time_end']) || empty($event['time_end'])) {
            $event['time_end'] = $event['timeduration'] > 0 ? date('h:i A', $event['timestart'] + $event['timeduration']) : '';
        }
        $grouped[$event_day]['events'][] = $event;
        $grouped[$event_day]['event_count']++;
    }
    $schedule_data = array_values($grouped);
}

// Calculate statistics
$total_events = count($all_events);
$today_events = 0;
$this_week_events = 0;
$week_start = strtotime('monday this week');
$week_end = strtotime('sunday this week') + (24 * 60 * 60);

foreach ($all_events as $event) {
    if (date('Y-m-d', $event['timestart']) === date('Y-m-d')) {
        $today_events++;
    }
    if ($event['timestart'] >= $week_start && $event['timestart'] < $week_end) {
        $this_week_events++;
    }
}

// Navigation URLs
$prev_offset = $week_offset - 1;
$next_offset = $week_offset + 1;

// Debug log
error_log("Schedule Navigation: Current offset=$week_offset, Prev=$prev_offset, Next=$next_offset, View=$view");

$prev_url = new moodle_url('/theme/remui_kids/teacher/schedule.php', [
    'view' => $view,
    'weekoffset' => $prev_offset
]);

$next_url = new moodle_url('/theme/remui_kids/teacher/schedule.php', [
    'view' => $view,
    'weekoffset' => $next_offset
]);

$today_url = new moodle_url('/theme/remui_kids/teacher/schedule.php', [
    'view' => $view,
    'weekoffset' => 0
]);

// Debug URLs
error_log("Prev URL: " . $prev_url->out());
error_log("Next URL: " . $next_url->out());

// Prepare template context
$templatecontext = [
    'teacher_name' => $USER->firstname . ' ' . $USER->lastname,
    'view' => $view,
    'week_offset' => $week_offset,
    'is_week_view' => ($view === 'week'),
    'is_month_view' => ($view === 'month'),
    'is_list_view' => ($view === 'list'),
    'schedule_data' => $schedule_data,
    'has_events' => !empty($all_events),
    'total_events' => $total_events,
    'today_events' => $today_events,
    'this_week_events' => $this_week_events,
    'date_range' => date('M j', $start_date) . ' - ' . date('M j, Y', $end_date),
    'current_month' => date('F Y', $start_date),
    'prev_url' => $prev_url->out(),
    'next_url' => $next_url->out(),
    'today_url' => $today_url->out(),
    'week_view_url' => (new moodle_url('/theme/remui_kids/teacher/schedule.php', ['view' => 'week']))->out(),
    'month_view_url' => (new moodle_url('/theme/remui_kids/teacher/schedule.php', ['view' => 'month']))->out(),
    'list_view_url' => (new moodle_url('/theme/remui_kids/teacher/schedule.php', ['view' => 'list']))->out(),
    'dashboard_url' => (new moodle_url('/my/'))->out(),
    'calendar_url' => (new moodle_url('/calendar/view.php'))->out(),
    'config' => ['wwwroot' => $CFG->wwwroot],
    'currentpage' => ['schedule' => true]
];

// Render the page
echo $OUTPUT->header();
?>
<style>
/* Schedule Page Specific Styles - Override Dashboard Styles */
.teacher-schedule-page {
    padding: 2rem;
    background: #f8f9fa;
    min-height: 100vh;
}

/* Schedule Page Header - Matching Dashboard Hero */
.schedule-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
    padding: 1.75rem 2rem;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    background: linear-gradient(135deg, rgb(255, 255, 255), rgb(244, 247, 248));
    border-radius: 18px;
    border-bottom: 6px solid transparent;
    border-image: linear-gradient(90deg, #9fa1ff, #98dbfa, #92f0e5);
    border-image-slice: 1;
    margin-bottom: 1.5rem;
}

.schedule-page-header .header-left {
    flex: 1;
}

.schedule-page-header .page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.schedule-page-header .page-title i {
    color: #3b82f6;
    font-size: 1.75rem;
}

.schedule-page-header .page-subtitle {
    margin: 0.5rem 0 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.schedule-page-header .header-right {
    display: flex;
    align-items: center;
}

.schedule-page-header .btn-outline {
    background: transparent;
    color: #3b82f6;
    border: 2px solid #3b82f6;
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 600;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.schedule-page-header .btn-outline:hover {
    background: #3b82f6;
    color: white;
    text-decoration: none;
}

/* Schedule Stats Cards - Exact Match from Dashboard */
.schedule-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.teacher-schedule-page .stat-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    border: none;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.stat-card::before {
    background: white !important;
}

.teacher-schedule-page .stat-card:hover {
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
    transform: translateY(-2px);
}

.teacher-schedule-page .stat-card::after {
    content: '';
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 10px;
    height: 4px;
    border-radius: 999px;
    background: linear-gradient(90deg, #9fa1ff, #98dbfa, #92f0e5);
}

/* Icon in top-right corner */
.teacher-schedule-page .stat-card .stat-icon {
    position: absolute;
    top: 1.25rem;
    right: 1.25rem;
    width: 44px;
    height: 44px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    font-weight: 600;
    flex-shrink: 0;
    margin: 0;
}

.teacher-schedule-page .stat-card .stat-icon i {
    color: inherit;
}

/* Label at the top */
.teacher-schedule-page .stat-card .stat-label {
    font-size: 0.8125rem;
    color: #64748b;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 0.5rem;
    margin-top: 0;
}

/* Content container */
.teacher-schedule-page .stat-card .stat-content {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-top: 0;
}

/* Large number in the middle */
.teacher-schedule-page .stat-card .stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
    margin: 0;
}

/* Schedule Controls */
.schedule-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding: 1rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    flex-wrap: wrap;
}

.view-selector {
    display: flex;
    gap: 0.5rem;
}

.view-btn {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.view-btn:hover {
    background: #f1f5f9;
    color: #3b82f6;
    text-decoration: none;
}

.view-btn.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.date-navigation {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.nav-btn {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #64748b;
    font-size: 0.875rem;
}

.nav-btn:hover {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

.current-date {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    min-width: 180px;
    text-align: center;
}

.today-btn {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #3b82f6;
    font-size: 0.875rem;
    font-weight: 600;
}

.today-btn:hover {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .teacher-schedule-page {
        padding: 1rem;
    }
    
    .schedule-page-header {
        flex-direction: column;
        gap: 1rem;
        padding: 1.5rem;
    }
    
    .schedule-stats {
        grid-template-columns: 1fr;
    }
    
    .schedule-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .view-selector {
        width: 100%;
        justify-content: center;
    }
    
    .date-navigation {
        width: 100%;
        justify-content: center;
    }
}
</style>
<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>
        
        <?php echo $OUTPUT->render_from_template('theme_remui_kids/teacher_schedule_page', $templatecontext); ?>
        
    </div><!-- End teacher-dashboard-wrapper -->
</div><!-- End teacher-css-wrapper -->
<?php
echo $OUTPUT->footer();

