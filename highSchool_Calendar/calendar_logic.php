<?php
/**
 * High School Calendar Logic
 * Contains all business logic for the high school calendar page
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Check if event was created by teacher or admin
 * 
 * @param object $event Event object from database
 * @return bool True if created by teacher/admin
 */
function remui_kids_is_event_created_by_staff($event) {
    global $DB;
    
    // If event has no userid, it's a system/course event (likely created by admin/teacher)
    if (empty($event->userid)) {
        return true;
    }
    
    // Check if the user who created the event is a teacher or admin
    $event_creator = $DB->get_record('user', array('id' => $event->userid));
    if (!$event_creator) {
        return false;
    }
    
    // Check user roles
    $context = context_system::instance();
    $creator_roles = get_user_roles($context, $event->userid);
    
    foreach ($creator_roles as $role) {
        if (in_array($role->shortname, array('editingteacher', 'teacher', 'manager', 'coursecreator', 'admin'))) {
            return true;
        }
    }
    
    // Check if user is site admin
    if (is_siteadmin($event->userid)) {
        return true;
    }
    
    return false;
}

/**
 * Check if current user can edit an event
 * 
 * @param object $event Event object from database
 * @param int $current_userid Current user ID
 * @return bool True if user can edit the event
 */
function remui_kids_can_user_edit_event($event, $current_userid) {
    global $DB, $CFG;
    
    // Load calendar event object for permission check
    try {
        require_once($CFG->dirroot . '/calendar/lib.php');
        
        // Get full event record
        $event_record = $DB->get_record('event', array('id' => $event->id));
        if (!$event_record) {
            return false;
        }
        
        // Create calendar_event object
        $calendar_event = new calendar_event($event_record);
        
        // Check if user can edit using Moodle's permission system
        if (function_exists('calendar_edit_event_allowed')) {
            return calendar_edit_event_allowed($calendar_event);
        }
        
        // Fallback: Check if event was created by current user and is a user event
        if ($event_record->userid == $current_userid && empty($event_record->courseid)) {
            // User can edit their own personal events
            return true;
        }
        
        // If event was created by teacher/admin, student cannot edit
        if (remui_kids_is_event_created_by_staff($event_record)) {
            // Check if current user is also a teacher/admin
            $context = context_system::instance();
            $user_roles = get_user_roles($context, $current_userid);
            foreach ($user_roles as $role) {
                if (in_array($role->shortname, array('editingteacher', 'teacher', 'manager', 'coursecreator', 'admin'))) {
                    return true; // Teacher/admin can edit
                }
            }
            return false; // Student cannot edit teacher/admin events
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking event edit permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Get calendar events data for high school students
 * 
 * @param int $userid User ID
 * @return array Array containing events_data, upcoming_events, and statistics
 */
function remui_kids_get_highschool_calendar_data($userid) {
    global $DB;
    
    $events_data = array();
    $upcoming_events = array();
    
    try {
        // Get user's enrolled courses
        $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname');
        $course_ids = array_keys($courses);
        
        // Initialize events array
        $events = array();
        
        // Build query to get events - include both course events and user events
        try {
            $sql = "";
            $params = array();
            
            if (!empty($course_ids)) {
                // User has enrolled courses - get both course events and user events
                $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
                $params = array_merge($course_ids, array($userid, $userid, time() - (30 * 24 * 60 * 60)));
                
                $sql = "
                    SELECT e.*, c.fullname as course_name, c.shortname as course_shortname
                    FROM {event} e
                    LEFT JOIN {course} c ON e.courseid = c.id
                    WHERE (
                        (e.courseid IN ($placeholders))
                        OR (e.userid = ? AND e.eventtype = 'user')
                        OR (e.userid = ? AND e.courseid = 0)
                    )
                    AND e.timestart >= ?
                    ORDER BY e.timestart ASC
                    LIMIT 100
                ";
            } else {
                // User has no enrolled courses - just get their personal events
                $params = array($userid, $userid, time() - (30 * 24 * 60 * 60));
                
                $sql = "
                    SELECT e.*, c.fullname as course_name, c.shortname as course_shortname
                    FROM {event} e
                    LEFT JOIN {course} c ON e.courseid = c.id
                    WHERE (
                        (e.userid = ? AND e.eventtype = 'user')
                        OR (e.userid = ? AND e.courseid = 0)
                    )
                    AND e.timestart >= ?
                    ORDER BY e.timestart ASC
                    LIMIT 100
                ";
            }
            
            $events = $DB->get_records_sql($sql, $params);
            
            // Ensure userid is set for each event (it should be from the query)
            foreach ($events as $event) {
                if (!isset($event->userid)) {
                    $event->userid = 0;
                }
            }
        } catch (Exception $e) {
            // If calendar events table doesn't exist or query fails, use empty array
            error_log("Calendar events query failed: " . $e->getMessage());
            $events = array();
        }
        
        // Get school admin calendar events for this student
        require_once(__DIR__ . '/../lib.php');
        $start_timestamp = time() - (30 * 24 * 60 * 60);
        $end_timestamp = time() + (30 * 24 * 60 * 60);
        $admin_events = theme_remui_kids_get_school_admin_calendar_events($userid, $start_timestamp, $end_timestamp);
        
        // Convert admin events to the same format as Moodle events
        foreach ($admin_events as $admin_event) {
            $admin_event_obj = new stdClass();
            $admin_event_obj->id = 'admin_' . $admin_event->admin_event_id;
            $admin_event_obj->name = $admin_event->name;
            $admin_event_obj->description = $admin_event->description ?? '';
            $admin_event_obj->timestart = $admin_event->timestart;
            $admin_event_obj->timeduration = $admin_event->timeduration ?? 0;
            $admin_event_obj->eventtype = $admin_event->eventtype ?? 'meeting';
            $admin_event_obj->courseid = 0;
            $admin_event_obj->userid = $userid;
            $admin_event_obj->course_name = 'School Event';
            $admin_event_obj->course_shortname = 'SCH';
            $admin_event_obj->modulename = '';
            $admin_event_obj->instance = 0;
            $admin_event_obj->admin_event = true;
            $admin_event_obj->admin_color = $admin_event->color ?? 'blue';
            $events[] = $admin_event_obj;
        }
        
        $current_time = time();
        $today_start = strtotime('today');
        $today_end = strtotime('tomorrow') - 1;
        
        foreach ($events as $event) {
            $is_today = ($event->timestart >= $today_start && $event->timestart <= $today_end);
            $is_overdue = ($event->timestart < $current_time && $event->timestart < $today_start);
            $is_upcoming = ($event->timestart > $current_time);
            
            // Determine event type and priority
            $event_type = 'event';
            $priority = 'medium';
            $status = 'upcoming';
            
            // Check if this is an admin event
            if (isset($event->admin_event) && $event->admin_event) {
                // Use the event type from admin event
                $admin_type = strtolower($event->eventtype ?? 'meeting');
                if ($admin_type === 'meeting') {
                    $event_type = 'meeting';
                    $priority = 'high';
                } elseif ($admin_type === 'lecture') {
                    $event_type = 'lecture';
                    $priority = 'high';
                } elseif ($admin_type === 'exam') {
                    $event_type = 'exam';
                    $priority = 'high';
                } elseif ($admin_type === 'activity') {
                    $event_type = 'activity';
                    $priority = 'medium';
                } else {
                    $event_type = 'school event';
                    $priority = 'medium';
                }
            } elseif (strpos(strtolower($event->name), 'course start') !== false) {
                $event_type = 'course start';
                $priority = 'high';
            } elseif (strpos(strtolower($event->name), 'course end') !== false) {
                $event_type = 'course end';
                $priority = 'high';
            } elseif (strpos(strtolower($event->name), 'assignment') !== false || strpos(strtolower($event->name), 'due') !== false) {
                $event_type = 'assignment';
                $priority = 'high';
            } elseif (strpos(strtolower($event->name), 'exam') !== false || strpos(strtolower($event->name), 'test') !== false) {
                $event_type = 'exam';
                $priority = 'high';
            } elseif (strpos(strtolower($event->name), 'holiday') !== false) {
                $event_type = 'holiday';
                $priority = 'low';
            }
            
            if ($is_overdue) {
                $status = 'overdue';
            } elseif ($is_today) {
                $status = 'today';
            } elseif ($is_upcoming) {
                $status = 'upcoming';
            } else {
                $status = 'completed';
            }
                
            // Get instructor information
            $instructor_name = 'System';
            if ($event->courseid) {
                $course_context = context_course::instance($event->courseid);
                $teachers = get_users_by_capability($course_context, 'moodle/course:update', 'u.id, u.firstname, u.lastname');
                if (!empty($teachers)) {
                    $teacher = reset($teachers);
                    $instructor_name = fullname($teacher);
                }
            }
            
            // Check if user can edit this event
            $can_edit = remui_kids_can_user_edit_event($event, $userid);
            $is_created_by_staff = remui_kids_is_event_created_by_staff($event);
            
            // Get activity URL if event is linked to an activity (assignment, quiz, etc.)
            $activity_url = '';
            if (!empty($event->modulename) && !empty($event->instance)) {
                try {
                    // Get course module from modulename and instance
                    // If courseid is 0 or empty, get_coursemodule_from_instance will find it from the module
                    $courseid_for_cm = !empty($event->courseid) ? $event->courseid : 0;
                    $cm = get_coursemodule_from_instance($event->modulename, $event->instance, $courseid_for_cm);
                    if ($cm && !empty($cm->id)) {
                        // Create activity URL: /mod/{modulename}/view.php?id={cmid}
                        $activity_url = (new moodle_url('/mod/' . $event->modulename . '/view.php', array('id' => $cm->id)))->out(false);
                    }
                } catch (Exception $e) {
                    // If we can't get the course module, activity_url remains empty
                    error_log("Calendar: Could not get activity URL for event {$event->id}: " . $e->getMessage());
                } catch (dml_exception $e) {
                    // Database exception - activity might not exist
                    error_log("Calendar: Activity not found for event {$event->id} (module: {$event->modulename}, instance: {$event->instance})");
                }
            }
            
            // For admin events, use school event as course name
            $course_name = 'General';
            $course_shortname = 'GEN';
            if (isset($event->admin_event) && $event->admin_event) {
                $course_name = 'School Event';
                $course_shortname = 'SCH';
            } else {
                $course_name = $event->course_name ?: 'General';
                $course_shortname = $event->course_shortname ?: 'GEN';
            }
            
            $event_data = array(
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description ? strip_tags($event->description) : 'No description available',
                'course_name' => $course_name,
                'course_shortname' => $course_shortname,
                'instructor' => $instructor_name,
                'timestart' => $event->timestart,
                'date_formatted' => date('n/j/Y', $event->timestart),
                'time_formatted' => date('g:i A', $event->timestart),
                'event_type' => $event_type,
                'priority' => $priority,
                'status' => $status,
                'is_today' => $is_today,
                'is_overdue' => $is_overdue,
                'is_upcoming' => $is_upcoming,
                'can_edit' => $can_edit,
                'is_created_by_staff' => $is_created_by_staff,
                'event_url' => isset($event->admin_event) && $event->admin_event ? '#' : (new moodle_url('/calendar/view.php', array('view' => 'event', 'id' => $event->id)))->out(false),
                'edit_event_url' => $can_edit ? (new moodle_url('/calendar/event.php', array('action' => 'edit', 'id' => $event->id)))->out(false) : '',
                'activity_url' => $activity_url,
                'has_activity' => !empty($activity_url),
                'admin_event' => isset($event->admin_event) && $event->admin_event,
                'admin_color' => isset($event->admin_color) ? $event->admin_color : null
            );
            
            $events_data[] = $event_data;
            
            // Add to upcoming events if it's upcoming
            if ($is_upcoming && count($upcoming_events) < 5) {
                $upcoming_events[] = $event_data;
            }
        }
        
    } catch (Exception $e) {
        error_log("Calendar events fetch error: " . $e->getMessage());
    }
    
    // Calculate statistics
    $total_events = count($events_data);
    $today_events = 0;
    $overdue_events = 0;
    $upcoming_count = count($upcoming_events);
    
    foreach ($events_data as $event) {
        if ($event['is_today']) {
            $today_events++;
        }
        if ($event['is_overdue']) {
            $overdue_events++;
        }
    }
    
    return array(
        'events' => $events_data,
        'upcoming_events' => $upcoming_events,
        'total_events' => $total_events,
        'today_events' => $today_events,
        'overdue_events' => $overdue_events,
        'upcoming_count' => $upcoming_count
    );
}

/**
 * Get user grade level from profile or cohort
 * 
 * @param int $userid User ID
 * @return array Array containing user_grade and is_highschool flag
 */
function remui_kids_get_user_grade_info($userid) {
    $user_grade = 'Unknown';
    $is_highschool = false;
    $user_cohorts = cohort_get_user_cohorts($userid);
    
    // Check user profile custom field for grade
    $user_profile_fields = profile_user_record($userid);
    if (isset($user_profile_fields->grade)) {
        $user_grade = $user_profile_fields->grade;
        // If profile has a high school grade, mark as high school
        if (preg_match('/grade\s*(?:9|10|11|12)/i', $user_grade)) {
            $is_highschool = true;
        }
    } else {
        // Fallback to cohort-based detection
        foreach ($user_cohorts as $cohort) {
            $cohort_name = strtolower($cohort->name);
            // Use regex for better matching
            if (preg_match('/grade\s*(?:9|10|11|12)/i', $cohort_name)) {
                // Extract grade number
                if (preg_match('/grade\s*9/i', $cohort_name)) {
                    $user_grade = 'Grade 9';
                } elseif (preg_match('/grade\s*10/i', $cohort_name)) {
                    $user_grade = 'Grade 10';
                } elseif (preg_match('/grade\s*11/i', $cohort_name)) {
                    $user_grade = 'Grade 11';
                } elseif (preg_match('/grade\s*12/i', $cohort_name)) {
                    $user_grade = 'Grade 12';
                }
                $is_highschool = true;
                break;
            }
        }
    }
    
    return array(
        'user_grade' => $user_grade,
        'is_highschool' => $is_highschool
    );
}

/**
 * Check if user has access to high school calendar
 * 
 * @param object $context System context
 * @param int $userid User ID
 * @return bool True if user has access
 */
function remui_kids_check_calendar_access($context, $userid) {
    $user_roles = get_user_roles($context, $userid);
    $is_student = false;
    
    foreach ($user_roles as $role) {
        if ($role->shortname === 'student') {
            $is_student = true;
            break;
        }
    }
    
    // Also check for editingteacher and teacher roles as they might be testing the page
    foreach ($user_roles as $role) {
        if ($role->shortname === 'editingteacher' || $role->shortname === 'teacher' || $role->shortname === 'manager') {
            $is_student = true; // Allow teachers/managers to view the page
            break;
        }
    }
    
    return $is_student;
}
