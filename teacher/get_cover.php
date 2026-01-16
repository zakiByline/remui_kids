<?php
/**
 * Cover Image Server
 * Serves cover images from the covers/ directory
 */

require_once(__DIR__ . '/../../../config.php');

$filename = required_param('file', PARAM_FILE);

// Validate filename (prevent directory traversal)
if (preg_match('/[^a-zA-Z0-9_\-\.]/', $filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    die('Invalid filename');
}

$covers_dir = $CFG->dataroot . '/theme_remui_kids/covers';
$cover_path = $covers_dir . '/' . $filename;

// Check if file exists and is within the covers directory
if (!file_exists($cover_path) || !is_readable($cover_path)) {
    http_response_code(404);
    die('Cover image not found');
}

// Verify the file is actually in the covers directory (security check)
$real_path = realpath($cover_path);
$real_dir = realpath($covers_dir);
if (strpos($real_path, $real_dir) !== 0) {
    http_response_code(403);
    die('Access denied');
}

// Serve the image
header('Content-Type: image/png');
header('Content-Length: ' . filesize($cover_path));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

readfile($cover_path);


