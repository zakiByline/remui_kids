<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

global $USER, $DB;

require_login();
require_sesskey();

$context = context_system::instance();

// Check if user is global admin or school manager
$is_admin = has_capability('moodle/site:config', $context);
$is_school_manager = false;
$manager_companyid = 0;

if (!$is_admin) {
    $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
    if ($companymanagerrole) {
        $is_school_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
        
        if ($is_school_manager) {
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

if (!$is_admin && !$is_school_manager) {
    throw new moodle_exception('nopermissions', 'error', '', 'manage teacher emulator access');
}

$action = required_param('action', PARAM_ALPHANUMEXT);

// Debug: Log all incoming parameters
error_log("teacher_emulator_access.php: action=$action, is_admin=" . ($is_admin ? '1' : '0') . ", manager_companyid=$manager_companyid");
error_log("POST params: " . print_r($_POST, true));

$sendjson = function(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    die();
};

try {
    switch ($action) {
        case 'get_teachers':
            $emulator = required_param('emulator', PARAM_ALPHANUMEXT);
            
            // For admins, companyid is required. For school managers, use their companyid
            if ($is_admin) {
                $target_companyid = required_param('companyid', PARAM_INT);
                if ($target_companyid <= 0) {
                    error_log("get_teachers error: Admin provided invalid companyid=$target_companyid");
                    throw new moodle_exception('invalidparameter', 'error', '', 'companyid must be > 0');
                }
                $companyid = $target_companyid;
            } else {
                if ($manager_companyid <= 0) {
                    error_log("get_teachers error: School manager has no companyid");
                    throw new moodle_exception('invalidparameter', 'error', '', 'manager companyid');
                }
                $companyid = $manager_companyid;
            }
            
            $teachers = theme_remui_kids_get_school_teachers($companyid);
            $access = theme_remui_kids_get_teacher_emulator_access($emulator, $companyid);
            
            // Debug: Log teacher count (remove in production)
            error_log("get_teachers: companyid=$companyid, found " . count($teachers) . " teachers");
            
            $teacherlist = [];
            foreach ($teachers as $teacher) {
                $teacherlist[] = [
                    'id' => (int)$teacher->id,
                    'firstname' => $teacher->firstname,
                    'lastname' => $teacher->lastname,
                    'fullname' => fullname($teacher),
                    'email' => $teacher->email,
                    'allowed' => isset($access[$teacher->id]) ? $access[$teacher->id] : false, // Default DISABLED - must explicitly grant
                ];
            }
            
            $sendjson([
                'success' => true,
                'teachers' => $teacherlist,
                'debug' => [
                    'companyid' => $companyid,
                    'teacher_count' => count($teacherlist),
                    'emulator' => $emulator
                ]
            ]);
            break;

        case 'update_teacher_access':
            $teacherid = required_param('teacherid', PARAM_INT);
            $emulator = required_param('emulator', PARAM_ALPHANUMEXT);
            $allowed = required_param('allowed', PARAM_BOOL);
            $target_companyid = optional_param('companyid', 0, PARAM_INT);
            
            // Use provided companyid for admins, or manager's companyid for school managers
            $companyid = ($is_admin && $target_companyid > 0) ? $target_companyid : $manager_companyid;
            
            if ($companyid <= 0) {
                throw new moodle_exception('invalidparameter', 'error', '', 'companyid');
            }
            
            theme_remui_kids_update_teacher_emulator_access(
                $teacherid,
                $companyid,
                $emulator,
                $allowed,
                $USER->id
            );
            
            $sendjson([
                'success' => true,
                'message' => 'Teacher access updated',
            ]);
            break;

        default:
            throw new moodle_exception('invalidparameter', 'error', '', 'action');
    }
} catch (Exception $e) {
    $errorcode = $e->getCode();
    $errormessage = $e->getMessage();
    $a = $e->a ?? null;
    
    // For moodle_exception, get a more user-friendly message
    if ($e instanceof moodle_exception) {
        $errormessage = $e->errorcode . ($a ? ' ' . json_encode($a) : '');
    }
    
    error_log("teacher_emulator_access.php error: " . $errormessage . " (code: $errorcode)");
    
    // Get companyid from POST if available
    $post_companyid = isset($_POST['companyid']) ? (int)$_POST['companyid'] : 0;
    
    $sendjson([
        'success' => false,
        'message' => $errormessage,
        'errorcode' => $errorcode,
        'debug' => [
            'is_admin' => isset($is_admin) ? $is_admin : false,
            'is_school_manager' => isset($is_school_manager) ? $is_school_manager : false,
            'manager_companyid' => isset($manager_companyid) ? $manager_companyid : 0,
            'post_companyid' => $post_companyid,
            'companyid' => $post_companyid, // Also include as companyid for consistency
            'post_params' => $_POST ?? []
        ]
    ]);
}

