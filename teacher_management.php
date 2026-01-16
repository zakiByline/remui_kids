<?php
/**
 * Teacher Management - School Manager
 * List and manage teachers assigned to the school
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

global $USER, $DB, $CFG, $OUTPUT;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher_management.php');
$PAGE->set_title('Teacher Management');
$PAGE->set_heading('Teacher Management');

// Handle bulk delete action (permanent deletion)
if (isset($_POST['bulk_delete']) && isset($_POST['selected_teachers'])) {
    require_sesskey();
    
    $selected_teachers = $_POST['selected_teachers'];
    $deleted_count = 0;
    $errors = [];
    
    if (!empty($selected_teachers) && is_array($selected_teachers)) {
        require_once($CFG->dirroot . '/user/lib.php');
        
        foreach ($selected_teachers as $teacher_id) {
            $teacher_id = (int)$teacher_id;
            
            // Verify the teacher belongs to this company
            if ($company_info) {
                $teacher_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$teacher_id, $company_info->id]
                );
                
                if ($teacher_in_company) {
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user) {
                        // Permanent delete - remove from database
                        $fullname = fullname($user);
                        if (user_delete_user($user)) {
                            $deleted_count++;
                        } else {
                            $errors[] = "Failed to delete teacher '{$fullname}'.";
                        }
                    }
                } else {
                    $errors[] = "Teacher ID {$teacher_id} does not belong to your school.";
                }
            }
        }
        
        if ($deleted_count > 0) {
            $message = "Successfully permanently deleted {$deleted_count} teacher(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No teachers were deleted. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/teacher_management.php'), $message, null, $type);
    }
}

// Handle bulk suspend action
if (isset($_POST['bulk_suspend']) && isset($_POST['selected_teachers'])) {
    require_sesskey();
    
    $selected_teachers = $_POST['selected_teachers'];
    $suspended_count = 0;
    $errors = [];
    
    if (!empty($selected_teachers) && is_array($selected_teachers)) {
        foreach ($selected_teachers as $teacher_id) {
            $teacher_id = (int)$teacher_id;
            
            // Verify the teacher belongs to this company
            if ($company_info) {
                $teacher_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$teacher_id, $company_info->id]
                );
                
                if ($teacher_in_company) {
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user) {
                        // Suspend the teacher (soft delete)
                        $user->suspended = 1;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $suspended_count++;
                    }
                } else {
                    $errors[] = "Teacher ID {$teacher_id} does not belong to your school.";
                }
            }
        }
        
        if ($suspended_count > 0) {
            $message = "Successfully suspended {$suspended_count} teacher(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No teachers were suspended. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/teacher_management.php'), $message, null, $type);
    }
}

// Handle bulk activate action
if (isset($_POST['bulk_activate']) && isset($_POST['selected_teachers'])) {
    require_sesskey();
    
    $selected_teachers = $_POST['selected_teachers'];
    $activated_count = 0;
    $errors = [];
    
    if (!empty($selected_teachers) && is_array($selected_teachers)) {
        foreach ($selected_teachers as $teacher_id) {
            $teacher_id = (int)$teacher_id;
            
            // Verify the teacher belongs to this company
            if ($company_info) {
                $teacher_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$teacher_id, $company_info->id]
                );
                
                if ($teacher_in_company) {
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user) {
                        // Activate the teacher
                        $user->suspended = 0;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $activated_count++;
                    }
                } else {
                    $errors[] = "Teacher ID {$teacher_id} does not belong to your school.";
                }
            }
        }
        
        if ($activated_count > 0) {
            $message = "Successfully activated {$activated_count} teacher(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No teachers were activated. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/teacher_management.php'), $message, null, $type);
    }
}

// Handle actions (delete, activate, deactivate)
if (isset($_GET['action']) && isset($_GET['teacher_id'])) {
    $action = $_GET['action'];
    $teacher_id = (int)$_GET['teacher_id'];
    
    // Verify the teacher belongs to this company
    if ($company_info) {
        $teacher_in_company = $DB->record_exists_sql(
            "SELECT 1 FROM {company_users} cu 
             WHERE cu.userid = ? AND cu.companyid = ?",
            [$teacher_id, $company_info->id]
        );
        
        if ($teacher_in_company) {
            switch ($action) {
                case 'delete':
                    // Soft delete - suspend the user
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user) {
                        $user->suspended = 1;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Teacher '" . fullname($user) . "' has been suspended.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                    }
                    break;
                
                case 'permanent_delete':
                    // Permanent delete - remove from database
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user && confirm_sesskey()) {
                        require_once($CFG->dirroot . '/user/lib.php');
                        
                        // Get full name before deletion
                        $fullname = fullname($user);
                        
                        // Use Moodle's user_delete_user function for proper deletion
                        // This handles all related records properly
                        if (user_delete_user($user)) {
                            $message = "Teacher '" . $fullname . "' has been permanently deleted.";
                            $type = \core\output\notification::NOTIFY_SUCCESS;
                        } else {
                            $message = "Error deleting teacher '" . $fullname . "'.";
                            $type = \core\output\notification::NOTIFY_ERROR;
                        }
                    }
                    break;
                    
                case 'activate':
                    $user = $DB->get_record('user', ['id' => $teacher_id]);
                    if ($user) {
                        $user->suspended = 0;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Teacher '" . fullname($user) . "' has been activated.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                    }
                    break;
            }
            
            if (isset($message)) {
                redirect(new moodle_url('/theme/remui_kids/teacher_management.php'), $message, null, $type);
            }
        }
    }
}

// OPTIMIZED TEACHER LOGIC - Efficient single query approach
$teachers = [];

if ($company_info) {
    // Single optimized query to get all teachers
    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email,
                u.phone1, u.city, u.country, u.suspended, u.lastaccess, u.timecreated, u.picture,
                GROUP_CONCAT(DISTINCT r.shortname) AS roles
         FROM {user} u
         INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
         INNER JOIN {role_assignments} ra ON u.id = ra.userid
         INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
         WHERE u.deleted = 0
         GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.phone1, u.city, u.country, 
                  u.suspended, u.lastaccess, u.timecreated, u.picture
         ORDER BY u.firstname, u.lastname",
        [$company_info->id]
    );
}

// Prepare template data for the school manager sidebar
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info ? $company_info->name : 'School Manager',
    'company_logo_url' => '',
    'has_logo' => false,
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'teachers_active' => true, // This page is for teacher management
    'dashboard_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'bulk_download_active' => false,
    'bulk_profile_upload_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'user_reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar using the correct Moodle method
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='school-manager-sidebar' style='position: fixed; top: 0; left: 0; width: 280px; height: 100vh; background: #d4edda; z-index: 1000;'>";
    echo "<div style='padding: 20px; text-align: center;'>";
    echo "<h2 style='color: #2c3e50; margin: 0;'>" . htmlspecialchars($sidebarcontext['company_name']) . "</h2>";
    echo "<p style='color: #495057; margin: 10px 0;'>" . htmlspecialchars($sidebarcontext['user_info']['fullname']) . "</p>";
    echo "<div style='background: #007bff; color: white; padding: 5px 15px; border-radius: 15px; display: inline-block;'>School Manager</div>";
    echo "</div>";
    echo "<nav style='padding: 20px 0;'>";
    echo "<a href='{$CFG->wwwroot}/my/' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>School Admin Dashboard</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' style='display: block; padding: 15px 20px; color: #007cba; background: #e3f2fd; text-decoration: none; font-weight: 600;'>Teacher Management</a>";
    echo "</nav>";
    echo "</div>";
}

// Custom CSS for the teacher management layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Reset and base styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f8f9fa;
    overflow-x: hidden;
}

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background-color: #ffffff;
    overflow-y: auto;
    z-index: 99;
    padding-top: 35px;
    transition: left 0.3s ease, width 0.3s ease;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 0 30px 30px 30px;
}

/* Header */
.page-header {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 0.75rem;
    padding: 2rem 2.5rem;
    margin-bottom: 2rem;
    margin-top: 0;
    border: 1px solid #e2e8f0;
    box-shadow: 
        0 1px 3px rgba(0, 0, 0, 0.05),
        0 4px 12px rgba(0, 0, 0, 0.04);
    position: relative;
    overflow: visible;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #3b82f6 0%, #06b6d4 100%);
    border-radius: 0.75rem 0 0 0.75rem;
}

