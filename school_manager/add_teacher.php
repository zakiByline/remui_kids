<?php
/**
 * Add New Teacher - School Manager
 * Form for adding new teachers to the school
 */

// Use __DIR__ for reliable path resolution
require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    // Try alternative query to find company info if first query fails
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.* 
             FROM {company} c 
             JOIN {company_users} cu ON c.id = cu.companyid 
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/add_teacher.php'));
$PAGE->set_title('Add New Teacher');
$PAGE->set_heading('Add New Teacher');
$PAGE->set_pagelayout('admin');

// Initialize error array
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify sesskey for security
    if (!confirm_sesskey()) {
        $errors[] = 'Invalid session key. Please try again.';
    }
    
    if (empty($errors)) {
    // Validate required fields
    $required_fields = ['username', 'password', 'firstname', 'lastname', 'email'];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    
    // Validate email format
        if (!empty($_POST['email']) && !validate_email($_POST['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    // Check if username already exists
        if (!empty($_POST['username']) && $DB->record_exists('user', ['username' => $_POST['username'], 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
        $errors[] = 'Username already exists. Please choose a different username.';
    }
    
    // Check if email already exists
        if (!empty($_POST['email']) && $DB->record_exists('user', ['email' => $_POST['email'], 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
        $errors[] = 'Email already exists. Please use a different email address.';
    }
    
    // Validate password strength
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        }
    }
        
        // Check if company info is available
        if (!$company_info) {
            $errors[] = 'Unable to determine school information. Please contact administrator.';
        }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $transaction = $DB->start_delegated_transaction();
            
                // Use Moodle's built-in user creation function (more reliable)
            $user_data = new stdClass();
            $user_data->username = trim($_POST['username']);
                $user_data->password = hash_internal_user_password($_POST['password']); // Hash password using Moodle's function
                error_log("Password hashed for user: " . $user_data->username . " - Hash: " . substr($user_data->password, 0, 20) . "...");
            $user_data->firstname = trim($_POST['firstname']);
            $user_data->lastname = trim($_POST['lastname']);
            $user_data->email = trim($_POST['email']);
            $user_data->phone1 = !empty($_POST['phone']) ? trim($_POST['phone']) : '';
            $user_data->city = !empty($_POST['city']) ? trim($_POST['city']) : '';
            $user_data->country = !empty($_POST['country']) ? $_POST['country'] : '';
            $user_data->description = !empty($_POST['notes']) ? trim($_POST['notes']) : '';
            $user_data->auth = 'manual';
            $user_data->confirmed = 1;
            $user_data->mnethostid = $CFG->mnet_localhost_id;
            $user_data->suspended = 0; // Ensure user is not suspended
            $user_data->deleted = 0; // Ensure user is not deleted
            
                // Create user using Moodle's function
                try {
                    $user_id = user_create_user($user_data, false, false);
                    error_log("Teacher created successfully with ID: " . $user_id);
                    
                    // Handle profile image upload if provided
                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $uploaded_file = $_FILES['profile_image'];
                        
                        // Validate file
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                        $max_size = 2 * 1024 * 1024; // 2MB
                        
                        if (in_array($uploaded_file['type'], $allowed_types) && $uploaded_file['size'] <= $max_size) {
                            // Create user context
                            $user_context = context_user::instance($user_id);
                            
                            // Prepare file area
                            $fs = get_file_storage();
                            
                            // Delete any existing profile images from both areas
                            $fs->delete_area_files($user_context->id, 'user', 'icon');
                            $fs->delete_area_files($user_context->id, 'user', 'newicon');
                            
                            // Determine file extension
                            $file_extension = 'jpg';
                            if (strpos($uploaded_file['type'], 'png') !== false) {
                                $file_extension = 'png';
                            } elseif (strpos($uploaded_file['type'], 'gif') !== false) {
                                $file_extension = 'gif';
                            }
                            
                            // Create file record (use 'icon' area which Moodle uses for profile pictures)
                            $file_record = array(
                                'contextid' => $user_context->id,
                                'component' => 'user',
                                'filearea' => 'icon',
                                'itemid' => 0,
                                'filepath' => '/',
                                'filename' => 'f1.' . $file_extension  // Moodle uses f1, f2, f3 for different sizes
                            );
                            
                            // Store the file
                            $stored_file = $fs->create_file_from_pathname($file_record, $uploaded_file['tmp_name']);
                            
                            if ($stored_file) {
                                // Update user's picture field with current timestamp
                                $user = $DB->get_record('user', ['id' => $user_id]);
                                $user->picture = time(); // Set to current timestamp (Moodle uses this for cache-busting)
                                $user->timemodified = time();
                                $DB->update_record('user', $user);
                                
                                error_log("✅ Profile image uploaded successfully for user ID: " . $user_id . " (picture field set to: " . $user->picture . ")");
                            } else {
                                error_log("❌ Failed to store profile image file for user ID: " . $user_id);
                            }
                        } else {
                            error_log("Invalid profile image file: type=" . $uploaded_file['type'] . ", size=" . $uploaded_file['size']);
                        }
                    }
                    
                } catch (Exception $user_error) {
                    error_log("Error creating teacher: " . $user_error->getMessage());
                    throw new Exception('Error creating teacher: ' . $user_error->getMessage());
                }
                
                if ($user_id) {
                    // Assign ONLY editingteacher role (primary role for teachers from school manager)
                    $editingteacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
                    
                    $roles_assigned = [];
                    
                    // Assign ONLY editingteacher role (consistent with school manager view)
                    if ($editingteacher_role) {
                        try {
                            role_assign($editingteacher_role->id, $user_id, $context->id);
                            $roles_assigned[] = 'editingteacher';
                            error_log("Editingteacher role assigned successfully to user ID: " . $user_id . " with role ID: " . $editingteacher_role->id);
                        } catch (Exception $role_error) {
                            error_log("Error assigning editingteacher role: " . $role_error->getMessage());
                        }
                    } else {
                        error_log("ERROR: editingteacher role not found in database");
                    }
                    
                    // Verify role assignments were created
                    if (!empty($roles_assigned)) {
                        foreach ($roles_assigned as $role_name) {
                            $role_obj = $DB->get_record('role', ['shortname' => $role_name]);
                            if ($role_obj) {
                                $verify_role_assignment = $DB->get_record('role_assignments', [
                                    'userid' => $user_id, 
                                    'roleid' => $role_obj->id, 
                                    'contextid' => $context->id
                                ]);
                                if ($verify_role_assignment) {
                                    error_log("Verification: " . $role_name . " role assignment exists - userid: " . $verify_role_assignment->userid . ", roleid: " . $verify_role_assignment->roleid);
                                } else {
                                    error_log("ERROR: " . $role_name . " role assignment NOT found after creation!");
                                }
                            }
                        }
                    } else {
                        error_log("ERROR: No teacher roles found in database");
                    }
                    
                    // Add user to company if company info exists (CRITICAL for teacher visibility)
                if ($company_info) {
                        error_log("Company info found: " . $company_info->name . " (ID: " . $company_info->id . ")");
                        
                        // Check if company_users table exists
                        if (!$DB->get_manager()->table_exists('company_users')) {
                            error_log("CRITICAL ERROR: company_users table does not exist - IOMAD may not be properly installed");
                            throw new Exception("School association system not available. Please contact administrator.");
                        }
                        
                        // Get or create default department for the company
                        $department = $DB->get_record('department', ['company' => $company_info->id]);
                        if (!$department) {
                            // Create default department if none exists
                            $default_department = new stdClass();
                            $default_department->name = 'General Department';
                            $default_department->shortname = 'general';
                            $default_department->company = $company_info->id;
                            $default_department->parent = 0;
                            
                            $department_id = $DB->insert_record('department', $default_department);
                            $department = new stdClass();
                            $department->id = $department_id;
                            error_log("Created default department with ID: " . $department_id);
                        } else {
                            $department_id = $department->id;
                            error_log("Using existing department: " . $department->name . " (ID: " . $department_id . ")");
                        }
                        
                        // Check if user is already in company_users to avoid duplicates
                        $existing_company_user = $DB->get_record('company_users', [
                            'userid' => $user_id,
                            'companyid' => $company_info->id
                        ]);
                        
                        if ($existing_company_user) {
                            error_log("Company user already exists for user ID: " . $user_id . " in company ID: " . $company_info->id);
                            // Update existing record to ensure correct flags
                            $existing_company_user->managertype = 0;
                            $existing_company_user->educator = 1;
                            $existing_company_user->timemodified = time();
                            $DB->update_record('company_users', $existing_company_user);
                            error_log("Updated existing company user record");
                        } else {
                            // Create new company user record with multiple fallback approaches
                            $company_user_created = false;
                            $company_user_id = null;
                            
                            // Approach 1: Standard insert with all fields
                            try {
                    $company_user = new stdClass();
                    $company_user->userid = $user_id;
                    $company_user->companyid = $company_info->id;
                    $company_user->departmentid = $department_id;
                                $company_user->managertype = 0;
                                $company_user->educator = 1;
                    $company_user->timecreated = time();
                    $company_user->timemodified = time();
                    
                                $company_user_id = $DB->insert_record('company_users', $company_user);
                                $company_user_created = true;
                                error_log("Company user created successfully with ID: " . $company_user_id);
                            } catch (Exception $e1) {
                                error_log("Approach 1 failed: " . $e1->getMessage());
                                
                                // Approach 2: Insert with minimal fields
                                try {
                                    $company_user = new stdClass();
                                    $company_user->userid = $user_id;
                                    $company_user->companyid = $company_info->id;
                                    $company_user->departmentid = $department_id;
                                    
                                    $company_user_id = $DB->insert_record('company_users', $company_user);
                                    $company_user_created = true;
                                    error_log("Company user created with minimal fields, ID: " . $company_user_id);
                                    
                                    // Update with additional fields
                                    $company_user->id = $company_user_id;
                                    $company_user->managertype = 0;
                                    $company_user->educator = 1;
                                    $company_user->timecreated = time();
                                    $company_user->timemodified = time();
                                    $DB->update_record('company_users', $company_user);
                                    error_log("Updated company user with additional fields");
                                } catch (Exception $e2) {
                                    error_log("Approach 2 failed: " . $e2->getMessage());
                                    
                                    // Approach 3: Direct SQL insert
                                    try {
                                        $sql = "INSERT INTO {company_users} (userid, companyid, departmentid, managertype, educator, timecreated, timemodified) VALUES (?, ?, ?, ?, ?, ?, ?)";
                                        $DB->execute($sql, [$user_id, $company_info->id, $department_id, 0, 1, time(), time()]);
                                        $company_user_created = true;
                                        error_log("Company user created with direct SQL");
                                    } catch (Exception $e3) {
                                        error_log("Approach 3 failed: " . $e3->getMessage());
                                    }
                                }
                            }
                            
                            if (!$company_user_created) {
                                error_log("CRITICAL ERROR: All approaches failed to create company user");
                                throw new Exception("Failed to associate teacher with school: All database insert methods failed");
                            }
                        }
                        
                        // Verify the company user was created
                        $verify_company_user = $DB->get_record('company_users', ['userid' => $user_id, 'companyid' => $company_info->id]);
                        if ($verify_company_user) {
                            error_log("SUCCESS: Company user record verified - userid: " . $verify_company_user->userid . ", companyid: " . $verify_company_user->companyid . ", managertype: " . $verify_company_user->managertype . ", educator: " . $verify_company_user->educator);
                        } else {
                            error_log("CRITICAL ERROR: Company user record NOT found after creation!");
                            throw new Exception("Failed to associate teacher with school. Teacher will not be visible in teacher management.");
                        }
                    } else {
                        error_log("CRITICAL ERROR: No company info found - teacher will be created but not associated with any company");
                        throw new Exception("Unable to determine school information. Please contact administrator.");
                }
                
                // Verify user can authenticate before committing
                $created_user = $DB->get_record('user', ['id' => $user_id]);
                if (!$created_user || $created_user->suspended || $created_user->deleted || !$created_user->confirmed) {
                    throw new Exception('User account verification failed. User may not be able to log in.');
                }
                
                // Commit transaction
                $transaction->allow_commit();
                
                    // Set success flag and redirect
                    $success_message = 'Teacher "' . $user_data->firstname . ' ' . $user_data->lastname . '" has been successfully added to ' . ($company_info ? $company_info->name : 'the school') . ' with editingteacher role! The teacher can now log in with their credentials.';
                    redirect(
                        $CFG->wwwroot . '/theme/remui_kids/teacher_management.php',
                        $success_message,
                        null,
                        \core\output\notification::NOTIFY_SUCCESS
                    );
                    
            } else {
                    throw new Exception('Failed to create user account.');
            }
            
        } catch (Exception $e) {
                // Rollback transaction on error
                if (isset($transaction)) {
                    try {
                $transaction->rollback($e);
                    } catch (Exception $rollback_exception) {
                        // Ignore rollback errors
                    }
                }
                $errors[] = 'Error creating teacher: ' . $e->getMessage();
            }
        }
    }
}

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info ? $company_info->name : 'School Manager',
    'company_logo_url' => '', // Removed logo URL
    'has_logo' => false, // Set has_logo to false
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'teachers_active' => true, // This page is for teacher management
    'dashboard_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'bulk_download_active' => false,
    'bulk_profile_upload_active' => false,
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

// Render the school manager sidebar using the correct Moodle method
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='school-manager-sidebar' style='position: fixed; top: 0; left: 0; width: 280px; height: 100vh; background: #d4edda; z-index: 1000;'>";
    echo "<div style='padding: 20px; text-align: center;'>";
    echo "<h2 style='color: #2c3e50; margin: 0;'>" . htmlspecialchars($sidebarcontext['company_name']) . "</h2>";
    echo "<p style='color: #495057; margin: 10px 0;'>" . htmlspecialchars($sidebarcontext['user_info']['fullname']) . "</p>";
    echo "<div style='background: #007bff; color: white; padding: 5px 15px; border-radius: 15px; display: inline-block;'>School Manager</div>";
    echo "</div>";
    echo "<nav style='padding: 20px 0;'>";
    echo "<a href='{$CFG->wwwroot}/my/' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>School Admin Dashboard</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' style='display: block; padding: 15px 20px; color: #007cba; background: #e3f2fd; text-decoration: none; font-weight: 600;'>Teacher Management</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>Student Management</a>";
    echo "</nav>";
    echo "</div>";
}

