<?php
// AJAX endpoint for parent teacher meeting actions.

require_once('../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json');

$action = required_param('action', PARAM_ALPHAEXT);

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
theme_remui_kids_require_parent(new moodle_url('/my/'));

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_teacher_meetings_handler.php');

$response = ['success' => false, 'message' => get_string('error')];

if ($action === 'create') {
    $data = [
        'teacherid' => required_param('teacherid', PARAM_INT),
        'childid' => optional_param('childid', 0, PARAM_INT),
        'subject' => required_param('subject', PARAM_TEXT),
        'description' => optional_param('description', '', PARAM_RAW),
        'date' => required_param('date', PARAM_RAW_TRIMMED),
        'time' => required_param('time', PARAM_RAW_TRIMMED),
        'duration' => optional_param('duration', 30, PARAM_INT),
        'type' => optional_param('type', 'in-person', PARAM_TEXT),
        'location' => optional_param('location', '', PARAM_TEXT),
        'meeting_link' => optional_param('meeting_link', '', PARAM_RAW_TRIMMED),
        'notes' => optional_param('notes', '', PARAM_RAW)
    ];

    $response = create_parent_teacher_meeting($USER->id, $data);
} else if ($action === 'cancel') {
    $eventid = required_param('eventid', PARAM_INT);
    $response = cancel_parent_teacher_meeting($USER->id, $eventid);
} else {
    $response['message'] = get_string('invalidrequest', 'error');
}

echo json_encode($response);
die();

