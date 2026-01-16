<?php
/**
 * Edit Parent - School Manager
 * Form for editing existing parents in the school
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Ensure lib.php is loaded for theme functions
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

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

// Get parent ID from URL
$parent_id = required_param('id', PARAM_INT);

// Get parent data
$parent = $DB->get_record('user', ['id' => $parent_id, 'deleted' => 0]);
if (!$parent) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/parent_management.php', 'Parent not found.', null, \core\output\notification::NOTIFY_ERROR);
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
}

// Verify parent belongs to this company (check both direct membership and via children)
$parent_in_company = false;
if ($company_info) {
    // Check if parent is in company_users
    $parent_in_company = $DB->record_exists_sql(
        "SELECT 1 FROM {company_users} cu 
         WHERE cu.userid = ? AND cu.companyid = ?",
        [$parent_id, $company_info->id]
    );
    
    // Also check if parent is linked via children in company
    if (!$parent_in_company) {
        $parent_role = $DB->get_record('role', ['shortname' => 'parent']);
        if ($parent_role) {
            $parent_in_company = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                 JOIN {user} child ON child.id = ctx.instanceid
                 JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = :companyid
                 WHERE ra.userid = :parentid AND ra.roleid = :roleid",
                [
                    'ctxlevel' => CONTEXT_USER,
                    'companyid' => $company_info->id,
                    'parentid' => $parent_id,
                    'roleid' => $parent_role->id
                ]
            );
        }
    }
    
    if (!$parent_in_company) {
        redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/parent_management.php', 'Access denied. This parent does not belong to your school.', null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Get parent role
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);

// Get children assigned to this parent
$assigned_children = [];
$assigned_child_ids = [];
if ($parent_role) {
    $children_records = $DB->get_records_sql(
        "SELECT child.id,
                child.firstname,
                child.lastname,
                child.username,
                child.email,
                COALESCE(uifd.data, '') AS grade_level
           FROM {role_assignments} ra
           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
           JOIN {user} child ON child.id = ctx.instanceid
           LEFT JOIN {user_info_data} uifd ON uifd.userid = child.id
           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
          WHERE ra.userid = :parentid
            AND ra.roleid = :roleid
            AND child.deleted = 0
       ORDER BY child.firstname, child.lastname",
        [
            'ctxlevel' => CONTEXT_USER,
            'parentid' => $parent_id,
            'roleid' => $parent_role->id
        ]
    );
    
    foreach ($children_records as $child) {
        $assigned_children[] = [
            'id' => $child->id,
            'name' => fullname($child),
            'firstname' => $child->firstname,
            'lastname' => $child->lastname,
            'username' => $child->username,
            'email' => $child->email,
            'grade_level' => $child->grade_level ?: 'Not assigned'
        ];
        $assigned_child_ids[] = $child->id;
    }
}

// Fetch all available students for the company
$students = [];
$studentoptions = [];
$cohortcounts = [];
if ($company_info && $parent_role) {
    try {
        $students = $DB->get_records_sql(
            "SELECT DISTINCT u.id,
                    u.firstname,
                    u.lastname,
                    u.username,
                    COALESCE(uifd.data, '') AS grade_level
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
               LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
              WHERE u.deleted = 0
           ORDER BY u.firstname, u.lastname",
            ['companyid' => $company_info->id]
        );
        
        foreach ($students as $student) {
            $profileimageurl = '';
            try {
                $usercontext = context_user::instance($student->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($usercontext->id, 'user', 'icon', 0, 'sortorder', false);
                if ($files) {
                    foreach ($files as $file) {
                        if ($file->is_valid_image()) {
                            $profileimageurl = moodle_url::make_pluginfile_url(
                                $usercontext->id,
                                'user',
                                'icon',
                                0,
                                '/',
                                $file->get_filename()
                            )->out(false);
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                $profileimageurl = '';
            }
            
            $studentoptions[] = [
                'id' => $student->id,
                'name' => fullname($student),
                'firstname' => $student->firstname,
                'lastname' => $student->lastname,
                'username' => $student->username,
                'cohort' => $student->grade_level ?: 'Not assigned',
                'profileimage' => $profileimageurl,
            ];
            $cohortlabel = $student->grade_level ?: 'Not assigned';
            if (!isset($cohortcounts[$cohortlabel])) {
                $cohortcounts[$cohortlabel] = 0;
            }
            $cohortcounts[$cohortlabel]++;
        }
    } catch (Exception $e) {
        debugging('Error fetching students for parent assignment: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/edit_parent.php', ['id' => $parent_id]));
$PAGE->set_title('Edit Parent');
$PAGE->set_heading('Edit Parent');
$PAGE->set_pagelayout('standard');

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
        // Validate required fields (password is optional for edit)
        $required_fields = ['username', 'firstname', 'lastname', 'email'];
        
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
            
            // Check if username already exists (excluding current parent)
            if ($DB->record_exists_sql(
                "SELECT 1 FROM {user} WHERE username = ? AND mnethostid = ? AND deleted = 0 AND id != ?",
                [$username, $CFG->mnet_localhost_id, $parent_id]
            )) {
                $errors[] = 'Username already exists. Please choose a different username.';
            }
        }
        
        // Check if email already exists (excluding current parent)
        if (!empty($_POST['email']) && $DB->record_exists_sql(
            "SELECT 1 FROM {user} WHERE email = ? AND mnethostid = ? AND deleted = 0 AND id != ?",
            [$_POST['email'], $CFG->mnet_localhost_id, $parent_id]
        )) {
            $errors[] = 'Email already exists. Please use a different email address.';
        }
        
        // Validate password strength if provided
        if (!empty($_POST['password'])) {
            $password = $_POST['password'];
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters long.';
            }
        }
        
        // Validate at least one student is selected
        $selected_student_ids = [];
        if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
            $selected_student_ids = array_map('intval', $_POST['student_ids']);
            $selected_student_ids = array_filter($selected_student_ids);
        }
        if (empty($selected_student_ids)) {
            $errors[] = 'Please select at least one student to assign to this parent.';
        }
        
        if (empty($errors)) {
            try {
                // Update user data
                $user_data = new stdClass();
                $user_data->id = $parent_id;
                $user_data->username = strtolower(trim($_POST['username']));
                
                // Only update password if provided
                if (!empty($_POST['password'])) {
                    $user_data->password = hash_internal_user_password($_POST['password']);
                }
                
                $user_data->firstname = trim($_POST['firstname']);
                $user_data->lastname = trim($_POST['lastname']);
                $user_data->email = trim($_POST['email']);
                $user_data->phone1 = !empty($_POST['phone']) ? trim($_POST['phone']) : '';
                $user_data->city = !empty($_POST['city']) ? trim($_POST['city']) : '';
                $user_data->country = !empty($_POST['country']) ? $_POST['country'] : '';
                $user_data->description = !empty($_POST['note']) ? trim($_POST['note']) : '';
                $user_data->timemodified = time();
                
                // Update user using Moodle's function
                user_update_user($user_data, false);
                
                // Handle student assignments
                if ($parent_role && $company_info) {
                    // Get new student IDs from form (using $selected_student_ids from validation)
                    $new_student_ids = $selected_student_ids;
                    
                    // Get current student IDs
                    $current_student_ids = [];
                    if ($parent_role) {
                        $current_assignments = $DB->get_records_sql(
                            "SELECT ctx.instanceid AS studentid
                               FROM {role_assignments} ra
                               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                              WHERE ra.userid = :parentid
                                AND ra.roleid = :roleid",
                            [
                                'ctxlevel' => CONTEXT_USER,
                                'parentid' => $parent_id,
                                'roleid' => $parent_role->id
                            ]
                        );
                        $current_student_ids = array_map(function($a) { return $a->studentid; }, $current_assignments);
                    }
                    
                    // Remove students that are no longer assigned
                    $students_to_remove = array_diff($current_student_ids, $new_student_ids);
                    foreach ($students_to_remove as $student_id) {
                        // Verify student belongs to company
                        $student_in_company = $DB->record_exists('company_users', [
                            'userid' => $student_id,
                            'companyid' => $company_info->id
                        ]);
                        
                        if ($student_in_company) {
                            $studentcontext = context_user::instance($student_id);
                            role_unassign($parent_role->id, $parent_id, $studentcontext->id);
                        }
                    }
                    
                    // Add new students
                    $students_to_add = array_diff($new_student_ids, $current_student_ids);
                    foreach ($students_to_add as $student_id) {
                        // Verify student belongs to company
                        $student_in_company = $DB->record_exists('company_users', [
                            'userid' => $student_id,
                            'companyid' => $company_info->id
                        ]);
                        
                        if ($student_in_company) {
                            $studentcontext = context_user::instance($student_id);
                            role_assign($parent_role->id, $parent_id, $studentcontext->id);
                        }
                    }
                }
                
                // Handle profile image upload if provided
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $uploaded_file = $_FILES['profile_image'];
                    
                    // Validate file
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 2 * 1024 * 1024; // 2MB
                    
                    if (in_array($uploaded_file['type'], $allowed_types) && $uploaded_file['size'] <= $max_size) {
                        // Create user context
                        $user_context = context_user::instance($parent_id);
                        
                        // Prepare file area
                        $fs = get_file_storage();
                        
                        // Delete any existing profile images
                        $fs->delete_area_files($user_context->id, 'user', 'icon');
                        $fs->delete_area_files($user_context->id, 'user', 'newicon');
                        
                        // Determine file extension
                        $file_extension = 'jpg';
                        if (strpos($uploaded_file['type'], 'png') !== false) {
                            $file_extension = 'png';
                        } elseif (strpos($uploaded_file['type'], 'gif') !== false) {
                            $file_extension = 'gif';
                        }
                        
                        // Create file record
                        $file_record = array(
                            'contextid' => $user_context->id,
                            'component' => 'user',
                            'filearea' => 'icon',
                            'itemid' => 0,
                            'filepath' => '/',
                            'filename' => 'f1.' . $file_extension
                        );
                        
                        // Store the file
                        $stored_file = $fs->create_file_from_pathname($file_record, $uploaded_file['tmp_name']);
                        
                        if ($stored_file) {
                            // Update user's picture field
                            $user = $DB->get_record('user', ['id' => $parent_id]);
                            $user->picture = time();
                            $user->timemodified = time();
                            $DB->update_record('user', $user);
                        }
                    }
                }
                
                $success = true;
                redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/parent_management.php', 'Parent updated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
                
            } catch (Exception $e) {
                $errors[] = 'Error updating parent: ' . $e->getMessage();
            }
        }
    }
}

// Get updated parent data
$parent = $DB->get_record('user', ['id' => $parent_id, 'deleted' => 0]);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School Manager',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'parent_management_active' => true
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
?>

<style>
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    background-color: #f8fafc;
    overflow-y: auto;
    padding: 45px 0 50px;
}

.main-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 40px 40px;
}

.page-header {
    background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 50%, #ede9fe 100%);
    padding: 25px 30px;
    border-radius: 18px;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
}

.page-header h1 {
    margin: 0;
    font-size: 1.8rem;
    color: #111827;
}

.parent-form-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
    border: 1px solid #e5e7eb;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
}

.required-star {
    color: #dc2626;
    margin-left: 4px;
    font-weight: 700;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: border 0.2s ease, box-shadow 0.2s ease;
    background: #fff;
    box-sizing: border-box;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.assigned-children-section {
    margin-top: 20px;
    padding: 20px;
    background: #f8fafc;
    border: 2px dashed #c7d2fe;
    border-radius: 12px;
}

.assigned-children-section strong {
    display: block;
    margin-bottom: 14px;
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    letter-spacing: 0.3px;
}

.children-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.child-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.child-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.child-info {
    flex: 1;
    min-width: 0;
}

.child-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 2px;
    font-size: 0.95rem;
}

.child-details {
    font-size: 0.85rem;
    color: #6c757d;
}

.no-children {
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-style: italic;
}

.submit-section {
    margin-top: 25px;
    display: flex;
    gap: 15px;
}

.submit-btn {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border: none;
    padding: 12px 28px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px rgba(79, 70, 229, 0.35);
}

.cancel-btn {
    background: #e5e7eb;
    border: none;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    color: #374151;
    display: inline-flex;
    align-items: center;
    transition: all 0.2s ease;
}

.cancel-btn:hover {
    background: #d1d5db;
    color: #1f2937;
    text-decoration: none;
}

.error-alert {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
}

.student-filter-row {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.cohort-filter-control {
    flex: 2;
    position: relative;
    min-width: 0;
}

.student-select-control {
    flex: 3;
    min-width: 0;
}

.cohort-filter-control label,
.student-select-control label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #4b5563;
    display: block;
    margin-bottom: 4px;
}

/* Custom Cohort Dropdown Styles */
.custom-cohort-dropdown {
    position: relative;
    width: 100%;
}

