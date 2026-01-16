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
 * Teacher Competencies page - minimal UI to browse course competencies
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

require_login();
$context = context_system::instance();

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Restrict to teachers/admins.
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher competencies page');
}

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/competencies.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Competencies');
$PAGE->add_body_class('quizzes-page'); // Reuse page styling

// Breadcrumb.
$PAGE->navbar->add('Competencies');

// Teacher courses.
$teachercourses = enrol_get_my_courses('id, fullname, shortname', 'visible DESC, sortorder ASC');

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

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
.students-page-header.has-actions {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.5rem;
}
.students-page-header-left {
    display: flex;
    flex-direction: column;
    gap: .25rem;
}
.students-page-header-actions {
    flex-shrink: 0;
}
.teacher-dashboard-wrapper .search-box {
    position: relative;
    display: flex;
    align-items: center;
    background: #fff;
    border: 1px solid #dfe3ea;
    border-radius: 12px;
    padding: 0 16px;
    min-width: 320px;
    height: 48px;
}
.teacher-dashboard-wrapper .search-box .search-icon {
    position: absolute;
    left: 18px;
    top: 36%;
    transform: translateY(-50%);
    font-size: 16px;
    color: #94a3b8;
    pointer-events: none;
}
.teacher-dashboard-wrapper .search-box .search-input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font-size: 15px;
    color: #1f2937;
    padding-left: 28px;
}

/* Override global stat card styles - Competencies Page Only */
.teacher-css-wrapper .stat-card::before,
.teacher-css-wrapper .stat-card::after,
.teacher-dashboard-wrapper .stat-card::before,
.teacher-dashboard-wrapper .stat-card::after {
    display: none !important;
    content: none !important;
}

/* Override card-icon if it exists */
.teacher-css-wrapper .card-icon,
.teacher-dashboard-wrapper .card-icon {
    position: static !important;
    width: auto !important;
    height: auto !important;
}

/* Improved Stats Cards */
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

.teacher-help-video-player.active {
    display: block;
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

// Layout wrapper and sidebar (same as other teacher pages).
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

// Header
echo '<div class="students-page-header has-actions">';
echo '<div class="students-page-header-left">';
echo '<h1 class="students-page-title">Competencies</h1>';
echo '<p class="students-page-subtitle">Browse and manage competencies linked to your courses</p>';
echo '</div>';
echo '<div class="students-page-header-actions">';
if ($has_help_videos) {
    echo '<a class="teacher-help-button" id="teacherHelpButton" style="margin-right: 12px; text-decoration: none; display: inline-flex;">';
    echo '<i class="fa fa-question-circle"></i>';
    echo '<span>Need Help?</span>';
    echo '<span class="help-badge-count">' . $help_videos_count . '</span>';
    echo '</a>';
}
echo '<a class="filter-btn" href="' . new moodle_url('/theme/remui_kids/teacher/reports.php') . '"><i class="fa fa-chart-line"></i> Reports</a>';
echo '</div>';
echo '</div>';

// Overall stats across teacher courses
$courseids = array_keys($teachercourses);
if (!empty($courseids)) {
    list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

    // Total linked competencies across teacher's courses
    $totalcompetencies = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cc.competencyid)
           FROM {competency_coursecomp} cc
          WHERE cc.courseid $insql",
        $params
    );

    // Total course-competency links (can be > competencies due to reuse across courses)
    $totallinks = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {competency_coursecomp} cc
          WHERE cc.courseid $insql",
        $params
    );

    // Number of courses that have at least one competency
    $courseswithcomps = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cc.courseid)
           FROM {competency_coursecomp} cc
          WHERE cc.courseid $insql",
        $params
    );

    echo '<div class="stats-grid">';
    
    // Unique Competencies Card
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">UNIQUE COMPETENCIES</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-sitemap"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . (int)$totalcompetencies . '</div>';
    echo '<div class="stat-description">Distinct competencies across your courses</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Total Links Card
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">TOTAL LINKS</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-link"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . (int)$totallinks . '</div>';
    echo '<div class="stat-description">Course-competency connections</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Courses with Competencies Card
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">COURSES WITH COMPETENCIES</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-book"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . (int)$courseswithcomps . '</div>';
    echo '<div class="stat-description">Courses using competency framework</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Total Courses Card
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">TOTAL COURSES</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-graduation-cap"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . count($teachercourses) . '</div>';
    echo '<div class="stat-description">Total courses you\'re teaching</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    echo '</div>';
}

