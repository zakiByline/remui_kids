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
 * Super Admin Reporting Dashboard - Comprehensive analytics and reporting
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/admin/superreports/lib.php');

require_login();
$context = context_system::instance();

// Restrict to site admins only
if (!is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'access super admin reports');
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/superreports/index.php');
$PAGE->set_title('Super Admin Reports');

$PAGE->set_pagelayout('base');
$PAGE->add_body_class('superreports-page');
$PAGE->set_cacheable(false);
echo $OUTPUT->header();

// Add external CSS
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/admin/superreports/style.css">';
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/admin/superreports/competencies_styles.css">';

// Add jQuery and Chart.js CDN
echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';

// Add custom CSS for the superreports page with admin sidebar
echo '<style>
@import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap\');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #f5f7fa;
    font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;
    overflow-x: hidden;
}

/* Ensure body has space for fixed header */
body.superreports-page {
    padding-top: 0 !important;
}

#region-main,
[role=main] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Hide default Moodle page header content since we have custom header */
#page-header {
    display: none !important;
}

/* Ensure page wrapper does not add extra space */
#page-wrapper,
#page {
    margin: 0 !important;
    padding: 0 !important;
}

/* Handle footer */
#page-footer {
    display: block !important;
    margin-left: 280px;
    width: calc(100% - 280px);
    background: white;
    padding: 1rem;
    border-top: 1px solid #e9ecef;
}

/* Smooth scrolling */
html {
    scroll-behavior: smooth;
}

/* Ensure Moodle navbar is visible and above everything */


/* Ensure navbar content is visible */


/* Make sure wrapper does not cover header */
.admin-dashboard-wrapper {
    position: relative;
    z-index: 1 !important;
    padding-top: 0;
    margin-top: 0;
}

/* Admin Sidebar Navigation - Sticky on all pages */
.admin-sidebar {
    position: fixed !important;
    top: 60px; /* Below navbar - adjust based on your navbar height */
    left: 0;
    width: 280px;
    height: calc(100vh - 50px); /* Full height minus navbar */
    background: white;
    border-right: 1px solid #e9ecef;
    z-index: 900;
    overflow-y: auto;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    will-change: transform;
    backface-visibility: hidden;
}

.admin-sidebar .sidebar-content {
    padding: 1.5rem 0;
}

.admin-sidebar .sidebar-section {
    margin-bottom: 2rem;
}

.admin-sidebar .sidebar-category {
    font-size: 0.75rem;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 1rem;
    padding: 0 2rem;
    margin-top: 0;
}

.admin-sidebar .sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.admin-sidebar .sidebar-item {
    margin-bottom: 0.25rem;
}

.admin-sidebar .sidebar-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 2rem;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
}

.admin-sidebar .sidebar-link:hover {
    background-color: #f8f9fa;
    color: #2c3e50;
    text-decoration: none;
    border-left-color: #667eea;
}

.admin-sidebar .sidebar-icon {
    width: 20px;
    height: 20px;
    margin-right: 1rem;
    font-size: 1rem;
    color: #6c757d;
    text-align: center;
}

.admin-sidebar .sidebar-text {
    font-size: 0.9rem;
    font-weight: 500;
}

.admin-sidebar .sidebar-item.active .sidebar-link {
    background-color: #e3f2fd;
    color: #1976d2;
    border-left-color: #1976d2;
}

.admin-sidebar .sidebar-item.active .sidebar-icon {
    color: #1976d2;
}

/* Scrollbar styling */
.admin-sidebar::-webkit-scrollbar {
    width: 6px;
}

.admin-sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.admin-sidebar::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.admin-sidebar::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Main content area with sidebar - FULL SCREEN */
.admin-main-content {
    position: fixed;
    top: 10px; /* Below navbar */
    left: 280px;
    width: calc(100% - 280px);
    max-width: calc(100vw - 280px);
    height: calc(100vh - 50px); /* Full height minus navbar */
    background-color: #f5f7fa;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 99;
    will-change: transform;
    backface-visibility: hidden;
    padding: 0;
    box-sizing: border-box;
}

/* Wrapper inside main content */
.superreports-wrapper {
    padding: 1.5rem;
    min-height: 100%;
}

/* Ensure inner content respects container width */
.admin-main-content > * {
    max-width: 100%;
    box-sizing: border-box;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .admin-sidebar {
        position: fixed;
        top: 50px; /* Below navbar */
        left: 0;
        width: 280px;
        height: calc(100vh - 50px);
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1001;
    }
    
    .admin-sidebar.sidebar-open {
        transform: translateX(0);
    }
    
    .admin-main-content {
        position: relative;
        top: 50px; /* Below navbar */
        left: 0;
        width: 100vw;
        height: auto;
        min-height: calc(100vh - 50px);
        padding: 0;
    }
    
    .superreports-wrapper {
        padding: 1rem;
    }
    
    .sidebar-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        bottom: 20px;
        left: 20px;
        z-index: 1100;
        background: #3498db;
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 60px;
        height: 60px;
        cursor: pointer;
        box-shadow: 0 8px 24px rgba(52, 152, 219, 0.4);
        transition: all 0.3s ease;
    }
    
    .sidebar-toggle:hover {
        background: #2980b9;
        transform: translateY(-4px);
    }
    
    .sidebar-toggle i {
        font-size: 24px;
    }
}

