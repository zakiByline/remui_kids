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
 * Teacher Quizzes page - custom minimal UI listing quizzes for a selected course
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

require_login();
$context = context_system::instance();

// Restrict to teachers/admins.
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher quizzes page');
}

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/quizzes.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Quizzes');
$PAGE->add_body_class('quizzes-page');

// Breadcrumb.
$PAGE->navbar->add('Quizzes');

// Teacher courses - filter to teacher's school/company.
$teachercourses = theme_remui_kids_get_teacher_school_courses(null, $teacher_company_id);

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

/* Override global stat card styles - Quizzes Page Only */
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

.stat-icon-green,
.stat-icon-blue,
.stat-icon-purple,
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

.stat-card-green .stat-gradient-bar,
.stat-card-blue .stat-gradient-bar,
.stat-card-purple .stat-gradient-bar,
.stat-card-orange .stat-gradient-bar {
    background: linear-gradient(90deg, #dbeafe 0%, #93c5fd 50%, #60a5fa 100%) !important;
}

/* Page Header with Create Button */
.students-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}

/* Create Quiz Button */
.btn-create-quiz {
    background: #3b82f6;
    color: white;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    white-space: nowrap;
}

.btn-create-quiz:hover {
    background: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    color: white;
    text-decoration: none;
}

.btn-create-quiz i {
    font-size: 14px;
}

/* Edit Button Styling */
.filter-btn-edit {
    background: #3b82f6 !important;
    color: white !important;
    border: none !important;
}

.filter-btn-edit:hover {
    background: #2563eb !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(59, 130, 246, 0.4);
}

