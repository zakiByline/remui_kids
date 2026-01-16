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
 * Import Schools Page - Custom Implementation
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

$success_message = '';
$error_message = '';
$imported_schools = [];
$errors = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if (isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['csvfile']['tmp_name'];
        $file_name = $_FILES['csvfile']['name'];
        
        // Validate file extension
        if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'csv') {
            $error_message = 'Please upload a CSV file';
        } else {
            try {
                // Read CSV file directly
                $file_handle = fopen($tmp_name, 'r');
                if ($file_handle === false) {
                    throw new Exception('Could not open CSV file');
                }
                
                // Read header row - trim whitespace from column names
                $columns = fgetcsv($file_handle);
                if ($columns === false) {
                    fclose($file_handle);
                    throw new Exception('CSV file is empty');
                }
                
                // Clean up column names (remove BOM, trim whitespace)
                $columns = array_map(function($col) {
                    // Remove UTF-8 BOM if present
                    $col = str_replace("\xEF\xBB\xBF", '', $col);
                    return trim($col);
                }, $columns);
                
                // Validate required columns
                $required = ['name', 'shortname'];
                $missing = array_diff($required, $columns);
                
                if (!empty($missing)) {
                    fclose($file_handle);
                    throw new moodle_exception('error', '', '', 'Missing required columns: ' . implode(', ', $missing));
                }
                
                $line = 1; // Header is line 1
                $imported_count = 0;
                $error_count = 0;
                
                // Read data rows
                while (($record = fgetcsv($file_handle)) !== false) {
                    $line++;
                    
                    // Skip completely empty rows or rows with only empty values
                    $filtered_record = array_filter($record, function($value) {
                        return $value !== null && $value !== '';
                    });
                    
                    if (empty($filtered_record)) {
                        continue;
                    }
                    
                    // Pad or trim the record to match column count
                    $record_count = count($record);
                    $column_count = count($columns);
                    
                    if ($record_count < $column_count) {
                        // Pad with empty strings
                        $record = array_pad($record, $column_count, '');
                    } else if ($record_count > $column_count) {
                        // Trim extra values
                        $record = array_slice($record, 0, $column_count);
                    }
                    
                    $data = array_combine($columns, $record);
                    
                    try {
                        // Validate required fields
                        if (empty($data['name']) || empty($data['shortname'])) {
                            throw new Exception('Name and Short Name are required');
                        }
                        
                        // Check if shortname already exists
                        if ($DB->record_exists('company', ['shortname' => $data['shortname']])) {
                            throw new Exception('Short Name already exists: ' . $data['shortname']);
                        }
                        
                        // Create company record - only use fields that exist in DB
                        $company = new stdClass();
                        $company->name = $data['name'];
                        $company->shortname = $data['shortname'];
                        $company->city = isset($data['city']) ? $data['city'] : '';
                        $company->country = isset($data['country']) ? $data['country'] : '';
                        $company->timecreated = time();
                        $company->timemodified = time();
                        
                        // Get available columns
                        $dbcolumns = $DB->get_columns('company');
                        
                        // Only add optional fields if they exist
                        if (array_key_exists('suspended', $dbcolumns)) {
                            $company->suspended = isset($data['suspended']) ? (int)$data['suspended'] : 0;
                        }
                        if (array_key_exists('maildisplay', $dbcolumns)) {
                            $company->maildisplay = 2;
                        }
                        if (array_key_exists('mailformat', $dbcolumns)) {
                            $company->mailformat = 1;
                        }
                        if (array_key_exists('maildigest', $dbcolumns)) {
                            $company->maildigest = 0;
                        }
                        if (array_key_exists('autosubscribe', $dbcolumns)) {
                            $company->autosubscribe = 1;
                        }
                        if (array_key_exists('trackforums', $dbcolumns)) {
                            $company->trackforums = 0;
                        }
                        if (array_key_exists('validto', $dbcolumns)) {
                            $company->validto = 0;
                        }
                        if (array_key_exists('suspendafter', $dbcolumns)) {
                            $company->suspendafter = 0;
                        }
                        if (array_key_exists('managernotify', $dbcolumns)) {
                            $company->managernotify = 0;
                        }
                        if (array_key_exists('supervisornotify', $dbcolumns)) {
                            $company->supervisornotify = 0;
                        }
                        if (array_key_exists('maxusers', $dbcolumns)) {
                            $company->maxusers = 0;
                        }
                        if (array_key_exists('profileid', $dbcolumns)) {
                            $company->profileid = 0;
                        }
                        if (array_key_exists('category', $dbcolumns)) {
                            $company->category = 0;
                        }
                        
                        $companyid = $DB->insert_record('company', $company);
                        
                        if ($companyid) {
                            // Set up profile category
                            $catdata = new stdclass();
                            $catdata->sortorder = $DB->count_records('user_info_category') + 1;
                            $catdata->name = $data['shortname'];
                            $profileid = $DB->insert_record('user_info_category', $catdata);
                            $DB->set_field('company', 'profileid', $profileid, ['id' => $companyid]);
                            
                            // Set up default department
                            $department = new stdClass();
                            $department->name = $data['name'];
                            $department->shortname = $data['shortname'];
                            $department->company = $companyid;
                            $department->parent = 0;
                            $DB->insert_record('department', $department);
                            
                            // Set up course category
                            $coursecat = new stdclass();
                            $coursecat->name = $data['name'];
                            $coursecat->sortorder = 999;
                            $coursecat->parent = 0;
                            $coursecat->depth = 1;
                            $coursecatid = $DB->insert_record('course_categories', $coursecat);
                            $DB->set_field('company', 'category', $coursecatid, ['id' => $companyid]);
                            
                            $imported_schools[] = $data['name'];
                            $imported_count++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Line {$line}: " . $e->getMessage();
                        $error_count++;
                    }
                }
                
                // Close the file handle
                fclose($file_handle);
                
                if ($imported_count > 0) {
                    $success_message = "{$imported_count} school(s) imported successfully!";
                }
                
                if ($error_count > 0) {
                    $error_message = "{$error_count} error(s) occurred during import. See details below.";
                }
                
            } catch (Exception $e) {
                // Make sure file handle is closed on error
                if (isset($file_handle) && is_resource($file_handle)) {
                    fclose($file_handle);
                }
                $error_message = 'Error processing CSV file: ' . $e->getMessage();
            }
        }
    } else {
        $error_message = 'Please select a CSV file to upload';
    }
}

