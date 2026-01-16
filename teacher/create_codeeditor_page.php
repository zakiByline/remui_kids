<?php
/**
 * Code Editor Creation Page
 * Dedicated page for creating new code editor activities with course structure selection
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access code editor creation page');
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/create_codeeditor_page.php');
$PAGE->set_title('Create Code Editor');
$PAGE->set_heading('Create New Code Editor Activity');
$PAGE->add_body_class('codeeditor-creation-page');

// Get teacher's courses
$teacher_courses = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate
    FROM {course} c
    JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
    JOIN {role_assignments} ra ON ra.contextid = ctx.id
    JOIN {role} r ON r.id = ra.roleid
    WHERE ra.userid = ? AND r.shortname IN ('teacher', 'editingteacher', 'manager')
    AND c.id > 1
    ORDER BY c.fullname
", [$USER->id]);

echo $OUTPUT->header();

// Initialize TinyMCE editor options for rich text fields
$editoroptions = [
    'maxfiles' => 10,
    'maxbytes' => $CFG->maxbytes,
    'trusttext' => false,
    'subdirs' => false,
    'context' => $context,
    'noclean' => false
];

// Check if we're in edit mode
$edit_mode = isset($GLOBALS['edit_mode']) && $GLOBALS['edit_mode'];
$edit_data = $edit_mode ? $GLOBALS['edit_mode_data'] : null;
?>

<?php if ($edit_mode): ?>
<script>
// Pre-load assignment data for editing
window.assignmentData = <?php echo json_encode($edit_data); ?>;
window.isEditMode = true;
</script>
<?php endif; ?>

<style>
/* Hide Moodle's default main content area */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Teacher Dashboard Styles */
.teacher-css-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

/* Code Editor Creation Page Styles */
.codeeditor-creation-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

.codeeditor-creation-container {
    background: #fff;
    margin: 0 auto;
    padding: 30px 40px;
    max-width: 100%;
    width: 100%;
}

.page-header {
    background: linear-gradient(135deg, #e8f5f0 0%, #e3f2fd 50%, #e8f8f5 100%);
    padding: 35px 40px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.8);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #20c997, #28a745, #20c997);
    background-size: 200% 100%;
    animation: gradientShift 3s ease infinite;
}

@keyframes gradientShift {
    0%, 100% { background-position: 0% 0%; }
    50% { background-position: 100% 0%; }
}

.page-header-content {
    display: flex;
    align-items: flex-start;
    gap: 24px;
}

.page-header-text {
    flex: 1;
    text-align: left;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
}

.page-title-header {
    font-size: 32px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 8px 0;
    text-align: left;
    width: 100%;
}

.page-subtitle {
    color: #6c757d;
    font-size: 15px;
    margin: 0;
    line-height: 1.5;
    text-align: left;
    width: 100%;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #495057;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.back-button:hover {
    background: #f8f9fa;
    color: #2c3e50;
    text-decoration: none;
    transform: translateX(-3px);
    border-color: #28a745;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.15);
}

.back-button i {
    font-size: 16px;
    transition: transform 0.3s ease;
}

.back-button:hover i {
    transform: translateX(-2px);
}

.creation-form {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    overflow: hidden;
}

.form-section {
    padding: 30px;
    border-bottom: 1px solid #e9ecef;
}

.form-section:last-child {
    border-bottom: none;
}

.form-section-title {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 20px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-label {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
}

.form-label.required::after {
    content: ' *';
    color: #dc3545;
}

.form-input, .form-select, .form-textarea {
    padding: 12px 16px;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    width: 100%;
    box-sizing: border-box;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 120px;
}

/* Course Structure Tree */
.course-structure-section {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.course-structure-title {
    font-size: 16px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 15px;
}

.course-structure-description {
    color: #6c757d;
    font-size: 14px;
    margin-bottom: 20px;
}

.course-structure-tree {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    background: white;
    max-height: 400px;
    overflow-y: auto;
}

.course-tree {
    list-style: none;
    padding: 0;
    margin: 0;
}

.course-tree li {
    margin: 0;
    padding: 0;
}

.tree-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s ease;
    margin-bottom: 2px;
    border: 1px solid transparent;
}

.tree-item:hover {
    background: #e9ecef;
    border-color: #dee2e6;
}

.tree-item.selected {
    background: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.tree-item.disabled {
    color: #6c757d;
    cursor: not-allowed;
    background: #f8f9fa;
}

.tree-icon {
    margin-right: 10px;
    font-size: 16px;
    width: 20px;
    text-align: center;
}

.tree-label {
    font-size: 14px;
    font-weight: 500;
    flex: 1;
}

.tree-toggle {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: #6c757d;
    font-size: 12px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.tree-toggle:hover {
    background: #e9ecef;
}

.tree-children {
    margin-left: 24px;
    border-left: 2px solid #dee2e6;
    padding-left: 16px;
}

.selection-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 12px 16px;
    margin-top: 15px;
    font-size: 14px;
    color: #0c5460;
}

.selection-info.hidden {
    display: none;
}

/* Form Actions */
.form-actions {
    padding: 30px;
    background: #f8f9fa;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 16px;
    border-top: 1px solid #e9ecef;
}

/* Code Editor Creation Button Styles */
.create-codeeditor-btn-cancel,
.create-codeeditor-btn-submit {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    justify-content: center;
}

.create-codeeditor-btn-cancel {
    background: #ffffff;
    color: #6c757d;
    border: 1px solid #dee2e6;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.create-codeeditor-btn-cancel:hover {
    background: #f8f9fa;
    color: #495057;
    border-color: #adb5bd;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    text-decoration: none;
}

.create-codeeditor-btn-submit {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
}

.create-codeeditor-btn-submit:hover {
    background: linear-gradient(135deg, #5568d3, #653a8b);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
    color: white;
}

.create-codeeditor-btn-submit:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-action {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
    justify-content: center;
}

.btn-primary {
    background: #28a745;
    color: white;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
}

.btn-primary:hover {
    background: #218838;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
}

.btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
    color: white;
    text-decoration: none;
}

.btn-secondary:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    transform: none;
}

/* Loading States */
.loading {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

.loading::after {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #28a745;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-left: 10px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Date Time Groups */
.date-time-group {
    margin-bottom: 24px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    background: #f8f9fa;
}

.date-time-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.enable-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
}

.enable-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
}

.enable-toggle label {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    cursor: pointer;
}

.date-time-fields {
    display: none;
    transition: all 0.3s ease;
}

.date-time-fields.show {
    display: block;
}

.date-time-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: end;
}

.date-fields, .time-fields {
    display: flex;
    gap: 8px;
    align-items: center;
}

.date-fields select, .time-fields select {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    background: white;
}

.date-fields select:focus, .time-fields select:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
}

/* Checkbox and Radio Groups */
.checkbox-group, .radio-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
}

.checkbox-item, .radio-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
}

.checkbox-item:hover, .radio-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.checkbox-item input[type="checkbox"], .radio-item input[type="radio"] {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
}

.checkbox-item label, .radio-item label {
    font-size: 14px;
    font-weight: 500;
    color: #495057;
    cursor: pointer;
    flex: 1;
}

/* Competencies Info */
/* Competency Search Bar */
.competency-search-container {
    margin-bottom: 24px;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 16px;
    color: #6c757d;
    font-size: 16px;
    pointer-events: none;
}

.competency-search-input {
    width: 100%;
    padding: 12px 45px 12px 45px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    color: #495057;
    background: white;
    transition: all 0.3s ease;
}

.competency-search-input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.competency-search-input::placeholder {
    color: #adb5bd;
}

.clear-search-btn {
    position: absolute;
    right: 12px;
    width: 28px;
    height: 28px;
    border: none;
    background: #6c757d;
    color: white;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    font-size: 12px;
}

.clear-search-btn:hover {
    background: #495057;
    transform: scale(1.1);
}

.search-results-count {
    margin-top: 8px;
    font-size: 13px;
    color: #6c757d;
    font-style: italic;
}

.search-results-count.has-results {
    color: #28a745;
    font-weight: 600;
}

.search-results-count.no-results {
    color: #dc3545;
}

/* Competencies Container */
.competencies-container {
    padding: 20px;
}

.competencies-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 600px;
    overflow-y: auto;
    padding: 8px;
}

.competency-tree {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.competency-tree-children {
    margin-left: 32px;
    padding-left: 16px;
    border-left: 2px solid #e9ecef;
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.competency-item {
    display: flex;
    flex-direction: row !important;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    border: 1.5px solid #e9ecef;
    border-radius: 6px;
    background: white;
    transition: all 0.2s ease;
    position: relative;
}

.competency-item.has-children {
    padding-left: 40px;
}

.competency-toggle {
    position: absolute;
    left: 12px;
    top: 14px;
    width: 20px;
    height: 20px;
    border: none;
    background: #28a745;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.competency-toggle:hover {
    background: #218838;
    transform: scale(1.1);
}

.competency-toggle.collapsed::before {
    content: '▶';
}

.competency-toggle.expanded::before {
    content: '▼';
}

.competency-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}

.competency-item.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.03);
}

.competency-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
    cursor: pointer;
    margin-top: 3px;
    flex-shrink: 0;
}

.competency-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    margin-bottom: 0 !important;
}

.competency-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.competency-name {
    font-size: 14px;
    font-weight: 600;
    color: #212529;
    margin: 0;
    flex: 1;
}

.competency-idnumber {
    font-size: 11px;
    color: #868e96;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    flex-shrink: 0;
}

.competency-description {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.5;
    margin: 0;
}

.competency-bottom-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.competency-framework {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    background: #e3f2fd;
    color: #1976d2;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-text {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: #6c757d;
    font-style: italic;
}

/* Assign to Groups Section */
.assign-to-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.assign-to-options .radio-item {
    padding: 16px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.assign-to-options .radio-item:has(input:checked) {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.05);
}

.assign-to-options .radio-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.assign-to-options .radio-item label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    cursor: pointer;
    margin-left: 8px;
}

.assign-to-options .radio-item strong {
    font-size: 15px;
    color: #212529;
}

.assign-to-options .radio-item small {
    font-size: 13px;
    color: #6c757d;
    font-weight: normal;
}

.group-selection-container {
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.groups-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 400px;
    overflow-y: auto;
}

.group-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.group-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.group-item.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.05);
}

.group-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
    cursor: pointer;
    flex-shrink: 0;
}

.group-details {
    flex: 1;
}

.group-name {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    margin: 0 0 4px 0;
}

.group-description {
    font-size: 13px;
    color: #6c757d;
    margin: 0 0 6px 0;
}

.group-member-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1976d2;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.group-member-count i {
    font-size: 11px;
}

