<?php
require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/adminlib.php');

// Ensure user is logged in
require_login();

// Get current user and company information
$user = $USER;
$company_id = null;
$company_info = null;

// Get company information for the current user
try {
    $company_info = $DB->get_record_sql(
        "SELECT c.*, cu.managertype 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$user->id]
    );
    
    if ($company_info) {
        $company_id = $company_info->id;
    }
} catch (Exception $e) {
    error_log("Error getting company info: " . $e->getMessage());
}

// Redirect if not a school manager
if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Access denied. You must be a school manager to access this page.', null, \core\output\notification::NOTIFY_ERROR);
}

// Handle bulk delete action (permanent deletion)
if (isset($_POST['bulk_delete']) && isset($_POST['selected_students'])) {
    require_sesskey();
    
    $selected_students = $_POST['selected_students'];
    $deleted_count = 0;
    $errors = [];
    
    if (!empty($selected_students) && is_array($selected_students)) {
        require_once($CFG->dirroot . '/user/lib.php');
        
        foreach ($selected_students as $student_id) {
            $student_id = (int)$student_id;
            
            // Verify the student belongs to this company
            if ($company_info) {
                $student_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$student_id, $company_info->id]
                );
                
                if ($student_in_company) {
                    $user = $DB->get_record('user', ['id' => $student_id]);
                    if ($user) {
                        // Permanent delete - remove from database
                        $fullname = fullname($user);
                        if (user_delete_user($user)) {
                            $deleted_count++;
                        } else {
                            $errors[] = "Failed to delete student '{$fullname}'.";
                        }
                    }
                } else {
                    $errors[] = "Student ID {$student_id} does not belong to your school.";
                }
            }
        }
        
        if ($deleted_count > 0) {
            $message = "Successfully permanently deleted {$deleted_count} student(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No students were deleted. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/student_management.php'), $message, null, $type);
    }
}

// Handle bulk suspend action
if (isset($_POST['bulk_suspend']) && isset($_POST['selected_students'])) {
    require_sesskey();
    
    $selected_students = $_POST['selected_students'];
    $suspended_count = 0;
    $errors = [];
    
    if (!empty($selected_students) && is_array($selected_students)) {
        foreach ($selected_students as $student_id) {
            $student_id = (int)$student_id;
            
            // Verify the student belongs to this company
            if ($company_info) {
                $student_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$student_id, $company_info->id]
                );
                
                if ($student_in_company) {
                    $user = $DB->get_record('user', ['id' => $student_id]);
                    if ($user) {
                        // Suspend the student (soft delete)
                        $user->suspended = 1;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $suspended_count++;
                    }
                } else {
                    $errors[] = "Student ID {$student_id} does not belong to your school.";
                }
            }
        }
        
        if ($suspended_count > 0) {
            $message = "Successfully suspended {$suspended_count} student(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No students were suspended. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/student_management.php'), $message, null, $type);
    }
}

// Handle bulk activate action
if (isset($_POST['bulk_activate']) && isset($_POST['selected_students'])) {
    require_sesskey();
    
    $selected_students = $_POST['selected_students'];
    $activated_count = 0;
    $errors = [];
    
    if (!empty($selected_students) && is_array($selected_students)) {
        foreach ($selected_students as $student_id) {
            $student_id = (int)$student_id;
            
            // Verify the student belongs to this company
            if ($company_info) {
                $student_in_company = $DB->record_exists_sql(
                    "SELECT 1 FROM {company_users} cu 
                     WHERE cu.userid = ? AND cu.companyid = ?",
                    [$student_id, $company_info->id]
                );
                
                if ($student_in_company) {
                    $user = $DB->get_record('user', ['id' => $student_id]);
                    if ($user) {
                        // Activate the student
                        $user->suspended = 0;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $activated_count++;
                    }
                } else {
                    $errors[] = "Student ID {$student_id} does not belong to your school.";
                }
            }
        }
        
        if ($activated_count > 0) {
            $message = "Successfully activated {$activated_count} student(s).";
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $message = "No students were activated. " . implode(' ', $errors);
            $type = \core\output\notification::NOTIFY_WARNING;
        }
        
        redirect(new moodle_url('/theme/remui_kids/student_management.php'), $message, null, $type);
    }
}

// Handle individual actions (delete, suspend, activate)
if (isset($_GET['action']) && isset($_GET['student_id'])) {
    $action = $_GET['action'];
    $student_id = (int)$_GET['student_id'];
    
    // Verify the student belongs to this company
    if ($company_info) {
        $student_in_company = $DB->record_exists_sql(
            "SELECT 1 FROM {company_users} cu 
             WHERE cu.userid = ? AND cu.companyid = ?",
            [$student_id, $company_info->id]
        );
        
        if ($student_in_company) {
            $user = $DB->get_record('user', ['id' => $student_id]);
            if ($user && confirm_sesskey()) {
                $fullname = fullname($user);
                
                switch ($action) {
                    case 'permanent_delete':
                        // Permanent delete - remove from database
                        require_once($CFG->dirroot . '/user/lib.php');
                        
                        if (user_delete_user($user)) {
                            $message = "Student '" . $fullname . "' has been permanently deleted.";
                            $type = \core\output\notification::NOTIFY_SUCCESS;
                        } else {
                            $message = "Error deleting student '" . $fullname . "'.";
                            $type = \core\output\notification::NOTIFY_ERROR;
                        }
                        break;
                    
                    case 'suspend':
                        // Suspend the student
                        $user->suspended = 1;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Student '" . $fullname . "' has been suspended.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                        break;
                    
                    case 'activate':
                        // Activate the student
                        $user->suspended = 0;
                        $user->timemodified = time();
                        $DB->update_record('user', $user);
                        $message = "Student '" . $fullname . "' has been activated.";
                        $type = \core\output\notification::NOTIFY_SUCCESS;
                        break;
                }
                
                if (isset($message)) {
                    redirect(new moodle_url('/theme/remui_kids/student_management.php'), $message, null, $type);
                }
            }
        }
    }
}

// Get search parameters
$search_query = optional_param('search', '', PARAM_TEXT);
$search_field = optional_param('search_field', 'all', PARAM_TEXT);
$cohort_filter = optional_param('cohort_filter', 'all', PARAM_TEXT);

