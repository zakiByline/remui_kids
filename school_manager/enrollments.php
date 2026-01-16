<?php
/**
 * Enrollments Management Page - Manage and view all course enrollments
 */

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company info
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'No company found for current user.', null, \core\output\notification::NOTIFY_ERROR);
}

// Handle AJAX filter requests
$is_ajax = !empty($_GET['ajax']) && $_GET['ajax'] == '1';

if ($is_ajax) {
    header('Content-Type: application/json');
    
    // Get filter parameters
    $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
    $course_filter = isset($_GET['course']) ? (int)$_GET['course'] : 0;
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    $role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
    // Fixed page size for AJAX responses: always 10 enrollments per page
    $per_page = 10;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Build WHERE conditions
    $where_conditions = ["cc.companyid = ?", "cu.companyid = ?", "e.status = 0", "c.visible = 1", "u.deleted = 0", "(r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))"];
    $params = [$company_info->id, $company_info->id];
    
    if (!empty($search_query)) {
        $where_conditions[] = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $search_param = "%{$search_query}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($course_filter > 0) {
        $where_conditions[] = "c.id = ?";
        $params[] = $course_filter;
    }
    
    if ($status_filter !== 'all') {
        $status_value = ($status_filter === 'active') ? 0 : 1;
        $where_conditions[] = "ue.status = ?";
        $params[] = $status_value;
    }
    
    if ($role_filter !== 'all') {
        switch ($role_filter) {
            case 'teacher':
                $editingteacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
                if ($editingteacher_role) {
                    $where_conditions[] = "r.id = ?";
                    $params[] = $editingteacher_role->id;
                }
                break;
            case 'student':
                $student_role = $DB->get_record('role', ['shortname' => 'student']);
                if ($student_role) {
                    $where_conditions[] = "r.id = ?";
                    $params[] = $student_role->id;
                }
                break;
        }
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(DISTINCT ue.id)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON ue.enrolid = e.id
                  JOIN {course} c ON e.courseid = c.id
                  JOIN {user} u ON ue.userid = u.id
                  JOIN {company_course} cc ON c.id = cc.courseid
                  JOIN {company_users} cu ON u.id = cu.userid
                  LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                  LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
                  LEFT JOIN {role} r ON ra.roleid = r.id
                  WHERE " . implode(' AND ', $where_conditions);
    
    $total_count = $DB->count_records_sql($count_sql, $params);
    
    // Get enrollments
    $sql = "SELECT ue.id, ue.timecreated, ue.status, ue.enrolid,
                    u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                    c.id as courseid, c.fullname as coursename,
                    e.enrol as enrollment_method,
                    r.shortname as role_shortname,
                    r.name as role_name
             FROM {user_enrolments} ue
             JOIN {enrol} e ON ue.enrolid = e.id
             JOIN {course} c ON e.courseid = c.id
             JOIN {user} u ON ue.userid = u.id
             JOIN {company_course} cc ON c.id = cc.courseid
             JOIN {company_users} cu ON u.id = cu.userid
             LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
             LEFT JOIN {role} r ON ra.roleid = r.id
             WHERE " . implode(' AND ', $where_conditions) . "
             ORDER BY ue.timecreated DESC
             LIMIT {$per_page} OFFSET {$offset}";
    
    $enrollments = $DB->get_records_sql($sql, $params);
    
    // Format enrollments for JSON
    $formatted_enrollments = [];
    foreach ($enrollments as $enrollment) {
        $role_display = 'Unknown';
        switch ($enrollment->role_shortname) {
            case 'editingteacher':
                $role_display = 'Editing Teacher';
                break;
            case 'teacher':
                $role_display = 'Teacher';
                break;
            case 'student':
                $role_display = 'Student';
                break;
            default:
                $role_display = ucwords(str_replace('_', ' ', $enrollment->role_shortname));
        }
        
        // Get user profile picture URL
        $user_picture_url = '';
        try {
            $user_record = $DB->get_record('user', ['id' => $enrollment->userid], 'id, picture, imagealt, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
            if ($user_record && !empty($user_record->picture)) {
                $user_picture = new user_picture($user_record);
                $user_picture->size = 1; // f1 = 35x35px
                $user_picture_url = $user_picture->get_url($PAGE)->out(false);
            }
        } catch (Exception $e) {
            error_log("Failed to generate user picture for user ID {$enrollment->userid}: " . $e->getMessage());
        }
        
        $formatted_enrollments[] = [
            'id' => $enrollment->id,
            'userid' => $enrollment->userid,
            'firstname' => $enrollment->firstname,
            'lastname' => $enrollment->lastname,
            'email' => $enrollment->email,
            'picture_url' => $user_picture_url,
            'imagealt' => $enrollment->imagealt,
            'courseid' => $enrollment->courseid,
            'coursename' => $enrollment->coursename,
            'role_shortname' => $enrollment->role_shortname ?: 'other',
            'role_display' => $role_display,
            'status' => $enrollment->status,
            'enrolled_date' => date('M j Y g:i A', $enrollment->timecreated)
        ];
    }
    
    // Calculate pagination
    $start_item = $offset + 1;
    $end_item = min($offset + $per_page, $total_count);
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'enrollments' => $formatted_enrollments,
        'pagination' => [
            'total' => $total_count,
            'start' => $start_item,
            'end' => $end_item,
            'current_page' => $current_page,
            'total_pages' => ceil($total_count / $per_page)
        ],
        'filter_info' => [],
        'stats' => null
    ]);
    exit;
}

// Handle AJAX un-enroll request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unenroll_user') {
    $enrollment_id = (int)$_POST['enrollment_id'];
    $user_id = (int)$_POST['user_id'];
    $course_id = (int)$_POST['course_id'];
    
    try {
        // Verify the enrollment belongs to the company
        $enrollment_check = $DB->get_record_sql(
            "SELECT ue.id, ue.userid, ue.enrolid
             FROM {user_enrolments} ue
             JOIN {enrol} e ON ue.enrolid = e.id
             JOIN {course} c ON e.courseid = c.id
             JOIN {company_course} cc ON c.id = cc.courseid
             JOIN {company_users} cu ON ue.userid = cu.userid
             WHERE ue.id = ? AND cc.companyid = ? AND cu.companyid = ?",
            [$enrollment_id, $company_info->id, $company_info->id]
        );
        
        if (!$enrollment_check) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Enrollment not found or access denied.']);
            exit;
        }
        
        // Get the enrollment instance
        $enrollment_instance = $DB->get_record('enrol', ['id' => $enrollment_check->enrolid]);
        
        if (!$enrollment_instance) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Enrollment instance not found.']);
            exit;
        }
        
        // Use Moodle's proper unenrollment method
        $enrol_plugin = enrol_get_plugin($enrollment_instance->enrol);
        if ($enrol_plugin) {
            // Unenroll the user using the plugin's method
            $enrol_plugin->unenrol_user($enrollment_instance, $user_id);
        } else {
            // Fallback: manual removal if plugin method fails
            $DB->delete_records('user_enrolments', ['id' => $enrollment_id]);
            
            // Remove role assignments in course context
            $course_context = context_course::instance($course_id);
            $DB->delete_records('role_assignments', [
                'userid' => $user_id,
                'contextid' => $course_context->id
            ]);
        }
        
        // Additional cleanup to ensure complete removal
        $course_context = context_course::instance($course_id);
        
        // Remove any remaining role assignments in course context
        $DB->delete_records('role_assignments', [
            'userid' => $user_id,
            'contextid' => $course_context->id
        ]);
        
        // Remove from course groups if any
        $DB->delete_records('groups_members', [
            'userid' => $user_id,
            'component' => 'enrol_manual',
            'itemid' => $enrollment_instance->id
        ]);
        
        // Clear any cached course access
        if (function_exists('purge_all_caches')) {
            purge_all_caches();
        }
        
        // Log the unenrollment
        error_log("User {$user_id} un-enrolled from course {$course_id} by company manager {$USER->id}");
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'User successfully un-enrolled.']);
        exit;
        
    } catch (Exception $e) {
        error_log("Un-enroll error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to un-enroll user: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_unenroll_users') {
    header('Content-Type: application/json');
    $enrollment_ids = $_POST['enrollment_ids'] ?? [];

    if (empty($enrollment_ids) || !is_array($enrollment_ids)) {
        echo json_encode(['success' => false, 'message' => 'No enrollments selected.']);
        exit;
    }

    $success_count = 0;
    $errors = [];

    foreach ($enrollment_ids as $raw_id) {
        $enrollment_id = (int)$raw_id;
        if ($enrollment_id <= 0) {
            continue;
        }

        try {
            $enrollment_check = $DB->get_record_sql(
                "SELECT ue.id, ue.userid, ue.enrolid, e.courseid
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON ue.enrolid = e.id
                 JOIN {course} c ON e.courseid = c.id
                 JOIN {company_course} cc ON c.id = cc.courseid
                 JOIN {company_users} cu ON ue.userid = cu.userid
                 WHERE ue.id = ? AND cc.companyid = ? AND cu.companyid = ?",
                [$enrollment_id, $company_info->id, $company_info->id]
            );

            if (!$enrollment_check) {
                $errors[] = "Enrollment ID {$enrollment_id} not found or access denied.";
                continue;
            }

            $enrollment_instance = $DB->get_record('enrol', ['id' => $enrollment_check->enrolid]);
            if (!$enrollment_instance) {
                $errors[] = "Enrollment instance not found for enrollment ID {$enrollment_id}.";
                continue;
            }

            $course_context = context_course::instance($enrollment_check->courseid);
            $enrol_plugin = enrol_get_plugin($enrollment_instance->enrol);
            if ($enrol_plugin) {
                $enrol_plugin->unenrol_user($enrollment_instance, $enrollment_check->userid);
            } else {
                $DB->delete_records('user_enrolments', ['id' => $enrollment_id]);
                $DB->delete_records('role_assignments', [
                    'userid' => $enrollment_check->userid,
                    'contextid' => $course_context->id
                ]);
            }

            $DB->delete_records('role_assignments', [
                'userid' => $enrollment_check->userid,
                'contextid' => $course_context->id
            ]);

            $DB->delete_records('groups_members', [
                'userid' => $enrollment_check->userid,
                'component' => 'enrol_manual',
                'itemid' => $enrollment_instance->id
            ]);

            $success_count++;
        } catch (Exception $e) {
            $errors[] = "Enrollment ID {$enrollment_id}: " . $e->getMessage();
        }
    }

    if ($success_count > 0 && function_exists('purge_all_caches')) {
        purge_all_caches();
    }

    if ($success_count > 0) {
        $message = $success_count . ' user' . ($success_count > 1 ? 's' : '') . ' un-enrolled successfully.';
        if (!empty($errors)) {
            $message .= ' Some entries could not be processed: ' . implode(' | ', $errors);
        }
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => !empty($errors) ? implode(' | ', $errors) : 'No enrollments were un-enrolled.']);
    }
    exit;
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/enrollments.php'));
$PAGE->set_title('Enrollments Management - ' . $company_info->name);
$PAGE->set_heading('Enrollments Management');

