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
 * Simplified Gradebook for Teachers (theme_remui_kids)
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

require_login();

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url('/theme/remui_kids/teacher/gradebook.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Gradebook');
$PAGE->add_body_class('gradebook-page');
$PAGE->navbar->add('Gradebook');

// Security: teacher/admin only.
if (!has_capability('moodle/course:update', $systemcontext) && !has_capability('moodle/grade:viewall', $systemcontext) && !is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'access gradebook');
}

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Teacher courses (limited to teacher's school/company).
$teachercourses = theme_remui_kids_get_teacher_school_courses($USER->id, $teacher_company_id);

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

// Params.
$courseid = optional_param('courseid', 0, PARAM_INT);

echo $OUTPUT->header();

// Align gradebook styling with the rubrics UI
echo '<style>

#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}
.teacher-css-wrapper {
    min-height: 100vh;
    background: #f5f7fb;
}
.teacher-dashboard-wrapper {
    display: flex;
    min-height: 100vh;
}
.teacher-main-content {
    flex: 1;
    margin-left: 280px;
    background: transparent;
    padding-bottom: 40px;
}
.teacher-main-content .students-page-wrapper {
    padding: 32px clamp(20px, 4vw, 60px);
}
.students-page-header {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 24px;
}
.students-page-title {
    font-size: 2.2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}
.students-page-subtitle {
    color: #6b7280;
    margin: 0;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: #fff;
    border: 1px solid #e6e9f2;
    border-radius: 18px;
    padding: 18px;
    display: flex;
    gap: 14px;
    align-items: center;
    box-shadow: 0 18px 45px rgba(15,23,42,0.08);
}
.stat-card .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eef2ff;
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.stat-value {
    font-weight: 700;
    font-size: 22px;
    color: #0f172a;
}
.stat-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #94a3b8;
}
.students-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
    margin-bottom: 24px;
}
.search-box {
    position: relative;
    display: flex;
    align-items: center;
    background: #fff;
    border: 1px solid #e1e6ef;
    border-radius: 14px;
    padding: 0 18px;
    min-width: 320px;
    height: 48px;
    box-shadow: 0 12px 30px rgba(15,23,42,0.08);
}
.search-box .search-icon {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 16px;
}
.search-box .search-input {
    width: 100%;
    border: 0;
    background: transparent;
    padding-left: 32px;
    font-size: 15px;
    color: #1f2937;
}
.search-box .search-input::placeholder {
    color: #9ca3af;
}
.filter-chips {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.filter-chip {
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid #e0e7ff;
    background: #fff;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all .2s ease;
}
.filter-chip.active,
.filter-chip:hover {
    background: #4f46e5;
    color: #fff;
    border-color: #4f46e5;
    box-shadow: 0 10px 18px rgba(79,70,229,0.25);
}
.students-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.students-table-wrapper {
    background: #fff;
    border: 1px solid #e6e9f2;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15,23,42,0.08);
    overflow-y: auto;
    overflow-x: hidden;
    max-height: 70vh;
}
.students-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}
.students-table thead th {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #7c8295;
    background: #f8f9fd;
    padding: 16px;
    border-bottom: 1px solid #eef0f6;
}
.students-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f2f4f8;
    color: #1f2937;
    font-size: 14px;
    text-align: center;
}
.students-table tbody tr:hover {
    background: rgba(99,102,241,0.05);
}
.gradebook-table thead th:first-child,
.gradebook-table tbody td:first-child {
    position: sticky;
    left: 0;
    z-index: 5;
    background: #fff;
    box-shadow: 4px 0 15px rgba(15,23,42,0.05);
    background-clip: padding-box;
}
.gradebook-table thead th:first-child {
    z-index: 6;
    background: #f8f9fd;
    top: 0;
}
.students-table .student-name {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    text-align: left;
}
.student-email {
    display: block;
    color: #94a3b8;
    font-size: 12px;
}
.student-avatar {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg,#6366f1,#8b5cf6);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}
.students-table .actions {
    text-align: center;
}
.students-table .actions a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #dfe3f0;
    text-decoration: none;
    color: #4f46e5;
    font-weight: 600;
    font-size: 13px;
    background: #fff;
    transition: all .2s;
}
.students-table .actions a:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
}
.grade-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.grade-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s;
}
.grade-icon.plus {
    background: #eef2ff;
    color: #4f46e5;
    border-color: #d6dbff;
}
.grade-icon.rubric {
    background: #d1fae5;
    color: #15803d;
    border-color: #a7f3d0;
}
.grade-icon:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(15,23,42,0.15);
}
.grade-percentage {
    display: inline-flex;
    min-width: 64px;
    justify-content: center;
    padding: 8px 12px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid transparent;
}
.grade-excellent{background:#e0f2f1;color:#11695a;border-color:#b2dfdb;}
.grade-good{background:#fff8e1;color:#8a6914;border-color:#ffeaa7;}
.grade-fair{background:#fce4ec;color:#a61b4d;border-color:#f5c6cb;}
.grade-poor{background:#ffe1e1;color:#c62828;border-color:#f4b4b4;}
.grade-ungraded{background:#f5f5f5;color:#8c8c8c;border-color:#e0e0e0;}
.student-percentage {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 6px;
    border: 1px solid transparent;
}
.student-percentage.excellent{background:#def7ec;color:#0f766e;border-color:#9fe3d0;}
.student-percentage.good{background:#fff1c6;color:#a16207;border-color:#ffd48a;}
.student-percentage.fair{background:#fde4f2;color:#a21caf;border-color:#f9b4df;}
.student-percentage.poor{background:#ffe1e1;color:#c62828;border-color:#f4b4b4;}
.student-percentage.ungraded{background:#f5f5f5;color:#8c8c8c;border-color:#e0e0e0;}
.helper-note {
    text-align: center;
    color: #94a3b8;
    margin-top: 18px;
    font-size: 13px;
}
.course-selector {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.course-dropdown-label {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    white-space: nowrap;
    line-height: 1.2;
}
.course-dropdown {
    position: relative;
    min-width: 200px;
    max-width: 400px;
    width: 100%;
    flex: 1 1 auto;
    padding: 0px !important;
}
#gbCourseSelect {
    width: 100%;
    padding: 10px 36px 10px 14px;
    border: 1px solid #e1e6ef;
    background: transparent !important;
    color: #1f2937;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    appearance: none;
     background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
    box-shadow: 0 2px 8px rgba(15,23,42,0.06);
}
#gbCourseSelect:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15,23,42,0.1);
}
#gbCourseSelect:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
}
@media (max-width: 768px) {
    .course-selector {
        flex-direction: column;
        align-items: stretch;
    }
    .course-dropdown-label {
        margin-bottom: 4px;
    }
    .course-dropdown {
        max-width: 100%;
    }
}
@media (max-width: 1024px) {
    .teacher-main-content { margin-left: 0; }
    .students-table { min-width: 600px; }
}

/* Grading Modal Styles */
.grading-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}

.grading-modal-content {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.3);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    animation: slideUp 0.3s ease;
}

.grading-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 28px;
    border-bottom: 1px solid #e6e9f2;
}

.grading-modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
}

.grading-modal-close {
    font-size: 28px;
    font-weight: 300;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.grading-modal-close:hover {
    color: #0f172a;
    background: #f1f5f9;
}

.grading-modal-body {
    padding: 28px;
}

.grading-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
    padding: 20px;
    background: #f8f9fd;
    border-radius: 12px;
}

.grading-detail-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.grading-detail-item label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #7c8295;
}

.grading-detail-item span {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
}

.grading-input-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.grading-input-section label {
    font-size: 14px;
    font-weight: 600;
    color: #475569;
}

.grading-input-section input {
    width: 100%;
    padding: 14px 18px;
    border: 1px solid #e1e6ef;
    border-radius: 12px;
    font-size: 15px;
    color: #1f2937;
    background: #fff;
    transition: all 0.2s;
    box-sizing: border-box;
}

.grading-input-section input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.grading-actions {
    display: flex;
    gap: 12px;
}

.grading-save-btn,
.grading-clear-btn {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.grading-save-btn {
    background: #4f46e5;
    color: #fff;
}

.grading-save-btn:hover {
    background: #4338ca;
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(79, 70, 229, 0.3);
}

.grading-clear-btn {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e1e6ef;
}

.grading-clear-btn:hover {
    background: #e2e8f0;
    border-color: #cbd5e1;
}

.grading-modal-footer {
    padding: 20px 28px;
    border-top: 1px solid #e6e9f2;
    display: flex;
    justify-content: flex-end;
}

.grading-modal-btn {
    padding: 10px 24px;
    border: 1px solid #e1e6ef;
    border-radius: 10px;
    background: #fff;
    color: #475569;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.grading-modal-btn:hover {
    background: #f8f9fd;
    border-color: #cbd5e1;
}

/* SCORM Modal Styles */
.scorm-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}

.scorm-modal-content {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.3);
    max-width: 700px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    animation: slideUp 0.3s ease;
}

.scorm-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 28px;
    border-bottom: 1px solid #e6e9f2;
}

.scorm-modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
}

