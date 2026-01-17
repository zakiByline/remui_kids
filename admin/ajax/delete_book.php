<?php
/**
 * Delete Book AJAX Handler - Admin
 */

require_once(__DIR__ . '/../../../../config.php');
require_sesskey();

if (!isloggedin()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

global $DB, $USER, $CFG;

// Check if user is admin
if (!is_siteadmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

$book_id = required_param('id', PARAM_INT);

$book = $DB->get_record('theme_remui_kids_books', ['id' => $book_id]);

if ($book) {
    // Delete cover image file if exists
    if ($book->cover_image && strpos($book->cover_image, $CFG->wwwroot) === 0) {
        // Handle both old dataroot path and new pix path
        if (strpos($book->cover_image, '/dataroot/') !== false) {
            $relative_path = str_replace($CFG->wwwroot . '/dataroot/', '', $book->cover_image);
            $file_path = $CFG->dataroot . '/' . $relative_path;
        } else {
            // New pix path
            $relative_path = str_replace($CFG->wwwroot . '/theme/remui_kids/pix/ebooks/', '', $book->cover_image);
            $file_path = __DIR__ . '/../../pix/ebooks/' . $relative_path;
        }
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }
    
    if ($DB->delete_records('theme_remui_kids_books', ['id' => $book_id])) {
        echo json_encode(['success' => true, 'message' => 'Book deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete book']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Book not found']);
}
?>
