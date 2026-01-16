<?php
/**
 * AJAX endpoint for loading best performing students
 *
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_login();

// Get parameters
$courseid = optional_param('courseid', 0, PARAM_INT);
$limit = optional_param('limit', 10, PARAM_INT);

// Set header for JSON response
header('Content-Type: application/json');

// Get best performing students
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

try {
    $data = theme_remui_kids_get_best_performing_students($courseid, $limit);
    
    if ($data && isset($data['has_data'])) {
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No student data available'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading student data: ' . $e->getMessage()
    ]);
}