<?php
/**
 * Grade 4-7 Student Dashboard Sidebar Component
 * Reusable sidebar for G4G7 Dashboard Learning Platform
 */

defined('MOODLE_INTERNAL') || die();

global $USER, $CFG, $DB;

require_once(__DIR__ . '/../../lib/cohort_sidebar_helper.php');

$has_scratch_editor_access = theme_remui_kids_user_has_scratch_editor_access($USER->id);
$has_code_editor_access = theme_remui_kids_user_has_code_editor_access($USER->id);

// Determine if user should see certificate option (for middle school students - Grade 4-7)
$show_certificates = false;
$usercohortname = '';

try {
    // Get user's cohort information
    if ($DB && isset($USER->id)) {
        $usercohorts = $DB->get_records_sql(
            "SELECT c.name, c.id 
             FROM {cohort} c 
             JOIN {cohort_members} cm ON c.id = cm.cohortid 
             WHERE cm.userid = ?",
            [$USER->id]
        );
        
        if (!empty($usercohorts)) {
            $cohort = reset($usercohorts);
            $usercohortname = $cohort->name;
            
            // Check if user is in middle school cohort (Grade 4-7, g4tog7, etc.)
            if (preg_match('/grade\s*[4-7]/i', $usercohortname) ||
                preg_match('/g\s*[4-7]/i', $usercohortname) ||
                preg_match('/g\s*[4-7]\s*(?:to|till|-)\s*g\s*[4-7]/i', $usercohortname)) {
                $show_certificates = true;
            }
        }
    }
} catch (Exception $e) {
    // If cohort check fails, default to showing certificates for G4G7 sidebar
    $show_certificates = true;
    error_log("G4G7 Sidebar cohort check error: " . $e->getMessage());
}

// Certificate URL for middle school students
$certificates_url = (new moodle_url('/local/certificate_approval/index.php'))->out(false);

// Include this file in any page that needs the G4G7 sidebar
// Usage: include_once('components/g4g7_sidebar.php');
?>

<!-- G4G7 Student Dashboard Sidebar -->
<style>
/* G4G7 Sidebar Styles */
.g4g7-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100vh;
    background: #ffffff;
    border-right: 1px solid #e5e7eb;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    overflow-y: auto;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header Section */
.g4g7-header {
    background: #f0f9ff;
    padding: 25px 20px;
    color: #0369a1;
    position: relative;
    border-bottom: 1px solid #bae6fd;
}

.g4g7-logo-container {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 2;
}

.g4g7-logo {
    width: 45px;
    height: 45px;
    background: #e0f2fe;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.g4g7-logo::before {
    content: 'G4G7';
    font-size: 12px;
    font-weight: bold;
    color: white;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.g4g7-brand {
    flex: 1;
}

.g4g7-brand-name {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.g4g7-brand-subtitle {
    font-size: 12px;
    font-weight: 400;
    margin: 0;
    opacity: 0.9;
    line-height: 1.3;
}

/* Navigation Content */
.g4g7-nav-content {
    padding: 0;
    background: #ffffff;
}

/* Section Headers */
.g4g7-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 16px 16px 8px 16px;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #374151;
    position: relative;
    cursor: pointer;
}

.g4g7-section-dot {
    width: 6px;
    height: 6px;
    background: #6b7280;
    border-radius: 50%;
}

.g4g7-section-toggle {
    font-size: 12px;
    color: #9ca3af;
    transition: transform 0.2s;
}

.g4g7-section-toggle.collapsed {
    transform: rotate(-90deg);
}

/* Navigation Items */
.g4g7-nav-item {
    margin: 0 16px 4px 16px;
    border-radius: 8px;
    overflow: hidden;
}

/* Collapsible Sections */
.g4g7-section-content {
    display: block;
    transition: all 0.3s ease;
}

.g4g7-section-content.collapsed {
    display: none;
}

.g4g7-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    text-decoration: none;
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    border-radius: 8px;
    position: relative;
}

.g4g7-nav-link:hover {
    background: #f3f4f6;
    color: #1f2937;
    text-decoration: none;
}

.g4g7-nav-link.active {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.g4g7-nav-link.active .g4g7-nav-icon {
    color: white;
}

.g4g7-nav-icon {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 16px;
}

.g4g7-nav-text {
    flex: 1;
}

/* Quick Actions Section */
.g4g7-quick-actions {
    padding: 20px 12px;
}

.g4g7-action-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.g4g7-action-card:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}

