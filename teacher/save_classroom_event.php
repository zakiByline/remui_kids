<?php
require_once(__DIR__ . '/../../../config.php');

require_login();

$userid = required_param('userid', PARAM_INT);
$competencyid = required_param('competencyid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$eventtitle = required_param('eventtitle', PARAM_TEXT);
$eventdate = required_param('eventdate', PARAM_TEXT);
$description = optional_param('description', '', PARAM_TEXT);

// Convert date string to timestamp
$eventdatestamp = strtotime($eventdate . ' 00:00:00');
if ($eventdatestamp === false) {
    $eventdatestamp = time();
}

// Prepare the record
$record = new stdClass();
$record->userid = $userid;
$record->competencyid = $competencyid;
$record->courseid = $courseid;
$record->eventtitle = $eventtitle;
$record->description = $description;
$record->eventdate = $eventdatestamp;
$record->createdby = $USER->id;
$record->timecreated = time();
$record->timemodified = time();

try {
    $eventid = $DB->insert_record('theme_remui_kids_classroom_events', $record);
    
    if ($eventid) {
        $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
            'userid' => $userid,
            'competencyid' => $competencyid,
            'courseid' => $courseid
        ));
        $redirecturl->param('success', '1');
        redirect($redirecturl, 'Classroom event added successfully!', 3);
    } else {
        throw new Exception('Failed to save classroom event');
    }
    
} catch (Exception $e) {
    $redirecturl = new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array(
        'userid' => $userid,
        'competencyid' => $competencyid,
        'courseid' => $courseid
    ));
    $redirecturl->param('error', '1');
    redirect($redirecturl, 'Failed to save classroom event: ' . $e->getMessage(), 3);
}

