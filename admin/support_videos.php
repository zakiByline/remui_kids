<?php
/**
 * Support Videos Management Page (Admin View)
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/support/classes/video_manager.php');

use local_support\video_manager;

require_login();
require_capability('local/support:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/admin/support_videos.php'));
$PAGE->set_title(get_string('supportvideos', 'theme_remui_kids'));
$PAGE->set_heading(get_string('supportvideos', 'theme_remui_kids'));
$PAGE->set_pagelayout('admin');

// Get action
$action = optional_param('action', '', PARAM_ALPHA);
$videoid = optional_param('id', 0, PARAM_INT);

// Handle actions
if ($action === 'delete' && $videoid && confirm_sesskey()) {
    if (video_manager::delete_video($videoid)) {
        redirect($PAGE->url, get_string('videodeletesuccess', 'local_support'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect($PAGE->url, get_string('videodeletefailed', 'local_support'), null, \core\output\notification::NOTIFY_ERROR);
    }
} else if ($action === 'toggle' && $videoid && confirm_sesskey()) {
    $video = video_manager::get_video($videoid);
    if ($video) {
        $newvisibility = $video->visible ? 0 : 1;
        video_manager::update_visibility($videoid, $newvisibility);
        redirect($PAGE->url, get_string('visibilityupdated', 'local_support'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Get all videos grouped by category
$videosGrouped = video_manager::get_videos_by_category(null, false); // Get all videos including hidden

// Get statistics
$stats = video_manager::get_statistics();

echo $OUTPUT->header();
?>

<style>
/* Admin Layout */
.admin-main-content {
    padding: 30px;
    min-height: calc(100vh - 80px);
    background: #f5f7fa;
}

@media (max-width: 1024px) {
    .admin-main-content {
        margin-left: 0;
        padding: 20px;
    }
}

.support-videos-container {
    margin: 0 auto;
    padding: 2%;
}

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.admin-title h2 {
    margin: 0;
    color: #333;
    font-size: 28px;
}

.admin-actions {
    display: flex;
    gap: 15px;
}

