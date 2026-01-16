<?php
/**
 * Student Report - Quiz Reports Tab (AJAX fragment)
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
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => 'quiz']);
    redirect($target);
}

// Initialize quiz-only data
$quiz_attempts_data = [];
$summary_stats = [
    'total_quiz_attempts' => 0,
    'avg_quiz_score' => 0,
    'highest_quiz_score' => 0,
    'lowest_quiz_score' => 100,
    'ungraded_quiz_attempts' => 0,
    'total_time_taken' => 0,
    'avg_time_per_attempt' => 0,
    'active_students' => 0,
    'total_quizzes_created' => 0,
    'total_quizzes_assigned' => 0,
    'total_students_assigned' => 0,
    'students_not_attempted' => 0
];

$score_distribution = [
    'excellent' => 0,
    'good' => 0,
    'average' => 0,
    'poor' => 0
];

$quiz_overview = [
    'total_quizzes' => 0,
    'total_attempts' => 0,
    'completed_attempts' => 0,
    'in_progress_attempts' => 0,
    'average_score' => 0,
    'quizzes_list' => []
];

$completion_status = [
    'completed' => 0,
    'not_completed' => 0,
    'overdue' => 0
];

$daily_attempts = [];
$days_labels = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily_attempts[$day] = 0;
    $days_labels[] = $day;
}

if ($company_info) {
    // Get total quizzes assigned (quizzes that have at least one student enrolled in the course)
    $summary_stats['total_quizzes_assigned'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT q.id)
         FROM {quiz} q
         INNER JOIN {course} c ON c.id = q.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         INNER JOIN {enrol} e ON e.courseid = c.id
         INNER JOIN {user_enrolments} ue ON ue.enrolid = e.id
         INNER JOIN {user} u ON u.id = ue.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cc.companyid = ? 
         AND cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND c.visible = 1",
        [$company_info->id, $company_info->id]
    );
    
    // Get all students for the company
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
    
    // Get students who have quizzes assigned (enrolled in courses with quizzes)
    $students_with_quizzes = $DB->get_records_sql(
        "SELECT DISTINCT u.id
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {course} c ON c.id = e.courseid
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         INNER JOIN {quiz} q ON q.course = c.id
         WHERE cu.companyid = ?
         AND cc.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND c.visible = 1",
        [$company_info->id, $company_info->id]
    );
    
    $summary_stats['total_students_assigned'] = count($students_with_quizzes);
    
    // Build quiz overview (per quiz statistics)
    $quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, q.course, c.fullname AS coursename, q.timeopen, q.timeclose,
                q.grade AS maxgrade, q.sumgrades
         FROM {quiz} q
         INNER JOIN {course} c ON c.id = q.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ? AND c.visible = 1
         ORDER BY c.fullname ASC, q.name ASC",
        [$company_info->id]
    );
    
    $quiz_overview['total_quizzes'] = count($quizzes);
    $overall_grade_sum = 0;
    $overall_grade_count = 0;
    
    foreach ($quizzes as $quiz) {
        $attempts = $DB->get_records_sql(
            "SELECT qa.id, qa.state, qa.sumgrades, qa.userid
             FROM {quiz_attempts} qa
             INNER JOIN {user} u ON u.id = qa.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             WHERE qa.quiz = ?
             AND cu.companyid = ?
             AND u.deleted = 0
             AND qa.preview = 0",
            [$quiz->id, $company_info->id]
        );
        
        $total_attempts = count($attempts);
        $completed = 0;
        $in_progress = 0;
        $sum_grades = 0;
        $graded_attempts = 0;
        $students_who_completed = [];
        
        foreach ($attempts as $attempt) {
            if ($attempt->state === 'finished') {
                $completed++;
                if (!in_array($attempt->userid, $students_who_completed)) {
                    $students_who_completed[] = $attempt->userid;
                }
                if ($attempt->sumgrades !== null && $quiz->sumgrades > 0) {
                    $sum_grades += ($attempt->sumgrades / $quiz->sumgrades) * 100;
                    $graded_attempts++;
                }
            } else {
                $in_progress++;
            }
        }
        
        // Get total students assigned to this quiz (students enrolled in the course)
        $total_assign_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {course} c ON c.id = e.courseid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ?
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0
             AND ue.status = 0
             AND c.visible = 1
             AND c.id = ?",
            [$company_info->id, $company_info->id, $quiz->course]
        );
        
        // Calculate incomplete students (assigned but not completed)
        $incomplete_students = $total_assign_students - count($students_who_completed);
        
        // Get teacher name who created/is assigned to this course
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
            [$quiz->course, $company_info->id]
        );
        
        if ($course_teacher) {
            $teacher_name = fullname($course_teacher);
        }
        
        $quiz_overview['total_attempts'] += $total_attempts;
        $quiz_overview['completed_attempts'] += $completed;
        $quiz_overview['in_progress_attempts'] += $in_progress;
        
        $avg_grade = $graded_attempts > 0 ? round($sum_grades / $graded_attempts, 1) : 0;
        if ($graded_attempts > 0) {
            $overall_grade_sum += $sum_grades;
            $overall_grade_count += $graded_attempts;
        }
        
        $quiz_overview['quizzes_list'][] = [
            'id' => $quiz->id,
            'name' => $quiz->name,
            'course' => $quiz->coursename,
            'created_by' => $teacher_name,
            'total_attempts' => $total_attempts,
            'completed' => $completed,
            'in_progress' => $in_progress,
            'total_assign_students' => $total_assign_students,
            'incomplete_students' => max(0, $incomplete_students), // Ensure non-negative
            'avg_grade' => $avg_grade,
            'max_grade' => round($quiz->maxgrade, 1),
            'timeopen' => $quiz->timeopen,
            'timeclose' => $quiz->timeclose
        ];
    }
    
    if ($overall_grade_count > 0) {
        $quiz_overview['average_score'] = round($overall_grade_sum / $overall_grade_count, 1);
    }
    
    $summary_stats['total_quizzes_created'] = $quiz_overview['total_quizzes'];
    
    // Initialize variables for tracking attempts
    $total_quiz_scores = 0;
    $total_quiz_count = 0;
    $total_time_seconds = 0;
    $total_time_count = 0;
    $students_with_attempts = [];

    foreach ($students as $student) {
        // Get total quizzes assigned to this student (quizzes in courses where student is enrolled)
        $total_assigned_quizzes = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT q.id)
             FROM {quiz} q
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             INNER JOIN {user_enrolments} ue ON ue.userid = ?
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
             WHERE cc.companyid = ?
             AND ue.status = 0
             AND e.status = 0
             AND c.visible = 1",
            [$student->id, $company_info->id]
        );
        
        // Only process students who have quizzes assigned
        if ($total_assigned_quizzes == 0) {
            continue;
        }
        
        $quiz_attempts = $DB->get_records_sql(
            "SELECT qa.id, qa.quiz, qa.attempt, qa.timestart, qa.timefinish, qa.state,
                    qa.sumgrades, q.sumgrades AS maxgrade, q.name AS quiz_name,
                    c.fullname AS course_name
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE qa.userid = ?
             AND cc.companyid = ?
             AND qa.preview = 0
             ORDER BY qa.timestart DESC",
            [$student->id, $company_info->id]
        );
        
        // Track students who have attempted quizzes
        if (!empty($quiz_attempts)) {
            $students_with_attempts[] = $student->id;
        }
        
        $student_quiz_attempts = [];
        $student_highest_score = 0;
        $student_lowest_score = 100;
        $student_total_time = 0;
        $student_ungraded = 0;

        foreach ($quiz_attempts as $attempt) {
            $score = 0;
            $is_ungraded = false;

            if ($attempt->state === 'finished' && $attempt->timefinish > 0 && $attempt->maxgrade > 0) {
                $score = round(($attempt->sumgrades / $attempt->maxgrade) * 100, 2);
                $total_quiz_scores += $score;
                $total_quiz_count++;

                if ($score > $student_highest_score) {
                    $student_highest_score = $score;
                }
                if ($score < $student_lowest_score) {
                    $student_lowest_score = $score;
                }

                if ($score >= 90) {
                    $score_distribution['excellent']++;
                } elseif ($score >= 70) {
                    $score_distribution['good']++;
                } elseif ($score >= 50) {
                    $score_distribution['average']++;
                } else {
                    $score_distribution['poor']++;
                }
            } else {
                $is_ungraded = true;
                $student_ungraded++;
                $summary_stats['ungraded_quiz_attempts']++;
            }

            if ($attempt->timefinish > $attempt->timestart) {
                $time_taken = $attempt->timefinish - $attempt->timestart;
                $student_total_time += $time_taken;
                $total_time_seconds += $time_taken;
                $total_time_count++;
            }

            $attempt_date = date('Y-m-d', $attempt->timestart);
            if (isset($daily_attempts[$attempt_date])) {
                $daily_attempts[$attempt_date]++;
            }

            $student_quiz_attempts[] = [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz,
                'quiz_name' => $attempt->quiz_name,
                'course_name' => $attempt->course_name,
                'attempt_number' => $attempt->attempt,
                'timestart' => $attempt->timestart,
                'timefinish' => $attempt->timefinish,
                'time_taken' => ($attempt->timefinish > $attempt->timestart) ? ($attempt->timefinish - $attempt->timestart) : 0,
                'score' => $score,
                'state' => $attempt->state,
                'is_ungraded' => $is_ungraded
            ];
        }

        $quiz_attempts_data[] = [
            'student_id' => $student->id,
            'student_name' => fullname($student),
            'student_email' => $student->email,
            'grade_level' => $student->grade_level ?? 'N/A',
            'total_assigned_quizzes' => $total_assigned_quizzes,
            'total_quiz_attempts' => count($student_quiz_attempts),
            'highest_quiz_score' => $student_highest_score,
            'lowest_quiz_score' => $student_lowest_score > 99 ? 0 : $student_lowest_score,
            'avg_quiz_score' => count($student_quiz_attempts) > 0 ? round(array_sum(array_column($student_quiz_attempts, 'score')) / count($student_quiz_attempts), 2) : 0,
            'total_time_taken' => $student_total_time,
            'avg_time_per_attempt' => count($student_quiz_attempts) > 0 ? round($student_total_time / count($student_quiz_attempts)) : 0,
            'ungraded_quiz_attempts' => $student_ungraded,
            'quiz_attempts' => $student_quiz_attempts
        ];

        $summary_stats['total_quiz_attempts'] += count($student_quiz_attempts);
        if ($student_highest_score > $summary_stats['highest_quiz_score']) {
            $summary_stats['highest_quiz_score'] = $student_highest_score;
        }
        if ($student_lowest_score < $summary_stats['lowest_quiz_score'] && $student_lowest_score < 99) {
            $summary_stats['lowest_quiz_score'] = $student_lowest_score;
        }
    }

    if ($total_quiz_count > 0) {
        $summary_stats['avg_quiz_score'] = round($total_quiz_scores / $total_quiz_count, 2);
    }
    if ($total_time_count > 0) {
        $summary_stats['avg_time_per_attempt'] = round($total_time_seconds / $total_time_count);
        $summary_stats['total_time_taken'] = $total_time_seconds;
    }
    $summary_stats['active_students'] = count($quiz_attempts_data);
    
    // Calculate students not attempted (students assigned to quizzes but haven't attempted)
    $students_not_attempted = 0;
    foreach ($students_with_quizzes as $student_with_quiz) {
        if (!in_array($student_with_quiz->id, $students_with_attempts)) {
            $students_not_attempted++;
        }
    }
    $summary_stats['students_not_attempted'] = $students_not_attempted;
    
    // Calculate Quiz Completion Status based on summary card data
    // Count completed attempts (attempts that are finished and submitted)
    $completed_attempts_count = $DB->count_records_sql(
        "SELECT COUNT(qa.id)
         FROM {quiz_attempts} qa
         INNER JOIN {quiz} q ON q.id = qa.quiz
         INNER JOIN {course} c ON c.id = q.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         INNER JOIN {company_users} cu ON cu.userid = qa.userid
         WHERE qa.preview = 0 
         AND qa.state = 'finished' 
         AND qa.timefinish > 0
         AND cc.companyid = ?
         AND cu.companyid = ?",
        [$company_info->id, $company_info->id]
    );
    
    // Use summary stats data to match the summary cards
    // Completed: Number of completed attempts (from total attempts)
    $completion_status['completed'] = $completed_attempts_count;
    
    // Not Completed: Students who haven't attempted (from summary stats)
    $completion_status['not_completed'] = $summary_stats['students_not_attempted'];
    
    // Overdue: Check for overdue quiz assignments (quizzes past deadline and not completed)
    $current_time = time();
    $overdue_assignments = $DB->get_records_sql(
        "SELECT DISTINCT u.id as userid, q.id as quizid
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {course} c ON c.id = e.courseid
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         INNER JOIN {quiz} q ON q.course = c.id
         LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = u.id AND qa.preview = 0 AND qa.state = 'finished' AND qa.timefinish > 0
         WHERE cu.companyid = ?
         AND cc.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND c.visible = 1
         AND q.timeclose > 0
         AND q.timeclose < ?
         AND qa.id IS NULL",
        [$company_info->id, $company_info->id, $current_time]
    );
    
    $completion_status['overdue'] = count($overdue_assignments);
}

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.quiz-reports-container {
    padding: 0;
}

.quiz-reports-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.quiz-reports-summary-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.7));
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
    min-height: 100px;
}

.quiz-reports-summary-card .summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: #fff;
}

.quiz-reports-summary-card .summary-content h4 {
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    margin: 0 0 6px 0;
    color: #475569;
    text-transform: uppercase;
}

.quiz-reports-summary-card .summary-content .value {
    font-size: 2rem;
    font-weight: 800;
    margin: 0 0 6px 0;
    color: #0f172a;
}

.quiz-reports-summary-card .summary-content .subtitle {
    margin: 0;
    font-size: 0.85rem;
    color: #6b7280;
}

.quiz-reports-summary-card.card-total-quizzes {
    background: linear-gradient(135deg, #fff5e6, #ffe9cc);
    border: 2px solid #f59e0b;
}
.quiz-reports-summary-card.card-total-quizzes .summary-icon {
    background: #f59e0b;
}

.quiz-reports-summary-card.card-total-students {
    background: linear-gradient(135deg, #e0f2ff, #f2f7ff);
    border: 2px solid #3b82f6;
}
.quiz-reports-summary-card.card-total-students .summary-icon {
    background: #3b82f6;
}

.quiz-reports-summary-card.card-total-attempts {
    background: linear-gradient(135deg, #e6fff7, #f2fff6);
    border: 2px solid #10b981;
}
.quiz-reports-summary-card.card-total-attempts .summary-icon {
    background: #10b981;
}

.quiz-reports-summary-card.card-not-attempted {
    background: linear-gradient(135deg, #ffeaea, #fff5f5);
    border: 2px solid #ef4444;
}
.quiz-reports-summary-card.card-not-attempted .summary-icon {
    background: #ef4444;
}

.quiz-reports-summary-card.card-avg-score {
    background: linear-gradient(135deg, #f6f0ff, #faf5ff);
    border: 2px solid #8b5cf6;
}
.quiz-reports-summary-card.card-avg-score .summary-icon {
    background: #8b5cf6;
}

.quiz-reports-charts {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.quiz-reports-chart-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.quiz-reports-chart-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.quiz-reports-chart-title i { color: #3b82f6; }
.quiz-reports-chart-canvas { 
    position: relative; 
    height: 300px; 
    min-height: 250px;
    max-height: 400px;
    width: 100%;
}

.quiz-reports-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.quiz-reports-table-card.student-reports-card {
    margin-top: 30px;
}

.quiz-reports-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.quiz-reports-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.quiz-reports-table-title i { color: #3b82f6; }

.quiz-reports-search-container {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.quiz-reports-search-input {
    width: 100%;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
}

.quiz-reports-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.quiz-reports-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.quiz-reports-clear-btn {
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

.quiz-reports-table-wrapper { overflow-x: auto; margin-bottom: 20px; }
.quiz-reports-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 900px; }
.quiz-reports-table thead { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.quiz-reports-table th { padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
.quiz-reports-table th.center, .quiz-reports-table td.center { text-align: center; }
.quiz-reports-table tbody tr { border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; }
.quiz-reports-table tbody tr:hover { background: #f9fafb; }
.quiz-reports-table td { padding: 12px; color: #1f2937; }

.quiz-reports-score-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.quiz-reports-score-badge.excellent { background: #d1fae5; color: #065f46; }
.quiz-reports-score-badge.good { background: #dbeafe; color: #1e40af; }
.quiz-reports-score-badge.average { background: #fef3c7; color: #92400e; }
.quiz-reports-score-badge.poor { background: #fee2e2; color: #991b1b; }

.quiz-reports-ungraded-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #f3f4f6;
    color: #6b7280;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
}

.quiz-reports-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.quiz-reports-pagination-info { font-size: 0.9rem; color: #6b7280; }
.quiz-reports-pagination-controls { display: flex; align-items: center; gap: 10px; }
.quiz-reports-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: white;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
}

.quiz-reports-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quiz-reports-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.quiz-reports-page-number {
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

.quiz-reports-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-reports-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.quiz-reports-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-reports-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
}

.quiz-reports-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.quiz-reports-empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
    display: block;
}

@media (max-width: 1024px) {
    .quiz-reports-charts {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1200px) {
    .quiz-reports-charts {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .quiz-reports-charts {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .quiz-reports-summary-cards {
        grid-template-columns: 1fr;
    }
}

/* Quiz Details Pagination */
.quiz-details-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.quiz-details-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-details-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: white;
    cursor: pointer;
}

