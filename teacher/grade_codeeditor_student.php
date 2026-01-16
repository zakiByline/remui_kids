<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once($CFG->libdir . '/gradelib.php');

// Get parameters first to get proper context
$codeeditorid = required_param('codeeditorid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$studentid = required_param('studentid', PARAM_INT);

// Get code editor, course, and student details
$codeeditor = $DB->get_record('codeeditor', ['id' => $codeeditorid], '*', MUST_EXIST);
$course = get_course($courseid);
$student = $DB->get_record('user', ['id' => $studentid], '*', MUST_EXIST);

// Get course module and proper context
$cm = get_coursemodule_from_instance('codeeditor', $codeeditorid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);

// Security checks with proper context
require_login($course);
require_capability('mod/codeeditor:grade', $context);

$codeeditor_intro = '';
if (!empty($codeeditor->intro)) {
    $codeeditor_intro = format_text($codeeditor->intro, $codeeditor->introformat, ['context' => $context, 'noclean' => true, 'overflowdiv' => true]);
}

// Get rubric for this code editor activity
$rubric_data = theme_remui_kids_get_rubric_by_cmid($cm->id);

// Get all students for this code editor (for dropdown)
$all_students = theme_remui_kids_get_assignment_students($codeeditorid, $courseid, 'codeeditor');

// Get student's submission
$submission = $DB->get_record('codeeditor_submissions', 
    ['codeeditorid' => $codeeditorid, 'userid' => $studentid, 'latest' => 1]);

// Check if student has already been graded
$already_graded = ($submission && $submission->grade !== null && $submission->grade >= 0);

// Get existing rubric fillings if already graded
$existing_fillings = [];
$existing_feedback = '';
$total_score = 0;
$max_score = 0;
if ($already_graded && $submission && $submission->grade >= 0) {
    // For Code Editor, we need to check if there's a grading instance
    // The grade might be stored directly in codeeditor_submissions, but rubric fillings would be in grading tables
    // Check if there's a grading instance linked to this submission
    $grading_instance = $DB->get_record_sql(
        "SELECT gi.* FROM {grading_instances} gi
         JOIN {codeeditor_submissions} cs ON cs.id = gi.itemid
         WHERE cs.codeeditorid = ? AND cs.userid = ? AND cs.latest = 1
         ORDER BY gi.timemodified DESC
         LIMIT 1",
        [$codeeditorid, $studentid]
    );
    
    if ($grading_instance) {
        // Get all rubric fillings for this grading instance
        $fillings = $DB->get_records('gradingform_rubric_fillings', 
            ['instanceid' => $grading_instance->id]
        );
        
        // Organize fillings by criterion ID for easy lookup
        foreach ($fillings as $filling) {
            $existing_fillings[$filling->criterionid] = [
                'levelid' => $filling->levelid,
                'remark' => $filling->remark
            ];
            
            // Get the score for this level
            $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
            if ($level) {
                $total_score += $level->score;
            }
        }
        
        error_log("Existing Fillings Debug: Found " . count($existing_fillings) . " criterion fillings for submission ID " . $submission->id);
    }
    
    // Calculate max score from rubric
    if ($rubric_data) {
        foreach ($rubric_data['criteria'] as $criterion) {
            $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
            $max_score += max($scores);
        }
    }
    
    // Get existing overall feedback from submission
    if ($submission && !empty($submission->feedbacktext)) {
        $existing_feedback = $submission->feedbacktext;
        error_log("Existing Feedback Debug: Found feedback in submission ID " . $submission->id);
    }
}

// Process grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    try {
        // Get rubric data
        $rubric_data = theme_remui_kids_get_rubric_by_cmid($cm->id);
        
        if ($rubric_data) {
            error_log("Rubric Data Debug: rubric_id = " . $rubric_data['rubric_id']);
            error_log("Rubric Data Debug: criteria count = " . count($rubric_data['criteria']));
            
            $total_score = 0;
            $grading_data = [];
            
            // Process each criterion and collect scores
            foreach ($rubric_data['criteria'] as $criterion) {
                $criterion_id = $criterion['id'];
                $selected_level = optional_param('criterion_' . $criterion_id, null, PARAM_INT);
                $comment = optional_param('comment_' . $criterion_id, '', PARAM_TEXT);
                
                if ($selected_level) {
                    // Find the selected level to get its score
                    foreach ($criterion['levels'] as $level) {
                        if ($level->id == $selected_level) {
                            $total_score += $level->score;
                            $grading_data[] = [
                                'criterionid' => $criterion_id,
                                'levelid' => $selected_level,
                                'score' => $level->score,
                                'comment' => $comment
                            ];
                            break;
                        }
                    }
                }
            }
            
            // Calculate percentage based on rubric scores
            $percentage = theme_remui_kids_calculate_rubric_grade($rubric_data, $grading_data, $codeeditor);
            error_log("Rubric Calculation Debug: calculated percentage = $percentage");
            error_log("Grading Data Debug: " . json_encode($grading_data));
            
            // Ensure submission exists
            if (!$submission) {
                // Create a new submission record if it doesn't exist
                $submission = new stdClass();
                $submission->codeeditorid = $codeeditorid;
                $submission->userid = $studentid;
                $submission->code = '';
                $submission->language = '';
                $submission->output = '';
                $submission->status = 'submitted';
                $submission->attemptnumber = 0;
                $submission->latest = 1;
                $submission->timecreated = time();
                $submission->timemodified = time();
                $submission->id = $DB->insert_record('codeeditor_submissions', $submission);
                error_log("Created new submission record ID = " . $submission->id);
            }
            
            // Check if already graded - handle update vs create
            if ($already_graded && $submission) {
                // UPDATE EXISTING GRADE
                error_log("Update Mode: Updating existing grade for student $studentid");
                
                // Update the submission record
                $submission->grade = $percentage;
                $submission->grader = $USER->id;
                $submission->timegraded = time();
                $submission->timemodified = time();
                $DB->update_record('codeeditor_submissions', $submission);
                error_log("Submission Update Debug: Updated submission ID = {$submission->id} with new percentage = $percentage");
                
                // Get existing grading instance
                $grading_instance = $DB->get_record_sql(
                    "SELECT gi.* FROM {grading_instances} gi
                     WHERE gi.itemid = ?
                     ORDER BY gi.timemodified DESC
                     LIMIT 1",
                    [$submission->id]
                );
                
                if ($grading_instance) {
                    // Delete existing rubric fillings
                    $DB->delete_records('gradingform_rubric_fillings', 
                        ['instanceid' => $grading_instance->id]
                    );
                    error_log("Rubric Update Debug: Deleted existing fillings for instance = {$grading_instance->id}");
                    
                    // Update grading instance
                    $grading_instance->rawgrade = $percentage;
                    $grading_instance->timemodified = time();
                    $DB->update_record('grading_instances', $grading_instance);
                    error_log("Grading Instance Update Debug: Updated instance ID = {$grading_instance->id}");
                    
                    // Insert new rubric fillings
                    foreach ($grading_data as $criterion_data) {
                        $criterion_filling = new stdClass();
                        $criterion_filling->instanceid = $grading_instance->id;
                        $criterion_filling->criterionid = $criterion_data['criterionid'];
                        $criterion_filling->levelid = $criterion_data['levelid'];
                        $criterion_filling->remark = $criterion_data['comment'];
                        $criterion_filling->remarkformat = FORMAT_HTML;
                        
                        try {
                            $criterion_filling_id = $DB->insert_record('gradingform_rubric_fillings', $criterion_filling);
                            error_log("Criterion Filling Update Debug: Created new filling ID = $criterion_filling_id for criterion " . $criterion_data['criterionid']);
                        } catch (Exception $e) {
                            error_log("Criterion Filling Update Error: Failed to create filling for criterion " . $criterion_data['criterionid'] . " - " . $e->getMessage());
                        }
                    }
                } else {
                    // Create new grading instance for update
                    $grading_instance = new stdClass();
                    $grading_instance->definitionid = $rubric_data['rubric_id'];
                    $grading_instance->raterid = $USER->id;
                    $grading_instance->itemid = $submission->id;
                    $grading_instance->rawgrade = $percentage;
                    $grading_instance->status = 1;
                    $grading_instance->feedback = '';
                    $grading_instance->feedbackformat = FORMAT_HTML;
                    $grading_instance->timemodified = time();
                    $grading_instance_id = $DB->insert_record('grading_instances', $grading_instance);
                    error_log("Created new grading instance ID = $grading_instance_id for update");
                    
                    // Insert rubric fillings
                    foreach ($grading_data as $criterion_data) {
                        $criterion_filling = new stdClass();
                        $criterion_filling->instanceid = $grading_instance_id;
                        $criterion_filling->criterionid = $criterion_data['criterionid'];
                        $criterion_filling->levelid = $criterion_data['levelid'];
                        $criterion_filling->remark = $criterion_data['comment'];
                        $criterion_filling->remarkformat = FORMAT_HTML;
                        $DB->insert_record('gradingform_rubric_fillings', $criterion_filling);
                    }
                }
                
                // Update overall feedback
                $overall_feedback = optional_param('overall_feedback', '', PARAM_TEXT);
                if (!empty($overall_feedback)) {
                    $submission->feedbacktext = $overall_feedback;
                    $submission->feedbackformat = FORMAT_HTML;
                    $DB->update_record('codeeditor_submissions', $submission);
                    error_log("Feedback Update Debug: Updated feedback in submission ID = {$submission->id}");
                }
                
            } else {
                // CREATE NEW GRADE (first-time grading)
                error_log("Create Mode: Creating new grade for student $studentid");
                
                // Update submission with grade
                $submission->grade = $percentage;
                $submission->grader = $USER->id;
                $submission->timegraded = time();
                $submission->timemodified = time();
                $DB->update_record('codeeditor_submissions', $submission);
                error_log("Submission Create Debug: Updated submission ID = {$submission->id} with percentage = $percentage");
                
                // Create new grading instance
                $grading_instance = new stdClass();
                $grading_instance->definitionid = $rubric_data['rubric_id'];
                $grading_instance->raterid = $USER->id;
                $grading_instance->itemid = $submission->id;
                $grading_instance->rawgrade = $percentage;
                $grading_instance->status = 1;
                $grading_instance->feedback = '';
                $grading_instance->feedbackformat = FORMAT_HTML;
                $grading_instance->timemodified = time();
                $grading_instance_id = $DB->insert_record('grading_instances', $grading_instance);
                error_log("Rubric Storage Debug: Created new grading instance ID = $grading_instance_id for submission_id = {$submission->id}");
                
                // Save each criterion filling
                foreach ($grading_data as $criterion_data) {
                    $criterion_filling = new stdClass();
                    $criterion_filling->instanceid = $grading_instance_id;
                    $criterion_filling->criterionid = $criterion_data['criterionid'];
                    $criterion_filling->levelid = $criterion_data['levelid'];
                    $criterion_filling->remark = $criterion_data['comment'];
                    $criterion_filling->remarkformat = FORMAT_HTML;
                    
                    try {
                        $criterion_filling_id = $DB->insert_record('gradingform_rubric_fillings', $criterion_filling);
                        error_log("Criterion Filling Debug: Created criterion filling ID = $criterion_filling_id for criterion " . $criterion_data['criterionid']);
                    } catch (Exception $e) {
                        error_log("Criterion Filling Error: Failed to create criterion filling for criterion " . $criterion_data['criterionid'] . " - " . $e->getMessage());
                    }
                }
                
                // Save overall feedback
                $overall_feedback = optional_param('overall_feedback', '', PARAM_TEXT);
                if (!empty($overall_feedback)) {
                    $submission->feedbacktext = $overall_feedback;
                    $submission->feedbackformat = FORMAT_HTML;
                    $DB->update_record('codeeditor_submissions', $submission);
                    error_log("Overall Feedback Debug: Updated feedback in submission ID = {$submission->id}");
                }
            }
            
            // Update gradebook
            try {
                // Get the grade item for this code editor activity
                $grade_item = $DB->get_record('grade_items', [
                    'itemtype' => 'mod',
                    'itemmodule' => 'codeeditor', 
                    'iteminstance' => $codeeditorid,
                    'courseid' => $courseid
                ]);
                
                if ($grade_item) {
                    // Check if grade already exists in grade_grades
                    $existing_grade_grade = $DB->get_record('grade_grades', [
                        'itemid' => $grade_item->id,
                        'userid' => $studentid
                    ]);
                    
                    if ($existing_grade_grade) {
                        // Update existing grade_grades record
                        $existing_grade_grade->finalgrade = $percentage;
                        $existing_grade_grade->rawgrade = $percentage;
                        $existing_grade_grade->timemodified = time();
                        $existing_grade_grade->usermodified = $USER->id;
                        $DB->update_record('grade_grades', $existing_grade_grade);
                        error_log("Gradebook Update Debug: Updated existing grade_grades record - finalgrade=$percentage");
                    } else {
                        // Create new grade_grades record
                        $new_grade_grade = new stdClass();
                        $new_grade_grade->itemid = $grade_item->id;
                        $new_grade_grade->userid = $studentid;
                        $new_grade_grade->finalgrade = $percentage;
                        $new_grade_grade->rawgrade = $percentage;
                        $new_grade_grade->rawgrademax = $codeeditor->grade;
                        $new_grade_grade->rawgrademin = 0;
                        $new_grade_grade->timecreated = time();
                        $new_grade_grade->timemodified = time();
                        $new_grade_grade->usermodified = $USER->id;
                        $new_grade_grade->hidden = 0;
                        $new_grade_grade->locked = 0;
                        $new_grade_grade->exported = 0;
                        $new_grade_grade->overridden = 0;
                        $new_grade_grade->excluded = 0;
                        $new_grade_grade->feedback = '';
                        $new_grade_grade->feedbackformat = FORMAT_HTML;
                        $new_grade_grade->information = '';
                        $new_grade_grade->informationformat = FORMAT_HTML;
                        
                        $grade_grade_id = $DB->insert_record('grade_grades', $new_grade_grade);
                        error_log("Gradebook Update Debug: Created new grade_grades record ID = $grade_grade_id - finalgrade=$percentage");
                    }
                } else {
                    error_log("Gradebook Update Error: No grade item found for codeeditor $codeeditorid");
                }
                
            } catch (Exception $e) {
                error_log("Gradebook Update Error: " . $e->getMessage());
            }
            
            // Refresh submission data after saving
            $submission = $DB->get_record('codeeditor_submissions', 
                ['codeeditorid' => $codeeditorid, 'userid' => $studentid, 'latest' => 1]);
            
            // Update the existing fillings and feedback for display
            $existing_fillings = [];
            $existing_feedback = '';
            $total_score = 0;
            $max_score = 0;
            
            if ($submission && $submission->grade >= 0) {
                // Get the grading instance for this submission
                $grading_instance = $DB->get_record_sql(
                    "SELECT gi.* FROM {grading_instances} gi
                     WHERE gi.itemid = ?
                     ORDER BY gi.timemodified DESC
                     LIMIT 1",
                    [$submission->id]
                );
                
                if ($grading_instance) {
                    // Get all rubric fillings for this grading instance
                    $fillings = $DB->get_records('gradingform_rubric_fillings', 
                        ['instanceid' => $grading_instance->id]
                    );
                    
                    // Organize fillings by criterion ID for easy lookup
                    foreach ($fillings as $filling) {
                        $existing_fillings[$filling->criterionid] = [
                            'levelid' => $filling->levelid,
                            'remark' => $filling->remark
                        ];
                        
                        // Get the score for this level
                        $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
                        if ($level) {
                            $total_score += $level->score;
                        }
                    }
                }
                
                // Calculate max score from rubric
                if ($rubric_data) {
                    foreach ($rubric_data['criteria'] as $criterion) {
                        $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
                        $max_score += max($scores);
                    }
                }
                
                // Get existing overall feedback
                if (!empty($submission->feedbacktext)) {
                    $existing_feedback = $submission->feedbacktext;
                }
            }
            
            $already_graded = ($submission && $submission->grade >= 0);
            
            // Redirect to prevent resubmission
            $redirect_url = new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', [
                'assignmentid' => $codeeditorid,
                'courseid' => $courseid,
                'activitytype' => 'codeeditor'
            ]);
            $success_message = $already_graded ? 'Grade updated successfully!' : 'Grade saved successfully!';
            redirect($redirect_url, $success_message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (Exception $e) {
        error_log('Error saving grade: ' . $e->getMessage());
        // Continue to show the form with error
    }
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/grade_codeeditor_student.php', [
    'codeeditorid' => $codeeditorid, 
    'courseid' => $courseid,
    'studentid' => $studentid
]);
$PAGE->set_pagelayout('popup');
$PAGE->set_title('Grading: ' . fullname($student));
$PAGE->navbar->add('Rubrics', new moodle_url('/theme/remui_kids/teacher/rubrics.php'));
$PAGE->navbar->add('Grading', new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', [
    'assignmentid' => $codeeditorid, 
    'courseid' => $courseid,
    'activitytype' => 'codeeditor'
]));
$PAGE->navbar->add(fullname($student));

