<?php
/**
 * AJAX endpoint to get enrolled students for a specific course
 * Only returns students from the requesting school/company
 */

require_once('../../config.php');
require_login();

global $USER, $DB, $CFG;

// Check if user is a company manager
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    // Try alternative query to find company info
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ?",
        [$USER->id]
    );
}

if (!$company_info) {
    echo json_encode(['success' => false, 'message' => 'No company access']);
    exit;
}

// Get course ID from POST data
$course_id = required_param('course_id', PARAM_INT);
$company_id = required_param('company_id', PARAM_INT);

// Verify the course is assigned to this company
$course_assigned = $DB->record_exists('company_course', [
    'courseid' => $course_id,
    'companyid' => $company_id
]);

if (!$course_assigned) {
    echo json_encode(['success' => false, 'message' => 'Course not assigned to this school']);
    exit;
}

// Get enrolled users from this company (students and editing teachers only, exclude school managers/admins)
$enrolled_users = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, ue.timecreated as enrolled_date,
            GROUP_CONCAT(DISTINCT r.shortname ORDER BY r.shortname SEPARATOR ', ') as roles,
            GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') as role_names,
            uifd.data as grade_level
     FROM {user_enrolments} ue 
     JOIN {enrol} e ON ue.enrolid = e.id 
     JOIN {user} u ON ue.userid = u.id
     JOIN {company_users} cu ON u.id = cu.userid
     JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
     JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
     JOIN {role} r ON r.id = ra.roleid
     LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
     LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
     WHERE e.courseid = ? 
     AND cu.companyid = ?
     AND u.deleted = 0
     AND u.suspended = 0
     AND (r.shortname = 'student' OR r.shortname = 'editingteacher' OR r.shortname = 'teacher')
     GROUP BY u.id, u.firstname, u.lastname, u.email, ue.timecreated, uifd.data
     ORDER BY u.firstname ASC, u.lastname ASC",
    [$course_id, $company_id]
);

$student_list = [];
foreach ($enrolled_users as $user) {
    // Determine primary role display
    $role_display = 'No role';
    $role_class = '';
    
    if (!empty($user->roles)) {
        $roles_array = explode(', ', $user->roles);
        
        if (in_array('editingteacher', $roles_array)) {
            $role_display = 'Editing Teacher';
            $role_class = 'role-teacher';
        } elseif (in_array('teacher', $roles_array)) {
            $role_display = 'Teacher';
            $role_class = 'role-teacher';
        } elseif (in_array('student', $roles_array)) {
            $role_display = 'Student';
            $role_class = 'role-student';
        }
    }
    
    // Determine cohort/grade level
    $cohort_display = 'No cohort';
    $cohort_class = '';
    
    if (!empty($user->grade_level)) {
        $cohort_display = $user->grade_level;
        $cohort_class = 'cohort-assigned';
    } elseif ($role_display === 'Student') {
        $cohort_display = 'Grade not assigned';
        $cohort_class = 'cohort-none';
    } elseif (in_array($role_display, ['Teacher', 'Editing Teacher'])) {
        $cohort_display = 'N/A (Teacher)';
        $cohort_class = 'cohort-na';
    }
    
    $student_list[] = [
        'id' => $user->id,
        'fullname' => fullname($user),
        'email' => $user->email,
        'enrolled_date' => date('M d, Y', $user->enrolled_date),
        'role' => $role_display,
        'role_class' => $role_class,
        'cohort' => $cohort_display,
        'cohort_class' => $cohort_class
    ];
}

echo json_encode([
    'success' => true,
    'students' => $student_list,
    'total' => count($student_list)
]);
?>