.group-member-count:hover {
    background: #d1ecf1;
    color: #0c5460;
}

/* Members Modal */
.members-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    padding: 20px;
    overflow-y: auto;
}

.members-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.members-modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 800px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.members-modal-header {
    padding: 20px 24px;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.members-modal-title {
    font-size: 20px;
    font-weight: 600;
    color: #212529;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.members-modal-title i {
    color: #007bff;
}

.members-count-badge {
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.members-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.members-modal-close:hover {
    background: #e9ecef;
    color: #212529;
}

.members-modal-search {
    padding: 16px 24px;
    border-bottom: 1px solid #dee2e6;
    background: white;
}

.members-search-input {
    width: 100%;
    padding: 10px 12px 10px 40px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.members-search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.members-search-wrapper {
    position: relative;
}

.members-search-wrapper i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.members-list-content {
    flex: 1;
    overflow-y: auto;
    padding: 16px 24px;
}

.member-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 10px;
    transition: all 0.2s ease;
    background: white;
}

.member-item:hover {
    background: #f8f9fa;
    border-color: #007bff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 18px;
    flex-shrink: 0;
}

.member-info {
    flex: 1;
    min-width: 0;
}

.member-name {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    margin-bottom: 4px;
}

.member-email {
    font-size: 13px;
    color: #6c757d;
}

.member-added {
    font-size: 12px;
    color: #adb5bd;
    margin-left: auto;
    white-space: nowrap;
}

.loading-members,
.no-members,
.error-members {
    padding: 40px 20px;
    text-align: center;
    font-size: 14px;
    color: #6c757d;
}

.loading-members i {
    font-size: 24px;
    margin-bottom: 10px;
}

.error-members {
    color: #dc3545;
}

.no-members {
    color: #6c757d;
}

.members-list-empty {
    padding: 60px 20px;
    text-align: center;
}

.members-list-empty i {
    font-size: 48px;
    color: #dee2e6;
    margin-bottom: 16px;
}

/* Create Group Modal */
.create-group-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    padding: 20px;
    overflow-y: auto;
}

.create-group-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.create-group-modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.create-group-modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.create-group-modal-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.create-group-modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    font-size: 28px;
    color: white;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.create-group-modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
}

.create-group-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

.create-group-modal-footer {
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    border-radius: 0 0 12px 12px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Create Group Button */
.btn-create-group {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
}

.btn-create-group:hover {
    background: linear-gradient(135deg, #218838, #1ea085);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
}

.btn-create-group:active {
    transform: translateY(0);
}

.dropdown-toggle {
    width: 100%;
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 500;
    color: #495057;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dropdown-toggle:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
}

.dropdown-toggle i.fa-plus-circle {
    color: #28a745;
}

.dropdown-arrow {
    margin-left: auto;
    font-size: 12px;
    transition: transform 0.2s ease;
}

.dropdown-toggle.active .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-panel {
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 6px 6px;
    padding: 20px;
    margin-top: -1px;
}

.create-group-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.create-group-form .form-col-full {
    grid-column: 1 / -1;
}

.create-group-form .form-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #495057;
    margin-bottom: 6px;
}

.create-group-form .required {
    color: #dc3545;
}

.create-group-form .form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.create-group-form .form-input:focus {
    outline: none;
    border-color: #80bdff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
}

.student-selector {
    border: 1px solid #ced4da !important;
    border-radius: 4px;
    overflow: hidden;
    background: #ffffff !important;
    box-shadow: none !important;
}

.student-selector * {
    border-top-color: transparent !important;
    border-bottom-color: transparent !important;
}

.student-selector *:not(.student-item):not(.search-input):not(.student-search) {
    border-left-color: transparent !important;
    border-right-color: transparent !important;
}

.student-search {
    position: relative;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.student-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 13px;
}

.search-input {
    width: 100%;
    padding: 10px 10px 10px 36px;
    border: none;
    background: transparent;
    font-size: 13px;
}

.search-input:focus {
    outline: none;
}

.students-list {
    max-height: 300px;
    overflow-y: auto;
    padding: 10px;
    background: #ffffff !important;
    border: none !important;
    box-shadow: none !important;
}

.students-list::before,
.students-list::after {
    display: none !important;
}

.student-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid #dee2e6 !important;
    border-top: 1px solid #dee2e6 !important;
    border-bottom: 1px solid #dee2e6 !important;
    border-left: 1px solid #dee2e6 !important;
    border-right: 1px solid #dee2e6 !important;
    border-radius: 6px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: none !important;
    transform: none !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    min-height: 60px;
    box-shadow: none !important;
    outline: none !important;
}

.student-item::before,
.student-item::after {
    display: none !important;
}

.student-item:hover {
    background: #f8f9fa !important;
    background-color: #f8f9fa !important;
    border: 1px solid #adb5bd !important;
    border-top: 1px solid #adb5bd !important;
    border-bottom: 1px solid #adb5bd !important;
    border-left: 1px solid #adb5bd !important;
    border-right: 1px solid #adb5bd !important;
    transform: none !important;
    transition: none !important;
}

.student-item.selected {
    background: #d4edda !important;
    background-color: #d4edda !important;
    border: 1px solid #28a745 !important;
    border-top: 1px solid #28a745 !important;
    border-bottom: 1px solid #28a745 !important;
    border-left: 1px solid #28a745 !important;
    border-right: 1px solid #28a745 !important;
    box-shadow: 0 2px 6px rgba(40, 167, 69, 0.2) !important;
}

.student-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
    accent-color: #28a745;
}

.student-details {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.student-name {
    font-size: 14px;
    font-weight: 600;
    color: #212529;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.student-email {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #dee2e6;
}

.btn-cancel {
    padding: 8px 16px;
    background: #ffffff;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    color: #495057;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-cancel:hover {
    background: #e9ecef;
}

.btn-create {
    padding: 8px 16px;
    background: #28a745;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    color: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-create:hover {
    background: #218838;
}

.btn-create:disabled {
    background: #6c757d;
    cursor: not-allowed;
}

/* Existing Groups Section */
.existing-groups-section {
    margin-top: 20px;
}

.section-title {
    font-size: 15px;
    font-weight: 600;
    color: #495057;
    margin: 0 0 12px 0;
}

.info-message {
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-size: 13px;
    background: #ffffff !important;
    border: none !important;
}

.info-message i {
    color: #6c757d;
    margin-right: 6px;
}

.competencies-info {
    text-align: center;
    padding: 20px;
    color: #6c757d;
}

.competencies-info p {
    margin: 0 0 16px 0;
    font-size: 16px;
}

.info-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 12px 16px;
    color: #0c5460;
    font-size: 14px;
}

.info-box i {
    font-size: 16px;
}

/* Form Section Icons */
.form-section-title i {
    margin-right: 8px;
    color: #28a745;
    font-size: 18px;
}

/* Tab Navigation */
.form-tabs {
    margin-bottom: 0;
    background: white;
    border-radius: 12px 12px 0 0;
    border: 1px solid #e9ecef;
    border-bottom: none;
}

.tab-nav {
    display: flex;
    background: #f8f9fa;
    border-radius: 12px 12px 0 0;
    padding: 0;
    margin: 0;
    overflow-x: auto;
}

.tab-button {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px 20px;
    border: none;
    background: transparent;
    color: #6c757d;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
    min-width: 120px;
}

.tab-button:hover {
    background: #e9ecef;
    color: #495057;
}

.tab-button.active {
    background: white;
    color: #28a745;
    border-bottom-color: #28a745;
    font-weight: 600;
}

.tab-button i {
    font-size: 16px;
}

/* Tab Content */
.tab-content {
    background: white;
    border: 1px solid #e9ecef;
    border-top: none;
    border-radius: 0 0 12px 12px;
    min-height: 400px;
}

.tab-panel {
    display: none;
    padding: 40px 50px;
    animation: fadeIn 0.3s ease-in-out;
}

.tab-panel.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Enhanced Form Styling */
.form-section {
    position: relative;
    margin-bottom: 0;
}

.form-section::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #28a745, #20c997);
    border-radius: 0 2px 2px 0;
}

.form-section-title {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e9ecef;
    position: relative;
}

.form-section-title::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 60px;
    height: 2px;
    background: #28a745;
}

/* Enhanced Input Styling */
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
    transform: translateY(-1px);
}

.form-input, .form-select, .form-textarea {
    transition: all 0.2s ease;
}

.form-input:hover, .form-select:hover, .form-textarea:hover {
    border-color: #adb5bd;
}

/* Required Field Indicator */
.form-label.required::after {
    content: ' *';
    color: #dc3545;
    font-weight: bold;
}

/* Loading States */
.loading {
    text-align: center;
    padding: 40px;
    color: #6c757d;
    font-style: italic;
}

.loading::after {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #28a745;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-left: 10px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Rubric Builder Styles - Tabular Format */
.rubric-builder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.rubric-builder-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.rubric-criteria-container {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow-x: auto;
    overflow-y: visible;
    max-width: 100%;
}

.rubric-criteria-container::-webkit-scrollbar {
    height: 10px;
}

.rubric-criteria-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 0 0 8px 8px;
}

.rubric-criteria-container::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 5px;
}

.rubric-criteria-container::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.rubric-empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    color: #6c757d;
}

.rubric-empty-state i {
    font-size: 48px;
    color: #dee2e6;
    margin-bottom: 16px;
    display: block;
}

.rubric-empty-state p {
    margin: 0;
    font-size: 15px;
}

.rubric-table {
    width: auto;
    min-width: 100%;
    border-collapse: collapse;
    background: white;
    table-layout: auto;
}

.rubric-table thead {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-bottom: 2px solid #dee2e6;
}

.rubric-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-right: 1px solid #dee2e6;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    white-space: nowrap;
}

