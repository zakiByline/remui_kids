<?php
/**
 * Student Bulk Upload Page - Upload multiple students at once using CSV files
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
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/student_bulk_upload.php'));
$PAGE->set_title('Student Bulk Upload - ' . $company_info->name);
$PAGE->set_heading('Student Bulk Upload');

// Get statistics
$current_month = date('Y-m-01');
$current_week = date('Y-m-d', strtotime('monday this week'));

// Students added this month
$students_this_month = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND cu.educator = 0 AND u.timecreated >= ?",
    [$company_info->id, strtotime($current_month)]
);

// Active students this week
$active_students_this_week = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND cu.educator = 0 AND u.lastaccess >= ?",
    [$company_info->id, strtotime($current_week)]
);

// Total students in school
$total_students = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user} u 
     JOIN {company_users} cu ON u.id = cu.userid 
     WHERE cu.companyid = ? AND cu.educator = 0",
    [$company_info->id]
);

// Process CSV upload if form is submitted
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Check for duplicate submission using session
    session_start();
    $upload_token = $_POST['upload_token'] ?? '';
    $upload_type = $_POST['upload_type'] ?? 'add_new_only'; // Default upload type
    $match_field = $_POST['match_field'] ?? 'username'; // Default match field
    
    if (empty($upload_token) || !isset($_SESSION['last_upload_token']) || $_SESSION['last_upload_token'] !== $upload_token) {
        // Process the upload with the selected upload type and match field
        $upload_result = process_student_csv_upload($_FILES['csv_file'], $company_info, $upload_type, $match_field);
        
        // Store this token to prevent duplicate submissions
        $_SESSION['last_upload_token'] = $upload_token;
        
        // Store result in session for redirect
        $_SESSION['upload_result'] = $upload_result;
        
        // Redirect to prevent form resubmission on refresh (POST-Redirect-GET pattern)
        redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/student_bulk_upload.php?uploaded=1');
        exit;
    } else {
        // Duplicate submission detected, ignore
        $upload_result = ['success' => false, 'message' => 'Duplicate upload detected. Please try again with a different file.'];
    }
}

// Check if we were redirected after upload
if (isset($_GET['uploaded'])) {
    session_start();
    if (isset($_SESSION['upload_result'])) {
        $upload_result = $_SESSION['upload_result'];
        unset($_SESSION['upload_result']); // Clear it after displaying
    }
}

function process_student_csv_upload($file, $company_info, $upload_type = 'add_new_only', $match_field = 'username') {
    global $DB, $USER, $CFG;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error: ' . $file['error']];
    }
    
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        return ['success' => false, 'message' => 'File size exceeds 5MB limit'];
    }
    
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Could not read CSV file'];
    }
    
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty'];
    }
    
    // Validate required columns
    $required_columns = ['username', 'password', 'firstname', 'lastname', 'email', 'cohort'];
    $optional_columns = ['phone', 'city', 'country', 'notes'];
    $all_columns = array_merge($required_columns, $optional_columns);
    
    $missing_columns = array_diff($required_columns, $header);
    if (!empty($missing_columns)) {
        fclose($handle);
        return ['success' => false, 'message' => 'Missing required columns: ' . implode(', ', $missing_columns)];
    }
    
    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;
    $updated_count = 0;
    $errors = [];
    
    $context = context_system::instance();
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    
    // Processing student bulk upload (logging disabled for performance)
    
    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) !== count($header)) {
            $error_count++;
            $errors[] = "Row " . ($success_count + $error_count + 1) . ": Column count mismatch";
            continue;
        }
        
        $row_data = array_combine($header, $data);
        
        // Validate required fields
        foreach ($required_columns as $field) {
            if (empty(trim($row_data[$field]))) {
                $error_count++;
                $errors[] = "Row " . ($success_count + $error_count + 1) . ": Required field '{$field}' is empty";
                continue 2;
            }
        }
        
        // Convert username to lowercase and validate
        $username = strtolower(trim($row_data['username']));
        
        // Validate username format (alphanumeric, dots, underscores, hyphens only)
        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            $error_count++;
            $errors[] = "Row " . ($success_count + $error_count + 1) . ": Username '{$row_data['username']}' can only contain lowercase letters, numbers, dots, underscores, and hyphens";
            continue;
        }
        
        // Check if user already exists based on match field
        $existing_user = null;
        
        if ($match_field === 'email') {
            // Match by email ID
            $existing_user = $DB->get_record('user', ['email' => trim($row_data['email'])]);
        } else {
            // Match by username (default)
            $existing_user = $DB->get_record('user', ['username' => $username]);
        }
        
        // Handle based on upload type
        if ($existing_user) {
            // User exists - handle based on upload type
            switch ($upload_type) {
                case 'add_new_only':
                    // Skip existing users
                    $skipped_count++;
                    continue 2;
                    
                case 'add_all_append':
                    // Append number to username if needed
                    $base_username = $username;
                    $counter = 1;
                    while ($DB->record_exists('user', ['username' => $username])) {
                        $username = $base_username . $counter;
                        $counter++;
                    }
                    // Continue to create new user with modified username
                    $existing_user = null;
                    break;
                    
                case 'add_and_update':
                case 'update_only':
                    // Update existing user
                    try {
                        $existing_user->firstname = trim($row_data['firstname']);
                        $existing_user->lastname = trim($row_data['lastname']);
                        
                        // Update username if matching by email and username has changed
                        if ($match_field === 'email' && $existing_user->username !== $username) {
                            // Check if new username is already taken
                            if (!$DB->record_exists('user', ['username' => $username])) {
                                $existing_user->username = $username;
                            }
                        }
                        
                        // Update email if matching by username and email has changed
                        if ($match_field === 'username') {
                            $existing_user->email = trim($row_data['email']);
                        }
                        
                        // Update password if provided
                        if (!empty($row_data['password'])) {
                            $existing_user->password = hash_internal_user_password($row_data['password']);
                        }
                        
                        // Update optional fields if provided
                        if (!empty($row_data['phone'])) {
                            $existing_user->phone1 = trim($row_data['phone']);
                        }
                        if (!empty($row_data['city'])) {
                            $existing_user->city = trim($row_data['city']);
                        }
                        if (!empty($row_data['country'])) {
                            $existing_user->country = trim($row_data['country']);
                        }
                        
                        user_update_user($existing_user, false, false);
                        
                        // Check if already associated with this company
                        $already_in_company = $DB->record_exists('company_users', [
                            'userid' => $existing_user->id,
                            'companyid' => $company_info->id
                        ]);
                        
                        if (!$already_in_company) {
                            // Associate with company
                            $company_department = $DB->get_record_sql(
                                "SELECT id FROM {department} WHERE company = ? ORDER BY id ASC LIMIT 1",
                                [$company_info->id]
                            );
                            
                            if ($company_department) {
                                $company_user = new stdClass();
                                $company_user->userid = $existing_user->id;
                                $company_user->companyid = $company_info->id;
                                $company_user->departmentid = $company_department->id;
                                $company_user->managertype = 0;
                                $company_user->educator = 0; // Student
                                $company_user->suspended = 0;
                                $DB->insert_record('company_users', $company_user);
                            }
                        }
                        
                        // Assign student role if not already assigned
                        if ($student_role && !user_has_role_assignment($existing_user->id, $student_role->id, $context->id)) {
                            role_assign($student_role->id, $existing_user->id, $context->id);
                        }
                        
                        // Update cohort assignment if cohort is different
                        $cohort_name = trim($row_data['cohort']);
                        $cohort = $DB->get_record('cohort', ['name' => $cohort_name, 'visible' => 1]);
                        if ($cohort) {
                            // Check if already in this cohort
                            $existing_cohort = $DB->get_record('cohort_members', [
                                'cohortid' => $cohort->id,
                                'userid' => $existing_user->id
                            ]);
                            
                            if (!$existing_cohort) {
                                // Remove from other cohorts first
                                $DB->delete_records('cohort_members', ['userid' => $existing_user->id]);
                                
                                // Add to new cohort
                                $cohort_member = new stdClass();
                                $cohort_member->cohortid = $cohort->id;
                                $cohort_member->userid = $existing_user->id;
                                $cohort_member->timeadded = time();
                                $DB->insert_record('cohort_members', $cohort_member);
                            }
                            
                            // Update grade level
                            $grade_level = determine_grade_level_from_cohort($cohort->name);
                            $grade_field = $DB->get_record('user_info_field', ['shortname' => 'gradelevel']);
                            if ($grade_field) {
                                $existing_grade = $DB->get_record('user_info_data', [
                                    'userid' => $existing_user->id,
                                    'fieldid' => $grade_field->id
                                ]);
                                if ($existing_grade) {
                                    $existing_grade->data = $grade_level;
                                    $DB->update_record('user_info_data', $existing_grade);
                                } else {
                                    $grade_data = new stdClass();
                                    $grade_data->userid = $existing_user->id;
                                    $grade_data->fieldid = $grade_field->id;
                                    $grade_data->data = $grade_level;
                                    $grade_data->dataformat = FORMAT_HTML;
                                    $DB->insert_record('user_info_data', $grade_data);
                                }
                            }
                        }
                        
                        // Update notes if provided
                        if (!empty($row_data['notes'])) {
                            $notes_field = $DB->get_record('user_info_field', ['shortname' => 'additional_notes']);
                            if ($notes_field) {
                                $existing_notes = $DB->get_record('user_info_data', [
                                    'userid' => $existing_user->id,
                                    'fieldid' => $notes_field->id
                                ]);
                                if ($existing_notes) {
                                    $existing_notes->data = trim($row_data['notes']);
                                    $DB->update_record('user_info_data', $existing_notes);
                                } else {
                                    $notes_data = new stdClass();
                                    $notes_data->userid = $existing_user->id;
                                    $notes_data->fieldid = $notes_field->id;
                                    $notes_data->data = trim($row_data['notes']);
                                    $notes_data->dataformat = FORMAT_HTML;
                                    $DB->insert_record('user_info_data', $notes_data);
                                }
                            }
                        }
                        
                        $updated_count++;
                        continue 2;
                        
                    } catch (Exception $e) {
                        $error_count++;
                        $errors[] = "Row " . ($success_count + $error_count + 1) . ": Failed to update student - " . $e->getMessage();
                        continue 2;
                    }
            }
        } else {
            // User doesn't exist
            if ($upload_type === 'update_only') {
                // Skip non-existing users in update-only mode
                $skipped_count++;
                continue;
            }
        }
        
        // Validate cohort exists
        $cohort_name = trim($row_data['cohort']);
        $cohort = $DB->get_record('cohort', ['name' => $cohort_name, 'visible' => 1]);
        if (!$cohort) {
            $error_count++;
            $errors[] = "Row " . ($success_count + $error_count + 1) . ": Cohort '{$cohort_name}' not found or not available";
            continue;
        }
        
        try {
            // Create user
            $user_data = new stdClass();
            $user_data->username = $username;
            $user_data->password = $row_data['password']; // Keep password plain text for user_create_user to hash it properly
            $user_data->firstname = trim($row_data['firstname']);
            $user_data->lastname = trim($row_data['lastname']);
            $user_data->email = trim($row_data['email']);
            $user_data->auth = 'manual';
            $user_data->confirmed = 1;
            $user_data->mnethostid = $CFG->mnet_localhost_id;
            $user_data->suspended = 0;
            $user_data->deleted = 0;
            
            // Add optional fields if provided
            if (!empty($row_data['phone'])) {
                $user_data->phone1 = trim($row_data['phone']);
            }
            if (!empty($row_data['city'])) {
                $user_data->city = trim($row_data['city']);
            }
            if (!empty($row_data['country'])) {
                $user_data->country = trim($row_data['country']);
            }
            
            // Use user_create_user with updatepassword = true to properly hash the password
            $user_id = user_create_user($user_data, true, false);
            
            // Verify user was created successfully
            $created_user = $DB->get_record('user', ['id' => $user_id]);
            if (!$created_user || $created_user->suspended || $created_user->deleted || !$created_user->confirmed) {
                throw new Exception('User creation failed or user is not properly configured');
            }
            
            // Assign student role
            if ($student_role) {
                role_assign($student_role->id, $user_id, $context->id);
            }
            
            // Get the company's default department
            $company_department = $DB->get_record_sql(
                "SELECT id FROM {department} WHERE company = ? ORDER BY id ASC LIMIT 1",
                [$company_info->id]
            );
            
            if (!$company_department) {
                throw new Exception('No department found for this school. Please contact administrator.');
            }
            
            // Associate with company (school)
            $company_user = new stdClass();
            $company_user->userid = $user_id;
            $company_user->companyid = $company_info->id;
            $company_user->departmentid = $company_department->id; // REQUIRED: Department ID
            $company_user->managertype = 0;
            $company_user->educator = 0; // Mark as student
            $company_user->suspended = 0;
            
            try {
                $DB->insert_record('company_users', $company_user);
            } catch (Exception $e) {
                throw new Exception('Failed to associate student with school: ' . $e->getMessage());
            }
            
            // Assign to cohort
            $cohort_member = new stdClass();
            $cohort_member->cohortid = $cohort->id;
            $cohort_member->userid = $user_id;
            $cohort_member->timeadded = time();
            
            try {
                $DB->insert_record('cohort_members', $cohort_member);
            } catch (Exception $cohort_error) {
                throw new Exception('Failed to assign student to cohort: ' . $cohort_error->getMessage());
            }
            
            // Determine grade level from cohort name
            $grade_level = determine_grade_level_from_cohort($cohort->name);
            
            // Store grade level in custom field
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
            
            $grade_data = new stdClass();
            $grade_data->userid = $user_id;
            $grade_data->fieldid = $grade_field_id;
            $grade_data->data = $grade_level;
            $grade_data->dataformat = FORMAT_HTML;
            
            $DB->insert_record('user_info_data', $grade_data);
            
            // Store additional notes if provided
            if (!empty($row_data['notes'])) {
                $notes_field = $DB->get_record('user_info_field', ['shortname' => 'additional_notes']);
                if (!$notes_field) {
                    // Create notes field if it doesn't exist
                    $notes_field = new stdClass();
                    $notes_field->shortname = 'additional_notes';
                    $notes_field->name = 'Additional Notes';
                    $notes_field->datatype = 'textarea';
                    $notes_field->description = 'Additional Notes for Student';
                    $notes_field->descriptionformat = FORMAT_HTML;
                    $notes_field->categoryid = 1;
                    $notes_field->sortorder = 1;
                    $notes_field->required = 0;
                    $notes_field->locked = 0;
                    $notes_field->visible = 1;
                    $notes_field->forceunique = 0;
                    $notes_field->signup = 0;
                    $notes_field->defaultdata = '';
                    $notes_field->defaultdataformat = FORMAT_HTML;
                    $notes_field->param1 = '10';
                    $notes_field->param2 = '5';
                    $notes_field->param3 = '';
                    $notes_field->param4 = '';
                    $notes_field->param5 = '';
                    
                    $notes_field_id = $DB->insert_record('user_info_field', $notes_field);
                } else {
                    $notes_field_id = $notes_field->id;
                }
                
                $notes_data = new stdClass();
                $notes_data->userid = $user_id;
                $notes_data->fieldid = $notes_field_id;
                $notes_data->data = trim($row_data['notes']);
                $notes_data->dataformat = FORMAT_HTML;
                
                $DB->insert_record('user_info_data', $notes_data);
            }
            
            $success_count++;
            
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Row " . ($success_count + $error_count + 1) . ": " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    return [
        'success' => ($success_count > 0 || $updated_count > 0),
        'success_count' => $success_count,
        'updated_count' => $updated_count,
        'skipped_count' => $skipped_count,
        'error_count' => $error_count,
        'errors' => $errors
    ];
}

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

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Custom CSS for the student bulk upload layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

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

/* Main content positioning to work with the new sidebar template */
.main-content {
    padding: 20px 20px 20px 20px;
    padding-top: 35px;
    min-height: 100vh;
    background: transparent;
}

/* Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    margin-top: 25px;
    padding: 0 40px;
}

.page-title {
    font-size: 2rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.header-buttons {
    display: flex;
    gap: 12px;
    align-items: center;
}

.upload-picture-button {
    background: #3b82f6;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.upload-picture-button:hover {
    background: #2563eb;
    color: white;
    text-decoration: none;
}

.back-button {
    background: #6b7280;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.back-button:hover {
    background: #4b5563;
    color: white;
    text-decoration: none;
}

/* Stats Cards - Dashboard Style */
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
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stat-card.students {
    border-top-color: #3b82f6;
}

.stat-card.active {
    border-top-color: #10b981;
}

.stat-card.month {
    border-top-color: #f59e0b;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.stat-icon i {
    font-size: 20px;
    color: white;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 500;
}

/* Upload Section */
.upload-section {
    background: white;
    border-radius: 0.75rem;
    padding: 2rem;
    margin: 0 40px 2rem 40px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
}

/* Upload Type Selector */
.upload-type-container {
    background: linear-gradient(135deg, #e0f2fe 0%, #e0e7ff 100%);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
    border: 1px solid #bfdbfe;
}

/* Match Field Selector */
.match-field-container {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
    border: 1px solid #6ee7b7;
}

.upload-type-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.upload-type-icon {
    width: 40px;
    height: 40px;
    background: #3b82f6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
}

.upload-type-label {
    font-size: 1rem;
    font-weight: 600;
    color: #1e40af;
    margin: 0;
}

.upload-type-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #bfdbfe;
    border-radius: 0.5rem;
    font-size: 1rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 500;
}