.scorm-modal-close {
    font-size: 28px;
    font-weight: 300;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.scorm-modal-close:hover {
    color: #0f172a;
    background: #f1f5f9;
}

.scorm-modal-body {
    padding: 28px;
}

.scorm-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.scorm-detail-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.scorm-detail-item label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #7c8295;
}

.scorm-detail-item span {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
}

.status-badge,
.completion-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-transform: capitalize;
}

.status-badge.completed,
.status-badge.passed {
    background: #d1fae5;
    color: #15803d;
}

.status-badge.incomplete,
.status-badge.failed {
    background: #fee2e2;
    color: #dc2626;
}

.status-badge.browsed {
    background: #fef3c7;
    color: #d97706;
}

.status-badge.not_attempted,
.status-badge.attempted {
    background: #e5e7eb;
    color: #6b7280;
}

.completion-badge.completed {
    background: #d1fae5;
    color: #15803d;
}

.completion-badge.incomplete {
    background: #fee2e2;
    color: #dc2626;
}

.scorm-modal-footer {
    padding: 20px 28px;
    border-top: 1px solid #e6e9f2;
    display: flex;
    justify-content: flex-end;
}

.scorm-modal-btn {
    padding: 10px 24px;
    border: 1px solid #e1e6ef;
    border-radius: 10px;
    background: #fff;
    color: #475569;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.scorm-modal-btn:hover {
    background: #f8f9fd;
    border-color: #cbd5e1;
}

/* Modal Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideUp {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .grading-modal-content,
    .scorm-modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .grading-details-grid,
    .scorm-details-grid {
        grid-template-columns: 1fr;
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

/* Teacher Help Modal Styles */
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