/* Desktop - hide sidebar toggle */
@media (min-width: 769px) {
    .sidebar-toggle {
        display: none !important;
    }
}
</style>';

// Wrapper with sidebar
echo '<div class="admin-dashboard-wrapper">';

// Mobile Sidebar Toggle Button
echo '<button class="sidebar-toggle" onclick="toggleAdminSidebar()">';
echo '<i class="fa fa-bars"></i>';
echo '</button>';

// Include admin sidebar from includes
require_once(__DIR__ . '/../includes/admin_sidebar.php');

// Main Content Area
echo '<div class="admin-main-content">';
echo '<div class="superreports-wrapper">';

// Header Section
echo '<div class="superreports-header">';
echo '<div class="header-left">';
echo '<h1 class="header-title"><i class="fa fa-chart-bar"></i>Admin Reports</h1>';
echo '</div>';
echo '<div class="header-right">';
echo '<div class="global-filters">';

// School Selector
echo '<div class="filter-group">';
echo '<label for="schoolFilter"><i class="fa fa-school"></i></label>';
echo '<select id="schoolFilter" class="filter-select">';
echo '<option value="">All Schools</option>';
// Get all companies (schools) - check if table exists
try {
    if ($DB->get_manager()->table_exists('company')) {
        $companies = $DB->get_records('company', null, 'name ASC');
        foreach ($companies as $company) {
            echo '<option value="' . $company->id . '">' . format_string($company->name) . '</option>';
        }
    }
} catch (Exception $e) {
    // Silently fail - just show "All Schools" option
}
echo '</select>';
echo '</div>';

// Grade/Cohort Selector
echo '<div class="filter-group">';
echo '<label for="gradeFilter"><i class="fa fa-layer-group"></i></label>';
echo '<select id="gradeFilter" class="filter-select">';
echo '<option value="">All Grades</option>';
// Get all cohorts (grades/classes) dynamically
try {
    $cohorts = $DB->get_records('cohort', null, 'name ASC');
    if (!empty($cohorts)) {
        foreach ($cohorts as $cohort) {
            echo '<option value="' . $cohort->id . '">' . format_string($cohort->name) . '</option>';
        }
    } else {
        // Fallback: if no cohorts exist, show basic grade options
        for ($i = 1; $i <= 12; $i++) {
            echo '<option value="' . $i . '">Grade ' . $i . '</option>';
        }
    }
} catch (Exception $e) {
    // Fallback: if cohort table doesn't exist, show basic grade options
    for ($i = 1; $i <= 12; $i++) {
        echo '<option value="' . $i . '">Grade ' . $i . '</option>';
    }
}
echo '</select>';
echo '</div>';

// Competency Framework Selector (for competencies tab)
echo '<div class="filter-group" id="frameworkFilterGroup" style="display:none;">';
echo '<label for="frameworkFilter"><i class="fa fa-sitemap"></i></label>';
echo '<select id="frameworkFilter" class="filter-select">';
echo '<option value="">All Frameworks</option>';
// Get all competency frameworks
try {
    if ($DB->get_manager()->table_exists('competency_framework')) {
        $frameworks = $DB->get_records('competency_framework', null, 'shortname ASC');
        foreach ($frameworks as $framework) {
            echo '<option value="' . $framework->id . '">' . format_string($framework->shortname) . '</option>';
        }
    }
} catch (Exception $e) {
    // Silently fail
}
echo '</select>';
echo '</div>';

// Date Range Selector
echo '<div class="filter-group">';
echo '<label for="dateRangeFilter"><i class="fa fa-calendar"></i></label>';
echo '<select id="dateRangeFilter" class="filter-select">';
echo '<option value="week">This Week</option>';
echo '<option value="month" selected>This Month</option>';
echo '<option value="quarter">This Quarter</option>';
echo '<option value="year">This Year</option>';
echo '<option value="custom">Custom Range</option>';
echo '</select>';
echo '</div>';

// Custom date inputs (hidden by default)
echo '<div id="customDateInputs" class="custom-date-inputs" style="display:none;">';
echo '<input type="date" id="startDate" class="date-input">';
echo '<span class="date-separator">to</span>';
echo '<input type="date" id="endDate" class="date-input">';
echo '</div>';

// Refresh Button
echo '<button id="refreshBtn" class="action-btn refresh-btn" onclick="refreshDashboard()">';
echo '<i class="fa fa-sync-alt"></i> Refresh';
echo '</button>';

// Export CSV Button
echo '<button class="action-btn export-btn" onclick="exportToCSV()">';
echo '<i class="fa fa-file-csv"></i> Export to CSV';
echo '</button>';

