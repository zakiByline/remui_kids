<?php
/**
 * Parent Dashboard - Communications Hub (ENHANCED)
 * Professional communications center with advanced features
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once($CFG->dirroot . '/message/lib.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

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
$PAGE->set_url('/theme/remui_kids/parent/parent_communications.php');
$PAGE->set_title('Communications Hub');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// ==================== MESSAGES ====================
$conversations = [];
$grouped_conversations = [];
$unread_messages_count = 0;
$sent_messages_count = 0;
$received_messages_count = 0;
$today_count = 0;
$week_count = 0;

try {
    $sql = "SELECT m.id, m.useridfrom, m.useridto, m.subject, m.fullmessage, 
                   m.timecreated, m.timeread,
                   ufrom.firstname as from_firstname, ufrom.lastname as from_lastname, 
                   ufrom.email as from_email,
                   uto.firstname as to_firstname, uto.lastname as to_lastname,
                   uto.email as to_email
            FROM {message} m
            LEFT JOIN {user} ufrom ON ufrom.id = m.useridfrom
            LEFT JOIN {user} uto ON uto.id = m.useridto
            WHERE (m.useridto = :userid1 OR m.useridfrom = :userid2)
            ORDER BY m.timecreated DESC
            LIMIT 100";
    
    $messages = $DB->get_records_sql($sql, [
        'userid1' => $userid,
        'userid2' => $userid
    ]);
    
    foreach ($messages as $msg) {
        $is_received = ($msg->useridto == $userid);
        $other_user_id = $is_received ? $msg->useridfrom : $msg->useridto;
        
        // Use fullname() for proper null handling
        if ($is_received) {
            $other_user_obj = (object)[
                'firstname' => $msg->from_firstname ?? '',
                'lastname' => $msg->from_lastname ?? ''
            ];
            $other_email = $msg->from_email ?? '';
        } else {
            $other_user_obj = (object)[
                'firstname' => $msg->to_firstname ?? '',
                'lastname' => $msg->to_lastname ?? ''
            ];
            $other_email = $msg->to_email ?? '';
        }
        $other_user = fullname($other_user_obj);
        
        // Generate initials safely
        $initials = '';
        if ($is_received && !empty($msg->from_firstname) && !empty($msg->from_lastname)) {
            $initials = strtoupper(substr($msg->from_firstname, 0, 1) . substr($msg->from_lastname, 0, 1));
        } else if (!empty($msg->to_firstname) && !empty($msg->to_lastname)) {
            $initials = strtoupper(substr($msg->to_firstname, 0, 1) . substr($msg->to_lastname, 0, 1));
        }
        
        // Calculate time ago
        $time_diff = time() - $msg->timecreated;
        if ($time_diff < 86400) $today_count++;
        if ($time_diff < (7 * 86400)) $week_count++;
        
        $conversations[] = [
            'id' => $msg->id,
            'subject' => $msg->subject ?: 'No Subject',
            'message' => strip_tags($msg->fullmessage),
            'time' => $msg->timecreated,
            'is_received' => $is_received,
            'is_read' => ($msg->timeread > 0),
            'other_user' => $other_user,
            'other_user_id' => $other_user_id,
            'other_email' => $other_email,
            'initials' => $initials,
            'direction' => $is_received ? 'received' : 'sent',
            'priority' => strlen($msg->fullmessage) > 500 ? 'high' : 'normal'
        ];
        
        if ($is_received && !$msg->timeread) {
            $unread_messages_count++;
        }
        
        if ($is_received) {
            $received_messages_count++;
        } else {
            $sent_messages_count++;
        }
        
        // Group by conversation partner
        if (!isset($grouped_conversations[$other_user_id])) {
            $grouped_conversations[$other_user_id] = [
                'user_id' => $other_user_id,
                'user_name' => $other_user,
                'user_email' => $other_email,
                'initials' => $initials,
                'messages' => [],
                'unread_count' => 0,
                'last_message_time' => 0
            ];
        }
        
        $grouped_conversations[$other_user_id]['messages'][] = $conversations[count($conversations) - 1];
        $grouped_conversations[$other_user_id]['last_message_time'] = max(
            $grouped_conversations[$other_user_id]['last_message_time'],
            $msg->timecreated
        );
        
        if ($is_received && !$msg->timeread) {
            $grouped_conversations[$other_user_id]['unread_count']++;
        }
    }
    
    // Sort grouped conversations by latest message
    uasort($grouped_conversations, function($a, $b) {
        return $b['last_message_time'] - $a['last_message_time'];
    });
    
} catch (Exception $e) {
    error_log("Communications error: " . $e->getMessage());
}

// ==================== NOTIFICATIONS ====================
$notifications = [];
$announcement_count = 0;
$assignment_count = 0;
$quiz_count = 0;
$event_count = 0;

if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        try {
            list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
            $params['timefilter'] = time() - (30 * 24 * 60 * 60);
            
            // Forum announcements
            $sql = "SELECT fp.id, fp.subject, fp.message, fp.created, fp.modified,
                           u.firstname, u.lastname, u.email,
                           f.name as forumname, c.fullname as coursename,
                           'announcement' as type
                    FROM {forum_posts} fp
                    JOIN {forum_discussions} fd ON fd.id = fp.discussion
                    JOIN {forum} f ON f.id = fd.forum
                    JOIN {course} c ON c.id = f.course
                    JOIN {user} u ON u.id = fp.userid
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE f.type = 'news'
                    AND ue.userid $insql
                    AND fp.created > :timefilter
                    ORDER BY fp.created DESC
                    LIMIT 30";
            
            $forum_posts = $DB->get_records_sql($sql, $params);
            
            foreach ($forum_posts as $post) {
                $author_obj = (object)[
                    'firstname' => $post->firstname ?? '',
                    'lastname' => $post->lastname ?? ''
                ];
                $author_name = fullname($author_obj);
                $author_initials = '';
                if (!empty($post->firstname) && !empty($post->lastname)) {
                    $author_initials = strtoupper(substr($post->firstname, 0, 1) . substr($post->lastname, 0, 1));
                }
                
                $notifications[] = [
                    'id' => 'announcement_' . $post->id,
                    'title' => $post->subject,
                    'message' => strip_tags($post->message),
                    'time' => $post->created,
                    'type' => 'announcement',
                    'author' => $author_name,
                    'author_initials' => $author_initials,
                    'author_email' => $post->email,
                    'course' => $post->coursename,
                    'icon' => 'bullhorn',
                    'color' => '#3b82f6',
                    'bg_color' => '#eff6ff',
                    'priority' => 'high'
                ];
                $announcement_count++;
            }
        } catch (Exception $e) {
            error_log("Forum posts error: " . $e->getMessage());
        }
        
        // Assignment deadlines
        try {
            list($insql2, $params2) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
            $params2['now'] = time();
            $params2['future'] = time() + (14 * 24 * 60 * 60);
            
            $sql = "SELECT a.id, a.name, a.duedate, c.fullname as coursename,
                           u.firstname, u.lastname,
                           'assignment' as type
                    FROM {assign} a
                    JOIN {course} c ON c.id = a.course
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    JOIN {user} u ON u.id = ue.userid
                    LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = u.id
                    WHERE ue.userid $insql2
                    AND a.duedate > :now
                    AND a.duedate < :future
                    AND a.duedate > 0
                    AND (asub.status IS NULL OR asub.status != 'submitted')
                    ORDER BY a.duedate ASC
                    LIMIT 25";
            
            $assignments = $DB->get_records_sql($sql, $params2);
            
            foreach ($assignments as $assign) {
                $days_until = floor(($assign->duedate - time()) / 86400);
                $urgency = $days_until <= 2 ? 'urgent' : ($days_until <= 5 ? 'soon' : 'normal');
                $color = $days_until <= 2 ? '#ef4444' : ($days_until <= 5 ? '#f59e0b' : '#10b981');
                
                $student_obj = (object)[
                    'firstname' => $assign->firstname ?? '',
                    'lastname' => $assign->lastname ?? ''
                ];
                $student_name = fullname($student_obj);
                $student_initials = '';
                if (!empty($assign->firstname) && !empty($assign->lastname)) {
                    $student_initials = strtoupper(substr($assign->firstname, 0, 1) . substr($assign->lastname, 0, 1));
                }
                
                $notifications[] = [
                    'id' => 'assign_' . $assign->id,
                    'title' => 'Assignment Due: ' . $assign->name,
                    'message' => 'Due in ' . $days_until . ' day' . ($days_until != 1 ? 's' : '') . ' for ' . $student_name,
                    'time' => $assign->duedate,
                    'type' => 'assignment',
                    'author' => $student_name,
                    'author_initials' => $student_initials,
                    'course' => $assign->coursename,
                    'icon' => 'file-alt',
                    'color' => $color,
                    'bg_color' => $days_until <= 2 ? '#fee2e2' : ($days_until <= 5 ? '#fef3c7' : '#d1fae5'),
                    'urgency' => $urgency,
                    'days_until' => $days_until,
                    'priority' => $urgency === 'urgent' ? 'high' : 'normal'
                ];
                $assignment_count++;
            }
        } catch (Exception $e) {
            error_log("Assignments error: " . $e->getMessage());
        }
        
        // Quiz deadlines
        try {
            list($insql3, $params3) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
            $params3['now'] = time();
            $params3['future'] = time() + (14 * 24 * 60 * 60);
            
            $sql = "SELECT q.id, q.name, q.timeclose, c.fullname as coursename,
                           u.firstname, u.lastname
                    FROM {quiz} q
                    JOIN {course} c ON c.id = q.course
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    JOIN {user} u ON u.id = ue.userid
                    WHERE ue.userid $insql3
                    AND q.timeclose > :now
                    AND q.timeclose < :future
                    AND q.timeclose > 0
                    ORDER BY q.timeclose ASC
                    LIMIT 20";
            
            $quizzes = $DB->get_records_sql($sql, $params3);
            
            foreach ($quizzes as $quiz) {
                $days_until = floor(($quiz->timeclose - time()) / 86400);
                $color = $days_until <= 2 ? '#ef4444' : ($days_until <= 5 ? '#f59e0b' : '#8b5cf6');
                
                $student_obj = (object)[
                    'firstname' => $quiz->firstname ?? '',
                    'lastname' => $quiz->lastname ?? ''
                ];
                $student_name = fullname($student_obj);
                $student_initials = '';
                if (!empty($quiz->firstname) && !empty($quiz->lastname)) {
                    $student_initials = strtoupper(substr($quiz->firstname, 0, 1) . substr($quiz->lastname, 0, 1));
                }
                
                $notifications[] = [
                    'id' => 'quiz_' . $quiz->id,
                    'title' => 'Quiz Due: ' . $quiz->name,
                    'message' => 'Closes in ' . $days_until . ' day' . ($days_until != 1 ? 's' : '') . ' for ' . $student_name,
                    'time' => $quiz->timeclose,
                    'type' => 'quiz',
                    'author' => $student_name,
                    'author_initials' => $student_initials,
                    'course' => $quiz->coursename,
                    'icon' => 'clipboard-check',
                    'color' => $color,
                    'bg_color' => $days_until <= 2 ? '#fee2e2' : ($days_until <= 5 ? '#fef3c7' : '#faf5ff'),
                    'days_until' => $days_until,
                    'priority' => $days_until <= 2 ? 'high' : 'normal'
                ];
                $quiz_count++;
        }
        } catch (Exception $e) {
            error_log("Quizzes error: " . $e->getMessage());
}

        // Calendar events
        try {
            if ($DB->get_manager()->table_exists('event')) {
                list($insql4, $params4) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
                $params4['now'] = time();
                $params4['future'] = time() + (7 * 24 * 60 * 60);
                
                $sql = "SELECT ev.id, ev.name, ev.description, ev.timestart, ev.courseid,
                               c.fullname as coursename
                        FROM {event} ev
                        LEFT JOIN {course} c ON c.id = ev.courseid
                        WHERE ev.userid $insql4
                        AND ev.timestart > :now
                        AND ev.timestart < :future
                        AND ev.eventtype != 'parent_teacher_meeting'
                        ORDER BY ev.timestart ASC
                        LIMIT 15";
                
                $events = $DB->get_records_sql($sql, $params4);
                
                foreach ($events as $event) {
                    $days_until = floor(($event->timestart - time()) / 86400);
                    $notifications[] = [
                        'id' => 'event_' . $event->id,
                        'title' => 'Upcoming: ' . $event->name,
                        'message' => strip_tags($event->description ?? 'Scheduled in ' . $days_until . ' day' . ($days_until != 1 ? 's' : '')),
                        'time' => $event->timestart,
                        'type' => 'event',
                        'author' => '',
                        'author_initials' => '',
                        'course' => $event->coursename ?? 'General',
                        'icon' => 'calendar-alt',
                        'color' => '#14b8a6',
                        'bg_color' => '#f0fdfa',
                        'days_until' => $days_until,
                        'priority' => 'normal'
                ];
                    $event_count++;
                }
            }
        } catch (Exception $e) {
            error_log("Events error: " . $e->getMessage());
        }
    }
}

// Sort notifications by priority then time
usort($notifications, function($a, $b) {
    if ($a['priority'] === 'high' && $b['priority'] !== 'high') return -1;
    if ($a['priority'] !== 'high' && $b['priority'] === 'high') return 1;
    return $b['time'] - $a['time'];
});

$unread_notifications_count = count(array_filter($notifications, function($n) {
    return $n['priority'] === 'high';
}));

// ==================== TEACHER MEETINGS ====================
require_once(__DIR__ . '/../lib/parent_teacher_meetings_handler.php');
$all_meetings = get_parent_meetings($userid, 'all');
$upcoming_meetings = [];
$past_meetings = [];
$now = time();

foreach ($all_meetings as $meeting) {
    if ($meeting['timestamp'] >= $now && $meeting['status'] === 'scheduled') {
        $upcoming_meetings[] = $meeting;
    } else {
        $past_meetings[] = $meeting;
    }
}

// Get teachers for children's courses
$teachers = [];
$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);
    $params['ctxcourse'] = CONTEXT_COURSE;
    
    $sql = "SELECT u.id AS teacherid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   u.phone1,
                   c.fullname AS coursename
              FROM {user} u
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {context} ctx ON ctx.id = ra.contextid
              JOIN {role} r ON r.id = ra.roleid
         LEFT JOIN {course} c ON c.id = ctx.instanceid
             WHERE r.shortname IN ('editingteacher', 'teacher')
               AND ctx.contextlevel = :ctxcourse
               AND u.deleted = 0
               AND u.id NOT IN (
                   SELECT DISTINCT ra_admin.userid
                   FROM {role_assignments} ra_admin
                   JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
                   WHERE r_admin.shortname IN ('manager', 'administrator')
               )
               AND c.id IN (
                    SELECT DISTINCT c2.id
                      FROM {course} c2
                      JOIN {enrol} e ON e.courseid = c2.id
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                     WHERE ue.userid $insql
               )
          ORDER BY u.firstname, u.lastname, c.fullname";
    
    try {
        $recordset = $DB->get_recordset_sql($sql, $params);
        $seen = [];
        
        foreach ($recordset as $row) {
            $id = (int)$row->teacherid;
            
            if (!isset($seen[$id])) {
                $seen[$id] = ['courses' => [], 'coursecount' => 0];
                $teacher = new stdClass();
                $teacher->id = $id;
                $teacher->firstname = $row->firstname;
                $teacher->lastname = $row->lastname;
                $teacher->email = $row->email;
                $teacher->phone1 = $row->phone1;
                $teacher->courses = '';
                $teacher->course_count = 0;
                $teachers[$id] = $teacher;
            }
            
            if (!empty($row->coursename) && !in_array($row->coursename, $seen[$id]['courses'], true)) {
                $seen[$id]['courses'][] = $row->coursename;
                $seen[$id]['coursecount']++;
            }
        }
        
        foreach ($seen as $teacherid => $courseinfo) {
            $teachers[$teacherid]->courses = implode('|||', $courseinfo['courses']);
            $teachers[$teacherid]->course_count = $courseinfo['coursecount'];
        }
        
        $recordset->close();
        uasort($teachers, static function($a, $b) {
            return strcasecmp(fullname($a), fullname($b));
        });
    } catch (Exception $e) {
        debugging('Error fetching teachers: ' . $e->getMessage());
    }
}

// ==================== EVENTS ====================
$events = [];
$events_by_timerange = [
    'today' => [],
    'tomorrow' => [],
    'this_week' => [],
    'next_week' => [],
    'this_month' => [],
    'later' => []
];
$event_stats = [
    'total' => 0,
    'today' => 0,
    'this_week' => 0,
    'this_month' => 0
];

if (!empty($target_children)) {
    list($insql1, $params1) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED, 'child1');
    list($insql2, $params2) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED, 'child2');
    
    $sql = "SELECT DISTINCT e.*, c.fullname as coursename, c.shortname as courseshort
            FROM {event} e
            LEFT JOIN {course} c ON c.id = e.courseid
            WHERE ((e.userid $insql1) OR (e.courseid IN (
                SELECT DISTINCT c2.id 
                FROM {course} c2
                JOIN {enrol} en ON en.courseid = c2.id
                JOIN {user_enrolments} ue ON ue.enrolid = en.id
                WHERE ue.userid $insql2
            )))
            AND e.timestart >= :now
            AND e.visible = 1
            ORDER BY e.timestart ASC
            LIMIT 50";
    
    $events = $DB->get_records_sql($sql, array_merge(
        $params1,
        $params2,
        ['now' => time()]
    ));
    
    $today_start = strtotime('today');
    $today_end = strtotime('tomorrow') - 1;
    $tomorrow_start = strtotime('tomorrow');
    $tomorrow_end = strtotime('+2 days') - 1;
    $week_end = strtotime('+7 days');
    $next_week_end = strtotime('+14 days');
    $month_end = strtotime('+30 days');
    
    foreach ($events as $event) {
        $event_stats['total']++;
        
        if ($event->timestart >= $today_start && $event->timestart <= $today_end) {
            $events_by_timerange['today'][] = $event;
            $event_stats['today']++;
            $event_stats['this_week']++;
            $event_stats['this_month']++;
        } elseif ($event->timestart >= $tomorrow_start && $event->timestart <= $tomorrow_end) {
            $events_by_timerange['tomorrow'][] = $event;
            $event_stats['this_week']++;
            $event_stats['this_month']++;
        } elseif ($event->timestart <= $week_end) {
            $events_by_timerange['this_week'][] = $event;
            $event_stats['this_week']++;
            $event_stats['this_month']++;
        } elseif ($event->timestart <= $next_week_end) {
            $events_by_timerange['next_week'][] = $event;
            $event_stats['this_month']++;
        } elseif ($event->timestart <= $month_end) {
            $events_by_timerange['this_month'][] = $event;
            $event_stats['this_month']++;
        } else {
            $events_by_timerange['later'][] = $event;
        }
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
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

/* Enhanced Communications Hub */
.parent-main-content {
    margin-left: 280px;
    padding: 25px 30px;
    min-height: 100vh;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #ffffff 100%);
    animation: fadeIn 0.5s ease-in-out;
}

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

