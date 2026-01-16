<?php
defined('MOODLE_INTERNAL') || die();

global $CFG, $USER;

$currentparentpage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>

<!-- Parent Dashboard Sidebar -->
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Parent Sidebar Styles - Base (Desktop default) */
.parent-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    background: white;
    border-right: 1px solid #e9ecef;
    z-index: 1000;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    font-family: 'Inter', sans-serif;
    transition: transform 0.3s ease;
}

/* Desktop: Show sidebar by default */
@media screen and (min-width: 1025px) {
    .parent-sidebar {
        transform: translateX(0);
    }
}


/* Hide toggle button by default on desktop */
.parent-sidebar-toggle {
    display: none;
}

.parent-sidebar .sidebar-content {
    padding: 6rem 0 2rem 0;
}

.parent-sidebar .sidebar-section {
    margin-bottom: 2rem;
}

.parent-sidebar .sidebar-category {
    font-size: 0.75rem;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 1rem;
    padding: 0 2rem;
    margin-top: 0;
}

.parent-sidebar .sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.parent-sidebar .sidebar-item {
    margin-bottom: 0.25rem;
}

.parent-sidebar .sidebar-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 2rem;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
}

.parent-sidebar .sidebar-link:hover {
    background-color: #f8f9fa;
    color: #2c3e50;
    text-decoration: none;
    border-left-color: #667eea;
}

.parent-sidebar .sidebar-icon {
    width: 20px;
    height: 20px;
    margin-right: 1rem;
    font-size: 1rem;
    color: #6c757d;
    text-align: center;
}

.parent-sidebar .sidebar-text {
    font-size: 0.9rem;
    font-weight: 500;
}

.parent-sidebar .sidebar-item.active .sidebar-link {
    background-color: #e3f2fd;
    color: #1976d2;
    border-left-color: #1976d2;
}

.parent-sidebar .sidebar-item.active .sidebar-icon {
    color: #1976d2;
}

/* Scrollbar styling */
.parent-sidebar::-webkit-scrollbar {
    width: 6px;
}

.parent-sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.parent-sidebar::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.parent-sidebar::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* ============================================
   RESPONSIVE DESIGN - MOBILE & TABLET
   Hide sidebar by default, show on button click
   ============================================ */
