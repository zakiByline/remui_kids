<?php
/**
 * Student Report Detail Page
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $DB, $USER, $CFG, $PAGE, $OUTPUT;

$studentid = required_param('studentid', PARAM_INT);

// Ensure current user is company manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company information for current manager.
$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company context not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Validate student belongs to the same company.
$student = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
            uifd.data AS grade_level
       FROM {user} u
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
  LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
  LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
      WHERE cu.companyid = ?
        AND r.shortname = 'student'
        AND u.deleted = 0
        AND u.suspended = 0
        AND u.id = ?",
    [$company_info->id, $studentid]
);

if (!$student) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/student_report.php',
        'Student not found or not part of your school.', null, \core\output\notification::NOTIFY_ERROR);
}

/**
 * Helper to calculate per-course progress for a student.
 */
function theme_remui_kids_student_course_progress(int $courseid, int $studentid): float {
    global $DB;

    $total_modules = $DB->count_records_sql(
        "SELECT COUNT(id)
           FROM {course_modules}
          WHERE course = ?
            AND deletioninprogress = 0
            AND completion > 0",
        [$courseid]
    );

    if ($total_modules === 0) {
        return 0;
    }

    $completed_modules = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cmc.coursemoduleid)
           FROM {course_modules_completion} cmc
           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
          WHERE cm.course = ?
            AND cmc.userid = ?
            AND cmc.completionstate IN (1,2,3)",
        [$courseid, $studentid]
    );

    return round(($completed_modules / $total_modules) * 100, 1);
}

function theme_remui_kids_get_course_quiz_details(int $courseid, int $studentid): array {
    global $DB;

    $records = $DB->get_records_sql(
        "SELECT q.id, q.name, q.sumgrades,
                COUNT(qa.id) AS attempts,
                SUM(CASE WHEN qa.state = 'finished' AND qa.timefinish > 0 THEN (qa.timefinish - qa.timestart) ELSE 0 END) AS totaltime,
                MAX(CASE WHEN qa.state = 'finished' THEN qa.timefinish ELSE NULL END) AS lastfinish,
                MAX(CASE WHEN qa.state = 'finished' THEN qa.sumgrades ELSE NULL END) AS lastscore,
                MAX(CASE WHEN qa.state = 'inprogress' THEN 1 ELSE 0 END) AS hasinprogress,
                MAX(CASE WHEN qa.state = 'finished' THEN 1 ELSE 0 END) AS hasfinished
           FROM {quiz} q
      LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = ?
          WHERE q.course = ?
       GROUP BY q.id, q.name, q.sumgrades
       ORDER BY q.name",
        [$studentid, $courseid]
    );

    $details = [];
    foreach ($records as $record) {
        $status = 'not_attempted';
        if ($record->attempts > 0) {
            if (!empty($record->hasfinished)) {
                $status = 'attempted';
            } else if (!empty($record->hasinprogress)) {
                $status = 'in_progress';
            }
        }

        $score = null;
        if (!empty($record->sumgrades) && !empty($record->lastscore)) {
            $score = round(($record->lastscore / $record->sumgrades) * 100, 1);
        }

        $details[] = [
            'id' => $record->id,
            'name' => $record->name,
            'attempts' => (int)$record->attempts,
            'score' => $score,
            'totaltime' => (int)$record->totaltime,
            'submitted' => $record->lastfinish ? (int)$record->lastfinish : null,
            'status' => $status
        ];
    }

    return $details;
}

