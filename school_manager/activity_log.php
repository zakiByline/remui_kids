<?php
/**
 * Activity Log for School Manager Dashboard
 * 
 * This page displays all user activity logs for the specific school,
 * including logins, course views, quiz submissions, assignment submissions, etc.
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Ensure user is logged in
require_login();

global $USER, $PAGE, $OUTPUT, $DB, $CFG;

// Get current user and company information
$user = $USER;
$company_id = null;
$company_info = null;

// Get company information for the current user
try {
    $company_info = $DB->get_record_sql(
        "SELECT c.*, cu.managertype 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$user->id]
    );
    
    if ($company_info) {
        $company_id = $company_info->id;
    }
} catch (Exception $e) {
    error_log("Error getting company info: " . $e->getMessage());
}

// Redirect if not a school manager
if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Access denied. You must be a school manager to access this page.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get filter and pagination parameters
$event_filter = optional_param('event_filter', 'all', PARAM_TEXT);
$action_filter = optional_param('action_filter', 'all', PARAM_TEXT);
$user_filter = optional_param('user_filter', 'all', PARAM_INT);
$date_from = optional_param('date_from', '', PARAM_TEXT);
$date_to = optional_param('date_to', '', PARAM_TEXT);
$search_query = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT); // Pagination: current page
$per_page = 10; // Items per page

// Build SQL query based on filters
$sql_where = [];
$sql_params = [];

// Always filter by company (school)
$sql_where[] = "cu.companyid = ?";
$sql_params[] = $company_id;

// Event type filter
if ($event_filter !== 'all') {
    switch ($event_filter) {
        case 'login':
            $sql_where[] = "l.action = 'loggedin'";
            break;
        case 'view_dashboard':
            $sql_where[] = "(l.action = 'viewed' AND l.target = 'dashboard')";
            break;
        case 'view_course':
            $sql_where[] = "(l.action = 'viewed' AND l.target = 'course')";
            break;
        case 'quiz_submission':
            $sql_where[] = "(l.component = 'mod_quiz' AND l.action = 'submitted')";
            break;
        case 'assignment_submission':
            $sql_where[] = "(l.component = 'mod_assign' AND l.action = 'submitted')";
            break;
    }
}

// Action filter
if ($action_filter !== 'all') {
    $sql_where[] = "l.action = ?";
    $sql_params[] = $action_filter;
}

// User filter
if ($user_filter !== 'all' && $user_filter > 0) {
    $sql_where[] = "l.userid = ?";
    $sql_params[] = $user_filter;
}

// Date range filter
if (!empty($date_from)) {
    $timestamp_from = strtotime($date_from . ' 00:00:00');
    if ($timestamp_from) {
        $sql_where[] = "l.timecreated >= ?";
        $sql_params[] = $timestamp_from;
    }
}

if (!empty($date_to)) {
    $timestamp_to = strtotime($date_to . ' 23:59:59');
    if ($timestamp_to) {
        $sql_where[] = "l.timecreated <= ?";
        $sql_params[] = $timestamp_to;
    }
}

// Build WHERE clause
$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

// Count total matching records (before pagination)
$total_count = 0;
try {
    if ($DB->get_manager()->table_exists('logstore_standard_log')) {
        $total_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.id)
               FROM {logstore_standard_log} l
               JOIN {company_users} cu ON cu.userid = l.userid
               JOIN {user} u ON u.id = l.userid
          LEFT JOIN {course} c ON c.id = l.courseid
              $where_clause",
            $sql_params
        );
    }
} catch (Exception $e) {
    error_log("Error counting activity logs: " . $e->getMessage());
}

// Calculate pagination
$total_pages = ceil($total_count / $per_page);
$page = max(1, min($page, $total_pages)); // Ensure page is within valid range
$offset = ($page - 1) * $per_page;

// Fetch activity logs with pagination
$activity_logs = [];
try {
    if ($DB->get_manager()->table_exists('logstore_standard_log')) {
        $activity_logs = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.timecreated, l.action, l.target, l.objecttable, l.objectid,
                    l.component, l.eventname, l.ip, l.other,
                    u.firstname, u.lastname, u.username, u.email,
                    c.fullname AS coursename, c.id AS courseid
               FROM {logstore_standard_log} l
               JOIN {company_users} cu ON cu.userid = l.userid
               JOIN {user} u ON u.id = l.userid
          LEFT JOIN {course} c ON c.id = l.courseid
              $where_clause
           ORDER BY l.timecreated DESC
              LIMIT $per_page OFFSET $offset",
            $sql_params
        );
    }
} catch (Exception $e) {
    error_log("Error fetching activity logs: " . $e->getMessage());
}

// Apply text search filter if provided (re-fetch with search in SQL)
if (!empty($search_query)) {
    $search_param = '%' . $DB->sql_like_escape($search_query) . '%';
    $search_where = "(u.firstname " . $DB->sql_like() . " ? OR u.lastname " . $DB->sql_like() . " ? OR u.username " . $DB->sql_like() . " ? OR u.email " . $DB->sql_like() . " ? OR l.action " . $DB->sql_like() . " ? OR l.target " . $DB->sql_like() . " ? OR c.fullname " . $DB->sql_like() . " ?)";
    
    // Add search to WHERE clause
    $sql_where_with_search = $sql_where;
    $sql_where_with_search[] = $search_where;
    $sql_params_with_search = array_merge($sql_params, array_fill(0, 7, $search_param));
    
    $where_clause_with_search = !empty($sql_where_with_search) ? "WHERE " . implode(" AND ", $sql_where_with_search) : "";
    
    // Recount with search
    try {
        $total_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.id)
               FROM {logstore_standard_log} l
               JOIN {company_users} cu ON cu.userid = l.userid
               JOIN {user} u ON u.id = l.userid
          LEFT JOIN {course} c ON c.id = l.courseid
              $where_clause_with_search",
            $sql_params_with_search
        );
        
        // Recalculate pagination
        $total_pages = ceil($total_count / $per_page);
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;
        
        // Re-fetch with search and pagination
        $activity_logs = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.timecreated, l.action, l.target, l.objecttable, l.objectid,
                    l.component, l.eventname, l.ip, l.other,
                    u.firstname, u.lastname, u.username, u.email,
                    c.fullname AS coursename, c.id AS courseid
               FROM {logstore_standard_log} l
               JOIN {company_users} cu ON cu.userid = l.userid
               JOIN {user} u ON u.id = l.userid
          LEFT JOIN {course} c ON c.id = l.courseid
              $where_clause_with_search
           ORDER BY l.timecreated DESC
              LIMIT $per_page OFFSET $offset",
            $sql_params_with_search
        );
    } catch (Exception $e) {
        error_log("Error fetching activity logs with search: " . $e->getMessage());
    }
}

// Get list of users in this school for filter dropdown
$school_users = [];
try {
    $school_users = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.username
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
          WHERE u.deleted = 0
       ORDER BY u.firstname ASC, u.lastname ASC
          LIMIT 100",
        [$company_id]
    );
} catch (Exception $e) {
    error_log("Error fetching school users: " . $e->getMessage());
}

// Calculate statistics
$total_logs = count($activity_logs);
$login_count = count(array_filter($activity_logs, function($log) { return $log->action === 'loggedin'; }));
$unique_users = count(array_unique(array_column($activity_logs, 'userid')));
$today_count = count(array_filter($activity_logs, function($log) { return $log->timecreated >= strtotime('today'); }));

// Get school logo URL - Multiple methods
$school_logo_url = null;
$has_logo = false;

try {
    // Method 1: Check company_logo table (IOMAD standard way)
    if ($DB->get_manager()->table_exists('company_logo')) {
        $company_logo = $DB->get_record('company_logo', ['companyid' => $company_id]);
        
        if ($company_logo && !empty($company_logo->filename)) {
            // Check if logo file actually exists
            $logo_filepath = $CFG->dataroot . '/company/' . $company_id . '/' . $company_logo->filename;
            
            if (file_exists($logo_filepath)) {
                // Construct URL to serve the logo through get_company_logo.php
                $school_logo_url = $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_id;
                $has_logo = true;
                
                error_log("✅ ACTIVITY LOG - Company logo found (Method 1): " . $company_logo->filename);
                error_log("   Logo URL: " . $school_logo_url);
                error_log("   File path: " . $logo_filepath);
            } else {
                error_log("⚠️ ACTIVITY LOG - Logo file not found at: " . $logo_filepath);
            }
        } else {
            error_log("⚠️ ACTIVITY LOG - No logo record in company_logo table for company ID: " . $company_id);
        }
    } else {
        error_log("⚠️ ACTIVITY LOG - company_logo table does not exist");
    }
    
    // Method 2: Try Moodle File Storage API (if Method 1 failed)
    if (!$has_logo) {
        $fs = get_file_storage();
        $syscontext = context_system::instance();
        
        // Try multiple file areas
        $fileareas = ['companylogo', 'logo', 'company_logo', 'logo_image'];
        
        foreach ($fileareas as $filearea) {
            $files = $fs->get_area_files($syscontext->id, 'local_iomad', $filearea, $company_id, 'timemodified DESC', false);
            
            if (!empty($files)) {
                foreach ($files as $file) {
                    if ($file->is_directory()) {
                        continue;
                    }
                    
                    $mimetype = $file->get_mimetype();
                    if (strpos($mimetype, 'image/') === 0) {
                        $school_logo_url = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out();
                        
                        $has_logo = true;
                        error_log("✅ ACTIVITY LOG - Logo found (Method 2 - File Storage): " . $file->get_filename());
                        error_log("   Filearea: " . $filearea);
                        error_log("   Logo URL: " . $school_logo_url);
                        break 2; // Exit both loops
                    }
                }
            }
        }
        
        if (!$has_logo) {
            error_log("⚠️ ACTIVITY LOG - No files in Moodle file storage (tried areas: " . implode(', ', $fileareas) . ")");
        }
    }
    
    // Method 3: Check company table's logo field (alternative storage)
    if (!$has_logo && !empty($company_info->logo)) {
        error_log("⚠️ ACTIVITY LOG - Checking company->logo field: " . $company_info->logo);
        
        // Check if it's a URL or filename
        if (filter_var($company_info->logo, FILTER_VALIDATE_URL)) {
            $school_logo_url = $company_info->logo;
            $has_logo = true;
            error_log("✅ ACTIVITY LOG - Logo found (Method 3 - URL): " . $school_logo_url);
        } else if (file_exists($CFG->dataroot . '/' . $company_info->logo)) {
            // If it's a relative path
            $school_logo_url = $CFG->wwwroot . '/pluginfile.php/1/local_iomad/company/' . $company_id . '/' . $company_info->logo;
            $has_logo = true;
            error_log("✅ ACTIVITY LOG - Logo found (Method 3 - File): " . $school_logo_url);
        }
    }
    
    // Final status
    if (!$has_logo) {
        error_log("❌ ACTIVITY LOG - No logo found using any method for company: " . $company_info->name);
        error_log("   To add a logo, go to: Site administration → IOMAD → Edit Companies");
    }
    
} catch (Exception $e) {
    error_log("❌ ACTIVITY LOG - Error fetching company logo: " . $e->getMessage());
}

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => $school_logo_url,
    'has_logo' => $has_logo,
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'activity_log_active' => true,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false
];

error_log("🎨 ACTIVITY LOG - Sidebar Context:");
error_log("   Company: " . $company_info->name);
error_log("   Logo URL: " . ($school_logo_url ?: 'No logo'));
error_log("   Has Logo: " . ($has_logo ? 'YES' : 'NO'));

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/school_manager/activity_log.php');
$PAGE->set_title('Activity Log - ' . $company_info->name);
$PAGE->set_heading('Activity Log');

// Output the header
echo $OUTPUT->header();

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Custom CSS for activity log page
echo "<style>
/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* School Manager Main Content Area */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 320px;
    width: calc(100vw - 320px);
    height: calc(100vh - 55px);
    background-color: #f8f9fa;
    overflow-y: auto;
    z-index: 99;
    padding-top: 35px;
    transition: left 0.3s ease, width 0.3s ease;
}

