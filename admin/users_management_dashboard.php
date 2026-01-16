<?php
/**
 * Users Management Dashboard
 * Comprehensive user administration and management tools
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_dashboard_stats':
            try {
                // Get total users
                $total_users = $DB->count_records('user', ['deleted' => 0]);
                
                // Get active users (logged in within last 30 days)
                $active_users = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) FROM {user} u 
                     JOIN {user_lastaccess} ul ON u.id = ul.userid 
                     WHERE u.deleted = 0 AND ul.timeaccess > ?",
                    [time() - (30 * 24 * 60 * 60)]
                );
                
                // Get pending approvals (mock data for now)
                $pending_approvals = 0;
                
                // Get department managers
                $managerrole = $DB->get_record('role', ['shortname' => 'manager']);
                $department_managers = 0;
                if ($managerrole) {
                    $department_managers = $DB->count_records_sql(
                        "SELECT COUNT(DISTINCT u.id) 
                         FROM {user} u 
                         JOIN {role_assignments} ra ON u.id = ra.userid 
                         JOIN {context} ctx ON ra.contextid = ctx.id 
                         WHERE ctx.contextlevel = ? AND ra.roleid = ? AND u.deleted = 0",
                        [CONTEXT_SYSTEM, $managerrole->id]
                    );
                }
                
                // Get recent uploads (users created this month)
                $recent_uploads = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {user} WHERE timecreated > ? AND deleted = 0",
                    [strtotime('first day of this month')]
                );
                
                // Get recent user activity
                $recent_activity = $DB->get_records_sql(
                    "SELECT u.id, u.firstname, u.lastname, u.timecreated,
                            CASE 
                                WHEN u.timecreated > ? THEN 'User created'
                                ELSE 'User updated'
                            END as activity_type
                     FROM {user} u 
                     WHERE u.deleted = 0 AND u.id > 1
                     ORDER BY u.timecreated DESC 
                     LIMIT 10",
                    [strtotime('-7 days')]
                );
                
                echo json_encode([
                    'status' => 'success',
                    'total_users' => $total_users,
                    'active_users' => $active_users,
                    'pending_approvals' => $pending_approvals,
                    'department_managers' => $department_managers,
                    'recent_uploads' => $recent_uploads,
                    'recent_activity' => array_values($recent_activity),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'create_user':
            // Handle user creation
            echo json_encode(['status' => 'success', 'message' => 'User creation functionality will be implemented']);
            exit;
            
            
        case 'export_users':
            // Handle user export
            echo json_encode(['status' => 'success', 'message' => 'Export functionality will be implemented']);
            exit;
            
        case 'approve_events':
            // Handle event approval
            echo json_encode(['status' => 'success', 'message' => 'Event approval functionality will be implemented']);
            exit;
    }
}

// Set page context
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/users_management_dashboard.php');
$PAGE->set_title('Users Management Dashboard');
$PAGE->set_heading('Users Management Dashboard');

// Get initial dashboard statistics
$total_users = $DB->count_records('user', ['deleted' => 0]);
$active_users = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id) FROM {user} u 
     JOIN {user_lastaccess} ul ON u.id = ul.userid 
     WHERE u.deleted = 0 AND ul.timeaccess > ?",
    [time() - (30 * 24 * 60 * 60)]
);
$pending_approvals = 0;
$department_managers = 0;
$recent_uploads = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} WHERE timecreated > ? AND deleted = 0",
    [strtotime('first day of this month')]
);

// Get recent activity
$recent_activity = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.timecreated,
            CASE 
                WHEN u.timecreated > ? THEN 'User created'
                ELSE 'User updated'
            END as activity_type
     FROM {user} u 
     WHERE u.deleted = 0 AND u.id > 1
     ORDER BY u.timecreated DESC 
     LIMIT 10",
    [strtotime('-7 days')]
);

// Prepare template data
$templatecontext = [
    'total_users' => $total_users,
    'active_users' => $active_users,
    'pending_approvals' => $pending_approvals,
    'department_managers' => $department_managers,
    'recent_uploads' => $recent_uploads,
    'recent_activity' => array_values($recent_activity),
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

// Add CSS for sidebar
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #f8f9fa;
        min-height: 100vh;
        overflow-x: hidden;
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
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px; /* Add padding to account for topbar */
    }
    
    /* Mobile responsive */
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
            padding-top: 20px;
        }
    }
</style>";

echo $OUTPUT->render_from_template('theme_remui_kids/users_management_dashboard', $templatecontext);
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