// Get enrollment statistics with improved queries and error handling
$current_month = date('Y-m-01');

// Debug: Log company info
error_log("Enrollments Debug - Company ID: " . $company_info->id . ", Company Name: " . $company_info->name);

// Total enrollments - ONLY count enrollments of users from the same company (EXCLUDE school admins)
try {
    $total_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON ue.userid = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND e.status = 0 
           AND c.visible = 1
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
        [$company_info->id, $company_info->id]
    );
    error_log("Total enrollments found (excluding school admins): " . $total_enrollments);
} catch (Exception $e) {
    error_log("Error getting total enrollments: " . $e->getMessage());
    $total_enrollments = 0;
}

// Active enrollments - ONLY count users from the same company (EXCLUDE school admins)
try {
    $active_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON ue.userid = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND ue.status = 0 
           AND e.status = 0 
           AND c.visible = 1
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
        [$company_info->id, $company_info->id]
    );
    error_log("Active enrollments found (excluding school admins): " . $active_enrollments);
} catch (Exception $e) {
    error_log("Error getting active enrollments: " . $e->getMessage());
    $active_enrollments = 0;
}

// Suspended enrollments - ONLY count users from the same company (EXCLUDE school admins)
try {
    $suspended_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON ue.userid = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND ue.status = 1 
           AND e.status = 0 
           AND c.visible = 1
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
        [$company_info->id, $company_info->id]
    );
    error_log("Suspended enrollments found (excluding school admins): " . $suspended_enrollments);
} catch (Exception $e) {
    error_log("Error getting suspended enrollments: " . $e->getMessage());
    $suspended_enrollments = 0;
}

// Available courses - Improved query
try {
    $available_courses = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id) 
         FROM {course} c
         JOIN {company_course} cc ON c.id = cc.courseid
         WHERE cc.companyid = ? AND c.visible = 1 AND c.id > 1",
        [$company_info->id]
    );
    error_log("Available courses found: " . $available_courses);
} catch (Exception $e) {
    error_log("Error getting available courses: " . $e->getMessage());
    $available_courses = 0;
}

// Additional statistics for better insights - ONLY count users from the same company (EXCLUDE school admins)
try {
    // Get total unique students enrolled (excluding school admins)
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON ue.userid = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND e.status = 0 
           AND c.visible = 1
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
        [$company_info->id, $company_info->id]
    );
    error_log("Total unique students (excluding school admins): " . $total_students);
} catch (Exception $e) {
    error_log("Error getting total students: " . $e->getMessage());
    $total_students = 0;
}

// Get enrollments this month - ONLY count users from the same company (EXCLUDE school admins)
try {
    $monthly_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON ue.userid = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND ue.timecreated >= ? 
           AND e.status = 0 
           AND c.visible = 1
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
        [$company_info->id, $company_info->id, strtotime($current_month)]
    );
    error_log("Monthly enrollments (excluding school admins): " . $monthly_enrollments);
} catch (Exception $e) {
    error_log("Error getting monthly enrollments: " . $e->getMessage());
    $monthly_enrollments = 0;
}

// Get recent enrollments with improved query - ONLY users from the same company
// EXCLUDE school admins (companymanager, companycoursenoneditor roles)
try {
    $recent_enrollments = $DB->get_records_sql(
        "SELECT ue.id, ue.timecreated, ue.status, ue.enrolid,
                u.id as userid, u.firstname, u.lastname, u.email,
                c.id as courseid, c.fullname as coursename,
                e.enrol as enrollment_method,
                r.shortname as role_shortname,
                r.name as role_name
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         JOIN {course} c ON e.courseid = c.id
         JOIN {user} u ON ue.userid = u.id
         JOIN {company_course} cc ON c.id = cc.courseid
         JOIN {company_users} cu ON u.id = cu.userid
         LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
         LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
         LEFT JOIN {role} r ON ra.roleid = r.id
         WHERE cc.companyid = ? 
           AND cu.companyid = ? 
           AND e.status = 0 
           AND c.visible = 1 
           AND u.deleted = 0
           AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))
         ORDER BY ue.timecreated DESC
         LIMIT 20",
        [$company_info->id, $company_info->id]
    );
    error_log("Recent enrollments found (excluding school admins): " . count($recent_enrollments));
} catch (Exception $e) {
    error_log("Error getting recent enrollments: " . $e->getMessage());
    $recent_enrollments = [];
}

// Get available courses for filter with improved query
try {
    $available_courses_list = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname
         FROM {course} c
         JOIN {company_course} cc ON c.id = cc.courseid
         WHERE cc.companyid = ? AND c.visible = 1 AND c.id > 1
         ORDER BY c.fullname",
        [$company_info->id]
    );
    error_log("Available courses for filter: " . count($available_courses_list));
} catch (Exception $e) {
    error_log("Error getting available courses list: " . $e->getMessage());
    $available_courses_list = [];
}

// Handle search and filtering
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$course_filter = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';

// Handle pagination - fixed to 10 enrollments per page
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// Debug: Log filter parameters
error_log("=== FILTER DEBUG ===");
error_log("Search Query: " . $search_query);
error_log("Course Filter: " . $course_filter);
error_log("Status Filter: " . $status_filter);
error_log("Role Filter: " . $role_filter);

$filtered_enrollments = [];
$filter_info = []; // For displaying active filters
$total_count = 0; // Total count for pagination

