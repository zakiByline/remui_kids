<?php
// This file is part of Moodle - https://moodle.org/
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
 * Update existing quiz handler (theme_remui_kids)
 *
 * Accepts the same payload as create_quiz.php but updates an existing
 * quiz, its slots, group availability and competency links.
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->libdir . '/questionlib.php');
require_once(__DIR__ . '/includes/question_helpers.php');

if (!function_exists('generate_unique_stamp')) {
    function generate_unique_stamp() {
        if (function_exists('make_unique_id_code')) {
            return make_unique_id_code();
        }
        return md5(uniqid(rand(), true));
    }
}

if (!function_exists('course_change_cm_section')) {
    /**
     * Minimal fallback for moving a course module between sections when the core helper is unavailable.
     */
    function course_change_cm_section($courseid, $cmid, $newsectionnumber) {
        global $DB;

        $cm = get_coursemodule_from_id(null, $cmid, $courseid, false, MUST_EXIST);
        $targetsection = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $newsectionnumber
        ], '*', MUST_EXIST);

        if ((int)$cm->section === (int)$targetsection->id) {
            return;
        }

        if (function_exists('moveto_module')) {
            moveto_module($cm, $targetsection);
        } else {
            // Fall back to directly updating the course_modules table and section sequence.
            $DB->set_field('course_modules', 'section', $targetsection->id, ['id' => $cmid]);
            rebuild_course_cache($courseid, true);
        }
    }
}

