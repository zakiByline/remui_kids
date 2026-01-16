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
 * Create Assignment Handler (theme_remui_kids)
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
error_log("create_assignment.php - File accessed via " . $_SERVER['REQUEST_METHOD']);

// Security checks
require_login();
$systemcontext = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $systemcontext) && !is_siteadmin()) {
    error_log("create_assignment.php - Access denied for user: " . $USER->id);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Validate sesskey
require_sesskey();

// Log all incoming POST data for debugging
error_log("Assignment creation - POST data: " . print_r($_POST, true));
error_log("Assignment creation - FILES data: " . print_r($_FILES, true));

try {
    // Get form data
    $name = required_param('name', PARAM_TEXT);
    $courseid = required_param('courseid', PARAM_INT);
    $intro = optional_param('intro', '', PARAM_RAW);
    $activity_instructions = optional_param('activity_instructions', '', PARAM_RAW);
    $grade = optional_param('grade', 100, PARAM_FLOAT);
    $section = optional_param('section', 0, PARAM_INT);
    $module = optional_param('module', 0, PARAM_INT);
    
    // Log parsed parameters for debugging
    error_log("Assignment creation - Parsed parameters:");
    error_log("  Name: " . $name);
    error_log("  Course ID: " . $courseid);
    error_log("  Section: " . $section);
    error_log("  Grade: " . $grade);
    
    // Get submission type settings (checkboxes send "on" when checked, nothing when unchecked)
    $online_text = optional_param('online_text', '', PARAM_TEXT) === 'on' ? 1 : 0;
    $file_submissions = optional_param('file_submissions', '', PARAM_TEXT) === 'on' ? 1 : 0;
    $max_upload_size = optional_param('max_upload_size', 52428800, PARAM_INT); // Default 50MB
    $autocomplete = optional_param('autocomplete', 'submission', PARAM_ALPHA);
    $grading_method = optional_param('grading_method', 'simple', PARAM_ALPHA);
    
    error_log("  Online text: " . $online_text);
    error_log("  File submissions: " . $file_submissions);
    error_log("  Max upload size: " . $max_upload_size . " bytes (" . ($max_upload_size / 1048576) . " MB)");
    error_log("  Raw POST max_upload_size: " . ($_POST['max_upload_size'] ?? 'NOT SET'));
    error_log("  Autocomplete: " . $autocomplete);
    error_log("  Grading method: " . $grading_method);
    
    // Debug date parameters
    error_log("Date parameters debug:");
    error_log("  enable_allow_from: " . optional_param('enable_allow_from', 'NOT_SET', PARAM_TEXT));
    error_log("  allow_from_day: " . optional_param('allow_from_day', 'NOT_SET', PARAM_TEXT));
    error_log("  allow_from_month: " . optional_param('allow_from_month', 'NOT_SET', PARAM_TEXT));
    error_log("  allow_from_year: " . optional_param('allow_from_year', 'NOT_SET', PARAM_TEXT));
    error_log("  enable_due_date: " . optional_param('enable_due_date', 'NOT_SET', PARAM_TEXT));
    error_log("  due_day: " . optional_param('due_day', 'NOT_SET', PARAM_TEXT));
    error_log("  due_month: " . optional_param('due_month', 'NOT_SET', PARAM_TEXT));
    error_log("  due_year: " . optional_param('due_year', 'NOT_SET', PARAM_TEXT));
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);
    
    // Process date/time fields - Allow submissions from
    $allow_from_day = optional_param('allow_from_day', 0, PARAM_INT);
    $allow_from_month = optional_param('allow_from_month', 0, PARAM_INT);
    $allow_from_year = optional_param('allow_from_year', 0, PARAM_INT);
    $allow_from_hour = optional_param('allow_from_hour', 0, PARAM_INT);
    $allow_from_minute = optional_param('allow_from_minute', 0, PARAM_INT);
    
    if ($allow_from_day && $allow_from_month && $allow_from_year) {
        $allowsubmissionsfromdate = mktime($allow_from_hour, $allow_from_minute, 0, $allow_from_month, $allow_from_day, $allow_from_year);
        error_log("  Calculated allow from date: " . $allowsubmissionsfromdate . " (" . date('Y-m-d H:i:s', $allowsubmissionsfromdate) . ")");
    } else {
        // Default to midnight (00:00) of today to avoid timezone confusion
        $allowsubmissionsfromdate = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
        error_log("  Using default allow from date (midnight today): " . $allowsubmissionsfromdate . " (" . date('Y-m-d H:i:s', $allowsubmissionsfromdate) . ")");
    }
    error_log("  Final allow from date: " . $allowsubmissionsfromdate);
    
    // Process date/time fields - Due date
    $due_day = optional_param('due_day', 0, PARAM_INT);
    $due_month = optional_param('due_month', 0, PARAM_INT);
    $due_year = optional_param('due_year', 0, PARAM_INT);
    $due_hour = optional_param('due_hour', 0, PARAM_INT);
    $due_minute = optional_param('due_minute', 0, PARAM_INT);
    
    if ($due_day && $due_month && $due_year) {
        $duedate = mktime($due_hour, $due_minute, 0, $due_month, $due_day, $due_year);
        error_log("  Calculated due date: " . $duedate . " (" . date('Y-m-d H:i:s', $duedate) . ")");
    } else {
        // Default to 23:59 (end of day) 7 days from now
        $duedate = mktime(23, 59, 0, date('n'), date('j') + 7, date('Y'));
        error_log("  Using default due date (7 days from now at 23:59): " . $duedate . " (" . date('Y-m-d H:i:s', $duedate) . ")");
    }
    error_log("  Final due date: " . $duedate);
    
    // Process date/time fields - Cut-off date
    $cutoffdate = 0;
    $cutoff_day = optional_param('cutoff_day', 0, PARAM_INT);
    $cutoff_month = optional_param('cutoff_month', 0, PARAM_INT);
    $cutoff_year = optional_param('cutoff_year', 0, PARAM_INT);
    $cutoff_hour = optional_param('cutoff_hour', 0, PARAM_INT);
    $cutoff_minute = optional_param('cutoff_minute', 0, PARAM_INT);
    
    if ($cutoff_day && $cutoff_month && $cutoff_year) {
        $cutoffdate = mktime($cutoff_hour, $cutoff_minute, 0, $cutoff_month, $cutoff_day, $cutoff_year);
        error_log("  Calculated cutoff date: " . $cutoffdate . " (" . date('Y-m-d H:i:s', $cutoffdate) . ")");
    }
    error_log("  Final cutoff date: " . $cutoffdate);
    
    // Process date/time fields - Grading reminder
    $gradingduedate = 0;
    $reminder_day = optional_param('reminder_day', 0, PARAM_INT);
    $reminder_month = optional_param('reminder_month', 0, PARAM_INT);
    $reminder_year = optional_param('reminder_year', 0, PARAM_INT);
    $reminder_hour = optional_param('reminder_hour', 0, PARAM_INT);
    $reminder_minute = optional_param('reminder_minute', 0, PARAM_INT);
    
    if ($reminder_day && $reminder_month && $reminder_year) {
        $gradingduedate = mktime($reminder_hour, $reminder_minute, 0, $reminder_month, $reminder_day, $reminder_year);
        error_log("  Calculated reminder date: " . $gradingduedate . " (" . date('Y-m-d H:i:s', $gradingduedate) . ")");
    }
    error_log("  Final reminder date: " . $gradingduedate);
    
    // Combine intro and activity instructions
    $full_intro = $intro;
    if (!empty($activity_instructions)) {
        $full_intro .= '<br><br><h3>Activity Instructions</h3>' . $activity_instructions;
    }
    
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    // Create assignment record
    $assignment = new stdClass();
    $assignment->course = $courseid;
    $assignment->name = $name;
    $assignment->intro = $full_intro;
    $assignment->introformat = FORMAT_HTML;
    $assignment->allowsubmissionsfromdate = $allowsubmissionsfromdate;
    $assignment->duedate = $duedate;
    $assignment->grade = $grade;
    $assignment->attemptreopenmethod = 'manual';
    $assignment->maxattempts = -1; // Unlimited attempts
    $assignment->gradingduedate = $gradingduedate;
    $assignment->gradingduedateenabled = $gradingduedate ? 1 : 0;
    $assignment->cutoffdate = $cutoffdate;
    $assignment->cutoffdateenabled = $cutoffdate ? 1 : 0;
    $assignment->submissiondrafts = 1;
    $assignment->requiresubmissionstatement = 0;
    $assignment->teamsubmission = 0;
    $assignment->requireallteammemberssubmit = 0;
    $assignment->teamsubmissiongroupingid = 0;
    $assignment->blindmarking = 0;
    $assignment->revealidentities = 0;
    $assignment->markingworkflow = 0;
    $assignment->markingallocation = 0;
    $assignment->sendnotifications = 1;
    $assignment->sendstudentnotifications = 1;
    $assignment->sendlatenotifications = 1;
    $assignment->duedateenabled = $duedate ? 1 : 0;
    $assignment->allowsubmissionsfromdateenabled = $allowsubmissionsfromdate ? 1 : 0;
    $assignment->timemodified = time();
    
    // Set completion settings for assignment
    if ($autocomplete === 'submission') {
        $assignment->completionsubmit = 1; // Mark complete when student submits
    } else if ($autocomplete === 'grading') {
        $assignment->completionsubmit = 0; // Mark complete when graded (handled by completionpass)
    } else {
        $assignment->completionsubmit = 0;
    }
    
    $assignmentid = $DB->insert_record('assign', $assignment);
    
    if (!$assignmentid) {
        throw new Exception('Failed to create assignment record');
    }
    
    // Configure submission plugins
    // Online text plugin
    if ($online_text) {
        $plugin = new stdClass();
        $plugin->assignment = $assignmentid;
        $plugin->plugin = 'onlinetext';
        $plugin->subtype = 'assignsubmission';
        $plugin->name = 'enabled';
        $plugin->value = '1';
        $DB->insert_record('assign_plugin_config', $plugin);
        
        // Set word limit (0 = no limit)
        $wordlimit = new stdClass();
        $wordlimit->assignment = $assignmentid;
        $wordlimit->plugin = 'onlinetext';
        $wordlimit->subtype = 'assignsubmission';
        $wordlimit->name = 'wordlimit';
        $wordlimit->value = '0'; // No word limit
        $DB->insert_record('assign_plugin_config', $wordlimit);
        
        // Enable word limit (0 = disabled)
        $wordlimitenabled = new stdClass();
        $wordlimitenabled->assignment = $assignmentid;
        $wordlimitenabled->plugin = 'onlinetext';
        $wordlimitenabled->subtype = 'assignsubmission';
        $wordlimitenabled->name = 'wordlimitenabled';
        $wordlimitenabled->value = '0'; // Disabled
        $DB->insert_record('assign_plugin_config', $wordlimitenabled);
        
        error_log("  Online text submission plugin enabled");
    }
    
    // File submission plugin
    if ($file_submissions) {
        // Enable the plugin
        $plugin = new stdClass();
        $plugin->assignment = $assignmentid;
        $plugin->plugin = 'file';
        $plugin->subtype = 'assignsubmission';
        $plugin->name = 'enabled';
        $plugin->value = '1';
        $DB->insert_record('assign_plugin_config', $plugin);
        
        // Set max files to 5 by default
        $config = new stdClass();
        $config->assignment = $assignmentid;
        $config->plugin = 'file';
        $config->subtype = 'assignsubmission';
        $config->name = 'maxfilesubmissions';
        $config->value = '5';
        $DB->insert_record('assign_plugin_config', $config);
        
        // Set max submission size from form
        $config2 = new stdClass();
        $config2->assignment = $assignmentid;
        $config2->plugin = 'file';
        $config2->subtype = 'assignsubmission';
        $config2->name = 'maxsubmissionsizebytes';
        $config2->value = (string)$max_upload_size;
        $DB->insert_record('assign_plugin_config', $config2);
        
        // CRITICAL: Set accepted file types (empty = all types accepted)
        $config3 = new stdClass();
        $config3->assignment = $assignmentid;
        $config3->plugin = 'file';
        $config3->subtype = 'assignsubmission';
        $config3->name = 'filetypes';
        $config3->value = ''; // Empty means all file types accepted
        $DB->insert_record('assign_plugin_config', $config3);
        
        error_log("  File submission plugin enabled (max files: 5, max size: " . ($max_upload_size / 1048576) . " MB, all file types)");
    }
    
    // Comments plugin (always enabled for feedback)
    $commentsPlugin = new stdClass();
    $commentsPlugin->assignment = $assignmentid;
    $commentsPlugin->plugin = 'comments';
    $commentsPlugin->subtype = 'assignsubmission';
    $commentsPlugin->name = 'enabled';
    $commentsPlugin->value = '1';
    $DB->insert_record('assign_plugin_config', $commentsPlugin);
    
    error_log("  Comments submission plugin enabled");
    
    // IMPORTANT: If neither online text nor file submissions are enabled, 
    // enable file submissions by default to prevent "no submission required" message
    if (!$online_text && !$file_submissions) {
        error_log("  WARNING: No submission types were selected. Enabling file submissions by default.");
        
        $plugin = new stdClass();
        $plugin->assignment = $assignmentid;
        $plugin->plugin = 'file';
        $plugin->subtype = 'assignsubmission';
        $plugin->name = 'enabled';
        $plugin->value = '1';
        $DB->insert_record('assign_plugin_config', $plugin);
        
        // Set max files to 5 by default
        $config = new stdClass();
        $config->assignment = $assignmentid;
        $config->plugin = 'file';
        $config->subtype = 'assignsubmission';
        $config->name = 'maxfilesubmissions';
        $config->value = '5';
        $DB->insert_record('assign_plugin_config', $config);
        
        // Set default max submission size to 50MB
        $config2 = new stdClass();
        $config2->assignment = $assignmentid;
        $config2->plugin = 'file';
        $config2->subtype = 'assignsubmission';
        $config2->name = 'maxsubmissionsizebytes';
        $config2->value = '52428800'; // 50MB
        $DB->insert_record('assign_plugin_config', $config2);
        
        // Set accepted file types
        $config3 = new stdClass();
        $config3->assignment = $assignmentid;
        $config3->plugin = 'file';
        $config3->subtype = 'assignsubmission';
        $config3->name = 'filetypes';
        $config3->value = '';
        $DB->insert_record('assign_plugin_config', $config3);
        
        error_log("  File submission plugin enabled (FALLBACK - max files: 5, max size: 50 MB)");
    }
    
    // Create course module
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'assign']);
    if (!$moduleid) {
        throw new Exception('Assignment module not found');
    }
    
    // Get the course section by ID (not section number!)
    // The form sends the database ID of the section from mdl_course_sections
    if (!$section) {
        throw new Exception('No section specified for assignment placement');
    }
    
    $coursesection = $DB->get_record('course_sections', [
        'id' => $section,
        'course' => $courseid
    ]);
    
    if (!$coursesection) {
        throw new Exception('Invalid section ID: ' . $section . ' for course: ' . $courseid);
    }
    
    error_log("  Using section - ID: " . $coursesection->id . ", Section Number: " . $coursesection->section . ", Name: " . ($coursesection->name ?: 'Unnamed') . ", Component: " . ($coursesection->component ?: 'NULL'));
    
    // Create course module record first
    error_log("  Creating course module record");
    
    $coursemodule = new stdClass();
    $coursemodule->course = $courseid;
    $coursemodule->module = $moduleid;
    $coursemodule->instance = $assignmentid;
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
    // Set completion settings based on autocomplete option
    if ($autocomplete === 'submission') {
        $coursemodule->completion = 2; // Completion enabled
        $coursemodule->completionview = 0;
        $coursemodule->completionexpected = 0;
    } else if ($autocomplete === 'grading') {
        $coursemodule->completion = 2; // Completion enabled
        $coursemodule->completionview = 0;
        $coursemodule->completionexpected = 0;
    } else {
        $coursemodule->completion = 0; // No completion tracking
        $coursemodule->completionview = 0;
        $coursemodule->completionexpected = 0;
    }
    $coursemodule->showdescription = 0;
    $coursemodule->availability = null;
    $coursemodule->deletioninprogress = 0;
    
    try {
        $coursemoduleid = $DB->insert_record('course_modules', $coursemodule);
        error_log("  Course module record created with ID: " . $coursemoduleid);
        
        // Now add it to the section using Moodle's API
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
        // This uses Moodle's availability system to hide the assignment from students not in the selected groups
        $availability_conditions = [];
        $showc_array = []; // Array of show/hide flags for each condition
        
        foreach ($group_ids as $groupid) {
            $availability_conditions[] = [
                'type' => 'group',
                'id' => (int)$groupid
            ];
            $showc_array[] = false; // Don't show to students who don't meet this condition
        }
        
        // If multiple groups, use OR operator (student in ANY of the selected groups)
        $availability = [
            'op' => '|', // OR operator
            'c' => $availability_conditions,
            'show' => false, // Hide if conditions not met (required field)
            'showc' => $showc_array // Show/hide flags for each condition
        ];
        
        $availability_json = json_encode($availability);
        error_log("  Availability JSON: " . $availability_json);
        
        // Update course module with availability restriction
        $DB->set_field('course_modules', 'availability', $availability_json, ['id' => $coursemoduleid]);
        
        error_log("  Successfully set group-based availability for " . count($group_ids) . " groups");
    } else {
        error_log("  Assignment visible to all students (no group restrictions)");
    }
    
    // Grade item will be created automatically by Moodle's assignment module
    // via grade_update() when the assignment is first graded
    // We just need to ensure the assignment has the correct grade value
    grade_update('mod/assign', $courseid, 'mod', 'assign', $assignmentid, 0, null, array('itemname' => $name));
    
    // Create context
    $modulecontext = context_module::instance($coursemoduleid);
    
    // Create grading area and definition if rubric is selected
    if ($grading_method === 'rubric') {
        error_log("  Creating grading area for rubric method");
        
        // Create grading area
        $gradingarea = new stdClass();
        $gradingarea->contextid = $modulecontext->id;
        $gradingarea->component = 'mod_assign';
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
        $gradingdefinition->status = 20; // READY_FOR_USE status (20 = ready, 10 = draft)
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
                error_log("    Criterion $criterion_counter created: ID=$criterionid, Description='$criterion_description'");
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
    
    // Link competencies to the assignment module
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
    
    // Purge all caches to make the new assignment immediately visible to students
    // This is crucial so students don't need to wait for cache expiry
    cache_helper::purge_by_event('changesincourse');
    cache_helper::purge_by_event('changesincoursecat');
    
    // Purge the course modinfo cache using get_fast_modinfo to safely reset it
    // This ensures students see the new assignment immediately
    get_fast_modinfo($courseid, 0, true); // Force reset of modinfo cache
    
    // Purge completion cache if autocomplete is enabled
    if (isset($autocomplete) && $autocomplete !== 'none') {
        cache_helper::purge_by_event('changesincoursecompletion');
    }
    
    error_log("  Cache purged - assignment should be immediately visible to students");
    
    // Commit transaction
    $transaction->allow_commit();
    
    // Trigger events
    $event = \core\event\course_module_created::create([
        'objectid' => $coursemoduleid,
        'context' => $modulecontext,
        'other' => [
            'modulename' => 'assign',
            'instanceid' => $assignmentid,
            'name' => $name
        ]
    ]);
    $event->add_record_snapshot('course_modules', $coursemodule);
    $event->add_record_snapshot('assign', $assignment);
    $event->trigger();
    
    echo json_encode([
        'success' => true,
        'message' => 'Assignment created successfully',
        'assignment_id' => $assignmentid,
        'course_module_id' => $coursemoduleid,
        'competencies_linked' => isset($competencies) ? count($competencies) : 0,
        'url' => $CFG->wwwroot . '/mod/assign/view.php?id=' . $coursemoduleid
    ]);
    
} catch (Exception $e) {
    if (isset($transaction) && $transaction) {
        $transaction->rollback($e);
    }
    
    error_log("Assignment creation error: " . $e->getMessage());
    error_log("Assignment creation error trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Error creating assignment: ' . $e->getMessage()
    ]);
}
?>