if (!empty($search_query) || $course_filter > 0 || $status_filter !== 'all' || $role_filter !== 'all') {
    $where_conditions = ["cc.companyid = ?", "cu.companyid = ?", "e.status = 0", "c.visible = 1", "u.deleted = 0", "(r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))"];
    $params = [$company_info->id, $company_info->id];
    
    if (!empty($search_query)) {
        $where_conditions[] = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $search_param = "%{$search_query}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $filter_info[] = "Search: '" . htmlspecialchars($search_query) . "'";
    }
    
    if ($course_filter > 0) {
        $where_conditions[] = "c.id = ?";
        $params[] = $course_filter;
        // Get course name for display
        $selected_course = $DB->get_record('course', ['id' => $course_filter], 'fullname');
        if ($selected_course) {
            $filter_info[] = "Course: '" . htmlspecialchars($selected_course->fullname) . "'";
        }
    }
    
    if ($status_filter !== 'all') {
        $status_value = ($status_filter === 'active') ? 0 : 1;
        $where_conditions[] = "ue.status = ?";
        $params[] = $status_value;
        $filter_info[] = "Status: " . ucfirst($status_filter);
    }
    
    if ($role_filter !== 'all') {
        // Get role IDs for the specific roles (course context)
        $role_conditions = [];
        switch ($role_filter) {
            case 'teacher':
                // Include both editingteacher and teacher roles
                $editingteacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
                if ($editingteacher_role) {
                    $role_conditions[] = "r.id = ?";
                    $params[] = $editingteacher_role->id;
                }
                $teacher_role = $DB->get_record('role', ['shortname' => 'teacher']);
                if ($teacher_role) {
                    $role_conditions[] = "r.id = ?";
                    $params[] = $teacher_role->id;
                }
                break;
            case 'student':
                $student_role = $DB->get_record('role', ['shortname' => 'student']);
                if ($student_role) {
                    $role_conditions[] = "r.id = ?";
                    $params[] = $student_role->id;
                }
                break;
        }
        
        if (!empty($role_conditions)) {
            $where_conditions[] = "(" . implode(' OR ', $role_conditions) . ")";
            $filter_info[] = "Role: " . ucfirst($role_filter);
        }
    }
    
    // First get total count for pagination
    $count_sql = "SELECT COUNT(DISTINCT ue.id)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON ue.enrolid = e.id
                  JOIN {course} c ON e.courseid = c.id
                  JOIN {user} u ON ue.userid = u.id
                  JOIN {company_course} cc ON c.id = cc.courseid
                  JOIN {company_users} cu ON u.id = cu.userid
                  LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                  LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
                  LEFT JOIN {role} r ON ra.roleid = r.id
                  WHERE " . implode(' AND ', $where_conditions);
    
    try {
        $total_count = $DB->count_records_sql($count_sql, $params);
        error_log("Total filtered enrollments count: " . $total_count);
    } catch (Exception $e) {
        error_log("Error getting total count: " . $e->getMessage());
        $total_count = 0;
    }
    
    // Build the SQL query with course context role information
    $sql = "SELECT ue.id, ue.timecreated, ue.status, ue.enrolid,
                    u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                    c.id as courseid, c.fullname as coursename,
                    e.enrol as enrollment_method,
                    r.shortname as role_shortname,
                    r.name as role_name
             FROM {user_enrolments} ue
             JOIN {enrol} e ON ue.enrolid = e.id
             JOIN {course} c ON e.courseid = c.id
             JOIN {user} u ON ue.userid = u.id
             JOIN {company_course} cc ON c.id = cc.courseid
             JOIN {company_users} cu ON u.id = cu.userid
             LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
             LEFT JOIN {role} r ON ra.roleid = r.id
             WHERE " . implode(' AND ', $where_conditions) . "
             ORDER BY c.id DESC
             LIMIT {$per_page} OFFSET {$offset}";
    
    error_log("SQL Query: " . $sql);
    error_log("Params: " . json_encode($params));
    
    try {
        $filtered_enrollments = $DB->get_records_sql($sql, $params);
        error_log("Filtered enrollments found: " . count($filtered_enrollments));
    } catch (Exception $e) {
        error_log("Error getting filtered enrollments: " . $e->getMessage());
        $filtered_enrollments = [];
    }
} else {
    // No filters applied, get all enrollments with pagination (EXCLUDING school admins)
    $where_conditions = ["cc.companyid = ?", "cu.companyid = ?", "e.status = 0", "c.visible = 1", "u.deleted = 0", "(r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))"];
    $params = [$company_info->id, $company_info->id];
    
    // Use the dashboard metric directly to ensure perfect consistency with the summary cards
    // This is the same value shown in "Total Enrollments: 174" card
    $total_count = isset($total_enrollments) && $total_enrollments > 0 ? (int)$total_enrollments : 0;
    error_log("Total enrollments count (no filters, using dashboard metric): " . $total_count);
    
    // Get paginated enrollments
    try {
        $sql = "SELECT ue.id, ue.timecreated, ue.status, ue.enrolid,
                        u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                        c.id as courseid, c.fullname as coursename,
                        e.enrol as enrollment_method,
                        r.shortname as role_shortname,
                        r.name as role_name
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON ue.enrolid = e.id
                 JOIN {course} c ON e.courseid = c.id
                 JOIN {user} u ON ue.userid = u.id
                 JOIN {company_course} cc ON c.id = cc.courseid
                 JOIN {company_users} cu ON u.id = cu.userid
                 LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                 LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ctx.id
                 LEFT JOIN {role} r ON ra.roleid = r.id
                 WHERE " . implode(' AND ', $where_conditions) . "
                 ORDER BY c.id DESC
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $filtered_enrollments = $DB->get_records_sql($sql, $params);
        error_log("Enrollments found (no filters): " . count($filtered_enrollments));
    } catch (Exception $e) {
        error_log("Error getting enrollments (no filters): " . $e->getMessage());
        $filtered_enrollments = [];
    }
}

// Calculate pagination info
// If total_count is 0 or not set, use dashboard metric first, then recalculate if needed
if ($total_count <= 0) {
    // First try to use the dashboard metric (this should match the "Total Enrollments: 174" card)
    if (isset($total_enrollments) && $total_enrollments > 0) {
        $total_count = (int)$total_enrollments;
        error_log("Using dashboard metric for total_count: " . $total_count);
    } else {
        // Last resort: recalculate using the same query as dashboard metric
        try {
            $total_count = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT ue.id) 
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON ue.enrolid = e.id
                 JOIN {course} c ON e.courseid = c.id
                 JOIN {company_course} cc ON c.id = cc.courseid
                 JOIN {company_users} cu ON ue.userid = cu.userid
                 LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                 LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
                 LEFT JOIN {role} r ON ra.roleid = r.id
                 WHERE cc.companyid = ? 
                   AND cu.companyid = ? 
                   AND e.status = 0 
                   AND c.visible = 1
                   AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
                [$company_info->id, $company_info->id]
            );
            error_log("Recalculated total_count for pagination: " . $total_count);
        } catch (Exception $e) {
            error_log("Error recalculating total_count: " . $e->getMessage());
            $total_count = 0;
        }
    }
}

$total_pages = $total_count > 0 ? max(1, ceil($total_count / $per_page)) : 0;
$start_item = $total_count > 0 ? $offset + 1 : 0;
$end_item = $total_count > 0 ? min($offset + $per_page, $total_count) : 0;

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => !empty($company_info->logo) ? $company_info->logo : '',
    'has_logo' => !empty($company_info->logo),
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'enrollments_active' => true, // This page is for enrollments
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'certificates_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

// Debug: Log page access
error_log("Enrollments page accessed by user: " . $USER->id . " (" . fullname($USER) . ")");

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Custom CSS for the enrollments management layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* CRITICAL: Force sidebar to always be visible on enrollments page */
/* NOTE: Sidebar styling (width, height, background, colors) is now handled by the template */
/* IMPORTANT: Position sidebar BELOW the navbar (top: 55px) */
.school-manager-sidebar,
body .school-manager-sidebar,
html .school-manager-sidebar {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    position: fixed !important;
    top: 55px !important; /* Below navbar */
    left: 0 !important;
    height: calc(100vh - 55px) !important; /* Full height minus navbar */
    z-index: 1000 !important; /* Below navbar (navbar uses 1100) */
    pointer-events: auto !important;
    transform: translateX(0) !important;
}

/* Reset and base styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f8f9fa;
    overflow-x: hidden;
}

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background-color: #ffffff;
    overflow-y: auto;
    z-index: 99;
    padding-top: 35px;
    transition: left 0.3s ease, width 0.3s ease;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 0 30px 30px 30px;
}

/* Page Header */
.page-header {
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
    padding: 1.75rem 2rem;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    margin-bottom: 1.5rem;
    margin-top: 0;
    position: relative;
}

