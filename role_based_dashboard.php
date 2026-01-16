<?php
/**
 * Role-Based Dashboard Router
 * This file handles role-based routing to different dashboard types
 * Based on user roles, it redirects to appropriate dashboard
 */

require_once('../../config.php');
require_login();

global $USER, $DB, $CFG;

// Function to check user role
function checkUserRole($user_id, $role_shortname) {
    global $DB;
    
    $role = $DB->get_record('role', ['shortname' => $role_shortname]);
    if (!$role) {
        return false;
    }
    
    $context = context_system::instance();
    return user_has_role_assignment($user_id, $role->id, $context->id);
}

// Function to get user's primary role
function getUserPrimaryRole($user_id) {
    global $DB;
    
    // Define role hierarchy (from highest to lowest priority)
    $role_hierarchy = [
        'companymanager',
        'schoolmanager', 
        'manager',
        'teacher',
        'student'
    ];
    
    foreach ($role_hierarchy as $role_shortname) {
        if (checkUserRole($user_id, $role_shortname)) {
            return $role_shortname;
        }
    }
    
    return 'student'; // Default role
}

// Get user's primary role
$user_role = getUserPrimaryRole($USER->id);

// Route based on role
switch ($user_role) {
    case 'companymanager':
        // Redirect to Company Manager Dashboard
        redirect($CFG->wwwroot . '/theme/remui_kids/company_manager_dashboard.php');
        break;
        
    case 'schoolmanager':
        // Redirect to School Manager Dashboard
        redirect($CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php');
        break;
        
    case 'manager':
        // Check if user is assigned to a company
        if ($DB->get_manager()->table_exists('company_users')) {
            $company_user = $DB->get_record('company_users', ['userid' => $USER->id]);
            if ($company_user && $company_user->managertype == 1) {
                // User is a company manager
                redirect($CFG->wwwroot . '/theme/remui_kids/company_manager_dashboard.php');
            } else {
                // User is a school manager
                redirect($CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php');
            }
        } else {
            // Fallback to school manager dashboard
            redirect($CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php');
        }
        break;
        
    case 'teacher':
        // Redirect to Teacher Dashboard
        redirect($CFG->wwwroot . '/my/');
        break;
        
    case 'student':
    default:
        // Redirect to Student Dashboard or default Moodle dashboard
        redirect($CFG->wwwroot . '/my/');
        break;
}

// If we reach here, something went wrong
redirect($CFG->wwwroot . '/my/', 'Unable to determine your dashboard. Please contact administrator.', null, \core\output\notification::NOTIFY_ERROR);
?>