.teacher-help-video-player {
    display: none;
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

#teacherHelpVideoPlayer {
    width: 100%;
    border-radius: 8px;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
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

@media (max-width: 768px) {
    .teacher-help-button span:not(.help-badge-count) {
        display: none;
    }
    
    .teacher-help-modal-content {
        width: 95%;
        max-height: 90vh;
    }
}
</style>';

// Sidebar (reuse links pattern from other teacher pages) - minimal subset.
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';
// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

// Main content.
echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';
echo '<div class="students-page-header">';
echo '<div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">';
echo '<div>';
echo '<h1 class="students-page-title">Gradebook</h1>';
echo '<p class="students-page-subtitle">See and update grades across all activities</p>';
echo '</div>';
if ($has_help_videos) {
    echo '<a class="teacher-help-button" id="teacherHelpButton" style="text-decoration: none; display: inline-flex;">';
    echo '<i class="fa fa-question-circle"></i>';
    echo '<span>Need Help?</span>';
    echo '<span class="help-badge-count">' . $help_videos_count . '</span>';
    echo '</a>';
}
echo '</div>';
echo '</div>';

// Course selector.
echo '<div class="course-selector">';
echo '<label for="gbCourseSelect" class="course-dropdown-label">Select Course</label>';
echo '<div class="course-dropdown">';
echo '<select id="gbCourseSelect" onchange="window.location.href=this.value">';
echo '<option value="">Choose a course...</option>';
foreach ($teachercourses as $course) {
    $selected = ($courseid == $course->id) ? 'selected' : '';
    $url = new moodle_url('/theme/remui_kids/teacher/gradebook.php', array('courseid' => $course->id));
    echo '<option value="' . $url->out() . '" ' . $selected . '>' . format_string($course->fullname) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

if ($courseid) {
    try {
        error_log("Gradebook: Loading course with ID: " . $courseid);
        
        $course = get_course($courseid);
        $coursecontext = context_course::instance($course->id);
        error_log("Gradebook: Course loaded - " . $course->fullname);

        require_capability('moodle/grade:viewall', $coursecontext);

        // Enrolled students (only student role, same school, exclude admins/managers/teachers).
        error_log("Gradebook: Fetching enrolled students for course " . $course->id);
        $students = theme_remui_kids_get_course_students_by_school($course->id, $teacher_company_id);
        $students = theme_remui_kids_filter_out_admins($students, $course->id);
        error_log("Gradebook: Found " . count($students) . " students after school/admin filter");

        // Grade items: include mod/manual; omit course/category totals to keep UI clean.
        error_log("Gradebook: Fetching grade items for course " . $course->id);
        $gradeitems = $DB->get_records_select('grade_items', 'courseid = ? AND itemtype IN (\'mod\', \'manual\')', array($course->id), 'sortorder ASC');
        error_log("Gradebook: Found " . count($gradeitems) . " grade items");
        
        // Check grading methods for each grade item to detect rubric usage
        // Use the same logic as rubric_grading.php and rubrics.php
        $gradingMethods = array();
        
        // Get all rubrics for this course using the existing function
        $rubrics = theme_remui_kids_get_teacher_rubrics($USER->id, $course->id);
        $rubricAssignmentIds = array();
        
        // Extract assignment IDs that use rubrics
        foreach ($rubrics as $rubric) {
            $rubricAssignmentIds[] = $rubric['assignment_id'];
        }
        
        error_log("Gradebook: Found " . count($rubrics) . " rubrics for course " . $course->id);
        error_log("Gradebook: Rubric assignment IDs: " . implode(', ', $rubricAssignmentIds));
        
        // Check each grade item
        foreach ($gradeitems as $gi) {
            error_log("Gradebook: Checking grade item - ID: " . $gi->id . ", Module: " . $gi->itemmodule . ", Instance: " . $gi->iteminstance . ", Name: " . $gi->itemname);
            
            if ($gi->itemmodule === 'assign' && in_array($gi->iteminstance, $rubricAssignmentIds)) {
                $gradingMethods[$gi->id] = 'rubric';
                error_log("Gradebook: Assignment " . $gi->itemname . " uses rubric grading (ID: " . $gi->iteminstance . ")");
            } else {
                $gradingMethods[$gi->id] = 'standard';
                error_log("Gradebook: Grade item " . $gi->itemname . " uses standard grading");
            }
        }

    // Preload grades for all students and items.
    $itemids = array_map(function($gi){return $gi->id;}, $gradeitems);
    $gradesbykey = array();
    if (!empty($itemids) && !empty($students)) {
        list($insql, $inparams) = $DB->get_in_or_equal($itemids, SQL_PARAMS_QM);
        $userids = array_map(function($u){return $u->id;}, $students);
        list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);
        
        
        // Use Moodle's exact approach from grade_report_grader
        $query = "SELECT g.*
                  FROM {grade_items} gi,
                       {grade_grades} g
                 WHERE g.itemid = gi.id AND gi.courseid = ?";
        error_log("Gradebook: Using Moodle's query: " . $query);
        error_log("Gradebook: Course ID: " . $course->id);
        
        $records = $DB->get_records_sql($query, array($course->id));
        
        // Store all grades (SQL already filters out null/empty)
        error_log("Gradebook: Found " . count($records) . " non-null grade records");
        
        foreach ($records as $rec) {
            // Only store non-null, non-empty grades (like Moodle does)
            if ($rec->finalgrade !== null && $rec->finalgrade !== '') {
                $gradesbykey[$rec->userid . ':' . $rec->itemid] = $rec->finalgrade;
                error_log("Gradebook: Stored grade - User: " . $rec->userid . ", Item: " . $rec->itemid . ", Grade: " . $rec->finalgrade);
            }
        }
        error_log("Gradebook: Total grades stored: " . count($gradesbykey));
    }

    // Preload SCORM tracking data for SCORM activities
    $scormtracking = array();
    $scorm_by_user_scorm = array();
    $gradeitem_to_scormid = array();
    
    try {
        error_log("Gradebook: Starting SCORM tracking data collection");
        
        $scormitems = array_filter($gradeitems, function($gi) { return $gi->itemmodule === 'scorm'; });
        error_log("Gradebook: Found " . count($scormitems) . " SCORM items");
        
        if (!empty($scormitems) && !empty($students)) {
            $scormitemids = array_map(function($gi){return $gi->id;}, $scormitems);
            error_log("Gradebook: SCORM item IDs: " . implode(',', $scormitemids));
            
            list($insql, $inparams) = $DB->get_in_or_equal($scormitemids, SQL_PARAMS_QM);
            error_log("Gradebook: SCORM instances query - SQL: " . $insql . ", Params: " . implode(',', $inparams));
            $scorminstances = $DB->get_records_sql(
                "SELECT gi.id as gradeitemid, s.id as scormid, s.name as scormname
                 FROM {grade_items} gi
                 JOIN {scorm} s ON s.id = gi.iteminstance
                 WHERE gi.id $insql
                 AND gi.itemmodule = 'scorm'",
                $inparams
            );
            error_log("Gradebook: Found " . count($scorminstances) . " SCORM instances");
            foreach ($scorminstances as $inst) {
                error_log("Gradebook: SCORM instance - GradeItemID: " . $inst->gradeitemid . ", SCORMID: " . $inst->scormid . ", Name: " . $inst->scormname);
            }
            
            // Build item->scorm map and a second index (user:scormid) for robust lookups
            foreach ($scorminstances as $instance) {
                $gradeitem_to_scormid[$instance->gradeitemid] = $instance->scormid;
                error_log("Gradebook: Processing SCORM instance - ID: " . $instance->scormid . ", Name: " . $instance->scormname);
                
                $userids = array_map(function($u){return $u->id;}, $students);
                list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);
                
                $trackingquery = "SELECT sa.userid, sv.value, se.element, sv.timemodified
                     FROM {scorm_attempt} sa
                     JOIN {scorm_scoes_value} sv ON sv.attemptid = sa.id
                     JOIN {scorm_element} se ON se.id = sv.elementid
                     WHERE sa.scormid = ? AND sa.userid $uinsql
                     AND se.element IN ('cmi.core.lesson_status', 'cmi.core.completion_status', 'cmi.core.total_time', 'cmi.core.session_time', 'cmi.core.exit', 'cmi.core.entry', 'cmi.core.score.raw')
                     ORDER BY sa.userid, sv.timemodified DESC";
                $trackingparams = array_merge(array($instance->scormid), $uinparams);
                error_log("Gradebook: Tracking query for SCORM " . $instance->scormid . " - Params: " . implode(',', $trackingparams));
                $trackingdata = $DB->get_records_sql($trackingquery, $trackingparams);
                error_log("Gradebook: Found " . count($trackingdata) . " tracking records for SCORM " . $instance->scormid);
                
                // Also get ALL tracking data for this SCORM to see what we're missing
                $allTrackingQuery = "SELECT sa.userid, sv.value, se.element, sv.timemodified
                     FROM {scorm_attempt} sa
                     JOIN {scorm_scoes_value} sv ON sv.attemptid = sa.id
                     JOIN {scorm_element} se ON se.id = sv.elementid
                     WHERE sa.scormid = ? AND sa.userid $uinsql
                     ORDER BY sa.userid, sv.timemodified DESC";
                $allTrackingData = $DB->get_records_sql($allTrackingQuery, array_merge(array($instance->scormid), $uinparams));
                error_log("Gradebook: ALL tracking data for SCORM " . $instance->scormid . " (" . count($allTrackingData) . " records):");
                foreach ($allTrackingData as $allTrack) {
                    error_log("Gradebook: ALL - User: " . $allTrack->userid . ", Element: " . $allTrack->element . ", Value: " . $allTrack->value);
                }
                
                // Debug: Log all tracking records
                foreach ($trackingdata as $debugTrack) {
                    error_log("Gradebook: Raw track - User: " . $debugTrack->userid . ", Element: " . $debugTrack->element . ", Value: " . $debugTrack->value . ", TimeModified: " . $debugTrack->timemodified);
                }
                
                // Debug: Check if we're getting the expected elements
                $elementsFound = array();
                foreach ($trackingdata as $debugTrack) {
                    $elementsFound[$debugTrack->element] = true;
                }
                error_log("Gradebook: Elements found in query: " . implode(', ', array_keys($elementsFound)));
                
                // Group tracking data by user first, then process all elements for each user
                $userTrackingData = array();
                foreach ($trackingdata as $track) {
                    $userid = $track->userid;
                    if (!isset($userTrackingData[$userid])) {
                        $userTrackingData[$userid] = array();
                    }
                    $userTrackingData[$userid][] = $track;
                }
                
                // Process each user's tracking data
                foreach ($userTrackingData as $userid => $userTracks) {
                    $key = $userid . ':' . $instance->gradeitemid;
                    
                    // Initialize user data
                    $scormtracking[$key] = array(
                        'scormname' => $instance->scormname,
                        'status' => 'not_attempted',
                        'completion' => 'not_attempted', 
                        'score' => null,
                        'total_time' => '00:00:00',
                        'session_time' => '00:00:00',
                        'entry' => 'ab-initio',
                        'exit' => null,
                        'last_accessed' => null,
                        'has_attempted' => false
                    );
                    
                    // Mark as attempted since we have tracking data
                    $scormtracking[$key]['has_attempted'] = true;
                    
                    // Also index by user + scormid so we can resolve mismatches
                    $altkey = $userid . ':' . $instance->scormid;
                    $scorm_by_user_scorm[$altkey] = &$scormtracking[$key];
                    
                    // Process all tracking records for this user
                    foreach ($userTracks as $track) {
                        error_log("Gradebook: Processing track for user $userid - Element: " . $track->element . ", Value: " . $track->value . ", Time: " . $track->timemodified);
                        
                        switch ($track->element) {
                            case 'cmi.core.lesson_status':
                                $scormtracking[$key]['status'] = $track->value;
                                error_log("Gradebook: Set status to: " . $track->value);
                                // Also set completion based on lesson_status for consistency
                                if ($track->value === 'completed' || $track->value === 'passed') {
                                    $scormtracking[$key]['completion'] = 'completed';
                                } else if ($track->value === 'incomplete' || $track->value === 'failed') {
                                    $scormtracking[$key]['completion'] = 'incomplete';
                                } else if ($track->value === 'browsed') {
                                    $scormtracking[$key]['completion'] = 'browsed';
                                }
                                break;
                            case 'cmi.core.completion_status':
                                $scormtracking[$key]['completion'] = $track->value;
                                error_log("Gradebook: Set completion to: " . $track->value);
                                break;
                            case 'cmi.core.total_time':
                                $scormtracking[$key]['total_time'] = $track->value;
                                error_log("Gradebook: Set total_time to: " . $track->value);
                                break;
                            case 'cmi.core.session_time':
                                $scormtracking[$key]['session_time'] = $track->value;
                                error_log("Gradebook: Set session_time to: " . $track->value);
                                break;
                            case 'cmi.core.exit':
                                $scormtracking[$key]['exit'] = $track->value;
                                error_log("Gradebook: Set exit to: " . $track->value);
                                break;
                            case 'cmi.core.entry':
                                $scormtracking[$key]['entry'] = $track->value;
                                error_log("Gradebook: Set entry to: " . $track->value);
                                break;
                            case 'cmi.core.score.raw':
                                $scormtracking[$key]['score'] = $track->value;
                                error_log("Gradebook: Set score to: " . $track->value);
                                break;
                            default:
                                error_log("Gradebook: Unknown element: " . $track->element);
                                break;
                        }
                        $scormtracking[$key]['last_accessed'] = max($scormtracking[$key]['last_accessed'] ?? 0, $track->timemodified);
                    }
                    
                    error_log("Gradebook: Final data for user $userid: " . json_encode($scormtracking[$key]));
                }
            }
        }
        error_log("Gradebook: SCORM tracking data collection completed. Total records: " . count($scormtracking));
        error_log("Gradebook: SCORM tracking (by scormid) records: " . count($scorm_by_user_scorm));
        
    } catch (Exception $e) {
        error_log("Gradebook: SCORM tracking error - " . $e->getMessage());
        error_log("Gradebook: SCORM tracking error trace - " . $e->getTraceAsString());
        // Continue without SCORM tracking if there's an error
        $scormtracking = array();
    }

    // Enhanced dashboard statistics
    $studentcount = count($students);
    $columncount = count($gradeitems);
    $gradedcells = 0; 
    $attemptedcells = 0;
    $completedactivities = 0;
    $gradesum = 0;
    $gradecount = 0;
    $totalcells = max(1, $studentcount * max(1, $columncount));
    
    foreach ($students as $s) {
        foreach ($gradeitems as $gi) {
            $final = null;
            if (isset($gradesbykey[$s->id . ':' . $gi->id])) {
                $final = $gradesbykey[$s->id . ':' . $gi->id];
                $gradedcells++;
                if ($final !== null && $final !== '') {
                    $gradesum += $final;
                    $gradecount++;
                }
            }
            
            // Check for attempts and completions
            $hasAttempted = false;
            if ($gi->itemmodule === 'scorm' && isset($scormtracking[$s->id][$gi->id])) {
                $hasAttempted = $scormtracking[$s->id][$gi->id]['has_attempted'];
                if ($scormtracking[$s->id][$gi->id]['completion'] === 'completed') {
                    $completedactivities++;
                }
            } else if ($gi->itemmodule === 'quiz') {
                $attempts = $DB->get_records('quiz_attempts', array('quiz' => $gi->iteminstance, 'userid' => $s->id));
                $hasAttempted = !empty($attempts);
            } else if ($gi->itemmodule === 'assign') {
                $submissions = $DB->get_records('assign_submission', array('assignment' => $gi->iteminstance, 'userid' => $s->id));
                $hasAttempted = !empty($submissions);
            }
            
            if ($hasAttempted) {
                $attemptedcells++;
            }
        }
    }
    
    $gradedpct = round(($gradedcells / $totalcells) * 100);
    $attemptedpct = round(($attemptedcells / $totalcells) * 100);
    $avgGrade = $gradecount > 0 ? round($gradesum / $gradecount, 1) : 0;
    $completionRate = $totalcells > 0 ? round(($completedactivities / $totalcells) * 100) : 0;

    echo '<div class="stats-grid">';
    echo '  <div class="stat-card"><div class="stat-icon"><i class="fa fa-users"></i></div><div><div class="stat-value">' . $studentcount . '</div><div class="stat-label">Students</div></div></div>';
    echo '  <div class="stat-card"><div class="stat-icon"><i class="fa fa-layer-group"></i></div><div><div class="stat-value">' . $columncount . '</div><div class="stat-label">Activities</div></div></div>';
    echo '  <div class="stat-card"><div class="stat-icon"><i class="fa fa-chart-line"></i></div><div><div class="stat-value">' . $avgGrade . '</div><div class="stat-label">Avg Grade</div></div></div>';
    echo '  <div class="stat-card"><div class="stat-icon"><i class="fa fa-check-circle"></i></div><div><div class="stat-value">' . $gradedpct . '%</div><div class="stat-label">Graded</div></div></div>';
    echo '</div>';

    // Controls: search & quick filters.
    echo '<div class="students-controls">';
    echo '  <div class="search-box">';
    echo '      <i class="fa fa-search search-icon"></i>';
    echo '      <input type="text" id="gbSearch" class="search-input" placeholder="Search students or emails...">';
    echo '  </div>';
    echo '  <div class="filter-chips">';
    echo '      <button type="button" class="filter-chip active" data-filter="all">All Students</button>';
    echo '      <button type="button" class="filter-chip" data-filter="graded">Has Grades</button>';
    echo '      <button type="button" class="filter-chip" data-filter="attempted">Has Attempts</button>';
    echo '      <button type="button" class="filter-chip" data-filter="missing">Needs Grading</button>';
    echo '  </div>';
    echo '</div>';
    
    // Table.
    echo '<div class="students-container">';
    echo '<div class="students-table-wrapper">';
    echo '<table class="students-table gradebook-table">';
    echo '<thead><tr>';
    echo '<th class="sticky-student-header">Student</th>';
    echo '<th>Total</th>';
    foreach ($gradeitems as $gi) {
        $coltitle = format_string($gi->itemname ?: $gi->itemtype);
        $mod = isset($gi->itemmodule) ? $gi->itemmodule : '';
        $icon = 'fa-star';
        if ($mod === 'quiz') { $icon = 'fa-question-circle'; }
        else if ($mod === 'assign') { $icon = 'fa-tasks'; }
        else if ($mod === 'scorm') { $icon = 'fa-cube'; }
        else if ($mod === 'h5pactivity') { $icon = 'fa-shapes'; }
        else if ($mod === 'workshop') { $icon = 'fa-people-arrows'; }
        echo '<th title="' . s($coltitle) . '"><span class="modhead"><i class="fa ' . $icon . '"></i><span>' . s(core_text::substr($coltitle, 0, 28)) . '</span></span></th>';
    }
    echo '<th class="actions">Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($students as $s) {
        $initials = strtoupper(substr($s->firstname, 0, 1) . substr($s->lastname, 0, 1));
        $rowcells = '';
        $studentHasGraded = false;
        $studentHasAttempted = false;
        $studentHasMissing = false;

        $rowcells .= '<td class="student-name">';
        $rowcells .= '<span class="student-avatar">' . $initials . '</span>';
        $rowcells .= '<div>';
        $rowcells .= '<div>'. fullname($s) . '</div>';
        $rowcells .= '<small class="student-email">' . s($s->email) . '</small>';
        $rowcells .= '</div>';
        $rowcells .= '</td>';
        
        $usertotal = 0; 
        $usertotalmax = 0;
        foreach ($gradeitems as $gi) {
            $key = $s->id . ':' . $gi->id;
            $final = isset($gradesbykey[$key]) ? (float)$gradesbykey[$key] : null;
            $max = (float)$gi->grademax;
            if ($final !== null && $final !== '' && $final > 0) {
                $usertotal += $final; 
                $usertotalmax += $max;
            }
        }

        $totaldisplay = ($usertotalmax > 0) ? format_float(($usertotal / $usertotalmax) * 100, 1) . '%' : '-';
        $userurl = new moodle_url('/grade/report/user/index.php', array('id' => $course->id, 'userid' => $s->id));
        $studentPercentage = ($usertotalmax > 0) ? ($usertotal / $usertotalmax) * 100 : 0;
        $studentBadgeClass = 'ungraded';
        if ($studentPercentage >= 75) {
            $studentBadgeClass = 'excellent';
        } else if ($studentPercentage >= 50) {
            $studentBadgeClass = 'good';
        } else if ($studentPercentage >= 30) {
            $studentBadgeClass = 'fair';
        } else if ($studentPercentage > 0) {
            $studentBadgeClass = 'poor';
        }
        $percentageBadge = '';
        if ($studentPercentage > 0) {
            $percentageBadge = '<span class="student-percentage ' . $studentBadgeClass . '">' . round($studentPercentage) . '%</span>';
        }
        $rowcells .= '<td><strong>' . $totaldisplay . '</strong>' . $percentageBadge . '</td>';
        
        foreach ($gradeitems as $gi) {
            $key = $s->id . ':' . $gi->id;
            $final = isset($gradesbykey[$key]) ? (float)$gradesbykey[$key] : null;
            $max = (float)$gi->grademax;
            $display = '-';
            $class = 'grade-ungraded';
            $hasAttempted = false;
            
            if ($final !== null && $final !== '' && $final > 0) {
                $studentHasGraded = true;
                $percentage = ($final / $max) * 100;
                $display = format_float($final, 2);
                if ($percentage >= 75) {
                    $class = 'grade-excellent';
                } else if ($percentage >= 50) {
                    $class = 'grade-good';
                } else if ($percentage >= 30) {
                    $class = 'grade-fair';
                } else {
                    $class = 'grade-poor';
                }
            } else {
                $studentHasMissing = true;
            }
            
            $cellContent = '';
            if ($gi->itemmodule === 'scorm') {
                $scormkey = $s->id . ':' . $gi->id;
                $scormdata = isset($scormtracking[$scormkey]) ? $scormtracking[$scormkey] : null;
                if ($scormdata) {
                    $hasAttempted = $scormdata['has_attempted'] ?? false;
                }
                if ($final !== null && $final !== '' && $final > 0) {
                    $percentage = ($final / $max) * 100;
                    $display = format_float($final, 1);
                    if ($percentage >= 75) {
                        $class = 'grade-excellent';
                    } else if ($percentage >= 50) {
                        $class = 'grade-good';
                    } else if ($percentage >= 30) {
                        $class = 'grade-fair';
                    } else {
                        $class = 'grade-poor';
                    }
                    $cellContent = '<span class="grade-percentage ' . $class . '" onclick="showScormDetails(' . $s->id . ', ' . $gi->id . ', \'' . addslashes($s->firstname . ' ' . $s->lastname) . '\', \'' . addslashes($gi->itemname) . '\')" style="cursor: pointer;">' . $display . '</span>';
                } else {
                    if ($scormdata) {
                        $status = $scormdata['status'] ?? 'not_attempted';
                        $completion = $scormdata['completion'] ?? 'not_attempted';
                        $primaryStatus = ($completion !== 'not_attempted') ? $completion : $status;
                        if ($primaryStatus === 'completed' || $primaryStatus === 'passed') {
                            $display = '✓';
                            $class = 'grade-excellent';
                        } else if ($primaryStatus === 'incomplete' || $primaryStatus === 'failed') {
                            $display = '◐';
                            $class = 'grade-fair';
                        } else if ($primaryStatus === 'browsed') {
                            $display = '👁';
                            $class = 'grade-good';
                        } else if ($hasAttempted) {
                            $display = '◐';
                            $class = 'grade-fair';
                        } else {
                            $display = '-';
                            $class = 'grade-ungraded';
                        }
                    }
                    $cellContent = '<span class="grade-percentage ' . $class . '" onclick="showScormDetails(' . $s->id . ', ' . $gi->id . ', \'' . addslashes($s->firstname . ' ' . $s->lastname) . '\', \'' . addslashes($gi->itemname) . '\')" style="cursor: pointer;">' . $display . '</span>';
                }
            } else {
                if ($gi->itemmodule === 'quiz') {
                    $attempts = $DB->get_records('quiz_attempts', array('quiz' => $gi->iteminstance, 'userid' => $s->id));
                    $hasAttempted = !empty($attempts);
                } else if ($gi->itemmodule === 'assign') {
                    $submissions = $DB->get_records('assign_submission', array('assignment' => $gi->iteminstance, 'userid' => $s->id));
                    $hasAttempted = !empty($submissions);
                }
                $cellContent = '<span class="grade-percentage ' . $class . '">' . $display . '</span>';
            }
            
            if ($hasAttempted) {
                $studentHasAttempted = true;
            }
            
            $gradingMethod = isset($gradingMethods[$gi->id]) ? $gradingMethods[$gi->id] : 'standard';
            if ($gradingMethod === 'rubric') {
                $rubricUrl = $CFG->wwwroot . '/theme/remui_kids/teacher/grade_student.php?assignmentid=' . $gi->iteminstance . '&courseid=' . $course->id . '&studentid=' . $s->id;
                $gradingIcon = '<div class="grade-icon rubric" onclick="window.open(\'' . $rubricUrl . '\', \'_blank\')" title="Grade with rubric">R</div>';
            } else {
                $gradingIcon = '<div class="grade-icon plus" onclick="showGradingModal(' . $s->id . ', ' . $gi->id . ', \'' . addslashes($s->firstname . ' ' . $s->lastname) . '\', \'' . addslashes($gi->itemname) . '\', ' . $gi->grademax . ', \'' . $final . '\')" title="Grade this activity">+</div>';
            }
            
            $rowcells .= '<td><div class="grade-cell">' . $cellContent . $gradingIcon . '</div></td>';
        }
        
        $rowcells .= '<td class="actions"><a href="' . $userurl->out() . '" target="_blank"><i class="fa fa-external-link-alt"></i> User report</a></td>';
        
        $rowClasses = ['gradebook-row'];
        if ($studentHasGraded) { $rowClasses[] = 'has-graded'; }
        if ($studentHasAttempted) { $rowClasses[] = 'has-attempted'; }
        if ($studentHasMissing) { $rowClasses[] = 'has-missing'; }
        
        echo '<tr class="' . implode(' ', $rowClasses) . '">';
        echo $rowcells;
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '<p class="helper-note">Tip: Use the search and filters above to quickly find students who need attention. Totals shown are simple percent across visible items.</p>';
    echo '</div>';
        
    } catch (Exception $e) {
        error_log("Gradebook: Main error - " . $e->getMessage());
        error_log("Gradebook: Main error trace - " . $e->getTraceAsString());
        echo '<div class="alert alert-danger">Error loading gradebook: ' . $e->getMessage() . '</div>';
    }
}

