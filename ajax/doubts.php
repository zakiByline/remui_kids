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

define('AJAX_SCRIPT', true);

require_once('../../../config.php');

use context_system;
use theme_remui_kids\local\doubts\constants;
use theme_remui_kids\local\doubts\service;

require_login();

$PAGE->set_context(context_system::instance());

require_sesskey();

$action = required_param('action', PARAM_ALPHAEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$filters = optional_param_array('filters', [], PARAM_RAW);

$service = new service();

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'list':
            $safe = [];
            if (!empty($filters['status'])) {
                $safe['status'] = clean_param($filters['status'], PARAM_ALPHANUMEXT);
            }
            if (!empty($filters['priority'])) {
                $safe['priority'] = clean_param($filters['priority'], PARAM_ALPHANUMEXT);
            }
            if (!empty($filters['assigned'])) {
                $assigned = clean_param($filters['assigned'], PARAM_RAW_TRIMMED);
                if ($assigned === 'self') {
                    $safe['assigned'] = (string) $USER->id;
                } else {
                    $safe['assigned'] = $assigned;
                }
            }
            if (!empty($filters['search'])) {
                $safe['search'] = clean_param($filters['search'], PARAM_RAW_TRIMMED);
            }

            $data = $service->list_for_teacher($USER->id, $safe, max(0, $page), max(1, $perpage));
            $data['summary'] = $service->get_summary($USER->id);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'detail':
            $doubtid = required_param('doubtid', PARAM_INT);
            $data = $service->get_detail($doubtid, $USER->id);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'reply':
            $doubtid = required_param('doubtid', PARAM_INT);
            $message = required_param('message', PARAM_RAW);
            $messageformat = optional_param('format', FORMAT_HTML, PARAM_INT);
            $visibility = optional_param('visibility', constants::VISIBILITY_PUBLIC, PARAM_ALPHANUMEXT);
            $resolution = optional_param('resolution', 0, PARAM_BOOL);
            $draftitemid = optional_param('draftitemid', 0, PARAM_INT);
            $uploads = [];

            if (!empty($_FILES['attachments'])) {
                $names = $_FILES['attachments']['name'];
                $tmp = $_FILES['attachments']['tmp_name'];
                $types = $_FILES['attachments']['type'];
                $errors = $_FILES['attachments']['error'];
                $sizes = $_FILES['attachments']['size'];

                if (is_array($names)) {
                    foreach ($names as $idx => $filename) {
                        if ($filename === '') {
                            continue;
                        }
                        $uploads[] = [
                            'name' => $filename,
                            'tmp_name' => $tmp[$idx],
                            'type' => $types[$idx],
                            'error' => $errors[$idx],
                            'size' => $sizes[$idx],
                        ];
                    }
                } else {
                    if ($names !== '') {
                        $uploads[] = [
                            'name' => $names,
                            'tmp_name' => $tmp,
                            'type' => $types,
                            'error' => $errors,
                            'size' => $sizes,
                        ];
                    }
                }
            }

            $data = $service->reply(
                $doubtid,
                $USER->id,
                $message,
                $messageformat,
                $visibility,
                (bool) $resolution,
                $draftitemid ?: null,
                $uploads
            );
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'status':
            $doubtid = required_param('doubtid', PARAM_INT);
            $status = required_param('status', PARAM_ALPHANUMEXT);
            $note = optional_param('note', '', PARAM_RAW_TRIMMED);
            $data = $service->update_status($doubtid, $USER->id, $status, $note ?: null);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'assign':
            $doubtid = required_param('doubtid', PARAM_INT);
            $assigneeid = optional_param('assigneeid', 0, PARAM_INT);
            $data = $service->assign($doubtid, $USER->id, $assigneeid ?: null);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        default:
            throw new moodle_exception('invalidparameter', 'error');
    }
} catch (\Throwable $e) {
    http_response_code(400);
    debugging($e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

die();

