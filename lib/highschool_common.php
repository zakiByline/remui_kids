<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Common functions and utilities for high school pages.
 * Provides shared functionality for all high school student pages.
 *
 * @package    theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/highschool_sidebar.php');

if (!function_exists('remui_kids_setup_highschool_page')) {
    /**
     * Set up common page properties for high school pages.
     * This function configures the Moodle PAGE object with standard settings
     * for high school student pages.
     *
     * @param object $PAGE Moodle page object
     * @param string $url Page URL
     * @param string $title Page title
     * @param string $pagelayout Page layout (default: 'base')
     * @return void
     */
    function remui_kids_setup_highschool_page($PAGE, string $url, string $title, string $pagelayout = 'base'): void {
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_title($title);
        $PAGE->set_pagelayout($pagelayout);
        $PAGE->add_body_class('custom-dashboard-page');
        $PAGE->add_body_class('has-student-sidebar');
        $PAGE->add_body_class('highschool-page');
    }
}

if (!function_exists('remui_kids_check_highschool_student')) {
    /**
     * Check if the current user is a high school student (Grade 8-12).
     *
     * @param object $USER Moodle user object
     * @param object $DB Moodle database object
     * @return bool True if user is a high school student
     */
    function remui_kids_check_highschool_student($USER, $DB): bool {
        // Get user's cohort information
        $usercohorts = $DB->get_records_sql(
            "SELECT c.name, c.id 
             FROM {cohort} c 
             JOIN {cohort_members} cm ON c.id = cm.cohortid 
             WHERE cm.userid = ?",
            [$USER->id]
        );

        if (!empty($usercohorts)) {
            $cohort = reset($usercohorts);
            $usercohortname = $cohort->name;

            // Check if user is in Grade 8-12 (High School)
            if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
                return true;
            }
        }

        // Check user profile custom field for grade as fallback
        $user_profile_fields = profile_user_record($USER->id);
        if (isset($user_profile_fields->grade)) {
            $user_grade = $user_profile_fields->grade;
            if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $user_grade)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('remui_kids_render_highschool_page_standard')) {
    /**
     * Standard rendering function for high school pages.
     * This function handles the common pattern:
     * 1. Build sidebar context
     * 2. Merge with page-specific template data
     * 3. Render template (which should include sidebar partial)
     * 4. Output header, content, and footer
     *
     * @param object $OUTPUT Moodle output renderer
     * @param string $activepage Sidebar key to mark as active
     * @param object $USER Moodle user object
     * @param string $template_name Template name (without 'theme_remui_kids/' prefix)
     * @param array $page_data Additional template data
     * @return void Outputs the page directly
     */
    function remui_kids_render_highschool_page_standard($OUTPUT, string $activepage, $USER, string $template_name, array $page_data = []): void {
        // Build sidebar context
        $sidebar_context = remui_kids_build_highschool_sidebar_context($activepage, $USER);
        
        // Merge sidebar context with page-specific data
        $template_data = array_merge($sidebar_context, $page_data);
        
        // Output header
        echo $OUTPUT->header();
        
        // Render template (template should include sidebar partial)
        echo $OUTPUT->render_from_template('theme_remui_kids/' . $template_name, $template_data);
        
        // Output footer
        echo $OUTPUT->footer();
    }
}