echo '</div>'; // students-page-wrapper
echo '</div>'; // teacher-main-content
echo '</div>'; // teacher-dashboard-wrapper
echo '</div>'; // teacher-css-wrapper

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

// Manual Grading Modal
echo '<div id="gradingModal" class="grading-modal" style="display: none;">';
echo '  <div class="grading-modal-content">';
echo '    <div class="grading-modal-header">';
echo '      <h3 id="gradingModalTitle">Manual Grade Entry</h3>';
echo '      <span class="grading-modal-close" onclick="closeGradingModal()">&times;</span>';
echo '    </div>';
echo '    <div class="grading-modal-body">';
echo '      <div class="grading-details-grid">';
echo '        <div class="grading-detail-item">';
echo '          <label>Student:</label>';
echo '          <span id="gradingStudentName">-</span>';
echo '        </div>';
echo '        <div class="grading-detail-item">';
echo '          <label>Activity:</label>';
echo '          <span id="gradingActivityName">-</span>';
echo '        </div>';
echo '        <div class="grading-detail-item">';
echo '          <label>Max Points:</label>';
echo '          <span id="gradingMaxPoints">-</span>';
echo '        </div>';
echo '        <div class="grading-detail-item">';
echo '          <label>Current Grade:</label>';
echo '          <span id="gradingCurrentGrade">-</span>';
echo '        </div>';
echo '      </div>';
echo '      <div class="grading-input-section">';
echo '        <label for="gradingInput">Enter Grade:</label>';
echo '        <input type="number" id="gradingInput" step="0.01" min="0" placeholder="Enter grade...">';
echo '        <div class="grading-actions">';
echo '          <button onclick="saveManualGrade()" class="grading-save-btn">Save Grade</button>';
echo '          <button onclick="clearManualGrade()" class="grading-clear-btn">Clear Grade</button>';
echo '        </div>';
echo '      </div>';
echo '    </div>';
echo '    <div class="grading-modal-footer">';
echo '      <button onclick="closeGradingModal()" class="grading-modal-btn">Close</button>';
echo '    </div>';
echo '  </div>';
echo '</div>';