echo $OUTPUT->header();
?>

<style>
/* Neutralize the default main container */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

.teacher-css-wrapper {
    display: flex;
    min-height: 100vh;
    background-color: #f0f2f5;
}

.teacher-dashboard-wrapper {
    display: flex;
    flex-grow: 1;
}

.teacher-main-content {
    flex-grow: 1;
    padding: 20px;
    background-color: #f0f2f5;
}

.grading-page-spacer {
    width: 100%;
    margin-bottom: 30px;
}

.assignment-meta {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.assignment-meta-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
}
.assignment-meta-description {
    background-color: #f0f2f5;
}

.assignment-meta-type {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #6b7280;
}

.assignment-meta-title {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1.3;
}

.assignment-meta-summary {
    margin-top: 8px;
    font-size: 15px;
    color: #4b5563;
}

.assignment-meta-description {
    font-size: 15px;
    line-height: 1.6;
    color: #4b5563;
    margin-top: 16px;
    background-color: #fff;
    border-radius: 12px;
    padding: 20px;
}

.assignment-meta-description.empty {
    font-style: italic;
    color: #9ca3af;
}

.grading-page-wrapper {
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    padding: 20px;
}

.grading-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
    gap: 20px;
}

/* Title Section (Left) */
.grading-title-section {
    flex-grow: 1;
    text-align: left;
    margin-left: 20px;
}

