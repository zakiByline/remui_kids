<?php
/**
 * Simple AJAX Test Page
 */

require_once('../../../config.php');

// Set JSON header
header('Content-Type: application/json');

// Check if user has admin capabilities
require_capability('moodle/grade:viewall', context_system::instance());

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$response = array(
    'success' => true,
    'message' => 'AJAX endpoint is working!',
    'received_data' => $input,
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => array(
        'php_version' => phpversion(),
        'moodle_version' => $CFG->version,
        'database_type' => $CFG->dbtype
    )
);

echo json_encode($response);
?>










