// Custom CSS for the add teacher layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    padding: 20px 20px 20px 20px;
    padding-top: 35px;
    min-height: 100vh;
    max-width: 1400px;
    margin: 0 auto;
}

/* Page Header */
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

/* Back Button */
.back-button {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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

/* Form Container */
.form-container {
    background: white;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
    margin-bottom: 30px;
}

.form-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1a202c;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e2e8f0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 0.9rem;
}

.required {
    color: #dc2626;
}

.form-input, .form-select, .form-textarea {
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #4a5568;
    transition: all 0.3s ease;
    background: white;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 120px;
    height: 120px;
}

.notes-group .form-textarea {
    min-height: 120px;
    height: 120px;
}

/* Profile Image Upload Styles */
.form-row-horizontal {
    display: flex;
    gap: 25px;
    margin-bottom: 20px;
    align-items: flex-start;
}

.notes-group {
    flex: 2;
    min-width: 0;
}

.profile-image-group {
    flex: 1;
    max-width: 350px;
    min-width: 300px;
}

.image-upload-container {
    position: relative;
}

.image-upload-input {
    display: none;
}

.image-upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #f9fafb;
    min-height: 120px;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.image-upload-area:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: #6b7280;
}

.upload-placeholder i {
    font-size: 32px;
    color: #9ca3af;
}

