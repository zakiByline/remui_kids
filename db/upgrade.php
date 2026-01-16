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
 * Upgrade steps for the RemUI Kids theme.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/ddllib.php');

/**
 * Executes upgrade tasks between versions.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_theme_remui_kids_upgrade(int $oldversion): bool {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025120402) {
        // Define tables to be created for support videos system
        
        // Define table theme_remui_kids_support_videos
        $table = new xmldb_table('theme_remui_kids_support_videos');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('category', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('subcategory', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('videotype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'upload');
            $table->add_field('videourl', XMLDB_TYPE_CHAR, '500', null, null, null, null);
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('filepath', XMLDB_TYPE_CHAR, '500', null, null, null, null);
            $table->add_field('filesize', XMLDB_TYPE_INTEGER, '20', null, null, null, null);
            $table->add_field('duration', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('thumbnail', XMLDB_TYPE_CHAR, '500', null, null, null, null);
            $table->add_field('captionfile', XMLDB_TYPE_CHAR, '500', null, null, null, null);
            $table->add_field('targetrole', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'all');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('views', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('uploadedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uploadedby', XMLDB_KEY_FOREIGN, ['uploadedby'], 'user', ['id']);
            $table->add_index('category', XMLDB_INDEX_NOTUNIQUE, ['category']);
            $table->add_index('targetrole', XMLDB_INDEX_NOTUNIQUE, ['targetrole']);
            $table->add_index('visible', XMLDB_INDEX_NOTUNIQUE, ['visible']);
            $table->add_index('sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);
            $table->add_index('category_sortorder', XMLDB_INDEX_NOTUNIQUE, ['category', 'sortorder']);

            $dbman->create_table($table);
        }

        // Define table theme_remui_kids_video_views
        $table = new xmldb_table('theme_remui_kids_video_views');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('videoid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('watchtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lastposition', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('videoid', XMLDB_KEY_FOREIGN, ['videoid'], 'theme_remui_kids_support_videos', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('videoid_userid', XMLDB_INDEX_NOTUNIQUE, ['videoid', 'userid']);
            $table->add_index('timeviewed', XMLDB_INDEX_NOTUNIQUE, ['timeviewed']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120402, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025111700) {
        $table = new xmldb_table('theme_remui_kids_lessonplans');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lessonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('grade', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('coursename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('lessonname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('planjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('lessonid_idx', XMLDB_INDEX_NOTUNIQUE, ['lessonid']);
            $table->add_index('grade_idx', XMLDB_INDEX_NOTUNIQUE, ['grade']);
            $table->add_index('lesson_idx', XMLDB_INDEX_NOTUNIQUE, ['lessonname']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111700, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025111800) {
        // Create table for cohort sidebar access settings.
        $table = new xmldb_table('theme_remui_kids_cohort_sidebar');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('cohortid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scratch_editor_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('code_editor_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cohort_idx', XMLDB_INDEX_UNIQUE, ['cohortid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111800, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025111900) {
        // Create table for classroom events/evidence for competencies.
        $table = new xmldb_table('theme_remui_kids_classroom_events');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('eventtitle', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('eventdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('competencyid_idx', XMLDB_INDEX_NOTUNIQUE, ['competencyid']);
            $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('eventdate_idx', XMLDB_INDEX_NOTUNIQUE, ['eventdate']);
            $table->add_index('createdby_idx', XMLDB_INDEX_NOTUNIQUE, ['createdby']);

            $dbman->create_table($table);
        }

        // Theme savepoint reached
        upgrade_plugin_savepoint(true, 2025111900, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025120405) {
        // Create table for help/support tickets.
        $table = new xmldb_table('theme_remui_kids_helptickets');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('ticketnumber', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('category', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'general');
            $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'normal');
            $table->add_field('assignedto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastmessageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ticketnumber_idx', XMLDB_INDEX_UNIQUE, ['ticketnumber']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('assignedto_idx', XMLDB_INDEX_NOTUNIQUE, ['assignedto']);
            $table->add_index('category_idx', XMLDB_INDEX_NOTUNIQUE, ['category']);

            $dbman->create_table($table);
        }

        // Create table for help ticket messages.
        $table = new xmldb_table('theme_remui_kids_helpticket_msgs');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('messageformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('isadmin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('isinternal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hasattachments', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ticketid_idx', XMLDB_INDEX_NOTUNIQUE, ['ticketid']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);

            $dbman->create_table($table);
        }

        // Create table for help ticket file attachments.
        $table = new xmldb_table('theme_remui_kids_helpticket_files');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('messageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '/');
            $table->add_field('mimetype', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('filehash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('messageid_idx', XMLDB_INDEX_NOTUNIQUE, ['messageid']);
            $table->add_index('ticketid_idx', XMLDB_INDEX_NOTUNIQUE, ['ticketid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120405, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025120501) {
        // Create table for emulator access control.
        $table = new xmldb_table('theme_remui_kids_emulator_access');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('emulator', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('allowteachers', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
            $table->add_field('allowstudents', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('emulator_scope_scopeid_idx', XMLDB_INDEX_UNIQUE, ['emulator', 'scope', 'scopeid']);
            $table->add_index('emulator_idx', XMLDB_INDEX_NOTUNIQUE, ['emulator']);
            $table->add_index('scope_idx', XMLDB_INDEX_NOTUNIQUE, ['scope']);
            $table->add_index('scopeid_idx', XMLDB_INDEX_NOTUNIQUE, ['scopeid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120501, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025120502) {
        // Create table for school-level emulator grants.
        $table = new xmldb_table('theme_remui_kids_emulator_school_grants');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('emulator', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('granted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('emulator_companyid_idx', XMLDB_INDEX_UNIQUE, ['emulator', 'companyid']);
            $table->add_index('emulator_idx', XMLDB_INDEX_NOTUNIQUE, ['emulator']);
            $table->add_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120502, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025120503) {
        // Create table for individual teacher emulator access.
        $table = new xmldb_table('theme_remui_kids_teacher_emulator');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('emulator', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('allowed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('teacher_emulator_unique', XMLDB_INDEX_UNIQUE, ['teacherid', 'companyid', 'emulator']);
            $table->add_index('teacherid_idx', XMLDB_INDEX_NOTUNIQUE, ['teacherid']);
            $table->add_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);
            $table->add_index('emulator_idx', XMLDB_INDEX_NOTUNIQUE, ['emulator']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120503, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025120504) {
        // Add companyid field to emulator_access table to support per-school cohort access
        $table = new xmldb_table('theme_remui_kids_emulator_access');
        
        // Add companyid field
        $field = new xmldb_field('companyid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'scopeid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Drop old unique index
        $index = new xmldb_index('uniq_scope', XMLDB_INDEX_UNIQUE, ['emulator', 'scope', 'scopeid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        
        // Add new unique index with companyid
        $index = new xmldb_index('uniq_scope_company', XMLDB_INDEX_UNIQUE, ['emulator', 'scope', 'scopeid', 'companyid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Add companyid index for faster lookups
        $index = new xmldb_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // For existing records, set companyid based on createdby user's company
        // For company scope, companyid = scopeid
        // For cohort scope, companyid = company of the user who created the record
        $sql = "UPDATE {theme_remui_kids_emulator_access} ea
                SET companyid = CASE 
                    WHEN ea.scope = 'company' THEN ea.scopeid
                    WHEN ea.scope = 'cohort' THEN COALESCE(
                        (SELECT cu.companyid 
                         FROM {company_users} cu 
                         WHERE cu.userid = ea.createdby 
                         LIMIT 1), 0)
                    ELSE 0
                END
                WHERE ea.companyid = 0 OR ea.companyid IS NULL";
        $DB->execute($sql);
        
        upgrade_plugin_savepoint(true, 2025120504, 'theme', 'remui_kids');
    }

    if ($oldversion < 2025121718) {
        // Install calendar tables from install.xml
        // These tables are defined in install.xml but need to be created for existing installations
        $installxmlpath = $CFG->dirroot . '/theme/remui_kids/db/install.xml';
        
        if (file_exists($installxmlpath)) {
            // Load the XMLDB file
            $xmldb_file = new xmldb_file($installxmlpath);
            if ($xmldb_file->loadXMLStructure()) {
                $structure = $xmldb_file->getStructure();
                $tables = $structure->getTables();
                
                // Only install calendar-related tables
                $calendar_tables = [
                    'theme_remui_kids_calendar_events',
                    'theme_remui_kids_calendar_event_participants',
                    'theme_remui_kids_lecture_schedules',
                    'theme_remui_kids_lecture_sessions',
                    'theme_remui_kids_lecture_notifications'
                ];
                
                foreach ($tables as $table) {
                    $tablename = $table->getName();
                    if (in_array($tablename, $calendar_tables)) {
                        if (!$dbman->table_exists($table)) {
                            $dbman->create_table($table);
                        }
                    }
                }
            } else {
                // Fallback: Create tables manually if XML parsing fails
                error_log("Warning: Could not parse install.xml, creating calendar tables manually");
                
                // Create theme_remui_kids_calendar_events table
                $table = new xmldb_table('theme_remui_kids_calendar_events');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
                    $table->add_field('eventdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('starttime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('endtime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('eventtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'meeting');
                    $table->add_field('color', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'blue');
                    $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);
                    $table->add_index('eventdate_idx', XMLDB_INDEX_NOTUNIQUE, ['eventdate']);
                    $table->add_index('eventtype_idx', XMLDB_INDEX_NOTUNIQUE, ['eventtype']);
                    $table->add_index('createdby_idx', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
                    
                    $dbman->create_table($table);
                }
                
                // Create theme_remui_kids_calendar_event_participants table
                $table = new xmldb_table('theme_remui_kids_calendar_event_participants');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('participanttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('participantid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('eventid_idx', XMLDB_INDEX_NOTUNIQUE, ['eventid']);
                    $table->add_index('participant_idx', XMLDB_INDEX_NOTUNIQUE, ['participanttype', 'participantid']);
                    
                    $dbman->create_table($table);
                }
                
                // Create theme_remui_kids_lecture_schedules table
                $table = new xmldb_table('theme_remui_kids_lecture_schedules');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
                    $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('starttime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '00:00');
                    $table->add_field('endtime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '00:00');
                    $table->add_field('frequency', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'weekly');
                    $table->add_field('days', XMLDB_TYPE_CHAR, '50', null, null, null, null);
                    $table->add_field('color', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'green');
                    $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);
                    $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
                    $table->add_index('teacherid_idx', XMLDB_INDEX_NOTUNIQUE, ['teacherid']);
                    $table->add_index('startdate_idx', XMLDB_INDEX_NOTUNIQUE, ['startdate']);
                    
                    $dbman->create_table($table);
                }
                
                // Create theme_remui_kids_lecture_sessions table
                $table = new xmldb_table('theme_remui_kids_lecture_sessions');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('scheduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('sessiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('starttime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '00:00');
                    $table->add_field('endtime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '00:00');
                    $table->add_field('color', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'green');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('scheduleid_idx', XMLDB_INDEX_NOTUNIQUE, ['scheduleid']);
                    $table->add_index('sessiondate_idx', XMLDB_INDEX_NOTUNIQUE, ['sessiondate']);
                    $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
                    $table->add_index('teacherid_idx', XMLDB_INDEX_NOTUNIQUE, ['teacherid']);
                    
                    $dbman->create_table($table);
                }
                
                // Create theme_remui_kids_lecture_notifications table
                $table = new xmldb_table('theme_remui_kids_lecture_notifications');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('scheduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
                    $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
                    $table->add_field('is_read', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timeread', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
                    
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('teacherid_idx', XMLDB_INDEX_NOTUNIQUE, ['teacherid']);
                    $table->add_index('scheduleid_idx', XMLDB_INDEX_NOTUNIQUE, ['scheduleid']);
                    $table->add_index('is_read_idx', XMLDB_INDEX_NOTUNIQUE, ['is_read']);
                    $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
                    
                    $dbman->create_table($table);
                }
            }
        }
        
        upgrade_plugin_savepoint(true, 2025121718, 'theme', 'remui_kids');
    }
    
    if ($oldversion < 2025123000) {
        // Add teacher_available field to lecture_sessions table
        $table = new xmldb_table('theme_remui_kids_lecture_sessions');
        $field = new xmldb_field('teacher_available', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'timecreated');
        
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2025123000, 'theme', 'remui_kids');
    }

    return true;
}