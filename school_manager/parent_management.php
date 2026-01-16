<?php
/**
 * Parent Management Page
 * Provides summary metrics and parent directory for school managers.
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $DB, $CFG, $USER, $PAGE, $OUTPUT;

// Ensure lib.php is loaded for theme functions
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

// Ensure current user is a school/company manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;
$context = context_system::instance();

if ($companymanagerrole) {
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect(new moodle_url('/my/'), get_string('nopermissions', 'error', 'view parent management'), null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company info of current manager.
$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Company not found', null, \core\output\notification::NOTIFY_ERROR);
}

$companyid = $company_info->id;
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
$parents = [];
$parentids = [];
$contextuserlevel = CONTEXT_USER;

if ($parent_role) {
    // Parents that belong to this company directly (have parent role anywhere).
    if ($DB->get_manager()->table_exists('company_users')) {
        $company_parents = $DB->get_records_sql(
            "SELECT DISTINCT u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.phone1,
                    u.firstaccess,
                    u.lastaccess,
                    u.suspended
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {role_assignments} ra ON ra.userid = u.id
              WHERE ra.roleid = :roleid
                AND u.deleted = 0",
            [
                'companyid' => $companyid,
                'roleid' => $parent_role->id
            ]
        );

        foreach ($company_parents as $parent) {
            $parents[$parent->id] = $parent;
        }
    }

    // Parents linked to children in this company via parent role assignments.
    if ($DB->get_manager()->table_exists('company_users')) {
        $role_parents = $DB->get_records_sql(
            "SELECT DISTINCT p.id,
                    p.firstname,
                    p.lastname,
                    p.email,
                    p.phone1,
                    p.firstaccess,
                    p.lastaccess,
                    p.suspended
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {user} child ON child.id = ctx.instanceid
               JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = :companyid
               JOIN {user} p ON p.id = ra.userid
              WHERE ra.roleid = :roleid
                AND p.deleted = 0",
            [
                'ctxlevel' => $contextuserlevel,
                'companyid' => $companyid,
                'roleid' => $parent_role->id
            ]
        );

        foreach ($role_parents as $parent) {
            if (!isset($parents[$parent->id])) {
                $parents[$parent->id] = $parent;
            }
        }
    }

    $parentids = array_keys($parents);
}


// Map parent -> children names.
$parent_children = [];

// Initialize parent_children array for all parents to ensure we have entries
if (!empty($parentids)) {
    foreach ($parentids as $pid) {
        $parent_children[(int)$pid] = [];
    }
}
if (!empty($parentids) && $parent_role && $DB->get_manager()->table_exists('company_users')) {
    // Build the query to fetch ALL children for ALL parents
    // In role_assignments: userid = parent ID, contextid points to student's user context
    list($insql, $params) = $DB->get_in_or_equal($parentids, SQL_PARAMS_NAMED);
    $params['ctxlevel'] = $contextuserlevel;
    $params['roleid'] = $parent_role->id;
    $params['companyid'] = $companyid;
    
    // Fetch all children for all parents in one query
    // Only include students that belong to the same company
    // IMPORTANT: In role_assignments, userid = parent ID, contextid points to student's user context
    // When contextlevel = CONTEXT_USER, ctx.instanceid = student's user ID
    // Remove DISTINCT to ensure we get all records, we'll handle duplicates in PHP
    $sql = "SELECT ra.userid AS parentid,
                   ctx.instanceid AS childid,
                   child.id AS child_user_id,
                   child.firstname,
                   child.lastname
              FROM {role_assignments} ra
              INNER JOIN {context} ctx ON ctx.id = ra.contextid 
              INNER JOIN {user} child ON child.id = ctx.instanceid
              INNER JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = :companyid
             WHERE ra.userid $insql
               AND ctx.contextlevel = :ctxlevel
               AND ra.roleid = :roleid
               AND child.deleted = 0
          ORDER BY ra.userid, child.firstname, child.lastname";
    
    try {
        $children_records = $DB->get_records_sql($sql, $params);
        
        // Debug: Log how many records were found
        if (function_exists('error_log')) {
            error_log("Parent Management: Found " . count($children_records) . " child records for " . count($parentids) . " parents");
            error_log("Parent Management: Parent IDs: " . implode(', ', $parentids));
        }
    } catch (Exception $e) {
        debugging("Error fetching children: " . $e->getMessage(), DEBUG_DEVELOPER);
        $children_records = [];
    }

    // Group children by parent ID - use child ID to avoid duplicates
    foreach ($children_records as $record) {
        $parentid = (int)$record->parentid;
        // Use childid from context.instanceid (this is the student's user ID)
        // Fallback to child_user_id if childid is empty
        $childid = (int)(!empty($record->childid) ? $record->childid : $record->child_user_id);
        
        if ($parentid > 0 && $childid > 0) {
            // Ensure parent entry exists
            if (!isset($parent_children[$parentid])) {
                $parent_children[$parentid] = [];
            }
            
            // Use child ID as key to avoid duplicates, store full name as value
            // This ensures each student only appears once per parent
            if (!isset($parent_children[$parentid][$childid])) {
                $parent_children[$parentid][$childid] = fullname($record);
            }
        }
    }
    
    // Convert nested arrays to simple arrays of names
    foreach ($parent_children as $parentid => $children_data) {
        $parent_children[$parentid] = array_values($children_data);
    }
    
    // Debug: Log final counts per parent
    if (function_exists('error_log')) {
        foreach ($parent_children as $pid => $kids) {
            error_log("Parent Management: Parent ID $pid has " . count($kids) . " children: " . implode(', ', $kids));
        }
    }
}

// Attach assigned counts and normalise array order.
// IMPORTANT: Do this BEFORE converting to array_values to preserve ID keys
foreach ($parents as $id => $parent) {
    $parent_id_int = (int)$id;
    $child_count = 0;
    $children_array = [];
    
    // Get children for this parent
    if (isset($parent_children[$parent_id_int]) && is_array($parent_children[$parent_id_int])) {
        $children_array = $parent_children[$parent_id_int];
        $child_count = count($children_array);
    }
    
    // Store on parent object - this will persist even after array_values()
    $parents[$id]->assigned_children = $child_count;
    $parents[$id]->children_list = $children_array;
    
    // Debug: Log for specific parent if needed
    if (function_exists('error_log') && $child_count > 0) {
        error_log("Parent Management: Attached to parent ID $parent_id_int: count=$child_count, children=" . implode(', ', $children_array));
    }
}

// Now convert to indexed array for sorting
$parents = array_values($parents);
usort($parents, function($a, $b) {
    return strcasecmp($a->firstname . ' ' . $a->lastname, $b->firstname . ' ' . $b->lastname);
});

// Summaries.
$total_parents = count($parents);
$assigned_parents = 0;

foreach ($parents as $parent) {
    if (!empty($parent->assigned_children)) {
        $assigned_parents++;
    }
}
$unassigned_parents = max(0, $total_parents - $assigned_parents);

// Handle individual actions (delete, suspend, activate)
if (isset($_GET['action']) && isset($_GET['parent_id'])) {
    $action = $_GET['action'];
    $parent_id = (int)$_GET['parent_id'];
    
    // Verify the parent belongs to this company
    if ($company_info) {
        $parent_in_company = false;
        
        // Check if parent is in company_users
        $parent_in_company = $DB->record_exists_sql(
            "SELECT 1 FROM {company_users} cu 
             WHERE cu.userid = ? AND cu.companyid = ?",
            [$parent_id, $company_info->id]
        );
        
        // Also check if parent is linked via children in company
        if (!$parent_in_company) {
            $parent_in_company = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                 JOIN {user} child ON child.id = ctx.instanceid
                 JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = :companyid
                 WHERE ra.userid = :parentid AND ra.roleid = :roleid",
                [
                    'ctxlevel' => CONTEXT_USER,
                    'companyid' => $company_info->id,
                    'parentid' => $parent_id,
                    'roleid' => $parent_role->id
                ]
            );
        }
        
        if ($parent_in_company && confirm_sesskey()) {
            $user = $DB->get_record('user', ['id' => $parent_id]);
            if ($user) {
                $fullname = fullname($user);
                
                switch ($action) {
                    case 'permanent_delete':
                        // Permanent delete - remove from database
                        require_once($CFG->dirroot . '/user/lib.php');
                        
                        if (user_delete_user($user)) {
                            $message = "Parent '" . $fullname . "' has been permanently deleted.";
                            $type = \core\output\notification::NOTIFY_SUCCESS;
                        } else {
                            $message = "Error deleting parent '" . $fullname . "'.";
                            $type = \core\output\notification::NOTIFY_ERROR;
                        }
                        break;
                    
                    case 'suspend':
                        // Suspend the parent
                        $user->suspended = 1;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Parent '" . $fullname . "' has been suspended.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                        break;
                    
                    case 'activate':
                        // Activate the parent
                        $user->suspended = 0;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Parent '" . $fullname . "' has been activated.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                        break;
                    
                    default:
                        $message = "Invalid action.";
                        $type = \core\output\notification::NOTIFY_ERROR;
                        break;
                }
                
                redirect(new moodle_url('/theme/remui_kids/school_manager/parent_management.php'), $message, null, $type);
            }
        } else {
            redirect(new moodle_url('/theme/remui_kids/school_manager/parent_management.php'), 'Parent not found or you do not have permission.', null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

// Sidebar context.
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname,
    ],
    'config' => [
        'wwwroot' => $CFG->wwwroot,
    ],
    'parent_management_active' => true,
    'certificates_active' => false,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/parent_management.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Parent Management');
$PAGE->set_heading('Parent Management');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);

?>

<style>
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

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
    background: #ffffff;
    border-radius: 12px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    margin-top: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.page-header-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    line-height: 1.2;
    font-family: 'Inter', 'Segoe UI', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

/* Back Button */
.back-button {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border: none;
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 
        0 2px 8px rgba(59, 130, 246, 0.2),
        0 1px 4px rgba(37, 99, 235, 0.15);
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.back-button:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 
        0 4px 12px rgba(59, 130, 246, 0.3),
        0 2px 6px rgba(37, 99, 235, 0.2);
    color: #ffffff;
    text-decoration: none;
}

