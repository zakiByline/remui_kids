<?php
/**
 * Edit Users Page
 * Comprehensive user editing with search, filters, and bulk operations
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
        case 'get_users':
            try {
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 20);
                $search = trim($_GET['search'] ?? '');
                $role_filter = $_GET['role'] ?? '';
                $status_filter = $_GET['status'] ?? '';
                $sort_by = $_GET['sort'] ?? 'firstname';
                $sort_order = $_GET['order'] ?? 'ASC';
                $offset = ($page - 1) * $per_page;
                
                $where_conditions = ["u.deleted = 0"];
                $params = [];
                
                if ($search) {
                    $where_conditions[] = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
                }
                
                if ($role_filter) {
                    $where_conditions[] = "r.shortname = ?";
                    $params[] = $role_filter;
                }
                
                if ($status_filter === 'active') {
                    $where_conditions[] = "u.suspended = 0";
                } elseif ($status_filter === 'suspended') {
                    $where_conditions[] = "u.suspended = 1";
                }
                
                $where_clause = implode(' AND ', $where_conditions);
                
                // Get users with role information
                $users = $DB->get_records_sql(
                    "SELECT u.id, u.username, u.email, u.firstname, u.lastname, u.suspended, u.timecreated,
                            r.shortname as role_shortname,
                            r.name as role_name,
                            MAX(ul.timeaccess) as last_access,
                            COUNT(ue.id) as enrollment_count
                     FROM {user} u 
                     LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ?
                     LEFT JOIN {role} r ON ra.roleid = r.id
                     LEFT JOIN {user_lastaccess} ul ON u.id = ul.userid
                     LEFT JOIN {user_enrolments} ue ON u.id = ue.userid
                     WHERE {$where_clause}
                     GROUP BY u.id, u.username, u.email, u.firstname, u.lastname, u.suspended, u.timecreated, r.shortname, r.name
                     ORDER BY u.{$sort_by} {$sort_order}
                     LIMIT {$per_page} OFFSET {$offset}",
                    array_merge([$context->id], $params ?? [])
                );
                
                // Get total count
                $total_count = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     LEFT JOIN {role_assignments} ra ON u.id = ra.userid AND ra.contextid = ?
                     LEFT JOIN {role} r ON ra.roleid = r.id
                     WHERE {$where_clause}",
                    array_merge([$context->id], $params ?? [])
                );
                
                // Format users data
                $formatted_users = [];
                foreach ($users as $user) {
                    $formatted_users[] = [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'fullname' => fullname($user),
                        'role' => $user->role_name ?? 'No Role',
                        'role_shortname' => $user->role_shortname ?? '',
                        'suspended' => (int)$user->suspended, // Ensure it's an integer
                        'last_access' => $user->last_access ? date('M j, Y g:i A', $user->last_access) : 'Never',
                        'enrollment_count' => $user->enrollment_count,
                        'timecreated' => date('M j, Y', $user->timecreated)
                    ];
                }
                
                echo json_encode([
                    'status' => 'success',
                    'users' => $formatted_users,
                    'total' => $total_count,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => ceil($total_count / $per_page)
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'update_user':
            try {
                $data = json_decode(file_get_contents('php://input'), true);
                $userid = intval($data['userid']);
                
                if (!$userid) {
                    throw new Exception("Invalid user ID");
                }
                
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                if (!$user) {
                    throw new Exception("User not found");
                }
                
                // Update user fields
                $update_fields = ['firstname', 'lastname', 'email', 'suspended'];
                foreach ($update_fields as $field) {
                    if (isset($data[$field])) {
                        $user->$field = $data[$field];
                    }
                }
                
                $user->timemodified = time();
                
                if (!$DB->update_record('user', $user)) {
                    throw new Exception("Failed to update user");
                }
                
                // Update role if specified
                if (isset($data['role'])) {
                    // Remove existing role assignments
                    $DB->delete_records('role_assignments', ['userid' => $userid, 'contextid' => $context->id]);
                    
                    // Assign new role
                    if ($data['role']) {
                        $role = $DB->get_record('role', ['shortname' => $data['role']]);
                        if ($role) {
                            role_assign($role->id, $userid, $context->id);
                        }
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'User updated successfully'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'bulk_update':
            try {
                $data = json_decode(file_get_contents('php://input'), true);
                $user_ids = $data['user_ids'] ?? [];
                $action = $data['action'] ?? '';
                
                if (empty($user_ids) || !$action) {
                    throw new Exception("Invalid bulk operation");
                }
                
                $updated_count = 0;
                
                foreach ($user_ids as $userid) {
                    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                    if (!$user) continue;
                    
                    switch ($action) {
                        case 'suspend':
                            $user->suspended = 1;
                            break;
                        case 'unsuspend':
                            $user->suspended = 0;
                            break;
                        case 'delete':
                            $user->deleted = 1;
                            break;
                    }
                    
                    $user->timemodified = time();
                    if ($DB->update_record('user', $user)) {
                        $updated_count++;
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => "Successfully updated {$updated_count} users",
                    'updated_count' => $updated_count
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'get_user_details':
            try {
                $userid = intval($_GET['userid']);
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                
                if (!$user) {
                    throw new Exception("User not found");
                }
                
                // Get user role
                $role = $DB->get_record_sql(
                    "SELECT r.* FROM {role} r 
                     JOIN {role_assignments} ra ON r.id = ra.roleid 
                     WHERE ra.userid = ? AND ra.contextid = ?",
                    [$userid, $context->id]
                );
                
                // Get user enrollments
                $enrollments = $DB->get_records_sql(
                    "SELECT c.id, c.fullname, c.shortname, ue.timecreated
                     FROM {course} c
                     JOIN {enrol} e ON c.id = e.courseid
                     JOIN {user_enrolments} ue ON e.id = ue.enrolid
                     WHERE ue.userid = ? AND c.visible = 1
                     ORDER BY ue.timecreated DESC
                     LIMIT 10",
                    [$userid]
                );
                
                echo json_encode([
                    'status' => 'success',
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'suspended' => $user->suspended,
                        'timecreated' => $user->timecreated,
                        'lastaccess' => $user->lastaccess,
                        'role' => $role ? $role->shortname : '',
                        'role_name' => $role ? $role->name : 'No Role',
                        'enrollments' => array_values($enrollments)
                    ]
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
    }
}

// Set page context
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/edit_users.php');
$PAGE->set_title('Edit Users');
$PAGE->set_heading('Edit Users');

// Get available roles
$roles = $DB->get_records('role', [], 'name ASC');

// Prepare template data
$templatecontext = [
    'roles' => array_values($roles),
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Sidebar toggle button for mobile
echo "<button class='sidebar-toggle' onclick='toggleSidebar()' aria-label='Toggle sidebar'>";
echo "<i class='fa fa-bars'></i>";
echo "</button>";

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
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
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
        padding: 1rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
        font-weight: 500;
        font-size: 0.95rem;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background: #f8f9fa;
        color: #2196F3;
        padding-left: 2.5rem;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background: linear-gradient(90deg, rgba(33, 150, 243, 0.1) 0%, transparent 100%);
        color: #2196F3;
        border-left: 4px solid #2196F3;
        font-weight: 600;
    }
    
    .admin-sidebar .sidebar-icon {
        margin-right: 1rem;
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
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
        padding-top: 80px;
    }
    
    /* Sidebar toggle button for mobile */
    .sidebar-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1001;
        background: #2196F3;
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
        transition: all 0.3s ease;
    }
    
    .sidebar-toggle:hover {
        background: #1976D2;
        transform: scale(1.1);
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            transition: left 0.3s ease;
        }
        
        .admin-sidebar.sidebar-open {
            left: 0;
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
        
        .sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
</style>";

// Add JavaScript for sidebar toggle
echo "<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});
</script>";

// Main content wrapper
echo "<div class='admin-main-content'>";

echo $OUTPUT->render_from_template('theme_remui_kids/edit_users', $templatecontext);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