.communications-header {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 50%, #1d4ed8 100%);
    padding: 30px 35px;
    border-radius: 18px;
    margin-bottom: 25px;
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.35), 0 2px 8px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.communications-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    border-radius: 50%;
    animation: float 6s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(20px, 20px) rotate(5deg); }
}

.communications-header::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -5%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
    border-radius: 50%;
    animation: float 8s ease-in-out infinite reverse;
}

.communications-header h1 {
    color: white;
    font-size: 32px;
    font-weight: 800;
    margin: 0 0 10px 0;
    position: relative;
    z-index: 2;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 12px;
}

.communications-header h1 i {
    font-size: 36px;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    animation: pulse 2s ease-in-out infinite;
}

.communications-header p {
    color: rgba(255, 255, 255, 0.95);
    font-size: 16px;
    margin: 0;
    position: relative;
    z-index: 2;
    font-weight: 500;
}

/* Enhanced Statistics */
.stats-dashboard-comm {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    animation: fadeInUp 0.6s ease-in-out 0.2s backwards;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stat-card-comm {
    background: white;
    border-radius: 14px;
    padding: 18px 14px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    border-top: 3px solid;
    position: relative;
    overflow: hidden;
}

.stat-card-comm::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 60%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0, 0, 0, 0.02));
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.stat-card-comm:hover {
    transform: translateY(-5px) scale(1.02);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.stat-card-comm:hover::before {
    opacity: 1;
}

.stat-icon-comm {
    font-size: 28px;
    margin-bottom: 8px;
    display: inline-block;
}

.stat-value-comm {
    font-size: 28px;
    font-weight: 700;
    margin: 5px 0;
    line-height: 1;
}

.stat-label-comm {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 700;
}

/* Modern Tabs */
.tabs-container-modern {
    background: white;
    border-radius: 18px;
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.12), 0 2px 8px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    border: 2px solid #e0f2fe;
    animation: fadeInUp 0.6s ease-in-out 0.4s backwards;
}