.page-header-content {
    flex: 1;
    min-width: 260px;
}

.page-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.back-button {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}

.back-button i {
    color: #ffffff;
}

.back-button:hover {
    background: #1d4ed8;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    text-decoration: none;
    color: #ffffff;
    transform: translateY(-1px);
}

.enroll-button {
    background: linear-gradient(135deg, #34d399, #059669);
    color: #ffffff;
    padding: 12px 22px;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 18px rgba(5, 150, 105, 0.25);
    border: none;
    transition: all 0.3s ease;
}

.enroll-button i {
    font-size: 1.05rem;
}

.enroll-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(5, 150, 105, 0.32);
    text-decoration: none;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    color: #6c757d;
    text-decoration: none;
}

/* Stats Cards */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    border-top: 4px solid transparent;
    min-height: 140px;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.stat-card:nth-child(1) {
    border-top-color: #667eea; /* Purple/Blue for Total Enrollments */
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
}

.stat-card:nth-child(2) {
    border-top-color: #43e97b; /* Green for Active Enrollments */
    background: linear-gradient(180deg, rgba(67, 233, 123, 0.08), #ffffff);
}

.stat-card:nth-child(3) {
    border-top-color: #8b5cf6; /* Purple for Suspended Enrollments */
    background: linear-gradient(180deg, rgba(139, 92, 246, 0.08), #ffffff);
}

.stat-card:nth-child(4) {
    border-top-color: #f59e0b; /* Orange for Available Courses */
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.08), #ffffff);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-icon.blue {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.stat-icon.green {
    background: linear-gradient(135deg, #10b981, #059669);
}

.stat-icon.purple {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
}

.stat-icon.orange {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.stat-icon i {
    font-size: 1.25rem;
    color: white;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

/* Filter Section */
.filter-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.filter-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1.5rem;
}

.filter-form {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr auto;
    gap: 1rem;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.filter-input, .filter-select {
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.2s ease;
    background: white;
}

.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-input::placeholder {
    color: #9ca3af;
}

.apply-filters-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.apply-filters-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
}

/* Enrollments Table Section */
.enrollments-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.enrollments-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1.5rem;
}

.enrollments-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}

.enrollments-table th {
    background: #f8fafc;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e5e7eb;
}

.enrollments-table td {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

.enrollment-select,
.select-all-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.select-cell {
    text-align: center;
    width: 50px;
}

.bulk-actions-container {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.bulk-unenroll-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 700;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.25);
}

.bulk-unenroll-btn:disabled {
    cursor: not-allowed;
    background: #f3f4f6;
    color: #9ca3af;
    box-shadow: none;
}

.bulk-unenroll-btn:not(:disabled):hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(239, 68, 68, 0.35);
}

.selection-count {
    font-size: 0.85rem;
    color: #6b7280;
    font-weight: 600;
}

.enrollments-table tbody tr:hover {
    background: #f8fafc;
}

.student-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
    flex-shrink: 0;
}

.student-avatar img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e5e7eb;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: block;
    background: #ffffff;
}

.student-avatar img:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    border-color: #3b82f6;
}

.student-avatar img[src=\"\"],
.student-avatar img:not([src]) {
    display: none;
}

.student-details {
    display: flex;
    flex-direction: column;
}

.student-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.875rem;
}

.course-name {
    font-weight: 500;
    color: #374151;
    font-size: 0.875rem;
}

.enrollment-method {
    background: #e0f2fe;
    color: #0369a1;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.role-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: inline-block;
}

.role-schooladmin {
    background: #fef3c7;
    color: #92400e;
}

.role-teacher {
    background: #e9d5ff;
    color: #6b21a8;
}

.role-student {
    background: #dcfce7;
    color: #166534;
}

.role-schooladmin:hover {
    color: #ffffff;
}

.role-teacher:hover {
    color: #ffffff;
}

.role-student:hover {
    color: #ffffff;
}

.role-other {
    background: #f3f4f6;
    color: #374151;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-suspended {
    background: #fef3c7;
    color: #92400e;
}

.enrolled-date {
    color: #6b7280;
    font-size: 0.875rem;
}

.actions-btn {
    background: none;
    border: none;
    color: #6b7280;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.actions-btn:hover {
    background: #f3f4f6;
    color: #374151;
}

/* Actions Dropdown */
.actions-dropdown {
    position: relative;
    display: inline-block;
}

.actions-menu {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    min-width: 150px;
    overflow: hidden;
}

.action-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
}

.action-item:last-child {
    border-bottom: none;
}

.action-item:hover {
    background: #f8fafc;
    color: #1f2937;
    text-decoration: none;
}

.unenroll-action:hover {
    background: #fef2f2;
    color: #dc2626;
}

.unenroll-action i {
    color: #dc2626;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .filter-form {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stats-section {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
}

