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
 * Teacher's Courses Dashboard - Shows courses organized by categories
 *
 * @package   theme_remui_kids
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Prevent any output buffering issues
ob_start();

require_once('../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

// Debug: Log that this page is being accessed
if (debugging()) {
    error_log("Teacher Courses Page Accessed - User ID: " . $USER->id);
    // Output some debug info immediately
    echo "<!-- DEBUG: Teacher Courses Page Starting -->";
}

// Require login and proper access.
require_login();

// Prevent any redirects
$PAGE->set_context(context_system::instance());
$context = $PAGE->context;

// Check if user has teacher capabilities - simplified check
$isteacher = false;
$can_create_courses = false;

// Check for site admin first
if (is_siteadmin()) {
    $isteacher = true;
    $can_create_courses = true;
} else {
    // Check for teacher roles
    $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
    $roleids = array_keys($teacherroles);

    if (!empty($roleids)) {
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['userid'] = $USER->id;
        $params['ctxlevel'] = CONTEXT_COURSE;
        
        $teacher_courses = $DB->get_records_sql(
            "SELECT DISTINCT ctx.instanceid as courseid
             FROM {role_assignments} ra
             JOIN {context} ctx ON ra.contextid = ctx.id
             WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
             LIMIT 1",
            $params
        );
        
        if (!empty($teacher_courses)) {
            $isteacher = true;
            // Allow course creation for any teacher role
            $can_create_courses = true;
        }
    }
    
    // Also check system context for course creation capability
    if ($isteacher && !$can_create_courses) {
        $can_create_courses = has_capability('moodle/course:create', context_system::instance());
    }
}

if (!$isteacher) {
    throw new moodle_exception('nopermissions', 'error', '', 'You must be a teacher to access this page');
}

// Debug information (remove in production)
if (debugging()) {
    error_log("Teacher Courses Debug - User ID: " . $USER->id);
    error_log("Teacher Courses Debug - Is Teacher: " . ($isteacher ? 'Yes' : 'No'));
    error_log("Teacher Courses Debug - Can Create Courses: " . ($can_create_courses ? 'Yes' : 'No'));
    error_log("Teacher Courses Debug - Is Site Admin: " . (is_siteadmin() ? 'Yes' : 'No'));
}

// Get teacher's school (company) ID using helper function
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Set up the page.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/teacher_courses.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Courses - Teacher Dashboard');
$PAGE->set_heading('');

// Debug: Check if page is being set up correctly
if (debugging()) {
    error_log("Page URL set to: " . $PAGE->url->out());
}

// Add a specific body class so we can safely scope page-specific CSS overrides
$PAGE->add_body_class('teacher-courses-page');

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

// No breadcrumb needed for this page

// Add Font Awesome CSS directly to head
$PAGE->requires->js_init_code('
    var link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css";
    document.head.appendChild(link);
');

// Sidebar toggle functionality will be added via inline script below

echo $OUTPUT->header();

// Add complete modern CSS
echo '<style>
/* Neutralize the default main container */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Remove the main page container */
#page.drawers.drag-container {
    background: transparent !important;
    margin: 0 !important;
    padding: 0 !important;
    box-shadow: none !important;
    border: 0 !important;
}

/* Remove the d-flex flex-wrap container */
div.d-flex.flex-wrap {
    display: none !important;
}

.teacher-courses-page .footer-container,
.teacher-courses-page .footer-container.container,
.teacher-courses-page #page-footer,
.teacher-courses-page .page-footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Modern Course Management Layout */
.courses-page {
    min-height: 100vh;
    background: #f8fafc;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    padding-top: 0;
    margin-top: 0;
}

.teacher-courses-page .teacher-main-content {
    padding-top: 180px !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    padding-bottom: 24px !important;
}

/* Unified Container */
.unified-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    margin: 24px;
    margin-top: 0;
    overflow: hidden;
}

.dashboard-hero {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.75rem 2rem;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    background: linear-gradient(135deg, rgb(255, 255, 255), rgb(244, 247, 248));
    border-radius: 18px 18px 0 0;
    border-top: 1px solid transparent;
    border-bottom: 6px solid transparent;
    border-image: linear-gradient(90deg, #9fa1ff, #98dbfa, #92f0e5);
    border-image-slice: 1;
    margin-bottom: 1.5rem;
}

.dashboard-hero h1 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
}

.dashboard-hero p {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.dashboard-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.dashboard-hero-copy {
    flex: 1;
    min-width: 260px;
}

.dashboard-hero-header .header-filters {
    display: flex;
    align-items: center;
    gap: 16px;
    background: rgba(255, 255, 255, 0.65);
    padding: 10px;
    border-radius: 16px;
    backdrop-filter: blur(6px);
    position: relative;
    overflow: visible;
    z-index: 1000;
}

.courses-container {
    padding: 32px;
}

.booktype-summary-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin: 20px 32px 0 32px;
}

.booktype-summary-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    padding: 14px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.booktype-summary-card span {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #94a3b8;
}

.booktype-summary-card strong {
    font-size: 18px;
    color: #0f172a;
}


.filter-dropdown {
    position: relative;
    min-width: 180px;
    overflow: visible;
    z-index: 1000;
}

