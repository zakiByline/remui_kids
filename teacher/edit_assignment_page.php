<?php
/**
 * Edit Assignment Page
 * Load and edit existing assignment data
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access assignment edit page');
}

// Get parameters
$cmid = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Get course module and assignment
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$assignment = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Verify access
$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

// Get assignment configuration
$onlinetext_enabled = $DB->record_exists('assign_plugin_config', [
    'assignment' => $assignment->id,
    'plugin' => 'onlinetext',
    'subtype' => 'assignsubmission',
    'name' => 'enabled',
    'value' => '1'
]);

$file_enabled = $DB->record_exists('assign_plugin_config', [
    'assignment' => $assignment->id,
    'plugin' => 'file',
    'subtype' => 'assignsubmission',
    'name' => 'enabled',
    'value' => '1'
]);

$max_upload_size = $DB->get_field('assign_plugin_config', 'value', [
    'assignment' => $assignment->id,
    'plugin' => 'file',
    'subtype' => 'assignsubmission',
    'name' => 'maxfilesubmissions'
]);

// Get grading method
$modulecontext = context_module::instance($cmid);
$gradingarea = $DB->get_record('grading_areas', [
    'contextid' => $modulecontext->id,
    'component' => 'mod_assign',
    'areaname' => 'submissions'
]);

$grading_method = 'simple';
if ($gradingarea) {
    $grading_method = $gradingarea->activemethod;
}

// Get rubric criteria if using rubric
$rubric_criteria = [];
if ($grading_method === 'rubric' && $gradingarea) {
    $gradingdefinition = $DB->get_record('grading_definitions', ['areaid' => $gradingarea->id]);
    if ($gradingdefinition) {
        // Get criteria with levels
        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $gradingdefinition->id], 'sortorder ASC');
        foreach ($criteria as $criterion) {
            $levels = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $criterion->id], 'score ASC');
            $rubric_criteria[] = [
                'id' => $criterion->id,
                'description' => $criterion->description,
                'levels' => array_values($levels)
            ];
        }
    }
}

// Get linked competencies
$linked_competencies = $DB->get_records('competency_modulecomp', ['cmid' => $cmid]);
$competency_ids = array_column(array_values($linked_competencies), 'competencyid');

// Get completion action (assuming same for all competencies)
$completion_action = 0;
if (!empty($linked_competencies)) {
    $first_comp = reset($linked_competencies);
    $completion_action = $first_comp->ruleoutcome;
}

// Get groups assigned to this assignment
$assigned_groups = [];
if (!empty($cm->availability)) {
    $availability = json_decode($cm->availability, true);
    if (isset($availability['c']) && is_array($availability['c'])) {
        foreach ($availability['c'] as $condition) {
            if (isset($condition['type']) && $condition['type'] === 'group') {
                $assigned_groups[] = $condition['id'];
            }
        }
    }
}

// Get section info
$section = $DB->get_record('course_sections', ['id' => $cm->section]);

// Extract activity instructions (if stored in intro)
$activityinstructions = '';
$cleanintro = $assignment->intro;
if (preg_match('/(<br\s*\/?>\s*){0,2}<h3>Activity Instructions<\/h3>(.*)$/is', $assignment->intro, $matches)) {
    $instructionsHtml = $matches[2];
    $cleanintro = preg_replace('/(<br\s*\/?>\s*){0,2}<h3>Activity Instructions<\/h3>.*$/is', '', $assignment->intro);
    $instructionsText = preg_replace('/<br\s*\/?>/i', "\n", $instructionsHtml);
    $activityinstructions = trim(html_entity_decode(strip_tags($instructionsText)));
}

// Store data for JavaScript to access
$edit_mode_data = [
    'id' => $assignment->id,
    'cmid' => $cmid,
    'courseid' => $courseid,
    'name' => $assignment->name,
    'intro' => $cleanintro,
    'activityinstructions' => $activityinstructions,
    'allowsubmissionsfromdate' => $assignment->allowsubmissionsfromdate,
    'duedate' => $assignment->duedate,
    'cutoffdate' => $assignment->cutoffdate,
    'gradingduedate' => $assignment->gradingduedate,
    'grade' => $assignment->grade,
    'onlinetext_enabled' => $onlinetext_enabled,
    'file_enabled' => $file_enabled,
    'max_upload_size' => $max_upload_size ?: 52428800,
    'grading_method' => $grading_method,
    'completion' => $cm->completion,
    'completionsubmit' => $assignment->completionsubmit,
    'sectionid' => $cm->section,
    'sectionname' => $section->name,
    'competencies' => $competency_ids,
    'competency_completion_action' => $completion_action,
    'assigned_groups' => $assigned_groups,
    'rubric_criteria' => $rubric_criteria
];

// Set a global variable that create_assignment_page.php can access
$GLOBALS['edit_mode'] = true;
$GLOBALS['edit_mode_data'] = $edit_mode_data;

// Include the create_assignment_page.php - it will handle header and footer
// This must be the last thing we do - it outputs the full page
include(__DIR__ . '/create_assignment_page.php');

// Everything after this point will be injected into the page before </body>
?>
<script>
// Override the form for editing mode
document.addEventListener('DOMContentLoaded', function() {
    // Update page title
    document.querySelector('.page-title-header').textContent = 'Edit Assignment';
    document.querySelector('.page-subtitle').textContent = 'Update assignment details and settings';
    
    // Update back button
    document.querySelector('.back-button').href = 'assignments.php';
    
    // Update button text
    const buttonsToUpdate = ['createBtn', 'createBtnFinal'];
    buttonsToUpdate.forEach(btnId => {
        const btn = document.getElementById(btnId);
        if (btn) {
            btn.innerHTML = '<i class="fa fa-save"></i> Update Assignment';
            btn.onclick = function() { updateAssignment(this); };
        }
    });
    
    // Add assignment ID to form
    const form = document.getElementById('assignmentForm');
    form.action = 'update_assignment.php'; // Change to update handler
    
    const assignmentIdInput = document.createElement('input');
    assignmentIdInput.type = 'hidden';
    assignmentIdInput.name = 'assignment_id';
    assignmentIdInput.value = window.assignmentData.id;
    form.appendChild(assignmentIdInput);
    
    const cmidInput = document.createElement('input');
    cmidInput.type = 'hidden';
    cmidInput.name = 'cmid';
    cmidInput.value = window.assignmentData.cmid;
    form.appendChild(cmidInput);
    
    // Pre-fill form fields
    setTimeout(function() {
        prefillAssignmentData();
    }, 200);
});

// Update assignment function
function updateAssignment(triggerBtn = null) {
    const form = document.getElementById('assignmentForm');
    const formData = new FormData(form);
    const updateBtn = triggerBtn || document.getElementById('createBtn') || document.getElementById('createBtnFinal');
    
    // Validate
    if (!formData.get('name')) {
        showToast('Please enter an assignment name.', 'error');
        return;
    }
    
    // Show loading
    const originalText = updateBtn.innerHTML;
    updateBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating...';
    updateBtn.disabled = true;
    
    // Submit form
    fetch('update_assignment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Assignment updated successfully!', 'success');
            setTimeout(() => {
                window.location.href = 'assignments.php';
            }, 1500);
        } else {
            showToast('Error updating assignment: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error updating assignment. Please try again.', 'error');
    })
    .finally(() => {
        updateBtn.innerHTML = originalText;
        updateBtn.disabled = false;
    });
}

function prefillAssignmentData() {
    const data = window.assignmentData;
    
    // Basic fields
    document.getElementById('assignmentName').value = data.name;
    document.getElementById('assignmentDescription').value = data.intro.replace(/<[^>]*>/g, ''); // Strip HTML
    document.getElementById('assignmentCourse').value = data.courseid;
    if (data.activityinstructions) {
        document.getElementById('activityInstructions').value = data.activityinstructions;
    }
    
    // Trigger course structure load
    loadCourseStructure();
    loadCompetencies(data.courseid);
    loadCourseGroups(data.courseid);
    
    // Set section selection (wait for structure to load)
    setTimeout(function() {
        document.getElementById('selectedSection').value = data.sectionid;
        // Select the tree item visually
        const treeItem = document.querySelector(`[data-id="${data.sectionid}"]`);
        if (treeItem) {
            selectTreeItem(treeItem);
        }
    }, 1000);
    
    // Dates
    if (data.allowsubmissionsfromdate > 0) {
        document.getElementById('enableAllowFrom').checked = true;
        toggleDateTime('allowFrom');
        setDateTimeFromTimestamp('allow_from', data.allowsubmissionsfromdate);
    }
    
    if (data.duedate > 0) {
        document.getElementById('enableDueDate').checked = true;
        toggleDateTime('dueDate');
        setDateTimeFromTimestamp('due', data.duedate);
    }
    
    if (data.cutoffdate > 0) {
        document.getElementById('enableCutoff').checked = true;
        toggleDateTime('cutoff');
        setDateTimeFromTimestamp('cutoff', data.cutoffdate);
    }
    
    if (data.gradingduedate > 0) {
        document.getElementById('enableReminder').checked = true;
        toggleDateTime('reminder');
        setDateTimeFromTimestamp('reminder', data.gradingduedate);
    }
    
    // Submission types
    document.getElementById('onlineText').checked = data.onlinetext_enabled;
    document.getElementById('fileSubmissions').checked = data.file_enabled;
    document.getElementById('maxUploadSize').value = data.max_upload_size;
    
    // Auto complete
    if (data.completionsubmit == 1) {
        document.getElementById('autocompleteSubmission').checked = true;
    } else if (data.completion == 2) {
        document.getElementById('autocompleteGrading').checked = true;
    }
    
    // Grade and grading method
    document.getElementById('maxGrade').value = data.grade;
    document.getElementById('gradingMethod').value = data.grading_method;
    toggleRubricBuilder();
    
    // Load rubric data if exists
    if (data.grading_method === 'rubric' && data.rubric_criteria.length > 0) {
        rubricData = data.rubric_criteria.map((c, idx) => ({
            id: idx + 1,
            description: c.description,
            levels: c.levels.map(l => ({
                score: parseFloat(l.score),
                definition: l.definition || ''
            }))
        }));
        criterionCounter = rubricData.length;
        renderRubricTable();
    }
    
    // Competencies (wait for them to load)
    setTimeout(function() {
        data.competencies.forEach(compId => {
            const checkbox = document.querySelector(`input[name="competencies[]"][value="${compId}"]`);
            if (checkbox) {
                checkbox.checked = true;
                checkbox.closest('.competency-item').classList.add('selected');
            }
        });
        document.getElementById('competencyCompletionAction').value = data.competency_completion_action;
    }, 1500);
    
    // Groups
    const assignTo = data.assigned_groups.length > 0 ? 'groups' : 'all';
    if (assignTo === 'groups') {
        document.getElementById('assignToGroups').checked = true;
        toggleGroupSelection();
        
        // Wait for groups to load
        setTimeout(function() {
            data.assigned_groups.forEach(groupId => {
                const checkbox = document.querySelector(`input[name="group_ids[]"][value="${groupId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    checkbox.closest('.group-item').classList.add('selected');
                }
            });
        }, 1500);
    } else {
        document.getElementById('assignToAll').checked = true;
    }
}

// Helper function to set date/time from Unix timestamp
function setDateTimeFromTimestamp(prefix, timestamp) {
    const date = new Date(timestamp * 1000);
    setDefaultDateTime(prefix, date);
}

// Flag to indicate we're in edit mode (for create_assignment_page.php to know)
window.isEditMode = true;
</script>

