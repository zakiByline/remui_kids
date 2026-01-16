<?php
/**
 * Course Overview Detail page.
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

$courseid = required_param('courseid', PARAM_INT);

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
           FROM {company} c
           JOIN {company_users} cu ON c.id = cu.companyid
          WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/c_reports.php', 'Unable to load company information.', null, \core\output\notification::NOTIFY_ERROR);
}

$course = $DB->get_record_sql(
    "SELECT c.*, cat.name AS categoryname
       FROM {course} c
       JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
  LEFT JOIN {course_categories} cat ON cat.id = c.category
      WHERE c.id = :courseid AND c.visible = 1 AND c.id > 1",
    ['companyid' => $company_info->id, 'courseid' => $courseid]
);

if (!$course) {
    redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/c_reports.php?tab=overview', 'Course not found or not assigned to your company.', null, \core\output\notification::NOTIFY_ERROR);
}

// Total students.
$total_students = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
       JOIN {role} r ON r.id = ra.roleid
      WHERE e.courseid = ?
        AND ue.status = 0
        AND cu.companyid = ?
        AND r.shortname = 'student'
        AND u.deleted = 0
        AND u.suspended = 0",
    [CONTEXT_COURSE, $course->id, $company_info->id]
);

// Total teachers (enrolled with teacher/editingteacher role).
$total_teachers = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
       JOIN {role} r ON r.id = ra.roleid
      WHERE e.courseid = ?
        AND ue.status = 0
        AND cu.companyid = ?
        AND r.shortname IN ('teacher', 'editingteacher')
        AND COALESCE(cu.educator, 0) = 1
        AND u.deleted = 0
        AND u.suspended = 0",
    [CONTEXT_COURSE, $course->id, $company_info->id]
);

$quiz_module_id = $DB->get_field('modules', 'id', ['name' => 'quiz']);
$assign_module_id = $DB->get_field('modules', 'id', ['name' => 'assign']);

$total_quizzes = 0;
if ($quiz_module_id) {
    $total_quizzes = $DB->count_records_sql(
        "SELECT COUNT(*)
           FROM {course_modules}
          WHERE course = ? AND module = ? AND visible = 1 AND deletioninprogress = 0",
        [$course->id, $quiz_module_id]
    );
}

$total_assignments = 0;
if ($assign_module_id) {
    $total_assignments = $DB->count_records_sql(
        "SELECT COUNT(*)
           FROM {course_modules}
          WHERE course = ? AND module = ? AND visible = 1 AND deletioninprogress = 0",
        [$course->id, $assign_module_id]
    );
}

$enrolled_students_data = $DB->get_records_sql(
    "SELECT DISTINCT u.id, cc.timecompleted, cc.timestarted
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
       JOIN {role} r ON r.id = ra.roleid
  LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
      WHERE e.courseid = ?
        AND ue.status = 0
        AND cu.companyid = ?
        AND r.shortname = 'student'
        AND u.deleted = 0
        AND u.suspended = 0",
    [CONTEXT_COURSE, $course->id, $company_info->id]
);

$total_modules = $DB->count_records_sql(
    "SELECT COUNT(id)
       FROM {course_modules}
      WHERE course = ? AND visible = 1 AND deletioninprogress = 0 AND completion > 0",
    [$course->id]
);

$total_progress = 0;
$students_count = count($enrolled_students_data);
$student_progress_map = [];

foreach ($enrolled_students_data as $student_data) {
    $student_progress = 0;
    if ($student_data->timecompleted) {
        $student_progress = 100;
    } else if ($total_modules > 0) {
        $completed_modules = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cmc.coursemoduleid)
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid = ?
                AND cm.course = ?
                AND cm.visible = 1
                AND cm.deletioninprogress = 0
                AND cm.completion > 0
                AND cmc.completionstate IN (1,2,3)",
            [$student_data->id, $course->id]
        );
        $student_progress = $completed_modules > 0 ? round(($completed_modules / $total_modules) * 100, 1) : 0;
    } elseif ($student_data->timestarted) {
        $student_progress = 5;
    }
    $total_progress += $student_progress;
    $student_progress_map[$student_data->id] = $student_progress;
}

$average_completion_rate = $students_count > 0 ? round($total_progress / $students_count, 1) : 0;

// Fetch completion data over time (last 30 days)
$completion_timeline_labels = [];
$completion_timeline_data = [];

if ($students_count > 0 && $total_modules > 0) {
    // Get all enrolled student IDs
    $enrolled_student_ids = array_keys($enrolled_students_data);
    
    // Calculate completion rate for each day in the last 30 days
    for ($i = 29; $i >= 0; $i--) {
        $day_timestamp = strtotime("-$i days");
        $day_start = mktime(0, 0, 0, date('n', $day_timestamp), date('j', $day_timestamp), date('Y', $day_timestamp));
        $day_end = $day_start + 86400; // End of day
        
        $total_progress_sum = 0;
        
        foreach ($enrolled_student_ids as $student_id) {
            $student_progress = 0;
            
            // Check if student completed the course by this day
            $completion_record = $DB->get_record_sql(
                "SELECT cc.timecompleted
                   FROM {course_completions} cc
                  WHERE cc.userid = ? AND cc.course = ? AND cc.timecompleted <= ?",
                [$student_id, $course->id, $day_end],
                IGNORE_MISSING
            );
            
            if ($completion_record && $completion_record->timecompleted) {
                $student_progress = 100;
            } else {
                // Calculate progress based on completed modules up to this day
                $completed_modules = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cmc.coursemoduleid)
                       FROM {course_modules_completion} cmc
                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                      WHERE cmc.userid = ?
                        AND cm.course = ?
                        AND cm.visible = 1
                        AND cm.deletioninprogress = 0
                        AND cm.completion > 0
                        AND cmc.completionstate IN (1,2,3)
                        AND cmc.timemodified <= ?",
                    [$student_id, $course->id, $day_end]
                );
                
                if ($total_modules > 0) {
                    $student_progress = round(($completed_modules / $total_modules) * 100, 1);
                } else {
                    // If no modules with completion, check if student started
                    $timestarted = $DB->get_field_sql(
                        "SELECT cc.timestarted
                           FROM {course_completions} cc
                          WHERE cc.userid = ? AND cc.course = ? AND cc.timestarted <= ?",
                        [$student_id, $course->id, $day_end],
                        IGNORE_MISSING
                    );
                    if ($timestarted) {
                        $student_progress = 5; // Minimal progress for started
                    }
                }
            }
            
            $total_progress_sum += $student_progress;
        }
        
        $completion_rate = $students_count > 0 ? round($total_progress_sum / $students_count, 1) : 0;
        
        $completion_timeline_labels[] = date('M j', $day_timestamp);
        $completion_timeline_data[] = $completion_rate;
    }
} else {
    // If no students or modules, fill with zeros
    for ($i = 29; $i >= 0; $i--) {
        $day_timestamp = strtotime("-$i days");
        $completion_timeline_labels[] = date('M j', $day_timestamp);
        $completion_timeline_data[] = 0;
    }
}

$dateonlyformat = get_string('strftimedatefullshort', 'langconfig');
$timeformat = get_string('strftimetime', 'langconfig');
$datetime_noday_format = trim($dateonlyformat . ', ' . $timeformat);

// Detailed lists for interactive panels.
$students_list = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
            ue.timecreated AS timeenrolled,
            COALESCE(ula.timeaccess, 0) AS lastaccess,
            cc.timecompleted
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
       JOIN {role} r ON r.id = ra.roleid
  LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
  LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
      WHERE e.courseid = ?
        AND ue.status = 0
        AND cu.companyid = ?
        AND r.shortname = 'student'
        AND u.deleted = 0
        AND u.suspended = 0
   ORDER BY u.firstname ASC, u.lastname ASC",
    [CONTEXT_COURSE, $course->id, $company_info->id]
);

foreach ($students_list as $sid => $student) {
    $students_list[$sid]->completionpercent = isset($student_progress_map[$sid]) ? $student_progress_map[$sid] : 0;
}

$student_quiz_counts = [];
$student_assign_counts = [];
if (!empty($students_list)) {
    $student_ids = array_keys($students_list);
    list($insql, $params) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'stu');
    $params['course'] = $course->id;

    // Quiz counts
    $quiz_counts = $DB->get_records_sql(
        "SELECT qa.userid, COUNT(DISTINCT qa.quiz) AS cnt
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
          WHERE q.course = :course
            AND qa.userid $insql
         GROUP BY qa.userid",
        $params
    );
    foreach ($quiz_counts as $record) {
        $student_quiz_counts[$record->userid] = $record->cnt;
    }

    // Assignment submission counts
    $assign_counts = $DB->get_records_sql(
        "SELECT s.userid, COUNT(DISTINCT s.assignment) AS cnt
           FROM {assign_submission} s
           JOIN {assign} a ON a.id = s.assignment
          WHERE a.course = :course
            AND s.userid $insql
         GROUP BY s.userid",
        $params
    );
    foreach ($assign_counts as $record) {
        $student_assign_counts[$record->userid] = $record->cnt;
    }
}

// Get teachers assigned to this specific course (enrolled with teacher/editingteacher role)
$teachers_list = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username,
            MIN(ue.timecreated) AS assignedon
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {company_users} cu ON cu.userid = u.id
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
       JOIN {role} r ON r.id = ra.roleid
      WHERE e.courseid = ?
        AND ue.status = 0
        AND cu.companyid = ?
        AND r.shortname IN ('teacher', 'editingteacher')
        AND COALESCE(cu.educator, 0) = 1
        AND u.deleted = 0
        AND u.suspended = 0
   GROUP BY u.id, u.firstname, u.lastname, u.email, u.username
   ORDER BY u.firstname ASC, u.lastname ASC",
    [CONTEXT_COURSE, $course->id, $company_info->id]
);

$quizzes_list = [];
if ($quiz_module_id) {
    $quizzes_list = $DB->get_records_sql(
        "SELECT q.id, q.name, q.timeopen, q.timeclose, q.timecreated,
                (SELECT COUNT(id) FROM {quiz_attempts} qa WHERE qa.quiz = q.id) AS attempts
           FROM {quiz} q
          WHERE q.course = ?
       ORDER BY q.name ASC",
        [$course->id]
    );
}

$quiz_creator_map = [];
if (!empty($quizzes_list) && $DB->get_manager()->table_exists('logstore_standard_log')) {
    $quiz_ids = array_keys($quizzes_list);
    list($insql, $params) = $DB->get_in_or_equal($quiz_ids, SQL_PARAMS_NAMED, 'quizid');
    $creator_rows = $DB->get_records_sql(
        "SELECT l.objectid AS quizid, l.userid, l.timecreated
           FROM {logstore_standard_log} l
           JOIN (
                 SELECT objectid, MIN(timecreated) AS mintime
                   FROM {logstore_standard_log}
                  WHERE objecttable = 'quiz'
                    AND crud = 'c'
                    AND objectid $insql
               GROUP BY objectid
           ) first ON first.objectid = l.objectid AND first.mintime = l.timecreated",
        $params
    );

    if (!empty($creator_rows)) {
        $user_ids = array_unique(array_map(function($row) {
            return $row->userid;
        }, $creator_rows));

        if (!empty($user_ids)) {
            $creator_users = $DB->get_records_list('user', 'id', $user_ids, '', 'id, firstname, lastname, username');
            foreach ($creator_rows as $row) {
                if (isset($creator_users[$row->userid])) {
                    $quiz_creator_map[$row->quizid] = $creator_users[$row->userid];
                }
            }
        }
    }
}

$assignments_list = [];
if ($assign_module_id) {
    $assignments_list = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, a.timemodified
           FROM {assign} a
          WHERE a.course = ?
       ORDER BY a.name ASC",
        [$course->id]
    );
}

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/course_overview_detail.php', ['courseid' => $course->id]));
$PAGE->set_title('Course Overview: ' . format_string($course->fullname));
$PAGE->set_heading('Course Overview');

echo $OUTPUT->header();

$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'c_reports',
    'c_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
:root {
    --bg: #f7f9fc;
    --panel-gradient: linear-gradient(135deg, #6f8ffe, #8cd3ff);
    --panel-shadow: 0 25px 60px rgba(111, 143, 254, 0.18);
    --card-bg: #ffffff;
    --card-border: #ebeef5;
    --text-dark: #0f1230;
    --text-muted: #6c7393;
    --accent: #ff8f70;
    --accent2: #ffb56b;
}

.course-summary-page {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    padding: 55px 70px 80px;
    font-family: 'Inter', sans-serif;
    background: var(--bg);
}

.course-summary-container {
    max-width: 1320px;
    margin: 0 auto;
}

.course-summary-header {
    background: transparent;
    color: var(--text-dark);
    border-radius: 28px;
    padding: 38px 44px;
    box-shadow: none;
    border: 2px solid #3b82f6;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 35px;
    position: relative;
    overflow: visible;
}

.course-summary-header::after {
    display: none;
}

.course-summary-header h1 {
    margin: 0;
    font-size: 2.3rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--text-dark);
}

.course-summary-header p {
    margin: 6px 0 0;
    color: var(--text-muted);
    font-size: 1rem;
    font-weight: 500;
}

.course-info-boxes {
    display: flex;
    gap: 15px;
    margin-top: 15px;
}

.info-box {
    display: flex;
    flex-direction: column;
    padding: 12px 20px;
    border-radius: 12px;
    border: 2px solid;
    background: #ffffff;
    backdrop-filter: none;
    min-width: 140px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.info-box .info-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    opacity: 0.9;
}

.info-box .info-value {
    font-size: 1.1rem;
    font-weight: 700;
}

.grade-box {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.1);
}

.grade-box .info-label {
    color: #6b7280;
}

.grade-box .info-value {
    color: #10b981;
}

.courseid-box {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.1);
}

.courseid-box .info-label {
    color: #6b7280;
}

.courseid-box .info-value {
    color: #3b82f6;
}

.back-button {
    background: #3b82f6;
    border: none;
    color: #fff;
    padding: 12px 22px;
    border-radius: 16px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    transition: transform 0.2s ease, background 0.2s ease;
    position: relative;
    z-index: 1;
}

.back-button:hover {
    transform: translateY(-2px);
    background: #2563eb;
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 22px;
}

.summary-card {
    background: var(--card-bg);
    border-radius: 24px;
    padding: 32px 28px;
    border: 1px solid var(--card-border);
    box-shadow: 0 22px 55px rgba(15, 23, 42, 0.07);
    backdrop-filter: blur(10px);
    position: relative;
    overflow: hidden;
    min-height: 150px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    cursor: default;
}

.summary-card[data-target] {
    cursor: pointer;
}

.summary-card::after {
    content: '';
    position: absolute;
    width: 120px;
    height: 120px;
    background: rgba(255, 143, 112, 0.15);
    border-radius: 50%;
    top: -40px;
    right: -40px;
}

.summary-card h4 {
    margin: 0;
    font-size: 0.78rem;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.35px;
}

.summary-card .value {
    margin-top: 18px;
    font-size: 2.4rem;
    font-weight: 800;
    color: var(--text-dark);
}

.completion-progress-section {
    margin-top: 30px;
    margin-bottom: 30px;
    padding: 20px;
    border-radius: 12px;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}

.completion-progress-section.highlighted {
    background: rgba(59, 130, 246, 0.05);
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.completion-graph-section {
    margin-top: 30px;
    margin-bottom: 30px;
    padding: 28px 30px;
    background: #ffffff;
    border-radius: 24px;
    border: 1px solid #edf1f7;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

.completion-graph-header {
    margin-bottom: 20px;
}

.completion-graph-header h3 {
    margin: 0 0 6px;
    font-size: 1.3rem;
    color: #0f1230;
    font-weight: 700;
}

.completion-graph-header p {
    margin: 0;
    color: #6b7280;
    font-size: 0.95rem;
}

.completion-graph-wrapper {
    position: relative;
    height: 350px;
    width: 100%;
}

.completion-progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.completion-progress-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.completion-percentage {
    font-size: 1.3rem;
    font-weight: 800;
    color: #3b82f6;
}

.completion-progress-bar-container {
    width: 100%;
    height: 12px;
    background: #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}

.completion-progress-bar {
    height: 100%;
    background: #3b82f6;
    border-radius: 6px;
    transition: width 0.6s ease;
}

.meta-grid {
    margin-top: 34px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}

.meta-card {
    background: var(--card-bg);
    border-radius: 20px;
    padding: 24px 26px;
    border: 1px solid var(--card-border);
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
    backdrop-filter: blur(12px);
}

.meta-label {
    text-transform: uppercase;
    font-size: 0.7rem;
    color: var(--text-muted);
    letter-spacing: 0.25px;
    margin-bottom: 8px;
    font-weight: 600;
}

.meta-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.detail-sections {
    margin-top: 40px;
}

.detail-section {
    display: none;
    background: #ffffff;
    border-radius: 24px;
    padding: 28px 30px;
    border: 1px solid #edf1f7;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    margin-bottom: 30px;
}

.detail-section.active {
    display: block;
}

.detail-section h3 {
    margin: 0 0 8px;
    font-size: 1.3rem;
    color: #0f1230;
    font-weight: 700;
}

.detail-section p {
    margin: 0 0 20px;
    color: #6b7280;
    font-size: 0.95rem;
}

.detail-table {
    width: 100%;
    border-collapse: collapse;
}

.detail-table th {
    text-align: left;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #1e293b;
    padding: 16px 12px;
    border-bottom: 2px solid #e2e8f0;
    background-color: #f1f5f9;
    font-weight: 700;
}

.detail-table th:nth-child(3),
.detail-table th:nth-child(4),
.detail-table th:nth-child(5) {
    text-align: center;
}

.detail-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    background-color: #ffffff;
    color: #475569;
}

.detail-table td.teacher-performance-cell {
    text-align: center;
}

.detail-table td:nth-child(3),
.detail-table td:nth-child(4),
.detail-table td:nth-child(5) {
    text-align: center;
    font-weight: 600;
    color: #1f2937;
}

.detail-table td:nth-child(2) {
    color: #1e3a8a;
    font-weight: 600;
}

.assignment-compare {
    font-weight: 700;
    display: inline-block;
    min-width: 60px;
}

.assignment-compare.match {
    color: #15803d;
}

.assignment-compare.mismatch {
    color: #dc2626;
}

.teacher-performance {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 90px;
    padding: 6px 12px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.85rem;
}

.teacher-performance.high {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}

.teacher-performance.mid {
    background: rgba(250, 204, 21, 0.18);
    color: #b45309;
}

.teacher-performance.low {
    background: rgba(248, 113, 113, 0.18);
    color: #b91c1c;
}

.detail-table tbody tr:hover {
    background: #f8fafc;
}

.student-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e0e7ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #3b82f6;
    overflow: hidden;
}

.student-info {
    display: flex;
    flex-direction: column;
}

.student-info span:first-child {
    font-weight: 600;
    color: #111827;
}

.student-info span:last-child {
    font-size: 0.8rem;
    color: #94a3b8;
}

.progress-metric {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.progress-value {
    font-weight: 600;
    color: #111827;
    font-size: 0.9rem;
}

.progress-track {
    width: 160px;
    height: 6px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #3b82f6);
    border-radius: 999px;
}

.status-badge {
    font-weight: 600;
    font-size: 0.9rem;
}

.status-completed {
    color: #16a34a;
}

.status-progress {
    color: #f97316;
}

.status-notstarted {
    color: #9ca3af;
}

.pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
    font-size: 0.85rem;
    color: #6b7280;
}

.pagination-controls {
    display: flex;
    gap: 8px;
    align-items: center;
}

.pagination-controls button {
    border: 1px solid #d1d5db;
    background: #fff;
    padding: 6px 12px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.pagination-controls button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.page-number {
    border: 1px solid #d1d5db;
    background: #fff;
    padding: 6px 10px;
    border-radius: 8px;
    cursor: pointer;
}

.page-number.active {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

.summary-card.active {
    box-shadow: 0 15px 40px rgba(79, 70, 229, 0.25);
    transform: translateY(-3px);
    border-color: rgba(79, 70, 229, 0.35);
}

@media (max-width: 1200px) {
    .course-summary-page {
        left: 0;
        padding: 30px 20px 60px;
    }

    .course-summary-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 18px;
    }

    .course-summary-header::after {
        display: none;
    }
}

/* Hide chat bubbles and floating icons */
[class*="chat"],
[class*="Chat"],
[id*="chat"],
[id*="Chat"],
[class*="bubble"],
[class*="Bubble"],
[class*="floating"],
[class*="Floating"],
a[href*="chat"],
button[class*="chat"],
div[class*="chat-widget"],
div[class*="chatbot"],
div[class*="live-chat"],
.fab,
.floating-action-button,
[data-chat],
[data-bubble] {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
}
</style>

