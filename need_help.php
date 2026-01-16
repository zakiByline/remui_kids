<?php
/**
 * Need Help Page - Displays all support videos based on user role
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
require_once($CFG->dirroot . '/local/support/classes/video_manager.php');

use local_support\video_manager;

require_login();

// Determine user role
$isadmin = is_siteadmin($USER);
$context = context_system::instance();
$isteacher = has_capability('moodle/course:update', $context);

$targetrole = 'student';
if ($isadmin) {
    $targetrole = 'admin';
} else if ($isteacher) {
    $targetrole = 'teacher';
}

// For students, ensure they only see videos assigned to them (targetrole = 'student' or 'all')
// Get all videos for this role, grouped by category
$videosGrouped = video_manager::get_videos_by_category($targetrole, true);

// Additional filtering for students: ensure they only see student-appropriate videos
if ($targetrole === 'student') {
    // Filter out any videos that are not meant for students
    foreach ($videosGrouped as $category => $data) {
        $filteredVideos = [];
        foreach ($data['videos'] as $video) {
            // Only include videos where targetrole is 'student' or 'all'
            if ($video->targetrole === 'student' || $video->targetrole === 'all') {
                $filteredVideos[] = $video;
            }
        }
        
        // Update the category with filtered videos
        if (!empty($filteredVideos)) {
            $videosGrouped[$category]['videos'] = $filteredVideos;
        } else {
            // Remove category if no videos remain
            unset($videosGrouped[$category]);
        }
    }
}

// Get page title based on role
$pagetitle = 'Need Help';
if ($targetrole === 'teacher') {
    $pagetitle = 'Teacher Help Videos';
} else if ($targetrole === 'admin') {
    $pagetitle = 'Admin Help Videos';
} else {
    $pagetitle = 'Student Help Videos';
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/need_help.php'));
$PAGE->set_title($pagetitle);
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// Determine if user is elementary student
$is_elementary = false;
$is_student = false;
if (!$isteacher && !$isadmin) {
    $is_student = true; // User is a student
    
    // Check if user is in elementary cohort
    try {
        // First check by cohort name/idnumber
        $user_cohorts = $DB->get_records_sql(
            "SELECT c.id, c.name, c.idnumber 
             FROM {cohort} c
             JOIN {cohort_members} cm ON c.id = cm.cohortid
             WHERE cm.userid = ? AND (c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.name LIKE ? OR c.name LIKE ? OR c.name LIKE ?)",
            [$USER->id, 'grade1%', 'grade2%', 'grade3%', 'elementary%', '%grade 1%', '%grade 2%', '%grade 3%']
        );
        
        if (!empty($user_cohorts)) {
            $is_elementary = true;
        } else {
            // Also check by cohort name pattern (case-insensitive)
            $user_cohorts_by_name = $DB->get_records_sql(
                "SELECT c.id, c.name, c.idnumber 
                 FROM {cohort} c
                 JOIN {cohort_members} cm ON c.id = cm.cohortid
                 WHERE cm.userid = ?",
                [$USER->id]
            );
            
            foreach ($user_cohorts_by_name as $cohort) {
                $cohort_name_lower = strtolower($cohort->name);
                $cohort_idnumber_lower = strtolower($cohort->idnumber ?? '');
                
                // Check if cohort name or idnumber contains grade 1, 2, 3, or elementary
                if (preg_match('/grade\s*[1-3]|elementary/i', $cohort_name_lower) || 
                    preg_match('/grade\s*[1-3]|elementary/i', $cohort_idnumber_lower)) {
                    $is_elementary = true;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error checking elementary status: " . $e->getMessage());
        $is_elementary = false;
    }
}

// Add CSS to remove the default main container and ensure content is visible
echo '<style>
/* Neutralize the default main container */
#region-main,
[role="main"],
#page-content,
#page {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Ensure content is visible for all users */
.need-help-page-wrapper {
    min-height: 100vh;
    background: #f8f9fa;
    display: block !important;
    visibility: visible !important;
    position: relative;
    z-index: 1;
}

/* For students without sidebar */
.need-help-page-wrapper.student-no-sidebar {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
}

/* For elementary students with sidebar */
.need-help-page-wrapper.elementary-with-sidebar {
    min-height: calc(100vh - 80px);
    width: calc(97vw - 280px);
    max-width: calc(100vw - 280px);
    box-sizing: border-box;
    position: relative;
}