.rubric-table th:first-child,
.rubric-table th:nth-child(2) {
    position: sticky;
    z-index: 10;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.rubric-table th:first-child {
    left: 0;
}

.rubric-table th:nth-child(2) {
    left: 60px;
}

.rubric-table th:last-child {
    border-right: none;
}

.rubric-table tbody tr {
    border-bottom: 1px solid #e9ecef;
    transition: background 0.2s ease;
}

.rubric-table tbody tr:hover {
    background: #f8f9fa;
}

.rubric-table td {
    padding: 16px;
    vertical-align: top;
    border-right: 1px solid #e9ecef;
    background: white;
}

.rubric-table td:first-child,
.rubric-table td:nth-child(2) {
    position: sticky;
    z-index: 5;
    background: white;
}

.rubric-table td:first-child {
    left: 0;
}

.rubric-table td:nth-child(2) {
    left: 60px;
}

.rubric-table tbody tr:hover td {
    background: #f8f9fa;
}

.rubric-table tbody tr:hover td:first-child,
.rubric-table tbody tr:hover td:nth-child(2) {
    background: #f8f9fa;
}

.rubric-table td:last-child {
    border-right: none;
}

.criterion-controls {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
    min-width: 40px;
}

.btn-criterion-control {
    background: white;
    border: 1px solid #dee2e6;
    width: 32px;
    height: 32px;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    transition: all 0.2s ease;
}

.btn-criterion-control:hover {
    border-color: #adb5bd;
    background: #f8f9fa;
    color: #495057;
}

.btn-criterion-control.delete:hover {
    border-color: #dc3545;
    background: #fff5f5;
    color: #dc3545;
}

.criterion-description-cell {
    min-width: 200px;
}

.criterion-description-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    resize: vertical;
    min-height: 70px;
    font-family: inherit;
}

.criterion-description-input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
}

.level-cell {
    min-width: 150px;
    position: relative;
}

.level-content {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.level-definition-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 13px;
    resize: vertical;
    min-height: 60px;
    font-family: inherit;
    order: 1; /* Textarea first */
}

.level-definition-input:focus {
    outline: none;
    border-color: #28a745;
}

.level-score-row {
    display: flex;
    align-items: center;
    gap: 8px;
    order: 2; /* Score row second */
}

.level-score-input {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    color: #28a745;
    min-width: 60px;
}

.level-score-input:focus {
    outline: none;
    border-color: #28a745;
}

.level-points-label {
    font-size: 12px;
    color: #28a745;
    font-weight: 600;
    white-space: nowrap;
}

.btn-delete-level {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    padding: 4px;
    font-size: 14px;
    transition: all 0.2s ease;
}

.btn-delete-level:hover {
    transform: scale(1.2);
}

.add-level-cell {
    text-align: center;
    vertical-align: middle;
}

.btn-add-level {
    background: white;
    border: 2px dashed #007bff;
    color: #007bff;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-add-level:hover {
    background: #e7f3ff;
    border-color: #0056b3;
    color: #0056b3;
}

.btn-add-criterion {
    background: white;
    border: 2px dashed #007bff;
    color: #007bff;
    padding: 10px 20px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
}

.btn-add-criterion:hover {
    background: #e7f3ff;
    border-color: #0056b3;
    color: #0056b3;
}

.rubric-summary {
    margin-top: 24px;
    padding: 20px;
    background: linear-gradient(135deg, #e3f2fd, #e8f5e9);
    border-radius: 8px;
    border: 1px solid #b3e5fc;
    display: flex;
    justify-content: space-around;
    align-items: center;
}

.summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.summary-label {
    font-size: 13px;
    color: #546e7a;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
    color: #1976d2;
}

/* Responsive Design */
@media (max-width: 768px) {
    .assignment-creation-container {
        padding: 10px;
    }
    
    .tab-nav {
        flex-direction: column;
        overflow-x: visible;
    }
    
    .tab-button {
        min-width: auto;
        padding: 12px 16px;
        border-bottom: 1px solid #e9ecef;
        border-right: none;
    }
    
    .tab-button.active {
        border-bottom-color: #28a745;
        border-right: 3px solid #28a745;
    }
    
    .tab-content {
        border-radius: 0 0 12px 12px;
    }
    
    .tab-panel {
        padding: 20px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .date-time-row {
        grid-template-columns: 1fr;
    }
    
    .date-fields, .time-fields {
        flex-wrap: wrap;
    }
    
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-action {
        width: 100%;
        justify-content: center;
    }
    
    .tree-children {
        margin-left: 16px;
    }
    
    .checkbox-group, .radio-group {
        gap: 8px;
    }
    
    .checkbox-item, .radio-item {
        padding: 8px 12px;
    }
}
</style>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>

        <!-- Main Content -->
        <div class="teacher-main-content">
            <div class="codeeditor-creation-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <a href="assignments.php" class="back-button">
                    <i class="fa fa-arrow-left"></i>
                    Back to Activities
                </a>
                <div class="page-header-text">
                    <h1 class="page-title-header">Create New Code Editor</h1>
                    <p class="page-subtitle">Create a new code editor activity and place it in your course structure</p>
                </div>
            </div>
        </div>

        <!-- Code Editor Creation Form -->
        <form id="codeeditorForm" class="creation-form" method="POST" action="create_codeeditor.php">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            
            <!-- Tab Navigation -->
            <div class="form-tabs">
                <div class="tab-nav">
                    <button type="button" class="tab-button active" onclick="showTab('general')">
                        <i class="fa fa-info-circle"></i>
                        General
                    </button>
                    <button type="button" class="tab-button" onclick="showTab('grade')">
                        <i class="fa fa-star"></i>
                        Grade
                    </button>
                    <button type="button" class="tab-button" onclick="showTab('competencies')">
                        <i class="fa fa-trophy"></i>
                        Competencies
                    </button>
                    <button type="button" class="tab-button" onclick="showTab('assignto')">
                        <i class="fa fa-users"></i>
                        Assign to
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="tab-content">
                
                <!-- General Section -->
                <div id="general-tab" class="tab-panel active">
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <i class="fa fa-info-circle"></i>
                            General
                        </h2>

                         <!-- Course Selection -->
                         <div class="form-group">
                            <label class="form-label required" for="codeeditorCourse">Course</label>
                            <select id="codeeditorCourse" name="courseid" class="form-select" required onchange="loadCourseStructure(); loadCompetencies(this.value);">
                                <option value="">Select a course...</option>
                                <?php foreach ($teacher_courses as $course): ?>
                                    <option value="<?php echo $course->id; ?>"><?php echo format_string($course->fullname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                           <!-- Course Structure Placement -->
                           <div class="course-structure-section">
                            <div class="course-structure-title">Course Structure Placement</div>
                            <div class="course-structure-description">
                                Choose the lesson and module where this code editor activity should be placed.
                            </div>
                            <div class="course-structure-tree">
                                <div id="courseStructureTree">
                                    <div class="loading">Please select a course first</div>
                                </div>
                            </div>
                            <div id="selectionInfo" class="selection-info hidden">
                                <strong>Selected:</strong> <span id="selectionText"></span>
                            </div>
                            <input type="hidden" id="selectedSection" name="section" value="">
                            <input type="hidden" id="selectedModule" name="module" value="">
                        </div>
                        
                        <!-- Code Editor Name -->
                        <div class="form-group">
                            <label class="form-label required" for="codeeditorName">Activity name</label>
                            <input type="text" id="codeeditorName" name="name" class="form-input" required 
                                   placeholder="E.g., Python Basics, JavaScript Calculator, HTML Portfolio...">
                        </div>

                        <!-- Description (Coding Challenge/Problem Statement) -->
                        <div class="form-group">
                            <label class="form-label required" for="codeeditorDescription">Coding Challenge / Problem Statement</label>
                            <?php
                            // Use Moodle's TinyMCE editor for rich text with image upload
                            $intro_editor = editors_get_preferred_editor(FORMAT_HTML);
                            $intro_editor->use_editor('codeeditorDescription', $editoroptions);
                            ?>
                            <textarea id="codeeditorDescription" name="intro" rows="10" style="width: 100%;" required></textarea>
                            <small class="form-text">This will be shown to students as the coding assignment instructions. You can add images, formatting, and media.</small>
                        </div>

                        <!-- Additional Instructions -->
                        <div class="form-group">
                            <label class="form-label" for="codeeditorInstructions">Additional instructions (Optional)</label>
                            <?php
                            // Use Moodle's TinyMCE editor for additional instructions
                            $desc_editor = editors_get_preferred_editor(FORMAT_HTML);
                            $desc_editor->use_editor('codeeditorInstructions', $editoroptions);
                            ?>
                            <textarea id="codeeditorInstructions" name="description" rows="8" style="width: 100%;"></textarea>
                            <small class="form-text">Add any additional guidance, hints, or requirements. Supports images and rich formatting.</small>
                        </div>
                    </div>
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <i class="fa fa-calendar"></i>
                            Availability
                        </h2>
                
                        <!-- Due date -->
                        <div class="date-time-group">
                            <div class="date-time-header">
                                <label class="form-label">Due date</label>
                                <div class="enable-toggle">
                                    <input type="checkbox" id="enableDueDate" name="enable_due_date" onchange="toggleDateTime('dueDate')">
                                    <label for="enableDueDate">Enable</label>
                                </div>
                            </div>
                            <div id="dueDateFields" class="date-time-fields">
                                <div class="date-time-row">
                                    <div class="date-fields">
                                        <select name="due_day" class="form-select">
                                            <option value="">Day</option>
                                            <?php for($i = 1; $i <= 31; $i++): ?>
                                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select name="due_month" class="form-select">
                                            <option value="">Month</option>
                                            <?php foreach($months as $index => $month): ?>
                                                <option value="<?php echo $index + 1; ?>"><?php echo $month; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="due_year" class="form-select">
                                            <option value="">Year</option>
                                            <?php for($i = date('Y'); $i <= date('Y') + 5; $i++): ?>
                                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="time-fields">
                                        <select name="due_hour" class="form-select">
                                            <option value="">Hour</option>
                                            <?php for($i = 0; $i < 24; $i++): ?>
                                                <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select name="due_minute" class="form-select">
                                            <option value="">Minute</option>
                                            <?php for($i = 0; $i < 60; $i += 5): ?>
                                                <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assign to Section -->
                <div id="assignto-tab" class="tab-panel">
                    <div class="form-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                            <h2 class="form-section-title" style="margin: 0;">
                                <i class="fa fa-users"></i>
                                Assign to
                            </h2>
                            <button type="button" class="btn-create-group" onclick="openCreateGroupModal()">
                                <i class="fa fa-plus"></i> Create New Group
                            </button>
                        </div>
                        
                        <div class="assign-to-options">
                            <div class="radio-item">
                                <input type="radio" id="assignToAll" name="assign_to" value="all" checked onchange="toggleGroupSelection()">
                                <label for="assignToAll">
                                    <strong>All students in the course</strong>
                                    <small>Everyone will see and can submit this assignment</small>
                                </label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="assignToGroups" name="assign_to" value="groups" onchange="toggleGroupSelection()">
                                <label for="assignToGroups">
                                    <strong>Specific groups only</strong>
                                    <small>Only students in selected groups will see this assignment</small>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Group Selection (hidden by default) -->
                        <div id="groupSelectionContainer" class="group-selection-container" style="display: none; margin-top: 24px;">
                            <h3 class="section-title">Select Groups</h3>
                            <div class="loading" id="groupsLoading">
                                <i class="fa fa-spinner fa-spin"></i> Loading groups... Select a course first if you haven't already.
                            </div>
                            <div id="groupsList" class="groups-list" style="display: none;">
                                <!-- Groups will be loaded here dynamically -->
                            </div>
                            <div id="noGroups" class="info-message" style="display: none;">
                                <i class="fa fa-info-circle"></i>
                                No groups found. Click "Create New Group" above.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grade Section -->
                <div id="grade-tab" class="tab-panel">
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <i class="fa fa-star"></i>
                            Grade
                        </h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="maxGrade">Maximum Grade</label>
                                <input type="number" id="maxGrade" name="grade" class="form-input" value="100" min="0" step="0.01" readonly>
                                <small class="form-text">Grade will be calculated from rubric criteria (if using rubric)</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="gradingMethod">Grading method</label>
                                <select id="gradingMethod" name="grading_method" class="form-select" onchange="toggleRubricBuilder()">
                                    <option value="simple">Simple Grading</option>
                                    <option value="rubric">Rubric</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Rubric Builder (shown only when rubric is selected) -->
                        <div id="rubricBuilder" style="display: none; margin-top: 30px;">
                            <div class="rubric-builder-header">
                                <h3 class="rubric-builder-title">
                                    <i class="fa fa-table"></i>
                                    Rubric Design
                                </h3>
                            </div>
                            
                            <div id="rubricCriteria" class="rubric-criteria-container">
                                <div class="rubric-empty-state">
                                    <i class="fa fa-table"></i>
                                    <p>No criteria added yet. Click "Add Criterion" to get started.</p>
                                </div>
                            </div>
                            
                            <button type="button" class="btn-add-criterion" onclick="addCriterion()">
                                <i class="fa fa-plus"></i> Add criterion
                            </button>
                            
                            <div class="rubric-summary" id="rubricSummary" style="display: none;">
                                <div class="summary-item">
                                    <span class="summary-label">Total Criteria:</span>
                                    <span class="summary-value" id="criteriaCount">0</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Maximum Score:</span>
                                    <span class="summary-value" id="maxScore">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Competencies Section -->
                <div id="competencies-tab" class="tab-panel">
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <i class="fa fa-trophy"></i>
                            Competencies
                        </h2>
                        
                        <!-- Competency Search Bar -->
                        <div class="competency-search-container" id="competencySearchContainer" style="display: none;">
                            <div class="search-input-wrapper">
                                <i class="fa fa-search search-icon"></i>
                                <input type="text" 
                                       id="competencySearchInput" 
                                       class="competency-search-input" 
                                       placeholder="Search competencies by name, ID, or description..."
                                       autocomplete="off">
                                <button type="button" id="clearSearchBtn" class="clear-search-btn" style="display: none;">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <div id="searchResultsCount" class="search-results-count" style="display: none;"></div>
                        </div>
                        
                        <div id="competenciesContainer" class="competencies-container">
                            <div class="loading" id="competenciesLoading">
                                <i class="fa fa-spinner fa-spin"></i>
                                Loading competencies... Please select a course first.
                            </div>
                            <div id="competenciesList" class="competencies-list" style="display: none;">
                                <!-- Competencies will be loaded here dynamically -->
                            </div>
                            <div id="noCompetencies" class="info-box" style="display: none;">
                                <i class="fa fa-info-circle"></i>
                                <span>No competencies are linked to this course yet. You can add competencies from the course management page.</span>
                            </div>
                        </div>
                        
                        <!-- Global Competency Completion Action -->
                        <div class="form-group" id="globalCompetencyAction" style="margin-top: 20px; display: none;">
                            <label class="form-label" for="competencyCompletionAction">
                                <i class="fa fa-check-circle"></i>
                                Upon activity completion for all selected competencies
                            </label>
                            <select id="competencyCompletionAction" name="competency_completion_action" class="form-select">
                                <option value="0">Do nothing</option>
                                <option value="1">Attach evidence</option>
                                <option value="2">Send for review</option>
                                <option value="3">Complete the competency</option>
                            </select>
                            <small class="form-text">This action will apply to all competencies you select above.</small>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="assignments.php" class="create-codeeditor-btn-cancel">
                    <i class="fa fa-times"></i>
                    Cancel
                </a>
                <button type="button" class="create-codeeditor-btn-submit" onclick="createCodeEditor()" id="createBtn">
                    <i class="fa fa-plus"></i>
                    Create Code Editor
                </button>
            </div>
        </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle teacher sidebar
function toggleTeacherSidebar() {
    const sidebar = document.getElementById('teacherSidebar');
    sidebar.classList.toggle('collapsed');
}

// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.getElementById('teacherSidebar');
    
    if (window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
    }
    
    window.addEventListener('resize', function() {
        if (window.innerWidth <= 768) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    });
});

// Set default dates when page loads
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    // Set to midnight (00:00) of today to avoid timezone issues
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
    
    // Set to 11:59 PM (23:59) 7 days from now
    const sevenDaysLater = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
    sevenDaysLater.setHours(23, 59, 0, 0);
    
    // Enable due date field by default
    const dueDateCheckbox = document.getElementById('enableDueDate');
    
    if (dueDateCheckbox) {
        dueDateCheckbox.checked = true;
        toggleDateTime('dueDate');
    }
    
    // Set default value for due date (7 days from now)
    setTimeout(function() {
        setDefaultDateTime('due', sevenDaysLater);
    }, 100);
    
    // Load competencies when course is selected
    const codeeditorCourse = document.getElementById('codeeditorCourse');
    if (codeeditorCourse) {
        codeeditorCourse.addEventListener('change', function() {
            loadCompetencies(this.value);
            loadCourseGroups(this.value);
        });
        
        // Load competencies for initially selected course
        if (codeeditorCourse.value) {
            loadCompetencies(codeeditorCourse.value);
            loadCourseGroups(codeeditorCourse.value);
        }
    }
});