.grading-page-title {
    font-size: 24px;
    font-weight: 700;
    color: #333;
    margin: 0;
}

.grading-page-subtitle {
    font-size: 14px;
    color: #666;
    margin: 0;
}

/* Back Button (Left) */
.back-button {
    background-color: #6c757d;
    color: white;
    padding: 10px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    white-space: nowrap;
    min-width: 140px;
    justify-content: center;
}

.back-button:hover {
    background-color: #5a6268;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.grading-content {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-top: 24px;
}

/* Student Header Card */
.student-header-card {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e5e7eb;
    margin-bottom: 0;
}

.student-header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    flex-wrap: wrap;
}

.student-info-section {
    display: flex;
    align-items: center;
    gap: 24px;
    flex: 1;
    min-width: 300px;
}

.student-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%);
    color: #065f46;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    box-shadow: 0 0 0 3px #f0fdf4;
    flex-shrink: 0;
}

.student-details {
    flex: 1;
}

.student-details h2 {
    margin: 0 0 12px 0;
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1.2;
}

.student-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.student-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.student-meta-item i {
    color: #9ca3af;
}

.grade-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #dcfce7;
    border: 2px solid #86efac;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    color: #065f46;
    box-shadow: none;
}

.grade-badge i {
    color: #10b981;
    font-size: 18px;
}

