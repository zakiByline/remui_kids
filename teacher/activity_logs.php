<?php
/**
 * Teacher Activity Logs page
 *
 * Provides teachers with a filtered view of student activity across their courses.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

require_login();

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

$context = context_system::instance();
$PAGE->set_context($context);

$coursefilter     = optional_param('courseid', 0, PARAM_INT);
$participantfilter = optional_param('participant', 'student', PARAM_ALPHA);
$dayfilter        = optional_param('day', 'all', PARAM_RAW_TRIMMED);
$eventfilter      = optional_param('event', 'all', PARAM_ALPHANUMEXT);
$search           = trim(optional_param('search', '', PARAM_NOTAGS));
$page             = optional_param('page', 0, PARAM_INT);
$userfilter       = optional_param('user', 0, PARAM_INT);
$datefilter       = optional_param('date', 0, PARAM_INT);
$activityfilter   = optional_param('modid', 0, PARAM_INT);
$actionfilter     = optional_param('modaction', '', PARAM_ALPHAEXT);
$originfilter     = optional_param('origin', '', PARAM_ALPHAEXT);
$edulevelfilter   = optional_param('edulevel', -1, PARAM_INT);

if ($page < 0) {
    $page = 0;
}

$urlparams = [
    'courseid'   => $coursefilter,
    'participant'=> $participantfilter,
    'day'        => $dayfilter,
    'event'      => $eventfilter,
];

if ($search !== '') {
    $urlparams['search'] = $search;
}
if ($page > 0) {
    $urlparams['page'] = $page;
}
$urlparams['user'] = $userfilter;
$urlparams['date'] = $datefilter;
$urlparams['modid'] = $activityfilter;
$urlparams['modaction'] = $actionfilter;
$urlparams['origin'] = $originfilter;
$urlparams['edulevel'] = $edulevelfilter;

$teacherid = $USER->id;

// Get courses where the current user is a teacher or editing teacher - Filter by school
$teacher_school_courses = theme_remui_kids_get_teacher_school_courses($teacherid, $teacher_company_id);
$teachercourseids = array_keys($teacher_school_courses);

// Guard: only teachers/editing teachers (with assigned courses) or site admins may access this page.
if (!is_siteadmin() && empty($teachercourseids)) {
    print_error('nopermissions', 'error', '', 'view student activity logs');
}

// Build course options (All courses + teacher's school courses only).
$courseoptions = [0 => 'All courses'];
if (!empty($teachercourseids)) {
    // Use already filtered school courses
    foreach ($teacher_school_courses as $course) {
        $courseoptions[$course->id] = format_string($course->fullname);
    }
}

if ($coursefilter > 0 && !in_array($coursefilter, $teachercourseids, true)) {
    $coursefilter = 0;
}

$urlparams['courseid'] = $coursefilter;
$PAGE->set_url(new moodle_url('/theme/remui_kids/teacher/activity_logs.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Activity Logs');
$PAGE->set_heading('Activity Logs');
$PAGE->add_body_class('teacher-activity-logs-page');

// Gather participants (students and current teacher only) linked to these courses - Filter by school
$studentids = [];
$editingteacherids = [];

if (!empty($teachercourseids)) {
    // Get students from teacher's school only (excludes admins/managers/teachers)
    foreach ($teachercourseids as $courseid) {
        $course_students = theme_remui_kids_get_course_students_by_school($courseid, $teacher_company_id);
        $course_students = theme_remui_kids_filter_out_admins($course_students, $courseid);
        
        foreach ($course_students as $student) {
            $studentids[$student->id] = $student->id;
        }
    }
    
    $studentids = array_values(array_unique($studentids));
}

// Only include the current teacher (not other teachers/admins)
$editingteacherids = [$teacherid];

$participantoptions = [
    'student' => 'Students',
    'teacher' => 'My Activity',
    'all'     => 'All participants',
];

if (!array_key_exists($participantfilter, $participantoptions)) {
    $participantfilter = 'student';
}

switch ($participantfilter) {
    case 'teacher':
        // Only show current teacher's activity
        $alloweduserids = [$teacherid];
        break;
    case 'all':
        // Show students from same school + current teacher only
        $alloweduserids = array_values(array_unique(array_merge($studentids, [$teacherid])));
        break;
    default:
        // Default: only students from same school
        $alloweduserids = $studentids;
        break;
}

// Ensure we never include other teachers/admins
if ($participantfilter !== 'teacher') {
    $alloweduserids = array_diff($alloweduserids, $editingteacherids);
    $alloweduserids = array_values(array_filter($alloweduserids));
}

$urlparams['participant'] = $participantfilter;

$alloweduserids = array_filter($alloweduserids, static function($id) {
    return !empty($id);
});

$useroptions = [0 => get_string('allparticipants')];
if (!empty($alloweduserids)) {
    $userrecords = $DB->get_records_list('user', 'id', $alloweduserids, 'lastname ASC, firstname ASC', 'id, firstname, lastname');
    foreach ($userrecords as $userrecord) {
        $useroptions[$userrecord->id] = fullname($userrecord);
    }
}
if ($userfilter > 0 && !array_key_exists($userfilter, $useroptions)) {
    $userfilter = 0;
}

$dateoptions = theme_remui_kids_teacher_date_options($teachercourseids, $coursefilter);
if (!array_key_exists($datefilter, $dateoptions)) {
    $datefilter = 0;
}

$activityoptions = theme_remui_kids_teacher_activity_options($teachercourseids, $coursefilter);
if ($activityfilter > 0 && !array_key_exists($activityfilter, $activityoptions)) {
    $activityfilter = 0;
}

$actionoptions = [
    ''    => get_string_manager()->string_exists('allactions', 'report_log') ? get_string('allactions', 'report_log') : 'All actions',
    'c'   => get_string('create'),
    'r'   => get_string('view'),
    'u'   => get_string('update'),
    'd'   => get_string('delete'),
    'cud' => get_string('allchanges'),
];
if (!array_key_exists($actionfilter, $actionoptions)) {
    $actionfilter = '';
}

$originoptions = [
    ''      => get_string('allsources', 'report_log'),
    'cli'   => get_string('cli', 'report_log'),
    'restore' => get_string('restore', 'report_log'),
    'web'   => get_string('web', 'report_log'),
    'ws'    => get_string('ws', 'report_log'),
    '---'   => get_string('other', 'report_log'),
];
if (!array_key_exists($originfilter, $originoptions)) {
    $originfilter = '';
}

$edulevellabel = theme_remui_kids_teacher_string('edulevel', 'report_log', 'Edu level');
$edulevelteacherlabel = theme_remui_kids_teacher_string('edulevelteacher', 'report_log', 'Teaching');
$edulevelparticipatinglabel = theme_remui_kids_teacher_string('edulevelparticipating', 'report_log', 'Participating');
$edulevelotherlabel = theme_remui_kids_teacher_string('edulevelother', 'report_log', 'Other');

$eduleveloptions = [
    -1 => $edulevellabel,
    \core\event\base::LEVEL_TEACHING => $edulevelteacherlabel,
    \core\event\base::LEVEL_PARTICIPATING => $edulevelparticipatinglabel,
    \core\event\base::LEVEL_OTHER => $edulevelotherlabel,
];
if (!array_key_exists($edulevelfilter, $eduleveloptions)) {
    $edulevelfilter = -1;
}

$eventgroups = [
    'all' => [
        'label' => 'All events',
        'events' => []
    ],
    'login' => [
        'label' => 'Student login',
        'events' => ['\\core\\event\\user_loggedin']
    ],
    'dashboard' => [
        'label' => 'Student view dashboard',
        'events' => ['\\core\\event\\dashboard_viewed', '\\core\\event\\my_dashboard_viewed']
    ],
    'courseview' => [
        'label' => 'Student view course',
        'events' => ['\\core\\event\\course_viewed']
    ],
    'quizsubmit' => [
        'label' => 'Quiz submission',
        'events' => ['\\mod_quiz\\event\\attempt_submitted']
    ],
    'assignsubmit' => [
        'label' => 'Assignment submission',
        'events' => ['\\mod_assign\\event\\submission_submitted', '\\mod_assign\\event\\assessable_submitted']
    ],
];

if (!array_key_exists($eventfilter, $eventgroups)) {
    $eventfilter = 'all';
}

$urlparams['event'] = $eventfilter;

$perpage = 20;
$offset  = $page * $perpage;
$totalcount = 0;
$activitylogs = [];

if (!empty($alloweduserids)) {
    $logmanager = get_log_manager();
    $readers = $logmanager->get_readers('core\log\sql_reader');

    if (!empty($readers)) {
        $reader = reset($readers);
        $conditions = [];
        $params = [];

        list($userinsql, $userparams) = $DB->get_in_or_equal($alloweduserids, SQL_PARAMS_NAMED, 'uid');
        $conditions[] = "userid $userinsql";
        $params += $userparams;

        if ($userfilter > 0) {
            $conditions[] = 'userid = :useridfilter';
            $params['useridfilter'] = $userfilter;
        }

        if (!empty($teachercourseids)) {
            if ($coursefilter > 0) {
                $conditions[] = '(courseid = :coursefilter)';
                $params['coursefilter'] = $coursefilter;
            } else {
                list($teachcourseinsql, $teachcourseparams) = $DB->get_in_or_equal($teachercourseids, SQL_PARAMS_NAMED, 'cid');
                $conditions[] = '(courseid = 0 OR courseid ' . $teachcourseinsql . ')';
                $params += $teachcourseparams;
            }
        }

        if ($datefilter > 0) {
            $conditions[] = '(timecreated BETWEEN :datefilterstart AND :datefilterend)';
            $params['datefilterstart'] = $datefilter;
            $params['datefilterend'] = $datefilter + DAYSECS;
        } else if ($dayfilter !== 'all') {
            $daystart = $dayfilter === 'today' ? usergetmidnight(time()) : strtotime($dayfilter . ' 00:00:00');
            if ($daystart) {
                $dayend = $daystart + DAYSECS - 1;
                $conditions[] = '(timecreated BETWEEN :daystart AND :dayend)';
                $params['daystart'] = $daystart;
                $params['dayend'] = $dayend;
            }
        }

        if ($activityfilter > 0) {
            $conditions[] = '(contextinstanceid = :activitycmid AND contextlevel = :activitycontextlevel)';
            $params['activitycmid'] = $activityfilter;
            $params['activitycontextlevel'] = CONTEXT_MODULE;
        }

        if ($actionfilter !== '') {
            $crudletters = str_split($actionfilter);
            list($crudsql, $crudparams) = $DB->get_in_or_equal($crudletters, SQL_PARAMS_NAMED, 'crud');
            $conditions[] = 'crud ' . $crudsql;
            $params += $crudparams;
        }

        if ($originfilter !== '') {
            if ($originfilter === '---') {
                list($originsql, $originparams) = $DB->get_in_or_equal(['cli', 'restore', 'web', 'ws'], SQL_PARAMS_NAMED, 'origin', false);
                $conditions[] = 'origin ' . $originsql;
                $params += $originparams;
            } else {
                $conditions[] = 'origin = :originfilter';
                $params['originfilter'] = $originfilter;
            }
        }

        if ($edulevelfilter >= 0) {
            $conditions[] = 'edulevel = :edulevel';
            $params['edulevel'] = $edulevelfilter;
        }

        if ($eventfilter !== 'all') {
            $eventnames = $eventgroups[$eventfilter]['events'];
            list($eventinsql, $eventparams) = $DB->get_in_or_equal($eventnames, SQL_PARAMS_NAMED, 'evt');
            $conditions[] = "eventname $eventinsql";
            $params += $eventparams;
        }

        if ($search !== '') {
            $searchlike = '%' . $DB->sql_like_escape($search) . '%';
            $searchclauses = [];

            // Search users - Filter by school and exclude admins
            $usersearchsql = "
                SELECT DISTINCT u.id
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND (" .
                $DB->sql_like('u.firstname', ':sfirstname', false, false) . ' OR ' .
                $DB->sql_like('u.lastname', ':slastname', false, false) . ' OR ' .
                $DB->sql_like('u.username', ':susername', false, false) .
            ')';
            
            // Add school filter if teacher has a company
            if ($teacher_company_id) {
                $usersearchsql .= " AND EXISTS (
                    SELECT 1 FROM {company_users} cu 
                    WHERE cu.userid = u.id AND cu.companyid = :searchcompanyid
                )";
            }
            
            // Exclude admins/managers
            $usersearchsql .= " AND u.id NOT IN (
                SELECT ra.userid 
                FROM {role_assignments} ra
                JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :searchcontextlevel
                JOIN {role} r ON r.id = ra.roleid
                WHERE r.shortname IN ('manager', 'siteadmin')
            )";
            
            // Exclude super admins
            $usersearchsql .= " AND u.id NOT IN (
                SELECT DISTINCT ra.userid
                FROM {role_assignments} ra
                JOIN {context} ctx ON ctx.id = ra.contextid
                JOIN {role} r ON r.id = ra.roleid
                WHERE ctx.contextlevel = :searchsystemcontext
                  AND r.shortname IN ('manager', 'siteadmin')
            )";
            
            $usersearchparams = [
                'sfirstname' => $searchlike,
                'slastname' => $searchlike,
                'susername' => $searchlike,
                'searchcontextlevel' => CONTEXT_COURSE,
                'searchsystemcontext' => CONTEXT_SYSTEM,
            ];
            
            if ($teacher_company_id) {
                $usersearchparams['searchcompanyid'] = $teacher_company_id;
            }
            
            $usersearchids = $DB->get_fieldset_sql($usersearchsql, $usersearchparams);

            if (!empty($usersearchids)) {
                list($searchuserinsql, $searchuserparams) = $DB->get_in_or_equal($usersearchids, SQL_PARAMS_NAMED, 'suid');
                $searchclauses[] = "userid $searchuserinsql";
                $params += $searchuserparams;
            }

            // Search courses - Only from teacher's school
            $coursesearchsql = "
                SELECT c.id
                  FROM {course} c
                 WHERE " . $DB->sql_like('c.fullname', ':scoursename', false, false);
            
            // Filter by school if teacher has a company
            if ($teacher_company_id && !empty($teachercourseids)) {
                list($schoolcourseinsql, $schoolcourseparams) = $DB->get_in_or_equal($teachercourseids, SQL_PARAMS_NAMED, 'scid');
                $coursesearchsql .= " AND c.id $schoolcourseinsql";
                $coursesearchparams = array_merge(['scoursename' => $searchlike], $schoolcourseparams);
            } else {
                $coursesearchparams = ['scoursename' => $searchlike];
            }
            
            $coursesearchids = $DB->get_fieldset_sql($coursesearchsql, $coursesearchparams);

            if (!empty($coursesearchids)) {
                list($searchcourseinsql, $searchcourseparams) = $DB->get_in_or_equal($coursesearchids, SQL_PARAMS_NAMED, 'scid');
                $searchclauses[] = "courseid $searchcourseinsql";
                $params += $searchcourseparams;
            }

            $searchclauses[] = $DB->sql_like('eventname', ':searcheventname', false, false);
            $params['searcheventname'] = $searchlike;

            if (!empty($searchclauses)) {
                $conditions[] = '(' . implode(' OR ', $searchclauses) . ')';
            }
        }

        $anonymouscontext = context_system::instance();
        if (!has_capability('moodle/site:viewanonymousevents', $anonymouscontext)) {
            $conditions[] = 'anonymous = 0';
        }

        $selector = implode(' AND ', $conditions);

        $totalcount = $reader->get_events_select_count($selector, $params);

        if ($totalcount > 0) {
            $order = 'timecreated DESC';
            $eventsiterator = $reader->get_events_select_iterator($selector, $params, $order, $offset, $perpage);
            $logevents = [];
            $useridsinlogs = [];
            $courseidsinlogs = [];

            foreach ($eventsiterator as $event) {
                $logevents[] = $event;
                if (!empty($event->userid)) {
                    $useridsinlogs[$event->userid] = true;
                }
                if (!empty($event->courseid)) {
                    $courseidsinlogs[$event->courseid] = true;
                }
            }

            $userrecords = [];
            if (!empty($useridsinlogs)) {
                $userrecords = $DB->get_records_list('user', 'id', array_keys($useridsinlogs), '',
                    'id, firstname, lastname, username');
            }

            $courserecords = [];
            if (!empty($courseidsinlogs)) {
                $courserecords = $DB->get_records_list('course', 'id', array_keys($courseidsinlogs), '',
                    'id, fullname');
            }

            foreach ($logevents as $event) {
                $data = $event->get_data();
                $logextra = $event->get_logextra();
                $user = $userrecords[$data['userid']] ?? null;
                $course = $courserecords[$data['courseid']] ?? null;
                $description = method_exists($event, 'get_description') ? $event->get_description() : '';

                $record = (object)[
                    'id' => $data['eventid'] ?? $data['contextid'],
                    'userid' => $data['userid'],
                    'timecreated' => $data['timecreated'],
                    'eventname' => $data['eventname'],
                    'component' => $data['component'],
                    'action' => $data['action'],
                    'target' => $data['target'],
                    'courseid' => $data['courseid'],
                    'contextinstanceid' => $data['contextinstanceid'],
                    'contextid' => $data['contextid'],
                    'contextlevel' => $data['contextlevel'],
                    'ip' => $logextra['ip'] ?? '',
                    'other' => $data['other'],
                    'description' => $description,
                    'firstname' => $user->firstname ?? '',
                    'lastname' => $user->lastname ?? '',
                    'username' => $user->username ?? '',
                    'coursename' => $course ? $course->fullname : '',
                ];

                $activitylogs[] = $record;
            }
        }
    }
}
// Day filter options (last 30 days + today).
$dayoptions = [
    'all'   => 'All days',
    'today' => 'Today',
];

for ($i = 0; $i < 35; $i++) {
    $timestamp = strtotime("-$i day");
    $datevalue = date('Y-m-d', $timestamp);
    $dayoptions[$datevalue] = userdate($timestamp, '%A, %e %B %Y');
}

/**
 * Safe helper for pulling strings with fallbacks.
 *
 * @param string $identifier
 * @param string $component
 * @param string $fallback
 * @return string
 */
