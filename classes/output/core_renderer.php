<?php
/**
 * Core renderer override for theme_remui_kids
 * 
 * This file automatically injects translated strings into ALL templates,
 * eliminating the need to modify each template individually.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_remui_kids\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom core renderer that injects translations into all templates
 */
class core_renderer extends \theme_remui\output\core_renderer {

    /**
     * Override render_from_template to inject translated strings
     *
     * @param string $templatename The name of the template
     * @param array|stdClass $context The context for the template
     * @return string The rendered template
     */
    public function render_from_template($templatename, $context) {
        // Convert to array if object
        if (is_object($context)) {
            $context = (array) $context;
        }
        
        // Inject translations into context
        $context = $this->inject_translations($context);
        
        // Inject translator context for grading_navigation template
        if ($templatename === 'mod_assign/grading_navigation') {
            $context = $this->inject_translator_context($context);
        }
        
        return parent::render_from_template($templatename, $context);
    }

    /**
     * Inject all translated strings into the template context
     *
     * @param array $context The template context
     * @return array The context with injected translations
     */
    protected function inject_translations($context) {
        // Skip if already injected
        if (isset($context['_translations_injected'])) {
            return $context;
        }
        
        // Get all translated strings
        $translations = $this->get_all_translations();
        
        // Add to context with 't' prefix for easy access
        // Usage in templates: {{t.dashboard}}, {{t.my_courses}}, etc.
        $context['t'] = $translations;
        $context['_translations_injected'] = true;
        
        return $context;
    }

    /**
     * Get a translated string safely (returns key if string doesn't exist)
     * 
     * @param string $identifier String identifier
     * @param string $component Component name
     * @return string Translated string or identifier
     */
    protected function safe_get_string($identifier, $component = 'theme_remui_kids') {
        try {
            return get_string($identifier, $component);
        } catch (\Exception $e) {
            return $identifier; // Return the key as fallback
        }
    }

