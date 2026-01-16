<?php
/**
 * Badges Management Page - Admin
 * Displays site badges management with full functionality
 * 
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/badgeslib.php');

use core_badges\reportbuilder\local\systemreports\badges;
use core_reportbuilder\system_report_factory;

require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Check if badges are enabled
if (empty($CFG->enablebadges)) {
    throw new \moodle_exception('badgesdisabled', 'badges');
}

// Get current user
global $USER, $DB, $OUTPUT, $PAGE;

// Badge type (1 = BADGE_TYPE_SITE)
$type = BADGE_TYPE_SITE;
$deactivate = optional_param('lock', 0, PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);
$delete = optional_param('delete', 0, PARAM_INT);
$archive = optional_param('archive', 0, PARAM_INT);
$msg = optional_param('msg', '', PARAM_TEXT);

$urlparams = ['type' => $type];
$returnurl = new moodle_url('/theme/remui_kids/admin/badges.php', $urlparams);

// Set up the page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/badges.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('sitebadges', 'badges'));
$PAGE->set_heading(get_string('sitebadges', 'badges'));

// Check badge capabilities
if (!has_any_capability([
    'moodle/badges:viewbadges',
    'moodle/badges:viewawarded',
    'moodle/badges:createbadge',
    'moodle/badges:awardbadge',
    'moodle/badges:configurecriteria',
    'moodle/badges:configuremessages',
    'moodle/badges:configuredetails',
    'moodle/badges:deletebadge'], $context)) {
    redirect($CFG->wwwroot);
}

/** @var core_badges_renderer $output */
$output = $PAGE->get_renderer('core', 'badges');

// Handle badge deletion or archiving
if ($delete || $archive) {
    $badgeid = ($archive != 0) ? $archive : $delete;
    $badge = new badge($badgeid);
    require_capability('moodle/badges:deletebadge', $badge->get_context());
    if (!$confirm) {
        echo $OUTPUT->header();
        // Archive this badge?
        echo $output->heading(get_string('archivebadge', 'badges', $badge->name));
        $archivebutton = $output->single_button(
            new moodle_url($PAGE->url, ['archive' => $badge->id, 'confirm' => 1]),
            get_string('archiveconfirm', 'badges'));
        echo $output->box(get_string('archivehelp', 'badges') . $archivebutton, 'generalbox');

        // Delete this badge?
        echo $output->heading(get_string('delbadge', 'badges', $badge->name));
        $deletebutton = $output->single_button(
            new moodle_url($PAGE->url, ['delete' => $badge->id, 'confirm' => 1]),
            get_string('delconfirm', 'badges'));
        echo $output->box(get_string('deletehelp', 'badges') . $deletebutton, 'generalbox');

        // Go back.
        echo $output->action_link($returnurl, get_string('cancel'));
        echo $OUTPUT->footer();
        die();
    } else {
        require_sesskey();
        $archiveonly = ($archive != 0) ? true : false;
        $badge->delete($archiveonly);
        redirect($returnurl);
    }
}

// Handle badge deactivation
if ($deactivate) {
    require_sesskey();
    $badge = new badge($deactivate);
    require_capability('moodle/badges:configuredetails', $badge->get_context());
    if ($badge->is_locked()) {
        $badge->set_status(BADGE_STATUS_INACTIVE_LOCKED);
    } else {
        $badge->set_status(BADGE_STATUS_INACTIVE);
    }
    $msg = 'deactivatesuccess';
    $returnurl->param('msg', $msg);
    redirect($returnurl);
}

// Get selected school filter
$selected_school = optional_param('school_id', 0, PARAM_INT);

// Get all schools/companies for dropdown
$all_schools = [];
if ($DB->get_manager()->table_exists('company')) {
    $all_schools = $DB->get_records('company', [], 'name ASC');
}

// Build SQL conditions for badges based on school filter
$badge_where = "b.type = :type";
$badge_params = ['type' => $type];
$stats_where = "b.type = :type";
$stats_params = ['type' => $type];

