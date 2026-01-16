<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * PPT Preview Endpoint
 * Extracts embedded thumbnail from PPTX files (similar to PDF.js for PDFs)
 * PPTX files are ZIP archives containing docProps/thumbnail.jpeg or thumbnail.wmf
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/classes/local/secure_file_token.php');

use theme_remui_kids\local\secure_file_token;

$fileid = required_param('fileid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$expires = required_param('expires', PARAM_INT);
$token = required_param('token', PARAM_ALPHANUMEXT);

// Validate token
if (!secure_file_token::validate($fileid, $userid, $expires, $token)) {
    error_log("PPT Preview: Invalid token for fileid={$fileid}, userid={$userid}");
    http_response_code(403);
    exit;
}

$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);

if (!$file || $file->is_directory()) {
    error_log("PPT Preview: File not found - fileid={$fileid}");
    // Return 1x1 transparent PNG instead of 404 to avoid console errors
    $transparent_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($transparent_png));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $transparent_png;
    exit;
}

// Check file extension
$filename = $file->get_filename();
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!in_array($extension, ['ppt', 'pptx', 'odp'])) {
    error_log("PPT Preview: Invalid file type - fileid={$fileid}, extension={$extension}");
    // Return 1x1 transparent PNG instead of 400 to avoid console errors
    $transparent_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($transparent_png));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $transparent_png;
    exit;
}

// Log the file URL we're trying to access
$file_url = $CFG->wwwroot . '/theme/remui_kids/teacher/ppt_preview.php?fileid=' . $fileid . '&userid=' . $userid . '&expires=' . $expires . '&token=' . $token;
error_log("PPT Preview: Attempting to extract thumbnail from fileid={$fileid}, filename={$filename}, url={$file_url}");

// Create temporary file for extraction
$temp_file = $CFG->tempdir . '/ppt_preview_' . $fileid . '_' . time() . '.' . $extension;

