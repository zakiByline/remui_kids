<?php
/**
 * Enroll School Admins in Courses
 * 
 * This page allows you to enroll all school admins (Company Managers) 
 * in courses that have been copied to their school.
 * 
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Check for AJAX request FIRST, before loading config.php
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action === 'enroll_admins') {
    // CRITICAL: Define AJAX_SCRIPT BEFORE requiring config.php
    define('AJAX_SCRIPT', true);
}

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/enrollib.php');
require_once($CFG->dirroot.'/local/iomad/lib/company.php');

// Handle AJAX enrollment action FIRST, before page setup
if ($action === 'enroll_admins') {
    // Initialize minimal Moodle for AJAX
    require_login();
    $context = context_system::instance();
    require_capability('moodle/site:config', $context);
    
    // Set JSON header
    header('Content-Type: application/json');
    
    // Prevent any output buffering or page rendering
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    global $DB;
    
    // Check sesskey
    $sesskey = required_param('sesskey', PARAM_RAW);
    if (!confirm_sesskey($sesskey)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid session key']);
        exit;
    }
    
    $companyid = required_param('companyid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    
    try {
        // Verify company exists
        $company = $DB->get_record('company', ['id' => $companyid]);
        if (!$company) {
            echo json_encode(['status' => 'error', 'message' => 'School not found']);
            exit;
        }
        
        // Verify course exists and is in company's category
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            echo json_encode(['status' => 'error', 'message' => 'Course not found']);
            exit;
        }
        
        if (!empty($company->category) && $course->category != $company->category) {
            // Check if course is in a subcategory of company's category
            $company_category = $DB->get_record('course_categories', ['id' => $company->category]);
            if ($company_category) {
                $course_category = $DB->get_record('course_categories', ['id' => $course->category]);
                if (!$course_category || strpos($course_category->path, $company_category->path . '/') !== 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Course is not in this school\'s category']);
                    exit;
                }
            }
        }
        
        // Get all company managers (managertype = 1)
        $companyobj = new company($companyid);
        $manager_records = $companyobj->get_company_managers(1); // managertype = 1 for Company Managers
        
        if (empty($manager_records)) {
            echo json_encode(['status' => 'error', 'message' => 'No school admins found for this school']);
            exit;
        }
        
        // Extract user IDs from manager records, filtering out site administrators
        $manager_userids = [];
        foreach ($manager_records as $manager) {
            $userid = is_object($manager) ? $manager->userid : $manager;
            // Skip site administrators - they shouldn't be enrolled as school admins
            if (!is_siteadmin($userid)) {
                $manager_userids[] = $userid;
            }
        }
        
        if (empty($manager_userids)) {
            echo json_encode(['status' => 'error', 'message' => 'No school admins found (excluding site administrators)']);
            exit;
        }
        
        // Get manual enrollment instance for this course
        $enrol_instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
            'status' => 0
        ]);
        
        if (!$enrol_instance) {
            echo json_encode(['status' => 'error', 'message' => 'Manual enrollment method not found for this course']);
            exit;
        }
        
        // DO NOT assign any course-level roles - school admins should only have Company Manager role
        // at the system/company level, not at the course level
        $enrol_plugin = enrol_get_plugin('manual');
        $course_context = context_course::instance($courseid);
        
        $enrolled_count = 0;
        $already_enrolled_count = 0;
        $errors = [];
        
        foreach ($manager_userids as $userid) {
            
            // Check if already enrolled
            $existing_enrollment = $DB->get_record('user_enrolments', [
                'enrolid' => $enrol_instance->id,
                'userid' => $userid
            ]);
            
            if ($existing_enrollment) {
                // Remove any course-level roles that might have been assigned
                $course_roles = get_user_roles($course_context, $userid, false);
                foreach ($course_roles as $role) {
                    role_unassign($role->roleid, $userid, $course_context->id);
                }
                $already_enrolled_count++;
                continue;
            }
            
            // Enroll the user WITHOUT assigning any role (pass null for roleid)
            try {
                // Enroll without role assignment - school admins should only have Company Manager role
                $enrol_plugin->enrol_user($enrol_instance, $userid, null);
                
                // Ensure no roles were auto-assigned (some plugins might assign default roles)
                $course_roles = get_user_roles($course_context, $userid, false);
                foreach ($course_roles as $role) {
                    role_unassign($role->roleid, $userid, $course_context->id);
                }
                
                $enrolled_count++;
            } catch (Exception $e) {
                $errors[] = "Failed to enroll user ID $userid: " . $e->getMessage();
            }
        }
        
        $message = "Enrolled $enrolled_count school admin(s). ";
        if ($already_enrolled_count > 0) {
            $message .= "$already_enrolled_count were already enrolled. ";
        }
        if (!empty($errors)) {
            $message .= "Errors: " . implode(', ', $errors);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'enrolled' => $enrolled_count,
            'already_enrolled' => $already_enrolled_count
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle bulk enrollment for all courses in a school
if ($enroll_all && $companyid && confirm_sesskey()) {
    try {
        $company = $DB->get_record('company', ['id' => $companyid]);
        if (!$company || empty($company->category)) {
            throw new moodle_exception('School not found or has no category');
        }
        
        // Get all courses in the school's category tree
        $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
        if (!$school_category) {
            throw new moodle_exception('School category not found');
        }
        
        // Get all categories under the school's category (including all nested subcategories at any depth)
        // Moodle paths are like /1/5/6/7/ - we need to match all categories whose path starts with the school category's path
        $path_pattern = $school_category->path . '/%'; // Matches all subcategories at any depth
        
        $all_categories = $DB->get_records_sql(
            "SELECT id FROM {course_categories} 
             WHERE (id = ? OR path LIKE ?) AND visible = 1",
            [$company->category, $path_pattern]
        );
        
        if (empty($all_categories)) {
            // Fallback: just the school category itself
            $all_categories = [$company->category => (object)['id' => $company->category]];
        }
        
        $category_ids = array_keys($all_categories);
        list($in_sql, $params) = $DB->get_in_or_equal($category_ids);
        
        $courses = $DB->get_records_sql(
            "SELECT id, fullname, shortname FROM {course} 
             WHERE category $in_sql AND visible = 1 AND id > 1",
            $params
        );
        
        // Get all company managers (excluding site administrators)
        $companyobj = new company($companyid);
        $manager_records = $companyobj->get_company_managers(1);
        
        if (empty($manager_records)) {
            throw new moodle_exception('No school admins found for this school');
        }
        
        // Extract user IDs, filtering out site administrators
        $manager_userids = [];
        foreach ($manager_records as $manager) {
            $userid = is_object($manager) ? $manager->userid : $manager;
            // Skip site administrators - they shouldn't be enrolled as school admins
            if (!is_siteadmin($userid)) {
                $manager_userids[] = $userid;
            }
        }
        
        if (empty($manager_userids)) {
            throw new moodle_exception('No school admins found (excluding site administrators)');
        }
        
        // DO NOT assign any course-level roles - school admins should only have Company Manager role
        // at the system/company level, not at the course level
        $enrol_plugin = enrol_get_plugin('manual');
        $total_enrolled = 0;
        $total_courses = 0;
        
        foreach ($courses as $course) {
            // Get enrollment instance
            $enrol_instance = $DB->get_record('enrol', [
                'courseid' => $course->id,
                'enrol' => 'manual',
                'status' => 0
            ]);
            
            if (!$enrol_instance) {
                continue;
            }
            
            $course_context = context_course::instance($course->id);
            $course_enrolled = 0;
            
            foreach ($manager_userids as $userid) {
                
                // Check if already enrolled
                if ($DB->record_exists('user_enrolments', [
                    'enrolid' => $enrol_instance->id,
                    'userid' => $userid
                ])) {
                    // Remove any course-level roles that might have been assigned
                    $course_roles = get_user_roles($course_context, $userid, false);
                    foreach ($course_roles as $role) {
                        role_unassign($role->roleid, $userid, $course_context->id);
                    }
                    continue;
                }
                
                // Enroll the user WITHOUT assigning any role (pass null for roleid)
                try {
                    // Enroll without role assignment - school admins should only have Company Manager role
                    $enrol_plugin->enrol_user($enrol_instance, $userid, null);
                    
                    // Ensure no roles were auto-assigned (some plugins might assign default roles)
                    $course_roles = get_user_roles($course_context, $userid, false);
                    foreach ($course_roles as $role) {
                        role_unassign($role->roleid, $userid, $course_context->id);
                    }
                    
                    $course_enrolled++;
                    $total_enrolled++;
                } catch (Exception $e) {
                    // Log error but continue
                    error_log("Failed to enroll user $userid in course {$course->id}: " . $e->getMessage());
                }
            }
            
            if ($course_enrolled > 0) {
                $total_courses++;
            }
        }
        
        redirect(
            new moodle_url('/theme/remui_kids/admin/enroll_school_admins.php', ['companyid' => $companyid]),
            "Successfully enrolled school admins in $total_courses course(s). Total enrollments: $total_enrolled.",
            3,
            \core\output\notification::NOTIFY_SUCCESS
        );
        
    } catch (Exception $e) {
        redirect(
            new moodle_url('/theme/remui_kids/admin/enroll_school_admins.php', ['companyid' => $companyid]),
            'Error: ' . $e->getMessage(),
            3,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// If we get here, it's not an AJAX request - render the normal page
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$companyid = optional_param('companyid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$enroll_all = optional_param('enroll_all', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/enroll_school_admins.php');
$PAGE->set_title('Enroll School Admins in Courses');
$PAGE->set_heading('Enroll School Admins in Courses');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Include admin sidebar
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Get all schools
$schools = $DB->get_records_sql(
    "SELECT id, name FROM {company} ORDER BY name ASC",
    []
);

// Get selected school's courses
$school_courses = [];
$school_admins = [];
$company = null;

if ($companyid) {
    $company = $DB->get_record('company', ['id' => $companyid]);
    if ($company && !empty($company->category)) {
        // Get all courses in school's category tree (including all nested subcategories)
        $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
        if ($school_category) {
            // Get all categories under the school's category (including nested subcategories at any depth)
            // Moodle paths are like /1/5/6/7/ where each number is a category ID
            // We need to match: the school category itself AND all categories whose path starts with the school category's path
            $path_pattern = $school_category->path . '/%'; // Matches all subcategories at any depth
            
            $all_categories = $DB->get_records_sql(
                "SELECT id, name, path, depth 
                 FROM {course_categories} 
                 WHERE (id = ? OR path LIKE ?) AND visible = 1
                 ORDER BY path ASC",
                [$company->category, $path_pattern]
            );
            
            if (empty($all_categories)) {
                // Fallback: just the school category itself
                $all_categories = [$company->category => $school_category];
            }
            
            $category_ids = array_keys($all_categories);
            
            if (!empty($category_ids)) {
                list($in_sql, $params) = $DB->get_in_or_equal($category_ids);
                
                // Debug: Log category IDs being searched
                error_log("Enroll School Admins - School Category ID: " . $company->category);
                error_log("Enroll School Admins - School Category Path: " . $school_category->path);
                error_log("Enroll School Admins - Found " . count($category_ids) . " categories to search: " . implode(', ', $category_ids));
                
                $school_courses = $DB->get_records_sql(
                    "SELECT c.id, c.fullname, c.shortname, c.category,
                            cc.name as category_name,
                            cc.path as category_path,
                            (SELECT COUNT(DISTINCT ue.userid) 
                             FROM {user_enrolments} ue
                             JOIN {enrol} e ON e.id = ue.enrolid
                             WHERE e.courseid = c.id AND e.enrol = 'manual' AND ue.status = 0) as enrolled_count
                     FROM {course} c
                     LEFT JOIN {course_categories} cc ON c.category = cc.id
                     WHERE c.category $in_sql AND c.visible = 1 AND c.id > 1
                     ORDER BY cc.path ASC, c.fullname ASC",
                    $params
                );
                
                // Debug: Log courses found
                error_log("Enroll School Admins - Found " . count($school_courses) . " courses");
            } else {
                $school_courses = [];
            }
        }
        
        // Get school admins (excluding site administrators)
        $companyobj = new company($companyid);
        $manager_records = $companyobj->get_company_managers(1);
        foreach ($manager_records as $manager) {
            $userid = is_object($manager) ? $manager->userid : $manager;
            // Skip site administrators - they shouldn't be shown as school admins
            if (!is_siteadmin($userid)) {
                $user = $DB->get_record('user', ['id' => $userid]);
                if ($user) {
                    $school_admins[] = $user;
                }
            }
        }
    }
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: #f5f5f7;
    color: #1d1d1f;
}

.admin-main-content {
    position: fixed;
    top: 0;
    left: 280px;
    width: calc(100vw - 280px);
    height: 100vh;
    overflow-y: auto;
    z-index: 99;
    padding: 24px 32px;
    background: #f5f5f7;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #86868b;
    margin-bottom: 16px;
}

.breadcrumb a {
    color: #0071e3;
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.breadcrumb span {
    color: #86868b;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e1e1e3;
}

.page-header-left h1 {
    font-size: 32px;
    font-weight: 600;
    color: #1d1d1f;
    margin: 0 0 4px 0;
}

.page-header-left p {
    font-size: 15px;
    color: #86868b;
    margin: 0;
}

.school-selection {
    background: white;
    border-radius: 12px;
    border: 1px solid #d2d2d7;
    padding: 20px;
    margin-bottom: 20px;
}

.school-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d2d2d7;
    border-radius: 8px;
    font-size: 15px;
    background: white;
    cursor: pointer;
    transition: border-color 0.2s ease;
}

.school-select:focus {
    outline: none;
    border-color: #0071e3;
    box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.1);
}

.info-box {
    background: white;
    border: 1px solid #d2d2d7;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.info-box strong {
    color: #1d1d1f;
    font-weight: 600;
}

.courses-table {
    background: white;
    border-radius: 12px;
    border: 1px solid #d2d2d7;
    overflow: hidden;
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

th {
    background: #f5f5f7;
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: #86868b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e1e1e3;
}

td {
    padding: 16px;
    border-bottom: 1px solid #f5f5f7;
    font-size: 14px;
    color: #1d1d1f;
}

tbody tr {
    transition: background 0.2s ease;
}

tbody tr:hover {
    background: #f5f5f7;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
}

.btn-primary {
    background: #0071e3;
    color: white;
}

.btn-primary:hover {
    background: #0077ed;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 113, 227, 0.25);
}

.btn-secondary {
    background: white;
    color: #1d1d1f;
    border: 1px solid #d2d2d7;
}

.btn-secondary:hover {
    background: #f5f5f7;
    color: #1d1d1f;
    text-decoration: none;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success {
    background: #d1f4e0;
    color: #0f5132;
}

.badge-warning {
    background: #fff3cd;
    color: #664d03;
}

.bulk-actions {
    background: white;
    border: 1px solid #d2d2d7;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.bulk-actions strong {
    font-size: 15px;
    color: #1d1d1f;
    font-weight: 600;
}

.admins-list {
    background: white;
    border: 1px solid #d2d2d7;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.admins-list h3 {
    font-size: 18px;
    font-weight: 600;
    color: #1d1d1f;
    margin: 0 0 16px 0;
}

.admin-item {
    padding: 12px;
    border-bottom: 1px solid #f5f5f7;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.admin-item:last-child {
    border-bottom: none;
}

.admin-item span {
    font-size: 14px;
    color: #1d1d1f;
}

/* Responsive */
@media (max-width: 968px) {
    .admin-main-content {
        left: 0;
        width: 100vw;
        padding: 16px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .bulk-actions {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .courses-table {
        font-size: 13px;
    }
    
    table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
}
</style>

<div class="admin-main-content">
<div class="container">

<div class="breadcrumb">
    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/companies_list.php"><i class="fa fa-school"></i> Schools</a>
    <span>/</span>
    <span>Enroll Admins</span>
</div>

<div class="page-header">
    <div class="page-header-left">
        <h1>Enroll School Admins in Courses</h1>
        <p>Automatically enroll all school administrators in courses assigned to their school</p>
    </div>
</div>

<div class="school-selection">
    <label for="schoolSelect" style="display: block; margin-bottom: 10px; font-weight: 600;">Select School:</label>
    <select id="schoolSelect" class="school-select" onchange="handleSchoolChange(this.value)">
        <option value="">Choose a school...</option>
        <?php foreach ($schools as $school): ?>
            <option value="<?php echo $school->id; ?>" <?php echo $companyid == $school->id ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($school->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($companyid && $company): ?>
    <div class="info-box">
        <strong>School:</strong> <?php echo htmlspecialchars($company->name); ?><br>
        <strong>School Admins:</strong> <?php echo count($school_admins); ?> admin(s) found<br>
        <strong>Courses:</strong> <?php echo count($school_courses); ?> course(s) found in school category tree<br>
        <?php if ($company && !empty($company->category)): 
            $school_cat = $DB->get_record('course_categories', ['id' => $company->category]);
            if ($school_cat):
                $path_pattern = $school_cat->path . '/%';
                $all_cats = $DB->get_records_sql(
                    "SELECT id, name, path, depth FROM {course_categories} 
                     WHERE (id = ? OR path LIKE ?) AND visible = 1 ORDER BY path ASC",
                    [$company->category, $path_pattern]
                );
        ?>
            <strong>Categories Searched:</strong> <?php echo count($all_cats); ?> category/categories (including all nested subcategories)<br>
            <small style="color: #6b7280; font-style: italic;">
                Searching in: <?php echo htmlspecialchars($school_cat->name); ?> and all its subcategories (Foundation, Grade 1, etc.)
            </small>
        <?php endif; endif; ?>
    </div>
    
    <?php if (!empty($school_admins)): ?>
        <div class="admins-list">
            <h3 style="margin-top: 0;">School Administrators</h3>
            <?php foreach ($school_admins as $admin): ?>
                <div class="admin-item">
                    <span><?php echo fullname($admin); ?> (<?php echo $admin->email; ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($school_courses)): ?>
        <div class="bulk-actions">
            <div>
                <strong>Bulk Actions:</strong>
            </div>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="companyid" value="<?php echo $companyid; ?>">
                <input type="hidden" name="enroll_all" value="1">
                <button type="submit" class="btn btn-primary" onclick="return confirm('This will enroll all school admins in ALL courses for this school. Continue?');">
                    <i class="fa fa-users"></i> Enroll All Admins in All Courses
                </button>
            </form>
        </div>
        
        <div class="courses-table">
            <table>
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Shortname</th>
                        <th>Category</th>
                        <th>Total Enrolled</th>
                        <th>Admins Enrolled</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($school_courses as $course): 
                        // Check how many school admins are enrolled (check enrollment, not role assignment)
                        // Get manual enrollment instance
                        $enrol_instance = $DB->get_record('enrol', [
                            'courseid' => $course->id,
                            'enrol' => 'manual',
                            'status' => 0
                        ]);
                        
                        $enrolled_admins = 0;
                        if ($enrol_instance) {
                            foreach ($school_admins as $admin) {
                                // Check if user is enrolled (has enrollment record)
                                if ($DB->record_exists('user_enrolments', [
                                    'enrolid' => $enrol_instance->id,
                                    'userid' => $admin->id,
                                    'status' => 0 // Active enrollment
                                ])) {
                                    $enrolled_admins++;
                                }
                            }
                        }
                        
                        $all_enrolled = ($enrolled_admins == count($school_admins) && count($school_admins) > 0);
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($course->fullname); ?></td>
                            <td><?php echo htmlspecialchars($course->shortname); ?></td>
                            <td>
                                <?php 
                                if (isset($course->category_name)) {
                                    echo htmlspecialchars($course->category_name);
                                } else {
                                    $cat = $DB->get_record('course_categories', ['id' => $course->category]);
                                    echo $cat ? htmlspecialchars($cat->name) : 'Unknown';
                                }
                                ?>
                            </td>
                            <td><?php echo $course->enrolled_count ?? 0; ?> users</td>
                            <td><?php echo $enrolled_admins; ?> / <?php echo count($school_admins); ?></td>
                            <td>
                                <?php if ($all_enrolled && count($school_admins) > 0): ?>
                                    <span class="badge badge-success">All Enrolled</span>
                                <?php elseif (count($school_admins) > 0): ?>
                                    <span class="badge badge-warning">Incomplete</span>
                                <?php else: ?>
                                    <span class="badge">No Admins</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$all_enrolled && count($school_admins) > 0): ?>
                                    <button class="btn btn-primary btn-enroll" 
                                            data-company-id="<?php echo $companyid; ?>"
                                            data-course-id="<?php echo $course->id; ?>"
                                            data-course-name="<?php echo htmlspecialchars($course->fullname); ?>">
                                        <i class="fa fa-user-plus"></i> Enroll Admins
                                    </button>
                                <?php else: ?>
                                    <span style="color: #86868b; font-size: 14px; font-weight: 500;">
                                        <i class="fa fa-check-circle" style="color: #34c759;"></i> Enrolled
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="info-box">
            <p>No courses found in this school's category. Courses need to be assigned to the school first.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>
</div>

<script>
function handleSchoolChange(schoolId) {
    if (schoolId) {
        window.location.href = '?companyid=' + schoolId;
    } else {
        window.location.href = '<?php echo $PAGE->url; ?>';
    }
}

// Handle individual course enrollment
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-enroll').forEach(btn => {
        btn.addEventListener('click', async function() {
            const companyId = this.dataset.companyId;
            const courseId = this.dataset.courseId;
            const courseName = this.dataset.courseName;
            
            if (!confirm(`Enroll all school admins in "${courseName}"?`)) {
                return;
            }
            
            // Disable button
            this.disabled = true;
            this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enrolling...';
            
            try {
                const formData = new FormData();
                formData.append('action', 'enroll_admins');
                formData.append('sesskey', '<?php echo sesskey(); ?>');
                formData.append('companyid', companyId);
                formData.append('courseid', courseId);
                
                const response = await fetch('<?php echo $PAGE->url; ?>', {
                    method: 'POST',
                    body: formData
                });
                
                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                // Get response text first to check if it's JSON
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('Response was not JSON:', responseText);
                    throw new Error('Invalid response from server. Please check the browser console.');
                }
                
                if (data.status === 'success') {
                    alert(data.message);
                    // Reload page to refresh status
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                    this.disabled = false;
                    this.innerHTML = '<i class="fa fa-user-plus"></i> Enroll Admins';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                this.disabled = false;
                this.innerHTML = '<i class="fa fa-user-plus"></i> Enroll Admins';
            }
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>