.g4g7-action-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.g4g7-action-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6366f1;
    font-size: 18px;
}

.g4g7-action-info {
    flex: 1;
}

.g4g7-action-title {
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 4px 0;
}

.g4g7-action-desc {
    font-size: 12px;
    color: #6b7280;
    margin: 0;
    line-height: 1.3;
}

.g4g7-action-arrow {
    width: 24px;
    height: 24px;
    background: #e5e7eb;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 12px;
    transition: all 0.2s ease;
}

.g4g7-action-card:hover .g4g7-action-arrow {
    background: #3b82f6;
    color: white;
}

/* Responsive Design */
@media (max-width: 768px) {
    .g4g7-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .g4g7-sidebar.sidebar-open {
        transform: translateX(0);
    }
    
    .g4g7-sidebar-toggle {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1001;
        background: #3b82f6;
        color: white;
        border: 2px solid #bae6fd;
        border-radius: 8px;
        padding: 12px;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
}

/* Scrollbar Styling */
.g4g7-sidebar::-webkit-scrollbar {
    width: 4px;
}

.g4g7-sidebar::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.g4g7-sidebar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}

.g4g7-sidebar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<!-- Sidebar Toggle Button for Mobile -->
<button class="g4g7-sidebar-toggle" onclick="toggleG4G7Sidebar()" style="display: none;">
    <i class="fa fa-bars"></i>
</button>

<!-- Main Sidebar -->
<div class="g4g7-sidebar" id="g4g7-sidebar">
    <!-- Header -->
    <div class="g4g7-header">
        <div class="g4g7-logo-container">
            <div class="g4g7-logo"></div>
            <div class="g4g7-brand">
                <h1 class="g4g7-brand-name">G4G7</h1>
                <p class="g4g7-brand-subtitle">Dashboard</p>
                <p class="g4g7-brand-subtitle">Learning Platform</p>
            </div>
        </div>
    </div>

    <!-- Navigation Content -->
    <div class="g4g7-nav-content">
        <!-- My Courses Section -->
        <div class="g4g7-section-header" onclick="toggleSection('mycourses')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span>My Courses</span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="mycourses-section" class="g4g7-section-content">
            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/course/" class="g4g7-nav-link active">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-book"></i>
                    </div>
                    <span class="g4g7-nav-text">My Courses</span>
                </a>
            </div>
        </div>

        <!-- Reports & Progress Section -->
        <div class="g4g7-section-header" onclick="toggleSection('reports')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span>Reports & Progress</span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="reports-section" class="g4g7-section-content">
            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/my/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-chart-bar"></i>
                    </div>
                    <span class="g4g7-nav-text">My Reports</span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/badges/mybadges.php" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-trophy"></i>
                    </div>
                    <span class="g4g7-nav-text">Achievements</span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/badges/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-shield-alt"></i>
                    </div>
                    <span class="g4g7-nav-text">Badges</span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/grade/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-graduation-cap"></i>
                    </div>
                    <span class="g4g7-nav-text">Grades</span>
                </a>
            </div>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/course/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-book"></i>
                </div>
                <span class="g4g7-nav-text">My Courses</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/local/lesson/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-play-circle"></i>
                </div>
                <span class="g4g7-nav-text">Lessons</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/local/quiz/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-chart-line"></i>
                </div>
                <span class="g4g7-nav-text">Activities</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/badges/mybadges.php" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-trophy"></i>
                </div>
                <span class="g4g7-nav-text">Achievements</span>
            </a>
        </div>
        
        <?php if ($show_certificates): ?>
        <div class="g4g7-nav-item">
            <a href="<?php echo $certificates_url; ?>" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-certificate"></i>
                </div>
                <span class="g4g7-nav-text">Certificates</span>
            </a>
        </div>
        <?php endif; ?>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/admin/tool/lp/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-bullseye"></i>
                </div>
                <span class="g4g7-nav-text">Competencies</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/grade/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-graduation-cap"></i>
                </div>
                <span class="g4g7-nav-text">Grades</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/badges/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-shield-alt"></i>
                </div>
                <span class="g4g7-nav-text">Badges</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/calendar/" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-calendar"></i>
                </div>
                <span class="g4g7-nav-text">Schedule</span>
            </a>
        </div>
        
        <div class="g4g7-nav-item">
            <a href="<?php echo $CFG->wwwroot; ?>/user/preferences.php" class="g4g7-nav-link">
                <div class="g4g7-nav-icon">
                    <i class="fa fa-cog"></i>
                </div>
                <span class="g4g7-nav-text">Settings</span>
            </a>
        </div>

        <!-- Quick Actions Section -->
        <div class="g4g7-section-header" onclick="toggleSection('quickactions')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span>Quick Actions</span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="quickactions-section" class="g4g7-section-content">
            <div class="g4g7-quick-actions">
                <?php if ($has_scratch_editor_access): ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/scratch_simple.php'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-puzzle-piece"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title">Scratch Editor</h4>
                            <p class="g4g7-action-desc">Create coding projects visually</p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($has_code_editor_access): ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/code_editor_simple.php'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-code"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title">Code Editor</h4>
                            <p class="g4g7-action-desc">Write and run code online</p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($show_certificates): ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $certificates_url; ?>'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-certificate"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title">Certificates</h4>
                            <p class="g4g7-action-desc">View your certificates</p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
            
            <?php if ($has_scratch_editor_access): ?>
            <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/scratch_simple.php'">
                <div class="g4g7-action-content">
                    <div class="g4g7-action-icon">
                        <i class="fa fa-puzzle-piece"></i>
                    </div>
                    <div class="g4g7-action-info">
                        <h4 class="g4g7-action-title">Scratch Editor</h4>
                        <p class="g4g7-action-desc">Create interactive stories and games</p>
                    </div>
                    <div class="g4g7-action-arrow">
                        <i class="fa fa-chevron-right"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($has_code_editor_access): ?>
            <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/code_editor_simple.php'">
                <div class="g4g7-action-content">
                    <div class="g4g7-action-icon">
                        <i class="fa fa-code"></i>
                    </div>
                    <div class="g4g7-action-info">
                        <h4 class="g4g7-action-title">Code Editor</h4>
                        <p class="g4g7-action-desc">Learn programming with hands-on coding</p>
                    </div>
                    <div class="g4g7-action-arrow">
                        <i class="fa fa-chevron-right"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/message/'">
                <div class="g4g7-action-content">
                    <div class="g4g7-action-icon">
                        <i class="fa fa-comments"></i>
                    </div>
                    <div class="g4g7-action-info">
                        <h4 class="g4g7-action-title">Ask Teacher</h4>
                        <p class="g4g7-action-desc">Get help from your teachers</p>
                    </div>
                    <div class="g4g7-action-arrow">
                        <i class="fa fa-chevron-right"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for Sidebar Functionality -->
