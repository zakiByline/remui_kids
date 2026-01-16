<?php
/**
 * Create Quiz Handler
 * Backend script to create a new quiz with questions
 */

// Set error handler to catch fatal errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        error_log('Fatal error in create_quiz.php: ' . $error['message']);
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $error['message'] . ' in ' . basename($error['file']) . ' on line ' . $error['line']
        ]);
        exit;
    }
});

define('AJAX_SCRIPT', true);

// Start output buffering to catch any errors
ob_start();

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->libdir . '/questionlib.php');
require_once(__DIR__ . '/includes/question_helpers.php');

// Clear any output from includes
ob_clean();

header('Content-Type: application/json');

// Security checks
try {
    require_login();
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Login required: ' . $e->getMessage()]);
    exit;
}

// Check sesskey - don't require it for AJAX, just validate if present
$sesskey = optional_param('sesskey', '', PARAM_RAW);
if (!empty($sesskey) && !confirm_sesskey($sesskey)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid session key']);
    exit;
}

$systemcontext = context_system::instance();

if (!has_capability('moodle/course:update', $systemcontext) && !is_siteadmin()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    error_log("=== Quiz Creation Started ===");
    error_log("POST data: " . print_r($_POST, true));
    
    // Get form data
    $name = required_param('name', PARAM_TEXT);
    $courseid = required_param('courseid', PARAM_INT);
    $intro = optional_param('intro', '', PARAM_RAW);
    $section = required_param('section', PARAM_INT);
    
    error_log("Quiz name: $name");
    error_log("Course ID: $courseid");
    error_log("Section ID: $section");
    
    // Timing
    $timeopen = 0;
    $timeclose = 0;
    $timelimit = optional_param('timelimit', 0, PARAM_INT) * 60; // Convert minutes to seconds
    
    // Process time open
    $open_day = optional_param('open_day', 0, PARAM_INT);
    $open_month = optional_param('open_month', 0, PARAM_INT);
    $open_year = optional_param('open_year', 0, PARAM_INT);
    $open_hour = optional_param('open_hour', 0, PARAM_INT);
    $open_minute = optional_param('open_minute', 0, PARAM_INT);
    
    if ($open_day && $open_month && $open_year) {
        $timeopen = mktime($open_hour, $open_minute, 0, $open_month, $open_day, $open_year);
    }
    
    // Process time close
    $close_day = optional_param('close_day', 0, PARAM_INT);
    $close_month = optional_param('close_month', 0, PARAM_INT);
    $close_year = optional_param('close_year', 0, PARAM_INT);
    $close_hour = optional_param('close_hour', 0, PARAM_INT);
    $close_minute = optional_param('close_minute', 0, PARAM_INT);
    
    if ($close_day && $close_month && $close_year) {
        $timeclose = mktime($close_hour, $close_minute, 0, $close_month, $close_day, $close_year);
    }
    
    // Grade settings
    $grademethod = optional_param('grademethod', 1, PARAM_INT);
    $grade = optional_param('grade', 100, PARAM_FLOAT);
    $decimalpoints = optional_param('decimalpoints', 2, PARAM_INT);
    $questiondecimalpoints = optional_param('questiondecimalpoints', -1, PARAM_INT);
    $attempts = optional_param('attempts', 0, PARAM_INT);
    
    // Behavior settings
    $preferredbehaviour = optional_param('preferredbehaviour', 'deferredfeedback', PARAM_ALPHANUMEXT);
    $shuffleanswers = optional_param('shuffleanswers', 0, PARAM_INT);
    $navmethod = optional_param('navmethod', 'free', PARAM_ALPHANUMEXT);
    $questionsperpage = optional_param('questionsperpage', 1, PARAM_INT);
    
    error_log("Behavior settings - preferredbehaviour: $preferredbehaviour, navmethod: $navmethod, shuffleanswers: $shuffleanswers");
    
    // Review options - Process bit fields
    $review_options = [
        'reviewattempt' => 0,
        'reviewcorrectness' => 0,
        'reviewmarks' => 0,
        'reviewspecificfeedback' => 0,
        'reviewgeneralfeedback' => 0,
        'reviewrightanswer' => 0,
        'reviewoverallfeedback' => 0
    ];
    
    // Timing constants for review options
    $timings = ['during' => 0x10000, 'immediate' => 0x01, 'open' => 0x100, 'closed' => 0x1000];
    
    foreach ($review_options as $option => $value) {
        foreach ($timings as $timing => $bit) {
            if (optional_param("{$option}_{$timing}", 0, PARAM_INT)) {
                $review_options[$option] |= $bit;
            }
        }
    }
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);
    
    error_log("Starting database transaction");
    
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    error_log("Creating quiz record");
    
    // Create quiz record
    $quiz = new stdClass();
    $quiz->course = $courseid;
    $quiz->name = $name;
    $quiz->intro = $intro;
    $quiz->introformat = FORMAT_HTML;
    $quiz->timeopen = $timeopen;
    $quiz->timeclose = $timeclose;
    $quiz->timelimit = $timelimit;
    $quiz->overduehandling = 'autosubmit';
    $quiz->graceperiod = 0;
    $quiz->preferredbehaviour = $preferredbehaviour;
    $quiz->canredoquestions = 0;
    $quiz->attempts = $attempts;
    $quiz->attemptonlast = 0;
    $quiz->grademethod = $grademethod;
    $quiz->decimalpoints = $decimalpoints;
    $quiz->questiondecimalpoints = $questiondecimalpoints;
    $quiz->reviewattempt = $review_options['reviewattempt'];
    $quiz->reviewcorrectness = $review_options['reviewcorrectness'];
    $quiz->reviewmarks = $review_options['reviewmarks'];
    $quiz->reviewmaxmarks = $review_options['reviewmarks']; // Same as reviewmarks
    $quiz->reviewspecificfeedback = $review_options['reviewspecificfeedback'];
    $quiz->reviewgeneralfeedback = $review_options['reviewgeneralfeedback'];
    $quiz->reviewrightanswer = $review_options['reviewrightanswer'];
    $quiz->reviewoverallfeedback = $review_options['reviewoverallfeedback'];
    $quiz->questionsperpage = $questionsperpage;
    $quiz->navmethod = $navmethod;
    $quiz->shuffleanswers = $shuffleanswers;
    $quiz->sumgrades = 0; // Will be calculated when questions are added
    $quiz->grade = $grade;
    $quiz->timecreated = time();
    $quiz->timemodified = time();
    $quiz->completionattemptsexhausted = 0;
    $quiz->completionminattempts = 0;
    $quiz->allowofflineattempts = 0;
    
    error_log("Inserting quiz record into database");
    error_log("Quiz data: " . print_r($quiz, true));
    
    $quizid = $DB->insert_record('quiz', $quiz);
    
    if (!$quizid) {
        throw new Exception('Failed to create quiz record');
    }
    
    error_log("Quiz created successfully with ID: $quizid");
    
    // Create course module
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
    if (!$moduleid) {
        throw new Exception('Quiz module not found');
    }
    
    // Get course section
    $coursesection = $DB->get_record('course_sections', [
        'id' => $section,
        'course' => $courseid
    ]);
    
    if (!$coursesection) {
        throw new Exception('Invalid section ID');
    }
    
    // Create course module record
    $coursemodule = new stdClass();
    $coursemodule->course = $courseid;
    $coursemodule->module = $moduleid;
    $coursemodule->instance = $quizid;
    $coursemodule->section = 0;
    $coursemodule->idnumber = '';
    $coursemodule->added = time();
    $coursemodule->score = 0;
    $coursemodule->indent = 0;
    $coursemodule->visible = 1;
    $coursemodule->visibleoncoursepage = 1;
    $coursemodule->visibleold = 1;
    $coursemodule->groupmode = 0;
    $coursemodule->groupingid = 0;
    $coursemodule->completion = 0;
    $coursemodule->completionview = 0;
    $coursemodule->completionexpected = 0;
    $coursemodule->showdescription = 0;
    $coursemodule->availability = null;
    $coursemodule->deletioninprogress = 0;
    
    $coursemoduleid = $DB->insert_record('course_modules', $coursemodule);
    
    // Add to section
    $sectionid = course_add_cm_to_section($courseid, $coursemoduleid, $coursesection->section);
    $DB->set_field('course_modules', 'section', $sectionid, ['id' => $coursemoduleid]);
    
    // Get module context
    $modulecontext = context_module::instance($coursemoduleid);
    
    // Process questions
    $questions_json = optional_param('questions_data', '', PARAM_RAW);
    if (!empty($questions_json)) {
        $questions_data = json_decode($questions_json, true);
        
        if (is_array($questions_data)) {
            $slot_number = 1;
            $total_marks = 0;
            
            foreach ($questions_data as $question_data) {
                // Create quiz slot
                $slot = new stdClass();
                $slot->quizid = $quizid;
                $slot->slot = $slot_number;
                $slot->page = isset($question_data['page']) ? $question_data['page'] : 1;
                $slot->requireprevious = 0;
                $slot->maxmark = $question_data['defaultmark'];
                
                $slotid = $DB->insert_record('quiz_slots', $slot);
                
                if ($question_data['isNew']) {
                    // Create new question (simplified for now)
                    $questionid = createNewQuestion($question_data, $coursecontext->id);
                } else {
                    // Use existing question
                    $questionid = $question_data['id'];
                }
                
                // Create question reference
                $questionbankentryid = get_question_bank_entry($questionid)->id;
                
                $reference = new stdClass();
                $reference->usingcontextid = $modulecontext->id;
                $reference->component = 'mod_quiz';
                $reference->questionarea = 'slot';
                $reference->itemid = $slotid;
                $reference->questionbankentryid = $questionbankentryid;
                $reference->version = null; // Use latest
                
                $DB->insert_record('question_references', $reference);
                
                $total_marks += $slot->maxmark;
                $slot_number++;
            }
            
            // Update quiz sumgrades
            $DB->set_field('quiz', 'sumgrades', $total_marks, ['id' => $quizid]);
        }
    }
    
    // Create default first section
    $section = new stdClass();
    $section->quizid = $quizid;
    $section->firstslot = 1;
    $section->heading = '';
    $section->shufflequestions = 0;
    $DB->insert_record('quiz_sections', $section);
    
    // Handle group assignment
    $assign_to = optional_param('assign_to', 'all', PARAM_ALPHA);
    $group_ids = optional_param_array('group_ids', [], PARAM_INT);
    
    if ($assign_to === 'groups' && !empty($group_ids)) {
        $availability_conditions = [];
        foreach ($group_ids as $groupid) {
            $availability_conditions[] = [
                'type' => 'group',
                'id' => (int)$groupid
            ];
        }
        
        $availability = [
            'op' => '|',
            'c' => $availability_conditions,
            'show' => false
        ];
        
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $coursemoduleid]);
    }
    
    // Link competencies
    $competencies = optional_param_array('competencies', [], PARAM_INT);
    if (!empty($competencies)) {
        $sortorder = 0;
        foreach ($competencies as $competencyid) {
            $modulecomp = new stdClass();
            $modulecomp->cmid = $coursemoduleid;
            $modulecomp->competencyid = $competencyid;
            $modulecomp->timecreated = time();
            $modulecomp->timemodified = time();
            $modulecomp->usermodified = $USER->id;
            $modulecomp->sortorder = $sortorder++;
            $modulecomp->ruleoutcome = 0;
            $modulecomp->overridegrade = 0;
            
            $DB->insert_record('competency_modulecomp', $modulecomp);
        }
    }
    
    // Create grade item
    grade_update('mod/quiz', $courseid, 'mod', 'quiz', $quizid, 0, null, ['itemname' => $name]);
    
    // Rebuild course cache
    rebuild_course_cache($courseid, true);
    cache_helper::purge_by_event('changesincourse');
    get_fast_modinfo($courseid, 0, true);
    
    // Commit transaction
    $transaction->allow_commit();
    
    error_log("Quiz creation completed successfully");
    
    // Clean output buffer before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Quiz created successfully',
        'quiz_id' => $quizid,
        'course_module_id' => $coursemoduleid,
        'url' => $CFG->wwwroot . '/mod/quiz/view.php?id=' . $coursemoduleid
    ]);
    
} catch (Exception $e) {
    if (isset($transaction) && $transaction) {
        try {
            $transaction->rollback($e);
        } catch (Exception $rollback_error) {
            error_log("Transaction rollback error: " . $rollback_error->getMessage());
        }
    }
    
    error_log("=== Quiz Creation Error ===");
    error_log("Error message: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    
    // Clean output buffer before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => 'Error creating quiz: ' . $e->getMessage()
    ]);
}

