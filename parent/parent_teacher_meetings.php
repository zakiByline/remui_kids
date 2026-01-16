<?php
/**
 * Parent-Teacher Meetings - FULLY FUNCTIONAL
 * Real backend integration with Moodle calendar system
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once(__DIR__ . '/../lib/parent_teacher_meetings_handler.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_teacher_meetings.php');
$PAGE->set_title('Teacher Meetings');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get selected child name
$selected_child_name = '';
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    foreach ($children as $child) {
        if ($child['id'] == $selected_child) {
            $selected_child_name = $child['name'];
            break;
        }
    }
}

$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

// Get teachers for children's courses
$teachers = [];
if (!empty($target_children)) {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);

    $sql = "SELECT u.id AS teacherid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   u.phone1,
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
                     WHERE ue.userid $insql
               )
          ORDER BY u.firstname, u.lastname, c.fullname";

    $params['ctxcourse'] = CONTEXT_COURSE;

    try {
        $recordset = $DB->get_recordset_sql($sql, $params);
        $seen = [];

        foreach ($recordset as $row) {
            $id = (int)$row->teacherid;

            if (!isset($seen[$id])) {
                $seen[$id] = [
                    'courses' => [],
                    'coursecount' => 0,
                ];

                $teacher = new stdClass();
                $teacher->id = $id;
                $teacher->firstname = $row->firstname;
                $teacher->lastname = $row->lastname;
                $teacher->email = $row->email;
                $teacher->phone1 = $row->phone1;
                $teacher->courses = '';
                $teacher->course_count = 0;

                $teachers[$id] = $teacher;
            }

            if (!empty($row->coursename) && !in_array($row->coursename, $seen[$id]['courses'], true)) {
                $seen[$id]['courses'][] = $row->coursename;
                $seen[$id]['coursecount']++;
            }
        }

        foreach ($seen as $teacherid => $courseinfo) {
            $teachers[$teacherid]->courses = implode('|||', $courseinfo['courses']);
            $teachers[$teacherid]->course_count = $courseinfo['coursecount'];
        }

        $recordset->close();
    } catch (Exception $e) {
        debugging('Error fetching teachers: ' . $e->getMessage());
    }

    // Ensure teachers are ordered by name for consistent display.
    uasort($teachers, static function($a, $b) {
        $aname = fullname($a);
        $bname = fullname($b);
        return strcasecmp($aname, $bname);
    });
}

// Get meetings from database - REAL DATA!
$all_meetings = get_parent_meetings($userid, 'all');
$upcoming_meetings = [];
$past_meetings = [];
$now = time();

foreach ($all_meetings as $meeting) {
    if ($meeting['timestamp'] >= $now && $meeting['status'] === 'scheduled') {
        $upcoming_meetings[] = $meeting;
    } else {
        $past_meetings[] = $meeting;
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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

/* Modern Meeting System Styling */
.parent-main-content {
    margin-left: 280px;
    padding: 0;
    min-height: 100vh;
    background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
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

.meetings-header {
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
    padding: 25px 30px;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
    position: relative;
    overflow: hidden;
}

.meetings-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.meetings-header h1 {
    color: white;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px 0;
    position: relative;
    z-index: 2;
}

.meetings-header p {
    color: rgba(255, 255, 255, 0.95);
    font-size: 14px;
    margin: 0;
    position: relative;
    z-index: 2;
}

/* Statistics */
.stats-grid-meetings {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card-meeting {
    background: white;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border-top: 3px solid;
}

.stat-card-meeting:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}

.stat-icon-meeting {
    font-size: 28px;
    margin-bottom: 8px;
}

.stat-value-meeting {
    font-size: 28px;
    font-weight: 700;
    margin: 5px 0;
    color: #4b5563;
}

.stat-label-meeting {
    font-size: 10px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* Tabs */
.meeting-tabs {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    background: white;
    padding: 10px;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.meeting-tab {
    flex: 1;
    padding: 15px 20px;
    border: none;
    background: transparent;
    color: #6b7280;
    font-size: 15px;
    font-weight: 700;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.meeting-tab:hover {
    background: #f3f4f6;
    color: #4b5563;
}

.meeting-tab.active {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Teacher Cards */
.teachers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 24px;
}

.teacher-card {
    background: white;
    border-radius: 12px;
    padding: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    overflow: hidden;
    border: 2px solid #f3f4f6;
}

.teacher-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.15);
    border-color: #60a5fa;
}

.teacher-card-header {
    background: linear-gradient(135deg, #fafbfc, #f3f4f6);
    padding: 18px;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 12px;
}

.teacher-avatar {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    font-weight: 700;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.12);
}

.teacher-card-body {
    padding: 18px;
}

.teacher-info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    color: #4b5563;
    font-size: 14px;
    border-bottom: 1px solid #f3f4f6;
}

.teacher-info-item:last-of-type {
    border-bottom: none;
}

.teacher-info-icon {
    width: 20px;
    color: #3b82f6;
    font-size: 16px;
}

.teacher-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 20px;
}