<div class="course-summary-page">
    <div class="course-summary-container">
        <div class="course-summary-header">
            <div>
                <h1><?php echo format_string($course->fullname); ?></h1>
            </div>
            <a class="back-button" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/c_reports.php?tab=overview">
                <i class="fa fa-arrow-left"></i> Back to Overview
            </a>
        </div>

        <div class="summary-grid">
            <div class="summary-card active" data-target="detail-students">
                <h4>Total Students</h4>
                <div class="value"><?php echo number_format($total_students); ?></div>
            </div>
            <div class="summary-card" data-target="detail-teachers">
                <h4>Total Teachers</h4>
                <div class="value"><?php echo number_format($total_teachers); ?></div>
            </div>
            <div class="summary-card" data-target="detail-quizzes">
                <h4>Total Quizzes</h4>
                <div class="value"><?php echo number_format($total_quizzes); ?></div>
            </div>
            <div class="summary-card" data-target="detail-assignments">
                <h4>Total Assignments</h4>
                <div class="value"><?php echo number_format($total_assignments); ?></div>
            </div>
            <div class="summary-card" data-target="completion-progress">
                <h4>Average Completion</h4>
                <div class="value"><?php echo number_format($average_completion_rate, 1); ?>%</div>
            </div>
        </div>

        <div class="completion-progress-section" id="completion-progress">
            <div class="completion-progress-header">
                <h3>Total Average Course Completion Rate</h3>
                <span class="completion-percentage"><?php echo number_format($average_completion_rate, 1); ?>%</span>
            </div>
            <div class="completion-progress-bar-container">
                <div class="completion-progress-bar" style="width: <?php echo min(100, max(0, $average_completion_rate)); ?>%;"></div>
            </div>
        </div>

        <div class="completion-graph-section" id="completion-graph-section" style="display: none;">
            <div class="completion-graph-header">
                <h3>Course Completion Over Time</h3>
                <p>Completion rate trend for the last 30 days</p>
            </div>
            <div class="completion-graph-wrapper">
                <canvas id="completionGraphChart"></canvas>
            </div>
        </div>

        <div class="detail-sections">
            <div class="detail-section active" id="detail-students">
                <h3><i class="fa fa-users" style="color:#3b82f6;"></i> Enrolled Students</h3>
                <p>Complete list of students enrolled in this course.</p>
                <?php if (empty($students_list)): ?>
                    <p style="color:#9ca3af;">No students enrolled yet.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="detail-table" data-list="students">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Enrolled On</th>
                                    <th>Assign Quiz / Assignments</th>
                                    <th>Quiz / Assignments</th>
                                    <th>Completion Rate</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students_list as $student): ?>
                                    <tr>
                    <td>
                        <div class="student-cell">
                            <div class="student-avatar">
                                <?php
                                    $initials = strtoupper(substr($student->firstname ?? '', 0, 1) . substr($student->lastname ?? '', 0, 1));
                                    echo $initials ?: 'S';
                                ?>
                            </div>
                            <div class="student-info">
                                <span><?php echo fullname($student); ?></span>
                                <span>@<?php echo format_string($student->username); ?></span>
                            </div>
                        </div>
                    </td>
                    <td><?php echo $student->timeenrolled ? userdate($student->timeenrolled, $datetime_noday_format) : '—'; ?></td>
                    <td><?php echo $total_quizzes . ' / ' . $total_assignments; ?></td>
                    <td>
                        <?php
                            $assignedQuizTotal = (int)$total_quizzes;
                            $assignedAssignTotal = (int)$total_assignments;
                            $quizC = isset($student_quiz_counts[$student->id]) ? (int)$student_quiz_counts[$student->id] : 0;
                            $assignC = isset($student_assign_counts[$student->id]) ? (int)$student_assign_counts[$student->id] : 0;
                            $statusClass = ($quizC === $assignedQuizTotal && $assignC === $assignedAssignTotal) ? 'match' : 'mismatch';
                        ?>
                        <span class="assignment-compare <?php echo $statusClass; ?>">
                            <?php echo $quizC . ' / ' . $assignC; ?>
                        </span>
                    </td>
                    <td>
                        <div class="progress-metric">
                            <span class="progress-value"><?php echo $student->completionpercent; ?>%</span>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: <?php echo min(100, max(0, $student->completionpercent)); ?>%; background: linear-gradient(90deg,#10b981,#22d3ee);"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                            if ($student->timecompleted) {
                                echo '<span class="status-badge status-completed">Completed</span>';
                            } elseif (!empty($student->lastaccess)) {
                                echo '<span class="status-badge status-progress">In Progress</span>';
                            } else {
                                echo '<span class="status-badge status-notstarted">Not Started</span>';
                            }
                        ?>
                    </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="detail-section" id="detail-teachers">
                <h3><i class="fa fa-chalkboard-teacher" style="color:#10b981;"></i> Assigned Teachers</h3>
                <p>Educators currently linked to this course.</p>
                <?php if (empty($teachers_list)): ?>
                    <p style="color:#9ca3af;">No teachers assigned yet.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="detail-table" data-list="teachers">
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Email</th>
                                    <th>Teacher Performance</th>
                                    <th>Created</th>
                                    <th>Assigned On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teachers_list as $teacher): ?>
                                    <?php
                                        $teacherInitials = strtoupper(substr($teacher->firstname ?? '', 0, 1) . substr($teacher->lastname ?? '', 0, 1));
                                        $teacherPerfPercent = $average_completion_rate;
                                        if ($teacherPerfPercent >= 70) {
                                            $teacherPerfClass = 'high';
                                        } elseif ($teacherPerfPercent >= 40) {
                                            $teacherPerfClass = 'mid';
                                        } else {
                                            $teacherPerfClass = 'low';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <div class="student-avatar"><?php echo $teacherInitials ?: 'T'; ?></div>
                                                <div class="student-info">
                                                    <span><?php echo fullname($teacher); ?></span>
                                                    <span>@<?php echo format_string($teacher->username); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo format_string($teacher->email); ?></td>
                                        <td class="teacher-performance-cell">
                                            <span class="teacher-performance <?php echo $teacherPerfClass; ?>">
                                                <?php echo number_format($teacherPerfPercent, 1); ?>%
                                            </span>
                                        </td>
                                        <td class="teacher-performance-cell">
                                            <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                                                <span style="font-weight:700;"><?php echo $total_quizzes . ' / ' . $total_assignments; ?></span>
                                                <small style="color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; font-size:0.65rem;">Quizzes / Assignments</small>
                                            </div>
                                        </td>
                                        <td><?php echo $teacher->assignedon ? userdate($teacher->assignedon, $datetime_noday_format) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="detail-section" id="detail-quizzes">
                <h3><i class="fa fa-question-circle" style="color:#f59e0b;"></i> Course Quizzes</h3>
                <p>Overview of quizzes configured for this course.</p>
                <?php if (empty($quizzes_list)): ?>
                    <p style="color:#9ca3af;">No quizzes created yet.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="detail-table" data-list="quizzes">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Opens</th>
                                    <th>Closes</th>
                                    <th>Created By</th>
                                    <th>Created On</th>
                                    <th>Attempts</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizzes_list as $quiz): ?>
                                    <tr>
                                        <td><?php echo format_string($quiz->name); ?></td>
                                        <td><?php echo $quiz->timeopen ? userdate($quiz->timeopen) : 'Not set'; ?></td>
                                        <td><?php echo $quiz->timeclose ? userdate($quiz->timeclose) : 'Not set'; ?></td>
                    <td>
                        <?php if (!empty($quiz_creator_map[$quiz->id])): ?>
                            <?php
                                $creator = $quiz_creator_map[$quiz->id];
                                echo fullname($creator);
                            ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                                        <td><?php echo $quiz->timecreated ? userdate($quiz->timecreated, $datetime_noday_format) : '—'; ?></td>
                                        <td><?php echo (int)$quiz->attempts; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="detail-section" id="detail-assignments">
                <h3><i class="fa fa-tasks" style="color:#ec4899;"></i> Course Assignments</h3>
                <p>Assignments currently available in this course.</p>
                <?php if (empty($assignments_list)): ?>
                    <p style="color:#9ca3af;">No assignments created yet.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="detail-table" data-list="assignments">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Due Date</th>
                                    <th>Last Modified</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments_list as $assign): ?>
                                    <tr>
                                        <td><?php echo format_string($assign->name); ?></td>
                                        <td><?php echo $assign->duedate ? userdate($assign->duedate) : 'Not set'; ?></td>
                                        <td><?php echo $assign->timemodified ? userdate($assign->timemodified) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js for completion graph -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const cards = document.querySelectorAll('.summary-card[data-target]');
    const sections = document.querySelectorAll('.detail-section');
    const paginate = (table, page = 1, perPage = 10) => {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const totalPages = Math.ceil(rows.length / perPage) || 1;
        const start = (page - 1) * perPage;
        const end = start + perPage;

        rows.forEach((row, index) => {
            row.style.display = index >= start && index < end ? '' : 'none';
        });

        let paginationBar = table.parentElement.querySelector('.pagination-bar');
        if (!paginationBar) {
            paginationBar = document.createElement('div');
            paginationBar.className = 'pagination-bar';
            paginationBar.innerHTML = `
                <div class="pagination-info"></div>
                <div class="pagination-controls"></div>
            `;
            table.parentElement.appendChild(paginationBar);
        }

        const info = paginationBar.querySelector('.pagination-info');
        const controls = paginationBar.querySelector('.pagination-controls');
        info.textContent = `Showing ${Math.min(rows.length, start + 1)}-${Math.min(rows.length, end)} of ${rows.length}`;
        controls.innerHTML = '';

        const prevBtn = document.createElement('button');
        prevBtn.textContent = 'Prev';
        prevBtn.disabled = page === 1;
        prevBtn.addEventListener('click', () => paginate(table, page - 1, perPage));
        controls.appendChild(prevBtn);

        for (let i = 1; i <= totalPages; i++) {
            const pageBtn = document.createElement('span');
            pageBtn.textContent = i;
            pageBtn.className = 'page-number' + (i === page ? ' active' : '');
            pageBtn.addEventListener('click', () => paginate(table, i, perPage));
            controls.appendChild(pageBtn);
        }

        const nextBtn = document.createElement('button');
        nextBtn.textContent = 'Next';
        nextBtn.disabled = page === totalPages;
        nextBtn.addEventListener('click', () => paginate(table, page + 1, perPage));
        controls.appendChild(nextBtn);
    };

    document.querySelectorAll('.detail-table').forEach(table => {
        paginate(table, 1, 10);
    });

    const completionProgressSection = document.getElementById('completion-progress');
    const completionGraphSection = document.getElementById('completion-graph-section');
    let completionChartInstance = null;

    // Initialize completion graph
    function initCompletionGraph() {
        const ctx = document.getElementById('completionGraphChart');
        if (!ctx || !window.Chart) {
            return;
        }

        const completionLabels = <?php echo json_encode($completion_timeline_labels, JSON_UNESCAPED_UNICODE); ?>;
        const completionData = <?php echo json_encode($completion_timeline_data, JSON_UNESCAPED_UNICODE); ?>;

        if (!completionLabels.length || !completionData.length) {
            return;
        }

        if (completionChartInstance) {
            completionChartInstance.destroy();
        }

        completionChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: completionLabels,
                datasets: [{
                    label: 'Completion Rate (%)',
                    data: completionData,
                    borderColor: '#14b8a6',
                    backgroundColor: 'rgba(20, 184, 166, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#14b8a6',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#14b8a6',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
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
                                return 'Completion: ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            },
                            stepSize: 20
                        },
                        grid: {
                            color: '#f1f5f9',
                            lineWidth: 1
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    cards.forEach(card => {
        card.addEventListener('click', () => {
            const targetId = card.getAttribute('data-target');
            const isActive = card.classList.contains('active');

            cards.forEach(c => c.classList.remove('active'));
            sections.forEach(section => section.classList.remove('active'));
            if (completionProgressSection) {
                completionProgressSection.classList.remove('highlighted');
            }
            if (completionGraphSection) {
                completionGraphSection.style.display = 'none';
            }

            if (!isActive && targetId) {
                card.classList.add('active');
                const targetSection = document.getElementById(targetId);
                if (targetSection) {
                    if (targetId === 'completion-progress') {
                        // Show graph without highlighting the progress bar section
                        if (completionGraphSection) {
                            completionGraphSection.style.display = 'block';
                            // Initialize graph when shown
                            setTimeout(() => {
                                initCompletionGraph();
                            }, 100);
                        }
                    } else {
                        targetSection.classList.add('active');
                    }
                }
            }
        });
    });

    // Remove chat bubbles and floating icons
    function removeChatBubbles() {
        const selectors = [
            '[class*="chat"]',
            '[class*="Chat"]',
            '[id*="chat"]',
            '[id*="Chat"]',
            '[class*="bubble"]',
            '[class*="Bubble"]',
            '[class*="floating"]',
            '[class*="Floating"]',
            'a[href*="chat"]',
            'button[class*="chat"]',
            'div[class*="chat-widget"]',
            'div[class*="chatbot"]',
            'div[class*="live-chat"]',
            '.fab',
            '.floating-action-button',
            '[data-chat]',
            '[data-bubble]'
        ];
        
        selectors.forEach(selector => {
            try {
                document.querySelectorAll(selector).forEach(el => {
                    if (el && el.parentNode) {
                        el.style.display = 'none';
                        el.style.visibility = 'hidden';
                        el.style.opacity = '0';
                    }
                });
            } catch (e) {
                // Ignore errors
            }
        });
    }

    // Remove immediately and also after page load
    removeChatBubbles();
    document.addEventListener('DOMContentLoaded', removeChatBubbles);
    window.addEventListener('load', removeChatBubbles);
    
    // Use MutationObserver to remove dynamically added chat bubbles
    const observer = new MutationObserver(() => {
        removeChatBubbles();
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});
</script>

<?php
echo $OUTPUT->footer();

