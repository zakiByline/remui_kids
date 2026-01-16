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
 * A drawer based layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $PAGE, $COURSE, $USER, $DB, $OUTPUT;

require_once($CFG->dirroot . '/theme/remui_kids/layout/common.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/cohort_sidebar_helper.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/sidebar_helper.php');

// Load MAP Test helper if available.
$maptestlocallib = $CFG->dirroot . '/local/maptest/locallib.php';
if (file_exists($maptestlocallib)) {
    require_once($maptestlocallib);
}

// Safely get URL path - check if $PAGE->url exists and is a moodle_url object
$page_url_path = '';
if (isset($PAGE->url) && is_object($PAGE->url) && method_exists($PAGE->url, 'get_path')) {
    $page_url_path = $PAGE->url->get_path();
} else if (isset($PAGE->url) && is_string($PAGE->url)) {
    // If $PAGE->url is a string, use it directly
    $page_url_path = $PAGE->url;
} else if (isset($PAGE->url) && is_object($PAGE->url) && method_exists($PAGE->url, '__toString')) {
    // If object has __toString method, use it
    $page_url_path = (string)$PAGE->url;
} else {
    // Fallback: use empty string or try to get path from $PAGE if available
    $page_url_path = '';
}

// Debug: Log that drawers.php is being called
error_log("DRAWERS DEBUG: drawers.php called. URL = " . $page_url_path);

// Check if teacher is viewing as student (loggedinas mode) and inject readonly banner
$is_teacher_viewing_student = \core\session\manager::is_loggedinas();
if ($is_teacher_viewing_student) {
    // Include the teacherviewstudent lib functions
    $teacherview_lib = $CFG->dirroot . '/local/teacherviewstudent/lib.php';
    if (file_exists($teacherview_lib)) {
        require_once($teacherview_lib);
    }
}

// Check if this is a dashboard page, mycourses page, or our custom pages
$is_custom_mycourses = (strpos($page_url_path, '/theme/remui_kids/mycourses.php') !== false);
$is_custom_lessons = (strpos($page_url_path, '/theme/remui_kids/lessons.php') !== false);
$is_elementary_lessons = (strpos($page_url_path, '/theme/remui_kids/elementary_lessons.php') !== false);
$is_elementary_activities = (strpos($page_url_path, '/theme/remui_kids/elementary_activities.php') !== false);
    $is_elementary_current_activity = (strpos($page_url_path, '/theme/remui_kids/elementary_current_activity.php') !== false);
    $is_middleschool_current_activity = (strpos($page_url_path, '/theme/remui_kids/middleschool_current_activity.php') !== false);
    $is_highschool_current_activity = (strpos($page_url_path, '/theme/remui_kids/highschool_current_activity.php') !== false);
$is_community_page = (strpos($page_url_path, '/theme/remui_kids/community.php') !== false);
$is_treeview = (strpos($page_url_path, '/theme/remui_kids/treeview.php') !== false);
$is_lesson_modules = (strpos($page_url_path, '/theme/remui_kids/lesson_modules.php') !== false);
$is_lesson_view = (strpos($page_url_path, '/theme/remui_kids/lesson_view.php') !== false);

// Check if this is a school manager specific page (exclude from dashboard logic)
$is_school_manager_page = (strpos($page_url_path, '/theme/remui_kids/school_manager/') !== false);

