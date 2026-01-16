<?php
/**
 * Bulk Profile Picture Upload Page - Upload user profile pictures in ZIP format
 * Images should be named with username (e.g., username.jpg, username.png)
 */

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company info
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'No company found for current user.', null, \core\output\notification::NOTIFY_ERROR);
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/bulk_profile_upload.php'));
$PAGE->set_title('Bulk Profile Picture Upload - ' . $company_info->name);
$PAGE->set_heading('Bulk Profile Picture Upload');

// Get statistics for users in the school
$total_users = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND u.deleted = 0",
    [$company_info->id]
);

$users_with_pictures = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND u.deleted = 0 AND u.picture > 0",
    [$company_info->id]
);

$users_without_pictures = $total_users - $users_with_pictures;

// Process ZIP upload - REGULAR FORM SUBMISSION (no AJAX to avoid routing issues)
$upload_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
    // Verify session key for security
    $provided_sesskey = optional_param('sesskey', '', PARAM_RAW);
    if ($provided_sesskey !== sesskey()) {
        // Session key mismatch - show error
        $upload_result = [
            'success' => false,
            'message' => 'Invalid session key. Please refresh the page and try again.',
            'errors' => []
        ];
    } else {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $upload_token = $_POST['upload_token'] ?? '';
        
        // Check for duplicate submission
        if (!empty($upload_token) && (!isset($_SESSION['last_upload_token']) || $_SESSION['last_upload_token'] !== $upload_token)) {
            $upload_result = process_bulk_profile_pictures($_FILES['zip_file'], $company_info);
            
            $_SESSION['last_upload_token'] = $upload_token;
            $_SESSION['upload_result'] = $upload_result;
            
            // Redirect to prevent form resubmission (POST-Redirect-GET pattern)
            $redirect_url = new moodle_url('/theme/remui_kids/school_manager/bulk_profile_upload.php', ['uploaded' => 1]);
            redirect($redirect_url);
            exit;
        }
    }
}

// Check if redirected after upload (POST-Redirect-GET pattern)
if (isset($_GET['uploaded'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['upload_result'])) {
        $upload_result = $_SESSION['upload_result'];
        unset($_SESSION['upload_result']);
    }
}

/**
 * Convert PHP size string to bytes
 */
function return_bytes($size_str) {
    if (empty($size_str)) {
        return 2097152; // Default 2MB
    }
    
    $size_str = trim($size_str);
    $last = strtolower($size_str[strlen($size_str)-1]);
    $num = (int)$size_str;
    
    switch($last) {
        case 'g':
            $num *= 1024 * 1024 * 1024;
            break;
        case 'm':
            $num *= 1024 * 1024;
            break;
        case 'k':
            $num *= 1024;
            break;
        default:
            // If no unit specified, assume bytes
            $num = (int)$size_str;
            break;
    }
    
    return $num > 0 ? $num : 2097152; // Return value or default 2MB
}

/**
 * Process bulk profile picture upload from ZIP file
 */