.cohort-dropdown-trigger {
    width: 100%;
    padding: 10px 40px 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    position: relative;
}

.cohort-dropdown-trigger:hover {
    border-color: #007bff;
}

.cohort-dropdown-trigger.active {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.cohort-dropdown-trigger .trigger-text {
    flex: 1;
    text-align: left;
    font-weight: 500;
    color: #2c3e50;
}

.cohort-dropdown-trigger .trigger-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
    color: #6c757d;
}

.cohort-dropdown-trigger.active .trigger-icon {
    transform: translateY(-50%) rotate(180deg);
}

.cohort-dropdown-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    max-height: 195px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.cohort-dropdown-menu.show {
    display: block;
}

.cohort-dropdown-menu::-webkit-scrollbar {
    width: 6px;
}

.cohort-dropdown-menu::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.cohort-dropdown-menu::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.cohort-dropdown-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #f1f3f5;
    cursor: pointer;
    transition: all 0.2s ease;
}

.cohort-dropdown-item:last-child {
    border-bottom: none;
}

.cohort-dropdown-item:hover {
    background: #f8f9fa;
}

.cohort-dropdown-item.selected {
    background: #e3f2fd;
    border-left: 3px solid #007bff;
}

.cohort-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    flex-shrink: 0;
}

.cohort-icon svg {
    width: 18px;
    height: 18px;
    fill: white;
}