echo '</div>'; // global-filters
echo '</div>'; // header-right
echo '</div>'; // superreports-header

// AI Summary Section (always visible at top)
echo '<div class="ai-summary-section">';
echo '<div class="ai-summary-card">';
echo '<h3><i class="fa fa-robot"></i> AI-Powered Insights Summary</h3>';
echo '<div id="aiSummaryContent" class="ai-summary-content">';
echo '<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Generating insights...</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Tab Navigation
echo '<div class="tabs-container">';
echo '<div class="tabs-nav">';
echo '<button class="tab-btn active" data-tab="overview"><i class="fa fa-home"></i> Overview</button>';
echo '<button class="tab-btn" data-tab="assignments"><i class="fa fa-file-alt"></i> Assignments</button>';
echo '<button class="tab-btn" data-tab="quizzes"><i class="fa fa-question-circle"></i> Quizzes</button>';
echo '<button class="tab-btn" data-tab="overall-grades"><i class="fa fa-chart-line"></i> Overall Grades</button>';
echo '<button class="tab-btn" data-tab="competencies"><i class="fa fa-puzzle-piece"></i> Competencies</button>';
echo '<button class="tab-btn" data-tab="performance"><i class="fa fa-users"></i> Performance</button>';
echo '<button class="tab-btn" data-tab="courses"><i class="fa fa-book"></i> Courses</button>';
echo '<button class="tab-btn" data-tab="activity"><i class="fa fa-comments"></i> Activity & Engagement</button>';
echo '<button class="tab-btn" data-tab="attendance"><i class="fa fa-calendar-check"></i> Attendance</button>';
echo '</div>';
echo '</div>';

// Tab Content Container
echo '<div class="tabs-content">';

// Overview Tab (default loaded)
echo '<div id="overview-tab" class="tab-content active">';
echo '<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Loading data...</div>';
echo '</div>';

// Other tabs (loaded via AJAX)
echo '<div id="assignments-tab" class="tab-content"></div>';
echo '<div id="quizzes-tab" class="tab-content"></div>';
echo '<div id="overall-grades-tab" class="tab-content"></div>';
echo '<div id="competencies-tab" class="tab-content"></div>';
echo '<div id="performance-tab" class="tab-content"></div>';
echo '<div id="courses-tab" class="tab-content"></div>';
echo '<div id="activity-tab" class="tab-content"></div>';
echo '<div id="attendance-tab" class="tab-content"></div>';

echo '</div>'; // tabs-content

echo '</div>'; // superreports-wrapper
echo '</div>'; // admin-main-content
echo '</div>'; // admin-dashboard-wrapper

// Pass PHP configuration to JavaScript
echo '<script>
// Initialize Moodle config if not exists
if (typeof M === "undefined") {
    var M = { cfg: {} };
}
if (!M.cfg) {
    M.cfg = {};
}
M.cfg.wwwroot = "' . $CFG->wwwroot . '";
M.cfg.sesskey = "' . sesskey() . '";
</script>';

// JavaScript for tab switching and AJAX
echo '<script src="' . $CFG->wwwroot . '/theme/remui_kids/admin/superreports/script.js"></script>';
echo '<script src="' . $CFG->wwwroot . '/theme/remui_kids/admin/superreports/competencies_render.js"></script>';

echo '<script>
// Sidebar toggle function
function toggleAdminSidebar() {
    const sidebar = document.querySelector(".admin-sidebar");
    sidebar.classList.toggle("sidebar-open");
}

// Close sidebar when clicking outside on mobile
document.addEventListener("click", function(event) {
    const sidebar = document.querySelector(".admin-sidebar");
    const toggleButton = document.querySelector(".sidebar-toggle");
    
    if (window.innerWidth <= 768) {
        if (sidebar && toggleButton && !sidebar.contains(event.target) && !toggleButton.contains(event.target)) {
            sidebar.classList.remove("sidebar-open");
        }
    }
});

// Handle window resize
window.addEventListener("resize", function() {
    const sidebar = document.querySelector(".admin-sidebar");
    if (sidebar && window.innerWidth > 768) {
        sidebar.classList.remove("sidebar-open");
    }
});

// Wait for jQuery, DOM and Chart.js to be ready
function initializeDashboard() {
    if (typeof $ !== "undefined" && typeof $.fn !== "undefined" && typeof Chart !== "undefined") {
        loadTabData("overview");
    } else {
        // If jQuery or Chart.js is not loaded yet, wait a bit and try again
        setTimeout(initializeDashboard, 100);
    }
}

// Use jQuery ready if available, otherwise DOMContentLoaded
if (typeof $ !== "undefined" && typeof $.fn !== "undefined") {
    $(document).ready(function() {
        initializeDashboard();
    });
} else {
    document.addEventListener("DOMContentLoaded", function() {
        initializeDashboard();
    });
}
</script>';

echo $OUTPUT->footer();

