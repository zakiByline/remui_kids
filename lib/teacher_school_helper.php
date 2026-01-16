<?php
/**
 * Helper functions for teacher school/company filtering
 * Use this to ensure teachers only see data from their own school
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the teacher's primary school/company ID
 *
 * @param int $userid The teacher's user ID (optional, defaults to current user)
 * @return int The company ID, or 0 if not found
 */
function theme_remui_kids_get_teacher_company_id($userid = null) {
    global $DB, $USER;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    $companies = $DB->get_records('company_users', array('userid' => $userid), '', 'companyid');
    $company_ids = !empty($companies) ? array_keys($companies) : array(0);
    
    return !empty($company_ids) ? $company_ids[0] : 0;
}

/**
 * Get the teacher's school/company name
 *
 * @param int $companyid The company ID (optional, auto-detects if not provided)
 * @return string The school/company name, or empty string if not found
 */
function theme_remui_kids_get_teacher_school_name($companyid = null) {
    global $DB;
    
    if ($companyid === null) {
        $companyid = theme_remui_kids_get_teacher_company_id();
    }
    
    if ($companyid) {
        $school = $DB->get_record('company', array('id' => $companyid), 'name');
        return $school ? $school->name : '';
    }
    
    return '';
}

/**
 * Get SQL condition and params for filtering students by teacher's company
 * Use this in WHERE clauses to filter students
 *
 * @param string $user_alias The alias used for the user table in the SQL (default 'u')
 * @param int $companyid The company ID (optional, auto-detects if not provided)
 * @return array Array with 'condition' (string) and 'params' (array)
 */
function theme_remui_kids_get_school_student_filter($user_alias = 'u', $companyid = null) {
    global $DB;
    
    if ($companyid === null) {
        $companyid = theme_remui_kids_get_teacher_company_id();
    }
    
    if ($companyid) {
        return [
            'join' => " JOIN {company_users} cu ON cu.userid = {$user_alias}.id AND cu.companyid = :filter_companyid",
            'params' => ['filter_companyid' => $companyid]
        ];
    }
    
    return [
        'join' => '',
        'params' => []
    ];
}

/**
 * Get count of students enrolled in a course, filtered by teacher's school
 *
 * @param int $courseid The course ID
 * @param int $companyid The company ID (optional, auto-detects if not provided)
 * @return int Number of students from the teacher's school
 */
function theme_remui_kids_count_course_students_by_school($courseid, $companyid = null) {
    global $DB;
    
    if ($companyid === null) {
        $companyid = theme_remui_kids_get_teacher_company_id();
    }
    
    if ($companyid) {
        // Filter by company - only count students from teacher's school
        return $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {enrol} e
             JOIN {user_enrolments} ue ON e.id = ue.enrolid
             JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = :companyid
             JOIN {role_assignments} ra ON ra.userid = ue.userid
             JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.instanceid = e.courseid
             JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid = :courseid
               AND r.shortname = 'student'
               AND ctx.contextlevel = :contextlevel",
            [
                'courseid' => $courseid,
                'companyid' => $companyid,
                'contextlevel' => CONTEXT_COURSE
            ]
        );
    } else {
        // No company filter - count all enrolled students
        return $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {enrol} e
             JOIN {user_enrolments} ue ON e.id = ue.enrolid
             JOIN {role_assignments} ra ON ra.userid = ue.userid
             JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.instanceid = e.courseid
             JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid = :courseid
               AND r.shortname = 'student'
               AND ctx.contextlevel = :contextlevel",
            [
                'courseid' => $courseid,
                'contextlevel' => CONTEXT_COURSE
            ]
        );
    }
}

/**
 * Get list of students enrolled in a course, filtered by teacher's school
 *
 * @param int $courseid The course ID
 * @param int $companyid The company ID (optional, auto-detects if not provided)
 * @return array Array of user objects
 */
