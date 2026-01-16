<?php
/**
 * Student Global Search AJAX Handler
 * Searches across courses, activities, lessons, and resources
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

global $USER, $DB, $CFG;

$query = optional_param('query', '', PARAM_TEXT);
$limit = optional_param('limit', 10, PARAM_INT);

header('Content-Type: application/json');

if (empty($query) || strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query too short']);
    exit;
}

$results = [
    'courses' => [],
    'sections' => [],
    'activities' => [],
];

try {
    // Get user's enrolled courses
    $enrolled_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname, summary');
    $course_ids = array_keys($enrolled_courses);
    
    if (empty($course_ids)) {
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    list($insql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
    $search_param = '%' . $DB->sql_like_escape($query) . '%';
    
    // Search Courses
    $sql = "SELECT id, fullname, shortname, summary
            FROM {course}
            WHERE id $insql
            AND (
                " . $DB->sql_like('fullname', ':search1', false) . "
                OR " . $DB->sql_like('shortname', ':search2', false) . "
                OR " . $DB->sql_like('summary', ':search3', false) . "
            )
            ORDER BY fullname ASC";
    
    $params['search1'] = $search_param;
    $params['search2'] = $search_param;
    $params['search3'] = $search_param;
    
    $courses = $DB->get_records_sql($sql, $params, 0, $limit);
    
    foreach ($courses as $course) {
        $results['courses'][] = [
            'id' => $course->id,
            'name' => format_string($course->fullname),
            'type' => 'Course',
            'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'icon' => 'fa-book',
            'category' => 'course',
        ];
    }
    
    // Search Course Sections (Lessons/Modules/Subsections)
    $sql = "SELECT cs.id, cs.course, cs.name, cs.section, cs.summary, c.fullname as coursename
            FROM {course_sections} cs
            JOIN {course} c ON c.id = cs.course
            WHERE cs.course $insql
            AND cs.name IS NOT NULL
            AND cs.name != ''
            AND (
                " . $DB->sql_like('cs.name', ':sectionsearch', false) . "
                OR " . $DB->sql_like('cs.summary', ':sectionsummary', false) . "
            )
            ORDER BY c.fullname ASC, cs.section ASC";
    
    $params['sectionsearch'] = $search_param;
    $params['sectionsummary'] = $search_param;
    
    $sections = $DB->get_records_sql($sql, $params, 0, $limit);
    
    foreach ($sections as $section) {
        $results['sections'][] = [
            'id' => $section->id,
            'name' => format_string($section->name),
            'type' => 'Section',
            'coursename' => format_string($section->coursename),
            'url' => (new moodle_url('/course/view.php', ['id' => $section->course, 'section' => $section->section]))->out(false),
            'icon' => 'fa-list-ul',
            'category' => 'section',
        ];
    }
    
    // Search ALL Course Modules (Activities, Lessons, Resources, etc.)
    // Build dynamic SQL for all module types
    $module_joins = '';
    $module_conditions = [];
    $mod_search_params = [];
    
    $module_types = [
        'assign', 'quiz', 'lesson', 'resource', 'forum', 'page', 'book', 'url', 
        'folder', 'choice', 'feedback', 'workshop', 'wiki', 'glossary', 'chat', 
        'scorm', 'h5pactivity', 'codeeditor', 'scratch', 'mix', 'photopea', 
        'sql', 'webdev', 'wick', 'wokwi'
    ];
    
    $coalesce_parts = [];
    foreach ($module_types as $modtype) {
        $alias = substr($modtype, 0, 3) . '_tbl';
        $module_joins .= " LEFT JOIN {{$modtype}} {$alias} ON {$alias}.id = cm.instance AND m.name = '{$modtype}'\n";
        $coalesce_parts[] = "{$alias}.name";
        $param_key = $modtype . '_search';
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
            WHERE cm.course $insql
            AND cm.deletioninprogress = 0
            AND ({$conditions_sql})
            ORDER BY activityname ASC";
    
    $params = array_merge($params, $mod_search_params);
    
    $activities = $DB->get_records_sql($sql, $params, 0, $limit * 2);
    
    foreach ($activities as $activity) {
        if (empty($activity->activityname)) {
            continue;
        }
        
        $icon = 'fa-file';
        $type = ucfirst($activity->modname);
        
        // Map module names to user-friendly types and icons
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
            'category' => 'activity',
        ];
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

