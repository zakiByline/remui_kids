
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
 * Course Preview Page - Custom view for teachers to preview course content
 *
 * @package   theme_remui_kids
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/modinfolib.php');

// Get course ID
$courseid = required_param('id', PARAM_INT);

// Require login
require_login();

// Get course
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Check if user has teacher capabilities
$isteacher = false;
if (is_siteadmin()) {
    $isteacher = true;
} else {
    $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
    $roleids = array_keys($teacherroles);
    
    if (!empty($roleids)) {
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['userid'] = $USER->id;
        $params['courseid'] = $courseid;
        $params['ctxlevel'] = CONTEXT_COURSE;
        
        $has_role = $DB->record_exists_sql(
            "SELECT 1
             FROM {role_assignments} ra
             JOIN {context} ctx ON ra.contextid = ctx.id
             WHERE ra.userid = :userid AND ctx.instanceid = :courseid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}",
            $params
        );
        
        if ($has_role) {
            $isteacher = true;
        }
    }
}

if (!$isteacher) {
    throw new moodle_exception('nopermissions', 'error', '', 'You must be a teacher to preview this course');
}

// Set up the page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/course_preview.php', ['id' => $courseid]);
$PAGE->set_pagelayout('base');
$PAGE->set_title('Preview: ' . format_string($course->fullname));
$PAGE->set_heading('');
$PAGE->add_body_class('course-preview-page');

// Add Font Awesome
$PAGE->requires->js_init_code('
    var link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css";
    document.head.appendChild(link);
');

echo $OUTPUT->header();

// Get course image
$courseimage = '';
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

// Get course modinfo
$modinfo = get_fast_modinfo($course);

// Get course statistics
$enrollment_count = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ue.userid)
     FROM {enrol} e
     JOIN {user_enrolments} ue ON e.id = ue.enrolid
     WHERE e.courseid = ?",
    [$courseid]
);