.tabs-header-modern {
    display: flex;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border-bottom: 3px solid #e0f2fe;
    padding: 12px;
    gap: 12px;
}

.tab-button-modern {
    flex: 1;
    padding: 18px 28px;
    border: none;
    background: transparent;
    font-size: 16px;
    font-weight: 700;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    border-radius: 12px;
}

.tab-button-modern:hover {
    background: #eff6ff;
    color: #3b82f6;
    transform: translateY(-2px);
}

.tab-button-modern.active {
    color: white;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
}

.tab-button-modern i {
    font-size: 22px;
}

.tab-badge-modern {
    background: #ef4444;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    min-width: 26px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.tab-button-modern.active .tab-badge-modern {
    background: white;
    color: #3b82f6;
}

.tab-content-modern {
    display: none;
    padding: 35px;
    min-height: 400px;
    animation: fadeIn 0.4s ease-in-out;
}

.tab-content-modern.active {
    display: block;
}

/* Conversation Groups */
.conversation-group {
    background: white;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    border-left: 5px solid #3b82f6;
    transition: all 0.3s;
    cursor: pointer;
}

.conversation-group:hover {
    transform: translateX(8px);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15);
}

.conversation-group.has-unread {
    border-left-color: #ef4444;
    background: linear-gradient(135deg, #fef2f2, #ffffff);
}

.conversation-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 12px;
}

.conversation-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    font-weight: 800;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.conversation-info {
    flex: 1;
    min-width: 0;
}

.conversation-name {
    font-size: 18px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 4px 0;
}

.conversation-email {
    font-size: 13px;
    color: #6b7280;
    font-weight: 600;
}

.conversation-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.conversation-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.messages-preview {
    margin-left: 76px;
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
}

.message-preview-item {
    padding: 10px 12px;
    background: #fafbfc;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 14px;
    color: #4b5563;
    line-height: 1.5;
}

.message-preview-item .time {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
}

/* Enhanced Message Cards */
.message-card-modern {
    background: white;
    border-radius: 14px;
    padding: 26px;
    margin-bottom: 18px;
    border-left: 5px solid;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.06);
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.message-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0, 0, 0, 0.02));
    pointer-events: none;
}

.message-card-modern:hover {
    transform: translateX(10px);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
}

.message-card-modern.received {
    border-left-color: #3b82f6;
}

.message-card-modern.sent {
    border-left-color: #10b981;
}

.message-card-modern.unread {
    background: linear-gradient(135deg, #eff6ff, #ffffff);
    border-left-width: 6px;
    border-left-color: #ef4444;
    box-shadow: 0 4px 16px rgba(239, 68, 68, 0.15);
}

.message-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    font-weight: 800;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
}

.message-subject {
    font-size: 19px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 10px 0;
    line-height: 1.4;
}

.message-excerpt {
    font-size: 15px;
    color: #6b7280;
    margin: 0 0 14px 0;
    line-height: 1.7;
}

.message-badges {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.badge-modern {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Enhanced Notification Cards */
.notification-card-modern {
    background: white;
    border-radius: 14px;
    padding: 26px;
    margin-bottom: 18px;
    border-left: 5px solid;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.06);
    transition: all 0.3s;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 22px;
    align-items: start;
}

.notification-card-modern:hover {
    transform: translateY(-6px);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
}

.notification-card-modern.priority-high {
    border-left-width: 6px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.notification-icon-modern {
    width: 64px;
    height: 64px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.notification-title-modern {
    font-size: 18px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 12px 0;
    line-height: 1.4;
}

.notification-message-modern {
    font-size: 15px;
    color: #4b5563;
    margin: 0 0 14px 0;
    line-height: 1.7;
}

.notification-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 13px;
    color: #6b7280;
    font-weight: 600;
}

.notification-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.notification-urgency {
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
    text-align: center;
    min-width: 90px;
    letter-spacing: 0.5px;
}

.urgency-urgent {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    animation: shake 0.5s infinite;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-2px); }
    75% { transform: translateX(2px); }
}

.urgency-soon {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

.urgency-normal {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

/* Filter Section */
.filter-section-comm {
    background: white;
    padding: 25px;
    border-radius: 16px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
    border: 2px solid #e0f2fe;
}

.filter-header-comm {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.filter-badge-comm {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 10px 18px;
    border-radius: 12px;
    font-weight: 800;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-input-comm {
    flex: 1;
    padding: 14px 20px;
    border: 2px solid #e0f2fe;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 500;
    transition: all 0.2s;
}

.filter-input-comm:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.filter-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-btn-comm {
    padding: 12px 20px;
    border: 2px solid #e0f2fe;
    background: white;
    color: #3b82f6;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.filter-btn-comm:hover {
    background: #eff6ff;
    border-color: #3b82f6;
    transform: translateY(-2px);
}

.filter-btn-comm.active {
    background: #3b82f6;
    color: white;
}

/* Empty State */
.empty-state-comm {
    text-align: center;
    padding: 80px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.empty-icon-comm {
    font-size: 90px;
    color: #d1d5db;
    margin-bottom: 28px;
    display: inline-block;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-12px); }
}

.empty-title-comm {
    font-size: 30px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 14px 0;
}

.empty-text-comm {
    font-size: 17px;
    color: #6b7280;
    margin: 0 0 32px 0;
    line-height: 1.6;
}

.btn-primary-comm {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 16px 36px;
    border-radius: 14px;
    text-decoration: none;
    font-weight: 800;
    font-size: 17px;
    transition: all 0.3s;
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
}

.btn-primary-comm:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 28px rgba(59, 130, 246, 0.4);
    color: white;
    text-decoration: none;
}

/* Collapse Headers */
.collapsible-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    cursor: pointer;
    padding: 16px 20px;
    background: linear-gradient(135deg, #eff6ff, #ffffff);
    border-radius: 12px;
    border: 2px solid #e0f2fe;
    transition: all 0.3s;
}

.collapsible-header:hover {
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
    border-color: #3b82f6;
}

.section-title-with-count {
    font-size: 22px;
    font-weight: 800;
    color: #4b5563;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.count-badge {
    background: #3b82f6;
    color: white;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 800;
}

.collapse-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.collapse-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
}

.collapsible-content {
    overflow: hidden;
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Responsive */
@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 20px;
    }
    
    .stats-dashboard-comm {
        grid-template-columns: repeat(2, 1fr);
}
    
    .notification-card-modern {
        grid-template-columns: auto 1fr;
}
    
    .notification-urgency {
        grid-column: 1 / -1;
    }
}
</style>