.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 0 30px 30px 30px;
}

/* Page Header */
.page-header {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    margin-top: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    position: relative;
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.page-header-content {
    flex: 1;
    text-align: center;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.back-button {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}

.back-button i {
    color: #ffffff;
}

.back-button:hover {
    background: #1d4ed8;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    text-decoration: none;
    color: #ffffff;
    transform: translateY(-1px);
}

/* Stats Section */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 0.75rem;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    border-top: 4px solid transparent;
    text-align: center;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.stat-card.total-logs {
    border-top-color: #667eea;
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
}
.stat-card.logins {
    border-top-color: #10b981;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.08), #ffffff);
}
.stat-card.unique-users {
    border-top-color: #3b82f6;
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.08), #ffffff);
}
.stat-card.today {
    border-top-color: #f59e0b;
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.08), #ffffff);
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

/* Filters Section */
.filters-section {
    background: white;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.filters-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
}

.filter-select,
.filter-input {
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #4a5568;
    transition: all 0.3s ease;
    background: white;
}

.filter-select {
    cursor: pointer;
    appearance: none;
    background-image: url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A//www.w3.org/2000/svg%27%20width%3D%2712%27%20height%3D%2712%27%20viewBox%3D%270%200%2012%2012%27%3E%3Cpath%20fill%3D%27%234a5568%27%20d%3D%27M6%209L1%204h10z%27/%3E%3C/svg%3E');
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 40px;
}

.filter-select:focus,
.filter-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.filter-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn-apply {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-reset {
    background: #e2e8f0;
    color: #4a5568;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-reset:hover {
    background: #cbd5e0;
    color: #2d3748;
    text-decoration: none;
}

/* Activity Table */
.activity-container {
    background: white;
    border-radius: 15px;
    padding: 0;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    overflow: hidden;
}

.table-header {
    padding: 20px 25px;
    border-bottom: 1px solid #e9ecef;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.table-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.activity-table {
    width: 100%;
    border-collapse: collapse;
}

.activity-table th {
    background: #f8f9fa;
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.activity-table td {
    padding: 18px 20px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
    font-size: 0.9rem;
}

.activity-table tbody tr:hover {
    background: #f8fafc;
    box-shadow: inset 0 0 0 1px #e5e7eb;
}

/* User Cell */
.user-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.user-name {
    font-weight: 600;
    color: #1f2937;
}

.user-username {
    font-size: 0.8rem;
    color: #6b7280;
}

/* Time Cell */
.time-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.time-date {
    font-weight: 500;
    color: #374151;
}

.time-relative {
    font-size: 0.8rem;
    color: #6b7280;
}

/* Event Badge */
.event-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
}

.event-login {
    background: #d1fae5;
    color: #065f46;
}

.event-viewed {
    background: #dbeafe;
    color: #1e40af;
}

.event-submitted {
    background: #fef3c7;
    color: #92400e;
}

.event-created {
    background: #e0e7ff;
    color: #3730a3;
}

.event-updated {
    background: #e0f2fe;
    color: #075985;
}

.event-deleted {
    background: #fee2e2;
    color: #991b1b;
}

/* Description Cell */
.description-text {
    color: #4b5563;
    line-height: 1.5;
}

/* Context Cell */
.context-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}

.context-link:hover {
    text-decoration: underline;
}

/* IP Address */
.ip-address {
    font-family: 'Courier New', monospace;
    color: #6b7280;
    font-size: 0.85rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 1.5rem;
    color: #4a5568;
    margin-bottom: 10px;
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-top: 1px solid #e9ecef;
}

.pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
    font-weight: 500;
}