function theme_remui_kids_teacher_string(string $identifier, string $component, string $fallback): string {
    $manager = get_string_manager();
    if ($manager->string_exists($identifier, $component)) {
        return get_string($identifier, $component);
    }
    return $fallback;
}

/**
 * Build a list of date options similar to the core log report.
 *
 * @param array $teachercourseids
 * @param int $coursefilter
 * @return array
 */
function theme_remui_kids_teacher_date_options(array $teachercourseids, int $coursefilter): array {
    global $SITE;

    $course = $SITE;
    $targetcourseid = $coursefilter ?: ($teachercourseids[0] ?? 0);
    if ($targetcourseid > 0) {
        try {
            $course = get_course($targetcourseid);
        } catch (Throwable $t) {
            $course = $SITE;
        }
    }

    if (empty($course->startdate) || $course->startdate > time()) {
        $course->startdate = $course->timecreated;
    }

    $dates = [
        0 => get_string('alldays'),
    ];

    $timenow = time();
    $strftimedate = get_string('strftimedate');
    $strftimedaydate = get_string('strftimedaydate');

    $timemidnight = usergetmidnight($timenow);
    $dates[$timemidnight] = get_string('today') . ', ' . userdate($timenow, $strftimedate);

    $numdates = 1;
    while ($timemidnight > $course->startdate && $numdates < 365) {
        $timemidnight -= DAYSECS;
        $timenow -= DAYSECS;
        $dates[$timemidnight] = userdate($timenow, $strftimedaydate);
        $numdates++;
    }

    return $dates;
}

