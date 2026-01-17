<?php
/**
 * Save Book AJAX Handler - Admin
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

// Get form data
$book_id = optional_param('book_id', 0, PARAM_INT);
$level = required_param('level', PARAM_TEXT);
$subject = required_param('subject', PARAM_TEXT);
$book_type = required_param('book_type', PARAM_TEXT);
$title = required_param('title', PARAM_TEXT);
$description = optional_param('description', '', PARAM_TEXT);
$book_link = required_param('book_link', PARAM_URL);

// Validate URL format (should be smartbooks.kodeit.co format)
if (!filter_var($book_link, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid URL format']);
    exit;
}

// Handle cover image upload
$cover_image = '';
if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == 0) {
    $file = $_FILES['cover_image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    
    if (in_array($file['type'], $allowed_types)) {
        // Use web-accessible directory within theme
        $upload_dir = __DIR__ . '/../../pix/ebooks/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $file_name = 'book_' . time() . '_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Store web-accessible path
            $cover_image = $CFG->wwwroot . '/theme/remui_kids/pix/ebooks/' . $file_name;
        }
    }
}

$time = time();

if ($book_id > 0) {
    // Update existing book
    $book = $DB->get_record('theme_remui_kids_books', ['id' => $book_id]);
    if ($book) {
        $book->title = $title;
        $book->description = $description;
        $book->book_link = $book_link;
        if ($cover_image) {
            $book->cover_image = $cover_image;
        }
        $book->timemodified = $time;
        
        if ($DB->update_record('theme_remui_kids_books', $book)) {
            echo json_encode(['success' => true, 'message' => 'Book updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update book']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Book not found']);
    }
} else {
    // Insert new book
    $book = new stdClass();
    $book->level = $level;
    $book->subject = $subject;
    $book->book_type = $book_type;
    $book->title = $title;
    $book->description = $description;
    $book->book_link = $book_link;
    $book->cover_image = $cover_image;
    $book->userid = $USER->id;
    $book->timecreated = $time;
    $book->timemodified = $time;
    
    if ($DB->insert_record('theme_remui_kids_books', $book)) {
        echo json_encode(['success' => true, 'message' => 'Book added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add book']);
    }
}
?>
