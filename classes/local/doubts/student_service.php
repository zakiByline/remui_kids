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
use context_course;
use moodle_exception;
use moodle_url;
use stdClass;

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Service layer for student-facing doubt interactions.
 *
 * @package theme_remui_kids
 */
class student_service {
    /** @var repository */
    private $repository;

    /**
     * @param repository|null $repository
     */
    public function __construct(?repository $repository = null) {
        $this->repository = $repository ?? new repository();
    }

    /**
     * Returns list data for a student's doubts.
     *
     * @param int $studentid
     * @param int|null $courseid
     * @return array
     */
    public function list(int $studentid, ?int $courseid = null): array {
        $records = $this->repository->list_doubts_for_student($studentid, $courseid);
        $statuses = constants::status_labels();
        $priorities = constants::priorities();
        $prioritylabels = [];
        foreach ($priorities as $priority) {
            $prioritylabels[$priority] = get_string('doubtpriority:' . $priority, 'theme_remui_kids');
        }

        $dateformat = get_string('strftimedatetimeshort', 'langconfig');

        return array_map(static function($record) use ($statuses, $prioritylabels, $dateformat) {
            return [
                'id' => (int) $record->id,
                'subject' => format_string($record->subject, true),
                'status' => $record->status,
                'statuslabel' => $statuses[$record->status] ?? $record->status,
                'priority' => $record->priority,
                'prioritylabel' => $prioritylabels[$record->priority] ?? $record->priority,
                'course' => format_string($record->coursefullname ?? '', true),
                'timecreated' => (int) $record->timecreated,
                'timecreatedhuman' => userdate($record->timecreated, $dateformat),
                'timemodified' => (int) $record->timemodified,
                'timemodifiedhuman' => userdate($record->timemodified, $dateformat),
                'isresolved' => $record->status === constants::STATUS_RESOLVED,
            ];
        }, $records);
    }

