<?php
/**
 * Get Questions from Question Bank
 * Returns questions from question bank with filtering options
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

// Security checks
require_login();
require_sesskey();

try {
    $courseid = required_param('courseid', PARAM_INT);
    $categoryid = optional_param('categoryid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $qtype = optional_param('qtype', '', PARAM_ALPHA);
    
    // Validate course access
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/question:viewall', $coursecontext);
    
    // Build SQL query
    $sql = "SELECT q.id, q.name, q.questiontext, q.questiontextformat, q.qtype, q.defaultmark,
                   qbe.id as questionbankentryid, qv.version
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
            WHERE qc.contextid = ?
            AND qv.version = (
                SELECT MAX(version)
                FROM {question_versions}
                WHERE questionbankentryid = qbe.id
            )";
    
    $params = [$coursecontext->id];
    
    // Apply category filter
    if ($categoryid > 0) {
        $sql .= " AND qbe.questioncategoryid = ?";
        $params[] = $categoryid;
    }
    
    // Apply search filter
    if (!empty($search)) {
        $sql .= " AND (q.name LIKE ? OR q.questiontext LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Apply question type filter
    if (!empty($qtype)) {
        $sql .= " AND q.qtype = ?";
        $params[] = $qtype;
    }
    
    $sql .= " ORDER BY q.name ASC LIMIT 100";
    
    $questions = $DB->get_records_sql($sql, $params);
    
    $result_questions = [];
    foreach ($questions as $question) {
        // Clean question text for preview
        $questiontext = format_text($question->questiontext, $question->questiontextformat);
        $questiontext = strip_tags($questiontext);
        
        $result_questions[] = [
            'id' => $question->id,
            'name' => format_string($question->name),
            'questiontext' => $questiontext,
            'qtype' => $question->qtype,
            'defaultmark' => $question->defaultmark,
            'questionbankentryid' => $question->questionbankentryid,
            'version' => $question->version
        ];
    }
    
    echo json_encode([
        'success' => true,
        'questions' => $result_questions,
        'count' => count($result_questions)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}