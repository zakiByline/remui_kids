<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Save Manual Grade (theme_remui_kids)
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');

// Set JSON header
header('Content-Type: application/json');

// Security check
require_login();
$systemcontext = context_system::instance();

if (!has_capability('moodle/grade:edit', $systemcontext) && !has_capability('moodle/grade:manage', $systemcontext) && !is_siteadmin()) {
    echo json_encode(['success' => false, 'message' => 'No permission to edit grades']);
    exit;
}

// Get parameters
$userid = required_param('userid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);
$grade = optional_param('grade', '', PARAM_TEXT);

try {
    // Validate grade item
    $gradeitem = $DB->get_record('grade_items', ['id' => $itemid], '*', MUST_EXIST);
    
    // Validate user
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    
    // Check if user is enrolled in the course
    $coursecontext = context_course::instance($gradeitem->courseid);
    if (!is_enrolled($coursecontext, $userid)) {
        echo json_encode(['success' => false, 'message' => 'User not enrolled in course']);
        exit;
    }
    
    // Prepare grade data
    $gradedata = new stdClass();
    $gradedata->userid = $userid;
    $gradedata->itemid = $itemid;
    
    if ($grade === '' || $grade === null) {
        // Clear the grade
        $gradedata->finalgrade = null;
        $gradedata->rawgrade = null;
    } else {
        // Set the grade
        $finalgrade = (float)$grade;
        $gradedata->finalgrade = $finalgrade;
        $gradedata->rawgrade = $finalgrade;
    }
    
    $gradedata->timemodified = time();
    $gradedata->usermodified = $USER->id;
    
    // Check if grade already exists
    $existinggrade = $DB->get_record('grade_grades', [
        'userid' => $userid,
        'itemid' => $itemid
    ]);
    
    if ($existinggrade) {
        // Update existing grade
        $gradedata->id = $existinggrade->id;
        $DB->update_record('grade_grades', $gradedata);
    } else {
        // Insert new grade
        $gradedata->id = $DB->insert_record('grade_grades', $gradedata);
    }
    
    // Trigger grade updated event
    $eventdata = [
        'objectid' => $gradedata->id,
        'context' => $coursecontext,
        'courseid' => $gradeitem->courseid,
        'userid' => $userid,
        'itemid' => $itemid
    ];
    
    // Log the grade change
    $logdata = [
        'userid' => $userid,
        'itemid' => $itemid,
        'grade' => $grade,
        'teacher' => $USER->id,
        'timestamp' => time()
    ];
    error_log("Manual Grade Saved: " . json_encode($logdata));
    
    echo json_encode(['success' => true, 'message' => 'Grade saved successfully']);
    
} catch (Exception $e) {
    error_log("Manual Grade Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error saving grade: ' . $e->getMessage()]);
}