.filter-dropdown-toggle {
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 11px 16px;
    border-radius: 12px;
    border: 1px solid #dfe6fb;
    background: linear-gradient(120deg, #ffffff, #f5f7ff);
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    cursor: pointer;
    transition: border 0.2s ease, box-shadow 0.2s ease;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 6px 15px rgba(15, 23, 42, 0.06);
}

.filter-dropdown-toggle:hover {
    border-color: #94a3ff;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 10px 24px rgba(79, 70, 229, 0.18);
}

.filter-label {
    max-width: 140px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.filter-dropdown-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    min-width: 220px;
    background: #ffffff;
    border: 1px solid #e5e7fb;
    border-radius: 12px;
    box-shadow: 0 24px 45px rgba(15, 23, 42, 0.14);
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    opacity: 0;
    pointer-events: none;
    transform: translateY(-6px);
    transition: opacity 0.2s ease, transform 0.2s ease;
    z-index: 1001;
}

.filter-dropdown.open .filter-dropdown-menu {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}

.category-filters,
.booktype-filters {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.category-btn,
.booktype-btn {
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid transparent;
    background: #f8fafc;
    color: #4b5563;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.category-btn span,
.booktype-btn span {
    font-size: 13px;
}

.category-btn small,
.booktype-btn small {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 500;
}
.category-btn:hover,
.booktype-btn:hover {
    background: #eef2ff;
    color: #312e81;
}

.category-btn.active,
.booktype-btn.active {
    background: #3b82f6;
    border-color: #2563eb;
    color: #ffffff;
}

.view-toggles {
    display: flex;
    gap: 0;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
    background: white;
}

.view-toggle {
    padding: 8px 12px;
    border: none;
    background: white;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.view-toggle.active {
    background: #3b82f6;
    color: white;
}

.view-toggle:hover:not(.active) {
    background: #f9fafb;
    color: #374151;
}

.view-toggle:active {
    transform: scale(0.98);
}

.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

/* List View Styles */
.courses-grid.list-view {
    grid-template-columns: 1fr;
    gap: 12px;
}

.courses-grid.list-view .course-card {
    flex-direction: row;
    align-items: center;
    padding: 12px 16px;
    height: 250px;
    background: radial-gradient(circle at top left, rgba(99,102,241,0.08), rgba(255,255,255,0.95));
    border: 1px solid rgba(148,163,184,0.2);
    border-radius: 18px;
    box-shadow: 0 14px 32px rgba(15,23,42,0.08);
    gap: 18px;
}

.courses-grid.list-view .course-image-wrapper {
    width: 64px;
    height: 64px;
    min-width: 64px;
    flex-shrink: 0;
    margin-right: 12px;
    border-radius: 8px;
}

.courses-grid.list-view .course-image {
    border-radius: 8px;
}

.courses-grid.list-view .course-image-placeholder {
    border-radius: 8px;
    font-size: 24px;
}

.courses-grid.list-view .course-category-badge {
    font-size: 9px;
    padding: 4px 8px;
    top: 6px;
    left: 6px;
}

.courses-grid.list-view .course-card-content {
    flex: 1;
    padding: 0;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 20px;
}

.courses-grid.list-view .course-header-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}

.courses-grid.list-view .course-title-block h3 {
    font-size: 15px;
    margin: 0;
    -webkit-line-clamp: 1;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.courses-grid.list-view .course-title-block p {
    font-size: 11px;
}

.courses-grid.list-view .course-highlight-value {
    font-size: 18px;
}

.courses-grid.list-view .course-icon-row {
    display: flex;
    flex: 1;
    gap: 10px;
    justify-content: center;
}

.courses-grid.list-view .course-icon-stat {
    flex: 1 1 100px;
}

.courses-grid.list-view .course-bottom-stats {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    min-width: 105px;
}

.courses-grid.list-view .course-actions {
    margin: 0;
    gap: 8px;
    flex-direction: column;
    width: 140px;
}

.courses-grid.list-view .btn-preview,
.courses-grid.list-view .btn-view {
    width: 100%;
    min-width: 0;
    padding: 8px 10px;
}

.courses-grid.list-view .course-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 40px rgba(15,23,42,0.12);
}

/* Course Cards - Professional Design */
.course-card {
    position: relative;
    background: #ffffff;
    border-radius: 20px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    min-height: 420px;
    z-index: 1;
}

.course-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.12);
}

/* Course Image */
.course-image-wrapper {
    position: relative;
    width: 100%;
    height: 200px;
    overflow: hidden;
    background: #f3f4f6;
}

.course-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}

.course-image-wrapper:hover .course-image {
    transform: scale(1.05);
}

.course-image-fallback {
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    transition: transform 0.3s ease;
    position: relative;
}

.course-image-fallback.no-image {
    background: #050505;
}

.course-card:hover .course-image-fallback {
    transform: scale(1.04);
}

.course-status-badge {
    position: absolute;
    top: 18px;
    left: 18px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
}

.course-status-badge .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    display: inline-block;
}

.course-status-badge.active .status-dot {
    background: #22c55e;
}

.course-status-badge.draft .status-dot {
    background: #f97316;
}

.course-status-badge.active {
    color: #15803d;
}

.course-status-badge.draft {
    color: #9a3412;
}

.course-type-label {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(15, 23, 42, 0.82);
    color: #f8fafc;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    padding: 10px 18px;
    border-radius: 999px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.25);
    white-space: nowrap;
    max-width: 80%;
    overflow: hidden;
    text-overflow: ellipsis;
}

