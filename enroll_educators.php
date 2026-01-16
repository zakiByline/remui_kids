<?php
/**
 * Script to enroll educators in courses to make them "enrolled teachers"
 * This helps set up the enrolled teachers count properly
 */

require_once('C:/wamp64/www/kodeit/iomad/config.php');
require_login();

// Only allow school managers to access this script
if (!user_has_role_assignment($USER->id, 'companymanager')) {
    die('Access denied. This script is only for school managers.');
}

echo "<h2>Enroll Educators in Courses</h2>";
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
if (isset($_POST['enroll_educator'])) {
    $user_id = required_param('user_id', PARAM_INT);
    $course_id = required_param('course_id', PARAM_INT);
    $role_id = required_param('role_id', PARAM_INT);
    
    // Get the course context
    $course = $DB->get_record('course', ['id' => $course_id]);
    if (!$course) {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
        echo "❌ Course not found.";
        echo "</div>";
    } else {
        $context = context_course::instance($course_id);
        
        // Assign role to user in course context
        $role = $DB->get_record('role', ['id' => $role_id]);
        if ($role) {
            role_assign($role_id, $user_id, $context->id);
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
            echo "✅ Successfully assigned role '{$role->name}' to user in course '{$course->fullname}'";
            echo "</div>";
        } else {
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
            echo "❌ Role not found.";
            echo "</div>";
        }
    }
}

// Get educators in the company
$educators = $DB->get_records_sql(
    "SELECT u.id, u.username, u.firstname, u.lastname, u.email
     FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0
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
     WHERE r.shortname IN ('teacher', 'editingteacher', 'coursecreator', 'student')
     ORDER BY r.name"
);

if ($educators && $courses && $roles) {
    echo "<h3>Enroll Educator in Course</h3>";
    echo "<form method='post'>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr>";
    echo "<td><strong>Select Educator:</strong></td>";
    echo "<td>";
    echo "<select name='user_id' required>";
    echo "<option value=''>-- Select Educator --</option>";
    foreach ($educators as $educator) {
        echo "<option value='{$educator->id}'>{$educator->firstname} {$educator->lastname} ({$educator->username})</option>";
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
    echo "<td colspan='2'><input type='submit' name='enroll_educator' value='Enroll Educator' style='padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;'></td>";
    echo "</tr>";
    echo "</table>";
    echo "</form>";
    
    // Show current enrollments
    echo "<h3>Current Educator Enrollments</h3>";
    $current_enrollments = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, c.fullname as course_name, r.name as role_name
         FROM {user} u 
         JOIN {company_users} cu ON u.id = cu.userid 
         JOIN {role_assignments} ra ON u.id = ra.userid 
         JOIN {role} r ON ra.roleid = r.id 
         JOIN {context} ctx ON ra.contextid = ctx.id 
         JOIN {course} c ON ctx.instanceid = c.id
         WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
         AND ctx.contextlevel = ? AND c.visible = 1
         AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator', 'student')
         ORDER BY u.lastname, u.firstname, c.fullname",
        [$company_info->id, CONTEXT_COURSE]
    );
    
    if ($current_enrollments) {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th>Educator</th><th>Course</th><th>Role</th>";
        echo "</tr>";
        foreach ($current_enrollments as $enrollment) {
            echo "<tr>";
            echo "<td>{$enrollment->firstname} {$enrollment->lastname}</td>";
            echo "<td>{$enrollment->course_name}</td>";
            echo "<td>{$enrollment->role_name}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><em>No current enrollments found for educators.</em></p>";
    }
    
} else {
    if (!$educators) {
        echo "<p>No educators found in this company. <a href='assign_educator_status.php'>Assign educator status first</a>.</p>";
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
echo "<a href='debug_teacher_count.php'>Check Teacher Count</a> | ";
echo "<a href='assign_educator_status.php'>Assign Educator Status</a></p>";
?>
