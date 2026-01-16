<?php
/**
 * Add New Cohort Page - Custom implementation
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Set up the page
$contextid = optional_param('contextid', context_system::instance()->id, PARAM_INT);
$context = context::instance_by_id($contextid);

$PAGE->set_url('/theme/remui_kids/cohorts/add_cohort.php', array('contextid' => $contextid));
$PAGE->set_context($context);
$PAGE->set_title('Add New Cohort');
$PAGE->set_heading('Add New Cohort');
$PAGE->set_pagelayout('admin');

// Check if user has permission to manage cohorts
require_capability('moodle/cohort:manage', $context);
require_login();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $name = required_param('name', PARAM_TEXT);
    $idnumber = optional_param('idnumber', '', PARAM_RAW);
    $description = optional_param('description', '', PARAM_RAW);
    $descriptionformat = optional_param('descriptionformat', FORMAT_HTML, PARAM_INT);
    $visible = optional_param('visible', 1, PARAM_INT);
    
    // Validate required fields
    $errors = array();
    
    if (empty(trim($name))) {
        $errors[] = 'Cohort name is required';
    }
    
    // Check if idnumber already exists
    if (!empty($idnumber)) {
        $existing = $DB->get_record('cohort', array('idnumber' => $idnumber));
        if ($existing) {
            $errors[] = 'Cohort ID number already exists';
        }
    }
    
    if (empty($errors)) {
        // Create cohort object
        $cohort = new stdClass();
        $cohort->contextid = $contextid;
        $cohort->name = $name;
        $cohort->idnumber = $idnumber;
        $cohort->description = $description;
        $cohort->descriptionformat = $descriptionformat;
        $cohort->visible = $visible;
        $cohort->component = '';
        $cohort->timecreated = time();
        $cohort->timemodified = time();
        
        try {
            // Insert cohort
            $cohortid = cohort_add_cohort($cohort);
            
            // Redirect to cohorts list with success message
            redirect(
                new moodle_url('/theme/remui_kids/cohorts/index.php'),
                'Cohort created successfully',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (Exception $e) {
            $errors[] = 'Error creating cohort: ' . $e->getMessage();
        }
    }
}

echo $OUTPUT->header();

// Add custom CSS
echo "<style>
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
    
    .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-item {
        margin: 2px 0;
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
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
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
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
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
    
    /* Form Container */
    .form-container {
        max-width: 800px;
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .form-header {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
    }
    
    .form-title {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 10px 0;
    }
    
    .form-subtitle {
        font-size: 14px;
        color: #7f8c8d;
        margin: 0;
    }
    
    .form-group {
        margin-bottom: 25px;
    }
    
    .form-label {
        display: block;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 8px;
        font-size: 14px;
    }
    
    .form-label.required:after {
        content: ' *';
        color: #e74c3c;
    }
    
    .form-input,
    .form-textarea,
    .form-select {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .form-input:focus,
    .form-textarea:focus,
    .form-select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .form-textarea {
        min-height: 120px;
        resize: vertical;
    }
    
    .form-help {
        font-size: 12px;
        color: #7f8c8d;
        margin-top: 5px;
    }
    
    .form-checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .form-checkbox {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }
    
    .btn {
        padding: 12px 30px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .btn-secondary {
        background: #e0e0e0;
        color: #2c3e50;
    }
    
    .btn-secondary:hover {
        background: #d0d0d0;
        color: #2c3e50;
        text-decoration: none;
    }
    
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .alert-danger {
        background: #fee;
        border-left: 4px solid #e74c3c;
        color: #c0392b;
    }
    
    .alert ul {
        margin: 10px 0 0 20px;
    }
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');

// Main content area
echo "<div class='admin-main-content'>";

// Display errors if any
if (isset($errors) && !empty($errors)) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>⚠️ Please correct the following errors:</strong>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

// Form
echo "<div class='form-container' style='max-width: 800px; margin: 0 auto; padding: 40px 20px;'>";

echo "<div class='form-header'>";
echo "<h1 class='form-title'>Add New Cohort</h1>";
echo "<p class='form-subtitle'>Create a new cohort to organize and manage groups of users</p>";
echo "</div>";

echo "<form method='POST' action=''>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<input type='hidden' name='contextid' value='{$contextid}'>";

// Name field
echo "<div class='form-group'>";
echo "<label class='form-label required'>Cohort Name</label>";
echo "<input type='text' name='name' class='form-input' placeholder='Enter cohort name' required value='" . (isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '') . "'>";
echo "<div class='form-help'>A descriptive name for the cohort</div>";
echo "</div>";

// ID Number field
echo "<div class='form-group'>";
echo "<label class='form-label'>Cohort ID</label>";
echo "<input type='text' name='idnumber' class='form-input' placeholder='Enter unique cohort ID' value='" . (isset($_POST['idnumber']) ? htmlspecialchars($_POST['idnumber']) : '') . "'>";
echo "<div class='form-help'>Optional unique identifier for external systems</div>";
echo "</div>";

// Description field
echo "<div class='form-group'>";
echo "<label class='form-label'>Description</label>";
echo "<textarea name='description' class='form-textarea' placeholder='Enter cohort description'>" . (isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '') . "</textarea>";
echo "<div class='form-help'>Provide additional information about this cohort</div>";
echo "</div>";

// Visible field
echo "<div class='form-group'>";
echo "<div class='form-checkbox-group'>";
echo "<input type='checkbox' name='visible' value='1' class='form-checkbox' id='visible' " . (isset($_POST['visible']) ? 'checked' : 'checked') . ">";
echo "<label for='visible' class='form-label' style='margin-bottom: 0;'>Visible</label>";
echo "</div>";
echo "<div class='form-help'>If checked, this cohort will be visible to users with appropriate permissions</div>";
echo "</div>";

// Form actions
echo "<div class='form-actions'>";
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='fa fa-save'></i> Create Cohort";
echo "</button>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/index.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";
echo "</div>";

echo "</form>";

echo "</div>"; // End form-container

echo "</div>"; // End admin-main-content

echo $OUTPUT->footer();