.status-navigation-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
    align-items: flex-end;
}

.status-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.section-title {
    font-size: 16px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 12px;
    color: #6b7280;
}

.rubric-section {
    background-color: #ffffff;
    border-radius: 16px;
    padding: 30px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
}

.submission-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: none;
}

.submission-status.submitted {
    background: #dcfce7;
    border: 2px solid #86efac;
    color: #065f46;
}

.submission-status.not-submitted {
    background: #fee2e2;
    border: 2px solid #fca5a5;
    color: #991b1b;
}

.submission-status.draft {
    background: #dbeafe;
    border: 2px solid #93c5fd;
    color: #1e40af;
}

.graded-indicator {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #dcfce7;
    border: 2px solid #86efac;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    color: #065f46;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: none;
}

.submission-date {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
}

.submission-date i {
    color: #9ca3af;
}

.code-preview {
    background: #0f172a;
    color: #e2e8f0;
    padding: 30px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 15px;
    line-height: 1.8;
    overflow-x: auto;
    min-height: 250px;
    max-height: 600px;
    overflow-y: auto;
    margin: 0;
}

.code-preview pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: #e2e8f0;
}

.output-preview {
    background: #0f172a;
    color: #22d3ee;
    padding: 30px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 15px;
    line-height: 1.8;
    overflow-x: auto;
    min-height: 180px;
    max-height: 400px;
    overflow-y: auto;
    margin: 0;
}

