<?php
/**
 * Parent Dashboard - Messages Page
 * Integrated with Moodle Messaging System
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once($CFG->dirroot . '/message/lib.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$userid = $USER->id;

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get conversations/messages
$conversations = [];
$unread_count = 0;

try {
    // Get recent conversations using Moodle's messaging API
    $sql = "SELECT m.id, m.useridfrom, m.useridto, m.subject, m.fullmessage, 
                   m.timecreated, m.timeread,
                   ufrom.firstname as from_firstname, ufrom.lastname as from_lastname, 
                   ufrom.picture as from_picture,
                   uto.firstname as to_firstname, uto.lastname as to_lastname,
                   uto.picture as to_picture
            FROM {messages} m
            LEFT JOIN {user} ufrom ON ufrom.id = m.useridfrom
            LEFT JOIN {user} uto ON uto.id = m.useridto
            WHERE (m.useridto = :userid1 OR m.useridfrom = :userid2)
            AND m.timedeleted IS NULL
            ORDER BY m.timecreated DESC
            LIMIT 50";
    
    $messages = $DB->get_records_sql($sql, [
        'userid1' => $userid,
        'userid2' => $userid
    ]);
    
    // Group by conversation partner
    $conversation_partners = [];
    foreach ($messages as $msg) {
        $partner_id = ($msg->useridfrom == $userid) ? $msg->useridto : $msg->useridfrom;
        
        if (!isset($conversation_partners[$partner_id])) {
            $conversation_partners[$partner_id] = [
                'partner_id' => $partner_id,
                'partner_name' => ($msg->useridfrom == $userid) 
                    ? fullname($msg, 'to_') 
                    : fullname($msg, 'from_'),
                'last_message' => $msg->fullmessage,
                'last_time' => $msg->timecreated,
                'unread' => ($msg->useridto == $userid && !$msg->timeread) ? 1 : 0,
                'messages' => []
            ];
        }
        
        $conversation_partners[$partner_id]['messages'][] = $msg;
        
        if ($msg->useridto == $userid && !$msg->timeread) {
            $unread_count++;
        }
    }
    
    $conversations = array_values($conversation_partners);
    
} catch (Exception $e) {
    debugging('Error fetching messages: ' . $e->getMessage());
}

// Get teachers of children's courses
$teachers = [];
if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED, 'child');
        
        $sql = "SELECT u.id AS teacherid,
                       u.firstname,
                       u.lastname,
                       u.email,
                       u.picture,
                       c.fullname AS coursename
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {context} ctx ON ctx.id = ra.contextid
                JOIN {role} r ON r.id = ra.roleid
           LEFT JOIN {course} c ON c.id = ctx.instanceid
                WHERE r.shortname IN ('editingteacher', 'teacher')
                  AND ctx.contextlevel = :ctxcourse
                  AND u.deleted = 0
                  AND u.id NOT IN (
                      SELECT DISTINCT ra_admin.userid
                      FROM {role_assignments} ra_admin
                      JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
                      WHERE r_admin.shortname IN ('manager', 'administrator')
                  )
                  AND c.id IN (
                      SELECT DISTINCT c2.id 
                        FROM {course} c2
                        JOIN {enrol} e ON e.courseid = c2.id
                        JOIN {user_enrolments} ue ON ue.enrolid = e.id
                       WHERE ue.userid {$insql}
                  )
            ORDER BY u.lastname, u.firstname, c.fullname";

        $params['ctxcourse'] = CONTEXT_COURSE;

        $recordset = $DB->get_recordset_sql($sql, $params);
        $teacherlist = [];

        foreach ($recordset as $row) {
            $id = (int)$row->teacherid;

            if (!isset($teacherlist[$id])) {
                $teacherlist[$id] = (object) [
                    'id' => $id,
                    'firstname' => $row->firstname,
                    'lastname' => $row->lastname,
                    'email' => $row->email,
                    'picture' => $row->picture,
                    'courses' => [],
                ];
            }

            if (!empty($row->coursename) && !in_array($row->coursename, $teacherlist[$id]->courses, true)) {
                $teacherlist[$id]->courses[] = $row->coursename;
            }
        }

        $recordset->close();

        $teachers = array_map(static function($teacher) {
            $teacher->courses = implode(', ', $teacher->courses);
            return $teacher;
        }, $teacherlist);
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/parent_dashboard.css">';
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
?>

<style>
/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

.parent-main-content {
    margin-left: 280px;
    padding: 0;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
}

.parent-content-wrapper {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.container,
.container-fluid,
#region-main,
#region-main-box {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
}

.messages-container {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
    height: calc(100vh - 200px);
}

.conversations-list {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    overflow-y: auto;
}

.list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.list-title {
    font-size: 20px;
    font-weight: 700;
    color: #4b5563;
    margin: 0;
}

.new-message-btn {
    padding: 8px 16px;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.new-message-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(96, 165, 250, 0.3);
}

.conversation-item {
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.conversation-item:hover {
    background: #f9fafb;
    border-color: #60a5fa;
}

.conversation-item.unread {
    background: #f0f9ff;
    border-color: #60a5fa;
}

.conversation-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.user-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 16px;
}

.conversation-info {
    flex: 1;
}

.conversation-name {
    font-weight: 600;
    color: #4b5563;
    font-size: 15px;
    margin-bottom: 3px;
}

.conversation-preview {
    font-size: 13px;
    color: #6b7280;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.conversation-time {
    font-size: 11px;
    color: #9ca3af;
}

.message-view {
    background: white;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
}

.message-header {
    padding-bottom: 20px;
    border-bottom: 2px solid #f0f0f0;
    margin-bottom: 20px;
}

.teachers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.teacher-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 2px solid #f0f0f0;
    transition: all 0.3s ease;
}

.teacher-card:hover {
    border-color: #60a5fa;
    transform: translateY(-3px);
    box-shadow: 0 4px 16px rgba(96, 165, 250, 0.2);
}

.teacher-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.teacher-avatar {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 20px;
}

.teacher-info h3 {
    margin: 0 0 5px 0;
    color: #4b5563;
    font-size: 16px;
}

.teacher-courses {
    font-size: 12px;
    color: #6b7280;
    margin: 10px 0;
    line-height: 1.5;
}

.message-btn {
    padding: 10px;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    transition: all 0.3s ease;
}

.message-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(96, 165, 250, 0.3);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-icon {
    font-size: 64px;
    color: #d1d5db;
    margin-bottom: 20px;
}

@media (max-width: 968px) {
    .messages-container {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .teachers-grid {
        grid-template-columns: 1fr;
    }

    .parent-main-content {
        margin-left: 0;
        width: 100%;
        padding: 24px 20px 40px;
    }
}
</style>

<div class="parent-main-content">
    <div class="parent-content-wrapper">
        
        <nav class="parent-breadcrumb">
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" class="breadcrumb-link">Dashboard</a>
            <i class="fas fa-chevron-right breadcrumb-separator"></i>
            <span class="breadcrumb-current">Messages</span>
        </nav>

        <!-- Header -->
        <div style="background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%); color: white; padding: 30px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 20px rgba(96, 165, 250, 0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <h1 style="margin: 0 0 10px 0; font-size: 28px; display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                            <i class="fas fa-envelope"></i>
                        </div>
                        Messages
                    </h1>
                    <p style="margin: 0; opacity: 0.9; font-size: 14px;">
                        Communicate with teachers and school staff
                    </p>
                </div>
                <?php if ($unread_count > 0): ?>
                <div style="background: rgba(255, 255, 255, 0.2); padding: 10px 20px; border-radius: 10px;">
                    <div style="font-size: 24px; font-weight: 700;"><?php echo $unread_count; ?></div>
                    <div style="font-size: 12px; opacity: 0.9;">Unread Messages</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Message to Teachers -->
        <?php if (!empty($teachers)): ?>
        <div style="margin-bottom: 30px;">
            <h2 style="color: #1f2937; margin-bottom: 20px; font-size: 20px;">
                <i class="fas fa-chalkboard-teacher"></i> Message Teachers
            </h2>
            <div class="teachers-grid">
                <?php foreach ($teachers as $teacher): ?>
                <div class="teacher-card">
                    <div class="teacher-header">
                        <div class="teacher-avatar">
                            <?php echo strtoupper(substr($teacher->firstname, 0, 1)); ?>
                        </div>
                        <div class="teacher-info">
                            <h3><?php echo fullname($teacher); ?></h3>
                            <div style="font-size: 12px; color: #6b7280;">
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($teacher->email); ?>
                            </div>
                        </div>
                    </div>
                    <div class="teacher-courses">
                        <i class="fas fa-book"></i> 
                        <?php echo htmlspecialchars(substr($teacher->courses, 0, 100)); ?>
                        <?php if (strlen($teacher->courses) > 100): ?>...<?php endif; ?>
                    </div>
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?id=<?php echo $teacher->id; ?>" 
                       class="message-btn">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Conversations -->
        <div>
            <h2 style="color: #1f2937; margin-bottom: 20px; font-size: 20px;">
                <i class="fas fa-comments"></i> Recent Conversations
            </h2>
            
            <?php if (!empty($conversations)): ?>
            <div style="background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);">
                <?php foreach ($conversations as $conv): ?>
                <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?id=<?php echo $conv['partner_id']; ?>" 
                   class="conversation-item <?php echo $conv['unread'] ? 'unread' : ''; ?>" 
                   style="display: block; text-decoration: none; color: inherit;">
                    <div class="conversation-header">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($conv['partner_name'], 0, 1)); ?>
                        </div>
                        <div class="conversation-info">
                            <div class="conversation-name">
                                <?php echo htmlspecialchars($conv['partner_name']); ?>
                                <?php if ($conv['unread']): ?>
                                    <span style="background: #ef4444; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 8px;">NEW</span>
                                <?php endif; ?>
                            </div>
                            <div class="conversation-preview">
                                <?php echo htmlspecialchars(substr(strip_tags($conv['last_message']), 0, 60)); ?>...
                            </div>
                        </div>
                        <div class="conversation-time">
                            <?php echo date('M d, Y', $conv['last_time']); ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                
                <div style="margin-top: 20px; text-align: center;">
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" 
                       style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white; text-decoration: none; border-radius: 10px; font-weight: 600;">
                        <i class="fas fa-external-link-alt"></i> Open Full Messaging System
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3 style="color: #1f2937; margin: 0 0 10px 0;">No Messages Yet</h3>
                <p>Start a conversation with your child's teachers</p>
                <a href="<?php echo $CFG->wwwroot; ?>/message/index.php" 
                   style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white; text-decoration: none; border-radius: 10px; font-weight: 600;">
                    <i class="fas fa-plus"></i> Start New Message
                </a>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php echo $OUTPUT->footer(); ?>




