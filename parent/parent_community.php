<?php
/**
 * Parent Community Hub page.
 *
 * Lists all parents in the organisation and surfaces their upcoming meetings
 * so families can stay informed about community engagement.
 *
 * @package     theme_remui_kids
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once(__DIR__ . '/../../../config.php');
require_login();

global $DB, $USER, $CFG, $PAGE, $OUTPUT;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_teacher_meetings_handler.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/message/lib.php');

$context = context_system::instance();
$system_context = $context;
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_community.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Parent Community Hub - Connect, Collaborate & Stay Informed');
$PAGE->set_heading('Parent Community Hub');

$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
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
    print_error('nopermissions', 'error', '', get_string('parent'));
}

$trim_text = static function($text, $limit = 160) {
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if ($clean === '') {
        return '';
    }
    return core_text::strlen($clean) > $limit ? rtrim(core_text::substr($clean, 0, $limit)) . '…' : $clean;
};

$my_children = get_parent_children($USER->id);
$my_child_ids = array_map(static function($child) {
    return (int)$child['id'];
}, $my_children);

// Create child ID to name mapping for display
$child_id_to_name = [];
foreach ($my_children as $child) {
    $child_id_to_name[(int)$child['id']] = $child['name'];
}

$my_courses_map = [];
$my_course_ids = [];
if (!empty($my_child_ids)) {
    foreach ($my_child_ids as $child_id) {
        $enrolled = enrol_get_users_courses($child_id, true, 'id, fullname, shortname, summary');
        foreach ($enrolled as $course) {
            if (!isset($my_courses_map[$course->id])) {
                $my_courses_map[$course->id] = $course;
                $my_course_ids[] = (int)$course->id;
            }
        }
    }
}

$announcements = [];
$teacher_updates = [];
$community_feed = [];
$events_notices = [];

// Announcements from site, category, and course levels (read-only for parents)
$announcements = [];
try {
    $announcement_queries = [];
    
    // 1. Site-level announcements (News forum on front page)
    $news_forum = $DB->get_record('forum', ['course' => SITEID, 'type' => 'news'], '*', IGNORE_MULTIPLE);
    if ($news_forum) {
        $site_announcements = $DB->get_records_sql(
            "SELECT p.id, p.subject, p.message, p.messageformat, p.modified, p.created,
                    u.id as authorid, u.firstname, u.lastname, u.picture, u.imagealt,
                    d.id as discussionid, d.name as discussionname,
                    c.id as courseid, c.fullname as coursename,
                    'site' as announcement_level
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {user} u ON u.id = p.userid
               JOIN {course} c ON c.id = :siteid
              WHERE d.forum = :forumid
                AND p.parent = 0
                AND u.deleted = 0
           ORDER BY p.modified DESC, p.created DESC",
            ['forumid' => $news_forum->id, 'siteid' => SITEID],
            0,
            5
        );
        if ($site_announcements) {
            $announcements = array_merge($announcements, array_values($site_announcements));
        }
    }
    
    // 2. Category-level announcements (from category courses with news forums)
    if (!empty($my_course_ids)) {
        // Get unique categories from child courses
        $categories = $DB->get_records_sql(
            "SELECT DISTINCT cat.id, cat.name
               FROM {course_categories} cat
               JOIN {course} c ON c.category = cat.id
              WHERE c.id IN (" . implode(',', array_map('intval', $my_course_ids)) . ")
                AND cat.visible = 1",
            []
        );
        
        if (!empty($categories)) {
            $category_course_ids = [];
            foreach ($categories as $cat) {
                $cat_courses = $DB->get_records('course', ['category' => $cat->id, 'visible' => 1], '', 'id');
                $category_course_ids = array_merge($category_course_ids, array_keys($cat_courses));
            }
            $category_course_ids = array_unique($category_course_ids);
            
            if (!empty($category_course_ids)) {
                list($cat_course_sql, $cat_course_params) = $DB->get_in_or_equal($category_course_ids, SQL_PARAMS_NAMED, 'cat');
                $category_announcements = $DB->get_records_sql(
                    "SELECT p.id, p.subject, p.message, p.messageformat, p.modified, p.created,
                            u.id as authorid, u.firstname, u.lastname, u.picture, u.imagealt,
                            d.id as discussionid, d.name as discussionname,
                            c.id as courseid, c.fullname as coursename,
                            cat.name as categoryname,
                            'category' as announcement_level
                       FROM {forum_posts} p
                       JOIN {forum_discussions} d ON d.id = p.discussion
                       JOIN {forum} f ON f.id = d.forum
                       JOIN {course} c ON c.id = f.course
                       JOIN {course_categories} cat ON cat.id = c.category
                       JOIN {user} u ON u.id = p.userid
                      WHERE f.course $cat_course_sql
                        AND f.type = 'news'
                        AND p.parent = 0
                        AND u.deleted = 0
                        AND p.created >= :recent
                   ORDER BY p.modified DESC, p.created DESC",
                    array_merge($cat_course_params, ['recent' => time() - (DAYSECS * 60)]),
                    0,
                    5
                );
                if ($category_announcements) {
                    $announcements = array_merge($announcements, array_values($category_announcements));
                }
            }
        }
    }
    
    // 3. Course-level announcements (from child courses)
    if (!empty($my_course_ids)) {
        list($course_ann_sql, $course_ann_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'cann');
        $course_announcements = $DB->get_records_sql(
            "SELECT p.id, p.subject, p.message, p.messageformat, p.modified, p.created,
                    u.id as authorid, u.firstname, u.lastname, u.picture, u.imagealt,
                    d.id as discussionid, d.name as discussionname,
                    c.id as courseid, c.fullname as coursename,
                    'course' as announcement_level
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {forum} f ON f.id = d.forum
               JOIN {course} c ON c.id = f.course
               JOIN {user} u ON u.id = p.userid
              WHERE f.course $course_ann_sql
                AND f.type = 'news'
                AND p.parent = 0
                AND u.deleted = 0
                AND p.created >= :recent
           ORDER BY p.modified DESC, p.created DESC",
            array_merge($course_ann_params, ['recent' => time() - (DAYSECS * 30)]),
            0,
            10
        );
        if ($course_announcements) {
            $announcements = array_merge($announcements, array_values($course_announcements));
        }
    }
    
    // Sort all announcements by modified date and limit
    usort($announcements, function($a, $b) {
        $time_a = $a->modified ?? $a->created ?? 0;
        $time_b = $b->modified ?? $b->created ?? 0;
        return $time_b - $time_a;
    });
    $announcements = array_slice($announcements, 0, 15);
    
} catch (Exception $e) {
    debugging('Community announcements query failed: ' . $e->getMessage());
    $announcements = [];
}

// Teacher/Class updates sourced from logstore for the parent's child courses.
if (!empty($my_course_ids)) {
    try {
        list($course_sql, $course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'tcu');
        $teacher_updates = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.action, l.target, l.objecttable, l.objectid,
                    l.courseid, l.component, c.fullname AS coursename, u.id AS teacherid,
                    u.firstname, u.lastname
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxcourse AND ctx.instanceid = l.courseid
               JOIN {role} r ON r.id = ra.roleid
               LEFT JOIN {course} c ON c.id = l.courseid
              WHERE l.courseid $course_sql
                AND r.shortname IN ('editingteacher','teacher')
                AND l.action IN ('created','updated','graded')
                AND l.timecreated >= :recent
                AND u.deleted = 0
                AND u.id NOT IN (
                    SELECT DISTINCT ra_admin.userid
                    FROM {role_assignments} ra_admin
                    JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
                    WHERE r_admin.shortname IN ('manager', 'administrator')
                )
           ORDER BY l.timecreated DESC",
            array_merge($course_params, [
                'ctxcourse' => CONTEXT_COURSE,
                'recent' => time() - (DAYSECS * 30),
            ]),
            0,
            8
        );
    } catch (Exception $e) {
        debugging('Community teacher updates query failed: ' . $e->getMessage());
        $teacher_updates = [];
    }
}

// Events & notices (site-wide + child course events + user events for children).
try {
    $event_conditions = [];
    $event_params = ['now' => time()];
    
    // Site and category events
    $event_conditions[] = "e.eventtype IN ('site','category')";
    
    // Course events for child courses
    if (!empty($my_course_ids)) {
        list($evt_sql, $evt_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'cevt');
        $event_conditions[] = "e.courseid $evt_sql";
        $event_params = array_merge($event_params, $evt_params);
    }
    
    // User events for children
    if (!empty($my_child_ids)) {
        list($child_sql, $child_params) = $DB->get_in_or_equal($my_child_ids, SQL_PARAMS_NAMED, 'uevt');
        $event_conditions[] = "(e.eventtype = 'user' AND e.userid $child_sql)";
        $event_params = array_merge($event_params, $child_params);
    }
    
    $event_condition_sql = '(' . implode(' OR ', $event_conditions) . ')';
    
    $events_notices = $DB->get_records_sql(
        "SELECT e.id, e.name, e.description, e.timestart, e.eventtype, e.location,
                e.courseid, e.userid, c.fullname AS coursename
           FROM {event} e
      LEFT JOIN {course} c ON c.id = e.courseid
          WHERE e.timestart >= :now
            AND e.visible = 1
            AND $event_condition_sql
       ORDER BY e.timestart ASC",
        $event_params,
        0,
        10
    );
} catch (Exception $e) {
    debugging('Community events query failed: ' . $e->getMessage());
    $events_notices = [];
}
$search = trim(optional_param('search', '', PARAM_TEXT));
$meetingfilter = trim(strtolower(optional_param('meetingfilter', 'upcoming', PARAM_ALPHA)));
$allowedfilters = ['upcoming', 'past', 'all'];
if (!in_array($meetingfilter, $allowedfilters, true)) {
    $meetingfilter = 'upcoming';
}

$parentparams = [
    'role' => 'parent',
    'ctxsys' => CONTEXT_SYSTEM,
    'ctxuser' => CONTEXT_USER,
];

$searchsql = '';
if ($search !== '') {
    $like = '%' . $DB->sql_like_escape(core_text::strtolower($search)) . '%';
    $parentparams['search1'] = $like;
    $parentparams['search2'] = $like;
    $parentparams['search3'] = $like;
    $parentparams['search4'] = $like;
    $searchsql = " AND (
        " . $DB->sql_like('LOWER(u.firstname)', ':search1') . "
        OR " . $DB->sql_like('LOWER(u.lastname)', ':search2') . "
        OR " . $DB->sql_like('LOWER(u.email)', ':search3') . "
        OR " . $DB->sql_like('LOWER(u.city)', ':search4') . "
    )";
}

// Check capability: moodle/site:viewparticipants
$can_view_participants = has_capability('moodle/site:viewparticipants', $system_context);

$parentrecords = [];
if ($can_view_participants) {
$parentsql = "
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.city, u.country,
           u.phone1, u.lastaccess, u.picture, u.imagealt, u.timecreated
      FROM {user} u
INNER JOIN {role_assignments} ra ON ra.userid = u.id
INNER JOIN {role} r ON r.id = ra.roleid
INNER JOIN {context} ctx ON ctx.id = ra.contextid
     WHERE r.shortname = :role
       AND u.deleted = 0
       AND ctx.contextlevel IN (:ctxsys, :ctxuser)
       $searchsql
  ORDER BY LOWER(u.firstname), LOWER(u.lastname)
";

$parentrecords = $DB->get_records_sql($parentsql, $parentparams);
}

$communitycards = [];
$totalparents = count($parentrecords);
$totalupcomingmeetings = 0;
$totalchildren = 0;

foreach ($parentrecords as $parent) {
    $parentuser = (object)$parent;
    $parentname = fullname($parentuser);
    $children = get_parent_children($parent->id);
    $totalchildren += count($children);

    $allmeetings = get_parent_meetings($parent->id, 'all');
    $upcomingmeetings = array_values(array_filter($allmeetings, static function($meeting) {
        return ($meeting['status'] ?? '') === 'scheduled' && ($meeting['timestamp'] ?? 0) >= time();
    }));
    $pastmeetings = array_values(array_filter($allmeetings, static function($meeting) {
        return ($meeting['status'] ?? '') !== 'scheduled' || ($meeting['timestamp'] ?? 0) < time();
    }));

    $selectedmeetings = $upcomingmeetings;
    if ($meetingfilter === 'past') {
        $selectedmeetings = $pastmeetings;
    } else if ($meetingfilter === 'all') {
        $selectedmeetings = $allmeetings;
    }

    $totalupcomingmeetings += count($upcomingmeetings);
    $highlightmeetings = array_slice($selectedmeetings, 0, 3);

    $communitycards[] = [
        'user' => $parentuser,
        'name' => $parentname,
        'children' => $children,
        'meetings' => $highlightmeetings,
        'counts' => [
            'all' => count($allmeetings),
            'upcoming' => count($upcomingmeetings),
            'past' => count($pastmeetings),
        ],
    ];
}

// Community feed derived from most engaged parents (by upcoming meetings).
$community_feed = is_array($communitycards) ? $communitycards : [];
if (!empty($community_feed)) {
    usort($community_feed, static function($a, $b) {
        $aCount = $a['counts']['upcoming'] ?? 0;
        $bCount = $b['counts']['upcoming'] ?? 0;
        if ($aCount === $bCount) {
            return ($a['counts']['all'] ?? 0) < $b['counts']['all'] ? 1 : -1;
        }
        return ($aCount > $bCount) ? -1 : 1;
    });
    $community_feed = array_slice($community_feed, 0, 6);
}

// Recent forum posts from child courses
$recent_forum_posts = [];
if (!empty($my_course_ids)) {
    try {
        list($forum_course_sql, $forum_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'fcp');
        $recent_forum_posts = $DB->get_records_sql(
            "SELECT p.id, p.subject, p.message, p.messageformat, p.created, p.modified,
                    d.id as discussionid, d.name as discussionname, d.forum,
                    f.name as forumname, f.course as courseid,
                    c.fullname AS coursename,
                    u.id as authorid, u.firstname, u.lastname, u.picture
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {forum} f ON f.id = d.forum
               JOIN {course} c ON c.id = f.course
               JOIN {user} u ON u.id = p.userid
              WHERE f.course $forum_course_sql
                AND p.parent = 0
                AND u.deleted = 0
                AND p.created >= :recent
           ORDER BY p.created DESC",
            array_merge($forum_course_params, [
                'recent' => time() - (DAYSECS * 7),
            ]),
            0,
            8
        );
    } catch (Exception $e) {
        debugging('Recent forum posts query failed: ' . $e->getMessage());
        $recent_forum_posts = [];
    }
}

// Upcoming assignments and quizzes for children
$upcoming_assignments = [];
$upcoming_quizzes = [];
if (!empty($my_course_ids)) {
    try {
        list($assign_course_sql, $assign_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'acp');
        
        // Upcoming assignments
        $upcoming_assignments = $DB->get_records_sql(
            "SELECT a.id, a.name, a.duedate, a.intro, a.introformat,
                    c.id as courseid, c.fullname AS coursename,
                    cm.id as cmid
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
               JOIN {course} c ON c.id = a.course
              WHERE a.course $assign_course_sql
                AND a.duedate > :now
                AND a.duedate <= :future
                AND cm.visible = 1
           ORDER BY a.duedate ASC",
            array_merge($assign_course_params, [
                'now' => time(),
                'future' => time() + (DAYSECS * 30),
            ]),
            0,
            10
        );
        
        // Upcoming quizzes
        $upcoming_quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.timeclose, q.intro, q.introformat,
                    c.id as courseid, c.fullname AS coursename,
                    cm.id as cmid
               FROM {quiz} q
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')
               JOIN {course} c ON c.id = q.course
              WHERE q.course $assign_course_sql
                AND q.timeclose > :now
                AND q.timeclose <= :future
                AND cm.visible = 1
           ORDER BY q.timeclose ASC",
            array_merge($assign_course_params, [
                'now' => time(),
                'future' => time() + (DAYSECS * 30),
            ]),
            0,
            10
        );
    } catch (Exception $e) {
        debugging('Upcoming assignments/quizzes query failed: ' . $e->getMessage());
    }
}

// Recent grades and feedback for children
$recent_grades = [];
if (!empty($my_child_ids) && !empty($my_course_ids)) {
    try {
        list($grade_child_sql, $grade_child_params) = $DB->get_in_or_equal($my_child_ids, SQL_PARAMS_NAMED, 'gcp');
        list($grade_course_sql, $grade_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'gcrp');
        
        $recent_grades = $DB->get_records_sql(
            "SELECT gg.id, gg.finalgrade, gg.feedback, gg.feedbackformat, gg.timemodified,
                    gi.itemname, gi.itemtype, gi.itemmodule,
                    c.id as courseid, c.fullname AS coursename,
                    u.id as userid
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
               JOIN {course} c ON c.id = gi.courseid
               JOIN {user} u ON u.id = gg.userid
              WHERE u.id $grade_child_sql
                AND gi.courseid $grade_course_sql
                AND gg.finalgrade IS NOT NULL
                AND gg.timemodified >= :recent
                AND gi.itemtype = 'mod'
           ORDER BY gg.timemodified DESC",
            array_merge($grade_child_params, $grade_course_params, [
                'recent' => time() - (DAYSECS * 14),
            ]),
            0,
            12
        );
    } catch (Exception $e) {
        debugging('Recent grades query failed: ' . $e->getMessage());
        $recent_grades = [];
    }
}

// School resources/files from child courses
$school_resources = [];
if (!empty($my_course_ids)) {
    try {
        list($res_course_sql, $res_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'rcp');
        
        $school_resources = $DB->get_records_sql(
            "SELECT f.id, f.filename, f.filesize, f.timecreated, f.timemodified,
                    c.id as courseid, c.fullname AS coursename,
                    ctx.id as contextid
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ctx.contextlevel = :ctxcourse
                AND ctx.instanceid $res_course_sql
                AND f.component IN ('course', 'mod_resource', 'mod_folder')
                AND f.filearea IN ('section_backup', 'content', 'intro')
                AND f.filesize > 0
                AND f.filename != '.'
                AND f.timemodified >= :recent
           ORDER BY f.timemodified DESC",
            array_merge($res_course_params, [
                'ctxcourse' => CONTEXT_COURSE,
                'recent' => time() - (DAYSECS * 60),
            ]),
            0,
            15
        );
    } catch (Exception $e) {
        debugging('School resources query failed: ' . $e->getMessage());
        $school_resources = [];
    }
}

// ==================== TEACHER DIRECTORY & CONNECTIONS ====================
// Fetch ONLY teachers from courses where parent's children are enrolled
$teacher_directory = [];
$teacher_courses_map = [];
if (!empty($my_child_ids)) {
    try {
        // First, get all admin/manager user IDs to exclude
        $exclude_user_ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE r.shortname IN ('manager', 'administrator', 'companymanager', 'companydepartmentmanager')"
        );
        
        // Also check for site admins in config
        $site_admins = explode(',', $CFG->siteadmins ?? '');
        $exclude_user_ids = array_merge($exclude_user_ids, array_filter(array_map('trim', $site_admins)));
        $exclude_user_ids = array_unique(array_filter($exclude_user_ids));
        
        // Build exclusion SQL
        $exclude_sql = '';
        $exclude_params = [];
        if (!empty($exclude_user_ids)) {
            list($exclude_in_sql, $exclude_params) = $DB->get_in_or_equal($exclude_user_ids, SQL_PARAMS_NAMED, 'exclude');
            $exclude_sql = "AND u.id NOT $exclude_in_sql";
        }
        
        list($child_sql, $child_params) = $DB->get_in_or_equal($my_child_ids, SQL_PARAMS_NAMED, 'child');
        
        // Get ONLY teachers from courses where children are enrolled
        // This ensures we only show teachers for courses the parent's children are actually taking
        $teacher_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id AS teacherid,
                    u.firstname, u.lastname, u.email, u.phone1, u.phone2, 
                    u.city, u.country, u.picture, u.imagealt, u.description, u.descriptionformat,
                    c.id AS courseid, c.fullname AS coursename, c.shortname AS courseshortname,
                    ue.userid AS childid
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {course} c ON c.id = ctx.instanceid
               JOIN {enrol} e ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE ctx.contextlevel = :ctxcourse
                AND r.shortname IN ('editingteacher', 'teacher')
                AND u.deleted = 0
                AND u.suspended = 0
                AND ue.userid $child_sql
                AND e.status = 0
                AND ue.status = 0
                $exclude_sql
           ORDER BY u.firstname, u.lastname, c.fullname",
            array_merge($child_params, $exclude_params, [
                'ctxcourse' => CONTEXT_COURSE,
            ])
        );
        
        // Group teachers and their courses (only courses where children are enrolled)
        if (!empty($teacher_records)) {
            foreach ($teacher_records as $record) {
                $teacher_id = (int)$record->teacherid;
                
                // Initialize teacher if not exists
                if (!isset($teacher_directory[$teacher_id])) {
                    $teacher_directory[$teacher_id] = (object)[
                        'id' => $teacher_id,
                        'firstname' => $record->firstname,
                        'lastname' => $record->lastname,
                        'email' => $record->email,
                        'phone1' => $record->phone1,
                        'phone2' => $record->phone2,
                        'city' => $record->city,
                        'country' => $record->country,
                        'picture' => $record->picture,
                        'imagealt' => $record->imagealt,
                        'description' => $record->description,
                        'descriptionformat' => $record->descriptionformat,
                        'coursecount' => 0,
                    ];
                    $teacher_courses_map[$teacher_id] = [];
                }
                
                // Add course if not already added (only courses where children are enrolled)
                if (!empty($record->courseid) && !empty($record->coursename)) {
                    $course_exists = false;
                    foreach ($teacher_courses_map[$teacher_id] as $existing_course) {
                        if ($existing_course['id'] == $record->courseid) {
                            $course_exists = true;
                            break;
                        }
                    }
                    if (!$course_exists) {
                        $teacher_courses_map[$teacher_id][] = [
                            'id' => (int)$record->courseid,
                            'fullname' => $record->coursename,
                            'shortname' => $record->courseshortname,
                        ];
                        $teacher_directory[$teacher_id]->coursecount = count($teacher_courses_map[$teacher_id]);
                    }
                }
            }
            
            // Double-check: Filter out any admins/managers that might have slipped through
            $filtered_teachers = [];
            foreach ($teacher_directory as $teacher_id => $teacher) {
                // Check if user has any admin/manager roles at any context level
                $has_excluded_role = $DB->record_exists_sql(
                    "SELECT 1 FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = :userid
                       AND r.shortname IN ('manager', 'administrator', 'companymanager', 'companydepartmentmanager')",
                    ['userid' => $teacher_id]
                );
                
                if (!$has_excluded_role && !in_array($teacher_id, $exclude_user_ids)) {
                    $filtered_teachers[$teacher_id] = $teacher;
                }
            }
            $teacher_directory = $filtered_teachers;
        }
    } catch (Exception $e) {
        debugging('Teacher directory query failed: ' . $e->getMessage());
        $teacher_directory = [];
    }
}

// Check if messaging is enabled and get unread message count
$unread_messages_count = 0;
$messaging_available = false;
try {
    if (function_exists('message_count_unread_messages')) {
        $unread_messages_count = message_count_unread_messages($USER);
        $messaging_available = true;
    }
} catch (Exception $e) {
    debugging('Message count failed: ' . $e->getMessage());
    $messaging_available = false;
}

// ==================== ENHANCED PARENT GROUPS/COHORTS ====================
$parent_groups = [];
$parent_group_memberships = [];
try {
    // Get all visible parent groups/cohorts
    $parent_groups = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.name, c.description, c.descriptionformat, c.idnumber,
                c.visible, c.contextid,
                COUNT(DISTINCT cm.userid) as membercount,
                COUNT(DISTINCT CASE WHEN cm.userid = :currentuser THEN 1 END) as is_member
           FROM {cohort} c
           LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
           LEFT JOIN {user} u ON u.id = cm.userid
          WHERE c.visible = 1
            AND (c.contextid = :syscontext 
                 OR c.contextid IN (
                     SELECT DISTINCT ctx.id
                     FROM {context} ctx
                     WHERE ctx.contextlevel = :ctxsystem
                 ))
          GROUP BY c.id, c.name, c.description, c.descriptionformat, c.idnumber,
                   c.visible, c.contextid
          ORDER BY c.name ASC",
        [
            'currentuser' => $USER->id,
            'syscontext' => context_system::instance()->id,
            'ctxsystem' => CONTEXT_SYSTEM,
        ]
    );
    
    // Get current user's group memberships
    if (!empty($parent_groups)) {
        $group_ids = array_keys($parent_groups);
        list($gid_sql, $gid_params) = $DB->get_in_or_equal($group_ids, SQL_PARAMS_NAMED, 'gid');
        
        $memberships = $DB->get_records_sql(
            "SELECT DISTINCT cohortid
               FROM {cohort_members}
              WHERE userid = :userid
                AND cohortid $gid_sql",
            array_merge($gid_params, [
                'userid' => $USER->id,
            ])
        );
        
        foreach ($memberships as $membership) {
            $parent_group_memberships[$membership->cohortid] = true;
        }
    }
} catch (Exception $e) {
    debugging('Parent groups query failed: ' . $e->getMessage());
    $parent_groups = [];
}

// Handle group join/leave actions
$action = optional_param('action', '', PARAM_ALPHA);
$cohortid = optional_param('cohortid', 0, PARAM_INT);
$selected_tab = optional_param('tab', 'overview', PARAM_ALPHA);
$redirecturl = new moodle_url('/theme/remui_kids/parent/parent_community.php', ['tab' => 'groups']);

if ($action === 'joingroup' && $cohortid > 0) {
    require_sesskey();
    try {
        $cohort = $DB->get_record('cohort', ['id' => $cohortid, 'visible' => 1]);
        if ($cohort) {
            // Check if already a member
            $existing = $DB->get_record('cohort_members', ['cohortid' => $cohort->id, 'userid' => $USER->id]);
            if (!$existing) {
                $member = new stdClass();
                $member->cohortid = $cohort->id;
                $member->userid = $USER->id;
                $member->timeadded = time();
                $DB->insert_record('cohort_members', $member);
                redirect($redirecturl, 'Successfully joined group!', null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                redirect($redirecturl, 'You are already a member of this group.', null, \core\output\notification::NOTIFY_INFO);
            }
        }
    } catch (Exception $e) {
        redirect($redirecturl, 'Error joining group: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'leavegroup' && $cohortid > 0) {
    require_sesskey();
    try {
        $cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        if ($cohort) {
            $DB->delete_records('cohort_members', ['cohortid' => $cohort->id, 'userid' => $USER->id]);
            redirect($redirecturl, 'Successfully left group!', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (Exception $e) {
        redirect($redirecturl, 'Error leaving group: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ==================== PARENT NETWORKING BY COURSES ====================
$parents_by_course = [];
$parent_connections = [];
if (!empty($my_course_ids) && !empty($my_child_ids)) {
    try {
        // Find parents whose children are in the same courses
        list($pbc_course_sql, $pbc_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'pbc');
        
        // Simplified query: Get all students enrolled in same courses, then find their parents
        $student_parent_connections = $DB->get_records_sql(
            "SELECT DISTINCT 
                    ue.userid as studentid,
                    u.firstname as studentfirstname, u.lastname as studentlastname,
                    e.courseid as courseid,
                    c.fullname as coursename
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.status = 0
                AND ue.status = 0
                AND c.id $pbc_course_sql
                AND u.deleted = 0
                AND u.id != :currentuserid
           ORDER BY c.fullname, u.firstname",
            array_merge($pbc_course_params, [
                'currentuserid' => $USER->id,
            ])
        );
        
        // Now find parents for these students
        $parent_connections_raw = [];
        if (!empty($student_parent_connections)) {
            $student_ids_found = array_unique(array_column($student_parent_connections, 'studentid'));
            list($student_sql, $student_params) = $DB->get_in_or_equal($student_ids_found, SQL_PARAMS_NAMED, 'std');
            
            $parent_role_id = $DB->get_field('role', 'id', ['shortname' => 'parent']);
            
            if ($parent_role_id) {
                $parents_of_students = $DB->get_records_sql(
                    "SELECT DISTINCT 
                            ra.userid as parentid,
                            p.firstname, p.lastname, p.email, p.picture,
                            ctx.instanceid as studentid
                FROM {role_assignments} ra
                JOIN {context} ctx ON ctx.id = ra.contextid
                       JOIN {user} p ON p.id = ra.userid
                      WHERE ra.roleid = :parentroleid
                AND ctx.contextlevel = :ctxuser
                        AND ctx.instanceid $student_sql
                        AND p.deleted = 0
                        AND p.id != :currentuserid",
                    array_merge($student_params, [
                        'parentroleid' => $parent_role_id,
            'ctxuser' => CONTEXT_USER,
                        'currentuserid' => $USER->id,
                    ])
                );
                
                // Combine student and parent data
                foreach ($student_parent_connections as $student_conn) {
                    if (isset($parents_of_students[$student_conn->studentid])) {
                        $parent = $parents_of_students[$student_conn->studentid];
                        $parent_connections_raw[] = (object)[
                            'parentid' => $parent->parentid,
                            'firstname' => $parent->firstname,
                            'lastname' => $parent->lastname,
                            'email' => $parent->email,
                            'picture' => $parent->picture,
                            'courseid' => $student_conn->courseid,
                            'coursename' => $student_conn->coursename,
                            'studentid' => $student_conn->studentid,
                            'studentfirstname' => $student_conn->studentfirstname,
                            'studentlastname' => $student_conn->studentlastname,
                        ];
                    }
                }
            }
        }
        
        // Organize by course
        foreach ($parent_connections_raw as $conn) {
            if (!isset($parents_by_course[$conn->courseid])) {
                $parents_by_course[$conn->courseid] = [
                    'course' => ['id' => $conn->courseid, 'name' => $conn->coursename],
                    'parents' => [],
                ];
            }
            
            if (!isset($parents_by_course[$conn->courseid]['parents'][$conn->parentid])) {
                $parents_by_course[$conn->courseid]['parents'][$conn->parentid] = [
                    'id' => $conn->parentid,
                    'name' => fullname((object)['firstname' => $conn->firstname, 'lastname' => $conn->lastname]),
                    'email' => $conn->email,
                    'picture' => $conn->picture,
                    'children' => [],
                ];
            }
            
            $parents_by_course[$conn->courseid]['parents'][$conn->parentid]['children'][] = [
                'id' => $conn->studentid,
                'name' => fullname((object)['firstname' => $conn->studentfirstname, 'lastname' => $conn->studentlastname]),
            ];
        }
        
        // Convert associative arrays to indexed
        foreach ($parents_by_course as $courseid => &$course_data) {
            $course_data['parents'] = array_values($course_data['parents']);
        }
    } catch (Exception $e) {
        debugging('Parent connections query failed: ' . $e->getMessage());
        $parents_by_course = [];
    }
}

// ==================== PARENT-TEACHER FORUM DISCUSSIONS ====================
$parent_teacher_forums = [];
if (!empty($my_course_ids)) {
    try {
        list($ptf_course_sql, $ptf_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'ptf');
        
        $parent_teacher_forums = $DB->get_records_sql(
            "SELECT DISTINCT f.id, f.name, f.intro, f.introformat, f.course,
                    c.fullname as coursename,
                    COUNT(DISTINCT d.id) as discussioncount,
                    COUNT(DISTINCT p.id) as postcount,
                    MAX(p.created) as lastpost
               FROM {forum} f
               JOIN {course} c ON c.id = f.course
               LEFT JOIN {forum_discussions} d ON d.forum = f.id
               LEFT JOIN {forum_posts} p ON p.discussion = d.id
              WHERE f.course $ptf_course_sql
                AND f.type IN ('general', 'single')
                AND f.visible = 1
           GROUP BY f.id, f.name, f.intro, f.introformat, f.course, c.fullname
           ORDER BY lastpost DESC, c.fullname, f.name",
            $ptf_course_params,
        0,
        10
    );
} catch (Exception $e) {
        debugging('Parent-teacher forums query failed: ' . $e->getMessage());
        $parent_teacher_forums = [];
    }
}

// Recent activity across all child courses - filtered by child user ID
$recent_activity = [];
if (!empty($my_child_ids) && !empty($my_course_ids)) {
    try {
        list($act_child_sql, $act_child_params) = $DB->get_in_or_equal($my_child_ids, SQL_PARAMS_NAMED, 'act_child');
        list($act_course_sql, $act_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'acp2');
        
        $recent_activity = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.action, l.target, l.objecttable, l.objectid,
                    l.courseid, l.userid, l.component,
                    c.fullname AS coursename,
                    u.firstname, u.lastname
               FROM {logstore_standard_log} l
               JOIN {course} c ON c.id = l.courseid
               JOIN {user} u ON u.id = l.userid
              WHERE l.userid $act_child_sql
                AND l.courseid $act_course_sql
                AND l.timecreated >= :recent
                AND u.deleted = 0
                AND l.action IN ('viewed', 'created', 'updated', 'submitted', 'graded')
           ORDER BY l.timecreated DESC",
            array_merge($act_child_params, $act_course_params, [
                'recent' => time() - (DAYSECS * 7),
            ]),
            0,
            20
        );
    } catch (Exception $e) {
        debugging('Recent activity query failed: ' . $e->getMessage());
        $recent_activity = [];
    }
}

// ==================== OVERVIEW DATA - PER CHILD ====================
$child_overview_data = [];
$attendance_summary = [];
if (!empty($my_children)) {
    foreach ($my_children as $child) {
        $child_id = (int)$child['id'];
        $child_courses = enrol_get_users_courses($child_id, true, 'id, fullname, shortname');
        $child_course_ids = array_keys($child_courses);
        
        // Get upcoming deadlines (assignments + quizzes)
        $upcoming_deadlines = [];
        if (!empty($child_course_ids)) {
            try {
                list($deadline_course_sql, $deadline_course_params) = $DB->get_in_or_equal($child_course_ids, SQL_PARAMS_NAMED, 'deadline');
                
                // Assignments
                $assignments = $DB->get_records_sql(
                    "SELECT a.id, a.name, a.duedate, c.fullname as coursename
                       FROM {assign} a
                       JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                       JOIN {course} c ON c.id = a.course
                      WHERE a.course $deadline_course_sql
                        AND a.duedate > :now
                        AND a.duedate <= :future
                        AND cm.visible = 1
                   ORDER BY a.duedate ASC
                      LIMIT 5",
                    array_merge($deadline_course_params, [
                        'now' => time(),
                        'future' => time() + (DAYSECS * 30),
                    ])
                );
                
                // Quizzes
                $quizzes = $DB->get_records_sql(
                    "SELECT q.id, q.name, q.timeclose, c.fullname as coursename
                       FROM {quiz} q
                       JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')
                       JOIN {course} c ON c.id = q.course
                      WHERE q.course $deadline_course_sql
                        AND q.timeclose > :now
                        AND q.timeclose <= :future
                        AND cm.visible = 1
                   ORDER BY q.timeclose ASC
                      LIMIT 5",
                    array_merge($deadline_course_params, [
                        'now' => time(),
                        'future' => time() + (DAYSECS * 30),
                    ])
                );
                
                foreach ($assignments as $a) {
                    $upcoming_deadlines[] = [
                        'type' => 'assignment',
                        'name' => $a->name,
                        'duedate' => $a->duedate,
                        'course' => $a->coursename,
                    ];
                }
                foreach ($quizzes as $q) {
                    $upcoming_deadlines[] = [
                        'type' => 'quiz',
                        'name' => $q->name,
                        'duedate' => $q->timeclose,
                        'course' => $q->coursename,
                    ];
                }
                
                // Sort by due date
                usort($upcoming_deadlines, function($a, $b) {
                    return $a['duedate'] - $b['duedate'];
                });
                $upcoming_deadlines = array_slice($upcoming_deadlines, 0, 5);
            } catch (Exception $e) {
                debugging('Upcoming deadlines query failed: ' . $e->getMessage());
            }
        }
        
        // Get recent announcements for this child's courses
        $child_announcements = [];
        if (!empty($child_course_ids)) {
            try {
                list($ann_course_sql, $ann_course_params) = $DB->get_in_or_equal($child_course_ids, SQL_PARAMS_NAMED, 'ann');
                $child_announcements = $DB->get_records_sql(
                    "SELECT fp.id, fp.subject, fp.message, fp.messageformat, fp.modified, fp.created,
                            u.firstname, u.lastname,
                            f.name as forumname, c.fullname as coursename
                       FROM {forum_posts} fp
                       JOIN {forum_discussions} fd ON fd.id = fp.discussion
                       JOIN {forum} f ON f.id = fd.forum
                       JOIN {course} c ON c.id = f.course
                       JOIN {user} u ON u.id = fp.userid
                      WHERE f.course $ann_course_sql
                        AND f.type = 'news'
                        AND fp.parent = 0
                        AND u.deleted = 0
                        AND fp.created >= :recent
                   ORDER BY fp.created DESC
                      LIMIT 5",
                    array_merge($ann_course_params, [
                        'recent' => time() - (DAYSECS * 30),
                    ])
                );
            } catch (Exception $e) {
                debugging('Child announcements query failed: ' . $e->getMessage());
            }
        }
        
        // Get recent grades for this child
        $child_recent_grades = [];
        if (!empty($child_course_ids)) {
            try {
                list($grade_course_sql, $grade_course_params) = $DB->get_in_or_equal($child_course_ids, SQL_PARAMS_NAMED, 'cg');
                $child_recent_grades = $DB->get_records_sql(
                    "SELECT gg.id, gg.finalgrade, gg.feedback, gg.timemodified,
                            gi.itemname, gi.itemmodule,
                            c.fullname AS coursename
                       FROM {grade_grades} gg
                       JOIN {grade_items} gi ON gi.id = gg.itemid
                       JOIN {course} c ON c.id = gi.courseid
                      WHERE gg.userid = :userid
                        AND gi.courseid $grade_course_sql
                        AND gg.finalgrade IS NOT NULL
                        AND gg.timemodified >= :recent
                        AND gi.itemtype = 'mod'
                   ORDER BY gg.timemodified DESC
                      LIMIT 5",
                    array_merge($grade_course_params, [
                        'userid' => $child_id,
                        'recent' => time() - (DAYSECS * 14),
                    ])
                );
            } catch (Exception $e) {
                debugging('Child recent grades query failed: ' . $e->getMessage());
            }
        }
        
        // Get attendance summary (if attendance plugin is installed)
        $child_attendance = null;
        if ($DB->get_manager()->table_exists('attendance') && !empty($child_course_ids)) {
            try {
                list($att_course_sql, $att_course_params) = $DB->get_in_or_equal($child_course_ids, SQL_PARAMS_NAMED, 'att');
                
                // Get total sessions and present count
                $attendance_stats = $DB->get_record_sql(
                    "SELECT COUNT(DISTINCT ats.id) as total_sessions,
                            COUNT(DISTINCT CASE WHEN al.statusid IS NOT NULL AND ast.acronym = 'P' THEN al.id END) as present_count
                       FROM {attendance_sessions} ats
                       JOIN {attendance} att ON att.id = ats.attendanceid
                       LEFT JOIN {attendance_log} al ON al.sessionid = ats.id AND al.studentid = :userid
                       LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
                      WHERE att.course $att_course_sql
                        AND ats.sessdate >= :recent",
                    array_merge($att_course_params, [
                        'userid' => $child_id,
                        'recent' => time() - (DAYSECS * 30),
                    ])
                );
                
                if ($attendance_stats && $attendance_stats->total_sessions > 0) {
                    $attendance_rate = round(($attendance_stats->present_count / $attendance_stats->total_sessions) * 100, 1);
                    $child_attendance = [
                        'total_sessions' => (int)$attendance_stats->total_sessions,
                        'present_count' => (int)$attendance_stats->present_count,
                        'rate' => $attendance_rate,
                    ];
                }
            } catch (Exception $e) {
                debugging('Child attendance query failed: ' . $e->getMessage());
            }
        }
        
        $child_overview_data[$child_id] = [
            'child' => $child,
            'courses' => $child_courses,
            'course_count' => count($child_courses),
            'upcoming_deadlines' => $upcoming_deadlines,
            'recent_announcements' => $child_announcements,
            'recent_grades' => $child_recent_grades,
            'attendance' => $child_attendance,
        ];
        
        if ($child_attendance) {
            $attendance_summary[$child_id] = $child_attendance;
        }
    }
}

echo $OUTPUT->header();
include_once($CFG->dirroot . '/theme/remui_kids/components/parent_sidebar.php');
?>

<style>
* {
    box-sizing: border-box;
}

/* Aggressively remove all Moodle container margins and padding */
.parent-community-page .container,
.parent-community-page #page,
.parent-community-page #page-content,
.parent-community-page .region-main-content,
.parent-community-page .container-fluid,
.parent-community-page .wrapper,
.parent-community-page [class*="container"],
.parent-community-page [id*="page"],
.parent-community-page [class*="region"] {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Remove any wrapper margins globally */
#page-wrapper,
#page,
#region-main-box,
#region-main,
#region-main-content,
.region-main-content,
.region-main {
    margin: 0 !important;
    padding: 0 !important;
}

/* Ensure full width for the entire page area */
body #page,
body #page-content,
body .container,
body [class*="container"] {
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100% !important;
}

/* Remove spacing from Moodle header/footer areas */
#page-header,
#page-footer,
.navbar,
header,
footer {
    margin: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* Remove all gaps and spacing */
.parent-community-page * {
    box-sizing: border-box;
}

.parent-community-page {
    margin: 0 !important;
    margin-left: 280px !important;
    padding: 0 !important;
    background: #f1f5f9;
    min-height: 100vh;
    width: calc(100% - 280px);
    position: relative;
    overflow-x: hidden;
}

/* White Container for Main Content */
.community-main-container {
    background-color: #ffffff;
    padding: 0;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    margin: 20px 30px;
    overflow: hidden;
    min-height: calc(100vh - 40px);
}

/* Remove any gaps between elements */
.parent-community-page > * {
    margin-top: 0;
    margin-bottom: 0;
}

/* Tab Navigation - Professional & Compact */
.community-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid #e2e8f0;
    padding: 0 20px;
    margin: 0;
    background: #ffffff;
    overflow-x: auto;
    scrollbar-width: thin;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: none;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.community-tabs::-webkit-scrollbar {
    height: 4px;
}

.community-tabs::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.community-tabs::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}

.community-tab {
    padding: 12px 18px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    min-width: fit-content;
    margin-bottom: -1px;
}

.community-tab::before {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 3px;
    background: #3b82f6;
    transform: scaleX(0);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-radius: 2px 2px 0 0;
}

.community-tab:hover {
    color: #3b82f6;
    background: #f8fafc;
}

.community-tab.active {
    color: #3b82f6;
    background: transparent;
    font-weight: 700;
    border-bottom-color: #3b82f6;
}

.community-tab.active::before {
    transform: scaleX(1);
}

.community-tab i {
    font-size: 14px;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}

.community-tab:hover i {
    transform: scale(1.1);
}

.community-tab.active i {
    color: #3b82f6;
}

/* Tab Content - Professional */
.community-tab-content {
    display: none !important;
    padding: 20px 24px;
    margin: 0;
    background: #ffffff;
    min-height: calc(100vh - 180px);
}

.community-tab-content.active,
.community-tab-content[style*="block"] {
    display: block !important;
    animation: fadeInUp 0.3s ease-out;
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

.community-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin: 20px 0;
}

.community-kpi-card {
    background: #ffffff;
    border-radius: 8px;
    padding: 16px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.community-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.community-kpi-card small {
    text-transform: uppercase;
    font-size: 11px;
    color: #64748b;
    letter-spacing: 0.3px;
    font-weight: 600;
    display: block;
    margin-bottom: 8px;
}

.community-kpi-card strong {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
    letter-spacing: -0.3px;
}

.community-controls {
    background: #ffffff;
    border-radius: 8px;
    padding: 16px 20px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.community-controls form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    flex: 1;
    align-items: center;
}

.community-controls input[type="text"] {
    flex: 1;
    min-width: 280px;
    padding: 16px 20px;
    border-radius: 14px;
    border: 2px solid rgba(148, 163, 184, 0.2);
    font-size: 15px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: white;
    color: #0f172a;
    font-weight: 500;
}

.community-controls input[type="text"]::placeholder {
    color: #94a3b8;
    font-weight: 400;
}

.community-controls input[type="text"]:focus {
    outline: none;
    border-color: #3b82f6;
    background: white;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12), 0 4px 12px rgba(59, 130, 246, 0.15);
    transform: translateY(-1px);
}

.community-controls select {
    padding: 16px 20px;
    border-radius: 14px;
    border: 2px solid rgba(148, 163, 184, 0.2);
    font-size: 15px;
    min-width: 200px;
    background: white;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    color: #0f172a;
    font-weight: 500;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    padding-right: 44px;
}

.community-controls select:focus {
    outline: none;
    border-color: #3b82f6;
    background-color: white;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12), 0 4px 12px rgba(59, 130, 246, 0.15);
    transform: translateY(-1px);
}