function process_bulk_profile_pictures($zip_file, $company_info) {
    global $DB, $CFG;
    
    $result = [
        'success' => false,
        'message' => '',
        'success_count' => 0,
        'skipped_count' => 0,
        'error_count' => 0,
        'errors' => []
    ];
    
    // Validate file upload with specific error messages
    if (!isset($zip_file['error'])) {
        $result['message'] = 'No file was uploaded. Please select a ZIP file.';
        return $result;
    }
    
    if ($zip_file['error'] !== UPLOAD_ERR_OK) {
        switch ($zip_file['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $result['message'] = 'The uploaded file exceeds the maximum file size allowed by the server (upload_max_filesize).';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $result['message'] = 'The uploaded file exceeds the maximum file size allowed.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $result['message'] = 'The file was only partially uploaded. Please try again.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $result['message'] = 'No file was uploaded. Please select a ZIP file.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $result['message'] = 'Server configuration error: Missing temporary folder.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $result['message'] = 'Server configuration error: Failed to write file to disk.';
                break;
            case UPLOAD_ERR_EXTENSION:
                $result['message'] = 'Server configuration error: A PHP extension stopped the file upload.';
                break;
            default:
                $result['message'] = 'File upload error (Code: ' . $zip_file['error'] . '). Please try again.';
                break;
        }
        return $result;
    }
    
    // Validate that file was actually uploaded
    if (!isset($zip_file['tmp_name']) || empty($zip_file['tmp_name']) || !is_uploaded_file($zip_file['tmp_name'])) {
        $result['message'] = 'Invalid file upload. Please try again.';
        return $result;
    }
    
    // Check if it's a ZIP file
    $file_extension = strtolower(pathinfo($zip_file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'zip') {
        $result['message'] = 'Please upload a ZIP file.';
        return $result;
    }
    
    // Create temporary directory for extraction
    $temp_dir = $CFG->tempdir . '/profile_pictures_' . time() . '_' . uniqid();
    if (!mkdir($temp_dir, 0777, true)) {
        $result['message'] = 'Failed to create temporary directory.';
        return $result;
    }
    
    // Extract ZIP file
    $zip = new ZipArchive();
    if ($zip->open($zip_file['tmp_name']) !== true) {
        $result['message'] = 'Failed to open ZIP file.';
        rmdir($temp_dir);
        return $result;
    }
    
    $zip->extractTo($temp_dir);
    $zip->close();
    
    // Get all users from the school
    $school_users = $DB->get_records_sql(
        "SELECT u.* 
         FROM {user} u 
         JOIN {company_users} cu ON u.id = cu.userid 
         WHERE cu.companyid = ? AND u.deleted = 0",
        [$company_info->id]
    );
    
    // Create username to user ID mapping
    $username_map = [];
    foreach ($school_users as $user) {
        $username_map[strtolower($user->username)] = $user;
    }
    
    // Supported image extensions
    $supported_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    // Process each file in the extracted directory
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            $filename = $file->getFilename();
            $filepath = $file->getPathname();
            
            // Skip hidden files and system files
            if (strpos($filename, '.') === 0 || strpos($filename, '__MACOSX') !== false) {
                continue;
            }
            
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Check if it's a supported image format
            if (!in_array($file_extension, $supported_extensions)) {
                $result['errors'][] = "Skipped '{$filename}': Unsupported file format";
                $result['skipped_count']++;
                continue;
            }
            
            // Extract username from filename (without extension)
            $username = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            
            // Check if user exists in the school
            if (!isset($username_map[$username])) {
                $result['errors'][] = "Skipped '{$filename}': User '{$username}' not found in your school";
                $result['skipped_count']++;
                continue;
            }
            
            $user = $username_map[$username];
            
            // Update user profile picture (following Moodle's standard approach)
            try {
                $usercontext = context_user::instance($user->id);
                $fs = get_file_storage();
                
                // Delete old newicon files
                $fs->delete_area_files($usercontext->id, 'user', 'newicon');
                
                // Prepare file record for newicon area
                $fileinfo = [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'newicon',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $filename
                ];
                
                // Create file in newicon area
                $stored_file = $fs->create_file_from_pathname($fileinfo, $filepath);
                
                if ($stored_file) {
                    // Copy file to temporary location
                    $tempfile = $stored_file->copy_content_to_temp();
                    
                    if ($tempfile) {
                        // Process the image using the temp file path
                        require_once($CFG->libdir . '/gdlib.php');
                        
                        // process_new_icon expects: context, component, filearea, itemid, temp_file_path
                        $newpicture = (int) process_new_icon($usercontext, 'user', 'icon', 0, $tempfile);
                        
                        // Delete temporary file
                        @unlink($tempfile);
                        
                        // Remove newicon area files
                        $fs->delete_area_files($usercontext->id, 'user', 'newicon');
                        
                        if ($newpicture) {
                            // Update user picture field
                            $DB->set_field('user', 'picture', $newpicture, ['id' => $user->id]);
                            $result['success_count']++;
                        } else {
                            $result['errors'][] = "Failed to process image for user '{$username}'";
                            $result['error_count']++;
                        }
                    } else {
                        $result['errors'][] = "Failed to create temp file for user '{$username}'";
                        $result['error_count']++;
                    }
                } else {
                    $result['errors'][] = "Failed to store image for user '{$username}'";
                    $result['error_count']++;
                }
            } catch (Exception $e) {
                $result['errors'][] = "Error processing '{$filename}': " . $e->getMessage();
                $result['error_count']++;
            }
        }
    }
    
    // Clean up temporary directory
    delete_directory_recursive($temp_dir);
    
    // Set final result
    if ($result['success_count'] > 0) {
        $result['success'] = true;
        $result['message'] = "Successfully uploaded {$result['success_count']} profile picture(s)";
    } else {
        $result['message'] = 'No profile pictures were uploaded.';
    }
    
    return $result;
}

