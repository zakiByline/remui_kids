<?php
/**
 * Manage Cohort Members Page - Custom implementation
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Get cohort ID
$cohortid = required_param('id', PARAM_INT);
$cohort = $DB->get_record('cohort', array('id' => $cohortid), '*', MUST_EXIST);

// Get selected school ID
$schoolid = optional_param('schoolid', 0, PARAM_INT);

$context = context::instance_by_id($cohort->contextid);

$PAGE->set_url('/theme/remui_kids/cohorts/manage_members.php', array('id' => $cohortid, 'schoolid' => $schoolid));
$PAGE->set_context($context);
$PAGE->set_title('Manage Cohort Members: ' . $cohort->name);
$PAGE->set_heading('Manage Cohort Members: ' . $cohort->name);
$PAGE->set_pagelayout('admin');

// Check if user has permission to assign cohort members
require_capability('moodle/cohort:assign', $context);
require_login();

// Get all schools for dropdown
$schools = $DB->get_records('company', [], 'name ASC', 'id, name');

// Get student role ID
$studentrole = $DB->get_record('role', ['shortname' => 'student']);
$studentroleid = $studentrole ? $studentrole->id : 0;

// Handle member removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    
    if ($action === 'remove') {
        $userid = required_param('userid', PARAM_INT);
        
        try {
            cohort_remove_member($cohortid, $userid);
            redirect(
                new moodle_url('/theme/remui_kids/cohorts/manage_members.php', array('id' => $cohortid)),
                'Member removed successfully',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (Exception $e) {
            $error = 'Error removing member: ' . $e->getMessage();
        }
    } else if ($action === 'add') {
        $userids = optional_param_array('userids', array(), PARAM_INT);
        
        if (!empty($userids)) {
            $added = 0;
            foreach ($userids as $userid) {
                try {
                    cohort_add_member($cohortid, $userid);
                    $added++;
                } catch (Exception $e) {
                    // User might already be a member
                }
            }
            
            redirect(
                new moodle_url('/theme/remui_kids/cohorts/manage_members.php', array('id' => $cohortid)),
                $added . ' member(s) added successfully',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } else if ($action === 'bulk_delete') {
        $userids = optional_param_array('delete_userids', array(), PARAM_INT);
        
        if (!empty($userids)) {
            $deleted = 0;
            foreach ($userids as $userid) {
                try {
                    cohort_remove_member($cohortid, $userid);
                    $deleted++;
                } catch (Exception $e) {
                    // User might not be a member
                }
            }
            
            redirect(
                new moodle_url('/theme/remui_kids/cohorts/manage_members.php', array('id' => $cohortid)),
                $deleted . ' member(s) removed successfully',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }
}

// Build SQL for current members with school and student role filter
$members_sql = "
    SELECT u.id, u.firstname, u.lastname, u.email, u.username, cm.timeadded
    FROM {user} u
    JOIN {cohort_members} cm ON cm.userid = u.id
    JOIN {role_assignments} ra ON ra.userid = u.id
    JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 10
    WHERE cm.cohortid = :cohortid
    AND ra.roleid = :studentroleid
";

$members_params = [
    'cohortid' => $cohortid,
    'studentroleid' => $studentroleid
];

// Add school filter if selected
if ($schoolid > 0) {
    $members_sql .= " AND EXISTS (
        SELECT 1 FROM {company_users} cu 
        WHERE cu.userid = u.id AND cu.companyid = :schoolid
    )";
    $members_params['schoolid'] = $schoolid;
}

$members_sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, cm.timeadded
                  ORDER BY u.lastname, u.firstname";

$members = $DB->get_records_sql($members_sql, $members_params);

// Build SQL for potential members (users not in cohort) with school and student role filter
$potential_sql = "
    SELECT u.id, u.firstname, u.lastname, u.email, u.username
    FROM {user} u
    JOIN {role_assignments} ra ON ra.userid = u.id
    JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 10
    WHERE u.deleted = 0 
    AND u.suspended = 0
    AND ra.roleid = :studentroleid
    AND u.id NOT IN (
        SELECT userid FROM {cohort_members} WHERE cohortid = :cohortid
    )
";

$potential_params = [
    'studentroleid' => $studentroleid,
    'cohortid' => $cohortid
];

// Add school filter if selected
if ($schoolid > 0) {
    $potential_sql .= " AND EXISTS (
        SELECT 1 FROM {company_users} cu 
        WHERE cu.userid = u.id AND cu.companyid = :schoolid
    )";
    $potential_params['schoolid'] = $schoolid;
}

$potential_sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username
                    ORDER BY u.lastname, u.firstname";

$potential_members = $DB->get_records_sql($potential_sql, $potential_params);

echo $OUTPUT->header();

// Add custom CSS
echo "<style>
    /* Admin Sidebar Navigation - Sticky on all pages */
    .admin-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        will-change: transform;
        backface-visibility: hidden;
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    .admin-sidebar .sidebar-section {
        margin-bottom: 2rem;
    }
    
    .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-item {
        margin: 2px 0;
    }
    
    .admin-sidebar .sidebar-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background-color: #f8f9fa;
        color: #2c3e50;
        text-decoration: none;
        border-left-color: #667eea;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .admin-sidebar .sidebar-icon {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        font-size: 1rem;
        color: #6c757d;
        text-align: center;
    }
    
    .admin-sidebar .sidebar-text {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px; /* Add padding to account for topbar */
    }
    
    /* Members Container */
    .members-container {
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    /* Two Panel Layout */
    .two-panel-layout {
        display: flex;
        gap: 20px;
        margin-top: 20px;
    }
    
    .panel {
        flex: 1;
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border: 1px solid #e9ecef;
    }
    
    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }
    
    .panel-title {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .panel-count {
        background: #e3f2fd;
        color: #1976d2;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .panel-content {
        max-height: 500px;
        overflow-y: auto;
    }
    
    /* Panel Controls */
    .panel-controls {
        margin: 20px 0;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .select-all-container {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #e9ecef;
    }
    
    .select-all-container input[type='checkbox'] {
        margin: 0;
        transform: scale(1.2);
        cursor: pointer;
    }
    
    .select-all-container label {
        font-weight: 600;
        color: #495057;
        cursor: pointer;
        margin: 0;
        font-size: 14px;
    }
    
    .select-all-container span {
        color: #6c757d;
        font-weight: 500;
        font-size: 13px;
    }
    
    /* Add spacing after button rows */
    .panel-content > div:first-of-type {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #f0f0f0;
    }
    
    /* Add extra space after the control row */
    .panel-content > div:first-of-type::after {
        content: '';
        display: block;
        height: 15px;
    }
    
    /* Add gap between select all and buttons */
    .select-all-container {
        margin-right: 15px;
    }
    
    .btn-add-selected, .btn-bulk-delete {
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .btn-add-selected {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .btn-add-selected:hover {
        background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
    }
    
    .btn-add-selected:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        opacity: 0.6;
    }
    
    .btn-bulk-delete {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        color: white;
    }
    
    .btn-bulk-delete:hover {
        background: linear-gradient(135deg, #ff5252 0%, #e53935 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(255, 107, 107, 0.4);
    }
    
    .btn-bulk-delete:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        opacity: 0.6;
    }
    
    .btn-add-selected i, .btn-bulk-delete i {
        font-size: 16px;
    }
    
    .btn-add-selected span, .btn-bulk-delete span {
        font-size: 12px;
        font-weight: 700;
        background: rgba(255,255,255,0.2);
        padding: 2px 8px;
        border-radius: 12px;
        margin-left: 4px;
    }
    
    .panel-search {
        margin-bottom: 15px;
    }
    
    .panel-search input {
        width: 100%;
        padding: 10px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .panel-search input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .panel-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .panel-table th {
        background: #f8f9fa;
        color: #495057;
        padding: 12px 8px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .panel-table td {
        padding: 12px 8px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 13px;
    }
    
    .panel-table tr:hover {
        background: #f8f9fa;
    }
    
    .user-info-compact {
        display: flex;
        flex-direction: column;
    }
    
    .user-name-compact {
        font-weight: 600;
        color: #2c3e50;
        font-size: 13px;
    }
    
    .user-email-compact {
        font-size: 11px;
        color: #7f8c8d;
        margin-top: 2px;
    }
    
    .panel-actions {
        display: flex;
        gap: 5px;
    }
    
    .btn-compact {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border: none;
    }
    
    .btn-danger-compact {
        background: #e74c3c;
        color: white;
    }
    
    .btn-danger-compact:hover {
        background: #c0392b;
        color: white;
        text-decoration: none;
    }
    
    .btn-primary-compact {
        background: #3498db;
        color: white;
    }
    
    .btn-primary-compact:hover {
        background: #2980b9;
        color: white;
        text-decoration: none;
    }
    
    .checkbox-cell {
        text-align: center;
    }
    
    .user-checkbox {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    
    .panel-footer {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 2px solid #f0f0f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .select-all-container {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #7f8c8d;
    }
    
    .btn-add-selected {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-add-selected:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .no-data {
        text-align: center;
        padding: 40px 20px;
        color: #7f8c8d;
    }
    
    .no-data i {
        font-size: 32px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    
    .no-data h4 {
        margin: 10px 0 5px 0;
        color: #495057;
    }
    
    .no-data p {
        margin: 0;
        font-size: 12px;
    }
    
    .members-header {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .members-title {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 10px 0;
    }
    
    .members-subtitle {
        font-size: 14px;
        color: #7f8c8d;
        margin: 0;
    }
    
    .members-stats {
        text-align: right;
    }
    
    .stat-number {
        font-size: 32px;
        font-weight: 700;
        color: #667eea;
    }
    
    .stat-label {
        font-size: 12px;
        color: #7f8c8d;
        text-transform: uppercase;
    }
    
    /* School Filter Dropdown */
    .school-filter-container {
        margin: 20px 0;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .school-filter-label {
        font-size: 14px;
        font-weight: 600;
        color: #495057;
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
    }
    
    .school-filter-label i {
        color: #667eea;
        font-size: 16px;
    }
    
    .school-filter-select {
        flex: 1;
        max-width: 400px;
        padding: 10px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        color: #495057;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .school-filter-select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .school-filter-select:hover {
        border-color: #667eea;
    }
    
    .members-section {
        margin-bottom: 40px;
    }
    
    .section-title {
        font-size: 20px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .search-box {
        margin-bottom: 20px;
    }
    
    .search-input {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .members-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    
    .members-table thead tr {
        background: #f8f9fa;
    }
    
    .members-table th {
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .members-table td {
        padding: 15px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        color: #2c3e50;
    }
    
    .members-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .user-info {
        display: flex;
        flex-direction: column;
    }
    
    .user-name {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .user-email {
        font-size: 12px;
        color: #7f8c8d;
    }
    
    .btn {
        padding: 8px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .btn-danger {
        background: #e74c3c;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c0392b;
        color: white;
        text-decoration: none;
    }
    
    .btn-secondary {
        background: #e0e0e0;
        color: #2c3e50;
    }
    
    .btn-secondary:hover {
        background: #d0d0d0;
        color: #2c3e50;
        text-decoration: none;
    }
    
    .no-members {
        text-align: center;
        padding: 40px;
        color: #7f8c8d;
    }
    
    .no-members i {
        font-size: 48px;
        margin-bottom: 15px;
        opacity: 0.5;
    }
    
    .add-members-form {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-top: 20px;
    }
    
    .user-checkbox {
        margin-right: 10px;
    }
    
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .alert-danger {
        background: #fee;
        border-left: 4px solid #e74c3c;
        color: #c0392b;
    }
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');

// Main content area
echo "<div class='admin-main-content'>";

// Display errors if any
if (isset($error)) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>⚠️ Error:</strong> " . htmlspecialchars($error);
    echo "</div>";
}

echo "<div class='members-container'>";

// Header
echo "<div class='members-header'>";
echo "<div>";
echo "<h1 class='members-title'>" . htmlspecialchars($cohort->name) . "</h1>";
echo "<p class='members-subtitle'>Manage cohort members and assignments</p>";
echo "</div>";
echo "<div class='members-stats'>";
echo "<div class='stat-number'>" . count($members) . "</div>";
echo "<div class='stat-label'>Total Members</div>";
echo "</div>";
echo "</div>";

// School Filter Dropdown
echo "<div class='school-filter-container'>";
echo "<label for='school-filter' class='school-filter-label'>";
echo "<i class='fa fa-school'></i> Filter by School:";
echo "</label>";
echo "<select id='school-filter' class='school-filter-select' onchange='filterBySchool(this.value)'>";
echo "<option value='0'" . ($schoolid == 0 ? " selected" : "") . ">All Schools</option>";
foreach ($schools as $school) {
    $selected = ($schoolid == $school->id) ? " selected" : "";
    echo "<option value='{$school->id}'{$selected}>" . htmlspecialchars($school->name) . "</option>";
}
echo "</select>";
echo "</div>";

// Two Panel Layout
echo "<div class='two-panel-layout'>";

// Left Panel - Current Members
echo "<div class='panel'>";
echo "<div class='panel-header'>";
echo "<h3 class='panel-title'><i class='fa fa-users'></i> Current Members</h3>";
echo "<span class='panel-count'>" . count($members) . "</span>";
echo "</div>";

echo "<div class='panel-content'>";
echo "<div class='panel-search'>";
echo "<input type='text' placeholder='Search members...' id='member-search'>";
echo "</div>";

// Controls for Current Members (at top of left panel)
if (!empty($members)) {
   
    echo "<div style='display: flex; justify-content: space-between; align-items: center;'>";
    echo "<div class='select-all-container'>";
    echo "<input type='checkbox' id='select-all-members'>";
    echo "<label for='select-all-members'>Select All <span id='member-count-text'></span></label>";
    echo "</div>";
    echo "<button type='submit' form='delete-members-form' class='btn-bulk-delete' id='delete-button' onclick='return confirm(\"Are you sure you want to delete the selected members?\")'>";
    echo "<i class='fa fa-trash'></i> Delete Selected <span id='delete-count'>(0)</span>";
    echo "</button>";
    echo "</div>";
    echo "<br>"; // Add spacing after buttons
  
    
}

if (!empty($members)) {
    echo "<form method='POST' action='' id='delete-members-form'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    echo "<input type='hidden' name='action' value='bulk_delete'>";
    
    echo "<table class='panel-table' id='members-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th width='40' class='checkbox-cell'>Select</th>";
    echo "<th>Name</th>";
    echo "<th>Username</th>";
    echo "<th>Added</th>";
    echo "<th>Actions</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($members as $member) {
        echo "<tr>";
        echo "<td class='checkbox-cell'>";
        echo "<input type='checkbox' name='delete_userids[]' value='{$member->id}' class='member-checkbox'>";
        echo "</td>";
        echo "<td>";
        echo "<div class='user-info-compact'>";
        echo "<span class='user-name-compact'>" . fullname($member) . "</span>";
        echo "<span class='user-email-compact'>" . htmlspecialchars($member->email) . "</span>";
        echo "</div>";
        echo "</td>";
        echo "<td>" . htmlspecialchars($member->username) . "</td>";
        echo "<td>" . userdate($member->timeadded, '%d %b %Y') . "</td>";
        echo "<td>";
        echo "<div class='panel-actions'>";
        echo "<form method='POST' action='' style='display: inline;'>";
        echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
        echo "<input type='hidden' name='action' value='remove'>";
        echo "<input type='hidden' name='userid' value='{$member->id}'>";
        echo "<button type='submit' class='btn-compact btn-danger-compact' onclick='return confirm(\"Remove this member?\")'>";
        echo "<i class='fa fa-trash'></i>";
        echo "</button>";
        echo "</form>";
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</form>";
} else {
    echo "<div class='no-data'>";
    echo "<i class='fa fa-users'></i>";
    echo "<h4>No Members Yet</h4>";
    echo "<p>This cohort doesn't have any members.</p>";
    echo "</div>";
}

echo "</div>"; // End panel-content
echo "</div>"; // End left panel

// Right Panel - Add Members
echo "<div class='panel'>";
echo "<div class='panel-header'>";
echo "<h3 class='panel-title'><i class='fa fa-user-plus'></i> Add Members</h3>";
echo "<span class='panel-count'>" . count($potential_members) . "</span>";
echo "</div>";

echo "<div class='panel-content'>";
echo "<div class='panel-search'>";
echo "<input type='text' placeholder='Search users to add...' id='potential-search'>";
echo "</div>";

// Controls for Add Members (at top of right panel)
if (!empty($potential_members)) {
    
    echo "<div style='display: flex; justify-content: space-between; align-items: center;'>";
    echo "<div class='select-all-container'>";
    echo "<input type='checkbox' id='select-all-users'>";
    echo "<label for='select-all-users'>Select All <span id='visible-count-text'></span></label>";
    echo "</div>";
    echo "<button type='submit' form='add-members-form' class='btn-add-selected' id='add-button'>";
    echo "<i class='fa fa-plus'></i> Add Selected <span id='selected-count'>(0)</span>";
    echo "</button>";
    echo "</div>";
    echo "<br>"; // Add spacing after buttons
   
}

if (!empty($potential_members)) {
    echo "<form method='POST' action='' id='add-members-form'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    echo "<input type='hidden' name='action' value='add'>";
    
    echo "<table class='panel-table' id='potential-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th width='40' class='checkbox-cell'>Select</th>";
    echo "<th>Name</th>";
    echo "<th>Username</th>";
    echo "<th>Email</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($potential_members as $user) {
        echo "<tr>";
        echo "<td class='checkbox-cell'>";
        echo "<input type='checkbox' name='userids[]' value='{$user->id}' class='user-checkbox'>";
        echo "</td>";
        echo "<td>";
        echo "<div class='user-info-compact'>";
        echo "<span class='user-name-compact'>" . fullname($user) . "</span>";
        echo "<span class='user-email-compact'>" . htmlspecialchars($user->email) . "</span>";
        echo "</div>";
        echo "</td>";
        echo "<td>" . htmlspecialchars($user->username) . "</td>";
        echo "<td>" . htmlspecialchars($user->email) . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    echo "</form>";
} else {
    echo "<div class='no-data'>";
    echo "<i class='fa fa-user-plus'></i>";
    echo "<h4>No Users Available</h4>";
    echo "<p>All users are already in this cohort.</p>";
    echo "</div>";
}

echo "</div>"; // End panel-content
echo "</div>"; // End right panel

echo "</div>"; // End two-panel-layout

// Back button
echo "<div style='margin-top: 30px; padding-top: 20px; border-top: 2px solid #f0f0f0;'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/index.php' class='btn btn-secondary'>";
echo "<i class='fa fa-arrow-left'></i> Back to Cohorts";
echo "</a>";
echo "</div>";

echo "</div>"; // End members-container

// Add JavaScript for search and select all functionality
echo "<script>
// Function to filter by school - reload page with selected school parameter
function filterBySchool(schoolId) {
    const currentUrl = new URL(window.location.href);
    if (schoolId === '0') {
        currentUrl.searchParams.delete('schoolid');
    } else {
        currentUrl.searchParams.set('schoolid', schoolId);
    }
    window.location.href = currentUrl.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    // Search current members
    const memberSearch = document.getElementById('member-search');
    const membersTable = document.getElementById('members-table');
    
    if (memberSearch && membersTable) {
        const memberRows = membersTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        memberSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let visibleCount = 0;
            
            for (let i = 0; i < memberRows.length; i++) {
                const row = memberRows[i];
                const text = row.textContent.toLowerCase();
                const isVisible = text.includes(searchTerm);
                row.style.display = isVisible ? '' : 'none';
                
                // Uncheck hidden rows
                if (!isVisible) {
                    const checkbox = row.querySelector('.member-checkbox');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                } else {
                    visibleCount++;
                }
            }
            
            // Update member count text
            updateMemberCountText(visibleCount);
            
            // Update select all state
            updateSelectAllMembersState();
        });
    }
    
    // Search potential members
    const potentialSearch = document.getElementById('potential-search');
    const potentialTable = document.getElementById('potential-table');
    
    if (potentialSearch && potentialTable) {
        const potentialRows = potentialTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        potentialSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let visibleCount = 0;
            
            for (let i = 0; i < potentialRows.length; i++) {
                const row = potentialRows[i];
                const text = row.textContent.toLowerCase();
                const isVisible = text.includes(searchTerm);
                row.style.display = isVisible ? '' : 'none';
                
                // Uncheck hidden rows
                if (!isVisible) {
                    const checkbox = row.querySelector('.user-checkbox');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                } else {
                    visibleCount++;
                }
            }
            
            // Update visible count in panel
            updateVisibleCount(visibleCount);
            
            // Update select all checkbox state
            updateSelectAllState();
        });
    }
    
    // Select All functionality - only selects VISIBLE users
    const selectAllCheckbox = document.getElementById('select-all-users');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    
    if (selectAllCheckbox && userCheckboxes.length > 0) {
        selectAllCheckbox.addEventListener('change', function() {
            // Only select/deselect visible checkboxes
            userCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (row && row.style.display !== 'none') {
                    checkbox.checked = this.checked;
                }
            });
            
            // Update the selected count after changing checkboxes
            updateSelectAllState();
        });
        
        // Update select all when individual checkboxes change
        userCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectAllState();
            });
        });
    }
    
    // Function to update select all checkbox state based on visible checkboxes
    function updateSelectAllState() {
        if (!selectAllCheckbox || !userCheckboxes.length) return;
        
        const visibleCheckboxes = Array.from(userCheckboxes).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const checkedVisibleCount = visibleCheckboxes.filter(cb => cb.checked).length;
        const visibleCount = visibleCheckboxes.length;
        
        // Debug logging
        console.log('updateSelectAllState - visibleCount:', visibleCount, 'checkedVisibleCount:', checkedVisibleCount);
        
        if (visibleCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = checkedVisibleCount === visibleCount;
            selectAllCheckbox.indeterminate = checkedVisibleCount > 0 && checkedVisibleCount < visibleCount;
        }
        
        // Update selected count display
        updateSelectedCount(checkedVisibleCount);
        
        // Update visible count text
        const visibleCountText = document.getElementById('visible-count-text');
        if (visibleCountText && potentialSearch && potentialSearch.value.trim() !== '') {
            visibleCountText.textContent = '(' + visibleCount + ' visible)';
        } else if (visibleCountText) {
            visibleCountText.textContent = '';
        }
    }
    
    // Function to update selected count in button
    function updateSelectedCount(count) {
        const selectedCountSpan = document.getElementById('selected-count');
        const addButton = document.getElementById('add-button');
        
        // Debug logging
        console.log('updateSelectedCount called with count:', count);
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = '(' + count + ')';
        }
        
        // Disable button if no users selected
        if (addButton) {
            if (count === 0) {
                addButton.disabled = true;
                addButton.style.opacity = '0.5';
                addButton.style.cursor = 'not-allowed';
            } else {
                addButton.disabled = false;
                addButton.style.opacity = '1';
                addButton.style.cursor = 'pointer';
            }
        }
    }
    
    // Function to update visible count display
    function updateVisibleCount(count) {
        const panelCountBadges = document.querySelectorAll('.panel-count');
        if (panelCountBadges.length >= 2) {
            // Update second panel (Add Members) with filtered count
            const totalCount = document.querySelectorAll('#potential-table tbody tr').length;
            if (potentialSearch && potentialSearch.value.trim() !== '') {
                panelCountBadges[1].textContent = count;
                panelCountBadges[1].title = 'Showing ' + count + ' of ' + totalCount + ' users';
            } else {
                panelCountBadges[1].textContent = totalCount;
                panelCountBadges[1].title = totalCount + ' total users';
            }
        }
    }
    
    // Update panel counts dynamically
    function updatePanelCounts() {
        // Count current members (left panel)
        const membersTable = document.getElementById('members-table');
        let memberCount = 0;
        if (membersTable) {
            memberCount = membersTable.querySelectorAll('tbody tr').length;
        }
        
        // Count potential members (right panel)
        const potentialTable = document.getElementById('potential-table');
        let potentialCount = 0;
        if (potentialTable) {
            potentialCount = potentialTable.querySelectorAll('tbody tr').length;
        } else {
            // If no table exists, check if there's a no-data message
            const noDataElement = document.querySelector('.no-data');
            if (noDataElement && noDataElement.textContent.includes('No Users Available')) {
                potentialCount = 0;
            }
        }
        
        // Update current members count (first panel)
        const memberCountBadges = document.querySelectorAll('.panel-count');
        if (memberCountBadges.length >= 1) {
            memberCountBadges[0].textContent = memberCount;
        }
        
        // Update potential members count (second panel)
        if (memberCountBadges.length >= 2) {
            memberCountBadges[1].textContent = potentialCount;
        }
    }
    
    // Bulk Delete functionality for Current Members
    const selectAllMembersCheckbox = document.getElementById('select-all-members');
    const memberCheckboxes = document.querySelectorAll('.member-checkbox');
    
    if (selectAllMembersCheckbox && memberCheckboxes.length > 0) {
        selectAllMembersCheckbox.addEventListener('change', function() {
            // Only select/deselect visible checkboxes
            memberCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (row && row.style.display !== 'none') {
                    checkbox.checked = this.checked;
                }
            });
            updateDeleteCount();
        });
        
        memberCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateDeleteCount();
                updateSelectAllMembersState();
            });
        });
    }
    
    // Function to update delete count
    function updateDeleteCount() {
        const checkedMembers = document.querySelectorAll('.member-checkbox:checked');
        const deleteCountSpan = document.getElementById('delete-count');
        const deleteButton = document.getElementById('delete-button');
        
        const count = checkedMembers.length;
        
        if (deleteCountSpan) {
            deleteCountSpan.textContent = '(' + count + ')';
        }
        
        if (deleteButton) {
            if (count === 0) {
                deleteButton.disabled = true;
                deleteButton.style.opacity = '0.5';
                deleteButton.style.cursor = 'not-allowed';
            } else {
                deleteButton.disabled = false;
                deleteButton.style.opacity = '1';
                deleteButton.style.cursor = 'pointer';
            }
        }
    }
    
    // Function to update select all members state
    function updateSelectAllMembersState() {
        if (!selectAllMembersCheckbox || !memberCheckboxes.length) return;
        
        const visibleCheckboxes = Array.from(memberCheckboxes).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const checkedVisibleCount = visibleCheckboxes.filter(cb => cb.checked).length;
        const visibleCount = visibleCheckboxes.length;
        
        if (visibleCount === 0) {
            selectAllMembersCheckbox.checked = false;
            selectAllMembersCheckbox.indeterminate = false;
        } else {
            selectAllMembersCheckbox.checked = checkedVisibleCount === visibleCount;
            selectAllMembersCheckbox.indeterminate = checkedVisibleCount > 0 && checkedVisibleCount < visibleCount;
        }
        
        // Update member count text
        updateMemberCountText(visibleCount);
    }
    
    // Function to update member count text
    function updateMemberCountText(visibleCount) {
        const memberCountText = document.getElementById('member-count-text');
        const memberSearch = document.getElementById('member-search');
        
        if (memberCountText) {
            if (memberSearch && memberSearch.value.trim() !== '') {
                memberCountText.textContent = '(' + visibleCount + ' visible)';
            } else {
                memberCountText.textContent = '(' + visibleCount + ')';
            }
        }
    }
    
    // Initialize button state on page load
    updateSelectedCount(0);
    updateDeleteCount();
    
    // Also update the select all state to ensure proper initial counts
    updateSelectAllState();
    updateSelectAllMembersState();
    
    // Only call update function if we need to refresh counts after changes
    // Don't override the initial PHP counts on page load
    // updatePanelCounts();
});
</script>";

echo "</div>"; // End admin-main-content

echo $OUTPUT->footer();

