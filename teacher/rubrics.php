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
 * Teacher Rubrics page - custom UI for managing assessment rubrics
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();
$context = context_system::instance();

// Restrict to teachers/admins.
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher rubrics page');
}

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/rubrics.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Rubrics');
$PAGE->add_body_class('rubrics-page');

// Breadcrumb.
$PAGE->navbar->add('Rubrics');

// Teacher courses.
$teachercourses = enrol_get_my_courses('id, fullname, shortname', 'visible DESC, sortorder ASC');

// Get course filter parameter
$currentcourseid = optional_param('courseid', 0, PARAM_INT);

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

// Get rubrics data
$rubrics = theme_remui_kids_get_teacher_rubrics($USER->id, $currentcourseid);

// Debugging output to browser console
echo '<script>console.log("Teacher Rubrics Debug: selectedCourseId=' . (int)$currentcourseid . '");</script>';
if (!empty($teachercourses)) {
    foreach ($teachercourses as $cdbg) {
        echo '<script>console.log("Course ID: ' . (int)$cdbg->id . ' - ' . addslashes($cdbg->shortname) . ' - ' . addslashes($cdbg->fullname) . '");</script>';
    }
}
echo '<script>console.log("Rubrics count: ' . count($rubrics) . '");</script>';

// Output start.
echo $OUTPUT->header();

// Add CSS to remove the default main container
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

/* Override global stat card styles - Rubrics Page Only */
.teacher-css-wrapper .stat-card::before,
.teacher-css-wrapper .stat-card::after,
.teacher-dashboard-wrapper .stat-card::before,
.teacher-dashboard-wrapper .stat-card::after {
    display: none !important;
    content: none !important;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: #ffffff !important;
    border-radius: 16px !important;
    padding: 1.5rem !important;
    padding-top: 1.5rem !important;
    padding-bottom: 1.25rem !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06) !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    position: relative !important;
    overflow: hidden !important;
    display: block !important;
    border: none !important;
}

.stat-card:hover {
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1), 0 6px 12px rgba(0, 0, 0, 0.08) !important;
    transform: translateY(-4px) !important;
}

.stat-card-header {
    display: block !important;
    margin-bottom: 0.75rem !important;
}

.stat-title {
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: 0.8px !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    max-width: calc(100% - 56px) !important;
    line-height: 1.4 !important;
    padding-right: 8px !important;
    display: block !important;
}

.stat-icon-wrapper {
    width: 48px !important;
    height: 48px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    position: absolute !important;
    top: 1.5rem !important;
    right: 1.5rem !important;
    margin: 0 !important;
    z-index: 5 !important;
}

.stat-card:hover .stat-icon-wrapper {
    transform: scale(1.05) !important;
}

.stat-icon-wrapper i {
    font-size: 22px !important;
    color: #ffffff !important;
}

.stat-icon-purple,
.stat-icon-green,
.stat-icon-blue,
.stat-icon-orange {
    background: #3b82f6 !important;
}

.stat-value {
    font-size: 42px !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    line-height: 1 !important;
    margin: 0.5rem 0 0.25rem 0 !important;
    letter-spacing: -0.02em !important;
}

.stat-label,
.stat-description {
    font-size: 13.5px !important;
    color: #64748b !important;
    line-height: 1.5 !important;
    margin-bottom: 0.75rem !important;
    font-weight: 500 !important;
}

.stat-gradient-bar {
    position: absolute !important;
    bottom: 0 !important;
    left: 0 !important;
    right: 0 !important;
    height: 4px !important;
    border-radius: 0 0 16px 16px !important;
}

.stat-card-purple .stat-gradient-bar,
.stat-card-green .stat-gradient-bar,
.stat-card-blue .stat-gradient-bar,
.stat-card-orange .stat-gradient-bar {
    background: linear-gradient(90deg, #dbeafe 0%, #93c5fd 50%, #60a5fa 100%) !important;
}

/* Table Header - Blue Theme */
.students-table thead {
    background: #3b82f6 !important;
}

.students-table thead th {
    color: white !important;
    background: #3b82f6 !important;
}

/* Override any green colors from global styles */
.student-avatar {
    background: #3b82f6 !important;
}

.filter-btn {
    background: transparent !important;
    border: 1px solid #3b82f6 !important;
    color: #3b82f6 !important;
}

.filter-btn:hover {
    background: #3b82f6 !important;
    color: white !important;
}

/* Remove any green gradients */
*[style*="background: linear-gradient"][style*="#28a745"],
*[style*="background: linear-gradient"][style*="#20c997"],
*[style*="background: linear-gradient"][style*="#10b981"] {
    background: #3b82f6 !important;
}

/* Override view-toggle teal colors to blue */
.view-toggle {
    border-color: #dbeafe !important;
}

.toggle-option:hover {
    background: #eff6ff !important;
    color: #3b82f6 !important;
}

.view-toggle input[type="radio"]:checked + .toggle-option {
    background: #3b82f6 !important;
    color: white !important;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3) !important;
}