.pagination-info span {
    color: #1f2937;
    font-weight: 600;
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
}

.pagination-btn:hover:not(.disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
    background: linear-gradient(135deg, #5568d3 0%, #6339a0 100%);
}

.pagination-btn:active:not(.disabled) {
    transform: translateY(0);
}

.pagination-btn.disabled {
    background: #e9ecef;
    color: #adb5bd;
    cursor: not-allowed;
    box-shadow: none;
}

.pagination-pages {
    display: flex;
    align-items: center;
    gap: 0.375rem;
}

.pagination-page {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.5rem;
    height: 2.5rem;
    padding: 0 0.75rem;
    background: white;
    color: #4b5563;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.pagination-page:hover:not(.active) {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: #1f2937;
}

.pagination-page.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: transparent;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.pagination-ellipsis {
    padding: 0 0.5rem;
    color: #9ca3af;
    font-weight: 600;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .school-manager-main-content {
        left: 280px;
        width: calc(100vw - 280px);
    }
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
        padding-top: 80px;
    }
    
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .activity-table {
        font-size: 0.8rem;
    }
    
    .activity-table th,
    .activity-table td {
        padding: 12px 10px;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;
    }
    
    .pagination-controls {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .pagination-btn {
        font-size: 0.8rem;
        padding: 0.5rem 0.875rem;
    }
    
    .pagination-page {
        min-width: 2.25rem;
        height: 2.25rem;
        font-size: 0.8rem;
    }
}
</style>";

// Main content
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Activity Log</h1>";
echo "<p class='page-subtitle'>Monitor all user activities in " . htmlspecialchars($company_info->name) . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";

// Statistics Cards
echo "<div class='stats-section'>";

echo "<div class='stat-card total-logs'>";
echo "<h3 class='stat-number'>" . $total_logs . "</h3>";
echo "<p class='stat-label'>Total Activities</p>";
echo "</div>";

echo "<div class='stat-card logins'>";
echo "<h3 class='stat-number'>" . $login_count . "</h3>";
echo "<p class='stat-label'>Login Events</p>";
echo "</div>";

echo "<div class='stat-card unique-users'>";
echo "<h3 class='stat-number'>" . $unique_users . "</h3>";
echo "<p class='stat-label'>Unique Users</p>";
echo "</div>";

echo "<div class='stat-card today'>";
echo "<h3 class='stat-number'>" . $today_count . "</h3>";
echo "<p class='stat-label'>Today's Activities</p>";
echo "</div>";

echo "</div>";

// Filters Section
echo "<div class='filters-section'>";
echo "<div class='filters-header'>";
echo "<h2 class='filters-title'><i class='fa fa-filter'></i> Filter Activities</h2>";
echo "</div>";

echo "<form method='GET' action=''>";
echo "<div class='filter-grid'>";

// Event Type Filter
echo "<div class='filter-group'>";
echo "<label class='filter-label'>EVENT TYPE</label>";
echo "<select name='event_filter' class='filter-select'>";
echo "<option value='all'" . ($event_filter === 'all' ? ' selected' : '') . ">All events</option>";
echo "<option value='login'" . ($event_filter === 'login' ? ' selected' : '') . ">Student login</option>";
echo "<option value='view_dashboard'" . ($event_filter === 'view_dashboard' ? ' selected' : '') . ">Student view dashboard</option>";
echo "<option value='view_course'" . ($event_filter === 'view_course' ? ' selected' : '') . ">Student view course</option>";
echo "<option value='quiz_submission'" . ($event_filter === 'quiz_submission' ? ' selected' : '') . ">Quiz submission</option>";
echo "<option value='assignment_submission'" . ($event_filter === 'assignment_submission' ? ' selected' : '') . ">Assignment submission</option>";
echo "</select>";
echo "</div>";

// Action Filter
echo "<div class='filter-group'>";
echo "<label class='filter-label'>ACTION</label>";
echo "<select name='action_filter' class='filter-select'>";
echo "<option value='all'" . ($action_filter === 'all' ? ' selected' : '') . ">All actions</option>";
echo "<option value='created'" . ($action_filter === 'created' ? ' selected' : '') . ">Create</option>";
echo "<option value='viewed'" . ($action_filter === 'viewed' ? ' selected' : '') . ">View</option>";
echo "<option value='updated'" . ($action_filter === 'updated' ? ' selected' : '') . ">Update</option>";
echo "<option value='deleted'" . ($action_filter === 'deleted' ? ' selected' : '') . ">Delete</option>";
echo "</select>";
echo "</div>";

// User Filter
echo "<div class='filter-group'>";
echo "<label class='filter-label'>USER</label>";
echo "<select name='user_filter' class='filter-select'>";
echo "<option value='all'" . ($user_filter === 'all' ? ' selected' : '') . ">All users</option>";
foreach ($school_users as $school_user) {
    $selected = ($user_filter == $school_user->id) ? ' selected' : '';
    echo "<option value='{$school_user->id}'{$selected}>" . htmlspecialchars(fullname($school_user)) . "</option>";
}
echo "</select>";
echo "</div>";

// Date From
echo "<div class='filter-group'>";
echo "<label class='filter-label'>DATE FROM</label>";
echo "<input type='date' name='date_from' value='" . htmlspecialchars($date_from) . "' class='filter-input'>";
echo "</div>";

// Date To
echo "<div class='filter-group'>";
echo "<label class='filter-label'>DATE TO</label>";
echo "<input type='date' name='date_to' value='" . htmlspecialchars($date_to) . "' class='filter-input'>";
echo "</div>";

// Search
echo "<div class='filter-group'>";
echo "<label class='filter-label'>SEARCH</label>";
echo "<input type='text' name='search' value='" . htmlspecialchars($search_query) . "' placeholder='Search activities...' class='filter-input'>";
echo "</div>";

echo "</div>"; // End filter-grid

echo "<div class='filter-actions'>";
echo "<button type='submit' class='btn-apply'><i class='fa fa-check'></i> Apply Filters</button>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/activity_log.php' class='btn-reset'><i class='fa fa-redo'></i> Reset</a>";
echo "</div>";

echo "</form>";
echo "</div>"; // End filters-section

// Activity Table
echo "<div class='activity-container'>";
echo "<div class='table-header'>";
echo "<h2 class='table-title'><i class='fa fa-list'></i> Activity Logs</h2>";
echo "</div>";

if (empty($activity_logs)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-history'></i>";
    echo "<h3>No Activity Found</h3>";
    echo "<p>No activity logs match your current filters.</p>";
    echo "</div>";
} else {
    echo "<div style='overflow-x: auto;'>";
    echo "<table class='activity-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>USER</th>";
    echo "<th>TIME</th>";
    echo "<th>EVENT</th>";
    echo "<th>DESCRIPTION</th>";
    echo "<th>EVENT CONTEXT</th>";
    echo "<th>IP ADDRESS</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($activity_logs as $log) {
        $fullname = fullname($log);
        $username = $log->username;
        $time_formatted = userdate($log->timecreated, '%d %B %Y, %I:%M %p');
        $time_ago = format_time(time() - $log->timecreated);
        
        // Determine event badge class
        $event_class = 'event-viewed';
        if ($log->action === 'loggedin') {
            $event_class = 'event-login';
        } elseif ($log->action === 'submitted') {
            $event_class = 'event-submitted';
        } elseif ($log->action === 'created') {
            $event_class = 'event-created';
        } elseif ($log->action === 'updated') {
            $event_class = 'event-updated';
        } elseif ($log->action === 'deleted') {
            $event_class = 'event-deleted';
        }
        
        // Format event name
        $event_name = ucfirst($log->action);
        if ($log->target) {
            $event_name .= ' ' . $log->target;
        }
        
        // Format description
        $description = "The user with id '{$log->userid}' has " . $log->action;
        if ($log->target) {
            $description .= " " . $log->target;
        }
        if ($log->coursename) {
            $description .= " in course '" . $log->coursename . "'";
        }
        
        // Format context
        $context = "User: " . htmlspecialchars($fullname);
        $context_url = $CFG->wwwroot . '/user/view.php?id=' . $log->userid;
        
        echo "<tr>";
        
        // User column
        echo "<td>";
        echo "<div class='user-cell'>";
        echo "<span class='user-name'>" . htmlspecialchars($fullname) . "</span>";
        echo "<span class='user-username'>@" . htmlspecialchars($username) . "</span>";
        echo "</div>";
        echo "</td>";
        
        // Time column
        echo "<td>";
        echo "<div class='time-cell'>";
        echo "<span class='time-date'>" . $time_formatted . "</span>";
        echo "<span class='time-relative'>" . $time_ago . " ago</span>";
        echo "</div>";
        echo "</td>";
        
        // Event column
        echo "<td>";
        echo "<span class='event-badge " . $event_class . "'>";
        if ($log->action === 'loggedin') {
            echo "<i class='fa fa-sign-in-alt'></i> ";
        } elseif ($log->action === 'viewed') {
            echo "<i class='fa fa-eye'></i> ";
        } elseif ($log->action === 'submitted') {
            echo "<i class='fa fa-paper-plane'></i> ";
        } else {
            echo "<i class='fa fa-bolt'></i> ";
        }
        echo htmlspecialchars($event_name);
        echo "</span>";
        echo "</td>";
        
        // Description column
        echo "<td class='description-text'>" . htmlspecialchars($description) . "</td>";
        
        // Context column
        echo "<td>";
        echo "<a href='" . $context_url . "' class='context-link' target='_blank'>" . $context . "</a>";
        echo "</td>";
        
        // IP Address column
        echo "<td class='ip-address'>" . htmlspecialchars($log->ip) . "</td>";
        
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
    // Pagination controls
    if ($total_pages > 1) {
        echo "<div class='pagination-container'>";
        
        // Pagination info
        echo "<div class='pagination-info'>";
        $showing_from = $offset + 1;
        $showing_to = min($offset + $per_page, $total_count);
        echo "Showing <span>" . $showing_from . "-" . $showing_to . "</span> of <span>" . $total_count . "</span> activities";
        echo "</div>";
        
        // Pagination controls
        echo "<div class='pagination-controls'>";
        
        // Previous button
        if ($page > 1) {
            $prev_url = new moodle_url('/theme/remui_kids/school_manager/activity_log.php', [
                'page' => $page - 1,
                'event_filter' => $event_filter,
                'action_filter' => $action_filter,
                'user_filter' => $user_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search_query
            ]);
            echo "<a href='" . $prev_url->out(false) . "' class='pagination-btn pagination-prev'>";
            echo "<i class='fa fa-chevron-left'></i> Previous";
            echo "</a>";
        } else {
            echo "<span class='pagination-btn pagination-prev disabled'>";
            echo "<i class='fa fa-chevron-left'></i> Previous";
            echo "</span>";
        }
        
        // Page numbers
        echo "<div class='pagination-pages'>";
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        // First page
        if ($start_page > 1) {
            $first_url = new moodle_url('/theme/remui_kids/school_manager/activity_log.php', [
                'page' => 1,
                'event_filter' => $event_filter,
                'action_filter' => $action_filter,
                'user_filter' => $user_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search_query
            ]);
            echo "<a href='" . $first_url->out(false) . "' class='pagination-page'>1</a>";
            if ($start_page > 2) {
                echo "<span class='pagination-ellipsis'>...</span>";
            }
        }
        
        // Page range
        for ($i = $start_page; $i <= $end_page; $i++) {
            $page_url = new moodle_url('/theme/remui_kids/school_manager/activity_log.php', [
                'page' => $i,
                'event_filter' => $event_filter,
                'action_filter' => $action_filter,
                'user_filter' => $user_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search_query
            ]);
            
            if ($i == $page) {
                echo "<span class='pagination-page active'>" . $i . "</span>";
            } else {
                echo "<a href='" . $page_url->out(false) . "' class='pagination-page'>" . $i . "</a>";
            }
        }
        
        // Last page
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo "<span class='pagination-ellipsis'>...</span>";
            }
            $last_url = new moodle_url('/theme/remui_kids/school_manager/activity_log.php', [
                'page' => $total_pages,
                'event_filter' => $event_filter,
                'action_filter' => $action_filter,
                'user_filter' => $user_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search_query
            ]);
            echo "<a href='" . $last_url->out(false) . "' class='pagination-page'>" . $total_pages . "</a>";
        }
        
        echo "</div>"; // End pagination-pages
        
        // Next button
        if ($page < $total_pages) {
            $next_url = new moodle_url('/theme/remui_kids/school_manager/activity_log.php', [
                'page' => $page + 1,
                'event_filter' => $event_filter,
                'action_filter' => $action_filter,
                'user_filter' => $user_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search_query
            ]);
            echo "<a href='" . $next_url->out(false) . "' class='pagination-btn pagination-next'>";
            echo "Next <i class='fa fa-chevron-right'></i>";
            echo "</a>";
        } else {
            echo "<span class='pagination-btn pagination-next disabled'>";
            echo "Next <i class='fa fa-chevron-right'></i>";
            echo "</span>";
        }
        
        echo "</div>"; // End pagination-controls
        echo "</div>"; // End pagination-container
    } else {
        // Show count even if only 1 page
        echo "<div class='pagination-container'>";
        echo "<div class='pagination-info'>";
        echo "Showing <span>" . count($activity_logs) . "</span> of <span>" . $total_count . "</span> activities";
        echo "</div>";
        echo "</div>";
    }
}

echo "</div>"; // End activity-container

echo "</div>"; // End main-content
echo "</div>"; // End school-manager-main-content

echo $OUTPUT->footer();
?>

