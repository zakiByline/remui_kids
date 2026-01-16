<?php
/**
 * Get Parent Children Helper
 * 
 * Returns an array of children linked to a parent user
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get children linked to a parent user
 *
 * @param int $parentid The ID of the parent user
 * @return array Array of children with id, name, class, email, etc.
 */
function get_parent_children($parentid) {
    global $DB;
    
    $children = [];
    $children_records = [];
    
    try {
        // Method 1: IOMAD company_users approach (if table exists)
        if ($DB->get_manager()->table_exists('company_users')) {
            $parent_company = $DB->get_record('company_users', ['userid' => $parentid]);
            
            if ($parent_company) {
                $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated,
                               c.id as cohortid, c.name as cohortname
                        FROM {user} u
                        INNER JOIN {company_users} cu ON cu.userid = u.id
                        LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                        LEFT JOIN {cohort} c ON c.id = cm.cohortid
                        WHERE cu.companyid = :companyid
                        AND u.id IN (
                            SELECT ctx.instanceid 
                            FROM {role_assignments} ra
                            JOIN {context} ctx ON ctx.id = ra.contextid
                            JOIN {role} r ON r.id = ra.roleid
                            WHERE ra.userid = :parentid
                            AND ctx.contextlevel = :ctxlevel
                            AND r.shortname = 'parent'
                        )
                        AND u.deleted = 0
                        ORDER BY u.firstname, u.lastname";
                
                $children_records = $DB->get_records_sql($sql, [
                    'companyid' => $parent_company->companyid,
                    'parentid' => $parentid,
                    'ctxlevel' => CONTEXT_USER
                ]);
            }
        }
        
        // Method 2: Standard Moodle role assignment (if Method 1 found nothing)
        if (empty($children_records)) {
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated,
                           c.id as cohortid, c.name as cohortname
                    FROM {user} u
                    LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                    LEFT JOIN {cohort} c ON c.id = cm.cohortid
                    WHERE u.id IN (
                        SELECT ctx.instanceid 
                        FROM {role_assignments} ra
                        JOIN {context} ctx ON ctx.id = ra.contextid
                        JOIN {role} r ON r.id = ra.roleid
                        WHERE ra.userid = :parentid
                        AND ctx.contextlevel = :ctxlevel
                        AND r.shortname = 'parent'
                    )
                    AND u.deleted = 0
                    ORDER BY u.firstname, u.lastname";
            
            $children_records = $DB->get_records_sql($sql, [
                'parentid' => $parentid,
                'ctxlevel' => CONTEXT_USER
            ]);
        }
        
        // Convert records to array format
        foreach ($children_records as $child) {
            $class = 'N/A';
            $section = 'A';
            
            if (!empty($child->cohortname)) {
                if (preg_match('/grade[\s]*(\d+)/i', $child->cohortname, $matches)) {
                    $class = $matches[1];
                }
                if (preg_match('/section[\s]*([A-Z])/i', $child->cohortname, $matches)) {
                    $section = $matches[1];
                }
            }
            
            $children[] = [
                'id' => $child->id,
                'name' => fullname($child),
                'class' => $class,
                'section' => $section,
                'email' => $child->email,
                'timecreated' => $child->timecreated,
                'cohortid' => $child->cohortid ?? null,
                'cohortname' => $child->cohortname ?? null
            ];
        }
    } catch (Exception $e) {
        debugging('Error fetching parent children: ' . $e->getMessage());
        return [];
    }
    
    return $children;
}






