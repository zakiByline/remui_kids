<?php
/**
 * Dashboard Selector
 * Allows users to manually select their dashboard if they have multiple roles
 * or provides a landing page for role-based dashboard routing
 */

require_once('../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/dashboard_selector.php');
$PAGE->set_title('Select Dashboard');
$PAGE->set_heading('Select Your Dashboard');

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

// Get user's available roles
$available_roles = [];
$role_descriptions = [
    'companymanager' => [
        'title' => 'Company Manager Dashboard',
        'description' => 'Manage multiple schools, departments, and company-wide operations',
        'icon' => 'fa-building',
        'url' => $CFG->wwwroot . '/theme/remui_kids/company_manager_dashboard.php',
        'color' => '#2ecc71'
    ],
    'schoolmanager' => [
        'title' => 'School Manager Dashboard', 
        'description' => 'Manage teachers, students, and school operations',
        'icon' => 'fa-school',
        'url' => $CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php',
        'color' => '#3498db'
    ],
    'manager' => [
        'title' => 'Manager Dashboard',
        'description' => 'General management access based on your assignments',
        'icon' => 'fa-users-cog',
        'url' => $CFG->wwwroot . '/theme/remui_kids/school_manager_dashboard.php',
        'color' => '#9b59b6'
    ],
    'teacher' => [
        'title' => 'Teacher Dashboard',
        'description' => 'Access your courses, students, and teaching tools',
        'icon' => 'fa-chalkboard-teacher',
        'url' => $CFG->wwwroot . '/my/',
        'color' => '#e74c3c'
    ],
    'student' => [
        'title' => 'Student Dashboard',
        'description' => 'Access your courses and learning materials',
        'icon' => 'fa-user-graduate',
        'url' => $CFG->wwwroot . '/my/',
        'color' => '#f39c12'
    ]
];

// Check which roles the user has
foreach ($role_descriptions as $role => $details) {
    if (checkUserRole($USER->id, $role)) {
        $available_roles[$role] = $details;
    }
}

// If user has only one role, redirect automatically
if (count($available_roles) == 1) {
    $role = array_keys($available_roles)[0];
    redirect($available_roles[$role]['url']);
}

// If no roles found, redirect to default dashboard
if (empty($available_roles)) {
    redirect($CFG->wwwroot . '/my/', 'No dashboard access found. Please contact administrator.', null, \core\output\notification::NOTIFY_ERROR);
}

// Prepare template data
$templatecontext = [
    'user_name' => fullname($USER),
    'available_roles' => $available_roles,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

// Dashboard selector template
echo $OUTPUT->render_from_template('theme_remui_kids/dashboard_selector', $templatecontext);

echo $OUTPUT->footer();
?>

