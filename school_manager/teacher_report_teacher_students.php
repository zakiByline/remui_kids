<?php
/**
 * Teacher course & student summary page (School Manager)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $DB, $USER, $CFG, $OUTPUT, $PAGE;

$teacherid = required_param('teacherid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Ensure current user is a school manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;
$company_info = null;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect(
        new moodle_url('/my/'),
        get_string('nopermissions', 'error', 'view this page'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Fetch company info for sidebar context + scoping.
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
           FROM {company} c
           JOIN {company_users} cu ON cu.companyid = c.id
          WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Unable to determine company context.', null, \core\output\notification::NOTIFY_ERROR);
}

// Validate the teacher belongs to the same company.
$teacher = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email
       FROM {user} u
       JOIN {company_users} cu ON cu.userid = u.id
      WHERE u.id = ? AND cu.companyid = ?
        AND u.deleted = 0 AND u.suspended = 0",
    [$teacherid, $company_info->id]
);

if (!$teacher) {
    redirect(new moodle_url('/theme/remui_kids/school_manager/teacher_report.php'), 'Teacher not found or inaccessible.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch courses taught by this teacher for current company.
$teacher_courses = $DB->get_records_sql(
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
   ORDER BY c.fullname ASC",
    [$teacher->id, $company_info->id]
);

$course_rows = [];
$total_course_students = 0;
$selected_course = null;
$course_student_rows = [];
$course_student_summary = [
    'total' => 0,
    'completed' => 0,
    'average_grade' => null
];

if ($teacher_courses) {
    foreach ($teacher_courses as $course) {
        $studentcount = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
               JOIN {role} r ON r.id = ra.roleid
              WHERE ue.status = 0
                AND r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0",
            ['companyid' => $company_info->id, 'courseid' => $course->id]
        );

        $course_rows[] = [
            'id' => $course->id,
            'name' => $course->fullname,
            'students' => $studentcount
        ];
        $total_course_students += $studentcount;
    }

    if (!$courseid && !empty($course_rows)) {
        $courseid = $course_rows[0]['id'];
    }

    if ($courseid && array_key_exists($courseid, $teacher_courses)) {
        $selected_course = $teacher_courses[$courseid];

        $course_student_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    ue.timecreated AS enrolledat,
                    cc.timecompleted,
                    gg.finalgrade,
                    gg.rawgrademax
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
               JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :courseid
               LEFT JOIN {grade_items} gi ON gi.courseid = :courseid AND gi.itemtype = 'course'
               LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = u.id
              WHERE ue.status = 0
                AND e.status = 0
                AND u.deleted = 0
                AND u.suspended = 0
           ORDER BY u.lastname ASC, u.firstname ASC",
            [
                'companyid' => $company_info->id,
                'courseid' => $courseid
            ]
        );

        if (!empty($course_student_records)) {
            $newrows = [];
            $completedcount = 0;
            $gradesum = 0;
            $gradecount = 0;
            foreach ($course_student_records as $record) {
                if (!empty($record->timecompleted)) {
                    $completedcount++;
                }
                if ($record->finalgrade !== null && $record->rawgrademax > 0) {
                    $gradesum += round(($record->finalgrade / $record->rawgrademax) * 100, 2);
                    $gradecount++;
                }
                $gradepercent = null;
                if ($record->finalgrade !== null && $record->rawgrademax > 0) {
                    $gradepercent = round(($record->finalgrade / $record->rawgrademax) * 100, 1);
                }
                $newrows[] = [
                    'name' => fullname($record),
                    'email' => $record->email ?? '',
                    'enrolled' => $record->enrolledat ? userdate($record->enrolledat, get_string('strftimedateshort')) : get_string('never'),
                    'completed' => !empty($record->timecompleted),
                    'completed_date' => !empty($record->timecompleted) ? userdate($record->timecompleted, get_string('strftimedateshort')) : null,
                    'grade' => $gradepercent
                ];
            }
            $course_student_rows = $newrows;
            $course_student_summary['total'] = count($course_student_rows);
            $course_student_summary['completed'] = $completedcount;
            if ($gradecount > 0) {
                $course_student_summary['average_grade'] = round($gradesum / $gradecount, 1);
            }
        }
    }
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_students.php', ['teacherid' => $teacher->id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Teacher Courses - ' . fullname($teacher));
$PAGE->set_heading('Teacher Courses');

$sidebarcontext = [
    'company_name' => $company_info->name ?? 'School',
    'config' => ['wwwroot' => $CFG->wwwroot],
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'teacher_report',
    'teacher_report_active' => true
];

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
    echo "<script>window.forceSidebarAlways = true;</script>";
} catch (Exception $e) {
    echo "<div style='color:red;padding:20px;'>Sidebar error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$backurl = new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'overview']);
$teachername = fullname($teacher);

?>
<style>
    .school-manager-main-content {
        position: fixed;
        top: 55px;
        left: 280px;
        right: 0;
        bottom: 0;
        padding: 24px;
        overflow-y: auto;
        background: #f8fafc;
        font-family: 'Inter', sans-serif;
    }
.teacher-course-wrapper {
    max-width: 1800px;
        margin: 0 auto;
        width: 100%;
    }
    .teacher-course-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #e0bbe4 0%, #a7dbd8 100%);
        padding: 24px 30px;
        border-radius: 18px;
        color: #1f2937;
        margin-bottom: 24px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
    }
    .teacher-course-header h1 {
        margin: 0 0 6px 0;
        font-size: 1.9rem;
        font-weight: 700;
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }
    .summary-card span {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 600;
    }
    .summary-card strong {
        display: block;
        margin-top: 8px;
        font-size: 2rem;
        font-weight: 800;
        color: #111827;
    }
    .course-table-wrapper {
        background: white;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.1);
        padding: 20px;
    }
    .course-table-wrapper table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }
    .course-table-wrapper table tr:hover {
        background: #f8fafc;
    }
    .course-row-selected {
        background: #eef2ff;
    }
    .course-table-wrapper th,
    .course-table-wrapper td {
        padding: 14px 12px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }
    .course-table-wrapper th {
        text-transform: uppercase;
        font-size: 0.8rem;
        color: #64748b;
        letter-spacing: 0.4px;
    }
    .pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 14px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0f172a;
        font-weight: 600;
        min-width: 48px;
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 600;
        font-size: 0.8rem;
    }
    .status-pill.complete {
        background: #dcfce7;
        color: #065f46;
    }
    .status-pill.in-progress {
        background: #fee2e2;
        color: #b91c1c;
    }
    .course-link {
        text-decoration: none;
        color: #0f172a;
        font-weight: 600;
    }
    .course-link:hover {
        color: #3b82f6;
    }
    .back-link {
        text-decoration: none;
        padding: 10px 16px;
        border-radius: 8px;
        border: 1px solid #c7d2fe;
        background: white;
        color: #4338ca;
        font-weight: 600;
    }
</style>

<div class="school-manager-main-content">
    <div class="teacher-course-wrapper">
        <div class="teacher-course-header">
            <div>
                <h1><?php echo format_string($teachername); ?></h1>
                <p style="margin:0;color:#334155;">Courses & students assigned to this teacher.</p>
            </div>
            <a class="back-link" href="<?php echo $backurl; ?>">&larr; Back to Teacher Overview</a>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <span>Total Courses</span>
                <strong><?php echo count($course_rows); ?></strong>
            </div>
            <div class="summary-card">
                <span>Total Students Across Courses</span>
                <strong><?php echo number_format($total_course_students); ?></strong>
            </div>
            <div class="summary-card">
                <span>Teacher Email</span>
                <strong style="font-size:1.1rem;"><?php echo s($teacher->email ?? ''); ?></strong>
            </div>
        </div>

        <div class="course-table-wrapper">
            <h3 style="margin-top:0;margin-bottom:16px;color:#0f172a;">Course List</h3>
            <?php if (!empty($course_rows)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th style="text-align:center;">Total Students</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_rows as $row): ?>
                            <?php
                                $iscurrent = ($selected_course && (int)$selected_course->id === (int)$row['id']);
                                $courselink = new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_students.php', [
                                    'teacherid' => $teacher->id,
                                    'courseid' => $row['id']
                                ]);
                            ?>
                            <tr class="<?php echo $iscurrent ? 'course-row-selected' : ''; ?>">
                                <td>
                                    <a class="course-link" href="<?php echo $courselink; ?>">
                                        <?php echo format_string($row['name']); ?>
                                        <?php if ($iscurrent): ?>
                                            <span style="font-size:0.75rem;color:#6366f1;margin-left:6px;">(selected)</span>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td style="text-align:center;">
                                    <span class="pill"><?php echo number_format($row['students']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding:32px;text-align:center;color:#94a3b8;">
                    <i class="fa fa-info-circle" style="font-size:2rem;margin-bottom:10px;"></i><br>
                    No active courses found for this teacher.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($selected_course): ?>
            <div class="course-table-wrapper" style="margin-top:24px;">
                <h3 style="margin-top:0;margin-bottom:16px;color:#0f172a;">
                    Student Performance — <?php echo format_string($selected_course->fullname); ?>
                </h3>
                <div class="summary-grid" style="margin-top:0;">
                    <div class="summary-card">
                        <span>Total Students</span>
                        <strong><?php echo number_format($course_student_summary['total']); ?></strong>
                    </div>
                    <div class="summary-card">
                        <span>Completed</span>
                        <strong><?php echo number_format($course_student_summary['completed']); ?></strong>
                    </div>
                    <div class="summary-card">
                        <span>Average Grade</span>
                        <strong>
                            <?php echo $course_student_summary['average_grade'] !== null
                                ? $course_student_summary['average_grade'] . '%'
                                : get_string('na', 'moodle'); ?>
                        </strong>
                    </div>
                </div>

                <?php if (!empty($course_student_rows)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Enrolled</th>
                                <th style="text-align:center;">Completion</th>
                                <th style="text-align:center;">Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_student_rows as $student): ?>
                                <tr>
                                    <td><?php echo format_string($student['name']); ?></td>
                                    <td><?php echo s($student['email']); ?></td>
                                    <td><?php echo $student['enrolled']; ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($student['completed']): ?>
                                            <span class="status-pill complete">Completed</span>
                                        <?php else: ?>
                                            <span class="status-pill in-progress">In progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php echo $student['grade'] !== null ? $student['grade'] . '%' : get_string('na', 'moodle'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding:32px;text-align:center;color:#94a3b8;">
                        <i class="fa fa-info-circle" style="font-size:2rem;margin-bottom:10px;"></i><br>
                        No students found for this course.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
