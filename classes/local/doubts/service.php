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

use context;
use context_course;
use required_capability_exception;
use moodle_exception;
use moodle_url;
use stdClass;
use core_user;

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');

defined('MOODLE_INTERNAL') || die();

/**
 * High-level service encapsulating operations around doubts for teacher workflows.
 *
 * @package   theme_remui_kids
 */
class service {
    /** @var repository */
    private $repository;

    /**
     * @param repository|null $repository
     */
    public function __construct(?repository $repository = null) {
        $this->repository = $repository ?? new repository();
    }

    /**
     * Returns paginated list of doubts for a teacher.
     *
     * @param int $userid
     * @param array $filters
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public function list_for_teacher(int $userid, array $filters = [], int $page = 0, int $perpage = 20): array {
        if (!empty($filters['status']) && !in_array($filters['status'], constants::statuses(), true)) {
            unset($filters['status']);
        }
        if (!empty($filters['priority']) && !in_array($filters['priority'], constants::priorities(), true)) {
            unset($filters['priority']);
        }

        $courseids = $this->repository->get_accessible_courseids($userid);

        $result = $this->repository->list_doubts($courseids, $filters, $page, $perpage);

        if (empty($result['records'])) {
            return [
                'pagination' => $this->build_pagination($page, $perpage, $result['total']),
                'records' => [],
            ];
        }

        $courses = [];
        $coursecontexts = [];
        foreach ($result['records'] as $record) {
            if (!isset($courses[$record->courseid])) {
                $courses[$record->courseid] = get_course($record->courseid);
                $coursecontexts[$record->courseid] = context_course::instance($record->courseid);
            }
        }

        $statuses = constants::status_labels();
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        $records = [];

        foreach ($result['records'] as $record) {
            $student = $this->make_name($record->studentfirstname ?? '', $record->studentlastname ?? '');
            $teacher = null;
            if (!empty($record->teacherid)) {
                $teacher = $this->make_name($record->teacherfirstname ?? '', $record->teacherlastname ?? '');
            }

            $records[] = [
                'id' => (int) $record->id,
                'subject' => format_string($record->subject, true, ['context' => $coursecontexts[$record->courseid]]),
                'status' => $record->status,
                'statuslabel' => $statuses[$record->status] ?? $record->status,
                'priority' => $record->priority,
                'prioritylabel' => get_string('doubtpriority:' . $record->priority, 'theme_remui_kids'),
                'course' => [
                    'id' => (int) $record->courseid,
                    'fullname' => format_string($courses[$record->courseid]->fullname ?? '', true),
                    'shortname' => format_string($courses[$record->courseid]->shortname ?? '', true),
                ],
                'student' => [
                    'id' => (int) $record->studentid,
                    'name' => $student,
                    'email' => $record->studentemail,
                ],
                'assigned' => [
                    'id' => empty($record->teacherid) ? null : (int) $record->teacherid,
                    'name' => $teacher,
                ],
                'messagecount' => (int) $record->messagecount,
                'lastactivity' => (int) ($record->lastmessagetime ?? $record->timemodified),
                'lastactivityhuman' => userdate($record->lastmessagetime ?? $record->timemodified, $dateformat),
                'timecreated' => (int) $record->timecreated,
                'timecreatedhuman' => userdate($record->timecreated, $dateformat),
                'timemodified' => (int) $record->timemodified,
                'due' => (int) $record->duedate,
                'gradeband' => $record->gradeband,
            ];
        }

        return [
            'pagination' => $this->build_pagination($page, $perpage, $result['total']),
            'records' => $records,
        ];
    }

    /**
     * Returns teacher summary metrics.
     *
     * @param int $userid
     * @return array
     */
    public function get_summary(int $userid): array {
        $courseids = $this->repository->get_accessible_courseids($userid);
        return $this->repository->get_summary_counts($courseids);
    }

