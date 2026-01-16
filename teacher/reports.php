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
 * Teacher reports hub.
 *
 * @package   theme_remui_kids
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

require_login();
$context = context_system::instance();

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher reports page');
}

require_once($CFG->dirroot . '/lib/enrollib.php');

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/reports.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Reports');
$PAGE->add_body_class('teacher-reports');
$PAGE->navbar->add('Reports');

$selectedcourseid = optional_param('courseid', 0, PARAM_INT);

// Get only courses from teacher's school
$teachercourses = theme_remui_kids_get_teacher_school_courses($USER->id, $teacher_company_id);

// Fallback: If no courses found with company filter, get all teacher courses
if (empty($teachercourses)) {
$teachercourses = enrol_get_my_courses('id, fullname, shortname', 'fullname ASC');
}

// Ensure courses are indexed by ID and sorted by fullname
$sorted_courses = [];
foreach ($teachercourses as $courseid => $course) {
    // Handle both array key and object property access
    $id = is_object($course) ? $course->id : $courseid;
    $fullname = is_object($course) ? $course->fullname : (isset($course['fullname']) ? $course['fullname'] : '');
    
    // Skip if missing required data
    if (empty($id) || empty($fullname)) {
        continue;
    }
    
    $sorted_courses[$id] = [
        'id' => (int)$id,
        'fullname' => $fullname,
        'course' => $course
    ];
}

// Sort by fullname
usort($sorted_courses, function($a, $b) {
    return strcmp($a['fullname'], $b['fullname']);
});

// Build course ID lookup array for validation
$valid_course_ids = [];
foreach ($sorted_courses as $course_data) {
    $valid_course_ids[$course_data['id']] = true;
}

// Validate selected course - only reset if it's truly invalid
if ($selectedcourseid > 0 && !isset($valid_course_ids[$selectedcourseid])) {
    // If selected course is not in filtered list, reset to 0
    $selectedcourseid = 0;
}

// Re-index teachercourses by course ID for report functions
$teachercourses = [];
foreach ($sorted_courses as $course_data) {
    $teachercourses[$course_data['id']] = $course_data['course'];
}

// Build course options for dropdown
$coursesforselect = [];
foreach ($sorted_courses as $course_data) {
    $coursesforselect[] = [
        'id' => $course_data['id'],
        'label' => format_string($course_data['fullname']),
        'selected' => ($selectedcourseid == $course_data['id'])
    ];
}

