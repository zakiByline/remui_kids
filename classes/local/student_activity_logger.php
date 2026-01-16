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

use core\event\base as event_base;
use dml_exception;

/**
 * Persists captured student activity events for teacher dashboards.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 */
final class student_activity_logger {
    /**
     * Cache whether the backing table exists.
     *
     * @var bool|null
     */
    private static ?bool $available = null;

    /**
     * Cached resolved system IP.
     *
     * @var string|null
     */
    private static ?string $systemip = null;

    /**
     * Store a supported event in the activity log table.
     *
     * @param event_base $event
     * @return void
     */
    public static function capture(event_base $event): void {
        global $DB;

        if (!self::is_available()) {
            return;
        }

        $userid = (int)($event->userid ?? 0);
        if ($userid <= 0) {
            return;
        }

        $record = (object) [
            'userid' => $userid,
            'courseid' => (int)($event->courseid ?? 0),
            'contextinstanceid' => (int)($event->contextinstanceid ?? 0),
            'eventname' => (string)($event->eventname ?? ''),
            'component' => (string)($event->component ?? ''),
            'action' => (string)($event->action ?? ''),
            'target' => (string)($event->target ?? ''),
            'ip' => self::extract_ip($event),
            'other' => self::extract_other($event),
            'timecreated' => (int)($event->timecreated ?? time()),
        ];

        try {
            $DB->insert_record('activity_log', $record);
        } catch (dml_exception $exception) {
            debugging('theme_remui_kids: failed to persist activity log - ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Determine whether the activity_log table exists.
     *
     * @return bool
     */
    private static function is_available(): bool {
        global $DB;

        if (self::$available === null) {
            $dbman = $DB->get_manager();
            self::$available = $dbman->table_exists('activity_log');
        }

        return self::$available;
    }

    /**
     * Extract the IP address from the event payload.
     *
     * @param event_base $event
     * @return string
     */
    private static function extract_ip(event_base $event): string {
        $data = $event->get_data();

        $candidates = [];

        if (!empty($data['ip'])) {
            $candidates[] = (string)$data['ip'];
        }

        $other = $data['other'] ?? null;
        if (is_array($other) && !empty($other['ip'])) {
            $candidates[] = (string)$other['ip'];
        }

        foreach (self::header_ips('HTTP_CF_CONNECTING_IP') as $ip) {
            $candidates[] = $ip;
        }
        foreach (self::header_ips('HTTP_X_FORWARDED_FOR') as $ip) {
            $candidates[] = $ip;
        }
        foreach (self::header_ips('HTTP_X_REAL_IP') as $ip) {
            $candidates[] = $ip;
        }
        foreach (self::header_ips('HTTP_CLIENT_IP') as $ip) {
            $candidates[] = $ip;
        }

        $remoteaddr = getremoteaddr();
        if ($remoteaddr !== false) {
            $candidates[] = (string)$remoteaddr;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return self::resolve_system_ip();
    }

    /**
     * Encode the event "other" payload as JSON for storage.
     *
     * @param event_base $event
     * @return string
     */
    private static function extract_other(event_base $event): string {
        $data = $event->get_data();
        $other = $data['other'] ?? null;

        if (empty($other)) {
            return '';
        }

        if (is_string($other)) {
            return $other;
        }

        $encoded = json_encode($other, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '' : $encoded;
    }

    /**
     * Parse a comma-delimited IP header into individual addresses.
     *
     * @param string $header
     * @return array
     */
    private static function header_ips(string $header): array {
        if (empty($_SERVER[$header])) {
            return [];
        }

        $values = explode(',', $_SERVER[$header]);
        $ips = [];

        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $ips[] = $value;
            }
        }

        return $ips;
    }

    /**
     * Resolve the best guess for the system (server-facing) IP.
     *
     * @return string
     */
    public static function resolve_system_ip(): string {
        if (self::$systemip !== null) {
            return self::$systemip;
        }

        $serverip = $_SERVER['SERVER_ADDR'] ?? '';

        if ($serverip === '' || $serverip === '::1' || $serverip === '0:0:0:0:0:0:0:1') {
            $resolved = gethostbyname(gethostname());
            if (!empty($resolved)) {
                $serverip = $resolved;
            } else {
                $serverip = '127.0.0.1';
            }
        }

        self::$systemip = $serverip ?: '127.0.0.1';
        return self::$systemip;
    }
}
