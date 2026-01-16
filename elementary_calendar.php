<?php
/**
 * Elementary Calendar (Grades 1–3 only)
 * A standalone page with a clone-style calendar UI (month/week/day + right rail).
 *
 * @package   theme_remui_kids
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/elementary_calendar.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title('Elementary Calendar');
$PAGE->set_heading('Elementary Calendar');

// Restrict strictly to Elementary cohorts (Grade 1–3 by name or idnumber).
$is_elementary = false;
$usercohorts = cohort_get_user_cohorts($USER->id);
foreach ($usercohorts as $c) {
    $needle = strtolower($c->name . ' ' . $c->idnumber);
    if (preg_match('/grade\s*[1-3]|grade[1-3]/', $needle)) { $is_elementary = true; break; }
}
if (!$is_elementary) {
    // Soft block – redirect to dashboard.
    redirect(new moodle_url('/my/'));
}

// Dynamic view/state via query params
$view = optional_param('view', 'month', PARAM_ALPHA);
$year = optional_param('y', (int)date('Y'), PARAM_INT);
$month = optional_param('m', (int)date('n'), PARAM_INT);
$day = optional_param('d', (int)date('j'), PARAM_INT);

$base = make_timestamp($year, $month, $day ?: 1);
$today = time();

function ecal_get_weekdays_data(int $year, int $month, int $day, int $today): array {
    // Get the current week's Monday
    $current_date = make_timestamp($year, $month, $day);
    $monday = strtotime('monday this week', $current_date);
    
    $weekdays = [];
    $day_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $today_iso = date('Y-m-d', $today);
    
    for ($i = 0; $i < 7; $i++) {
        $cursor = strtotime("+{$i} days", $monday);
        $cursor_iso = date('Y-m-d', $cursor);
        
        $weekdays[] = [
            'name' => $day_names[$i],
            'date' => date('j', $cursor),
            'iso' => $cursor_iso,
            'is_today' => $cursor_iso === $today_iso,
            'events' => []
        ];
    }
    
    return $weekdays;
}

function ecal_get_month_grid(int $year, int $month, int $today): array {
    $first = make_timestamp($year, $month, 1);
    $start = strtotime('monday this week', $first);
    if ((int)date('N', $first) === 1) { $start = $first; }
    $rows = [];
    $cursor = $start;
    $today_iso = date('Y-m-d', $today);
    
    for ($r = 0; $r < 6; $r++) {
        $cols = [];
        for ($c = 0; $c < 7; $c++) {
            $cursor_iso = date('Y-m-d', $cursor);
            $cols[] = [
                'date' => date('j', $cursor),
                'iso' => $cursor_iso,
                'inmonth' => (int)date('n', $cursor) === $month,
                'is_today' => $cursor_iso === $today_iso,
                'events' => []
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
        $rows[] = ['cols' => $cols];
    }
    return $rows;
}

function ecal_fetch_events(int $startts, int $endts): array {
    global $DB, $USER;
    $events = [];
    
    // Get all user's enrolled courses
    $courses = enrol_get_all_users_courses($USER->id, true);
    $courseids = array_keys($courses);
    
    // Get school admin calendar events FIRST (always fetch these, even if no courses)
    require_once(__DIR__ . '/lib.php');
    $admin_events = theme_remui_kids_get_school_admin_calendar_events($USER->id, $startts, $endts);
    foreach ($admin_events as $admin_event) {
        // Map admin event colors to tones
        $tone = 'blue';
        $color = strtolower($admin_event->color ?? 'blue');
        if ($color === 'red') { $tone = 'red'; }
        else if ($color === 'green') { $tone = 'green'; }
        else if ($color === 'orange' || $color === 'yellow') { $tone = 'yellow'; }
        else if ($color === 'purple') { $tone = 'purple'; }
        
        // Get event type from admin_event
        $event_type = strtolower($admin_event->eventtype ?? 'meeting');
        
        $events[] = [
            't' => (int)$admin_event->timestart,
            'title' => format_string($admin_event->name, true, ['context' => context_system::instance()]),
            'url' => '#', // School admin events don't have a specific URL
            'tone' => $tone,
            'admin_event' => true,
            'date' => date('Y-m-d', $admin_event->timestart), // Required for JS filtering
            'time' => date('h:i A', $admin_event->timestart), // Required for JS display
            'type' => $event_type, // Required for JS badge display
            'course' => $admin_event->coursename ?? 'School Event' // Required for JS display
        ];
    }
    
    if (!empty($courseids)) {
        list($inidsql, $inidparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        
        // 1. Get calendar events (site, user, course events)
        $sql = "SELECT id, name, eventtype, timestart, timeduration, courseid, userid
                FROM {event}
                WHERE (
                    (timestart BETWEEN :start1 AND :end1)
                    OR (timestart <= :start2 AND (timestart + timeduration) >= :end2)
                )
                AND (eventtype = 'site'
                     OR (eventtype = 'user' AND userid = :userid)
                     OR (eventtype = 'course' AND courseid $inidsql))";
        $params = array_merge([
            'start1' => $startts, 
            'end1' => $endts, 
            'start2' => $startts, 
            'end2' => $endts,
            'userid' => $USER->id
        ], $inidparams);
        $records = $DB->get_records_sql($sql, $params);

        foreach ($records as $e) {
            $tone = 'blue';
            if ($e->eventtype === 'site') { $tone = 'purple'; }
            else if ($e->eventtype === 'user') { $tone = 'green'; }
            else if ($e->eventtype === 'course') { $tone = 'yellow'; }
            
            // Get course name
            $course_name = 'General';
            if ($e->courseid && isset($courses[$e->courseid])) {
                $course_name = $courses[$e->courseid]->fullname;
            }
            
            $events[] = [
                't' => (int)$e->timestart,
                'title' => format_string($e->name, true, ['context' => context_system::instance()]),
                'url' => (new moodle_url('/calendar/event.php', ['id' => $e->id]))->out(),
                'tone' => $tone,
                'date' => date('Y-m-d', $e->timestart), // Required for JS filtering
                'time' => date('h:i A', $e->timestart), // Required for JS display
                'type' => $e->eventtype, // Required for JS badge display
                'course' => $course_name // Required for JS display
            ];
        }
        
        // 2. Get assignments with due dates
        $sql = "SELECT a.id, a.name, a.duedate, a.course, cm.id cmid, c.fullname as coursename
                FROM {assign} a
                JOIN {course_modules} cm ON cm.instance = a.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                JOIN {course} c ON c.id = a.course
                WHERE a.course $inidsql 
                AND a.duedate > 0
                AND a.duedate >= :startts AND a.duedate <= :endts
                AND cm.visible = 1 AND cm.deletioninprogress = 0";
        $params = array_merge(['startts' => $startts, 'endts' => $endts], $inidparams);
        $assignments = $DB->get_records_sql($sql, $params);
        
        foreach ($assignments as $a) {
            $events[] = [
                't' => (int)$a->duedate,
                'title' => '📝 ' . format_string($a->name),
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => $a->cmid]))->out(),
                'tone' => 'blue',
                'date' => date('Y-m-d', $a->duedate), // Required for JS filtering
                'time' => date('h:i A', $a->duedate), // Required for JS display
                'type' => 'assignment', // Required for JS badge display
                'course' => $a->coursename // Required for JS display
            ];
        }
        
        // 3. Get quizzes with close dates
        $sql = "SELECT q.id, q.name, q.timeclose, q.course, cm.id cmid, c.fullname as coursename
                FROM {quiz} q
                JOIN {course_modules} cm ON cm.instance = q.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                JOIN {course} c ON c.id = q.course
                WHERE q.course $inidsql 
                AND q.timeclose > 0
                AND q.timeclose >= :startts AND q.timeclose <= :endts
                AND cm.visible = 1 AND cm.deletioninprogress = 0";
        $params = array_merge(['startts' => $startts, 'endts' => $endts], $inidparams);
        $quizzes = $DB->get_records_sql($sql, $params);
        
        foreach ($quizzes as $q) {
            $events[] = [
                't' => (int)$q->timeclose,
                'title' => '❓ ' . format_string($q->name),
                'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $q->cmid]))->out(),
                'tone' => 'green',
                'date' => date('Y-m-d', $q->timeclose), // Required for JS filtering
                'time' => date('h:i A', $q->timeclose), // Required for JS display
                'type' => 'quiz', // Required for JS badge display
                'course' => $q->coursename // Required for JS display
            ];
        }
        
        // 4. Get lessons with deadlines
        $sql = "SELECT l.id, l.name, l.deadline, l.course, cm.id cmid, c.fullname as coursename
                FROM {lesson} l
                JOIN {course_modules} cm ON cm.instance = l.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'lesson'
                JOIN {course} c ON c.id = l.course
                WHERE l.course $inidsql 
                AND l.deadline > 0
                AND l.deadline >= :startts AND l.deadline <= :endts
                AND cm.visible = 1 AND cm.deletioninprogress = 0";
        $params = array_merge(['startts' => $startts, 'endts' => $endts], $inidparams);
        $lessons = $DB->get_records_sql($sql, $params);
        
        foreach ($lessons as $l) {
            $events[] = [
                't' => (int)$l->deadline,
                'title' => '📖 ' . format_string($l->name),
                'url' => (new moodle_url('/mod/lesson/view.php', ['id' => $l->cmid]))->out(),
                'tone' => 'purple',
                'date' => date('Y-m-d', $l->deadline), // Required for JS filtering
                'time' => date('h:i A', $l->deadline), // Required for JS display
                'type' => 'lesson', // Required for JS badge display
                'course' => $l->coursename // Required for JS display
            ];
        }
        
        // 5. Get course start and end dates
        foreach ($courses as $course) {
            if ($course->startdate > 0 && $course->startdate >= $startts && $course->startdate <= $endts) {
                $events[] = [
                    't' => (int)$course->startdate,
                    'title' => '🎓 ' . format_string($course->fullname) . ' - Course Start',
                    'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
                    'tone' => 'purple',
                    'date' => date('Y-m-d', $course->startdate), // Required for JS filtering
                    'time' => date('h:i A', $course->startdate), // Required for JS display
                    'type' => 'course', // Required for JS badge display
                    'course' => $course->fullname // Required for JS display
                ];
            }
            
            if ($course->enddate > 0 && $course->enddate >= $startts && $course->enddate <= $endts) {
                $events[] = [
                    't' => (int)$course->enddate,
                    'title' => '🏆 ' . format_string($course->fullname) . ' - Course End',
                    'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
                    'tone' => 'green',
                    'date' => date('Y-m-d', $course->enddate), // Required for JS filtering
                    'time' => date('h:i A', $course->enddate), // Required for JS display
                    'type' => 'course', // Required for JS badge display
                    'course' => $course->fullname // Required for JS display
                ];
            }
        }
    }
    
    return $events;
}

// Compute range for selected view and get appropriate data
if ($view === 'month') {
    // For month view, get events for the entire month including previous/next month days in the grid
    $firstday = make_timestamp((int)date('Y',$base), (int)date('n',$base), 1);
    $lastday = make_timestamp((int)date('Y',$base), (int)date('n',$base), (int)date('t',$base), 23, 59, 59);
    // Expand to include the full calendar grid (6 weeks)
    $monthstart = strtotime('monday this week', $firstday);
    if ((int)date('N', $firstday) === 1) { $monthstart = $firstday; }
    $monthend = strtotime('+41 days 23:59:59', $monthstart);
    $events = ecal_fetch_events($monthstart, $monthend);
    $rows = ecal_get_month_grid((int)date('Y',$base),(int)date('n',$base), $today);
    $weekdays = [];
    $selected_day_events = [];
} elseif ($view === 'week') {
    // For week view, get Monday to Sunday
    $weekstart = strtotime('monday this week', $base);
    $weekend = strtotime('sunday this week 23:59:59', $base);
    $events = ecal_fetch_events($weekstart, $weekend);
    $weekdays = ecal_get_weekdays_data((int)date('Y',$base),(int)date('n',$base),(int)date('j',$base), $today);
    $rows = [];
    $selected_day_events = [];
} else { // day
    $daystart = strtotime(date('Y-m-d 00:00:00', $base));
    $dayend = strtotime(date('Y-m-d 23:59:59', $base));
    $events = ecal_fetch_events($daystart, $dayend);
    $selected_day_events = $events; // All events for the selected day
    $rows = [];
    $weekdays = [];
}

// Map events by view type
$byday = [];
foreach ($events as $e) {
    $k = date('Y-m-d', $e['t']);
    $hour = (int)date('H', $e['t']);
    $minute = (int)date('i', $e['t']);
    
    // Calculate time position for daily view (8 AM = 0, each hour = 60px)
    $time_position = (($hour - 8) * 60) + ($minute * 1); // 8 AM to 5 PM
    if ($time_position < 0) $time_position = 0;
    if ($time_position > 540) $time_position = 540; // Max 5 PM
    
    // Calculate duration (default 1 hour, minimum 60px)
    $duration = 60; // Default 1 hour
    
    // Format time range
    $time_range = date('g:i A', $e['t']);
    
    $byday[$k][] = [
        'title' => $e['title'],
        'tone' => $e['tone'],
        'url' => $e['url'],
        'time_position' => $time_position,
        'duration' => $duration,
        'time_range' => $time_range
    ];
}

// Map events to appropriate views
if ($view === 'month') {
    // Map events to month grid cells
    foreach ($rows as &$r) {
        foreach ($r['cols'] as &$c) {
            $c['events'] = $byday[$c['iso']] ?? [];
        }
    }
    unset($r,$c);
} elseif ($view === 'week') {
    // Map events to weekdays
    foreach ($weekdays as &$weekday) {
        $weekday['events'] = $byday[$weekday['iso']] ?? [];
    }
    unset($weekday);
} else { // day
    // Process selected day events for daily view
    foreach ($selected_day_events as &$event) {
        $event['time_position'] = $byday[date('Y-m-d', $event['t'])][0]['time_position'] ?? 0;
        $event['duration'] = $byday[date('Y-m-d', $event['t'])][0]['duration'] ?? 60;
        $event['time_range'] = $byday[date('Y-m-d', $event['t'])][0]['time_range'] ?? '';
    }
    unset($event);
}

// Mini calendar
$daysinmonth = (int)date('t', $base);
$firstw = (int)date('N', make_timestamp((int)date('Y',$base),(int)date('n',$base),1));
$mini = [];
$lead = $firstw-1; $cells = 42; $n = 1;
for ($i=0;$i<$cells;$i++) {
    $val = ($i>=$lead && $n<= $daysinmonth) ? $n++ : '';
    $active = ($val && (int)date('j')===$val && (int)date('n')===$month && (int)date('Y')===$year) ? 'active':'';
    $mini[] = ['n'=>$val,'active'=>$active];
}

// Calculate event statistics
$total_events = count($events);
$today_events = 0;
$week_events = 0;
$week_start = strtotime('monday this week', $today);
$week_end = strtotime('sunday this week', $today);
$today_date = date('Y-m-d', $today);

foreach ($events as $e) {
    $event_date = date('Y-m-d', $e['t']);
    if ($event_date === $today_date) {
        $today_events++;
    }
    if ($e['t'] >= $week_start && $e['t'] <= $week_end) {
        $week_events++;
    }
}

// Get upcoming events list for sidebar
$upcoming_events = [];
$event_limit = 5;
$sorted_events = $events;
usort($sorted_events, function($a, $b) { return $a['t'] - $b['t']; });
foreach (array_slice($sorted_events, 0, $event_limit) as $e) {
    $upcoming_events[] = [
        'title' => $e['title'],
        'tone' => $e['tone'],
        'url' => $e['url'],
        'date' => userdate($e['t'], '%d %b'),
        'time' => userdate($e['t'], '%I:%M %p'),
    ];
}

// Navigation URLs based on current view
if ($view === 'week') {
    // Week navigation: move week by week
    $prev_period = strtotime('-1 week', $base);
    $next_period = strtotime('+1 week', $base);
    $prev_url_params = ['view' => 'week', 'y' => (int)date('Y', $prev_period), 'm' => (int)date('n', $prev_period), 'd' => (int)date('j', $prev_period)];
    $next_url_params = ['view' => 'week', 'y' => (int)date('Y', $next_period), 'm' => (int)date('n', $next_period), 'd' => (int)date('j', $next_period)];
} elseif ($view === 'day') {
    // Day navigation: move day by day
    $prev_period = strtotime('-1 day', $base);
    $next_period = strtotime('+1 day', $base);
    $prev_url_params = ['view' => 'day', 'y' => (int)date('Y', $prev_period), 'm' => (int)date('n', $prev_period), 'd' => (int)date('j', $prev_period)];
    $next_url_params = ['view' => 'day', 'y' => (int)date('Y', $next_period), 'm' => (int)date('n', $next_period), 'd' => (int)date('j', $next_period)];
} else {
    // Month navigation: move month by month
    $prev_month = make_timestamp($month == 1 ? $year - 1 : $year, $month == 1 ? 12 : $month - 1, 1);
    $next_month = make_timestamp($month == 12 ? $year + 1 : $year, $month == 12 ? 1 : $month + 1, 1);
    $prev_url_params = ['view' => 'month', 'y' => (int)date('Y', $prev_month), 'm' => (int)date('n', $prev_month)];
    $next_url_params = ['view' => 'month', 'y' => (int)date('Y', $next_month), 'm' => (int)date('n', $next_month)];
}

// Generate appropriate title based on view
if ($view === 'week') {
    $week_start = strtotime('monday this week', $base);
    $week_end = strtotime('sunday this week', $base);
    $period_title = userdate($week_start, '%d %b') . ' - ' . userdate($week_end, '%d %b %Y');
} elseif ($view === 'day') {
    $period_title = userdate($base, '%A, %d %B %Y');
} else {
    $period_title = userdate($base, '%B %Y');
}

$templatecontext = [
    'student_name' => $USER->firstname ?: fullname($USER),
    'today_iso' => date('Y-m-d', $today),
    'month_name' => $period_title,
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'calendarurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'rows' => $rows,
    'weekdays' => $weekdays,
    'selected_day_name' => date('l', $base),
    'selected_day_date' => date('j', $base),
    'selected_day_events' => $selected_day_events,
    'mini' => $mini,
    'view_month_url' => (new moodle_url('/theme/remui_kids/elementary_calendar.php',['view'=>'month','y'=>$year,'m'=>$month]))->out(),
    'view_week_url' => (new moodle_url('/theme/remui_kids/elementary_calendar.php',['view'=>'week','y'=>$year,'m'=>$month,'d'=>$day]))->out(),
    'view_day_url' => (new moodle_url('/theme/remui_kids/elementary_calendar.php',['view'=>'day','y'=>$year,'m'=>$month,'d'=>$day]))->out(),
    'prev_month_url' => (new moodle_url('/theme/remui_kids/elementary_calendar.php', $prev_url_params))->out(),
    'next_month_url' => (new moodle_url('/theme/remui_kids/elementary_calendar.php', $next_url_params))->out(),
    'is_month' => $view==='month',
    'is_week' => $view==='week',
    'is_day' => $view==='day',
    'total_events' => $total_events,
    'today_events' => $today_events,
    'week_events' => $week_events,
    'upcoming_events' => $upcoming_events,
    'has_upcoming' => !empty($upcoming_events),
    // Elementary sidebar URLs
    'show_elementary_sidebar' => true,
    'is_schedule_page' => true, // Mark schedule as active
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'treeviewurl' => $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php',
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_calendar', $templatecontext);
echo $OUTPUT->footer();