.filter-btn-edit i {
    margin-right: 4px;
    font-size: 12px;
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

/* Quiz Search Bar Styling */
.quiz-search-wrapper {
    display: flex;
    flex-direction: column;
}

.quiz-search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.quiz-search-input {
    width: 100%;
    padding: 12px 45px 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    color: #1f2937;
    background: #ffffff;
    transition: all 0.3s ease;
    outline: none;
}

.quiz-search-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.quiz-search-input::placeholder {
    color: #9ca3af;
}

.quiz-search-icon {
    position: absolute;
    right: 16px;
    color: #6b7280;
    font-size: 16px;
    pointer-events: none;
}

.quiz-search-input:focus + .quiz-search-icon {
    color: #3b82f6;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .course-selector {
        flex-direction: column !important;
        gap: 15px !important;
    }
    
    .course-dropdown-wrapper,
    .quiz-search-wrapper {
        flex: none !important;
        width: 100% !important;
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
.teacher-help-modal {
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

.teacher-help-modal.active {
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

.teacher-back-to-list-btn {
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

.teacher-back-to-list-btn:hover {
    background: #5568d3;
    transform: translateX(-3px);
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
// Layout wrapper and sidebar (same as students page).
echo '<div class="teacher-dashboard-wrapper">';
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

// Header
echo '<div class="students-page-header">';
echo '<div>';
echo '<h1 class="students-page-title">Quizzes</h1>';
echo '<p class="students-page-subtitle">Browse and manage quizzes in your courses</p>';
echo '</div>';
echo '<div style="display: flex; align-items: center; gap: 12px;">';
if ($has_help_videos) {
    echo '<a class="teacher-help-button" id="teacherHelpButton" style="text-decoration: none; display: inline-flex;">';
    echo '<i class="fa fa-question-circle"></i>';
    echo '<span>Need Help?</span>';
    echo '<span class="help-badge-count">' . $help_videos_count . '</span>';
    echo '</a>';
}
echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/create_quiz_page.php" class="btn-create-quiz">';
echo '<i class="fa fa-plus"></i> Create Quiz';
echo '</a>';
echo '</div>';
echo '</div>';

// Calculate overall statistics across all teacher's courses
$courseids = array_keys($teachercourses);
if (!empty($courseids)) {
    list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    
    // Total quizzes
    $totalquizzes = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT q.id) 
         FROM {quiz} q 
         JOIN {course_modules} cm ON cm.instance = q.id 
         JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
         WHERE q.course $insql
         AND cm.deletioninprogress = 0",
        $params
    );
    
    // Get all quiz IDs for this teacher's courses
    $quizids = $DB->get_fieldset_sql(
        "SELECT DISTINCT q.id 
         FROM {quiz} q 
         JOIN {course_modules} cm ON cm.instance = q.id 
         JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
         WHERE q.course $insql
         AND cm.deletioninprogress = 0",
        $params
    );
    
    $activestudents = 0;
    $totalattempts = 0;
    $finishedattempts = 0;
    
    if (!empty($quizids)) {
        list($quizinsql, $quizparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED);
        
        // Active students (unique students who have attempted any quiz) - Filter by school
        if ($teacher_company_id) {
            $activestudents = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT qa.userid) 
                 FROM {quiz_attempts} qa
                 JOIN {company_users} cu ON cu.userid = qa.userid AND cu.companyid = :companyid
                 WHERE qa.quiz $quizinsql",
                array_merge($quizparams, ['companyid' => $teacher_company_id])
            );
            
            // Total attempts - Filter by school
            $totalattempts = $DB->count_records_sql(
                "SELECT COUNT(*) 
                 FROM {quiz_attempts} qa
                 JOIN {company_users} cu ON cu.userid = qa.userid AND cu.companyid = :companyid2
                 WHERE qa.quiz $quizinsql",
                array_merge($quizparams, ['companyid2' => $teacher_company_id])
            );
        } else {
            // No company filter
        $activestudents = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) 
             FROM {quiz_attempts} 
             WHERE quiz $quizinsql",
            $quizparams
        );
        
        // Total attempts
        $totalattempts = $DB->count_records_sql(
            "SELECT COUNT(*) 
             FROM {quiz_attempts} 
             WHERE quiz $quizinsql",
            $quizparams
        );
        }
        
        // Finished attempts for completion rate - Filter by school
        if ($teacher_company_id) {
            $finishedattempts = $DB->count_records_sql(
                "SELECT COUNT(*) 
                 FROM {quiz_attempts} qa
                 JOIN {company_users} cu ON cu.userid = qa.userid AND cu.companyid = :companyid3
                 WHERE qa.quiz $quizinsql AND qa.state = 'finished'",
                array_merge($quizparams, ['companyid3' => $teacher_company_id])
            );
        } else {
        $finishedattempts = $DB->count_records_sql(
            "SELECT COUNT(*) 
             FROM {quiz_attempts} 
             WHERE quiz $quizinsql AND state = 'finished'",
            $quizparams
        );
        }
    }
    
    $completionrate = ($totalattempts > 0) ? round(($finishedattempts / $totalattempts) * 100, 1) : 0;
    
    // Display statistics cards
    echo '<div class="stats-grid">';
    
    // Card 1: Total Quizzes
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">TOTAL QUIZZES</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-question-circle"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . $totalquizzes . '</div>';
    echo '<div class="stat-description">Quizzes across all your courses</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Card 2: Active Students
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">ACTIVE STUDENTS</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-users"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . $activestudents . '</div>';
    echo '<div class="stat-description">Students who attempted quizzes</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Card 3: Total Attempts
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">TOTAL ATTEMPTS</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-edit"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . $totalattempts . '</div>';
    echo '<div class="stat-description">All quiz attempts submitted</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    // Card 4: Completion Rate
    echo '<div class="stat-card stat-card-blue">';
    echo '<div class="stat-card-header">';
    echo '<span class="stat-title">COMPLETION RATE</span>';
    echo '<div class="stat-icon-wrapper stat-icon-blue"><i class="fa fa-check-circle"></i></div>';
    echo '</div>';
    echo '<div class="stat-value">' . $completionrate . '%</div>';
    echo '<div class="stat-description">Finished vs total attempts</div>';
    echo '<div class="stat-gradient-bar"></div>';
    echo '</div>';
    
    echo '</div>'; // stats-grid
}

