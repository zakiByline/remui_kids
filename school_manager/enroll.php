<?php
/**
 * Enroll Users Page - Enroll students and teachers in courses
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

// Handle AJAX user search
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'search_users') {
    $search_term = trim($_POST['search']);
    
    if (strlen($search_term) >= 2) {
        try {
            $users = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
                 FROM {user} u
                 JOIN {company_users} cu ON u.id = cu.userid
                 WHERE cu.companyid = ? 
                 AND u.deleted = 0 
                 AND u.suspended = 0
                 AND (LOWER(u.firstname) LIKE ? 
                      OR LOWER(u.lastname) LIKE ? 
                      OR LOWER(u.email) LIKE ? 
                      OR LOWER(u.username) LIKE ?)
                 ORDER BY u.firstname, u.lastname
                 LIMIT 20",
                [
                    $company_info->id,
                    '%' . strtolower($search_term) . '%',
                    '%' . strtolower($search_term) . '%',
                    '%' . strtolower($search_term) . '%',
                    '%' . strtolower($search_term) . '%'
                ]
            );
            
            $response = [];
            foreach ($users as $user) {
                $response[] = [
                    'id' => $user->id,
                    'name' => $user->firstname . ' ' . $user->lastname,
                    'email' => $user->email
                ];
            }
            
            header('Content-Type: application/json');
            echo json_encode(['users' => $response]);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Search failed']);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['users' => []]);
        exit;
    }
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/enroll.php'));
$PAGE->set_title('Enroll Users - ' . $company_info->name);
$PAGE->set_heading('Enroll Users');

// Get available courses for the company
try {
    $available_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname
         FROM {course} c
         JOIN {company_course} cc ON c.id = cc.courseid
         WHERE cc.companyid = ? AND c.visible = 1 AND c.id > 1
         ORDER BY c.fullname",
        [$company_info->id]
    );
} catch (Exception $e) {
    $available_courses = [];
}

// Get available cohorts (system-wide, but we'll filter users by company)
try {
    $available_cohorts = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.name, c.idnumber
         FROM {cohort} c
         WHERE c.visible = 1
         ORDER BY c.name",
        []
    );
} catch (Exception $e) {
    $available_cohorts = [];
}

// Handle user search
$search_results = [];
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search_query)) {
    try {
        $search_results = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
             FROM {user} u
             JOIN {company_users} cu ON u.id = cu.userid
             WHERE cu.companyid = ? 
               AND u.deleted = 0 
               AND u.suspended = 0
               AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)
             ORDER BY u.firstname, u.lastname
             LIMIT 50",
            [$company_info->id, "%{$search_query}%", "%{$search_query}%", "%{$search_query}%", "%{$search_query}%"]
        );
    } catch (Exception $e) {
        $search_results = [];
    }
}

// Get available roles
$student_role = $DB->get_record('role', ['shortname' => 'student']);
$teacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
$schooladmin_role = $DB->get_record('role', ['shortname' => 'companycoursenoneditor']);

// Handle form submission
$enrollment_success = false;
$enrollment_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_users'])) {
    $selected_course = (int)$_POST['course_id'];
    $selected_users = $_POST['user_ids'] ?? [];
    $selected_cohorts = $_POST['cohort_ids'] ?? [];
    $selected_role = $_POST['role_id'];
    $enrollment_end_date = $_POST['enrollment_end_date'] ?? '';
    
    // Debug form data
    error_log("Form data received:");
    error_log("Course ID: " . $selected_course);
    error_log("User IDs: " . print_r($selected_users, true));
    error_log("Cohort IDs: " . print_r($selected_cohorts, true));
    error_log("Role ID: " . $selected_role);
    
    if (empty($selected_course)) {
        $enrollment_errors[] = 'Please select a course.';
    }
    
    if (empty($selected_users) && empty($selected_cohorts)) {
        $enrollment_errors[] = 'Please select at least one user or cohort.';
    }
    
    if (empty($selected_role)) {
        $enrollment_errors[] = 'Please select a role.';
    }
    
    if (empty($enrollment_errors)) {
        $course_context = context_course::instance($selected_course);
        
        // Get users from selected cohorts (only from our company)
        $cohort_users = [];
        if (!empty($selected_cohorts)) {
            error_log("Selected cohorts: " . implode(', ', $selected_cohorts));
            error_log("Company ID: " . $company_info->id);
            
            foreach ($selected_cohorts as $cohort_id) {
                try {
                    error_log("Getting members for cohort ID: " . $cohort_id);
                    
                    $cohort_members = $DB->get_records_sql(
                        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                         FROM {user} u
                         JOIN {cohort_members} cm ON u.id = cm.userid
                         JOIN {company_users} cu ON u.id = cu.userid
                         WHERE cm.cohortid = ? AND cu.companyid = ? AND u.deleted = 0 AND u.suspended = 0",
                        [$cohort_id, $company_info->id]
                    );
                    
                    error_log("Found " . count($cohort_members) . " members for cohort " . $cohort_id);
                    foreach ($cohort_members as $member) {
                        error_log("Cohort member: ID=" . $member->id . ", Name=" . $member->firstname . " " . $member->lastname);
                    }
                    
                    // Merge cohort members properly
                    foreach ($cohort_members as $member) {
                        $cohort_users[$member->id] = $member;
                    }
                } catch (Exception $e) {
                    $enrollment_errors[] = "Error getting cohort members: " . $e->getMessage();
                    error_log("Error getting cohort members: " . $e->getMessage());
                }
            }
        }
        
        // Combine individual users and cohort users
        $all_users = array_merge($selected_users, array_keys($cohort_users));
        $all_users = array_unique($all_users);
        
        // Filter out system users (Guest user, Admin users, etc.)
        $system_user_ids = $DB->get_fieldset_sql(
            "SELECT id FROM {user} WHERE username IN ('guest', 'admin') OR firstname = 'Guest' OR lastname = 'User'"
        );
        $all_users = array_diff($all_users, $system_user_ids);
        
        // Debug: Log the users being enrolled
        error_log("Users to be enrolled: " . implode(', ', $all_users));
        error_log("Selected users: " . implode(', ', $selected_users));
        error_log("Cohort users: " . implode(', ', array_keys($cohort_users)));
        
        // Calculate enrollment end time
        $enrollment_end_time = 0;
        if (!empty($enrollment_end_date)) {
            $enrollment_end_time = strtotime($enrollment_end_date);
            if ($enrollment_end_time === false) {
                $enrollment_errors[] = 'Invalid enrollment end date format.';
            }
        }
        
        if (empty($enrollment_errors)) {
            foreach ($all_users as $user_id) {
                try {
                    // Get the user record
                    $user = $DB->get_record('user', ['id' => $user_id]);
                    if (!$user) continue;
                    
                    // Check if user is already enrolled
                    $existing_enrollment = $DB->get_record_sql(
                        "SELECT ue.id 
                         FROM {user_enrolments} ue
                         JOIN {enrol} e ON ue.enrolid = e.id
                         WHERE e.courseid = ? AND ue.userid = ?",
                        [$selected_course, $user_id]
                    );
                    
                    if ($existing_enrollment) {
                        $enrollment_errors[] = "User {$user->firstname} {$user->lastname} ({$user->username}) is already enrolled in this course.";
                        error_log("User already enrolled: {$user->firstname} {$user->lastname} (ID: {$user_id}, Username: {$user->username})");
                        continue;
                    }
                    
                    // Get manual enrollment instance
                    $manual_enrol = $DB->get_record('enrol', ['courseid' => $selected_course, 'enrol' => 'manual', 'status' => 0]);
                    
                    if ($manual_enrol) {
                        // Create enrollment record
                        $enrollment_data = new stdClass();
                        $enrollment_data->enrolid = $manual_enrol->id;
                        $enrollment_data->userid = $user_id;
                        $enrollment_data->status = 0; // Active
                        $enrollment_data->timestart = time();
                        $enrollment_data->timeend = $enrollment_end_time;
                        $enrollment_data->timecreated = time();
                        $enrollment_data->timemodified = time();
                        
                        $enrollment_id = $DB->insert_record('user_enrolments', $enrollment_data);
                        
                        if ($enrollment_id) {
                            // Assign the role in course context
                            role_assign($selected_role, $user_id, $course_context->id);
                            $enrollment_success = true;
                        } else {
                            $enrollment_errors[] = "Failed to create enrollment record for user {$user->firstname} {$user->lastname}.";
                        }
                    } else {
                        $enrollment_errors[] = "Manual enrollment method not found for course.";
                    }
                    
                } catch (Exception $e) {
                    $enrollment_errors[] = "Error enrolling user {$user->firstname} {$user->lastname}: " . $e->getMessage();
                }
            }
        }
    }
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
    'enrollments_active' => true, // This page is for enrollments
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
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

// Custom CSS for the enrollment page
echo "<style>";
echo "
/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Reset and base styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f8f9fa;
    overflow-x: hidden;
}

/* School Manager Main Content Area */
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

