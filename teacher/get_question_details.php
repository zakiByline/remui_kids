<?php
/**
 * Fetch detailed data for a specific question so the builder can prefill fields.
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/includes/question_helpers.php');

header('Content-Type: application/json');

require_login();
require_sesskey();

global $DB, $PAGE;

// Read debug parameter early so it's always available in catch block
$debug = optional_param('debug', 0, PARAM_INT) || isset($_GET['debug']) || isset($_POST['debug']);

try {
    $questionid = optional_param('questionid', 0, PARAM_INT);
    $quizid = required_param('quizid', PARAM_INT);
    $slotnum = optional_param('slot', 0, PARAM_INT);
    $cmid = required_param('cmid', PARAM_INT);

    if (!$questionid && !$slotnum) {
        throw new moodle_exception('missingparam', 'error', '', 'questionid or slot');
    }

    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);
    $systemcontext = context_system::instance();

    // Ensure PAGE context is available for format_text() and other helpers.
    if (!empty($PAGE)) {
        $PAGE->set_context($coursecontext);
    }
    if (!has_capability('moodle/course:update', $coursecontext) && !has_capability('moodle/site:config', $systemcontext)) {
        throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
    }

    $existingtables = array_flip($DB->get_tables(false));
    $tableexists = static function(string $tablename) use ($existingtables): bool {
        return isset($existingtables[$tablename]);
    };

    $question = null;
    $debugInfo = [];
    if ($questionid) {
        $question = $DB->get_record('question', ['id' => $questionid], '*', IGNORE_MISSING);
        if (!$question) {
            $debugInfo[] = "Question ID $questionid not found in question table";
        }
    }
    if (!$question) {
        $question = fetch_question_by_slot($quizid, $slotnum, $debug);
        if (!$question) {
            $debugInfo[] = "fetch_question_by_slot returned null for quizid=$quizid, slot=$slotnum";
        }
    }
    if (!$question) {
        $question = fetch_question_via_query_variants($quizid, $slotnum, $debug, $cm->course);
        if (!$question) {
            $debugInfo[] = "fetch_question_via_query_variants returned null for quizid=$quizid, slot=$slotnum";
        }
    }
    if (!$question) {
        $errorMsg = 'questionnotfound';
        if ($debug && !empty($debugInfo)) {
            $errorMsg .= ' (' . implode('; ', $debugInfo) . ')';
        }
        throw new moodle_exception($errorMsg, 'question');
    }
    $questionid = (int)$question->id;
    $entry = null;
    try {
        $entry = theme_remui_kids_get_question_bank_entry($questionid);
    } catch (Exception $entryError) {
        if ($debug) {
            $debugInfo[] = "Failed to get question bank entry: " . $entryError->getMessage();
        }
        // Continue without entry - we can still return question data
    }

    $category = $DB->get_record('question_categories', ['id' => $question->category], 'id, contextid', IGNORE_MISSING);
    if (!$category && $debug) {
        debugging('Question category ' . $question->category . ' not found; falling back to course context', DEBUG_DEVELOPER);
    }
    if ($category && $category->contextid) {
        $questioncontext = context::instance_by_id($category->contextid, MUST_EXIST);
    } else {
        $questioncontext = $coursecontext;
    }
    $context = $questioncontext;

    if (!has_capability('moodle/question:viewall', $questioncontext) && !has_capability('moodle/question:viewall', $coursecontext)) {
        if (!has_capability('moodle/course:update', $coursecontext) && !has_capability('moodle/site:config', $systemcontext)) {
            throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
        }
    }

    $formattedtext = format_text($question->questiontext, $question->questiontextformat, ['context' => $context]);
    $plainquestion = trim(html_to_text($formattedtext, 0, false));

    $result = [
        'id' => (int)$question->id,
        'name' => format_string($question->name),
        'qtype' => $question->qtype,
        'questiontext' => $plainquestion,
        'defaultmark' => (float)$question->defaultmark,
        'isNew' => false,
        'detailsLoaded' => true,
        'editable' => true,
        'questionbankentryid' => $entry ? (int)$entry->id : null,
    ];

    switch ($question->qtype) {
        case 'multichoice':
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
            $result['answers'] = array_map(static function($answer) use ($context) {
                $answertext = format_text($answer->answer, $answer->answerformat, ['context' => $context]);
                $feedback = format_text($answer->feedback, $answer->feedbackformat, ['context' => $context]);
                return [
                    'text' => trim(html_to_text($answertext, 0, false)),
                    'iscorrect' => ((float)$answer->fraction) > 0,
                    'fraction' => (float)$answer->fraction,
                    'feedback' => trim(html_to_text($feedback, 0, false))
                ];
            }, array_values($answers));
            break;

        case 'truefalse':
            $tf = $DB->get_record('question_truefalse', ['question' => $questionid], '*', IGNORE_MISSING);
            if ($tf) {
                $trueanswer = $DB->get_record('question_answers', ['id' => $tf->trueanswer], '*', IGNORE_MISSING);
                $falseanswer = $DB->get_record('question_answers', ['id' => $tf->falseanswer], '*', IGNORE_MISSING);
                if ($trueanswer && $falseanswer) {
                    $result['answer'] = ((float)$trueanswer->fraction) >= ((float)$falseanswer->fraction) ? '1' : '0';
                    break;
                }
            }
            // Fallback: assume the stored question text contains the correct answer
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
            foreach ($answers as $answer) {
                if ((float)$answer->fraction > 0.49) {
                    $result['answer'] = strpos(strtolower($answer->answer), 'true') !== false ? '1' : '0';
                    break;
                }
            }
            if (!isset($result['answer'])) {
                $result['answer'] = '1';
            }
            break;

        case 'shortanswer':
            if ($tableexists('question_shortanswer')) {
                $shortoptions = $DB->get_record('question_shortanswer', ['question' => $questionid], '*', IGNORE_MISSING);
                $result['caseSensitive'] = !empty($shortoptions->usecase);
            } else {
                $result['caseSensitive'] = false;
                if ($debug) {
                    debugging('question_shortanswer table missing; skipping options lookup', DEBUG_DEVELOPER);
                }
            }
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
            $result['answers'] = array_map(static function($answer) use ($context) {
                $answertext = format_text($answer->answer, $answer->answerformat, ['context' => $context]);
                return [
                    'text' => trim(html_to_text($answertext, 0, false)),
                    'fraction' => (float)$answer->fraction
                ];
            }, array_values($answers));
            break;

        case 'essay':
            $essaytable = $tableexists('qtype_essay_options')
                ? 'qtype_essay_options'
                : ($tableexists('question_essay') ? 'question_essay' : null);
            $essayoptions = null;
            if ($essaytable) {
                try {
                    $essayoptions = $DB->get_record($essaytable, ['questionid' => $questionid], '*', IGNORE_MISSING);
                    if (!$essayoptions && $essaytable === 'question_essay') {
                        $essayoptions = $DB->get_record($essaytable, ['question' => $questionid], '*', IGNORE_MISSING);
                    }
                } catch (\dml_exception $essayerror) {
                    if ($debug) {
                        debugging('Failed to read essay options from ' . $essaytable . ': ' . $essayerror->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
            $result['minWords'] = $essayoptions && property_exists($essayoptions, 'minwordlimit') ? (int)$essayoptions->minwordlimit : 0;
            $result['maxWords'] = $essayoptions && property_exists($essayoptions, 'maxwordlimit') ? (int)$essayoptions->maxwordlimit : 0;
            $result['attachments'] = $essayoptions && property_exists($essayoptions, 'attachments') ? (int)$essayoptions->attachments : 0;
            break;

        case 'numerical':
            $numericalanswers = $DB->get_records('question_numerical', ['question' => $questionid], 'id ASC');
            if (!empty($numericalanswers)) {
                $firstanswer = reset($numericalanswers);
                $answerid = $firstanswer->answer;
                $answerrecord = $DB->get_record('question_answers', ['id' => $answerid], 'answer', IGNORE_MISSING);
                $result['answer'] = $answerrecord ? $answerrecord->answer : '';
                $result['tolerance'] = $firstanswer->tolerance;
            }
            $unit = $DB->get_record('question_numerical_units', ['question' => $questionid, 'multiplier' => 1], '*', IGNORE_MISSING);
            $result['unit'] = $unit ? $unit->unit : '';
            break;

        case 'match':
            $matchsources = [];
            if ($tableexists('qtype_match_subquestions')) {
                $matchsources[] = ['table' => 'qtype_match_subquestions', 'field' => 'questionid'];
            }
            if ($tableexists('question_match_sub')) {
                $matchsources[] = ['table' => 'question_match_sub', 'field' => 'question'];
            }
            $subs = [];
            $matchtable = null;
            foreach ($matchsources as $source) {
                try {
                    $records = $DB->get_records($source['table'], [$source['field'] => $questionid], 'id ASC');
                    if (!empty($records)) {
                        $subs = $records;
                        $matchtable = $source['table'];
                        break;
                    }
                    if ($matchtable === null) {
                        $matchtable = $source['table'];
                    }
                } catch (\dml_exception $readerror) {
                    if ($debug) {
                        debugging('Failed to read matching pairs from ' . $source['table'] . ': ' . $readerror->getMessage(), DEBUG_DEVELOPER);
                    }
                    $subs = [];
                    continue;
                }
            }
            if ($subs) {
                $result['pairs'] = array_map(static function($sub) use ($context) {
                    $questionformat = property_exists($sub, 'questiontextformat') ? (int)$sub->questiontextformat : FORMAT_HTML;
                    $questiontext = format_text($sub->questiontext, $questionformat, ['context' => $context]);
                    $answerraw = $sub->answertext ?? '';
                    return [
                        'question' => trim(html_to_text($questiontext, 0, false)),
                        'answer' => trim((string)$answerraw)
                    ];
                }, array_values($subs));
            } else {
                $result['pairs'] = [];
                if ($debug && empty($matchsources)) {
                    debugging('Matching tables not found when fetching question details.', DEBUG_DEVELOPER);
                }
            }
            break;
        case 'gapselect':
            $gapoptions = $DB->get_record('question_gapselect', ['questionid' => $questionid], '*', IGNORE_MISSING);
            $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
            $placeholders = [];
            if (!empty($result['questiontext'])) {
                if (preg_match_all('/\[\[(\d+)]]/', $result['questiontext'], $matches)) {
                    $placeholders = array_map('intval', $matches[1]);
                }
            }
            $answersByGroup = [];
            foreach ($answers as $ans) {
                $groupkey = (int)($ans->feedback ?? 0);
                if ($groupkey <= 0) {
                    continue;
                }
                if (!isset($answersByGroup[$groupkey])) {
                    $answersByGroup[$groupkey] = [
                        'correct' => null,
                        'choices' => [],
                    ];
                }
                $answersByGroup[$groupkey]['choices'][] = $ans->answer;
                if ($answersByGroup[$groupkey]['correct'] === null && (float)$ans->fraction > 0) {
                    $answersByGroup[$groupkey]['correct'] = $ans->answer;
                }
            }
            $groups = [];
            $remainingGroups = $answersByGroup;
            foreach ($placeholders as $slotindex) {
                $groupdata = $answersByGroup[$slotindex] ?? null;
                if (!$groupdata && !empty($remainingGroups)) {
                    $groupdata = array_shift($remainingGroups);
                }
                if (!$groupdata) {
                    continue;
                }
                $correct = $groupdata['correct'] ?? ($groupdata['choices'][0] ?? '');
                if ($correct === '') {
                    continue;
                }
                $distractors = array_values(array_filter($groupdata['choices'], static function($choice) use ($correct) {
                    $choice = trim((string)$choice);
                    return $choice !== '' && $choice !== $correct;
                }));
                $groups[] = [
                    'slot' => $slotindex,
                    'correct' => $correct,
                    'distractors' => $distractors,
                ];
                unset($answersByGroup[$slotindex]);
            }
            // Include any remaining groups even if no matching placeholder (legacy data).
            foreach ($answersByGroup as $slot => $groupdata) {
                $correct = $groupdata['correct'] ?? ($groupdata['choices'][0] ?? '');
                if ($correct === '') {
                    continue;
                }
                $distractors = array_values(array_filter($groupdata['choices'], static function($choice) use ($correct) {
                    $choice = trim((string)$choice);
                    return $choice !== '' && $choice !== $correct;
                }));
                $groups[] = [
                    'slot' => $slot,
                    'correct' => $correct,
                    'distractors' => $distractors,
                ];
            }
            $result['gapselect'] = [
                'shuffle' => $gapoptions ? !empty($gapoptions->shuffleanswers) : true,
                'groups' => $groups,
            ];
            break;

        case 'ordering':
            $orderingitems = $DB->get_records('question_answers', ['question' => $questionid], 'fraction ASC');
            $result['items'] = array_map(static function($answer) use ($context) {
                $text = format_text($answer->answer, $answer->answerformat ?? FORMAT_HTML, ['context' => $context]);
                return [
                    'text' => trim(html_to_text($text, 0, false)),
                    'order' => (int)$answer->fraction,
                ];
            }, array_values($orderingitems));
            break;

        case 'ddwtos':
            $ddwtosoptions = $DB->get_record('question_ddwtos', ['questionid' => $questionid], '*', IGNORE_MISSING);
            $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
            $placeholders = [];
            if (!empty($result['questiontext'])) {
                if (preg_match_all('/\[\[(\d+)]]/', $result['questiontext'], $matches)) {
                    $placeholders = array_map('intval', $matches[1]);
                }
            }
            $answersByGroup = [];
            foreach ($answers as $ans) {
                $groupkey = remui_kids_decode_ddwtos_feedback($ans->feedback ?? '');
                if ($groupkey === null) {
                    continue;
                }
                if (!isset($answersByGroup[$groupkey])) {
                    $answersByGroup[$groupkey] = [
                        'correct' => null,
                        'choices' => [],
                    ];
                }
                $answersByGroup[$groupkey]['choices'][] = $ans->answer;
                if ($answersByGroup[$groupkey]['correct'] === null && (float)$ans->fraction > 0) {
                    $answersByGroup[$groupkey]['correct'] = $ans->answer;
                }
            }
            $groups = [];
            $matchedSlots = [];
            // First, match groups to placeholders in the question text
            foreach ($placeholders as $slotindex) {
                $groupdata = $answersByGroup[$slotindex] ?? null;
                if (!$groupdata) {
                    continue;
                }
                $correct = $groupdata['correct'] ?? ($groupdata['choices'][0] ?? '');
                if ($correct === '') {
                    continue;
                }
                $distractors = array_values(array_filter($groupdata['choices'], static function($choice) use ($correct) {
                    $choice = trim((string)$choice);
                    return $choice !== '' && $choice !== $correct;
                }));
                $groups[] = [
                    'slot' => $slotindex,
                    'correct' => $correct,
                    'distractors' => $distractors,
                ];
                $matchedSlots[$slotindex] = true;
            }
            // Include any unmatched answer groups so teachers can recover legacy data
            if (!empty($answersByGroup)) {
                ksort($answersByGroup);
                foreach ($answersByGroup as $groupkey => $groupdata) {
                    if (isset($matchedSlots[$groupkey])) {
                        continue;
                    }
                    $correct = $groupdata['correct'] ?? ($groupdata['choices'][0] ?? '');
                    if ($correct === '') {
                        continue;
                    }
                    $distractors = array_values(array_filter($groupdata['choices'], static function($choice) use ($correct) {
                        $choice = trim((string)$choice);
                        return $choice !== '' && $choice !== $correct;
                    }));
                    $groups[] = [
                        'slot' => $groupkey,
                        'correct' => $correct,
                        'distractors' => $distractors,
                    ];
                }
            }
            $result['ddwtos'] = [
                'shuffle' => $ddwtosoptions ? !empty($ddwtosoptions->shuffleanswers) : true,
                'groups' => $groups,
            ];
            break;

        case 'ddimageortext':
            $ddimageoptions = $DB->get_record('qtype_ddimageortext', ['questionid' => $questionid], '*', IGNORE_MISSING);
            $drops = $DB->get_records('qtype_ddimageortext_drops', ['questionid' => $questionid], 'no ASC');
            $drags = $DB->get_records('qtype_ddimageortext_drags', ['questionid' => $questionid], 'no ASC');
            
            $dragMap = [];
            foreach ($drags as $drag) {
                $dragMap[$drag->no] = $drag->label;
            }
            
            $dropsdata = [];
            foreach ($drops as $drop) {
                $correctLabel = $dragMap[$drop->choice] ?? '';
                $distractors = [];
                foreach ($drags as $drag) {
                    if ($drag->no !== $drop->choice) {
                        $distractors[] = $drag->label;
                    }
                }
                $dropsdata[] = [
                    'index' => $drop->no,
                    'label' => $drop->label,
                    'correct' => $correctLabel,
                    'distractors' => $distractors,
                ];
            }
            
            $result['ddimageortext'] = [
                'shuffle' => $ddimageoptions ? !empty($ddimageoptions->shuffleanswers) : true,
                'drops' => $dropsdata,
            ];
            break;

        default:
            // Unsupported types fall back to Moodle's native editor.
            $result['editable'] = false;
            $result['message'] = 'This question type must be edited from the standard Question Bank interface.';
            break;
    }

    echo json_encode([
        'success' => true,
        'question' => $result
    ]);
} catch (Exception $e) {
    // Always log full error details
    $params = [
        'questionid' => isset($questionid) ? $questionid : null,
        'slotnum' => isset($slotnum) ? $slotnum : null,
        'quizid' => isset($quizid) ? $quizid : null,
        'cmid' => isset($cmid) ? $cmid : null
    ];
    error_log('get_question_details error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() . ' | Params: ' . json_encode($params));
    
    // Build error message - always show real error when debug is enabled
    $errorMessage = 'Error reading from database';
    if ($debug) {
        $errorMessage = $e->getMessage();
        if (method_exists($e, 'getDebugInfo') && $e->getDebugInfo()) {
            $errorMessage .= ' | Debug: ' . $e->getDebugInfo();
        }
        $errorMessage .= ' | File: ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    
    $response = [
        'success' => false,
        'message' => $errorMessage,
        'errorcode' => method_exists($e, 'errorcode') ? $e->errorcode : 'generalexceptionmessage'
    ];
    
    // Include debug info in response if debug is enabled
    if ($debug) {
        $response['debug'] = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'params' => $params
        ];
    }
    
    echo json_encode($response);
}

function fetch_question_by_slot($quizid, $slotnum, $debug = 0) {
    global $DB;

    if (!$quizid || !$slotnum) {
        return null;
    }

    $slot = $DB->get_record('quiz_slots', ['quizid' => $quizid, 'slot' => $slotnum], '*', IGNORE_MISSING);
    if (!$slot) {
        return null;
    }

    if (property_exists($slot, 'questionid') && !empty($slot->questionid)) {
        return $DB->get_record('question', ['id' => $slot->questionid], '*', IGNORE_MISSING);
    }

    $slotid = $slot->id ?? 0;
    $tables = $DB->get_tables(false);
    $hasrefs = in_array('question_references', $tables, true);
    $hasversions = in_array('question_versions', $tables, true);
    $hasqbe = in_array('question_bank_entries', $tables, true);
    $hasinstances = in_array('quiz_question_instances', $tables, true);

    if ($slotid && $hasrefs) {
        $qrcolumns = $DB->get_columns('question_references');
        $qrhasquestionid = is_array($qrcolumns) && array_key_exists('questionid', $qrcolumns);

        if ($hasqbe && $hasversions) {
        $sql = "
            SELECT COALESCE(qvref.questionid, qvlatest.questionid) AS questionid
            FROM {question_references} qr
            JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
            LEFT JOIN {question_versions} qvref ON qvref.id = qr.version
            LEFT JOIN {question_versions} qvlatest ON qvlatest.id = qbe.latestversion
            WHERE qr.component = 'mod_quiz'
              AND qr.questionarea = 'slot'
              AND qr.itemid = ?
        ";
        try {
            $ref = $DB->get_record_sql($sql, [$slotid]);
            if ($ref && !empty($ref->questionid)) {
                return $DB->get_record('question', ['id' => $ref->questionid], '*', IGNORE_MISSING);
            }
        } catch (Exception $e) {
            if ($debug) {
                debugging('get_question_details fallback 1 failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            }
        }

        if ($qrhasquestionid) {
            $ref = $DB->get_record('question_references', [
                'component' => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid' => $slotid
            ], 'questionid', IGNORE_MISSING);
            if ($ref && !empty($ref->questionid)) {
                return $DB->get_record('question', ['id' => $ref->questionid], '*', IGNORE_MISSING);
            }
        }
    }

    if ($hasinstances) {
        try {
            $instance = $DB->get_record('quiz_question_instances', ['quiz' => $quizid, 'slot' => $slotnum], '*', IGNORE_MISSING);
            if ($instance && !empty($instance->question)) {
                return $DB->get_record('question', ['id' => $instance->question], '*', IGNORE_MISSING);
            }
        } catch (Exception $e) {
            if ($debug) {
                debugging('get_question_details fallback 3 failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    return null;
}

function fetch_question_via_query_variants($quizid, $slotnum, $debug = 0, $courseid = null) {
    global $DB;

    if (!$quizid || !$slotnum) {
        return null;
    }

    // First verify the slot exists
    $slot = $DB->get_record('quiz_slots', ['quizid' => $quizid, 'slot' => $slotnum], 'id, slot, page', IGNORE_MISSING);
    if (!$slot) {
        if ($debug) {
            debugging("Slot $slotnum not found in quiz_slots for quizid $quizid", DEBUG_DEVELOPER);
        }
        return null;
    }

    $slotid = (int)$slot->id;

    // Try the simplest direct path first: quiz_slots -> question_references -> question_bank_entries -> question_versions -> question
    $alltables = $DB->get_tables(false);
    $hasrefs = in_array('question_references', $alltables, true);
    $hasqbe = in_array('question_bank_entries', $alltables, true);
    $hasversions = in_array('question_versions', $alltables, true);

    if ($hasrefs && $hasqbe && $hasversions) {
        try {
            $qbecolumns = $DB->get_columns('question_bank_entries');
            $qbhaslatest = is_array($qbecolumns) && array_key_exists('latestversion', $qbecolumns);
            
            if ($qbhaslatest) {
                // Use latestversion column
                $sql = "
                    SELECT q.id AS questionid
                    FROM {question_references} qr
                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                    JOIN {question_versions} qv ON qv.id = qbe.latestversion
                    JOIN {question} q ON q.id = qv.questionid
                    WHERE qr.component = 'mod_quiz'
                      AND qr.questionarea = 'slot'
                      AND qr.itemid = ?
                ";
            } else {
                // Use MAX version subquery
                $sql = "
                    SELECT q.id AS questionid
                    FROM {question_references} qr
                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                      AND qv.version = (
                          SELECT MAX(version)
                          FROM {question_versions}
                          WHERE questionbankentryid = qbe.id
                      )
                    JOIN {question} q ON q.id = qv.questionid
                    WHERE qr.component = 'mod_quiz'
                      AND qr.questionarea = 'slot'
                      AND qr.itemid = ?
                ";
            }
            $result = $DB->get_record_sql($sql, [$slotid]);
            if ($result && !empty($result->questionid)) {
                $question = $DB->get_record('question', ['id' => $result->questionid], '*', IGNORE_MISSING);
                if ($question) {
                    return $question;
                }
            }
        } catch (Exception $e) {
            if ($debug) {
                debugging("Direct query failed: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    $questionqueries = [];

    $qrcolumns = $DB->get_columns('question_references');
    $qrhasversion = is_array($qrcolumns) && array_key_exists('version', $qrcolumns);
    $qrhasquestionid = is_array($qrcolumns) && array_key_exists('questionid', $qrcolumns);
    $alltables = $DB->get_tables(false);
    $questionversionsexists = in_array('question_versions', $alltables, true);
    $quizslotcolumns = $DB->get_columns('quiz_slots');
    $slotshavequestionid = is_array($quizslotcolumns) && array_key_exists('questionid', $quizslotcolumns);
    $qbecolumns = $DB->get_columns('question_bank_entries');
    $qbhaslatest = is_array($qbecolumns) && array_key_exists('latestversion', $qbecolumns);

    // Primary path: quiz_slots -> question_references -> question_bank_entries -> question_versions -> question
    // This works when question_references.version is NULL (uses latestversion from question_bank_entries)
    if ($questionversionsexists && $qbhaslatest) {
        $questionqueries[] = "
            SELECT
                qs.slot,
                qs.page,
                qs.maxmark,
                q.id AS questionid,
                q.name,
                q.qtype,
                q.questiontext
            FROM {quiz_slots} qs
            JOIN {question_references} qr
              ON qr.itemid = qs.id
             AND qr.component = 'mod_quiz'
             AND qr.questionarea = 'slot'
            JOIN {question_bank_entries} qbe
              ON qbe.id = qr.questionbankentryid
            JOIN {question_versions} qv
              ON qv.questionbankentryid = qbe.id
             AND qv.version = (
                 SELECT MAX(version)
                 FROM {question_versions}
                 WHERE questionbankentryid = qbe.id
             )
            JOIN {question} q
              ON q.id = qv.questionid
           WHERE qs.quizid = ?
        ";
    }

    // Alternative: use latestversion column if available
    if ($questionversionsexists && $qbhaslatest) {
        $questionqueries[] = "
            SELECT
                qs.slot,
                qs.page,
                qs.maxmark,
                q.id AS questionid,
                q.name,
                q.qtype,
                q.questiontext
            FROM {quiz_slots} qs
            JOIN {question_references} qr
              ON qr.itemid = qs.id
             AND qr.component = 'mod_quiz'
             AND qr.questionarea = 'slot'
            JOIN {question_bank_entries} qbe
              ON qbe.id = qr.questionbankentryid
            JOIN {question_versions} qv
              ON qv.id = qbe.latestversion
            JOIN {question} q
              ON q.id = qv.questionid
           WHERE qs.quizid = ?
        ";
    }

    if ($slotshavequestionid) {
        $questionqueries[] = "
            SELECT
                qs.slot,
                qs.page,
                qs.maxmark,
                q.id AS questionid,
                q.name,
                q.qtype,
                q.questiontext
            FROM {quiz_slots} qs
            JOIN {question} q
              ON q.id = qs.questionid
           WHERE qs.quizid = ?
        ";
    }

    if ($qrhasquestionid) {
        $questionqueries[] = "
            SELECT
                qs.slot,
                qs.page,
                qs.maxmark,
                q.id AS questionid,
                q.name,
                q.qtype,
                q.questiontext
            FROM {quiz_slots} qs
            JOIN {question_references} qr
              ON qr.itemid = qs.id
             AND qr.component = 'mod_quiz'
             AND qr.questionarea = 'slot'
            JOIN {question} q
              ON q.id = qr.questionid
           WHERE qs.quizid = ?
        ";
    }

    if (in_array('quiz_question_instances', $alltables, true)) {
        $questionqueries[] = "
            SELECT
                qqi.slot,
                1 AS page,
                qqi.grade AS maxmark,
                q.id AS questionid,
                q.name,
                q.qtype,
                q.questiontext
            FROM {quiz_question_instances} qqi
            JOIN {question} q
              ON q.id = qqi.question
           WHERE qqi.quiz = ?
        ";
    }

    if (empty($questionqueries)) {
        return null;
    }

    $errors = [];
    foreach ($questionqueries as $sqlindex => $sql) {
        try {
            $records = $DB->get_records_sql($sql, [$quizid]);
        } catch (Exception $e) {
            $errors[] = 'Query variant ' . ($sqlindex + 1) . ' failed: ' . $e->getMessage();
            if ($debug) {
                debugging('get_question_details query variant ' . ($sqlindex + 1) . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            continue;
        }

        if (empty($records)) {
            $errors[] = 'Query variant ' . ($sqlindex + 1) . ' returned no records';
            continue;
        }

        foreach ($records as $record) {
            if ((int)$record->slot === (int)$slotnum && !empty($record->questionid)) {
                $question = $DB->get_record('question', ['id' => $record->questionid], '*', IGNORE_MISSING);
                if ($question) {
                    return $question;
                }
            }
        }
        $errors[] = 'Query variant ' . ($sqlindex + 1) . ' found ' . count($records) . ' records but none matched slot ' . $slotnum;
    }

    // Debug: get actual slots to see what we have
    try {
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot ASC', 'slot, page, id');
        $errors[] = 'Available slots: ' . json_encode(array_values($slots));
    } catch (Exception $e) {
        $errors[] = 'Could not fetch slots: ' . $e->getMessage();
    }

    if ($debug && !empty($errors)) {
        debugging('get_question_details errors: ' . implode('; ', $errors), DEBUG_DEVELOPER);
    }

    return null;
}


