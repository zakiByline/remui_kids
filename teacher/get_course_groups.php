<?php
/**
 * Get Course Groups
 * Returns groups for a specific course
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

// Security checks
require_login();

try {
    $courseid = required_param('courseid', PARAM_INT);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);
    
    // Get all groups for this course
    $groups = $DB->get_records_sql("
        SELECT g.id, g.name, g.description, COUNT(gm.id) as membercount
        FROM {groups} g
        LEFT JOIN {groups_members} gm ON gm.groupid = g.id
        WHERE g.courseid = ?
        GROUP BY g.id, g.name, g.description
        ORDER BY g.name ASC
    ", [$courseid]);
    
    $result_groups = [];
    foreach ($groups as $group) {
        $result_groups[] = [
            'id' => $group->id,
            'name' => format_string($group->name),
            'description' => format_text($group->description),
            'membercount' => $group->membercount
        ];
    }
    
    echo json_encode([
        'success' => true,
        'groups' => $result_groups
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