// SCORM Details Modal
echo '<div id="scormModal" class="scorm-modal" style="display: none;">';
echo '  <div class="scorm-modal-content">';
echo '    <div class="scorm-modal-header">';
echo '      <h3 id="scormModalTitle">SCORM Activity Details</h3>';
echo '      <span class="scorm-modal-close" onclick="closeScormModal()">&times;</span>';
echo '    </div>';
echo '    <div class="scorm-modal-body">';
echo '      <div class="scorm-details-grid">';
echo '        <div class="scorm-detail-item">';
echo '          <label>Student:</label>';
echo '          <span id="scormStudentName">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Activity:</label>';
echo '          <span id="scormActivityName">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Status:</label>';
echo '          <span id="scormStatus" class="status-badge">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Completion:</label>';
echo '          <span id="scormCompletion" class="completion-badge">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Score:</label>';
echo '          <span id="scormScore">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Total Time:</label>';
echo '          <span id="scormTotalTime">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Last Session:</label>';
echo '          <span id="scormSessionTime">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Entry Type:</label>';
echo '          <span id="scormEntry">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Exit Type:</label>';
echo '          <span id="scormExit">-</span>';
echo '        </div>';
echo '        <div class="scorm-detail-item">';
echo '          <label>Last Accessed:</label>';
echo '          <span id="scormLastAccessed">-</span>';
echo '        </div>';
echo '      </div>';
echo '    </div>';
echo '    <div class="scorm-modal-footer">';
echo '      <button onclick="closeScormModal()" class="scorm-modal-btn">Close</button>';
echo '    </div>';
echo '  </div>';
echo '</div>';