// Course selector
echo '<div class="course-selector">';
echo '<div class="course-dropdown-wrapper">';
echo '<label for="compCourseSelect" class="course-dropdown-label">Select Course</label>';
echo '<select id="compCourseSelect" class="course-dropdown" onchange="window.location.href=this.value">';
echo '<option value="">Choose a course...</option>';

$currentcourseid = optional_param('courseid', 0, PARAM_INT);
foreach ($teachercourses as $course) {
    $selected = ($currentcourseid == $course->id) ? 'selected' : '';
    $url = new moodle_url('/theme/remui_kids/teacher/competencies.php', array('courseid' => $course->id));
    echo '<option value="' . $url->out() . '" ' . $selected . '>'  . $course->fullname . '</option>';
}
echo '</select>';
echo '</div>';

// View Toggle Button
echo '<div class="view-toggle-wrapper">';
echo '<label class="view-toggle-label">View:</label>';
echo '<div class="view-toggle">';
echo '<input type="radio" id="view-competency" name="view-type" value="competency" checked>';
echo '<label for="view-competency" class="toggle-option">';
echo '<i class="fa fa-sitemap"></i>';
echo '<span>Competency First</span>';
echo '</label>';
echo '<input type="radio" id="view-student" name="view-type" value="student">';
echo '<label for="view-student" class="toggle-option">';
echo '<i class="fa fa-users"></i>';
echo '<span>Student First</span>';
echo '</label>';
echo '</div>';
echo '</div>';

echo '</div>';

