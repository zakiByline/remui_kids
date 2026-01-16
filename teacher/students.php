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
 * Teacher's view of enrolled students
 *
 * @package   theme_remui_kids
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

// Require login and proper access.
require_login();
$context = context_system::instance();

// Check if user has teacher capabilities.
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher students page');
}

// Set up the page.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/students.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('All Students');
// Removed set_heading to prevent duplicate header

// Add a specific body class so we can safely scope page-specific CSS overrides
$PAGE->add_body_class('students-page');

// Add breadcrumb.
$PAGE->navbar->add('All Students');

// Get teacher's school (company) ID using helper function
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Get all courses where the current user is a teacher AND belongs to their school
$teachercourses = array();
$all_teacher_courses = enrol_get_my_courses('id, fullname, shortname', 'visible DESC, sortorder ASC');

foreach ($all_teacher_courses as $course) {
    // Check if this course belongs to teacher's company
    $course_company = $DB->get_record('company_course', array('courseid' => $course->id, 'companyid' => $teacher_company_id));
    if ($course_company || $teacher_company_id == 0) {
        $teachercourses[$course->id] = $course;
    }
}

// Start output.
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

// Teacher dashboard layout wrapper and sidebar (same as dashboard)
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

// Main content area next to sidebar
echo '<div class="teacher-main-content">';

// Page Header - With School Name
echo '<div class="students-page-header">';
echo '<h1 class="students-page-title">My Students';
if ($school_name) {
    echo ' <span style="color: #667eea; font-size: 0.7em;">(' . s($school_name) . ')</span>';
}
echo '</h1>';
echo '<p class="students-page-subtitle">Manage and view your enrolled students from ' . ($school_name ? s($school_name) : 'your school') . '</p>';
echo '</div>';

