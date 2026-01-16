<?php
/**
 * AJAX endpoint for admin dashboard data filtering
 */

// This MUST be first - before any output
define('AJAX_SCRIPT', true);

// Capture everything
ob_start();

// Load Moodle
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

// Discard any output from config.php
ob_end_clean();

// Set header
@header('Content-Type: application/json; charset=utf-8');

// Require user to be logged in
require_login();

// Get parameters FIRST before any processing
$action = optional_param('action', '', PARAM_ALPHANUMEXT); // Changed to allow underscores
$companyid = optional_param('companyid', 0, PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_RAW);

// Convert empty to null
if (empty($companyid)) {
    $companyid = null;
}

// Check admin capability
$context = context_system::instance();
if (!is_siteadmin() && !has_capability('moodle/site:config', $context)) {
    die(json_encode(['success' => false, 'error' => 'No permission']));
}

// Verify sesskey
if (!confirm_sesskey($sesskey)) {
    die(json_encode(['success' => false, 'error' => 'Invalid sesskey']));
}

// Start output buffer for function calls
ob_start();

try {
    switch ($action) {
        case 'get_student_activities':
            $stats = theme_remui_kids_get_admin_student_activity_stats($companyid);
            $output = ob_get_clean();
            die(json_encode(['success' => true, 'data' => $stats]));
            
        case 'get_student_enrollments':
            $enrollments = theme_remui_kids_get_recent_student_enrollments($companyid);
            $output = ob_get_clean();
            die(json_encode(['success' => true, 'data' => $enrollments]));
            
        case 'get_all_dashboard_data':
            $data = [
                'student_activities' => theme_remui_kids_get_admin_student_activity_stats($companyid),
                'student_enrollments' => theme_remui_kids_get_recent_student_enrollments($companyid),
                'student_activities_detail' => theme_remui_kids_get_school_admins_activities($companyid)
            ];
            $output = ob_get_clean();
            die(json_encode(['success' => true, 'data' => $data]));
            
        default:
            ob_end_clean();
            die(json_encode(['success' => false, 'error' => 'Invalid action']));
    }
} catch (Exception $e) {
    ob_end_clean();
    die(json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]));
}
