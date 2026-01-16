<?php
/**
 * Script to assign courses to companies in IOMAD
 * This helps set up the available courses count properly
 */

require_once('C:/wamp64/www/kodeit/iomad/config.php');
require_login();

// Only allow school managers to access this script
if (!user_has_role_assignment($USER->id, 'companymanager')) {
    die('Access denied. This script is only for school managers.');
}

echo "<h2>Assign Courses to Company</h2>";
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
if (isset($_POST['assign_course'])) {
    $course_id = required_param('course_id', PARAM_INT);
    
    // Check if company_course table exists
    if (!$DB->get_manager()->table_exists('company_course')) {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
        echo "❌ company_course table doesn't exist. Cannot assign courses to company.";
        echo "</div>";
    } else {
        // Check if course is already assigned
        $existing = $DB->get_record('company_course', 
            ['companyid' => $company_info->id, 'courseid' => $course_id]);
        
        if ($existing) {
            echo "<div style='color: orange; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;'>";
            echo "⚠️ Course is already assigned to this company.";
            echo "</div>";
        } else {
            // Assign course to company
            $record = new stdClass();
            $record->companyid = $company_info->id;
            $record->courseid = $course_id;
            
            $inserted = $DB->insert_record('company_course', $record);
            
            if ($inserted) {
                echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
                echo "✅ Course successfully assigned to company!";
                echo "</div>";
            } else {
                echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
                echo "❌ Failed to assign course to company.";
                echo "</div>";
            }
        }
    }
}

// Handle course removal
if (isset($_POST['remove_course'])) {
    $course_id = required_param('course_id', PARAM_INT);
    
    if ($DB->get_manager()->table_exists('company_course')) {
        $deleted = $DB->delete_records('company_course', 
            ['companyid' => $company_info->id, 'courseid' => $course_id]);
        
        if ($deleted) {
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
            echo "✅ Course successfully removed from company!";
            echo "</div>";
        } else {
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
            echo "❌ Failed to remove course from company.";
            echo "</div>";
        }
    }
}

// Check if company_course table exists
$table_exists = $DB->get_manager()->table_exists('company_course');
echo "<p><strong>company_course table exists:</strong> " . ($table_exists ? 'Yes' : 'No') . "</p>";

if ($table_exists) {
    // Show currently assigned courses
    $assigned_courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.visible, cc.id as assignment_id
         FROM {course} c 
         JOIN {company_course} cc ON c.id = cc.courseid
         WHERE cc.companyid = ?
         ORDER BY c.fullname",
        [$company_info->id]
    );
    
    if ($assigned_courses) {
        echo "<h3>Currently Assigned Courses</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th>Course Name</th><th>Short Name</th><th>Visible</th><th>Action</th>";
        echo "</tr>";
        
        foreach ($assigned_courses as $course) {
            echo "<tr>";
            echo "<td>{$course->fullname}</td>";
            echo "<td>{$course->shortname}</td>";
            echo "<td>" . ($course->visible ? 'Yes' : 'No') . "</td>";
            echo "<td>";
            echo "<form method='post' style='display: inline;'>";
            echo "<input type='hidden' name='course_id' value='{$course->id}'>";
            echo "<input type='submit' name='remove_course' value='Remove' style='padding: 2px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;'>";
            echo "</form>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<h3>Currently Assigned Courses</h3>";
        echo "<p><em>No courses are currently assigned to this company.</em></p>";
    }
    
    // Show available courses to assign
    $available_courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.visible
         FROM {course} c
         WHERE c.visible = 1 AND c.id > 1 
         AND c.id NOT IN (
             SELECT cc.courseid 
             FROM {company_course} cc 
             WHERE cc.companyid = ?
         )
         ORDER BY c.fullname",
        [$company_info->id]
    );
    
    if ($available_courses) {
        echo "<h3>Available Courses to Assign</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th>Course Name</th><th>Short Name</th><th>Visible</th><th>Action</th>";
        echo "</tr>";
        
        foreach ($available_courses as $course) {
            echo "<tr>";
            echo "<td>{$course->fullname}</td>";
            echo "<td>{$course->shortname}</td>";
            echo "<td>" . ($course->visible ? 'Yes' : 'No') . "</td>";
            echo "<td>";
            echo "<form method='post' style='display: inline;'>";
            echo "<input type='hidden' name='course_id' value='{$course->id}'>";
            echo "<input type='submit' name='assign_course' value='Assign' style='padding: 2px 8px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;'>";
            echo "</form>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<h3>Available Courses to Assign</h3>";
        echo "<p><em>No available courses to assign (all courses may already be assigned).</em></p>";
    }
    
    // Show summary
    $total_assigned = count($assigned_courses);
    $total_available = count($available_courses);
    $total_courses = $DB->count_records_sql("SELECT COUNT(*) FROM {course} WHERE visible = 1 AND id > 1");
    
    echo "<h3>Summary</h3>";
    echo "<p><strong>Total Courses in System:</strong> {$total_courses}</p>";
    echo "<p><strong>Assigned to Company:</strong> {$total_assigned}</p>";
    echo "<p><strong>Available to Assign:</strong> {$total_available}</p>";
    
} else {
    echo "<h3>Setup Required</h3>";
    echo "<p>The <code>company_course</code> table doesn't exist. This table is needed to assign courses to companies.</p>";
    echo "<p>You may need to:</p>";
    echo "<ul>";
    echo "<li>Run the IOMAD database upgrade</li>";
    echo "<li>Install the IOMAD company course plugin</li>";
    echo "<li>Check your IOMAD installation</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='{$CFG->wwwroot}/my/'>← Back to Dashboard</a> | ";
echo "<a href='debug_teacher_count.php'>Check Course Count</a></p>";
?>
