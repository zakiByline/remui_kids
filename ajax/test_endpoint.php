<?php
/**
 * Test AJAX endpoint - Simple test to verify the endpoint is reachable
 */

define('AJAX_SCRIPT', true);
require_once('../../../../config.php');

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'AJAX endpoint is working!',
    'timestamp' => time(),
    'user_logged_in' => isloggedin(),
    'is_admin' => is_siteadmin(),
    'config_wwwroot' => $CFG->wwwroot,
    'script_path' => $_SERVER['SCRIPT_NAME']
]);

exit;

