<?php
/**
 * Debug script to check student emulator access
 * Usage: Access via browser with ?userid=XXX&emulator=remix
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

require_login();

// Only allow admins
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context)) {
    die('Access denied');
}

$userid = optional_param('userid', 0, PARAM_INT);
$emulator = optional_param('emulator', 'remix', PARAM_ALPHANUMEXT);

if (!$userid) {
    die('Please provide ?userid=XXX');
}

global $DB, $USER;

echo "<h2>Debug Student Emulator Access</h2>";
echo "<p>User ID: $userid</p>";
echo "<p>Emulator: $emulator</p>";

// Get user info
$user = $DB->get_record('user', ['id' => $userid]);
if (!$user) {
    die("User not found");
}
echo "<h3>User Info</h3>";
echo "<p>Name: {$user->firstname} {$user->lastname}</p>";
echo "<p>Email: {$user->email}</p>";

// Get user's companies
$user_companyids = theme_remui_kids_get_user_company_ids($userid);
echo "<h3>User Company IDs</h3>";
echo "<pre>" . print_r($user_companyids, true) . "</pre>";

// Get user's cohorts
$user_cohortids = theme_remui_kids_get_user_cohort_ids($userid);
echo "<h3>User Cohort IDs</h3>";
echo "<pre>" . print_r($user_cohortids, true) . "</pre>";

// Get cohort details
if (!empty($user_cohortids)) {
    list($cohortinsql, $cohortparams) = $DB->get_in_or_equal($user_cohortids, SQL_PARAMS_NAMED, 'cid');
    $cohorts = $DB->get_records_sql("SELECT * FROM {cohort} WHERE id $cohortinsql", $cohortparams);
    echo "<h3>Cohort Details</h3>";
    echo "<pre>" . print_r($cohorts, true) . "</pre>";
}

// Check if companyid column exists
$has_companyid = theme_remui_kids_has_companyid_column();
echo "<h3>Database Schema</h3>";
echo "<p>companyid column exists: " . ($has_companyid ? 'YES' : 'NO') . "</p>";

// Get access records for cohorts
if (!empty($user_cohortids) && !empty($user_companyids)) {
    $filtered_companyids = array_filter($user_companyids, function($id) {
        return $id > 0;
    });
    
    if (!empty($filtered_companyids)) {
        list($cohortinsql, $cohortparams) = $DB->get_in_or_equal($user_cohortids, SQL_PARAMS_NAMED, 'cid');
        list($compinsql, $compparams) = $DB->get_in_or_equal($filtered_companyids, SQL_PARAMS_NAMED, 'comp');
        
        $params = array_merge($cohortparams, $compparams);
        $params['emulator'] = $emulator;
        $params['scope'] = THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT;
        
        if ($has_companyid) {
            $sql = "SELECT ea.*
                    FROM {theme_remui_kids_emulator_access} ea
                    WHERE ea.emulator = :emulator 
                      AND ea.scope = :scope 
                      AND ea.scopeid $cohortinsql
                      AND ea.companyid $compinsql";
        } else {
            $sql = "SELECT ea.*
                    FROM {theme_remui_kids_emulator_access} ea
                    INNER JOIN {company_users} cu ON cu.userid = ea.createdby
                    WHERE ea.emulator = :emulator 
                      AND ea.scope = :scope 
                      AND ea.scopeid $cohortinsql
                      AND cu.companyid $compinsql";
        }
        
        echo "<h3>Access Records Query</h3>";
        echo "<pre>SQL: " . $sql . "</pre>";
        echo "<pre>Params: " . print_r($params, true) . "</pre>";
        
        $records = $DB->get_records_sql($sql, $params);
        echo "<h3>Access Records Found</h3>";
        echo "<p>Count: " . count($records) . "</p>";
        echo "<pre>" . print_r($records, true) . "</pre>";
        
        // Check reduce_records
        $decision = theme_remui_kids_reduce_records($records, 'allowstudents');
        echo "<h3>Access Decision</h3>";
        echo "<p>Decision: " . ($decision === null ? 'null (no records)' : ($decision ? 'ALLOWED' : 'DENIED')) . "</p>";
    }
}

// Test the actual function
echo "<h3>Function Result</h3>";
$has_access = theme_remui_kids_user_has_emulator_access($userid, $emulator, 'student');
echo "<p>theme_remui_kids_user_has_emulator_access(): " . ($has_access ? 'ALLOWED' : 'DENIED') . "</p>";

// Check quick actions
$quick_actions = theme_remui_kids_get_emulator_quick_actions($userid, 'student');
echo "<h3>Quick Actions</h3>";
echo "<p>Count: " . count($quick_actions) . "</p>";
$emulator_slugs = array_column($quick_actions, 'slug');
echo "<p>Emulators: " . implode(', ', $emulator_slugs) . "</p>";

