<?php
/**
 * Parent Teacher Meeting helper functions.
 *
 * @package    theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');
require_once(__DIR__ . '/get_parent_children.php');

/**
 * Fetch meetings created by the specified parent.
 *
 * @param int $parentid
 * @param string $filter all|upcoming|past
 * @return array
 */
function get_parent_meetings(int $parentid, string $filter = 'all'): array {
    global $DB;

    if ($parentid <= 0) {
        return [];
    }

    $filter = strtolower($filter);
    $now = time();

    $events = $DB->get_records('event', [
        'component' => 'theme_remui_kids',
        'eventtype' => 'parent_teacher_meeting',
        'userid' => $parentid
    ], 'timestart DESC');

    $children = get_parent_children($parentid);
    $childmap = [];
    foreach ($children as $child) {
        $childmap[$child['id']] = $child['name'];
    }

    $results = [];
    foreach ($events as $event) {
        $custom = [];
        if (!empty($event->customdata)) {
            $custom = json_decode($event->customdata, true) ?? [];
        }

        $status = $custom['status'] ?? ($event->timestart < $now ? 'completed' : 'scheduled');
        if (!$event->visible && $status !== 'cancelled') {
            $status = 'cancelled';
        }

        if ($filter === 'upcoming' && $status !== 'scheduled') {
            continue;
        }
        if ($filter === 'past' && $status === 'scheduled') {
            continue;
        }

        $teacherid = $custom['teacherid'] ?? 0;
        $teacher = $teacherid ? $DB->get_record('user', ['id' => $teacherid], 'id,firstname,lastname,email,phone1') : null;

        $duration = (int)($custom['duration'] ?? 0);
        if (!$duration && !empty($event->timeduration)) {
            $duration = (int)round($event->timeduration / 60);
        }
        if ($duration <= 0) {
            $duration = 30;
        }

        $meeting = [
            'id' => $event->id,
            'subject' => $event->name,
            'description' => $event->description,
            'teacher_id' => $teacherid,
            'teacher_name' => $teacher ? fullname($teacher) : get_string('teacher'),
            'teacher_email' => $teacher->email ?? '',
            'teacher_phone' => $teacher->phone1 ?? '',
            'teacher_initial' => $teacher ? strtoupper(core_text::substr($teacher->firstname, 0, 1)) : '?',
            'child_id' => $custom['childid'] ?? 0,
            'child_name' => $childmap[$custom['childid'] ?? 0] ?? 'Child',
            'timestamp' => $event->timestart,
            'date' => userdate($event->timestart, get_string('strftimedate', 'langconfig')),
            'time' => userdate($event->timestart, get_string('strftimetime', 'langconfig')),
            'duration' => $duration,
            'type' => $custom['type'] ?? 'in-person',
            'location' => $custom['location'] ?? '',
            'meeting_link' => $custom['meeting_link'] ?? '',
            'notes' => $custom['notes'] ?? '',
            'status' => $status
        ];

        $results[] = $meeting;
    }

    return $results;
}

/**
 * Create a new meeting.
 *
 * @param int $parentid
 * @param array $data
 * @return array
 */
function create_parent_teacher_meeting(int $parentid, array $data): array {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/calendar/lib.php');

    if ($parentid <= 0) {
        return ['success' => false, 'message' => get_string('invaliduser', 'error')];
    }

    $teacherid = (int)($data['teacherid'] ?? 0);
    if (!$teacherid) {
        return ['success' => false, 'message' => get_string('teacher', 'theme_remui_kids') . ' ' . get_string('missingparam', 'error')];
    }

    if (!$DB->record_exists('user', ['id' => $teacherid, 'deleted' => 0])) {
        return ['success' => false, 'message' => get_string('invaliduser', 'error')];
    }

    $datestr = $data['date'] ?? '';
    $timestr = $data['time'] ?? '';
    if (!$datestr || !$timestr) {
        return ['success' => false, 'message' => get_string('invaliddate', 'error')];
    }

    $timestamp = strtotime($datestr . ' ' . $timestr);
    if (!$timestamp) {
        return ['success' => false, 'message' => get_string('invaliddate', 'error')];
    }

    $duration = max(15, (int)($data['duration'] ?? 30));
    $customdata = [
        'teacherid' => $teacherid,
        'childid' => (int)($data['childid'] ?? 0),
        'duration' => $duration,
        'type' => $data['type'] ?? 'in-person',
        'location' => trim($data['location'] ?? ''),
        'meeting_link' => trim($data['meeting_link'] ?? ''),
        'notes' => trim($data['notes'] ?? ''),
        'status' => 'scheduled'
    ];

    $eventdata = (object)[
        'name' => trim($data['subject'] ?? get_string('meeting', 'theme_remui_kids')),
        'description' => format_text($data['description'] ?? '', FORMAT_HTML),
        'format' => FORMAT_HTML,
        'courseid' => SITEID,
        'groupid' => 0,
        'userid' => $parentid,
        'timestart' => $timestamp,
        'timeduration' => $duration * 60,
        'visible' => 1,
        'eventtype' => 'parent_teacher_meeting',
        'component' => 'theme_remui_kids',
        'customdata' => json_encode($customdata)
    ];

    try {
        $event = \calendar_event::create($eventdata);
        return ['success' => true, 'eventid' => $event->id];
    } catch (Exception $e) {
        debugging('Error creating meeting: ' . $e->getMessage());
        return ['success' => false, 'message' => get_string('error')];
    }
}

/**
 * Cancel an existing meeting.
 *
 * @param int $parentid
 * @param int $eventid
 * @return array
 */
function cancel_parent_teacher_meeting(int $parentid, int $eventid): array {
    global $CFG;

    require_once($CFG->dirroot . '/calendar/lib.php');

    if ($parentid <= 0 || $eventid <= 0) {
        return ['success' => false, 'message' => get_string('invalidrequest', 'error')];
    }

    try {
        $event = \calendar_event::load($eventid);
    } catch (\Exception $e) {
        return ['success' => false, 'message' => get_string('invalidevent', 'error')];
    }

    if ($event->component !== 'theme_remui_kids' ||
        $event->eventtype !== 'parent_teacher_meeting' ||
        (int)$event->userid !== $parentid) {
        return ['success' => false, 'message' => get_string('nopermissions', 'error', 'cancel meeting')];
    }

    $custom = [];
    if (!empty($event->properties()->customdata)) {
        $custom = json_decode($event->properties()->customdata, true) ?? [];
    }
    $custom['status'] = 'cancelled';

    $data = (object)[
        'visible' => 0,
        'customdata' => json_encode($custom)
    ];

    try {
        $event->update($data);
        return ['success' => true];
    } catch (Exception $e) {
        debugging('Error cancelling meeting: ' . $e->getMessage());
        return ['success' => false, 'message' => get_string('error')];
    }
}