.output-preview pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: #22d3ee;
}

.no-output-message {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    padding: 30px;
    border-radius: 12px;
    text-align: center;
    margin: 20px;
}

.no-output-message i {
    color: #d97706;
    font-size: 32px;
    margin-bottom: 12px;
    display: block;
}

.no-output-message span {
    color: #92400e;
    font-size: 15px;
    font-weight: 500;
}

.code-section, .output-section {
    background: white;
    padding: 0;
    border-radius: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.section-header {
    margin: 0;
    padding: 24px 30px;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    background: #f8fafb;
    border-bottom: 1px solid #e5e7eb;
    position: relative;
}

.section-header.code-header {
    color: #047857;
    background: #ecfdf5;
    border-bottom-color: #a7f3d0;
}

.section-header.output-header {
    color: #0e7490;
    background: #f0fdfa;
    border-bottom-color: #99f6e4;
}

.section-header i {
    font-size: 20px;
}

.language-badge {
    position: absolute;
    right: 30px;
    font-size: 10px;
    background: white;
    color: #047857;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 2px solid #047857;
    box-shadow: none;
}

.student-navigation {
    display: flex;
    align-items: center;
}

.nav-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
}

.nav-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border: 2px solid #d1d5db;
    border-radius: 10px;
    background: white;
    color: #374151;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.nav-btn:hover:not(.disabled) {
    background: #f9fafb;
    border-color: #9ca3af;
}

.nav-btn.disabled {
    background: #f3f4f6;
    border-color: #e5e7eb;
    color: #9ca3af;
    cursor: not-allowed;
}

.nav-info {
    font-size: 13px;
    font-weight: 700;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 12px;
}

.no-submission {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.no-submission i {
    font-size: 48px;
    color: #ccc;
    margin-bottom: 15px;
}

.no-submission h3 {
    margin: 0 0 10px 0;
    color: #555;
}

.no-submission p {
    margin: 0;
    font-size: 14px;
}

.rubric-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.rubric-table th,
.rubric-table td {
    border: 1px solid #dee2e6;
    padding: 12px;
    text-align: left;
}

.rubric-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.rubric-table tbody tr:hover {
    background-color: #f8f9fa;
}

.criterion-row {
    background-color: #ffffff;
}

.level-option {
    text-align: center;
    cursor: pointer;
    transition: background-color 0.2s;
}

.level-option:hover {
    background-color: #e3f2fd;
}

.level-option.selected {
    background-color:rgb(0, 185, 83);
    color: white;
}

.level-option input[type="radio"] {
    display: none !important;
}

.level-option label {
    display: block;
    cursor: pointer;
    margin: 0;
    width: 100%;
    height: 100%;
}

.level-content {
    font-size: 13px;
    line-height: 1.4;
    color: #333;
    margin: 0;
    padding: 0;
}

.level-option.selected .level-content {
    color: white;
}

.comment-cell {
    vertical-align: top;
    padding: 10px;
    width: 200px;
}

.criterion-comment {
    width: 100%;
    min-height: 80px;
    padding: 8px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    background-color: #ffffff;
}

.criterion-comment:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.criterion-comment::placeholder {
    color: #6c757d;
    font-style: italic;
}

.overall-feedback-textarea {
    width: 100%;
    min-height: 120px;
    padding: 12px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    background-color: #ffffff;
    line-height: 1.5;
}

.overall-feedback-textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
}

.overall-feedback-textarea::placeholder {
    color: #6c757d;
    font-style: italic;
}