<div class="parent-main-content">
    <!-- Enhanced Header -->
    <div class="communications-header">
        <h1><i class="fas fa-satellite-dish"></i> Communications Hub</h1>
        <p>Stay connected with messages, notifications, and important announcements</p>
    </div>

    <!-- Enhanced Statistics Dashboard -->
    <div class="stats-dashboard-comm">
        <div class="stat-card-comm" style="border-top-color: #3b82f6;">
            <div class="stat-icon-comm" style="color: #3b82f6;"><i class="fas fa-envelope"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo count($conversations); ?></div>
            <div class="stat-label-comm">Total Messages</div>
                </div>
        <div class="stat-card-comm" style="border-top-color: #ef4444;">
            <div class="stat-icon-comm" style="color: #ef4444;"><i class="fas fa-envelope-open"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo $unread_messages_count; ?></div>
            <div class="stat-label-comm">Unread</div>
                </div>
        <div class="stat-card-comm" style="border-top-color: #10b981;">
            <div class="stat-icon-comm" style="color: #10b981;"><i class="fas fa-paper-plane"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo $sent_messages_count; ?></div>
            <div class="stat-label-comm">Sent</div>
        </div>
        <div class="stat-card-comm" style="border-top-color: #f59e0b;">
            <div class="stat-icon-comm" style="color: #f59e0b;"><i class="fas fa-bell"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo count($notifications); ?></div>
            <div class="stat-label-comm">Notifications</div>
        </div>
        <div class="stat-card-comm" style="border-top-color: #60a5fa;">
            <div class="stat-icon-comm" style="color: #60a5fa;"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo $today_count; ?></div>
            <div class="stat-label-comm">Today</div>
        </div>
        <div class="stat-card-comm" style="border-top-color: #8b5cf6;">
            <div class="stat-icon-comm" style="color: #8b5cf6;"><i class="fas fa-calendar-week"></i></div>
            <div class="stat-value-comm" style="color: #1f2937;"><?php echo $week_count; ?></div>
            <div class="stat-label-comm">This Week</div>
                </div>
            </div>

    <!-- Enhanced Tabs Container -->
    <div class="tabs-container-modern">
                <!-- Tabs Header -->
        <div class="tabs-header-modern">
            <button class="tab-button-modern active" onclick="switchTab('conversations')">
                <i class="fas fa-comments"></i>
                <span>Conversations</span>
                <?php if (count($grouped_conversations) > 0): ?>
                <span class="tab-badge-modern"><?php echo count($grouped_conversations); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-button-modern" onclick="switchTab('messages')">
                        <i class="fas fa-envelope"></i>
                <span>All Messages</span>
                        <?php if ($unread_messages_count > 0): ?>
                <span class="tab-badge-modern"><?php echo $unread_messages_count; ?></span>
                        <?php endif; ?>
                    </button>
            <button class="tab-button-modern" onclick="switchTab('notifications')">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                        <?php if ($unread_notifications_count > 0): ?>
                <span class="tab-badge-modern"><?php echo $unread_notifications_count; ?></span>
                        <?php endif; ?>
                    </button>
            <button class="tab-button-modern" onclick="switchTab('meetings')">
                        <i class="fas fa-handshake"></i>
                        <span>Teacher Meetings</span>
                        <?php if (count($upcoming_meetings) > 0): ?>
                <span class="tab-badge-modern"><?php echo count($upcoming_meetings); ?></span>
                        <?php endif; ?>
                    </button>
            <button class="tab-button-modern" onclick="switchTab('events')">
                        <i class="fas fa-calendar-day"></i>
                        <span>Events</span>
                        <?php if ($event_stats['today'] > 0): ?>
                <span class="tab-badge-modern"><?php echo $event_stats['today']; ?></span>
                        <?php endif; ?>
                    </button>
                </div>

        <!-- Conversations Tab (NEW - Grouped by Person) -->
        <div id="conversations-tab" class="tab-content-modern active">
            <?php if (!empty($grouped_conversations)): ?>
                <div style="margin-bottom: 25px;">
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" class="btn-primary-comm">
                        <i class="fas fa-plus-circle"></i> New Message
                    </a>
                </div>

                <?php 
                $avatar_colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6'];
                foreach ($grouped_conversations as $conversation): 
                    $color_index = abs(crc32($conversation['user_name'])) % count($avatar_colors);
                    $avatar_color = $avatar_colors[$color_index];
                    $has_unread = $conversation['unread_count'] > 0;
                ?>
                <div class="conversation-group <?php echo $has_unread ? 'has-unread' : ''; ?>" 
                     onclick="toggleConversation('conv_<?php echo $conversation['user_id']; ?>')">
                    <div class="conversation-header">
                        <div class="conversation-avatar" style="background: <?php echo $avatar_color; ?>;">
                            <?php echo $conversation['initials']; ?>
                        </div>
                        <div class="conversation-info">
                            <h3 class="conversation-name"><?php echo htmlspecialchars($conversation['user_name']); ?></h3>
                            <p class="conversation-email">
                                <i class="fas fa-envelope" style="color: #3b82f6;"></i>
                                <?php echo htmlspecialchars($conversation['user_email']); ?>
                            </p>
                        </div>
                        <div class="conversation-meta">
                            <?php if ($has_unread): ?>
                            <span class="conversation-badge" style="background: #fee2e2; color: #991b1b;">
                                <i class="fas fa-circle"></i> <?php echo $conversation['unread_count']; ?> Unread
                            </span>
                            <?php endif; ?>
                            <span class="conversation-badge" style="background: #dbeafe; color: #1e40af;">
                                <?php echo count($conversation['messages']); ?> messages
                            </span>
                            <i class="fas fa-chevron-down" style="color: #3b82f6; font-size: 18px;"></i>
                        </div>
                    </div>
                    
                    <!-- Messages Preview (Collapsible) -->
                    <div id="conv_<?php echo $conversation['user_id']; ?>" class="messages-preview collapsible-content" 
                         style="max-height: 0; opacity: 0;">
                        <?php foreach (array_slice($conversation['messages'], 0, 5) as $msg): ?>
                        <div class="message-preview-item">
                            <strong style="color: <?php echo $msg['is_received'] ? '#3b82f6' : '#10b981'; ?>;">
                                <?php echo $msg['is_received'] ? 'ðŸ“© Received' : 'ðŸ“¤ Sent'; ?>:
                            </strong>
                            <?php echo htmlspecialchars($msg['subject']); ?>
                            <div class="time">
                                <i class="fas fa-clock"></i>
                                <?php echo userdate($msg['time'], '%d %b, %H:%M'); ?>
                                <?php if (!$msg['is_read'] && $msg['is_received']): ?>
                                <span style="color: #ef4444; font-weight: 800;">â€¢ UNREAD</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div style="margin-top: 12px;">
                            <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?id=<?php echo $conversation['user_id']; ?>" 
                               class="btn-primary-comm" style="font-size: 14px; padding: 10px 20px;">
                                <i class="fas fa-comment"></i> View Full Conversation
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state-comm">
                    <div class="empty-icon-comm"><i class="fas fa-comments"></i></div>
                    <h3 class="empty-title-comm">No Conversations Yet</h3>
                    <p class="empty-text-comm">Start a conversation with teachers!</p>
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" class="btn-primary-comm">
                        <i class="fas fa-plus-circle"></i> Send First Message
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- All Messages Tab -->
        <div id="messages-tab" class="tab-content-modern">
                    <?php if (!empty($conversations)): ?>
                <!-- Filter Bar -->
                <div class="filter-section-comm">
                    <div class="filter-header-comm">
                        <div class="filter-badge-comm">
                            <i class="fas fa-search"></i>
                            SEARCH & FILTER
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 15px;">
                        <input type="text" id="messageSearch" class="filter-input-comm" 
                               placeholder="ðŸ” Search messages by subject, sender, or content..." 
                               onkeyup="filterMessages()">
                    </div>
                    
                    <div class="filter-buttons">
                        <button class="filter-btn-comm active" onclick="filterMessagesByType('all')">
                            <i class="fas fa-list"></i> All
                        </button>
                        <button class="filter-btn-comm" onclick="filterMessagesByType('unread')">
                            <i class="fas fa-envelope"></i> Unread Only
                        </button>
                        <button class="filter-btn-comm" onclick="filterMessagesByType('received')">
                            <i class="fas fa-inbox"></i> Received
                        </button>
                        <button class="filter-btn-comm" onclick="filterMessagesByType('sent')">
                            <i class="fas fa-paper-plane"></i> Sent
                        </button>
                    </div>
                </div>

                <!-- Collapsible Section Header -->
                <div class="collapsible-header" onclick="toggleSection('messagesSection')">
                    <h2 class="section-title-with-count">
                        <i class="fas fa-inbox"></i>
                        Messages
                        <span class="count-badge"><?php echo count($conversations); ?></span>
                    </h2>
                    <button class="collapse-btn" id="messagesSectionBtn">
                        <i class="fas fa-chevron-up"></i>
                        <span>Collapse</span>
                    </button>
                </div>

                <div id="messagesSection" class="collapsible-content" style="max-height: none;">
                        <div style="margin-bottom: 25px;">
                        <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" class="btn-primary-comm">
                            <i class="fas fa-plus-circle"></i> Compose New Message
                            </a>
                        </div>

                    <div id="messagesContainer">
                        <?php 
                        $avatar_colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6'];
                        foreach ($conversations as $conv): 
                            $color_index = abs(crc32($conv['other_user'])) % count($avatar_colors);
                            $avatar_color = $avatar_colors[$color_index];
                        ?>
                        <div class="message-card-modern message-item <?php echo $conv['direction']; ?> <?php echo !$conv['is_read'] && $conv['is_received'] ? 'unread' : ''; ?>"
                             data-subject="<?php echo htmlspecialchars(strtolower($conv['subject'])); ?>"
                             data-sender="<?php echo htmlspecialchars(strtolower($conv['other_user'])); ?>"
                             data-message="<?php echo htmlspecialchars(strtolower($conv['message'])); ?>"
                             data-read="<?php echo $conv['is_read'] ? '1' : '0'; ?>"
                             data-direction="<?php echo $conv['direction']; ?>">
                            <div style="display: flex; gap: 22px; align-items: start;">
                                <!-- Avatar -->
                                <div class="message-avatar" style="background: <?php echo $avatar_color; ?>;">
                                    <?php echo $conv['initials']; ?>
                                </div>
                                
                                <!-- Content -->
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                            <h4 class="message-subject">
                                                <?php if (!$conv['is_read'] && $conv['is_received']): ?>
                                                <span style="display: inline-block; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; margin-right: 8px; animation: pulse 2s infinite;"></span>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($conv['subject']); ?>
                                            </h4>
                                            <div style="font-size: 14px; color: #3b82f6; font-weight: 700; margin-bottom: 8px;">
                                                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($conv['other_user']); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right; margin-left: 20px;">
                                            <div style="font-size: 13px; color: #6b7280; font-weight: 700; white-space: nowrap;">
                                                <i class="fas fa-clock"></i> <?php echo userdate($conv['time'], '%d %b, %H:%M'); ?>
                                            </div>
                                            <?php
                                            $time_ago = time() - $conv['time'];
                                            if ($time_ago < 3600) {
                                                $ago_text = floor($time_ago / 60) . 'm ago';
                                                $ago_color = '#10b981';
                                            } elseif ($time_ago < 86400) {
                                                $ago_text = floor($time_ago / 3600) . 'h ago';
                                                $ago_color = '#3b82f6';
                                            } else {
                                                $ago_text = floor($time_ago / 86400) . 'd ago';
                                                $ago_color = '#6b7280';
                                            }
                                            ?>
                                            <div style="font-size: 11px; color: <?php echo $ago_color; ?>; font-weight: 800; margin-top: 4px;">
                                                <?php echo $ago_text; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <p class="message-excerpt">
                                        <?php echo htmlspecialchars(substr($conv['message'], 0, 220)) . (strlen($conv['message']) > 220 ? '...' : ''); ?>
                                    </p>
                                    
                                    <div class="message-badges">
                                        <?php if ($conv['direction'] == 'received'): ?>
                                        <span class="badge-modern" style="background: #dbeafe; color: #1e40af;">
                                            <i class="fas fa-arrow-down"></i> Received
                                        </span>
                                        <?php else: ?>
                                        <span class="badge-modern" style="background: #d1fae5; color: #065f46;">
                                            <i class="fas fa-arrow-up"></i> Sent
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!$conv['is_read'] && $conv['is_received']): ?>
                                        <span class="badge-modern" style="background: #fee2e2; color: #991b1b;">
                                            <i class="fas fa-circle"></i> Unread
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($conv['priority'] === 'high'): ?>
                                        <span class="badge-modern" style="background: #fef3c7; color: #92400e;">
                                            <i class="fas fa-star"></i> Long
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="noMessagesResults" class="empty-state-comm" style="display: none;">
                        <div class="empty-icon-comm"><i class="fas fa-search"></i></div>
                        <h3 class="empty-title-comm">No Messages Found</h3>
                        <p class="empty-text-comm">Try adjusting your search or filter</p>
                    </div>
                </div>
                    <?php else: ?>
                <div class="empty-state-comm">
                    <div class="empty-icon-comm"><i class="fas fa-envelope-open"></i></div>
                    <h3 class="empty-title-comm">No Messages Yet</h3>
                    <p class="empty-text-comm">You don't have any messages. Start a conversation with teachers!</p>
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" class="btn-primary-comm">
                        <i class="fas fa-plus-circle"></i> Send First Message
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

        <!-- Notifications Tab -->
        <div id="notifications-tab" class="tab-content-modern">
                    <?php if (!empty($notifications)): ?>
                <!-- Filter Bar -->
                <div class="filter-section-comm">
                    <div class="filter-header-comm">
                        <div class="filter-badge-comm">
                            <i class="fas fa-filter"></i>
                            FILTER NOTIFICATIONS
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 15px;">
                        <input type="text" id="notifSearch" class="filter-input-comm" 
                               placeholder="ðŸ” Search notifications..." 
                               onkeyup="filterNotifications()">
                    </div>
                    
                    <div class="filter-buttons">
                        <button class="filter-btn-comm active" onclick="filterNotifByType('all')">
                            <i class="fas fa-th"></i> All
                        </button>
                        <button class="filter-btn-comm" onclick="filterNotifByType('announcement')">
                            <i class="fas fa-bullhorn"></i> Announcements (<?php echo $announcement_count; ?>)
                        </button>
                        <button class="filter-btn-comm" onclick="filterNotifByType('assignment')">
                            <i class="fas fa-file-alt"></i> Assignments (<?php echo $assignment_count; ?>)
                        </button>
                        <button class="filter-btn-comm" onclick="filterNotifByType('quiz')">
                            <i class="fas fa-clipboard-check"></i> Quizzes (<?php echo $quiz_count; ?>)
                        </button>
                        <button class="filter-btn-comm" onclick="filterNotifByType('event')">
                            <i class="fas fa-calendar-alt"></i> Events (<?php echo $event_count; ?>)
                        </button>
                    </div>
                </div>

                <!-- Collapsible Section Header -->
                <div class="collapsible-header" onclick="toggleSection('notificationsSection')">
                    <h2 class="section-title-with-count">
                        <i class="fas fa-bell"></i>
                        Active Notifications
                        <span class="count-badge"><?php echo count($notifications); ?></span>
                    </h2>
                    <button class="collapse-btn" id="notificationsSectionBtn">
                        <i class="fas fa-chevron-up"></i>
                        <span>Collapse</span>
                    </button>
                </div>

                <div id="notificationsSection" class="collapsible-content" style="max-height: none;">
                    <div id="notificationsContainer">
                        <?php foreach ($notifications as $notif): ?>
                        <div class="notification-card-modern notif-item priority-<?php echo $notif['priority']; ?>" 
                             data-type="<?php echo $notif['type']; ?>"
                             data-search="<?php echo htmlspecialchars(strtolower($notif['title'] . ' ' . $notif['message'])); ?>"
                             style="border-left-color: <?php echo $notif['color']; ?>;">
                            
                            <!-- Icon -->
                            <div class="notification-icon-modern" style="background: <?php echo $notif['color']; ?>;">
                                <i class="fas fa-<?php echo $notif['icon']; ?>"></i>
                            </div>
                            
                            <!-- Content -->
                            <div style="flex: 1; min-width: 0;">
                                <h4 class="notification-title-modern">
                                    <?php if ($notif['priority'] === 'high'): ?>
                                    <span style="color: #ef4444; margin-right: 6px;">
                                        <i class="fas fa-exclamation-circle"></i>
                                    </span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </h4>
                                <p class="notification-message-modern">
                                    <?php echo htmlspecialchars(substr($notif['message'], 0, 280)) . (strlen($notif['message']) > 280 ? '...' : ''); ?>
                                </p>
                                <div class="notification-meta">
                                    <span>
                                        <i class="fas fa-clock"></i>
                                        <?php echo userdate($notif['time'], '%d %b, %H:%M'); ?>
                                    </span>
                                    <?php if (!empty($notif['course'])): ?>
                                    <span>
                                        <i class="fas fa-book"></i>
                                        <?php echo htmlspecialchars($notif['course']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($notif['author'])): ?>
                                    <span>
                                        <i class="fas fa-user"></i>
                                        <?php echo htmlspecialchars($notif['author']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Urgency Badge -->
                            <?php if (isset($notif['urgency'])): ?>
                            <div class="notification-urgency urgency-<?php echo $notif['urgency']; ?>">
                                <?php if ($notif['urgency'] == 'urgent'): ?>
                                    <i class="fas fa-exclamation-triangle"></i> URGENT
                                <?php elseif ($notif['urgency'] == 'soon'): ?>
                                    <i class="fas fa-clock"></i> SOON
                                <?php else: ?>
                                    <i class="fas fa-check-circle"></i> <?php echo $notif['days_until']; ?> days
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="noNotifResults" class="empty-state-comm" style="display: none;">
                        <div class="empty-icon-comm"><i class="fas fa-search"></i></div>
                        <h3 class="empty-title-comm">No Notifications Found</h3>
                        <p class="empty-text-comm">Try adjusting your search or filter</p>
                    </div>
                </div>
                    <?php else: ?>
                <div class="empty-state-comm">
                    <div class="empty-icon-comm"><i class="fas fa-bell-slash"></i></div>
                    <h3 class="empty-title-comm">No Notifications</h3>
                    <p class="empty-text-comm">You're all caught up! No new notifications at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>

        <!-- Teacher Meetings Tab -->
        <div id="meetings-tab" class="tab-content-modern">
            <?php if (!empty($children)): ?>
                <!-- Statistics -->
                <div class="stats-dashboard-comm" style="margin-bottom: 30px;">
                    <div class="stat-card-comm" style="border-top-color: #60a5fa;">
                        <div class="stat-icon-comm" style="color: #60a5fa;"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="stat-value-comm"><?php echo count($teachers); ?></div>
                        <div class="stat-label-comm">Teachers</div>
                    </div>
                    <div class="stat-card-comm" style="border-top-color: #10b981;">
                        <div class="stat-icon-comm" style="color: #10b981;"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-value-comm"><?php echo count($upcoming_meetings); ?></div>
                        <div class="stat-label-comm">Upcoming</div>
                    </div>
                    <div class="stat-card-comm" style="border-top-color: #3b82f6;">
                        <div class="stat-icon-comm" style="color: #3b82f6;"><i class="fas fa-history"></i></div>
                        <div class="stat-value-comm"><?php echo count($past_meetings); ?></div>
                        <div class="stat-label-comm">Past</div>
                    </div>
                </div>

                <!-- Meeting Tabs -->
                <div style="display: flex; gap: 15px; margin-bottom: 25px; background: white; padding: 10px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                    <button class="filter-btn-comm active" onclick="switchMeetingTab('teachers')" style="flex: 1;">
                        <i class="fas fa-users"></i> Teachers
                    </button>
                    <button class="filter-btn-comm" onclick="switchMeetingTab('upcoming')" style="flex: 1;">
                        <i class="fas fa-calendar-plus"></i> Upcoming (<?php echo count($upcoming_meetings); ?>)
                    </button>
                    <button class="filter-btn-comm" onclick="switchMeetingTab('past')" style="flex: 1;">
                        <i class="fas fa-history"></i> Past (<?php echo count($past_meetings); ?>)
                    </button>
                </div>

                <!-- Teachers Section -->
                <div id="meetings-teachers" class="meeting-tab-content" style="display: block;">
                    <?php if (!empty($teachers)): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                            <?php 
                            $avatar_colors = ['#3b82f6', '#60a5fa', '#2563eb', '#1d4ed8', '#93c5fd', '#7dd3fc'];
                            $color_index = 0;
                            foreach ($teachers as $teacher): 
                                $courses_array = explode('|||', $teacher->courses);
                                $initials = strtoupper(substr($teacher->firstname, 0, 1) . substr($teacher->lastname, 0, 1));
                                $avatar_color = $avatar_colors[$color_index++ % count($avatar_colors)];
                            ?>
                            <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 2px solid #f3f4f6;">
                                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                    <div style="width: 50px; height: 50px; border-radius: 50%; background: <?php echo $avatar_color; ?>; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px;">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div style="flex: 1;">
                                        <h4 style="margin: 0 0 5px 0; font-size: 18px; font-weight: 700; color: #1f2937;">
                                            <?php echo htmlspecialchars(fullname($teacher)); ?>
                                        </h4>
                                        <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                            <?php echo $teacher->course_count; ?> Course<?php echo $teacher->course_count != 1 ? 's' : ''; ?>
                                        </p>
                                    </div>
                                </div>
                                <div style="margin-bottom: 15px; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                    <div style="font-size: 13px; color: #4b5563; margin-bottom: 8px;">
                                        <i class="fas fa-envelope" style="color: #3b82f6;"></i> <?php echo htmlspecialchars($teacher->email); ?>
                                    </div>
                                    <?php if ($teacher->phone1): ?>
                                    <div style="font-size: 13px; color: #4b5563;">
                                        <i class="fas fa-phone" style="color: #3b82f6;"></i> <?php echo htmlspecialchars($teacher->phone1); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <button class="btn-primary-comm" style="font-size: 13px; padding: 10px;" onclick='openScheduleModal(<?php echo json_encode([
                                        "id" => $teacher->id,
                                        "name" => fullname($teacher),
                                        "email" => $teacher->email
                                    ]); ?>)'>
                                        <i class="fas fa-calendar-plus"></i> Schedule
                                    </button>
                                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?id=<?php echo $teacher->id; ?>" 
                                       class="btn-primary-comm" style="font-size: 13px; padding: 10px; text-align: center; background: white; color: #3b82f6; border: 2px solid #3b82f6;">
                                        <i class="fas fa-comment"></i> Message
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-comm">
                            <div class="empty-icon-comm"><i class="fas fa-chalkboard-teacher"></i></div>
                            <h3 class="empty-title-comm">No Teachers Found</h3>
                            <p class="empty-text-comm">No teachers are assigned to your child's courses.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upcoming Meetings Section -->
                <div id="meetings-upcoming" class="meeting-tab-content" style="display: none;">
                    <?php if (!empty($upcoming_meetings)): ?>
                        <?php foreach ($upcoming_meetings as $meeting): ?>
                        <div style="background: white; border-radius: 14px; padding: 25px; margin-bottom: 20px; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08); border-left: 5px solid #10b981;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                                <div>
                                    <h4 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #4b5563;">
                                        <?php echo htmlspecialchars($meeting['subject']); ?>
                                    </h4>
                                    <p style="margin: 0; color: #6b7280; font-size: 14px;">
                                        <i class="fas fa-user-tie"></i> with <?php echo htmlspecialchars($meeting['teacher_name']); ?>
                                    </p>
                                </div>
                                <span style="padding: 6px 14px; background: #d1fae5; color: #065f46; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                    <i class="fas fa-clock"></i> Scheduled
                                </span>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 15px; background: #f9fafb; border-radius: 10px; margin-bottom: 15px;">
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-calendar" style="color: #3b82f6;"></i>
                                    <strong><?php echo $meeting['date']; ?></strong>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-clock" style="color: #3b82f6;"></i>
                                    <?php echo $meeting['time']; ?> (<?php echo $meeting['duration']; ?> min)
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-<?php echo $meeting['type'] === 'virtual' ? 'video' : 'building'; ?>" style="color: #3b82f6;"></i>
                                    <?php echo ucfirst($meeting['type']); ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-map-marker-alt" style="color: #3b82f6;"></i>
                                    <?php echo htmlspecialchars($meeting['location']); ?>
                                </div>
                            </div>
                            <?php if (!empty($meeting['notes'])): ?>
                            <div style="background: #eff6ff; padding: 15px; border-radius: 10px; border-left: 3px solid #3b82f6; margin-bottom: 15px;">
                                <strong style="color: #1e40af;"><i class="fas fa-sticky-note"></i> Notes:</strong><br>
                                <span style="color: #4b5563;"><?php echo nl2br(htmlspecialchars($meeting['notes'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($meeting['meeting_link'])): ?>
                            <div style="margin-bottom: 15px;">
                                <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" 
                                   class="btn-primary-comm" style="display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-video"></i> Join Virtual Meeting
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-comm">
                            <div class="empty-icon-comm"><i class="fas fa-calendar-check"></i></div>
                            <h3 class="empty-title-comm">No Upcoming Meetings</h3>
                            <p class="empty-text-comm">You don't have any scheduled meetings. Schedule one from the Teachers tab!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Past Meetings Section -->
                <div id="meetings-past" class="meeting-tab-content" style="display: none;">
                    <?php if (!empty($past_meetings)): ?>
                        <?php foreach ($past_meetings as $meeting): ?>
                        <div style="background: white; border-radius: 14px; padding: 25px; margin-bottom: 20px; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08); border-left: 5px solid #6b7280; opacity: 0.9;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                                <div>
                                    <h4 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #4b5563;">
                                        <?php echo htmlspecialchars($meeting['subject']); ?>
                                    </h4>
                                    <p style="margin: 0; color: #6b7280; font-size: 14px;">
                                        <i class="fas fa-user-tie"></i> with <?php echo htmlspecialchars($meeting['teacher_name']); ?>
                                    </p>
                                </div>
                                <span style="padding: 6px 14px; background: #dbeafe; color: #1e40af; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                    <i class="fas fa-check"></i> Completed
                                </span>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 15px; background: #f9fafb; border-radius: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-calendar" style="color: #3b82f6;"></i>
                                    <strong><?php echo $meeting['date']; ?></strong>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; color: #4b5563; font-size: 14px;">
                                    <i class="fas fa-clock" style="color: #3b82f6;"></i>
                                    <?php echo $meeting['time']; ?> (<?php echo $meeting['duration']; ?> min)
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-comm">
                            <div class="empty-icon-comm"><i class="fas fa-history"></i></div>
                            <h3 class="empty-title-comm">No Past Meetings</h3>
                            <p class="empty-text-comm">Your meeting history will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-comm">
                    <div class="empty-icon-comm"><i class="fas fa-users"></i></div>
                    <h3 class="empty-title-comm">No Children Found</h3>
                    <p class="empty-text-comm">You need to be linked to your children first.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Events Tab -->
        <div id="events-tab" class="tab-content-modern">
            <div class="stats-dashboard-comm" style="margin-bottom: 30px;">
                <div class="stat-card-comm" style="border-top-color: #3b82f6;">
                    <div class="stat-icon-comm" style="color: #3b82f6;"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-value-comm"><?php echo $event_stats['total']; ?></div>
                    <div class="stat-label-comm">Total Events</div>
                </div>
                <div class="stat-card-comm" style="border-top-color: #ef4444;">
                    <div class="stat-icon-comm" style="color: #ef4444;"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-value-comm"><?php echo $event_stats['today']; ?></div>
                    <div class="stat-label-comm">Today</div>
                </div>
                <div class="stat-card-comm" style="border-top-color: #10b981;">
                    <div class="stat-icon-comm" style="color: #10b981;"><i class="fas fa-calendar-week"></i></div>
                    <div class="stat-value-comm"><?php echo $event_stats['this_week']; ?></div>
                    <div class="stat-label-comm">This Week</div>
                </div>
                <div class="stat-card-comm" style="border-top-color: #f59e0b;">
                    <div class="stat-icon-comm" style="color: #f59e0b;"><i class="fas fa-calendar"></i></div>
                    <div class="stat-value-comm"><?php echo $event_stats['this_month']; ?></div>
                    <div class="stat-label-comm">This Month</div>
                </div>
            </div>

            <?php if (!empty($events)): ?>
                <?php 
                $timerange_labels = [
                    'today' => ['label' => 'Today', 'icon' => 'fa-calendar-day', 'color' => '#ef4444'],
                    'tomorrow' => ['label' => 'Tomorrow', 'icon' => 'fa-calendar-plus', 'color' => '#f59e0b'],
                    'this_week' => ['label' => 'This Week', 'icon' => 'fa-calendar-week', 'color' => '#3b82f6'],
                    'next_week' => ['label' => 'Next Week', 'icon' => 'fa-calendar', 'color' => '#8b5cf6'],
                    'this_month' => ['label' => 'Later This Month', 'icon' => 'fa-calendar-alt', 'color' => '#10b981'],
                    'later' => ['label' => 'Later', 'icon' => 'fa-calendar-check', 'color' => '#6b7280']
                ];
                
                foreach ($timerange_labels as $range_key => $range_info):
                    if (!empty($events_by_timerange[$range_key])):
                ?>
                <div style="margin-bottom: 30px;">
                    <div style="background: linear-gradient(135deg, <?php echo $range_info['color']; ?>20, <?php echo $range_info['color']; ?>10); padding: 12px 20px; border-radius: 10px; margin-bottom: 15px; border-left: 4px solid <?php echo $range_info['color']; ?>;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas <?php echo $range_info['icon']; ?>" style="color: <?php echo $range_info['color']; ?>; font-size: 18px;"></i>
                            <strong style="color: #4b5563; font-size: 16px;"><?php echo $range_info['label']; ?></strong>
                            <span style="background: <?php echo $range_info['color']; ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                <?php echo count($events_by_timerange[$range_key]); ?> events
                            </span>
                        </div>
                    </div>
                    
                    <div style="display: grid; gap: 12px; margin-left: 20px;">
                        <?php foreach ($events_by_timerange[$range_key] as $event): 
                            $time_until = $event->timestart - time();
                            $hours_until = floor($time_until / 3600);
                            $days_until = floor($hours_until / 24);
                        ?>
                        <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid <?php echo $range_info['color']; ?>;">
                            <div style="display: flex; gap: 15px; align-items: start;">
                                <div style="background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white; padding: 12px; border-radius: 10px; text-align: center; min-width: 70px;">
                                    <div style="font-size: 28px; font-weight: 700; line-height: 1;"><?php echo date('d', $event->timestart); ?></div>
                                    <div style="font-size: 12px; opacity: 0.9; margin-top: 3px;"><?php echo date('M', $event->timestart); ?></div>
                                </div>
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 10px 0; font-size: 18px; font-weight: 700; color: #4b5563;">
                                        <?php echo htmlspecialchars($event->name); ?>
                                    </h4>
                                    <div style="display: flex; gap: 15px; flex-wrap: wrap; font-size: 13px; color: #6b7280; margin-bottom: 10px;">
                                        <span><i class="fas fa-clock"></i> <?php echo date('g:i A', $event->timestart); ?></span>
                                        <?php if ($event->coursename): ?>
                                        <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($event->coursename); ?></span>
                                        <?php endif; ?>
                                        <?php if ($event->location): ?>
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event->location); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($event->description): ?>
                                    <p style="color: #374151; font-size: 14px; line-height: 1.6; margin: 0;">
                                        <?php echo format_text($event->description, FORMAT_HTML); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            <?php else: ?>
                <div class="empty-state-comm">
                    <div class="empty-icon-comm"><i class="fas fa-calendar-times"></i></div>
                    <h3 class="empty-title-comm">No Upcoming Events</h3>
                    <p class="empty-text-comm">There are no events scheduled at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    document.querySelectorAll('.tab-content-modern').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.querySelectorAll('.tab-button-modern').forEach(btn => {
        btn.classList.remove('active');
    });
    
    document.getElementById(tabName + '-tab').classList.add('active');
    event.target.closest('.tab-button-modern').classList.add('active');
}

