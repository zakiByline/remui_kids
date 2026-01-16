<?php
/**
 * AJAX endpoint for creating calendar events
 * Handles event creation from the add event modal
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

// Require login
require_login();

// Set JSON header
header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Verify sesskey for security (optional but recommended)
$sesskey = optional_param('sesskey', '', PARAM_RAW);
if (!empty($sesskey)) {
    if (!confirm_sesskey($sesskey)) {
        echo json_encode(['success' => false, 'message' => 'Invalid session key']);
        exit;
    }
}

// Get and validate input
$event_name = required_param('name', PARAM_TEXT);
$timestart = required_param('timestart', PARAM_INT);
$description = optional_param('description', '', PARAM_TEXT);
$event_type_param = optional_param('event_type', 'event', PARAM_TEXT);
$priority = optional_param('priority', 'medium', PARAM_TEXT);

// Map event type to Moodle event type (user events are always 'user')
$eventtype = 'user'; // User-created events in Moodle are always 'user' type

// Validate required fields
if (empty($event_name)) {
    echo json_encode(['success' => false, 'message' => 'Event name is required']);
    exit;
}

if (empty($timestart) || $timestart <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid start time is required']);
    exit;
}

try {
    global $USER, $DB;
    
    // Prepare event properties
    $event_properties = new stdClass();
    $event_properties->name = $event_name;
    $event_properties->description = $description;
    $event_properties->format = FORMAT_HTML;
    $event_properties->timestart = $timestart;
    $event_properties->timeduration = 0; // Default duration
    $event_properties->eventtype = $eventtype; // Always 'user' for user-created events
    $event_properties->userid = $USER->id;
    $event_properties->visible = 1;
    
    // For Moodle 3.x+, use courseid = 0 for user events
    $event_properties->courseid = 0;
    $event_properties->instance = 0;
    $event_properties->modulename = '';
    
    // Create the event using Moodle's calendar API
    $event = calendar_event::create($event_properties, true);
    
    if ($event && $event->id) {
        // Format response data
        $date_obj = new DateTime();
        $date_obj->setTimestamp($timestart);
        $formatted_date = $date_obj->format('n/j/Y');
        $formatted_time = $date_obj->format('g:i A');
        
        echo json_encode([
            'success' => true,
            'message' => 'Event created successfully',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'date_formatted' => $formatted_date,
                'time_formatted' => $formatted_time,
                'event_type' => $event_type_param,
                'priority' => $priority
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create event object']);
    }
    
} catch (Exception $e) {
    error_log('Calendar event creation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

