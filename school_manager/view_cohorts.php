<?php
/**
 * View Available Cohorts - Show all cohorts in the system
 */

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    die('Access denied. School manager role required.');
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
    die('No company found for current user.');
}

echo "<h1>Available Cohorts</h1>";
echo "<p><strong>Company:</strong> {$company_info->name}</p>";

// Get all cohorts
$cohorts = $DB->get_records('cohort', ['visible' => 1], 'name ASC');

if ($cohorts) {
    echo "<h2>All Cohorts in System</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th><th>Description</th><th>ID Number</th><th>Members</th><th>Grade Level</th></tr>";
    
    foreach ($cohorts as $cohort) {
        // Count members
        $member_count = $DB->count_records('cohort_members', ['cohortid' => $cohort->id]);
        
        // Determine grade level
        $grade_level = determine_grade_level_from_cohort($cohort->name);
        
        echo "<tr>";
        echo "<td>{$cohort->id}</td>";
        echo "<td><strong>{$cohort->name}</strong></td>";
        echo "<td>" . ($cohort->description ? $cohort->description : 'No description') . "</td>";
        echo "<td>" . ($cohort->idnumber ? $cohort->idnumber : 'No ID') . "</td>";
        echo "<td>{$member_count}</td>";
        echo "<td><em>{$grade_level}</em></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>CSV Format for Bulk Upload</h3>";
    echo "<p>When creating a CSV file for bulk upload, use the cohort <strong>name</strong> (not ID) in the cohort column.</p>";
    echo "<p><strong>Example CSV:</strong></p>";
    echo "<pre>";
    echo "firstname,lastname,email,username,password,role,cohort\n";
    echo "John,Doe,john.doe@email.com,john.doe,password123,student,Grade 9A\n";
    echo "Jane,Smith,jane.smith@email.com,jane.smith,password456,student,Grade 10B\n";
    echo "</pre>";
    
} else {
    echo "<p>No cohorts found in the system.</p>";
    echo "<p>You may need to create cohorts first before adding students.</p>";
}

echo "<p><a href='add_student.php'>Back to Add Student</a></p>";

/**
 * Determine grade level from cohort name
 */
function determine_grade_level_from_cohort($cohort_name) {
    $cohort_name = strtolower(trim($cohort_name));
    
    // Check for specific grade patterns
    if (preg_match('/grade\s*1\b/', $cohort_name)) return 'Grade 1';
    if (preg_match('/grade\s*2\b/', $cohort_name)) return 'Grade 2';
    if (preg_match('/grade\s*3\b/', $cohort_name)) return 'Grade 3';
    if (preg_match('/grade\s*4\b/', $cohort_name)) return 'Grade 4';
    if (preg_match('/grade\s*5\b/', $cohort_name)) return 'Grade 5';
    if (preg_match('/grade\s*6\b/', $cohort_name)) return 'Grade 6';
    if (preg_match('/grade\s*7\b/', $cohort_name)) return 'Grade 7';
    if (preg_match('/grade\s*8\b/', $cohort_name)) return 'Grade 8';
    if (preg_match('/grade\s*9\b/', $cohort_name)) return 'Grade 9';
    if (preg_match('/grade\s*10\b/', $cohort_name)) return 'Grade 10';
    if (preg_match('/grade\s*11\b/', $cohort_name)) return 'Grade 11';
    if (preg_match('/grade\s*12\b/', $cohort_name)) return 'Grade 12';
    
    // Check for general grade ranges
    if (preg_match('/grade\s*[1-3]/', $cohort_name)) return 'Elementary';
    if (preg_match('/grade\s*[4-7]/', $cohort_name)) return 'Middle School';
    if (preg_match('/grade\s*[8-9]|grade\s*1[0-2]/', $cohort_name)) return 'High School';
    
    // Check for other patterns
    if (strpos($cohort_name, 'elementary') !== false) return 'Elementary';
    if (strpos($cohort_name, 'middle') !== false) return 'Middle School';
    if (strpos($cohort_name, 'high') !== false) return 'High School';
    if (strpos($cohort_name, 'primary') !== false) return 'Primary';
    if (strpos($cohort_name, 'secondary') !== false) return 'Secondary';
    
    // Default fallback
    return 'Grade Level';
}
?>
