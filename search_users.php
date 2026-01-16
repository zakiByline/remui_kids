<?php
/**
 * Search Users for Enrollment
 * Returns teachers and students from the specific company/school
 */

require_once('../../config.php');
require_login();

global $DB, $USER;

// Set JSON header
header('Content-Type: application/json');

try {
    // Get parameters
    $query = optional_param('query', '', PARAM_TEXT);
    $company_id = optional_param('company_id', 0, PARAM_INT);
    $role_filter = optional_param('role', '', PARAM_TEXT);
    $load_all = optional_param('load_all', 0, PARAM_INT);
    
    // Validate inputs - allow empty query if load_all is set
    if (!$load_all && (empty($query) || strlen($query) < 2)) {
        echo json_encode([
            'success' => false,
            'message' => 'Query must be at least 2 characters'
        ]);
        exit;
    }
    
    if (empty($company_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Company ID is required'
        ]);
        exit;
    }
    
    // Verify user is a manager of this company
    $is_manager = $DB->record_exists('company_users', [
        'userid' => $USER->id,
        'companyid' => $company_id,
        'managertype' => 1
    ]);
    
    if (!$is_manager) {
        echo json_encode([
            'success' => false,
            'message' => 'Access denied'
        ]);
        exit;
    }
    
    // Search for users (teachers and students) in this company
    // Search by firstname, lastname, email, or username
    // Filter by role if specified
    
    // Build SQL based on role filter
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username,
                   GROUP_CONCAT(DISTINCT r.shortname) as roles
            FROM {user} u
            INNER JOIN {company_users} cu ON cu.userid = u.id";
    
    // Add role filter if specified
    if (!empty($role_filter)) {
        $sql .= " INNER JOIN {role_assignments} ra ON ra.userid = u.id
                  INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = :rolefilter
                  INNER JOIN {context} ctx ON ra.contextid = ctx.id";
    } else {
        $sql .= " LEFT JOIN {role_assignments} ra ON ra.userid = u.id
                  LEFT JOIN {role} r ON r.id = ra.roleid";
    }
    
    $sql .= " WHERE cu.companyid = :companyid
            AND u.deleted = 0
            AND u.suspended = 0
            AND u.id != :currentuserid";
    
    // Add search conditions only if query is provided
    if (!empty($query)) {
        $sql .= " AND (
                " . $DB->sql_like('u.firstname', ':search1', false) . "
                OR " . $DB->sql_like('u.lastname', ':search2', false) . "
                OR " . $DB->sql_like('u.email', ':search3', false) . "
                OR " . $DB->sql_like('u.username', ':search4', false) . "
                OR " . $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':search5', false) . "
            )";
    }
    
    $sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username
              ORDER BY u.firstname, u.lastname";
    
    // Increase limit for load_all, otherwise limit to 20
    $sql .= $load_all ? " LIMIT 100" : " LIMIT 20";
    
    $params = [
        'companyid' => $company_id,
        'currentuserid' => $USER->id
    ];
    
    // Add search parameters only if query is provided (already defined in SQL section above)
    if (!empty($query)) {
        $params['search1'] = '%' . $DB->sql_like_escape($query) . '%';
        $params['search2'] = '%' . $DB->sql_like_escape($query) . '%';
        $params['search3'] = '%' . $DB->sql_like_escape($query) . '%';
        $params['search4'] = '%' . $DB->sql_like_escape($query) . '%';
        $params['search5'] = '%' . $DB->sql_like_escape($query) . '%';
    }
    
    // Add role filter parameter if specified
    if (!empty($role_filter)) {
        $params['rolefilter'] = $role_filter;
    }
    
    $users = $DB->get_records_sql($sql, $params);
    
    // Format users for response
    $result = [];
    foreach ($users as $user) {
        $result[] = [
            'id' => $user->id,
            'fullname' => fullname($user),
            'email' => $user->email,
            'username' => $user->username,
            'roles' => $user->roles ? explode(',', $user->roles) : []
        ];
    }
    
    // If no users found with role filter, try without role filter as fallback
    if (count($result) === 0 && !empty($role_filter)) {
        
        // Build SQL without role filter
        $fallback_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username,
                               GROUP_CONCAT(DISTINCT r.shortname) as roles
                        FROM {user} u
                        INNER JOIN {company_users} cu ON cu.userid = u.id
                        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
                        LEFT JOIN {role} r ON r.id = ra.roleid
                        WHERE cu.companyid = :companyid
                        AND u.deleted = 0
                        AND u.suspended = 0
                        AND u.id != :currentuserid";
        
        // Add search conditions only if query is provided
        if (!empty($query)) {
            $fallback_sql .= " AND (
                    " . $DB->sql_like('u.firstname', ':search1', false) . "
                    OR " . $DB->sql_like('u.lastname', ':search2', false) . "
                    OR " . $DB->sql_like('u.email', ':search3', false) . "
                    OR " . $DB->sql_like('u.username', ':search4', false) . "
                    OR " . $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':search5', false) . "
                )";
        }
        
        $fallback_sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username
                          ORDER BY u.firstname, u.lastname";
        $fallback_sql .= $load_all ? " LIMIT 100" : " LIMIT 20";
        
        $fallback_params = [
            'companyid' => $company_id,
            'currentuserid' => $USER->id
        ];
        
        // Add search parameters only if query is provided
        if (!empty($query)) {
            $fallback_params['search1'] = '%' . $DB->sql_like_escape($query) . '%';
            $fallback_params['search2'] = '%' . $DB->sql_like_escape($query) . '%';
            $fallback_params['search3'] = '%' . $DB->sql_like_escape($query) . '%';
            $fallback_params['search4'] = '%' . $DB->sql_like_escape($query) . '%';
            $fallback_params['search5'] = '%' . $DB->sql_like_escape($query) . '%';
        }
        
        $fallback_users = $DB->get_records_sql($fallback_sql, $fallback_params);
        
        // Format fallback users
        $result = [];
        foreach ($fallback_users as $user) {
            $result[] = [
                'id' => $user->id,
                'fullname' => fullname($user),
                'email' => $user->email,
                'username' => $user->username,
                'roles' => $user->roles ? explode(',', $user->roles) : []
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'users' => $result,
        'count' => count($result)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error searching users: ' . $e->getMessage()
    ]);
}

