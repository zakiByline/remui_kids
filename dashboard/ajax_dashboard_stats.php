<?php
/**
 * AJAX endpoint for fetching dashboard statistics
 * Usage: GET /ajax_dashboard_stats.php?userid=123
 */

require_once('../../../config.php');

// Set JSON header
header('Content-Type: application/json');

// Get user ID from parameter or use current user
$userid = optional_param('userid', 0, PARAM_INT);
if (!$userid) {
    global $USER;
    $userid = $USER->id;
}

// Require login
require_login();

// Include theme functions
require_once('theme/remui_kids/lib.php');

try {
    // Get dashboard statistics
    $stats = theme_remui_kids_get_elementary_dashboard_stats($userid);
    
    // Add additional metadata
    $response = [
        'success' => true,
        'userid' => $userid,
        'timestamp' => time(),
        'data' => $stats
    ];
    
    // Add detailed course breakdown if requested
    if (optional_param('detailed', false, PARAM_BOOL)) {
        $courses = theme_remui_kids_get_elementary_courses($userid);
        $response['courses'] = $courses;
    }
    
    echo json_encode($response);
    
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