// Collapse/Expand Sections
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    const btn = document.getElementById(sectionId + 'Btn');
    const icon = btn.querySelector('i');
    const text = btn.querySelector('span');
    
    if (section.style.maxHeight === '0px' || section.style.maxHeight === '') {
        section.style.maxHeight = section.scrollHeight + 'px';
        section.style.opacity = '1';
        icon.className = 'fas fa-chevron-up';
        text.textContent = 'Collapse';
        btn.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
    } else {
        section.style.maxHeight = '0px';
        section.style.opacity = '0';
        icon.className = 'fas fa-chevron-down';
        text.textContent = 'Expand';
        btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
    }
}

// Toggle individual conversation
function toggleConversation(convId) {
    event.stopPropagation();
    const conv = document.getElementById(convId);
    const parent = conv.closest('.conversation-group');
    const icon = parent.querySelector('.fa-chevron-down, .fa-chevron-up');
    
    if (conv.style.maxHeight === '0px' || conv.style.maxHeight === '') {
        conv.style.maxHeight = conv.scrollHeight + 'px';
        conv.style.opacity = '1';
        conv.style.marginTop = '16px';
        if (icon) icon.className = 'fas fa-chevron-up';
    } else {
        conv.style.maxHeight = '0px';
        conv.style.opacity = '0';
        conv.style.marginTop = '0';
        if (icon) icon.className = 'fas fa-chevron-down';
    }
}

