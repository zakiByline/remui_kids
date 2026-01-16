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

use coding_exception;
use context_system;
use stdClass;

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Repository for doubt data persistence.
 *
 * @package   theme_remui_kids
 */
class repository {
    /**
     * Returns accessible course ids for a user, null indicates all courses.
     *
     * @param int $userid
     * @return int[]|null
     */
    public function get_accessible_courseids(int $userid): ?array {
        if (has_capability('theme/remui_kids:managedoubts', context_system::instance(), $userid)) {
            return null;
        }

        $courses = get_user_capability_course('theme/remui_kids:viewdoubts', $userid, false);
        if (empty($courses)) {
            return [];
        }

        $courseids = [];
        foreach ($courses as $course) {
            $courseids[] = (int) $course->id;
        }

        return $courseids;
    }

    /**
     * Fetches paginated doubts for teacher view.
     *
     * @param int[]|null $courseids null for all courses
     * @param array $filters
     * @param int $page
     * @param int $perpage
     * @return array{total:int,records:stdClass[]}
     */
    public function list_doubts(?array $courseids, array $filters, int $page, int $perpage): array {
        global $DB;

        if ($courseids !== null && empty($courseids)) {
            return ['total' => 0, 'records' => []];
        }

        $params = [];
        $where = [];

        if ($courseids !== null) {
            list($sql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
            $where[] = "d.courseid {$sql}";
            $params += $cparams;
        }

        if (!empty($filters['status'])) {
            $where[] = 'd.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = 'd.priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['assigned'])) {
            if ($filters['assigned'] === 'unassigned') {
                $where[] = 'd.assignedto = 0';
            } else {
                $where[] = 'd.assignedto = :assignedto';
                $params['assignedto'] = (int) $filters['assigned'];
            }
        }

        if (!empty($filters['search'])) {
            $search = '%' . $DB->sql_like_escape($filters['search'], false) . '%';
            $fullnameexpr = $DB->sql_fullname('stu.firstname', 'stu.lastname');

            $likeconditions = [];
            $likeconditions[] = $DB->sql_like('LOWER(d.subject)', ':searchsubject', false);
            $likeconditions[] = $DB->sql_like('LOWER(' . $fullnameexpr . ')', ':searchname', false);
            $likeconditions[] = $DB->sql_like('LOWER(stu.email)', ':searchemail', false);

            $params['searchsubject'] = strtolower($search);
            $params['searchname'] = strtolower($search);
            $params['searchemail'] = strtolower($search);

            $where[] = '(' . implode(' OR ', $likeconditions) . ')';
        }

        $wheresql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $countsql = "SELECT COUNT(1)
                       FROM {theme_remui_kids_dbt} d
                       JOIN {user} stu ON stu.id = d.studentid
                     {$wheresql}";
        $total = (int) $DB->get_field_sql($countsql, $params);

        if ($total === 0) {
            return ['total' => 0, 'records' => []];
        }

        $fields = "d.*, stu.firstname AS studentfirstname, stu.lastname AS studentlastname, stu.email AS studentemail,
                   ass.id AS teacherid, ass.firstname AS teacherfirstname, ass.lastname AS teacherlastname,
                   (SELECT COUNT(1) FROM {theme_remui_kids_dbtmsg} m WHERE m.doubtid = d.id) AS messagecount,
                   (SELECT MAX(timecreated) FROM {theme_remui_kids_dbtmsg} m WHERE m.doubtid = d.id) AS lastmessagetime";

        $sql = "SELECT {$fields}
                  FROM {theme_remui_kids_dbt} d
                  JOIN {user} stu ON stu.id = d.studentid
             LEFT JOIN {user} ass ON ass.id = d.assignedto
                {$wheresql}
              ORDER BY d.timemodified DESC";

        $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['total' => $total, 'records' => array_values($records)];
    }

    /**
     * Retrieves a doubt record.
     *
     * @param int $doubtid
     * @return stdClass|null
     */
    public function get_doubt(int $doubtid): ?stdClass {
        global $DB;
        return $DB->get_record('theme_remui_kids_dbt', ['id' => $doubtid], '*', IGNORE_MISSING);
    }

