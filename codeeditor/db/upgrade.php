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

/**
 * Code Editor module upgrade code
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute codeeditor upgrade from the given old version
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_codeeditor_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025101400) {
        upgrade_mod_savepoint(true, 2025101400, 'codeeditor');
    }

    // Add grading and assessment features - Version 2025102900
    if ($oldversion < 2025102900) {
        
        // Add grading fields to codeeditor table
        $table = new xmldb_table('codeeditor');
        
        // Add grade field
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'descriptionformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add duedate field
        $field = new xmldb_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add cutoffdate field
        $field = new xmldb_field('cutoffdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duedate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add allowsubmissionsfromdate field
        $field = new xmldb_field('allowsubmissionsfromdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cutoffdate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add requiresubmit field
        $field = new xmldb_field('requiresubmit', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'allowsubmissionsfromdate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add blindmarking field (anonymous submissions)
        $field = new xmldb_field('blindmarking', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'requiresubmit');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add hidegrader field
        $field = new xmldb_field('hidegrader', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'blindmarking');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add markingworkflow field
        $field = new xmldb_field('markingworkflow', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'hidegrader');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add markingallocation field
        $field = new xmldb_field('markingallocation', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'markingworkflow');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Update codeeditor_submissions table with grading fields
        $table = new xmldb_table('codeeditor_submissions');
        
        // Modify status field to have proper default
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft', 'output');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }
        
        // Add grade field to submissions
        $field = new xmldb_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add grader field
        $field = new xmldb_field('grader', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add feedbacktext field
        $field = new xmldb_field('feedbacktext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'grader');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add feedbackformat field
        $field = new xmldb_field('feedbackformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'feedbacktext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add attemptnumber field
        $field = new xmldb_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'feedbackformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add latest field
        $field = new xmldb_field('latest', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'attemptnumber');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add timemodified field if doesn't exist
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add timegraded field
        $field = new xmldb_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add key for grader
        $key = new xmldb_key('grader', XMLDB_KEY_FOREIGN, array('grader'), 'user', array('id'));
        $dbman->add_key($table, $key);
        
        // Add indexes
        $index = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, array('status'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $index = new xmldb_index('latest', XMLDB_INDEX_NOTUNIQUE, array('latest'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Codeeditor savepoint reached.
        upgrade_mod_savepoint(true, 2025102900, 'codeeditor');
    }
    
    // Add advanced grading options - Version 2025102901
    if ($oldversion < 2025102901) {
        
        $table = new xmldb_table('codeeditor');
        
        // Add blindmarking field (anonymous submissions)
        $field = new xmldb_field('blindmarking', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'requiresubmit');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add hidegrader field
        $field = new xmldb_field('hidegrader', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'blindmarking');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add markingworkflow field
        $field = new xmldb_field('markingworkflow', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'hidegrader');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add markingallocation field
        $field = new xmldb_field('markingallocation', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'markingworkflow');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Codeeditor savepoint reached.
        upgrade_mod_savepoint(true, 2025102901, 'codeeditor');
    }
    
    // Register grading areas for rubrics - Version 2025102902
    if ($oldversion < 2025102902) {
        // No database changes needed - just registering the grading area callback
        // The grading_areas_list() function in lib.php will be called automatically
        
        // Purge grading cache to ensure new grading areas are recognized
        cache_helper::purge_by_event('changesingradingform');
        
        // Codeeditor savepoint reached.
        upgrade_mod_savepoint(true, 2025102902, 'codeeditor');
    }

    return true;
}
