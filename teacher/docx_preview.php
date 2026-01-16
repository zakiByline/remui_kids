<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * DOCX Preview Endpoint
 * Extracts the first embedded image from DOCX files for preview
 * DOCX files are ZIP archives containing images in word/media/ folder
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/classes/local/secure_file_token.php');

use theme_remui_kids\local\secure_file_token;

// Set headers to prevent caching issues during development
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$fileid = optional_param('fileid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$expires = optional_param('expires', 0, PARAM_INT);
$token = optional_param('token', '', PARAM_ALPHANUMEXT);

$filename = 'unknown'; // Default filename for logging

try {
    if (empty($fileid) || empty($userid) || empty($expires) || empty($token)) {
        throw new Exception("Missing required parameters for secure file token validation.");
    }

    if (!secure_file_token::validate($fileid, $userid, $expires, $token)) {
        throw new Exception("Invalid or expired secure file token.");
    }

    $fs = get_file_storage();
    $file = $fs->get_file_by_id($fileid);

    if (!$file || $file->is_directory()) {
        throw new Exception("File not found or is a directory.");
    }

    $filename = $file->get_filename();
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Log the URL being accessed for debugging
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    error_log("DOCX Preview: Attempting to extract first image for fileid={$fileid}, filename={$filename}, URL={$current_url}");

    // Only process DOCX files
    if ($file_extension !== 'docx') {
        error_log("DOCX Preview: Unsupported file type for image extraction - fileid={$fileid}, filename={$filename}, extension={$file_extension}");
        throw new Exception("Unsupported file type for image extraction.");
    }

    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        error_log("DOCX Preview: ZipArchive class not available - fileid={$fileid}, filename={$filename}");
        throw new Exception("ZipArchive class not available. Cannot extract images from DOCX file.");
    }

    // Get the actual file path on disk
    $filepath = $file->get_content_file();
    if (!$filepath || !file_exists($filepath)) {
        error_log("DOCX Preview: Stored file content not found on disk - fileid={$fileid}, filename={$filename}, filepath={$filepath}");
        throw new Exception("Stored file content not found on disk.");
    }

    $zip = new ZipArchive;
    if ($zip->open($filepath) !== TRUE) {
        error_log("DOCX Preview: Could not open DOCX file as zip archive - fileid={$fileid}, filename={$filename}, filepath={$filepath}");
        throw new Exception("Could not open DOCX file as zip archive.");
    }

    // DOCX files store images in word/media/ folder
    // Find the first image file in word/media/
    $image_data = null;
    $image_mimetype = null;
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    
    // Get all files in the ZIP
    $media_files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $file_name = $zip->getNameIndex($i);
        if ($file_name !== false && strpos($file_name, 'word/media/') === 0) {
            $media_files[] = $file_name;
        }
    }
    
    error_log("DOCX Preview: Found " . count($media_files) . " files in word/media/ for fileid={$fileid}, filename={$filename}");
    
    // Find the first valid image file
    foreach ($media_files as $media_file) {
        $file_ext = strtolower(pathinfo($media_file, PATHINFO_EXTENSION));
        if (in_array($file_ext, $image_extensions)) {
            $image_data = $zip->getFromName($media_file);
            if ($image_data !== false && strlen($image_data) > 0) {
                // Determine MIME type
                if ($file_ext === 'png') {
                    $image_mimetype = 'image/png';
                } else if (in_array($file_ext, ['jpg', 'jpeg'])) {
                    $image_mimetype = 'image/jpeg';
                } else if ($file_ext === 'gif') {
                    $image_mimetype = 'image/gif';
                } else if ($file_ext === 'bmp') {
                    $image_mimetype = 'image/bmp';
                } else if ($file_ext === 'webp') {
                    $image_mimetype = 'image/webp';
                } else {
                    $image_mimetype = 'image/jpeg'; // Default
                }
                
                // Validate image data
                $image_info = @getimagesizefromstring($image_data);
                if ($image_info !== false && $image_info[0] > 0 && $image_info[1] > 0) {
                    error_log("DOCX Preview: Found first image at {$media_file} - fileid={$fileid}, filename={$filename}, size=" . strlen($image_data) . ", dimensions={$image_info[0]}x{$image_info[1]}");
                    break;
                } else {
                    error_log("DOCX Preview: Invalid image data at {$media_file} - fileid={$fileid}, filename={$filename}");
                    $image_data = null;
                }
            }
        }
    }

    $zip->close();

    if ($image_data === null) {
        throw new Exception("No embedded images found in DOCX file.");
    }

    // Validate image data (basic check for image header)
    if ($image_mimetype === 'image/jpeg' && substr($image_data, 0, 3) !== "\xFF\xD8\xFF") {
        error_log("DOCX Preview: Invalid JPEG image header for fileid={$fileid}, filename={$filename}");
        throw new Exception("Invalid JPEG image header.");
    }
    if ($image_mimetype === 'image/png' && substr($image_data, 0, 8) !== "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
        error_log("DOCX Preview: Invalid PNG image header for fileid={$fileid}, filename={$filename}");
        throw new Exception("Invalid PNG image header.");
    }

    // Clear any previous output before sending image
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Send the image
    header('Content-Type: ' . $image_mimetype);
    header('Content-Length: ' . strlen($image_data));
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
    echo $image_data;
    exit;

} catch (Exception $e) {
    error_log("DOCX Preview: Exception for fileid={$fileid}, filename={$filename}, error=" . $e->getMessage());

    // Clear any previous output before sending fallback image
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Return 404 so browser treats it as failed load (prevents error display)
    http_response_code(404);
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo 'Preview not available';
    exit;
}