// Count all activities (excluding subsections themselves)
$activity_count = $DB->count_records_sql("
    SELECT COUNT(*)
    FROM {course_modules} cm
    JOIN {modules} m ON m.id = cm.module
    WHERE cm.course = ? 
    AND cm.deletioninprogress = 0
    AND m.name != 'subsection'
", [$courseid]);

// Get category
$category = $DB->get_record('course_categories', ['id' => $course->category]);

// CSS Styles
echo '<style>
/* Neutralize Moodle defaults - Force full width always */
#page,
#page-wrapper,
#page-content,
#region-main-box,
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

#page.drawers.drag-container {
    background: transparent !important;
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Force full width in split mode */
body.split-mode-active,
body.split-mode-active #page,
body.split-mode-active #page-wrapper,
body.split-mode-active #region-main-box,
body.split-mode-active #region-main,
body.split-mode-active [role="main"],
body.split-mode-active .container,
body.split-mode-active .container-fluid {
    max-width: 100% !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

.container,
.container-fluid {
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Hide main header/navbar when in split mode */
body.split-mode-active #page-header,
body.split-mode-active header,
body.split-mode-active .navbar,
body.split-mode-active nav.navbar,
body.split-mode-active .primary-navigation,
body.split-mode-active .navbar-nav,
body.split-mode-active .usernavigation,
body.split-mode-active .header-content,
body.split-mode-active .header-main,
body.split-mode-active .page-header,
body.split-mode-active #header,
body.split-mode-active header[role="banner"] {
    display: none !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
}

div.d-flex.flex-wrap {
    display: none !important;
}

/* Hide course-specific navigation only */
.secondary-navigation,
.course-content-header,
.tertiary-navigation,
nav[aria-label="Navigation bar"] {
    display: none !important;
}

/* Hide breadcrumbs */
.breadcrumb-nav,
nav[aria-label="breadcrumb"],
ol.breadcrumb {
    display: none !important;
}

/* Force full width on body and html */
html,
body {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden !important;
}

/* Override any wrapper constraints */
body.course-preview-page #page,
body.course-preview-page #page-wrapper,
body.course-preview-page #page-content,
body.course-preview-page .wrapper,
body.course-preview-page .main-content,
body.course-preview-page .content-wrapper {
    max-width: 100% !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Preview Page Styles */
.preview-page {
    min-height: 100vh;
    background: #f8fafc;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    padding: 0;
    margin: 0;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}

body.split-mode-active .preview-page {
    padding: 0 !important;
    margin: 0 !important;
}

.preview-container {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 40px 20px;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

/* Split Screen Mode */
.preview-page.split-mode {
    display: flex !important;
    gap: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

.preview-page.split-mode .preview-container {
    width: 30% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 20px 16px !important;
    overflow-y: auto !important;
    height: 100vh !important;
    position: relative !important;
    flex-shrink: 0 !important;
}

/* Remove any centering in split mode */
.preview-page.split-mode .back-button,
.preview-page.split-mode .preview-header,
.preview-page.split-mode .content-grid,
.preview-page.split-mode .action-buttons {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.preview-page.split-mode .preview-header {
    border-radius: 8px;
}

.preview-page.split-mode .section-card {
    border-radius: 8px;
}

/* Compact spacing in split mode */
.preview-page.split-mode .preview-header {
    margin-bottom: 16px;
}

.preview-page.split-mode .header-banner {
    padding: 20px;
}

.preview-page.split-mode .course-banner-image,
.preview-page.split-mode .course-banner-placeholder {
    height: 120px;
    margin-bottom: 12px;
}

.preview-page.split-mode .course-title {
    font-size: 20px;
}

.preview-page.split-mode .course-summary {
    font-size: 13px;
}

.preview-page.split-mode .header-stats-section {
    padding: 16px 20px;
}

.preview-page.split-mode .stats-bar {
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.preview-page.split-mode .stat-value {
    font-size: 16px;
}

.preview-page.split-mode .section-card {
    margin-bottom: 12px;
}

.preview-page.split-mode .section-header {
    padding: 16px 20px;
}

.preview-page.split-mode .back-button {
    margin-bottom: 16px;
}

.preview-page.split-mode .content-grid {
    gap: 12px;
}

.code-editor-panel {
    width: 70%;
    height: calc(100vh - 80px);
    position: fixed;
    right: 0;
    top: 70px;
    background: white;
    box-shadow: -4px 0 12px rgba(0, 0, 0, 0.15);
    z-index: 998;
    display: none;
    flex-direction: column;
    border-radius: 12px 0 0 0;
    overflow: hidden;
}

.code-editor-panel.active {
    display: flex;
}

/* Adjust panel position when header is hidden in split mode */
body.split-mode-active .code-editor-panel {
    top: 0 !important;
    height: 100vh !important;
    border-radius: 0 !important;
}

/* Activity wrapper for activity + description */
.activity-wrapper {
    display: flex;
    flex-direction: column;
}

/* Activity description section - appears below activity in left column */
.activity-description-section {
    display: none;
    background: #f0f9ff;
    border-left: 3px solid #3b82f6;
    border-bottom: 1px solid #f3f4f6;
    animation: slideDown 0.3s ease-out;
}

.activity-description-section.active {
    display: block;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 1000px;
    }
}

.activity-description-content {
    padding: 16px 20px;
}

/* Metadata Overview Section */
.activity-metadata-section {
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 2px solid #bfdbfe;
}

.activity-metadata-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
}

.activity-metadata-header i {
    color: #059669;
    font-size: 14px;
}

.activity-metadata-header h4 {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    color: #047857;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.activity-metadata-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}

.metadata-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e0f2fe;
    transition: all 0.2s ease;
}

.metadata-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.1);
}

.metadata-item-full {
    grid-column: 1 / -1;
}

.metadata-icon {
    width: 32px;
    height: 32px;
    min-width: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 6px;
    font-size: 14px;
}

.metadata-icon.overdue {
    background: #fee2e2;
    color: #dc2626;
}

.metadata-details {
    flex: 1;
    min-width: 0;
}

.metadata-label {
    font-size: 10px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}

.metadata-value {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    line-height: 1.3;
}

.metadata-value.overdue {
    color: #dc2626;
}

.overdue-badge {
    display: inline-block;
    padding: 2px 6px;
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-left: 4px;
}

.activity-description-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #bfdbfe;
}

.activity-description-header i {
    color: #3b82f6;
    font-size: 14px;
}

.activity-description-header h4 {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.activity-description-body {
    font-size: 13px;
    line-height: 1.6;
    color: #1f2937;
    max-height: 400px;
    overflow-y: auto;
}

.activity-description-body p {
    margin: 0 0 10px 0;
}

.activity-description-body p:last-child {
    margin-bottom: 0;
}

.activity-description-body h1,
.activity-description-body h2,
.activity-description-body h3,
.activity-description-body h4 {
    color: #1e40af;
    margin: 12px 0 8px 0;
    font-weight: 600;
}

.activity-description-body h1 { font-size: 18px; }
.activity-description-body h2 { font-size: 16px; }
.activity-description-body h3 { font-size: 14px; }
.activity-description-body h4 { font-size: 13px; }

.activity-description-body ul,
.activity-description-body ol {
    margin: 0 0 10px 0;
    padding-left: 24px;
}

.activity-description-body li {
    margin-bottom: 4px;
}

.activity-description-body pre {
    background: #1f2937;
    color: #f9fafb;
    padding: 10px 14px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 10px 0;
    font-size: 12px;
    line-height: 1.5;
    font-family: "Courier New", Consolas, monospace;
}

.activity-description-body code {
    background: #e0f2fe;
    color: #1e40af;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-family: "Courier New", Consolas, monospace;
}

.activity-description-body pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}

.activity-description-body a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
}

.activity-description-body a:hover {
    text-decoration: underline;
}

.activity-description-body img {
    max-width: 100%;
    height: auto;
    border-radius: 6px;
    margin: 10px 0;
}

.activity-description-body strong {
    color: #1e40af;
    font-weight: 600;
}

.code-editor-header {
    padding: 14px 24px;
    background: white;
    color: #111827;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid #e5e7eb;
    flex-shrink: 0;
    min-height: 56px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.code-editor-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #111827;
}

.code-editor-title i {
    color: #059669;
    font-size: 16px;
}

.btn-close-editor {
    background: #ef4444;
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer !important;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
}

.btn-close-editor:hover {
    background: #dc2626;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
}

.btn-close-editor i {
    font-size: 14px;
}

.code-editor-iframe {
    flex: 1;
    border: none;
    width: 100%;
    height: calc(100% - 56px);
    background: white;
    overflow: auto;
}

/* Back Button */
.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: white;
    color: #374151;
    text-decoration: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
    margin-bottom: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.back-button:hover {
    background: #f9fafb;
    color: #1f2937;
    text-decoration: none;
    transform: translateX(-4px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
}

/* Course Header */
.preview-header {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    margin-bottom: 24px;
    width: 100%;
    max-width: 100%;
}

.header-banner {
    position: relative;
    padding: 32px 40px;
    background: white;
    border-bottom: 1px solid #f3f4f6;
}

.header-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.preview-badge {
    padding: 6px 14px;
    background: #f0f9ff;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #0369a1;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #e0f2fe;
}

.preview-badge i {
    color: #0ea5e9;
}

.course-actions-header {
    display: flex;
    gap: 12px;
}

.btn-manage-course {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #1f2937;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 1px solid #111827;
}

.btn-manage-course:hover {
    background: #111827;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.course-banner-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 20px;
}

.course-banner-placeholder {
    width: 100%;
    height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 60px;
    color: #d1d5db;
    background: #fafbfc;
    border-radius: 8px;
    border: 2px dashed #e5e7eb;
    margin-bottom: 20px;
}

.course-category {
    display: inline-block;
    padding: 6px 14px;
    background: #f0f9ff;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #0369a1;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.course-title {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.course-summary {
    font-size: 14px;
    color: #6b7280;
    line-height: 1.6;
    margin: 0;
}

/* Stats Section */
.header-stats-section {
    padding: 24px 40px;
    background: #fafbfc;
    border-top: 1px solid #f3f4f6;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 24px;
}

.stat-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.stat-label {
    font-size: 11px;
    color: #9ca3af;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

/* Content Sections */
.content-grid {
    display: grid;
    gap: 16px;
    width: 100%;
    max-width: 100%;
}

.section-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    transition: all 0.2s ease;
    width: 100%;
    max-width: 100%;
}

.section-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: #d1d5db;
}

.section-header {
    padding: 20px 24px !important;
    background:rgb(231, 231, 231) !important;
    border-bottom: 1px solid #f3f4f6 !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    width: 100% !important;
}

.section-title-wrapper {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.section-title {
    font-size: 18px !important;
    font-weight: 600 !important;
    margin: 0 !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    color: #111827 !important;
}

.section-title i {
    color: #3b82f6 !important;
    font-size: 16px !important;
}

.section-badge {
    padding: 4px 12px !important;
    background: #eff6ff !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    color: #3b82f6 !important;
    flex-shrink: 0 !important;
}

.section-right-controls {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 12px !important;
    flex-shrink: 0 !important;
    margin-left: auto !important;
}

.section-content {
    padding: 0;
}

.section-content.collapsed {
    display: none;
}

.activities-list {
    display: flex;
    flex-direction: column;
}

/* Collapsible Toggle */
.collapse-toggle {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    margin: 0;
    color: #6b7280;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.collapse-toggle:hover {
    color: #3b82f6;
    background: #f3f4f6;
    border-radius: 6px;
}

.collapse-toggle.collapsed i {
    transform: rotate(-90deg);
}

.section-header {
    cursor: pointer;
    user-select: none;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}

.section-header:hover .section-title {
    color: #3b82f6;
}

/* Subsections */
.subsection-wrapper {
    border-left: 2px solid #e5e7eb;
    margin-left: 24px;
}

.subsection-item {
    background:rgb(231, 229, 229);
    border-bottom: 1px solid #f3f4f6;
}

.subsection-item:last-child {
    border-bottom: none;
}

.subsection-header {
    padding: 14px 20px 14px 14px !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    cursor: pointer !important;
    user-select: none !important;
    transition: all 0.2s ease !important;
    outline: none !important;
    border-bottom: 2px solid rgb(82, 129, 168) !important;
}

.subsection-header:hover {
    background: #f3f4f6 !important;
    outline: none !important;
}

.subsection-left {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.subsection-right {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 12px !important;
    flex-shrink: 0 !important;
}

/* Remove drag cursor and fix all cursors */
.preview-page * {
    cursor: default !important;
}

.preview-page .section-header,
.preview-page .subsection-header,
.preview-page .collapse-toggle,
.preview-page .back-button,
.preview-page .btn-action,
.preview-page .btn-manage-course,
.preview-page a {
    cursor: pointer !important;
}

.preview-page .activity-item {
    border-bottom: 1px solid #f3f4f6 !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
}

.preview-page .activity-item:hover {
    border-bottom: 1px solid #f3f4f6 !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
    outline: none !important;
}

.preview-page *:focus,
.preview-page *:active,
.preview-page *:focus-visible {
    outline: none !important;
    box-shadow: none !important;
}

/* Override any Moodle drag-drop styles */
.preview-page .activity-item,
.preview-page .subsection-item {
    -webkit-user-drag: none !important;
    -moz-user-drag: none !important;
    user-drag: none !important;
}

.subsection-icon {
    width: 32px;
    height: 32px;
    min-width: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 8px;
    font-size: 14px;
    color: #6b7280;
    border: 1px solid #e5e7eb;
}

.subsection-info {
    flex: 1;
    min-width: 0;
}

.subsection-title {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin: 0 0 2px 0;
}

.subsection-meta {
    font-size: 11px;
    color: #9ca3af;
}

.subsection-activities {
    padding-left: 36px;
    background: white;
}

.subsection-activities.collapsed {
    display: none;
}

.activity-item {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 14px !important;
    padding: 16px 24px !important;
    background: white !important;
    border-bottom: 1px solid #f3f4f6 !important;
    transition: all 0.2s ease !important;
    cursor: default !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
    flex-wrap: nowrap !important;
}

.activity-item:last-child {
    border-bottom: none !important;
}

.activity-item:hover {
    background: #fafbfc !important;
    border-bottom: 1px solid #f3f4f6 !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
}

.activity-item:last-child:hover {
    border-bottom: none !important;
}

.activity-icon {
    width: 40px;
    height: 40px;
    min-width: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f9fafb;
    border-radius: 10px;
    font-size: 18px;
    border: 1px solid #f3f4f6;
}

.activity-icon.assignment { 
    color: #ef4444; 
    background: #fef2f2;
    border-color: #fee2e2;
}
.activity-icon.quiz { 
    color: #8b5cf6; 
    background: #f5f3ff;
    border-color: #ede9fe;
}
.activity-icon.forum { 
    color: #3b82f6; 
    background: #eff6ff;
    border-color: #dbeafe;
}
.activity-icon.resource { 
    color: #10b981; 
    background: #f0fdf4;
    border-color: #dcfce7;
}
.activity-icon.page { 
    color: #f59e0b; 
    background: #fffbeb;
    border-color: #fef3c7;
}
.activity-icon.url { 
    color: #06b6d4; 
    background: #f0fdfa;
    border-color: #ccfbf1;
}
.activity-icon.label { 
    color: #6b7280; 
    background: #f9fafb;
    border-color: #f3f4f6;
}
.activity-icon.folder { 
    color: #f97316; 
    background: #fff7ed;
    border-color: #ffedd5;
}
.activity-icon.default { 
    color: #6b7280; 
    background: #f9fafb;
    border-color: #f3f4f6;
}

.activity-info {
    flex: 1 !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 2px !important;
}

.activity-name {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #111827 !important;
    margin: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

.activity-type {
    font-size: 12px !important;
    color: #9ca3af !important;
    margin: 0 !important;
    text-transform: capitalize !important;
}

.activity-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}

.meta-badge {
    padding: 4px 10px;
    background: #f9fafb;
    border: 1px solid #f3f4f6;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 4px;
}

.meta-badge i {
    font-size: 10px;
}

.btn-view-activity,
.btn-launch-editor {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #f9fafb;
    color: rgb(143, 143, 143);
    text-decoration: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 1px solid rgb(180, 180, 180);
    white-space: nowrap;
    cursor: pointer !important;
}

.btn-launch-editor {
    background: #059669;
    color: white;
    border-color: #047857;
}

.btn-view-activity:hover,
.btn-launch-editor:hover {
    background: #111827;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.btn-launch-editor:hover {
    background: #047857;
}

.btn-view-activity i,
.btn-launch-editor i {
    font-size: 11px;
}

.empty-section {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
}

.empty-section i {
    font-size: 40px;
    margin-bottom: 8px;
    opacity: 0.4;
}

.empty-section p {
    font-size: 13px;
    margin: 0;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 32px;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.btn-primary {
    background: #1f2937;
    color: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #111827;
}

.btn-primary:hover {
    color: white;
    text-decoration: none;
    background: #111827;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.btn-secondary {
    background: white;
    color: #374151;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.btn-secondary:hover {
    color: #1f2937;
    text-decoration: none;
    background: #f9fafb;
    border-color: #d1d5db;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
}

@media (max-width: 768px) {
    .preview-page {
        padding: 20px 12px;
    }
    
    .header-banner {
        padding: 20px 24px;
    }
    
    .header-top-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .course-actions-header {
        width: 100%;
    }
    
    .btn-manage-course {
        width: 100%;
        justify-content: center;
    }
    
    .course-banner-image,
    .course-banner-placeholder {
        height: 160px;
    }
    
    .course-title {
        font-size: 22px;
    }
    
    .course-summary {
        font-size: 13px;
    }
    
    .header-stats-section {
        padding: 20px 24px;
    }
    
    .stats-bar {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    
    .section-header {
        padding: 16px 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .section-title {
        font-size: 16px;
    }
    
    .activity-item {
        padding: 14px 20px;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .activity-icon {
        width: 36px;
        height: 36px;
        min-width: 36px;
        font-size: 16px;
    }
    
    .activity-info {
        flex: 1 1 100%;
        order: 2;
    }
    
    .activity-name {
        font-size: 13px;
    }
    
    .activity-type {
        font-size: 11px;
    }
    
    .activity-meta {
        flex-wrap: wrap;
        width: 100%;
        order: 3;
        margin-left: 0;
        justify-content: flex-start;
    }
    
    .btn-view-activity {
        width: 100%;
        justify-content: center;
        margin-top: 8px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
        justify-content: center;
    }
    
    /* Split mode adjustments for mobile */
    .preview-page.split-mode {
        flex-direction: column;
    }
    
    .preview-page.split-mode .preview-container {
        width: 100%;
        height: 50vh;
        position: relative;
    }
    
    .code-editor-panel {
        width: 100%;
        height: 50vh;
        position: fixed;
        top: auto;
        bottom: 0;
        right: 0;
    }
}
</style>';

// Page Content
echo '<div class="preview-page">';
echo '<div class="preview-container">';

// Back Button
echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/teacher_courses.php" class="back-button">';
echo '<i class="fa fa-arrow-left"></i> Back to Courses';
echo '</a>';

// Course Header
echo '<div class="preview-header">';

// Banner
echo '<div class="header-banner">';

// Top row with badge and action buttons
echo '<div class="header-top-row">';
echo '<div class="preview-badge">';
echo '<i class="fa fa-eye"></i> Preview Mode';
echo '</div>';
echo '<div class="course-actions-header">';
echo '<a href="' . $CFG->wwwroot . '/course/view.php?id=' . $courseid . '&sesskey=' . sesskey() . '&edit=on" class="btn-manage-course">';
echo '<i class="fa fa-cog"></i> Manage Course';
echo '</a>';
echo '</div>';
echo '</div>';

// Course image (professional, minimal)
if (!empty($courseimage)) {
    echo '<img src="' . $courseimage . '" alt="' . htmlspecialchars($course->fullname) . '" class="course-banner-image">';
} else {
    echo '<div class="course-banner-placeholder">';
    echo '<i class="fa fa-book"></i>';
    echo '</div>';
}

// Course info
if ($category) {
    echo '<div class="course-category">' . htmlspecialchars($category->name) . '</div>';
}

echo '<h1 class="course-title">' . format_string($course->fullname) . '</h1>';

if (!empty($course->summary)) {
    echo '<div class="course-summary">' . format_text($course->summary, $course->summaryformat) . '</div>';
}

echo '</div>'; // header-banner

// Stats Bar (moved to separate section)
echo '<div class="header-stats-section">';
echo '<div class="stats-bar">';
echo '<div class="stat-item">';
echo '<div class="stat-label">Students</div>';
echo '<div class="stat-value">' . $enrollment_count . '</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-label">Activities</div>';
echo '<div class="stat-value">' . $activity_count . '</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-label">Lessons</div>';
$lessons_count = $DB->count_records_sql("
    SELECT COUNT(*)
    FROM {course_sections}
    WHERE course = ?
    AND section >= 1
    AND visible = 1
    AND component IS NULL
", [$courseid]);
echo '<div class="stat-value">' . $lessons_count . '</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-label">Subsections</div>';
$subsections_count = $DB->count_records_sql("
    SELECT COUNT(*)
    FROM {course_sections}
    WHERE course = ?
    AND visible = 1
    AND component = 'mod_subsection'
", [$courseid]);
echo '<div class="stat-value">' . $subsections_count . '</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-label">Start Date</div>';
echo '<div class="stat-value">' . ($course->startdate ? date('M d, Y', $course->startdate) : 'N/A') . '</div>';
echo '</div>';
echo '</div>';
echo '</div>'; // header-stats-section
echo '</div>'; // preview-header

// Function to get activity icon
function get_activity_icon($modname) {
    $icons = [
        // Standard Moodle activities
        'assign' => ['icon' => 'fa-tasks', 'class' => 'assignment'],
        'quiz' => ['icon' => 'fa-question-circle', 'class' => 'quiz'],
        'forum' => ['icon' => 'fa-comments', 'class' => 'forum'],
        'resource' => ['icon' => 'fa-file-alt', 'class' => 'resource'],
        'page' => ['icon' => 'fa-file-text', 'class' => 'page'],
        'url' => ['icon' => 'fa-link', 'class' => 'url'],
        'folder' => ['icon' => 'fa-folder', 'class' => 'folder'],
        'label' => ['icon' => 'fa-tag', 'class' => 'label'],
        'book' => ['icon' => 'fa-book', 'class' => 'resource'],
        'workshop' => ['icon' => 'fa-users-cog', 'class' => 'assignment'],
        'glossary' => ['icon' => 'fa-list', 'class' => 'resource'],
        'wiki' => ['icon' => 'fa-edit', 'class' => 'resource'],
        'scorm' => ['icon' => 'fa-play-circle', 'class' => 'resource'],
        'subsection' => ['icon' => 'fa-folder', 'class' => 'folder'],
        'h5pactivity' => ['icon' => 'fa-gamepad', 'class' => 'resource'],
        'choice' => ['icon' => 'fa-poll', 'class' => 'quiz'],
        'feedback' => ['icon' => 'fa-comment-dots', 'class' => 'forum'],
        'lesson' => ['icon' => 'fa-graduation-cap', 'class' => 'resource'],
        'survey' => ['icon' => 'fa-clipboard-check', 'class' => 'quiz'],
        'data' => ['icon' => 'fa-database', 'class' => 'resource'],
        'chat' => ['icon' => 'fa-comment', 'class' => 'forum'],
        // Custom modules
        'edwvideo' => ['icon' => 'fa-video', 'class' => 'resource'],
        'codeeditor' => ['icon' => 'fa-code', 'class' => 'assignment'],
        'hvp' => ['icon' => 'fa-gamepad', 'class' => 'resource'],
        'certificate' => ['icon' => 'fa-certificate', 'class' => 'resource'],
        'scratchemu' => ['icon' => 'fa-puzzle-piece', 'class' => 'resource'],
        'scratch' => ['icon' => 'fa-puzzle-piece', 'class' => 'resource'],
        'scratchemulator' => ['icon' => 'fa-puzzle-piece', 'class' => 'resource'],
    ];
    
    return $icons[$modname] ?? ['icon' => 'fa-file', 'class' => 'default'];
}

// Function to render activity
function render_activity($cm) {
    global $CFG, $DB;
    $icondata = get_activity_icon($cm->modname);
    
    // Get activity type name - handle custom modules
    $typename = '';
    try {
        $typename = get_string('modulename', $cm->modname);
    } catch (Exception $e) {
        // If string doesn't exist, use capitalized modname
        $typename = ucfirst($cm->modname);
    }
    
    // Get activity URL
    $activity_url = $cm->url;
    
    // Get activity description/intro and metadata
    $description = '';
    $metadata = [];
    
    try {
        // Try to get full activity record with all common fields
        $activity_record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        
        if ($activity_record) {
            // Get description/intro
            if (!empty($activity_record->intro)) {
                $description = format_text($activity_record->intro, $activity_record->introformat ?? FORMAT_HTML);
            } else if (!empty($activity_record->description)) {
                $description = format_text($activity_record->description, $activity_record->descriptionformat ?? FORMAT_HTML);
            }
            
            // Collect metadata
            
            // Start date (allowsubmissionsfromdate, timeavailable, timeopen)
            if (!empty($activity_record->allowsubmissionsfromdate)) {
                $metadata['start_date'] = $activity_record->allowsubmissionsfromdate;
            } else if (!empty($activity_record->timeavailable)) {
                $metadata['start_date'] = $activity_record->timeavailable;
            } else if (!empty($activity_record->timeopen)) {
                $metadata['start_date'] = $activity_record->timeopen;
            }
            
            // Due date (duedate, timeclose)
            if (!empty($activity_record->duedate)) {
                $metadata['due_date'] = $activity_record->duedate;
            } else if (!empty($activity_record->timeclose)) {
                $metadata['due_date'] = $activity_record->timeclose;
            }
            
            // Cut-off date
            if (!empty($activity_record->cutoffdate)) {
                $metadata['cutoff_date'] = $activity_record->cutoffdate;
            }
            
            // Grade/Points
            if (isset($activity_record->grade)) {
                $metadata['max_grade'] = $activity_record->grade;
            }
            
            // Grading method (for assignments with rubrics)
            if (!empty($activity_record->gradingmethod)) {
                $metadata['grading_method'] = $activity_record->gradingmethod;
            }
            
            // Number of attempts allowed
            if (isset($activity_record->maxattempts)) {
                $metadata['max_attempts'] = $activity_record->maxattempts;
            }
            
            // Time limit (for quizzes)
            if (!empty($activity_record->timelimit)) {
                $metadata['time_limit'] = $activity_record->timelimit;
            }
            
            // Submission types (for assignments)
            if (!empty($activity_record->submissiontypes)) {
                $metadata['submission_types'] = $activity_record->submissiontypes;
            }
            
            // Pass grade
            if (!empty($activity_record->gradepass)) {
                $metadata['pass_grade'] = $activity_record->gradepass;
            }
        }
        
        // Get competencies count
        $competencies_count = $DB->count_records('competency_modulecomp', ['cmid' => $cm->id]);
        if ($competencies_count > 0) {
            $metadata['competencies_count'] = $competencies_count;
        }
        
        // Get completion requirements
        $completion = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cm->id], '*', IGNORE_MULTIPLE);
        if (!$completion) {
            // Check course_modules for completion tracking
            $cm_completion = $DB->get_record('course_modules', ['id' => $cm->id], 'completion, completionview, completionexpected');
            if ($cm_completion) {
                $completion_text = '';
                switch ($cm_completion->completion) {
                    case COMPLETION_TRACKING_NONE:
                        $completion_text = 'None';
                        break;
                    case COMPLETION_TRACKING_MANUAL:
                        $completion_text = 'Manual';
                        break;
                    case COMPLETION_TRACKING_AUTOMATIC:
                        // Get more specific requirements
                        $requirements = [];
                        
                        // View requirement
                        if (!empty($cm_completion->completionview)) {
                            $requirements[] = 'View';
                        }
                        
                        // Grade requirement (for assignments, quizzes)
                        if (!empty($activity_record->completiongrade)) {
                            $requirements[] = 'Receive a grade';
                        }
                        
                        // Submit requirement (for assignments)
                        if (!empty($activity_record->completionsubmit)) {
                            $requirements[] = 'Submit';
                        }
                        
                        // Pass grade requirement
                        if (!empty($activity_record->completionpassgrade)) {
                            $requirements[] = 'Pass grade';
                        }
                        
                        // Expected completion date
                        if (!empty($cm_completion->completionexpected)) {
                            $metadata['completion_expected'] = $cm_completion->completionexpected;
                        }
                        
                        if (!empty($requirements)) {
                            $completion_text = 'Automatic: ' . implode(', ', $requirements);
                        } else {
                            $completion_text = 'Automatic';
                        }
                        break;
                }
                
                if (!empty($completion_text)) {
                    $metadata['completion_requirement'] = $completion_text;
                }
            }
        }
        
    } catch (Exception $e) {
        // Activity might not exist or fields might be different
        $description = '';
        $metadata = [];
    }
    
    echo '<div class="activity-wrapper">';
    echo '<div class="activity-item" data-activity-id="' . $cm->id . '" data-activity-type="' . $cm->modname . '">';
    echo '<div class="activity-icon ' . $icondata['class'] . '">';
    echo '<i class="fa ' . $icondata['icon'] . '"></i>';
    echo '</div>';
    echo '<div class="activity-info">';
    echo '<h3 class="activity-name">' . format_string($cm->name) . '</h3>';
    echo '<p class="activity-type">' . htmlspecialchars($typename) . '</p>';
    echo '</div>';
    echo '<div class="activity-meta">';
    
    // Add Launch button for ALL activity types
    // Special handling for Code Editor and Scratch (they use custom URLs)
    if ($cm->modname === 'codeeditor') {
        echo '<button class="btn-launch-editor" onclick="launchActivity(' . $cm->id . ', \'' . htmlspecialchars(addslashes(format_string($cm->name))) . '\', \'' . htmlspecialchars(addslashes($activity_url)) . '\', \'codeeditor\')">';
        echo '<i class="fa fa-play"></i> Launch';
        echo '</button>';
    } else if ($cm->modname === 'scratchemu' || $cm->modname === 'scratch' || $cm->modname === 'scratchemulator') {
        echo '<button class="btn-launch-editor" onclick="launchActivity(' . $cm->id . ', \'' . htmlspecialchars(addslashes(format_string($cm->name))) . '\', \'' . htmlspecialchars(addslashes($activity_url)) . '\', \'scratch\')">';
        echo '<i class="fa fa-play"></i> Launch';
        echo '</button>';
    } else {
        // For all other activity types, use the activity URL directly
        echo '<button class="btn-launch-editor" onclick="launchActivity(' . $cm->id . ', \'' . htmlspecialchars(addslashes(format_string($cm->name))) . '\', \'' . htmlspecialchars(addslashes($activity_url)) . '\', \'' . htmlspecialchars(addslashes($cm->modname)) . '\')">';
        echo '<i class="fa fa-play"></i> Launch';
        echo '</button>';
    }
    
    echo '<a href="' . $activity_url . '" class="btn-view-activity">';
    echo '<i class="fa fa-arrow-right"></i> View Activity';
    echo '</a>';
    echo '</div>';
    echo '</div>';
    
    // Activity description section (collapsible, shown when launched)
    if (!empty($description) || !empty($metadata)) {
        echo '<div class="activity-description-section" id="activity-desc-' . $cm->id . '">';
        echo '<div class="activity-description-content">';
        
        // Metadata Overview Section
        if (!empty($metadata)) {
            echo '<div class="activity-metadata-section">';
            echo '<div class="activity-metadata-header">';
            echo '<i class="fa fa-chart-line"></i>';
            echo '<h4>Activity Overview</h4>';
            echo '</div>';
            echo '<div class="activity-metadata-grid">';
            
            // Start Date
            if (!empty($metadata['start_date'])) {
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-calendar-check"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Start Date</div>';
                echo '<div class="metadata-value">' . userdate($metadata['start_date'], '%d %B %Y, %I:%M %p') . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Due Date
            if (!empty($metadata['due_date'])) {
                $is_overdue = $metadata['due_date'] < time();
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon ' . ($is_overdue ? 'overdue' : '') . '"><i class="fa fa-calendar-times"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Due Date</div>';
                echo '<div class="metadata-value ' . ($is_overdue ? 'overdue' : '') . '">' . userdate($metadata['due_date'], '%d %B %Y, %I:%M %p');
                if ($is_overdue) {
                    echo ' <span class="overdue-badge">Overdue</span>';
                }
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Cut-off Date
            if (!empty($metadata['cutoff_date'])) {
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-ban"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Cut-off Date</div>';
                echo '<div class="metadata-value">' . userdate($metadata['cutoff_date'], '%d %B %Y, %I:%M %p') . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Max Grade
            if (isset($metadata['max_grade'])) {
                $grade_display = $metadata['max_grade'] > 0 ? $metadata['max_grade'] . ' points' : 'No grade';
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-star"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Max Grade</div>';
                echo '<div class="metadata-value">' . $grade_display . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Pass Grade
            if (!empty($metadata['pass_grade'])) {
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-check-circle"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Pass Grade</div>';
                echo '<div class="metadata-value">' . $metadata['pass_grade'] . ' points</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Grading Method
            if (!empty($metadata['grading_method'])) {
                $grading_method_text = 'Simple';
                if ($metadata['grading_method'] == 'rubric') {
                    $grading_method_text = 'Rubric';
                } else if ($metadata['grading_method'] == 'guide') {
                    $grading_method_text = 'Marking Guide';
                }
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-clipboard-check"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Grading Method</div>';
                echo '<div class="metadata-value">' . $grading_method_text . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Max Attempts
            if (isset($metadata['max_attempts'])) {
                $attempts_display = $metadata['max_attempts'] == 0 ? 'Unlimited' : $metadata['max_attempts'];
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-redo"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Attempts Allowed</div>';
                echo '<div class="metadata-value">' . $attempts_display . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Time Limit
            if (!empty($metadata['time_limit'])) {
                $minutes = floor($metadata['time_limit'] / 60);
                $time_display = $minutes . ' minutes';
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-clock"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Time Limit</div>';
                echo '<div class="metadata-value">' . $time_display . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Competencies Count
            if (!empty($metadata['competencies_count'])) {
                $comp_text = $metadata['competencies_count'] . ' ' . ($metadata['competencies_count'] == 1 ? 'competency' : 'competencies');
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-graduation-cap"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Linked Competencies</div>';
                echo '<div class="metadata-value">' . $comp_text . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Completion Requirement
            if (!empty($metadata['completion_requirement'])) {
                echo '<div class="metadata-item metadata-item-full">';
                echo '<div class="metadata-icon"><i class="fa fa-tasks"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Completion Requirement</div>';
                echo '<div class="metadata-value">' . htmlspecialchars($metadata['completion_requirement']) . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            // Expected Completion Date
            if (!empty($metadata['completion_expected'])) {
                echo '<div class="metadata-item">';
                echo '<div class="metadata-icon"><i class="fa fa-flag-checkered"></i></div>';
                echo '<div class="metadata-details">';
                echo '<div class="metadata-label">Expected Completion</div>';
                echo '<div class="metadata-value">' . userdate($metadata['completion_expected'], '%d %B %Y') . '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            echo '</div>'; // activity-metadata-grid
            echo '</div>'; // activity-metadata-section
        }
        
        // Description/Instructions Section
        if (!empty($description)) {
            echo '<div class="activity-description-header">';
            echo '<i class="fa fa-info-circle"></i>';
            echo '<h4>Instructions & Resources</h4>';
            echo '</div>';
            echo '<div class="activity-description-body">';
            echo $description;
            echo '</div>';
        }
        
        echo '</div>'; // activity-description-content
        echo '</div>'; // activity-description-section
    }
    
    echo '</div>'; // activity-wrapper
}

// Course Content Sections - Proper hierarchical structure
echo '<div class="content-grid">';

// Get main course sections (Lessons) - exclude section 0 and delegated sections
$main_sections = $DB->get_records_sql("
    SELECT * FROM {course_sections} 
    WHERE course = ? 
    AND section >= 1
    AND visible = 1
    AND component IS NULL
    ORDER BY section ASC
", [$courseid]);

foreach ($main_sections as $section) {
    // Get section name
    $sectionname = $section->name ?: "Lesson " . $section->section;
    
    // Get subsections and direct activities in this main section
    $subsections = [];
    $direct_activities = [];
    $total_items = 0;
    
    if (!empty($section->sequence)) {
        $moduleIds = explode(',', $section->sequence);
        
        // Get subsections (modules with modname='subsection')
        $subsection_cms = $DB->get_records_sql("
            SELECT cs.id as section_id, cs.section as section_num, cs.name, cs.visible, cs.sequence, 
                   cm.id as cmid, cm.instance
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module AND m.name = 'subsection'
            JOIN {course_sections} cs ON cs.component = 'mod_subsection' AND cs.itemid = cm.instance
            WHERE cm.id IN (" . implode(',', array_map('intval', $moduleIds)) . ")
            AND cm.course = ?
            AND cs.visible = 1
            ORDER BY cm.id
        ", [$courseid]);
        
        foreach ($subsection_cms as $subsection) {
            $subsections[] = $subsection;
            $total_items++;
        }
        
        // Get direct activities (non-subsection activities in this section)
        if (!empty($modinfo->sections[$section->section])) {
            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if ($cm->uservisible && $cm->modname !== 'subsection') {
                    $direct_activities[] = $cm;
                    $total_items++;
                }
            }
        }
    }
    
    // Skip empty sections
    if ($total_items === 0) {
        continue;
    }
    
    $section_id = 'section-' . $section->section;
    
    echo '<div class="section-card">';
    
    // Section Header with collapse toggle on right
    echo '<div class="section-header" onclick="toggleSection(\'' . $section_id . '\')">';
    echo '<div class="section-title-wrapper">';
    echo '<h2 class="section-title">';
    echo '<i class="fa fa-folder-open"></i>';
    echo htmlspecialchars($sectionname);
    echo '</h2>';
    echo '<div class="section-badge">' . $total_items . ' ' . ($total_items == 1 ? 'Module' : 'Modules') . '</div>';
    echo '</div>';
    echo '<div class="section-right-controls">';
    echo '<button class="collapse-toggle" id="toggle-' . $section_id . '"><i class="fa fa-chevron-down"></i></button>';
    echo '</div>';
    echo '</div>';
    
    // Section Content
    echo '<div class="section-content" id="' . $section_id . '">';
    echo '<div class="activities-list">';
    
    // Render subsections first
    foreach ($subsections as $subsection) {
        $subsection_html_id = 'subsection-' . $subsection->section_id;
        
        // Get activities within this subsection
        $subsection_activities = [];
        if (!empty($subsection->sequence)) {
            $sub_module_ids = explode(',', $subsection->sequence);
            foreach ($sub_module_ids as $sub_cmid) {
                if (isset($modinfo->cms[$sub_cmid])) {
                    $sub_cm = $modinfo->cms[$sub_cmid];
                    if ($sub_cm->uservisible && $sub_cm->modname !== 'subsection') {
                        $subsection_activities[] = $sub_cm;
                    }
                }
            }
        }
        
        echo '<div class="subsection-wrapper">';
        echo '<div class="subsection-item">';
        
        // Subsection header with arrow on right
        echo '<div class="subsection-header" onclick="toggleSubsection(\'' . $subsection_html_id . '\')">';
        echo '<div class="subsection-left">';
        echo '<div class="subsection-icon">';
        echo '<i class="fa fa-folder"></i>';
        echo '</div>';
        echo '<div class="subsection-info">';
        echo '<div class="subsection-title">' . htmlspecialchars($subsection->name) . '</div>';
        echo '<div class="subsection-meta">Subsection • ' . count($subsection_activities) . ' activities</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="subsection-right">';
        echo '<button class="collapse-toggle" id="toggle-' . $subsection_html_id . '"><i class="fa fa-chevron-down"></i></button>';
        echo '</div>';
        echo '</div>';
        
        // Subsection activities (collapsible)
        echo '<div class="subsection-activities" id="' . $subsection_html_id . '">';
        
        if (!empty($subsection_activities)) {
            foreach ($subsection_activities as $sub_activity) {
                render_activity($sub_activity);
            }
        } else {
            echo '<div class="empty-section" style="padding: 24px;">';
            echo '<i class="fa fa-inbox"></i>';
            echo '<p style="font-size: 12px;">No activities in this subsection</p>';
            echo '</div>';
        }
        
        echo '</div>'; // subsection-activities
        echo '</div>'; // subsection-item
        echo '</div>'; // subsection-wrapper
    }
    
    // Then render direct activities (non-subsection activities)
    foreach ($direct_activities as $cm) {
        render_activity($cm);
    }
    
    echo '</div>'; // activities-list
    echo '</div>'; // section-content
    echo '</div>'; // section-card
}

echo '</div>'; // content-grid

// Action Buttons
echo '<div class="action-buttons">';
echo '<a href="' . $CFG->wwwroot . '/course/view.php?id=' . $courseid . '" class="btn-action btn-primary">';
echo '<i class="fa fa-arrow-right"></i> Go to Course';
echo '</a>';
echo '</div>';

echo '</div>'; // preview-container

// Code Editor Panel (Hidden by default)
echo '<div class="code-editor-panel" id="codeEditorPanel">';
echo '<div class="code-editor-header">';
echo '<h3 class="code-editor-title">';
echo '<i class="fa fa-code"></i>';
echo '<span id="editorActivityTitle">Code Editor</span>';
echo '</h3>';
echo '<button class="btn-close-editor" onclick="closeCodeEditor()">';
echo '<i class="fa fa-times"></i> Close';
echo '</button>';
echo '</div>';
echo '<iframe id="codeEditorIframe" class="code-editor-iframe" src="about:blank"></iframe>';
echo '</div>';

echo '</div>'; // preview-page

// JavaScript for collapsible functionality
echo '<script>
function toggleSection(sectionId) {
    event.stopPropagation();
    const content = document.getElementById(sectionId);
    const toggle = document.getElementById("toggle-" + sectionId);
    
    if (content && toggle) {
        content.classList.toggle("collapsed");
        toggle.classList.toggle("collapsed");
    }
}

function toggleSubsection(subsectionId) {
    event.stopPropagation();
    const content = document.getElementById(subsectionId);
    const toggle = document.getElementById("toggle-" + subsectionId);
    
    if (content && toggle) {
        content.classList.toggle("collapsed");
        toggle.classList.toggle("collapsed");
    }
}

// Unified Launch function for ALL activity types
function launchActivity(activityId, activityName, activityUrl, activityType) {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    const editorTitle = document.getElementById("editorActivityTitle");
    
    // Determine the URL based on activity type
    let iframeUrl = activityUrl;
    const codeEditorUrl = "' . $CFG->wwwroot . '/theme/remui_kids/ide-master/index.html";
    const scratchUrl = "' . $CFG->wwwroot . '/theme/remui_kids/scratch-gui-develop/build/index.html";
    
    if (activityType === \'codeeditor\') {
        iframeUrl = codeEditorUrl;
    } else if (activityType === \'scratch\') {
        iframeUrl = scratchUrl;
    }
    // For all other types, use the activityUrl directly
    
    // Set the iframe source
    editorIframe.src = iframeUrl;
    
    // Determine icon based on activity type
    let iconClass = \'fa-play\';
    let iconHtml = \'\';
    
    switch(activityType) {
        case \'codeeditor\':
            iconClass = \'fa-code\';
            break;
        case \'scratch\':
            iconClass = \'fa-puzzle-piece\';
            break;
        case \'scorm\':
            iconClass = \'fa-play-circle\';
            break;
        case \'edwvideo\':
            iconClass = \'fa-video\';
            break;
        case \'assign\':
            iconClass = \'fa-tasks\';
            break;
        case \'quiz\':
            iconClass = \'fa-question-circle\';
            break;
        case \'forum\':
            iconClass = \'fa-comments\';
            break;
        case \'page\':
            iconClass = \'fa-file-text\';
            break;
        case \'url\':
            iconClass = \'fa-link\';
            break;
        case \'folder\':
            iconClass = \'fa-folder\';
            break;
        default:
            iconClass = \'fa-play\';
    }
    
    iconHtml = \'<i class="fa \' + iconClass + \'"></i> \' + activityName;
    editorTitle.innerHTML = iconHtml;
    
    // Show activity description in left column
    const descriptionSection = document.getElementById("activity-desc-" + activityId);
    if (descriptionSection) {
        // Hide all other descriptions first
        document.querySelectorAll(".activity-description-section.active").forEach(el => {
            el.classList.remove("active");
        });
        // Show this activity\'s description
        descriptionSection.classList.add("active");
        
        // Scroll to the activity item
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
    
    // Enable split mode
    document.body.classList.add("split-mode-active");
    previewPage.classList.add("split-mode");
    editorPanel.classList.add("active");
}

// Launch Code Editor in split-screen mode (kept for backward compatibility)
function launchCodeEditor(activityId, activityName) {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    const editorTitle = document.getElementById("editorActivityTitle");
    
    // Set the iframe source
    const editorUrl = "' . $CFG->wwwroot . '/theme/remui_kids/ide-master/index.html";
    editorIframe.src = editorUrl;
    
    // Set the title
    editorTitle.textContent = activityName;
    
    // Show activity description in left column
    const descriptionSection = document.getElementById("activity-desc-" + activityId);
    if (descriptionSection) {
        // Hide all other descriptions first
        document.querySelectorAll(".activity-description-section.active").forEach(el => {
            el.classList.remove("active");
        });
        // Show this activity\'s description
        descriptionSection.classList.add("active");
        
        // Scroll to the activity item
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
    
    // Enable split mode
    document.body.classList.add("split-mode-active");
    previewPage.classList.add("split-mode");
    editorPanel.classList.add("active");
}

// Launch Scratch Emulator in split-screen mode
function launchScratchEmulator(activityId, activityName) {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    const editorTitle = document.getElementById("editorActivityTitle");
    
    // Set the iframe source for Scratch
    const scratchUrl = "' . $CFG->wwwroot . '/theme/remui_kids/scratch-gui-develop/build/index.html";
    editorIframe.src = scratchUrl;
    
    // Set the title with puzzle piece icon
    editorTitle.innerHTML = \'<i class="fa fa-puzzle-piece"></i> \' + activityName;
    
    // Show activity description in left column
    const descriptionSection = document.getElementById("activity-desc-" + activityId);
    if (descriptionSection) {
        // Hide all other descriptions first
        document.querySelectorAll(".activity-description-section.active").forEach(el => {
            el.classList.remove("active");
        });
        // Show this activity\'s description
        descriptionSection.classList.add("active");
        
        // Scroll to the activity item
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
    
    // Enable split mode
    document.body.classList.add("split-mode-active");
    previewPage.classList.add("split-mode");
    editorPanel.classList.add("active");
}

// Launch SCORM activity in split-screen mode
function launchScorm(activityId, activityName, activityUrl) {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    const editorTitle = document.getElementById("editorActivityTitle");
    
    // Set the iframe source to the SCORM activity URL
    editorIframe.src = activityUrl;
    
    // Set the title with play icon
    editorTitle.innerHTML = \'<i class="fa fa-play-circle"></i> \' + activityName;
    
    // Show activity description in left column
    const descriptionSection = document.getElementById("activity-desc-" + activityId);
    if (descriptionSection) {
        // Hide all other descriptions first
        document.querySelectorAll(".activity-description-section.active").forEach(el => {
            el.classList.remove("active");
        });
        // Show this activity\'s description
        descriptionSection.classList.add("active");
        
        // Scroll to the activity item
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
    
    // Enable split mode
    document.body.classList.add("split-mode-active");
    previewPage.classList.add("split-mode");
    editorPanel.classList.add("active");
}

// Launch Edwiser Video in split-screen mode
function launchEdwiserVideo(activityId, activityName, activityUrl) {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    const editorTitle = document.getElementById("editorActivityTitle");
    
    // Set the iframe source to the Edwiser Video activity URL
    editorIframe.src = activityUrl;
    
    // Set the title with video icon
    editorTitle.innerHTML = \'<i class="fa fa-video"></i> \' + activityName;
    
    // Show activity description in left column
    const descriptionSection = document.getElementById("activity-desc-" + activityId);
    if (descriptionSection) {
        // Hide all other descriptions first
        document.querySelectorAll(".activity-description-section.active").forEach(el => {
            el.classList.remove("active");
        });
        // Show this activity\'s description
        descriptionSection.classList.add("active");
        
        // Scroll to the activity item
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
    
    // Enable split mode
    document.body.classList.add("split-mode-active");
    previewPage.classList.add("split-mode");
    editorPanel.classList.add("active");
}

// Close Code Editor and return to normal view
function closeCodeEditor() {
    const previewPage = document.querySelector(".preview-page");
    const editorPanel = document.getElementById("codeEditorPanel");
    const editorIframe = document.getElementById("codeEditorIframe");
    
    // Disable split mode
    document.body.classList.remove("split-mode-active");
    previewPage.classList.remove("split-mode");
    editorPanel.classList.remove("active");
    
    // Hide all activity descriptions
    document.querySelectorAll(".activity-description-section.active").forEach(el => {
        el.classList.remove("active");
    });
    
    // Clear iframe
    editorIframe.src = "about:blank";
}

// Add keyboard accessibility
document.addEventListener("DOMContentLoaded", function() {
    // Make section headers keyboard accessible
    const sectionHeaders = document.querySelectorAll(".section-header");
    sectionHeaders.forEach(header => {
        header.setAttribute("tabindex", "0");
        header.setAttribute("role", "button");
        header.setAttribute("aria-expanded", "true");
        
        header.addEventListener("keypress", function(e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                this.click();
            }
        });
    });
    
    // Make subsection headers keyboard accessible
    const subsectionHeaders = document.querySelectorAll(".subsection-header");
    subsectionHeaders.forEach(header => {
        header.setAttribute("tabindex", "0");
        header.setAttribute("role", "button");
        header.setAttribute("aria-expanded", "true");
        
        header.addEventListener("keypress", function(e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                this.click();
            }
        });
    });
    
    // Close editor on Escape key
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") {
            const editorPanel = document.getElementById("codeEditorPanel");
            if (editorPanel && editorPanel.classList.contains("active")) {
                closeCodeEditor();
            }
        }
    });
});
</script>';

echo $OUTPUT->footer();