// Course selector and Search bar
echo '<div class="course-selector" style="display: flex; gap: 20px; align-items: flex-end; margin-bottom: 1.5rem;">';
echo '<div class="course-dropdown-wrapper" style="flex: 1;">';
echo '<label for="quizCourseSelect" class="course-dropdown-label">Select Course</label>';
echo '<select id="quizCourseSelect" class="course-dropdown" onchange="window.location.href=this.value">';
echo '<option value="">Choose a course...</option>';

$currentcourseid = optional_param('courseid', 0, PARAM_INT);
foreach ($teachercourses as $course) {
    $selected = ($currentcourseid == $course->id) ? 'selected' : '';
    $url = new moodle_url('/theme/remui_kids/teacher/quizzes.php', array('courseid' => $course->id));
    echo '<option value="' . $url->out() . '" ' . $selected . '>' . $course->fullname . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="quiz-search-wrapper" style="flex: 1;">';
echo '<label for="quizSearchInput" class="course-dropdown-label">Search Quizzes</label>';
echo '<div class="quiz-search-input-wrapper">';
echo '<input type="text" id="quizSearchInput" class="quiz-search-input" placeholder="Search by quiz name or course...">';
echo '<i class="fa fa-search quiz-search-icon"></i>';
echo '</div>';
echo '</div>';
echo '</div>';

// List quizzes - show all if no course selected, or filtered by course if selected
$quizzes = array();
$coursesmap = array(); // Store course info for each quiz

if ($currentcourseid) {
    // Show quizzes from selected course only
    $course = get_course($currentcourseid);
    $modinfo = get_fast_modinfo($course);
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname === 'quiz' && $cm->uservisible && empty($cm->deletioninprogress)) {
            $quizzes[] = $cm;
            $coursesmap[$cm->id] = $course; // Store course info
        }
    }
} else {
    // Show all quizzes from all teacher courses
    foreach ($teachercourses as $course) {
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'quiz' && $cm->uservisible && empty($cm->deletioninprogress)) {
                $quizzes[] = $cm;
                $coursesmap[$cm->id] = $course; // Store course info for each quiz
            }
        }
    }
}

echo '<div class="students-container">';