/**
 * Build a list of activity options (course modules) available to the teacher.
 *
 * @param array $teachercourseids
 * @param int $coursefilter
 * @return array
 */
function theme_remui_kids_teacher_activity_options(array $teachercourseids, int $coursefilter): array {
    $allactivitieslabel = 'All activities';
    if (get_string_manager()->string_exists('allactivities', 'report_log')) {
        $allactivitieslabel = get_string('allactivities', 'report_log');
    }
    $courseid = $coursefilter ?: ($teachercourseids[0] ?? 0);
    $options = [0 => $allactivitieslabel];

    if (empty($courseid)) {
        return $options;
    }

    $modinfo = get_fast_modinfo($courseid);
    foreach ($modinfo->cms as $cm) {
        if (!$cm->uservisible || (!$cm->has_view() && strcmp($cm->modname, 'folder') !== 0)) {
            continue;
        }
        $name = strip_tags($cm->get_formatted_name());
        if (core_text::strlen($name) > 55) {
            $name = core_text::substr($name, 0, 50) . '...';
        }
        $options[$cm->id] = $name;
    }

    return $options;
}

/**
 * Format the event label for display.
 *
 * @param stdClass $log
 * @param array $eventgroups
 * @return string
 */
function theme_remui_kids_teacher_log_event_label(stdClass $log, array $eventgroups): array {
    $eventname = $log->eventname ?? '';
    $coursename = format_string($log->coursename ?? '');

    switch ($eventname) {
        case '\\core\\event\\user_loggedin':
            return ['label' => 'Student login', 'class' => 'event-login', 'icon' => 'fa-sign-in-alt'];
        case '\\core\\event\\dashboard_viewed':
        case '\\core\\event\\my_dashboard_viewed':
            return ['label' => 'Viewed dashboard', 'class' => 'event-dashboard', 'icon' => 'fa-tachometer-alt'];
        case '\\core\\event\\course_viewed':
            return [
                'label' => $coursename ? 'Viewed course: ' . $coursename : 'Viewed course',
                'class' => 'event-course',
                'icon' => 'fa-school'
            ];
        case '\\mod_quiz\\event\\attempt_submitted':
            return [
                'label' => $coursename ? 'Submitted quiz in ' . $coursename : 'Submitted quiz attempt',
                'class' => 'event-quiz',
                'icon' => 'fa-question-circle'
            ];
        case '\\mod_assign\\event\\submission_submitted':
        case '\\mod_assign\\event\\assessable_submitted':
            return [
                'label' => $coursename ? 'Submitted assignment in ' . $coursename : 'Submitted assignment',
                'class' => 'event-assign',
                'icon' => 'fa-file-alt'
            ];
        default:
            // Fallback to formatted event name.
            $parts = explode('\\', $eventname);
            return [
                'label' => str_replace('_', ' ', array_pop($parts)),
                'class' => 'event-other',
                'icon' => 'fa-bolt'
            ];
    }
}

