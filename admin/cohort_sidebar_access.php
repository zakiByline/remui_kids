<?php
/**
 * Cohort Sidebar Access Management Page
 *
 * Admin page to manage student sidebar access (Scratch Editor and Code Editor)
 * for each cohort.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/../lib/cohort_sidebar_helper.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

require_login();
require_capability('moodle/cohort:manage', context_system::instance());

$PAGE->set_url('/theme/remui_kids/admin/cohort_sidebar_access.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Cohort Sidebar Access Management');
$PAGE->set_heading('Cohort Sidebar Access Management');
$PAGE->set_pagelayout('admin');

// Handle bulk update first (before individual form submission)
if (isset($_POST['bulk_update']) && confirm_sesskey()) {
    $cohorts = optional_param_array('cohorts', [], PARAM_INT);
    $scratch_editor = optional_param('bulk_scratch_editor', -1, PARAM_INT);
    $code_editor = optional_param('bulk_code_editor', -1, PARAM_INT);
    
    if (empty($cohorts)) {
        \core\notification::warning('Please select at least one cohort to update.');
        redirect($PAGE->url);
    }
    
    $updated = 0;
    foreach ($cohorts as $cohortid) {
        $current = theme_remui_kids_get_cohort_sidebar_settings($cohortid);
        $new_scratch = ($scratch_editor >= 0) ? $scratch_editor : $current->scratch_editor_enabled;
        $new_code = ($code_editor >= 0) ? $code_editor : $current->code_editor_enabled;
        
        if (theme_remui_kids_save_cohort_sidebar_settings($cohortid, $new_scratch, $new_code)) {
            $updated++;
        }
    }
    
    if ($updated > 0) {
        \core\notification::success("Updated sidebar access settings for {$updated} cohort(s).");
    }
    
    redirect($PAGE->url);
}

// Handle individual form submission (only if not bulk update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['bulk_update']) && confirm_sesskey()) {
    $cohortid = required_param('cohortid', PARAM_INT);
    $scratch_editor = optional_param('scratch_editor', 0, PARAM_INT);
    $code_editor = optional_param('code_editor', 0, PARAM_INT);
    
    if (theme_remui_kids_save_cohort_sidebar_settings($cohortid, $scratch_editor, $code_editor)) {
        \core\notification::success('Sidebar access settings updated successfully for cohort.');
    } else {
        \core\notification::error('Failed to update sidebar access settings.');
    }
    
    redirect($PAGE->url);
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
    
    /* Main content area with sidebar */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #f5f7fa;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px;
    }
    
    /* Page Container */
    .page-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 30px 20px;
    }
    
    .page-header {
        background: white;
        border-radius: 12px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .page-title {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 10px 0;
    }
    
    .page-subtitle {
        font-size: 14px;
        color: #7f8c8d;
        margin: 0;
    }
    
    /* Table Container */
    .table-container {
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        overflow-x: auto;
    }
    
    .cohort-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .cohort-table thead {
        background: #f8f9fa;
    }
    
    .cohort-table th {
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
        font-size: 14px;
    }
    
    .cohort-table td {
        padding: 15px;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }
    
    .cohort-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .cohort-name {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .cohort-idnumber {
        font-size: 12px;
        color: #6c757d;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }
    
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 24px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: \"\";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
        background-color: #28a745;
    }
    
    input:checked + .toggle-slider:before {
        transform: translateX(26px);
    }
    
    /* Action Buttons */
    .btn {
        padding: 10px 20px;
        border-radius: 6px;
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
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    /* Bulk Actions */
    .bulk-actions {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .bulk-actions-title {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 15px;
    }
    
    .bulk-controls {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .bulk-control-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .bulk-control-group label {
        font-size: 14px;
        color: #495057;
        font-weight: 500;
    }
    
    .form-select {
        padding: 8px 12px;
        border: 2px solid #e0e0e0;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .status-enabled {
        background: #d4edda;
        color: #155724;
    }
    
    .status-disabled {
        background: #f8d7da;
        color: #721c24;
    }
</style>";

// Include admin sidebar
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area
echo "<div class='admin-main-content'>";
echo "<div class='page-container'>";

// Page Header
echo "<div class='page-header'>";
echo "<h1 class='page-title'>Cohort Sidebar Access Management</h1>";
echo "<p class='page-subtitle'>Enable or disable Scratch Editor and Code Editor access for students in each cohort</p>";
echo "</div>";

// Bulk Actions
echo "<div class='bulk-actions'>";
echo "<form method='POST' id='bulk-form'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<h3 class='bulk-actions-title'>Bulk Actions</h3>";
echo "<div class='bulk-controls'>";
echo "<div class='bulk-control-group'>";
echo "<label>Scratch Editor:</label>";
echo "<select name='bulk_scratch_editor' class='form-select'>";
echo "<option value='-1'>No Change</option>";
echo "<option value='1'>Enable All</option>";
echo "<option value='0'>Disable All</option>";
echo "</select>";
echo "</div>";
echo "<div class='bulk-control-group'>";
echo "<label>Code Editor:</label>";
echo "<select name='bulk_code_editor' class='form-select'>";
echo "<option value='-1'>No Change</option>";
echo "<option value='1'>Enable All</option>";
echo "<option value='0'>Disable All</option>";
echo "</select>";
echo "</div>";
echo "<button type='submit' name='bulk_update' class='btn btn-primary btn-sm'>Apply to Selected</button>";
echo "</div>";
echo "</form>";
echo "</div>";

// Get all cohorts with settings
$cohorts = theme_remui_kids_get_all_cohorts_with_sidebar_settings();

// Table Container
echo "<div class='table-container'>";
echo "<table class='cohort-table'>";
echo "<thead>";
echo "<tr>";
echo "<th><input type='checkbox' id='select-all'></th>";
echo "<th>Cohort Name</th>";
echo "<th>ID Number</th>";
echo "<th>Scratch Editor</th>";
echo "<th>Code Editor</th>";
echo "<th>Actions</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

if (empty($cohorts)) {
    echo "<tr><td colspan='6' style='text-align: center; padding: 40px; color: #6c757d;'>No cohorts found. Please create cohorts first.</td></tr>";
} else {
    foreach ($cohorts as $cohort) {
        echo "<tr>";
        echo "<td><input type='checkbox' name='cohorts[]' value='{$cohort->id}' class='cohort-checkbox'></td>";
        echo "<td><span class='cohort-name'>{$cohort->name}</span></td>";
        echo "<td><span class='cohort-idnumber'>" . ($cohort->idnumber ?: '-') . "</span></td>";
        echo "<td>";
        echo "<span class='status-badge " . ($cohort->scratch_editor_enabled ? 'status-enabled' : 'status-disabled') . "'>";
        echo $cohort->scratch_editor_enabled ? 'Enabled' : 'Disabled';
        echo "</span>";
        echo "</td>";
        echo "<td>";
        echo "<span class='status-badge " . ($cohort->code_editor_enabled ? 'status-enabled' : 'status-disabled') . "'>";
        echo $cohort->code_editor_enabled ? 'Enabled' : 'Disabled';
        echo "</span>";
        echo "</td>";
        echo "<td>";
        echo "<form method='POST' style='display: inline;' onsubmit='return confirm(\"Are you sure you want to update settings for this cohort?\");'>";
        echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
        echo "<input type='hidden' name='cohortid' value='{$cohort->id}'>";
        echo "<input type='hidden' name='scratch_editor' value='0' id='scratch_editor_{$cohort->id}'>";
        echo "<input type='hidden' name='code_editor' value='0' id='code_editor_{$cohort->id}'>";
        echo "<label class='toggle-switch' style='margin-right: 10px;'>";
        echo "<input type='checkbox' " . ($cohort->scratch_editor_enabled ? 'checked' : '') . " onchange='document.getElementById(\"scratch_editor_{$cohort->id}\").value = this.checked ? \"1\" : \"0\"; this.form.submit();'>";
        echo "<span class='toggle-slider'></span>";
        echo "</label>";
        echo "<label class='toggle-switch'>";
        echo "<input type='checkbox' " . ($cohort->code_editor_enabled ? 'checked' : '') . " onchange='document.getElementById(\"code_editor_{$cohort->id}\").value = this.checked ? \"1\" : \"0\"; this.form.submit();'>";
        echo "<span class='toggle-slider'></span>";
        echo "</label>";
        echo "<script>document.getElementById('scratch_editor_{$cohort->id}').value = " . ($cohort->scratch_editor_enabled ? '1' : '0') . "; document.getElementById('code_editor_{$cohort->id}').value = " . ($cohort->code_editor_enabled ? '1' : '0') . ";</script>";
        echo "</form>";
        echo "</td>";
        echo "</tr>";
    }
}

echo "</tbody>";
echo "</table>";
echo "</div>";

echo "</div>"; // page-container
echo "</div>"; // admin-main-content

// JavaScript for select all checkbox and bulk form submission
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.cohort-checkbox');
    const bulkForm = document.getElementById('bulk-form');
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
    
    // Update select all when individual checkboxes change
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            const noneChecked = Array.from(checkboxes).every(c => !c.checked);
            if (selectAll) {
                selectAll.checked = allChecked;
                selectAll.indeterminate = !allChecked && !noneChecked;
            }
        });
    });
    
    // Handle bulk form submission - collect selected checkboxes
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const selectedCheckboxes = Array.from(checkboxes).filter(cb => cb.checked);
            
            if (selectedCheckboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one cohort to update.');
                return false;
            }
            
            // Add selected cohort IDs as hidden inputs to the form
            selectedCheckboxes.forEach(cb => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'cohorts[]';
                hiddenInput.value = cb.value;
                bulkForm.appendChild(hiddenInput);
            });
        });
    }
});
</script>";

echo $OUTPUT->footer();