// Message Filtering
function filterMessages() {
    const searchValue = document.getElementById('messageSearch').value.toLowerCase();
    const messages = document.querySelectorAll('.message-item');
    const noResults = document.getElementById('noMessagesResults');
    const container = document.getElementById('messagesContainer');
    let visibleCount = 0;
    
    messages.forEach(msg => {
        const subject = msg.getAttribute('data-subject');
        const sender = msg.getAttribute('data-sender');
        const message = msg.getAttribute('data-message');
        
        if (subject.includes(searchValue) || sender.includes(searchValue) || message.includes(searchValue)) {
            msg.style.display = 'block';
            visibleCount++;
        } else {
            msg.style.display = 'none';
        }
    });
    
    updateMessageResults(visibleCount, messages.length, container, noResults);
}

function filterMessagesByType(type) {
    // Update active button
    document.querySelectorAll('.filter-btn-comm').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    const messages = document.querySelectorAll('.message-item');
    const noResults = document.getElementById('noMessagesResults');
    const container = document.getElementById('messagesContainer');
    let visibleCount = 0;
    
    messages.forEach(msg => {
        const isRead = msg.getAttribute('data-read');
        const direction = msg.getAttribute('data-direction');
        let show = false;
        
        if (type === 'all') show = true;
        else if (type === 'unread' && isRead === '0') show = true;
        else if (type === 'received' && direction === 'received') show = true;
        else if (type === 'sent' && direction === 'sent') show = true;
        
        if (show) {
            msg.style.display = 'block';
            visibleCount++;
        } else {
            msg.style.display = 'none';
        }
    });
    
    updateMessageResults(visibleCount, messages.length, container, noResults);
}