function theme_remui_kids_get_course_assignment_details(int $courseid, int $studentid): array {
    global $DB;

    $records = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, a.grade AS maxgrade,
                MAX(CASE WHEN s.id IS NOT NULL THEN s.timemodified ELSE NULL END) AS submittedon,
                MAX(CASE WHEN s.status IN ('submitted','graded','reopened') THEN 1 ELSE 0 END) AS hassubmission,
                MAX(CASE WHEN g.grade IS NOT NULL THEN g.grade ELSE NULL END) AS gradevalue,
                MAX(CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END) AS isgraded
           FROM {assign} a
      LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = ?
      LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = ?
          WHERE a.course = ?
       GROUP BY a.id, a.name, a.duedate, a.grade
       ORDER BY a.name",
        [$studentid, $studentid, $courseid]
    );

    $now = time();
    $details = [];
    foreach ($records as $record) {
        $status = 'not_submitted';
        if (!empty($record->hassubmission)) {
            if (!empty($record->submittedon) && $record->duedate && $record->submittedon > $record->duedate) {
                $status = 'late_submitted';
            } else {
                $status = 'submitted';
            }
        } else if (!empty($record->duedate) && $record->duedate > $now) {
            $status = 'due';
        }

        $score = null;
        if (!empty($record->gradevalue) && !empty($record->maxgrade)) {
            $score = round(($record->gradevalue / $record->maxgrade) * 100, 1);
        }

        $details[] = [
            'id' => $record->id,
            'name' => $record->name,
            'gradingstatus' => !empty($record->isgraded) ? 'graded' : 'not_graded',
            'score' => $score,
            'status' => $status,
            'submitted' => $record->submittedon ? (int)$record->submittedon : null
        ];
    }

    return $details;
}

/**
 * Fetch course list for student with status/grades.
 */
function theme_remui_kids_get_student_courses(int $studentid, int $companyid): array {
    global $DB;

    $courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname,
                ue.timecreated AS enrollment_date,
                cc.timecompleted,
                gg.finalgrade, gg.rawgrademax
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {course} c ON c.id = e.courseid
           JOIN {company_course} cc_link ON cc_link.courseid = c.id
      LEFT JOIN {course_completions} cc ON cc.userid = ue.userid AND cc.course = c.id
      LEFT JOIN {grade_grades} gg ON gg.userid = ue.userid
      LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = c.id AND gi.itemtype = 'course'
          WHERE ue.userid = ?
            AND cc_link.companyid = ?
            AND ue.status = 0
            AND c.id > 1
       ORDER BY c.fullname",
        [$studentid, $companyid]
    );

    $results = [];
    foreach ($courses as $course) {
        $status = 'not_started';
        $progress = 0;
        $grade = null;

        if ($course->finalgrade && $course->rawgrademax > 0) {
            $grade = round(($course->finalgrade / $course->rawgrademax) * 100, 1);
        }

        if (!empty($course->timecompleted)) {
            $status = 'completed';
            $progress = 100;
        } else {
            $completion = $DB->get_record_sql(
                "SELECT COUNT(DISTINCT cmc.id) AS completed,
                        (SELECT COUNT(id)
                           FROM {course_modules}
                          WHERE course = ? AND visible = 1 AND deletioninprogress = 0) AS total
                   FROM {course_modules_completion} cmc
                   JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                  WHERE cmc.userid = ? AND cm.course = ? AND cmc.completionstate > 0",
                [$course->id, $studentid, $course->id]
            );

            if ($completion && $completion->total > 0 && $completion->completed > 0) {
                $progress = round(($completion->completed / $completion->total) * 100, 1);
                $status = 'in_progress';
            }

            if ($status === 'not_started') {
                $quiz_attempts = $DB->count_records_sql(
                    "SELECT COUNT(*)
                       FROM {quiz_attempts} qa
                       JOIN {quiz} q ON q.id = qa.quiz
                      WHERE q.course = ? AND qa.userid = ?",
                    [$course->id, $studentid]
                );
                if ($quiz_attempts > 0) {
                    $status = 'in_progress';
                    $progress = max($progress, 10);
                }
            }

            if ($status === 'not_started') {
                $assignment_submissions = $DB->count_records_sql(
                    "SELECT COUNT(*)
                       FROM {assign_submission} s
                       JOIN {assign} a ON a.id = s.assignment
                      WHERE a.course = ? AND s.userid = ? AND s.status = 'submitted'",
                    [$course->id, $studentid]
                );
                if ($assignment_submissions > 0) {
                    $status = 'in_progress';
                    $progress = max($progress, 10);
                }
            }

            if ($status === 'not_started') {
                $course_access = $DB->count_records_sql(
                    "SELECT COUNT(*)
                       FROM {logstore_standard_log}
                      WHERE courseid = ?
                        AND userid = ?
                        AND target = 'course_module'
                        AND action = 'viewed'",
                    [$course->id, $studentid]
                );
                if ($course_access > 0) {
                    $status = 'in_progress';
                    $progress = max($progress, 5);
                }
            }

            if ($status === 'not_started' && $grade !== null && $grade > 0) {
                $status = 'in_progress';
                $progress = max($progress, min(50, $grade / 2));
            }

            $last_access = $DB->get_field_sql(
                "SELECT MAX(timeaccess)
                   FROM {user_lastaccess}
                  WHERE courseid = ? AND userid = ?",
                [$course->id, $studentid]
            );

            if ($status === 'not_started' && $last_access) {
                $status = 'in_progress';
                $progress = max($progress, 3);
            }
        }

        if ($status === 'completed' && $progress < 100) {
            $progress = 100;
        } elseif ($status === 'in_progress' && $progress === 0) {
            $progress = 5;
        }

        $last_access = $DB->get_field_sql(
            "SELECT MAX(timeaccess)
               FROM {user_lastaccess}
              WHERE courseid = ? AND userid = ?",
            [$course->id, $studentid]
        );

        $results[] = [
            'id' => $course->id,
            'name' => $course->fullname,
            'shortname' => $course->shortname,
            'status_key' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'progress' => $progress,
            'grade' => $grade,
            'enrollment_date' => $course->enrollment_date ? userdate($course->enrollment_date, get_string('strftimedatefullshort')) : get_string('never'),
            'lastaccess' => $last_access ? userdate($last_access, get_string('strftimedatefullshort')) : get_string('never'),
            'quizzes' => theme_remui_kids_get_course_quiz_details($course->id, $studentid),
            'assignments' => theme_remui_kids_get_course_assignment_details($course->id, $studentid)
        ];
    }

    return $results;
}