.course-type-label.student-book {
    background: linear-gradient(120deg, #22c55e, #16a34a);
    color: #f0fdf4;
}

.course-type-label.student-course {
    background: linear-gradient(120deg, #3b82f6, #2563eb);
    color: #eff6ff;
}

.course-type-label.teacher-resource {
    background: linear-gradient(120deg, #f97316, #f59e0b);
    color: #fff7ed;
}

.course-type-label.worksheet-pack {
    background: linear-gradient(120deg, #0ea5e9, #2563eb);
    color: #eff6ff;
}

.course-type-label.teacher-guide {
    background: linear-gradient(120deg, #ec4899, #db2777);
    color: #fdf2f8;
}

.course-type-label.practice-book {
    background: linear-gradient(120deg, #8b5cf6, #7c3aed);
    color: #f5f3ff;
}

.course-type-label.teacher-book {
    background: linear-gradient(120deg, #14b8a6, #0d9488);
    color: #ecfdf5;
}

.course-image-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f9fafb;
    color: #9ca3af;
    font-size: 48px;
}

.course-category-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 4px 10px;
    background: rgba(255, 255, 255, 0.96);
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    color: #1f2937;
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
}

/* Course Content */
.course-card-content {
    padding: 12px 16px 16px;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 10px;
}

.course-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
}

.course-title-block h3 {
    font-size: 15px;
    margin: 0 0 2px 0;
    color: #0f172a;
    font-weight: 600;
}

.course-title-block p {
    margin: 0;
    font-size: 11px;
    color: #6b7280;
}

.course-highlight {
    text-align: right;
}

.course-highlight-label {
    font-size: 9px;
    text-transform: uppercase;
    color: #94a3b8;
    letter-spacing: 0.08em;
    display: block;
}

.course-highlight-value {
    font-size: 14px;
    font-weight: 700;
    color: #2563eb;
}

.course-icon-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
}

.course-icon-stat {
    padding: 6px 8px;
    border: 1px solid #edf2ff;
    border-radius: 12px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    gap: 6px;
}

.course-icon-stat .stat-icon {
    width: 24px;
    height: 24px;
    border-radius: 8px;
    background: #e0e7ff;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4338ca;
    font-size: 12px;
    flex-shrink: 0;
}

.course-icon-stat .stat-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}

.course-icon-stat .label {
    font-size: 9px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.course-icon-stat .value {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.course-bottom-stats {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    align-items: center;
}

.course-bottom-stats .stat-pill {
    background: #eef2ff;
    color: #4338ca;
    padding: 4px 8px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 10px;
}

.course-bottom-stats .stat-muted {
    color: #6b7280;
    font-size: 10px;
}

/* Course Actions */
.course-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}

.btn-preview,
.btn-view {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.btn-preview i,
.btn-view i {
    font-size: 11px;
}

.btn-preview {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}

.btn-preview:hover {
    background: #e5e7eb;
    color: #1f2937;
    text-decoration: none;
    transform: translateY(-1px);
}

.btn-view {
    background: linear-gradient(90deg, #3b82f6 0%, #2563eb 50%, #3b82f6 100%);
    background-size: 200% 100%;
    color: white;
    box-shadow: 0 10px 20px rgba(59, 130, 246, 0.25);
    transition: background-position 0.3s ease, box-shadow 0.2s ease;
}

.btn-view:hover {
    background-position: -100% 0;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(59, 130, 246, 0.35);
}

.btn-preview i,
.btn-view i {
    font-size: 12px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #f9fafb;
    border-radius: 12px;
    border: 2px dashed #e5e7eb;
}

.empty-icon {
    font-size: 48px;
    color: #9ca3af;
    margin-bottom: 16px;
}

.empty-title {
    font-size: 20px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 8px 0;
}

.empty-text {
    font-size: 14px;
    color: #6b7280;
    margin: 0 0 24px 0;
}

.btn-create {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #3b82f6;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-create:hover {
    background: #2563eb;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

@media (max-width: 768px) {
    .courses-grid {
        grid-template-columns: 1fr;
    }
    
    .courses-container {
        padding: 24px 16px 32px;
    }
    
    .dashboard-hero-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .header-filters {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-dropdown {
        width: 100%;
    }
    
    .course-actions {
        flex-direction: column;
    }
    
    .btn-preview,
    .btn-view {
        width: 100%;
    }
    
    .course-icon-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    
    .course-icon-stat {
        width: 100%;
    }
    
    .course-bottom-stats {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    /* List View Mobile Adjustments */
    .courses-grid.list-view .course-card {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .courses-grid.list-view .course-image-wrapper {
        width: 100%;
        height: 110px;
        margin-right: 0;
        margin-bottom: 12px;
    }
    
    .courses-grid.list-view .course-card-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        width: 100%;
    }
    
    .courses-grid.list-view .course-title-block h3 {
        width: 100%;
    }
    
    .courses-grid.list-view .course-actions {
        width: 100%;
    }
    
    .courses-grid.list-view .btn-preview,
    .courses-grid.list-view .btn-view {
        flex: 1;
    }
}
/* Teacher Help Button Styles */
.teacher-help-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.teacher-help-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.5);
}

.teacher-help-button i {
    font-size: 16px;
}

.help-badge-count {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
    min-width: 20px;
    text-align: center;
}

/* Teacher Help Video Modal Styles */
.teacher-help-video-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 10000;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.3s ease;
}

.teacher-help-video-modal.active {
    display: flex;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.teacher-help-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
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

.teacher-help-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    border-bottom: 2px solid #f0f0f0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.teacher-help-modal-header h2 {
    margin: 0;
    font-size: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.teacher-help-modal-close {
    background: none;
    border: none;
    font-size: 32px;
    cursor: pointer;
    color: white;
    transition: transform 0.3s ease;
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.teacher-help-modal-close:hover {
    transform: rotate(90deg);
}

.teacher-help-modal-body {
    padding: 25px;
    overflow-y: auto;
    flex: 1;
}

.teacher-help-videos-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.teacher-help-video-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.teacher-help-video-item:hover {
    background: #e9ecef;
    border-color: #667eea;
    transform: translateX(5px);
}

.teacher-help-video-item h4 {
    margin: 0 0 8px 0;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.teacher-help-video-item p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.back-to-list-btn {
    background: #667eea;
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    margin-bottom: 15px;
}

.back-to-list-btn:hover {
    background: #5568d3;
    transform: translateX(-3px);
}

.teacher-help-video-player {
    width: 100%;
}

.teacher-help-video-player video {
    width: 100%;
    border-radius: 8px;
}

/* Responsive */
@media (max-width: 768px) {
    .teacher-help-button span:not(.help-badge-count) {
        display: none;
    }
    
    .teacher-help-modal-content {
        width: 95%;
        max-height: 90vh;
    }
    
    .teacher-help-modal-header h2 {
        font-size: 18px;
    }
}
</style>';

// Add a simple test message to verify page is loading
if (debugging()) {
    echo '<div style="background: #d1fae5; color: #059669; padding: 10px; margin: 10px; border-radius: 5px;">DEBUG: Teacher Courses Page Loading Successfully</div>';
}

// Teacher dashboard layout wrapper and sidebar
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';
include(__DIR__ . '/includes/sidebar.php');

// Main content area
echo '<div class="teacher-main-content" data-layout="custom">';
echo '<div class="courses-page">';

// Unified Container
echo '<div class="unified-container">';

// Dashboard Hero Section - Matching teacher dashboard
echo '<div class="dashboard-hero">';
echo '<div class="dashboard-hero-header">';
echo '<div class="dashboard-hero-copy">';
echo '<h1>My Courses</h1>';
echo '<p>Manage and view all your courses</p>';
echo '</div>';
echo '<div class="header-filters">';
if ($has_help_videos) {
    echo '<a class="teacher-help-button" id="teacherHelpButton" style="margin-right: 12px; text-decoration: none; display: inline-flex;">';
    echo '<i class="fa fa-question-circle"></i>';
    echo '<span>Need Help?</span>';
    echo '<span class="help-badge-count">' . $help_videos_count . '</span>';
    echo '</a>';
}
echo '<div class="filter-dropdown" data-filter="category">';
echo '<button class="filter-dropdown-toggle" type="button" data-default-label="All Courses">';
echo '<span class="filter-label">All Courses</span>';
echo '<i class="fa fa-chevron-down"></i>';
echo '</button>';
echo '<div class="filter-dropdown-menu category-filters">';
echo '<button class="category-btn active" data-category="all">All Courses</button>';
echo '</div>';
echo '</div>';

echo '<div class="filter-dropdown" data-filter="booktype">';
echo '<button class="filter-dropdown-toggle" type="button" data-default-label="All Book Types">';
echo '<span class="filter-label">All Book Types</span>';
echo '<i class="fa fa-chevron-down"></i>';
echo '</button>';
echo '<div class="filter-dropdown-menu booktype-filters">';
echo '<button class="booktype-btn active" data-booktype="all">All Book Types</button>';
echo '</div>';
echo '</div>';

echo '<div class="view-toggles">';
echo '<button class="view-toggle active" data-view="grid"><i class="fa fa-th"></i></button>';
echo '<button class="view-toggle" data-view="list"><i class="fa fa-list"></i></button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<div class="booktype-summary-row" id="booktypeSummary"></div>';

// Courses Grid
echo '<div class="courses-container">';
echo '<div class="courses-grid" id="coursesGrid">';

// Get courses organized by categories
try {
    // Get all categories
    $categories = $DB->get_records('course_categories', ['visible' => 1], 'sortorder ASC');
    
    // Get courses for current user (where user is teacher)
    $teacher_courses = [];
    $teacher_roles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
    $roleids = array_keys($teacher_roles);
    
    if (!empty($roleids)) {
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['userid'] = $USER->id;
        $params['ctxlevel'] = CONTEXT_COURSE;
        
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.*, cat.name as category_name, cat.id as category_id
             FROM {course} c
             JOIN {context} ctx ON c.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
             JOIN {role_assignments} ra ON ctx.id = ra.contextid AND ra.userid = :userid AND ra.roleid {$insql}
             LEFT JOIN {course_categories} cat ON c.category = cat.id
             WHERE c.visible = 1 AND c.id > 1
             ORDER BY cat.sortorder ASC, c.sortorder ASC",
            $params
        );
        
        // Organize courses by category
        foreach ($courses as $course) {
            $category_id = $course->category_id ?: 0;
            $category_name = $course->category_name ?: 'Uncategorized';
            
            if (!isset($teacher_courses[$category_id])) {
                $teacher_courses[$category_id] = [
                    'name' => $category_name,
                    'courses' => []
                ];
            }
            
            // Get course statistics - Count only students from SAME SCHOOL as teacher
            $enrollment_count = theme_remui_kids_count_course_students_by_school($course->id, $teacher_company_id);
            
            $activity_count = $DB->count_records_sql(
                "SELECT COUNT(*)
                 FROM {course_modules}
                 WHERE course = ? AND visible = 1",
                [$course->id]
            );
            
            // Get course completion percentage (average across students from SAME SCHOOL)
            $completion_percentage = 0;
            if ($enrollment_count > 0) {
                try {
                    if ($teacher_company_id) {
                        // Filter by company
                        $completed_count = $DB->count_records_sql(
                            "SELECT COUNT(DISTINCT cc.userid)
                             FROM {course_completions} cc
                             JOIN {company_users} cu ON cu.userid = cc.userid AND cu.companyid = :companyid
                             WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL",
                            [
                                'courseid' => $course->id,
                                'companyid' => $teacher_company_id
                            ]
                        );
                    } else {
                        // No company filter
                    $completed_count = $DB->count_records_sql(
                        "SELECT COUNT(DISTINCT cc.userid)
                         FROM {course_completions} cc
                         WHERE cc.course = ? AND cc.timecompleted IS NOT NULL",
                        [$course->id]
                    );
                    }
                    
                    if ($completed_count > 0) {
                        $completion_percentage = round(($completed_count / $enrollment_count) * 100);
                    }
                } catch (Exception $compl_ex) {
                    // If completion tracking fails, just set to 0
                    $completion_percentage = 0;
                }
            }
            
            // Get course image
            require_once($CFG->libdir . '/filelib.php');
            $courseimage = '';
            $context = context_course::instance($course->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0);
            foreach ($files as $file) {
                if ($file->is_valid_image()) {
                    $courseimage = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        null,
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out();
                    break;
                }
            }
            
            // Get teacher enrollment date
            $enrollment_date = '';
            $enrol_date_timestamp = $DB->get_field_sql(
                "SELECT MIN(ra.timemodified)
                 FROM {role_assignments} ra
                 JOIN {context} ctx ON ra.contextid = ctx.id
                 WHERE ctx.instanceid = ? AND ctx.contextlevel = ? AND ra.userid = ?",
                [$course->id, CONTEXT_COURSE, $USER->id]
            );
            if ($enrol_date_timestamp) {
                $enrollment_date = date('j M Y', $enrol_date_timestamp);
            }
            
            $teacher_courses[$category_id]['courses'][] = [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'summary' => $course->summary,
                'startdate' => $course->startdate,
                'enrollment_count' => $enrollment_count,
                'activity_count' => $activity_count,
                'completion_percentage' => $completion_percentage,
                'status' => $course->visible ? 'active' : 'draft',
                'courseimage' => $courseimage,
                'enrollment_date' => $enrollment_date
            ];
        }
    }
    
    // Display courses with modern card format
    if (!empty($teacher_courses)) {
        if (!function_exists('theme_remui_kids_slugify')) {
            function theme_remui_kids_slugify(string $text): string {
                $text = strtolower($text);
                $text = preg_replace('/[^a-z0-9]+/', '-', $text);
                return trim($text, '-');
            }
        }

        if (!function_exists('theme_remui_kids_get_booktype_cover_overrides')) {
            function theme_remui_kids_get_booktype_cover_overrides(): array {
                static $overrides = null;
                if ($overrides !== null) {
                    return $overrides;
                }
                global $CFG;
                $jsonpath = $CFG->dirroot . '/theme/remui_kids/CradsImg/booktype_covers.json';
                if (file_exists($jsonpath)) {
                    $decoded = json_decode(file_get_contents($jsonpath), true);
                    if (is_array($decoded)) {
                        $overrides = $decoded;
                        return $overrides;
                    }
                }
                $overrides = [];
                return $overrides;
            }
        }

        $coursecoverdefaults = [
            'student_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_96dybo96dybo96dy.png',
            'student_course' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hcwxdbhcwxdbhcwx.png',
            'teacher_resource' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_7xb0pl7xb0pl7xb0.png',
            'worksheet_pack' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_ciywx0ciywx0ciyw.png',
            'teacher_guide' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_k3ktqnk3ktqnk3kt.png',
            'practice_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hz61skhz61skhz61.png',
            'teacher_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_kmjtndkmjtndkmjt.png'
        ];
        $coursecovercycle = array_values($coursecoverdefaults);
        $fallbackindex = 0;
        $availablebooktypes = [];
        $booktypecounts = [];

        if (!function_exists('theme_remui_kids_course_keyword_match')) {
            function theme_remui_kids_course_keyword_match(string $haystack, array $keywords): bool {
                foreach ($keywords as $keyword) {
                    $keyword = strtolower(trim($keyword));
                    if ($keyword === '') {
                        continue;
                    }
                    if (strpos($haystack, $keyword) !== false) {
                        return true;
                    }
                    if (strlen($keyword) <= 3 && preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $haystack)) {
                        return true;
                    }
                }
                return false;
            }
        }

        if (!function_exists('theme_remui_kids_extract_label_from_fullname')) {
            function theme_remui_kids_extract_label_from_fullname(string $fullname): string {
                $fullname = trim($fullname);
                if ($fullname === '') {
                    return '';
                }
                $parts = preg_split('/\s*(?:-|–|—|:|\||•)\s*/u', $fullname);
                if (!empty($parts) && trim($parts[0]) !== '') {
                    return trim($parts[0]);
                }
                return $fullname;
            }
        }

        if (!function_exists('theme_remui_kids_detect_course_book_type')) {
            function theme_remui_kids_detect_course_book_type(array $course): string {
                $fullname = $course['fullname'] ?? '';
                $shortname = $course['shortname'] ?? '';
                $haystack = strtolower($fullname . ' ' . $shortname);

                $bookTypeKeywords = [
                    // Check Student Course FIRST (before Student Book) to avoid conflicts
                    'Student Course' => ['student course', 'student-course', 'studentcourse', 'sc', 'student courses'],
                    'Practice Book' => ['practice book', 'practice-book', 'practicebook', 'pb'],
                    'Student Book' => ['student book', 'student-book', 'studentbook', 'sb', 'learner book', 'learner\'s book'],
                    'Teacher Resource' => ['teacher resource', 'resource pack', 'resource book', 'tr'],
                    'Teacher Book' => ['teacher book', 'teachers book', 'tb'],
                    'Teacher Guide' => ['teacher guide', 'teachers guide', 'guide book', 'guidebook', 'tg'],
                    'Worksheet Pack' => ['worksheet pack', 'worksheet', 'worksheets', 'activity pack', 'wp'],
                    'Workbook' => ['workbook', 'work book', 'wb'],
                    'Assessment Book' => ['assessment book', 'assessment pack', 'assessment', 'ab']
                ];

                // Check in order - Student Course must be checked before Student Book
                foreach ($bookTypeKeywords as $label => $keywords) {
                    if (theme_remui_kids_course_keyword_match($haystack, $keywords)) {
                        return $label;
                    }
                }

                // Try extracting from fullname first part
                $derivedLabel = theme_remui_kids_extract_label_from_fullname($fullname);
                if ($derivedLabel !== '') {
                    // Check if derived label matches any known type (case-insensitive)
                    $derivedLower = strtolower($derivedLabel);
                    foreach ($bookTypeKeywords as $label => $keywords) {
                        foreach ($keywords as $keyword) {
                            if (strtolower($keyword) === $derivedLower || strpos($derivedLower, strtolower($keyword)) !== false) {
                                return $label;
                            }
                        }
                    }
                    // If it contains "student" and "course", return Student Course
                    if (stripos($derivedLabel, 'student') !== false && stripos($derivedLabel, 'course') !== false) {
                        return 'Student Course';
                    }
                    // If it's a known type, return it
                    if (in_array($derivedLabel, array_keys($bookTypeKeywords))) {
                        return $derivedLabel;
                    }
                }

                if (!empty($shortname)) {
                    // Check shortname against keywords too
                    $shortLower = strtolower($shortname);
                    foreach ($bookTypeKeywords as $label => $keywords) {
                        foreach ($keywords as $keyword) {
                            if (strpos($shortLower, strtolower($keyword)) !== false) {
                                return $label;
                            }
                        }
                    }
                }

                return '';
            }
        }

        if (!function_exists('theme_remui_kids_select_course_cover')) {
            function theme_remui_kids_select_course_cover(array $course, array $defaults, array $cycle, int &$index, ?string &$type = null) {
                static $dynamiccovermap = [];
                global $CFG;
                $overrides = theme_remui_kids_get_booktype_cover_overrides();

                $labelKeyMap = [
                    'Student Book' => 'student_book',
                    'Student Course' => 'student_course',
                    'Teacher Resource' => 'teacher_resource',
                    'Worksheet Pack' => 'worksheet_pack',
                    'Teacher Guide' => 'teacher_guide',
                    'Practice Book' => 'practice_book',
                    'Teacher Book' => 'teacher_book',
                    'Workbook' => 'workbook',
                    'Assessment Book' => 'assessment_book'
                ];

                if (empty($type)) {
                    $type = theme_remui_kids_detect_course_book_type($course);
                }

                $hasType = !empty($type);
                $slug = '';
                if ($hasType && function_exists('theme_remui_kids_slugify')) {
                    $slug = theme_remui_kids_slugify($type);
                }

                if ($hasType) {
                    if (isset($labelKeyMap[$type]) && isset($defaults[$labelKeyMap[$type]])) {
                        return $defaults[$labelKeyMap[$type]];
                    }

                    $customcoverdir = $CFG->dirroot . '/theme/remui_kids/CradsImg';
                    $customcoverurl = $CFG->wwwroot . '/theme/remui_kids/CradsImg';

                    if (!empty($slug)) {
                        if (isset($overrides[$slug])) {
                            $overridefile = $overrides[$slug];
                            if ($overridefile && file_exists($customcoverdir . '/' . $overridefile)) {
                                $cover = $customcoverurl . '/' . $overridefile;
                                $dynamiccovermap[$slug] = $cover;
                                return $cover;
                            }
                        }

                        $generatedCandidates = [
                            'Gemini_Generated_Image_' . $slug . '.png',
                            'booktype-' . $slug . '.png',
                            $slug . '.png'
                        ];
                        foreach ($generatedCandidates as $candidate) {
                            if (file_exists($customcoverdir . '/' . $candidate)) {
                                $cover = $customcoverurl . '/' . $candidate;
                                $dynamiccovermap[$slug] = $cover;
                                return $cover;
                            }
                        }

                        if (isset($dynamiccovermap[$slug])) {
                            return $dynamiccovermap[$slug];
                        }
                    }

                    return '';
                }

                if (empty($cycle)) {
                    $type = 'Student Book';
                    return isset($defaults['student_book']) ? $defaults['student_book'] : '';
                }

                $cycleIndex = $index % count($cycle);
                $cover = $cycle[$cycleIndex];
                $index++;

                if (!empty($slug)) {
                    $dynamiccovermap[$slug] = $cover;
                }

                return $cover;
            }
        }
        $all_categories = [];
        
        // Collect all categories for filter buttons
        foreach ($teacher_courses as $category_id => $category_data) {
            if (!empty($category_data['courses'])) {
                $all_categories[$category_data['name']] = true;
            }
        }
        
        // Add category filter buttons
        foreach (array_keys($all_categories) as $category) {
            echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const categoryFilters = document.querySelector(".category-filters");
                if (categoryFilters) {
                    const btn = document.createElement("button");
                    btn.className = "category-btn";
                    btn.dataset.category = "' . htmlspecialchars($category) . '";
                    btn.textContent = "' . htmlspecialchars($category) . '";
                    categoryFilters.appendChild(btn);
                    
                    console.log("Created category button:", "' . htmlspecialchars($category) . '");
                    
                    // Add event listener to the new button
                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const category = this.dataset.category;
                        console.log("Dynamic category button clicked:", category);
                        filterByCategory(category, this);
                    });
                }
            });
            </script>';
        }
        
        foreach ($teacher_courses as $category_id => $category_data) {
            if (empty($category_data['courses'])) {
                continue;
            }
            
            foreach ($category_data['courses'] as $course) {
                $booktypelabel = theme_remui_kids_detect_course_book_type($course);
                
                // Debug: Log detection for Student Course
                if (debugging() && (stripos($course['fullname'], 'student') !== false || stripos($course['shortname'], 'student') !== false)) {
                    error_log("Course: " . $course['fullname'] . " | Detected Type: " . ($booktypelabel ?: 'NONE'));
                }
                
                $courseimageurl = $course['courseimage'] ?? '';
                $fallbackimage = '';
                if (empty($courseimageurl)) {
                    $originalLabel = $booktypelabel;
                    $fallbackLabel = $booktypelabel;
                    $fallbackimage = theme_remui_kids_select_course_cover($course, $coursecoverdefaults, $coursecovercycle, $fallbackindex, $fallbackLabel);
                    $booktypelabel = $originalLabel ?: $fallbackLabel;
                    
                    // Debug: Log image selection
                    if (debugging() && $booktypelabel === 'Student Course') {
                        error_log("Student Course Image Selected: " . $fallbackimage);
                    }
                }
                $statusclass = ($course['status'] ?? 'active') === 'active' ? 'active' : 'draft';
                $statuslabel = $statusclass === 'active' ? 'Active' : 'Draft';

                if (!empty($booktypelabel)) {
                    $booktypeslug = theme_remui_kids_slugify($booktypelabel);
                    if (!isset($booktypecounts[$booktypeslug])) {
                        $booktypecounts[$booktypeslug] = [
                            'label' => $booktypelabel,
                            'count' => 0
                        ];
                    }
                    $booktypecounts[$booktypeslug]['count']++;
                    $availablebooktypes[$booktypeslug] = $booktypecounts[$booktypeslug];
                } else {
                    $booktypeslug = 'general-course';
                }
                
                echo '<div class="course-card" data-category="' . htmlspecialchars($category_data['name']) . '" data-booktype="' . htmlspecialchars($booktypeslug) . '">';
                echo '<span class="course-status-badge ' . $statusclass . '"><span class="status-dot"></span>' . htmlspecialchars($statuslabel) . '</span>';
                
                // Course Image with Category Badge
                echo '<div class="course-image-wrapper">';
                if (!empty($courseimageurl)) {
                    echo '<img src="' . $courseimageurl . '" alt="' . htmlspecialchars($course['fullname']) . '" class="course-image" loading="lazy">';
                } else {
                    $fallbackalt = 'Course cover for ' . htmlspecialchars($course['fullname']);
                    $fallbackstyle = !empty($fallbackimage)
                        ? 'background-image: url(' . "'" . $fallbackimage . "'" . ');'
                        : 'background: #050505;';
                    $fallbackclass = 'course-image-fallback';
                    if (empty($fallbackimage)) {
                        $fallbackclass .= ' no-image';
                    }
                    echo '<div class="' . $fallbackclass . '" style="' . $fallbackstyle . '" role="img" aria-label="' . $fallbackalt . '"></div>';
                }
                if (!empty($booktypelabel)) {
                    echo '<span class="course-type-label ' . htmlspecialchars($booktypeslug) . '">' . htmlspecialchars($booktypelabel) . '</span>';
                }
                echo '<div class="course-category-badge">' . htmlspecialchars($category_data['name']) . '</div>';
                echo '</div>';
                
                // Course Content
                echo '<div class="course-card-content">';
                echo '<div class="course-header-row">';
                echo '<div class="course-title-block">';
                echo '<h3>' . htmlspecialchars($course['fullname']) . '</h3>';
                echo '<p>' . htmlspecialchars($category_data['name']) . '</p>';
                echo '</div>';
                echo '<div class="course-highlight">';
                echo '<span class="course-highlight-label">Completion</span>';
                echo '<span class="course-highlight-value">' . intval($course['completion_percentage']) . '%</span>';
                echo '</div>';
                echo '</div>';

                $iconstats = [
                    ['icon' => 'fa-file-alt', 'label' => 'Activities', 'value' => $course['activity_count']],
                    ['icon' => 'fa-users', 'label' => 'Learners', 'value' => $course['enrollment_count']],
                    ['icon' => 'fa-calendar-alt', 'label' => 'Assigned', 'value' => $course['enrollment_date'] ?: 'N/A'],
                    ['icon' => 'fa-book', 'label' => 'Book Type', 'value' => $booktypelabel ?: 'Course']
                ];

                echo '<div class="course-icon-row">';
                foreach ($iconstats as $stat) {
                    echo '<div class="course-icon-stat">';
                    echo '<div class="stat-icon"><i class="fa ' . $stat['icon'] . '"></i></div>';
                    echo '<div class="stat-text">';
                    echo '<span class="label">' . htmlspecialchars($stat['label']) . '</span>';
                    echo '<span class="value">' . htmlspecialchars((string)$stat['value']) . '</span>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';

                echo '<div class="course-bottom-stats">';
                echo '<div class="stat-pill">' . htmlspecialchars($statuslabel) . '</div>';
                $updatedLabel = !empty($course['startdate']) ? date('d M Y', $course['startdate']) : 'No start date';
                echo '<div class="stat-muted">Updated ' . htmlspecialchars($updatedLabel) . '</div>';
                echo '</div>';
                
                // Course Actions
                echo '<div class="course-actions">';
                echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/course_preview.php?id=' . $course['id'] . '" class="btn-preview">';
                echo '<i class="fa fa-eye"></i> Preview';
                echo '</a>';
                echo '<a href="' . $CFG->wwwroot . '/course/view.php?id=' . $course['id'] . '" class="btn-view">';
                echo '<i class="fa fa-arrow-right"></i> View Course';
                echo '</a>';
                echo '</div>';
                
                echo '</div>'; // course-card-content
                echo '</div>'; // course-card
            }
        }
        if (!empty($availablebooktypes)) {
            echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const bookTypeFilters = document.querySelector(".booktype-filters");
                const bookTypes = ' . json_encode($availablebooktypes) . ';
                if (bookTypeFilters && bookTypes) {
                    Object.keys(bookTypes).forEach(function(slug) {
                        const typeData = bookTypes[slug] || {};
                        const label = typeData.label || slug;
                        const count = typeData.count || 0;
                        const btn = document.createElement("button");
                        btn.className = "booktype-btn";
                        btn.dataset.booktype = slug;
                        btn.innerHTML = "<span>" + label + "</span>" + (count ? "<small>" + count + " course" + (count !== 1 ? "s" : "") + "</small>" : "");
                        bookTypeFilters.appendChild(btn);
                    });
                }

                const summaryContainer = document.getElementById("booktypeSummary");
                if (summaryContainer && bookTypes) {
                    summaryContainer.innerHTML = "";
                    Object.keys(bookTypes).forEach(function(slug) {
                        const typeData = bookTypes[slug] || {};
                        const label = typeData.label || slug;
                        const count = typeData.count || 0;
                        const card = document.createElement("div");
                        card.className = "booktype-summary-card";
                        card.innerHTML = "<span>" + label + "</span><strong>" + count + " course" + (count !== 1 ? "s" : "") + "</strong>";
                        summaryContainer.appendChild(card);
                    });
                }
            });
            </script>';
        }
    } else {
        // Empty state
        echo '<div class="empty-state">';
        echo '<div class="empty-icon"><i class="fas fa-book-open"></i></div>';
        echo '<h3 class="empty-title">No courses found</h3>';
        echo '<p class="empty-text">You are not assigned as a teacher in any courses yet.</p>';
        echo '<a href="' . $CFG->wwwroot . '/course/edit.php" class="btn-create">Create Your First Course</a>';
        echo '</div>';
    }
    
} catch (Exception $e) {
    // Error handling
    echo '<div class="empty-state">';
    echo '<div class="empty-icon"><i class="fas fa-exclamation-triangle"></i></div>';
    echo '<h3 class="empty-title">Error loading courses</h3>';
    echo '<p class="empty-text">There was an error loading your courses. Please try again later.</p>';
    if (debugging()) {
        echo '<div style="background: #fee2e2; color: #991b1b; padding: 15px; margin: 20px; border-radius: 8px; text-align: left;">';
        echo '<strong>Debug Information:</strong><br>';
        echo 'Error: ' . htmlspecialchars($e->getMessage()) . '<br>';
        echo 'File: ' . htmlspecialchars($e->getFile()) . '<br>';
        echo 'Line: ' . $e->getLine() . '<br>';
        echo '<pre style="overflow-x: auto; margin-top: 10px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</div>';
    }
    echo '</div>';
    
    error_log("Teacher Courses Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}

echo '</div>'; // End courses-grid
echo '</div>'; // End courses-container
echo '</div>'; // End unified-container
echo '</div>'; // End courses-page
echo '</div>'; // End teacher-main-content
echo '</div>'; // End teacher-dashboard-wrapper
echo '</div>'; // End teacher-css-wrapper

// Teacher Help Video Modal
if ($has_help_videos) {
    echo '<div id="teacherHelpVideoModal" class="teacher-help-video-modal">';
    echo '<div class="teacher-help-modal-content">';
    echo '<div class="teacher-help-modal-header">';
    echo '<h2><i class="fa fa-video"></i> Teacher Help Videos</h2>';
    echo '<button class="teacher-help-modal-close" id="closeTeacherHelpModal">&times;</button>';
    echo '</div>';
    echo '<div class="teacher-help-modal-body">';
    echo '<div class="teacher-help-videos-list" id="teacherHelpVideosList">';
    echo '<p style="text-align: center; padding: 20px; color: #666;">';
    echo '<i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>';
    echo 'Loading help videos...';
    echo '</p>';
    echo '</div>';
    echo '<div class="teacher-help-video-player" id="teacherHelpVideoPlayerContainer" style="display: none;">';
    echo '<button class="back-to-list-btn" id="teacherBackToListBtn">';
    echo '<i class="fa fa-arrow-left"></i> Back to List';
    echo '</button>';
    echo '<video id="teacherHelpVideoPlayer" controls style="width: 100%; border-radius: 8px;">';
    echo '<source src="" type="video/mp4">';
    echo 'Your browser does not support the video tag.';
    echo '</video>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Sidebar JS
echo '<script>
function toggleTeacherSidebar() {
  const sidebar = document.querySelector(".teacher-sidebar");
  if (sidebar) {
    sidebar.classList.toggle("sidebar-open");
  }
}
document.addEventListener("click", function(event) {
  const sidebar = document.querySelector(".teacher-sidebar");
  const toggleButton = document.querySelector(".sidebar-toggle");
  if (!sidebar || !toggleButton) return;
  if (window.innerWidth <= 768 && !sidebar.contains(event.target) && !toggleButton.contains(event.target)) {
    sidebar.classList.remove("sidebar-open");
  }
});
window.addEventListener("resize", function() {
  const sidebar = document.querySelector(".teacher-sidebar");
  if (!sidebar) return;
  if (window.innerWidth > 768) {
    sidebar.classList.remove("sidebar-open");
  }
});
</script>';

// JavaScript for interactivity
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    console.log("DOM loaded, initializing teacher courses functionality");
    
    let activeCategory = "all";
    let activeBookType = "all";
    const filterDropdowns = [];

    function updateFilterLabel(type, text) {
        const dropdown = document.querySelector(\'.filter-dropdown[data-filter="\' + type + \'"]\');
        if (!dropdown) {
            return;
        }
        const labelEl = dropdown.querySelector(".filter-label");
        const toggle = dropdown.querySelector(".filter-dropdown-toggle");
        const defaultLabel = toggle ? (toggle.dataset.defaultLabel || "") : "";
        if (labelEl) {
            labelEl.textContent = text && text.trim() ? text.trim() : defaultLabel;
        }
    }

    function closeAllDropdowns(except) {
        filterDropdowns.forEach(dd => {
            if (dd !== except) {
                dd.classList.remove("open");
            }
        });
    }

    function applyCourseFilters() {
        const courseCards = document.querySelectorAll(".course-card");
        courseCards.forEach(card => {
            const cardCategory = card.dataset.category || "";
            const cardBookType = card.dataset.booktype || "";
            const categoryMatch = activeCategory === "all" || cardCategory === activeCategory;
            const bookTypeMatch = activeBookType === "all" || cardBookType === activeBookType;
            card.style.display = categoryMatch && bookTypeMatch ? "block" : "none";
        });
    }

    // Define functions inside DOMContentLoaded to ensure DOM is ready
    function filterByCategory(category, element) {
        console.log("Filtering by category:", category);
        activeCategory = category;
        
        // Update active button
        document.querySelectorAll(".category-btn").forEach(btn => {
            btn.classList.remove("active");
        });
        if (element) {
            element.classList.add("active");
        }
        const labelText = category === "all" ? "" : (element ? element.textContent.trim() : category);
        updateFilterLabel("category", labelText);
        closeAllDropdowns();
        applyCourseFilters();
    }

    function filterByBookType(booktype, element) {
        console.log("Filtering by book type:", booktype);
        activeBookType = booktype;
        document.querySelectorAll(".booktype-btn").forEach(btn => btn.classList.remove("active"));
        if (element) {
            element.classList.add("active");
        }
        const labelText = booktype === "all" ? "" : (element ? element.textContent.trim() : booktype);
        updateFilterLabel("booktype", labelText);
        closeAllDropdowns();
        applyCourseFilters();
    }

    function setView(view, element) {
        console.log("Setting view to:", view);
        console.log("Element:", element);
        
        // Update active button
        document.querySelectorAll(".view-toggle").forEach(btn => {
            btn.classList.remove("active");
            console.log("Removed active from button:", btn);
        });
        if (element) {
            element.classList.add("active");
            console.log("Added active to button:", element);
        }
        
        // Change grid layout
        const grid = document.getElementById("coursesGrid");
        console.log("Found grid element:", grid);
        
        if (grid) {
            if (view === "list") {
                grid.style.gridTemplateColumns = "1fr";
                grid.classList.add("list-view");
                grid.classList.remove("grid-view");
                console.log("Set to list view");
            } else {
                grid.style.gridTemplateColumns = "repeat(auto-fill, minmax(350px, 1fr))";
                grid.classList.add("grid-view");
                grid.classList.remove("list-view");
                console.log("Set to grid view");
            }
        } else {
            console.error("Grid element not found!");
        }
    }
    
    // Make functions globally available
    window.filterByCategory = filterByCategory;
    window.filterByBookType = filterByBookType;
    window.setView = setView;
    
    document.querySelectorAll(".filter-dropdown").forEach(dropdown => {
        filterDropdowns.push(dropdown);
        const toggle = dropdown.querySelector(".filter-dropdown-toggle");
        if (toggle) {
            toggle.addEventListener("click", function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = dropdown.classList.contains("open");
                closeAllDropdowns(isOpen ? null : dropdown);
                if (!isOpen) {
                    dropdown.classList.add("open");
                }
            });
        }
    });

    // Add click event listeners to category buttons
    const categoryButtons = document.querySelectorAll(".category-btn");
    console.log("Found category buttons:", categoryButtons.length);
    
    categoryButtons.forEach((btn, index) => {
        console.log("Adding event listener to category button:", index, btn);
        btn.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            const category = this.dataset.category || this.textContent.trim();
            console.log("Category button clicked:", category);
            console.log("Button element:", this);
            filterByCategory(category, this);
        });
        
        // Test if button is clickable
        console.log("Category button clickable test:", btn.offsetWidth > 0 && btn.offsetHeight > 0);
    });
    
    const bookTypeButtons = document.querySelectorAll(".booktype-btn");
    bookTypeButtons.forEach((btn, index) => {
        btn.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            const booktype = this.dataset.booktype || this.textContent.trim();
            filterByBookType(booktype, this);
        });
    });

    // Add click event listeners to view toggle buttons
    const viewToggleButtons = document.querySelectorAll(".view-toggle");
    console.log("Found view toggle buttons:", viewToggleButtons.length);
    
    viewToggleButtons.forEach((btn, index) => {
        console.log("Adding event listener to view toggle button:", index, btn);
        btn.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const view = this.dataset.view || (this.querySelector("i").classList.contains("fa-th") ? "grid" : "list");
            console.log("View toggle clicked:", view);
            console.log("Button element:", this);
            console.log("Button dataset:", this.dataset);
            
            setView(view, this);
        });
        
        // Test if button is clickable
        console.log("Button clickable test:", btn.offsetWidth > 0 && btn.offsetHeight > 0);
    });
    
    // New Course button removed as requested
    
    // Fallback: Use event delegation for all buttons
    document.addEventListener("click", function(e) {
        if (!e.target.closest(".filter-dropdown")) {
            closeAllDropdowns();
        }
        // Handle category buttons
        if (e.target.closest(".category-btn")) {
            const btn = e.target.closest(".category-btn");
            e.preventDefault();
            e.stopPropagation();
            
            const category = btn.dataset.category || btn.textContent.trim();
            console.log("Category button clicked via delegation:", category);
            console.log("Button element:", btn);
            
            filterByCategory(category, btn);
        }

        if (e.target.closest(".booktype-btn")) {
            const btn = e.target.closest(".booktype-btn");
            e.preventDefault();
            e.stopPropagation();
            const booktype = btn.dataset.booktype || btn.textContent.trim();
            filterByBookType(booktype, btn);
        }
        
        // Handle view toggle buttons
        if (e.target.closest(".view-toggle")) {
            const btn = e.target.closest(".view-toggle");
            e.preventDefault();
            e.stopPropagation();
            
            const view = btn.dataset.view || (btn.querySelector("i").classList.contains("fa-th") ? "grid" : "list");
            console.log("View toggle clicked via delegation:", view);
            console.log("Button element:", btn);
            
            setView(view, btn);
        }
    });
    
    console.log("All event listeners initialized successfully");
});
</script>';
if ($has_help_videos): 
    echo '<script>
