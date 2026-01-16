<?php
/**
 * Edit Teacher Page - Beautiful animated page for editing teacher details
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE;

// Get teacher ID from URL
$teacher_id = optional_param('id', 0, PARAM_INT);

if (!$teacher_id) {
    header('Location: teachers_list.php');
    exit;
}

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/edit_teacher.php', ['id' => $teacher_id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Edit Teacher');
$PAGE->set_heading('Edit Teacher');
$PAGE->set_pagelayout('admin');

// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

// Get teacher data
$teacher = $DB->get_record('user', ['id' => $teacher_id]);
if (!$teacher) {
    header('Location: teachers_list.php');
    exit;
}

// Handle form submission
if ($_POST) {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    
    if ($firstname && $lastname && $email && $username) {
        $teacher->firstname = $firstname;
        $teacher->lastname = $lastname;
        $teacher->email = $email;
        $teacher->username = $username;
        $teacher->timemodified = time();
        
        if ($DB->update_record('user', $teacher)) {
            $success_message = "Teacher updated successfully!";
        } else {
            $error_message = "Failed to update teacher. Please try again.";
        }
    } else {
        $error_message = "All fields are required.";
    }
}

echo $OUTPUT->header();

// Add custom CSS with animations
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #E8DFFF 0%, #DCD0FF 100%);
        min-height: 100vh;
        padding: 20px;
    }
    
    /* Sidebar Styles */
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
        border-left-color: #9D8DF1;
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
        background-color: #E8DFFF;
        color: #7C73E6;
        border-left-color: #9D8DF1;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #9D8DF1;
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

    /* Main content area with sidebar */
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
    
    .edit-container {
        max-width: 1200px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
    }
    
    .edit-header {
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
        color: #5B4E9E;
        padding: 40px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .edit-header::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    }
    
    .edit-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 10px;
        position: relative;
        z-index: 1;
        color: #5B4E9E;
    }
    
    .edit-subtitle {
        font-size: 1.1rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
        color: #7C73E6;
    }
    
    .teacher-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: bold;
        margin: 20px auto;
        border: 3px solid rgba(255, 255, 255, 0.6);
        position: relative;
        z-index: 1;
        color: #7C73E6;
    }
    
    .edit-form {
        padding: 40px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .form-group {
        position: relative;
        margin-bottom: 30px;
    }
    
    .form-label {
        display: block;
        font-weight: 600;
        color: #7C73E6;
        margin-bottom: 8px;
        font-size: 0.95rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .form-control {
        width: 100%;
        padding: 15px 20px;
        border: 2px solid #DCD0FF;
        border-radius: 12px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: #f8fafc;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #9D8DF1;
        background: white;
        box-shadow: 0 0 0 3px rgba(157, 141, 241, 0.1);
        transform: translateY(-2px);
    }
    
    .form-control:hover {
        border-color: #C5B4E3;
        background: white;
    }
    
    .button-group {
        display: flex;
        gap: 20px;
        justify-content: center;
        margin-top: 40px;
    }
    
    .btn {
        padding: 15px 30px;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 150px;
        justify-content: center;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #9D8DF1 0%, #7C73E6 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(157, 141, 241, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(157, 141, 241, 0.4);
    }
    
    .btn-secondary {
        background: #ffffff;
        color: #7C73E6;
        border: 2px solid #DCD0FF;
    }
    
    .btn-secondary:hover {
        background: #E8DFFF;
        transform: translateY(-2px);
    }
    
    .alert {
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 30px;
        font-weight: 500;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        color: white;
    }
    
    .alert-error {
        background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        color: white;
    }
    
    .breadcrumb {
        background: rgba(255, 255, 255, 0.3);
        padding: 15px 30px;
        border-radius: 12px;
        margin-bottom: 20px;
        backdrop-filter: blur(10px);
    }
    
    .breadcrumb a {
        color: #7C73E6;
        text-decoration: none;
        transition: color 0.3s ease;
        font-weight: 500;
    }
    
    .breadcrumb a:hover {
        color: #9D8DF1;
    }
    
    .breadcrumb-item {
        color: #5B4E9E;
        font-weight: 600;
    }
    
    .floating-elements {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: -1;
    }
    
    .floating-circle {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
    }
    
    .floating-circle:nth-child(1) {
        width: 80px;
        height: 80px;
        top: 20%;
        left: 10%;
    }
    
    .floating-circle:nth-child(2) {
        width: 120px;
        height: 120px;
        top: 60%;
        right: 10%;
    }
    
    .floating-circle:nth-child(3) {
        width: 60px;
        height: 60px;
        bottom: 20%;
        left: 20%;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .edit-title {
            font-size: 2rem;
        }
        
        .button-group {
            flex-direction: column;
            align-items: center;
        }
        
        .btn {
            width: 100%;
            max-width: 300px;
        }
    }
</style>";

// Floating background elements
echo "<div class='floating-elements'>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "</div>";

// Admin Sidebar Navigation
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

echo "<div class='edit-container'>";
echo "<div class='edit-header'>";
echo "<div class='breadcrumb'>";

echo "</div>";

echo "<div class='teacher-avatar'>";
echo strtoupper(substr($teacher->firstname, 0, 1));
echo "</div>";

echo "<h1 class='edit-title'>Edit Teacher</h1>";
echo "<p class='edit-subtitle'>Update teacher information and details</p>";
echo "</div>";

echo "<div class='edit-form'>";

// Show success/error messages
if (isset($success_message)) {
    echo "<div class='alert alert-success'>";
    echo "<i class='fa fa-check-circle'></i> $success_message";
    echo "</div>";
}

if (isset($error_message)) {
    echo "<div class='alert alert-error'>";
    echo "<i class='fa fa-exclamation-circle'></i> $error_message";
    echo "</div>";
}

echo "<form method='POST' action=''>";
echo "<div class='form-row'>";
echo "<div class='form-group'>";
echo "<label class='form-label'>First Name</label>";
echo "<input type='text' class='form-control' name='firstname' value='" . htmlspecialchars($teacher->firstname) . "' required>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label class='form-label'>Last Name</label>";
echo "<input type='text' class='form-control' name='lastname' value='" . htmlspecialchars($teacher->lastname) . "' required>";
echo "</div>";
echo "</div>";

echo "<div class='form-row'>";
echo "<div class='form-group'>";
echo "<label class='form-label'>Username</label>";
echo "<input type='text' class='form-control' name='username' value='" . htmlspecialchars($teacher->username) . "' required>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label class='form-label'>Email Address</label>";
echo "<input type='email' class='form-control' name='email' value='" . htmlspecialchars($teacher->email) . "' required>";
echo "</div>";
echo "</div>";

echo "<div class='button-group'>";
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='fa fa-save'></i> Update Teacher";
echo "</button>";
echo "<a href='teachers_list.php' class='btn btn-secondary'>";
echo "<i class='fa fa-arrow-left'></i> Back to Teachers";
echo "</a>";
echo "</div>";

echo "</form>";
echo "</div>";
echo "</div>";

// Add JavaScript for sidebar toggle
echo "<script>
// Sidebar toggle function
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && toggleBtn && !toggleBtn.contains(event.target)) {
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

// Close admin-main-content div
echo "</div>";

echo $OUTPUT->footer();
?>
