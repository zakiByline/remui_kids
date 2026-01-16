<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * API endpoint for submitting code from the IDE
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/codeeditor/lib.php');

// Get parameters
$cmid = required_param('cmid', PARAM_INT);
$code = required_param('code', PARAM_RAW);
$language = optional_param('language', 'python', PARAM_TEXT);
$output = optional_param('output', '', PARAM_RAW);

// Verify sesskey for security
require_sesskey();

// Get course module and verify permissions
$cm = get_coursemodule_from_id('codeeditor', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$codeeditor = $DB->get_record('codeeditor', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Check if user can submit (students, teachers, or admins)
$cansubmit = has_capability('mod/codeeditor:submit', $context) || 
             has_capability('moodle/site:config', context_system::instance());

if (!$cansubmit) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode([
        'success' => false,
        'error' => 'You do not have permission to submit code'
    ]);
    die();
}

try {
    // Mark any previous submissions as not latest
    $DB->set_field('codeeditor_submissions', 'latest', 0, [
        'codeeditorid' => $codeeditor->id,
        'userid' => $USER->id
    ]);
    
    // Create new submission
    $submission = new stdClass();
    $submission->codeeditorid = $codeeditor->id;
    $submission->userid = $USER->id;
    $submission->code = $code;
    $submission->language = $language;
    $submission->output = $output;
    $submission->status = 'submitted';
    $submission->timecreated = time();
    $submission->timemodified = time();
    $submission->latest = 1;
    $submission->attemptnumber = $DB->count_records('codeeditor_submissions', [
        'codeeditorid' => $codeeditor->id,
        'userid' => $USER->id
    ]) + 1;
    
    $submissionid = $DB->insert_record('codeeditor_submissions', $submission);
    
    // Trigger submission event
    $event = \mod_codeeditor\event\submission_created::create(array(
        'objectid' => $submissionid,
        'context' => $context,
        'other' => array(
            'codeeditorid' => $codeeditor->id
        )
    ));
    $event->trigger();
    
    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'submissionid' => $submissionid,
        'message' => 'Code submitted successfully!',
        'timestamp' => userdate(time())
    ]);
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