// Add SCORM tracking data to JavaScript
echo '<script>';
echo 'var scormTrackingData = ' . json_encode($scormtracking) . ';';
echo 'var scormByUserScorm = ' . json_encode($scorm_by_user_scorm) . ';';
echo 'var gradeitemToScorm = ' . json_encode($gradeitem_to_scormid) . ';';
echo 'console.log("SCORM Tracking Data:", scormTrackingData);';
echo 'console.log("SCORM By User Scorm:", scormByUserScorm);';
echo '</script>';

// Sidebar toggle and client-side search/filter behavior.
echo <<<'JS'
<script>
function toggleTeacherSidebar(){const s=document.querySelector(".teacher-sidebar");if(s){s.classList.toggle("sidebar-open");}}

// Manual Grading Functions
function showGradingModal(userId, itemId, studentName, activityName, maxPoints, currentGrade) {
  // Update modal content
  document.getElementById('gradingStudentName').textContent = studentName;
  document.getElementById('gradingActivityName').textContent = activityName;
  document.getElementById('gradingMaxPoints').textContent = maxPoints;
  document.getElementById('gradingCurrentGrade').textContent = currentGrade || 'No grade';
  
  // Set current grade as placeholder in input
  const input = document.getElementById('gradingInput');
  input.value = currentGrade || '';
  input.max = maxPoints;
  input.placeholder = 'Enter grade (max: ' + maxPoints + ')';
  
  // Store data for saving
  window.currentGradingData = {
    userId: userId,
    itemId: itemId,
    studentName: studentName,
    activityName: activityName,
    maxPoints: maxPoints
  };
  
  // Show modal
  document.getElementById('gradingModal').style.display = 'flex';
}

