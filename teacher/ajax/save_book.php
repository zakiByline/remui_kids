<?php
/**
 * Save Book AJAX Handler
 */

require_once(__DIR__ . '/../../../../config.php');
require_sesskey();

if (!isloggedin()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

global $DB, $USER, $CFG;

// Check if user is teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;

    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
         LIMIT 1",
        $params
    );

    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

if (!$isteacher) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
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

// Handle cover image upload
$cover_image = '';
if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == 0) {
    $file = $_FILES['cover_image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    
    if (in_array($file['type'], $allowed_types)) {
        $upload_dir = $CFG->dataroot . '/theme_remui_kids_books/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = 'book_' . time() . '_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Store relative path or use Moodle file API later
            $cover_image = $CFG->wwwroot . '/dataroot/theme_remui_kids_books/' . $file_name;
            // For better security, consider using Moodle file API instead
        }
    }
}

$time = time();

if ($book_id > 0) {
    // Update existing book
    $book = $DB->get_record('theme_remui_kids_books', ['id' => $book_id]);
    if ($book && ($book->userid == $USER->id || is_siteadmin())) {
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
        echo json_encode(['success' => false, 'message' => 'Book not found or access denied']);
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