.upload-placeholder span {
    font-weight: 500;
    font-size: 14px;
}

.upload-placeholder small {
    font-size: 12px;
    color: #9ca3af;
}

.image-preview {
    max-width: 100%;
    max-height: 120px;
    border-radius: 8px;
    object-fit: cover;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.image-upload-area.has-image {
    border-color: #10b981;
    background: #f0fdf4;
}

.image-upload-area.has-image .upload-placeholder {
    display: none;
}

/* Delete Image Button */
.delete-image-btn {
    margin-top: 10px;
    padding: 0;
    background: transparent;
    color: #dc2626;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: none;
    text-align: right;
    width: 100%;
}

.delete-image-btn:hover {
    color: #b91c1c;
    transform: translateX(-2px);
}

.delete-image-btn i {
    margin-right: 5px;
}

/* Button Styles */
.button-group {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #e2e8f0;
    color: #4a5568;
}

.btn-secondary:hover {
    background: #cbd5e0;
    color: #2d3748;
    text-decoration: none;
}

/* Error Messages */
.error-messages {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
}

.error-messages ul {
    margin: 0;
    padding-left: 20px;
    color: #dc2626;
}

.error-messages li {
    margin-bottom: 5px;
}

/* Success Messages */
.success-messages {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
    color: #166534;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .school-manager-main-content {
        position: relative;
        left: 0;
        right: 0;
        bottom: auto;
        overflow-y: visible;
    }
    
    .main-content {
        padding: 20px;
        min-height: 100vh;
    }
}

