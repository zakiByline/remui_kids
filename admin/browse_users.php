<?php
/**
 * Browse Users Page
 * Display and manage all users in the system
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
                $sort = $_GET['sort'] ?? 'firstname';
                $order = $_GET['order'] ?? 'ASC';
                $offset = ($page - 1) * $per_page;
                
                $where_conditions = "u.deleted = 0";
                $params = [];
                
                if ($search) {
                    $where_conditions .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params ?? [], [$search_param, $search_param, $search_param, $search_param]);
                }
                
                // Validate sort column
                $allowed_sorts = ['firstname', 'lastname', 'email', 'username', 'timecreated', 'lastaccess'];
                if (!in_array($sort, $allowed_sorts)) {
                    $sort = 'firstname';
                }
                
                // Validate order
                $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
                
                // Start with the simplest possible query to test basic functionality
                try {
                    // First test: Can we access the user table at all?
                    $test_user = $DB->get_record_sql("SELECT id FROM {user} WHERE deleted = 0 LIMIT 1");
                    if (!$test_user) {
                        throw new Exception("No users found in database");
                    }
                    
                    // Try the complex query first, fallback to simple query if it fails
                    try {
                        $users = $DB->get_records_sql(
                            "SELECT u.*, 
                                    COALESCE(ue_count.enrollment_count, 0) as enrollment_count,
                                    COALESCE(ul_data.last_access, 0) as last_access
                             FROM {user} u 
                             LEFT JOIN (
                                 SELECT userid, COUNT(*) as enrollment_count 
                                 FROM {user_enrolments} 
                                 GROUP BY userid
                             ) ue_count ON u.id = ue_count.userid
                             LEFT JOIN (
                                 SELECT userid, MAX(timeaccess) as last_access 
                                 FROM {user_lastaccess} 
                                 GROUP BY userid
                             ) ul_data ON u.id = ul_data.userid
                             WHERE {$where_conditions}
                             ORDER BY u.{$sort} {$order}
                             LIMIT {$per_page} OFFSET {$offset}",
                            $params
                        );
                    } catch (Exception $e) {
                        // Fallback to simple query if complex query fails
                        $users = $DB->get_records_sql(
                            "SELECT u.*, 0 as enrollment_count, 0 as last_access
                             FROM {user} u 
                             WHERE {$where_conditions}
                             ORDER BY u.{$sort} {$order}
                             LIMIT {$per_page} OFFSET {$offset}",
                            $params
                        );
                    }
                } catch (Exception $e) {
                    // If even the basic query fails, return empty result
                    $users = [];
                }
                
                $total_users = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {user} u WHERE {$where_conditions}",
                    $params
                );
                
                echo json_encode([
                    'status' => 'success', 
                    'users' => array_values($users),
                    'total' => $total_users,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => ceil($total_users / $per_page)
                ]);
            } catch (Exception $e) {
                // Add more detailed error information for debugging
                $error_message = 'Failed to load users: ' . $e->getMessage();
                $error_message .= ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine();
                echo json_encode(['status' => 'error', 'message' => $error_message]);
            }
            exit;
            
        case 'get_user_details':
            $user_id = intval($_GET['user_id']);
            try {
                $user = $DB->get_record('user', ['id' => $user_id, 'deleted' => 0]);
                if ($user) {
                    // Try to get user enrollments, but don't fail if tables don't exist
                    $enrollments = [];
                    try {
                        $enrollments = $DB->get_records_sql(
                            "SELECT c.fullname, c.shortname, e.timecreated as enrolled_date
                             FROM {user_enrolments} ue
                             JOIN {enrol} e ON ue.enrolid = e.id
                             JOIN {course} c ON e.courseid = c.id
                             WHERE ue.userid = ?
                             ORDER BY e.timecreated DESC",
                            [$user_id]
                        );
                    } catch (Exception $e) {
                        // If enrollment tables don't exist, just use empty array
                        $enrollments = [];
                    }
                    
                    // Try to get user last access, but don't fail if table doesn't exist
                    $last_access = null;
                    try {
                        $last_access = $DB->get_record_sql(
                            "SELECT MAX(timeaccess) as last_access FROM {user_lastaccess} WHERE userid = ?",
                            [$user_id]
                        );
                    } catch (Exception $e) {
                        // If lastaccess table doesn't exist, just use null
                        $last_access = null;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'user' => $user,
                        'enrollments' => array_values($enrollments),
                        'last_access' => $last_access ? $last_access->last_access : null
                    ]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'User not found']);
                }
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load user details: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'bulk_action':
                $action = $_POST['bulk_action_type'];
                $user_ids = $_POST['user_ids'] ?? [];
                
                if (empty($user_ids)) {
                    $success_message = "Please select users to perform action.";
                    $message_type = "error";
                } else {
                    $count = 0;
                    foreach ($user_ids as $user_id) {
                        $user_id = intval($user_id);
                        switch ($action) {
                            case 'suspend':
                                $user = $DB->get_record('user', ['id' => $user_id]);
                                if ($user) {
                                    $user->suspended = 1;
                                    $user->timemodified = time();
                                    $DB->update_record('user', $user);
                                    $count++;
                                }
                                break;
                            case 'unsuspend':
                                $user = $DB->get_record('user', ['id' => $user_id]);
                                if ($user) {
                                    $user->suspended = 0;
                                    $user->timemodified = time();
                                    $DB->update_record('user', $user);
                                    $count++;
                                }
                                break;
                            case 'delete':
                                $user = $DB->get_record('user', ['id' => $user_id]);
                                if ($user) {
                                    $user->deleted = 1;
                                    $user->timemodified = time();
                                    $DB->update_record('user', $user);
                                    $count++;
                                }
                                break;
                        }
                    }
                    $success_message = "Action completed on {$count} users.";
                    $message_type = "success";
                }
                break;
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/browse_users.php');
$PAGE->set_title('Browse Users');
$PAGE->set_heading('Browse Users');

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Sidebar toggle button for mobile
echo "<button class='sidebar-toggle' onclick='toggleSidebar()' aria-label='Toggle sidebar'>";
echo "<i class='fa fa-bars'></i>";
echo "</button>";

// Main content wrapper
echo "<div class='admin-main-content'>";
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

/* Browse Users Page Styles */
.browse-users-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.browse-users-header {
    text-align: center;
    margin-bottom: 40px;
    color: white;
    position: relative;
}

