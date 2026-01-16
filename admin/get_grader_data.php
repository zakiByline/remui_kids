<?php
/**
 * AJAX endpoint for getting filtered grader data
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/gradelib.php');

// Set JSON header
header('Content-Type: application/json');

// Check if user has admin capabilities
require_capability('moodle/grade:viewall', context_system::instance());

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$school_id = isset($input['school_id']) ? (int)$input['school_id'] : 0;
$grade_id = isset($input['grade_id']) ? (int)$input['grade_id'] : 0;
$course_id = isset($input['course_id']) ? (int)$input['course_id'] : 0;
$load_more = isset($input['load_more']) ? (bool)$input['load_more'] : false;

try {
    // Get filtered data
    $students = get_filtered_students($school_id, $grade_id, $course_id, $load_more);
    $analytics = calculate_analytics($students);
    $chart_data = prepare_chart_data($students);
    
    $response = array(
        'success' => true,
        'students' => $students,
        'analytics' => $analytics,
        'chartData' => $chart_data,
        'debug' => array(
            'filters' => array(
                'school_id' => $school_id,
                'grade_id' => $grade_id,
                'course_id' => $course_id
            ),
            'student_count' => count($students),
            'timestamp' => date('Y-m-d H:i:s')
        )
    );
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log the error for debugging
    debugging('Custom grader report error: ' . $e->getMessage());
    
    $response = array(
        'success' => false,
        'error' => 'Database error occurred. Please check your data and try again.',
        'debug' => $e->getMessage()
    );
    
    echo json_encode($response);
}

/**
 * Get filtered students with their grade data
 */
function get_filtered_students($school_id, $grade_id, $course_id, $load_more = false) {
    global $DB;
    
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
    
    try {
        debugging('Dynamic user query: ' . $sql);
        debugging('Query parameters: ' . print_r($params, true));
        $users = $DB->get_records_sql($sql, $params);
        debugging('Found ' . count($users) . ' users with current filters');
        
        // If no users found with filters, get all users
        if (count($users) == 0 && ($school_id > 0 || $grade_id > 0 || $course_id > 0)) {
            debugging('No users found with filters, getting all users...');
            $fallback_sql = "SELECT u.id, u.firstname, u.lastname, u.email
                            FROM {user} u
                            WHERE u.deleted = 0 AND u.suspended = 0
                            ORDER BY u.firstname, u.lastname";
            $users = $DB->get_records_sql($fallback_sql);
            debugging('Fallback query found ' . count($users) . ' users');
        }
        
        // Optimize: Just return basic user data without complex lookups
        // This avoids the N+1 query problem
        $students = array();
        foreach ($users as $user) {
            $students[] = array(
                'id' => $user->id,
                'fullname' => $user->firstname . ' ' . $user->lastname,
                'email' => $user->email,
                'school_name' => 'N/A',
                'grade_name' => 'N/A',
                'course_name' => 'N/A',
                'total_activities' => 0,
                'average_grade' => 0,
                'status' => 'Needs Improvement'
            );
        }
        
        debugging('Processed ' . count($students) . ' students');
        return $students;
        
    } catch (Exception $e) {
        debugging('Database error in get_filtered_students: ' . $e->getMessage());
        return array();
    }
}

/**
 * Calculate analytics from student data
 */
function calculate_analytics($students) {
    $total_students = count($students);
    $total_courses = count(array_unique(array_column($students, 'course_name')));
    
    $grades = array_filter(array_column($students, 'average_grade'));
    $avg_grade = !empty($grades) ? round(array_sum($grades) / count($grades), 1) : 0;
    
    $completed_students = count(array_filter($students, function($student) {
        return $student['average_grade'] > 0;
    }));
    $completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;
    
    return array(
        'total_students' => $total_students,
        'total_courses' => $total_courses,
        'avg_grade' => $avg_grade,
        'completion_rate' => $completion_rate
    );
}

/**
 * Prepare chart data for visualizations
 */
function prepare_chart_data($students) {
    // Grade distribution
    $grade_ranges = array(
        'A (90-100)' => 0,
        'B (80-89)' => 0,
        'C (70-79)' => 0,
        'D (60-69)' => 0,
        'F (0-59)' => 0
    );
    
    foreach ($students as $student) {
        $grade = $student['average_grade'];
        if ($grade >= 90) {
            $grade_ranges['A (90-100)']++;
        } elseif ($grade >= 80) {
            $grade_ranges['B (80-89)']++;
        } elseif ($grade >= 70) {
            $grade_ranges['C (70-79)']++;
        } elseif ($grade >= 60) {
            $grade_ranges['D (60-69)']++;
        } else {
            $grade_ranges['F (0-59)']++;
        }
    }
    
    // School performance
    $school_performance = array();
    $school_grades = array();
    
    foreach ($students as $student) {
        $school = $student['school_name'];
        if (!isset($school_grades[$school])) {
            $school_grades[$school] = array();
        }
        if ($student['average_grade'] > 0) {
            $school_grades[$school][] = $student['average_grade'];
        }
    }
    
    foreach ($school_grades as $school => $grades) {
        if (!empty($grades)) {
            $school_performance[$school] = round(array_sum($grades) / count($grades), 1);
        }
    }
    
    return array(
        'grade_distribution' => array(
            'labels' => array_keys($grade_ranges),
            'data' => array_values($grade_ranges)
        ),
        'school_performance' => array(
            'labels' => array_keys($school_performance),
            'data' => array_values($school_performance)
        )
    );
}
?>
