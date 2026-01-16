<?php
/**
 * Get Student Courses API
 * Fetches all courses for a specific student with their status and progress
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

header('Content-Type: application/json');

// Get student ID from request
$student_id = required_param('student_id', PARAM_INT);

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get company information
$company_info = $DB->get_record_sql(
    "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    echo json_encode(['error' => 'Company not found']);
    exit;
}

// Verify student belongs to this company
$student_company = $DB->get_record_sql(
    "SELECT cu.companyid FROM {company_users} cu 
     WHERE cu.userid = ? AND cu.companyid = ?",
    [$student_id, $company_info->id]
);

if (!$student_company) {
    echo json_encode(['error' => 'Student not found in your company']);
    exit;
}

// Get all courses for this student
$courses_sql = "SELECT c.id, c.fullname, c.shortname,
                       ue.timecreated as enrollment_date,
                       cc.timecompleted,
                       gg.finalgrade, gg.rawgrademax
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {company_course} cc_link ON cc_link.courseid = c.id
                LEFT JOIN {course_completions} cc ON cc.userid = ue.userid AND cc.course = c.id
                LEFT JOIN {grade_grades} gg ON gg.userid = ue.userid
                LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = c.id AND gi.itemtype = 'course'
                WHERE ue.userid = ?
                AND cc_link.companyid = ?
                AND ue.status = 0
                AND c.id > 1
                ORDER BY c.fullname";

$courses = $DB->get_records_sql($courses_sql, [$student_id, $company_info->id]);

$courses_array = [];
foreach ($courses as $course) {
    // Determine course status
    $status = 'not_started';
    $progress = 0;
    $grade = null;
    
    // Calculate grade if available
    if ($course->finalgrade && $course->rawgrademax > 0) {
        $grade = round(($course->finalgrade / $course->rawgrademax) * 100, 1);
    }
    
    // Check completion status
    if ($course->timecompleted) {
        $status = 'completed';
        $progress = 100;
    } else {
        // Check for course progress via completion tracking
        $completion_sql = "SELECT COUNT(DISTINCT cmc.id) as completed,
                                  (SELECT COUNT(id) 
                                   FROM {course_modules} 
                                   WHERE course = ? AND visible = 1 AND deletioninprogress = 0) as total
                           FROM {course_modules_completion} cmc
                           INNER JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                           WHERE cmc.userid = ? AND cm.course = ? AND cmc.completionstate > 0";
        
        $completion = $DB->get_record_sql($completion_sql, [$course->id, $student_id, $course->id]);
        
        if ($completion && $completion->total > 0 && $completion->completed > 0) {
            $progress = round(($completion->completed / $completion->total) * 100, 1);
            $status = 'in_progress';
        }
        
        // Check for any quiz attempts
        if ($status === 'not_started') {
            $quiz_attempts = $DB->count_records_sql(
                "SELECT COUNT(*)
                 FROM {quiz_attempts} qa
                 INNER JOIN {quiz} q ON q.id = qa.quiz
                 WHERE q.course = ? AND qa.userid = ?",
                [$course->id, $student_id]
            );
            
            if ($quiz_attempts > 0) {
                $status = 'in_progress';
                if ($progress === 0) {
                    $progress = 10; // Minimum progress if quiz attempted
                }
            }
        }
        
        // Check for any assignment submissions
        if ($status === 'not_started') {
            $assignment_submissions = $DB->count_records_sql(
                "SELECT COUNT(*)
                 FROM {assign_submission} s
                 INNER JOIN {assign} a ON a.id = s.assignment
                 WHERE a.course = ? AND s.userid = ? AND s.status = 'submitted'",
                [$course->id, $student_id]
            );
            
            if ($assignment_submissions > 0) {
                $status = 'in_progress';
                if ($progress === 0) {
                    $progress = 10; // Minimum progress if assignment submitted
                }
            }
        }
        
        // Check for any course access logs (viewed course content)
        if ($status === 'not_started') {
            $course_access = $DB->count_records_sql(
                "SELECT COUNT(*)
                 FROM {logstore_standard_log}
                 WHERE courseid = ? 
                 AND userid = ? 
                 AND target = 'course_module'
                 AND action = 'viewed'
                 LIMIT 1",
                [$course->id, $student_id]
            );
            
            if ($course_access > 0) {
                $status = 'in_progress';
                if ($progress === 0) {
                    $progress = 5; // Minimum progress if course content viewed
                }
            }
        }
        
        // Check for grade - if has grade but no other activity detected
        if ($status === 'not_started' && $grade !== null && $grade > 0) {
            $status = 'in_progress';
            $progress = max($progress, min(50, $grade / 2)); // Estimate progress from grade
        }
        
        // If still not started but enrolled date is in the past, check course access
        if ($status === 'not_started') {
            $last_course_access = $DB->get_record_sql(
                "SELECT MAX(timeaccess) as lastaccess
                 FROM {user_lastaccess}
                 WHERE courseid = ? AND userid = ?",
                [$course->id, $student_id]
            );
            
            if ($last_course_access && $last_course_access->lastaccess > 0) {
                $status = 'in_progress';
                if ($progress === 0) {
                    $progress = 3; // Minimum progress if course accessed
                }
            }
        }
    }
    
    // Final validation: ensure progress matches status
    if ($status === 'completed' && $progress < 100) {
        $progress = 100;
    } elseif ($status === 'in_progress' && $progress === 0) {
        $progress = 5; // Default minimum progress for in-progress courses
    }
    
    $courses_array[] = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'status' => $status,
        'progress' => $progress,
        'grade' => $grade,
        'enrollment_date' => date('M d, Y', $course->enrollment_date),
        'completed_date' => $course->timecompleted ? date('M d, Y', $course->timecompleted) : null
    ];
    
    // Debug logging
    error_log("Course: {$course->fullname}, Status: {$status}, Progress: {$progress}%, Grade: " . ($grade ?? 'null'));
}

// Return JSON response
echo json_encode([
    'success' => true,
    'student_id' => $student_id,
    'courses' => $courses_array,
    'total_courses' => count($courses_array)
]);