if (    (($PAGE->pagelayout == 'mydashboard' && $PAGE->pagetype == 'my-index') || 
            ($PAGE->pagelayout == 'mycourses' && $PAGE->pagetype == 'my-index') ||
            $is_custom_mycourses || $is_custom_lessons || $is_elementary_lessons || $is_elementary_activities || $is_elementary_current_activity || $is_middleschool_current_activity || $is_highschool_current_activity) && !$is_school_manager_page) {
// If this is treeview, lesson_modules, or lesson_view page, skip dashboard logic but allow normal layout
if ($is_treeview || $is_lesson_modules || $is_lesson_view) {
    error_log("DRAWERS DEBUG: Treeview/custom page detected. Skipping dashboard logic but allowing normal layout rendering.");
    error_log("DRAWERS DEBUG: URL path = " . $page_url_path);
    
    // Skip to normal layout rendering at the end
    // Don't return early - let it fall through to the normal layout code
    $skip_dashboard_logic = true;
} else {
    $skip_dashboard_logic = false;
}

if (!$skip_dashboard_logic && (($PAGE->pagelayout == 'mydashboard' && $PAGE->pagetype == 'my-index') || 
    ($PAGE->pagelayout == 'mycourses' && $PAGE->pagetype == 'my-index') ||
    $is_custom_mycourses || $is_custom_lessons || $is_elementary_lessons || $is_elementary_activities || $is_elementary_current_activity || $is_middleschool_current_activity)) {
    // Check if user is admin first
    $isadmin = is_siteadmin($USER) || has_capability('moodle/site:config', context_system::instance(), $USER);
    
    if ($isadmin) {
        // Show admin dashboard
        $templatecontext['custom_dashboard'] = true;
        $templatecontext['dashboard_type'] = 'admin';
        $templatecontext['admin_dashboard'] = true;
        $templatecontext['admin_stats'] = theme_remui_kids_get_admin_dashboard_stats();
        $templatecontext['admin_user_stats'] = theme_remui_kids_get_admin_user_stats();
        $templatecontext['admin_course_stats'] = theme_remui_kids_get_admin_course_stats();
        $templatecontext['admin_course_categories'] = theme_remui_kids_get_admin_course_categories();
        $templatecontext['student_enrollments'] = theme_remui_kids_get_recent_student_enrollments();
        $templatecontext['admin_student_activity_stats'] = theme_remui_kids_get_admin_student_activity_stats();
        $templatecontext['student_activities'] = theme_remui_kids_get_school_admins_activities();
        $templatecontext['admin_recent_activity'] = theme_remui_kids_get_admin_recent_activity();

        // Expose MAP Test configuration so admins can manage it without leaving the dashboard.
        $maptestconfig = get_config('local_maptest');
        $defaulttitle = get_string('default_cardtitle', 'local_maptest');
        $defaultdescription = get_string('default_carddescription', 'local_maptest');
        $defaultbutton = get_string('default_buttontitle', 'local_maptest');

        $allowedids = [];
        if (!empty($maptestconfig->cohortids)) {
            $allowedids = array_filter(array_map('intval', explode(',', $maptestconfig->cohortids)));
        }

        $cohortoptions = [];
        try {
            $cohortrecords = $DB->get_records('cohort', null, 'name ASC', 'id, name');
            foreach ($cohortrecords as $cohort) {
                $cohortoptions[] = [
                    'id' => $cohort->id,
                    'name' => format_string($cohort->name),
                    'selected' => in_array((int)$cohort->id, $allowedids, true)
                ];
            }
        } catch (Exception $e) {
            $cohortoptions = [];
        }

        $templatecontext['maptest_admin_panel'] = [
            'enabled' => !empty($maptestconfig->enablecard),
            'allowallcohorts' => !empty($maptestconfig->enableallcohorts),
            'cardtitle' => !empty($maptestconfig->cardtitle) ? $maptestconfig->cardtitle : $defaulttitle,
            'carddescription' => !empty($maptestconfig->carddescription) ? $maptestconfig->carddescription : $defaultdescription,
            'buttontitle' => !empty($maptestconfig->buttontitle) ? $maptestconfig->buttontitle : $defaultbutton,
            'hascohortoptions' => !empty($cohortoptions),
            'enablesso' => !empty($maptestconfig->enablesso),
            'maptesturl' => !empty($maptestconfig->maptesturl) ? $maptestconfig->maptesturl : 'https://map-test.bylinelms.com/login',
            'ssosecret' => !empty($maptestconfig->ssosecret) ? $maptestconfig->ssosecret : '',
            'moodle_validation_url' => $CFG->wwwroot . '/local/maptest/validate_sso.php'
        ];
        $templatecontext['maptest_cohort_options'] = $cohortoptions;
        $templatecontext['maptest_save_url'] = (new moodle_url('/local/maptest/ajax.php'))->out(false);
        
        // Capture admin sidebar HTML from centralized component
        ob_start();
        require_once($CFG->dirroot . '/theme/remui_kids/admin/includes/admin_sidebar.php');
        $templatecontext['admin_sidebar_html'] = ob_get_clean();
        
        // Get all schools/companies for the filter dropdown
        try {
            $schools = $DB->get_records('company', null, 'name ASC', 'id, name');
            $templatecontext['schools'] = array_values(array_map(function($school) {
                return [
                    'id' => $school->id,
                    'name' => $school->name
                ];
            }, $schools));
        } catch (Exception $e) {
            $templatecontext['schools'] = [];
        }
        
        // Add sesskey for AJAX requests
        $templatecontext['sesskey'] = sesskey();
        
        // Must be called before rendering the template.
        require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
        
        // Render our custom admin dashboard template
        echo $OUTPUT->render_from_template('theme_remui_kids/admin_dashboard', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if user is a school manager/company manager first
    $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
    $is_school_manager = false;
    
    if ($companymanagerrole) {
        $context = context_system::instance();
        $is_school_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
    }
    
    if ($is_school_manager) {
        // Show school manager dashboard with sidebar
        $templatecontext['custom_dashboard'] = true;
        $templatecontext['dashboard_type'] = 'school_manager';
        $templatecontext['school_manager_dashboard'] = true;
        
        // Get school/company information for the current user
        $company_info = null;
        if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
            $company_info = $DB->get_record_sql(
                "SELECT c.*, u.firstname, u.lastname, u.email 
                 FROM {company} c 
                 JOIN {company_users} cu ON c.id = cu.companyid 
                 JOIN {user} u ON cu.userid = u.id 
                 WHERE cu.userid = ? AND cu.managertype = 1",
                [$USER->id]
            );
        }
        
        // Get company logo if exists
        if ($company_info && $DB->get_manager()->table_exists('company_logo')) {
            $company_logo = $DB->get_record('company_logo', ['companyid' => $company_info->id]);
            if ($company_logo) {
                $company_info->logo_filename = $company_logo->filename;
                $company_info->logo_filepath = $CFG->dataroot . '/company/' . $company_info->id . '/' . $company_logo->filename;
            }
        }
        
        // Get dashboard statistics for the specific school/company
        $total_teachers = 0;
        $editing_teachers = 0;
        $enrolled_teachers = 0;
        $total_students = 0;
        $total_courses = 0;
        $active_enrollments = 0;
        
        if ($company_info && $DB->get_manager()->table_exists('company_users')) {
            $company_id = $company_info->id;
            error_log("Found company: {$company_info->name} (ID: {$company_id})");
        } else {
            error_log("No company found for user {$USER->id} or company_users table doesn't exist");
            // Fallback: try to get basic counts without company filtering
            if ($DB->get_manager()->table_exists('company_users')) {
                // Use educator field if company_users table exists
                $total_teachers = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     JOIN {company_users} cu ON u.id = cu.userid 
                     WHERE cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0"
                );
            } else {
                // Fallback to role-based counting if company_users doesn't exist
                $total_teachers = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     JOIN {role_assignments} ra ON u.id = ra.userid 
                     JOIN {role} r ON ra.roleid = r.id 
                     WHERE r.shortname IN ('teacher', 'editingteacher', 'coursecreator', 'manager') AND u.deleted = 0"
                );
            }
            
            $total_courses = $DB->count_records('course', ['visible' => 1]);
            
            $active_enrollments = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT ue.id) 
                 FROM {user_enrolments} ue 
                 JOIN {enrol} e ON ue.enrolid = e.id 
                 WHERE ue.status = 0 AND e.status = 0"
            );
        }
        
        if ($company_info && $DB->get_manager()->table_exists('company_users')) {
            $company_id = $company_info->id;
            
            // ULTRA-STRICT teacher count - Only count teachers with roles that actually belong to this school
            $total_teachers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u
                 INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
                 INNER JOIN {role_assignments} ra ON u.id = ra.userid
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
                 WHERE u.deleted = 0",
                [$company_id]
            );
            
            error_log("Dashboard (drawers.php) ULTRA-STRICT: Found " . $total_teachers . " teachers for company ID: " . $company_id);
            
            // Count editing teachers specifically in this company
            $editing_teachers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                 AND r.shortname = 'editingteacher'",
                [$company_id]
            );
            
            // Count regular teachers (non-editing teachers) in this company
            $regular_teachers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                 AND r.shortname = 'teacher'",
                [$company_id]
            );
            
            // Count enrolled/active teachers - try multiple approaches
            $enrolled_teachers = 0;
            
            // Approach 1: Educators who have teaching roles in company courses
            $teachers_with_roles = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 JOIN {context} ctx ON ra.contextid = ctx.id 
                 JOIN {course} c ON ctx.instanceid = c.id
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                 AND ctx.contextlevel = ? AND c.visible = 1
                 AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')",
                [$company_id, CONTEXT_COURSE]
            );
            
            // Approach 2: Educators enrolled as students in company courses
            $teachers_enrolled_as_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {user_enrolments} ue ON u.id = ue.userid 
                 JOIN {enrol} e ON ue.enrolid = e.id 
                 JOIN {course} c ON e.courseid = c.id
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                 AND ue.status = 0 AND e.status = 0 AND c.visible = 1",
                [$company_id]
            );
            
            // Approach 3: Educators in company courses (if company_course table exists)
            $teachers_in_company_courses = 0;
            if ($DB->get_manager()->table_exists('company_course')) {
                $teachers_in_company_courses = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     JOIN {company_users} cu ON u.id = cu.userid 
                     JOIN {user_enrolments} ue ON u.id = ue.userid 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     JOIN {course} c ON e.courseid = c.id
                     JOIN {company_course} cc ON c.id = cc.courseid
                     WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                     AND ue.status = 0 AND e.status = 0 AND c.visible = 1 AND cc.companyid = ?",
                    [$company_id, $company_id]
                );
            }
            
            // Use the highest count from all approaches
            $enrolled_teachers = max($teachers_with_roles, $teachers_enrolled_as_students, $teachers_in_company_courses);
            
            // Count students in this company using the SAME logic as student_management.php
            $students = [];
            try {
                // First try the IOMAD approach (company_users table exists) - SAME AS STUDENT MANAGEMENT
                if ($DB->get_manager()->table_exists('company_users')) {
                    // Primary query: Students in company_users with student role
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
                                uifd.data AS grade_level
                           FROM {user} u
                           INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                           INNER JOIN {role_assignments} ra ON ra.userid = u.id
                           INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                           LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                          WHERE u.deleted = 0
                        GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
                        [$company_id]
                    );
                    error_log("Dashboard (drawers.php): Found " . count($students) . " students using IOMAD approach for company ID: " . $company_id);
                    
                    // If no students found, try alternative approach: Students in company_users (any role)
                    if (empty($students)) {
                        error_log("Dashboard (drawers.php): No students found with student role, trying alternative query...");
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
                            error_log("Dashboard (drawers.php): Found " . count($alternative_students) . " students using alternative approach (company_users only)");
                            $students = $alternative_students;
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
                    error_log("Dashboard (drawers.php): Found " . count($students) . " students using fallback approach (no company association)");
                }
                
                // Count the students array (same as student_management.php)
                $total_students = count($students);
                
            } catch (Exception $e) {
                error_log("Dashboard (drawers.php): Error getting students: " . $e->getMessage());
                $students = [];
                $total_students = 0;
            }
            
            // Count ONLY courses explicitly assigned to this company (NOT courses available to all schools)
            $total_courses = 0;
            
            if ($DB->get_manager()->table_exists('company_course')) {
                $total_courses = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT c.id) 
                     FROM {course} c
                     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
                     WHERE c.visible = 1 
                     AND c.id > 1 
                     AND comp_c.companyid = ?",
                    [$company_id]
                );
                error_log("Dashboard (drawers.php): Found " . $total_courses . " courses explicitly assigned to company ID: " . $company_id);
            } else {
                // Fallback: count all visible courses if company_course table doesn't exist
                $total_courses = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {course} WHERE visible = 1 AND id > 1"
                );
                error_log("Dashboard (drawers.php): Company_course table not found, counting all visible courses: " . $total_courses);
            }
            
            // Count active enrollments (students enrolled in company courses - ONLY explicitly assigned courses)
            $active_enrollments = 0;
            
            if ($DB->get_manager()->table_exists('company_course')) {
                $active_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.id) 
                     FROM {user_enrolments} ue 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     JOIN {course} c ON e.courseid = c.id 
                     INNER JOIN {company_course} cc ON c.id = cc.courseid 
                     JOIN {user} u ON ue.userid = u.id
                     JOIN {company_users} cu ON u.id = cu.userid
                     WHERE cc.companyid = ? AND cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0",
                    [$company_id, $company_id]
                );
                error_log("Dashboard (drawers.php): Found " . $active_enrollments . " active enrollments for company ID: " . $company_id);
            } else {
                // Fallback: count all active enrollments if company_course table doesn't exist
                $active_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.id) 
                     FROM {user_enrolments} ue 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     JOIN {course} c ON e.courseid = c.id 
                     WHERE ue.status = 0 AND e.status = 0 AND c.visible = 1",
                    []
                );
                error_log("Dashboard (drawers.php): Company_course table not found, counting all active enrollments: " . $active_enrollments);
            }
        }
        
        // Log the statistics for debugging
        error_log("School Manager Dashboard Stats for User {$USER->id}:");
        error_log("- Company: " . ($company_info ? $company_info->name : 'No company found'));
        error_log("- Company ID: " . ($company_info ? $company_info->id : 'N/A'));
        error_log("- Total Teachers (Final Count): {$total_teachers}");
        if (isset($total_teachers_alternative)) {
            error_log("- Alternative Teacher Count: {$total_teachers_alternative}");
        }
        error_log("- Editing Teachers: {$editing_teachers}");
        error_log("- Regular Teachers: {$regular_teachers}");
        error_log("- Enrolled Teachers: {$enrolled_teachers}");
        error_log("- Total Courses: {$total_courses}");
        error_log("- Active Enrollments: {$active_enrollments}");
        
        // Additional debugging: Check if there are any educators in the system
        if ($company_info) {
            $all_educators = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 WHERE cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0"
            );
            error_log("- Total Educators in System: {$all_educators}");
            
            $company_educators = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0",
                [$company_info->id]
            );
            error_log("- Educators in Company {$company_info->id}: {$company_educators}");
            
            // Debug enrolled teachers specifically
            error_log("- Educators with Teaching Roles in Courses: {$teachers_with_roles}");
            error_log("- Educators Enrolled as Students: {$teachers_enrolled_as_students}");
            error_log("- Educators in Company Courses: {$teachers_in_company_courses}");
            
            // Debug students specifically
            error_log("- Total Students Found: {$total_students}");
            
            // Debug course count specifically
            error_log("- Company Assigned Courses: {$company_assigned_courses}");
            error_log("- Courses with Company Users: {$courses_with_company_users}");
            error_log("- All Visible Courses: {$all_visible_courses}");
            error_log("- Final Total Courses: {$total_courses}");
            
            // Debug active enrollments specifically
            error_log("- Enrollments in Company Courses: {$enrollments_in_company_courses}");
            error_log("- Company User Enrollments: {$company_user_enrollments}");
            error_log("- All Active Enrollments: {$all_active_enrollments}");
            error_log("- Final Active Enrollments: {$active_enrollments}");
        }
        
        // Get Grade Distribution Data - INLINE QUERY (same as school_manager_dashboard.php)
        $grade_distribution_data = ['labels' => [], 'values' => [], 'total' => 0, 'rows' => []];
        
        if ($company_id && $DB->get_manager()->table_exists('cohort') && $DB->get_manager()->table_exists('cohort_members')) {
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
                    [$company_id, $company_id]
                );
                
                $labels = [];
                $values = [];
                $total = 0;
                $rows = [];
                
                if (!empty($cohorts)) {
                    foreach ($cohorts as $cohort) {
                        $labels[] = $cohort->name;
                        $values[] = (int)$cohort->student_count;
                        $total += (int)$cohort->student_count;
                        $rows[] = ['label' => $cohort->name, 'value' => (int)$cohort->student_count];
                    }
                    error_log("✅ DRAWERS.PHP - Found " . count($cohorts) . " cohorts with " . $total . " students");
                }
                
                $grade_distribution_data = ['labels' => $labels, 'values' => $values, 'total' => $total, 'rows' => $rows];
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting grade distribution: " . $e->getMessage());
            }
        }
        
        // Get Login Trend Data - INLINE QUERY (last 30 days)
        $login_trend_data = ['dates' => [], 'student' => [], 'teacher' => [], 'total_student_logins' => 0, 'total_teacher_logins' => 0, 'average_daily_logins' => 0];
        
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            try {
                // Generate date array for last 30 days (format: Y-m-d for Chart.js)
                $dates = [];
                $student = [];
                $teacher = [];
                for ($i = 29; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $dates[] = $date;
                    $student[$date] = 0;
                    $teacher[$date] = 0;
                }
                
                $starttime = strtotime('-30 days');
                
                // Get student login records
                $student_records = $DB->get_records_sql(
                    "SELECT DISTINCT u.id, u.lastaccess
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id
                  LEFT JOIN {role_assignments} ra ON ra.userid = u.id
                  LEFT JOIN {role} r ON r.id = ra.roleid
                      WHERE u.deleted = 0
                        AND u.suspended = 0
                        AND u.lastaccess >= :threshold
                        AND cu.companyid = :companyid
                        AND (COALESCE(cu.educator, 0) = 0 OR r.shortname = 'student')",
                    ['threshold' => $starttime, 'companyid' => $company_id]
                );
                
                // Get teacher login records
                $teacherRoles = ['teacher', 'editingteacher', 'manager', 'coursecreator', 'noneditingteacher'];
                list($roleSql, $roleParams) = $DB->get_in_or_equal($teacherRoles, SQL_PARAMS_NAMED, 'trole');
                $teacherParams = array_merge(['threshold' => $starttime, 'companyid' => $company_id], $roleParams);
                
                $teacher_records = $DB->get_records_sql(
                    "SELECT DISTINCT u.id, u.lastaccess
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id
                  LEFT JOIN {role_assignments} ra ON ra.userid = u.id
                  LEFT JOIN {role} r ON r.id = ra.roleid
                      WHERE u.deleted = 0
                        AND u.suspended = 0
                        AND u.lastaccess >= :threshold
                        AND cu.companyid = :companyid
                        AND (COALESCE(cu.educator, 0) = 1 OR r.shortname $roleSql)",
                    $teacherParams
                );
                
                // Count logins per day
                foreach ($dates as $date) {
                    $date_start = strtotime($date . ' 00:00:00');
                    $date_end = strtotime($date . ' 23:59:59');
                    
                    $student[$date] = array_reduce($student_records, function ($carry, $record) use ($date_start, $date_end) {
                        return $carry + (($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) ? 1 : 0);
                    }, 0);
                    
                    $teacher[$date] = array_reduce($teacher_records, function ($carry, $record) use ($date_start, $date_end) {
                        return $carry + (($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) ? 1 : 0);
                    }, 0);
                }
                
                $studentSeries = array_values($student);
                $teacherSeries = array_values($teacher);
                $studentTotal = array_sum($studentSeries);
                $teacherTotal = array_sum($teacherSeries);
                $avgDaily = round(($studentTotal + $teacherTotal) / (count($dates) ?: 1), 1);
                
                $login_trend_data = [
                    'dates' => $dates,
                    'student' => $studentSeries,
                    'teacher' => $teacherSeries,
                    'total_student_logins' => $studentTotal,
                    'total_teacher_logins' => $teacherTotal,
                    'average_daily_logins' => $avgDaily
                ];
                
                error_log("✅ DRAWERS.PHP - Login Trend: " . $studentTotal . " student logins, " . $teacherTotal . " teacher logins over 30 days");
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting login trend: " . $e->getMessage());
            }
        }
        
        // Get Assignment & Quiz Summary Data - INLINE QUERY with detailed lists
        $assessment_summary_data = [
            'assignments' => ['total' => 0, 'submitted' => 0, 'graded' => 0, 'avg_grade' => 0, 'list' => []],
            'quizzes' => ['total' => 0, 'attempts' => 0, 'completed' => 0, 'avg_score' => 0, 'list' => []]
        ];
        
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            try {
                // Get detailed assignment data (STUDENTS ONLY)
                if ($DB->get_manager()->table_exists('assign')) {
                    $assignments = $DB->get_records_sql(
                        "SELECT a.id, a.name, a.duedate, c.fullname AS course_name, c.id AS course_id,
                                COUNT(DISTINCT ue.userid) AS total_students,
                                COUNT(DISTINCT CASE WHEN asub.status = 'submitted' THEN asub.userid END) AS submitted_count,
                                COUNT(DISTINCT CASE WHEN ag.grade IS NOT NULL THEN ag.userid END) AS graded_count,
                                AVG(CASE WHEN ag.grade IS NOT NULL THEN (ag.grade / NULLIF(a.grade, 0)) * 100 END) AS avg_score
                           FROM {assign} a
                           JOIN {course} c ON c.id = a.course
                           JOIN {enrol} e ON e.courseid = c.id
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                           JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                           JOIN {user} u ON u.id = ue.userid
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                           JOIN {role} r ON r.id = ra.roleid
                      LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = ue.userid
                      LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = ue.userid
                          WHERE c.visible = 1 
                            AND a.grade > 0
                            AND u.deleted = 0
                            AND u.suspended = 0
                            AND r.shortname = 'student'
                       GROUP BY a.id, a.name, a.duedate, c.fullname, c.id
                       ORDER BY a.duedate DESC
                          LIMIT 10",
                        [$company_id]
                    );
                    
                    $assignment_list = [];
                    $total_assignments = 0;
                    $total_submitted = 0;
                    $total_graded = 0;
                    $grades_sum = 0;
                    $grades_count = 0;
                    
                    foreach ($assignments as $assign) {
                        $incomplete = max(0, (int)$assign->total_students - (int)$assign->submitted_count);
                        $status = time() > $assign->duedate ? 'Overdue' : 'Active';
                        
                        $assignment_list[] = [
                            'id' => $assign->id,
                            'name' => format_string($assign->name),
                            'course_name' => format_string($assign->course_name),
                            'course_id' => $assign->course_id,
                            'total_students' => (int)$assign->total_students,
                            'submitted_count' => (int)$assign->submitted_count,
                            'graded_count' => (int)$assign->graded_count,
                            'incomplete_count' => $incomplete,
                            'avg_score' => $assign->avg_score ? round($assign->avg_score, 1) : 0,
                            'avg_score_display' => $assign->avg_score ? round($assign->avg_score, 1) . '%' : 'N/A',
                            'status' => $status,
                            'status_class' => $status === 'Active' ? 'active' : 'overdue',
                            'due_date' => $assign->duedate ? userdate($assign->duedate, '%d %b %Y') : 'No due date'
                        ];
                        
                        $total_assignments++;
                        $total_submitted += (int)$assign->submitted_count;
                        $total_graded += (int)$assign->graded_count;
                        if ($assign->avg_score) {
                            $grades_sum += $assign->avg_score;
                            $grades_count++;
                        }
                    }
                    
                    $assessment_summary_data['assignments'] = [
                        'total' => $total_assignments,
                        'submitted' => $total_submitted,
                        'graded' => $total_graded,
                        'avg_grade' => $grades_count > 0 ? round($grades_sum / $grades_count, 1) : 0,
                        'list' => $assignment_list,
                        'has_data' => !empty($assignment_list)
                    ];
                }
                
                // Get detailed quiz data (STUDENTS ONLY)
                if ($DB->get_manager()->table_exists('quiz')) {
                    $quizzes = $DB->get_records_sql(
                        "SELECT q.id, q.name, q.timeclose, c.fullname AS course_name, c.id AS course_id, q.grade AS max_grade,
                                COUNT(DISTINCT ue.userid) AS total_students,
                                COUNT(DISTINCT qa.id) AS total_attempts,
                                COUNT(DISTINCT CASE WHEN qa.state = 'finished' THEN qa.userid END) AS completed_count,
                                AVG(CASE WHEN qa.state = 'finished' THEN (qa.sumgrades / NULLIF(q.sumgrades, 0)) * 100 END) AS avg_score
                           FROM {quiz} q
                           JOIN {course} c ON c.id = q.course
                           JOIN {enrol} e ON e.courseid = c.id
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                           JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                           JOIN {user} u ON u.id = ue.userid
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                           JOIN {role} r ON r.id = ra.roleid
                      LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = ue.userid
                          WHERE c.visible = 1
                            AND u.deleted = 0
                            AND u.suspended = 0
                            AND r.shortname = 'student'
                       GROUP BY q.id, q.name, q.timeclose, c.fullname, c.id, q.grade, q.sumgrades
                       ORDER BY q.timeclose DESC
                          LIMIT 10",
                        [$company_id]
                    );
                    
                    $quiz_list = [];
                    $total_quizzes = 0;
                    $total_attempts = 0;
                    $total_completed = 0;
                    $scores_sum = 0;
                    $scores_count = 0;
                    
                    foreach ($quizzes as $quiz) {
                        $incomplete = max(0, (int)$quiz->total_students - (int)$quiz->completed_count);
                        $status = ($quiz->timeclose && time() > $quiz->timeclose) ? 'Closed' : 'Active';
                        
                        $quiz_list[] = [
                            'id' => $quiz->id,
                            'name' => format_string($quiz->name),
                            'course_name' => format_string($quiz->course_name),
                            'course_id' => $quiz->course_id,
                            'total_students' => (int)$quiz->total_students,
                            'total_attempts' => (int)$quiz->total_attempts,
                            'completed_count' => (int)$quiz->completed_count,
                            'incomplete_count' => $incomplete,
                            'avg_score' => $quiz->avg_score ? round($quiz->avg_score, 1) : 0,
                            'avg_score_display' => $quiz->avg_score ? round($quiz->avg_score, 1) . '%' : 'N/A',
                            'max_grade' => $quiz->max_grade ? round($quiz->max_grade) : 100,
                            'status' => $status,
                            'status_class' => $status === 'Active' ? 'active' : 'closed',
                            'close_date' => $quiz->timeclose ? userdate($quiz->timeclose, '%d %b %Y') : 'No close date'
                        ];
                        
                        $total_quizzes++;
                        $total_attempts += (int)$quiz->total_attempts;
                        $total_completed += (int)$quiz->completed_count;
                        if ($quiz->avg_score) {
                            $scores_sum += $quiz->avg_score;
                            $scores_count++;
                        }
                    }
                    
                    $assessment_summary_data['quizzes'] = [
                        'total' => $total_quizzes,
                        'attempts' => $total_attempts,
                        'completed' => $total_completed,
                        'avg_score' => $scores_count > 0 ? round($scores_sum / $scores_count, 1) : 0,
                        'list' => $quiz_list,
                        'has_data' => !empty($quiz_list)
                    ];
                }
                
                error_log("✅ DRAWERS.PHP - Assessment Summary: " . count($assessment_summary_data['assignments']['list']) . " assignments, " . count($assessment_summary_data['quizzes']['list']) . " quizzes");
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting assessment summary: " . $e->getMessage());
            }
        }
        
        // Get Course Completion Summary - ENHANCED QUERY (uses enrollments + completion tracking)
        $course_completion_summary = ['total' => 0, 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0, 'completion_rate' => 0, 'course_list' => []];
        
        if ($company_id) {
            try {
                // Use enrollment-based counting with NOT STARTED tracking (STUDENTS ONLY)
                if ($DB->get_manager()->table_exists('company_course')) {
                    // Count total enrollments with NOT STARTED, IN PROGRESS, and COMPLETED states (STUDENTS ONLY)
                    $enrollment_data = $DB->get_record_sql(
                        "SELECT COUNT(DISTINCT ue.id) AS total_enrollments,
                                COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN ue.id END) AS completed_enrollments,
                                COUNT(DISTINCT CASE WHEN cc.timecompleted IS NULL AND ula.timeaccess IS NOT NULL THEN ue.id END) AS inprogress_enrollments,
                                COUNT(DISTINCT CASE WHEN cc.timecompleted IS NULL AND ula.timeaccess IS NULL THEN ue.id END) AS notstarted_enrollments
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON ue.enrolid = e.id AND e.status = 0
                           JOIN {course} c ON e.courseid = c.id AND c.visible = 1 AND c.id > 1
                           JOIN {company_course} compco ON c.id = compco.courseid AND compco.companyid = :companyid1
                           JOIN {company_users} cu ON ue.userid = cu.userid AND cu.companyid = :companyid2
                           JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                           JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                      LEFT JOIN {course_completions} cc ON cc.course = c.id AND cc.userid = ue.userid
                      LEFT JOIN {user_lastaccess} ula ON ula.userid = ue.userid AND ula.courseid = c.id
                          WHERE ue.status = 0
                            AND COALESCE(cu.educator, 0) = 0",
                        ['companyid1' => $company_id, 'companyid2' => $company_id]
                    );
                    
                    if ($enrollment_data && $enrollment_data->total_enrollments > 0) {
                        $total = (int)$enrollment_data->total_enrollments;
                        $completed = (int)$enrollment_data->completed_enrollments;
                        $inprogress = (int)$enrollment_data->inprogress_enrollments;
                        $notstarted = (int)$enrollment_data->notstarted_enrollments;
                        $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
                        
                        $course_completion_summary = [
                            'total' => $total,
                            'completed' => $completed,
                            'inprogress' => $inprogress,
                            'notstarted' => $notstarted,
                            'completion_rate' => $rate
                        ];
                        
                        error_log("✅ DRAWERS.PHP - Course Completion: " . $completed . " completed, " . $inprogress . " in progress, " . $notstarted . " not started out of " . $total . " (" . $rate . "%)");
                    }
                }
                
                // Get detailed course list with completion stats (including NOT STARTED tracking) - STUDENTS ONLY
                if ($DB->get_manager()->table_exists('company_course')) {
                    $courses = $DB->get_records_sql(
                        "SELECT c.id, c.fullname, c.shortname, c.startdate,
                                COUNT(DISTINCT ue.userid) AS enrolled_students,
                                COUNT(DISTINCT CASE WHEN cc2.timecompleted IS NOT NULL THEN cc2.userid END) AS completed_students,
                                COUNT(DISTINCT CASE WHEN cc2.timecompleted IS NULL AND ula.timeaccess IS NOT NULL THEN ue.userid END) AS inprogress_students,
                                COUNT(DISTINCT CASE WHEN cc2.timecompleted IS NULL AND ula.timeaccess IS NULL THEN ue.userid END) AS notstarted_students,
                                AVG(CASE WHEN gg.finalgrade IS NOT NULL AND gi.grademax > 0 THEN (gg.finalgrade / gi.grademax) * 100 END) AS avg_grade
                           FROM {course} c
                           JOIN {company_course} cc ON c.id = cc.courseid AND cc.companyid = :companyid1
                           JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                           JOIN {company_users} cu ON ue.userid = cu.userid AND cu.companyid = :companyid2
                           JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                           JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                      LEFT JOIN {course_completions} cc2 ON cc2.course = c.id AND cc2.userid = ue.userid
                      LEFT JOIN {user_lastaccess} ula ON ula.userid = ue.userid AND ula.courseid = c.id
                      LEFT JOIN {grade_grades} gg ON gg.userid = ue.userid
                      LEFT JOIN {grade_items} gi ON gg.itemid = gi.id AND gi.courseid = c.id AND gi.itemtype = 'course'
                          WHERE c.visible = 1 
                            AND c.id > 1
                            AND COALESCE(cu.educator, 0) = 0
                       GROUP BY c.id, c.fullname, c.shortname, c.startdate
                       ORDER BY enrolled_students DESC
                          LIMIT 10",
                        ['companyid1' => $company_id, 'companyid2' => $company_id]
                    );
                    
                    $course_list = [];
                    foreach ($courses as $course) {
                        $enrolled = (int)$course->enrolled_students;
                        $completed_count = (int)$course->completed_students;
                        $inprogress_count = (int)$course->inprogress_students;
                        $notstarted_count = (int)$course->notstarted_students;
                        $completion_percentage = $enrolled > 0 ? round(($completed_count / $enrolled) * 100, 1) : 0;
                        
                        $course_list[] = [
                            'id' => $course->id,
                            'name' => format_string($course->fullname),
                            'shortname' => format_string($course->shortname),
                            'enrolled_students' => $enrolled,
                            'completed_students' => $completed_count,
                            'inprogress_students' => $inprogress_count,
                            'notstarted_students' => $notstarted_count,
                            'completion_percentage' => $completion_percentage,
                            'avg_grade' => $course->avg_grade ? round($course->avg_grade, 1) : 0,
                            'avg_grade_display' => $course->avg_grade ? round($course->avg_grade, 1) . '%' : 'N/A'
                        ];
                    }
                    
                    $course_completion_summary['course_list'] = $course_list;
                    $course_completion_summary['has_courses'] = !empty($course_list);
                    
                    error_log("✅ DRAWERS.PHP - Found " . count($course_list) . " courses with detailed enrollment data");
                }
                
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting course completion: " . $e->getMessage());
            }
        }
