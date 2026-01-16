<?php
/**
 * Edit Quiz Page
 *
 * Collects an existing quiz configuration and reuses the create quiz page in edit mode.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once(__DIR__ . '/includes/question_helpers.php');

require_login();

$cmid = required_param('cmid', PARAM_INT);
$quizid = required_param('quizid', PARAM_INT);

$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
if ((int)$cm->instance !== $quizid) {
    throw new moodle_exception('invalidcmorid', 'error');
}

$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

// Set page context to avoid "PAGE->context was not set" error
$PAGE->set_context($coursecontext);

// Get section name - use section number, not section ID
$sectionrecord = $DB->get_record('course_sections', ['id' => $cm->section], 'id, name, section', IGNORE_MISSING);
$sectionname = '';
if ($sectionrecord && isset($sectionrecord->section)) {
    // Use section number (not ID) for get_section_name
    $sectionname = get_section_name($course, $sectionrecord->section);
} else if ($sectionrecord && !empty($sectionrecord->name)) {
    // Fallback to section name if available
    $sectionname = $sectionrecord->name;
} else {
    // Final fallback
    $sectionname = get_string('section') . ' ' . ($cm->section ?? '');
}

// Determine group availability settings.
$assigned_groups = [];
$assign_to = 'all';
if (!empty($cm->availability)) {
    $availability = json_decode($cm->availability);
    if ($availability && !empty($availability->c) && is_array($availability->c)) {
        foreach ($availability->c as $condition) {
            if (isset($condition->type) && $condition->type === 'group' && !empty($condition->id)) {
                $assigned_groups[] = (int)$condition->id;
            }
        }
    }

    if (!empty($assigned_groups)) {
        $assign_to = 'groups';
    }
}

// Collect linked competencies.
$competencyrecords = $DB->get_records('competency_modulecomp', ['cmid' => $cmid], 'sortorder ASC');
$competencies = [];
$completion_action = 0;
if (!empty($competencyrecords)) {
    foreach ($competencyrecords as $record) {
        $competencies[] = (int)$record->competencyid;
        if (!$completion_action) {
            $completion_action = (int)$record->ruleoutcome;
        }
    }
}

// Fetch current quiz questions with slot/order information.
$qrcolumns = $DB->get_columns('question_references');
$qrhasversion = is_array($qrcolumns) && array_key_exists('version', $qrcolumns);
$qrhasquestionid = is_array($qrcolumns) && array_key_exists('questionid', $qrcolumns);
$alltables = $DB->get_tables(false);
$questionversionsexists = in_array('question_versions', $alltables, true);
$quizslotcolumns = $DB->get_columns('quiz_slots');
$slotshavequestionid = is_array($quizslotcolumns) && array_key_exists('questionid', $quizslotcolumns);
$qbecolumns = $DB->get_columns('question_bank_entries');
$qbhaslatest = is_array($qbecolumns) && array_key_exists('latestversion', $qbecolumns);

$questionqueries = [];

if ($questionversionsexists && $qrhasversion && $qbhaslatest) {
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
        LEFT JOIN {question_versions} qvref
          ON qvref.id = qr.version
        JOIN {question_versions} qvlatest
          ON qvlatest.id = qbe.latestversion
        JOIN {question} q
          ON q.id = COALESCE(qvref.questionid, qvlatest.questionid)
       WHERE qs.quizid = ?
       ORDER BY qs.slot ASC
    ";
}

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
       ORDER BY qs.slot ASC
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
       ORDER BY qs.slot ASC
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
       ORDER BY qs.slot ASC
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
       ORDER BY qqi.slot ASC
    ";
}

if (empty($questionqueries)) {
    $questionqueries[] = "
        SELECT
            qs.slot,
            qs.page,
            qs.maxmark,
            0 AS questionid,
            '' AS name,
            '' AS qtype,
            '' AS questiontext
        FROM {quiz_slots} qs
       WHERE qs.quizid = ?
       ORDER BY qs.slot ASC
    ";
}

$questionrecords = [];
$lastdmlerror = null;
foreach ($questionqueries as $index => $questionsql) {
    try {
        $questionrecords = $DB->get_records_sql($questionsql, [$quizid]);
        if (!empty($questionrecords)) {
            debugging('Quiz edit query variant ' . ($index + 1) . ' succeeded with ' . count($questionrecords) . ' rows.', DEBUG_DEVELOPER);
            break;
        }
        debugging('Quiz edit query variant ' . ($index + 1) . ' executed but returned no rows, trying next fallback.', DEBUG_DEVELOPER);
        $questionrecords = [];
    } catch (dml_exception $e) {
        $lastdmlerror = $e;
        $debuginfo = method_exists($e, 'get_dml_debug') ? $e->get_dml_debug() : (property_exists($e, 'debuginfo') ? $e->debuginfo : '');
        $message = 'Quiz edit question query variant ' . ($index + 1) . ' failed: ' . $e->getMessage() .
            (!empty($debuginfo) ? ' | Debug: ' . $debuginfo : '');
        debugging($message, DEBUG_DEVELOPER);
        error_log('[edit_quiz_page] ' . $message);
        $questionrecords = [];
        continue;
    }
}

if (empty($questionrecords) && $lastdmlerror instanceof dml_exception) {
    throw $lastdmlerror;
}
$questions = [];
foreach ($questionrecords as $record) {
    $entry = !empty($record->questionid) ? theme_remui_kids_get_question_bank_entry($record->questionid) : null;
    $questions[] = [
        'id' => (int)$record->questionid,
        'name' => format_string($record->name),
        'qtype' => $record->qtype,
        'defaultmark' => (float)$record->maxmark,
        'page' => (int)$record->page,
        'slot' => (int)$record->slot,
        'questiontext' => $record->questiontext ?? '',
        'questionbankentryid' => $entry ? (int)$entry->id : null
    ];
}

$editdata = [
    'quizid' => (int)$quizid,
    'cmid' => (int)$cmid,
    'courseid' => (int)$course->id,
    'coursename' => format_string($course->fullname),
    'name' => $quiz->name,
    'intro' => $quiz->intro,
    'sectionid' => (int)$cm->section,
    'sectionname' => $sectionname,
    'timeopen' => (int)$quiz->timeopen,
    'timeclose' => (int)$quiz->timeclose,
    'timelimit' => (int)$quiz->timelimit,
    'grademethod' => (int)$quiz->grademethod,
    'grade' => (float)$quiz->grade,
    'decimalpoints' => (int)$quiz->decimalpoints,
    'questiondecimalpoints' => (int)$quiz->questiondecimalpoints,
    'attempts' => (int)$quiz->attempts,
    'preferredbehaviour' => $quiz->preferredbehaviour,
    'shuffleanswers' => (int)$quiz->shuffleanswers,
    'navmethod' => $quiz->navmethod,
    'questionsperpage' => (int)$quiz->questionsperpage,
    'reviewoptions' => [
        'reviewattempt' => (int)$quiz->reviewattempt,
        'reviewcorrectness' => (int)$quiz->reviewcorrectness,
        'reviewmarks' => (int)$quiz->reviewmarks,
        'reviewspecificfeedback' => (int)$quiz->reviewspecificfeedback,
        'reviewgeneralfeedback' => (int)$quiz->reviewgeneralfeedback,
        'reviewrightanswer' => (int)$quiz->reviewrightanswer,
        'reviewoverallfeedback' => (int)$quiz->reviewoverallfeedback,
    ],
    'assign_to' => $assign_to,
    'assigned_groups' => $assigned_groups,
    'competencies' => $competencies,
    'competency_completion_action' => $completion_action,
    'questions' => $questions,
    'sumgrades' => (float)$quiz->sumgrades,
];

$GLOBALS['quiz_edit_mode'] = true;
$GLOBALS['quiz_edit_data'] = $editdata;

// Reuse the create quiz page which now detects edit mode via the globals above.
include(__DIR__ . '/create_quiz_page.php');