.view-toggle input[type="radio"]:checked + .toggle-option:hover {
    background: #2563eb !important;
}

@media (max-width: 768px) {
    .stats-grid {
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

echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

// Header
echo '<div class="students-page-header">';
echo '<div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">';
echo '<div>';
echo '<h1 class="students-page-title">Rubrics</h1>';
echo '<p class="students-page-subtitle">Create and manage assessment rubrics for your courses</p>';
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

// Course selector
echo '<div class="course-selector">';
echo '<div class="course-dropdown-wrapper">';
echo '<label for="rubricCourseSelect" class="course-dropdown-label">Select Course</label>';
echo '<select id="rubricCourseSelect" class="course-dropdown" onchange="window.location.href=this.value">';
echo '<option value="' . (new moodle_url('/theme/remui_kids/teacher/rubrics.php'))->out() . '">All Courses</option>';

foreach ($teachercourses as $course) {
    $selected = ($currentcourseid == $course->id) ? 'selected' : '';
    $url = new moodle_url('/theme/remui_kids/teacher/rubrics.php', array('courseid' => $course->id));
    echo '<option value="' . $url->out() . '" ' . $selected . '>' . $course->shortname . ' - ' . $course->fullname . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

// Statistics cards
$total_rubrics = count($rubrics);
$total_criteria = 0;
$total_submissions = 0;
$graded_submissions = 0;

foreach ($rubrics as $rubric) {
    $total_criteria += is_array($rubric['criteria']) ? count($rubric['criteria']) : 0;
    $total_submissions += $rubric['total_submissions'];
    $graded_submissions += $rubric['graded_submissions'];
}

$grading_progress = ($total_submissions > 0) ? round(min(100, ($graded_submissions / $total_submissions) * 100), 1) : 0;

echo '<div class="stats-grid">';

// Card 1: Total Rubrics
echo '<div class="stat-card stat-card-blue">';
echo '<div class="stat-card-header">';
echo '<span class="stat-title">TOTAL RUBRICS</span>';
echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-list-alt"></i></div>';
echo '</div>';
echo '<div class="stat-value">' . $total_rubrics . '</div>';
echo '<div class="stat-description">Assessment rubrics in your courses</div>';
echo '<div class="stat-gradient-bar"></div>';
echo '</div>';

// Card 2: Total Criteria
echo '<div class="stat-card stat-card-blue">';
echo '<div class="stat-card-header">';
echo '<span class="stat-title">TOTAL CRITERIA</span>';
echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-tasks"></i></div>';
echo '</div>';
echo '<div class="stat-value">' . $total_criteria . '</div>';
echo '<div class="stat-description">Grading criteria across rubrics</div>';
echo '<div class="stat-gradient-bar"></div>';
echo '</div>';

// Card 3: Total Submissions
echo '<div class="stat-card stat-card-blue">';
echo '<div class="stat-card-header">';
echo '<span class="stat-title">TOTAL SUBMISSIONS</span>';
echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-file-text"></i></div>';
echo '</div>';
echo '<div class="stat-value">' . $total_submissions . '</div>';
echo '<div class="stat-description">Assignments using rubrics</div>';
echo '<div class="stat-gradient-bar"></div>';
echo '</div>';

// Card 4: Grading Progress
echo '<div class="stat-card stat-card-blue">';
echo '<div class="stat-card-header">';
echo '<span class="stat-title">GRADING PROGRESS</span>';
echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-check-circle"></i></div>';
echo '</div>';
echo '<div class="stat-value">' . $grading_progress . '%</div>';
echo '<div class="stat-description">Submissions graded with rubrics</div>';
echo '<div class="stat-gradient-bar"></div>';
echo '</div>';

echo '</div>'; // stats-grid

// Rubrics list
echo '<div class="students-container">';

if (empty($rubrics)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-list-alt"></i></div>';
    echo '<h3 class="empty-state-title">No Rubrics Found</h3>';
    echo '<p class="empty-state-text">You don\'t have any assignments with rubrics yet. Create assignments and set their grading method to "Rubric" to see them here.</p>';
    echo '</div>';
} else {
    echo '<div class="students-table-wrapper">';
    echo '<table class="students-table">';
    echo '<thead><tr><th>Assignment</th><th>Course</th><th>Rubric Name</th><th>Criteria</th><th>Submissions</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($rubrics as $rubric) {
        $criteria_count = count($rubric['criteria']);
        $submission_text = $rubric['graded_submissions'] . ' / ' . $rubric['total_submissions'];
        
        // Determine avatar/icon based on activity type
        $activity_type = $rubric['activity_type'] ?? 'assign';
        if ($activity_type === 'codeeditor') {
            $avatar_html = '<div class="student-avatar" style="background: #3b82f6; color:white" title="Code Editor"><i class="fa fa-code"></i></div>';
            $activity_label = 'Code Editor';
        } else {
            $avatar_html = '<div class="student-avatar" style="background: #3b82f6; color:white" title="Assignment"><i class="fa fa-file-alt"></i></div>';
            $activity_label = 'Assignment';
        }
        
        echo '<tr>';
        echo '<td class="student-name">' . $avatar_html . format_string($rubric['assignment_name']) . '</td>';
        echo '<td class="student-email">' . format_string($rubric['course_name']) . '</td>';
        echo '<td>' . format_string($rubric['rubric_name']) . '</td>';
        echo '<td>' . $criteria_count . ' criteria</td>';
        echo '<td>' . $submission_text . '</td>';
        echo '<td>';
        echo '<div class="rubric-actions">';
        echo '<button class="rubric-action ghost" type="button" onclick="toggleRubric(\'' . $rubric['cmid'] . '\')"><i class="fa fa-eye"></i><span>View</span></button>';
        
        // Grade button - always links to rubric_grading.php for rubric-based grading
        $grading_url = new moodle_url('/theme/remui_kids/teacher/rubric_grading.php', [
            'assignmentid' => $rubric['assignment_id'],
            'courseid' => $rubric['course_id'],
            'activitytype' => $activity_type
        ]);
        echo '<a class="rubric-action primary" href="' . $grading_url->out() . '"><i class="fa fa-check-circle"></i><span>Grade</span></a>';
        echo '<a class="rubric-action link" href="' . $rubric['assignment_url'] . '"><i class="fa fa-external-link"></i><span>Open ' . $activity_label . '</span></a>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        // Expandable rubric row
        echo '<tr id="rubric-details-' . $rubric['cmid'] . '" class="rubric-details-row" style="display:none;">';
        echo '<td colspan="6">';
        echo '<div class="rubric-detail-card">';
        echo '<div class="rubric-header-line"><span class="pill">' . format_string($rubric['rubric_name']) . '</span></div>';

        // Build rubric table from criteria
        $maxlevels = 0; foreach ($rubric['criteria'] as $c) { $maxlevels = max($maxlevels, count($c['levels'])); }
        echo '<div class="rubric-table-wrapper"><table class="rubric-table">';
        echo '<thead><tr><th style="width:28%">Criterion</th>';
        for ($i = 0; $i < $maxlevels; $i++) { echo '<th>Level ' . ($i+1) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rubric['criteria'] as $criterion) {
            echo '<tr>';
            echo '<td>' . format_text($criterion['description'] ?? '', FORMAT_HTML) . '</td>';
            $levels = $criterion['levels'];
            for ($i = 0; $i < $maxlevels; $i++) {
                if (isset($levels[$i])) {
                    $lvl = $levels[$i];
                    $cell = '<div class="rubric-level-score"><strong>' . format_float($lvl->score ?? 0, 2) . '</strong></div>';
                    $cell .= '<div class="rubric-level-desc">' . format_text($lvl->definition ?? '', FORMAT_HTML) . '</div>';
                } else { $cell = '<span class="muted">—</span>'; }
                echo '<td>' . $cell . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table></div>';
}

echo '</div>'; // students-container

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

// Sidebar JS
echo '<script>
function toggleTeacherSidebar() {
  const sidebar = document.querySelector(".teacher-sidebar");
  sidebar.classList.toggle("sidebar-open");
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

// Styles + JS for dropdown rubric
echo '<style>
.rubric-detail-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px}
.rubric-table{width:100%;border-collapse:separate;border-spacing:0}
.rubric-table thead th{background:#f7f9fc;border-bottom:1px solid #e5e7eb;padding:10px;text-align:left}
.rubric-table td{border-bottom:1px solid #f0f2f5;padding:10px;vertical-align:top}
.muted{color:#9ca3af}
.student-avatar{width:40px;height:40px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-right:12px;font-size:16px;color:white;vertical-align:middle;box-shadow:none!important;filter:none!important;transition:none!important}
.student-avatar:hover{box-shadow:none!important;filter:none!important;transform:none!important}
.rubric-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.rubric-action{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;font-size:12px;font-weight:600;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#0f172a;text-decoration:none;cursor:pointer;transition:all .2s ease}
.rubric-action i{font-size:12px}
.rubric-action:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,23,42,.12)}
.rubric-action.primary{background:#e0f2ff;border-color:#c7d2fe;color:#1d4ed8}
.rubric-action.ghost{background:#fff;border-color:#e5e7eb;color:#334155}
.rubric-action.link{background:#fdf2f8;border-color:#fbcfe8;color:#9d174d}
.rubric-action.danger{background:#fee2e2;border-color:#fecaca;color:#b91c1c}
</style>';

echo '<script>
function toggleRubric(cmid){
  var row = document.getElementById("rubric-details-"+cmid);
  if(!row) return;
  row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
}
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
endif; ?>

echo $OUTPUT->footer();

