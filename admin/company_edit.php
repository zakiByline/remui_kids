<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Edit School Page - Custom Implementation
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// Check if user is logged in
require_login();

// Check if user has admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get company ID - redirect to list if not provided
$companyid = optional_param('id', 0, PARAM_INT);

if ($companyid == 0) {
    // No school ID provided, redirect to schools list
    redirect(
        new moodle_url('/theme/remui_kids/admin/companies_list.php'),
        'Please select a school to edit',
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// Get company record
$company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $name = required_param('name', PARAM_TEXT);
    $shortname = required_param('shortname', PARAM_TEXT);
    $city = optional_param('city', '', PARAM_TEXT);
    $country = optional_param('country', '', PARAM_ALPHA);
    $address = optional_param('address', '', PARAM_TEXT);
    $region = optional_param('region', '', PARAM_TEXT);
    $postcode = optional_param('postcode', '', PARAM_TEXT);
    $suspended = optional_param('suspended', 0, PARAM_INT);
    
    try {
        // Validate required fields
        if (empty($name) || empty($shortname)) {
            throw new moodle_exception('error', '', '', 'Name and Short Name are required');
        }
        
        // Check if shortname already exists (excluding current company)
        $existing = $DB->get_record('company', ['shortname' => $shortname]);
        if ($existing && $existing->id != $companyid) {
            throw new moodle_exception('error', '', '', 'Short Name already exists');
        }
        
        // Update company/school record
        $company->name = $name;
        $company->shortname = $shortname;
        $company->city = $city;
        $company->country = $country;
        $company->timemodified = time();
        
        // Only update suspended field if it exists in the database
        $dbcolumns = $DB->get_columns('company');
        if (array_key_exists('suspended', $dbcolumns)) {
            $company->suspended = $suspended;
        }
        
        $DB->update_record('company', $company);
        
        $success_message = 'School "' . $name . '" updated successfully!';
        
        // Redirect after update
        redirect(
            new moodle_url('/theme/remui_kids/admin/schools_management.php'),
            $success_message,
            2,
            \core\output\notification::NOTIFY_SUCCESS
        );
        
    } catch (Exception $e) {
        $error_message = 'Error updating school: ' . $e->getMessage();
    }
}

// Get list of countries for dropdown
$countries = get_string_manager()->get_list_of_countries();

// Set up page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/company_edit.php', ['id' => $companyid]);
$PAGE->set_title('Edit School: ' . $company->name);
$PAGE->set_heading('Edit School: ' . $company->name);
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Admin Sidebar Navigation - Reuse from create */
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
        padding-top: 80px;
    }
    
    /* Form Container */
    .form-container {
        max-width: 1800px;
        margin: 0 auto;
        padding: 30px;
    }
    
    .page-header {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        padding: 2rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 20px rgba(225, 245, 254, 0.3);
        border: 1px solid #b3e5fc;
    }
    
    .page-title {
        margin: 0 0 0.5rem 0;
        font-size: 2.5rem;
        font-weight: 700;
        color: #1976d2;
        text-shadow: 2px 2px 4px rgba(25, 118, 210, 0.1);
    }
    
    .page-subtitle {
        margin: 0;
        font-size: 1.1rem;
        color: #546e7a;
        opacity: 0.9;
        font-weight: 400;
    }
    
    /* Alert Messages */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
        border-left: 4px solid #28a745;
    }
    
    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
        border-left: 4px solid #dc3545;
    }
    
    .alert-warning {
        background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        color: #856404;
        border-left: 4px solid #ffc107;
    }
    
    /* Form Card */
    .form-card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }
    
    .form-section {
        margin-bottom: 2rem;
    }
    
    .section-title {
        font-size: 1.4rem;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e9ecef;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #495057;
        font-size: 0.95rem;
    }
    
    .required::after {
        content: ' *';
        color: #dc3545;
    }
    
    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        font-size: 1rem;
        font-family: 'Inter', sans-serif;
        transition: all 0.3s ease;
        background: white;
    }
    
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #81d4fa;
        box-shadow: 0 0 0 3px rgba(129, 212, 250, 0.1);
    }
    
    .form-textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .form-help {
        display: block;
        margin-top: 0.25rem;
        font-size: 0.85rem;
        color: #6c757d;
        font-style: italic;
    }
    
    .form-checkbox-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 10px;
        border: 2px solid #e0e0e0;
    }
    
    .form-checkbox {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    
    /* Form Actions */
    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-top: 2rem;
        padding-top: 2rem;
        border-top: 2px solid #e9ecef;
    }
    
    .btn {
        padding: 0.75rem 2rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(129, 212, 250, 0.4);
    }
    
    .btn-secondary {
        background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%);
        color: #2c3e50;
    }
    
    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(189, 189, 189, 0.4);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #ef5350 0%, #f44336 100%);
        color: white;
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(239, 83, 80, 0.4);
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            padding-top: 20px;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<?php
// Admin Sidebar Navigation (same as create page)
include('includes/admin_sidebar.php');

// Main Content
echo "<div class='admin-main-content'>";
echo "<div class='form-container'>";

// Page Header
echo "<div class='page-header'>";
echo "<h1 class='page-title'>Edit School</h1>";
echo "<p class='page-subtitle'>Update information for " . htmlspecialchars($company->name) . "</p>";
echo "</div>";

