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

namespace theme_remui_kids\event;

defined('MOODLE_INTERNAL') || die();

class doubt_status_updated extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'theme_remui_kids_dbt';
    }

    public static function get_name(): string {
        return get_string('event_doubt_status_updated', 'theme_remui_kids');
    }

    public function get_description(): string {
        return "User {$this->userid} changed doubt {$this->objectid} status from {$this->other['from']} to {$this->other['to']}";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/theme/remui_kids/pages/teacher_doubts.php', ['doubtid' => $this->objectid]);
    }
}