/**
 * Recursively delete directory and its contents
 */
function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            delete_directory_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => !empty($company_info->logo) ? $company_info->logo : '',
    'has_logo' => !empty($company_info->logo),
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'students_active' => false,
    'dashboard_active' => false,
    'teachers_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'bulk_download_active' => false,
    'bulk_profile_upload_active' => true, // This page is active
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'user_reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Page Styles
echo "<style>
/* Reset and base styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f8f9fa;
    overflow-x: hidden;
}

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background: linear-gradient(135deg, #f8f9ff 0%, #ffffff 100%);
    overflow-y: auto;
    z-index: 99;
    will-change: transform;
    backface-visibility: hidden;
}

/* Main content positioning */
.main-content {
    padding: 20px 20px 20px 20px;
    padding-top: 35px;
    min-height: 100vh;
    background: transparent;
}

/* Header */
.page-header {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    margin-top: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    position: relative;
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.page-header-content {
    flex: 1;
    text-align: center;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.header-buttons {
    display: flex;
    gap: 12px;
    align-items: center;
}

.back-button {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.back-button:hover {
    background: #1d4ed8;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    text-decoration: none;
    color: #ffffff;
    transform: translateY(-1px);
}

.back-button i {
    color: #ffffff;
}

/* Stats Cards */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding: 0 40px;
}

.stat-card {
    background: white;
    border-radius: 0.75rem;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 140px;
    border-top: 4px solid transparent;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.stat-card.users {
    border-top-color: #3b82f6;
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.08), #ffffff);
}

.stat-card.with-pictures {
    border-top-color: #10b981;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.08), #ffffff);
}

.stat-card.without-pictures {
    border-top-color: #f59e0b;
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.08), #ffffff);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}

.stat-card.users .stat-icon {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
}

.stat-card.with-pictures .stat-icon {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
}

.stat-card.without-pictures .stat-icon {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 500;
}

/* Upload Section */
.upload-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin: 0 40px 2rem 40px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
}

.upload-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 1rem;
}

.upload-header i {
    font-size: 1.5rem;
    color: #3b82f6;
}

.upload-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.upload-description {
    color: #6b7280;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.instructions-box {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border-radius: 6px;
}

.instructions-box h3 {
    margin: 0 0 0.75rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.instructions-box h3 i {
    color: #3b82f6;
}

.instructions-box ul {
    margin: 0;
    padding-left: 1.5rem;
    color: #4b5563;
}

.instructions-box li {
    margin-bottom: 0.5rem;
    line-height: 1.5;
}

.instructions-box code {
    background: #dbeafe;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
    font-size: 0.875rem;
    color: #1e40af;
}

.instructions-box .example {
    margin-top: 0.75rem;
    padding: 0.75rem;
    background: white;
    border-radius: 4px;
    font-size: 0.875rem;
}

.instructions-box .example strong {
    color: #1f2937;
}

/* Upload Area */
.upload-area {
    border: 2px dashed #cbd5e1;
    border-radius: 8px;
    padding: 3rem 2rem;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
    background: #f8fafc;
}

.upload-area:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}

.upload-area.dragover {
    border-color: #3b82f6;
    background: #dbeafe;
}

.upload-icon {
    font-size: 3rem;
    color: #94a3b8;
    margin-bottom: 1rem;
}

.upload-text {
    font-size: 1.125rem;
    color: #1f2937;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.upload-hint {
    color: #6b7280;
    font-size: 0.875rem;
}

.file-input {
    display: none;
}

