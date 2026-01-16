<?php
// AJAX endpoint for refreshing "This Week's Learning Activity" data on the High School dashboard.

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_login();
require_sesskey();

require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

header('Content-Type: application/json');

try {
    $activitydata = theme_remui_kids_get_weekly_activity_data($USER->id);
    echo json_encode([
        'success' => true,
        'data' => $activitydata,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

die();

