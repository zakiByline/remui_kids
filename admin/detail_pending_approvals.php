<?php
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE = new moodle_page();
$PAGE->set_url('/theme/remui_kids/admin/detail_pending_approvals.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Pending Approvals Details');
$PAGE->set_heading('Pending Approvals Details');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_pending_approvals':
            $page = $_GET['page'] ?? 1;
            $per_page = $_GET['per_page'] ?? 20;
            $search = $_GET['search'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';
            
            $offset = ($page - 1) * $per_page;
            
            try {
                // Get pending training event approvals from trainingevent_users table
                $sql = "SELECT te.id, te.name as title, te.startdatetime, te.enddatetime, 
                               te.coursecapacity, te.approvaltype, teu.id as approval_id,
                               teu.userid, teu.approved, teu.waitlisted, teu.booking_notes,
                               u.firstname, u.lastname, u.email, u.username,
                               c.fullname as course_name
                        FROM {trainingevent} te
                        JOIN {trainingevent_users} teu ON te.id = teu.trainingeventid
                        JOIN {user} u ON teu.userid = u.id
                        JOIN {course} c ON te.course = c.id
                        WHERE teu.approved = 0 AND u.deleted = 0";
                
                $params = [];
                
                if (!empty($search)) {
                    $sql .= " AND (te.name LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = array_merge($params ?? [], [$search_param, $search_param, $search_param, $search_param]);
                }
                
                $sql .= " ORDER BY te.{$sort} {$order} LIMIT {$per_page} OFFSET {$offset}";
                
                $events = $DB->get_records_sql($sql, $params);
                
                // Get total count
                $count_sql = "SELECT COUNT(*) FROM {trainingevent} te
                              JOIN {trainingevent_users} teu ON te.id = teu.trainingeventid
                              JOIN {user} u ON teu.userid = u.id
                              WHERE teu.approved = 0 AND u.deleted = 0";
                $count_params = [];
                if (!empty($search)) {
                    $count_sql .= " AND (te.name LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
                    $count_params = [$search_param, $search_param, $search_param, $search_param];
                }
                $total_count = $DB->count_records_sql($count_sql, $count_params);
                
                echo json_encode([
                    'status' => 'success',
                    'events' => array_values($events),
                    'total_count' => $total_count,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => ceil($total_count / $per_page)
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_approval_stats':
            try {
                // Get real approval statistics
                $pending_approvals = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {trainingevent_users} WHERE approved = 0"
                );
                
                $approved_this_week = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {trainingevent_users} 
                     WHERE approved = 1 AND timecreated > ?",
                    [strtotime('1 week ago')]
                );
                
                $rejected_this_week = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {trainingevent_users} 
                     WHERE approved = 2 AND timecreated > ?",
                    [strtotime('1 week ago')]
                );
                
                $total_events = $DB->count_records('trainingevent');
                
                echo json_encode([
                    'status' => 'success',
                    'stats' => [
                        'pending_approvals' => $pending_approvals,
                        'approved_this_week' => $approved_this_week,
                        'rejected_this_week' => $rejected_this_week,
                        'total_events' => $total_events
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'approve_event':
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            try {
                $approval_id = $data['approval_id'] ?? null;
                $action = $data['action'] ?? 'approve'; // approve or reject
                
                if (!$approval_id) {
                    throw new Exception('Approval ID is required');
                }
                
                $approval_status = ($action === 'approve') ? 1 : 2; // 1 = approved, 2 = rejected
                
                $result = $DB->set_field('trainingevent_users', 'approved', $approval_status, ['id' => $approval_id]);
                
                if ($result) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Event ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully'
                    ]);
                } else {
                    throw new Exception('Failed to update approval status');
                }
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// Render the page
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

$template_data = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->render_from_template('theme_remui_kids/detail_pending_approvals', $template_data);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
