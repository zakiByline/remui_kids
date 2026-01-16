<?php
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot.'/local/iomad/lib/company.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Handle AJAX request for shortname validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_shortname') {
    header('Content-Type: application/json');
    $shortname = trim($_POST['shortname'] ?? '');
    
    if (empty($shortname)) {
        echo json_encode(['exists' => false]);
        exit;
    }
    
    global $DB;
    $exists = $DB->record_exists('company', ['shortname' => $shortname]);
    echo json_encode(['exists' => $exists]);
    exit;
}

$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    global $USER, $DB;
    // Get all form parameters
    $name = required_param('name', PARAM_TEXT);
    $shortname = required_param('shortname', PARAM_TEXT);
    $code = optional_param('code', '', PARAM_TEXT);
    $city = required_param('city', PARAM_TEXT);
    $country = required_param('country', PARAM_ALPHA);
    $region = optional_param('region', '', PARAM_TEXT);
    $address = optional_param('address', '', PARAM_TEXT);
    $postcode = optional_param('postcode', '', PARAM_TEXT);
    
    try {
        // Validate required fields
        if (empty($name) || empty($shortname) || empty($city) || empty($country)) {
            throw new moodle_exception('error', '', '', 'Name, Short Name, City, and Country are required');
        }
        
        // Check if shortname already exists
        if ($DB->record_exists('company', ['shortname' => $shortname])) {
            throw new moodle_exception('error', '', '', 'Short Name already exists');
        }
        
        // Trim all text fields
        $name = trim($name);
        $shortname = trim($shortname);
        $code = trim($code);
        $city = trim($city);
        $region = trim($region);
        
        // Create company/school record
        $company = new stdClass();
        $company->name = $name;
        $company->shortname = $shortname;
        $company->code = $code;
        $company->city = $city;
        $company->country = $country;
        $company->region = $region;
        $company->address = $address;
        $company->postcode = $postcode;
        $company->timecreated = time();
        $company->timemodified = time();
        
        // Set default values for user preferences
        $company->maildisplay = 2;
        $company->mailformat = 1;
        $company->maildigest = 0;
        $company->autosubscribe = 1;
        $company->trackforums = 0;
        
        // Add optional fields only if they exist in database
        $dbcolumns = $DB->get_columns('company');
        if (array_key_exists('suspended', $dbcolumns)) { 
            $company->suspended = 0; 
        }
        if (array_key_exists('validto', $dbcolumns)) { 
            // Set to NULL (no expiration) to prevent auto-suspension by IOMAD cron
            // 0 means expired on Jan 1, 1970, which causes immediate suspension
            $company->validto = null; 
        }
        if (array_key_exists('suspendafter', $dbcolumns)) { 
            // Set to NULL (no grace period after expiry)
            $company->suspendafter = null; 
        }
        if (array_key_exists('ecommerce', $dbcolumns)) { 
            $company->ecommerce = 0; 
        }
        if (array_key_exists('parentid', $dbcolumns)) { 
            $company->parentid = 0; 
        }
        if (array_key_exists('supervisornotify', $dbcolumns)) { 
            $company->supervisornotify = 0; 
        }
        if (array_key_exists('profileid', $dbcolumns)) { 
            $company->profileid = 0; 
        }
        if (array_key_exists('category', $dbcolumns)) { 
            $company->category = 0; 
        }
        if (array_key_exists('theme', $dbcolumns)) { 
            $company->theme = 'remui_kids'; 
        }
        
        // Insert the company
        $companyid = $DB->insert_record('company', $company);
        
        if ($companyid) {
            // Set up a profiles field category for this company
            $catdata = new stdclass();
            $catdata->sortorder = $DB->count_records('user_info_category') + 1;
            $catdata->name = $shortname;
            $profileid = $DB->insert_record('user_info_category', $catdata);
            
            // Update company with profile ID
            $DB->set_field('company', 'profileid', $profileid, ['id' => $companyid]);
            
            // Set up default department
            $department = new stdClass();
            $department->name = $name;
            $department->shortname = $shortname;
            $department->company = $companyid;
            $department->parent = 0;
            $departmentid = $DB->insert_record('department', $department);
            
            // Add the current user to company_users as a company manager so they can see/manage the company
            if ($DB->get_manager()->table_exists('company_users')) {
                // Check if user is already in company_users (shouldn't be, but check anyway)
                if (!$DB->get_record('company_users', [
                    'userid' => $USER->id,
                    'companyid' => $companyid,
                    'departmentid' => $departmentid
                ])) {
                    $company_user = new stdClass();
                    $company_user->userid = $USER->id;
                    $company_user->companyid = $companyid;
                    $company_user->departmentid = $departmentid;
                    $company_user->managertype = 1; // Company manager
                    $company_user->suspended = 0;
                    $company_user->educator = 0;
                    $company_user->lastused = time();
                    $DB->insert_record('company_users', $company_user);
                }
            }
            
            // Set up course category for company.
            $coursecat = new stdclass();
            $coursecat->name = $name;
            $coursecat->sortorder = 999;
            $coursecat->id = $DB->insert_record('course_categories', $coursecat);
            $coursecat->context = context_coursecat::instance($coursecat->id);
            $categorycontext = $coursecat->context;
            $categorycontext->mark_dirty();
            $DB->update_record('course_categories', $coursecat);
            fix_course_sortorder();
            $companydetails = $DB->get_record('company', array('id' => $companyid));
            $companydetails->category = $coursecat->id;
            $DB->update_record('company', $companydetails);
            
            // Set theme for the company
            if (!empty($companydetails->theme)) {
                $companyobj = new company($companyid);
                $companyobj->update_theme($companydetails->theme);
            }
            
            // Deal with logo file upload if provided
            $draftcompanylogoid = file_get_submitted_draft_itemid('companylogo');
            if ($draftcompanylogoid) {
                $fs = get_file_storage();
                file_save_draft_area_files($draftcompanylogoid,
                                           $context->id,
                                           'core_admin',
                                           'logo' . $companyid,
                                           0,
                                           ['maxfiles' => 1]);
                
                // Set the plugin config so it can actually be picked up.
                if ($files = $fs->get_area_files($context->id, 'core_admin', 'logo'. $companyid)) {
                    foreach ($files as $file) {
                        if ($file->get_filename() != '.') {
                            set_config('logo' . $companyid, $file->get_filepath() . $file->get_filename(), 'core_admin');
                            break;
                        }
                    }
                } else {
                    set_config('logo' . $companyid, '', 'core_admin');
                }
            }
            
            redirect(
                new moodle_url('/theme/remui_kids/admin/companies_list.php', ['companyid' => $companyid]), 
                'School "' . $name . '" created successfully!', 
                2, 
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}
$countries = get_string_manager()->get_list_of_countries();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/company_create.php');
$PAGE->set_title('Create New School');
$PAGE->set_heading('Create New School');
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
    .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #e9ecef; }
    .btn { padding: 0.75rem 2rem; border-radius: 10px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .btn-primary { background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%); color: white; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(129, 212, 250, 0.4); }
    .btn-secondary { background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%); color: #2c3e50; }
    .btn-secondary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(189, 189, 189, 0.4); }
</style>

<?php require_once('includes/admin_sidebar.php'); ?>

<div class='admin-main-content'>
<div class='form-container'>

<div class='page-header'>
<h1 class='page-title'>Create New School</h1>
<p class='page-subtitle'>Add a new educational institution to the system</p>
</div>

<?php
if ($success_message) {
    echo "<div class='alert alert-success'><i class='fa fa-check-circle'></i><span>{$success_message}</span></div>";
}
if ($error_message) {
    echo "<div class='alert alert-danger'><i class='fa fa-exclamation-triangle'></i><span>{$error_message}</span></div>";
}
?>

<form method='POST' action='' class='form-card'>
<input type='hidden' name='sesskey' value='<?php echo sesskey(); ?>'>

<div class='form-section'>
<h2 class='section-title'><i class='fa fa-info-circle'></i> Basic Information</h2>
<div class='form-grid'>
<div class='form-group'>
<label class='form-label required' for='name'>School Name</label>
<input type='text' id='name' name='name' class='form-input' required maxlength='50'>
<small class='form-help'>Full official name of the school</small>
</div>
<div class='form-group'>
<label class='form-label required' for='shortname'>Short Name</label>
<input type='text' id='shortname' name='shortname' class='form-input' required maxlength='25'>
<small class='form-help'>Unique identifier (lowercase, no spaces)</small>
</div>
<div class='form-group'>
<label class='form-label' for='code'>Company Code</label>
<input type='text' id='code' name='code' class='form-input' maxlength='255' placeholder='Optional code'>
<small class='form-help'>Optional internal reference code</small>
</div>
</div>
</div>

<div class='form-section'>
<h2 class='section-title'><i class='fa fa-map-marker-alt'></i> Location Information</h2>
<div class='form-grid'>
<div class='form-group'>
<label class='form-label required' for='city'>City</label>
<input type='text' id='city' name='city' class='form-input' required maxlength='50' placeholder='e.g., Riyadh'>
</div>
<div class='form-group'>
<label class='form-label required' for='country'>Country</label>
<select id='country' name='country' class='form-select' required>
<option value=''>-- Select Country --</option>
<?php foreach ($countries as $code => $country_name) {
    $selected = ($code === 'SA') ? 'selected' : '';
    echo "<option value='{$code}' {$selected}>{$country_name}</option>";
} ?>
</select>
</div>
<div class='form-group'>
<label class='form-label' for='region'>Region/State</label>
<input type='text' id='region' name='region' class='form-input' maxlength='50' placeholder='e.g., Central Region'>
</div>
<div class='form-group'>
<label class='form-label' for='postcode'>Postal Code</label>
<input type='text' id='postcode' name='postcode' class='form-input' maxlength='20' placeholder='e.g., 12345'>
</div>
<div class='form-group' style='grid-column: 1 / -1;'>
<label class='form-label' for='address'>Street Address</label>
<textarea id='address' name='address' class='form-textarea' rows='3' placeholder='Enter full street address'></textarea>
</div>
</div>
</div>

<div class='form-section'>
<h2 class='section-title'><i class='fa fa-image'></i> School Branding</h2>
<div class='form-grid'>
<div class='form-group' style='grid-column: 1 / -1;'>
<label class='form-label' for='companylogo'>School Logo</label>
<?php
// Prepare filemanager for logo upload
$draftcompanylogoid = file_get_submitted_draft_itemid('companylogo');
file_prepare_draft_area($draftcompanylogoid,
                        $context->id,
                        'core_admin',
                        'logo0', 0,
                        array('subdirs' => 0, 'maxfiles' => 1));

// Create filemanager options
require_once($CFG->dirroot.'/lib/form/filemanager.php');
$fmoptions = new stdClass();
$fmoptions->mainfile = null;
$fmoptions->maxbytes = 0; // Use system default
$fmoptions->maxfiles = 1;
$fmoptions->client_id = uniqid();
$fmoptions->itemid = $draftcompanylogoid;
$fmoptions->subdirs = 0;
$fmoptions->target = 'companylogo';
$fmoptions->accepted_types = array('.jpg', '.jpeg', '.png', '.gif');
$fmoptions->return_types = FILE_INTERNAL;
$fmoptions->context = $context;
$fmoptions->areamaxbytes = 0; // Use system default - no area limit

// Create and render filemanager
$fm = new form_filemanager($fmoptions);
$filesrenderer = $PAGE->get_renderer('core', 'files');
echo $filesrenderer->render($fm);
?>
<input type='hidden' name='companylogo' id='companylogo' value='<?php echo $draftcompanylogoid; ?>'>
<small class='form-help'>Upload school logo (JPG/PNG/GIF format). Recommended size: under 500KB for best performance.</small>
</div>
</div>
</div>

<div class='form-actions'>
<a href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/schools_management.php' class='btn btn-secondary'>
<i class='fa fa-times'></i> Cancel
</a>
<button type='submit' class='btn btn-primary'>
<i class='fa fa-check'></i> Create School
</button>
</div>

</form>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const shortnameInput = document.getElementById('shortname');
    const form = document.querySelector('form');
    let checkTimeout = null;
    let shortnameValid = true;
    
    if (shortnameInput) {
        // Create validation message element
        const validationMsg = document.createElement('div');
        validationMsg.id = 'shortname-validation';
        validationMsg.style.cssText = 'margin-top: 8px; font-size: 14px; font-weight: 500;';
        shortnameInput.parentNode.insertBefore(validationMsg, shortnameInput.nextSibling.nextSibling);
        
        // Check shortname availability as user types
        shortnameInput.addEventListener('input', function() {
            const shortname = this.value.trim();
            validationMsg.innerHTML = '';
            
            // Clear previous timeout
            if (checkTimeout) {
                clearTimeout(checkTimeout);
            }
            
            if (!shortname) {
                shortnameValid = false;
                return;
            }
            
            // Debounce the check - wait 500ms after user stops typing
            checkTimeout = setTimeout(async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'check_shortname');
                    formData.append('shortname', shortname);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.exists) {
                        validationMsg.innerHTML = '<span style="color: #dc3545;"><i class="fa fa-times-circle"></i> This shortname is already taken. Please choose another.</span>';
                        shortnameInput.style.borderColor = '#dc3545';
                        shortnameValid = false;
                    } else {
                        validationMsg.innerHTML = '<span style="color: #28a745;"><i class="fa fa-check-circle"></i> Shortname is available.</span>';
                        shortnameInput.style.borderColor = '#28a745';
                        shortnameValid = true;
                    }
                } catch (error) {
                    console.error('Error checking shortname:', error);
                }
            }, 500);
        });
        
        // Validate on form submit
        form.addEventListener('submit', function(e) {
            if (!shortnameValid) {
                e.preventDefault();
                alert('Please choose a different shortname. The current one is already taken.');
                shortnameInput.focus();
                return false;
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>