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
 * Create Code Editor Handler (theme_remui_kids)
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
error_log("create_codeeditor.php - File accessed via " . $_SERVER['REQUEST_METHOD']);

// Security checks
require_login();
$systemcontext = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $systemcontext) && !is_siteadmin()) {
    error_log("create_codeeditor.php - Access denied for user: " . $USER->id);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Validate sesskey
require_sesskey();

// Log all incoming POST data for debugging
error_log("Code Editor creation - POST data: " . print_r($_POST, true));

try {
    // Get form data
    $name = required_param('name', PARAM_TEXT);
    $courseid = required_param('courseid', PARAM_INT);
    $intro = required_param('intro', PARAM_RAW); // Required for code editor (problem statement)
    $description = optional_param('description', '', PARAM_RAW); // Additional instructions
    $grade = optional_param('grade', 100, PARAM_FLOAT);
    $section = optional_param('section', 0, PARAM_INT);
    $module = optional_param('module', 0, PARAM_INT);
    $grading_method = optional_param('grading_method', 'simple', PARAM_ALPHA);
    
    // Log parsed parameters for debugging
    error_log("Code Editor creation - Parsed parameters:");
    error_log("  Name: " . $name);
    error_log("  Course ID: " . $courseid);
    error_log("  Section: " . $section);
    error_log("  Grade: " . $grade);
    error_log("  Grading method: " . $grading_method);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);
    
    // Process date/time fields - Due date (only due date for code editor)
    $due_day = optional_param('due_day', 0, PARAM_INT);
    $due_month = optional_param('due_month', 0, PARAM_INT);
    $due_year = optional_param('due_year', 0, PARAM_INT);
    $due_hour = optional_param('due_hour', 23, PARAM_INT);
    $due_minute = optional_param('due_minute', 59, PARAM_INT);
    
    if ($due_day && $due_month && $due_year) {
        $duedate = mktime($due_hour, $due_minute, 0, $due_month, $due_day, $due_year);
        error_log("  Calculated due date: " . $duedate . " (" . date('Y-m-d H:i:s', $duedate) . ")");
    } else {
        // Default to 23:59 (end of day) 7 days from now
        $duedate = mktime(23, 59, 0, date('n'), date('j') + 7, date('Y'));
        error_log("  Using default due date (7 days from now at 23:59): " . $duedate . " (" . date('Y-m-d H:i:s', $duedate) . ")");
    }
    error_log("  Final due date: " . $duedate);
    
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    // Create codeeditor record
    $codeeditor = new stdClass();
    $codeeditor->course = $courseid;
    $codeeditor->name = $name;
    $codeeditor->intro = $intro;
    $codeeditor->introformat = FORMAT_HTML;
    $codeeditor->description = $description;
    $codeeditor->descriptionformat = FORMAT_HTML;
    $codeeditor->duedate = $duedate;
    $codeeditor->grade = $grade;
    $codeeditor->timecreated = time();
    $codeeditor->timemodified = time();
    
    $codeeditorid = $DB->insert_record('codeeditor', $codeeditor);
    
    if (!$codeeditorid) {
        throw new Exception('Failed to create code editor record');
    }
    
    error_log("  Code editor record created with ID: " . $codeeditorid);
    
    // Create course module
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'codeeditor']);
    if (!$moduleid) {
        throw new Exception('Code editor module not found');
    }
    
    // Get the course section by ID
    if (!$section) {
        throw new Exception('No section specified for code editor placement');
    }
    
    $coursesection = $DB->get_record('course_sections', [
        'id' => $section,
        'course' => $courseid
    ]);
    
    if (!$coursesection) {
        throw new Exception('Invalid section ID: ' . $section . ' for course: ' . $courseid);
    }
    
    error_log("  Using section - ID: " . $coursesection->id . ", Section Number: " . $coursesection->section);
    
    // Create course module record
    error_log("  Creating course module record");
    
    $coursemodule = new stdClass();
    $coursemodule->course = $courseid;
    $coursemodule->module = $moduleid;
    $coursemodule->instance = $codeeditorid;
    $coursemodule->section = 0; // Will be set by course_add_cm_to_section
    $coursemodule->idnumber = '';
    $coursemodule->added = time();
    $coursemodule->score = 0;
    $coursemodule->indent = 0;
    $coursemodule->visible = 1;
    $coursemodule->visibleoncoursepage = 1;
    $coursemodule->visibleold = 1;
    $coursemodule->groupmode = 0;
    $coursemodule->groupingid = 0;
    $coursemodule->completion = 2; // Completion enabled - mark complete when code is submitted
    $coursemodule->completionview = 0;
    $coursemodule->completionexpected = 0;
    $coursemodule->showdescription = 0;
    $coursemodule->availability = null;
    $coursemodule->deletioninprogress = 0;
    
    try {
        $coursemoduleid = $DB->insert_record('course_modules', $coursemodule);
        error_log("  Course module record created with ID: " . $coursemoduleid);
        
        // Add it to the section using Moodle's API
        $sectionid = course_add_cm_to_section($courseid, $coursemoduleid, $coursesection->section);
        error_log("  Course module added to section, section record ID: " . $sectionid);
        
        // Update the course module with the correct section
        $DB->set_field('course_modules', 'section', $sectionid, ['id' => $coursemoduleid]);
        
        // Get the updated record
        $coursemodule = $DB->get_record('course_modules', ['id' => $coursemoduleid], '*', MUST_EXIST);
        
    } catch (Exception $e) {
        error_log("  ERROR creating/adding course module: " . $e->getMessage());
        throw new Exception('Failed to create course module: ' . $e->getMessage());
    }
    
    // Handle group assignment (restrict visibility to specific groups)
    $assign_to = optional_param('assign_to', 'all', PARAM_ALPHA);
    $group_ids = optional_param_array('group_ids', [], PARAM_INT);
    
    error_log("  Assign to: " . $assign_to);
    error_log("  Group IDs: " . print_r($group_ids, true));
    
    if ($assign_to === 'groups' && !empty($group_ids)) {
        error_log("  Setting up group-based availability restrictions");
        
        // Build availability JSON for group restriction
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
            'op' => '|', // OR operator
            'c' => $availability_conditions,
            'show' => false,
            'showc' => $showc_array
        ];
        
        $availability_json = json_encode($availability);
        error_log("  Availability JSON: " . $availability_json);
        
        // Update course module with availability restriction
        $DB->set_field('course_modules', 'availability', $availability_json, ['id' => $coursemoduleid]);
        
        error_log("  Successfully set group-based availability for " . count($group_ids) . " groups");
    } else {
        error_log("  Code editor visible to all students (no group restrictions)");
    }
    
    // Create grade item for code editor
    grade_update('mod/codeeditor', $courseid, 'mod', 'codeeditor', $codeeditorid, 0, null, array('itemname' => $name));
    
    // Create context
    $modulecontext = context_module::instance($coursemoduleid);
    
    // Create grading area and definition if rubric is selected
    if ($grading_method === 'rubric') {
        error_log("  Creating grading area for rubric method");
        
        // Create grading area for codeeditor
        $gradingarea = new stdClass();
        $gradingarea->contextid = $modulecontext->id;
        $gradingarea->component = 'mod_codeeditor';
        $gradingarea->areaname = 'submissions';
        $gradingarea->activemethod = 'rubric';
        
        $gradingareaid = $DB->insert_record('grading_areas', $gradingarea);
        error_log("  Grading area created with ID: " . $gradingareaid);
        
        // Create grading definition
        $gradingdefinition = new stdClass();
        $gradingdefinition->areaid = $gradingareaid;
        $gradingdefinition->method = 'rubric';
        $gradingdefinition->name = 'Rubric for ' . $name;
        $gradingdefinition->description = '';
        $gradingdefinition->descriptionformat = FORMAT_HTML;
        $gradingdefinition->status = 20; // READY_FOR_USE
        $gradingdefinition->copiedfromid = null;
        $gradingdefinition->timecreated = time();
        $gradingdefinition->usercreated = $USER->id;
        $gradingdefinition->timemodified = time();
        $gradingdefinition->usermodified = $USER->id;
        $gradingdefinition->timecopied = 0;
        $gradingdefinition->options = '{"alwaysshowdefinition":"1","showmarkspercriteria":"1","showgradeitempoints":"1","enableremarks":"1","showremarksinpdf":"1"}';
        
        $gradingdefinitionid = $DB->insert_record('grading_definitions', $gradingdefinition);
        error_log("  Grading definition created with ID: " . $gradingdefinitionid);
        
        // Process rubric criteria and levels
        $criterion_counter = 0;
        $total_criteria = 0;
        $total_levels = 0;
        
        // Get all criterion descriptions from POST
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'criterion_description_') === 0) {
                $criterion_id_from_form = str_replace('criterion_description_', '', $key);
                $criterion_description = trim($value);
                
                if (empty($criterion_description)) {
                    continue; // Skip empty criteria
                }
                
                $criterion_counter++;
                
                // Insert criterion
                $criterion = new stdClass();
                $criterion->definitionid = $gradingdefinitionid;
                $criterion->sortorder = $criterion_counter;
                $criterion->description = $criterion_description;
                $criterion->descriptionformat = FORMAT_HTML;
                
                $criterionid = $DB->insert_record('gradingform_rubric_criteria', $criterion);
                error_log("    Criterion $criterion_counter created: ID=$criterionid");
                $total_criteria++;
                
                // Get levels for this criterion
                $level_scores_key = "criterion_{$criterion_id_from_form}_level_score";
                $level_definitions_key = "criterion_{$criterion_id_from_form}_level_definition";
                
                $level_scores = isset($_POST[$level_scores_key]) ? $_POST[$level_scores_key] : [];
                $level_definitions = isset($_POST[$level_definitions_key]) ? $_POST[$level_definitions_key] : [];
                
                // Insert levels
                for ($i = 0; $i < count($level_scores); $i++) {
                    $score = isset($level_scores[$i]) ? floatval($level_scores[$i]) : 0;
                    $definition = isset($level_definitions[$i]) ? trim($level_definitions[$i]) : '';
                    
                    $level = new stdClass();
                    $level->criterionid = $criterionid;
                    $level->score = $score;
                    $level->definition = $definition;
                    $level->definitionformat = FORMAT_HTML;
                    
                    $levelid = $DB->insert_record('gradingform_rubric_levels', $level);
                    error_log("      Level created: ID=$levelid, Score=$score");
                    $total_levels++;
                }
            }
        }
        
        error_log("  Rubric grading method configured successfully");
        error_log("  Total criteria created: $total_criteria");
        error_log("  Total levels created: $total_levels");
    }
    
    // Link competencies to the code editor module
    $competencies = optional_param_array('competencies', [], PARAM_INT);
    $global_completion_action = optional_param('competency_completion_action', 0, PARAM_INT);
    
    if (!empty($competencies)) {
        error_log("  Linking " . count($competencies) . " competencies to module");
        error_log("  Global completion action: " . $global_completion_action);
        
        $sortorder = 0;
        foreach ($competencies as $competencyid) {
            $modulecomp = new stdClass();
            $modulecomp->cmid = $coursemoduleid;
            $modulecomp->competencyid = $competencyid;
            $modulecomp->timecreated = time();
            $modulecomp->timemodified = time();
            $modulecomp->usermodified = $USER->id;
            $modulecomp->sortorder = $sortorder;
            $modulecomp->ruleoutcome = $global_completion_action; // 0=Do nothing, 1=Attach evidence, 2=Send for review, 3=Complete
            $modulecomp->overridegrade = 0;
            
            $DB->insert_record('competency_modulecomp', $modulecomp);
            error_log("    Linked competency ID: " . $competencyid . " with action: " . $global_completion_action);
            
            $sortorder++;
        }
        
        error_log("  Successfully linked all competencies with global completion action");
    }
    
    // Rebuild course cache
    rebuild_course_cache($courseid, true);
    
    // Purge all caches to make the new code editor immediately visible to students
    cache_helper::purge_by_event('changesincourse');
    cache_helper::purge_by_event('changesincoursecat');
    get_fast_modinfo($courseid, 0, true); // Force reset of modinfo cache
    cache_helper::purge_by_event('changesincoursecompletion');
    
    error_log("  Cache purged - code editor should be immediately visible to students");
    
    // Commit transaction
    $transaction->allow_commit();
    
    // Trigger events
    $event = \core\event\course_module_created::create([
        'objectid' => $coursemoduleid,
        'context' => $modulecontext,
        'other' => [
            'modulename' => 'codeeditor',
            'instanceid' => $codeeditorid,
            'name' => $name
        ]
    ]);
    $event->add_record_snapshot('course_modules', $coursemodule);
    $event->add_record_snapshot('codeeditor', $codeeditor);
    $event->trigger();
    
    echo json_encode([
        'success' => true,
        'message' => 'Code editor activity created successfully',
        'codeeditor_id' => $codeeditorid,
        'course_module_id' => $coursemoduleid,
        'competencies_linked' => isset($competencies) ? count($competencies) : 0,
        'url' => $CFG->wwwroot . '/mod/codeeditor/view.php?id=' . $coursemoduleid
    ]);
    
} catch (Exception $e) {
    if (isset($transaction) && $transaction) {
        $transaction->rollback($e);
    }
    
    error_log("Code editor creation error: " . $e->getMessage());
    error_log("Code editor creation error trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Error creating code editor: ' . $e->getMessage()
    ]);
}
?>