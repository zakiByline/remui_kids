<?php
/**
 * Secure file proxy for parent previews.
 *
 * Allows verified parents to preview/download course files that belong to
 * their child's courses without requiring direct enrolment in the course.
 *
 * @package    theme_remui_kids
 * @copyright  2024
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');

require_login();

global $USER, $CFG, $DB;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');

$fileid = required_param('fileid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$childid = required_param('child', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);

// Ensure user is a parent with permission to access parent pages.
try {
    theme_remui_kids_require_parent(new moodle_url('/theme/remui_kids/parent/parent_dashboard.php'));
} catch (Exception $e) {
    print_error('nopermissions', 'error', 'parent');
}

$children = get_parent_children($USER->id);
$childmap = [];
if (!empty($children)) {
    foreach ($children as $child) {
        $childmap[$child['id']] = $child;
    }
}

if (empty($childmap) || !isset($childmap[$childid])) {
    print_error('nopermissions', 'error', 'child');
}

// Ensure the selected child is enrolled in the requested course.
$enrolledcourses = enrol_get_users_courses($childid, true, 'id');
if (empty($enrolledcourses) || !array_key_exists($courseid, $enrolledcourses)) {
    print_error('nopermissions', 'error', 'course');
}

$course = get_course($courseid);
$coursecontext = context_course::instance($courseid);

$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);
if (!$file) {
    print_error('filenotfound', 'error');
}

$context = context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
    print_error('nopermissions', 'error', 'context');
}

$cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
if ((int)$cm->course !== (int)$courseid) {
    print_error('invalidcourseid', 'error');
}

$allowedcomponents = [
    'mod_resource',
    'mod_folder',
    'mod_page',
    'mod_assign',
    'mod_book',
    'mod_lesson',
    'mod_quiz'
];

if (!in_array($file->get_component(), $allowedcomponents, true)) {
    print_error('nopermissions', 'error', 'component');
}

// Verify the child can actually see the module.
$modinfo = get_fast_modinfo($course, $childid);
if (empty($modinfo->cms[$cm->id]) || !$modinfo->cms[$cm->id]->uservisible) {
    print_error('nopermissions', 'error', 'visibility');
}

// Everything looks good: send the file content.
\core\session\manager::write_close();

$forcedownload = (bool)$download;
send_stored_file($file, 0, 0, $forcedownload);
// send_stored_file() terminates execution.