.cohort-info {
    flex: 1;
    min-width: 0;
}

.cohort-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 2px;
    font-size: 0.85rem;
}

.cohort-members {
    font-size: 0.75rem;
    color: #6c757d;
}

/* Custom Student Dropdown Styles */
.custom-student-dropdown {
    position: relative;
    width: 100%;
}

.student-dropdown-trigger {
    width: 100%;
    padding: 10px 40px 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    position: relative;
    display: flex;
    align-items: center;
    min-height: 42px;
}

.student-dropdown-trigger:hover {
    border-color: #007bff;
}

.student-dropdown-trigger.active {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.student-dropdown-trigger .trigger-text {
    flex: 1;
    text-align: left;
    font-weight: 500;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    flex-wrap: wrap;
}

.student-dropdown-trigger .trigger-text .student-avatar {
    flex-shrink: 0;
}

.student-dropdown-trigger .trigger-text > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.student-trigger-input {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 85%;
    max-width: 550px;
    height: 100%;
    border: 2px solid #007bff;
    border-radius: 8px;
    padding: 0 40px 0 40px;
    font-size: 0.95rem;
    color: #1f2937;
    background: #ffffff;
    display: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    z-index: 10;
    text-align: center;
}

.student-trigger-input::placeholder {
    text-align: center;
    color: #9ca3af;
}

.student-trigger-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
    text-align: left;
}

