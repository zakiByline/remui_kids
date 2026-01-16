<?php
/**
 * Standalone teacher performance detail page.
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $DB, $USER, $CFG, $OUTPUT, $PAGE;

$teacherid = required_param('teacherid', PARAM_INT);

// Ensure the viewer is a school/company manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$ismanager = false;
$company = null;

if ($companymanagerrole) {
    $syscontext = context_system::instance();
    $ismanager = user_has_role_assignment($USER->id, $companymanagerrole->id, $syscontext->id);
}

if (!$ismanager) {
    redirect(new moodle_url('/my/'), get_string('nopermissions', 'error', 'view this page'), null, \core\output\notification::NOTIFY_ERROR);
}

if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company = $DB->get_record_sql(
        "SELECT c.*
           FROM {company} c
           JOIN {company_users} cu ON cu.companyid = c.id
          WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company) {
    redirect(new moodle_url('/my/'), 'Unable to determine your school/company context.', null, \core\output\notification::NOTIFY_ERROR);
}

// Ensure the requested teacher belongs to this company.
$teacher = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email
       FROM {user} u
       JOIN {company_users} cu ON cu.userid = u.id
      WHERE u.id = ? AND cu.companyid = ?
        AND u.deleted = 0 AND u.suspended = 0",
    [$teacherid, $company->id]
);

if (!$teacher) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'performance']), 'Teacher not found or inaccessible.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch courses assigned to this teacher.
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname
       FROM {course} c
       JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
       JOIN {role_assignments} ra ON ra.contextid = ctx.id
       JOIN {role} r ON r.id = ra.roleid
       JOIN {company_course} cc ON cc.courseid = c.id
      WHERE ra.userid = ?
        AND r.shortname IN ('teacher', 'editingteacher')
        AND cc.companyid = ?
        AND c.visible = 1
        AND c.id > 1
   ORDER BY c.fullname",
    [$teacherid, $company->id]
);

$courses_taught = count($courses);
$course_details = [];
$total_students = 0;
$completed_students = 0;
$avg_course_grade = null;
$avg_quiz_score = null;
$avg_assignment_grade = null;
$assessment_totals = [
    'quizzes_created' => 0,
    'assignments_created' => 0,
    'quiz_attempts' => 0,
    'assignment_submissions' => 0
];

if ($courses) {
    $courseids = array_keys($courses);
    list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

    // Total / completed students across all courses.
    $student_counts = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT u.id) AS total_students,
                COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN u.id END) AS completed_students
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {user_enrolments} ue ON ue.userid = u.id
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
           JOIN {role} r ON r.id = ra.roleid
           LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
          WHERE e.courseid $insql
            AND ue.status = 0
            AND e.status = 0
            AND r.shortname = 'student'
            AND u.deleted = 0
            AND u.suspended = 0",
        ['companyid' => $company->id] + $inparams
    );
    if ($student_counts) {
        $total_students = (int)$student_counts->total_students;
        $completed_students = (int)$student_counts->completed_students;
    }

    // Average course grade.
    $grade_row = $DB->get_record_sql(
        "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_course
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
           JOIN {user} u ON u.id = gg.userid
           JOIN {company_users} cu ON cu.userid = u.id
          WHERE gi.courseid $insql
            AND cu.companyid = :companyid
            AND gi.itemtype = 'course'
            AND gg.finalgrade IS NOT NULL
            AND gg.rawgrademax > 0
            AND u.deleted = 0
            AND u.suspended = 0",
        ['companyid' => $company->id] + $inparams
    );
    if ($grade_row && $grade_row->avg_course !== null) {
        $avg_course_grade = round((float)$grade_row->avg_course, 1);
    }

    // Average quiz score.
    $quiz_row = $DB->get_record_sql(
        "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_quiz
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
           JOIN {user} u ON u.id = gg.userid
           JOIN {company_users} cu ON cu.userid = u.id
          WHERE gi.courseid $insql
            AND cu.companyid = :companyid
            AND gi.itemtype = 'mod'
            AND gi.itemmodule = 'quiz'
            AND gg.finalgrade IS NOT NULL
            AND gg.rawgrademax > 0
            AND u.deleted = 0
            AND u.suspended = 0",
        ['companyid' => $company->id] + $inparams
    );
    if ($quiz_row && $quiz_row->avg_quiz !== null) {
        $avg_quiz_score = round((float)$quiz_row->avg_quiz, 1);
    }

    // Average assignment grade.
    $assign_row = $DB->get_record_sql(
        "SELECT AVG((ag.grade / a.grade) * 100) AS avg_assignment
           FROM {assign_grades} ag
           JOIN {assign} a ON a.id = ag.assignment
           JOIN {user} u ON u.id = ag.userid
           JOIN {company_users} cu ON cu.userid = u.id
          WHERE a.course $insql
            AND cu.companyid = :companyid
            AND ag.grade IS NOT NULL
            AND ag.grade >= 0
            AND a.grade > 0",
        ['companyid' => $company->id] + $inparams
    );
    if ($assign_row && $assign_row->avg_assignment !== null) {
        $avg_assignment_grade = round((float)$assign_row->avg_assignment, 1);
    }

    foreach ($courses as $course) {
        $course_params = [
            'courseid' => $course->id,
            'companyid' => $company->id
        ];

        $course_student_stats = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT u.id) AS total_students,
                    COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN u.id END) AS completed_students
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
               JOIN {role} r ON r.id = ra.roleid
               LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
              WHERE ue.status = 0
                AND e.status = 0
                AND r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0",
            $course_params
        );

        $course_total_students = $course_student_stats ? (int)$course_student_stats->total_students : 0;
        $course_completed_students = $course_student_stats ? (int)$course_student_stats->completed_students : 0;
        $course_completion_rate = $course_total_students > 0
            ? round(($course_completed_students / $course_total_students) * 100, 1)
            : 0;

        $course_grade_record = $DB->get_record_sql(
            "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_grade
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
               JOIN {user} u ON u.id = gg.userid
               JOIN {company_users} cu ON cu.userid = u.id
              WHERE gi.courseid = :courseid
                AND cu.companyid = :companyid
                AND gi.itemtype = 'course'
                AND gg.finalgrade IS NOT NULL
                AND gg.rawgrademax > 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $course_params
        );
        $course_grade_value = ($course_grade_record && $course_grade_record->avg_grade !== null)
            ? round((float)$course_grade_record->avg_grade, 1)
            : null;

        $course_quiz_grade_record = $DB->get_record_sql(
            "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_grade
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
               JOIN {user} u ON u.id = gg.userid
               JOIN {company_users} cu ON cu.userid = u.id
              WHERE gi.courseid = :courseid
                AND cu.companyid = :companyid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule = 'quiz'
                AND gg.finalgrade IS NOT NULL
                AND gg.rawgrademax > 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $course_params
        );
        $course_quiz_grade_value = ($course_quiz_grade_record && $course_quiz_grade_record->avg_grade !== null)
            ? round((float)$course_quiz_grade_record->avg_grade, 1)
            : null;

        $course_assignment_grade_record = $DB->get_record_sql(
            "SELECT AVG((ag.grade / a.grade) * 100) AS avg_grade
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
               JOIN {user} u ON u.id = ag.userid
               JOIN {company_users} cu ON cu.userid = u.id
              WHERE a.course = :courseid
                AND cu.companyid = :companyid
                AND ag.grade IS NOT NULL
                AND ag.grade >= 0
                AND a.grade > 0",
            $course_params
        );
        $course_assignment_grade_value = ($course_assignment_grade_record && $course_assignment_grade_record->avg_grade !== null)
            ? round((float)$course_assignment_grade_record->avg_grade, 1)
            : null;

        $quizzes_created = $DB->count_records('quiz', ['course' => $course->id]);
        $assignments_created = $DB->count_records('assign', ['course' => $course->id]);
        $quiz_attempts = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.id)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {user} u ON u.id = qa.userid
               JOIN {company_users} cu ON cu.userid = u.id
              WHERE q.course = :courseid
                AND cu.companyid = :companyid
                AND qa.state = 'finished'
                AND qa.timefinish > 0",
            $course_params
        );
        $assignment_submissions = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
               JOIN {user} u ON u.id = ag.userid
               JOIN {company_users} cu ON cu.userid = u.id
              WHERE a.course = :courseid
                AND cu.companyid = :companyid
                AND ag.grade IS NOT NULL
                AND ag.grade >= 0",
            $course_params
        );

        $assessment_totals['quizzes_created'] += $quizzes_created;
        $assessment_totals['assignments_created'] += $assignments_created;
        $assessment_totals['quiz_attempts'] += $quiz_attempts;
        $assessment_totals['assignment_submissions'] += $assignment_submissions;

        $course_details[] = [
            'name' => $course->fullname,
            'students' => $course_total_students,
            'completed' => $course_completed_students,
            'completion_rate' => $course_completion_rate,
            'avg_course_grade' => $course_grade_value,
            'avg_quiz_score' => $course_quiz_grade_value,
            'avg_assignment_grade' => $course_assignment_grade_value
        ];
    }
}

$completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_performance.php', ['teacherid' => $teacherid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Teacher Performance - ' . fullname($teacher));
$PAGE->set_heading('Teacher Performance');

$sidebarcontext = [
    'company_name' => $company->name ?? 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'teacher_report',
    'teacher_report_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
    echo "<script>window.forceSidebarAlways = true;</script>";
} catch (Exception $e) {
    echo "<div style='color:red;padding:20px;'>Sidebar error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$backurl = new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'performance']);
$teachername = fullname($teacher);

?>
<style>
    .school-manager-main-content {
        position: fixed;
        top: 55px;
        left: 280px;
        right: 0;
        bottom: 0;
        overflow-y: auto;
        padding: 24px;
        background: #f8fafc;
        font-family: 'Inter', sans-serif;
    }
    .performance-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    .performance-header {
        background: linear-gradient(135deg, #d8b4fe 0%, #a5f3fc 100%);
        border-radius: 18px;
        padding: 28px 30px;
        color: #0f172a;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 15px 30px rgba(79, 70, 229, 0.18);
    }
    .performance-header h1 {
        margin: 0;
        font-size: 2rem;
        font-weight: 700;
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 16px;
    }
    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 18px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .summary-card span {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #94a3b8;
        font-weight: 600;
    }
    .summary-card strong {
        font-size: 2rem;
        color: #0f172a;
        font-weight: 800;
    }
    .summary-card small {
        color: #64748b;
        font-size: 0.8rem;
    }
    .section-card {
        background: white;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        padding: 22px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }
    .section-card h3 {
        margin: 0 0 16px 0;
        font-size: 1.2rem;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    table.performance-table {
        width: 100%;
        border-collapse: collapse;
    }
    table.performance-table th,
    table.performance-table td {
        padding: 12px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 0.92rem;
        text-align: left;
    }
    table.performance-table th {
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.4px;
        color: #94a3b8;
    }
    .pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 48px;
        padding: 6px 14px;
        border-radius: 999px;
        font-weight: 600;
        background: #e0f2fe;
        color: #0f172a;
    }
    .back-link {
        text-decoration: none;
        padding: 10px 18px;
        border-radius: 8px;
        border: 1px solid rgba(99, 102, 241, 0.4);
        font-weight: 600;
        color: #4338ca;
        background: rgba(255, 255, 255, 0.9);
    }
</style>

<div class="school-manager-main-content">
    <div class="performance-wrapper">
        <div class="performance-header">
            <div>
                <h1><?php echo format_string($teachername); ?></h1>
                <p style="margin:0;color:#1e293b;">
                    <?php echo $courses_taught; ?> course<?php echo $courses_taught === 1 ? '' : 's'; ?> ·
                    <?php echo $total_students; ?> student<?php echo $total_students === 1 ? '' : 's'; ?> ·
                    <?php echo $completion_rate; ?>% completion
                </p>
            </div>
            <a class="back-link" href="<?php echo $backurl; ?>">&larr; Back to Teacher Performance</a>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <span>Courses Assigned</span>
                <strong><?php echo $courses_taught; ?></strong>
                <small>Active courses</small>
            </div>
            <div class="summary-card">
                <span>Total Students</span>
                <strong><?php echo number_format($total_students); ?></strong>
                <small><?php echo $completed_students; ?> completed · <?php echo max(0, $total_students - $completed_students); ?> pending</small>
            </div>
            <div class="summary-card">
                <span>Completion Rate</span>
                <strong><?php echo $completion_rate; ?>%</strong>
                <small>Finished vs enrolled</small>
            </div>
            <div class="summary-card">
                <span>Avg Course Grade</span>
                <strong><?php echo $avg_course_grade !== null ? $avg_course_grade . '%' : 'N/A'; ?></strong>
            </div>
            <div class="summary-card">
                <span>Avg Quiz Score</span>
                <strong><?php echo $avg_quiz_score !== null ? $avg_quiz_score . '%' : 'N/A'; ?></strong>
            </div>
            <div class="summary-card">
                <span>Avg Assignment Grade</span>
                <strong><?php echo $avg_assignment_grade !== null ? $avg_assignment_grade . '%' : 'N/A'; ?></strong>
            </div>
            <div class="summary-card">
                <span>Assessments Created</span>
                <strong><?php echo number_format($assessment_totals['quizzes_created'] + $assessment_totals['assignments_created']); ?></strong>
                <small><?php echo $assessment_totals['quizzes_created']; ?> quizzes · <?php echo $assessment_totals['assignments_created']; ?> assignments</small>
            </div>
            <div class="summary-card">
                <span>Graded Submissions</span>
                <strong><?php echo number_format($assessment_totals['quiz_attempts'] + $assessment_totals['assignment_submissions']); ?></strong>
                <small><?php echo $assessment_totals['quiz_attempts']; ?> quiz attempts · <?php echo $assessment_totals['assignment_submissions']; ?> assignments</small>
            </div>
        </div>

        <div class="section-card">
            <h3><i class="fa fa-chart-line" style="color:#6366f1;"></i> Course Performance</h3>
            <?php if (!empty($course_details)): ?>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Students</th>
                            <th>Completed</th>
                            <th>Completion %</th>
                            <th>Avg Grade</th>
                            <th>Avg Quiz</th>
                            <th>Avg Assignment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_details as $detail): ?>
                            <tr>
                                <td><?php echo format_string($detail['name']); ?></td>
                                <td><?php echo number_format($detail['students']); ?></td>
                                <td><?php echo number_format($detail['completed']); ?></td>
                                <td><?php echo $detail['completion_rate']; ?>%</td>
                                <td><?php echo $detail['avg_course_grade'] !== null ? $detail['avg_course_grade'] . '%' : 'N/A'; ?></td>
                                <td><?php echo $detail['avg_quiz_score'] !== null ? $detail['avg_quiz_score'] . '%' : 'N/A'; ?></td>
                                <td><?php echo $detail['avg_assignment_grade'] !== null ? $detail['avg_assignment_grade'] . '%' : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding:24px;text-align:center;color:#94a3b8;">
                    <i class="fa fa-info-circle" style="font-size:1.5rem;margin-bottom:8px;"></i><br>
                    No active courses found for this teacher.
                </div>
            <?php endif; ?>
        </div>

        <div class="section-card">
            <h3><i class="fa fa-clipboard-check" style="color:#10b981;"></i> Assessment Overview</h3>
            <?php if ($assessment_totals['quizzes_created'] || $assessment_totals['assignments_created']): ?>
                <p style="margin:0;color:#475569;">
                    Quizzes created: <strong><?php echo $assessment_totals['quizzes_created']; ?></strong> ·
                    Assignments created: <strong><?php echo $assessment_totals['assignments_created']; ?></strong> ·
                    Quiz attempts graded: <strong><?php echo $assessment_totals['quiz_attempts']; ?></strong> ·
                    Assignment submissions graded: <strong><?php echo $assessment_totals['assignment_submissions']; ?></strong>
                </p>
            <?php else: ?>
                <div style="padding:24px;text-align:center;color:#94a3b8;">
                    <i class="fa fa-info-circle" style="font-size:1.5rem;margin-bottom:8px;"></i><br>
                    No assessments have been created or graded for this teacher yet.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();








