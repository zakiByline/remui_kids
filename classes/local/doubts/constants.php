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

namespace theme_remui_kids\local\doubts;

defined('MOODLE_INTERNAL') || die();

/**
 * Doubt system constants and helpers.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class constants {
    public const STATUS_OPEN = 'open';
    public const STATUS_INPROGRESS = 'inprogress';
    public const STATUS_WAITING_STUDENT = 'waiting_student';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_INTERNAL = 'internal';

    /**
     * Returns list of valid statuses.
     *
     * @return string[]
     */
    public static function statuses(): array {
        return [
            self::STATUS_OPEN,
            self::STATUS_INPROGRESS,
            self::STATUS_WAITING_STUDENT,
            self::STATUS_RESOLVED,
            self::STATUS_ARCHIVED,
        ];
    }

    /**
     * Returns map of statuses to human labels.
     *
     * @return array<string,string>
     */
    public static function status_labels(): array {
        return [
            self::STATUS_OPEN => get_string('doubtstatus:open', 'theme_remui_kids'),
            self::STATUS_INPROGRESS => get_string('doubtstatus:inprogress', 'theme_remui_kids'),
            self::STATUS_WAITING_STUDENT => get_string('doubtstatus:waiting_student', 'theme_remui_kids'),
            self::STATUS_RESOLVED => get_string('doubtstatus:resolved', 'theme_remui_kids'),
            self::STATUS_ARCHIVED => get_string('doubtstatus:archived', 'theme_remui_kids'),
        ];
    }

    /**
     * Returns list of valid priorities.
     *
     * @return string[]
     */
    public static function priorities(): array {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    /**
     * Returns allowed message visibilities.
     *
     * @return string[]
     */
    public static function visibilities(): array {
        return [self::VISIBILITY_PUBLIC, self::VISIBILITY_INTERNAL];
    }
}