$courses = theme_remui_kids_get_student_courses($student->id, $company_info->id);
$total_courses = count($courses);

$status_counts = [
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0
];

foreach ($courses as $course) {
    if (isset($status_counts[$course['status_key']])) {
        $status_counts[$course['status_key']]++;
    }
}

$quiz_assignment_stats = [
    'quiz_total' => 0,
    'quiz_completed' => 0,
    'assign_total' => 0,
    'assign_submitted' => 0,
    'avg_quiz_grade' => null,
    'avg_assign_grade' => null
];

$courseids = array_column($courses, 'id');
if (!empty($courseids)) {
    list($inplaceholders, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_QM);

    $quiz_assignment_stats['quiz_total'] = (int)$DB->count_records_sql(
        "SELECT COUNT(q.id) FROM {quiz} q WHERE q.course {$inplaceholders}",
        $inparams
    );

    $quiz_assignment_stats['assign_total'] = (int)$DB->count_records_sql(
        "SELECT COUNT(a.id) FROM {assign} a WHERE a.course {$inplaceholders}",
        $inparams
    );

    $quiz_assignment_stats['quiz_completed'] = (int)$DB->count_records_sql(
        "SELECT COUNT(qa.id)
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
          WHERE qa.userid = ?
            AND qa.state = 'finished'
            AND q.course {$inplaceholders}",
        array_merge([$student->id], $inparams)
    );

    $quiz_assignment_stats['assign_submitted'] = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id)
           FROM {assign_submission} s
           JOIN {assign} a ON a.id = s.assignment
          WHERE s.userid = ?
            AND s.status IN ('submitted','graded','reopened')
            AND a.course {$inplaceholders}",
        array_merge([$student->id], $inparams)
    );

    $quiz_grade_record = $DB->get_record_sql(
        "SELECT AVG(CASE WHEN q.sumgrades > 0 THEN (qg.grade / q.sumgrades) * 100 END) AS avggrade
           FROM {quiz_grades} qg
           JOIN {quiz} q ON q.id = qg.quiz
          WHERE qg.userid = ?
            AND q.course {$inplaceholders}
            AND qg.grade IS NOT NULL
            ",
        array_merge([$student->id], $inparams)
    );

    if ($quiz_grade_record && $quiz_grade_record->avggrade !== null) {
        $quiz_assignment_stats['avg_quiz_grade'] = round($quiz_grade_record->avggrade, 1);
    }

    $assign_grade_record = $DB->get_record_sql(
        "SELECT AVG(CASE WHEN a.grade > 0 THEN (ag.grade / a.grade) * 100 END) AS avggrade
           FROM {assign_grades} ag
           JOIN {assign} a ON a.id = ag.assignment
          WHERE ag.userid = ?
            AND a.course {$inplaceholders}
            AND ag.grade IS NOT NULL
            ",
        array_merge([$student->id], $inparams)
    );

    if ($assign_grade_record && $assign_grade_record->avggrade !== null) {
        $quiz_assignment_stats['avg_assign_grade'] = round($assign_grade_record->avggrade, 1);
    }
}

