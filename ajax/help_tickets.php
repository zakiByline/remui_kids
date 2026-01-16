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
 * AJAX handler for help ticket operations
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

require_login();
require_sesskey();

// Get action from POST or GET
$action = optional_param('action', '', PARAM_ALPHAEXT);

// If action is empty, try getting it from $_POST or $_GET directly (for FormData compatibility)
if (empty($action)) {
    if (isset($_POST['action'])) {
        $action = clean_param($_POST['action'], PARAM_ALPHAEXT);
    } else if (isset($_GET['action'])) {
        $action = clean_param($_GET['action'], PARAM_ALPHAEXT);
    }
}

header('Content-Type: application/json');

try {
    if (empty($action)) {
        throw new Exception('Invalid action parameter - no action received');
    }

    switch ($action) {
        case 'create_ticket':
            echo json_encode(create_ticket());
            break;

        case 'list_tickets':
            echo json_encode(list_tickets());
            break;

        case 'get_ticket':
            $ticketid = required_param('ticket_id', PARAM_INT);
            echo json_encode(get_ticket($ticketid));
            break;

        case 'add_message':
            echo json_encode(add_message());
            break;

        case 'mark_read':
            $ticketid = required_param('ticket_id', PARAM_INT);
            echo json_encode(mark_ticket_read($ticketid));
            break;

        case 'unread_count':
            echo json_encode(get_unread_count());
            break;

        case 'update_status':
            echo json_encode(update_ticket_status());
            break;

        case 'assign_ticket':
            echo json_encode(assign_ticket());
            break;

        case 'list_all_tickets':
            // Admin only - list all tickets
            echo json_encode(list_all_tickets());
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Create a new help ticket
 *
 * @return array Response data
 */
function create_ticket() {
    global $DB, $USER;

    // Get parameters - try both methods for FormData compatibility
    $category = optional_param('category', '', PARAM_ALPHAEXT);
    $subject = optional_param('subject', '', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_RAW);
    $priority = optional_param('priority', 'normal', PARAM_ALPHAEXT);

    // Fallback to direct $_POST access if params are empty (FormData issue)
    if (empty($category) && isset($_POST['category'])) {
        $category = clean_param($_POST['category'], PARAM_ALPHAEXT);
    }
    if (empty($subject) && isset($_POST['subject'])) {
        $subject = clean_param($_POST['subject'], PARAM_TEXT);
    }
    if (empty($description) && isset($_POST['description'])) {
        $description = clean_param($_POST['description'], PARAM_RAW);
    }
    if (empty($priority) || $priority === 'normal') {
        if (isset($_POST['priority'])) {
            $priority = clean_param($_POST['priority'], PARAM_ALPHAEXT);
        }
    }

    // Validate inputs
    if (empty($category)) {
        throw new Exception('Category is required');
    }
    if (empty($subject)) {
        throw new Exception('Subject is required');
    }
    if (empty($description)) {
        throw new Exception('Description is required');
    }

    // Generate unique ticket number
    $ticketnumber = generate_ticket_number();

    // Create ticket record
    $ticket = new stdClass();
    $ticket->ticketnumber = $ticketnumber;
    $ticket->userid = $USER->id;
    $ticket->category = $category;
    $ticket->subject = $subject;
    $ticket->description = $description;
    $ticket->status = 'open';
    $ticket->priority = $priority;
    $ticket->assignedto = 0;
    $ticket->lastmessageid = 0;
    $ticket->timecreated = time();
    $ticket->timemodified = time();
    $ticket->timeresolved = 0;

    $ticketid = $DB->insert_record('theme_remui_kids_helptickets', $ticket);

    // Create initial message
    $message = new stdClass();
    $message->ticketid = $ticketid;
    $message->userid = $USER->id;
    $message->message = $description;
    $message->messageformat = FORMAT_HTML;
    $message->isadmin = 0;
    $message->isinternal = 0;
    $message->hasattachments = 0;
    $message->timecreated = time();
    $message->timemodified = time();

    $messageid = $DB->insert_record('theme_remui_kids_helpticket_msgs', $message);

    // Update ticket with last message id
    $DB->set_field('theme_remui_kids_helptickets', 'lastmessageid', $messageid, ['id' => $ticketid]);

    // Handle file uploads
    if (!empty($_FILES['files'])) {
        handle_file_uploads($ticketid, $messageid);
        $DB->set_field('theme_remui_kids_helpticket_msgs', 'hasattachments', 1, ['id' => $messageid]);
    }

    // Send notification to admins
    notify_admins_new_ticket($ticketid);

    return [
        'success' => true,
        'ticketid' => $ticketid,
        'ticketnumber' => $ticketnumber,
        'message' => 'Ticket created successfully'
    ];
}

/**
 * List user's tickets
 *
 * @return array Response data
 */
function list_tickets() {
    global $DB, $USER;

    $tickets = $DB->get_records('theme_remui_kids_helptickets', 
        ['userid' => $USER->id], 
        'timecreated DESC'
    );

    $result = [];
    foreach ($tickets as $ticket) {
        // Count unread messages (admin replies user hasn't seen)
        $sql = "SELECT COUNT(*) 
                FROM {theme_remui_kids_helpticket_msgs} m
                LEFT JOIN {theme_remui_kids_helpticket_reads} r ON r.messageid = m.id AND r.userid = :userid
                WHERE m.ticketid = :ticketid 
                AND m.isadmin = 1
                AND r.id IS NULL";
        
        $unread = 0;
        try {
            // Check if reads table exists
            if ($DB->get_manager()->table_exists(new xmldb_table('theme_remui_kids_helpticket_reads'))) {
                $unread = $DB->count_records_sql($sql, [
                    'ticketid' => $ticket->id,
                    'userid' => $USER->id
                ]);
            }
        } catch (Exception $e) {
            // Table doesn't exist yet, skip unread count
        }

        $result[] = [
            'id' => $ticket->id,
            'ticketnumber' => $ticket->ticketnumber,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'description' => substr(strip_tags($ticket->description), 0, 150) . '...',
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'timecreated' => $ticket->timecreated,
            'timeago' => format_time_ago($ticket->timecreated),
            'unread' => $unread
        ];
    }

    return [
        'success' => true,
        'tickets' => $result
    ];
}

/**
 * Get ticket details with messages
 *
 * @param int $ticketid Ticket ID
 * @return array Response data
 */
function get_ticket($ticketid) {
    global $DB, $USER;

    $ticket = $DB->get_record('theme_remui_kids_helptickets', ['id' => $ticketid], '*', MUST_EXIST);

    // Check permission
    $isadmin = is_siteadmin() || has_capability('moodle/site:config', context_system::instance());
    if ($ticket->userid != $USER->id && !$isadmin) {
        throw new moodle_exception('nopermission', 'error');
    }

    // Get messages
    $messages = $DB->get_records('theme_remui_kids_helpticket_msgs', 
        ['ticketid' => $ticketid], 
        'timecreated ASC'
    );

    $messagesarray = [];
    foreach ($messages as $msg) {
        $user = $DB->get_record('user', ['id' => $msg->userid], 'id, firstname, lastname');
        
        $msgdata = [
            'id' => $msg->id,
            'message' => $msg->message,
            'userid' => $msg->userid,
            'username' => fullname($user),
            'isadmin' => $msg->isadmin,
            'timecreated' => $msg->timecreated,
            'timeago' => format_time_ago($msg->timecreated),
            'attachments' => []
        ];

        // Get attachments
        if ($msg->hasattachments) {
            $files = $DB->get_records('theme_remui_kids_helpticket_files', ['messageid' => $msg->id]);
            foreach ($files as $file) {
                $msgdata['attachments'][] = [
                    'filename' => $file->filename,
                    'url' => get_file_url($file),
                    'size' => $file->filesize
                ];
            }
        }

        $messagesarray[] = $msgdata;
    }

    return [
        'success' => true,
        'ticket' => [
            'id' => $ticket->id,
            'ticketnumber' => $ticket->ticketnumber,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'timecreated' => $ticket->timecreated,
            'timeago' => format_time_ago($ticket->timecreated)
        ],
        'messages' => $messagesarray
    ];
}

/**
 * Add a message to a ticket
 *
 * @return array Response data
 */
function add_message() {
    global $DB, $USER;

    $ticketid = optional_param('ticket_id', 0, PARAM_INT);
    $messagetext = optional_param('message', '', PARAM_RAW);

    // Fallback to direct $_POST access
    if (empty($ticketid) && isset($_POST['ticket_id'])) {
        $ticketid = clean_param($_POST['ticket_id'], PARAM_INT);
    }
    if (empty($messagetext) && isset($_POST['message'])) {
        $messagetext = clean_param($_POST['message'], PARAM_RAW);
    }

    if (empty($ticketid)) {
        throw new Exception('Ticket ID is required');
    }
    if (empty($messagetext)) {
        throw new Exception('Message is required');
    }

    $ticket = $DB->get_record('theme_remui_kids_helptickets', ['id' => $ticketid], '*', MUST_EXIST);

    // Check permission
    $isadmin = is_siteadmin() || has_capability('moodle/site:config', context_system::instance());
    if ($ticket->userid != $USER->id && !$isadmin) {
        throw new moodle_exception('nopermission', 'error');
    }

    // Create message
    $message = new stdClass();
    $message->ticketid = $ticketid;
    $message->userid = $USER->id;
    $message->message = $messagetext;
    $message->messageformat = FORMAT_HTML;
    $message->isadmin = $isadmin ? 1 : 0;
    $message->isinternal = 0;
    $message->hasattachments = 0;
    $message->timecreated = time();
    $message->timemodified = time();

    $messageid = $DB->insert_record('theme_remui_kids_helpticket_msgs', $message);

    // Update ticket
    $ticket->lastmessageid = $messageid;
    $ticket->timemodified = time();
    
    // If user is replying, set status to open if it was resolved
    if (!$isadmin && $ticket->status == 'resolved') {
        $ticket->status = 'open';
    }
    // If admin is replying, set to in_progress
    if ($isadmin && $ticket->status == 'open') {
        $ticket->status = 'in_progress';
    }

    $DB->update_record('theme_remui_kids_helptickets', $ticket);

    // Send notification
    if ($isadmin) {
        notify_user_reply($ticketid, $ticket->userid);
    } else {
        notify_admins_reply($ticketid);
    }

    return [
        'success' => true,
        'messageid' => $messageid
    ];
}

/**
 * Mark ticket as read
 *
 * @param int $ticketid Ticket ID
 * @return array Response data
 */
function mark_ticket_read($ticketid) {
    global $DB, $USER;

    // Get all admin messages in this ticket that user hasn't read
    $sql = "SELECT m.id 
            FROM {theme_remui_kids_helpticket_msgs} m
            WHERE m.ticketid = :ticketid 
            AND m.isadmin = 1
            AND m.userid != :userid";
    
    $messages = $DB->get_records_sql($sql, [
        'ticketid' => $ticketid,
        'userid' => $USER->id
    ]);

    // Mark all as read (we'll create a simple tracking mechanism)
    // For now, we'll just track in session or use a timestamp approach
    
    return [
        'success' => true
    ];
}

/**
 * Get unread ticket count for current user
 *
 * @return array Response data
 */
function get_unread_count() {
    global $DB, $USER;

    // Count only open tickets (exclude resolved and closed)
    $sql = "SELECT COUNT(DISTINCT t.id)
            FROM {theme_remui_kids_helptickets} t
            JOIN {theme_remui_kids_helpticket_msgs} m ON m.ticketid = t.id
            WHERE t.userid = :userid
            AND t.status IN ('open', 'in_progress')
            AND m.isadmin = 1
            AND m.timecreated > COALESCE(
                (SELECT MAX(m2.timecreated) 
                 FROM {theme_remui_kids_helpticket_msgs} m2 
                 WHERE m2.ticketid = t.id 
                 AND m2.isadmin = 0), 
                0
            )";

    $count = $DB->count_records_sql($sql, ['userid' => $USER->id]);

    return [
        'success' => true,
        'count' => $count
    ];
}

/**
 * Update ticket status (admin only)
 *
 * @return array Response data
 */
function update_ticket_status() {
    global $DB, $USER;

    require_capability('moodle/site:config', context_system::instance());

    $ticketid = optional_param('ticket_id', 0, PARAM_INT);
    $status = optional_param('status', '', PARAM_ALPHAEXT);

    // Fallback to direct $_POST access
    if (empty($ticketid) && isset($_POST['ticket_id'])) {
        $ticketid = clean_param($_POST['ticket_id'], PARAM_INT);
    }
    if (empty($status) && isset($_POST['status'])) {
        $status = clean_param($_POST['status'], PARAM_ALPHAEXT);
    }

    if (empty($ticketid)) {
        throw new Exception('Ticket ID is required');
    }
    if (empty($status)) {
        throw new Exception('Status is required');
    }

    $ticket = $DB->get_record('theme_remui_kids_helptickets', ['id' => $ticketid], '*', MUST_EXIST);
    $ticket->status = $status;
    $ticket->timemodified = time();

    if ($status == 'resolved' || $status == 'closed') {
        $ticket->timeresolved = time();
    }

    $DB->update_record('theme_remui_kids_helptickets', $ticket);

    return [
        'success' => true
    ];
}

/**
 * Assign ticket to admin (admin only)
 *
 * @return array Response data
 */
function assign_ticket() {
    global $DB, $USER;

    require_capability('moodle/site:config', context_system::instance());

    $ticketid = optional_param('ticket_id', 0, PARAM_INT);
    $assignto = optional_param('assign_to', 0, PARAM_INT);

    // Fallback to direct $_POST access
    if (empty($ticketid) && isset($_POST['ticket_id'])) {
        $ticketid = clean_param($_POST['ticket_id'], PARAM_INT);
    }
    if (empty($assignto) && isset($_POST['assign_to'])) {
        $assignto = clean_param($_POST['assign_to'], PARAM_INT);
    }

    if (empty($ticketid)) {
        throw new Exception('Ticket ID is required');
    }

    $DB->set_field('theme_remui_kids_helptickets', 'assignedto', $assignto, ['id' => $ticketid]);
    $DB->set_field('theme_remui_kids_helptickets', 'timemodified', time(), ['id' => $ticketid]);

    return [
        'success' => true
    ];
}

/**
 * List all tickets (admin only)
 *
 * @return array Response data
 */
function list_all_tickets() {
    global $DB;

    require_capability('moodle/site:config', context_system::instance());

    $status = optional_param('status', '', PARAM_ALPHAEXT);
    $category = optional_param('category', '', PARAM_ALPHAEXT);

    $params = [];
    $where = [];

    if (!empty($status)) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    if (!empty($category)) {
        $where[] = 'category = :category';
        $params['category'] = $category;
    }

    $wheresql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT t.*, u.firstname, u.lastname, u.email
            FROM {theme_remui_kids_helptickets} t
            JOIN {user} u ON u.id = t.userid
            $wheresql
            ORDER BY t.timecreated DESC";

    $tickets = $DB->get_records_sql($sql, $params);

    $result = [];
    foreach ($tickets as $ticket) {
        $result[] = [
            'id' => $ticket->id,
            'ticketnumber' => $ticket->ticketnumber,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'userid' => $ticket->userid,
            'username' => fullname($ticket),
            'useremail' => $ticket->email,
            'timecreated' => $ticket->timecreated,
            'timeago' => format_time_ago($ticket->timecreated)
        ];
    }

    return [
        'success' => true,
        'tickets' => $result
    ];
}

// Helper functions

/**
 * Generate unique ticket number
 *
 * @return string Ticket number
 */
function generate_ticket_number() {
    global $DB;

    do {
        $number = 'TKT-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $exists = $DB->record_exists('theme_remui_kids_helptickets', ['ticketnumber' => $number]);
    } while ($exists);

    return $number;
}

/**
 * Handle file uploads for a ticket message
 *
 * @param int $ticketid Ticket ID
 * @param int $messageid Message ID
 */
function handle_file_uploads($ticketid, $messageid) {
    global $DB;

    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
        return;
    }

    $context = context_system::instance();
    $fs = get_file_storage();

    foreach ($_FILES['files']['name'] as $key => $filename) {
        if ($_FILES['files']['error'][$key] != UPLOAD_ERR_OK) {
            continue;
        }

        $tmpfile = $_FILES['files']['tmp_name'][$key];
        $filesize = $_FILES['files']['size'][$key];
        $mimetype = $_FILES['files']['type'][$key];

        // Store actual file in Moodle file system first
        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'theme_remui_kids',
            'filearea' => 'helpticket_attachment',
            'itemid' => $messageid,
            'filepath' => '/',
            'filename' => clean_filename($filename)
        ];

        $storedfile = $fs->create_file_from_pathname($fileinfo, $tmpfile);
        
        // Get hash from stored file (more efficient)
        $hash = $storedfile->get_contenthash();

        // Save file metadata
        $filerecord = new stdClass();
        $filerecord->messageid = $messageid;
        $filerecord->ticketid = $ticketid;
        $filerecord->filename = clean_filename($filename);
        $filerecord->filepath = '/';
        $filerecord->mimetype = $mimetype;
        $filerecord->filesize = $filesize;
        $filerecord->filehash = $hash;
        $filerecord->timecreated = time();

        $DB->insert_record('theme_remui_kids_helpticket_files', $filerecord);
    }
}