    /**
     * Creates a doubt for the given student.
     *
     * @param int $studentid
     * @param int $courseid
     * @param string $subject
     * @param string $details
     * @param string $priority
     * @return int
     * @throws moodle_exception
     */
    public function create(int $studentid, int $courseid, string $subject, string $details, string $priority, array $uploads = []): int {
        global $DB;

        $subject = trim($subject);
        $details = trim($details);

        if ($subject === '') {
            throw new moodle_exception('student_doubt_error_subject', 'theme_remui_kids');
        }

        if ($details === '') {
            throw new moodle_exception('student_doubt_error_details', 'theme_remui_kids');
        }

        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid])) {
            throw new moodle_exception('student_doubt_error_course', 'theme_remui_kids');
        }

        $context = context_course::instance($courseid);
        if (!is_enrolled($context, $studentid)) {
            throw new moodle_exception('student_doubt_error_course', 'theme_remui_kids');
        }

        $allowedpriorities = constants::priorities();
        if (!in_array($priority, $allowedpriorities, true)) {
            $priority = constants::PRIORITY_NORMAL;
        }

        $record = (object) [
            'courseid' => $courseid,
            'contextid' => $context->id,
            'cmid' => 0,
            'studentid' => $studentid,
            'assignedto' => 0,
            'subject' => $subject,
            'summary' => $details,
            'status' => constants::STATUS_OPEN,
            'priority' => $priority,
        ];

        $doubtid = $this->repository->create_doubt($record);

        $messageid = $this->repository->insert_message((object) [
            'doubtid' => $doubtid,
            'userid' => $studentid,
            'actorrole' => 'student',
            'message' => $details,
            'messageformat' => FORMAT_PLAIN,
            'visibility' => constants::VISIBILITY_PUBLIC,
            'hasattachments' => 0,
            'isresolution' => 0,
        ]);

        if (!empty($uploads)) {
            $context = context_course::instance($courseid);
            if ($this->store_uploaded_files($context, $messageid, $uploads)) {
                $DB->set_field('theme_remui_kids_dbtmsg', 'hasattachments', 1, ['id' => $messageid]);
            }
        }

        return $doubtid;
    }

    /**
     * Returns detailed information about a student's doubt.
     *
     * @param int $doubtid
     * @param int $studentid
     * @return array
     * @throws moodle_exception
     */
    public function get_detail(int $doubtid, int $studentid): array {
        $doubt = $this->repository->get_doubt_for_student($doubtid, $studentid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $context = context_course::instance($doubt->courseid);
        $messages = $this->repository->get_messages_for_student($doubtid);
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');

        $fs = get_file_storage();
        $attachmentslabel = get_string('doubt_uploaded_files', 'theme_remui_kids');
        $formattedmessages = [];

        foreach ($messages as $message) {
            $fullname = fullname($message);
            if (empty(trim($fullname))) {
                $fullname = get_string('unknownuser');
            }

            $files = $fs->get_area_files($context->id, 'theme_remui_kids', 'doubt_message', $message->id, 'filename', false);
            $filedata = [];
            foreach ($files as $file) {
                $filedata[] = [
                    'filename' => $file->get_filename(),
                    'filesize' => $file->get_filesize(),
                    'mimetype' => $file->get_mimetype(),
                    'url' => moodle_url::make_pluginfile_url(
                        $context->id,
                        'theme_remui_kids',
                        'doubt_message',
                        $message->id,
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out(false),
                ];
            }

            $formattedmessages[] = [
                'id' => (int) $message->id,
                'userid' => (int) $message->userid,
                'isstudent' => ((int) $message->userid === (int) $studentid),
                'actorrole' => $message->actorrole ?? null,
                'fullname' => $fullname,
                'message' => format_text($message->message, $message->messageformat, ['context' => $context, 'para' => false]),
                'timehuman' => userdate($message->timecreated, $dateformat),
                'hasattachments' => !empty($filedata),
                'attachments' => $filedata,
                'attachmentslabel' => $attachmentslabel,
            ];
        }

        $statuses = constants::status_labels();

        $course = '';
        if (!empty($doubt->courseid)) {
            $course = format_string($doubt->coursefullname ?? '', true, ['context' => $context]);
        }

        return [
            'doubt' => [
                'id' => (int) $doubt->id,
                'subject' => format_string($doubt->subject, true, ['context' => $context]),
                'summary' => format_text($doubt->summary, FORMAT_PLAIN, ['context' => $context, 'para' => true]),
                'status' => $doubt->status,
                'statuslabel' => $statuses[$doubt->status] ?? $doubt->status,
                'priority' => $doubt->priority,
                'prioritylabel' => get_string('doubtpriority:' . $doubt->priority, 'theme_remui_kids'),
                'timemodifiedhuman' => userdate($doubt->timemodified, $dateformat),
                'course' => $course,
            ],
            'messages' => $formattedmessages,
            'messagescount' => count($formattedmessages),
        ];
    }

    /**
     * Appends a reply message from the student to an existing doubt.
     *
     * @param int $doubtid
     * @param int $studentid
     * @param string $message
     * @param array $uploads
     * @return array
     * @throws moodle_exception
     */
    public function reply(int $doubtid, int $studentid, string $message, array $uploads = []): array {
        global $DB;

        $doubt = $this->repository->get_doubt_for_student($doubtid, $studentid);
        if (!$doubt) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $message = trim($message);
        if ($message === '' && empty($uploads)) {
            throw new moodle_exception('student_doubt_error_details', 'theme_remui_kids');
        }

        $context = context_course::instance($doubt->courseid);

        $record = (object) [
            'doubtid' => $doubtid,
            'userid' => $studentid,
            'actorrole' => 'student',
            'message' => $message,
            'messageformat' => FORMAT_PLAIN,
            'visibility' => constants::VISIBILITY_PUBLIC,
            'hasattachments' => 0,
            'isresolution' => 0,
        ];

        $messageid = $this->repository->insert_message($record);

        $hasattachments = false;
        if (!empty($uploads)) {
            $hasattachments = $this->store_uploaded_files($context, $messageid, $uploads);
        }

        if ($hasattachments) {
            $DB->set_field('theme_remui_kids_dbtmsg', 'hasattachments', 1, ['id' => $messageid]);
        }

        $newstatus = $doubt->status;
        if ($doubt->status === constants::STATUS_WAITING_STUDENT) {
            $newstatus = constants::STATUS_INPROGRESS;
        } else if (in_array($doubt->status, [constants::STATUS_RESOLVED, constants::STATUS_ARCHIVED], true)) {
            $newstatus = constants::STATUS_OPEN;
        }

        $updates = [
            'lastmessageid' => $messageid,
            'timemodified' => time(),
        ];

        if ($newstatus !== $doubt->status) {
            $updates['status'] = $newstatus;
            if ($newstatus !== constants::STATUS_RESOLVED) {
                $updates['timeresolved'] = 0;
            }
        }

        $this->repository->update_doubt_fields($doubtid, $updates);

        return $this->get_detail($doubtid, $studentid);
    }

    /**
     * Stores uploaded attachments for a student message.
     *
     * @param context_course $context
     * @param int $messageid
     * @param array $uploads
     * @return bool
     */
    private function store_uploaded_files(context_course $context, int $messageid, array $uploads): bool {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $fs = get_file_storage();
        $stored = false;

        foreach ($uploads as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
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
                'filearea' => 'doubt_message',
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
                $stored = true;
            }
        }

        return $stored;
    }
}