/**
 * Format name with username for display.
 *
 * @param stdClass $log
 * @return string
 */
function theme_remui_kids_teacher_log_fullname(stdClass $log): string {
    $name = fullname($log);
    $username = s($log->username ?? '');
    return html_writer::span($name, 'log-name') .
        html_writer::span('username: ' . $username, 'log-username');
}

/**
 * Format timestamp for display using the viewer's timezone.
 *
 * @param int $timestamp
 * @return array{display: string, relative: string}
 */
function theme_remui_kids_teacher_log_time(int $timestamp): array {
    global $USER;

    if ($timestamp <= 0) {
        $never = get_string('never');
        return ['display' => $never, 'relative' => $never];
    }

    $timezone = core_date::get_user_timezone($USER);
    $display = userdate($timestamp, get_string('strftimedatetime', 'langconfig'), $timezone);
    $relative = format_time(max(0, time() - $timestamp));

    return ['display' => $display, 'relative' => $relative];
}

/**
 * Normalise and format the IP address for display.
 *
 * @param string $ip
 * @return string
 */
function theme_remui_kids_teacher_log_ip(string $ip): string {
    $ip = trim($ip);

    if ($ip === '' || $ip === '0.0.0.0') {
        $ip = \theme_remui_kids\local\student_activity_logger::resolve_system_ip();
    }

    if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
        $ip = '127.0.0.1';
    } else if (stripos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }

    if ($ip === '' || $ip === '0.0.0.0') {
        return '&mdash;';
    }

    $clean = cleanremoteaddr($ip);
    $display = $clean !== false ? $clean : $ip;

    return s($display);
}