    /**
     * Retrieves messages for a doubt.
     *
     * @param int $doubtid
     * @return stdClass[]
     */
    public function get_messages(int $doubtid): array {
        global $DB;

        $sql = "SELECT m.*, u.firstname, u.lastname, u.email, u.alternatename, u.middlename,
                       u.firstnamephonetic, u.lastnamephonetic, u.picture, u.imagealt
                  FROM {theme_remui_kids_dbtmsg} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.doubtid = :doubtid
              ORDER BY m.timecreated ASC";

        return $DB->get_records_sql($sql, ['doubtid' => $doubtid]);
    }

    /**
     * Retrieves public messages visible to students.
     *
     * @param int $doubtid
     * @return stdClass[]
     */
    public function get_messages_for_student(int $doubtid): array {
        global $DB;

        $sql = "SELECT m.*, u.firstname, u.lastname, u.email, u.alternatename, u.middlename,
                       u.firstnamephonetic, u.lastnamephonetic, u.picture, u.imagealt
                  FROM {theme_remui_kids_dbtmsg} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.doubtid = :doubtid AND m.visibility <> :internal
              ORDER BY m.timecreated ASC";

        return $DB->get_records_sql($sql, ['doubtid' => $doubtid, 'internal' => constants::VISIBILITY_INTERNAL]);
    }

    /**
     * Inserts a message record.
     *
     * @param stdClass $record
     * @return int
     * @throws coding_exception
     */
    public function insert_message(stdClass $record): int {
        global $DB;

        $record->timecreated = $record->timecreated ?? time();
        $record->timemodified = $record->timemodified ?? $record->timecreated;

        $id = $DB->insert_record('theme_remui_kids_dbtmsg', $record);
        if (!$id) {
            throw new coding_exception('Failed to insert doubt message');
        }

        return $id;
    }

    /**
     * Updates fields on a doubt record.
     *
     * @param int $doubtid
     * @param array $fields
     * @return void
     */
    public function update_doubt_fields(int $doubtid, array $fields): void {
        global $DB;

        if (empty($fields)) {
            return;
        }

        $fields['id'] = $doubtid;
        $fields['timemodified'] = $fields['timemodified'] ?? time();
        $DB->update_record('theme_remui_kids_dbt', (object) $fields);
    }

    /**
     * Persists status change log.
     *
     * @param stdClass $record
     * @return void
     */
    public function log_status_change(stdClass $record): void {
        global $DB;
        $record->timecreated = $record->timecreated ?? time();
        $DB->insert_record('theme_remui_kids_dbtlog', $record, false);
    }

    /**
     * Fetch doubts status history.
     *
     * @param int $doubtid
     * @return stdClass[]
     */
    public function get_status_history(int $doubtid): array {
        global $DB;

        $sql = "SELECT l.*, u.firstname, u.lastname
                  FROM {theme_remui_kids_dbtlog} l
             LEFT JOIN {user} u ON u.id = l.userid
                 WHERE l.doubtid = :doubtid
              ORDER BY l.timecreated ASC";

        return $DB->get_records_sql($sql, ['doubtid' => $doubtid]);
    }

    /**
     * Records attachment metadata for quick lookup.
     *
     * @param stdClass $record
     * @return void
     */
    public function insert_attachment(stdClass $record): void {
        global $DB;
        $record->timecreated = $record->timecreated ?? time();
        $record->timemodified = $record->timemodified ?? $record->timecreated;
        $DB->insert_record('theme_remui_kids_dbtatt', $record, false);
    }

    /**
     * Removes attachment metadata for a message prior to refresh.
     *
     * @param int $messageid
     * @return void
     */
    public function delete_attachments_for_message(int $messageid): void {
        global $DB;
        $DB->delete_records('theme_remui_kids_dbtatt', ['messageid' => $messageid]);
    }

    /**
     * Returns attachment metadata keyed by message id.
     *
     * @param int[] $messageids
     * @return array<int,stdClass[]>
     */
    public function get_attachments_for_messages(array $messageids): array {
        global $DB;

        if (empty($messageids)) {
            return [];
        }

        list($sql, $params) = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED, 'msg');

