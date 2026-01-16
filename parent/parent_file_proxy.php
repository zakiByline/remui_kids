<?php
/**
 * Parent File Proxy
 *
 * Streams stored files (resources, embedded media) to parents after validating
 * their relationship to the selected child and the child's enrollment in the
 * owning course. This keeps files accessible without granting the parent full
 * enrolment or capability changes.
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');

require_login();
try {
    theme_remui_kids_require_parent(new moodle_url('/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$contextid = required_param('contextid', PARAM_INT);
$component = required_param('component', PARAM_COMPONENT);
$filearea = required_param('filearea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$filepath = required_param('filepath', PARAM_RAW);
$filename = required_param('filename', PARAM_FILE);
$childid = required_param('child', PARAM_INT);

$filepath = base64_decode($filepath, true);
if ($filepath === false) {
    print_error('invalidfile', 'error');
}

set_selected_child($childid);

$children = get_parent_children($USER->id);
if (empty($children) || !is_array($children) || !array_key_exists($childid, array_column($children, null, 'id'))) {
    print_error('nopermissions', 'error');
}

$childrenmap = [];
foreach ($children as $child) {
    $childrenmap[$child['id']] = $child;
}

if (!isset($childrenmap[$childid])) {
    print_error('nopermissions', 'error');
}

$context = context::instance_by_id($contextid, MUST_EXIST);
$courseid = null;

switch ($context->contextlevel) {
    case CONTEXT_MODULE:
        $cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
        $courseid = $cm->course;
        break;
    case CONTEXT_COURSE:
        $courseid = $context->instanceid;
        break;
    default:
        print_error('invalidcontext');
}

$childcourses = enrol_get_users_courses($childid, true, 'id');
if (empty($childcourses) || !array_key_exists($courseid, $childcourses)) {
    print_error('nopermissions', 'error');
}

$fs = get_file_storage();
$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
if (!$file || $file->is_directory()) {
    print_error('invalidfile', 'error');
}

// Stream the file respecting cache headers; force download = false for inline usage.
send_stored_file($file, 0, 0, false);