// ===== TEACHER SUPPORT/HELP BUTTON FUNCTIONALITY =====
document.addEventListener(\'DOMContentLoaded\', function() {
    const helpButton = document.getElementById(\'teacherHelpButton\');';
?>
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const closeModal = document.getElementById('closeTeacherHelpModal');
    
    if (helpButton) {
        helpButton.addEventListener('click', function() {
            if (helpModal) {
                helpModal.classList.add('active');
                document.body.style.overflow = 'hidden';
                loadTeacherHelpVideos();
            }
        });
    }
    
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            closeTeacherHelpModal();
        });
    }
    
    if (helpModal) {
        helpModal.addEventListener('click', function(e) {
            if (e.target === helpModal) {
                closeTeacherHelpModal();
            }
        });
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && helpModal && helpModal.classList.contains('active')) {
            closeTeacherHelpModal();
        }
    });
    
    const backToListBtn = document.getElementById('teacherBackToListBtn');
    if (backToListBtn) {
        backToListBtn.addEventListener('click', function() {
            const videosListContainer = document.getElementById('teacherHelpVideosList');
            const videoPlayerContainer = document.getElementById('teacherHelpVideoPlayerContainer');
            const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
            
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.currentTime = 0;
                videoPlayer.src = '';
            }
            
            const existingIframe = document.getElementById('teacherTempIframe');
            if (existingIframe) {
                existingIframe.remove();
            }
            
            if (videoPlayerContainer) {
                videoPlayerContainer.style.display = 'none';
            }
            
            if (videosListContainer) {
                videosListContainer.style.display = 'block';
            }
        });
    }
});