// Set default date/time values
function setDefaultDateTime(prefix, date) {
    const day = date.getDate();
    const month = date.getMonth() + 1;
    const year = date.getFullYear();
    const hour = date.getHours();
    const minute = Math.floor(date.getMinutes() / 5) * 5; // Round to nearest 5 minutes
    
    console.log(`Setting ${prefix} to:`, day, month, year, hour, minute);
    
    // Set day
    const daySelect = document.querySelector(`select[name="${prefix}_day"]`);
    if (daySelect) {
        daySelect.value = day.toString();
        console.log(`  Day set to: ${daySelect.value}`);
    }
    
    // Set month
    const monthSelect = document.querySelector(`select[name="${prefix}_month"]`);
    if (monthSelect) {
        monthSelect.value = month.toString();
        console.log(`  Month set to: ${monthSelect.value}`);
    }
    
    // Set year
    const yearSelect = document.querySelector(`select[name="${prefix}_year"]`);
    if (yearSelect) {
        yearSelect.value = year.toString();
        console.log(`  Year set to: ${yearSelect.value}`);
    }
    
    // Set hour
    const hourSelect = document.querySelector(`select[name="${prefix}_hour"]`);
    if (hourSelect) {
        hourSelect.value = hour.toString();
        console.log(`  Hour set to: ${hourSelect.value}`);
    }
    
    // Set minute
    const minuteSelect = document.querySelector(`select[name="${prefix}_minute"]`);
    if (minuteSelect) {
        minuteSelect.value = minute.toString();
        console.log(`  Minute set to: ${minuteSelect.value}`);
    }
}

// Toggle date/time fields
function toggleDateTime(type) {
    const checkbox = document.getElementById(`enable${type.charAt(0).toUpperCase() + type.slice(1)}`);
    const fields = document.getElementById(`${type}Fields`);
    
    if (checkbox.checked) {
        fields.classList.add('show');
    } else {
        fields.classList.remove('show');
    }
}

