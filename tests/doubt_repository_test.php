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

namespace theme_remui_kids\tests;

use advanced_testcase;
use context_course;
use theme_remui_kids\local\doubts\repository;
use theme_remui_kids\local\doubts\constants;

defined('MOODLE_INTERNAL') || die();

class doubt_repository_test extends advanced_testcase {
    public function test_summary_counts_empty_set(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $summary = $repo->get_summary_counts([]);

        $this->assertEquals(0, $summary['total']);
        $this->assertEquals(0, $summary['open']);
        $this->assertEquals(0, $summary['inprogress']);
        $this->assertEquals(0, $summary['waiting_student']);
        $this->assertEquals(0, $summary['resolved']);
        $this->assertEquals(0, $summary['archived']);
        $this->assertEquals(0, $summary['unassigned']);
    }

    public function test_summary_counts_with_records(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $context = context_course::instance($course->id);

        $records = [
            [constants::STATUS_OPEN, 0],
            [constants::STATUS_INPROGRESS, $student->id],
            [constants::STATUS_WAITING_STUDENT, 0],
            [constants::STATUS_RESOLVED, 0],
        ];

        foreach ($records as $idx => [$status, $assigned]) {
            $DB->insert_record('theme_remui_kids_dbt', (object) [
                'courseid' => $course->id,
                'contextid' => $context->id,
                'cmid' => 0,
                'studentid' => $student->id,
                'assignedto' => $assigned,
                'subject' => 'Sample doubt ' . $idx,
                'summary' => 'Body',
                'status' => $status,
                'priority' => constants::PRIORITY_NORMAL,
                'tags' => '',
                'gradeband' => '',
                'timeresolved' => 0,
                'duedate' => 0,
                'lastmessageid' => 0,
                'extradata' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $repo = new repository();
        $summary = $repo->get_summary_counts([$course->id]);

        $this->assertEquals(4, $summary['total']);
        $this->assertEquals(1, $summary['open']);
        $this->assertEquals(1, $summary['inprogress']);
        $this->assertEquals(1, $summary['waiting_student']);
        $this->assertEquals(1, $summary['resolved']);
        $this->assertEquals(3, $summary['unassigned']);
    }
}

