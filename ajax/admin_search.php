<?php
/**
 * Admin Global Search AJAX Handler
 * Searches across users, courses, schools, cohorts
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();

global $USER, $DB, $CFG;

$context = context_system::instance();

// Check if user is admin
if (!has_capability('moodle/site:config', $context)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$query = optional_param('query', '', PARAM_TEXT);
$limit = optional_param('limit', 20, PARAM_INT);

header('Content-Type: application/json');

if (empty($query) || strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query too short']);
    exit;
}

$results = [
    'users' => [],
    'courses' => [],
    'sections' => [],
    'activities' => [],
    'schools' => [],
    'cohorts' => [],
];

try {
    $search_param = '%' . $DB->sql_like_escape($query) . '%';
    
    // Search Users (students, teachers, managers)
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
            FROM {user} u
            WHERE u.deleted = 0
            AND u.suspended = 0
            AND u.id > 2
            AND (
                " . $DB->sql_like('u.firstname', ':fsearch', false) . "
                OR " . $DB->sql_like('u.lastname', ':lsearch', false) . "
                OR " . $DB->sql_like('u.email', ':esearch', false) . "
                OR " . $DB->sql_like('u.username', ':usearch', false) . "
                OR " . $DB->sql_like("CONCAT(u.firstname, ' ', u.lastname)", ':flsearch', false) . "
            )
            ORDER BY u.lastname ASC, u.firstname ASC";
    
    $params = [
        'fsearch' => $search_param,
        'lsearch' => $search_param,
        'esearch' => $search_param,
        'usearch' => $search_param,
        'flsearch' => $search_param,
    ];
    
    $users = $DB->get_records_sql($sql, $params, 0, $limit);
    
    foreach ($users as $user) {
        $role_label = 'User';
        
        // Get user's primary role from system context
        $system_context = context_system::instance();
        $role_assignments = $DB->get_records_sql(
            "SELECT DISTINCT r.shortname
             FROM {role_assignments} ra
             JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid
             AND ra.contextid = :contextid
             LIMIT 1",
            ['userid' => $user->id, 'contextid' => $system_context->id]
        );
        
        if (!empty($role_assignments)) {
            $role = reset($role_assignments);
            $role_shortname = $role->shortname;
            
            if ($role_shortname === 'student') {
                $role_label = 'Student';
            } elseif ($role_shortname === 'teacher' || $role_shortname === 'editingteacher') {
                $role_label = 'Teacher';
            } elseif ($role_shortname === 'companymanager') {
                $role_label = 'School Manager';
            } elseif ($role_shortname === 'manager') {
                $role_label = 'Manager';
            }
        }
        
        $results['users'][] = [
            'id' => $user->id,
            'name' => fullname($user),
            'email' => $user->email,
            'type' => $role_label,
            'url' => (new moodle_url('/user/view.php', ['id' => $user->id]))->out(false),
            'icon' => 'fa-user',
        ];
    }
    
    // Search Courses
    $sql = "SELECT id, fullname, shortname, summary
            FROM {course}
            WHERE id > 1
            AND (
                " . $DB->sql_like('fullname', ':csearch1', false) . "
                OR " . $DB->sql_like('shortname', ':csearch2', false) . "
            )
            ORDER BY fullname ASC";
    
    $course_params = [
        'csearch1' => $search_param,
        'csearch2' => $search_param,
    ];
    
    $courses = $DB->get_records_sql($sql, $course_params, 0, $limit);
    
    foreach ($courses as $course) {
        $results['courses'][] = [
            'id' => $course->id,
            'name' => format_string($course->fullname),
            'type' => 'Course',
            'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'icon' => 'fa-book',
        ];
    }
    
    // Search Course Sections (All courses)
    $sql = "SELECT cs.id, cs.course, cs.name, cs.section, cs.summary, c.fullname as coursename
            FROM {course_sections} cs
            JOIN {course} c ON c.id = cs.course
            WHERE c.id > 1
            AND cs.name IS NOT NULL
            AND cs.name != ''
            AND (
                " . $DB->sql_like('cs.name', ':sectionsearch', false) . "
                OR " . $DB->sql_like('cs.summary', ':sectionsummary', false) . "
            )
            ORDER BY c.fullname ASC, cs.section ASC";
    
    $section_params = [
        'sectionsearch' => $search_param,
        'sectionsummary' => $search_param,
    ];
    
    $sections = $DB->get_records_sql($sql, $section_params, 0, $limit);
    
    foreach ($sections as $section) {
        $results['sections'][] = [
            'id' => $section->id,
            'name' => format_string($section->name),
            'type' => 'Section',
            'coursename' => format_string($section->coursename),
            'url' => (new moodle_url('/course/view.php', ['id' => $section->course, 'section' => $section->section]))->out(false),
            'icon' => 'fa-list-ul',
        ];
    }
    
    // Search ALL Course Modules (Activities across all courses)
    $module_joins = '';
    $module_conditions = [];
    $mod_search_params = [];
    
    $module_types = [
        'assign', 'quiz', 'lesson', 'resource', 'forum', 'page', 'book', 'url', 
        'folder', 'choice', 'feedback', 'workshop', 'wiki', 'glossary', 'chat', 
        'scorm', 'h5pactivity', 'codeeditor', 'scratch', 'mix', 'photopea', 
        'sql', 'webdev', 'wick', 'wokwi'
    ];
    
    // Filter to only include tables that exist
    $existing_module_types = [];
    foreach ($module_types as $modtype) {
        if ($DB->get_manager()->table_exists($modtype)) {
            $existing_module_types[] = $modtype;
        }
    }
    
    // If no module tables exist, skip activity search
    if (empty($existing_module_types)) {
        $activities = [];
    } else {
        $coalesce_parts = [];
        foreach ($existing_module_types as $modtype) {
            $alias = substr($modtype, 0, 3) . '_tbl';
            $module_joins .= " LEFT JOIN {{$modtype}} {$alias} ON {$alias}.id = cm.instance AND m.name = '{$modtype}'\n";
            $coalesce_parts[] = "{$alias}.name";
            $param_key = $modtype . '_asearch';
            $module_conditions[] = $DB->sql_like("{$alias}.name", ":{$param_key}", false);
            $mod_search_params[$param_key] = $search_param;
        }
        
        $coalesce_sql = 'COALESCE(' . implode(', ', $coalesce_parts) . ", '')";
        $conditions_sql = implode(' OR ', $module_conditions);
        
        $sql = "SELECT cm.id, cm.course, cm.section, m.name as modname, c.fullname as coursename,
                       {$coalesce_sql} as activityname
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                JOIN {course} c ON c.id = cm.course
                {$module_joins}
                WHERE c.id > 1
                AND cm.deletioninprogress = 0
                AND ({$conditions_sql})
                ORDER BY activityname ASC";
        
        $activities = $DB->get_records_sql($sql, $mod_search_params, 0, $limit);
    }
    
    if (!empty($activities) && is_array($activities)) {
        foreach ($activities as $activity) {
        if (empty($activity->activityname)) {
            continue;
        }
        
        $icon = 'fa-file';
        $type = ucfirst($activity->modname);
        
        $module_map = [
            'assign' => ['type' => 'Assignment', 'icon' => 'fa-clipboard-list'],
            'quiz' => ['type' => 'Quiz', 'icon' => 'fa-question-circle'],
            'lesson' => ['type' => 'Lesson', 'icon' => 'fa-book-open'],
            'resource' => ['type' => 'Resource', 'icon' => 'fa-file-alt'],
            'forum' => ['type' => 'Forum', 'icon' => 'fa-comments'],
            'page' => ['type' => 'Page', 'icon' => 'fa-file'],
            'book' => ['type' => 'Book', 'icon' => 'fa-book'],
            'url' => ['type' => 'Link', 'icon' => 'fa-link'],
            'folder' => ['type' => 'Folder', 'icon' => 'fa-folder'],
            'choice' => ['type' => 'Choice', 'icon' => 'fa-check-square'],
            'feedback' => ['type' => 'Feedback', 'icon' => 'fa-comment-dots'],
            'workshop' => ['type' => 'Workshop', 'icon' => 'fa-users'],
            'wiki' => ['type' => 'Wiki', 'icon' => 'fa-book'],
            'glossary' => ['type' => 'Glossary', 'icon' => 'fa-list'],
            'chat' => ['type' => 'Chat', 'icon' => 'fa-comment'],
            'scorm' => ['type' => 'SCORM', 'icon' => 'fa-play-circle'],
            'h5pactivity' => ['type' => 'H5P', 'icon' => 'fa-cube'],
            'codeeditor' => ['type' => 'Code Editor', 'icon' => 'fa-code'],
            'scratch' => ['type' => 'Scratch', 'icon' => 'fa-puzzle-piece'],
            'mix' => ['type' => 'Remix IDE', 'icon' => 'fa-code-branch'],
            'photopea' => ['type' => 'Photopea', 'icon' => 'fa-image'],
            'sql' => ['type' => 'SQL Lab', 'icon' => 'fa-database'],
            'webdev' => ['type' => 'WebDev Studio', 'icon' => 'fa-html5'],
            'wick' => ['type' => 'Wick Editor', 'icon' => 'fa-pencil-ruler'],
            'wokwi' => ['type' => 'Wokwi', 'icon' => 'fa-microchip'],
        ];
        
        if (isset($module_map[$activity->modname])) {
            $type = $module_map[$activity->modname]['type'];
            $icon = $module_map[$activity->modname]['icon'];
        }
        
        $results['activities'][] = [
            'id' => $activity->id,
            'name' => format_string($activity->activityname),
            'type' => $type,
            'coursename' => format_string($activity->coursename),
            'url' => (new moodle_url('/mod/' . $activity->modname . '/view.php', ['id' => $activity->id]))->out(false),
            'icon' => $icon,
        ];
        }
    }
    
    // Search Schools/Companies (if IOMAD is installed)
    if ($DB->get_manager()->table_exists('company')) {
        $sql = "SELECT id, name, shortname
                FROM {company}
                WHERE " . $DB->sql_like('name', ':companysearch', false) . "
                OR " . $DB->sql_like('shortname', ':companyshort', false) . "
                ORDER BY name ASC";
        
        $company_params = [
            'companysearch' => $search_param,
            'companyshort' => $search_param,
        ];
        
        $companies = $DB->get_records_sql($sql, $company_params, 0, $limit);
        
        foreach ($companies as $company) {
            $results['schools'][] = [
                'id' => $company->id,
                'name' => format_string($company->name),
                'type' => 'School',
                'url' => (new moodle_url('/local/iomad_dashboard/index.php', ['companyid' => $company->id]))->out(false),
                'icon' => 'fa-school',
            ];
        }
    }
    
    // Search Cohorts
    $sql = "SELECT id, name, idnumber, description
            FROM {cohort}
            WHERE " . $DB->sql_like('name', ':cohortsearch', false) . "
            OR " . $DB->sql_like('idnumber', ':cohortid', false) . "
            ORDER BY name ASC";
    
    $cohort_params = [
        'cohortsearch' => $search_param,
        'cohortid' => $search_param,
    ];
    
    $cohorts = $DB->get_records_sql($sql, $cohort_params, 0, $limit);
    
    foreach ($cohorts as $cohort) {
        // Count members
        $member_count = $DB->count_records('cohort_members', ['cohortid' => $cohort->id]);
        
        $results['cohorts'][] = [
            'id' => $cohort->id,
            'name' => format_string($cohort->name),
            'members' => $member_count,
            'type' => 'Cohort',
            'url' => (new moodle_url('/cohort/assign.php', ['id' => $cohort->id]))->out(false),
            'icon' => 'fa-users',
        ];
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    
} catch (Exception $e) {
    
    // Return actual error to user (temporarily for debugging)
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

