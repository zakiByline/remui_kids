<?php
/**
 * PDF Preview Generator
 * Generates a preview image of the first page of a PDF file
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
    http_response_code(403);
    die('Invalid token');
}

$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);

if (!$file || $file->is_directory()) {
    http_response_code(404);
    die('File not found');
}

// Verify it's a PDF
$filename = $file->get_filename();
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    http_response_code(400);
    die('Not a PDF file');
}

// Check if preview already exists in file storage
$fs = get_file_storage();
$preview_filename = 'preview_' . $file->get_contenthash() . '.jpg';
$system_context = context_system::instance();

// Try to get existing preview
$preview_file = $fs->get_file(
    $system_context->id,
    'theme_remui_kids',
    'pdf_previews',
    0,
    '/',
    $preview_filename
);

if ($preview_file) {
    // Serve existing preview
    send_stored_file($preview_file, 0, 0, false, [
        'cacheability' => 'public',
        'dontdie' => false,
    ]);
    exit;
}

// Generate preview using ImageMagick
$preview_image = null;

// Check if ImageMagick is available
if (extension_loaded('imagick')) {
    try {
        $pdf_content = $file->get_content();
        
        $imagick = new Imagick();
        $imagick->setResolution(150, 150); // Set resolution for better quality
        $imagick->readImageBlob($pdf_content);
        $imagick->setIteratorIndex(0); // Get first page only
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(85);
        
        // Resize to max width 800px while maintaining aspect ratio
        $imagick->scaleImage(800, 0);
        
        $preview_image = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();
    } catch (Exception $e) {
        error_log('PDF Preview Error (ImageMagick): ' . $e->getMessage());
        $preview_image = null;
    }
}

// Fallback: Try using Ghostscript if ImageMagick failed
if ($preview_image === null && function_exists('shell_exec')) {
    // Check if gs (Ghostscript) is available
    $gs_path = '';
    $possible_paths = [
        '/usr/bin/gs',
        '/usr/local/bin/gs',
        'gs', // Try system PATH
    ];
    
    foreach ($possible_paths as $path) {
        $test = @shell_exec("which $path 2>/dev/null");
        if (!empty($test)) {
            $gs_path = trim($test);
            break;
        }
    }
    
    if (!empty($gs_path)) {
        try {
            // Create temporary files
            $temp_pdf = $CFG->tempdir . '/pdf_preview_' . $fileid . '_' . time() . '.pdf';
            $temp_image = $CFG->tempdir . '/pdf_preview_' . $fileid . '_' . time() . '.jpg';
            
            // Write PDF to temp file
            file_put_contents($temp_pdf, $file->get_content());
            
            // Convert first page to image using Ghostscript
            $command = escapeshellarg($gs_path) . 
                       ' -dNOPAUSE -dBATCH -sDEVICE=jpeg' .
                       ' -dFirstPage=1 -dLastPage=1' .
                       ' -r150' . // Resolution
                       ' -dJPEGQ=85' . // Quality
                       ' -sOutputFile=' . escapeshellarg($temp_image) .
                       ' ' . escapeshellarg($temp_pdf) . ' 2>&1';
            
            $output = @shell_exec($command);
            
            if (file_exists($temp_image)) {
                $preview_image = file_get_contents($temp_image);
                
                // Resize if too large (max width 800px)
                if (extension_loaded('gd')) {
                    $img = imagecreatefromstring($preview_image);
                    if ($img) {
                        $width = imagesx($img);
                        $height = imagesy($img);
                        
                        if ($width > 800) {
                            $new_width = 800;
                            $new_height = (int)($height * ($new_width / $width));
                            $resized = imagecreatetruecolor($new_width, $new_height);
                            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                            
                            ob_start();
                            imagejpeg($resized, null, 85);
                            $preview_image = ob_get_clean();
                            
                            imagedestroy($resized);
                            imagedestroy($img);
                        } else {
                            imagedestroy($img);
                        }
                    }
                }
                
                // Clean up temp files
                @unlink($temp_pdf);
                @unlink($temp_image);
            } else {
                @unlink($temp_pdf);
            }
        } catch (Exception $e) {
            error_log('PDF Preview Error (Ghostscript): ' . $e->getMessage());
            $preview_image = null;
        }
    }
}

// If preview generation failed, create a placeholder
if ($preview_image === null) {
    // Create a simple placeholder image
    if (extension_loaded('gd')) {
        $width = 800;
        $height = 600;
        $img = imagecreatetruecolor($width, $height);
        
        // Background color (light gray)
        $bg_color = imagecolorallocate($img, 248, 249, 250);
        imagefill($img, 0, 0, $bg_color);
        
        // Text color
        $text_color = imagecolorallocate($img, 108, 117, 125);
        
        // Add PDF icon text
        $font_size = 5; // Use built-in font
        $text = 'PDF';
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = ($width - $text_width) / 2;
        $y = ($height - $text_height) / 2;
        imagestring($img, $font_size, $x, $y, $text, $text_color);
        
        ob_start();
        imagejpeg($img, null, 85);
        $preview_image = ob_get_clean();
        imagedestroy($img);
    } else {
        // If GD is not available, return 404
        http_response_code(503);
        die('Preview generation not available');
    }
}

// Store the preview in file storage for future requests
try {
    $file_record = [
        'contextid' => $system_context->id,
        'component' => 'theme_remui_kids',
        'filearea' => 'pdf_previews',
        'itemid' => 0,
        'filepath' => '/',
        'filename' => $preview_filename,
        'mimetype' => 'image/jpeg',
    ];
    $fs->create_file_from_string($file_record, $preview_image);
} catch (Exception $e) {
    // If storage fails, just serve the image (don't fail the request)
    error_log('PDF Preview Storage Error: ' . $e->getMessage());
}

// Serve the preview image
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($preview_image));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
echo $preview_image;