// Display messages
if ($success_message) {
    echo "<div class='alert alert-success'>";
    echo "<i class='fa fa-check-circle'></i>";
    echo "<span>{$success_message}</span>";
    echo "</div>";
}

if ($error_message) {
    echo "<div class='alert alert-danger'>";
    echo "<i class='fa fa-exclamation-triangle'></i>";
    echo "<span>{$error_message}</span>";
    echo "</div>";
}

// Form
echo "<form method='POST' action='' class='form-card'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<input type='hidden' name='id' value='{$companyid}'>";

// Basic Information Section
echo "<div class='form-section'>";
echo "<h2 class='section-title'><i class='fa fa-info-circle'></i> Basic Information</h2>";
echo "<div class='form-grid'>";

// School Name
echo "<div class='form-group'>";
echo "<label class='form-label required' for='name'>School Name</label>";
echo "<input type='text' id='name' name='name' class='form-input' required value='" . htmlspecialchars($company->name) . "'>";
echo "<small class='form-help'>Full official name of the school</small>";
echo "</div>";

// Short Name
echo "<div class='form-group'>";
echo "<label class='form-label required' for='shortname'>Short Name</label>";
echo "<input type='text' id='shortname' name='shortname' class='form-input' required value='" . htmlspecialchars($company->shortname) . "'>";
echo "<small class='form-help'>Unique identifier (lowercase, no spaces)</small>";
echo "</div>";

echo "</div>"; // End form-grid
echo "</div>"; // End form-section

// Location Information Section
echo "<div class='form-section'>";
echo "<h2 class='section-title'><i class='fa fa-map-marker-alt'></i> Location Information</h2>";
echo "<div class='form-grid'>";

// City
echo "<div class='form-group'>";
echo "<label class='form-label' for='city'>City</label>";
echo "<input type='text' id='city' name='city' class='form-input' value='" . htmlspecialchars($company->city ?? '') . "'>";
echo "</div>";

// Country
echo "<div class='form-group'>";
echo "<label class='form-label' for='country'>Country</label>";
echo "<select id='country' name='country' class='form-select'>";
echo "<option value=''>-- Select Country --</option>";
foreach ($countries as $code => $country_name) {
    $selected = ($code === $company->country) ? 'selected' : '';
    echo "<option value='{$code}' {$selected}>{$country_name}</option>";
}
echo "</select>";
echo "</div>";

// Address (full width)
echo "<div class='form-group' style='grid-column: 1 / -1;'>";
echo "<label class='form-label' for='address'>Street Address</label>";
echo "<textarea id='address' name='address' class='form-textarea'>" . htmlspecialchars($company->address ?? '') . "</textarea>";
echo "</div>";

// Region
echo "<div class='form-group'>";
echo "<label class='form-label' for='region'>Region/State</label>";
echo "<input type='text' id='region' name='region' class='form-input' value='" . htmlspecialchars($company->region ?? '') . "'>";
echo "</div>";

// Postal Code
echo "<div class='form-group'>";
echo "<label class='form-label' for='postcode'>Postal Code</label>";
echo "<input type='text' id='postcode' name='postcode' class='form-input' value='" . htmlspecialchars($company->postcode ?? '') . "'>";
echo "</div>";

echo "</div>"; // End form-grid
echo "</div>"; // End form-section

// Status Section - only show if suspended field exists
$dbcolumns_check = $DB->get_columns('company');
if (array_key_exists('suspended', $dbcolumns_check)) {
    echo "<div class='form-section'>";
    echo "<h2 class='section-title'><i class='fa fa-toggle-on'></i> Status</h2>";
    echo "<div class='form-checkbox-group'>";
    $suspended_checked = (isset($company->suspended) && $company->suspended) ? ' checked' : '';
    echo "<input type='checkbox' id='suspended' name='suspended' value='1' class='form-checkbox'{$suspended_checked}>";
    echo "<label for='suspended' class='form-label' style='margin: 0;'>Suspend this school (prevents access)</label>";
    echo "</div>";
    echo "</div>";
}

// Form Actions
echo "<div class='form-actions'>";
echo "<div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/schools_management.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";
echo "</div>";
echo "<div style='display: flex; gap: 1rem;'>";
echo "<button type='button' onclick='deleteSchool()' class='btn btn-danger'>";
echo "<i class='fa fa-trash'></i> Delete School";
echo "</button>";
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='fa fa-save'></i> Update School";
echo "</button>";
echo "</div>";
echo "</div>";

echo "</form>";

echo "</div>"; // End form-container
echo "</div>"; // End admin-main-content
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const shortname = document.getElementById('shortname').value.trim();
            
            if (!name || !shortname) {
                e.preventDefault();
                alert('Please fill in all required fields (marked with *)');
                return false;
            }
            
            // Validate shortname format
            if (!/^[a-z0-9]+$/.test(shortname)) {
                e.preventDefault();
                alert('Short Name must contain only lowercase letters and numbers (no spaces or special characters)');
                return false;
            }
        });
    }
});

function deleteSchool() {
    if (confirm('Are you sure you want to delete this school?\n\nThis action cannot be undone and will remove:\n- All user associations\n- All course assignments\n- All departments\n\nClick OK to confirm deletion.')) {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/company_delete.php?id=<?php echo $companyid; ?>&sesskey=<?php echo sesskey(); ?>';
    }
}
</script>

<?php
echo $OUTPUT->footer();
?>