// Fetch cohorts for this company
$cohorts_list = [];
if ($company_info && $DB->get_manager()->table_exists('cohort') && $DB->get_manager()->table_exists('cohort_members')) {
    try {
        $cohorts = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.name, c.idnumber,
                    (SELECT COUNT(DISTINCT cm.userid)
                     FROM {cohort_members} cm
                     INNER JOIN {user} u ON u.id = cm.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     INNER JOIN {role_assignments} ra ON ra.userid = u.id
                     INNER JOIN {role} r ON r.id = ra.roleid
                     WHERE cm.cohortid = c.id
                     AND cu.companyid = ?
                     AND r.shortname = 'student'
                     AND u.deleted = 0
                     AND u.suspended = 0) AS student_count
             FROM {cohort} c
             WHERE c.visible = 1
             AND EXISTS (
                 SELECT 1
                 FROM {cohort_members} cm
                 INNER JOIN {user} u ON u.id = cm.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid
                 WHERE cm.cohortid = c.id
                 AND cu.companyid = ?
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 AND u.suspended = 0
             )
             ORDER BY c.name ASC",
            [$company_info->id, $company_info->id]
        );
        
        foreach ($cohorts as $cohort) {
            $cohorts_list[] = [
                'id' => (int)$cohort->id,
                'name' => $cohort->name,
                'idnumber' => $cohort->idnumber ?? '',
                'student_count' => (int)$cohort->student_count
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching cohorts: " . $e->getMessage());
    }
}

// Get students for this company (ONLY users with 'student' role)
$students = [];
try {
    // First try the IOMAD approach (company_users table exists)
    if ($DB->get_manager()->table_exists('company_users')) {
        // Primary query: Students in company_users with student role (including cohort info)
        $students = $DB->get_records_sql(
            "SELECT u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.phone1,
                    u.username,
                    u.suspended,
                    u.lastaccess,
                    cu.educator,
                    GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                    uifd.data AS grade_level,
                    GROUP_CONCAT(DISTINCT coh.id SEPARATOR ',') AS cohort_ids,
                    GROUP_CONCAT(DISTINCT coh.name SEPARATOR ', ') AS cohort_names
               FROM {user} u
               INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
               INNER JOIN {role_assignments} ra ON ra.userid = u.id
               INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
               LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
               LEFT JOIN {cohort_members} cm ON cm.userid = u.id
               LEFT JOIN {cohort} coh ON coh.id = cm.cohortid AND coh.visible = 1
              WHERE u.deleted = 0
            GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
            [$company_id]
        );
        error_log("Found " . count($students) . " students using IOMAD approach for company ID: " . $company_id);
        
        // If no students found, try alternative approach: Students in company_users (any role)
        if (empty($students)) {
            error_log("No students found with student role, trying alternative query...");
            $alternative_students = $DB->get_records_sql(
                "SELECT u.id,
                        u.firstname,
                        u.lastname,
                        u.email,
                        u.phone1,
                        u.username,
                        u.suspended,
                        u.lastaccess,
                        cu.educator,
                        'student' as roles,
                        uifd.data AS grade_level
                   FROM {user} u
                   INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                   LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                   LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                  WHERE u.deleted = 0 AND cu.educator = 0
                GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
                [$company_id]
            );
            
            if (!empty($alternative_students)) {
                error_log("Found " . count($alternative_students) . " students using alternative approach (company_users only)");
                
                // Assign student role to users who don't have it
                foreach ($alternative_students as $alt_student) {
                    $student_role = $DB->get_record('role', ['shortname' => 'student']);
                    if ($student_role) {
                        $has_student_role = $DB->record_exists('role_assignments', [
                            'userid' => $alt_student->id,
                            'roleid' => $student_role->id,
                            'contextid' => context_system::instance()->id
                        ]);
                        
                        if (!$has_student_role) {
                            try {
                                role_assign($student_role->id, $alt_student->id, context_system::instance()->id);
                                error_log("Assigned student role to user ID: " . $alt_student->id);
                            } catch (Exception $role_error) {
                                error_log("Error assigning student role to user " . $alt_student->id . ": " . $role_error->getMessage());
                            }
                        }
                    }
                }
                
                // Retry the primary query (with cohort info)
                $students = $DB->get_records_sql(
                    "SELECT u.id,
                            u.firstname,
                            u.lastname,
                            u.email,
                            u.phone1,
                            u.username,
                            u.suspended,
                            u.lastaccess,
                            cu.educator,
                            GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                            uifd.data AS grade_level,
                            GROUP_CONCAT(DISTINCT coh.id SEPARATOR ',') AS cohort_ids,
                            GROUP_CONCAT(DISTINCT coh.name SEPARATOR ', ') AS cohort_names
                       FROM {user} u
                       INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                       INNER JOIN {role_assignments} ra ON ra.userid = u.id
                       INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                       LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                       LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                       LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                       LEFT JOIN {cohort} coh ON coh.id = cm.cohortid AND coh.visible = 1
                      WHERE u.deleted = 0
                    GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
                    [$company_id]
                );
                error_log("Found " . count($students) . " students after role assignment fix");
            }
        }
    } else {
        // Fallback: Get all users with student role (no company association)
        $students = $DB->get_records_sql(
            "SELECT u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.phone1,
                    u.username,
                    u.suspended,
                    u.lastaccess,
                    '0' as educator,
                    GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                    uifd.data AS grade_level
               FROM {user} u
               INNER JOIN {role_assignments} ra ON ra.userid = u.id
               INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
               LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
              WHERE u.deleted = 0
            GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, uifd.data",
            []
        );
        error_log("Found " . count($students) . " students using fallback approach (no company association)");
    }
} catch (Exception $e) {
    error_log("Error getting students: " . $e->getMessage());
    $students = [];
}

// Apply search filter if provided
if (!empty($search_query)) {
    $filtered_students = [];
    
    foreach ($students as $student) {
        $match = false;
        $relevance_score = 0;
        
        switch ($search_field) {
            case 'name':
                $name = strtolower($student->firstname . ' ' . $student->lastname);
                if (strpos($name, strtolower($search_query)) !== false) {
                    $match = true;
                    if ($name === strtolower($search_query)) $relevance_score = 100;
                    elseif (strpos($name, strtolower($search_query)) === 0) $relevance_score = 80;
                    else $relevance_score = 60;
                }
                break;
            case 'email':
                if (strpos(strtolower($student->email), strtolower($search_query)) !== false) {
                    $match = true;
                    if (strtolower($student->email) === strtolower($search_query)) $relevance_score = 100;
                    elseif (strpos(strtolower($student->email), strtolower($search_query)) === 0) $relevance_score = 80;
                    else $relevance_score = 60;
                }
                break;
            case 'cohort':
                if (strpos(strtolower($student->grade_level ?: ''), strtolower($search_query)) !== false) {
                    $match = true;
                    $relevance_score = 60;
                }
                break;
            case 'grade':
                if (strpos(strtolower($student->grade_level ?: ''), strtolower($search_query)) !== false) {
                    $match = true;
                    $relevance_score = 60;
                }
                break;
            case 'username':
                if (strpos(strtolower($student->username), strtolower($search_query)) !== false) {
                    $match = true;
                    if (strtolower($student->username) === strtolower($search_query)) $relevance_score = 100;
                    elseif (strpos(strtolower($student->username), strtolower($search_query)) === 0) $relevance_score = 80;
                    else $relevance_score = 60;
                }
                break;
            case 'all':
            default:
                $name = strtolower($student->firstname . ' ' . $student->lastname);
                $email = strtolower($student->email);
                $cohort = strtolower($student->grade_level ?: '');
                $username = strtolower($student->username);
                $search_lower = strtolower($search_query);
                
                if (strpos($name, $search_lower) !== false || 
                    strpos($email, $search_lower) !== false || 
                    strpos($cohort, $search_lower) !== false || 
                    strpos($username, $search_lower) !== false) {
                    $match = true;
                    
                    // Calculate relevance score
                    if ($name === $search_lower) $relevance_score = 100;
                    elseif (strpos($name, $search_lower) === 0) $relevance_score = 85;
                    elseif ($email === $search_lower || $username === $search_lower) $relevance_score = 90;
                    elseif (strpos($email, $search_lower) === 0 || strpos($username, $search_lower) === 0) $relevance_score = 75;
                    elseif (strpos($name, $search_lower) !== false) $relevance_score = 70;
                    elseif (strpos($email, $search_lower) !== false || strpos($username, $search_lower) !== false) $relevance_score = 60;
                    elseif (strpos($cohort, $search_lower) !== false) $relevance_score = 50;
                }
                break;
        }
        
        if ($match) {
            $student->relevance_score = $relevance_score;
            $filtered_students[] = $student;
        }
    }
    
    // Sort by relevance score
    usort($filtered_students, function($a, $b) {
        if ($a->relevance_score == $b->relevance_score) {
            return strcasecmp($a->firstname . ' ' . $a->lastname, $b->firstname . ' ' . $b->lastname);
        }
        return $b->relevance_score - $a->relevance_score;
    });
    
    $students = $filtered_students;
}

// Apply cohort filter if provided
if (!empty($cohort_filter) && $cohort_filter !== 'all') {
    $cohort_filter_id = (int)$cohort_filter;
    $filtered_by_cohort = [];
    
    foreach ($students as $student) {
        $student_cohort_ids = !empty($student->cohort_ids) ? explode(',', $student->cohort_ids) : [];
        
        // Check if student belongs to the selected cohort
        if (in_array($cohort_filter_id, array_map('intval', $student_cohort_ids))) {
            $filtered_by_cohort[] = $student;
        }
    }
    
    $students = $filtered_by_cohort;
}

// Calculate statistics
$total_students = count($students);
$active_students = count(array_filter($students, function($s) { return !$s->suspended; }));
$suspended_students = count(array_filter($students, function($s) { return $s->suspended; }));

// Debug logging
error_log("Student Management Debug - Company ID: " . $company_id . ", Total Students: " . $total_students . ", Active: " . $active_students);
if (!empty($students)) {
    error_log("Sample student data: " . print_r(array_slice($students, 0, 1), true));
}

// Count enrolled students (students who are enrolled in courses) - ONLY 'student' role
$enrolled_students = 0;
try {
    $enrolled_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
           FROM {user} u
           INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
           INNER JOIN {role_assignments} ra ON ra.userid = u.id
           INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
           INNER JOIN {user_enrolments} ue ON ue.userid = u.id
           INNER JOIN {enrol} e ON e.id = ue.enrolid
           INNER JOIN {course} c ON c.id = e.courseid
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
          WHERE u.deleted = 0
            AND ue.status = 0
            AND e.status = 0
            AND c.visible = 1",
        [$company_id, $company_id]
    );
} catch (Exception $e) {
    error_log("Error counting enrolled students: " . $e->getMessage());
}

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => '',
    'has_logo' => false,
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'students_active' => true,
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

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/student_management.php');
$PAGE->set_title('Student Management - ' . $company_info->name);
$PAGE->set_heading('Student Management');

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