if (empty($quizzes)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-question-circle"></i></div>';
    echo '<h3 class="empty-state-title">No Quizzes Found</h3>';
    if ($currentcourseid) {
        echo '<p class="empty-state-text">This course does not have any quizzes yet.</p>';
    } else {
        echo '<p class="empty-state-text">You do not have any quizzes in your courses yet.</p>';
    }
    echo '</div>';
} else {
    echo '<div class="students-table-wrapper">';
    echo '<table class="students-table">';
    echo '<thead><tr><th>Quiz</th><th>Course</th><th>Attempts</th><th>Avg Grade</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    foreach ($quizzes as $cm) {
        $quizid = $cm->instance; // quiz table id
        $quiz = $DB->get_record('quiz', array('id' => $quizid), 'id,sumgrades,grade,grademethod,name');
        $attemptcount = (int)$DB->get_field_sql('SELECT COUNT(1) FROM {quiz_attempts} WHERE quiz = ? AND state = ?', [ $quizid, 'finished' ]);

        // Respect quiz grading method by using {quiz_grades} which already stores per-user final grades.
        $avggrade = $DB->get_field_sql('SELECT AVG(grade) FROM {quiz_grades} WHERE quiz = ?', [ $quizid ]);
        $avgdisplay = '-';
        if ($avggrade !== false && $avggrade !== null) {
            $avgdisplay = format_float((float)$avggrade, 2) . ' / ' . format_float((float)$quiz->grade, 2);
        }

        $quizurl = new moodle_url('/mod/quiz/view.php', array('id' => $cm->id));
        $attemptsurl = new moodle_url('/theme/remui_kids/teacher/quiz_attempts.php', array('quizid' => $quizid));
        
        // Get course info for this quiz
        $quizcourse = isset($coursesmap[$cm->id]) ? $coursesmap[$cm->id] : null;
        $quizname = format_string($cm->name);
        $coursename = $quizcourse ? format_string($quizcourse->fullname) : '-';

        echo '<tr class="quiz-row" data-quiz-name="' . htmlspecialchars(strtolower($quizname), ENT_QUOTES, 'UTF-8') . '" data-course-name="' . htmlspecialchars(strtolower($coursename), ENT_QUOTES, 'UTF-8') . '">';
        echo '<td class="student-name"><div class="student-avatar">QZ</div>' . $quizname . '</td>';
        if ($quizcourse) {
            echo '<td class="student-email">' . $coursename . '</td>';
        } else {
            echo '<td class="student-email">-</td>';
        }
        echo '<td>' . $attemptcount . '</td>';
        echo '<td>' . $avgdisplay . '</td>';
        $editurl = new moodle_url('/theme/remui_kids/teacher/edit_quiz_page.php', array('cmid' => $cm->id, 'quizid' => $quizid));
        
        echo '<td>';
        echo '<a class="filter-btn" href="' . $attemptsurl->out() . '">Attempts</a> ';
        echo '<a class="filter-btn filter-btn-edit" href="' . $editurl->out() . '"><i class="fa fa-edit"></i> Edit</a> ';
        echo '<a class="filter-btn" href="' . $quizurl->out() . '">Open</a>';
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

// Quiz Search Functionality
document.addEventListener("DOMContentLoaded", function() {
  const searchInput = document.getElementById("quizSearchInput");
  const quizRows = document.querySelectorAll(".quiz-row");
  const studentsTableWrapper = document.querySelector(".students-table-wrapper");
  
  if (!searchInput) return;
  
  searchInput.addEventListener("input", function() {
    const searchTerm = this.value.toLowerCase().trim();
    let visibleCount = 0;
    
    quizRows.forEach(function(row) {
      const quizName = row.getAttribute("data-quiz-name") || "";
      const courseName = row.getAttribute("data-course-name") || "";
      
      if (searchTerm === "" || quizName.includes(searchTerm) || courseName.includes(searchTerm)) {
        row.style.display = "";
        visibleCount++;
      } else {
        row.style.display = "none";
      }
    });
    
    // Show/hide table based on results
    if (studentsTableWrapper) {
      if (visibleCount === 0 && searchTerm !== "") {
        // Show "no results" message if search has no matches
        let noResultsMsg = studentsTableWrapper.querySelector(".no-results-message");
        if (!noResultsMsg) {
          noResultsMsg = document.createElement("div");
          noResultsMsg.className = "no-results-message";
          noResultsMsg.style.cssText = "text-align: center; padding: 40px 20px; color: #64748b;";
          noResultsMsg.innerHTML = "<i class=\"fa fa-search\" style=\"font-size: 48px; margin-bottom: 16px; opacity: 0.3;\"></i><h3 style=\"margin: 0 0 8px 0; color: #1f2937;\">No quizzes found</h3><p style=\"margin: 0;\">Try adjusting your search terms</p>";
          studentsTableWrapper.appendChild(noResultsMsg);
        }
        studentsTableWrapper.querySelector("table").style.display = "none";
        noResultsMsg.style.display = "block";
      } else {
        // Show table and hide "no results" message
        const noResultsMsg = studentsTableWrapper.querySelector(".no-results-message");
        if (noResultsMsg) {
          noResultsMsg.style.display = "none";
        }
        const table = studentsTableWrapper.querySelector("table");
        if (table) {
          table.style.display = "";
        }
      }
    }
  });
  
  // Clear search on page load if needed
  const urlParams = new URLSearchParams(window.location.search);
  if (!urlParams.has("search")) {
    searchInput.value = "";
  }
});
</script>';

// ===== TEACHER SUPPORT/HELP BUTTON FUNCTIONALITY =====
if ($has_help_videos) {
    echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const helpButton = document.getElementById("teacherHelpButton");
    const helpModal = document.getElementById("teacherHelpVideoModal");
    const closeModal = document.getElementById("closeTeacherHelpModal");
    
    // Open modal
    if (helpButton) {
        helpButton.addEventListener("click", function() {
            if (helpModal) {
                helpModal.classList.add("active");
                document.body.style.overflow = "hidden";
                loadTeacherHelpVideos();
            }
        });
    }
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener("click", function() {
            closeTeacherHelpModal();
        });
    }
    
    // Close on outside click
    if (helpModal) {
        helpModal.addEventListener("click", function(e) {
            if (e.target === helpModal) {
                closeTeacherHelpModal();
            }
        });
    }
    
    // Close on Escape key
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && helpModal && helpModal.classList.contains("active")) {
            closeTeacherHelpModal();
        }
    });
    
    // Back to list button
    const backToListBtn = document.getElementById("teacherBackToListBtn");
    if (backToListBtn) {
        backToListBtn.addEventListener("click", function() {
            const videosListContainer = document.querySelector(".teacher-help-videos-list");
            const videoPlayerContainer = document.querySelector(".teacher-help-video-player");
            const videoPlayer = document.getElementById("teacherHelpVideoPlayer");
            
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.currentTime = 0;
                videoPlayer.src = "";
            }
            
            if (videoPlayerContainer) {
                videoPlayerContainer.style.display = "none";
            }
            
            if (videosListContainer) {
                videosListContainer.style.display = "block";
            }
        });
    }
});

