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
 * A drawer based layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

// Ensure doctype() is called at the very beginning (required by Moodle core renderer).
$OUTPUT->doctype();

if(!apply_latest_user_pref()){
    user_preference_allow_ajax_update('enable_focus_mode', PARAM_BOOL);
}

require_once($CFG->dirroot . '/theme/remui_kids/layout/common.php');

if (isset($templatecontext['focusdata']['enabled']) && $templatecontext['focusdata']['enabled']) {
    if (isset($PAGE->cm->id)) {
        list(
            $templatecontext['focusdata']['sections'],
            $templatecontext['focusdata']['active'],
            $templatecontext['focusdata']['previous'],
            $templatecontext['focusdata']['next']
        ) = \theme_remui\utility::get_focus_mode_sections($COURSE, $PAGE->cm->id);
    } else {
        list(
            $templatecontext['focusdata']['sections'],
            $templatecontext['focusdata']['active']
        ) = \theme_remui\utility::get_focus_mode_sections($COURSE);
    }
}

$template = 'theme_remui/incourse';

// Return if not on enrolment page.
if ($PAGE->pagetype == "enrol-index" & get_config('theme_remui', 'enrolment_page_layout')) {
    $extraclasses[] = 'page-enrolment';
    $template = 'theme_remui/enrolpage';

    $eh = new \theme_remui\EnrolmentPageHandler();
    $templatecontext['enrolment'] = $eh->generate_enrolment_page_context($templatecontext);
}

// Must be called before rendering the template.
// This will ease us to add body classes directly to the array.
require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');

// doctype() is already called at the beginning of the file, no need to call again

echo $OUTPUT->render_from_template($template, $templatecontext);