if (empty($teachercourses)) {
    echo '<div class="students-container">';
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-book"></i></div>';
    echo '<h3 class="empty-state-title">No Teaching Courses</h3>';
    echo '<p class="empty-state-text">You are not enrolled as a teacher in any courses yet.</p>';
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// Course Selector - Professional Dropdown
echo '<div class="course-selector">';
echo '<div class="course-dropdown-wrapper">';
echo '<label for="courseSelect" class="course-dropdown-label">Select Course</label>';
echo '<select id="courseSelect" class="course-dropdown" onchange="window.location.href=this.value">';
echo '<option value="">Choose a course...</option>';

$currentCourseId = optional_param('courseid', 0, PARAM_INT);
foreach ($teachercourses as $course) {
    $selected = ($currentCourseId == $course->id) ? 'selected' : '';
    $courseUrl = new moodle_url('/theme/remui_kids/teacher/students.php', array('courseid' => $course->id));
    echo '<option value="' . $courseUrl->out() . '" ' . $selected . '>' . s($course->fullname) . '</option>';
}

echo '</select>';
echo '</div>';
echo '</div>';

// Get the selected course.
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid) {
    $course = get_course($courseid);
    $context = context_course::instance($course->id);
    
    // Get only students from the SAME SCHOOL (company) as the teacher
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                (
                    SELECT c.name
                      FROM {cohort} c
                      JOIN {cohort_members} cm ON cm.cohortid = c.id
                     WHERE cm.userid = u.id
                     ORDER BY c.id ASC
                     LIMIT 1
                ) AS cohortname
           FROM {user} u
           JOIN {user_enrolments} ue ON ue.userid = u.id
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :contextlevel
           JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid";
    
    // Add company filter if teacher belongs to a school
    if ($teacher_company_id) {
        $sql .= " JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid";
    }
    
    $sql .= " WHERE e.courseid = :courseid
            AND r.shortname = :studentrole
            AND u.deleted = 0
            AND u.suspended = 0
       ORDER BY u.lastname ASC, u.firstname ASC";
    
    $params = [
            'courseid' => $courseid,
            'contextlevel' => CONTEXT_COURSE,
            'studentrole' => 'student'
    ];
    
    if ($teacher_company_id) {
        $params['companyid'] = $teacher_company_id;
    }
    
    $enrolledusers = $DB->get_records_sql($sql, $params);
    echo '<div class="students-container">';
    
    // Students Header - Removed to eliminate empty div
    
    if (empty($enrolledusers)) {
        echo '<div class="empty-state">';
        echo '<div class="empty-state-icon"><i class="fa fa-users"></i></div>';
        echo '<h3 class="empty-state-title">No Students Enrolled</h3>';
        echo '<p class="empty-state-text">There are no students enrolled in this course yet.</p>';
        echo '</div>';
    } else {
        // Section heading with selected course
        $coursetitle = format_string($course->fullname, true, ['context' => $context]);
        echo '<div class="students-table-heading">';
        echo '<h2 class="students-table-title">' . $coursetitle . '</h2>';
        echo '</div>';

        // Search and Filter Controls
        echo '<div class="students-controls">';
        echo '<div class="search-box">';
        echo '<span class="search-icon"><i class="fa fa-search"></i></span>';
        echo '<input type="text" class="search-input" placeholder="Search students..." id="studentSearch">';
        echo '</div>';
        echo '<div class="filter-buttons">';
        echo '<button class="filter-btn active" data-filter="all">All</button>';
        echo '<button class="filter-btn" data-filter="active">Active</button>';
        echo '<button class="filter-btn" data-filter="inactive">Inactive</button>';
        echo '</div>';
        echo '</div>';
        
        // Preload assignment submissions and quiz attempts per student.
        $assignmentcounts = [];
        $quizattemptcounts = [];

        $userids = array_keys($enrolledusers);
        if (!empty($userids)) {
            list($useridsql, $useridparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');

            // Assignment submissions (latest non-new submissions).
            $assignids = $DB->get_fieldset_select('assign', 'id', 'course = ?', [$courseid]);
            if (!empty($assignids)) {
                list($assignidsql, $assignidparams) = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'assignid');
                $assignparams = array_merge($useridparams, $assignidparams);
                $assignparams['newstatus'] = 'new';
                $assignrecords = $DB->get_records_sql("
                    SELECT userid, COUNT(*) AS submissioncount
                      FROM {assign_submission}
                     WHERE userid $useridsql
                       AND assignment $assignidsql
                       AND latest = 1
                       AND status <> :newstatus
                  GROUP BY userid
                ", $assignparams);
                foreach ($assignrecords as $record) {
                    $assignmentcounts[$record->userid] = (int)$record->submissioncount;
                }
            }

            // Quiz attempts (finished, non-preview attempts).
            $quizparams = $useridparams;
            $quizparams['quizcourse'] = $courseid;
            $quizparams['finishedstate'] = 'finished';
            $quizrecords = $DB->get_records_sql("
                SELECT qa.userid, COUNT(*) AS attemptcount
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                 WHERE qa.userid $useridsql
                   AND q.course = :quizcourse
                   AND qa.preview = 0
                   AND qa.state = :finishedstate
              GROUP BY qa.userid
            ", $quizparams);
            foreach ($quizrecords as $record) {
                $quizattemptcounts[$record->userid] = (int)$record->attemptcount;
            }
        }

        // Students Table
        echo '<div class="students-table-wrapper">';
        echo '<table class="students-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Full Name</th>';
        echo '<th>Email Address</th>';
        echo '<th>Grade Level</th>';
        echo '<th>Last Access</th>';
        echo '<th>Assignments</th>';
        echo '<th>Quizzes</th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($enrolledusers as $user) {
            $userlastaccess = $user->lastaccess ? userdate($user->lastaccess) : get_string('never');
            $lastAccessClass = $user->lastaccess ? 'last-access-recent' : 'last-access-never';
            $userInitials = strtoupper(substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1));
            $gradelevel = $user->cohortname ? $user->cohortname : 'N/A';
            $assignmentcount = $assignmentcounts[$user->id] ?? 0;
            $quizcount = $quizattemptcounts[$user->id] ?? 0;
            
            echo '<tr>';
            echo '<td class="student-name">';
            echo '<div class="student-avatar">' . $userInitials . '</div>';
            echo fullname($user);
            echo '</td>';
            echo '<td class="student-email">' . $user->email . '</td>';
            echo '<td class="student-grade">' . s($gradelevel) . '</td>';
            echo '<td class="last-access ' . $lastAccessClass . '">' . $userlastaccess . '</td>';
            echo '<td class="student-activity-counts">';
            echo '<span class="assignments-count"><i class="fa fa-file-text-o"></i> ' . $assignmentcount . '</span>';
            echo '</td>';
            echo '<td class="student-activity-counts">';
            echo '<span class="quizzes-count"><i class="fa fa-question-circle"></i> ' . $quizcount . '</span>';
            echo '</td>';
            echo '<td class="student-actions">';
            $reportUrl = new moodle_url('/theme/remui_kids/teacher/student_report.php', array('userid' => $user->id, 'courseid' => $courseid));
            echo '<a href="' . $reportUrl->out() . '" class="filter-btn" title="View Reports"><i class="fa fa-chart-line"></i> Reports</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        
        // Add JavaScript for search and filter functionality
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById("studentSearch");
            const filterButtons = document.querySelectorAll(".filter-btn");
            const tableRows = document.querySelectorAll(".students-table tbody tr");
            
            // Search functionality
            searchInput.addEventListener("input", function() {
                const searchTerm = this.value.toLowerCase();
                tableRows.forEach(row => {
                    const name = row.querySelector(".student-name").textContent.toLowerCase();
                    const email = row.querySelector(".student-email").textContent.toLowerCase();
                    if (name.includes(searchTerm) || email.includes(searchTerm)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                });
            });
            
            // Filter functionality
            filterButtons.forEach(button => {
                button.addEventListener("click", function() {
                    filterButtons.forEach(btn => btn.classList.remove("active"));
                    this.classList.add("active");
                    
                    const filter = this.dataset.filter;
                    tableRows.forEach(row => {
                        const lastAccess = row.querySelector(".last-access").textContent;
                        if (filter === "all") {
                            row.style.display = "";
                        } else if (filter === "active" && lastAccess !== "Never") {
                            row.style.display = "";
                        } else if (filter === "inactive" && lastAccess === "Never") {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    });
                });
            });
        });
        </script>';
    }
    
    echo '</div>'; // Close students-container
}

// Close main content and wrapper
echo '</div>'; // End teacher-main-content
echo '</div>'; // End teacher-dashboard-wrapper
echo '</div>'; // End teacher-css-wrapper
// Sidebar toggle script (reuse from dashboard template)
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