.browse-users-header h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 10px;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    animation: titleGlow 2s ease-in-out infinite alternate;
}

.browse-users-header p {
    font-size: 1.1rem;
    opacity: 0.9;
    margin-bottom: 30px;
}

.header-actions {
    position: absolute;
    top: 0;
    right: 0;
    display: flex;
    gap: 15px;
}

.header-btn {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    padding: 10px 20px;
    border-radius: 25px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    gap: 8px;
}

.header-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
}

.header-btn.primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}

.header-btn.primary:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}

@keyframes titleGlow {
    from { text-shadow: 2px 2px 4px rgba(0,0,0,0.3), 0 0 20px rgba(255,255,255,0.3); }
    to { text-shadow: 2px 2px 4px rgba(0,0,0,0.3), 0 0 30px rgba(255,255,255,0.6); }
}

/* Search and Filter Section */
.search-section {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
}

.search-form {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input {
    flex: 1;
    min-width: 300px;
    padding: 12px 20px;
    border: 2px solid #e9ecef;
    border-radius: 25px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 25px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.filter-select {
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    background: white;
    cursor: pointer;
}

/* Users Table */
.users-table-container {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    overflow-x: auto;
}

.users-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.users-table th,
.users-table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.users-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s ease;
}

.users-table th:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}

.users-table th.sortable {
    position: relative;
}

.users-table th.sortable:after {
    content: '↕';
    position: absolute;
    right: 10px;
    opacity: 0.5;
}

.users-table th.sort-asc:after {
    content: '↑';
    opacity: 1;
}

.users-table th.sort-desc:after {
    content: '↓';
    opacity: 1;
}