.btn {
    padding: 12px 16px;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #3b82f6;
    color: white;
    text-decoration: none;
}

/* Meeting Cards */
.meeting-card {
    background: white;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    margin-bottom: 20px;
    border-left: 5px solid #60a5fa;
    transition: all 0.3s ease;
}

.meeting-card:hover {
    transform: translateX(8px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.meeting-card-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 20px;
}

.meeting-subject {
    font-size: 22px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 8px 0;
}

.meeting-teacher-name {
    color: #6b7280;
    font-size: 15px;
    font-weight: 600;
}

.meeting-status {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-scheduled {
    background: #d1fae5;
    color: #065f46;
}

.status-completed {
    background: #dbeafe;
    color: #1e40af;
}

.status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.meeting-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin: 20px 0;
    padding: 20px;
    background: #fafbfc;
    border-radius: 12px;
}

.meeting-detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #4b5563;
    font-size: 14px;
    font-weight: 600;
}

.meeting-detail-item i {
    color: #3b82f6;
    width: 20px;
    font-size: 16px;
}

.meeting-notes {
    background: #eff6ff;
    padding: 16px;
    border-radius: 12px;
    border-left: 3px solid #3b82f6;
    margin: 16px 0;
}

.meeting-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    backdrop-filter: blur(4px);
    animation: fadeIn 0.3s ease;
}

.modal-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background: white;
    border-radius: 20px;
    padding: 0;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    padding: 30px;
    border-radius: 20px 20px 0 0;
    color: white;
}

.modal-title {
    font-size: 26px;
    font-weight: 800;
    margin: 0 0 8px 0;
}

.modal-subtitle {
    font-size: 14px;
    opacity: 0.9;
    margin: 0;
}

