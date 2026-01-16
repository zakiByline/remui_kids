<?php
/**
 * Search Cohorts for Enrollment
 * Returns cohorts available for the specific company/school
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
    
    // Log request
    error_log("Search Cohorts - Query: '$query', Company ID: $company_id");
    
    // Validate company_id
    if (empty($company_id)) {
        error_log("Search Cohorts Error: No company ID provided");
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
    
    // Get cohorts that have members from this specific school/company only
    $sql = "SELECT DISTINCT c.id, c.name, c.idnumber, c.description,
                   (SELECT COUNT(DISTINCT cm.userid) 
                    FROM {cohort_members} cm 
                    INNER JOIN {company_users} cu ON cm.userid = cu.userid
                    WHERE cm.cohortid = c.id 
                    AND cu.companyid = :companyid) as member_count
            FROM {cohort} c
            INNER JOIN {cohort_members} cm ON cm.cohortid = c.id
            INNER JOIN {company_users} cu ON cu.userid = cm.userid
            WHERE c.visible = 1
            AND cu.companyid = :companyid2";
    
    $params = [
        'companyid' => $company_id,
        'companyid2' => $company_id
    ];
    
    // Add search filter if query provided
    if (!empty($query) && strlen($query) >= 2) {
        $search_pattern = '%' . $DB->sql_like_escape($query) . '%';
        $sql .= " AND (" . $DB->sql_like('c.name', ':search1', false) . "
                  OR " . $DB->sql_like('c.idnumber', ':search2', false) . ")";
        $params['search1'] = $search_pattern;
        $params['search2'] = $search_pattern;
    }
    
    $sql .= " ORDER BY c.name LIMIT 50";
    
    error_log("Search Cohorts SQL: $sql");
    error_log("Search Cohorts Params: " . print_r($params, true));
    $cohorts = $DB->get_records_sql($sql, $params);
    error_log("Search Cohorts Final Result: Found " . count($cohorts) . " school-specific cohorts");
    
    // Format cohorts for response
    $result = [];
    foreach ($cohorts as $cohort) {
        $result[] = [
            'id' => $cohort->id,
            'name' => $cohort->name,
            'idnumber' => $cohort->idnumber ?? '',
            'description' => strip_tags($cohort->description ?? ''),
            'member_count' => (int)$cohort->member_count
        ];
    }
    
    echo json_encode([
        'success' => true,
        'cohorts' => $result,
        'count' => count($result)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error searching cohorts: ' . $e->getMessage()
    ]);
}