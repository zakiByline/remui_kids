<?php
/**
 * User Management Page
 * Comprehensive user administration and account management
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
                $offset = ($page - 1) * $per_page;
                
                $where_conditions = "u.deleted = 0";
                $params = [];
                
                if ($search) {
                    $where_conditions .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params ?? [], [$search_param, $search_param, $search_param, $search_param]);
                }
                
                $users = $DB->get_records_sql(
                    "SELECT u.*, 
                            COUNT(ue.id) as enrollment_count,
                            MAX(ul.timecreated) as last_login
                     FROM {user} u 
                     LEFT JOIN {user_enrolments} ue ON u.id = ue.userid 
                     LEFT JOIN {user_lastaccess} ul ON u.id = ul.userid
                     WHERE {$where_conditions}
                     GROUP BY u.id
                     ORDER BY u.firstname ASC, u.lastname ASC
                     LIMIT {$per_page} OFFSET {$offset}",
                    $params
                );
                
                $total_users = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {user} u WHERE {$where_conditions}",
                    $params
                );
                
                echo json_encode([
                    'status' => 'success', 
                    'users' => array_values($users),
                    'total' => $total_users,
                    'page' => $page,
                    'per_page' => $per_page
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load users: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_cohorts':
            try {
                $cohorts = $DB->get_records('cohort', ['visible' => 1], 'name ASC');
                echo json_encode(['status' => 'success', 'cohorts' => array_values($cohorts)]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load cohorts: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_user_stats':
            try {
                $total_users = $DB->count_records('user', ['deleted' => 0]);
                $active_users = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) FROM {user} u 
                     JOIN {user_lastaccess} ul ON u.id = ul.userid 
                     WHERE u.deleted = 0 AND ul.timeaccess > ?",
                    [time() - (30 * 24 * 60 * 60)] // Last 30 days
                );
                $total_cohorts = $DB->count_records('cohort', ['visible' => 1]);
                $total_enrollments = $DB->count_records('user_enrolments');
                
                echo json_encode([
                    'status' => 'success',
                    'stats' => [
                        'total_users' => $total_users,
                        'active_users' => $active_users,
                        'total_cohorts' => $total_cohorts,
                        'total_enrollments' => $total_enrollments
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load stats: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_user':
                $firstname = trim($_POST['firstname']);
                $lastname = trim($_POST['lastname']);
                $email = trim($_POST['email']);
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                
                if ($firstname && $lastname && $email && $username && $password) {
                    // Check if username or email already exists
                    if ($DB->record_exists('user', ['username' => $username])) {
                        $success_message = "Username already exists!";
                        $message_type = "error";
                    } elseif ($DB->record_exists('user', ['email' => $email])) {
                        $success_message = "Email already exists!";
                        $message_type = "error";
                    } else {
                        $user = new stdClass();
                        $user->firstname = $firstname;
                        $user->lastname = $lastname;
                        $user->email = $email;
                        $user->username = $username;
                        $user->password = password_hash($password, PASSWORD_DEFAULT);
                        $user->timecreated = time();
                        $user->timemodified = time();
                        $user->confirmed = 1;
                        $user->deleted = 0;
                        $user->suspended = 0;
                        
                        if ($DB->insert_record('user', $user)) {
                            $success_message = "User created successfully!";
                            $message_type = "success";
                        } else {
                            $success_message = "Failed to create user. Please try again.";
                            $message_type = "error";
                        }
                    }
                } else {
                    $success_message = "Please fill in all required fields.";
                    $message_type = "error";
                }
                break;
                
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
$PAGE->set_url('/theme/remui_kids/admin/user_management.php');
$PAGE->set_title('User Management');
$PAGE->set_heading('User Management');

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
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

/* User Management Page Styles */
.user-management-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.user-management-header {
    text-align: center;
    margin-bottom: 40px;
    color: white;
    position: relative;
}

.user-management-header h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 10px;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    animation: titleGlow 2s ease-in-out infinite alternate;
}