/* Tablet Responsive (768px - 1024px) */
@media (max-width: 1024px) and (min-width: 769px) {
    .school-manager-main-content {
        left: 240px;
        width: calc(100vw - 240px);
    }
    
    .main-content {
        padding: 0 20px 30px 20px;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
}

/* Mobile Responsive (max-width: 768px) */
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
        top: 55px;
        height: calc(100vh - 55px);
        padding-top: 80px;
    }
    
    .main-content {
        padding: 0 15px 30px 15px;
    }
    
    .page-header {
        flex-direction: column;
        gap: 15px;
        padding: 20px;
        margin-top: 0;
        align-items: flex-start;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        font-size: 0.9rem;
    }

    .page-header-actions {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .back-button {
        width: 100%;
        justify-content: center;
    }

    .enroll-button {
        width: 100%;
        justify-content: center;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .stat-card {
        padding: 1rem;
        min-height: 100px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
    }
    
    .enrollments-table {
        font-size: 0.875rem;
    }
    
    .enrollments-table th,
    .enrollments-table td {
        padding: 0.75rem 0.5rem;
    }
}

/* Small Mobile Responsive (max-width: 480px) */
@media (max-width: 480px) {
    .school-manager-main-content {
        padding-top: 70px;
    }
    
    .page-header {
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .page-title {
        font-size: 1.3rem;
    }
    
    .page-subtitle {
        font-size: 0.85rem;
    }
    
    .stats-section {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .stat-card {
        min-height: 90px;
        padding: 1rem;
    }
    
    .stat-icon {
        width: 35px;
        height: 35px;
        font-size: 0.9rem;
    }
    
    .stat-number {
        font-size: 1.6rem;
    }
}

/* Loading Animation */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.loading {
    animation: pulse 2s infinite;
}

/* Pagination Controls */
.pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10px 0;
    margin-top: 10px;
    background: transparent;
    border: none;
    box-shadow: none;
    gap: 10px;
}

.pagination-info {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
    text-align: center;
    order: 2;
}

.pagination-info span {
    color: #1f2937;
    font-weight: 600;
}

.pagination-controls {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 0 !important;
    margin: 0 !important;
    order: 1;
}

.pagination-controls a,
.pagination-controls button,
.pagination-controls span {
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
    text-decoration: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #e5e7eb;
    background: #f3f4f6;
    color: #374151;
    cursor: pointer;
    font-family: inherit;
}

.pagination-controls a:hover,
.pagination-controls button:hover {
    background: #e5e7eb;
    transform: translateY(-1px);
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-controls button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-controls .active-page {
    background: #3b82f6 !important;
    color: white !important;
    border-color: #3b82f6 !important;
}

.pagination-controls .disabled {
    color: #9ca3af;
    cursor: not-allowed;
    opacity: 0.5;
}

.pagination-numbers {
    display: flex;
    gap: 5px;
}

.pagination-btn {
    padding: 8px 16px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.pagination-btn:hover:not(:disabled) {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-number,
button.pagination-number {
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    transition: all 0.3s ease;
    min-width: 40px;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    font-family: inherit;
}

.pagination-number:hover,
button.pagination-number:hover {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-number.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
    cursor: default;
}
";

echo "</style>";

$enrollmentjs = <<<'JS'
(function() {
    const selectedEnrollments = new Map();

    function decodeHtmlEntities(str) {
        if (!str) {
            return '';
        }
        const textarea = document.createElement('textarea');
        textarea.innerHTML = str;
        return textarea.value;
    }

    function encodeHtmlEntities(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function syncEnrollmentSelection(checkbox, checked) {
        if (!checkbox) {
            return;
        }
        const key = checkbox.dataset.enrollmentId;
        if (!key) {
            return;
        }
        if (checked) {
            selectedEnrollments.set(key, {
                enrollmentId: key,
                userId: checkbox.dataset.userId,
                courseId: checkbox.dataset.courseId,
                userName: checkbox.dataset.userName || ''
            });
        } else {
            selectedEnrollments.delete(key);
        }
    }

    function updateBulkSelectionUI() {
        const countSpan = document.getElementById('selectedCount');
        const bulkBtn = document.getElementById('bulkUnenrollBtn');
        const count = selectedEnrollments.size;
        if (countSpan) {
            countSpan.textContent = count + (count === 1 ? ' selected' : ' selected');
        }
        if (bulkBtn) {
            bulkBtn.disabled = count === 0;
        }
    }

    function updateSelectAllCheckbox() {
        const selectAll = document.getElementById('selectAllEnrollments');
        const checkboxes = document.querySelectorAll('.enrollment-select');
        if (!selectAll) {
            return;
        }
        if (!checkboxes.length) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
        }
        let checkedVisible = 0;
        checkboxes.forEach(cb => {
            if (selectedEnrollments.has(cb.dataset.enrollmentId)) {
                checkedVisible++;
                if (!cb.checked) {
                    cb.checked = true;
                }
            } else if (cb.checked) {
                cb.checked = false;
            }
        });
        selectAll.checked = checkedVisible === checkboxes.length && checkboxes.length > 0;
        selectAll.indeterminate = checkedVisible > 0 && checkedVisible < checkboxes.length;
    }

    function onEnrollmentCheckboxChange(event) {
        const checkbox = event.target;
        syncEnrollmentSelection(checkbox, checkbox.checked);
        updateSelectAllCheckbox();
        updateBulkSelectionUI();
    }

    function onSelectAllChange(event) {
        const checked = event.target.checked;
        const checkboxes = document.querySelectorAll('.enrollment-select');
        checkboxes.forEach(cb => {
            if (cb.checked !== checked) {
                cb.checked = checked;
            }
            syncEnrollmentSelection(cb, checked);
        });
        updateSelectAllCheckbox();
        updateBulkSelectionUI();
    }

    function attachEnrollmentSelectionHandlers() {
        const checkboxes = document.querySelectorAll('.enrollment-select');
        checkboxes.forEach(cb => {
            cb.checked = selectedEnrollments.has(cb.dataset.enrollmentId);
        });
        updateSelectAllCheckbox();
        updateBulkSelectionUI();
    }

    function bulkUnenrollSelected() {
        if (!selectedEnrollments.size) {
            return;
        }
        const names = Array.from(selectedEnrollments.values()).map(item => decodeHtmlEntities(item.userName) || 'Selected User');
        const preview = names.slice(0, 5).join(', ') + (names.length > 5 ? ', ...' : '');
        if (!confirm('Are you sure you want to un-enroll the selected ' + selectedEnrollments.size + ' users?\n' + preview)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'bulk_unenroll_users');
        selectedEnrollments.forEach(item => {
            formData.append('enrollment_ids[]', item.enrollmentId);
        });

        const tbody = document.querySelector('.enrollments-table tbody');
        if (tbody) {
            tbody.style.opacity = '0.5';
            tbody.style.pointerEvents = 'none';
        }

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedEnrollments.clear();
                    updateBulkSelectionUI();
                    updateSelectAllCheckbox();
                    refreshEnrollmentsTable();
                } else {
                    alert(data.message || 'Failed to un-enroll selected users.');
                    if (tbody) {
                        tbody.style.opacity = '1';
                        tbody.style.pointerEvents = 'auto';
                    }
                }
            })
            .catch(error => {
                console.error('Bulk un-enroll error:', error);
                alert('Failed to un-enroll selected users.');
                if (tbody) {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';
                }
            });
    }

    function toggleActionsDropdownInternal(enrollmentId) {
        document.querySelectorAll('.actions-menu').forEach(menu => {
            if (menu.id !== 'actions-' + enrollmentId) {
                menu.style.display = 'none';
            }
        });
        const menu = document.getElementById('actions-' + enrollmentId);
        if (menu) {
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
    }

    function refreshEnrollmentsTable(page = null) {
        const form = document.getElementById('enrollments-filter-form');
        if (!form) {
            window.location.reload();
            return;
        }

        const params = new URLSearchParams(new FormData(form));
        params.set('ajax', '1');
        
        // Use provided page or get from pagination info
        let currentPage = page;
        if (!currentPage) {
        const paginationInfo = document.querySelector('[data-current-page]');
            currentPage = paginationInfo ? (paginationInfo.getAttribute('data-current-page') || '1') : '1';
        }
        params.set('page', currentPage);

        const tbody = document.querySelector('.enrollments-table tbody');
        if (tbody) {
            tbody.style.opacity = '0.5';
            tbody.style.pointerEvents = 'none';
        }

        fetch(window.location.pathname + '?' + params.toString(), {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateEnrollmentsTableHTML(data.enrollments);
                    updatePaginationInfo(data.pagination);
                    attachEnrollmentSelectionHandlers();
                    
                    const newUrl = window.location.pathname + '?' + params.toString().replace('ajax=1&', '');
                    history.pushState({}, '', newUrl);
                } else {
                    console.error('Error refreshing table:', data.message);
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error refreshing enrollments:', error);
                window.location.reload();
            })
            .finally(() => {
                if (tbody) {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';
                }
            });
    }

    function updateEnrollmentsTableHTML(enrollments) {
        const tbody = document.querySelector('.enrollments-table tbody');
        if (!tbody) {
            return;
        }

        if (!enrollments || enrollments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 3rem; color: #6b7280;"><i class="fa fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; display: block;"></i>No enrollments found</td></tr>';
            return;
        }

        let html = '';
        enrollments.forEach(function(enrollment) {
            const studentInitials = (enrollment.firstname.charAt(0) + enrollment.lastname.charAt(0)).toUpperCase();
            const statusClass = enrollment.status === 0 ? 'status-active' : 'status-suspended';
            const statusText = enrollment.status === 0 ? 'Active' : 'Suspended';
            
            let roleDisplay = 'Unknown';
            let roleClass = 'role-badge role-other';
            switch (enrollment.role_shortname) {
                case 'editingteacher':
                    roleDisplay = 'Editing Teacher';
                    roleClass = 'role-badge role-teacher';
                    break;
                case 'teacher':
                    roleDisplay = 'Teacher';
                    roleClass = 'role-badge role-teacher';
                    break;
                case 'student':
                    roleDisplay = 'Student';
                    roleClass = 'role-badge role-student';
                    break;
                default:
                    roleDisplay = enrollment.role_display || 'Unknown';
            }

            const safeName = encodeHtmlEntities(enrollment.firstname + ' ' + enrollment.lastname);
            const pictureUrl = enrollment.picture_url || '';

            html += '<tr>';
            html += '<td class="select-cell"><input type="checkbox" class="enrollment-select" data-enrollment-id="' + enrollment.id + '" data-user-id="' + enrollment.userid + '" data-course-id="' + enrollment.courseid + '" data-user-name="' + safeName + '"></td>';
            html += '<td>';
            html += '<div class="student-info">';
            html += '<div class="student-avatar" data-initials="' + studentInitials + '">';
            if (pictureUrl) {
                html += '<img src="' + pictureUrl + '" alt="' + safeName + '" onerror="this.style.display=\'none\'; this.parentElement.textContent=this.parentElement.dataset.initials;">';
            } else {
                html += studentInitials;
            }
            html += '</div>';
            html += '<div class="student-details">';
            html += '<div class="student-name">' + safeName + '</div>';
            html += '</div>';
            html += '</div>';
            html += '</td>';
            html += '<td><div class="course-name">' + encodeHtmlEntities(enrollment.coursename) + '</div></td>';
            html += '<td><span class="' + roleClass + '">' + encodeHtmlEntities(roleDisplay) + '</span></td>';
            html += '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>';
            html += '<td><span class="enrolled-date">' + enrollment.enrolled_date + '</span></td>';
            html += '<td>';
            html += '<div class="actions-dropdown">';
            html += '<button class="actions-btn" onclick="toggleActionsDropdown(' + enrollment.id + ')" title="More actions">';
            html += '<i class="fa fa-ellipsis-v"></i>';
            html += '</button>';
            html += '<div class="actions-menu" id="actions-' + enrollment.id + '" style="display: none;">';
            html += '<a href="#" class="action-item unenroll-action" data-enrollment-id="' + enrollment.id + '" data-user-id="' + enrollment.userid + '" data-course-id="' + enrollment.courseid + '" data-user-name="' + safeName + '"><i class="fa fa-user-times"></i> Un-Enroll</a>';
            html += '</div>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html;
    }

    function updatePaginationInfo(pagination) {
        if (!pagination) {
            return;
        }

        // Update the blue info bar at the top
        const paginationInfo = document.querySelector('[data-total-count]');
        if (paginationInfo) {
            paginationInfo.setAttribute('data-total-count', pagination.total);
            paginationInfo.setAttribute('data-current-page', pagination.current_page);
            const infoText = pagination.total > 0 
                ? 'Showing ' + pagination.start + '-' + pagination.end + ' of ' + pagination.total + ' enrollment' + (pagination.total !== 1 ? 's' : '') + 
                  (pagination.total_pages > 1 ? ' - Page ' + pagination.current_page + ' of ' + pagination.total_pages : '')
                : 'No enrollments found';
            const pTag = paginationInfo.querySelector('p');
            if (pTag) {
                pTag.textContent = infoText;
        }
    }
        
        // Update the pagination info in the pagination container at the bottom
        const paginationInfoBottom = document.querySelector('.pagination-info');
        if (paginationInfoBottom) {
            let infoText = 'Showing <span>' + pagination.start + '</span>-<span>' + pagination.end + '</span> of <span>' + pagination.total + '</span> enrollment' + (pagination.total !== 1 ? 's' : '');
            if (pagination.total_pages > 1) {
                infoText += ' - Page <span>' + pagination.current_page + '</span> of <span>' + pagination.total_pages + '</span>';
            }
            paginationInfoBottom.innerHTML = infoText;
        }
        
        // Also update pagination controls
        updatePaginationControls(pagination);
    }
    
    function updatePaginationControls(pagination) {
        if (!pagination || !pagination.total_pages) {
            return;
        }
        
        const paginationControls = document.querySelector('.pagination-controls');
        if (!paginationControls) {
            return;
        }
        
        const currentPage = pagination.current_page;
        const totalPages = pagination.total_pages;
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        let html = '';
        
        // Previous button
        if (currentPage > 1) {
            html += '<button type="button" class="pagination-btn" data-page="' + (currentPage - 1) + '" onclick="handlePaginationClick(' + (currentPage - 1) + ')">';
            html += '<i class="fa fa-chevron-left"></i> Previous';
            html += '</button>';
        } else {
            html += '<span class="pagination-btn" style="opacity: 0.5; cursor: not-allowed;">';
            html += '<i class="fa fa-chevron-left"></i> Previous';
            html += '</span>';
        }
        
        // Page numbers
        html += '<div class="pagination-numbers">';
        
        if (startPage > 1) {
            html += '<button type="button" class="pagination-number" data-page="1" onclick="handlePaginationClick(1)">1</button>';
            if (startPage > 2) {
                html += '<span style="color: #6b7280; padding: 8px 4px;">...</span>';
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                html += '<span class="pagination-number active">' + i + '</span>';
            } else {
                html += '<button type="button" class="pagination-number" data-page="' + i + '" onclick="handlePaginationClick(' + i + ')">' + i + '</button>';
            }
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += '<span style="color: #6b7280; padding: 8px 4px;">...</span>';
            }
            html += '<button type="button" class="pagination-number" data-page="' + totalPages + '" onclick="handlePaginationClick(' + totalPages + ')">' + totalPages + '</button>';
        }
        
        html += '</div>';
        
        // Next button
        if (currentPage < totalPages) {
            html += '<button type="button" class="pagination-btn" data-page="' + (currentPage + 1) + '" onclick="handlePaginationClick(' + (currentPage + 1) + ')">';
            html += 'Next <i class="fa fa-chevron-right"></i>';
            html += '</button>';
        } else {
            html += '<span class="pagination-btn" style="opacity: 0.5; cursor: not-allowed;">';
            html += 'Next <i class="fa fa-chevron-right"></i>';
            html += '</span>';
        }
        
        paginationControls.innerHTML = html;
    }
    
    // Global function to handle pagination clicks
    window.handlePaginationClick = function(page) {
        if (!page || page < 1) {
            return;
        }
        
        // Update the data attribute for current page
        const paginationInfo = document.querySelector('[data-current-page]');
        if (paginationInfo) {
            paginationInfo.setAttribute('data-current-page', page);
        }
        
        // Refresh table with new page
        refreshEnrollmentsTable(page);
        
        // Scroll to top of table smoothly
        const table = document.querySelector('.enrollments-table');
        if (table) {
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    function unEnrollUserInternal(enrollmentId, userId, courseId, userName) {
        const displayName = userName || 'Selected User';
        if (!confirm('Are you sure you want to un-enroll ' + displayName + ' from this course?')) {
            return;
        }
        const formData = new FormData();
        formData.append('action', 'unenroll_user');
        formData.append('enrollment_id', enrollmentId);
        formData.append('user_id', userId);
        formData.append('course_id', courseId);

        const menu = document.getElementById('actions-' + enrollmentId);
        const row = menu ? menu.closest('tr') : null;
        if (menu) {
            menu.innerHTML = '<div style="padding: 12px 16px; color: #6b7280; font-size: 0.875rem;">Un-enrolling...</div>';
        }
        if (row) {
            row.style.opacity = '0.5';
        }
        const encodedUserName = encodeHtmlEntities(displayName);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedEnrollments.delete(String(enrollmentId));
                    updateBulkSelectionUI();
                    updateSelectAllCheckbox();
                    
                    // Close the dropdown menu
                    if (menu) {
                        menu.style.display = 'none';
                    }
                    
                    // Refresh the table automatically
                    refreshEnrollmentsTable();
                } else {
                    alert('Error: ' + (data.message || 'Failed to un-enroll user.'));
                    if (menu) {
                        menu.innerHTML = '<a href="#" class="action-item unenroll-action" data-enrollment-id="' + enrollmentId + '" data-user-id="' + userId + '" data-course-id="' + courseId + '" data-user-name="' + encodedUserName + '"><i class="fa fa-user-times"></i> Un-Enroll</a>';
                    }
                    if (row) {
                        row.style.opacity = '1';
                    }
                    attachEnrollmentSelectionHandlers();
                }
            })
            .catch(error => {
                console.error('Un-enroll error:', error);
                alert('Error: Failed to un-enroll user.');
                if (menu) {
                    menu.innerHTML = '<a href="#" class="action-item unenroll-action" data-enrollment-id="' + enrollmentId + '" data-user-id="' + userId + '" data-course-id="' + courseId + '" data-user-name="' + encodedUserName + '"><i class="fa fa-user-times"></i> Un-Enroll</a>';
                }
                if (row) {
                    row.style.opacity = '1';
                }
                attachEnrollmentSelectionHandlers();
            });
    }

    function registerFilterHandlers() {
        const courseFilter = document.getElementById('course-filter');
        const statusFilter = document.getElementById('status-filter');
        const roleFilter = document.getElementById('role-filter');
        const searchInput = document.getElementById('search-filter');
        const form = document.getElementById('enrollments-filter-form');
        if (!form) {
            return;
        }

        let searchTimeout;
        let currentPage = 1;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            applyFilters();
        });

        [courseFilter, statusFilter, roleFilter].forEach(filter => {
            if (filter) {
                filter.addEventListener('change', function() {
                    currentPage = 1;
                    applyFilters();
                });
            }
        });

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    currentPage = 1;
                    applyFilters();
                }, 500);
            });
        }

    function applyFilters(page = 1) {
        const params = new URLSearchParams(new FormData(form));
        params.set('page', page);
        
        // Include per_page from selector if it exists
        const perPageSelect = document.getElementById('per-page-select');
        if (perPageSelect) {
            params.set('per_page', perPageSelect.value);
        }
        
        const targetUrl = window.location.pathname + '?' + params.toString();
        window.location.href = targetUrl;
    }

        window.applyEnrollmentsFilters = function(page = 1) {
            currentPage = page;
            applyFilters(page);
        };
    }

    document.addEventListener('DOMContentLoaded', function() {
        attachEnrollmentSelectionHandlers();
        const bulkBtn = document.getElementById('bulkUnenrollBtn');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', bulkUnenrollSelected);
        }
        registerFilterHandlers();
        
        // Handle per-page selector change
        const perPageSelect = document.getElementById('per-page-select');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function() {
                const selectedPerPage = this.value;
                const url = new URL(window.location.href);
                url.searchParams.set('per_page', selectedPerPage);
                url.searchParams.set('page', '1'); // Reset to first page when changing per page
                window.location.href = url.toString();
            });
        }
    });

    document.addEventListener('change', function(event) {
        const target = event.target;
        if (!target) {
            return;
        }
        if (target.classList && target.classList.contains('enrollment-select')) {
            onEnrollmentCheckboxChange(event);
        } else if (target.id === 'selectAllEnrollments') {
            onSelectAllChange(event);
        }
    });

    document.addEventListener('click', function(event) {
        const rawTarget = event.target;
        const link = rawTarget && rawTarget.closest ? rawTarget.closest('.unenroll-action') : null;
        if (link) {
            event.preventDefault();
            const enrollmentId = parseInt(link.dataset.enrollmentId, 10);
            const userId = parseInt(link.dataset.userId, 10);
            const courseId = parseInt(link.dataset.courseId, 10);
            const userName = decodeHtmlEntities(link.dataset.userName) || 'Selected User';
            unEnrollUserInternal(enrollmentId, userId, courseId, userName);
            return;
        }

        if (!rawTarget.closest('.actions-dropdown')) {
            document.querySelectorAll('.actions-menu').forEach(menu => {
                menu.style.display = 'none';
            });
        }
    });

    window.toggleActionsDropdown = toggleActionsDropdownInternal;
    window.unEnrollUser = unEnrollUserInternal;
    window.bulkUnenrollSelected = bulkUnenrollSelected;
})();
JS;

