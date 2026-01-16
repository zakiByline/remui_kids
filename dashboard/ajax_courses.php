<?php
/**
 * AJAX endpoint for fetching elementary courses dynamically
 * Usage: GET /ajax_courses.php?userid=123&limit=3
 * 
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

// Set JSON header
header('Content-Type: application/json');

// Get parameters
$userid = optional_param('userid', 0, PARAM_INT);
$limit = optional_param('limit', 3, PARAM_INT);
$offset = optional_param('offset', 0, PARAM_INT);

// Require login
require_login();

// If no userid provided, use current user
if (!$userid) {
    global $USER;
    $userid = $USER->id;
}

// Verify user can access this data (only their own or admin)
global $USER;
if ($userid != $USER->id && !is_siteadmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Access denied',
        'timestamp' => time()
    ]);
    exit;
}

// Include theme functions
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

try {
    // Get all courses for the user
    $allcourses = theme_remui_kids_get_elementary_courses($userid);
    
    // Apply pagination
    $total = count($allcourses);
    $courses = array_slice($allcourses, $offset, $limit);
    
    // Prepare response
    $response = [
        'success' => true,
        'userid' => $userid,
        'timestamp' => time(),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total,
        'courses' => $courses
    ];
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Return error response
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'userid' => $userid,
        'timestamp' => time()
    ];
    
    http_response_code(500);
    echo json_encode($response);
}
?>






