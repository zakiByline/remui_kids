<?php
/**
 * Get Book AJAX Handler - Admin
 */

require_once(__DIR__ . '/../../../../config.php');
require_login();

global $DB, $USER;

// Check if user is admin
if (!is_siteadmin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied. Admin only.']);
    exit;
}

$book_id = required_param('id', PARAM_INT);

$book = $DB->get_record('theme_remui_kids_books', ['id' => $book_id]);

if ($book) {
    header('Content-Type: application/json');
    echo json_encode($book);
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Book not found']);
}
?>