/**
 * Resolve the context label for a log entry.
 *
 * @param stdClass $log
 * @return string
 */
function theme_remui_kids_teacher_log_context(stdClass $log): string {
    if (empty($log->contextid)) {
        return get_string('other');
    }

    $context = context::instance_by_id($log->contextid, IGNORE_MISSING);
    if ($context) {
        $name = $context->get_context_name(true);
        if ($url = $context->get_url()) {
            return html_writer::link($url, $name);
        }
        return $name;
    }

    if (class_exists('\report_log\helper')) {
        $fallback = \report_log\helper::get_context_fallback((object)[
            'contextid' => $log->contextid,
            'contextlevel' => $log->contextlevel ?? null,
            'contextinstanceid' => $log->contextinstanceid ?? null,
            'courseid' => $log->courseid ?? null,
        ]);
        if ($fallback) {
            return $fallback;
        }
    }

    return get_string('other');
}

/**
 * Format event description text.
 *
 * @param stdClass $log
 * @return string
 */
function theme_remui_kids_teacher_log_description(stdClass $log): string {
    $description = trim((string)($log->description ?? ''));
    if ($description === '') {
        return '&mdash;';
    }

    return format_text($description, FORMAT_PLAIN, ['noclean' => false]);
}

echo $OUTPUT->header();