function theme_remui_kids_get_course_students_by_school($courseid, $companyid = null) {
    global $DB;
    
    if ($companyid === null) {
        $companyid = theme_remui_kids_get_teacher_company_id();
    }
    
    $sql = "SELECT DISTINCT u.*
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.instanceid = e.courseid
            JOIN {role} r ON r.id = ra.roleid";
    
    if ($companyid) {
        $sql .= " JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid";
    }
    
    $sql .= " WHERE e.courseid = :courseid
                AND r.shortname = 'student'
                AND ctx.contextlevel = :contextlevel
                AND u.deleted = 0
                AND u.suspended = 0
           ORDER BY u.lastname ASC, u.firstname ASC";
    
    $params = [
        'courseid' => $courseid,
        'contextlevel' => CONTEXT_COURSE
    ];
    
    if ($companyid) {
        $params['companyid'] = $companyid;
    }
    
    return $DB->get_records_sql($sql, $params);
}

/**
 * Filter out admin users from a student list
 * Excludes users with manager, siteadmin roles or super admins
 *
 * @param array $students Array of user objects
 * @param int $courseid Course ID for context checking
 * @return array Filtered array of student objects (admins removed)
 */
function theme_remui_kids_filter_out_admins($students, $courseid = 0) {
    global $DB;
    
    if (empty($students)) {
        return array();
    }
    
    $filtered = array();
    $admin_role_ids = array();
    
    // Get admin role IDs
    $admin_roles = $DB->get_records_select('role', "shortname IN ('manager', 'siteadmin')", array(), 'id');
    if (!empty($admin_roles)) {
        $admin_role_ids = array_keys($admin_roles);
    }
    
    $coursecontext = $courseid ? context_course::instance($courseid) : null;
    $system_context = context_system::instance();
    
    foreach ($students as $student) {
        // Skip if user is deleted or suspended
        if (isset($student->deleted) && $student->deleted) {
            continue;
        }
        if (isset($student->suspended) && $student->suspended) {
            continue;
        }
        
        // Check if user is super admin
        if (is_siteadmin($student->id)) {
            continue;
        }
        
        // Check if user has admin role at system or course level
        $is_admin = false;
        if (!empty($admin_role_ids)) {
            foreach ($admin_role_ids as $roleid) {
                $has_system_role = $DB->record_exists('role_assignments', array(
                    'userid' => $student->id,
                    'roleid' => $roleid,
                    'contextid' => $system_context->id
                ));
                
                $has_course_role = false;
                if ($coursecontext) {
                    $has_course_role = $DB->record_exists('role_assignments', array(
                        'userid' => $student->id,
                        'roleid' => $roleid,
                        'contextid' => $coursecontext->id
                    ));
                }
                
                if ($has_system_role || $has_course_role) {
                    $is_admin = true;
                    break;
                }
            }
        }
        
        // Skip admins
        if ($is_admin) {
            continue;
        }
        
        $filtered[$student->id] = $student;
    }
    
    return $filtered;
}

/**
 * Get teacher's courses filtered by their school/company
 *
 * @param int $userid The teacher's user ID (optional, defaults to current user)
 * @param int $companyid The company ID (optional, auto-detects if not provided)
 * @return array Array of course objects
 */
function theme_remui_kids_get_teacher_school_courses($userid = null, $companyid = null) {
    global $DB, $USER;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    if ($companyid === null) {
        $companyid = theme_remui_kids_get_teacher_company_id($userid);
    }
    
    // Get all courses where user is a teacher
    $all_courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, visible, startdate, enddate, summary, category');
    
    if (!$companyid) {
        return $all_courses;
    }
    
    // Filter courses by company
    $school_courses = array();
    foreach ($all_courses as $course) {
        $course_company = $DB->get_record('company_course', 
            array('courseid' => $course->id, 'companyid' => $companyid));
        if ($course_company) {
            $school_courses[$course->id] = $course;
        }
    }
    
    return $school_courses;
}

