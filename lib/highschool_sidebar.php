<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Helper to build context for high school student sidebar.
 *
 * @package    theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/cohort_sidebar_helper.php');
require_once(__DIR__ . '/emulator_manager.php');

if (!function_exists('remui_kids_build_highschool_sidebar_context')) {
    /**
     * Build the shared sidebar context for high school student dashboards.
     *
     * @param string $activepage Sidebar key to mark as active (e.g. 'courses', 'assignments').
     * @param stdClass $user The Moodle user record.
     * @param array $overrides Optional overrides to merge into the context.
     * @return array
     */
    function remui_kids_build_highschool_sidebar_context(string $activepage, stdClass $user, array $overrides = []): array {
        $context = [
            'student_name' => fullname($user),
            'dashboardurl' => (new moodle_url('/theme/remui_kids/highschool_dashboard.php'))->out(),
            'treeviewurl' => (new moodle_url('/theme/remui_kids/highschool_treeview.php'))->out(),
            'communityurl' => (new moodle_url('/theme/remui_kids/community.php'))->out(),
            'mycoursesurl' => (new moodle_url('/theme/remui_kids/highschool_courses.php'))->out(),
            'assignmentsurl' => (new moodle_url('/theme/remui_kids/highschool_assignments.php'))->out(),
            'gradesurl' => (new moodle_url('/theme/remui_kids/highschool_grades.php'))->out(),
            'reportsurl' => (new moodle_url('/theme/remui_kids/highschool_myreports.php'))->out(),
            'achievementsurl' => (new moodle_url('/theme/remui_kids/achievements.php'))->out(),
            'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
            'currentactivityurl' => (new moodle_url('/theme/remui_kids/highschool_current_activity.php'))->out(),
            'lessonsurl' => (new moodle_url('/theme/remui_kids/highschool_lessons.php'))->out(),
            'activitiesurl' => (new moodle_url('/theme/remui_kids/highschool_activities.php'))->out(),
            'calendarurl' => (new moodle_url('/theme/remui_kids/highschool_calendar.php'))->out(),
            'messagesurl' => (new moodle_url('/theme/remui_kids/highschool_messages.php'))->out(),
            'profileurl' => (new moodle_url('/theme/remui_kids/highschool_profile.php'))->out(),
            'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
            'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
            'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
            'askteacherurl' =>  (new moodle_url('/theme/remui_kids/highschool_messages.php'))->out(),
            'studypartnerurl' => (new moodle_url('/local/studypartner/index.php'))->out(),
            'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
            
            // Sidebar access permissions (based on user's cohort)
            'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($user->id),
            'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($user->id),
            'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
        ];

        if ($activepage !== '') {
            $context['currentpage'] = [$activepage => true];
        }

        // Merge overrides but ensure certificatesurl is always set
        $merged_context = array_merge($context, $overrides);
        
        // Ensure certificatesurl is always valid (not empty)
        if (empty($merged_context['certificatesurl'])) {
            $merged_context['certificatesurl'] = (new moodle_url('/local/certificate_approval/index.php'))->out();
        }
        
        return $merged_context;
    }
}

if (!function_exists('remui_kids_render_highschool_page')) {
    /**
     * Render a high school page with common sidebar, header, and footer.
     * This is a helper function to ensure consistent layout across all high school pages.
     *
     * @param object $OUTPUT Moodle output renderer
     * @param string $activepage Sidebar key to mark as active (e.g. 'courses', 'assignments')
     * @param object $USER The Moodle user record
     * @param array $template_data Template data to merge with sidebar context
     * @param string $page_content HTML content for the page body
     * @return void Outputs the page directly
     */
    function remui_kids_render_highschool_page($OUTPUT, string $activepage, $USER, array $template_data = [], string $page_content = ''): void {
        // Build sidebar context
        $sidebar_context = remui_kids_build_highschool_sidebar_context($activepage, $USER);
        
        // Merge with provided template data
        $full_template_data = array_merge($sidebar_context, $template_data);
        
        // Output header
        echo $OUTPUT->header();
        
        // Render sidebar
        echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $full_template_data);
        
        // Output page content if provided
        if (!empty($page_content)) {
            echo $page_content;
        }
        
        // Output footer
        echo $OUTPUT->footer();
    }
}

if (!function_exists('remui_kids_render_highschool_page_with_layout')) {
    /**
     * @param object $OUTPUT Moodle output renderer
     * @param string $activepage Sidebar key to mark as active (e.g. 'courses', 'assignments')
     * @param object $USER The Moodle user record
     * @param string $page_content HTML content for the page body
     * @param array $template_data Additional template data to merge with sidebar context
     * @return void Outputs the page directly
     */
    function remui_kids_render_highschool_page_with_layout($OUTPUT, string $activepage, $USER, string $page_content = '', array $template_data = []): void {
        $sidebar_context = remui_kids_build_highschool_sidebar_context($activepage, $USER);
        $full_template_data = array_merge($sidebar_context, $template_data);
        $full_template_data['page_content'] = $page_content;
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('theme_remui_kids/highschool_layout', $full_template_data);
        echo $OUTPUT->footer();
    }
}

if (!function_exists('remui_kids_render_highschool_template_with_layout')) {
    /**
     *
     * @param object $OUTPUT Moodle output renderer
     * @param string $activepage Sidebar key to mark as active (e.g. 'courses', 'assignments')
     * @param object $USER The Moodle user record
     * @param string $template_name Name of the template to render (e.g. 'highschool_courses_page')
     * @param array $template_data Template data for the page template
     * @return void Outputs the page directly
     */
    function remui_kids_render_highschool_template_with_layout($OUTPUT, string $activepage, $USER, string $template_name, array $template_data = []): void {
        $sidebar_context = remui_kids_build_highschool_sidebar_context($activepage, $USER);
        $full_template_data = array_merge($sidebar_context, $template_data);
        $page_content = $OUTPUT->render_from_template('theme_remui_kids/' . $template_name, $full_template_data);
        echo $OUTPUT->header();
        echo $page_content;
        echo $OUTPUT->footer();
    }
}