@media (max-width: 992px) {
    .student-header-content {
        flex-direction: column;
        align-items: stretch;
    }
    
    .student-info-section {
        min-width: 100%;
        flex-direction: column;
        text-align: center;
    }
    
    .student-details {
        text-align: center;
    }
    
    .student-meta {
        justify-content: center;
    }
    
    .status-navigation-section {
        align-items: center;
    }
    
    .status-row {
        justify-content: center;
    }
    
    .nav-buttons {
        justify-content: center;
    }
    
    .section-header {
        padding: 16px 20px;
        font-size: 16px;
    }
    
    .code-preview, .output-preview {
        padding: 20px;
        font-size: 14px;
    }
}

@media (max-width: 768px) {
    .grading-page-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    .grading-title-section {
        text-align: left;
        order: 1;
        margin-left: 0;
    }
    
    .back-button {
        order: 2;
        align-self: flex-start;
        min-width: auto;
    }
    
    .student-selector-section {
        order: 3;
        align-items: flex-start;
        min-width: auto;
    }
    
    .student-selector-dropdown {
        min-width: 100%;
        width: 100%;
    }
    
    .student-header-card {
        padding: 24px;
    }
    
    .student-avatar {
        width: 64px;
        height: 64px;
        font-size: 24px;
    }
    
    .student-details h2 {
        font-size: 22px;
    }
    
    .nav-btn {
        padding: 8px 14px;
        font-size: 11px;
    }
    
    .nav-info {
        font-size: 12px;
    }
    
    .grade-badge {
        font-size: 14px;
        padding: 6px 12px;
    }
    
    .student-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}

/* Student Selector Section (Right) */
.student-selector-section {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    min-width: 280px;
}