function closeTeacherHelpModal() {
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (helpModal) {
        helpModal.classList.remove('active');
    }
    
    if (videoPlayer) {
        videoPlayer.pause();
        videoPlayer.currentTime = 0;
        videoPlayer.src = '';
    }
    
    const existingIframe = document.getElementById('teacherTempIframe');
    if (existingIframe) {
        existingIframe.remove();
    }
    
    document.body.style.overflow = 'auto';
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function loadTeacherHelpVideos() {
    const videosListContainer = document.getElementById('teacherHelpVideosList');
    if (!videosListContainer) return;
    
    videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;"><i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>Loading help videos...</p>';
    
    const wwwroot = typeof M !== 'undefined' && M.cfg ? M.cfg.wwwroot : window.location.origin;
    fetch(wwwroot + '/local/support/get_videos.php?category=teachers')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.videos && data.videos.length > 0) {
                let html = '';
                data.videos.forEach(function(video) {
                    html += '<div class="teacher-help-video-item" ';
                    html += 'data-video-id="' + video.id + '" ';
                    html += 'data-video-url="' + escapeHtml(video.video_url) + '" ';
                    html += 'data-embed-url="' + escapeHtml(video.embed_url) + '" ';
                    html += 'data-video-type="' + video.videotype + '" ';
                    html += 'data-has-captions="' + video.has_captions + '" ';
                    html += 'data-caption-url="' + escapeHtml(video.caption_url) + '">';
                    html += '  <h4><i class="fa fa-play-circle"></i> ' + escapeHtml(video.title) + '</h4>';
                    if (video.description) {
                        html += '  <p>' + escapeHtml(video.description) + '</p>';
                    }
                    html += '</div>';
                });
                videosListContainer.innerHTML = html;
                
                document.querySelectorAll('.teacher-help-video-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        const videoId = this.getAttribute('data-video-id');
                        const videoUrl = this.getAttribute('data-video-url');
                        const embedUrl = this.getAttribute('data-embed-url');
                        const videoType = this.getAttribute('data-video-type');
                        const hasCaptions = this.getAttribute('data-has-captions') === 'true';
                        const captionUrl = this.getAttribute('data-caption-url');
                        playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl);
                    });
                });
            } else {
                videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">No help videos available for teachers.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading help videos:', error);
            videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #d9534f;">Error loading videos. Please try again.</p>';
        });
}

function playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl) {
    const videosListContainer = document.getElementById('teacherHelpVideosList');
    const videoPlayerContainer = document.getElementById('teacherHelpVideoPlayerContainer');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (!videoPlayerContainer || !videoPlayer) return;
    
    videoPlayer.innerHTML = '';
    videoPlayer.src = '';
    
    const existingIframe = document.getElementById('teacherTempIframe');
    if (existingIframe) {
        existingIframe.remove();
    }
    
    if (videoType === 'youtube' || videoType === 'vimeo' || videoType === 'external') {
        videoPlayer.style.display = 'none';
        const iframe = document.createElement('iframe');
        iframe.src = embedUrl || videoUrl;
        iframe.width = '100%';
        iframe.style.height = '450px';
        iframe.style.borderRadius = '8px';
        iframe.frameBorder = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.id = 'teacherTempIframe';
        videoPlayer.parentNode.insertBefore(iframe, videoPlayer);
    } else {
        videoPlayer.style.display = 'block';
        videoPlayer.src = videoUrl;
        
        if (hasCaptions && captionUrl) {
            const track = document.createElement('track');
            track.kind = 'captions';
            track.src = captionUrl;
            track.srclang = 'en';
            track.label = 'English';
            track.default = true;
            videoPlayer.appendChild(track);
        }
        
        videoPlayer.load();
    }
    
    if (videosListContainer) {
        videosListContainer.style.display = 'none';
    }
    videoPlayerContainer.style.display = 'block';
    
    const wwwroot = typeof M !== 'undefined' && M.cfg ? M.cfg.wwwroot : window.location.origin;
    const sesskey = typeof M !== 'undefined' && M.cfg ? M.cfg.sesskey : '';
    fetch(wwwroot + '/local/support/record_view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'videoid=' + videoId + '&sesskey=' + sesskey
    });
}
<?php 
    echo '</script>';
endif;

echo $OUTPUT->footer();