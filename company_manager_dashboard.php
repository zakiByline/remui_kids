<?php
/**
 * Company Manager Dashboard
 * Dedicated dashboard for company managers with sidebar navigation
 * This is separate from school manager dashboard for role-based access
 */

require_once('../../config.php');
require_login();

// Get current user and check if they are a company manager
global $USER, $DB, $OUTPUT, $CFG;

// Check if user has company manager role (different from school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect to appropriate page
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'You do not have permission to access the Company Manager Dashboard.', null, \core\output\notification::NOTIFY_ERROR);
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/company_manager_dashboard.php');
$PAGE->set_title('Company Manager Dashboard');
$PAGE->set_heading('Company Manager Dashboard');

// Get company information for the current user
$company_info = null;
if ($is_company_manager) {
    // Check if company tables exist
    if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
        // Get company information for the current user
        $company_info = $DB->get_record_sql(
            "SELECT c.*, u.firstname, u.lastname, u.email 
             FROM {company} c 
             JOIN {company_users} cu ON c.id = cu.companyid 
             JOIN {user} u ON cu.userid = u.id 
             WHERE cu.userid = ? AND cu.managertype = 1",
            [$USER->id]
        );
    }
    
    // Get company logo if exists
    if ($company_info) {
        // Check if company_logo table exists
        if ($DB->get_manager()->table_exists('company_logo')) {
            $company_logo = $DB->get_record('company_logo', ['companyid' => $company_info->id]);
            if ($company_logo) {
                $company_info->logo_filename = $company_logo->filename;
                $company_info->logo_filepath = $CFG->dataroot . '/company/' . $company_info->id . '/' . $company_logo->filename;
            }
        }
    }
}

// Handle AJAX requests for dashboard stats
if (isset($_GET['action']) && $_GET['action'] === 'get_dashboard_stats') {
    header('Content-Type: application/json');
    
    try {
        $company_id = $company_info ? $company_info->id : null;
        
        // Get statistics for the company
        $total_teachers = 0;
        $total_students = 0;
        $total_courses = 0;
        $active_enrollments = 0;
        $total_schools = 0;
        $total_departments = 0;
        
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            // Count total teachers in this company
            $teacher_roles = ['teacher', 'editingteacher', 'coursecreator', 'manager'];
            $total_teachers = 0;
            
            foreach ($teacher_roles as $role_shortname) {
                $count = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     JOIN {company_users} cu ON u.id = cu.userid 
                     JOIN {role_assignments} ra ON u.id = ra.userid 
                     JOIN {role} r ON ra.roleid = r.id 
                     WHERE cu.companyid = ? AND r.shortname = ? AND u.deleted = 0",
                    [$company_id, $role_shortname]
                );
                $total_teachers += $count;
            }
            
            // Count students in this company
            $total_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 WHERE cu.companyid = ? AND r.shortname = 'student' AND u.deleted = 0",
                [$company_id]
            );
            
            // Count courses assigned to this company
            if ($DB->get_manager()->table_exists('company_course')) {
                $total_courses = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cc.courseid) 
                     FROM {company_course} cc 
                     WHERE cc.companyid = ?",
                    [$company_id]
                );
            }
            
            // Count active enrollments
            if ($DB->get_manager()->table_exists('company_course')) {
                $active_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.id) 
                     FROM {user_enrolments} ue 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     JOIN {course} c ON e.courseid = c.id 
                     JOIN {company_course} cc ON c.id = cc.courseid 
                     JOIN {user} u ON ue.userid = u.id
                     JOIN {company_users} cu ON u.id = cu.userid
                     WHERE cc.companyid = ? AND cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0",
                    [$company_id, $company_id]
                );
            }
            
            // Count schools/departments in this company
            if ($DB->get_manager()->table_exists('company')) {
                $total_schools = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT id) 
                     FROM {company} 
                     WHERE parentid = ? OR id = ?",
                    [$company_id, $company_id]
                );
            }
            
            // Count departments (assuming department table exists)
            if ($DB->get_manager()->table_exists('company_department')) {
                $total_departments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT id) 
                     FROM {company_department} 
                     WHERE companyid = ?",
                    [$company_id]
                );
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'total_teachers' => $total_teachers,
            'total_students' => $total_students,
            'total_courses' => $total_courses,
            'active_enrollments' => $active_enrollments,
            'total_schools' => $total_schools,
            'total_departments' => $total_departments,
            'company_name' => $company_info ? $company_info->name : 'Company Dashboard',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get initial dashboard statistics