function closeTeacherHelpModal() {
    const helpModal = document.getElementById("teacherHelpVideoModal");
    const videoPlayer = document.getElementById("teacherHelpVideoPlayer");
    
    if (helpModal) {
        helpModal.classList.remove("active");
    }
    
    if (videoPlayer) {
        videoPlayer.pause();
        videoPlayer.currentTime = 0;
        videoPlayer.src = "";
    }
    
    document.body.style.overflow = "auto";
}

// Load help videos function
function loadTeacherHelpVideos() {
    const videosListContainer = document.querySelector(".teacher-help-videos-list");
    const videoPlayerContainer = document.querySelector(".teacher-help-video-player");
    
    if (!videosListContainer) return;
    
    // Show loading
    videosListContainer.innerHTML = "<p style=\"text-align: center; padding: 20px; color: #666;\"><i class=\"fa fa-spinner fa-spin\" style=\"font-size: 24px;\"></i><br>Loading help videos...</p>";
    
    // Fetch videos from plugin endpoint for "teachers" category
    fetch(M.cfg.wwwroot + "/local/support/get_videos.php?category=teachers")
        .then(response => response.json())
        .then(data => {
            console.log("Teacher Support Videos Response:", data);
            
            if (data.success && data.videos && data.videos.length > 0) {
                let html = "";
                data.videos.forEach(function(video) {
                    html += "<div class=\"teacher-help-video-item\" ";
                    html += "data-video-id=\"" + video.id + "\" ";
                    html += "data-video-url=\"" + escapeHtml(video.video_url) + "\" ";
                    html += "data-embed-url=\"" + escapeHtml(video.embed_url) + "\" ";
                    html += "data-video-type=\"" + video.videotype + "\" ";
                    html += "data-has-captions=\"" + video.has_captions + "\" ";
                    html += "data-caption-url=\"" + escapeHtml(video.caption_url) + "\">";
                    html += "  <h4><i class=\"fa fa-play-circle\"></i> " + escapeHtml(video.title) + "</h4>";
                    if (video.description) {
                        html += "  <p>" + escapeHtml(video.description) + "</p>";
                    }
                    if (video.duration) {
                        html += "  <small style=\"color: #999;\"><i class=\"fa fa-clock-o\"></i> " + escapeHtml(video.duration) + " &middot; <i class=\"fa fa-eye\"></i> " + video.views + " views</small>";
                    }
                    html += "</div>";
                });
                videosListContainer.innerHTML = html;
                
                // Add click handlers to video items
                document.querySelectorAll(".teacher-help-video-item").forEach(function(item) {
                    item.addEventListener("click", function() {
                        const videoId = this.getAttribute("data-video-id");
                        const videoUrl = this.getAttribute("data-video-url");
                        const embedUrl = this.getAttribute("data-embed-url");
                        const videoType = this.getAttribute("data-video-type");
                        const hasCaptions = this.getAttribute("data-has-captions") === "true";
                        const captionUrl = this.getAttribute("data-caption-url");
                        
                        playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl);
                    });
                });
            } else {
                videosListContainer.innerHTML = "<p style=\"text-align: center; padding: 20px; color: #666;\">No help videos available for teachers.</p>";
            }
        })
        .catch(error => {
            console.error("Error loading help videos:", error);
            videosListContainer.innerHTML = "<p style=\"text-align: center; padding: 20px; color: #d9534f;\">Error loading videos. Please try again.</p>";
        });
}

function playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl) {
    const videosListContainer = document.querySelector(".teacher-help-videos-list");
    const videoPlayerContainer = document.querySelector(".teacher-help-video-player");
    const videoPlayer = document.getElementById("teacherHelpVideoPlayer");
    
    if (!videoPlayerContainer || !videoPlayer) return;
    
    // Clear previous video
    videoPlayer.innerHTML = "";
    videoPlayer.src = "";
    
    // Remove any existing iframe
    const existingIframe = document.getElementById("teacherTempIframe");
    if (existingIframe) {
        existingIframe.remove();
    }
    
    if (videoType === "youtube" || videoType === "vimeo" || videoType === "external") {
        // For external videos, use iframe
        videoPlayer.style.display = "none";
        const iframe = document.createElement("iframe");
        iframe.src = embedUrl || videoUrl;
        iframe.width = "100%";
        iframe.style.height = "450px";
        iframe.style.borderRadius = "8px";
        iframe.frameBorder = "0";
        iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture";
        iframe.allowFullscreen = true;
        iframe.id = "teacherTempIframe";
        videoPlayer.parentNode.insertBefore(iframe, videoPlayer);
    } else {
        // For uploaded videos, use HTML5 video player
        videoPlayer.style.display = "block";
        videoPlayer.src = videoUrl;
        
        // Add captions if available
        if (hasCaptions && captionUrl) {
            const track = document.createElement("track");
            track.kind = "captions";
            track.src = captionUrl;
            track.srclang = "en";
            track.label = "English";
            track.default = true;
            videoPlayer.appendChild(track);
        }
        
        videoPlayer.load();
    }
    
    // Show player, hide list
    videosListContainer.style.display = "none";
    videoPlayerContainer.style.display = "block";
    
    // Record view
    fetch(M.cfg.wwwroot + "/local/support/record_view.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "videoid=" + videoId + "&sesskey=" + M.cfg.sesskey
    });
}

function escapeHtml(text) {
    const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "\"": "&quot;",
        "\'": "&#039;"
    };
    return text.replace(/[&<>"\']/g, m => map[m]);
}
</script>';

    // Modal HTML
    echo '<!-- Teacher Help/Support Video Modal -->';
    echo '<div id="teacherHelpVideoModal" class="teacher-help-modal">';
    echo '<div class="teacher-help-modal-content">';
    echo '<div class="teacher-help-modal-header">';
    echo '<h2><i class="fa fa-video"></i> Teacher Help Videos</h2>';
    echo '<button class="teacher-help-modal-close" id="closeTeacherHelpModal">&times;</button>';
    echo '</div>';
    echo '<div class="teacher-help-modal-body">';
    echo '<div class="teacher-help-videos-list">';
    echo '<p style="text-align: center; padding: 20px; color: #666;">';
    echo '<i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>';
    echo 'Loading help videos...';
    echo '</p>';
    echo '</div>';
    echo '<div class="teacher-help-video-player" style="display: none;">';
    echo '<button class="teacher-back-to-list-btn" id="teacherBackToListBtn">';
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

echo $OUTPUT->footer();