.user-management-header p {
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

.stats-row {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

.stat-card {
    background: rgba(255, 255, 255, 0.95);
    padding: 25px;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    transition: all 0.3s ease;
    animation: fadeInUp 0.6s ease-out;
    display: flex;
    align-items: center;
    gap: 20px;
    min-width: 200px;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.3);
}

.stat-card.stat-users {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.stat-card.stat-active {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.stat-card.stat-cohorts {
    background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);
    color: white;
}

.stat-card.stat-enrollments {
    background: linear-gradient(135deg, #fd7e14 0%, #dc3545 100%);
    color: white;
}

.stat-icon {
    font-size: 2.5rem;
    opacity: 0.8;
}

.stat-content {
    text-align: left;
}

.stat-content .number {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 5px;
    line-height: 1;
}

.stat-content .label {
    font-size: 1rem;
    opacity: 0.9;
    font-weight: 500;
}

/* Management Section */
.management-section {
    margin-bottom: 50px;
}

.section-title {
    text-align: center;
    color: white;
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 30px;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
}

/* Management Cards Grid */
.management-grid-container {
    max-width: 100%;
    margin: 0 auto 40px auto;
    padding: 20px;
}

.management-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    justify-items: center;
}

.management-card {
    width: 100%;
    max-width: 300px;
    background: rgba(45, 45, 45, 0.95);
    border-radius: 15px;
    padding: 25px;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
}

.management-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.4);
    background: rgba(55, 55, 55, 0.95);
}

