<?php
require_once(__DIR__ . '/../../../config.php');

require_login();

$userid = required_param('userid', PARAM_INT);
$competencyid = required_param('competencyid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$grade = required_param('grade', PARAM_INT);
$comment = optional_param('comment', '', PARAM_TEXT);

try {
    // Use Moodle's proper competency API
    require_once($CFG->dirroot . '/competency/classes/api.php');
    
    // Use Moodle's API to grade the competency in the course
    $result = \core_competency\api::grade_competency_in_course($courseid, $userid, $competencyid, $grade, $comment);
    
    if ($result) {
        $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
            'userid' => $userid,
            'competencyid' => $competencyid,
            'courseid' => $courseid
        ));
        $redirecturl->param('success', '1');
        redirect($redirecturl, 'Competency rating saved successfully!', 3);
    } else {
        throw new Exception('Failed to save competency rating');
    }
    
} catch (Exception $e) {
    $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
        'userid' => $userid,
        'competencyid' => $competencyid,
        'courseid' => $courseid
    ));
    $redirecturl->param('error', '1');
    redirect($redirecturl, 'Failed to save competency rating: ' . $e->getMessage(), 3);
}