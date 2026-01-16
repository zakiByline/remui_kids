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
 * Define all the backup steps that will be used by the backup_codeeditor_activity_task
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class backup_codeeditor_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        $codeeditor = new backup_nested_element('codeeditor', array('id'), array(
            'name', 'intro', 'introformat', 'description', 'descriptionformat',
            'timecreated', 'timemodified'));

        $codeeditor->set_source_table('codeeditor', array('id' => backup::VAR_ACTIVITYID));

        $codeeditor->annotate_files('mod_codeeditor', 'intro', null);
        $codeeditor->annotate_files('mod_codeeditor', 'description', null);

        return $this->prepare_activity_structure($codeeditor);
    }
}
