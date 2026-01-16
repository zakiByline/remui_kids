<?php
/**
 * Create Group API
 * Handles group creation for assignment assignment
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/course:managegroups', context_system::instance());

// Set AJAX script
define('AJAX_SCRIPT', true);

// Require sesskey for security
require_sesskey();

// Set JSON header
header('Content-Type: application/json');

try {
    // Get parameters
    $courseid = required_param('courseid', PARAM_INT);
    $name = required_param('name', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    
    // Validate course
    $course = get_course($courseid);
    if (!$course) {
        throw new Exception('Invalid course ID');
    }
    
    // Check course access
    $context = context_course::instance($courseid);
    require_capability('moodle/course:managegroups', $context);
    
    // Validate group name
    $name = trim($name);
    if (empty($name)) {
        throw new Exception('Group name cannot be empty');
    }
    
    if (strlen($name) < 2) {
        throw new Exception('Group name must be at least 2 characters long');
    }
    
    if (strlen($name) > 100) {
        throw new Exception('Group name cannot exceed 100 characters');
    }
    
    // Check if group name already exists in this course
    $existing = $DB->get_record('groups', array(
        'courseid' => $courseid,
        'name' => $name
    ));
    
    if ($existing) {
        throw new Exception('A group with this name already exists in this course');
    }
    
    // Prepare group data
    $group_data = new stdClass();
    $group_data->courseid = $courseid;
    $group_data->name = $name;
    $group_data->description = $description;
    $group_data->descriptionformat = FORMAT_HTML;
    $group_data->enrolmentkey = ''; // No enrolment key by default
    $group_data->picture = 0;
    $group_data->hidepicture = 0;
    $group_data->timecreated = time();
    $group_data->timemodified = time();
    
    // Insert group
    $group_id = $DB->insert_record('groups', $group_data);
    
    if (!$group_id) {
        throw new Exception('Failed to create group');
    }
    
    // Add students to the group
    $student_ids = optional_param_array('student_ids', array(), PARAM_INT);
    $added_count = 0;
    
    if (!empty($student_ids)) {
        foreach ($student_ids as $student_id) {
            // Verify student is enrolled in the course
            $enrolled = $DB->record_exists_sql(
                "SELECT 1 FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid",
                array('courseid' => $courseid, 'userid' => $student_id)
            );
            
            if ($enrolled) {
                // Check if already a member
                $existing = $DB->get_record('groups_members', array(
                    'groupid' => $group_id,
                    'userid' => $student_id
                ));
                
                if (!$existing) {
                    $member_data = new stdClass();
                    $member_data->groupid = $group_id;
                    $member_data->userid = $student_id;
                    $member_data->timeadded = time();
                    $member_data->component = '';
                    $member_data->itemid = 0;
                    
                    if ($DB->insert_record('groups_members', $member_data)) {
                        $added_count++;
                        
                        // Trigger member added event
                        $event = \core\event\group_member_added::create(array(
                            'objectid' => $group_id,
                            'context' => $context,
                            'relateduserid' => $student_id,
                            'other' => array(
                                'component' => '',
                                'itemid' => 0
                            )
                        ));
                        $event->trigger();
                    }
                }
            }
        }
    }
    
    // Log the group creation
    $event = \core\event\group_created::create(array(
        'objectid' => $group_id,
        'context' => $context,
        'other' => array(
            'name' => $name,
            'description' => $description
        )
    ));
    $event->trigger();
    
    // Return success response
    echo json_encode(array(
        'success' => true,
        'message' => 'Group created successfully',
        'group_id' => $group_id,
        'group_name' => $name,
        'member_count' => $added_count
    ));
    
} catch (Exception $e) {
    // Log error
    error_log('create_group.php - Error: ' . $e->getMessage());
    
    // Return error response
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}
?>