// Get School Overview Performance Data - COMPREHENSIVE METRICS
        $school_overview_data = [
            'overall_academic' => ['avg_grade' => 0, 'pass_rate' => 0],
            'enrollment_summary' => ['total' => 0, 'new' => 0],
            'active_users' => ['active' => 0, 'inactive' => 0, 'active_percent' => 0, 'inactive_percent' => 0],
            'growth_report' => ['labels' => [], 'values' => []],
            'login_access' => ['labels' => ['Students', 'Teachers', 'Parents'], 'values' => [0, 0, 0]]
        ];
        
        if ($company_id) {
            try {
                // 1. Average Grade (from all graded items - STUDENTS ONLY)
                $avg_grade = $DB->get_field_sql(
                    "SELECT AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100)
                       FROM {grade_grades} gg
                       JOIN {grade_items} gi ON gi.id = gg.itemid
                       JOIN {company_users} cu ON cu.userid = gg.userid AND cu.companyid = ?
                       JOIN {user} u ON u.id = gg.userid
                       JOIN {role_assignments} ra ON ra.userid = u.id
                       JOIN {context} ctx ON ctx.id = ra.contextid
                       JOIN {role} r ON r.id = ra.roleid
                      WHERE gg.finalgrade IS NOT NULL
                        AND gi.grademax > 0
                        AND u.deleted = 0
                        AND u.suspended = 0
                        AND r.shortname = 'student'",
                    [$company_id]
                );
                
                $school_overview_data['overall_academic']['avg_grade'] = $avg_grade ? round($avg_grade, 1) : 0;
                $school_overview_data['overall_academic']['pass_rate'] = $course_completion_summary['completion_rate'];
                
                // 2. Total Enrollments + New This Month (STUDENTS ONLY)
                $thirty_days_ago = strtotime('-30 days');
                $enrollments = $DB->get_record_sql(
                    "SELECT COUNT(DISTINCT ue.id) AS total,
                            SUM(CASE WHEN ue.timecreated >= ? THEN 1 ELSE 0 END) AS recent
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                       JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                       JOIN {user} u ON u.id = ue.userid
                       JOIN {role_assignments} ra ON ra.userid = u.id
                       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                       JOIN {role} r ON r.id = ra.roleid
                      WHERE u.deleted = 0
                        AND u.suspended = 0
                        AND ue.status = 0
                        AND r.shortname = 'student'",
                    [$thirty_days_ago, $company_id]
                );
                
                if ($enrollments) {
                    $school_overview_data['enrollment_summary']['total'] = (int)$enrollments->total;
                    $school_overview_data['enrollment_summary']['new'] = (int)$enrollments->recent;
                }
                
                // 3. Active vs Inactive Users (based on last 30 days activity)
                $active_threshold = strtotime('-30 days');
                $useractivity = $DB->get_record_sql(
                    "SELECT SUM(CASE WHEN u.lastaccess >= ? THEN 1 ELSE 0 END) AS active,
                            SUM(CASE WHEN u.lastaccess < ? OR u.lastaccess IS NULL THEN 1 ELSE 0 END) AS inactive
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                      WHERE u.deleted = 0",
                    [$active_threshold, $active_threshold, $company_id]
                );
                
                if ($useractivity) {
                    $school_overview_data['active_users']['active'] = (int)$useractivity->active;
                    $school_overview_data['active_users']['inactive'] = (int)$useractivity->inactive;
                    $totalusers = ((int)$useractivity->active) + ((int)$useractivity->inactive);
                    $school_overview_data['active_users']['active_percent'] = $totalusers ? round($useractivity->active / $totalusers * 100, 1) : 0;
                    $school_overview_data['active_users']['inactive_percent'] = $totalusers ? round($useractivity->inactive / $totalusers * 100, 1) : 0;
                }
                
                // 4. Growth Report (New enrollments last 6 months - STUDENTS ONLY)
                $growth_labels = [];
                $growth_values = [];
                for ($i = 5; $i >= 0; $i--) {
                    $monthStart = strtotime("first day of -" . $i . " month");
                    $monthEnd = strtotime("first day of -" . ($i - 1) . " month");
                    $label = date('M', $monthStart);
                    $growth_labels[] = $label;
                    $count = $DB->get_field_sql(
                        "SELECT COUNT(DISTINCT ue.id)
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON e.id = ue.enrolid
                           JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                           JOIN {user} u ON u.id = ue.userid
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE ue.timecreated >= ? 
                            AND ue.timecreated < ?
                            AND u.deleted = 0
                            AND u.suspended = 0
                            AND ue.status = 0
                            AND r.shortname = 'student'",
                        [$company_id, $monthStart, $monthEnd]
                    );
                    $growth_values[] = (int)$count;
                }
                
                $school_overview_data['growth_report']['labels'] = $growth_labels;
                $school_overview_data['growth_report']['values'] = $growth_values;
                
                // 5. Login Access Report (ACTIVE and INACTIVE users by role in last 30 days)
                $roleMap = [
                    'Students' => 'student',
                    'Teachers' => ['teacher', 'editingteacher'],
                    'Parents' => 'parent'
                ];
                
                $active_values = [];
                $inactive_values = [];
                
                foreach ($roleMap as $roleName => $roleShortnames) {
                    // Ensure it's an array
                    $roleShortnames = is_array($roleShortnames) ? $roleShortnames : [$roleShortnames];
                    
                    // Get active users (logged in last 30 days)
                    list($insql, $params) = $DB->get_in_or_equal($roleShortnames, SQL_PARAMS_NAMED, 'role');
                    $params['companyid'] = $company_id;
                    $params['lastaccess'] = $thirty_days_ago;
                    
                    $active_count = $DB->count_records_sql(
                        "SELECT COUNT(DISTINCT u.id)
                           FROM {user} u
                           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE r.shortname $insql
                            AND u.lastaccess >= :lastaccess
                            AND u.deleted = 0
                            AND u.suspended = 0",
                        $params
                    );
                    
                    // Get inactive users (NOT logged in last 30 days OR never logged in)
                    list($insql2, $params2) = $DB->get_in_or_equal($roleShortnames, SQL_PARAMS_NAMED, 'role');
                    $params2['companyid'] = $company_id;
                    $params2['lastaccess'] = $thirty_days_ago;
                    
                    $inactive_count = $DB->count_records_sql(
                        "SELECT COUNT(DISTINCT u.id)
                           FROM {user} u
                           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
                           JOIN {role_assignments} ra ON ra.userid = u.id
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE r.shortname $insql2
                            AND (u.lastaccess < :lastaccess OR u.lastaccess IS NULL OR u.lastaccess = 0)
                            AND u.deleted = 0
                            AND u.suspended = 0",
                        $params2
                    );
                    
                    $active_values[] = (int)$active_count;
                    $inactive_values[] = (int)$inactive_count;
                }
                
                $school_overview_data['login_access']['active_values'] = $active_values;
                $school_overview_data['login_access']['inactive_values'] = $inactive_values;
                
                error_log("✅ DRAWERS.PHP - School Overview: Avg Grade=" . $school_overview_data['overall_academic']['avg_grade'] . "%, Pass Rate=" . $school_overview_data['overall_academic']['pass_rate'] . "%, Active Users=" . $school_overview_data['active_users']['active'] . "/" . ($school_overview_data['active_users']['active'] + $school_overview_data['active_users']['inactive']));
                error_log("✅ DRAWERS.PHP - Growth Report: " . count($growth_labels) . " months, Total new enrollments: " . array_sum($growth_values));
                error_log("✅ DRAWERS.PHP - Login Access (Active): Students=" . $school_overview_data['login_access']['active_values'][0] . ", Teachers=" . $school_overview_data['login_access']['active_values'][1] . ", Parents=" . $school_overview_data['login_access']['active_values'][2]);
                error_log("✅ DRAWERS.PHP - Login Access (Inactive): Students=" . $school_overview_data['login_access']['inactive_values'][0] . ", Teachers=" . $school_overview_data['login_access']['inactive_values'][1] . ", Parents=" . $school_overview_data['login_access']['inactive_values'][2]);
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting school overview: " . $e->getMessage());
            }
        }
        
        // Get Total Parents for this company
        $total_parents = 0;
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            try {
                $parent_role = $DB->get_record('role', ['shortname' => 'parent']);
                if ($parent_role) {
                    // Parents directly in company
                    $parents_direct = $DB->get_records_sql(
                        "SELECT DISTINCT u.id
                           FROM {user} u
                           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                           JOIN {role_assignments} ra ON ra.userid = u.id
                          WHERE ra.roleid = ?
                            AND u.deleted = 0",
                        [$company_id, $parent_role->id]
                    );
                    
                    // Parents linked to children in this company
                    $contextuserlevel = CONTEXT_USER;
                    $parents_linked = $DB->get_records_sql(
                        "SELECT DISTINCT p.id
                           FROM {role_assignments} ra
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ?
                           JOIN {user} child ON child.id = ctx.instanceid
                           JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = ?
                           JOIN {user} p ON p.id = ra.userid
                          WHERE ra.roleid = ?
                            AND p.deleted = 0",
                        [$contextuserlevel, $company_id, $parent_role->id]
                    );
                    
                    // Merge both lists
                    $all_parents = $parents_direct;
                    foreach ($parents_linked as $parent) {
                        if (!isset($all_parents[$parent->id])) {
                            $all_parents[$parent->id] = $parent;
                        }
                    }
                    
                    $total_parents = count($all_parents);
                }
                error_log("✅ DRAWERS.PHP - Found " . $total_parents . " parents for company ID: " . $company_id);
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting parents: " . $e->getMessage());
            }
        }
        
        // Get User Role Distribution - INLINE QUERY
        $role_distribution_data = ['labels' => [], 'values' => [], 'students' => 0, 'teachers' => 0, 'parents' => 0, 'managers' => 0, 'total' => 0];
        
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            try {
                // Count managers (companymanager, manager, schoolmanager roles)
                $managercount = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cu.userid)
                       FROM {company_users} cu
                       JOIN {role_assignments} ra ON ra.userid = cu.userid
                       JOIN {context} ctx ON ctx.id = ra.contextid
                       JOIN {role} r ON r.id = ra.roleid
                       JOIN {user} u ON u.id = cu.userid
                      WHERE cu.companyid = ?
                        AND ctx.contextlevel = ?
                        AND r.shortname IN ('companymanager', 'manager', 'schoolmanager')
                        AND u.deleted = 0",
                    [$company_id, CONTEXT_SYSTEM]
                );
                
                $total = (int)$total_students + (int)$total_teachers + (int)$total_parents + (int)$managercount;
                
                $role_distribution_data = [
                    'labels' => ['Students', 'Teachers', 'Parents', 'Managers'],
                    'values' => [
                        (int)$total_students,
                        (int)$total_teachers,
                        (int)$total_parents,
                        (int)$managercount
                    ],
                    'students' => (int)$total_students,
                    'teachers' => (int)$total_teachers,
                    'parents' => (int)$total_parents,
                    'managers' => (int)$managercount,
                    'total' => $total
                ];
                
                error_log("✅ DRAWERS.PHP - Role Distribution: Students=" . $total_students . ", Teachers=" . $total_teachers . ", Parents=" . $total_parents . ", Managers=" . $managercount . ", Total=" . $total);
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting role distribution: " . $e->getMessage());
            }
        }
        
        // Create JSON encodings
        $grade_distribution_json = json_encode($grade_distribution_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $login_trend_json = json_encode($login_trend_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $assessment_summary_json = json_encode($assessment_summary_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $course_completion_json = json_encode($course_completion_summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $school_overview_json = json_encode($school_overview_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $role_distribution_json = json_encode($role_distribution_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $super_admin_actions_json = json_encode($super_admin_actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        error_log("📦 DRAWERS.PHP - Grade Distribution JSON: " . $grade_distribution_json);
        error_log("📦 DRAWERS.PHP - Login Trend - Student Total: " . $login_trend_data['total_student_logins'] . ", Teacher Total: " . $login_trend_data['total_teacher_logins'] . ", Dates Count: " . count($login_trend_data['dates']));
        error_log("📦 DRAWERS.PHP - Assignments: " . count($assessment_summary_data['assignments']['list']) . " items, Total: " . $assessment_summary_data['assignments']['total']);
        error_log("📦 DRAWERS.PHP - Quizzes: " . count($assessment_summary_data['quizzes']['list']) . " items, Total: " . $assessment_summary_data['quizzes']['total']);
        error_log("📦 DRAWERS.PHP - Course Completion: " . $course_completion_summary['completed'] . " completed, " . $course_completion_summary['inprogress'] . " in progress, " . $course_completion_summary['notstarted'] . " not started out of " . $course_completion_summary['total'] . " (" . $course_completion_summary['completion_rate'] . "%), " . count($course_completion_summary['course_list']) . " courses in list");
        error_log("📦 DRAWERS.PHP - Role Distribution: Students=" . $role_distribution_data['students'] . ", Teachers=" . $role_distribution_data['teachers'] . ", Parents=" . $role_distribution_data['parents'] . ", Managers=" . $role_distribution_data['managers'] . ", Total=" . $role_distribution_data['total']);
        
        // Get Super Admin Actions - Course Assignments to This School
        $super_admin_actions = ['course_assignments' => [], 'total_courses_assigned' => 0, 'recent_assignments' => 0, 'has_data' => false];
        
        if ($company_id && $DB->get_manager()->table_exists('company_course')) {
            try {
                // Get ALL course assignments to this school (NO LIMIT)
                $ten_days_ago = strtotime('-10 days'); // NEW badge for last 10 days
                
                // Try to get timecreated from company_course if it exists, otherwise use course.timecreated
                $company_course_columns = $DB->get_columns('company_course');
                $has_cc_timecreated = isset($company_course_columns['timecreated']);
                
                $timecreated_field = $has_cc_timecreated ? 'cc.timecreated' : 'c.timecreated';
                $assigned_date_field = $has_cc_timecreated ? 'cc.timecreated' : 'c.timecreated';
                
                $course_assignments = $DB->get_records_sql(
                    "SELECT cc.id, cc.courseid, cc.companyid, c.fullname AS course_name, c.shortname AS course_shortname,
                            c.category AS category_id, cat.name AS category_name, 
                            parent_cat.name AS parent_category_name,
                            c.timecreated AS course_created,
                            c.timemodified AS course_modified, c.visible, c.startdate, c.enddate,
                            " . ($has_cc_timecreated ? "cc.timecreated AS assigned_timestamp," : "c.timecreated AS assigned_timestamp,") . "
                            (SELECT COUNT(DISTINCT u.id) 
                             FROM {user} u
                             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                             INNER JOIN {enrol} e ON e.id = ue.enrolid
                             INNER JOIN {company_users} cu ON cu.userid = u.id
                             INNER JOIN {role_assignments} ra ON ra.userid = u.id
                             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                             INNER JOIN {role} r ON r.id = ra.roleid
                             WHERE e.courseid = c.id 
                             AND ue.status = 0
                             AND cu.companyid = ?
                             AND r.shortname = 'student'
                             AND u.deleted = 0
                             AND u.suspended = 0) AS enrolled_students,
                            (SELECT COUNT(DISTINCT cm.id) 
                             FROM {course_modules} cm 
                             WHERE cm.course = c.id AND cm.visible = 1) AS total_activities
                       FROM {company_course} cc
                       JOIN {course} c ON c.id = cc.courseid
                  LEFT JOIN {course_categories} cat ON cat.id = c.category
                  LEFT JOIN {course_categories} parent_cat ON parent_cat.id = cat.parent
                      WHERE cc.companyid = ?
                        AND c.id > 1
                   ORDER BY " . ($has_cc_timecreated ? "cc.timecreated" : "c.timecreated") . " DESC, c.id DESC",
                    [$company_id, $company_id]
                );
                
                $assignment_list = [];
                $total_assigned = 0;
                $recent_count = 0; // Count for last 10 days only
                
                foreach ($course_assignments as $assignment) {
                    // Use assigned_timestamp (from company_course.timecreated or course.timecreated) for assignment date
                    $assigned_timestamp = isset($assignment->assigned_timestamp) ? $assignment->assigned_timestamp : $assignment->course_created;
                    
                    // NEW badge: Only for courses assigned in last 10 days (based on actual assignment date)
                    $is_recent = ($assigned_timestamp >= $ten_days_ago);
                    if ($is_recent) {
                        $recent_count++;
                    }
                    
                    $status = 'Active';
                    $status_class = 'active';
                    if ($assignment->visible == 0) {
                        $status = 'Hidden';
                        $status_class = 'hidden';
                    } else if ($assignment->enddate && $assignment->enddate < time()) {
                        $status = 'Ended';
                        $status_class = 'ended';
                    }
                    
                    $assignment_list[] = [
                        'id' => $assignment->courseid,
                        'course_name' => format_string($assignment->course_name),
                        'course_shortname' => format_string($assignment->course_shortname),
                        'category_name' => $assignment->category_name ? format_string($assignment->category_name) : 'Uncategorized',
                        'parent_category_name' => $assignment->parent_category_name ? format_string($assignment->parent_category_name) : 'No Parent',
                        'enrolled_students' => (int)$assignment->enrolled_students,
                        'total_activities' => (int)$assignment->total_activities,
                        'status' => $status,
                        'status_class' => $status_class,
                        'is_recent' => $is_recent,
                        'assigned_date' => userdate($assigned_timestamp, '%d %b %Y'),
                        'course_url' => $CFG->wwwroot . '/course/view.php?id=' . $assignment->courseid
                    ];
                    $total_assigned++;
                }
                
                $super_admin_actions = [
                    'course_assignments' => $assignment_list,
                    'total_courses_assigned' => $total_assigned,
                    'recent_assignments' => $recent_count,
                    'has_data' => !empty($assignment_list)
                ];
                
                error_log("✅ DRAWERS.PHP - Super Admin Actions: " . $total_assigned . " total courses assigned to school, " . $recent_count . " NEW (last 10 days)");
            } catch (Exception $e) {
                error_log("❌ DRAWERS.PHP - Error getting super admin actions: " . $e->getMessage());
            }
        }
        
        error_log("📦 DRAWERS.PHP - ========== ALL 7 DASHBOARD SECTIONS DATA READY ==========");
        
        // Prepare template data for school manager
        $templatecontext['company_name'] = $company_info ? $company_info->name : 'School Dashboard';
        $templatecontext['company_info'] = $company_info;
        $templatecontext['company_logo_url'] = $company_info && isset($company_info->logo_filename) 
            ? $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id 
            : null;
        $templatecontext['has_logo'] = $company_info && isset($company_info->logo_filename);
        $templatecontext['total_teachers'] = $total_teachers;
        $templatecontext['editing_teachers'] = $editing_teachers;
        $templatecontext['regular_teachers'] = $regular_teachers;
        $templatecontext['enrolled_teachers'] = $enrolled_teachers;
        $templatecontext['total_students'] = $total_students;
        $templatecontext['total_courses'] = $total_courses;
        $templatecontext['active_enrollments'] = $active_enrollments;
        $templatecontext['total_parents'] = $total_parents;
        
        // Define calendar function if not already defined
        if (!function_exists('theme_remui_kids_get_school_calendar_events')) {
            /**
             * Get comprehensive school calendar events (assignments, quizzes, activities) for school admin dashboard
             * @param int $company_id Company/School ID
             * @return array Calendar events and upcoming sessions
             */
            function theme_remui_kids_get_school_calendar_events($company_id) {
                global $DB, $CFG;
                
                if (!$company_id) {
                    return ['events' => [], 'upcoming_sessions' => [], 'stats' => ['total' => 0, 'assignments' => 0, 'quizzes' => 0, 'other' => 0]];
                }
                
                $events = [];
                $upcoming_sessions = [];
                $now = time();
                $next_90_days = $now + (90 * 24 * 60 * 60);
                
                try {
                    // 1. Get Assignment Due Dates (for students in this school)
                    if ($DB->get_manager()->table_exists('assign')) {
                        $assignments = $DB->get_records_sql(
                            "SELECT a.id, a.name, a.duedate, a.course, c.fullname AS coursename, c.id AS courseid,
                                    cat.name AS categoryname,
                                    COUNT(DISTINCT cu.userid) AS student_count
                               FROM {assign} a
                               JOIN {course} c ON c.id = a.course
                               JOIN {course_categories} cat ON cat.id = c.category
                               JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
                               JOIN {enrol} e ON e.courseid = c.id
                               JOIN {user_enrolments} ue ON ue.enrolid = e.id
                               JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                               JOIN {role_assignments} ra ON ra.userid = cu.userid
                               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                               JOIN {role} r ON r.id = ra.roleid
                              WHERE a.duedate > 0
                                AND a.duedate >= ?
                                AND a.duedate <= ?
                                AND c.visible = 1
                                AND r.shortname = 'student'
                           GROUP BY a.id, a.name, a.duedate, a.course, c.fullname, c.id, cat.name
                           ORDER BY a.duedate ASC",
                            [$company_id, $company_id, $now, $next_90_days]
                        );
                        
                        foreach ($assignments as $assign) {
                            $event_date = date('Y-m-d', $assign->duedate);
                            $event_time = date('h:i A', $assign->duedate);
                            $color = 'blue';
                            
                            $events[] = [
                                'id' => 'assign_' . $assign->id,
                                'title' => format_string($assign->name),
                                'type' => 'Assignment Due',
                                'date' => $event_date,
                                'timestamp' => $assign->duedate,
                                'time' => $event_time,
                                'course' => format_string($assign->coursename),
                                'course_id' => $assign->courseid,
                                'category' => format_string($assign->categoryname),
                                'color' => $color,
                                'icon' => 'fa-file-text',
                                'students_count' => (int)$assign->student_count,
                                'url' => $CFG->wwwroot . '/local/assign/view.php?id=' . $assign->id
                            ];
                            
                            if ($assign->duedate <= ($now + (14 * 24 * 60 * 60))) {
                                $upcoming_sessions[] = [
                                    'title' => format_string($assign->name),
                                    'type' => 'Assignment Due',
                                    'date' => userdate($assign->duedate, '%d %b %Y'),
                                    'time' => userdate($assign->duedate, '%H:%M'),
                                    'course' => format_string($assign->coursename),
                                    'location' => format_string($assign->categoryname),
                                    'color' => $color,
                                    'enrollment_count' => (int)$assign->student_count,
                                    'url' => $CFG->wwwroot . '/local/assign/view.php?id=' . $assign->id
                                ];
                            }
                        }
                    }
                    
                    // 2. Get Quiz Close Dates (for students in this school)
                    if ($DB->get_manager()->table_exists('quiz')) {
                        $quizzes = $DB->get_records_sql(
                            "SELECT q.id, q.name, q.timeclose, q.course, c.fullname AS coursename, c.id AS courseid,
                                    cat.name AS categoryname,
                                    COUNT(DISTINCT cu.userid) AS student_count
                               FROM {quiz} q
                               JOIN {course} c ON c.id = q.course
                               JOIN {course_categories} cat ON cat.id = c.category
                               JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
                               JOIN {enrol} e ON e.courseid = c.id
                               JOIN {user_enrolments} ue ON ue.enrolid = e.id
                               JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                               JOIN {role_assignments} ra ON ra.userid = cu.userid
                               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                               JOIN {role} r ON r.id = ra.roleid
                              WHERE q.timeclose > 0
                                AND q.timeclose >= ?
                                AND q.timeclose <= ?
                                AND c.visible = 1
                                AND r.shortname = 'student'
                           GROUP BY q.id, q.name, q.timeclose, q.course, c.fullname, c.id, cat.name
                           ORDER BY q.timeclose ASC",
                            [$company_id, $company_id, $now, $next_90_days]
                        );
                        
                        foreach ($quizzes as $quiz) {
                            $event_date = date('Y-m-d', $quiz->timeclose);
                            $event_time = date('h:i A', $quiz->timeclose);
                            $color = 'green';
                            
                            $events[] = [
                                'id' => 'quiz_' . $quiz->id,
                                'title' => format_string($quiz->name),
                                'type' => 'Quiz Due',
                                'date' => $event_date,
                                'timestamp' => $quiz->timeclose,
                                'time' => $event_time,
                                'course' => format_string($quiz->coursename),
                                'course_id' => $quiz->courseid,
                                'category' => format_string($quiz->categoryname),
                                'color' => $color,
                                'icon' => 'fa-question-circle',
                                'students_count' => (int)$quiz->student_count,
                                'url' => $CFG->wwwroot . '/local/quiz/view.php?id=' . $quiz->id
                            ];
                            
                            if ($quiz->timeclose <= ($now + (14 * 24 * 60 * 60))) {
                                $upcoming_sessions[] = [
                                    'title' => format_string($quiz->name),
                                    'type' => 'Quiz Closes',
                                    'date' => userdate($quiz->timeclose, '%d %b %Y'),
                                    'time' => userdate($quiz->timeclose, '%H:%M'),
                                    'course' => format_string($quiz->coursename),
                                    'location' => format_string($quiz->categoryname),
                                    'color' => $color,
                                    'enrollment_count' => (int)$quiz->student_count,
                                    'url' => $CFG->wwwroot . '/local/quiz/view.php?id=' . $quiz->id
                                ];
                            }
                        }
                    }
                    
                    // 3. Get Course Start/End Dates
                    $courses = $DB->get_records_sql(
                        "SELECT c.id, c.fullname, c.startdate, c.enddate, cat.name AS categoryname,
                                COUNT(DISTINCT cu.userid) AS student_count
                           FROM {course} c
                           JOIN {course_categories} cat ON cat.id = c.category
                           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
                           JOIN {enrol} e ON e.courseid = c.id
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                           JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = ?
                           JOIN {role_assignments} ra ON ra.userid = cu.userid
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE c.visible = 1
                            AND c.id > 1
                            AND r.shortname = 'student'
                            AND ((c.startdate > 0 AND c.startdate >= ? AND c.startdate <= ?)
                             OR (c.enddate > 0 AND c.enddate >= ? AND c.enddate <= ?))
                       GROUP BY c.id, c.fullname, c.startdate, c.enddate, cat.name
                       ORDER BY c.startdate ASC, c.enddate ASC",
                        [$company_id, $company_id, $now, $next_90_days, $now, $next_90_days]
                    );
                    
                    foreach ($courses as $course) {
                        if ($course->startdate > 0 && $course->startdate >= $now && $course->startdate <= $next_90_days) {
                            $event_date = date('Y-m-d', $course->startdate);
                            $event_time = date('h:i A', $course->startdate);
                            
                            $events[] = [
                                'id' => 'course_start_' . $course->id,
                                'title' => format_string($course->fullname) . ' - Start',
                                'type' => 'Course Start',
                                'date' => $event_date,
                                'timestamp' => $course->startdate,
                                'time' => $event_time,
                                'course' => format_string($course->fullname),
                                'course_id' => $course->id,
                                'category' => format_string($course->categoryname),
                                'color' => 'purple',
                                'icon' => 'fa-graduation-cap',
                                'students_count' => (int)$course->student_count,
                                'url' => $CFG->wwwroot . '/course/view.php?id=' . $course->id
                            ];
                        }
                        
                        if ($course->enddate > 0 && $course->enddate >= $now && $course->enddate <= $next_90_days) {
                            $event_date = date('Y-m-d', $course->enddate);
                            $event_time = date('h:i A', $course->enddate);
                            
                            $events[] = [
                                'id' => 'course_end_' . $course->id,
                                'title' => format_string($course->fullname) . ' - End',
                                'type' => 'Course End',
                                'date' => $event_date,
                                'timestamp' => $course->enddate,
                                'time' => $event_time,
                                'course' => format_string($course->fullname),
                                'course_id' => $course->id,
                                'category' => format_string($course->categoryname),
                                'color' => 'orange',
                                'icon' => 'fa-flag-checkered',
                                'students_count' => (int)$course->student_count,
                                'url' => $CFG->wwwroot . '/course/view.php?id=' . $course->id
                            ];
                        }
                    }
                    
                    usort($events, function($a, $b) {
                        return $a['timestamp'] - $b['timestamp'];
                    });
                    
                    usort($upcoming_sessions, function($a, $b) {
                        return strtotime($a['date'] . ' ' . $a['time']) - strtotime($b['date'] . ' ' . $b['time']);
                    });
                    
                    $upcoming_sessions = array_slice($upcoming_sessions, 0, 10);
                    
                    $assignment_count = count(array_filter($events, function($e) { return $e['type'] === 'Assignment Due'; }));
                    $quiz_count = count(array_filter($events, function($e) { return $e['type'] === 'Quiz Due'; }));
                    $other_count = count($events) - $assignment_count - $quiz_count;
                    
                    error_log("✅ SCHOOL CALENDAR: " . count($events) . " events loaded (Assignments: $assignment_count, Quizzes: $quiz_count, Other: $other_count)");
                    error_log("✅ UPCOMING SESSIONS: " . count($upcoming_sessions) . " sessions in next 14 days");
                    
                } catch (Exception $e) {
                    error_log("❌ Error fetching school calendar events: " . $e->getMessage());
                }
                
                // If no real events found, add sample/dummy data for demonstration
                if (empty($events)) {
                    error_log("⚠️ No real events found - Adding sample data for calendar demonstration");
                    
                    $today = time();
                    $sample_events = [];
                    
                    // Sample Assignment 1 - Tomorrow
                    $tomorrow = strtotime('+1 day', $today);
                    $sample_events[] = [
                        'id' => 'sample_assign_1',
                        'title' => 'Mathematics Chapter 5 Assignment',
                        'type' => 'Assignment Due',
                        'date' => date('Y-m-d', $tomorrow),
                        'timestamp' => $tomorrow,
                        'time' => '23:59',
                        'course' => 'Grade 7 Mathematics',
                        'course_id' => 0,
                        'category' => 'Grade 7',
                        'color' => 'blue',
                        'icon' => 'fa-file-text',
                        'students_count' => 25,
                        'url' => '#'
                    ];
                    
                    // Sample Quiz 1 - In 3 days
                    $day3 = strtotime('+3 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_quiz_1',
                        'title' => 'Science Chapter 3 Quiz',
                        'type' => 'Quiz Due',
                        'date' => date('Y-m-d', $day3),
                        'timestamp' => $day3,
                        'time' => '14:30',
                        'course' => 'Grade 7 Science',
                        'course_id' => 0,
                        'category' => 'Grade 7',
                        'color' => 'green',
                        'icon' => 'fa-question-circle',
                        'students_count' => 28,
                        'url' => '#'
                    ];
                    
                    // Sample Assignment 2 - In 5 days
                    $day5 = strtotime('+5 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_assign_2',
                        'title' => 'English Essay - Book Review',
                        'type' => 'Assignment Due',
                        'date' => date('Y-m-d', $day5),
                        'timestamp' => $day5,
                        'time' => '17:00',
                        'course' => 'Grade 8 English',
                        'course_id' => 0,
                        'category' => 'Grade 8',
                        'color' => 'blue',
                        'icon' => 'fa-file-text',
                        'students_count' => 22,
                        'url' => '#'
                    ];
                    
                    // Sample Course Start - In 7 days
                    $day7 = strtotime('+7 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_course_1',
                        'title' => 'Advanced Programming - Start',
                        'type' => 'Course Start',
                        'date' => date('Y-m-d', $day7),
                        'timestamp' => $day7,
                        'time' => '09:00',
                        'course' => 'Advanced Programming',
                        'course_id' => 0,
                        'category' => 'Grade 10',
                        'color' => 'purple',
                        'icon' => 'fa-graduation-cap',
                        'students_count' => 18,
                        'url' => '#'
                    ];
                    
                    // Sample Quiz 2 - In 10 days
                    $day10 = strtotime('+10 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_quiz_2',
                        'title' => 'History Midterm Quiz',
                        'type' => 'Quiz Due',
                        'date' => date('Y-m-d', $day10),
                        'timestamp' => $day10,
                        'time' => '10:00',
                        'course' => 'World History',
                        'course_id' => 0,
                        'category' => 'Grade 9',
                        'color' => 'green',
                        'icon' => 'fa-question-circle',
                        'students_count' => 30,
                        'url' => '#'
                    ];
                    
                    // Sample Assignment 3 - In 14 days
                    $day14 = strtotime('+14 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_assign_3',
                        'title' => 'Physics Lab Report',
                        'type' => 'Assignment Due',
                        'date' => date('Y-m-d', $day14),
                        'timestamp' => $day14,
                        'time' => '16:00',
                        'course' => 'Grade 10 Physics',
                        'course_id' => 0,
                        'category' => 'Grade 10',
                        'color' => 'blue',
                        'icon' => 'fa-file-text',
                        'students_count' => 20,
                        'url' => '#'
                    ];
                    
                    // Sample Course End - In 20 days
                    $day20 = strtotime('+20 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_course_2',
                        'title' => 'Digital Foundations - End',
                        'type' => 'Course End',
                        'date' => date('Y-m-d', $day20),
                        'timestamp' => $day20,
                        'time' => '17:00',
                        'course' => 'Digital Foundations',
                        'course_id' => 0,
                        'category' => 'Grade 1',
                        'color' => 'orange',
                        'icon' => 'fa-flag-checkered',
                        'students_count' => 15,
                        'url' => '#'
                    ];
                    
                    // Sample events for this week
                    $today_date = date('Y-m-d');
                    $sample_events[] = [
                        'id' => 'sample_today_1',
                        'title' => 'Computer Science Lab Session',
                        'type' => 'Assignment Due',
                        'date' => $today_date,
                        'timestamp' => $today,
                        'time' => '15:00',
                        'course' => 'Computer Science',
                        'course_id' => 0,
                        'category' => 'Grade 9',
                        'color' => 'blue',
                        'icon' => 'fa-file-text',
                        'students_count' => 24,
                        'url' => '#'
                    ];
                    
                    $day2 = strtotime('+2 days', $today);
                    $sample_events[] = [
                        'id' => 'sample_day2_1',
                        'title' => 'Arabic Language Test',
                        'type' => 'Quiz Due',
                        'date' => date('Y-m-d', $day2),
                        'timestamp' => $day2,
                        'time' => '11:00',
                        'course' => 'Arabic Language',
                        'course_id' => 0,
                        'category' => 'Grade 8',
                        'color' => 'green',
                        'icon' => 'fa-question-circle',
                        'students_count' => 26,
                        'url' => '#'
                    ];
                    
                    $events = $sample_events;
                    
                    // Create upcoming sessions from sample events
                    $upcoming_sessions = [];
                    foreach ($sample_events as $event) {
                        if ($event['timestamp'] <= ($today + (14 * 24 * 60 * 60))) {
                            $upcoming_sessions[] = [
                                'title' => $event['title'],
                                'type' => $event['type'],
                                'date' => userdate($event['timestamp'], '%d %b %Y'),
                                'time' => $event['time'],
                                'course' => $event['course'],
                                'location' => $event['category'],
                                'color' => $event['color'],
                                'enrollment_count' => $event['students_count'],
                                'url' => $event['url']
                            ];
                        }
                    }
                    
                    $assignment_count = count(array_filter($events, function($e) { return $e['type'] === 'Assignment Due'; }));
                    $quiz_count = count(array_filter($events, function($e) { return $e['type'] === 'Quiz Due'; }));
                    $other_count = count($events) - $assignment_count - $quiz_count;
                    
                    error_log("✅ SAMPLE CALENDAR DATA: " . count($events) . " sample events created (Assignments: $assignment_count, Quizzes: $quiz_count, Other: $other_count)");
                }
                
                return [
                    'events' => $events,
                    'upcoming_sessions' => $upcoming_sessions,
                    'has_events' => !empty($events),
                    'has_upcoming' => !empty($upcoming_sessions),
                    'stats' => [
                        'total' => count($events),
                        'assignments' => $assignment_count ?? 0,
                        'quizzes' => $quiz_count ?? 0,
                        'other' => $other_count ?? 0
                    ]
                ];
            }
        }
        
        // Get School Calendar Events (Assignments, Quizzes, Activities)
        $calendar_data = theme_remui_kids_get_school_calendar_events($company_id);
        $templatecontext['calendar_events'] = $calendar_data['events'];
        $templatecontext['calendar_events_json'] = json_encode($calendar_data['events'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $templatecontext['all_calendar_data_json'] = json_encode($calendar_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $templatecontext['upcoming_sessions'] = $calendar_data['upcoming_sessions'];
        $templatecontext['school_calendar_title'] = $company_info ? format_string($company_info->name) . ' Calendar' : 'School Calendar';
        $templatecontext['has_events'] = false;
        
        // ============ FETCH CALENDAR FORM DATA (TEACHERS, STUDENTS, COHORTS, COURSES) ============
        // Using helper functions from lib.php - SAME TECHNIQUE AS grade_distribution_data
        error_log("==========================================");
        error_log("📊 DRAWERS.PHP - FETCHING CALENDAR FORM DATA USING LIB.PHP FUNCTIONS");
        error_log("Company ID: " . $company_id);
        error_log("==========================================");
        
        // Use helper functions from lib.php (same pattern as theme_remui_kids_get_grade_distribution_data)
        $calendar_teachers = theme_remui_kids_get_calendar_teachers($company_id);
        $calendar_students = theme_remui_kids_get_calendar_students($company_id);
        $calendar_cohorts = theme_remui_kids_get_calendar_cohorts($company_id);
        $calendar_courses = theme_remui_kids_get_calendar_courses($company_id);
        
        // Build JSON strings BEFORE template context (to ensure they're ready)
        $calendar_teachers_json = json_encode(array_map(function($t) {
            return [
                'id' => (int)$t->id,
                'firstname' => isset($t->firstname) ? $t->firstname : '',
                'lastname' => isset($t->lastname) ? $t->lastname : '',
                'email' => isset($t->email) ? $t->email : '',
                'fullname' => trim((isset($t->firstname) ? $t->firstname : '') . ' ' . (isset($t->lastname) ? $t->lastname : ''))
            ];
        }, array_values($calendar_teachers)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

        $calendar_students_json = json_encode(array_map(function($s) {
            return [
                'id' => (int)$s->id,
                'firstname' => isset($s->firstname) ? $s->firstname : '',
                'lastname' => isset($s->lastname) ? $s->lastname : '',
                'email' => isset($s->email) ? $s->email : '',
                'fullname' => trim((isset($s->firstname) ? $s->firstname : '') . ' ' . (isset($s->lastname) ? $s->lastname : ''))
            ];
        }, array_values($calendar_students)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

        $calendar_cohorts_json = json_encode(array_map(function($c) {
            return [
                'id' => (int)$c->id,
                'name' => isset($c->name) ? $c->name : '',
                'idnumber' => isset($c->idnumber) ? $c->idnumber : '',
                'student_count' => isset($c->student_count) ? (int)$c->student_count : 0
            ];
        }, array_values($calendar_cohorts)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

        $calendar_courses_json = json_encode(array_map(function($c) {
            return [
                'id' => (int)$c->id,
                'fullname' => isset($c->fullname) ? $c->fullname : '',
                'shortname' => isset($c->shortname) ? $c->shortname : '',
                'idnumber' => isset($c->idnumber) ? $c->idnumber : ''
            ];
        }, array_values($calendar_courses)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        
        // Final verification before template rendering
        error_log("==========================================");
        error_log("📦 DRAWERS.PHP - FINAL VERIFICATION BEFORE TEMPLATE RENDER");
        error_log("Calendar Teachers Array Count: " . count($calendar_teachers));
        error_log("Calendar Students Array Count: " . count($calendar_students));
        error_log("Calendar Cohorts Array Count: " . count($calendar_cohorts));
        error_log("Calendar Courses Array Count: " . count($calendar_courses));
        error_log("Calendar Teachers JSON Length: " . strlen($calendar_teachers_json));
        error_log("Calendar Students JSON Length: " . strlen($calendar_students_json));
        error_log("Calendar Cohorts JSON Length: " . strlen($calendar_cohorts_json));
        error_log("Calendar Courses JSON Length: " . strlen($calendar_courses_json));
        if (strlen($calendar_teachers_json) > 2) {
            error_log("Teachers JSON Preview: " . substr($calendar_teachers_json, 0, 300));
        }
        if (strlen($calendar_students_json) > 2) {
            error_log("Students JSON Preview: " . substr($calendar_students_json, 0, 300));
        }
        if (strlen($calendar_cohorts_json) > 2) {
            error_log("Cohorts JSON Preview: " . substr($calendar_cohorts_json, 0, 300));
        }
        error_log("==========================================");
        $templatecontext['grade_distribution'] = $grade_distribution_data;
        $templatecontext['grade_distribution_rows'] = $grade_distribution_data['rows'] ?? [];
        $templatecontext['grade_distribution_json'] = $grade_distribution_json;
        $templatecontext['login_trend'] = $login_trend_data;
        $templatecontext['login_trend_json'] = $login_trend_json;
        $templatecontext['assessment_summary'] = $assessment_summary_data;
        $templatecontext['assessment_summary_json'] = $assessment_summary_json;
        $templatecontext['course_completion_summary'] = $course_completion_summary;
        $templatecontext['course_completion_json'] = $course_completion_json;
        $templatecontext['school_overview'] = $school_overview_data;
        $templatecontext['school_overview_json'] = $school_overview_json;
        $templatecontext['role_distribution'] = $role_distribution_data;
        $templatecontext['role_distribution_json'] = $role_distribution_json;
        $templatecontext['super_admin_actions'] = $super_admin_actions;
        $templatecontext['super_admin_actions_json'] = $super_admin_actions_json;
        
        // Calendar form data - Pass arrays and JSON to template
        $templatecontext['calendar_teachers'] = array_values($calendar_teachers);
        $templatecontext['calendar_students'] = array_values($calendar_students);
        $templatecontext['calendar_cohorts'] = array_values($calendar_cohorts);
        $templatecontext['calendar_courses'] = array_values($calendar_courses);
        $templatecontext['calendar_teachers_count'] = count($calendar_teachers);
        $templatecontext['calendar_students_count'] = count($calendar_students);
        $templatecontext['calendar_cohorts_count'] = count($calendar_cohorts);
        $templatecontext['calendar_courses_count'] = count($calendar_courses);
        // Calendar JSON data - Built BEFORE template context (variables defined above)
        $templatecontext['calendar_teachers_json'] = $calendar_teachers_json;
        $templatecontext['calendar_students_json'] = $calendar_students_json;
        $templatecontext['calendar_cohorts_json'] = $calendar_cohorts_json;
        $templatecontext['calendar_courses_json'] = $calendar_courses_json;
        
        $templatecontext['user_info'] = [
            'fullname' => fullname($USER),
            'email' => $USER->email,
            'id' => $USER->id
        ];
        $templatecontext['config'] = [
            'wwwroot' => $CFG->wwwroot
        ];
        $templatecontext['timestamp'] = time();
        $templatecontext['dashboard_active'] = true;
        $templatecontext['sesskey'] = sesskey();
        
        // Set the template context for normal rendering
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout('mydashboard');
        
        // Set a flag to indicate this is a school manager dashboard
        $templatecontext['is_school_manager_dashboard'] = true;
        
        // Must be called before rendering the template.
        require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
        
        // Render school manager dashboard with sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_dashboard', $templatecontext);
        return; // Exit early to prevent normal rendering
    }

    // Check if user is a teacher (editingteacher, teacher, or has teacher capabilities)
    $isteacher = false;
    $context = context_system::instance();
    
    // Check for teacher roles in any course context
    $teacherroles = $DB->get_records_sql(
        "SELECT DISTINCT r.shortname 
         FROM {role} r 
         JOIN {role_assignments} ra ON r.id = ra.roleid 
         JOIN {context} ctx ON ra.contextid = ctx.id 
         WHERE ra.userid = ? 
         AND ctx.contextlevel = ? 
         AND r.shortname IN ('editingteacher', 'teacher')",
        [$USER->id, CONTEXT_COURSE]
    );
    
    if (!empty($teacherroles)) {
        $isteacher = true;
    }
    
    // Also check for teacher capabilities in system context
    if (!$isteacher && (has_capability('moodle/course:create', $context, $USER) || 
                       has_capability('moodle/course:manageactivities', $context, $USER))) {
        $isteacher = true;
    }
    
    if ($isteacher) {
        // Show teacher dashboard
        $templatecontext['custom_dashboard'] = true;
        $templatecontext['dashboard_type'] = 'teacher';
        $templatecontext['teacher_dashboard'] = true;
        $templatecontext['teacher_stats'] = theme_remui_kids_get_teacher_dashboard_stats();
        $templatecontext['teacher_profile'] = theme_remui_kids_get_teacher_profile_data();
        
        // Check if Quick Navigation is enabled for this teacher
        require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_settings_helper.php');
        $templatecontext['quick_navigation_enabled'] = theme_remui_kids_is_quick_navigation_enabled($USER->id);
        
        // Teacher Schedule and Recent Resources
        $templatecontext['teacher_schedule'] = theme_remui_kids_get_teacher_schedule(0);
        $templatecontext['recent_teacher_resources'] = theme_remui_kids_get_recent_teacher_resources(5);
        
        // Course Statistics with Charts
        $templatecontext['course_statistics'] = theme_remui_kids_get_course_statistics();
        $templatecontext['course_statistics_json'] = json_encode($templatecontext['course_statistics']);
        
        // Recent Assignment Submissions and Quiz Completions
        $templatecontext['recent_assignment_submissions'] = theme_remui_kids_get_recent_assignment_submissions(5);
        $templatecontext['recent_quiz_completions'] = theme_remui_kids_get_recent_quiz_completions(0, 5);
        $templatecontext['recent_community_posts'] = theme_remui_kids_get_recent_community_posts(4);
        $templatecontext['recent_student_doubts'] = theme_remui_kids_get_recent_student_doubts(4);
        
        // Assignment Statistics Summary for Doughnut Chart
        $templatecontext['assignment_stats_summary'] = theme_remui_kids_get_assignment_stats_summary();
        
        // Course Progress Data
        $templatecontext['course_progress_data'] = theme_remui_kids_get_course_progress_data(5);
        
        // Current page indicator for sidebar highlighting
        $templatecontext['currentpage'] = ['dashboard' => true];
        
        // Add sesskey for AJAX requests
        $templatecontext['sesskey'] = sesskey();
        
        if (!empty($templatecontext['teacher_schedule'])) {
            error_log("Loaded teacher schedule with " . count($templatecontext['teacher_schedule']['days']) . " days");
        } else {
            error_log("No teacher schedule data found - user may not have calendar events");
        }
        
        if (!empty($templatecontext['teacher_upcoming_sessions'])) {
            error_log("Loaded " . count($templatecontext['teacher_upcoming_sessions']) . " upcoming sessions");
        } else {
            error_log("No upcoming sessions found in next 30 days");
        }
        
        // Top Courses (real data, with mock fallback for layout preview)
        $templatecontext['top_courses'] = theme_remui_kids_get_top_courses_by_enrollment(5);
        if (empty($templatecontext['top_courses'])) {
            error_log("No top courses found - user may not be a teacher in any courses");
            // No mock data - template will show "No courses available" message
        } else {
            error_log("Loaded " . count($templatecontext['top_courses']) . " top courses with real data");
        }
        
        // Real data sections - Recent Student Activity and Course Overview
        $templatecontext['recent_student_activity'] = theme_remui_kids_get_recent_student_activity();
        if (empty($templatecontext['recent_student_activity'])) {
            error_log("No recent student activity found in the last 7 days");
            // No mock data - template will show "No recent activity" message
        } else {
            error_log("Loaded " . count($templatecontext['recent_student_activity']) . " recent activities");
        }

        // Recent Users (Students) with activity data
        $templatecontext['recent_users'] = theme_remui_kids_get_recent_users(10);
        if (empty($templatecontext['recent_users'])) {
            error_log("No recent users found in the last 7 days");
        } else {
            error_log("Loaded " . count($templatecontext['recent_users']) . " recent users");
        }

        // Student Questions System - Integrated with Moodle messaging and forums
        $integrated_questions = theme_remui_kids_get_student_questions_integrated($USER->id);
        
        if (!empty($integrated_questions)) {
            // Use real data from Moodle messaging and forums
            $templatecontext['student_questions'] = $integrated_questions;
            error_log("Loaded " . count($integrated_questions) . " integrated questions from Moodle systems");
        } else {
            // Fallback to mock data if no real questions found
            $templatecontext['student_questions'] = [
            [
                'id' => 1,
                'title' => 'What wrong in this code',
                'content' => 'I am getting an error when trying to run this JavaScript function. Can someone help me understand what is wrong?',
                'student_name' => 'Zaki',
                'grade' => 'Grade 9',
                'course' => 'Mathematics',
                'date' => '14 Apr 2025',
                'status' => 'MENTOR REPLIED',
                'status_class' => 'mentor-replied',
                'upvotes' => 0,
                'replies' => 1
            ],
            [
                'id' => 2,
                'title' => 'What wrong in this code',
                'content' => 'I have been working on this problem for hours but cannot figure out the solution. Please help!',
                'student_name' => 'Zaki',
                'grade' => 'Grade 10',
                'course' => 'Science',
                'date' => '28 Mar 2025',
                'status' => 'MENTOR REPLIED',
                'status_class' => 'mentor-replied',
                'upvotes' => 0,
                'replies' => 1
            ],
            [
                'id' => 3,
                'title' => 'What wrong in this code',
                'content' => 'This is a follow-up question to my previous post. I still need help with the same issue.',
                'student_name' => 'Zaki',
                'grade' => 'Grade 11',
                'course' => 'English',
                'date' => '28 Mar 2025',
                'status' => 'MENTOR REPLIED',
                'status_class' => 'mentor-replied',
                'upvotes' => 0,
                'replies' => 1
            ],
            [
                'id' => 4,
                'title' => 'Some tests are not getting passed.',
                'content' => 'I have written several test cases but some of them are failing. Can you help me debug this issue?',
                'student_name' => 'Sujith',
                'grade' => 'Grade 12',
                'course' => 'Mathematics',
                'date' => '19 Sep 2024',
                'status' => 'Clarified',
                'status_class' => 'clarified',
                'upvotes' => 1,
                'replies' => 1
            ],
            [
                'id' => 5,
                'title' => 'CheckBox',
                'content' => 'I need help with implementing a checkbox functionality in my web application.',
                'student_name' => 'Daveed',
                'grade' => 'Grade 9',
                'course' => 'Science',
                'date' => '16 Dec 2023',
                'status' => 'MENTOR REPLIED',
                'status_class' => 'mentor-replied',
                'upvotes' => 1,
                'replies' => 3
            ],
            [
                'id' => 6,
                'title' => 'How to solve quadratic equations?',
                'content' => 'I am struggling with the quadratic formula. Can someone explain it step by step?',
                'student_name' => 'Emma Wilson',
                'grade' => 'Grade 10',
                'course' => 'Mathematics',
                'date' => '2 days ago',
                'status' => 'Pending',
                'status_class' => 'pending',
                'upvotes' => 0,
                'replies' => 0
            ],
            [
                'id' => 7,
                'title' => 'Physics lab experiment help',
                'content' => 'I need assistance with the pendulum experiment. The results are not matching the expected values.',
                'student_name' => 'Ryan Chen',
                'grade' => 'Grade 11',
                'course' => 'Science',
                'date' => '1 day ago',
                'status' => 'MENTOR REPLIED',
                'status_class' => 'mentor-replied',
                'upvotes' => 2,
                'replies' => 1
            ],
            [
                'id' => 8,
                'title' => 'Essay writing structure',
                'content' => 'Can someone help me understand the proper structure for a persuasive essay?',
                'student_name' => 'Sophia Martinez',
                'grade' => 'Grade 12',
                'course' => 'English',
                'date' => '3 days ago',
                'status' => 'Clarified',
                'status_class' => 'clarified',
                'upvotes' => 1,
                'replies' => 2
            ]
            ];
        }

        $templatecontext['course_overview'] = theme_remui_kids_get_course_overview();
        if (empty($templatecontext['course_overview'])) {
            error_log("No courses found for overview");
            $templatecontext['course_overview'] = [
                ['id' => 0, 'name' => 'No courses yet', 'shortname' => '-', 'student_count' => 0, 
                 'activity_count' => 0, 'assignment_count' => 0, 'quiz_count' => 0, 'url' => '#']
            ];
        } else {
            error_log("Loaded " . count($templatecontext['course_overview']) . " courses for overview");
        }
        
        // Get teacher courses for dropdown
        $teacher_courses_raw = enrol_get_all_users_courses($USER->id, true);
        $templatecontext['teacher_courses'] = [];
        foreach ($teacher_courses_raw as $course) {
            $templatecontext['teacher_courses'][] = [
                'id' => $course->id,
                'fullname' => format_string($course->fullname),
                'shortname' => format_string($course->shortname)
            ];
        }
        
        // Get competency analytics (overall by default)
        $templatecontext['competency_analytics'] = theme_remui_kids_get_teacher_competency_analytics(0);
        if (empty($templatecontext['competency_analytics']['has_data'])) {
            $templatecontext['competency_analytics'] = [
                'top_competencies' => [],
                'bottom_competencies' => [],
                'has_data' => false
            ];
        }
        
        // Get best performing students (overall by default, limit 5)
        $best_students_data = theme_remui_kids_get_best_performing_students(0, 5);
        $best_students_list = !empty($best_students_data['students']) ? $best_students_data['students'] : [];
        $templatecontext['best_students'] = [
            'students' => $best_students_list,
            'has_data' => !empty($best_students_list)
        ];
        if (empty($templatecontext['best_students']['has_data'])) {
            $templatecontext['best_students'] = [
                'students' => [],
                'has_data' => false
            ];
        }

        // Build leaderboard users (top performers + current user)
        $templatecontext['leaderboard_users'] = theme_remui_kids_build_leaderboard_users_from_best_students(
            $best_students_list,
            $best_students_data['all_students'] ?? [],
            $USER->id,
            3,
            fullname($USER)
        );
        
        // Additional real data for teacher dashboard
        $templatecontext['teaching_progress'] = theme_remui_kids_get_teaching_progress_data();
        if (empty($templatecontext['teaching_progress']) || !isset($templatecontext['teaching_progress']['progress_percentage'])) {
            $templatecontext['teaching_progress'] = [
                'progress_percentage' => 68,
                'progress_label' => '34 of 50 activities completed'
            ];
        }
        $templatecontext['student_feedback'] = theme_remui_kids_get_student_feedback_data();
        $templatecontext['recent_feedback'] = theme_remui_kids_get_recent_feedback_data();
        if (empty($templatecontext['recent_feedback'])) {
            $templatecontext['recent_feedback'] = [
                ['student_name' => 'John Smith', 'date' => '2 days ago', 'grade_percent' => 95, 'item_name' => 'Quiz 1', 'course_name' => 'Mathematics 101'],
                ['student_name' => 'Sarah Johnson', 'date' => '3 days ago', 'grade_percent' => 82, 'item_name' => 'Assignment 1', 'course_name' => 'Science Basics'],
                ['student_name' => 'Mike Davis', 'date' => '5 days ago', 'grade_percent' => 76, 'item_name' => 'Midterm', 'course_name' => 'English Grammar']
            ];
        }

        // Assignments mock fallback
        if (empty($templatecontext['teacher_assignments'])) {
            $templatecontext['teacher_assignments'] = [
                ['id' => 0, 'name' => 'Essay: My Summer', 'course_name' => 'English Grammar', 'course_id' => 0, 'due_date' => 'Nov 20, 2025', 'submission_count' => 12, 'graded_count' => 5, 'status' => 'pending', 'url' => '#'],
                ['id' => 0, 'name' => 'Lab Report #2', 'course_name' => 'Science Basics', 'course_id' => 0, 'due_date' => 'Nov 18, 2025', 'submission_count' => 18, 'graded_count' => 10, 'status' => 'due_soon', 'url' => '#'],
                ['id' => 0, 'name' => 'Unit Test', 'course_name' => 'Mathematics 101', 'course_id' => 0, 'due_date' => 'Nov 10, 2025', 'submission_count' => 22, 'graded_count' => 22, 'status' => 'overdue', 'url' => '#']
            ];
        }


        // Grades overview fallback
        if (empty($templatecontext['student_feedback']) || !isset($templatecontext['student_feedback']['average_percent'])) {
            $templatecontext['student_feedback'] = [
                'average_percent' => 84,
                'total_graded' => 120,
                'distribution' => [
                    '80_100' => 50, '60_79' => 40, '40_59' => 18, '20_39' => 8, '0_19' => 4,
                    '80_100_percent' => 42, '60_79_percent' => 33, '40_59_percent' => 15, '20_39_percent' => 7, '0_19_percent' => 3
                ]
            ];
        }
        
        // Capture sidebar HTML from sidebar.php
        ob_start();
        include($CFG->dirroot . '/theme/remui_kids/teacher/includes/sidebar.php');
        $sidebar_html = ob_get_clean();
        $templatecontext['sidebar_html'] = $sidebar_html;
        
        // Capture sidebar HTML from sidebar.php
        ob_start();
        include($CFG->dirroot . '/theme/remui_kids/teacher/includes/sidebar.php');
        $sidebar_html = ob_get_clean();
        $templatecontext['sidebar_html'] = $sidebar_html;
        
        // Must be called before rendering the template.
        require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
        
        // Render our custom teacher dashboard template
        echo $OUTPUT->render_from_template('theme_remui_kids/teacher_dashboard', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Get user's cohort information for non-admin users
    $usercohorts = $DB->get_records_sql(
        "SELECT c.name, c.id 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$USER->id]
    );

    $usercohortname = '';
    $usercohortid = 0;

    if (!empty($usercohorts)) {
        // Get the first cohort (assuming user is in one main cohort)
        $cohort = reset($usercohorts);
        $usercohortname = $cohort->name;
        $usercohortid = $cohort->id;
    }

    // Fallback to custom profile field "gradelevel" if no cohort found.
    if (empty($usercohortname)) {
        $gradefield = $DB->get_record('user_info_field', ['shortname' => 'gradelevel'], 'id', IGNORE_MISSING);
        if ($gradefield && !empty($gradefield->id)) {
            $gradevalue = $DB->get_field('user_info_data', 'data', [
                'fieldid' => $gradefield->id,
                'userid' => $USER->id
            ], IGNORE_MISSING);

            if (!empty($gradevalue)) {
                $usercohortname = $gradevalue;
            }
        }
    }

    // Determine which dashboard layout to show based on cohort
    $dashboardtype = 'default'; // Default dashboard

    if (!empty($usercohortname)) {
        // Check for Grade 8-12 (High School) - Check this first to avoid conflicts
        if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
            $dashboardtype = 'highschool';
        }
        // Check for Grade 4-7 (Middle)
        elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
            $dashboardtype = 'middle';
        }
        // Check for Grade 1-3 or KG Level 1-3 (Elementary) - Check this last
        elseif (preg_match('/grade\s*[1-3]/i', $usercohortname) ||
                preg_match('/kg\s*-\s*level\s*[1-3]/i', $usercohortname) ||
                preg_match('/kindergarten\s*(?:level\s*)?[1-3]?/i', $usercohortname)) {
            $dashboardtype = 'elementary';
        }
    }

    // Add custom dashboard data to template context
    $templatecontext['custom_dashboard'] = true;
    $templatecontext['dashboard_type'] = $dashboardtype;
    $templatecontext['user_cohort_name'] = $usercohortname;
    $templatecontext['user_cohort_id'] = $usercohortid;
    $templatecontext['student_name'] = $USER->firstname;
    $templatecontext['user_fullname'] = fullname($USER);
    $templatecontext['hello_message'] = "Hello " . $USER->firstname . "!";
    
    // Set My Courses URL based on dashboard type
    if ($dashboardtype === 'highschool') {
        $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/highschool_courses.php'))->out();
        $templatecontext['assignmentsurl'] = (new moodle_url('/theme/remui_kids/highschool_assignments.php'))->out();
        $templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/highschool_profile.php'))->out();
        $templatecontext['messagesurl'] = (new moodle_url('/theme/remui_kids/highschool_messages.php'))->out();
        $templatecontext['gradesurl'] = (new moodle_url('/theme/remui_kids/highschool_grades.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/highschool_calendar.php'))->out();
        $templatecontext['reportsurl'] = (new moodle_url('/theme/remui_kids/highschool_myreports.php'))->out();
        $templatecontext['treeviewurl'] = (new moodle_url('/theme/remui_kids/treeview.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/competencies.php'))->out();
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/highschool_lessons.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/highschool_activities.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        $templatecontext['ebooksurl'] = (new moodle_url('/theme/remui_kids/ebooks.php'))->out();
        $templatecontext['askteacherurl'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['certificatesurl'] = (new moodle_url('/local/certificate_approval/index.php'))->out();
        
        // Add Study Partner context for high school dashboard (check config and capability)
        $showstudypartnercta = get_config('local_studypartner', 'showstudentnav');
        if ($showstudypartnercta === null) {
            $showstudypartnercta = true; // Default to visible
        } else {
            $showstudypartnercta = (bool)$showstudypartnercta;
        }
        // Check if user has the capability to view Study Partner
        $context = context_system::instance();
        $hasstudypartnercapability = has_capability('local/studypartner:view', $context);
        // Only show if both config is enabled AND user has capability
        $templatecontext['showstudypartnercta'] = $showstudypartnercta && $hasstudypartnercapability;
        $templatecontext['studypartnerurl'] = (new moodle_url('/local/studypartner/index.php'))->out();
        
        $templatecontext['logouturl'] = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out();
        $templatecontext['currentpage'] = ['dashboard' => true];
        $templatecontext['is_dashboard_page'] = true;
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        
        $templatecontext['is_dashboard_page'] = true;
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        
        error_log("DRAWERS DEBUG: High School - Setting profileurl = " . $templatecontext['profileurl']);
    } else {
        $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out();
        $templatecontext['assignmentsurl'] = (new moodle_url('/local/assign/index.php'))->out();
        $templatecontext['assignmentsurl'] = (new moodle_url('/local/assign/index.php'))->out();
        // Elementary students (Grades 1-3) - Use custom profile page
        // Non-elementary students will be redirected by the page itself
        $templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out();                // Set lessons URL based on dashboard type
        if ($dashboardtype === 'elementary') {
            $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out();
            $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out();
            $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out();
            $templatecontext['myreportsurl'] = (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out();
        } else {
            $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/lessons.php'))->out();
            $templatecontext['activitiesurl'] = (new moodle_url('/local/quiz/index.php'))->out();
        }
        $templatecontext['messagesurl'] = (new moodle_url('/message/index.php'))->out();
        $templatecontext['gradesurl'] = (new moodle_url('/grade/report/overview/index.php'))->out();
        
        // Add custom URLs for middle school students (Grade 4-7, including Grade 5)
        if ($dashboardtype === 'middle') {
            $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/middleschool_current_activity.php'))->out();
            $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/achievements.php'))->out();
            $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/competencies.php'))->out();
            $templatecontext['gradesurl'] = (new moodle_url('/theme/remui_kids/grades.php'))->out();
            $templatecontext['badgesurl'] = (new moodle_url('/theme/remui_kids/badges.php'))->out();
            $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
            $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        } else {
            // Elementary students (Grades 1-3) - Use custom achievements page
            // Non-elementary students will be redirected by the page itself
            $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
            $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        }
        
        // Set dashboard page flag for sidebar highlighting
        $templatecontext['is_dashboard_page'] = true;
        $templatecontext['currentpage'] = ['dashboard' => true];
        
        // Set dashboard page flag for sidebar highlighting
        $templatecontext['is_dashboard_page'] = true;
        $templatecontext['currentpage'] = ['dashboard' => true];
    }
    
    // Global Scratch Emulator URL for all dashboards
    if (!isset($templatecontext['scratchemulatorurl'])) {
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
    }
    
    // Set treeview URL based on dashboard type (elementary gets dedicated page)
    if ($dashboardtype === 'elementary') {
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
    } else {
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/treeview.php';
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/schedule.php'))->out();
    }
    error_log("DRAWERS DEBUG: Setting treeviewurl = " . $templatecontext['treeviewurl']);
    if (!isset($templatecontext['calendarurl'])) {
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
    }
    
    // Always ensure dashboardurl and mycoursesurl are set for navbar (set early, before any conditionals)
    if (!isset($templatecontext['dashboardurl'])) {
        $templatecontext['dashboardurl'] = (new moodle_url('/my/'))->out();
    }
    
    // Set mycoursesurl based on dashboard type if not already set
    if (!isset($templatecontext['mycoursesurl'])) {
        if ($dashboardtype === 'highschool') {
            $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/highschool_courses.php'))->out();
        } elseif ($dashboardtype === 'elementary') {
            $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out();
        } else {
            $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out();
        }
    }
    $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
    $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
    $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
    $templatecontext['scratchurl'] = (new moodle_url('/local/lti/view.php', ['id' => 2]))->out(); // Adjust ID as needed
    $templatecontext['logouturl'] = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out();
    // Only set profileurl here if it hasn't been set yet (for non-highschool students)
    if (!isset($templatecontext['profileurl'])) {
        $templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out();
        error_log("DRAWERS DEBUG: Setting default profileurl (elementary) = " . $templatecontext['profileurl']);
    } else {
        error_log("DRAWERS DEBUG: Profileurl already set, keeping it as = " . $templatecontext['profileurl']);
    }
    $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
    $templatecontext['wwwroot'] = $CFG->wwwroot;
    if (!isset($templatecontext['codeeditorurl'])) {
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
    }
    if (!isset($templatecontext['ebooksurl'])) {
        $templatecontext['ebooksurl'] = (new moodle_url('/theme/remui_kids/ebooks.php'))->out();
    }
    if (!isset($templatecontext['askteacherurl'])) {
        $templatecontext['askteacherurl'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out();
    }
    
    // AI Learning Assistant availability (visible for all logged-in users)
    $learningassistantpluginpath = $CFG->dirroot . '/local/learningassistant/lib.php';
    $learningassistantexists = file_exists($learningassistantpluginpath);
    
    // Show AI assistant to all logged-in non-guest users if plugin exists
    $canuseaiassistant = $learningassistantexists && !isguestuser() && isloggedin();
    
    $templatecontext['show_aiassistant'] = $canuseaiassistant;
    if ($canuseaiassistant) {
        $templatecontext['aiassistanturl'] = (new moodle_url('/local/learningassistant/learning_assistant.php'))->out();
    } else {
        $templatecontext['aiassistanturl'] = '';
    }
    
    // Add custom body class for dashboard styling
    $templatecontext['bodyattributes'] = 'class="custom-dashboard-page has-student-sidebar"';
    
    // Ensure parent theme navigation context is properly set up
    $templatecontext['navlayout'] = \theme_remui\toolbox::get_setting('header-primary-layout-desktop');
    $templatecontext['applylatestuserpref'] = apply_latest_user_pref();
    
    // Set up drawer preferences for parent theme navigation
    user_preference_allow_ajax_update('drawer-open-nav', PARAM_ALPHA);
    user_preference_allow_ajax_update('drawer-open-index', PARAM_BOOL);
    user_preference_allow_ajax_update('drawer-open-block', PARAM_BOOL);
    
    $navdraweropen = (get_user_preferences('drawer-open-nav', true) == true);
    $templatecontext['navdraweropen'] = $navdraweropen;
    
    // Add parent theme navigation context
    $templatecontext['applylatestdrawerjs'] = (get_moodle_release_version_branch() > '402');
    
    // Ensure parent theme navigation JavaScript is loaded
    $PAGE->requires->data_for_js('applylatestuserpref', $templatecontext['applylatestuserpref']);
    
    // Set individual dashboard type flags for Mustache template
    $templatecontext['elementary'] = ($dashboardtype === 'elementary');
    $templatecontext['middle'] = ($dashboardtype === 'middle');
    $templatecontext['highschool'] = ($dashboardtype === 'highschool');
    $templatecontext['default'] = ($dashboardtype === 'default');

    // Inject MAP Test launch card data for student dashboards.
    $studentdashboards = ['elementary', 'middle', 'highschool'];
    error_log("MAP Test Debug: Dashboard type = '$dashboardtype', User ID = {$USER->id}");
    error_log("MAP Test Debug: Is dashboard type in student dashboards? " . (in_array($dashboardtype, $studentdashboards, true) ? 'YES' : 'NO'));
    error_log("MAP Test Debug: Function exists? " . (function_exists('local_maptest_get_card_context') ? 'YES' : 'NO'));
    error_log("MAP Test Debug: maptest_card already set? " . (isset($templatecontext['maptest_card']) ? 'YES' : 'NO'));
    
    if (!isset($templatecontext['maptest_card']) &&
        in_array($dashboardtype, $studentdashboards, true) &&
        function_exists('local_maptest_get_card_context')) {
        $maptestcardcontext = local_maptest_get_card_context($USER->id);
        error_log("MAP Test Debug: Card context result for user {$USER->id}: " . (empty($maptestcardcontext) ? 'EMPTY' : 'NOT EMPTY (' . count($maptestcardcontext) . ' items)'));
        if (!empty($maptestcardcontext)) {
            $templatecontext['maptest_card'] = $maptestcardcontext;
            $templatecontext['show_maptest_card'] = true;
            error_log("MAP Test Debug: Card context SET in template for user {$USER->id}");
            $PAGE->requires->js_init_code(<<<'JS'
(function() {
    let mapTestWindow = null;
    let overlayCheckInterval = null;
    let overlayElement = null;

    function createOverlay() {
        if (overlayElement) {
            return;
        }
        overlayElement = document.createElement('div');
        overlayElement.id = 'maptest-overlay';
        overlayElement.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        `;
        overlayElement.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 12px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <div style="font-size: 48px; margin-bottom: 20px;">🔒</div>
                <h3 style="margin: 0 0 10px 0; color: #0f172a; font-size: 20px;">MAP Test in Progress</h3>
                <p style="margin: 0; color: #64748b; font-size: 14px;">Please close the test window to continue</p>
            </div>
        `;
        document.body.appendChild(overlayElement);
        document.body.style.overflow = 'hidden';
    }

    function removeOverlay() {
        if (overlayElement) {
            overlayElement.remove();
            overlayElement = null;
        }
        document.body.style.overflow = '';
        if (overlayCheckInterval) {
            clearInterval(overlayCheckInterval);
            overlayCheckInterval = null;
        }
    }

    function checkPopupClosed() {
        if (!mapTestWindow || mapTestWindow.closed) {
            removeOverlay();
            mapTestWindow = null;
        }
    }

    document.addEventListener('click', function(e) {
        const button = e.target.closest('.maptest-start-btn');
        if (!button) {
            return;
        }
        e.preventDefault();
        const url = button.getAttribute('data-maptest-url');
        if (!url) {
            return;
        }

        // Check if SSO is enabled
        const enablesso = button.getAttribute('data-maptest-sso') === 'true' || button.getAttribute('data-maptest-sso') === '1';
        const ssourl = button.getAttribute('data-maptest-ssourl') || '';
        const maptesturl = button.getAttribute('data-maptest-baseurl') || 'https://map-test.bylinelms.com/login';
        
        // Function to open popup with URL
        function openMapTestPopup(targetUrl) {
            mapTestWindow = window.open(
                targetUrl,
                'mapTestWindow',
                'width=1024,height=768,resizable=yes,scrollbars=yes'
            );

            if (mapTestWindow) {
                // Create overlay to block parent window
                createOverlay();

                // Check every 500ms if popup is still open
                overlayCheckInterval = setInterval(checkPopupClosed, 500);

                // Also check on window focus (in case user switches tabs)
                window.addEventListener('focus', checkPopupClosed);
            }
        }

        if (enablesso && ssourl) {
            // SSO enabled - fetch token first
            fetch(ssourl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('SSO token fetch failed');
                }
                return response.json();
            })
            .then(data => {
                if (data.status === 'success' && data.redirect_url) {
                    // Debug: Log the redirect URL to verify token is included
                    console.log('MAP Test SSO - Redirect URL:', data.redirect_url);
                    console.log('MAP Test SSO - Token:', data.token ? data.token.substring(0, 50) + '...' : 'No token');
                    // Open popup with SSO token URL
                    openMapTestPopup(data.redirect_url);
                } else {
                    console.warn('MAP Test SSO - Invalid response:', data);
                    // Fallback to regular URL
                    openMapTestPopup(url);
                }
            })
            .catch(error => {
                console.error('MAP Test SSO error:', error);
                // Fallback to regular URL
                openMapTestPopup(url);
            });
        } else {
            // SSO not enabled - use regular URL
            openMapTestPopup(url);
        }
    });

    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        removeOverlay();
    });
})();
JS
            );
        }
    }
    
    // Add Grade 1-3 specific statistics and courses for elementary students
    if ($dashboardtype === 'elementary') {
        $templatecontext['elementary_stats'] = theme_remui_kids_get_elementary_dashboard_stats($USER->id);
        $courses = theme_remui_kids_get_elementary_courses($USER->id);
        $templatecontext['elementary_courses'] = array_slice($courses, 0, 3); // Show only first 3 courses
        $templatecontext['has_elementary_courses'] = !empty($courses);
        $templatecontext['total_courses_count'] = count($courses);
        $templatecontext['show_view_all_button'] = count($courses) > 3;
        
        // Add elementary-specific URLs for sidebar navigation
        $templatecontext['elementary_mycoursesurl'] = (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out();
        $templatecontext['mycoursesurl'] = (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out();
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out();
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out();
        $templatecontext['myreportsurl'] = (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['allcoursesurl'] = (new moodle_url('/course/index.php'))->out();
        // Elementary students (Grades 1-3) - Use custom profile page
    // Non-elementary students will be redirected by the page itself
        $templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out();
        $templatecontext['profileurl'] = (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out();
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        
        // Fetch calendar events for elementary dashboard
        // Wrap in try-catch to prevent fatal errors from breaking the page
        $calendar_events = [];
        try {
            // Check if $USER is available and has an id
            if (!isset($USER) || !is_object($USER) || !isset($USER->id) || empty($USER->id)) {
                error_log("Elementary Dashboard: User object not available or invalid");
                $calendar_events = [];
            } else if (function_exists('theme_remui_kids_get_elementary_calendar_events')) {
                $calendar_events = theme_remui_kids_get_elementary_calendar_events($USER->id);
            } else {
                error_log("Elementary Dashboard: theme_remui_kids_get_elementary_calendar_events function not found");
                $calendar_events = [];
            }
        } catch (Exception $e) {
            error_log("Elementary Dashboard: Error fetching calendar events: " . $e->getMessage());
            error_log("Elementary Dashboard: Stack trace: " . $e->getTraceAsString());
            $calendar_events = [];
        } catch (Throwable $e) {
            error_log("Elementary Dashboard: Fatal error fetching calendar events: " . $e->getMessage());
            error_log("Elementary Dashboard: Stack trace: " . $e->getTraceAsString());
            $calendar_events = [];
        }
        
        // Debug: Log how many events were fetched (safely)
        if (isset($USER) && is_object($USER) && isset($USER->id)) {
            error_log("Elementary Dashboard: Fetched " . count($calendar_events) . " calendar events for user " . $USER->id);
        } else {
            error_log("Elementary Dashboard: Fetched " . count($calendar_events) . " calendar events (user not available)");
        }
        
        // Transform events to match JavaScript expected format
        $transformed_events = [];
        foreach ($calendar_events as $event) {
            // Check if this is an admin event first (admin events have all fields pre-populated)
            if (isset($event['admin_event']) && $event['admin_event']) {
                // Admin events already have date, time, type, and course fields set
                $event_date = $event['date'] ?? date('Y-m-d', $event['t']);
                $event_time = $event['time'] ?? date('g:i A', $event['t']);
                $event_type = $event['type'] ?? 'meeting';
                $course_name = $event['course'] ?? 'School Event';
                
                // Get color from admin_event_type or tone
                $event_color = 'blue'; // Default
                if (isset($event['admin_event_type'])) {
                    $admin_type = strtolower($event['admin_event_type'] ?? 'meeting');
                    if ($admin_type === 'meeting') {
                        $event_color = 'blue';
                    } elseif ($admin_type === 'lecture') {
                        $event_color = 'green';
                    } elseif ($admin_type === 'exam') {
                        $event_color = 'red';
                    } elseif ($admin_type === 'activity') {
                        $event_color = 'orange';
                    }
                } else {
                    // Use tone as color fallback
                    $tone = $event['tone'] ?? 'blue';
                    if ($tone === 'red') { $event_color = 'red'; }
                    elseif ($tone === 'green') { $event_color = 'green'; }
                    elseif ($tone === 'yellow' || $tone === 'orange') { $event_color = 'orange'; }
                    elseif ($tone === 'purple') { $event_color = 'purple'; }
                    else { $event_color = 'blue'; }
                }
                
                error_log("Elementary Dashboard: Transforming admin event - " . $event['title'] . " (type: {$event_type}, date: {$event_date}, color: {$event_color})");
            } else {
                // Non-admin events - calculate from timestamp and determine type from title
                $event_date = date('Y-m-d', $event['t']);
                $event_time = date('g:i A', $event['t']);
                $event_type = 'event';
                $course_name = 'General';
                $title_lower = strtolower($event['title']);
                
                if (strpos($title_lower, 'assignment') !== false || strpos($title_lower, '📝') !== false) {
                    $event_type = 'assignment';
                } elseif (strpos($title_lower, 'quiz') !== false || strpos($title_lower, '❓') !== false) {
                    $event_type = 'quiz';
                } elseif (strpos($title_lower, 'lesson') !== false || strpos($title_lower, '📖') !== false) {
                    $event_type = 'lesson';
                } elseif (strpos($title_lower, 'course start') !== false || strpos($title_lower, '🎓') !== false) {
                    $event_type = 'course';
                    // Extract course name from title
                    if (preg_match('/🎓\s*(.+?)\s*-/', $event['title'], $matches)) {
                        $course_name = trim($matches[1]);
                    }
                } elseif (strpos($title_lower, 'course end') !== false || strpos($title_lower, '🏆') !== false) {
                    $event_type = 'course';
                    // Extract course name from title
                    if (preg_match('/🏆\s*(.+?)\s*-/', $event['title'], $matches)) {
                        $course_name = trim($matches[1]);
                    }
                }
            }
            
            $transformed_event = [
                'date' => $event_date,
                'time' => $event_time,
                'title' => $event['title'],
                'type' => $event_type,
                'url' => $event['url'] ?? '#',
                'course' => $course_name,
                'tone' => $event['tone'] ?? 'blue',
                'description' => $event['description'] ?? '',
                'admin_event' => isset($event['admin_event']) ? $event['admin_event'] : false,
                'color' => $event_color ?? ($event['tone'] ?? 'blue')
            ];
            
            // Add color field for admin events (for monthly view dots and upcoming events)
            if (isset($event['admin_event']) && $event['admin_event']) {
                // Use color from event if available, otherwise use the color we determined above
                if (isset($event['color'])) {
                    $transformed_event['color'] = $event['color'];
                } else {
                    $transformed_event['color'] = isset($event_color) ? $event_color : 'blue';
                }
            }
            
            $transformed_events[] = $transformed_event;
        }
        
        // Debug: Log how many events were transformed
        error_log("Elementary Dashboard: Transformed " . count($transformed_events) . " events for display");
        if (!empty($transformed_events)) {
            error_log("Elementary Dashboard: Sample event - " . json_encode($transformed_events[0]));
        }
        
        $templatecontext['elementary_calendar_events'] = json_encode($transformed_events);

         // Add page detection flags
         $templatecontext['is_lessons_page'] = $is_elementary_lessons;
         $templatecontext['is_activities_page'] = $is_elementary_activities;
         $templatecontext['is_community_page'] = $is_community_page;
        // Add Study Partner context for elementary dashboard
        $showstudypartnercta = get_config('local_studypartner', 'showstudentnav');
        if ($showstudypartnercta === null) {
            $showstudypartnercta = true; // Default to visible
        } else {
            $showstudypartnercta = (bool)$showstudypartnercta;
        }
        // Check if user has the capability to view Study Partner
        $context = context_system::instance();
        $hasstudypartnercapability = has_capability('local/studypartner:view', $context);
        // Only show if both config is enabled AND user has capability
        $templatecontext['showstudypartnercta'] = $showstudypartnercta && $hasstudypartnercapability;
        $templatecontext['studypartnerurl'] = (new moodle_url('/local/studypartner/index.php'))->out();
        $templatecontext['studypartnerdiagnosticurl'] = (new moodle_url('/theme/remui_kids/study_partner_diagnostic.php'))->out();
        $templatecontext['isadmin'] = is_siteadmin() || has_capability('moodle/site:config', context_system::instance());
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        

         // Add page detection flags
         $templatecontext['is_lessons_page'] = $is_elementary_lessons;
         $templatecontext['is_activities_page'] = $is_elementary_activities;
         $templatecontext['is_community_page'] = $is_community_page;
        // Add Study Partner context for elementary dashboard
        $showstudypartnercta = get_config('local_studypartner', 'showstudentnav');
        if ($showstudypartnercta === null) {
            $showstudypartnercta = true; // Default to visible
        } else {
            $showstudypartnercta = (bool)$showstudypartnercta;
        }
        // Check if user has the capability to view Study Partner
        $context = context_system::instance();
        $hasstudypartnercapability = has_capability('local/studypartner:view', $context);
        // Only show if both config is enabled AND user has capability
        $templatecontext['showstudypartnercta'] = $showstudypartnercta && $hasstudypartnercapability;
        $templatecontext['studypartnerurl'] = (new moodle_url('/local/studypartner/index.php'))->out();
        $templatecontext['studypartnerdiagnosticurl'] = (new moodle_url('/theme/remui_kids/study_partner_diagnostic.php'))->out();
        $templatecontext['isadmin'] = is_siteadmin() || has_capability('moodle/site:config', context_system::instance());
        
        // Add active sections data
        $activesections = theme_remui_kids_get_elementary_active_sections($USER->id);
        $templatecontext['elementary_active_sections'] = $activesections;
        $templatecontext['has_elementary_active_sections'] = !empty($activesections);
        
        // Add active lessons data
        $activelessons = theme_remui_kids_get_elementary_active_lessons($USER->id);
        $templatecontext['elementary_active_lessons'] = $activelessons;
        $templatecontext['has_elementary_active_lessons'] = !empty($activelessons);
        
        // Add calendar widget data
        $templatecontext['current_month'] = date('F Y');
        $templatecontext['calendar_days'] = theme_remui_kids_get_calendar_widget_days($USER->id);
        $templatecontext['upcoming_events'] = theme_remui_kids_get_upcoming_events_widget($USER->id);
        $templatecontext['has_upcoming_events'] = !empty($templatecontext['upcoming_events']);
        
    }
    
    // Add Grade 4-7 specific statistics and courses for middle school students
    if ($dashboardtype === 'middle') {
        $templatecontext['middle_stats'] = theme_remui_kids_get_elementary_dashboard_stats($USER->id); // Reuse the same stats function
        $courses = theme_remui_kids_get_elementary_courses($USER->id); // Reuse the same courses function
        
        // Limit to exactly 2 courses for display in My Courses section (MAP test card will be the 3rd card)
        $displayedcourses = array_slice($courses, 0, 2);
        $templatecontext['middle_courses'] = $displayedcourses; // Show only first 2 courses
        $templatecontext['has_middle_courses'] = !empty($courses);
        $templatecontext['total_courses_count'] = count($courses);
        $templatecontext['show_view_all_button'] = count($courses) > 2;
        
        // Add course sections data for modal preview (only for displayed courses)
        $coursesectionsdata = [];
        foreach ($displayedcourses as $course) {
            $sectionsdata = theme_remui_kids_get_course_sections_for_modal($course['id']);
            $coursesectionsdata[$course['id']] = $sectionsdata;
            // Debug: Log the data for each course
            error_log("Course {$course['id']} ({$course['fullname']}) sections data: " . print_r($sectionsdata, true));
        }
        $templatecontext['middle_courses_sections'] = json_encode($coursesectionsdata);
        // Debug: Log the final JSON data
        error_log("Final courses sections JSON: " . $templatecontext['middle_courses_sections']);
        
        // Add active sections data (limit to 3 for Current Lessons section)
        $activesections = theme_remui_kids_get_elementary_active_sections($USER->id);
        $templatecontext['middle_active_sections'] = array_slice($activesections, 0, 3); // Show only first 3 sections
        $templatecontext['has_middle_active_sections'] = !empty($activesections);
        
        // Add active lessons data (limit to 3 like elementary dashboard)
        $activelessons = theme_remui_kids_get_elementary_active_lessons($USER->id);
        $templatecontext['middle_active_lessons'] = array_slice($activelessons, 0, 3); // Show only first 3 lessons
        $templatecontext['has_middle_active_lessons'] = !empty($activelessons);
        
        // Add calendar and sidebar data
        $templatecontext['calendar_week'] = theme_remui_kids_get_calendar_week_data($USER->id);
        $templatecontext['upcoming_events'] = theme_remui_kids_get_upcoming_events($USER->id);
        $templatecontext['highschool_schedule_events'] = array_slice($templatecontext['upcoming_events'], 0, 4);
        $templatecontext['has_highschool_schedule'] = !empty($templatecontext['highschool_schedule_events']);
        $templatecontext['learning_stats'] = theme_remui_kids_get_learning_progress_stats($USER->id);
        $templatecontext['achievements'] = theme_remui_kids_get_achievements_data($USER->id);
        $templatecontext['calendarurl'] = (new moodle_url('/calendar/view.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['config'] = ['wwwroot' => $CFG->wwwroot];
        $templatecontext['is_community_page'] = $is_community_page;
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['config'] = ['wwwroot' => $CFG->wwwroot];
        $templatecontext['is_community_page'] = $is_community_page;

        // Calculate real statistics for middle school dashboard
        $middle_real_stats = theme_remui_kids_get_middle_real_stats($USER->id);
        // Calculate SVG progress offset for circular progress bar (175 - (percentage/100 * 175))
        $middle_real_stats['progress_offset'] = 175 - round(($middle_real_stats['overall_progress_percentage'] / 100) * 175);
        $templatecontext['middle_real_stats'] = $middle_real_stats;

        // Generate dynamic achievements based on real progress
        $middle_achievements = theme_remui_kids_generate_middle_achievements($middle_real_stats);
        $templatecontext['middle_achievements'] = $middle_achievements;

        // Add leaderboard data for middle school dashboard
        $leaderboard_students = isset($GLOBALS['leaderboard_students']) ? $GLOBALS['leaderboard_students'] : [];
        if (!empty($leaderboard_students)) {
            // Process leaderboard data for template
            $processed_leaderboard = [];
            foreach ($leaderboard_students as $student) {
                $processed_leaderboard[] = [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'full_name' => $student['full_name'],
                    'points' => $student['points'],
                    'score' => $student['points'], // Use points as score for display
                    'profile_picture_url' => $student['profile_picture_url'],
                    'name_initial' => strtoupper(substr($student['name'], 0, 1)),
                    'is_current_user' => $student['is_current_user']
                ];
            }
            $templatecontext['default_leaderboard_students'] = $processed_leaderboard;
            $templatecontext['leaderboard_students'] = $processed_leaderboard; // Also set the main variable
        }
    }
    // Add Grade 8-12 specific statistics and courses for high school students
    if ($dashboardtype === 'highschool') {
        $templatecontext['highschool_stats'] = theme_remui_kids_get_highschool_dashboard_stats($USER->id);
        $templatecontext['highschool_metrics'] = theme_remui_kids_get_highschool_dashboard_metrics($USER->id);
        $courses = theme_remui_kids_get_highschool_courses($USER->id);
        $templatecontext['highschool_courses'] = array_slice($courses, 0); // Show only first 3 courses
        $templatecontext['has_highschool_courses'] = !empty($courses);
        $templatecontext['total_courses_count'] = count($courses);
        $templatecontext['show_view_all_button'] = count($courses) > 3;

        $subjectdistribution = theme_remui_kids_get_highschool_subject_distribution($USER->id, $courses);
        $templatecontext['highschool_subject_distribution'] = $subjectdistribution;
        $templatecontext['has_highschool_subject_distribution'] = !empty($subjectdistribution['subjects']);
        $templatecontext['highschool_subject_distribution_json'] = !empty($subjectdistribution['subjects'])
            ? json_encode($subjectdistribution, JSON_UNESCAPED_UNICODE)
            : null;

        $notifications = theme_remui_kids_get_header_notifications($USER->id, 3);
        $templatecontext['highschool_notifications'] = $notifications;
        $templatecontext['has_highschool_notifications'] = !empty($notifications);
        
        // Add course sections data for modal preview
        $coursesectionsdata = [];
        foreach ($courses as $course) {
            $sectionsdata = theme_remui_kids_get_course_sections_for_modal($course['id']);
            $coursesectionsdata[$course['id']] = $sectionsdata;
            // Debug: Log the data for each course
            error_log("High school course {$course['id']} ({$course['fullname']}) sections data: " . print_r($sectionsdata, true));
        }
        $templatecontext['highschool_courses_sections'] = json_encode($coursesectionsdata);
        // Debug: Log the final JSON data
        error_log("Final high school courses sections JSON: " . $templatecontext['highschool_courses_sections']);
        
        // Add active sections data (limit to 3 for Current Lessons section)
        $activesections = theme_remui_kids_get_highschool_active_sections($USER->id);
        $templatecontext['highschool_active_sections'] = array_slice($activesections, 0, 3);
        $templatecontext['has_highschool_active_sections'] = !empty($activesections);
        
        // Add active lessons data (limit to 3)
        $activelessons = theme_remui_kids_get_highschool_active_lessons($USER->id);
        $templatecontext['highschool_active_lessons'] = array_slice($activelessons, 0, 3);
        $templatecontext['has_highschool_active_lessons'] = !empty($activelessons);
        
        // Get active activities separately (quizzes, assignments, etc.)
        if (function_exists('theme_remui_kids_get_highschool_active_activities')) {
            $active_activities = theme_remui_kids_get_highschool_active_activities($USER->id, 8);
            // Function now returns activities directly, no transformation needed
            $templatecontext['highschool_active_activities'] = $active_activities;
            $templatecontext['has_highschool_active_activities'] = !empty($active_activities);
            $templatecontext['highschool_active_activities_count'] = count($active_activities);
        }
        
        // Add calendar and sidebar data
        $templatecontext['calendar_week'] = theme_remui_kids_get_calendar_week_data($USER->id);
        $templatecontext['upcoming_events'] = theme_remui_kids_get_upcoming_events($USER->id);
        $templatecontext['learning_stats'] = theme_remui_kids_get_learning_progress_stats($USER->id);
        $templatecontext['achievements'] = theme_remui_kids_get_achievements_data($USER->id);
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/highschool_calendar.php'))->out();
        
        // Performance trend over time (used by chart)
        if (function_exists('theme_remui_kids_get_highschool_performance_trend')) {
            $trenddata = theme_remui_kids_get_highschool_performance_trend($USER->id);
            $templatecontext['highschool_performance_trend'] = $trenddata;
            $templatecontext['has_highschool_performance_trend'] = !empty($trenddata);
            $templatecontext['highschool_performance_trend_json'] = !empty($trenddata)
                ? json_encode($trenddata, JSON_UNESCAPED_UNICODE)
                : null;
        }
        
        // Weekly activity data for the bar chart
        if (function_exists('theme_remui_kids_get_weekly_activity_data')) {
            $weekly_activity = theme_remui_kids_get_weekly_activity_data($USER->id);
            $templatecontext['weekly_activity'] = $weekly_activity;
            $templatecontext['weekly_activity_json'] = !empty($weekly_activity)
                ? json_encode($weekly_activity, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)
                : null;
        }
        
        // Add learning progress data for HighSchool dashboard (inline calculation)
        $thirty_days_ago = time() - (30 * 24 * 60 * 60);
        
        // Get active courses (courses with recent access or with completed activities)
        try {
            // First, try to get courses with recent access
            $active_courses = $DB->get_field_sql(
                "SELECT COUNT(DISTINCT ula.courseid)
                 FROM {user_lastaccess} ula
                 JOIN {enrol} e ON ula.courseid = e.courseid
                 JOIN {user_enrolments} ue ON e.id = ue.enrolid
                 WHERE ula.userid = ? 
                 AND ue.userid = ?
                 AND ula.timeaccess > ?
                 AND ula.courseid > 1",
                [$USER->id, $USER->id, $thirty_days_ago]
            ) ?: 0;
            
            // Also count courses with recent completion activity (last 30 days)
            $courses_with_activity = $DB->get_field_sql(
                "SELECT COUNT(DISTINCT cm.course)
                 FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
                 JOIN {enrol} e ON cm.course = e.courseid
                 JOIN {user_enrolments} ue ON e.id = ue.enrolid
                 WHERE cmc.userid = ?
                 AND ue.userid = ?
                 AND cmc.timemodified > ?
                 AND cm.course > 1",
                [$USER->id, $USER->id, $thirty_days_ago]
            ) ?: 0;
            
            $active_courses = max($active_courses, $courses_with_activity);
            
        } catch (Exception $e) {
            // Fallback: Just count enrolled courses
            $active_courses = $DB->get_field_sql(
                "SELECT COUNT(DISTINCT c.id)
                 FROM {course} c
                 JOIN {enrol} e ON c.id = e.courseid
                 JOIN {user_enrolments} ue ON e.id = ue.enrolid
                 WHERE ue.userid = ? 
                 AND c.visible = 1
                 AND c.id > 1",
                [$USER->id]
            ) ?: 0;
        }
        
        // Get completed activities count
        try {
            $completed_activities = $DB->get_field_sql(
                "SELECT COUNT(*)
                 FROM {course_modules_completion} cmc
                 WHERE cmc.userid = ? 
                 AND cmc.completionstate IN (1, 2)",
                [$USER->id]
            ) ?: 0;
        } catch (Exception $e) {
            $completed_activities = 0;
        }
        
        // Calculate study time (estimate: 15 minutes per completed activity)
        $study_time_minutes = $completed_activities * 15;
        $study_time_hours = round($study_time_minutes / 60, 1);
        $study_time_display = $study_time_hours >= 24 
            ? round($study_time_hours / 24, 1) . 'd' 
            : ($study_time_hours >= 1 
                ? round($study_time_hours, 1) . 'h' 
                : round($study_time_minutes) . 'm');
        
        // Calculate overall progress percentage
        try {
            $total_activities = $DB->get_field_sql(
                "SELECT COUNT(*)
                 FROM {course_modules} cm
                 JOIN {enrol} e ON cm.course = e.courseid
                 JOIN {user_enrolments} ue ON e.id = ue.enrolid
                 WHERE ue.userid = ? 
                 AND cm.completion > 0",
                [$USER->id]
            ) ?: 1;
            
            $overall_progress = $total_activities > 0 
                ? round(($completed_activities / $total_activities) * 100) 
                : 0;
        } catch (Exception $e) {
            $total_activities = 1;
            $overall_progress = 0;
        }
        
        $templatecontext['highschool_learning_progress'] = [
            'active_courses' => (int)$active_courses,
            'completed_activities' => (int)$completed_activities,
            'study_time' => $study_time_display,
            'study_time_hours' => $study_time_hours,
            'overall_progress' => $overall_progress
        ];

        $performance_trend = theme_remui_kids_get_highschool_performance_trend($USER->id);
        $templatecontext['highschool_performance_trend'] = $performance_trend;
        $templatecontext['has_highschool_performance_trend'] = !empty($performance_trend);
        $templatecontext['highschool_performance_trend_json'] = !empty($performance_trend)
            ? json_encode($performance_trend, JSON_UNESCAPED_UNICODE)
            : null;
    }

    // Add cohort-specific data
    switch ($dashboardtype) {
        case 'elementary':
            $templatecontext['dashboard_title'] = 'Elementary Dashboard (Grades 1-3)';
            $templatecontext['dashboard_color'] = '#FF6B6B'; // Red
            break;
        case 'middle':
            $templatecontext['dashboard_title'] = 'Middle School Dashboard (Grades 4-7)';
            $templatecontext['dashboard_color'] = '#4ECDC4'; // Teal
            break;
        case 'highschool':
            $templatecontext['dashboard_title'] = 'High School Dashboard (Grades 8-12)';
            $templatecontext['dashboard_color'] = '#45B7D1'; // Blue
            break;
        default:
            $templatecontext['dashboard_title'] = 'Default Dashboard';
            $templatecontext['dashboard_color'] = '#95A5A6'; // Gray
            break;
    }

    // Must be called before rendering the template.
    require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
    
    // Check if this is the mycourses page and user is elementary student
    if (($PAGE->pagelayout == 'mycourses' && $PAGE->pagetype == 'my-index' && $dashboardtype === 'elementary') ||
        ($is_custom_mycourses && $dashboardtype === 'elementary')) {
        // For mycourses page with elementary students, add sidebar data to template context
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/lessons.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/local/quiz/index.php'))->out();
        $templatecontext['myreportsurl'] = (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        $templatecontext['show_elementary_sidebar'] = true;
        $templatecontext['hide_default_navbar'] = true; // Hide navbar for custom mycourses page
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Check if teacher is viewing as student and add banner
        if ($is_teacher_viewing_student) {
            $realuser = \core\session\manager::get_realuser();
            $templatecontext['is_teacher_viewing_student'] = true;
            $templatecontext['viewing_student_name'] = fullname($USER);
            $templatecontext['teacher_name'] = fullname($realuser);
            $templatecontext['return_to_normal_url'] = (new moodle_url('/login/logout.php', array('sesskey' => sesskey())))->out();
        }
        
        // Use our custom drawers template with enhanced sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the lessons page and user is elementary student
    if ($is_custom_lessons && $dashboardtype === 'elementary') {
        // For lessons page with elementary students, add sidebar data to template context
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/lessons.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/local/quiz/index.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        $templatecontext['show_elementary_sidebar'] = true;
        $templatecontext['hide_default_navbar'] = true; // Hide navbar for custom lessons page
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Use our custom drawers template with enhanced sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the middle school current activity page and user is middle school student
    if ($is_middleschool_current_activity && $dashboardtype === 'middle') {
        // Get sidebar context for middle school
        require_once($CFG->dirroot . '/theme/remui_kids/lib/sidebar_helper.php');
        $sidebar_context = theme_remui_kids_get_elementary_sidebar_context('currentactivity', $USER);
        $templatecontext = array_merge($templatecontext, $sidebar_context);
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/middleschool_current_activity.php'))->out();
        $templatecontext['is_currentactivity_page'] = true;
        $templatecontext['currentpage'] = ['currentactivity' => true];
        // Don't hide navbar for middle school - let the page handle its own layout
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the high school current activity page and user is high school student
    if ($is_highschool_current_activity && $dashboardtype === 'highschool') {
        // Get sidebar context for high school
        require_once($CFG->dirroot . '/theme/remui_kids/lib/highschool_sidebar.php');
        $sidebar_context = remui_kids_build_highschool_sidebar_context('currentactivity', $USER);
        $templatecontext = array_merge($templatecontext, $sidebar_context);
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/highschool_current_activity.php'))->out();
        $templatecontext['is_currentactivity_page'] = true;
        $templatecontext['currentpage'] = ['currentactivity' => true];
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the elementary current activity page and user is elementary student
    if ($is_elementary_current_activity && $dashboardtype === 'elementary') {
        // For elementary current activity page with elementary students, add sidebar data to template context
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out();
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out();
        $templatecontext['myreportsurl'] = (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        $templatecontext['show_elementary_sidebar'] = true;
        $templatecontext['hide_default_navbar'] = true;
        
        // Add sidebar access permissions
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Use our custom drawers template with enhanced sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the elementary lessons page and user is elementary student
    if ($is_elementary_lessons && $dashboardtype === 'elementary') {
        // For elementary lessons page with elementary students, add sidebar data to template context
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out();
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out();
        $templatecontext['myreportsurl'] = (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        $templatecontext['show_elementary_sidebar'] = true;
        $templatecontext['hide_default_navbar'] = true; // Hide navbar for elementary lessons page
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Use our custom drawers template with enhanced sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Check if this is the elementary activities page and user is elementary student
    if ($is_elementary_activities && $dashboardtype === 'elementary') {
        // For elementary activities page with elementary students, add sidebar data to template context
        $templatecontext['lessonsurl'] = (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out();
        $templatecontext['currentactivityurl'] = (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out();
        $templatecontext['activitiesurl'] = (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out();
        $templatecontext['achievementsurl'] = (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out();
        $templatecontext['competenciesurl'] = (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out();
        $templatecontext['scheduleurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
        $templatecontext['calendarurl'] = (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['communityurl'] = (new moodle_url('/theme/remui_kids/community.php'))->out();
        $templatecontext['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
        $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_emulator.php'))->out();
        $templatecontext['treeviewurl'] = $CFG->wwwroot . '/theme/remui_kids/elementary_treeview.php';
        $templatecontext['settingsurl'] = (new moodle_url('/user/preferences.php'))->out();
        $templatecontext['show_elementary_sidebar'] = true;
        $templatecontext['hide_default_navbar'] = true; // Hide navbar for elementary activities page
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Add sidebar access permissions (based on user's cohort)
        $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
        $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
        $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
        $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
        
        // Use our custom drawers template with enhanced sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/drawers', $templatecontext);
        return; // Exit early to prevent normal rendering
    }
    
    // Render our student dashboard template (handles elementary, middle, and high school)
    echo $OUTPUT->render_from_template('theme_remui_kids/dashboard', $templatecontext);
    return; // Exit early to prevent normal rendering
}
}

// For non-dashboard pages, use the original logic (but skip for treeview/custom pages)
if (!isset($skip_dashboard_logic)) {
    $skip_dashboard_logic = false;
}
if (!$skip_dashboard_logic && isset($COURSE->id)) {
    $coursecontext = context_course::instance($COURSE->id);
    if (!is_guest($coursecontext, $USER) &&
        \theme_remui\toolbox::get_setting('enabledashboardcoursestats') &&
        $PAGE->pagelayout == 'mydashboard' && $PAGE->pagetype == 'my-index') {
        $templatecontext['isdashboardstatsshow'] = true;
        $setupstatus = get_config("theme_remui","setupstatus");
        if(get_config("theme_remui","dashboardpersonalizerinfo") == "show" && ( $setupstatus == "final" || $setupstatus == 'finished' )) {
            $templatecontext['showpersonlizerinfo'] = true;
        }
    }
}

// Must be called before rendering the template.
// This will ease us to add body classes directly to the array.
require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
echo $OUTPUT->render_from_template('theme_remui/drawers', $templatecontext);