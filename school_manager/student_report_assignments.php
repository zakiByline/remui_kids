<?php
/**
 * Student Report - Assignment Submission Report Tab (AJAX fragment)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
         FROM {company} c
         JOIN {company_users} cu ON c.id = cu.companyid
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.*
             FROM {company} c
             JOIN {company_users} cu ON c.id = cu.companyid
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'assignments']);
    redirect($target);
}

// Initialize data arrays
$assignment_data = [];
$summary_stats = [
    'total_assignments' => 0,
    'submitted_assignments' => 0,
    'not_submitted_assignments' => 0,
    'late_submissions' => 0,
    'graded_assignments' => 0,
    'ungraded_assignments' => 0,
    'on_time_submissions' => 0
];

$submission_status_distribution = [
    'submitted' => 0,
    'not_submitted' => 0,
    'late' => 0
];

$grading_status_distribution = [
    'graded' => 0,
    'ungraded' => 0
];

$score_distribution = [
    'excellent' => 0,  // 90-100%
    'good' => 0,       // 70-89%
    'average' => 0,    // 50-69%
    'poor' => 0        // 0-49%
];

$assignment_completion_status = [
    'completed' => 0,
    'not_completed' => 0,
    'overdue' => 0
];

$daily_submissions = [];
$days_labels = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily_submissions[$day] = ['submitted' => 0, 'late' => 0];
    $days_labels[] = $day;
}

if ($company_info) {
    // Get all assignments in company courses
    $all_assignments = $DB->get_records_sql(
        "SELECT DISTINCT a.id, a.name, a.course, a.duedate, a.allowsubmissionsfromdate,
                a.cutoffdate, a.grade, c.fullname as course_name, c.shortname as course_shortname
         FROM {assign} a
         INNER JOIN {course} c ON c.id = a.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ?
         AND c.id > 1
         ORDER BY a.duedate DESC",
        [$company_info->id]
    );
    
    // Get all students
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                uifd.data as grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id]
    );
    
    $summary_stats['total_assignments'] = count($all_assignments);
    
    // Build assignment overview (per assignment statistics)
    $assignment_overview = [
        'total_assignments' => count($all_assignments),
        'assignments_list' => []
    ];
    
    foreach ($all_assignments as $assignment) {
        // Get total students enrolled in the course
        $total_assign_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {user_enrolments} ue
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = ue.userid
             WHERE e.courseid = ?
             AND ue.status = 0
             AND e.status = 0
             AND cu.companyid = ?",
            [$assignment->course, $company_info->id]
        );
        
        // Count completed submissions
        $completed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT s.userid)
             FROM {assign_submission} s
             INNER JOIN {user_enrolments} ue ON ue.userid = s.userid
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = s.userid
             WHERE s.assignment = ?
             AND s.status IN ('submitted', 'draft')
             AND e.courseid = ?
             AND ue.status = 0
             AND cu.companyid = ?",
            [$assignment->id, $assignment->course, $company_info->id]
        );
        
        $incomplete_students = max(0, $total_assign_students - $completed);
        
        // Get average grade for this assignment
        $avg_grade = 0;
        $graded_count = $DB->count_records_sql(
            "SELECT COUNT(ag.id)
             FROM {assign_grades} ag
             INNER JOIN {user_enrolments} ue ON ue.userid = ag.userid
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = ag.userid
             WHERE ag.assignment = ?
             AND ag.grade IS NOT NULL
             AND e.courseid = ?
             AND ue.status = 0
             AND cu.companyid = ?",
            [$assignment->id, $assignment->course, $company_info->id]
        );
        
        if ($graded_count > 0 && $assignment->grade > 0) {
            $grade_sum = $DB->get_field_sql(
                "SELECT SUM(ag.grade)
                 FROM {assign_grades} ag
                 INNER JOIN {user_enrolments} ue ON ue.userid = ag.userid
                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                 INNER JOIN {company_users} cu ON cu.userid = ag.userid
                 WHERE ag.assignment = ?
                 AND ag.grade IS NOT NULL
                 AND e.courseid = ?
                 AND ue.status = 0
                 AND cu.companyid = ?",
                [$assignment->id, $assignment->course, $company_info->id]
            );
            if ($grade_sum) {
                $avg_grade = round(($grade_sum / $graded_count / $assignment->grade) * 100, 1);
            }
        }
        
        // Get teacher name who created the assignment (from course context)
        $teacher_name = 'N/A';
        $course_teacher = $DB->get_record_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
             FROM {context} ctx
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {user} u ON u.id = ra.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             WHERE ctx.contextlevel = 50 AND ctx.instanceid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cu.companyid = ?
             AND u.deleted = 0 AND u.suspended = 0
             ORDER BY u.lastname, u.firstname
             LIMIT 1",
            [$assignment->course, $company_info->id]
        );
        
        if ($course_teacher) {
            $teacher_name = fullname($course_teacher);
        }
        
        $assignment_overview['assignments_list'][] = [
            'id' => $assignment->id,
            'name' => $assignment->name,
            'course' => $assignment->course_name,
            'created_by' => $teacher_name,
            'total_assign_students' => $total_assign_students,
            'completed' => $completed,
            'incomplete_students' => $incomplete_students,
            'avg_grade' => $avg_grade,
            'max_grade' => round($assignment->grade, 1),
            'duedate' => $assignment->duedate,
            'allowsubmissionsfromdate' => $assignment->allowsubmissionsfromdate,
            'cutoffdate' => $assignment->cutoffdate
        ];
    }
    
    // Process each student's assignment submissions
    foreach ($students as $student) {
        $student_assignments = [];
        $student_submitted = 0;
        $student_not_submitted = 0;
        $student_late = 0;
        $student_graded = 0;
        $student_ungraded = 0;
        $student_on_time = 0;
        
        foreach ($all_assignments as $assignment) {
            // Check if student is enrolled in the course
            $is_enrolled = $DB->record_exists_sql(
                "SELECT 1
                 FROM {user_enrolments} ue
                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = ?
                 AND e.courseid = ?
                 AND ue.status = 0",
                [$student->id, $assignment->course]
            );
            
            if (!$is_enrolled) {
                continue;
            }
            
            // Get submission
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assignment->id,
                'userid' => $student->id
            ]);
            
            // Get grade
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $assignment->id,
                'userid' => $student->id
            ]);
            
            $is_submitted = false;
            $is_late = false;
            $is_graded = false;
            $submission_date = null;
            $grade_value = null;
            $grade_percentage = null;
            
            if ($submission && in_array($submission->status, ['submitted', 'draft'])) {
                $is_submitted = true;
                $submission_date = $submission->timemodified;
                
                // Check if late
                if ($assignment->duedate > 0 && $submission->timemodified > $assignment->duedate) {
                    $is_late = true;
                    $student_late++;
                    $summary_stats['late_submissions']++;
                    $submission_status_distribution['late']++;
                } else {
                    $student_on_time++;
                    $summary_stats['on_time_submissions']++;
                    $submission_status_distribution['submitted']++;
                }
                
                $student_submitted++;
                $summary_stats['submitted_assignments']++;
                
                // Track daily submissions
                $sub_date = date('Y-m-d', $submission->timemodified);
                if (isset($daily_submissions[$sub_date])) {
                    if ($is_late) {
                        $daily_submissions[$sub_date]['late']++;
                    } else {
                        $daily_submissions[$sub_date]['submitted']++;
                    }
                }
            } else {
                $student_not_submitted++;
                $summary_stats['not_submitted_assignments']++;
                $submission_status_distribution['not_submitted']++;
            }
            
            if ($grade && $grade->grade !== null) {
                $is_graded = true;
                $grade_value = $grade->grade;
                if ($assignment->grade > 0) {
                    $grade_percentage = round(($grade->grade / $assignment->grade) * 100, 2);
                    
                    // Track score distribution
                    if ($grade_percentage >= 90) {
                        $score_distribution['excellent']++;
                    } elseif ($grade_percentage >= 70) {
                        $score_distribution['good']++;
                    } elseif ($grade_percentage >= 50) {
                        $score_distribution['average']++;
                    } else {
                        $score_distribution['poor']++;
                    }
                }
                $student_graded++;
                $summary_stats['graded_assignments']++;
                $grading_status_distribution['graded']++;
            } else {
                if ($is_submitted) {
                    $student_ungraded++;
                    $summary_stats['ungraded_assignments']++;
                    $grading_status_distribution['ungraded']++;
                }
            }
            
            $student_assignments[] = [
                'assignment_id' => $assignment->id,
                'assignment_name' => $assignment->name,
                'course_name' => $assignment->course_name,
                'duedate' => $assignment->duedate,
                'is_submitted' => $is_submitted,
                'is_late' => $is_late,
                'is_graded' => $is_graded,
                'submission_date' => $submission_date,
                'grade_value' => $grade_value,
                'grade_percentage' => $grade_percentage,
                'max_grade' => $assignment->grade
            ];
        }
        
        if (!empty($student_assignments)) {
            // Calculate highest, lowest, and average scores
            $scores = array_filter(array_column($student_assignments, 'grade_percentage'), function($score) {
                return $score !== null && $score > 0;
            });
            
            $highest_score = !empty($scores) ? max($scores) : 0;
            $lowest_score = !empty($scores) ? min($scores) : 0;
            $avg_score = !empty($scores) ? round(array_sum($scores) / count($scores), 2) : 0;
            
            // Count unique completed assignments
            $completed_assignments = [];
            foreach ($student_assignments as $assign) {
                if ($assign['is_submitted']) {
                    $completed_assignments[$assign['assignment_id']] = true;
                }
            }
            $completed_count = count($completed_assignments);
            $incompleted_count = max(0, count($student_assignments) - $completed_count);
            
            // Calculate average completion rate
            $avg_completion_rate = count($student_assignments) > 0 ? round(($completed_count / count($student_assignments)) * 100, 1) : 0;
            
            $assignment_data[] = [
                'student_id' => $student->id,
                'student_name' => fullname($student),
                'student_email' => $student->email,
                'grade_level' => $student->grade_level ?? 'N/A',
                'total_assigned_assignments' => count($student_assignments),
                'completed_count' => $completed_count,
                'incompleted_count' => $incompleted_count,
                'highest_score' => $highest_score,
                'lowest_score' => $lowest_score,
                'avg_score' => $avg_score,
                'avg_completion_rate' => $avg_completion_rate,
                'submitted_count' => $student_submitted,
                'not_submitted_count' => $student_not_submitted,
                'late_count' => $student_late,
                'on_time_count' => $student_on_time,
                'graded_count' => $student_graded,
                'ungraded_count' => $student_ungraded,
                'submission_rate' => count($student_assignments) > 0 ? round(($student_submitted / count($student_assignments)) * 100, 2) : 0,
                'assignments' => $student_assignments
            ];
        }
    }
    
    // Calculate Assignment Completion Status based on summary card data
    // Count completed submissions (submitted assignments)
    $completed_submissions_count = $summary_stats['submitted_assignments'];
    
    // Use summary stats data to match the summary cards
    // Completed: Number of submitted assignments
    $assignment_completion_status['completed'] = $completed_submissions_count;
    
    // Not Completed: Assignments not submitted (from summary stats)
    $assignment_completion_status['not_completed'] = $summary_stats['not_submitted_assignments'];
    
    // Overdue: Late submissions count
    $assignment_completion_status['overdue'] = $summary_stats['late_submissions'];
}

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.assignments-container {
    padding: 0;
}

.assignments-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.assignments-summary-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.7));
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
    min-height: 100px;
}

.assignments-summary-card .summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: #fff;
}

.assignments-summary-card .summary-content h4 {
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    margin: 0 0 6px 0;
    color: #475569;
    text-transform: uppercase;
}

.assignments-summary-card .summary-content .value {
    font-size: 2rem;
    font-weight: 800;
    margin: 0 0 6px 0;
    color: #0f172a;
}

.assignments-summary-card .summary-content .subtitle {
    margin: 0;
    font-size: 0.85rem;
    color: #6b7280;
}

.assignments-summary-card.card-total-assignments {
    background: linear-gradient(135deg, #fff5e6, #ffe9cc);
    border: 2px solid #f59e0b;
}
.assignments-summary-card.card-total-assignments .summary-icon {
    background: #f59e0b;
}

.assignments-summary-card.card-total-students {
    background: linear-gradient(135deg, #e0f2ff, #f2f7ff);
    border: 2px solid #3b82f6;
}
.assignments-summary-card.card-total-students .summary-icon {
    background: #3b82f6;
}

.assignments-summary-card.card-total-submissions {
    background: linear-gradient(135deg, #e6fff7, #f2fff6);
    border: 2px solid #10b981;
}
.assignments-summary-card.card-total-submissions .summary-icon {
    background: #10b981;
}

.assignments-summary-card.card-not-submitted {
    background: linear-gradient(135deg, #ffeaea, #fff5f5);
    border: 2px solid #ef4444;
}
.assignments-summary-card.card-not-submitted .summary-icon {
    background: #ef4444;
}

.assignments-summary-card.card-avg-score {
    background: linear-gradient(135deg, #f6f0ff, #faf5ff);
    border: 2px solid #8b5cf6;
}
.assignments-summary-card.card-avg-score .summary-icon {
    background: #8b5cf6;
}

.assignments-charts {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.assignments-chart-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.assignments-chart-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.assignments-chart-title i {
    color: #3b82f6;
}

.assignments-chart-canvas {
    position: relative;
    height: 300px;
    min-height: 300px;
    width: 100%;
    position: relative;
    min-height: 300px;
    max-height: 400px;
    width: 100%;
}

.assignments-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.assignments-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.assignments-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.assignments-table-title i {
    color: #3b82f6;
}

.assignments-search-container {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.assignments-search-input {
    width: 100%;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
}

.assignments-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.assignments-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.assignments-clear-btn {
    padding: 8px 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: 6px;
}

.assignments-clear-btn:hover {
    background: #dc2626;
}

.assignments-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.assignments-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    min-width: 1200px;
}

.assignments-score-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.assignments-score-badge.excellent { background: #d1fae5; color: #065f46; }
.assignments-score-badge.good { background: #dbeafe; color: #1e40af; }
.assignments-score-badge.average { background: #fef3c7; color: #92400e; }
.assignments-score-badge.poor { background: #fee2e2; color: #991b1b; }

.assignments-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.assignments-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.assignments-table th.center {
    text-align: center;
}

.assignments-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
}

.assignments-table tbody tr:hover {
    background: #f9fafb;
}

.assignments-table td {
    padding: 12px;
    color: #1f2937;
}

.assignments-table td.center {
    text-align: center;
}

.assignments-status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
}

.assignments-status-badge.submitted {
    background: #d1fae5;
    color: #065f46;
}

.assignments-status-badge.not-submitted {
    background: #fee2e2;
    color: #991b1b;
}

.assignments-status-badge.late {
    background: #fef3c7;
    color: #92400e;
}

.assignments-status-badge.graded {
    background: #dbeafe;
    color: #1e40af;
}

.assignments-status-badge.ungraded {
    background: #f3f4f6;
    color: #6b7280;
}

.assignments-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.assignments-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.assignments-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.assignments-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.assignments-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.assignments-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.assignments-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.assignments-page-number {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 40px;
    text-align: center;
    transition: all 0.2s;
}

.assignments-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.assignments-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.assignments-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.assignments-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

.assignments-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.assignments-empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
    display: block;
}

@media (max-width: 1200px) {
    .assignments-charts {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .assignments-charts {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .assignments-summary-cards {
        grid-template-columns: 1fr;
    }
}

/* Assignment Details Pagination */
.assignment-details-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.assignment-details-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.assignment-details-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