@media screen and (max-width: 1024px) {
    /* Hide sidebar by default on mobile/tablet - Force with !important */
    #parent-sidebar,
    #parent-sidebar.parent-sidebar,
    .parent-sidebar {
        transform: translateX(-100%) !important;
        -webkit-transform: translateX(-100%) !important;
        -moz-transform: translateX(-100%) !important;
        -ms-transform: translateX(-100%) !important;
        z-index: 1001 !important;
        left: -280px !important;
    }
    
    /* Show sidebar when open class is added */
    #parent-sidebar.sidebar-open,
    #parent-sidebar.parent-sidebar.sidebar-open,
    .parent-sidebar.sidebar-open {
        transform: translateX(0) !important;
        -webkit-transform: translateX(0) !important;
        -moz-transform: translateX(0) !important;
        -ms-transform: translateX(0) !important;
        left: 0 !important;
    }
    
    /* Show toggle button on mobile/tablet */
    .parent-sidebar-toggle {
        display: flex !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    /* Adjust main content to full width - Remove all left margin */
    .parent-main-content,
    .parent-main-content.parent-courses-page,
    .parent-main-content.parent-course-view,
    .parent-main-content.schedule-page,
    .parent-assignments-page,
    [class*="parent-main-content"] {
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-left: 16px !important;
        padding-right: 16px !important;
        box-sizing: border-box !important;
    }
    
    .parent-sidebar .sidebar-content {
        padding: 4rem 0 2rem 0;
    }
    
    .parent-sidebar .sidebar-link {
        padding: 0.65rem 1.5rem;
        font-size: 0.85rem;
    }
    
    .parent-sidebar .sidebar-icon {
        width: 18px;
        height: 18px;
        font-size: 0.9rem;
    }
}

@media (max-width: 768px) {
    .parent-sidebar {
        width: 280px;
    }
    
    .parent-sidebar-toggle {
        top: 15px;
        left: 15px;
        padding: 10px 12px;
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    .parent-sidebar {
        width: 100%;
        max-width: 280px;
    }
    
    .parent-sidebar .sidebar-category {
        font-size: 0.7rem;
        padding: 0 1.5rem;
    }
    
    .parent-sidebar .sidebar-link {
        padding: 0.6rem 1.5rem;
    }
    
    .parent-sidebar-toggle {
        top: 12px;
        left: 12px;
        padding: 8px 10px;
        font-size: 14px;
    }
}

/* Desktop - Always show sidebar, hide toggle button */
@media (min-width: 1025px) {
    .parent-sidebar {
        transform: translateX(0) !important;
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    .parent-sidebar-toggle {
        display: none !important;
    }
    
    .sidebar-overlay {
        display: none !important;
    }
}
</style>

<button class="parent-sidebar-toggle" onclick="toggleParentSidebar()" aria-label="Toggle Sidebar">
    <i class="fa fa-bars"></i>
</button>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleParentSidebar()"></div>
<div class="parent-sidebar" id="parent-sidebar">
    <div class="sidebar-content">
        <!-- DASHBOARD Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category"></h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_dashboard.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/my/" class="sidebar-link">
                        <i class="fa fa-th-large sidebar-icon"></i>
                        <span class="sidebar-text">Parent Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_children.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_children.php" class="sidebar-link">
                        <i class="fa fa-child sidebar-icon"></i>
                        <span class="sidebar-text">My Children</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- ACADEMIC Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">ACADEMIC</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_my_courses.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_my_courses.php" class="sidebar-link">
                        <i class="fa fa-book sidebar-icon"></i>
                        <span class="sidebar-text">My Child Courses</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_reports.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_reports.php" class="sidebar-link">
                        <i class="fa fa-chart-bar sidebar-icon"></i>
                        <span class="sidebar-text">Reports</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_schedule.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_schedule.php" class="sidebar-link">
                        <i class="fa fa-calendar-alt sidebar-icon"></i>
                        <span class="sidebar-text">Class Schedule</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_teachers.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_teachers.php" class="sidebar-link">
                        <i class="fa fa-chalkboard-teacher sidebar-icon"></i>
                        <span class="sidebar-text">Teachers</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- LEARNING PROGRESS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">LEARNING PROGRESS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo in_array($currentparentpage, ['parent_learning_progress.php', 'parent_activities.php', 'parent_lessons.php', 'parent_progress.php']) ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_learning_progress.php" class="sidebar-link">
                        <i class="fa fa-chart-line sidebar-icon"></i>
                        <span class="sidebar-text">Learning Progress</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $currentparentpage === 'parent_competencies.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_competencies.php" class="sidebar-link">
                        <i class="fa fa-medal sidebar-icon"></i>
                        <span class="sidebar-text">Competencies & Badges</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- COMMUNICATION Section -->
        

        <!-- COMMUNITY Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">COMMUNITY</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $currentparentpage === 'community.php' ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/community.php" class="sidebar-link">
                        <i class="fa fa-users sidebar-icon"></i>
                        <span class="sidebar-text">Community Hub</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- SETTINGS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">SETTINGS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item">
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_profile.php" class="sidebar-link">
                        <i class="fa fa-user-circle sidebar-icon"></i>
                        <span class="sidebar-text">My Profile</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="<?php echo $CFG->wwwroot; ?>/user/preferences.php" class="sidebar-link">
                        <i class="fa fa-cog sidebar-icon"></i>
                        <span class="sidebar-text">Settings</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="<?php echo $CFG->wwwroot; ?>/login/logout.php" class="sidebar-link">
                        <i class="fa fa-sign-out-alt sidebar-icon"></i>
                        <span class="sidebar-text">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
// Simple Toggle Function - Just adds/removes class
function toggleParentSidebar() {
    var sidebar = document.getElementById('parent-sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    
    if (sidebar) {
        sidebar.classList.toggle('sidebar-open');
        
        // Toggle overlay on mobile/tablet
        if (overlay && window.innerWidth <= 1024) {
            overlay.classList.toggle('active');
        }
    }
}

// Initialize on page load - Force hide on mobile/tablet
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('parent-sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    
    // Force hide sidebar on mobile/tablet on page load
    if (sidebar) {
        if (window.innerWidth <= 1024) {
            sidebar.classList.remove('sidebar-open');
            // Force transform with inline style
            sidebar.style.transform = 'translateX(-100%)';
            sidebar.style.webkitTransform = 'translateX(-100%)';
            sidebar.style.mozTransform = 'translateX(-100%)';
            sidebar.style.msTransform = 'translateX(-100%)';
        } else {
            // Desktop: ensure sidebar is visible
            sidebar.classList.add('sidebar-open');
            sidebar.style.transform = 'translateX(0)';
            sidebar.style.webkitTransform = 'translateX(0)';
            sidebar.style.mozTransform = 'translateX(0)';
            sidebar.style.msTransform = 'translateX(0)';
        }
    }
    
    // Close sidebar when clicking overlay
    if (overlay) {
        overlay.addEventListener('click', function() {
            if (sidebar && sidebar.classList.contains('sidebar-open')) {
                toggleParentSidebar();
            }
        });
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (sidebar) {
            if (window.innerWidth > 1024) {
                // Desktop: show sidebar
                sidebar.classList.add('sidebar-open');
                sidebar.style.transform = 'translateX(0)';
                sidebar.style.webkitTransform = 'translateX(0)';
                sidebar.style.mozTransform = 'translateX(0)';
                sidebar.style.msTransform = 'translateX(0)';
                if (overlay) {
                    overlay.classList.remove('active');
                }
            } else {
                // Mobile/Tablet: hide sidebar if not manually opened
                if (!sidebar.classList.contains('sidebar-open')) {
                    sidebar.style.transform = 'translateX(-100%)';
                    sidebar.style.webkitTransform = 'translateX(-100%)';
                    sidebar.style.mozTransform = 'translateX(-100%)';
                    sidebar.style.msTransform = 'translateX(-100%)';
                }
                if (overlay) {
                    overlay.classList.remove('active');
                }
            }
        }
    });
});
</script>