.modal-body {
    padding: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.modal-footer {
    padding: 20px 30px 30px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-cancel {
    background: #f3f4f6;
    color: #374151;
    border: 2px solid #e5e7eb;
}

.btn-cancel:hover {
    background: #e5e7eb;
}

/* Empty State */
.empty-state-meetings {
    text-align: center;
    padding: 80px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.empty-icon-meetings {
    font-size: 80px;
    color: #d1d5db;
    margin-bottom: 24px;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.empty-title-meetings {
    font-size: 28px;
    font-weight: 800;
    color: #4b5563;
    margin: 0 0 12px 0;
}

.empty-text-meetings {
    font-size: 16px;
    color: #6b7280;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 20px;
    }
    
    .teachers-grid {
        grid-template-columns: 1fr;
    }
    
    .meeting-details-grid {
        grid-template-columns: 1fr;
    }
    
    .teacher-actions {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="parent-main-content">
    <!-- Header -->
    <div class="meetings-header">
        <h1><i class="fas fa-handshake"></i> Teacher Meetings</h1>
        <p>Schedule and manage meetings with your child's teachers</p>
    </div>

    <?php if ($selected_child && $selected_child !== 'all' && $selected_child != 0): ?>
    <div style="display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #dbeafe, #eff6ff); padding: 12px 20px; border-radius: 30px; margin-bottom: 25px; border: 2px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);">
        <i class="fas fa-user-check" style="color: #3b82f6; font-size: 18px;"></i>
        <span style="font-size: 15px; font-weight: 700; color: #3b82f6;">Viewing: <?php echo htmlspecialchars($selected_child_name); ?></span>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
           style="color: #3b82f6; text-decoration: none; font-size: 14px; font-weight: 700; margin-left: 5px;"
           title="Change Child">
            <i class="fas fa-sync-alt"></i>
        </a>
    </div>
    <?php endif; ?>

    <?php if (!empty($children)): ?>
    
    <!-- Statistics -->
    <div class="stats-grid-meetings">
        <div class="stat-card-meeting" style="border-top-color: #60a5fa;">
            <div class="stat-icon-meeting" style="color: #60a5fa;"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-value-meeting"><?php echo count($teachers); ?></div>
            <div class="stat-label-meeting">Teachers Available</div>
        </div>
        <div class="stat-card-meeting" style="border-top-color: #10b981;">
            <div class="stat-icon-meeting" style="color: #10b981;"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value-meeting"><?php echo count($upcoming_meetings); ?></div>
            <div class="stat-label-meeting">Upcoming</div>
        </div>
        <div class="stat-card-meeting" style="border-top-color: #3b82f6;">
            <div class="stat-icon-meeting" style="color: #3b82f6;"><i class="fas fa-history"></i></div>
            <div class="stat-value-meeting"><?php echo count($past_meetings); ?></div>
            <div class="stat-label-meeting">Past Meetings</div>
        </div>
        <div class="stat-card-meeting" style="border-top-color: #60a5fa;">
            <div class="stat-icon-meeting" style="color: #60a5fa;"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-value-meeting"><?php echo count($all_meetings); ?></div>
            <div class="stat-label-meeting">Total</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="meeting-tabs">
        <button class="meeting-tab active" onclick="switchTab('teachers')">
            <i class="fas fa-users"></i> Teachers
        </button>
        <button class="meeting-tab" onclick="switchTab('upcoming')">
            <i class="fas fa-calendar-plus"></i> Upcoming (<?php echo count($upcoming_meetings); ?>)
        </button>
        <button class="meeting-tab" onclick="switchTab('past')">
            <i class="fas fa-history"></i> Past (<?php echo count($past_meetings); ?>)
        </button>
    </div>

    <!-- Teachers Tab -->
    <div id="teachers-tab" class="tab-content active">
        <?php if (!empty($teachers)): ?>
        <div class="teachers-grid">
            <?php 
            $avatar_colors = ['#3b82f6', '#60a5fa', '#2563eb', '#1d4ed8', '#93c5fd', '#7dd3fc'];
            $color_index = 0;
            foreach ($teachers as $teacher): 
                $courses_array = explode('|||', $teacher->courses);
                $initials = strtoupper(substr($teacher->firstname, 0, 1) . substr($teacher->lastname, 0, 1));
                $avatar_color = $avatar_colors[$color_index++ % count($avatar_colors)];
            ?>
            <div class="teacher-card">
                <div class="teacher-card-header">
                    <div class="teacher-avatar" style="background: <?php echo $avatar_color; ?>;">
                        <?php echo $initials; ?>
                    </div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 6px 0; font-size: 20px; font-weight: 800; color: #1f2937;">
                            <?php echo htmlspecialchars(fullname($teacher)); ?>
                        </h3>
                        <p style="margin: 0; font-size: 13px; color: #6b7280; font-weight: 600;">
                            <?php echo $teacher->course_count; ?> Course<?php echo $teacher->course_count != 1 ? 's' : ''; ?>
                        </p>
                    </div>
                </div>
                
                <div class="teacher-card-body">
                    <div class="teacher-info-item">
                        <i class="fas fa-envelope teacher-info-icon"></i>
                        <span><?php echo htmlspecialchars($teacher->email); ?></span>
                    </div>
                    <?php if ($teacher->phone1): ?>
                    <div class="teacher-info-item">
                        <i class="fas fa-phone teacher-info-icon"></i>
                        <span><?php echo htmlspecialchars($teacher->phone1); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="teacher-info-item">
                        <i class="fas fa-book teacher-info-icon"></i>
                        <span><?php echo htmlspecialchars(implode(', ', array_slice($courses_array, 0, 2))); ?><?php echo count($courses_array) > 2 ? '...' : ''; ?></span>
                </div>
                
                <div class="teacher-actions">
                        <button class="btn btn-primary" onclick='openScheduleModal(<?php echo json_encode([
                            "id" => $teacher->id,
                            "name" => fullname($teacher),
                            "email" => $teacher->email
                        ]); ?>)'>
                            <i class="fas fa-calendar-plus"></i> Schedule
                    </button>
                    <a href="<?php echo $CFG->wwwroot; ?>/message/index.php?id=<?php echo $teacher->id; ?>" class="btn btn-secondary">
                            <i class="fas fa-comment"></i> Message
                    </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state-meetings">
            <div class="empty-icon-meetings"><i class="fas fa-chalkboard-teacher"></i></div>
            <h3 class="empty-title-meetings">No Teachers Found</h3>
            <p class="empty-text-meetings">No teachers are assigned to your child's courses.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Meetings Tab -->
    <div id="upcoming-tab" class="tab-content">
        <?php if (!empty($upcoming_meetings)): ?>
            <?php foreach ($upcoming_meetings as $meeting): ?>
            <div class="meeting-card">
                <div class="meeting-card-header">
                <div>
                    <h3 class="meeting-subject"><?php echo htmlspecialchars($meeting['subject']); ?></h3>
                    <p class="meeting-teacher-name"><i class="fas fa-user-tie"></i> with <?php echo htmlspecialchars($meeting['teacher_name']); ?></p>
                    </div>
                <span class="meeting-status status-scheduled">
                    <i class="fas fa-clock"></i> Scheduled
                    </span>
                </div>
                
                <div class="meeting-details-grid">
                <div class="meeting-detail-item">
                        <i class="fas fa-calendar"></i>
                        <strong><?php echo $meeting['date']; ?></strong>
                    </div>
                <div class="meeting-detail-item">
                        <i class="fas fa-clock"></i>
                    <?php echo $meeting['time']; ?> (<?php echo $meeting['duration']; ?> min)
                    </div>
                <div class="meeting-detail-item">
                    <i class="fas fa-<?php echo $meeting['type'] === 'virtual' ? 'video' : 'building'; ?>"></i>
                    <?php echo ucfirst($meeting['type']); ?>
                    </div>
                <div class="meeting-detail-item">
                        <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($meeting['location']); ?>
                    </div>
                </div>
                
                <?php if (!empty($meeting['notes'])): ?>
                <div class="meeting-notes">
                <strong style="color: #1e40af;"><i class="fas fa-sticky-note"></i> Notes:</strong><br>
                <span style="color: #4b5563;"><?php echo nl2br(htmlspecialchars($meeting['notes'])); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($meeting['meeting_link'])): ?>
            <div style="margin: 16px 0;">
                <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" 
                   style="display: inline-flex; align-items: center; gap: 8px; background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700;">
                    <i class="fas fa-video"></i> Join Virtual Meeting
                </a>
                </div>
                <?php endif; ?>
                
                <div class="meeting-actions">
                    <button class="btn btn-secondary" onclick="cancelMeeting(<?php echo $meeting['id']; ?>)">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                <a href="mailto:<?php echo htmlspecialchars($meeting['teacher_email']); ?>?subject=Re: <?php echo urlencode($meeting['subject']); ?>" 
                   class="btn btn-secondary">
                    <i class="fas fa-envelope"></i> Email Teacher
                </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state-meetings">
            <div class="empty-icon-meetings"><i class="fas fa-calendar-check"></i></div>
            <h3 class="empty-title-meetings">No Upcoming Meetings</h3>
            <p class="empty-text-meetings">You don't have any scheduled meetings. Schedule one from the Teachers tab!</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Past Meetings Tab -->
    <div id="past-tab" class="tab-content">
        <?php if (!empty($past_meetings)): ?>
            <?php foreach ($past_meetings as $meeting): ?>
        <div class="meeting-card" style="opacity: 0.9;">
                <div class="meeting-card-header">
                <div>
                    <h3 class="meeting-subject"><?php echo htmlspecialchars($meeting['subject']); ?></h3>
                    <p class="meeting-teacher-name"><i class="fas fa-user-tie"></i> with <?php echo htmlspecialchars($meeting['teacher_name']); ?></p>
                    </div>
                    <span class="meeting-status status-completed">
                    <i class="fas fa-check"></i> Completed
                    </span>
                </div>
                
                <div class="meeting-details-grid">
                <div class="meeting-detail-item">
                        <i class="fas fa-calendar"></i>
                        <strong><?php echo $meeting['date']; ?></strong>
                    </div>
                <div class="meeting-detail-item">
                        <i class="fas fa-clock"></i>
                    <?php echo $meeting['time']; ?> (<?php echo $meeting['duration']; ?> min)
                    </div>
                <div class="meeting-detail-item">
                    <i class="fas fa-<?php echo $meeting['type'] === 'virtual' ? 'video' : 'building'; ?>"></i>
                    <?php echo ucfirst($meeting['type']); ?>
                    </div>
                </div>
                
            <?php if (!empty($meeting['notes'])): ?>
                <div class="meeting-notes">
                <strong style="color: #1e40af;"><i class="fas fa-check-circle"></i> Notes:</strong><br>
                <span style="color: #4b5563;"><?php echo nl2br(htmlspecialchars($meeting['notes'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state-meetings">
            <div class="empty-icon-meetings"><i class="fas fa-history"></i></div>
            <h3 class="empty-title-meetings">No Past Meetings</h3>
            <p class="empty-text-meetings">Your meeting history will appear here.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="empty-state-meetings">
        <div class="empty-icon-meetings"><i class="fas fa-users"></i></div>
        <h3 class="empty-title-meetings">No Children Found</h3>
        <p class="empty-text-meetings">You need to be linked to your children first.</p>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" class="btn btn-primary" style="margin-top: 20px;">
            <i class="fas fa-plus"></i> Setup Parent-Child Connection
        </a>
                    </div>
    <?php endif; ?>
                </div>
                
<!-- Schedule Meeting Modal -->
<div id="scheduleModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-calendar-plus"></i> Schedule Meeting</h2>
            <p class="modal-subtitle">Book a meeting with <span id="modalTeacherName"></span></p>
        </div>
        
        <form id="scheduleMeetingForm" class="modal-body">
            <input type="hidden" id="teacherId" name="teacherid">
            <input type="hidden" id="childId" name="childid" value="<?php echo $selected_child ?? 0; ?>">
            
            <div class="form-group">
                <label class="form-label">Meeting Subject *</label>
                <input type="text" class="form-control" id="subject" name="subject" 
                       placeholder="e.g., Discuss Math Progress" required>
                    </div>
            
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" 
                          placeholder="What would you like to discuss?"></textarea>
                    </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" class="form-control" id="date" name="date" 
                           min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                
                <div class="form-group">
                    <label class="form-label">Time *</label>
                    <input type="time" class="form-control" id="time" name="time" required>
                    </div>
                </div>
                
            <div class="form-group">
                <label class="form-label">Duration (minutes) *</label>
                <select class="form-control" id="duration" name="duration" required>
                    <option value="15">15 minutes</option>
                    <option value="30" selected>30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">1 hour</option>
                </select>
                    </div>
            
            <div class="form-group">
                <label class="form-label">Meeting Type *</label>
                <select class="form-control" id="type" name="type" required onchange="toggleMeetingFields()">
                    <option value="in-person">In-Person</option>
                    <option value="virtual">Virtual (Online)</option>
                </select>
                    </div>
            
            <div class="form-group" id="locationField">
                <label class="form-label">Location</label>
                <input type="text" class="form-control" id="location" name="location" 
                       placeholder="e.g., School Office, Room 101">
                </div>
                
            <div class="form-group" id="meetingLinkField" style="display: none;">
                <label class="form-label">Meeting Link (Zoom, Google Meet, etc.)</label>
                <input type="url" class="form-control" id="meeting_link" name="meeting_link" 
                       placeholder="https://zoom.us/j/...">
                </div>
                
            <div class="form-group">
                <label class="form-label">Additional Notes</label>
                <textarea class="form-control" id="notes" name="notes" 
                          placeholder="Any specific topics or questions?"></textarea>
            </div>
        </form>
        
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeScheduleModal()">
                <i class="fas fa-times"></i> Cancel
                    </button>
            <button type="button" class="btn btn-primary" onclick="submitMeeting()">
                <i class="fas fa-check"></i> Schedule Meeting
                    </button>
                </div>
            </div>
    </div>

<script>
// Tab Switching
function switchTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.meeting-tab').forEach(btn => btn.classList.remove('active'));
    document.getElementById(tabName + '-tab').classList.add('active');
    event.target.classList.add('active');
}

// Open Schedule Modal
function openScheduleModal(teacher) {
    document.getElementById('scheduleModal').classList.add('active');
    document.getElementById('modalTeacherName').textContent = teacher.name;
    document.getElementById('teacherId').value = teacher.id;
    document.getElementById('scheduleMeetingForm').reset();
}

// Close Modal
function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

// Toggle meeting type fields
function toggleMeetingFields() {
    const type = document.getElementById('type').value;
    const locationField = document.getElementById('locationField');
    const linkField = document.getElementById('meetingLinkField');
    
    if (type === 'virtual') {
        locationField.style.display = 'none';
        linkField.style.display = 'block';
    } else {
        locationField.style.display = 'block';
        linkField.style.display = 'none';
    }
}

// Submit Meeting
function submitMeeting() {
    const form = document.getElementById('scheduleMeetingForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'create');
    formData.append('sesskey', M.cfg.sesskey);
    
    // Show loading
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scheduling...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/ajax/meeting_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('âœ… Meeting scheduled successfully!\n\nThe teacher will be notified.');
            closeScheduleModal();
        location.reload();
        } else {
            alert('âŒ Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Schedule Meeting';
        }
    })
    .catch(error => {
        alert('âŒ Error scheduling meeting. Please try again.');
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Schedule Meeting';
    });
}

// Cancel Meeting
function cancelMeeting(eventid) {
    if (!confirm('Are you sure you want to cancel this meeting?\n\nThe teacher will be notified.')) {
        return;
}

    const formData = new FormData();
    formData.append('action', 'cancel');
    formData.append('eventid', eventid);
    formData.append('sesskey', M.cfg.sesskey);
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/ajax/meeting_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('âœ… Meeting cancelled successfully!');
            location.reload();
        } else {
            alert('âŒ Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('âŒ Error cancelling meeting. Please try again.');
        console.error('Error:', error);
    });
}

// Close modal on background click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        closeScheduleModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeScheduleModal();
    }
});
</script>

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




