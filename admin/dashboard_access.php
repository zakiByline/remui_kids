<?php
/**
 * Dashboard Access Management Page
 *
 * Admin page to view and manage dashboard access for School Admins, Teachers, Students, and Parents
 * organized by school.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->libdir . '/ddllib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/theme/remui_kids/admin/dashboard_access.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Dashboard Access Management');
$PAGE->set_heading('Dashboard Access Management');
$PAGE->set_pagelayout('admin');

// Handle AJAX requests for toggling Quick Navigation for entire school or all schools
if (isset($_POST['action']) && $_POST['action'] === 'toggle_school_quick_nav') {
    header('Content-Type: application/json');
    
    // Verify sesskey
    $sesskey = optional_param('sesskey', '', PARAM_RAW);
    if (!confirm_sesskey($sesskey)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid session key. Please refresh the page and try again.'
        ]);
        exit;
    }
    
    $schoolid = required_param('schoolid', PARAM_INT);
    $enabled = required_param('enabled', PARAM_INT);
    
    // Create table if it doesn't exist
    $dbman = $DB->get_manager();
    $table = new xmldb_table('theme_remui_school_settings');
    
    if (!$dbman->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('schoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('quick_navigation_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('schoolid', XMLDB_KEY_UNIQUE, ['schoolid']);
        $dbman->create_table($table);
    }
    
    // Handle "All Schools" selection (schoolid = -1)
    if ($schoolid == -1) {
        // Get all schools
        $all_schools = [];
        if ($DB->get_manager()->table_exists('company')) {
            $all_schools = $DB->get_records('company', null, '', 'id');
        }
        
        // Update or insert settings for each school
        $updated_count = 0;
        foreach ($all_schools as $school) {
            $existing = $DB->get_record('theme_remui_school_settings', ['schoolid' => $school->id]);
            
            if ($existing) {
                $existing->quick_navigation_enabled = $enabled;
                $existing->timemodified = time();
                $DB->update_record('theme_remui_school_settings', $existing);
            } else {
                $record = new stdClass();
                $record->schoolid = $school->id;
                $record->quick_navigation_enabled = $enabled;
                $record->timecreated = time();
                $record->timemodified = time();
                $DB->insert_record('theme_remui_school_settings', $record);
            }
            $updated_count++;
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $enabled ? 
                "Quick Navigation enabled for all teachers across {$updated_count} schools" : 
                "Quick Navigation disabled for all teachers across {$updated_count} schools"
        ]);
    } else {
        // Handle individual school selection
        $existing = $DB->get_record('theme_remui_school_settings', ['schoolid' => $schoolid]);
        
        if ($existing) {
            $existing->quick_navigation_enabled = $enabled;
            $existing->timemodified = time();
            $DB->update_record('theme_remui_school_settings', $existing);
        } else {
            $record = new stdClass();
            $record->schoolid = $schoolid;
            $record->quick_navigation_enabled = $enabled;
            $record->timecreated = time();
            $record->timemodified = time();
            $DB->insert_record('theme_remui_school_settings', $record);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $enabled ? 
                'Quick Navigation enabled for all teachers in this school' : 
                'Quick Navigation disabled for all teachers in this school'
        ]);
    }
    exit;
}

// Handle AJAX requests for toggling Role Switch Access for entire school or all schools
if (isset($_POST['action']) && $_POST['action'] === 'toggle_school_role_switch') {
    header('Content-Type: application/json');
    
    // Verify sesskey
    $sesskey = optional_param('sesskey', '', PARAM_RAW);
    if (!confirm_sesskey($sesskey)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid session key. Please refresh the page and try again.'
        ]);
        exit;
    }
    
    $schoolid = required_param('schoolid', PARAM_INT);
    $enabled = required_param('enabled', PARAM_INT);
    
    // Create table if it doesn't exist and add role_switch_enabled field
    $dbman = $DB->get_manager();
    $table = new xmldb_table('theme_remui_school_settings');
    
    if (!$dbman->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('schoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('quick_navigation_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('role_switch_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('schoolid', XMLDB_KEY_UNIQUE, ['schoolid']);
        $dbman->create_table($table);
    } else {
        // Check if role_switch_enabled field exists, if not add it
        $field = new xmldb_field('role_switch_enabled');
        if (!$dbman->field_exists($table, $field)) {
            $field->set_attributes(XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $dbman->add_field($table, $field);
        }
    }
    
    // Handle "All Schools" selection (schoolid = -1)
    if ($schoolid == -1) {
        // Get all schools
        $all_schools = [];
        if ($DB->get_manager()->table_exists('company')) {
            $all_schools = $DB->get_records('company', null, '', 'id');
        }
        
        // Update or insert settings for each school
        $updated_count = 0;
        foreach ($all_schools as $school) {
            $existing = $DB->get_record('theme_remui_school_settings', ['schoolid' => $school->id]);
            
            if ($existing) {
                $existing->role_switch_enabled = $enabled;
                $existing->timemodified = time();
                $DB->update_record('theme_remui_school_settings', $existing);
            } else {
                $record = new stdClass();
                $record->schoolid = $school->id;
                $record->quick_navigation_enabled = 1;
                $record->role_switch_enabled = $enabled;
                $record->timecreated = time();
                $record->timemodified = time();
                $DB->insert_record('theme_remui_school_settings', $record);
            }
            $updated_count++;
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $enabled ? 
                "Role Switch Access enabled for all teachers across {$updated_count} schools" : 
                "Role Switch Access disabled for all teachers across {$updated_count} schools"
        ]);
    } else {
        // Handle individual school selection
        $existing = $DB->get_record('theme_remui_school_settings', ['schoolid' => $schoolid]);
        
        if ($existing) {
            $existing->role_switch_enabled = $enabled;
            $existing->timemodified = time();
            $DB->update_record('theme_remui_school_settings', $existing);
        } else {
            $record = new stdClass();
            $record->schoolid = $schoolid;
            $record->quick_navigation_enabled = 1;
            $record->role_switch_enabled = $enabled;
            $record->timecreated = time();
            $record->timemodified = time();
            $DB->insert_record('theme_remui_school_settings', $record);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $enabled ? 
                'Role Switch Access enabled for all teachers in this school' : 
                'Role Switch Access disabled for all teachers in this school'
        ]);
    }
    exit;
}

// Get selected school if any
$selected_school_id = optional_param('schoolid', 0, PARAM_INT);

// Get all schools
$schools = [];
if ($DB->get_manager()->table_exists('company')) {
    $schools = $DB->get_records('company', null, 'name ASC', 'id, name, shortname');
} else {
    // Fallback to course categories
    $schools = $DB->get_records('course_categories', ['parent' => 0], 'name ASC', 'id, name');
}

// Initialize data arrays
$school_admins = [];
$teachers_count = 0;
$students = [];
$parents = [];
$quick_nav_enabled = 1; // Default enabled
$role_switch_enabled = 1; // Default enabled

// If a school is selected, fetch the data
if ($selected_school_id > 0 || $selected_school_id == -1) {
    try {
        // Build WHERE clause based on selection
        $where_clause = ($selected_school_id == -1) ? "" : "WHERE cu.companyid = ?";
        $params_school = ($selected_school_id == -1) ? [] : [$selected_school_id];
        
        // Get School Admins
        $sql_school_admins = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.lastaccess,
                ra.roleid, r.name as rolename
         FROM {user} u
         JOIN {company_users} cu ON cu.userid = u.id
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {role} r ON r.id = ra.roleid
         " . ($selected_school_id == -1 ? "" : "WHERE cu.companyid = ?") . "
         " . ($selected_school_id == -1 ? "WHERE" : "AND") . " (r.shortname = 'manager' OR r.shortname = 'companymanager' OR cu.managertype > 0)
         AND u.deleted = 0
         ORDER BY u.firstname, u.lastname";
        
        $school_admins = $DB->get_records_sql($sql_school_admins, $params_school);

    // Get Teachers count
    $sql_teachers_count = "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         JOIN {company_users} cu ON cu.userid = u.id
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {role} r ON r.id = ra.roleid
         " . ($selected_school_id == -1 ? "" : "WHERE cu.companyid = ?") . "
         " . ($selected_school_id == -1 ? "WHERE" : "AND") . " (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
         AND u.deleted = 0";
    
    $teachers_count = $DB->count_records_sql($sql_teachers_count, $params_school);
    
    // Get Quick Navigation setting for this school
    $dbman = $DB->get_manager();
    $settings_table_exists = $dbman->table_exists(new xmldb_table('theme_remui_school_settings'));
    
    if ($settings_table_exists) {
        $school_setting = $DB->get_record('theme_remui_school_settings', ['schoolid' => $selected_school_id]);
        if ($school_setting) {
            $quick_nav_enabled = $school_setting->quick_navigation_enabled;
            // Check if role_switch_enabled field exists
            if (isset($school_setting->role_switch_enabled)) {
                $role_switch_enabled = $school_setting->role_switch_enabled;
            } else {
                // Field doesn't exist yet, add it with default value
                $dbman = $DB->get_manager();
                $table = new xmldb_table('theme_remui_school_settings');
                $field = new xmldb_field('role_switch_enabled');
                if (!$dbman->field_exists($table, $field)) {
                    $field->set_attributes(XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
                    $dbman->add_field($table, $field);
                }
                // Update existing record with default value
                $school_setting->role_switch_enabled = 1;
                $DB->update_record('theme_remui_school_settings', $school_setting);
                $role_switch_enabled = 1;
            }
        }
    }

        // Get Students
        $sql_students = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.lastaccess,
                (SELECT COUNT(*) 
                 FROM {user_enrolments} ue 
                 JOIN {enrol} e ON e.id = ue.enrolid 
                 WHERE ue.userid = u.id) as enrolled_courses
         FROM {user} u
         JOIN {company_users} cu ON cu.userid = u.id
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {role} r ON r.id = ra.roleid
         " . ($selected_school_id == -1 ? "" : "WHERE cu.companyid = ?") . "
         " . ($selected_school_id == -1 ? "WHERE" : "AND") . " r.shortname = 'student'
         AND u.deleted = 0
         ORDER BY u.firstname, u.lastname";
        
        $students = $DB->get_records_sql($sql_students, $params_school);

    // Get Parents (users with parent role)
    $sql_parents = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.lastaccess,
                (SELECT COUNT(DISTINCT rc.userid) 
                 FROM {role_assignments} rc 
                 WHERE rc.roleid = (SELECT id FROM {role} WHERE shortname = 'parent')
                 AND rc.contextid IN (
                     SELECT ctx.id FROM {context} ctx WHERE ctx.contextlevel = 30
                 )) as children_count
         FROM {user} u
         JOIN {company_users} cu ON cu.userid = u.id
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {role} r ON r.id = ra.roleid
         " . ($selected_school_id == -1 ? "" : "WHERE cu.companyid = ?") . "
         " . ($selected_school_id == -1 ? "WHERE" : "AND") . " r.shortname = 'parent'
         AND u.deleted = 0
         ORDER BY u.firstname, u.lastname";
        
        $parents = $DB->get_records_sql($sql_parents, $params_school);
    } catch (Exception $e) {
        // Log error and continue with empty arrays
        error_log('Dashboard Access Error: ' . $e->getMessage());
        \core\notification::error('Error loading data: ' . $e->getMessage());
    }
}

echo $OUTPUT->header();
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #f7f8fb;
        min-height: 100vh;
        overflow-x: hidden;
        color: #0f172a;
    }
    
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
    
    .admin-sidebar .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .admin-sidebar .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .admin-sidebar .sidebar-item {
        margin-bottom: 0.25rem;
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
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    /* Scrollbar styling */
    .admin-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .admin-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #f7f8fb;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding: 20px;
    }
    
    /* Dashboard Access Container */
    .dashboard-container {
        background: #ffffff;
        border-radius: 18px;
        box-shadow: 0 25px 50px rgba(15, 23, 42, 0.08);
        padding: 32px;
        margin: 24px 0 48px;
    }

    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 36px;
        padding: 28px 32px;
        border-radius: 18px;
        background: linear-gradient(135deg, #e0f7ff 0%, #f3fff8 100%);
        border: 1px solid #e2e8f0;
    }

    .dashboard-title {
        font-size: 30px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .dashboard-subtitle {
        color: #475569;
        margin: 8px 0 0 0;
        font-size: 16px;
    }

    /* School Selector Section */
    .school-selector-section {
        background: #f1f5f9;
        padding: 18px 22px;
        border-radius: 14px;
        margin-bottom: 24px;
        border: 1px solid #e2e8f0;
    }

    .school-selector-label {
        font-size: 14px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .school-selector-label i {
        color: #667eea;
        font-size: 16px;
    }

    .school-dropdown {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        font-size: 14px;
        background: #fff;
        color: #0f172a;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .school-dropdown:hover {
        border-color: #667eea;
    }

    .school-dropdown:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        padding: 28px 24px;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: 1px solid;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.15);
    }

    .stat-card h4 {
        margin: 0 0 12px 0;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-card .stat-value {
        font-size: 36px;
        font-weight: 700;
        margin: 0;
    }

    /* Individual Card Colors */
    .stat-card:nth-child(1) {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        border-color: #7dd3fc;
    }

    .stat-card:nth-child(1) h4 {
        color: #0369a1;
    }

    .stat-card:nth-child(1) .stat-value {
        color: #075985;
    }

    .stat-card:nth-child(2) {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border-color: #fcd34d;
    }

    .stat-card:nth-child(2) h4 {
        color: #d97706;
    }

    .stat-card:nth-child(2) .stat-value {
        color: #b45309;
    }

    .stat-card:nth-child(3) {
        background: linear-gradient(135deg, #ddd6fe 0%, #c4b5fd 100%);
        border-color: #a78bfa;
    }

    .stat-card:nth-child(3) h4 {
        color: #7c3aed;
    }

    .stat-card:nth-child(3) .stat-value {
        color: #6d28d9;
    }

    .stat-card:nth-child(4) {
        background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%);
        border-color: #f9a8d4;
    }

    .stat-card:nth-child(4) h4 {
        color: #db2777;
    }

    .stat-card:nth-child(4) .stat-value {
        color: #be185d;
    }

    /* Tabs Navigation */
    .tabs-container {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }

    .tabs-nav {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        background: #f8fafc;
        border-bottom: 2px solid #e5e7eb;
        padding: 0;
        margin: 0;
        list-style: none;
    }

    .tab-button {
        padding: 20px 24px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-size: 15px;
        font-weight: 600;
        color: #64748b;
        transition: all 0.3s ease;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        border-right: 1px solid #e5e7eb;
    }

    .tab-button:last-child {
        border-right: none;
    }

    .tab-button:hover {
        background: #e0e7ff;
        color: #667eea;
    }

    .tab-button.active {
        background: white;
        color: #667eea;
    }

    .tab-button.active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        right: 0;
        height: 3px;
        background: #667eea;
    }

    .tab-button i {
        font-size: 18px;
    }

    .tab-badge {
        background: #667eea;
        color: white;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        min-width: 28px;
        text-align: center;
    }

    .tab-button.active .tab-badge {
        background: #764ba2;
    }

    /* Tab Content */
    .tab-content {
        display: none;
        padding: 32px;
        animation: fadeIn 0.3s ease;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #64748b;
    }

    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 20px;
    }

    .empty-state h3 {
        font-size: 24px;
        color: #475569;
        margin-bottom: 8px;
        font-weight: 600;
    }

    .empty-state p {
        font-size: 15px;
        color: #94a3b8;
    }

    /* Users Table */
    .users-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 12px;
        margin-top: 12px;
    }

    .users-table thead {
        background: #eef4ff;
        border-radius: 12px;
    }

    .users-table th {
        font-size: 12px;
        font-weight: 700;
        color: #1d4ed8;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 12px 16px;
        text-align: left;
        border: none;
    }

    .users-table tbody tr {
        background: #fdfdfd;
        box-shadow: 0 15px 35px rgba(15, 23, 42, 0.06);
        border-radius: 16px;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .users-table tbody tr:hover {
        transform: translateY(-4px);
        background: #ffffff;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
    }

    .users-table tbody tr td:first-child {
        border-top-left-radius: 16px;
        border-bottom-left-radius: 16px;
    }

    .users-table tbody tr td:last-child {
        border-top-right-radius: 16px;
        border-bottom-right-radius: 16px;
    }

    .users-table td {
        padding: 18px 16px;
        border-bottom: none;
        vertical-align: middle;
        color: #0f172a;
    }

    .user-name {
        font-weight: 600;
        color: #0f172a;
        font-size: 15px;
    }

    .user-email {
        color: #94a3b8;
        font-size: 13px;
    }

    .status-badge {
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        display: inline-block;
    }

    .status-active {
        background: #dcfce7;
        color: #15803d;
    }

    .status-inactive {
        background: #fee2e2;
        color: #b91c1c;
    }

    .action-button {
        padding: 8px 14px;
        background: #eaf2ff;
        color: #3b82f6;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-right: 6px;
    }

    .action-button:hover {
        transform: translateY(-1px);
        background: #3b82f6;
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .action-button i {
        font-size: 12px;
    }

    /* Feature Control Card - Modern Style */
    .feature-control-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 20px;
        padding: 32px 28px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        transition: all 0.3s ease;
        max-width: 420px;
        margin: 0 auto;
        position: relative;
    }

    .feature-control-card:hover {
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .feature-card-header {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        width: 100%;
    }

    .feature-icon {
        width: 64px;
        height: 64px;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .feature-icon i {
        font-size: 32px;
        color: white;
    }

    .feature-info {
        width: 100%;
        flex: 1;
    }

    .feature-title {
        font-size: 22px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 8px 0;
    }

    .feature-description {
        font-size: 14px;
        color: #6b7280;
        line-height: 1.6;
        margin: 0;
    }

    .feature-description strong {
        color: #1f2937;
        font-weight: 600;
    }

    .feature-card-action {
        display: flex;
        flex-direction: column;
        gap: 16px;
        width: 100%;
    }

    /* Toggle Row */
    .toggle-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 12px 16px;
        background: #f9fafb;
        border-radius: 12px;
    }

    .toggle-label-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .status-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #ef4444;
    }

    .status-indicator.active {
        background: #10b981;
    }

    .status-text {
        font-size: 15px;
        font-weight: 600;
        color: #1f2937;
    }

    .status-subtext {
        font-size: 13px;
        color: #9ca3af;
        margin-left: 4px;
    }

    /* Large Toggle Switch */
    .toggle-switch-large {
        position: relative;
        display: inline-block;
        width: 56px;
        height: 28px;
    }

    .toggle-switch-large input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider-large {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #e5e7eb;
        transition: .3s;
        border-radius: 34px;
    }

    .toggle-slider-large:before {
        position: absolute;
        content: "";
        height: 22px;
        width: 22px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .3s;
        border-radius: 50%;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .toggle-switch-large input:checked + .toggle-slider-large {
        background-color: #10b981;
    }

    .toggle-switch-large input:checked + .toggle-slider-large:before {
        transform: translateX(28px);
    }

    .toggle-switch-large input:disabled + .toggle-slider-large {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Launch Button */
    .launch-button {
        width: 100%;
        padding: 14px 24px;
        background: #10b981;
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .launch-button:hover {
        background: #059669;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .launch-button i {
        font-size: 16px;
    }

    /* Info Card */
    .info-card {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border: 2px solid #e5e7eb;
        border-radius: 16px;
        padding: 40px;
        display: flex;
        align-items: flex-start;
        gap: 24px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .info-icon {
        width: 72px;
        height: 72px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .info-icon i {
        font-size: 32px;
        color: white;
    }

    .info-content {
        flex: 1;
    }

    .info-title {
        font-size: 24px;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 12px 0;
    }

    .info-description {
        font-size: 16px;
        color: #475569;
        line-height: 1.6;
        margin: 0 0 16px 0;
    }

    .info-description strong {
        color: #667eea;
        font-weight: 600;
    }

    .info-note {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 16px;
        background: #f1f5f9;
        border-radius: 8px;
        font-size: 14px;
        color: #64748b;
        margin: 0;
    }

    .info-note i {
        color: #667eea;
        font-size: 16px;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
        }
        
        .admin-sidebar.sidebar-open {
            transform: translateX(0);
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding: 15px;
        }

        .dashboard-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 20px;
        }

        .stats-grid {
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tabs-nav {
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }

        .tab-button {
            padding: 16px 12px;
            font-size: 13px;
            border-right: none !important;
            border-bottom: 1px solid #e5e7eb;
        }

        .tab-button:nth-child(odd) {
            border-right: 1px solid #e5e7eb !important;
        }

        .tab-button:nth-child(3),
        .tab-button:nth-child(4) {
            border-bottom: none;
        }

        .dashboard-title {
            font-size: 24px;
        }

        .feature-control-card {
            padding: 24px 20px;
            max-width: 100%;
        }

        .feature-card-header {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .feature-icon {
            width: 56px;
            height: 56px;
        }

        .feature-icon i {
            font-size: 28px;
        }

        .feature-info {
            text-align: center;
        }

        .feature-title {
            font-size: 20px;
        }

        .feature-description {
            font-size: 14px;
        }

        .toggle-row {
            flex-direction: column;
            gap: 12px;
            padding: 16px;
        }

        .launch-button {
            font-size: 14px;
            padding: 12px 20px;
        }

        .info-card {
            flex-direction: column;
            padding: 32px 24px;
            text-align: center;
            align-items: center;
        }

        .info-title {
            font-size: 20px;
        }

        .info-description {
            font-size: 15px;
        }

        .info-note {
            flex-direction: column;
            text-align: center;
            gap: 8px;
        }
    }
</style>

<!-- Admin Sidebar -->
<?php include(__DIR__ . '/includes/admin_sidebar.php'); ?>

<!-- Main Content -->
<div class="admin-main-content">
    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="dashboard-header">
            <div>
                <h1 class="dashboard-title"><i class="fa fa-tachometer-alt"></i> Dashboard Access Management</h1>
                <p class="dashboard-subtitle">View and manage dashboard access for School Admins, Teachers, Students, and Parents by school</p>
            </div>
        </div>

        <!-- School Selector -->
        <div class="school-selector-section">
            <label class="school-selector-label">
                <i class="fa fa-school"></i>
                Select School
            </label>
            <form method="GET" action="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/dashboard_access.php" id="schoolForm">
                <select name="schoolid" class="school-dropdown" onchange="this.form.submit()">
                    <option value="0">-- Select a School --</option>
                    <option value="-1" <?php echo ($selected_school_id == -1) ? 'selected' : ''; ?>>All Schools</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?php echo $school->id; ?>" <?php echo ($selected_school_id == $school->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($school->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selected_school_id > 0 || $selected_school_id == -1): ?>
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>School Admins</h4>
                    <p class="stat-value"><?php echo count($school_admins); ?></p>
                </div>
                <div class="stat-card">
                    <h4>Teachers</h4>
                    <p class="stat-value"><?php echo $teachers_count; ?></p>
                </div>
                <div class="stat-card">
                    <h4>Students</h4>
                    <p class="stat-value"><?php echo count($students); ?></p>
                </div>
                <div class="stat-card">
                    <h4>Parents</h4>
                    <p class="stat-value"><?php echo count($parents); ?></p>
                </div>
            </div>

            <!-- Tabs Container -->
            <div class="tabs-container">
                <!-- Tabs Navigation -->
                <ul class="tabs-nav">
                    <li>
                        <button class="tab-button active" data-tab="school-admin">
                            <i class="fa fa-user-shield"></i>
                            <span>School Admin</span>
                            <span class="tab-badge"><?php echo count($school_admins); ?></span>
                        </button>
                    </li>
                    <li>
                        <button class="tab-button" data-tab="teacher">
                            <i class="fa fa-chalkboard-teacher"></i>
                            <span>Teacher</span>
                            <span class="tab-badge"><?php echo $teachers_count; ?></span>
                        </button>
                    </li>
                    <li>
                        <button class="tab-button" data-tab="student">
                            <i class="fa fa-user-graduate"></i>
                            <span>Student</span>
                            <span class="tab-badge"><?php echo count($students); ?></span>
                        </button>
                    </li>
                    <li>
                        <button class="tab-button" data-tab="parent">
                            <i class="fa fa-users"></i>
                            <span>Parent</span>
                            <span class="tab-badge"><?php echo count($parents); ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Tab Content: School Admin -->
                <div class="tab-content active" id="school-admin">
                    <?php if (count($school_admins) > 0): ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Last Access</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($school_admins as $admin): ?>
                                    <tr>
                                        <td class="user-name"><?php echo htmlspecialchars($admin->firstname . ' ' . $admin->lastname); ?></td>
                                        <td class="user-email"><?php echo htmlspecialchars($admin->email); ?></td>
                                        <td><?php echo htmlspecialchars($admin->username); ?></td>
                                        <td><?php echo htmlspecialchars($admin->rolename); ?></td>
                                        <td><?php echo $admin->lastaccess ? userdate($admin->lastaccess, '%d %b %Y') : 'Never'; ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $admin->lastaccess > (time() - 604800) ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $admin->lastaccess > (time() - 604800) ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="action-button" onclick="viewUser(<?php echo $admin->id; ?>)">
                                                <i class="fa fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-user-shield"></i>
                            <h3>No School Admins Found</h3>
                            <p>There are no school administrators assigned to this school yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab Content: Teacher -->
                <div class="tab-content" id="teacher">
                    <?php if ($teachers_count > 0): ?>
                        <!-- Show Control Card for both All Schools and Individual School -->
                        <div class="feature-control-card">
                            <div class="feature-card-header">
                                <div class="feature-icon">
                                    <i class="fa fa-compass"></i>
                                </div>
                                <div class="feature-info">
                                    <h3 class="feature-title">Teacher learning pathway</h3>
                                    <p class="feature-description">
                                        Block-based navigation feature for all <strong><?php echo $teachers_count; ?> teachers</strong>. When enabled, teachers see Planning, Resources, and Assessments cards.
                                    </p>
                                </div>
                            </div>
                            
                            <div class="feature-card-action">
                                <div class="toggle-row">
                                    <div class="toggle-label-group">
                                        <span class="status-indicator <?php echo $quick_nav_enabled ? 'active' : ''; ?>"></span>
                                        <span class="status-text">
                                            <?php echo $quick_nav_enabled ? 'Enabled' : 'Disabled'; ?>
                                            <span class="status-subtext">(Default)</span>
                                        </span>
                                    </div>
                                    <label class="toggle-switch-large">
                                        <input type="checkbox" 
                                               id="school-quick-nav-toggle" 
                                               data-school-id="<?php echo $selected_school_id; ?>"
                                               <?php echo $quick_nav_enabled ? 'checked' : ''; ?>>
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                
                                <button class="launch-button" onclick="viewTeachersList()">
                                    <i class="fa fa-external-link-alt"></i>
                                    View Teachers
                                </button>
                            </div>
                        </div>
                        
                        <!-- Role Switch Access Control Card -->
                        <div class="feature-control-card" style="margin-top: 20px;">
                            <div class="feature-card-header">
                                <div class="feature-icon">
                                    <i class="fa fa-user-graduate"></i>
                                </div>
                                <div class="feature-info">
                                    <h3 class="feature-title">Role Switch Access</h3>
                                    <p class="feature-description">
                                        Allow teachers to switch roles and view student dashboards. When enabled, teachers can access the "Switch Role" feature in their sidebar to preview student experiences.
                                    </p>
                                </div>
                            </div>
                            
                            <div class="feature-card-action">
                                <div class="toggle-row">
                                    <div class="toggle-label-group">
                                        <span class="status-indicator <?php echo $role_switch_enabled ? 'active' : ''; ?>"></span>
                                        <span class="status-text">
                                            <?php echo $role_switch_enabled ? 'Enabled' : 'Disabled'; ?>
                                            <span class="status-subtext">(Default)</span>
                                        </span>
                                    </div>
                                    <label class="toggle-switch-large">
                                        <input type="checkbox" 
                                               id="school-role-switch-toggle" 
                                               data-school-id="<?php echo $selected_school_id; ?>"
                                               <?php echo $role_switch_enabled ? 'checked' : ''; ?>>
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                
                                <button class="launch-button" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/local/teacherviewstudent/'">
                                    <i class="fa fa-external-link-alt"></i>
                                    View Switch Role Page
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-chalkboard-teacher"></i>
                            <h3>No Teachers Found</h3>
                            <p>There are no teachers assigned<?php echo $selected_school_id == -1 ? ' across all schools' : ' to this school'; ?> yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab Content: Student -->
                <div class="tab-content" id="student">
                    <!-- Reserved for future access control cards -->
                </div>

                <!-- Tab Content: Parent -->
                <div class="tab-content" id="parent">
                    <!-- Reserved for future access control cards -->
                </div>
            </div>
        <?php else: ?>
            <!-- Empty State for No School Selected -->
            <div class="tabs-container">
                <div class="empty-state" style="padding: 80px 40px;">
                    <i class="fa fa-school"></i>
                    <h3>Select a School</h3>
                    <p>Please select a school from the dropdown above to view dashboard access information.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons and contents
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                // Add active class to clicked button
                this.classList.add('active');

                // Show corresponding content
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // School-level Role Switch Access Toggle functionality
        const schoolRoleSwitchToggle = document.getElementById('school-role-switch-toggle');
        if (schoolRoleSwitchToggle) {
            schoolRoleSwitchToggle.addEventListener('change', function() {
                const schoolId = this.getAttribute('data-school-id');
                const enabled = this.checked ? 1 : 0;
                const toggleRow = this.closest('.toggle-row');
                const statusIndicator = toggleRow.querySelector('.status-indicator');
                const statusTextSpan = toggleRow.querySelector('.status-text');
                
                // Show loading state
                statusTextSpan.innerHTML = 'Updating... <span class="status-subtext">(Please wait)</span>';
                this.disabled = true;
                
                // Send AJAX request
                const formData = new FormData();
                formData.append('action', 'toggle_school_role_switch');
                formData.append('schoolid', schoolId);
                formData.append('enabled', enabled);
                
                // Get sesskey
                let sesskey = '';
                if (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
                    sesskey = M.cfg.sesskey;
                } else {
                    const sesskeyInput = document.querySelector('input[name="sesskey"]');
                    if (sesskeyInput) {
                        sesskey = sesskeyInput.value;
                    }
                }
                formData.append('sesskey', sesskey);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update status text
                        const newStatus = enabled ? 'Enabled' : 'Disabled';
                        statusTextSpan.innerHTML = newStatus + ' <span class="status-subtext">(Default)</span>';
                        
                        // Update status indicator color
                        if (enabled) {
                            statusIndicator.classList.add('active');
                        } else {
                            statusIndicator.classList.remove('active');
                        }
                        
                        // Show success notification
                        showNotification(data.message, 'success');
                    } else {
                        // Revert toggle on error
                        this.checked = !this.checked;
                        showNotification(data.message || 'An error occurred. Please try again.', 'error');
                    }
                })
                .catch(error => {
                    // Revert toggle on error
                    this.checked = !this.checked;
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(() => {
                    this.disabled = false;
                });
            });
        }

        // School-level Quick Navigation Toggle functionality
        const schoolQuickNavToggle = document.getElementById('school-quick-nav-toggle');
        if (schoolQuickNavToggle) {
            schoolQuickNavToggle.addEventListener('change', function() {
                const schoolId = this.getAttribute('data-school-id');
                const enabled = this.checked ? 1 : 0;
                const toggleRow = this.closest('.toggle-row');
                const statusIndicator = toggleRow.querySelector('.status-indicator');
                const statusTextSpan = toggleRow.querySelector('.status-text');
                
                // Get only the text node content (without the (Default) part)
                const originalText = enabled ? 'Disabled' : 'Enabled';
                
                // Show loading state
                statusTextSpan.innerHTML = 'Updating... <span class="status-subtext">(Please wait)</span>';
                this.disabled = true;
                
                // Send AJAX request
                const formData = new FormData();
                formData.append('action', 'toggle_school_quick_nav');
                formData.append('schoolid', schoolId);
                formData.append('enabled', enabled);
                
                // Get sesskey - try M.cfg.sesskey first, then fallback to page sesskey
                let sesskey = '';
                if (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
                    sesskey = M.cfg.sesskey;
                } else {
                    // Fallback: get from a hidden input or meta tag if available
                    const sesskeyInput = document.querySelector('input[name="sesskey"]');
                    if (sesskeyInput) {
                        sesskey = sesskeyInput.value;
                    }
                }
                formData.append('sesskey', sesskey);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update status text
                        const newStatus = enabled ? 'Enabled' : 'Disabled';
                        statusTextSpan.innerHTML = newStatus + ' <span class="status-subtext">(Default)</span>';
                        
                        // Update status indicator color
                        if (enabled) {
                            statusIndicator.classList.add('active');
                        } else {
                            statusIndicator.classList.remove('active');
                        }
                        
                        // Show success notification
                        showNotification(data.message, 'success');
                    } else {
                        // Revert toggle on error
                        this.checked = !this.checked;
                        statusTextSpan.innerHTML = originalText + ' <span class="status-subtext">(Default)</span>';
                        showNotification('Failed to update Quick Navigation', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Revert toggle on error
                    this.checked = !this.checked;
                    statusTextSpan.innerHTML = originalText + ' <span class="status-subtext">(Default)</span>';
                    showNotification('An error occurred: ' + error.message, 'error');
                })
                .finally(() => {
                    this.disabled = false;
                });
            });
        }
    });

    // View user function
    function viewUser(userId) {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/user/profile.php?id=' + userId;
    }

    // View teachers list function
    function viewTeachersList() {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/teachers_list.php';
    }

    // Show notification function
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            background: ${type === 'success' ? '#22c55e' : '#ef4444'};
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            font-weight: 600;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        `;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
</script>

<style>
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
</style>

<?php
echo $OUTPUT->footer();
?>