.community-controls button {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    border: none;
    border-radius: 14px;
    padding: 16px 32px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35), 0 2px 6px rgba(59, 130, 246, 0.2);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: 0.3px;
    position: relative;
    overflow: hidden;
}

.community-controls button::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.community-controls button:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 10px 30px rgba(59, 130, 246, 0.45), 0 4px 12px rgba(59, 130, 246, 0.3);
}

.community-controls button:hover::before {
    left: 100%;
}

.community-controls button:active {
    transform: translateY(-1px) scale(0.98);
}

.community-insights {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.community-section {
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    display: flex;
    flex-direction: column;
    gap: 16px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.community-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    opacity: 0.6;
}

.community-section:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.community-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.community-section__header small {
    text-transform: uppercase;
    font-size: 11px;
    color: #64748b;
    letter-spacing: 0.12em;
    font-weight: 700;
}

.community-section__header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.2px;
}

.community-section__header h3 i {
    color: #3b82f6;
    font-size: 16px;
}

.community-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.community-list__item {
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 8px;
    padding: 14px 16px;
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.community-list__item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.community-list__item:hover {
    transform: translateX(4px);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08), 0 2px 6px rgba(0, 0, 0, 0.04);
    border-color: rgba(59, 130, 246, 0.3);
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.03) 0%, white 10%);
}

