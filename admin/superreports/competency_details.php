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
 * Competency Details Endpoint - Returns detailed course mappings for a competency
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once('../../../../config.php');

global $DB, $CFG;

require_login();

// Verify admin access
if (!is_siteadmin()) {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Verify session key
$sesskey = optional_param('sesskey', '', PARAM_RAW);
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Invalid session key']));
}

// Get competency ID
$competencyid = required_param('id', PARAM_INT);

// Clean any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

try {
    // Get courses mapped to this competency with statistics
    $sql = "SELECT co.id, co.fullname, co.shortname,
                   COUNT(DISTINCT ue.userid) as students,
                   AVG(CASE WHEN ucc.grade IS NOT NULL THEN ucc.grade END) as avg_grade,
                   COUNT(DISTINCT CASE WHEN ucc.proficiency = 1 THEN ucc.userid END) as proficient,
                   COUNT(DISTINCT ucc.userid) as total_users
            FROM {course} co
            INNER JOIN {competency_coursecomp} cc ON cc.courseid = co.id
            LEFT JOIN {enrol} e ON e.courseid = co.id
            LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
            LEFT JOIN {competency_usercompcourse} ucc ON ucc.courseid = co.id AND ucc.competencyid = cc.competencyid
            WHERE cc.competencyid = :competencyid
            GROUP BY co.id, co.fullname, co.shortname
            ORDER BY co.fullname ASC";
    
    $courses = $DB->get_records_sql($sql, ['competencyid' => $competencyid]);
    
    $coursearray = [];
    $totalmastery = 0;
    $masterycount = 0;
    
    foreach ($courses as $course) {
        // Get primary teacher
        $teacher = 'N/A';
        $teachersql = "SELECT u.firstname, u.lastname
                       FROM {user} u
                       INNER JOIN {role_assignments} ra ON ra.userid = u.id
                       INNER JOIN {context} ctx ON ctx.id = ra.contextid
                       INNER JOIN {role} r ON r.id = ra.roleid
                       WHERE ctx.contextlevel = 50
                       AND ctx.instanceid = :courseid
                       AND r.shortname IN ('editingteacher', 'teacher')
                       LIMIT 1";
        
        $teacherrecord = $DB->get_record_sql($teachersql, ['courseid' => $course->id]);
        if ($teacherrecord) {
            $teacher = fullname($teacherrecord);
        }
        
        $completion = 0;
        if ($course->total_users > 0) {
            $completion = round(($course->proficient / $course->total_users) * 100, 1);
        }
        
        $avggrade = $course->avg_grade ? round($course->avg_grade, 1) : 0;
        if ($avggrade > 0) {
            $totalmastery += $avggrade;
            $masterycount++;
        }
        
        $coursearray[] = [
            'name' => format_string($course->fullname),
            'shortname' => format_string($course->shortname),
            'teacher' => $teacher,
            'students' => (int)$course->students,
            'avg_grade' => $avggrade,
            'completion' => $completion
        ];
    }
    
    $avgmastery = $masterycount > 0 ? round($totalmastery / $masterycount, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'courses' => $coursearray,
            'avg_mastery' => $avgmastery
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}










