.upload-type-select:hover {
    border-color: #3b82f6;
}

.upload-type-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.upload-type-description {
    margin-top: 0.75rem;
    font-size: 0.875rem;
    color: #1e40af;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.6);
    border-radius: 0.5rem;
}

.upload-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
}

.upload-header i {
    font-size: 1.5rem;
    color: #3b82f6;
    margin-right: 0.75rem;
}

.upload-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.upload-description {
    color: #6b7280;
    margin-bottom: 2rem;
    font-size: 1rem;
}

/* Upload Area */
.upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 0.75rem;
    padding: 3rem 2rem;
    text-align: center;
    background: #f9fafb;
    transition: all 0.3s ease;
    cursor: pointer;
    margin-bottom: 2rem;
}

.upload-area:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}

.upload-area.dragover {
    border-color: #3b82f6;
    background: #eff6ff;
    transform: scale(1.02);
}

.upload-icon {
    font-size: 3rem;
    color: #9ca3af;
    margin-bottom: 1rem;
}

.upload-text {
    font-size: 1.25rem;
    color: #374151;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.upload-subtext {
    color: #6b7280;
    margin-bottom: 1.5rem;
}

.upload-btn {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 0.5rem;
    font-size: 1rem;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.upload-btn:hover {
    background: #2563eb;
}

/* Requirements */
.requirements {
    background: #f8fafc;
    border-radius: 0.5rem;
    padding: 1.5rem;
    border-left: 4px solid #3b82f6;
}

.requirements h3 {
    color: #1f2937;
    margin-bottom: 1rem;
    font-size: 1.125rem;
    font-weight: 600;
}

.requirements ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.requirements li {
    padding: 0.5rem 0;
    color: #4b5563;
    position: relative;
    padding-left: 1.5rem;
}

.requirements li:before {
    content: '•';
    color: #3b82f6;
    font-weight: bold;
    position: absolute;
    left: 0;
}

.requirements .required {
    color: #dc2626;
    font-weight: 600;
}

.requirements .optional {
    color: #6b7280;
    font-style: italic;
}

/* Result Messages */
.result-message {
    padding: 1rem 1.5rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    font-weight: 500;
}

.result-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.result-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.error-list {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-top: 0.75rem;
    max-height: 200px;
    overflow-y: auto;
}

.error-list ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.error-list li {
    padding: 0.25rem 0;
    color: #991b1b;
    font-size: 0.875rem;
}

/* CSV Format Example */
.csv-example {
    background: #f1f5f9;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-top: 1.5rem;
    border: 1px solid #e2e8f0;
}

.csv-example h4 {
    color: #1e293b;
    margin-bottom: 1rem;
    font-size: 1rem;
    font-weight: 600;
}

.csv-example pre {
    background: #1e293b;
    color: #e2e8f0;
    padding: 1rem;
    border-radius: 0.375rem;
    overflow-x: auto;
    font-size: 0.875rem;
    line-height: 1.5;
}

/* Responsive Design */
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100vw;
    }
    
    .page-header {
        padding: 0 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .header-buttons {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }
    
    .upload-picture-button,
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

/* Animation for upload area */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.upload-area.uploading {
    animation: pulse 2s infinite;
}
";

echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<h1 class='page-title'>Student Bulk Upload</h1>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Student Management";
echo "</a>";
echo "</div>";

// Stats Section
echo "<div class='stats-section'>";
echo "<div class='stat-card students'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-graduation-cap'></i>";
echo "</div>";
echo "<div class='stat-number'>{$total_students}</div>";
echo "<div class='stat-label'>Total Students</div>";
echo "</div>";

echo "<div class='stat-card active'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-check-circle'></i>";
echo "</div>";
echo "<div class='stat-number'>{$active_students_this_week}</div>";
echo "<div class='stat-label'>Active This Week</div>";
echo "</div>";

echo "<div class='stat-card month'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-calendar'></i>";
echo "</div>";
echo "<div class='stat-number'>{$students_this_month}</div>";
echo "<div class='stat-label'>Added This Month</div>";
echo "</div>";
echo "</div>";

// Upload Section
echo "<div class='upload-section'>";
echo "<div class='upload-header'>";
echo "<i class='fa fa-upload'></i>";
echo "<h2 class='upload-title'>Bulk Student Upload</h2>";
echo "</div>";
echo "<p class='upload-description'>Upload a CSV file to add multiple students at once. All students will be automatically assigned the 'student' role, associated with your school, and assigned to their specified cohort with automatic grade level assignment.</p>";

// Show upload result if any
if ($upload_result) {
    $result_class = $upload_result['success'] ? 'result-success' : 'result-error';
    echo "<div class='result-message {$result_class}'>";
    
    if ($upload_result['success']) {
        $messages = [];
        if (isset($upload_result['success_count']) && $upload_result['success_count'] > 0) {
            $messages[] = "{$upload_result['success_count']} new student(s) created";
        }
        if (isset($upload_result['updated_count']) && $upload_result['updated_count'] > 0) {
            $messages[] = "{$upload_result['updated_count']} student(s) updated";
        }
        if (isset($upload_result['skipped_count']) && $upload_result['skipped_count'] > 0) {
            $messages[] = "{$upload_result['skipped_count']} student(s) skipped";
        }
        
        echo "<strong>Success!</strong> " . implode(", ", $messages) . ".";
        
        if (isset($upload_result['error_count']) && $upload_result['error_count'] > 0) {
            echo " {$upload_result['error_count']} record(s) failed.";
        }
    } else {
        echo "<strong>Upload Failed:</strong> " . (isset($upload_result['message']) ? $upload_result['message'] : 'Unknown error');
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
$upload_token = uniqid('upload_', true); // Generate unique token
echo "<form method='POST' enctype='multipart/form-data' id='uploadForm'>";
echo "<input type='hidden' name='upload_token' value='{$upload_token}'>";

// Upload Type Selector
echo "<div class='upload-type-container'>";
echo "<div class='upload-type-header'>";
echo "<div class='upload-type-icon'>";
echo "<i class='fa fa-list-ul'></i>";
echo "</div>";
echo "<h3 class='upload-type-label'>Upload type</h3>";
echo "</div>";
echo "<select name='upload_type' id='upload_type' class='upload-type-select'>";
echo "<option value='add_new_only'>Add new only, skip existing users</option>";
echo "<option value='add_all_append'>Add all, append number to usernames if needed</option>";
echo "<option value='add_and_update'>Add new and update existing users</option>";
echo "<option value='update_only'>Update existing users only</option>";
echo "</select>";
echo "<div class='upload-type-description' id='uploadTypeDescription'>";
echo "New users will be created. Existing users will be skipped.";
echo "</div>";
echo "</div>";

// Match Field Selector (for update operations)
echo "<div class='match-field-container' id='matchFieldContainer' style='display: none;'>";
echo "<div class='upload-type-header'>";
echo "<div class='upload-type-icon' style='background: #10b981;'>";
echo "<i class='fa fa-key'></i>";
echo "</div>";
echo "<h3 class='upload-type-label'>Match existing users by</h3>";
echo "</div>";
echo "<select name='match_field' id='match_field' class='upload-type-select'>";
echo "<option value='username'>Username</option>";
echo "<option value='email'>Email ID</option>";
echo "</select>";
echo "<div class='upload-type-description' id='matchFieldDescription'>";
echo "Existing users will be identified by their username.";
echo "</div>";
echo "</div>";

echo "<div class='upload-area' id='uploadArea'>";
echo "<div class='upload-icon'>";
echo "<i class='fa fa-cloud-upload-alt'></i>";
echo "</div>";
echo "<div class='upload-text'>Drop your CSV file here</div>";
echo "<div class='upload-subtext'>or click to browse files</div>";
echo "<button type='button' class='upload-btn' id='chooseFileBtn'>";
echo "Choose File";
echo "</button>";
echo "<input type='file' id='csv_file' name='csv_file' accept='.csv' style='display: none;'>";
echo "</div>";
echo "</form>";

// Requirements
echo "<div class='requirements'>";
echo "<h3>CSV Format Requirements:</h3>";
echo "<ul>";
echo "<li><span class='required'>Required columns:</span> username, password, firstname, lastname, email, cohort</li>";
echo "<li><span class='optional'>Optional columns:</span> phone, city, country, notes</li>";
echo "<li>File size: Maximum 5MB</li>";
echo "<li>All students will be assigned 'student' role automatically</li>";
echo "<li>All students will be associated with your school automatically</li>";
echo "<li>Students will be assigned to their specified cohort automatically</li>";
echo "<li>Grade level will be automatically determined from cohort name</li>";
echo "<li><strong>Tip:</strong> When updating existing users, you can choose to match by username or email ID</li>";
echo "</ul>";
echo "</div>";

// CSV Example
echo "<div class='csv-example'>";
echo "<h4>CSV Format Example:</h4>";
echo "<pre>";
echo "username,password,firstname,lastname,email,phone,cohort,city,country,notes\n";
echo "john.doe,password123,John,Doe,john.doe@school.com,+1234567890,Grade 9,New York,USA,Excellent student\n";
echo "jane.smith,password456,Jane,Smith,jane.smith@school.com,,Grade 10,Los Angeles,USA,Needs extra help\n";
echo "mike.wilson,password789,Mike,Wilson,mike.wilson@school.com,+1987654321,Grade 11,Chicago,USA,Math enthusiast";
echo "</pre>";
echo "</div>";

echo "</div>"; // End upload-section

echo "</div>"; // End main-content
echo "</div>"; // End school-manager-main-content

// JavaScript
echo "<script>";
echo "
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('csv_file');
    const chooseFileBtn = document.getElementById('chooseFileBtn');
    const uploadForm = document.getElementById('uploadForm');
    const uploadTypeSelect = document.getElementById('upload_type');
    const uploadTypeDescription = document.getElementById('uploadTypeDescription');
    const matchFieldContainer = document.getElementById('matchFieldContainer');
    const matchFieldSelect = document.getElementById('match_field');
    const matchFieldDescription = document.getElementById('matchFieldDescription');
    let isSubmitting = false; // Prevent double submission
    
    // Upload type descriptions
    const uploadTypeDescriptions = {
        'add_new_only': 'New users will be created. Existing users will be skipped.',
        'add_all_append': 'All users will be created. If a username exists, a number will be appended to make it unique.',
        'add_and_update': 'New users will be created. Existing users will be updated with new information.',
        'update_only': 'Only existing users will be updated. New users will be skipped.'
    };
    
    // Match field descriptions
    const matchFieldDescriptions = {
        'username': 'Existing users will be identified by their username.',
        'email': 'Existing users will be identified by their email ID.'
    };
    
    // Function to show/hide match field selector
    function updateMatchFieldVisibility() {
        const selectedType = uploadTypeSelect.value;
        // Show match field selector only for update operations
        if (selectedType === 'add_and_update' || selectedType === 'update_only') {
            matchFieldContainer.style.display = 'block';
        } else {
            matchFieldContainer.style.display = 'none';
        }
    }
    
    // Update description when upload type changes
    uploadTypeSelect.addEventListener('change', function() {
        const selectedType = this.value;
        uploadTypeDescription.textContent = uploadTypeDescriptions[selectedType] || '';
        updateMatchFieldVisibility();
    });
    
    // Update description when match field changes
    matchFieldSelect.addEventListener('change', function() {
        const selectedField = this.value;
        matchFieldDescription.textContent = matchFieldDescriptions[selectedField] || '';
    });
    
    // Initialize visibility on page load
    updateMatchFieldVisibility();

    function handleFileSelect(input) {
        const file = input.files[0];
        if (!file || isSubmitting) {
            return;
        }
        
        if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
            alert('Please select a CSV file.');
            input.value = ''; // Clear the file input
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            alert('File size exceeds 5MB limit.');
            input.value = ''; // Clear the file input
            return;
        }
        
        // Update upload area text
        const uploadText = uploadArea.querySelector('.upload-text');
        uploadText.textContent = 'Selected: ' + file.name;
        uploadText.style.color = '#10b981';
        
        // Add uploading class
        uploadArea.classList.add('uploading');
        
        // Prevent double submission
        isSubmitting = true;
        
        // Disable the button
        chooseFileBtn.disabled = true;
        chooseFileBtn.textContent = 'Uploading...';
        
        // Auto-submit form
        setTimeout(function() {
            uploadForm.submit();
        }, 100);
    }

    // File input change event
    fileInput.addEventListener('change', function() {
        handleFileSelect(this);
    });

    // Drag and drop functionality
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        if (isSubmitting) return;
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect(fileInput);
        }
    });

    // Choose file button click
    chooseFileBtn.addEventListener('click', () => {
        if (!isSubmitting) {
            fileInput.click();
        }
    });
    
    // Click on upload area (but not on button)
    uploadArea.addEventListener('click', (e) => {
        if (e.target !== chooseFileBtn && !chooseFileBtn.contains(e.target) && !isSubmitting) {
            fileInput.click();
        }
    });
});
";
echo "</script>";

echo $OUTPUT->footer();
?>


