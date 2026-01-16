<?php
/**
 * AJAX Endpoint: Get Student Certificates
 * 
 * Returns JSON data of all certificates for a student
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/certificate_completion.php');

// Require login
require_login();

global $USER, $DB;

// Set JSON header
header('Content-Type: application/json');

// Get optional parameters
$userid = optional_param('userid', null, PARAM_INT);
$format = optional_param('format', 'json', PARAM_ALPHA); // json or html

// If userid is provided, check permissions (only allow if viewing own certificates or has capability)
if ($userid !== null && $userid != $USER->id) {
    // Check if user has capability to view other users' certificates
    $context = context_system::instance();
    if (!has_capability('moodle/user:viewdetails', $context)) {
        echo json_encode(array(
            'success' => false,
            'error' => 'Access denied'
        ));
        exit;
    }
} else {
    $userid = $USER->id;
}

try {
    // Get certificates
    $certificates = theme_remui_kids_get_student_certificates($userid, true);
    $certificate_counts = theme_remui_kids_get_student_certificates_count($userid);
    
    // Format certificates for JSON response
    $formatted_certificates = array();
    foreach ($certificates as $cert) {
        $formatted_cert = array(
            'id' => $cert['id'],
            'type' => $cert['type'],
            'certificate_name' => $cert['certificate_name'],
            'course_id' => $cert['course_id'],
            'course_name' => $cert['course_name'],
            'course_shortname' => $cert['course_shortname'],
            'status' => $cert['status'],
            'issued_date' => $cert['issued_date'],
            'issued_date_formatted' => !empty($cert['issued_date']) ? userdate($cert['issued_date'], '%B %d, %Y') : null,
            'view_url' => !empty($cert['view_url']) ? $cert['view_url']->out(false) : null,
            'download_url' => !empty($cert['download_url']) ? $cert['download_url']->out(false) : null,
            'download_url_needs_sesskey' => !empty($cert['download_url_needs_sesskey']),
            'course_url' => !empty($cert['course_id']) ? (new moodle_url('/course/view.php', array('id' => $cert['course_id'])))->out(false) : null
        );
        
        // Add optional fields
        if (!empty($cert['certificate_code'])) {
            $formatted_cert['certificate_code'] = $cert['certificate_code'];
        }
        if (!empty($cert['filename'])) {
            $formatted_cert['filename'] = $cert['filename'];
        }
        if (!empty($cert['updated_date'])) {
            $formatted_cert['updated_date'] = $cert['updated_date'];
            $formatted_cert['updated_date_formatted'] = userdate($cert['updated_date'], '%B %d, %Y');
        }
        
        $formatted_certificates[] = $formatted_cert;
    }
    
    // Return JSON response
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'certificates' => $formatted_certificates,
            'counts' => $certificate_counts,
            'total' => count($formatted_certificates)
        )
    ), JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage()
    ));
}