// If school is selected, filter badges by users from that school
if ($selected_school > 0 && $DB->get_manager()->table_exists('company_users')) {
    $badge_where .= " AND b.usercreated IN (
        SELECT cu.userid 
        FROM {company_users} cu 
        WHERE cu.companyid = :schoolid
    )";
    $badge_params['schoolid'] = $selected_school;
    
    $stats_where .= " AND b.usercreated IN (
        SELECT cu.userid 
        FROM {company_users} cu 
        WHERE cu.companyid = :schoolid
    )";
    $stats_params['schoolid'] = $selected_school;
}

// Get badges statistics filtered by school
$total_badges = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {badge} b WHERE $stats_where AND b.status = :status_active",
    array_merge($stats_params, ['status_active' => BADGE_STATUS_ACTIVE])
);

$total_archived = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {badge} b WHERE $stats_where AND b.status = :status_archived",
    array_merge($stats_params, ['status_archived' => BADGE_STATUS_ARCHIVED])
);

$total_inactive = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {badge} b WHERE $stats_where AND b.status IN (:status_inactive1, :status_inactive2)",
    array_merge($stats_params, [
        'status_inactive1' => BADGE_STATUS_INACTIVE,
        'status_inactive2' => BADGE_STATUS_INACTIVE_LOCKED
    ])
);

$total_awarded = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT bi.userid) 
     FROM {badge_issued} bi 
     JOIN {badge} b ON bi.badgeid = b.id 
     WHERE $stats_where AND b.status != :status_archived",
    array_merge($stats_params, ['status_archived' => BADGE_STATUS_ARCHIVED])
);

// Get selected school name
$selected_school_name = '';
if ($selected_school > 0 && isset($all_schools[$selected_school])) {
    $selected_school_name = $all_schools[$selected_school]->name;
}

// Get badges list - all badges created by any user
$filtered_badges = [];
if ($selected_school > 0 && $DB->get_manager()->table_exists('company_users')) {
    // Filter by selected school
    $sql = "SELECT DISTINCT b.*, 
                   u.firstname, u.lastname, u.email,
                   c.name as school_name,
                   (SELECT COUNT(*) FROM {badge_issued} bi WHERE bi.badgeid = b.id) as awards_count
            FROM {badge} b
            INNER JOIN {company_users} cu ON cu.userid = b.usercreated AND cu.companyid = :schoolid
            LEFT JOIN {user} u ON b.usercreated = u.id
            LEFT JOIN {company} c ON c.id = cu.companyid
            WHERE b.type = :type
            ORDER BY b.timecreated DESC";
    $filtered_badges = $DB->get_records_sql($sql, ['type' => $type, 'schoolid' => $selected_school]);
} else {
    // Fetch ALL badges created by any user (no school filter) - ensures all badges are retrieved
    $sql = "SELECT DISTINCT b.*, 
                   u.firstname, u.lastname, u.email,
                   COALESCE(c.name, 'N/A') as school_name,
                   (SELECT COUNT(*) FROM {badge_issued} bi WHERE bi.badgeid = b.id) as awards_count
            FROM {badge} b
            LEFT JOIN {user} u ON b.usercreated = u.id
            LEFT JOIN {company_users} cu ON cu.userid = b.usercreated
            LEFT JOIN {company} c ON c.id = cu.companyid
            WHERE b.type = :type
            ORDER BY b.timecreated DESC";
    $filtered_badges = $DB->get_records_sql($sql, ['type' => $type]);
}

echo $OUTPUT->header();