/* Stats Section */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 0.75rem;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 110px;
    border-top: 4px solid transparent;
    text-align: center;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.stat-card.total-parents-card {
    border-top-color: #8b5cf6;
    background: linear-gradient(180deg, rgba(139, 92, 246, 0.08), #ffffff);
}

.stat-card.assigned-parents-card {
    border-top-color: #10b981;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.08), #ffffff);
}

.stat-card.unassigned-parents-card {
    border-top-color: #f97316;
    background: linear-gradient(180deg, rgba(249, 115, 22, 0.08), #ffffff);
}

.stat-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.stat-icon-circle {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    font-size: 1.2rem;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25);
}

.stat-icon-circle.total {
    background: rgba(139, 92, 246, 0.15);
    color: #6d28d9;
}

.stat-icon-circle.assigned {
    background: rgba(16, 185, 129, 0.18);
    color: #047857;
}

.stat-icon-circle.unassigned {
    background: rgba(249, 115, 22, 0.18);
    color: #c2410c;
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.stat-trend {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    justify-content: center;
}

.trend-arrow {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.trend-text {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.stat-description {
    margin-top: 0.5rem;
    font-size: 0.85rem;
    color: #6b7280;
    text-align: center;
}

/* Search Section */
.search-section {
    background: #ffffff;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.search-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
}

.add-student-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.add-student-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
    color: white;
    text-decoration: none;
}

.search-container {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.search-input-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}

.search-field-select,
.search-input {
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: white;
    font-size: 0.9rem;
    color: #4a5568;
    transition: all 0.3s ease;
}

.search-field-select {
    min-width: 150px;
}

.search-field-select:focus,
.search-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.clear-search-btn {
    background: #e2e8f0;
    color: #4a5568;
    border: none;
    padding: 12px 15px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.clear-search-btn:hover {
    background: #cbd5e0;
    color: #2d3748;
    text-decoration: none;
}

/* Table Styles Shared */
.students-table,
.parent-table {
    width: 100%;
    border-collapse: collapse;
}

.students-table th,
.parent-table th {
    background: #f8f9fa;
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
}

.students-table td,
.parent-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

.parent-table-card {
    background: white;
    border-radius: 18px;
    box-shadow: 0 15px 35px rgba(15, 23, 42, 0.07);
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.table-header {
    padding: 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
}

.table-header-left {
    display: flex;
    align-items: center;
}

.table-header-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
}

.table-header-center {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
    min-width: 0;
    margin: 0 20px;
}

.buttons-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.table-header h2 {
    margin: 0;
    font-size: 1.4rem;
    color: #0f172a;
}

.bulk-parent-btn,
.add-parent-btn {
    color: white;
    text-decoration: none;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}

.bulk-parent-btn {
    background: linear-gradient(135deg, #0ea5e9, #2563eb);
    box-shadow: 0 8px 20px rgba(14, 165, 233, 0.25);
}

.bulk-parent-btn i {
    font-size: 0.85rem;
}

.bulk-parent-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(14, 165, 233, 0.35);
    color: white;
}

.add-parent-btn {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.25);
}

.add-parent-btn i {
    font-size: 0.85rem;
}

.add-parent-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(99, 102, 241, 0.35);
    color: white;
}

.search-box {
    position: relative;
    width: 100%;
    max-width: 600px;
    min-width: 400px;
}

.search-box input {
    width: 100%;
    padding: 12px 16px 12px 40px;
    border-radius: 10px;
    border: 1px solid #cbd5f5;
    font-size: 0.95rem;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.parent-row:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.parent-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.parent-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4f46e5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-assigned {
    background: #dcfce7;
    color: #166534;
}

.status-unassigned {
    background: #fee2e2;
    color: #b91c1c;
}

/* Ensure assigned students column can wrap properly and display multiple chips */
.parent-table td:nth-child(3) {
    max-width: 400px;
    word-wrap: break-word;
    vertical-align: top;
    padding-top: 15px;
}

.child-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    margin: 2px 4px 2px 0;
    background: #e3f2fd;
    color: #1976d2;
    border: 1px solid #90caf9;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 500;
    white-space: nowrap;
}

.child-chip i {
    font-size: 0.75rem;
}

/* Total Students column styling */
.parent-table td:nth-child(4) {
    text-align: center;
    vertical-align: middle;
}

.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.action-btn {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.action-btn.btn-edit {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}

.action-btn.btn-edit:hover {
    background: #e5e7eb;
    color: #1f2937;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(55, 65, 81, 0.2);
    text-decoration: none;
}

.action-btn.btn-delete {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.action-btn.btn-delete:hover {
    background: #fecaca;
    color: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
    text-decoration: none;
}

.action-btn.btn-suspend {
    background: #fef3c7;
    color: #f97316;
    border: 1px solid #fde68a;
}

.action-btn.btn-suspend:hover {
    background: #fde68a;
    color: #ea580c;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(249, 115, 22, 0.2);
    text-decoration: none;
}

.action-btn.btn-activate {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.action-btn.btn-activate:hover {
    background: #a7f3d0;
    color: #047857;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(6, 95, 70, 0.2);
    text-decoration: none;
}

/* Pagination Styles */
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
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: center;
    order: 1;
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

.pagination-numbers {
    display: flex;
    gap: 5px;
}

.pagination-number {
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
}

.pagination-number:hover {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-number.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

@media (max-width: 1024px) and (min-width: 769px) {
    .school-manager-main-content {
        left: 240px;
        width: calc(100vw - 240px);
    }
    
    .main-content {
        padding: 0 20px 30px 20px;
    }
    
    .search-box {
        max-width: 500px;
        min-width: 350px;
    }
    
    .table-header-center {
        margin: 0 15px;
    }
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
        padding-top: 80px;
    }
    
    .main-content {
        padding: 0 15px 30px 15px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.5rem 1.5rem;
        margin-top: 0;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .stat-card {
        padding: 1rem;
        min-height: 100px;
    }
    
    .parent-table-card,
    .search-section {
        padding: 15px;
    }
    
    .table-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .table-header-left,
    .table-header-center,
    .table-header-right {
        width: 100%;
        align-items: stretch;
    }
    
    .table-header-center {
        margin: 0;
        order: 2;
    }
    
    .table-header-left {
        order: 1;
    }
    
    .table-header-right {
        order: 3;
        align-items: stretch;
    }
    
    .buttons-group {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .table-header-right .bulk-parent-btn,
    .table-header-right .add-parent-btn {
        justify-content: center;
        flex: 1;
        min-width: 140px;
    }
    
    .search-box {
        width: 100%;
        max-width: none;
        min-width: 0;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">Parent Management</h1>
                <p class="page-subtitle">Monitor guardians linked to <?php echo format_string($company_info->name); ?></p>
            </div>
            <a class="back-button" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" target="_blank">
                <i class="fa fa-external-link-alt"></i> Open Parent Dashboard
            </a>
        </div>

        <div class="stats-section">
            <div class="stat-card total-parents-card">
                <div class="stat-content">
                    <div class="stat-icon-circle total"><i class="fa fa-users"></i></div>
                    <h3 class="stat-number"><?php echo number_format($total_parents); ?></h3>
                    <p class="stat-label">Total Parents</p>
                    <p class="stat-description">Registered guardians in your school</p>
                </div>
            </div>
            <div class="stat-card assigned-parents-card">
                <div class="stat-content">
                    <div class="stat-icon-circle assigned"><i class="fa fa-user-check"></i></div>
                    <h3 class="stat-number"><?php echo number_format($assigned_parents); ?></h3>
                    <p class="stat-label">Assigned Parents</p>
                    <p class="stat-description">Parents linked to at least one student</p>
                </div>
            </div>
            <div class="stat-card unassigned-parents-card">
                <div class="stat-content">
                    <div class="stat-icon-circle unassigned"><i class="fa fa-user-clock"></i></div>
                    <h3 class="stat-number"><?php echo number_format($unassigned_parents); ?></h3>
                    <p class="stat-label">Unassigned Parents</p>
                    <p class="stat-description">Parents awaiting child assignment</p>
                </div>
            </div>
        </div>

        <div class="parent-table-card students-container">
            <div class="table-header">
                <div class="table-header-left">
                <h2>Parent Directory</h2>
                </div>
                <div class="table-header-center">
                    <div class="search-box">
                        <i class="fa fa-search"></i>
                        <input type="text" id="parentSearchInput" placeholder="Search by name, email, or child">
                    </div>
                </div>
                <div class="table-header-right">
                    <div class="buttons-group">
                        <a class="bulk-parent-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/bulk_add_parents.php">
                            <i class="fa fa-layer-group"></i> Add Bulk Parents
                        </a>
                        <a class="add-parent-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/add_parent.php">
                            <i class="fa fa-plus"></i> Add Parent
                        </a>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="parent-table" id="parentTable">
                    <thead>
                        <tr>
                            <th>Parent</th>
                            <th>Email</th>
                            <th>Assigned Students</th>
                            <th>Total Students</th>
                            <th>Status</th>
                            <th>Last Access</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($parents)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:50px;">
                                    <i class="fa fa-users" style="font-size:2.5rem; color:#cbd5f5;"></i>
                                    <p style="margin:15px 0 0; color:#64748b;">No parents found for this school.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parents as $parent): ?>
                                <?php
                                    $fullname = fullname($parent);
                                    $initials = strtoupper(substr($parent->firstname, 0, 1) . substr($parent->lastname, 0, 1));
                                    $parent_id = (int)$parent->id;
                                    
                                    // Get children - prioritize children_list from parent object
                                    // This was set before array_values() so it should be preserved
                                    $children = [];
                                    if (isset($parent->children_list) && is_array($parent->children_list) && !empty($parent->children_list)) {
                                        $children = $parent->children_list;
                                    } elseif (isset($parent_children[$parent_id]) && is_array($parent_children[$parent_id])) {
                                        $children = $parent_children[$parent_id];
                                    }
                                    
                                    // Get count - use children_list count if available, otherwise use assigned_children
                                    $student_count = count($children);
                                    if ($student_count == 0 && isset($parent->assigned_children)) {
                                        $student_count = (int)$parent->assigned_children;
                                    }
                                    
                                    $lastaccess = $parent->lastaccess ? userdate($parent->lastaccess, get_string('strftimedatefullshort')) : get_string('never');
                                    $status_class = $student_count > 0 ? 'status-assigned' : 'status-unassigned';
                                    $status_text = $student_count > 0 ? 'Assigned' : 'Not Assigned';
                                ?>
                                <tr class="parent-row" data-search="<?php echo strtolower($fullname . ' ' . $parent->email . ' ' . implode(' ', $children)); ?>">
                                    <td>
                                        <div class="parent-info">
                                            <div class="parent-avatar"><?php echo $initials; ?></div>
                                            <div class="parent-meta">
                                                <span class="name"><?php echo format_string($fullname); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo format_string($parent->email); ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($children) && is_array($children)): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                                <?php foreach ($children as $childname): ?>
                                                    <span class="child-chip"><i class="fa fa-child" style="margin-right:6px;"></i><?php echo format_string($childname); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#cbd5f5;">No students linked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <span style="display: inline-block; padding: 6px 12px; background: #e3f2fd; color: #1976d2; border-radius: 12px; font-weight: 600; font-size: 0.9rem;">
                                                <?php echo $student_count; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    <td><?php echo format_string($lastaccess); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/edit_parent.php?id=<?php echo $parent->id; ?>" class="action-btn btn-edit" title="Edit Parent">
                                                <i class="fa fa-edit"></i> Edit
                                            </a>
                                            <?php
                                            $delete_confirm = "⚠️ WARNING: This will permanently delete the parent and all their data from the database.\n\nThis action cannot be undone!\n\nAre you absolutely sure you want to delete " . htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') . "?";
                                            $activate_confirm = "Are you sure you want to activate " . htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') . "?";
                                            $suspend_confirm = "Are you sure you want to suspend " . htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') . "?";
                                            ?>
                                            <a href="#" class="action-btn btn-delete delete-parent-btn" title="Delete Parent Permanently" data-parent-id="<?php echo $parent->id; ?>" data-parent-name="<?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>" data-sesskey="<?php echo sesskey(); ?>">
                                                <i class="fa fa-trash"></i> Delete
                                            </a>
                                            <?php if (!empty($parent->suspended)): ?>
                                                <a href="?action=activate&parent_id=<?php echo $parent->id; ?>&sesskey=<?php echo sesskey(); ?>" class="action-btn btn-activate" title="Activate Parent" onclick="return confirm('<?php echo addslashes($activate_confirm); ?>')">
                                                    <i class="fa fa-check"></i> Activate
                                                </a>
                                            <?php else: ?>
                                                <a href="?action=suspend&parent_id=<?php echo $parent->id; ?>&sesskey=<?php echo sesskey(); ?>" class="action-btn btn-suspend" title="Suspend Parent" onclick="return confirm('<?php echo addslashes($suspend_confirm); ?>')">
                                                    <i class="fa fa-ban"></i> Suspend
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
            
        <!-- Pagination - OUTSIDE the container -->
            <?php
            $total_parents_count = count($parents);
            $per_page = 10;
            $total_pages = ceil($total_parents_count / $per_page);
            ?>
            <div class='pagination-container' id='pagination-container' style='display: <?php echo ($total_pages > 1 ? 'flex' : 'none'); ?>;'>
                <div class='pagination-info'>Showing <span id='current_start'>1</span>-<span id='current_end'><?php echo min($per_page, $total_parents_count); ?></span> of <span id='total_count'><?php echo $total_parents_count; ?></span> parents</div>
                <div class='pagination-controls' id='pagination_controls'>
                    <button class='pagination-btn' id='prev_page' onclick='changePage(-1)' disabled>
                        <i class='fa fa-chevron-left'></i> Previous
                    </button>
                    <div class='pagination-numbers' id='pagination_numbers'></div>
                    <button class='pagination-btn' id='next_page' onclick='changePage(1)'>
                        Next <i class='fa fa-chevron-right'></i>
                    </button>
            </div>
        </div>
    </div>
</div>

<script>
// Pagination variables
window.currentPage = 1;
window.perPage = 10;
window.totalPages = 1;
window.allRows = [];

// Initialize pagination
function initializePagination() {
    const parentTable = document.getElementById('parentTable');
    if (!parentTable) {
        console.log('Parent table not found');
        return;
    }
    
    const tbody = parentTable.querySelector('tbody');
    if (!tbody) {
        console.log('Table body not found');
        return;
    }
    
    window.allRows = Array.from(tbody.querySelectorAll('tr.parent-row'));
    window.totalPages = Math.ceil(window.allRows.length / window.perPage);
    
    console.log('Pagination initialized:', {
        totalRows: window.allRows.length,
        perPage: window.perPage,
        totalPages: window.totalPages
    });
    
    // Restore pagination button visibility
    const prevBtn = document.getElementById('prev_page');
    const nextBtn = document.getElementById('next_page');
    const paginationNumbers = document.getElementById('pagination_numbers');
    
    if (window.totalPages > 1) {
        // Hide rows beyond first page
        window.allRows.forEach((row, index) => {
            if (index >= window.perPage) {
                row.style.display = 'none';
            }
        });
        
        // Show pagination controls
        if (prevBtn) prevBtn.style.display = 'flex';
        if (nextBtn) nextBtn.style.display = 'flex';
        if (paginationNumbers) paginationNumbers.style.display = 'flex';
        
        // Update pagination display
        updatePaginationDisplay();
        renderPaginationNumbers();
    } else if (window.totalPages === 1) {
        // Update the end count for single page
        const endEl = document.getElementById('current_end');
        if (endEl) {
            endEl.textContent = window.allRows.length;
        }
        
        // Hide pagination controls for single page (but keep container visible)
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
        if (paginationNumbers) paginationNumbers.style.display = 'none';
        
        updatePaginationDisplay();
    }
}

function showPage(page) {
    if (page < 1 || page > window.totalPages) return;
    
    window.currentPage = page;
    
    // Show/hide rows based on current page
    window.allRows.forEach((row, index) => {
        const startIndex = (page - 1) * window.perPage;
        const endIndex = startIndex + window.perPage;
        
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update pagination display
    updatePaginationDisplay();
    renderPaginationNumbers();
    
    // Scroll to top of table smoothly
    const parentContainer = document.querySelector('.parent-table-card');
    if (parentContainer) {
        parentContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updatePaginationDisplay() {
    const startNum = ((window.currentPage - 1) * window.perPage) + 1;
    const endNum = Math.min(window.currentPage * window.perPage, window.allRows.length);
    const totalCount = window.allRows.length;
    
    const startEl = document.getElementById('current_start');
    const endEl = document.getElementById('current_end');
    const totalEl = document.getElementById('total_count');
    
    if (startEl) startEl.textContent = startNum;
    if (endEl) endEl.textContent = endNum;
    if (totalEl) totalEl.textContent = totalCount;
    
    // Update button states
    const prevBtn = document.getElementById('prev_page');
    const nextBtn = document.getElementById('next_page');
    
    if (prevBtn) prevBtn.disabled = (window.currentPage === 1);
    if (nextBtn) nextBtn.disabled = (window.currentPage === window.totalPages);
}

function renderPaginationNumbers() {
    const container = document.getElementById('pagination_numbers');
    if (!container) return;
    
    container.innerHTML = '';
    
    // Show max 5 page numbers at a time
    let startPage = Math.max(1, window.currentPage - 2);
    let endPage = Math.min(window.totalPages, startPage + 4);
    
    // Adjust if we're near the end
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }
    
    // Add first page if not visible
    if (startPage > 1) {
        addPageButton(1);
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
    }
    
    // Add page numbers
    for (let i = startPage; i <= endPage; i++) {
        addPageButton(i);
    }
    
    // Add last page if not visible
    if (endPage < window.totalPages) {
        if (endPage < window.totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
        addPageButton(window.totalPages);
    }
}

function addPageButton(pageNum) {
    const container = document.getElementById('pagination_numbers');
    const pageBtn = document.createElement('button');
    pageBtn.className = 'pagination-number' + (pageNum === window.currentPage ? ' active' : '');
    pageBtn.textContent = pageNum;
    pageBtn.onclick = () => showPage(pageNum);
    container.appendChild(pageBtn);
}

function changePage(direction) {
    const newPage = window.currentPage + direction;
    if (newPage >= 1 && newPage <= window.totalPages) {
        showPage(newPage);
    }
}

// Search functionality with pagination
document.getElementById('parentSearchInput').addEventListener('input', function() {
    const term = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('#parentTable tbody .parent-row');
    let visibleCount = 0;
    const visibleRows = [];
    
    tableRows.forEach(function(row) {
        if (row.dataset.search.includes(term)) {
            row.style.display = '';
            visibleRows.push(row);
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update pagination with filtered rows
    if (term !== '') {
        // Search is active - reinitialize pagination with filtered rows
        reinitializePaginationWithFilteredRows(visibleRows);
    } else {
        // No search - restore normal pagination
        tableRows.forEach(function(row) {
            row.style.display = '';
        });
        
        if (typeof initializePagination === 'function') {
            initializePagination();
        }
        
        const paginationContainer = document.querySelector('.pagination-container');
        if (paginationContainer) {
            paginationContainer.style.display = 'flex';
        }
    }
});

// Function to reinitialize pagination with filtered rows
function reinitializePaginationWithFilteredRows(visibleRows) {
    if (!visibleRows || visibleRows.length === 0) {
        // No visible rows - hide all and hide pagination
        const tableRows = document.querySelectorAll('#parentTable tbody .parent-row');
        tableRows.forEach(function(row) {
            row.style.display = 'none';
        });
        
        const paginationContainer = document.querySelector('.pagination-container');
        if (paginationContainer) {
            paginationContainer.style.display = 'none';
        }
        return;
    }
    
    // Update global pagination variables
    window.allRows = visibleRows;
    window.perPage = window.perPage || 10;
    window.totalPages = Math.ceil(visibleRows.length / window.perPage);
    window.currentPage = 1;
    
    // Hide all rows first
    const tableRows = document.querySelectorAll('#parentTable tbody .parent-row');
    tableRows.forEach(function(row) {
        row.style.display = 'none';
    });
    
    // Show only first page of visible rows
    visibleRows.forEach(function(row, index) {
        if (index < window.perPage) {
            row.style.display = '';
        }
    });
    
    // Update pagination display
    updatePaginationDisplay();
    renderPaginationNumbers();
    
    // Always show pagination controls when filters are active (to show count)
    const paginationContainer = document.querySelector('.pagination-container');
    if (paginationContainer) {
        paginationContainer.style.display = 'flex';
        
        // Hide pagination numbers if only one page
        const paginationNumbers = document.getElementById('pagination_numbers');
        const prevBtn = document.getElementById('prev_page');
        const nextBtn = document.getElementById('next_page');
        
        if (visibleRows.length <= window.perPage) {
            // Only one page - hide navigation buttons but keep info
            if (prevBtn) prevBtn.style.display = 'none';
            if (nextBtn) nextBtn.style.display = 'none';
            if (paginationNumbers) paginationNumbers.style.display = 'none';
        } else {
            // Multiple pages - show navigation
            if (prevBtn) prevBtn.style.display = 'flex';
            if (nextBtn) nextBtn.style.display = 'flex';
            if (paginationNumbers) paginationNumbers.style.display = 'flex';
        }
    }
}

// Initialize pagination on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing pagination on page load...');
    initializePagination();
    
    // Handle delete button clicks for modal
    const deleteButtons = document.querySelectorAll('.delete-parent-btn');
    deleteButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const parentId = this.getAttribute('data-parent-id');
            const parentName = this.getAttribute('data-parent-name');
            const sesskey = this.getAttribute('data-sesskey');
            openDeleteModal(parentId, parentName, sesskey);
        });
    });
    
    // Close modal when clicking overlay
    const modal = document.getElementById('deleteModal');
    if (modal) {
        const overlay = modal.querySelector('.delete-modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', closeDeleteModal);
        }
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDeleteModal();
        }
    });
});

// Delete Modal Functions
function openDeleteModal(parentId, parentName, sesskey) {
    const modal = document.getElementById('deleteModal');
    const parentNameElement = document.getElementById('deleteParentName');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    if (modal && parentNameElement && confirmBtn) {
        parentNameElement.textContent = parentName;
        confirmBtn.href = '?action=permanent_delete&parent_id=' + parentId + '&sesskey=' + sesskey;
        
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}
</script>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="delete-modal" style="display: none;">
    <div class="delete-modal-overlay"></div>
    <div class="delete-modal-content">
        <div class="delete-modal-header">
            <i class="fa fa-exclamation-triangle"></i>
            <h3>Confirm Deletion</h3>
        </div>
        <div class="delete-modal-body">
            <p class="delete-warning">⚠️ <strong>WARNING:</strong> This will permanently delete the parent and all their data from the database.</p>
            <p class="delete-message">This action cannot be undone!</p>
            <p class="delete-parent-name">Are you absolutely sure you want to delete <strong id="deleteParentName"></strong>?</p>
        </div>
        <div class="delete-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <a href="#" id="confirmDeleteBtn" class="btn-confirm-delete">Delete Permanently</a>
        </div>
    </div>
</div>

<style>
/* Delete Modal Styles */
.delete-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.delete-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
}

.delete-modal-content {
    position: relative;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    z-index: 10001;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.delete-modal-header {
    padding: 24px 24px 16px 24px;
    border-bottom: 2px solid #fee2e2;
    display: flex;
    align-items: center;
    gap: 12px;
}

.delete-modal-header i {
    font-size: 28px;
    color: #dc2626;
}

.delete-modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
}

.delete-modal-body {
    padding: 24px;
}

.delete-warning {
    color: #dc2626;
    font-size: 1rem;
    margin-bottom: 12px;
    line-height: 1.6;
}

.delete-message {
    color: #991b1b;
    font-weight: 600;
    margin-bottom: 16px;
    font-size: 0.95rem;
}

.delete-parent-name {
    color: #374151;
    font-size: 1rem;
    line-height: 1.6;
    margin: 0;
}

.delete-parent-name strong {
    color: #1f2937;
    font-weight: 700;
}

.delete-modal-actions {
    padding: 16px 24px 24px 24px;
    border-top: 2px solid #f3f4f6;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn-cancel {
    padding: 10px 20px;
    background: #e2e8f0;
    color: #4a5568;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-cancel:hover {
    background: #cbd5e0;
    transform: translateY(-1px);
}

.btn-confirm-delete {
    padding: 10px 20px;
    background: #dc2626;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-confirm-delete:hover {
    background: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
    color: white;
    text-decoration: none;
}
</style>

<?php
echo $OUTPUT->footer();