function updateMessageResults(visible, total, container, noResults) {
    if (visible === 0) {
        container.style.display = 'none';
        noResults.style.display = 'block';
    } else {
        container.style.display = 'block';
        noResults.style.display = 'none';
    }
}

// Notification Filtering
function filterNotifications() {
    const searchValue = document.getElementById('notifSearch').value.toLowerCase();
    const notifs = document.querySelectorAll('.notif-item');
    const noResults = document.getElementById('noNotifResults');
    const container = document.getElementById('notificationsContainer');
    let visibleCount = 0;
    
    notifs.forEach(notif => {
        const searchText = notif.getAttribute('data-search');
        if (searchText.includes(searchValue)) {
            notif.style.display = 'grid';
            visibleCount++;
        } else {
            notif.style.display = 'none';
        }
    });
    
    updateNotifResults(visibleCount, notifs.length, container, noResults);
}

function filterNotifByType(type) {
    // Update active button
    document.querySelectorAll('#notifications-tab .filter-btn-comm').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    document.getElementById('notifSearch').value = '';
    const notifs = document.querySelectorAll('.notif-item');
    const noResults = document.getElementById('noNotifResults');
    const container = document.getElementById('notificationsContainer');
    let visibleCount = 0;
    
    notifs.forEach(notif => {
        const notifType = notif.getAttribute('data-type');
        if (type === 'all' || notifType === type) {
            notif.style.display = 'grid';
            visibleCount++;
        } else {
            notif.style.display = 'none';
        }
    });
    
    updateNotifResults(visibleCount, notifs.length, container, noResults);
}