.assignment-details-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.assignment-details-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.assignment-details-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.assignment-details-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.assignment-details-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.assignment-details-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.assignment-details-page-number {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 40px;
    text-align: center;
    transition: all 0.2s;
}

.assignment-details-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.assignment-details-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}
</style>

<div class="assignments-container">
    <!-- Summary Cards -->
    <div class="assignments-summary-cards">
        <div class="assignments-summary-card card-total-assignments">
            <div class="summary-icon">
                <i class="fa fa-clipboard-list"></i>
            </div>
            <div class="summary-content">
                <h4>Total Create Assignment</h4>
                <div class="value"><?php echo number_format($summary_stats['total_assignments']); ?></div>
                <p class="subtitle">Total assignments created in courses</p>
            </div>
        </div>
        <div class="assignments-summary-card card-total-students">
            <div class="summary-icon">
                <i class="fa fa-user-graduate"></i>
            </div>
            <div class="summary-content">
                <h4>Total Assignment Assign Student</h4>
                <div class="value"><?php 
                    // Count total unique student-assignment pairs
                    $total_students_assigned = 0;
                    foreach ($assignment_overview['assignments_list'] as $assign) {
                        $total_students_assigned += $assign['total_assign_students'];
                    }
                    echo number_format($total_students_assigned);
                ?></div>
                <p class="subtitle">Students assigned to assignments</p>
            </div>
        </div>
        <div class="assignments-summary-card card-total-submissions">
            <div class="summary-icon">
                <i class="fa fa-chart-line"></i>
            </div>
            <div class="summary-content">
                <h4>Total Submission</h4>
                <div class="value"><?php echo number_format($summary_stats['submitted_assignments']); ?></div>
                <p class="subtitle">All assignment submissions by students</p>
            </div>
        </div>
        <div class="assignments-summary-card card-not-submitted">
            <div class="summary-icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <div class="summary-content">
                <h4>Not Submissions</h4>
                <div class="value"><?php echo number_format($summary_stats['not_submitted_assignments']); ?></div>
                <p class="subtitle">Students who haven't submitted</p>
            </div>
        </div>
        <div class="assignments-summary-card card-avg-score">
            <div class="summary-icon">
                <i class="fa fa-star"></i>
            </div>
            <div class="summary-content">
                <h4>Average Assignment Score</h4>
                <div class="value"><?php 
                    $avg_assignment_score = 0;
                    if ($summary_stats['graded_assignments'] > 0) {
                        // Calculate average from all graded assignments
                        $total_score_sum = 0;
                        $total_score_count = 0;
                        foreach ($assignment_data as $student_data) {
                            foreach ($student_data['assignments'] as $assign) {
                                if ($assign['is_graded'] && $assign['grade_percentage'] !== null) {
                                    $total_score_sum += $assign['grade_percentage'];
                                    $total_score_count++;
                                }
                            }
                        }
                        $avg_assignment_score = $total_score_count > 0 ? round($total_score_sum / $total_score_count, 1) : 0;
                    }
                    echo $avg_assignment_score;
                ?>%</div>
                <p class="subtitle">Across all completed submissions</p>
            </div>
        </div>
    </div>
    
    <!-- Charts -->
    <div class="assignments-charts">
        <!-- Assignment Completion Status Chart -->
        <div class="assignments-chart-card">
            <h4 class="assignments-chart-title">
                <i class="fa fa-check-circle"></i> Assignment Completion Status
            </h4>
            <div class="assignments-chart-canvas">
                <canvas id="assignmentCompletionChart"></canvas>
            </div>
        </div>
        
        <!-- Daily Submission Trend Chart -->
        <div class="assignments-chart-card">
            <h4 class="assignments-chart-title">
                <i class="fa fa-line-chart"></i> Daily Submission Trend
            </h4>
            <div class="assignments-chart-canvas">
                <canvas id="dailySubmissionsChart"></canvas>
            </div>
        </div>
        
        <!-- Score Distribution Chart -->
        <div class="assignments-chart-card">
            <h4 class="assignments-chart-title">
                <i class="fa fa-pie-chart"></i> Score Distribution
            </h4>
            <div class="assignments-chart-canvas">
                <canvas id="scoreDistributionChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Assignment Details Table -->
    <?php if ($assignment_overview['total_assignments'] > 0): ?>
    <div class="assignments-table-card" style="margin-bottom: 30px;">
        <div class="assignments-table-header">
            <h4 class="assignments-table-title">
                <i class="fa fa-list"></i> Assignment Details
            </h4>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div class="assignments-search-container">
                    <i class="fa fa-search assignments-search-icon"></i>
                    <input type="text" id="assignmentDetailsSearch" class="assignments-search-input" placeholder="Search by assignment name, course, or creator..." autocomplete="off" />
                    <button type="button" id="assignmentDetailsClear" class="assignments-clear-btn" style="display: none;">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>
        <div class="assignments-table-wrapper">
            <table class="assignments-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Assignment Name</th>
                        <th style="min-width: 200px;">Course</th>
                        <th style="min-width: 180px;">Created by</th>
                        <th class="center" style="min-width: 150px;">Total Assign Student</th>
                        <th class="center" style="min-width: 120px;">Completed</th>
                        <th class="center" style="min-width: 150px;">Incomplete Student Count</th>
                        <th class="center" style="min-width: 120px;">Avg Score</th>
                        <th class="center" style="min-width: 120px;">Max Grade</th>
                        <th class="center" style="min-width: 120px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignment_overview['assignments_list'] as $assign): ?>
                    <tr class="assignment-row-clickable" style="cursor: pointer;" data-assignment-id="<?php echo $assign['id']; ?>" data-assignment-name="<?php echo htmlspecialchars($assign['name'], ENT_QUOTES); ?>">
                        <td>
                            <div style="font-weight: 600; color: #1f2937;">
                                <?php echo htmlspecialchars($assign['name']); ?>
                            </div>
                        </td>
                        <td style="color: #6b7280;"><?php echo htmlspecialchars($assign['course']); ?></td>
                        <td style="color: #4b5563; font-weight: 500;">
                            <?php echo htmlspecialchars($assign['created_by']); ?>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#e0f2fe;color:#1e3a8a;border-radius:12px;font-weight:600;">
                                <?php echo $assign['total_assign_students']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#dcfce7;color:#166534;border-radius:12px;font-weight:600;">
                                <?php echo $assign['completed']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#fee2e2;color:#991b1b;border-radius:12px;font-weight:600;">
                                <?php echo $assign['incomplete_students']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <?php if ($assign['avg_grade'] > 0): ?>
                                <span style="font-weight:700;color:<?php echo $assign['avg_grade'] >= 70 ? '#10b981' : ($assign['avg_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                    <?php echo $assign['avg_grade']; ?>%
                                </span>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-weight:600;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="center" style="color:#4b5563;font-weight:600;">
                            <?php echo $assign['max_grade']; ?>
                        </td>
                        <td class="center">
                            <?php
                                $now = time();
                                if ($assign['allowsubmissionsfromdate'] > 0 && $assign['allowsubmissionsfromdate'] > $now) {
                                    echo '<span style="display:inline-block;padding:4px 12px;background:#e0e7ff;color:#4338ca;border-radius:12px;font-size:0.8rem;font-weight:500;">Upcoming</span>';
                                } elseif ($assign['duedate'] > 0 && $assign['duedate'] < $now && $assign['cutoffdate'] > 0 && $assign['cutoffdate'] < $now) {
                                    echo '<span style="display:inline-block;padding:4px 12px;background:#fee2e2;color:#991b1b;border-radius:12px;font-size:0.8rem;font-weight:500;">Closed</span>';
                                } else {
                                    echo '<span style="display:inline-block;padding:4px 12px;background:#dcfce7;color:#166534;border-radius:12px;font-size:0.8rem;font-weight:500;">Active</span>';
                                }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Assignment Details Pagination -->
        <div class="assignment-details-pagination" id="assignmentDetailsPagination" style="display: none;">
            <div class="assignment-details-show-entries">
                <span>Show:</span>
                <select id="assignmentDetailsEntriesPerPage">
                    <option value="5" selected>5 entries</option>
                    <option value="10">10 entries</option>
                    <option value="25">25 entries</option>
                    <option value="50">50 entries</option>
                </select>
            </div>
            <div id="assignmentDetailsPaginationInfo" class="assignment-details-pagination-info">
                Showing 1 to 5 of 0 entries
            </div>
            <div class="assignment-details-pagination-controls">
                <button type="button" id="assignmentDetailsPrev" class="assignment-details-pagination-btn" disabled>&lt; Previous</button>
                <div id="assignmentDetailsPageNumbers" class="assignment-details-page-numbers"></div>
                <button type="button" id="assignmentDetailsNext" class="assignment-details-pagination-btn" disabled>Next &gt;</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Student Assignment Reports Table -->
    <div class="assignments-table-card" style="margin-top: 30px;">
        <div class="assignments-table-header">
            <h4 class="assignments-table-title">
                <i class="fa fa-table"></i> Student Assignment Reports
            </h4>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div class="assignments-search-container">
                    <i class="fa fa-search assignments-search-icon"></i>
                    <input type="text" id="assignmentsSearch" class="assignments-search-input" placeholder="Search students by name or email..." autocomplete="off" />
                    <button type="button" id="assignmentsClear" class="assignments-clear-btn">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>
        
        <?php if (!empty($assignment_data)): ?>
        <div class="assignments-table-wrapper">
            <table class="assignments-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Student Name</th>
                        <th class="center" style="min-width: 100px;">Grade Level</th>
                        <th class="center" style="min-width: 120px;">Total Assign Assignment</th>
                        <th class="center" style="min-width: 120px;">Completed</th>
                        <th class="center" style="min-width: 120px;">Incompleted</th>
                        <th class="center" style="min-width: 120px;">Highest Score</th>
                        <th class="center" style="min-width: 120px;">Lowest Score</th>
                        <th class="center" style="min-width: 120px;">Average Score</th>
                        <th class="center" style="min-width: 120px;">Avg Completion Rate</th>
                    </tr>
                </thead>
                <tbody id="assignmentsTableBody">
                    <?php foreach ($assignment_data as $student_data): ?>
                    <tr class="assignments-row" 
                        data-name="<?php echo strtolower(htmlspecialchars($student_data['student_name'])); ?>"
                        data-email="<?php echo strtolower(htmlspecialchars($student_data['student_email'])); ?>"
                        data-grade="<?php echo strtolower(htmlspecialchars($student_data['grade_level'])); ?>">
                        <td>
                            <div style="font-weight: 600; color: #1f2937;">
                                <?php echo htmlspecialchars($student_data['student_name']); ?>
                            </div>
                            <div style="font-size: 0.75rem; color: #6b7280;">
                                <?php echo htmlspecialchars($student_data['student_email']); ?>
                            </div>
                        </td>
                        <td class="center" style="color: #4b5563; font-weight: 500;">
                            <?php echo htmlspecialchars($student_data['grade_level']); ?>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: #e0f2fe; color: #1e3a8a; border-radius: 8px; font-weight: 700;">
                                <?php echo $student_data['total_assigned_assignments']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: #d1fae5; color: #065f46; border-radius: 8px; font-weight: 700;">
                                <?php echo $student_data['completed_count']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: <?php echo $student_data['incompleted_count'] == 0 ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $student_data['incompleted_count'] == 0 ? '#065f46' : '#991b1b'; ?>; border-radius: 8px; font-weight: 700;">
                                <?php echo $student_data['incompleted_count']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span class="assignments-score-badge <?php 
                                if ($student_data['highest_score'] >= 90) echo 'excellent';
                                elseif ($student_data['highest_score'] >= 70) echo 'good';
                                elseif ($student_data['highest_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['highest_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span class="assignments-score-badge <?php 
                                if ($student_data['lowest_score'] >= 90) echo 'excellent';
                                elseif ($student_data['lowest_score'] >= 70) echo 'good';
                                elseif ($student_data['lowest_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['lowest_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span class="assignments-score-badge <?php 
                                if ($student_data['avg_score'] >= 90) echo 'excellent';
                                elseif ($student_data['avg_score'] >= 70) echo 'good';
                                elseif ($student_data['avg_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['avg_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                if ($student_data['avg_completion_rate'] >= 80) echo '#d1fae5';
                                elseif ($student_data['avg_completion_rate'] >= 50) echo '#fef3c7';
                                else echo '#fee2e2';
                            ?>; color: <?php 
                                if ($student_data['avg_completion_rate'] >= 80) echo '#065f46';
                                elseif ($student_data['avg_completion_rate'] >= 50) echo '#92400e';
                                else echo '#991b1b';
                            ?>; border-radius: 8px; font-weight: 700;">
                                <?php echo $student_data['avg_completion_rate']; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="assignmentsEmpty" class="assignments-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <div class="assignments-pagination">
            <div class="assignments-show-entries">
                <span>Show:</span>
                <select id="assignmentsEntriesPerPage">
                    <option value="10" selected>10 entries</option>
                    <option value="25">25 entries</option>
                    <option value="50">50 entries</option>
                    <option value="100">100 entries</option>
                </select>
            </div>
            <div id="assignmentsPaginationInfo" class="assignments-pagination-info">
                Showing 0 to 0 of <?php echo count($assignment_data); ?> entries
            </div>
            <div class="assignments-pagination-controls">
                <button type="button" id="assignmentsPrev" class="assignments-pagination-btn">&lt; Previous</button>
                <div id="assignmentsPageNumbers" class="assignments-page-numbers"></div>
                <button type="button" id="assignmentsNext" class="assignments-pagination-btn">Next &gt;</button>
            </div>
        </div>
        <?php else: ?>
        <div class="assignments-empty-state">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No assignment submission data available.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

<?php if (!empty($assignment_data)): ?>
<script>
// Initialize Charts (with AJAX support)
(function() {
    const daysLabels = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d)); }, $days_labels)); ?>;
    const submittedData = <?php echo json_encode(array_column($daily_submissions, 'submitted')); ?>;
    const lateData = <?php echo json_encode(array_column($daily_submissions, 'late')); ?>;
    const completionData = <?php echo json_encode([
        $assignment_completion_status['completed'],
        $assignment_completion_status['not_completed'],
        $assignment_completion_status['overdue']
    ]); ?>;
    const scoreDistributionData = <?php echo json_encode([
        $score_distribution['excellent'],
        $score_distribution['good'],
        $score_distribution['average'],
        $score_distribution['poor']
    ]); ?>;
    const totalCompletions = <?php echo $assignment_completion_status['completed'] + $assignment_completion_status['not_completed'] + $assignment_completion_status['overdue']; ?>;
    
    function createDailySubmissionsChart() {
        const dailySubmissionsCtx = document.getElementById('dailySubmissionsChart');
        
        if (!dailySubmissionsCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        if (window.dailySubmissionsChartInstance) {
            try {
                window.dailySubmissionsChartInstance.destroy();
            } catch(e) {}
            window.dailySubmissionsChartInstance = null;
        }
        
        try {
            window.dailySubmissionsChartInstance = new Chart(dailySubmissionsCtx, {
                type: 'line',
                data: {
                    labels: daysLabels,
                    datasets: [{
                        label: 'On-Time Submissions',
                        data: submittedData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Late Submissions',
                        data: lateData,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14, weight: 'bold' },
                            bodyFont: { size: 12 },
                            displayColors: true
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                autoSkip: true,
                                maxTicksLimit: 10
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
            return true;
        } catch (error) {
            console.error('Error creating daily submissions chart:', error);
            return false;
        }
    }
    
    function createAssignmentCompletionChart() {
        const completionCtx = document.getElementById('assignmentCompletionChart');
        
        if (!completionCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        if (window.assignmentCompletionChartInstance) {
            try {
                window.assignmentCompletionChartInstance.destroy();
            } catch(e) {}
            window.assignmentCompletionChartInstance = null;
        }
        
        try {
            const completedCount = completionData[0];
            const notCompletedCount = completionData[1];
            const overdueCount = completionData[2];
            
            const completedPercent = totalCompletions > 0 ? ((completedCount / totalCompletions) * 100).toFixed(1) : 0;
            const notCompletedPercent = totalCompletions > 0 ? ((notCompletedCount / totalCompletions) * 100).toFixed(1) : 0;
            const overduePercent = totalCompletions > 0 ? ((overdueCount / totalCompletions) * 100).toFixed(1) : 0;
            
            window.assignmentCompletionChartInstance = new Chart(completionCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        'Completed (' + completedCount + ' - ' + completedPercent + '%)',
                        'Not Completed (' + notCompletedCount + ' - ' + notCompletedPercent + '%)',
                        'Overdue (' + overdueCount + ' - ' + overduePercent + '%)'
                    ],
                    datasets: [{
                        data: completionData,
                        backgroundColor: [
                            '#3b82f6',
                            '#f59e0b',
                            '#ef4444'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 12,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return label.split(' (')[0] + ': ' + value + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            return true;
        } catch (error) {
            console.error('Error creating assignment completion chart:', error);
            return false;
        }
    }
    
    function createScoreDistributionChart() {
        const scoreDistCtx = document.getElementById('scoreDistributionChart');
        
        if (!scoreDistCtx) {
            console.log('Score distribution chart canvas not found');
            return false;
        }
        
        // Wait for Chart.js to be available
        if (typeof Chart === 'undefined') {
            console.log('Chart.js not loaded, waiting...');
            setTimeout(createScoreDistributionChart, 100);
            return false;
        }
        
        // Destroy existing chart if any
        if (window.scoreDistributionChartInstance) {
            try {
                window.scoreDistributionChartInstance.destroy();
            } catch(e) {
                console.error('Error destroying existing chart:', e);
            }
            window.scoreDistributionChartInstance = null;
        }
        
        try {
            // Ensure we have valid data array
            const chartData = Array.isArray(scoreDistributionData) && scoreDistributionData.length === 4 
                ? scoreDistributionData 
                : [0, 0, 0, 0];
            
            const totalScores = chartData.reduce((a, b) => (a || 0) + (b || 0), 0);
            
            // If no data, show empty state message
            if (totalScores === 0) {
                scoreDistCtx.parentElement.innerHTML = '<div style="text-align: center; padding: 40px; color: #6b7280;"><i class="fa fa-chart-pie" style="font-size: 48px; margin-bottom: 12px; opacity: 0.3;"></i><p style="font-weight: 600; margin: 0;">No score data available yet</p><p style="font-size: 0.9rem; margin-top: 8px;">Scores will appear here once assignments are graded.</p></div>';
                return true;
            }
            
            const excellentPercent = totalScores > 0 ? ((chartData[0] / totalScores) * 100).toFixed(1) : 0;
            const goodPercent = totalScores > 0 ? ((chartData[1] / totalScores) * 100).toFixed(1) : 0;
            const averagePercent = totalScores > 0 ? ((chartData[2] / totalScores) * 100).toFixed(1) : 0;
            const poorPercent = totalScores > 0 ? ((chartData[3] / totalScores) * 100).toFixed(1) : 0;
            
            window.scoreDistributionChartInstance = new Chart(scoreDistCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        'Excellent (90-100%)',
                        'Good (70-89%)',
                        'Average (50-69%)',
                        'Poor (0-49%)'
                    ],
                    datasets: [{
                        data: chartData,
                        backgroundColor: [
                            '#10b981',
                            '#3b82f6',
                            '#f59e0b',
                            '#ef4444'
                        ],
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1.5,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { 
                                    size: 13,
                                    weight: '600',
                                    family: 'Inter, sans-serif'
                                },
                                generateLabels: function(chart) {
                                    const data = chart.data;
                                    if (data.labels.length && data.datasets.length) {
                                        return data.labels.map((label, i) => {
                                            const value = data.datasets[0].data[i] || 0;
                                            const total = data.datasets[0].data.reduce((a, b) => (a || 0) + (b || 0), 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return {
                                                text: label.split(' (')[0] + ' (' + value + ' - ' + percentage + '%)',
                                                fillStyle: data.datasets[0].backgroundColor[i],
                                                hidden: false,
                                                index: i
                                            };
                                        });
                                    }
                                    return [];
                                }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => (a || 0) + (b || 0), 0);
                                    if (total === 0) {
                                        return label.split(' (')[0] + ': No data';
                                    }
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return label.split(' (')[0] + ': ' + value + ' submission' + (value !== 1 ? 's' : '') + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            
            console.log('Score distribution chart created successfully with data:', chartData);
            return true;
        } catch (error) {
            console.error('Error creating score distribution chart:', error);
            return false;
        }
    }
    
    function initCharts() {
        const chart1 = createAssignmentCompletionChart();
        const chart2 = createDailySubmissionsChart();
        const chart3 = createScoreDistributionChart();
        return chart1 && chart2 && chart3;
    }
    
    // Make function globally accessible for AJAX-loaded content
    window.initAssignmentReportsChart = function() {
        return initCharts();
    };
    
    // Wait for DOM and Chart.js to be ready
    function waitForChartJS(callback, maxAttempts = 50) {
        let attempts = 0;
        const checkInterval = setInterval(function() {
            attempts++;
            if (typeof Chart !== 'undefined' && document.getElementById('scoreDistributionChart')) {
                clearInterval(checkInterval);
                callback();
            } else if (attempts >= maxAttempts) {
                clearInterval(checkInterval);
                console.warn('Chart.js or canvas element not found after maximum attempts');
            }
        }, 100);
    }
    
    // Initialize charts when ready
    waitForChartJS(function() {
        if (!initCharts()) {
            // Retry once more after a short delay
            setTimeout(initCharts, 300);
        }
    });
    
    // Also try immediately in case Chart.js is already loaded
    if (typeof Chart !== 'undefined') {
        setTimeout(initCharts, 100);
    }
})();

// Table search and pagination
(function() {
    const allRows = document.querySelectorAll('.assignments-row');
    let entriesPerPage = 10;
    let currentPage = 1;
    
    const searchInput = document.getElementById('assignmentsSearch');
    const clearBtn = document.getElementById('assignmentsClear');
    const tableBody = document.getElementById('assignmentsTableBody');
    const emptyState = document.getElementById('assignmentsEmpty');
    const prevBtn = document.getElementById('assignmentsPrev');
    const nextBtn = document.getElementById('assignmentsNext');
    const pageNumbers = document.getElementById('assignmentsPageNumbers');
    const paginationInfo = document.getElementById('assignmentsPaginationInfo');
    const entriesSelect = document.getElementById('assignmentsEntriesPerPage');
    
    if (!allRows.length || !tableBody) {
        return;
    }
    
    let filteredRows = Array.from(allRows);
    
    function updateDisplay() {
        const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
        
        if (clearBtn) {
            clearBtn.style.display = searchTerm ? 'flex' : 'none';
        }
        
        filteredRows = Array.from(allRows).filter(row => {
            if (!searchTerm) return true;
            const name = row.getAttribute('data-name') || '';
            const email = row.getAttribute('data-email') || '';
            const grade = row.getAttribute('data-grade') || '';
            return name.includes(searchTerm) || email.includes(searchTerm) || grade.includes(searchTerm);
        });
        
        allRows.forEach(row => row.style.display = 'none');
        
        const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        const pageRows = filteredRows.slice(startIndex, endIndex);
        
        pageRows.forEach(row => row.style.display = '');
        
        if (emptyState) {
            emptyState.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }
        if (tableBody) {
            tableBody.style.display = filteredRows.length === 0 ? 'none' : '';
        }
        
        if (paginationInfo) {
            const start = filteredRows.length === 0 ? 0 : startIndex + 1;
            const end = Math.min(endIndex, filteredRows.length);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
        }
        
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages === 0;
        
        if (pageNumbers) {
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(startPage + maxButtons - 1, totalPages);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            let html = '';
            if (startPage > 1) {
                html += `<button class="assignments-page-number" data-page="1">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="assignments-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="assignments-page-number" data-page="${totalPages}">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            pageNumbers.querySelectorAll('.assignments-page-number').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPage = parseInt(this.getAttribute('data-page'));
                    updateDisplay();
                });
            });
        }
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentPage = 1;
            updateDisplay();
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                currentPage = 1;
                updateDisplay();
            }
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateDisplay();
        });
    }
    
    updateDisplay();
})();

// Handle assignment row clicks - works for both initial load and AJAX-loaded content
(function() {
    function attachClickHandlers() {
    const assignmentRows = document.querySelectorAll('.assignment-row-clickable');
        
    assignmentRows.forEach(row => {
            // Skip if already has click handler
            if (row.hasAttribute('data-click-handler-attached')) {
                return;
            }
            
            row.setAttribute('data-click-handler-attached', 'true');
            
            row.addEventListener('click', function(e) {
                // Prevent default link behavior if any
                e.preventDefault();
                e.stopPropagation();
                
            const assignmentId = this.getAttribute('data-assignment-id');
            const assignmentName = this.getAttribute('data-assignment-name');
            
            if (assignmentId) {
                    const baseUrl = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/student_report_assignment_students.php';
                    const assignmentParam = encodeURIComponent(assignmentName || '');
                    const finalUrl = baseUrl + '?assignment_id=' + assignmentId + '&assignment_name=' + assignmentParam;
                console.log('Navigating to assignment students page:', finalUrl);
                window.location.href = finalUrl;
                } else {
                    console.error('Assignment ID not found');
            }
        });
            
            // Add hover effect
            row.style.transition = 'background-color 0.2s';
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f9fafb';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
        
        if (assignmentRows.length > 0) {
            console.log('Assignment row click handlers attached to', assignmentRows.length, 'rows');
        }
    }
    
    // Attach handlers when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachClickHandlers);
    } else {
        // DOM already loaded
        attachClickHandlers();
    }
    
    // Also attach handlers after a short delay to catch AJAX-loaded content
    setTimeout(attachClickHandlers, 500);
})();

// Assignment Details Pagination and Search
(function() {
    const assignmentDetailsTable = document.querySelector('.assignments-table-card .assignments-table tbody');
    if (!assignmentDetailsTable) return;
    
    const allAssignmentRows = Array.from(assignmentDetailsTable.querySelectorAll('.assignment-row-clickable'));
    if (allAssignmentRows.length === 0) return;
    
    let entriesPerPage = 5;
    let currentPage = 1;
    let filteredRows = Array.from(allAssignmentRows);
    
    const paginationContainer = document.getElementById('assignmentDetailsPagination');
    const entriesSelect = document.getElementById('assignmentDetailsEntriesPerPage');
    const paginationInfo = document.getElementById('assignmentDetailsPaginationInfo');
    const prevBtn = document.getElementById('assignmentDetailsPrev');
    const nextBtn = document.getElementById('assignmentDetailsNext');
    const pageNumbers = document.getElementById('assignmentDetailsPageNumbers');
    const searchInput = document.getElementById('assignmentDetailsSearch');
    const clearBtn = document.getElementById('assignmentDetailsClear');
    
    function filterAssignmentRows() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        filteredRows = allAssignmentRows.filter(row => {
            if (!searchTerm) return true;
            
            // Get text content from all cells in the row
            const cells = row.querySelectorAll('td');
            let rowText = '';
            cells.forEach(cell => {
                rowText += cell.textContent.toLowerCase() + ' ';
            });
            
            return rowText.includes(searchTerm);
        });
        
        currentPage = 1; // Reset to first page when filtering
        updateAssignmentDetailsDisplay();
    }
    
    function updateAssignmentDetailsDisplay() {
        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        
        // Show/hide rows
        allAssignmentRows.forEach((row) => {
            const filteredIndex = filteredRows.indexOf(row);
            const isVisible = filteredIndex >= 0 && filteredIndex >= startIndex && filteredIndex < endIndex;
            row.style.display = isVisible ? '' : 'none';
        });
        
        // Update pagination info
        if (paginationInfo) {
            const start = totalRows > 0 ? startIndex + 1 : 0;
            const end = Math.min(endIndex, totalRows);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${totalRows} entries`;
        }
        
        // Update pagination buttons
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages === 0;
        
        // Show/hide pagination
        if (paginationContainer) {
            paginationContainer.style.display = totalRows > entriesPerPage ? 'flex' : 'none';
        }
        
        // Update page numbers
        if (pageNumbers && totalPages > 0) {
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(startPage + maxButtons - 1, totalPages);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            let html = '';
            if (startPage > 1) {
                html += `<button class="assignment-details-page-number" data-page="1">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="assignment-details-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="assignment-details-page-number" data-page="${totalPages}">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            pageNumbers.querySelectorAll('.assignment-details-page-number').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPage = parseInt(this.getAttribute('data-page'));
                    updateAssignmentDetailsDisplay();
                });
            });
        }
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateAssignmentDetailsDisplay();
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateAssignmentDetailsDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateAssignmentDetailsDisplay();
            }
        });
    }
    
    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterAssignmentRows();
            if (clearBtn) {
                clearBtn.style.display = this.value.trim() ? 'flex' : 'none';
            }
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterAssignmentRows();
            }
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                filterAssignmentRows();
            }
        });
    }
    
    // Initialize
    setTimeout(updateAssignmentDetailsDisplay, 100);
})();
</script>
<?php endif; ?>

<?php
echo ob_get_clean();
exit;
?>

