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
 * Preview Error Logging Endpoint
 * Logs preview loading errors from JavaScript
 */

require_once(__DIR__ . '/../../../config.php');

// Require login
require_login();

$type = optional_param('type', '', PARAM_ALPHA); // 'ppt', 'pdf', etc.
$src = optional_param('src', '', PARAM_URL);
$filename = optional_param('filename', '', PARAM_TEXT);
$fileid = optional_param('fileid', '', PARAM_TEXT);

// Log the error
$error_message = sprintf(
    "Preview Error: type=%s, filename=%s, fileid=%s, src=%s, userid=%d",
    $type,
    $filename,
    $fileid,
    $src,
    $USER->id
);

error_log($error_message);

// Return success response
header('Content-Type: application/json');
echo json_encode(['success' => true]);