// Tab switching functionality
function showTab(tabName) {
    // Hide all tab panels
    const tabPanels = document.querySelectorAll('.tab-panel');
    tabPanels.forEach(panel => {
        panel.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab panel
    const selectedPanel = document.getElementById(`${tabName}-tab`);
    if (selectedPanel) {
        selectedPanel.classList.add('active');
    }
    
    // Add active class to clicked tab button
    const clickedButton = event.target.closest('.tab-button');
    if (clickedButton) {
        clickedButton.classList.add('active');
    }
}

// Load competencies for selected course
function loadCompetencies(courseid) {
    console.log('loadCompetencies called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('competenciesLoading');
    const listDiv = document.getElementById('competenciesList');
    const noCompDiv = document.getElementById('noCompetencies');
    
    // Show loading
    loadingDiv.style.display = 'flex';
    listDiv.style.display = 'none';
    noCompDiv.style.display = 'none';
    listDiv.innerHTML = '';
    
    if (!courseid) {
        console.log('No courseid provided, showing no competencies message');
        loadingDiv.style.display = 'none';
        noCompDiv.style.display = 'flex';
        return;
    }
    
    const apiUrl = `get_course_competencies.php?courseid=${courseid}`;
    console.log('Fetching competencies from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => {
            console.log('Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Competencies data:', data);
            loadingDiv.style.display = 'none';
            
            if (data.success && data.competencies && data.competencies.length > 0) {
                console.log('Rendering', data.competencies.length, 'competencies');
                // Store competencies globally for searching
                window.allCompetencies = data.competencies;
                renderCompetencies(data.competencies);
                listDiv.style.display = 'flex';
                // Show the global completion action dropdown
                document.getElementById('globalCompetencyAction').style.display = 'block';
                // Show the search bar
                document.getElementById('competencySearchContainer').style.display = 'block';
                // Initialize search functionality
                initializeCompetencySearch();
            } else {
                console.log('No competencies found or error occurred');
                noCompDiv.style.display = 'flex';
                // Hide the global completion action dropdown
                document.getElementById('globalCompetencyAction').style.display = 'none';
                // Hide the search bar
                document.getElementById('competencySearchContainer').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading competencies:', error);
            loadingDiv.style.display = 'none';
            noCompDiv.style.display = 'flex';
        });
}

// Render competencies as tree structure
function renderCompetencies(competencies) {
    const listDiv = document.getElementById('competenciesList');
    listDiv.innerHTML = '';
    
    const tree = document.createElement('div');
    tree.className = 'competency-tree';
    
    competencies.forEach(comp => {
        const itemContainer = createCompetencyItem(comp);
        tree.appendChild(itemContainer);
    });
    
    listDiv.appendChild(tree);
}

// Create a competency item with potential children
function createCompetencyItem(comp, level = 0) {
    const container = document.createElement('div');
    
    const item = document.createElement('div');
    item.className = 'competency-item';
    item.dataset.competencyId = comp.id;
    
    // Add toggle button if has children
    if (comp.children && comp.children.length > 0) {
        item.classList.add('has-children');
        
        const toggle = document.createElement('button');
        toggle.className = 'competency-toggle expanded';
        toggle.type = 'button';
        toggle.onclick = function() {
            toggleCompetencyChildren(comp.id);
        };
        item.appendChild(toggle);
    }
    
    // Checkbox
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'competencies[]';
    checkbox.value = comp.id;
    checkbox.className = 'competency-checkbox';
    checkbox.id = `comp_${comp.id}`;
    checkbox.dataset.competencyId = comp.id;
    
    // When parent is checked, check all children
    checkbox.addEventListener('change', function() {
        if (this.checked) {
            item.classList.add('selected');
            // Check all children recursively
            checkAllChildren(comp.id, true);
        } else {
            item.classList.remove('selected');
            // Uncheck all children recursively
            checkAllChildren(comp.id, false);
        }
    });
    
    item.appendChild(checkbox);
    
    // Details
    const details = document.createElement('div');
    details.className = 'competency-details';
    
    // Top row: Name and ID
    const topRow = document.createElement('div');
    topRow.className = 'competency-top-row';
    
    const nameLabel = document.createElement('label');
    nameLabel.className = 'competency-name';
    nameLabel.htmlFor = `comp_${comp.id}`;
    nameLabel.textContent = comp.shortname;
    nameLabel.style.cursor = 'pointer';
    
    // Show children count if parent
    if (comp.children && comp.children.length > 0) {
        nameLabel.textContent += ` (${comp.children.length} sub-competencies)`;
    }
    
    // Allow clicking on label to toggle checkbox
    nameLabel.addEventListener('click', function() {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
    });
    
    topRow.appendChild(nameLabel);
    
    if (comp.idnumber) {
        const idnumber = document.createElement('span');
        idnumber.className = 'competency-idnumber';
        idnumber.textContent = `ID: ${comp.idnumber}`;
        topRow.appendChild(idnumber);
    }
    
    details.appendChild(topRow);
    
    // Description (shortened)
    if (comp.description) {
        const description = document.createElement('div');
        description.className = 'competency-description';
        // Strip HTML tags for display
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = comp.description;
        const descText = tempDiv.textContent || tempDiv.innerText || '';
        // Truncate to 200 characters for parents, 150 for children
        const maxLength = (comp.children && comp.children.length > 0) ? 200 : 150;
        description.textContent = descText.length > maxLength ? descText.substring(0, maxLength) + '...' : descText;
        details.appendChild(description);
    }
    
    // Bottom row: Framework
    const bottomRow = document.createElement('div');
    bottomRow.className = 'competency-bottom-row';
    
    if (comp.framework) {
        const framework = document.createElement('span');
        framework.className = 'competency-framework';
        framework.textContent = comp.framework;
        bottomRow.appendChild(framework);
    }
    
    details.appendChild(bottomRow);
    item.appendChild(details);
    container.appendChild(item);
    
    // Render children if exists
    if (comp.children && comp.children.length > 0) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'competency-tree-children';
        childrenContainer.id = `children_${comp.id}`;
        
        comp.children.forEach(child => {
            const childItem = createCompetencyItem(child, level + 1);
            childrenContainer.appendChild(childItem);
        });
        
        container.appendChild(childrenContainer);
    }
    
    return container;
}

// Toggle competency children visibility
function toggleCompetencyChildren(competencyId) {
    const childrenContainer = document.getElementById(`children_${competencyId}`);
    const toggle = document.querySelector(`[data-competency-id="${competencyId}"] .competency-toggle`);
    
    if (childrenContainer && toggle) {
        if (childrenContainer.style.display === 'none') {
            childrenContainer.style.display = 'flex';
            toggle.classList.remove('collapsed');
            toggle.classList.add('expanded');
        } else {
            childrenContainer.style.display = 'none';
            toggle.classList.remove('expanded');
            toggle.classList.add('collapsed');
        }
    }
}

// Check/uncheck all children recursively
function checkAllChildren(parentId, checked) {
    const childrenContainer = document.getElementById(`children_${parentId}`);
    if (!childrenContainer) return;
    
    const childCheckboxes = childrenContainer.querySelectorAll('.competency-checkbox');
    childCheckboxes.forEach(checkbox => {
        checkbox.checked = checked;
        const item = checkbox.closest('.competency-item');
        if (checked) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
        
        // Recursively check children of this child
        const childId = checkbox.dataset.competencyId;
        checkAllChildren(childId, checked);
    });
}

// Load course structure when course is selected
function loadCourseStructure() {
    const courseId = document.getElementById('codeeditorCourse').value;
    const treeContainer = document.getElementById('courseStructureTree');
    const selectionInfo = document.getElementById('selectionInfo');
    
    if (!courseId) {
        treeContainer.innerHTML = '<div class="loading">Please select a course first</div>';
        selectionInfo.classList.add('hidden');
        return;
    }
    
    // Show loading
    treeContainer.innerHTML = '<div class="loading">Loading course structure...</div>';
    selectionInfo.classList.add('hidden');
    
    // Fetch course structure via AJAX
    fetch('get_course_structure.php?courseid=' + courseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderCourseTree(data.structure);
            } else {
                treeContainer.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading course structure</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            treeContainer.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading course structure</div>';
        });
}

// Render course structure tree
function renderCourseTree(structure) {
    const container = document.getElementById('courseStructureTree');
    container.innerHTML = '';
    
    const tree = document.createElement('ul');
    tree.className = 'course-tree';
    
    structure.forEach(section => {
        const sectionItem = createTreeItem(section.name, '📁', section.id, 'section');
        const children = document.createElement('ul');
        children.className = 'tree-children';
        
        if (section.modules && section.modules.length > 0) {
            section.modules.forEach(module => {
                // Use section_id from module if available, otherwise use section.id
                const sectionId = module.section_id || section.id;
                console.log('Rendering module:', module.name, 'Module ID:', module.id, 'Section ID:', sectionId);
                const moduleItem = createTreeItem(module.name, '📂', module.id, 'module', sectionId);
                children.appendChild(moduleItem);
            });
        } else {
            const noModules = document.createElement('li');
            noModules.innerHTML = '<div class="tree-item disabled"><span class="tree-icon">📁</span><span class="tree-label">No modules in this lesson</span></div>';
            children.appendChild(noModules);
        }
        
        sectionItem.appendChild(children);
        tree.appendChild(sectionItem);
    });
    
    container.appendChild(tree);
}

// Create tree item element
function createTreeItem(name, icon, id, type, parentId = null) {
    const li = document.createElement('li');
    const item = document.createElement('div');
    item.className = 'tree-item';
    item.setAttribute('data-id', id);
    item.setAttribute('data-type', type);
    if (parentId) item.setAttribute('data-parent', parentId);
    
    item.innerHTML = `
        <span class="tree-icon">${icon}</span>
        <span class="tree-label">${name}</span>
        <button class="tree-toggle" onclick="toggleTreeItem(this)">▼</button>
    `;
    
    item.addEventListener('click', function(e) {
        if (e.target.classList.contains('tree-toggle')) return;
        selectTreeItem(this);
    });
    
    li.appendChild(item);
    return li;
}

// Toggle tree item (expand/collapse)
function toggleTreeItem(button) {
    const item = button.closest('.tree-item');
    const children = item.parentElement.querySelector('.tree-children');
    if (children) {
        children.style.display = children.style.display === 'none' ? 'block' : 'none';
        button.textContent = button.textContent === '▼' ? '▶' : '▼';
    }
}

// Select tree item
function selectTreeItem(element) {
    // Remove previous selection
    document.querySelectorAll('.tree-item.selected').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Add selection to clicked item
    element.classList.add('selected');
    
    const type = element.getAttribute('data-type');
    const id = element.getAttribute('data-id');
    const parentId = element.getAttribute('data-parent');
    
    // Update hidden fields
    if (type === 'section') {
        document.getElementById('selectedSection').value = id;
        document.getElementById('selectedModule').value = '';
        console.log('Section selected - ID:', id);
        updateSelectionInfo(element.querySelector('.tree-label').textContent, 'lesson');
    } else if (type === 'module') {
        document.getElementById('selectedSection').value = parentId;
        document.getElementById('selectedModule').value = id;
        console.log('Module selected - Module ID:', id, 'Parent Section ID:', parentId);
        const sectionName = element.closest('.tree-children').previousElementSibling.querySelector('.tree-label').textContent;
        updateSelectionInfo(`${sectionName} > ${element.querySelector('.tree-label').textContent}`, 'module');
    }
}

// Update selection info display
function updateSelectionInfo(text, type) {
    const selectionInfo = document.getElementById('selectionInfo');
    const selectionText = document.getElementById('selectionText');
    
    // Get the selected section ID
    const sectionId = document.getElementById('selectedSection').value;
    
    // Display text with section ID
    selectionText.textContent = text + ' (Section ID: ' + sectionId + ')';
    selectionInfo.classList.remove('hidden');
}

// Create code editor
function createCodeEditor() {
    const form = document.getElementById('codeeditorForm');
    const formData = new FormData(form);
    const createBtn = document.getElementById('createBtn');
    
    // Validate required fields
    if (!formData.get('name')) {
        alert('Please enter a code editor activity name');
        return;
    }
    
    if (!formData.get('intro')) {
        alert('Please enter the coding challenge/problem statement');
        return;
    }
    
    if (!formData.get('courseid')) {
        alert('Please select a course');
        return;
    }
    
    if (!document.getElementById('selectedSection').value) {
        alert('Please select a section (lesson) for the code editor activity');
        return;
    }
    
    // Show loading
    const originalText = createBtn.innerHTML;
    createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    createBtn.disabled = true;
    
    // Submit form
    fetch('create_codeeditor.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Code Editor activity created successfully!');
            window.location.href = 'assignments.php';
        } else {
            alert('Error creating code editor: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating code editor. Please try again.');
    })
    .finally(() => {
        createBtn.innerHTML = originalText;
        createBtn.disabled = false;
    });
}

// Initialize competency search functionality
function initializeCompetencySearch() {
    const searchInput = document.getElementById('competencySearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    const resultsCount = document.getElementById('searchResultsCount');
    
    if (!searchInput) return;
    
    // Search as user types (with debounce)
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        // Show/hide clear button
        clearBtn.style.display = query ? 'flex' : 'none';
        
        searchTimeout = setTimeout(() => {
            searchCompetencies(query);
        }, 300); // 300ms debounce
    });
    
    // Clear search
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        resultsCount.style.display = 'none';
        searchCompetencies(''); // Reset to show all
    });
    
    // Search on Enter key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchCompetencies(this.value.trim());
        }
    });
}