.main-content {
    padding: 20px 20px 20px 20px;
    padding-top: 35px;
    min-height: 100vh;
    background: transparent;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 30px;
    margin-top: 25px;
    padding: 0 40px;
}

.page-title-section {
    flex: 1;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: #1e40af;
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.page-subtitle {
    font-size: 1.125rem;
    color: #3b82f6;
    margin: 0;
    font-weight: 400;
}

.back-btn {
    background: #f3f4f6;
    color: #374151;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 1rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.back-btn:hover {
    background: #e5e7eb;
    color: #374151;
    text-decoration: none;
}

/* Enrollment Form */
.enrollment-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin: 0 40px 2rem 40px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.enrollment-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1.5rem;
}

.enrollment-form {
    display: grid;
    gap: 1.5rem;
}

.search-form {
    display: grid;
    gap: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.form-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.form-select, .form-input {
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.2s ease;
    background: white;
}

.form-select:focus, .form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.chip-selector {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    position: relative;
    min-height: 60px;
}

.selected-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 8px;
    min-height: 20px;
}

.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dbeafe;
    color: #1e40af;
    padding: 4px 8px;
    border-radius: 16px;
    font-size: 0.875rem;
    border: 1px solid #93c5fd;
}

.chip-remove {
    cursor: pointer;
    font-weight: bold;
    color: #1e40af;
    margin-left: 4px;
}

