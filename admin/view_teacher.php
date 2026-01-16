<?php
/**
 * View Teacher Page - Beautiful animated page for viewing teacher profile
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
$PAGE->set_url('/theme/remui_kids/admin/view_teacher.php', ['id' => $teacher_id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Teacher Profile');
$PAGE->set_heading('Teacher Profile');
$PAGE->set_pagelayout('admin');

// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

// Get teacher data
$teacher = $DB->get_record('user', ['id' => $teacher_id]);
if (!$teacher) {
    header('Location: teachers_list.php');
    exit;
}

// Get teacher role assignment info
$role_info = $DB->get_record_sql(
    "SELECT 
        r.name as role_name,
        r.shortname as role_shortname,
        FROM_UNIXTIME(ra.timemodified) as assigned_date,
        ra.timemodified as role_timestamp
     FROM {role_assignments} ra
     JOIN {role} r ON ra.roleid = r.id
     JOIN {context} ctx ON ra.contextid = ctx.id
     WHERE ra.userid = ? AND ctx.contextlevel = ? AND r.shortname = 'teachers'",
    [$teacher_id, CONTEXT_SYSTEM]
);


// Get recent activity
$recent_activity = $DB->get_records_sql(
    "SELECT 
        'login' as type,
        'Last Login' as description,
        FROM_UNIXTIME(lastaccess) as activity_date,
        lastaccess as timestamp
     FROM {user}
     WHERE id = ? AND lastaccess > 0
     UNION ALL
     SELECT 
        'role' as type,
        'Role Assigned' as description,
        FROM_UNIXTIME(ra.timemodified) as activity_date,
        ra.timemodified as timestamp
     FROM {role_assignments} ra
     WHERE ra.userid = ? AND ra.roleid = (
         SELECT id FROM {role} WHERE shortname = 'teachers'
     )
     ORDER BY timestamp DESC
     LIMIT 5",
    [$teacher_id, $teacher_id]
);

echo $OUTPUT->header();

// Add custom CSS with animations
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
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
    
    .view-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    
    .profile-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin-bottom: 30px;
        position: relative;
    }
    
    .header-background {
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
        height: 200px;
        position: relative;
        overflow: hidden;
    }
    
    .header-background::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    }
    
    .profile-content {
        padding: 40px;
        position: relative;
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
    
    .profile-info {
        display: grid;
        grid-template-columns: 200px 1fr;
        gap: 40px;
        align-items: start;
    }
    
    .profile-avatar {
        text-align: center;
        position: relative;
    }
    
    .avatar-container {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 4rem;
        font-weight: bold;
        color: white;
        margin: 0 auto 20px;
        border: 5px solid white;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        position: relative;
        overflow: hidden;
    }
    
    .avatar-container::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
    }
    
    .status-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-active {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        color: white;
    }
    
    .status-suspended {
        background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        color: white;
    }
    
    .profile-details {
    }
    
    .profile-name {
        font-size: 2.5rem;
        font-weight: 800;
        color: #2d3748;
        margin-bottom: 10px;
        background: linear-gradient(135deg, #9D8DF1 0%, #7C73E6 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    .profile-title {
        font-size: 1.2rem;
        color: #7C73E6;
        margin-bottom: 20px;
        font-weight: 500;
    }
    
    .profile-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px;
        background: #f8fafc;
        border-radius: 12px;
        border-left: 4px solid #9D8DF1;
        transition: all 0.3s ease;
    }
    
    .meta-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .meta-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.2rem;
    }
    
    .meta-content {
        flex: 1;
    }
    
    .meta-label {
        font-size: 0.85rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    
    .meta-value {
        font-size: 1rem;
        color: #2d3748;
        font-weight: 600;
        margin-top: 2px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #C5B4E3 0%, #B8B5FF 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        margin: 0 auto 20px;
    }
    
    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: #7C73E6;
        margin-bottom: 10px;
    }
    
    .stat-label {
        font-size: 1rem;
        color: #9D8DF1;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .activity-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #7C73E6;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .activity-list {
        list-style: none;
    }
    
    .activity-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 0;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .activity-item:last-child {
        border-bottom: none;
    }
    
    .activity-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1rem;
    }
    
    .activity-content {
        flex: 1;
    }
    
    .activity-title {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 2px;
    }
    
    .activity-date {
        font-size: 0.9rem;
        color: #6b7280;
    }
    
    .action-buttons {
        display: flex;
        gap: 15px;
        justify-content: center;
        margin-top: 30px;
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
    
    .btn-success {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    }
    
    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(72, 187, 120, 0.4);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(229, 62, 62, 0.3);
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(229, 62, 62, 0.4);
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
        width: 100px;
        height: 100px;
        top: 10%;
        left: 10%;
    }
    
    .floating-circle:nth-child(2) {
        width: 80px;
        height: 80px;
        top: 60%;
        right: 10%;
    }
    
    .floating-circle:nth-child(3) {
        width: 60px;
        height: 60px;
        bottom: 20%;
        left: 20%;
    }
    
    .floating-circle:nth-child(4) {
        width: 120px;
        height: 120px;
        top: 30%;
        right: 30%;
    }
    
    .confirmation-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(197, 180, 227, 0.85) 0%, rgba(184, 181, 255, 0.85) 100%);
        backdrop-filter: blur(10px);
    }
    .modal-content {
        background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
        margin: 5% auto;
        padding: 0;
        border: none;
        border-radius: 24px;
        width: 90%;
        max-width: 500px;
        box-shadow: 
            0 30px 100px rgba(0, 0, 0, 0.3),
            0 0 0 1px rgba(255, 255, 255, 0.1),
            inset 0 1px 0 rgba(255, 255, 255, 0.2);
        overflow: hidden;
        position: relative;
        transform-style: preserve-3d;
    }
    .modal-content::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #C5B4E3 0%, #B8B5FF 50%, #C5B4E3 100%);
        background-size: 200% 100%;
    }
    .modal-body {
        padding: 40px 45px 35px;
        text-align: center;
        position: relative;
    }
    .modal-message {
        font-size: 1.2rem;
        color: #2d3748;
        margin-bottom: 0;
        line-height: 1.7;
        font-weight: 500;
        position: relative;
    }
    .modal-message::before {
        content: '⚠️';
        display: block;
        font-size: 3rem;
        margin-bottom: 20px;
    }
    .modal-footer {
        padding: 0 45px 40px;
        display: flex;
        gap: 20px;
        justify-content: center;
    }
    .modal-btn {
        padding: 16px 32px;
        border: none;
        border-radius: 16px;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        min-width: 140px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        letter-spacing: 0.5px;
        position: relative;
        overflow: hidden;
        text-transform: uppercase;
        font-size: 0.9rem;
    }
    .modal-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: left 0.6s;
    }
    .modal-btn:hover::before {
        left: 100%;
    }
    .modal-btn-secondary {
        background: linear-gradient(135deg, #ffffff 0%, #E8DFFF 100%);
        color: #7C73E6;
        border: 2px solid #DCD0FF;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    .modal-btn-secondary:hover {
        background: linear-gradient(135deg, #E8DFFF 0%, #DCD0FF 100%);
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 12px 35px rgba(157, 141, 241, 0.2);
    }
    .modal-btn-danger {
        background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        color: white;
        box-shadow: 0 6px 20px rgba(229, 62, 62, 0.4);
        border: 2px solid #e53e3e;
    }
    .modal-btn-danger:hover {
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 15px 40px rgba(229, 62, 62, 0.6);
        background: linear-gradient(135deg, #c53030 0%, #9c2626 100%);
    }
    .modal-btn-success {
        background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
        color: white;
        box-shadow: 0 6px 20px rgba(56, 161, 105, 0.4);
        border: 2px solid #38a169;
    }
    .modal-btn-success:hover {
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 15px 40px rgba(56, 161, 105, 0.6);
        background: linear-gradient(135deg, #2f855a 0%, #276749 100%);
    }
    
    @media (max-width: 768px) {
        .profile-info {
            grid-template-columns: 1fr;
            text-align: center;
        }
        
        .profile-meta {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
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
echo "<div class='floating-circle'></div>";
echo "</div>";

// Admin Sidebar Navigation
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

echo "<div class='view-container'>";

// Profile Header
echo "<div class='profile-header'>";
echo "<div class='header-background'>";
echo "<div class='breadcrumb'>";

echo "</div>";
echo "</div>";

echo "<div class='profile-content'>";
echo "<div class='profile-info'>";

// Avatar and Status
echo "<div class='profile-avatar'>";
echo "<div class='avatar-container'>";
echo strtoupper(substr($teacher->firstname, 0, 1));
echo "</div>";
$status_class = $teacher->suspended ? 'status-suspended' : 'status-active';
$status_text = $teacher->suspended ? 'Suspended' : 'Active';
echo "<span class='status-badge $status_class'>$status_text</span>";
echo "</div>";

// Profile Details
echo "<div class='profile-details'>";
echo "<h1 class='profile-name'>{$teacher->firstname} {$teacher->lastname}</h1>";
echo "<p class='profile-title'>Teacher • ID: {$teacher->id}</p>";

echo "<div class='profile-meta'>";
echo "<div class='meta-item'>";
echo "<div class='meta-icon'><i class='fa fa-user'></i></div>";
echo "<div class='meta-content'>";
echo "<div class='meta-label'>Username</div>";
echo "<div class='meta-value'>{$teacher->username}</div>";
echo "</div>";
echo "</div>";

echo "<div class='meta-item'>";
echo "<div class='meta-icon'><i class='fa fa-envelope'></i></div>";
echo "<div class='meta-content'>";
echo "<div class='meta-label'>Email</div>";
echo "<div class='meta-value'>{$teacher->email}</div>";
echo "</div>";
echo "</div>";

if ($role_info) {
    echo "<div class='meta-item'>";
    echo "<div class='meta-icon'><i class='fa fa-graduation-cap'></i></div>";
    echo "<div class='meta-content'>";
    echo "<div class='meta-label'>Role Assigned</div>";
    echo "<div class='meta-value'>{$role_info->assigned_date}</div>";
    echo "</div>";
    echo "</div>";
}

$last_access = $teacher->lastaccess ? date('M j, Y g:i A', $teacher->lastaccess) : 'Never';
echo "<div class='meta-item'>";
echo "<div class='meta-icon'><i class='fa fa-clock'></i></div>";
echo "<div class='meta-content'>";
echo "<div class='meta-label'>Last Access</div>";
echo "<div class='meta-value'>$last_access</div>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";

// Statistics Grid
echo "<div class='stats-grid'>";
echo "<div class='stat-card'>";
echo "<div class='stat-icon'><i class='fa fa-calendar'></i></div>";
echo "<div class='stat-number'>" . date('Y', $teacher->timecreated) . "</div>";
echo "<div class='stat-label'>Member Since</div>";
echo "</div>";
echo "</div>";

// Recent Activity
echo "<div class='activity-section'>";
echo "<h2 class='section-title'>";
echo "<i class='fa fa-history'></i> Recent Activity";
echo "</h2>";

if ($recent_activity) {
    echo "<ul class='activity-list'>";
    foreach ($recent_activity as $activity) {
        $icon = $activity->type === 'login' ? 'fa-sign-in-alt' : 'fa-user-plus';
        echo "<li class='activity-item'>";
        echo "<div class='activity-icon'><i class='fa $icon'></i></div>";
        echo "<div class='activity-content'>";
        echo "<div class='activity-title'>{$activity->description}</div>";
        echo "<div class='activity-date'>{$activity->activity_date}</div>";
        echo "</div>";
        echo "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='text-align: center; color: #6b7280; padding: 20px;'>No recent activity to display</p>";
}
echo "</div>";

// Action Buttons
echo "<div class='action-buttons'>";
echo "<a href='edit_teacher.php?id={$teacher->id}' class='btn btn-primary'>";
echo "<i class='fa fa-edit'></i> Edit Teacher";
echo "</a>";
echo "<a href='teachers_list.php' class='btn btn-secondary'>";
echo "<i class='fa fa-arrow-left'></i> Back to Teachers";
echo "</a>";
if (!$teacher->suspended) {
    echo "<button onclick='toggleTeacherStatus({$teacher->id}, true)' class='btn btn-danger'>";
    echo "<i class='fa fa-ban'></i> Suspend Teacher";
    echo "</button>";
} else {
    echo "<button onclick='toggleTeacherStatus({$teacher->id}, false)' class='btn btn-primary'>";
    echo "<i class='fa fa-check'></i> Activate Teacher";
    echo "</button>";
}
echo "</div>";

echo "</div>"; // End view-container

// Confirmation Modal
echo "<div id='confirmationModal' class='confirmation-modal'>";
echo "<div class='modal-content'>";
echo "<div class='modal-body'>";
echo "<p class='modal-message' id='modalMessage'>Are you sure you want to perform this action?</p>";
echo "</div>";
echo "<div class='modal-footer'>";
echo "<button class='modal-btn modal-btn-secondary' onclick='closeConfirmationModal()'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</button>";
echo "<button class='modal-btn modal-btn-danger' id='confirmBtn' onclick='confirmAction()'>";
echo "<i class='fa fa-check'></i> Confirm";
echo "</button>";
echo "</div>";
echo "</div>";
echo "</div>";

// JavaScript for status toggle
echo "<script>
// Global variables for modal
let currentUserId = null;
let currentAction = null;

function toggleTeacherStatus(userid, suspend) {
    currentUserId = userid;
    currentAction = suspend ? 'suspend' : 'activate';
    
    // Update modal content based on action
    const modalMessage = document.getElementById('modalMessage');
    const confirmBtn = document.getElementById('confirmBtn');
    
    if (suspend) {
        modalMessage.innerHTML = 'Are you sure you want to suspend this teacher?<br>They will not be able to access the system until reactivated.';
        confirmBtn.className = 'modal-btn modal-btn-danger';
        confirmBtn.innerHTML = '<i class=\"fa fa-ban\"></i> Suspend';
    } else {
        modalMessage.innerHTML = 'Are you sure you want to activate this teacher?<br>They will regain access to the system.';
        confirmBtn.className = 'modal-btn modal-btn-success';
        confirmBtn.innerHTML = '<i class=\"fa fa-check\"></i> Activate';
    }
    
    // Show modal
    document.getElementById('confirmationModal').style.display = 'block';
}

function closeConfirmationModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    currentUserId = null;
    currentAction = null;
}

function confirmAction() {
    if (!currentUserId) return;
    
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('userid', currentUserId);
    
    // Show loading state
    const confirmBtn = document.getElementById('confirmBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class=\"fa fa-spinner fa-spin\"></i> Processing...';
    confirmBtn.disabled = true;
    
    fetch('teachers_list.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            // Show success message briefly
            confirmBtn.innerHTML = '<i class=\"fa fa-check\"></i> Success!';
            confirmBtn.className = 'modal-btn modal-btn-success';
            
            setTimeout(() => {
                closeConfirmationModal();
                location.reload();
            }, 1500);
        } else {
            // Show error
            confirmBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
            confirmBtn.className = 'modal-btn modal-btn-danger';
            setTimeout(() => {
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            }, 2000);
        }
    })
    .catch(error => {
        confirmBtn.innerHTML = '<i class=\"fa fa-exclamation\"></i> Error';
        confirmBtn.className = 'modal-btn modal-btn-danger';
        setTimeout(() => {
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }, 2000);
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('confirmationModal');
    if (event.target === modal) {
        closeConfirmationModal();
    }
}

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

// Close admin-main-content div
echo "</div>";

echo $OUTPUT->footer();
?>
