<?php
/**
 * Create User Page
 * Comprehensive user creation with validation and animations
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
        case 'create_user':
            try {
                $raw_input = file_get_contents('php://input');
                error_log("Raw input: " . $raw_input); // Debug log
                
                $data = json_decode($raw_input, true);
                error_log("Decoded data: " . print_r($data, true)); // Debug log
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON data: " . json_last_error_msg());
                }
                
                // Validate required fields
                $required_fields = ['username', 'email', 'firstname', 'lastname', 'password'];
                foreach ($required_fields as $field) {
                    if (empty($data[$field])) {
                        throw new Exception("Field {$field} is required");
                    }
                }
                
                // Check if username already exists
                if ($DB->record_exists('user', ['username' => $data['username']])) {
                    throw new Exception("Username '{$data['username']}' already exists. Please choose a different username.");
                }
                
                // Check if email already exists
                if ($DB->record_exists('user', ['email' => $data['email']])) {
                    throw new Exception("Email '{$data['email']}' already exists. Please use a different email address.");
                }
                
                // Create user object
                $user = new stdClass();
                $user->username = $data['username'];
                $user->email = $data['email'];
                $user->firstname = $data['firstname'];
                $user->lastname = $data['lastname'];
                $user->password = hash_internal_user_password($data['password']);
                $user->confirmed = 1;
                $user->mnethostid = 1;
                $user->timecreated = time();
                $user->timemodified = time();
                $user->lang = $data['lang'] ?? 'en';
                $user->timezone = $data['timezone'] ?? '99';
                $user->maildisplay = $data['maildisplay'] ?? 2;
                $user->mailformat = $data['mailformat'] ?? 1;
                $user->maildigest = $data['maildigest'] ?? 0;
                $user->autosubscribe = $data['autosubscribe'] ?? 1;
                $user->trackforums = $data['trackforums'] ?? 0;
                $user->deleted = 0;
                $user->suspended = 0;
                
                // Insert user
                error_log("Attempting to insert user: " . print_r($user, true)); // Debug log
                $userid = $DB->insert_record('user', $user);
                error_log("User ID returned: " . $userid); // Debug log
                
                if (!$userid) {
                    throw new Exception("Failed to create user");
                }
                
                // Assign role if specified
                if (!empty($data['role'])) {
                    $role = $DB->get_record('role', ['shortname' => $data['role']]);
                    if ($role) {
                        role_assign($role->id, $userid, $context->id);
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'User created successfully',
                    'userid' => $userid,
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'firstname' => $data['firstname'],
                    'lastname' => $data['lastname']
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'check_username':
            $username = $_GET['username'] ?? '';
            $exists = $DB->record_exists('user', ['username' => $username]);
            echo json_encode(['available' => !$exists]);
            exit;
            
        case 'check_email':
            $email = $_GET['email'] ?? '';
            $exists = $DB->record_exists('user', ['email' => $email]);
            echo json_encode(['available' => !$exists]);
            exit;
    }
}

// Set page context
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/create_user.php');
$PAGE->set_title('Create New User');
$PAGE->set_heading('Create New User');

// Get available roles
$roles = $DB->get_records('role', [], 'shortname ASC');

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

echo $OUTPUT->render_from_template('theme_remui_kids/create_user', $templatecontext);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
