<?php
require_once('../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/theme/remui_kids/admin/assign_school.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Assign Users to School');
$PAGE->set_heading('Assign Users to School');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'test':
            echo json_encode(['status' => 'success', 'message' => 'Test endpoint working']);
            exit;
        case 'get_school_users':
            $school_id = $_GET['school_id'] ?? null;
            $search = $_GET['search'] ?? '';
            $filter = $_GET['filter'] ?? '';
            
            if (!$school_id) {
                echo json_encode(['status' => 'error', 'message' => 'School ID is required']);
                exit;
            }
            
            try {
                $school_users = [];
                
                // Get users assigned to this company/school using company_users table
                $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.lastaccess, cu.managertype, cu.departmentid
                        FROM {user} u
                        JOIN {company_users} cu ON u.id = cu.userid
                        WHERE cu.companyid = ? AND u.deleted = 0 AND u.suspended = 0 AND cu.suspended = 0";
                $params = [$school_id];
                
                if (!empty($search)) {
                    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params ?? [], [$search_param, $search_param, $search_param, $search_param]);
                }
                
                $sql .= " ORDER BY u.firstname, u.lastname LIMIT 100";
                $school_users = $DB->get_records_sql($sql, $params);
                
                echo json_encode([
                    'status' => 'success',
                    'users' => array_values($school_users)
                ]);
            } catch (Exception $e) {
                error_log("Error in get_school_users: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_available_users':
            $school_id = $_GET['school_id'] ?? null;
            $search = $_GET['search'] ?? '';
            $filter = $_GET['filter'] ?? '';
            
            if (!$school_id) {
                echo json_encode(['status' => 'error', 'message' => 'School ID is required']);
                exit;
            }
            
            try {
                $available_users = [];
                
                // Get users NOT assigned to this company/school using company_users table
                $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.lastaccess
                        FROM {user} u
                        WHERE u.deleted = 0 AND u.suspended = 0
                        AND u.id NOT IN (
                            SELECT cu.userid FROM {company_users} cu 
                            WHERE cu.companyid = ? AND cu.suspended = 0
                        )";
                $params = [$school_id];
                
                if (!empty($search)) {
                    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params ?? [], [$search_param, $search_param, $search_param, $search_param]);
                }
                
                $sql .= " ORDER BY u.firstname, u.lastname LIMIT 100";
                $available_users = $DB->get_records_sql($sql, $params);
                
                echo json_encode([
                    'status' => 'success',
                    'users' => array_values($available_users)
                ]);
            } catch (Exception $e) {
                error_log("Error in get_available_users: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_schools':
            try {
                // Check if company table exists
                if (!$DB->get_manager()->table_exists('company')) {
                    // If no company table, use course categories as schools
                    $schools = $DB->get_records('course_categories', ['parent' => 0], 'name ASC', 'id, name, idnumber as code');
                    foreach ($schools as $school) {
                        $school->address = '';
                        $school->phone = '';
                        $school->email = '';
                    }
                } else {
                    // Get schools from mdl_company table
                    $schools = $DB->get_records('company', null, 'name ASC', 'id, name, shortname, address, city, region, postcode, country');
                    // Add code field from shortname for compatibility
                    foreach ($schools as $school) {
                        $school->code = $school->shortname;
                        $school->phone = '';
                        $school->email = '';
                    }
                }
                
                // If no companies exist, create a default "General" school
                if (empty($schools)) {
                    $schools = [
                        (object)[
                            'id' => 1,
                            'name' => 'General School',
                            'code' => 'GEN001',
                            'address' => '',
                            'phone' => '',
                            'email' => ''
                        ]
                    ];
                }
                
                echo json_encode(['status' => 'success', 'schools' => array_values($schools)]);
            } catch (Exception $e) {
                error_log("Error in get_schools: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_school_details':
            $school_id = $_GET['school_id'] ?? null;
            
            if (!$school_id) {
                echo json_encode(['status' => 'error', 'message' => 'School ID is required']);
                exit;
            }
            
            // Log the school_id for debugging
            error_log("get_school_details called with school_id: " . $school_id);
            
            try {
                // Get school details from mdl_company table or course_categories
                if ($DB->get_manager()->table_exists('company')) {
                    $school = $DB->get_record('company', ['id' => $school_id], 'id, name, shortname, address, city, region, postcode, country');
                    if ($school) {
                        $school->code = $school->shortname;
                        $school->phone = '';
                        $school->email = '';
                    }
                } else {
                    // Check if the course category exists before trying to fetch it
                    if ($DB->record_exists('course_categories', ['id' => $school_id])) {
                        $school = $DB->get_record('course_categories', ['id' => $school_id], 'id, name, idnumber as code');
                        if ($school) {
                            $school->address = '';
                            $school->phone = '';
                            $school->email = '';
                        }
                    } else {
                        // Return error if course category doesn't exist
                        echo json_encode(['status' => 'error', 'message' => 'Course category with ID ' . $school_id . ' not found']);
                        exit;
                    }
                }
                
                if (!$school) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found']);
                    exit;
                }
                
                echo json_encode(['status' => 'success', 'school' => $school]);
            } catch (Exception $e) {
                error_log("Error in get_school_details: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'assign_users':
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            try {
                $user_ids = $data['user_ids'] ?? [];
                $school_id = $data['school_id'] ?? null;
                $action = $data['action'] ?? 'add';
                
                if (empty($user_ids) || !$school_id) {
                    throw new Exception("User IDs and School ID are required");
                }
                
                $processed_count = 0;
                $errors = [];
                
                foreach ($user_ids as $user_id) {
                    // Check if user exists
                    if (!$DB->record_exists('user', ['id' => $user_id, 'deleted' => 0])) {
                        $errors[] = "User ID {$user_id} not found";
                        continue;
                    }
                    
                    if ($action === 'add') {
                        // Add user to company/school using company_users table
                        // Get the top-level department for this company
                        $top_department = $DB->get_record('department', ['company' => $school_id, 'parent' => 0]);
                        if (!$top_department) {
                            $errors[] = "No top-level department found for company {$school_id}";
                            continue;
                        }
                        
                        // Check if already assigned
                        if (!$DB->record_exists('company_users', ['userid' => $user_id, 'companyid' => $school_id, 'departmentid' => $top_department->id])) {
                            $assignment = new stdClass();
                            $assignment->userid = $user_id;
                            $assignment->companyid = $school_id;
                            $assignment->departmentid = $top_department->id;
                            $assignment->managertype = 0; // Regular user (0 - User, 1 - Company manager, 2 - Department manager)
                            $assignment->suspended = 0;
                            $assignment->educator = 0;
                            $assignment->lastused = time();
                            
                            if ($DB->insert_record('company_users', $assignment)) {
                                $processed_count++;
                            } else {
                                $errors[] = "Failed to assign user {$user_id} to school";
                            }
                        }
                    } else {
                        // Remove user from company/school
                        if ($DB->delete_records('company_users', ['userid' => $user_id, 'companyid' => $school_id])) {
                            $processed_count++;
                        } else {
                            $errors[] = "Failed to remove user {$user_id} from school";
                        }
                    }
                }
                
                $action_text = $action === 'add' ? 'added to' : 'removed from';
                $message = "Successfully {$action_text} {$processed_count} users to/from school";
                
                echo json_encode([
                    'status' => 'success',
                    'message' => $message,
                    'processed_count' => $processed_count,
                    'errors' => $errors
                ]);
                
            } catch (Exception $e) {
                error_log("Error in assign_users: " . $e->getMessage());
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'get_user_schools':
            $user_id = $_GET['user_id'] ?? null;
            
            if (!$user_id) {
                echo json_encode(['status' => 'error', 'message' => 'User ID required']);
                exit;
            }
            
            // For demo purposes, return empty array since we don't have user_school table
            // In a real implementation, you would query the user_school table
            echo json_encode([
                'status' => 'success',
                'schools' => []
            ]);
            exit;
    }
}

// Get template data
$template_data = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'user' => [
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
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

// Render template
echo $OUTPUT->render_from_template('theme_remui_kids/assign_school', $template_data);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