.upload-button {
    background: #3b82f6;
    color: white;
    padding: 12px 32px;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.2s ease;
    margin-top: 1rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.upload-button:hover {
    background: #2563eb;
}

.upload-button:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

.selected-file {
    margin-top: 1rem;
    padding: 0.75rem;
    background: #eff6ff;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #1f2937;
}

.selected-file i {
    color: #3b82f6;
}

/* Result Messages */
.result-message {
    padding: 1rem 1.25rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.result-success {
    background: #d1fae5;
    border-left: 4px solid #10b981;
    color: #065f46;
}

.result-error {
    background: #fee2e2;
    border-left: 4px solid #ef4444;
    color: #991b1b;
}

.result-message strong {
    font-weight: 600;
}

.error-list {
    margin-top: 0.75rem;
    max-height: 200px;
    overflow-y: auto;
}

.error-list ul {
    margin: 0.5rem 0 0 0;
    padding-left: 1.5rem;
}

.error-list li {
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100vw;
    }
    
    .page-header {
        padding: 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .header-buttons {
        width: 100%;
    }
    
    .back-button {
        width: 100%;
        justify-content: center;
    }
    
    .stats-section {
        padding: 0 20px;
    }
    
    .upload-section {
        margin: 0 20px 2rem 20px;
    }
    
    .upload-area {
        padding: 2rem 1rem;
    }
}

/* Loading Animation */
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.loading {
    display: inline-block;
    animation: spin 1s linear infinite;
}
</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Bulk Profile Picture Upload</h1>";
echo "<p class='page-subtitle'>Upload profile pictures for users in " . htmlspecialchars($company_info->name) . "</p>";
echo "</div>";
echo "<div class='header-buttons'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/student_bulk_upload.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Student Upload";
echo "</a>";
echo "</div>";
echo "</div>";

// Stats Section
echo "<div class='stats-section'>";
echo "<div class='stat-card users'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-users'></i>";
echo "</div>";
echo "<div class='stat-number'>{$total_users}</div>";
echo "<div class='stat-label'>Total Users</div>";
echo "</div>";

echo "<div class='stat-card with-pictures'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-image'></i>";
echo "</div>";
echo "<div class='stat-number'>{$users_with_pictures}</div>";
echo "<div class='stat-label'>With Profile Pictures</div>";
echo "</div>";

echo "<div class='stat-card without-pictures'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-circle'></i>";
echo "</div>";
echo "<div class='stat-number'>{$users_without_pictures}</div>";
echo "<div class='stat-label'>Without Profile Pictures</div>";
echo "</div>";
echo "</div>";

// Upload Section
echo "<div class='upload-section'>";
echo "<div class='upload-header'>";
echo "<i class='fa fa-camera'></i>";
echo "<h2 class='upload-title'>Upload Profile Pictures</h2>";
echo "</div>";
echo "<p class='upload-description'>Upload a ZIP file containing user profile pictures. Each image should be named with the user's username (e.g., john.doe.jpg, jane.smith.png). Only users from your school will have their profile pictures updated.</p>";

// Instructions Box
echo "<div class='instructions-box'>";
echo "<h3><i class='fa fa-info-circle'></i> Instructions</h3>";
echo "<ul>";
echo "<li>Create a ZIP file containing all profile pictures</li>";
echo "<li>Name each image file with the user's <strong>username</strong> (e.g., <code>username.jpg</code>, <code>username.png</code>)</li>";
echo "<li>Supported formats: JPG, JPEG, PNG, GIF</li>";
echo "<li>Images will only be uploaded for users that exist in your school (<strong>{$company_info->name}</strong>)</li>";
echo "<li>Existing profile pictures will be replaced with new ones</li>";
echo "</ul>";
echo "<div class='example'>";
echo "<strong>Example ZIP contents:</strong><br>";
echo "<code>john.doe.jpg<br>jane.smith.png<br>mike.wilson.jpeg</code>";
echo "</div>";
echo "</div>";

// Display upload limits
$upload_max = ini_get('upload_max_filesize');
$post_max = ini_get('post_max_size');
echo "<div class='instructions-box' style='background: #f0fdf4; border-left-color: #10b981;'>";
echo "<h3><i class='fa fa-info-circle'></i> Upload Limits</h3>";
echo "<p style='margin: 0; color: #4b5563;'>";
echo "Maximum file size: <strong>{$upload_max}</strong> | Maximum post size: <strong>{$post_max}</strong>";
echo "</p>";
echo "</div>";

// Show upload result if any
if ($upload_result) {
    $result_class = $upload_result['success'] ? 'result-success' : 'result-error';
    echo "<div class='result-message {$result_class}'>";
    
    if ($upload_result['success']) {
        echo "<i class='fa fa-check-circle' style='font-size: 1.25rem;'></i>";
        echo "<div>";
        $messages = [];
        if (isset($upload_result['success_count']) && $upload_result['success_count'] > 0) {
            $messages[] = "{$upload_result['success_count']} profile picture(s) uploaded successfully";
        }
        if (isset($upload_result['skipped_count']) && $upload_result['skipped_count'] > 0) {
            $messages[] = "{$upload_result['skipped_count']} file(s) skipped";
        }
        if (isset($upload_result['error_count']) && $upload_result['error_count'] > 0) {
            $messages[] = "{$upload_result['error_count']} file(s) failed";
        }
        
        echo "<strong>Success!</strong> " . implode(", ", $messages) . ".";
        echo "</div>";
    } else {
        echo "<i class='fa fa-exclamation-circle' style='font-size: 1.25rem;'></i>";
        echo "<div>";
        echo "<strong>Upload Failed:</strong> " . (isset($upload_result['message']) ? $upload_result['message'] : 'Unknown error');
        echo "</div>";
    }
    
    if (!empty($upload_result['errors'])) {
        echo "<div class='error-list'>";
        echo "<ul>";
        foreach ($upload_result['errors'] as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    echo "</div>";
}

// Upload Form
$upload_token = uniqid('upload_', true);
// Convert upload_max_filesize to bytes for MAX_FILE_SIZE
$max_size_str = ini_get('upload_max_filesize');
$max_size_bytes = return_bytes($max_size_str);
// Ensure we have a valid number (fallback to 2MB if something goes wrong)
if (empty($max_size_bytes) || !is_numeric($max_size_bytes)) {
    $max_size_bytes = 2 * 1024 * 1024; // 2MB default
}
// Use regular form submission (no AJAX to avoid routing issues)
echo "<form method='POST' enctype='multipart/form-data' id='uploadForm'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "' />";
echo "<input type='hidden' name='upload_token' value='{$upload_token}' />";
echo "<input type='hidden' name='MAX_FILE_SIZE' value='{$max_size_bytes}' />";

echo "<div class='upload-area' id='uploadArea'>";
echo "<div class='upload-icon'>";
echo "<i class='fa fa-cloud-upload'></i>";
echo "</div>";
echo "<div class='upload-text'>Drag and drop your ZIP file here</div>";
echo "<div class='upload-hint'>or click to browse</div>";
echo "<input type='file' name='zip_file' id='zipFile' class='file-input' accept='.zip' required />";
echo "<div id='selectedFile' class='selected-file' style='display: none;'>";
echo "<i class='fa fa-file-archive-o'></i>";
echo "<span id='fileName'></span>";
echo "</div>";
echo "</div>";

echo "<div style='text-align: center;'>";
echo "<button type='submit' class='upload-button' id='uploadButton' disabled>";
echo "<i class='fa fa-upload'></i> Upload Profile Pictures";
echo "</button>";
echo "</div>";

echo "</form>";
echo "</div>";

echo "</div>"; // main-content
echo "</div>"; // school-manager-main-content

// JavaScript for file upload
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('zipFile');
    const uploadButton = document.getElementById('uploadButton');
    const selectedFile = document.getElementById('selectedFile');
    const fileName = document.getElementById('fileName');
    const uploadForm = document.getElementById('uploadForm');
    const maxFileSize = " . (int)$max_size_bytes . "; // Maximum file size in bytes
    
    // Click to browse
    uploadArea.addEventListener('click', function(e) {
        if (e.target.id !== 'zipFile') {
            fileInput.click();
        }
    });
    
    // File selection with validation
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            
            // Validate file type
            if (!file.name.toLowerCase().endsWith('.zip')) {
                alert('Please select a ZIP file.');
                this.value = '';
                return;
            }
            
            // Validate file size
            if (file.size > maxFileSize) {
                const maxSizeMB = (maxFileSize / (1024 * 1024)).toFixed(2);
                const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                alert('File size (' + fileSizeMB + ' MB) exceeds the maximum allowed size of ' + maxSizeMB + ' MB. Please choose a smaller file.');
                this.value = '';
                return;
            }
            
            // Display selected file
            const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
            fileName.textContent = file.name + ' (' + fileSizeMB + ' MB)';
            selectedFile.style.display = 'inline-flex';
            uploadButton.disabled = false;
        }
    });
    
    // Drag and drop
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            const event = new Event('change');
            fileInput.dispatchEvent(event);
        }
    });
    
    // Form submission - simple validation before submit
    uploadForm.addEventListener('submit', function(e) {
        // Validate file is selected
        if (!fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('Please select a ZIP file first.');
            return false;
        }
        
        // Show uploading state
        uploadButton.innerHTML = '<i class=\"fa fa-spinner loading\"></i> Uploading...';
        uploadButton.disabled = true;
        
        // Let the form submit normally (no AJAX, no routing issues!)
        return true;
    });
});
</script>";

echo $OUTPUT->footer();