.btn-primary-custom {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary-custom:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    color: white;
    text-decoration: none;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.category-section {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.category-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.category-title {
    font-size: 22px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.category-count {
    background: #667eea;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
}

.videos-table {
    width: 100%;
    border-collapse: collapse;
}

.videos-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #666;
    border-bottom: 2px solid #dee2e6;
}

.videos-table td {
    padding: 15px 12px;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}

.video-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
}

.video-description {
    font-size: 13px;
    color: #666;
}

.video-type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.video-type-uploaded {
    background: #e3f2fd;
    color: #1976d2;
}

.video-type-youtube {
    background: #ffebee;
    color: #c62828;
}

.video-type-vimeo {
    background: #e8f5e9;
    color: #388e3c;
}

.video-type-external {
    background: #fff3e0;
    color: #f57c00;
}

.role-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.role-admin {
    background: #fce4ec;
    color: #c2185b;
}

.role-teacher {
    background: #e1f5fe;
    color: #0277bd;
}

.role-student {
    background: #f3e5f5;
    color: #7b1fa2;
}

.role-all {
    background: #f1f8e9;
    color: #558b2f;
}

.visibility-toggle {
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    border: none;
    transition: all 0.2s;
}

.visibility-visible {
    background: #e8f5e9;
    color: #2e7d32;
}

.visibility-hidden {
    background: #ffebee;
    color: #c62828;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s;
}

.btn-view {
    background: #e3f2fd;
    color: #1976d2;
}

.btn-view:hover {
    background: #bbdefb;
    color: #1565c0;
}

.btn-edit {
    background: #fff3e0;
    color: #f57c00;
}

.btn-edit:hover {
    background: #ffe0b2;
    color: #ef6c00;
}

.btn-delete {
    background: #ffebee;
    color: #c62828;
}

.btn-delete:hover {
    background: #ffcdd2;
    color: #b71c1c;
}

.no-videos {
    text-align: center;
    padding: 40px;
    color: #999;
}
</style>

<?php require_once('includes/admin_sidebar.php'); ?>

<div class="admin-main-content">
<div class="support-videos-container">
    <div class="admin-header">
        <div class="admin-title">
            <h2><i class="fa fa-video"></i> Support Videos Management</h2>
        </div>
        <div class="admin-actions">
            <a href="<?php echo $CFG->wwwroot; ?>/local/support/manage.php" class="btn-primary-custom">
                <i class="fa fa-plus"></i> Add New Video
            </a>
            <a href="<?php echo $CFG->wwwroot; ?>/local/support/index.php" class="btn-primary-custom">
                <i class="fa fa-eye"></i> View Help Center
            </a>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats->total_videos; ?></div>
            <div class="stat-label">Total Videos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats->total_views; ?></div>
            <div class="stat-label">Total Views</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats->unique_viewers; ?></div>
            <div class="stat-label">Unique Viewers</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats->total_uploads; ?></div>
            <div class="stat-label">Uploaded Videos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats->total_external; ?></div>
            <div class="stat-label">External Videos</div>
        </div>
    </div>

    <!-- Videos by Category -->
    <?php if (empty($videosGrouped)): ?>
        <div class="category-section">
            <div class="no-videos">
                <i class="fa fa-video" style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;"></i>
                <h3>No videos available</h3>
                <p>Start by adding your first support video.</p>
                <a href="<?php echo $CFG->wwwroot; ?>/local/support/manage.php" class="btn-primary-custom" style="margin-top: 15px;">
                    <i class="fa fa-plus"></i> Add Your First Video
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($videosGrouped as $category => $data): ?>
            <div class="category-section">
                <div class="category-header">
                    <div class="category-title">
                        <i class="fa fa-folder-open"></i>
                        <?php echo htmlspecialchars($data['name']); ?>
                        <span class="category-count"><?php echo count($data['videos']); ?></span>
                    </div>
                </div>

                <table class="videos-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Video</th>
                            <th style="width: 10%;">Type</th>
                            <th style="width: 10%;">Target</th>
                            <th style="width: 8%;">Views</th>
                            <th style="width: 10%;">Visibility</th>
                            <th style="width: 22%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['videos'] as $video): ?>
                            <tr>
                                <td>
                                    <div class="video-title"><?php echo htmlspecialchars($video->title); ?></div>
                                    <?php if ($video->description): ?>
                                        <div class="video-description">
                                            <?php echo htmlspecialchars(substr($video->description, 0, 80)) . (strlen($video->description) > 80 ? '...' : ''); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($video->subcategory): ?>
                                        <div class="video-description">
                                            <i class="fa fa-tag"></i> <?php echo htmlspecialchars($video->subcategory); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="video-type-badge video-type-<?php echo $video->videotype; ?>">
                                        <?php echo $video->videotype; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $video->targetrole; ?>">
                                        <?php echo ucfirst($video->targetrole); ?>
                                    </span>
                                </td>
                                <td>
                                    <i class="fa fa-eye"></i> <?php echo $video->views; ?>
                                </td>
                                <td>
                                    <?php 
                                    $toggleurl = new moodle_url('/theme/remui_kids/admin/support_videos.php', [
                                        'action' => 'toggle',
                                        'id' => $video->id,
                                        'sesskey' => sesskey()
                                    ]);
                                    ?>
                                    <button class="visibility-toggle <?php echo $video->visible ? 'visibility-visible' : 'visibility-hidden'; ?>"
                                            onclick="window.location.href='<?php echo $toggleurl->out(); ?>'">
                                        <i class="fa fa-<?php echo $video->visible ? 'eye' : 'eye-slash'; ?>"></i>
                                        <?php echo $video->visible ? 'Visible' : 'Hidden'; ?>
                                    </button>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo $CFG->wwwroot; ?>/local/support/player.php?id=<?php echo $video->id; ?>" 
                                           class="action-btn btn-view" target="_blank">
                                            <i class="fa fa-play"></i> View
                                        </a>
                                        <a href="<?php echo $CFG->wwwroot; ?>/local/support/manage.php?action=edit&id=<?php echo $video->id; ?>" 
                                           class="action-btn btn-edit">
                                            <i class="fa fa-edit"></i> Edit
                                        </a>
                                        <?php 
                                        $deleteurl = new moodle_url('/theme/remui_kids/admin/support_videos.php', [
                                            'action' => 'delete',
                                            'id' => $video->id,
                                            'sesskey' => sesskey()
                                        ]);
                                        ?>
                                        <button class="action-btn btn-delete" 
                                                onclick="if(confirm('Are you sure you want to delete this video?')) window.location.href='<?php echo $deleteurl->out(); ?>'">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- End admin-main-content -->

<?php
echo $OUTPUT->footer();

