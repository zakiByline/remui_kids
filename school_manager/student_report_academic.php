<?php
/**
 * Student Report - Academic Tab (AJAX fragment)
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
        "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'academic']);
    redirect($target);
}

$academic_data = [];
$grade_wise_performance = [];
$grade_chart_data = [];
$term_trend_data = [];
$students_by_grade = []; // Store students organized by grade

if ($company_info) {
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, uifd.data as grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE cu.companyid = ? AND r.shortname = 'student' AND u.deleted = 0 AND u.suspended = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id]
    );

    foreach ($students as $student) {
        $grade_level = $student->grade_level ?? 'Grade Level';
        
        // Get average course grade
        $avg_grade = $DB->get_record_sql(
            "SELECT AVG(CASE WHEN gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND gi.grademax > 0
                THEN (gg.finalgrade / gi.grademax * 100) ELSE NULL END) as avg_grade
             FROM {user_enrolments} ue
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {course} c ON c.id = e.courseid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             LEFT JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ue.userid
             WHERE ue.userid = ? AND cc.companyid = ? AND ue.status = 0 AND c.visible = 1 AND c.id > 1",
            [$student->id, $company_info->id]
        );

        // Get quiz scores
        $quiz_scores = $DB->get_records_sql(
            "SELECT qa.sumgrades, q.sumgrades as maxgrade
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE qa.userid = ? AND cc.companyid = ? AND qa.preview = 0 
             AND qa.state = 'finished' AND qa.timefinish > 0 AND q.sumgrades > 0",
            [$student->id, $company_info->id]
        );

        // Get assignment scores
        $assignment_scores = $DB->get_records_sql(
            "SELECT ag.grade, a.grade as maxgrade
             FROM {assign_grades} ag
             INNER JOIN {assign} a ON a.id = ag.assignment
             INNER JOIN {course} c ON c.id = a.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ag.userid = ? AND cc.companyid = ? AND ag.grade IS NOT NULL AND a.grade > 0",
            [$student->id, $company_info->id]
        );

        // Calculate performance score from all sources
        $all_scores = [];
        if ($avg_grade && $avg_grade->avg_grade !== null) {
            $all_scores[] = (float)$avg_grade->avg_grade;
        }
        foreach ($quiz_scores as $qs) {
            if ($qs->maxgrade > 0) {
                $all_scores[] = ($qs->sumgrades / $qs->maxgrade) * 100;
            }
        }
        foreach ($assignment_scores as $as) {
            if ($as->maxgrade > 0) {
                $all_scores[] = ($as->grade / $as->maxgrade) * 100;
            }
        }

        $performance_score = !empty($all_scores) ? round(array_sum($all_scores) / count($all_scores), 1) : null;
        $highest_score = !empty($all_scores) ? round(max($all_scores), 1) : 0;
        $lowest_score = !empty($all_scores) ? round(min($all_scores), 1) : 0;

        // Get course completion rate
        $total_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {user_enrolments} ue ON ue.userid = ?
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ? AND ue.status = 0 AND c.visible = 1 AND c.id > 1",
            [$student->id, $company_info->id]
        );

        $completed_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.course)
             FROM {course_completions} cc
             INNER JOIN {user_enrolments} ue ON ue.userid = cc.userid
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
             INNER JOIN {company_course} cc_link ON cc_link.courseid = cc.course
             WHERE cc.userid = ? AND cc_link.companyid = ? AND cc.timecompleted IS NOT NULL AND ue.status = 0",
            [$student->id, $company_info->id]
        );

        $completion_rate = $total_courses > 0 ? round(($completed_courses / $total_courses) * 100, 1) : 0;

        // Initialize grade-wise data
        if (!isset($grade_wise_performance[$grade_level])) {
            $grade_wise_performance[$grade_level] = [
                'count' => 0,
                'scores' => [],
                'highest_scores' => [],
                'lowest_scores' => [],
                'completion_rates' => []
            ];
        }

        $grade_wise_performance[$grade_level]['count']++;
        if ($performance_score !== null) {
            $grade_wise_performance[$grade_level]['scores'][] = $performance_score;
        }
        if ($highest_score > 0) {
            $grade_wise_performance[$grade_level]['highest_scores'][] = $highest_score;
        }
        if ($lowest_score > 0) {
            $grade_wise_performance[$grade_level]['lowest_scores'][] = $lowest_score;
        }
        $grade_wise_performance[$grade_level]['completion_rates'][] = $completion_rate;

        $student_data = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'grade_level' => $grade_level,
            'avg_grade' => $performance_score,
            'highest_score' => $highest_score,
            'lowest_score' => $lowest_score,
            'completion_rate' => $completion_rate,
            'total_courses' => $total_courses,
            'completed_courses' => $completed_courses
        ];

        $academic_data[] = $student_data;
        
        // Organize students by grade for drill-down
        // Normalize grade level key (handle variations like "Grade 1", "grade 1", etc.)
        $grade_key = trim($grade_level);
        if (!isset($students_by_grade[$grade_key])) {
            $students_by_grade[$grade_key] = [];
        }
        $students_by_grade[$grade_key][] = $student_data;

        // Get term-based data for trend chart (last 4 terms/quarters)
        $current_year = date('Y');
        $quarters = [];
        for ($q = 4; $q >= 1; $q--) {
            $quarter_start = mktime(0, 0, 0, ($q - 1) * 3 + 1, 1, $current_year);
            $quarter_end = mktime(23, 59, 59, $q * 3, date('t', mktime(0, 0, 0, $q * 3, 1, $current_year)), $current_year);
            
            $term_scores = [];
            // Course grades in this quarter
            $term_course_grades = $DB->get_records_sql(
                "SELECT (gg.finalgrade / gi.grademax * 100) as score
                 FROM {grade_grades} gg
                 INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                 INNER JOIN {course} c ON c.id = gi.courseid
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE gg.userid = ? AND cc.companyid = ? 
                 AND gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND gi.grademax > 0
                 AND gg.timemodified >= ? AND gg.timemodified <= ?",
                [$student->id, $company_info->id, $quarter_start, $quarter_end]
            );
            foreach ($term_course_grades as $tg) {
                $term_scores[] = (float)$tg->score;
            }

            // Quiz scores in this quarter
            $term_quiz_scores = $DB->get_records_sql(
                "SELECT (qa.sumgrades / q.sumgrades * 100) as score
                 FROM {quiz_attempts} qa
                 INNER JOIN {quiz} q ON q.id = qa.quiz
                 INNER JOIN {course} c ON c.id = q.course
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE qa.userid = ? AND cc.companyid = ? 
                 AND qa.preview = 0 AND qa.state = 'finished' AND qa.timefinish > 0 AND q.sumgrades > 0
                 AND qa.timefinish >= ? AND qa.timefinish <= ?",
                [$student->id, $company_info->id, $quarter_start, $quarter_end]
            );
            foreach ($term_quiz_scores as $tq) {
                $term_scores[] = (float)$tq->score;
            }

            $term_avg = !empty($term_scores) ? round(array_sum($term_scores) / count($term_scores), 1) : 0;
            $quarters["Term $q"] = $term_avg;
        }

        // Add current term (current quarter)
        $current_quarter = ceil(date('n') / 3);
        $current_term_start = mktime(0, 0, 0, ($current_quarter - 1) * 3 + 1, 1, $current_year);
        $current_term_scores = [];
        
        $current_course_grades = $DB->get_records_sql(
            "SELECT (gg.finalgrade / gi.grademax * 100) as score
             FROM {grade_grades} gg
             INNER JOIN {grade_items} gi ON gi.id = gg.itemid
             INNER JOIN {course} c ON c.id = gi.courseid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE gg.userid = ? AND cc.companyid = ? 
             AND gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND gi.grademax > 0
             AND gg.timemodified >= ?",
            [$student->id, $company_info->id, $current_term_start]
        );
        foreach ($current_course_grades as $cg) {
            $current_term_scores[] = (float)$cg->score;
        }

        $current_quiz_scores = $DB->get_records_sql(
            "SELECT (qa.sumgrades / q.sumgrades * 100) as score
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE qa.userid = ? AND cc.companyid = ? 
             AND qa.preview = 0 AND qa.state = 'finished' AND qa.timefinish > 0 AND q.sumgrades > 0
             AND qa.timefinish >= ?",
            [$student->id, $company_info->id, $current_term_start]
        );
        foreach ($current_quiz_scores as $cq) {
            $current_term_scores[] = (float)$cq->score;
        }

        $current_avg = !empty($current_term_scores) ? round(array_sum($current_term_scores) / count($current_term_scores), 1) : 0;
        $quarters['Current'] = $current_avg;

        // Store term data by grade
        foreach ($quarters as $term_name => $term_score) {
            if (!isset($term_trend_data[$grade_level][$term_name])) {
                $term_trend_data[$grade_level][$term_name] = [];
            }
            if ($term_score > 0) {
                $term_trend_data[$grade_level][$term_name][] = $term_score;
            }
        }
    }

    // Calculate grade-wise summary
    foreach ($grade_wise_performance as $grade => $data) {
        $avg_score = !empty($data['scores']) ? round(array_sum($data['scores']) / count($data['scores']), 1) : 0;
        $highest = !empty($data['highest_scores']) ? round(max($data['highest_scores']), 1) : 0;
        $lowest = !empty($data['lowest_scores']) ? round(min($data['lowest_scores']), 1) : 0;
        $avg_completion = !empty($data['completion_rates']) ? round(array_sum($data['completion_rates']) / count($data['completion_rates']), 1) : 0;

        $grade_chart_data[] = [
            'grade' => $grade,
            'students' => $data['count'],
            'performance_score' => $avg_score,
            'highest' => $highest,
            'lowest' => $lowest,
            'completion_rate' => $avg_completion
        ];
    }

    // Sort grade data
    usort($grade_chart_data, function($a, $b) {
        // Extract numbers from grade names for proper sorting
        preg_match('/\d+/', $a['grade'], $a_num);
        preg_match('/\d+/', $b['grade'], $b_num);
        $a_num = !empty($a_num) ? (int)$a_num[0] : 999;
        $b_num = !empty($b_num) ? (int)$b_num[0] : 999;
        return $a_num <=> $b_num;
    });

    // Calculate term trend averages by grade
    $term_trend_final = [];
    $term_names = ['Term 1', 'Term 2', 'Term 3', 'Current'];
    foreach ($term_trend_data as $grade => $terms) {
        $term_trend_final[$grade] = [];
        foreach ($term_names as $term) {
            if (isset($terms[$term]) && !empty($terms[$term])) {
                $term_trend_final[$grade][$term] = round(array_sum($terms[$term]) / count($terms[$term]), 1);
            } else {
                $term_trend_final[$grade][$term] = 0;
            }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.academic-reports-container {
    padding: 0;
}

.academic-summary-table-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.academic-summary-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.academic-summary-table-subtitle {
    font-size: 0.9rem;
    color: #6b7280;
    margin: 0 0 20px 0;
}

.academic-summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.academic-summary-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.academic-summary-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.academic-summary-table th.center {
    text-align: center;
}

.academic-summary-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
    cursor: pointer;
}

.academic-summary-table tbody tr:hover {
    background: #f1f5f9;
}

.academic-summary-table tbody tr.grade-row-clicked {
    background: #e0e7ff;
}

.academic-summary-table td {
    padding: 12px;
    color: #1f2937;
}

.academic-summary-table td.center {
    text-align: center;
}

.academic-charts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

.academic-chart-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.academic-chart-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.academic-chart-title i {
    color: #f59e0b;
}

.academic-chart-subtitle {
    font-size: 0.9rem;
    color: #6b7280;
    margin: 0 0 20px 0;
}

.academic-chart-canvas {
    position: relative;
    height: 300px;
}

.grade-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.grade-badge.success {
    background: #d1fae5;
    color: #065f46;
}

.grade-badge.warning {
    background: #fee2e2;
    color: #991b1b;
}

.grade-badge.info {
    color: #1e40af;
}


.grade-students-table-wrapper {
    overflow-x: auto;
}

.grade-students-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    min-width: 1000px;
}

.grade-students-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.grade-students-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.grade-students-table th.center {
    text-align: center;
}

.grade-students-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
}

.grade-students-table tbody tr:hover {
    background: #f9fafb;
}

.grade-students-table td {
    padding: 12px;
    color: #1f2937;
}

.grade-students-table td.center {
    text-align: center;
}

@media (max-width: 1024px) {
    .academic-charts {
        grid-template-columns: 1fr;
    }
    
    .grade-students-modal-content {
        max-width: 95%;
    }
}
</style>

<div class="academic-reports-container">
    <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
        <i class="fa fa-book" style="color: #8b5cf6;"></i> Academic Performance
    </h3>
    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">
        Academic performance and grade averages across courses.
    </p>

    <?php if (!empty($grade_chart_data)): ?>
    <!-- Grade-wise Performance Summary Table -->
    <div class="academic-summary-table-card">
        <h4 class="academic-summary-table-title">
            <i class="fa fa-table"></i> Grade-wise Performance Summary
        </h4>
        <p class="academic-summary-table-subtitle">Average marks per grade level</p>
        <div style="overflow-x: auto;">
            <table class="academic-summary-table">
                <thead>
                    <tr>
                        <th style="min-width: 150px;">Grade</th>
                        <th class="center" style="min-width: 100px;">Students</th>
                        <th class="center" style="min-width: 140px;">Performance Score</th>
                        <th class="center" style="min-width: 100px;">Highest</th>
                        <th class="center" style="min-width: 100px;">Lowest</th>
                        <th class="center" style="min-width: 140px;">Completion %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grade_chart_data as $grade_data): ?>
                    <tr class="grade-row" data-grade="<?php echo htmlspecialchars($grade_data['grade']); ?>" data-grade-key="<?php echo htmlspecialchars($grade_data['grade']); ?>">
                        <td style="font-weight: 600; color: #1f2937;">
                            <i class="fa fa-chevron-right" style="margin-right: 8px; color: #6b7280; font-size: 0.8rem;"></i>
                            <?php echo htmlspecialchars($grade_data['grade']); ?>
                        </td>
                        <td class="center" style="color: #1e40af; font-weight: 700;"><?php echo $grade_data['students']; ?></td>
                        <td class="center">
                            <span class="grade-badge <?php echo $grade_data['performance_score'] >= 50 ? 'success' : 'warning'; ?>">
                                <?php echo $grade_data['performance_score']; ?>%
                            </span>
                        </td>
                        <td class="center">
                            <span class="grade-badge success"><?php echo $grade_data['highest']; ?>%</span>
                        </td>
                        <td class="center">
                            <span class="grade-badge warning"><?php echo $grade_data['lowest']; ?>%</span>
                        </td>
                        <td class="center">
                            <span class="grade-badge <?php echo $grade_data['completion_rate'] >= 50 ? 'success' : 'warning'; ?>" style="<?php echo $grade_data['completion_rate'] < 50 ? 'background: #fee2e2; color: #991b1b;' : ''; ?>">
                                <?php echo $grade_data['completion_rate']; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Charts -->
    <div class="academic-charts">
        <!-- Grade Performance Comparison Bar Chart -->
        <div class="academic-chart-card">
            <h4 class="academic-chart-title">
                <i class="fa fa-bar-chart"></i> Grade Performance Comparison
            </h4>
            <p class="academic-chart-subtitle">Average scores across grade levels</p>
            <div class="academic-chart-canvas">
                <canvas id="gradePerformanceChart"></canvas>
            </div>
        </div>

        <!-- Improvement Trend Line Chart -->
        <div class="academic-chart-card">
            <h4 class="academic-chart-title">
                <i class="fa fa-line-chart"></i> Improvement Trend (Last 3 Terms)
            </h4>
            <p class="academic-chart-subtitle">Academic progress over terms</p>
            <div class="academic-chart-canvas">
                <canvas id="improvementTrendChart"></canvas>
            </div>
        </div>
    </div>


    <script>
    // Grade row click handler - Navigate to separate page
    (function() {
        function initGradeNavigation() {
            const gradeRows = document.querySelectorAll('.grade-row');
            
            if (gradeRows.length === 0) {
                return false;
            }
            
            gradeRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const grade = this.getAttribute('data-grade');
                    const gradeKey = this.getAttribute('data-grade-key');
                    // Build URL using moodle_url for proper routing
                    const gradeParam = encodeURIComponent(grade);
                    const baseUrl = '<?php 
                        $grade_url = new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php');
                        echo $grade_url->out(false);
                    ?>';
                    const url = baseUrl + '?grade=' + gradeParam;
                    
                    console.log('Navigating to grade students page:', url);
                    
                    // Navigate to new page
                    window.location.href = url;
                });
            });
            
            return true;
        }
        
        // Try to initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initGradeNavigation, 100);
            });
        } else {
            setTimeout(initGradeNavigation, 100);
        }
        
        // Also try after a delay for AJAX-loaded content
        setTimeout(initGradeNavigation, 500);
        setTimeout(initGradeNavigation, 1000);
    })();
    
    // Initialize Charts
    (function() {
        const gradeData = <?php echo json_encode($grade_chart_data); ?>;
        const termTrendData = <?php echo json_encode($term_trend_final); ?>;

        function createGradePerformanceChart() {
            const ctx = document.getElementById('gradePerformanceChart');
            if (!ctx || typeof Chart === 'undefined') {
                return false;
            }

            if (window.gradePerformanceChartInstance) {
                try {
                    window.gradePerformanceChartInstance.destroy();
                } catch(e) {}
                window.gradePerformanceChartInstance = null;
            }

            try {
                const labels = gradeData.map(g => g.grade);
                const scores = gradeData.map(g => g.performance_score);

                window.gradePerformanceChartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Performance Score',
                            data: scores,
                            backgroundColor: '#3b82f6',
                            borderColor: '#2563eb',
                            borderWidth: 2,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    stepSize: 10
                                }
                            }
                        }
                    }
                });
                return true;
            } catch (error) {
                console.error('Error creating grade performance chart:', error);
                return false;
            }
        }

        function createImprovementTrendChart() {
            const ctx = document.getElementById('improvementTrendChart');
            if (!ctx || typeof Chart === 'undefined') {
                return false;
            }

            if (window.improvementTrendChartInstance) {
                try {
                    window.improvementTrendChartInstance.destroy();
                } catch(e) {}
                window.improvementTrendChartInstance = null;
            }

            try {
                const termNames = ['Term 1', 'Term 2', 'Term 3', 'Current'];
                const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444'];
                const datasets = [];
                let colorIndex = 0;

                for (const grade in termTrendData) {
                    const termData = termTrendData[grade];
                    datasets.push({
                        label: grade,
                        data: termNames.map(term => termData[term] || 0),
                        borderColor: colors[colorIndex % colors.length],
                        backgroundColor: colors[colorIndex % colors.length] + '20',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    });
                    colorIndex++;
                }

                window.improvementTrendChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: termNames,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    stepSize: 10
                                }
                            }
                        }
                    }
                });
                return true;
            } catch (error) {
                console.error('Error creating improvement trend chart:', error);
                return false;
            }
        }

        function initCharts() {
            const chart1 = createGradePerformanceChart();
            const chart2 = createImprovementTrendChart();
            return chart1 && chart2;
        }

        window.initAcademicCharts = function() {
            return initCharts();
        };

        if (!initCharts()) {
            setTimeout(initCharts, 200);
            setTimeout(initCharts, 500);
            setTimeout(initCharts, 1000);
        }
    })();
    </script>
    <?php endif; ?>
</div>
<?php
echo ob_get_clean();
exit;
?>
