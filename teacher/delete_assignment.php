<?php
/**
 * Delete Assignment Endpoint
 * 
 * Handles deletion of assignments and code editor activities
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/filelib.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Verify sesskey
$sesskey = required_param('sesskey', PARAM_TEXT);
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid session key']);
    exit;
}

// Get parameters
$cmid = required_param('cmid', PARAM_INT);
$activity_type = required_param('activity_type', PARAM_ALPHA);

// Get course module
$cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Verify user has permission in this course
$coursecontext = context_course::instance($course->id);
if (!has_capability('moodle/course:manageactivities', $coursecontext)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete activities in this course']);
    exit;
}

// Verify activity type matches
if ($cm->modname !== $activity_type) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Activity type mismatch']);
    exit;
}

try {
    // Use Moodle's course_delete_module function to properly delete the activity
    // This handles all cleanup including files, grades, submissions, etc.
    course_delete_module($cmid);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Assignment deleted successfully']);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error deleting assignment: ' . $e->getMessage()]);
}