    /**
     * Returns detailed data for a doubt including messages and attachments.
     *
     * @param int $doubtid
     * @param int $userid
     * @return array
     */
    public function get_detail(int $doubtid, int $userid): array {
        global $DB;

        $doubt = $this->repository->get_doubt($doubtid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $context = $this->require_view_capability($doubt, $userid);
        $course = get_course($doubt->courseid);

        $student = $DB->get_record('user', ['id' => $doubt->studentid], '*', MUST_EXIST);
        $assigned = $doubt->assignedto ? $DB->get_record('user', ['id' => $doubt->assignedto], '*', IGNORE_MISSING) : null;

        $messages = $this->repository->get_messages($doubtid);
        $historyrecords = array_values($this->repository->get_status_history($doubtid));

        $fs = get_file_storage();
        $formattedmessages = [];
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');

        foreach ($messages as $message) {
            $msgcontext = $context;
            $filearea = $message->visibility === constants::VISIBILITY_INTERNAL ? 'doubt_internal' : 'doubt_message';
            $files = $fs->get_area_files($msgcontext->id, 'theme_remui_kids', $filearea, $message->id, 'filename', false);

            $filedata = [];
            foreach ($files as $file) {
                $mimetype = (string)$file->get_mimetype();
                $filedata[] = [
                    'filename' => $file->get_filename(),
                    'filesize' => $file->get_filesize(),
                    'mimetype' => $mimetype,
                    'isimage' => strncmp($mimetype, 'image/', 6) === 0,
                    'url' => moodle_url::make_pluginfile_url(
                        $msgcontext->id,
                        'theme_remui_kids',
                        $filearea,
                        $message->id,
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out(false),
                ];
            }

            $fullname = fullname($message);
            if (empty(trim($fullname))) {
                $fullname = get_string('unknownuser');
            }

            $formattedmessages[] = [
                'id' => (int) $message->id,
                'userid' => (int) $message->userid,
                'actorrole' => $message->actorrole,
                'fullname' => $fullname,
                'message' => format_text($message->message, $message->messageformat, ['context' => $context, 'para' => false]),
                'rawmessage' => $message->message,
                'visibility' => $message->visibility,
                'visibility_internal' => $message->visibility === constants::VISIBILITY_INTERNAL,
                'hasattachments' => (bool) $message->hasattachments,
                'attachments' => $filedata,
                'isresolution' => (bool) $message->isresolution,
                'timecreated' => (int) $message->timecreated,
                'timehuman' => userdate($message->timecreated, $dateformat),
            ];
        }

        $statusoptions = array_map(function(string $status) use ($doubt) {
            return [
                'value' => $status,
                'label' => get_string('doubtstatus:' . $status, 'theme_remui_kids'),
                'selected' => $status === $doubt->status,
            ];
        }, constants::statuses());

        return [
            'doubt' => [
                'id' => (int) $doubt->id,
                'subject' => format_string($doubt->subject, true, ['context' => $context]),
                'summary' => format_text($doubt->summary, FORMAT_PLAIN, ['context' => $context, 'para' => true]),
                'status' => $doubt->status,
                'statuslabel' => constants::status_labels()[$doubt->status] ?? $doubt->status,
                'priority' => $doubt->priority,
                'prioritylabel' => get_string('doubtpriority:' . $doubt->priority, 'theme_remui_kids'),
                'course' => [
                    'id' => (int) $course->id,
                    'fullname' => format_string($course->fullname, true, ['context' => $context]),
                ],
                'gradeband' => $doubt->gradeband,
                'timecreated' => (int) $doubt->timecreated,
                'timecreatedhuman' => userdate($doubt->timecreated, $dateformat),
                'timemodified' => (int) $doubt->timemodified,
                'timemodifiedhuman' => userdate($doubt->timemodified, $dateformat),
                'timeresolved' => (int) $doubt->timeresolved,
                'timeresolvedhuman' => $doubt->timeresolved ? userdate($doubt->timeresolved, $dateformat) : null,
                'duedate' => (int) $doubt->duedate,
                'duedatehuman' => $doubt->duedate ? userdate($doubt->duedate, $dateformat) : null,
            ],
            'student' => [
                'id' => (int) $student->id,
                'name' => fullname($student),
                'email' => $student->email,
            ],
            'assigned' => $assigned ? [
                'id' => (int) $assigned->id,
                'name' => fullname($assigned),
                'email' => $assigned->email,
            ] : null,
            'messages' => $formattedmessages,
            'history' => array_map(static function($entry) use ($dateformat) {
                return [
                    'id' => (int) $entry->id,
                    'userid' => (int) $entry->userid,
                    'fullname' => $entry->userid ? fullname($entry) : null,
                    'from' => $entry->oldstatus,
                    'fromlabel' => constants::status_labels()[$entry->oldstatus] ?? $entry->oldstatus,
                    'to' => $entry->newstatus,
                    'tolabel' => constants::status_labels()[$entry->newstatus] ?? $entry->newstatus,
                    'note' => $entry->note,
                    'timecreated' => (int) $entry->timecreated,
                    'timehuman' => userdate($entry->timecreated, $dateformat),
                ];
            }, $historyrecords),
            'statusoptions' => $statusoptions,
        ];
    }

    /**
     * Creates a reply message.
     *
     * @param int $doubtid
     * @param int $userid
     * @param string $message
     * @param int $format
     * @param string $visibility
     * @param bool $resolution
     * @param int|null $draftitemid
     * @return array
     */
    public function reply(int $doubtid, int $userid, string $message, int $format, string $visibility, bool $resolution, ?int $draftitemid = null, array $uploads = []): array {
        global $DB;

        $doubt = $this->repository->get_doubt($doubtid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $context = $this->require_reply_capability($doubt, $userid);

        if (!in_array($visibility, constants::visibilities(), true)) {
            throw new moodle_exception('invalidparameter', 'error');
        }

        if (trim($message) === '' && empty($uploads)) {
            throw new moodle_exception('invalidparameter', 'error');
        }

        $oldstatus = $doubt->status;

        $record = (object) [
            'doubtid' => $doubtid,
            'userid' => $userid,
            'actorrole' => $this->resolve_actor_role($context, $userid),
            'message' => $message,
            'messageformat' => $format,
            'visibility' => $visibility,
            'hasattachments' => 0,
            'isresolution' => $resolution ? 1 : 0,
        ];

        $transaction = $DB->start_delegated_transaction();
        $messageid = $this->repository->insert_message($record);

        $hasattachments = false;
        if (!empty($uploads)) {
            $hasattachments = $this->store_uploaded_files($context, $messageid, $visibility, $uploads);
        } else {
            $hasattachments = $this->process_attachments($context, $messageid, $visibility, $draftitemid);
        }

        if ($hasattachments) {
            $DB->set_field('theme_remui_kids_dbtmsg', 'hasattachments', 1, ['id' => $messageid]);
        }

        $now = time();
        $updates = [
            'lastmessageid' => $messageid,
            'timemodified' => $now,
        ];

        if ($resolution) {
            $updates['status'] = constants::STATUS_RESOLVED;
            $updates['timeresolved'] = $now;
        }

        $this->repository->update_doubt_fields($doubtid, $updates);

        if ($resolution && $doubt->status !== constants::STATUS_RESOLVED) {
            $this->repository->log_status_change((object) [
                'doubtid' => $doubtid,
                'userid' => $userid,
                'oldstatus' => $doubt->status,
                'newstatus' => constants::STATUS_RESOLVED,
                'note' => '',
            ]);
        }

        $transaction->allow_commit();

        $updateddoubt = $this->repository->get_doubt($doubtid) ?? $doubt;

        $this->trigger_reply_event($context, $updateddoubt, $messageid, $visibility, $resolution, $userid);

        if ($visibility === constants::VISIBILITY_PUBLIC) {
            $this->notify_student_reply($context, $updateddoubt, $userid, $message, $format);
        }

        if ($resolution && $oldstatus !== constants::STATUS_RESOLVED) {
            $this->trigger_status_event($context, $updateddoubt, $oldstatus, constants::STATUS_RESOLVED, $userid);
            $this->notify_student_status($context, $updateddoubt, $userid, $oldstatus, constants::STATUS_RESOLVED);
        }

        return $this->get_detail($doubtid, $userid);
    }

    /**
     * Updates status of a doubt.
     *
     * @param int $doubtid
     * @param int $userid
     * @param string $status
     * @param string|null $note
     * @return array
     */
    public function update_status(int $doubtid, int $userid, string $status, ?string $note = null): array {
        $doubt = $this->repository->get_doubt($doubtid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $context = $this->require_manage_capability($doubt, $userid);

        if (!in_array($status, constants::statuses(), true)) {
            throw new moodle_exception('invalidparameter', 'error');
        }

        if ($status === $doubt->status) {
            return $this->get_detail($doubtid, $userid);
        }

        $this->repository->update_doubt_fields($doubtid, [
            'status' => $status,
            'timeresolved' => $status === constants::STATUS_RESOLVED ? time() : $doubt->timeresolved,
        ]);

        $this->repository->log_status_change((object) [
            'doubtid' => $doubtid,
            'userid' => $userid,
            'oldstatus' => $doubt->status,
            'newstatus' => $status,
            'note' => $note,
        ]);

        $updated = $this->repository->get_doubt($doubtid) ?? $doubt;
        $this->trigger_status_event($context, $updated, $doubt->status, $status, $userid);
        $this->notify_student_status($context, $updated, $userid, $doubt->status, $status);

        return $this->get_detail($doubtid, $userid);
    }

    /**
     * Assigns the doubt to a teacher.
     *
     * @param int $doubtid
     * @param int $userid
     * @param int|null $assigneeid null to unassign
     * @return array
     */
    public function assign(int $doubtid, int $userid, ?int $assigneeid): array {
        global $DB;

        $doubt = $this->repository->get_doubt($doubtid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $context = $this->require_manage_capability($doubt, $userid);

        if ($assigneeid) {
            $assignee = $DB->get_record('user', ['id' => $assigneeid], '*', IGNORE_MISSING);
            if (!$assignee) {
                throw new moodle_exception('invaliduser', 'error');
            }
        }

        $this->repository->update_doubt_fields($doubtid, [
            'assignedto' => $assigneeid ?? 0,
        ]);

        return $this->get_detail($doubtid, $userid);
    }

    /**
     * Builds pagination payload.
     */
    private function build_pagination(int $page, int $perpage, int $total): array {
        $pages = $perpage > 0 ? (int) ceil($total / $perpage) : 0;

        return [
            'page' => $page,
            'perpage' => $perpage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * Formats name fallback.
     */
    private function make_name(?string $firstname, ?string $lastname): string {
        $name = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));
        if ($name === '') {
            $name = get_string('unknownuser');
        }
        return $name;
    }

    /**
     * Ensures user can view the doubt.
     */
    private function require_view_capability(stdClass $doubt, int $userid): context {
        $context = context::instance_by_id($doubt->contextid, MUST_EXIST);
        if (!has_any_capability([
            'theme/remui_kids:viewdoubts',
            'theme/remui_kids:replydoubts',
            'theme/remui_kids:managedoubts'
        ], $context, $userid)) {
            throw new required_capability_exception($context, 'theme/remui_kids:viewdoubts', 'nopermissions', '');
        }

        return $context;
    }

    /**
     * Ensures user can reply.
     */
    private function require_reply_capability(stdClass $doubt, int $userid): context {
        $context = context::instance_by_id($doubt->contextid, MUST_EXIST);
        if (!has_any_capability([
            'theme/remui_kids:replydoubts',
            'theme/remui_kids:managedoubts'
        ], $context, $userid)) {
            throw new required_capability_exception($context, 'theme/remui_kids:replydoubts', 'nopermissions', '');
        }
        return $context;
    }

    /**
     * Ensures user can manage.
     */
    private function require_manage_capability(stdClass $doubt, int $userid): context {
        $context = context::instance_by_id($doubt->contextid, MUST_EXIST);
        if (!has_capability('theme/remui_kids:managedoubts', $context, $userid)) {
            throw new required_capability_exception($context, 'theme/remui_kids:managedoubts', 'nopermissions', '');
        }
        return $context;
    }

    /**
     * Stores attachments from draft area and metadata.
     */
    private function process_attachments(context $context, int $messageid, string $visibility, ?int $draftitemid): bool {
        global $CFG;

        if (empty($draftitemid)) {
            return false;
        }

        require_once($CFG->libdir . '/filelib.php');

        $filearea = $visibility === constants::VISIBILITY_INTERNAL ? 'doubt_internal' : 'doubt_message';

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'theme_remui_kids',
            $filearea,
            $messageid,
            [
                'subdirs' => 0,
                'maxbytes' => 0,
                'maxfiles' => -1,
            ]
        );

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'theme_remui_kids', $filearea, $messageid, 'filename', false);

        $this->repository->delete_attachments_for_message($messageid);

        foreach ($files as $file) {
            $this->repository->insert_attachment((object) [
                'messageid' => $messageid,
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'mimetype' => $file->get_mimetype(),
                'filesize' => $file->get_filesize(),
                'filehash' => $file->get_pathnamehash(),
            ]);
        }

        return !empty($files);
    }

    /**
     * Stores uploaded files directly from request without draft area.
     */
    private function store_uploaded_files(context $context, int $messageid, string $visibility, array $uploads): bool {
        if (empty($uploads)) {
            return false;
        }

        $fs = get_file_storage();
        $filearea = $visibility === constants::VISIBILITY_INTERNAL ? 'doubt_internal' : 'doubt_message';

        $this->repository->delete_attachments_for_message($messageid);

        $stored = 0;

        foreach ($uploads as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                continue;
            }

            $cleanname = clean_param($file['name'], PARAM_FILE);
            if ($cleanname === '') {
                continue;
            }

            $record = [
                'contextid' => $context->id,
                'component' => 'theme_remui_kids',
                'filearea' => $filearea,
                'itemid' => $messageid,
                'filepath' => '/',
                'filename' => $cleanname,
            ];

            $storedfile = $fs->create_file_from_pathname($record, $file['tmp_name']);
            if ($storedfile) {
                $this->repository->insert_attachment((object) [
                    'messageid' => $messageid,
                    'filename' => $storedfile->get_filename(),
                    'filepath' => $storedfile->get_filepath(),
                    'mimetype' => $storedfile->get_mimetype(),
                    'filesize' => $storedfile->get_filesize(),
                    'filehash' => $storedfile->get_pathnamehash(),
                ]);
                $stored++;
            }
        }

        return $stored > 0;
    }

    /**
     * Derives actor role label for logging.
     */
    private function trigger_reply_event(context $context, stdClass $doubt, int $messageid, string $visibility, bool $resolution, int $userid): void {
        $event = \theme_remui_kids\event\doubt_replied::create([
            'objectid' => $messageid,
            'context' => $context,
            'userid' => $userid,
            'relateduserid' => $doubt->studentid,
            'other' => [
                'doubtid' => $doubt->id,
                'visibility' => $visibility,
                'isresolution' => $resolution,
            ],
        ]);
        $event->trigger();
    }

    private function trigger_status_event(context $context, stdClass $doubt, string $from, string $to, int $userid): void {
        $event = \theme_remui_kids\event\doubt_status_updated::create([
            'objectid' => $doubt->id,
            'context' => $context,
            'userid' => $userid,
            'relateduserid' => $doubt->studentid,
            'other' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
        $event->trigger();
    }

    private function notify_student_reply(context $context, stdClass $doubt, int $userid, string $message, int $format): void {
        global $CFG;

        if (empty($doubt->studentid) || $doubt->studentid == $userid) {
            return;
        }

        require_once($CFG->dirroot . '/message/lib.php');

        $student = core_user::get_user($doubt->studentid, '*', MUST_EXIST);
        $teacher = core_user::get_user($userid, '*', MUST_EXIST);
        $course = get_course($doubt->courseid);

        $plainmessage = trim(format_text($message, $format, [
            'context' => $context,
            'para' => false,
            'plain' => true,
        ]));

        $url = new moodle_url('/theme/remui_kids/pages/teacher_doubts.php', ['doubtid' => $doubt->id]);

        $stringdata = (object) [
            'student' => fullname($student),
            'teacher' => fullname($teacher),
            'subject' => format_string($doubt->subject, true, ['context' => $context]),
            'course' => format_string($course->fullname, true, ['context' => $context]),
            'message' => $plainmessage,
            'link' => $url->out(false),
        ];

        $notificationsubject = get_string('message_doubtreply_subject', 'theme_remui_kids', $stringdata);
        $notificationbody = get_string('message_doubtreply_body', 'theme_remui_kids', $stringdata);
        $notificationhtml = nl2br(htmlspecialchars($notificationbody, ENT_QUOTES | ENT_SUBSTITUTE));

        $msg = new \core\message\message();
        $msg->component = 'theme_remui_kids';
        $msg->name = 'doubtreply';
        $msg->userfrom = $teacher;
        $msg->userto = $student;
        $msg->subject = $notificationsubject;
        $msg->fullmessage = $notificationbody;
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->fullmessagehtml = $notificationhtml;
        $msg->smallmessage = get_string('message_doubtreply_short', 'theme_remui_kids', (object) ['teacher' => fullname($teacher)]);
        $msg->courseid = $doubt->courseid;
        $msg->contexturl = $url->out(false);
        $msg->contexturlname = get_string('teacher_doubts', 'theme_remui_kids');
        $msg->notification = 1;

        message_send($msg);
    }

    private function notify_student_status(context $context, stdClass $doubt, int $userid, string $from, string $to): void {
        global $CFG;

        if (empty($doubt->studentid) || $doubt->studentid == $userid) {
            return;
        }

        require_once($CFG->dirroot . '/message/lib.php');

        $student = core_user::get_user($doubt->studentid, '*', MUST_EXIST);
        $teacher = core_user::get_user($userid, '*', MUST_EXIST);
        $labels = constants::status_labels();
        $url = new moodle_url('/theme/remui_kids/pages/teacher_doubts.php', ['doubtid' => $doubt->id]);

        $stringdata = (object) [
            'student' => fullname($student),
            'subject' => format_string($doubt->subject, true, ['context' => $context]),
            'from' => $labels[$from] ?? $from,
            'to' => $labels[$to] ?? $to,
            'link' => $url->out(false),
        ];

        $subject = get_string('message_doubtstatus_subject', 'theme_remui_kids', $stringdata);
        $body = get_string('message_doubtstatus_body', 'theme_remui_kids', $stringdata);
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE));

        $msg = new \core\message\message();
        $msg->component = 'theme_remui_kids';
        $msg->name = 'doubtstatus';
        $msg->userfrom = $teacher;
        $msg->userto = $student;
        $msg->subject = $subject;
        $msg->fullmessage = $body;
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->fullmessagehtml = $html;
        $msg->smallmessage = get_string('message_doubtstatus_short', 'theme_remui_kids', (object) ['to' => $stringdata->to]);
        $msg->courseid = $doubt->courseid;
        $msg->contexturl = $url->out(false);
        $msg->contexturlname = get_string('teacher_doubts', 'theme_remui_kids');
        $msg->notification = 1;

        message_send($msg);
    }

    private function resolve_actor_role(context $context, int $userid): string {
        if (has_capability('theme/remui_kids:managedoubts', $context, $userid)) {
            return 'manager';
        }
        if (has_capability('theme/remui_kids:replydoubts', $context, $userid)) {
            return 'teacher';
        }
        return 'student';
    }

    private function display_fullname(stdClass $userrecord): string {
        $name = trim(($userrecord->firstname ?? '') . ' ' . ($userrecord->lastname ?? ''));
        if ($name === '') {
            $name = get_string('unknownuser');
        }
        return $name;
    }
}

