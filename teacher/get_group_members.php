<?php
/**
 * API endpoint to get group members for assignments
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

// Set JSON header
header('Content-Type: application/json');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !is_siteadmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    // Get group IDs from URL parameter (can be single or multiple)
    $groupids_param = optional_param('groupids', '', PARAM_RAW);
    $groupid_param = optional_param('groupid', 0, PARAM_INT);
    
    // Parse group IDs
    $group_ids = [];
    if (!empty($groupids_param)) {
        // Handle JSON array of group IDs
        $decoded = json_decode($groupids_param, true);
        if (is_array($decoded)) {
            $group_ids = array_map('intval', $decoded);
        }
    } elseif ($groupid_param > 0) {
        // Handle single group ID
        $group_ids = [$groupid_param];
    }
    
    if (empty($group_ids)) {
        throw new Exception('No group IDs provided');
    }
    
    // Get all groups and their members
    $groups_data = [];
    
    foreach ($group_ids as $groupid) {
        // Get group details
        $group = $DB->get_record('groups', ['id' => $groupid]);
        
        if (!$group) {
            continue; // Skip if group not found
        }
        
        // Get group members with join date
        $members_sql = "
            SELECT u.id, u.firstname, u.lastname, u.email,
                   CONCAT(u.firstname, ' ', u.lastname) as fullname,
                   gm.timeadded
            FROM {user} u
            JOIN {groups_members} gm ON gm.userid = u.id
            WHERE gm.groupid = ?
            AND u.deleted = 0
            ORDER BY u.lastname ASC, u.firstname ASC
        ";
        
        $members = $DB->get_records_sql($members_sql, [$groupid]);
        
        // Convert members to array
        $members_array = [];
        foreach ($members as $member) {
            $members_array[] = [
                'id' => $member->id,
                'fullname' => $member->fullname,
                'firstname' => $member->firstname,
                'lastname' => $member->lastname,
                'email' => $member->email,
                'added' => userdate($member->timeadded, get_string('strftimedatetime', 'langconfig'))
            ];
        }
        
        $groups_data[] = [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'members' => $members_array
        ];
    }
    
    echo json_encode([
        'success' => true,
        'groups' => $groups_data
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_group_members.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading group members: ' . $e->getMessage()
    ]);
}
