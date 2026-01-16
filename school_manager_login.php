<?php
/**
 * School Manager Login Redirect
 * Automatically redirects company managers to the school manager dashboard
 */

require_once('../../config.php');
require_login();

// Get current user
global $USER, $DB, $CFG;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If user is a company manager, redirect to school manager dashboard
if ($is_company_manager) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php');
} else {
    // If not a company manager, redirect to regular dashboard
    redirect($CFG->wwwroot . '/my/');
}
?>
