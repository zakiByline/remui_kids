<?php
/**
 * Teacher Resources Page
 * Displays all teacher resources (activities and materials hidden from students)
 * I * Shows resources from all courses where the teacher is assigned
 * 
 * Note: This page validates that all displayed resources:
 * - Are not marked for deletion (deletioninprogress = 0)
 * - Have valid module instances in the database
 * - Actually exist and haven't been deleted by admin
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/url/locallib.php');
require_once($CFG->dirroot . '/theme/remui_kids/classes/local/secure_file_token.php');

if (!function_exists('theme_remui_kids_teacher_generate_file_url')) {
    /**
     * Create a short-lived, tokenised URL that can be safely embedded inside third party
     * viewers (e.g. Microsoft Office) without requiring the Moodle session cookie.
     *
     * @param \stored_file $file
     * @param int $userid
     * @return moodle_url
     */
    function theme_remui_kids_teacher_generate_file_url(\stored_file $file, int $userid): moodle_url {
        $token = \theme_remui_kids\local\secure_file_token::generate($file->get_id(), $userid);

        return new moodle_url('/theme/remui_kids/teacher/file_proxy.php', [
            'fileid' => $file->get_id(),
            'userid' => $userid,
            'expires' => $token['expires'],
            'token' => $token['token'],
        ]);
    }
}

if (!function_exists('theme_remui_kids_teacher_generate_preview_url')) {
    /**
     * Generate a preview image URL for a file (first page of PDF, video thumbnail, etc.)
     *
     * @param \stored_file $file
     * @param int $userid
     * @param string $file_extension
     * @return string|null Preview URL or null if preview not available
     */
    function theme_remui_kids_teacher_generate_preview_url(\stored_file $file, int $userid, string $file_extension): ?string {
        global $CFG;
        
        $file_extension_lower = strtolower($file_extension);
        $mimetype = $file->get_mimetype();
        
        // For images, use the file directly as preview
        if (in_array($file_extension_lower, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'bmp', 'webp']) ||
            strpos($mimetype, 'image/') === 0) {
            $file_url = theme_remui_kids_teacher_generate_file_url($file, $userid);
            return $file_url->out(false);
        }
        
        // For PDFs - use Mozilla PDF.js viewer for first page preview
        if ($file_extension_lower === 'pdf') {
            $file_url = theme_remui_kids_teacher_generate_file_url($file, $userid);
            $absolute_url = $file_url->out(false);
            // Make absolute URL if relative
            if (strpos($absolute_url, 'http') !== 0) {
                $absolute_url = $CFG->wwwroot . $absolute_url;
            }
            // Use Mozilla PDF.js viewer for PDF first page preview (better for iframes)
            // Return the file URL - JavaScript will handle rendering first page
            return $absolute_url;
        }
        
        // For videos - return file URL for client-side thumbnail extraction
        if (in_array($file_extension_lower, ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm'])) {
            $file_url = theme_remui_kids_teacher_generate_file_url($file, $userid);
            $absolute_url = $file_url->out(false);
            // Make absolute URL if relative
            if (strpos($absolute_url, 'http') !== 0) {
                $absolute_url = $CFG->wwwroot . $absolute_url;
            }
            // Return video URL - JavaScript will extract thumbnail frame
            return $absolute_url;
        }
        
        // For PowerPoint files - extract embedded thumbnail (similar to PDF.js approach for PDFs)
        if (in_array($file_extension_lower, ['ppt', 'pptx'])) {
            // Generate preview URL that will extract embedded thumbnail from PPTX file
            // PPTX files are ZIP archives containing docProps/thumbnail.jpeg or thumbnail.wmf
            $token = \theme_remui_kids\local\secure_file_token::generate($file->get_id(), $userid);
            $preview_url = new moodle_url('/theme/remui_kids/teacher/ppt_preview.php', [
                'fileid' => $file->get_id(),
                'userid' => $userid,
                'expires' => $token['expires'],
                'token' => $token['token'],
            ]);
            
            $preview_url_string = $preview_url->out(false);
            
            // Log the URL we're trying to access
            
            // Return preview URL - this will extract and serve the embedded thumbnail
            return $preview_url_string;
        }
        
        // For Word documents (DOCX) - extract first image from DOCX file
        if ($file_extension_lower === 'docx') {
            $token = \theme_remui_kids\local\secure_file_token::generate($file->get_id(), $userid);
            $preview_url = new moodle_url('/theme/remui_kids/teacher/docx_preview.php', [
                'fileid' => $file->get_id(),
                'userid' => $userid,
                'expires' => $token['expires'],
                'token' => $token['token'],
            ]);
            return $preview_url->out(false);
        }
        
        // For old DOC and ODT files - no preview available (binary format, can't extract images)
        if (in_array($file_extension_lower, ['doc', 'odt'])) {
            return null; // No preview available for old DOC format
        }
        
        // For Excel documents (XLS, XLSX) - use office preview endpoint
        if (in_array($file_extension_lower, ['xls', 'xlsx', 'ods', 'csv'])) {
            $token = \theme_remui_kids\local\secure_file_token::generate($file->get_id(), $userid);
            $preview_url = new moodle_url('/theme/remui_kids/teacher/office_preview.php', [
                'fileid' => $file->get_id(),
                'userid' => $userid,
                'expires' => $token['expires'],
                'token' => $token['token'],
            ]);
            return $preview_url->out(false);
        }
        
        // For HTML files - could use a screenshot service, but for now return null
        if (in_array($file_extension_lower, ['html', 'htm'])) {
            return null;
        }
        
        return null;
    }
}

if (!function_exists('theme_remui_kids_teacher_generate_scorm_preview_url')) {
    /**
     * Generate a preview URL for SCORM module (first SCO view)
     *
     * @param int $cmid Course module ID
     * @param int $userid User ID
     * @return string|null Preview URL or null if preview not available
     */
    function theme_remui_kids_teacher_generate_scorm_preview_url(int $cmid, int $userid): ?string {
        global $CFG, $DB;
        
        try {
            // Get SCORM instance
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
            
            // Get the first SCO (Shareable Content Object) from the SCORM package
            $scoes = $DB->get_records_select(
                'scorm_scoes',
                'scorm = ? AND ' . $DB->sql_isnotempty('scorm_scoes', 'launch', false, true),
                [$scorm->id],
                'sortorder, id',
                'id, launch, scormtype',
                0,
                1
            );
            
            if (empty($scoes)) {
                return null;
            }
            
            $sco = reset($scoes);
            
            if (empty($sco->launch)) {
                return null;
            }
            
            // Generate preview URL pointing to SCORM player with first SCO
            // This will load the first SCO in the SCORM player for preview
            $preview_url = new moodle_url('/mod/scorm/player.php', [
                'cm' => $cmid,
                'scoid' => $sco->id,
                'mode' => 'normal'
            ]);
            
            $absolute_url = $preview_url->out(false);
            // Make absolute URL if relative
            if (strpos($absolute_url, 'http') !== 0) {
                $absolute_url = $CFG->wwwroot . $absolute_url;
            }
            
            return $absolute_url;
        } catch (Exception $e) {
            return null;
        }
    }
}

// Security checks
require_login();
$context = context_system::instance();

/**
 * Get section type (Plan/Teach/Assess) from section name
 * @param string $section_name Section name (e.g., "Plan", "Plan > Unit 1", "Teach", "Assess")
 * @return string|null Section type (plan, teach, assess) or null if not found
 */
function get_section_type($section_name) {
    if (empty($section_name)) {
        return null;
    }
    
    // Extract the main section name (before " > " if it exists)
    $main_section = explode(' > ', $section_name)[0];
    $main_section_lower = strtolower(trim($main_section));
    
    // Check if section name starts with Plan, Teach, or Assess
    if ($main_section_lower === 'plan') {
        return 'plan';
    } else if ($main_section_lower === 'teach') {
        return 'teach';
    } else if ($main_section_lower === 'assess') {
        return 'assess';
    }
    
    return null;
}

// Get all courses where user is a teacher (ALL courses, not just those with teacher resources)
$userid = $USER->id;
$sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.category
        FROM {course} c
        JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
        JOIN {role_assignments} ra ON ra.contextid = ctx.id
        JOIN {role} r ON r.id = ra.roleid
        WHERE ra.userid = :userid 
        AND r.archetype = 'editingteacher'
        AND c.id != 1
        AND c.visible = 1
        ORDER BY c.fullname ASC";

$params = [
    'userid' => $userid
];

$teacher_courses = $DB->get_records_sql($sql, $params);

// If no courses found, show error
if (empty($teacher_courses)) {
    print_error('You are not assigned as a teacher in any courses.');
}

// Page setup - use system context since we're showing resources from all courses
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/teacher_resources.php');
$PAGE->set_pagelayout('base'); // Use base layout like competencies.php
$PAGE->set_title('Teacher Resources');
$PAGE->set_heading(''); // Remove default heading like competencies.php

// Get ALL activities/resources that are hidden from students from ALL teacher courses
$teacher_resources = []; // Activities hidden from students
$all_courses_data = []; // Store course data for category mapping

// Initialize resource counts (will be calculated when resources are processed)
$resource_counts = [
    'all' => 0,
    'plan' => 0,
    'teach' => 0,
    'assess' => 0
];

// Iterate through all teacher courses
foreach ($teacher_courses as $teacher_course) {
    $courseid = $teacher_course->id;
    
    // Verify user is a teacher in this course
    try {
        $coursecontext = context_course::instance($courseid);
        require_capability('moodle/course:update', $coursecontext);
    } catch (Exception $e) {
        continue; // Skip if user doesn't have permission
    }
    
    // Get course record
    $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
    if (!$course) {
        continue;
    }
    
    // Store course data for category mapping
    $all_courses_data[$courseid] = $course;
    
    // Get course sections
    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
    
    // Get modinfo for the course
    $modinfo = get_fast_modinfo($courseid);
    
    foreach ($sections as $section) {
        if ((int)$section->section === 0) {
            continue;
        }
        
        if ($section->component === 'mod_subsection') {
            continue;
        }
        
        // Check if section is hidden from students or explicitly marked as teacher resources
        $section_hidden = ((int)$section->visible === 0);
        $section_name = (string)($section->name ?? '');
        $section_display_name = $section_name !== '' ? $section_name : ('Section ' . $section->section);
        
        if ($section_hidden || 
            stripos($section_name, 'teacher resource') !== false || 
            stripos($section_name, 'teacher material') !== false ||
            stripos($section_name, 'instructor resource') !== false) {
            
            // Get all activities in this section
            if (!empty($section->sequence)) {
                $module_ids = explode(',', $section->sequence);
                foreach ($module_ids as $module_id) {
                    try {
                        $cm = $modinfo->get_cm($module_id);
                        if (!$cm) continue;
                        
                        // Skip deleted or invalid modules
                        if (!empty($cm->deletioninprogress)) continue;
                        
                        // Verify the module instance actually exists
                        $module_table = $cm->modname;
                        $module_exists = $DB->record_exists($module_table, ['id' => $cm->instance]);
                        if (!$module_exists) continue;
                        
                        // If it's a subsection, look inside it for activities
                        if ($cm->modname === 'subsection') {
                            // Get the subsection's actual section
                            $subsection = $DB->get_record('course_sections', [
                                'component' => 'mod_subsection',
                                'itemid' => $cm->instance
                            ]);
                            
                            if ($subsection && !empty($subsection->sequence)) {
                                $sub_module_ids = explode(',', $subsection->sequence);
                                foreach ($sub_module_ids as $sub_module_id) {
                                    try {
                                        $sub_cm = $modinfo->get_cm($sub_module_id);
                                        if (!$sub_cm || $sub_cm->modname === 'subsection') continue;
                                        
                                        // Skip deleted or invalid sub-modules
                                        if (!empty($sub_cm->deletioninprogress)) continue;
                                        
                                        // Verify the sub-module instance actually exists
                                        $sub_module_table = $sub_cm->modname;
                                        $sub_module_exists = $DB->record_exists($sub_module_table, ['id' => $sub_cm->instance]);
                                        if (!$sub_module_exists) continue;
                                        
                                        // Add activities from inside subsection with course ID
                                        $teacher_resources[] = [
                                            'cm' => $sub_cm,
                                            'section_name' => $section_display_name . ' > ' . ($subsection->name ?? ''),
                                            'course_id' => $courseid,
                                            'course' => $course
                                        ];
                                    } catch (Exception $e) {
                                        continue;
                                    }
                                }
                            }
                        } else {
                            // Regular activity (not a subsection) with course ID
                            $teacher_resources[] = [
                                'cm' => $cm,
                                'section_name' => $section_display_name,
                                'course_id' => $courseid,
                                'course' => $course
                            ];
                        }
                    } catch (Exception $e) {
                        // Skip invalid modules
                        continue;
                    }
                }
            }
        }
        
        // Also check for individual hidden activities in visible sections
        if (!$section_hidden && !empty($section->sequence)) {
            $module_ids = explode(',', $section->sequence);
            foreach ($module_ids as $module_id) {
                try {
                    $cm = $modinfo->get_cm($module_id);
                    if ($cm && $cm->visible == 0 && $cm->modname !== 'subsection') {
                        // Skip deleted or invalid modules
                        if (!empty($cm->deletioninprogress)) continue;
                        
                        // Verify the module instance actually exists
                        $module_table = $cm->modname;
                        $module_exists = $DB->record_exists($module_table, ['id' => $cm->instance]);
                        if (!$module_exists) continue;
                        
                        // This activity is hidden from students in a visible section with course ID
                        $teacher_resources[] = [
                            'cm' => $cm,
                            'section_name' => $section_display_name,
                            'course_id' => $courseid,
                            'course' => $course
                        ];
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }
    }
}

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

echo $OUTPUT->header();
?>

<style>
/* Hide ALL Moodle navigation and UI elements */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: visible !important;
}

/* Remove ALL gaps and spacing from page wrapper */
#page {
    margin: 0 !important;
    padding: 0 !important;
}

#page-content {
    padding: 0 !important;
    margin: 0 !important;
}

#region-main-box {
    padding: 0 !important;
    margin: 0 !important;
}

.drawers {
    margin: 0 !important;
    padding: 0 !important;
}

/* Hide Moodle navigation bars (but keep top navbar) */
.secondary-navigation,
.tertiary-navigation,
.breadcrumb,
#page-header,
.page-context-header,
.activity-navigation,
[data-region="drawer"],
.drawer-toggles {
    display: none !important;
}

/* Hide page header title area */
#page-header {
    display: none !important;
}

/* Ensure navbar and all navigation elements are visible and clickable */
.navbar,
.navbar.fixed-top,
.navbar .sub-nav {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.navbar .navbar-nav,
.primary-navigation,
.dashboard-nav-link,
.mycourses-nav-link {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.navbar .navbar-nav .nav-item,
.navbar .navbar-nav .nav-link,
.navbar .dropdown-menu,
.navbar .usermenu,
.navbar .usermenu .dropdown-menu,
.navbar .usermenu .dropdown-toggle,
.primary-navigation .moremenu,
[data-region="usermenu"],
[data-region="usermenu"] .dropdown-menu,
[data-region="usermenu"] .dropdown-toggle,
.usermenu,
.usermenu .dropdown-menu,
.usermenu .dropdown-toggle,
.navbar-nav .usermenu,
.navbar-nav .usermenu .dropdown-menu,
.primary-navigation .usermenu,
.primary-navigation .usermenu .dropdown-menu {
    visibility: visible !important;
    opacity: 1 !important;
    z-index: 1050 !important;
    position: relative !important;
}

.navbar .usermenu .dropdown-menu,
[data-region="usermenu"] .dropdown-menu,
.usermenu .dropdown-menu,
.navbar-nav .usermenu .dropdown-menu,
.primary-navigation .usermenu .dropdown-menu {
    z-index: 1060 !important;
    position: absolute !important;
    overflow: visible !important;
}

.navbar .usermenu .dropdown-toggle::after,
[data-region="usermenu"] .dropdown-toggle::after,
.usermenu .dropdown-toggle::after {
    display: inline-block !important;
}

/* Ensure no parent containers block the dropdown */
#page,
#page-wrapper,
.teacher-course-view-wrapper,
.teacher-dashboard-wrapper,
[role="navigation"],
.navbar-container {
    overflow: visible !important;
    position: relative !important;
}

/* Ensure user menu dropdown is clickable */
.navbar .usermenu,
[data-region="usermenu"],
.usermenu,
.navbar-nav .usermenu {
    position: relative !important;
    z-index: 1050 !important;
}

.navbar .usermenu .dropdown-menu,
[data-region="usermenu"] .dropdown-menu,
.usermenu .dropdown-menu {
    pointer-events: auto !important;
}

/* Ensure dropdown only shows when Bootstrap adds .show class */
.navbar .usermenu .dropdown-menu:not(.show),
[data-region="usermenu"] .dropdown-menu:not(.show),
.usermenu .dropdown-menu:not(.show) {
    display: none !important;
}

/* Ensure dropdown shows when Bootstrap adds .show class */
.navbar .usermenu .dropdown-menu.show,
[data-region="usermenu"] .dropdown-menu.show,
.usermenu .dropdown-menu.show,
#user-action-menu.show {
    display: block !important;
}

/* Ensure #user-action-menu dropdown is properly styled */
#user-action-menu,
.dropdown-menu#user-action-menu {
    z-index: 1060 !important;
    position: absolute !important;
    overflow: visible !important;
    pointer-events: auto !important;
}

#user-action-menu:not(.show) {
    display: none !important;
}

/* Ensure user menu toggle button is clickable */
#user-menu-toggle,
#user-menu-toggle.btn,
#user-menu-toggle.dropdown-toggle {
    cursor: pointer !important;
    pointer-events: auto !important;
    position: relative !important;
    z-index: 1051 !important;
}

/* Hide notification and message icons in topbar for this page only */
.navbar [data-region="notifications"],
.navbar .popover-region-notifications,
.navbar [data-region="notifications-popover"],
.navbar .nav-item[data-region="notifications"],
.navbar .notification-area,
.navbar [data-region="messages"],
.navbar .popover-region-messages,
.navbar [data-region="messages-popover"],
.navbar .nav-item[data-region="messages"],
.navbar .message-area,
.navbar .popover-region,
.navbar #nav-notification-popover-container,
.navbar #nav-message-popover-container,
.navbar .popover-region-container[data-region="notifications"],
.navbar .popover-region-container[data-region="messages"],
.navbar .nav-link[data-toggle="popover"][data-region="notifications"],
.navbar .nav-link[data-toggle="popover"][data-region="messages"],
.navbar a[href*="message"],
.navbar a[href*="notification"],
.navbar .icon-bell,
.navbar .fa-bell,
.navbar .icon-envelope,
.navbar .fa-envelope,
.navbar .edw-icon-Notification,
.navbar .edw-icon-Message {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Hide dark mode / night mode toggle in topbar for this page only */
.navbar .nav-darkmode,
.navbar .nav-item.nav-darkmode,
.navbar [data-dm],
.navbar .dm-toggle,
.navbar .darkmodeicon,
.navbar .lightmodeicon,
.navbar .edw-icon-Dark-mode,
.navbar .edw-icon-Light-mode,
.navbar a.dm-toggle {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Hide edit mode toggle in topbar for this page only */
.navbar .editingmode,
.navbar .editmode,
.navbar [data-key="editmode"],
.navbar .editmode-switch,
.navbar .editmode-toggle,
.navbar a[href*="editmode"],
.navbar a[href*="edit=on"],
.navbar a[href*="edit=off"],
.navbar .usermenu .editmode,
.navbar #usernavigation .editmode,
.navbar .navbar-nav .editmode {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Hide all user menu dropdown items except logout */
.navbar #user-action-menu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .usermenu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar [data-region="usermenu"] .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .dropdown-menu#user-action-menu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .carousel-item .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar #usermenu-carousel .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar #user-action-menu a.dropdown-item:not([href*="logout"]):not([href*="logout.php"]) {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Show logout button */
.navbar #user-action-menu .dropdown-item[href*="logout"],
.navbar #user-action-menu .dropdown-item[href*="logout.php"],
.navbar .usermenu .dropdown-item[href*="logout"],
.navbar .usermenu .dropdown-item[href*="logout.php"],
.navbar [data-region="usermenu"] .dropdown-item[href*="logout"],
.navbar [data-region="usermenu"] .dropdown-item[href*="logout.php"],
.navbar .dropdown-menu#user-action-menu .dropdown-item[href*="logout"],
.navbar .dropdown-menu#user-action-menu .dropdown-item[href*="logout.php"],
.navbar .carousel-item .dropdown-item[href*="logout"],
.navbar .carousel-item .dropdown-item[href*="logout.php"],
.navbar #usermenu-carousel .dropdown-item[href*="logout"],
.navbar #usermenu-carousel .dropdown-item[href*="logout.php"] {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    margin: 0.25rem 0 !important;
    padding: 0.5rem 1rem !important;
    pointer-events: auto !important;
}

/* Hide all dividers in user menu (they're not needed if only logout is visible) */
.navbar #user-action-menu .dropdown-divider,
.navbar .usermenu .dropdown-divider,
.navbar [data-region="usermenu"] .dropdown-divider {
    display: none !important;
}

/* Hide submenu navigation links (carousel navigation) */
.navbar #user-action-menu .carousel-navigation-link,
.navbar .usermenu .carousel-navigation-link {
    display: none !important;
}

/* Teacher Course View Wrapper */
.teacher-course-view-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
    overflow: visible !important;
}

.teacher-dashboard-wrapper {
    display: flex;
    min-height: 100vh;
}

.teacher-main-content {
    flex: 1;
    padding: 0;
    width: 100%;
}

.content-wrapper {
    margin: 0 auto;
    padding: 0 15px 30px 15px;
}

/* Dashboard Hero Section - Redesigned */
.dashboard-hero {
    background: #ffffff;
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.dashboard-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 24px;
}

.dashboard-hero-title-section {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex: 1;
}

.dashboard-hero-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    flex-shrink: 0;
}

.dashboard-hero-icon svg {
    width: 24px;
    height: 24px;
}

.dashboard-hero-copy {
    flex: 1;
    min-width: 0;
}

.dashboard-hero h1 {
    margin: 0 0 8px 0;
    font-size: 32px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
}

.dashboard-hero-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 15px;
    line-height: 1.5;
}

.dashboard-hero-stats {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

.total-resources-box {
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    border-radius: 12px;
    padding: 16px 20px;
    text-align: center;
    min-width: 100px;
}

.total-resources-number {
    font-size: 32px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1;
    margin-bottom: 4px;
}

.total-resources-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Resource Type Tabs - Removed (using tier cards instead) */

/* Search and Filters Section inside Header */
.dashboard-hero-search-filters {
    margin-top: 0;
}

.dashboard-hero-search-filters .search-filters-row {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.dashboard-hero-search-filters .search-bar-container {
    flex: 1;
    min-width: 300px;
    position: relative;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    display: flex;
    align-items: center;
    padding: 0;
    transition: all 0.2s ease;
    height: 48px;
    overflow: hidden;
}

.dashboard-hero-search-filters .search-bar-container:focus-within {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.dashboard-hero-search-filters .search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 16px;
    z-index: 1;
    pointer-events: none;
}

.dashboard-hero-search-filters .resource-search-input {
    flex: 1;
    border: none;
    outline: none;
    padding: 0 50px 0 40px;
    margin: 0;
    font-size: 15px;
    color: #1e293b;
    background: transparent;
    height: 100%;
    line-height: 48px;
    width: 100%;
}

.dashboard-hero-search-filters .resource-search-input::placeholder {
    color: #94a3b8;
}

.dashboard-hero-search-filters .clear-search-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
    width: 32px;
    height: 32px;
    z-index: 1;
}

.dashboard-hero-search-filters .clear-search-btn:hover {
    background: #f1f5f9;
    color: #64748b;
}

.dashboard-hero-search-filters .clear-search-btn i {
    font-size: 14px;
}

/* Minimal Filter Selects */
.filter-select-minimal {
    background: #f8f9fa;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 16px;
    height: 48px;
    font-size: 15px;
    color: #1e293b;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
    min-width: 160px;
    transition: all 0.2s ease;
}

.filter-select-minimal:focus {
    outline: none;
    background: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-select-minimal:hover {
    background: #ffffff;
    border-color: #cbd5e1;
}

.sections-filter-wrapper,
.folders-filter-wrapper {
    display: block;
}

.dashboard-hero-search-filters .filter-select-with-icon {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 0 12px;
    transition: all 0.2s ease;
    height: 44px;
    position: relative;
}

.dashboard-hero-search-filters .filter-select-with-icon:focus-within {
    border-color: #a78bfa;
    box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.1);
}

.dashboard-hero-search-filters .filter-select-icon {
    color: #3b82f6;
    font-size: 16px;
    flex-shrink: 0;
    display: inline-block;
    line-height: 44px;
    vertical-align: middle;
    width: 16px;
    text-align: center;
}

.dashboard-hero-search-filters .filter-select {
    border: none;
    outline: none;
    padding: 0;
    font-size: 14px;
    color: #1f2937;
    background: transparent;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 24px;
    flex: 1;
    height: 100%;
    line-height: normal;
}

.dashboard-hero-search-filters .sections-filter-wrapper,
.dashboard-hero-search-filters .folders-filter-wrapper {
    flex-shrink: 0;
}

.dashboard-hero-search-filters .sections-filter-wrapper .filter-select-with-icon,
.dashboard-hero-search-filters .folders-filter-wrapper .filter-select-with-icon {
    min-width: 200px;
}

.dashboard-hero-search-filters .sections-filter-wrapper .filter-select,
.dashboard-hero-search-filters .folders-filter-wrapper .filter-select {
    min-width: 150px;
}

/* Course Selector Dropdown - Simple */
.course-selector-wrapper {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dashboard-hero-header .course-selector-wrapper {
    min-width: 200px;
}

.selector-label {
    color: #6c757d;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
}

.selector-label i {
    font-size: 10px;
    color: #5b9bd5;
}

.course-selector {
    padding: 11px 18px;
    font-size: 14px;
    font-weight: 500;
    color: #2c3e50;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 280px;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 40px;
}

.course-selector:hover {
    border-color: #5b9bd5;
    box-shadow: 0 2px 8px rgba(91, 155, 213, 0.15);
}

.course-selector:focus {
    outline: none;
    border-color: #5b9bd5;
    box-shadow: 0 0 0 3px rgba(91, 155, 213, 0.1);
}

/* Teacher Resources View - Grid Layout with Sidebar */
.teacher-resources-container {
    background: transparent;
    border-radius: 0;
    box-shadow: none;
    overflow: visible;
    border: none;
}

.resources-header {
    background: white;
    color: #2c3e50;
    padding: 24px 30px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 24px;
    border: 1px solid #e9ecef;
}

.resources-header h2 {
    margin: 0 0 8px 0;
    font-size: 20px;
    font-weight: 600;
    color: #2c3e50;
}

.resources-header p {
    margin: 0;
    color: #6c757d;
    font-size: 13px;
}

/* Main Resources Layout - Sidebar + Content */
.resources-main-layout {
    display: flex !important;
    flex-direction: row !important;
    gap: 20px;
    align-items: flex-start;
    width: 100%;
    flex-wrap: nowrap;
    box-sizing: border-box;
}

/* Left Sidebar Filters */
.resources-sidebar {
    width: 280px;
    min-width: 280px;
    max-width: 320px;
    flex-shrink: 0;
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    padding: 20px;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    align-self: flex-start;
}

.resources-sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}

.resources-sidebar-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.resources-sidebar-title i {
    color: #3b82f6;
    font-size: 16px;
}

.clear-filters-link {
    font-size: 13px;
    color: #3b82f6;
    text-decoration: none;
    cursor: pointer;
    transition: color 0.2s ease;
    font-weight: 500;
}

.clear-filters-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.filter-section {
    margin-bottom: 24px;
}

.filter-section-title {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
    padding: 0;
    width: 100%;
}

.filter-section-title span {
    flex: 1;
    text-align: left;
}

.filter-section-title i {
    font-size: 12px;
    transition: transform 0.2s ease;
    color: #64748b;
    flex-shrink: 0;
    margin-left: 8px;
}

.filter-section-title.collapsed i {
    transform: rotate(-90deg);
}

.filter-checkbox-list {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 400px;
    overflow-y: auto;
}

.filter-checkbox-item {
    margin-bottom: 4px;
}

.filter-checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 8px 4px;
    border-radius: 6px;
    transition: background 0.15s ease;
    font-size: 14px;
    color: #334155;
    font-weight: 400;
}

.filter-checkbox-label:hover {
    background: #f8fafc;
}

/* Nested category styles */
.filter-category-children {
    list-style: none;
    padding: 0;
    margin: 4px 0 0 0;
    padding-left: 28px;
}

.filter-checkbox-label-child {
    padding-left: 0;
}

.filter-category-parent {
    position: relative;
}

.filter-checkbox {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: #3b82f6;
    margin: 0;
    flex-shrink: 0;
}

.filter-checkbox-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-left: 2px;
}

