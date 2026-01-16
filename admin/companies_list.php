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
 * School Management Dashboard - Central Hub for School Administration
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/iomad/lib/company.php');

// Check for AJAX request FIRST, before loading config.php
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action) {
    // CRITICAL: Define AJAX_SCRIPT BEFORE requiring config.php
    define('AJAX_SCRIPT', true);
}

// Check if user is logged in
require_login();

// Check if user has admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Handle AJAX requests
if ($action) {
    // Set JSON header
    header('Content-Type: application/json');
    
    // Prevent any output buffering or page rendering
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    global $USER, $DB;
    
    switch ($action) {
        case 'get_license_details':
            $licenseid = intval($_GET['licenseid'] ?? 0);
            
            try {
                $license = $DB->get_record('companylicense', ['id' => $licenseid]);
                if (!$license) {
                    echo json_encode(['status' => 'error', 'message' => 'License not found']);
                    exit;
                }
                
                // Get linked courses
                $courses = company::get_courses_by_license($licenseid);
                
                // Get usage count
                $used = $DB->count_records('companylicense_users', [
                    'licenseid' => $licenseid,
                    'isusing' => 1
                ]);
                
                echo json_encode([
                    'status' => 'success',
                    'license' => $license,
                    'courses' => array_values($courses),
                    'used' => $used,
                    'available' => max(0, $license->allocation - $used)
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_school_courses_for_license':
            $companyid = intval($_GET['companyid'] ?? 0);
            
            try {
                $company = $DB->get_record('company', ['id' => $companyid]);
                if (!$company || empty($company->category)) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                // Get all courses in school's category tree
                $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
                if (!$school_category) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                $path_pattern = $school_category->path . '/%';
                $all_categories = $DB->get_records_sql(
                    "SELECT id FROM {course_categories}
                     WHERE (id = ? OR path LIKE ?) AND visible = 1",
                    [$company->category, $path_pattern]
                );
                
                $category_ids = array_keys($all_categories);
                if (empty($category_ids)) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                list($in_sql, $params) = $DB->get_in_or_equal($category_ids);
                
                $courses = $DB->get_records_sql(
                    "SELECT c.id, c.fullname, c.shortname
                     FROM {course} c
                     WHERE c.category $in_sql AND c.visible = 1 AND c.id > 1
                     ORDER BY c.fullname ASC",
                    $params
                );
                
                echo json_encode(['status' => 'success', 'courses' => array_values($courses)]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'create_license':
            $companyid = intval($_POST['companyid'] ?? 0);
            $form_data = json_decode($_POST['form_data'] ?? '{}', true);
            
            try {
                $company = $DB->get_record('company', ['id' => $companyid]);
                if (!$company) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found']);
                    exit;
                }
                
                // Extract form data
                $name = trim($form_data['name'] ?? '');
                $overall_allocation = isset($form_data['overall_allocation']) ? intval($form_data['overall_allocation']) : null;
                $startdate = intval($form_data['startdate'] ?? 0);
                $enddate = intval($form_data['enddate'] ?? 0);
                $courseids = $form_data['courseids'] ?? [];
                $percourse_allocations = $form_data['percourse_allocations'] ?? [];
                
                if (empty($name)) {
                    echo json_encode(['status' => 'error', 'message' => 'License name is required']);
                    exit;
                }
                
                if (empty($courseids)) {
                    echo json_encode(['status' => 'error', 'message' => 'At least one course must be selected']);
                    exit;
                }
                
                if ($startdate <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Start date is required']);
                    exit;
                }
                
                if ($enddate <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Expiry date is required']);
                    exit;
                }
                
                if ($enddate <= $startdate) {
                    echo json_encode(['status' => 'error', 'message' => 'Expiry date must be after start date']);
                    exit;
                }
                
                $licenses_created = [];
                
                // Create overall license if provided
                if ($overall_allocation !== null && $overall_allocation > 0) {
                    $license = new \stdClass();
                    $license->name = $name . ' - Overall';
                    $license->allocation = $overall_allocation;
                    $license->used = 0;
                    $license->startdate = $startdate;
                    $license->expirydate = $enddate;
                    $license->validlength = 0; // Not used, just dates
                    $license->companyid = $companyid;
                    $license->parentid = 0;
                    $license->type = 0; // Standard license
                    $license->program = 0;
                    $license->reference = '';
                    $license->instant = 0;
                    $license->cutoffdate = 0;
                    $license->clearonexpire = 0;
                    
                    $licenseid = $DB->insert_record('companylicense', $license);
                    
                    // Link all courses to overall license
                    foreach ($courseids as $courseid) {
                        $DB->insert_record('companylicense_courses', [
                            'licenseid' => $licenseid,
                            'courseid' => intval($courseid)
                        ]);
                    }
                    
                    $licenses_created[] = $licenseid;
                }
                
                // Create per-course licenses if provided
                foreach ($courseids as $courseid) {
                    $courseid_int = intval($courseid);
                    $percourse_allocation = isset($percourse_allocations[$courseid]) ? intval($percourse_allocations[$courseid]) : 0;
                    
                    if ($percourse_allocation <= 0) {
                        continue; // Skip courses without allocation
                    }
                    
                    $course = $DB->get_record('course', ['id' => $courseid_int]);
                    if (!$course) {
                        continue;
                    }
                    
                    $license = new \stdClass();
                    $license->name = $name . ' - ' . $course->fullname;
                    $license->allocation = $percourse_allocation;
                    $license->used = 0;
                    $license->startdate = $startdate;
                    $license->expirydate = $enddate;
                    $license->validlength = 0; // Not used, just dates
                    $license->companyid = $companyid;
                    $license->parentid = 0;
                    $license->type = 0; // Standard license
                    $license->program = 0;
                    $license->reference = '';
                    $license->instant = 0;
                    $license->cutoffdate = 0;
                    $license->clearonexpire = 0;
                    
                    $licenseid = $DB->insert_record('companylicense', $license);
                    
                    // Link this course to its license
                    $DB->insert_record('companylicense_courses', [
                        'licenseid' => $licenseid,
                        'courseid' => $courseid_int
                    ]);
                    
                    $licenses_created[] = $licenseid;
                }
                
                if (empty($licenses_created)) {
                    echo json_encode(['status' => 'success', 'message' => 'No limits provided. Courses remain unlimited.', 'licenseids' => [], 'count' => 0]);
                    exit;
                }
                
                // Mark courses as licensed
                foreach ($courseids as $courseid) {
                    $courseid_int = intval($courseid);
                    if (!$iomad_course = $DB->get_record('iomad_courses', ['courseid' => $courseid_int])) {
                        $iomad_course = new \stdClass();
                        $iomad_course->courseid = $courseid_int;
                        $iomad_course->licensed = 1;
                        $iomad_course->shared = 0;
                        $iomad_course->validlength = 0;
                        $iomad_course->warnexpire = 0;
                        $iomad_course->warncompletion = 0;
                        $iomad_course->notifyperiod = 0;
                        $iomad_course->expireafter = 0;
                        $iomad_course->warnnotstarted = 0;
                        $iomad_course->hasgrade = 1;
                        $DB->insert_record('iomad_courses', $iomad_course);
                    } else {
                        $iomad_course->licensed = 1;
                        $DB->update_record('iomad_courses', $iomad_course);
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'License(s) created successfully',
                    'licenseids' => $licenses_created,
                    'count' => count($licenses_created)
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create license: ' . $e->getMessage()]);
            }
            exit;
            
        case 'update_license':
            $licenseid = intval($_POST['licenseid'] ?? 0);
            $form_data = json_decode($_POST['form_data'] ?? '{}', true);
            
            try {
                $license = $DB->get_record('companylicense', ['id' => $licenseid]);
                if (!$license) {
                    echo json_encode(['status' => 'error', 'message' => 'License not found']);
                    exit;
                }
                
                // Extract form data
                $name = trim($form_data['name'] ?? '');
                $allocation = isset($form_data['allocation']) ? intval($form_data['allocation']) : null;
                $startdate = isset($form_data['startdate']) ? intval($form_data['startdate']) : null;
                $enddate = isset($form_data['enddate']) ? intval($form_data['enddate']) : null;
                $courseids = $form_data['courseids'] ?? null;
                
                // Update license fields
                if (!empty($name)) {
                    $license->name = $name;
                }
                if ($allocation !== null && $allocation > 0) {
                    // Can't reduce allocation below used count
                    if ($allocation < $license->used) {
                        echo json_encode(['status' => 'error', 'message' => 'Allocation cannot be less than used licenses (' . $license->used . ')']);
                        exit;
                    }
                    $license->allocation = $allocation;
                }
                if ($startdate !== null && $startdate > 0) {
                    $license->startdate = $startdate;
                }
                if ($enddate !== null && $enddate > 0) {
                    $license->expirydate = $enddate;
                }
                
                $DB->update_record('companylicense', $license);
                
                // Update course links if provided
                if ($courseids !== null && is_array($courseids)) {
                    // Remove old links
                    $DB->delete_records('companylicense_courses', ['licenseid' => $licenseid]);
                    
                    // Add new links
                    foreach ($courseids as $courseid) {
                        $DB->insert_record('companylicense_courses', [
                            'licenseid' => $licenseid,
                            'courseid' => intval($courseid)
                        ]);
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'License updated successfully'
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update license: ' . $e->getMessage()]);
            }
            exit;
            
        case 'extend_license':
            $licenseid = intval($_POST['licenseid'] ?? 0);
            $form_data = json_decode($_POST['form_data'] ?? '{}', true);
            
            try {
                $license = $DB->get_record('companylicense', ['id' => $licenseid]);
                if (!$license) {
                    echo json_encode(['status' => 'error', 'message' => 'License not found']);
                    exit;
                }
                
                $new_enddate = intval($form_data['enddate'] ?? 0);
                $extend_days = intval($form_data['extend_days'] ?? 0);
                
                if ($extend_days > 0) {
                    // Extend by number of days
                    $current_end = $license->expirydate > 0 ? $license->expirydate : time();
                    $license->expirydate = $current_end + ($extend_days * 86400);
                } else if ($new_enddate > 0) {
                    // Set specific end date
                    $license->expirydate = $new_enddate;
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Either end date or extension days must be provided']);
                    exit;
                }
                
                $DB->update_record('companylicense', $license);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'License extended successfully',
                    'new_enddate' => $license->expirydate
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to extend license: ' . $e->getMessage()]);
            }
            exit;
            
        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
            exit;
    }
    exit;
}

// Normal page rendering starts here
$companyid = optional_param('companyid', 0, PARAM_INT);

// Get all schools with stats
$schools = $DB->get_records_sql(
    "SELECT c.*, 
            (SELECT COUNT(*) FROM {company_users} cu WHERE cu.companyid = c.id AND cu.managertype = 1) as admin_count,
            (SELECT COUNT(DISTINCT co.id) 
             FROM {course} co 
             JOIN {course_categories} cc ON co.category = cc.id 
             WHERE (cc.id = c.category OR cc.path LIKE CONCAT(
                 (SELECT path FROM {course_categories} WHERE id = c.category), '/%'
             )) AND co.id > 1) as course_count
     FROM {company} c
     ORDER BY c.name ASC"
);

// Get selected school's data
$school_admins = [];
$school_courses = [];
$school_licenses = [];
$company = null;

if ($companyid) {
    $company = $DB->get_record('company', ['id' => $companyid]);
    if ($company && !empty($company->category)) {
        // Get all courses in school's category tree
        $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
        if ($school_category) {
            $path_pattern = $school_category->path . '/%';
            $all_categories = $DB->get_records_sql(
                "SELECT id, name, path, depth
                 FROM {course_categories}
                 WHERE (id = ? OR path LIKE ?) AND visible = 1
                 ORDER BY path ASC",
                [$company->category, $path_pattern]
            );

            if (empty($all_categories)) {
                $all_categories = [$company->category => $school_category];
            }

            $category_ids = array_keys($all_categories);

            if (!empty($category_ids)) {
                list($in_sql, $params) = $DB->get_in_or_equal($category_ids);
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
            } else {
                $school_courses = [];
            }
        }

        // Get school admins (excluding site administrators)
        $companyobj = new company($companyid);
        $manager_records = $companyobj->get_company_managers(1);
        foreach ($manager_records as $manager) {
            $userid = is_object($manager) ? $manager->userid : $manager;
            if (!is_siteadmin($userid)) {
                $user = $DB->get_record('user', ['id' => $userid]);
                if ($user) {
                    $school_admins[] = $user;
                }
            }
        }
        
        // Get all licenses for this school with usage statistics
        $school_licenses = $DB->get_records_sql(
            "SELECT cl.*,
                    COUNT(DISTINCT clc.courseid) as course_count,
                    COUNT(DISTINCT clu.id) as used_count,
                    GROUP_CONCAT(DISTINCT c.fullname SEPARATOR ', ') as course_names
             FROM {companylicense} cl
             LEFT JOIN {companylicense_courses} clc ON cl.id = clc.licenseid
             LEFT JOIN {companylicense_users} clu ON cl.id = clu.licenseid AND clu.isusing = 1
             LEFT JOIN {course} c ON clc.courseid = c.id
             WHERE cl.companyid = ?
             GROUP BY cl.id
             ORDER BY cl.startdate DESC, cl.name ASC",
            [$companyid]
        );
        
        // Process licenses to add course details
        foreach ($school_licenses as $license) {
            $license->courses = company::get_courses_by_license($license->id);
            $license->available = max(0, $license->allocation - $license->used);
            $license->usage_percent = $license->allocation > 0 
                ? round(($license->used / $license->allocation) * 100, 1) 
                : 0;
            $license->is_expired = $license->expirydate > 0 && $license->expirydate < time();
            $license->is_active = !$license->is_expired && $license->startdate <= time();
        }
    }
}

// Helper function to get school logo URL (using IOMAD's native method)
function get_school_logo_url($companyid) {
    // Use IOMAD's company::get_logo_url() method
    // Parameters: companyid, maxwidth (null for original), maxheight (60 for card display)
    $logo_url = company::get_logo_url($companyid, null, 60);
    return $logo_url ? $logo_url->out() : null;
}

// Set up page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/companies_list.php', $companyid ? ['companyid' => $companyid] : []);
$PAGE->set_title($companyid && $company ? htmlspecialchars($company->name) . ' - School Management' : 'School Management Dashboard');
$PAGE->set_heading($companyid && $company ? htmlspecialchars($company->name) : 'School Management Dashboard');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Include admin sidebar
require_once(__DIR__ . '/includes/admin_sidebar.php');
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
    
    /* Header Styles */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
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
    
    /* Button Styles */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 20px;
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
    
    .btn-small {
        padding: 6px 14px;
        font-size: 13px;
    }
    
    /* Schools Grid */
    .schools-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        margin-top: 24px;
    }
    
    .school-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #d2d2d7;
        padding: 24px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .school-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        border-color: #0071e3;
    }
    
    .school-card-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .school-logo {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        background: #f5f5f7;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        border: 1px solid #e1e1e3;
    }
    
    .school-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .school-logo-placeholder {
        font-size: 24px;
        font-weight: 600;
        color: #86868b;
    }
    
    .school-card-info {
        flex: 1;
        min-width: 0;
    }
    
    .school-name {
        font-size: 18px;
        font-weight: 600;
        color: #1d1d1f;
        margin: 0 0 4px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .school-meta {
        font-size: 13px;
        color: #86868b;
    }
    
    .school-stats {
        display: flex;
        gap: 20px;
        padding-top: 16px;
        border-top: 1px solid #f5f5f7;
    }
    
    .stat {
        flex: 1;
    }
    
    .stat-label {
        font-size: 12px;
        color: #86868b;
        margin-bottom: 4px;
    }
    
    .stat-value {
        font-size: 20px;
        font-weight: 600;
        color: #1d1d1f;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 64px 24px;
        color: #86868b;
    }
    
    .empty-state i {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.3;
    }
    
    .empty-state h3 {
        font-size: 20px;
        font-weight: 600;
        color: #1d1d1f;
        margin: 0 0 8px 0;
    }
    
    .empty-state p {
        font-size: 15px;
        color: #86868b;
        margin: 0 0 24px 0;
    }
    
    /* School Management View */
    .school-management {
        margin-top: 24px;
    }
    
    .section-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #d2d2d7;
        padding: 24px;
        margin-bottom: 20px;
    }
    
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid #f5f5f7;
    }
    
    .section-title {
        font-size: 20px;
        font-weight: 600;
        color: #1d1d1f;
        margin: 0;
    }
    
    /* Admins Grid */
    .admins-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
    }
    
    .admin-card {
        background: #f5f5f7;
        border-radius: 8px;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.2s ease;
    }
    
    .admin-card:hover {
        background: #e8e8ed;
    }
    
    .admin-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #0071e3;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 16px;
        flex-shrink: 0;
    }
    
    .admin-info {
        flex: 1;
        min-width: 0;
    }
    
    .admin-name {
        font-size: 15px;
        font-weight: 600;
        color: #1d1d1f;
        margin: 0 0 2px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .admin-email {
        font-size: 13px;
        color: #86868b;
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Table Styles */
    .courses-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    .courses-table th {
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
    
    .courses-table td {
        padding: 16px;
        border-bottom: 1px solid #f5f5f7;
        font-size: 14px;
        color: #1d1d1f;
    }
    
    .courses-table tbody tr {
        transition: background 0.2s ease;
    }
    
    .courses-table tbody tr:hover {
        background: #f5f5f7;
    }
    
    .course-name {
        font-weight: 600;
        color: #1d1d1f;
        margin-bottom: 2px;
    }
    
    .course-shortname {
        font-size: 13px;
        color: #86868b;
    }
    
    .actions-cell {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .badge-info {
        background: #e8f4fd;
        color: #0071e3;
    }
    
    /* Quick Actions Bar */
    .quick-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    /* License Management Styles */
    .licenses-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        gap: 20px;
    }
    
    .license-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #d2d2d7;
        padding: 20px;
        transition: all 0.2s ease;
    }
    
    .license-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }
    
    .license-card.expired {
        opacity: 0.7;
        border-color: #ff3b30;
    }
    
    .license-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
        padding-bottom: 16px;
        border-bottom: 1px solid #f5f5f7;
    }
    
    .license-title-section {
        flex: 1;
    }
    
    .license-name {
        font-size: 18px;
        font-weight: 600;
        color: #1d1d1f;
        margin: 0 0 8px 0;
    }
    
    .license-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .license-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        background: #e8f4fd;
        color: #0071e3;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .license-status {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .license-status.active {
        background: #d1f2eb;
        color: #16a34a;
    }
    
    .license-status.expired {
        background: #fee;
        color: #dc2626;
    }
    
    .license-status.pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .license-actions {
        display: flex;
        gap: 8px;
    }
    
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #d2d2d7;
        background: white;
        color: #1d1d1f;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    
    .btn-icon:hover {
        background: #f5f5f7;
        border-color: #0071e3;
        color: #0071e3;
    }
    
    .license-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 16px;
        padding: 16px;
        background: #f5f5f7;
        border-radius: 8px;
    }
    
    .stat-item {
        text-align: center;
    }
    
    .stat-item .stat-label {
        font-size: 12px;
        color: #86868b;
        margin-bottom: 4px;
    }
    
    .stat-item .stat-value {
        font-size: 20px;
        font-weight: 600;
        color: #1d1d1f;
    }
    
    .stat-item .stat-value.used {
        color: #0071e3;
    }
    
    .stat-item .stat-value.available {
        color: #16a34a;
    }
    
    .usage-bar {
        width: 100%;
        height: 8px;
        background: #e1e1e3;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 4px;
    }
    
    .usage-fill {
        height: 100%;
        background: linear-gradient(90deg, #16a34a 0%, #0071e3 100%);
        transition: width 0.3s ease;
    }
    
    .usage-percent {
        font-size: 12px;
        color: #86868b;
    }
    
    .license-dates {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 16px;
        padding: 12px;
        background: #f9f9f9;
        border-radius: 6px;
    }
    
    .date-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #1d1d1f;
    }
    
    .date-item i {
        color: #86868b;
        width: 16px;
    }
    
    .license-courses {
        padding-top: 16px;
        border-top: 1px solid #f5f5f7;
    }
    
    .courses-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        font-size: 14px;
        font-weight: 600;
        color: #1d1d1f;
    }
    
    .courses-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .course-tag {
        display: inline-block;
        padding: 4px 10px;
        background: #e8f4fd;
        color: #0071e3;
        border-radius: 4px;
        font-size: 12px;
    }
    
    /* Modal Styles */
    .license-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .license-modal-content {
        background: white;
        border-radius: 12px;
        padding: 30px;
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e1e1e3;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 24px;
        font-weight: 600;
        color: #1d1d1f;
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: none;
        background: #f5f5f7;
        color: #1d1d1f;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .modal-close:hover {
        background: #e1e1e3;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: #1d1d1f;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d2d2d7;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #0071e3;
        box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.1);
    }
    
    .form-group small {
        display: block;
        margin-top: 4px;
        color: #86868b;
        font-size: 12px;
    }
    
    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #e1e1e3;
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
        
        .schools-grid {
            grid-template-columns: 1fr;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        
        .admins-grid {
            grid-template-columns: 1fr;
        }
        
        .courses-table {
            font-size: 13px;
        }
        
        .licenses-grid {
            grid-template-columns: 1fr;
        }
        
        .license-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<script>
// License Management Functions
async function showCreateLicenseModal(companyid) {
    try {
        // Load courses for this school
        const response = await fetch(`?action=get_school_courses_for_license&companyid=${companyid}`);
        const data = await response.json();
        
        if (data.status !== 'success') {
            alert('Failed to load courses: ' + (data.message || 'Unknown error'));
            return;
        }
        
        const courses = data.courses || [];
        
        if (courses.length === 0) {
            alert('No courses available for this school. Please assign courses first.');
            return;
        }
        
        // Create modal
        const modal = createLicenseModal('Create License', companyid, null, courses);
        document.body.appendChild(modal);
        // Initialize fields after modal is in DOM
        setTimeout(() => updateLicenseFields(), 100);
    } catch (error) {
        console.error('Error loading courses:', error);
        alert('Failed to load courses. Please try again.');
    }
}

async function editLicense(licenseid) {
    try {
        // Load license details
        const response = await fetch(`?action=get_license_details&licenseid=${licenseid}`);
        const data = await response.json();
        
        if (data.status !== 'success') {
            alert('Failed to load license: ' + (data.message || 'Unknown error'));
            return;
        }
        
        const license = data.license;
        const courses = data.courses || [];
        
        // Load all school courses
        const coursesResponse = await fetch(`?action=get_school_courses_for_license&companyid=${license.companyid}`);
        const coursesData = await coursesResponse.json();
        const allCourses = coursesData.courses || [];
        
        // Create modal
        const modal = createLicenseModal('Edit License', license.companyid, license, allCourses, courses);
        document.body.appendChild(modal);
        // Initialize fields after modal is in DOM
        setTimeout(() => updateLicenseFields(), 100);
    } catch (error) {
        console.error('Error loading license:', error);
        alert('Failed to load license. Please try again.');
    }
}

async function extendLicense(licenseid) {
    try {
        // Load license details
        const response = await fetch(`?action=get_license_details&licenseid=${licenseid}`);
        const data = await response.json();
        
        if (data.status !== 'success') {
            alert('Failed to load license: ' + (data.message || 'Unknown error'));
            return;
        }
        
        const license = data.license;
        const currentEndDate = license.expirydate > 0 ? new Date(license.expirydate * 1000) : null;
        
        // Create extend modal
        const modal = document.createElement('div');
        modal.className = 'license-modal';
        modal.innerHTML = `
            <div class="license-modal-content">
                <div class="modal-header">
                    <h3>Extend License</h3>
                    <button class="modal-close" onclick="this.closest('.license-modal').remove()">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <form id="extendLicenseForm">
                    <input type="hidden" name="licenseid" value="${licenseid}">
                    <div class="form-group">
                        <label>Current Expiry Date</label>
                        <input type="text" value="${currentEndDate ? currentEndDate.toLocaleDateString() : 'Not set'}" disabled>
                    </div>
                    <div class="form-group">
                        <label>Extend By (Days)</label>
                        <input type="number" name="extend_days" min="1" placeholder="e.g., 30">
                        <small>Enter number of days to extend from current expiry date</small>
                    </div>
                    <div class="form-group">
                        <label>OR Set New Expiry Date</label>
                        <input type="date" name="enddate" 
                               value="${currentEndDate ? currentEndDate.toISOString().split('T')[0] : ''}">
                        <small>Set a specific expiry date (leave extend days empty if using this)</small>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="this.closest('.license-modal').remove()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Extend License</button>
                    </div>
                </form>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Handle form submission - get form from modal
        const extendForm = modal.querySelector('#extendLicenseForm');
        if (extendForm) {
            extendForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const extendData = {
                enddate: formData.get('enddate') ? Math.floor(new Date(formData.get('enddate')).getTime() / 1000) : 0,
                extend_days: parseInt(formData.get('extend_days')) || 0
            };
            
            try {
                const submitForm = new FormData();
                submitForm.append('licenseid', licenseid);
                submitForm.append('form_data', JSON.stringify(extendData));
                
                const response = await fetch('?action=extend_license', {
                    method: 'POST',
                    body: submitForm
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    alert('License extended successfully!');
                    location.reload();
                } else {
                    alert('Failed to extend license: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error extending license:', error);
                alert('Failed to extend license. Please try again.');
            }
            });
        }
    } catch (error) {
        console.error('Error loading license:', error);
        alert('Failed to load license. Please try again.');
    }
}

function createLicenseModal(title, companyid, license, allCourses, selectedCourses = []) {
    const isEdit = license !== null;
    const selectedCourseIds = selectedCourses.map(c => c.id);
    
    const modal = document.createElement('div');
    modal.className = 'license-modal';
    
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(0, 0, 0, 0);
    
    const defaultStartDate = license ? new Date(license.startdate * 1000) : tomorrow;
    const defaultEndDate = license && license.expirydate > 0 ? new Date(license.expirydate * 1000) : null;
    
    modal.innerHTML = `
        <div class="license-modal-content">
            <div class="modal-header">
                <h3>${title}</h3>
                <button class="modal-close" onclick="this.closest('.license-modal').remove()">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <form id="licenseForm">
                <input type="hidden" name="companyid" value="${companyid}">
                ${isEdit ? `<input type="hidden" name="licenseid" value="${license.id}">` : ''}
                
                <div class="form-group">
                    <label>License Name <span style="color: red;">*</span></label>
                    <input type="text" name="name" required 
                           value="${license ? escapeHtml(license.name) : ''}"
                           placeholder="e.g., School Name - Overall License">
                </div>
                
                <div class="form-group" id="overallLicenseGroup">
                    <label>Overall Allocation (Max Students Across All Courses)</label>
                    <input type="number" name="overall_allocation" id="overallAllocation" min="1"
                           value="${license ? license.allocation : ''}"
                           placeholder="e.g., 300">
                    <small>Optional. Leave empty for no overall limit (unlimited combined).</small>
                </div>
                
                <div class="form-group">
                    <label>Start Date <span style="color: red;">*</span></label>
                    <input type="date" name="startdate" required
                           value="${defaultStartDate.toISOString().split('T')[0]}">
                </div>
                
                <div class="form-group">
                    <label>Expiry Date <span style="color: red;">*</span></label>
                    <input type="date" name="enddate" required
                           value="${defaultEndDate ? defaultEndDate.toISOString().split('T')[0] : ''}">
                    <small>When the license expires</small>
                </div>
                
                <div class="form-group">
                    <label>Linked Courses <span style="color: red;">*</span></label>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #d2d2d7; border-radius: 6px; padding: 12px; background: #f9f9f9;">
                        ${allCourses.map(course => {
                            const courseLicense = license && selectedCourseIds.includes(course.id) ? 
                                (license.percourse_allocations && license.percourse_allocations[course.id] ? 
                                    license.percourse_allocations[course.id] : '') : '';
                            return `
                            <div style="display: flex; align-items: center; gap: 12px; padding: 10px; margin-bottom: 8px; background: white; border-radius: 6px; border: 1px solid #e1e1e3;">
                                <input type="checkbox" name="courseids[]" value="${course.id}" 
                                       class="course-checkbox"
                                       ${selectedCourseIds.includes(course.id) ? 'checked' : ''}
                                       onchange="toggleCourseAllocation(this, ${course.id})">
                                <div style="flex: 1;">
                                    <strong>${escapeHtml(course.fullname)}</strong>
                                    <div style="font-size: 12px; color: #666;">${escapeHtml(course.shortname)}</div>
                                </div>
                                <div id="percourse-allocation-${course.id}" style="display: ${selectedCourseIds.includes(course.id) ? 'block' : 'none'}; min-width: 150px;">
                                    <input type="number" name="percourse_allocation[${course.id}]" 
                                           min="1" placeholder="Max students" 
                                           value="${courseLicense}"
                                           style="width: 100%; padding: 6px; border: 1px solid #d2d2d7; border-radius: 4px;">
                                    <small style="display: block; font-size: 11px; color: #666; margin-top: 2px;">Optional per-course limit</small>
                                </div>
                            </div>
                        `;
                        }).join('')}
                    </div>
                    <small>Select courses. Optionally set per-course limits.</small>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.license-modal').remove()">Cancel</button>
                    <button type="submit" class="btn btn-primary">${isEdit ? 'Update' : 'Create'} License</button>
                </div>
            </form>
        </div>
    `;
    
    // Get the form element from the modal (before appending to DOM)
    const form = modal.querySelector('#licenseForm');
    
    // Handle form submission
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            const courseids = formData.getAll('courseids[]');
            
            // Collect per-course allocations
            const percourseAllocations = {};
            courseids.forEach(courseId => {
                const allocation = formData.get(`percourse_allocation[${courseId}]`);
                if (allocation) {
                    percourseAllocations[courseId] = parseInt(allocation);
                }
            });
            
            const licenseData = {
                name: formData.get('name'),
                overall_allocation: formData.get('overall_allocation') ? parseInt(formData.get('overall_allocation')) : null,
                startdate: formData.get('startdate') ? Math.floor(new Date(formData.get('startdate')).getTime() / 1000) : 0,
                enddate: formData.get('enddate') ? Math.floor(new Date(formData.get('enddate')).getTime() / 1000) : 0,
                courseids: courseids,
                percourse_allocations: percourseAllocations
            };
            
            try {
                const submitForm = new FormData();
                if (isEdit) {
                    submitForm.append('licenseid', formData.get('licenseid'));
                    submitForm.append('form_data', JSON.stringify(licenseData));
                    
                    const response = await fetch('?action=update_license', {
                        method: 'POST',
                        body: submitForm
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        alert('License updated successfully!');
                        location.reload();
                    } else {
                        alert('Failed to update license: ' + (result.message || 'Unknown error'));
                    }
                } else {
                    submitForm.append('companyid', companyid);
                    submitForm.append('form_data', JSON.stringify(licenseData));
                    
                    const response = await fetch('?action=create_license', {
                        method: 'POST',
                        body: submitForm
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        alert('License created successfully!');
                        location.reload();
                    } else {
                        alert('Failed to create license: ' + (result.message || 'Unknown error'));
                    }
                }
            } catch (error) {
                console.error('Error saving license:', error);
                alert('Failed to save license. Please try again.');
            }
        });
    }
    
    return modal;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateLicenseFields() {
    const checkboxes = document.querySelectorAll('.course-checkbox');
    checkboxes.forEach(cb => {
        const courseId = parseInt(cb.value);
        const perCourseDiv = document.getElementById(`percourse-allocation-${courseId}`);
        if (perCourseDiv) {
            if (cb.checked) {
                perCourseDiv.style.display = 'block';
            } else {
                perCourseDiv.style.display = 'none';
            }
        }
    });
}

function toggleCourseAllocation(checkbox, courseId) {
    const perCourseDiv = document.getElementById(`percourse-allocation-${courseId}`);
    if (!perCourseDiv) return;
    
    const input = perCourseDiv.querySelector('input');
    
    if (checkbox.checked) {
        perCourseDiv.style.display = 'block';
    } else {
        perCourseDiv.style.display = 'none';
    }
}
</script>

<div class="admin-main-content">
    <div class="container">
        
        <?php if (!$companyid): ?>
            <!-- Schools List View -->
            <div class="breadcrumb">
                <i class="fa fa-school"></i>
                <span>School Management</span>
            </div>
            
            <div class="page-header">
                <div class="page-header-left">
                    <h1>School Management</h1>
                    <p>Manage all your schools in one place</p>
                </div>
                <div>
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/company_create.php" class="btn btn-primary">
                        <i class="fa fa-plus"></i> Create School
                    </a>
                </div>
            </div>
            
            <?php if (empty($schools)): ?>
                <div class="empty-state">
                    <i class="fa fa-school"></i>
                    <h3>No Schools Found</h3>
                    <p>Get started by creating your first school</p>
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/company_create.php" class="btn btn-primary">
                        <i class="fa fa-plus"></i> Create School
                    </a>
                </div>
            <?php else: ?>
                <div class="schools-grid">
                    <?php foreach ($schools as $school): 
                        $logo_url = get_school_logo_url($school->id);
                        $initials = strtoupper(substr($school->name, 0, 2));
                    ?>
                        <div class="school-card" onclick="window.location.href='?companyid=<?php echo $school->id; ?>'">
                            <div class="school-card-header">
                                <div class="school-logo">
                                    <?php if ($logo_url): ?>
                                        <img src="<?php echo $logo_url; ?>" alt="<?php echo htmlspecialchars($school->name); ?>">
                                    <?php else: ?>
                                        <span class="school-logo-placeholder"><?php echo $initials; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="school-card-info">
                                    <h3 class="school-name"><?php echo htmlspecialchars($school->name); ?></h3>
                                    <div class="school-meta">
                                        <?php echo htmlspecialchars($school->shortname); ?>
                                        <?php if ($school->suspended): ?>
                                            <span style="color: #ff3b30;"> • Suspended</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="school-stats">
                                <div class="stat">
                                    <div class="stat-label">Admins</div>
                                    <div class="stat-value"><?php echo $school->admin_count ?? 0; ?></div>
                                </div>
                                <div class="stat">
                                    <div class="stat-label">Courses</div>
                                    <div class="stat-value"><?php echo $school->course_count ?? 0; ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
        <?php elseif ($companyid && $company): ?>
            <!-- Individual School Management View -->
            <div class="breadcrumb">
                <a href="?"><i class="fa fa-school"></i> Schools</a>
                <span>/</span>
                <span><?php echo htmlspecialchars($company->name); ?></span>
            </div>
            
            <div class="page-header">
                <div class="page-header-left">
                    <h1><?php echo htmlspecialchars($company->name); ?></h1>
                    <p><?php echo htmlspecialchars($company->shortname); ?> 
                        <?php if ($company->suspended): ?>
                            <span style="color: #ff3b30;">• Suspended</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="quick-actions">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/create_school_admin.php?companyid=<?php echo $companyid; ?>" class="btn btn-secondary">
                        <i class="fa fa-user-plus"></i> Add Admin
                    </a>
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/assign_to_school.php" class="btn btn-primary">
                        <i class="fa fa-book"></i> Assign Courses
                    </a>
                </div>
            </div>
            
            <div class="school-management">
                <!-- School Admins Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">School Administrators</h2>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/create_school_admin.php?companyid=<?php echo $companyid; ?>" class="btn btn-secondary btn-small">
                            <i class="fa fa-user-plus"></i> Add Admin
                        </a>
                    </div>
                    
                    <?php if (!empty($school_admins)): ?>
                        <div class="admins-grid">
                            <?php foreach ($school_admins as $admin): 
                                $initials = strtoupper(substr($admin->firstname, 0, 1) . substr($admin->lastname, 0, 1));
                            ?>
                                <div class="admin-card">
                                    <div class="admin-avatar"><?php echo $initials; ?></div>
                                    <div class="admin-info">
                                        <div class="admin-name"><?php echo fullname($admin); ?></div>
                                        <div class="admin-email"><?php echo htmlspecialchars($admin->email); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-users"></i>
                            <h3>No Administrators</h3>
                            <p>This school doesn't have any administrators yet.</p>
                            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/create_school_admin.php?companyid=<?php echo $companyid; ?>" class="btn btn-primary">
                                <i class="fa fa-user-plus"></i> Add Administrator
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Courses Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Courses (<?php echo count($school_courses); ?>)</h2>
                        <div class="quick-actions">
                            <?php if (!empty($school_courses)): ?>
                                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/enroll_school_admins.php?companyid=<?php echo $companyid; ?>" 
                                   class="btn btn-secondary btn-small">
                                    <i class="fa fa-user-plus"></i> Enroll Admins
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/assign_to_school.php" 
                               class="btn btn-primary btn-small">
                                <i class="fa fa-plus"></i> Assign Courses
                            </a>
                        </div>
                    </div>
                    
                    <?php if (!empty($school_courses)): ?>
                        <table class="courses-table">
                            <thead>
                                <tr>
                                    <th>Course Name</th>
                                    <th>Category</th>
                                    <th>Enrolled</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($school_courses as $course): ?>
                                    <tr>
                                        <td>
                                            <div class="course-name"><?php echo format_string($course->fullname); ?></div>
                                            <div class="course-shortname"><?php echo htmlspecialchars($course->shortname); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($course->category_name ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <i class="fa fa-users"></i> <?php echo $course->enrolled_count ?? 0; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" 
                                                   class="btn btn-secondary btn-small" target="_blank">
                                                    <i class="fa fa-external-link-alt"></i> View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-book"></i>
                            <h3>No Courses Assigned</h3>
                            <p>This school doesn't have any courses yet. Assign courses to get started.</p>
                            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/assign_to_school.php" class="btn btn-primary">
                                <i class="fa fa-plus"></i> Assign Courses
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Licenses Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Licenses (<?php echo count($school_licenses); ?>)</h2>
                        <div class="quick-actions">
                            <button class="btn btn-primary btn-small" onclick="showCreateLicenseModal(<?php echo $companyid; ?>)">
                                <i class="fa fa-plus"></i> Create License
                            </button>
                        </div>
                    </div>
                    
                    <?php if (!empty($school_licenses)): ?>
                        <div class="licenses-grid">
                            <?php foreach ($school_licenses as $license): ?>
                                <div class="license-card <?php echo $license->is_expired ? 'expired' : ''; ?>">
                                    <div class="license-header">
                                        <div class="license-title-section">
                                            <h3 class="license-name"><?php echo htmlspecialchars($license->name); ?></h3>
                                            <div class="license-meta">
                                                <span class="license-type-badge">
                                                    <?php 
                                                    if (count($license->courses) > 1) {
                                                        echo '<i class="fa fa-globe"></i> Overall License';
                                                    } else {
                                                        echo '<i class="fa fa-book"></i> Per-Course License';
                                                    }
                                                    ?>
                                                </span>
                                                <?php if ($license->is_expired): ?>
                                                    <span class="license-status expired">Expired</span>
                                                <?php elseif ($license->is_active): ?>
                                                    <span class="license-status active">Active</span>
                                                <?php else: ?>
                                                    <span class="license-status pending">Pending</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="license-actions">
                                            <button class="btn-icon" onclick="editLicense(<?php echo $license->id; ?>)" title="Edit License">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <button class="btn-icon" onclick="extendLicense(<?php echo $license->id; ?>)" title="Extend License">
                                                <i class="fa fa-calendar-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="license-stats">
                                        <div class="stat-item">
                                            <div class="stat-label">Allocation</div>
                                            <div class="stat-value"><?php echo number_format($license->allocation); ?></div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-label">Used</div>
                                            <div class="stat-value used"><?php echo number_format($license->used); ?></div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-label">Available</div>
                                            <div class="stat-value available"><?php echo number_format($license->available); ?></div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-label">Usage</div>
                                            <div class="stat-value">
                                                <div class="usage-bar">
                                                    <div class="usage-fill" style="width: <?php echo min(100, $license->usage_percent); ?>%"></div>
                                                </div>
                                                <span class="usage-percent"><?php echo $license->usage_percent; ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="license-dates">
                                        <div class="date-item">
                                            <i class="fa fa-calendar-check"></i>
                                            <span><strong>Start:</strong> <?php echo $license->startdate > 0 ? date('Y-m-d', $license->startdate) : 'Not set'; ?></span>
                                        </div>
                                        <div class="date-item">
                                            <i class="fa fa-calendar-times"></i>
                                            <span><strong>Expiry:</strong> <?php echo $license->expirydate > 0 ? date('Y-m-d', $license->expirydate) : 'Never'; ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($license->courses)): ?>
                                        <div class="license-courses">
                                            <div class="courses-header">
                                                <i class="fa fa-book"></i>
                                                <strong>Linked Courses (<?php echo count($license->courses); ?>):</strong>
                                            </div>
                                            <div class="courses-list">
                                                <?php foreach ($license->courses as $course): ?>
                                                    <span class="course-tag"><?php echo htmlspecialchars($course->fullname); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-key"></i>
                            <h3>No Licenses</h3>
                            <p>This school doesn't have any licenses yet. Create licenses to manage course access.</p>
                            <button class="btn btn-primary" onclick="showCreateLicenseModal(<?php echo $companyid; ?>)">
                                <i class="fa fa-plus"></i> Create License
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
?>
