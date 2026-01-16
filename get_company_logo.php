<?php
/**
 * Company Logo Display Script
 * Serves company logos for the school manager sidebar
 */

require_once('../../config.php');
require_login();

global $DB, $CFG;

// Get company ID from parameter
$company_id = required_param('id', PARAM_INT);

// Check if user has access to this company
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// Check if user is manager of this company
$user_company = $DB->get_record('company_users', [
    'companyid' => $company_id,
    'userid' => $USER->id,
    'managertype' => 1
]);

if (!$is_company_manager || !$user_company) {
    http_response_code(403);
    die('Access denied');
}

// Get company logo information
$company_logo = $DB->get_record('company_logo', ['companyid' => $company_id]);

if (!$company_logo) {
    // Return default logo or 404
    http_response_code(404);
    die('Logo not found');
}

// Construct file path
$logo_path = $CFG->dataroot . '/company/' . $company_id . '/' . $company_logo->filename;

// Check if file exists
if (!file_exists($logo_path)) {
    http_response_code(404);
    die('Logo file not found');
}

// Get file info
$file_info = pathinfo($logo_path);
$mime_type = 'image/jpeg'; // Default

// Set appropriate MIME type
switch (strtolower($file_info['extension'])) {
    case 'jpg':
    case 'jpeg':
        $mime_type = 'image/jpeg';
        break;
    case 'png':
        $mime_type = 'image/png';
        break;
    case 'gif':
        $mime_type = 'image/gif';
        break;
    case 'webp':
        $mime_type = 'image/webp';
        break;
}

// Set headers
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($logo_path));
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($logo_path)) . ' GMT');

// Output the file
readfile($logo_path);
exit;
?>

