<?php
/**
 * Cohort Sidebar Access Helper Functions
 *
 * This file provides helper functions to manage and check cohort-based
 * sidebar access permissions for students.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get cohort sidebar access settings
 *
 * @param int $cohortid The cohort ID
 * @return object|false Settings object or false if not found
 */
function theme_remui_kids_get_cohort_sidebar_settings($cohortid) {
    global $DB;
    
    if (empty($cohortid)) {
        return false;
    }
    
    $settings = $DB->get_record('theme_remui_kids_cohort_sidebar', ['cohortid' => $cohortid]);
    
    // If no settings exist, return default (both enabled)
    if (!$settings) {
        return (object)[
            'cohortid' => $cohortid,
            'scratch_editor_enabled' => 1,
            'code_editor_enabled' => 1
        ];
    }
    
    return $settings;
}

/**
 * Save or update cohort sidebar access settings
 *
 * @param int $cohortid The cohort ID
 * @param int $scratch_editor_enabled 1 if enabled, 0 if disabled
 * @param int $code_editor_enabled 1 if enabled, 0 if disabled
 * @return bool Success status
 */
function theme_remui_kids_save_cohort_sidebar_settings($cohortid, $scratch_editor_enabled, $code_editor_enabled) {
    global $DB;
    
    if (empty($cohortid)) {
        return false;
    }
    
    $existing = $DB->get_record('theme_remui_kids_cohort_sidebar', ['cohortid' => $cohortid]);
    
    $data = (object)[
        'cohortid' => $cohortid,
        'scratch_editor_enabled' => $scratch_editor_enabled ? 1 : 0,
        'code_editor_enabled' => $code_editor_enabled ? 1 : 0,
        'timemodified' => time()
    ];
    
    if ($existing) {
        $data->id = $existing->id;
        return $DB->update_record('theme_remui_kids_cohort_sidebar', $data);
    } else {
        $data->timecreated = time();
        return $DB->insert_record('theme_remui_kids_cohort_sidebar', $data);
    }
}

/**
 * Check if a user has access to scratch editor based on their cohort
 *
 * @param int $userid The user ID (optional, defaults to current user)
 * @return bool True if access is enabled, false otherwise
 */
function theme_remui_kids_user_has_scratch_editor_access($userid = null) {
    global $USER, $DB;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    // Get user's cohorts
    $cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$userid]
    );
    
    if (empty($cohorts)) {
        // No cohort assigned, default to enabled
        return true;
    }
    
    $cohortids = array_keys($cohorts);
    list($insql, $params) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'cohort');
    
    // Get all explicit settings for user's cohorts
    $explicit_settings = $DB->get_records_sql(
        "SELECT cohortid, scratch_editor_enabled 
         FROM {theme_remui_kids_cohort_sidebar} 
         WHERE cohortid $insql",
        $params
    );
    
    // Build a map of cohort IDs that have explicit settings
    $cohorts_with_explicit = [];
    foreach ($explicit_settings as $setting) {
        $cohorts_with_explicit[$setting->cohortid] = (bool)$setting->scratch_editor_enabled;
    }
    
    // Check each cohort
    $has_enabled = false;
    $has_disabled = false;
    $has_no_setting = false;
    
    foreach ($cohortids as $cohortid) {
        if (isset($cohorts_with_explicit[$cohortid])) {
            // Explicit setting exists
            if ($cohorts_with_explicit[$cohortid]) {
                $has_enabled = true;
            } else {
                $has_disabled = true;
            }
        } else {
            // No explicit setting - defaults to enabled
            $has_no_setting = true;
        }
    }
    
    // If any cohort has it explicitly disabled, deny access (strict policy)
    if ($has_disabled) {
        return false;
    }
    
    // If any cohort has it enabled or has no setting (default enabled), allow access
    if ($has_enabled || $has_no_setting) {
        return true;
    }
    
    // Fallback: deny access
    return false;
}

/**
 * Check if a user has access to code editor based on their cohort
 *
 * @param int $userid The user ID (optional, defaults to current user)
 * @return bool True if access is enabled, false otherwise
 */
function theme_remui_kids_user_has_code_editor_access($userid = null) {
    global $USER, $DB;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    // Get user's cohorts
    $cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$userid]
    );
    
    if (empty($cohorts)) {
        // No cohort assigned, default to enabled
        return true;
    }
    
    $cohortids = array_keys($cohorts);
    list($insql, $params) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'cohort');
    
    // Get all explicit settings for user's cohorts
    $explicit_settings = $DB->get_records_sql(
        "SELECT cohortid, code_editor_enabled 
         FROM {theme_remui_kids_cohort_sidebar} 
         WHERE cohortid $insql",
        $params
    );
    
    // Build a map of cohort IDs that have explicit settings
    $cohorts_with_explicit = [];
    foreach ($explicit_settings as $setting) {
        $cohorts_with_explicit[$setting->cohortid] = (bool)$setting->code_editor_enabled;
    }
    
    // Check each cohort
    $has_enabled = false;
    $has_disabled = false;
    $has_no_setting = false;
    
    foreach ($cohortids as $cohortid) {
        if (isset($cohorts_with_explicit[$cohortid])) {
            // Explicit setting exists
            if ($cohorts_with_explicit[$cohortid]) {
                $has_enabled = true;
            } else {
                $has_disabled = true;
            }
        } else {
            // No explicit setting - defaults to enabled
            $has_no_setting = true;
        }
    }
    
    // If any cohort has it explicitly disabled, deny access (strict policy)
    if ($has_disabled) {
        return false;
    }
    
    // If any cohort has it enabled or has no setting (default enabled), allow access
    if ($has_enabled || $has_no_setting) {
        return true;
    }
    
    // Fallback: deny access
    return false;
}

/**
 * Get all cohorts with their sidebar settings
 *
 * @return array Array of cohort objects with sidebar settings
 */
function theme_remui_kids_get_all_cohorts_with_sidebar_settings() {
    global $DB;
    
    $cohorts = $DB->get_records('cohort', null, 'name ASC');
    
    $result = [];
    foreach ($cohorts as $cohort) {
        $settings = theme_remui_kids_get_cohort_sidebar_settings($cohort->id);
        $result[] = (object)[
            'id' => $cohort->id,
            'name' => $cohort->name,
            'idnumber' => $cohort->idnumber,
            'scratch_editor_enabled' => $settings->scratch_editor_enabled,
            'code_editor_enabled' => $settings->code_editor_enabled
        ];
    }
    
    return $result;
}

