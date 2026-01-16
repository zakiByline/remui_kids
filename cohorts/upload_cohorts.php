<?php
/**
 * Upload Cohorts Page - Custom implementation for bulk CSV upload
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->libdir . '/csvlib.class.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Set up the page
$contextid = optional_param('contextid', context_system::instance()->id, PARAM_INT);
$context = context::instance_by_id($contextid);

$PAGE->set_url('/theme/remui_kids/cohorts/upload_cohorts.php', array('contextid' => $contextid));
$PAGE->set_context($context);
$PAGE->set_title('Upload Cohorts');
$PAGE->set_heading('Upload Cohorts');
$PAGE->set_pagelayout('admin');

// Check if user has permission to manage cohorts
require_capability('moodle/cohort:manage', $context);
require_login();

$errors = array();
$success_messages = array();
$preview_data = array();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    
    if ($action === 'upload' && isset($_FILES['csvfile'])) {
        $csvfile = $_FILES['csvfile'];
        
        if ($csvfile['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($csvfile['tmp_name']);
            $iid = csv_import_reader::get_new_iid('uploadcohort');
            $cir = new csv_import_reader($iid, 'uploadcohort');
            
            $readcount = $cir->load_csv_content($content, 'utf-8', 'comma');
            
            if ($readcount === false) {
                $errors[] = 'Error reading CSV file';
            } else {
                $cir->init();
                $columns = $cir->get_columns();
                
                // Validate columns
                $required_columns = array('name', 'idnumber');
                $missing = array_diff($required_columns, $columns);
                
                if (!empty($missing)) {
                    $errors[] = 'Missing required columns: ' . implode(', ', $missing);
                } else {
                    // Preview mode - show what will be imported
                    $linenum = 1;
                    while ($line = $cir->next()) {
                        $linenum++;
                        $data = array_combine($columns, $line);
                        
                        // Validate data
                        $row_errors = array();
                        
                        if (empty(trim($data['name']))) {
                            $row_errors[] = 'Name is required';
                        }
                        
                        if (empty(trim($data['idnumber']))) {
                            $row_errors[] = 'ID number is required';
                        } else {
                            // Check if idnumber already exists
                            $existing = $DB->get_record('cohort', array('idnumber' => $data['idnumber']));
                            if ($existing) {
                                $row_errors[] = 'ID number already exists';
                            }
                        }
                        
                        $preview_data[] = array(
                            'line' => $linenum,
                            'name' => $data['name'],
                            'idnumber' => $data['idnumber'],
                            'description' => isset($data['description']) ? $data['description'] : '',
                            'visible' => isset($data['visible']) ? $data['visible'] : '1',
                            'errors' => $row_errors
                        );
                    }
                    
                    $cir->close();
                }
            }
        } else {
            $errors[] = 'Error uploading file';
        }
    } else if ($action === 'confirm') {
        // Process the upload
        $upload_data = optional_param('upload_data', '', PARAM_RAW);
        $rows = json_decode($upload_data, true);
        
        if (!empty($rows)) {
            $created = 0;
            $failed = 0;
            
            foreach ($rows as $row) {
                if (!empty($row['errors'])) {
                    $failed++;
                    continue;
                }
                
                try {
                    $cohort = new stdClass();
                    $cohort->contextid = $contextid;
                    $cohort->name = $row['name'];
                    $cohort->idnumber = $row['idnumber'];
                    $cohort->description = $row['description'];
                    $cohort->descriptionformat = FORMAT_HTML;
                    $cohort->visible = $row['visible'];
                    $cohort->component = '';
                    $cohort->timecreated = time();
                    $cohort->timemodified = time();
                    
                    cohort_add_cohort($cohort);
                    $created++;
                } catch (Exception $e) {
                    $failed++;
                }
            }
            
            redirect(
                new moodle_url('/theme/remui_kids/cohorts/index.php'),
                $created . ' cohort(s) created successfully' . ($failed > 0 ? ', ' . $failed . ' failed' : ''),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
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
    
    /* Upload Container */
    .upload-container {
        max-width: 1000px;
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .upload-header {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
    }
    
    .upload-title {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 10px 0;
    }
    
    .upload-subtitle {
        font-size: 14px;
        color: #7f8c8d;
        margin: 0;
    }
    
    .upload-info {
        background: #e8f4fd;
        border-left: 4px solid #3498db;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
    }
    
    .upload-info h3 {
        margin: 0 0 10px 0;
        color: #2c3e50;
        font-size: 16px;
    }
    
    .upload-info ul {
        margin: 10px 0 10px 20px;
        color: #555;
    }
    
    .upload-info code {
        background: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-family: monospace;
        color: #e74c3c;
    }
    
    .file-upload-area {
        border: 2px dashed #e0e0e0;
        border-radius: 12px;
        padding: 40px;
        text-align: center;
        background: #f8f9fa;
        transition: all 0.3s ease;
        margin-bottom: 30px;
    }
    
    .file-upload-area:hover {
        border-color: #667eea;
        background: #f0f4ff;
    }
    
    .file-upload-icon {
        font-size: 48px;
        color: #667eea;
        margin-bottom: 15px;
    }
    
    .file-input {
        display: none;
    }
    
    .file-label {
        display: inline-block;
        padding: 12px 30px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .file-label:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    .preview-table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    
    .preview-table thead tr {
        background: #f8f9fa;
    }
    
    .preview-table th {
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        font-size: 12px;
        text-transform: uppercase;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .preview-table td {
        padding: 12px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
    }
    
    .preview-table tr.error-row {
        background: #fff5f5;
    }
    
    .preview-table tr.success-row {
        background: #f0fff4;
    }
    
    .error-text {
        color: #e74c3c;
        font-size: 12px;
    }
    
    .success-badge {
        background: #27ae60;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
    }
    
    .error-badge {
        background: #e74c3c;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
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
    
    .alert-success {
        background: #eeffee;
        border-left: 4px solid #27ae60;
        color: #1e7e34;
    }
    
    .alert ul {
        margin: 10px 0 0 20px;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');

// Main content area
echo "<div class='admin-main-content'>";

// Display errors if any
if (!empty($errors)) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>⚠️ Please correct the following errors:</strong>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "<div class='upload-container' style='max-width: 800px; margin: 0 auto; padding: 40px 20px;'>";

echo "<div class='upload-header'>";
echo "<h1 class='upload-title'>Upload Cohorts from CSV</h1>";
echo "<p class='upload-subtitle'>Bulk import cohorts using a CSV file</p>";
echo "</div>";

// Show preview if data exists
if (!empty($preview_data)) {
    $valid_count = 0;
    $error_count = 0;
    
    foreach ($preview_data as $row) {
        if (empty($row['errors'])) {
            $valid_count++;
        } else {
            $error_count++;
        }
    }
    
    echo "<div class='alert " . ($error_count > 0 ? 'alert-danger' : 'alert-success') . "'>";
    echo "<strong>📊 Preview Results:</strong><br>";
    echo "Total rows: " . count($preview_data) . " | Valid: {$valid_count} | Errors: {$error_count}";
    echo "</div>";
    
    echo "<h3>Preview Data</h3>";
    echo "<table class='preview-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>Line</th>";
    echo "<th>Name</th>";
    echo "<th>ID Number</th>";
    echo "<th>Description</th>";
    echo "<th>Visible</th>";
    echo "<th>Status</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($preview_data as $row) {
        $row_class = empty($row['errors']) ? 'success-row' : 'error-row';
        echo "<tr class='{$row_class}'>";
        echo "<td>{$row['line']}</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['idnumber']) . "</td>";
        echo "<td>" . htmlspecialchars($row['description']) . "</td>";
        echo "<td>" . ($row['visible'] ? 'Yes' : 'No') . "</td>";
        echo "<td>";
        if (empty($row['errors'])) {
            echo "<span class='success-badge'>✓ Valid</span>";
        } else {
            echo "<span class='error-badge'>✗ Error</span><br>";
            echo "<span class='error-text'>" . implode(', ', $row['errors']) . "</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    // Confirm upload form
    if ($valid_count > 0) {
        echo "<form method='POST' action=''>";
        echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
        echo "<input type='hidden' name='action' value='confirm'>";
        echo "<input type='hidden' name='upload_data' value='" . htmlspecialchars(json_encode($preview_data)) . "'>";
        
        echo "<div class='form-actions'>";
        echo "<button type='submit' class='btn btn-primary'>";
        echo "<i class='fa fa-check'></i> Confirm and Import {$valid_count} Cohort(s)";
        echo "</button>";
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/upload_cohorts.php' class='btn btn-secondary'>";
        echo "<i class='fa fa-times'></i> Cancel";
        echo "</a>";
        echo "</div>";
        
        echo "</form>";
    } else {
        echo "<div class='form-actions'>";
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/upload_cohorts.php' class='btn btn-secondary'>";
        echo "<i class='fa fa-arrow-left'></i> Try Again";
        echo "</a>";
        echo "</div>";
    }
    
} else {
    // Show upload form
    echo "<div class='upload-info'>";
    echo "<h3>📋 CSV File Format</h3>";
    echo "<p>Your CSV file should contain the following columns:</p>";
    echo "<ul>";
    echo "<li><code>name</code> - Cohort name (required)</li>";
    echo "<li><code>idnumber</code> - Unique cohort ID (required)</li>";
    echo "<li><code>description</code> - Cohort description (optional)</li>";
    echo "<li><code>visible</code> - Visibility (1 or 0, optional, defaults to 1)</li>";
    echo "</ul>";
    echo "<p><strong>Example CSV:</strong></p>";
    echo "<code>name,idnumber,description,visible<br>";
    echo "\"Grade 10 Students\",\"grade10\",\"All students in grade 10\",1<br>";
    echo "\"Science Club\",\"sciclub\",\"Students enrolled in science club\",1</code>";
    echo "</div>";
    
    echo "<form method='POST' action='' enctype='multipart/form-data'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    echo "<input type='hidden' name='action' value='upload'>";
    
    echo "<div class='file-upload-area'>";
    echo "<div class='file-upload-icon'><i class='fa fa-cloud-upload-alt'></i></div>";
    echo "<h3>Choose CSV File to Upload</h3>";
    echo "<p>Click the button below to select your CSV file</p>";
    echo "<input type='file' name='csvfile' id='csvfile' accept='.csv' class='file-input' required onchange='this.form.submit()'>";
    echo "<label for='csvfile' class='file-label'>";
    echo "<i class='fa fa-file-csv'></i> Select CSV File";
    echo "</label>";
    echo "</div>";
    
    echo "</form>";
    
    echo "<div class='form-actions'>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/index.php' class='btn btn-secondary'>";
    echo "<i class='fa fa-arrow-left'></i> Back to Cohorts";
    echo "</a>";
    echo "</div>";
}

echo "</div>"; // End upload-container

echo "</div>"; // End admin-main-content

echo $OUTPUT->footer();