.student-trigger-input:focus::placeholder {
    text-align: left;
}

.student-trigger-search-icon {
    position: absolute;
    left: calc(7.5% + 14px);
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.9rem;
    pointer-events: none;
    z-index: 11;
    display: none;
}

@media (max-width: 768px) {
    .student-trigger-search-icon {
        left: 14px;
    }
    .student-trigger-input {
        width: calc(100% - 80px);
        max-width: none;
        left: 40px;
        transform: none;
        text-align: left;
    }
    .student-trigger-input::placeholder {
        text-align: left;
    }
}

.student-dropdown-trigger.search-mode .student-trigger-search-icon {
    display: block;
}

.student-dropdown-trigger.search-mode .student-trigger-input {
    display: block;
}

.student-dropdown-trigger.search-mode .trigger-text {
    visibility: hidden;
}

.student-dropdown-trigger.search-mode .trigger-icon {
    z-index: 11;
}

.student-dropdown-trigger .trigger-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
    color: #6c757d;
}

.student-dropdown-trigger.active .trigger-icon {
    transform: translateY(-50%) rotate(180deg);
}

.student-dropdown-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    max-height: 300px;
    overflow: hidden;
    z-index: 1000;
    display: none;
    flex-direction: column;
}

.student-dropdown-menu.show {
    display: flex;
}

.student-search-box {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    position: relative;
    z-index: 10;
}

.student-search-input {
    width: 100%;
    padding: 10px 12px 10px 36px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: #ffffff;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.student-search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.student-search-box .search-icon {
    position: absolute;
    left: 24px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.85rem;
    pointer-events: none;
}

.student-dropdown-list {
    overflow-y: auto;
    max-height: 240px;
    flex: 1;
}

.student-dropdown-list::-webkit-scrollbar {
    width: 6px;
}

.student-dropdown-list::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.student-dropdown-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.student-dropdown-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f3f5;
    cursor: pointer;
    transition: all 0.2s ease;
}

.student-dropdown-item:last-child {
    border-bottom: none;
}

.student-dropdown-item:hover {
    background: #f8f9fa;
}

.student-dropdown-item.selected {
    background: #e3f2fd;
    border-left: 3px solid #007bff;
}

.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    flex-shrink: 0;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
}

.student-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.student-avatar .initials {
    font-size: 0.9rem;
    font-weight: 600;
    color: white;
}

.student-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.student-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 2px;
    font-size: 0.95rem;
    line-height: 1.3;
}

.student-username {
    font-size: 0.85rem;
    color: #6c757d;
    line-height: 1.3;
}

.selected-students-list {
    margin-top: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.selected-student-chip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background: #e3f2fd;
    border: 1px solid #007bff;
    border-radius: 8px;
}

.selected-student-chip-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.selected-student-chip .student-avatar {
    width: 32px;
    height: 32px;
    margin-right: 0;
    font-size: 0.8rem;
}

.selected-student-chip .student-name {
    font-size: 0.9rem;
    margin: 0;
}

