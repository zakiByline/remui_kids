<?php
/**
 * Script to assign educator status to users in IOMAD
 * This helps set up teachers properly in the system
 */

require_once('C:/wamp64/www/kodeit/iomad/config.php');
require_login();

// Only allow school managers to access this script
if (!user_has_role_assignment($USER->id, 'companymanager')) {
    die('Access denied. This script is only for school managers.');
}

echo "<h2>Assign Educator Status to Users</h2>";
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
if (isset($_POST['assign_educator'])) {
    $user_id = required_param('user_id', PARAM_INT);
    $educator_status = required_param('educator_status', PARAM_INT);
    
    // Update educator status
    $updated = $DB->set_field('company_users', 'educator', $educator_status, 
        ['userid' => $user_id, 'companyid' => $company_info->id]);
    
    if ($updated) {
        echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
        echo "✅ Educator status updated successfully!";
        echo "</div>";
    } else {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
        echo "❌ Failed to update educator status.";
        echo "</div>";
    }
}

// Get all users in the company
$company_users = $DB->get_records_sql(
    "SELECT u.id, u.username, u.firstname, u.lastname, u.email, cu.educator, cu.managertype
     FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND u.deleted = 0 AND u.suspended = 0
     ORDER BY u.lastname, u.firstname",
    [$company_info->id]
);

if ($company_users) {
    echo "<h3>Company Users</h3>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
    echo "<tr style='background: #f8f9fa;'>";
    echo "<th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Manager Type</th><th>Educator Status</th><th>Action</th>";
    echo "</tr>";
    
    foreach ($company_users as $user) {
        $educator_text = $user->educator ? '✅ Yes' : '❌ No';
        $manager_text = '';
        switch ($user->managertype) {
            case 0: $manager_text = 'User'; break;
            case 1: $manager_text = 'Company Manager'; break;
            case 2: $manager_text = 'Department Manager'; break;
            default: $manager_text = 'Unknown'; break;
        }
        
        echo "<tr>";
        echo "<td>{$user->id}</td>";
        echo "<td>{$user->username}</td>";
        echo "<td>{$user->firstname} {$user->lastname}</td>";
        echo "<td>{$user->email}</td>";
        echo "<td>{$manager_text}</td>";
        echo "<td>{$educator_text}</td>";
        echo "<td>";
        
        if ($user->managertype == 0) { // Only allow for regular users, not managers
            echo "<form method='post' style='display: inline;'>";
            echo "<input type='hidden' name='user_id' value='{$user->id}'>";
            echo "<select name='educator_status' style='margin-right: 5px;'>";
            echo "<option value='0'" . (!$user->educator ? ' selected' : '') . ">Remove Educator</option>";
            echo "<option value='1'" . ($user->educator ? ' selected' : '') . ">Make Educator</option>";
            echo "</select>";
            echo "<input type='submit' name='assign_educator' value='Update' style='padding: 2px 8px;'>";
            echo "</form>";
        } else {
            echo "<em>Manager</em>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show summary
    $total_users = count($company_users);
    $educators = array_filter($company_users, function($user) { return $user->educator == 1; });
    $educator_count = count($educators);
    
    echo "<h3>Summary</h3>";
    echo "<p><strong>Total Users in Company:</strong> {$total_users}</p>";
    echo "<p><strong>Educators:</strong> {$educator_count}</p>";
    echo "<p><strong>Regular Users:</strong> " . ($total_users - $educator_count) . "</p>";
    
} else {
    echo "<p>No users found in this company.</p>";
}

echo "<hr>";
echo "<p><a href='{$CFG->wwwroot}/my/'>← Back to Dashboard</a> | ";
echo "<a href='debug_teacher_count.php'>Check Teacher Count</a></p>";
?>