// If course selected, show overview table for that course
if ($currentcourseid) {
    $course = get_course($currentcourseid);
    $coursecontext = context_course::instance($course->id);
    echo '<div class="students-container">';

    // Controls
    echo '<div class="students-controls">';
    echo '<div class="search-box">';
    echo '<i class="fa fa-search search-icon"></i>';
    echo '<input type="text" id="compSearch" class="search-input" placeholder="Search competencies..." onkeyup="filterComps()">';
    echo '</div>';
    echo '</div>';

    // Student First View Container
    echo '<div id="studentFirstView" class="view-content" style="display: none;">';
    
    // Get enrolled students - Filter by school and exclude admins
    $students = theme_remui_kids_get_course_students_by_school($currentcourseid, $teacher_company_id);
    
    // Filter out any admins that might have student role
    $students = theme_remui_kids_filter_out_admins($students, $currentcourseid);
    
    if (empty($students)) {
        echo '<div class="empty-state">';
        echo '<div class="empty-state-icon"><i class="fa fa-users"></i></div>';
        echo '<div class="empty-state-title">No Students Enrolled</div>';
        echo '<div class="empty-state-text">There are no enrolled students in this course.</div>';
        echo '</div>';
    } else {
        // Get all competencies linked to this course for progress calculation
        $linkedcompetencies = $DB->get_records_sql(
            "SELECT DISTINCT cc.competencyid
               FROM {competency_coursecomp} cc
              WHERE cc.courseid = ?",
            array($currentcourseid)
        );
        $totalcompetencies = count($linkedcompetencies);
        
        echo '<div class="students-table-wrapper">';
        echo '<table class="students-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Student</th>';
        echo '<th>Email</th>';
        echo '<th>Progress</th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($students as $student) {
            $fullname = $student->firstname . ' ' . $student->lastname;
            $initials = strtoupper(substr($student->firstname, 0, 1) . substr($student->lastname, 0, 1));
            
            // Calculate student's competency progress
            $proficientcount = 0;
            foreach ($linkedcompetencies as $comp) {
                $usercomp = $DB->get_record('competency_usercompcourse', array(
                    'userid' => $student->id,
                    'competencyid' => $comp->competencyid,
                    'courseid' => $currentcourseid
                ));
                
                // If not found in course table, check global table as fallback
                if (!$usercomp) {
                    $usercomp = $DB->get_record('competency_usercomp', array(
                        'userid' => $student->id,
                        'competencyid' => $comp->competencyid
                    ));
                }
                
                if ($usercomp && $usercomp->proficiency) {
                    $proficientcount++;
                }
            }
            
            echo '<tr>';
            echo '<td class="student-name">';
            echo '<div class="student-avatar">' . $initials . '</div>';
            echo '<span>' . s($fullname) . '</span>';
            echo '</td>';
            echo '<td class="student-email">' . s($student->email) . '</td>';
            echo '<td class="progress-cell">';
            echo '<div class="progress-info">';
            echo '<span class="progress-text">' . $proficientcount . ' / ' . $totalcompetencies . '</span>';
            echo '<div class="progress-bar">';
            $percentage = $totalcompetencies > 0 ? ($proficientcount / $totalcompetencies) * 100 : 0;
            echo '<div class="progress-fill" style="width: ' . round($percentage, 1) . '%"></div>';
            echo '</div>';
            echo '</div>';
            echo '</td>';
            echo '<td class="student-actions">';
            echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/student_competencies.php', array('userid' => $student->id, 'courseid' => $currentcourseid)) . '" class="filter-btn">';
            echo '<i class="fa fa-sitemap"></i> View Competencies';
            echo '</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
    
    echo '</div>'; // end studentFirstView

    // Competency First View Container
    echo '<div id="competencyFirstView" class="view-content">';

    // Fetch frameworks that have competencies linked in this course
    $frameworks = $DB->get_records_sql(
        "SELECT DISTINCT f.id, f.shortname, f.idnumber
           FROM {competency_coursecomp} cc
           JOIN {competency} c ON c.id = cc.competencyid
           JOIN {competency_framework} f ON f.id = c.competencyframeworkid
          WHERE cc.courseid = ?
       ORDER BY f.shortname ASC",
        array($currentcourseid)
    );

    // Fetch all competencies for those frameworks that are linked to this course
    $comps = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.shortname, c.idnumber, c.parentid, c.competencyframeworkid AS frameworkid
           FROM {competency_coursecomp} cc
           JOIN {competency} c ON c.id = cc.competencyid
          WHERE cc.courseid = ?
       ORDER BY c.sortorder, c.shortname",
        array($currentcourseid)
    );

    // Build hierarchy: framework -> parents -> children
    $byframework = array();
    foreach ($frameworks as $f) { $byframework[$f->id] = array('framework' => $f, 'nodes' => array(), 'children' => array()); }
    foreach ($comps as $c) {
        if (!isset($byframework[$c->frameworkid])) { continue; }
        $byframework[$c->frameworkid]['nodes'][$c->id] = $c;
        $byframework[$c->frameworkid]['children'][$c->parentid ?? 0][] = $c->id;
    }

    // Helper to count linked activities per competency
    $hasmodulecomp = $DB->get_manager()->table_exists('competency_modulecomp');
    $hasactivity = $DB->get_manager()->table_exists('competency_activity');
    $countlinked = function(int $competencyid) use ($DB, $currentcourseid, $hasmodulecomp, $hasactivity): int {
        if ($hasmodulecomp) {
            return (int)$DB->get_field_sql(
                "SELECT COUNT(1) FROM {competency_modulecomp} mc JOIN {course_modules} cm ON cm.id = mc.cmid WHERE mc.competencyid = ? AND cm.course = ?",
                array($competencyid, $currentcourseid)
            );
        }
        if ($hasactivity) {
            return (int)$DB->get_field_sql(
                "SELECT COUNT(1) FROM {competency_activity} ca JOIN {course_modules} cm ON cm.id = ca.cmid WHERE ca.competencyid = ? AND cm.course = ?",
                array($competencyid, $currentcourseid)
            );
        }
        return 0;
    };

    // Helper to count proficient students per competency
    $countproficient = function(int $competencyid) use ($DB, $currentcourseid, $coursecontext, $teacher_company_id): int {
        // Get enrolled students - Filter by school and exclude admins
        $students = theme_remui_kids_get_course_students_by_school($currentcourseid, $teacher_company_id);
        
        // Filter out any admins that might have student role
        $students = theme_remui_kids_filter_out_admins($students, $currentcourseid);
        
        if (empty($students)) {
            return 0;
        }
        
        $proficientcount = 0;
        foreach ($students as $student) {
            // Check course-specific proficiency first
            $usercomp = $DB->get_record('competency_usercompcourse', array(
                'userid' => $student->id,
                'competencyid' => $competencyid,
                'courseid' => $currentcourseid
            ));
            
            // If not found in course table, check global table as fallback
            if (!$usercomp) {
                $usercomp = $DB->get_record('competency_usercomp', array(
                    'userid' => $student->id,
                    'competencyid' => $competencyid
                ));
            }
            
            if ($usercomp && $usercomp->proficiency) {
                $proficientcount++;
            }
        }
        
        return $proficientcount;
    };

    // Enrolled student count once - Filter by school and exclude admins
    $filtered_students = theme_remui_kids_get_course_students_by_school($currentcourseid, $teacher_company_id);
    
    // Filter out any admins that might have student role
    $filtered_students = theme_remui_kids_filter_out_admins($filtered_students, $currentcourseid);
    
    $numstudents = count($filtered_students);

    // Render tree
    echo '<div id="compTree" class="comp-tree">';
    if (empty($frameworks)) {
        echo '<div class="empty-state">No competencies linked to this course yet.</div>';
    } else {
        foreach ($byframework as $fwid => $bundle) {
            $f = $bundle['framework'];
            echo '<div class="tree-framework">';
            echo '<div class="tree-header" onclick="toggleNode(this)"><span class="caret">▶</span> ' . format_string($f->shortname) . '</div>';
            echo '<ul class="tree-level" style="display:none">';

            // Render nodes recursively starting at parentid 0/null
            $render = function($parentid, $bundle, $render) use ($countlinked, $countproficient, $currentcourseid) {
                $children = $bundle['children'][$parentid ?? 0] ?? array();
                foreach ($children as $cid) {
                    $c = $bundle['nodes'][$cid];
                    $linked = $countlinked($c->id);
                    $proficient = $countproficient($c->id);
                    echo '<li class="tree-item">';
                    $hasgrand = !empty($bundle['children'][$c->id]);
                    echo '<div class="tree-row"' . ($hasgrand ? ' onclick="toggleNode(this)"' : '') . '>';
                    if ($hasgrand) { echo '<span class="caret">▶</span> '; }
                    echo '<span class="tree-name">' . format_string($c->shortname) . '</span>';
                    echo '<span class="tree-meta">' . $linked . ' activities · ' . $proficient . ' students</span>';
                    echo '<span class="tree-actions">';
                    echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/competency_details.php', array('competencyid' => $c->id, 'courseid' => $currentcourseid)) . '" class="filter-btn" title="View Details"><i class="fa fa-info-circle"></i> Details</a>';
                    echo '<a href="' . new moodle_url('/admin/tool/lp/coursecompetencies.php', array('courseid' => $currentcourseid)) . '" target="_blank" class="filter-btn" title="Manage Competency"><i class="fa fa-cog"></i> Manage</a>';
                    echo '</span>';
                    echo '</div>';
                    if ($hasgrand) {
                        echo '<ul class="tree-level" style="display:none">';
                        $render($c->id, $bundle, $render);
                        echo '</ul>';
                    }
                    echo '</li>';
                }
            };

            // Render top-level competencies (both parentid = 0 and parentid = null)
            $topLevelIds = array_merge(
                $bundle['children'][0] ?? array(),
                $bundle['children'][null] ?? array()
            );
            $topLevelIds = array_unique($topLevelIds); // Remove duplicates
            
            foreach ($topLevelIds as $cid) {
                $c = $bundle['nodes'][$cid];
                $linked = $countlinked($c->id);
                $proficient = $countproficient($c->id);
                echo '<li class="tree-item">';
                $hasgrand = !empty($bundle['children'][$c->id]);
                echo '<div class="tree-row"' . ($hasgrand ? ' onclick="toggleNode(this)"' : '') . '>';
                if ($hasgrand) { echo '<span class="caret">▶</span> '; }
                echo '<span class="tree-name">' . format_string($c->shortname) . '</span>';
                echo '<span class="tree-meta">' . $linked . ' activities · ' . $proficient . ' students</span>';
                echo '<span class="tree-actions">';
                echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/competency_details.php', array('competencyid' => $c->id, 'courseid' => $currentcourseid)) . '" class="filter-btn" title="View Details"><i class="fa fa-info-circle"></i> Details</a>';
                // echo '<a href="' . new moodle_url('/admin/tool/lp/coursecompetencies.php', array('courseid' => $currentcourseid)) . '" target="_blank" class="filter-btn" title="Manage Competency"><i class="fa fa-cog"></i> Manage</a>';
                echo '</span>';
                echo '</div>';
                if ($hasgrand) {
                    echo '<ul class="tree-level" style="display:none">';
                    $render($c->id, $bundle, $render);
                    echo '</ul>';
                }
                echo '</li>';
            }

            echo '</ul>';
            echo '</div>';
        }
    }
    echo '</div>';

    echo '</div>'; // end competencyFirstView
    echo '</div>';
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

// Sidebar + page JS
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

function filterComps() {
  const term = (document.getElementById("compSearch")?.value || "").toLowerCase();
  const frameworks = document.querySelectorAll("#compTree .tree-framework");
  
  if (!term) {
    // Show all items when search is empty
    frameworks.forEach(fw => fw.style.display = "");
    const items = document.querySelectorAll("#compTree .tree-item");
    items.forEach(item => item.style.display = "");
    return;
  }
  
  frameworks.forEach(function(framework) {
    const items = framework.querySelectorAll(".tree-item .tree-name");
    let hasMatches = false;
    
    items.forEach(function(span) {
      const row = span.closest(".tree-item");
      const txt = span.textContent.toLowerCase();
      const matches = txt.includes(term);
      
      if (matches) {
        hasMatches = true;
        row.style.display = "";
        // Show parent framework
        framework.style.display = "";
        // Expand parent levels to show this item
        let parent = row.closest(".tree-level");
        while (parent && parent !== framework) {
          parent.style.display = "block";
          const parentRow = parent.previousElementSibling;
          if (parentRow && parentRow.querySelector(".caret")) {
            parentRow.querySelector(".caret").textContent = "▼";
          }
          parent = parent.closest(".tree-level");
        }
      } else {
        row.style.display = "none";
      }
    });
    
    // Hide framework if no matches
    if (!hasMatches) {
      framework.style.display = "none";
    }
  });
}

function toggleNode(el) {
  const row = el.classList.contains("tree-row") ? el : el.nextElementSibling;
  const list = el.classList.contains("tree-row") ? el.nextElementSibling : el.parentElement.querySelector(".tree-level");
  if (!list) return;
  const caret = (el.querySelector && el.querySelector(".caret")) || (el.previousElementSibling && el.previousElementSibling.querySelector && el.previousElementSibling.querySelector(".caret"));
  const isOpen = list.style.display !== "none";
  list.style.display = isOpen ? "none" : "block";
  if (caret) caret.textContent = isOpen ? "▶" : "▼";
}

// View toggle functionality
function toggleView() {
  const competencyView = document.getElementById("competencyFirstView");
  const studentView = document.getElementById("studentFirstView");
  const competencyRadio = document.getElementById("view-competency");
  const studentRadio = document.getElementById("view-student");
  
  if (competencyRadio.checked) {
    competencyView.style.display = "block";
    studentView.style.display = "none";
    // Update search placeholder
    const searchInput = document.getElementById("compSearch");
    if (searchInput) {
      searchInput.placeholder = "Search competencies...";
    }
  } else if (studentRadio.checked) {
    competencyView.style.display = "none";
    studentView.style.display = "block";
    // Update search placeholder
    const searchInput = document.getElementById("compSearch");
    if (searchInput) {
      searchInput.placeholder = "Search students...";
    }
  }
}

// Add event listeners to radio buttons
document.addEventListener("DOMContentLoaded", function() {
  const competencyRadio = document.getElementById("view-competency");
  const studentRadio = document.getElementById("view-student");
  
  if (competencyRadio) {
    competencyRadio.addEventListener("change", toggleView);
  }
  if (studentRadio) {
    studentRadio.addEventListener("change", toggleView);
  }
  
  // Initialize view on page load
  toggleView();
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
    
    // Open modal
    if (helpButton) {
        helpButton.addEventListener('click', function() {
            if (helpModal) {
                helpModal.classList.add('active');
                document.body.style.overflow = 'hidden';
                loadTeacherHelpVideos();
            }
        });
    }
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            closeTeacherHelpModal();
        });
    }
    
    // Close on outside click
    if (helpModal) {
        helpModal.addEventListener('click', function(e) {
            if (e.target === helpModal) {
                closeTeacherHelpModal();
            }
        });
    }
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && helpModal && helpModal.classList.contains('active')) {
            closeTeacherHelpModal();
        }
    });
    
    // Back to list button
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
            
            // Remove any existing iframe
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
    
    // Remove any existing iframe
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

// Load help videos function
function loadTeacherHelpVideos() {
    const videosListContainer = document.getElementById('teacherHelpVideosList');
    
    if (!videosListContainer) return;
    
    // Show loading
    videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;"><i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>Loading help videos...</p>';
    
    // Fetch videos from plugin endpoint for 'teachers' category
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
                
                // Add click handlers to video items
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
    
    // Clear previous video
    videoPlayer.innerHTML = '';
    videoPlayer.src = '';
    
    // Remove any existing iframe
    const existingIframe = document.getElementById('teacherTempIframe');
    if (existingIframe) {
        existingIframe.remove();
    }
    
    if (videoType === 'youtube' || videoType === 'vimeo' || videoType === 'external') {
        // For external videos, use iframe
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
        // For uploaded videos, use HTML5 video player
        videoPlayer.style.display = 'block';
        videoPlayer.src = videoUrl;
        
        // Add captions if available
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
    
    // Show player, hide list
    if (videosListContainer) {
        videosListContainer.style.display = 'none';
    }
    videoPlayerContainer.style.display = 'block';
    
    // Record view
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