// Inline CSS for page layout.
echo '<style>
#region-main,
[role=\"main\"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

body.teacher-activity-logs-page,
body.teacher-activity-logs-page #page,
body.teacher-activity-logs-page #page-content {
    background: #f8fafc !important;
}

body.teacher-activity-logs-page #page-content {
    padding: 0 !important;
}

.teacher-css-wrapper {
    min-height: 100vh;
    background: #f8fafc;
}

.teacher-dashboard-wrapper {
    display: flex;
    min-height: 100vh;
}

.teacher-main-content {
    flex: 1;
    margin-left: 280px;
    padding: 40px 48px;
    background: #f8fafc;
}

@media (max-width: 992px) {
    .teacher-main-content {
        margin-left: 0;
        padding: 24px;
    }
}

.teacher-activity-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.teacher-activity-header p {
    color: #6b7280;
    margin-top: 8px;
    font-size: 0.95rem;
}

.teacher-activity-card {
    background: #ffffff;
    border-radius: 20px;
    box-shadow: none;
    padding: 32px;
    margin-top: 28px;
    border: 1px solid #e2e8f0;
}

.teacher-activity-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.teacher-activity-filters label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: 6px;
    display: inline-block;
}

.teacher-activity-filters select,
.teacher-activity-filters input[type=\"text\"] {
    width: 100%;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid #d1d5db;
    background: #f8fafc;
    font-size: 0.95rem;
    color: #1f2937;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.teacher-activity-filters select:focus,
.teacher-activity-filters input[type=\"text\"]:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    outline: none;
}

.teacher-activity-actions {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.teacher-activity-actions button,
.teacher-activity-actions a {
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.teacher-activity-actions button {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #ffffff;
    box-shadow: 0 12px 24px rgba(99, 102, 241, 0.25);
}

.teacher-activity-actions button:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 30px rgba(99, 102, 241, 0.35);
}

.teacher-activity-actions a.reset-link {
    background: #f3f4f6;
    color: #374151;
}

.teacher-activity-actions a.reset-link:hover {
    background: #e5e7eb;
}

.search-field {
    position: relative;
}

.search-input-wrapper {
    position: relative;
}

.search-input-wrapper .search-icon {
    position: absolute;
    top: 50%;
    left: 14px;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 0.9rem;
}

.search-input-wrapper input {
    padding-left: 40px !important;
    border: 1px solid #d1d5db;
    border-radius: 12px;
}

.search-input-wrapper input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    outline: none;
}

.teacher-activity-table-wrapper {
    overflow-x: auto;
}

.teacher-activity-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 880px;
}