if (!function_exists('createNewQuestion')) {
    function createNewQuestion(array $question_data, $contextid) {
        global $DB, $USER;

        $category = $DB->get_record_sql("SELECT * FROM {question_categories} WHERE contextid = ? AND parent = 0 ORDER BY id ASC", [$contextid]);
        if (!$category) {
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
        }

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

        $entry = new stdClass();
        $entry->questioncategoryid = $category->id;
        $entry->idnumber = null;
        $entry->ownerid = $USER->id;
        $entryid = $DB->insert_record('question_bank_entries', $entry);

        $version = new stdClass();
        $version->questionbankentryid = $entryid;
        $version->version = 1;
        $version->questionid = $questionid;
        $version->status = 'ready';
        $DB->insert_record('question_versions', $version);

        switch ($question_data['qtype']) {
            case 'multichoice':
                if (!empty($question_data['answers'])) {
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
                $selected = isset($question_data['answer']) ? (string)$question_data['answer'] : '1';
                $true_answer = new stdClass();
                $true_answer->question = $questionid;
                $true_answer->answer = get_string('true', 'question');
                $true_answer->answerformat = FORMAT_MOODLE;
                $true_answer->fraction = $selected === '1' ? 1 : 0;
                $true_answer->feedback = '';
                $true_answer->feedbackformat = FORMAT_HTML;
                $trueanswerid = $DB->insert_record('question_answers', $true_answer);

                $false_answer = new stdClass();
                $false_answer->question = $questionid;
                $false_answer->answer = get_string('false', 'question');
                $false_answer->answerformat = FORMAT_MOODLE;
                $false_answer->fraction = $selected === '0' ? 1 : 0;
                $false_answer->feedback = '';
                $false_answer->feedbackformat = FORMAT_HTML;
                $falseanswerid = $DB->insert_record('question_answers', $false_answer);

                $tfoptions = new stdClass();
                $tfoptions->question = $questionid;
                $tfoptions->trueanswer = $trueanswerid;
                $tfoptions->falseanswer = $falseanswerid;
                $DB->insert_record('question_truefalse', $tfoptions);
                break;

            case 'shortanswer':
                if (!empty($question_data['answers'])) {
                    foreach ($question_data['answers'] as $answer_data) {
                        $answer = new stdClass();
                        $answer->question = $questionid;
                        $answer->answer = $answer_data['text'];
                        $answer->answerformat = FORMAT_MOODLE;
                        $answer->fraction = isset($answer_data['fraction']) ? $answer_data['fraction'] : 1;
                        $answer->feedback = '';
                        $answer->feedbackformat = FORMAT_HTML;
                        $DB->insert_record('question_answers', $answer);
                    }
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

                    $numoptions = new stdClass();
                    $numoptions->question = $questionid;
                    $numoptions->answer = $answerid;
                    $numoptions->tolerance = isset($question_data['tolerance']) ? $question_data['tolerance'] : 0;
                    $DB->insert_record('question_numerical', $numoptions);

                if (array_key_exists('unit', $question_data) && $question_data['unit'] !== '') {
                    $unitrecord = new stdClass();
                    $unitrecord->question = $questionid;
                    $unitrecord->multiplier = 1;
                    $unitrecord->unit = $question_data['unit'];
                    $DB->insert_record('question_numerical_units', $unitrecord);
                }
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
                    // Delete existing data
                    $DB->delete_records('question_ddwtos', ['questionid' => $questionid]);
                    $DB->delete_records('question_answers', ['question' => $questionid]);

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
                    // Delete existing data
                    $DB->delete_records('qtype_ddimageortext', ['questionid' => $questionid]);
                    $DB->delete_records('qtype_ddimageortext_drops', ['questionid' => $questionid]);
                    $DB->delete_records('qtype_ddimageortext_drags', ['questionid' => $questionid]);

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

                    // Collect all unique draggable items
                    $allDrags = [];
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
                    $dragIndex = 1;
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

                    // Insert drop zones
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
                        $dropzone->xleft = 50 + ($dropIndex * 100);
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
            // No type-specific data required.
            break;

        case 'multianswer':
            // Cloze questions rely on questiontext content only.
                break;
        }

        return $questionid;
    }
}

header('Content-Type: application/json');

try {
    require_login();

    $sesskey = optional_param('sesskey', null, PARAM_RAW);
    if (!empty($sesskey) && !confirm_sesskey($sesskey)) {
        throw new moodle_exception('invalidsesskey', 'error');
    }

    $quizid = required_param('quiz_id', PARAM_INT);
    $cmid = required_param('cmid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $sectionid = required_param('section', PARAM_INT);

    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    if ((int)$cm->instance !== $quizid) {
        throw new moodle_exception('invalidcoursemodule', 'error');
    }

    if ((int)$cm->course !== $courseid) {
        throw new moodle_exception('invalidcourseid', 'error');
    }

    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);

    $modulecontext = context_module::instance($cmid);

    $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

    $name = required_param('name', PARAM_TEXT);
    $intro = optional_param('intro', '', PARAM_RAW);

    $timelimit = optional_param('timelimit', 0, PARAM_INT) * 60;

    $timeopen = 0;
    $open_day = optional_param('open_day', 0, PARAM_INT);
    $open_month = optional_param('open_month', 0, PARAM_INT);
    $open_year = optional_param('open_year', 0, PARAM_INT);
    $open_hour = optional_param('open_hour', 0, PARAM_INT);
    $open_minute = optional_param('open_minute', 0, PARAM_INT);
    if ($open_day && $open_month && $open_year) {
        $timeopen = mktime($open_hour, $open_minute, 0, $open_month, $open_day, $open_year);
    }

    $timeclose = 0;
    $close_day = optional_param('close_day', 0, PARAM_INT);
    $close_month = optional_param('close_month', 0, PARAM_INT);
    $close_year = optional_param('close_year', 0, PARAM_INT);
    $close_hour = optional_param('close_hour', 0, PARAM_INT);
    $close_minute = optional_param('close_minute', 0, PARAM_INT);
    if ($close_day && $close_month && $close_year) {
        $timeclose = mktime($close_hour, $close_minute, 0, $close_month, $close_day, $close_year);
    }

    $grademethod = optional_param('grademethod', 1, PARAM_INT);
    $grade = optional_param('grade', 100, PARAM_FLOAT);
    $decimalpoints = optional_param('decimalpoints', 2, PARAM_INT);
    $questiondecimalpoints = optional_param('questiondecimalpoints', -1, PARAM_INT);
    $attempts = optional_param('attempts', 0, PARAM_INT);

    $preferredbehaviour = optional_param('preferredbehaviour', 'deferredfeedback', PARAM_ALPHANUMEXT);
    $shuffleanswers = optional_param('shuffleanswers', 0, PARAM_INT);
    $navmethod = optional_param('navmethod', 'free', PARAM_ALPHANUMEXT);
    $questionsperpage = optional_param('questionsperpage', 1, PARAM_INT);

    $review_options = [
        'reviewattempt' => 0,
        'reviewcorrectness' => 0,
        'reviewmarks' => 0,
        'reviewspecificfeedback' => 0,
        'reviewgeneralfeedback' => 0,
        'reviewrightanswer' => 0,
        'reviewoverallfeedback' => 0
    ];
    $timings = ['during' => 0x10000, 'immediate' => 0x01, 'open' => 0x100, 'closed' => 0x1000];
    foreach ($review_options as $option => $value) {
        foreach ($timings as $timing => $bit) {
            if (optional_param("{$option}_{$timing}", 0, PARAM_INT)) {
                $review_options[$option] |= $bit;
            }
        }
    }

    $assign_to = optional_param('assign_to', 'all', PARAM_ALPHA);
    $group_ids = optional_param_array('group_ids', [], PARAM_INT);
    $competencies = optional_param_array('competencies', [], PARAM_INT);

    $questions_json = optional_param('questions_data', '', PARAM_RAW);
    $questions_data = [];
    if (!empty($questions_json)) {
        $decoded = json_decode($questions_json, true);
        if (is_array($decoded)) {
            $questions_data = $decoded;
        }
    }

    $transaction = $DB->start_delegated_transaction();

    $quizupdate = new stdClass();
    $quizupdate->id = $quizid;
    $quizupdate->name = $name;
    $quizupdate->intro = $intro;
    $quizupdate->introformat = FORMAT_HTML;
    $quizupdate->timeopen = $timeopen;
    $quizupdate->timeclose = $timeclose;
    $quizupdate->timelimit = $timelimit;
    $quizupdate->grademethod = $grademethod;
    $quizupdate->grade = $grade;
    $quizupdate->decimalpoints = $decimalpoints;
    $quizupdate->questiondecimalpoints = $questiondecimalpoints;
    $quizupdate->attempts = $attempts;
    $quizupdate->preferredbehaviour = $preferredbehaviour;
    $quizupdate->shuffleanswers = $shuffleanswers;
    $quizupdate->navmethod = $navmethod;
    $quizupdate->questionsperpage = $questionsperpage;
    $quizupdate->reviewattempt = $review_options['reviewattempt'];
    $quizupdate->reviewcorrectness = $review_options['reviewcorrectness'];
    $quizupdate->reviewmarks = $review_options['reviewmarks'];
    $quizupdate->reviewmaxmarks = $review_options['reviewmarks'];
    $quizupdate->reviewspecificfeedback = $review_options['reviewspecificfeedback'];
    $quizupdate->reviewgeneralfeedback = $review_options['reviewgeneralfeedback'];
    $quizupdate->reviewrightanswer = $review_options['reviewrightanswer'];
    $quizupdate->reviewoverallfeedback = $review_options['reviewoverallfeedback'];
    $quizupdate->timemodified = time();

    $DB->update_record('quiz', $quizupdate);

    $targetsection = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid], 'id, section', MUST_EXIST);
    if ((int)$cm->section !== (int)$sectionid) {
        course_change_cm_section($courseid, $cmid, (int)$targetsection->section);
        $cm->section = $sectionid;
    }

    $slotids = $DB->get_fieldset_select('quiz_slots', 'id', 'quizid = ?', [$quizid]);
    if (!empty($slotids)) {
        list($insql, $params) = $DB->get_in_or_equal($slotids, SQL_PARAMS_NAMED);
        $params['component'] = 'mod_quiz';
        $params['questionarea'] = 'slot';
        $DB->delete_records_select('question_references', "component = :component AND questionarea = :questionarea AND itemid $insql", $params);
    }

    $DB->delete_records('quiz_slots', ['quizid' => $quizid]);
    $DB->delete_records('quiz_sections', ['quizid' => $quizid]);

    $total_marks = 0;
    $slot_number = 1;

    foreach ($questions_data as $question_data) {
        $slot = new stdClass();
        $slot->quizid = $quizid;
        $slot->slot = $slot_number++;
        $slot->page = isset($question_data['page']) ? (int)$question_data['page'] : 1;
        $slot->requireprevious = 0;
        $slot->maxmark = isset($question_data['defaultmark']) ? (float)$question_data['defaultmark'] : 1;
        $slotid = $DB->insert_record('quiz_slots', $slot);

        $needsclone = !empty($question_data['isNew']);
        $questionid = null;
        if (!$needsclone && !empty($question_data['id'])) {
            $questionsummary = $DB->get_record('question', ['id' => (int)$question_data['id']]);
            if ($questionsummary) {
                $needsclone = true;
                $question_data = theme_remui_kids_prepare_question_clone_payload($question_data, $questionsummary->qtype);
            } else {
                $questionid = (int)$question_data['id'];
            }
        }
        if ($needsclone) {
            $questionid = createNewQuestion($question_data, $coursecontext->id);
        }

        $questionbankentryid = !empty($question_data['questionbankentryid']) ? (int)$question_data['questionbankentryid'] : null;
        $entry = null;
        if ($questionbankentryid) {
            $entry = $DB->get_record('question_bank_entries', ['id' => $questionbankentryid]);
        }
        if (!$entry) {
            $entry = theme_remui_kids_get_question_bank_entry($questionid);
        }
        if (!$entry) {
            throw new moodle_exception('questionnotfound', 'question');
        }

        $reference = new stdClass();
        $reference->usingcontextid = $modulecontext->id;
        $reference->component = 'mod_quiz';
        $reference->questionarea = 'slot';
        $reference->itemid = $slotid;
        $reference->questionbankentryid = $entry->id;
        $reference->version = null;
        $DB->insert_record('question_references', $reference);

        $total_marks += $slot->maxmark;
    }

    $section = new stdClass();
    $section->quizid = $quizid;
    $section->firstslot = $total_marks > 0 ? 1 : 1;
    $section->heading = '';
    $section->shufflequestions = 0;
    $DB->insert_record('quiz_sections', $section);

    $DB->set_field('quiz', 'sumgrades', $total_marks, ['id' => $quizid]);

    $assign_record = new stdClass();
    if ($assign_to === 'groups' && !empty($group_ids)) {
        $conditions = [];
        foreach ($group_ids as $groupid) {
            $conditions[] = ['type' => 'group', 'id' => (int)$groupid];
        }
        $availability = [
            'op' => '|',
            'c' => $conditions,
            'show' => false
        ];
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cmid]);
    } else {
        $DB->set_field('course_modules', 'availability', null, ['id' => $cmid]);
    }

    $DB->delete_records('competency_modulecomp', ['cmid' => $cmid]);
    if (!empty($competencies)) {
        $sortorder = 0;
        foreach ($competencies as $competencyid) {
            $modulecomp = new stdClass();
            $modulecomp->cmid = $cmid;
            $modulecomp->competencyid = (int)$competencyid;
            $modulecomp->timecreated = time();
            $modulecomp->timemodified = time();
            $modulecomp->usermodified = $USER->id;
            $modulecomp->sortorder = $sortorder++;
            $modulecomp->ruleoutcome = 0;
            $modulecomp->overridegrade = 0;
            $DB->insert_record('competency_modulecomp', $modulecomp);
        }
    }

    grade_update('mod/quiz', $courseid, 'mod', 'quiz', $quizid, 0, null, ['itemname' => $name]);

    rebuild_course_cache($courseid, true);
    cache_helper::purge_by_event('changesincourse');
    get_fast_modinfo($courseid, 0, true);

    $transaction->allow_commit();

    echo json_encode([
        'success' => true,
        'message' => 'Quiz updated successfully',
        'quiz_id' => $quizid,
        'course_module_id' => $cmid
    ]);
} catch (Exception $e) {
    if (!empty($transaction) && $transaction instanceof moodle_transaction) {
        try {
            $transaction->rollback($e);
        } catch (Exception $rollbackerror) {
            debugging('Quiz update rollback failed: ' . $rollbackerror->getMessage(), DEBUG_DEVELOPER);
        }
    }

    debugging('Quiz update error: ' . $e->getMessage(), DEBUG_DEVELOPER);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