function updateNotifResults(visible, total, container, noResults) {
    if (visible === 0) {
        container.style.display = 'none';
        noResults.style.display = 'block';
    } else {
        container.style.display = 'block';
        noResults.style.display = 'none';
    }
}

// Meeting Tab Switching
function switchMeetingTab(tabName) {
    document.querySelectorAll('.meeting-tab-content').forEach(tab => {
        tab.style.display = 'none';
    });
    document.querySelectorAll('#meetings-tab .filter-btn-comm').forEach(btn => {
        btn.classList.remove('active');
    });
    document.getElementById('meetings-' + tabName).style.display = 'block';
    event.target.classList.add('active');
}

// Schedule Meeting Modal Functions
function openScheduleModal(teacher) {
    // Create modal if it doesn't exist
    if (!document.getElementById('scheduleModal')) {
        createScheduleModal();
    }
    document.getElementById('scheduleModal').classList.add('active');
    document.getElementById('modalTeacherName').textContent = teacher.name;
    document.getElementById('teacherId').value = teacher.id;
    document.getElementById('scheduleMeetingForm').reset();
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

function toggleMeetingFields() {
    const type = document.getElementById('type').value;
    const locationField = document.getElementById('locationField');
    const linkField = document.getElementById('meetingLinkField');
    
    if (type === 'virtual') {
        locationField.style.display = 'none';
        linkField.style.display = 'block';
    } else {
        locationField.style.display = 'block';
        linkField.style.display = 'none';
    }
}

function submitMeeting() {
    const form = document.getElementById('scheduleMeetingForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'create');
    formData.append('sesskey', M.cfg.sesskey);
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scheduling...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/ajax/meeting_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Meeting scheduled successfully!\n\nThe teacher will be notified.');
            closeScheduleModal();
            location.reload();
        } else {
            alert('✗ Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Schedule Meeting';
        }
    })
    .catch(error => {
        alert('✗ Error scheduling meeting. Please try again.');
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Schedule Meeting';
    });
}

function createScheduleModal() {
    const modal = document.createElement('div');
    modal.id = 'scheduleModal';
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-calendar-plus"></i> Schedule Meeting</h2>
                <p class="modal-subtitle">Book a meeting with <span id="modalTeacherName"></span></p>
            </div>
            <form id="scheduleMeetingForm" class="modal-body">
                <input type="hidden" id="teacherId" name="teacherid">
                <input type="hidden" id="childId" name="childid" value="<?php echo $selected_child ?? 0; ?>">
                <div class="form-group">
                    <label class="form-label">Meeting Subject *</label>
                    <input type="text" class="form-control" id="subject" name="subject" placeholder="e.g., Discuss Math Progress" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" placeholder="What would you like to discuss?"></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" id="date" name="date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time *</label>
                        <input type="time" class="form-control" id="time" name="time" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Duration (minutes) *</label>
                    <select class="form-control" id="duration" name="duration" required>
                        <option value="15">15 minutes</option>
                        <option value="30" selected>30 minutes</option>
                        <option value="45">45 minutes</option>
                        <option value="60">1 hour</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Meeting Type *</label>
                    <select class="form-control" id="type" name="type" required onchange="toggleMeetingFields()">
                        <option value="in-person">In-Person</option>
                        <option value="virtual">Virtual (Online)</option>
                    </select>
                </div>
                <div class="form-group" id="locationField">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-control" id="location" name="location" placeholder="e.g., School Office, Room 101">
                </div>
                <div class="form-group" id="meetingLinkField" style="display: none;">
                    <label class="form-label">Meeting Link (Zoom, Google Meet, etc.)</label>
                    <input type="url" class="form-control" id="meeting_link" name="meeting_link" placeholder="https://zoom.us/j/...">
                </div>
                <div class="form-group">
                    <label class="form-label">Additional Notes</label>
                    <textarea class="form-control" id="notes" name="notes" placeholder="Any specific topics or questions?"></textarea>
                </div>
            </form>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeScheduleModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitMeeting()">
                    <i class="fas fa-check"></i> Schedule Meeting
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Close modal on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeScheduleModal();
        }
    });
    
    // Close modal on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            closeScheduleModal();
        }
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    console.log('Communications Hub enhanced version loaded');
    
    // Initialize collapsible sections
    document.querySelectorAll('.collapsible-content').forEach(content => {
        if (content.style.maxHeight !== '0px') {
            content.style.maxHeight = 'none';
        }
    });
    
    // Add modal styles if not already present
    if (!document.getElementById('modalStyles')) {
        const style = document.createElement('style');
        style.id = 'modalStyles';
        style.textContent = `
            .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); z-index: 9999; backdrop-filter: blur(4px); }
            .modal-overlay.active { display: flex; align-items: center; justify-content: center; padding: 20px; }
            .modal-content { background: white; border-radius: 20px; padding: 0; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); }
            .modal-header { background: linear-gradient(135deg, #60a5fa, #3b82f6); padding: 30px; border-radius: 20px 20px 0 0; color: white; }
            .modal-title { font-size: 26px; font-weight: 800; margin: 0 0 8px 0; }
            .modal-subtitle { font-size: 14px; opacity: 0.9; margin: 0; }
            .modal-body { padding: 30px; }
            .form-group { margin-bottom: 20px; }
            .form-label { display: block; font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
            .form-control { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 14px; font-weight: 500; transition: all 0.2s; box-sizing: border-box; }
            .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
            textarea.form-control { resize: vertical; min-height: 80px; }
            .modal-footer { padding: 20px 30px 30px; display: flex; gap: 10px; justify-content: flex-end; }
            .btn-cancel { background: #f3f4f6; color: #374151; border: 2px solid #e5e7eb; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; }
            .btn-cancel:hover { background: #e5e7eb; }
        `;
        document.head.appendChild(style);
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





