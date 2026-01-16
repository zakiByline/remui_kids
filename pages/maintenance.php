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
 * Friendly maintenance landing page for the RemUI Kids theme.
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

$statuscode = (int)(get_config('theme_remui_kids', 'maintenance_statuscode') ?? 503);
http_response_code($statuscode);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/maintenance.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('maintenance_title', 'theme_remui_kids'));
$PAGE->set_heading('');
$PAGE->set_cacheable(false);
$PAGE->add_body_class('remui-kids-maintenance-page');

$maintenancetitle = get_config('theme_remui_kids', 'maintenance_custom_title') ??
    get_string('maintenance_title', 'theme_remui_kids');

$maintenancemessage = get_config('theme_remui_kids', 'maintenance_custom_message') ??
    get_string('maintenance_message', 'theme_remui_kids');

$contactemail = get_config('theme_remui_kids', 'maintenance_support_email');
if (empty($contactemail) && !empty($CFG->supportemail)) {
    $contactemail = $CFG->supportemail;
}

$homeurl = new moodle_url('/');
$dashurl = new moodle_url('/my/');

$templatecontext = [
    'title' => format_string($maintenancetitle),
    'message' => format_text($maintenancemessage, FORMAT_HTML),
    'statuslabel' => get_string('maintenance_status_label', 'theme_remui_kids', $statuscode),
    'reloadlabel' => get_string('maintenance_reload', 'theme_remui_kids'),
    'homeurl' => $homeurl->out(false),
    'homebuttonlabel' => get_string('maintenance_home', 'theme_remui_kids'),
    'dashurl' => $dashurl->out(false),
    'dashbuttonlabel' => get_string('maintenance_dashboard', 'theme_remui_kids'),
    'contactemail' => $contactemail,
    'supportemail' => $contactemail,
    'contactlabel' => get_string('maintenance_contact', 'theme_remui_kids'),
    'hascontact' => !empty($contactemail),
    'timestamp' => userdate(time(), get_string('strftimedatetimeshort')),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/maintenance', $templatecontext);
echo $OUTPUT->footer();
