<?php
/**
 * Save New Question to Question Bank
 * Creates a new question with all required database entries
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

// Security checks
require_login();
require_sesskey();

try {
    $courseid = required_param('courseid', PARAM_INT);
    $qtype = required_param('qtype', PARAM_ALPHA);
    $questiontext = required_param('questiontext', PARAM_RAW);
    $name = required_param('name', PARAM_TEXT);
    $defaultmark = optional_param('defaultmark', 1, PARAM_FLOAT);
    $categoryid = optional_param('categoryid', 0, PARAM_INT);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/question:add', $coursecontext);
    
    // Get or create category
    if ($categoryid > 0) {
        $category = $DB->get_record('question_categories', ['id' => $categoryid]);
    } else {
        // Get default category for course
        $category = $DB->get_record_sql("
            SELECT * FROM {question_categories}
            WHERE contextid = ? AND parent = 0
            ORDER BY id ASC
            LIMIT 1
        ", [$coursecontext->id]);
        
        if (!$category) {
            // Create default category
            $category = new stdClass();
            $category->name = 'Default for ' . $course->shortname;
            $category->contextid = $coursecontext->id;
            $category->info = 'The default category for questions in this course.';
            $category->infoformat = FORMAT_HTML;
            $category->stamp = make_unique_id_code();
            $category->parent = 0;
            $category->sortorder = 999;
            $category->idnumber = null;
            $category->id = $DB->insert_record('question_categories', $category);
        }
    }
    
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    // Create question record
    $question = new stdClass();
    $question->category = $category->id;
    $question->parent = 0;
    $question->name = $name;
    $question->questiontext = $questiontext;
    $question->questiontextformat = FORMAT_HTML;
    $question->generalfeedback = '';
    $question->generalfeedbackformat = FORMAT_HTML;
    $question->defaultmark = $defaultmark;
    $question->penalty = 0.3333333;
    $question->qtype = $qtype;
    $question->length = 1;
    $question->stamp = make_unique_id_code();
    $question->timecreated = time();
    $question->timemodified = time();
    $question->createdby = $USER->id;
    $question->modifiedby = $USER->id;
    
    $questionid = $DB->insert_record('question', $question);
    
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
    switch ($qtype) {
        case 'multichoice':
            saveMultichoiceData($questionid);
            break;
        case 'truefalse':
            saveTrueFalseData($questionid);
            break;
        case 'shortanswer':
            saveShortAnswerData($questionid);
            break;
        case 'essay':
            saveEssayData($questionid);
            break;
        case 'numerical':
            saveNumericalData($questionid);
            break;
        case 'match':
            saveMatchingData($questionid);
            break;
    }
    
    $transaction->allow_commit();
    
    echo json_encode([
        'success' => true,
        'question_id' => $questionid,
        'message' => 'Question created successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($transaction)) {
        $transaction->rollback($e);
    }
    
    error_log("Question creation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function saveMultichoiceData($questionid) {
    global $DB;
    
    $answers_json = optional_param('answers', '', PARAM_RAW);
    $answers = json_decode($answers_json, true);
    
    if (is_array($answers)) {
        foreach ($answers as $answer_data) {
            $answer = new stdClass();
            $answer->question = $questionid;
            $answer->answer = $answer_data['text'];
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = $answer_data['fraction'];
            $answer->feedback = '';
            $answer->feedbackformat = FORMAT_HTML;
            
            $DB->insert_record('question_answers', $answer);
        }
    }
    
    // Create multichoice options
    $options = new stdClass();
    $options->questionid = $questionid;
    $options->single = optional_param('single', 1, PARAM_INT);
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

function saveTrueFalseData($questionid) {
    global $DB;
    
    $correct_answer = optional_param('correct_answer', 1, PARAM_INT);
    
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

function saveShortAnswerData($questionid) {
    global $DB;
    
    $answers_json = optional_param('answers', '', PARAM_RAW);
    $answers = json_decode($answers_json, true);
    
    if (is_array($answers)) {
        foreach ($answers as $answer_data) {
            $answer = new stdClass();
            $answer->question = $questionid;
            $answer->answer = $answer_data['text'];
            $answer->answerformat = FORMAT_MOODLE;
            $answer->fraction = $answer_data['fraction'];
            $answer->feedback = '';
            $answer->feedbackformat = FORMAT_HTML;
            
            $DB->insert_record('question_answers', $answer);
        }
    }
    
    // Create shortanswer options
    $options = new stdClass();
    $options->questionid = $questionid;
    $options->usecase = 0; // Case insensitive
    
    $DB->insert_record('qtype_shortanswer_options', $options);
}

function saveEssayData($questionid) {
    global $DB;
    
    // Create essay options
    $options = new stdClass();
    $options->questionid = $questionid;
    $options->responseformat = 'editor'; // HTML editor
    $options->responserequired = 1;
    $options->responsefieldlines = 15;
    $options->minwordlimit = null;
    $options->maxwordlimit = null;
    $options->attachments = 0;
    $options->attachmentsrequired = 0;
    $options->maxbytes = 0;
    $options->filetypeslist = null;
    $options->responsetemplate = '';
    $options->responsetemplateformat = FORMAT_HTML;
    $options->graderinfo = '';
    $options->graderinfoformat = FORMAT_HTML;
    
    $DB->insert_record('qtype_essay_options', $options);
}

function saveNumericalData($questionid) {
    global $DB;
    
    $answer_value = optional_param('answer_value', 0, PARAM_FLOAT);
    $tolerance = optional_param('tolerance', 0, PARAM_FLOAT);
    
    // Create answer
    $answer = new stdClass();
    $answer->question = $questionid;
    $answer->answer = $answer_value;
    $answer->answerformat = FORMAT_MOODLE;
    $answer->fraction = 1;
    $answer->feedback = '';
    $answer->feedbackformat = FORMAT_HTML;
    
    $answerid = $DB->insert_record('question_answers', $answer);
    
    // Create numerical option
    $options = new stdClass();
    $options->question = $questionid;
    $options->answer = $answerid;
    $options->tolerance = $tolerance;
    
    $DB->insert_record('question_numerical', $options);
}

function saveMatchingData($questionid) {
    global $DB;
    
    $subquestions_json = optional_param('subquestions', '', PARAM_RAW);
    $subquestions = json_decode($subquestions_json, true);
    
    if (is_array($subquestions)) {
        foreach ($subquestions as $subq) {
            $answer = new stdClass();
            $answer->question = $questionid;
            $answer->answer = $subq['answer'];
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = 0;
            $answer->feedback = '';
            $answer->feedbackformat = FORMAT_HTML;
            
            $answerid = $DB->insert_record('question_answers', $answer);
            
            // Create match subquestion
            $match = new stdClass();
            $match->questionid = $questionid;
            $match->questiontext = $subq['question'];
            $match->questiontextformat = FORMAT_HTML;
            $match->answerid = $answerid;
            
            $DB->insert_record('qtype_match_subquestions', $match);
        }
    }
    
    // Create match options
    $options = new stdClass();
    $options->questionid = $questionid;
    $options->subquestions = '';
    $options->shuffleanswers = 1;
    $options->correctfeedback = 'Correct!';
    $options->correctfeedbackformat = FORMAT_HTML;
    $options->partiallycorrectfeedback = '';
    $options->partiallycorrectfeedbackformat = FORMAT_HTML;
    $options->incorrectfeedback = '';
    $options->incorrectfeedbackformat = FORMAT_HTML;
    $options->shownumcorrect = 1;
    
    $DB->insert_record('qtype_match_options', $options);
}