$total_teachers = 0;
$total_students = 0;
$total_courses = 0;
$active_enrollments = 0;
$total_schools = 0;
$total_departments = 0;

if ($company_info && $DB->get_manager()->table_exists('company_users')) {
    $company_id = $company_info->id;
    
    // Count total teachers in this company
    $teacher_roles = ['teacher', 'editingteacher', 'coursecreator', 'manager'];
    $total_teachers = 0;
    
    foreach ($teacher_roles as $role_shortname) {
        $count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u 
             JOIN {company_users} cu ON u.id = cu.userid 
             JOIN {role_assignments} ra ON u.id = ra.userid 
             JOIN {role} r ON ra.roleid = r.id 
             WHERE cu.companyid = ? AND r.shortname = ? AND u.deleted = 0",
            [$company_id, $role_shortname]
        );
        $total_teachers += $count;
    }
    
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id) 
         FROM {user} u 
         JOIN {company_users} cu ON u.id = cu.userid 
         JOIN {role_assignments} ra ON u.id = ra.userid 
         JOIN {role} r ON ra.roleid = r.id 
         WHERE cu.companyid = ? AND r.shortname = 'student' AND u.deleted = 0",
        [$company_id]
    );
    
    if ($DB->get_manager()->table_exists('company_course')) {
        $total_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.courseid) 
             FROM {company_course} cc 
             WHERE cc.companyid = ?",
            [$company_id]
        );
    }
    
    if ($DB->get_manager()->table_exists('company_course')) {
        $active_enrollments = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.id) 
             FROM {user_enrolments} ue 
             JOIN {enrol} e ON ue.enrolid = e.id 
             JOIN {course} c ON e.courseid = c.id 
             JOIN {company_course} cc ON c.id = cc.courseid 
             JOIN {user} u ON ue.userid = u.id
             JOIN {company_users} cu ON u.id = cu.userid
             WHERE cc.companyid = ? AND cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0",
            [$company_id, $company_id]
        );
    }
    
    // Count schools/departments
    if ($DB->get_manager()->table_exists('company')) {
        $total_schools = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT id) 
             FROM {company} 
             WHERE parentid = ? OR id = ?",
            [$company_id, $company_id]
        );
    }
    
    if ($DB->get_manager()->table_exists('company_department')) {
        $total_departments = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT id) 
             FROM {company_department} 
             WHERE companyid = ?",
            [$company_id]
        );
    }
}

// Prepare template data
$templatecontext = [
    'company_name' => $company_info ? $company_info->name : 'Company Dashboard',
    'company_info' => $company_info,
    'company_logo_url' => $company_info && isset($company_info->logo_filename) 
        ? $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id 
        : null,
    'has_logo' => $company_info && isset($company_info->logo_filename),
    'total_teachers' => $total_teachers,
    'total_students' => $total_students,
    'total_courses' => $total_courses,
    'active_enrollments' => $active_enrollments,
    'total_schools' => $total_schools,
    'total_departments' => $total_departments,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'user_info' => [
        'fullname' => fullname($USER),
        'email' => $USER->email
    ]
];

echo $OUTPUT->header();

// Company Manager Sidebar Navigation
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'Company Dashboard',
    'company_logo_url' => $company_info && isset($company_info->logo_filename) 
        ? $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id 
        : null,
    'has_logo' => $company_info && isset($company_info->logo_filename),
    'user_info' => [
        'fullname' => fullname($USER),
        'email' => $USER->email,
        'id' => $USER->id
    ],
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'dashboard_active' => true,
    'sesskey' => sesskey()
];

echo $OUTPUT->render_from_template('theme_remui_kids/company_manager_sidebar', $sidebarcontext);

// Main content area
echo "<div class='company-manager-main-content'>";

// Dashboard content will be rendered here
echo $OUTPUT->render_from_template('theme_remui_kids/company_manager_dashboard', $templatecontext);

echo "</div>"; // End company-manager-main-content

// Add CSS for company manager main content
echo "<style>
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
    
    /* Main content area */
    .company-manager-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #f8f9fa;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px;
        margin-top: 0;
        padding-left: 0;
        padding-right: 0;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .company-manager-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }
</style>";

echo $OUTPUT->footer();
?>

