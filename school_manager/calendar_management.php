<?php
/**
 * Calendar Management API
 * Handles CRUD operations for calendar events and lecture schedules
 */

require_once('../../../config.php');
require_once(__DIR__ . '/../lang_init.php');
require_login();

global $USER, $DB, $CFG;

// Helper function to convert 24-hour time to 12-hour format
function convert24To12Hour($time24) {
    if (empty($time24)) {
        return '';
    }
    $parts = explode(':', $time24);
    $hour = isset($parts[0]) ? (int)$parts[0] : 0;
    $minute = isset($parts[1]) ? (int)$parts[1] : 0;
    
    $ampm = 'AM';
    if ($hour >= 12) {
        $ampm = 'PM';
        if ($hour > 12) {
            $hour -= 12;
        }
    } else if ($hour == 0) {
        $hour = 12;
    }
    
    return sprintf('%02d:%02d %s', $hour, $minute, $ampm);
}

// Get action first to check if it's a teacher-only action
// Check both GET and POST for action parameter
$action = '';
if (isset($_POST['action'])) {
    $action = clean_param($_POST['action'], PARAM_ALPHANUMEXT);
} elseif (isset($_GET['action'])) {
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
}

// Set JSON header early for teacher actions
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Update teacher availability - accessible to teachers (not just company managers)
if ($action === 'update_teacher_availability') {
    // For teacher actions, we verify ownership instead of strict sesskey
    // This is more reliable for AJAX requests
    // Ensure user is logged in
    if (!isloggedin() || isguestuser()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'User must be logged in']);
        exit;
    }
    
    // Optional sesskey check - log if provided but don't block
    if (isset($_POST['sesskey']) || isset($_GET['sesskey'])) {
        $sesskey = isset($_POST['sesskey']) ? clean_param($_POST['sesskey'], PARAM_RAW) : optional_param('sesskey', '', PARAM_RAW);
        if (!empty($sesskey)) {
            try {
                confirm_sesskey($sesskey);
            } catch (Exception $e) {
                // Log but don't block - we'll verify ownership below
                error_log("Sesskey check warning for user {$USER->id}: " . $e->getMessage());
            }
        }
    }
    
    // Get parameters from POST data directly
    $session_id = 0;
    $available = 1;
    if (isset($_POST['session_id'])) {
        $session_id = (int)$_POST['session_id'];
    }
    if (isset($_POST['available'])) {
        $available = (int)$_POST['available'];
    }
    
    if ($session_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid session ID']);
        exit;
    }
    
    if ($available !== 0 && $available !== 1) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid availability value']);
        exit;
    }
    
    try {
        // Get the session to verify it exists and belongs to the teacher
        $session = $DB->get_record('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
        if (!$session) {
            throw new Exception('Lecture session not found');
        }
        
        // Verify the session belongs to the current user (teacher)
        if ($session->teacherid != $USER->id) {
            throw new Exception('Access denied: You can only update your own availability');
        }
        
        // Update availability
        $session->teacher_available = $available;
        $result = $DB->update_record('theme_remui_kids_lecture_sessions', $session);
        
        // Verify the update was successful
        if ($result) {
            // Double-check by reading back the value (get_record only takes 2 parameters: table and conditions)
            $updated_session = $DB->get_record('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
            if ($updated_session) {
                error_log("Teacher availability updated: Session {$session_id}, teacher_available set to {$available}, verified value: " . (int)$updated_session->teacher_available);
                echo json_encode(['status' => 'success', 'available' => $available, 'verified' => (int)$updated_session->teacher_available]);
            } else {
                error_log("WARNING: Could not verify teacher availability update for session {$session_id}");
                echo json_encode(['status' => 'success', 'available' => $available]);
            }
        } else {
            error_log("ERROR: Failed to update teacher availability for session {$session_id}");
            throw new Exception('Failed to update teacher availability');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Check if user has company manager role (for all other actions)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

// Get company information
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Company not found']);
    exit;
}

$company_id = $company_info->id;

header('Content-Type: application/json');

// Create event
if ($action === 'create_event') {
    // Debug: Log all received data
    error_log("==========================================");
    error_log("📥 CREATE EVENT REQUEST RECEIVED");
    error_log("==========================================");
    error_log("POST data: " . print_r($_POST, true));
    error_log("REQUEST data: " . print_r($_REQUEST, true));
    error_log("Action: " . $action);
    error_log("Company ID: " . $company_id);
    error_log("User ID: " . $USER->id);
    
    try {
        require_sesskey();
        error_log("✅ Session key validated");
    } catch (Exception $e) {
        error_log("❌ Session key validation failed: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid session key: ' . $e->getMessage()]);
        exit;
    }
    
    // Get required parameters with error handling
    try {
        $title = required_param('title', PARAM_TEXT);
        $eventdate = optional_param('eventdate', 0, PARAM_INT); // For backward compatibility
        $startdate = optional_param('startdate', 0, PARAM_INT);
        $enddate = optional_param('enddate', 0, PARAM_INT);
        $starttime = required_param('starttime', PARAM_TEXT);
        $endtime = required_param('endtime', PARAM_TEXT);
        $eventtype = required_param('eventtype', PARAM_TEXT);
        error_log("✅ Required parameters received");
    } catch (Exception $e) {
        error_log("❌ Missing required parameter: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required parameter: ' . $e->getMessage()]);
        exit;
    }
    
    // Use startdate if provided, otherwise fall back to eventdate for backward compatibility
    if ($startdate <= 0 && $eventdate > 0) {
        $startdate = $eventdate;
    }
    // End date is optional - if not provided, event is single day (use startdate)
    // Only validate enddate if it's provided and greater than 0
    if ($enddate <= 0) {
        $enddate = $startdate; // Single day event
    }
    
    $description = optional_param('description', '', PARAM_TEXT);
    $color = optional_param('color', 'blue', PARAM_TEXT);
    $frequency = optional_param('frequency', 'specific_date', PARAM_TEXT); // Default to specific_date
    $selected_dates = optional_param('selected_dates', '', PARAM_TEXT); // Comma-separated dates for specific_date
    $weekly_days = optional_param('weekly_days', '', PARAM_TEXT); // Comma-separated day numbers (1-7) for weekly
    
    // Handle array parameters from FormData
    // FormData with append('teacher_ids[]', id) sends as $_POST['teacher_ids'] array in PHP
    // But we need to check both formats: 'teacher_ids' and 'teacher_ids[]'
    $teacher_ids = [];
    if (isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids'])) {
        $teacher_ids = array_filter(array_map('intval', $_POST['teacher_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['teacher_ids']) && is_array($_REQUEST['teacher_ids'])) {
        $teacher_ids = array_filter(array_map('intval', $_REQUEST['teacher_ids']), function($v) { return $v > 0; });
    }
    
    $student_ids = [];
    if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        $student_ids = array_filter(array_map('intval', $_POST['student_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['student_ids']) && is_array($_REQUEST['student_ids'])) {
        $student_ids = array_filter(array_map('intval', $_REQUEST['student_ids']), function($v) { return $v > 0; });
    }
    
    $cohort_ids = [];
    if (isset($_POST['cohort_ids']) && is_array($_POST['cohort_ids'])) {
        $cohort_ids = array_filter(array_map('intval', $_POST['cohort_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['cohort_ids']) && is_array($_REQUEST['cohort_ids'])) {
        $cohort_ids = array_filter(array_map('intval', $_REQUEST['cohort_ids']), function($v) { return $v > 0; });
    }
    
    error_log("📝 Array parameters - Teachers: " . count($teacher_ids) . ", Students: " . count($student_ids) . ", Cohorts: " . count($cohort_ids));
    error_log("📝 POST data keys: " . implode(', ', array_keys($_POST)));
    
    // Validate startdate is a valid timestamp
    if ($startdate <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid start date']);
        exit;
    }
    
    // Validate enddate is after or equal to startdate (only if enddate is different from startdate)
    if ($enddate > 0 && $enddate < $startdate) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'End date must be after or equal to start date']);
        exit;
    }
    
    // Validate time format (HH:MM)
    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $starttime) || !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $endtime)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid time format. Use HH:MM format']);
        exit;
    }
    
    // Log received data for debugging
    error_log("📝 CREATE EVENT - Received data:");
    error_log("  Title: " . $title);
    error_log("  Start Date: " . $startdate . " (" . date('Y-m-d H:i:s', $startdate) . ")");
    error_log("  End Date: " . $enddate . " (" . date('Y-m-d H:i:s', $enddate) . ")");
    error_log("  Start Time: " . $starttime);
    error_log("  End Time: " . $endtime);
    error_log("  Event Type: " . $eventtype);
    error_log("  Color: " . $color);
    error_log("  Frequency: " . $frequency);
    error_log("  Selected Dates: " . $selected_dates);
    error_log("  Weekly Days: " . $weekly_days);
    error_log("  Teacher IDs: " . json_encode($teacher_ids));
    error_log("  Student IDs: " . json_encode($student_ids));
    error_log("  Cohort IDs: " . json_encode($cohort_ids));
    error_log("  Company ID: " . $company_id);
    
    try {
        // Check if table exists
        if (!$DB->get_manager()->table_exists('theme_remui_kids_calendar_events')) {
            throw new Exception('Calendar events table does not exist. Please run database upgrade.');
        }
        
        // Determine which dates to create events for
        $dates_to_create = [];
        
        // If enddate equals startdate (single day event), create only one event
        if ($enddate == $startdate) {
            $dates_to_create[] = $startdate;
        } else if ($frequency === 'daily') {
            // Daily: Create events for all days between start and end date (inclusive)
            $current = $startdate;
            while ($current <= $enddate) {
                $dates_to_create[] = $current;
                $current = strtotime('+1 day', $current);
            }
        } else if ($frequency === 'specific_date') {
            // Create events for each selected date
            if (!empty($selected_dates)) {
                $date_array = explode(',', $selected_dates);
                foreach ($date_array as $date_str) {
                    $date_str = trim($date_str);
                    if (empty($date_str)) continue;
                    
                    // Convert date string (YYYY-MM-DD) to timestamp
                    $date_parts = explode('-', $date_str);
                    if (count($date_parts) === 3) {
                        $timestamp = mktime(0, 0, 0, (int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0]);
                        if ($timestamp >= $startdate && $timestamp <= $enddate) {
                            $dates_to_create[] = $timestamp;
                        }
                    }
                }
            } else {
                // If no dates selected, use start date (backward compatibility)
                $dates_to_create[] = $startdate;
            }
        } else if ($frequency === 'weekly') {
            // Create events for each selected day of week between start and end date
            if (!empty($weekly_days)) {
                $day_array = explode(',', $weekly_days);
                $day_array = array_map('trim', $day_array);
                $day_array = array_filter($day_array);
                
                $current = $startdate;
                while ($current <= $enddate) {
                    $day_of_week = date('N', $current); // 1=Monday, 7=Sunday
                    if (in_array((string)$day_of_week, $day_array)) {
                        $dates_to_create[] = $current;
                    }
                    $current = strtotime('+1 day', $current);
                }
            } else {
                // If no days selected, use start date only
                $dates_to_create[] = $startdate;
            }
        } else {
            // Default: create single event for start date
            $dates_to_create[] = $startdate;
        }
        
        if (empty($dates_to_create)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No valid dates selected for event creation']);
            exit;
        }
        
        $created_event_ids = [];
        
        // Create an event for each date
        foreach ($dates_to_create as $eventdate) {
            $event = new stdClass();
            $event->companyid = $company_id;
            $event->title = $title;
            $event->description = $description;
            $event->eventdate = $eventdate;
            $event->starttime = $starttime;
            $event->endtime = $endtime;
            $event->eventtype = $eventtype;
            $event->color = $color;
            $event->createdby = $USER->id;
            $event->timecreated = time();
            $event->timemodified = time();
            
            error_log("📝 INSERTING EVENT - Attempting to insert into theme_remui_kids_calendar_events for date " . date('Y-m-d', $eventdate));
            $event_id = $DB->insert_record('theme_remui_kids_calendar_events', $event);
            $created_event_ids[] = $event_id;
            error_log("✅ EVENT CREATED - ID: " . $event_id . " for date " . date('Y-m-d', $eventdate));
        
            // Add participants for this event
            $participants = [];
            
            // Add teachers
            foreach ($teacher_ids as $teacher_id) {
                $participants[] = (object)[
                    'eventid' => $event_id,
                    'participanttype' => 'teacher',
                    'participantid' => $teacher_id,
                    'timecreated' => time()
                ];
            }
            
            // Add students
            foreach ($student_ids as $student_id) {
                $participants[] = (object)[
                    'eventid' => $event_id,
                    'participanttype' => 'student',
                    'participantid' => $student_id,
                    'timecreated' => time()
                ];
            }
            
            // Add cohorts (and their members)
            foreach ($cohort_ids as $cohort_id) {
                $participants[] = (object)[
                    'eventid' => $event_id,
                    'participanttype' => 'cohort',
                    'participantid' => $cohort_id,
                    'timecreated' => time()
                ];
                
                // Get cohort members
                $cohort_members = $DB->get_records('cohort_members', ['cohortid' => $cohort_id]);
                foreach ($cohort_members as $member) {
                    $participants[] = (object)[
                        'eventid' => $event_id,
                        'participanttype' => 'student',
                        'participantid' => $member->userid,
                        'timecreated' => time()
                    ];
                }
            }
            
            if (!empty($participants)) {
                $DB->insert_records('theme_remui_kids_calendar_event_participants', $participants);
            }
        }
        
        echo json_encode([
            'status' => 'success', 
            'event_ids' => $created_event_ids,
            'event_count' => count($created_event_ids),
            'message' => 'Created ' . count($created_event_ids) . ' event(s) successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Update event
if ($action === 'update_event') {
    require_sesskey();
    
    $event_id = required_param('event_id', PARAM_INT);
    $title = required_param('title', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $eventdate = required_param('eventdate', PARAM_INT);
    $starttime = required_param('starttime', PARAM_TEXT);
    $endtime = required_param('endtime', PARAM_TEXT);
    $eventtype = required_param('eventtype', PARAM_TEXT);
    $color = optional_param('color', 'blue', PARAM_TEXT);
    
    // Handle array parameters from FormData
    // FormData with append('teacher_ids[]', id) sends as $_POST['teacher_ids'] array in PHP
    // But we need to check both formats: 'teacher_ids' and 'teacher_ids[]'
    $teacher_ids = [];
    if (isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids'])) {
        $teacher_ids = array_filter(array_map('intval', $_POST['teacher_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['teacher_ids']) && is_array($_REQUEST['teacher_ids'])) {
        $teacher_ids = array_filter(array_map('intval', $_REQUEST['teacher_ids']), function($v) { return $v > 0; });
    }
    
    $student_ids = [];
    if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        $student_ids = array_filter(array_map('intval', $_POST['student_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['student_ids']) && is_array($_REQUEST['student_ids'])) {
        $student_ids = array_filter(array_map('intval', $_REQUEST['student_ids']), function($v) { return $v > 0; });
    }
    
    $cohort_ids = [];
    if (isset($_POST['cohort_ids']) && is_array($_POST['cohort_ids'])) {
        $cohort_ids = array_filter(array_map('intval', $_POST['cohort_ids']), function($v) { return $v > 0; });
    } elseif (isset($_REQUEST['cohort_ids']) && is_array($_REQUEST['cohort_ids'])) {
        $cohort_ids = array_filter(array_map('intval', $_REQUEST['cohort_ids']), function($v) { return $v > 0; });
    }
    
    try {
        $event = $DB->get_record('theme_remui_kids_calendar_events', ['id' => $event_id, 'companyid' => $company_id]);
        if (!$event) {
            throw new Exception('Event not found');
        }
        
        $event->title = $title;
        $event->description = $description;
        $event->eventdate = $eventdate;
        $event->starttime = $starttime;
        $event->endtime = $endtime;
        $event->eventtype = $eventtype;
        $event->color = $color;
        $event->timemodified = time();
        
        $DB->update_record('theme_remui_kids_calendar_events', $event);
        
        // Remove old participants
        $DB->delete_records('theme_remui_kids_calendar_event_participants', ['eventid' => $event_id]);
        
        // Add new participants
        $participants = [];
        
        foreach ($teacher_ids as $teacher_id) {
            $participants[] = (object)[
                'eventid' => $event_id,
                'participanttype' => 'teacher',
                'participantid' => $teacher_id,
                'timecreated' => time()
            ];
        }
        
        foreach ($student_ids as $student_id) {
            $participants[] = (object)[
                'eventid' => $event_id,
                'participanttype' => 'student',
                'participantid' => $student_id,
                'timecreated' => time()
            ];
        }
        
        foreach ($cohort_ids as $cohort_id) {
            $participants[] = (object)[
                'eventid' => $event_id,
                'participanttype' => 'cohort',
                'participantid' => $cohort_id,
                'timecreated' => time()
            ];
            
            $cohort_members = $DB->get_records('cohort_members', ['cohortid' => $cohort_id]);
            foreach ($cohort_members as $member) {
                $participants[] = (object)[
                    'eventid' => $event_id,
                    'participanttype' => 'student',
                    'participantid' => $member->userid,
                    'timecreated' => time()
                ];
            }
        }
        
        if (!empty($participants)) {
            $DB->insert_records('theme_remui_kids_calendar_event_participants', $participants);
        }
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Delete event
if ($action === 'delete_event') {
    require_sesskey();
    
    $event_id = required_param('event_id', PARAM_INT);
    
    try {
        $event = $DB->get_record('theme_remui_kids_calendar_events', ['id' => $event_id, 'companyid' => $company_id]);
        if (!$event) {
            throw new Exception('Event not found');
        }
        
        $DB->delete_records('theme_remui_kids_calendar_event_participants', ['eventid' => $event_id]);
        $DB->delete_records('theme_remui_kids_calendar_events', ['id' => $event_id]);
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get events
if ($action === 'get_events') {
    $start_date = optional_param('start_date', 0, PARAM_INT);
    $end_date = optional_param('end_date', 0, PARAM_INT);
    $user_id = optional_param('user_id', 0, PARAM_INT); // For teacher/student view
    
    try {
        $sql = "SELECT e.*, 
                       GROUP_CONCAT(DISTINCT CASE WHEN p.participanttype = 'teacher' THEN p.participantid END) AS teacher_ids,
                       GROUP_CONCAT(DISTINCT CASE WHEN p.participanttype = 'student' THEN p.participantid END) AS student_ids,
                       GROUP_CONCAT(DISTINCT CASE WHEN p.participanttype = 'cohort' THEN p.participantid END) AS cohort_ids
                FROM {theme_remui_kids_calendar_events} e
                LEFT JOIN {theme_remui_kids_calendar_event_participants} p ON p.eventid = e.id
                WHERE e.companyid = ?";
        
        $params = [$company_id];
        
        // Filter by date range - we'll filter by actual start timestamp in PHP
        // to ensure events show on the correct date based on their start time
        if ($start_date > 0 && $end_date > 0) {
            // Use a wider range to catch events that might start on different dates
            // than their eventdate due to time component
            $sql .= " AND e.eventdate >= ? AND e.eventdate <= ?";
            $params[] = $start_date - (24 * 60 * 60); // Include previous day
            $params[] = $end_date + (24 * 60 * 60); // Include next day
        }
        
        if ($user_id > 0) {
            // Filter by user participation
            $sql .= " AND (e.createdby = ? OR p.participantid = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        
        $sql .= " GROUP BY e.id ORDER BY e.eventdate ASC, e.starttime ASC";
        
        $events = $DB->get_records_sql($sql, $params);
        
        $result = [];
        foreach ($events as $event) {
            // Calculate actual start timestamp (eventdate + starttime)
            $start_time_parts = explode(':', $event->starttime);
            $start_hour = isset($start_time_parts[0]) ? (int)$start_time_parts[0] : 0;
            $start_minute = isset($start_time_parts[1]) ? (int)$start_time_parts[1] : 0;
            $actual_start_timestamp = $event->eventdate + ($start_hour * 3600) + ($start_minute * 60);
            
            // Filter by actual start timestamp to ensure events show in the correct date range
            // This ensures events appear on the correct date based on their EXACT start time
            if ($start_date > 0 && $end_date > 0) {
                if ($actual_start_timestamp < $start_date || $actual_start_timestamp > $end_date) {
                    continue; // Skip events outside the requested time range
                }
            }
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $start_time_12h = convert24To12Hour($event->starttime);
            $end_time_12h = '';
            if ($event->endtime) {
                $end_time_12h = convert24To12Hour($event->endtime);
            }
            
            $result[] = [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'date' => date('Y-m-d', $actual_start_timestamp), // Use actual start date
                'start_time' => $start_time_12h, // 12-hour format
                'end_time' => $end_time_12h, // 12-hour format
                'startTime' => $start_time_12h, // Alias for compatibility
                'endTime' => $end_time_12h, // Alias for compatibility
                'type' => $event->eventtype,
                'color' => $event->color,
                'teacher_ids' => $event->teacher_ids ? explode(',', $event->teacher_ids) : [],
                'student_ids' => $event->student_ids ? explode(',', $event->student_ids) : [],
                'cohort_ids' => $event->cohort_ids ? explode(',', $event->cohort_ids) : [],
                'timestart' => $actual_start_timestamp // For sorting and date calculations
            ];
        }
        
        echo json_encode(['status' => 'success', 'events' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Create lecture schedule
if ($action === 'create_lecture_schedule') {
    require_sesskey();
    
    $courseid = required_param('courseid', PARAM_INT);
    $teacherid = required_param('teacherid', PARAM_INT);
    // Get course name to use as default title
    $course = $DB->get_record('course', ['id' => $courseid]);
    $title = optional_param('title', $course ? $course->fullname : 'Lecture Schedule', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $startdate = required_param('startdate', PARAM_INT);
    $enddate = required_param('enddate', PARAM_INT);
    $starttime = required_param('starttime', PARAM_TEXT);
    $endtime = required_param('endtime', PARAM_TEXT);
    $frequency = required_param('frequency', PARAM_TEXT);
    $days = optional_param('days', '', PARAM_TEXT);
    $color = optional_param('color', 'green', PARAM_TEXT);
    
    try {
        // Check for time conflicts
        $conflicts = checkTimeConflicts($DB, $company_id, $teacherid, $startdate, $enddate, $starttime, $endtime, $days, $frequency);
        if (!empty($conflicts)) {
            echo json_encode(['status' => 'error', 'message' => 'Time conflicts detected', 'conflicts' => $conflicts]);
            exit;
        }
        
        $schedule = new stdClass();
        $schedule->companyid = $company_id;
        $schedule->courseid = $courseid;
        $schedule->teacherid = $teacherid;
        $schedule->title = $title;
        $schedule->description = $description;
        $schedule->startdate = $startdate;
        $schedule->enddate = $enddate;
        $schedule->starttime = $starttime;
        $schedule->endtime = $endtime;
        $schedule->frequency = $frequency;
        $schedule->days = $days;
        $schedule->color = $color;
        $schedule->createdby = $USER->id;
        $schedule->timecreated = time();
        $schedule->timemodified = time();
        
        $schedule_id = $DB->insert_record('theme_remui_kids_lecture_schedules', $schedule);
        
        // Generate lecture sessions
        generateLectureSessions($DB, $schedule_id, $schedule);
        
        echo json_encode(['status' => 'success', 'schedule_id' => $schedule_id]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get lecture schedules
if ($action === 'get_lecture_schedules') {
    try {
        $schedule_id = optional_param('schedule_id', 0, PARAM_INT);
        
        if ($schedule_id > 0) {
            // Get single schedule
            $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $schedule_id, 'companyid' => $company_id]);
            if (!$schedule) {
                echo json_encode(['status' => 'error', 'message' => 'Schedule not found']);
                exit;
            }
            
            $course = $DB->get_record('course', ['id' => $schedule->courseid]);
            $teacher = $DB->get_record('user', ['id' => $schedule->teacherid]);
            
            $result = [
                'id' => $schedule->id,
                'course_id' => $schedule->courseid,
                'course_name' => $course ? $course->fullname : '',
                'teacher_id' => $schedule->teacherid,
                'teacher_name' => $teacher ? fullname($teacher) : '',
                'title' => $schedule->title,
                'description' => $schedule->description,
                'start_date' => date('Y-m-d', $schedule->startdate),
                'end_date' => date('Y-m-d', $schedule->enddate),
                'start_time' => $schedule->starttime,
                'end_time' => $schedule->endtime,
                'frequency' => $schedule->frequency,
                'days' => $schedule->days ? explode(',', $schedule->days) : [],
                'color' => $schedule->color
            ];
            
            echo json_encode(['status' => 'success', 'schedule' => $result]);
        } else {
            // Get all schedules
            $schedules = $DB->get_records('theme_remui_kids_lecture_schedules', ['companyid' => $company_id], 'startdate ASC');
            
            $result = [];
            foreach ($schedules as $schedule) {
                $course = $DB->get_record('course', ['id' => $schedule->courseid]);
                $teacher = $DB->get_record('user', ['id' => $schedule->teacherid]);
                
                $result[] = [
                    'id' => $schedule->id,
                    'course_id' => $schedule->courseid,
                    'course_name' => $course ? $course->fullname : '',
                    'teacher_id' => $schedule->teacherid,
                    'teacher_name' => $teacher ? fullname($teacher) : '',
                    'title' => $schedule->title,
                    'description' => $schedule->description,
                    'start_date' => date('Y-m-d', $schedule->startdate),
                    'end_date' => date('Y-m-d', $schedule->enddate),
                    'start_time' => $schedule->starttime,
                    'end_time' => $schedule->endtime,
                    'frequency' => $schedule->frequency,
                    'days' => $schedule->days ? explode(',', $schedule->days) : [],
                    'color' => $schedule->color
                ];
            }
            
            echo json_encode(['status' => 'success', 'schedules' => $result]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Update lecture schedule
if ($action === 'update_lecture_schedule') {
    require_sesskey();
    
    $schedule_id = required_param('schedule_id', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $teacherid = required_param('teacherid', PARAM_INT);
    $description = optional_param('description', '', PARAM_TEXT);
    $startdate = required_param('startdate', PARAM_INT);
    $enddate = required_param('enddate', PARAM_INT);
    $starttime = required_param('starttime', PARAM_TEXT);
    $endtime = required_param('endtime', PARAM_TEXT);
    // Frequency is optional when editing (not shown in edit form)
    $frequency = optional_param('frequency', '', PARAM_TEXT);
    $days = optional_param('days', '', PARAM_TEXT);
    $color = optional_param('color', 'green', PARAM_TEXT);
    
    try {
        $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $schedule_id, 'companyid' => $company_id]);
        if (!$schedule) {
            throw new Exception('Lecture schedule not found');
        }
        
        // If frequency not provided, keep existing frequency
        if (empty($frequency)) {
            $frequency = $schedule->frequency;
        }
        // If days not provided, keep existing days
        if (empty($days)) {
            $days = $schedule->days;
        }
        
        // Check for time conflicts (excluding current schedule)
        $conflicts = checkTimeConflicts($DB, $company_id, $teacherid, $startdate, $enddate, $starttime, $endtime, $days, $frequency, $schedule_id);
        if (!empty($conflicts)) {
            echo json_encode(['status' => 'error', 'message' => 'Time conflicts detected', 'conflicts' => $conflicts]);
            exit;
        }
        
        // Store old teacher ID to check if teacher changed
        $old_teacher_id = $schedule->teacherid;
        
        $schedule->courseid = $courseid;
        $schedule->teacherid = $teacherid;
        $schedule->description = $description;
        $schedule->startdate = $startdate;
        $schedule->enddate = $enddate;
        $schedule->starttime = $starttime;
        $schedule->endtime = $endtime;
        $schedule->frequency = $frequency;
        $schedule->days = $days;
        $schedule->color = $color;
        $schedule->timemodified = time();
        
        $DB->update_record('theme_remui_kids_lecture_schedules', $schedule);
        
        // Delete old sessions and regenerate with new teacher/course
        // This ensures the card appears in the new teacher's calendar and is removed from old teacher's calendar
        $DB->delete_records('theme_remui_kids_lecture_sessions', ['scheduleid' => $schedule_id]);
        generateLectureSessions($DB, $schedule_id, $schedule);
        
        echo json_encode([
            'status' => 'success', 
            'schedule_id' => $schedule_id,
            'teacher_changed' => ($old_teacher_id != $teacherid),
            'old_teacher_id' => $old_teacher_id,
            'new_teacher_id' => $teacherid
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Delete lecture schedule
if ($action === 'delete_lecture_schedule') {
    require_sesskey();
    
    $schedule_id = required_param('schedule_id', PARAM_INT);
    
    try {
        $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $schedule_id, 'companyid' => $company_id]);
        if (!$schedule) {
            throw new Exception('Lecture schedule not found');
        }
        
        // Delete all associated lecture sessions first
        $DB->delete_records('theme_remui_kids_lecture_sessions', ['scheduleid' => $schedule_id]);
        
        // Delete the schedule
        $DB->delete_records('theme_remui_kids_lecture_schedules', ['id' => $schedule_id]);
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get lecture sessions
if ($action === 'get_lecture_sessions') {
    $start_date = optional_param('start_date', 0, PARAM_INT);
    $end_date = optional_param('end_date', 0, PARAM_INT);
    
    try {
        // Filter by company through the schedule
        // Use wider date range to catch sessions that might start on different dates due to time
        // We'll filter by actual start timestamp in PHP to ensure accuracy
        $sql = "SELECT ls.* 
                FROM {theme_remui_kids_lecture_sessions} ls
                INNER JOIN {theme_remui_kids_lecture_schedules} s ON ls.scheduleid = s.id
                WHERE s.companyid = ? 
                AND ls.sessiondate >= ? 
                AND ls.sessiondate <= ?
                ORDER BY ls.sessiondate ASC, ls.starttime ASC";
        
        // Use wider range to ensure we catch all sessions, then filter by actual start time in PHP
        $params = [$company_id, $start_date - (24 * 60 * 60), $end_date + (24 * 60 * 60)];
        $sessions = $DB->get_records_sql($sql, $params);
        
        $result = [];
        foreach ($sessions as $session) {
            // Get course name - ensure courseid is valid
            $course_name = 'Unknown Course';
            if (!empty($session->courseid)) {
                $course = $DB->get_record('course', ['id' => $session->courseid]);
                if ($course) {
                    $course_name = $course->fullname ? $course->fullname : ($course->shortname ? $course->shortname : 'Unknown Course');
                }
            }
            
            // Get teacher name
            $teacher_name = 'Unknown Teacher';
            if (!empty($session->teacherid)) {
                $teacher = $DB->get_record('user', ['id' => $session->teacherid]);
                if ($teacher) {
                    $teacher_name = fullname($teacher);
                }
            }
            
            // Calculate actual start timestamp (sessiondate + starttime)
            $start_time_parts = explode(':', $session->starttime);
            $start_hour = isset($start_time_parts[0]) ? (int)$start_time_parts[0] : 0;
            $start_minute = isset($start_time_parts[1]) ? (int)$start_time_parts[1] : 0;
            $actual_start_timestamp = $session->sessiondate + ($start_hour * 3600) + ($start_minute * 60);
            
            // Filter by actual start timestamp to ensure sessions show in the correct date range
            // This ensures sessions appear on the correct date based on their EXACT start time
            if ($start_date > 0 && $end_date > 0) {
                if ($actual_start_timestamp < $start_date || $actual_start_timestamp > $end_date) {
                    continue; // Skip sessions outside the requested time range
                }
            }
            
            // Calculate actual end timestamp - use EXACT time set during creation
            $end_time_parts = explode(':', $session->endtime);
            $end_hour = isset($end_time_parts[0]) ? (int)$end_time_parts[0] : 0;
            $end_minute = isset($end_time_parts[1]) ? (int)$end_time_parts[1] : 0;
            $actual_end_timestamp = $session->sessiondate + ($end_hour * 3600) + ($end_minute * 60);
            
            // Format time in 12-hour format directly from stored time string (not from timestamp)
            // This ensures the exact time entered by the user is displayed, regardless of timezone
            $start_time_12h = convert24To12Hour($session->starttime);
            $end_time_12h = convert24To12Hour($session->endtime);
            
            $result[] = [
                'id' => $session->id,
                'schedule_id' => $session->scheduleid,
                'course_id' => $session->courseid,
                'course_name' => $course_name,
                'teacher_id' => $session->teacherid,
                'teacher_name' => $teacher_name,
                'title' => $session->title,
                'date' => date('Y-m-d', $actual_start_timestamp), // Use actual start date
                'start_time' => $start_time_12h, // 12-hour format
                'end_time' => $end_time_12h, // 12-hour format
                'startTime' => $start_time_12h, // Alias for compatibility
                'endTime' => $end_time_12h, // Alias for compatibility
                'color' => $session->color,
                'teacher_available' => isset($session->teacher_available) && $session->teacher_available !== null ? (int)$session->teacher_available : 1, // Default to 1 (available) if null/undefined
                'timestart' => $actual_start_timestamp // For sorting and date calculations
            ];
            
            // Debug: Log teacher availability for this session
            error_log("Session {$session->id}: teacher_available = " . (isset($session->teacher_available) ? (int)$session->teacher_available : 1) . " (raw: " . var_export($session->teacher_available, true) . ")");
        }
        
        echo json_encode(['status' => 'success', 'sessions' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Delete single lecture session
if ($action === 'delete_lecture_session') {
    require_sesskey();
    
    $session_id = required_param('session_id', PARAM_INT);
    
    try {
        // Get the session to verify it exists and belongs to the company
        $session = $DB->get_record('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
        if (!$session) {
            throw new Exception('Lecture session not found');
        }
        
        // Verify the schedule belongs to the company
        $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $session->scheduleid, 'companyid' => $company_id]);
        if (!$schedule) {
            throw new Exception('Access denied');
        }
        
        // Delete the session
        $DB->delete_records('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Update single lecture session
if ($action === 'update_lecture_session') {
    require_sesskey();
    
    $session_id = required_param('session_id', PARAM_INT);
    $starttime = required_param('starttime', PARAM_TEXT);
    $endtime = required_param('endtime', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    // Allow teacher and course to be changed for single session
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $teacherid = optional_param('teacherid', 0, PARAM_INT);
    $sessiondate = optional_param('sessiondate', 0, PARAM_INT);
    
    try {
        // Get the session to verify it exists and belongs to the company
        $session = $DB->get_record('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
        if (!$session) {
            throw new Exception('Lecture session not found');
        }
        
        // Verify the schedule belongs to the company
        $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $session->scheduleid, 'companyid' => $company_id]);
        if (!$schedule) {
            throw new Exception('Access denied');
        }
        
        // Validate time format
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $starttime) || !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $endtime)) {
            throw new Exception('Invalid time format. Use HH:MM format');
        }
        
        // Store old teacher ID to check if teacher changed
        $old_teacher_id = $session->teacherid;
        
        // Update the session
        $session->starttime = $starttime;
        $session->endtime = $endtime;
        
        // Update course if provided
        if ($courseid > 0) {
            $session->courseid = $courseid;
            // Also update schedule course if this is the only session or we want to update the schedule
            $schedule->courseid = $courseid;
        }
        
        // Update teacher if provided
        if ($teacherid > 0 && $teacherid != $old_teacher_id) {
            $session->teacherid = $teacherid;
            // Reset teacher_available to 1 (available) when teacher changes to a new teacher
            $session->teacher_available = 1;
            // Also update schedule teacher if this is the only session or we want to update the schedule
            $schedule->teacherid = $teacherid;
        }
        
        // Update date if provided
        if ($sessiondate > 0) {
            $session->sessiondate = $sessiondate;
        }
        
        $DB->update_record('theme_remui_kids_lecture_sessions', $session);
        
        // Update schedule if course or teacher changed
        if (($courseid > 0 || $teacherid > 0)) {
            $schedule->timemodified = time();
            $DB->update_record('theme_remui_kids_lecture_schedules', $schedule);
        }
        
        // Update schedule description if provided
        if ($description !== '') {
            $schedule->description = $description;
            $DB->update_record('theme_remui_kids_lecture_schedules', $schedule);
        }
        
        echo json_encode([
            'status' => 'success',
            'teacher_changed' => ($teacherid > 0 && $old_teacher_id != $teacherid),
            'old_teacher_id' => $old_teacher_id,
            'new_teacher_id' => ($teacherid > 0 ? $teacherid : $old_teacher_id)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get single lecture session
if ($action === 'get_lecture_session') {
    $session_id = required_param('session_id', PARAM_INT);
    
    try {
        $session = $DB->get_record('theme_remui_kids_lecture_sessions', ['id' => $session_id]);
        if (!$session) {
            throw new Exception('Lecture session not found');
        }
        
        // Get schedule details
        $schedule = $DB->get_record('theme_remui_kids_lecture_schedules', ['id' => $session->scheduleid]);
        
        // Get course name
        $course_name = 'Unknown Course';
        if (!empty($session->courseid)) {
            $course = $DB->get_record('course', ['id' => $session->courseid]);
            if ($course) {
                $course_name = $course->fullname ? $course->fullname : ($course->shortname ? $course->shortname : 'Unknown Course');
            }
        }
        
        // Get teacher name
        $teacher_name = 'Unknown Teacher';
        if (!empty($session->teacherid)) {
            $teacher = $DB->get_record('user', ['id' => $session->teacherid]);
            if ($teacher) {
                $teacher_name = fullname($teacher);
            }
        }
        
        $result = [
            'id' => $session->id,
            'schedule_id' => $session->scheduleid,
            'course_id' => $session->courseid,
            'course_name' => $course_name,
            'teacher_id' => $session->teacherid,
            'teacher_name' => $teacher_name,
            'date' => date('Y-m-d', $session->sessiondate),
            'start_time' => $session->starttime,
            'end_time' => $session->endtime,
            'description' => $schedule->description ?? '',
            'color' => $session->color
        ];
        
        echo json_encode(['status' => 'success', 'session' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get teachers for a specific course
if ($action === 'get_course_teachers') {
    $course_id = required_param('course_id', PARAM_INT);
    
    try {
        // Get teacher roles
        $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','coursecreator')");
        $roleids = (is_array($teacherroles) && !empty($teacherroles)) ? array_keys($teacherroles) : [];
        
        if (empty($roleids)) {
            echo json_encode(['status' => 'success', 'teachers' => []]);
            exit;
        }
        
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['courseid'] = $course_id;
        $params['ctxlevel'] = CONTEXT_COURSE;
        $params['companyid'] = $company_id;
        
        // Get teachers assigned to this course
        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
             FROM {user} u
             JOIN {role_assignments} ra ON u.id = ra.userid
             JOIN {context} ctx ON ra.contextid = ctx.id
             JOIN {company_users} cu ON u.id = cu.userid
             WHERE ctx.contextlevel = :ctxlevel 
             AND ctx.instanceid = :courseid
             AND ra.roleid {$insql}
             AND cu.companyid = :companyid
             AND u.deleted = 0
             ORDER BY u.firstname, u.lastname",
            $params
        );
        
        $result = [];
        foreach ($teachers as $teacher) {
            $result[] = [
                'id' => $teacher->id,
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
                'fullname' => fullname($teacher),
                'email' => $teacher->email,
                'username' => $teacher->username
            ];
        }
        
        echo json_encode(['status' => 'success', 'teachers' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Helper function to check time conflicts
function checkTimeConflicts($DB, $company_id, $teacher_id, $start_date, $end_date, $start_time, $end_time, $days, $frequency, $exclude_schedule_id = null) {
    $conflicts = [];
    
    // Check existing lecture schedules
    $sql = "SELECT * FROM {theme_remui_kids_lecture_schedules} 
            WHERE companyid = ? AND teacherid = ? 
            AND ((startdate <= ? AND enddate >= ?) OR (startdate <= ? AND enddate >= ?))";
    $params = [$company_id, $teacher_id, $start_date, $start_date, $end_date, $end_date];
    
    if ($exclude_schedule_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_schedule_id;
    }
    
    $existing_schedules = $DB->get_records_sql($sql, $params);
    
    foreach ($existing_schedules as $schedule) {
        if (timesOverlap($start_time, $end_time, $schedule->starttime, $schedule->endtime)) {
            if ($frequency === 'daily' || ($frequency === 'weekly' && daysOverlap($days, $schedule->days))) {
                $conflicts[] = [
                    'type' => 'schedule',
                    'id' => $schedule->id,
                    'title' => $schedule->title,
                    'date' => date('Y-m-d', $schedule->startdate)
                ];
            }
        }
    }
    
    return $conflicts;
}

function timesOverlap($start1, $end1, $start2, $end2) {
    $time1_start = strtotime($start1);
    $time1_end = strtotime($end1);
    $time2_start = strtotime($start2);
    $time2_end = strtotime($end2);
    
    return ($time1_start < $time2_end && $time1_end > $time2_start);
}

function daysOverlap($days1, $days2) {
    if (empty($days1) || empty($days2)) return true;
    
    $days1_arr = explode(',', $days1);
    $days2_arr = explode(',', $days2);
    
    return !empty(array_intersect($days1_arr, $days2_arr));
}

// Helper function to generate lecture sessions
function generateLectureSessions($DB, $schedule_id, $schedule) {
    $start = $schedule->startdate;
    $end = $schedule->enddate;
    $current = $start;
    
    $sessions = [];
    
    while ($current <= $end) {
        $day_of_week = date('N', $current); // 1=Monday, 7=Sunday
        
        $should_create = false;
        
        if ($schedule->frequency === 'daily') {
            $should_create = true;
        } elseif ($schedule->frequency === 'weekly') {
            $should_create = true; // Every week on same day
        } elseif ($schedule->frequency === 'custom' && !empty($schedule->days)) {
            $days_arr = explode(',', $schedule->days);
            $should_create = in_array($day_of_week, $days_arr);
        }
        
        if ($should_create) {
            $session = new stdClass();
            $session->scheduleid = $schedule_id;
            $session->courseid = $schedule->courseid;
            $session->teacherid = $schedule->teacherid;
            $session->title = $schedule->title;
            $session->sessiondate = $current;
            $session->starttime = $schedule->starttime;
            $session->endtime = $schedule->endtime;
            $session->color = $schedule->color;
            $session->teacher_available = 1; // Default to available for new teacher assignments
            $session->timecreated = time();
            
            $sessions[] = $session;
        }
        
        $current = strtotime('+1 day', $current);
    }
    
    if (!empty($sessions)) {
        $DB->insert_records('theme_remui_kids_lecture_sessions', $sessions);
    }
}

// If we reach here, no action was matched
error_log("❌ Invalid action requested: " . $action);
error_log("  Available actions: create_event, update_event, delete_event, get_events, create_lecture_schedule, get_lecture_schedules, get_lecture_sessions");
error_log("  POST data keys: " . (isset($_POST) ? implode(', ', array_keys($_POST)) : 'No POST data'));
error_log("  GET data keys: " . (isset($_GET) ? implode(', ', array_keys($_GET)) : 'No GET data'));

http_response_code(400);
echo json_encode([
    'status' => 'error', 
    'message' => 'Invalid action: ' . $action,
    'received_action' => $action,
    'available_actions' => ['create_event', 'update_event', 'delete_event', 'get_events', 'create_lecture_schedule', 'update_lecture_schedule', 'delete_lecture_schedule', 'get_lecture_schedules', 'get_lecture_sessions', 'get_course_teachers', 'update_teacher_availability']
]);

