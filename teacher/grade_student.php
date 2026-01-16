<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

// Get parameters first to get proper context
$assignmentid = required_param('assignmentid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$studentid = required_param('studentid', PARAM_INT);

// Get assignment, course, and student details
$assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
$course = get_course($courseid);
$student = $DB->get_record('user', ['id' => $studentid], '*', MUST_EXIST);

// Get course module and proper context
$cm = get_coursemodule_from_instance('assign', $assignmentid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);

// Security checks with proper context
require_login($course);
require_capability('mod/assign:grade', $context);

// Get rubric for this assignment
$rubric_data = theme_remui_kids_get_rubric_by_cmid($cm->id);

// Get all students for this assignment (for dropdown)
$all_students = theme_remui_kids_get_assignment_students($assignmentid, $courseid);

// Get student's submission
$submission = $DB->get_record('assign_submission', 
    ['assignment' => $assignmentid, 'userid' => $studentid, 'latest' => 1]);

// Check if student has already been graded
$grade = $DB->get_record('assign_grades',
    ['assignment' => $assignmentid, 'userid' => $studentid],
    '*',
    IGNORE_MULTIPLE
);

$already_graded = ($grade && $grade->grade >= 0);

// Get existing rubric fillings if already graded
$existing_fillings = [];
$existing_feedback = '';
$total_score = 0;
$max_score = 0;
if ($already_graded && $grade) {
    // Get the grading instance for this grade
    $grading_instance = $DB->get_record('grading_instances', 
        ['itemid' => $grade->id],
        '*',
        IGNORE_MULTIPLE
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
        
        error_log("Existing Fillings Debug: Found " . count($existing_fillings) . " criterion fillings for grade ID " . $grade->id);
    }
    
    // Calculate max score from rubric
    if ($rubric_data) {
        foreach ($rubric_data['criteria'] as $criterion) {
            $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
            $max_score += max($scores);
        }
    }
    
    // Get existing overall feedback
    $feedback_record = $DB->get_record('assignfeedback_comments', 
        ['grade' => $grade->id, 'assignment' => $assignmentid],
        '*',
        IGNORE_MULTIPLE
    );
    
    if ($feedback_record) {
        $existing_feedback = $feedback_record->commenttext;
        error_log("Existing Feedback Debug: Found feedback for grade ID " . $grade->id);
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
            $percentage = theme_remui_kids_calculate_rubric_grade($rubric_data, $grading_data, $assignment);
            error_log("Rubric Calculation Debug: calculated percentage = $percentage");
            error_log("Grading Data Debug: " . json_encode($grading_data));
            
            // Check if already graded - handle update vs create
            if ($already_graded && $grade) {
                // UPDATE EXISTING GRADE
                error_log("Update Mode: Updating existing grade for student $studentid");
                
                // Update the main grade record
                $grade->grade = $percentage;
                $grade->timemodified = time();
                $grade->grader = $USER->id;
                $DB->update_record('assign_grades', $grade);
                error_log("Grade Update Debug: Updated grade ID = {$grade->id} with new percentage = $percentage");
                
                // Get existing grading instance
                $grading_instance = $DB->get_record('grading_instances', 
                    ['itemid' => $grade->id],
                    '*',
                    IGNORE_MULTIPLE
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
                }
                
                // Update overall feedback
                $overall_feedback = optional_param('overall_feedback', '', PARAM_TEXT);
                if (!empty($overall_feedback)) {
                    $feedback_record = $DB->get_record('assignfeedback_comments', 
                        ['grade' => $grade->id, 'assignment' => $assignmentid],
                        '*',
                        IGNORE_MULTIPLE
                    );
                    
                    if ($feedback_record) {
                        // Update existing feedback
                        $feedback_record->commenttext = $overall_feedback;
                        $feedback_record->commentformat = FORMAT_HTML;
                        $DB->update_record('assignfeedback_comments', $feedback_record);
                        error_log("Feedback Update Debug: Updated existing feedback record ID = {$feedback_record->id}");
                    } else {
                        // Create new feedback record
                        $new_feedback = new stdClass();
                        $new_feedback->assignment = $assignmentid;
                        $new_feedback->grade = $grade->id;
                        $new_feedback->commenttext = $overall_feedback;
                        $new_feedback->commentformat = FORMAT_HTML;
                        
                        try {
                            $feedback_id = $DB->insert_record('assignfeedback_comments', $new_feedback);
                            error_log("Feedback Update Debug: Created new feedback record ID = $feedback_id");
                        } catch (Exception $e) {
                            error_log("Feedback Update Error: Failed to create feedback record - " . $e->getMessage());
                        }
                    }
                }
                
                // ✅ FIXED: Update gradebook for existing grades too
                // This ensures the updated percentage is reflected in Moodle's gradebook
                try {
                    // Get the grade item for this assignment
                    $grade_item = $DB->get_record('grade_items', [
                        'itemtype' => 'mod',
                        'itemmodule' => 'assign', 
                        'iteminstance' => $assignmentid,
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
                            $new_grade_grade->rawgrademax = $assignment->grade;
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
                        
                        // Verify the grade was written correctly
                        $verify_grade = $DB->get_record('grade_grades', [
                            'itemid' => $grade_item->id,
                            'userid' => $studentid
                        ]);
                        
                        if ($verify_grade && $verify_grade->finalgrade == $percentage) {
                            error_log("Grade Verification SUCCESS: Grade correctly stored in grade_grades table - finalgrade={$verify_grade->finalgrade}");
                        } else {
                            error_log("Grade Verification WARNING: Grade mismatch! Expected=$percentage, Found=" . ($verify_grade ? $verify_grade->finalgrade : 'NULL'));
                        }
                        
                    } else {
                        error_log("Gradebook Update Error: No grade item found for assignment $assignmentid");
                    }
                    
                } catch (Exception $e) {
                    error_log("Gradebook Update Error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
                
            } else {
                // CREATE NEW GRADE (first-time grading)
                error_log("Create Mode: Creating new grade for student $studentid");
                
                $assign = new assign($context, $cm, $course);
                
                // Create new grade
                $new_grade = new stdClass();
                $new_grade->assignment = $assignmentid;
                $new_grade->userid = $studentid;
                $new_grade->timecreated = time();
                $new_grade->timemodified = time();
                $new_grade->grader = $USER->id;
                $new_grade->grade = $percentage;
                $new_grade->attemptnumber = 0;
                $grade_id = $DB->insert_record('assign_grades', $new_grade);
                error_log("Grade Create Debug: Created new grade ID = $grade_id for student $studentid with percentage = $percentage");
                $grade = $new_grade;
                $grade->id = $grade_id;
            
                // ✅ FIXED: Use direct gradebook update to avoid Moodle's recalculation
                // This prevents Moodle from overwriting our calculated percentage
                try {
                    // Get the grade item for this assignment
                    $grade_item = $DB->get_record('grade_items', [
                        'itemtype' => 'mod',
                        'itemmodule' => 'assign', 
                        'iteminstance' => $assignmentid,
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
                            $new_grade_grade->rawgrademax = $assignment->grade;
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
                        
                        // Verify the grade was written correctly
                        $verify_grade = $DB->get_record('grade_grades', [
                            'itemid' => $grade_item->id,
                            'userid' => $studentid
                        ]);
                        
                        if ($verify_grade && $verify_grade->finalgrade == $percentage) {
                            error_log("Grade Verification SUCCESS: Grade correctly stored in grade_grades table - finalgrade={$verify_grade->finalgrade}");
                        } else {
                            error_log("Grade Verification WARNING: Grade mismatch! Expected=$percentage, Found=" . ($verify_grade ? $verify_grade->finalgrade : 'NULL'));
                        }
                        
                    } else {
                        error_log("Gradebook Update Error: No grade item found for assignment $assignmentid");
                    }
                    
                } catch (Exception $e) {
                    error_log("Gradebook Update Error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
            }
            
            // Let's also check if there are other students with the same grade ID
            $all_grades_with_same_id = $DB->get_records('assign_grades', ['id' => $grade->id]);
            error_log("Grade ID Check Debug: Found " . count($all_grades_with_same_id) . " grade records with ID = " . $grade->id);
            foreach ($all_grades_with_same_id as $g) {
                error_log("Grade ID Check Debug: Grade ID " . $g->id . " belongs to student " . $g->userid . " in assignment " . $g->assignment);
            }
            
            // Save rubric filling data to Moodle's standard tables
            if (!empty($grading_data)) {
                $grade_id = $grade ? $grade->id : $DB->get_field('assign_grades', 'id', ['assignment' => $assignmentid, 'userid' => $studentid]);
                
                // Create new grading instance (first-time grading only)
                $grading_instance = new stdClass();
                $grading_instance->definitionid = $rubric_data['rubric_id'];
                $grading_instance->raterid = $USER->id;
                $grading_instance->itemid = $grade_id;
                $grading_instance->rawgrade = $percentage;
                $grading_instance->status = 1; // Set to 1 (graded)
                $grading_instance->feedback = '';
                $grading_instance->feedbackformat = FORMAT_HTML;
                $grading_instance->timemodified = time();
                $grading_instance_id = $DB->insert_record('grading_instances', $grading_instance);
                error_log("Rubric Storage Debug: Created new grading instance ID = $grading_instance_id for grade_id = $grade_id (definition=" . $rubric_data['rubric_id'] . ")");
                
                // Step 2: Use grading instance ID as instanceid for rubric fillings
                $instanceid = $grading_instance_id;
                error_log("Rubric Storage Debug: Using grading_instance_id = $instanceid as instanceid for rubric fillings");
                error_log("Rubric Storage Debug: grading_data = " . json_encode($grading_data));
                
                // Check if table exists
                $table_exists = $DB->get_manager()->table_exists('gradingform_rubric_fillings');
                error_log("Rubric Storage Debug: table_exists = " . ($table_exists ? "true" : "false"));
                
                if (!$table_exists) {
                    error_log("Rubric Storage Debug: Table doesn't exist, skipping rubric storage");
                } else {
                    // Save each criterion filling as a separate record (first-time grading only)
                    foreach ($grading_data as $criterion_data) {
                        $criterion_filling = new stdClass();
                        $criterion_filling->instanceid = $instanceid;
                        $criterion_filling->criterionid = $criterion_data['criterionid'];
                        $criterion_filling->levelid = $criterion_data['levelid'];
                        $criterion_filling->remark = $criterion_data['comment'];
                        $criterion_filling->remarkformat = FORMAT_HTML;
                        
                        try {
                            $criterion_filling_id = $DB->insert_record('gradingform_rubric_fillings', $criterion_filling);
                            error_log("Criterion Filling Debug: Created criterion filling ID = $criterion_filling_id for criterion " . $criterion_data['criterionid']);
                        } catch (Exception $e) {
                            error_log("Criterion Filling Error: Failed to create criterion filling for criterion " . $criterion_data['criterionid'] . " - " . $e->getMessage());
                            // Continue with other criterion fillings
                        }
                    }
                }
            }
            
            // Save overall feedback (first-time grading only)
            $overall_feedback = optional_param('overall_feedback', '', PARAM_TEXT);
            error_log("Overall Feedback Debug: feedback = '$overall_feedback'");
            if (!empty($overall_feedback)) {
                $new_feedback = new stdClass();
                $new_feedback->assignment = $assignmentid;
                $new_feedback->grade = $grade->id;
                $new_feedback->commenttext = $overall_feedback;
                $new_feedback->commentformat = FORMAT_HTML;
                
                try {
                    $feedback_id = $DB->insert_record('assignfeedback_comments', $new_feedback);
                    error_log("Overall Feedback Debug: Created new feedback record ID = $feedback_id");
                } catch (Exception $e) {
                    error_log("Overall Feedback Error: Failed to create feedback record - " . $e->getMessage());
                    throw $e;
                }
            }
            
            // Refresh grade data after saving
            $grade = $DB->get_record('assign_grades',
                ['assignment' => $assignmentid, 'userid' => $studentid],
                '*',
                IGNORE_MULTIPLE
            );
            
            // Update the existing fillings and feedback for display
            $existing_fillings = [];
            $existing_feedback = '';
            $total_score = 0;
            $max_score = 0;
            
            if ($grade) {
                // Get the grading instance for this grade
                $grading_instance = $DB->get_record('grading_instances', 
                    ['itemid' => $grade->id],
                    '*',
                    IGNORE_MULTIPLE
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
                $feedback_record = $DB->get_record('assignfeedback_comments', 
                    ['grade' => $grade->id, 'assignment' => $assignmentid],
                    '*',
                    IGNORE_MULTIPLE
                );
                
                if ($feedback_record) {
                    $existing_feedback = $feedback_record->commenttext;
                }
            }
            
            $already_graded = ($grade && $grade->grade >= 0);
            
            // Redirect to prevent resubmission
            $redirect_url = new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', [
                'assignmentid' => $assignmentid,
                'courseid' => $courseid
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
$PAGE->set_url('/theme/remui_kids/teacher/grade_student.php', [
    'assignmentid' => $assignmentid, 
    'courseid' => $courseid,
    'studentid' => $studentid
]);
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('grading-page');
$PAGE->set_title('Grading: ' . fullname($student));
$PAGE->navbar->add('Rubrics', new moodle_url('/theme/remui_kids/teacher/rubrics.php'));
$PAGE->navbar->add('Grading', new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', [
    'assignmentid' => $assignmentid, 
    'courseid' => $courseid
]));
$PAGE->navbar->add(fullname($student));

echo $OUTPUT->header();
?>

<style>
/* Prevent horizontal overflow on body and html */
body.grading-page,
html body.grading-page {
    overflow-x: hidden !important;
    max-width: 100vw !important;
}

/* Neutralize the default main container */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
}

.teacher-main-content {
    flex-grow: 1;
    padding: 20px;
    background-color: #f0f2f5;
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 1800px !important;
    min-width: 0;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    transition: margin-left 0.3s ease, width 0.3s ease, max-width 0.3s ease;
}

body.grading-page .teacher-main-content {
    padding-top: 80px;
}

/* Adjust teacher-main-content width when drawer sidebar is open */
/* Removed margin-left and width adjustments - content will use full width */

/* Responsive: On mobile, drawer overlays so no margin adjustment needed */
@media (max-width: 767px) {
    .drawers.show-drawer-left .teacher-main-content,
    #page.drawers.show-drawer-left .teacher-main-content,
    body.drawer-open-left .teacher-main-content,
    body.show-drawer-left .teacher-main-content,
    html body.drawer-open-left .teacher-main-content,
    html body.show-drawer-left .teacher-main-content,
    body:has(#theme_remui-drawers-courseindex.show) .teacher-main-content,
    body:has(.theme_remui-drawers-courseindex.show) .teacher-main-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
}



.grading-page-spacer {
    width: 100%;
    max-width: 100%;
    margin-bottom: 30px;
    box-sizing: border-box;
}

.grading-page-wrapper {
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    padding: 20px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow-x: hidden;
}

body.grading-page .secondary-navigation,
body.grading-page .tertiary-navigation,
body.grading-page .secondarynavigation,
body.grading-page .activity-header,
body.grading-page .page-header-headings {
    display: none !important;
}

body.grading-page #page,
body.grading-page #page-content,
body.grading-page .main-inner,
body.grading-page #region-main-box,
body.grading-page .teacher-main-content {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

body.grading-page #page-header,
body.grading-page header#page-header,
body.grading-page header#page-header.header-maxwidth,
body.grading-page .page-header,
body.grading-page .page-header-headings {
    display: none !important;
}

body.grading-page #region-main > h2 {
    display: none !important;
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
    margin-left: 0;
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
    gap: 20px;
    margin-top: 20px;
}

.submission-section, .rubric-section {
    background-color: #ffffff;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
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

.submission-info {
    background-color: #f8fafc;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e2e8f0;
}

.student-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.student-info-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.student-avatar {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.student-details h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: #1f2937;
    line-height: 1.2;
}

.student-details p {
    margin: 4px 0 0 0;
    color: #6b7280;
    font-size: 14px;
    font-weight: 400;
}

.submission-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.submission-status.submitted {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.submission-status.not-submitted {
    background-color: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.submission-status.draft {
    background-color: #eff6ff;
    color: #2563eb;
    border: 1px solid #dbeafe;
}

.graded-indicator {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background-color: #dcfce7;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #166534;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-section {
    margin-top: 16px;
}

.status-badges {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.submission-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
}

.submission-date i {
    color: #9ca3af;
}

.file-display {
    margin-top: 20px;
}

.student-navigation {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 0;
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
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #ffffff;
    color: #374151;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.nav-btn:hover:not(.disabled) {
    background-color: #3b82f6;
    color: white;
    border-color: #3b82f6;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.nav-btn.disabled {
    background-color: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
    cursor: not-allowed;
}

.nav-info {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 8px;
}

.file-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background-color: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 0;
    transition: all 0.2s ease;
}

.file-item:hover {
    border-color: #d1d5db;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.file-icon {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f3f4f6;
    border-radius: 8px;
    color: #6b7280;
    border: 1px solid #e5e7eb;
}

.file-details h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    line-height: 1.3;
}

.file-details p {
    margin: 3px 0 0 0;
    font-size: 13px;
    color: #6b7280;
    font-weight: 400;
}

.file-actions {
    margin-left: auto;
}

.view-file-btn {
    background-color: #3b82f6;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.view-file-btn:hover {
    background-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    color: white;
    text-decoration: none;
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

@media (max-width: 768px) {
    .grading-content {
        flex-direction: column;
    }
    
    .grading-page-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    .grading-title-section {
        text-align: left;
        order: 1;
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

/* Style for graded students with green circle checkmark */
.student-selector-dropdown option[data-graded="true"] {
    color: #1f2937;
    font-weight: 600;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3e%3ccircle cx='12' cy='12' r='10' fill='%2322c55e'/%3e%3cpath d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z' fill='white' stroke='white' stroke-width='0.5'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: 6px center;
    background-size: 16px 16px;
    padding-left: 28px;
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

.student-selector-dropdown:disabled {
    background-color: #f8f9fa;
    cursor: not-allowed;
    opacity: 0.6;
}

/* Loading indicator for dropdown */
.student-switching {
    position: relative;
}

.student-switching::after {
    content: '';
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translateY(-50%) rotate(0deg); }
    100% { transform: translateY(-50%) rotate(360deg); }
}

/* PDF Modal Styles */
.pdf-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(4px);
}

.pdf-modal-content {
    background-color: #ffffff;
    margin: 2% auto;
    padding: 0;
    border-radius: 12px;
    width: 95%;
    max-width: 1200px;
    height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.pdf-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    background-color: #f8fafc;
    border-radius: 12px 12px 0 0;
}

.pdf-modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
}

.pdf-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6b7280;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.pdf-modal-close:hover {
    background-color: #f3f4f6;
    color: #374151;
}

.pdf-modal-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.pdf-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-bottom: 1px solid #e5e7eb;
    background-color: #f8fafc;
    flex-wrap: wrap;
}

.pdf-control-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #ffffff;
    color: #374151;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
}

.pdf-control-btn:hover {
    background-color: #3b82f6;
    color: white;
    border-color: #3b82f6;
    transform: translateY(-1px);
}

#zoomLevel {
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    padding: 0 8px;
    min-width: 50px;
    text-align: center;
}

.pdf-container {
    flex: 1;
    position: relative;
    overflow: hidden;
}

#pdfViewer {
    width: 100%;
    height: 100%;
    border: none;
    background-color: #ffffff;
}

.pdf-loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    z-index: 10;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #e5e7eb;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    animation: pdfSpinner 1s linear infinite;
    margin: 0 auto 16px;
}

@keyframes pdfSpinner {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.pdf-fallback {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    z-index: 10;
}

.fallback-content {
    background-color: #ffffff;
    padding: 32px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    max-width: 400px;
}

.fallback-content h4 {
    margin: 16px 0 8px;
    color: #374151;
    font-size: 18px;
}

.fallback-content p {
    margin: 0 0 24px;
    color: #6b7280;
    font-size: 14px;
}

.fallback-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
}

.download-btn, .cancel-btn {
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.download-btn {
    background-color: #3b82f6;
    color: white;
}

.download-btn:hover {
    background-color: #2563eb;
    transform: translateY(-1px);
}

.cancel-btn {
    background-color: #f3f4f6;
    color: #374151;
}

.cancel-btn:hover {
    background-color: #e5e7eb;
}

@media (max-width: 768px) {
    .pdf-modal-content {
        width: 98%;
        height: 95vh;
        margin: 1% auto;
    }
    
    .pdf-controls {
        padding: 8px 12px;
        gap: 6px;
    }
    
    .pdf-control-btn {
        width: 32px;
        height: 32px;
        font-size: 12px;
    }
}

.assignment-meta {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow-x: hidden;
}

.assignment-meta-title {
    margin: 0 0 12px 0;
    font-size: 26px;
    font-weight: 700;
    color: #1f2937;
}

.assignment-meta-description {
    font-size: 15px;
    line-height: 1.6;
    color: #4b5563;
}

.assignment-meta-description.empty {
    font-style: italic;
    color: #9ca3af;
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
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow-x: hidden;
}

.assignment-meta-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
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
    background-color: #f7f9fb;
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
</style>

<div class="teacher-main-content">
    <div class="grading-page-spacer">
        <div class="assignment-meta">
            <div class="assignment-meta-header">
                <span class="assignment-meta-type">Assignment Overview</span>
                <h2 class="assignment-meta-title"><?php echo format_string($assignment->name); ?></h2>
            </div>
            <?php if (!empty($assignment->intro)): ?>
                <div class="assignment-meta-description"><?php echo format_text($assignment->intro, $assignment->introformat, ['context' => $context, 'noclean' => true, 'overflowdiv' => true]); ?></div>
            <?php else: ?>
                <p class="assignment-meta-description empty">No description has been added for this assignment.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="grading-page-wrapper">
        <div class="grading-page-header">
            <!-- Back Button (Left) -->
                    <a href="<?php echo (new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', ['assignmentid' => $assignmentid, 'courseid' => $courseid]))->out(); ?>" class="back-button">
                        <i class="fa fa-arrow-left"></i> Back to Students
                    </a>
                    
                    <!-- Title (Center) -->
                    <div class="grading-title-section">
                        <h1 class="grading-page-title">Grading: <?php echo fullname($student); ?></h1>
                        <p class="grading-page-subtitle">Assignment: <?php echo format_string($assignment->name); ?> | Course: <?php echo format_string($course->fullname); ?></p>
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
                    <!-- Submission Section -->
                    <div class="submission-section">
                        <h2 class="section-title">
                            <i class="fa fa-file-text"></i>
                            Student Submission
                        </h2>
                        
                        <div class="submission-info">
                            <div class="student-info">
                                <div class="student-info-left">
                                    <div class="student-avatar">
                                        <?php echo strtoupper(substr($student->firstname, 0, 1) . substr($student->lastname, 0, 1)); ?>
                                    </div>
                                    <div class="student-details">
                                        <h3>
                                            <?php echo fullname($student); ?>
                                            <?php if ($already_graded && $max_score > 0): ?>
                                                <span style="margin-left: 10px; padding: 4px 12px; background-color: #dcfce7; color: #166534; border-radius: 6px; font-size: 14px; font-weight: 600;">
                                                    <?php echo $total_score; ?>/<?php echo $max_score; ?> - <?php echo number_format($grade->grade, 2); ?>%
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p><?php echo $student->email; ?></p>
                                    </div>
                                </div>
                                
                                <!-- Student Navigation Buttons -->
                                <div class="student-navigation">
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
                            
                            <?php if ($submission): ?>
                                <div class="status-section">
                                    <div class="status-badges">
                                        <span class="submission-status <?php echo $submission->status === 'submitted' ? 'submitted' : ($submission->status === 'draft' ? 'draft' : 'not-submitted'); ?>">
                                            <?php echo ucfirst($submission->status); ?>
                                        </span>
                                        
                                        <?php if ($grade && $grade->grade >= 0): ?>
                                            <span class="graded-indicator">
                                                <i class="fa fa-check-circle"></i>
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
                                </div>
                                
                                <!-- File Display -->
                                <div class="file-display">
                                    <?php
                                    // Get submission files
                                    $fs = get_file_storage();
                                    $context = context_module::instance($cm->id);
                                    $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'timemodified', false);
                                    
                                    if (!empty($files)) {
                                        foreach ($files as $file) {
                                            $filename = $file->get_filename();
                                            $fileurl = moodle_url::make_pluginfile_url(
                                                $file->get_contextid(),
                                                $file->get_component(),
                                                $file->get_filearea(),
                                                $file->get_itemid(),
                                                $file->get_filepath(),
                                                $filename,
                                                true
                                            );
                                            
                                            // Get file icon based on extension
                                            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                            $icon = 'fa-file';
                                            if (in_array($extension, ['pdf'])) {
                                                $icon = 'fa-file-pdf-o';
                                            } elseif (in_array($extension, ['doc', 'docx'])) {
                                                $icon = 'fa-file-word-o';
                                            } elseif (in_array($extension, ['xls', 'xlsx'])) {
                                                $icon = 'fa-file-excel-o';
                                            } elseif (in_array($extension, ['ppt', 'pptx'])) {
                                                $icon = 'fa-file-powerpoint-o';
                                            } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                $icon = 'fa-file-image-o';
                                            }
                                            
                                            echo '<div class="file-item">';
                                            echo '<div class="file-icon"><i class="fa ' . $icon . '"></i></div>';
                                            echo '<div class="file-details">';
                                            echo '<h4>' . format_string($filename) . '</h4>';
                                            echo '<p>' . display_size($file->get_filesize()) . '</p>';
                                            echo '</div>';
                                            echo '<div class="file-actions">';
                                            // Check if it's a PDF file
                                            if (strtolower($extension) === 'pdf') {
                                                // Create a custom PDF viewer URL that serves the file inline
                                                $pdf_viewer_url = new moodle_url('/theme/remui_kids/teacher/pdf_viewer.php', [
                                                    'fileid' => $file->get_id(),
                                                    'contextid' => $file->get_contextid(),
                                                    'component' => $file->get_component(),
                                                    'filearea' => $file->get_filearea(),
                                                    'itemid' => $file->get_itemid(),
                                                    'filepath' => $file->get_filepath(),
                                                    'filename' => $filename
                                                ]);
                                                echo '<button onclick="openPDFViewer(\'' . $pdf_viewer_url->out() . '\', \'' . format_string($filename) . '\', \'' . $fileurl->out() . '\')" class="view-file-btn">View PDF</button>';
                                            } else {
                                                // For non-PDF files, use regular download
                                                echo '<a href="' . $fileurl->out() . '" target="_blank" class="view-file-btn">View</a>';
                                            }
                                            echo '</div>';
                                            echo '</div>';
                                        }
                                    } else {
                                        echo '<div class="no-submission">';
                                        echo '<i class="fa fa-file-o"></i>';
                                        echo '<h3>No Files Submitted</h3>';
                                        echo '<p>This student has not uploaded any files.</p>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="no-submission">
                                    <i class="fa fa-exclamation-triangle"></i>
                                    <h3>No Submission</h3>
                                    <p>This student has not submitted anything yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

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
                                        This student has already been graded with a score of <strong><?php echo format_float($grade->grade, 2); ?>%</strong> 
                                        on <?php echo userdate($grade->timemodified, get_string('strftimedatefullshort')); ?>.
                                    </p>
                                    <p style="margin: 10px 0 0 0;">
                                        You can modify the rubric selections and feedback below. Changes will be saved when you submit the form.
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <form id="rubric-grading-form" method="post" action="">
                                <input type="hidden" name="assignmentid" value="<?php echo $assignmentid; ?>">
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
                                        Provide general feedback and comments for this assignment submission.
                                    </p>
                                    <textarea name="overall_feedback" 
                                              class="overall-feedback-textarea" 
                                              placeholder="Enter your overall feedback for this assignment..."
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
                                <p>This assignment does not have a rubric configured.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

<!-- PDF Viewer Modal -->
<div id="pdfModal" class="pdf-modal">
    <div class="pdf-modal-content">
        <div class="pdf-modal-header">
            <h3 id="pdfModalTitle">PDF Viewer</h3>
            <button class="pdf-modal-close" onclick="closePDFViewer()">&times;</button>
        </div>
        <div class="pdf-modal-body">
            <div class="pdf-controls">
                <button onclick="zoomOut()" class="pdf-control-btn" title="Zoom Out">
                    <i class="fa fa-search-minus"></i>
                </button>
                <span id="zoomLevel">100%</span>
                <button onclick="zoomIn()" class="pdf-control-btn" title="Zoom In">
                    <i class="fa fa-search-plus"></i>
                </button>
                <button onclick="fitToWidth()" class="pdf-control-btn" title="Fit to Width">
                    <i class="fa fa-arrows-h"></i>
                </button>
                <button onclick="fitToPage()" class="pdf-control-btn" title="Fit to Page">
                    <i class="fa fa-expand"></i>
                </button>
                <button onclick="downloadPDF()" class="pdf-control-btn" title="Download PDF">
                    <i class="fa fa-download"></i>
                </button>
            </div>
            <div class="pdf-container">
                <div id="pdfLoading" class="pdf-loading" style="display: none;">
                    <div class="loading-spinner"></div>
                    <p>Loading PDF...</p>
                </div>
                <iframe id="pdfViewer" src="" width="100%" height="600px" frameborder="0"></iframe>
                <div id="pdfJsViewer" style="display: none; width: 100%; height: 600px;">
                    <iframe id="pdfJsFrame" src="" width="100%" height="100%" frameborder="0"></iframe>
                </div>
                <div class="pdf-fallback" style="display: none;">
                    <div class="fallback-content">
                        <i class="fa fa-file-pdf-o" style="font-size: 48px; color: #dc2626; margin-bottom: 16px;"></i>
                        <h4>PDF Preview Not Available</h4>
                        <p id="fallbackMessage">Your browser doesn't support inline PDF viewing, or the file is protected.</p>
                        <div class="fallback-actions">
                            <a id="pdfDownloadLink" href="#" class="download-btn" target="_blank">
                                <i class="fa fa-download"></i> Download PDF
                            </a>
                            <button onclick="closePDFViewer()" class="cancel-btn">
                                <i class="fa fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Immediate fix for layout issues - runs before DOMContentLoaded
(function() {
    function fixMainContentLayout() {
        const mainContent = document.querySelector('.teacher-main-content');
        if (mainContent) {
            // Remove any inline styles for margin-left, width, or max-width
            mainContent.style.removeProperty('margin-left');
            mainContent.style.removeProperty('width');
            mainContent.style.removeProperty('max-width');
        }
    }
    
    // Run immediately if DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fixMainContentLayout);
    } else {
        fixMainContentLayout();
    }
    
    // Also run after a short delay to catch any late-applied styles
    setTimeout(fixMainContentLayout, 50);
})();

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

// PDF Viewer Functions
let currentPDFUrl = '';
let currentDownloadUrl = '';
let currentZoom = 100;

function openPDFViewer(pdfUrl, filename, downloadUrl) {
    console.log('Opening PDF viewer:', {pdfUrl, filename, downloadUrl});
    
    currentPDFUrl = pdfUrl;
    currentDownloadUrl = downloadUrl;
    currentZoom = 100;
    
    // Set modal title
    document.getElementById('pdfModalTitle').textContent = filename;
    
    // Set download link
    document.getElementById('pdfDownloadLink').href = downloadUrl;
    
    // Show modal
    document.getElementById('pdfModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Show loading spinner
    document.getElementById('pdfLoading').style.display = 'block';
    document.getElementById('pdfViewer').style.display = 'none';
    document.getElementById('pdfJsViewer').style.display = 'none';
    document.querySelector('.pdf-fallback').style.display = 'none';
    
    // Try Chrome-friendly approach first
    if (navigator.userAgent.includes('Chrome')) {
        tryChromePDFViewer(pdfUrl);
    } else {
        tryStandardPDFViewer(pdfUrl);
    }
    
    // Update zoom display
    updateZoomDisplay();
}

function tryChromePDFViewer(pdfUrl) {
    console.log('Trying Chrome-friendly PDF viewer');
    
    // Use Google Docs viewer as fallback for Chrome
    const googleViewerUrl = 'https://docs.google.com/gview?url=' + encodeURIComponent(pdfUrl) + '&embedded=true';
    const iframe = document.getElementById('pdfViewer');
    
    // Clear any previous src
    iframe.src = '';
    
    // Set a shorter timeout for Chrome
    const loadingTimeout = setTimeout(() => {
        console.log('Chrome PDF loading timeout - trying fallback');
        tryStandardPDFViewer(pdfUrl);
    }, 8000);
    
    // Handle iframe load events
    iframe.onload = function() {
        console.log('Chrome PDF iframe loaded');
        clearTimeout(loadingTimeout);
        
        setTimeout(() => {
            document.getElementById('pdfLoading').style.display = 'none';
            document.getElementById('pdfViewer').style.display = 'block';
            console.log('Chrome PDF viewer displayed');
        }, 1500);
    };
    
    // Handle iframe load error
    iframe.onerror = function(error) {
        console.error('Chrome PDF iframe load error:', error);
        clearTimeout(loadingTimeout);
        tryStandardPDFViewer(pdfUrl);
    };
    
    // Set the PDF URL
    iframe.src = pdfUrl;
    console.log('Set Chrome iframe src to:', pdfUrl);
}

function tryStandardPDFViewer(pdfUrl) {
    console.log('Trying standard PDF viewer');
    
    const iframe = document.getElementById('pdfViewer');
    
    // Clear any previous src
    iframe.src = '';
    
    // Set a timeout to show fallback if loading takes too long
    const loadingTimeout = setTimeout(() => {
        console.log('Standard PDF loading timeout - showing fallback');
        showPDFFallback();
    }, 10000);
    
    // Handle iframe load events
    iframe.onload = function() {
        console.log('Standard PDF iframe loaded');
        clearTimeout(loadingTimeout);
        
        setTimeout(() => {
            document.getElementById('pdfLoading').style.display = 'none';
            document.getElementById('pdfViewer').style.display = 'block';
            
            // Try to detect if PDF actually loaded
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                if (!iframeDoc || iframeDoc.body.innerHTML.trim() === '') {
                    console.log('PDF iframe appears empty - showing fallback');
                    showPDFFallback();
                } else {
                    console.log('PDF appears to have loaded successfully');
                }
            } catch (e) {
                // Cross-origin restrictions - assume it loaded if no error
                console.log('PDF loaded successfully (cross-origin)');
            }
        }, 2000);
    };
    
    // Handle iframe load error
    iframe.onerror = function(error) {
        console.error('Standard PDF iframe load error:', error);
        clearTimeout(loadingTimeout);
        showPDFFallback();
    };
    
    // Set the PDF URL
    iframe.src = pdfUrl;
    console.log('Set standard iframe src to:', pdfUrl);
}

function closePDFViewer() {
    document.getElementById('pdfModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Clear iframe src to stop loading
    const iframe = document.getElementById('pdfViewer');
    iframe.src = '';
    
    // Reset zoom
    currentZoom = 100;
    updateZoomDisplay();
}

function showPDFFallback() {
    document.getElementById('pdfLoading').style.display = 'none';
    document.getElementById('pdfViewer').style.display = 'none';
    document.getElementById('pdfJsViewer').style.display = 'none';
    
    // Update fallback message based on browser
    const fallbackMessage = document.getElementById('fallbackMessage');
    if (navigator.userAgent.includes('Chrome')) {
        fallbackMessage.textContent = 'Chrome requires PDF files to be downloaded or viewed in a new tab. Click "Download PDF" to view the file.';
    } else {
        fallbackMessage.textContent = 'Your browser doesn\'t support inline PDF viewing, or the file is protected.';
    }
    
    document.querySelector('.pdf-fallback').style.display = 'block';
}

function zoomIn() {
    currentZoom = Math.min(currentZoom + 25, 300);
    updateZoomDisplay();
    applyZoom();
}

function zoomOut() {
    currentZoom = Math.max(currentZoom - 25, 50);
    updateZoomDisplay();
    applyZoom();
}

function fitToWidth() {
    currentZoom = 100;
    updateZoomDisplay();
    applyZoom();
}

function fitToPage() {
    currentZoom = 75;
    updateZoomDisplay();
    applyZoom();
}

function updateZoomDisplay() {
    document.getElementById('zoomLevel').textContent = currentZoom + '%';
}

function applyZoom() {
    const iframe = document.getElementById('pdfViewer');
    iframe.style.transform = `scale(${currentZoom / 100})`;
    iframe.style.transformOrigin = 'top left';
}

function downloadPDF() {
    if (currentDownloadUrl) {
        window.open(currentDownloadUrl, '_blank');
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('pdfModal');
    if (event.target === modal) {
        closePDFViewer();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('pdfModal');
        if (modal.style.display === 'block') {
            closePDFViewer();
        }
    }
});

// Add interactivity to rubric selection
document.addEventListener('DOMContentLoaded', function() {
    // Immediately fix any unwanted margin on teacher-main-content
    const mainContent = document.querySelector('.teacher-main-content');
    if (mainContent) {
        // Remove any inline styles for margin-left, width, or max-width
        mainContent.style.removeProperty('margin-left');
        mainContent.style.removeProperty('width');
        mainContent.style.removeProperty('max-width');
    }
    
    const radioButtons = document.querySelectorAll('input[type="radio"]');
    
    // Debug: Log pre-filled data
    console.log('=== Rubric Grading Form Debug ===');
    console.log('Student ID: <?php echo $studentid; ?>');
    console.log('Assignment ID: <?php echo $assignmentid; ?>');
    console.log('Grade exists: <?php echo ($grade && $grade->grade >= 0) ? "YES" : "NO"; ?>');
    <?php if ($grade && $grade->grade >= 0): ?>
    console.log('Current grade: <?php echo $grade->grade; ?>%');
    console.log('Graded by: User ID <?php echo $grade->grader; ?> on <?php echo date("Y-m-d H:i:s", $grade->timemodified); ?>');
    <?php endif; ?>
    console.log('First-time grading mode: NO existing filling loaded');
    
    // Count pre-checked radio buttons
    const checkedCount = document.querySelectorAll('input[type="radio"]:checked').length;
    console.log('Pre-checked radio buttons: ' + checkedCount);
    console.log('================================');
    
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
    
    // Monitor drawer sidebar state and adjust teacher-main-content width
    function updateMainContentForDrawer() {
        const drawers = document.querySelector('.drawers');
        const sidebar = document.querySelector('#theme_remui-drawers-courseindex, .theme_remui-drawers-courseindex');
        const mainContent = document.querySelector('.teacher-main-content');
        
        if (!mainContent) return;
        
        // Check if drawer is open - multiple detection methods
        const hasShowDrawerClass = drawers && drawers.classList.contains('show-drawer-left');
        const hasShowClass = sidebar && sidebar.classList.contains('show');
        const sidebarLeft = sidebar ? getComputedStyle(sidebar).left : '';
        const isSidebarVisible = sidebarLeft === '0px' || sidebarLeft === '0';
        const isDrawerOpen = hasShowDrawerClass || hasShowClass || isSidebarVisible;
        
        // Remove any inline styles for margin-left and width
        mainContent.style.removeProperty('margin-left');
        mainContent.style.removeProperty('width');
        
        // Increase max-width when sidebar is closed
        if (!isDrawerOpen) {
            // Sidebar is closed - use larger max-width
            mainContent.style.setProperty('max-width', '2000px', 'important');
        } else {
            // Sidebar is open - use default max-width from CSS
            mainContent.style.removeProperty('max-width');
        }
    }
    
    // Initial check - run immediately and after delays to catch all states
    updateMainContentForDrawer();
    setTimeout(updateMainContentForDrawer, 100);
    setTimeout(updateMainContentForDrawer, 500);
    
    // Also run after window loads completely
    if (document.readyState === 'complete') {
        updateMainContentForDrawer();
    } else {
        window.addEventListener('load', function() {
            setTimeout(updateMainContentForDrawer, 100);
        });
    }
    
    // Monitor for changes using MutationObserver
    const observer = new MutationObserver(function(mutations) {
        updateMainContentForDrawer();
    });
    
    // Observe drawer element for class changes
    const drawers = document.querySelector('.drawers');
    if (drawers) {
        observer.observe(drawers, {
            attributes: true,
            attributeFilter: ['class'],
            subtree: false
        });
    }
    
    // Observe sidebar element for class and style changes
    const sidebar = document.querySelector('#theme_remui-drawers-courseindex, .theme_remui-drawers-courseindex');
    if (sidebar) {
        observer.observe(sidebar, {
            attributes: true,
            attributeFilter: ['class', 'style'],
            subtree: false
        });
    }
    
    // Also listen for window resize
    window.addEventListener('resize', updateMainContentForDrawer);
    
    // Periodic check as fallback (in case MutationObserver misses something)
    // More frequent checks to catch 350px margin issue quickly
    setInterval(updateMainContentForDrawer, 200);
});
</script>

<?php
echo $OUTPUT->footer();
?>