.page-header-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}

.page-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.75rem 0;
    letter-spacing: -0.5px;
    line-height: 1.2;
    font-family: 'Inter', 'Segoe UI', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
}

.page-subtitle {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0;
    font-weight: 400;
    line-height: 1.5;
}

.back-button {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border: none;
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 
        0 2px 8px rgba(59, 130, 246, 0.2),
        0 1px 4px rgba(37, 99, 235, 0.15);
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.back-button:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 
        0 4px 12px rgba(59, 130, 246, 0.3),
        0 2px 6px rgba(37, 99, 235, 0.2);
    color: #ffffff;
    text-decoration: none;
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
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 140px;
    border-top: 4px solid transparent;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.stat-card:nth-child(1) {
    border-top-color: #667eea; /* Purple for Total Teachers */
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
}

.stat-card:nth-child(2) {
    border-top-color: #43e97b; /* Green for Active Teachers */
    background: linear-gradient(180deg, rgba(67, 233, 123, 0.08), #ffffff);
}

.stat-card:nth-child(3) {
    border-top-color: #f093fb; /* Pink for Suspended Teachers */
    background: linear-gradient(180deg, rgba(240, 147, 251, 0.08), #ffffff);
}

.stat-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.stat-trend {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.stat-trend .trend-arrow {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.stat-trend .trend-text {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.stat-icon {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 3rem;
    height: 3rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: white;
    transition: all 0.3s ease;
}

.stat-card:nth-child(1) .stat-icon {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.stat-card:nth-child(2) .stat-icon {
    background: linear-gradient(135deg, #43e97b 0%, #38d9a9 100%);
}

.stat-card:nth-child(3) .stat-icon {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.stat-action {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    opacity: 0.6;
    transition: opacity 0.3s ease;
}

.stat-card:hover .stat-action {
    opacity: 1;
}

.stat-link {
    color: #6c757d;
    text-decoration: none;
    font-size: 1.25rem;
    transition: color 0.3s ease;
}

.stat-link:hover {
    color: #495057;
}

/* Teachers Table */
.teachers-container {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
    margin-top: 1.5rem;
    overflow: hidden;
}

.teachers-header {
    background: #f8f9fa;
    padding: 20px 30px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.teachers-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.add-teacher-btn {
    background: #9D7ECE;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.add-teacher-btn:hover {
    background: #8B6FC5;
    color: white;
    text-decoration: none;
}

/* Teacher Actions Container */
.teacher-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.bulk-upload-teacher-btn {
    background: #4A90E2;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.bulk-upload-teacher-btn:hover {
    background: #3A7BC8;
    color: white;
    text-decoration: none;
}

.bulk-upload-teacher-btn i {
    font-size: 14px;
}


/* Search Bar Styles */
.search-section {
    background: white;
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
}

.search-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
}

.search-section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.search-container {
    max-width: 100%;
}

.search-form {
    width: 100%;
}

.search-input-group {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: wrap;
}

.search-field-select {
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    background: white;
    font-size: 0.9rem;
    color: #374151;
    min-width: 160px;
    transition: all 0.2s ease;
}

.search-field-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.search-input {
    flex: 1;
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    font-size: 0.9rem;
    color: #374151;
    min-width: 200px;
    transition: all 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.search-input::placeholder {
    color: #9ca3af;
}

.search-btn {
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.search-btn:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.clear-search-btn {
    padding: 0.75rem 1rem;
    background: #6c757d;
    color: white;
    text-decoration: none;
    border-radius: 0.5rem;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.clear-search-btn:hover {
    background: #5a6268;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    color: white;
    text-decoration: none;
}

.search-results-info {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    background: #e3f2fd;
    border-radius: 0.5rem;
    border-left: 4px solid #007bff;
}

.search-results-count {
    font-size: 0.9rem;
    color: #1976d2;
    font-weight: 500;
}

/* Live Search Enhancements */
.search-input:focus {
    border-color: #007bff !important;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
}

.search-input.searching {
    background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23999\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E');
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 16px 16px;
}

.teachers-table tr {
    transition: opacity 0.2s ease;
}

.teachers-table tr.hidden {
    display: none !important;
}

.search-results-info {
    transition: all 0.3s ease;
}

/* Responsive Search Bar */
@media (max-width: 768px) {
    .search-input-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-field-select,
    .search-input {
        min-width: auto;
        width: 100%;
    }
    
    .search-btn,
    .clear-search-btn {
        width: 100%;
        justify-content: center;
    }
}

/* Table Styles */
.teachers-table {
    width: 100%;
    border-collapse: collapse;
}

.teachers-table th {
    background: #f8f9fa;
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
}

.teachers-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

/* Card-like row visuals inspired by provided mock */
.teachers-table tbody tr {
    background: #fff;
}

.teachers-table tbody tr td {
    border-bottom: 0;
}

.teachers-table tbody tr + tr td {
    border-top: 1px solid #f0f2f5;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4f46e5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    transition: all 0.2s ease;
    overflow: hidden;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
}

.status-dot {
    position: absolute;
    right: -2px;
    bottom: -2px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #22c55e; /* active */
    border: 2px solid #fff;
}

.user-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}

.user-meta .primary {
    font-weight: 600;
    color: #111827;
}

.user-meta .secondary {
    font-size: 0.8rem;
    color: #6b7280;
}

.teachers-table tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.teachers-table tbody tr:hover .user-avatar {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.2);
}

.teacher-name {
    font-weight: 600;
    color: #1f2937;
}

.teacher-email {
    color: #6b7280;
    font-size: 0.9rem;
}

.teacher-grade {
    background: #e0f2fe;
    color: #0369a1;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-suspended {
    background: #fef2f2;
    color: #dc2626;
}

.action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: flex-start;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
}

.btn-view {
    background: #dbeafe;
    color: #1e40af;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-view:hover {
    background: #bfdbfe;
    color: #1e3a8a;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(30, 64, 175, 0.2);
}

.btn-edit {
    background: #f3f4f6;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-edit:hover {
    background: #e5e7eb;
    color: #1f2937;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn-suspend {
    background: #fef3c7;
    color: #d97706;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-suspend:hover {
    background: #fde68a;
    color: #b45309;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(217, 119, 6, 0.2);
}

.btn-delete {
    background: #fee2e2;
    color: #dc2626;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-delete:hover {
    background: #fecaca;
    color: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
}

.btn-activate {
    background: #dcfce7;
    color: #166534;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-activate:hover {
    background: #bbf7d0;
    color: #14532d;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(22, 101, 52, 0.2);
}

.btn-delete {
    background: #fef2f2;
    color: #dc2626;
}

.btn-delete:hover {
    background: #fecaca;
    color: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state-icon {
    font-size: 4rem;
    color: #d1d5db;
    margin-bottom: 20px;
}

.empty-state-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 10px;
}

.empty-state-text {
    font-size: 1rem;
    color: #6b7280;
    margin-bottom: 20px;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10px 0;
    margin-top: 10px;
    background: transparent;
    border: none;
    box-shadow: none;
    gap: 10px;
}

.pagination-info {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
    text-align: center;
    order: 2;
}

.pagination-info span {
    color: #1f2937;
    font-weight: 600;
}

.pagination-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    order: 1;
}

.pagination-btn {
    padding: 8px 16px;
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.pagination-btn:hover:not(:disabled) {
    background: #f3f4f6;
    border-color: #9ca3af;
    transform: translateY(-1px);
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-numbers {
    display: flex;
    gap: 5px;
}

.pagination-number {
    padding: 8px 12px;
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 40px;
    text-align: center;
}

.pagination-number:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.pagination-number.active {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
    border-color: #007bff;
}

/* Tablet Responsive (768px - 1024px) */
@media (max-width: 1024px) and (min-width: 769px) {
    .school-manager-main-content {
        left: 240px;
        width: calc(100vw - 240px);
    }
    
    .main-content {
        padding: 0 20px 30px 20px;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
}

/* Mobile Responsive (max-width: 768px) */
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
        top: 55px;
        height: calc(100vh - 55px);
        padding-top: 80px;
    }
    
    .main-content {
        padding: 0 15px 30px 15px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.5rem 1.5rem;
        margin-top: 0;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .back-button {
        width: 100%;
        justify-content: center;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .stat-card {
        padding: 1rem;
        min-height: 100px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
        margin-bottom: 10px;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
    }
    
    .teachers-table {
        font-size: 0.9rem;
    }
    
    .teachers-table th,
    .teachers-table td {
        padding: 10px 15px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 5px;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 15px;
        padding: 15px 20px;
    }
    
    .pagination-controls {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .pagination-btn {
        font-size: 0.8rem;
        padding: 6px 12px;
    }
    
    .pagination-number {
        padding: 6px 10px;
        min-width: 35px;
        font-size: 0.8rem;
    }
}

/* Small Mobile Responsive (max-width: 480px) */
@media (max-width: 480px) {
    .school-manager-main-content {
        padding-top: 70px;
    }
    
    .page-header {
        padding: 1.5rem 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .stats-section {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .stat-card {
        min-height: 90px;
    }
    
    .stat-number {
        font-size: 1.6rem;
    }
}
";
echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Teacher Management</h1>";
echo "<p class='page-subtitle'>Manage and monitor all teachers in " . htmlspecialchars($company_info->name) . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";

// Statistics cards
$total_teachers = count($teachers);
$active_teachers = count(array_filter($teachers, function($teacher) { return !$teacher->suspended; }));
$suspended_teachers = $total_teachers - $active_teachers;

// Debug output removed for performance optimization

echo "<div class='stats-section'>";
echo "<div class='stat-card'>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>" . $total_teachers . "</div>";
echo "<div class='stat-label'>TOTAL TEACHERS</div>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>Active</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-chalkboard-teacher'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";
echo "<div class='stat-card'>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>" . $active_teachers . "</div>";
echo "<div class='stat-label'>ACTIVE TEACHERS</div>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>Active</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-check'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";
echo "<div class='stat-card'>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>" . $suspended_teachers . "</div>";
echo "<div class='stat-label'>SUSPENDED TEACHERS</div>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↓</span>";
echo "<span class='trend-text'>Inactive</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-times'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";
echo "</div>";

// Add search functionality
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_field = isset($_GET['search_field']) ? $_GET['search_field'] : 'all';
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';

// Filter teachers based on search if provided
if (!empty($search_query)) {
    $filtered_teachers = [];
    $search_lower = strtolower($search_query);
    
    foreach ($teachers as $teacher) {
        $match = false;
        $relevance_score = 0;
        
        switch ($search_field) {
            case 'name':
                $full_name = strtolower($teacher->firstname . ' ' . $teacher->lastname);
                $first_name = strtolower($teacher->firstname);
                $last_name = strtolower($teacher->lastname);
                
                if ($full_name === $search_lower) {
                    $match = true;
                    $relevance_score = 100; // Exact match
                } elseif ($first_name === $search_lower || $last_name === $search_lower) {
                    $match = true;
                    $relevance_score = 90; // Exact first or last name match
                } elseif (strpos($full_name, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 80; // Starts with search term
                } elseif (strpos($first_name, $search_lower) === 0 || strpos($last_name, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 70; // First or last name starts with search term
                } elseif (strpos($full_name, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 60; // Contains search term
                }
                break;
                
            case 'email':
                $email_lower = strtolower($teacher->email);
                if ($email_lower === $search_lower) {
                    $match = true;
                    $relevance_score = 100; // Exact match
                } elseif (strpos($email_lower, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 80; // Starts with search term
                } elseif (strpos($email_lower, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 60; // Contains search term
                }
                break;
                
            case 'role':
                $role_lower = strtolower($teacher->roles ?: '');
                if ($role_lower === $search_lower) {
                    $match = true;
                    $relevance_score = 100; // Exact match
                } elseif (strpos($role_lower, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 60; // Contains search term
                }
                break;
                
            case 'username':
                $username_lower = strtolower($teacher->username);
                if ($username_lower === $search_lower) {
                    $match = true;
                    $relevance_score = 100; // Exact match
                } elseif (strpos($username_lower, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 80; // Starts with search term
                } elseif (strpos($username_lower, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 60; // Contains search term
                }
                break;
                
            case 'all':
            default:
                $full_name = strtolower($teacher->firstname . ' ' . $teacher->lastname);
                $first_name = strtolower($teacher->firstname);
                $last_name = strtolower($teacher->lastname);
                $email_lower = strtolower($teacher->email);
                $username_lower = strtolower($teacher->username);
                $role_lower = strtolower($teacher->roles ?: '');
                
                // Check for exact matches first
                if ($full_name === $search_lower) {
                    $match = true;
                    $relevance_score = 100;
                } elseif ($first_name === $search_lower || $last_name === $search_lower) {
                    $match = true;
                    $relevance_score = 95;
                } elseif ($email_lower === $search_lower || $username_lower === $search_lower) {
                    $match = true;
                    $relevance_score = 90;
                }
                // Check for starts with matches
                elseif (strpos($full_name, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 85;
                } elseif (strpos($first_name, $search_lower) === 0 || strpos($last_name, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 80;
                } elseif (strpos($email_lower, $search_lower) === 0 || strpos($username_lower, $search_lower) === 0) {
                    $match = true;
                    $relevance_score = 75;
                }
                // Check for contains matches
                elseif (strpos($full_name, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 70;
                } elseif (strpos($first_name, $search_lower) !== false || strpos($last_name, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 65;
                } elseif (strpos($email_lower, $search_lower) !== false || strpos($username_lower, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 60;
                } elseif (strpos($role_lower, $search_lower) !== false) {
                    $match = true;
                    $relevance_score = 50;
                }
                break;
        }
        
        if ($match) {
            // Add relevance score to teacher object for sorting
            $teacher->relevance_score = $relevance_score;
            $filtered_teachers[] = $teacher;
        }
    }
    
    // Sort by relevance score (highest first), then by name
    usort($filtered_teachers, function($a, $b) {
        if ($a->relevance_score == $b->relevance_score) {
            // If same relevance, sort by name
            return strcasecmp($a->firstname . ' ' . $a->lastname, $b->firstname . ' ' . $b->lastname);
        }
        return $b->relevance_score - $a->relevance_score;
    });
    
    $teachers = $filtered_teachers;
}

// Apply status filter
if ($status_filter !== 'all') {
    $status_filtered_teachers = [];
    foreach ($teachers as $teacher) {
        if ($status_filter === 'active' && !$teacher->suspended) {
            $status_filtered_teachers[] = $teacher;
        } elseif ($status_filter === 'suspended' && $teacher->suspended) {
            $status_filtered_teachers[] = $teacher;
        }
    }
    $teachers = $status_filtered_teachers;
}

// Search Bar - Separate section
echo "<div class='search-section'>";
echo "<div class='search-section-header'>";
echo "<h2 class='search-section-title'>Search Teachers</h2>";
echo "<div class='teacher-actions'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/teacher_bulk_upload.php' class='bulk-upload-teacher-btn'>";
echo "<i class='fa fa-upload'></i> Bulk Upload Teacher";
echo "</a>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/add_teacher.php' class='add-teacher-btn'>+ Add New Teacher</a>";
echo "</div>";
echo "</div>";
echo "<div class='search-container'>";
echo "<form method='GET' class='search-form' id='search-form'>";
echo "<div class='search-input-group'>";
echo "<select name='search_field' id='search-field-select' class='search-field-select'>";
echo "<option value='all'" . ($search_field === 'all' ? ' selected' : '') . ">Search All Fields</option>";
echo "<option value='name'" . ($search_field === 'name' ? ' selected' : '') . ">Name</option>";
echo "<option value='email'" . ($search_field === 'email' ? ' selected' : '') . ">Email</option>";
echo "<option value='role'" . ($search_field === 'role' ? ' selected' : '') . ">Role</option>";
echo "<option value='username'" . ($search_field === 'username' ? ' selected' : '') . ">Username</option>";
echo "</select>";
echo "<select name='status_filter' id='status-filter-select' class='search-field-select'>";
echo "<option value='all'" . ($status_filter === 'all' ? ' selected' : '') . ">All Status</option>";
echo "<option value='active'" . ($status_filter === 'active' ? ' selected' : '') . ">Active</option>";
echo "<option value='suspended'" . ($status_filter === 'suspended' ? ' selected' : '') . ">Suspended</option>";
echo "</select>";
echo "<input type='text' name='search' id='search-input' placeholder='Enter search term...' value='" . htmlspecialchars($search_query) . "' class='search-input'>";
echo "<button type='submit' class='search-btn'>";
echo "<i class='fa fa-search'></i> Search";
echo "</button>";
if (!empty($search_query) || $status_filter !== 'all') {
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='clear-search-btn'>";
    echo "<i class='fa fa-times'></i> Clear";
    echo "</a>";
}
echo "</div>";
echo "</form>";
echo "</div>";

// Search results info
if (!empty($search_query)) {
    $total_teachers_count = count($teachers);
    echo "<div class='search-results-info'>";
    echo "<span class='search-results-count'>Found {$total_teachers_count} teacher(s) matching '{$search_query}'</span>";
    echo "</div>";
}

echo "</div>";

// Teachers table - Separate section
echo "<div class='teachers-container'>";
echo "<div class='teachers-header'>";
echo "<h2 class='teachers-title'>Teachers List</h2>";
echo "</div>";

if (empty($teachers)) {
    echo "<div class='empty-state'>";
    echo "<div class='empty-state-icon'>👨‍🏫</div>";
    echo "<div class='empty-state-title'>No Teachers Found</div>";
    echo "<div class='empty-state-text'>There are no teachers assigned to your school yet.</div>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/add_teacher.php' class='add-teacher-btn'>Add Your First Teacher</a>";
    echo "</div>";
} else {
    // Bulk action form
    echo "<form method='POST' id='bulk-delete-form'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    
    echo "<table class='teachers-table' id='teachers-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th style='width: 40px;'><input type='checkbox' id='select-all' title='Select All'></th>";
    echo "<th>Teacher</th>";
    echo "<th>Email</th>";
    echo "<th>Role</th>";
    echo "<th>Status</th>";
    echo "<th>Last Access</th>";
    echo "<th>Actions</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($teachers as $teacher) {
        $status_class = $teacher->suspended ? 'status-suspended' : 'status-active';
        $status_text = $teacher->suspended ? 'Suspended' : 'Active';
        
        $last_access = $teacher->lastaccess ? date('M j, Y', $teacher->lastaccess) : 'Never';
        
        echo "<tr data-name='" . htmlspecialchars(strtolower($teacher->firstname . ' ' . $teacher->lastname)) . "' data-email='" . htmlspecialchars(strtolower($teacher->email)) . "' data-role='" . htmlspecialchars(strtolower($teacher->roles ?: '')) . "' data-username='" . htmlspecialchars(strtolower($teacher->username)) . "' data-suspended='" . ($teacher->suspended ? '1' : '0') . "'>";
        
        // Checkbox column
        echo "<td style='text-align: center;'>";
        echo "<input type='checkbox' name='selected_teachers[]' value='{$teacher->id}' class='teacher-checkbox' data-suspended='" . ($teacher->suspended ? '1' : '0') . "'>";
        echo "</td>";
        
        // Get teacher profile image using Moodle's user_picture class
        $teacher_user = $DB->get_record('user', ['id' => $teacher->id]);
        
        // Check if teacher has a profile picture
        $has_profile_picture = false;
        $profile_image_url = '';
        
        if ($teacher_user && $teacher_user->picture > 0) {
            // Verify the file actually exists in file storage
            $user_context = context_user::instance($teacher_user->id);
            $fs = get_file_storage();
            
            // Check if file exists in 'icon' area (where Moodle stores profile pics)
            $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
            
            if (!empty($files)) {
                // File exists, safe to generate URL
                try {
                    $user_picture = new user_picture($teacher_user);
                    $user_picture->size = 1; // f1 = small thumbnail
                    $profile_image_url = $user_picture->get_url($PAGE)->out(false);
                    
                    // Set flag to true if we have a valid URL
                    if (!empty($profile_image_url)) {
                        $has_profile_picture = true;
                    }
                } catch (Exception $e) {
                    error_log("Error generating teacher profile image URL for user ID " . $teacher_user->id . ": " . $e->getMessage());
                    $has_profile_picture = false;
                }
            } else {
                // Picture field is set but file doesn't exist - reset the field
                $teacher_user->picture = 0;
                $teacher_user->timemodified = time();
                $DB->update_record('user', $teacher_user);
                $has_profile_picture = false;
            }
        }
        
        // Fallback initials if no profile image
        $initials = strtoupper(substr($teacher->firstname, 0, 1) . substr($teacher->lastname, 0, 1));
        
        echo "<td>";
        echo "<div class='user-cell'>";
        echo "  <div class='user-avatar'>";
        
        if ($has_profile_picture && !empty($profile_image_url)) {
            // Show actual profile picture with initials as fallback
            echo "    <img src='" . htmlspecialchars($profile_image_url) . "' ";
            echo "         alt='" . htmlspecialchars($teacher->firstname . ' ' . $teacher->lastname) . "' ";
            echo "         onerror='console.log(\"Failed to load teacher image:\", this.src); this.style.display=\"none\"; this.nextElementSibling.style.display=\"flex\";' ";
            echo "         style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block;'>";
            echo "    <span style='display: none; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
        } else {
            // Show initials placeholder
            echo "    <span style='display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
        }
        
        // Additional safety: Add a data attribute to help debug
        if ($teacher_user) {
            echo "<!-- Teacher ID: " . $teacher_user->id . ", Picture field: " . $teacher_user->picture . " -->";
        }
        
        echo (!$teacher->suspended ? "<span class='status-dot'></span>" : "") . "</div>";
        echo "  <div class='user-meta'>";
        echo "    <span class='primary'>" . htmlspecialchars($teacher->firstname . ' ' . $teacher->lastname) . "</span>";
        echo "    <span class='secondary'>@" . htmlspecialchars($teacher->username) . "</span>";
        echo "  </div>";
        echo "</div>";
        echo "</td>";
        echo "<td class='teacher-email'>" . htmlspecialchars($teacher->email) . "</td>";
        echo "<td>";
        $role_label = 'teacher';
        if (!empty($teacher->roles)) {
            $roleparts = explode(',', strtolower($teacher->roles));
            $role_label = str_replace('_', ' ', trim($roleparts[0]));
        }
        echo "<span class='teacher-grade'>" . htmlspecialchars(ucwords($role_label)) . "</span>";
        echo "</td>";
        echo "<td><span class='status-badge $status_class'>$status_text</span></td>";
        echo "<td>" . $last_access . "</td>";
        echo "<td>";
        echo "<div class='action-buttons'>";
        
        // View button
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_profile.php?id=" . $teacher->id . "' class='action-btn btn-view' title='View Teacher Profile'>";
        echo "<i class='fa fa-eye'></i> View";
        echo "</a>";
        
        // Edit button
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/edit_teacher.php?id=" . $teacher->id . "' class='action-btn btn-edit' title='Edit Teacher'>";
        echo "<i class='fa fa-edit'></i> Edit";
        echo "</a>";
        
        // Delete button (Permanent deletion)
        echo "<a href='?action=permanent_delete&teacher_id=" . $teacher->id . "&sesskey=" . sesskey() . "' class='action-btn btn-delete' title='Delete Teacher Permanently' onclick='return confirm(\"⚠️ WARNING: This will permanently delete the teacher and all their data from the database.\\n\\nThis action cannot be undone!\\n\\nAre you absolutely sure you want to delete " . addslashes(fullname($teacher)) . "?\")'>";
        echo "<i class='fa fa-trash'></i> Delete";
        echo "</a>";
        
        // Suspend/Activate button
        if ($teacher->suspended) {
            echo "<a href='?action=activate&teacher_id=" . $teacher->id . "' class='action-btn btn-activate' title='Activate Teacher' onclick='return confirm(\"Are you sure you want to activate this teacher?\")'>";
            echo "<i class='fa fa-check'></i> Activate";
            echo "</a>";
        } else {
            echo "<a href='?action=delete&teacher_id=" . $teacher->id . "' class='action-btn btn-suspend' title='Suspend Teacher' onclick='return confirm(\"Are you sure you want to suspend this teacher?\")'>";
            echo "<i class='fa fa-ban'></i> Suspend";
            echo "</a>";
        }
        
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    // Bulk action buttons
    echo "<div class='bulk-actions' id='bulk-actions' style='display: none; margin: 20px 0; padding: 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #f59e0b;'>";
    echo "<span id='selected-count' style='font-weight: 600; color: #1f2937; margin-right: 20px;'>0 teachers selected</span>";
    echo "<div style='display: flex; gap: 12px;'>";
    echo "<button type='button' id='btn-bulk-suspend-activate' onclick='confirmBulkSuspendOrActivate()' class='btn-bulk-suspend' style='background: #f59e0b; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;'>";
    echo "<i class='fa fa-pause' id='suspend-activate-icon'></i> <span id='suspend-activate-text'>Suspend Selected</span>";
    echo "</button>";
    echo "<button type='button' onclick='confirmBulkDelete()' class='btn-bulk-delete' style='background: #ef4444; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;'>";
    echo "<i class='fa fa-trash'></i> Delete Selected";
    echo "</button>";
    echo "</div>";
    echo "</div>";
    
    echo "</form>";
}

echo "</div>"; // End teachers-container

// Add pagination controls - Always show when there are teachers (OUTSIDE the container)
if (!empty($teachers)) {
    echo "<div class='pagination-container' id='pagination-container'>";
    echo "<div class='pagination-controls'>";
    echo "<button id='prev_page' class='pagination-btn' onclick='changePage(-1)' disabled>";
    echo "<i class='fa fa-chevron-left'></i> Previous";
    echo "</button>";
    echo "<div id='pagination_numbers' class='pagination-numbers'></div>";
    echo "<button id='next_page' class='pagination-btn' onclick='changePage(1)'>";
    echo "Next <i class='fa fa-chevron-right'></i>";
    echo "</button>";
    echo "</div>";
    echo "<div class='pagination-info'>";
    echo "Showing <span id='current_start'>1</span>-<span id='current_end'>10</span> of <span id='total_teachers'>" . count($teachers) . "</span> teachers";
    echo "</div>";
    echo "</div>";
}

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// Add pagination JavaScript
echo "<script>
// Pagination variables - GLOBAL scope for access by search function
var currentPage = 1;
var perPage = 10;
var totalPages = 1;
var allRows = [];

// Make initializePagination globally accessible
function initializePagination() {
    const teachersTable = document.getElementById('teachers-table');
    if (!teachersTable) {
        console.log('Teachers table not found');
        return;
    }
    
    const tbody = teachersTable.querySelector('tbody');
    if (!tbody) {
        console.log('Table body not found');
        return;
    }
    
    allRows = Array.from(tbody.querySelectorAll('tr'));
    totalPages = Math.ceil(allRows.length / perPage);
    
    console.log('Pagination initialized:', {
        totalRows: allRows.length,
        perPage: perPage,
        totalPages: totalPages
    });
    
    // Always update pagination display
    updatePaginationDisplay();
    
    if (totalPages > 1) {
        // Hide rows beyond first page
        allRows.forEach((row, index) => {
            if (index >= perPage) {
                row.style.display = 'none';
            }
        });
        
        // Show pagination controls
        const prevBtn = document.getElementById('prev_page');
        const nextBtn = document.getElementById('next_page');
        const paginationNumbers = document.getElementById('pagination_numbers');
        
        if (prevBtn) prevBtn.style.display = 'flex';
        if (nextBtn) nextBtn.style.display = 'flex';
        if (paginationNumbers) paginationNumbers.style.display = 'flex';
        
        renderPaginationNumbers();
    } else {
        // Single page - hide navigation but keep info visible
        const prevBtn = document.getElementById('prev_page');
        const nextBtn = document.getElementById('next_page');
        const paginationNumbers = document.getElementById('pagination_numbers');
        
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
        if (paginationNumbers) paginationNumbers.style.display = 'none';
        
        // Update the end count for single page
        const endEl = document.getElementById('current_end');
        if (endEl) {
            endEl.textContent = allRows.length;
        }
    }
}

function showPage(page) {
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    
    // Show/hide rows based on current page
    allRows.forEach((row, index) => {
        const startIndex = (page - 1) * perPage;
        const endIndex = startIndex + perPage;
        
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update pagination display
    updatePaginationDisplay();
    renderPaginationNumbers();
    
    // Scroll to top of table smoothly
    const teachersContainer = document.querySelector('.teachers-container');
    if (teachersContainer) {
        teachersContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updatePaginationDisplay() {
    const startNum = ((currentPage - 1) * perPage) + 1;
    const endNum = Math.min(currentPage * perPage, allRows.length);
    
    const startEl = document.getElementById('current_start');
    const endEl = document.getElementById('current_end');
    
    if (startEl) startEl.textContent = startNum;
    if (endEl) endEl.textContent = endNum;
    
    // Update button states
    const prevBtn = document.getElementById('prev_page');
    const nextBtn = document.getElementById('next_page');
    
    if (prevBtn) prevBtn.disabled = (currentPage === 1);
    if (nextBtn) nextBtn.disabled = (currentPage === totalPages);
}

function renderPaginationNumbers() {
    const container = document.getElementById('pagination_numbers');
    if (!container) return;
    
    container.innerHTML = '';
    
    // Show max 5 page numbers at a time
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    
    // Adjust if we're near the end
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }
    
    // Add first page if not visible
    if (startPage > 1) {
        addPageButton(1);
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
    }
    
    // Add page numbers
    for (let i = startPage; i <= endPage; i++) {
        addPageButton(i);
    }
    
    // Add last page if not visible
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
        addPageButton(totalPages);
    }
}

function addPageButton(pageNum) {
    const container = document.getElementById('pagination_numbers');
    const pageBtn = document.createElement('button');
    pageBtn.className = 'pagination-number' + (pageNum === currentPage ? ' active' : '');
    pageBtn.textContent = pageNum;
    pageBtn.onclick = () => showPage(pageNum);
    container.appendChild(pageBtn);
}

function changePage(direction) {
    const newPage = currentPage + direction;
    if (newPage >= 1 && newPage <= totalPages) {
        showPage(newPage);
    }
}

// Initialize pagination on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing pagination on page load...');
    initializePagination();
});
</script>";

// Bulk delete JavaScript
echo "<script>
// ========================================
// BULK DELETE FUNCTIONALITY
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select-all');
    const teacherCheckboxes = document.querySelectorAll('.teacher-checkbox');
    const bulkActionsDiv = document.getElementById('bulk-actions');
    const selectedCountSpan = document.getElementById('selected-count');
    
    if (selectAllCheckbox && bulkActionsDiv) {
        selectAllCheckbox.addEventListener('change', function() {
            teacherCheckboxes.forEach(function(checkbox) {
                // Only check visible rows
                const row = checkbox.closest('tr');
                if (row && row.style.display !== 'none') {
                    checkbox.checked = selectAllCheckbox.checked;
                }
            });
            updateBulkActions();
        });
    }
    
    // Update bulk actions when individual checkboxes change
    teacherCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateBulkActions();
            updateSelectAllState();
        });
    });
    
    // Update Select All checkbox state
    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        
        const visibleCheckboxes = Array.from(teacherCheckboxes).filter(function(cb) {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const totalVisible = visibleCheckboxes.length;
        const checkedVisible = visibleCheckboxes.filter(function(cb) { return cb.checked; }).length;
        
        if (checkedVisible === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedVisible === totalVisible) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    // Show/hide bulk actions and update count
    function updateBulkActions() {
        if (!bulkActionsDiv || !selectedCountSpan) return;
        
        const checkedCheckboxes = document.querySelectorAll('.teacher-checkbox:checked');
        const count = checkedCheckboxes.length;
        
        if (count > 0) {
            bulkActionsDiv.style.display = 'block';
            selectedCountSpan.textContent = count + ' teacher' + (count > 1 ? 's' : '') + ' selected';
            
            // Check if all selected teachers are suspended
            updateSuspendActivateButton(checkedCheckboxes);
        } else {
            bulkActionsDiv.style.display = 'none';
        }
    }
    
    // Update the suspend/activate button based on selection
    function updateSuspendActivateButton(checkedCheckboxes) {
        const button = document.getElementById('btn-bulk-suspend-activate');
        const icon = document.getElementById('suspend-activate-icon');
        const text = document.getElementById('suspend-activate-text');
        
        if (!button || !icon || !text) return;
        
        let allSuspended = true;
        let anySuspended = false;
        
        checkedCheckboxes.forEach(function(checkbox) {
            const isSuspended = checkbox.getAttribute('data-suspended') === '1';
            if (!isSuspended) {
                allSuspended = false;
            }
            if (isSuspended) {
                anySuspended = true;
            }
        });
        
        // If ALL selected teachers are suspended, show Activate Selected button
        if (allSuspended) {
            text.textContent = 'Activate Selected';
            icon.className = 'fa fa-check';
            button.style.background = '#4A90E2'; // Light navy blue color
            button.setAttribute('data-action', 'activate');
        } else {
            // Otherwise, show Suspend Selected button
            text.textContent = 'Suspend Selected';
            icon.className = 'fa fa-pause';
            button.style.background = '#f59e0b'; // Orange color
            button.setAttribute('data-action', 'suspend');
        }
    }
    
    console.log('Bulk actions (Suspend & Delete) functionality initialized');
});

// Confirm and submit bulk suspend or activate (global function)
function confirmBulkSuspendOrActivate() {
    const checkedCheckboxes = document.querySelectorAll('.teacher-checkbox:checked');
    const count = checkedCheckboxes.length;
    
    if (count === 0) {
        alert('Please select at least one teacher.');
        return false;
    }
    
    // Check if the action is to activate or suspend
    const button = document.getElementById('btn-bulk-suspend-activate');
    const action = button ? button.getAttribute('data-action') : 'suspend';
    
    // Get names of selected teachers
    const teacherNames = [];
    checkedCheckboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        if (row) {
            const nameCell = row.querySelector('.primary');
            if (nameCell) {
                teacherNames.push(nameCell.textContent.trim());
            }
        }
    });
    
    let confirmMessage = '';
    
    if (action === 'activate') {
        confirmMessage = '✅ ACTIVATE: You are about to activate ' + count + ' teacher(s):\\n\\n';
        
        // Show first 5 names
        const displayNames = teacherNames.slice(0, 5);
        confirmMessage += displayNames.join('\\n');
        
        if (teacherNames.length > 5) {
            confirmMessage += '\\n... and ' + (teacherNames.length - 5) + ' more';
        }
        
        confirmMessage += '\\n\\nThese teachers will be ACTIVATED and able to log in.\\n';
        confirmMessage += 'Are you sure you want to continue?';
    } else {
        confirmMessage = '⏸️ SUSPEND: You are about to suspend ' + count + ' teacher(s):\\n\\n';
        
        // Show first 5 names
        const displayNames = teacherNames.slice(0, 5);
        confirmMessage += displayNames.join('\\n');
        
        if (teacherNames.length > 5) {
            confirmMessage += '\\n... and ' + (teacherNames.length - 5) + ' more';
        }
        
        confirmMessage += '\\n\\nThese teachers will be SUSPENDED and unable to log in.\\n';
        confirmMessage += 'You can activate them later if needed.\\n\\n';
        confirmMessage += 'Are you sure you want to continue?';
    }
    
    if (confirm(confirmMessage)) {
        // Submit the form
        const form = document.getElementById('bulk-delete-form');
        const input = document.createElement('input');
        input.type = 'hidden';
        
        if (action === 'activate') {
            input.name = 'bulk_activate';
        } else {
            input.name = 'bulk_suspend';
        }
        
        input.value = '1';
        form.appendChild(input);
        form.submit();
        return true;
    }
    
    return false;
}

// Confirm and submit bulk delete (permanent deletion) - global function
function confirmBulkDelete() {
    const checkedCheckboxes = document.querySelectorAll('.teacher-checkbox:checked');
    const count = checkedCheckboxes.length;
    
    if (count === 0) {
        alert('Please select at least one teacher to delete.');
        return false;
    }
    
    // Get names of selected teachers
    const teacherNames = [];
    checkedCheckboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        if (row) {
            const nameCell = row.querySelector('.primary');
            if (nameCell) {
                teacherNames.push(nameCell.textContent.trim());
            }
        }
    });
    
    let confirmMessage = '⚠️ PERMANENT DELETE: You are about to PERMANENTLY DELETE ' + count + ' teacher(s):\\n\\n';
    
    // Show first 5 names
    const displayNames = teacherNames.slice(0, 5);
    confirmMessage += displayNames.join('\\n');
    
    if (teacherNames.length > 5) {
        confirmMessage += '\\n... and ' + (teacherNames.length - 5) + ' more';
    }
    
    confirmMessage += '\\n\\n⚠️ WARNING: This action CANNOT be undone!\\n';
    confirmMessage += 'The teachers will be PERMANENTLY DELETED from the database.\\n';
    confirmMessage += 'All their data, courses, and records will be removed.\\n\\n';
    confirmMessage += 'Are you absolutely sure you want to continue?';
    
    if (confirm(confirmMessage)) {
        // Double confirmation for permanent delete
        if (confirm('⚠️ FINAL WARNING: This will PERMANENTLY DELETE ' + count + ' teacher(s) from the database.\\n\\nThis cannot be undone!\\n\\nClick OK to proceed with permanent deletion.')) {
            // Submit the form
            const form = document.getElementById('bulk-delete-form');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_delete';
            input.value = '1';
            form.appendChild(input);
            form.submit();
            return true;
        }
    }
    
    return false;
}
</script>";

echo $OUTPUT->footer();
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const searchFieldSelect = document.getElementById('search-field-select');
    const statusFilterSelect = document.getElementById('status-filter-select');
    const teachersTable = document.getElementById('teachers-table');
    const searchResultsInfo = document.querySelector('.search-results-info');
    
    if (!searchInput || !teachersTable) {
        console.log('Search elements not found');
        return;
    }
    
    // Function to perform live search
    function performLiveSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const searchField = searchFieldSelect.value;
        const statusFilter = statusFilterSelect ? statusFilterSelect.value : 'all';
        const tableRows = teachersTable.querySelectorAll('tbody tr');
        let visibleCount = 0;
        
        // If search is active, show matching rows and disable pagination
        if (searchTerm !== '') {
            tableRows.forEach(function(row) {
                // First check status filter
                const isSuspended = row.getAttribute('data-suspended') === '1';
                let statusMatch = false;
                
                if (statusFilter === 'all') {
                    statusMatch = true;
                } else if (statusFilter === 'active' && !isSuspended) {
                    statusMatch = true;
                } else if (statusFilter === 'suspended' && isSuspended) {
                    statusMatch = true;
                }
                
                // If status doesn't match, hide row
                if (!statusMatch) {
                    row.style.display = 'none';
                    return;
                }
                
                let match = false;
                // Get data attributes from the row
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const role = row.getAttribute('data-role') || '';
                const username = row.getAttribute('data-username') || '';
                
                // Check match based on selected field
                switch (searchField) {
                    case 'name':
                        match = name.includes(searchTerm);
                        break;
                    case 'email':
                        match = email.includes(searchTerm);
                        break;
                    case 'role':
                        match = role.includes(searchTerm);
                        break;
                    case 'username':
                        match = username.includes(searchTerm);
                        break;
                    case 'all':
                    default:
                        match = name.includes(searchTerm) || 
                               email.includes(searchTerm) || 
                               role.includes(searchTerm) || 
                               username.includes(searchTerm);
                        break;
                }
                
                // Show/hide row based on match
                if (match) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update search results info
            updateSearchResultsInfo(searchTerm, visibleCount, tableRows.length);
            
            // Sort visible rows by relevance if there's a search term
            sortRowsByRelevance(searchTerm, searchField);
            
            // Hide pagination controls during search
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                paginationContainer.style.display = 'none';
            }
        } else {
            // Search is empty - apply status filter and restore pagination
            console.log('Search cleared - applying status filter');
            
            // Apply status filter first
            const statusFilter = statusFilterSelect ? statusFilterSelect.value : 'all';
            if (statusFilter !== 'all') {
                tableRows.forEach(function(row) {
                    const isSuspended = row.getAttribute('data-suspended') === '1';
                    if ((statusFilter === 'active' && !isSuspended) || 
                        (statusFilter === 'suspended' && isSuspended)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Hide pagination when status filter is active
                const paginationContainer = document.querySelector('.pagination-container');
                if (paginationContainer) {
                    paginationContainer.style.display = 'none';
                }
            } else {
                // No status filter - make all rows visible
                tableRows.forEach(function(row) {
                    row.style.display = '';
                });
                
                // Then reinitialize pagination to show only first 10 rows
                if (typeof initializePagination === 'function') {
                    initializePagination();
                }
            
            // Show pagination controls
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                paginationContainer.style.display = 'flex';
            }
            
            // Update pagination display
            if (typeof updatePaginationDisplay === 'function') {
                updatePaginationDisplay();
            }
            if (typeof renderPaginationNumbers === 'function') {
                renderPaginationNumbers();
            }
            
            // Hide search results info
            updateSearchResultsInfo('', 0, tableRows.length);
        }
    }
    
    // Function to sort rows by relevance
    function sortRowsByRelevance(searchTerm, searchField) {
        const tbody = teachersTable.querySelector('tbody');
        const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(row => row.style.display !== 'none');
        
        visibleRows.sort(function(a, b) {
            const aScore = calculateRelevanceScore(a, searchTerm, searchField);
            const bScore = calculateRelevanceScore(b, searchTerm, searchField);
            
            if (aScore === bScore) {
                // If same relevance, sort by name
                const aName = a.getAttribute('data-name') || '';
                const bName = b.getAttribute('data-name') || '';
                return aName.localeCompare(bName);
            }
            
            return bScore - aScore; // Higher score first
        });
        
        // Re-append sorted rows
        visibleRows.forEach(function(row) {
            tbody.appendChild(row);
        });
    }
    
    // Function to calculate relevance score
    function calculateRelevanceScore(row, searchTerm, searchField) {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const role = row.getAttribute('data-role') || '';
        const username = row.getAttribute('data-username') || '';
        
        let score = 0;
        
        switch (searchField) {
            case 'name':
                if (name === searchTerm) score = 100;
                else if (name.startsWith(searchTerm)) score = 80;
                else if (name.includes(searchTerm)) score = 60;
                break;
            case 'email':
                if (email === searchTerm) score = 100;
                else if (email.startsWith(searchTerm)) score = 80;
                else if (email.includes(searchTerm)) score = 60;
                break;
            case 'role':
                if (role === searchTerm) score = 100;
                else if (role.includes(searchTerm)) score = 60;
                break;
            case 'username':
                if (username === searchTerm) score = 100;
                else if (username.startsWith(searchTerm)) score = 80;
                else if (username.includes(searchTerm)) score = 60;
                break;
            case 'all':
            default:
                // Check all fields and give highest score
                if (name === searchTerm) score = 100;
                else if (name.startsWith(searchTerm)) score = 85;
                else if (email === searchTerm || username === searchTerm) score = 90;
                else if (email.startsWith(searchTerm) || username.startsWith(searchTerm)) score = 75;
                else if (name.includes(searchTerm)) score = 70;
                else if (email.includes(searchTerm) || username.includes(searchTerm)) score = 60;
                else if (role.includes(searchTerm)) score = 50;
                break;
        }
        
        return score;
    }
    
    // Function to update search results info
    function updateSearchResultsInfo(searchTerm, visibleCount, totalCount) {
        if (searchResultsInfo) {
            if (searchTerm === '') {
                searchResultsInfo.style.display = 'none';
            } else {
                searchResultsInfo.style.display = 'block';
                const countElement = searchResultsInfo.querySelector('.search-results-count');
                if (countElement) {
                    countElement.textContent = `Found ${visibleCount} teacher(s) matching '${searchTerm}'`;
                }
            }
        }
    }
    
    // Add event listeners
    searchInput.addEventListener('input', function() {
        // Add a small delay to prevent too many searches while typing
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(performLiveSearch, 300);
    });
    
    searchFieldSelect.addEventListener('change', function() {
        // Re-search when field selection changes, but only if there's a search term
        if (searchInput.value.trim() !== '') {
            performLiveSearch();
        } else if (statusFilterSelect && statusFilterSelect.value !== 'all') {
            // If no search term but status filter is active, apply status filter
            filterByStatus(statusFilterSelect.value);
        }
    });
    
    // Filter by status without page refresh - instant filtering on change
    if (statusFilterSelect) {
        statusFilterSelect.addEventListener('change', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const selectedStatus = this.value;
            // Immediately filter without page refresh
            filterByStatus(selectedStatus);
            // Update URL without page reload for bookmarking
            const url = new URL(window.location);
            if (selectedStatus === 'all') {
                url.searchParams.delete('status_filter');
            } else {
                url.searchParams.set('status_filter', selectedStatus);
            }
            window.history.pushState({status_filter: selectedStatus}, '', url);
            return false;
        });
        
        // Prevent form submission when status filter changes
        const searchForm = document.getElementById('search-form');
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                // Only submit if search button was clicked, not if status filter changed
                const submitButton = document.activeElement;
                if (submitButton && submitButton.type === 'submit' && submitButton.classList.contains('search-btn')) {
                    // Allow form submission for search button
                    return true;
                }
                // If status filter was changed, prevent form submission
                if (statusFilterSelect === document.activeElement) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    }
    
    // Function to filter teachers by status
    function filterByStatus(status) {
        const tbody = teachersTable.querySelector('tbody');
        if (!tbody) return;
        
        const tableRows = Array.from(tbody.querySelectorAll('tr'));
        let visibleCount = 0;
        const searchTerm = searchInput.value.trim().toLowerCase();
        const searchField = searchFieldSelect.value;
        
        tableRows.forEach(function(row) {
            const isSuspended = row.getAttribute('data-suspended') === '1';
            let statusMatch = false;
            
            // Check status filter
            if (status === 'all') {
                statusMatch = true;
            } else if (status === 'active' && !isSuspended) {
                statusMatch = true;
            } else if (status === 'suspended' && isSuspended) {
                statusMatch = true;
            }
            
            // If status doesn't match, hide row
            if (!statusMatch) {
                row.style.display = 'none';
                return;
            }
            
            // If status matches, check search filter if there's a search term
            if (searchTerm !== '') {
                let searchMatch = false;
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const role = row.getAttribute('data-role') || '';
                const username = row.getAttribute('data-username') || '';
                
                switch (searchField) {
                    case 'name':
                        searchMatch = name.includes(searchTerm);
                        break;
                    case 'email':
                        searchMatch = email.includes(searchTerm);
                        break;
                    case 'role':
                        searchMatch = role.includes(searchTerm);
                        break;
                    case 'username':
                        searchMatch = username.includes(searchTerm);
                        break;
                    case 'all':
                    default:
                        searchMatch = name.includes(searchTerm) || 
                                     email.includes(searchTerm) || 
                                     role.includes(searchTerm) || 
                                     username.includes(searchTerm);
                        break;
                }
                
                if (searchMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            } else {
                // No search term, just show based on status
                row.style.display = '';
                visibleCount++;
            }
        });
        
        // Update pagination if search is not active
        if (searchTerm === '') {
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                if (status === 'all') {
                    // Restore normal pagination
                    if (typeof initializePagination === 'function') {
                        initializePagination();
                    }
                    paginationContainer.style.display = 'flex';
                } else {
                    // Hide pagination when status filter is active
                    paginationContainer.style.display = 'none';
                }
            }
        }
        
        // Update search results info
        if (searchTerm !== '') {
            updateSearchResultsInfo(searchTerm, visibleCount, tableRows.length);
        } else {
            updateSearchResultsInfo('', 0, tableRows.length);
        }
        
        // Update the "Showing X-Y of Z teachers" text
        updateTeacherCountDisplay(visibleCount);
    }
    
    // Function to update teacher count display
    function updateTeacherCountDisplay(visibleCount) {
        const countElements = document.querySelectorAll('.pagination-info, .search-results-count');
        if (visibleCount > 0) {
            const message = `Showing 1-${visibleCount} of ${visibleCount} teacher${visibleCount !== 1 ? 's' : ''}`;
            // Update pagination info if visible
            const paginationInfo = document.querySelector('.pagination-info');
            if (paginationInfo) {
                paginationInfo.textContent = message;
            }
        }
    }
    
    // Initialize status filter on page load
    if (statusFilterSelect && statusFilterSelect.value !== 'all') {
        // Delay slightly to ensure DOM is fully ready
        setTimeout(function() {
            filterByStatus(statusFilterSelect.value);
        }, 100);
    }
    
    // Add visual feedback for live search
    searchInput.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            this.style.borderColor = '#007bff';
            this.style.boxShadow = '0 0 0 3px rgba(0, 123, 255, 0.1)';
        } else {
            this.style.borderColor = '#d1d5db';
            this.style.boxShadow = 'none';
        }
    });
    
    // Add loading indicator
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        // Show loading state
        this.style.backgroundImage = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23999\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E")';
        this.style.backgroundRepeat = 'no-repeat';
        this.style.backgroundPosition = 'right 10px center';
        this.style.backgroundSize = '16px 16px';
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            this.style.backgroundImage = 'none';
        }, 300);
    });
    
    // Don't run initial search - let pagination handle the initial display
    // Only run search if there's an actual search term in the input
    if (searchInput.value.trim() !== '') {
        performLiveSearch();
    }
    
    console.log('Live search functionality initialized');
});
</script>