.community-list__item:hover::before {
    opacity: 1;
}

.community-list__title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    justify-content: space-between;
    gap: 8px;
}

.community-list__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.community-list__meta span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.community-list__meta i {
    color: #94a3b8;
}

.teacher-update-item {
    border-left: 3px solid #3b82f6;
    padding-left: 12px;
}

.event-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.12);
    color: #1e3a8a;
    font-size: 11px;
    font-weight: 600;
}

.community-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.community-card {
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    padding: 18px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    display: flex;
    flex-direction: column;
    gap: 14px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}


.community-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.community-card__header {
    display: flex;
    gap: 16px;
    align-items: center;
    position: relative;
    z-index: 1;
}

.community-avatar {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    font-weight: 700;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}

.community-avatar::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.community-card:hover .community-avatar {
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.4), 0 6px 16px rgba(59, 130, 246, 0.3);
}

.community-card:hover .community-avatar::before {
    opacity: 1;
}

.community-card__title {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}

.community-card__title h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.3px;
    line-height: 1.3;
}

.community-card__title span {
    font-size: 14px;
    color: #64748b;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

.community-card__stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 16px;
    padding: 20px 16px;
    gap: 16px;
    border: 1px solid rgba(148, 163, 184, 0.15);
    font-size: 13px;
    text-align: center;
    font-weight: 700;
    color: #475569;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
    position: relative;
    z-index: 1;
}