function closeGradingModal() {
  document.getElementById('gradingModal').style.display = 'none';
  window.currentGradingData = null;
}

function saveManualGrade() {
  const input = document.getElementById('gradingInput');
  const grade = parseFloat(input.value);
  const maxPoints = window.currentGradingData.maxPoints;
  
  if (isNaN(grade) || grade < 0) {
    alert('Please enter a valid grade (0 or higher)');
    return;
  }
  
  if (grade > maxPoints) {
    alert('Grade cannot exceed maximum points (' + maxPoints + ')');
    return;
  }
  
  // Send AJAX request to save grade
  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'save_manual_grade.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          if (response.success) {
            alert('Grade saved successfully!');
            closeGradingModal();
            location.reload(); // Refresh to show updated grade
          } else {
            alert('Error saving grade: ' + response.message);
          }
        } catch (e) {
          alert('Error processing response');
        }
      } else {
        alert('Error saving grade. Please try again.');
      }
    }
  };
  
  const data = 'userid=' + window.currentGradingData.userId + 
               '&itemid=' + window.currentGradingData.itemId + 
               '&grade=' + grade;
  xhr.send(data);
}

function clearManualGrade() {
  if (confirm('Are you sure you want to clear this grade?')) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'save_manual_grade.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.success) {
              alert('Grade cleared successfully!');
              closeGradingModal();
              location.reload();
            } else {
              alert('Error clearing grade: ' + response.message);
            }
          } catch (e) {
            alert('Error processing response');
          }
        } else {
          alert('Error clearing grade. Please try again.');
        }
      }
    };
    
    const data = 'userid=' + window.currentGradingData.userId + 
                 '&itemid=' + window.currentGradingData.itemId + 
                 '&grade=';
    xhr.send(data);
  }
}

