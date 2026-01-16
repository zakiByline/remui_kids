<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

global $USER;

require_login();
require_sesskey();

$context = context_system::instance();
$action = required_param('action', PARAM_ALPHANUMEXT);
$companyid = optional_param('companyid', 0, PARAM_INT);

// Check permissions - allow both global admins and school managers
$is_admin = has_capability('moodle/site:config', $context);
$is_school_manager = false;
$manager_companyid = 0;

if (!$is_admin) {
    // Check if user is a school manager
    $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
    if ($companymanagerrole) {
        $is_school_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
        
        if ($is_school_manager) {
            // Get the company this manager belongs to
            $company_info = $DB->get_record_sql(
                "SELECT c.id FROM {company} c 
                 JOIN {company_users} cu ON c.id = cu.companyid 
                 WHERE cu.userid = ? AND cu.managertype = 1",
                [$USER->id]
            );
            
            if ($company_info) {
                $manager_companyid = (int)$company_info->id;
            }
        }
    }
}

// Deny access if neither admin nor school manager
if (!$is_admin && !$is_school_manager) {
    throw new moodle_exception('nopermissions', 'error', '', 'access emulator management');
}

// School managers can only manage their own school
if ($is_school_manager && !$is_admin) {
    if ($action === 'grant') {
        // School managers cannot manage grants
        throw new moodle_exception('nopermissions', 'error', '', 'manage emulator grants');
    }
    // Override companyid to their own school
    $companyid = $manager_companyid;
}

$catalog = theme_remui_kids_emulator_catalog();

$sendjson = function(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    die();
};

$ensure_emulator = function(string $slug) use ($catalog) {
    if (!array_key_exists($slug, $catalog)) {
        throw new moodle_exception('invalidrecord', 'error', '', 'emulator');
    }
};

try {
    switch ($action) {
        case 'matrix':
            $matrix = theme_remui_kids_build_emulator_matrix($companyid);
            
            // Also fetch cohorts for the company
            $cohorts = theme_remui_kids_get_company_cohorts($companyid);
            $cohorts_data = [];
            if (is_array($cohorts)) {
                error_log("ajax/emulator_access.php: Found " . count($cohorts) . " cohorts for companyid=$companyid");
                $cohorts_data = array_map(function($cohort) {
                    return [
                        'id' => (int)$cohort->id,
                        'name' => format_string($cohort->name),
                        'members' => (int)$cohort->members,
                    ];
                }, $cohorts);
            } else {
                error_log("ajax/emulator_access.php: Cohorts is not an array for companyid=$companyid");
            }
            
            $sendjson([
                'success' => true,
                'data' => $matrix,
                'cohorts' => $cohorts_data,
            ]);
            break;

        case 'update':
            $emulator = required_param('emulator', PARAM_ALPHANUMEXT);
            $scope = required_param('scope', PARAM_ALPHA);
            $scopeid = required_param('scopeid', PARAM_INT);
            $field = required_param('field', PARAM_ALPHA);
            // Accept both 'value' and 'enabled' parameters for compatibility
            $value_param = optional_param('value', null, PARAM_INT);
            if ($value_param === null) {
                $value_param = optional_param('enabled', 0, PARAM_INT);
            }
            $value = (bool)$value_param; // Convert to boolean

            error_log("emulator_access.php update: emulator=$emulator, scope=$scope, scopeid=$scopeid, field=$field, value_param=$value_param, value=" . ($value ? '1' : '0'));

            $ensure_emulator($emulator);
            if (!in_array($scope, [THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY, THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT], true)) {
                error_log("emulator_access.php update: Invalid scope=$scope");
                throw new moodle_exception('invalidparameter', 'error', '', 'scope');
            }
            if (!in_array($field, ['teachers', 'students'], true)) {
                error_log("emulator_access.php update: Invalid field=$field");
                throw new moodle_exception('invalidparameter', 'error', '', 'field');
            }

            // For cohort scope, verify the cohort belongs to the company being managed
            if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT && $companyid > 0) {
                $cohorts = theme_remui_kids_get_company_cohorts($companyid);
                $cohortids = array_map(function($cohort) {
                    return (int)$cohort->id;
                }, $cohorts);
                
                if (!in_array($scopeid, $cohortids, true)) {
                    error_log("emulator_access.php update: Cohort $scopeid does not belong to company $companyid");
                    throw new moodle_exception('invalidparameter', 'error', '', 'Cohort does not belong to this school');
                }
            }

            try {
                $result = theme_remui_kids_update_emulator_access($emulator, $scope, $scopeid, $field, $value ? 1 : 0, $USER->id, $companyid);
                error_log("emulator_access.php update: Success. Record ID=" . ($result ? $result->id : 'null'));
                
                // Verify the record was actually saved
                global $DB;
                $verify_companyid = ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY) ? $scopeid : $companyid;
                $verify = $DB->get_record('theme_remui_kids_emulator_access', [
                    'emulator' => $emulator,
                    'scope' => $scope,
                    'scopeid' => $scopeid,
                    'companyid' => $verify_companyid,
                ]);
                if ($verify) {
                    $column = $field === 'teachers' ? 'allowteachers' : 'allowstudents';
                    error_log("emulator_access.php update: Verified saved value - " . $column . " = " . $verify->$column);
                } else {
                    error_log("emulator_access.php update: WARNING - Record not found after save!");
                }
            } catch (Exception $e) {
                error_log("emulator_access.php update: Exception - " . $e->getMessage());
                error_log("emulator_access.php update: Exception trace - " . $e->getTraceAsString());
                throw $e;
            }

            $sendjson([
                'success' => true,
                'data' => theme_remui_kids_build_emulator_matrix($companyid),
            ]);
            break;

        case 'reset':
            $emulator = required_param('emulator', PARAM_ALPHANUMEXT);
            $scope = required_param('scope', PARAM_ALPHA);
            $scopeid = required_param('scopeid', PARAM_INT);
            $field = optional_param('field', null, PARAM_ALPHA);

            $ensure_emulator($emulator);
            if (!in_array($scope, [THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY, THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT], true)) {
                throw new moodle_exception('invalidparameter', 'error', '', 'scope');
            }
            if ($field !== null && !in_array($field, ['teachers', 'students'], true)) {
                throw new moodle_exception('invalidparameter', 'error', '', 'field');
            }

            theme_remui_kids_reset_emulator_access($emulator, $scope, $scopeid, $field, $USER->id, $companyid);

            $sendjson([
                'success' => true,
                'data' => theme_remui_kids_build_emulator_matrix($companyid),
            ]);
            break;

        case 'grant':
            // Only admins can manage grants
            if (!$is_admin) {
                throw new moodle_exception('nopermissions', 'error', '', 'manage emulator grants');
            }

            $emulator = required_param('emulator', PARAM_ALPHANUMEXT);
            $grantcompanyid = required_param('companyid', PARAM_INT);
            $granted = required_param('granted', PARAM_BOOL);

            $ensure_emulator($emulator);
            
            if ($grantcompanyid === 0) {
                throw new moodle_exception('invalidparameter', 'error', '', 'Cannot set grants for global scope');
            }

            theme_remui_kids_update_emulator_school_grant($emulator, $grantcompanyid, $granted, $USER->id);

            $sendjson([
                'success' => true,
                'data' => theme_remui_kids_build_school_grant_matrix(),
            ]);
            break;

        default:
            throw new moodle_exception('invalidparameter', 'error', '', 'action');
    }
} catch (Exception $e) {
    $sendjson([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