$PAGE->requires->js_init_code($enrollmentjs);

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Enrollments Management</h1>";
echo "<p class='page-subtitle'>Manage and view all course enrollments in " . htmlspecialchars($company_info->name) . "</p>";
echo "</div>";
echo "<div class='page-header-actions'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/enroll.php' class='enroll-button'>";
echo "<i class='fa fa-user-plus'></i> Enroll";
echo "</a>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";
echo "</div>";


// Stats Section
echo "<div class='stats-section'>";
echo "<div class='stat-card'>";
echo "<div class='stat-icon blue'>";
echo "<i class='fa fa-users'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$total_enrollments}</div>";
echo "<div class='stat-label'>Total Enrollments</div>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon green'>";
echo "<i class='fa fa-check-circle'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$active_enrollments}</div>";
echo "<div class='stat-label'>Active Enrollments</div>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon purple'>";
echo "<i class='fa fa-pause-circle'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$suspended_enrollments}</div>";
echo "<div class='stat-label'>Suspended Enrollments</div>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon orange'>";
echo "<i class='fa fa-book'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$available_courses}</div>";
echo "<div class='stat-label'>Available Courses</div>";
echo "</div>";
echo "</div>";
echo "</div>";

// Filter Section
echo "<div class='filter-section'>";
echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;'>";
echo "<h2 class='filter-title' style='margin-bottom: 0;'>Filter Enrollments</h2>";
echo "<small style='color: #10b981; font-size: 0.8rem; font-weight: 500;'><i class='fa fa-check-circle'></i> Filters apply automatically</small>";
echo "</div>";
echo "<form method='GET' class='filter-form' id='enrollments-filter-form'>";
echo "<input type='hidden' name='page' value='1'>";
echo "<input type='hidden' name='per_page' value='{$per_page}'>";
echo "<div class='filter-group'>";
echo "<label class='filter-label'>Search Student</label>";
echo "<input type='text' name='search' class='filter-input' id='search-filter' placeholder='Search by name or email...' value='" . htmlspecialchars($search_query) . "'>";
echo "</div>";
echo "<div class='filter-group'>";
echo "<label class='filter-label'>Course</label>";
echo "<select name='course' class='filter-select auto-submit-filter' id='course-filter'>";
echo "<option value='0'>All Courses</option>";
foreach ($available_courses_list as $course) {
    $selected = ($course_filter == $course->id) ? ' selected' : '';
    echo "<option value='{$course->id}'$selected>" . htmlspecialchars($course->fullname) . "</option>";
}
echo "</select>";
echo "</div>";
echo "<div class='filter-group'>";
echo "<label class='filter-label'>Status</label>";
echo "<select name='status' class='filter-select auto-submit-filter' id='status-filter'>";
echo "<option value='all'" . ($status_filter === 'all' ? ' selected' : '') . ">All Status</option>";
echo "<option value='active'" . ($status_filter === 'active' ? ' selected' : '') . ">Active</option>";
echo "<option value='suspended'" . ($status_filter === 'suspended' ? ' selected' : '') . ">Suspended</option>";
echo "</select>";
echo "</div>";
echo "<div class='filter-group'>";
echo "<label class='filter-label'>Role</label>";
echo "<select name='role' class='filter-select auto-submit-filter' id='role-filter'>";
echo "<option value='all'" . ($role_filter === 'all' ? ' selected' : '') . ">All Roles</option>";
echo "<option value='teacher'" . ($role_filter === 'teacher' ? ' selected' : '') . ">Teacher</option>";
echo "<option value='student'" . ($role_filter === 'student' ? ' selected' : '') . ">Student</option>";
echo "</select>";
echo "</div>";
echo "<button type='submit' class='apply-filters-btn' style='opacity: 0.7;' title='Press Enter or click to search immediately'>";
echo "<i class='fa fa-search'></i> Search Now";
echo "</button>";
echo "</form>";
echo "</div>";