.teacher-activity-table thead th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #6b7280;
    background: #f9fafb;
    border-bottom: 1px solid #e2e8f0;
}

.teacher-activity-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
    color: #1f2937;
    vertical-align: top;
}

.teacher-activity-table tbody tr:hover {
    background: #f8fafc;
}

.log-name {
    display: block;
    font-weight: 600;
    color: #1f2937;
}

.log-username {
    display: block;
    font-size: 0.8rem;
    color: #6b7280;
    margin-top: 4px;
}

.log-time {
    font-weight: 600;
    color: #111827;
}

.log-time small {
    display: block;
    color: #6b7280;
    font-size: 0.78rem;
}

.event-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 999px;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 600;
    font-size: 0.9rem;
}

.event-badge .badge-icon {
    font-size: 0.85rem;
}

.event-badge.event-login {
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
}

.event-badge.event-dashboard {
    background: rgba(59, 130, 246, 0.15);
    color: #1d4ed8;
}

.event-badge.event-course {
    background: rgba(96, 165, 250, 0.15);
    color: #1e3a8a;
}

.event-badge.event-quiz {
    background: rgba(244, 114, 182, 0.18);
    color: #be185d;
}

.event-badge.event-assign {
    background: rgba(251, 191, 36, 0.22);
    color: #92400e;
}

.event-badge.event-other {
    background: rgba(99, 102, 241, 0.18);
    color: #4c1d95;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 48px;
    color: #a5b4fc;
    margin-bottom: 16px;
}

.teacher-page-empty-state {
    margin: 80px auto;
    max-width: 540px;
    text-align: center;
    background: #ffffff;
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
}

.teacher-page-empty-state .empty-state-icon {
    font-size: 48px;
    color: #6366f1;
    margin-bottom: 16px;
}

.paging {
    margin-top: 24px;
}
.teacher-activity-header,.teacher-activity-filters{
    background: white;
    padding: 20px 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    margin: 12px 0 24px;
}
.#page.drawers .main-inner {
    margin-top: 0rem !important;
    }
    .teacher-main-content, .admin-main-content {
    padding-top: 0px !important;
    }
</style>';

echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';
include(__DIR__ . '/includes/sidebar.php');
echo '<div class="teacher-main-content">';

echo '<div class="teacher-activity-header">';
echo '<h1>Activity Logs</h1>';
echo '<p>Monitor recent activity for students enrolled in your courses.</p>';
echo '</div>';

echo '<div class="teacher-activity-card">';

// Filter form.
$formurl = new moodle_url('/theme/remui_kids/teacher/activity_logs.php');
echo '<form method="get" action="' . $formurl->out(false) . '">';
echo '<input type="hidden" name="page" value="0" />';
echo '<div class="teacher-activity-filters">';

// Course filter.
echo '<div>';
echo '<label for="filter-course">Course</label>';
echo html_writer::select($courseoptions, 'courseid', $coursefilter, null, ['id' => 'filter-course']);
echo '</div>';

// Participant filter.
echo '<div>';
echo '<label for="filter-participant">Participant role</label>';
echo html_writer::select($participantoptions, 'participant', $participantfilter, null, ['id' => 'filter-participant']);
echo '</div>';

// User filter.
echo '<div>';
echo '<label for="filter-user">User</label>';
echo html_writer::select($useroptions, 'user', $userfilter, null, ['id' => 'filter-user']);
echo '</div>';

// Day filter.
echo '<div>';
echo '<label for="filter-day">Day</label>';
echo html_writer::select($dayoptions, 'day', $dayfilter, null, ['id' => 'filter-day']);
echo '</div>';

// Date selector aligned with core logs.
echo '<div>';
echo '<label for="filter-date">Date</label>';
echo html_writer::select($dateoptions, 'date', $datefilter, null, ['id' => 'filter-date']);
echo '</div>';

