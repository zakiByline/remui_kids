<?php
/**
 * Teacher Settings Helper Functions
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/ddllib.php');

/**
 * Check if Quick Navigation is enabled for a teacher based on their school
 *
 * @param int $userid The user ID of the teacher
 * @return bool True if enabled, false otherwise
 */
function theme_remui_kids_is_quick_navigation_enabled($userid) {
    global $DB;
    
    try {
        // Get the teacher's school/company ID
        $schoolid = $DB->get_field_sql(
            "SELECT cu.companyid 
             FROM {company_users} cu 
             WHERE cu.userid = ? 
             LIMIT 1",
            [$userid]
        );
        
        if (!$schoolid) {
            // Not assigned to any school, return true (enabled by default)
            return true;
        }
        
        // Check if school settings table exists
        $dbman = $DB->get_manager();
        $table = new xmldb_table('theme_remui_school_settings');
        
        if (!$dbman->table_exists($table)) {
            // Table doesn't exist yet, return true (enabled by default)
            return true;
        }
        
        // Get the setting for this school
        $setting = $DB->get_record('theme_remui_school_settings', ['schoolid' => $schoolid]);
        
        if ($setting) {
            return (bool)$setting->quick_navigation_enabled;
        }
        
        // No setting found, return true (enabled by default)
        return true;
        
    } catch (Exception $e) {
        // On any error, return true (enabled by default)
        return true;
    }
}

/**
 * Set Quick Navigation status for a school
 *
 * @param int $schoolid The school/company ID
 * @param bool $enabled True to enable, false to disable
 * @return bool True on success, false on failure
 */
function theme_remui_kids_set_school_quick_navigation($schoolid, $enabled) {
    global $DB;
    
    try {
        // Create table if it doesn't exist
        $dbman = $DB->get_manager();
        $table = new xmldb_table('theme_remui_school_settings');
        
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('schoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quick_navigation_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('schoolid', XMLDB_KEY_UNIQUE, ['schoolid']);
            $dbman->create_table($table);
        }
        
        // Check if record exists
        $existing = $DB->get_record('theme_remui_school_settings', ['schoolid' => $schoolid]);
        
        if ($existing) {
            $existing->quick_navigation_enabled = $enabled ? 1 : 0;
            $existing->timemodified = time();
            return $DB->update_record('theme_remui_school_settings', $existing);
        } else {
            $record = new stdClass();
            $record->schoolid = $schoolid;
            $record->quick_navigation_enabled = $enabled ? 1 : 0;
            $record->timecreated = time();
            $record->timemodified = time();
            return $DB->insert_record('theme_remui_school_settings', $record);
        }
        
    } catch (Exception $e) {
        return false;
    }
}

