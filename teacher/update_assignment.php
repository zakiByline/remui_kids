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
 * Update Assignment Handler (theme_remui_kids)
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Set JSON header
header('Content-Type: application/json');

// Log that the file is being accessed
error_log("update_assignment.php - File accessed via " . $_SERVER['REQUEST_METHOD']);

// Security checks
require_login();
$systemcontext = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $systemcontext) && !is_siteadmin()) {
    error_log("update_assignment.php - Access denied for user: " . $USER->id);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Validate sesskey
require_sesskey();

try {
    // Get assignment ID and course module ID
    $assignmentid = required_param('assignment_id', PARAM_INT);
    $cmid = required_param('cmid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    
    error_log("update_assignment.php - Received parameters:");
    error_log("  assignment_id: $assignmentid");
    error_log("  cmid: $cmid");
    error_log("  courseid: $courseid");
    
    // Get existing assignment
    $assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
    
    error_log("  Found assignment: " . $assignment->name);
    error_log("  Found course module: " . $cm->id);
    
    // Verify access
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);
    
    error_log("  Access verified for user: " . $USER->id);
    
    // Get form parameters (same as create_assignment.php)
    $name = required_param('name', PARAM_TEXT);
    $intro = optional_param('intro', '', PARAM_RAW);
    $activity_instructions = optional_param('activity_instructions', '', PARAM_RAW);
    $grade = optional_param('grade', 100, PARAM_FLOAT);
    $grading_method = optional_param('grading_method', 'simple', PARAM_ALPHA);
    $autocomplete = optional_param('autocomplete', 'none', PARAM_ALPHA);
    
    error_log("Updating assignment ID: $assignmentid");
    error_log("  Name: $name");
    error_log("  Grading method: $grading_method");
    
    // Process dates (same logic as create)
    $allow_from_day = optional_param('allow_from_day', 0, PARAM_INT);
    $allow_from_month = optional_param('allow_from_month', 0, PARAM_INT);
    $allow_from_year = optional_param('allow_from_year', 0, PARAM_INT);
    $allow_from_hour = optional_param('allow_from_hour', 0, PARAM_INT);
    $allow_from_minute = optional_param('allow_from_minute', 0, PARAM_INT);
    
    if ($allow_from_day && $allow_from_month && $allow_from_year) {
        $allowsubmissionsfromdate = mktime($allow_from_hour, $allow_from_minute, 0, $allow_from_month, $allow_from_day, $allow_from_year);
    } else {
        $allowsubmissionsfromdate = $assignment->allowsubmissionsfromdate; // Keep existing
    }
    
    $due_day = optional_param('due_day', 0, PARAM_INT);
    $due_month = optional_param('due_month', 0, PARAM_INT);
    $due_year = optional_param('due_year', 0, PARAM_INT);
    $due_hour = optional_param('due_hour', 0, PARAM_INT);
    $due_minute = optional_param('due_minute', 0, PARAM_INT);
    
    if ($due_day && $due_month && $due_year) {
        $duedate = mktime($due_hour, $due_minute, 0, $due_month, $due_day, $due_year);
    } else {
        $duedate = $assignment->duedate; // Keep existing
    }
    
    $cutoff_day = optional_param('cutoff_day', 0, PARAM_INT);
    $cutoff_month = optional_param('cutoff_month', 0, PARAM_INT);
    $cutoff_year = optional_param('cutoff_year', 0, PARAM_INT);
    $cutoff_hour = optional_param('cutoff_hour', 0, PARAM_INT);
    $cutoff_minute = optional_param('cutoff_minute', 0, PARAM_INT);
    
    if ($cutoff_day && $cutoff_month && $cutoff_year) {
        $cutoffdate = mktime($cutoff_hour, $cutoff_minute, 0, $cutoff_month, $cutoff_day, $cutoff_year);
    } else {
        $cutoffdate = $assignment->cutoffdate; // Keep existing
    }
    
    $reminder_day = optional_param('reminder_day', 0, PARAM_INT);
    $reminder_month = optional_param('reminder_month', 0, PARAM_INT);
    $reminder_year = optional_param('reminder_year', 0, PARAM_INT);
    $reminder_hour = optional_param('reminder_hour', 0, PARAM_INT);
    $reminder_minute = optional_param('reminder_minute', 0, PARAM_INT);
    
    if ($reminder_day && $reminder_month && $reminder_year) {
        $gradingduedate = mktime($reminder_hour, $reminder_minute, 0, $reminder_month, $reminder_day, $reminder_year);
    } else {
        $gradingduedate = $assignment->gradingduedate; // Keep existing
    }
    
    // Combine intro and activity instructions
    $full_intro = $intro;
    if (!empty($activity_instructions)) {
        $full_intro .= '<br><br><h3>Activity Instructions</h3>' . $activity_instructions;
    }
    
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    // Update assignment record - only update specific fields
    $update_data = new stdClass();
    $update_data->id = $assignmentid;
    $update_data->name = $name;
    $update_data->intro = $full_intro;
    $update_data->introformat = FORMAT_HTML;
    $update_data->allowsubmissionsfromdate = $allowsubmissionsfromdate;
    $update_data->duedate = $duedate;
    $update_data->cutoffdate = $cutoffdate;
    $update_data->gradingduedate = $gradingduedate;
    $update_data->grade = $grade;
    $update_data->timemodified = time();
    
    // Set completion settings
    if ($autocomplete === 'submission') {
        $update_data->completionsubmit = 1;
    } else {
        $update_data->completionsubmit = 0;
    }
    
    try {
        $DB->update_record('assign', $update_data);
        error_log("  Assignment record updated successfully");
    } catch (Exception $update_error) {
        error_log("  ERROR updating assignment record: " . $update_error->getMessage());
        error_log("  Update data: " . print_r($update_data, true));
        throw $update_error;
    }
    
    // Fetch existing submission plugin states for defaults
    $onlinetext_config = $DB->get_record('assign_plugin_config', [
        'assignment' => $assignmentid,
        'plugin' => 'onlinetext',
        'subtype' => 'assignsubmission',
        'name' => 'enabled'
    ]);
    
    $existing_online_text = $onlinetext_config ? (int)$onlinetext_config->value : 0;
    $online_text_param = optional_param('online_text', null, PARAM_RAW);
    if ($online_text_param === null) {
        $online_text = $existing_online_text;
    } else {
        $online_text = ($online_text_param === 'on' || $online_text_param === '1') ? 1 : 0;
    }
    
    $file_enabled_config = $DB->get_record('assign_plugin_config', [
        'assignment' => $assignmentid,
        'plugin' => 'file',
        'subtype' => 'assignsubmission',
        'name' => 'enabled'
    ]);
    $existing_file_enabled = $file_enabled_config ? (int)$file_enabled_config->value : 0;
    
    $file_maxsize_config = $DB->get_record('assign_plugin_config', [
        'assignment' => $assignmentid,
        'plugin' => 'file',
        'subtype' => 'assignsubmission',
        'name' => 'maxfilesubmissions'
    ]);
    $existing_max_upload = $file_maxsize_config ? (int)$file_maxsize_config->value : 52428800;
    
    $file_submissions_param = optional_param('file_submissions', null, PARAM_RAW);
    if ($file_submissions_param === null) {
        $file_submissions = $existing_file_enabled;
    } else {
        $file_submissions = ($file_submissions_param === 'on' || $file_submissions_param === '1') ? 1 : 0;
    }
    $max_upload_size = optional_param('max_upload_size', $existing_max_upload, PARAM_INT);
    
    // Update submission types - use update or insert logic
    // Online text plugin
    if ($online_text) {
        if ($onlinetext_config) {
            $DB->set_field('assign_plugin_config', 'value', '1', ['id' => $onlinetext_config->id]);
        } else {
            $plugin_config = new stdClass();
            $plugin_config->assignment = $assignmentid;
            $plugin_config->plugin = 'onlinetext';
            $plugin_config->subtype = 'assignsubmission';
            $plugin_config->name = 'enabled';
            $plugin_config->value = '1';
            $DB->insert_record('assign_plugin_config', $plugin_config);
        }
    } else if ($onlinetext_config) {
        $DB->set_field('assign_plugin_config', 'value', '0', ['id' => $onlinetext_config->id]);
    }
    
    // File submission plugin
    if ($file_submissions) {
        if ($file_enabled_config) {
            $DB->set_field('assign_plugin_config', 'value', '1', ['id' => $file_enabled_config->id]);
        } else {
            $plugin_config = new stdClass();
            $plugin_config->assignment = $assignmentid;
            $plugin_config->plugin = 'file';
            $plugin_config->subtype = 'assignsubmission';
            $plugin_config->name = 'enabled';
            $plugin_config->value = '1';
            $DB->insert_record('assign_plugin_config', $plugin_config);
        }
        
        if ($file_maxsize_config) {
            $DB->set_field('assign_plugin_config', 'value', $max_upload_size, ['id' => $file_maxsize_config->id]);
        } else {
            $plugin_config = new stdClass();
            $plugin_config->assignment = $assignmentid;
            $plugin_config->plugin = 'file';
            $plugin_config->subtype = 'assignsubmission';
            $plugin_config->name = 'maxfilesubmissions';
            $plugin_config->value = $max_upload_size;
            $DB->insert_record('assign_plugin_config', $plugin_config);
        }
    } else if ($file_enabled_config) {
        $DB->set_field('assign_plugin_config', 'value', '0', ['id' => $file_enabled_config->id]);
    }
    
    error_log("  Submission types updated");
    
    // Update course module completion
    error_log("  Updating course module completion...");
    try {
        if ($autocomplete === 'grading') {
            $DB->set_field('course_modules', 'completion', 2, ['id' => $cmid]);
            error_log("    Set completion to 2 (grading)");
        } else if ($autocomplete === 'submission') {
            $DB->set_field('course_modules', 'completion', 1, ['id' => $cmid]);
            error_log("    Set completion to 1 (submission)");
        }
    } catch (Exception $e) {
        error_log("  ERROR updating completion: " . $e->getMessage());
        throw $e;
    }
    
    // Update competencies
    error_log("  Updating competencies...");
    $competencies = optional_param_array('competencies', [], PARAM_INT);
    $global_completion_action = optional_param('competency_completion_action', 0, PARAM_INT);
    
    try {
        // Delete existing competency links
        $deleted = $DB->delete_records('competency_modulecomp', ['cmid' => $cmid]);
        error_log("    Deleted existing competency links");
        
        // Add new competency links
        if (!empty($competencies)) {
            $sortorder = 0;
            foreach ($competencies as $competencyid) {
                $modulecomp = new stdClass();
                $modulecomp->cmid = $cmid;
                $modulecomp->competencyid = $competencyid;
                $modulecomp->timecreated = time();
                $modulecomp->timemodified = time();
                $modulecomp->usermodified = $USER->id;
                $modulecomp->sortorder = $sortorder;
                $modulecomp->ruleoutcome = $global_completion_action;
                $modulecomp->overridegrade = 0;
                
                $DB->insert_record('competency_modulecomp', $modulecomp);
                error_log("      Linked competency ID: $competencyid");
                $sortorder++;
            }
            error_log("    Updated " . count($competencies) . " competency links");
        }
    } catch (Exception $e) {
        error_log("  ERROR updating competencies: " . $e->getMessage());
        throw $e;
    }
    
    // Update group assignment
    error_log("  Updating group assignment...");
    $assign_to = optional_param('assign_to', 'all', PARAM_ALPHA);
    $group_ids = optional_param_array('group_ids', [], PARAM_INT);
    
    try {
        if ($assign_to === 'groups' && !empty($group_ids)) {
            $availability_conditions = [];
            $showc_array = [];
            foreach ($group_ids as $groupid) {
                $availability_conditions[] = [
                    'type' => 'group',
                    'id' => (int)$groupid
                ];
                $showc_array[] = false;
            }
            $availability = [
                'op' => '|',
                'c' => $availability_conditions,
                'show' => false,
                'showc' => $showc_array
            ];
            $availability_json = json_encode($availability);
            $DB->set_field('course_modules', 'availability', $availability_json, ['id' => $cmid]);
            error_log("    Updated group restrictions");
        } else {
            $DB->set_field('course_modules', 'availability', null, ['id' => $cmid]);
            error_log("    Removed group restrictions");
        }
    } catch (Exception $e) {
        error_log("  ERROR updating group assignment: " . $e->getMessage());
        throw $e;
    }
    
    // Update rubric if grading method is rubric
    error_log("  Updating rubric...");
    $modulecontext = context_module::instance($cmid);
    
    if ($grading_method === 'rubric') {
        try {
            // Get or create grading area
            $gradingarea = $DB->get_record('grading_areas', [
                'contextid' => $modulecontext->id,
                'component' => 'mod_assign',
                'areaname' => 'submissions'
            ]);
            
            if (!$gradingarea) {
                error_log("    Creating new grading area");
                $gradingarea = new stdClass();
                $gradingarea->contextid = $modulecontext->id;
                $gradingarea->component = 'mod_assign';
                $gradingarea->areaname = 'submissions';
                $gradingarea->activemethod = 'rubric';
                $gradingareaid = $DB->insert_record('grading_areas', $gradingarea);
                error_log("    Grading area created: ID=$gradingareaid");
            } else {
                $gradingareaid = $gradingarea->id;
                $DB->set_field('grading_areas', 'activemethod', 'rubric', ['id' => $gradingareaid]);
                error_log("    Updated existing grading area: ID=$gradingareaid");
            }
            
            // Get or create grading definition
            $gradingdefinition = $DB->get_record('grading_definitions', ['areaid' => $gradingareaid]);
            
            if (!$gradingdefinition) {
                error_log("    Creating new grading definition");
                $gradingdefinition = new stdClass();
                $gradingdefinition->areaid = $gradingareaid;
                $gradingdefinition->method = 'rubric';
                $gradingdefinition->name = 'Rubric for ' . $name;
                $gradingdefinition->description = '';
                $gradingdefinition->descriptionformat = FORMAT_HTML;
                $gradingdefinition->status = 20;
                $gradingdefinition->copiedfromid = null;
                $gradingdefinition->timecreated = time();
                $gradingdefinition->usercreated = $USER->id;
                $gradingdefinition->timemodified = time();
                $gradingdefinition->usermodified = $USER->id;
                $gradingdefinition->timecopied = 0;
                $gradingdefinition->options = '{"alwaysshowdefinition":"1","showmarkspercriteria":"1","showgradeitempoints":"1","enableremarks":"1","showremarksinpdf":"1"}';
                $gradingdefinitionid = $DB->insert_record('grading_definitions', $gradingdefinition);
                error_log("    Grading definition created: ID=$gradingdefinitionid");
            } else {
                $gradingdefinitionid = $gradingdefinition->id;
                $DB->set_field('grading_definitions', 'timemodified', time(), ['id' => $gradingdefinitionid]);
                $DB->set_field('grading_definitions', 'usermodified', $USER->id, ['id' => $gradingdefinitionid]);
                error_log("    Updated existing grading definition: ID=$gradingdefinitionid");
            }
            
            // Delete existing criteria and levels
            error_log("    Deleting existing rubric criteria and levels");
            $existing_criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $gradingdefinitionid]);
            foreach ($existing_criteria as $criterion) {
                $DB->delete_records('gradingform_rubric_levels', ['criterionid' => $criterion->id]);
            }
            $DB->delete_records('gradingform_rubric_criteria', ['definitionid' => $gradingdefinitionid]);
            error_log("    Deleted " . count($existing_criteria) . " existing criteria");
            
            // Insert new criteria and levels (same logic as create)
            error_log("    Inserting new rubric criteria...");
            $criterion_counter = 0;
            $total_levels = 0;
            
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'criterion_description_') === 0) {
                    $criterion_id_from_form = str_replace('criterion_description_', '', $key);
                    $criterion_description = trim($value);
                    
                    if (empty($criterion_description)) {
                        continue;
                    }
                    
                    $criterion_counter++;
                    
                    $criterion = new stdClass();
                    $criterion->definitionid = $gradingdefinitionid;
                    $criterion->sortorder = $criterion_counter;
                    $criterion->description = $criterion_description;
                    $criterion->descriptionformat = FORMAT_HTML;
                    
                    $criterionid = $DB->insert_record('gradingform_rubric_criteria', $criterion);
                    error_log("      Criterion $criterion_counter created: ID=$criterionid");
                    
                    $level_scores_key = "criterion_{$criterion_id_from_form}_level_score";
                    $level_definitions_key = "criterion_{$criterion_id_from_form}_level_definition";
                    
                    $level_scores = isset($_POST[$level_scores_key]) ? $_POST[$level_scores_key] : [];
                    $level_definitions = isset($_POST[$level_definitions_key]) ? $_POST[$level_definitions_key] : [];
                    
                    for ($i = 0; $i < count($level_scores); $i++) {
                        $score = isset($level_scores[$i]) ? floatval($level_scores[$i]) : 0;
                        $definition = isset($level_definitions[$i]) ? trim($level_definitions[$i]) : '';
                        
                        $level = new stdClass();
                        $level->criterionid = $criterionid;
                        $level->score = $score;
                        $level->definition = $definition;
                        $level->definitionformat = FORMAT_HTML;
                        
                        $DB->insert_record('gradingform_rubric_levels', $level);
                        $total_levels++;
                    }
                    error_log("        Added " . count($level_scores) . " levels");
                }
            }
            error_log("    Rubric updated: $criterion_counter criteria, $total_levels levels");
        } catch (Exception $e) {
            error_log("  ERROR in rubric update: " . $e->getMessage());
            error_log("  Error trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    // Update grade item
    error_log("  Updating grade item...");
    try {
        grade_update('mod/assign', $courseid, 'mod', 'assign', $assignmentid, 0, null, array('itemname' => $name));
        error_log("    Grade item updated");
    } catch (Exception $e) {
        error_log("  ERROR updating grade item: " . $e->getMessage());
        throw $e;
    }
    
    // Purge caches
    error_log("  Purging caches...");
    try {
        rebuild_course_cache($courseid, true);
        cache_helper::purge_by_event('changesincourse');
        cache_helper::purge_by_event('changesincoursecat');
        get_fast_modinfo($courseid, 0, true);
        error_log("    Cache purged successfully");
    } catch (Exception $e) {
        error_log("  ERROR purging cache: " . $e->getMessage());
        throw $e;
    }
    
    // Commit transaction
    error_log("  Committing transaction...");
    $transaction->allow_commit();
    error_log("  Transaction committed successfully");
    
    echo json_encode([
        'success' => true,
        'message' => 'Assignment updated successfully',
        'assignment_id' => $assignmentid,
        'url' => $CFG->wwwroot . '/mod/assign/view.php?id=' . $cmid
    ]);
    
} catch (Exception $e) {
    if (isset($transaction) && $transaction) {
        $transaction->rollback($e);
    }
    
    error_log("Assignment update error: " . $e->getMessage());
    error_log("Assignment update error trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Error updating assignment: ' . $e->getMessage()
    ]);
}