.card-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.browse-icon { background: linear-gradient(135deg, #667eea, #764ba2); }
.bulk-icon { background: linear-gradient(135deg, #28a745, #20c997); }
.add-user-icon { background: linear-gradient(135deg, #6f42c1, #e83e8c); }
.manage-icon { background: linear-gradient(135deg, #fd7e14, #dc3545); }
.preferences-icon { background: linear-gradient(135deg, #17a2b8, #6f42c1); }
.profile-icon { background: linear-gradient(135deg, #e83e8c, #fd7e14); }
.cohorts-icon { background: linear-gradient(135deg, #28a745, #20c997); }
.cohort-fields-icon { background: linear-gradient(135deg, #6f42c1, #e83e8c); }
.merge-icon { background: linear-gradient(135deg, #fd7e14, #dc3545); }
.logs-icon { background: linear-gradient(135deg, #17a2b8, #6f42c1); }
.upload-icon { background: linear-gradient(135deg, #e83e8c, #fd7e14); }
.pictures-icon { background: linear-gradient(135deg, #28a745, #20c997); }

.card-content {
    flex: 1;
}

.card-content h3 {
    margin: 0 0 8px 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: white;
}

.card-subtitle {
    margin: 0 0 8px 0;
    font-size: 0.9rem;
    color: #ccc;
    font-weight: 500;
}

.card-description {
    margin: 0 0 12px 0;
    font-size: 0.85rem;
    color: #aaa;
    line-height: 1.4;
}

.card-status {
    margin-top: 10px;
}

.status-available {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.card-arrow {
    font-size: 1.2rem;
    color: #666;
    transition: all 0.3s ease;
}

.management-card:hover .card-arrow {
    color: white;
    transform: translateX(5px);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    backdrop-filter: blur(5px);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-50px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 20px 20px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
}

.close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.close:hover {
    opacity: 0.7;
}

.modal-body {
    padding: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.modal-footer {
    padding: 20px 30px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
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

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Design */
@media (max-width: 768px) {
    .user-management-container {
        padding: 15px;
    }
    
    .user-management-header h1 {
        font-size: 2rem;
    }
    
    .header-actions {
        position: static;
        justify-content: center;
        margin-bottom: 20px;
    }
    
    .stats-row {
        flex-direction: column;
        align-items: center;
    }
    
    .stat-card {
        min-width: auto;
        width: 100%;
        max-width: 300px;
    }
    
    .management-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .management-card {
        width: 100%;
        max-width: none;
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .card-icon {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
    }
    
    .management-grid-container {
        padding: 10px;
    }
}
</style>

<div class="user-management-container">
    <div class="user-management-header">
        <div class="header-actions">
            <button class="header-btn" onclick="refreshUsers()">
                <i class="fa fa-refresh"></i>
                Refresh
            </button>
            <button class="header-btn primary" onclick="openModal('createUserModal')">
                <i class="fa fa-plus"></i>
                Add User
            </button>
        </div>
        <h1>User Management</h1>
        <p>Comprehensive user administration and account management tools</p>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="message message-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <!-- Top Statistics Row -->
    <div class="stats-row">
        <div class="stat-card stat-users">
            <div class="stat-icon">
                <i class="fa fa-users"></i>
            </div>
            <div class="stat-content">
                <div class="number" id="totalUsers">-</div>
                <div class="label">Total Users</div>
            </div>
        </div>
        <div class="stat-card stat-active">
            <div class="stat-icon">
                <i class="fa fa-user-check"></i>
            </div>
            <div class="stat-content">
                <div class="number" id="activeUsers">-</div>
                <div class="label">Active Users</div>
            </div>
        </div>
        <div class="stat-card stat-cohorts">
            <div class="stat-icon">
                <i class="fa fa-users"></i>
            </div>
            <div class="stat-content">
                <div class="number" id="totalCohorts">-</div>
                <div class="label">Cohorts</div>
            </div>
        </div>
        <div class="stat-card stat-enrollments">
            <div class="stat-icon">
                <i class="fa fa-graduation-cap"></i>
            </div>
            <div class="stat-content">
                <div class="number" id="totalEnrollments">-</div>
                <div class="label">Enrollments</div>
            </div>
        </div>
    </div>

    <!-- Management Cards Section -->
    <div class="management-section">
        <h2 class="section-title">User Management</h2>
        <div class="management-grid-container">
            <div class="management-grid" id="managementGrid">
                <div class="management-card" onclick="browseUsers()">
                    <div class="card-icon browse-icon">
                        <i class="fa fa-list"></i>
                    </div>
                    <div class="card-content">
                        <h3>Browse list of users</h3>
                        <p class="card-subtitle">User Directory</p>
                        <p class="card-description">View and search through all system users</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="bulkUserActions()">
                    <div class="card-icon bulk-icon">
                        <i class="fa fa-tasks"></i>
                    </div>
                    <div class="card-content">
                        <h3>Bulk user actions</h3>
                        <p class="card-subtitle">Batch Operations</p>
                        <p class="card-description">Perform actions on multiple users at once</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="openModal('createUserModal')">
                    <div class="card-icon add-user-icon">
                        <i class="fa fa-user-plus"></i>
                    </div>
                    <div class="card-content">
                        <h3>Add a new user</h3>
                        <p class="card-subtitle">User Creation</p>
                        <p class="card-description">Create new user accounts in the system</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="userManagement()">
                    <div class="card-icon manage-icon">
                        <i class="fa fa-cogs"></i>
                    </div>
                    <div class="card-content">
                        <h3>User management</h3>
                        <p class="card-subtitle">User Administration</p>
                        <p class="card-description">Advanced user management and configuration</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="userDefaultPreferences()">
                    <div class="card-icon preferences-icon">
                        <i class="fa fa-sliders"></i>
                    </div>
                    <div class="card-content">
                        <h3>User default preferences</h3>
                        <p class="card-subtitle">System Preferences</p>
                        <p class="card-description">Configure default user preferences and settings</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="userProfileFields()">
                    <div class="card-icon profile-icon">
                        <i class="fa fa-id-card"></i>
                    </div>
                    <div class="card-content">
                        <h3>User profile fields</h3>
                        <p class="card-subtitle">Profile Configuration</p>
                        <p class="card-description">Manage custom user profile fields and data</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="manageCohorts()">
                    <div class="card-icon cohorts-icon">
                        <i class="fa fa-users"></i>
                    </div>
                    <div class="card-content">
                        <h3>Cohorts</h3>
                        <p class="card-subtitle">Group Management</p>
                        <p class="card-description">Create and manage user cohorts and groups</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="cohortCustomFields()">
                    <div class="card-icon cohort-fields-icon">
                        <i class="fa fa-tags"></i>
                    </div>
                    <div class="card-content">
                        <h3>Cohort custom fields</h3>
                        <p class="card-subtitle">Custom Fields</p>
                        <p class="card-description">Configure custom fields for cohorts</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="iomadMergeAccounts()">
                    <div class="card-icon merge-icon">
                        <i class="fa fa-compress"></i>
                    </div>
                    <div class="card-content">
                        <h3>IOMAD Merge user accounts</h3>
                        <p class="card-subtitle">Account Merging</p>
                        <p class="card-description">Merge duplicate user accounts in IOMAD</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="seeMergingLogs()">
                    <div class="card-icon logs-icon">
                        <i class="fa fa-history"></i>
                    </div>
                    <div class="card-content">
                        <h3>See merging logs</h3>
                        <p class="card-subtitle">Audit Trail</p>
                        <p class="card-description">View history of account merging operations</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="uploadUsers()">
                    <div class="card-icon upload-icon">
                        <i class="fa fa-upload"></i>
                    </div>
                    <div class="card-content">
                        <h3>Upload users</h3>
                        <p class="card-subtitle">Bulk Import</p>
                        <p class="card-description">Import multiple users from CSV files</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>

                <div class="management-card" onclick="uploadUserPictures()">
                    <div class="card-icon pictures-icon">
                        <i class="fa fa-image"></i>
                    </div>
                    <div class="card-content">
                        <h3>Upload user pictures</h3>
                        <p class="card-subtitle">Profile Images</p>
                        <p class="card-description">Bulk upload user profile pictures</p>
                        <div class="card-status">
                            <span class="status-available">Available</span>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <i class="fa fa-arrow-right"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New User</h3>
            <span class="close" onclick="closeModal('createUserModal')">&times;</span>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label for="firstname">First Name *</label>
                    <input type="text" id="firstname" name="firstname" required>
                </div>
                
                <div class="form-group">
                    <label for="lastname">Last Name *</label>
                    <input type="text" id="lastname" name="lastname" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<script>
// Load user statistics on page load
document.addEventListener('DOMContentLoaded', function() {
    loadUserStats();
});

function loadUserStats() {
    fetch('?action=get_user_stats')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('totalUsers').textContent = data.stats.total_users;
                document.getElementById('activeUsers').textContent = data.stats.active_users;
                document.getElementById('totalCohorts').textContent = data.stats.total_cohorts;
                document.getElementById('totalEnrollments').textContent = data.stats.total_enrollments;
            }
        })
        .catch(error => {
            console.error('Error loading stats:', error);
        });
}

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

function refreshUsers() {
    location.reload();
}

// Management card functions
function browseUsers() {
    // Redirect to user browsing page
    window.location.href = 'browse_users.php';
}

function bulkUserActions() {
    // Redirect to bulk actions page
    window.location.href = 'bulk_user_actions.php';
}

function userManagement() {
    // Redirect to user management page
    window.location.href = 'user_management_advanced.php';
}

function userDefaultPreferences() {
    // Redirect to user preferences page
    window.location.href = 'user_preferences.php';
}

function userProfileFields() {
    // Redirect to profile fields page
    window.location.href = 'user_profile_fields.php';
}

function manageCohorts() {
    // Redirect to cohorts management page
    window.location.href = 'cohorts_management.php';
}

function cohortCustomFields() {
    // Redirect to cohort custom fields page
    window.location.href = 'cohort_custom_fields.php';
}

function iomadMergeAccounts() {
    // Redirect to IOMAD merge accounts page
    window.location.href = 'iomad_merge_accounts.php';
}

function seeMergingLogs() {
    // Redirect to merging logs page
    window.location.href = 'merging_logs.php';
}

function uploadUsers() {
    // Redirect to upload users page
    window.location.href = 'upload_users.php';
}

function uploadUserPictures() {
    // Redirect to upload pictures page
    window.location.href = 'upload_user_pictures.php';
}

function showMessage(message, type) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    
    const container = document.querySelector('.user-management-container');
    container.insertBefore(messageDiv, container.firstChild);
    
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

// Grid layout initialization
function initGrids() {
    console.log('User management grid initialized');
}

// Initialize grids when page loads
document.addEventListener('DOMContentLoaded', function() {
    initGrids();
});
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>


