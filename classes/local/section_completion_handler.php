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

namespace theme_remui_kids\local;

defined('MOODLE_INTERNAL') || die();

use core\event\course_module_completion_updated;
use completion_info;

/**
 * Handles section completion when all activities in a section are completed.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_completion_handler {

    /**
     * Handle course module completion updated event.
     * Checks if all activities in the section are complete and marks section as complete if so.
     *
     * @param course_module_completion_updated $event
     * @return void
     */
    public static function handle_activity_completion(course_module_completion_updated $event) {
        global $DB;

        // Get the course module that was completed.
        $cmid = $event->contextinstanceid;
        $userid = $event->relateduserid;

        if (!$cmid || !$userid) {
            return;
        }

        // Get course module record to find the section.
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $courseid = $cm->course;
        $sectionid = $cm->section;

        // Get section record.
        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', IGNORE_MISSING);
        if (!$section) {
            return;
        }

        // Skip section 0 (general section) as it's usually for announcements.
        if ($section->section == 0) {
            return;
        }

        // Get course object.
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return;
        }

        // Check if completion is enabled for the course.
        $completioninfo = new completion_info($course);
        if (!$completioninfo->is_enabled()) {
            return;
        }

        // Get all course modules in this section.
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->sections[$section->section])) {
            return;
        }

        $totalactivities = 0;
        $completedactivities = 0;

        // Count activities with completion tracking enabled.
        foreach ($modinfo->sections[$section->section] as $sectioncmid) {
            if (!isset($modinfo->cms[$sectioncmid])) {
                continue;
            }

            $sectioncm = $modinfo->cms[$sectioncmid];

            // Skip if not visible to user or is being deleted.
            if (!$sectioncm->uservisible || $sectioncm->deletioninprogress) {
                continue;
            }

            // Skip labels as they don't have completion.
            if ($sectioncm->modname === 'label') {
                continue;
            }

            // Check if completion is enabled for this activity.
            if ($completioninfo->is_enabled($sectioncm) == COMPLETION_TRACKING_NONE) {
                continue;
            }

            $totalactivities++;

            // Check if this activity is completed.
            $completiondata = $completioninfo->get_data($sectioncm, false, $userid);
            if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                $completedactivities++;
            }
        }

        // If there are no activities with completion tracking, don't mark section as complete.
        // Also clean up any existing completion records for this section.
        if ($totalactivities == 0) {
            $DB->delete_records('theme_remui_kids_section_completions', [
                'userid' => $userid,
                'courseid' => $courseid,
                'sectionid' => $sectionid
            ]);
            return;
        }

        // If all activities are completed, mark the section as complete.
        if ($completedactivities >= $totalactivities) {
            $now = time();

            // Check if section completion record already exists.
            $existing = $DB->get_record('theme_remui_kids_section_completions', [
                'userid' => $userid,
                'courseid' => $courseid,
                'sectionid' => $sectionid
            ]);

            if ($existing) {
                // Update existing record if not already completed.
                if (!$existing->timecompleted) {
                    $existing->timecompleted = $now;
                    $existing->timemodified = $now;
                    $DB->update_record('theme_remui_kids_section_completions', $existing);
                }
            } else {
                // Create new section completion record.
                $record = (object)[
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'sectionid' => $sectionid,
                    'sectionnum' => $section->section,
                    'timecompleted' => $now,
                    'timecreated' => $now,
                    'timemodified' => $now
                ];
                $DB->insert_record('theme_remui_kids_section_completions', $record);
            }
        } else {
            // If not all activities are complete, remove section completion if it exists.
            // This handles the case where an activity completion is revoked.
            $DB->delete_records('theme_remui_kids_section_completions', [
                'userid' => $userid,
                'courseid' => $courseid,
                'sectionid' => $sectionid
            ]);
        }
    }
}