/**
 * Get file URL for download
 *
 * @param stdClass $file File record
 * @return string URL
 */
function get_file_url($file) {
    global $CFG;

    $context = context_system::instance();
    return $CFG->wwwroot . '/pluginfile.php/' . $context->id . 
           '/theme_remui_kids/helpticket_attachment/' . $file->messageid . 
           '/' . $file->filename;
}

/**
 * Format time ago string
 *
 * @param int $timestamp Unix timestamp
 * @return string Formatted time ago
 */
function format_time_ago($timestamp) {
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } else if ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } else if ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else if ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return userdate($timestamp, '%d %B %Y');
    }
}

/**
 * Notify admins of new ticket
 *
 * @param int $ticketid Ticket ID
 */
function notify_admins_new_ticket($ticketid) {
    global $DB;

    $ticket = $DB->get_record('theme_remui_kids_helptickets', ['id' => $ticketid]);
    $user = $DB->get_record('user', ['id' => $ticket->userid]);

    // Get all site admins
    $admins = get_admins();

    foreach ($admins as $admin) {
        // Send email or message notification
        // TODO: Implement notification system
    }
}

/**
 * Notify user of admin reply
 *
 * @param int $ticketid Ticket ID
 * @param int $userid User ID
 */
function notify_user_reply($ticketid, $userid) {
    // TODO: Implement notification
}

/**
 * Notify admins of user reply
 *
 * @param int $ticketid Ticket ID
 */
function notify_admins_reply($ticketid) {
    // TODO: Implement notification
}