    /**
     * Get all translated strings for the current language
     *
     * @return array Array of translated strings
     */
    protected function get_all_translations() {
        $component = 'theme_remui_kids';
        
        return [
            // Navigation
            'dashboard' => $this->safe_get_string('nav_dashboard', $component),
            'my_courses' => $this->safe_get_string('nav_mycourses', $component),
            'lessons' => $this->safe_get_string('nav_lessons', $component),
            'activities' => $this->safe_get_string('nav_activities', $component),
            'achievements' => $this->safe_get_string('nav_achievements', $component),
            'competencies' => $this->safe_get_string('nav_competencies', $component),
            'grades' => $this->safe_get_string('nav_grades', $component),
            'badges' => $this->safe_get_string('nav_badges', $component),
            'schedule' => $this->safe_get_string('nav_schedule', $component),
            'settings' => $this->safe_get_string('nav_settings', $component),
            'calendar' => $this->safe_get_string('nav_calendar', $component),
            'messages' => $this->safe_get_string('nav_messages', $component),
            'communities' => $this->safe_get_string('nav_communities', $component),
            'my_reports' => $this->safe_get_string('nav_myreports', $component),
            'assignments' => $this->safe_get_string('nav_assignments', $component),
            'profile' => $this->safe_get_string('nav_profile', $component),
            'ebooks' => $this->safe_get_string('nav_ebooks', $component),
            'help' => $this->safe_get_string('nav_help', $component),
            
            // Section headers
            'section_dashboard' => $this->safe_get_string('section_dashboard', $component),
            'section_tools' => $this->safe_get_string('section_tools', $component),
            'section_quickactions' => $this->safe_get_string('section_quickactions', $component),
            'section_overview' => $this->safe_get_string('section_overview', $component),
            'section_courses' => $this->safe_get_string('section_courses', $component),
            'section_insights' => $this->safe_get_string('section_insights', $component),
            
            // Dashboard common
            'welcome' => $this->safe_get_string('welcome', $component),
            'welcome_back' => $this->safe_get_string('welcome_back', $component),
            'progress' => $this->safe_get_string('progress', $component),
            'your_progress' => $this->safe_get_string('your_progress', $component),
            'courses' => $this->safe_get_string('courses', $component),
            'total_courses' => $this->safe_get_string('total_courses', $component),
            'enrolled_courses' => $this->safe_get_string('enrolled_courses', $component),
            'active_courses' => $this->safe_get_string('active_courses', $component),
            'completed_courses' => $this->safe_get_string('completed_courses', $component),
            'activities_done' => $this->safe_get_string('activities_done', $component),
            'activities_completed' => $this->safe_get_string('activities_completed', $component),
            'total_activities' => $this->safe_get_string('total_activities', $component),
            'view_all' => $this->safe_get_string('view_all', $component),
            'view_details' => $this->safe_get_string('view_details', $component),
            'view_course' => $this->safe_get_string('view_course', $component),
            'continue_learning' => $this->safe_get_string('continue_learning', $component),
            'start_course' => $this->safe_get_string('start_course', $component),
            'resume_course' => $this->safe_get_string('resume_course', $component),
            
            // Statistics
            'subject_focus' => $this->safe_get_string('subject_focus', $component),
            'subject_focus_desc' => $this->safe_get_string('subject_focus_desc', $component),
            'course_overview' => $this->safe_get_string('course_overview', $component),
            'course_overview_desc' => $this->safe_get_string('course_overview_desc', $component),
            'completion_rate' => $this->safe_get_string('completion_rate', $component),
            
            // Admin/School Manager Dashboard
            'admin_dashboard' => $this->safe_get_string('admin_dashboard', $component),
            'platform_metrics' => $this->safe_get_string('platform_metrics', $component),
            'avg_course_rating' => $this->safe_get_string('avg_course_rating', $component),
            'excellent' => $this->safe_get_string('excellent', $component),
            'good' => $this->safe_get_string('good', $component),
            'average' => $this->safe_get_string('average', $component),
            'total_schools' => $this->safe_get_string('total_schools', $component),
            'active' => $this->safe_get_string('active', $component),
            'inactive' => $this->safe_get_string('inactive', $component),
            'available' => $this->safe_get_string('available', $component),
            'enrolled' => $this->safe_get_string('enrolled', $component),
            
            // User statistics
            'user_statistics' => $this->safe_get_string('user_statistics', $component),
            'new_this_month' => $this->safe_get_string('new_this_month', $component),
            'active_users' => $this->safe_get_string('active_users', $component),
            'admins' => $this->safe_get_string('admins', $component),
            'students' => $this->safe_get_string('students', $component),
            'teachers' => $this->safe_get_string('teachers', $component),
            'total_users' => $this->safe_get_string('total_users', $component),
            'total_students' => $this->safe_get_string('total_students', $component),
            
            // Course statistics
            'course_statistics' => $this->safe_get_string('course_statistics', $component),
            'recent_activity' => $this->safe_get_string('recent_activity', $component),
            
            // Management
            'management' => $this->safe_get_string('management', $component),
            'teacher_management' => $this->safe_get_string('teacher_management', $component),
            'student_management' => $this->safe_get_string('student_management', $component),
            'parent_management' => $this->safe_get_string('parent_management', $component),
            'course_management' => $this->safe_get_string('course_management', $component),
            'enrollments' => $this->safe_get_string('nav_enrollments', $component),
            'actions' => $this->safe_get_string('actions', $component),
            'bulk_download' => $this->safe_get_string('bulk_download', $component),
            'bulk_upload_images' => $this->safe_get_string('bulk_upload_images', $component),
            'reports' => $this->safe_get_string('nav_reports', $component),
            'course_reports' => $this->safe_get_string('course_reports', $component),
            'teacher_report' => $this->safe_get_string('teacher_report', $component),
            'student_reports' => $this->safe_get_string('student_reports', $component),
            'system' => $this->safe_get_string('system', $component),
            'activity_log' => $this->safe_get_string('activity_log', $component),
            
            // Common UI
            'loading' => $this->safe_get_string('loading', $component),
            'error' => $this->safe_get_string('error', $component),
            'success' => $this->safe_get_string('success', $component),
            'save' => $this->safe_get_string('save', $component),
            'cancel' => $this->safe_get_string('cancel', $component),
            'close' => $this->safe_get_string('close', $component),
            'submit' => $this->safe_get_string('submit', $component),
            'edit' => $this->safe_get_string('edit', $component),
            'delete' => $this->safe_get_string('delete', $component),
            'search' => $this->safe_get_string('search', $component),
            'filter' => $this->safe_get_string('filter', $component),
            'refresh' => $this->safe_get_string('refresh', $component),
            'no_data' => $this->safe_get_string('no_data', $component),
            'no_results' => $this->safe_get_string('no_results', $component),
            
            // Quick actions
            'ebooks_desc' => $this->safe_get_string('ebooks_desc', $component),
            'scratch_editor' => $this->safe_get_string('scratch_editor', $component),
            'scratch_editor_desc' => $this->safe_get_string('scratch_editor_desc', $component),
            'code_editor' => $this->safe_get_string('code_editor', $component),
            'code_editor_desc' => $this->safe_get_string('code_editor_desc', $component),
        ];
    }

    /**
     * Inject translator plugin context for grading navigation template
     *
     * @param array $context The template context
     * @return array The context with translator variables added
     */
    protected function inject_translator_context($context) {
        // Check if translator plugin is enabled
        $translator_enabled = (bool)get_config('local_translator', 'enabled');
        $translator_sourcelang = get_config('local_translator', 'sourcelang') ?: 'en';
        
        $context['translator_enabled'] = $translator_enabled;
        $context['translator_sourcelang'] = $translator_sourcelang;
        
        // Load translator CSS if enabled
        if ($translator_enabled) {
            global $PAGE;
            $PAGE->requires->css('/local/translator/styles.css');
        }
        
        return $context;
    }
}