// Include admin sidebar
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #f8f9fa;
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Admin Sidebar Navigation - Sticky on all pages */
    .admin-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        will-change: transform;
        backface-visibility: hidden;
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
        }
        
        .admin-sidebar.sidebar-open {
            transform: translateX(0);
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }

    /* Badges Page Styles */
    .badges-container {
        max-width: 100%;
        margin: 0;
        padding: 2rem 2.5rem;
        background: #ffffff;
        min-height: 100vh;
    }

    .badges-header {
        text-align: center;
        margin-bottom: 50px;
        color: #2c3e50;
        position: relative;
        background: #ffffff;
        padding: 35px 30px;
        border-radius: 15px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        border: 1px solid rgba(99, 102, 241, 0.18);
    }

    .badges-header h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 15px;
        color: #0f172a;
        text-shadow: none;
    }

    .badges-header p {
        font-size: 1.1rem;
        color: #5c6ac4;
        margin-bottom: 30px;
        font-weight: 500;
    }

    .header-actions {
        position: absolute;
        top: 20px;
        right: 20px;
        display: flex;
        gap: 15px;
    }

    .header-btn {
        background: #ffffff;
        color: #6c757d;
        border: 2px solid #e9ecef;
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        text-decoration: none;
    }

    .header-btn:hover {
        background: #f8f9fa;
        border-color: #dee2e6;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        text-decoration: none;
        color: #6c757d;
    }

    .header-btn.primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        border: none;
    }

    .header-btn.primary:hover {
        background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        color: white;
    }

    .stats-row {
        display: flex;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 40px;
        flex-wrap: wrap;
    }

    .stat-card {
        background: #ffffff;
        padding: 30px;
        border-radius: 18px;
        text-align: center;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
        border: 1px solid rgba(148, 163, 184, 0.35);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        animation: fadeInUp 0.6s ease-out;
        display: flex;
        align-items: center;
        gap: 18px;
        flex: 1;
        min-width: 280px;
        position: relative;
    }

    .stat-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
        border-color: rgba(59, 130, 246, 0.25);
    }

    .stat-icon {
        width: 58px;
        height: 58px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        font-weight: 600;
        flex-shrink: 0;
    }

    .stat-content {
        text-align: left;
    }

    .stat-content .number {
        font-size: 2.6rem;
        font-weight: 800;
        margin-bottom: 6px;
        line-height: 1;
        color: #0f172a;
    }

    .stat-content .label {
        font-size: 1rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }

    .stat-card.stat-active .stat-icon {
        background: rgba(59, 130, 246, 0.15);
        color: #1d4ed8;
    }

    .stat-card.stat-active .number,
    .stat-card.stat-active .label {
        color: #1d4ed8;
    }

    .stat-card.stat-archived .stat-icon {
        background: rgba(107, 114, 128, 0.15);
        color: #374151;
    }

    .stat-card.stat-archived .number,
    .stat-card.stat-archived .label {
        color: #374151;
    }

    .stat-card.stat-inactive .stat-icon {
        background: rgba(249, 115, 22, 0.15);
        color: #c2410c;
    }

    .stat-card.stat-inactive .number,
    .stat-card.stat-inactive .label {
        color: #c2410c;
    }

    .stat-card.stat-awarded .stat-icon {
        background: rgba(34, 197, 94, 0.15);
        color: #15803d;
    }

    .stat-card.stat-awarded .number,
    .stat-card.stat-awarded .label {
        color: #15803d;
    }

    /* Badges Report Container */
    .badges-report-container {
        background: #ffffff;
        padding: 30px;
        border-radius: 18px;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
        border: 1px solid rgba(148, 163, 184, 0.35);
        margin-bottom: 40px;
    }

    .badges-report-container .badges-heading {
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #e9ecef;
    }

    .badges-report-container .badges-heading h2 {
        font-size: 1.8rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    /* Message Styles */
    .message {
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: 500;
        animation: slideInDown 0.5s ease-out;
    }

    .message-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .message-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    @keyframes slideInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .badges-container {
            padding: 15px;
        }
        
        .badges-header h1 {
            font-size: 2rem;
        }
        
        .header-actions {
            position: static;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .stats-row {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }
        
        .stat-card {
            min-width: auto;
            width: 100%;
            flex: none;
        }
    }

    /* Override default Moodle table styles for better integration */
    .badges-report-container .generaltable {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        margin-top: 20px;
    }

    .badges-report-container .generaltable th {
        background: #f8f9fa;
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
    }

    .badges-report-container .generaltable td {
        padding: 15px;
        border-bottom: 1px solid #e9ecef;
    }

    .badges-report-container .generaltable tr:hover {
        background: #f8f9fa;
    }

    /* Ensure system report displays correctly */
    .badges-report-container .reportbuilder-table-container {
        overflow-x: auto;
        margin-top: 20px;
    }

    .badges-report-container .reportbuilder-table-wrapper {
        width: 100%;
    }

    /* Badge status badges */
    .badge-success {
        background-color: #28a745;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .badge-warning {
        background-color: #ffc107;
        color: #212529;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .badge-secondary {
        background-color: #6c757d;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .btn-sm {
        padding: 4px 8px;
        font-size: 0.875rem;
        border-radius: 4px;
        margin-right: 5px;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary {
        background-color: #007bff;
        color: white;
        border: 1px solid #007bff;
    }

    .btn-primary:hover {
        background-color: #0056b3;
        border-color: #0056b3;
        color: white;
        text-decoration: none;
    }

    .btn-secondary {
        background-color: #6c757d;
        color: white;
        border: 1px solid #6c757d;
    }

    .btn-secondary:hover {
        background-color: #5a6268;
        border-color: #545b62;
        color: white;
        text-decoration: none;
    }
</style>

<div class="badges-container">
    <div class="badges-header">
        <div class="header-actions">
            <?php if (has_capability('moodle/badges:createbadge', $context)): ?>
            <a href="<?php echo $CFG->wwwroot; ?>/badges/edit.php?type=<?php echo $type; ?>" class="header-btn primary">
                <i class="fa fa-plus"></i>
                Create Badge
            </a>
            <?php endif; ?>
        </div>
        <h1><?php echo get_string('sitebadges', 'badges'); ?></h1>
        <p>Manage and configure site-wide badges for achievements and recognition</p>
        
        <!-- School Filter -->
        <?php if (!empty($all_schools)): ?>
        <div class="school-filter-container" style="margin-top: 20px; text-align: left; padding-top: 20px; border-top: 1px solid #e9ecef;">
            <form method="get" action="<?php echo $PAGE->url; ?>" style="display: inline-block;">
                <label for="school_filter" style="margin-right: 10px; font-weight: 600; color: #495057;">Filter by School:</label>
                <select name="school_id" id="school_filter" onchange="this.form.submit()" style="padding: 8px 15px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 0.95rem; min-width: 250px; background: white; cursor: pointer;">
                    <option value="0">All Schools</option>
                    <?php foreach ($all_schools as $school): ?>
                        <option value="<?php echo $school->id; ?>" <?php echo ($selected_school == $school->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($school->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_school > 0): ?>
                    <a href="<?php echo $PAGE->url; ?>" class="header-btn" style="margin-left: 10px; padding: 8px 15px; display: inline-block; text-decoration: none;">
                        <i class="fa fa-times"></i> Clear Filter
                    </a>
                <?php endif; ?>
            </form>
            <?php if ($selected_school > 0 && $selected_school_name): ?>
                <div style="margin-top: 10px; color: #5c6ac4; font-weight: 500;">
                    <i class="fa fa-filter"></i> Showing badges created by users from: <strong><?php echo htmlspecialchars($selected_school_name); ?></strong>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="message message-success">
            <?php echo get_string($msg, 'badges'); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Row -->
    <div class="stats-row">
        <div class="stat-card stat-active">
            <div class="stat-icon">
                <i class="fa fa-certificate"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_badges; ?></div>
                <div class="label">Active Badges</div>
            </div>
        </div>
        <div class="stat-card stat-awarded">
            <div class="stat-icon">
                <i class="fa fa-trophy"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_awarded; ?></div>
                <div class="label">Users Awarded</div>
            </div>
        </div>
        <div class="stat-card stat-inactive">
            <div class="stat-icon">
                <i class="fa fa-pause-circle"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_inactive; ?></div>
                <div class="label">Inactive Badges</div>
            </div>
        </div>
        <div class="stat-card stat-archived">
            <div class="stat-icon">
                <i class="fa fa-archive"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_archived; ?></div>
                <div class="label">Archived Badges</div>
            </div>
        </div>
    </div>

    <!-- Badges Report -->
    <div class="badges-report-container">
        <?php if ($selected_school > 0): ?>
        <div class="badges-heading">
            <h2>Badges List - <?php echo htmlspecialchars($selected_school_name); ?></h2>
        </div>
        <?php else: ?>
        <div class="badges-heading">
            <h2>Badges List</h2>
        </div>
        <?php endif; ?>
        <?php
        // Show badges list - all badges created by any user
        if (!empty($filtered_badges)) {
            echo $OUTPUT->box('', 'notifyproblem hide', 'check_connection');
            ?>
            <table class="generaltable">
                <thead>
                    <tr>
                        <th>Badge</th>
                        <th>Name</th>
                        <th>Created By</th>
                        <th>School</th>
                        <th>Status</th>
                        <th>Awards</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filtered_badges as $badgeobj): 
                        $badge = new badge($badgeobj->id);
                    ?>
                    <tr>
                        <td><?php echo print_badge_image($badge, $badge->get_context(), 'small'); ?></td>
                        <td><strong><?php echo htmlspecialchars($badge->name); ?></strong></td>
                        <td>
                            <?php if ($badgeobj->firstname): ?>
                                <?php echo htmlspecialchars($badgeobj->firstname . ' ' . $badgeobj->lastname); ?>
                                <br><small style="color: #6c757d;"><?php echo htmlspecialchars($badgeobj->email); ?></small>
                            <?php else: ?>
                                <span style="color: #6c757d;">User ID: <?php echo $badgeobj->usercreated; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($badgeobj->school_name ?: 'N/A'); ?></td>
                        <td>
                            <?php 
                            $status_class = '';
                            $status_text = $badge->get_status_name();
                            if ($badge->status == BADGE_STATUS_ACTIVE || $badge->status == BADGE_STATUS_ACTIVE_LOCKED) {
                                $status_class = 'badge-success';
                            } elseif ($badge->status == BADGE_STATUS_INACTIVE || $badge->status == BADGE_STATUS_INACTIVE_LOCKED) {
                                $status_class = 'badge-warning';
                            } else {
                                $status_class = 'badge-secondary';
                            }
                            echo '<span class="badge ' . $status_class . '">' . $status_text . '</span>';
                            ?>
                        </td>
                        <td><?php echo $badgeobj->awards_count ?: 0; ?></td>
                        <td><?php echo userdate($badge->timecreated, get_string('strftimedatefullshort')); ?></td>
                        <td>
                            <a href="<?php echo $CFG->wwwroot; ?>/badges/overview.php?id=<?php echo $badge->id; ?>" class="btn btn-sm btn-primary" title="View">
                                <i class="fa fa-eye"></i>
                            </a>
                            <?php if (has_capability('moodle/badges:configuredetails', $badge->get_context())): ?>
                                <a href="<?php echo $CFG->wwwroot; ?>/badges/edit.php?id=<?php echo $badge->id; ?>" class="btn btn-sm btn-secondary" title="Edit">
                                    <i class="fa fa-edit"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo $OUTPUT->notification('No badges found.', 'info');
        }
        
        // Trigger event, badge listing viewed.
        $eventparams = ['context' => $PAGE->context, 'other' => ['badgetype' => BADGE_TYPE_SITE]];
        $event = \core\event\badge_listing_viewed::create($eventparams);
        $event->trigger();
        ?>
    </div>
</div>

<script>
// Get base URL from PHP
const WWWROOT = '<?php echo $CFG->wwwroot; ?>';

// Any additional JavaScript can be added here
document.addEventListener('DOMContentLoaded', function() {
    console.log('Badges page loaded');
});
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>