// Custom CSS for the student management layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background-color: #ffffff;
    overflow-y: auto;
    z-index: 99;
    padding-top: 35px;
    transition: left 0.3s ease, width 0.3s ease;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 0 30px 30px 30px;
}

.students-container{
    background-color: #fff;
}
/* Page Header */
.page-header {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 0.75rem;
    padding: 2rem 2.5rem;
    margin-bottom: 2rem;
    margin-top: 0;
    border: 1px solid #e2e8f0;
    box-shadow: 
        0 1px 3px rgba(0, 0, 0, 0.05),
        0 4px 12px rgba(0, 0, 0, 0.04);
    position: relative;
    overflow: visible;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #3b82f6 0%, #06b6d4 100%);
    border-radius: 0.75rem 0 0 0.75rem;
}

.page-header-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}

.page-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.75rem 0;
    letter-spacing: -0.5px;
    line-height: 1.2;
    font-family: 'Inter', 'Segoe UI', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
}

.page-subtitle {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0;
    font-weight: 400;
    line-height: 1.5;
}

/* Back Button */
.back-button {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border: none;
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 
        0 2px 8px rgba(59, 130, 246, 0.2),
        0 1px 4px rgba(37, 99, 235, 0.15);
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.back-button:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 
        0 4px 12px rgba(59, 130, 246, 0.3),
        0 2px 6px rgba(37, 99, 235, 0.2);
    color: #ffffff;
    text-decoration: none;
}

/* Stats Section */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
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
    text-align: center;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.stat-card.students-card {
    border-top-color: #667eea; /* Purple/Blue for Total Students */
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
}