try {
    // Copy file to temp location
    $file->copy_content_to($temp_file);
    
    if (!file_exists($temp_file)) {
        throw new Exception("Failed to create temporary file");
    }
    
    // PPTX files are ZIP archives - extract thumbnail
    if ($extension === 'pptx' || $extension === 'odp') {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            error_log("PPT Preview: ZipArchive class not available - fileid={$fileid}, filename={$filename}");
            throw new Exception("ZipArchive class not available");
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($temp_file);
        
        if ($result !== TRUE) {
            error_log("PPT Preview: Failed to open ZIP archive - fileid={$fileid}, filename={$filename}, error_code={$result}");
            throw new Exception("Failed to open ZIP archive: " . $result);
        }
        
        // Look for embedded thumbnail in common locations
        // PowerPoint embeds thumbnails in various locations depending on version
        $thumbnail_paths = [
            'docProps/thumbnail.jpeg',
            'docProps/thumbnail.jpg',
            'docProps/thumbnail.png',
            'docProps/thumbnail.wmf',
            'Thumbnails/thumbnail.png',
            'Thumbnails/thumbnail.jpeg',
            'Thumbnails/thumbnail.jpg',
        ];
        
        // Also check all files in docProps folder for thumbnails
        $docprops_files = [];
        $all_files_in_zip = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename_in_zip = $zip->getNameIndex($i);
            if ($filename_in_zip !== false) {
                $all_files_in_zip[] = $filename_in_zip;
                // Check if it's in docProps or Thumbnails folder and looks like an image
                if ((strpos($filename_in_zip, 'docProps/') === 0 || strpos($filename_in_zip, 'Thumbnails/') === 0) &&
                    (stripos($filename_in_zip, 'thumbnail') !== false || 
                     stripos($filename_in_zip, 'thumb') !== false)) {
                    $docprops_files[] = $filename_in_zip;
                }
            }
        }
        
        // Log ZIP contents for debugging (first 20 files)
        $sample_files = array_slice($all_files_in_zip, 0, 20);
        error_log("PPT Preview: ZIP contents sample (first 20) - fileid={$fileid}, files=" . implode(', ', $sample_files));
        
        if (!empty($docprops_files)) {
            error_log("PPT Preview: Found potential thumbnail files - fileid={$fileid}, files=" . implode(', ', $docprops_files));
        }
        
        // Add found files to search paths
        $thumbnail_paths = array_merge($thumbnail_paths, $docprops_files);
        $thumbnail_paths = array_unique($thumbnail_paths);
        
        $thumbnail_found = false;
        $thumbnail_data = null;
        $thumbnail_mime = 'image/jpeg';
        
        foreach ($thumbnail_paths as $thumb_path) {
            $thumbnail_data = $zip->getFromName($thumb_path);
            if ($thumbnail_data !== false && strlen($thumbnail_data) > 0) {
                // Determine MIME type based on path and content
                if (strpos($thumb_path, '.png') !== false) {
                    $thumbnail_mime = 'image/png';
                } else if (strpos($thumb_path, '.wmf') !== false) {
                    // WMF needs conversion - skip for now
                    error_log("PPT Preview: WMF thumbnail found but not supported - fileid={$fileid}, filename={$filename}, path={$thumb_path}");
                    $thumbnail_data = false;
                    continue;
                } else {
                    // Default to JPEG, but validate
                    $thumbnail_mime = 'image/jpeg';
                }
                
                // Validate it's actually an image
                $image_info = @getimagesizefromstring($thumbnail_data);
                if ($image_info !== false && $image_info[0] > 0 && $image_info[1] > 0) {
                    $thumbnail_found = true;
                    error_log("PPT Preview: Thumbnail found at {$thumb_path} - fileid={$fileid}, filename={$filename}, size=" . strlen($thumbnail_data) . ", dimensions={$image_info[0]}x{$image_info[1]}");
                    break;
                } else {
                    error_log("PPT Preview: Invalid image data at {$thumb_path} - fileid={$fileid}, filename={$filename}");
                    $thumbnail_data = false;
                }
            }
        }
        
        $zip->close();
        
        if ($thumbnail_found && $thumbnail_data !== false) {
            // Validate image data
            $image_info = @getimagesizefromstring($thumbnail_data);
            if ($image_info === false) {
                error_log("PPT Preview: Invalid thumbnail image data - fileid={$fileid}, filename={$filename}");
                throw new Exception("Invalid thumbnail image data");
            }
            
            // Send the thumbnail
            header('Content-Type: ' . $thumbnail_mime);
            header('Content-Length: ' . strlen($thumbnail_data));
            header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
            header('X-Content-Type-Options: nosniff');
            
            // Clear any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            echo $thumbnail_data;
            
            // Cleanup
            @unlink($temp_file);
            exit;
        } else {
            error_log("PPT Preview: No embedded thumbnail found - fileid={$fileid}, filename={$filename}");
            // Return 1x1 transparent PNG instead of 404 to avoid console errors
            // JavaScript onerror handler will still catch this and show placeholder
            $transparent_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($transparent_png));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $transparent_png;
            exit;
        }
    } else {
        // Old PPT format - doesn't have embedded thumbnails in ZIP format
        error_log("PPT Preview: Old PPT format not supported (no embedded thumbnail) - fileid={$fileid}, filename={$filename}");
        // Return 1x1 transparent PNG instead of 404 to avoid console errors
        $transparent_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($transparent_png));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $transparent_png;
        exit;
    }
    
} catch (Exception $e) {
    error_log("PPT Preview: Exception - fileid={$fileid}, filename={$filename}, error=" . $e->getMessage());
    
    // Cleanup on error
    if (isset($temp_file) && file_exists($temp_file)) {
        @unlink($temp_file);
    }
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Return 1x1 transparent PNG instead of 404 to avoid console errors
    // JavaScript onerror handler will catch this and show placeholder icon
    $transparent_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($transparent_png));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $transparent_png;
    exit;
}