// Flush and end output buffering
ob_end_flush();

/**
 * Helper function to create a unique ID code
 */
function generate_unique_stamp() {
    if (function_exists('make_unique_id_code')) {
        return make_unique_id_code();
    }
    // Fallback: create a unique stamp
    return md5(uniqid(rand(), true));
}


/**
 * Helper function to create a new question
 */
function createNewQuestion($question_data, $contextid) {
    global $DB, $USER;
    
    error_log("Creating new question: " . $question_data['name']);
    
    // Get or create default category for course
    $category = $DB->get_record_sql("
        SELECT * FROM {question_categories}
        WHERE contextid = ? AND parent = 0
        LIMIT 1
    ", [$contextid]);
    
    if (!$category) {
        error_log("Creating default question category for context: $contextid");
        // Create default category
        $category = new stdClass();
        $category->name = 'Default';
        $category->contextid = $contextid;
        $category->info = 'Default category';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = generate_unique_stamp();
        $category->parent = 0;
        $category->sortorder = 999;
        $category->idnumber = null;
        $category->id = $DB->insert_record('question_categories', $category);
        error_log("Created category with ID: " . $category->id);
    }
    
    // Create question
    $question = new stdClass();
    $question->category = $category->id;
    $question->parent = 0;
    $question->name = $question_data['name'];
    $question->questiontext = $question_data['questiontext'];
    $question->questiontextformat = FORMAT_HTML;
    $question->generalfeedback = '';
    $question->generalfeedbackformat = FORMAT_HTML;
    $question->defaultmark = $question_data['defaultmark'];
    $question->penalty = 0.3333333;
    $question->qtype = $question_data['qtype'];
    $question->length = 1;
    $question->stamp = generate_unique_stamp();
    $question->timecreated = time();
    $question->timemodified = time();
    $question->createdby = $USER->id;
    $question->modifiedby = $USER->id;
    
    $questionid = $DB->insert_record('question', $question);
    error_log("Created question with ID: " . $questionid);
    
    // Create question bank entry
    $entry = new stdClass();
    $entry->questioncategoryid = $category->id;
    $entry->idnumber = null;
    $entry->ownerid = $USER->id;
    
    $entryid = $DB->insert_record('question_bank_entries', $entry);
    
    // Create question version
    $version = new stdClass();
    $version->questionbankentryid = $entryid;
    $version->version = 1;
    $version->questionid = $questionid;
    $version->status = 'ready';
    
    $DB->insert_record('question_versions', $version);
    
    // Save type-specific data
    switch ($question_data['qtype']) {
        case 'multichoice':
            if (isset($question_data['answers'])) {
                foreach ($question_data['answers'] as $answer_data) {
                    $answer = new stdClass();
                    $answer->question = $questionid;
                    $answer->answer = $answer_data['text'];
                    $answer->answerformat = FORMAT_HTML;
                    $answer->fraction = $answer_data['fraction'];
                    $answer->feedback = '';
                    $answer->feedbackformat = FORMAT_HTML;
                    
                    $DB->insert_record('question_answers', $answer);
                }
                
                // Create multichoice options
                $options = new stdClass();
                $options->questionid = $questionid;
                $options->single = 1;
                $options->shuffleanswers = 1;
                $options->answernumbering = 'abc';
                $options->correctfeedback = 'Correct!';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->shownumcorrect = 1;
                
                $DB->insert_record('qtype_multichoice_options', $options);
            }
            break;
            
        case 'truefalse':
            if (isset($question_data['answer'])) {
                $correct_answer = $question_data['answer'];
                
                // True answer
                $true_answer = new stdClass();
                $true_answer->question = $questionid;
                $true_answer->answer = 'True';
                $true_answer->answerformat = FORMAT_MOODLE;
                $true_answer->fraction = $correct_answer == 1 ? 1 : 0;
                $true_answer->feedback = '';
                $true_answer->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $true_answer);
                
                // False answer
                $false_answer = new stdClass();
                $false_answer->question = $questionid;
                $false_answer->answer = 'False';
                $false_answer->answerformat = FORMAT_MOODLE;
                $false_answer->fraction = $correct_answer == 0 ? 1 : 0;
                $false_answer->feedback = '';
                $false_answer->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $false_answer);
            }
            break;
            
        case 'shortanswer':
            if (isset($question_data['answers'])) {
                foreach ($question_data['answers'] as $answer_data) {
                    $answer = new stdClass();
                    $answer->question = $questionid;
                    $answer->answer = $answer_data['text'];
                    $answer->answerformat = FORMAT_MOODLE;
                    $answer->fraction = 1;
                    $answer->feedback = '';
                    $answer->feedbackformat = FORMAT_HTML;
                    
                    $DB->insert_record('question_answers', $answer);
                }
                
                // Create shortanswer options
                $options = new stdClass();
                $options->questionid = $questionid;
                $options->usecase = isset($question_data['caseSensitive']) && $question_data['caseSensitive'] ? 1 : 0;
                
                $DB->insert_record('qtype_shortanswer_options', $options);
            }
            break;
            
        case 'essay':
            $options = new stdClass();
            $options->questionid = $questionid;
            $options->responseformat = 'editor';
            $options->responserequired = 1;
            $options->responsefieldlines = 15;
            $options->minwordlimit = isset($question_data['minWords']) ? $question_data['minWords'] : null;
            $options->maxwordlimit = isset($question_data['maxWords']) ? $question_data['maxWords'] : null;
            $options->attachments = isset($question_data['attachments']) ? $question_data['attachments'] : 0;
            $options->attachmentsrequired = 0;
            $options->maxbytes = 0;
            $options->filetypeslist = null;
            $options->responsetemplate = '';
            $options->responsetemplateformat = FORMAT_HTML;
            $options->graderinfo = '';
            $options->graderinfoformat = FORMAT_HTML;
            
            $DB->insert_record('qtype_essay_options', $options);
            break;
            
        case 'numerical':
            if (isset($question_data['answer'])) {
                $answer = new stdClass();
                $answer->question = $questionid;
                $answer->answer = $question_data['answer'];
                $answer->answerformat = FORMAT_MOODLE;
                $answer->fraction = 1;
                $answer->feedback = '';
                $answer->feedbackformat = FORMAT_HTML;
                
                $answerid = $DB->insert_record('question_answers', $answer);
                
                // Create numerical option
                $numoptions = new stdClass();
                $numoptions->question = $questionid;
                $numoptions->answer = $answerid;
                $numoptions->tolerance = isset($question_data['tolerance']) ? $question_data['tolerance'] : 0;
                
                $DB->insert_record('question_numerical', $numoptions);
            }
            break;

        case 'gapselect':
            $gapdata = $question_data['gapselect'] ?? [];
            $groups = isset($gapdata['groups']) && is_array($gapdata['groups']) ? $gapdata['groups'] : [];
            if (!empty($groups)) {
                $options = new stdClass();
                $options->questionid = $questionid;
                $options->shuffleanswers = !empty($gapdata['shuffle']) ? 1 : 0;
                $options->correctfeedback = 'Correct!';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->shownumcorrect = 0;
                $DB->insert_record('question_gapselect', $options);

                $groupIndex = 1;
                foreach ($groups as $group) {
                    $correct = trim($group['correct'] ?? '');
                    if ($correct === '') {
                        $groupIndex++;
                        continue;
                    }
                    $choicegroup = $groupIndex;
                    $choices = [$correct];
                    if (!empty($group['distractors']) && is_array($group['distractors'])) {
                        foreach ($group['distractors'] as $distractor) {
                            $clean = trim($distractor);
                            if ($clean !== '') {
                                $choices[] = $clean;
                            }
                        }
                    }
                    $choiceIndex = 0;
                        foreach ($choices as $choiceText) {
                        $answer = new stdClass();
                        $answer->question = $questionid;
                        $answer->answer = $choiceText;
                        $answer->answerformat = FORMAT_HTML;
                        $answer->fraction = $choiceIndex === 0 ? 1 : 0;
                            $answer->feedback = (string)$choicegroup;
                        $answer->feedbackformat = FORMAT_HTML;
                        $DB->insert_record('question_answers', $answer);
                        $choiceIndex++;
                    }
                    $groupIndex++;
                }
            }
            break;
            
        case 'ddwtos':
            $ddwtosdata = $question_data['ddwtos'] ?? [];
            $groups = isset($ddwtosdata['groups']) && is_array($ddwtosdata['groups']) ? $ddwtosdata['groups'] : [];
            if (!empty($groups)) {
                $options = new stdClass();
                $options->questionid = $questionid;
                $options->shuffleanswers = !empty($ddwtosdata['shuffle']) ? 1 : 0;
                $options->correctfeedback = 'Correct!';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->shownumcorrect = 0;
                $DB->insert_record('question_ddwtos', $options);

                foreach ($groups as $group) {
                    $slot = isset($group['slot']) && is_numeric($group['slot']) ? (int)$group['slot'] : null;
                    if ($slot === null || $slot < 1) {
                        continue; // Skip invalid groups
                    }
                    $correct = trim($group['correct'] ?? '');
                    if ($correct === '') {
                        continue;
                    }
                    $choices = [$correct];
                    if (!empty($group['distractors']) && is_array($group['distractors'])) {
                        foreach ($group['distractors'] as $distractor) {
                            $clean = trim($distractor);
                            if ($clean !== '') {
                                $choices[] = $clean;
                            }
                        }
                    }
                    $choiceIndex = 0;
                    foreach ($choices as $choiceText) {
                        $answer = new stdClass();
                        $answer->question = $questionid;
                        $answer->answer = $choiceText;
                        $answer->answerformat = FORMAT_HTML;
                        $answer->fraction = $choiceIndex === 0 ? 1 : 0;
                        $answer->feedback = remui_kids_serialize_ddwtos_feedback($slot);
                        $answer->feedbackformat = FORMAT_HTML;
                        $DB->insert_record('question_answers', $answer);
                        $choiceIndex++;
                    }
                }
            }
            break;
            
        case 'ddimageortext':
            $ddimagedata = $question_data['ddimageortext'] ?? [];
            $drops = isset($ddimagedata['drops']) && is_array($ddimagedata['drops']) ? $ddimagedata['drops'] : [];
            if (!empty($drops)) {
                $options = new stdClass();
                $options->questionid = $questionid;
                $options->shuffleanswers = !empty($ddimagedata['shuffle']) ? 1 : 0;
                $options->correctfeedback = 'Correct!';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->shownumcorrect = 0;
                $DB->insert_record('qtype_ddimageortext', $options);

                // Collect all unique draggable items (correct answers + distractors)
                $allDrags = [];
                $dragIndex = 1;
                foreach ($drops as $drop) {
                    $correct = trim($drop['correct'] ?? '');
                    if ($correct !== '' && !in_array($correct, $allDrags, true)) {
                        $allDrags[] = $correct;
                    }
                    if (!empty($drop['distractors']) && is_array($drop['distractors'])) {
                        foreach ($drop['distractors'] as $distractor) {
                            $clean = trim($distractor);
                            if ($clean !== '' && !in_array($clean, $allDrags, true)) {
                                $allDrags[] = $clean;
                            }
                        }
                    }
                }

                // Insert draggable items
                foreach ($allDrags as $dragText) {
                    $drag = new stdClass();
                    $drag->questionid = $questionid;
                    $drag->no = $dragIndex;
                    $drag->draggroup = 1;
                    $drag->infinite = 0;
                    $drag->label = $dragText;
                    $DB->insert_record('qtype_ddimageortext_drags', $drag);
                    $dragIndex++;
                }

                // Insert drop zones (with default positions - user will need to position manually in Moodle)
                $dropIndex = 1;
                foreach ($drops as $drop) {
                    $correct = trim($drop['correct'] ?? '');
                    if ($correct === '') {
                        continue;
                    }
                    $dragNo = array_search($correct, $allDrags, true);
                    if ($dragNo === false) {
                        continue;
                    }
                    $dropzone = new stdClass();
                    $dropzone->questionid = $questionid;
                    $dropzone->no = $dropIndex;
                    $dropzone->xleft = 50 + ($dropIndex * 100); // Default positions
                    $dropzone->ytop = 50 + ($dropIndex * 50);
                    $dropzone->choice = $dragNo + 1;
                    $dropzone->label = trim($drop['label'] ?? "Zone {$dropIndex}");
                    $DB->insert_record('qtype_ddimageortext_drops', $dropzone);
                    $dropIndex++;
                }
            }
            break;
            
        case 'match':
            if (!empty($question_data['pairs']) && is_array($question_data['pairs'])) {
                foreach ($question_data['pairs'] as $pair) {
                    $questiontext = trim($pair['question'] ?? '');
                    $answertext = trim($pair['answer'] ?? '');
                    if ($questiontext === '' || $answertext === '') {
                        continue;
                    }
                    $subq = new stdClass();
                    $subq->questionid = $questionid;
                    $subq->questiontext = $questiontext;
                    $subq->questiontextformat = FORMAT_HTML;
                    $subq->answertext = $answertext;
                    
                    $DB->insert_record('qtype_match_subquestions', $subq);
                }
                
                // Create match options
                $matchoptions = new stdClass();
                $matchoptions->questionid = $questionid;
                $matchoptions->shuffleanswers = 1;
                $matchoptions->correctfeedback = 'Correct!';
                $matchoptions->correctfeedbackformat = FORMAT_HTML;
                $matchoptions->partiallycorrectfeedback = '';
                $matchoptions->partiallycorrectfeedbackformat = FORMAT_HTML;
                $matchoptions->incorrectfeedback = '';
                $matchoptions->incorrectfeedbackformat = FORMAT_HTML;
                $matchoptions->shownumcorrect = 1;
                
                $DB->insert_record('qtype_match_options', $matchoptions);
            }
            break;

        case 'ordering':
            $items = [];
            if (!empty($question_data['items']) && is_array($question_data['items'])) {
                foreach ($question_data['items'] as $item) {
                    $text = is_array($item) ? ($item['text'] ?? '') : $item;
                    $text = trim((string)$text);
                    if ($text !== '') {
                        $items[] = $text;
                    }
                }
            }
            if (count($items) < 2) {
                break;
            }
            foreach ($items as $index => $text) {
                $answer = new stdClass();
                $answer->question = $questionid;
                $answer->answer = $text;
                $answer->answerformat = FORMAT_HTML;
                $answer->fraction = $index + 1;
                $answer->feedback = '';
                $answer->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $answer);
            }

            $orderingoptions = new stdClass();
            $orderingoptions->questionid = $questionid;
            $orderingoptions->layouttype = 0;
            $orderingoptions->selecttype = 0;
            $orderingoptions->selectcount = max(2, count($items));
            $orderingoptions->gradingtype = 0;
            $orderingoptions->showgrading = 0;
            $orderingoptions->numberingstyle = 'none';
            $orderingoptions->correctfeedback = '';
            $orderingoptions->correctfeedbackformat = FORMAT_HTML;
            $orderingoptions->incorrectfeedback = '';
            $orderingoptions->incorrectfeedbackformat = FORMAT_HTML;
            $orderingoptions->partiallycorrectfeedback = '';
            $orderingoptions->partiallycorrectfeedbackformat = FORMAT_HTML;
            $orderingoptions->shownumcorrect = 0;
            $DB->insert_record('qtype_ordering_options', $orderingoptions);
            break;
            
        case 'description':
            // Description questions don't need any additional data
            break;
            
        case 'multianswer':
            // Cloze questions are parsed from the questiontext
            // No additional tables needed
            break;
    }
    
    return $questionid;
}
