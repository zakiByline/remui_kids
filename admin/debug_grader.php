<?php
/**
 * Debug endpoint for grader report
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->libdir.'/gradelib.php');

header('Content-Type: application/json');

// Check if user is logged in and has permissions
require_login();
require_capability('moodle/grade:viewall', context_system::instance());

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$school_id = isset($input['school_id']) ? (int)$input['school_id'] : 0;
$grade_id = isset($input['grade_id']) ? (int)$input['grade_id'] : 0;
$course_id = isset($input['course_id']) ? (int)$input['course_id'] : 0;
$load_more = isset($input['load_more']) ? (bool)$input['load_more'] : false;

$debug_info = array(
    'received_input' => $input,
    'school_id' => $school_id,
    'grade_id' => $grade_id,
    'course_id' => $course_id,
    'load_more' => $load_more,
    'queries' => array()
);

try {
    // Build dynamic query based on filters
    $sql = "SELECT u.id, u.firstname, u.lastname, u.email
            FROM {user} u";
    
    $params = array();
    $where_conditions = array("u.deleted = 0", "u.suspended = 0");
    
    // Add school filter if specified
    if ($school_id > 0) {
        $sql .= " JOIN {company_users} cu ON u.id = cu.userid";
        $where_conditions[] = "cu.companyid = :school_id";
        $params['school_id'] = $school_id;
    }
    
    // Add grade/cohort filter if specified
    if ($grade_id > 0) {
        $sql .= " JOIN {cohort_members} cm ON u.id = cm.userid";
        $where_conditions[] = "cm.cohortid = :grade_id";
        $params['grade_id'] = $grade_id;
    }
    
    // Add course filter if specified
    if ($course_id > 0) {
        $sql .= " JOIN {user_enrolments} ue ON u.id = ue.userid
                  JOIN {enrol} e ON ue.enrolid = e.id";
        $where_conditions[] = "e.courseid = :course_id";
        $params['course_id'] = $course_id;
    }
    
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
    $sql .= " ORDER BY u.firstname, u.lastname";
    
    // Add LIMIT based on load_more parameter
    if ($load_more) {
        $sql .= " LIMIT 100"; // Load more users
    } else {
        $sql .= " LIMIT 20"; // Initial load
    }
    
    $debug_info['queries'][] = array(
        'sql' => $sql,
        'params' => $params
    );
    
    // Try to execute the query
    $users = $DB->get_records_sql($sql, $params);
    
    $debug_info['user_count'] = count($users);
    $debug_info['sample_users'] = array_slice(array_values($users), 0, 3);
    
    echo json_encode(array(
        'success' => true,
        'debug' => $debug_info
    ));
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug_info
    ));
}











