        $records = $DB->get_records_select('theme_remui_kids_dbtatt', "messageid {$sql}", $params, 'timecreated ASC');

        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->messageid][] = $record;
        }

        return $grouped;
    }

    /**
     * Returns aggregated summary counts for doubts accessible to the user.
     *
     * @param int[]|null $courseids
     * @return array<string,int>
     */
    public function get_summary_counts(?array $courseids): array {
        global $DB;

        if ($courseids !== null && empty($courseids)) {
            return [
                'total' => 0,
                'open' => 0,
                'inprogress' => 0,
                'waiting_student' => 0,
                'resolved' => 0,
                'archived' => 0,
                'unassigned' => 0,
            ];
        }

        $params = [];
        $where = [];

        if ($courseids !== null) {
            list($sql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
            $where[] = "courseid {$sql}";
            $params += $cparams;
        }

        $wheresql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT status, COUNT(1) AS total
                  FROM {theme_remui_kids_dbt}
               {$wheresql}
              GROUP BY status";

        $statuscounts = $DB->get_records_sql_menu($sql, $params);

        $total = array_sum($statuscounts ?: []);

        $unassignedwhere = $where;
        $unassignedwhere[] = 'assignedto = 0';
        $unassignedwheresql = 'WHERE ' . implode(' AND ', $unassignedwhere);
        $unassignedsql = "SELECT COUNT(1)
                             FROM {theme_remui_kids_dbt}
                          {$unassignedwheresql}";
        $unassigned = (int) $DB->get_field_sql($unassignedsql, $params);

        return [
            'total' => $total,
            'open' => (int) ($statuscounts[constants::STATUS_OPEN] ?? 0),
            'inprogress' => (int) ($statuscounts[constants::STATUS_INPROGRESS] ?? 0),
            'waiting_student' => (int) ($statuscounts[constants::STATUS_WAITING_STUDENT] ?? 0),
            'resolved' => (int) ($statuscounts[constants::STATUS_RESOLVED] ?? 0),
            'archived' => (int) ($statuscounts[constants::STATUS_ARCHIVED] ?? 0),
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Creates a new doubt record from student submission.
     *
     * @param stdClass $record
     * @return int
     * @throws coding_exception
     */
    public function create_doubt(stdClass $record): int {
        global $DB;

        $record->status = $record->status ?? constants::STATUS_OPEN;
        $record->priority = $record->priority ?? constants::PRIORITY_NORMAL;
        $record->assignedto = $record->assignedto ?? 0;
        $record->tags = $record->tags ?? '';
        $record->gradeband = $record->gradeband ?? '';
        $record->timeresolved = $record->timeresolved ?? 0;
        $record->duedate = $record->duedate ?? 0;
        $record->lastmessageid = $record->lastmessageid ?? 0;
        $record->extradata = $record->extradata ?? null;
        $record->timecreated = $record->timecreated ?? time();
        $record->timemodified = $record->timemodified ?? $record->timecreated;

        $id = $DB->insert_record('theme_remui_kids_dbt', $record);
        if (!$id) {
            throw new coding_exception('Failed to create doubt record');
        }

        return $id;
    }

    /**
     * Lists doubts for a specific student.
     *
     * @param int $studentid
     * @param int|null $courseid
     * @return stdClass[]
     */
    public function list_doubts_for_student(int $studentid, ?int $courseid = null): array {
        global $DB;

        $params = ['studentid' => $studentid];
        $where = ['d.studentid = :studentid'];

        if (!empty($courseid)) {
            $where[] = 'd.courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        $wheresql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT d.*, c.fullname AS coursefullname
                  FROM {theme_remui_kids_dbt} d
             LEFT JOIN {course} c ON c.id = d.courseid
                {$wheresql}
              ORDER BY d.timemodified DESC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Retrieves a doubt ensuring it belongs to the student.
     *
     * @param int $doubtid
     * @param int $studentid
     * @return stdClass|null
     */
    public function get_doubt_for_student(int $doubtid, int $studentid): ?stdClass {
        global $DB;

        $sql = "SELECT d.*, c.fullname AS coursefullname
                  FROM {theme_remui_kids_dbt} d
             LEFT JOIN {course} c ON c.id = d.courseid
                 WHERE d.id = :doubtid AND d.studentid = :studentid";

        $record = $DB->get_record_sql($sql, [
            'doubtid' => $doubtid,
            'studentid' => $studentid,
        ], IGNORE_MISSING);

        return $record ?: null;
    }
}

