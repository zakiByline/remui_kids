<?php
/**
 * Reusable Admin Sidebar Component
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Make sure this is being included properly
defined('MOODLE_INTERNAL') || die();

global $CFG, $PAGE;

// Get current page to highlight active menu item
$current_url = '';
$current_script = '';

if (isset($PAGE) && $PAGE->url) {
    $current_url = $PAGE->url->out_omit_querystring();
}
if (isset($_SERVER['SCRIPT_NAME'])) {
    $current_script = basename($_SERVER['SCRIPT_NAME']);
}

// Helper function to check if page is active
function is_active_page($patterns) {
    global $current_url, $current_script;
    foreach ((array)$patterns as $pattern) {
        // Check if both variables are strings before using strpos
        if ((is_string($current_url) && strpos($current_url, $pattern) !== false) || 
            (is_string($current_script) && strpos($current_script, $pattern) !== false)) {
            return 'active';
        }
    }
    return '';
}

// Admin Sidebar Navigation
echo "<div class='admin-sidebar'>";
echo "<div class='sidebar-content'>";

// DASHBOARD Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>DASHBOARD</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['my/index.php', '/my/']) . "'>";
echo "<a href='{$CFG->wwwroot}/my/' class='sidebar-link'>";
echo "<i class='fa fa-th-large sidebar-icon'></i>";
echo "<span class='sidebar-text'>Admin Dashboard</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . (basename($current_script) === 'community.php' ? 'active' : '') . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/community.php' class='sidebar-link'>";
echo "<i class='fa fa-users sidebar-icon'></i>";
echo "<span class='sidebar-text'>Community</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['enrollments.php', 'enroll_student.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/enrollments.php' class='sidebar-link'>";
echo "<i class='fa fa-graduation-cap sidebar-icon'></i>";
echo "<span class='sidebar-text'>Enrollments</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

// OVERVIEW Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>OVERVIEW</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['teachers_list.php', 'add_teacher.php', 'edit_teacher.php', 'view_teacher.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/teachers_list.php' class='sidebar-link'>";
echo "<i class='fa fa-users sidebar-icon'></i>";
echo "<span class='sidebar-text'>Teachers</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['school_hierarchy.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/school_hierarchy.php' class='sidebar-link'>";
echo "<i class='fa fa-sitemap sidebar-icon'></i>";
echo "<span class='sidebar-text'>School Hierarchy</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

// COURSES & PROGRAMS Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>COURSES & PROGRAMS</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['courses.php', 'course_categories.php', 'manage_course_content.php', 'view_all_courses.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/courses.php' class='sidebar-link'>";
echo "<i class='fa fa-book sidebar-icon'></i>";
echo "<span class='sidebar-text'>Courses & Programs</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['badges/index.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/badges/index.php?type=1' class='sidebar-link'>";
echo "<i class='fa fa-certificate sidebar-icon'></i>";
echo "<span class='sidebar-text'>Badges</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['local/certificate_approval']) . "'>";
echo "<a href='{$CFG->wwwroot}/local/certificate_approval/index.php' class='sidebar-link'>";
echo "<i class='fa fa-graduation-cap sidebar-icon'></i>";
echo "<span class='sidebar-text'>Certificates</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['schools_management.php', 'companies_list.php', 'company_create.php', 'company_edit.php', 'company_import.php', 'assign_to_school.php', 'assign_school.php', 'training_events.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/schools_management.php' class='sidebar-link'>";
echo "<i class='fa fa-school sidebar-icon'></i>";
echo "<span class='sidebar-text'>Schools</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['local/ebook/manage.php', 'local/ebook/edit.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/local/ebook/manage.php' class='sidebar-link'>";
echo "<i class='fa fa-book-reader sidebar-icon'></i>";
echo "<span class='sidebar-text'>E-Book Manage</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

// INSIGHTS Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>INSIGHTS</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['admin_analytics.php', 'admin_analytics']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/admin_analytics.php' class='sidebar-link'>";
echo "<i class='fa fa-chart-bar sidebar-icon'></i>";
echo "<span class='sidebar-text'>Analytics</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['insights/insights.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/report/insights/insights.php' class='sidebar-link'>";
echo "<i class='fa fa-chart-line sidebar-icon'></i>";
echo "<span class='sidebar-text'>Predictive Models</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['superreports', 'custom_grader_report.php', 'bulk_download.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/superreports/index.php' class='sidebar-link'>";
echo "<i class='fa fa-file-alt sidebar-icon'></i>";
echo "<span class='sidebar-text'>Reports</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['competency_maps.php', 'check_competency.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/competency_maps.php' class='sidebar-link'>";
echo "<i class='fa fa-map sidebar-icon'></i>";
echo "<span class='sidebar-text'>Competency</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";



// AI ASSISTANT Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>AI ASSISTANT</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['ai_assistant.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/ai_assistant.php' class='sidebar-link'>";
echo "<i class='fa fa-robot sidebar-icon'></i>";
echo "<span class='sidebar-text'>AI Assistant</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['train_ai.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/train_ai.php' class='sidebar-link'>";
echo "<i class='fa fa-graduation-cap sidebar-icon'></i>";
echo "<span class='sidebar-text'>Train AI</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item'>";
echo "<a href='#' class='sidebar-link maptest-sidebar-trigger' id='maptest-sidebar-link'>";
echo "<i class='fa fa-rocket sidebar-icon'></i>";
echo "<span class='sidebar-text'>MAP Test</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

// SETTINGS Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>SETTINGS</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['user_profile_management.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/user_profile_management.php' class='sidebar-link'>";
echo "<i class='fa fa-cog sidebar-icon'></i>";
echo "<span class='sidebar-text'>System Settings</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['users_management_dashboard.php', 'user_management.php', 'browse_users.php', 'create_user.php', 'edit_users.php', 'upload_users.php', 'detail_']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/users_management_dashboard.php' class='sidebar-link'>";
echo "<i class='fa fa-user-friends sidebar-icon'></i>";
echo "<span class='sidebar-text'>User Management</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['cohorts/', 'add_cohort.php', 'edit_cohort.php', 'delete_cohort.php', 'manage_members.php', 'upload_cohorts.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/index.php' class='sidebar-link'>";
echo "<i class='fa fa-users-cog sidebar-icon'></i>";
echo "<span class='sidebar-text'>Cohort Navigation</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['cohort_sidebar_access.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/cohort_sidebar_access.php' class='sidebar-link'>";
echo "<i class='fa fa-toggle-on sidebar-icon'></i>";
echo "<span class='sidebar-text'>Cohort Sidebar Access</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['dashboard_access.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/dashboard_access.php' class='sidebar-link'>";
echo "<i class='fa fa-tachometer-alt sidebar-icon'></i>";
echo "<span class='sidebar-text'>Dashboard Access</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['emulator_access.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/emulator_access.php' class='sidebar-link'>";
echo "<i class='fa fa-microchip sidebar-icon'></i>";
echo "<span class='sidebar-text'>Emulator Access</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['langswitch', 'local/langswitch']) . "'>";
echo "<a href='{$CFG->wwwroot}/local/langswitch/index.php' class='sidebar-link'>";
echo "<i class='fa fa-language sidebar-icon'></i>";
echo "<span class='sidebar-text'>Switch the language</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . (basename($current_script) === 'community_management.php' ? 'active' : '') . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/community_management.php' class='sidebar-link'>";
echo "<i class='fa fa-shield-alt sidebar-icon'></i>";
echo "<span class='sidebar-text'>Community Management</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

// HELP & SUPPORT Section
echo "<div class='sidebar-section'>";
echo "<h3 class='sidebar-category'>HELP & SUPPORT</h3>";
echo "<ul class='sidebar-menu'>";
echo "<li class='sidebar-item " . is_active_page(['support_videos.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/support_videos.php' class='sidebar-link'>";
echo "<i class='fa fa-video sidebar-icon'></i>";
echo "<span class='sidebar-text'>Support Videos</span>";
echo "</a>";
echo "</li>";
echo "<li class='sidebar-item " . is_active_page(['help_tickets.php']) . "'>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/admin/help_tickets.php' class='sidebar-link'>";
echo "<i class='fa fa-life-ring sidebar-icon'></i>";
echo "<span class='sidebar-text'>Help Tickets</span>";
echo "</a>";
echo "</li>";
echo "</ul>";
echo "</div>";

echo "</div>"; // sidebar-content
echo "</div>"; // admin-sidebar

// Include MAP Test Modal (available on all admin pages)
require_once(__DIR__ . '/maptest_modal.php');
?>