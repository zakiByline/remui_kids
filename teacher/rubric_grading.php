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
 * Teacher Rubric Grading page - shows students for an assignment with submission status
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
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher rubric grading page');
}

// Get parameters
$assignmentid = required_param('assignmentid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$activitytype = optional_param('activitytype', 'assign', PARAM_ALPHA);

// Get activity details based on type
if ($activitytype === 'codeeditor') {
    $assignment = $DB->get_record('codeeditor', ['id' => $assignmentid], '*', MUST_EXIST);
    $assignment->name = $assignment->name; // Ensure name field exists
} else {
    $assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
}
$course = get_course($courseid);

// Get students with submission status (function needs to handle both types)
$students = theme_remui_kids_get_assignment_students($assignmentid, $courseid, $activitytype);

// Sort students: graded first, then submitted, then others
usort($students, function($a, $b) {
    // If both have same submission status, maintain original order
    if ($a['submission_status'] === $b['submission_status']) {
        return 0;
    }
    
    // Graded students come first
    if ($a['submission_status'] === 'Graded') {
        return -1;
    }
    if ($b['submission_status'] === 'Graded') {
        return 1;
    }
    
    // Submitted students come second
    if ($a['submission_status'] === 'Submitted') {
        return -1;
    }
    if ($b['submission_status'] === 'Submitted') {
        return 1;
    }
    
    // For other statuses, maintain original order
    return 0;
});

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/rubric_grading.php', ['assignmentid' => $assignmentid, 'courseid' => $courseid]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Grade Assignment');
$PAGE->add_body_class('rubric-grading-page');

// Breadcrumb.
$PAGE->navbar->add('Rubrics', new moodle_url('/theme/remui_kids/teacher/rubrics.php'));
$PAGE->navbar->add('Grade Assignment');

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
</style>';

echo '<div class="teacher-css-wrapper">';
// Layout wrapper and sidebar
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

// Header
$activity_label = ($activitytype === 'codeeditor') ? 'Code Editor' : 'Assignment';
echo '<div class="students-page-header">';
echo '<h1 class="students-page-title">' . format_string($assignment->name) . '</h1>';
echo '<p class="students-page-subtitle">' . format_string($course->fullname) . ' - Grade ' . $activity_label . ' submissions</p>';
echo '<a href="' . (new moodle_url('/theme/remui_kids/teacher/rubrics.php'))->out() . '" class="filter-btn" style="margin-top:12px;">← Back to Rubrics</a>';
echo '</div>';

// Statistics cards
$total_students = count($students);
$submitted_count = 0;
$graded_count = 0;
$not_submitted_count = 0;

foreach ($students as $student) {
    if ($student['submission_status'] === 'Submitted') {
        $submitted_count++;
    } else if ($student['submission_status'] === 'Not submitted') {
        $not_submitted_count++;
    }
    if ($student['graded']) {
        $graded_count++;
    }
}

$grading_progress = ($total_students > 0) ? round(($graded_count / $total_students) * 100, 1) : 0;

echo '<div class="stats-grid">';

// Card 1: Total Students
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-users"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $total_students . '</div>';
echo '<div class="stat-label">Total Students</div>';
echo '</div>';
echo '</div>';

// Card 2: Submitted
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $submitted_count . '</div>';
echo '<div class="stat-label">Submitted</div>';
echo '</div>';
echo '</div>';

// Card 3: Not Submitted
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-times-circle"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $not_submitted_count . '</div>';
echo '<div class="stat-label">Not Submitted</div>';
echo '</div>';
echo '</div>';

// Card 4: Grading Progress
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-star"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $grading_progress . '%</div>';
echo '<div class="stat-label">Graded</div>';
echo '</div>';
echo '</div>';

echo '</div>'; // stats-grid

// Students list
echo '<div class="students-container">';

if (empty($students)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-users"></i></div>';
    echo '<h3 class="empty-state-title">No Students Found</h3>';
    echo '<p class="empty-state-text">No students are enrolled in this course yet.</p>';
    echo '</div>';
} else {
    echo '<div class="students-table-wrapper">';
    echo '<table class="students-table">';
    echo '<thead><tr><th>Student</th><th>Email</th><th>Submission Status</th><th>Submitted On</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($students as $student) {
        // Get initials for avatar
        $initials = strtoupper(substr($student['firstname'], 0, 1) . substr($student['lastname'], 0, 1));
        
        // Status badge styling
        $status_badge_class = 'status-badge ';
        switch ($student['status_class']) {
            case 'graded':
                $status_badge_class .= 'status-graded';
                break;
            case 'submitted':
                $status_badge_class .= 'status-success';
                break;
            case 'draft':
                $status_badge_class .= 'status-warning';
                break;
            default:
                $status_badge_class .= 'status-default';
                break;
        }
        
        echo '<tr>';
        echo '<td class="student-name"><div class="student-avatar">' . $initials . '</div>' . format_string($student['fullname']) . '</td>';
        echo '<td class="student-email">' . $student['email'] . '</td>';
        echo '<td><span class="' . $status_badge_class . '">' . $student['submission_status'] . '</span></td>';
        echo '<td>' . $student['submitted_time_formatted'] . '</td>';
        echo '<td>';
        
        // Link to appropriate grading page based on activity type
        if ($activitytype === 'codeeditor') {
            // For code editor, use our custom grading page
            $grade_url = new moodle_url('/theme/remui_kids/teacher/grade_codeeditor_student.php', [
                'codeeditorid' => $assignmentid,
                'courseid' => $courseid,
                'studentid' => $student['id']
            ]);
        } else {
            // For assignment, use grade_student.php
            $grade_url = new moodle_url('/theme/remui_kids/teacher/grade_student.php', [
                'assignmentid' => $assignmentid,
                'courseid' => $courseid,
                'studentid' => $student['id']
            ]);
        }
        echo '<a class="filter-btn" href="' . $grade_url->out() . '">Grade</a>';
        
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
</script>';

// Additional styles for status badges
echo '<style>
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.status-graded {
    background: #dbeafe;
    color: #1e40af;
}
.status-success {
    background: #d1fae5;
    color: #065f46;
}
.status-warning {
    background: #fef3c7;
    color: #92400e;
}
.status-default {
    background: #f3f4f6;
    color: #374151;
}
.muted-text {
    color: #9ca3af;
    font-size: 14px;
}
</style>';

echo $OUTPUT->footer();