.community-card__stats > div {
    padding: 8px;
    border-radius: 12px;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.5);
}

.community-card__stats > div:hover {
    background: rgba(59, 130, 246, 0.08);
    transform: translateY(-2px);
}

.community-card__children {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    position: relative;
    z-index: 1;
}

.child-chip {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(139, 92, 246, 0.12));
    color: #1e40af;
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    border: 1px solid rgba(59, 130, 246, 0.2);
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.child-chip:hover {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(139, 92, 246, 0.2));
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.community-card__meetings {
    border-top: 2px solid rgba(148, 163, 184, 0.1);
    padding-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.meeting-entry {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 16px 18px;
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.12);
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.meeting-entry::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.meeting-entry:hover {
    transform: translateX(4px);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08), 0 2px 6px rgba(0, 0, 0, 0.04);
    border-color: rgba(59, 130, 246, 0.3);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.03) 0%, #ffffff 10%);
}

.meeting-entry:hover::before {
    opacity: 1;
}

.meeting-entry strong {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.2px;
    line-height: 1.4;
}

.meeting-entry small {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

.meeting-entry small i {
    color: #3b82f6;
    font-size: 12px;
    width: 16px;
    text-align: center;
}

.mini-empty-state {
    padding: 40px 32px;
    text-align: center;
    color: #94a3b8;
    font-size: 14px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 16px;
    border: 2px dashed rgba(148, 163, 184, 0.3);
    font-weight: 500;
}

.no-results-box {
    margin-top: 30px;
    text-align: center;
    padding: 80px 40px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    border: 2px dashed rgba(148, 163, 184, 0.3);
    color: #475569;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
}

.no-results-box i {
    opacity: 0.5;
    filter: grayscale(0.3);
    transition: all 0.3s ease;
}

.no-results-box:hover i {
    opacity: 0.7;
    filter: grayscale(0);
    transform: scale(1.1);
}

.no-results-box h3 {
    margin: 24px 0 12px;
    color: #0f172a;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.no-results-box p {
    margin: 0;
    color: #64748b;
    font-size: 15px;
    font-weight: 500;
    line-height: 1.6;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .parent-community-page {
        margin-left: 0 !important;
        width: 100%;
    }

    .community-tabs {
        padding-left: 12px;
        padding-right: 12px;
    }

    .community-tab {
        padding: 12px 12px;
        font-size: 13px;
        gap: 6px;
    }

    .community-tab i {
        font-size: 13px;
    }

    .community-tab-content {
        padding: 16px;
    }

    .community-kpis {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 16px;
    }

    .community-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .community-tabs {
        padding: 0 8px;
        gap: 2px;
    }

    .community-tab {
        padding: 10px 8px;
        font-size: 12px;
        gap: 4px;
    }

    .community-tab i {
        font-size: 12px;
        margin-right: 0;
    }

    .community-tab span {
        display: none;
    }

    .community-tab-content {
        padding: 16px 12px;
    }

    .community-kpis {
        grid-template-columns: 1fr;
    }

    .community-grid {
        grid-template-columns: 1fr;
    }

    .community-controls {
        flex-direction: column;
        align-items: stretch;
    }

    .community-controls form {
        flex-direction: column;
        width: 100%;
    }

    .community-controls input[type="text"],
    .community-controls select {
        width: 100%;
        min-width: auto;
    }
}
</style>

<div class="parent-community-page">
    <div class="community-main-container">
        <!-- Professional Header -->
        <div style="background: #ffffff; padding: 20px 24px; border-bottom: 1px solid #e2e8f0; box-shadow: none; position: relative; overflow: hidden; border-top-left-radius: 16px; border-top-right-radius: 16px;">
            <div style="max-width: 1400px; margin: 0 auto;">
                <h1 style="font-size: 22px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 12px; letter-spacing: -0.4px; color: #0f172a;">
                    <div style="width: 40px; height: 40px; background: #3b82f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                    <i class="fas fa-users" style="font-size: 18px; color: white;"></i>
                </div>
                Parent Community Hub
            </h1>
            <p style="margin: 6px 0 0; color: #64748b; font-size: 13px; font-weight: 400; line-height: 1.5;">
                Connect with parents, teachers, and stay informed about your child's educational community
            </p>
        </div>
    </div>
    
    <!-- Simple Tab Switching Script - Must Load First -->
    <script>
    function showCommunityTab(tabName) {
        console.log('=== SWITCHING TAB ===', tabName);
        
        // Hide all tab contents using !important
        var contents = document.querySelectorAll('.community-tab-content');
        console.log('Found', contents.length, 'tab contents');
        for (var i = 0; i < contents.length; i++) {
            contents[i].style.setProperty('display', 'none', 'important');
            contents[i].classList.remove('active');
        }
        
        // Remove active from all buttons
        var buttons = document.querySelectorAll('.community-tab');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('active');
        }
        
        // Show selected tab with !important
        var tabContent = document.getElementById('tab-' + tabName);
        var tabButton = document.querySelector('.community-tab[data-tab="' + tabName + '"]');
        
        if (tabContent) {
            tabContent.style.setProperty('display', 'block', 'important');
            tabContent.classList.add('active');
            console.log('✓ Tab content shown:', tabName, tabContent);
        } else {
            console.error('✗ Tab not found: tab-' + tabName);
            // Show overview as fallback
            var overview = document.getElementById('tab-overview');
            if (overview) {
                overview.style.setProperty('display', 'block', 'important');
                overview.classList.add('active');
            }
        }
        
        if (tabButton) {
            tabButton.classList.add('active');
        }
        
        window.scrollTo(0, 0);
        return false;
    }
    
    // Make function available globally
    window.showCommunityTab = showCommunityTab;
    console.log('showCommunityTab function loaded');
    
    // Initialize overview tab on page load
    window.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing overview tab');
        var overview = document.getElementById('tab-overview');
        if (overview) {
            overview.style.setProperty('display', 'block', 'important');
            overview.classList.add('active');
        }
    });
    
    // Also try immediately
    setTimeout(function() {
        var overview = document.getElementById('tab-overview');
        if (overview && overview.style.display === 'none') {
            overview.style.setProperty('display', 'block', 'important');
            overview.classList.add('active');
        }
    }, 100);
    </script>
    
    <!-- Tab Navigation -->
    <div class="community-tabs">
        <button class="community-tab active" data-tab="overview" onclick="return showCommunityTab('overview');">
            <i class="fas fa-chart-pie"></i>
            <span>Overview</span>
        </button>
        <button class="community-tab" data-tab="parents" onclick="return showCommunityTab('parents');">
            <i class="fas fa-users"></i>
            <span>Parents Directory</span>
        </button>
        <button class="community-tab" data-tab="announcements" onclick="return showCommunityTab('announcements');">
            <i class="fas fa-bullhorn"></i>
            <span>Announcements</span>
        </button>
        <button class="community-tab" data-tab="events" onclick="return showCommunityTab('events');">
            <i class="fas fa-calendar-day"></i>
            <span>Events & Notices</span>
        </button>
        <button class="community-tab" data-tab="assignments" onclick="return showCommunityTab('assignments');">
            <i class="fas fa-tasks"></i>
            <span>Assignments & Quizzes</span>
        </button>
        <button class="community-tab" data-tab="grades" onclick="return showCommunityTab('grades');">
            <i class="fas fa-star"></i>
            <span>Recent Grades</span>
        </button>
        <button class="community-tab" data-tab="resources" onclick="return showCommunityTab('resources');">
            <i class="fas fa-folder-open"></i>
            <span>Resources</span>
        </button>
        <button class="community-tab" data-tab="activity" onclick="return showCommunityTab('activity');">
            <i class="fas fa-history"></i>
            <span>Recent Activity</span>
        </button>
        <button class="community-tab" data-tab="teachers" onclick="return showCommunityTab('teachers');">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Teachers</span>
            <?php if (!empty($teacher_directory)): ?>
            <span style="background: #ef4444; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 6px;">
                <?php echo count($teacher_directory); ?>
            </span>
            <?php endif; ?>
        </button>
        <button class="community-tab" data-tab="groups" onclick="return showCommunityTab('groups');">
            <i class="fas fa-users-cog"></i>
            <span>Parent Groups</span>
            <?php if (!empty($parent_groups)): ?>
            <span style="background: #3b82f6; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 6px;">
                <?php echo count($parent_groups); ?>
            </span>
            <?php endif; ?>
        </button>
        <button class="community-tab" data-tab="networking" onclick="return showCommunityTab('networking');">
            <i class="fas fa-network-wired"></i>
            <span>Networking</span>
        </button>
    </div>

    <!-- Overview Tab -->
    <div class="community-tab-content active" id="tab-overview" style="display: block !important;">
        <div class="community-kpis">
        <div class="community-kpi-card">
            <small>My Children</small>
            <strong><?php echo number_format(count($my_children)); ?></strong>
        </div>
        <div class="community-kpi-card">
            <small>Enrolled Courses</small>
            <strong><?php echo number_format(count($my_courses_map)); ?></strong>
        </div>
        <div class="community-kpi-card">
            <small>Upcoming Deadlines</small>
            <strong><?php 
                $total_deadlines = 0;
                foreach ($child_overview_data as $data) {
                    $total_deadlines += count($data['upcoming_deadlines']);
                }
                echo number_format($total_deadlines);
            ?></strong>
        </div>
        <div class="community-kpi-card">
            <small>Recent Announcements</small>
            <strong><?php 
                $total_announcements = 0;
                foreach ($child_overview_data as $data) {
                    $total_announcements += count($data['recent_announcements']);
                }
                echo number_format($total_announcements);
            ?></strong>
        </div>
        <div class="community-kpi-card">
            <small>Recent Grades</small>
            <strong><?php 
                $total_recent_grades = 0;
                foreach ($child_overview_data as $data) {
                    $total_recent_grades += count($data['recent_grades']);
                }
                echo number_format($total_recent_grades);
            ?></strong>
        </div>
        <?php if (!empty($attendance_summary)): ?>
        <div class="community-kpi-card">
            <small>Avg Attendance</small>
            <strong><?php 
                $avg_attendance = 0;
                $att_count = 0;
                foreach ($attendance_summary as $att) {
                    $avg_attendance += $att['rate'];
                    $att_count++;
                }
                echo $att_count > 0 ? number_format($avg_attendance / $att_count, 1) . '%' : 'N/A';
            ?></strong>
        </div>
        <?php endif; ?>
        <div class="community-kpi-card">
            <small>Upcoming Events</small>
            <strong><?php echo number_format(count($events_notices)); ?></strong>
        </div>
        </div>
        
        <!-- Per-Child Overview -->
        <?php if (!empty($child_overview_data)): ?>
        <section class="community-insights" style="margin-top: 24px;">
            <?php foreach ($child_overview_data as $child_id => $overview): ?>
            <div class="community-section" style="grid-column: 1 / -1;">
                <div class="community-section__header">
                    <div>
                        <small>Child Overview</small>
                        <h3><i class="fas fa-user-graduate"></i><?php echo s($overview['child']['name']); ?> - Overview</h3>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 16px;">
                    <!-- Enrolled Courses -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <i class="fas fa-book" style="color: #3b82f6; font-size: 18px;"></i>
                            <strong style="font-size: 14px; color: #0f172a;">Enrolled Courses</strong>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 8px;">
                            <?php echo $overview['course_count']; ?>
                        </div>
                        <?php if (!empty($overview['courses'])): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php 
                            $course_names = array_slice(array_column($overview['courses'], 'fullname'), 0, 3);
                            echo implode(', ', array_map('s', $course_names));
                            if (count($overview['courses']) > 3) echo '...';
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Attendance Summary -->
                    <?php if (!empty($overview['attendance'])): ?>
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <i class="fas fa-check-circle" style="color: #10b981; font-size: 18px;"></i>
                            <strong style="font-size: 14px; color: #0f172a;">Attendance (30 days)</strong>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 8px;">
                            <?php echo $overview['attendance']['rate']; ?>%
                        </div>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php echo $overview['attendance']['present_count']; ?> of <?php echo $overview['attendance']['total_sessions']; ?> sessions
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Upcoming Deadlines -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <i class="fas fa-calendar-alt" style="color: #f59e0b; font-size: 18px;"></i>
                            <strong style="font-size: 14px; color: #0f172a;">Upcoming Deadlines</strong>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 8px;">
                            <?php echo count($overview['upcoming_deadlines']); ?>
                        </div>
                        <div style="font-size: 12px; color: #64748b;">
                            Next 30 days
                        </div>
                    </div>
                    
                    <!-- Recent Announcements -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <i class="fas fa-bullhorn" style="color: #8b5cf6; font-size: 18px;"></i>
                            <strong style="font-size: 14px; color: #0f172a;">Recent Announcements</strong>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 8px;">
                            <?php echo count($overview['recent_announcements']); ?>
                        </div>
                        <div style="font-size: 12px; color: #64748b;">
                            Last 30 days
                        </div>
                    </div>
                </div>
                
                <!-- Upcoming Deadlines List -->
                <?php if (!empty($overview['upcoming_deadlines'])): ?>
                <div style="margin-top: 20px;">
                    <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">
                        <i class="fas fa-tasks" style="color: #f59e0b; margin-right: 6px;"></i>Upcoming Deadlines
                    </h4>
                    <div class="community-list">
                        <?php foreach (array_slice($overview['upcoming_deadlines'], 0, 5) as $deadline): ?>
                        <div class="community-list__item">
                            <div class="community-list__title">
                                <span><?php echo s($deadline['name']); ?></span>
                                <span style="font-size: 11px; color: #64748b;">
                                    <?php echo userdate($deadline['duedate'], '%d %b %Y'); ?>
                                </span>
                            </div>
                            <div class="community-list__meta">
                                <span><i class="fas fa-<?php echo $deadline['type'] === 'assignment' ? 'file-alt' : 'question-circle'; ?>"></i><?php echo ucfirst($deadline['type']); ?></span>
                                <span><i class="fas fa-book"></i><?php echo s($deadline['course']); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Recent Announcements List -->
                <?php if (!empty($overview['recent_announcements'])): ?>
                <div style="margin-top: 20px;">
                    <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">
                        <i class="fas fa-bullhorn" style="color: #8b5cf6; margin-right: 6px;"></i>Recent Announcements
                    </h4>
                    <div class="community-list">
                        <?php foreach (array_slice($overview['recent_announcements'], 0, 5) as $announcement): 
                            $author = (object)['firstname' => $announcement->firstname ?? '', 'lastname' => $announcement->lastname ?? ''];
                            $summary = $trim_text(format_text($announcement->message ?? '', $announcement->messageformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 120);
                        ?>
                        <div class="community-list__item">
                            <div class="community-list__title">
                                <span><?php echo format_string($announcement->subject ?? ''); ?></span>
                                <span style="font-size: 11px; color: #94a3b8;">
                                    <?php echo userdate($announcement->created ?? time(), '%d %b %Y'); ?>
                                </span>
                            </div>
                            <?php if ($summary): ?>
                            <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($summary); ?></p>
                            <?php endif; ?>
                            <div class="community-list__meta">
                                <span><i class="fas fa-user"></i><?php echo fullname($author); ?></span>
                                <span><i class="fas fa-book"></i><?php echo s($announcement->coursename ?? ''); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Recent Grades List -->
                <?php if (!empty($overview['recent_grades'])): ?>
                <div style="margin-top: 20px;">
                    <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">
                        <i class="fas fa-star" style="color: #f59e0b; margin-right: 6px;"></i>Recent Grades
                    </h4>
                    <div class="community-list">
                        <?php foreach (array_slice($overview['recent_grades'], 0, 5) as $grade): 
                            $grade_percent = 0;
                            if (!empty($grade->finalgrade)) {
                                // Try to get max grade
                                $max_grade = $DB->get_field('grade_items', 'grademax', ['id' => $grade->id]);
                                if ($max_grade > 0) {
                                    $grade_percent = round(($grade->finalgrade / $max_grade) * 100, 1);
                                }
                            }
                            $grade_badge = $grade_percent >= 90 ? 'Excellent' : ($grade_percent >= 75 ? 'Good' : ($grade_percent >= 60 ? 'Fair' : 'Needs Improvement'));
                            $grade_color = $grade_percent >= 90 ? '#10b981' : ($grade_percent >= 75 ? '#3b82f6' : ($grade_percent >= 60 ? '#f59e0b' : '#ef4444'));
                        ?>
                        <div class="community-list__item">
                            <div class="community-list__title">
                                <span><?php echo s($grade->itemname ?? 'Grade'); ?></span>
                                <span style="font-size: 11px; padding: 4px 8px; border-radius: 6px; background: <?php echo $grade_color; ?>15; color: <?php echo $grade_color; ?>; font-weight: 600;">
                                    <?php echo $grade_percent > 0 ? $grade_percent . '%' : 'Graded'; ?>
                                </span>
                            </div>
                            <div class="community-list__meta">
                                <span><i class="fas fa-book"></i><?php echo s($grade->coursename ?? ''); ?></span>
                                <span><i class="fas fa-clock"></i><?php echo userdate($grade->timemodified ?? time(), '%d %b %Y'); ?></span>
                                <span style="color: <?php echo $grade_color; ?>; font-weight: 600;"><?php echo s($grade_badge); ?></span>
                            </div>
                            <?php if (!empty($grade->feedback)): ?>
                            <p style="margin: 4px 0 0; font-size: 12px; color: #64748b; font-style: italic;">
                                <?php echo s($trim_text(format_text($grade->feedback, $grade->feedbackformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 100)); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="community-insights">
            <div class="community-section">
                <div class="community-section__header">
                    <div>
                        <small>Community Feed</small>
                        <h3><i class="fas fa-users"></i>Most Active Parents</h3>
                    </div>
                </div>
                <div class="community-list">
                    <?php if (!empty($community_feed)): ?>
                        <?php foreach ($community_feed as $feed): ?>
                            <div class="community-list__item">
                                <div class="community-list__title">
                                    <span><?php echo s($feed['name']); ?></span>
                                    <span style="font-size: 11px; color: #94a3b8;"><?php echo count($feed['children']); ?> child<?php echo count($feed['children']) === 1 ? '' : 'ren'; ?></span>
                                </div>
                                <div class="community-list__meta">
                                    <span><i class="fas fa-calendar-check"></i><?php echo $feed['counts']['upcoming']; ?> upcoming meetings</span>
                                    <span><i class="fas fa-history"></i><?php echo $feed['counts']['past']; ?> past</span>
                                </div>
                                <?php if (!empty($feed['meetings'])): ?>
                                <p style="margin: 4px 0 0; font-size: 12px; color: #475569;">
                                    Next: <strong><?php echo s($feed['meetings'][0]['subject']); ?></strong> on <?php echo userdate($feed['meetings'][0]['timestamp'] ?? time(), '%d %b'); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="mini-empty-state">Community activity will appear here as meetings are scheduled.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="community-section">
                <div class="community-section__header">
                    <div>
                        <small>Teacher/Class Updates</small>
                        <h3><i class="fas fa-chalkboard-teacher"></i>Teacher & Class Updates</h3>
                    </div>
                </div>
                <div class="community-list">
                    <?php if (!empty($teacher_updates)): ?>
                        <?php foreach ($teacher_updates as $update):
                            $teacher = (object)[
                                'firstname' => $update->firstname ?? '',
                                'lastname' => $update->lastname ?? ''
                            ];
                            $actionlabel = ucfirst($update->action ?? '');
                            $targetlabel = $update->target ? ucwords(str_replace('_', ' ', $update->target)) : 'activity';
                        ?>
                        <div class="community-list__item teacher-update-item">
                            <div class="community-list__title">
                                <span><?php echo s($actionlabel . ' ' . $targetlabel); ?></span>
                                <span style="font-size: 11px; color: #94a3b8;"><?php echo userdate($update->timecreated ?? time(), '%d %b %Y'); ?></span>
                            </div>
                            <p style="margin: 0; font-size: 13px; color: #475569;">
                                <strong><?php echo fullname($teacher); ?></strong>
                                <?php if (!empty($update->coursename)): ?>
                                    <span style="color: #94a3b8;"> • <?php echo s($update->coursename); ?></span>
                                <?php endif; ?>
                            </p>
                            <div class="community-list__meta">
                                <span><i class="fas fa-clock"></i><?php echo userdate($update->timecreated ?? time(), '%I:%M %p'); ?></span>
                                <?php if (!empty($update->component)): 
                                    $component_parts = explode('_', $update->component);
                                    $component_display = !empty($component_parts[1]) ? ucwords(str_replace('_', ' ', $component_parts[1])) : ucwords(str_replace('_', ' ', $update->component));
                                ?>
                                    <span><i class="fas fa-layer-group"></i><?php echo s($component_display); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="mini-empty-state">No recent teacher updates for your child courses.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- Parents Directory Tab -->
    <div class="community-tab-content" id="tab-parents">
        <?php if (!$can_view_participants): ?>
        <div class="community-section">
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas fa-lock" style="font-size: 48px; color: #94a3b8; margin-bottom: 16px;"></i>
                <h3 style="color: #0f172a; margin-bottom: 8px;">Access Restricted</h3>
                <p style="color: #64748b; font-size: 14px;">You don't have permission to view the parents directory. This feature requires the 'moodle/site:viewparticipants' capability.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="community-controls">
            <form method="get">
                <input type="text" name="search" value="<?php echo s($search); ?>" placeholder="Search parents by name, email, city..." />
                <select name="meetingfilter">
                    <option value="upcoming" <?php echo $meetingfilter === 'upcoming' ? 'selected' : ''; ?>>Upcoming meetings</option>
                    <option value="past" <?php echo $meetingfilter === 'past' ? 'selected' : ''; ?>>Past meetings</option>
                    <option value="all" <?php echo $meetingfilter === 'all' ? 'selected' : ''; ?>>All meetings</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i>&nbsp;Apply</button>
            </form>
        </div>

        <?php if (empty($communitycards)): ?>
            <div class="no-results-box">
                <i class="fas fa-users" style="font-size: 42px; color: #94a3b8; margin-bottom: 16px;"></i>
                <h3>No parents found</h3>
                <p>Try adjusting your search or meeting filter to see more community members.</p>
            </div>
        <?php else: ?>
            <div class="community-grid">
                <?php foreach ($communitycards as $card): ?>
                    <div class="community-card" data-parent-name="<?php echo s(core_text::strtolower($card['name'])); ?>">
                        <div class="community-card__header">
                            <div class="community-avatar">
                                <?php echo strtoupper(core_text::substr($card['name'], 0, 1)); ?>
                            </div>
                            <div class="community-card__title">
                                <h3><?php echo s($card['name']); ?></h3>
                                <span><?php echo s($card['user']->city ?? ''); ?></span>
                                <span style="font-size: 12px;"><i class="fas fa-envelope"></i> <?php echo s($card['user']->email); ?></span>
                            </div>
                        </div>

                        <div class="community-card__stats">
                            <div>
                                Upcoming<br><?php echo $card['counts']['upcoming']; ?>
                            </div>
                            <div>
                                Completed<br><?php echo $card['counts']['past']; ?>
                            </div>
                            <div>
                                Total<br><?php echo $card['counts']['all']; ?>
                            </div>
                        </div>

                        <?php if (!empty($card['children'])): ?>
                            <div class="community-card__children">
                                <?php foreach ($card['children'] as $child): ?>
                                    <span class="child-chip"><i class="fas fa-child"></i> <?php echo s($child['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="community-card__meetings">
                            <?php if (empty($card['meetings'])): ?>
                                <div class="meeting-entry" style="background: #fff;">
                                    <strong>No meetings in this view</strong>
                                    <small>Switch the meeting filter above to explore other timelines.</small>
                                </div>
                            <?php else: ?>
                                <?php foreach ($card['meetings'] as $meeting): ?>
                                    <div class="meeting-entry">
                                        <strong><?php echo s($meeting['subject']); ?></strong>
                                        <small><i class="fas fa-calendar-alt"></i> <?php echo s($meeting['date']); ?> • <?php echo s($meeting['time']); ?> (<?php echo s($meeting['duration']); ?> min)</small>
                                        <?php if (!empty($meeting['child_name'])): ?>
                                            <small><i class="fas fa-user-graduate"></i> <?php echo s($meeting['child_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($meeting['teacher_name'])): ?>
                                            <small><i class="fas <?php echo $meeting['type'] === 'virtual' ? 'fa-video' : 'fa-chalkboard-teacher'; ?>"></i> <?php echo s($meeting['teacher_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($meeting['location']) || !empty($meeting['meeting_link'])): ?>
                                            <small>
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo s($meeting['location'] ?: $meeting['meeting_link']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; // End can_view_participants check ?>
    </div>

    <!-- Announcements Tab -->
    <div class="community-tab-content" id="tab-announcements">
        <div class="community-section">
            <div class="community-section__header">
                <div>
                    <small>Announcements</small>
                    <h3><i class="fas fa-bullhorn"></i>Announcements (Read-Only)</h3>
                </div>
                <div style="font-size: 12px; color: #64748b;">
                    <i class="fas fa-info-circle"></i> From site, category, and course levels
                </div>
            </div>
            <div class="community-list">
                <?php if (!empty($announcements)): ?>
                    <?php foreach ($announcements as $announcement): 
                        $author = (object)[
                            'firstname' => $announcement->firstname ?? '',
                            'lastname' => $announcement->lastname ?? ''
                        ];
                        $summary = $trim_text(format_text($announcement->message ?? '', $announcement->messageformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]));
                        $level = $announcement->announcement_level ?? 'course';
                        $level_badge = [
                            'site' => ['label' => 'Site', 'color' => '#8b5cf6'],
                            'category' => ['label' => 'Category', 'color' => '#3b82f6'],
                            'course' => ['label' => 'Course', 'color' => '#10b981'],
                        ];
                        $badge = $level_badge[$level] ?? $level_badge['course'];
                    ?>
                    <div class="community-list__item">
                        <div class="community-list__title">
                            <span><?php echo format_string($announcement->subject ?? ''); ?></span>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 10px; padding: 3px 8px; border-radius: 6px; background: <?php echo $badge['color']; ?>15; color: <?php echo $badge['color']; ?>; font-weight: 600;">
                                    <?php echo s($badge['label']); ?>
                                </span>
                            <span style="font-size: 11px; color: #94a3b8;"><?php echo userdate($announcement->modified ?? time(), '%d %b %Y'); ?></span>
                            </div>
                        </div>
                        <?php if ($summary): ?>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($summary); ?></p>
                        <?php endif; ?>
                        <div class="community-list__meta">
                            <span><i class="fas fa-user"></i><?php echo fullname($author); ?></span>
                            <?php if (!empty($announcement->coursename)): ?>
                            <span><i class="fas fa-book"></i><?php echo s($announcement->coursename); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($announcement->categoryname)): ?>
                            <span><i class="fas fa-folder"></i><?php echo s($announcement->categoryname); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-clock"></i><?php echo userdate($announcement->modified ?? time(), get_string('strftimetime', 'langconfig')); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mini-empty-state">No announcements found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Events & Notices Tab -->
    <div class="community-tab-content" id="tab-events">
        <div class="community-section">
            <div class="community-section__header">
                <div>
                    <small>Events & Notices</small>
                    <h3><i class="fas fa-calendar-day"></i>Events & Notices</h3>
                </div>
            </div>
            <div class="community-list">
                <?php if (!empty($events_notices)): ?>
                    <?php foreach ($events_notices as $event): 
                        $desc = $trim_text(format_text($event->description ?? '', FORMAT_HTML, ['para' => false, 'filter' => true]), 150);
                        $evttype = ucfirst($event->eventtype ?? 'event');
                    ?>
                    <div class="community-list__item">
                        <div class="community-list__title">
                            <span><?php echo format_string($event->name ?? ''); ?></span>
                            <span class="event-badge"><i class="fas fa-tag"></i><?php echo s($evttype); ?></span>
                        </div>
                        <div class="community-list__meta">
                            <span><i class="fas fa-calendar-alt"></i><?php echo userdate($event->timestart ?? time(), '%d %b %Y, %I:%M %p'); ?></span>
                            <?php if (!empty($event->userid) && isset($child_id_to_name[$event->userid])): ?>
                                <span><i class="fas fa-user-graduate"></i><?php echo s($child_id_to_name[$event->userid]); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event->coursename)): ?>
                                <span><i class="fas fa-book"></i><?php echo s($event->coursename); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event->location)): ?>
                                <span><i class="fas fa-map-marker-alt"></i><?php echo s($event->location); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($desc): ?>
                            <p style="margin: 4px 0 0; font-size: 12px; color: #475569;"><?php echo s($desc); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mini-empty-state">No upcoming events for your family right now.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Assignments & Quizzes Tab -->
    <div class="community-tab-content" id="tab-assignments">
        <div class="community-insights">
            <div class="community-section">
                <div class="community-section__header">
                    <div>
                        <small>Upcoming Assignments</small>
                        <h3><i class="fas fa-tasks"></i>Upcoming Assignments</h3>
                    </div>
                </div>
                <div class="community-list">
                    <?php if (!empty($upcoming_assignments)): ?>
                        <?php foreach ($upcoming_assignments as $assignment): 
                            $desc = $trim_text(format_text($assignment->intro ?? '', $assignment->introformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 120);
                            $days_until = round(($assignment->duedate - time()) / DAYSECS);
                        ?>
                        <div class="community-list__item">
                            <div class="community-list__title">
                                <span><?php echo format_string($assignment->name ?? ''); ?></span>
                                <span style="font-size: 11px; color: <?php echo $days_until <= 3 ? '#ef4444' : ($days_until <= 7 ? '#f59e0b' : '#64748b'); ?>;">
                                    <?php echo $days_until > 0 ? $days_until . ' days left' : 'Due today'; ?>
                                </span>
                            </div>
                            <?php if ($desc): ?>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($desc); ?></p>
                            <?php endif; ?>
                            <div class="community-list__meta">
                                <span><i class="fas fa-book"></i><?php echo s($assignment->coursename ?? ''); ?></span>
                                <span><i class="fas fa-calendar-alt"></i><?php echo userdate($assignment->duedate ?? time(), '%d %b %Y, %I:%M %p'); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="mini-empty-state">No upcoming assignments in the next 30 days.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="community-section">
                <div class="community-section__header">
                    <div>
                        <small>Upcoming Quizzes</small>
                        <h3><i class="fas fa-question-circle"></i>Upcoming Quizzes</h3>
                    </div>
                </div>
                <div class="community-list">
                    <?php if (!empty($upcoming_quizzes)): ?>
                        <?php foreach ($upcoming_quizzes as $quiz): 
                            $desc = $trim_text(format_text($quiz->intro ?? '', $quiz->introformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 120);
                            $days_until = round(($quiz->timeclose - time()) / DAYSECS);
                        ?>
                        <div class="community-list__item">
                            <div class="community-list__title">
                                <span><?php echo format_string($quiz->name ?? ''); ?></span>
                                <span style="font-size: 11px; color: <?php echo $days_until <= 3 ? '#ef4444' : ($days_until <= 7 ? '#f59e0b' : '#64748b'); ?>;">
                                    <?php echo $days_until > 0 ? $days_until . ' days left' : 'Closes today'; ?>
                                </span>
                            </div>
                            <?php if ($desc): ?>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($desc); ?></p>
                            <?php endif; ?>
                            <div class="community-list__meta">
                                <span><i class="fas fa-book"></i><?php echo s($quiz->coursename ?? ''); ?></span>
                                <span><i class="fas fa-clock"></i><?php echo userdate($quiz->timeclose ?? time(), '%d %b %Y, %I:%M %p'); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="mini-empty-state">No upcoming quizzes in the next 30 days.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Grades Tab -->
    <div class="community-tab-content" id="tab-grades">
        <?php 
        // Check parent viewing permissions for grades
        $can_view_grades = true;
        if (!empty($my_child_ids)) {
            $parent_role_id = $DB->get_field('role', 'id', ['shortname' => 'parent']);
            if ($parent_role_id) {
                $can_view_grades = $DB->record_exists_sql(
                    "SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                     WHERE ra.userid = :userid
                       AND ra.roleid = :roleid
                       AND ctx.contextlevel = :ctxuser
                       AND ctx.instanceid IN (" . implode(',', array_map('intval', $my_child_ids)) . ")",
                    ['userid' => $USER->id, 'roleid' => $parent_role_id, 'ctxuser' => CONTEXT_USER]
                );
            }
        }
        
        // Calculate grade trends
        $grade_trends = [];
        if (!empty($my_child_ids) && !empty($my_course_ids)) {
            try {
                list($trend_child_sql, $trend_child_params) = $DB->get_in_or_equal($my_child_ids, SQL_PARAMS_NAMED, 'trend_child');
                list($trend_course_sql, $trend_course_params) = $DB->get_in_or_equal($my_course_ids, SQL_PARAMS_NAMED, 'trend_course');
                
                $current_period_start = time() - (DAYSECS * 14);
                $current_grades = $DB->get_records_sql(
                    "SELECT gg.userid, AVG(gg.finalgrade / NULLIF(gi.grademax, 0) * 100) as avg_grade
                       FROM {grade_grades} gg
                       JOIN {grade_items} gi ON gi.id = gg.itemid
                      WHERE gg.userid $trend_child_sql
                        AND gi.courseid $trend_course_sql
                        AND gg.finalgrade IS NOT NULL
                        AND gi.grademax > 0
                        AND gg.timemodified >= :current_start
                        AND gi.itemtype = 'mod'
                   GROUP BY gg.userid",
                    array_merge($trend_child_params, $trend_course_params, ['current_start' => $current_period_start])
                );
                
                $previous_period_start = time() - (DAYSECS * 28);
                $previous_period_end = $current_period_start;
                $previous_grades = $DB->get_records_sql(
                    "SELECT gg.userid, AVG(gg.finalgrade / NULLIF(gi.grademax, 0) * 100) as avg_grade
                       FROM {grade_grades} gg
                       JOIN {grade_items} gi ON gi.id = gg.itemid
                      WHERE gg.userid $trend_child_sql
                        AND gi.courseid $trend_course_sql
                        AND gg.finalgrade IS NOT NULL
                        AND gi.grademax > 0
                        AND gg.timemodified >= :prev_start
                        AND gg.timemodified < :prev_end
                        AND gi.itemtype = 'mod'
                   GROUP BY gg.userid",
                    array_merge($trend_child_params, $trend_course_params, [
                        'prev_start' => $previous_period_start,
                        'prev_end' => $previous_period_end
                    ])
                );
                
                foreach ($current_grades as $userid => $current) {
                    $previous = $previous_grades[$userid] ?? null;
                    if ($previous && $previous->avg_grade > 0) {
                        $change = $current->avg_grade - $previous->avg_grade;
                        $grade_trends[$userid] = [
                            'change' => round($change, 1),
                            'percent_change' => round(($change / $previous->avg_grade) * 100, 1),
                            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
                        ];
                    }
                }
            } catch (Exception $e) {
                debugging('Grade trends calculation failed: ' . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($can_view_grades): ?>
        <div class="community-section">
            <div class="community-section__header">
                <div>
                    <small>Recent Grades & Feedback</small>
                    <h3><i class="fas fa-star"></i>Recent Grades & Feedback</h3>
                </div>
            </div>
            <div class="community-list">
                <?php if (!empty($recent_grades)): ?>
                    <?php foreach ($recent_grades as $grade): 
                        $child_name = isset($child_id_to_name[$grade->userid]) ? $child_id_to_name[$grade->userid] : 'Student';
                        $feedback = $trim_text(format_text($grade->feedback ?? '', $grade->feedbackformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 100);
                        
                        // Calculate grade percentage
                        $grade_percent = 0;
                        if (!empty($grade->finalgrade)) {
                            $max_grade = $DB->get_field('grade_items', 'grademax', ['id' => $grade->id]);
                            if ($max_grade > 0) {
                                $grade_percent = round(($grade->finalgrade / $max_grade) * 100, 1);
                            }
                        }
                        
                        // Grade badge
                        $grade_badge = $grade_percent >= 90 ? 'Excellent' : ($grade_percent >= 75 ? 'Good' : ($grade_percent >= 60 ? 'Fair' : 'Needs Improvement'));
                        $grade_color = $grade_percent >= 90 ? '#10b981' : ($grade_percent >= 75 ? '#3b82f6' : ($grade_percent >= 60 ? '#f59e0b' : '#ef4444'));
                        
                        // Grade trend
                        $trend = $grade_trends[$grade->userid] ?? null;
                    ?>
                    <div class="community-list__item">
                        <div class="community-list__title">
                            <span><?php echo format_string($grade->itemname ?? 'Grade'); ?></span>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($trend): ?>
                                <span style="font-size: 11px; padding: 3px 8px; border-radius: 6px; background: <?php echo $trend['direction'] === 'up' ? '#10b981' : ($trend['direction'] === 'down' ? '#ef4444' : '#64748b'); ?>15; color: <?php echo $trend['direction'] === 'up' ? '#10b981' : ($trend['direction'] === 'down' ? '#ef4444' : '#64748b'); ?>; font-weight: 600;">
                                    <i class="fas fa-arrow-<?php echo $trend['direction'] === 'up' ? 'up' : ($trend['direction'] === 'down' ? 'down' : 'right'); ?>"></i>
                                    <?php echo abs($trend['change']); ?>%
                                </span>
                                <?php endif; ?>
                                <span style="font-size: 11px; padding: 4px 8px; border-radius: 6px; background: <?php echo $grade_color; ?>15; color: <?php echo $grade_color; ?>; font-weight: 600;">
                                    <?php echo s($grade_badge); ?>
                                </span>
                            <span style="font-size: 14px; font-weight: 800; color: #3b82f6;">
                                    <?php echo $grade_percent > 0 ? number_format($grade_percent, 1) . '%' : 'Graded'; ?>
                            </span>
                            </div>
                        </div>
                        <?php if ($feedback): ?>
                            <p style="margin: 4px 0 0; font-size: 13px; color: #475569; font-style: italic;"><?php echo s($feedback); ?></p>
                        <?php endif; ?>
                        <div class="community-list__meta">
                            <span><i class="fas fa-user-graduate"></i><?php echo s($child_name); ?></span>
                            <span><i class="fas fa-book"></i><?php echo s($grade->coursename ?? ''); ?></span>
                            <span><i class="fas fa-clock"></i><?php echo userdate($grade->timemodified ?? time(), '%d %b %Y'); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mini-empty-state">No recent grades or feedback available.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="community-section">
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas fa-lock" style="font-size: 48px; color: #94a3b8; margin-bottom: 16px;"></i>
                <h3 style="color: #0f172a; margin-bottom: 8px;">Access Restricted</h3>
                <p style="color: #64748b; font-size: 14px;">You don't have permission to view grades. Please ensure you have the parent role assigned to your children.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Resources Tab -->
    <div class="community-tab-content" id="tab-resources">
        <div class="community-section">
            <div class="community-section__header">
                <div>
                    <small>School Resources</small>
                    <h3><i class="fas fa-folder-open"></i>School Resources & Files</h3>
                </div>
            </div>
            <div class="community-list">
                <?php if (!empty($school_resources)): ?>
                    <?php foreach ($school_resources as $resource): 
                        $file_size = $resource->filesize ?? 0;
                        $size_str = $file_size > 1048576 ? number_format($file_size / 1048576, 2) . ' MB' : number_format($file_size / 1024, 2) . ' KB';
                    ?>
                    <div class="community-list__item">
                        <div class="community-list__title">
                            <span><i class="fas fa-file"></i> <?php echo s($resource->filename ?? 'File'); ?></span>
                            <span style="font-size: 11px; color: #94a3b8;"><?php echo s($size_str); ?></span>
                        </div>
                        <div class="community-list__meta">
                            <span><i class="fas fa-book"></i><?php echo s($resource->coursename ?? ''); ?></span>
                            <span><i class="fas fa-clock"></i><?php echo userdate($resource->timemodified ?? time(), '%d %b %Y'); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mini-empty-state">No recent resources available.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($parent_groups)): ?>
        <div class="community-section" style="margin-top: 24px;">
            <div class="community-section__header">
                <div>
                    <small>Parent Groups</small>
                    <h3><i class="fas fa-users-cog"></i>Parent Groups & Cohorts</h3>
                </div>
            </div>
            <div class="community-list">
                <?php foreach ($parent_groups as $group): ?>
                <div class="community-list__item">
                    <div class="community-list__title">
                        <span><?php echo format_string($group->name ?? ''); ?></span>
                        <span style="font-size: 11px; color: #94a3b8;"><?php echo ($group->membercount ?? 0); ?> members</span>
                    </div>
                    <?php if (!empty($group->description)): 
                        $group_desc = $trim_text(format_text($group->description ?? '', $group->descriptionformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 150);
                    ?>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($group_desc); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Activity Tab -->
    <div class="community-tab-content" id="tab-activity">
        <div class="community-section">
            <div class="community-section__header">
                <div>
                    <small>Recent Activity</small>
                    <h3><i class="fas fa-history"></i>Recent Activity Feed</h3>
                </div>
            </div>
            <div class="community-list">
                <?php if (!empty($recent_activity)): ?>
                    <?php foreach ($recent_activity as $activity): 
                        $action_label = ucfirst($activity->action ?? '');
                        $target_label = $activity->target ? ucwords(str_replace('_', ' ', $activity->target)) : 'item';
                        $user_name = fullname((object)['firstname' => $activity->firstname ?? '', 'lastname' => $activity->lastname ?? '']);
                    ?>
                    <div class="community-list__item">
                        <div class="community-list__title">
                            <span><?php echo s($action_label . ' ' . $target_label); ?></span>
                            <span style="font-size: 11px; color: #94a3b8;"><?php echo userdate($activity->timecreated ?? time(), '%d %b %Y'); ?></span>
                        </div>
                        <p style="margin: 0; font-size: 13px; color: #475569;">
                            <strong><?php echo s($user_name); ?></strong>
                            <?php if (!empty($activity->coursename)): ?>
                                <span style="color: #94a3b8;"> • <?php echo s($activity->coursename); ?></span>
                            <?php endif; ?>
                        </p>
                        <div class="community-list__meta">
                            <span><i class="fas fa-clock"></i><?php echo userdate($activity->timecreated ?? time(), '%I:%M %p'); ?></span>
                            <?php if (!empty($activity->component)): 
                                $comp_parts = explode('_', $activity->component);
                                $comp_display = !empty($comp_parts[1]) ? ucwords(str_replace('_', ' ', $comp_parts[1])) : ucwords(str_replace('_', ' ', $activity->component));
                            ?>
                                <span><i class="fas fa-layer-group"></i><?php echo s($comp_display); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mini-empty-state">No recent activity to display.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($recent_forum_posts)): ?>
        <div class="community-section" style="margin-top: 24px;">
            <div class="community-section__header">
                <div>
                    <small>Recent Forum Posts</small>
                    <h3><i class="fas fa-comments"></i>Recent Forum Discussions</h3>
                </div>
            </div>
            <div class="community-list">
                <?php foreach ($recent_forum_posts as $post): 
                    $author = (object)['firstname' => $post->firstname ?? '', 'lastname' => $post->lastname ?? ''];
                    $summary = $trim_text(format_text($post->message ?? '', $post->messageformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 120);
                ?>
                <div class="community-list__item">
                    <div class="community-list__title">
                        <span><?php echo format_string($post->subject ?? $post->discussionname ?? ''); ?></span>
                        <span style="font-size: 11px; color: #94a3b8;"><?php echo userdate($post->created ?? time(), '%d %b'); ?></span>
                    </div>
                    <?php if ($summary): ?>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #475569;"><?php echo s($summary); ?></p>
                    <?php endif; ?>
                    <div class="community-list__meta">
                        <span><i class="fas fa-user"></i><?php echo fullname($author); ?></span>
                        <span><i class="fas fa-book"></i><?php echo s($post->coursename ?? ''); ?></span>
                        <span><i class="fas fa-comments"></i><?php echo s($post->forumname ?? ''); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Teachers Tab -->
    <div class="community-tab-content" id="tab-teachers">
        <div class="community-insights">
            <div class="community-section" style="grid-column: 1 / -1;">
                <div class="community-section__header">
                    <div>
                        <small>Teacher Directory</small>
                        <h3><i class="fas fa-chalkboard-teacher"></i>Your Child's Teachers</h3>
                    </div>
                    <div style="font-size: 12px; color: #64748b; font-weight: 600;">
                        <?php echo count($teacher_directory); ?> teacher<?php echo count($teacher_directory) != 1 ? 's' : ''; ?>
    </div>
</div>
                
                <?php if (!empty($teacher_directory)): ?>
                <div class="community-grid" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
                    <?php foreach ($teacher_directory as $teacher): 
                        // Get full user record for profile picture
                        try {
                            $teacher_user = $DB->get_record('user', ['id' => $teacher->id], '*', MUST_EXIST);
                        } catch (Exception $e) {
                            $teacher_user = (object)[
                                'id' => $teacher->id,
                                'firstname' => $teacher->firstname,
                                'lastname' => $teacher->lastname,
                                'picture' => $teacher->picture ?? 0,
                                'imagealt' => $teacher->imagealt ?? '',
                            ];
                        }
                        
                        $teacher_obj = $teacher_user;
                        $teacher_courses = $teacher_courses_map[$teacher->id] ?? [];
                        $messaging_enabled = $messaging_available;
                        
                        // Generate profile picture
                        $profile_picture_html = '';
                        try {
                            if (!empty($teacher_user->picture) && $teacher_user->picture > 0) {
                                $user_picture = new user_picture($teacher_user);
                                $user_picture->size = 64;
                                $profile_url = $user_picture->get_url($PAGE)->out(false);
                                $profile_picture_html = '<img src="' . htmlspecialchars($profile_url) . '" alt="' . htmlspecialchars(fullname($teacher_user)) . '" style="width: 100%; height: 100%; border-radius: 20px; object-fit: cover;" />';
                            } else {
                                $initials = strtoupper(core_text::substr($teacher->firstname ?? '', 0, 1) . core_text::substr($teacher->lastname ?? '', 0, 1));
                                $profile_picture_html = htmlspecialchars($initials);
                            }
                        } catch (Exception $e) {
                            $initials = strtoupper(core_text::substr($teacher->firstname ?? '', 0, 1) . core_text::substr($teacher->lastname ?? '', 0, 1));
                            $profile_picture_html = htmlspecialchars($initials);
                        }
                    ?>
                    <div class="community-card">
                        <div class="community-card__header">
                            <div class="community-avatar" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <?php echo $profile_picture_html; ?>
                            </div>
                            <div class="community-card__title">
                                <h3><?php echo fullname($teacher_obj); ?></h3>
                                <span><i class="fas fa-graduation-cap"></i> <?php echo isset($teacher->coursecount) ? $teacher->coursecount : count($teacher_courses); ?> course<?php echo (isset($teacher->coursecount) ? $teacher->coursecount : count($teacher_courses)) != 1 ? 's' : ''; ?></span>
                                <?php if (!empty($teacher->email)): ?>
                                <span style="font-size: 12px;"><i class="fas fa-envelope"></i> <?php echo s($teacher->email); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($teacher->phone1)): ?>
                                <span style="font-size: 12px;"><i class="fas fa-phone"></i> <?php echo s($teacher->phone1); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($teacher_courses)): ?>
                        <div style="background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border-radius: 12px; padding: 16px; border: 1px solid rgba(148, 163, 184, 0.15); margin-top: 16px;">
                            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                                <i class="fas fa-book-open" style="margin-right: 6px;"></i>Teaching Courses
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach (array_slice($teacher_courses, 0, 5) as $course): ?>
                                <div style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: white; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.1); transition: all 0.2s;">
                                    <i class="fas fa-book" style="color: #3b82f6; font-size: 13px;"></i>
                                    <span style="font-size: 13px; color: #475569; font-weight: 600; flex: 1;"><?php echo s($course['fullname']); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($teacher_courses) > 5): ?>
                                <div style="font-size: 11px; color: #94a3b8; text-align: center; padding: 6px; font-weight: 600;">
                                    +<?php echo count($teacher_courses) - 5; ?> more course<?php echo (count($teacher_courses) - 5) != 1 ? 's' : ''; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid rgba(148, 163, 184, 0.15); margin-top: 16px; text-align: center;">
                            <div style="font-size: 12px; color: #94a3b8;">
                                <i class="fas fa-info-circle" style="margin-right: 6px;"></i>No courses assigned
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($teacher->description)): 
                            $desc = $trim_text(format_text($teacher->description, $teacher->descriptionformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 120);
                        ?>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6; font-style: italic;">
                                <i class="fas fa-quote-left" style="color: #cbd5e1; margin-right: 6px;"></i><?php echo s($desc); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 10px; margin-top: auto;">
                            <?php if ($messaging_enabled): ?>
                            <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?convid=<?php echo $teacher->id; ?>" 
                               style="flex: 1; padding: 12px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 13px; text-align: center; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px;"
                               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(59, 130, 246, 0.4)'"
                               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                <i class="fas fa-comment"></i> Message
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($teacher->email)): ?>
                            <a href="mailto:<?php echo s($teacher->email); ?>" 
                               style="padding: 12px 20px; background: white; color: #3b82f6; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 13px; border: 2px solid #3b82f6; transition: all 0.3s; display: flex; align-items: center; justify-content: center;"
                               onmouseover="this.style.background='#3b82f6'; this.style.color='white'"
                               onmouseout="this.style.background='white'; this.style.color='#3b82f6'">
                                <i class="fas fa-envelope"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="mini-empty-state">No teachers found for your child's courses.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Parent Groups Tab -->
    <div class="community-tab-content" id="tab-groups">
        <div class="community-insights">
            <div class="community-section" style="grid-column: 1 / -1;">
                <div class="community-section__header">
                    <div>
                        <small>Parent Groups & Communities</small>
                        <h3><i class="fas fa-users-cog"></i>Join Parent Groups</h3>
                    </div>
                    <div style="font-size: 12px; color: #64748b; font-weight: 600;">
                        Connect with other parents
                    </div>
                </div>
                
                <?php if (!empty($parent_groups)): ?>
                <div class="community-grid" style="grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                    <?php foreach ($parent_groups as $group): 
                        $is_member = isset($parent_group_memberships[$group->id]) || ($group->is_member ?? 0) > 0;
                        $description = $trim_text(format_text($group->description ?? '', $group->descriptionformat ?? FORMAT_HTML, ['para' => false, 'filter' => true]), 150);
                    ?>
                    <div class="community-card" style="position: relative;">
                        <?php if ($is_member): ?>
                        <div style="position: absolute; top: 16px; right: 16px; background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; z-index: 10; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
                            <i class="fas fa-check-circle"></i> Member
                        </div>
                        <?php endif; ?>
                        
                        <div class="community-card__header">
                            <div class="community-avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); width: 56px; height: 56px; font-size: 20px;">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="community-card__title">
                                <h3><?php echo format_string($group->name); ?></h3>
                                <span><i class="fas fa-users"></i> <?php echo ($group->membercount ?? 0); ?> member<?php echo ($group->membercount ?? 0) != 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                        
                        <?php if ($description): ?>
                        <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                            <?php echo s($description); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div style="margin-top: auto; padding-top: 16px; border-top: 1px solid rgba(148, 163, 184, 0.15);">
                            <?php if ($is_member): ?>
                            <form method="post" action="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_community.php">
                                <input type="hidden" name="action" value="leavegroup">
                                <input type="hidden" name="cohortid" value="<?php echo $group->id; ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="tab" value="groups">
                                <button type="submit" 
                                        style="width: 100%; padding: 12px; background: white; color: #ef4444; border: 2px solid #ef4444; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px;"
                                        onmouseover="this.style.background='#ef4444'; this.style.color='white'"
                                        onmouseout="this.style.background='white'; this.style.color='#ef4444'">
                                    <i class="fas fa-sign-out-alt"></i> Leave Group
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="post" action="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_community.php">
                                <input type="hidden" name="action" value="joingroup">
                                <input type="hidden" name="cohortid" value="<?php echo $group->id; ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="tab" value="groups">
                                <button type="submit" 
                                        style="width: 100%; padding: 12px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);"
                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(59, 130, 246, 0.4)'"
                                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.3)'">
                                    <i class="fas fa-user-plus"></i> Join Group
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="mini-empty-state">No parent groups available at this time.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Networking Tab -->
    <div class="community-tab-content" id="tab-networking">
        <div class="community-insights">
            <div class="community-section" style="grid-column: 1 / -1;">
                <div class="community-section__header">
                    <div>
                        <small>Parent Networking</small>
                        <h3><i class="fas fa-network-wired"></i>Connect with Parents in Same Courses</h3>
                    </div>
                    <div style="font-size: 12px; color: #64748b; font-weight: 600;">
                        Find parents whose children share courses with yours
                    </div>
                </div>
                
                <?php if (!empty($parents_by_course)): ?>
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <?php foreach ($parents_by_course as $course_data): ?>
                    <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 16px; padding: 24px; border: 1px solid rgba(148, 163, 184, 0.15); box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid rgba(148, 163, 184, 0.1);">
                            <div>
                                <h4 style="margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-book" style="color: #3b82f6;"></i>
                                    <?php echo s($course_data['course']['name']); ?>
                                </h4>
                                <p style="margin: 8px 0 0; font-size: 13px; color: #64748b;">
                                    <?php echo count($course_data['parents']); ?> parent<?php echo count($course_data['parents']) != 1 ? 's' : ''; ?> in this course
                                </p>
                            </div>
                        </div>
                        
                        <div class="community-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                            <?php foreach ($course_data['parents'] as $parent_conn): ?>
                            <div class="community-card" style="padding: 20px;">
                                <div class="community-card__header" style="gap: 12px;">
                                    <div class="community-avatar" style="width: 48px; height: 48px; font-size: 18px;">
                                        <?php 
                                        if (!empty($parent_conn['picture'])) {
                                            echo '<img src="' . $OUTPUT->user_picture_url((object)['id' => $parent_conn['id'], 'picture' => $parent_conn['picture']], ['size' => 48]) . '" style="width: 100%; height: 100%; border-radius: 16px; object-fit: cover;" />';
                                        } else {
                                            $initials = explode(' ', $parent_conn['name']);
                                            $init = (isset($initials[0][0]) ? $initials[0][0] : '') . (isset($initials[1][0]) ? $initials[1][0] : '');
                                            echo strtoupper($init);
                                        }
                                        ?>
                                    </div>
                                    <div class="community-card__title">
                                        <h3 style="font-size: 16px;"><?php echo s($parent_conn['name']); ?></h3>
                                        <?php if (!empty($parent_conn['email'])): ?>
                                        <span style="font-size: 11px;"><i class="fas fa-envelope"></i> <?php echo s($parent_conn['email']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($parent_conn['children'])): ?>
                                <div style="margin-top: 12px; padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid rgba(148, 163, 184, 0.1);">
                                    <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Their Children</div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($parent_conn['children'] as $child): ?>
                                        <span class="child-chip" style="font-size: 11px; padding: 6px 10px;">
                                            <i class="fas fa-child"></i> <?php echo s($child['name']); ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div style="display: flex; gap: 8px; margin-top: 16px;">
                                    <?php if ($messaging_available): ?>
                                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?convid=<?php echo $parent_conn['id']; ?>" 
                                       style="flex: 1; padding: 10px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 12px; text-align: center; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 6px;"
                                       onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.4)'"
                                       onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                        <i class="fas fa-comment"></i> Message
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($parent_conn['email'])): ?>
                                    <a href="mailto:<?php echo s($parent_conn['email']); ?>" 
                                       style="padding: 10px 16px; background: white; color: #3b82f6; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 12px; border: 2px solid #3b82f6; transition: all 0.3s; display: flex; align-items: center; justify-content: center;"
                                       onmouseover="this.style.background='#3b82f6'; this.style.color='white'"
                                       onmouseout="this.style.background='white'; this.style.color='#3b82f6'">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="mini-empty-state">No parent connections found. Parents will appear here when their children are enrolled in the same courses as yours.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div> <!-- End community-main-container -->
</div> <!-- End parent-community-page -->

<script>
(function() {
    'use strict';
    
    function removeContainerPadding() {
    // Aggressively remove all container margins and padding
        var containers = document.querySelectorAll('.container, #page, #page-content, .region-main-content, [class*="container"], [id*="page"], [class*="region"]');
        for (var i = 0; i < containers.length; i++) {
            containers[i].style.margin = '0';
            containers[i].style.marginLeft = '0';
            containers[i].style.marginRight = '0';
            containers[i].style.marginTop = '0';
            containers[i].style.marginBottom = '0';
            containers[i].style.paddingLeft = '0';
            containers[i].style.paddingRight = '0';
            containers[i].style.maxWidth = '100%';
            containers[i].style.width = '100%';
        }

    // Remove spacing from body and html if needed
        if (document.body) {
    document.body.style.margin = '0';
    document.body.style.padding = '0';
        }
        if (document.documentElement) {
    document.documentElement.style.margin = '0';
    document.documentElement.style.padding = '0';
        }
    }
    
    // Tab switching system
    var tabButtons, tabPanels;
    var currentTab = 'overview';
    
    function showTab(tabName) {
        if (!tabName) {
            tabName = 'overview';
        }
        
        console.log('Showing tab:', tabName);
        
        // Hide all panels
        if (tabPanels) {
            for (var i = 0; i < tabPanels.length; i++) {
                tabPanels[i].style.display = 'none';
                tabPanels[i].classList.remove('active');
            }
        }
        
        // Remove active from all buttons
        if (tabButtons) {
            for (var i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
        }
        
        // Show the selected panel
        var targetPanel = document.getElementById('tab-' + tabName);
        var targetButton = document.querySelector('.community-tab[data-tab="' + tabName + '"]');
        
        if (targetPanel) {
            targetPanel.style.display = 'block';
            targetPanel.classList.add('active');
            console.log('Tab shown:', tabName);
        } else {
            console.error('Panel not found: tab-' + tabName);
            // Fallback
            var overviewPanel = document.getElementById('tab-overview');
            var overviewBtn = document.querySelector('.community-tab[data-tab="overview"]');
            if (overviewPanel) {
                overviewPanel.style.display = 'block';
                overviewPanel.classList.add('active');
            }
            if (overviewBtn) {
                overviewBtn.classList.add('active');
            }
            return;
        }
        
        // Activate button
        if (targetButton) {
            targetButton.classList.add('active');
        }
        
        currentTab = tabName;
        window.scrollTo(0, 0);
    }
    
    function initTabs() {
        console.log('Initializing tabs...');
        
        // Get tab elements
        tabButtons = document.querySelectorAll('.community-tab');
        tabPanels = document.querySelectorAll('.community-tab-content');
        
        console.log('Found buttons:', tabButtons ? tabButtons.length : 0);
        console.log('Found panels:', tabPanels ? tabPanels.length : 0);
        
        if (!tabButtons || !tabButtons.length) {
            console.error('No tab buttons found!');
            return false;
        }
        
        if (!tabPanels || !tabPanels.length) {
            console.error('No tab panels found!');
            return false;
        }
        
        // Get initial tab from URL
        var urlParams = new URLSearchParams(window.location.search);
        currentTab = urlParams.get('tab') || 'overview';

        // Add click handlers
        for (var i = 0; i < tabButtons.length; i++) {
            (function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var tabName = this.getAttribute('data-tab');
                    console.log('Tab clicked:', tabName);
                    
                    if (tabName) {
                        showTab(tabName);
                        
                        // Update URL
                        try {
                            var url = new URL(window.location);
                            url.searchParams.set('tab', tabName);
                            window.history.pushState({tab: tabName}, '', url);
                        } catch(err) {
                            console.warn('URL update failed:', err);
                        }
                    }
                });
            })(tabButtons[i]);
        }
        
        // Handle browser navigation
        window.addEventListener('popstate', function(e) {
            var urlParams = new URLSearchParams(window.location.search);
            var tab = urlParams.get('tab') || 'overview';
            showTab(tab);
        });
        
        // Show initial tab
        setTimeout(function() {
            showTab(currentTab);
        }, 100);
        
        return true;
    }
    
    function init() {
        removeContainerPadding();
        
        // Try to initialize tabs
        if (!initTabs()) {
            // Retry after a delay if failed
            setTimeout(function() {
                console.log('Retrying tab initialization...');
                if (initTabs()) {
                    showTab(currentTab);
                }
            }, 500);
        }

        // Search functionality
        var searchInput = document.querySelector('input[name="search"]');
        var cards = document.querySelectorAll('.community-card');

    if (searchInput && cards.length) {
        searchInput.addEventListener('input', function() {
                var needle = this.value.toLowerCase();
                for (var i = 0; i < cards.length; i++) {
                    var name = cards[i].getAttribute('data-parent-name') || '';
                    cards[i].style.display = name.indexOf(needle) !== -1 ? '' : 'none';
                }
            });
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 50);
    }
})();
</script>

<!-- Standalone Tab System - Must Work -->
<script>
(function() {
    'use strict';
    
    function switchTab(tabName) {
        console.log('SWITCHING TO TAB:', tabName);
        
        // Hide all tab contents
        var contents = document.querySelectorAll('.community-tab-content');
        for (var i = 0; i < contents.length; i++) {
            contents[i].style.display = 'none';
            contents[i].classList.remove('active');
        }
        
        // Remove active from all buttons
        var buttons = document.querySelectorAll('.community-tab');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('active');
        }
        
        // Show target tab
        var target = document.getElementById('tab-' + tabName);
        var btn = document.querySelector('.community-tab[data-tab="' + tabName + '"]');
        
        if (target) {
            target.style.display = 'block';
            target.classList.add('active');
            console.log('Tab shown:', tabName);
        }
        
        if (btn) {
            btn.classList.add('active');
        }
    }
    
    function attachHandlers() {
        var buttons = document.querySelectorAll('.community-tab');
        
        if (!buttons || buttons.length === 0) {
            console.log('No tabs found, retrying...');
            setTimeout(attachHandlers, 300);
            return;
        }
        
        console.log('Attaching handlers to', buttons.length, 'tabs');
        
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var tab = this.getAttribute('data-tab');
                if (tab) {
                    switchTab(tab);
                }
            });
        }
        
        // Show initial tab
        var params = new URLSearchParams(window.location.search);
        var initial = params.get('tab') || 'overview';
        switchTab(initial);
    }
    
    // Try multiple times to ensure it works
    if (document.readyState === 'complete') {
        attachHandlers();
    } else {
        document.addEventListener('DOMContentLoaded', attachHandlers);
        setTimeout(attachHandlers, 1000);
    }
    
    // Global function for debugging
    window.switchCommunityTab = switchTab;
})();
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


