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
 * Friendly maintenance page for the course outline report.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$id = required_param('id', PARAM_INT);
$startdate = optional_param('startdate', null, PARAM_INT);
$enddate = optional_param('enddate', null, PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
require_capability('report/outline:view', $context);

$pageparams = ['id' => $id];
if ($startdate) {
    $pageparams['startdate'] = $startdate;
}
if ($enddate) {
    $pageparams['enddate'] = $enddate;
}

$PAGE->set_url('/theme/remui_kids/teacher/report_outline_maintenance.php', $pageparams);
$PAGE->set_pagelayout('report');

$maintenancetitle = get_string('reportoutlinemaintenancetitle', 'theme_remui_kids');
$PAGE->set_title($course->shortname . ': ' . $maintenancetitle);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading($maintenancetitle);

$maintenancemessage = get_string('reportoutlinemaintenancebody', 'theme_remui_kids');
echo html_writer::div($maintenancemessage, 'remui-maintenance-message lead');

$buttonhtml = html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('maintenance_dashboard', 'theme_remui_kids'),
        'get',
        ['class' => 'btn btn-primary']
    ) .
    $OUTPUT->single_button(
        new moodle_url('/'),
        get_string('maintenance_home', 'theme_remui_kids'),
        'get',
        ['class' => 'btn btn-secondary ml-2']
    ),
    'remui-maintenance-actions d-flex gap-2 mt-4'
);
echo $buttonhtml;

$supportemail = get_config('theme_remui_kids', 'maintenance_support_email');
if (empty($supportemail)) {
    $supportemail = get_string('maintenance_support_email', 'theme_remui_kids');
}
$contactmessage = get_string('reportoutlinemaintenancecontact', 'theme_remui_kids', $supportemail);
echo html_writer::div($contactmessage, 'remui-maintenance-contact text-muted mt-3');

echo $OUTPUT->footer();

