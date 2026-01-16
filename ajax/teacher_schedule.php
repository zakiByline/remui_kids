<?php
/**
 * AJAX endpoint for loading teacher schedule
 *
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_login();

// Get parameters
$week_offset = optional_param('week_offset', 0, PARAM_INT);
$view = optional_param('view', 'week', PARAM_ALPHA);
$year = optional_param('year', date('Y'), PARAM_INT);
$month = optional_param('month', date('n') - 1, PARAM_INT); // JavaScript months are 0-indexed
$weeks = optional_param('weeks', 4, PARAM_INT);

// Check if user is a teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
         LIMIT 1",
        $params
    );
    
    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

// Set header for JSON response
header('Content-Type: application/json');

if (!$isteacher) {
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. User is not a teacher.'
    ]);
    exit;
}

// Get schedule data using the function from lib.php
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once($CFG->dirroot . '/calendar/lib.php');

try {
    if ($view === 'week') {
        // Week view
        $schedule = theme_remui_kids_get_teacher_schedule($week_offset);
        
        if ($schedule) {
            echo json_encode([
                'success' => true,
                'schedule' => $schedule
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No schedule data available'
            ]);
        }
    } elseif ($view === 'month') {
        // Month view - get events for entire month
        $courses = enrol_get_all_users_courses($USER->id, true);
        $courseids = array_keys($courses);
        
        // Calculate month start and end
        $month_start = mktime(0, 0, 0, $month + 1, 1, $year); // JS month is 0-indexed, PHP is 1-indexed
        $month_end = mktime(23, 59, 59, $month + 2, 0, $year); // Last day of month
        
        // Get school admin calendar events for this teacher FIRST
        $admin_events = theme_remui_kids_get_school_admin_calendar_events($USER->id, $month_start, $month_end);
        
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
                    'start_date' => $month_start,
                    'end_date' => $month_end
                ]
            );
        }
        
        // Get events for the month (calendar, assignments, quizzes)
        $calendar_events = calendar_get_events($month_start, $month_end, true, true, true, $courseids);
        
        // Get assignments for the month
        $assignments = [];
        if (!empty($courseids)) {
            list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['start'] = $month_start;
            $params['end'] = $month_end;
            
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
        
        // Get quizzes for the month
        $quizzes = [];
        if (!empty($courseids)) {
            list($courseids_sql2, $params2) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params2['start'] = $month_start;
            $params2['end'] = $month_end;
            
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
        $events = [];
        
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
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $time_formatted = '';
            $time_end_formatted = '';
            if (isset($event->starttime) && !empty($event->starttime)) {
                // lib.php should be loaded, but check if function exists
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
            
            $events[] = [
                'id' => $event->id,
                'name' => format_string($event->name),
                'timestart' => $event->timestart,
                'timeduration' => $event->timeduration ?? 0,
                'eventtype' => $event_type,
                'coursename' => format_string($event->coursename ?? 'School Event'),
                'icon' => $event_icon,
                'color' => $event->color ?? '#3b82f6',
                'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out(),
                'admin_event' => true,
                'type' => $event_type,
                'date' => date('Y-m-d', $event->timestart),
                'time' => $time_formatted,
                'time_end' => $time_end_formatted,
                'description' => strip_tags($event->description ?? '')
            ];
        }
        
        foreach ($calendar_events as $event) {
            $course_name = 'General';
            if (isset($event->courseid) && $event->courseid > 0 && isset($courses[$event->courseid])) {
                $course_name = $courses[$event->courseid]->fullname;
            }
            
            $events[] = [
                'id' => $event->id,
                'name' => format_string($event->name),
                'timestart' => $event->timestart,
                'timeduration' => $event->timeduration ?? 0,
                'eventtype' => $event->eventtype ?? 'course',
                'coursename' => format_string($course_name),
                'icon' => 'fa-calendar',
                'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out()
            ];
        }
        
        foreach ($assignments as $assign) {
            $events[] = [
                'id' => $assign->id,
                'name' => format_string($assign->name),
                'timestart' => $assign->duedate,
                'timeduration' => 0,
                'eventtype' => 'due',
                'coursename' => format_string($assign->coursename),
                'icon' => 'fa-file-text',
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out()
            ];
        }
        
        foreach ($quizzes as $quiz) {
            $events[] = [
                'id' => $quiz->id,
                'name' => format_string($quiz->name),
                'timestart' => $quiz->timeclose,
                'timeduration' => 0,
                'eventtype' => 'close',
                'coursename' => format_string($quiz->coursename),
                'icon' => 'fa-question-circle',
                'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out()
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
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $time_formatted = '';
            $time_end_formatted = '';
            if (isset($session->starttime) && !empty($session->starttime)) {
                if (function_exists('theme_remui_kids_convert24To12Hour')) {
                    $time_formatted = theme_remui_kids_convert24To12Hour($session->starttime);
                    if (isset($session->endtime) && !empty($session->endtime)) {
                        $time_end_formatted = theme_remui_kids_convert24To12Hour($session->endtime);
                    }
                } else {
                    // Fallback if function not available
                    $time_formatted = date('h:i A', $timestart);
                    if ($timeduration > 0) {
                        $time_end_formatted = date('h:i A', $timeend);
                    }
                }
            } else {
                // Fallback to timestamp formatting if time strings not available
                $time_formatted = date('h:i A', $timestart);
                if ($timeduration > 0) {
                    $time_end_formatted = date('h:i A', $timeend);
                }
            }
            
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
            
            $events[] = [
                'id' => $session->id,
                'name' => format_string($session->course_name ?? 'Lecture'),
                'timestart' => $timestart,
                'timeduration' => $timeduration,
                'eventtype' => 'lecture',
                'coursename' => format_string($session->course_name ?? 'Unknown Course'),
                'icon' => 'fa-chalkboard-teacher',
                'color' => $session_color,
                'url' => (new moodle_url('/course/view.php', ['id' => $session->courseid]))->out(),
                'lecture_session' => true,
                'schedule_id' => $session->scheduleid,
                'courseid' => $session->courseid,
                'teacher_available' => isset($session->teacher_available) ? (int)$session->teacher_available : 1,
                'time' => $time_formatted,
                'time_end' => $time_end_formatted,
                'date' => date('Y-m-d', $session->sessiondate),
                'description' => strip_tags($session->title ?? '')
            ];
        }
        
        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
    } elseif ($view === 'list') {
        // List view - get upcoming events
        $courses = enrol_get_all_users_courses($USER->id, true);
        $courseids = array_keys($courses);
        
        // Calculate date range (next X weeks)
        $start_time = time();
        $end_time = $start_time + ($weeks * 7 * 24 * 60 * 60);
        
        // Get events
        $calendar_events = calendar_get_events($start_time, $end_time, true, true, true, $courseids);
        
        // Get school admin calendar events for this teacher
        $admin_events = theme_remui_kids_get_school_admin_calendar_events($USER->id, $start_time, $end_time);
        
        // Get lecture sessions for this teacher
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
                    'start_date' => $start_time,
                    'end_date' => $end_time
                ]
            );
        }
        
        // Get assignments
        $assignments = [];
        if (!empty($courseids)) {
            list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['start'] = $start_time;
            $params['end'] = $end_time;
            
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
            $params2['start'] = $start_time;
            $params2['end'] = $end_time;
            
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
        
        // Combine and convert all events
        $events = [];
        
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
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $time_formatted = '';
            $time_end_formatted = '';
            if (isset($event->starttime) && !empty($event->starttime)) {
                // lib.php should be loaded, but check if function exists
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
            
            $events[] = [
                'id' => $event->id,
                'name' => format_string($event->name),
                'timestart' => $event->timestart,
                'timeduration' => $event->timeduration ?? 0,
                'eventtype' => $event_type,
                'coursename' => format_string($event->coursename ?? 'School Event'),
                'icon' => $event_icon,
                'color' => $event->color ?? '#3b82f6',
                'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out(),
                'admin_event' => true,
                'type' => $event_type,
                'date' => date('Y-m-d', $event->timestart),
                'time' => $time_formatted,
                'time_end' => $time_end_formatted,
                'description' => strip_tags($event->description ?? '')
            ];
        }
        
        foreach ($calendar_events as $event) {
            $course_name = 'General';
            if (isset($event->courseid) && $event->courseid > 0 && isset($courses[$event->courseid])) {
                $course_name = $courses[$event->courseid]->fullname;
            }
            
            $events[] = [
                'id' => $event->id,
                'name' => format_string($event->name),
                'timestart' => $event->timestart,
                'timeduration' => $event->timeduration ?? 0,
                'eventtype' => $event->eventtype ?? 'course',
                'coursename' => format_string($course_name),
                'icon' => 'fa-calendar',
                'url' => (new moodle_url('/calendar/view.php', ['view' => 'day', 'time' => $event->timestart]))->out()
            ];
        }
        
        foreach ($assignments as $assign) {
            $events[] = [
                'id' => $assign->id,
                'name' => format_string($assign->name),
                'timestart' => $assign->duedate,
                'timeduration' => 0,
                'eventtype' => 'due',
                'coursename' => format_string($assign->coursename),
                'icon' => 'fa-file-text',
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out()
            ];
        }
        
        foreach ($quizzes as $quiz) {
            $events[] = [
                'id' => $quiz->id,
                'name' => format_string($quiz->name),
                'timestart' => $quiz->timeclose,
                'timeduration' => 0,
                'eventtype' => 'close',
                'coursename' => format_string($quiz->coursename),
                'icon' => 'fa-question-circle',
                'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out()
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
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $time_formatted = '';
            $time_end_formatted = '';
            if (isset($session->starttime) && !empty($session->starttime)) {
                if (function_exists('theme_remui_kids_convert24To12Hour')) {
                    $time_formatted = theme_remui_kids_convert24To12Hour($session->starttime);
                    if (isset($session->endtime) && !empty($session->endtime)) {
                        $time_end_formatted = theme_remui_kids_convert24To12Hour($session->endtime);
                    }
                } else {
                    // Fallback if function not available
                    $time_formatted = date('h:i A', $timestart);
                    if ($timeduration > 0) {
                        $time_end_formatted = date('h:i A', $timeend);
                    }
                }
            } else {
                // Fallback to timestamp formatting if time strings not available
                $time_formatted = date('h:i A', $timestart);
                if ($timeduration > 0) {
                    $time_end_formatted = date('h:i A', $timeend);
                }
            }
            
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
            
            $events[] = [
                'id' => $session->id,
                'name' => format_string($session->course_name ?? 'Lecture'),
                'timestart' => $timestart,
                'timeduration' => $timeduration,
                'eventtype' => 'lecture',
                'coursename' => format_string($session->course_name ?? 'Unknown Course'),
                'icon' => 'fa-chalkboard-teacher',
                'color' => $session_color,
                'url' => (new moodle_url('/course/view.php', ['id' => $session->courseid]))->out(),
                'lecture_session' => true,
                'schedule_id' => $session->scheduleid,
                'courseid' => $session->courseid,
                'teacher_available' => isset($session->teacher_available) ? (int)$session->teacher_available : 1,
                'time' => $time_formatted,
                'time_end' => $time_end_formatted,
                'date' => date('Y-m-d', $session->sessiondate),
                'description' => strip_tags($session->title ?? '')
            ];
        }
        
        // Sort by timestart
        usort($events, function($a, $b) {
            return $a['timestart'] - $b['timestart'];
        });
        
        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid view type'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}


