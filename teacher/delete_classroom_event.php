<?php
require_once(__DIR__ . '/../../../config.php');

require_login();

$userid = required_param('userid', PARAM_INT);
$competencyid = required_param('competencyid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$eventid = required_param('eventid', PARAM_INT);

// Verify the event exists and belongs to the correct student/competency/course
$event = $DB->get_record('theme_remui_kids_classroom_events', array(
    'id' => $eventid,
    'userid' => $userid,
    'competencyid' => $competencyid,
    'courseid' => $courseid
));

if (!$event) {
    $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
        'userid' => $userid,
        'competencyid' => $competencyid,
        'courseid' => $courseid
    ));
    $redirecturl->param('error', '1');
    redirect($redirecturl, 'Classroom event not found or you do not have permission to delete it.', 3);
}

try {
    $DB->delete_records('theme_remui_kids_classroom_events', array('id' => $eventid));
    
    $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
        'userid' => $userid,
        'competencyid' => $competencyid,
        'courseid' => $courseid
    ));
    $redirecturl->param('success', '1');
    redirect($redirecturl, 'Classroom event deleted successfully!', 3);
    
} catch (Exception $e) {
    $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
        'userid' => $userid,
        'competencyid' => $competencyid,
        'courseid' => $courseid
    ));
    $redirecturl->param('error', '1');
    redirect($redirecturl, 'Failed to delete classroom event: ' . $e->getMessage(), 3);
}