// SCORM Modal Functions
function showScormDetails(userId, itemId, studentName, activityName) {
  const key = userId + ':' + itemId;
  let data = scormTrackingData[key];

  // Fallback lookup if primary key fails
  if (!data) {
    const scormId = (typeof gradeitemToScorm !== 'undefined' && gradeitemToScorm[itemId]) ? gradeitemToScorm[itemId] : null;
    if (scormId) {
      const altKey = userId + ':' + scormId;
      data = (typeof scormByUserScorm !== 'undefined') ? scormByUserScorm[altKey] : null;
    }
  }
  if (!data) data = {}; // default fallback

  // Update modal content
  document.getElementById('scormStudentName').textContent = studentName;
  document.getElementById('scormActivityName').textContent = activityName;
  
  // Status - use lesson_status
  const statusEl = document.getElementById('scormStatus');
  const status = data.status || 'not_attempted';
  const hasAttempted = data.has_attempted || false;
  
  // If student has attempted but status is not_attempted, show as attempted
  let displayStatus = status;
  if (status === 'not_attempted' && hasAttempted) {
    displayStatus = 'attempted';
  }
  
  statusEl.textContent = displayStatus.replace('_', ' ');
  statusEl.className = 'status-badge ' + displayStatus;
  
  // Completion - use completion_status, fallback to lesson_status
  const completionEl = document.getElementById('scormCompletion');
  let completion = data.completion || 'not_attempted';
  
  // If completion is not_attempted but student has attempted, show as attempted
  if (completion === 'not_attempted' && hasAttempted) {
    completion = 'attempted';
  } else if (completion === 'not_attempted' && status !== 'not_attempted') {
    completion = status;
  }
  
  completionEl.textContent = completion.replace('_', ' ');
  completionEl.className = 'completion-badge ' + completion;
  
  // Score - handle different score formats
  const scoreEl = document.getElementById('scormScore');
  if (data.score && data.score !== '') {
    // If score is already in percentage format or has a slash, use as-is
    if (data.score.toString().includes('/') || data.score.toString().includes('%')) {
      scoreEl.textContent = data.score;
    } else {
      // Assume raw score, display as-is
      scoreEl.textContent = data.score;
    }
  } else {
    scoreEl.textContent = 'No score';
  }
  
  // Time tracking - debug what we're getting
  console.log('SCORM data for time tracking:', data);
  document.getElementById('scormTotalTime').textContent = data.total_time || '00:00:00';
  document.getElementById('scormSessionTime').textContent = data.session_time || '00:00:00';
  
  // Entry/Exit
  document.getElementById('scormEntry').textContent = data.entry || 'ab-initio';
  document.getElementById('scormExit').textContent = data.exit || 'Not exited';
  
  // Last accessed
  const lastAccessed = data.last_accessed ? new Date(data.last_accessed * 1000).toLocaleString() : 'Never';
  document.getElementById('scormLastAccessed').textContent = lastAccessed;
  
  // Show modal
  document.getElementById('scormModal').style.display = 'flex';
}

function closeScormModal() {
  document.getElementById('scormModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
  const scormModal = document.getElementById('scormModal');
  const gradingModal = document.getElementById('gradingModal');
  
  if (event.target === scormModal) {
    closeScormModal();
  }
  if (event.target === gradingModal) {
    closeGradingModal();
  }
}

document.addEventListener("DOMContentLoaded",function(){
  console.log("Gradebook: Initializing search and filters...");
  const searchInput=document.getElementById("gbSearch");
const filterBtns=document.querySelectorAll(".filter-chip");
const rows=[...document.querySelectorAll(".gradebook-table tbody tr")];
  let activeFilter="all";
  
  console.log("Gradebook: Found search input:", searchInput);
  console.log("Gradebook: Found filter buttons:", filterBtns.length);
  console.log("Gradebook: Found table rows:", rows.length);
  
  function apply(){
    const q=(searchInput?searchInput.value.toLowerCase():"")||"";
    console.log("Gradebook: Applying filter - query:", q, "activeFilter:", activeFilter);
    rows.forEach(row=>{
      const name=(row.querySelector(".student-name")?.innerText||"").toLowerCase();
      const email=(row.querySelector(".student-email")?.innerText||"").toLowerCase();
      const hasGraded=row.classList.contains("has-graded");
      const hasMissing=row.classList.contains("has-missing");
      const hasAttempted=row.classList.contains("has-attempted");
      let ok=true;
      if(q && !(name.includes(q)||email.includes(q))) ok=false;
      if(activeFilter==='graded' && !hasGraded) ok=false;
      if(activeFilter==='attempted' && !hasAttempted) ok=false;
      if(activeFilter==='missing' && !hasMissing) ok=false;
      row.style.display= ok? '' : 'none';
    });
  }
  if(searchInput){searchInput.addEventListener("input",apply);} 
  filterBtns.forEach(btn=>btn.addEventListener("click",function(){
    filterBtns.forEach(b=>b.classList.remove("active"));
    this.classList.add("active");
    activeFilter=this.dataset.filter||"all";
    console.log("Gradebook: Filter clicked:", activeFilter);
    apply();
  }));
});
</script>
JS;

// ===== TEACHER SUPPORT/HELP BUTTON FUNCTIONALITY =====
if ($has_help_videos):
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const helpButton = document.getElementById('teacherHelpButton');
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
</script>
<?php endif; 

echo $OUTPUT->footer();