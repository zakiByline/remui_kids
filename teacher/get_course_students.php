<?php
/**
 * Get Course Students API
 * Fetches students enrolled in a specific course
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

// Set JSON header
header('Content-Type: application/json');

try {
    // Get course ID
    $courseid = required_param('courseid', PARAM_INT);
    
    // Validate course
    $course = get_course($courseid);
    if (!$course) {
        throw new Exception('Invalid course ID');
    }
    
    // Check course access
    $context = context_course::instance($courseid);
    require_capability('moodle/course:viewparticipants', $context);
    
    // Get enrolled students (role ID 5 is typically 'student' in Moodle)
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE e.courseid = :courseid
              AND ctx.contextlevel = 50
              AND ctx.instanceid = :courseid2
              AND ra.roleid = 5
              AND u.deleted = 0
              AND u.suspended = 0
            ORDER BY u.lastname, u.firstname";
    
    $students = $DB->get_records_sql($sql, array(
        'courseid' => $courseid,
        'courseid2' => $courseid
    ));
    
    // Format student data
    $formatted_students = array();
    foreach ($students as $student) {
        $formatted_students[] = array(
            'id' => $student->id,
            'fullname' => fullname($student),
            'firstname' => $student->firstname,
            'lastname' => $student->lastname,
            'email' => $student->email
        );
    }
    
    // Return success response
    echo json_encode(array(
        'success' => true,
        'students' => $formatted_students,
        'count' => count($formatted_students)
    ));
    
} catch (Exception $e) {
    // Log error
    error_log('get_course_students.php - Error: ' . $e->getMessage());
    
    // Return error response
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage(),
        'students' => array()
    ));
}
?>