$overall_completion_rate = $total_courses > 0
    ? round(($status_counts['completed'] / $total_courses) * 100, 1)
    : 0;

$PAGE->set_context(context_system::instance());
$page_url = new moodle_url('/theme/remui_kids/school_manager/student_report_detail.php', ['studentid' => $student->id]);
$PAGE->set_url($page_url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Student Detail');
$PAGE->set_heading('Student Detail');

$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'student_repo',
    'student_report_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}
?>

<style>
.student-detail-wrapper {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    padding: 30px 40px 60px;
    font-family: 'Inter', sans-serif;
}

.student-detail-card {
    background: linear-gradient(135deg, #ede5ff, #d6c4ff);
    color: #0f172a;
    border-radius: 28px;
    padding: 30px 40px;
    box-shadow: 0 20px 45px rgba(99, 89, 170, 0.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.35);
}

.student-info h1 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #1e1b4b;
}

.student-info p {
    margin: 6px 0;
    font-size: 0.95rem;
    color: #4c4372;
}

.student-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 14px;
}

.student-stat-card {
    background: rgba(255, 255, 255, 0.9);
    border-radius: 16px;
    padding: 12px 16px;
    border: 1px solid rgba(255, 255, 255, 0.6);
    box-shadow: 0 8px 18px rgba(99, 89, 170, 0.18);
}

.student-stat-card span {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
}

.student-stat-card strong {
    font-size: 1.4rem;
    font-weight: 700;
    color: #111827;
}

.student-detail-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 24px;
    align-items: flex-start;
}

.course-list-column {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.course-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}

.course-summary-card {
    background: #fff;
    border-radius: 16px;
    padding: 14px 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
}

.course-summary-card h5 {
    margin: 0;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #94a3b8;
}

.course-summary-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: #0f172a;
    margin: 10px 0 6px;
}

.course-summary-subtext {
    font-size: 0.85rem;
    color: #64748b;
}

.course-summary-grid.compact {
    grid-template-columns: repeat(2, minmax(150px, 1fr));
    gap: 12px;
    margin: 10px 0 4px;
}

.course-summary-card.compact {
    border-radius: 14px;
    padding: 12px 14px;
    box-shadow: none;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}

.course-summary-value.compact {
    font-size: 1rem;
    margin: 4px 0 2px;
}

.course-summary-subtext.compact {
    font-size: 0.7rem;
}

.course-summary-card.stat-dual-card {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    gap: 4px;
    padding: 12px 14px;
}

.stat-dual-label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
}

.stat-dual-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 8px;
}

.stat-dual-value span {
    color: #94a3b8;
    font-size: 1.1rem;
}

.stat-dual-subtitle {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #a0aec0;
}

.course-card {
    background: #fff;
    border-radius: 20px;
    padding: 20px 24px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    border: 1px solid #eef2ff;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.course-card.overview-trigger {
    cursor: pointer;
}

.course-card.course-card-active {
    border-color: #3b82f6;
    box-shadow: 0 15px 32px rgba(59, 130, 246, 0.18);
}

.course-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.course-status-badge {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
}