.quiz-details-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.quiz-details-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.quiz-details-pagination-btn {
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

.quiz-details-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-details-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quiz-details-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.quiz-details-page-number {
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

.quiz-details-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.quiz-details-page-number.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}
</style>

<div class="quiz-reports-container">
    <div class="quiz-reports-summary-cards">
        <div class="quiz-reports-summary-card card-total-quizzes">
            <div class="summary-icon">
                <i class="fa fa-clipboard-list"></i>
            </div>
            <div class="summary-content">
                <h4>Total Create Quiz</h4>
                <div class="value"><?php echo number_format($summary_stats['total_quizzes_created']); ?></div>
                <p class="subtitle">Total quizzes created in courses</p>
            </div>
        </div>
        <div class="quiz-reports-summary-card card-total-students">
            <div class="summary-icon">
                <i class="fa fa-user-graduate"></i>
            </div>
            <div class="summary-content">
                <h4>Total Quiz Assign Student</h4>
                <div class="value"><?php echo number_format($summary_stats['total_students_assigned']); ?></div>
                <p class="subtitle">Students assigned to quizzes</p>
            </div>
        </div>
        <div class="quiz-reports-summary-card card-total-attempts">
            <div class="summary-icon">
                <i class="fa fa-chart-line"></i>
            </div>
            <div class="summary-content">
                <h4>Total Attempt</h4>
                <div class="value"><?php echo number_format($summary_stats['total_quiz_attempts']); ?></div>
                <p class="subtitle">All quiz attempts by students</p>
            </div>
        </div>
        <div class="quiz-reports-summary-card card-not-attempted">
            <div class="summary-icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <div class="summary-content">
                <h4>Not Attempts</h4>
                <div class="value"><?php echo number_format($summary_stats['students_not_attempted']); ?></div>
                <p class="subtitle">Students who haven't attempted</p>
            </div>
        </div>
        <div class="quiz-reports-summary-card card-avg-score">
            <div class="summary-icon">
                <i class="fa fa-star"></i>
            </div>
            <div class="summary-content">
                <h4>Average Quiz Score</h4>
                <div class="value"><?php echo $summary_stats['avg_quiz_score']; ?>%</div>
                <p class="subtitle">Across all completed attempts</p>
            </div>
        </div>
    </div>
    
    <!-- Charts - All in One Row -->
    <div class="quiz-reports-charts">
        <!-- Quiz Completion Status Chart -->
        <div class="quiz-reports-chart-card">
            <h4 class="quiz-reports-chart-title">
                <i class="fa fa-check-circle"></i> Quiz Completion Status
            </h4>
            <div class="quiz-reports-chart-canvas">
                <canvas id="quizCompletionChart"></canvas>
            </div>
        </div>
        
        <!-- Daily Attempts Chart -->
        <div class="quiz-reports-chart-card">
            <h4 class="quiz-reports-chart-title">
                <i class="fa fa-line-chart"></i> Daily Quiz Attempts Trend
            </h4>
            <div class="quiz-reports-chart-canvas">
                <canvas id="dailyQuizAttemptsChart"></canvas>
            </div>
        </div>
        
        <!-- Score Distribution Chart -->
        <div class="quiz-reports-chart-card">
            <h4 class="quiz-reports-chart-title">
                <i class="fa fa-pie-chart"></i> Score Distribution
            </h4>
            <div class="quiz-reports-chart-canvas">
                <canvas id="scoreDistributionChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Quiz Overview Table -->
    <?php if ($quiz_overview['total_quizzes'] > 0): ?>
    <div class="quiz-reports-table-card">
        <div class="quiz-reports-table-header">
            <h4 class="quiz-reports-table-title">
                <i class="fa fa-list"></i> Quiz Details
            </h4>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div class="quiz-reports-search-container">
                    <i class="fa fa-search quiz-reports-search-icon"></i>
                    <input type="text" id="quizDetailsSearch" class="quiz-reports-search-input" placeholder="Search by quiz name, course, or creator..." autocomplete="off" />
                    <button type="button" id="quizDetailsClear" class="quiz-reports-clear-btn" style="display: none;">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>
        <div class="quiz-reports-table-wrapper">
            <table class="quiz-reports-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Quiz Name</th>
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
                    <?php foreach ($quiz_overview['quizzes_list'] as $quiz): ?>
                    <tr class="quiz-row-clickable" style="cursor: pointer;" data-quiz-id="<?php echo $quiz['id']; ?>" data-quiz-name="<?php echo htmlspecialchars($quiz['name'], ENT_QUOTES); ?>">
                        <td>
                            <div style="font-weight: 600; color: #1f2937;">
                                <?php echo htmlspecialchars($quiz['name']); ?>
                            </div>
                        </td>
                        <td style="color: #6b7280;"><?php echo htmlspecialchars($quiz['course']); ?></td>
                        <td style="color: #4b5563; font-weight: 500;">
                            <?php echo htmlspecialchars($quiz['created_by']); ?>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#e0f2fe;color:#1e3a8a;border-radius:12px;font-weight:600;">
                                <?php echo $quiz['total_assign_students']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#dcfce7;color:#166534;border-radius:12px;font-weight:600;">
                                <?php echo $quiz['completed']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display:inline-block;padding:4px 10px;background:#fee2e2;color:#991b1b;border-radius:12px;font-weight:600;">
                                <?php echo $quiz['incomplete_students']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <?php if ($quiz['avg_grade'] > 0): ?>
                                <span style="font-weight:700;color:<?php echo $quiz['avg_grade'] >= 70 ? '#10b981' : ($quiz['avg_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                    <?php echo $quiz['avg_grade']; ?>%
                                </span>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-weight:600;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="center" style="color:#4b5563;font-weight:600;">
                            <?php echo $quiz['max_grade']; ?>
                        </td>
                        <td class="center">
                            <?php
                                $now = time();
                                if ($quiz['timeopen'] > 0 && $quiz['timeopen'] > $now) {
                                    echo '<span style="display:inline-block;padding:4px 12px;background:#e0e7ff;color:#4338ca;border-radius:12px;font-size:0.8rem;font-weight:500;">Upcoming</span>';
                                } elseif ($quiz['timeclose'] > 0 && $quiz['timeclose'] < $now) {
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
        
        <!-- Quiz Details Pagination -->
        <div class="quiz-details-pagination" id="quizDetailsPagination" style="display: none;">
            <div class="quiz-details-show-entries">
                <span>Show:</span>
                <select id="quizDetailsEntriesPerPage">
                    <option value="5" selected>5 entries</option>
                    <option value="10">10 entries</option>
                    <option value="25">25 entries</option>
                    <option value="50">50 entries</option>
                </select>
            </div>
            <div id="quizDetailsPaginationInfo" class="quiz-details-pagination-info">
                Showing 1 to 5 of 0 entries
            </div>
            <div class="quiz-details-pagination-controls">
                <button type="button" id="quizDetailsPrev" class="quiz-details-pagination-btn" disabled>&lt; Previous</button>
                <div id="quizDetailsPageNumbers" class="quiz-details-page-numbers"></div>
                <button type="button" id="quizDetailsNext" class="quiz-details-pagination-btn" disabled>Next &gt;</button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="quiz-reports-empty-state" style="margin-bottom: 30px;">
        <i class="fa fa-info-circle"></i>
        <h4>No quizzes found</h4>
        <p>Create quizzes in your courses to see detailed stats here.</p>
    </div>
    <?php endif; ?>
    
    <!-- Student Details Table -->
    <div class="quiz-reports-table-card student-reports-card">
        <div class="quiz-reports-table-header">
            <h4 class="quiz-reports-table-title">
                <i class="fa fa-table"></i> Student Quiz Reports
            </h4>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div class="quiz-reports-search-container">
                    <i class="fa fa-search quiz-reports-search-icon"></i>
                    <input type="text" id="quizReportsSearch" class="quiz-reports-search-input" placeholder="Search students by name or email..." autocomplete="off" />
                    <button type="button" id="quizReportsClear" class="quiz-reports-clear-btn">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>
        
        <?php if (!empty($quiz_attempts_data)): ?>
        <div class="quiz-reports-table-wrapper">
            <table class="quiz-reports-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Student Name</th>
                        <th class="center" style="min-width: 100px;">Grade Level</th>
                        <th class="center" style="min-width: 120px;">Total Assign Quiz</th>
                        <th class="center" style="min-width: 120px;">Completed</th>
                        <th class="center" style="min-width: 120px;">Incompleted</th>
                        <th class="center" style="min-width: 120px;">Highest Score</th>
                        <th class="center" style="min-width: 120px;">Lowest Score</th>
                        <th class="center" style="min-width: 120px;">Average Score</th>
                        <th class="center" style="min-width: 120px;">Avg Completion Rate</th>
                    </tr>
                </thead>
                <tbody id="quizReportsTableBody">
                    <?php foreach ($quiz_attempts_data as $student_data): 
                        // Count unique completed quizzes (quizzes with at least one finished and submitted attempt)
                        $completed_quizzes = [];
                        foreach ($student_data['quiz_attempts'] as $attempt) {
                            // A quiz is considered completed if:
                            // 1. State is 'finished'
                            // 2. Has a quiz_id
                            // 3. Has timefinish set and greater than 0 (actually submitted, not just started)
                            if (isset($attempt['state']) && 
                                $attempt['state'] === 'finished' && 
                                isset($attempt['quiz_id']) && 
                                isset($attempt['timefinish']) && 
                                !empty($attempt['timefinish']) && 
                                $attempt['timefinish'] > 0) {
                                $completed_quizzes[$attempt['quiz_id']] = true;
                            }
                        }
                        $completed_count = count($completed_quizzes);
                        $incompleted_count = max(0, $student_data['total_assigned_quizzes'] - $completed_count);
                        
                        // Calculate average completion rate: completed quizzes / total assigned quizzes
                        $avg_completion_rate = $student_data['total_assigned_quizzes'] > 0 ? round(($completed_count / $student_data['total_assigned_quizzes']) * 100, 1) : 0;
                    ?>
                    <tr class="quiz-reports-row" 
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
                                <?php echo $student_data['total_assigned_quizzes']; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: #d1fae5; color: #065f46; border-radius: 8px; font-weight: 700;">
                                <?php echo $completed_count; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: <?php echo $incompleted_count == 0 ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $incompleted_count == 0 ? '#065f46' : '#991b1b'; ?>; border-radius: 8px; font-weight: 700;">
                                <?php echo $incompleted_count; ?>
                            </span>
                        </td>
                        <td class="center">
                            <span class="quiz-reports-score-badge <?php 
                                if ($student_data['highest_quiz_score'] >= 90) echo 'excellent';
                                elseif ($student_data['highest_quiz_score'] >= 70) echo 'good';
                                elseif ($student_data['highest_quiz_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['highest_quiz_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span class="quiz-reports-score-badge <?php 
                                if ($student_data['lowest_quiz_score'] >= 90) echo 'excellent';
                                elseif ($student_data['lowest_quiz_score'] >= 70) echo 'good';
                                elseif ($student_data['lowest_quiz_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['lowest_quiz_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span class="quiz-reports-score-badge <?php 
                                if ($student_data['avg_quiz_score'] >= 90) echo 'excellent';
                                elseif ($student_data['avg_quiz_score'] >= 70) echo 'good';
                                elseif ($student_data['avg_quiz_score'] >= 50) echo 'average';
                                else echo 'poor';
                            ?>">
                                <?php echo $student_data['avg_quiz_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span style="display: inline-block; padding: 6px 12px; background: <?php 
                                if ($avg_completion_rate >= 80) echo '#d1fae5';
                                elseif ($avg_completion_rate >= 50) echo '#fef3c7';
                                else echo '#fee2e2';
                            ?>; color: <?php 
                                if ($avg_completion_rate >= 80) echo '#065f46';
                                elseif ($avg_completion_rate >= 50) echo '#92400e';
                                else echo '#991b1b';
                            ?>; border-radius: 8px; font-weight: 700;">
                                <?php echo $avg_completion_rate; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="quizReportsEmpty" class="quiz-reports-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <div class="quiz-reports-pagination">
            <div class="quiz-reports-show-entries">
                <span>Show:</span>
                <select id="quizReportsEntriesPerPage">
                    <option value="10" selected>10 entries</option>
                    <option value="25">25 entries</option>
                    <option value="50">50 entries</option>
                    <option value="100">100 entries</option>
                </select>
            </div>
            <div id="quizReportsPaginationInfo" class="quiz-reports-pagination-info">
                Showing 0 to 0 of <?php echo count($quiz_attempts_data); ?> entries
            </div>
            <div class="quiz-reports-pagination-controls">
                <button type="button" id="quizReportsPrev" class="quiz-reports-pagination-btn">&lt; Previous</button>
                <div id="quizReportsPageNumbers" class="quiz-reports-page-numbers"></div>
                <button type="button" id="quizReportsNext" class="quiz-reports-pagination-btn">Next &gt;</button>
            </div>
        </div>
        <?php else: ?>
        <div class="quiz-reports-empty-state">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No quiz attempts data available.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($quiz_attempts_data)): ?>
<script>
// Initialize Charts (with AJAX support)
(function() {
    const daysLabels = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d)); }, $days_labels)); ?>;
    const quizAttempts = <?php echo json_encode(array_values($daily_attempts)); ?>;
    const scoreData = <?php echo json_encode([
        $score_distribution['excellent'],
        $score_distribution['good'],
        $score_distribution['average'],
        $score_distribution['poor']
    ]); ?>;
    const completionData = <?php echo json_encode([
        $completion_status['completed'],
        $completion_status['not_completed'],
        $completion_status['overdue']
    ]); ?>;
    const totalCompletions = <?php echo $completion_status['completed'] + $completion_status['not_completed'] + $completion_status['overdue']; ?>;
    
    function createDailyQuizAttemptsChart() {
        const dailyQuizAttemptsCtx = document.getElementById('dailyQuizAttemptsChart');
        
        if (!dailyQuizAttemptsCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        // Destroy existing chart if any
        if (window.dailyQuizAttemptsChartInstance) {
            try {
                window.dailyQuizAttemptsChartInstance.destroy();
            } catch(e) {}
            window.dailyQuizAttemptsChartInstance = null;
        }
        
        try {
            window.dailyQuizAttemptsChartInstance = new Chart(dailyQuizAttemptsCtx, {
                type: 'line',
                data: {
                    labels: daysLabels,
                    datasets: [{
                        label: 'Quiz Attempts',
                        data: quizAttempts,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            enabled: true,
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 13,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 12
                            },
                            displayColors: true,
                            callbacks: {
                                title: function(context) {
                                    return 'Date: ' + context[0].label;
                                },
                                label: function(context) {
                                    return 'Attempts: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                autoSkip: true,
                                maxTicksLimit: 15,
                                stepSize: 1,
                                padding: 12,
                                font: {
                                    size: 11,
                                    family: "'Inter', sans-serif"
                                },
                                color: '#6b7280'
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.05)',
                                drawBorder: true,
                                drawOnChartArea: true
                            },
                            border: {
                                display: true,
                                color: '#e5e7eb'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                padding: 10,
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.05)',
                                drawBorder: true
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
            return true;
        } catch (error) {
            console.error('Error creating daily quiz attempts chart:', error);
            return false;
        }
    }
    
    function createQuizCompletionChart() {
        const completionCtx = document.getElementById('quizCompletionChart');
        
        if (!completionCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        // Destroy existing chart if any
        if (window.quizCompletionChartInstance) {
            try {
                window.quizCompletionChartInstance.destroy();
            } catch(e) {}
            window.quizCompletionChartInstance = null;
        }
        
        try {
            const completedCount = completionData[0];
            const notCompletedCount = completionData[1];
            const overdueCount = completionData[2];
            
            const completedPercent = totalCompletions > 0 ? ((completedCount / totalCompletions) * 100).toFixed(1) : 0;
            const notCompletedPercent = totalCompletions > 0 ? ((notCompletedCount / totalCompletions) * 100).toFixed(1) : 0;
            const overduePercent = totalCompletions > 0 ? ((overdueCount / totalCompletions) * 100).toFixed(1) : 0;
            
            window.quizCompletionChartInstance = new Chart(completionCtx, {
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
                            '#06b6d4',  // Teal for Completed
                            '#f59e0b',  // Orange for Not Completed
                            '#ef4444'   // Red for Overdue
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
                                padding: 15,
                                font: {
                                    size: 12
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return label + ': ' + value + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            return true;
        } catch (error) {
            console.error('Error creating quiz completion chart:', error);
            return false;
        }
    }
    
    function createScoreDistributionChart() {
        const scoreDistCtx = document.getElementById('scoreDistributionChart');
        
        if (!scoreDistCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        // Destroy existing chart if any
        if (window.scoreDistributionChartInstance) {
            try {
                window.scoreDistributionChartInstance.destroy();
            } catch(e) {}
            window.scoreDistributionChartInstance = null;
        }
        
        try {
            window.scoreDistributionChartInstance = new Chart(scoreDistCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Excellent (90-100%)', 'Good (70-89%)', 'Average (50-69%)', 'Poor (0-49%)'],
                    datasets: [{
                        data: scoreData,
                        backgroundColor: [
                            '#10b981',
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
                            position: 'bottom'
                        }
                    }
                }
            });
            return true;
        } catch (error) {
            console.error('Error creating score distribution chart:', error);
            return false;
        }
    }
    
    // Try to create charts
    function initCharts() {
        const chart1 = createDailyQuizAttemptsChart();
        const chart2 = createScoreDistributionChart();
        const chart3 = createQuizCompletionChart();
        return chart1 && chart2 && chart3;
    }
    
    // Make function globally accessible
    window.initQuizReportsChart = function() {
        return initCharts();
    };
    
    // Try immediately
    if (!initCharts()) {
        // Retry for AJAX-loaded content
        let attempts = 0;
        const maxAttempts = 100;
        
        const retryInterval = setInterval(function() {
            attempts++;
            if (initCharts() || attempts >= maxAttempts) {
                clearInterval(retryInterval);
            }
        }, 100);
        
        // Also try with multiple setTimeout delays
        setTimeout(initCharts, 200);
        setTimeout(initCharts, 500);
        setTimeout(initCharts, 1000);
    }
})();

// Table search and pagination
(function() {
    const allRows = document.querySelectorAll('.quiz-reports-row');
    let entriesPerPage = 10;
    let currentPage = 1;
    
    const searchInput = document.getElementById('quizReportsSearch');
    const clearBtn = document.getElementById('quizReportsClear');
    const tableBody = document.getElementById('quizReportsTableBody');
    const emptyState = document.getElementById('quizReportsEmpty');
    const prevBtn = document.getElementById('quizReportsPrev');
    const nextBtn = document.getElementById('quizReportsNext');
    const pageNumbers = document.getElementById('quizReportsPageNumbers');
    const paginationInfo = document.getElementById('quizReportsPaginationInfo');
    const entriesSelect = document.getElementById('quizReportsEntriesPerPage');
    
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
                html += `<button class="quiz-reports-page-number" data-page="1">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="quiz-reports-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="quiz-reports-page-number" data-page="${totalPages}">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            pageNumbers.querySelectorAll('.quiz-reports-page-number').forEach(btn => {
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

// Quiz row click handler - Navigate to quiz students page
(function() {
    function initQuizNavigation() {
        const quizRows = document.querySelectorAll('.quiz-row-clickable');
        
        if (quizRows.length === 0) {
            return false;
        }
        
        quizRows.forEach(row => {
            row.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const quizId = this.getAttribute('data-quiz-id');
                const quizName = this.getAttribute('data-quiz-name');
                
                // Build URL using moodle_url for proper routing
                const baseUrl = '<?php 
                    $quiz_url = new moodle_url('/theme/remui_kids/school_manager/student_report_quiz_students.php');
                    echo $quiz_url->out(false);
                ?>';
                const url = baseUrl + '?quiz_id=' + encodeURIComponent(quizId) + '&quiz_name=' + encodeURIComponent(quizName);
                
                console.log('Navigating to quiz students page:', url);
                
                // Navigate to new page
                window.location.href = url;
            });
            
            // Add hover effect
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f3f4f6';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
        
        return true;
    }
    
    // Try to initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initQuizNavigation, 100);
        });
    } else {
        setTimeout(initQuizNavigation, 100);
    }
    
    // Also try after a delay for AJAX-loaded content
    setTimeout(initQuizNavigation, 500);
    setTimeout(initQuizNavigation, 1000);
})();

// Quiz Details Pagination and Search
(function() {
    const quizDetailsTable = document.querySelector('.quiz-reports-table-card .quiz-reports-table tbody');
    if (!quizDetailsTable) return;
    
    const allQuizRows = Array.from(quizDetailsTable.querySelectorAll('.quiz-row-clickable'));
    if (allQuizRows.length === 0) return;
    
    let entriesPerPage = 5;
    let currentPage = 1;
    let filteredRows = Array.from(allQuizRows);
    
    const paginationContainer = document.getElementById('quizDetailsPagination');
    const entriesSelect = document.getElementById('quizDetailsEntriesPerPage');
    const paginationInfo = document.getElementById('quizDetailsPaginationInfo');
    const prevBtn = document.getElementById('quizDetailsPrev');
    const nextBtn = document.getElementById('quizDetailsNext');
    const pageNumbers = document.getElementById('quizDetailsPageNumbers');
    const searchInput = document.getElementById('quizDetailsSearch');
    const clearBtn = document.getElementById('quizDetailsClear');
    
    function filterQuizRows() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        filteredRows = allQuizRows.filter(row => {
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
        updateQuizDetailsDisplay();
    }
    
    function updateQuizDetailsDisplay() {
        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        
        // Show/hide rows
        allQuizRows.forEach((row) => {
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
                html += `<button class="quiz-details-page-number" data-page="1">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="quiz-details-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="quiz-details-page-number" data-page="${totalPages}">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            pageNumbers.querySelectorAll('.quiz-details-page-number').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPage = parseInt(this.getAttribute('data-page'));
                    updateQuizDetailsDisplay();
                });
            });
        }
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateQuizDetailsDisplay();
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateQuizDetailsDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateQuizDetailsDisplay();
            }
        });
    }
    
    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterQuizRows();
            if (clearBtn) {
                clearBtn.style.display = this.value.trim() ? 'flex' : 'none';
            }
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterQuizRows();
            }
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                filterQuizRows();
            }
        });
    }
    
    // Initialize
    setTimeout(updateQuizDetailsDisplay, 100);
})();
</script>
<?php endif; ?>

<?php
echo ob_get_clean();
exit;
?>