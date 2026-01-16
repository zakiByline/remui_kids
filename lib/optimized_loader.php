<?php
/**
 * Optimized Loader for School Admin Pages
 * Provides fast, cached access to common data
 */
 
defined('MOODLE_INTERNAL') || die();
 
class school_admin_loader {
   
    private static $cache_enabled = true;
    private static $debug_enabled = false;
   
    /**
     * Get company info for current user (cached)
     */
    public static function get_user_company($user_id) {
        global $DB;
       
        static $company_cache = [];
       
        if (isset($company_cache[$user_id])) {
            return $company_cache[$user_id];
        }
       
        $company_info = $DB->get_record_sql(
            "SELECT c.*
             FROM {company} c
             JOIN {company_users} cu ON c.id = cu.companyid
             WHERE cu.userid = ? AND cu.managertype = 1
             LIMIT 1",
            [$user_id]
        );
       
        $company_cache[$user_id] = $company_info;
        return $company_info;
    }
   
    /**
     * Get optimized teacher statistics
     */
    public static function get_teacher_stats($company_id) {
        global $DB;
       
        $week_start = strtotime('monday this week');
        $month_start = strtotime(date('Y-m-01'));
       
        // Single optimized query
        $stats = $DB->get_record_sql(
            "SELECT
                COUNT(DISTINCT u.id) as total,
                COUNT(DISTINCT CASE WHEN u.lastaccess >= ? THEN u.id END) as active_week,
                COUNT(DISTINCT CASE WHEN u.timecreated >= ? THEN u.id END) as added_month
             FROM {user} u
             INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
             INNER JOIN {role_assignments} ra ON u.id = ra.userid
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
             WHERE u.deleted = 0 AND u.suspended = 0",
            [$week_start, $month_start, $company_id]
        );
       
        return $stats;
    }
   
    /**
     * Get optimized student statistics
     */
    public static function get_student_stats($company_id) {
        global $DB;
       
        $week_start = strtotime('monday this week');
        $month_start = strtotime(date('Y-m-01'));
       
        // Single optimized query
        $stats = $DB->get_record_sql(
            "SELECT
                COUNT(DISTINCT u.id) as total,
                COUNT(DISTINCT CASE WHEN u.lastaccess >= ? THEN u.id END) as active_week,
                COUNT(DISTINCT CASE WHEN u.timecreated >= ? THEN u.id END) as added_month
             FROM {user} u
             INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ?
             WHERE cu.educator = 0 AND u.deleted = 0",
            [$week_start, $month_start, $company_id]
        );
       
        return $stats;
    }
   
    /**
     * Get teachers with minimal queries
     */
    public static function get_teachers($company_id) {
        global $DB;
       
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email,
                    u.phone1, u.city, u.country, u.suspended, u.lastaccess, u.timecreated, u.picture,
                    GROUP_CONCAT(DISTINCT r.shortname) AS roles
             FROM {user} u
             INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
             INNER JOIN {role_assignments} ra ON u.id = ra.userid
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
             WHERE u.deleted = 0
             GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.phone1, u.city, u.country,
                      u.suspended, u.lastaccess, u.timecreated, u.picture
             ORDER BY u.firstname, u.lastname",
            [$company_id]
        );
    }
   
    /**
     * Get students with minimal queries
     */
    public static function get_students($company_id) {
        global $DB;
       
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email,
                    u.phone1, u.city, u.country, u.suspended, u.lastaccess, u.timecreated, u.picture,
                    GROUP_CONCAT(DISTINCT c.name) as cohorts
             FROM {user} u
             INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ?
             LEFT JOIN {cohort_members} cm ON u.id = cm.userid
             LEFT JOIN {cohort} c ON cm.cohortid = c.id
             WHERE cu.educator = 0 AND u.deleted = 0
             GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.phone1, u.city, u.country,
                      u.suspended, u.lastaccess, u.timecreated, u.picture
             ORDER BY u.firstname, u.lastname",
            [$company_id]
        );
    }
   
    /**
     * Get user picture URL (optimized with static cache)
     */
    public static function get_picture_url($user, $page) {
        static $cache = [];
       
        $cache_key = $user->id . '_' . ($user->picture ?? 0);
       
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }
       
        $result = ['has_picture' => false, 'url' => '', 'initials' => ''];
        $result['initials'] = strtoupper(substr($user->firstname ?? '', 0, 1) . substr($user->lastname ?? '', 0, 1));
       
        if (!empty($user->picture) && $user->picture > 0) {
            try {
                $user_context = context_user::instance($user->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'filename', false);
               
                if (!empty($files)) {
                    $user_picture = new user_picture($user);
                    $user_picture->size = 1;
                    $url = $user_picture->get_url($page)->out();
                   
                    if (strpos($url, '/pluginfile.php/' . $user_context->id . '/') !== false &&
                        strpos($url, '/remui_kids/') === false) {
                        $result['has_picture'] = true;
                        $result['url'] = $url;
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }
       
        $cache[$cache_key] = $result;
        return $result;
    }
   
    /**
     * Conditional log (only logs if debug enabled)
     */
    public static function log($message) {
        if (self::$debug_enabled) {
            error_log($message);
        }
    }
   
    /**
     * Enable debug mode
     */
    public static function enable_debug() {
        self::$debug_enabled = true;
    }
}