.remove-student-btn {
    background: #dc2626;
    color: white;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.remove-student-btn:hover {
    background: #b91c1c;
    transform: scale(1.1);
}

@media (max-width: 768px) {
    .school-manager-main-content {
        position: relative;
        top: 55px;
        left: 0;
        right: 0;
        bottom: auto;
        width: 100%;
        padding: 25px 0 30px;
    }
    .page-header {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <h1>Edit Parent Account</h1>
            <a class="cancel-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/parent_management.php">Back to Parent Management</a>
        </div>

        <div class="parent-form-card">
            <?php if (!empty($errors)): ?>
                <div class="error-alert">
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo format_string($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="firstname">First Name <span class="required-star">*</span></label>
                        <input type="text" id="firstname" name="firstname" value="<?php echo format_string($parent->firstname); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="lastname">Last Name <span class="required-star">*</span></label>
                        <input type="text" id="lastname" name="lastname" value="<?php echo format_string($parent->lastname); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email <span class="required-star">*</span></label>
                        <input type="email" id="email" name="email" value="<?php echo format_string($parent->email); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username <span class="required-star">*</span></label>
                        <input type="text" id="username" name="username" value="<?php echo format_string($parent->username); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                        <small style="color: #6b7280; font-size: 0.85rem; margin-top: 5px; display: block;">Minimum 6 characters. Leave blank to keep current password.</small>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone (optional)</label>
                        <input type="text" id="phone" name="phone" value="<?php echo format_string($parent->phone1 ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="city">City (optional)</label>
                        <input type="text" id="city" name="city" value="<?php echo format_string($parent->city ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="country">Country (optional)</label>
                        <select id="country" name="country">
                            <option value="">Select Country</option>
                            <?php
                            require_once($CFG->dirroot . '/lib/moodlelib.php');
                            $countries = get_string_manager()->get_list_of_countries();
                            foreach ($countries as $code => $name) {
                                $selected = ($parent->country === $code) ? 'selected' : '';
                                echo "<option value='{$code}' {$selected}>" . htmlspecialchars($name) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top:20px;">
                    <label for="studentids">Select Students to Assign <span class="required-star">*</span></label>
                    <div class="student-filter-row">
                        <div class="cohort-filter-control">
                            <label for="cohortFilter">Filter by Cohort</label>
                            <div class="custom-cohort-dropdown">
                                <div class="cohort-dropdown-trigger" id="cohortFilterTrigger">
                                    <span class="trigger-text">All Cohorts</span>
                                    <span class="trigger-icon">
                                        <i class="fa fa-chevron-down"></i>
                                    </span>
                                </div>
                                <div class="cohort-dropdown-menu" id="cohortFilterMenu">
                                    <!-- Options will be populated by JavaScript -->
                                </div>
                                <input type="hidden" id="cohortFilter" value="">
                            </div>
                        </div>
                        <div class="student-select-control">
                            <label for="studentFilterTrigger">Select students <span class="required-star">*</span></label>
                            <div class="custom-student-dropdown">
                                <div class="student-dropdown-trigger" id="studentFilterTrigger">
                                    <i class="fa fa-search student-trigger-search-icon"></i>
                                    <input type="text" class="student-trigger-input" id="studentSearchInput" placeholder="Search students..." autocomplete="off">
                                    <span class="trigger-text">Select students</span>
                                    <span class="trigger-icon">
                                        <i class="fa fa-chevron-down"></i>
                                    </span>
                                </div>
                                <div class="student-dropdown-menu" id="studentFilterMenu">
                                    <div class="student-search-box">
                                        <i class="fa fa-search search-icon"></i>
                                        <input type="text" class="student-search-input" id="studentDropdownSearch" placeholder="Search students..." autocomplete="off">
                                    </div>
                                    <div class="student-dropdown-list" id="studentDropdownList">
                                        <!-- Options will be populated by JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="selected-students-list" id="selectedStudentsList">
                        <!-- Selected students will appear here -->
                    </div>
                    <input type="hidden" name="student_ids[]" id="hiddenStudentIds" value="">
                </div>

                <div class="form-group" style="margin-top:20px;">
                    <label for="note">Notes (optional)</label>
                    <textarea id="note" name="note" rows="3" placeholder="Internal notes or special considerations"><?php echo format_string($parent->description ?? ''); ?></textarea>
                </div>

                <div class="submit-section">
                    <button type="submit" class="submit-btn">Save Changes</button>
                    <a class="cancel-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/parent_management.php">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const studentsData = <?php echo json_encode(array_values($studentoptions)); ?>;
const cohortCounts = <?php echo json_encode($cohortcounts); ?>;
const initiallyAssignedStudentIds = <?php echo json_encode($assigned_child_ids); ?>;
let selectedStudentIds = [...initiallyAssignedStudentIds];

// Get DOM elements - will be initialized when DOM is ready
let studentSelectHidden, studentFilterTrigger, studentFilterMenu, studentSearchInput;
let studentDropdownSearch, cohortFilterHidden, cohortFilterTrigger, cohortFilterMenu, selectedStudentsList;

// SVG icon for three human figures
const cohortIconSVG = `<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white">
    <circle cx="6" cy="5.5" r="2.5"/>
    <path d="M6 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
    <circle cx="12" cy="5.5" r="2.5"/>
    <path d="M12 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
    <circle cx="18" cy="5.5" r="2.5"/>
    <path d="M18 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
</svg>`;

function buildCohortOptions() {
    // Ensure variable is initialized
    if (!cohortFilterMenu) cohortFilterMenu = document.getElementById('cohortFilterMenu');
    if (!cohortFilterMenu) return;
    
    cohortFilterMenu.innerHTML = '';
    
    const totalStudents = studentsData.length;
    const allCount = totalStudents === 1 ? '1 member' : `${totalStudents} members`;
    const allItem = createCohortItem('', 'All Cohorts', totalStudents, true);
    cohortFilterMenu.appendChild(allItem);
    
    const cohorts = Array.from(new Set(studentsData.map(s => s.cohort || 'Not assigned'))).sort();
    cohorts.forEach(cohort => {
        const count = cohortCounts[cohort] || 0;
        const item = createCohortItem(cohort, cohort, count, false);
        cohortFilterMenu.appendChild(item);
    });
}

function createCohortItem(value, name, count, isAll) {
    const item = document.createElement('div');
    item.className = 'cohort-dropdown-item';
    item.dataset.value = value;
    
    const membersText = count === 1 ? '1 member' : `${count} members`;
    item.innerHTML = `
        <div class="cohort-icon">${cohortIconSVG}</div>
        <div class="cohort-info">
            <div class="cohort-name">${name}</div>
            <div class="cohort-members">${membersText}</div>
        </div>
    `;
    
    item.addEventListener('click', () => selectCohort(value, name, count, isAll));
    return item;
}

function selectCohort(value, name, count, isAll) {
    // Ensure variables are initialized
    if (!cohortFilterHidden) cohortFilterHidden = document.getElementById('cohortFilter');
    if (!cohortFilterTrigger) cohortFilterTrigger = document.getElementById('cohortFilterTrigger');
    if (!cohortFilterMenu) cohortFilterMenu = document.getElementById('cohortFilterMenu');
    
    if (cohortFilterHidden) cohortFilterHidden.value = value;
    if (cohortFilterTrigger) {
        const membersText = count === 1 ? '1 member' : `${count} members`;
        const triggerText = cohortFilterTrigger.querySelector('.trigger-text');
        if (triggerText) triggerText.textContent = `${name} — ${membersText}`;
    }
    
    if (cohortFilterMenu) {
    const items = cohortFilterMenu.querySelectorAll('.cohort-dropdown-item');
    items.forEach(item => {
        item.classList.remove('selected');
        if (item.dataset.value === value) item.classList.add('selected');
    });
    }
    
    closeCohortDropdown();
    renderStudentOptions();
}

function openCohortDropdown() {
    if (cohortFilterTrigger && cohortFilterMenu) {
        cohortFilterTrigger.classList.add('active');
        cohortFilterMenu.classList.add('show');
    }
}

function closeCohortDropdown() {
    if (cohortFilterTrigger && cohortFilterMenu) {
        cohortFilterTrigger.classList.remove('active');
        cohortFilterMenu.classList.remove('show');
    }
}

function getInitials(firstname, lastname) {
    const first = firstname ? firstname.charAt(0).toUpperCase() : '';
    const last = lastname ? lastname.charAt(0).toUpperCase() : '';
    return (first + last) || '?';
}

function createStudentItem(student) {
    const item = document.createElement('div');
    item.className = 'student-dropdown-item';
    item.dataset.studentId = student.id;
    
    const isSelected = selectedStudentIds.includes(Number(student.id));
    if (isSelected) item.classList.add('selected');
    
    const initials = getInitials(student.firstname || '', student.lastname || '');
    const hasProfileImage = student.profileimage && student.profileimage.trim() !== '';
    
    let avatarHtml = hasProfileImage 
        ? `<img src="${student.profileimage}" alt="${student.name}">`
        : `<span class="initials">${initials}</span>`;
    
    item.innerHTML = `
        <div class="student-avatar">${avatarHtml}</div>
        <div class="student-info">
            <div class="student-name">${student.name}</div>
            <div class="student-username">@${student.username}</div>
        </div>
    `;
    
    item.addEventListener('click', () => toggleStudent(student));
    return item;
}

function toggleStudent(student) {
    const studentId = Number(student.id);
    const index = selectedStudentIds.indexOf(studentId);
    
    if (index > -1) {
        selectedStudentIds.splice(index, 1);
    } else {
        selectedStudentIds.push(studentId);
    }
    
    updateSelectedStudentsList();
    renderStudentOptions();
    updateHiddenInputs();
}

function renderStudentOptions() {
    // Ensure variables are initialized
    if (!cohortFilterHidden) cohortFilterHidden = document.getElementById('cohortFilter');
    if (!studentDropdownSearch) studentDropdownSearch = document.getElementById('studentDropdownSearch');
    
    const studentDropdownList = document.getElementById('studentDropdownList');
    if (!studentDropdownList) return;
    
    const cohortValue = cohortFilterHidden ? cohortFilterHidden.value : '';
    const searchQuery = studentDropdownSearch ? studentDropdownSearch.value.toLowerCase().trim() : '';
    
    studentDropdownList.innerHTML = '';
    
    const filteredStudents = studentsData.filter(student => {
        const matchesCohort = !cohortValue || student.cohort === cohortValue;
        const matchesSearch = !searchQuery || 
            student.name.toLowerCase().includes(searchQuery) ||
            student.username.toLowerCase().includes(searchQuery) ||
            (student.firstname && student.firstname.toLowerCase().includes(searchQuery)) ||
            (student.lastname && student.lastname.toLowerCase().includes(searchQuery));
        return matchesCohort && matchesSearch;
    });
    
    if (filteredStudents.length === 0) {
        studentDropdownList.innerHTML = '<div style="padding: 20px; text-align: center; color: #6c757d;">No students found</div>';
        return;
    }
    
    filteredStudents.forEach(student => {
        const item = createStudentItem(student);
        studentDropdownList.appendChild(item);
    });
}

function updateSelectedStudentsList() {
    // Ensure variable is initialized
    if (!selectedStudentsList) selectedStudentsList = document.getElementById('selectedStudentsList');
    if (!selectedStudentsList) return;
    
    selectedStudentsList.innerHTML = '';
    
    if (selectedStudentIds.length === 0) {
        selectedStudentsList.innerHTML = '<div class="no-children">No students selected</div>';
        return;
    }
    
    selectedStudentIds.forEach(studentId => {
        const student = studentsData.find(s => Number(s.id) === Number(studentId));
        if (!student) return;
        
        const initials = getInitials(student.firstname || '', student.lastname || '');
        const hasProfileImage = student.profileimage && student.profileimage.trim() !== '';
        
        let avatarHtml = hasProfileImage 
            ? `<img src="${student.profileimage}" alt="${student.name}">`
            : `<span class="initials">${initials}</span>`;
        
        const chip = document.createElement('div');
        chip.className = 'selected-student-chip';
        chip.innerHTML = `
            <div class="selected-student-chip-info">
                <div class="student-avatar">${avatarHtml}</div>
                <div class="student-name">${student.name}</div>
            </div>
            <button type="button" class="remove-student-btn" onclick="removeStudent(${student.id})">
                <i class="fa fa-times"></i>
            </button>
        `;
        selectedStudentsList.appendChild(chip);
    });
}

// Make removeStudent globally accessible for onclick handlers
window.removeStudent = function(studentId) {
    const index = selectedStudentIds.indexOf(Number(studentId));
    if (index > -1) {
        selectedStudentIds.splice(index, 1);
        updateSelectedStudentsList();
        renderStudentOptions();
        updateHiddenInputs();
    }
};

function updateHiddenInputs() {
    // Ensure variable is initialized
    if (!studentSelectHidden) studentSelectHidden = document.getElementById('hiddenStudentIds');
    if (!studentSelectHidden) return;
    
    // Remove all existing hidden inputs
    const existingInputs = document.querySelectorAll('input[name="student_ids[]"]');
    existingInputs.forEach(input => {
        if (input.id !== 'hiddenStudentIds') input.remove();
    });
    
    // Add hidden inputs for each selected student
    selectedStudentIds.forEach(studentId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_ids[]';
        input.value = studentId;
        studentSelectHidden.parentNode.insertBefore(input, studentSelectHidden);
    });
}

function openStudentDropdown() {
    // Ensure variables are initialized
    if (!studentFilterTrigger) studentFilterTrigger = document.getElementById('studentFilterTrigger');
    if (!studentFilterMenu) studentFilterMenu = document.getElementById('studentFilterMenu');
    if (!studentDropdownSearch) studentDropdownSearch = document.getElementById('studentDropdownSearch');
    
    if (studentFilterTrigger && studentFilterMenu) {
        studentFilterTrigger.classList.add('active');
        studentFilterTrigger.classList.add('search-mode');
        studentFilterMenu.classList.add('show');
        setTimeout(() => {
            if (studentDropdownSearch) studentDropdownSearch.focus();
        }, 100);
    }
}

function closeStudentDropdown() {
    // Ensure variables are initialized
    if (!studentFilterTrigger) studentFilterTrigger = document.getElementById('studentFilterTrigger');
    if (!studentFilterMenu) studentFilterMenu = document.getElementById('studentFilterMenu');
    if (!studentSearchInput) studentSearchInput = document.getElementById('studentSearchInput');
    if (!studentDropdownSearch) studentDropdownSearch = document.getElementById('studentDropdownSearch');
    
    if (studentFilterTrigger && studentFilterMenu) {
        studentFilterTrigger.classList.remove('active');
        studentFilterTrigger.classList.remove('search-mode');
        if (studentSearchInput) studentSearchInput.blur();
        if (studentDropdownSearch) studentDropdownSearch.blur();
        studentFilterMenu.classList.remove('show');
    }
}

// Initialize function - wrapped to ensure DOM is ready
function initializeEditParentForm() {
    // Get DOM elements
    studentSelectHidden = document.getElementById('hiddenStudentIds');
    studentFilterTrigger = document.getElementById('studentFilterTrigger');
    studentFilterMenu = document.getElementById('studentFilterMenu');
    studentSearchInput = document.getElementById('studentSearchInput');
    studentDropdownSearch = document.getElementById('studentDropdownSearch');
    cohortFilterHidden = document.getElementById('cohortFilter');
    cohortFilterTrigger = document.getElementById('cohortFilterTrigger');
    cohortFilterMenu = document.getElementById('cohortFilterMenu');
    selectedStudentsList = document.getElementById('selectedStudentsList');
    
    // Check if required elements exist
    if (!studentsData || studentsData.length === 0) {
        console.error('Edit Parent: No students data available');
        return;
    }
    
    if (!studentFilterMenu || !cohortFilterMenu || !selectedStudentsList) {
        console.error('Edit Parent: Required DOM elements not found', {
            studentFilterMenu: !!studentFilterMenu,
            cohortFilterMenu: !!cohortFilterMenu,
            selectedStudentsList: !!selectedStudentsList
        });
        // Try again after a short delay
        setTimeout(initializeEditParentForm, 200);
        return;
    }
    
    // Build cohort options
    buildCohortOptions();
    
    // Render student options
        renderStudentOptions();
    
    // Update selected students list (this will show previously assigned students)
    updateSelectedStudentsList();
    
    // Update hidden inputs
    updateHiddenInputs();
    
    // Set initial cohort display
    const totalStudents = studentsData.length;
    const allCount = totalStudents === 1 ? '1 member' : `${totalStudents} members`;
    if (cohortFilterTrigger) {
        const triggerText = cohortFilterTrigger.querySelector('.trigger-text');
        if (triggerText) {
            triggerText.textContent = `All Cohorts — ${allCount}`;
        }
    }
    
    // Cohort dropdown toggle
    if (cohortFilterTrigger) {
        cohortFilterTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (cohortFilterMenu.classList.contains('show')) {
                closeCohortDropdown();
            } else {
                openCohortDropdown();
            }
        });
    }
    
    // Student dropdown toggle
    if (studentFilterTrigger) {
        studentFilterTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (studentFilterMenu.classList.contains('show')) {
                closeStudentDropdown();
            } else {
                openStudentDropdown();
            }
        });
    }
    
    // Student search functionality in dropdown
    if (studentDropdownSearch) {
        let searchTimeout;
        studentDropdownSearch.addEventListener('input', (e) => {
            e.stopPropagation();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderStudentOptions();
            }, 200);
        });
        studentDropdownSearch.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    // Student trigger search input
    if (studentSearchInput) {
        studentSearchInput.addEventListener('click', (e) => {
            e.stopPropagation();
            openStudentDropdown();
        });
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        // Close cohort dropdown
        if (cohortFilterMenu && cohortFilterTrigger && 
            !cohortFilterMenu.contains(e.target) && 
            !cohortFilterTrigger.contains(e.target)) {
            closeCohortDropdown();
        }
        
        // Close student dropdown
        if (studentFilterMenu && studentFilterTrigger && 
            !studentFilterMenu.contains(e.target) && 
            !studentFilterTrigger.contains(e.target)) {
            closeStudentDropdown();
        }
    });
    
    // Debug: Log initial state
    console.log('Edit Parent Form Initialized:', {
        totalStudents: studentsData.length,
        initiallyAssigned: initiallyAssignedStudentIds.length,
        selectedStudentIds: selectedStudentIds.length,
        assignedIds: initiallyAssignedStudentIds
    });
}

// Initialize when DOM is ready
if (studentsData && studentsData.length > 0) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeEditParentForm);
    } else {
        // DOM is already ready, but wait a bit to ensure all elements are rendered
        setTimeout(initializeEditParentForm, 100);
    }
} else {
    console.error('Edit Parent: No students data available on page load');
}
</script>

<?php
echo $OUTPUT->footer();
?>