/* For teachers with sidebar */
.teacher-main-content {
    padding-top: 0px !important;
}

/* Ensure need-help-container is visible */
.need-help-container {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}
</style>';

// Show sidebar for teachers/admins or students
if ($isteacher || $isadmin) {
    // Teacher dashboard layout wrapper and sidebar
    echo '<div class="teacher-css-wrapper">';
    echo '<div class="teacher-dashboard-wrapper">';
    
    // Include reusable sidebar
    include(__DIR__ . '/teacher/includes/sidebar.php');
    
    // Main content area next to sidebar
    echo '<div class="teacher-main-content need-help-page-wrapper">';
} else if ($is_student) {
    // For all students, show elementary sidebar (works for all student types)
    require_once($CFG->dirroot . '/theme/remui_kids/lib/sidebar_helper.php');
    
    // Get sidebar context - use 'needhelp' as current page
    $sidebar_context = theme_remui_kids_get_elementary_sidebar_context('needhelp', $USER);
    $sidebar_context['is_needhelp_page'] = true;
    
    // Ensure needhelpurl is set
    if (!isset($sidebar_context['needhelpurl'])) {
        $sidebar_context['needhelpurl'] = (new moodle_url('/theme/remui_kids/need_help.php'))->out();
    }
    
    // Render elementary sidebar
    echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_sidebar', $sidebar_context);
    
    // Content wrapper for students with sidebar
    echo '<div class="need-help-page-wrapper elementary-with-sidebar">';
} else {
    // Fallback for other user types without sidebar
    echo '<div class="need-help-page-wrapper student-no-sidebar">';
}
?>

<style>
.need-help-container {
    margin: 0;
    padding: 0;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.teacher-main-content .need-help-container {
    margin-top: 8%;
}

.teacher-main-content{
    padding-top: 0px !important;
}
.help-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
}

.help-header h1 {
    margin: 0 0 10px 0;
    font-size: 36px;
    font-weight: 700;
}

.help-header p {
    margin: 0;
    font-size: 18px;
    opacity: 0.95;
}

.help-search-wrapper {
    margin-bottom: 30px;
}

.help-search-container {
    position: relative;
    max-width: 600px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    padding: 0;
    overflow: hidden;
}

.help-search-icon {
    position: absolute;
    left: 20px;
    color: #667eea;
    font-size: 18px;
    z-index: 1;
}

.help-search-input {
    width: 100%;
    padding: 15px 50px 15px 55px;
    border: none;
    outline: none;
    font-size: 16px;
    color: #333;
    background: transparent;
}

.help-search-input::placeholder {
    color: #999;
}

.help-search-clear {
    position: absolute;
    right: 15px;
    background: transparent;
    border: none;
    color: #999;
    cursor: pointer;
    padding: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
    z-index: 1;
}

.help-search-clear:hover {
    background: #f0f0f0;
    color: #667eea;
}

.search-results-count {
    text-align: center;
    margin-top: 15px;
    color: #666;
    font-size: 14px;
}

.search-results-count strong {
    color: #667eea;
    font-weight: 600;
}

.video-card.hidden {
    display: none;
}

.category-section.hidden {
    display: none;
}

.category-section {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    width: 100%;
    box-sizing: border-box;
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
    font-size: 24px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.category-title i {
    color: #667eea;
}

.category-count {
    background: #667eea;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

.videos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.video-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.video-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    border-color: #667eea;
}

.video-card-header {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 15px;
}

.video-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    flex-shrink: 0;
}

.video-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0 0 8px 0;
    line-height: 1.3;
}

.video-description {
    font-size: 14px;
    color: #666;
    line-height: 1.5;
    margin: 0;
}

.video-meta {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #dee2e6;
    font-size: 13px;
    color: #999;
}

.video-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.video-type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
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

.no-videos {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.no-videos i {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.3;
    color: #667eea;
}

.no-videos h3 {
    color: #666;
    margin-bottom: 10px;
}

/* Video Modal Styles */
.video-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    z-index: 10000;
    overflow-y: auto;
}