// Search competencies by name, ID, or description
function searchCompetencies(query) {
    const resultsCount = document.getElementById('searchResultsCount');
    
    if (!query) {
        // Show all competencies
        renderCompetencies(window.allCompetencies);
        resultsCount.style.display = 'none';
        return;
    }
    
    query = query.toLowerCase();
    
    // Recursively search competencies and their children
    function searchInCompetency(comp) {
        const matchesName = comp.shortname && comp.shortname.toLowerCase().includes(query);
        const matchesId = comp.idnumber && comp.idnumber.toLowerCase().includes(query);
        const matchesDesc = comp.description && stripHtml(comp.description).toLowerCase().includes(query);
        
        const matches = matchesName || matchesId || matchesDesc;
        
        let filteredChildren = [];
        if (comp.children && comp.children.length > 0) {
            filteredChildren = comp.children
                .map(child => searchInCompetency(child))
                .filter(child => child !== null);
        }
        
        // Include parent if it matches OR if any children match
        if (matches || filteredChildren.length > 0) {
            return {
                ...comp,
                children: filteredChildren
            };
        }
        
        return null;
    }
    
    // Filter all competencies
    const filteredCompetencies = window.allCompetencies
        .map(comp => searchInCompetency(comp))
        .filter(comp => comp !== null);
    
    // Count total matches (including children)
    function countMatches(comps) {
        let count = 0;
        comps.forEach(comp => {
            count++;
            if (comp.children && comp.children.length > 0) {
                count += countMatches(comp.children);
            }
        });
        return count;
    }
    
    const totalMatches = countMatches(filteredCompetencies);
    
    // Update UI
    if (totalMatches > 0) {
        renderCompetencies(filteredCompetencies);
        resultsCount.textContent = `Found ${totalMatches} competenc${totalMatches === 1 ? 'y' : 'ies'}`;
        resultsCount.className = 'search-results-count has-results';
        resultsCount.style.display = 'block';
    } else {
        document.getElementById('competenciesList').innerHTML = '<div class="info-box"><i class="fa fa-search"></i><span>No competencies found matching "' + query + '"</span></div>';
        resultsCount.textContent = 'No competencies found';
        resultsCount.className = 'search-results-count no-results';
        resultsCount.style.display = 'block';
    }
}

// Strip HTML tags from string
function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

// Show notification function
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fa fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Add styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
        color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
        border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
        border-radius: 8px;
        padding: 12px 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;
    
    // Add animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .notification-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    `;
    document.head.appendChild(style);
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Toggle group selection visibility
function toggleGroupSelection() {
    const groupsRadio = document.getElementById('assignToGroups');
    const groupSelectionContainer = document.getElementById('groupSelectionContainer');
    
    if (groupsRadio && groupsRadio.checked) {
        groupSelectionContainer.style.display = 'block';
        // Load groups when container becomes visible
        const courseSelect = document.getElementById('codeeditorCourse');
        if (courseSelect.value) {
            loadCourseGroups(courseSelect.value);
            loadCourseStudents(courseSelect.value);
        }
    } else {
        groupSelectionContainer.style.display = 'none';
    }
}

