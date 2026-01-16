<?php
/**
 * Script to enroll users in courses to create active enrollments
 * This helps set up the active enrollments count properly
 */

require_once('C:/wamp64/www/kodeit/iomad/config.php');
require_login();

// Only allow school managers to access this script
if (!user_has_role_assignment($USER->id, 'companymanager')) {
    die('Access denied. This script is only for school managers.');
}

echo "<h2>Enroll Users in Courses</h2>";
echo "<p><strong>Current User:</strong> " . fullname($USER) . " (ID: {$USER->id})</p>";

// Get company information for the current user
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    die('No company found for this user.');
}

echo "<h3>Company: {$company_info->name}</h3>";

// Handle form submission
if (isset($_POST['enroll_user'])) {
    $user_id = required_param('user_id', PARAM_INT);
    $course_id = required_param('course_id', PARAM_INT);
    $role_id = required_param('role_id', PARAM_INT);
    
    // Get the course
    $course = $DB->get_record('course', ['id' => $course_id]);
    if (!$course) {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
        echo "❌ Course not found.";
        echo "</div>";
    } else {
        // Get the user
        $user = $DB->get_record('user', ['id' => $user_id]);
        if (!$user) {
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
            echo "❌ User not found.";
            echo "</div>";
        } else {
            // Get the role
            $role = $DB->get_record('role', ['id' => $role_id]);
            if (!$role) {
                echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
                echo "❌ Role not found.";
                echo "</div>";
            } else {
                // Get course context
                $context = context_course::instance($course_id);
                
                // Check if user is already enrolled
                $existing_enrollment = $DB->get_record('user_enrolments', 
                    ['userid' => $user_id, 'enrolid' => $course_id]);
                
                if ($existing_enrollment) {
                    echo "<div style='color: orange; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;'>";
                    echo "⚠️ User is already enrolled in this course.";
                    echo "</div>";
                } else {
                    // Enroll user in course
                    $enrol_instance = $DB->get_record('enrol', 
                        ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
                    
                    if ($enrol_instance) {
                        // Use manual enrollment plugin
                        $enrol_plugin = enrol_get_plugin('manual');
                        $enrol_plugin->enrol_user($enrol_instance, $user_id, $role_id, time(), 0, ENROL_USER_ACTIVE);
                        
                        echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
                        echo "✅ Successfully enrolled user '{$user->firstname} {$user->lastname}' in course '{$course->fullname}' with role '{$role->name}'";
                        echo "</div>";
                    } else {
                        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
                        echo "❌ Manual enrollment plugin not available for this course.";
                        echo "</div>";
                    }
                }
            }
        }
    }
}

// Get company users
$company_users = $DB->get_records_sql(
    "SELECT u.id, u.username, u.firstname, u.lastname, u.email
     FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND u.deleted = 0 AND u.suspended = 0
     ORDER BY u.lastname, u.firstname",
    [$company_info->id]
);

// Get available courses
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname
     FROM {course} c
     WHERE c.visible = 1 AND c.id > 1
     ORDER BY c.fullname"
);

// Get available roles
$roles = $DB->get_records_sql(
    "SELECT r.id, r.name, r.shortname
     FROM {role} r
     WHERE r.shortname IN ('student', 'teacher', 'editingteacher')
     ORDER BY r.name"
);

if ($company_users && $courses && $roles) {
    echo "<h3>Enroll User in Course</h3>";
    echo "<form method='post'>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr>";
    echo "<td><strong>Select User:</strong></td>";
    echo "<td>";
    echo "<select name='user_id' required>";
    echo "<option value=''>-- Select User --</option>";
    foreach ($company_users as $user) {
        echo "<option value='{$user->id}'>{$user->firstname} {$user->lastname} ({$user->username})</option>";
    }
    echo "</select>";
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Select Course:</strong></td>";
    echo "<td>";
    echo "<select name='course_id' required>";
    echo "<option value=''>-- Select Course --</option>";
    foreach ($courses as $course) {
        echo "<option value='{$course->id}'>{$course->fullname} ({$course->shortname})</option>";
    }
    echo "</select>";
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Select Role:</strong></td>";
    echo "<td>";
    echo "<select name='role_id' required>";
    echo "<option value=''>-- Select Role --</option>";
    foreach ($roles as $role) {
        echo "<option value='{$role->id}'>{$role->name} ({$role->shortname})</option>";
    }
    echo "</select>";
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td colspan='2'><input type='submit' name='enroll_user' value='Enroll User' style='padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;'></td>";
    echo "</tr>";
    echo "</table>";
    echo "</form>";
    
    // Show current enrollments
    echo "<h3>Current Active Enrollments</h3>";
    $current_enrollments = $DB->get_records_sql(
        "SELECT ue.id, u.firstname, u.lastname, c.fullname as course_name, r.name as role_name, ue.status
         FROM {user_enrolments} ue 
         JOIN {enrol} e ON ue.enrolid = e.id 
         JOIN {course} c ON e.courseid = c.id 
         JOIN {user} u ON ue.userid = u.id
         JOIN {company_users} cu ON u.id = cu.userid
         JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = c.id
         JOIN {role} r ON ra.roleid = r.id
         WHERE cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0 AND c.visible = 1
         ORDER BY u.lastname, u.firstname, c.fullname
         LIMIT 20",
        [$company_info->id]
    );
    
    if ($current_enrollments) {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th>User</th><th>Course</th><th>Role</th><th>Status</th>";
        echo "</tr>";
        foreach ($current_enrollments as $enrollment) {
            echo "<tr>";
            echo "<td>{$enrollment->firstname} {$enrollment->lastname}</td>";
            echo "<td>{$enrollment->course_name}</td>";
            echo "<td>{$enrollment->role_name}</td>";
            echo "<td>" . ($enrollment->status == 0 ? 'Active' : 'Inactive') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if (count($current_enrollments) >= 20) {
            echo "<p><em>Showing first 20 enrollments. There may be more.</em></p>";
        }
    } else {
        echo "<p><em>No current active enrollments found for company users.</em></p>";
    }
    
    // Show summary
    $total_users = count($company_users);
    $total_courses = count($courses);
    $total_enrollments = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.id) 
         FROM {user_enrolments} ue 
         JOIN {enrol} e ON ue.enrolid = e.id 
         JOIN {course} c ON e.courseid = c.id 
         JOIN {user} u ON ue.userid = u.id
         JOIN {company_users} cu ON u.id = cu.userid
         WHERE cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0 AND c.visible = 1",
        [$company_info->id]
    );
    
    echo "<h3>Summary</h3>";
    echo "<p><strong>Total Company Users:</strong> {$total_users}</p>";
    echo "<p><strong>Total Available Courses:</strong> {$total_courses}</p>";
    echo "<p><strong>Total Active Enrollments:</strong> {$total_enrollments}</p>";
    
} else {
    if (!$company_users) {
        echo "<p>No users found in this company.</p>";
    }
    if (!$courses) {
        echo "<p>No courses found in the system.</p>";
    }
    if (!$roles) {
        echo "<p>No suitable roles found.</p>";
    }
}

echo "<hr>";
echo "<p><a href='{$CFG->wwwroot}/my/'>← Back to Dashboard</a> | ";
echo "<a href='debug_teacher_count.php'>Check Enrollment Count</a></p>";
?>
