<?php
/**
 * Teachers List Page - Display all teachers in a proper admin interface
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE;

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/teachers_list.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Teachers Management');
$PAGE->set_heading('Teachers Management');
$PAGE->set_pagelayout('admin');

// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

// Handle status toggle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    header('Content-Type: application/json');
    
    $userid = intval($_POST['userid']);
    if ($userid) {
        $user = $DB->get_record('user', ['id' => $userid]);
        if ($user) {
            $user->suspended = $user->suspended ? 0 : 1;
            if ($DB->update_record('user', $user)) {
                $status = $user->suspended ? 'suspended' : 'activated';
                echo json_encode(['status' => 'success', 'message' => "Teacher $status successfully"]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update teacher status']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid teacher ID']);
    }
    exit;
}

echo $OUTPUT->header();

// Add custom CSS for the teachers list with admin sidebar
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #f7f8fb;
        min-height: 100vh;
        overflow-x: hidden;
        color: #0f172a;
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
    
    .teachers-container {
        background: #ffffff;
        border-radius: 18px;
        box-shadow: 0 25px 50px rgba(15, 23, 42, 0.08);
        padding: 32px;
        margin: 24px 0 48px;
    }
    .teachers-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 36px;
        padding: 28px 32px;
        border-radius: 18px;
        background: linear-gradient(135deg, #e0f7ff 0%, #f3fff8 100%);
        border: 1px solid #e2e8f0;
    }
    .teachers-title {
        font-size: 30px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .teachers-subtitle {
        color: #475569;
        margin: 8px 0 0 0;
        font-size: 16px;
    }
    .teachers-stats {
        display: flex;
        gap: 16px;
    }
    .stat-item {
        text-align: right;
        min-width: 90px;
    }
    .stat-number {
        font-size: 26px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .stat-label {
        font-size: 13px;
        color: #94a3b8;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .teachers-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 12px;
        margin-top: 12px;
    }
    .teachers-table thead {
        background: #eef4ff;
        border-radius: 12px;
    }
    .teachers-table thead th {
        font-size: 12px;
        font-weight: 700;
        color: #1d4ed8;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 12px 16px;
        border: none;
    }
    .teachers-table tbody tr {
        background: #fdfdfd;
        box-shadow: 0 15px 35px rgba(15, 23, 42, 0.06);
        border-radius: 16px;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }
    .teachers-table tbody tr:hover {
        transform: translateY(-4px);
        background: #ffffff;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
    }
    .teachers-table tbody tr td:first-child {
        border-top-left-radius: 16px;
        border-bottom-left-radius: 16px;
    }
    .teachers-table tbody tr td:last-child {
        border-top-right-radius: 16px;
        border-bottom-right-radius: 16px;
    }
    .teachers-table td {
        padding: 18px 16px;
        border-bottom: none;
        vertical-align: middle;
        color: #0f172a;
    }
    .teachers-table.is-loading tbody {
       
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    .teachers-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-direction: column;
        gap: 10px;
        margin-top: 25px;
        flex-wrap: wrap;
    }
    .teachers-pagination.is-loading {
        opacity: 0.6;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    .teachers-pagination-info {
        font-size: 0.9rem;
        color: #6b7280;
        margin-bottom: 4px;
        text-align: center;
        width: 100%;
    }
    .teachers-pagination-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .teachers-pagination-buttons .pagination-button {
        padding: 10px 16px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #0369a1;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        min-width: 40px;
        text-align: center;
    }
    .teachers-pagination-buttons .pagination-button:hover:not(.disabled):not(.active) {
        background: #e0f2fe;
        border-color: #bae6fd;
        color: #0369a1;
    }
    .teachers-pagination-buttons .pagination-button.active {
        background: #0369a1;
        color: #fff;
        border-color: #0369a1;
        box-shadow: 0 8px 20px rgba(3, 105, 161, 0.2);
    }
    .teachers-pagination-buttons .pagination-button.disabled {
        color: #a0aec0;
        background: #f8fafc;
        pointer-events: none;
    }
    .teacher-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .teacher-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #eef2ff;
        color: #4338ca;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 16px;
    }
    .teacher-name {
        font-weight: 600;
        color: #0f172a;
        margin: 0;
        font-size: 15px;
    }
    .teacher-email {
        color: #94a3b8;
        font-size: 13px;
        margin: 2px 0 0;
    }
    .role-badge {
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        background: #e0edff;
        color: #1d4ed8;
        border: none;
    }
    .status-badge {
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        text-transform: capitalize;
    }
    .status-active {
        background: #dcfce7;
        color: #15803d;
    }
    .status-suspended {
        background: #fee2e2;
        color: #b91c1c;
    }
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    .btn {
        padding: 8px 14px;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .btn:hover {
        transform: translateY(-1px);
    }
    .btn-view {
        background: #eaf2ff;
        color: #3b82f6;
    }
    .btn-view i {
        color: #60a5fa;
    }
    .btn-edit {
        background: #f5f6f8;
        color: #6b7280;
    }
    .btn-edit i {
        color: #9ca3af;
    }
    .btn-suspend {
        background: #ffe7eb;
        color: #f87171;
    }
    .btn-suspend i {
        color: #fca5a5;
    }
    .btn-activate {
        background: #ecfdf5;
        color: #059669;
    }
    .btn-activate i {
        color: #34d399;
    }
    .search-filter-bar {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 24px;
        padding: 18px 22px;
        background: #f1f5f9;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
    }
    .search-box {
        flex: 1;
        min-width: 360px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .search-input {
        flex: 1;
        padding: 12px 16px;
        border: 1px solid #cbd5f5;
        border-radius: 12px;
        background: #fff;
        font-size: 14px;
        color: #0f172a;
    }
    .filter-select {
        padding: 12px 14px;
        border: 1px solid #cbd5f5;
        border-radius: 12px;
        background: #fff;
        font-size: 14px;
        color: #0f172a;
        min-width: 160px;
    }
    .filter-submit {
        background: #0d6efd;
        color: #fff;
        padding: 12px 20px;
        border-radius: 999px;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        box-shadow: 0 12px 24px rgba(13, 110, 253, 0.25);
    }
    .filter-submit i {
        font-size: 0.9rem;
    }
    .filter-submit:hover {
        transform: translateY(-1px);
    }
    .add-teacher-btn {
        padding: 14px 26px;
        border-radius: 999px;
        background: #136ef6;
        color: #fff;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 15px 25px rgba(19, 110, 246, 0.3);
    }
    .add-teacher-btn:hover {
        transform: translateY(-2px);
        color: #fff;
    }
    .no-teachers {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    .no-teachers i {
        font-size: 48px;
        margin-bottom: 20px;
        color: #dee2e6;
    }
    .breadcrumb {
        background: none;
        padding: 0;
        margin-bottom: 20px;
    }
    .breadcrumb-item {
        color: #6c757d;
    }
    .breadcrumb-item.active {
        color: #2c3e50;
        font-weight: 600;
    }
    .confirmation-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.8) 0%, rgba(118, 75, 162, 0.8) 100%);
        backdrop-filter: blur(10px);
        animation: modalFadeIn 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
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
        animation: modalSlideIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
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
        background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #667eea 100%);
        background-size: 200% 100%;
        animation: shimmer 2s ease-in-out infinite;
    }
    @keyframes modalFadeIn {
        0% { 
            opacity: 0;
            backdrop-filter: blur(0px);
        }
        100% { 
            opacity: 1;
            backdrop-filter: blur(10px);
        }
    }
    @keyframes modalSlideIn {
        0% {
            opacity: 0;
            transform: translateY(-100px) scale(0.8) rotateX(20deg);
        }
        50% {
            opacity: 0.8;
            transform: translateY(10px) scale(1.02) rotateX(-5deg);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1) rotateX(0deg);
        }
    }
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
        40% { transform: translateY(-10px); }
        60% { transform: translateY(-5px); }
    }
    @keyframes bodySlideIn {
        0% {
            opacity: 0;
            transform: translateY(30px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    @keyframes messageFadeIn {
        0% {
            opacity: 0;
            transform: scale(0.8);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }
    @keyframes footerSlideUp {
        0% {
            opacity: 0;
            transform: translateY(20px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .modal-body {
        padding: 40px 45px 35px;
        text-align: center;
        position: relative;
        animation: bodySlideIn 0.8s ease-out 0.2s both;
    }
    .modal-message {
        font-size: 1.2rem;
        color: #2d3748;
        margin-bottom: 0;
        line-height: 1.7;
        font-weight: 500;
        position: relative;
        animation: messageFadeIn 1s ease-out 0.4s both;
    }
    .modal-message::before {
        content: '⚠️';
        display: block;
        font-size: 3rem;
        margin-bottom: 20px;
        animation: bounce 2s infinite;
    }
    .modal-footer {
        padding: 0 45px 40px;
        display: flex;
        gap: 20px;
        justify-content: center;
        animation: footerSlideUp 0.8s ease-out 0.6s both;
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
    .modal-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    .modal-btn-primary:hover {
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 12px 35px rgba(102, 126, 234, 0.5);
    }
    .modal-btn-secondary {
        background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
        color: #4a5568;
        border: 2px solid #e2e8f0;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    .modal-btn-secondary:hover {
        background: linear-gradient(135deg, #edf2f7 0%, #e2e8f0 100%);
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
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
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

try {
    // Get teacher roles (both 'editingteacher' and 'teacher')
    $teacherroles = $DB->get_records_sql(
        "SELECT * FROM {role} WHERE shortname IN ('editingteacher', 'teacher')"
    );
    
    if (empty($teacherroles)) {
        echo "<div class='alert alert-warning'>";
        echo "<h4>⚠️ Teacher Roles Not Found</h4>";
        echo "<p>The 'editingteacher' and 'teacher' roles do not exist in your system. Please create them first.</p>";
        echo "</div>";
        echo "</div>"; // End admin-main-content
        echo $OUTPUT->footer();
        exit;
    }
    
    // Get role IDs for the SQL query
    $role_ids = array_column($teacherroles, 'id');
    $role_ids_placeholder = implode(',', array_fill(0, count($role_ids), '?'));
    
    // Get all schools for the filter dropdown
    $schools = [];
    if ($DB->get_manager()->table_exists('company')) {
        $schools = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.name 
             FROM {company} c
             ORDER BY c.name"
        );
    }
    
    // Get all teachers with their details (both editingteacher and teacher roles) and their assigned schools
    // Show editingteacher as primary role when user has both roles
    // Updated to show ALL teachers (system-level AND course-level)
$searchquery = optional_param('search', '', PARAM_RAW_TRIMMED);
$statusfilter = optional_param('status', 'all', PARAM_ALPHA);
$schoolfilter = optional_param('school', 'all', PARAM_RAW_TRIMMED);
$filtersapplied = ($searchquery !== '' || $statusfilter !== 'all' || $schoolfilter !== 'all');
$teacherpage = max(1, optional_param('tpage', 1, PARAM_INT));
$teachersperpage = 10;

$teachers = $DB->get_records_sql(
        "SELECT 
            u.id,
            u.username,
            u.firstname,
            u.lastname,
            u.email,
            u.suspended,
            u.deleted,
            u.lastaccess,
            u.timecreated,
            FROM_UNIXTIME(MAX(ra.timemodified)) as role_assigned_date,
            MAX(ra.timemodified) as role_timestamp,
            CASE 
                WHEN MAX(CASE WHEN r.shortname = 'editingteacher' THEN 1 ELSE 0 END) = 1 THEN 'editingteacher'
                ELSE MAX(r.shortname)
            END as role_shortname,
            CASE 
                WHEN MAX(CASE WHEN r.shortname = 'editingteacher' THEN 1 ELSE 0 END) = 1 THEN 'Editing Teacher'
                ELSE MAX(r.name)
            END as role_name,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as school_names,
            GROUP_CONCAT(DISTINCT c.id ORDER BY c.name SEPARATOR ', ') as school_ids
         FROM {user} u
         JOIN {role_assignments} ra ON u.id = ra.userid
         JOIN {context} ctx ON ra.contextid = ctx.id
         JOIN {role} r ON ra.roleid = r.id
         LEFT JOIN {company_users} cu ON u.id = cu.userid
         LEFT JOIN {company} c ON cu.companyid = c.id
         WHERE ra.roleid IN ($role_ids_placeholder)
         AND u.deleted = 0
         GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.suspended, u.deleted, u.lastaccess, u.timecreated
         ORDER BY u.firstname, u.lastname",
        $role_ids ?? []
    );
    
    // Count statistics
$total_teachers = count($teachers);
$active_teachers = count(array_filter($teachers, function($t) { return !$t->suspended; }));
$suspended_teachers = $total_teachers - $active_teachers;

$filtered_teachers = array_values(array_filter($teachers, function($teacher) use ($searchquery, $statusfilter, $schoolfilter) {
    $matchesSearch = true;
    if ($searchquery !== '') {
        $haystack = strtolower(
            $teacher->firstname . ' ' .
            $teacher->lastname . ' ' .
            $teacher->email . ' ' .
            ($teacher->school_names ?? '')
        );
        $matchesSearch = strpos($haystack, strtolower($searchquery)) !== false;
    }

    $matchesStatus = true;
    if ($statusfilter === 'active') {
        $matchesStatus = !$teacher->suspended;
    } else if ($statusfilter === 'suspended') {
        $matchesStatus = (bool)$teacher->suspended;
    }

    $matchesSchool = true;
    if ($schoolfilter !== 'all') {
        if ($schoolfilter === 'unassigned') {
            $matchesSchool = empty($teacher->school_names);
        } else {
            $schoolIds = !empty($teacher->school_ids) ? array_map('trim', explode(',', $teacher->school_ids)) : [];
            $matchesSchool = in_array($schoolfilter, $schoolIds, true);
        }
    }

    return $matchesSearch && $matchesStatus && $matchesSchool;
}));

$filtered_total = count($filtered_teachers);
$teacher_totalpages = max(1, (int)ceil($filtered_total / $teachersperpage));
if ($teacherpage > $teacher_totalpages) {
    $teacherpage = $teacher_totalpages;
}
$teacher_offset = ($teacherpage - 1) * $teachersperpage;
$teachers_display = array_slice($filtered_teachers, $teacher_offset, $teachersperpage);
    
    // Breadcrumb
    
    
    // Main container
    echo "<div class='teachers-container'>";
    
    // Header
    echo "<div class='teachers-header'>";
    echo "<div>";
    echo "<h1 class='teachers-title'>Teachers Management</h1>";
    echo "<p class='teachers-subtitle'>Manage and view all teachers in your system</p>";
    echo "</div>";
    echo "<div class='teachers-stats'>";
    echo "<div class='stat-item'>";
    echo "<div class='stat-number'>$total_teachers</div>";
    echo "<div class='stat-label'>Total</div>";
    echo "</div>";
    echo "<div class='stat-item'>";
    echo "<div class='stat-number'>$active_teachers</div>";
    echo "<div class='stat-label'>Active</div>";
    echo "</div>";
    echo "<div class='stat-item'>";
    echo "<div class='stat-number'>$suspended_teachers</div>";
    echo "<div class='stat-label'>Suspended</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Search and filter bar
    echo "<form class='search-filter-bar' method='get' id='teachersFilterForm'>";
    echo "<input type='hidden' name='tpage' value='1'>";
    echo "<div class='search-box'>";
    echo "<input type='text' class='search-input' name='search' value='" . s($searchquery) . "' placeholder='Search teachers by name, email, or school...' />";
    echo "<select class='filter-select' name='status'>";
    $statusOptions = [
        'all' => 'All Teachers',
        'active' => 'Active Only',
        'suspended' => 'Suspended Only'
    ];
    foreach ($statusOptions as $value => $label) {
        $selected = $statusfilter === $value ? 'selected' : '';
        echo "<option value='$value' $selected>$label</option>";
    }
    echo "</select>";
    echo "<select class='filter-select' name='school'>";
    $selected = $schoolfilter === 'all' ? 'selected' : '';
    echo "<option value='all' $selected>All Schools</option>";
    if (!empty($schools)) {
        foreach ($schools as $school) {
            $sel = (string)$school->id === (string)$schoolfilter ? 'selected' : '';
            echo "<option value='" . $school->id . "' $sel>" . format_string($school->name) . "</option>";
        }
    }
    $selected = $schoolfilter === 'unassigned' ? 'selected' : '';
    echo "<option value='unassigned' $selected>Unassigned Teachers</option>";
    echo "</select>";
    echo "</div>";
    echo "<a href='add_teacher.php' class='add-teacher-btn'><i class='fa fa-plus'></i> Add New Teacher</a>";
    echo "</form>";
    
    if ($filtered_total > 0) {
        // Teachers table
        echo "<table class='teachers-table' id='teachers-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>Teacher</th>";
        echo "<th>Email</th>";
        echo "<th>School</th>";
        echo "<th>Role</th>";
        echo "<th>Status</th>";
        echo "<th>Last Access</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        foreach ($teachers_display as $teacher) {
            $status_class = $teacher->suspended ? 'status-suspended' : 'status-active';
            $status_text = $teacher->suspended ? 'Suspended' : 'Active';
            
            $last_access = $teacher->lastaccess ? date('M j, Y g:i A', $teacher->lastaccess) : 'Never';
            $role_assigned = date('M j, Y', $teacher->role_timestamp);
            
            // Get first letter of first name for avatar
            $avatar_letter = strtoupper(substr($teacher->firstname, 0, 1));
            
            // Get first school ID for filtering (if teacher has multiple schools, use the first one)
            $school_id_attr = '';
            if (!empty($teacher->school_ids)) {
                $school_ids_array = explode(', ', $teacher->school_ids);
                $school_id_attr = ' data-school-id="' . $school_ids_array[0] . '"';
            }
            
            echo "<tr{$school_id_attr}>";
            echo "<td>";
            echo "<div class='teacher-info'>";
            echo "<div class='teacher-avatar'>$avatar_letter</div>";
            echo "<div>";
            echo "<div class='teacher-name'>{$teacher->firstname} {$teacher->lastname}</div>";
            echo "<div class='teacher-email'>ID: {$teacher->id}</div>";
            echo "</div>";
            echo "</div>";
            echo "</td>";
            echo "<td>{$teacher->email}</td>";
            echo "<td>";
            if (!empty($teacher->school_names)) {
                echo "<span style='color: #0369a1; font-weight: 500;'>" . s($teacher->school_names) . "</span>";
            } else {
                echo "<span style='color: #9ca3af; font-style: italic;'>No school assigned</span>";
            }
            echo "</td>";
            echo "<td><span class='role-badge'>" . ucfirst($teacher->role_shortname) . "</span></td>";
            echo "<td><span class='status-badge $status_class'>$status_text</span></td>";
            echo "<td>$last_access</td>";
            echo "<td>";
            echo "<div class='action-buttons'>";
            echo "<a href='view_teacher.php?id={$teacher->id}' class='btn btn-view' title='View Profile'>";
            echo "<i class='fa fa-eye'></i> View";
            echo "</a>";
            echo "<a href='edit_teacher.php?id={$teacher->id}' class='btn btn-edit' title='Edit Teacher'>";
            echo "<i class='fa fa-edit'></i> Edit";
            echo "</a>";
            if (!$teacher->suspended) {
                echo "<button onclick='toggleTeacherStatus({$teacher->id}, true)' class='btn btn-suspend' title='Suspend Teacher'>";
                echo "<i class='fa fa-ban'></i> Suspend";
                echo "</button>";
            } else {
                echo "<button onclick='toggleTeacherStatus({$teacher->id}, false)' class='btn btn-activate' title='Activate Teacher'>";
                echo "<i class='fa fa-check'></i> Activate";
                echo "</button>";
            }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";

        if ($teacher_totalpages > 1) {
            echo "<div class='teachers-pagination'>";
            $startDisplay = $filtered_total ? $teacher_offset + 1 : 0;
            $endDisplay = min($teacher_offset + $teachersperpage, $filtered_total);
            echo "<div class='teachers-pagination-info'>Showing $startDisplay-$endDisplay of $filtered_total teachers</div>";
            echo "<div class='teachers-pagination-buttons'>";

            $baseparams = [];
            if ($searchquery !== '') { $baseparams['search'] = $searchquery; }
            if ($statusfilter !== 'all') { $baseparams['status'] = $statusfilter; }
            if ($schoolfilter !== 'all') { $baseparams['school'] = $schoolfilter; }

            $prevClass = $teacherpage <= 1 ? 'disabled' : '';
            $prevUrl = new moodle_url($PAGE->url, array_merge($baseparams, ['tpage' => max(1, $teacherpage - 1)]));
            echo "<a class='pagination-button $prevClass' href='" . $prevUrl->out(false) . "'>Prev</a>";

            $window = 5;
            $windowstart = max(1, $teacherpage - 2);
            $windowend = min($teacher_totalpages, $windowstart + $window - 1);

            if ($windowstart > 1) {
                $firstUrl = new moodle_url($PAGE->url, array_merge($baseparams, ['tpage' => 1]));
                echo "<a class='pagination-button' href='" . $firstUrl->out(false) . "'>1</a>";
                if ($windowstart > 2) {
                    echo "<span class='pagination-button disabled'>...</span>";
                }
            }

            for ($i = $windowstart; $i <= $windowend; $i++) {
                $url = new moodle_url($PAGE->url, array_merge($baseparams, ['tpage' => $i]));
                $active = $i === $teacherpage ? 'active' : '';
                echo "<a class='pagination-button $active' href='" . $url->out(false) . "'>$i</a>";
            }

            if ($windowend < $teacher_totalpages) {
                if ($windowend < $teacher_totalpages - 1) {
                    echo "<span class='pagination-button disabled'>...</span>";
                }
                $lastUrl = new moodle_url($PAGE->url, array_merge($baseparams, ['tpage' => $teacher_totalpages]));
                echo "<a class='pagination-button' href='" . $lastUrl->out(false) . "'>$teacher_totalpages</a>";
            }

            $nextClass = $teacherpage >= $teacher_totalpages ? 'disabled' : '';
            $nextUrl = new moodle_url($PAGE->url, array_merge($baseparams, ['tpage' => min($teacher_totalpages, $teacherpage + 1)]));
            echo "<a class='pagination-button $nextClass' href='" . $nextUrl->out(false) . "'>Next</a>";
            echo "</div>";
            echo "</div>";
        }
        
    } else if ($filtersapplied) {
        // Filters applied but no matches - render structure without rows
        echo "<table class='teachers-table' id='teachers-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>Teacher</th>";
        echo "<th>Email</th>";
        echo "<th>School</th>";
        echo "<th>Role</th>";
        echo "<th>Status</th>";
        echo "<th>Last Access</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody></tbody>";
        echo "</table>";
    } else {
        // No teachers found
        echo "<div class='no-teachers'>";
        echo "<i class='fa fa-users'></i>";
        echo "<h3>No Teachers Found</h3>";
        echo "<p>There are no teachers with the 'teachers' role assigned at system level.</p>";
        echo "<p><a href='{$CFG->wwwroot}/admin/user.php' class='btn btn-primary'>Add Your First Teacher</a></p>";
        echo "</div>";
    }
    
    echo "</div>"; // End teachers-container
    
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
    echo "</div>"; // End admin-main-content
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

// Add JavaScript for modal functionality
echo "<script>
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
    
    fetch('', {
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

let teachersPageLoading = false;

function attachTeacherPaginationListeners() {
    document.body.addEventListener('click', function(event) {
        const link = event.target.closest('.teachers-pagination .pagination-button');
        if (!link || link.classList.contains('disabled') || teachersPageLoading) {
            return;
        }
        const href = link.getAttribute('href');
        if (!href) {
            return;
        }
        event.preventDefault();
        loadTeachersPage(href);
    });
}

function loadTeachersPage(url) {
    const table = document.getElementById('teachers-table');
    const pagination = document.querySelector('.teachers-pagination');
    if (table) {
        table.classList.add('is-loading');
    }
    if (pagination) {
        pagination.classList.add('is-loading');
    }
    teachersPageLoading = true;

    fetch(url, { credentials: 'same-origin' })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTbody = doc.querySelector('#teachers-table tbody');
            const existingTbody = table ? table.querySelector('tbody') : null;
            const newPagination = doc.querySelector('.teachers-pagination');
            const existingPagination = document.querySelector('.teachers-pagination');
            const noTeachersSection = doc.querySelector('.no-teachers');
            const container = document.querySelector('.teachers-container');

            if (newTbody && existingTbody) {
                existingTbody.innerHTML = newTbody.innerHTML;
            } else if (noTeachersSection && container) {
                container.innerHTML = noTeachersSection.outerHTML;
            }

            if (newPagination && existingPagination) {
                existingPagination.replaceWith(newPagination);
            } else if (!newPagination && existingPagination) {
                existingPagination.remove();
            } else if (newPagination && !existingPagination && container) {
                container.appendChild(newPagination);
            }

            const newUrl = new URL(url, window.location.origin);
            window.history.replaceState({}, '', newUrl);
        })
        .catch(error => {
            console.error('Failed to load teachers page via AJAX:', error);
        })
        .finally(() => {
            teachersPageLoading = false;
            if (table) {
                table.classList.remove('is-loading');
            }
            const paginationAfter = document.querySelector('.teachers-pagination');
            if (paginationAfter) {
                paginationAfter.classList.remove('is-loading');
            }
        });
}

function submitTeacherFiltersAjax(form) {
    if (!form) {
        return;
    }
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    const actionUrl = form.action || window.location.href;
    const connector = actionUrl.includes('?') ? '&' : '?';
    const fetchUrl = actionUrl + connector + params.toString();
    loadTeachersPage(fetchUrl);
}

function initTeacherFiltersAutoSubmit() {
    const filterForm = document.getElementById('teachersFilterForm');
    if (!filterForm) {
        return;
    }

    const searchInput = filterForm.querySelector('input[name=\"search\"]');
    const selects = filterForm.querySelectorAll('select');
    const resetPage = () => {
        const pageField = filterForm.querySelector('input[name=\"tpage\"]');
        if (pageField) {
            pageField.value = '1';
        }
    };

    let debounceTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                resetPage();
                submitTeacherFiltersAjax(filterForm);
            }, 400);
        });
    }

    selects.forEach(select => {
        select.addEventListener('change', function() {
            resetPage();
            submitTeacherFiltersAjax(filterForm);
        });
    });

    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        resetPage();
        submitTeacherFiltersAjax(filterForm);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    attachTeacherPaginationListeners();
    initTeacherFiltersAutoSubmit();
});

</script>";

echo $OUTPUT->footer();
?>
