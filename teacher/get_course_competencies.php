<?php
/**
 * Get Course Competencies API
 * 
 * Returns all competencies linked to a specific course
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

try {
    // Security checks
    require_login();
    
    // Get course ID
    $courseid = required_param('courseid', PARAM_INT);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);
    
    // Fetch competencies linked to this course with parent-child relationships
    $sql = "
        SELECT 
            c.id,
            c.shortname,
            c.idnumber,
            c.description,
            c.descriptionformat,
            c.parentid,
            c.path,
            cc.id as coursecompid,
            cc.ruleoutcome,
            cc.sortorder,
            cf.id as frameworkid,
            cf.shortname as framework_shortname
        FROM {competency_coursecomp} cc
        JOIN {competency} c ON c.id = cc.competencyid
        LEFT JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
        WHERE cc.courseid = ?
        ORDER BY c.path ASC, cc.sortorder ASC
    ";
    
    $competencies = $DB->get_records_sql($sql, [$courseid]);
    
    // Build hierarchical structure
    $competency_map = [];
    $tree = [];
    
    // First pass: Create all competency objects
    foreach ($competencies as $comp) {
        $competency_map[$comp->id] = [
            'id' => $comp->id,
            'shortname' => $comp->shortname,
            'idnumber' => $comp->idnumber,
            'description' => $comp->description,
            'descriptionformat' => $comp->descriptionformat,
            'framework' => $comp->framework_shortname,
            'ruleoutcome' => $comp->ruleoutcome,
            'parentid' => $comp->parentid,
            'path' => $comp->path,
            'children' => []
        ];
    }
    
    // Second pass: Build tree structure
    foreach ($competency_map as $id => $comp) {
        if ($comp['parentid'] == 0 || !isset($competency_map[$comp['parentid']])) {
            // Root level competency
            $tree[] = &$competency_map[$id];
        } else {
            // Child competency - add to parent's children array
            $competency_map[$comp['parentid']]['children'][] = &$competency_map[$id];
        }
    }
    
    $result = $tree;
    
    echo json_encode([
        'success' => true,
        'competencies' => $result,
        'count' => count($result)
    ]);
    
} catch (Exception $e) {
    error_log("Get course competencies error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading competencies: ' . $e->getMessage(),
        'competencies' => []
    ]);
}
?>

