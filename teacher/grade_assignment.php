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
 * Teacher Assignment Grading page - shows students for an assignment with submission status
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
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher assignment grading page');
}

// Get parameters
$cmid = required_param('id', PARAM_INT);

// Get course module and assignment
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$assignment = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

// Get group restrictions if any
$group_ids = [];
$assigned_to_groups = false;
if (!empty($cm->availability)) {
    $availability = json_decode($cm->availability, true);
    if ($availability && isset($availability['c']) && is_array($availability['c'])) {
        foreach ($availability['c'] as $condition) {
            if (isset($condition['type']) && $condition['type'] === 'group' && isset($condition['id'])) {
                $group_ids[] = $condition['id'];
                $assigned_to_groups = true;
            }
        }
    }
}

// Get students with submission status
$students = theme_remui_kids_get_assignment_students($assignment->id, $course->id);

// If assigned to groups, filter students by group membership
if ($assigned_to_groups && !empty($group_ids)) {
    $filtered_students = [];
    foreach ($students as $student) {
        // Check if student is in any of the assigned groups
        list($insql, $params) = $DB->get_in_or_equal($group_ids, SQL_PARAMS_NAMED);
        $params['userid'] = $student['id'];
        
        $is_member = $DB->record_exists_sql(
            "SELECT 1 FROM {groups_members} WHERE groupid $insql AND userid = :userid",
            $params
        );
        
        if ($is_member) {
            $filtered_students[] = $student;
        }
    }
    $students = $filtered_students;
}

// Sort students: graded first, then submitted, then others
usort($students, function($a, $b) {
    if ($a['submission_status'] === $b['submission_status']) {
        return 0;
    }
    
    if ($a['submission_status'] === 'Graded') {
        return -1;
    }
    if ($b['submission_status'] === 'Graded') {
        return 1;
    }
    
    if ($a['submission_status'] === 'Submitted') {
        return -1;
    }
    if ($b['submission_status'] === 'Submitted') {
        return 1;
    }
    
    return 0;
});

// Get groups information if applicable
$groups_info = [];
if ($assigned_to_groups && !empty($group_ids)) {
    list($insql, $params) = $DB->get_in_or_equal($group_ids, SQL_PARAMS_NAMED);
    $groups = $DB->get_records_sql(
        "SELECT id, name, description FROM {groups} WHERE id $insql",
        $params
    );
    
    foreach ($groups as $group) {
        $members = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email
             FROM {user} u
             JOIN {groups_members} gm ON gm.userid = u.id
             WHERE gm.groupid = ?
             ORDER BY u.lastname, u.firstname",
            [$group->id]
        );
        
        $groups_info[] = [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'member_count' => count($members)
        ];
    }
}

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/grade_assignment.php', ['id' => $cmid]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Grade Assignment: ' . format_string($assignment->name));
$PAGE->add_body_class('assignment-grading-page');

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

/* Teacher Dashboard Wrapper */
.teacher-css-wrapper {
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

/* Page Header */
.grading-page-header {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 8px 0;
}

.page-subtitle {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0 0 20px 0;
}

.grade-info-row {
    display: flex;
    gap: 32px;
    padding: 20px 0;
    border-top: 1px solid #e9ecef;
}

.grade-info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.grade-info-label {
    font-size: 12px;
    color: #6c757d;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.grade-info-value {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
}

/* Back Button */
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: white;
    color: #495057;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 1px solid #dee2e6;
    margin-bottom: 20px;
}

.btn-back:hover {
    background: #f8f9fa;
    color: #2c3e50;
    text-decoration: none;
    border-color: #adb5bd;
}

/* Groups Section */
.groups-section {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.groups-section-title {
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
}

.group-card {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 16px;
    transition: all 0.2s ease;
}

.group-card:hover {
    border-color: #3498db;
    background: white;
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.15);
}