.video-modal.active {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.video-modal-content {
    background: #1a1a1a;
    border-radius: 12px;
    max-width: 1200px;
    width: 100%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.video-modal-header {
    padding: 20px 25px;
    background: #2a2a2a;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.video-modal-title {
    color: white;
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}

.video-modal-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 28px;
    cursor: pointer;
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.2s;
}

.video-modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.video-modal-body {
    padding: 25px;
    flex: 1;
    overflow-y: auto;
}

.video-player-wrapper {
    width: 100%;
    margin-bottom: 20px;
    border-radius: 8px;
    overflow: hidden;
    background: #000;
}

.video-player-wrapper video,
.video-player-wrapper iframe {
    width: 100%;
    height: auto;
    min-height: 450px;
    display: block;
}

.video-info-section {
    color: white;
    margin-top: 20px;
}

.video-info-section h3 {
    color: white;
    margin-bottom: 10px;
    font-size: 18px;
}

.video-info-section p {
    color: #ccc;
    line-height: 1.6;
    margin: 0;
}

.back-to-list-btn {
    position: absolute;
    top: 20px;
    left: 20px;
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
    z-index: 10001;
}

.back-to-list-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

@media (max-width: 768px) {
    .videos-grid {
        grid-template-columns: 1fr;
    }
    
    .help-header h1 {
        font-size: 28px;
    }
    
    .help-header p {
        font-size: 16px;
    }
    
    .video-modal-content {
        max-height: 100vh;
        border-radius: 0;
    }
}
</style>

<div class="need-help-container">
    <div class="help-header">
        <h1><i class="fa fa-question-circle"></i> <?php echo htmlspecialchars($pagetitle); ?></h1>
        <p>Find answers and tutorials to help you get the most out of the platform</p>
    </div>

    <!-- Search Bar -->
    <div class="help-search-wrapper">
        <div class="help-search-container">
            <i class="fa fa-search help-search-icon"></i>
            <input type="text" 
                   id="helpSearchInput" 
                   class="help-search-input" 
                   placeholder="Search for help videos by category, title or description...">
            <button id="clearSearchBtn" class="help-search-clear" style="display: none;">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div id="searchResultsCount" class="search-results-count" style="display: none;"></div>
    </div>

    <?php if (empty($videosGrouped)): ?>
        <div class="category-section">
            <div class="no-videos">
                <i class="fa fa-video"></i>
                <h3>No help videos available</h3>
                <p>There are currently no help videos available for your role.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($videosGrouped as $category => $data): ?>
            <div class="category-section" data-category-name="<?php echo htmlspecialchars(strtolower($data['name'])); ?>">
                <div class="category-header">
                    <div class="category-title">
                        <i class="fa fa-folder-open"></i>
                        <?php echo htmlspecialchars($data['name']); ?>
                        <span class="category-count"><?php echo count($data['videos']); ?></span>
                    </div>
                </div>

                <div class="videos-grid">
                    <?php foreach ($data['videos'] as $video): ?>
                        <div class="video-card" 
                             data-video-id="<?php echo $video->id; ?>"
                             data-video-url="<?php echo htmlspecialchars($video->video_url instanceof moodle_url ? $video->video_url->out(false) : $video->video_url); ?>"
                             data-embed-url="<?php echo htmlspecialchars($video->embed_url instanceof moodle_url ? $video->embed_url->out(false) : $video->embed_url); ?>"
                             data-video-type="<?php echo htmlspecialchars($video->videotype); ?>"
                             data-has-captions="<?php echo $video->has_captions ? 'true' : 'false'; ?>"
                             data-caption-url="<?php echo htmlspecialchars($video->caption_url instanceof moodle_url ? $video->caption_url->out(false) : ($video->caption_url ?? '')); ?>"
                             data-video-title="<?php echo htmlspecialchars($video->title); ?>"
                             data-video-description="<?php echo htmlspecialchars($video->description ?? ''); ?>"
                             data-category-name="<?php echo htmlspecialchars(strtolower($data['name'])); ?>"
                             data-search-text="<?php echo htmlspecialchars(strtolower($data['name'] . ' ' . $video->title . ' ' . ($video->description ?? ''))); ?>">
                            <div class="video-card-header">
                                <div class="video-icon">
                                    <i class="fa fa-play"></i>
                                </div>
                                <div style="flex: 1;">
                                    <h3 class="video-title"><?php echo htmlspecialchars($video->title); ?></h3>
                                    <?php if ($video->description): ?>
                                        <p class="video-description">
                                            <?php echo htmlspecialchars(core_text::substr($video->description, 0, 120)) . (strlen($video->description) > 120 ? '...' : ''); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="video-meta">
                                <div class="video-meta-item">
                                    <i class="fa fa-eye"></i>
                                    <span><?php echo $video->views ?? 0; ?> views</span>
                                </div>
                                <span class="video-type-badge video-type-<?php echo $video->videotype; ?>">
                                    <?php echo $video->videotype; ?>
                                </span>
                                <?php if ($video->durationformatted): ?>
                                    <div class="video-meta-item">
                                        <i class="fa fa-clock-o"></i>
                                        <span><?php echo $video->durationformatted; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Video Modal -->
<div id="helpVideoModal" class="video-modal">
    <button class="back-to-list-btn" id="backToListBtn" style="display: none;">
        <i class="fa fa-arrow-left"></i> Back to List
    </button>
    <div class="video-modal-content">
        <div class="video-modal-header">
            <h2 class="video-modal-title" id="modalVideoTitle">Video Title</h2>
            <button class="video-modal-close" id="closeVideoModal">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="video-modal-body">
            <div class="video-player-wrapper" id="videoPlayerWrapper">
                <video id="helpVideoPlayer" controls style="display: none;">
                    Your browser does not support the video tag.
                </video>
            </div>
            <div class="video-info-section" id="videoInfoSection" style="display: none;">
                <h3>Description</h3>
                <p id="modalVideoDescription"></p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const videoCards = document.querySelectorAll('.video-card');
    const videoModal = document.getElementById('helpVideoModal');
    const closeModal = document.getElementById('closeVideoModal');
    const backToListBtn = document.getElementById('backToListBtn');
    const videoPlayerWrapper = document.getElementById('videoPlayerWrapper');
    const videoPlayer = document.getElementById('helpVideoPlayer');
    const modalTitle = document.getElementById('modalVideoTitle');
    const modalDescription = document.getElementById('modalVideoDescription');
    const videoInfoSection = document.getElementById('videoInfoSection');
    const searchInput = document.getElementById('helpSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const searchResultsCount = document.getElementById('searchResultsCount');
    
    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            performSearch(this.value.trim());
        });
        
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                performSearch('');
            }
        });
    }
    
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            performSearch('');
            searchInput.focus();
        });
    }
    
    function performSearch(query) {
        const searchTerm = query.toLowerCase();
        let visibleCount = 0;
        let totalCount = 0;
        
        // Show/hide clear button
        if (clearSearchBtn) {
            clearSearchBtn.style.display = searchTerm ? 'flex' : 'none';
        }
        
        // Search through all video cards (includes category, title, and description)
        videoCards.forEach(function(card) {
            totalCount++;
            const searchText = card.getAttribute('data-search-text') || '';
            const categoryName = card.getAttribute('data-category-name') || '';
            
            // Search in category name, title, and description
            const matches = searchTerm === '' || 
                          searchText.includes(searchTerm) || 
                          categoryName.includes(searchTerm);
            
            if (matches) {
                card.classList.remove('hidden');
                visibleCount++;
            } else {
                card.classList.add('hidden');
            }
        });
        
        // Hide/show category sections based on visible videos
        const categorySections = document.querySelectorAll('.category-section');
        categorySections.forEach(function(section) {
            const videosGrid = section.querySelector('.videos-grid');
            if (!videosGrid) {
                section.classList.add('hidden');
                return;
            }
            
            // Also check if category name matches search term
            const categoryName = section.getAttribute('data-category-name') || '';
            const categoryMatches = searchTerm === '' || categoryName.includes(searchTerm);
            
            const visibleVideos = videosGrid.querySelectorAll('.video-card:not(.hidden)');
            if (visibleVideos.length === 0 && !categoryMatches) {
                section.classList.add('hidden');
            } else {
                section.classList.remove('hidden');
            }
        });
        
        // Update search results count
        if (searchResultsCount) {
            if (searchTerm) {
                searchResultsCount.style.display = 'block';
                searchResultsCount.innerHTML = '<strong>' + visibleCount + '</strong> of <strong>' + totalCount + '</strong> videos found';
            } else {
                searchResultsCount.style.display = 'none';
            }
        }
    }
    
    // Open modal when video card is clicked
    videoCards.forEach(function(card) {
        card.addEventListener('click', function() {
            const videoId = this.getAttribute('data-video-id');
            const videoUrl = this.getAttribute('data-video-url');
            const embedUrl = this.getAttribute('data-embed-url');
            const videoType = this.getAttribute('data-video-type');
            const hasCaptions = this.getAttribute('data-has-captions') === 'true';
            const captionUrl = this.getAttribute('data-caption-url');
            const title = this.getAttribute('data-video-title');
            const description = this.getAttribute('data-video-description');
            
            playVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl, title, description);
        });
    });
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            closeVideoModal();
        });
    }
    
    // Close modal on background click
    if (videoModal) {
        videoModal.addEventListener('click', function(e) {
            if (e.target === videoModal) {
                closeVideoModal();
            }
        });
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && videoModal && videoModal.classList.contains('active')) {
            closeVideoModal();
        }
    });
    
    // Back to list button
    if (backToListBtn) {
        backToListBtn.addEventListener('click', function() {
            closeVideoModal();
        });
    }
    
    function playVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl, title, description) {
        // Clear previous content
        videoPlayerWrapper.innerHTML = '';
        videoPlayer.style.display = 'none';
        
        // Remove existing iframe if any
        const existingIframe = document.getElementById('tempVideoIframe');
        if (existingIframe) {
            existingIframe.remove();
        }
        
        // Set title and description
        modalTitle.textContent = title || 'Video';
        if (description) {
            modalDescription.textContent = description;
            videoInfoSection.style.display = 'block';
        } else {
            videoInfoSection.style.display = 'none';
        }
        
        // Show modal
        videoModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        backToListBtn.style.display = 'flex';
        
        // Handle different video types
        if (videoType === 'youtube' || videoType === 'vimeo' || videoType === 'external') {
            // Use iframe for external videos
            const iframe = document.createElement('iframe');
            iframe.id = 'tempVideoIframe';
            iframe.src = embedUrl || videoUrl;
            iframe.width = '100%';
            iframe.style.height = '450px';
            iframe.style.border = 'none';
            iframe.style.borderRadius = '8px';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            iframe.allowFullscreen = true;
            videoPlayerWrapper.appendChild(iframe);
        } else {
            // Use HTML5 video player for uploaded videos
            videoPlayer.style.display = 'block';
            videoPlayer.src = videoUrl;
            
            if (hasCaptions && captionUrl) {
                // Remove existing tracks
                const existingTracks = videoPlayer.querySelectorAll('track');
                existingTracks.forEach(track => track.remove());
                
                // Add caption track
                const track = document.createElement('track');
                track.kind = 'captions';
                track.src = captionUrl;
                track.srclang = 'en';
                track.label = 'English';
                track.default = true;
                videoPlayer.appendChild(track);
            }
            
            videoPlayer.load();
            videoPlayerWrapper.appendChild(videoPlayer);
        }
        
        // Record view
        const wwwroot = typeof M !== 'undefined' && M.cfg ? M.cfg.wwwroot : window.location.origin;
        const sesskey = typeof M !== 'undefined' && M.cfg ? M.cfg.sesskey : '';
        
        fetch(wwwroot + '/local/support/record_view.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'videoid=' + videoId + '&sesskey=' + sesskey
        }).catch(function(error) {
            console.error('Error recording view:', error);
        });
    }
    
    function closeVideoModal() {
        videoModal.classList.remove('active');
        document.body.style.overflow = 'auto';
        backToListBtn.style.display = 'none';
        
        // Pause and reset video
        if (videoPlayer) {
            videoPlayer.pause();
            videoPlayer.currentTime = 0;
            videoPlayer.src = '';
        }
        
        // Remove iframe
        const existingIframe = document.getElementById('tempVideoIframe');
        if (existingIframe) {
            existingIframe.remove();
        }
        
        // Clear wrapper
        videoPlayerWrapper.innerHTML = '';
    }
});
</script>

<?php
// Close sidebar wrapper for all user types
if ($isteacher || $isadmin) {
    echo '</div>'; // Close teacher-main-content need-help-page-wrapper
    echo '</div>'; // Close teacher-dashboard-wrapper
    echo '</div>'; // Close teacher-css-wrapper
} else if (isset($is_student) && $is_student) {
    echo '</div>'; // Close student content wrapper with sidebar
} else {
    echo '</div>'; // Close fallback wrapper
}

echo $OUTPUT->footer();