.chip-remove:hover {
    color: #dc2626;
}

.selector-input {
    display: flex;
    align-items: center;
    border-top: 1px solid #e5e7eb;
    padding: 8px;
}

.selector-search {
    flex: 1;
    border: none;
    outline: none;
    padding: 4px 8px;
    font-size: 14px;
    background: transparent;
}

.dropdown-arrow {
    cursor: pointer;
    padding: 4px 8px;
    color: #6b7280;
    font-size: 12px;
    transition: transform 0.2s ease;
}

.dropdown-arrow:hover {
    color: #374151;
}

.dropdown-arrow.open {
    transform: rotate(180deg);
}

.dropdown-list {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.dropdown-item {
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background-color 0.2s ease;
}

.dropdown-item:hover {
    background-color: #f9fafb;
}

.dropdown-item:last-child {
    border-bottom: none;
}

.dropdown-item.disabled {
    color: #9ca3af;
    cursor: not-allowed;
}

.dropdown-item .user-name {
    font-weight: 500;
    color: #111827;
    margin-bottom: 2px;
}

.dropdown-item .user-email {
    font-size: 0.75rem;
    color: #6b7280;
}

.users-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
    max-height: 300px;
    overflow-y: auto;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    background: #f9fafb;
}

.user-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.user-checkbox:hover {
    background: #e5e7eb;
}

.user-checkbox input[type='checkbox'] {
    margin: 0;
}

.user-info {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.875rem;
}

.user-email {
    color: #6b7280;
    font-size: 0.75rem;
}

.submit-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    width: 200px;
    margin: 0 auto;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
}

/* Success/Error Messages */
.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-weight: 500;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
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
        gap: 1rem;
        align-items: stretch;
    }
    
    .enrollment-section {
        margin: 0 20px 2rem 20px;
    }
    
    .users-grid {
        grid-template-columns: 1fr;
    }
}
";

echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-title-section'>";
echo "<h1 class='page-title'>Enroll Users</h1>";
echo "<p class='page-subtitle'>Enroll students and teachers in courses</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/enrollments.php' class='back-btn'>";
echo "<i class='fa fa-arrow-left'></i> Back to Enrollments";
echo "</a>";
echo "</div>";

// Show success/error messages
if ($enrollment_success && empty($enrollment_errors)) {
    echo "<div class='alert alert-success'>";
    echo "<i class='fa fa-check-circle'></i> Users enrolled successfully!";
    echo "</div>";
}

