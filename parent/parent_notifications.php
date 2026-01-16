<?php
/**
 * Parent Dashboard - Notifications Page
 * Shows announcements, forum posts, and system notifications
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
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

// Get notifications from multiple sources
$notifications = [];

// 1. Get forum announcements from children's courses
if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED, 'forum');
        
        $sql = "SELECT fp.id, fp.subject, fp.message, fp.created, fp.modified,
                       u.firstname, u.lastname, u.picture,
                       f.name as forumname, c.fullname as coursename, c.id as courseid,
                       'announcement' as notification_type
                FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fd.id = fp.discussion
                JOIN {forum} f ON f.id = fd.forum
                JOIN {course} c ON c.id = f.course
                JOIN {user} u ON u.id = fp.userid
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE f.type = 'news'
                AND ue.userid $insql
                AND fp.created > :timefilter
                ORDER BY fp.created DESC
                LIMIT 20";
        
        $announcements = $DB->get_records_sql($sql, array_merge($params, [
            'timefilter' => time() - (60 * 24 * 60 * 60) // Last 60 days
        ]));
        
        $notifications = array_merge($notifications, array_values($announcements));
    }
}

// 2. Get grade notifications (new grades posted)
if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED, 'grade');
        
        $sql = "SELECT gg.id, gi.itemname as subject, gg.timemodified as created,
                       c.fullname as coursename, c.id as courseid,
                       u.firstname, u.lastname,
                       CONCAT('New grade: ', ROUND(gg.finalgrade, 1), '/', ROUND(gi.grademax, 1)) as message,
                       'grade' as notification_type
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                JOIN {course} c ON c.id = gi.courseid
                JOIN {user} u ON u.id = gg.userid
                WHERE gg.userid $insql
                AND gg.timemodified > :timefilter
                AND gg.finalgrade IS NOT NULL
                ORDER BY gg.timemodified DESC
                LIMIT 15";
        
        $grades = $DB->get_records_sql($sql, array_merge($params, [
            'timefilter' => time() - (30 * 24 * 60 * 60) // Last 30 days
        ]));
        
        $notifications = array_merge($notifications, array_values($grades));
    }
}

// 3. Get upcoming assignment deadlines
if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED, 'deadline');
        
        $sql = "SELECT a.id, a.name as subject, a.duedate as created,
                       c.fullname as coursename, c.id as courseid,
                       CONCAT('Assignment due: ', a.name) as message,
                       'deadline' as notification_type
                FROM {assign} a
                JOIN {course} c ON c.id = a.course
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE ue.userid $insql
                AND a.duedate BETWEEN :now AND :nextweek
                ORDER BY a.duedate ASC
                LIMIT 10";
        
        $deadlines = $DB->get_records_sql($sql, array_merge($params, [
            'now' => time(),
            'nextweek' => time() + (7 * 24 * 60 * 60)
        ]));
        
        $notifications = array_merge($notifications, array_values($deadlines));
    }
}

// Sort all notifications by date
usort($notifications, function($a, $b) {
    return $b->created - $a->created;
});

// Count by type
$stats = [
    'total' => count($notifications),
    'announcements' => count(array_filter($notifications, function($n) { return $n->notification_type === 'announcement'; })),
    'grades' => count(array_filter($notifications, function($n) { return $n->notification_type === 'grade'; })),
    'deadlines' => count(array_filter($notifications, function($n) { return $n->notification_type === 'deadline'; }))
];

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

.notif-header {
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
    color: white;
    padding: 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(96, 165, 250, 0.3);
}

.notif-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.stat-box {
    background: rgba(255, 255, 255, 0.15);
    padding: 15px;
    border-radius: 12px;
    text-align: center;
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 13px;
    opacity: 0.9;
}

.filter-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}

.filter-tab {
    padding: 10px 20px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    color: #6b7280;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-tab:hover {
    border-color: #60a5fa;
    color: #60a5fa;
}

.filter-tab.active {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    border-color: #60a5fa;
}

.notifications-container {
    background: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.notification-item {
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 15px;
    border-left: 4px solid;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.notification-item:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.notification-item.announcement { 
    border-left-color: #3b82f6; 
    background: linear-gradient(to right, #eff6ff 0%, white 10%);
}

.notification-item.grade { 
    border-left-color: #10b981; 
    background: linear-gradient(to right, #ecfdf5 0%, white 10%);
}

.notification-item.deadline { 
    border-left-color: #f59e0b; 
    background: linear-gradient(to right, #fffbeb 0%, white 10%);
}

.notification-header {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 12px;
}

.notification-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    flex-shrink: 0;
}

.icon-announcement { background: #3b82f6; }
.icon-grade { background: #10b981; }
.icon-deadline { background: #f59e0b; }

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 700;
    color: #4b5563;
    font-size: 17px;
    margin-bottom: 8px;
    line-height: 1.4;
}

.notification-meta {
    display: flex;
    gap: 15px;
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 10px;
    flex-wrap: wrap;
    font-weight: 500;
}

.notification-message {
    color: #374151;
    font-size: 14px;
    line-height: 1.7;
    margin: 12px 0;
    font-weight: 400;
}

.notification-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-announcement { background: #dbeafe; color: #1e40af; }
.badge-grade { background: #d1fae5; color: #065f46; }
.badge-deadline { background: #fef3c7; color: #92400e; }

@media (max-width: 768px) {
    .notif-stats {
        grid-template-columns: repeat(2, 1fr);
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
            <span class="breadcrumb-current">Notifications</span>
        </nav>

        <!-- Header -->
        <div class="notif-header">
            <h1 style="margin: 0 0 10px 0; font-size: 28px; display: flex; align-items: center; gap: 15px;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    <i class="fas fa-bell"></i>
                </div>
                Notifications & Announcements
            </h1>
            <p style="margin: 0 0 20px 65px; opacity: 0.9; font-size: 14px;">
                Stay updated with school news, grades, and important deadlines
            </p>
            
            <div class="notif-stats">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Notifications</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['announcements']; ?></div>
                    <div class="stat-label">Announcements</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['grades']; ?></div>
                    <div class="stat-label">New Grades</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['deadlines']; ?></div>
                    <div class="stat-label">Upcoming Deadlines</div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" onclick="filterNotifications('all')">
                <i class="fas fa-th-large"></i> All
            </button>
            <button class="filter-tab" onclick="filterNotifications('announcement')">
                <i class="fas fa-bullhorn"></i> Announcements
            </button>
            <button class="filter-tab" onclick="filterNotifications('grade')">
                <i class="fas fa-chart-line"></i> Grades
            </button>
            <button class="filter-tab" onclick="filterNotifications('deadline')">
                <i class="fas fa-clock"></i> Deadlines
            </button>
        </div>

        <!-- Notifications List -->
        <?php if (!empty($notifications)): ?>
        <div class="notifications-container">
            <?php foreach ($notifications as $notif): 
                $icon_class = '';
                $icon = '';
                $badge_class = '';
                
                switch ($notif->notification_type) {
                    case 'announcement':
                        $icon = 'fa-bullhorn';
                        $icon_class = 'icon-announcement';
                        $badge_class = 'badge-announcement';
                        break;
                    case 'grade':
                        $icon = 'fa-chart-line';
                        $icon_class = 'icon-grade';
                        $badge_class = 'badge-grade';
                        break;
                    case 'deadline':
                        $icon = 'fa-clock';
                        $icon_class = 'icon-deadline';
                        $badge_class = 'badge-deadline';
                        break;
                }
            ?>
            <div class="notification-item <?php echo $notif->notification_type; ?>" data-type="<?php echo $notif->notification_type; ?>">
                <div class="notification-header">
                    <div class="notification-icon <?php echo $icon_class; ?>">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notif->subject); ?></div>
                        <div class="notification-meta">
                            <span><i class="fas fa-calendar"></i> <?php echo userdate($notif->created, '%d %B, %Y'); ?></span>
                            <?php if (isset($notif->coursename)): ?>
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($notif->coursename); ?></span>
                            <?php endif; ?>
                            <?php if (isset($notif->firstname)): ?>
                                <span><i class="fas fa-user"></i> <?php echo fullname($notif); ?></span>
                            <?php endif; ?>
                            <span class="notification-badge <?php echo $badge_class; ?>">
                                <?php echo ucfirst($notif->notification_type); ?>
                            </span>
                        </div>
                        <div class="notification-message">
                            <?php echo strip_tags(substr($notif->message, 0, 250)); ?>
                            <?php if (strlen($notif->message) > 250): ?>...<?php endif; ?>
                        </div>
                        <?php if (isset($notif->courseid)): ?>
                            <a href="<?php echo (new moodle_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $notif->courseid, 'child' => $selected_child]))->out(); ?>" 
                               style="display: inline-block; margin-top: 10px; color: #60a5fa; font-weight: 600; text-decoration: none;">
                                View Course <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="background: white; border-radius: 16px; padding: 60px; text-align: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);">
            <div style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;">
                <i class="fas fa-bell-slash"></i>
            </div>
            <h3 style="color: #4b5563; margin: 0 0 10px 0;">No Notifications</h3>
            <p style="color: #6b7280;">You're all caught up! Check back later for updates.</p>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function filterNotifications(type) {
    const items = document.querySelectorAll('.notification-item');
    const tabs = document.querySelectorAll('.filter-tab');
    
    // Update active tab
    tabs.forEach(tab => tab.classList.remove('active'));
    event.target.closest('.filter-tab').classList.add('active');
    
    // Filter items
    items.forEach(item => {
        if (type === 'all' || item.dataset.type === type) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
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