@media (max-width: 992px) {
    .form-row-horizontal {
        flex-direction: column;
    }
    
    .profile-image-group {
        max-width: 100%;
        width: 100%;
    }
}

@media (max-width: 768px) {
    .school-manager-main-content {
        position: relative;
        left: 0;
        right: 0;
        bottom: auto;
        overflow-y: visible;
    }
    
    .main-content {
        padding: 20px;
        min-height: 100vh;
    }
    
    .page-title {
        font-size: 2rem;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .button-group {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
";
echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Add New Teacher</h1>";
echo "<p class='page-subtitle'>Add a new teacher to " . htmlspecialchars($company_info ? $company_info->name : 'your school') . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Teacher Management";
echo "</a>";
echo "</div>";

// Display errors if any
if (!empty($errors)) {
    echo "<div class='error-messages'>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

// Form Container
echo "<div class='form-container'>";
echo "<h2 class='form-title'>Teacher Information</h2>";

// Use current page URL for form action
$form_action = new moodle_url('/theme/remui_kids/school_manager/add_teacher.php');

echo "<form method='POST' action='" . $form_action->out() . "' enctype='multipart/form-data'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<div class='form-grid'>";

// Username
echo "<div class='form-group'>";
echo "<label for='username' class='form-label'>Username <span class='required'>*</span></label>";
echo "<input type='text' id='username' name='username' class='form-input' value='" . s($_POST['username'] ?? '') . "' required>";
echo "</div>";

// Password
echo "<div class='form-group'>";
echo "<label for='password' class='form-label'>Password <span class='required'>*</span></label>";
echo "<input type='password' id='password' name='password' class='form-input' required>";
echo "</div>";

// First Name
echo "<div class='form-group'>";
echo "<label for='firstname' class='form-label'>First Name <span class='required'>*</span></label>";
echo "<input type='text' id='firstname' name='firstname' class='form-input' value='" . s($_POST['firstname'] ?? '') . "' required>";
echo "</div>";

// Last Name
echo "<div class='form-group'>";
echo "<label for='lastname' class='form-label'>Last Name <span class='required'>*</span></label>";
echo "<input type='text' id='lastname' name='lastname' class='form-input' value='" . s($_POST['lastname'] ?? '') . "' required>";
echo "</div>";

// Email
echo "<div class='form-group'>";
echo "<label for='email' class='form-label'>Email Address <span class='required'>*</span></label>";
echo "<input type='email' id='email' name='email' class='form-input' value='" . s($_POST['email'] ?? '') . "' required>";
echo "</div>";

// Phone
echo "<div class='form-group'>";
echo "<label for='phone' class='form-label'>Phone Number</label>";
echo "<input type='tel' id='phone' name='phone' class='form-input' value='" . s($_POST['phone'] ?? '') . "'>";
echo "</div>";

// City
echo "<div class='form-group'>";
echo "<label for='city' class='form-label'>City</label>";
echo "<input type='text' id='city' name='city' class='form-input' value='" . s($_POST['city'] ?? '') . "'>";
echo "</div>";

// Country
echo "<div class='form-group'>";
echo "<label for='country' class='form-label'>Country</label>";
echo "<select id='country' name='country' class='form-select'>";
echo "<option value=''>Select Country</option>";
$countries = ['US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia', 
              'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'IN' => 'India', 'PK' => 'Pakistan', 
              'BD' => 'Bangladesh', 'EG' => 'Egypt'];
foreach ($countries as $code => $name) {
    $selected = (($_POST['country'] ?? '') === $code) ? ' selected' : '';
    echo "<option value='$code'$selected>$name</option>";
}
echo "</select>";
echo "</div>";

echo "</div>"; // End form-grid

// Notes and Profile Image Section (Side by Side)
echo "<div class='form-row-horizontal'>";

// Notes (Left Side)
echo "<div class='form-group notes-group'>";
echo "<label for='notes' class='form-label'>Additional Notes</label>";
echo "<textarea id='notes' name='notes' class='form-textarea' placeholder='Any additional information about the teacher...'>" . s($_POST['notes'] ?? '') . "</textarea>";
echo "</div>";

// Profile Image Upload Section (Right Side)
echo "<div class='form-group profile-image-group'>";
echo "<label for='profile_image' class='form-label'>Profile Image</label>";
echo "<div class='image-upload-container'>";
echo "<input type='file' id='profile_image' name='profile_image' accept='image/*' class='image-upload-input' style='display: none;'>";
echo "<div class='image-upload-area' onclick='document.getElementById(\"profile_image\").click()'>";
echo "<div class='upload-placeholder'>";
echo "<i class='fa fa-image'></i>";
echo "<span>Click to upload profile image</span>";
echo "<small>JPG, PNG, GIF (Max 2MB)</small>";
echo "</div>";
echo "<img id='image_preview' class='image-preview' style='display: none;' alt='Profile preview'>";
echo "</div>";
echo "<button type='button' class='delete-image-btn' id='delete_image_btn' onclick='deleteImage()'>";
echo "<i class='fa fa-trash'></i> Delete Image";
echo "</button>";
echo "</div>";
echo "</div>";

echo "</div>"; // End form-row-horizontal

// Button Group
echo "<div class='button-group'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='fa fa-plus'></i> Add Teacher";
echo "</button>";
echo "</div>";

echo "</form>";
echo "</div>"; // End form-container

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// JavaScript for image preview
echo "<script>
// Preview image from file input
function previewImageFromFile(file) {
    const preview = document.getElementById('image_preview');
    const uploadArea = document.querySelector('.image-upload-area');
    const deleteBtn = document.getElementById('delete_image_btn');
    
    if (file) {
        // Validate file size (2MB max)
        if (file.size > 2 * 1024 * 1024) {
            alert('File size must be less than 2MB');
            return;
        }
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            uploadArea.classList.add('has-image');
            deleteBtn.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
}

// Delete image
function deleteImage() {
    const preview = document.getElementById('image_preview');
    const uploadArea = document.querySelector('.image-upload-area');
    const deleteBtn = document.getElementById('delete_image_btn');
    const fileInput = document.getElementById('profile_image');
    
    preview.src = '';
    preview.style.display = 'none';
    uploadArea.classList.remove('has-image');
    deleteBtn.style.display = 'none';
    fileInput.value = '';
}

// Handle file input change
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('profile_image');
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            previewImageFromFile(this.files[0]);
        }
    });
    
    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            if (file.size > 2 * 1024 * 1024) {
                e.preventDefault();
                alert('Image file size must be less than 2MB');
                return false;
            }
        }
    });
});
</script>";

echo $OUTPUT->footer();
?>