<script>
window.toggleG4G7Sidebar = function() {
    const sidebar = document.getElementById('g4g7-sidebar');
    if (sidebar) {
        sidebar.classList.toggle('sidebar-open');
    }
};

// Toggle collapsible sections
window.toggleSection = function(sectionId) {
    const section = document.getElementById(sectionId + '-section');
    const toggle = document.querySelector('[onclick="toggleSection(\'' + sectionId + '\')"] .g4g7-section-toggle');

    if (section && toggle) {
        section.classList.toggle('collapsed');
        toggle.classList.toggle('collapsed');
    }
};

// Show/hide toggle button based on screen size
function checkScreenSize() {
    const toggleBtn = document.querySelector('.g4g7-sidebar-toggle');
    const sidebar = document.getElementById('g4g7-sidebar');

    if (window.innerWidth <= 768) {
        toggleBtn.style.display = 'block';
        sidebar.classList.remove('sidebar-open');
    } else {
        toggleBtn.style.display = 'none';
        sidebar.classList.add('sidebar-open');
    }
}

// Check screen size on load and resize
window.addEventListener('load', checkScreenSize);
window.addEventListener('resize', checkScreenSize);

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('g4g7-sidebar');
    const toggleBtn = document.querySelector('.g4g7-sidebar-toggle');

    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Add active class to current page
document.addEventListener('DOMContentLoaded', function() {
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.g4g7-nav-link');

    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href.replace('<?php echo $CFG->wwwroot; ?>', ''))) {
            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));
            // Add active class to current link
            link.classList.add('active');
        }
    });
});
</script>
