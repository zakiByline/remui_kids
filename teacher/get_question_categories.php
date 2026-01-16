<?php
/**
 * Get Question Categories for Course
 * Returns question categories available in the course context
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

// Security checks
require_login();
require_sesskey();

try {
    $courseid = required_param('courseid', PARAM_INT);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/question:viewall', $coursecontext);
    
    // Get question categories for this course context
    $categories = $DB->get_records_sql("
        SELECT id, name, info, parent
        FROM {question_categories}
        WHERE contextid = ?
        ORDER BY parent, sortorder, name
    ", [$coursecontext->id]);
    
    $result_categories = [];
    foreach ($categories as $category) {
        $result_categories[] = [
            'id' => $category->id,
            'name' => format_string($category->name),
            'info' => $category->info,
            'parent' => $category->parent
        ];
    }
    
    echo json_encode([
        'success' => true,
        'categories' => $result_categories
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}