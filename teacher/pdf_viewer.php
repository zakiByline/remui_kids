<?php
/**
 * Custom PDF Viewer for Assignment Submissions
 * 
 * This file serves PDF files inline without forcing downloads,
 * specifically for the assignment grading interface.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

// Get parameters
$fileid = required_param('fileid', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);
$component = required_param('component', PARAM_ALPHAEXT);
$filearea = required_param('filearea', PARAM_ALPHAEXT);
$itemid = required_param('itemid', PARAM_INT);
$filepath = required_param('filepath', PARAM_PATH);
$filename = required_param('filename', PARAM_FILE);

// Security checks
require_login();

// Get the file
$fs = get_file_storage();
$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

if (!$file || $file->is_directory()) {
    throw new moodle_exception('filenotfound', 'error');
}

// Verify it's a PDF file
if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
    throw new moodle_exception('invalidfiletype', 'error');
}

// Additional security check - verify user has access to this file
$context = context::instance_by_id($contextid);
if (!$context) {
    throw new moodle_exception('invalidcontext', 'error');
}

// For assignment submissions, check if user can view the assignment
if ($component === 'assignsubmission_file') {
    // Get the assignment from the context
    $cm = get_coursemodule_from_id('assign', $context->instanceid);
    if (!$cm) {
        throw new moodle_exception('invalidcoursemodule', 'error');
    }
    
    // Check if user can view this assignment
    require_capability('mod/assign:grade', $context);
}

// Set appropriate headers for inline PDF viewing
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . $file->get_filesize());
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output the file content
$file->readfile();

