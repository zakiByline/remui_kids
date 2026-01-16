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
 * Event observers for student activity logging.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_loggedin',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
        'internal' => false,
        'priority' => 999,
    ],
    [
        'eventname' => '\core\event\dashboard_viewed',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\core\event\my_dashboard_viewed',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\core\event\course_viewed',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\mod_assign\event\submission_submitted',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\theme_remui_kids\local\student_activity_logger::capture',
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\theme_remui_kids\local\section_completion_handler::handle_activity_completion',
        'internal' => false,
    ],
];

