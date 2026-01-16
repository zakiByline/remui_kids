<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

/**
 * Student Analytics Dashboard
 * Comprehensive analytics with vertical sections for Overview, Assignments, Quizzes, Competencies, and Rubrics
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();
$context = context_system::instance();

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access student reports');
}

$userid = required_param('userid', PARAM_INT);
$courseids = optional_param_array('courseid', [], PARAM_INT);

// Support both single courseid and multiple courseids
if (empty($courseids)) {
    $singleCourseid = optional_param('courseid', 0, PARAM_INT);
    if ($singleCourseid) {
        $courseids = [$singleCourseid];
    }
}

if (empty($courseids)) {
    throw new moodle_exception('missingparam', 'error', '', 'courseid');
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Get course info for display
$courses = [];
foreach ($courseids as $cid) {
    try {
        $courses[$cid] = get_course($cid);
    } catch (Exception $e) {
        continue;
    }
}

if (empty($courses)) {
    throw new moodle_exception('invalidcourse', 'error');
}

// Page setup
$PAGE->set_context($context);
$urlparams = ['userid' => $userid];
foreach ($courseids as $cid) {
    $urlparams['courseid[]'] = $cid;
}
$PAGE->set_url('/theme/remui_kids/teacher/student_report.php', $urlparams);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Student Analytics: ' . fullname($user));
$PAGE->add_body_class('student-analytics-page');

$firstCourseId = reset($courseids);
$PAGE->navbar->add('Students', new moodle_url('/theme/remui_kids/teacher/students.php', ['courseid' => $firstCourseId]));
$PAGE->navbar->add('Student Analytics');

// Load CSS
$PAGE->requires->css('/theme/remui_kids/style/student_report.css');

echo $OUTPUT->header();

// Add CSS to remove the default main container
echo '<style>
#region-main, [role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}
</style>';

// Layout wrapper and sidebar
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="student-report-wrapper">';

// Get student analytics data
$analytics = theme_remui_kids_get_student_analytics($userid, $courseids);

if (!$analytics) {
    throw new moodle_exception('nodata', 'error', '', 'No analytics data available');
}

// Header Section - Matching teacher reports style
echo '<div class="student-analytics-hero">';
echo '<div class="student-analytics-hero-header">';
echo '<div class="student-analytics-hero-copy">';
echo '<h1>Student Analytics Dashboard</h1>';
echo '<p>';
if (count($analytics['courses']) == 1) {
    echo format_string($analytics['courses'][0]['name']);
} else {
    echo count($analytics['courses']) . ' Courses Selected';
}
echo '</p>';
echo '</div>';
echo '<div class="student-analytics-hero-actions">';
echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/students.php', ['courseid' => $firstCourseId]) . '" class="back-button">';
echo '<i class="fa fa-arrow-left"></i> Back to Students';
echo '</a>';
echo '</div>';
echo '</div>';
echo '</div>';

// Course selector
if (count($analytics['courses']) > 1) {
    echo '<div class="course-selector-section">';
    echo '<label>Selected Courses: </label>';
    foreach ($analytics['courses'] as $course) {
        echo '<span class="course-badge">' . s($course['fullname']) . '</span>';
    }
    echo '</div>';
}

// ============ TAB NAVIGATION ============
echo '<div class="analytics-tabs">';
echo '<div class="tab-nav">';
echo '<button type="button" class="tab-button active" onclick="showAnalyticsTab(\'overview\')">';
echo '<i class="fa fa-dashboard"></i>';
echo 'Overview';
echo '</button>';
echo '<button type="button" class="tab-button" onclick="showAnalyticsTab(\'assignments\')">';
echo '<i class="fa fa-file-text-o"></i>';
echo 'Assignments';
echo '</button>';
echo '<button type="button" class="tab-button" onclick="showAnalyticsTab(\'quizzes\')">';
echo '<i class="fa fa-question-circle"></i>';
echo 'Quizzes';
echo '</button>';
echo '<button type="button" class="tab-button" onclick="showAnalyticsTab(\'competencies\')">';
echo '<i class="fa fa-trophy"></i>';
echo 'Competencies';
echo '</button>';
echo '<button type="button" class="tab-button" onclick="showAnalyticsTab(\'rubrics\')">';
echo '<i class="fa fa-list-ul"></i>';
echo 'Rubrics';
echo '</button>';
echo '</div>';
echo '</div>';

// ============ TAB CONTENT ============
echo '<div class="analytics-tab-content">';

// ============ OVERVIEW TAB ============
echo '<div id="overview-tab" class="tab-panel active">';

// Learning Archetype - Redesigned
echo '<div class="overview-section-card archetype-section">';
echo '<div class="archetype-card-new">';
$patternType = $analytics['learning_pattern']['type'];
$patternIcons = [
    'visual' => 'fa-eye',
    'auditory' => 'fa-headphones',
    'slow_learner' => 'fa-hourglass-half',
    'fast_learner' => 'fa-rocket',
    'balanced' => 'fa-balance-scale'
];
$patternIcon = $patternIcons[$patternType] ?? 'fa-user';
echo '<div class="archetype-icon-new purple">';
echo '<i class="fa ' . $patternIcon . '"></i>';
echo '</div>';
echo '<div class="archetype-info-new">';
echo '<div class="archetype-title-new">' . ucwords(str_replace('_', ' ', $patternType)) . '</div>';
echo '<div class="archetype-description-new">' . htmlspecialchars($analytics['learning_pattern']['description']) . '</div>';
echo '</div>';
echo '</div>';
echo '</div>'; // archetype-section

// Stat cards - 4 cards horizontally
echo '<div class="overview-section-card stats-section">';
echo '<div class="stat-card-grid-new">';

echo '<div class="stat-card-new">';
echo '<div class="stat-icon-new blue"><i class="fa fa-file-text-o"></i></div>';
echo '<div class="stat-content-new">';
echo '<div class="stat-label-new">ASSIGNMENT AVERAGE</div>';
echo '<div class="stat-value-new">' . round($analytics['grades']['assignment_avg'], 1) . '%</div>';
echo '<div class="stat-details-new">';
echo '<span>Class Avg: ' . round($analytics['grades']['assignment_class_avg'], 1) . '%</span>';
// Find first pending assignment for deadline
$firstDeadline = null;
if (!empty($analytics['assignments_detail'])) {
    foreach ($analytics['assignments_detail'] as $assign) {
        if ($assign['status'] === 'pending' && $assign['duedate']) {
            $firstDeadline = $assign['duedate'];
            break;
        }
    }
}
if ($firstDeadline) {
    echo '<span>' . $firstDeadline . ' is the first deadline</span>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card-new">';
echo '<div class="stat-icon-new purple"><i class="fa fa-bullseye"></i></div>';
echo '<div class="stat-content-new">';
echo '<div class="stat-label-new">QUIZ AVERAGE</div>';
echo '<div class="stat-value-new">' . round($analytics['grades']['quiz_avg'], 1) . '%</div>';
echo '<div class="stat-details-new">';
echo '<span>Class Avg: ' . round($analytics['grades']['quiz_class_avg'], 1) . '%</span>';
if ($analytics['grades']['quiz_avg'] >= 80) {
    echo '<span>Excelling in all quizzes</span>';
} elseif ($analytics['grades']['quiz_avg'] >= 60) {
    echo '<span>Good performance</span>';
} else {
    echo '<span>Needs improvement</span>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card-new">';
echo '<div class="stat-icon-new orange"><i class="fa fa-clock-o"></i></div>';
echo '<div class="stat-content-new">';
echo '<div class="stat-label-new">LAST ACCESSED</div>';
echo '<div class="stat-value-new">' . htmlspecialchars($analytics['last_accessed']['display']) . '</div>';
echo '<div class="stat-details-new">';
$today = date('M j');
echo '<span>' . $today . ' is shown as seen</span>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card-new">';
echo '<div class="stat-icon-new green"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-content-new">';
echo '<div class="stat-label-new">COMPETENCY MASTERY</div>';
echo '<div class="stat-value-new">' . $analytics['competency']['percent_proficient'] . '%</div>';
echo '<div class="stat-details-new">';
echo '<span>' . $analytics['competency']['proficient_count'] . ' / ' . $analytics['competency']['total_count'] . '</span>';
if ($analytics['competency']['percent_proficient'] >= 80) {
    echo '<span>Showing excellent growth</span>';
} elseif ($analytics['competency']['percent_proficient'] >= 50) {
    echo '<span>Showing good growth</span>';
} else {
    echo '<span>Needs more practice</span>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '</div>'; // stat-card-grid-new
echo '</div>'; // stats-section

// Performance charts - Two separate charts
echo '<div class="overview-section-card snapshot-section">';
echo '<div class="performance-charts-container">';

// Assignment Performance Chart
echo '<div class="performance-chart-panel assignment-chart-panel">';
echo '<div class="chart-panel-header">';
echo '<div class="legend-title">Assignment Performance</div>';
echo '<div class="legend-items">';
echo '<div class="legend-item">';
echo '<span class="legend-color" style="background-color: rgba(59, 130, 246, 0.8);"></span>';
echo '<span class="legend-text">Student Avg</span>';
echo '</div>';
echo '<div class="legend-item">';
echo '<span class="legend-color" style="background-color: rgba(148, 163, 184, 0.6);"></span>';
echo '<span class="legend-text">Class Avg</span>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<canvas id="assignmentPerformanceChart"></canvas>';
echo '</div>';

// Quiz Performance Chart
echo '<div class="performance-chart-panel quiz-chart-panel">';
echo '<div class="chart-panel-header">';
echo '<div class="legend-title">Quiz Performance</div>';
echo '<div class="legend-items">';
echo '<div class="legend-item">';
echo '<span class="legend-color" style="background-color: rgba(251, 146, 60, 0.8);"></span>';
echo '<span class="legend-text">Student Avg</span>';
echo '</div>';
echo '<div class="legend-item">';
echo '<span class="legend-color" style="background-color: rgba(148, 163, 184, 0.6);"></span>';
echo '<span class="legend-text">Class Avg</span>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<canvas id="quizPerformanceChart"></canvas>';
echo '</div>';

echo '</div>'; // performance-charts-container
echo '</div>'; // snapshot-section

// Activity Engagement - Full width
echo '<div class="overview-section-card engagement-section-full">';
echo '<div class="section-card-header">';
echo '<h3><i class="fa fa-line-chart"></i> Activity Engagement</h3>';
echo '</div>';
echo '<div class="engagement-content-full">';

// SCORM Activities - Left side
echo '<div class="engagement-item scorm-item">';
echo '<div class="engagement-icon teal"><i class="fa fa-book"></i></div>';
echo '<div class="engagement-info">';
echo '<div class="engagement-label">SCORM Activities</div>';
echo '<div class="engagement-value">' . $analytics['activities']['scorm_viewed'] . ' modules</div>';
echo '<div class="engagement-sub">Attempted modules</div>';
echo '</div>';
echo '</div>';

// SCORM Time Spent - Right side
echo '<div class="engagement-item scorm-time-item">';
echo '<div class="engagement-icon teal-dark"><i class="fa fa-clock-o"></i></div>';
echo '<div class="engagement-info">';
echo '<div class="engagement-label">SCORM Time Spent</div>';
if ($analytics['activities']['scorm_time_spent'] > 0) {
    $hours = floor($analytics['activities']['scorm_time_spent'] / 3600);
    $minutes = floor(($analytics['activities']['scorm_time_spent'] % 3600) / 60);
    $seconds = $analytics['activities']['scorm_time_spent'] % 60;
    if ($hours > 0) {
        $timeDisplay = $hours . 'h ' . $minutes . 'm';
    } elseif ($minutes > 0) {
        $timeDisplay = $minutes . 'm ' . $seconds . 's';
    } else {
        $timeDisplay = $seconds . 's';
    }
    echo '<div class="engagement-value">' . $timeDisplay . '</div>';
    echo '<div class="engagement-sub">Total time spent in SCORM activities</div>';
} else {
    echo '<div class="engagement-value">0h 0m</div>';
    echo '<div class="engagement-sub">No time tracked</div>';
}
echo '</div>';
echo '</div>';

// Videos Watched - Third item
echo '<div class="engagement-item video-item">';
echo '<div class="engagement-icon red"><i class="fa fa-video-camera"></i></div>';
echo '<div class="engagement-info">';
echo '<div class="engagement-label">Videos Watched</div>';
echo '<div class="engagement-value">' . $analytics['activities']['videos_watched'] . ' videos</div>';
if ($analytics['activities']['video_time_spent'] > 0) {
    echo '<div class="engagement-sub">Watch time: ' . $analytics['activities']['video_time_spent_display'] . '</div>';
} else {
    echo '<div class="engagement-sub">Total videos viewed</div>';
}
echo '</div>';
echo '</div>';

echo '</div>'; // engagement-content-full
echo '</div>'; // engagement-section-full

// Competency Overview - Full width
echo '<div class="overview-section-card competency-section">';
echo '<div class="competency-overview-panel">';
echo '<div class="chart-header">';
echo '<div>';
echo '<h3><i class="fa fa-bullseye"></i> Competency Overview</h3>';
echo '<p>Activities completed vs remaining for each competency.</p>';
echo '</div>';
echo '<div class="chart-header-controls">';
echo '<div class="chart-stats">';
echo '<span class="stat-badge mastery">' . $analytics['competency']['percent_proficient'] . '% mastery</span>';
echo '<span class="stat-badge count">' . $analytics['competency']['proficient_count'] . '/' . $analytics['competency']['total_count'] . ' proficient</span>';
echo '</div>';
if (!empty($analytics['competency']['frameworks'])) {
    echo '<div class="framework-selector">';
    echo '<label for="competencyFrameworkSelect" style="margin-right: 0.5rem; font-size: 0.875rem; color: #64748b; font-weight: 600;">Framework:</label>';
    echo '<select id="competencyFrameworkSelect" class="framework-dropdown" onchange="switchCompetencyFramework(this.value)">';
    $firstFramework = true;
    foreach ($analytics['competency']['frameworks'] as $framework) {
        $selected = $firstFramework ? 'selected' : '';
        echo '<option value="' . htmlspecialchars($framework['id']) . '" ' . $selected . '>' . htmlspecialchars($framework['name']) . '</option>';
        $firstFramework = false;
    }
    echo '</select>';
    echo '</div>';
    // Export Report Button
    $exportParams = [
        'userid' => $userid,
        'frameworkid' => $analytics['competency']['frameworks'][0]['id'],
        'sesskey' => sesskey()
    ];
    foreach ($courseids as $cid) {
        $exportParams['courseid[]'] = $cid;
    }
    $exportUrl = new moodle_url('/theme/remui_kids/teacher/competency_export.php', $exportParams);
    echo '<div class="framework-export">';
    echo '<a href="' . $exportUrl->out() . '" class="export-report-btn" id="exportReportBtnOverview" title="Export Competency Report">';
    echo '<i class="fa fa-download"></i> Export Report';
    echo '</a>';
    echo '</div>';
}
echo '</div>';
echo '</div>';
echo '<canvas id="competencyOverviewChart"></canvas>';
echo '</div>';
echo '</div>'; // competency-section

// Time spent chart
echo '<div class="overview-section-card timeline-section">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-clock-o"></i> Daily Time Spent in this Course (estimated)</h3>';
echo '<div class="chart-toggle-buttons">';
echo '<button class="toggle-btn active" onclick="switchActiveDaysChart(\"week\")" id="toggle-week">This Week</button>';
echo '<button class="toggle-btn" onclick="switchActiveDaysChart(\"month\")" id="toggle-month">This Month</button>';
echo '</div>';
echo '</div>';
echo '<canvas id="activeDaysChart"></canvas>';
echo '</div>';

echo '</div>'; // tab-panel overview

// ============ ASSIGNMENTS TAB ============
echo '<div id="assignments-tab" class="tab-panel">';

// Enhanced Assignment Summary Stats - Individual Cards
echo '<div class="assignments-summary-cards">';

// Student Average Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon purple"><i class="fa fa-user"></i></div>';
echo '<div class="assignment-stat-value">' . round($analytics['grades']['assignment_avg'], 1) . '%</div>';
echo '<div class="assignment-stat-label">Student Average</div>';
echo '</div>';

// Class Average Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon light-blue"><i class="fa fa-users"></i></div>';
echo '<div class="assignment-stat-value">' . round($analytics['grades']['assignment_class_avg'], 1) . '%</div>';
echo '<div class="assignment-stat-label">Class Average</div>';
echo '</div>';

// Completion Rate Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon green"><i class="fa fa-check-circle"></i></div>';
echo '<div class="assignment-stat-value">' . $analytics['completion']['assignment_rate'] . '%</div>';
echo '<div class="assignment-stat-label">Completion Rate</div>';
echo '</div>';

// Pending Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon orange"><i class="fa fa-clock-o"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['completion']['pending_assignments'] ?? 0) . '</div>';
echo '<div class="assignment-stat-label">Pending</div>';
echo '</div>';

// Late Submissions Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon red"><i class="fa fa-exclamation-triangle"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['completion']['late_submissions'] ?? 0) . '</div>';
echo '<div class="assignment-stat-label">Late Submissions</div>';
echo '</div>';

// Overdue Card
$overdueCount = 0;
if (!empty($analytics['assignments_detail'])) {
    foreach ($analytics['assignments_detail'] as $assign) {
        if ($assign['status'] === 'overdue') {
            $overdueCount++;
        }
    }
}
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon red"><i class="fa fa-times-circle"></i></div>';
echo '<div class="assignment-stat-value">' . $overdueCount . '</div>';
echo '<div class="assignment-stat-label">Overdue</div>';
echo '</div>';

// Class Submission Rate Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon green"><i class="fa fa-bar-chart"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['completion']['class_submission_rate'] ?? 0) . '%</div>';
echo '<div class="assignment-stat-label">Class Submission Rate</div>';
echo '</div>';

echo '</div>'; // assignments-summary-cards

// Charts Section
echo '<div class="assignments-charts-section">';
// Submission Rate Chart (Student vs Class)
echo '<div class="overview-section-card">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-bar-chart"></i> Submission Rate Comparison</h3>';
echo '<p>Compare your submission rate to the class average.</p>';
echo '</div>';
echo '<canvas id="assignmentSubmissionChart"></canvas>';
echo '</div>';

// Grade Comparison Chart (Student vs Class)
echo '<div class="overview-section-card">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-line-chart"></i> Grade Comparison</h3>';
echo '<p>Compare your assignment grades to the class average.</p>';
echo '</div>';
echo '<canvas id="assignmentGradeChart"></canvas>';
echo '</div>';
echo '</div>'; // assignments-charts-section

// Assignment Details Table with Status
if (!empty($analytics['assignments_detail'])) {
    echo '<div class="detail-table-container">';
    echo '<table class="detail-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Assignment</th>';
    echo '<th>Course</th>';
    echo '<th>Status</th>';
    echo '<th>Due Date</th>';
    echo '<th>Submission Date</th>';
    echo '<th>Grade</th>';
    echo '<th>Percentage</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($analytics['assignments_detail'] as $assignment) {
        $statusClass = 'status-pending';
        $statusText = 'Pending';
        $statusIcon = 'fa-clock-o';
        
        if ($assignment['status'] === 'late') {
            $statusClass = 'status-late';
            $statusText = 'Late Submission';
            $statusIcon = 'fa-exclamation-triangle';
        } elseif ($assignment['status'] === 'overdue') {
            $statusClass = 'status-overdue';
            $statusText = 'Overdue';
            $statusIcon = 'fa-times-circle';
        } elseif ($assignment['status'] === 'ontime') {
            $statusClass = 'status-ontime';
            $statusText = 'On Time';
            $statusIcon = 'fa-check-circle';
        } elseif ($assignment['status'] === 'closed') {
            $statusClass = 'status-closed';
            $statusText = 'Closed';
            $statusIcon = 'fa-times-circle';
        } elseif ($assignment['status'] === 'pending') {
            $statusClass = 'status-pending';
            $statusText = 'Pending';
            $statusIcon = 'fa-clock-o';
        }
        
        $gradeClass = 'grade-excellent';
        if ($assignment['percentage'] && $assignment['percentage'] < 75) $gradeClass = 'grade-good';
        if ($assignment['percentage'] && $assignment['percentage'] < 50) $gradeClass = 'grade-fair';
        if ($assignment['percentage'] && $assignment['percentage'] < 30) $gradeClass = 'grade-poor';
        
        echo '<tr>';
        echo '<td><strong>' . s($assignment['name']) . '</strong></td>';
        echo '<td>' . s($assignment['course_fullname']) . '</td>';
        echo '<td><span class="status-badge ' . $statusClass . '"><i class="fa ' . $statusIcon . '"></i> ' . $statusText . '</span></td>';
        echo '<td>' . ($assignment['duedate'] ? $assignment['duedate'] : 'No due date') . '</td>';
        echo '<td>' . ($assignment['submission_date'] ? $assignment['submission_date'] : '-') . '</td>';
        echo '<td>';
        if ($assignment['has_grade']) {
            echo round($assignment['grade'], 1) . ' / ' . $assignment['maxgrade'];
        } else {
            // Show Grade button if not graded yet
            if ($assignment['has_submission']) {
                // Determine grading URL based on rubric usage
                if ($assignment['uses_rubric']) {
                    // Rubric grading page
                    $gradeUrl = new moodle_url('/theme/remui_kids/teacher/grade_student.php', [
                        'assignmentid' => $assignment['id'],
                        'courseid' => $assignment['course'],
                        'studentid' => $analytics['user']['id']
                    ]);
                } else {
                    // Standard Moodle manual grading page
                    $gradeUrl = new moodle_url('/mod/assign/view.php', [
                        'id' => $assignment['cmid'],
                        'action' => 'grader',
                        'userid' => $analytics['user']['id']
                    ]);
                }
                echo '<a href="' . $gradeUrl->out() . '" class="grade-button" title="Grade Assignment">';
                echo '<i class="fa fa-check-square-o"></i> Grade';
                echo '</a>';
            } else {
                echo '-';
            }
        }
        echo '</td>';
        echo '<td>';
        if ($assignment['has_grade']) {
            echo '<span class="grade-badge ' . $gradeClass . '">' . $assignment['percentage'] . '%</span>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
} else {
    echo '<div class="empty-state">No assignments available.</div>';
}

echo '</div>'; // tab-panel assignments

// ============ QUIZZES TAB ============
echo '<div id="quizzes-tab" class="tab-panel">';

// Enhanced Quiz Summary Stats - Individual Cards
echo '<div class="assignments-summary-cards quizzes-summary-cards">';

// Student Average Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon purple"><i class="fa fa-user"></i></div>';
echo '<div class="assignment-stat-value">' . round($analytics['grades']['quiz_avg'], 1) . '%</div>';
echo '<div class="assignment-stat-label">Student Average</div>';
echo '</div>';

// Class Average Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon light-blue"><i class="fa fa-users"></i></div>';
echo '<div class="assignment-stat-value">' . round($analytics['grades']['quiz_class_avg'], 1) . '%</div>';
echo '<div class="assignment-stat-label">Class Average</div>';
echo '</div>';

// Completion Rate Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon green"><i class="fa fa-check-circle"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['completion']['quiz_rate'] ?? 0) . '%</div>';
echo '<div class="assignment-stat-label">Completion Rate</div>';
echo '</div>';

// Avg. Time Spent Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon light-blue"><i class="fa fa-clock-o"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['quiz_stats']['avg_time_spent_display'] ?? '0:00:00') . '</div>';
echo '<div class="assignment-stat-label">Avg. Time Spent</div>';
echo '</div>';

// Improvement Rate Card
$improvementRate = $analytics['quiz_stats']['improvement_rate'] ?? 0;
$improvementIcon = $improvementRate >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
$improvementClass = $improvementRate >= 0 ? 'green' : 'red';
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon ' . $improvementClass . '"><i class="fa ' . $improvementIcon . '"></i></div>';
echo '<div class="assignment-stat-value">' . ($improvementRate >= 0 ? '+' : '') . $improvementRate . '%</div>';
echo '<div class="assignment-stat-label">Improvement Rate</div>';
echo '</div>';

// Attempts Made Card
echo '<div class="assignment-stat-card">';
echo '<div class="assignment-stat-icon orange"><i class="fa fa-repeat"></i></div>';
echo '<div class="assignment-stat-value">' . ($analytics['quiz_stats']['total_attempts'] ?? 0) . '</div>';
echo '<div class="assignment-stat-label">Attempts Made</div>';
echo '</div>';

echo '</div>'; // assignments-summary-cards

// Charts Section
echo '<div class="assignments-charts-section">';
// Score Comparison Chart (Student vs Class)
echo '<div class="overview-section-card">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-bar-chart"></i> Score Comparison</h3>';
echo '<p>Compare student\'s quiz scores to the class average.</p>';
echo '</div>';
echo '<canvas id="quizScoreChart"></canvas>';
echo '</div>';

// Completion Rate Chart (Student vs Class)
echo '<div class="overview-section-card">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-bar-chart"></i> Completion Rate</h3>';
echo '<p>Compare student\'s completion rate to the class average.</p>';
echo '</div>';
echo '<canvas id="quizCompletionChart"></canvas>';
echo '</div>';
echo '</div>'; // assignments-charts-section

// Performance Trend Chart
echo '<div class="overview-section-card">';
echo '<div class="chart-header">';
echo '<h3><i class="fa fa-line-chart"></i> Performance Trend</h3>';
echo '<p>Track Student\'s quiz performance over time compared to class average.</p>';
echo '</div>';
echo '<canvas id="quizPerformanceTrendChart"></canvas>';
echo '</div>';

// Quiz Details Table with Attempt Button
if (!empty($analytics['quizzes_detail'])) {
    echo '<div class="detail-table-container">';
    echo '<table class="detail-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Quiz</th>';
    echo '<th>Course</th>';
    echo '<th>Grade</th>';
    echo '<th>Percentage</th>';
    echo '<th>Attempt</th>';
    echo '<th>Date</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($analytics['quizzes_detail'] as $quiz) {
        $gradeClass = 'grade-excellent';
        if ($quiz['percentage'] < 75) $gradeClass = 'grade-good';
        if ($quiz['percentage'] < 50) $gradeClass = 'grade-fair';
        if ($quiz['percentage'] < 30) $gradeClass = 'grade-poor';
        
        echo '<tr>';
        echo '<td><strong>' . s($quiz['name']) . '</strong></td>';
        echo '<td>' . s($quiz['course_fullname'] ?? $quiz['course_shortname'] ?? '') . '</td>';
        echo '<td>' . $quiz['grade'] . ' / ' . $quiz['maxgrade'] . '</td>';
        echo '<td><span class="grade-badge ' . $gradeClass . '">' . $quiz['percentage'] . '%</span></td>';
        echo '<td>';
        if (!empty($quiz['attemptid'])) {
            // Link to quiz review page
            $attemptUrl = new moodle_url('/theme/remui_kids/teacher/quiz_review.php', [
                'attemptid' => $quiz['attemptid']
            ]);
            echo '<a href="' . $attemptUrl->out() . '" class="grade-button" title="View Attempt">';
            echo '<i class="fa fa-eye"></i> Attempt';
            echo '</a>';
        } else {
            echo '#' . ($quiz['attempt'] ?? 1);
        }
        echo '</td>';
        echo '<td>' . $quiz['date'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
} else {
    echo '<div class="empty-state">No quiz attempts completed yet.</div>';
}

echo '</div>'; // tab-panel quizzes

// ============ COMPETENCIES TAB ============
echo '<div id="competencies-tab" class="tab-panel">';

// Competency Overview Chart (Same as Overview section)
echo '<div class="overview-section-card competency-section">';
echo '<div class="competency-overview-panel">';
echo '<div class="chart-header">';
echo '<div>';
echo '<h3><i class="fa fa-bullseye"></i> Competency Overview</h3>';
echo '<p>Activities completed vs remaining for each competency.</p>';
echo '</div>';
echo '<div class="chart-header-controls">';
echo '<div class="chart-stats">';
echo '<span class="stat-badge mastery">' . $analytics['competency']['percent_proficient'] . '% mastery</span>';
echo '<span class="stat-badge count">' . $analytics['competency']['proficient_count'] . '/' . $analytics['competency']['total_count'] . ' proficient</span>';
echo '</div>';
if (!empty($analytics['competency']['frameworks'])) {
    echo '<div class="framework-selector">';
    echo '<label for="competencyFrameworkSelectTab" style="margin-right: 0.5rem; font-size: 0.875rem; color: #64748b; font-weight: 600;">Framework:</label>';
    echo '<select id="competencyFrameworkSelectTab" class="framework-dropdown" onchange="switchCompetencyFrameworkTab(this.value)">';
    $firstFramework = true;
    foreach ($analytics['competency']['frameworks'] as $framework) {
        $selected = $firstFramework ? 'selected' : '';
        echo '<option value="' . htmlspecialchars($framework['id']) . '" ' . $selected . '>' . htmlspecialchars($framework['name']) . '</option>';
        $firstFramework = false;
    }
    echo '</select>';
    echo '</div>';
    // Export Report Button
    $exportParamsTab = [
        'userid' => $userid,
        'frameworkid' => $analytics['competency']['frameworks'][0]['id'],
        'sesskey' => sesskey()
    ];
    foreach ($courseids as $cid) {
        $exportParamsTab['courseid[]'] = $cid;
    }
    $exportUrlTab = new moodle_url('/theme/remui_kids/teacher/competency_export.php', $exportParamsTab);
    echo '<div class="framework-export">';
    echo '<a href="' . $exportUrlTab->out() . '" class="export-report-btn" id="exportReportBtnTab" title="Export Competency Report">';
    echo '<i class="fa fa-download"></i> Export Report';
    echo '</a>';
    echo '</div>';
}
echo '</div>';
echo '</div>';
echo '<canvas id="competencyOverviewChartTab"></canvas>';
echo '</div>';
echo '</div>'; // competency-section

// Competency List with Evidence
if (!empty($analytics['competency']['competencies'])) {
    echo '<div class="competency-list-container">';
    foreach ($analytics['competency']['frameworks'] as $fwidx => $framework) {
        $display = $fwidx === 0 ? 'block' : 'none';
        echo '<div class="framework-competencies" data-framework-id="' . $framework['id'] . '" style="display: ' . $display . ';">';
        echo '<h3 class="framework-name">' . s($framework['name']) . '</h3>';
        
        $frameworkComps = array_filter($analytics['competency']['competencies'], function($c) use ($framework) {
            return $c['frameworkid'] == $framework['id'];
        });
        
        echo '<ul class="competency-tree-list">';
        foreach ($frameworkComps as $comp) {
            $statusClass = 'not-competent';
            $statusIcon = 'fa-times-circle';
            if ($comp['proficient']) {
                $statusClass = 'competent';
                $statusIcon = 'fa-check-circle';
            } elseif ($comp['in_progress']) {
                $statusClass = 'in-progress';
                $statusIcon = 'fa-clock-o';
            }
            
            $hasSubCompetencies = !empty($comp['sub_competencies']) && is_array($comp['sub_competencies']) && count($comp['sub_competencies']) > 0;
            
            echo '<li class="competency-tree-item">';
            echo '<div class="competency-tree-row"' . ($hasSubCompetencies ? ' onclick="toggleCompetencyNode(this)"' : '') . '>';
            
            // Caret for expandable items
            if ($hasSubCompetencies) {
                echo '<span class="competency-caret">▶</span>';
            } else {
                echo '<span class="competency-caret-spacer"></span>';
            }
            
            // Status icon
            echo '<i class="fa ' . $statusIcon . ' status-' . $statusClass . ' competency-status-icon"></i>';
            
            // Competency name
            echo '<span class="competency-tree-name">' . s($comp['name']) . '</span>';
            
            // Status badge
            echo '<span class="competency-status-badge ' . $statusClass . '">';
            if ($comp['proficient']) {
                echo 'Competent (' . $comp['proficiency_percent'] . '%)';
            } elseif ($comp['in_progress']) {
                echo 'In Progress (' . $comp['proficiency_percent'] . '%)';
            } else {
                echo 'Not Attempted';
            }
            echo '</span>';
            
            // Evidence Button for main competency
            if (!empty($comp['evidence'])) {
                $evidenceCount = count($comp['evidence']);
                $evidenceData = base64_encode(json_encode($comp['evidence']));
                $compId = $comp['id'] ?? 0;
                $compName = htmlspecialchars($comp['name'] ?? '', ENT_QUOTES, 'UTF-8');
                echo '<div class="competency-tree-actions">';
                echo '<button class="show-evidence-btn" data-competency-id="' . $compId . '" data-competency-name="' . $compName . '" data-evidence-data="' . $evidenceData . '">';
                echo '<i class="fa fa-eye"></i> Show Evidences (' . $evidenceCount . ')';
                echo '</button>';
                echo '</div>';
            }
            
            echo '</div>'; // competency-tree-row
            
            // Sub-competencies (initially hidden)
            if ($hasSubCompetencies) {
                echo '<ul class="competency-tree-level" style="display: none;">';
                foreach ($comp['sub_competencies'] as $subComp) {
                    // Determine sub-competency status
                    $subStatusIcon = 'fa-circle-o';
                    $subStatusClass = 'not-competent';
                    if ($subComp['proficient'] ?? false) {
                        $subStatusIcon = 'fa-check-circle';
                        $subStatusClass = 'competent';
                    } elseif ($subComp['in_progress'] ?? false) {
                        $subStatusIcon = 'fa-clock-o';
                        $subStatusClass = 'in-progress';
                    }
                    
                    $subStatusText = 'Not Attempted';
                    if ($subComp['proficient'] ?? false) {
                        $subStatusText = 'Competent (' . ($subComp['proficiency_percent'] ?? 0) . '%)';
                    } elseif ($subComp['in_progress'] ?? false) {
                        $subStatusText = 'In Progress (' . ($subComp['proficiency_percent'] ?? 0) . '%)';
                    }
                    
                    echo '<li class="competency-tree-item sub-competency-item">';
                    echo '<div class="competency-tree-row">';
                    echo '<span class="competency-caret-spacer"></span>'; // Spacer for alignment
                    echo '<i class="fa ' . $subStatusIcon . ' status-' . $subStatusClass . ' competency-status-icon"></i>';
                    echo '<span class="competency-tree-name">' . s($subComp['name']) . '</span>';
                    echo '<span class="competency-status-badge ' . $subStatusClass . '">' . $subStatusText . '</span>';
                    
                    // Evidence Button for sub-competency
                    if (!empty($subComp['evidence'])) {
                        $subEvidenceCount = count($subComp['evidence']);
                        $subEvidenceData = base64_encode(json_encode($subComp['evidence']));
                        $subCompId = $subComp['id'] ?? 0;
                        $subCompName = htmlspecialchars($subComp['name'] ?? '', ENT_QUOTES, 'UTF-8');
                        echo '<div class="competency-tree-actions">';
                        echo '<button class="show-evidence-btn" data-competency-id="' . $subCompId . '" data-competency-name="' . $subCompName . '" data-evidence-data="' . $subEvidenceData . '">';
                        echo '<i class="fa fa-eye"></i> Show Evidences (' . $subEvidenceCount . ')';
                        echo '</button>';
                        echo '</div>';
                    }
                    
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            
            echo '</li>'; // competency-tree-item
        }
        echo '</ul>'; // competency-tree-list
        echo '</div>'; // framework-competencies
    }
    echo '</div>'; // competency-list-container
}

echo '</div>'; // tab-panel competencies

// Evidence Modal
echo '<div id="evidenceModal" class="evidence-modal">';
echo '<div class="evidence-modal-content">';
echo '<div class="evidence-modal-header">';
echo '<h3 id="evidenceModalTitle">Evidence Submitted</h3>';
echo '<span class="evidence-modal-close" onclick="closeEvidenceModal()">&times;</span>';
echo '</div>';
echo '<div class="evidence-modal-body">';
echo '<div id="evidenceModalItems" class="evidence-modal-items"></div>';
echo '</div>';
echo '<div class="evidence-modal-footer">';
echo '<div class="evidence-pagination">';
echo '<button id="evidencePrevBtn" class="evidence-pagination-btn" onclick="changeEvidencePage(-1)" disabled>Previous</button>';
echo '<span id="evidencePageInfo" class="evidence-page-info">Page 1 of 1</span>';
echo '<button id="evidenceNextBtn" class="evidence-pagination-btn" onclick="changeEvidencePage(1)">Next</button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// ============ RUBRICS TAB ============
echo '<div id="rubrics-tab" class="tab-panel">';

if (!empty($analytics['rubrics']['details'])) {
    // Rubric Summary Stats
    $rubricAvgs = [];
    $gradedCount = 0;
    foreach ($analytics['rubrics']['details'] as $rubric) {
        if (!empty($analytics['rubrics']['performance'])) {
            foreach ($analytics['rubrics']['performance'] as $perf) {
                if ($perf['avg'] > 0) {
                    $rubricAvgs[] = $perf['avg'];
                }
            }
        }
        if ($rubric['is_graded'] ?? false) {
            $gradedCount++;
        }
    }
    $overallRubric = !empty($rubricAvgs) ? round(array_sum($rubricAvgs) / count($rubricAvgs), 1) : 0;
    $totalAttempted = count($analytics['rubrics']['details']);
    $ungradedCount = $totalAttempted - $gradedCount;
    
    echo '<div class="rubrics-summary-cards">';
    echo '<div class="rubric-stat-card">';
    echo '<div class="rubric-stat-icon purple"><i class="fa fa-list-ul"></i></div>';
    echo '<div class="rubric-stat-content">';
    echo '<div class="rubric-stat-value">' . $totalAttempted . '</div>';
    echo '<div class="rubric-stat-label">Total Attempted</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="rubric-stat-card">';
    echo '<div class="rubric-stat-icon green"><i class="fa fa-check-circle"></i></div>';
    echo '<div class="rubric-stat-content">';
    echo '<div class="rubric-stat-value">' . $gradedCount . '</div>';
    echo '<div class="rubric-stat-label">Graded</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="rubric-stat-card">';
    echo '<div class="rubric-stat-icon orange"><i class="fa fa-clock-o"></i></div>';
    echo '<div class="rubric-stat-content">';
    echo '<div class="rubric-stat-value">' . $ungradedCount . '</div>';
    echo '<div class="rubric-stat-label">Pending</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="rubric-stat-card">';
    echo '<div class="rubric-stat-icon blue"><i class="fa fa-bar-chart"></i></div>';
    echo '<div class="rubric-stat-content">';
    echo '<div class="rubric-stat-value">' . ($overallRubric > 0 ? $overallRubric . '%' : '-') . '</div>';
    echo '<div class="rubric-stat-label">Average Score</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Rubric Cards
    echo '<div class="rubrics-cards-container">';
    foreach ($analytics['rubrics']['details'] as $rubricDetail) {
        // Get rubric data for this assignment
        $cmid = $rubricDetail['cmid'] ?? null;
        $rubric_data = null;
        $existing_fillings = [];
        $existing_feedback = '';
        $total_score = 0;
        $max_score = 0;
        
        if ($cmid) {
            $rubric_data = theme_remui_kids_get_rubric_by_cmid($cmid);
            
            // If graded, get existing fillings and feedback
            if ($rubricDetail['is_graded'] && !empty($rubricDetail['grade_id'])) {
                $grade = $DB->get_record('assign_grades', ['id' => $rubricDetail['grade_id']], '*', IGNORE_MULTIPLE);
                if ($grade) {
                    $grading_instance = $DB->get_record('grading_instances', 
                        ['itemid' => $grade->id],
                        '*',
                        IGNORE_MULTIPLE
                    );
                    
                    if ($grading_instance) {
                        $fillings = $DB->get_records('gradingform_rubric_fillings', 
                            ['instanceid' => $grading_instance->id]
                        );
                        
                        foreach ($fillings as $filling) {
                            $existing_fillings[$filling->criterionid] = [
                                'levelid' => $filling->levelid,
                                'remark' => $filling->remark
                            ];
                            
                            $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
                            if ($level) {
                                $total_score += $level->score;
                            }
                        }
                        
                        $feedback_record = $DB->get_record('assignfeedback_comments', 
                            ['grade' => $grade->id, 'assignment' => $rubricDetail['assignment_id']],
                            '*',
                            IGNORE_MULTIPLE
                        );
                        
                        if ($feedback_record) {
                            $existing_feedback = $feedback_record->commenttext;
                        }
                    }
                }
                
                // Calculate max score from rubric
                if ($rubric_data) {
                    foreach ($rubric_data['criteria'] as $criterion) {
                        $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
                        $max_score += max($scores);
                    }
                }
            }
        }
        
        $cardId = 'rubric-card-body-' . $rubricDetail['assignment_id'];
        $toggleIconId = 'toggle-icon-' . $rubricDetail['assignment_id'];
        echo '<div class="rubric-card">';
        echo '<div class="rubric-card-header">';
        echo '<div class="rubric-card-title-section">';
        echo '<h3 class="rubric-card-title">' . s($rubricDetail['assignment_name']) . '</h3>';
        echo '<p class="rubric-card-subtitle">' . s($rubricDetail['course_fullname']) . '</p>';
        echo '</div>';
        
        echo '<div class="rubric-card-actions">';
        if ($rubricDetail['is_graded'] && $rubricDetail['rubric_grade'] !== null && $rubricDetail['rubric_grade'] > 0) {
            $gradeClass = 'grade-excellent';
            if ($rubricDetail['rubric_grade'] < 75) $gradeClass = 'grade-good';
            if ($rubricDetail['rubric_grade'] < 50) $gradeClass = 'grade-fair';
            if ($rubricDetail['rubric_grade'] < 30) $gradeClass = 'grade-poor';
            
            echo '<div class="rubric-card-grade">';
            echo '<span class="grade-badge ' . $gradeClass . '">' . $rubricDetail['rubric_grade'] . '%</span>';
            echo '<span class="rubric-grade-date">Graded: ' . ($rubricDetail['rubric_grade_date'] ?? 'N/A') . '</span>';
            echo '</div>';
        } else {
            echo '<div class="rubric-card-grade">';
            echo '<span class="grade-badge grade-pending">Pending</span>';
            echo '<span class="rubric-grade-date">Submitted: ' . ($rubricDetail['submission_date'] ?? 'N/A') . '</span>';
            echo '</div>';
        }
        
        if ($rubric_data && !empty($rubric_data['criteria'])) {
            echo '<button type="button" class="rubric-toggle-btn" onclick="toggleRubricCard(\'' . $cardId . '\', \'' . $toggleIconId . '\')">';
            echo '<i class="fa fa-chevron-down" id="' . $toggleIconId . '"></i>';
            echo '<span>View Rubric</span>';
            echo '</button>';
        }
        echo '</div>';
        echo '</div>';
        
        if ($rubric_data && !empty($rubric_data['criteria'])) {
            echo '<div class="rubric-card-body" id="' . $cardId . '" style="display: none;">';
            echo '<table class="rubric-view-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Criterion</th>';
            if (!empty($rubric_data['criteria'][0]['levels'])) {
                foreach ($rubric_data['criteria'][0]['levels'] as $level) {
                    echo '<th>' . format_float($level->score, 0) . ' Points</th>';
                }
            }
            echo '<th>Comments</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($rubric_data['criteria'] as $criterion) {
                echo '<tr class="criterion-row">';
                echo '<td class="criterion-name"><strong>' . format_string($criterion['description']) . '</strong></td>';
                
                $selected_level_id = null;
                $criterion_comment = '';
                if (isset($existing_fillings[$criterion['id']])) {
                    $selected_level_id = $existing_fillings[$criterion['id']]['levelid'];
                    $criterion_comment = $existing_fillings[$criterion['id']]['remark'];
                }
                
                foreach ($criterion['levels'] as $level) {
                    $is_selected = ($selected_level_id == $level->id);
                    echo '<td class="level-option-view ' . ($is_selected ? 'selected' : '') . '">';
                    echo '<div class="level-content">';
                    echo format_string($level->definition);
                    echo '</div>';
                    echo '</td>';
                }
                
                echo '<td class="comment-cell-view">';
                if ($criterion_comment) {
                    echo '<div class="criterion-comment-view">' . format_text($criterion_comment, FORMAT_HTML) . '</div>';
                } else {
                    echo '<div class="criterion-comment-view empty">No comments</div>';
                }
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            
            // Overall Feedback
            if ($existing_feedback) {
                echo '<div class="rubric-overall-feedback">';
                echo '<h4><i class="fa fa-comment"></i> Overall Feedback</h4>';
                echo '<div class="rubric-feedback-content">' . format_text($existing_feedback, FORMAT_HTML) . '</div>';
                echo '</div>';
            }
            
            // Score Summary (if graded)
            if ($rubricDetail['is_graded'] && $max_score > 0) {
                echo '<div class="rubric-score-summary">';
                echo '<div class="score-summary-item">';
                echo '<span class="score-label">Score</span>';
                echo '<span class="score-value">' . $total_score . ' / ' . $max_score . '</span>';
                echo '</div>';
                if ($rubricDetail['rubric_grade'] !== null) {
                    echo '<div class="score-summary-item">';
                    echo '<span class="score-label">Percentage</span>';
                    echo '<span class="score-value">' . $rubricDetail['rubric_grade'] . '%</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
            
            echo '</div>'; // rubric-card-body
        } else {
            echo '<div class="rubric-card-body">';
            echo '<div class="rubric-no-data">No rubric data available for this assignment.</div>';
            echo '</div>';
        }
        
        echo '</div>'; // rubric-card
    }
    echo '</div>'; // rubrics-cards-container
    
} else {
    echo '<div class="empty-state">No rubric evaluations found. Rubrics are used for grading assignments with rubric criteria.</div>';
}

echo '</div>'; // tab-panel rubrics

echo '</div>'; // analytics-tab-content

echo '</div>'; // student-report-wrapper
echo '</div>'; // teacher-main-content
echo '</div>'; // teacher-dashboard-wrapper
echo '</div>'; // teacher-css-wrapper

// JavaScript for charts, framework switching, and tab switching
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>';
echo '<script>
const analyticsData = ' . json_encode($analytics) . ';
const competencyData = ' . json_encode($analytics['competency']) . ';

// Tab Switching Functionality (like create_assignment_page.php)
function showAnalyticsTab(tabName) {
    // Hide all tab panels
    const tabPanels = document.querySelectorAll(".analytics-tab-content .tab-panel");
    tabPanels.forEach(panel => {
        panel.classList.remove("active");
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll(".analytics-tabs .tab-button");
    tabButtons.forEach(button => {
        button.classList.remove("active");
    });
    
    // Show selected tab panel
    const selectedPanel = document.getElementById(tabName + "-tab");
    if (selectedPanel) {
        selectedPanel.classList.add("active");
    }
    
    // Add active class to clicked tab button
    const clickedButton = event.target.closest(".tab-button");
    if (clickedButton) {
        clickedButton.classList.add("active");
    }
    
    // Initialize charts when tab becomes active
    if (tabName === "overview") {
        setTimeout(function() {
            initAssignmentPerformanceChart();
            initQuizPerformanceChart();
            initActiveDaysChart(activeDaysMode);
            initCompetencyOverviewChart();
        }, 100);
    } else if (tabName === "assignments") {
        setTimeout(function() {
            initAssignmentSubmissionChart();
            initAssignmentGradeChart();
        }, 100);
    } else if (tabName === "quizzes") {
        setTimeout(function() {
            initQuizScoreChart();
            initQuizCompletionChart();
            initQuizPerformanceTrendChart();
        }, 100);
    } else if (tabName === "competencies") {
        setTimeout(function() {
            initCompetencyOverviewChartTab();
            updateCompetencyEvidenceList();
        }, 100);
    }
}

// Switch competency framework
function switchCompetencyFramework(frameworkId) {
    currentFrameworkId = parseInt(frameworkId);
    initCompetencyOverviewChart();
    // Update export URL
    const exportBtn = document.getElementById("exportReportBtnOverview");
    if (exportBtn) {
        const url = new URL(exportBtn.href);
        url.searchParams.set("frameworkid", frameworkId);
        // Preserve courseid[] parameters
        exportBtn.href = url.toString();
    }
}

// Switch competency framework for tab
function switchCompetencyFrameworkTab(frameworkId) {
    currentFrameworkIdTab = parseInt(frameworkId);
    initCompetencyOverviewChartTab();
    updateCompetencyEvidenceList();
    // Update export URL
    const exportBtn = document.getElementById("exportReportBtnTab");
    if (exportBtn) {
        const url = new URL(exportBtn.href);
        url.searchParams.set("frameworkid", frameworkId);
        // Preserve courseid[] parameters
        exportBtn.href = url.toString();
    }
}

// Update competency evidence list based on selected framework
function updateCompetencyEvidenceList() {
    const frameworkSelect = document.getElementById("competencyFrameworkSelectTab");
    let frameworkId;
    if (frameworkSelect && frameworkSelect.value) {
        frameworkId = parseInt(frameworkSelect.value);
    } else if (currentFrameworkIdTab) {
        frameworkId = currentFrameworkIdTab;
    } else {
        frameworkId = competencyData && competencyData.frameworks && competencyData.frameworks.length > 0 
            ? competencyData.frameworks[0].id 
            : null;
    }
    
    if (!frameworkId) return;
    
    // Hide all framework competency sections
    const allFrameworkSections = document.querySelectorAll(".framework-competencies");
    allFrameworkSections.forEach(section => {
        section.style.display = "none";
    });
    
    // Show the selected framework competency section
    const selectedSection = document.querySelector(".framework-competencies[data-framework-id=\"" + frameworkId + "\"]");
    if (selectedSection) {
        selectedSection.style.display = "block";
    }
}

// Chart instances
let assignmentPerformanceChart = null;
let quizPerformanceChart = null;
let activeDaysChart = null;
let competencyOverviewChart = null;
let competencyOverviewChartTab = null;
let assignmentSubmissionChart = null;
let assignmentGradeChart = null;
let quizScoreChart = null;
let quizCompletionChart = null;
let quizPerformanceTrendChart = null;
let activeDaysMode = "week";
let currentFrameworkIdTab = null;

// Initialize on page load
document.addEventListener("DOMContentLoaded", function() {
    // Initialize all overview charts
    initAssignmentPerformanceChart();
    initQuizPerformanceChart();
    initActiveDaysChart("week");
    initCompetencyOverviewChart();
});

// Helper function to truncate names to first 2-3 words
function truncateName(name, maxWords) {
    if (!name) return "";
    const words = name.trim().split(/\s+/);
    if (words.length <= maxWords) return name;
    return words.slice(0, maxWords).join(" ") + "...";
}

// Assignment Performance Chart - Most Recent 5 Assignments
function initAssignmentPerformanceChart() {
    const ctx = document.getElementById("assignmentPerformanceChart");
    if (!ctx) return;
    
    if (assignmentPerformanceChart) assignmentPerformanceChart.destroy();
    
    // Get most recent 5 assignments (sorted by date, most recent first)
    const allAssignments = analyticsData.assignments_detail || [];
    const recentAssignments = allAssignments
        .filter(a => a.has_grade) // Only show graded assignments
        .sort((a, b) => {
            // Sort by date, most recent first
            const dateA = a.date ? new Date(a.date.split(" ").reverse().join(" ")) : new Date(0);
            const dateB = b.date ? new Date(b.date.split(" ").reverse().join(" ")) : new Date(0);
            return dateB - dateA;
        })
        .slice(0, 5)
        .reverse(); // Reverse to show oldest first (left to right)
    
    // Always ensure 5 items (pad with empty data if needed)
    const maxItems = 5;
    while (recentAssignments.length < maxItems) {
        recentAssignments.push({ name: "", percentage: null, has_grade: false });
    }
    
    // Prepare labels: Assignment names (truncate to 2-3 words if long)
    const labels = recentAssignments.map((a, i) => {
        if (!a.name || a.name === "") return "";
        const truncated = truncateName(a.name, 3);
        return truncated.length > 30 ? truncateName(a.name, 2) : truncated;
    });
    
    // Prepare student data (fill with 0 if no data)
    const studentData = recentAssignments.map(a => a.percentage !== null && a.percentage !== undefined ? a.percentage : 0);
    
    // Prepare class data (using individual assignment class averages)
    const classData = recentAssignments.map(a => a.class_avg !== null && a.class_avg !== undefined ? a.class_avg : 0);
    
    // Prepare colors: blue for assignments (transparent if no data)
    const studentColors = recentAssignments.map(a => 
        a.name && a.name !== "" ? "rgba(59, 130, 246, 0.8)" : "rgba(59, 130, 246, 0.1)"
    ); // Blue
    
    // Class average color (light gray)
    const classColor = "rgba(148, 163, 184, 0.6)"; // Light gray
    
    const data = {
        labels: labels,
        datasets: [{
            label: "Student Avg",
            data: studentData,
            backgroundColor: studentColors,
            borderColor: studentColors.map(c => c.replace("0.8", "1")),
            borderWidth: 1,
            borderRadius: 4
        }, {
            label: "Class Avg",
            data: classData,
            backgroundColor: classColor,
            borderColor: "rgba(148, 163, 184, 0.8)",
            borderWidth: 1,
            borderRadius: 4
        }]
    };
    
    assignmentPerformanceChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2.5,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ": " + context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10,
                            weight: "normal"
                        },
                        maxRotation: 0,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: false,
                    min: -20,
                    max: 100,
                    grid: {
                        color: "rgba(148, 163, 184, 0.2)"
                    },
                    ticks: {
                        stepSize: 20,
                        callback: function(value) {
                            return value + "%";
                        },
                        font: {
                            size: 11
                        }
                    }
                }
            }
        }
    });
}

// Quiz Performance Chart - Most Recent 5 Quizzes
function initQuizPerformanceChart() {
    const ctx = document.getElementById("quizPerformanceChart");
    if (!ctx) return;
    
    if (quizPerformanceChart) quizPerformanceChart.destroy();
    
    // Get most recent 5 quizzes (sorted by date, most recent first)
    const allQuizzes = analyticsData.quizzes_detail || [];
    const recentQuizzes = allQuizzes
        .sort((a, b) => {
            // Sort by date, most recent first
            const dateA = a.date ? new Date(a.date.split(" ").reverse().join(" ")) : new Date(0);
            const dateB = b.date ? new Date(b.date.split(" ").reverse().join(" ")) : new Date(0);
            return dateB - dateA;
        })
        .slice(0, 5)
        .reverse(); // Reverse to show oldest first (left to right)
    
    // Always ensure 5 items (pad with empty data if needed)
    const maxItems = 5;
    while (recentQuizzes.length < maxItems) {
        recentQuizzes.push({ name: "", percentage: null });
    }
    
    // Prepare labels: Quiz names (truncate to 2-3 words if long)
    const labels = recentQuizzes.map((q, i) => {
        if (!q.name || q.name === "") return "";
        const truncated = truncateName(q.name, 3);
        return truncated.length > 30 ? truncateName(q.name, 2) : truncated;
    });
    
    // Prepare student data (fill with 0 if no data)
    const studentData = recentQuizzes.map(q => q.percentage !== null && q.percentage !== undefined ? q.percentage : 0);
    
    // Prepare class data (using individual quiz class averages)
    const classData = recentQuizzes.map(q => q.class_avg !== null && q.class_avg !== undefined ? q.class_avg : 0);
    
    // Prepare colors: orange for quizzes (transparent if no data)
    const studentColors = recentQuizzes.map(q => 
        q.name && q.name !== "" ? "rgba(251, 146, 60, 0.8)" : "rgba(251, 146, 60, 0.1)"
    ); // Orange
    
    // Class average color (light gray)
    const classColor = "rgba(148, 163, 184, 0.6)"; // Light gray
    
    const data = {
        labels: labels,
        datasets: [{
            label: "Student Avg",
            data: studentData,
            backgroundColor: studentColors,
            borderColor: studentColors.map(c => c.replace("0.8", "1")),
            borderWidth: 1,
            borderRadius: 4
        }, {
            label: "Class Avg",
            data: classData,
            backgroundColor: classColor,
            borderColor: "rgba(148, 163, 184, 0.8)",
            borderWidth: 1,
            borderRadius: 4
        }]
    };
    
    quizPerformanceChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2.5,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ": " + context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10,
                            weight: "normal"
                        },
                        maxRotation: 0,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: false,
                    min: -20,
                    max: 100,
                    grid: {
                        color: "rgba(148, 163, 184, 0.2)"
                    },
                    ticks: {
                        stepSize: 20,
                        callback: function(value) {
                            return value + "%";
                        },
                        font: {
                            size: 11
                        }
                    }
                }
            }
        }
    });
}

// Active Days Chart (Week/Month Switchable)
function switchActiveDaysChart(mode) {
    activeDaysMode = mode;
    
    // Update button states
    document.getElementById("toggle-week").classList.toggle("active", mode === "week");
    document.getElementById("toggle-month").classList.toggle("active", mode === "month");
    
    initActiveDaysChart(mode);
}

// Helper function to convert seconds to hours (with decimals for minutes)
function secondsToHours(seconds) {
    return seconds / 3600; // Convert to hours
}

// Helper function to format time for display
function formatTimeForDisplay(hours) {
    if (hours === 0) return "0h";
    const wholeHours = Math.floor(hours);
    const minutes = Math.round((hours - wholeHours) * 60);
    if (wholeHours === 0) {
        return minutes + "m";
    } else if (minutes === 0) {
        return wholeHours + "h";
    } else {
        return wholeHours + "h " + minutes + "m";
    }
}

function initActiveDaysChart(mode) {
    const ctx = document.getElementById("activeDaysChart");
    if (!ctx) return;
    
    if (activeDaysChart) activeDaysChart.destroy();
    
    const activityData = analyticsData.activities || {};
    const weekData = activityData.time_spent_week || {}; // Now in seconds
    const monthData = activityData.time_spent_month || {}; // Now in seconds
    
    let labels = [];
    let data = []; // Will store hours
    
    if (mode === "week") {
        // Use the chronological order from backend (oldest to newest, today is last)
        const dayOrder = weekData._order || [];
        labels = dayOrder;
        // Convert seconds to hours - exclude _order from data
        const weekDataFiltered = Object.assign({}, weekData);
        delete weekDataFiltered._order;
        data = dayOrder.map(day => secondsToHours(weekDataFiltered[day] || 0));
    } else {
        // Month: last 30 days
        const monthLabels = Object.keys(monthData);
        if (monthLabels.length > 0) {
            labels = monthLabels.slice(-30); // Last 30 days
            // Convert seconds to hours
            data = labels.map(label => secondsToHours(monthData[label] || 0));
        } else {
            labels = [];
            data = [];
        }
    }
    
    activeDaysChart = new Chart(ctx.getContext("2d"), {
        type: "line",
        data: {
            labels: labels,
            datasets: [{
                label: "Time Spent",
                data: data,
                borderColor: "rgb(139, 92, 246)",
                backgroundColor: "rgba(139, 92, 246, 0.2)",
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: "rgb(139, 92, 246)",
                pointBorderColor: "#ffffff",
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2.5,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const hours = context.parsed.y;
                            return formatTimeForDisplay(hours);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: "Time (hours)"
                    },
                    ticks: {
                        callback: function(value) {
                            if (value === 0) return "0h";
                            const wholeHours = Math.floor(value);
                            const minutes = Math.round((value - wholeHours) * 60);
                            if (wholeHours === 0) {
                                return minutes + "m";
                            } else if (minutes === 0) {
                                return wholeHours + "h";
                            } else {
                                return wholeHours + "h";
                            }
                        },
                        stepSize: 0.5
                    }
                }
            }
        }
    });
}

// 6. Competency Overview Chart (Stacked Bar Chart - Activities Completed vs Remaining)
// Global variable to track current framework
let currentFrameworkId = null;

function initCompetencyOverviewChart() {
    const ctx = document.getElementById("competencyOverviewChart");
    if (!ctx || !competencyData || !competencyData.frameworks || competencyData.frameworks.length === 0) return;
    
    if (competencyOverviewChart) competencyOverviewChart.destroy();
    
    // Use selected framework or first framework
    const frameworkSelect = document.getElementById("competencyFrameworkSelect");
    let frameworkId;
    if (frameworkSelect && frameworkSelect.value) {
        frameworkId = parseInt(frameworkSelect.value);
    } else if (currentFrameworkId) {
        frameworkId = currentFrameworkId;
    } else {
        frameworkId = competencyData.frameworks[0].id;
    }
    currentFrameworkId = frameworkId;
    
    const frameworkComps = competencyData.competencies.filter(c => c.frameworkid == frameworkId);
    
    if (frameworkComps.length === 0) return;
    
    // Prepare data for stacked bar chart
    const labels = frameworkComps.map(c => {
        // Truncate long names
        const name = c.name.length > 25 ? c.name.substring(0, 25) + "..." : c.name;
        return name;
    });
    
    const completedData = frameworkComps.map(c => c.completed_activities || 0);
    const remainingData = frameworkComps.map(c => c.remaining_activities || 0);
    
    competencyOverviewChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: labels,
            datasets: [
                {
                    label: "Completed",
                    data: completedData,
                    backgroundColor: "rgba(34, 197, 94, 0.8)", // Green
                    borderColor: "rgb(34, 197, 94)",
                    borderWidth: 1
                },
                {
                    label: "Remaining",
                    data: remainingData,
                    backgroundColor: "rgba(59, 130, 246, 0.6)", // Blue
                    borderColor: "rgb(59, 130, 246)",
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: "y", // Horizontal bars
            plugins: {
                legend: {
                    display: true,
                    position: "top"
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const datasetLabel = context.dataset.label || "";
                            const value = context.parsed.x || 0;
                            return datasetLabel + ": " + value + " activities";
                        },
                        footer: function(tooltipItems) {
                            const total = tooltipItems.reduce((sum, item) => sum + (item.parsed.x || 0), 0);
                            return "Total: " + total + " activities";
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: "Number of Activities"
                    },
                    ticks: {
                        stepSize: 1,
                        precision: 0
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true
                }
            }
        }
    });
}

// Assignment Submission Rate Chart
function initAssignmentSubmissionChart() {
    const ctx = document.getElementById("assignmentSubmissionChart");
    if (!ctx) return;
    
    if (assignmentSubmissionChart) assignmentSubmissionChart.destroy();
    
    const studentRate = analyticsData.completion.assignment_rate || 0;
    const classRate = analyticsData.completion.class_submission_rate || 0;
    
    assignmentSubmissionChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: ["Students Submission Rate", "Class Submission Rate"],
            datasets: [{
                label: "Submission Rate (%)",
                data: [studentRate, classRate],
                backgroundColor: [
                    "rgba(59, 130, 246, 0.8)",
                    "rgba(148, 163, 184, 0.8)"
                ],
                borderColor: [
                    "rgb(59, 130, 246)",
                    "rgb(148, 163, 184)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + "%";
                        }
                    }
                }
            }
        }
    });
}

// Assignment Grade Comparison Chart
function initAssignmentGradeChart() {
    const ctx = document.getElementById("assignmentGradeChart");
    if (!ctx) return;
    
    if (assignmentGradeChart) assignmentGradeChart.destroy();
    
    const studentAvg = analyticsData.grades.assignment_avg || 0;
    const classAvg = analyticsData.grades.assignment_class_avg || 0;
    
    assignmentGradeChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: ["Student Average Grade", "Class Average Grade"],
            datasets: [{
                label: "Average Grade (%)",
                data: [studentAvg, classAvg],
                backgroundColor: [
                    "rgba(34, 197, 94, 0.8)",
                    "rgba(251, 191, 36, 0.8)"
                ],
                borderColor: [
                    "rgb(34, 197, 94)",
                    "rgb(251, 191, 36)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + "%";
                        }
                    }
                }
            }
        }
    });
}

// Competency Overview Chart for Tab
function initCompetencyOverviewChartTab() {
    const ctx = document.getElementById("competencyOverviewChartTab");
    if (!ctx || !competencyData || !competencyData.frameworks || competencyData.frameworks.length === 0) return;
    
    if (competencyOverviewChartTab) competencyOverviewChartTab.destroy();
    
    // Use selected framework or first framework
    const frameworkSelect = document.getElementById("competencyFrameworkSelectTab");
    let frameworkId;
    if (frameworkSelect && frameworkSelect.value) {
        frameworkId = parseInt(frameworkSelect.value);
    } else if (currentFrameworkIdTab) {
        frameworkId = currentFrameworkIdTab;
    } else {
        frameworkId = competencyData.frameworks[0].id;
    }
    currentFrameworkIdTab = frameworkId;
    
    const frameworkComps = competencyData.competencies.filter(c => c.frameworkid == frameworkId);
    
    if (frameworkComps.length === 0) return;
    
    // Prepare data for stacked bar chart
    const labels = frameworkComps.map(c => {
        // Truncate long names
        const name = c.name.length > 25 ? c.name.substring(0, 25) + "..." : c.name;
        return name;
    });
    
    const completedData = frameworkComps.map(c => c.completed_activities || 0);
    const remainingData = frameworkComps.map(c => c.remaining_activities || 0);
    
    competencyOverviewChartTab = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: labels,
            datasets: [
                {
                    label: "Completed",
                    data: completedData,
                    backgroundColor: "rgba(34, 197, 94, 0.8)", // Green
                    borderColor: "rgb(34, 197, 94)",
                    borderWidth: 1
                },
                {
                    label: "Remaining",
                    data: remainingData,
                    backgroundColor: "rgba(59, 130, 246, 0.6)", // Blue
                    borderColor: "rgb(59, 130, 246)",
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: "y", // Horizontal bars
            plugins: {
                legend: {
                    display: true,
                    position: "top"
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const datasetLabel = context.dataset.label || "";
                            const value = context.parsed.x || 0;
                            return datasetLabel + ": " + value + " activities";
                        },
                        footer: function(tooltipItems) {
                            const total = tooltipItems.reduce((sum, item) => sum + (item.parsed.x || 0), 0);
                            return "Total: " + total + " activities";
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: "Number of Activities"
                    },
                    ticks: {
                        stepSize: 1,
                        precision: 0
                    }
                },
                y: {
                    stacked: true
                }
            }
        }
    });
}

// Quiz Score Comparison Chart
function initQuizScoreChart() {
    const ctx = document.getElementById("quizScoreChart");
    if (!ctx) return;
    
    if (quizScoreChart) quizScoreChart.destroy();
    
    const studentAvg = analyticsData.grades.quiz_avg || 0;
    const classAvg = analyticsData.grades.quiz_class_avg || 0;
    
    quizScoreChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: ["Student Average Score", "Class Average Score"],
            datasets: [{
                label: "Average Score (%)",
                data: [studentAvg, classAvg],
                backgroundColor: [
                    "rgba(139, 92, 246, 0.8)",  // Purple
                    "rgba(59, 130, 246, 0.8)"   // Blue
                ],
                borderColor: [
                    "rgb(139, 92, 246)",
                    "rgb(59, 130, 246)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + "%";
                        }
                    }
                }
            }
        }
    });
}

// Quiz Completion Rate Chart
function initQuizCompletionChart() {
    const ctx = document.getElementById("quizCompletionChart");
    if (!ctx) return;
    
    if (quizCompletionChart) quizCompletionChart.destroy();
    
    const studentRate = analyticsData.completion.quiz_rate || 0;
    const classRate = analyticsData.quiz_stats.class_completion_rate || 0;
    
    quizCompletionChart = new Chart(ctx.getContext("2d"), {
        type: "bar",
        data: {
            labels: ["Student Completion Rate", "Class Completion Rate"],
            datasets: [{
                label: "Completion Rate (%)",
                data: [studentRate, classRate],
                backgroundColor: [
                    "rgba(16, 185, 129, 0.8)",  // Green
                    "rgba(148, 163, 184, 0.8)"  // Gray
                ],
                borderColor: [
                    "rgb(16, 185, 129)",
                    "rgb(148, 163, 184)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + "%";
                        }
                    }
                }
            }
        }
    });
}

// Quiz Performance Trend Chart (Line Chart)
function initQuizPerformanceTrendChart() {
    const ctx = document.getElementById("quizPerformanceTrendChart");
    if (!ctx) return;
    
    if (quizPerformanceTrendChart) quizPerformanceTrendChart.destroy();
    
    // Get most recent 5 quizzes (sorted by date, most recent first)
    const allQuizzes = analyticsData.quizzes_detail || [];
    const recentQuizzes = allQuizzes
        .sort((a, b) => {
            // Sort by date, most recent first
            const dateA = a.date ? new Date(a.date.split(" ").reverse().join(" ")) : new Date(0);
            const dateB = b.date ? new Date(b.date.split(" ").reverse().join(" ")) : new Date(0);
            return dateB - dateA;
        })
        .slice(0, 5)
        .reverse(); // Reverse to show oldest first (left to right)
    
    // Always ensure 5 items (pad with empty data if needed)
    const maxItems = 5;
    while (recentQuizzes.length < maxItems) {
        recentQuizzes.push({ name: "", percentage: null, class_avg: null });
    }
    
    // Prepare labels: Quiz 1, Quiz 2, etc. (or truncate names if available)
    const labels = recentQuizzes.map((q, i) => {
        if (!q.name || q.name === "") return "Quiz " + (i + 1);
        const truncated = truncateName(q.name, 3);
        return truncated.length > 30 ? truncateName(q.name, 2) : truncated;
    });
    
    // Prepare student data (fill with 0 if no data)
    const studentData = recentQuizzes.map(q => q.percentage !== null && q.percentage !== undefined ? q.percentage : null);
    
    // Prepare class data (using individual quiz class averages)
    const classData = recentQuizzes.map(q => q.class_avg !== null && q.class_avg !== undefined ? q.class_avg : null);
    
    quizPerformanceTrendChart = new Chart(ctx.getContext("2d"), {
        type: "line",
        data: {
            labels: labels,
            datasets: [{
                label: "Student Avg",
                data: studentData,
                borderColor: "rgb(139, 92, 246)",  // Purple
                backgroundColor: "rgba(139, 92, 246, 0.1)",
                borderWidth: 2,
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: "rgb(139, 92, 246)",
                pointBorderColor: "#ffffff",
                pointBorderWidth: 2,
                pointHoverRadius: 6
            }, {
                label: "Class Avg",
                data: classData,
                borderColor: "rgb(59, 130, 246)",  // Blue
                backgroundColor: "rgba(59, 130, 246, 0.1)",
                borderWidth: 2,
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: "rgb(59, 130, 246)",
                pointBorderColor: "#ffffff",
                pointBorderWidth: 2,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 3,
            plugins: {
                legend: {
                    display: true,
                    position: "top",
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 12,
                            weight: "500"
                        }
                    }
                },
                tooltip: {
                    mode: "index",
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y;
                            if (value === null || value === undefined) return "";
                            return context.dataset.label + ": " + value.toFixed(1) + "%";
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + "%";
                        },
                        stepSize: 25
                    },
                    grid: {
                        color: "rgba(148, 163, 184, 0.1)"
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 0,
                        minRotation: 0
                    }
                }
            },
            interaction: {
                mode: "nearest",
                axis: "x",
                intersect: false
            }
        }
    });
}

// Evidence Modal Functions
let currentEvidenceData = [];
let currentEvidencePage = 1;
const evidenceItemsPerPage = 6;

function openEvidenceModal(competencyId, competencyName, evidenceDataBase64) {
    console.log("openEvidenceModal called", { competencyId, competencyName, hasData: !!evidenceDataBase64 });
    
    try {
        const modal = document.getElementById("evidenceModal");
        const modalTitle = document.getElementById("evidenceModalTitle");
        
        if (!modal) {
            console.error("Modal element not found!");
            alert("Modal element not found. Please refresh the page.");
            return;
        }
        
        if (!modalTitle) {
            console.error("Modal title element not found!");
            return;
        }
        
        const evidenceDataJson = atob(evidenceDataBase64);
        currentEvidenceData = JSON.parse(evidenceDataJson);
        currentEvidencePage = 1;
        
        console.log("Parsed evidence data:", currentEvidenceData.length, "items");
        
        modalTitle.textContent = competencyName + " - Evidence Submitted";
        modal.classList.add("show");
        
        console.log("Modal class show added");
        
        renderEvidencePage();
    } catch (e) {
        console.error("Error opening evidence modal:", e);
        alert("Error loading evidence data: " + e.message);
    }
}

function closeEvidenceModal() {
    const modal = document.getElementById("evidenceModal");
    if (modal) {
        modal.classList.remove("show");
    }
    currentEvidenceData = [];
    currentEvidencePage = 1;
}

function changeEvidencePage(direction) {
    const totalPages = Math.ceil(currentEvidenceData.length / evidenceItemsPerPage);
    currentEvidencePage += direction;
    
    if (currentEvidencePage < 1) {
        currentEvidencePage = 1;
    } else if (currentEvidencePage > totalPages) {
        currentEvidencePage = totalPages;
    }
    
    renderEvidencePage();
}

function renderEvidencePage() {
    const container = document.getElementById("evidenceModalItems");
    const totalPages = Math.ceil(currentEvidenceData.length / evidenceItemsPerPage);
    const startIndex = (currentEvidencePage - 1) * evidenceItemsPerPage;
    const endIndex = startIndex + evidenceItemsPerPage;
    const pageData = currentEvidenceData.slice(startIndex, endIndex);
    
    // Clear container
    container.innerHTML = "";
    
    // Render items for current page
    if (pageData.length === 0) {
        container.innerHTML = "<p style=\"text-align: center; color: #64748b; padding: 2rem;\">No evidence found.</p>";
    } else {
        pageData.forEach(function(evidence) {
            const item = document.createElement("div");
            item.className = "evidence-modal-item";
            
            let html = "<div class=\"evidence-modal-item-info\">";
            html += "<strong>" + escapeHtml(evidence.activity_name || "Unknown Activity") + "</strong>";
            html += "<span class=\\"evidence-modal-item-date\\">" + (evidence.date || "No date") + "</span>";
            html += "</div>";
            
            if (evidence.grade) {
                html += "<span class=\\"evidence-modal-item-grade\\">Grade: " + escapeHtml(evidence.grade) + "</span>";
            }
            if (evidence.teacher_rating) {
                html += "<span class=\\"evidence-modal-item-rating\\">Rating: " + escapeHtml(evidence.teacher_rating) + "</span>";
            }
            
            item.innerHTML = html;
            container.appendChild(item);
        });
    }
    
    // Update pagination controls
    const pageInfo = document.getElementById("evidencePageInfo");
    const prevBtn = document.getElementById("evidencePrevBtn");
    const nextBtn = document.getElementById("evidenceNextBtn");
    
    pageInfo.textContent = "Page " + currentEvidencePage + " of " + totalPages;
    prevBtn.disabled = currentEvidencePage === 1;
    nextBtn.disabled = currentEvidencePage >= totalPages || totalPages === 0;
}

function escapeHtml(text) {
    if (!text) return "";
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/\'/g, "&#x27;");
}

// Event delegation for evidence buttons - attach directly to document
document.addEventListener("click", function(event) {
    if (event.target.closest(".show-evidence-btn")) {
        event.preventDefault();
        event.stopPropagation();
        const btn = event.target.closest(".show-evidence-btn");
        const competencyId = btn.getAttribute("data-competency-id");
        const competencyName = btn.getAttribute("data-competency-name");
        const evidenceDataBase64 = btn.getAttribute("data-evidence-data");
        
        console.log("Evidence button clicked", { competencyId, competencyName, hasData: !!evidenceDataBase64 });
        
        if (competencyId && competencyName && evidenceDataBase64) {
            openEvidenceModal(competencyId, competencyName, evidenceDataBase64);
        } else {
            console.error("Missing data attributes:", { competencyId, competencyName, evidenceDataBase64: !!evidenceDataBase64 });
        }
    }
    
    // Close modal when clicking outside
    const modal = document.getElementById("evidenceModal");
    if (event.target === modal) {
        closeEvidenceModal();
    }
});

// Toggle rubric card visibility
function toggleRubricCard(cardBodyId, iconId) {
    const cardBody = document.getElementById(cardBodyId);
    const icon = document.getElementById(iconId);
    
    if (!cardBody || !icon) return;
    
    const isExpanded = cardBody.style.display !== "none";
    
    if (isExpanded) {
        cardBody.style.display = "none";
        icon.classList.remove("fa-chevron-up");
        icon.classList.add("fa-chevron-down");
        // Update button text
        const btn = icon.closest(".rubric-toggle-btn");
        if (btn) {
            const span = btn.querySelector("span");
            if (span) span.textContent = "View Rubric";
        }
    } else {
        cardBody.style.display = "block";
        icon.classList.remove("fa-chevron-down");
        icon.classList.add("fa-chevron-up");
        // Update button text
        const btn = icon.closest(".rubric-toggle-btn");
        if (btn) {
            const span = btn.querySelector("span");
            if (span) span.textContent = "Hide Rubric";
        }
    }
}

function toggleCompetencyNode(el) {
    const row = el;
    const item = el.closest(".competency-tree-item");
    const list = item ? item.querySelector(".competency-tree-level") : null;
    
    if (!list) return;
    
    const caret = row.querySelector(".competency-caret");
    const isOpen = list.style.display !== "none";
    
    list.style.display = isOpen ? "none" : "block";
    if (caret) {
        caret.textContent = isOpen ? "▶" : "▼";
    }
}

</script>';

echo $OUTPUT->footer();