if (!empty($enrollment_errors)) {
    echo "<div class='alert alert-error'>";
    echo "<i class='fa fa-exclamation-triangle'></i> ";
    echo "<ul style='margin: 0; padding-left: 1rem;'>";
    foreach ($enrollment_errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

// (Moved) Search UI will be rendered below, between role and cohorts

// Enrollment Form
echo "<div class='enrollment-section'>";
echo "<h2 class='enrollment-title'>Enroll Users in Course</h2>";

// Start POST enrollment form
echo "<form method='POST' class='enrollment-form'>";

// Course Selection
echo "<div class='form-group'>";
echo "<label class='form-label'>Select Course</label>";
echo "<select name='course_id' class='form-select' required>";
echo "<option value=''>Choose a course...</option>";
foreach ($available_courses as $course) {
    echo "<option value='{$course->id}'>" . htmlspecialchars($course->fullname) . "</option>";
}
echo "</select>";
echo "</div>";

// Role Selection
echo "<div class='form-group'>";
echo "<label class='form-label'>Select Role</label>";
echo "<select name='role_id' class='form-select' required>";
echo "<option value=''>Choose a role...</option>";
if ($student_role) {
    echo "<option value='{$student_role->id}'>Student</option>";
}
if ($teacher_role) {
    echo "<option value='{$teacher_role->id}'>Teacher</option>";
}
if ($schooladmin_role) {
    echo "<option value='{$schooladmin_role->id}'>School Admin</option>";
}
echo "</select>";
echo "</div>";

// User Selection with Chips
echo "<div class='form-group'>";
echo "<label class='form-label'>Select Users (Optional)</label>";
echo "<div class='chip-selector' id='user-selector'>";
echo "<div class='selected-chips' id='selected-users'></div>";
echo "<div class='selector-input'>";
echo "<input type='text' class='selector-search' placeholder='Search users...' id='user-search'>";
echo "<div class='dropdown-arrow' onclick='toggleUserDropdown()'>▼</div>";
echo "</div>";
echo "<div class='dropdown-list' id='user-dropdown' style='display: none;'>";
echo "<div class='dropdown-item disabled'>Type at least 2 characters to search users</div>";
echo "</div>";
echo "</div>";
echo "</div>";

// Cohort Selection with Chips
echo "<div class='form-group'>";
echo "<label class='form-label'>Select Cohorts (Optional)</label>";
echo "<div class='chip-selector' id='cohort-selector'>";
echo "<div class='selected-chips' id='selected-cohorts'></div>";
echo "<div class='selector-input'>";
echo "<input type='text' class='selector-search' placeholder='Search cohorts...' id='cohort-search'>";
echo "<div class='dropdown-arrow' onclick='toggleCohortDropdown()'>▼</div>";
echo "</div>";
echo "<div class='dropdown-list' id='cohort-dropdown' style='display: none;'>";
foreach ($available_cohorts as $cohort) {
    echo "<div class='dropdown-item' onclick='selectCohort({$cohort->id}, \"" . htmlspecialchars($cohort->name) . "\")'>";
    echo htmlspecialchars($cohort->name);
    echo "</div>";
}
echo "</div>";
echo "</div>";
echo "</div>";

// Enrollment End Date
echo "<div class='form-group'>";
echo "<label class='form-label'>Enrollment End Date (Optional)</label>";
echo "<input type='datetime-local' name='enrollment_end_date' class='form-input'>";
echo "<small style='color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;'>Leave empty for no expiration</small>";
echo "</div>";

// Submit Button
echo "<button type='submit' name='enroll_users' class='submit-btn'>";
echo "<i class='fa fa-user-plus'></i> Enroll Users";
echo "</button>";

echo "</form>";
echo "</div>";

echo "</div>"; // End main-content
echo "</div>"; // End school-manager-main-content

// JavaScript for chip selectors
echo "<script>";
echo "
console.log('JavaScript loaded successfully');
let selectedUsers = [];
let selectedCohorts = [];

function toggleUserDropdown() {
    console.log('toggleUserDropdown called');
    const dropdown = document.getElementById('user-dropdown');
    const arrow = document.querySelector('#user-selector .dropdown-arrow');
    const isOpen = dropdown.style.display !== 'none';
    
    console.log('Dropdown found:', dropdown);
    console.log('Arrow found:', arrow);
    console.log('Is open:', isOpen);
    
    dropdown.style.display = isOpen ? 'none' : 'block';
    arrow.classList.toggle('open', !isOpen);
}

function toggleCohortDropdown() {
    console.log('toggleCohortDropdown called');
    const dropdown = document.getElementById('cohort-dropdown');
    const arrow = document.querySelector('#cohort-selector .dropdown-arrow');
    const isOpen = dropdown.style.display !== 'none';
    
    console.log('Cohort dropdown found:', dropdown);
    console.log('Cohort arrow found:', arrow);
    console.log('Cohort is open:', isOpen);
    
    dropdown.style.display = isOpen ? 'none' : 'block';
    arrow.classList.toggle('open', !isOpen);
}

function selectUser(id, name, email) {
    if (selectedUsers.find(u => u.id === id)) return;
    
    selectedUsers.push({id, name, email});
    updateUserChips();
    updateHiddenInputs();
    toggleUserDropdown();
}

function selectCohort(id, name) {
    if (selectedCohorts.find(c => c.id === id)) return;
    
    selectedCohorts.push({id, name});
    updateCohortChips();
    updateHiddenInputs();
    toggleCohortDropdown();
}

function removeUser(id) {
    selectedUsers = selectedUsers.filter(u => u.id !== id);
    updateUserChips();
    updateHiddenInputs();
}

function removeCohort(id) {
    selectedCohorts = selectedCohorts.filter(c => c.id !== id);
    updateCohortChips();
    updateHiddenInputs();
}

function updateUserChips() {
    const container = document.getElementById('selected-users');
    container.innerHTML = '';
    
    selectedUsers.forEach(user => {
        const chip = document.createElement('div');
        chip.className = 'chip';
        chip.innerHTML = '<span class=\"chip-remove\" onclick=\"removeUser(' + user.id + ')\">×</span><span>👤</span><span>' + user.name + '</span><span style=\"font-size: 0.75rem; opacity: 0.8;\">' + user.email + '</span>';
        container.appendChild(chip);
    });
}

function updateCohortChips() {
    const container = document.getElementById('selected-cohorts');
    container.innerHTML = '';
    
    selectedCohorts.forEach(cohort => {
        const chip = document.createElement('div');
        chip.className = 'chip';
        chip.innerHTML = '<span class=\"chip-remove\" onclick=\"removeCohort(' + cohort.id + ')\">×</span><span>' + cohort.name + '</span>';
        container.appendChild(chip);
    });
}

function updateHiddenInputs() {
    // Remove existing hidden inputs
    document.querySelectorAll('input[name=\"user_ids[]\"]').forEach(input => input.remove());
    document.querySelectorAll('input[name=\"cohort_ids[]\"]').forEach(input => input.remove());
    
    // Add new hidden inputs for selected users
    selectedUsers.forEach(user => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'user_ids[]';
        input.value = user.id;
        document.querySelector('.enrollment-form').appendChild(input);
    });
    
    // Add new hidden inputs for selected cohorts
    selectedCohorts.forEach(cohort => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'cohort_ids[]';
        input.value = cohort.id;
        document.querySelector('.enrollment-form').appendChild(input);
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.chip-selector')) {
        document.getElementById('user-dropdown').style.display = 'none';
        document.getElementById('cohort-dropdown').style.display = 'none';
        document.querySelectorAll('.dropdown-arrow').forEach(arrow => arrow.classList.remove('open'));
    }
});

