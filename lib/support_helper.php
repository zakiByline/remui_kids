<?php
/**
 * Support Videos Helper Functions
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Check if support videos exist for a category and return count
 *
 * @param string $category Category to check (e.g., 'courses', 'students', 'teachers')
 * @param int $userid User ID (default: current user)
 * @return array ['has_videos' => bool, 'count' => int]
 */
function theme_remui_kids_check_support_videos($category, $userid = null) {
    global $CFG, $DB, $USER;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    $result = ['has_videos' => false, 'count' => 0, 'html' => ''];
    
    try {
        // Check if help button is enabled in settings
        $enable_helpbutton = get_config('local_support', 'enable_helpbutton');
        if ($enable_helpbutton === false || $enable_helpbutton == 0) {
            // Help button is disabled, return empty result
            return $result;
        }
        
        // Check if plugin exists
        if (!file_exists($CFG->dirroot . '/local/support/classes/video_manager.php')) {
            return $result;
        }
        
        require_once($CFG->dirroot . '/local/support/classes/video_manager.php');
        
        // Check if table exists
        if (!$DB->get_manager()->table_exists('local_support_videos')) {
            return $result;
        }
        
        // Determine user role
        $isadmin = is_siteadmin($userid);
        $context = context_system::instance();
        $isteacher = has_capability('moodle/course:update', $context, $userid);
        
        $targetrole = 'all';
        if ($isadmin) {
            $targetrole = 'admin';
        } else if ($isteacher) {
            $targetrole = 'teacher';
        } else {
            $targetrole = 'student';
        }
        
        // Get videos for category
        $videos = \local_support\video_manager::get_videos($category, $targetrole, true);
        
        if (!empty($videos)) {
            $result['has_videos'] = true;
            $result['count'] = count($videos);
        }
    } catch (Exception $e) {
        error_log("Support videos check error: " . $e->getMessage());
    }
    
    return $result;
}

/**
 * Get the URL for the Need Help page
 *
 * @return moodle_url URL to the need help page
 */
function theme_remui_kids_get_need_help_url() {
    global $CFG;
    return new moodle_url('/theme/remui_kids/need_help.php');
}