.student-selector-label {
    font-weight: 600;
    color: #333;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}

.student-selector-dropdown {
    padding: 12px 16px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    background: white;
    font-size: 14px;
    font-weight: 500;
    min-width: 280px;
    cursor: pointer;
    transition: all 0.3s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
    padding-right: 40px;
}

.student-selector-dropdown:hover {
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
}

.student-selector-dropdown:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
}
</style>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>
        <div class="teacher-main-content">
            <div class="grading-page-spacer">
                <div class="assignment-meta">
                    <div class="assignment-meta-header">
                        <span class="assignment-meta-type">Code Editor Activity</span>
                    </div>
                </div>
                <div class="assignment-meta-description">
                <h2 class="assignment-meta-title"><?php echo format_string($codeeditor->name); ?></h2>
                <br>
                        <?php if (!empty($codeeditor_intro)): ?>
                            <?php echo $codeeditor_intro; ?>
                        <?php else: ?>
                            <p class="assignment-meta-description empty">No description has been added for this activity.</p>
                        <?php endif; ?>
                    </div>
            </div>
            <div class="grading-page-wrapper">
                <div class="grading-page-header">
                    <!-- Back Button (Left) -->
                    <a href="<?php echo (new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', ['assignmentid' => $codeeditorid, 'courseid' => $courseid, 'activitytype' => 'codeeditor']))->out(); ?>" class="back-button">
                        <i class="fa fa-arrow-left"></i> Back to Students
                    </a>
                    
                    <!-- Title (Center) -->
                    <div class="grading-title-section">
                        <h1 class="grading-page-title">Grading: <?php echo fullname($student); ?></h1>
                        <p class="grading-page-subtitle">Code Editor: <?php echo format_string($codeeditor->name); ?> | Course: <?php echo format_string($course->fullname); ?></p>
                    </div>
                    
                    <!-- Student Dropdown Selector (Right) -->
                    <div class="student-selector-section">
                        <label for="studentSelector" class="student-selector-label">Switch Student</label>
                        <select id="studentSelector" onchange="switchStudent(this.value)" class="student-selector-dropdown">
                            <?php foreach ($all_students as $stu): ?>
                                <option value="<?php echo $stu['id']; ?>" <?php echo ($stu['id'] == $studentid) ? 'selected' : ''; ?> <?php echo ($stu['submission_status'] === 'Graded') ? 'data-graded="true"' : ''; ?>>
                                    <?php if ($stu['submission_status'] === 'Graded'): ?>
                                        ✅ <?php echo fullname((object)$stu); ?> (Graded)
                                    <?php else: ?>
                                        <?php echo fullname((object)$stu); ?> 
                                        <?php if ($stu['submission_status'] === 'Submitted'): ?>
                                            (Submitted)
                                        <?php else: ?>
                                            (<?php echo $stu['submission_status']; ?>)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grading-content">
                    <?php
                    // Find current student position in the array
                    $current_index = -1;
                    $prev_student_id = null;
                    $next_student_id = null;
                    
                    foreach ($all_students as $index => $stu) {
                        if ($stu['id'] == $studentid) {
                            $current_index = $index;
                            break;
                        }
                    }
                    
                    // Get previous and next student IDs
                    if ($current_index > 0) {
                        $prev_student_id = $all_students[$current_index - 1]['id'];
                    }
                    if ($current_index < count($all_students) - 1) {
                        $next_student_id = $all_students[$current_index + 1]['id'];
                    }
                    ?>
                    
                    <!-- Student Header Card -->
                    <div class="student-header-card">
                        <div class="student-header-content">
                            <!-- Left: Student Info -->
                            <div class="student-info-section">
                                <div class="student-avatar">
                                    <?php echo strtoupper(substr($student->firstname, 0, 1) . substr($student->lastname, 0, 1)); ?>
                                </div>
                                <div class="student-details">
                                    <h2>
                                        <?php echo fullname($student); ?>
                                    </h2>
                                    <div class="student-meta">
                                        <span class="student-meta-item">
                                            <i class="fa fa-envelope"></i>
                                            <?php echo $student->email; ?>
                                        </span>
                                        <?php if ($already_graded && $max_score > 0): ?>
                                            <span class="grade-badge">
                                                <i class="fa fa-star"></i>
                                                <?php echo $total_score; ?>/<?php echo $max_score; ?> - <?php echo number_format($submission->grade, 2); ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right: Status & Navigation -->
                            <div class="status-navigation-section">
                                <?php if ($submission): ?>
                                    <div class="status-row">
                                        <span class="submission-status <?php echo $submission->status === 'submitted' ? 'submitted' : ($submission->status === 'draft' ? 'draft' : 'not-submitted'); ?>">
                                            <i class="fa fa-check-circle"></i>
                                            <?php echo ucfirst($submission->status); ?>
                                        </span>
                                        
                                        <?php if ($submission->grade !== null && $submission->grade >= 0): ?>
                                            <span class="graded-indicator">
                                                <i class="fa fa-trophy"></i>
                                                Graded
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($submission->status === 'submitted'): ?>
                                        <div class="submission-date">
                                            <i class="fa fa-clock-o"></i>
                                            <?php echo userdate($submission->timemodified); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Navigation Buttons -->
                                <div class="nav-buttons">
                                    <?php if ($prev_student_id): ?>
                                        <button onclick="navigateToStudent(<?php echo $prev_student_id; ?>)" class="nav-btn prev-btn" title="Previous Student">
                                            <i class="fa fa-chevron-left"></i>
                                            Previous
                                        </button>
                                    <?php else: ?>
                                        <button class="nav-btn prev-btn disabled" disabled title="No Previous Student">
                                            <i class="fa fa-chevron-left"></i>
                                            Previous
                                        </button>
                                    <?php endif; ?>
                                    
                                    <span class="nav-info">
                                        <?php echo ($current_index + 1); ?> of <?php echo count($all_students); ?>
                                    </span>
                                    
                                    <?php if ($next_student_id): ?>
                                        <button onclick="navigateToStudent(<?php echo $next_student_id; ?>)" class="nav-btn next-btn" title="Next Student">
                                            Next
                                            <i class="fa fa-chevron-right"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="nav-btn next-btn disabled" disabled title="No Next Student">
                                            Next
                                            <i class="fa fa-chevron-right"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($submission): ?>
                        <!-- Code Section -->
                        <div class="code-section">
                            <h3 class="section-header code-header">
                                <i class="fa fa-code"></i> Submitted Code
                                <?php if (!empty($submission->language)): ?>
                                    <span class="language-badge">
                                        <?php echo htmlspecialchars(strtoupper($submission->language)); ?>
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <div class="code-preview">
                                <pre><?php echo htmlspecialchars($submission->code ?: 'No code submitted'); ?></pre>
                            </div>
                        </div>
                        
                        <!-- Output Section -->
                        <div class="output-section">
                            <h3 class="section-header output-header">
                                <i class="fa fa-terminal"></i> Code Output
                            </h3>
                            <?php if (!empty($submission->output)): ?>
                                <div class="output-preview">
                                    <pre><?php echo htmlspecialchars($submission->output); ?></pre>
                                </div>
                            <?php else: ?>
                                <div class="no-output-message">
                                    <i class="fa fa-exclamation-triangle"></i>
                                    <span>No output captured for this submission.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-submission">
                            <i class="fa fa-exclamation-triangle"></i>
                            <h3>No Submission</h3>
                            <p>This student has not submitted anything yet.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Rubric Section -->
                    <div class="rubric-section">
                        <h2 class="section-title">
                            <i class="fa fa-list-alt"></i>
                            Rubric Grading
                        </h2>
                        
                        <?php if ($rubric_data): ?>
                            <?php if ($already_graded): ?>
                                <!-- Already Graded Notice -->
                                <div style="background-color: #e7f3ff; border: 1px solid #b3d9ff; color: #0066cc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                                    <h4 style="margin: 0 0 10px 0; color: #0066cc;">
                                        <i class="fa fa-edit"></i> Edit Existing Grade
                                    </h4>
                                    <p style="margin: 0;">
                                        This student has already been graded with a score of <strong><?php echo format_float($submission->grade, 2); ?>%</strong> 
                                        on <?php echo userdate($submission->timegraded, get_string('strftimedatefullshort')); ?>.
                                    </p>
                                    <p style="margin: 10px 0 0 0;">
                                        You can modify the rubric selections and feedback below. Changes will be saved when you submit the form.
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <form id="rubric-grading-form" method="post" action="">
                                <input type="hidden" name="codeeditorid" value="<?php echo $codeeditorid; ?>">
                                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                                <input type="hidden" name="studentid" value="<?php echo $studentid; ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                
                                <table class="rubric-table">
                                    <thead>
                                        <tr>
                                            <th>Criterion</th>
                                            <?php if (!empty($rubric_data['criteria'][0]['levels'])): ?>
                                                <?php foreach ($rubric_data['criteria'][0]['levels'] as $level): ?>
                                                    <th><?php echo format_float($level->score, 0); ?> points</th>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <th>Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rubric_data['criteria'] as $criterion): ?>
                                            <tr class="criterion-row">
                                                <td>
                                                    <strong><?php echo format_string($criterion['description']); ?></strong>
                                                </td>
                                                <?php foreach ($criterion['levels'] as $level): ?>
                                                    <?php 
                                                    // Check if this level was previously selected
                                                    $is_selected = false;
                                                    if (isset($existing_fillings[$criterion['id']])) {
                                                        $is_selected = ($existing_fillings[$criterion['id']]['levelid'] == $level->id);
                                                    }
                                                    ?>
                                                    <td class="level-option <?php echo $is_selected ? 'selected' : ''; ?>">
                                                        <label>
                                                            <input type="radio" 
                                                                   name="criterion_<?php echo $criterion['id']; ?>" 
                                                                   value="<?php echo $level->id; ?>"
                                                                   data-score="<?php echo $level->score; ?>"
                                                                   <?php echo $is_selected ? 'checked' : ''; ?>
                                                                   style="display: none;">
                                                            <div class="level-content">
                                                                <?php echo format_string($level->definition); ?>
                                                            </div>
                                                        </label>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="comment-cell">
                                                    <?php 
                                                    // Get existing comment if available
                                                    $existing_comment = '';
                                                    if (isset($existing_fillings[$criterion['id']])) {
                                                        $existing_comment = $existing_fillings[$criterion['id']]['remark'];
                                                    }
                                                    ?>
                                                    <textarea name="comment_<?php echo $criterion['id']; ?>" 
                                                              class="criterion-comment" 
                                                              placeholder="Add comments for this criterion..."
                                                              rows="3"><?php echo htmlspecialchars($existing_comment); ?></textarea>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <!-- Overall Feedback Section -->
                                <div class="overall-feedback-section" style="margin-top: 30px; padding: 20px; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                                    <h3 style="margin: 0 0 15px 0; color: #333; font-size: 16px; font-weight: 600;">
                                        <i class="fa fa-comment" style="margin-right: 8px; color: #007bff;"></i>
                                        Overall Feedback
                                    </h3>
                                    <p style="margin: 0 0 15px 0; color: #666; font-size: 14px;">
                                        Provide general feedback and comments for this code submission.
                                    </p>
                                    <textarea name="overall_feedback" 
                                              class="overall-feedback-textarea" 
                                              placeholder="Enter your overall feedback for this submission..."
                                              rows="4"><?php echo htmlspecialchars($existing_feedback); ?></textarea>
                                </div>
                                
                                <div style="margin-top: 20px; text-align: right;">
                                    <?php if ($already_graded): ?>
                                        <button type="submit" class="btn btn-warning" style="background-color: #ffc107; color: #212529; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                                            <i class="fa fa-edit"></i> Update Grade
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-primary" style="background-color: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                                            <i class="fa fa-save"></i> Save Grade
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="no-submission">
                                <i class="fa fa-exclamation-triangle"></i>
                                <h3>No Rubric Found</h3>
                                <p>This code editor activity does not have a rubric configured.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