.stat-card.active-students-card {
    border-top-color: #43e97b; /* Green for Active Students */
    background: linear-gradient(180deg, rgba(67, 233, 123, 0.08), #ffffff);
}

.stat-card.enrolled-students-card {
    border-top-color: #4facfe; /* Blue for Enrolled Students */
    background: linear-gradient(180deg, rgba(79, 172, 254, 0.08), #ffffff);
}

.stat-card.suspended-students-card {
    border-top-color: #ff6b6b; /* Red for Suspended Students */
    background: linear-gradient(180deg, rgba(255, 107, 107, 0.08), #ffffff);
}

.stat-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.stat-trend {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    justify-content: center;
}

.trend-arrow {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.trend-text {
    color: #28a745;
    font-size: 0.75rem;
    font-weight: 600;
}

.stat-icon {
    display: none; /* Hide icons to match the clean centered design */
}

.stat-action {
    display: none; /* Hide action arrows to match the clean centered design */
}

.stat-link {
    display: none;
}

/* Search Section */
.search-section {
    background: #ffffff;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.search-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
}

.add-student-btn {
    background: #9D7ECE;
    color: white;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.add-student-btn:hover {
    background: #8B6FC5;
    color: white;
    text-decoration: none;
}

.search-container {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.search-input-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}

.search-field-select {
    padding: 12px 40px 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: white;
    font-size: 0.9rem;
    color: #4a5568;
    min-width: 180px;
    transition: all 0.3s ease;
    cursor: pointer;
    appearance: none;
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-image: url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A//www.w3.org/2000/svg%27%20width%3D%2712%27%20height%3D%2712%27%20viewBox%3D%270%200%2012%2012%27%3E%3Cpath%20fill%3D%27%234a5568%27%20d%3D%27M6%209L1%204h10z%27/%3E%3C/svg%3E');
}

.search-field-select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.cohort-filter-select {
    min-width: 200px;
}

.search-input {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #4a5568;
    transition: all 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.clear-search-btn {
    background: #e2e8f0;
    color: #4a5568;
    border: none;
    padding: 12px 15px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.clear-search-btn:hover {
    background: #cbd5e0;
    color: #2d3748;
    text-decoration: none;
}

.search-results-info {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 15px;
    margin-top: 15px;
    display: none;
}

.search-results-count {
    font-size: 0.9rem;
    color: #4a5568;
    font-weight: 500;
}

/* Add Student Button */
.add-student-btn {
    background: #9D7ECE;
    color: white;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.add-student-btn:hover {
    background: #8B6FC5;
    color: white;
    text-decoration: none;
}

/* Student Actions Container */
.student-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.bulk-upload-student-btn {
    background: #4A90E2;
    color: white;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.bulk-upload-student-btn:hover {
    background: #3A7BC8;
    color: white;
    text-decoration: none;
}

.bulk-upload-student-btn i {
    font-size: 14px;
}


/* Live Search Enhancements */
.search-input:focus {
    border-color: #007bff !important;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
}

.search-input.searching {
    background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23999\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E');
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 16px 16px;
}

.students-table tr {
    transition: opacity 0.2s ease;
}

.students-table tr.hidden {
    display: none !important;
}

.search-results-info {
    transition: all 0.3s ease;
}

/* Responsive Search Bar */
@media (max-width: 768px) {
    .search-input-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-field-select,
    .search-input {
        min-width: auto;
        width: 100%;
    }
    
    .search-btn,
    .clear-search-btn {
        width: 100%;
        justify-content: center;
    }
}

/* Table Styles */
.students-table {
    width: 100%;
    border-collapse: collapse;
}

.students-table th {
    background: #f8f9fa;
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
}

.students-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

/* Card-like row visuals */
.students-table tbody tr td { border-bottom: 0; }
.students-table tbody tr + tr td { border-top: 1px solid #f0f2f5; }

.user-cell { display: flex; align-items: center; gap: 12px; }
.user-avatar { position: relative; width: 36px; height: 36px; border-radius: 50%; background: #eef2ff; color: #4f46e5; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; transition: all 0.2s ease; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; position: absolute; top: 0; left: 0; }
.status-dot { position: absolute; right: -2px; bottom: -2px; width: 9px; height: 9px; border-radius: 50%; background: #22c55e; border: 2px solid #fff; }
.user-meta { display: flex; flex-direction: column; line-height: 1.1; }
.user-meta .primary { font-weight: 600; color: #111827; }
.user-meta .secondary { font-size: 0.8rem; color: #6b7280; }

.students-table tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.students-table tbody tr:hover .user-avatar {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.2);
}

/* Student Info */
.student-name {
    font-weight: 600;
    color: #1f2937;
}

.student-email {
    color: #6b7280;
    font-size: 0.9rem;
}

.student-cohort {
    color: #6b7280;
    font-size: 0.9rem;
}

.cohort-badge {
    background: #f3e8ff;
    color: #7c3aed;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    display: inline-block;
    border: 1px solid #e9d5ff;
}

.cohort-badge.no-cohort {
    background: #f3f4f6;
    color: #6b7280;
}

.student-grade {
    background: #e0f2fe;
    color: #0369a1;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Status Badge */
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-suspended {
    background: #fef2f2;
    color: #dc2626;
}

/* Last Access */
.last-access {
    color: #6b7280;
    font-size: 0.9rem;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10px 0;
    margin-top: 10px;
    background: transparent;
    border: none;
    box-shadow: none;
    gap: 10px;
}

.pagination-info {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
    text-align: center;
    order: 2;
}

.pagination-info span {
    color: #1f2937;
    font-weight: 600;
}

.pagination-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: center;
    order: 1;
}

.pagination-btn {
    padding: 8px 16px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.pagination-btn:hover:not(:disabled) {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-numbers {
    display: flex;
    gap: 5px;
}

.pagination-number {
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    transition: all 0.3s ease;
    min-width: 40px;
    text-align: center;
}

.pagination-number:hover {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-number.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
}

.btn-view {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.btn-view:hover {
    background: #bfdbfe;
    color: #1e3a8a;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(30, 64, 175, 0.2);
    border-color: #93c5fd;
}

.btn-edit {
    background: #ccfbf1;
    color: #0d9488;
    border: 1px solid #99f6e4;
}

.btn-edit:hover {
    background: #99f6e4;
    color: #0f766e;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(13, 148, 136, 0.2);
    border-color: #5eead4;
}

.btn-suspend {
    background: #fef3c7;
    color: #d97706;
}

.btn-suspend:hover {
    background: #fde68a;
    color: #b45309;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(217, 119, 6, 0.2);
}

.btn-activate {
    background: #d1fae5;
    color: #059669;
}

.btn-activate:hover {
    background: #a7f3d0;
    color: #047857;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(5, 150, 105, 0.2);
}

.btn-delete {
    background: #fee2e2;
    color: #dc2626;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-delete:hover {
    background: #fecaca;
    color: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 1.5rem;
    color: #4a5568;
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 1rem;
    margin-bottom: 30px;
}

/* Tablet Responsive (768px - 1024px) */
@media (max-width: 1024px) and (min-width: 769px) {
    .school-manager-main-content {
        left: 240px;
        width: calc(100vw - 240px);
    }
    
    .main-content {
        padding: 0 20px 30px 20px;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
}

/* Mobile Responsive (max-width: 768px) */
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
        top: 55px;
        height: calc(100vh - 55px);
        padding-top: 80px;
    }
    
    .main-content {
        padding: 0 15px 30px 15px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.5rem 1.5rem;
        margin-top: 0;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        font-size: 0.875rem;
    }
    
    .back-button {
        width: 100%;
        justify-content: center;
    }
    
    .stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .stat-card {
        padding: 1rem;
        min-height: 100px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
        margin-bottom: 10px;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
    }
    
    .students-table {
        font-size: 0.8rem;
    }
    
    .students-table th,
    .students-table td {
        padding: 15px 10px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 5px;
    }
    
    .action-btn {
        padding: 6px 10px;
        font-size: 0.7rem;
    }
}

/* Small Mobile Responsive (max-width: 480px) */
@media (max-width: 480px) {
    .school-manager-main-content {
        padding-top: 70px;
    }
    
    .page-header {
        padding: 1.5rem 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        font-size: 0.875rem;
    }
    
    .stats-section {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .stat-card {
        min-height: 90px;
    }
    
    .stat-number {
        font-size: 1.6rem;
    }
    
    .search-section {
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .search-title {
        font-size: 1rem;
    }
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
";
echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Student Management</h1>";
echo "<p class='page-subtitle'>Manage and monitor all students in " . htmlspecialchars($company_info->name) . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";

// Summary Cards
echo "<div class='stats-section'>";
echo "<div class='stat-card students-card'>";
echo "<div class='stat-content'>";
echo "<h3 class='stat-number'>" . $total_students . "</h3>";
echo "<p class='stat-label'>Total Students</p>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>All Students</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-users'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/user/index.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card active-students-card'>";
echo "<div class='stat-content'>";
echo "<h3 class='stat-number'>" . $active_students . "</h3>";
echo "<p class='stat-label'>Active Students</p>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>Active</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-check'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/user/index.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card enrolled-students-card'>";
echo "<div class='stat-content'>";
echo "<h3 class='stat-number'>" . $enrolled_students . "</h3>";
echo "<p class='stat-label'>Enrolled Students</p>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>In Courses</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-graduation-cap'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/enrol/users.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";

echo "<div class='stat-card suspended-students-card'>";
echo "<div class='stat-content'>";
echo "<h3 class='stat-number'>" . $suspended_students . "</h3>";
echo "<p class='stat-label'>Suspended Students</p>";
echo "<div class='stat-trend'>";
echo "<span class='trend-arrow'>↑</span>";
echo "<span class='trend-text'>Inactive</span>";
echo "</div>";
echo "</div>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-times'></i>";
echo "</div>";
echo "<div class='stat-action'>";
echo "<a href='{$CFG->wwwroot}/user/index.php' class='stat-link'>";
echo "<i class='fa fa-arrow-right'></i>";
echo "</a>";
echo "</div>";
echo "</div>";

echo "</div>"; // End stats-section

// Search Section
echo "<div class='search-section'>";
echo "<div class='search-header'>";
echo "<h2 class='search-title'>Search Students</h2>";
echo "<div class='student-actions'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/student_bulk_upload.php' class='bulk-upload-student-btn'>";
echo "<i class='fa fa-upload'></i> Bulk Upload Student";
echo "</a>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/add_student.php' class='add-student-btn'>";
echo "<i class='fa fa-plus'></i> Add New Student";
echo "</a>";
echo "</div>";
echo "</div>";
echo "<div class='search-container'>";
echo "<form method='GET' class='search-form' id='search-form'>";
echo "<div class='search-input-group'>";
echo "<select name='search_field' id='search-field-select' class='search-field-select'>";
echo "<option value='all'" . ($search_field === 'all' ? ' selected' : '') . ">Search All Fields</option>";
echo "<option value='name'" . ($search_field === 'name' ? ' selected' : '') . ">Name</option>";
echo "<option value='email'" . ($search_field === 'email' ? ' selected' : '') . ">Email</option>";
echo "<option value='cohort'" . ($search_field === 'cohort' ? ' selected' : '') . ">Cohort/Grade</option>";
echo "<option value='role'" . ($search_field === 'role' ? ' selected' : '') . ">Role</option>";
echo "<option value='username'" . ($search_field === 'username' ? ' selected' : '') . ">Username</option>";
echo "</select>";
echo "<select name='cohort_filter' id='cohort-filter-select' class='search-field-select cohort-filter-select'>";
echo "<option value='all'" . ($cohort_filter === 'all' ? ' selected' : '') . ">All Cohorts</option>";
if (!empty($cohorts_list)) {
    foreach ($cohorts_list as $cohort) {
        $selected = ($cohort_filter === (string)$cohort['id']) ? ' selected' : '';
        echo "<option value='" . $cohort['id'] . "'" . $selected . ">" . htmlspecialchars($cohort['name']) . " (" . $cohort['student_count'] . ")</option>";
    }
}
echo "</select>";
echo "<input type='text' name='search' id='search-input' placeholder='Enter search term...' value='" . htmlspecialchars($search_query) . "' class='search-input'>";
echo "</div>";
echo "</form>";
if (!empty($search_query) || (!empty($cohort_filter) && $cohort_filter !== 'all')) {
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' class='clear-search-btn'>";
    echo "<i class='fa fa-times'></i> Clear";
    echo "</a>";
}
echo "</div>";

if (!empty($search_query) || (!empty($cohort_filter) && $cohort_filter !== 'all')) {
    echo "<div class='search-results-info'>";
    $result_text = "Found " . count($students) . " student(s)";
    if (!empty($search_query)) {
        $result_text .= " matching '" . htmlspecialchars($search_query) . "'";
    }
    if (!empty($cohort_filter) && $cohort_filter !== 'all') {
        $selected_cohort_name = 'All Cohorts';
        foreach ($cohorts_list as $cohort) {
            if ($cohort['id'] == $cohort_filter) {
                $selected_cohort_name = $cohort['name'];
                break;
            }
        }
        if (!empty($search_query)) {
            $result_text .= " in cohort '" . htmlspecialchars($selected_cohort_name) . "'";
        } else {
            $result_text .= " in cohort '" . htmlspecialchars($selected_cohort_name) . "'";
        }
    }
    echo "<div class='search-results-count'>" . $result_text . "</div>";
    echo "</div>";
}
echo "</div>";

// Students List Section
echo "<div class='students-container'>";
echo "<h2 style='color: #1a202c; font-size: 1.5rem; font-weight: 600; margin: 0 0 20px 0;'>Students List</h2>";

if (empty($students)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-users'></i>";
    echo "<h3>No Students Found</h3>";
    echo "<p>There are no students in this school yet.</p>";
    echo "<a href='{$CFG->wwwroot}/user/edit.php' class='add-student-btn'>";
    echo "<i class='fa fa-plus'></i> Add First Student";
    echo "</a>";
    echo "</div>";
} else {
    // Bulk action form
    echo "<form method='POST' id='bulk-delete-form'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    
    echo "<table class='students-table' id='students-table'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th style='width: 40px;'><input type='checkbox' id='select-all' title='Select All'></th>";
    echo "<th>Student</th>";
    echo "<th>Email</th>";
    echo "<th>Grade Level</th>";
    echo "<th>Role</th>";
    echo "<th>Status</th>";
    echo "<th>Last Access</th>";
    echo "<th>Actions</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($students as $student) {
        $status_class = $student->suspended ? 'status-suspended' : 'status-active';
        $status_text = $student->suspended ? 'Suspended' : 'Active';
        $last_access = $student->lastaccess ? date('M j, Y', $student->lastaccess) : 'Never';
        
        $cohort_ids_attr = !empty($student->cohort_ids) ? htmlspecialchars($student->cohort_ids) : '';
        $cohort_names_attr = !empty($student->cohort_names) ? htmlspecialchars(strtolower($student->cohort_names)) : '';
        echo "<tr data-name='" . htmlspecialchars(strtolower($student->firstname . ' ' . $student->lastname)) . "' data-email='" . htmlspecialchars(strtolower($student->email)) . "' data-phone='" . htmlspecialchars(strtolower($student->phone1 ?: '')) . "' data-grade='" . htmlspecialchars(strtolower($student->grade_level ?: '')) . "' data-username='" . htmlspecialchars(strtolower($student->username)) . "' data-suspended='" . ($student->suspended ? '1' : '0') . "' data-cohort-ids='" . $cohort_ids_attr . "' data-cohort-names='" . $cohort_names_attr . "'>";
        
        // Checkbox column
        echo "<td style='text-align: center;'>";
        echo "<input type='checkbox' name='selected_students[]' value='{$student->id}' class='student-checkbox' data-suspended='" . ($student->suspended ? '1' : '0') . "'>";
        echo "</td>";
        
        // Get student profile image using Moodle's user_picture class
        $student_user = $DB->get_record('user', ['id' => $student->id]);
        
        // Check if student has a profile picture
        $has_profile_picture = false;
        $profile_image_url = '';
        
        if ($student_user && $student_user->picture > 0) {
            // Verify the file actually exists in file storage
            $user_context = context_user::instance($student_user->id);
            $fs = get_file_storage();
            
            // Check if file exists in 'icon' area (where Moodle stores profile pics)
            $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
            
            if (!empty($files)) {
                // File exists, safe to generate URL
                try {
                    $user_picture = new user_picture($student_user);
                    $user_picture->size = 1; // f1 = small thumbnail
                    $profile_image_url = $user_picture->get_url($PAGE)->out(false);
                    
                    // Set flag to true if we have a valid URL
                    if (!empty($profile_image_url)) {
                        $has_profile_picture = true;
                    }
                } catch (Exception $e) {
                    error_log("Error generating student profile image URL for user ID " . $student_user->id . ": " . $e->getMessage());
                    $has_profile_picture = false;
                }
            } else {
                // Picture field is set but file doesn't exist - reset the field
                $student_user->picture = 0;
                $student_user->timemodified = time();
                $DB->update_record('user', $student_user);
                $has_profile_picture = false;
            }
        }
        
        // Fallback initials if no profile image
        $initials = strtoupper(substr($student->firstname, 0, 1) . substr($student->lastname, 0, 1));
        
        echo "<td>";
        echo "<div class='user-cell'>";
        echo "  <div class='user-avatar'>";
        
        if ($has_profile_picture && !empty($profile_image_url)) {
            // Show actual profile picture with initials as fallback
            echo "    <img src='" . htmlspecialchars($profile_image_url) . "' ";
            echo "         alt='" . htmlspecialchars($student->firstname . ' ' . $student->lastname) . "' ";
            echo "         onerror='console.log(\"Failed to load image:\", this.src); this.style.display=\"none\"; this.nextElementSibling.style.display=\"flex\";' ";
            echo "         style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block;'>";
            echo "    <span style='display: none; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
        } else {
            // Show initials placeholder
            echo "    <span style='display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
        }
        
        echo (!$student->suspended ? "<span class='status-dot'></span>" : "") . "</div>";
        echo "  <div class='user-meta'>";
        echo "    <span class='primary'>" . htmlspecialchars($student->firstname . ' ' . $student->lastname) . "</span>";
        echo "    <span class='secondary'>@" . htmlspecialchars($student->username) . "</span>";
        echo "  </div>";
        echo "</div>";
        echo "</td>";
        echo "<td class='student-email'>" . htmlspecialchars($student->email) . "</td>";
        echo "<td class='student-cohort'>";
        if (!empty($student->grade_level)) {
            echo "<span class='cohort-badge'>" . htmlspecialchars($student->grade_level) . "</span>";
        } else {
            echo "<span class='cohort-badge no-cohort'>Not assigned</span>";
        }
        echo "</td>";
        echo "<td>";
        $role_label = 'student';
        if (!empty($student->roles)) {
            // Show the first role in a readable way
            $roleparts = explode(',', strtolower($student->roles));
            $role_label = str_replace('_', ' ', trim($roleparts[0]));
        }
        echo "<span class='student-grade'>" . htmlspecialchars(ucwords($role_label)) . "</span>";
        echo "</td>";
        echo "<td><span class='status-badge " . $status_class . "'>" . $status_text . "</span></td>";
        echo "<td class='last-access'>" . $last_access . "</td>";
        echo "<td>";
        echo "<div class='action-buttons'>";
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_profile.php?id=" . $student->id . "' class='action-btn btn-view' title='View Profile'>";
        echo "<i class='fa fa-eye'></i> View";
        echo "</a>";
        echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/edit_student.php?id=" . $student->id . "' class='action-btn btn-edit' title='Edit Student'>";
        echo "<i class='fa fa-edit'></i> Edit";
        echo "</a>";
        
        // Delete button (Permanent deletion)
        echo "<a href='?action=permanent_delete&student_id=" . $student->id . "&sesskey=" . sesskey() . "' class='action-btn btn-delete' title='Delete Student Permanently' onclick='return confirm(\"⚠️ WARNING: This will permanently delete the student and all their data from the database.\\n\\nThis action cannot be undone!\\n\\nAre you absolutely sure you want to delete " . addslashes(fullname($student)) . "?\")'>";
        echo "<i class='fa fa-trash'></i> Delete";
        echo "</a>";
        
        // Conditional Suspend/Activate button (like teacher management)
        if (!$student->suspended) {
            // Student is ACTIVE → Show SUSPEND button
            echo "<a href='?action=suspend&student_id=" . $student->id . "&sesskey=" . sesskey() . "' class='action-btn btn-suspend' title='Suspend Student' onclick='return confirm(\"Are you sure you want to suspend " . addslashes(fullname($student)) . "?\\n\\nThey will not be able to log in.\")'>";
            echo "<i class='fa fa-pause'></i> Suspend";
            echo "</a>";
        } else {
            // Student is SUSPENDED → Show ACTIVATE button
            echo "<a href='?action=activate&student_id=" . $student->id . "&sesskey=" . sesskey() . "' class='action-btn btn-activate' title='Activate Student' onclick='return confirm(\"Are you sure you want to activate " . addslashes(fullname($student)) . "?\\n\\nThey will be able to log in.\")'>";
            echo "<i class='fa fa-check-circle'></i> Activate";
            echo "</a>";
        }
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    // Bulk action buttons
    echo "<div class='bulk-actions' id='bulk-actions' style='display: none; margin: 20px 0; padding: 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #f59e0b;'>";
    echo "<span id='selected-count' style='font-weight: 600; color: #1f2937; margin-right: 20px;'>0 students selected</span>";
    echo "<div style='display: flex; gap: 12px;'>";
    echo "<button type='button' id='btn-bulk-suspend-activate' onclick='confirmBulkSuspendOrActivate()' class='btn-bulk-suspend' style='background: #f59e0b; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;'>";
    echo "<i class='fa fa-pause' id='suspend-activate-icon'></i> <span id='suspend-activate-text'>Suspend Selected</span>";
    echo "</button>";
    echo "<button type='button' onclick='confirmBulkDelete()' class='btn-bulk-delete' style='background: #ef4444; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;'>";
    echo "<i class='fa fa-trash'></i> Delete Selected";
    echo "</button>";
    echo "</div>";
    echo "</div>";
    
    echo "</form>";
}
    
echo "</div>"; // End students-container

// Pagination - Always show pagination container (JavaScript will handle visibility) - OUTSIDE the container
if (!empty($students)) {
    $total_students = count($students);
    $per_page = 10;
    $total_pages = ceil($total_students / $per_page);
    
    echo "<div class='pagination-container' id='pagination-container' style='display: " . ($total_pages > 1 ? 'flex' : 'none') . ";'>";
        echo "<div class='pagination-info'>Showing <span id='current_start'>1</span>-<span id='current_end'>" . min($per_page, $total_students) . "</span> of <span id='total_count'>$total_students</span> students</div>";
        echo "<div class='pagination-controls' id='pagination_controls'>";
        echo "<button class='pagination-btn' id='prev_page' onclick='changePage(-1)' disabled>";
        echo "<i class='fa fa-chevron-left'></i> Previous";
        echo "</button>";
        echo "<div class='pagination-numbers' id='pagination_numbers'></div>";
        echo "<button class='pagination-btn' id='next_page' onclick='changePage(1)'>";
        echo "Next <i class='fa fa-chevron-right'></i>";
        echo "</button>";
        echo "</div>";
        echo "</div>";
}

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// Add pagination JavaScript
echo "<script>
// Pagination variables - GLOBAL scope for access by search function
var currentPage = 1;
var perPage = 10;
var totalPages = 1;
var allRows = [];

// Make initializePagination globally accessible
function initializePagination() {
    const studentsTable = document.getElementById('students-table');
    if (!studentsTable) {
        console.log('Students table not found');
        return;
    }
    
    const tbody = studentsTable.querySelector('tbody');
    if (!tbody) {
        console.log('Table body not found');
        return;
    }
    
    allRows = Array.from(tbody.querySelectorAll('tr'));
    totalPages = Math.ceil(allRows.length / perPage);
    
    console.log('Pagination initialized:', {
        totalRows: allRows.length,
        perPage: perPage,
        totalPages: totalPages
    });
    
    // Restore pagination button visibility
    const prevBtn = document.getElementById('prev_page');
    const nextBtn = document.getElementById('next_page');
    const paginationNumbers = document.getElementById('pagination_numbers');
    
    if (totalPages > 1) {
        // Hide rows beyond first page
        allRows.forEach((row, index) => {
            if (index >= perPage) {
                row.style.display = 'none';
            }
        });
        
        // Show pagination controls
        if (prevBtn) prevBtn.style.display = 'flex';
        if (nextBtn) nextBtn.style.display = 'flex';
        if (paginationNumbers) paginationNumbers.style.display = 'flex';
        
        // Update pagination display
        updatePaginationDisplay();
        renderPaginationNumbers();
    } else if (totalPages === 1) {
        // Update the end count for single page
        const endEl = document.getElementById('current_end');
        if (endEl) {
            endEl.textContent = allRows.length;
        }
        
        // Hide pagination controls for single page (but keep container visible)
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
        if (paginationNumbers) paginationNumbers.style.display = 'none';
        
        updatePaginationDisplay();
    }
}

function showPage(page) {
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    
    // Show/hide rows based on current page
    allRows.forEach((row, index) => {
        const startIndex = (page - 1) * perPage;
        const endIndex = startIndex + perPage;
        
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update pagination display
    updatePaginationDisplay();
    renderPaginationNumbers();
    
    // Scroll to top of table smoothly
    const studentsContainer = document.querySelector('.students-container');
    if (studentsContainer) {
        studentsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updatePaginationDisplay() {
    const startNum = ((currentPage - 1) * perPage) + 1;
    const endNum = Math.min(currentPage * perPage, allRows.length);
    const totalCount = allRows.length;
    
    const startEl = document.getElementById('current_start');
    const endEl = document.getElementById('current_end');
    const totalEl = document.getElementById('total_count');
    
    if (startEl) startEl.textContent = startNum;
    if (endEl) endEl.textContent = endNum;
    if (totalEl) totalEl.textContent = totalCount;
    
    // Update button states
    const prevBtn = document.getElementById('prev_page');
    const nextBtn = document.getElementById('next_page');
    
    if (prevBtn) prevBtn.disabled = (currentPage === 1);
    if (nextBtn) nextBtn.disabled = (currentPage === totalPages);
}

function renderPaginationNumbers() {
    const container = document.getElementById('pagination_numbers');
    if (!container) return;
    
    container.innerHTML = '';
    
    // Show max 5 page numbers at a time
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    
    // Adjust if we're near the end
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }
    
    // Add first page if not visible
    if (startPage > 1) {
        addPageButton(1);
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
    }
    
    // Add page numbers
    for (let i = startPage; i <= endPage; i++) {
        addPageButton(i);
    }
    
    // Add last page if not visible
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            ellipsis.style.color = '#6b7280';
            container.appendChild(ellipsis);
        }
        addPageButton(totalPages);
    }
}

function addPageButton(pageNum) {
    const container = document.getElementById('pagination_numbers');
    const pageBtn = document.createElement('button');
    pageBtn.className = 'pagination-number' + (pageNum === currentPage ? ' active' : '');
    pageBtn.textContent = pageNum;
    pageBtn.onclick = () => showPage(pageNum);
    container.appendChild(pageBtn);
}

function changePage(direction) {
    const newPage = currentPage + direction;
    if (newPage >= 1 && newPage <= totalPages) {
        showPage(newPage);
    }
}

// Initialize pagination on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing pagination on page load...');
    initializePagination();
});
</script>";

// Bulk actions JavaScript
echo "<script>
// ========================================
// BULK ACTIONS FUNCTIONALITY
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select-all');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const bulkActionsDiv = document.getElementById('bulk-actions');
    const selectedCountSpan = document.getElementById('selected-count');
    
    if (selectAllCheckbox && bulkActionsDiv) {
        selectAllCheckbox.addEventListener('change', function() {
            studentCheckboxes.forEach(function(checkbox) {
                // Only check visible rows
                const row = checkbox.closest('tr');
                if (row && row.style.display !== 'none') {
                    checkbox.checked = selectAllCheckbox.checked;
                }
            });
            updateBulkActions();
        });
    }
    
    // Update bulk actions when individual checkboxes change
    studentCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateBulkActions();
            updateSelectAllState();
        });
    });
    
    // Update Select All checkbox state
    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        
        const visibleCheckboxes = Array.from(studentCheckboxes).filter(function(cb) {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const totalVisible = visibleCheckboxes.length;
        const checkedVisible = visibleCheckboxes.filter(function(cb) { return cb.checked; }).length;
        
        if (checkedVisible === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedVisible === totalVisible) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    // Show/hide bulk actions and update count
    function updateBulkActions() {
        if (!bulkActionsDiv || !selectedCountSpan) return;
        
        const checkedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
        const count = checkedCheckboxes.length;
        
        if (count > 0) {
            bulkActionsDiv.style.display = 'block';
            selectedCountSpan.textContent = count + ' student' + (count > 1 ? 's' : '') + ' selected';
            
            // Check if all selected students are suspended
            updateSuspendActivateButton(checkedCheckboxes);
        } else {
            bulkActionsDiv.style.display = 'none';
        }
    }
    
    // Update the suspend/activate button based on selection
    function updateSuspendActivateButton(checkedCheckboxes) {
        const button = document.getElementById('btn-bulk-suspend-activate');
        const icon = document.getElementById('suspend-activate-icon');
        const text = document.getElementById('suspend-activate-text');
        
        if (!button || !icon || !text) return;
        
        let allSuspended = true;
        let anySuspended = false;
        
        checkedCheckboxes.forEach(function(checkbox) {
            const isSuspended = checkbox.getAttribute('data-suspended') === '1';
            if (!isSuspended) {
                allSuspended = false;
            }
            if (isSuspended) {
                anySuspended = true;
            }
        });
        
        // If ALL selected students are suspended, show Activate Selected button
        if (allSuspended) {
            text.textContent = 'Activate Selected';
            icon.className = 'fa fa-check';
            button.style.background = '#10b981'; // Green color
            button.setAttribute('data-action', 'activate');
        } else {
            // Otherwise, show Suspend Selected button
            text.textContent = 'Suspend Selected';
            icon.className = 'fa fa-pause';
            button.style.background = '#f59e0b'; // Orange color
            button.setAttribute('data-action', 'suspend');
        }
    }
    
    console.log('Bulk actions (Suspend & Delete) functionality initialized');
});

// Confirm and submit bulk suspend or activate (global function)
function confirmBulkSuspendOrActivate() {
    const checkedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
    const count = checkedCheckboxes.length;
    
    if (count === 0) {
        alert('Please select at least one student.');
        return false;
    }
    
    // Check if the action is to activate or suspend
    const button = document.getElementById('btn-bulk-suspend-activate');
    const action = button ? button.getAttribute('data-action') : 'suspend';
    
    // Get names of selected students
    const studentNames = [];
    checkedCheckboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        if (row) {
            const nameCell = row.querySelector('.primary');
            if (nameCell) {
                studentNames.push(nameCell.textContent.trim());
            }
        }
    });
    
    let confirmMessage = '';
    
    if (action === 'activate') {
        confirmMessage = '✅ ACTIVATE: You are about to activate ' + count + ' student(s):\\n\\n';
        
        // Show first 5 names
        const displayNames = studentNames.slice(0, 5);
        confirmMessage += displayNames.join('\\n');
        
        if (studentNames.length > 5) {
            confirmMessage += '\\n... and ' + (studentNames.length - 5) + ' more';
        }
        
        confirmMessage += '\\n\\nThese students will be ACTIVATED and able to log in.\\n';
        confirmMessage += 'Are you sure you want to continue?';
    } else {
        confirmMessage = '⏸️ SUSPEND: You are about to suspend ' + count + ' student(s):\\n\\n';
        
        // Show first 5 names
        const displayNames = studentNames.slice(0, 5);
        confirmMessage += displayNames.join('\\n');
        
        if (studentNames.length > 5) {
            confirmMessage += '\\n... and ' + (studentNames.length - 5) + ' more';
        }
        
        confirmMessage += '\\n\\nThese students will be SUSPENDED and unable to log in.\\n';
        confirmMessage += 'You can activate them later if needed.\\n\\n';
        confirmMessage += 'Are you sure you want to continue?';
    }
    
    if (confirm(confirmMessage)) {
        // Submit the form
        const form = document.getElementById('bulk-delete-form');
        const input = document.createElement('input');
        input.type = 'hidden';
        
        if (action === 'activate') {
            input.name = 'bulk_activate';
        } else {
            input.name = 'bulk_suspend';
        }
        
        input.value = '1';
        form.appendChild(input);
        form.submit();
        return true;
    }
    
    return false;
}

// Confirm and submit bulk delete (permanent deletion) - global function
function confirmBulkDelete() {
    const checkedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
    const count = checkedCheckboxes.length;
    
    if (count === 0) {
        alert('Please select at least one student to delete.');
        return false;
    }
    
    // Get names of selected students
    const studentNames = [];
    checkedCheckboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        if (row) {
            const nameCell = row.querySelector('.primary');
            if (nameCell) {
                studentNames.push(nameCell.textContent.trim());
            }
        }
    });
    
    let confirmMessage = '⚠️ PERMANENT DELETE: You are about to PERMANENTLY DELETE ' + count + ' student(s):\\n\\n';
    
    // Show first 5 names
    const displayNames = studentNames.slice(0, 5);
    confirmMessage += displayNames.join('\\n');
    
    if (studentNames.length > 5) {
        confirmMessage += '\\n... and ' + (studentNames.length - 5) + ' more';
    }
    
    confirmMessage += '\\n\\n⚠️ WARNING: This action CANNOT be undone!\\n';
    confirmMessage += 'The students will be PERMANENTLY DELETED from the database.\\n';
    confirmMessage += 'All their data, courses, and records will be removed.\\n\\n';
    confirmMessage += 'Are you absolutely sure you want to continue?';
    
    if (confirm(confirmMessage)) {
        // Double confirmation for permanent delete
        if (confirm('⚠️ FINAL WARNING: This will PERMANENTLY DELETE ' + count + ' student(s) from the database.\\n\\nThis cannot be undone!\\n\\nClick OK to proceed with permanent deletion.')) {
            // Submit the form
            const form = document.getElementById('bulk-delete-form');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_delete';
            input.value = '1';
            form.appendChild(input);
            form.submit();
            return true;
        }
    }
    
    return false;
}
</script>";

echo $OUTPUT->footer();
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const searchFieldSelect = document.getElementById('search-field-select');
    const cohortFilterSelect = document.getElementById('cohort-filter-select');
    const studentsTable = document.getElementById('students-table');
    const searchResultsInfo = document.querySelector('.search-results-info');
    
    if (!searchInput || !studentsTable) {
        console.log('Search elements not found');
        return;
    }
    
    // Function to perform live search
    function performLiveSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const searchField = searchFieldSelect.value;
        const cohortFilter = cohortFilterSelect ? cohortFilterSelect.value : 'all';
        const tableRows = studentsTable.querySelectorAll('tbody tr');
        let visibleCount = 0;
        const visibleRows = [];
        
        // Filter rows based on search and cohort filter
            tableRows.forEach(function(row) {
            let match = true;
            
            // First, check cohort filter
            if (cohortFilter !== 'all' && cohortFilter !== '') {
                const cohortIds = row.getAttribute('data-cohort-ids') || '';
                const cohortIdsArray = cohortIds ? cohortIds.split(',') : [];
                if (!cohortIdsArray.includes(cohortFilter)) {
                    match = false;
                }
            }
            
            // Then, check search term if provided
            if (match && searchTerm !== '') {
                match = false;
                // Get data attributes from the row
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const cohort = row.getAttribute('data-grade') || '';
                const cohortNames = row.getAttribute('data-cohort-names') || '';
                const username = row.getAttribute('data-username') || '';
                
                // Check match based on selected field
                switch (searchField) {
                    case 'name':
                        match = name.includes(searchTerm);
                        break;
                    case 'email':
                        match = email.includes(searchTerm);
                        break;
                    case 'cohort':
                        match = cohort.includes(searchTerm) || cohortNames.includes(searchTerm);
                        break;
                    case 'username':
                        match = username.includes(searchTerm);
                        break;
                    case 'all':
                    default:
                        match = name.includes(searchTerm) || 
                               email.includes(searchTerm) || 
                               cohort.includes(searchTerm) || 
                               cohortNames.includes(searchTerm) ||
                               username.includes(searchTerm);
                        break;
                }
                }
                
            // Store match result
                if (match) {
                visibleRows.push(row);
                    visibleCount++;
                }
            });
        
        // Sort visible rows by relevance if there's a search term
        if (searchTerm !== '') {
            sortRowsByRelevance(searchTerm, searchField, visibleRows);
        }
            
            // Update search results info
            updateSearchResultsInfo(searchTerm, visibleCount, tableRows.length);
            
        // Handle pagination based on whether filters are active
        if (searchTerm !== '' || (cohortFilter !== 'all' && cohortFilter !== '')) {
            // Filters are active - reinitialize pagination with filtered rows
            reinitializePaginationWithFilteredRows(visibleRows);
        } else {
            // No filters - restore normal pagination with all rows
            tableRows.forEach(function(row) {
                row.style.display = '';
            });
            
            if (typeof initializePagination === 'function') {
                initializePagination();
            }
            
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                paginationContainer.style.display = 'flex';
            }
        }
    }
    
    // Function to reinitialize pagination with filtered rows
    function reinitializePaginationWithFilteredRows(visibleRows) {
        if (!visibleRows || visibleRows.length === 0) {
            // No visible rows - hide all and hide pagination
            const tableRows = studentsTable.querySelectorAll('tbody tr');
            tableRows.forEach(function(row) {
                row.style.display = 'none';
            });
            
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                paginationContainer.style.display = 'none';
            }
            return;
        }
        
        // Update global pagination variables
        if (typeof window !== 'undefined') {
            window.allRows = visibleRows;
            window.perPage = window.perPage || 10;
            window.totalPages = Math.ceil(visibleRows.length / window.perPage);
            window.currentPage = 1;
        }
        
        // Hide all rows first
        const tableRows = studentsTable.querySelectorAll('tbody tr');
            tableRows.forEach(function(row) {
            row.style.display = 'none';
        });
        
        // Show only first page of visible rows
        const perPage = window.perPage || 10;
        visibleRows.forEach(function(row, index) {
            if (index < perPage) {
                row.style.display = '';
            }
            });
            
        // Update pagination display
        if (typeof updatePaginationDisplay === 'function') {
            updatePaginationDisplay();
        }
        if (typeof renderPaginationNumbers === 'function') {
            renderPaginationNumbers();
            }
            
        // Always show pagination controls when filters are active (to show count)
            const paginationContainer = document.querySelector('.pagination-container');
            if (paginationContainer) {
                paginationContainer.style.display = 'flex';
            
            // Hide pagination numbers if only one page
            const paginationNumbers = document.getElementById('pagination_numbers');
            const prevBtn = document.getElementById('prev_page');
            const nextBtn = document.getElementById('next_page');
            
            if (visibleRows.length <= perPage) {
                // Only one page - hide navigation buttons but keep info
                if (prevBtn) prevBtn.style.display = 'none';
                if (nextBtn) nextBtn.style.display = 'none';
                if (paginationNumbers) paginationNumbers.style.display = 'none';
            } else {
                // Multiple pages - show navigation
                if (prevBtn) prevBtn.style.display = 'flex';
                if (nextBtn) nextBtn.style.display = 'flex';
                if (paginationNumbers) paginationNumbers.style.display = 'flex';
            }
        }
    }
    
    // Function to sort rows by relevance
    function sortRowsByRelevance(searchTerm, searchField, visibleRows) {
        if (!visibleRows || visibleRows.length === 0) {
            return;
        }
        
        const tbody = studentsTable.querySelector('tbody');
        if (!tbody) return;
        
        // Sort the visible rows array
        visibleRows.sort(function(a, b) {
            const aScore = calculateRelevanceScore(a, searchTerm, searchField);
            const bScore = calculateRelevanceScore(b, searchTerm, searchField);
            
            if (aScore === bScore) {
                // If same relevance, sort by name
                const aName = a.getAttribute('data-name') || '';
                const bName = b.getAttribute('data-name') || '';
                return aName.localeCompare(bName);
            }
            
            return bScore - aScore; // Higher score first
        });
        
        // Re-append sorted rows to maintain order
        visibleRows.forEach(function(row) {
            tbody.appendChild(row);
        });
    }
    
    // Function to calculate relevance score
    function calculateRelevanceScore(row, searchTerm, searchField) {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const phone = row.getAttribute('data-phone') || '';
        const grade = row.getAttribute('data-grade') || '';
        const username = row.getAttribute('data-username') || '';
        
        let score = 0;
        
        switch (searchField) {
            case 'name':
                if (name === searchTerm) score = 100;
                else if (name.startsWith(searchTerm)) score = 80;
                else if (name.includes(searchTerm)) score = 60;
                break;
            case 'email':
                if (email === searchTerm) score = 100;
                else if (email.startsWith(searchTerm)) score = 80;
                else if (email.includes(searchTerm)) score = 60;
                break;
            case 'phone':
                if (phone === searchTerm) score = 100;
                else if (phone.includes(searchTerm)) score = 60;
                break;
            case 'grade':
                if (grade === searchTerm) score = 100;
                else if (grade.includes(searchTerm)) score = 60;
                break;
            case 'username':
                if (username === searchTerm) score = 100;
                else if (username.startsWith(searchTerm)) score = 80;
                else if (username.includes(searchTerm)) score = 60;
                break;
            case 'all':
            default:
                // Check all fields and give highest score
                if (name === searchTerm) score = 100;
                else if (name.startsWith(searchTerm)) score = 85;
                else if (email === searchTerm || username === searchTerm) score = 90;
                else if (email.startsWith(searchTerm) || username.startsWith(searchTerm)) score = 75;
                else if (name.includes(searchTerm)) score = 70;
                else if (email.includes(searchTerm) || username.includes(searchTerm)) score = 60;
                else if (phone.includes(searchTerm) || grade.includes(searchTerm)) score = 50;
                break;
        }
        
        return score;
    }
    
    // Function to update search results info
    function updateSearchResultsInfo(searchTerm, visibleCount, totalCount) {
        if (searchResultsInfo) {
            const currentCohortFilter = cohortFilterSelect ? cohortFilterSelect.value : 'all';
            const hasSearch = searchTerm !== '';
            const hasCohortFilter = currentCohortFilter !== 'all' && currentCohortFilter !== '';
            
            if (!hasSearch && !hasCohortFilter) {
                searchResultsInfo.style.display = 'none';
            } else {
                searchResultsInfo.style.display = 'block';
                const countElement = searchResultsInfo.querySelector('.search-results-count');
                if (countElement) {
                    let resultText = `Found ${visibleCount} student(s)`;
                    if (hasSearch) {
                        resultText += ` matching '${searchTerm}'`;
                    }
                    if (hasCohortFilter) {
                        const selectedOption = cohortFilterSelect ? cohortFilterSelect.options[cohortFilterSelect.selectedIndex] : null;
                        const cohortName = selectedOption ? selectedOption.text.split(' (')[0] : 'Selected Cohort';
                        if (hasSearch) {
                            resultText += ` in cohort '${cohortName}'`;
                        } else {
                            resultText += ` in cohort '${cohortName}'`;
                        }
                    }
                    countElement.textContent = resultText;
                }
            }
        }
    }
    
    // Add event listeners
    searchInput.addEventListener('input', function() {
        // Add a small delay to prevent too many searches while typing
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(performLiveSearch, 300);
    });
    
    searchFieldSelect.addEventListener('change', function() {
        // Re-search when field selection changes, but only if there's a search term
        if (searchInput.value.trim() !== '') {
            performLiveSearch();
        }
    });
    
    // Add event listener for cohort filter
    if (cohortFilterSelect) {
        cohortFilterSelect.addEventListener('change', function() {
            performLiveSearch();
        });
    }
    
    // Prevent form submission - use live search instead
    const searchForm = document.getElementById('search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            performLiveSearch();
        });
    }
    
    // Handle Enter key in search input
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performLiveSearch();
            }
        });
    }
    
    // Add visual feedback for live search
    searchInput.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            this.style.borderColor = '#007bff';
            this.style.boxShadow = '0 0 0 3px rgba(0, 123, 255, 0.1)';
        } else {
            this.style.borderColor = '#d1d5db';
            this.style.boxShadow = 'none';
        }
    });
    
    // Add loading indicator
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        // Show loading state
        this.style.backgroundImage = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23999\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E")';
        this.style.backgroundRepeat = 'no-repeat';
        this.style.backgroundPosition = 'right 10px center';
        this.style.backgroundSize = '16px 16px';
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            this.style.backgroundImage = 'none';
        }, 300);
    });
    
    // Don't run initial search - let pagination handle the initial display
    // Only run search if there's an actual search term in the input
    if (searchInput.value.trim() !== '') {
        performLiveSearch();
    }
    
    console.log('Live search functionality initialized for students');
});
</script>