// Recent Enrollments Section
echo "<div class='enrollments-section'>";
echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; gap: 12px; flex-wrap: wrap;'>";
echo "<h2 class='enrollments-title' style='margin-bottom: 0;'>Recent Enrollments</h2>";

echo "<div class='bulk-actions-container'>";
echo "<button type='button' id='bulkUnenrollBtn' class='bulk-unenroll-btn' disabled><i class='fa fa-user-times'></i> Un-Enroll Selected</button>";
echo "<span id='selectedCount' class='selection-count'>0 selected</span>";
echo "</div>";

// Note: per-page selector UI removed as per latest design request.

echo "</div>";
echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;'>";

// Show active filters
if (!empty($filter_info)) {
    echo "<div style='display: flex; gap: 8px; align-items: center;'>";
    echo "<span style='color: #6b7280; font-size: 0.875rem; font-weight: 600;'>Active Filters:</span>";
    foreach ($filter_info as $info) {
        echo "<span style='background: #3b82f6; color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;'>{$info}</span>";
    }
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/enrollments.php' style='color: #ef4444; font-size: 0.875rem; font-weight: 600; text-decoration: none; margin-left: 8px;'>Clear Filters</a>";
    echo "</div>";
}

echo "</div>";

// Show results count with pagination info
echo "<div style='background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 12px 16px; margin-bottom: 1rem; border-radius: 4px;' data-total-count='{$total_count}' data-current-page='{$current_page}'>";
echo "<p style='margin: 0; color: #1e40af; font-weight: 600;'>";
if ($total_count > 0) {
    echo "Showing {$start_item}-{$end_item} of {$total_count} enrollment" . ($total_count != 1 ? 's' : '');
    if (!empty($filter_info)) {
        echo " (filtered)";
    }
    if ($total_pages > 1) {
        echo " - Page {$current_page} of {$total_pages}";
    }
} else {
    echo "No enrollments found";
}
echo "</p>";
echo "</div>";

