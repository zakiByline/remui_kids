<?php
/**
 * Simple Grader Test - Basic functionality test
 */

require_once('../../../config.php');

// Set JSON header
header('Content-Type: application/json');

// Check if user has admin capabilities
require_capability('moodle/grade:viewall', context_system::instance());

try {
    // Get basic user count
    $user_count = $DB->count_records('user', ['deleted' => 0, 'suspended' => 0]);
    
    // Get basic course count
    $course_count = $DB->count_records('course', ['visible' => 1]);
    
    // Get basic company count
    $company_count = $DB->count_records('company');
    
    // Get basic cohort count
    $cohort_count = $DB->count_records('cohort');
    
    // Get a few sample users
    $sample_users = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email 
         FROM {user} u 
         WHERE u.deleted = 0 AND u.suspended = 0 
         ORDER BY u.firstname 
         LIMIT 5"
    );
    
    $response = array(
        'success' => true,
        'message' => 'Simple grader test successful',
        'data' => array(
            'user_count' => $user_count,
            'course_count' => $course_count,
            'company_count' => $company_count,
            'cohort_count' => $cohort_count,
            'sample_users' => array_values($sample_users)
        ),
        'timestamp' => date('Y-m-d H:i:s')
    );
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response = array(
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    );
    
    echo json_encode($response);
}
?>










































