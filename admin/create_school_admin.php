<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Create School Admin Page
 * 
 * Creates a school admin user and automatically assigns them to the company and department
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot.'/local/iomad/lib/company.php');
require_once($CFG->dirroot.'/local/iomad/lib/user.php');
require_once($CFG->dirroot.'/user/profile/lib.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$companyid = required_param('companyid', PARAM_INT);
$success_message = '';
$error_message = '';

// Validate company exists
if (!$company = $DB->get_record('company', ['id' => $companyid])) {
    throw new moodle_exception('invalidcompany', 'block_iomad_company_admin');
}

$companyobj = new company($companyid);
$companycontext = \core\context\company::instance($companyid);

// Get the parent department for this company (company managers go to parent department)
$parentdepartment = company::get_company_parentnode($companyid);
$departmentid = $parentdepartment->id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // Get all form parameters
    $firstname = required_param('firstname', PARAM_TEXT);
    $lastname = required_param('lastname', PARAM_TEXT);
    $email = required_param('email', PARAM_EMAIL);
    $username = optional_param('username', '', PARAM_USERNAME);
    $newpassword = optional_param('newpassword', '', PARAM_TEXT);
    $sendnewpasswordemails = optional_param('sendnewpasswordemails', 0, PARAM_INT);
    $use_email_as_username = optional_param('use_email_as_username', 0, PARAM_INT);
    
    try {
        // Validate required fields
        if (empty($firstname) || empty($lastname) || empty($email)) {
            throw new moodle_exception('error', '', '', 'First name, Last name, and Email are required');
        }
        
        // Trim fields
        $firstname = trim($firstname);
        $lastname = trim($lastname);
        $email = trim($email);
        $username = trim($username);
        
        // Prepare user data for company_user::create()
        $userdata = new stdClass();
        $userdata->firstname = $firstname;
        $userdata->lastname = $lastname;
        $userdata->email = $email;
        $userdata->companyid = $companyid;
        $userdata->managertype = 1; // Company Manager
        $userdata->educator = 0;
        $userdata->departmentid = $departmentid; // Parent department for company managers
        $userdata->userid = $USER->id;
        $userdata->sendnewpasswordemails = $sendnewpasswordemails;
        $userdata->use_email_as_username = $use_email_as_username;
        
        // Set password if provided
        if (!empty($newpassword)) {
            $userdata->newpassword = $newpassword;
        }
        
        // Set username if provided
        if (!empty($username)) {
            $userdata->username = $username;
        }
        
        // Create the user using IOMAD's company_user::create()
        // Note: company_user::create() will assign user to company, but we need to ensure
        // proper managertype assignment via upsert_company_user()
        if (!$userid = company_user::create($userdata, $companyid)) {
            throw new moodle_exception('error', '', '', 'Error creating user');
        }
        
        // Get the created user
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            throw new moodle_exception('error', '', '', 'User created but not found');
        }
        
        // Set the userid in userdata for profile_save_data
        $userdata->id = $userid;
        
        // Save custom profile fields data if any
        profile_save_data($userdata);
        \core\event\user_updated::create_from_userid($userid)->trigger();
        
        // Assign user to company and department as manager
        // For company managers (managertype = 1), they must be assigned to parent department
        // This ensures proper role assignment and permissions
        $result = company::upsert_company_user($userid, $companyid, $departmentid, 1, false, false, true);
        
        if (!$result) {
            throw new moodle_exception('error', '', '', 'Error assigning user to company');
        }
        
        // Assign role in SYSTEM context so user appears in admin/roles/assign.php?contextid=1
        // Get the system context (contextid=1)
        $systemcontext = context_system::instance();
        
        // Assign role ID 10 (Manager role) in system context
        // This makes the user visible in admin/roles/assign.php?contextid=1&roleid=10
        $systemroleid = 10; // Manager role - change if needed
        
        // Check if role exists
        if ($DB->get_record('role', ['id' => $systemroleid])) {
            // Assign the role in system context
            role_assign($systemroleid, $userid, $systemcontext->id);
        }
        
        // Success - redirect to company management page
        redirect(
            new moodle_url('/theme/remui_kids/admin/companies_list.php', ['companyid' => $companyid]),
            'School admin "' . $firstname . ' ' . $lastname . '" created and assigned successfully!',
            3,
            \core\output\notification::NOTIFY_SUCCESS
        );
        
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/create_school_admin.php', ['companyid' => $companyid]);
$PAGE->set_title('Create School Admin');
$PAGE->set_heading('Create School Admin for ' . $company->name);
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
    }
    
    .admin-sidebar {
        position: fixed !important;
        top: 0; left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    }
    
    .admin-sidebar .sidebar-content { padding: 6rem 0 2rem 0; }
    .sidebar-section { margin-bottom: 2rem; }
    .sidebar-category { font-size: 0.75rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 1rem; padding: 0 2rem; }
    .sidebar-menu { list-style: none; padding: 0; margin: 0; }
    .sidebar-item { margin: 2px 0; }
    .sidebar-link { display: flex; align-items: center; padding: 0.75rem 2rem; color: #495057; text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent; }
    .sidebar-link:hover { background-color: #f8f9fa; color: #2c3e50; text-decoration: none; border-left-color: #667eea; }
    .sidebar-item.active .sidebar-link { background-color: #e3f2fd; color: #1976d2; border-left-color: #1976d2; }
    .sidebar-icon { width: 20px; height: 20px; margin-right: 1rem; font-size: 1rem; color: #6c757d; }
    .sidebar-item.active .sidebar-icon { color: #1976d2; }
    .sidebar-text { font-size: 0.9rem; font-weight: 500; }
    
    .admin-main-content {
        position: fixed;
        top: 0; left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        overflow-y: auto;
        z-index: 99;
        padding-top: 80px;
    }
    
    .form-container { max-width: 1800px; margin: 0 auto; padding: 30px; }
    .page-header { background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%); padding: 2rem; border-radius: 15px; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(225, 245, 254, 0.3); border: 1px solid #b3e5fc; }
    .page-title { margin: 0 0 0.5rem 0; font-size: 2.5rem; font-weight: 700; color: #1976d2; }
    .page-subtitle { margin: 0; font-size: 1.1rem; color: #546e7a; }
    .alert { padding: 1rem 1.5rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; font-weight: 500; }
    .alert-success { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); color: #155724; border-left: 4px solid #28a745; }
    .alert-danger { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); color: #721c24; border-left: 4px solid #dc3545; }
    .form-card { background: white; border-radius: 15px; padding: 2rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); }
    .form-section { margin-bottom: 2rem; }
    .section-title { font-size: 1.4rem; font-weight: 600; color: #2c3e50; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e9ecef; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
    .form-group { margin-bottom: 1.5rem; }
    .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #495057; font-size: 0.95rem; }
    .required::after { content: ' *'; color: #dc3545; }
    .form-input, .form-select, .form-textarea { width: 100%; padding: 0.75rem 1rem; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; transition: all 0.3s ease; font-family: 'Inter', sans-serif; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #81d4fa; box-shadow: 0 0 0 3px rgba(129, 212, 250, 0.1); }
    .form-textarea { resize: vertical; min-height: 80px; }
    .form-help { display: block; margin-top: 0.25rem; font-size: 0.85rem; color: #6c757d; font-style: italic; }
    .form-checkbox { margin-right: 0.5rem; }
    .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #e9ecef; }
    .btn { padding: 0.75rem 2rem; border-radius: 10px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .btn-primary { background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%); color: white; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(129, 212, 250, 0.4); }
    .btn-secondary { background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%); color: #2c3e50; }
    .btn-secondary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(189, 189, 189, 0.4); }
    .info-box { background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; border-left: 4px solid #1976d2; }
    .info-box strong { color: #1976d2; }
</style>

<?php require_once('includes/admin_sidebar.php'); ?>

<div class='admin-main-content'>
<div class='form-container'>

<div class='page-header'>
<h1 class='page-title'>Create School Admin</h1>
<p class='page-subtitle'>Create an administrator for <?php echo htmlspecialchars($company->name); ?></p>
</div>

<?php
if ($success_message) {
    echo "<div class='alert alert-success'><i class='fa fa-check-circle'></i><span>{$success_message}</span></div>";
}
if ($error_message) {
    echo "<div class='alert alert-danger'><i class='fa fa-exclamation-triangle'></i><span>{$error_message}</span></div>";
}
?>

<div class='info-box'>
<strong>Note:</strong> This user will be automatically assigned as a <strong>Company Manager</strong> to <strong><?php echo htmlspecialchars($company->name); ?></strong> and will have full administrative access to manage this school.
</div>

<form method='POST' action='' class='form-card'>
<input type='hidden' name='sesskey' value='<?php echo sesskey(); ?>'>
<input type='hidden' name='companyid' value='<?php echo $companyid; ?>'>

<div class='form-section'>
<h2 class='section-title'><i class='fa fa-user'></i> User Information</h2>
<div class='form-grid'>
<div class='form-group'>
<label class='form-label required' for='firstname'>First Name</label>
<input type='text' id='firstname' name='firstname' class='form-input' required maxlength='100' placeholder='e.g., John'>
</div>
<div class='form-group'>
<label class='form-label required' for='lastname'>Last Name</label>
<input type='text' id='lastname' name='lastname' class='form-input' required maxlength='100' placeholder='e.g., Smith'>
</div>
<div class='form-group'>
<label class='form-label required' for='email'>Email Address</label>
<input type='email' id='email' name='email' class='form-input' required maxlength='100' placeholder='e.g., john.smith@school.com'>
<small class='form-help'>This will be used for login if username is not provided</small>
</div>
<div class='form-group'>
<label class='form-label' for='username'>Username</label>
<input type='text' id='username' name='username' class='form-input' maxlength='100' placeholder='Optional - leave blank to auto-generate'>
<small class='form-help'>Leave blank to auto-generate from email</small>
</div>
</div>
</div>

<div class='form-section'>
<h2 class='section-title'><i class='fa fa-lock'></i> Password & Settings</h2>
<div class='form-grid'>
<div class='form-group'>
<label class='form-label' for='newpassword'>Password</label>
<input type='password' id='newpassword' name='newpassword' class='form-input' placeholder='Leave blank to auto-generate'>
<small class='form-help'>Leave blank to auto-generate a secure password</small>
</div>
<div class='form-group' style='grid-column: 1 / -1;'>
<label style='display: flex; align-items: center; cursor: pointer;'>
<input type='checkbox' name='use_email_as_username' value='1' class='form-checkbox' id='use_email_as_username'>
<span>Use email as username</span>
</label>
</div>
<div class='form-group' style='grid-column: 1 / -1;'>
<label style='display: flex; align-items: center; cursor: pointer;'>
<input type='checkbox' name='sendnewpasswordemails' value='1' class='form-checkbox' id='sendnewpasswordemails' checked>
<span>Send password via email</span>
</label>
<small class='form-help'>If checked, the user will receive their login credentials via email</small>
</div>
</div>
</div>

<div class='form-actions'>
<a href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/schools_management.php' class='btn btn-secondary'>
<i class='fa fa-times'></i> Cancel
</a>
<button type='submit' class='btn btn-primary'>
<i class='fa fa-check'></i> Create School Admin
</button>
</div>

</form>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('email');
    const usernameInput = document.getElementById('username');
    const useEmailAsUsername = document.getElementById('use_email_as_username');
    
    if (emailInput && usernameInput) {
        // Auto-generate username from email when email changes
        emailInput.addEventListener('blur', function() {
            if (!usernameInput.value && !useEmailAsUsername.checked) {
                const email = this.value;
                if (email) {
                    usernameInput.value = email.split('@')[0].toLowerCase().replace(/[^a-z0-9]/g, '');
                }
            }
        });
        
        // Handle use email as username checkbox
        useEmailAsUsername.addEventListener('change', function() {
            if (this.checked) {
                usernameInput.value = '';
                usernameInput.disabled = true;
                usernameInput.placeholder = 'Will use email as username';
            } else {
                usernameInput.disabled = false;
                usernameInput.placeholder = 'Optional - leave blank to auto-generate';
                if (emailInput.value) {
                    usernameInput.value = emailInput.value.split('@')[0].toLowerCase().replace(/[^a-z0-9]/g, '');
                }
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>