<script>
// Function to switch between students
function switchStudent(newStudentId) {
    if (newStudentId && newStudentId !== '<?php echo $studentid; ?>') {
        // Build new URL with the selected student ID
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('studentid', newStudentId);
        
        // Show loading indicator
        const select = document.getElementById('studentSelector');
        const originalValue = select.value;
        select.disabled = true;
        select.style.opacity = '0.6';
        
        // Redirect to new student
        window.location.href = newUrl.toString();
    }
}

// Function to navigate to previous/next student
function navigateToStudent(studentId) {
    if (studentId && studentId !== '<?php echo $studentid; ?>') {
        // Build new URL with the selected student ID
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('studentid', studentId);
        
        // Redirect to new student
        window.location.href = newUrl.toString();
    }
}

// Add interactivity to rubric selection
document.addEventListener('DOMContentLoaded', function() {
    const radioButtons = document.querySelectorAll('input[type="radio"]');
    
    // Apply selected styling to pre-checked radio buttons
    radioButtons.forEach(radio => {
        if (radio.checked) {
            radio.closest('.level-option').classList.add('selected');
        }
    });
    
    radioButtons.forEach(radio => {
        radio.addEventListener('change', function() {
            // Remove selected class from all cells in this row
            const row = this.closest('tr');
            const cells = row.querySelectorAll('.level-option');
            cells.forEach(cell => cell.classList.remove('selected'));
            
            // Add selected class to the clicked cell
            this.closest('.level-option').classList.add('selected');
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>