if (!empty($filtered_enrollments)) {
    echo "<table class='enrollments-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th class='select-cell'><input type='checkbox' id='selectAllEnrollments' class='select-all-checkbox'></th>";
    echo "<th>Student</th>";
    echo "<th>Course</th>";
    echo "<th>Role</th>";
    echo "<th>Status</th>";
    echo "<th>Enrolled Date</th>";
    echo "<th>Actions</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($filtered_enrollments as $enrollment) {
        $student_initials = strtoupper(substr($enrollment->firstname, 0, 1) . substr($enrollment->lastname, 0, 1));
        $status_class = $enrollment->status == 0 ? 'status-active' : 'status-suspended';
        $status_text = $enrollment->status == 0 ? 'Active' : 'Suspended';
        $enrolled_date = date('M j Y g:i A', $enrollment->timecreated);
        
        // Get user profile picture URL using Moodle's core function
        $user_picture_url = '';
        try {
            // Fetch full user record to ensure all fields are available
            $user_record = $DB->get_record('user', ['id' => $enrollment->userid], 'id, picture, imagealt, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
            if ($user_record && !empty($user_record->picture)) {
                $user_picture = new user_picture($user_record);
                $user_picture->size = 1; // f1 = 35x35px
                $user_picture_url = $user_picture->get_url($PAGE)->out(false);
            }
        } catch (Exception $e) {
            // If picture generation fails, fall back to initials
            error_log("Failed to generate user picture for user ID {$enrollment->userid}: " . $e->getMessage());
        }
        
        // Determine role display (School admins are excluded from the list)
        // Show actual role names: Editing Teacher, Teacher, Student
        $role_display = 'Unknown';
        $role_class = 'enrollment-method'; // Default styling
        
        if (!empty($enrollment->role_shortname)) {
            switch ($enrollment->role_shortname) {
                case 'editingteacher':
                    $role_display = 'Editing Teacher';
                    $role_class = 'role-badge role-teacher';
                    break;
                case 'teacher':
                    $role_display = 'Teacher';
                    $role_class = 'role-badge role-teacher';
                    break;
                case 'student':
                    $role_display = 'Student';
                    $role_class = 'role-badge role-student';
                    break;
                default:
                    // For any other roles, display them properly formatted
                    $role_display = ucwords(str_replace('_', ' ', $enrollment->role_shortname));
                    $role_class = 'role-badge role-other';
            }
        }
        
        echo "<tr>";
        $safe_name = htmlspecialchars($enrollment->firstname . ' ' . $enrollment->lastname, ENT_QUOTES);
        echo "<td class='select-cell'><input type='checkbox' class='enrollment-select' data-enrollment-id='{$enrollment->id}' data-user-id='{$enrollment->userid}' data-course-id='{$enrollment->courseid}' data-user-name=\"{$safe_name}\"></td>";
        echo "<td>";
        echo "<div class='student-info'>";
        echo "<div class='student-avatar' data-initials='{$student_initials}'>";
        if (!empty($user_picture_url)) {
            echo "<img src='{$user_picture_url}' alt='" . htmlspecialchars($enrollment->firstname . ' ' . $enrollment->lastname) . "' onerror=\"this.style.display='none'; this.parentElement.textContent=this.parentElement.dataset.initials;\" />";
        } else {
            echo $student_initials;
        }
        echo "</div>";
        echo "<div class='student-details'>";
        echo "<div class='student-name'>" . htmlspecialchars($enrollment->firstname . ' ' . $enrollment->lastname) . "</div>";
        echo "</div>";
        echo "</div>";
        echo "</td>";
        echo "<td>";
        echo "<div class='course-name'>" . htmlspecialchars($enrollment->coursename) . "</div>";
        echo "</td>";
        echo "<td>";
        echo "<span class='{$role_class}'>{$role_display}</span>";
        echo "</td>";
        echo "<td>";
        echo "<span class='status-badge {$status_class}'>{$status_text}</span>";
        echo "</td>";
        echo "<td>";
        echo "<span class='enrolled-date'>{$enrolled_date}</span>";
        echo "</td>";
        echo "<td>";
        echo "<div class='actions-dropdown'>";
        echo "<button class='actions-btn' onclick='toggleActionsDropdown({$enrollment->id})' title='More actions'>";
        echo "<i class='fa fa-ellipsis-v'></i>";
        echo "</button>";
        echo "<div class='actions-menu' id='actions-{$enrollment->id}' style='display: none;'>";
        echo "<a href='#' class='action-item unenroll-action' data-enrollment-id='{$enrollment->id}' data-user-id='{$enrollment->userid}' data-course-id='{$enrollment->courseid}' data-user-name=\"{$safe_name}\"><i class='fa fa-user-times'></i> Un-Enroll</a>";
        echo "</div>";
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
} else {
    echo "<table class='enrollments-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th class='select-cell'><input type='checkbox' id='selectAllEnrollments' class='select-all-checkbox'></th>";
    echo "<th>Student</th>";
    echo "<th>Course</th>";
    echo "<th>Role</th>";
    echo "<th>Status</th>";
    echo "<th>Enrolled Date</th>";
    echo "<th>Actions</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    echo "<tr><td colspan='7' style='text-align: center; padding: 3rem; color: #6b7280;'><i class='fa fa-inbox' style='font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; display: block;'></i>No enrollments found</td></tr>";
    echo "</tbody>";
    echo "</table>";
}

echo "</div>"; // End enrollments-section

// Debug pagination variables
error_log("=== PAGINATION DEBUG ===");
error_log("Total Count: " . $total_count);
error_log("Per Page: " . $per_page);
error_log("Total Pages: " . $total_pages);
error_log("Current Page: " . $current_page);

// Add pagination controls - Always show pagination container (OUTSIDE the enrollments-section)
echo "<div class='pagination-container' id='pagination-container'>";
    
if ($total_count > 0) {
    // Pagination info - Always show when there are results
    echo "<div class='pagination-info'>";
    echo "Showing <span>{$start_item}</span>-<span>{$end_item}</span> of <span>{$total_count}</span> enrollment" . ($total_count != 1 ? 's' : '');
if ($total_pages > 1) {
        echo " - Page <span>{$current_page}</span> of <span>{$total_pages}</span>";
    }
    echo "</div>";
    
    // Pagination controls - Always show when there are results (even if only one page)
    if ($total_pages > 0) {
        echo "<div class='pagination-controls'>";
    
        // Previous button - Use JavaScript handler instead of href
    if ($current_page > 1) {
            echo "<button type='button' class='pagination-btn' data-page='" . ($current_page - 1) . "' onclick='handlePaginationClick(" . ($current_page - 1) . ")'>";
        echo "<i class='fa fa-chevron-left'></i> Previous";
            echo "</button>";
    } else {
            echo "<span class='pagination-btn' style='opacity: 0.5; cursor: not-allowed;'>";
        echo "<i class='fa fa-chevron-left'></i> Previous";
        echo "</span>";
    }
    
        // Page numbers - Use JavaScript handlers instead of href
        echo "<div class='pagination-numbers'>";
    $start_page = max(1, $current_page - 2);
        $end_page = max($total_pages, 1) > 1 ? min($total_pages, $current_page + 2) : 1;
    
    if ($start_page > 1) {
            echo "<button type='button' class='pagination-number' data-page='1' onclick='handlePaginationClick(1)'>1</button>";
        if ($start_page > 2) {
            echo "<span style='color: #6b7280; padding: 8px 4px;'>...</span>";
        }
    }
    
        // Ensure at least one page button is rendered
        if ($total_pages <= 1) {
            echo "<span class='pagination-number active'>1</span>";
        } else {
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
                    echo "<span class='pagination-number active'>{$i}</span>";
        } else {
                    echo "<button type='button' class='pagination-number' data-page='{$i}' onclick='handlePaginationClick({$i})'>{$i}</button>";
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            echo "<span style='color: #6b7280; padding: 8px 4px;'>...</span>";
        }
                echo "<button type='button' class='pagination-number' data-page='{$total_pages}' onclick='handlePaginationClick({$total_pages})'>{$total_pages}</button>";
    }
        }
        
        echo "</div>"; // End pagination-numbers
        
        // Next button - Use JavaScript handler instead of href
        if ($current_page < max($total_pages, 1)) {
            echo "<button type='button' class='pagination-btn' data-page='" . ($current_page + 1) . "' onclick='handlePaginationClick(" . ($current_page + 1) . ")'>";
        echo "Next <i class='fa fa-chevron-right'></i>";
            echo "</button>";
    } else {
            echo "<span class='pagination-btn' style='opacity: 0.5; cursor: not-allowed;'>";
        echo "Next <i class='fa fa-chevron-right'></i>";
        echo "</span>";
    }
    
        echo "</div>"; // End pagination-controls
    }
} else {
    // No results - show message
    echo "<div class='pagination-info'>No enrollments found</div>";
}

echo "</div>"; // End pagination-container

echo "</div>"; // End main-content
echo "</div>"; // End school-manager-main-content

echo $OUTPUT->footer();
?>