// Toggle create group dropdown
// Open create group modal
function openCreateGroupModal() {
    const courseSelect = document.getElementById('codeeditorCourse');
    if (!courseSelect.value) {
        alert('Please select a course first.');
        return;
    }
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('createGroupModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'createGroupModal';
        modal.className = 'create-group-modal';
        modal.innerHTML = `
            <div class="create-group-modal-content">
                <div class="create-group-modal-header">
                    <h3 class="create-group-modal-title">
                        <i class="fa fa-users"></i>
                        Create New Group
                    </h3>
                    <button class="create-group-modal-close" onclick="closeCreateGroupModal()">×</button>
                </div>
                <div class="create-group-modal-body">
                    <div class="create-group-form">
                        <div class="form-row">
                            <div class="form-col">
                                <label class="form-label">Group Name <span class="required">*</span></label>
                                <input type="text" id="modalGroupName" class="form-input" placeholder="e.g., Advanced Learners" maxlength="100">
                            </div>
                            <div class="form-col">
                                <label class="form-label">Description (Optional)</label>
                                <input type="text" id="modalGroupDescription" class="form-input" placeholder="Brief description..." maxlength="255">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-col-full">
                                <label class="form-label">Select Students</label>
                                <div class="student-selector">
                                    <div class="student-search">
                                        <i class="fa fa-search"></i>
                                        <input type="text" id="modalStudentSearchInput" class="search-input" placeholder="Search by name or email..." onkeyup="filterModalStudents()">
                                    </div>
                                    <div class="loading" id="modalStudentsLoading" style="display: none;">
                                        <i class="fa fa-spinner fa-spin"></i> Loading students...
                                    </div>
                                    <div id="modalStudentsList" class="students-list">
                                        <div class="info-message">
                                            <i class="fa fa-info-circle"></i>
                                            Loading students...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="create-group-modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeCreateGroupModal()">Cancel</button>
                    <button type="button" id="modalCreateGroupBtn" class="btn-create" onclick="createNewGroupFromModal()">
                        <i class="fa fa-check"></i> Create Group
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateGroupModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeCreateGroupModal();
            }
        });
    }
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Load students
    loadCourseStudentsForModal(courseSelect.value);
}

// Close create group modal
function closeCreateGroupModal() {
    const modal = document.getElementById('createGroupModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Clear form
        document.getElementById('modalGroupName').value = '';
        document.getElementById('modalGroupDescription').value = '';
        document.getElementById('modalStudentSearchInput').value = '';
        
        // Clear selections
        document.querySelectorAll('#modalStudentsList .student-checkbox:checked').forEach(cb => {
            cb.checked = false;
            cb.closest('.student-item').classList.remove('selected');
        });
    }
}

// Load course students for modal
function loadCourseStudentsForModal(courseid) {
    const loadingDiv = document.getElementById('modalStudentsLoading');
    const listDiv = document.getElementById('modalStudentsList');
    
    if (!courseid) {
        listDiv.innerHTML = '<div class="info-message"><i class="fa fa-info-circle"></i>Select a course first to see students</div>';
        return;
    }
    
    loadingDiv.style.display = 'block';
    listDiv.innerHTML = '';
    
    fetch('get_course_students.php?courseid=' + courseid)
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';
            
            if (data.success && data.students && data.students.length > 0) {
                renderModalStudents(data.students);
            } else {
                listDiv.innerHTML = '<div class="info-message"><i class="fa fa-info-circle"></i>No students found in this course</div>';
            }
        })
        .catch(error => {
            console.error('Error loading students:', error);
            loadingDiv.style.display = 'none';
            listDiv.innerHTML = '<div class="info-message" style="color: #dc3545;"><i class="fa fa-exclamation-circle"></i>Error loading students</div>';
        });
}

// Render students in modal
function renderModalStudents(students) {
    const listDiv = document.getElementById('modalStudentsList');
    listDiv.innerHTML = '';
    
    students.forEach(student => {
        const studentItem = document.createElement('div');
        studentItem.className = 'student-item';
        studentItem.dataset.studentName = student.fullname.toLowerCase();
        studentItem.dataset.studentEmail = student.email.toLowerCase();
        studentItem.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.student-checkbox');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
            } else {
                this.classList.toggle('selected', e.target.checked);
            }
        };
        
        studentItem.innerHTML = `
            <input type="checkbox" class="student-checkbox" value="${student.id}" onclick="event.stopPropagation();">
            <div class="student-details">
                <div class="student-name">${student.fullname}</div>
                <div class="student-email">${student.email}</div>
            </div>
        `;
        
        listDiv.appendChild(studentItem);
    });
}

// Filter students in modal
function filterModalStudents() {
    const searchInput = document.getElementById('modalStudentSearchInput');
    const query = searchInput.value.toLowerCase().trim();
    const studentItems = document.querySelectorAll('#modalStudentsList .student-item');
    
    studentItems.forEach(item => {
        const name = item.dataset.studentName || '';
        const email = item.dataset.studentEmail || '';
        
        if (name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Create group from modal
function createNewGroupFromModal() {
    const courseSelect = document.getElementById('codeeditorCourse');
    const groupNameInput = document.getElementById('modalGroupName');
    const groupDescInput = document.getElementById('modalGroupDescription');
    const createBtn = document.getElementById('modalCreateGroupBtn');
    
    const groupName = groupNameInput.value.trim();
    if (!groupName) {
        alert('Please enter a group name.');
        groupNameInput.focus();
        return;
    }
    
    if (groupName.length < 2) {
        alert('Group name must be at least 2 characters long.');
        groupNameInput.focus();
        return;
    }
    
    createBtn.disabled = true;
    createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    
    const selectedCheckboxes = document.querySelectorAll('#modalStudentsList .student-checkbox:checked');
    const selectedStudentIds = Array.from(selectedCheckboxes).map(cb => cb.value);
    
    const formData = new FormData();
    formData.append('courseid', courseSelect.value);
    formData.append('name', groupName);
    formData.append('description', groupDescInput.value.trim());
    formData.append('sesskey', document.querySelector('input[name="sesskey"]').value);
    
    selectedStudentIds.forEach(studentId => {
        formData.append('student_ids[]', studentId);
    });
    
    fetch('create_group.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Group "' + groupName + '" created successfully with ' + (data.member_count || 0) + ' student(s)!', 'success');
            closeCreateGroupModal();
            loadCourseGroups(courseSelect.value);
            
            setTimeout(() => {
                const newGroupCheckbox = document.querySelector(`input[value="${data.group_id}"]`);
                if (newGroupCheckbox) {
                    newGroupCheckbox.checked = true;
                }
            }, 500);
        } else {
            showNotification('Error creating group: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error creating group. Please try again.', 'error');
    })
    .finally(() => {
        createBtn.disabled = false;
        createBtn.innerHTML = '<i class="fa fa-check"></i> Create Group';
    });
}

// Load course students for group creation
function loadCourseStudents(courseid) {
    console.log('loadCourseStudents called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('studentsLoading');
    const listDiv = document.getElementById('studentsList');
    
    if (!courseid) {
        listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px;"><i class="fa fa-info-circle"></i><span>Select a course first to see students</span></div>';
        return;
    }
    
    // Show loading
    loadingDiv.style.display = 'block';
    listDiv.innerHTML = '';
    
    // Fetch students
    fetch('get_course_students.php?courseid=' + courseid)
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';
            
            if (data.success && data.students && data.students.length > 0) {
                renderStudents(data.students);
            } else {
                listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px;"><i class="fa fa-info-circle"></i><span>No students found in this course</span></div>';
            }
        })
        .catch(error => {
            console.error('Error loading students:', error);
            loadingDiv.style.display = 'none';
            listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px; color: #dc3545;"><i class="fa fa-exclamation-circle"></i><span>Error loading students</span></div>';
        });
}

// Render students list
function renderStudents(students) {
    const listDiv = document.getElementById('studentsList');
    listDiv.innerHTML = '';
    
    // Store students globally for filtering
    window.courseStudents = students;
    
    students.forEach(student => {
        const studentItem = document.createElement('div');
        studentItem.className = 'student-item';
        studentItem.dataset.studentId = student.id;
        studentItem.dataset.studentName = student.fullname.toLowerCase();
        studentItem.dataset.studentEmail = student.email.toLowerCase();
        studentItem.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.student-checkbox');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
            } else {
                this.classList.toggle('selected', e.target.checked);
            }
        };
        
        studentItem.innerHTML = `
            <input type="checkbox" class="student-checkbox" value="${student.id}" onclick="event.stopPropagation();">
            <div class="student-details">
                <div class="student-name">${student.fullname}</div>
                <div class="student-email">${student.email}</div>
            </div>
        `;
        
        listDiv.appendChild(studentItem);
    });
}

// Filter students based on search
function filterStudents() {
    const searchInput = document.getElementById('studentSearchInput');
    const query = searchInput.value.toLowerCase().trim();
    const studentItems = document.querySelectorAll('.student-item');
    
    let visibleCount = 0;
    
    studentItems.forEach(item => {
        const name = item.dataset.studentName || '';
        const email = item.dataset.studentEmail || '';
        
        if (name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show no results message if needed
    if (visibleCount === 0 && studentItems.length > 0 && query) {
        const listDiv = document.getElementById('studentsList');
        const existingMsg = listDiv.querySelector('.no-results-msg');
        if (!existingMsg) {
            const noResults = document.createElement('div');
            noResults.className = 'info-box no-results-msg';
            noResults.style.cssText = 'text-align: center; padding: 12px;';
            noResults.innerHTML = '<i class="fa fa-search"></i><span>No students found matching "' + query + '"</span>';
            listDiv.appendChild(noResults);
        }
    } else {
        const existingMsg = document.querySelector('.no-results-msg');
        if (existingMsg) {
            existingMsg.remove();
        }
    }
}

// Create new group function
function createNewGroup() {
    const courseSelect = document.getElementById('codeeditorCourse');
    const groupNameInput = document.getElementById('newGroupName');
    const groupDescInput = document.getElementById('newGroupDescription');
    const createBtn = document.getElementById('createGroupBtn');
    
    // Validation
    if (!courseSelect.value) {
        alert('Please select a course first.');
        return;
    }
    
    const groupName = groupNameInput.value.trim();
    if (!groupName) {
        alert('Please enter a group name.');
        groupNameInput.focus();
        return;
    }
    
    if (groupName.length < 2) {
        alert('Group name must be at least 2 characters long.');
        groupNameInput.focus();
        return;
    }
    
    // Disable button and show loading
    createBtn.disabled = true;
    createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    
    // Get selected students
    const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
    const selectedStudentIds = Array.from(selectedCheckboxes).map(cb => cb.value);
    
    // Prepare data
    const formData = new FormData();
    formData.append('courseid', courseSelect.value);
    formData.append('name', groupName);
    formData.append('description', groupDescInput.value.trim());
    formData.append('sesskey', document.querySelector('input[name="sesskey"]').value);
    
    // Add student IDs
    selectedStudentIds.forEach(studentId => {
        formData.append('student_ids[]', studentId);
    });
    
    // Send request
    fetch('create_group.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear form
            groupNameInput.value = '';
            groupDescInput.value = '';
            
            // Clear student selections
            document.querySelectorAll('.student-checkbox:checked').forEach(cb => {
                cb.checked = false;
                cb.closest('.student-item').classList.remove('selected');
            });
            
            // Clear search
            document.getElementById('studentSearchInput').value = '';
            
            // Show success message
            const studentCount = data.member_count || 0;
            showNotification('Group "' + groupName + '" created successfully with ' + studentCount + ' student(s)!', 'success');
            
            // Reload groups list
            loadCourseGroups(courseSelect.value);
            
            // Auto-select the new group
            setTimeout(() => {
                const newGroupCheckbox = document.querySelector(`input[value="${data.group_id}"]`);
                if (newGroupCheckbox) {
                    newGroupCheckbox.checked = true;
                }
            }, 500);
            
        } else {
            showNotification('Error creating group: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error creating group. Please try again.', 'error');
    })
    .finally(() => {
        // Re-enable button
        createBtn.disabled = false;
        createBtn.innerHTML = '<i class="fa fa-plus"></i> Create Group';
    });
}

// Load course groups
function loadCourseGroups(courseid) {
    console.log('loadCourseGroups called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('groupsLoading');
    const listDiv = document.getElementById('groupsList');
    const noGroupsDiv = document.getElementById('noGroups');
    
    if (!courseid) {
        loadingDiv.style.display = 'none';
        listDiv.style.display = 'none';
        noGroupsDiv.style.display = 'none';
        return;
    }
    
    // Show loading
    loadingDiv.style.display = 'flex';
    listDiv.style.display = 'none';
    noGroupsDiv.style.display = 'none';
    listDiv.innerHTML = '';
    
    const apiUrl = `get_course_groups.php?courseid=${courseid}`;
    console.log('Fetching groups from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => {
            console.log('Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Groups data:', data);
            loadingDiv.style.display = 'none';
            
            if (data.success && data.groups && data.groups.length > 0) {
                console.log('Rendering', data.groups.length, 'groups');
                renderGroups(data.groups);
                listDiv.style.display = 'flex';
            } else {
                console.log('No groups found');
                noGroupsDiv.style.display = 'flex';
            }
        })
        .catch(error => {
            console.error('Error loading groups:', error);
            loadingDiv.style.display = 'none';
            noGroupsDiv.style.display = 'flex';
        });
}

// Render groups list
function renderGroups(groups) {
    const listDiv = document.getElementById('groupsList');
    listDiv.innerHTML = '';
    
    groups.forEach(group => {
        const item = document.createElement('div');
        item.className = 'group-item';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'group_ids[]';
        checkbox.value = group.id;
        checkbox.className = 'group-checkbox';
        checkbox.id = `group_${group.id}`;
        
        // Toggle item selection
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });
        
        const details = document.createElement('div');
        details.className = 'group-details';
        
        const nameLabel = document.createElement('label');
        nameLabel.className = 'group-name';
        nameLabel.htmlFor = `group_${group.id}`;
        nameLabel.textContent = group.name;
        nameLabel.style.cursor = 'pointer';
        
        // Allow clicking on label to toggle checkbox
        nameLabel.addEventListener('click', function() {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change'));
        });
        
        details.appendChild(nameLabel);
        
        // Description
        if (group.description) {
            const description = document.createElement('div');
            description.className = 'group-description';
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = group.description;
            const descText = tempDiv.textContent || tempDiv.innerText || '';
            description.textContent = descText.length > 100 ? descText.substring(0, 100) + '...' : descText;
            details.appendChild(description);
        }
        
        // Member count badge - clickable to open modal
        const memberCount = document.createElement('span');
        memberCount.className = 'group-member-count';
        memberCount.innerHTML = `<i class="fa fa-users"></i> ${group.membercount} member${group.membercount !== 1 ? 's' : ''}`;
        memberCount.style.cursor = 'pointer';
        memberCount.title = 'Click to view members';
        memberCount.dataset.groupId = group.id;
        memberCount.dataset.groupName = group.name;
        memberCount.dataset.memberCount = group.membercount;
        
        // Click on member count to open modal
        memberCount.addEventListener('click', function(e) {
            e.stopPropagation();
            openMembersModal(group.id, group.name, group.membercount);
        });
        
        details.appendChild(memberCount);
        
        item.appendChild(checkbox);
        item.appendChild(details);
        listDiv.appendChild(item);
    });
    
}

// Open members modal
function openMembersModal(groupId, groupName, memberCount) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('membersModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'membersModal';
        modal.className = 'members-modal';
        modal.innerHTML = `
            <div class="members-modal-content">
                <div class="members-modal-header">
                    <h3 class="members-modal-title">
                        <i class="fa fa-users"></i>
                        <span id="modalGroupName">${groupName}</span>
                        <span class="members-count-badge" id="modalMemberCount">${memberCount}</span>
                    </h3>
                    <button class="members-modal-close" onclick="closeMembersModal()">×</button>
                </div>
                <div class="members-modal-search">
                    <div class="members-search-wrapper">
                        <i class="fa fa-search"></i>
                        <input type="text" class="members-search-input" id="memberSearchInput" placeholder="Search members by name or email...">
                    </div>
                </div>
                <div class="members-list-content" id="modalMembersList">
                    <div class="loading-members"><i class="fa fa-spinner fa-spin"></i><br>Loading members...</div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeMembersModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeMembersModal();
            }
        });
        
        // Search functionality
        document.getElementById('memberSearchInput').addEventListener('input', function() {
            filterMembers(this.value);
        });
    } else {
        // Update modal title if reusing
        document.getElementById('modalGroupName').textContent = groupName;
        document.getElementById('modalMemberCount').textContent = memberCount;
        document.getElementById('memberSearchInput').value = '';
    }
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    
    // Load members
    loadGroupMembers(groupId);
}

// Close members modal
function closeMembersModal() {
    const modal = document.getElementById('membersModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    }
}

// Load group members into modal
function loadGroupMembers(groupId) {
    const listContainer = document.getElementById('modalMembersList');
    listContainer.innerHTML = '<div class="loading-members"><i class="fa fa-spinner fa-spin"></i><br>Loading members...</div>';
    
    fetch(`get_group_members.php?groupid=${groupId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.members && data.members.length > 0) {
                window.currentMembers = data.members; // Store for search
                renderMembers(data.members);
            } else {
                listContainer.innerHTML = `
                    <div class="members-list-empty">
                        <i class="fa fa-users"></i><br>
                        <strong>No members in this group</strong>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading members:', error);
            listContainer.innerHTML = `
                <div class="error-members">
                    <i class="fa fa-exclamation-circle"></i><br>
                    Error loading members. Please try again.
                </div>
            `;
        });
}

