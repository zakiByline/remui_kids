<?php
/**
 * AJAX endpoint for getting real-time dashboard statistics
 * 
 * @package theme_remui_kids
 * @copyright (c) 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Set content type to JSON first
header('Content-Type: application/json');

try {
    require_once('../../../config.php');
    
    // Check if user is logged in and has admin privileges
    require_login();
    $context = context_system::instance();
    if (!has_capability('moodle/site:config', $context)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied', 'status' => 'error']);
        exit;
    }
    
    // Include the theme library
    require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
    
    // Get real-time statistics
    $stats = theme_remui_kids_get_admin_dashboard_stats();
    
    // Add additional real-time data
    $stats['timestamp'] = date('Y-m-d H:i:s');
    $stats['status'] = 'success';
    
    echo json_encode($stats);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch statistics',
        'message' => $e->getMessage(),
        'status' => 'error'
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'PHP Error',
        'message' => $e->getMessage(),
        'status' => 'error'
    ]);
}
?>