// Helper function to filter students by school in report data
$filter_students_by_school = function($students, $courseid) use ($teacher_company_id, $DB) {
    if (empty($students) || !$teacher_company_id) {
        return $students;
    }
    
    // Use helper function for better filtering
    $filtered = [];
    foreach ($students as $key => $student) {
        $student_id = is_object($student) ? $student->id : (isset($student['id']) ? $student['id'] : $key);
        
        // Get students from same school using helper
        $course_students = theme_remui_kids_get_course_students_by_school($courseid, $teacher_company_id);
        $course_students = theme_remui_kids_filter_out_admins($course_students, $courseid);
        
        // Check if this student is in the filtered list
        $found = false;
        foreach ($course_students as $filtered_student) {
            if ($filtered_student->id == $student_id) {
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $filtered[$key] = $student;
}
    }
    
    return $filtered;
};

$reportdata = null;
$assignmentdata = null;
$recent_submissions = null;
$top_assignment_students = null;
$quizdata = null;
$recent_quiz_completions = null;
$top_quiz_students = null;
if ($selectedcourseid) {
    $reportdata = theme_remui_kids_get_teacher_competency_report($selectedcourseid);
    
    // Filter students by school in report data
    if (!empty($reportdata['students'])) {
        $reportdata['students'] = $filter_students_by_school($reportdata['students'], $selectedcourseid);
        
        // Recalculate student count after filtering
        $filtered_student_count = count($reportdata['students']);
        
        // Update the stats.studentcount in report data
        if (isset($reportdata['stats'])) {
            $reportdata['stats']['studentcount'] = $filtered_student_count;
        } else {
            $reportdata['stats'] = ['studentcount' => $filtered_student_count];
        }
        
        // Also update class average calculation if needed
        // Recalculate class average based on filtered students
        if (!empty($reportdata['stats']['class_average']) && $filtered_student_count > 0) {
            $total_competent_percent = 0;
            $students_with_competencies = 0;
            foreach ($reportdata['students'] as $student) {
                if (isset($student['competent_percent']) && $student['total'] > 0) {
                    $total_competent_percent += $student['competent_percent'];
                    $students_with_competencies++;
                }
            }
            if ($students_with_competencies > 0) {
                $reportdata['stats']['class_average'] = round($total_competent_percent / $students_with_competencies, 1);
            }
        }
    } else {
        // If no students in report, get filtered count directly
        $filtered_student_count = theme_remui_kids_count_course_students_by_school($selectedcourseid, $teacher_company_id);
        if (isset($reportdata['stats'])) {
            $reportdata['stats']['studentcount'] = $filtered_student_count;
        } else {
            $reportdata['stats'] = ['studentcount' => $filtered_student_count];
        }
    }
    
    $assignmentdata = theme_remui_kids_get_assignment_analytics($selectedcourseid);
    
    // Get filtered student count for assignments
    $filtered_assignment_student_count = theme_remui_kids_count_course_students_by_school($selectedcourseid, $teacher_company_id);
    
    // Update enrolled_students count in assignment data
    if (!empty($assignmentdata['assignments'])) {
        foreach ($assignmentdata['assignments'] as &$assignment) {
            if (isset($assignment['enrolled_students'])) {
                $assignment['enrolled_students'] = $filtered_assignment_student_count;
                // Recalculate submission rate if needed
                if ($filtered_assignment_student_count > 0 && isset($assignment['submission_count'])) {
                    $assignment['submission_rate'] = round(($assignment['submission_count'] / $filtered_assignment_student_count) * 100, 1);
                }
            }
        }
        unset($assignment); // Break reference
    }
    
    $recent_submissions = theme_remui_kids_get_recent_assignment_submissions(10, $selectedcourseid);
    $top_assignment_students = theme_remui_kids_get_top_assignment_students($selectedcourseid, 5);
    
    // Filter assignment students by school
    if (!empty($top_assignment_students['students'])) {
        $top_assignment_students['students'] = $filter_students_by_school($top_assignment_students['students'], $selectedcourseid);
    }
    
    $quizdata = theme_remui_kids_get_quiz_analytics($selectedcourseid);
    
    // Get filtered student count for quizzes
    $filtered_quiz_student_count = theme_remui_kids_count_course_students_by_school($selectedcourseid, $teacher_company_id);
    
    // Update enrolled_students count in quiz data
    if (!empty($quizdata['quizzes'])) {
        foreach ($quizdata['quizzes'] as &$quiz) {
            if (isset($quiz['enrolled_students'])) {
                $quiz['enrolled_students'] = $filtered_quiz_student_count;
                // Recalculate completion rate if needed
                if ($filtered_quiz_student_count > 0 && isset($quiz['completion_count'])) {
                    $quiz['completion_rate'] = round(($quiz['completion_count'] / $filtered_quiz_student_count) * 100, 1);
                }
            }
        }
        unset($quiz); // Break reference
    }
    
    $recent_quiz_completions = theme_remui_kids_get_recent_quiz_completions($selectedcourseid, 10);
    $top_quiz_students = theme_remui_kids_get_top_quiz_students($selectedcourseid, 5);
    
    // Filter quiz students by school
    if (!empty($top_quiz_students['students'])) {
        $top_quiz_students['students'] = $filter_students_by_school($top_quiz_students['students'], $selectedcourseid);
    }
}

$chartRadar = $reportdata['radar'] ?? ['labels' => [], 'values' => []];
$chartStudent = $reportdata['student_chart'] ?? ['labels' => [], 'student_values' => [], 'class_values' => []];
$frameworkOptions = [];
if (!empty($reportdata['framework_competencies'])) {
    foreach ($reportdata['framework_competencies'] as $index => $framework) {
        $frameworkOptions[] = [
            'id' => $framework['id'],
            'name' => $framework['name'],
            'selected' => $index === 0
        ];
    }
}

// Capture sidebar HTML from sidebar.php
ob_start();
include($CFG->dirroot . '/theme/remui_kids/teacher/includes/sidebar.php');
$sidebar_html = ob_get_clean();

$overviewbeststudents = ['students' => [], 'has_data' => false];
$overviewworststudents = ['students' => [], 'has_data' => false];
if (!empty($reportdata) && $selectedcourseid) {
    $overviewbeststudents = theme_remui_kids_get_best_performing_students($selectedcourseid, 4);
    $overviewworststudents = theme_remui_kids_get_best_performing_students($selectedcourseid, 4, 'asc');
    
    // Filter best/worst students by school
    if (!empty($overviewbeststudents['students'])) {
        $overviewbeststudents['students'] = $filter_students_by_school($overviewbeststudents['students'], $selectedcourseid);
        $overviewbeststudents['has_data'] = !empty($overviewbeststudents['students']);
    }
    
    if (!empty($overviewworststudents['students'])) {
        $overviewworststudents['students'] = $filter_students_by_school($overviewworststudents['students'], $selectedcourseid);
        $overviewworststudents['has_data'] = !empty($overviewworststudents['students']);
    }
}

// Build course select URL with current selected course
$course_select_url = new moodle_url('/theme/remui_kids/teacher/reports.php');
if ($selectedcourseid > 0) {
    $course_select_url->param('courseid', $selectedcourseid);
}

$templatecontext = [
    'config' => ['wwwroot' => $CFG->wwwroot],
    'sidebar_html' => $sidebar_html,
    'school_name' => $school_name,
    'selected_course_id' => $selectedcourseid,
    'has_courses' => !empty($coursesforselect),
    'courses' => $coursesforselect,
    'course_select_action' => $course_select_url->out(false),
    'has_course_selected' => ($selectedcourseid > 0 && !empty($reportdata)),
    'has_students' => !empty($reportdata['students']),
    'report' => $reportdata ?: [],
    'assignment_data' => $assignmentdata ?: ['assignments' => [], 'has_data' => false],
    'overview_best_students' => $overviewbeststudents,
    'overview_worst_students' => $overviewworststudents,
    'has_student_performance_cards' => ($overviewbeststudents['has_data'] || $overviewworststudents['has_data']),
    'chartdata_radar' => json_encode($chartRadar),
    'chartdata_student' => json_encode($chartStudent),
    'framework_competency_json' => json_encode($reportdata['framework_competencies'] ?? []),
    'framework_options' => $frameworkOptions,
    'has_framework_options' => !empty($frameworkOptions),
    'framework_student_chart_json' => json_encode($reportdata['framework_student_charts'] ?? []),
    'framework_activities_charts_json' => json_encode($reportdata['framework_activities_charts'] ?? []),
    'assignment_data_json' => json_encode($assignmentdata ?: ['assignments' => [], 'has_data' => false]),
    'recent_assignment_submissions' => $recent_submissions,
    'top_assignment_students' => $top_assignment_students ?: ['students' => [], 'has_data' => false],
    'quiz_data' => $quizdata ?: ['quizzes' => [], 'has_data' => false],
    'recent_quiz_completions' => $recent_quiz_completions,
    'top_quiz_students' => $top_quiz_students ?: ['students' => [], 'has_data' => false],
    'quiz_data_json' => json_encode($quizdata ?: ['quizzes' => [], 'has_data' => false]),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/teacher_reports', $templatecontext);
echo $OUTPUT->footer();