// Event filter.
$eventoptions = [];
foreach ($eventgroups as $key => $group) {
    $eventoptions[$key] = $group['label'];
}

echo '<div>';
echo '<label for="filter-event">Event type</label>';
echo html_writer::select($eventoptions, 'event', $eventfilter, null, ['id' => 'filter-event']);
echo '</div>';

// Activity filter.
echo '<div>';
echo '<label for="filter-activity">Activity</label>';
echo html_writer::select($activityoptions, 'modid', $activityfilter, null, ['id' => 'filter-activity']);
echo '</div>';

// Action filter.
echo '<div>';
echo '<label for="filter-action">Action</label>';
echo html_writer::select($actionoptions, 'modaction', $actionfilter, null, ['id' => 'filter-action']);
echo '</div>';

// Origin filter.
echo '<div>';
echo '<label for="filter-origin">Source</label>';
echo html_writer::select($originoptions, 'origin', $originfilter, null, ['id' => 'filter-origin']);
echo '</div>';

// Education level.
echo '<div>';
echo '<label for="filter-edulevel">' . s($edulevellabel) . '</label>';
echo html_writer::select($eduleveloptions, 'edulevel', $edulevelfilter, null, ['id' => 'filter-edulevel']);
echo '</div>';

// Search.
echo '<div class="search-field">';
echo '<label for="filter-search">Search</label>';
echo '<div class="search-input-wrapper">';
echo '<span class="search-icon"><i class="fa fa-search"></i></span>';
echo '<input type="text" id="filter-search" name="search" value="' . s($search) . '" placeholder="Search by user, username, course or event..." />';
echo '</div>';
echo '</div>';

echo '</div>'; // filters grid.

echo '<div class="teacher-activity-actions">';
echo '<button type="submit"><i class="fa fa-filter"></i> Apply filters</button>';
$reseturl = new moodle_url('/theme/remui_kids/teacher/activity_logs.php');
echo '<a class="reset-link" href="' . $reseturl->out(false) . '"><i class="fa fa-undo"></i> Reset</a>';
echo '</div>';

echo '</form>';

// Table.
echo '<div class="teacher-activity-table-wrapper">';

if ($totalcount === 0 || empty($activitylogs)) {
    echo '<div class="empty-state">';
    echo '<i class="fa fa-clipboard-list"></i>';
    echo '<p>No activity logs match your current filters.</p>';
    echo '</div>';
} else {
    echo '<table class="teacher-activity-table">';
    echo '<thead>
            <tr>
                <th>User</th>
                <th>Time</th>
                <th>Event</th>
                <th>Description</th>
                <th>Event context</th>
                <th>IP address</th>
            </tr>
        </thead>';
    echo '<tbody>';

    foreach ($activitylogs as $log) {
        $eventinfo = theme_remui_kids_teacher_log_event_label($log, $eventgroups);
        $namecell = theme_remui_kids_teacher_log_fullname($log);

        $timeinfo = theme_remui_kids_teacher_log_time((int)$log->timecreated);
        $ipaddress = theme_remui_kids_teacher_log_ip($log->ip ?? '');

        echo '<tr>';
        echo '<td>' . $namecell . '</td>';
        echo '<td class="log-time">' . s($timeinfo['display']) . '<small>' . s($timeinfo['relative']) . ' ago</small></td>';
        echo '<td><span class="event-badge ' . s($eventinfo['class']) . '"><i class="fa ' . s($eventinfo['icon']) . ' badge-icon"></i>' . s($eventinfo['label']) . '</span></td>';
        $descriptiontext = theme_remui_kids_teacher_log_description($log);
        echo '<td>' . $descriptiontext . '</td>';
        $contextlabel = theme_remui_kids_teacher_log_context($log);
        echo '<td>' . $contextlabel . '</td>';
        echo '<td>' . $ipaddress . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

echo '</div>'; // table wrapper.

// Pagination.
if ($totalcount > $perpage) {
    $pagingparams = [
        'courseid'   => $coursefilter,
        'participant'=> $participantfilter,
        'day'        => $dayfilter,
        'event'      => $eventfilter,
        'user'       => $userfilter,
        'date'       => $datefilter,
        'modid'      => $activityfilter,
        'modaction'  => $actionfilter,
        'origin'     => $originfilter,
        'edulevel'   => $edulevelfilter,
    ];
    if ($search !== '') {
        $pagingparams['search'] = $search;
    }
    $baseurl = new moodle_url('/theme/remui_kids/teacher/activity_logs.php', $pagingparams);
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);
}
echo '</div>'; // card.
echo '</div>'; // main content.
echo '</div>'; // wrapper.
echo '</div>'; // css wrapper.
echo $OUTPUT->footer();