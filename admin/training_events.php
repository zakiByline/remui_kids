<?php
require_once('../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/theme/remui_kids/admin/training_events.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Training Events');
$PAGE->set_heading('Training Events');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_events':
            // For demo purposes, return sample events since we don't have training_events table
            $events = [
                (object)[
                    'id' => 1,
                    'title' => 'Introduction to Web Development',
                    'description' => 'Learn the basics of HTML, CSS, and JavaScript',
                    'start_date' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'end_date' => time() + (8 * 24 * 60 * 60), // 8 days from now
                    'max_participants' => 30,
                    'location' => 'Computer Lab A',
                    'instructor' => 'John Smith',
                    'status' => 'approved',
                    'created_by' => $USER->id,
                    'created_date' => time() - (2 * 24 * 60 * 60), // 2 days ago
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'email' => $USER->email,
                    'registrations_count' => 15
                ],
                (object)[
                    'id' => 2,
                    'title' => 'Database Management Workshop',
                    'description' => 'Advanced SQL and database optimization techniques',
                    'start_date' => time() + (14 * 24 * 60 * 60), // 14 days from now
                    'end_date' => time() + (15 * 24 * 60 * 60), // 15 days from now
                    'max_participants' => 20,
                    'location' => 'Conference Room B',
                    'instructor' => 'Sarah Johnson',
                    'status' => 'pending',
                    'created_by' => $USER->id,
                    'created_date' => time() - (1 * 24 * 60 * 60), // 1 day ago
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'email' => $USER->email,
                    'registrations_count' => 8
                ],
                (object)[
                    'id' => 3,
                    'title' => 'Project Management Fundamentals',
                    'description' => 'Essential project management skills and tools',
                    'start_date' => time() + (21 * 24 * 60 * 60), // 21 days from now
                    'end_date' => time() + (22 * 24 * 60 * 60), // 22 days from now
                    'max_participants' => 25,
                    'location' => 'Training Center',
                    'instructor' => 'Mike Davis',
                    'status' => 'approved',
                    'created_by' => $USER->id,
                    'created_date' => time() - (3 * 24 * 60 * 60), // 3 days ago
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'email' => $USER->email,
                    'registrations_count' => 22
                ]
            ];
            
            // Filter by status if specified
            $status = $_GET['status'] ?? 'all';
            if ($status !== 'all') {
                $events = array_filter($events, function($event) use ($status) {
                    return $event->status === $status;
                });
            }
            
            // Filter by search if specified
            $search = $_GET['search'] ?? '';
            if (!empty($search)) {
                $events = array_filter($events, function($event) use ($search) {
                    return stripos($event->title, $search) !== false || 
                           stripos($event->description, $search) !== false;
                });
            }
            
            echo json_encode([
                'status' => 'success',
                'events' => array_values($events)
            ]);
            exit;
            
        case 'create_event':
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            try {
                $required_fields = ['title', 'description', 'start_date', 'end_date', 'max_participants'];
                foreach ($required_fields as $field) {
                    if (empty($data[$field])) {
                        throw new Exception("Field {$field} is required");
                    }
                }
                
                // For demo purposes, simulate event creation
                // In a real implementation, you would insert into training_events table
                $event_id = rand(1000, 9999); // Generate a random ID for demo
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Training event created successfully',
                    'event_id' => $event_id
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'update_event_status':
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            try {
                $event_id = $data['event_id'] ?? null;
                $status = $data['status'] ?? null;
                $comments = $data['comments'] ?? '';
                
                if (!$event_id || !$status) {
                    throw new Exception("Event ID and status are required");
                }
                
                // For demo purposes, simulate status update
                // In a real implementation, you would update the training_events table
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Event status updated successfully'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'get_event_details':
            $event_id = $_GET['event_id'] ?? null;
            
            if (!$event_id) {
                echo json_encode(['status' => 'error', 'message' => 'Event ID required']);
                exit;
            }
            
            // For demo purposes, return sample event details
            // In a real implementation, you would query the training_events table
            $event = (object)[
                'id' => $event_id,
                'title' => 'Sample Training Event',
                'description' => 'This is a sample training event for demonstration purposes.',
                'start_date' => time() + (7 * 24 * 60 * 60),
                'end_date' => time() + (8 * 24 * 60 * 60),
                'max_participants' => 30,
                'location' => 'Training Center',
                'instructor' => 'John Smith',
                'status' => 'approved',
                'created_by' => $USER->id,
                'created_date' => time() - (2 * 24 * 60 * 60),
                'firstname' => $USER->firstname,
                'lastname' => $USER->lastname,
                'email' => $USER->email,
                'approved_by' => $USER->id,
                'approved_date' => time() - (1 * 24 * 60 * 60),
                'approver_firstname' => $USER->firstname,
                'approver_lastname' => $USER->lastname,
                'approval_comments' => 'Event approved for training purposes.'
            ];
            
            // Sample registrations
            $registrations = [
                (object)[
                    'id' => 1,
                    'user_id' => 1,
                    'event_id' => $event_id,
                    'registration_date' => time() - (1 * 24 * 60 * 60),
                    'firstname' => 'Alice',
                    'lastname' => 'Johnson',
                    'email' => 'alice.johnson@example.com',
                    'phone1' => '+1-555-0101'
                ],
                (object)[
                    'id' => 2,
                    'user_id' => 2,
                    'event_id' => $event_id,
                    'registration_date' => time() - (2 * 24 * 60 * 60),
                    'firstname' => 'Bob',
                    'lastname' => 'Smith',
                    'email' => 'bob.smith@example.com',
                    'phone1' => '+1-555-0102'
                ]
            ];
            
            echo json_encode([
                'status' => 'success',
                'event' => $event,
                'registrations' => array_values($registrations)
            ]);
            exit;
            
        case 'get_event_stats':
            // For demo purposes, return sample stats
            // In a real implementation, you would query the training_events table
            echo json_encode([
                'status' => 'success',
                'stats' => [
                    'total_events' => 3,
                    'pending_events' => 1,
                    'approved_events' => 2,
                    'rejected_events' => 0,
                    'total_registrations' => 45
                ]
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

// Admin Sidebar Navigation
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
echo $OUTPUT->render_from_template('theme_remui_kids/training_events', $template_data);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
