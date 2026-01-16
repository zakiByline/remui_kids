<?php
/**
 * Delete Cohort Page - Custom implementation
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Get cohort ID
$cohortid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Get cohort
$cohort = $DB->get_record('cohort', array('id' => $cohortid), '*', MUST_EXIST);

$context = context::instance_by_id($cohort->contextid);

$PAGE->set_url('/theme/remui_kids/cohorts/delete_cohort.php', array('id' => $cohortid));
$PAGE->set_context($context);
$PAGE->set_title('Delete Cohort');
$PAGE->set_heading('Delete Cohort');
$PAGE->set_pagelayout('admin');

// Check if user has permission to manage cohorts
require_capability('moodle/cohort:manage', $context);
require_login();
require_sesskey();

// Check if cohort is managed by a plugin (can't be deleted)
if (!empty($cohort->component)) {
    print_error('Cohort is managed by ' . $cohort->component . ' and cannot be deleted here');
}

// Handle confirmation
if ($confirm && confirm_sesskey()) {
    try {
        cohort_delete_cohort($cohort);
        
        redirect(
            new moodle_url('/theme/remui_kids/cohorts/index.php'),
            'Cohort deleted successfully',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (Exception $e) {
        $error = 'Error deleting cohort: ' . $e->getMessage();
    }
}

// Get member count
$member_count = $DB->count_records('cohort_members', array('cohortid' => $cohortid));

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
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    /* Delete Container */
    .delete-container {
        max-width: 600px;
        background: white;
        border-radius: 16px;
        padding: 40px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        text-align: center;
    }
    
    .delete-icon {
        font-size: 80px;
        color: #e74c3c;
        margin-bottom: 20px;
    }
    
    .delete-title {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 15px 0;
    }
    
    .delete-message {
        font-size: 16px;
        color: #7f8c8d;
        margin-bottom: 30px;
        line-height: 1.6;
    }
    
    .cohort-info {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 30px;
        text-align: left;
    }
    
    .cohort-info-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .cohort-info-item:last-child {
        border-bottom: none;
    }
    
    .info-label {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .info-value {
        color: #7f8c8d;
    }
    
    .warning-box {
        background: #fff3cd;
        border: 2px solid #ffc107;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 30px;
        text-align: left;
    }
    
    .warning-box strong {
        color: #856404;
        display: block;
        margin-bottom: 5px;
    }
    
    .warning-box ul {
        margin: 10px 0 0 20px;
        color: #856404;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: center;
    }
    
    .btn {
        padding: 14px 35px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-danger {
        background: #e74c3c;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c0392b;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .btn-secondary {
        background: #95a5a6;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #7f8c8d;
        color: white;
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
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');

// Main content area
echo "<div class='admin-main-content'>";

// Display errors if any
if (isset($error)) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>⚠️ Error:</strong> " . htmlspecialchars($error);
    echo "</div>";
}

echo "<div class='delete-container' style='max-width: 800px; margin: 0 auto; padding: 40px 20px;'>";

echo "<div class='delete-icon'>";
echo "<i class='fa fa-exclamation-triangle'></i>";
echo "</div>";

echo "<h1 class='delete-title'>Delete Cohort?</h1>";
echo "<p class='delete-message'>Are you sure you want to permanently delete this cohort? This action cannot be undone.</p>";

// Cohort information
echo "<div class='cohort-info'>";
echo "<div class='cohort-info-item'>";
echo "<span class='info-label'>Cohort Name:</span>";
echo "<span class='info-value'>" . htmlspecialchars($cohort->name) . "</span>";
echo "</div>";
echo "<div class='cohort-info-item'>";
echo "<span class='info-label'>Cohort ID:</span>";
echo "<span class='info-value'>" . htmlspecialchars($cohort->idnumber) . "</span>";
echo "</div>";
echo "<div class='cohort-info-item'>";
echo "<span class='info-label'>Members:</span>";
echo "<span class='info-value'>{$member_count} user(s)</span>";
echo "</div>";
echo "</div>";

// Warning box
echo "<div class='warning-box'>";
echo "<strong>⚠️ Warning:</strong>";
echo "<ul>";
echo "<li>All members will be removed from this cohort</li>";
echo "<li>Any course or activity enrollments linked to this cohort may be affected</li>";
echo "<li>This action is permanent and cannot be reversed</li>";
echo "</ul>";
echo "</div>";

// Action buttons
echo "<div class='form-actions'>";

echo "<form method='POST' action='' style='display: inline;'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<input type='hidden' name='id' value='{$cohortid}'>";
echo "<input type='hidden' name='confirm' value='1'>";
echo "<button type='submit' class='btn btn-danger'>";
echo "<i class='fa fa-trash'></i> Yes, Delete Cohort";
echo "</button>";
echo "</form>";

echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/index.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";

echo "</div>";

echo "</div>"; // End delete-container

echo "</div>"; // End admin-main-content

echo $OUTPUT->footer();

