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

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/classes/local/secure_file_token.php');

use theme_remui_kids\local\secure_file_token;

$fileid = required_param('fileid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$expires = required_param('expires', PARAM_INT);
$token = required_param('token', PARAM_ALPHANUMEXT);
$download = optional_param('download', 0, PARAM_BOOL);

if (!secure_file_token::validate($fileid, $userid, $expires, $token)) {
    throw new moodle_exception('invalidtoken', 'error');
}

$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);

if (!$file || $file->is_directory()) {
    throw new moodle_exception('filenotfound', 'error');
}

// Allow Microsoft/Google viewers to fetch the file by marking it public.
send_stored_file(
    $file,
    0,
    0,
    (bool)$download,
    [
        'cacheability' => 'public',
        'dontdie' => false,
    ]
);