.group-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.group-name {
    font-size: 15px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.group-member-count-badge {
    background: #3498db;
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.group-description {
    font-size: 13px;
    color: #6c757d;
    margin: 8px 0 0 0;
    line-height: 1.4;
}

/* Statistics */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 12px;
    margin-bottom: 12px;
    font-size: 24px;
}

.stat-icon.total {
    background: #e3f2fd;
    color: #1976d2;
}

.stat-icon.submitted {
    background: #d1fae5;
    color: #059669;
}

.stat-icon.not-submitted {
    background: #fee2e2;
    color: #dc2626;
}

.stat-icon.graded {
    background: #fef3c7;
    color: #d97706;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 4px 0;
}

.stat-label {
    color: #6c757d;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* Students Table */
.students-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

.students-table-wrapper {
    overflow-x: auto;
}

.students-table {
    width: 100%;
    border-collapse: collapse;
}

.students-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.students-table th {
    padding: 16px 20px;
    text-align: left;
    font-size: 13px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.students-table tbody tr {
    border-bottom: 1px solid #f1f3f5;
    transition: all 0.2s ease;
}

.students-table tbody tr:hover {
    background: #f8f9fa;
}

.students-table tbody tr:last-child {
    border-bottom: none;
}

.students-table td {
    padding: 16px 20px;
    vertical-align: middle;
}

/* Student Info */
.student-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

.student-details {
    flex: 1;
    min-width: 0;
}

.student-name {
    font-size: 15px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0 0 2px 0;
}

.student-email {
    font-size: 13px;
    color: #6c757d;
    margin: 0;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-graded {
    background: #d1fae5;
    color: #065f46;
}

.status-submitted {
    background: #dbeafe;
    color: #1e40af;
}

.status-draft {
    background: #fef3c7;
    color: #92400e;
}

.status-not-submitted {
    background: #f3f4f6;
    color: #6b7280;
}

/* Grade Display */
.grade-display {
    font-size: 16px;
    font-weight: 700;
    color: #2c3e50;
}

.grade-display.has-grade {
    color: #059669;
}

.grade-display .max-grade {
    font-size: 13px;
    color: #6c757d;
    font-weight: 500;
}

/* Submission Time */
.submission-time {
    font-size: 13px;
    color: #6c757d;
}

.submission-time i {
    margin-right: 6px;
    color: #9ca3af;
}

/* Action Button */
.btn-grade {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: #3498db;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
}

.btn-grade:hover {
    background: #2980b9;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
}

.btn-grade.graded {
    background: #059669;
}

.btn-grade.graded:hover {
    background: #047857;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.empty-state-title {
    font-size: 22px;
    font-weight: 600;
    color: #374151;
    margin: 0 0 8px 0;
}

.empty-state-text {
    font-size: 15px;
    color: #6b7280;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .students-table {
        font-size: 13px;
    }
    
    .students-table th,
    .students-table td {
        padding: 12px;
    }
}
</style>';

echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';
include(__DIR__ . '/includes/sidebar.php');

// Main Content
echo '<div class="teacher-main-content">';

// Back Button
echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/assignments.php" class="btn-back">';
echo '<i class="fa fa-arrow-left"></i> Back to Assignments';
echo '</a>';

// Header
echo '<div class="grading-page-header">';
echo '<h1 class="page-title">' . format_string($assignment->name) . '</h1>';
echo '<p class="page-subtitle">' . format_string($course->fullname) . '</p>';

// Grade info row
echo '<div class="grade-info-row">';
echo '<div class="grade-info-item">';
echo '<div class="grade-info-label">Maximum Grade</div>';
echo '<div class="grade-info-value">' . number_format($assignment->grade, 2) . ' points</div>';
echo '</div>';
echo '<div class="grade-info-item">';
echo '<div class="grade-info-label">Due Date</div>';
echo '<div class="grade-info-value">' . ($assignment->duedate ? userdate($assignment->duedate, '%d %b %Y') : 'No due date') . '</div>';
echo '</div>';
if ($assignment->allowsubmissionsfromdate > 0) {
    echo '<div class="grade-info-item">';
    echo '<div class="grade-info-label">Allow From</div>';
    echo '<div class="grade-info-value">' . userdate($assignment->allowsubmissionsfromdate, '%d %b %Y') . '</div>';
    echo '</div>';
}
if ($assignment->cutoffdate > 0) {
    echo '<div class="grade-info-item">';
    echo '<div class="grade-info-label">Cut-off Date</div>';
    echo '<div class="grade-info-value">' . userdate($assignment->cutoffdate, '%d %b %Y') . '</div>';
    echo '</div>';
}
echo '</div>';

echo '</div>'; // grading-page-header

// Groups Section (if assignment is assigned to groups)
if ($assigned_to_groups && !empty($groups_info)) {
    echo '<div class="groups-section">';
    echo '<h2 class="groups-section-title">';
    echo '<i class="fa fa-users"></i> Assigned Groups';
    echo '</h2>';
    echo '<div class="groups-grid">';
    
    foreach ($groups_info as $group) {
        echo '<div class="group-card">';
        echo '<div class="group-card-header">';
        echo '<h3 class="group-name">' . htmlspecialchars($group['name']) . '</h3>';
        echo '<span class="group-member-count-badge">' . $group['member_count'] . ' members</span>';
        echo '</div>';
        if (!empty($group['description'])) {
            $desc = strip_tags($group['description']);
            echo '<p class="group-description">' . htmlspecialchars(substr($desc, 0, 100)) . (strlen($desc) > 100 ? '...' : '') . '</p>';
        }
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
}

// Statistics
$total_students = count($students);
$submitted_count = 0;
$graded_count = 0;
$not_submitted_count = 0;

foreach ($students as $student) {
    if ($student['submission_status'] === 'Submitted' || $student['submission_status'] === 'Graded') {
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

// Total Students
echo '<div class="stat-card">';
echo '<div class="stat-icon total"><i class="fa fa-users"></i></div>';
echo '<div class="stat-value">' . $total_students . '</div>';
echo '<div class="stat-label">Total Students</div>';
echo '</div>';

// Submitted
echo '<div class="stat-card">';
echo '<div class="stat-icon submitted"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-value">' . $submitted_count . '</div>';
echo '<div class="stat-label">Submitted</div>';
echo '</div>';

// Not Submitted
echo '<div class="stat-card">';
echo '<div class="stat-icon not-submitted"><i class="fa fa-times-circle"></i></div>';
echo '<div class="stat-value">' . $not_submitted_count . '</div>';
echo '<div class="stat-label">Not Submitted</div>';
echo '</div>';

// Grading Progress
echo '<div class="stat-card">';
echo '<div class="stat-icon graded"><i class="fa fa-star"></i></div>';
echo '<div class="stat-value">' . $grading_progress . '%</div>';
echo '<div class="stat-label">Graded</div>';
echo '</div>';

echo '</div>'; // stats-grid

// Students list
echo '<div class="students-container">';

if (empty($students)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-users"></i></div>';
    echo '<h3 class="empty-state-title">No Students Found</h3>';
    echo '<p class="empty-state-text">No students are enrolled in this course' . ($assigned_to_groups ? ' or in the assigned groups' : '') . '.</p>';
    echo '</div>';
} else {
    echo '<div class="students-table-wrapper">';
    echo '<table class="students-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Student</th>';
    echo '<th>Email</th>';
    echo '<th>Status</th>';
    echo '<th>Grade</th>';
    echo '<th>Submitted On</th>';
    echo '<th>Action</th>';
    echo '</tr>';
    echo '</thead>';
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
                $status_badge_class .= 'status-submitted';
                break;
            case 'draft':
                $status_badge_class .= 'status-draft';
                break;
            default:
                $status_badge_class .= 'status-not-submitted';
                break;
        }
        
        echo '<tr>';
        
        // Student info
        echo '<td>';
        echo '<div class="student-info">';
        echo '<div class="student-avatar">' . $initials . '</div>';
        echo '<div class="student-details">';
        echo '<div class="student-name">' . format_string($student['fullname']) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</td>';
        
        // Email
        echo '<td><div class="student-email">' . htmlspecialchars($student['email']) . '</div></td>';
        
        // Status
        echo '<td><span class="' . $status_badge_class . '">';
        if ($student['graded']) {
            echo '<i class="fa fa-star"></i> Graded';
        } else if ($student['submission_status'] === 'Submitted') {
            echo '<i class="fa fa-check"></i> Submitted';
        } else if ($student['submission_status'] === 'Draft') {
            echo '<i class="fa fa-pencil"></i> Draft';
        } else {
            echo '<i class="fa fa-minus"></i> Not Submitted';
        }
        echo '</span></td>';
        
        // Grade
        echo '<td>';
        if ($student['graded'] && isset($student['grade'])) {
            echo '<div class="grade-display has-grade">';
            echo number_format($student['grade'], 2);
            echo '<span class="max-grade"> / ' . number_format($assignment->grade, 2) . '</span>';
            echo '</div>';
        } else {
            echo '<div class="grade-display">-</div>';
        }
        echo '</td>';
        
        // Submitted time
        echo '<td>';
        if (!empty($student['submitted_time_formatted']) && $student['submitted_time_formatted'] !== '-') {
            echo '<div class="submission-time">';
            echo '<i class="fa fa-clock"></i>';
            echo $student['submitted_time_formatted'];
            echo '</div>';
        } else {
            echo '<div class="submission-time">-</div>';
        }
        echo '</td>';
        
        // Action
        echo '<td>';
        $grade_url = new moodle_url('/mod/assign/view.php', [
            'id' => $cmid,
            'action' => 'grader',
            'userid' => $student['id']
        ]);
        
        if ($student['graded']) {
            echo '<a class="btn-grade graded" href="' . $grade_url->out() . '">';
            echo '<i class="fa fa-edit"></i> Edit Grade';
            echo '</a>';
        } else if ($student['submission_status'] === 'Submitted') {
            echo '<a class="btn-grade" href="' . $grade_url->out() . '">';
            echo '<i class="fa fa-star"></i> Grade Now';
            echo '</a>';
        } else {
            echo '<a class="btn-grade" href="' . $grade_url->out() . '" style="opacity: 0.6;">';
            echo '<i class="fa fa-eye"></i> View';
            echo '</a>';
        }
        echo '</td>';
        
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

echo '</div>'; // students-container

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

echo $OUTPUT->footer();