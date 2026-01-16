<?php
/**
 * AJAX endpoint for loading competency analytics
 *
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_login();

// Get course ID parameter
$courseid = optional_param('courseid', 0, PARAM_INT);

// Set header for JSON response
header('Content-Type: application/json');

// Get competency analytics
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

try {
    $analytics = theme_remui_kids_get_teacher_competency_analytics($courseid);
    
    if ($analytics && isset($analytics['has_data'])) {
        echo json_encode([
            'success' => true,
            'analytics' => $analytics
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No competency data available'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading competency analytics: ' . $e->getMessage()
    ]);
}