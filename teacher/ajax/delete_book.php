<?php
/**
 * Delete Book AJAX Handler
 */

require_once(__DIR__ . '/../../../../config.php');
require_sesskey();
require_login();

global $DB, $USER;

$book_id = required_param('id', PARAM_INT);

$book = $DB->get_record('theme_remui_kids_books', ['id' => $book_id]);

global $CFG;

if ($book && ($book->userid == $USER->id || is_siteadmin())) {
    // Delete cover image if exists
    if ($book->cover_image) {
        $image_path = str_replace($CFG->wwwroot . '/dataroot/', $CFG->dataroot . '/', $book->cover_image);
        if (file_exists($image_path)) {
            @unlink($image_path);
        }
    }
    
    if ($DB->delete_records('theme_remui_kids_books', ['id' => $book_id])) {
        echo json_encode(['success' => true, 'message' => 'Book deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete book']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Book not found or access denied']);
}
?>