.users-table tr:hover {
    background: rgba(102, 126, 234, 0.05);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 1.2rem;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-details h4 {
    margin: 0 0 5px 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
}

.user-details p {
    margin: 0;
    color: #666;
    font-size: 0.9rem;
}

.user-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-active {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.status-suspended {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.status-inactive {
    background: rgba(108, 117, 125, 0.2);
    color: #6c757d;
    border: 1px solid rgba(108, 117, 125, 0.3);
}

.user-actions {
    display: flex;
    gap: 10px;
}

.action-btn {
    padding: 8px 15px;
    border: none;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.action-btn.view {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
    border: 1px solid rgba(102, 126, 234, 0.3);
}

.action-btn.view:hover {
    background: rgba(102, 126, 234, 0.2);
}

.action-btn.edit {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.action-btn.edit:hover {
    background: rgba(40, 167, 69, 0.2);
}

.action-btn.suspend {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
    border: 1px solid rgba(255, 193, 7, 0.3);
}

.action-btn.suspend:hover {
    background: rgba(255, 193, 7, 0.2);
}

.action-btn.delete {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.action-btn.delete:hover {
    background: rgba(220, 53, 69, 0.2);
}

/* Bulk Actions */
.bulk-actions {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    display: none;
}

.bulk-actions.show {
    display: block;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.bulk-actions-content {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.bulk-actions select {
    padding: 8px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 0.9rem;
    background: white;
}

.bulk-actions .btn {
    padding: 8px 20px;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.bulk-actions .btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.bulk-actions .btn-secondary {
    background: #6c757d;
    color: white;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 30px;
}

.pagination-btn {
    padding: 10px 15px;
    border: 2px solid #e9ecef;
    background: white;
    color: #333;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.pagination-btn:hover {
    border-color: #667eea;
    color: #667eea;
}

.pagination-btn.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: transparent;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Loading Spinner */
.loading-spinner {
    display: none;
    text-align: center;
    padding: 40px;
}

.loading-spinner.show {
    display: block;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Message Styles */
.message {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 500;
    animation: slideInDown 0.5s ease-out;
}

.message-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.message-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Design */
@media (max-width: 768px) {
    .browse-users-container {
        padding: 15px;
    }
    
    .browse-users-header h1 {
        font-size: 2rem;
    }
    
    .header-actions {
        position: static;
        justify-content: center;
        margin-bottom: 20px;
    }
    
    .search-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-input {
        min-width: auto;
    }
    
    .users-table-container {
        padding: 15px;
        overflow-x: auto;
    }
    
    .user-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .user-actions {
        flex-direction: column;
        gap: 5px;
    }
    
    .bulk-actions-content {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="browse-users-container">
    <div class="browse-users-header">
        <div class="header-actions">
            <button class="header-btn" onclick="refreshUsers()">
                <i class="fa fa-refresh"></i>
                Refresh
            </button>
            <button class="header-btn" onclick="exportUsers()">
                <i class="fa fa-download"></i>
                Export
            </button>
            <button class="header-btn primary" onclick="window.location.href='user_management.php'">
                <i class="fa fa-arrow-left"></i>
                Back to User Management
            </button>
        </div>
        <h1>Browse Users</h1>
        <p>Search, filter, and manage all users in the system</p>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="message message-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <!-- Search and Filter Section -->
    <div class="search-section">
        <form class="search-form" id="searchForm">
            <input type="text" class="search-input" id="searchInput" placeholder="Search users by name, email, or username..." value="">
            <select class="filter-select" id="sortSelect">
                <option value="firstname">Sort by First Name</option>
                <option value="lastname">Sort by Last Name</option>
                <option value="email">Sort by Email</option>
                <option value="username">Sort by Username</option>
                <option value="timecreated">Sort by Registration Date</option>
                <option value="lastaccess">Sort by Last Access</option>
            </select>
            <select class="filter-select" id="orderSelect">
                <option value="ASC">Ascending</option>
                <option value="DESC">Descending</option>
            </select>
            <button type="submit" class="search-btn">
                <i class="fa fa-search"></i>
                Search
            </button>
        </form>
    </div>

    <!-- Bulk Actions -->
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-actions-content">
            <span id="selectedCount">0 users selected</span>
            <select id="bulkActionSelect">
                <option value="">Select Action</option>
                <option value="suspend">Suspend Users</option>
                <option value="unsuspend">Unsuspend Users</option>
                <option value="delete">Delete Users</option>
            </select>
            <button class="btn btn-primary" onclick="executeBulkAction()">Execute</button>
            <button class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="users-table-container">
        <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner"></div>
            <p>Loading users...</p>
        </div>
        
        <table class="users-table" id="usersTable" style="display: none;">
            <thead>
                <tr>
                    <th>
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                    </th>
                    <th class="sortable" data-sort="firstname" onclick="sortTable('firstname')">User</th>
                    <th class="sortable" data-sort="email" onclick="sortTable('email')">Email</th>
                    <th class="sortable" data-sort="username" onclick="sortTable('username')">Username</th>
                    <th class="sortable" data-sort="timecreated" onclick="sortTable('timecreated')">Registered</th>
                    <th class="sortable" data-sort="lastaccess" onclick="sortTable('lastaccess')">Last Access</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <!-- Users will be loaded here via AJAX -->
            </tbody>
        </table>
        
        <div id="noUsersMessage" style="display: none; text-align: center; padding: 40px; color: #666;">
            <i class="fa fa-users" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3;"></i>
            <h3>No users found</h3>
            <p>Try adjusting your search criteria or create a new user.</p>
        </div>
    </div>

    <!-- Pagination -->
    <div class="pagination" id="pagination">
        <!-- Pagination will be generated here -->
    </div>
</div>

<script>
let currentPage = 1;
let currentSearch = '';
let currentSort = 'firstname';
let currentOrder = 'ASC';
let selectedUsers = new Set();

// Load users on page load
document.addEventListener('DOMContentLoaded', function() {
    loadUsers();
    
    // Setup search form
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        currentSearch = document.getElementById('searchInput').value;
        currentSort = document.getElementById('sortSelect').value;
        currentOrder = document.getElementById('orderSelect').value;
        currentPage = 1;
        loadUsers();
    });
});

function loadUsers() {
    const loadingSpinner = document.getElementById('loadingSpinner');
    const usersTable = document.getElementById('usersTable');
    const noUsersMessage = document.getElementById('noUsersMessage');
    
    loadingSpinner.classList.add('show');
    usersTable.style.display = 'none';
    noUsersMessage.style.display = 'none';
    
    const params = new URLSearchParams({
        action: 'get_users',
        page: currentPage,
        per_page: 20,
        search: currentSearch,
        sort: currentSort,
        order: currentOrder
    });
    
    fetch(`?${params}`)
        .then(response => response.json())
        .then(data => {
            loadingSpinner.classList.remove('show');
            
            if (data.status === 'success') {
                if (data.users.length > 0) {
                    renderUsers(data.users);
                    renderPagination(data.total_pages, data.page);
                    usersTable.style.display = 'table';
                } else {
                    noUsersMessage.style.display = 'block';
                }
            } else {
                showMessage('Error loading users: ' + data.message, 'error');
            }
        })
        .catch(error => {
            loadingSpinner.classList.remove('show');
            console.error('Error loading users:', error);
            showMessage('Error loading users. Please try again.', 'error');
        });
}

function renderUsers(users) {
    const tbody = document.getElementById('usersTableBody');
    tbody.innerHTML = '';
    
    users.forEach(user => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <input type="checkbox" class="user-checkbox" value="${user.id}" onchange="updateSelection()">
            </td>
            <td>
                <div class="user-info">
                    <div class="user-avatar">
                        ${user.firstname.charAt(0).toUpperCase()}${user.lastname.charAt(0).toUpperCase()}
                    </div>
                    <div class="user-details">
                        <h4>${user.firstname} ${user.lastname}</h4>
                        <p>ID: ${user.id}</p>
                    </div>
                </div>
            </td>
            <td>${user.email}</td>
            <td>${user.username}</td>
            <td>${formatDate(user.timecreated)}</td>
            <td>${user.last_access ? formatDate(user.last_access) : 'Never'}</td>
            <td>
                <span class="user-status ${getUserStatusClass(user)}">
                    ${getUserStatusText(user)}
                </span>
            </td>
            <td>
                <div class="user-actions">
                    <a href="#" class="action-btn view" onclick="viewUser(${user.id})">
                        <i class="fa fa-eye"></i> View
                    </a>
                    <a href="#" class="action-btn edit" onclick="editUser(${user.id})">
                        <i class="fa fa-edit"></i> Edit
                    </a>
                    ${user.suspended ? 
                        `<a href="#" class="action-btn suspend" onclick="toggleUserStatus(${user.id}, 'unsuspend')">
                            <i class="fa fa-unlock"></i> Unsuspend
                        </a>` :
                        `<a href="#" class="action-btn suspend" onclick="toggleUserStatus(${user.id}, 'suspend')">
                            <i class="fa fa-lock"></i> Suspend
                        </a>`
                    }
                    <a href="#" class="action-btn delete" onclick="deleteUser(${user.id})">
                        <i class="fa fa-trash"></i> Delete
                    </a>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function renderPagination(totalPages, currentPageNum) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
    // Previous button
    const prevBtn = document.createElement('a');
    prevBtn.className = 'pagination-btn';
    prevBtn.innerHTML = '<i class="fa fa-chevron-left"></i> Previous';
    prevBtn.onclick = () => {
        if (currentPageNum > 1) {
            currentPage = currentPageNum - 1;
            loadUsers();
        }
    };
    if (currentPageNum <= 1) prevBtn.classList.add('disabled');
    pagination.appendChild(prevBtn);
    
    // Page numbers
    const startPage = Math.max(1, currentPageNum - 2);
    const endPage = Math.min(totalPages, currentPageNum + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('a');
        pageBtn.className = 'pagination-btn';
        if (i === currentPageNum) pageBtn.classList.add('active');
        pageBtn.textContent = i;
        pageBtn.onclick = () => {
            currentPage = i;
            loadUsers();
        };
        pagination.appendChild(pageBtn);
    }
    
    // Next button
    const nextBtn = document.createElement('a');
    nextBtn.className = 'pagination-btn';
    nextBtn.innerHTML = 'Next <i class="fa fa-chevron-right"></i>';
    nextBtn.onclick = () => {
        if (currentPageNum < totalPages) {
            currentPage = currentPageNum + 1;
            loadUsers();
        }
    };
    if (currentPageNum >= totalPages) nextBtn.classList.add('disabled');
    pagination.appendChild(nextBtn);
}

function sortTable(column) {
    if (currentSort === column) {
        currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        currentSort = column;
        currentOrder = 'ASC';
    }
    currentPage = 1;
    loadUsers();
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedUsers.add(parseInt(checkbox.value));
        } else {
            selectedUsers.delete(parseInt(checkbox.value));
        }
    });
    
    updateBulkActions();
}

function updateSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    selectedUsers.clear();
    
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            selectedUsers.add(parseInt(checkbox.value));
        }
    });
    
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = selectedUsers.size === checkboxes.length;
    selectAll.indeterminate = selectedUsers.size > 0 && selectedUsers.size < checkboxes.length;
    
    updateBulkActions();
}

function updateBulkActions() {
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    if (selectedUsers.size > 0) {
        bulkActions.classList.add('show');
        selectedCount.textContent = `${selectedUsers.size} user${selectedUsers.size === 1 ? '' : 's'} selected`;
    } else {
        bulkActions.classList.remove('show');
    }
}

function executeBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    if (!action) {
        showMessage('Please select an action.', 'error');
        return;
    }
    
    if (selectedUsers.size === 0) {
        showMessage('Please select users to perform action.', 'error');
        return;
    }
    
    if (confirm(`Are you sure you want to ${action} ${selectedUsers.size} user(s)?`)) {
        const formData = new FormData();
        formData.append('action', 'bulk_action');
        formData.append('bulk_action_type', action);
        formData.append('user_ids', Array.from(selectedUsers));
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            showMessage(`Action completed on ${selectedUsers.size} users.`, 'success');
            clearSelection();
            loadUsers();
        })
        .catch(error => {
            console.error('Error executing bulk action:', error);
            showMessage('Error executing bulk action.', 'error');
        });
    }
}

function clearSelection() {
    selectedUsers.clear();
    document.querySelectorAll('.user-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    document.getElementById('selectAll').indeterminate = false;
    updateBulkActions();
}

function viewUser(userId) {
    // Implement user view functionality
    console.log('View user:', userId);
}

function editUser(userId) {
    // Implement user edit functionality
    console.log('Edit user:', userId);
}

function toggleUserStatus(userId, action) {
    if (confirm(`Are you sure you want to ${action} this user?`)) {
        const formData = new FormData();
        formData.append('action', 'bulk_action');
        formData.append('bulk_action_type', action);
        formData.append('user_ids', [userId]);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            showMessage(`User ${action}ed successfully.`, 'success');
            loadUsers();
        })
        .catch(error => {
            console.error('Error toggling user status:', error);
            showMessage('Error updating user status.', 'error');
        });
    }
}

