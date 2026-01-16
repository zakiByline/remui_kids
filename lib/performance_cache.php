<?php
/**
 * Performance Caching Helper for School Admin Pages
 * Reduces database queries and improves page load times
 */

defined('MOODLE_INTERNAL') || die();

class school_admin_cache {
    
    /**
     * Cache statistics for a company (school)
     * @param int $company_id
     * @param int $cache_duration seconds to cache (default 300 = 5 minutes)
     * @return array Cached statistics
     */
    public static function get_company_stats($company_id, $cache_duration = 300) {
        global $DB;
        
        $cache_key = 'company_stats_' . $company_id;
        $cache = cache::make('theme_remui_kids', 'company_data');
        
        $stats = $cache->get($cache_key);
        
        if ($stats === false) {
            // Cache miss - fetch from database
            $stats = self::fetch_company_stats($company_id);
            $cache->set($cache_key, $stats);
        }
        
        return $stats;
    }
    
    /**
     * Fetch company statistics from database
     */
    private static function fetch_company_stats($company_id) {
        global $DB;
        
        // Single optimized query to get all stats at once
        $sql = "SELECT 
                    COUNT(DISTINCT CASE WHEN cu.educator = 1 THEN u.id END) as total_teachers,
                    COUNT(DISTINCT CASE WHEN cu.educator = 0 THEN u.id END) as total_students,
                    COUNT(DISTINCT CASE WHEN cu.educator = 1 AND u.lastaccess >= :week_start THEN u.id END) as active_teachers_week,
                    COUNT(DISTINCT CASE WHEN cu.educator = 0 AND u.lastaccess >= :week_start2 THEN u.id END) as active_students_week,
                    COUNT(DISTINCT CASE WHEN cu.educator = 1 AND u.timecreated >= :month_start THEN u.id END) as teachers_this_month,
                    COUNT(DISTINCT CASE WHEN cu.educator = 0 AND u.timecreated >= :month_start2 THEN u.id END) as students_this_month
                FROM {user} u
                INNER JOIN {company_users} cu ON u.id = cu.userid
                WHERE cu.companyid = :company_id 
                AND u.deleted = 0 
                AND u.suspended = 0";
        
        $week_start = strtotime('monday this week');
        $month_start = strtotime(date('Y-m-01'));
        
        $params = [
            'company_id' => $company_id,
            'week_start' => $week_start,
            'week_start2' => $week_start,
            'month_start' => $month_start,
            'month_start2' => $month_start
        ];
        
        return $DB->get_record_sql($sql, $params);
    }
    
    /**
     * Clear cache for a company
     */
    public static function clear_company_cache($company_id) {
        $cache = cache::make('theme_remui_kids', 'company_data');
        $cache->delete('company_stats_' . $company_id);
    }
    
    /**
     * Get user profile picture URL with caching
     */
    public static function get_user_picture_url($user_id, $size = 1) {
        global $DB, $PAGE;
        
        static $picture_cache = [];
        
        $cache_key = $user_id . '_' . $size;
        
        if (isset($picture_cache[$cache_key])) {
            return $picture_cache[$cache_key];
        }
        
        $user = $DB->get_record('user', ['id' => $user_id], 'id,picture', MUST_EXIST);
        
        if ($user->picture > 0) {
            $user_context = context_user::instance($user_id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'filename', false);
            
            if (!empty($files)) {
                $user_picture = new user_picture($user);
                $user_picture->size = $size;
                $url = $user_picture->get_url($PAGE)->out();
                
                if (strpos($url, '/pluginfile.php/' . $user_context->id . '/') !== false &&
                    strpos($url, '/remui_kids/') === false &&
                    strpos($url, '/theme/') === false) {
                    $picture_cache[$cache_key] = $url;
                    return $url;
                }
            }
        }
        
        $picture_cache[$cache_key] = '';
        return '';
    }
}

/**
 * Optimized database query helper
 */
class school_admin_query {
    
    /**
     * Get teachers for a company with minimal queries
     */
    public static function get_company_teachers($company_id, $limit = 0) {
        global $DB;
        
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username, 
                       u.suspended, u.lastaccess, u.picture,
                       GROUP_CONCAT(DISTINCT r.shortname) as roles
                FROM {user} u
                INNER JOIN {company_users} cu ON u.id = cu.userid
                INNER JOIN {role_assignments} ra ON u.id = ra.userid
                INNER JOIN {role} r ON r.id = ra.roleid
                WHERE cu.companyid = :company_id 
                  AND cu.educator = 1
                  AND u.deleted = 0
                  AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
                GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, 
                         u.suspended, u.lastaccess, u.picture
                ORDER BY u.firstname, u.lastname";
        
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        return $DB->get_records_sql($sql, ['company_id' => $company_id]);
    }
    
    /**
     * Get students for a company with minimal queries
     */
    public static function get_company_students($company_id, $limit = 0) {
        global $DB;
        
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
                       u.suspended, u.lastaccess, u.picture,
                       GROUP_CONCAT(DISTINCT c.name) as cohorts
                FROM {user} u
                INNER JOIN {company_users} cu ON u.id = cu.userid
                LEFT JOIN {cohort_members} cm ON u.id = cm.userid
                LEFT JOIN {cohort} c ON cm.cohortid = c.id
                WHERE cu.companyid = :company_id 
                  AND cu.educator = 0
                  AND u.deleted = 0
                GROUP BY u.id, u.firstname, u.lastname, u.email, u.username,
                         u.suspended, u.lastaccess, u.picture
                ORDER BY u.firstname, u.lastname";
        
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        return $DB->get_records_sql($sql, ['company_id' => $company_id]);
    }
}