// Search functionality with debouncing
let searchTimeout;

document.getElementById('user-search').addEventListener('input', function(e) {
    const searchTerm = e.target.value.trim();
    
    // Clear previous timeout
    clearTimeout(searchTimeout);
    
    if (searchTerm.length < 2) {
        document.getElementById('user-dropdown').innerHTML = '<div class=\"dropdown-item disabled\">Type at least 2 characters to search</div>';
        return;
    }
    
    // Debounce search - wait 500ms after user stops typing
    searchTimeout = setTimeout(() => {
        searchUsers(searchTerm);
    }, 500);
});

document.getElementById('cohort-search').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const items = document.querySelectorAll('#cohort-dropdown .dropdown-item');
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(searchTerm) ? 'block' : 'none';
    });
});

// Test if elements exist when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded');
    console.log('User dropdown element:', document.getElementById('user-dropdown'));
    console.log('Cohort dropdown element:', document.getElementById('cohort-dropdown'));
    console.log('User search element:', document.getElementById('user-search'));
    console.log('Cohort search element:', document.getElementById('cohort-search'));
});

function searchUsers(searchTerm) {
    // Show loading state
    document.getElementById('user-dropdown').innerHTML = '<div class=\"dropdown-item disabled\">Searching...</div>';
    
    // Create form data for AJAX request
    const formData = new FormData();
    formData.append('action', 'search_users');
    formData.append('search', searchTerm);
    
    // Make AJAX request to search users
    fetch('enroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const dropdown = document.getElementById('user-dropdown');
        dropdown.innerHTML = '';
        
        if (data.users && data.users.length > 0) {
            data.users.forEach(user => {
                const item = document.createElement('div');
                item.className = 'dropdown-item';
                item.onclick = () => selectUser(user.id, user.name, user.email);
                item.innerHTML = '<div class=\"user-name\">' + user.name + '</div><div class=\"user-email\">' + user.email + '</div>';
                dropdown.appendChild(item);
            });
        } else {
            dropdown.innerHTML = '<div class=\"dropdown-item disabled\">No users found</div>';
        }
    })
    .catch(error => {
        console.error('Search error:', error);
        document.getElementById('user-dropdown').innerHTML = '<div class=\"dropdown-item disabled\">Search failed</div>';
    });
}
";
echo "</script>";

echo $OUTPUT->footer();
?>
