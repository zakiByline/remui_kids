<?php
require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT;

// Check if user has admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// For now, skip company manager checks and use default company info
$company_info = new stdClass();
$company_info->name = 'Default School';
$company_info->id = 1;

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/add_teacher.php');
$PAGE->set_title('Add Teacher - ' . $company_info->name);
$PAGE->set_heading('Add Teacher');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_teacher'])) {
    
    // Debug: Log form submission
    error_log('Form submitted with data: ' . print_r($_POST, true));
    
    // Validate required fields
    $required_fields = ['username', 'firstname', 'lastname', 'email'];
    $errors = [];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst($field) . ' is required.';
        }
    }
    
    // Validate email format
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    // Check if username already exists
    if (!empty($_POST['username']) && $DB->record_exists('user', ['username' => $_POST['username']])) {
        $errors[] = 'Username already exists. Please choose a different username.';
    }
    
    // Check if email already exists
    if (!empty($_POST['email']) && $DB->record_exists('user', ['email' => $_POST['email']])) {
        $errors[] = 'Email already exists. Please use a different email address.';
    }
    
    // Validate password
    if (empty($_POST['password']) || strlen($_POST['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
        } else {
        $password = $_POST['password'];
        $password_errors = [];
        
        if (!preg_match('/[a-z]/', $password)) {
            $password_errors[] = 'at least 1 lowercase letter';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $password_errors[] = 'at least 1 uppercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $password_errors[] = 'at least 1 number';
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $password_errors[] = 'at least 1 special character';
        }
        
        if (!empty($password_errors)) {
            $errors[] = 'Password must contain ' . implode(', ', $password_errors) . '.';
        }
    }
    
    if (empty($errors)) {
        try {
            // Start transaction for data integrity
            $transaction = $DB->start_delegated_transaction();
            
            // Use Moodle's built-in user creation function for better reliability
            $user_data = new stdClass();
            $user_data->username = trim($_POST['username']);
            $user_data->password = $_POST['password']; // Will be hashed by create_user
            $user_data->firstname = trim($_POST['firstname']);
            $user_data->lastname = trim($_POST['lastname']);
            $user_data->email = trim($_POST['email']);
            $user_data->city = !empty($_POST['city']) ? trim($_POST['city']) : '';
            $user_data->country = !empty($_POST['country']) ? $_POST['country'] : '';
            $user_data->phone1 = !empty($_POST['phone']) ? trim($_POST['phone']) : '';
            $user_data->description = !empty($_POST['description']) ? trim($_POST['description']) : '';
            $user_data->auth = 'manual';
            $user_data->confirmed = 1;
            $user_data->mnethostid = $CFG->mnet_localhost_id;
            
            // Debug: Log the user data being created
            error_log('Creating user with Moodle function: ' . $user_data->username);
            
            // Use Moodle's user creation function
            $user_id = user_create_user($user_data, true, true);
            
            if ($user_id) {
                // Assign teacher role
                $teacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
                if ($teacher_role) {
                    role_assign($teacher_role->id, $user_id, $context->id);
                    error_log('Teacher role assigned to user: ' . $user_id);
                }
                
                // Add user to company
                $company_user = new stdClass();
                $company_user->userid = $user_id;
                $company_user->companyid = $company_info->id;
                $company_user->managertype = 0; // Regular user, not manager
                $company_user->timecreated = time();
                $company_user->timemodified = time();
                
                $DB->insert_record('company_users', $company_user);
                error_log('User added to company: ' . $company_info->id);
                try {
                    if ($DB->get_manager()->table_exists('company_users') && !empty($company_info->id)) {
                        $company_user = new stdClass();
                        $company_user->userid = $user_id;
                        $company_user->companyid = $company_info->id;
                        $company_user->managertype = 0; // Regular user, not manager
                        $company_user->timecreated = time();
                        $company_user->timemodified = time();
                        $DB->insert_record('company_users', $company_user);
                    }
                } catch (Exception $e) {
                    error_log('Skipping company_users insert: ' . $e->getMessage());
                }
                
                // Store grade information if provided
                if (!empty($_POST['grade'])) {
                    // Try to find or create a custom field for grade
                    $grade_field = $DB->get_record('user_info_field', ['shortname' => 'grade']);
                    if (!$grade_field) {
                        // Create grade field if it doesn't exist
                        $grade_field = new stdClass();
                        $grade_field->shortname = 'grade';
                        $grade_field->name = 'Grade';
                        $grade_field->datatype = 'text';
                        $grade_field->description = 'Teacher Grade Level';
                        $grade_field->descriptionformat = FORMAT_HTML;
                        $grade_field->categoryid = 1;
                        $grade_field->sortorder = 1;
                        $grade_field->required = 0;
                        $grade_field->locked = 0;
                        $grade_field->visible = 1;
                        $grade_field->forceunique = 0;
                        $grade_field->signup = 0;
                        $grade_field->defaultdata = '';
                        $grade_field->defaultdataformat = FORMAT_HTML;
                        $grade_field->param1 = '30';
                        $grade_field->param2 = '2048';
                        $grade_field->param3 = '0';
                        $grade_field->param4 = '';
                        $grade_field->param5 = '';
                        
                        $grade_field_id = $DB->insert_record('user_info_field', $grade_field);
                        $grade_field->id = $grade_field_id;
                        error_log('Grade field created: ' . $grade_field_id);
                    }
                    
                    // Check if grade data already exists
                    $existing_grade = $DB->get_record('user_info_data', [
                        'userid' => $user_id,
                        'fieldid' => $grade_field->id
                    ]);
                    
                    if ($existing_grade) {
                        // Update existing grade
                        $existing_grade->data = $_POST['grade'];
                        $existing_grade->dataformat = FORMAT_HTML;
                        $DB->update_record('user_info_data', $existing_grade);
                    } else {
                        // Add new grade data for user
                        $grade_data = new stdClass();
                        $grade_data->userid = $user_id;
                        $grade_data->fieldid = $grade_field->id;
                        $grade_data->data = $_POST['grade'];
                        $grade_data->dataformat = FORMAT_HTML;
                        
                        $DB->insert_record('user_info_data', $grade_data);
                        error_log('Grade data added for user: ' . $user_id);
                    }
                }
                
                // Get the created user for success message
                $created_user = $DB->get_record('user', ['id' => $user_id], 'id, firstname, lastname, email');
                
                // Commit transaction
                $transaction->allow_commit();
                
                // Redirect back with success message
                redirect(new moodle_url('/theme/remui_kids/admin/teachers_list.php'), 
                    'Teacher "' . $created_user->firstname . ' ' . $created_user->lastname . '" has been successfully added!', 
                    null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                $errors[] = 'Failed to create user account.';
            }
            
        } catch (Exception $e) {
            // Rollback transaction if it exists
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            
            // Log the full error for debugging
            error_log('Error creating teacher: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $error_message = $e->getMessage();
            
            // Clean up HTML tags from error messages
            $error_message = strip_tags($error_message);
            $error_message = html_entity_decode($error_message);
            
            // Add more specific error information
            if (strpos($error_message, 'Duplicate entry') !== false) {
                $errors[] = 'Username or email already exists. Please choose different values.';
            } elseif (strpos($error_message, 'Data too long') !== false) {
                $errors[] = 'One or more fields contain data that is too long.';
            } elseif (strpos($error_message, 'Incorrect string value') !== false) {
                $errors[] = 'Invalid characters in one or more fields.';
            } elseif (strpos($e->getMessage(), 'required') !== false) {
                $errors[] = 'Missing required fields. Please fill in all required information.';
            } elseif (strpos($error_message, 'lower case letter') !== false || 
                      strpos($error_message, 'upper case letter') !== false || 
                      strpos($error_message, 'special character') !== false) {
                $errors[] = 'Password must contain at least 1 lowercase letter, 1 uppercase letter, and 1 special character.';
            } else {
                $errors[] = 'Error creating teacher: ' . $error_message;
            }
        }
    }
}

echo $OUTPUT->header();

// Add custom CSS with pastel green theme and sidebar (no shared styles to avoid conflicts)
echo "<style>";
echo "
     /* Reset any conflicting styles */
     body {
         overflow-x: hidden !important;
         overflow-y: hidden !important;
         margin: 0 !important;
         padding: 0 !important;
     }
     
     /* Ensure sidebar doesn't interfere */
     .admin-sidebar {
         position: fixed !important;
         left: 0 !important;
         top: 0 !important;
         width: 280px !important;
         height: 100vh !important;
         z-index: 1000 !important;
         background: white !important;
         border-right: 1px solid #e9ecef !important;
         box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1) !important;
         overflow-y: auto !important;
     }
     
     .admin-sidebar .sidebar-content {
         padding: 6rem 0 2rem 0 !important;
     }
     
     /* Main content positioning */
     .admin-main-content {
         position: fixed !important;
         top: 0 !important;
         left: 280px !important;
         width: calc(100vw - 280px) !important;
         height: 100vh !important;
         background-color: #ffffff !important;
         overflow-y: auto !important;
         z-index: 99 !important;
         padding-top: 80px !important;
     }
     
     /* Ensure header is fully visible */
    .add-header {
         margin-top: 0 !important;
         position: relative !important;
         top: 0 !important;
         left: 0 !important;
         right: 0 !important;
         bottom: auto !important;
     }
     
     /* Custom Scrollbar Styling */
     .admin-main-content::-webkit-scrollbar {
         width: 8px;
     }
     
     .admin-main-content::-webkit-scrollbar-track {
         background: #f1f5f9;
         border-radius: 4px;
     }
     
     .admin-main-content::-webkit-scrollbar-thumb {
         background: #22c55e;
         border-radius: 4px;
     }
     
     .admin-main-content::-webkit-scrollbar-thumb:hover {
         background: #16a34a;
     }
     
     /* Firefox scrollbar */
     .admin-main-content {
         scrollbar-width: thin;
         scrollbar-color: #22c55e #f1f5f9;
     }
     
     .add-container {
         max-width: 1600px;
         margin: 0 auto;
         padding: 20px;
         min-height: auto;
         overflow: visible;
         margin-top: 0;
         position: relative;
         top: 0;
     }
    
      .add-header {
          background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
          color: #15803d;
          padding: 40px 30px;
          text-align: center;
          border-radius: 12px;
        margin-bottom: 40px;
          min-height: 150px;
          overflow: visible;
        display: flex;
          flex-direction: column;
          justify-content: center;
        align-items: center;
      }
     
     .add-title {
         font-size: 1.8rem;
         font-weight: 600;
         margin-bottom: 8px;
         color: #15803d;
         line-height: 1.2;
     }
     
     .add-subtitle {
         font-size: 1rem;
         color: #16a34a;
         opacity: 0.9;
         margin: 0;
         line-height: 1.3;
     }
    
    
    .form-section {
        margin-bottom: 40px;
          padding: 35px;
          background: #f0fdf4;
          border-radius: 12px;
          border-left: 4px solid #22c55e;
          box-shadow: 0 2px 8px rgba(34, 197, 94, 0.1);
          min-height: 200px;
    }
    
    .section-title {
         font-size: 1.2rem;
        font-weight: 600;
         color: #15803d;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .form-row {
        display: grid;
         grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
         gap: 20px;
         margin-bottom: 0;
    }
    
    .form-group {
         margin-bottom: 0;
    }
    
    .form-label {
        display: block;
         font-weight: 500;
         color: #374151;
        margin-bottom: 8px;
         font-size: 0.9rem;
     }

     .form-label.required::after {
         content: ' *';
         color: #dc2626;
    }
    
    .form-control {
        width: 100%;
         padding: 12px 16px;
         border: 2px solid #d1fae5;
         border-radius: 8px;
        font-size: 1rem;
        background: white;
         transition: all 0.2s ease;
    }
    
    .form-control:focus {
        outline: none;
         border-color: #22c55e;
         box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
    }
    
    .form-control:hover {
        border-color: #bbf7d0;
    }
    
    .password-strength {
        margin-top: 5px;
        font-size: 0.85rem;
    }
    
      .form-actions {
        display: flex;
          gap: 15px;
        justify-content: center;
          padding: 40px 0;
          border-top: 1px solid #e5e7eb;
        margin-top: 40px;
          min-height: 100px;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
         gap: 8px;
         padding: 12px 24px;
         border-radius: 8px;
         font-weight: 500;
         text-decoration: none;
         border: none;
         cursor: pointer;
         transition: all 0.2s ease;
         font-size: 1rem;
    }
    
    .btn-primary {
         background: #22c55e;
        color: white;
    }
    
    .btn-primary:hover {
         background: #16a34a;
         color: white;
         text-decoration: none;
    }
    
    .btn-secondary {
         background: #f3f4f6;
         color: #374151;
         border: 2px solid #d1d5db;
    }
    
    .btn-secondary:hover {
         background: #e5e7eb;
         color: #374151;
         text-decoration: none;
    }
    
    .alert {
         padding: 15px 20px;
         border-radius: 8px;
         margin-bottom: 20px;
        font-weight: 500;
         border: 1px solid;
    }
    
    .alert-success {
         background: #f0fdf4;
         border-color: #bbf7d0;
         color: #15803d;
    }
    
    .alert-error {
         background: #fef2f2;
         border-color: #fecaca;
         color: #dc2626;
     }

     /* Override shared styles that might cause header cutting */
     .admin-main-content {
         position: fixed !important;
         top: 0 !important;
         left: 280px !important;
         width: calc(100vw - 280px) !important;
         height: 100vh !important;
         background-color: #ffffff !important;
         overflow-y: auto !important;
         z-index: 99 !important;
         padding-top: 80px !important;
     }
     
     .add-container {
         max-width: 1400px !important;
         margin: 0 auto !important;
         padding: 20px !important;
         min-height: auto !important;
         overflow: visible !important;
         background: transparent !important;
         position: relative !important;
         z-index: 10 !important;
         margin-top: 0 !important;
         top: 0 !important;
     }
     
     .add-header {
         background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%) !important;
         color: #15803d !important;
         padding: 40px 30px !important;
         text-align: center !important;
         border-radius: 12px !important;
         margin-bottom: 40px !important;
         min-height: 150px !important;
         overflow: visible !important;
         height: auto !important;
         position: relative !important;
         z-index: 20 !important;
         display: flex !important;
         flex-direction: column !important;
         justify-content: center !important;
         align-items: center !important;
         box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
     }

     /* Mobile responsive */
     @media (max-width: 768px) {
         .admin-sidebar {
             position: fixed !important;
             top: 0 !important;
             left: 0 !important;
             width: 280px !important;
             height: 100vh !important;
             transform: translateX(-100%) !important;
             transition: transform 0.3s ease !important;
             z-index: 1001 !important;
         }
         
         .admin-sidebar.sidebar-open {
             transform: translateX(0) !important;
         }
         
         .admin-main-content {
             position: relative !important;
             left: 0 !important;
             width: 100vw !important;
             height: auto !important;
             min-height: 100vh !important;
             padding-top: 20px !important;
         }
         
         .add-container {
             padding: 15px;
         }
         
         .add-header {
             padding: 15px 20px;
         }
         
         .add-title {
             font-size: 1.5rem;
         }
         
        .form-row {
            grid-template-columns: 1fr;
         }
         
         .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }
";
echo "</style>";
    

    // Admin Sidebar Navigation (copied from courses.php)
    // Include admin sidebar from includes
    require_once(__DIR__ . '/includes/admin_sidebar.php');

    // Main content area with sidebar
    echo "<div class='admin-main-content'>";

   

    echo "<div class='add-container'>";
    echo "<div class='add-header'>";
    echo "<h1 class='add-title'>Add New Teacher</h1>";
    echo "<p class='add-subtitle'>Create a new teacher account with full access</p>";
    echo "</div>";

    echo "<div class='add-form'>";

    // Show success/error messages
    if (isset($success_message)) {
        echo "<div class='alert alert-success'>";
        echo "<i class='fa fa-check-circle'></i> $success_message";
        echo "</div>";
    }

    if (isset($errors) && !empty($errors)) {
        echo "<div class='alert alert-error'>";
        echo "<i class='fa fa-exclamation-circle'></i> Please fix the following errors:";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

     echo "<form method='POST' action='' class='teacher-form'>";

    // Personal Information Section
    echo "<div class='form-section'>";
    echo "<h3 class='section-title'>";
    echo "<i class='fa fa-user'></i> Personal Information";
    echo "</h3>";
    echo "<div class='form-row'>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>First Name</label>";
    echo "<input type='text' class='form-control' name='firstname' value='" . (isset($_POST['firstname']) ? htmlspecialchars($_POST['firstname']) : '') . "' required>";
    echo "</div>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>Last Name</label>";
    echo "<input type='text' class='form-control' name='lastname' value='" . (isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : '') . "' required>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

    // Account Information Section
    echo "<div class='form-section'>";
    echo "<h3 class='section-title'>";
    echo "<i class='fa fa-key'></i> Account Information";
    echo "</h3>";
    echo "<div class='form-row'>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>Username</label>";
    echo "<input type='text' class='form-control' name='username' value='" . (isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '') . "' required>";
    echo "</div>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>Email Address</label>";
    echo "<input type='email' class='form-control' name='email' value='" . (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '') . "' required>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

    // Security Section
    echo "<div class='form-section'>";
    echo "<h3 class='section-title'>";
    echo "<i class='fa fa-shield-alt'></i> Security";
    echo "</h3>";
    echo "<div class='form-row'>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>Password</label>";
    echo "<input type='password' class='form-control' name='password' id='password' required>";
    echo "<div class='password-strength' id='password-strength'></div>";
    echo "</div>";
    echo "<div class='form-group'>";
     echo "<label class='form-label required'>Confirm Password</label>";
    echo "<input type='password' class='form-control' name='confirm_password' required>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

     echo "<div class='form-actions'>";
     echo "<button type='submit' name='submit_teacher' class='btn btn-primary'>";
    echo "<i class='fa fa-user-plus'></i> Create Teacher";
    echo "</button>";
    echo "<a href='teachers_list.php' class='btn btn-secondary'>";
    echo "<i class='fa fa-arrow-left'></i> Back to Teachers";
    echo "</a>";
    echo "</div>";

    echo "</form>";
    echo "</div>";
    echo "</div>";

     // Add all JavaScript in one block
    echo "<script>
// Password strength checker
document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const strengthDiv = document.getElementById('password-strength');
    
    if (password.length === 0) {
        strengthDiv.innerHTML = '<small style=\"color: #666;\">Password requirements: at least 6 characters, 1 lowercase, 1 uppercase, 1 number, 1 special character</small>';
        return;
    }
    
    let requirements = [];
    let strength = 0;
    
    if (password.length >= 6) {
        requirements.push('✓ Length (6+ chars)');
        strength++;
    } else {
        requirements.push('✗ Length (6+ chars)');
    }
    
    if (password.match(/[a-z]/)) {
        requirements.push('✓ Lowercase letter');
        strength++;
    } else {
        requirements.push('✗ Lowercase letter');
    }
    
    if (password.match(/[A-Z]/)) {
        requirements.push('✓ Uppercase letter');
        strength++;
    } else {
        requirements.push('✗ Uppercase letter');
    }
    
    if (password.match(/[0-9]/)) {
        requirements.push('✓ Number');
        strength++;
    } else {
        requirements.push('✗ Number');
    }
    
    if (password.match(/[^a-zA-Z0-9]/)) {
        requirements.push('✓ Special character');
        strength++;
    } else {
        requirements.push('✗ Special character');
    }
    
    let message = requirements.join('<br>');
    
    if (strength < 3) {
        strengthDiv.className = 'password-strength strength-weak';
        strengthDiv.style.color = '#dc2626';
    } else if (strength < 5) {
        strengthDiv.className = 'password-strength strength-medium';
        strengthDiv.style.color = '#d97706';
    } else {
        strengthDiv.className = 'password-strength strength-strong';
        strengthDiv.style.color = '#16a34a';
    }
    
    strengthDiv.innerHTML = message;
});

// Sidebar toggle function
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});
</script>";

// Close admin-main-content div
echo "</div>";

echo $OUTPUT->footer();