/* Resource Type Colors */
.filter-checkbox-dot.type-video { background: #3b82f6; }
.filter-checkbox-dot.type-activity { background: #10b981; }
.filter-checkbox-dot.type-lesson { background: #8b5cf6; }
.filter-checkbox-dot.type-standards { background: #06b6d4; }
.filter-checkbox-dot.type-scope { background: #14b8a6; }
.filter-checkbox-dot.type-pacing { background: #ec4899; }
.filter-checkbox-dot.type-rubric { background: #ef4444; }
.filter-checkbox-dot.type-quiz { background: #f59e0b; }
.filter-checkbox-dot.type-question { background: #1e40af; }
.filter-checkbox-dot.type-pdf { background: #dc3545; }
.filter-checkbox-dot.type-pptx { background: #fd7e14; }
.filter-checkbox-dot.type-xlsx { background: #28a745; }
.filter-checkbox-dot.type-csv { background: #28a745; }
.filter-checkbox-dot.type-docx { background: #007bff; }
.filter-checkbox-dot.type-html { background: #ec4899; }
.filter-checkbox-dot.type-images { background: #6f42c1; }
.filter-checkbox-dot.type-image { background: #6f42c1; }
.filter-checkbox-dot.type-url { background: #0ea5e9; }
.filter-checkbox-dot.type-audio { background: #20c997; }
.filter-checkbox-dot.type-archive { background: #ffc107; }
.filter-checkbox-dot.type-default { background: #6c757d; }

/* Custom scrollbar for filter sidebar */
.resources-sidebar::-webkit-scrollbar,
.filter-checkbox-list::-webkit-scrollbar {
    width: 6px;
}

.resources-sidebar::-webkit-scrollbar-track,
.filter-checkbox-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.resources-sidebar::-webkit-scrollbar-thumb,
.filter-checkbox-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.resources-sidebar::-webkit-scrollbar-thumb:hover,
.filter-checkbox-list::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.resources-content::-webkit-scrollbar {
    width: 8px;
}

.resources-content::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.resources-content::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.resources-content::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Main Content Area */
.resources-content-area {
    flex: 1 1 auto;
    min-width: 0;
    max-width: none;
    display: flex;
    flex-direction: column;
    width: 100%;
}

/* Search Bar */
.resources-search-bar {
    background: white;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    width: 100%;
}

.search-filters-row {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    flex-direction: row;
}

.search-bar-container {
    position: relative;
    flex: 1;
    min-width: 250px;
}

/* Multi-Select Dropdown Styles */
.multi-select-dropdown {
    position: relative;
    flex-shrink: 0;
}

.multi-select-button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    background: white;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 180px;
    font-weight: 500;
}

.multi-select-button:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}

.multi-select-button.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.multi-select-button i:first-child {
    color: #5b9bd5;
    font-size: 14px;
}

.multi-select-text {
    flex: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.multi-select-arrow {
    color: #64748b;
    font-size: 11px;
    transition: transform 0.2s ease;
}

.multi-select-button.active .multi-select-arrow {
    transform: rotate(180deg);
}

.multi-select-dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    min-width: 250px;
    max-width: 350px;
    max-height: 300px;
    z-index: 1000;
    overflow: hidden;
}

.multi-select-dropdown-menu.active {
    display: block;
}

.multi-select-search {
    padding: 10px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.multi-select-search input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 13px;
    background: white;
}

.multi-select-search input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.multi-select-options {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 240px;
    overflow-y: auto;
}

.multi-select-option {
    padding: 0;
    margin: 0;
}

.multi-select-option label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    cursor: pointer;
    transition: background 0.2s ease;
    font-size: 13px;
    color: #334155;
}

.multi-select-option label:hover {
    background: #f8fafc;
}

.multi-select-option input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #3b82f6;
    flex-shrink: 0;
}

.multi-select-option.hidden {
    display: none;
}

.search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 16px;
    pointer-events: none;
}

.resource-search-input {
    width: 100%;
    padding: 12px 48px 12px 48px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: #f8fafc;
}

.resource-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    background: white;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.clear-search-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    border-radius: 50%;
}

.clear-search-btn:hover {
    color: #ef4444;
    background: #fee2e2;
}

.filters-row {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 8px;
}

.filter-label-small {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.filter-label-small i {
    margin-right: 2px;
    font-size: 10px;
}

.filter-select {
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #334155;
    min-width: 140px;
}

.filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-select:hover {
    border-color: #cbd5e1;
}

/* Filter select with icon wrapper */
.filter-select-with-icon {
    position: relative;
    display: inline-block;
    min-width: 200px;
}

.filter-select-with-icon .filter-select-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #5b9bd5;
    font-size: 14px;
    pointer-events: none;
    z-index: 1;
}

.filter-select-with-icon .filter-select {
    padding-left: 40px;
    width: 100%;
}

.reset-filters-btn {
    padding: 10px 16px;
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.reset-filters-btn:hover {
    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
    border-color: #94a3b8;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.reset-filters-btn i {
    margin-right: 6px;
}

.resources-header h2 i {
    color: #ffa726;
    margin-right: 8px;
}

.resources-header p {
    margin: 0;
    color: #6c757d;
    font-size: 13px;
}

.filter-label {
    font-size: 11px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
}

.filter-label i {
    color: #5b9bd5;
    font-size: 10px;
}

.resource-type-filter {
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 500;
    color: #2c3e50;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 200px;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}

.resource-type-filter:hover {
    border-color: #5b9bd5;
    box-shadow: 0 2px 8px rgba(91, 155, 213, 0.15);
}

.resource-type-filter:focus {
    outline: none;
    border-color: #5b9bd5;
    box-shadow: 0 0 0 3px rgba(91, 155, 213, 0.1);
}

/* Resources Grid */
.resources-grid-container {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 200px);
    overflow: hidden;
}

.resources-content {
    overflow-y: auto;
    overflow-x: hidden;
    flex: 1;
    min-height: 0;
}

.resources-grid-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e9ecef;
}

.resources-grid-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.resources-grid-subtitle {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0 0;
}

.resources-count {
    font-size: 14px;
    color: #64748b;
    font-weight: 500;
}

.resources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    padding: 0;
}

/* Resource Card - New Design */
.resource-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    display: flex;
    flex-direction: column;
    position: relative;
}

.resource-card:hover {
    border-color: #5b9bd5;
    box-shadow: 0 8px 16px rgba(91, 155, 213, 0.2);
    transform: translateY(-4px);
}

.resource-card-image-container {
    width: 100%;
    height: 180px;
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    display: flex;
    align-items: center;
    justify-content: center;
}

.resource-card-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.resource-card-image-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.resource-card-image-placeholder i {
    font-size: 64px;
    color: #cbd5e1;
}

/* Preview Image Styles */
.resource-card-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.resource-card-preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    pointer-events: none;
    display: block;
    background: white;
}

.resource-card-video-thumbnail {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.resource-card-pdf-preview {
    display: block;
    background: white;
    margin: 0 auto;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.resource-card-ppt-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.resource-card-office-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.resource-card-format-tag {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}

.resource-card-format-tag i {
    font-size: 16px;
    color: #64748b;
}

/* Format tag icon colors */
.resource-card-format-tag[data-type="pdf"] i { color: #dc3545; }
.resource-card-format-tag[data-type="pptx"] i,
.resource-card-format-tag[data-type="ppt"] i { color: #fd7e14; }
.resource-card-format-tag[data-type="xlsx"] i,
.resource-card-format-tag[data-type="xls"] i,
.resource-card-format-tag[data-type="csv"] i { color: #28a745; }
.resource-card-format-tag[data-type="docx"] i,
.resource-card-format-tag[data-type="doc"] i { color: #007bff; }
.resource-card-format-tag[data-type="html"] i,
.resource-card-format-tag[data-type="videos"] i { color: #ec4899; }
.resource-card-format-tag[data-type="images"] i { color: #6f42c1; }
.resource-card-format-tag[data-type="url"] i { color: #0ea5e9; }

.resource-card-favorite {
    width: 40px;
    height: 40px;
    background: transparent;
    border: none;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
}

.resource-card-favorite:hover {
    background: #f1f5f9;
}

.resource-card-favorite i {
    color: #64748b;
    font-size: 20px;
}

.resource-card-favorite.favorited i {
    color: #8b5cf6;
}

.resource-card-favorite.favorited .fa-star-o:before {
    content: "\f005"; /* fa-star (filled) */
}

.resource-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.resource-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 12px 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.resource-card-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 12px;
}

.resource-card-tag {
    font-size: 10px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 500;
    display: inline-block;
}

/* Course tag - light blue/purple */
.resource-card-tag-course {
    color: #6366f1;
    background: #eef2ff;
}

/* Section tag - light purple/pink */
.resource-card-tag-section {
    color: #a855f7;
    background: #f3e8ff;
}

/* Folder tag - light pink */
.resource-card-tag-folder {
    color: #ec4899;
    background: #fce7f3;
}

.resource-card-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
}

.resource-card-action-btn {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.resource-card-action-btn.view-btn {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
}

.resource-card-action-btn.view-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

/* PDF - Red */
.resource-card-action-btn.view-btn[data-file-type="pdf"] {
    background: linear-gradient(135deg, #dc3545 0%,rgb(224, 57, 74) 100%);
}

.resource-card-action-btn.view-btn[data-file-type="pdf"]:hover {
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

/* PPT/PPTX - Orange */
.resource-card-action-btn.view-btn[data-file-type="ppt"],
.resource-card-action-btn.view-btn[data-file-type="pptx"] {
    background: linear-gradient(135deg, #fd7e14 0%, #e8650e 100%);
}

.resource-card-action-btn.view-btn[data-file-type="ppt"]:hover,
.resource-card-action-btn.view-btn[data-file-type="pptx"]:hover {
    background: linear-gradient(135deg, #e8650e 0%, #d4550c 100%);
    box-shadow: 0 4px 8px rgba(253, 126, 20, 0.3);
}

/* Excel/CSV - Green */
.resource-card-action-btn.view-btn[data-file-type="xls"],
.resource-card-action-btn.view-btn[data-file-type="xlsx"],
.resource-card-action-btn.view-btn[data-file-type="csv"] {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
}

.resource-card-action-btn.view-btn[data-file-type="xls"]:hover,
.resource-card-action-btn.view-btn[data-file-type="xlsx"]:hover,
.resource-card-action-btn.view-btn[data-file-type="csv"]:hover {
    background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
}

/* Word - Blue */
.resource-card-action-btn.view-btn[data-file-type="doc"],
.resource-card-action-btn.view-btn[data-file-type="docx"] {
    background: linear-gradient(135deg, #007bff 0%, #0069d9 100%);
}

.resource-card-action-btn.view-btn[data-file-type="doc"]:hover,
.resource-card-action-btn.view-btn[data-file-type="docx"]:hover {
    background: linear-gradient(135deg, #0069d9 0%, #0062cc 100%);
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
}

/* HTML/Videos - Pink */
.resource-card-action-btn.view-btn[data-file-type="html"],
.resource-card-action-btn.view-btn[data-file-type="htm"],
.resource-card-action-btn.view-btn[data-file-type="videos"] {
    background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
}

.resource-card-action-btn.view-btn[data-file-type="html"]:hover,
.resource-card-action-btn.view-btn[data-file-type="htm"]:hover,
.resource-card-action-btn.view-btn[data-file-type="videos"]:hover {
    background: linear-gradient(135deg, #db2777 0%, #c21d6f 100%);
    box-shadow: 0 4px 8px rgba(236, 72, 153, 0.3);
}

/* Images - Purple */
.resource-card-action-btn.view-btn[data-file-type="images"],
.resource-card-action-btn.view-btn[data-file-type="png"],
.resource-card-action-btn.view-btn[data-file-type="jpg"],
.resource-card-action-btn.view-btn[data-file-type="jpeg"],
.resource-card-action-btn.view-btn[data-file-type="gif"],
.resource-card-action-btn.view-btn[data-file-type="svg"],
.resource-card-action-btn.view-btn[data-file-type="bmp"],
.resource-card-action-btn.view-btn[data-file-type="webp"] {
    background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
}

.resource-card-action-btn.view-btn[data-file-type="images"]:hover,
.resource-card-action-btn.view-btn[data-file-type="png"]:hover,
.resource-card-action-btn.view-btn[data-file-type="jpg"]:hover,
.resource-card-action-btn.view-btn[data-file-type="jpeg"]:hover,
.resource-card-action-btn.view-btn[data-file-type="gif"]:hover,
.resource-card-action-btn.view-btn[data-file-type="svg"]:hover,
.resource-card-action-btn.view-btn[data-file-type="bmp"]:hover,
.resource-card-action-btn.view-btn[data-file-type="webp"]:hover {
    background: linear-gradient(135deg, #5a32a3 0%, #4c2a8f 100%);
    box-shadow: 0 4px 8px rgba(111, 66, 193, 0.3);
}

/* URL - Sky Blue */
.resource-card-action-btn.view-btn[data-file-type="url"] {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
}

.resource-card-action-btn.view-btn[data-file-type="url"]:hover {
    background: linear-gradient(135deg, #0284c7 0%, #0271a5 100%);
    box-shadow: 0 4px 8px rgba(14, 165, 233, 0.3);
}

.resource-card-action-btn.download-btn {
    background: white;
    color: #475569;
    border: 2px solid #e2e8f0;
}

.resource-card-action-btn.download-btn:hover {
    background: #f8f9fa;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}

.no-resources {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

/* Scrollbar Styling */
.resources-sidebar::-webkit-scrollbar {
    width: 6px;
}

.resources-sidebar::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.resources-sidebar::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 10px;
}

.resources-sidebar::-webkit-scrollbar-thumb:hover {
    background: #5b9bd5;
}

.filter-checkbox-list::-webkit-scrollbar {
    width: 6px;
}

.filter-checkbox-list::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.filter-checkbox-list::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 10px;
}

.filter-checkbox-list::-webkit-scrollbar-thumb:hover {
    background: #5b9bd5;
}

.no-resources i {
    font-size: 64px;
    color: #dee2e6;
    margin-bottom: 20px;
}

/* Pagination Styles */
.resources-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 32px;
    padding: 20px 0;
    flex-wrap: nowrap;
    flex-direction: row;
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
}

.pagination-info {
    font-size: 14px;
    color: #64748b;
    margin: 0 16px;
    white-space: nowrap;
    flex-shrink: 0;
}

.pagination-button {
    padding: 10px 16px;
    border: 2px solid #e2e8f0;
    background: white;
    color: #475569;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-shrink: 0;
    white-space: nowrap;
}

.pagination-button:hover:not(:disabled) {
    background: #f8f9fa;
    border-color: #5b9bd5;
    color: #1d4ed8;
}

.pagination-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f8f9fa;
}

.pagination-button.active {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border-color: #2563eb;
}

.pagination-button i {
    font-size: 12px;
}

.pagination-page-numbers {
    display: flex;
    gap: 4px;
    align-items: center;
    flex-wrap: wrap;
    flex-shrink: 1;
    min-width: 0;
    max-width: 100%;
}

.pagination-ellipsis {
    padding: 10px 8px;
    color: #64748b;
    font-weight: 600;
}

/* Folder Description Modal */
.folder-description-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9998;
    padding: 20px;
}

.folder-description-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.folder-description-content {
    background: white;
    border-radius: 12px;
    width: 100%;
    max-width: 800px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.folder-description-header {
    padding: 20px 24px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.folder-description-title {
    font-size: 20px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.folder-description-close {
    background: none;
    border: none;
    font-size: 32px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s ease;
    line-height: 1;
}

.folder-description-close:hover {
    background: #e9ecef;
    color: #2c3e50;
}

.folder-description-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
    color: #2c3e50;
    line-height: 1.6;
}

.folder-description-body img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 12px 0;
}

.folder-description-body a {
    color: #5b9bd5;
    text-decoration: none;
}

.folder-description-body a:hover {
    text-decoration: underline;
}

.folder-description-body p {
    margin: 0 0 12px 0;
}

.folder-description-body ul,
.folder-description-body ol {
    margin: 12px 0;
    padding-left: 24px;
}

.folder-description-body h1,
.folder-description-body h2,
.folder-description-body h3,
.folder-description-body h4 {
    margin: 16px 0 12px 0;
    color: #2c3e50;
}

/* PPT Player Modal */
.ppt-player-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    z-index: 9999;
    padding: 20px;
}

.ppt-player-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ppt-player-content {
    background: white;
    border-radius: 12px;
    width: 100%;
    max-width: 1200px;
    height: 80vh;
    display: flex;
    flex-direction: column;
}

.ppt-player-header {
    padding: 20px 30px;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.ppt-player-title {
    font-size: 20px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    flex: 1;
}

.ppt-player-header-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ppt-player-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ppt-player-icon-btn {
    background: none;
    border: none;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #475569;
    font-size: 18px;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
}

.ppt-player-icon-btn:hover {
    background: #e2e8f0;
    color: #1d4ed8;
}

.ppt-player-icon-btn.fullscreen-active {
    background: #1d4ed8;
    color: #fff;
}

.ppt-player-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.ppt-player-close:hover {
    background: #e9ecef;
    color: #212529;
}

.ppt-player-body {
    flex: 1;
    padding: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.ppt-player-iframe {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: 8px;
}

.ppt-spreadsheet-container {
    flex: 1;
    width: 100%;
    overflow: auto;
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    padding: 16px;
}

.ppt-spreadsheet-container table {
    width: 100%;
    border-collapse: collapse;
}

.ppt-spreadsheet-container th,
.ppt-spreadsheet-container td {
    border: 1px solid #e2e8f0;
    padding: 6px 8px;
    font-size: 13px;
}

.ppt-spreadsheet-container h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.ppt-spreadsheet-container .sheet-block {
    margin-bottom: 24px;
}

.spreadsheet-error {
    color: #b91c1c;
}

.spreadsheet-loading {
    color: #4b5563;
    font-style: italic;
}

.ppt-player-actions {
    text-align: center;
}

.ppt-download-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: none;
    padding: 12px 28px;
    border-radius: 8px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.ppt-download-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.35);
}

.ppt-download-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    box-shadow: none;
}
.teacher-dashboard-wrapper .dashboard-hero {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.75rem 2rem;
    box-shadow: 0 12px 30px rgb(15 23 42 / .08);
    background: linear-gradient(135deg, white, #f4f7f8);
    border-radius: 18px 18px 0 0;
    border-top: 0px solid #fff0;
    border-bottom: 3px solid #fff0;
    border-image: linear-gradient(90deg, #9fa1ff, #98dbfa, #92f0e5);
    border-image-slice: 1;
    margin-bottom: 1.5rem;
}
.teacher-dashboard-wrapper .dashboard-hero-header {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 2rem;
    flex-direction: row;
}
/* Teacher Help Button Styles */
.teacher-help-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
    color: #1e293b;
    padding: 10px 18px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.teacher-help-button:hover {
    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
    border-color: #94a3b8;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.teacher-help-button i {
    font-size: 16px;
    color: #1e293b;
}

.help-badge-count {
    background: #cbd5e1;
    color: #1e293b;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
    min-width: 20px;
    text-align: center;
}

/* Teacher Help Modal Styles */
.teacher-help-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 10000;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.3s ease;
}

.teacher-help-modal.active {
    display: flex;
}

.teacher-help-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    animation: slideUp 0.3s ease;
}

.teacher-help-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    border-bottom: 2px solid #e2e8f0;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    color: #1e293b;
}

.teacher-help-modal-header h2 {
    margin: 0;
    font-size: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1e293b;
}

.teacher-help-modal-close {
    background: none;
    border: none;
    font-size: 32px;
    cursor: pointer;
    color: #64748b;
    transition: all 0.3s ease;
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.teacher-help-modal-close:hover {
    transform: rotate(90deg);
    color: #1e293b;
}

.teacher-help-modal-body {
    padding: 25px;
    overflow-y: auto;
    flex: 1;
}

.teacher-help-videos-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.teacher-help-video-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.teacher-help-video-item:hover {
    background: #e9ecef;
    border-color: #667eea;
    transform: translateX(5px);
}

.teacher-help-video-item h4 {
    margin: 0 0 8px 0;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.teacher-help-video-item p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.teacher-back-to-list-btn {
    background: #667eea;
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    margin-bottom: 15px;
}

.teacher-back-to-list-btn:hover {
    background: #5568d3;
    transform: translateX(-3px);
}

/* Responsive */
@media (max-width: 768px) {
    .teacher-main-content {
        padding: 0;
    }
    
    .teacher-help-button span:not(.help-badge-count) {
        display: none;
    }
    
    .teacher-help-modal-content {
        width: 95%;
        max-height: 90vh;
    }
    
    .dashboard-hero {
        padding: 20px 16px;
    }
    
    .dashboard-hero h1 {
        font-size: 24px;
    }
    
    .dashboard-hero-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .dashboard-hero-header .header-filters {
        align-items: center;
        width: 100%;
    }
    
    .dashboard-hero-search-filters .search-filters-row {
        flex-direction: column;
        gap: 12px;
    }
    
    .dashboard-hero-search-filters .search-bar-container {
        min-width: 100%;
    }
    
    .dashboard-hero-search-filters .sections-filter-wrapper,
    .dashboard-hero-search-filters .folders-filter-wrapper {
        width: 100%;
    }
    
    .dashboard-hero-search-filters .sections-filter-wrapper .filter-select,
    .dashboard-hero-search-filters .folders-filter-wrapper .filter-select {
        min-width: 100%;
        width: 100%;
    }
}
    
    .course-selector {
        width: 100%;
        min-width: unset;
    }
    
    .resources-header {
        padding: 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .resources-header-right {
        width: 100%;
        align-items: stretch;
    }
    
    .resources-header h2 {
        font-size: 18px;
    }
    
    .resource-type-filter {
        width: 100%;
        min-width: unset;
    }
    
    /* Responsive search and filters */
    .resources-search-filter-bar {
        padding: 16px;
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-filters-row {
        gap: 12px;
    }
    
    .multi-select-button {
        width: 100%;
        min-width: 100%;
    }
    
    .multi-select-dropdown-menu {
        right: 0;
        left: 0;
        min-width: 100%;
        max-width: 100%;
    }
    
    .filters-row {
        flex-direction: column;
        gap: 12px;
        width: 100%;
    }
    
    .filter-group {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .filter-select {
        width: 100%;
        min-width: 100%;
    }
    
    .reset-filters-btn {
        width: 100%;
        padding: 12px 20px;
    }
    
    .resources-main-layout {
        flex-direction: column;
    }
    
    .resources-sidebar {
        flex: 1;
        position: relative;
        max-height: none;
        margin-bottom: 24px;
    }
    
    .resources-grid-container {
        max-height: calc(100vh - 300px);
    }
    
    .resources-grid {
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 16px;
    }
    
    .resource-card-image-container {
        height: 150px;
    }
    
    .resource-card-body {
        padding: 12px;
    }
    
    .resource-card-title {
        font-size: 14px;
    }
    
    .resource-card-action-btn {
        padding: 8px 12px;
        font-size: 12px;
    }
    
    .folder-description-modal {
        padding: 10px;
    }
    
    .folder-description-content {
        max-height: 90vh;
    }
    
    .folder-description-header {
        padding: 16px 20px;
    }
    
    .folder-description-title {
        font-size: 18px;
    }
    
    .folder-description-body {
        padding: 20px;
    }
    
    /* Pagination responsive */
    .resources-pagination {
        flex-direction: row;
        flex-wrap: nowrap;
        gap: 4px;
        padding: 15px 10px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .pagination-page-numbers {
        justify-content: center;
        flex-wrap: nowrap;
        gap: 2px;
        overflow-x: auto;
    }
    
    .pagination-button {
        padding: 8px 10px;
        font-size: 12px;
        min-width: 32px;
        flex-shrink: 0;
    }
    
    .pagination-info {
        margin: 0 8px;
        font-size: 11px;
        white-space: nowrap;
        flex-shrink: 0;
    }
}

/* Tier Cards Styles - Plan, Teach, Assess */
.tier-cards-container {
    margin-bottom: 2rem;
    width: 100%;
    padding: 1.5rem 1rem 2rem 1rem;
    position: relative;
    min-height: 180px;
    background: linear-gradient(135deg, #faf8ff 0%, #f0f4ff 50%, #fef7f0 100%);
    border: 1px solid #e8e5f3;
    border-radius: 16px;
    transition: all 0.4s ease;
    overflow: hidden;
}

.tier-cards-container.expanded {
    padding-bottom: 2rem;
}

.tier-cards-expanded-section {
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transition: max-height 0.4s ease, opacity 0.3s ease, padding 0.3s ease;
    padding: 0;
    margin-top: 1.5rem;
    border-top: 2px solid #f0e8f7;
}

.tier-cards-container.expanded .tier-cards-expanded-section {
    max-height: 1000px;
    opacity: 1;
    padding: 1.5rem 0 0 0;
}

.category-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    width: 100%;
}

.category-card {
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    position: relative;
    padding: 1.5rem;
    transition: all 0.3s ease;
    cursor: pointer;
    min-height: 120px;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    overflow: visible;
}

/* Remove corner brackets */
.category-card::before,
.category-card::after {
    display: none;
}

.category-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Category Card Icon */
.category-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

/* Category Card Content */
.category-card-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.category-card-checkbox {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 2px solid #cbd5e1;
    background: #ffffff;
    cursor: pointer;
    opacity: 1;
    pointer-events: auto;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    margin: 0;
}

.category-card-checkbox:checked {
    background: #9333ea;
    border-color: #9333ea;
}

.category-card-checkbox:checked::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    line-height: 1;
    display: block;
}

.category-card-label {
    display: flex;
    align-items: flex-start;
    width: 100%;
    cursor: pointer;
    position: relative;
    z-index: 2;
    gap: 1rem;
}

.category-card-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    line-height: 1.3;
}

.category-card-description {
    font-size: 0.875rem;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
    display: block;
}

/* Course Cards Grid - Same format as category cards */
.course-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    width: 100%;
    margin-bottom: 2rem;
}

/* Course cards - Similar to category cards with grey border */
.course-card {
    position: relative;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    min-height: 120px;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    user-select: none;
    -webkit-user-select: none;
    overflow: visible;
}

/* Remove corner brackets for course cards */
.course-card::before,
.course-card::after {
    display: none;
}

/* Course Card Icon - Color themes for different subjects */
.course-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

/* English - Pink theme */
.course-card[data-course-name*="English" i] .course-card-icon,
.course-card[data-course-name*="english" i] .course-card-icon {
    background: #fce7f3;
    color: #ec4899;
}

.course-card[data-course-name*="English" i],
.course-card[data-course-name*="english" i] {
    border-color: #fbcfe8;
}

.course-card[data-course-name*="English" i].checked,
.course-card[data-course-name*="english" i].checked {
    border: 3px solid #ec4899;
    background: #fdf2f8;
}

.course-card[data-course-name*="English" i].checked .course-card-checkbox,
.course-card[data-course-name*="english" i].checked .course-card-checkbox {
    background: #ec4899 !important;
    border-color: #ec4899 !important;
}

/* Maths - Blue theme */
.course-card[data-course-name*="Math" i] .course-card-icon,
.course-card[data-course-name*="math" i] .course-card-icon {
    background: #dbeafe;
    color: #2563eb;
}

.course-card[data-course-name*="Math" i],
.course-card[data-course-name*="math" i] {
    border-color: #bfdbfe;
}

.course-card[data-course-name*="Math" i].checked,
.course-card[data-course-name*="math" i].checked {
    border: 3px solid #2563eb;
    background: #eff6ff;
}

.course-card[data-course-name*="Math" i].checked .course-card-checkbox,
.course-card[data-course-name*="math" i].checked .course-card-checkbox {
    background: #2563eb !important;
    border-color: #2563eb !important;
}

/* Science - Green theme */
.course-card[data-course-name*="Science" i] .course-card-icon,
.course-card[data-course-name*="science" i] .course-card-icon {
    background: #d1fae5;
    color: #059669;
}

.course-card[data-course-name*="Science" i],
.course-card[data-course-name*="science" i] {
    border-color: #a7f3d0;
}

.course-card[data-course-name*="Science" i].checked,
.course-card[data-course-name*="science" i].checked {
    border: 3px solid #059669;
    background: #ecfdf5;
}

.course-card[data-course-name*="Science" i].checked .course-card-checkbox,
.course-card[data-course-name*="science" i].checked .course-card-checkbox {
    background: #059669 !important;
    border-color: #059669 !important;
}

/* Grade Cards - Different colors for each grade (1-12) */
/* Grade 1 - Red */
.course-card[data-course-name*="Grade 1" i] .course-card-icon,
.course-card[data-course-name*="grade 1" i] .course-card-icon {
    background: #fee2e2;
    color: #dc2626;
}
.course-card[data-course-name*="Grade 1" i],
.course-card[data-course-name*="grade 1" i] {
    border-color: #fecaca;
}
.course-card[data-course-name*="Grade 1" i].checked,
.course-card[data-course-name*="grade 1" i].checked {
    border: 3px solid #dc2626;
    background: #fef2f2;
}
.course-card[data-course-name*="Grade 1" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 1" i].checked .course-card-checkbox {
    background: #dc2626 !important;
    border-color: #dc2626 !important;
}

/* Grade 2 - Orange */
.course-card[data-course-name*="Grade 2" i] .course-card-icon,
.course-card[data-course-name*="grade 2" i] .course-card-icon {
    background: #fed7aa;
    color: #ea580c;
}
.course-card[data-course-name*="Grade 2" i],
.course-card[data-course-name*="grade 2" i] {
    border-color: #fdba74;
}
.course-card[data-course-name*="Grade 2" i].checked,
.course-card[data-course-name*="grade 2" i].checked {
    border: 3px solid #ea580c;
    background: #fff7ed;
}
.course-card[data-course-name*="Grade 2" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 2" i].checked .course-card-checkbox {
    background: #ea580c !important;
    border-color: #ea580c !important;
}

/* Grade 3 - Amber */
.course-card[data-course-name*="Grade 3" i] .course-card-icon,
.course-card[data-course-name*="grade 3" i] .course-card-icon {
    background: #fef3c7;
    color: #d97706;
}
.course-card[data-course-name*="Grade 3" i],
.course-card[data-course-name*="grade 3" i] {
    border-color: #fde68a;
}
.course-card[data-course-name*="Grade 3" i].checked,
.course-card[data-course-name*="grade 3" i].checked {
    border: 3px solid #d97706;
    background: #fffbeb;
}
.course-card[data-course-name*="Grade 3" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 3" i].checked .course-card-checkbox {
    background: #d97706 !important;
    border-color: #d97706 !important;
}

/* Grade 4 - Yellow */
.course-card[data-course-name*="Grade 4" i] .course-card-icon,
.course-card[data-course-name*="grade 4" i] .course-card-icon {
    background: #fef9c3;
    color: #ca8a04;
}
.course-card[data-course-name*="Grade 4" i],
.course-card[data-course-name*="grade 4" i] {
    border-color: #fde047;
}
.course-card[data-course-name*="Grade 4" i].checked,
.course-card[data-course-name*="grade 4" i].checked {
    border: 3px solid #ca8a04;
    background: #fefce8;
}
.course-card[data-course-name*="Grade 4" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 4" i].checked .course-card-checkbox {
    background: #ca8a04 !important;
    border-color: #ca8a04 !important;
}

/* Grade 5 - Lime */
.course-card[data-course-name*="Grade 5" i] .course-card-icon,
.course-card[data-course-name*="grade 5" i] .course-card-icon {
    background: #ecfccb;
    color: #65a30d;
}
.course-card[data-course-name*="Grade 5" i],
.course-card[data-course-name*="grade 5" i] {
    border-color: #d9f99d;
}
.course-card[data-course-name*="Grade 5" i].checked,
.course-card[data-course-name*="grade 5" i].checked {
    border: 3px solid #65a30d;
    background: #f7fee7;
}
.course-card[data-course-name*="Grade 5" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 5" i].checked .course-card-checkbox {
    background: #65a30d !important;
    border-color: #65a30d !important;
}

/* Grade 6 - Green */
.course-card[data-course-name*="Grade 6" i] .course-card-icon,
.course-card[data-course-name*="grade 6" i] .course-card-icon {
    background: #d1fae5;
    color: #059669;
}
.course-card[data-course-name*="Grade 6" i],
.course-card[data-course-name*="grade 6" i] {
    border-color: #a7f3d0;
}
.course-card[data-course-name*="Grade 6" i].checked,
.course-card[data-course-name*="grade 6" i].checked {
    border: 3px solid #059669;
    background: #ecfdf5;
}
.course-card[data-course-name*="Grade 6" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 6" i].checked .course-card-checkbox {
    background: #059669 !important;
    border-color: #059669 !important;
}

/* Grade 7 - Teal */
.course-card[data-course-name*="Grade 7" i] .course-card-icon,
.course-card[data-course-name*="grade 7" i] .course-card-icon {
    background: #ccfbf1;
    color: #0d9488;
}
.course-card[data-course-name*="Grade 7" i],
.course-card[data-course-name*="grade 7" i] {
    border-color: #99f6e4;
}
.course-card[data-course-name*="Grade 7" i].checked,
.course-card[data-course-name*="grade 7" i].checked {
    border: 3px solid #0d9488;
    background: #f0fdfa;
}
.course-card[data-course-name*="Grade 7" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 7" i].checked .course-card-checkbox {
    background: #0d9488 !important;
    border-color: #0d9488 !important;
}

/* Grade 8 - Cyan */
.course-card[data-course-name*="Grade 8" i] .course-card-icon,
.course-card[data-course-name*="grade 8" i] .course-card-icon {
    background: #cffafe;
    color: #0891b2;
}
.course-card[data-course-name*="Grade 8" i],
.course-card[data-course-name*="grade 8" i] {
    border-color: #a5f3fc;
}
.course-card[data-course-name*="Grade 8" i].checked,
.course-card[data-course-name*="grade 8" i].checked {
    border: 3px solid #0891b2;
    background: #ecfeff;
}
.course-card[data-course-name*="Grade 8" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 8" i].checked .course-card-checkbox {
    background: #0891b2 !important;
    border-color: #0891b2 !important;
}

/* Grade 9 - Blue */
.course-card[data-course-name*="Grade 9" i] .course-card-icon,
.course-card[data-course-name*="grade 9" i] .course-card-icon {
    background: #dbeafe;
    color: #2563eb;
}
.course-card[data-course-name*="Grade 9" i],
.course-card[data-course-name*="grade 9" i] {
    border-color: #bfdbfe;
}
.course-card[data-course-name*="Grade 9" i].checked,
.course-card[data-course-name*="grade 9" i].checked {
    border: 3px solid #2563eb;
    background: #eff6ff;
}
.course-card[data-course-name*="Grade 9" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 9" i].checked .course-card-checkbox {
    background: #2563eb !important;
    border-color: #2563eb !important;
}

/* Grade 10 - Indigo */
.course-card[data-course-name*="Grade 10" i] .course-card-icon,
.course-card[data-course-name*="grade 10" i] .course-card-icon {
    background: #e0e7ff;
    color: #4f46e5;
}
.course-card[data-course-name*="Grade 10" i],
.course-card[data-course-name*="grade 10" i] {
    border-color: #c7d2fe;
}
.course-card[data-course-name*="Grade 10" i].checked,
.course-card[data-course-name*="grade 10" i].checked {
    border: 3px solid #4f46e5;
    background: #eef2ff;
}
.course-card[data-course-name*="Grade 10" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 10" i].checked .course-card-checkbox {
    background: #4f46e5 !important;
    border-color: #4f46e5 !important;
}

/* Grade 11 - Purple */
.course-card[data-course-name*="Grade 11" i] .course-card-icon,
.course-card[data-course-name*="grade 11" i] .course-card-icon {
    background: #e9d5ff;
    color: #9333ea;
}
.course-card[data-course-name*="Grade 11" i],
.course-card[data-course-name*="grade 11" i] {
    border-color: #ddd6fe;
}
.course-card[data-course-name*="Grade 11" i].checked,
.course-card[data-course-name*="grade 11" i].checked {
    border: 3px solid #9333ea;
    background: #faf5ff;
}
.course-card[data-course-name*="Grade 11" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 11" i].checked .course-card-checkbox {
    background: #9333ea !important;
    border-color: #9333ea !important;
}

/* Grade 12 - Pink */
.course-card[data-course-name*="Grade 12" i] .course-card-icon,
.course-card[data-course-name*="grade 12" i] .course-card-icon {
    background: #fce7f3;
    color: #ec4899;
}
.course-card[data-course-name*="Grade 12" i],
.course-card[data-course-name*="grade 12" i] {
    border-color: #fbcfe8;
}
.course-card[data-course-name*="Grade 12" i].checked,
.course-card[data-course-name*="grade 12" i].checked {
    border: 3px solid #ec4899;
    background: #fdf2f8;
}
.course-card[data-course-name*="Grade 12" i].checked .course-card-checkbox,
.course-card[data-course-name*="grade 12" i].checked .course-card-checkbox {
    background: #ec4899 !important;
    border-color: #ec4899 !important;
}

.course-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.course-card .category-card-label {
    position: relative;
    z-index: 10;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.course-card .category-card-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    line-height: 1.3;
}

.course-card .category-card-description {
    font-size: 0.875rem;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
    display: block;
}

/* Course card checkbox - Default grey, changes based on subject when checked */
.course-card .category-card-checkbox {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 2px solid #cbd5e1;
    background: #ffffff;
    cursor: pointer;
    opacity: 1;
    pointer-events: auto;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    margin: 0;
}

.course-card .category-card-checkbox:checked::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

.course-card.checked {
    /* Border and background colors are set per subject above */
}

/* Course Category Header */
.course-category-header {
    font-size: 1.2rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.5rem;
    margin-top: 1rem;
}

.course-category-header:first-child {
    margin-top: 0;
}

/* Course Category Separator */
.course-category-separator {
    height: 1px;
    background: #e5e7eb;
    margin-bottom: 1rem;
    width: 100%;
}

/* Resource Category Course Container */
.resource-category-course-container {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.resource-category-course-items {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.resource-category-courses-empty {
    text-align: center;
    color: #64748b;
    font-size: 0.95rem;
    padding: 2rem;
}

/* KG Level 1 - Teal/Blue-Green Theme */
.category-card[data-category-name*="Level 1"],
.category-card[data-category-name*="level 1"] {
    border-color: #14b8a6;
}

.category-card[data-category-name*="Level 1"] .category-card-icon,
.category-card[data-category-name*="level 1"] .category-card-icon {
    background: #ccfbf1;
    color: #0d9488;
}

.category-card[data-category-name*="Level 1"].checked,
.category-card[data-category-name*="level 1"].checked {
    border: 3px solid #14b8a6;
    background: #f0fdfa;
}

.category-card[data-category-name*="Level 1"].checked .category-card-checkbox,
.category-card[data-category-name*="level 1"].checked .category-card-checkbox {
    background: #14b8a6 !important;
    border-color: #14b8a6 !important;
}

.category-card[data-category-name*="Level 1"].checked .category-card-checkbox::after,
.category-card[data-category-name*="level 1"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

/* KG Level 2 - Purple Theme */
.category-card[data-category-name*="Level 2"],
.category-card[data-category-name*="level 2"] {
    border-color: #9333ea;
}

.category-card[data-category-name*="Level 2"] .category-card-icon,
.category-card[data-category-name*="level 2"] .category-card-icon {
    background: #e9d5ff;
    color: #9333ea;
}

.category-card[data-category-name*="Level 2"].checked,
.category-card[data-category-name*="level 2"].checked {
    border: 3px solid #9333ea;
    background: #faf5ff;
}

.category-card[data-category-name*="Level 2"].checked .category-card-checkbox,
.category-card[data-category-name*="level 2"].checked .category-card-checkbox {
    background: #9333ea !important;
    border-color: #9333ea !important;
}

.category-card[data-category-name*="Level 2"].checked .category-card-checkbox::after,
.category-card[data-category-name*="level 2"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

/* KG Level 3 - Pink Theme */
.category-card[data-category-name*="Level 3"],
.category-card[data-category-name*="level 3"] {
    border-color: #ec4899;
}

.category-card[data-category-name*="Level 3"] .category-card-icon,
.category-card[data-category-name*="level 3"] .category-card-icon {
    background: #fce7f3;
    color: #ec4899;
}

.category-card[data-category-name*="Level 3"].checked,
.category-card[data-category-name*="level 3"].checked {
    border: 3px solid #ec4899;
    background: #fdf2f8;
}

.category-card[data-category-name*="Level 3"].checked .category-card-checkbox,
.category-card[data-category-name*="level 3"].checked .category-card-checkbox {
    background: #ec4899 !important;
    border-color: #ec4899 !important;
}

.category-card[data-category-name*="Level 3"].checked .category-card-checkbox::after,
.category-card[data-category-name*="level 3"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

/* Foundation - Teal/Blue-Green Theme (similar to Level 1) */
.category-card[data-category-name*="Foundation" i],
.category-card[data-category-name*="foundation"] {
    border-color: #14b8a6;
}

.category-card[data-category-name*="Foundation" i] .category-card-icon,
.category-card[data-category-name*="foundation"] .category-card-icon {
    background: #ccfbf1;
    color: #0d9488;
}

.category-card[data-category-name*="Foundation" i].checked,
.category-card[data-category-name*="foundation"].checked {
    border: 3px solid #14b8a6;
    background: #f0fdfa;
}

.category-card[data-category-name*="Foundation" i].checked .category-card-checkbox,
.category-card[data-category-name*="foundation"].checked .category-card-checkbox {
    background: #14b8a6 !important;
    border-color: #14b8a6 !important;
}

.category-card[data-category-name*="Foundation" i].checked .category-card-checkbox::after,
.category-card[data-category-name*="foundation"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

/* Intermediate - Purple Theme (similar to Level 2) */
.category-card[data-category-name*="Intermediate" i],
.category-card[data-category-name*="intermediate"] {
    border-color: #9333ea;
}

.category-card[data-category-name*="Intermediate" i] .category-card-icon,
.category-card[data-category-name*="intermediate"] .category-card-icon {
    background: #e9d5ff;
    color: #9333ea;
}

.category-card[data-category-name*="Intermediate" i].checked,
.category-card[data-category-name*="intermediate"].checked {
    border: 3px solid #9333ea;
    background: #faf5ff;
}

.category-card[data-category-name*="Intermediate" i].checked .category-card-checkbox,
.category-card[data-category-name*="intermediate"].checked .category-card-checkbox {
    background: #9333ea !important;
    border-color: #9333ea !important;
}

.category-card[data-category-name*="Intermediate" i].checked .category-card-checkbox::after,
.category-card[data-category-name*="intermediate"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

/* Advanced - Pink Theme (similar to Level 3) */
.category-card[data-category-name*="Advanced" i],
.category-card[data-category-name*="advanced"] {
    border-color: #ec4899;
}

.category-card[data-category-name*="Advanced" i] .category-card-icon,
.category-card[data-category-name*="advanced"] .category-card-icon {
    background: #fce7f3;
    color: #ec4899;
}

.category-card[data-category-name*="Advanced" i].checked,
.category-card[data-category-name*="advanced"].checked {
    border: 3px solid #ec4899;
    background: #fdf2f8;
}

.category-card[data-category-name*="Advanced" i].checked .category-card-checkbox,
.category-card[data-category-name*="advanced"].checked .category-card-checkbox {
    background: #ec4899 !important;
    border-color: #ec4899 !important;
}

.category-card[data-category-name*="Advanced" i].checked .category-card-checkbox::after,
.category-card[data-category-name*="advanced"].checked .category-card-checkbox::after {
    content: '\2713';
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: bold;
    display: block;
}

.tier-cards-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr;
    gap: 1rem;
    align-items: flex-start;
    justify-items: center;
    width: 100%;
    max-width: 1800px;
    margin: 0 auto;
    padding-bottom: 1rem;
    position: relative;
    z-index: 2;
}

/* Flow Arrow Between Cards */
.tier-card-arrow-between {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 100%;
    flex-shrink: 0;
    position: relative;
}

.flow-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, #e8e5f3 0%, #d4c5e8 50%, #e8e5f3 100%);
    position: relative;
    transition: all 0.3s ease;
}

.flow-arrow::after {
    content: '';
    position: absolute;
    right: -8px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-left: 8px solid #d4c5e8;
    border-top: 6px solid transparent;
    border-bottom: 6px solid transparent;
    transition: all 0.3s ease;
}

.flow-arrow:hover {
    background: linear-gradient(90deg, #b8a9d9 0%, #a599d1 50%, #b8a9d9 100%);
}

.flow-arrow:hover::after {
    border-left-color: #a599d1;
    right: -10px;
}

/* Tier Card Base Styles */
.tier-card {
    background: #ffffff;
    border: 2px solid #e8e5f3;
    border-radius: 12px;
    position: relative;
    padding: 1.5rem;
    transition: all 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
    cursor: pointer;
    min-width: 280px;
    max-width: 320px;
    width: 100%;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    overflow: visible;
}

/* Remove corner brackets - using simple rounded corners */
.tier-card::before,
.tier-card::after {
    display: none;
}

/* All Resources Card - Pink theme */
.tier-card-all {
    border-color: #fbcfe8;
}

.tier-card-all .tier-card-icon {
    background: #fce7f3;
    color: #ec4899;
}

.tier-card-all .tier-card-count-badge {
    background: #fce7f3;
    color: #ec4899;
}

/* Planning Card - Purple theme */
.tier-card-planning {
    border-color: #e0d4f7;
}

.tier-card-planning .tier-card-icon {
    background: #e9d5ff;
    color: #9333ea;
}

.tier-card-planning .tier-card-count-badge {
    background: #e9d5ff;
    color: #9333ea;
}

/* Resources/Teach Card - Teal theme */
.tier-card-resources {
    border-color: #b2f5ea;
}

.tier-card-resources .tier-card-icon {
    background: #e0f2f1;
    color: #0d9488;
}

.tier-card-resources .tier-card-count-badge {
    background: #e0f2f1;
    color: #0d9488;
}

/* Assessments Card - Orange theme */
.tier-card-assessments {
    border-color: #fed7aa;
}

.tier-card-assessments .tier-card-icon {
    background: #ffedd5;
    color: #ea580c;
}

.tier-card-assessments .tier-card-count-badge {
    background: #ffedd5;
    color: #ea580c;
}

/* Hover Effects */
.tier-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Active State - Default (for Plan) - Purple theme */
.tier-card.active {
    border: 3px solid #9333ea !important;
    background: #faf5ff !important;
    box-shadow: 0 4px 12px rgba(147, 51, 234, 0.15);
    z-index: 10;
    position: relative;
}

.tier-card.active .tier-card-icon {
    background: #e9d5ff !important;
    color: #9333ea !important;
}

.tier-card.active .tier-card-count-badge {
    background: #e9d5ff !important;
    color: #9333ea !important;
}

.tier-card.active .tier-card-checkmark {
    background: #9333ea !important;
}

/* All Resources - Pink theme when active */
.tier-card-all.active {
    border: 3px solid #ec4899 !important;
    background: #fdf2f8 !important;
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.15);
}

.tier-card-all.active .tier-card-icon {
    background: #fce7f3 !important;
    color: #ec4899 !important;
}

.tier-card-all.active .tier-card-count-badge {
    background: #fce7f3 !important;
    color: #ec4899 !important;
}

.tier-card-all.active .tier-card-checkmark {
    background: #ec4899 !important;
}

/* Teach/Resources - Green theme when active */
.tier-card-resources.active {
    border: 3px solid #059669 !important;
    background: #ecfdf5 !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
}

.tier-card-resources.active .tier-card-icon {
    background: #d1fae5 !important;
    color: #059669 !important;
}

.tier-card-resources.active .tier-card-count-badge {
    background: #d1fae5 !important;
    color: #059669 !important;
}

.tier-card-resources.active .tier-card-checkmark {
    background: #059669 !important;
}

/* Assess - Yellow/Orange theme when active */
.tier-card-assessments.active {
    border: 3px solid #f59e0b !important;
    background: #fffbeb !important;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
}

.tier-card-assessments.active .tier-card-icon {
    background: #fef3c7 !important;
    color: #f59e0b !important;
}

.tier-card-assessments.active .tier-card-count-badge {
    background: #fef3c7 !important;
    color: #f59e0b !important;
}

.tier-card-assessments.active .tier-card-checkmark {
    background: #f59e0b !important;
}


/* Card Content Layout */
.tier-card-content {
    display: flex;
    align-items: flex-start;
    gap: 1.5rem;
    padding: 0;
    min-height: auto;
    background: transparent;
    border-radius: 0;
    position: relative;
    z-index: 2;
}

/* Icon Section */
.tier-card-icon-section {
    flex-shrink: 0;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 0;
}

/* Remove divider line */
.tier-card-divider {
    display: none;
}

/* Icon - Square with rounded corners */
.tier-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    transition: all 0.3s ease;
    border: none;
    background: #e9d5ff;
    color: #9333ea;
    box-shadow: none;
}

.tier-card-0 .tier-card-icon {
    background: #f1f5f9;
    color: #64748b;
}

.tier-card-1 .tier-card-icon {
    background: #e9d5ff;
    color: #9333ea;
}

.tier-card-2 .tier-card-icon {
    background: #e0f2f1;
    color: #0d9488;
}

.tier-card-3 .tier-card-icon {
    background: #ffedd5;
    color: #ea580c;
}

.tier-card:hover .tier-card-icon {
    transform: scale(1.05);
}

/* Content Section */
.tier-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding-top: 0;
}

.tier-card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    line-height: 1.3;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tier-card-description {
    display: block;
    font-size: 0.875rem;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
}

.tier-card-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
    margin-top: 0.5rem;
    width: fit-content;
}

/* Checkmark - Only visible when card is active */
.tier-card-checkmark {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #9333ea;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10;
    box-shadow: 0 2px 4px rgba(147, 51, 234, 0.2);
}

.tier-card-checkmark i {
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: bold;
}

.tier-card.active .tier-card-checkmark {
    display: flex;
}

.tier-card-count {
    font-weight: 600;
}

/* Responsive */
@media (max-width: 1400px) {
    .tier-cards-grid {
        grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr;
        gap: 0.75rem;
    }
    
    .tier-card {
        min-width: 180px;
        max-width: 220px;
        padding: 1rem 1rem 1rem 0.75rem;
    }
    
    .tier-card-icon {
        width: 45px;
        height: 45px;
        font-size: 1.25rem;
    }
}

@media (max-width: 1200px) {
    .tier-cards-grid {
        grid-template-columns: 1fr auto 1fr auto 1fr;
        gap: 0.75rem;
    }
    
    .tier-card {
        min-width: 200px;
        max-width: 240px;
    }
}

@media (max-width: 968px) {
    .tier-cards-container {
        padding: 1.5rem 1rem 2rem 1rem;
    }
    
    .tier-cards-grid {
        grid-template-columns: 1fr;
        gap: 2rem;
    }
    
    .tier-card-arrow-between {
        display: none;
    }
    
    .tier-card {
        min-width: 100%;
        max-width: 100%;
    }
}

/* Hide notification and message icons in topbar for this page only */
.navbar [data-region="notifications"],
.navbar .popover-region-notifications,
.navbar [data-region="notifications-popover"],
.navbar .nav-item[data-region="notifications"],
.navbar .notification-area,
.navbar [data-region="messages"],
.navbar .popover-region-messages,
.navbar [data-region="messages-popover"],
.navbar .nav-item[data-region="messages"],
.navbar .message-area,
.navbar .popover-region,
.navbar #nav-notification-popover-container,
.navbar #nav-message-popover-container,
.navbar .popover-region-container[data-region="notifications"],
.navbar .popover-region-container[data-region="messages"],
.navbar .nav-link[data-toggle="popover"][data-region="notifications"],
.navbar .nav-link[data-toggle="popover"][data-region="messages"],
.navbar a[href*="message"],
.navbar a[href*="notification"],
.navbar .icon-bell,
.navbar .fa-bell,
.navbar .icon-envelope,
.navbar .fa-envelope,
.navbar .edw-icon-Notification,
.navbar .edw-icon-Message {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Hide all user menu dropdown items except logout */
.navbar #user-action-menu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .usermenu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar [data-region="usermenu"] .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .dropdown-menu#user-action-menu .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar .carousel-item .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar #usermenu-carousel .dropdown-item:not([href*="logout"]):not([href*="logout.php"]),
.navbar #user-action-menu a.dropdown-item:not([href*="logout"]):not([href*="logout.php"]) {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Show logout button */
.navbar #user-action-menu .dropdown-item[href*="logout"],
.navbar #user-action-menu .dropdown-item[href*="logout.php"],
.navbar .usermenu .dropdown-item[href*="logout"],
.navbar .usermenu .dropdown-item[href*="logout.php"],
.navbar [data-region="usermenu"] .dropdown-item[href*="logout"],
.navbar [data-region="usermenu"] .dropdown-item[href*="logout.php"],
.navbar .dropdown-menu#user-action-menu .dropdown-item[href*="logout"],
.navbar .dropdown-menu#user-action-menu .dropdown-item[href*="logout.php"],
.navbar .carousel-item .dropdown-item[href*="logout"],
.navbar .carousel-item .dropdown-item[href*="logout.php"],
.navbar #usermenu-carousel .dropdown-item[href*="logout"],
.navbar #usermenu-carousel .dropdown-item[href*="logout.php"] {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    margin: 0.25rem 0 !important;
    padding: 0.5rem 1rem !important;
    pointer-events: auto !important;
}

/* Hide all dividers in user menu (they're not needed if only logout is visible) */
.navbar #user-action-menu .dropdown-divider,
.navbar .usermenu .dropdown-divider,
.navbar [data-region="usermenu"] .dropdown-divider {
    display: none !important;
}

/* Hide submenu navigation links (carousel navigation) */
.navbar #user-action-menu .carousel-navigation-link,
.navbar .usermenu .carousel-navigation-link {
    display: none !important;
}
</style>

<div class="teacher-course-view-wrapper">
    <div class="teacher-dashboard-wrapper">
        
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>

        <!-- Main Content with Sidebar -->
        <div class="teacher-main-content">
            <!-- Dashboard Hero Section - Redesigned -->
            <div class="dashboard-hero">
                <div class="dashboard-hero-header">
                    <div class="dashboard-hero-title-section">
                        
                        <div class="dashboard-hero-copy">
                            <h1>Teaching Resources</h1>
                            <p class="dashboard-hero-subtitle">Discover curated materials for planning, teaching, and assessing across all curriculum levels.</p>
                        </div>
                    </div>
                    <div class="dashboard-hero-stats">
                        <?php if ($has_help_videos): ?>
                        <a class="teacher-help-button" id="teacherHelpButton" style="text-decoration: none; display: inline-flex;">
                            <i class="fa fa-question-circle"></i>
                            <span>Need Help?</span>
                            <span class="help-badge-count"><?php echo $help_videos_count; ?></span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Navigation Tier Cards - All Resources, Plan, Teach, Assess -->
                <div class="tier-cards-container">
                    <div class="tier-cards-grid">
                        <!-- Step 0 Card - All Resources -->
                        <div class="tier-card tier-card-0 tier-card-all" data-tab="all" onclick="filterByResourceType('all')">
                            <div class="tier-card-content">
                                <!-- Icon on Left -->
                                <div class="tier-card-icon-section">
                                    <div class="tier-card-icon icon-all">
                        <i class="fa fa-th"></i>
                                    </div>
                                </div>
                                
                                <!-- Vertical Divider Line -->
                                <div class="tier-card-divider"></div>
                                
                                <!-- Content Section -->
                                <div class="tier-card-body">
                                    <h3 class="tier-card-title">All Resources</h3>
                                    <p class="tier-card-description">Browse all available teaching resources across all curriculum levels and themes.</p>
                                    <div class="tier-card-count-badge">
                                        <span class="tier-card-count" id="tierCardCountAll">0</span> <span>resources</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Checkmark for active state -->
                            <div class="tier-card-checkmark">
                                <i class="fa fa-check"></i>
                            </div>
                        </div>

                        <!-- Arrow Between Cards -->
                        <div class="tier-card-arrow-between">
                            <div class="flow-arrow"></div>
                        </div>

                        <!-- Step 1 Card - Planning -->
                        <div class="tier-card tier-card-1 tier-card-planning" data-tab="planning" onclick="filterByResourceType('plan')">
                            <div class="tier-card-content">
                                <!-- Icon on Left -->
                                <div class="tier-card-icon-section">
                                    <div class="tier-card-icon icon-planning">
                                        <i class="fa fa-lightbulb"></i>
                                    </div>
                                </div>
                                
                                <!-- Vertical Divider Line -->
                                <div class="tier-card-divider"></div>
                                
                                <!-- Content Section -->
                                <div class="tier-card-body">
                                    <h3 class="tier-card-title">Plan</h3>
                                    <p class="tier-card-description">Organize your lesson plans and curriculum to create engaging educational content.</p>
                                    <div class="tier-card-count-badge">
                                        <span class="tier-card-count" id="tierCardCountPlan">0</span> <span>resources</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Checkmark for active state -->
                            <div class="tier-card-checkmark">
                                <i class="fa fa-check"></i>
                            </div>
                        </div>

                        <!-- Arrow Between Cards -->
                        <div class="tier-card-arrow-between">
                            <div class="flow-arrow"></div>
                        </div>

                        <!-- Step 2 Card - Teaching -->
                        <div class="tier-card tier-card-2 tier-card-resources" data-tab="resources" onclick="filterByResourceType('teach')">
                            <div class="tier-card-content">
                                <!-- Icon on Left -->
                                <div class="tier-card-icon-section">
                                    <div class="tier-card-icon icon-resources">
                                        <i class="fa fa-chalkboard-user"></i>
                                    </div>
                                </div>
                                
                                <!-- Vertical Divider Line -->
                                <div class="tier-card-divider"></div>
                                
                                <!-- Content Section -->
                                <div class="tier-card-body">
                                    <h3 class="tier-card-title">Teach</h3>
                                    <p class="tier-card-description">Deliver lessons and share activities that help students learn confidently.</p>
                                    <div class="tier-card-count-badge">
                                        <span class="tier-card-count" id="tierCardCountTeach">0</span><span>resources</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Checkmark for active state -->
                            <div class="tier-card-checkmark">
                                <i class="fa fa-check"></i>
                            </div>
                        </div>

                        <!-- Arrow Between Cards -->
                        <div class="tier-card-arrow-between">
                            <div class="flow-arrow"></div>
                        </div>

                        <!-- Step 3 Card - Assessments -->
                        <div class="tier-card tier-card-3 tier-card-assessments" data-tab="assessments" onclick="filterByResourceType('assess')">
                            <div class="tier-card-content">
                                <!-- Icon on Left -->
                                <div class="tier-card-icon-section">
                                    <div class="tier-card-icon">
                                        <i class="fa fa-edit"></i>
                                    </div>
                                </div>
                                
                                <!-- Vertical Divider Line -->
                                <div class="tier-card-divider"></div>
                                
                                <!-- Content Section -->
                                <div class="tier-card-body">
                                    <h3 class="tier-card-title">Assess</h3>
                                    <p class="tier-card-description">Manage assignments and quizzes to track progress and provide feedback.</p>
                                    <div class="tier-card-count-badge">
                                        <span class="tier-card-count" id="tierCardCountAssess">0</span><span>resources</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Checkmark for active state -->
                            <div class="tier-card-checkmark">
                                <i class="fa fa-check"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Expanded Categories Section - Shows when any tier card is active -->
                    <div class="tier-cards-expanded-section" id="tier-cards-expanded">
                        <div class="category-cards-grid" id="categoryCardsGrid">
                            <!-- Will be populated by JavaScript -->
                        </div>
                        <!-- Courses Container - Shows courses for selected categories -->
                        <div class="resource-category-course-container" id="tierCoursesContainer" style="display: none;">
                            <div class="resource-category-course-items" id="tierCoursesItems">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Search and Filters inside Header -->
                <div class="dashboard-hero-search-filters">
                    <div class="search-filters-row">
                        <div class="search-bar-container">
                            <i class="fa fa-search search-icon"></i>
                            <input type="text" id="resourceSearch" class="resource-search-input" placeholder="Search resources by name, keyword, or category..." onkeyup="filterResources()">
                            <button class="clear-search-btn" onclick="clearSearch()" style="display: none;">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        
                        
                        <!-- Folders and Files Filter Select -->
                        <div class="folders-filter-wrapper" id="foldersFilterSection">
                            <select id="foldersFilterSelect" class="filter-select-minimal" disabled onchange="filterResources()">
                                <option value="">All Folders</option>
                                <!-- Will be populated by JavaScript -->
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Wrapper for max-width constraint -->
            <div class="content-wrapper">
            <!-- Teacher Resources View -->
                <div class="teacher-resources-container">
                    
                    <!-- Search and Results -->
                    <?php if (empty($teacher_resources)): ?>
                    <div class="no-resources">
                        <i class="fa fa-info-circle"></i>
                        <p>No hidden teacher resource sections found in any of your courses.</p>
                        <p style="font-size: 13px; margin-top: 10px;">Hide a section in a course to make it appear in this view.</p>
                    </div>
                    <?php else: ?>
                    
                    <!-- Main Layout: Sidebar + Content -->
                    <div class="resources-main-layout">
                        <!-- Left Sidebar Filters -->
                        <aside class="resources-sidebar">
                            <div class="resources-sidebar-header">
                                <h3 class="resources-sidebar-title">
                                    <i class="fa fa-filter"></i> Filters
                                </h3>
                                <a href="#" class="clear-filters-link" onclick="resetAllFilters(); return false;">Clear all filters</a>
                            </div>
                            
                            <!-- Resource Type Filters (Hidden from frontend, functionality preserved) -->
                            <div class="filter-section" style="display: none;">
                                <h4 class="filter-section-title" onclick="toggleFilterSection(this)">
                                    <span>Resource Type</span>
                                    <i class="fa fa-chevron-down"></i>
                                </h4>
                                <ul class="filter-checkbox-list" id="resourceTypeFilters">
                                    <!-- Will be populated by JavaScript -->
                                </ul>
                            </div>
                            
                            <!-- Category Filters (Hidden from frontend, functionality preserved) -->
                            <div class="filter-section" style="display: none;">
                                <h4 class="filter-section-title" onclick="toggleFilterSection(this)">
                                    <span>Category</span>
                                    <i class="fa fa-chevron-down"></i>
                                </h4>
                                <ul class="filter-checkbox-list" id="categoryFilters">
                                    <!-- Will be populated by JavaScript -->
                                </ul>
                            </div>
                            
                            <!-- Sections Filter (only active when courses are selected) -->
                            <div class="filter-section" id="sectionsFilterCheckboxSection" style="display: none;">
                                <h4 class="filter-section-title" onclick="toggleFilterSection(this)">
                                    <span>Sections</span>
                                    <i class="fa fa-chevron-down"></i>
                                </h4>
                                <ul class="filter-checkbox-list" id="sectionsFilters">
                                    <!-- Will be populated by JavaScript based on selected courses -->
                                </ul>
                            </div>
                        </aside>
                        
                        <!-- Main Content Area -->
                        <div class="resources-content-area">
                            <!-- Resources Grid -->
                            <div class="resources-grid-container">
                                <div class="resources-grid-header">
                                    <div>
                                        <h3 class="resources-grid-title">All Resources</h3>
                                        <p class="resources-grid-subtitle">Browse all available teaching resources across all levels and themes</p>
                                    </div>
                                    <div class="resources-count" id="resourcesCount">0 resources</div>
                                </div>
                                
                                <div class="resources-content">
                                    <div class="resources-grid" id="resourcesGrid">
                        <?php
                        // Helper function to get category info for a course (returns array with main and direct category)
                        function get_category_info_for_course($course, $DB) {
                            $course_category = $DB->get_record('course_categories', ['id' => $course->category], '*', IGNORE_MISSING);
                            if (!$course_category) {
                                return ['main' => 'Uncategorized', 'main_id' => 0, 'direct' => 'Uncategorized', 'direct_id' => 0];
                            }
                            
                            $direct_category = $course_category;
                            
                            // Traverse up the category tree to find the main category (parent = 0)
                            $current_cat = $course_category;
                            while ($current_cat && $current_cat->parent != 0) {
                                $current_cat = $DB->get_record('course_categories', ['id' => $current_cat->parent], '*', IGNORE_MISSING);
                                if (!$current_cat) {
                                    break;
                                }
                            }
                            
                            if ($current_cat && $current_cat->parent == 0) {
                                return [
                                    'main' => $current_cat->name,
                                    'main_id' => $current_cat->id,
                                    'direct' => $direct_category->name,
                                    'direct_id' => $direct_category->id
                                ];
                            }
                            
                            return ['main' => 'Uncategorized', 'main_id' => 0, 'direct' => 'Uncategorized', 'direct_id' => 0];
                        }
                        
                        // Flatten all resources into a single array for grid display
                        $all_resources = []; // All resources (files from folders + standalone)
                        $available_file_types = []; // Track unique file types
                        $category_resource_count = []; // Track category counts by ID
                        $category_info_map = []; // Map category ID to category info
                        $category_tree = []; // Tree structure: main_category_id => [child_category_id => child_name]
                        
                        $filestorage = get_file_storage();
                        
                        foreach ($teacher_resources as $resource) {
                            $cm = $resource['cm'];
                            $course = $resource['course'];
                            
                            // Get category info for this resource's course
                            $cat_info = get_category_info_for_course($course, $DB);
                            
                            // Store category info (only main categories, no sub-categories)
                            if ($cat_info['main_id'] > 0) {
                                $category_info_map[$cat_info['main_id']] = [
                                    'name' => $cat_info['main'],
                                    'id' => $cat_info['main_id'],
                                    'parent_id' => 0
                                ];
                                // Note: We don't add sub-categories to the tree anymore - only courses will be added later
                            }
                            
                            // If it's a folder, extract all files from it
                            if ($cm->modname === 'folder') {
                                // Get files inside this folder
                                $fs = get_file_storage();
                                $context = context_module::instance($cm->id);
                                $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'sortorder, filepath, filename', false);
                                
                                foreach ($files as $file) {
                                    $file_ext = strtoupper(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                                    if (!empty($file_ext) && !in_array($file_ext, $available_file_types)) {
                                        $available_file_types[] = $file_ext;
                                    }
                                    
                                    // Track category count by ID
                                    if ($cat_info['main_id'] > 0) {
                                        if (!isset($category_resource_count[$cat_info['main_id']])) {
                                            $category_resource_count[$cat_info['main_id']] = 0;
                                        }
                                        $category_resource_count[$cat_info['main_id']]++;
                                    }
                                    
                                    // Add file to all resources with category info
                                    $folder_name = format_string($cm->name); // Get folder name
                                    // Get section type (Plan/Teach/Assess) from section name
                                    $section_type = get_section_type($resource['section_name']);
                                    $all_resources[] = [
                                        'type' => 'file',
                                        'file' => $file,
                                        'category' => $cat_info['main'],
                                        'category_id' => $cat_info['main_id'],
                                        'direct_category' => $cat_info['direct'],
                                        'direct_category_id' => $cat_info['direct_id'],
                                        'section' => $resource['section_name'],
                                        'folder_name' => $folder_name,
                                        'folder_tag' => $section_type, // Store section type instead of tag
                                        'folder_cmid' => $cm->id,
                                        'course' => $course
                                    ];
                                }
                            } else {
                                // It's a standalone file/resource
                                $resource_type = strtoupper($cm->modname);
                                if (!in_array($resource_type, $available_file_types)) {
                                    $available_file_types[] = $resource_type;
                                }
                                
                                // Track category count by ID
                                if ($cat_info['main_id'] > 0) {
                                    if (!isset($category_resource_count[$cat_info['main_id']])) {
                                        $category_resource_count[$cat_info['main_id']] = 0;
                                    }
                                    $category_resource_count[$cat_info['main_id']]++;
                                }
                                
                                // Add standalone resource with category info
                                $folder_name = ($cm->modname === 'folder') ? format_string($cm->name) : '';
                                // Get section type (Plan/Teach/Assess) from section name
                                $section_type = get_section_type($resource['section_name']);
                                $all_resources[] = [
                                    'type' => 'resource',
                                    'cm' => $cm,
                                    'category' => $cat_info['main'],
                                    'category_id' => $cat_info['main_id'],
                                    'direct_category' => $cat_info['direct'],
                                    'direct_category_id' => $cat_info['direct_id'],
                                    'section' => $resource['section_name'],
                                    'folder_name' => $folder_name,
                                    'folder_tag' => $section_type, // Store section type instead of tag
                                    'folder_cmid' => ($cm->modname === 'folder') ? $cm->id : null,
                                    'course' => $course
                                ];
                            }
                        }
                        
                        // Calculate resource counts by tag type
                        $resource_counts = [
                            'all' => count($all_resources),
                            'plan' => 0,
                            'teach' => 0,
                            'assess' => 0
                        ];
                        
                        foreach ($all_resources as $resource_item) {
                            $folder_tag = isset($resource_item['folder_tag']) ? strtolower($resource_item['folder_tag']) : '';
                            if ($folder_tag === 'plan') {
                                $resource_counts['plan']++;
                            } else if ($folder_tag === 'teach') {
                                $resource_counts['teach']++;
                            } else if ($folder_tag === 'assess') {
                                $resource_counts['assess']++;
                            }
                        }
                        
                        // Build a set of course IDs that have resources (for filtering)
                        $courses_with_resources = [];
                        foreach ($all_resources as $resource_item) {
                            if (isset($resource_item['course']) && isset($resource_item['course']->id)) {
                                $courses_with_resources[$resource_item['course']->id] = true;
                            }
                        }
                        
                        // Get all courses for main categories where user is a teacher (including all nested subcategories)
                        // Build a map of all categories that have teacher courses
                        $all_teacher_category_ids = [];
                        foreach ($teacher_courses as $course) {
                            if (isset($course->category) && $course->category > 0) {
                                $all_teacher_category_ids[$course->category] = true;
                            }
                        }
                        
                        // For each category that has teacher courses, find the main category and add courses
                        foreach (array_keys($all_teacher_category_ids) as $cat_id) {
                            $current_cat = $DB->get_record('course_categories', ['id' => $cat_id], 'id, parent, name, path', IGNORE_MISSING);
                            if (!$current_cat) continue;
                            
                            // Traverse up to find main category (parent = 0)
                            $main_cat = $current_cat;
                            while ($main_cat && $main_cat->parent != 0) {
                                $main_cat = $DB->get_record('course_categories', ['id' => $main_cat->parent], 'id, parent, name, path', IGNORE_MISSING);
                                if (!$main_cat) break;
                            }
                            
                            if ($main_cat && $main_cat->parent == 0) {
                                $main_cat_id = $main_cat->id;
                                
                                // Get all categories that are descendants of this main category
                                $main_category_record = $DB->get_record('course_categories', ['id' => $main_cat_id], 'path', MUST_EXIST);
                                if (!$main_category_record) continue;
                                
                                $path_pattern = $main_category_record->path . '/%';
                                
                                // Get all category IDs that are descendants (including the main category itself)
                                $all_descendant_categories = $DB->get_records_sql(
                                    "SELECT id FROM {course_categories} 
                                     WHERE (id = ? OR path LIKE ?) AND visible = 1",
                                    [$main_cat_id, $path_pattern]
                                );
                                
                                $descendant_category_ids = array_keys($all_descendant_categories);
                                
                                if (!empty($descendant_category_ids)) {
                                    // Get ALL teacher courses in these categories
                                    list($in_sql, $params) = $DB->get_in_or_equal($descendant_category_ids);
                                    $params[] = $userid;
                                    
                                    $courses_in_category = $DB->get_records_sql(
                                        "SELECT DISTINCT c.id, c.fullname, c.shortname 
                                         FROM {course} c
                                         JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                                         JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                         JOIN {role} r ON r.id = ra.roleid
                                         WHERE c.category $in_sql 
                                         AND c.id > 1
                                         AND c.visible = 1
                                         AND ra.userid = ?
                                         AND r.archetype = 'editingteacher'
                                         ORDER BY c.fullname ASC",
                                        $params
                                    );
                                    
                                    // Filter courses: only include those that have resources OR have hidden sections
                                    $filtered_courses = [];
                                    if (!empty($courses_in_category)) {
                                        // Get all course IDs to check
                                        $course_ids_to_check = array_keys($courses_in_category);
                                        
                                        // Batch check for hidden sections (more efficient than individual queries)
                                        $courses_with_hidden_sections = [];
                                        if (!empty($course_ids_to_check)) {
                                            list($course_ids_sql, $course_ids_params) = $DB->get_in_or_equal($course_ids_to_check);
                                            $hidden_sections = $DB->get_records_sql(
                                                "SELECT DISTINCT course 
                                                 FROM {course_sections} 
                                                 WHERE course $course_ids_sql 
                                                 AND section > 0 
                                                 AND visible = 0",
                                                $course_ids_params
                                            );
                                            foreach ($hidden_sections as $section) {
                                                $courses_with_hidden_sections[$section->course] = true;
                                            }
                                        }
                                        
                                        // Filter courses
                                        foreach ($courses_in_category as $course_id => $course_record) {
                                            $has_resources = isset($courses_with_resources[$course_id]);
                                            $has_hidden_sections = isset($courses_with_hidden_sections[$course_id]);
                                            
                                            // Only include course if it has resources OR hidden sections
                                            if ($has_resources || $has_hidden_sections) {
                                                $filtered_courses[$course_id] = $course_record;
                                            }
                                        }
                                    }
                                    
                                    // Store filtered courses in the category tree (only those with resources or hidden sections)
                                    if (!empty($filtered_courses)) {
                                        if (!isset($category_tree[$main_cat_id])) {
                                            $category_tree[$main_cat_id] = [];
                                        }
                                        foreach ($filtered_courses as $course_id => $course_record) {
                                            // Use course ID as key and course fullname as value
                                            $category_tree[$main_cat_id][$course_id] = $course_record->fullname;
                                        }
                                        
                                        // Also add to category_info_map if not already there
                                        if (!isset($category_info_map[$main_cat_id])) {
                                            $category_info_map[$main_cat_id] = [
                                                'name' => $main_cat->name,
                                                'id' => $main_cat_id,
                                                'parent_id' => 0
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Sort file types alphabetically
                        sort($available_file_types);
                        
                        // Group image types together as "Images"
                        $image_extensions = ['PNG', 'JPG', 'JPEG', 'GIF', 'SVG', 'BMP', 'WEBP'];
                        $has_images = false;
                        $available_file_types = array_filter($available_file_types, function($type) use ($image_extensions, &$has_images) {
                            if (in_array($type, $image_extensions)) {
                                $has_images = true;
                                return false; // Remove individual image types
                            }
                            return true;
                        });
                        // Add "Images" as a group if any image types were found
                        if ($has_images && !in_array('Images', $available_file_types)) {
                            $available_file_types[] = 'Images';
                        }
                        // Re-sort after grouping
                        sort($available_file_types);
                        
                        // Collect course sections and folders data for JavaScript
                        $course_sections_data = []; // course_id => [sections]
                        $course_folders_data = []; // course_id => [folders by section]
                        $course_main_sections_data = []; // course_id => [main sections] - sections without subsections
                        $course_main_section_folders_data = []; // course_id => [all folders in main section]
                        $course_files_data = []; // course_id => [file names by section]
                        
                        foreach ($teacher_courses as $teacher_course) {
                            $courseid = $teacher_course->id;
                            $course_sections_data[$courseid] = [];
                            $course_folders_data[$courseid] = [];
                            $course_main_sections_data[$courseid] = [];
                            $course_main_section_folders_data[$courseid] = [];
                            $course_files_data[$courseid] = [];
                            
                            // Get course sections
                            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
                            $modinfo = get_fast_modinfo($courseid);
                            $filestorage = get_file_storage();
                            
                            // First pass: Collect only hidden main sections from the course (visible only to teachers)
                            foreach ($sections as $section) {
                                if ((int)$section->section === 0) {
                                    continue;
                                }
                                
                                if ($section->component === 'mod_subsection') {
                                    continue;
                                }
                                
                                $section_name = (string)($section->name ?? '');
                                $section_display_name = $section_name !== '' ? $section_name : ('Section ' . $section->section);
                                
                                // Check if section is hidden from students or explicitly marked as teacher resources
                                $section_hidden = ((int)$section->visible === 0);
                                
                                // Only add hidden sections (visible only to teachers)
                                if ($section_hidden || 
                                    stripos($section_name, 'teacher resource') !== false || 
                                    stripos($section_name, 'teacher material') !== false ||
                                    stripos($section_name, 'instructor resource') !== false) {
                                    
                                    // Check if this is a main section (doesn't contain " > ")
                                    $is_main_section = (strpos($section_display_name, ' > ') === false);
                                    
                                    // Only add hidden main sections to the list
                                    if ($is_main_section) {
                                        if (!in_array($section_display_name, $course_main_sections_data[$courseid])) {
                                            $course_main_sections_data[$courseid][] = $section_display_name;
                                        }
                                    }
                                }
                            }
                            
                            // Second pass: Collect teacher resource sections and folders
                            foreach ($sections as $section) {
                                if ((int)$section->section === 0) {
                                    continue;
                                }
                                
                                if ($section->component === 'mod_subsection') {
                                    continue;
                                }
                                
                                $section_name = (string)($section->name ?? '');
                                $section_display_name = $section_name !== '' ? $section_name : ('Section ' . $section->section);
                                
                                // Check if section is hidden from students or explicitly marked as teacher resources
                                $section_hidden = ((int)$section->visible === 0);
                                
                                if ($section_hidden || 
                                    stripos($section_name, 'teacher resource') !== false || 
                                    stripos($section_name, 'teacher material') !== false ||
                                    stripos($section_name, 'instructor resource') !== false) {
                                    
                                    // Add section to list (for teacher resource sections)
                                    if (!in_array($section_display_name, $course_sections_data[$courseid])) {
                                        $course_sections_data[$courseid][] = $section_display_name;
                                    }
                                    
                                    $is_main_section = (strpos($section_display_name, ' > ') === false);
                                    
                                    // Get folders in this section
                                    if (!empty($section->sequence)) {
                                        $module_ids = explode(',', $section->sequence);
                                        foreach ($module_ids as $module_id) {
                                            try {
                                                $cm = $modinfo->get_cm($module_id);
                                                if (!$cm) continue;
                                                
                                                // Skip deleted or invalid modules
                                                if (!empty($cm->deletioninprogress)) continue;
                                                
                                                // Verify the module instance actually exists
                                                $module_table = $cm->modname;
                                                $module_exists = $DB->record_exists($module_table, ['id' => $cm->instance]);
                                                if (!$module_exists) continue;
                                                
                                                // If it's a folder, add it to the folders list for this section
                                                if ($cm->modname === 'folder') {
                                                    $folder_name = format_string($cm->name);
                                                    if (!isset($course_folders_data[$courseid][$section_display_name])) {
                                                        $course_folders_data[$courseid][$section_display_name] = [];
                                                    }
                                                    if (!in_array($folder_name, $course_folders_data[$courseid][$section_display_name])) {
                                                        $course_folders_data[$courseid][$section_display_name][] = $folder_name;
                                                    }
                                                    
                                                    // If this is a main section, also add folder to main section folders
                                                    if ($is_main_section) {
                                                        if (!isset($course_main_section_folders_data[$courseid][$section_display_name])) {
                                                            $course_main_section_folders_data[$courseid][$section_display_name] = [];
                                                        }
                                                        if (!in_array($folder_name, $course_main_section_folders_data[$courseid][$section_display_name])) {
                                                            $course_main_section_folders_data[$courseid][$section_display_name][] = $folder_name;
                                                        }
                                                    }
                                                    
                                                    // Get all files from this folder
                                                    try {
                                                        $folder_context = context_module::instance($cm->id);
                                                        $folder_files = $filestorage->get_area_files($folder_context->id, 'mod_folder', 'content', 0, 'sortorder, filepath, filename', false);
                                                        foreach ($folder_files as $file) {
                                                            $file_name = $file->get_filename();
                                                            if (!isset($course_files_data[$courseid][$section_display_name])) {
                                                                $course_files_data[$courseid][$section_display_name] = [];
                                                            }
                                                            if (!in_array($file_name, $course_files_data[$courseid][$section_display_name])) {
                                                                $course_files_data[$courseid][$section_display_name][] = $file_name;
                                                            }
                                                        }
                                                    } catch (Exception $e) {
                                                        // Skip if context doesn't exist
                                                    }
                                                }
                                                
                                                // If it's a resource, get files attached to it
                                                if ($cm->modname === 'resource') {
                                                    try {
                                                        $resource_context = context_module::instance($cm->id);
                                                        $resource_files = $filestorage->get_area_files($resource_context->id, 'mod_resource', 'content', 0, 'sortorder, filepath, filename', false);
                                                        foreach ($resource_files as $file) {
                                                            $file_name = $file->get_filename();
                                                            if (!isset($course_files_data[$courseid][$section_display_name])) {
                                                                $course_files_data[$courseid][$section_display_name] = [];
                                                            }
                                                            if (!in_array($file_name, $course_files_data[$courseid][$section_display_name])) {
                                                                $course_files_data[$courseid][$section_display_name][] = $file_name;
                                                            }
                                                        }
                                                    } catch (Exception $e) {
                                                        // Skip if context doesn't exist
                                                    }
                                                }
                                                
                                                // If it's a subsection, collect files from activities inside it
                                                if ($cm->modname === 'subsection') {
                                                    try {
                                                        $subsection = $DB->get_record('course_sections', [
                                                            'component' => 'mod_subsection',
                                                            'itemid' => $cm->instance
                                                        ]);
                                                        
                                                        if ($subsection && !empty($subsection->sequence)) {
                                                            $subsection_name = $section_display_name . ' > ' . ($subsection->name ?? '');
                                                            $sub_module_ids = explode(',', $subsection->sequence);
                                                            foreach ($sub_module_ids as $sub_module_id) {
                                                                try {
                                                                    $sub_cm = $modinfo->get_cm($sub_module_id);
                                                                    if (!$sub_cm || $sub_cm->modname === 'subsection') continue;
                                                                    
                                                                    // Skip deleted or invalid sub-modules
                                                                    if (!empty($sub_cm->deletioninprogress)) continue;
                                                                    
                                                                    // Verify the sub-module instance actually exists
                                                                    $sub_module_table = $sub_cm->modname;
                                                                    $sub_module_exists = $DB->record_exists($sub_module_table, ['id' => $sub_cm->instance]);
                                                                    if (!$sub_module_exists) continue;
                                                                    
                                                                    // Collect files from folders in subsection
                                                                    if ($sub_cm->modname === 'folder') {
                                                                        try {
                                                                            $sub_folder_context = context_module::instance($sub_cm->id);
                                                                            $sub_folder_files = $filestorage->get_area_files($sub_folder_context->id, 'mod_folder', 'content', 0, 'sortorder, filepath, filename', false);
                                                                            foreach ($sub_folder_files as $file) {
                                                                                $file_name = $file->get_filename();
                                                                                if (!isset($course_files_data[$courseid][$subsection_name])) {
                                                                                    $course_files_data[$courseid][$subsection_name] = [];
                                                                                }
                                                                                if (!in_array($file_name, $course_files_data[$courseid][$subsection_name])) {
                                                                                    $course_files_data[$courseid][$subsection_name][] = $file_name;
                                                                                }
                                                                            }
                                                                        } catch (Exception $e) {
                                                                            // Skip if context doesn't exist
                                                                        }
                                                                    }
                                                                    
                                                                    // Collect files from resources in subsection
                                                                    if ($sub_cm->modname === 'resource') {
                                                                        try {
                                                                            $sub_resource_context = context_module::instance($sub_cm->id);
                                                                            $sub_resource_files = $filestorage->get_area_files($sub_resource_context->id, 'mod_resource', 'content', 0, 'sortorder, filepath, filename', false);
                                                                            foreach ($sub_resource_files as $file) {
                                                                                $file_name = $file->get_filename();
                                                                                if (!isset($course_files_data[$courseid][$subsection_name])) {
                                                                                    $course_files_data[$courseid][$subsection_name] = [];
                                                                                }
                                                                                if (!in_array($file_name, $course_files_data[$courseid][$subsection_name])) {
                                                                                    $course_files_data[$courseid][$subsection_name][] = $file_name;
                                                                                }
                                                                            }
                                                                        } catch (Exception $e) {
                                                                            // Skip if context doesn't exist
                                                                        }
                                                                    }
                                                                } catch (Exception $e) {
                                                                    continue;
                                                                }
                                                            }
                                                        }
                                                    } catch (Exception $e) {
                                                        // Skip if subsection doesn't exist
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                continue;
                                            }
                                        }
                                    }
                                }
                                
                                // Also check for individual hidden activities in visible sections
                                if (!$section_hidden && !empty($section->sequence)) {
                                    $module_ids = explode(',', $section->sequence);
                                    foreach ($module_ids as $module_id) {
                                        try {
                                            $cm = $modinfo->get_cm($module_id);
                                            if ($cm && $cm->visible == 0 && $cm->modname === 'folder') {
                                                // Skip deleted or invalid modules
                                                if (!empty($cm->deletioninprogress)) continue;
                                                
                                                // Verify the module instance actually exists
                                                $module_table = $cm->modname;
                                                $module_exists = $DB->record_exists($module_table, ['id' => $cm->instance]);
                                                if (!$module_exists) continue;
                                                
                                                // Note: We don't add visible sections to the dropdown even if they contain hidden activities
                                                // Only hidden sections (visible === 0) should appear in the sections filter
                                                
                                                // Add folder to the folders list for this section
                                                $folder_name = format_string($cm->name);
                                                if (!isset($course_folders_data[$courseid][$section_display_name])) {
                                                    $course_folders_data[$courseid][$section_display_name] = [];
                                                }
                                                if (!in_array($folder_name, $course_folders_data[$courseid][$section_display_name])) {
                                                    $course_folders_data[$courseid][$section_display_name][] = $folder_name;
                                                }
                                                
                                                // If this is a main section, also add folder to main section folders
                                                if ($is_main_section) {
                                                    if (!isset($course_main_section_folders_data[$courseid][$section_display_name])) {
                                                        $course_main_section_folders_data[$courseid][$section_display_name] = [];
                                                    }
                                                    if (!in_array($folder_name, $course_main_section_folders_data[$courseid][$section_display_name])) {
                                                        $course_main_section_folders_data[$courseid][$section_display_name][] = $folder_name;
                                                    }
                                                }
                                                
                                                // Get all files from this hidden folder
                                                try {
                                                    $folder_context = context_module::instance($cm->id);
                                                    $folder_files = $filestorage->get_area_files($folder_context->id, 'mod_folder', 'content', 0, 'sortorder, filepath, filename', false);
                                                    foreach ($folder_files as $file) {
                                                        $file_name = $file->get_filename();
                                                        if (!isset($course_files_data[$courseid][$section_display_name])) {
                                                            $course_files_data[$courseid][$section_display_name] = [];
                                                        }
                                                        if (!in_array($file_name, $course_files_data[$courseid][$section_display_name])) {
                                                            $course_files_data[$courseid][$section_display_name][] = $file_name;
                                                        }
                                                    }
                                                } catch (Exception $e) {
                                                    // Skip if context doesn't exist
                                                }
                                            }
                                            
                                            // Also collect files from hidden resources in visible sections
                                            if ($cm && $cm->visible == 0 && $cm->modname === 'resource') {
                                                // Skip deleted or invalid modules
                                                if (!empty($cm->deletioninprogress)) continue;
                                                
                                                // Verify the module instance actually exists
                                                $module_table = $cm->modname;
                                                $module_exists = $DB->record_exists($module_table, ['id' => $cm->instance]);
                                                if (!$module_exists) continue;
                                                
                                                // Get files from this hidden resource
                                                try {
                                                    $resource_context = context_module::instance($cm->id);
                                                    $resource_files = $filestorage->get_area_files($resource_context->id, 'mod_resource', 'content', 0, 'sortorder, filepath, filename', false);
                                                    foreach ($resource_files as $file) {
                                                        $file_name = $file->get_filename();
                                                        if (!isset($course_files_data[$courseid][$section_display_name])) {
                                                            $course_files_data[$courseid][$section_display_name] = [];
                                                        }
                                                        if (!in_array($file_name, $course_files_data[$courseid][$section_display_name])) {
                                                            $course_files_data[$courseid][$section_display_name][] = $file_name;
                                                        }
                                                    }
                                                } catch (Exception $e) {
                                                    // Skip if context doesn't exist
                                                }
                                            }
                                        } catch (Exception $e) {
                                            continue;
                                        }
                                    }
                                }
                            }
                            
                            // Aggregate folders from subsections into main sections
                            foreach ($course_folders_data[$courseid] as $section_name => $folders) {
                                // Check if this is a subsection (contains " > ")
                                if (strpos($section_name, ' > ') !== false) {
                                    // Extract main section name (part before " > ")
                                    $main_section_name = explode(' > ', $section_name)[0];
                                    
                                    // Check if the main section is hidden (visible only to teachers)
                                    $main_section_hidden = false;
                                    foreach ($sections as $section) {
                                        $section_name_check = (string)($section->name ?? '');
                                        $section_display_name_check = $section_name_check !== '' ? $section_name_check : ('Section ' . $section->section);
                                        
                                        if ($section_display_name_check === $main_section_name) {
                                            $main_section_hidden = ((int)$section->visible === 0) ||
                                                stripos($section_name_check, 'teacher resource') !== false ||
                                                stripos($section_name_check, 'teacher material') !== false ||
                                                stripos($section_name_check, 'instructor resource') !== false;
                                            break;
                                        }
                                    }
                                    
                                    // Only add main section if it's hidden (visible only to teachers)
                                    if ($main_section_hidden && !in_array($main_section_name, $course_main_sections_data[$courseid])) {
                                        $course_main_sections_data[$courseid][] = $main_section_name;
                                    }
                                    
                                    // Aggregate folders from subsection into main section (only if main section is hidden)
                                    if ($main_section_hidden) {
                                        if (!isset($course_main_section_folders_data[$courseid][$main_section_name])) {
                                            $course_main_section_folders_data[$courseid][$main_section_name] = [];
                                        }
                                        foreach ($folders as $folder) {
                                            if (!in_array($folder, $course_main_section_folders_data[$courseid][$main_section_name])) {
                                                $course_main_section_folders_data[$courseid][$main_section_name][] = $folder;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Aggregate files from subsections into main sections
                            foreach ($course_files_data[$courseid] as $section_name => $files) {
                                // Check if this is a subsection (contains " > ")
                                if (strpos($section_name, ' > ') !== false) {
                                    // Extract main section name (part before " > ")
                                    $main_section_name = explode(' > ', $section_name)[0];
                                    
                                    // Aggregate files from subsection into main section
                                    if (!isset($course_files_data[$courseid][$main_section_name])) {
                                        $course_files_data[$courseid][$main_section_name] = [];
                                    }
                                    foreach ($files as $file) {
                                        if (!in_array($file, $course_files_data[$courseid][$main_section_name])) {
                                            $course_files_data[$courseid][$main_section_name][] = $file;
                                        }
                                    }
                                }
                            }
                            
                            // Sort main sections alphabetically for each course
                            foreach ($course_main_sections_data as $courseid => $main_sections) {
                                sort($course_main_sections_data[$courseid]);
                            }
                        }
                           
                            // Output JavaScript to populate filter checkboxes
                            if (!empty($available_file_types) || !empty($category_resource_count)) {
                                // Define toggleCategoryChildren function BEFORE the DOMContentLoaded so it's available for inline handlers
                                echo '<script>';
                                echo 'function toggleCategoryChildren(checkbox) {';
                                echo '    const categoryId = checkbox.getAttribute("data-category-id");';
                                echo '    const childContainer = document.querySelector(".filter-category-children[data-parent-id=\"" + categoryId + "\"]");';
                                echo '    if (childContainer) {';
                                echo '        if (checkbox.checked) {';
                                echo '            childContainer.style.display = "block";';
                                echo '        } else {';
                                echo '            childContainer.style.display = "none";';
                                echo '            childContainer.querySelectorAll("input[type=\\"checkbox\\"]").forEach(childCheckbox => {';
                                echo '                childCheckbox.checked = false;';
                                echo '            });';
                                echo '        }';
                                echo '    }';
                                echo '    if (typeof updateSectionsAndFoldersFilters === "function") {';
                                echo '        updateSectionsAndFoldersFilters();';
                                echo '    }';
                                echo '}';
                                echo '</script>';
                                
                                echo '<script>';
                                echo 'document.addEventListener("DOMContentLoaded", function() {';
                                
                                // Pass course sections and folders data to JavaScript
                                echo 'window.courseSectionsData = ' . json_encode($course_sections_data) . ';';
                                echo 'window.courseFoldersData = ' . json_encode($course_folders_data) . ';';
                                echo 'window.courseMainSectionsData = ' . json_encode($course_main_sections_data) . ';';
                                echo 'window.courseMainSectionFoldersData = ' . json_encode($course_main_section_folders_data) . ';';
                                echo 'window.courseFilesData = ' . json_encode($course_files_data) . ';';
                                
                                // Initialize sections and folders filters on page load (after tab is set)
                                echo 'setTimeout(function() {';
                                echo '    if (typeof updateSectionsAndFoldersFilters === "function") {';
                                echo '        updateSectionsAndFoldersFilters();';
                                echo '    }';
                                echo '}, 150);';
                                
                                // Update resource tab counts on page load (after cards are rendered)
                                echo 'setTimeout(function() {';
                                echo '    if (typeof updateResourceTabCounts === "function") {';
                                echo '        updateResourceTabCounts();';
                                echo '    }';
                                echo '}, 800);';
                                
                                // Populate resource type filter checkboxes
                                echo 'const resourceTypeFilters = document.getElementById("resourceTypeFilters");';
                                echo 'if (resourceTypeFilters) {';
                                foreach ($available_file_types as $file_type) {
                                    $safe_file_type = addslashes($file_type);
                                    $file_type_lower = strtolower($file_type);
                                    $dot_class = 'type-' . $file_type_lower;
                                    
                                    // Display "Videos" instead of "HTML" for HTML file type
                                    $display_name = ($file_type_lower === 'html') ? 'Videos' : $file_type;
                                    $safe_display_name = addslashes($display_name);
                                    
                                    echo 'const li' . preg_replace('/[^A-Za-z0-9]/', '', $file_type) . ' = document.createElement("li");';
                                    echo 'li' . preg_replace('/[^A-Za-z0-9]/', '', $file_type) . '.className = "filter-checkbox-item";';
                                    echo 'li' . preg_replace('/[^A-Za-z0-9]/', '', $file_type) . '.innerHTML = \'<label class="filter-checkbox-label"><input type="checkbox" class="filter-checkbox" data-filter-type="resource-type" data-filter-value="' . $file_type_lower . '" onchange="filterResources()"><span class="filter-checkbox-dot ' . $dot_class . '"></span>' . $safe_display_name . '</label>\';';
                                    echo 'resourceTypeFilters.appendChild(li' . preg_replace('/[^A-Za-z0-9]/', '', $file_type) . ');';
                                }
                                echo '}';
                                
                                // Populate category filter checkboxes with hierarchical structure
                                echo 'const categoryFilters = document.getElementById("categoryFilters");';
                                echo 'if (categoryFilters) {';
                                
                                // Get main categories (parent_id = 0) that have courses (not just those with resources)
                                $main_categories = [];
                                foreach ($category_info_map as $cat_id => $cat_info) {
                                    if ($cat_info['parent_id'] == 0) {
                                        // Include category if it has courses in the category_tree (all teacher courses)
                                        if (isset($category_tree[$cat_id]) && !empty($category_tree[$cat_id])) {
                                            $main_categories[$cat_id] = $cat_info;
                                        }
                                    }
                                }
                                
                                // Sort main categories by name
                                uasort($main_categories, function($a, $b) {
                                    return strcmp($a['name'], $b['name']);
                                });
                                
                                foreach ($main_categories as $main_cat_id => $main_cat_info) {
                                    $decoded_category_name = html_entity_decode($main_cat_info['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                    $safe_main_category = addslashes($decoded_category_name);
                                    $main_cat_var = 'catLi' . preg_replace('/[^A-Za-z0-9]/', '', $main_cat_info['name']) . '_' . $main_cat_id;
                                    
                                    // Create main category item
                                    echo 'const ' . $main_cat_var . ' = document.createElement("li");';
                                    echo $main_cat_var . '.className = "filter-checkbox-item filter-category-parent";';
                                    echo $main_cat_var . '.setAttribute("data-category-id", "' . $main_cat_id . '");';
                                    echo $main_cat_var . '.innerHTML = \'<label class="filter-checkbox-label"><input type="checkbox" class="filter-checkbox filter-category-checkbox" data-filter-type="category" data-filter-value="' . addslashes($decoded_category_name) . '" data-category-id="' . $main_cat_id . '" onchange="toggleCategoryChildren(this); filterResources()">' . $safe_main_category . '</label>\';';
                                    echo 'categoryFilters.appendChild(' . $main_cat_var . ');';
                                    
                                    // Add courses if they exist
                                    if (isset($category_tree[$main_cat_id]) && !empty($category_tree[$main_cat_id])) {
                                        $child_container_var = 'childContainer_' . $main_cat_id;
                                        echo 'const ' . $child_container_var . ' = document.createElement("ul");';
                                        echo $child_container_var . '.className = "filter-checkbox-list filter-category-children";';
                                        echo $child_container_var . '.style.display = "none";';
                                        echo $child_container_var . '.setAttribute("data-parent-id", "' . $main_cat_id . '");';
                                        
                                        // Sort courses by name
                                        asort($category_tree[$main_cat_id]);
                                        
                                        foreach ($category_tree[$main_cat_id] as $course_id => $course_name) {
                                            $decoded_course_name = html_entity_decode($course_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                            $safe_course_name = addslashes($decoded_course_name);
                                            $course_var = 'courseLi' . preg_replace('/[^A-Za-z0-9]/', '', $course_name) . '_' . $course_id;
                                            
                                            echo 'const ' . $course_var . ' = document.createElement("li");';
                                            echo $course_var . '.className = "filter-checkbox-item filter-category-child";';
                                            echo $course_var . '.setAttribute("data-course-id", "' . $course_id . '");';
                                            echo $course_var . '.setAttribute("data-parent-id", "' . $main_cat_id . '");';
                                            echo $course_var . '.innerHTML = \'<label class="filter-checkbox-label filter-checkbox-label-child"><input type="checkbox" class="filter-checkbox filter-category-checkbox" data-filter-type="course" data-filter-value="' . addslashes($decoded_course_name) . '" data-course-id="' . $course_id . '" data-parent-id="' . $main_cat_id . '" onchange="updateSectionsAndFoldersFilters(); filterResources()">' . $safe_course_name . '</label>\';';
                                            echo $child_container_var . '.appendChild(' . $course_var . ');';
                                        }
                                        
                                        echo $main_cat_var . '.appendChild(' . $child_container_var . ');';
                                    }
                                }
                                echo '}';
                                
                                // Pass main categories data to JavaScript for tier card expansion (with courses)
                                $main_categories_array = [];
                                
                                // Track which category names we've already added to avoid duplicates (case-insensitive)
                                $added_category_names = [];
                                
                                foreach ($main_categories as $main_cat_id => $main_cat_info) {
                                    $courses_array = [];
                                    // Get courses for this category from category_tree
                                    if (isset($category_tree[$main_cat_id]) && !empty($category_tree[$main_cat_id])) {
                                        foreach ($category_tree[$main_cat_id] as $course_id => $course_name) {
                                            $courses_array[] = [
                                                'id' => (int)$course_id,
                                                'name' => $course_name
                                            ];
                                        }
                                    }
                                    $category_name_lower = mb_strtolower(trim($main_cat_info['name']), 'UTF-8');
                                    
                                    // Only add if we haven't already added a category with this name (case-insensitive)
                                    if (!in_array($category_name_lower, $added_category_names, true)) {
                                        $main_categories_array[] = [
                                            'id' => (int)$main_cat_id,
                                            'name' => $main_cat_info['name'],
                                            'courses' => $courses_array
                                        ];
                                        $added_category_names[] = $category_name_lower;
                                    }
                                }
                                
                                // Add Foundation, Intermediate, and Advanced categories (for different LMS instances)
                                // Only add if they don't already exist in the database categories
                                // Order: Foundation first, then Intermediate, then Advanced
                                
                                // Grade courses for Foundation (Grade 1-5)
                                $foundation_grades = [];
                                for ($grade = 1; $grade <= 5; $grade++) {
                                    $foundation_grades[] = [
                                        'id' => -100 - $grade, // Use negative IDs starting from -101
                                        'name' => 'Grade ' . $grade
                                    ];
                                }
                                
                                // Grade courses for Intermediate (Grade 6-8)
                                $intermediate_grades = [];
                                for ($grade = 6; $grade <= 8; $grade++) {
                                    $intermediate_grades[] = [
                                        'id' => -100 - $grade, // Use negative IDs starting from -106
                                        'name' => 'Grade ' . $grade
                                    ];
                                }
                                
                                // Grade courses for Advanced (Grade 9-12)
                                $advanced_grades = [];
                                for ($grade = 9; $grade <= 12; $grade++) {
                                    $advanced_grades[] = [
                                        'id' => -100 - $grade, // Use negative IDs starting from -109
                                        'name' => 'Grade ' . $grade
                                    ];
                                }
                                
                                $additional_categories = [
                                    [
                                        'id' => -1, // Use negative ID to avoid conflicts
                                        'name' => 'Foundation',
                                        'courses' => $foundation_grades,
                                        'sort_order' => 1 // For sorting
                                    ],
                                    [
                                        'id' => -2,
                                        'name' => 'Intermediate',
                                        'courses' => $intermediate_grades,
                                        'sort_order' => 2
                                    ],
                                    [
                                        'id' => -3,
                                        'name' => 'Advanced',
                                        'courses' => $advanced_grades,
                                        'sort_order' => 3
                                    ]
                                ];
                                
                                // Only add additional categories if they don't already exist (case-insensitive check)
                                foreach ($additional_categories as $additional_cat) {
                                    $additional_name_lower = mb_strtolower(trim($additional_cat['name']), 'UTF-8');
                                    // Check if this name already exists (case-insensitive)
                                    $exists = false;
                                    foreach ($added_category_names as $existing_name) {
                                        if ($existing_name === $additional_name_lower) {
                                            $exists = true;
                                            break;
                                        }
                                    }
                                    if (!$exists) {
                                        $main_categories_array[] = $additional_cat;
                                        $added_category_names[] = $additional_name_lower;
                                    }
                                }
                                
                                // Sort categories: Foundation, Intermediate, Advanced first, then others alphabetically
                                usort($main_categories_array, function($a, $b) {
                                    $a_name_lower = mb_strtolower(trim($a['name']), 'UTF-8');
                                    $b_name_lower = mb_strtolower(trim($b['name']), 'UTF-8');
                                    
                                    // Define priority order for Foundation, Intermediate, Advanced
                                    $priority = [
                                        'foundation' => 1,
                                        'intermediate' => 2,
                                        'advanced' => 3
                                    ];
                                    
                                    $a_priority = isset($priority[$a_name_lower]) ? $priority[$a_name_lower] : 999;
                                    $b_priority = isset($priority[$b_name_lower]) ? $priority[$b_name_lower] : 999;
                                    
                                    // If both have priority, sort by priority
                                    if ($a_priority < 999 && $b_priority < 999) {
                                        return $a_priority <=> $b_priority;
                                    }
                                    // If only one has priority, it comes first
                                    if ($a_priority < 999) {
                                        return -1;
                                    }
                                    if ($b_priority < 999) {
                                        return 1;
                                    }
                                    // Otherwise, sort alphabetically
                                    return strcmp($a['name'], $b['name']);
                                });
                                
                                echo 'window.mainCategoriesData = ' . json_encode($main_categories_array, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';

                                echo '    const urlSearchParams = new URLSearchParams(window.location.search);';
                                echo '    const collectParamValues = function(paramNames) {';
                                echo '        const values = new Set();';
                                echo '        paramNames.forEach(function(name) {';
                                echo '            urlSearchParams.getAll(name).forEach(function(value) {';
                                echo '                if (value !== null && value !== undefined && value !== "") {';
                                echo '                    values.add(value.toString());';
                                echo '                }';
                                echo '            });';
                                echo '        });';
                                echo '        return Array.from(values);';
                                echo '    };';
                                echo '    const preselectedCategoryIds = collectParamValues(["categories[]","categories","category"]);';
                                echo '    const preselectedCourseIds = collectParamValues(["courses[]","courses","course"]);';
                                echo '    if (categoryFilters && (preselectedCategoryIds.length > 0 || preselectedCourseIds.length > 0)) {';
                                echo '        preselectedCategoryIds.forEach(function(catId) {';
                                echo '            const trimmedId = catId.toString().trim();';
                                echo '            if (trimmedId === "") {';
                                echo '                return;';
                                echo '            }';
                                echo '            const checkbox = categoryFilters.querySelector("[data-filter-type=\\"category\\"][data-category-id=\\"" + trimmedId + "\\"]");';
                                echo '            if (!checkbox) {';
                                echo '                return;';
                                echo '            }';
                                echo '            checkbox.checked = true;';
                                echo '            if (typeof toggleCategoryChildren === "function") {';
                                echo '                toggleCategoryChildren(checkbox);';
                                echo '            } else {';
                                echo '                const parent = checkbox.closest(".filter-category-parent");';
                                echo '                if (parent) {';
                                echo '                    const childList = parent.querySelector(".filter-category-children");';
                                echo '                    if (childList) {';
                                echo '                        childList.style.display = "block";';
                                echo '                    }';
                                echo '                }';
                                echo '            }';
                                echo '        });';
                                echo '        preselectedCourseIds.forEach(function(courseId) {';
                                echo '            const trimmedId = courseId.toString().trim();';
                                echo '            if (trimmedId === "") {';
                                echo '                return;';
                                echo '            }';
                                echo '            const checkbox = categoryFilters.querySelector("[data-filter-type=\\"course\\"][data-course-id=\\"" + trimmedId + "\\"]");';
                                echo '            if (!checkbox) {';
                                echo '                return;';
                                echo '            }';
                                echo '            checkbox.checked = true;';
                                echo '            const childList = checkbox.closest(".filter-category-children");';
                                echo '            if (childList) {';
                                echo '                childList.style.display = "block";';
                                echo '            }';
                                echo '        });';
                                echo '        if (typeof updateSectionsAndFoldersFilters === "function") {';
                                echo '            updateSectionsAndFoldersFilters();';
                                echo '        }';
                                echo '        if (typeof filterResources === "function") {';
                                echo '            filterResources();';
                                echo '        }';
                                echo '    }';

                                echo '});';
                                echo '</script>';
                            }
                            
                            // Display all resources in grid
                            foreach ($all_resources as $resource_item) {
                                if ($resource_item['type'] === 'file') {
                                    // Handle files from folders
                                    $file = $resource_item['file'];
                                    $category = $resource_item['category'];
                                    $filename = $file->get_filename();
                                    $filesize = display_size($file->get_filesize());
                                    $mimetype = $file->get_mimetype();
                                    
                                    // Get file extension
                                    $file_extension = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
                                    if (empty($file_extension)) {
                                        $file_extension = 'FILE';
                                    }
                                    
                                    // Determine icon and colors
                                    $icon_class = 'fa-file';
                                    $icon_color = '#6c757d';
                                    $bg_color = '#f8f9fa';
                                    
                                    // Map file extensions to icons and colors
                                    if ($file_extension === 'PDF') {
                                        $icon_class = 'fa-file-pdf';
                                        $icon_color = '#dc3545';
                                        $bg_color = '#f8d7da';
                                    } else if ($file_extension === 'PPTX' || $file_extension === 'PPT') {
                                        $icon_class = 'fa-file-powerpoint';
                                        $icon_color = '#fd7e14';
                                        $bg_color = '#ffe5d0';
                                    } else if ($file_extension === 'XLSX' || $file_extension === 'XLS' || $file_extension === 'CSV') {
                                        $icon_class = 'fa-file-excel';
                                        $icon_color = '#28a745';
                                        $bg_color = '#d4edda';
                                    } else if ($file_extension === 'DOCX' || $file_extension === 'DOC') {
                                        $icon_class = 'fa-file-word';
                                        $icon_color = '#007bff';
                                        $bg_color = '#cfe2ff';
                                    } else if (in_array($file_extension, ['PNG', 'JPG', 'JPEG', 'GIF', 'SVG', 'BMP', 'WEBP'])) {
                                        $icon_class = 'fa-file-image';
                                        $icon_color = '#6f42c1';
                                        $bg_color = '#e2d9f3';
                                    } else if (in_array($file_extension, ['MP4', 'AVI', 'MOV', 'WMV', 'MKV'])) {
                                        $icon_class = 'fa-file-video';
                                        $icon_color = '#e83e8c';
                                        $bg_color = '#f7d6e6';
                                    } else if (in_array($file_extension, ['MP3', 'WAV', 'AAC', 'FLAC', 'OGG'])) {
                                        $icon_class = 'fa-file-audio';
                                        $icon_color = '#20c997';
                                        $bg_color = '#d2f4ea';
                                    } else if (in_array($file_extension, ['ZIP', 'RAR', 'TAR', 'GZ', '7Z'])) {
                                        $icon_class = 'fa-file-archive';
                                        $icon_color = '#ffc107';
                                        $bg_color = '#fff3cd';
                                    } else if (in_array($file_extension, ['HTML', 'HTM'])) {
                                        $icon_class = 'fa-file-video';
                                        $icon_color = '#e83e8c';
                                        $bg_color = '#f7d6e6';
                                    }
                                    
                                    // Get file URL
                                    $previewurl = theme_remui_kids_teacher_generate_file_url($file, $USER->id);
                                    $fileurlstring = $previewurl->out(false);
                                    
                                    // Get preview image URL
                                    $preview_image_url = theme_remui_kids_teacher_generate_preview_url($file, $USER->id, $file_extension);
                                    $has_preview = !empty($preview_image_url);
                                    
                                    // Generate grid card for file
                                    $category_id = isset($resource_item['category_id']) ? $resource_item['category_id'] : 0;
                                    $direct_category_id = isset($resource_item['direct_category_id']) ? $resource_item['direct_category_id'] : 0;
                                    $course_id = isset($resource_item['course']) ? $resource_item['course']->id : 0;
                                    $section_name = isset($resource_item['section']) ? $resource_item['section'] : '';
                                    $folder_name = isset($resource_item['folder_name']) ? $resource_item['folder_name'] : '';
                                    $folder_tag = isset($resource_item['folder_tag']) ? $resource_item['folder_tag'] : '';
                                    echo '<div class="resource-card" ';
                                    echo 'data-resource-type="' . htmlspecialchars(strtolower($file_extension), ENT_QUOTES) . '" ';
                                    echo 'data-category="' . htmlspecialchars($category, ENT_QUOTES) . '" ';
                                    echo 'data-category-id="' . htmlspecialchars($category_id, ENT_QUOTES) . '" ';
                                    echo 'data-direct-category-id="' . htmlspecialchars($direct_category_id, ENT_QUOTES) . '" ';
                                    echo 'data-course-id="' . htmlspecialchars($course_id, ENT_QUOTES) . '" ';
                                    echo 'data-section="' . htmlspecialchars($section_name, ENT_QUOTES) . '" ';
                                    echo 'data-folder-name="' . htmlspecialchars($folder_name, ENT_QUOTES) . '" ';
                                    echo 'data-folder-tag="' . htmlspecialchars(strtolower($folder_tag ?: ''), ENT_QUOTES) . '" ';
                                    echo 'data-file-url="' . htmlspecialchars($fileurlstring, ENT_QUOTES) . '" ';
                                    echo 'data-file-ext="' . htmlspecialchars(strtolower($file_extension), ENT_QUOTES) . '" ';
                                    // Add preview URL for PPT files (for viewing first slide)
                                    if ($preview_image_url && in_array(strtolower($file_extension), ['ppt', 'pptx'])) {
                                        echo 'data-preview-url="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" ';
                                    }
                                    echo 'data-file-name="' . htmlspecialchars($filename, ENT_QUOTES) . '">';
                                    
                                    // Card image/icon container
                                    echo '<div class="resource-card-image-container">';
                                    if ($has_preview) {
                                        // Show preview image
                                        if (in_array(strtolower($file_extension), ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm'])) {
                                            // For videos, use video element to generate thumbnail
                                            echo '<video class="resource-card-image" style="display: none;" preload="metadata" data-src="' . htmlspecialchars($fileurlstring, ENT_QUOTES) . '">';
                                            echo '<source src="' . htmlspecialchars($fileurlstring, ENT_QUOTES) . '" type="' . htmlspecialchars($mimetype, ENT_QUOTES) . '">';
                                            echo '</video>';
                                            echo '<canvas class="resource-card-video-thumbnail" style="width: 100%; height: 100%; object-fit: cover; display: none;"></canvas>';
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else {
                                            // For PDFs - use canvas for first page rendering
                                            if (strtolower($file_extension) === 'pdf') {
                                                echo '<canvas class="resource-card-pdf-preview" data-pdf-url="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" style="width: 100%; height: 100%; display: none;"></canvas>';
                                                echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                                echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                                echo '</div>';
                                            } else if (in_array(strtolower($file_extension), ['ppt', 'pptx'])) {
                                                // For PPT files - show first slide as cover image
                                                if ($preview_image_url) {
                                                    echo '<img class="resource-card-image resource-card-ppt-preview" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($filename, ENT_QUOTES) . '" style="display: none;" />';
                                                }
                                                echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: flex;">';
                                                echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                                echo '</div>';
                                            } else if (in_array(strtolower($file_extension), ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'])) {
                                                // For Office docs - show first page/sheet as cover image
                                                if ($preview_image_url) {
                                                    echo '<img class="resource-card-image resource-card-office-preview" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($filename, ENT_QUOTES) . '" style="display: none;" />';
                                                }
                                                echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: flex;">';
                                                echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                                echo '</div>';
                                            } else {
                                                // For images, use img tag - show image by default, hide placeholder
                                                echo '<img class="resource-card-image" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($filename, ENT_QUOTES) . '" onload="this.style.display=\'block\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'none\';" onerror="this.style.display=\'none\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'flex\';" />';
                                                echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: none;">';
                                                echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                                echo '</div>';
                                            }
                                        }
                                    } else {
                                        // No preview available, show placeholder
                                        echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                        echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                    
                                    // Resource type tag with icon (below image)
                                    $image_extensions = ['PNG', 'JPG', 'JPEG', 'GIF', 'SVG', 'BMP', 'WEBP'];
                                    $file_ext_lower = strtolower($file_extension);
                                    
                                    // Determine display name and icon
                                    if (in_array($file_extension, ['HTML', 'HTM'])) {
                                        $format_tag_display = 'Videos';
                                        $format_icon = 'fa-video';
                                        $format_type = 'videos';
                                    } else if (in_array($file_extension, $image_extensions)) {
                                        $format_tag_display = 'Images';
                                        $format_icon = 'fa-image';
                                        $format_type = 'images';
                                    } else if ($file_ext_lower === 'pdf') {
                                        $format_tag_display = 'PDF';
                                        $format_icon = 'fa-file-pdf';
                                        $format_type = 'pdf';
                                    } else if (in_array($file_ext_lower, ['pptx', 'ppt'])) {
                                        $format_tag_display = 'PowerPoint';
                                        $format_icon = 'fa-file-powerpoint';
                                        $format_type = 'pptx';
                                    } else if (in_array($file_ext_lower, ['xlsx', 'xls', 'csv'])) {
                                        $format_tag_display = ($file_ext_lower === 'csv') ? 'CSV' : 'Excel';
                                        $format_icon = 'fa-file-excel';
                                        $format_type = ($file_ext_lower === 'csv') ? 'csv' : 'xlsx';
                                    } else if (in_array($file_ext_lower, ['docx', 'doc'])) {
                                        $format_tag_display = 'Word';
                                        $format_icon = 'fa-file-word';
                                        $format_type = 'docx';
                                    } else if ($file_ext_lower === 'url') {
                                        $format_tag_display = 'URL';
                                        $format_icon = 'fa-link';
                                        $format_type = 'url';
                                    } else {
                                        $format_tag_display = $file_extension;
                                        $format_icon = 'fa-file';
                                        $format_type = strtolower($file_extension);
                                    }
                                    
                                    echo '<div class="resource-card-format-tag" data-type="' . htmlspecialchars($format_type, ENT_QUOTES) . '">';
                                    echo '<i class="fa ' . $format_icon . '"></i>';
                                    echo '<span>' . htmlspecialchars($format_tag_display, ENT_QUOTES) . '</span>';
                                    echo '</div>';
                                    
                                    // Card body
                                    echo '<div class="resource-card-body">';
                                    echo '<h4 class="resource-card-title">' . s($filename) . '</h4>';
                                    echo '<div class="resource-card-tags">';
                                    // Show course name, section name, and folder name as colored pills
                                    $course_name = isset($resource_item['course']) ? format_string($resource_item['course']->fullname) : '';
                                    if ($course_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-course">' . html_entity_decode($course_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    if ($section_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-section">' . html_entity_decode($section_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    if ($folder_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-folder">' . html_entity_decode($folder_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    echo '</div>';
                                    echo '<div class="resource-card-actions">';
                                    echo '<button class="resource-card-action-btn view-btn" data-file-type="' . htmlspecialchars(strtolower($file_extension), ENT_QUOTES) . '" onclick="previewTeacherFile(this.closest(\'.resource-card\'))">';
                                    echo '<i class="fa fa-eye"></i> View';
                                    echo '</button>';
                                    echo '<button class="resource-card-action-btn download-btn" onclick="event.stopPropagation(); downloadResourceFile(\'' . htmlspecialchars($fileurlstring, ENT_QUOTES) . '\')">';
                                    echo '<i class="fa fa-download"></i> Download';
                                    echo '</button>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>'; // End resource-card
                                    
                                } else if ($resource_item['type'] === 'resource') {
                                    // Handle standalone resources
                                    $cm = $resource_item['cm'];
                                    $course = $resource_item['course'];
                                    $mod_name = $cm->modname;
                                    $category = $resource_item['category'];
                                    $resource_display_name = format_string($cm->name);
                                    $icon_class = 'fa-file';
                                    $cardattributes = '';
                                    $resourcefileurl = '';
                                    $resourcefileext = '';
                                    $file_extension = strtoupper($mod_name);
                                    
                                    $preview_image_url = null;
                                    $resourcefilemimetype = '';
                                    
                                    if ($mod_name === 'resource') {
                                        $resourcecontext = context_module::instance($cm->id);
                                        $resourcefiles = $filestorage->get_area_files(
                                            $resourcecontext->id,
                                            'mod_resource',
                                            'content',
                                            0,
                                            'sortorder, id',
                                            false
                                        );
                                        if (!empty($resourcefiles)) {
                                            $resourcefile = reset($resourcefiles);
                                            $resourceurl = theme_remui_kids_teacher_generate_file_url($resourcefile, $USER->id);
                                            $resourcefileurl = $resourceurl->out(false);
                                            $resourcefileext = strtolower(pathinfo($resourcefile->get_filename(), PATHINFO_EXTENSION));
                                            $file_extension = strtoupper($resourcefileext);
                                            $resourcefilemimetype = $resourcefile->get_mimetype();
                                            
                                            // Get preview image URL
                                            $preview_image_url = theme_remui_kids_teacher_generate_preview_url($resourcefile, $USER->id, $file_extension);
                                            
                                            // Set icon based on file extension
                                            if (in_array($file_extension, ['HTML', 'HTM'])) {
                                                $icon_class = 'fa-file-video';
                                            } else if ($file_extension === 'PDF') {
                                                $icon_class = 'fa-file-pdf';
                                            } else if (in_array($file_extension, ['PPTX', 'PPT'])) {
                                                $icon_class = 'fa-file-powerpoint';
                                            } else if (in_array($file_extension, ['XLSX', 'XLS', 'CSV'])) {
                                                $icon_class = 'fa-file-excel';
                                            } else if (in_array($file_extension, ['DOCX', 'DOC'])) {
                                                $icon_class = 'fa-file-word';
                                            } else if (in_array($file_extension, ['PNG', 'JPG', 'JPEG', 'GIF', 'SVG', 'BMP', 'WEBP'])) {
                                                $icon_class = 'fa-file-image';
                                            } else if (in_array($file_extension, ['MP4', 'AVI', 'MOV', 'WMV', 'MKV'])) {
                                                $icon_class = 'fa-file-video';
                                            } else if (in_array($file_extension, ['MP3', 'WAV', 'AAC', 'FLAC', 'OGG'])) {
                                                $icon_class = 'fa-file-audio';
                                            }
                                        }
                                    } else if ($mod_name === 'url') {
                                        $urlrecord = $DB->get_record('url', ['id' => $cm->instance], '*', IGNORE_MISSING);
                                        if ($urlrecord) {
                                            $fullurl = url_get_full_url($urlrecord, $cm, $course);
                                            if ($fullurl instanceof moodle_url) {
                                                $fullurl = $fullurl->out(false);
                                            }
                                            if (!empty($fullurl) && is_string($fullurl)) {
                                                $resourcefileurl = $fullurl;
                                                $resourcefileext = 'link';
                                                $file_extension = 'LINK';
                                            }
                                        }
                                    } else if ($mod_name === 'scorm') {
                                        // Get SCORM preview URL (first SCO view)
                                        $preview_image_url = theme_remui_kids_teacher_generate_scorm_preview_url($cm->id, $USER->id);
                                        $file_extension = 'SCORM';
                                        $resourcefileurl = $CFG->wwwroot . '/mod/scorm/view.php?id=' . $cm->id;
                                    }
                                    
                                    // Set icon based on module type
                                    if ($mod_name === 'url') {
                                        $icon_class = 'fa-link';
                                    } else if ($mod_name === 'scorm') {
                                        $icon_class = 'fa-graduation-cap';
                                    } else if ($mod_name === 'page') {
                                        $icon_class = 'fa-file-alt';
                                    } else if ($mod_name === 'h5pactivity' || $mod_name === 'h5p') {
                                        $icon_class = 'fa-file-video';
                                    } else if ($mod_name === 'assign') {
                                        $icon_class = 'fa-tasks';
                                    } else if ($mod_name === 'quiz') {
                                        $icon_class = 'fa-question-circle';
                                    } else if ($mod_name === 'book') {
                                        $icon_class = 'fa-book';
                                    }
                                    
                                    // Determine colors
                                    $icon_color = '#6c757d';
                                    $bg_color = '#f8f9fa';
                                    if ($file_extension === 'PDF') {
                                        $icon_color = '#dc3545';
                                        $bg_color = '#f8d7da';
                                    } else if (in_array($file_extension, ['PPTX', 'PPT'])) {
                                        $icon_color = '#fd7e14';
                                        $bg_color = '#ffe5d0';
                                    } else if (in_array($file_extension, ['XLSX', 'XLS', 'CSV'])) {
                                        $icon_color = '#28a745';
                                        $bg_color = '#d4edda';
                                    } else if (in_array($file_extension, ['DOCX', 'DOC'])) {
                                        $icon_color = '#007bff';
                                        $bg_color = '#cfe2ff';
                                    } else if ($file_extension === 'SCORM') {
                                        $icon_color = '#9c27b0';
                                        $bg_color = '#e1bee7';
                                    }
                                    
                                    // Generate grid card for resource
                                    $category_id = isset($resource_item['category_id']) ? $resource_item['category_id'] : 0;
                                    $direct_category_id = isset($resource_item['direct_category_id']) ? $resource_item['direct_category_id'] : 0;
                                    $course_id = isset($resource_item['course']) ? $resource_item['course']->id : 0;
                                    $section_name = isset($resource_item['section']) ? $resource_item['section'] : '';
                                    $folder_name = isset($resource_item['folder_name']) ? $resource_item['folder_name'] : '';
                                    $folder_tag = isset($resource_item['folder_tag']) ? $resource_item['folder_tag'] : '';
                                    echo '<div class="resource-card" ';
                                    echo 'data-resource-type="' . htmlspecialchars(strtolower($mod_name), ENT_QUOTES) . '" ';
                                    echo 'data-category="' . htmlspecialchars($category, ENT_QUOTES) . '" ';
                                    echo 'data-category-id="' . htmlspecialchars($category_id, ENT_QUOTES) . '" ';
                                    echo 'data-direct-category-id="' . htmlspecialchars($direct_category_id, ENT_QUOTES) . '" ';
                                    echo 'data-course-id="' . htmlspecialchars($course_id, ENT_QUOTES) . '" ';
                                    echo 'data-section="' . htmlspecialchars($section_name, ENT_QUOTES) . '" ';
                                    echo 'data-folder-name="' . htmlspecialchars($folder_name, ENT_QUOTES) . '" ';
                                    echo 'data-folder-tag="' . htmlspecialchars(strtolower($folder_tag ?: ''), ENT_QUOTES) . '" ';
                                    if ($resourcefileurl) {
                                        echo 'data-file-url="' . htmlspecialchars($resourcefileurl, ENT_QUOTES) . '" ';
                                        echo 'data-file-ext="' . htmlspecialchars($resourcefileext, ENT_QUOTES) . '" ';
                                    }
                                    // Add preview URL for PPT and DOCX files (for viewing first slide/image)
                                    if ($preview_image_url && in_array(strtolower($resourcefileext), ['ppt', 'pptx', 'docx'])) {
                                        echo 'data-preview-url="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" ';
                                    }
                                    echo 'data-file-name="' . htmlspecialchars($resource_display_name, ENT_QUOTES) . '" ';
                                    echo 'data-cm-id="' . $cm->id . '" ';
                                    echo 'data-mod-name="' . htmlspecialchars($mod_name, ENT_QUOTES) . '" ';
                                    echo 'onclick="openResource(this, ' . $cm->id . ', \'' . addslashes($cm->name) . '\', \'' . $mod_name . '\')">';
                                    
                                    // Card image/icon container
                                    $has_preview = !empty($preview_image_url) && $file_extension !== 'LINK';
                                    echo '<div class="resource-card-image-container">';
                                    if ($has_preview && $mod_name === 'scorm') {
                                        // For SCORM - show first SCO view in iframe
                                        if ($preview_image_url) {
                                            echo '<iframe class="resource-card-preview-iframe" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" style="display: none;" allowfullscreen></iframe>';
                                        }
                                        echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: flex;">';
                                        echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                        echo '</div>';
                                    } else if ($has_preview && $mod_name === 'resource') {
                                        // Show preview image for resource files
                                        $file_ext_lower = strtolower($file_extension);
                                        if (in_array($file_ext_lower, ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm'])) {
                                            // For videos, use video element to generate thumbnail
                                            echo '<video class="resource-card-image" style="display: none;" preload="metadata" data-src="' . htmlspecialchars($resourcefileurl, ENT_QUOTES) . '">';
                                            echo '<source src="' . htmlspecialchars($resourcefileurl, ENT_QUOTES) . '" type="' . htmlspecialchars($resourcefilemimetype, ENT_QUOTES) . '">';
                                            echo '</video>';
                                            echo '<canvas class="resource-card-video-thumbnail" style="width: 100%; height: 100%; object-fit: cover; display: none;"></canvas>';
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else if ($file_ext_lower === 'pdf') {
                                            // Use canvas for PDF first page preview
                                            echo '<canvas class="resource-card-pdf-preview" data-pdf-url="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" style="width: 100%; height: 100%; display: none;"></canvas>';
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else if (in_array($file_ext_lower, ['ppt', 'pptx'])) {
                                            // For PPT files - show first slide as cover image
                                            if ($preview_image_url) {
                                                echo '<img class="resource-card-image resource-card-ppt-preview" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($resource_display_name, ENT_QUOTES) . '" style="display: none;" />';
                                            }
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: flex;">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else if (in_array($file_ext_lower, ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'])) {
                                            // For Office docs - show first page/sheet as cover image
                                            if ($preview_image_url) {
                                                echo '<img class="resource-card-image resource-card-office-preview" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($resource_display_name, ENT_QUOTES) . '" style="display: none;" />';
                                            }
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: flex;">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else if (in_array($file_ext_lower, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'bmp', 'webp'])) {
                                            // For images, use img tag - show image by default, hide placeholder
                                            echo '<img class="resource-card-image" src="' . htmlspecialchars($preview_image_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($resource_display_name, ENT_QUOTES) . '" onload="this.style.display=\'block\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'none\';" onerror="this.style.display=\'none\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'flex\';" />';
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . '; display: none;">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        } else {
                                            // Fallback to placeholder
                                            echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                            echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                            echo '</div>';
                                        }
                                    } else {
                                        // No preview available, show placeholder
                                        echo '<div class="resource-card-image-placeholder" style="background: ' . $bg_color . ';">';
                                        echo '<i class="fa ' . $icon_class . '" style="font-size: 64px; color: ' . $icon_color . ';"></i>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                    
                                    // Resource type tag with icon (below image)
                                    if ($file_extension !== 'LINK') {
                                        $image_extensions = ['PNG', 'JPG', 'JPEG', 'GIF', 'SVG', 'BMP', 'WEBP'];
                                        $file_ext_lower = strtolower($file_extension);
                                        
                                        // Determine display name and icon
                                        if (in_array($file_extension, ['HTML', 'HTM'])) {
                                            $format_tag_display = 'Videos';
                                            $format_icon = 'fa-video';
                                            $format_type = 'videos';
                                        } else if (in_array($file_extension, $image_extensions)) {
                                            $format_tag_display = 'Images';
                                            $format_icon = 'fa-image';
                                            $format_type = 'images';
                                        } else if ($file_ext_lower === 'pdf') {
                                            $format_tag_display = 'PDF';
                                            $format_icon = 'fa-file-pdf';
                                            $format_type = 'pdf';
                                        } else if (in_array($file_ext_lower, ['pptx', 'ppt'])) {
                                            $format_tag_display = 'PowerPoint';
                                            $format_icon = 'fa-file-powerpoint';
                                            $format_type = 'pptx';
                                        } else if (in_array($file_ext_lower, ['xlsx', 'xls', 'csv'])) {
                                            $format_tag_display = ($file_ext_lower === 'csv') ? 'CSV' : 'Excel';
                                            $format_icon = 'fa-file-excel';
                                            $format_type = ($file_ext_lower === 'csv') ? 'csv' : 'xlsx';
                                        } else if (in_array($file_ext_lower, ['docx', 'doc'])) {
                                            $format_tag_display = 'Word';
                                            $format_icon = 'fa-file-word';
                                            $format_type = 'docx';
                                        } else if ($file_ext_lower === 'url') {
                                            $format_tag_display = 'URL';
                                            $format_icon = 'fa-link';
                                            $format_type = 'url';
                                        } else {
                                            $format_tag_display = $file_extension;
                                            $format_icon = 'fa-file';
                                            $format_type = strtolower($file_extension);
                                        }
                                        
                                        echo '<div class="resource-card-format-tag" data-type="' . htmlspecialchars($format_type, ENT_QUOTES) . '">';
                                        echo '<i class="fa ' . $format_icon . '"></i>';
                                        echo '<span>' . htmlspecialchars($format_tag_display, ENT_QUOTES) . '</span>';
                                        echo '</div>';
                                    }
                                    
                                    // Card body
                                    echo '<div class="resource-card-body">';
                                    echo '<h4 class="resource-card-title">' . format_string($cm->name) . '</h4>';
                                    echo '<div class="resource-card-tags">';
                                    // Show course name, section name, and folder name as colored pills
                                    $course_name = isset($resource_item['course']) ? format_string($resource_item['course']->fullname) : '';
                                    if ($course_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-course">' . html_entity_decode($course_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    if ($section_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-section">' . html_entity_decode($section_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    if ($folder_name) {
                                        echo '<span class="resource-card-tag resource-card-tag-folder">' . html_entity_decode($folder_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                                    }
                                    echo '</div>';
                                    echo '<div class="resource-card-actions">';
                                    $view_btn_file_type = !empty($resourcefileext) ? strtolower($resourcefileext) : strtolower($mod_name);
                                    echo '<button class="resource-card-action-btn view-btn" data-file-type="' . htmlspecialchars($view_btn_file_type, ENT_QUOTES) . '" onclick="event.stopPropagation(); openResource(this.closest(\'.resource-card\'), ' . $cm->id . ', \'' . addslashes($cm->name) . '\', \'' . $mod_name . '\')">';
                                    echo '<i class="fa fa-eye"></i> View';
                                    echo '</button>';
                                    if ($resourcefileurl && $resourcefileext !== 'link') {
                                        echo '<button class="resource-card-action-btn download-btn" onclick="event.stopPropagation(); downloadResourceFile(\'' . htmlspecialchars($resourcefileurl, ENT_QUOTES) . '\')">';
                                        echo '<i class="fa fa-download"></i> Download';
                                        echo '</button>';
                                    }
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>'; // End resource-card
                                }
                            }
                            
                            echo '</div>'; // End resources-grid
                        ?>
                                
                                <!-- Pagination Controls -->
                                <div class="resources-pagination" id="resourcesPagination" style="display: none;">
                                    <button class="pagination-button" id="paginationPrev" onclick="changePage(currentPage - 1)" disabled>
                                        <i class="fa fa-chevron-left"></i> Previous
                                    </button>
                                    <div class="pagination-page-numbers" id="paginationPages"></div>
                                    <button class="pagination-button" id="paginationNext" onclick="changePage(currentPage + 1)">
                                        Next <i class="fa fa-chevron-right"></i>
                                    </button>
                                    <div class="pagination-info" id="paginationInfo"></div>
                                </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- End content-wrapper -->
        </div><!-- End teacher-main-content -->
    </div><!-- End teacher-dashboard-wrapper -->
</div><!-- End teacher-course-view-wrapper -->

<!-- Folder Description Modal -->
<div id="folderDescriptionModal" class="folder-description-modal">
    <div class="folder-description-content">
        <div class="folder-description-header">
            <h3 class="folder-description-title" id="folderDescriptionTitle">Folder Description</h3>
            <button class="folder-description-close" onclick="closeFolderDescription()">×</button>
        </div>
        <div class="folder-description-body" id="folderDescriptionBody">
            <!-- Description content will be inserted here -->
        </div>
    </div>
</div>

<!-- PPT Player Modal -->
<div id="pptPlayerModal" class="ppt-player-modal">
    <div class="ppt-player-content" id="pptPlayerContent">
        <div class="ppt-player-header">
            <h3 class="ppt-player-title" id="pptPlayerTitle">Resource Viewer</h3>
            <div class="ppt-player-header-controls">
                <div class="ppt-player-actions">
                    <button id="pptDownloadButton" class="ppt-download-btn" type="button" onclick="downloadCurrentResource()" disabled>
                        <i class="fa fa-download"></i>
                        Download File
                    </button>
                    <button id="pptFullscreenButton" class="ppt-player-icon-btn" type="button" onclick="togglePPTFullscreen()" title="Toggle fullscreen" aria-pressed="false" style="display:none;">
                        <i class="fa fa-expand"></i>
                    </button>
                </div>
                <button class="ppt-player-close" onclick="closePPTPlayer()">×</button>
            </div>
        </div>
        <div class="ppt-player-body">
            <iframe id="pptPlayerIframe" class="ppt-player-iframe" src="" allowfullscreen></iframe>
            <div id="pptSpreadsheetContainer" class="ppt-spreadsheet-container" hidden></div>
            
        </div>
    </div>
</div>

<?php
$officeviewer_enabled = true;
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/tier_cards_categories.js"></script>
<script>
const OFFICE_VIEWER_ENABLED = <?php echo $officeviewer_enabled ? 'true' : 'false'; ?>;
const PPT_FULLSCREEN_ELEMENT_ID = 'pptPlayerContent';

// Pagination constants
const ITEMS_PER_PAGE = 20;
let currentPage = 1;

// Check if PPT preview image is valid (not transparent placeholder)
// Define this early so it's available for inline onload handlers
function checkPPTPreviewImage(img) {
    if (!img) return;
    
    try {
        // Find placeholder - try next sibling first, then search in parent container
        let placeholder = img.nextElementSibling;
        if (!placeholder || !placeholder.classList.contains('resource-card-image-placeholder')) {
            const container = img.closest('.resource-card-image-container');
            if (container) {
                placeholder = container.querySelector('.resource-card-image-placeholder');
            }
        }
        
        if (!placeholder) {
            return;
        }
        
        // Validate image dimensions and check for corrupted/invalid images
        const width = img.naturalWidth || 0;
        const height = img.naturalHeight || 0;
        
        // Check if image failed to load (0x0 or invalid)
        if (width === 0 || height === 0 || isNaN(width) || isNaN(height)) {
            img.style.display = 'none';
            placeholder.style.display = 'flex';
            return;
        }
        
        // Check if image is valid (not 1x1 transparent PNG)
        // A real preview should be at least 50x50 pixels (lowered threshold for small slides)
        if (width >= 50 && height >= 50) {
            // Valid preview image - show it
            img.style.display = 'block';
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.style.objectPosition = 'center';
            placeholder.style.display = 'none';
        } else if (width > 1 && height > 1) {
            // Small image but might be valid - show it anyway
            img.style.display = 'block';
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.style.objectPosition = 'center';
            placeholder.style.display = 'none';
        } else {
            // Transparent placeholder (1x1 PNG) or invalid - keep placeholder visible
            img.style.display = 'none';
            placeholder.style.display = 'flex';
        }
    } catch (e) {
        console.error('Error in checkPPTPreviewImage:', e);
        // On error, hide image and show placeholder
        if (img) {
            img.style.display = 'none';
        }
        const container = img ? img.closest('.resource-card-image-container') : null;
        if (container) {
            const placeholder = container.querySelector('.resource-card-image-placeholder');
            if (placeholder) {
                placeholder.style.display = 'flex';
            }
        }
    }
}

// Sidebar functions are now in includes/sidebar.php

// Course and section selection removed - now showing all resources from all courses

// Filter resources by file type (legacy - kept for backwards compatibility)
function filterResourcesByType() {
    filterResources();
}

// Toggle filter section collapse/expand
function toggleFilterSection(element) {
    element.classList.toggle('collapsed');
    const list = element.nextElementSibling;
    if (list && list.classList.contains('filter-checkbox-list')) {
        list.style.display = element.classList.contains('collapsed') ? 'none' : 'block';
    }
}

// Populate sections and folders filters based on selected courses
function updateSectionsAndFoldersFilters() {
    // Get all selected course IDs from sidebar checkboxes
    const selectedCourseIds = [];
    document.querySelectorAll('#categoryFilters input[type="checkbox"][data-filter-type="course"]:checked').forEach(checkbox => {
        const courseId = checkbox.getAttribute('data-course-id');
        if (courseId) {
            selectedCourseIds.push(parseInt(courseId));
        }
    });
    
    // Also check for courses selected via tier cards (course cards with .checked class)
    document.querySelectorAll('.course-card.checked').forEach(courseCard => {
        const courseId = courseCard.getAttribute('data-course-id');
        if (courseId) {
            const courseIdInt = parseInt(courseId);
            if (!selectedCourseIds.includes(courseIdInt)) {
                selectedCourseIds.push(courseIdInt);
            }
        }
        // Also check for all-course-ids attribute (for deduplicated courses)
        const allCourseIdsAttr = courseCard.getAttribute('data-all-course-ids');
        if (allCourseIdsAttr) {
            try {
                const allCourseIds = JSON.parse(allCourseIdsAttr);
                if (Array.isArray(allCourseIds)) {
                    allCourseIds.forEach(cid => {
                        const cidInt = parseInt(cid);
                        if (cidInt && !selectedCourseIds.includes(cidInt)) {
                            selectedCourseIds.push(cidInt);
                        }
                    });
                }
            } catch (e) {
                // Ignore JSON parse errors
            }
        }
    });
    
    const sectionsFilterCheckboxSection = document.getElementById('sectionsFilterCheckboxSection');
    const sectionsFiltersList = document.getElementById('sectionsFilters');
    const foldersFilterSection = document.getElementById('foldersFilterSection');
    const sectionsFilterSelect = document.getElementById('sectionsFilterSelect');
    const foldersFilterSelect = document.getElementById('foldersFilterSelect');
    
    // If no courses selected, hide sections filter section and disable dropdowns
    if (selectedCourseIds.length === 0) {
        if (sectionsFilterCheckboxSection) {
            sectionsFilterCheckboxSection.style.display = 'none';
        }
        if (sectionsFiltersList) {
            sectionsFiltersList.innerHTML = '';
        }
        if (sectionsFilterSelect) {
            sectionsFilterSelect.disabled = true;
            sectionsFilterSelect.innerHTML = '<option value="">All Sections</option>';
        }
        if (foldersFilterSelect) {
            foldersFilterSelect.disabled = true;
            foldersFilterSelect.innerHTML = '<option value="">All Folders</option>';
        }
        // Hide folders filter when no courses are selected
        if (foldersFilterSection) {
            foldersFilterSection.style.display = 'none';
        }
        return;
    }
    
    // Show sections filter section when courses are selected
    if (sectionsFilterCheckboxSection) {
        sectionsFilterCheckboxSection.style.display = 'block';
    }
    
    // Get selected subsections from sidebar checkbox filter
    const selectedSubsections = [];
    if (sectionsFiltersList) {
        sectionsFiltersList.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
            const sectionValue = checkbox.getAttribute('data-filter-value');
            if (sectionValue) {
                selectedSubsections.push(decodeHtmlEntities(sectionValue));
            }
        });
    }
    
    // Enable/disable folders filter based on subsection selection
    // Keep it visible but disabled when no subsections are selected
    if (foldersFilterSelect) {
        if (selectedSubsections.length > 0) {
        foldersFilterSelect.disabled = false;
            if (foldersFilterSection) {
                foldersFilterSection.style.display = 'block';
            }
        } else {
            foldersFilterSelect.disabled = true;
            foldersFilterSelect.innerHTML = '<option value="">All Folders</option>';
            // Keep it visible but disabled - don't hide it completely
            if (foldersFilterSection) {
                foldersFilterSection.style.display = 'block';
            }
        }
    }
    
    // Disable sections select dropdown (no longer used)
    if (sectionsFilterSelect) {
        sectionsFilterSelect.disabled = true;
    }
    
    // Get selected section from select dropdown (for backward compatibility)
    const selectedSections = [];
    if (sectionsFilterSelect && sectionsFilterSelect.value) {
        selectedSections.push(sectionsFilterSelect.value);
    }
    
    // Also include selected subsections from checkbox filter
    selectedSubsections.forEach(subsection => {
        if (!selectedSections.includes(subsection)) {
            selectedSections.push(subsection);
        }
    });
    
    // Collect sections from course structure (from window.courseSectionsData)
    const sectionsSet = new Set();
    const foldersMap = new Map(); // section => Set of folders
    const mainSectionsSet = new Set(); // Main sections (without subsections)
    const mainSectionFoldersMap = new Map(); // main section => Set of all folders in that main section
    
    // Debug: Check if data exists
    if (!window.courseSectionsData) {
        console.warn('courseSectionsData not found');
    }
    if (!window.courseFoldersData) {
        console.warn('courseFoldersData not found');
    }
    
    if (window.courseSectionsData) {
        selectedCourseIds.forEach(courseId => {
            // Try both string and number keys
            const courseSections = window.courseSectionsData[courseId] || 
                                   window.courseSectionsData[String(courseId)] || 
                                   window.courseSectionsData[parseInt(courseId)] || [];
            
            courseSections.forEach(section => {
                const decodedSection = decodeHtmlEntities(section);
                
                // Collect ALL sections - we'll filter them later when displaying
                sectionsSet.add(decodedSection);
                
                // Check if this is a main section (doesn't contain " > ")
                if (decodedSection.indexOf(' > ') === -1) {
                    mainSectionsSet.add(decodedSection);
                }
                
                // Initialize folder set for this section if not exists
                if (!foldersMap.has(decodedSection)) {
                    foldersMap.set(decodedSection, new Set());
                }
            });
            
            // Get main sections for this course
            if (window.courseMainSectionsData) {
                const courseMainSections = window.courseMainSectionsData[courseId] || 
                                           window.courseMainSectionsData[String(courseId)] || 
                                           window.courseMainSectionsData[parseInt(courseId)] || [];
                courseMainSections.forEach(mainSection => {
                    mainSectionsSet.add(decodeHtmlEntities(mainSection));
                });
            }
            
            // Get folders for this course from window.courseFoldersData
            if (window.courseFoldersData) {
                const courseFolders = window.courseFoldersData[courseId] || 
                                     window.courseFoldersData[String(courseId)] || 
                                     window.courseFoldersData[parseInt(courseId)] || null;
                if (courseFolders) {
                    Object.keys(courseFolders).forEach(section => {
                        // Only add folders if section is selected or no sections are selected
                        if (selectedSections.length === 0 || selectedSections.includes(section)) {
                            const folders = courseFolders[section] || [];
                            folders.forEach(folder => {
                                const decodedFolder = decodeHtmlEntities(folder);
                                const decodedSection = decodeHtmlEntities(section);
                                if (!foldersMap.has(decodedSection)) {
                                    foldersMap.set(decodedSection, new Set());
                                }
                                foldersMap.get(decodedSection).add(decodedFolder);
                            });
                        }
                    });
                }
            }
            
            // Get all folders in main sections from window.courseMainSectionFoldersData
            if (window.courseMainSectionFoldersData) {
                const courseMainSectionFolders = window.courseMainSectionFoldersData[courseId] || 
                                                 window.courseMainSectionFoldersData[String(courseId)] || 
                                                 window.courseMainSectionFoldersData[parseInt(courseId)] || null;
                if (courseMainSectionFolders) {
                    Object.keys(courseMainSectionFolders).forEach(mainSection => {
                        const decodedMainSection = decodeHtmlEntities(mainSection);
                        if (!mainSectionFoldersMap.has(decodedMainSection)) {
                            mainSectionFoldersMap.set(decodedMainSection, new Set());
                        }
                        const folders = courseMainSectionFolders[mainSection] || [];
                        folders.forEach(folder => {
                            mainSectionFoldersMap.get(decodedMainSection).add(decodeHtmlEntities(folder));
                        });
                    });
                }
            }
        });
    }
    
    // All sections have been collected - now filter them for display based on active filter
    
    // Fallback: Also get sections and folders from resource cards (for backward compatibility)
    // Collect ALL sections from ALL cards - we'll filter them later when displaying
    const allCards = document.querySelectorAll('.resource-card');
    allCards.forEach(card => {
        const cardCourseId = parseInt(card.getAttribute('data-course-id')) || 0;
        if (selectedCourseIds.includes(cardCourseId)) {
            const section = decodeHtmlEntities(card.getAttribute('data-section') || '');
            const folder = decodeHtmlEntities(card.getAttribute('data-folder-name') || '');
            
            if (section && section.trim() !== '') {
                sectionsSet.add(section);
                
                // Check if this is a main section (doesn't contain " > ")
                if (section.indexOf(' > ') === -1) {
                    mainSectionsSet.add(section);
                } else {
                    // Extract main section name from subsection (part before " > ")
                    const mainSectionName = section.split(' > ')[0];
                    mainSectionsSet.add(mainSectionName);
                }
                
                // Add folder to the section's folder set
                if (folder && folder.trim() !== '') {
                    if (!foldersMap.has(section)) {
                        foldersMap.set(section, new Set());
                    }
                    foldersMap.get(section).add(folder);
                }
            }
        }
    });
    
    // Populate sections filter - show only Main Sections in select dropdown
    const sectionsSelect = document.getElementById('sectionsFilterSelect');
    if (sectionsSelect) {
        // Preserve the currently selected value
        const currentSelectedValue = sectionsSelect.value;
        
        // Clear existing options except "All Sections"
        sectionsSelect.innerHTML = '<option value="">All Sections</option>';
        
        // Show all Main Sections (sections without " > " separator)
        const mainSectionsArray = Array.from(mainSectionsSet).sort();
        if (mainSectionsArray.length > 0) {
            mainSectionsArray.forEach(mainSection => {
                const option = document.createElement('option');
                option.value = mainSection;
                option.textContent = mainSection;
                sectionsSelect.appendChild(option);
            });
        }
        
        // Restore the selected value if it still exists in the new options
        if (currentSelectedValue && Array.from(sectionsSelect.options).some(opt => opt.value === currentSelectedValue)) {
            sectionsSelect.value = currentSelectedValue;
        } else {
            // If the previously selected value no longer exists, reset to "All Sections"
            sectionsSelect.value = '';
        }
    }
    
    // Populate sections checkbox filter - show only subsections, excluding those from active main filter
    if (sectionsFiltersList) {
        // Preserve currently checked sections
        const checkedSections = new Set();
        sectionsFiltersList.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
            checkedSections.add(checkbox.getAttribute('data-filter-value'));
        });
        
        // Clear existing checkboxes
        sectionsFiltersList.innerHTML = '';
        
        // Get only subsections (sections containing " > ")
        let filteredSubsections = Array.from(sectionsSet).filter(section => section.indexOf(' > ') !== -1);
        
        // Get the current active filter - prioritize the variable (set immediately) over DOM check
        let activeFilter = 'all';
        
        // First, check the currentResourceTypeFilter variable (most reliable, set immediately)
        if (typeof currentResourceTypeFilter !== 'undefined' && currentResourceTypeFilter && currentResourceTypeFilter !== 'all') {
            activeFilter = String(currentResourceTypeFilter).toLowerCase().trim();
        } else {
            // Fallback: check the active tier card in DOM
            const activeTierCard = document.querySelector('.tier-card.active');
            if (activeTierCard) {
                if (activeTierCard.classList.contains('tier-card-0')) {
                    activeFilter = 'all';
                } else if (activeTierCard.classList.contains('tier-card-1')) {
                    activeFilter = 'plan';
                } else if (activeTierCard.classList.contains('tier-card-2')) {
                    activeFilter = 'teach';
                } else if (activeTierCard.classList.contains('tier-card-3')) {
                    activeFilter = 'assess';
                } else {
                    // Check data-tab attribute as additional fallback
                    const dataTab = activeTierCard.getAttribute('data-tab');
                    if (dataTab) {
                        if (dataTab === 'planning' || dataTab === 'plan') {
                            activeFilter = 'plan';
                        } else if (dataTab === 'resources' || dataTab === 'teach') {
                            activeFilter = 'teach';
                        } else if (dataTab === 'assessments' || dataTab === 'assess') {
                            activeFilter = 'assess';
                        } else if (dataTab === 'all') {
                            activeFilter = 'all';
                        }
                    }
                }
            }
        }
        
        // Normalize activeFilter to lowercase and trim
        activeFilter = String(activeFilter).toLowerCase().trim();
        
        // If a main filter is active (plan/teach/assess), show ONLY subsections from that main section
        // When "Plan" is active, show ONLY "Plan > ..." subsections, exclude "Teach > ..." and "Assess > ..."
        // When "Teach" is active, show ONLY "Teach > ..." subsections, exclude "Plan > ..." and "Assess > ..."
        // When "Assess" is active, show ONLY "Assess > ..." subsections, exclude "Plan > ..." and "Teach > ..."
        if (activeFilter !== 'all' && activeFilter) {
            filteredSubsections = filteredSubsections.filter(section => {
                // Extract main section name from subsection (part before " > ")
                const sectionParts = section.split(' > ');
                if (sectionParts.length < 2) {
                    // Not a subsection, exclude it (we only want subsections in this filter)
                    return false;
                }
                
                const subsectionMainSection = sectionParts[0].trim().toLowerCase();
                const activeFilterLower = activeFilter.toLowerCase().trim();
                
                // Keep ONLY subsections that match the active filter
                // If Plan is active, keep "Plan > ...", exclude "Teach > ..." and "Assess > ..."
                // If Teach is active, keep "Teach > ...", exclude "Plan > ..." and "Assess > ..."
                const matchesActiveFilter = subsectionMainSection === activeFilterLower;
                return matchesActiveFilter; // Return true to keep (if matches), false to exclude (if doesn't match)
            });
        }
        
        // Sort the filtered subsections
        const allSectionsArray = filteredSubsections.sort();
        
        if (allSectionsArray.length > 0) {
            allSectionsArray.forEach((section) => {
                const li = document.createElement('li');
                li.className = 'filter-checkbox-item';
                
                const label = document.createElement('label');
                label.className = 'filter-checkbox-label';
                
                // Extract only the subsection name (remove "Plan > ", "Teach > ", "Assess > " prefix)
                let displaySection = section;
                if (section.indexOf(' > ') !== -1) {
                    // Get everything after " > " (the subsection name)
                    const parts = section.split(' > ');
                    if (parts.length > 1) {
                        displaySection = parts.slice(1).join(' > '); // Join in case there are multiple " > " separators
                    }
                }
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'filter-checkbox';
                checkbox.setAttribute('data-filter-type', 'section');
                checkbox.setAttribute('data-filter-value', section); // Keep original value for filtering
                checkbox.onchange = function() { 
                    updateSectionsAndFoldersFilters();
                    filterResources(); 
                };
                
                // Restore checked state if it was previously checked
                if (checkedSections.has(section)) {
                    checkbox.checked = true;
                }
                
                label.appendChild(checkbox);
                label.appendChild(document.createTextNode(displaySection)); // Display only subsection name
                li.appendChild(label);
                sectionsFiltersList.appendChild(li);
            });
        }
    }
    
    // Populate folders and files filter - show only folders from selected subsections
    if (foldersFilterSelect) {
        // Only enable if subsections are selected from sidebar checkbox filter
        const selectedSubsectionsFromCheckbox = [];
        if (sectionsFiltersList) {
            sectionsFiltersList.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                const sectionValue = checkbox.getAttribute('data-filter-value');
                if (sectionValue) {
                    selectedSubsectionsFromCheckbox.push(decodeHtmlEntities(sectionValue));
                }
            });
        }
        
        // If no subsections selected, disable and clear the folders filter
        if (selectedSubsectionsFromCheckbox.length === 0) {
            foldersFilterSelect.disabled = true;
            foldersFilterSelect.innerHTML = '<option value="">All Folders</option>';
            // Keep it visible but disabled
            if (foldersFilterSection) {
                foldersFilterSection.style.display = 'block';
            }
        } else {
            // Enable folders filter when subsections are selected
            foldersFilterSelect.disabled = false;
            if (foldersFilterSection) {
                foldersFilterSection.style.display = 'block';
            }
            // Preserve the currently selected value
            const currentSelectedValue = foldersFilterSelect.value;
            
            foldersFilterSelect.innerHTML = '<option value="">All Folders</option>';
            const foldersSet = new Set();
            
            // Collect folders ONLY from the selected subsections
            selectedSubsectionsFromCheckbox.forEach(selectedSubsection => {
                // Get folders directly from the selected subsection
                if (foldersMap.has(selectedSubsection)) {
                    foldersMap.get(selectedSubsection).forEach(folder => {
                        foldersSet.add(decodeHtmlEntities(folder));
                    });
                }
            });
        
            // Also collect from resource cards - only from selected subsections
        allCards.forEach(card => {
            const cardCourseId = parseInt(card.getAttribute('data-course-id')) || 0;
            if (selectedCourseIds.includes(cardCourseId)) {
                const cardSection = decodeHtmlEntities(card.getAttribute('data-section') || '');
                const cardFolder = decodeHtmlEntities(card.getAttribute('data-folder-name') || '');
                
                    // Add folder only if it matches one of the selected subsections
                if (cardFolder && cardFolder.trim() !== '') {
                        if (cardSection && selectedSubsectionsFromCheckbox.includes(cardSection)) {
                        foldersSet.add(cardFolder);
                    }
                }
            }
        });
        
        // Sort folders and populate select
        const foldersArray = Array.from(foldersSet).sort();
        
        // Add folders without emoji
        foldersArray.forEach(folder => {
            const option = document.createElement('option');
            option.value = folder;
            option.textContent = folder;
            foldersFilterSelect.appendChild(option);
        });
        
        // Show message if no folders found
            if (foldersArray.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No folders found...';
            option.disabled = true;
            foldersFilterSelect.appendChild(option);
        }
        
        // Restore the selected value if it still exists in the new options
        if (currentSelectedValue && Array.from(foldersFilterSelect.options).some(opt => opt.value === currentSelectedValue)) {
            foldersFilterSelect.value = currentSelectedValue;
        } else {
            // If the previously selected value no longer exists, reset to "All Folders"
            foldersFilterSelect.value = '';
            }
        }
    }
    
    // Folders filter section visibility is already handled above based on subsection selection
}

// Toggle multi-select dropdown
function toggleMultiSelectDropdown(filterId) {
    const button = document.querySelector(`#${filterId}Section .multi-select-button`);
    const dropdown = document.getElementById(`${filterId}Dropdown`);
    
    if (!button || !dropdown) return;
    
    // Close all other dropdowns
    document.querySelectorAll('.multi-select-dropdown-menu').forEach(menu => {
        if (menu !== dropdown) {
            menu.classList.remove('active');
            const otherButton = menu.closest('.multi-select-dropdown')?.querySelector('.multi-select-button');
            if (otherButton) otherButton.classList.remove('active');
        }
    });
    
    // Toggle current dropdown
    button.classList.toggle('active');
    dropdown.classList.toggle('active');
}

// Update multi-select button text based on selected items
// Handle section select change
function handleSectionSelect(selectElement) {
    // Update folders filter when section changes
    updateSectionsAndFoldersFilters();
}

function updateMultiSelectText(filterId) {
    const listId = `${filterId}List`;
    const textElement = document.getElementById(`${filterId}Text`);
    const optionsList = document.getElementById(listId);
    
    if (!textElement || !optionsList) return;
    
    const checked = optionsList.querySelectorAll('input[type="checkbox"]:checked');
    
    if (checked.length === 0) {
        textElement.textContent = filterId === 'sectionsFilters' ? 'All Sections' : 'All Folders';
    } else if (checked.length === 1) {
        textElement.textContent = checked[0].closest('label').querySelector('span').textContent;
    } else {
        textElement.textContent = `${checked.length} selected`;
    }
}

// Filter options in multi-select dropdown
function filterMultiSelectOptions(input, listId) {
    const searchTerm = input.value.toLowerCase();
    const optionsList = document.getElementById(listId);
    
    if (!optionsList) return;
    
    optionsList.querySelectorAll('.multi-select-option').forEach(option => {
        const text = option.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            option.classList.remove('hidden');
        } else {
            option.classList.add('hidden');
        }
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-dropdown')) {
        document.querySelectorAll('.multi-select-dropdown-menu').forEach(menu => {
            menu.classList.remove('active');
            const button = menu.closest('.multi-select-dropdown')?.querySelector('.multi-select-button');
            if (button) button.classList.remove('active');
        });
    }
});

// Comprehensive filter function with search and checkbox filters
// Helper function to decode HTML entities
function decodeHtmlEntities(text) {
    if (!text) return '';
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

        // Filter by resource type (Plan, Teach, Assess) - based on section names
let currentResourceTypeFilter = 'all';

// Update resource tab counts based on folder tags
function updateResourceTabCounts() {
    // Count all resource cards (not filtered)
    const allCards = document.querySelectorAll('.resource-card');
    
    let allCount = allCards.length;
    let planCount = 0;
    let teachCount = 0;
    let assessCount = 0;
    
    allCards.forEach(card => {
        const folderTag = (card.getAttribute('data-folder-tag') || '').toLowerCase().trim();
        if (folderTag === 'plan') {
            planCount++;
        } else if (folderTag === 'teach') {
            teachCount++;
        } else if (folderTag === 'assess') {
            assessCount++;
        }
    });
    
    // Update tier card counts
    const tierCardCountAll = document.getElementById('tierCardCountAll');
    const tierCardCountPlan = document.getElementById('tierCardCountPlan');
    const tierCardCountTeach = document.getElementById('tierCardCountTeach');
    const tierCardCountAssess = document.getElementById('tierCardCountAssess');
    
    if (tierCardCountAll) tierCardCountAll.textContent = allCount;
    if (tierCardCountPlan) tierCardCountPlan.textContent = planCount;
    if (tierCardCountTeach) tierCardCountTeach.textContent = teachCount;
    if (tierCardCountAssess) tierCardCountAssess.textContent = assessCount;
}

function filterByResourceType(type) {
    currentResourceTypeFilter = type;
    
    // Update tier cards active state
    document.querySelectorAll('.tier-card').forEach(card => {
        card.classList.remove('active');
    });
    if (type === 'all') {
        document.querySelector('.tier-card-0')?.classList.add('active');
    } else if (type === 'plan') {
        document.querySelector('.tier-card-1')?.classList.add('active');
    } else if (type === 'teach') {
        document.querySelector('.tier-card-2')?.classList.add('active');
    } else if (type === 'assess') {
        document.querySelector('.tier-card-3')?.classList.add('active');
    }
    
    // Expand tier cards container and show categories
    const tierCardsContainer = document.querySelector('.tier-cards-container');
    if (tierCardsContainer) {
        tierCardsContainer.classList.add('expanded');
        // Populate categories when expanded
        if (typeof populateTierCardCategories === 'function') {
            populateTierCardCategories();
            // Render courses after categories are populated
            setTimeout(function() {
                if (typeof renderSelectedCourses === 'function') {
                    renderSelectedCourses();
                }
            }, 100);
        }
    }
    
    // Update sections filter to reflect the new active filter
    // Use a longer timeout to ensure tier card active state is set
    if (typeof updateSectionsAndFoldersFilters === 'function') {
        setTimeout(function() {
            updateSectionsAndFoldersFilters();
        }, 50);
    }
    
    // Trigger filter
    filterResources();
    
    // Update counts after filtering
    setTimeout(function() {
        if (typeof updateResourceTabCounts === 'function') {
            updateResourceTabCounts();
        }
    }, 200);
}

// Check URL parameter for filter and activate corresponding tab on page load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const filterParam = urlParams.get('filter');
    
    if (filterParam) {
        const filterType = filterParam.toLowerCase();
        if (filterType === 'plan' || filterType === 'teach' || filterType === 'assess') {
            // Set the filter immediately and then update sections
            if (typeof filterByResourceType === 'function') {
                filterByResourceType(filterType);
            } else {
                // If function not available yet, wait a bit
                setTimeout(function() {
                    if (typeof filterByResourceType === 'function') {
                        filterByResourceType(filterType);
                    }
                }, 100);
            }
        }
    } else {
        // If no filter param, set "All" as active and expand to show categories
        if (typeof filterByResourceType === 'function') {
            filterByResourceType('all');
        } else {
            setTimeout(function() {
                if (typeof filterByResourceType === 'function') {
                    filterByResourceType('all');
                }
            }, 100);
        }
    }
    
    // Initialize: Expand container and show categories on page load
    setTimeout(function() {
        const tierCardsContainer = document.querySelector('.tier-cards-container');
        if (tierCardsContainer) {
            tierCardsContainer.classList.add('expanded');
            if (typeof populateTierCardCategories === 'function') {
                populateTierCardCategories();
                // Render courses after categories are populated (in case categories are pre-selected)
                setTimeout(function() {
                    if (typeof renderSelectedCourses === 'function') {
                        renderSelectedCourses();
                    }
                }, 100);
            } else {
                console.error('populateTierCardCategories function not found');
            }
        }
    }, 300);
});

function filterResources() {
    const searchTerm = document.getElementById('resourceSearch')?.value.toLowerCase() || '';
    const allCards = document.querySelectorAll('.resource-card');
    
    // Get selected resource types from checkboxes
    const selectedResourceTypes = [];
    document.querySelectorAll('#resourceTypeFilters input[type="checkbox"]:checked').forEach(checkbox => {
        selectedResourceTypes.push(checkbox.getAttribute('data-filter-value'));
    });
    
    // Get selected categories and courses from checkboxes
    const selectedCategories = [];
    const selectedCourses = [];
    const selectedCategoryIds = [];
    
    document.querySelectorAll('#categoryFilters input[type="checkbox"]:checked').forEach(checkbox => {
        const filterType = checkbox.getAttribute('data-filter-type');
        if (filterType === 'category') {
            selectedCategories.push(decodeHtmlEntities(checkbox.getAttribute('data-filter-value')));
            const categoryId = checkbox.getAttribute('data-category-id');
            if (categoryId) {
                selectedCategoryIds.push(parseInt(categoryId));
            }
        } else if (filterType === 'course') {
            const courseId = checkbox.getAttribute('data-course-id');
            if (courseId) {
                selectedCourses.push(parseInt(courseId));
            }
        }
    });
    
    // Get selected sections from checkbox filters (priority) or select dropdown (fallback)
    const selectedSections = [];
    
    // First, check checkbox filters
    const sectionsCheckboxes = document.querySelectorAll('#sectionsFilters input[type="checkbox"]:checked');
    if (sectionsCheckboxes.length > 0) {
        sectionsCheckboxes.forEach(checkbox => {
            const sectionValue = checkbox.getAttribute('data-filter-value');
            if (sectionValue) {
                selectedSections.push(decodeHtmlEntities(sectionValue));
            }
        });
    } else {
        // Fallback to select dropdown if no checkboxes are checked
    const sectionSelect = document.getElementById('sectionsFilterSelect');
    if (sectionSelect && sectionSelect.value) {
        selectedSections.push(decodeHtmlEntities(sectionSelect.value));
        }
    }
    
    // Get selected folder from select dropdown
    let selectedFolder = null;
    const foldersFilterSelect = document.getElementById('foldersFilterSelect');
    if (foldersFilterSelect && foldersFilterSelect.value) {
        selectedFolder = decodeHtmlEntities(foldersFilterSelect.value);
    }
    
    // Show/hide clear search button
    const clearBtn = document.querySelector('.clear-search-btn');
    if (clearBtn) {
        clearBtn.style.display = searchTerm ? 'block' : 'none';
    }
    
    let visibleCount = 0;
    const visibleCards = [];
    
    // Filter cards - mark which cards match filters
    allCards.forEach(card => {
        const cardName = card.querySelector('.resource-card-title')?.textContent.toLowerCase() || '';
        const cardType = card.getAttribute('data-resource-type')?.toLowerCase() || '';
        const cardCategory = decodeHtmlEntities(card.getAttribute('data-category') || '').toLowerCase();
        
        let matchesSearch = true;
        let matchesResourceType = true;
        let matchesCategory = true;
        let matchesSection = true;
        let matchesFolder = true;
        let matchesResourceTypeTab = true;
        
        // Search filter
        if (searchTerm) {
            matchesSearch = cardName.includes(searchTerm) || 
                          cardType.includes(searchTerm) || 
                          cardCategory.includes(searchTerm);
        }
        
        // Resource type tab filter (Plan, Teach, Assess) - based on section names
        if (currentResourceTypeFilter !== 'all') {
            // Get section type from data attribute (extracted from section name)
            const cardFolderTag = (card.getAttribute('data-folder-tag') || '').toLowerCase().trim();
            
            // Only filter if the resource has a section type
            if (cardFolderTag) {
                // Match based on section type (Plan, Teach, or Assess)
                if (currentResourceTypeFilter === 'plan') {
                    matchesResourceTypeTab = cardFolderTag === 'plan';
                } else if (currentResourceTypeFilter === 'teach') {
                    matchesResourceTypeTab = cardFolderTag === 'teach';
                } else if (currentResourceTypeFilter === 'assess') {
                    matchesResourceTypeTab = cardFolderTag === 'assess';
                }
            } else {
                // If no section type is set, don't show the resource when filtering by Plan/Teach/Assess
                // (resources without section types are only shown in "All Resources")
                matchesResourceTypeTab = false;
            }
        }
        
        // Resource type filter (if any checkboxes are selected)
        if (selectedResourceTypes.length > 0) {
            // Check if "images" is selected (grouped image types)
            const imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'bmp', 'webp'];
            const isImageType = imageExtensions.includes(cardType);
            const isImagesSelected = selectedResourceTypes.includes('images');
            
            // If card is an image type and "images" is selected, it matches
            if (isImageType && isImagesSelected) {
                matchesResourceType = true;
            } else if (isImageType && !isImagesSelected) {
                // Card is an image but "images" is not selected
                matchesResourceType = false;
            } else {
                // For non-image types, check normal match
                matchesResourceType = selectedResourceTypes.includes(cardType);
            }
        }
        
        // Category and course filter (if any checkboxes are selected)
        // Priority: If ANY course is selected, ONLY match by course (ignore category selections)
        // If NO courses are selected, match by category
        if (selectedCategories.length > 0 || selectedCourses.length > 0) {
            matchesCategory = false;
            
            const cardCategoryId = parseInt(card.getAttribute('data-category-id')) || 0;
            const cardCourseId = parseInt(card.getAttribute('data-course-id')) || 0;
            
            // If ANY course is selected, ONLY match by course (ignore category selections)
            if (selectedCourses.length > 0) {
                matchesCategory = selectedCourses.includes(cardCourseId);
            } else {
                // Only check category match if NO courses are selected
                if (selectedCategoryIds.length > 0) {
                    // Check if the card's category matches any selected category
                    matchesCategory = selectedCategoryIds.includes(cardCategoryId);
                }
                
                // Also check by category name for backward compatibility
                if (!matchesCategory && selectedCategories.length > 0) {
                    const catLower = selectedCategories[0].toLowerCase();
                    const cardCatLower = cardCategory.toLowerCase();
                    matchesCategory = cardCatLower === catLower || cardCatLower.includes(catLower) || catLower.includes(cardCatLower);
                }
            }
        }
        
        // Section filter (if sections are selected from checkboxes or dropdown)
        if (selectedSections.length > 0) {
            const cardSection = decodeHtmlEntities(card.getAttribute('data-section') || '');
            
            // Match exact section or if card section starts with selected section (for subsections)
            matchesSection = selectedSections.some(selectedSection => {
                const cardSectionLower = cardSection.toLowerCase();
                const selectedSectionLower = selectedSection.toLowerCase();
                
                // Exact match
                if (cardSectionLower === selectedSectionLower) {
                    return true;
                }
                
                // If selected section is a main section (no " > "), match all its subsections
                if (selectedSection.indexOf(' > ') === -1) {
                    return cardSectionLower.startsWith(selectedSectionLower + ' > ');
                }
                
                // If selected section is a subsection, only match exact
                return false;
            });
        }
        
        // Folder filter (if a folder is selected from the select dropdown)
        if (selectedFolder) {
            matchesFolder = false;
            const cardFolder = decodeHtmlEntities(card.getAttribute('data-folder-name') || '');
            
            // The selected value is just the folder name (no prefix)
            matchesFolder = cardFolder.toLowerCase() === selectedFolder.toLowerCase() ||
                           cardFolder.toLowerCase().includes(selectedFolder.toLowerCase());
        }
        
        // Mark card as filtered (matches all criteria)
        const isVisible = matchesSearch && matchesResourceType && matchesResourceTypeTab && matchesCategory && matchesSection && matchesFolder;
        card.setAttribute('data-filtered', isVisible ? 'true' : 'false');
        
        if (isVisible) {
            visibleCards.push(card);
            visibleCount++;
        }
    });
    
    // Reset to page 1 when filters change
    currentPage = 1;
    
    // Apply pagination
    applyPagination();
    
    // Update count and pagination controls
    updateResourcesCount(visibleCount);
    updatePagination(visibleCount);
    
    // Update tier card counts based on visible/filtered resources
    if (typeof updateResourceTabCounts === 'function') {
        setTimeout(function() {
            updateResourceTabCounts();
        }, 50);
    }
}

// Update resources count display
function updateResourcesCount(count) {
    const countElement = document.getElementById('resourcesCount');
    if (countElement) {
        if (count === undefined) {
            // Count all filtered cards
            const allCards = document.querySelectorAll('.resource-card');
            count = Array.from(allCards).filter(card => 
                card.getAttribute('data-filtered') === 'true'
            ).length;
        }
        countElement.textContent = count + ' resource' + (count !== 1 ? 's' : '');
    }
    
    // Update total resources count in header
    const totalResourcesElement = document.getElementById('totalResourcesCount');
    if (totalResourcesElement) {
        if (count === undefined) {
            const allCards = document.querySelectorAll('.resource-card');
            count = Array.from(allCards).filter(card => 
                card.getAttribute('data-filtered') === 'true'
            ).length;
        }
        totalResourcesElement.textContent = count;
    }
}

// Apply pagination to show only items for current page
function applyPagination() {
    const allCards = document.querySelectorAll('.resource-card');
    const filteredCards = Array.from(allCards).filter(card => 
        card.getAttribute('data-filtered') === 'true'
    );
    
    const totalPages = Math.ceil(filteredCards.length / ITEMS_PER_PAGE);
    
    // Ensure currentPage is valid
    if (currentPage < 1) currentPage = 1;
    if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
    
    // Calculate start and end indices
    const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
    const endIndex = startIndex + ITEMS_PER_PAGE;
    
    // Hide all cards first
    allCards.forEach(card => {
        card.style.display = 'none';
    });
    
    // Show only cards for current page
    filteredCards.forEach((card, index) => {
        if (index >= startIndex && index < endIndex) {
            card.style.display = '';
            // Initialize preview for newly visible cards
            initializeCardPreview(card);
        }
    });
}

// Initialize preview for a single card
function initializeCardPreview(card) {
    if (!card) return;
    
    // Check if preview is already initialized or currently rendering
    if (card.hasAttribute('data-preview-initialized') || card.hasAttribute('data-preview-rendering')) {
        return;
    }
    
    // Handle PDF preview
    const pdfCanvas = card.querySelector('.resource-card-pdf-preview');
    if (pdfCanvas && typeof pdfjsLib !== 'undefined') {
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        const pdfUrl = pdfCanvas.getAttribute('data-pdf-url');
        
        if (pdfUrl && placeholder) {
            // Mark as rendering to prevent double initialization
            card.setAttribute('data-preview-rendering', 'true');
            
            // Store render task for potential cancellation
            let renderTask = null;
            
            pdfjsLib.getDocument({
                url: pdfUrl,
                disableAutoFetch: false,
                disableStream: false
            }).promise.then(function(pdf) {
                return pdf.getPage(1);
            }).then(function(page) {
                // Check if card is still in DOM and not removed
                if (!card.parentNode || !pdfCanvas.parentNode) {
                    return Promise.reject('Card removed from DOM');
                }
                
                // For preview thumbnails, always use rotation 0 to get consistent, correct orientation
                const rotation = 0;
                
                // Get viewport at natural orientation (rotation 0) for consistent previews
                const viewport = page.getViewport({ scale: 1.0, rotation: rotation });
                
                // Calculate scale to fit within card dimensions (320x180px max)
                const maxWidth = 320;
                const maxHeight = 180;
                const scaleX = maxWidth / viewport.width;
                const scaleY = maxHeight / viewport.height;
                const scale = Math.min(scaleX, scaleY, 1.0); // Don't scale up, only down
                
                // Get scaled viewport
                const scaledViewport = page.getViewport({ scale: scale, rotation: rotation });
                
                // Set canvas dimensions FIRST - this resets the canvas context
                // Calculate display dimensions
                const displayWidth = Math.floor(scaledViewport.width);
                const displayHeight = Math.floor(scaledViewport.height);
                
                // Set canvas internal size (for rendering quality)
                pdfCanvas.width = displayWidth;
                pdfCanvas.height = displayHeight;
                
                // Set CSS dimensions to match internal size
                // The flexbox container will center it automatically
                pdfCanvas.style.width = displayWidth + 'px';
                pdfCanvas.style.height = displayHeight + 'px';
                pdfCanvas.style.display = 'block';
                pdfCanvas.style.flexShrink = '0'; // Prevent flexbox from shrinking
                
                // Get context AFTER setting dimensions (important! - this resets the context)
                const context = pdfCanvas.getContext('2d', { alpha: false }); // No transparency for better performance
                
                // Clear canvas completely with white background
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, displayWidth, displayHeight);
                
                // Create render context with proper viewport
                // PDF.js will handle all transformations correctly
                const renderContext = {
                    canvasContext: context,
                    viewport: scaledViewport
                };
                
                // Store render task
                renderTask = page.render(renderContext);
                return renderTask.promise;
            }).then(function() {
                // Check if card is still in DOM
                if (!card.parentNode || !pdfCanvas.parentNode) {
                    return;
                }
                
                // Mark as initialized and remove rendering flag
                card.setAttribute('data-preview-initialized', 'true');
                card.removeAttribute('data-preview-rendering');
                
                // Show canvas, hide placeholder
                pdfCanvas.style.display = 'block';
                placeholder.style.display = 'none';
            }).catch(function(error) {
                // Remove rendering flag on error
                card.removeAttribute('data-preview-rendering');
                
                // Only log if it's not a cancellation
                if (error && error.toString().indexOf('removed') === -1) {
                    console.error('Error rendering PDF preview:', error);
                }
                
                // Keep placeholder visible on error
                if (placeholder) {
                    placeholder.style.display = 'flex';
                }
                if (pdfCanvas) {
                    pdfCanvas.style.display = 'none';
                }
            });
        }
    } else {
        // Mark as initialized even if no PDF preview (prevents re-checking)
        card.setAttribute('data-preview-initialized', 'true');
    }
    
    // Handle video thumbnail
    const video = card.querySelector('video.resource-card-image');
    if (video) {
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        const canvas = card.querySelector('.resource-card-video-thumbnail');
        
        if (canvas && placeholder && !video.hasAttribute('data-thumbnail-initialized')) {
            video.setAttribute('data-thumbnail-initialized', 'true');
            
            video.addEventListener('loadedmetadata', function() {
                const seekTime = Math.min(1, video.duration * 0.1);
                video.currentTime = seekTime;
            });
            
            video.addEventListener('seeked', function() {
                try {
                    const ctx = canvas.getContext('2d');
                    canvas.width = video.videoWidth || 320;
                    canvas.height = video.videoHeight || 180;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    canvas.style.display = 'block';
                    placeholder.style.display = 'none';
                    video.style.display = 'none';
                } catch (e) {
                    console.error('Error generating video thumbnail:', e);
                }
            });
            
            const src = video.getAttribute('data-src');
            if (src) {
                video.src = src;
            }
        }
    }
    
    // Handle iframe preview
    const iframe = card.querySelector('.resource-card-preview-iframe');
    if (iframe && !iframe.hasAttribute('data-iframe-initialized')) {
        iframe.setAttribute('data-iframe-initialized', 'true');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (placeholder) {
            iframe.addEventListener('load', function() {
                setTimeout(function() {
                    iframe.style.display = 'block';
                    placeholder.style.display = 'none';
                }, 500);
            });
        }
    }
    
    // Handle image preview
    const img = card.querySelector('.resource-card-image[src]');
    if (img && !img.hasAttribute('data-img-initialized')) {
        img.setAttribute('data-img-initialized', 'true');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (placeholder) {
            img.addEventListener('load', function() {
                img.style.display = 'block';
                placeholder.style.display = 'none';
            });
        }
    }
    
    // Handle PPT preview (first slide cover image)
    try {
        const pptImg = card.querySelector('.resource-card-ppt-preview');
        if (pptImg && !pptImg.hasAttribute('data-ppt-initialized')) {
            pptImg.setAttribute('data-ppt-initialized', 'true');
            const placeholder = card.querySelector('.resource-card-image-placeholder');
            
            if (!placeholder) {
                return; // No placeholder, skip
            }
            
            // Check if src is valid URL
            if (!pptImg.src || pptImg.src === '' || pptImg.src.indexOf('data:') === 0) {
                // No valid src, keep placeholder visible
                placeholder.style.display = 'flex';
                return;
            }
            
            // Set up load handler
            const loadHandler = function() {
                try {
                    checkPPTPreviewImage(pptImg);
                } catch (e) {
                    pptImg.style.display = 'none';
                    if (placeholder) {
                        placeholder.style.display = 'flex';
                    }
                }
            };
            
            // Set up error handler
            const errorHandler = function() {
                try {
                    const imgSrc = pptImg.src || 'unknown';
                    const fileName = card.getAttribute('data-file-name') || 'unknown';
                    const fileId = card.getAttribute('data-file-id') || 'unknown';
                    
                    // Hide broken image immediately
                    pptImg.style.display = 'none';
                    if (placeholder) {
                        placeholder.style.display = 'flex';
                    }
                    
                    // Log error to server (non-blocking)
                    if (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) {
                        fetch(M.cfg.wwwroot + '/theme/remui_kids/teacher/log_preview_error.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'type=ppt&src=' + encodeURIComponent(imgSrc) + 
                                  '&filename=' + encodeURIComponent(fileName) + 
                                  '&fileid=' + encodeURIComponent(fileId) + 
                                  '&sesskey=' + (M.cfg.sesskey || '')
                        }).catch(function(err) {
                            // Silently fail - don't break rendering
                        });
                    }
                } catch (e) {
                    console.error('Error in PPT preview error handler:', e);
                }
            };
            
            // Check if image is already loaded
            if (pptImg.complete && pptImg.naturalWidth !== 0 && pptImg.naturalHeight !== 0) {
                // Image already loaded, check it now
                loadHandler();
            } else {
                // Image not loaded yet, set up event listeners
                pptImg.addEventListener('load', loadHandler, { once: true });
                pptImg.addEventListener('error', errorHandler, { once: true });
                
                // Force load if not already loading
                if (!pptImg.complete) {
                    try {
                        pptImg.load();
                    } catch (e) {
                        // If load() fails, trigger error handler
                        errorHandler();
                    }
                }
            }
        }
    } catch (e) {
        console.error('Error initializing PPT preview:', e);
        // Don't break rendering - just log and continue
    }
    
    // Handle Office document preview (DOCX, XLSX, etc. - first page/sheet cover image)
    const officeImg = card.querySelector('.resource-card-office-preview');
    if (officeImg && !officeImg.hasAttribute('data-office-initialized')) {
        officeImg.setAttribute('data-office-initialized', 'true');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (placeholder) {
            // Check if image is already loaded
            if (officeImg.complete && officeImg.naturalWidth !== 0) {
                // Image already loaded, check it now
                checkPPTPreviewImage(officeImg); // Reuse the same validation function
            } else {
                // Image not loaded yet, wait for load event
                officeImg.addEventListener('load', function() {
                    checkPPTPreviewImage(officeImg); // Reuse the same validation function
                });
            }
            
            // Error handling: if image fails to load, keep placeholder visible
            officeImg.addEventListener('error', function() {
                officeImg.style.display = 'none';
                placeholder.style.display = 'flex';
            });
        }
    }
}

// Update pagination controls
function updatePagination(totalFiltered) {
    const paginationContainer = document.getElementById('resourcesPagination');
    const paginationPages = document.getElementById('paginationPages');
    const paginationInfo = document.getElementById('paginationInfo');
    const prevButton = document.getElementById('paginationPrev');
    const nextButton = document.getElementById('paginationNext');
    
    if (!paginationContainer || !paginationPages) return;
    
    const totalPages = Math.ceil(totalFiltered / ITEMS_PER_PAGE);
    
    // Show/hide pagination
    if (totalPages <= 1) {
        paginationContainer.style.display = 'none';
        return;
    }
    
    paginationContainer.style.display = 'flex';
    
    // Update prev/next buttons
    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
    }
    if (nextButton) {
        nextButton.disabled = currentPage >= totalPages;
    }
    
    // Update page numbers
    paginationPages.innerHTML = '';
    
    // Calculate which page numbers to show
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    // Adjust if we're near the start or end
    if (endPage - startPage < 4) {
        if (startPage === 1) {
            endPage = Math.min(totalPages, startPage + 4);
        } else if (endPage === totalPages) {
            startPage = Math.max(1, endPage - 4);
        }
    }
    
    // Add first page and ellipsis if needed
    if (startPage > 1) {
        const firstBtn = document.createElement('button');
        firstBtn.className = 'pagination-button';
        firstBtn.textContent = '1';
        firstBtn.onclick = () => changePage(1);
        paginationPages.appendChild(firstBtn);
        
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            paginationPages.appendChild(ellipsis);
        }
    }
    
    // Add page number buttons
    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('button');
        pageBtn.className = 'pagination-button' + (i === currentPage ? ' active' : '');
        pageBtn.textContent = i;
        pageBtn.onclick = () => changePage(i);
        paginationPages.appendChild(pageBtn);
    }
    
    // Add last page and ellipsis if needed
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '...';
            paginationPages.appendChild(ellipsis);
        }
        
        const lastBtn = document.createElement('button');
        lastBtn.className = 'pagination-button';
        lastBtn.textContent = totalPages;
        lastBtn.onclick = () => changePage(totalPages);
        paginationPages.appendChild(lastBtn);
    }
    
    // Update pagination info
    if (paginationInfo) {
        const startItem = totalFiltered === 0 ? 0 : (currentPage - 1) * ITEMS_PER_PAGE + 1;
        const endItem = Math.min(currentPage * ITEMS_PER_PAGE, totalFiltered);
        paginationInfo.textContent = `Showing ${startItem}-${endItem} of ${totalFiltered}`;
    }
}

// Change page
function changePage(page) {
    const allCards = document.querySelectorAll('.resource-card');
    const filteredCards = Array.from(allCards).filter(card => 
        card.getAttribute('data-filtered') === 'true'
    );
    const totalPages = Math.ceil(filteredCards.length / ITEMS_PER_PAGE);
    
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    applyPagination();
    
    const totalFiltered = filteredCards.length;
    updatePagination(totalFiltered);
    
    // Scroll to top of resources grid
    const resourcesGrid = document.getElementById('resourcesGrid');
    if (resourcesGrid) {
        resourcesGrid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Clear search input
function clearSearch() {
    document.getElementById('resourceSearch').value = '';
    filterResources();
}

// Reset all filters
function resetAllFilters() {
    document.getElementById('resourceSearch').value = '';
    
    // Uncheck all filter checkboxes (but NOT tier card selections - those are navigation, not filters)
    document.querySelectorAll('#resourceTypeFilters input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    document.querySelectorAll('#categoryFilters input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    document.querySelectorAll('#sectionsFilters input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Reset sections select dropdown
    const resetSectionSelect = document.getElementById('sectionsFilterSelect');
    if (resetSectionSelect) {
        resetSectionSelect.value = '';
    }
    
    // Reset folders and files select dropdown
    const foldersFilterSelect = document.getElementById('foldersFilterSelect');
    if (foldersFilterSelect) {
        foldersFilterSelect.value = '';
    }
    
    // Update sections and folders filters
    // This will check tier card selections, so sections filter will remain visible if tier cards have courses selected
    // Use a small timeout to ensure tier card state is properly detected
    setTimeout(function() {
        if (typeof updateSectionsAndFoldersFilters === 'function') {
            updateSectionsAndFoldersFilters();
        }
    }, 50);
    
    // Reset to page 1
    currentPage = 1;
    filterResources();
}

// Show folder description in modal
function showFolderDescription(folderName, folderId) {
    const modal = document.getElementById('folderDescriptionModal');
    const title = document.getElementById('folderDescriptionTitle');
    const body = document.getElementById('folderDescriptionBody');
    
    // Get description from hidden div
    const descriptionDiv = document.getElementById('folder-desc-' + folderId);
    
    if (descriptionDiv) {
        title.textContent = folderName;
        body.innerHTML = descriptionDiv.innerHTML;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

// Close folder description modal
function closeFolderDescription() {
    const modal = document.getElementById('folderDescriptionModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFolderDescription();
    }
});

// Close modal on background click
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('folderDescriptionModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeFolderDescription();
            }
        });
    }
});

function makeAbsoluteUrl(url) {
    try {
        return new URL(url, window.location.origin).toString();
    } catch (error) {
        return url;
    }
}

function escapeHtml(value) {
    return (value || '').replace(/[&<>"']/g, function(match) {
        switch (match) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case '\'': return '&#39;';
            default: return match;
        }
    });
}

function previewTeacherFile(cardElement) {
    if (!cardElement) {
        return;
    }
    const url = cardElement.dataset.fileUrl || '';
    if (!url) {
        const fallback = cardElement.dataset.fallbackUrl;
        if (fallback) {
            window.open(fallback, '_blank');
        }
        return;
    }
    const ext = (cardElement.dataset.fileExt || '').toLowerCase();
    const name = cardElement.dataset.fileName || 'Resource';
    const previewUrl = cardElement.dataset.previewUrl || ''; // Preview URL for PPT files
    const allowDownload = cardElement.dataset.allowDownload !== 'false';
    const previewType = cardElement.dataset.previewType || '';
    openPPTPlayer(url, name, ext, { allowDownload, previewType, previewUrl: previewUrl });
}

// Open resource (inline preview when possible, otherwise fallback)
function openResource(cardElement, cmid, name, modname) {
    const dataset = (cardElement && cardElement.dataset) ? cardElement.dataset : {};
    const dataUrl = dataset.fileUrl || '';
    const dataExt = (dataset.fileExt || '').toLowerCase();
    const previewUrl = dataset.previewUrl || ''; // Preview URL for PPT files
    const allowDownloadAttr = dataset.allowDownload;
    const previewType = dataset.previewType || '';
    const allowDownload = allowDownloadAttr !== 'false' && modname !== 'url';

    if (dataUrl) {
        openPPTPlayer(dataUrl, name, dataExt, { allowDownload, previewType, previewUrl: previewUrl });
        return;
    }

    const url = '<?php echo $CFG->wwwroot; ?>/mod/' + modname + '/view.php?id=' + cmid;

    if (modname === 'resource' || modname === 'folder') {
        openPPTPlayer(url, name, dataExt, { allowDownload: true, previewType: '', previewUrl: previewUrl });
    } else if (modname === 'url') {
        window.open(url, '_blank');
    } else {
        window.open(url, '_blank');
    }
}

// Open PPT player modal with support for Office viewers
const spreadsheetExtensions = ['xls', 'xlsx', 'xlsm', 'csv'];
const officeViewerExtensions = ['doc', 'docx'];
const pptExtensions = ['ppt', 'pptx', 'pps', 'ppsx']; // PPT files - show preview instead of direct download
const directDownloadExtensions = []; // Removed PPT extensions - they now show preview
let teacherResourceOriginalUrl = '';

function openPPTPlayer(url, title, ext, options = {}) {
    const modal = document.getElementById('pptPlayerModal');
    const iframe = document.getElementById('pptPlayerIframe');
    const spreadsheetContainer = document.getElementById('pptSpreadsheetContainer');
    const titleElement = document.getElementById('pptPlayerTitle');
    const downloadButton = document.getElementById('pptDownloadButton');
    const fullscreenButton = document.getElementById('pptFullscreenButton');
    const extension = (ext || '').toLowerCase();
    const isSpreadsheet = spreadsheetExtensions.includes(extension);
    const isPPT = pptExtensions.includes(extension);
    const isDOCX = extension === 'docx'; // DOCX files - show first image preview
    const allowDownload = options.allowDownload !== false && extension !== 'link' && options.previewType !== 'url';
    const isExternalLink = options.previewType === 'url' || extension === 'link';
    const previewUrl = options.previewUrl || ''; // Preview URL for PPT and DOCX files
    let viewerUrl = url;

    teacherResourceOriginalUrl = url; // Store original URL for download

    if (downloadButton) {
        if (allowDownload && url) {
            downloadButton.style.display = 'inline-flex';
            downloadButton.disabled = false;
        } else {
            downloadButton.style.display = 'none';
            downloadButton.disabled = true;
        }
    }

    if (fullscreenButton) {
        if (isExternalLink) {
            fullscreenButton.style.display = 'flex';
            fullscreenButton.disabled = false;
        } else {
            fullscreenButton.style.display = 'none';
            fullscreenButton.disabled = true;
            fullscreenButton.classList.remove('fullscreen-active');
            fullscreenButton.setAttribute('aria-pressed', 'false');
        }
    }

    // Handle PPT files - show first slide preview
    if (isPPT) {
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
        }
        if (spreadsheetContainer) {
            spreadsheetContainer.hidden = false;
            if (previewUrl) {
                // Show preview image with proper styling (20% bigger)
                spreadsheetContainer.innerHTML = 
                    '<div style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; min-height: 500px; display: flex; flex-direction: column; align-items: center; justify-content: center;">' +
                    '<img src="' + escapeHtml(previewUrl) + '" alt="' + escapeHtml(title) + ' - First Slide Preview" ' +
                    'style="max-width: 100%; max-height: 84vh; width: auto; height: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); background: white; padding: 8px; object-fit: contain;" ' +
                    'onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" />' +
                    '<div style="display: none; color: #666; padding: 40px;"><p style="font-size: 16px; margin-bottom: 12px;">Preview image failed to load</p><p style="font-size: 14px;">Click Download to view the full presentation</p></div>' +
                    '</div>' +
                    '<p style="text-align: center; color: #666; margin-top: 16px; font-size: 14px; padding: 0 20px;">First Slide Preview - Click Download button above to view the full presentation</p>';
            } else {
                // No preview available
                spreadsheetContainer.innerHTML = 
                    '<div style="text-align: center; padding: 60px 40px; color: #666; background: #f8f9fa; border-radius: 8px; min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: center;">' +
                    '<i class="fa fa-file-powerpoint" style="font-size: 64px; color: #fd7e14; margin-bottom: 20px;"></i>' +
                    '<p style="font-size: 18px; margin-bottom: 12px; font-weight: 600;">Preview not available</p>' +
                    '<p style="font-size: 14px; color: #999;">Click Download button above to view the full presentation</p>' +
                    '</div>';
            }
        }
    } else if (isDOCX) {
        // Handle DOCX files - show first image preview
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
        }
        if (spreadsheetContainer) {
            spreadsheetContainer.hidden = false;
            if (previewUrl) {
                // Show preview image with proper styling (20% bigger)
                spreadsheetContainer.innerHTML = 
                    '<div style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; min-height: 500px; display: flex; flex-direction: column; align-items: center; justify-content: center;">' +
                    '<img src="' + escapeHtml(previewUrl) + '" alt="' + escapeHtml(title) + ' - First Page Preview" ' +
                    'style="max-width: 100%; max-height: 84vh; width: auto; height: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); background: white; padding: 8px; object-fit: contain;" ' +
                    'onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" />' +
                    '<div style="display: none; color: #666; padding: 40px;"><p style="font-size: 16px; margin-bottom: 12px;">Preview image failed to load</p><p style="font-size: 14px;">Click Download to view the full document</p></div>' +
                    '</div>' +
                    '<p style="text-align: center; color: #666; margin-top: 16px; font-size: 14px; padding: 0 20px;">First Page Preview - Click Download button above to view the full document</p>';
            } else {
                // No preview available
                spreadsheetContainer.innerHTML = 
                    '<div style="text-align: center; padding: 60px 40px; color: #666; background: #f8f9fa; border-radius: 8px; min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: center;">' +
                    '<i class="fa fa-file-word" style="font-size: 64px; color: #007bff; margin-bottom: 20px;"></i>' +
                    '<p style="font-size: 18px; margin-bottom: 12px; font-weight: 600;">Preview not available</p>' +
                    '<p style="font-size: 14px; color: #999;">Click Download button above to view the full document</p>' +
                    '</div>';
            }
        }
    } else if (isSpreadsheet) {
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
        }
        if (spreadsheetContainer) {
            spreadsheetContainer.hidden = false;
            spreadsheetContainer.innerHTML = '<p class="spreadsheet-loading">Loading spreadsheet preview…</p>';
            loadSpreadsheetPreview(url, spreadsheetContainer);
        }
    } else {
        // Other file types - use iframe
        if (iframe) {
            iframe.style.display = '';
        }
        if (spreadsheetContainer) {
            spreadsheetContainer.hidden = true;
            spreadsheetContainer.innerHTML = '';
        }
        if (isExternalLink) {
            viewerUrl = url;
        } else if (officeViewerExtensions.includes(extension) && OFFICE_VIEWER_ENABLED) {
            const absoluteUrl = makeAbsoluteUrl(url);
            viewerUrl = 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(absoluteUrl);
        }
        
        if (iframe) {
            iframe.src = viewerUrl;
        }
    }
    
    titleElement.textContent = title;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    updatePPTFullscreenButton();
}

async function loadSpreadsheetPreview(url, container) {
    try {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const arrayBuffer = await response.arrayBuffer();
        const workbook = XLSX.read(arrayBuffer, { type: 'array' });

        let html = '';
        workbook.SheetNames.forEach((sheetName, index) => {
            const worksheet = workbook.Sheets[sheetName];
            const sheetHtml = XLSX.utils.sheet_to_html(worksheet, {
                header: '<h3>' + escapeHtml(sheetName) + '</h3>',
            });
            html += '<div class="sheet-block">' + sheetHtml + '</div>';
        });

        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="spreadsheet-error">Unable to preview this spreadsheet. <a href="' +
            teacherResourceOriginalUrl + '" target="_blank" rel="noopener">Open the file in a new tab</a>.</p>';
        console.error('Spreadsheet preview failed:', error);
    }
}

function downloadCurrentResource() {
    if (teacherResourceOriginalUrl) {
        downloadResourceFile(teacherResourceOriginalUrl);
    }
}

function downloadResourceFile(url) {
    if (!url) {
        return;
    }
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.target = '_blank';
    anchor.rel = 'noopener';
    anchor.download = '';
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
}

// Close PPT player
function closePPTPlayer() {
    const modal = document.getElementById('pptPlayerModal');
    const iframe = document.getElementById('pptPlayerIframe');
    const spreadsheetContainer = document.getElementById('pptSpreadsheetContainer');
    const fullscreenElement = getCurrentFullscreenElement();
    if (fullscreenElement && fullscreenElement.id === PPT_FULLSCREEN_ELEMENT_ID) {
        exitFullscreen();
    }
    
    modal.classList.remove('active');
    if (iframe) {
        iframe.src = '';
        iframe.style.display = '';
    }
    if (spreadsheetContainer) {
        spreadsheetContainer.hidden = true;
        spreadsheetContainer.innerHTML = '';
    }
    document.body.style.overflow = '';
    updatePPTFullscreenButton();
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const fullscreenElement = getCurrentFullscreenElement();
        if (fullscreenElement && fullscreenElement.id === PPT_FULLSCREEN_ELEMENT_ID) {
            exitFullscreen();
            return;
        }
        closePPTPlayer();
    }
});

// Close modal on background click
document.getElementById('pptPlayerModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePPTPlayer();
    }
});

function getCurrentFullscreenElement() {
    return document.fullscreenElement ||
        document.webkitFullscreenElement ||
        document.mozFullScreenElement ||
        document.msFullscreenElement ||
        null;
}

function requestFullscreen(element) {
    if (!element) {
        return;
    }
    if (element.requestFullscreen) {
        element.requestFullscreen();
    } else if (element.webkitRequestFullscreen) {
        element.webkitRequestFullscreen();
    } else if (element.mozRequestFullScreen) {
        element.mozRequestFullScreen();
    } else if (element.msRequestFullscreen) {
        element.msRequestFullscreen();
    }
}

function exitFullscreen() {
    if (document.exitFullscreen) {
        document.exitFullscreen();
    } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
    } else if (document.mozCancelFullScreen) {
        document.mozCancelFullScreen();
    } else if (document.msExitFullscreen) {
        document.msExitFullscreen();
    }
}

function togglePPTFullscreen() {
    const modalContent = document.getElementById(PPT_FULLSCREEN_ELEMENT_ID);
    if (!modalContent) {
        return;
    }
    const fullscreenElement = getCurrentFullscreenElement();
    if (!fullscreenElement) {
        requestFullscreen(modalContent);
    } else if (fullscreenElement.id === PPT_FULLSCREEN_ELEMENT_ID) {
        exitFullscreen();
    } else {
        requestFullscreen(modalContent);
    }
}

function updatePPTFullscreenButton() {
    const fullscreenButton = document.getElementById('pptFullscreenButton');
    if (!fullscreenButton || fullscreenButton.style.display === 'none') {
        return;
    }
    const fullscreenElement = getCurrentFullscreenElement();
    const isActive = fullscreenElement && fullscreenElement.id === PPT_FULLSCREEN_ELEMENT_ID;
    fullscreenButton.classList.toggle('fullscreen-active', !!isActive);
    fullscreenButton.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    const icon = fullscreenButton.querySelector('i');
    if (icon) {
        icon.classList.toggle('fa-expand', !isActive);
        icon.classList.toggle('fa-compress', !!isActive);
    }
}

['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(evt => {
    document.addEventListener(evt, updatePPTFullscreenButton);
});

// ===== TEACHER SUPPORT/HELP BUTTON FUNCTIONALITY =====
<?php if ($has_help_videos): ?>
document.addEventListener('DOMContentLoaded', function() {
    const helpButton = document.getElementById('teacherHelpButton');
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const closeModal = document.getElementById('closeTeacherHelpModal');
    
    // Open modal
    if (helpButton) {
        helpButton.addEventListener('click', function() {
            if (helpModal) {
                helpModal.classList.add('active');
                document.body.style.overflow = 'hidden';
                loadTeacherHelpVideos();
            }
        });
    }
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            closeTeacherHelpModal();
        });
    }
    
    // Close on outside click
    if (helpModal) {
        helpModal.addEventListener('click', function(e) {
            if (e.target === helpModal) {
                closeTeacherHelpModal();
            }
        });
    }
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && helpModal && helpModal.classList.contains('active')) {
            closeTeacherHelpModal();
        }
    });
    
    // Back to list button
    const backToListBtn = document.getElementById('teacherBackToListBtn');
    if (backToListBtn) {
        backToListBtn.addEventListener('click', function() {
            const videosListContainer = document.querySelector('.teacher-help-videos-list');
            const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
            const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
            
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.currentTime = 0;
                videoPlayer.src = '';
            }
            
            if (videoPlayerContainer) {
                videoPlayerContainer.style.display = 'none';
            }
            
            if (videosListContainer) {
                videosListContainer.style.display = 'block';
            }
        });
    }
});

function closeTeacherHelpModal() {
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (helpModal) {
        helpModal.classList.remove('active');
    }
    
    if (videoPlayer) {
        videoPlayer.pause();
        videoPlayer.currentTime = 0;
        videoPlayer.src = '';
    }
    
    document.body.style.overflow = 'auto';
}

// Load help videos function
function loadTeacherHelpVideos() {
    const videosListContainer = document.querySelector('.teacher-help-videos-list');
    const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
    
    if (!videosListContainer) return;
    
    // Show loading
    videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;"><i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>Loading help videos...</p>';
    
    // Fetch videos from plugin endpoint for 'teachers' category
    fetch(M.cfg.wwwroot + '/local/support/get_videos.php?category=teachers')
        .then(response => response.json())
        .then(data => {
            console.log('Teacher Support Videos Response:', data);
            
            if (data.success && data.videos && data.videos.length > 0) {
                let html = '';
                data.videos.forEach(function(video) {
                    html += '<div class="teacher-help-video-item" ';
                    html += 'data-video-id="' + video.id + '" ';
                    html += 'data-video-url="' + escapeHtml(video.video_url) + '" ';
                    html += 'data-embed-url="' + escapeHtml(video.embed_url) + '" ';
                    html += 'data-video-type="' + video.videotype + '" ';
                    html += 'data-has-captions="' + video.has_captions + '" ';
                    html += 'data-caption-url="' + escapeHtml(video.caption_url) + '">';
                    html += '  <h4><i class="fa fa-play-circle"></i> ' + escapeHtml(video.title) + '</h4>';
                    if (video.description) {
                        html += '  <p>' + escapeHtml(video.description) + '</p>';
                    }
                    if (video.duration) {
                        html += '  <small style="color: #999;"><i class="fa fa-clock-o"></i> ' + escapeHtml(video.duration) + ' &middot; <i class="fa fa-eye"></i> ' + video.views + ' views</small>';
                    }
                    html += '</div>';
                });
                videosListContainer.innerHTML = html;
                
                // Add click handlers to video items
                document.querySelectorAll('.teacher-help-video-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        const videoId = this.getAttribute('data-video-id');
                        const videoUrl = this.getAttribute('data-video-url');
                        const embedUrl = this.getAttribute('data-embed-url');
                        const videoType = this.getAttribute('data-video-type');
                        const hasCaptions = this.getAttribute('data-has-captions') === 'true';
                        const captionUrl = this.getAttribute('data-caption-url');
                        
                        playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl);
                    });
                });
            } else {
                videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">No help videos available for teachers.</p>';
            }
        })
        .catch(error => {
            videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #d9534f;">Error loading videos. Please try again.</p>';
        });
}

function playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl) {
    const videosListContainer = document.querySelector('.teacher-help-videos-list');
    const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (!videoPlayerContainer || !videoPlayer) return;
    
    // Clear previous video
    videoPlayer.innerHTML = '';
    videoPlayer.src = '';
    
    if (videoType === 'youtube' || videoType === 'vimeo' || videoType === 'external') {
        // For external videos, we need to use iframe instead
        videoPlayer.style.display = 'none';
        const iframe = document.createElement('iframe');
        iframe.src = embedUrl || videoUrl;
        iframe.width = '100%';
        iframe.style.height = '450px';
        iframe.style.borderRadius = '8px';
        iframe.frameBorder = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        videoPlayer.parentNode.insertBefore(iframe, videoPlayer);
        iframe.id = 'teacherTempIframe';
    } else {
        // Remove any existing iframe
        const existingIframe = document.getElementById('teacherTempIframe');
        if (existingIframe) {
            existingIframe.remove();
        }
        
        videoPlayer.style.display = 'block';
        videoPlayer.src = videoUrl;
        
        // Add captions if available
        if (hasCaptions && captionUrl) {
            const track = document.createElement('track');
            track.kind = 'captions';
            track.src = captionUrl;
            track.srclang = 'en';
            track.label = 'English';
            track.default = true;
            videoPlayer.appendChild(track);
        }
        
        videoPlayer.load();
    }
    
    // Show player, hide list
    videosListContainer.style.display = 'none';
    videoPlayerContainer.style.display = 'block';
    
    // Record view
    fetch(M.cfg.wwwroot + '/local/support/record_view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'videoid=' + videoId + '&sesskey=' + M.cfg.sesskey
    });
}
<?php endif; ?>

// Initialize preview images and pagination on page load
document.addEventListener('DOMContentLoaded', function() {
    // Wait a tiny bit to ensure all cards are in the DOM
    setTimeout(function() {
        const allCards = document.querySelectorAll('.resource-card');
        if (allCards.length > 0) {
            let totalFiltered = 0;
            allCards.forEach(card => {
                const filteredAttr = card.getAttribute('data-filtered');
                if (filteredAttr === null) {
                    card.setAttribute('data-filtered', 'true');
                    totalFiltered++;
                } else if (filteredAttr === 'true') {
                    totalFiltered++;
                }
            });
            if (totalFiltered === 0) {
                totalFiltered = allCards.length;
            }
            updateResourcesCount(totalFiltered);
            applyPagination();
            updatePagination(totalFiltered);
            
            // Update tier card counts
            if (typeof updateResourceTabCounts === 'function') {
                updateResourceTabCounts();
            }
            
            // Initialize preview images
            initializeResourcePreviews();
        }
    }, 100);
});

// Initialize resource preview images
function initializeResourcePreviews() {
    // Set PDF.js worker path
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
    
    // Handle PPT preview images (first slide cover images)
    document.querySelectorAll('.resource-card-ppt-preview').forEach(img => {
        try {
            const card = img.closest('.resource-card');
            const placeholder = card ? card.querySelector('.resource-card-image-placeholder') : null;
            
            if (!img || !placeholder) return;
            
            // Skip if already initialized
            if (img.hasAttribute('data-ppt-initialized')) return;
            img.setAttribute('data-ppt-initialized', 'true');
            
            // Check if src is valid URL
            if (!img.src || img.src === '' || img.src.indexOf('data:') === 0) {
                // No valid src, keep placeholder visible
                placeholder.style.display = 'flex';
                return;
            }
            
            // Set up load handler
            const loadHandler = function() {
                try {
                    checkPPTPreviewImage(img);
                } catch (e) {
                    img.style.display = 'none';
                    if (placeholder) {
                        placeholder.style.display = 'flex';
                    }
                }
            };
            
            // Set up error handler
            const errorHandler = function() {
                try {
                    const imgSrc = img.src || 'unknown';
                    const fileName = card ? (card.getAttribute('data-file-name') || 'unknown') : 'unknown';
                    const fileId = card ? (card.getAttribute('data-file-id') || 'unknown') : 'unknown';
                    
                    // Hide broken image immediately
                    img.style.display = 'none';
                    if (placeholder) {
                        placeholder.style.display = 'flex';
                    }
                    
                    // Log error to server (non-blocking)
                    if (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) {
                        fetch(M.cfg.wwwroot + '/theme/remui_kids/teacher/log_preview_error.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'type=ppt&src=' + encodeURIComponent(imgSrc) + 
                                  '&filename=' + encodeURIComponent(fileName) + 
                                  '&fileid=' + encodeURIComponent(fileId) + 
                                  '&sesskey=' + (M.cfg.sesskey || '')
                        }).catch(function(err) {
                            // Silently fail - don't break rendering
                        });
                    }
                } catch (e) {
                    console.error('Error in PPT preview error handler:', e);
                }
            };
            
            // Check if image is already loaded
            if (img.complete && img.naturalWidth !== 0 && img.naturalHeight !== 0) {
                // Image already loaded, check it now
                loadHandler();
            } else {
                // Image not loaded yet, set up event listeners
                img.addEventListener('load', loadHandler, { once: true });
                img.addEventListener('error', errorHandler, { once: true });
                
                // Force load if not already loading
                if (!img.complete) {
                    try {
                        img.load();
                    } catch (e) {
                        // If load() fails, trigger error handler
                        errorHandler();
                    }
                }
            }
        } catch (e) {
            console.error('Error processing PPT preview image:', e);
            // Continue with next image - don't break the loop
        }
    });
    
    // Handle Office document preview images (DOCX, XLSX, etc. - first page/sheet cover images)
    document.querySelectorAll('.resource-card-office-preview').forEach(img => {
        const card = img.closest('.resource-card');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (!img || !placeholder) return;
        
        // Check if image is already loaded
        if (img.complete && img.naturalWidth !== 0) {
            // Image already loaded, check it now
            checkPPTPreviewImage(img); // Reuse the same validation function
        } else {
            // Image not loaded yet, wait for load event
            img.addEventListener('load', function() {
                checkPPTPreviewImage(img); // Reuse the same validation function
            });
        }
        
        // Handle errors
        img.addEventListener('error', function() {
            img.style.display = 'none';
            placeholder.style.display = 'flex';
        });
    });
    
    // Handle PDF previews - only for visible cards that aren't already initialized
    document.querySelectorAll('.resource-card-pdf-preview').forEach(canvas => {
        const card = canvas.closest('.resource-card');
        if (!card) return;
        
        // Skip if already initialized or currently rendering
        if (card.hasAttribute('data-preview-initialized') || card.hasAttribute('data-preview-rendering')) {
            return;
        }
        
        // Skip if card is hidden (will be initialized when it becomes visible via pagination)
        if (card.style.display === 'none' || card.offsetParent === null) {
            return;
        }
        
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        const pdfUrl = canvas.getAttribute('data-pdf-url');
        
        if (!canvas || !placeholder || !pdfUrl || typeof pdfjsLib === 'undefined') {
            // Mark as initialized even if invalid (prevents re-checking)
            card.setAttribute('data-preview-initialized', 'true');
            return;
        }
        
        // Use the same initialization function to ensure consistency
        initializeCardPreview(card);
    });
    
    // Handle video thumbnails
    document.querySelectorAll('.resource-card video.resource-card-image').forEach(video => {
        const card = video.closest('.resource-card');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        const canvas = card.querySelector('.resource-card-video-thumbnail');
        
        if (!video || !canvas || !placeholder) return;
        
        video.addEventListener('loadedmetadata', function() {
            // Seek to 1 second or 10% of video duration
            const seekTime = Math.min(1, video.duration * 0.1);
            video.currentTime = seekTime;
        });
        
        video.addEventListener('seeked', function() {
            try {
                const ctx = canvas.getContext('2d');
                canvas.width = video.videoWidth || 320;
                canvas.height = video.videoHeight || 180;
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                // Show canvas, hide placeholder
                canvas.style.display = 'block';
                placeholder.style.display = 'none';
                video.style.display = 'none';
            } catch (e) {
                console.error('Error generating video thumbnail:', e);
            }
        });
        
        video.addEventListener('error', function() {
            // If video fails to load, keep placeholder visible
            placeholder.style.display = 'flex';
            canvas.style.display = 'none';
        });
        
        // Load video metadata
        const src = video.getAttribute('data-src');
        if (src) {
            video.src = src;
        }
    });
    
    // Handle iframe previews (Office docs)
    document.querySelectorAll('.resource-card-preview-iframe').forEach(iframe => {
        const card = iframe.closest('.resource-card');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (!iframe || !placeholder) return;
        
        iframe.addEventListener('load', function() {
            // Show iframe, hide placeholder after a short delay to ensure it's loaded
            setTimeout(function() {
                iframe.style.display = 'block';
                placeholder.style.display = 'none';
            }, 500);
        });
        
        iframe.addEventListener('error', function() {
            // If iframe fails to load, keep placeholder visible
            placeholder.style.display = 'flex';
            iframe.style.display = 'none';
        });
    });
    
    // Handle image previews
    document.querySelectorAll('.resource-card-image[src]').forEach(img => {
        const card = img.closest('.resource-card');
        const placeholder = card.querySelector('.resource-card-image-placeholder');
        
        if (!img || !placeholder) return;
        
        img.addEventListener('load', function() {
            // Show image, hide placeholder
            img.style.display = 'block';
            placeholder.style.display = 'none';
        });
        
        // If image fails to load, onerror handler in HTML will handle it
    });
}

// Initialize profile dropdown on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize user menu dropdown
    function initUserMenuDropdown() {
        // Find all user menu dropdown toggles
        const userMenuToggles = document.querySelectorAll('#user-menu-toggle, .dropdown-toggle[data-toggle="dropdown"], [data-region="usermenu"] .dropdown-toggle, .usermenu .dropdown-toggle, .navbar .usermenu .dropdown-toggle');
        
        userMenuToggles.forEach(toggle => {
            // Remove any existing event listeners by cloning
            const newToggle = toggle.cloneNode(true);
            toggle.parentNode.replaceChild(newToggle, toggle);
            
            const dropdown = newToggle.closest('.dropdown');
            if (!dropdown) return;
            
            const menu = dropdown.querySelector('.dropdown-menu, #user-action-menu');
            if (!menu) return;
            
            // Add click event to toggle dropdown
            newToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close all other dropdowns
                document.querySelectorAll('.dropdown-menu.show, #user-action-menu.show').forEach(openMenu => {
                    if (openMenu !== menu) {
                        openMenu.classList.remove('show');
                        openMenu.style.display = 'none';
                        const parentDropdown = openMenu.closest('.dropdown');
                        if (parentDropdown) {
                            const otherToggle = parentDropdown.querySelector('.dropdown-toggle, #user-menu-toggle');
                            if (otherToggle) {
                                otherToggle.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });
                
                // Toggle current dropdown
                const isOpen = menu.classList.contains('show');
                
                if (isOpen) {
                    menu.classList.remove('show');
                    menu.style.display = 'none';
                    newToggle.setAttribute('aria-expanded', 'false');
                } else {
                    menu.classList.add('show');
                    menu.style.display = 'block';
                    newToggle.setAttribute('aria-expanded', 'true');
                    
                    // Position dropdown properly
                    menu.style.position = 'absolute';
                    menu.style.zIndex = '1060';
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    menu.classList.remove('show');
                    menu.style.display = 'none';
                    newToggle.setAttribute('aria-expanded', 'false');
                }
            });
        });
        
        // Also try Bootstrap dropdown initialization if available
        if (typeof jQuery !== 'undefined' && jQuery.fn.dropdown) {
            jQuery('#user-menu-toggle, [data-toggle="dropdown"]').dropdown();
        } else if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const dropdownElementList = [].slice.call(document.querySelectorAll('#user-menu-toggle, [data-toggle="dropdown"]'));
            dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
        }
    }
    
    // Initialize immediately
    initUserMenuDropdown();
    
    // Also initialize after a short delay to catch dynamically loaded elements
    setTimeout(initUserMenuDropdown, 500);
});
</script>

<!-- Teacher Help/Support Video Modal -->
<?php if ($has_help_videos): ?>
<div id="teacherHelpVideoModal" class="teacher-help-modal">
    <div class="teacher-help-modal-content">
        <div class="teacher-help-modal-header">
            <h2><i class="fa fa-video"></i> Teacher Help Videos</h2>
            <button class="teacher-help-modal-close" id="closeTeacherHelpModal">&times;</button>
        </div>
        
        <div class="teacher-help-modal-body">
            <div class="teacher-help-videos-list">
                <p style="text-align: center; padding: 20px; color: #666;">
                    <i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>
                    Loading help videos...
                </p>
            </div>
            
            <div class="teacher-help-video-player" style="display: none;">
                <button class="teacher-back-to-list-btn" id="teacherBackToListBtn">
                    <i class="fa fa-arrow-left"></i> Back to List
                </button>
                <video id="teacherHelpVideoPlayer" controls style="width: 100%; border-radius: 8px;">
                    <source src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>