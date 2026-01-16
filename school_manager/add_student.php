<?php
/**
 * Add New Student - School Manager
 * Form for adding new students to the school
 */

// Use __DIR__ for reliable path resolution
require_once(__DIR__ . '/../../../config.php');
require_login();

/**
 * Determine grade level from cohort name
 * @param string $cohort_name The name of the cohort
 * @return string The grade level
 */
function determine_grade_level_from_cohort($cohort_name) {
    $cohort_name = strtolower(trim($cohort_name));
    
    // Check for specific grade patterns
    if (preg_match('/grade\s*1\b/', $cohort_name)) return 'Grade 1';
    if (preg_match('/grade\s*2\b/', $cohort_name)) return 'Grade 2';
    if (preg_match('/grade\s*3\b/', $cohort_name)) return 'Grade 3';
    if (preg_match('/grade\s*4\b/', $cohort_name)) return 'Grade 4';
    if (preg_match('/grade\s*5\b/', $cohort_name)) return 'Grade 5';
    if (preg_match('/grade\s*6\b/', $cohort_name)) return 'Grade 6';
    if (preg_match('/grade\s*7\b/', $cohort_name)) return 'Grade 7';
    if (preg_match('/grade\s*8\b/', $cohort_name)) return 'Grade 8';
    if (preg_match('/grade\s*9\b/', $cohort_name)) return 'Grade 9';
    if (preg_match('/grade\s*10\b/', $cohort_name)) return 'Grade 10';
    if (preg_match('/grade\s*11\b/', $cohort_name)) return 'Grade 11';
    if (preg_match('/grade\s*12\b/', $cohort_name)) return 'Grade 12';
    
    // Check for general grade ranges
    if (preg_match('/grade\s*[1-3]/', $cohort_name)) return 'Elementary';
    if (preg_match('/grade\s*[4-7]/', $cohort_name)) return 'Middle School';
    if (preg_match('/grade\s*[8-9]|grade\s*1[0-2]/', $cohort_name)) return 'High School';
    
    // Check for other patterns
    if (strpos($cohort_name, 'elementary') !== false) return 'Elementary';
    if (strpos($cohort_name, 'middle') !== false) return 'Middle School';
    if (strpos($cohort_name, 'high') !== false) return 'High School';
    if (strpos($cohort_name, 'primary') !== false) return 'Primary';
    if (strpos($cohort_name, 'secondary') !== false) return 'Secondary';
    
    // Default fallback
    return 'Grade Level';
}

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
    error_log("Looking for company info for user ID: " . $USER->id);
    
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    if ($company_info) {
        error_log("Found company info (primary query): " . $company_info->name . " (ID: " . $company_info->id . ")");
    } else {
        error_log("Primary company query failed, trying alternative...");
        
        // Try alternative query to find company info if first query fails
        $company_info = $DB->get_record_sql(
            "SELECT c.* 
             FROM {company} c 
             JOIN {company_users} cu ON c.id = cu.companyid 
             WHERE cu.userid = ?",
            [$USER->id]
        );
        
        if ($company_info) {
            error_log("Found company info (alternative query): " . $company_info->name . " (ID: " . $company_info->id . ")");
        } else {
            error_log("No company info found for user ID: " . $USER->id);
        }
    }
} else {
    error_log("Company or company_users table does not exist");
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/add_student.php'));
$PAGE->set_title('Add New Student');
$PAGE->set_heading('Add New Student');
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
        $required_fields = ['username', 'password', 'firstname', 'lastname', 'email', 'cohort_id'];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        
        // Validate email format
        if (!empty($_POST['email']) && !validate_email($_POST['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Convert username to lowercase and validate
        if (!empty($_POST['username'])) {
            $username = strtolower(trim($_POST['username']));
            
            // Validate username format (alphanumeric, dots, underscores, hyphens only)
            if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
                $errors[] = 'Username can only contain lowercase letters, numbers, dots, underscores, and hyphens.';
            }
            
            // Check if username already exists
            if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
                $errors[] = 'Username already exists. Please choose a different username.';
            }
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
        
        // Validate cohort exists
        if (!empty($_POST['cohort_id'])) {
            $cohort = $DB->get_record('cohort', ['id' => $_POST['cohort_id'], 'visible' => 1]);
            if (!$cohort) {
                $errors[] = 'Selected cohort is not valid or not available.';
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
                $user_data->username = strtolower(trim($_POST['username'])); // Ensure username is lowercase
                $user_data->password = hash_internal_user_password($_POST['password']); // Hash password using Moodle's function
                error_log("Password hashed for student: " . $user_data->username . " - Hash: " . substr($user_data->password, 0, 20) . "...");
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
                    error_log("User created successfully with ID: " . $user_id);
                    
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
                    error_log("Error creating user: " . $user_error->getMessage());
                    throw new Exception('Error creating user: ' . $user_error->getMessage());
                }
                
                if ($user_id) {
                    // Assign student role in system context
                    $student_role = $DB->get_record('role', ['shortname' => 'student']);
                    if ($student_role) {
                        try {
                            role_assign($student_role->id, $user_id, $context->id);
                            error_log("Student role assigned successfully to user ID: " . $user_id . " with role ID: " . $student_role->id . " in context ID: " . $context->id);
                            
                            // Verify role assignment was created
                            $verify_role_assignment = $DB->get_record('role_assignments', [
                                'userid' => $user_id, 
                                'roleid' => $student_role->id, 
                                'contextid' => $context->id
                            ]);
                            if ($verify_role_assignment) {
                                error_log("Verification: Student role assignment exists - userid: " . $verify_role_assignment->userid . ", roleid: " . $verify_role_assignment->roleid);
                            } else {
                                error_log("ERROR: Student role assignment NOT found after creation!");
                            }
                        } catch (Exception $role_error) {
                            error_log("Error assigning student role: " . $role_error->getMessage());
                            throw new Exception('Failed to assign student role: ' . $role_error->getMessage());
                        }
                    } else {
                        error_log("ERROR: Student role not found in database");
                    }
                    
                    // Add user to company if company info exists - CRITICAL for student listing
                    if ($company_info) {
                        try {
                            // Check if company_users table exists
                            if ($DB->get_manager()->table_exists('company_users')) {
                                // Check if user is already in company_users (avoid duplicates)
                                $existing_company_user = $DB->get_record('company_users', [
                                    'userid' => $user_id,
                                    'companyid' => $company_info->id
                                ]);
                                
                                if (!$existing_company_user) {
                                    // Get existing company user record to see what fields are required
                                    $sample_company_user = $DB->get_record('company_users', ['companyid' => $company_info->id], '*', IGNORE_MULTIPLE);
                                    
                                    $company_user = new stdClass();
                                    $company_user->userid = $user_id;
                                    $company_user->companyid = $company_info->id;
                                    $company_user->managertype = 0; // Regular user, not manager
                                    $company_user->educator = 0; // Student, not educator
                                    $company_user->timecreated = time();
                                    $company_user->timemodified = time();
                                    
                                    // Add any additional fields that might be required
                                    if ($sample_company_user) {
                                        // Copy any additional fields from existing records
                                        foreach ($sample_company_user as $field => $value) {
                                            if (!isset($company_user->$field) && $field !== 'id' && $field !== 'userid' && $field !== 'companyid') {
                                                if (is_numeric($value)) {
                                                    $company_user->$field = 0;
                                                } else {
                                                    $company_user->$field = '';
                                                }
                                            }
                                        }
                                    }
                                    
                                    error_log("Attempting to insert company user record: " . print_r($company_user, true));
                                    
                                    // Try insertion with better error handling
                                    try {
                                        $company_user_id = $DB->insert_record('company_users', $company_user);
                                        error_log("Company user created successfully with ID: " . $company_user_id . " for user ID: " . $user_id . " in company ID: " . $company_info->id);
                                    } catch (Exception $insert_error) {
                                        error_log("Database insertion error: " . $insert_error->getMessage());
                                        error_log("Failed record data: " . print_r($company_user, true));
                                        throw new Exception('Database insertion failed: ' . $insert_error->getMessage());
                                    }
                                    
                                    // Verify company association was created
                                    $verify_company_user = $DB->get_record('company_users', [
                                        'userid' => $user_id,
                                        'companyid' => $company_info->id
                                    ]);
                                    if ($verify_company_user) {
                                        error_log("Verification: Company user association exists - userid: " . $verify_company_user->userid . ", companyid: " . $verify_company_user->companyid . ", educator: " . $verify_company_user->educator);
                                    } else {
                                        error_log("ERROR: Company user association NOT found after creation!");
                                    }
                                } else {
                                    error_log("User already exists in company_users table");
                                }
                            } else {
                                error_log("company_users table does not exist - IOMAD may not be properly installed");
                                // Create a simple user_info_data record as fallback
                                $fallback_field = $DB->get_record('user_info_field', ['shortname' => 'company_association']);
                                if (!$fallback_field) {
                                    $fallback_field = new stdClass();
                                    $fallback_field->shortname = 'company_association';
                                    $fallback_field->name = 'Company Association';
                                    $fallback_field->datatype = 'text';
                                    $fallback_field->description = 'Associated Company ID';
                                    $fallback_field->descriptionformat = FORMAT_HTML;
                                    $fallback_field->categoryid = 1;
                                    $fallback_field->sortorder = 1;
                                    $fallback_field->required = 0;
                                    $fallback_field->locked = 0;
                                    $fallback_field->visible = 1;
                                    $fallback_field->forceunique = 0;
                                    $fallback_field->signup = 0;
                                    $fallback_field->defaultdata = '';
                                    $fallback_field->defaultdataformat = FORMAT_HTML;
                                    $fallback_field->param1 = '30';
                                    $fallback_field->param2 = '';
                                    $fallback_field->param3 = '';
                                    $fallback_field->param4 = '';
                                    $fallback_field->param5 = '';
                                    
                                    $fallback_field_id = $DB->insert_record('user_info_field', $fallback_field);
                                } else {
                                    $fallback_field_id = $fallback_field->id;
                                }
                                
                                // Store company association in user_info_data
                                $company_data = new stdClass();
                                $company_data->userid = $user_id;
                                $company_data->fieldid = $fallback_field_id;
                                $company_data->data = $company_info->id;
                                $company_data->dataformat = FORMAT_HTML;
                                
                                $DB->insert_record('user_info_data', $company_data);
                                error_log("Company association stored in user_info_data for user ID: " . $user_id);
                            }
                        } catch (Exception $company_error) {
                            error_log("Error creating company user: " . $company_error->getMessage());
                            
                            // Try alternative approach - use direct SQL insertion
                            try {
                                error_log("Attempting alternative SQL insertion for company_users...");
                                
                                $sql = "INSERT INTO {company_users} (userid, companyid, managertype, educator, timecreated, timemodified) VALUES (?, ?, ?, ?, ?, ?)";
                                $params = [$user_id, $company_info->id, 0, 0, time(), time()];
                                
                                $company_user_id = $DB->execute($sql, $params);
                                error_log("Alternative SQL insertion successful for user ID: $user_id");
                                
                                // Verify the insertion
                                $verify_company_user = $DB->get_record('company_users', [
                                    'userid' => $user_id,
                                    'companyid' => $company_info->id
                                ]);
                                
                                if (!$verify_company_user) {
                                    throw new Exception('Alternative insertion verification failed');
                                }
                                
                            } catch (Exception $alt_error) {
                                error_log("Alternative insertion also failed: " . $alt_error->getMessage());
                                
                                // Final fallback - store company association in user_info_data
                                try {
                                    error_log("Using final fallback - storing company association in user_info_data...");
                                    
                                    // Create or get company_association field
                                    $company_field = $DB->get_record('user_info_field', ['shortname' => 'company_association']);
                                    if (!$company_field) {
                                        $company_field = new stdClass();
                                        $company_field->shortname = 'company_association';
                                        $company_field->name = 'Company Association';
                                        $company_field->datatype = 'text';
                                        $company_field->description = 'Associated Company ID';
                                        $company_field->descriptionformat = FORMAT_HTML;
                                        $company_field->categoryid = 1;
                                        $company_field->sortorder = 1;
                                        $company_field->required = 0;
                                        $company_field->locked = 0;
                                        $company_field->visible = 1;
                                        $company_field->forceunique = 0;
                                        $company_field->signup = 0;
                                        $company_field->defaultdata = '';
                                        $company_field->defaultdataformat = FORMAT_HTML;
                                        $company_field->param1 = '30';
                                        $company_field->param2 = '';
                                        $company_field->param3 = '';
                                        $company_field->param4 = '';
                                        $company_field->param5 = '';
                                        
                                        $company_field_id = $DB->insert_record('user_info_field', $company_field);
                                        error_log("Created company_association field with ID: " . $company_field_id);
                                    } else {
                                        $company_field_id = $company_field->id;
                                    }
                                    
                                    // Store company association
                                    $company_data = new stdClass();
                                    $company_data->userid = $user_id;
                                    $company_data->fieldid = $company_field_id;
                                    $company_data->data = $company_info->id;
                                    $company_data->dataformat = FORMAT_HTML;
                                    
                                    $DB->insert_record('user_info_data', $company_data);
                                    error_log("Company association stored in user_info_data as fallback for user ID: " . $user_id);
                                    
                                } catch (Exception $fallback_error) {
                                    error_log("Final fallback also failed: " . $fallback_error->getMessage());
                                    throw new Exception('All methods failed to associate student with school. Original error: ' . $company_error->getMessage());
                                }
                            }
                        }
                    } else {
                        error_log("No company info found - user will be created but not associated with any company");
                        error_log("Current user ID: " . $USER->id . ", Company info: " . print_r($company_info, true));
                        throw new Exception('No company information found. Cannot associate student with school.');
                    }
                    
                    // Store grade level if provided
                    if (!empty($_POST['cohort_id'])) {
                        $cohort_id = (int)$_POST['cohort_id'];
                        $cohort = $DB->get_record('cohort', ['id' => $cohort_id]);
                        
                        if ($cohort) {
                            // Add user to cohort
                            $cohort_member = new stdClass();
                            $cohort_member->cohortid = $cohort_id;
                            $cohort_member->userid = $user_id;
                            $cohort_member->timeadded = time();
                            
                            try {
                                $DB->insert_record('cohort_members', $cohort_member);
                                error_log("Student assigned to cohort: " . $cohort->name . " (ID: " . $cohort_id . ") for user ID: " . $user_id);
                                
                                // Determine grade level based on cohort name
                                $grade_level = determine_grade_level_from_cohort($cohort->name);
                        // Try to find or create a custom field for grade
                        $grade_field = $DB->get_record('user_info_field', ['shortname' => 'gradelevel']);
                        if (!$grade_field) {
                            // Create grade field if it doesn't exist
                            $grade_field = new stdClass();
                            $grade_field->shortname = 'gradelevel';
                            $grade_field->name = 'Grade Level';
                            $grade_field->datatype = 'text';
                            $grade_field->description = 'Student Grade Level';
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
                            $grade_field->param2 = '';
                            $grade_field->param3 = '';
                            $grade_field->param4 = '';
                            $grade_field->param5 = '';
                            
                            $grade_field_id = $DB->insert_record('user_info_field', $grade_field);
                        } else {
                            $grade_field_id = $grade_field->id;
                        }
                        
                        // Store grade level data
                        $grade_data = new stdClass();
                        $grade_data->userid = $user_id;
                        $grade_data->fieldid = $grade_field_id;
                        $grade_data->data = $grade_level;
                        $grade_data->dataformat = FORMAT_HTML;
                        
                        $DB->insert_record('user_info_data', $grade_data);
                        error_log("Grade level automatically assigned: " . $grade_level . " for user ID: " . $user_id . " based on cohort: " . $cohort->name);
                        
                    } catch (Exception $cohort_error) {
                        error_log("Error assigning student to cohort: " . $cohort_error->getMessage());
                        // Don't throw exception - cohort assignment is not critical for user creation
                    }
                }
            }
                    
                    // Verify user can authenticate before committing
                    $created_user = $DB->get_record('user', ['id' => $user_id]);
                    if (!$created_user || $created_user->suspended || $created_user->deleted || !$created_user->confirmed) {
                        throw new Exception('User account verification failed. User may not be able to log in.');
                    }
                    
                    // Commit transaction
                    $transaction->allow_commit();
                    
                    // Set success flag and redirect
                    $success_message = 'Student "' . $user_data->firstname . ' ' . $user_data->lastname . '" has been successfully added to ' . ($company_info ? $company_info->name : 'the school') . '! The student can now log in with their credentials.';
                    redirect(
                        $CFG->wwwroot . '/theme/remui_kids/student_management.php',
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
                $errors[] = 'Error creating student: ' . $e->getMessage();
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
    'students_active' => true, // This page is for student management
    'dashboard_active' => false,
    'teachers_active' => false,
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
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>Teacher Management</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' style='display: block; padding: 15px 20px; color: #007cba; background: #e3f2fd; text-decoration: none; font-weight: 600;'>Student Management</a>";
    echo "</nav>";
    echo "</div>";
}

// Custom CSS for the add student layout
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
echo "<h1 class='page-title'>Add New Student</h1>";
echo "<p class='page-subtitle'>Add a new student to " . htmlspecialchars($company_info ? $company_info->name : 'your school') . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Student Management";
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
echo "<h2 class='form-title'>Student Information</h2>";

// Use current page URL for form action
$form_action = new moodle_url('/theme/remui_kids/school_manager/add_student.php');

echo "<form method='POST' action='" . $form_action->out() . "' enctype='multipart/form-data'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<div class='form-grid'>";

// Username
echo "<div class='form-group'>";
echo "<label for='username' class='form-label'>Username <span class='required'>*</span></label>";
echo "<input type='text' id='username' name='username' class='form-input' value='" . s(strtolower($_POST['username'] ?? '')) . "' required>";
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

// Cohort Selection
echo "<div class='form-group'>";
echo "<label for='cohort_id' class='form-label'>Cohort <span class='required'>*</span></label>";
echo "<select id='cohort_id' name='cohort_id' class='form-select' required>";

// Get all available cohorts
$cohorts = $DB->get_records('cohort', ['visible' => 1], 'name ASC');
echo "<option value=''>Select Cohort</option>";

foreach ($cohorts as $cohort) {
    $selected = (($_POST['cohort_id'] ?? '') == $cohort->id) ? ' selected' : '';
    echo "<option value='{$cohort->id}'$selected>{$cohort->name}</option>";
}

echo "</select>";
echo "<small class='form-text text-muted'>Select the cohort for this student. Grade level will be automatically assigned based on the cohort.</small>";
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
echo "<textarea id='notes' name='notes' class='form-textarea' placeholder='Any additional information about the student...'>" . s($_POST['notes'] ?? '') . "</textarea>";
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
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' class='btn btn-secondary'>";
echo "<i class='fa fa-times'></i> Cancel";
echo "</a>";
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='fa fa-plus'></i> Add Student";
echo "</button>";
echo "</div>";

echo "</form>";
echo "</div>"; // End form-container

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// Add JavaScript for username conversion and image upload
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    // Username lowercase conversion
    const usernameInput = document.getElementById('username');
    if (usernameInput) {
        usernameInput.addEventListener('input', function() {
            this.value = this.value.toLowerCase();
        });
        
        usernameInput.addEventListener('paste', function() {
            setTimeout(() => {
                this.value = this.value.toLowerCase();
            }, 10);
        });
    }
    
    // File input change handler
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

// Preview image from file
function previewImageFromFile(file) {
    const preview = document.getElementById('image_preview');
    const uploadArea = document.querySelector('.image-upload-area');
    const deleteBtn = document.getElementById('delete_image_btn');
    
    if (file) {
        if (file.size > 2 * 1024 * 1024) {
            alert('File size must be less than 2MB');
            return;
        }
        
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
</script>";

echo $OUTPUT->footer();
?>
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
</script>";

echo $OUTPUT->footer();
?>