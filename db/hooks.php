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
 * Hook callbacks for remui_kids theme
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\after_config::class,
        'callback' => function(\core\hook\after_config $hook) {
            // Log that Moodle initialization has completed
            // This runs as early as possible after config.php and setup.php
            if (function_exists('theme_remui_kids_log')) {
                global $CFG, $DB, $USER;
                
                $init_end = microtime(true);
                
                // Try to calculate initialization time if we have a start time
                // Note: We can't capture the exact start, but we can log what's available
                $data = [
                    'db_connected' => isset($DB) && is_object($DB),
                    'user_available' => isset($USER) && isset($USER->id),
                    'userid' => isset($USER->id) ? $USER->id : 0,
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                    'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown'
                ];
                
                theme_remui_kids_log('Moodle initialization completed (after_config hook)', $data, 'INFO');
            }
        },
    ],
];