// Render members list
function renderMembers(members) {
    const listContainer = document.getElementById('modalMembersList');
    let html = '';
    
    members.forEach(member => {
        const initials = member.fullname.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        html += `
            <div class="member-item" data-name="${member.fullname.toLowerCase()}" data-email="${member.email.toLowerCase()}">
                <div class="member-avatar">${initials}</div>
                <div class="member-info">
                    <div class="member-name">${member.fullname}</div>
                    <div class="member-email">${member.email}</div>
                </div>
                <div class="member-added">Added: ${member.added}</div>
            </div>
        `;
    });
    
    listContainer.innerHTML = html || '<div class="no-members">No members found</div>';
}

// Filter members by search query
function filterMembers(query) {
    query = query.toLowerCase().trim();
    const memberItems = document.querySelectorAll('#modalMembersList .member-item');
    let visibleCount = 0;
    
    memberItems.forEach(item => {
        const name = item.dataset.name || '';
        const email = item.dataset.email || '';
        
        if (query === '' || name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show "no results" if all filtered out
    if (visibleCount === 0 && query !== '') {
        const listContainer = document.getElementById('modalMembersList');
        if (!listContainer.querySelector('.no-members')) {
            listContainer.innerHTML += '<div class="no-members">No members found matching your search</div>';
        }
    } else {
        // Remove "no results" message if exists
        const noResults = document.querySelector('#modalMembersList .no-members');
        if (noResults) {
            noResults.remove();
        }
    }
}

// ============================================
// RUBRIC BUILDER FUNCTIONALITY - TABLE FORMAT
// ============================================

// Global rubric counter and data structure
let criterionCounter = 0;
let rubricData = []; // Array of {id, description, levels: [{score, definition}]}

// Toggle rubric builder visibility
function toggleRubricBuilder() {
    const gradingMethod = document.getElementById('gradingMethod').value;
    const rubricBuilder = document.getElementById('rubricBuilder');
    const maxGradeInput = document.getElementById('maxGrade');
    
    if (gradingMethod === 'rubric') {
        rubricBuilder.style.display = 'block';
        maxGradeInput.readOnly = true;
        // If no criteria exist, add only one default criterion
        if (rubricData.length === 0) {
            addCriterion();
        }
    } else {
        rubricBuilder.style.display = 'none';
        maxGradeInput.readOnly = false;
    }
}

// Render the entire rubric table
function renderRubricTable() {
    const container = document.getElementById('rubricCriteria');
    
    if (rubricData.length === 0) {
        container.innerHTML = `
            <div class="rubric-empty-state">
                <i class="fa fa-table"></i>
                <p>No criteria added yet. Click "Add Criterion" to get started.</p>
            </div>
        `;
        updateRubricSummary();
        return;
    }
    
    // Find max number of levels across all criteria
    const maxLevels = Math.max(...rubricData.map(c => c.levels.length));
    
    let html = '<table class="rubric-table"><thead><tr>';
    html += '<th style="width: 60px;"></th>'; // Controls column
    html += '<th style="width: 250px;">Criterion</th>'; // Criterion column
    
    // Level columns
    for (let i = 0; i < maxLevels; i++) {
        html += `<th>Level ${i + 1}</th>`;
    }
    html += '<th style="width: 120px;"></th>'; // Add level column
    html += '</tr></thead><tbody>';
    
    // Render each criterion row
    rubricData.forEach((criterion, criterionIndex) => {
        html += `<tr data-criterion-id="${criterion.id}">`;
        
        // Controls cell
        html += '<td class="criterion-controls-cell"><div class="criterion-controls">';
        if (criterionIndex > 0) {
            html += `<button type="button" class="btn-criterion-control" onclick="moveCriterion(${criterion.id}, 'up')" title="Move Up"><i class="fa fa-arrow-up"></i></button>`;
        }
        html += `<button type="button" class="btn-criterion-control delete" onclick="deleteCriterion(${criterion.id})" title="Delete"><i class="fa fa-trash"></i></button>`;
        if (criterionIndex < rubricData.length - 1) {
            html += `<button type="button" class="btn-criterion-control" onclick="moveCriterion(${criterion.id}, 'down')" title="Move Down"><i class="fa fa-arrow-down"></i></button>`;
        }
        html += `<button type="button" class="btn-criterion-control" onclick="duplicateCriterion(${criterion.id})" title="Duplicate"><i class="fa fa-copy"></i></button>`;
        html += '</div></td>';
        
        // Criterion description cell
        html += '<td class="criterion-description-cell">';
        html += `<textarea class="criterion-description-input" name="criterion_description_${criterion.id}" `;
        html += `placeholder="Click to edit criterion" onchange="updateCriterionDescription(${criterion.id}, this.value)">${criterion.description}</textarea>`;
        html += '</td>';
        
        // Level cells
        criterion.levels.forEach((level, levelIndex) => {
            html += '<td class="level-cell"><div class="level-content">';
            // First: Level definition textarea (top)
            html += `<textarea class="level-definition-input" name="criterion_${criterion.id}_level_definition[]" `;
            html += `placeholder="Click to edit level" onchange="updateLevelDefinition(${criterion.id}, ${levelIndex}, this.value)">${level.definition}</textarea>`;
            // Second: Score input with points label (bottom)
            html += '<div class="level-score-row">';
            html += `<input type="number" class="level-score-input" name="criterion_${criterion.id}_level_score[]" `;
            html += `value="${level.score}" min="0" step="0.01" required `;
            html += `onchange="updateLevelScore(${criterion.id}, ${levelIndex}, this.value)">`;
            html += `<span class="level-points-label">${level.score} points</span>`;
            if (levelIndex > 0) {
                html += `<button type="button" class="btn-delete-level" onclick="deleteLevel(${criterion.id}, ${levelIndex})" title="Delete Level"><i class="fa fa-trash"></i></button>`;
            }
            html += '</div>';
            html += '</div></td>';
        });
        
        // Fill empty cells if this criterion has fewer levels than max
        for (let i = criterion.levels.length; i < maxLevels; i++) {
            html += '<td></td>';
        }
        
        // Add level button cell
        html += '<td class="add-level-cell">';
        html += `<button type="button" class="btn-add-level" onclick="addLevel(${criterion.id})"><i class="fa fa-plus"></i> Add level</button>`;
        html += '</td>';
        
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
    
    updateRubricSummary();
}

// Add a new criterion
function addCriterion() {
    criterionCounter++;
    const newCriterion = {
        id: criterionCounter,
        description: '',
        levels: [
            { score: 1, definition: '' },
            { score: 2, definition: '' },
            { score: 3, definition: '' }
        ]
    };
    rubricData.push(newCriterion);
    renderRubricTable();
}

// Add a level to a criterion
function addLevel(criterionId) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (!criterion) return;
    
    const nextScore = criterion.levels.length > 0 ? 
        Math.max(...criterion.levels.map(l => l.score)) + 1 : 1;
    
    criterion.levels.push({ score: nextScore, definition: '' });
    renderRubricTable();
}

// Delete a level from a criterion
function deleteLevel(criterionId, levelIndex) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (!criterion) return;
    
    if (criterion.levels.length <= 1) {
        alert('Each criterion must have at least one level.');
        return;
    }
    
    criterion.levels.splice(levelIndex, 1);
    renderRubricTable();
}

// Delete a criterion
function deleteCriterion(criterionId) {
    if (!confirm('Are you sure you want to delete this criterion?')) {
        return;
    }
    
    const index = rubricData.findIndex(c => c.id === criterionId);
    if (index > -1) {
        rubricData.splice(index, 1);
    }
    
    renderRubricTable();
}

// Move criterion up or down
function moveCriterion(criterionId, direction) {
    const index = rubricData.findIndex(c => c.id === criterionId);
    if (index === -1) return;
    
    if (direction === 'up' && index > 0) {
        [rubricData[index], rubricData[index - 1]] = [rubricData[index - 1], rubricData[index]];
    } else if (direction === 'down' && index < rubricData.length - 1) {
        [rubricData[index], rubricData[index + 1]] = [rubricData[index + 1], rubricData[index]];
    }
    
    renderRubricTable();
}

// Duplicate a criterion
function duplicateCriterion(criterionId) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (!criterion) return;
    
    criterionCounter++;
    const newCriterion = {
        id: criterionCounter,
        description: criterion.description,
        levels: criterion.levels.map(l => ({ ...l })) // Deep copy levels
    };
    
    // Insert after the original
    const index = rubricData.findIndex(c => c.id === criterionId);
    rubricData.splice(index + 1, 0, newCriterion);
    
    renderRubricTable();
}

// Update criterion description
function updateCriterionDescription(criterionId, value) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (criterion) {
        criterion.description = value;
        updateRubricSummary();
    }
}

// Update level score
function updateLevelScore(criterionId, levelIndex, value) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (criterion && criterion.levels[levelIndex]) {
        criterion.levels[levelIndex].score = parseFloat(value) || 0;
        renderRubricTable(); // Re-render to update points label
    }
}

// Update level definition
function updateLevelDefinition(criterionId, levelIndex, value) {
    const criterion = rubricData.find(c => c.id === criterionId);
    if (criterion && criterion.levels[levelIndex]) {
        criterion.levels[levelIndex].definition = value;
    }
}

// Update rubric summary
function updateRubricSummary() {
    const criteriaCount = rubricData.length;
    
    let maxScore = 0;
    rubricData.forEach(criterion => {
        if (criterion.levels.length > 0) {
            const maxLevelScore = Math.max(...criterion.levels.map(l => l.score));
            maxScore += maxLevelScore;
        }
    });
    
    // Update display
    document.getElementById('criteriaCount').textContent = criteriaCount;
    document.getElementById('maxScore').textContent = maxScore.toFixed(2);
    
    // Update max grade input
    document.getElementById('maxGrade').value = maxScore.toFixed(2);
    
    // Show/hide summary
    const summary = document.getElementById('rubricSummary');
    summary.style.display = criteriaCount > 0 ? 'flex' : 'none';
}

// Validate rubric before submission
function validateRubric() {
    const gradingMethod = document.getElementById('gradingMethod').value;
    if (gradingMethod !== 'rubric') {
        return true; // Not using rubric, skip validation
    }
    
    if (rubricData.length === 0) {
        alert('Please add at least one criterion to your rubric.');
        return false;
    }
    
    // Check each criterion has description and levels
    for (let criterion of rubricData) {
        if (!criterion.description.trim()) {
            alert('Please provide a description for all criteria.');
            return false;
        }
        
        if (criterion.levels.length === 0) {
            alert('Each criterion must have at least one level.');
            return false;
        }
    }
    
    return true;
}

// Update createCodeEditor to validate rubric
const originalCreateCodeEditor = createCodeEditor;
createCodeEditor = function() {
    // Validate rubric first
    if (!validateRubric()) {
        return;
    }
    
    // Continue with original function
    originalCreateCodeEditor();
};
</script>

<?php
echo $OUTPUT->footer();
?>
