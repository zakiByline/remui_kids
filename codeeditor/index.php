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
 * List of all code editors in course
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/codeeditor/lib.php');

$id = required_param('id', PARAM_INT); // Course id.

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

require_course_login($course, true);
$PAGE->set_pagelayout('incourse');

// Trigger instances list viewed event.
$params = array('context' => context_course::instance($course->id));
$event = \mod_codeeditor\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

// Get all the appropriate data.
if (!$codeeditors = get_all_instances_in_course('codeeditor', $course)) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'codeeditor')),
        new moodle_url('/course/view.php', array('id' => $course->id)));
    exit;
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';

if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_'.$course->format);
    $table->head  = array ($strsectionname, get_string('name'));
    $table->align = array ('center', 'left');
} else {
    $table->head  = array (get_string('name'));
    $table->align = array ('left');
}

$modinfo = get_fast_modinfo($course);
$currentsection = '';
foreach ($codeeditors as $codeeditor) {
    $cm = $modinfo->cms[$codeeditor->coursemodule];
    if ($usesections) {
        $printsection = '';
        if ($codeeditor->section !== $currentsection) {
            if ($codeeditor->section) {
                $printsection = get_section_name($course, $codeeditor->section);
            }
            if ($currentsection !== '') {
                $table->data[] = 'hr';
            }
            $currentsection = $codeeditor->section;
        }
    }

    $class = $codeeditor->visible ? '' : 'class="dimmed"';

    $table->data[] = array (
        $printsection,
        html_writer::link(new moodle_url('view.php', array('id' => $cm->id)),
            format_string($codeeditor->name), array('class' => $class))
    );
}

$PAGE->set_url('/mod/codeeditor/index.php', array('id' => $id));
$PAGE->set_title($course->shortname.': '.get_string('modulenameplural', 'codeeditor'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('modulenameplural', 'codeeditor'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'codeeditor'));
echo html_writer::table($table);
echo $OUTPUT->footer();