.course-status-completed { background: rgba(16,185,129,0.15); color: #047857; }
.course-status-progress { background: rgba(59,130,246,0.15); color: #1d4ed8; }
.course-status-notstarted { background: rgba(248,113,113,0.15); color: #b91c1c; }

.course-progress-bar {
    width: 100%;
    height: 10px;
    border-radius: 999px;
    background: #e5e7eb;
    overflow: hidden;
    margin: 12px 0 6px;
}

.course-detail-template {
    display: none;
}

.course-detail-view {
    display: none;
    margin-top: 16px;
    background: #fff;
    border-radius: 20px;
    padding: 18px 22px;
    border: 1px solid #eef2ff;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
}

.course-detail-view.active {
    display: block;
}

.course-detail-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

.course-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.course-detail-header h4 {
    margin: 0 0 4px 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
}

.course-detail-header small {
    display: block;
    font-size: 0.85rem;
    color: #94a3b8;
    margin-top: 2px;
}

.detail-table {
    display: flex;
    flex-direction: column;
    gap: 0;
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    background: #fff;
}

.detail-count-pill {
    background: #e0e7ff;
    color: #3730a3;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
}

.detail-row {
    display: grid;
    grid-template-columns: 1.5fr 0.7fr 0.8fr 0.9fr 1.1fr 0.9fr;
    gap: 16px;
    font-size: 0.9rem;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    background: #fff;
    transition: background-color 0.2s ease;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-row:hover {
    background: #f8fafc;
}

.detail-row.assignment-row {
    grid-template-columns: 1.5fr 1fr 0.8fr 1fr 1.2fr;
}

.detail-row-head {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
    font-weight: 700;
    color: #64748b;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 12px 16px;
}

.detail-row-head span {
    color: #64748b;
}

.detail-row span {
    color: #1f2937;
    font-weight: 500;
}

.detail-row small {
    display: block;
    color: #94a3b8;
    font-size: 0.8rem;
}

.quiz-status-pill,
.assign-status-pill {
    display: inline-block;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 6px 14px;
    border-radius: 999px;
    text-align: center;
    white-space: nowrap;
}

.quiz-status-pill.attempted { 
    background: #10b981; 
    color: #fff; 
}
.quiz-status-pill.not_attempted { 
    background: #ef4444; 
    color: #fff; 
}
.quiz-status-pill.in_progress { 
    background: #f59e0b; 
    color: #fff; 
}

.assign-status-pill.submitted { 
    background: #10b981; 
    color: #fff; 
}
.assign-status-pill.late_submitted { 
    background: #f97316; 
    color: #fff; 
}
.assign-status-pill.due { 
    background: #3b82f6; 
    color: #fff; 
}
.assign-status-pill.not_submitted { 
    background: #ef4444; 
    color: #fff; 
}

.detail-table-empty {
    font-size: 0.9rem;
    color: #94a3b8;
    background: #fff;
    padding: 32px 24px;
    border-radius: 12px;
    border: 1px dashed #cbd5f5;
    text-align: center;
}

/* Ensure proper alignment for table cells */
.detail-row > span {
    display: flex;
    align-items: center;
}

.detail-row-head > span {
    display: flex;
    align-items: center;
}

/* Responsive adjustments for detail tables */
@media (max-width: 1200px) {
    .detail-row {
        grid-template-columns: 1.2fr 0.6fr 0.7fr 0.8fr 1fr 0.8fr;
        gap: 12px;
        font-size: 0.85rem;
        padding: 12px 14px;
    }
    
    .detail-row.assignment-row {
        grid-template-columns: 1.2fr 0.9fr 0.7fr 0.9fr 1.1fr;
    }
}

@media (max-width: 992px) {
    .course-detail-card {
        padding: 16px 18px;
    }
    
    .detail-row {
        grid-template-columns: 1fr;
        gap: 8px;
        padding: 12px;
    }
    
    .detail-row > span {
        padding: 4px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .detail-row > span:last-child {
        border-bottom: none;
    }
    
    .detail-row > span::before {
        content: attr(data-label);
        font-weight: 700;
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-right: 8px;
        min-width: 120px;
    }
    
    .detail-row-head {
        display: none;
    }
}

.detail-return-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}

.detail-course-title p {
    margin: 4px 0 16px;
    color: #94a3b8;
    font-size: 0.9rem;
}

.course-progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #34d399, #60a5fa);
}

.course-meta-row {
    display: flex;
    gap: 24px;
    font-size: 0.9rem;
    color: #475569;
    margin-top: 6px;
}

.student-overview-column {
    background: #fff;
    border-radius: 20px;
    padding: 22px 26px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    border: 1px solid #eef2ff;
}

.back-link-header {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}

.overview-donut {
    width: 220px;
    height: 220px;
    margin: 20px auto;
}

#course-overview-default.hidden {
    display: none;
}

.overview-summary {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 20px;
}

.overview-summary-row {
    display: flex;
    justify-content: space-between;
    font-weight: 600;
    color: #0f172a;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}

@media (max-width: 1200px) {
    .student-detail-grid {
        grid-template-columns: 1fr;
    }

    .student-detail-card {
        flex-direction: column;
        gap: 20px;
    }
}
</style>

<div class="student-detail-wrapper">
    <div class="student-detail-card">
        <div class="student-info">
            <h1><?php echo fullname($student); ?></h1>
            <p><?php echo $student->grade_level ? 'Grade ' . format_string($student->grade_level) : 'Grade not assigned'; ?></p>
            <p><?php echo s($student->email); ?></p>
        </div>
        <div class="student-stat-grid">
            <div class="student-stat-card">
                <span>Total Courses</span>
                <strong><?php echo $total_courses; ?></strong>
            </div>
            <div class="student-stat-card">
                <span>Completed</span>
            <strong><?php echo $status_counts['completed']; ?></strong>
            </div>
            <div class="student-stat-card">
                <span>In Progress</span>
            <strong><?php echo $status_counts['in_progress']; ?></strong>
            </div>
            <div class="student-stat-card">
                <span>Not Started</span>
            <strong><?php echo $status_counts['not_started']; ?></strong>
            </div>
        </div>
        <a class="back-link-header" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/student_report.php">
            <i class="fa fa-arrow-left"></i> Back to Student Report
        </a>
    </div>

    <div class="student-detail-grid">
        <div class="course-list-column">
            <div class="course-card overview-trigger" id="enrolled-courses-trigger" style="border: 1px solid #dbeafe; background: #eff6ff;">
                <div class="course-header">
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; color: #1d4ed8;">Enrolled Courses</h3>
                        <p style="margin: 4px 0 0; color: #475569;">Overall course completion status.</p>
                    </div>
                    <span class="course-status-badge course-status-progress" style="background: rgba(37,99,235,0.15); color:#1d4ed8;">
                        <?php echo number_format($overall_completion_rate, 1); ?>% completion
                    </span>
                </div>
            </div>

            <?php if (!empty($courses)): ?>
                <?php foreach ($courses as $course): ?>
                    <?php
                        $statusclass = 'course-status-notstarted';
                        if ($course['status_key'] === 'completed') {
                            $statusclass = 'course-status-completed';
                        } else if ($course['status_key'] === 'in_progress') {
                            $statusclass = 'course-status-progress';
                        }
                    ?>
                    <div class="course-card" data-course-id="<?php echo $course['id']; ?>" data-course-name="<?php echo s(format_string($course['name'])); ?>">
                        <div class="course-header">
                            <div>
                                <h4 style="margin: 0; font-size: 1.1rem; color: #0f172a;"><?php echo format_string($course['name']); ?></h4>
                                <small style="color: #94a3b8;"><?php echo format_string($course['shortname']); ?></small>
                            </div>
                            <span class="course-status-badge <?php echo $statusclass; ?>">
                                <?php echo $course['status_label']; ?>
                            </span>
                        </div>

                        <div class="course-progress-bar">
                            <div class="course-progress-fill" style="width: <?php echo min(100, $course['progress']); ?>%;"></div>
                        </div>
                        <div class="course-meta-row" style="font-size:0.85rem;">
                            <span>Progress: <strong><?php echo number_format($course['progress'], 1); ?>%</strong></span>
                            <span>Grade:
                                <?php if ($course['grade'] === null): ?>
                                    <strong style="color:#6b7280;">No grades</strong>
                                <?php else: ?>
                                    <strong><?php echo number_format($course['grade'], 1); ?>%</strong>
                                <?php endif; ?>
                            </span>
                            <span>Last access: <strong><?php echo $course['lastaccess']; ?></strong></span>
                        </div>
                    </div>
                    <div class="course-detail-template" id="course-detail-template-<?php echo $course['id']; ?>">
                        <div class="course-detail-card">
                            <div class="course-detail-header">
                                <div>
                                    <h4>Quiz Assign List</h4>
                                    <small style="color:#94a3b8;">For <?php echo format_string($course['name']); ?></small>
                                </div>
                                <span class="detail-count-pill"><?php echo count($course['quizzes']); ?> quizzes</span>
                            </div>
                            <?php if (!empty($course['quizzes'])): ?>
                                <div class="detail-table">
                                    <div class="detail-row detail-row-head">
                                        <span>Quiz name</span>
                                        <span>Total attempt</span>
                                        <span>Quiz score</span>
                                        <span>Total time</span>
                                        <span>Submitted date</span>
                                        <span>Status</span>
                                    </div>
                                    <?php foreach ($course['quizzes'] as $quiz): ?>
                                        <div class="detail-row">
                                            <span style="font-weight:600;color:#0f172a;"><?php echo format_string($quiz['name']); ?></span>
                                            <span style="text-align:center;"><?php echo $quiz['attempts']; ?></span>
                                            <span style="text-align:center;font-weight:600;"><?php echo $quiz['score'] !== null ? number_format($quiz['score'], 1) . '%' : '—'; ?></span>
                                            <span style="text-align:center;">
                                                <?php 
                                                if ($quiz['totaltime'] > 0) {
                                                    $seconds = $quiz['totaltime'];
                                                    if ($seconds < 60) {
                                                        echo $seconds . ' secs';
                                                    } else if ($seconds < 3600) {
                                                        echo floor($seconds / 60) . ' mins ' . ($seconds % 60) . ' secs';
                                                    } else {
                                                        echo floor($seconds / 3600) . ' hrs ' . floor(($seconds % 3600) / 60) . ' mins';
                                                    }
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </span>
                                            <span style="text-align:center;color:#64748b;font-size:0.85rem;">
                                                <?php 
                                                if ($quiz['submitted']) {
                                                    $date = userdate($quiz['submitted'], '%m/%d/%y');
                                                    $time = userdate($quiz['submitted'], '%H:%M');
                                                    echo $date . ', ' . $time;
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </span>
                                            <span style="text-align:center;">
                                                <span class="quiz-status-pill <?php echo $quiz['status']; ?>">
                                                    <?php 
                                                    $status_label = str_replace('_', ' ', $quiz['status']);
                                                    echo strtoupper($status_label);
                                                    ?>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="detail-table-empty">
                                    No quiz records for this course yet.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="course-detail-card">
                            <div class="course-detail-header">
                                <div>
                                    <h4>Assignment List</h4>
                                    <small style="color:#94a3b8;">For <?php echo format_string($course['name']); ?></small>
                                </div>
                                <span class="detail-count-pill"><?php echo count($course['assignments']); ?> assignments</span>
                            </div>
                            <?php if (!empty($course['assignments'])): ?>
                                <div class="detail-table">
                                    <div class="detail-row detail-row-head assignment-row">
                                        <span>Assignment name</span>
                                        <span>Grading status</span>
                                        <span>Grade score</span>
                                        <span>Status</span>
                                        <span>Submit date & time</span>
                                    </div>
                                    <?php foreach ($course['assignments'] as $assign): ?>
                                        <div class="detail-row assignment-row">
                                            <span style="font-weight:600;color:#0f172a;"><?php echo format_string($assign['name']); ?></span>
                                            <span style="text-align:center;text-transform:capitalize;font-weight:500;">
                                                <?php echo $assign['gradingstatus'] === 'graded' ? 'Graded' : 'Not graded'; ?>
                                            </span>
                                            <span style="text-align:center;font-weight:600;">
                                                <?php echo $assign['score'] !== null ? number_format($assign['score'], 1) . '%' : '—'; ?>
                                            </span>
                                            <span style="text-align:center;">
                                                <span class="assign-status-pill <?php echo $assign['status']; ?>">
                                                    <?php 
                                                    $status_label = str_replace('_', ' ', $assign['status']);
                                                    echo strtoupper($status_label);
                                                    ?>
                                                </span>
                                            </span>
                                            <span style="text-align:center;color:#64748b;font-size:0.85rem;">
                                                <?php 
                                                if ($assign['submitted']) {
                                                    $date = userdate($assign['submitted'], '%m/%d/%y');
                                                    $time = userdate($assign['submitted'], '%H:%M');
                                                    echo $date . ', ' . $time;
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="detail-table-empty">
                                    No assignment records for this course yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="course-card">
                    <p style="margin:0; color:#6b7280;">This student has no enrolled courses yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="student-overview-column">
            <h3 style="margin-top:0; font-size:1.2rem; color:#0f172a;">Course Completion Status</h3>
            <div id="course-overview-default">
                <div class="overview-donut">
                    <canvas id="studentCourseStatusChart"></canvas>
                </div>
                <div class="overview-summary">
                    <div class="overview-summary-row">
                        <span style="color:#16a34a;">Completed</span>
                        <span><?php echo $status_counts['completed']; ?></span>
                    </div>
                    <div class="overview-summary-row">
                        <span style="color:#2563eb;">In Progress</span>
                        <span><?php echo $status_counts['in_progress']; ?></span>
                    </div>
                    <div class="overview-summary-row">
                        <span style="color:#dc2626;">Not Started</span>
                        <span><?php echo $status_counts['not_started']; ?></span>
                    </div>
                </div>
            </div>
            <div id="course-detail-overview" class="course-detail-view"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('studentCourseStatusChart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress', 'Not Started'],
                datasets: [{
                    data: [
                        <?php echo $status_counts['completed']; ?>,
                        <?php echo $status_counts['in_progress']; ?>,
                        <?php echo $status_counts['not_started']; ?>
                    ],
                    backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const total = <?php echo $total_courses; ?>;
                                const value = context.parsed;
                                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + value + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    const courseCards = document.querySelectorAll('.course-card[data-course-id]');
    const detailContainer = document.getElementById('course-detail-overview');
    const defaultPanel = document.getElementById('course-overview-default');

    if (!courseCards.length || !detailContainer || !defaultPanel) {
        return;
    }

    const resetDetailView = () => {
        detailContainer.innerHTML = '';
        detailContainer.classList.remove('active');
        defaultPanel.classList.remove('hidden');
        courseCards.forEach(card => card.classList.remove('course-card-active'));
    };

    const overviewTrigger = document.getElementById('enrolled-courses-trigger');
    if (overviewTrigger) {
        overviewTrigger.addEventListener('click', () => {
            resetDetailView();
            overviewTrigger.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    courseCards.forEach(card => {
        card.addEventListener('click', () => {
            const courseId = card.dataset.courseId;
            const template = document.getElementById(`course-detail-template-${courseId}`);
            if (!template) {
                return;
            }

            const alreadyActive = card.classList.contains('course-card-active');
            courseCards.forEach(c => c.classList.remove('course-card-active'));

            if (alreadyActive) {
                resetDetailView();
                return;
            }

            card.classList.add('course-card-active');
            defaultPanel.classList.add('hidden');
            detailContainer.classList.add('active');
            detailContainer.innerHTML = '';

            const backLink = document.createElement('a');
            backLink.href = '#';
            backLink.className = 'detail-return-link';
            backLink.innerHTML = '<i class="fa fa-arrow-left"></i> Back to completion status';
            backLink.addEventListener('click', (e) => {
                e.preventDefault();
                resetDetailView();
            });
            detailContainer.appendChild(backLink);

            const titleWrap = document.createElement('div');
            titleWrap.className = 'detail-course-title';

            const title = document.createElement('h4');
            title.style.margin = '0';
            title.style.fontSize = '1.1rem';
            title.style.color = '#0f172a';
            title.textContent = card.dataset.courseName || '';
            const subtitle = document.createElement('p');
            subtitle.textContent = 'Quiz & assignment breakdown';

            titleWrap.appendChild(title);
            titleWrap.appendChild(subtitle);
            detailContainer.appendChild(titleWrap);

            detailContainer.insertAdjacentHTML('beforeend', template.innerHTML);
            detailContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();