// Set up page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/company_import.php');
$PAGE->set_title('Import Schools');
$PAGE->set_heading('Import Schools');
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
    
    /* Admin Sidebar */
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
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    .sidebar-section {
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
    }
    
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-item {
        margin: 2px 0;
    }
    
    .sidebar-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .sidebar-link:hover {
        background-color: #f8f9fa;
        color: #2c3e50;
        text-decoration: none;
        border-left-color: #667eea;
    }
    
    .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .sidebar-icon {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        font-size: 1rem;
        color: #6c757d;
    }
    
    .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    .sidebar-text {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    /* Main Content */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        padding-top: 80px;
    }
    
    .import-container {
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
    }
    
    .page-subtitle {
        margin: 0;
        font-size: 1.1rem;
        color: #546e7a;
    }
    
    /* Alert Messages */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: flex-start;
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
    
    .alert ul {
        margin: 0.5rem 0 0 0;
        padding-left: 1.5rem;
    }
    
    /* Cards */
    .card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
    }
    
    .card-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    /* Upload Form */
    .upload-area {
        border: 3px dashed #e0e0e0;
        border-radius: 15px;
        padding: 3rem;
        text-align: center;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .upload-area:hover {
        border-color: #81d4fa;
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
    }
    
    .upload-area.dragover {
        border-color: #4fc3f7;
        background: linear-gradient(135deg, #e1f5fe 0%, #b3e5fc 100%);
    }
    
    .upload-icon {
        font-size: 4rem;
        color: #81d4fa;
        margin-bottom: 1rem;
    }
    
    .upload-text {
        font-size: 1.2rem;
        color: #2c3e50;
        margin-bottom: 0.5rem;
        font-weight: 600;
    }
    
    .upload-hint {
        color: #6c757d;
        font-size: 0.95rem;
    }
    
    .file-input {
        display: none;
    }
    
    .file-name {
        margin-top: 1rem;
        padding: 0.75rem 1rem;
        background: white;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #2c3e50;
        font-weight: 500;
    }
    
    /* CSV Format Instructions */
    .format-instructions {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1.5rem;
        margin: 1.5rem 0;
    }
    
    .format-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 1rem;
    }
    
    .format-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .format-table th,
    .format-table td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }
    
    .format-table th {
        background: #e9ecef;
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.9rem;
    }
    
    .format-table code {
        background: #f8f9fa;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
        color: #e83e8c;
    }
    
    /* Buttons */
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
    
    .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    .btn-secondary {
        background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%);
        color: #2c3e50;
    }
    
    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(189, 189, 189, 0.4);
    }
    
    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
    }
    
    /* Results List */
    .results-list {
        list-style: none;
        padding: 0;
        margin: 1rem 0;
    }
    
    .results-list li {
        padding: 0.75rem 1rem;
        background: white;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .results-list li i {
        color: #28a745;
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        .admin-main-content {
            left: 0;
            width: 100vw;
            padding-top: 20px;
        }
        
        .upload-area {
            padding: 2rem 1rem;
        }
        
        .form-actions {
            flex-direction: column;
        }
    }
</style>

<?php
// Include Admin Sidebar
require_once('includes/admin_sidebar.php');

// Main Content
echo "<div class='admin-main-content'>";
echo "<div class='import-container'>";

// Page Header
echo "<div class='page-header'>";
echo "<h1 class='page-title'>Import Schools</h1>";
echo "<p class='page-subtitle'>Bulk import schools from CSV file</p>";
echo "</div>";

// Display messages
if ($success_message) {
    echo "<div class='alert alert-success'>";
    echo "<i class='fa fa-check-circle'></i>";
    echo "<div>";
    echo "<div>{$success_message}</div>";
    if (!empty($imported_schools)) {
        echo "<ul class='results-list'>";
        foreach ($imported_schools as $school_name) {
            echo "<li><i class='fa fa-check'></i> " . htmlspecialchars($school_name) . "</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
    echo "</div>";
}

if ($error_message) {
    echo "<div class='alert alert-danger'>";
    echo "<i class='fa fa-exclamation-triangle'></i>";
    echo "<div>";
    echo "<div>{$error_message}</div>";
    if (!empty($errors)) {
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
    echo "</div>";
}

// Upload Form Card
echo "<div class='card'>";
echo "<h2 class='card-title'><i class='fa fa-upload'></i> Upload CSV File</h2>";

echo "<form method='POST' action='' enctype='multipart/form-data' id='uploadForm'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";

echo "<div class='upload-area' id='uploadArea' onclick='document.getElementById(\"csvfile\").click()'>";
echo "<div class='upload-icon'><i class='fa fa-cloud-upload-alt'></i></div>";
echo "<div class='upload-text'>Click to select CSV file or drag and drop</div>";
echo "<div class='upload-hint'>Maximum file size: 2MB</div>";
echo "<input type='file' name='csvfile' id='csvfile' class='file-input' accept='.csv' required>";
echo "<div id='fileName' class='file-name' style='display: none;'>";
echo "<i class='fa fa-file-csv'></i>";
echo "<span id='fileNameText'></span>";
echo "</div>";
echo "</div>";

// CSV Format Instructions
echo "<div class='format-instructions'>";
echo "<div class='format-title'><i class='fa fa-info-circle'></i> CSV Format Requirements</div>";
echo "<table class='format-table'>";
echo "<tr>";
echo "<th>Column Name</th>";
echo "<th>Required</th>";
echo "<th>Description</th>";
echo "<th>Example</th>";
echo "</tr>";
echo "<tr>";
echo "<td><code>name</code></td>";
echo "<td><strong>Yes</strong></td>";
echo "<td>Full school name</td>";
echo "<td>Riyadh International School</td>";
echo "</tr>";
echo "<tr>";
echo "<td><code>shortname</code></td>";
echo "<td><strong>Yes</strong></td>";
echo "<td>Unique identifier (lowercase, no spaces)</td>";
echo "<td>ris</td>";
echo "</tr>";
echo "<tr>";
echo "<td><code>city</code></td>";
echo "<td>No</td>";
echo "<td>City location</td>";
echo "<td>Riyadh</td>";
echo "</tr>";
echo "<tr>";
echo "<td><code>country</code></td>";
echo "<td>No</td>";
echo "<td>Country code (2 letters)</td>";
echo "<td>SA</td>";
echo "</tr>";
echo "<tr>";
echo "<td><code>suspended</code></td>";
echo "<td>No</td>";
echo "<td>0 = Active, 1 = Suspended</td>";
echo "<td>0</td>";
echo "</tr>";
echo "</table>";
echo "</div>";

// Form Actions
echo "<div class='form-actions'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/schools_management.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";
echo "<button type='submit' class='btn btn-primary' id='submitBtn' disabled>";
echo "<i class='fa fa-upload'></i> Import Schools";
echo "</button>";
echo "</div>";

echo "</form>";
echo "</div>"; // End card

echo "</div>"; // End import-container
echo "</div>"; // End admin-main-content
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('csvfile');
    const fileName = document.getElementById('fileName');
    const fileNameText = document.getElementById('fileNameText');
    const submitBtn = document.getElementById('submitBtn');
    
    // File input change
    fileInput.addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            fileNameText.textContent = file.name;
            fileName.style.display = 'inline-flex';
            submitBtn.disabled = false;
        }
    });
    
    // Drag and drop
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>