function deleteUser(userId) {
    if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
        const formData = new FormData();
        formData.append('action', 'bulk_action');
        formData.append('bulk_action_type', 'delete');
        formData.append('user_ids', [userId]);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            showMessage('User deleted successfully.', 'success');
            loadUsers();
        })
        .catch(error => {
            console.error('Error deleting user:', error);
            showMessage('Error deleting user.', 'error');
        });
    }
}

function refreshUsers() {
    loadUsers();
}

function exportUsers() {
    // Implement user export functionality
    console.log('Export users');
}

function formatDate(timestamp) {
    const date = new Date(timestamp * 1000);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function getUserStatusClass(user) {
    if (user.suspended) return 'status-suspended';
    if (user.last_access && (Date.now() / 1000 - user.last_access) < (30 * 24 * 60 * 60)) return 'status-active';
    return 'status-inactive';
}

function getUserStatusText(user) {
    if (user.suspended) return 'Suspended';
    if (user.last_access && (Date.now() / 1000 - user.last_access) < (30 * 24 * 60 * 60)) return 'Active';
    return 'Inactive';
}

function showMessage(message, type) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    
    const container = document.querySelector('.browse-users-container');
    container.insertBefore(messageDiv, container.firstChild);
    
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}
</script>

<script>
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
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
