<?php
/**
 * Teacher Performance Reports - School Manager
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Ensure the current user has the school manager role.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company information for the current manager.
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

// Initialize teacher load distribution.
$teacher_load_distribution = [
    'no_load' => 0,
    'low_load' => 0,
    'medium_load' => 0,
    'high_load' => 0,
    'total' => 0
];

if ($company_info) {
    $teachers_courses = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname,
                COUNT(DISTINCT CASE WHEN c.visible = 1 AND c.id > 1 THEN ctx.instanceid END) AS course_count
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = 50
         LEFT JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = cu.companyid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND u.deleted = 0
         AND u.suspended = 0
         GROUP BY u.id, u.firstname, u.lastname
         ORDER BY course_count DESC",
        [$company_info->id]
    );

    foreach ($teachers_courses as $teacher) {
        if ((int)$teacher->course_count === 0) {
            $teacher_load_distribution['no_load']++;
        } elseif ($teacher->course_count >= 1 && $teacher->course_count <= 2) {
            $teacher_load_distribution['low_load']++;
        } elseif ($teacher->course_count >= 3 && $teacher->course_count <= 5) {
            $teacher_load_distribution['medium_load']++;
        } elseif ($teacher->course_count > 5) {
            $teacher_load_distribution['high_load']++;
        }
    }

    $teacher_load_distribution['total'] = count($teachers_courses);
}

// Teacher performance overview (courses, students, completion, scores).
$teacher_performance_data = [];
$teacher_detail_data = [];

$available_tabs = ['summary', 'performance', 'overview', 'assessment', 'coursewise', 'activitylog'];
$initial_tab = 'summary';
if (isset($initial_tab_override) && in_array($initial_tab_override, $available_tabs, true)) {
    $initial_tab = $initial_tab_override;
} elseif (isset($_GET['tab']) && in_array($_GET['tab'], $available_tabs, true)) {
    $initial_tab = $_GET['tab'];
}

$assessment_dashboard_data = [
    'kpi' => [
        'quizzes_created' => 0,
        'assignments_created' => 0,
        'pending_total' => 0,
        'avg_marks_overall' => 0,
        'avg_feedback_hours' => null
    ],
    'teacher_activity' => [
        'labels' => [],
        'quizzes' => [],
        'assignments' => []
    ],
    'pending' => [
        'assignment_total' => 0,
        'quiz_total' => 0,
        'total' => 0,
        'by_teacher' => [],
        'top' => []
    ],
    'average_marks' => [
        'labels' => [],
        'course' => [],
        'quiz' => [],
        'assignment' => []
    ],
    'feedback' => [
        'average_hours' => null,
        'labels' => [],
        'hours' => []
    ]
];
$assessment_teacher_table_rows = [];

$teacher_activity_log_data = [
    'summary' => [
        'latest_login_ts' => null,
        'latest_login_display' => null,
        'weekly_logins_total' => 0,
        'monthly_logins_total' => 0,
        'content_updates_total' => 0,
        'messages_total' => 0
    ],
    'teachers' => [],
    'weekly_chart' => [
        'labels' => [],
        'weekly' => [],
        'monthly' => []
    ],
    'content_chart' => [
        'labels' => [],
        'updates' => []
    ],
    'messages_chart' => [
        'labels' => [],
        'counts' => []
    ],
    'daily_login_trend' => [
        'labels' => [],
        'teacher_logins' => []
    ]
];

$student_feedback_data = [
    'available' => false,
    'summary' => [
        'avg_rating' => null,
        'responses' => 0,
        'comment_count' => 0
    ],
    'teachers' => [],
    'comments' => [],
    'trend' => [
        'labels' => [],
        'values' => []
    ]
];
if ($company_info) {
    $feedback_tables_available = $DB->get_manager()->table_exists('feedback')
        && $DB->get_manager()->table_exists('feedback_item')
        && $DB->get_manager()->table_exists('feedback_value')
        && $DB->get_manager()->table_exists('feedback_completed');
    $student_feedback_data['available'] = $feedback_tables_available;

    $now = time();
    $week_threshold = $now - WEEKSECS;
    $month_threshold = $now - (30 * DAYSECS);
    $feedback_trend_map = [];
    $overall_feedback_sum = 0;
    $overall_feedback_count = 0;
    $global_feedback_comments = [];

    $teachers_list = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND u.deleted = 0
         AND u.suspended = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id]
    );

    foreach ($teachers_list as $teacher) {
        $teacher_courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id
             FROM {course} c
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND c.visible = 1
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        $courses_taught = count($teacher_courses);
        $course_ids = array_keys($teacher_courses);

        $total_students = 0;
        $completed_students = 0;
        $avg_student_grade = 0;
        $avg_quiz_score = 0;
        $avg_assignment_grade_teacher = null;
        $teacher_pending_assignments = 0;
        $teacher_pending_quizzes = 0;
        $teacher_feedback_total_seconds = 0;
        $teacher_feedback_count = 0;

        $course_details = [];
        $total_quizzes_created = 0;
        $total_assignments_created = 0;
        $total_quiz_attempts = 0;
        $total_assignment_submissions = 0;
        $course_grade_sum = 0;
        $course_grade_count = 0;
        $assignment_grade_sum = 0;
        $assignment_grade_count = 0;

        if (!empty($course_ids)) {
            list($insql, $baseparams) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $params = $baseparams;
            $params['companyid'] = $company_info->id;

            $total_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id)
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
                 INNER JOIN {role} r ON r.id = ra.roleid
                 WHERE e.courseid $insql
                 AND cu.companyid = :companyid
                 AND ue.status = 0
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 AND u.suspended = 0",
                $params
            );

            $completed_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id)
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {course_completions} cc ON cc.userid = u.id
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = cc.course
                 INNER JOIN {role} r ON r.id = ra.roleid
                 WHERE cc.course $insql
                 AND cu.companyid = :companyid
                 AND cc.timecompleted IS NOT NULL
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 AND u.suspended = 0",
                $params
            );

            $grade_result = $DB->get_record_sql(
                "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_grade
                 FROM {grade_grades} gg
                 INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                 INNER JOIN {user} u ON u.id = gg.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE gi.courseid $insql
                 AND cu.companyid = :companyid
                 AND gi.itemtype = 'course'
                 AND gg.finalgrade IS NOT NULL
                 AND u.deleted = 0
                 AND u.suspended = 0",
                $params
            );
            $avg_student_grade = $grade_result && $grade_result->avg_grade ? round($grade_result->avg_grade, 1) : 0;

            $quiz_result = $DB->get_record_sql(
                "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_quiz
                 FROM {grade_grades} gg
                 INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                 INNER JOIN {user} u ON u.id = gg.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE gi.courseid $insql
                 AND cu.companyid = :companyid
                 AND gi.itemtype = 'mod'
                 AND gi.itemmodule = 'quiz'
                 AND gg.finalgrade IS NOT NULL
                 AND u.deleted = 0
                 AND u.suspended = 0",
                $params
            );
            $avg_quiz_score = $quiz_result && $quiz_result->avg_quiz ? round($quiz_result->avg_quiz, 1) : 0;

            $assignment_result = $DB->get_record_sql(
                "SELECT AVG((ag.grade / a.grade) * 100) AS avg_assignment
                 FROM {assign_grades} ag
                 INNER JOIN {assign} a ON a.id = ag.assignment
                 INNER JOIN {user} u ON u.id = ag.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE a.course $insql
                 AND cu.companyid = :companyid
                 AND ag.grade IS NOT NULL
                 AND ag.grade >= 0
                 AND a.grade > 0",
                $params
            );
            if ($assignment_result && $assignment_result->avg_assignment !== null) {
                $avg_assignment_grade_teacher = round((float)$assignment_result->avg_assignment, 1);
            }

            $course_records = $DB->get_records_sql(
                "SELECT c.id, c.fullname
                 FROM {course} c
                 WHERE c.id $insql
                 ORDER BY c.fullname ASC",
                $baseparams
            );
            foreach ($course_records as $course_record) {
                $course_id = $course_record->id;
                $course_params = [
                    'courseid' => $course_id,
                    'companyid' => $company_info->id
                ];

                $course_student_stats = $DB->get_record_sql(
                    "SELECT COUNT(DISTINCT u.id) AS total_students,
                            COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN u.id END) AS completed_students
                     FROM {user} u
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                     INNER JOIN {enrol} e ON e.id = ue.enrolid
                     INNER JOIN {role_assignments} ra ON ra.userid = u.id
                     INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
                     INNER JOIN {role} r ON r.id = ra.roleid
                     LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
                     WHERE e.courseid = :courseid
                     AND cu.companyid = :companyid
                     AND ue.status = 0
                     AND r.shortname = 'student'
                     AND u.deleted = 0
                     AND u.suspended = 0",
                    $course_params
                );

                $course_total_students = $course_student_stats ? (int)$course_student_stats->total_students : 0;
                $course_completed_students = $course_student_stats ? (int)$course_student_stats->completed_students : 0;
                $course_completion_rate = $course_total_students > 0 ? round(($course_completed_students / $course_total_students) * 100, 1) : 0;

                $course_grade_record = $DB->get_record_sql(
                    "SELECT AVG(gg.finalgrade / gg.rawgrademax * 100) AS avg_grade
                     FROM {grade_grades} gg
                     INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                     INNER JOIN {user} u ON u.id = gg.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     WHERE gi.courseid = :courseid
                     AND cu.companyid = :companyid
                     AND gi.itemtype = 'course'
                     AND gg.finalgrade IS NOT NULL
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
                     INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                     INNER JOIN {user} u ON u.id = gg.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     WHERE gi.courseid = :courseid
                     AND cu.companyid = :companyid
                     AND gi.itemtype = 'mod'
                     AND gi.itemmodule = 'quiz'
                     AND gg.finalgrade IS NOT NULL
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
                     INNER JOIN {assign} a ON a.id = ag.assignment
                     INNER JOIN {user} u ON u.id = ag.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
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

                $quizzes_created = $DB->count_records('quiz', ['course' => $course_id]);
                $assignments_created = $DB->count_records('assign', ['course' => $course_id]);

                $quiz_attempts = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT qa.id)
                     FROM {quiz_attempts} qa
                     INNER JOIN {quiz} q ON q.id = qa.quiz
                     INNER JOIN {user} u ON u.id = qa.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     WHERE q.course = :courseid
                     AND cu.companyid = :companyid
                     AND qa.state = 'finished'
                     AND qa.timefinish > 0",
                    $course_params
                );

                $assignment_submissions = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ag.id)
                     FROM {assign_grades} ag
                     INNER JOIN {assign} a ON a.id = ag.assignment
                     INNER JOIN {user} u ON u.id = ag.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     WHERE a.course = :courseid
                     AND cu.companyid = :companyid
                     AND ag.grade IS NOT NULL
                     AND ag.grade >= 0",
                    $course_params
                );

                $pending_assignment_count = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT s.id)
                     FROM {assign_submission} s
                     INNER JOIN {assign} a ON a.id = s.assignment
                     LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.userid = s.userid
                     WHERE a.course = :courseid
                     AND s.status = 'submitted'
                     AND (ag.grade IS NULL OR ag.grade < 0)",
                    $course_params
                );

                $pending_quiz_count = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT qa.id)
                     FROM {quiz_attempts} qa
                     INNER JOIN {quiz} q ON q.id = qa.quiz
                     WHERE q.course = :courseid
                     AND qa.state = 'finished'
                     AND (qa.sumgrades IS NULL OR qa.sumgrades < 0)",
                    $course_params
                );

                $feedback_records = $DB->get_records_sql(
                    "SELECT s.timemodified AS submissiontime, ag.timemodified AS gradetime
                     FROM {assign_submission} s
                     INNER JOIN {assign} a ON a.id = s.assignment
                     INNER JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.userid = s.userid
                     WHERE a.course = :courseid
                     AND s.status = 'submitted'
                     AND ag.grade IS NOT NULL
                     AND ag.grade >= 0
                     AND s.timemodified > 0
                     AND ag.timemodified > 0",
                    $course_params
                );

                if (!empty($feedback_records)) {
                    foreach ($feedback_records as $feedback_record) {
                        $submission_time = (int)($feedback_record->submissiontime ?? 0);
                        $grade_time = (int)($feedback_record->gradetime ?? 0);
                        if ($grade_time >= $submission_time && $submission_time > 0) {
                            $teacher_feedback_total_seconds += ($grade_time - $submission_time);
                            $teacher_feedback_count++;
                        }
                    }
                }

                if ($course_grade_value !== null) {
                    $course_grade_sum += $course_grade_value;
                    $course_grade_count++;
                }
                if ($course_assignment_grade_value !== null) {
                    $assignment_grade_sum += $course_assignment_grade_value;
                    $assignment_grade_count++;
                }

                $total_quizzes_created += $quizzes_created;
                $total_assignments_created += $assignments_created;
                $total_quiz_attempts += $quiz_attempts;
                $total_assignment_submissions += $assignment_submissions;
                $teacher_pending_assignments += $pending_assignment_count;
                $teacher_pending_quizzes += $pending_quiz_count;

                $course_details[] = [
                    'id' => $course_id,
                    'name' => $course_record->fullname,
                    'students' => $course_total_students,
                    'completed' => $course_completed_students,
                    'completion_rate' => $course_completion_rate,
                    'avg_course_grade' => $course_grade_value,
                    'avg_quiz_score' => $course_quiz_grade_value,
                    'avg_assignment_grade' => $course_assignment_grade_value,
                    'quizzes_created' => $quizzes_created,
                    'assignments_created' => $assignments_created,
                    'quiz_attempts' => $quiz_attempts,
                    'assignment_submissions' => $assignment_submissions
                ];
            }

            if (!empty($course_details)) {
                usort($course_details, function($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
            }

            if ($assignment_grade_count > 0) {
                $avg_assignment_grade_teacher = round($assignment_grade_sum / $assignment_grade_count, 1);
            }
        }

        $user_last_access = (int)$DB->get_field('user', 'lastaccess', ['id' => $teacher->id]);
        $log_last_login = (int)$DB->get_field_sql(
            "SELECT MAX(timecreated)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND action = 'loggedin'",
            [$teacher->id]
        );
        $last_login_ts = max($user_last_access, $log_last_login);

        $first_login_ts = (int)$DB->get_field_sql(
            "SELECT MIN(timecreated)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND action = 'loggedin'",
            [$teacher->id]
        );

        $total_login_count = (int)$DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND action = 'loggedin'",
            [$teacher->id]
        );

        $weekly_logins = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND action = 'loggedin'
             AND timecreated >= ?",
            [$teacher->id, $week_threshold]
        );

        $monthly_logins = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND action = 'loggedin'
             AND timecreated >= ?",
            [$teacher->id, $month_threshold]
        );

        $content_updates = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {logstore_standard_log} l
             JOIN {course} c ON c.id = l.courseid
             JOIN {company_course} cc ON cc.courseid = c.id
             WHERE l.userid = ?
             AND cc.companyid = ?
             AND l.timecreated >= ?
             AND l.crud = 'c'
             AND l.component LIKE 'mod_%'",
            [$teacher->id, $company_info->id, $month_threshold]
        );

        $messages_sent = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {logstore_standard_log}
             WHERE userid = ?
             AND timecreated >= ?
             AND component LIKE 'core_message%'",
            [$teacher->id, $month_threshold]
        );

        if ($last_login_ts && ($teacher_activity_log_data['summary']['latest_login_ts'] === null || $last_login_ts > $teacher_activity_log_data['summary']['latest_login_ts'])) {
            $teacher_activity_log_data['summary']['latest_login_ts'] = $last_login_ts;
        }

        $teacher_activity_log_data['summary']['weekly_logins_total'] += $weekly_logins;
        $teacher_activity_log_data['summary']['monthly_logins_total'] += $monthly_logins;
        $teacher_activity_log_data['summary']['content_updates_total'] += $content_updates;
        $teacher_activity_log_data['summary']['messages_total'] += $messages_sent;

        $user_created_ts = $teacher->timecreated ? (int)$teacher->timecreated : null;
        if (!$user_created_ts && !empty($teacher->firstaccess)) {
            $user_created_ts = (int)$teacher->firstaccess;
        }
        if (!$user_created_ts && $first_login_ts) {
            $user_created_ts = (int)$first_login_ts;
        }

        $teacher_activity_log_data['teachers'][] = [
            'id' => $teacher->id,
            'name' => fullname($teacher),
            'email' => $teacher->email ?? '',
            'last_login_ts' => $last_login_ts,
            'last_login_display' => $last_login_ts ? userdate($last_login_ts, get_string('strftimedatetime', 'langconfig')) : 'No login recorded',
            'weekly_logins' => $weekly_logins,
            'monthly_logins' => $monthly_logins,
            'content_updates' => $content_updates,
            'messages_sent' => $messages_sent,
            'first_login_ts' => $first_login_ts ?: null,
            'first_login_display' => $first_login_ts ? userdate($first_login_ts, get_string('strftimedatetime', 'langconfig')) : get_string('never'),
            'user_created_ts' => $user_created_ts,
            'user_created_display' => $user_created_ts ? userdate($user_created_ts, get_string('strftimedatetime', 'langconfig')) : get_string('never'),
            'total_logins' => $total_login_count
        ];
        $teacher_activity_log_data['weekly_chart']['labels'][] = fullname($teacher);
        $teacher_activity_log_data['weekly_chart']['weekly'][] = $weekly_logins;
        $teacher_activity_log_data['weekly_chart']['monthly'][] = $monthly_logins;
        $teacher_activity_log_data['content_chart']['labels'][] = fullname($teacher);
        $teacher_activity_log_data['content_chart']['updates'][] = $content_updates;
        $teacher_activity_log_data['messages_chart']['labels'][] = fullname($teacher);
        $teacher_activity_log_data['messages_chart']['counts'][] = $messages_sent;
        $teacher_feedback_avg = null;
        $teacher_feedback_count = 0;
        $teacher_feedback_comments = [];

        if ($feedback_tables_available && !empty($course_ids)) {
            list($feedback_course_sql, $feedback_course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);

            $numeric_sql = "
                SELECT fc.timemodified, fv.value
                FROM {feedback} fb
                JOIN {feedback_item} fi ON fi.feedback = fb.id
                JOIN {feedback_value} fv ON fv.item = fi.id
                JOIN {feedback_completed} fc ON fc.id = fv.completed
                WHERE fb.course $feedback_course_sql
                  AND fi.typ IN ('numeric', 'number', 'slider', 'rating')
                  AND fv.value IS NOT NULL
                  AND fv.value <> ''
            ";
            $numeric_records = $DB->get_records_sql($numeric_sql, $feedback_course_params);

            if (!empty($numeric_records)) {
                $teacher_rating_sum = 0;
                foreach ($numeric_records as $record) {
                    $value = trim((string)$record->value);
                    if ($value === '' || !is_numeric($value)) {
                        continue;
                    }
                    $rating = (float)$value;
                    $teacher_rating_sum += $rating;
                    $teacher_feedback_count++;
                    $overall_feedback_sum += $rating;
                    $overall_feedback_count++;

                    $bucket = date('Y-m', (int)$record->timemodified);
                    if (!isset($feedback_trend_map[$bucket])) {
                        $feedback_trend_map[$bucket] = ['sum' => 0, 'count' => 0];
                    }
                    $feedback_trend_map[$bucket]['sum'] += $rating;
                    $feedback_trend_map[$bucket]['count']++;
                }
                if ($teacher_feedback_count > 0) {
                    $teacher_feedback_avg = round($teacher_rating_sum / $teacher_feedback_count, 2);
                }
            }

            $comment_sql = "
                SELECT fc.timemodified, fv.value
                FROM {feedback} fb
                JOIN {feedback_item} fi ON fi.feedback = fb.id
                JOIN {feedback_value} fv ON fv.item = fi.id
                JOIN {feedback_completed} fc ON fc.id = fv.completed
                WHERE fb.course $feedback_course_sql
                  AND fi.typ IN ('textarea', 'textfield', 'text')
                  AND fv.value IS NOT NULL
                  AND fv.value <> ''
                ORDER BY fc.timemodified DESC
            ";
            $comment_records = $DB->get_records_sql($comment_sql, $feedback_course_params, 0, 30);

            if (!empty($comment_records)) {
                foreach ($comment_records as $comment_record) {
                    $comment_text = trim($comment_record->value);
                    if ($comment_text === '') {
                        continue;
                    }
                    $comment_entry = [
                        'teacher_id' => $teacher->id,
                        'teacher_name' => fullname($teacher),
                        'comment' => $comment_text,
                        'time' => (int)$comment_record->timemodified
                    ];
                    $teacher_feedback_comments[] = $comment_entry;
                    $global_feedback_comments[] = $comment_entry;
                }
            }
        }

        $student_feedback_data['teachers'][] = [
            'id' => $teacher->id,
            'name' => fullname($teacher),
            'avg_rating' => $teacher_feedback_avg,
            'responses' => $teacher_feedback_count,
            'recent_comments' => array_slice(array_map(function($entry) {
                return [
                    'comment' => $entry['comment'],
                    'time' => $entry['time']
                ];
            }, $teacher_feedback_comments), 0, 5)
        ];
        $student_feedback_data['summary']['responses'] += $teacher_feedback_count;
        $student_feedback_data['summary']['comment_count'] += count($teacher_feedback_comments);

        $completion_rate = $total_students > 0 ? ($completed_students / $total_students) : 0;
        $courses_score = min(($courses_taught / 5) * 30, 30);
        $completion_score = $completion_rate * 40;
        $engagement_score = min(($total_students / 20) * 30, 30);
        $performance_score = round($courses_score + $completion_score + $engagement_score, 1);

        $teacher_performance_data[] = [
            'id' => $teacher->id,
            'name' => fullname($teacher),
            'courses_taught' => $courses_taught,
            'total_students' => $total_students,
            'completed_students' => $completed_students,
            'completion_rate' => round($completion_rate * 100, 1),
            'avg_student_grade' => $avg_student_grade,
            'avg_quiz_score' => $avg_quiz_score,
            'avg_assignment_grade' => $avg_assignment_grade_teacher !== null ? $avg_assignment_grade_teacher : 0,
            'performance_score' => $performance_score
        ];

        $avg_course_grade_overall = $course_grade_count > 0
            ? round($course_grade_sum / $course_grade_count, 1)
            : ($total_students > 0 ? $avg_student_grade : null);

        $avg_feedback_hours = $teacher_feedback_count > 0
            ? round(($teacher_feedback_total_seconds / $teacher_feedback_count) / 3600, 2)
            : null;

        $teacher_detail_data[$teacher->id] = [
            'id' => $teacher->id,
            'name' => fullname($teacher),
            'courses_taught' => $courses_taught,
            'total_students' => $total_students,
            'completed_students' => $completed_students,
            'pending_students' => max(0, $total_students - $completed_students),
            'completion_rate' => round($completion_rate * 100, 1),
            'avg_course_grade' => $avg_course_grade_overall,
            'avg_student_grade' => $avg_student_grade,
            'avg_quiz_score' => $avg_quiz_score,
            'avg_assignment_grade' => $avg_assignment_grade_teacher,
            'courses' => $course_details,
            'pending_grading' => [
                'assignments' => $teacher_pending_assignments,
                'quizzes' => $teacher_pending_quizzes,
                'total' => $teacher_pending_assignments + $teacher_pending_quizzes
            ],
            'feedback' => [
                'total_seconds' => $teacher_feedback_total_seconds,
                'count' => $teacher_feedback_count,
                'avg_hours' => $avg_feedback_hours
            ],
            'assessment_totals' => [
                'quizzes_created' => $total_quizzes_created,
                'assignments_created' => $total_assignments_created,
                'quiz_attempts' => $total_quiz_attempts,
                'assignment_submissions' => $total_assignment_submissions
            ]
        ];
    }

    $total_course_avg_sum = 0;
    $course_avg_count = 0;
    $total_feedback_seconds_all = 0;
    $total_feedback_count_all = 0;
    $total_quizzes_created_all = 0;
    $total_assignments_created_all = 0;
    $pending_by_teacher = [];

    foreach ($teacher_detail_data as $detail) {
        $teacher_name = $detail['name'] ?? get_string('unknownuser', 'core');
        $assessment_totals = $detail['assessment_totals'] ?? [];
        $pending = $detail['pending_grading'] ?? ['assignments' => 0, 'quizzes' => 0, 'total' => 0];
        $feedback = $detail['feedback'] ?? ['avg_hours' => null, 'total_seconds' => 0, 'count' => 0];

        $quizzes_created = (int)($assessment_totals['quizzes_created'] ?? 0);
        $assignments_created = (int)($assessment_totals['assignments_created'] ?? 0);

        $assessment_dashboard_data['teacher_activity']['labels'][] = $teacher_name;
        $assessment_dashboard_data['teacher_activity']['quizzes'][] = $quizzes_created;
        $assessment_dashboard_data['teacher_activity']['assignments'][] = $assignments_created;

        $assessment_dashboard_data['average_marks']['labels'][] = $teacher_name;
        $course_avg = $detail['avg_course_grade'];
        $quiz_avg = $detail['avg_quiz_score'];
        $assignment_avg = $detail['avg_assignment_grade'];

        $assessment_dashboard_data['average_marks']['course'][] = $course_avg !== null ? round($course_avg, 1) : null;
        $assessment_dashboard_data['average_marks']['quiz'][] = $quiz_avg !== null ? round($quiz_avg, 1) : null;
        $assessment_dashboard_data['average_marks']['assignment'][] = $assignment_avg !== null ? round($assignment_avg, 1) : null;

        if ($course_avg !== null) {
            $total_course_avg_sum += $course_avg;
            $course_avg_count++;
        }

        $pending_assignments = (int)($pending['assignments'] ?? 0);
        $pending_quizzes = (int)($pending['quizzes'] ?? 0);
        $pending_total = (int)($pending['total'] ?? ($pending_assignments + $pending_quizzes));

        $assessment_dashboard_data['pending']['assignment_total'] += $pending_assignments;
        $assessment_dashboard_data['pending']['quiz_total'] += $pending_quizzes;
        $assessment_dashboard_data['pending']['total'] += $pending_total;

        $assignment_attempts = (int)($assessment_totals['assignment_submissions'] ?? 0) + $pending_assignments;

        $assessment_teacher_table_rows[] = [
            'name' => $teacher_name,
            'quizzes_created' => $quizzes_created,
            'assignments_created' => $assignments_created,
            'quiz_grading' => (int)($assessment_totals['quiz_attempts'] ?? 0),
            'assignment_attempts' => $assignment_attempts,
            'assignment_grading' => (int)($assessment_totals['assignment_submissions'] ?? 0),
            'pending_total' => $pending_total,
            'pending_assignments' => $pending_assignments,
            'pending_quizzes' => $pending_quizzes
        ];

        $pending_by_teacher[] = [
            'name' => $teacher_name,
            'assignments' => $pending_assignments,
            'quizzes' => $pending_quizzes,
            'total' => $pending_total
        ];

        if ($feedback['avg_hours'] !== null) {
            $assessment_dashboard_data['feedback']['labels'][] = $teacher_name;
            $assessment_dashboard_data['feedback']['hours'][] = (float)$feedback['avg_hours'];
        }

        $total_feedback_seconds_all += (int)($feedback['total_seconds'] ?? 0);
        $total_feedback_count_all += (int)($feedback['count'] ?? 0);

        $total_quizzes_created_all += $quizzes_created;
        $total_assignments_created_all += $assignments_created;
    }
    if (!empty($pending_by_teacher)) {
        usort($pending_by_teacher, function($a, $b) {
            return ($b['total'] <=> $a['total']) ?: ($b['assignments'] <=> $a['assignments']);
        });
        $assessment_dashboard_data['pending']['by_teacher'] = $pending_by_teacher;
        $assessment_dashboard_data['pending']['top'] = array_slice($pending_by_teacher, 0, 6);
    }

    $assessment_dashboard_data['kpi']['quizzes_created'] = $total_quizzes_created_all;
    $assessment_dashboard_data['kpi']['assignments_created'] = $total_assignments_created_all;
    $assessment_dashboard_data['kpi']['pending_total'] = $assessment_dashboard_data['pending']['total'];
    $assessment_dashboard_data['kpi']['avg_marks_overall'] = $course_avg_count > 0 ? round($total_course_avg_sum / $course_avg_count, 1) : 0;

    $assessment_dashboard_data['feedback']['average_hours'] = $total_feedback_count_all > 0
        ? round(($total_feedback_seconds_all / $total_feedback_count_all) / 3600, 1)
        : null;
    $assessment_dashboard_data['kpi']['avg_feedback_hours'] = $assessment_dashboard_data['feedback']['average_hours'];

    $company_course_ids = $DB->get_fieldset_select('company_course', 'courseid', 'companyid = ?', [$company_info->id]);
    if (!empty($company_course_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($company_course_ids, SQL_PARAMS_NAMED);
        $params['companyid'] = $company_info->id;

        $grade_records = $DB->get_records_sql(
            "SELECT (gg.finalgrade / gg.rawgrademax * 100) AS percentage
             FROM {grade_grades} gg
             INNER JOIN {grade_items} gi ON gi.id = gg.itemid
             INNER JOIN {user} u ON u.id = gg.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             WHERE gi.courseid $insql
             AND cu.companyid = :companyid
             AND gg.finalgrade IS NOT NULL
             AND gg.rawgrademax > 0",
            $params
        );

        $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        foreach ($grade_records as $record) {
            $percentage = isset($record->percentage) ? (float)$record->percentage : null;
            if ($percentage === null || !is_finite($percentage)) {
                continue;
            }
            if ($percentage >= 90) {
                $grade_counts['A']++;
            } elseif ($percentage >= 75) {
                $grade_counts['B']++;
            } elseif ($percentage >= 60) {
                $grade_counts['C']++;
            } elseif ($percentage >= 40) {
                $grade_counts['D']++;
            } else {
                $grade_counts['F']++;
            }
        }

        $total_grades = array_sum($grade_counts);
        $grade_percentages = $total_grades > 0
            ? array_map(function($count) use ($total_grades) {
                return round(($count / $total_grades) * 100, 1);
            }, $grade_counts)
            : [0, 0, 0, 0, 0];

        $assessment_dashboard_data['grade_distribution']['counts'] = array_values($grade_counts);
        $assessment_dashboard_data['grade_distribution']['percentages'] = array_values($grade_percentages);
        $assessment_dashboard_data['grade_distribution']['total'] = $total_grades;
    }
    if (!empty($teacher_activity_log_data['teachers'])) {
        usort($teacher_activity_log_data['teachers'], function($a, $b) {
            return ($b['last_login_ts'] <=> $a['last_login_ts']);
        });
    }

    if ($teacher_activity_log_data['summary']['latest_login_ts']) {
        $teacher_activity_log_data['summary']['latest_login_display'] = userdate(
            $teacher_activity_log_data['summary']['latest_login_ts'],
            get_string('strftimedatetime', 'langconfig')
        );
    } else {
        $teacher_activity_log_data['summary']['latest_login_display'] = get_string('never');
    }
    // Prepare daily teacher login trend data for the last 30 days
    if ($company_info) {
        $days_back = 30;
        $today_start = strtotime('today');
        $start_date = $today_start - (($days_back - 1) * DAYSECS);
        $end_date = $today_start + DAYSECS - 1;

        $labels = [];
        for ($i = 0; $i < $days_back; $i++) {
            $timestamp = $start_date + ($i * DAYSECS);
            $labels[] = date('M d', $timestamp);
        }

        $teacher_activity_log_data['daily_login_trend']['labels'] = $labels;
        $teacher_activity_log_data['daily_login_trend']['teacher_logins'] = array_fill(0, count($labels), 0);
        $label_index_map = array_flip($labels);

        $teacher_role_ids = $DB->get_records_sql(
            "SELECT DISTINCT r.id
             FROM {role} r
             WHERE r.shortname IN ('teacher', 'editingteacher')"
        );
        $teacher_role_ids = array_keys($teacher_role_ids);

        if (!empty($teacher_role_ids)) {
            list($teacher_role_sql, $teacher_role_params) = $DB->get_in_or_equal($teacher_role_ids, SQL_PARAMS_NAMED);
            $teacher_role_params['companyid'] = $company_info->id;
            $teacher_role_params['start_date'] = $start_date;
            $teacher_role_params['end_date'] = $end_date;

            $teacher_login_records = $DB->get_records_sql(
                "SELECT DISTINCT l.userid, l.timecreated
                 FROM {logstore_standard_log} l
                 INNER JOIN {user} u ON u.id = l.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 10
                 INNER JOIN {role} r ON r.id = ra.roleid
                 WHERE cu.companyid = :companyid
                 AND r.id $teacher_role_sql
                 AND l.action = 'loggedin'
                 AND l.timecreated BETWEEN :start_date AND :end_date
                 AND u.deleted = 0
                 ORDER BY l.timecreated ASC",
                $teacher_role_params
            );

            $teacher_login_map = [];
            foreach ($teacher_login_records as $record) {
                $label = date('M d', (int)$record->timecreated);
                if (!isset($label_index_map[$label])) {
                    continue;
                }
                $day_index = $label_index_map[$label];
                if (!isset($teacher_login_map[$day_index])) {
                    $teacher_login_map[$day_index] = [];
                }
                $teacher_login_map[$day_index][$record->userid] = true;
            }

            foreach ($teacher_login_map as $index => $users) {
                $teacher_activity_log_data['daily_login_trend']['teacher_logins'][$index] = count($users);
            }
        }
    }

    if ($overall_feedback_count > 0) {
        $student_feedback_data['summary']['avg_rating'] = round($overall_feedback_sum / $overall_feedback_count, 2);
    }

    if (!empty($feedback_trend_map)) {
        ksort($feedback_trend_map);
        foreach ($feedback_trend_map as $month => $values) {
            if (!empty($values['count'])) {
                $student_feedback_data['trend']['labels'][] = $month;
                $student_feedback_data['trend']['values'][] = round($values['sum'] / $values['count'], 2);
            }
        }
    }

    if (!empty($global_feedback_comments)) {
        usort($global_feedback_comments, function($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        $student_feedback_data['comments'] = array_slice(array_map(function($entry) {
            return [
                'teacher_name' => $entry['teacher_name'],
                'comment' => $entry['comment'],
                'time' => $entry['time']
            ];
        }, $global_feedback_comments), 0, 25);
    }
}

// Teacher activity metrics.
$teacher_activity_data = [];
if ($company_info) {
    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated, u.firstaccess, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND u.deleted = 0
         AND u.suspended = 0
         ORDER BY u.lastname, u.firstname",
        [$company_info->id]
    );

    foreach ($teachers as $teacher) {
        $courses_managed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND c.visible = 1
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        $activities_created = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cm.id)
             FROM {course_modules} cm
             INNER JOIN {course} c ON c.id = cm.course
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        $quiz_gradings = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.id)
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND qa.state = 'finished'
             AND qa.timefinish > 0
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        $assignment_gradings = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ag.id)
             FROM {assign_grades} ag
             INNER JOIN {assign} a ON a.id = ag.assignment
             INNER JOIN {course} c ON c.id = a.course
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND ag.grade IS NOT NULL
             AND ag.grade >= 0
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        $total_gradings = $quiz_gradings + $assignment_gradings;

        if ($courses_managed > 0 || $activities_created > 0 || $total_gradings > 0) {
            $teacher_activity_data[] = [
                'id' => $teacher->id,
                'name' => fullname($teacher),
                'courses_managed' => $courses_managed,
                'activities_created' => $activities_created,
                'grading_done' => $total_gradings
            ];
        }
    }
}
// Teacher performance report (detailed metrics used by final section).
$teacher_performance_report = [];
if ($company_info) {
    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND u.deleted = 0
         AND u.suspended = 0
         ORDER BY u.lastname, u.firstname",
        [$company_info->id]
    );

    foreach ($teachers as $teacher) {
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id
             FROM {course} c
             INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ra.userid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cc.companyid = ?
             AND c.visible = 1
             AND c.id > 1",
            [$teacher->id, $company_info->id]
        );

        if (empty($courses)) {
            continue;
        }

        $course_ids = array_keys($courses);
        list($insql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
        $params['companyid'] = $company_info->id;

        $avg_quiz_grade = $DB->get_record_sql(
            "SELECT AVG((qa.sumgrades / q.sumgrades) * 100) AS avg_grade
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {user} u ON u.id = qa.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = q.course
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE q.course $insql
             AND cu.companyid = :companyid
             AND qa.state = 'finished'
             AND r.shortname = 'student'
             AND q.sumgrades > 0",
            $params
        );

        $avg_assignment_grade = $DB->get_record_sql(
            "SELECT AVG((ag.grade / a.grade) * 100) AS avg_grade
             FROM {assign_grades} ag
             INNER JOIN {assign} a ON a.id = ag.assignment
             INNER JOIN {user} u ON u.id = ag.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = a.course
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE a.course $insql
             AND cu.companyid = :companyid
             AND ag.grade IS NOT NULL
             AND ag.grade >= 0
             AND a.grade > 0
             AND r.shortname = 'student'",
            $params
        );

        $completion_stats = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT u.id) AS total_students,
                    COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN u.id END) AS completed_students
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
             WHERE e.courseid $insql
             AND cu.companyid = :companyid
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            $params
        );

        $avg_engagement = $DB->get_record_sql(
            "SELECT AVG(login_count) AS avg_logins
             FROM (
                 SELECT u.id, COUNT(DISTINCT DATE(FROM_UNIXTIME(l.timecreated))) AS login_count
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid
                 LEFT JOIN {logstore_standard_log} l ON l.userid = u.id AND l.action = 'loggedin' AND l.timecreated > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
                 WHERE e.courseid $insql
                 AND cu.companyid = :companyid
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 GROUP BY u.id
             ) AS subquery",
            $params
        );

        $quiz_avg = $avg_quiz_grade && $avg_quiz_grade->avg_grade ? round($avg_quiz_grade->avg_grade, 1) : 0;
        $assignment_avg = $avg_assignment_grade && $avg_assignment_grade->avg_grade ? round($avg_assignment_grade->avg_grade, 1) : 0;

        $overall_avg_grade = 0;
        $grade_count = 0;
        if ($quiz_avg > 0) {
            $overall_avg_grade += $quiz_avg;
            $grade_count++;
        }
        if ($assignment_avg > 0) {
            $overall_avg_grade += $assignment_avg;
            $grade_count++;
        }
        $overall_avg_grade = $grade_count > 0 ? round($overall_avg_grade / $grade_count, 1) : 0;

        $total_students = $completion_stats ? (int)$completion_stats->total_students : 0;
        $completed_students = $completion_stats ? (int)$completion_stats->completed_students : 0;
        $completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;
        $engagement = $avg_engagement && $avg_engagement->avg_logins ? round($avg_engagement->avg_logins, 1) : 0;

        $performance_score = round(($overall_avg_grade * 0.4) + ($completion_rate * 0.3) + (min($engagement * 3, 30)), 1);

        if ($total_students > 0 || $overall_avg_grade > 0) {
            $teacher_performance_report[] = [
                'id' => $teacher->id,
                'name' => fullname($teacher),
                'email' => $teacher->email,
                'avg_quiz_grade' => $quiz_avg,
                'avg_assignment_grade' => $assignment_avg,
                'overall_avg_grade' => $overall_avg_grade,
                'total_students' => $total_students,
                'completion_rate' => $completion_rate,
                'engagement' => $engagement,
                'performance_score' => $performance_score
            ];
        }
    }
}
$overview_total_courses_handled = array_sum(array_column($teacher_performance_data, 'courses_taught'));
$overview_total_students = array_sum(array_column($teacher_performance_data, 'total_students'));
$overview_completed_students = array_sum(array_column($teacher_performance_data, 'completed_students'));
$overview_incomplete_students = max(0, $overview_total_students - $overview_completed_students);
$overview_average_courses_per_teacher = count($teacher_performance_data) ? round($overview_total_courses_handled / count($teacher_performance_data), 1) : 0;
$overview_average_students_per_teacher = count($teacher_performance_data) ? round($overview_total_students / count($teacher_performance_data), 1) : 0;
$overview_avg_completion_rate = count($teacher_performance_report) ? round(array_sum(array_column($teacher_performance_report, 'completion_rate')) / count($teacher_performance_report), 1) : 0;
$overview_avg_grade = count($teacher_performance_report) ? round(array_sum(array_column($teacher_performance_report, 'overall_avg_grade')) / count($teacher_performance_report), 1) : 0;
$overview_avg_performance_score = count($teacher_performance_report) ? round(array_sum(array_column($teacher_performance_report, 'performance_score')) / count($teacher_performance_report), 1) : 0;
$overview_avg_feedback_rating = $overview_avg_performance_score ? round(min(5, $overview_avg_performance_score / 20), 1) : 0;
$overview_feedback_percentage = min(100, round(($overview_avg_feedback_rating / 5) * 100, 1));
$overview_activity_total_courses = array_sum(array_column($teacher_activity_data, 'courses_managed'));
$overview_activity_total_activities = array_sum(array_column($teacher_activity_data, 'activities_created'));
$overview_activity_total_grading = array_sum(array_column($teacher_activity_data, 'grading_done'));
$overview_activity_teacher_count = count($teacher_activity_data);

$overview_top_teachers = [];
if (!empty($teacher_performance_report)) {
    $overview_top_teachers = $teacher_performance_report;
    usort($overview_top_teachers, function($a, $b) {
        return ($b['performance_score'] <=> $a['performance_score']);
    });
    $overview_top_teachers = array_slice($overview_top_teachers, 0, 5);
}

$overview_completion_chart = [
    'labels' => ['Completed', 'In Progress'],
    'data' => [
        (int)$overview_completed_students,
        max(0, (int)$overview_incomplete_students)
    ],
    'colors' => ['#10b981', '#f59e0b']
];

$overview_grade_chart = [
    'labels' => array_map(function($teacher) {
        return $teacher['name'];
    }, $overview_top_teachers),
    'grades' => array_map(function($teacher) {
        return round($teacher['overall_avg_grade'], 1);
    }, $overview_top_teachers),
    'completion' => array_map(function($teacher) {
        return round($teacher['completion_rate'], 1);
    }, $overview_top_teachers),
    'performance' => array_map(function($teacher) {
        return round($teacher['performance_score'], 1);
    }, $overview_top_teachers)
];

$overview_activity_timeline = $teacher_activity_data;
if (!empty($overview_activity_timeline)) {
    usort($overview_activity_timeline, function($a, $b) {
        $aTotal = ($a['courses_managed'] ?? 0) + ($a['activities_created'] ?? 0) + ($a['grading_done'] ?? 0);
        $bTotal = ($b['courses_managed'] ?? 0) + ($b['activities_created'] ?? 0) + ($b['grading_done'] ?? 0);
        return $bTotal <=> $aTotal;
    });
    $overview_activity_timeline = array_slice($overview_activity_timeline, 0, 8);
}

$course_dashboard_data = [];
$course_dashboard_summary = [
    'courses' => 0,
    'students' => 0,
    'avg_completion' => 0,
    'assignments' => 0,
    'quizzes' => 0,
    'pending' => 0,
    'latest_update' => null
];
$course_dashboard_charts = [
    'enrollment' => [
        'labels' => [],
        'students' => []
    ],
    'assessments' => [
        'labels' => [],
        'assignments' => [],
        'quizzes' => []
    ]
];
if ($company_info) {
    $courses_for_dashboard = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.category, c.timemodified, cat.name AS categoryname
         FROM {course} c
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         LEFT JOIN {course_categories} cat ON cat.id = c.category
         WHERE cc.companyid = ? AND c.id > 1 AND c.visible = 1
         ORDER BY c.fullname ASC",
        [$company_info->id]
    );

    foreach ($courses_for_dashboard as $course) {
        // Teachers assigned to the course.
        $course_teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
             FROM {context} ctx
             INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {user} u ON u.id = ra.userid
             WHERE ctx.contextlevel = 50 AND ctx.instanceid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND u.deleted = 0 AND u.suspended = 0
             ORDER BY u.lastname, u.firstname",
            [$course->id]
        );
        $teacher_names = array_map(function($teacher) {
            return fullname($teacher);
        }, $course_teachers);

        // Student enrolments.
        $course_params = ['courseid' => $course->id, 'companyid' => $company_info->id];
        $total_students_course = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {user_enrolments} ue
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             INNER JOIN {user} u ON u.id = ue.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
             WHERE ue.status = 0 AND u.deleted = 0 AND u.suspended = 0",
            $course_params
        );

        $completed_students_course = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.userid)
             FROM {course_completions} cc
             INNER JOIN {company_users} cu ON cu.userid = cc.userid AND cu.companyid = :companyid
             WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL",
            $course_params
        );

        $completion_rate_course = $total_students_course > 0
            ? round(($completed_students_course / $total_students_course) * 100, 1)
            : 0;

        $assignments_created = $DB->count_records('assign', ['course' => $course->id]);
        $quizzes_created = $DB->count_records('quiz', ['course' => $course->id]);

        $pending_assignment_grading = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {assign_submission} s
             INNER JOIN {assign} a ON a.id = s.assignment
             WHERE a.course = ? AND s.status = 'submitted' AND s.timemodified > 0
             AND NOT EXISTS (
                 SELECT 1 FROM {assign_grades} g
                 WHERE g.assignment = a.id AND g.userid = s.userid AND g.grade IS NOT NULL AND g.grade >= 0
             )",
            [$course->id]
        );

        $open_quiz_attempts = $DB->count_records_sql(
            "SELECT COUNT(1)
             FROM {quiz_attempts}
             WHERE quiz IN (SELECT id FROM {quiz} WHERE course = ?)
             AND state <> 'finished'",
            [$course->id]
        );

        $pending_items_total = $pending_assignment_grading + $open_quiz_attempts;

        $course_dashboard_data[] = [
            'course_id' => $course->id,
            'course_name' => format_string($course->fullname),
            'category_name' => $course->categoryname ?? get_string('miscellaneous'),
            'teachers' => implode(', ', $teacher_names) ?: get_string('none'),
            'students' => $total_students_course,
            'completion_rate' => $completion_rate_course,
            'assignments' => $assignments_created,
            'quizzes' => $quizzes_created,
            'pending' => $pending_items_total,
            'last_updated' => $course->timemodified ? userdate($course->timemodified, get_string('strftimedatetimeshort')) : get_string('never'),
            'last_updated_raw' => $course->timemodified
        ];

        $course_dashboard_summary['courses']++;
        $course_dashboard_summary['students'] += $total_students_course;
        $course_dashboard_summary['assignments'] += $assignments_created;
        $course_dashboard_summary['quizzes'] += $quizzes_created;
        $course_dashboard_summary['pending'] += $pending_items_total;
        if ($course->timemodified && ($course_dashboard_summary['latest_update'] === null || $course->timemodified > $course_dashboard_summary['latest_update'])) {
            $course_dashboard_summary['latest_update'] = $course->timemodified;
        }
    }

    if (!empty($course_dashboard_data)) {
        $course_dashboard_summary['avg_completion'] = round(array_sum(array_column($course_dashboard_data, 'completion_rate')) / count($course_dashboard_data), 1);
        usort($course_dashboard_data, function($a, $b) {
            return $b['students'] <=> $a['students'];
        });

        $top_for_enrollment = array_slice($course_dashboard_data, 0, 8);
        foreach ($top_for_enrollment as $entry) {
            $course_dashboard_charts['enrollment']['labels'][] = $entry['course_name'];
            $course_dashboard_charts['enrollment']['students'][] = $entry['students'];
        }

        $top_for_assessments = array_slice($course_dashboard_data, 0, 8);
        foreach ($top_for_assessments as $entry) {
            $course_dashboard_charts['assessments']['labels'][] = $entry['course_name'];
            $course_dashboard_charts['assessments']['assignments'][] = $entry['assignments'];
            $course_dashboard_charts['assessments']['quizzes'][] = $entry['quizzes'];
        }
    }
}

if ($course_dashboard_summary['latest_update']) {
    $course_dashboard_summary['latest_update_formatted'] = userdate($course_dashboard_summary['latest_update'], get_string('strftimedatetimeshort'));
} else {
    $course_dashboard_summary['latest_update_formatted'] = get_string('never');
}

// Page configuration and layout.
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/teacher_report.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Teacher Performance Reports');
$PAGE->set_heading('Teacher Performance Reports');

$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'teacher_report',
    'teacher_report_active' => true,
    'certificates_active' => false,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
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
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

html, body {
    overflow: hidden;
    margin: 0;
    padding: 0;
    height: 100vh;
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
}

/* NOTE: Sidebar styling is now handled by the template - do not override background/colors here */
/* IMPORTANT: Position sidebar BELOW the navbar (top: 55px) */
.school-manager-sidebar {
    position: fixed !important;
    top: 55px !important; /* Below navbar */
    left: 0 !important;
    height: calc(100vh - 55px) !important; /* Full height minus navbar */
    z-index: 1000 !important; /* Below navbar (navbar uses 1100) */
    visibility: visible !important;
    display: flex !important;
}

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    overflow-x: hidden;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
    padding: 20px;
    box-sizing: border-box;
}

.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 35px 20px 0 20px;
    overflow-x: hidden;
}

.page-header {
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
    padding: 1.75rem 2rem;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    margin-bottom: 1.5rem;
    margin-top: 0;
    position: relative;
}

.page-header-text {
    flex: 1;
    min-width: 260px;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.header-download-section {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.9);
    padding: 10px 18px;
    border-radius: 12px;
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.12);
}

.download-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: #475569;
    white-space: nowrap;
}

.download-select {
    border: 1px solid #cbd5f5;
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 0.9rem;
    font-weight: 500;
    color: #1f2937;
    min-width: 150px;
}

.download-btn {
    border: none;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    font-weight: 600;
    padding: 9px 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.download-btn:hover {
    background: #1d4ed8;
}

.tabs-container {
    margin-bottom: 30px;
}

.tabs-nav {
    display: flex;
    gap: 10px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 25px;
}

.overview-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 18px;
    margin-bottom: 30px;
}

.overview-kpi-card {
    background: linear-gradient(135deg, #eef2ff, #e0f2fe);
    border-radius: 14px;
    padding: 20px;
    border: 1px solid rgba(59, 130, 246, 0.18);
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.overview-kpi-card .metric-label {
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #64748b;
    font-weight: 600;
}

.overview-kpi-card .metric-value {
    font-size: 2.4rem;
    font-weight: 800;
    color: #1d4ed8;
    line-height: 1;
}

.overview-kpi-card .metric-subtext {
    font-size: 0.85rem;
    color: #475569;
}

.overview-charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.overview-chart-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}

.overview-chart-card h4 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.star-rating {
    display: inline-flex;
    gap: 4px;
    color: #fbbf24;
    font-size: 1.1rem;
}

.star-rating .inactive {
    color: #e2e8f0;
}

.overview-activity-table table {
    width: 100%;
    border-collapse: collapse;
}

.overview-activity-table th,
.overview-activity-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.overview-activity-table th {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #475569;
}

.tab-button {
    padding: 12px 24px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.tab-button:hover {
    color: #3b82f6;
    background: #f9fafb;
}
.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 600;
}

.tab-content {
    display: none;
    overflow: visible;
}

.tab-content.active {
    display: block;
    overflow: visible;
}

.report-table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.no-data-row {
    background: #f9fafb;
    border-radius: 16px;
    padding: 50px 40px;
    text-align: center;
    border: 1px dashed #cbd5f5;
    color: #6b7280;
}

.no-data-row p {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.scrollable-chart-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        cursor: grab;
    }

    .scrollable-chart-container::-webkit-scrollbar {
        display: none;
    }

    .scrollable-chart-container.dragging {
        cursor: grabbing;
        cursor: -webkit-grabbing;
    }

    .chart-toolbar {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 20px;
        margin: 0 auto 18px;
        max-width: 720px;
        flex-wrap: wrap;
    }

    .chart-toolbar .chart-hint {
        font-size: 0.82rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .chart-search {
        position: relative;
        display: flex;
        align-items: center;
        background: #f8fafc;
        border-radius: 999px;
        padding: 10px 18px;
        border: 2px solid transparent;
        background-image: linear-gradient(#f8fafc, #f8fafc), linear-gradient(135deg, rgba(59, 130, 246, 0.35), rgba(99, 102, 241, 0.35));
        background-origin: border-box;
        background-clip: padding-box, border-box;
        min-width: 320px;
        box-shadow: 0 6px 14px rgba(148, 163, 184, 0.12);
    }

    .chart-search i.fa-search {
        color: #94a3b8;
        font-size: 0.9rem;
        margin-right: 8px;
    }

    .chart-search input {
        border: none;
        background: transparent;
        outline: none;
        font-size: 0.9rem;
        color: #1f2937;
        flex: 1;
    }

    .chart-search input::placeholder {
        color: #9ca3af;
    }

    .chart-search button {
        border: none;
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
        padding: 4px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .chart-search button:hover {
        color: #475569;
    }

    .chart-search:focus-within {
        background-image: linear-gradient(#f8fafc, #f8fafc), linear-gradient(135deg, rgba(99, 102, 241, 0.55), rgba(59, 130, 246, 0.55));
        box-shadow: 0 12px 24px rgba(99, 102, 241, 0.25);
}

canvas {
    max-width: 100%;
}

/* Teacher performance detail expansion */
.teacher-row {
    cursor: pointer;
}

.teacher-row.selected-teacher-row {
    background: #eef2ff !important;
}

.teacher-row.selected-teacher-row td {
    color: #1d4ed8;
}

.teacher-detail-overlay {
    position: fixed;
    top: var(--teacher-detail-overlay-top, 81px);
    left: 280px;
    right: 0;
    bottom: 0;
    display: none;
    align-items: flex-start;
    justify-content: flex-start;
    padding: 24px 40px 40px;
    background: rgba(248, 250, 252, 0.97);
    z-index: 4000;
    overflow-y: auto;
    overflow-x: hidden;
}

.teacher-detail-overlay.active {
    display: flex;
}
.comparative-layout-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-top: 24px;
}

.comparative-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 16px 28px rgba(15, 23, 42, 0.08);
    border: 1px solid #e5e7eb;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.comparative-card h4 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.comparative-card h4 i {
    color: #6366f1;
    font-size: 1.05rem;
}

.comparative-chart-wrapper {
    position: relative;
    width: 100%;
    height: 440px;
}

.comparative-table table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
}

.comparative-table th,
.comparative-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.comparative-table thead th {
    background: #f9fafb;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.45px;
    color: #475569;
}

.comparative-table tbody tr:hover {
    background: #f8fafc;
}

.badge-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 0.78rem;
    font-weight: 600;
}

.badge-good {
    background: rgba(34, 197, 94, 0.12);
    color: #047857;
}

.badge-average {
    background: rgba(250, 204, 21, 0.18);
    color: #b45309;
}

.badge-poor {
    background: rgba(248, 113, 113, 0.18);
    color: #b91c1c;
}

.highlight-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 18px;
}

.highlight-card {
    border-radius: 14px;
    padding: 18px 20px;
    background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
    border: 1px solid rgba(99, 102, 241, 0.35);
    box-shadow: 0 14px 24px rgba(79, 70, 229, 0.15);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.highlight-card.low {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-color: rgba(239, 68, 68, 0.35);
    box-shadow: 0 14px 24px rgba(239, 68, 68, 0.15);
}

.highlight-card strong {
    font-size: 1.05rem;
    color: #1f2937;
}

.highlight-card span {
    font-size: 0.85rem;
    color: #475569;
}

.highlight-card .score {
    font-size: 1.4rem;
    font-weight: 800;
    color: #4338ca;
}

.highlight-card.low .score {
    color: #dc2626;
}

.teacher-detail-overlay-content {
    width: 100%;
    max-width: none;
    min-width: 0;
}

.teacher-detail-overlay .teacher-detail-panel {
    margin-top: 0;
}

.teacher-detail-panel {
    background: #ffffff;
    border-radius: 18px;
    padding: 28px;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.12);
    border: 1px solid #e2e8f0;
}

.teacher-detail-panel.active {
    border-color: #3b82f6;
    box-shadow: 0 18px 36px rgba(59, 130, 246, 0.28);
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}

.teacher-detail-panel h4 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
}

.teacher-detail-summary {
    display: flex;
    flex-wrap: nowrap;
    gap: 16px;
    overflow-x: auto;
    padding: 6px 2px 10px;
}

.teacher-detail-summary-card {
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: #0f172a;
    background: #f8fafc;
    border: 1px solid rgba(148, 163, 184, 0.35);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    min-width: 185px;
    flex: 1;
}

.teacher-detail-summary-card span {
    font-size: 0.78rem;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.45px;
    color: #475569;
}

.teacher-detail-summary-card strong {
    font-size: 1.9rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}

.teacher-detail-summary-card small {
    font-size: 0.8rem;
    color: #64748b;
}
.teacher-detail-secondary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-top: 26px;
}

.teacher-detail-chart {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
    border: 1px solid #e2e8f0;
}

.teacher-detail-chart h5 {
    margin: 0 0 16px 0;
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.teacher-detail-chart .chart-helper {
    margin-top: 14px;
    font-size: 0.8rem;
    color: #64748b;
    border-left: 3px solid #3b82f6;
    padding-left: 10px;
}

.teacher-detail-table {
    margin-top: 28px;
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
    border: 1px solid #e2e8f0;
}

.teacher-detail-table table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}

.teacher-detail-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #475569;
    padding: 12px;
    text-align: left;
    border-bottom: 2px solid #e2e8f0;
}

.teacher-detail-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.92rem;
    color: #334155;
}

.teacher-detail-caption {
    color: #64748b;
    font-size: 0.9rem;
    margin-top: 12px;
}

.teacher-detail-row td {
    padding: 0;
    border-top: none;
    background: transparent;
}

.assessment-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.assessment-kpi-card {
    background: #f8fafc;
    border-radius: 16px;
    padding: 22px 20px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    border: 1px solid rgba(148, 163, 184, 0.25);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.assessment-kpi-card span {
    font-size: 0.78rem;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.45px;
    color: #475569;
}

.assessment-kpi-card strong {
    font-size: 2.1rem;
    font-weight: 800;
    color: #0f172a;
}

.assessment-kpi-card small {
    font-size: 0.8rem;
    color: #64748b;
}

.assessment-kpi-card--primary {
    background: linear-gradient(135deg, #ede9fe, #c7d2fe);
    border-left: 4px solid #6366f1;
}

.assessment-kpi-card--success {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-left: 4px solid #10b981;
}

.assessment-kpi-card--warning {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border-left: 4px solid #ef4444;
}

.assessment-kpi-card--grade {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-left: 4px solid #f59e0b;
}

.assessment-kpi-card--info {
    background: linear-gradient(135deg, #e0f2fe, #bae6fd);
    border-left: 4px solid #0ea5e9;
}

.assessment-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-bottom: 28px;
}
.assessment-chart-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.assessment-chart-card--wide {
    grid-column: span 2;
}

.assessment-card-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.assessment-card-header h4 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
}

.assessment-card-helper {
    font-size: 0.8rem;
    color: #64748b;
}

.assessment-table-wrapper {
    overflow-x: auto;
}

.assessment-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 480px;
    font-size: 0.9rem;
}

.assessment-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 0.75rem;
    color: #475569;
    padding: 10px 12px;
    border-bottom: 2px solid #e2e8f0;
}

.assessment-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    color: #334155;
}

.pending-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.85rem;
    color: #ffffff;
    background: #94a3b8;
}

.pending-pill.pending-high {
    background: linear-gradient(135deg, #b91c1c, #ef4444);
}

.pending-pill.pending-medium {
    background: linear-gradient(135deg, #f97316, #fb923c);
}

.pending-pill.pending-low {
    background: linear-gradient(135deg, #10b981, #34d399);
}

.assessment-grade-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.assessment-grade-chip {
    background: #f8fafc;
    border-radius: 999px;
    padding: 8px 14px;
    font-size: 0.8rem;
    color: #475569;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #e2e8f0;
}

.grade-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 700;
    color: #ffffff;
}

.grade-a { background: #2563eb; }
.grade-b { background: #10b981; }
.grade-c { background: #f59e0b; }
.grade-d { background: #f97316; }
.grade-f { background: #ef4444; }

.assessment-note {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    background: #f8fafc;
    border-left: 4px solid #6366f1;
    border-radius: 12px;
    padding: 16px 18px;
    color: #475569;
    font-size: 0.9rem;
    margin-top: 14px;
}

.assessment-note i {
    color: #f59e0b;
    font-size: 1.2rem;
    margin-top: 2px;
}

.assessment-teacher-table-wrapper {
    margin-top: 30px;
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    border: 1px solid #e5e7eb;
}

.assessment-teacher-table-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
}

.assessment-teacher-table-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.assessment-teacher-table-title i {
    color: #6366f1;
}

.assessment-teacher-search {
    display: flex;
    gap: 10px;
    align-items: center;
}
.assessment-teacher-search input {
    border-radius: 12px;
    border: 1px solid #d1d5db;
    padding: 10px 16px;
    min-width: 260px;
    font-size: 0.95rem;
    background: #f8fafc;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.assessment-teacher-search input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    outline: none;
}

.assessment-teacher-search button {
    padding: 10px 18px;
    border-radius: 12px;
    border: none;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #ffffff;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.assessment-teacher-search button:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(99, 102, 241, 0.25);
}

.assessment-teacher-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 960px;
}

.assessment-teacher-table thead th,
.assessment-teacher-table tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
    color: #1f2937;
    text-align: center;
}

.assessment-teacher-table thead th {
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.45px;
    color: #6b7280;
    background: #f8fafc;
}

.assessment-teacher-table tbody td:first-child,
.assessment-teacher-table thead th:first-child {
    text-align: left;
}

.assessment-teacher-empty {
    padding: 28px;
    text-align: center;
    color: #94a3b8;
    border: 1px dashed #cbd5f5;
    border-radius: 12px;
    margin-top: 16px;
    font-weight: 600;
}
.assessment-teacher-pagination {
    margin-top: 20px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 0.9rem;
    color: #475569;
}
.assessment-teacher-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.assessment-teacher-pagination-controls button {
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.assessment-teacher-pagination-controls button:hover:not([disabled]) {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
}

.assessment-teacher-pagination-controls button[disabled] {
    cursor: not-allowed;
    opacity: 0.5;
}

.assessment-teacher-page-numbers {
    display: flex;
    gap: 6px;
}

.assessment-teacher-page-numbers button {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.assessment-teacher-page-numbers button.active {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
}

.assessment-teacher-page-numbers button:hover:not(.active) {
    background: #f1f5f9;
}

.feedback-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 18px;
    margin-bottom: 26px;
}

.feedback-kpi-card {
    padding: 20px;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.feedback-kpi-card span {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-weight: 600;
    color: #475569;
}

.feedback-kpi-card strong {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
}

.feedback-kpi-card small {
    font-size: 0.8rem;
    color: #64748b;
}

.feedback-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-bottom: 28px;
}
.feedback-chart-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.feedback-card-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.feedback-card-header h4 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.feedback-card-helper {
    font-size: 0.8rem;
    color: #64748b;
}

.feedback-table-wrapper {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
}

.feedback-table-wrapper h4 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 18px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.feedback-comments-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.feedback-comment-card {
    background: #f8fafc;
    border-radius: 14px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.feedback-comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.82rem;
    color: #475569;
    font-weight: 600;
}

.feedback-comment-header i {
    color: #6366f1;
}

.feedback-comment-body {
    font-size: 0.9rem;
    color: #1f2937;
    line-height: 1.5;
}

.feedback-empty {
    text-align: center;
    color: #94a3b8;
    padding: 30px;
    border: 1px dashed #cbd5f5;
    border-radius: 12px;
    margin-top: 16px;
    font-weight: 600;
}

.feedback-empty i {
    font-size: 2rem;
    margin-bottom: 8px;
    display: block;
}

.activity-log-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 18px;
    margin-bottom: 26px;
}
.activity-log-kpi-card {
    padding: 20px;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.activity-log-kpi-card span {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-weight: 600;
    color: #475569;
}

.activity-log-kpi-card strong {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}

.activity-log-kpi-card small {
    font-size: 0.78rem;
    color: #64748b;
}

.activity-log-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-bottom: 28px;
}

.activity-log-chart-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.activity-log-table-container {
    margin-top: 28px;
    background: #ffffff;
    border-radius: 20px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

.activity-log-table-toolbar {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-bottom: 18px;
}

.activity-log-table-header {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.activity-log-table-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.activity-log-table-title i {
    color: #16a34a;
    font-size: 1.1rem;
}

.activity-log-info-text {
    font-size: 0.9rem;
    color: #475569;
}

.activity-log-toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.activity-log-search {
    display: flex;
    gap: 10px;
    align-items: center;
}

.activity-log-search input {
    border-radius: 12px;
    border: 1px solid #d1d5db;
    padding: 10px 16px;
    min-width: 280px;
    font-size: 0.95rem;
    background: #f8fafc;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.activity-log-search input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    outline: none;
}

.activity-log-search button {
    padding: 10px 18px;
    border-radius: 12px;
    border: none;
    background: linear-gradient(135deg, #ef4444, #f87171);
    color: #ffffff;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.activity-log-search button:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(239, 68, 68, 0.25);
}

.activity-log-pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.activity-log-pagination-controls button {
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.activity-log-pagination-controls button:hover:not([disabled]) {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #ffffff;
}

.activity-log-pagination-controls button[disabled] {
    cursor: not-allowed;
    opacity: 0.5;
}

.activity-log-page-numbers {
    display: flex;
    gap: 6px;
}

.activity-log-page-numbers button {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.activity-log-page-numbers button.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #ffffff;
}

.activity-log-page-numbers button:hover:not(.active) {
    background: #f1f5f9;
}

.activity-log-table-wrapper {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
}

.activity-log-table-wrapper table {
    width: 100%;
    border-collapse: collapse;
    min-width: 880px;
}

.activity-log-table-wrapper th,
.activity-log-table-wrapper td {
    padding: 14px 12px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
    color: #1f2937;
}

.activity-log-table-wrapper th {
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.45px;
    color: #6b7280;
    background: #f8fafc;
    text-align: left;
}

.activity-log-table-empty {
    padding: 24px;
    text-align: center;
    color: #94a3b8;
    font-weight: 600;
    border: 1px dashed #cbd5f5;
    border-radius: 12px;
    margin-top: 16px;
}

.activity-log-total-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(250, 204, 21, 0.2);
    color: #b45309;
    font-weight: 700;
    min-width: 38px;
}

.activity-log-chart-card--wide {
    grid-column: span 2;
}

.activity-log-card-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.activity-log-card-header h4 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.activity-log-card-helper {
    font-size: 0.8rem;
    color: #64748b;
}

.activity-log-table-wrapper {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
}

.activity-log-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    min-width: 900px;
}

.activity-log-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 0.75rem;
    color: #475569;
    padding: 12px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}

.activity-log-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    color: #334155;
}

.activity-log-table tbody tr:hover {
    background: #f9fafb;
}

.activity-log-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 600;
    font-size: 0.78rem;
}

.activity-log-table-wrapper h4 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 18px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.activity-log-table-empty {
    padding: 40px;
    text-align: center;
    color: #64748b;
    font-weight: 600;
}

@media (max-width: 1200px) {
    .main-content {
        padding: 35px 16px 0 16px;
    }

    .report-table-container {
        padding: 25px;
    }
}
@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
        overflow-x: hidden;
    }

    .main-content {
        padding: 35px 10px 0 10px;
        overflow-x: hidden;
    }

    .page-header {
        padding: 30px 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }

    .page-title {
        font-size: 1.75rem;
    }

    .header-download-section {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }

    .header-download-section .download-select,
    .header-download-section .download-btn {
        width: 100%;
    }

    .tabs-nav {
        flex-direction: column;
        gap: 5px;
        border-bottom: none;
    }

    .tab-button {
        width: 100%;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
        border-radius: 6px;
        bottom: 0;
    }

    .tab-button.active {
        background: #eff6ff;
        border-color: #3b82f6;
    }

    .report-table-container {
        padding: 15px;
    }

    .activity-log-kpi-grid {
        grid-template-columns: 1fr;
    }

    .activity-log-chart-grid {
        grid-template-columns: 1fr;
    }

    .activity-log-chart-card--wide {
        grid-column: span 1;
    }

    .feedback-kpi-grid {
        grid-template-columns: 1fr;
    }

    .feedback-chart-grid {
        grid-template-columns: 1fr;
    }

    .feedback-comments-list {
        grid-template-columns: 1fr;
    }
}

.overview-table-wrapper {
    margin-top: 24px;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}

.overview-utility-bar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
}

.overview-search {
    flex: 1;
    min-width: 260px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border-radius: 999px;
    padding: 10px 18px;
    border: 2px solid transparent;
    background-image: linear-gradient(#f8fafc, #f8fafc), linear-gradient(120deg, rgba(59, 130, 246, 0.4), rgba(139, 92, 246, 0.4));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    box-shadow: 0 8px 18px rgba(148, 163, 184, 0.15);
}

.overview-search i {
    color: #94a3b8;
    font-size: 0.95rem;
}

.overview-search input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.95rem;
    color: #1f2937;
}

.overview-search button {
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    font-size: 0.9rem;
    padding: 4px;
}

.overview-pagination-info {
    font-size: 0.9rem;
    color: #475569;
    font-weight: 600;
}

.overview-teacher-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.95rem;
}

.overview-teacher-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #475569;
    padding: 14px 16px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}

.overview-teacher-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
}

.overview-teacher-table tbody tr {
    transition: background 0.2s ease, transform 0.2s ease;
}

.overview-teacher-table tbody tr.teacher-overview-row {
    cursor: pointer;
}

.overview-teacher-table tbody tr:hover {
    background: #f9fafb;
    transform: translateX(2px);
}

.teacher-name-link {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: inherit;
}

.teacher-name-link:hover {
    color: #2563eb;
}

.teacher-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: #0f172a;
}

.teacher-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #3b82f6);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
    text-transform: uppercase;
    box-shadow: 0 6px 12px rgba(59, 130, 246, 0.35);
}

.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 52px;
    padding: 8px 14px;
    border-radius: 999px;
    font-weight: 700;
    color: #0f172a;
    background: linear-gradient(135deg, #e0f2fe, #bfdbfe);
    border: 1px solid rgba(59, 130, 246, 0.2);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.count-pill.success {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-color: rgba(16, 185, 129, 0.25);
    color: #065f46;
}

.overview-empty-state {
    text-align: center;
    padding: 30px;
    color: #94a3b8;
    font-weight: 600;
}

/* Teacher Course Modal Styles */
.teacher-course-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.teacher-course-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.teacher-course-modal-content {
    position: relative;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    width: 90%;
    max-width: 700px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    z-index: 10001;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.teacher-course-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.teacher-course-modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
}

.teacher-course-modal-close {
    background: transparent;
    border: none;
    color: #6b7280;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: all 0.2s;
}

.teacher-course-modal-close:hover {
    background: #f3f4f6;
    color: #1f2937;
}

.teacher-course-modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

.teacher-course-modal-loading,
.teacher-course-modal-empty,
.teacher-course-modal-error {
    text-align: center;
    padding: 40px 20px;
    color: #6b7280;
}

.teacher-course-modal-loading i,
.teacher-course-modal-empty i,
.teacher-course-modal-error i {
    font-size: 2.5rem;
    margin-bottom: 12px;
    display: block;
    color: #94a3b8;
}

.teacher-course-modal-loading i {
    color: #3b82f6;
}

.teacher-course-modal-error i {
    color: #ef4444;
}

.teacher-course-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.95rem;
}

.teacher-course-table thead th {
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #475569;
    padding: 14px 16px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
    font-weight: 600;
}

.teacher-course-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
}

.teacher-course-table tbody tr:hover {
    background: #f9fafb;
}

.teacher-course-table tbody tr:last-child td {
    border-bottom: none;
}

.overview-pagination-controls {
    margin-top: 20px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.overview-pagination-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
}

.overview-pagination-buttons button {
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.overview-pagination-buttons button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.overview-page-numbers {
    display: flex;
    gap: 6px;
}

.overview-page-numbers button {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.2s ease;
}

.overview-page-numbers button.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #ffffff;
}

.overview-page-numbers button:not(.active):hover {
    background: #f1f5f9;
}
</style>
<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-text">
                <h1 class="page-title">Teacher Performance Reports</h1>
                <p class="page-subtitle">Access comprehensive activity and performance analytics for <?php echo htmlspecialchars($company_info ? $company_info->name : 'your school'); ?> teachers.</p>
            </div>

            <div class="header-download-section">
                <span class="download-label">Download reports</span>
                <select class="download-select" id="teacherDownloadFormat">
                    <option value="excel">Excel (.csv)</option>
                    <option value="pdf">PDF</option>
                </select>
                <button class="download-btn" type="button" onclick="downloadTeacherReport()">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
        </div>

        <div class="tabs-container">
        <?php
            $tab_urls = [
                'summary' => new moodle_url('/theme/remui_kids/school_manager/teacher_report.php'),
                'performance' => new moodle_url('/theme/remui_kids/school_manager/teacher_report_performance.php'),
                'overview' => new moodle_url('/theme/remui_kids/school_manager/teacher_report.php', ['tab' => 'overview']),
                'activitylog' => new moodle_url('/theme/remui_kids/school_manager/teacher_report_activitylog.php'),
                'assessment' => new moodle_url('/theme/remui_kids/school_manager/teacher_report_assessment.php'),
                'coursewise' => new moodle_url('/theme/remui_kids/school_manager/teacher_report_coursewise.php')
            ];
        ?>
        <div class="tabs-nav">
            <button class="tab-button<?php echo $initial_tab === 'summary' ? ' active' : ''; ?>" type="button" data-tab="summary">
                <i class="fa fa-chalkboard-teacher"></i>
                Teacher Summary
            </button>
            <button class="tab-button<?php echo $initial_tab === 'performance' ? ' active' : ''; ?>" type="button" data-tab="performance">
                <i class="fa fa-chart-line"></i>
                Teacher Performance
            </button>
            <button class="tab-button<?php echo $initial_tab === 'overview' ? ' active' : ''; ?>" type="button" data-tab="overview">
                <i class="fa fa-dashboard"></i>
                Teacher Overview
            </button>
            <button class="tab-button<?php echo $initial_tab === 'assessment' ? ' active' : ''; ?>" type="button" data-tab="assessment">
                <i class="fa fa-clipboard-check"></i>
                Assessment &amp; Grading Report
            </button>
            <button class="tab-button<?php echo $initial_tab === 'coursewise' ? ' active' : ''; ?>" type="button" data-tab="coursewise">
                <i class="fa fa-table"></i>
                Course-wise Teacher Report Dashboard Layout
            </button>
            <button class="tab-button<?php echo $initial_tab === 'activitylog' ? ' active' : ''; ?>" type="button" data-tab="activitylog">
                <i class="fa fa-history"></i>
                Teacher Login Activity
            </button>
        </div>
        </div>

        <div id="tab-teacher-report" class="tab-content<?php echo $initial_tab === 'summary' ? ' active' : ''; ?>" data-content="summary">
            <div class="report-table-container">
                <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                    <i class="fa fa-chalkboard-teacher" style="color: #8b5cf6;"></i> Teacher Summary
                </h3>
                <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">View the distribution of teachers by course assignments in <?php echo htmlspecialchars($company_info ? $company_info->name : 'the school'); ?>.</p>

                <?php if ($teacher_load_distribution['total'] > 0): ?>
                    <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 35px; align-items: center;">
                        <div style="display: flex; justify-content: center; align-items: center; background: #ffffff; padding: 48px; border-radius: 24px; box-shadow: 0 24px 40px rgba(79, 70, 229, 0.18);">
                            <div style="position: relative; width: 420px; height: 420px;">
                                <canvas id="teacherLoadChart"></canvas>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 16px;">
                            <div style="padding: 16px 20px; background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); border-radius: 14px; border-left: 5px solid #312E81; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 800; color: #1e1b4b; line-height: 1; margin-bottom: 6px;"><?php echo $teacher_load_distribution['no_load']; ?></div>
                                <div style="font-size: 0.8rem; color: #312e81; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">0 Courses (Not Assigned)</div>
                                <div style="font-size: 0.75rem; color: #4338ca; margin-top: 4px; font-weight: 600;">
                                    <?php echo round(($teacher_load_distribution['no_load'] / $teacher_load_distribution['total']) * 100, 1); ?>% of teachers
                                </div>
                            </div>

                            <div style="padding: 16px 20px; background: linear-gradient(135deg, #ede9fe 0%, #c4b5fd 100%); border-radius: 14px; border-left: 5px solid #8B5CF6; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 800; color: #553c9a; line-height: 1; margin-bottom: 6px;"><?php echo $teacher_load_distribution['low_load']; ?></div>
                                <div style="font-size: 0.8rem; color: #5b21b6; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">1-2 Courses</div>
                                <div style="font-size: 0.75rem; color: #7c3aed; margin-top: 4px; font-weight: 600;">
                                    <?php echo round(($teacher_load_distribution['low_load'] / $teacher_load_distribution['total']) * 100, 1); ?>% of teachers
                                </div>
                            </div>

                            <div style="padding: 16px 20px; background: linear-gradient(135deg, #e0f2ff 0%, #bae6fd 100%); border-radius: 14px; border-left: 5px solid #38BDF8; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 800; color: #0c4a6e; line-height: 1; margin-bottom: 6px;"><?php echo $teacher_load_distribution['medium_load']; ?></div>
                                <div style="font-size: 0.8rem; color: #0369a1; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">3-5 Courses</div>
                                <div style="font-size: 0.75rem; color: #0284c7; margin-top: 4px; font-weight: 600;">
                                    <?php echo round(($teacher_load_distribution['medium_load'] / $teacher_load_distribution['total']) * 100, 1); ?>% of teachers
                                </div>
                            </div>

                            <div style="padding: 16px 20px; background: linear-gradient(135deg, #ffe4e8 0%, #fecdd3 100%); border-radius: 14px; border-left: 5px solid #FB7185; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 800; color: #be123c; line-height: 1; margin-bottom: 6px;"><?php echo $teacher_load_distribution['high_load']; ?></div>
                                <div style="font-size: 0.8rem; color: #b91c1c; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">More than 5 Courses</div>
                                <div style="font-size: 0.75rem; color: #e11d48; margin-top: 4px; font-weight: 600;">
                                    <?php echo round(($teacher_load_distribution['high_load'] / $teacher_load_distribution['total']) * 100, 1); ?>% of teachers
                                </div>
                            </div>

                            <div style="padding: 18px 20px; background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); border-radius: 14px; border: 2px solid #8b5cf6; text-align: center; box-shadow: 0 18px 30px rgba(139, 92, 246, 0.2);">
                                <div style="font-size: 2.2rem; font-weight: 800; color: #6d28d9; line-height: 1; margin-bottom: 6px;"><?php echo $teacher_load_distribution['total']; ?></div>
                                <div style="font-size: 0.82rem; color: #5b21b6; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Teachers</div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data-row">
                        <i class="fa fa-chalkboard-teacher" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                        <p>No teachers found in your school.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div id="tab-teacher-performance" class="tab-content<?php echo $initial_tab === 'performance' ? ' active' : ''; ?>" data-content="performance">
            <div class="report-table-container">
                <div style="margin-top: 10px;">
                    <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                        <i class="fa fa-star" style="color: #f59e0b;"></i> Teacher Performance
                    </h3>
                    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">View teacher performance metrics based on courses taught, completion rates, and student engagement.</p>

                    <?php if (!empty($teacher_performance_data)): ?>
                        <div class="chart-toolbar">
                            <div class="chart-search" id="teacherPerformanceSearchWrapper">
                                <i class="fa fa-search"></i>
                                <input type="text" id="teacherPerformanceSearch" placeholder="Search teacher by name..." autocomplete="off" />
                                <button type="button" id="teacherPerformanceSearchClear" title="Clear search">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="scrollable-chart-container" style="padding: 30px 0;" data-scrollable="teacher-performance">
                            <div style="position: relative; height: 520px; width: <?php echo max(900, count($teacher_performance_data) * 150); ?>px;">
                                <canvas id="teacherPerformanceChart"></canvas>
                            </div>
                        </div>

                        <div style="margin-top: 30px; background: white; border-radius: 16px; padding: 28px; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;">
                                <h4 style="font-size: 1.15rem; font-weight: 700; color: #1f2937; margin: 0;">
                                    <i class="fa fa-table" style="color: #6b7280;"></i> Performance Breakdown
                                </h4>
                            </div>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; min-width: 1100px; border-collapse: collapse; font-size: 0.92rem;">
                                    <thead>
                                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 12px; text-align: left; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Teacher Name</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Courses Taught</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Total Students</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Completed</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Completion Rate</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Avg Student Grade</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Avg Quiz Score</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Performance Score</th>
                                        </tr>
                                    </thead>
                                    <tbody id="teacherTableBody">
                                        <?php $teacher_index = 0; ?>
                                        <?php foreach ($teacher_performance_data as $teacher): ?>
                                            <?php $teacher_index++; ?>
                                            <?php $detailurl = new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_performance.php', ['teacherid' => $teacher['id']]); ?>
                                            <tr class="teacher-row" data-page="<?php echo ceil($teacher_index / 12); ?>" style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; <?php echo $teacher_index > 12 ? 'display: none;' : ''; ?>" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                <td style="padding: 12px; font-weight: 600; color: #1f2937; white-space: nowrap;">
                                                    <a href="<?php echo $detailurl; ?>" style="color: inherit; text-decoration: none;">
                                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                                    </a>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600;"><?php echo $teacher['courses_taught']; ?></td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600;"><?php echo $teacher['total_students']; ?></td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600;"><?php echo $teacher['completed_students']; ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 700; color: <?php echo $teacher['completion_rate'] >= 70 ? '#10b981' : ($teacher['completion_rate'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                    <?php echo $teacher['completion_rate']; ?>%
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 700; color: <?php echo $teacher['avg_student_grade'] >= 70 ? '#10b981' : ($teacher['avg_student_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                    <?php echo $teacher['avg_student_grade']; ?>%
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 700; color: <?php echo $teacher['avg_quiz_score'] >= 70 ? '#10b981' : ($teacher['avg_quiz_score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                    <?php echo $teacher['avg_quiz_score']; ?>%
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 800; font-size: 1.1rem; color: <?php echo $teacher['performance_score'] >= 70 ? '#10b981' : ($teacher['performance_score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                    <?php echo $teacher['performance_score']; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php
                                $teachers_per_page = 12;
                                $total_teachers = count($teacher_performance_data);
                                $total_teacher_pages = ceil($total_teachers / $teachers_per_page);
                            ?>
                            <?php if ($total_teachers > 0): ?>
                                <div id="teacherPaginationControls" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; margin-top: 25px; padding: 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
                            <?php if ($total_teacher_pages > 1): ?>
                                        <div style="display: flex; justify-content: center; align-items: center; gap: 10px; order: 1;">
                                            <button id="teacherPrevBtn" onclick="changeTeacherPage('prev')" style="padding: 8px 16px; background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: not-allowed; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa fa-chevron-left"></i> Previous
                                    </button>
                                    <div id="teacherPageNumbers" style="display: flex; gap: 6px;"></div>
                                            <button id="teacherNextBtn" onclick="changeTeacherPage('next')" style="padding: 8px 16px; background: #ffffff; color: #3b82f6; border: 1px solid #3b82f6; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 6px;" onmouseover="if(this.style.cursor!='not-allowed'){this.style.background='#3b82f6'; this.style.color='#ffffff';}" onmouseout="if(this.style.cursor!='not-allowed'){this.style.background='#ffffff'; this.style.color='#3b82f6';}">
                                        Next <i class="fa fa-chevron-right"></i>
                                    </button>
                                        </div>
                                    <?php else: ?>
                                        <div style="display: none; order: 1;" id="teacherPaginationControlsHidden">
                                            <button id="teacherPrevBtn" style="display: none;"></button>
                                            <div id="teacherPageNumbers" style="display: none;"></div>
                                            <button id="teacherNextBtn" style="display: none;"></button>
                                        </div>
                                    <?php endif; ?>
                                    <div id="teacherPaginationInfo" style="font-size: 14px; color: #6b7280; font-weight: 500; text-align: center; order: 2;">
                                        Showing <span style="color: #1f2937; font-weight: 600;">1</span> - <span style="color: #1f2937; font-weight: 600;"><?php echo min(12, count($teacher_performance_data)); ?></span> of <span style="color: #1f2937; font-weight: 600;"><?php echo count($teacher_performance_data); ?></span> teachers
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top: 22px; padding: 18px; background: #f9fafb; border-radius: 10px; border-left: 4px solid #3b82f6;">
                                <p style="font-size: 0.9rem; color: #6b7280; margin: 0;">
                                    <strong>Performance Score Calculation:</strong> Courses taught (30%), student completion rate (40%), and student engagement (30%). Maximum score: 100 points.
                                </p>
                            </div>

                            <div id="teacherDetailOverlay" class="teacher-detail-overlay" aria-hidden="true">
                                <div class="teacher-detail-overlay-content">
                                    <div id="teacherDetailPanel" class="teacher-detail-panel" style="display: none; margin-top: 0;">
                                <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px;">
                                    <div>
                                        <h4 id="teacherDetailName"></h4>
                                        <p id="teacherDetailSubtitle" style="margin: 6px 0 0 0; color: #64748b; font-size: 0.9rem;"></p>
                                    </div>
                                    <button type="button" id="teacherDetailReset" style="padding: 10px 18px; border-radius: 999px; background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5f5; font-weight: 600; cursor: pointer; display: none;">
                                        <i class="fa fa-arrow-left"></i> Back to List
                                    </button>
                                </div>

                                <div id="teacherDetailSummary" class="teacher-detail-summary"></div>

                                <div class="teacher-detail-secondary">
                                    <div class="teacher-detail-chart">
                                        <h5><i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Course Performance</h5>
                                        <div style="position: relative; height: 320px;">
                                            <canvas id="teacherCoursePerformanceChart"></canvas>
                                        </div>
                                        <div id="teacherCourseChartHelper" class="chart-helper">Completion, grade, quiz, and assignment averages per course.</div>
                                    </div>
                                    <div class="teacher-detail-chart">
                                        <h5><i class="fa fa-poll" style="color: #6366f1;"></i> Assessment Overview</h5>
                                        <div style="position: relative; height: 320px;">
                                            <canvas id="teacherAssessmentChart"></canvas>
                                        </div>
                                        <div id="teacherAssessmentLegend" class="chart-helper">Breakdown of created assessments and grading activity.</div>
                                    </div>
                                </div>

                                <div class="teacher-detail-table">
                                    <h5 style="margin: 0 0 18px 0; font-size: 1rem; font-weight: 700; color: #1f2937;">
                                        <i class="fa fa-list-ul" style="color: #f97316;"></i> Course Breakdown
                                    </h5>
                                    <div style="overflow-x: auto;">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Course</th>
                                                    <th>Students</th>
                                                    <th>Completed</th>
                                                    <th>Completion Rate</th>
                                                    <th>Avg Course Grade</th>
                                                    <th>Avg Quiz Score</th>
                                                    <th>Avg Assignment Grade</th>
                                                    <th>Quizzes</th>
                                                    <th>Assignments</th>
                                                    <th>Quiz Attempts</th>
                                                    <th>Assignments Graded</th>
                                                </tr>
                                            </thead>
                                            <tbody id="teacherCourseTableBody"></tbody>
                                        </table>
                                    </div>
                                    <p id="teacherDetailEmptyState" class="teacher-detail-caption" style="display: none;">No course data available for this teacher yet.</p>
                                </div>
                            </div>
                        </div>
                </div>

                        </div>
                    <?php else: ?>
                        <div class="no-data-row">
                            <i class="fa fa-star" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                            <p>No teacher performance data available.</p>
                        </div>
                    <?php endif; ?>
                </div>

                            </div>
                            </div>
        <div id="tab-teacher-overview" class="tab-content<?php echo $initial_tab === 'overview' ? ' active' : ''; ?>" data-content="overview">
            <div class="report-table-container">
                <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fa fa-users" style="color: #3b82f6;"></i> Teacher Overview
                </h3>
                <p style="color: #6b7280; margin-bottom: 16px; font-size: 0.95rem;">
                    Review each teacher's current course assignments and total students under their responsibility.
                </p>

                <?php if (!empty($teacher_performance_data)): ?>
                    <div class="overview-utility-bar">
                        <div class="overview-search">
                            <i class="fa fa-search"></i>
                            <input type="search" id="teacherOverviewSearch" placeholder="Search teacher by name..." autocomplete="off" />
                            <button type="button" id="teacherOverviewSearchClear" title="Clear search">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        <div class="overview-pagination-info" id="teacherOverviewPaginationInfo">
                            Showing 0 - 0 of 0 teachers
                        </div>
                    </div>
                    <div class="overview-table-wrapper">
                        <table class="overview-teacher-table">
                            <thead>
                                <tr>
                                    <th>Teacher Name</th>
                                    <th style="text-align: center;">Total Courses Assigned</th>
                                    <th style="text-align: center;">Total Students</th>
                                </tr>
                            </thead>
                            <tbody id="teacherOverviewTableBody"></tbody>
                        </table>
                        <div class="overview-empty-state" id="teacherOverviewEmptyState" style="display: none;">
                            <i class="fa fa-info-circle" style="font-size: 1.4rem; display: block; margin-bottom: 6px;"></i>
                            No teachers match your search.
                        </div>
                    </div>
                    <div class="overview-pagination-controls">
                        <div class="overview-pagination-buttons">
                            <button type="button" id="teacherOverviewPrev" disabled>
                                <i class="fa fa-chevron-left"></i> Previous
                            </button>
                            <div class="overview-page-numbers" id="teacherOverviewPageNumbers"></div>
                            <button type="button" id="teacherOverviewNext" disabled>
                                Next <i class="fa fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data-row">
                        <i class="fa fa-info-circle" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                        <p>No teacher overview data is available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Teacher Course List Modal -->
        <div id="teacherCourseModal" class="teacher-course-modal" style="display: none;">
            <div class="teacher-course-modal-overlay" onclick="closeTeacherCourseModal()"></div>
            <div class="teacher-course-modal-content">
                <div class="teacher-course-modal-header">
                    <h3 id="teacherCourseModalTitle">Teacher Courses</h3>
                    <button type="button" class="teacher-course-modal-close" onclick="closeTeacherCourseModal()" aria-label="Close">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div class="teacher-course-modal-body">
                    <div id="teacherCourseModalLoading" class="teacher-course-modal-loading">
                        <i class="fa fa-spinner fa-spin"></i>
                        <p>Loading courses...</p>
                    </div>
                    <div id="teacherCourseModalContent" class="teacher-course-modal-content-inner" style="display: none;">
                        <table class="teacher-course-table">
                            <thead>
                                <tr>
                                    <th>Course Name</th>
                                    <th style="text-align: center;">Total Students</th>
                                </tr>
                            </thead>
                            <tbody id="teacherCourseTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div id="teacherCourseModalEmpty" class="teacher-course-modal-empty" style="display: none;">
                        <i class="fa fa-info-circle"></i>
                        <p>No courses assigned to this teacher.</p>
                    </div>
                    <div id="teacherCourseModalError" class="teacher-course-modal-error" style="display: none;">
                        <i class="fa fa-exclamation-circle"></i>
                        <p id="teacherCourseModalErrorMessage">An error occurred while loading courses.</p>
                    </div>
                </div>
            </div>
        </div>
    <div id="tab-assessment" class="tab-content<?php echo $initial_tab === 'assessment' ? ' active' : ''; ?>" data-content="assessment">
        <div class="report-table-container">
            <div style="margin-top: 10px;">
                <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fa fa-clipboard-check" style="color: #6366f1;"></i> Assessment &amp; Grading Report
                </h3>
                <p style="color: #6b7280; margin-bottom: 24px; font-size: 0.95rem;">
                    Monitor assessment creation activity, grading workload, student performance, and feedback turnaround trends across your teaching team.
                </p>

                <?php if (!empty($teacher_detail_data)): ?>
                    <?php
                        $assessment_kpi = $assessment_dashboard_data['kpi'];
                        $assessment_pending = $assessment_dashboard_data['pending'];
                        $assessment_feedback = $assessment_dashboard_data['feedback'];
                    ?>

                    <div class="assessment-kpi-grid">
                        <div class="assessment-kpi-card assessment-kpi-card--primary">
                            <span>Quizzes Created</span>
                            <strong><?php echo number_format($assessment_kpi['quizzes_created']); ?></strong>
                            <small>Total quizzes authored by teachers</small>
                        </div>
                        <div class="assessment-kpi-card assessment-kpi-card--success">
                            <span>Assignments Created</span>
                            <strong><?php echo number_format($assessment_kpi['assignments_created']); ?></strong>
                            <small>Assignments prepared for submission</small>
                        </div>
                        <div class="assessment-kpi-card assessment-kpi-card--warning">
                            <span>Pending Grading</span>
                            <strong><?php echo number_format($assessment_kpi['pending_total']); ?></strong>
                            <small><?php echo number_format($assessment_pending['assignment_total']); ?> assignments • <?php echo number_format($assessment_pending['quiz_total']); ?> quizzes awaiting review</small>
                        </div>
                        <div class="assessment-kpi-card assessment-kpi-card--grade">
                            <span>Average Student Marks</span>
                            <strong><?php echo $assessment_kpi['avg_marks_overall'] > 0 ? $assessment_kpi['avg_marks_overall'] . '%' : 'N/A'; ?></strong>
                            <small>Overall performance across courses</small>
                        </div>
                    </div>

                    <div class="assessment-chart-grid">
                        <div class="assessment-chart-card">
                            <div class="assessment-card-header">
                                <h4><i class="fa fa-layer-group" style="color: #6366f1;"></i> Assessment Creation Activity</h4>
                                <span class="assessment-card-helper">Compare quizzes vs assignments created per teacher</span>
                            </div>
                            <?php if (!empty($assessment_dashboard_data['teacher_activity']['labels'])): ?>
                                <div style="position: relative; height: 340px;">
                                    <canvas id="assessmentActivityChart"></canvas>
                                </div>
                            <?php else: ?>
                                <div class="no-data-row" style="margin: 20px 0;">No assessment creation activity recorded yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="assessment-chart-card">
                            <div class="assessment-card-header">
                                <h4><i class="fa fa-exclamation-triangle" style="color: #ef4444;"></i> Pending Grading Workload</h4>
                                <span class="assessment-card-helper">Highlight teachers with the highest review backlog</span>
                            </div>
                            <?php if (!empty($assessment_dashboard_data['pending']['top'])): ?>
                                <div class="assessment-table-wrapper">
                                    <table class="assessment-table">
                                        <thead>
                                            <tr>
                                                <th>Teacher</th>
                                                <th style="text-align: center;">Assignments Pending</th>
                                                <th style="text-align: center;">Quizzes Pending</th>
                                                <th style="text-align: center;">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assessment_dashboard_data['pending']['top'] as $pending_teacher): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($pending_teacher['name']); ?></div>
                                                    </td>
                                                    <td style="text-align: center; font-weight: 600; color: <?php echo $pending_teacher['assignments'] > 0 ? '#ef4444' : '#10b981'; ?>;">
                                                        <?php echo number_format($pending_teacher['assignments']); ?>
                                                    </td>
                                                    <td style="text-align: center; font-weight: 600; color: <?php echo $pending_teacher['quizzes'] > 0 ? '#f97316' : '#10b981'; ?>;">
                                                        <?php echo number_format($pending_teacher['quizzes']); ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <span class="pending-pill <?php echo $pending_teacher['total'] > 10 ? 'pending-high' : ($pending_teacher['total'] > 0 ? 'pending-medium' : 'pending-low'); ?>">
                                                            <?php echo number_format($pending_teacher['total']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-data-row" style="margin: 20px 0;">No grading backlog detected across teachers.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="assessment-note">
                        <i class="fa fa-lightbulb-o"></i>
                        <span>
                            Use these insights to balance grading workload, identify coaching opportunities for teachers with slower feedback cycles, and celebrate consistently high-performing classes.
                        </span>
                    </div>

                    <div class="assessment-teacher-table-wrapper">
                        <div class="assessment-teacher-table-toolbar">
                            <div class="assessment-teacher-table-title">
                                <i class="fa fa-list"></i>
                                Teacher Assessment Activity
                            </div>
                            <div class="assessment-teacher-search">
                                <input type="search" id="assessmentTeacherSearch" placeholder="Search teachers by name..." autocomplete="off" />
                                <button type="button" id="assessmentTeacherSearchClear">
                                    <i class="fa fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="assessment-teacher-table">
                                <thead>
                                    <tr>
                                        <th>Teacher Name</th>
                                        <th>Quizzes Created</th>
                                        <th>Assignments Created</th>
                                        <th>Attempt Quiz</th>
                                        <th>Attempt Assignment</th>
                                        <th>Assignment Grading Completed</th>
                                        <th>Pending Grading Total</th>
                                    </tr>
                                </thead>
                                <tbody id="assessmentTeacherTableBody"></tbody>
                            </table>
                        </div>
                        <div id="assessmentTeacherTableEmpty" class="assessment-teacher-empty" style="display: none;">
                            <i class="fa fa-info-circle"></i> No teachers match your filters.
                        </div>
                        <div class="assessment-teacher-pagination">
                            <div id="assessmentTeacherPaginationInfo">Showing 0 to 0 of 0 teachers</div>
                            <div class="assessment-teacher-pagination-controls">
                                <button type="button" id="assessmentTeacherPrev" disabled>
                                    <i class="fa fa-chevron-left"></i> Previous
                                </button>
                                <div id="assessmentTeacherPageNumbers" class="assessment-teacher-page-numbers"></div>
                                <button type="button" id="assessmentTeacherNext" disabled>
                                    Next <i class="fa fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data-row">
                        <i class="fa fa-clipboard-check" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                        <p>No assessment or grading data available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tab-course-dashboard" class="tab-content<?php echo $initial_tab === 'coursewise' ? ' active' : ''; ?>" data-content="coursewise">
            <div class="report-table-container">
                <h3 style="font-size: 1.4rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                    <i class="fa fa-table" style="color: #3b82f6;"></i> Course-wise Teacher Report Dashboard Layout
                </h3>
                <p style="color: #6b7280; margin-bottom: 24px; font-size: 0.95rem;">Detailed view of course performance, enrolments, assessment activity, and grading workload across the organisation.</p>

                <?php if (!empty($course_dashboard_data)): ?>
                    <div class="overview-kpi-grid" style="margin-bottom: 28px;">
                        <div class="overview-kpi-card">
                            <div class="metric-label">Total Courses</div>
                            <div class="metric-value"><?php echo number_format($course_dashboard_summary['courses']); ?></div>
                            <div class="metric-subtext">Latest update: <?php echo htmlspecialchars($course_dashboard_summary['latest_update_formatted']); ?></div>
                        </div>
                        <div class="overview-kpi-card" style="background: linear-gradient(135deg, #ecfeff, #e0f2fe); border-color: rgba(6, 182, 212, 0.35);">
                            <div class="metric-label">Total Students Enrolled</div>
                            <div class="metric-value" style="color: #0f766e;"><?php echo number_format($course_dashboard_summary['students']); ?></div>
                            <div class="metric-subtext">Across all active courses</div>
                        </div>
                        <div class="overview-kpi-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: rgba(234, 179, 8, 0.35);">
                            <div class="metric-label">Average Completion Rate</div>
                            <div class="metric-value" style="color: #b45309;"><?php echo $course_dashboard_summary['avg_completion']; ?>%</div>
                            <div class="metric-subtext">Based on completed vs enrolled learners</div>
                        </div>
                        <div class="overview-kpi-card" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border-color: rgba(16, 185, 129, 0.35);">
                            <div class="metric-label">Assessments Created</div>
                            <div class="metric-value" style="color: #047857;"><?php echo number_format($course_dashboard_summary['assignments'] + $course_dashboard_summary['quizzes']); ?></div>
                            <div class="metric-subtext">Assignments: <?php echo number_format($course_dashboard_summary['assignments']); ?> &bull; Quizzes: <?php echo number_format($course_dashboard_summary['quizzes']); ?></div>
                        </div>
                        <div class="overview-kpi-card" style="background: linear-gradient(135deg, #fee2e2, #fecaca); border-color: rgba(239, 68, 68, 0.35);">
                            <div class="metric-label">Pending Grading Items</div>
                            <div class="metric-value" style="color: #dc2626;"><?php echo number_format($course_dashboard_summary['pending']); ?></div>
                            <div class="metric-subtext">Assignments awaiting grading and unfinished quiz attempts</div>
                        </div>
                    </div>

                    <div class="overview-charts-grid">
                        <div class="overview-chart-card">
                            <h4><i class="fa fa-users" style="color: #2563eb;"></i> Enrolments by Course</h4>
                            <?php if (!empty($course_dashboard_charts['enrollment']['labels'])): ?>
                                <div style="position: relative; height: 320px;">
                                    <canvas id="courseDashboardEnrollmentChart"></canvas>
                                </div>
                            <?php else: ?>
                                <div class="no-data-row" style="margin: 0;">
                                    <i class="fa fa-database" style="font-size: 2.5rem; margin-bottom: 12px; color: #d1d5db;"></i>
                                    <p>No enrolment information available.</p>
                                </div>
                            <?php endif; ?>
                            <p style="margin-top: 16px; font-size: 0.85rem; color: #64748b;">Bar chart compares student volume across the top courses to identify where teacher focus is highest.</p>
                        </div>
                        <div class="overview-chart-card">
                            <h4><i class="fa fa-layer-group" style="color: #10b981;"></i> Assessments Created per Course</h4>
                            <?php if (!empty($course_dashboard_charts['assessments']['labels'])): ?>
                                <div style="position: relative; height: 320px;">
                                    <canvas id="courseDashboardAssessmentChart"></canvas>
                                </div>
                            <?php else: ?>
                                <div class="no-data-row" style="margin: 0;">
                                    <i class="fa fa-clipboard" style="font-size: 2.5rem; margin-bottom: 12px; color: #d1d5db;"></i>
                                    <p>No assessment activity recorded.</p>
                                </div>
                            <?php endif; ?>
                            <p style="margin-top: 16px; font-size: 0.85rem; color: #64748b;">Stacked comparison shows assignment and quiz creation activity for high-impact courses.</p>
                        </div>
                    </div>

                    <div class="overview-chart-card" style="margin-bottom: 28px;">
                        <h4><i class="fa fa-list" style="color: #0f766e;"></i> Course Summary Table</h4>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; min-width: 980px; border-collapse: collapse; font-size: 0.92rem;">
                                <thead>
                                    <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                        <th style="padding: 12px; text-align: left; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Course &amp; Category</th>
                                        <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Students</th>
                                        <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Completion</th>
                                        <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Assessments</th>
                                        <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Pending Grading</th>
                                        <th style="padding: 12px; text-align: center; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Last Update</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($course_dashboard_data as $course_row): ?>
                                        <tr style="border-bottom: 1px solid #e5e7eb;">
                                            <td style="padding: 12px;">
                                                <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($course_row['course_name']); ?></div>
                                                <div style="font-size: 0.78rem; color: #6b7280;">Category: <?php echo htmlspecialchars($course_row['category_name']); ?></div>
                                                <?php if (!empty($course_row['teachers'])): ?>
                                                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;"><i class="fa fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($course_row['teachers']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px; text-align: center; font-weight: 700; color: #2563eb;"><?php echo number_format($course_row['students']); ?></td>
                                            <td style="padding: 12px;">
                                                <div style="font-size: 0.82rem; color: #475569; font-weight: 600; margin-bottom: 6px; text-align: center;"><?php echo $course_row['completion_rate']; ?>%</div>
                                                <div style="width: 100%; background: #e2e8f0; border-radius: 999px; height: 8px;">
                                                    <div style="width: <?php echo min(100, $course_row['completion_rate']); ?>%; background: linear-gradient(90deg, #10b981, #059669); border-radius: 999px; height: 8px;"></div>
                                                </div>
                                            </td>
                                            <td style="padding: 12px; text-align: center;">
                                                <span style="display: inline-flex; align-items: center; gap: 6px; background: #ecfdf5; color: #047857; padding: 6px 10px; border-radius: 999px; font-weight: 600;">
                                                    <i class="fa fa-file-alt"></i> <?php echo $course_row['assignments']; ?>
                                                </span>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; background: #fef3c7; color: #b45309; padding: 6px 10px; border-radius: 999px; font-weight: 600; margin-left: 6px;">
                                                    <i class="fa fa-question-circle"></i> <?php echo $course_row['quizzes']; ?>
                                                </span>
                                            </td>
                                            <td style="padding: 12px; text-align: center;">
                                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-weight: 600; background: <?php echo $course_row['pending'] > 0 ? '#fee2e2' : '#ecfdf5'; ?>; color: <?php echo $course_row['pending'] > 0 ? '#b91c1c' : '#047857'; ?>;">
                                                    <i class="fa <?php echo $course_row['pending'] > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
                                                    <?php echo number_format($course_row['pending']); ?>
                                                </span>
                                            </td>
                                            <td style="padding: 12px; text-align: center; color: #475569; font-weight: 600;">
                                                <?php echo htmlspecialchars($course_row['last_updated']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data-row">
                        <i class="fa fa-table" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                        <p>No course-level performance data available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tab-teacher-activity-log" class="tab-content<?php echo $initial_tab === 'activitylog' ? ' active' : ''; ?>" data-content="activitylog">
        <div class="report-table-container">
            <div style="margin-top: 10px;">
                <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fa fa-history" style="color: #14b8a6;"></i> Teacher Login Activity
                </h3>
                <p style="color: #6b7280; margin-bottom: 24px; font-size: 0.95rem;">
                    Track login activity, content contributions, and communication events for each teacher to monitor engagement trends and identify support opportunities.
                </p>

                <?php if (!empty($teacher_activity_log_data['teachers'])): ?>
                    <?php $activity_summary = $teacher_activity_log_data['summary']; ?>

                    <div class="activity-log-kpi-grid">
                        <div class="activity-log-kpi-card" style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); border-left: 4px solid #0ea5e9;">
                            <span>Most Recent Login</span>
                            <strong><?php echo $activity_summary['latest_login_display']; ?></strong>
                            <small>Latest teacher access recorded</small>
                        </div>
                        <div class="activity-log-kpi-card" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-left: 4px solid #10b981;">
                            <span>Weekly Logins</span>
                            <strong><?php echo number_format($activity_summary['weekly_logins_total']); ?></strong>
                            <small>Combined logins in the last 7 days</small>
                        </div>
                        <div class="activity-log-kpi-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-left: 4px solid #f59e0b;">
                            <span>Content Updates</span>
                            <strong><?php echo number_format($activity_summary['content_updates_total']); ?></strong>
                            <small>New activities or resources added (30 days)</small>
                        </div>
                        <div class="activity-log-kpi-card" style="background: linear-gradient(135deg, #ede9fe, #c7d2fe); border-left: 4px solid #6366f1;">
                            <span>Messages Sent</span>
                            <strong><?php echo number_format($activity_summary['messages_total']); ?></strong>
                            <small>Announcements & messages shared (30 days)</small>
                        </div>
                    </div>

                    <div class="activity-log-chart-grid">
                        <div class="activity-log-chart-card">
                            <div class="activity-log-card-header">
                                <h4><i class="fa fa-line-chart" style="color: #10b981;"></i> Teacher Login Trend Over Time</h4>
                                <span class="activity-log-card-helper">Track daily teacher login activity over the past 30 days</span>
                            </div>
                            <div style="position: relative; height: 340px;">
                                <canvas id="activityLogTrendChart"></canvas>
                            </div>
                            </div>
                        </div>
                    <div class="activity-log-table-container">
                        <div class="activity-log-table-toolbar">
                            <div class="activity-log-table-header">
                                <div class="activity-log-table-title">
                                    <i class="fa fa-id-card"></i>
                                    Teacher Login Activity Details
                                </div>
                                <div id="teacherActivityPaginationInfo" class="activity-log-info-text">Showing 0 to 0 of 0 entries</div>
                            </div>
                            <div class="activity-log-toolbar-controls">
                                <div class="activity-log-search">
                                    <input type="search" id="teacherActivitySearch" placeholder="Search teachers by name or email..." autocomplete="off" />
                                    <button type="button" id="teacherActivitySearchClear">
                                        <i class="fa fa-times"></i> Clear
                                    </button>
                                </div>
                                <div class="activity-log-pagination-controls">
                                    <button type="button" id="teacherActivityPrev" disabled>Previous</button>
                                    <div id="teacherActivityPageNumbers" class="activity-log-page-numbers"></div>
                                    <button type="button" id="teacherActivityNext" disabled>Next</button>
                                </div>
                            </div>
                        </div>
                        <div class="activity-log-table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Teacher Name</th>
                                        <th>User Created</th>
                                        <th>First Login</th>
                                        <th>Last Login</th>
                                        <th style="text-align: center;">Weekly Logins</th>
                                        <th style="text-align: center;">Monthly Logins</th>
                                        <th style="text-align: center;">Total Logins</th>
                                    </tr>
                                </thead>
                                <tbody id="teacherActivityTableBody"></tbody>
                            </table>
                        </div>
                        <div id="teacherActivityTableEmpty" class="activity-log-table-empty" style="display: none;">
                            <i class="fa fa-info-circle"></i> No teacher login records match your filters.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data-row">
                        <i class="fa fa-history" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                        <p>No teacher activity log data available.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
let teacherActiveTab = '<?php echo $initial_tab; ?>';

function switchTeacherTab(tabName) {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    let changed = false;

    tabButtons.forEach(button => {
        const isTarget = button.getAttribute('data-tab') === tabName;
        if (isTarget && !button.classList.contains('active')) {
            changed = true;
        }
        button.classList.toggle('active', isTarget);
    });

    tabContents.forEach(content => {
        const isTarget = content.getAttribute('data-content') === tabName;
        content.classList.toggle('active', isTarget);
    });
    
    // Update active tab tracking
    teacherActiveTab = tabName;

    if (tabName === 'coursewise') {
        renderCourseDashboardCharts(changed || !courseDashboardChartsRendered);
    }

    if (tabName === 'activitylog') {
        renderTeacherActivityLogCharts(changed || !teacherActivityLogChartsRendered);
    }

    if (tabName === 'assessment') {
        renderAssessmentCharts(changed || !assessmentChartsRendered);
        initAssessmentTeacherTable();
    }

    if (tabName === 'performance') {
        // Reinitialize teacher pagination when performance tab is shown
        if (typeof updateTeacherTableDisplay === 'function') {
            setTimeout(() => {
                updateTeacherTableDisplay();
            }, 100);
        }
    }

    if ((tabName === 'performance' || tabName === 'coursewise' || tabName === 'assessment' || tabName === 'activitylog') && changed) {
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 150);
    }
}

window.switchTeacherTab = switchTeacherTab;

const courseDashboardChartsConfig = <?php echo json_encode($course_dashboard_charts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let courseDashboardEnrollmentChartInstance = null;
let courseDashboardAssessmentChartInstance = null;
let courseDashboardChartsRendered = false;

const teacherActivityLogConfig = <?php echo json_encode($teacher_activity_log_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let teacherActivityLogChartsRendered = false;
let activityLogContentChartInstance = null;
let activityLogMessagesChartInstance = null;
let activityLogTrendChartInstance = null;

let teacherActivityTableInitialized = false;
let teacherActivityTableData = [];
let teacherActivityTableFiltered = [];
let teacherActivityTableCurrentPage = 1;
const teacherActivityRowsPerPage = 10;
let teacherActivitySearchInput = null;
let teacherActivitySearchClearBtn = null;
let teacherActivityTableBody = null;
let teacherActivityTableEmpty = null;
let teacherActivityPaginationInfo = null;
let teacherActivityPageNumbers = null;
let teacherActivityPrevBtn = null;
let teacherActivityNextBtn = null;
const teacherDetailMap = <?php echo json_encode($teacher_detail_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
let teacherDetailCourseChart = null;
let teacherDetailAssessmentChart = null;
let selectedTeacherRowElement = null;
let selectedTeacherId = null;
const teacherCourseHelperDefault = 'Completion, grade, quiz, and assignment averages per course.';
const teacherAssessmentHelperDefault = 'Breakdown of created assessments and grading activity.';
let teacherDetailOverlayResizeBound = false;

const teacherOverviewDataset = <?php echo json_encode(array_map(function($teacher) {
    return [
        'id' => (int)($teacher['id'] ?? 0),
        'name' => $teacher['name'] ?? '',
        'courses' => (int)($teacher['courses_taught'] ?? 0),
        'students' => (int)($teacher['total_students'] ?? 0)
    ];
}, $teacher_performance_data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let teacherOverviewTableInitialized = false;
let teacherOverviewTableBody = null;
let teacherOverviewEmptyState = null;
let teacherOverviewPaginationInfo = null;
let teacherOverviewSearchInput = null;
let teacherOverviewSearchClearBtn = null;
let teacherOverviewPrevBtn = null;
let teacherOverviewNextBtn = null;
let teacherOverviewPageNumbers = null;
let teacherOverviewData = [];
let teacherOverviewFiltered = [];
let teacherOverviewCurrentPage = 1;
const teacherOverviewRowsPerPage = 8;
const teacherOverviewDetailUrlBase = '<?php echo (new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_students.php'))->out(false); ?>';

const assessmentDashboardConfig = <?php echo json_encode($assessment_dashboard_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let assessmentChartsRendered = false;
let assessmentActivityChartInstance = null;
const assessmentTeacherTableConfig = <?php echo json_encode($assessment_teacher_table_rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let assessmentTeacherTableInitialized = false;
let assessmentTeacherTableData = [];
let assessmentTeacherTableFiltered = [];
let assessmentTeacherTableCurrentPage = 1;
const assessmentTeacherRowsPerPage = 10;
let assessmentTeacherSearchInput = null;
let assessmentTeacherSearchClearBtn = null;
let assessmentTeacherTableBody = null;
let assessmentTeacherEmptyState = null;
let assessmentTeacherPaginationInfo = null;
let assessmentTeacherPrevBtn = null;
let assessmentTeacherNextBtn = null;
let assessmentTeacherPageNumbers = null;

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value).replace(/[&<>"']/g, function(match) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return map[match] || match;
    });
}

function formatNumber(value) {
    const number = Number(value);
    if (Number.isNaN(number) || !Number.isFinite(number)) {
        return '0';
    }
    return number.toLocaleString();
}

function formatPercent(value, fallback = '0%') {
    const number = Number(value);
    if (Number.isNaN(number) || !Number.isFinite(number)) {
        return fallback;
    }
    return `${number.toFixed(1)}%`;
}

function setText(element, text) {
    if (element) {
        element.textContent = text || '';
    }
}

function getInitialsFromName(name) {
    if (!name) {
        return 'NA';
    }
    const trimmed = String(name).trim();
    if (!trimmed) {
        return 'NA';
    }
    const parts = trimmed.split(/\s+/);
    const first = parts[0] ? parts[0].charAt(0) : '';
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : (parts[0] ? parts[0].charAt(1) : '');
    return (first + (last || '')).substring(0, 2).toUpperCase() || 'NA';
}

function initializeTeacherActivityTable(forceRefresh = false) {
    const tableBodyEl = document.getElementById('teacherActivityTableBody');
    if (!tableBodyEl) {
        return;
    }

    if (!forceRefresh && teacherActivityTableInitialized) {
        return;
    }

    teacherActivityTableBody = tableBodyEl;
    teacherActivityTableEmpty = document.getElementById('teacherActivityTableEmpty');
    teacherActivityPaginationInfo = document.getElementById('teacherActivityPaginationInfo');
    teacherActivityPageNumbers = document.getElementById('teacherActivityPageNumbers');
    teacherActivityPrevBtn = document.getElementById('teacherActivityPrev');
    teacherActivityNextBtn = document.getElementById('teacherActivityNext');
    teacherActivitySearchInput = document.getElementById('teacherActivitySearch');
    teacherActivitySearchClearBtn = document.getElementById('teacherActivitySearchClear');

    teacherActivityTableData = (teacherActivityLogConfig.teachers || []).map(entry => ({
        name: entry.name || '',
        email: entry.email || '',
        userCreated: entry.user_created_display || '—',
        userCreatedTs: entry.user_created_ts || null,
        firstLogin: entry.first_login_display || '<?php echo addslashes(get_string('never')); ?>',
        firstLoginTs: entry.first_login_ts || null,
        lastLogin: entry.last_login_display || '<?php echo addslashes(get_string('never')); ?>',
        lastLoginTs: entry.last_login_ts || null,
        weeklyLogins: Number(entry.weekly_logins || 0),
        monthlyLogins: Number(entry.monthly_logins || 0),
        totalLogins: Number(entry.total_logins || 0)
    }));

    teacherActivityTableFiltered = [...teacherActivityTableData];
    teacherActivityTableCurrentPage = 1;

    if (!teacherActivityTableInitialized) {
        if (teacherActivitySearchInput) {
            teacherActivitySearchInput.addEventListener('input', handleTeacherActivitySearch);
        }
        if (teacherActivitySearchClearBtn) {
            teacherActivitySearchClearBtn.addEventListener('click', () => {
                if (teacherActivitySearchInput) {
                    teacherActivitySearchInput.value = '';
                }
                handleTeacherActivitySearch();
            });
        }
        if (teacherActivityPrevBtn) {
            teacherActivityPrevBtn.addEventListener('click', () => {
                if (teacherActivityTableCurrentPage > 1) {
                    teacherActivityTableCurrentPage--;
                    renderTeacherActivityTable();
                }
            });
        }
        if (teacherActivityNextBtn) {
            teacherActivityNextBtn.addEventListener('click', () => {
                const totalPages = Math.max(1, Math.ceil(teacherActivityTableFiltered.length / teacherActivityRowsPerPage));
                if (teacherActivityTableCurrentPage < totalPages) {
                    teacherActivityTableCurrentPage++;
                    renderTeacherActivityTable();
                }
            });
        }
    }

    teacherActivityTableInitialized = true;
    renderTeacherActivityTable();
}
function handleTeacherActivitySearch() {
    const query = (teacherActivitySearchInput?.value || '').trim().toLowerCase();
    if (!query) {
        teacherActivityTableFiltered = [...teacherActivityTableData];
    } else {
        teacherActivityTableFiltered = teacherActivityTableData.filter(entry => {
            return entry.name.toLowerCase().includes(query) || entry.email.toLowerCase().includes(query);
        });
    }
    teacherActivityTableCurrentPage = 1;
    renderTeacherActivityTable();
}
function renderTeacherActivityTable() {
    if (!teacherActivityTableBody) {
        return;
    }

    const totalEntries = teacherActivityTableFiltered.length;
    const totalPages = Math.max(1, Math.ceil(totalEntries / teacherActivityRowsPerPage));
    teacherActivityTableCurrentPage = Math.min(Math.max(1, teacherActivityTableCurrentPage), totalPages);

    const startIndex = (teacherActivityTableCurrentPage - 1) * teacherActivityRowsPerPage;
    const pageEntries = teacherActivityTableFiltered.slice(startIndex, startIndex + teacherActivityRowsPerPage);

    if (pageEntries.length) {
        teacherActivityTableBody.innerHTML = pageEntries.map(entry => `
            <tr>
                <td style="font-weight: 600; color: #1f2937;">${escapeHtml(entry.name)}</td>
                <td style="color: #475569;">${escapeHtml(entry.userCreated)}</td>
                <td style="color: #475569;">${escapeHtml(entry.firstLogin)}</td>
                <td style="color: #475569;">${escapeHtml(entry.lastLogin)}</td>
                <td style="text-align: center; font-weight: 600; color: #2563eb;">${entry.weeklyLogins}</td>
                <td style="text-align: center; font-weight: 600; color: #6366f1;">${entry.monthlyLogins}</td>
                <td style="text-align: center;">
                    <span class="activity-log-total-pill">${entry.totalLogins}</span>
                </td>
            </tr>
        `).join('');
    } else {
        teacherActivityTableBody.innerHTML = '';
    }

    if (teacherActivityTableEmpty) {
        teacherActivityTableEmpty.style.display = pageEntries.length ? 'none' : 'block';
    }

    if (teacherActivityPaginationInfo) {
        const startDisplay = totalEntries === 0 ? 0 : startIndex + 1;
        const endDisplay = totalEntries === 0 ? 0 : startIndex + pageEntries.length;
        teacherActivityPaginationInfo.textContent = `Showing ${startDisplay} to ${endDisplay} of ${totalEntries} entries`;
    }

    updateTeacherActivityPaginationControls(totalPages);
}

function updateTeacherActivityPaginationControls(totalPages) {
    if (teacherActivityPrevBtn) {
        teacherActivityPrevBtn.disabled = teacherActivityTableCurrentPage <= 1;
    }
    if (teacherActivityNextBtn) {
        teacherActivityNextBtn.disabled = teacherActivityTableCurrentPage >= totalPages || totalPages === 0;
    }

    if (!teacherActivityPageNumbers) {
        return;
    }

    const maxButtons = 5;
    let startPage = Math.max(1, teacherActivityTableCurrentPage - Math.floor(maxButtons / 2));
    let endPage = startPage + maxButtons - 1;

    if (endPage > totalPages) {
        endPage = totalPages;
        startPage = Math.max(1, endPage - maxButtons + 1);
    }

    let buttonsHtml = '';
    for (let page = startPage; page <= endPage; page++) {
        const activeClass = page === teacherActivityTableCurrentPage ? 'active' : '';
        buttonsHtml += `<button type="button" class="${activeClass}" data-page="${page}">${page}</button>`;
    }
    teacherActivityPageNumbers.innerHTML = buttonsHtml;

    const pageButtons = teacherActivityPageNumbers.querySelectorAll('button[data-page]');
    pageButtons.forEach(button => {
        button.addEventListener('click', () => {
            const page = Number(button.getAttribute('data-page'));
            if (!Number.isNaN(page)) {
                teacherActivityTableCurrentPage = page;
                renderTeacherActivityTable();
            }
        });
    });
}

function positionTeacherDetailOverlay() {
    const overlay = document.getElementById('teacherDetailOverlay');
    if (!overlay) {
        return;
    }

    let topOffset = 55;

    const headerSelectors = [
        '.school-manager-topbar',
        '.school-manager-header',
        '.school-manager-navbar',
        '.school-manager-appbar',
        '.school-manager-toolbar',
        '.top-navbar',
        '.page-navbar',
        '#page-navbar',
        'header.navbar',
        'header.fixed-top',
        '.fixed-top'
    ];

    headerSelectors.forEach(selector => {
        const headerEl = document.querySelector(selector);
        if (headerEl) {
            const rect = headerEl.getBoundingClientRect();
            topOffset = Math.max(topOffset, rect.bottom);
        }
    });

    let leftOffset = 280;
    const sidebarSelectors = [
        '.school-manager-sidebar',
        '#nav-drawer',
        '.drawer',
        '.blockdrawer',
        '.school-manager-sidebar-container'
    ];

    sidebarSelectors.forEach(selector => {
        const sidebarEl = document.querySelector(selector);
        if (!sidebarEl) {
            return;
        }
        const styles = window.getComputedStyle(sidebarEl);
        if (styles.display === 'none' || styles.visibility === 'hidden' || styles.opacity === '0') {
            return;
        }
        const rect = sidebarEl.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
            leftOffset = Math.max(leftOffset, rect.left + rect.width);
        }
    });

    const overlayTop = Math.max(55, Math.round(topOffset));

    overlay.style.left = `${leftOffset}px`;
    overlay.style.width = `calc(100vw - ${leftOffset}px)`;
    overlay.style.setProperty('--teacher-detail-overlay-top', `${overlayTop}px`);
    overlay.style.top = `${overlayTop}px`;
    overlay.style.height = `calc(100vh - ${overlayTop}px)`;
    overlay.style.paddingTop = '24px';
    
    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }
}

function lightenColor(hex, amount = 0.25) {
    if (!hex) return '#ffffff';
    let color = hex.replace('#', '');
    if (color.length === 3) {
        color = color.split('').map(ch => ch + ch).join('');
    }
    const num = parseInt(color, 16);
    let r = (num >> 16) & 0xff;
    let g = (num >> 8) & 0xff;
    let b = num & 0xff;

    r = Math.round(r + (255 - r) * amount);
    g = Math.round(g + (255 - g) * amount);
    b = Math.round(b + (255 - b) * amount);

    return `rgb(${Math.min(255, r)}, ${Math.min(255, g)}, ${Math.min(255, b)})`;
}

function darkenColor(hex, amount = 0.2) {
    if (!hex) return '#000000';
    let color = hex.replace('#', '');
    if (color.length === 3) {
        color = color.split('').map(ch => ch + ch).join('');
    }
    const num = parseInt(color, 16);
    let r = (num >> 16) & 0xff;
    let g = (num >> 8) & 0xff;
    let b = num & 0xff;

    r = Math.round(r * (1 - amount));
    g = Math.round(g * (1 - amount));
    b = Math.round(b * (1 - amount));

    return `rgb(${Math.max(0, r)}, ${Math.max(0, g)}, ${Math.max(0, b)})`;
}

function enableDragScroll(container) {
    if (!container) {
        return;
    }

    let isDragging = false;
    let startX = 0;
    let scrollLeft = 0;

    const startDrag = (x) => {
        isDragging = true;
        startX = x;
        scrollLeft = container.scrollLeft;
        container.classList.add('dragging');
    };

    const moveDrag = (x) => {
        if (!isDragging) return;
        const walk = (x - startX);
        container.scrollLeft = scrollLeft - walk;
    };

    const stopDrag = () => {
        isDragging = false;
        container.classList.remove('dragging');
    };

    container.addEventListener('mousedown', (event) => {
        event.preventDefault();
        startDrag(event.pageX);
    });

    container.addEventListener('mousemove', (event) => {
        if (!isDragging) return;
        event.preventDefault();
        moveDrag(event.pageX);
    });

    container.addEventListener('mouseup', stopDrag);
    container.addEventListener('mouseleave', stopDrag);

    container.addEventListener('touchstart', (event) => {
        if (!event.touches.length) return;
        startDrag(event.touches[0].pageX);
    }, { passive: true });

    container.addEventListener('touchmove', (event) => {
        if (!event.touches.length) return;
        moveDrag(event.touches[0].pageX);
    }, { passive: true });

    container.addEventListener('touchend', stopDrag);
}

function getPerformanceColor(value) {
    const number = Number(value);
    if (Number.isNaN(number) || !Number.isFinite(number)) {
        return '#6b7280';
    }
    if (number >= 70) {
        return '#10b981';
    }
    if (number >= 50) {
        return '#f59e0b';
    }
    return '#ef4444';
}

function initAssessmentTeacherTable() {
    const tableEl = document.getElementById('assessmentTeacherTableBody');
    if (!tableEl) {
        return;
    }
    if (assessmentTeacherTableInitialized) {
        return;
    }

    assessmentTeacherTableBody = tableEl;
    assessmentTeacherEmptyState = document.getElementById('assessmentTeacherTableEmpty');
    assessmentTeacherPaginationInfo = document.getElementById('assessmentTeacherPaginationInfo');
    assessmentTeacherPrevBtn = document.getElementById('assessmentTeacherPrev');
    assessmentTeacherNextBtn = document.getElementById('assessmentTeacherNext');
    assessmentTeacherPageNumbers = document.getElementById('assessmentTeacherPageNumbers');
    assessmentTeacherSearchInput = document.getElementById('assessmentTeacherSearch');
    assessmentTeacherSearchClearBtn = document.getElementById('assessmentTeacherSearchClear');

    assessmentTeacherTableData = (Array.isArray(assessmentTeacherTableConfig) ? assessmentTeacherTableConfig : []).map(entry => ({
        name: entry.name || '',
        quizzes_created: Number(entry.quizzes_created || 0),
        assignments_created: Number(entry.assignments_created || 0),
        quiz_grading: Number(entry.quiz_grading || 0),
        assignment_attempts: Number(entry.assignment_attempts || 0),
        assignment_grading: Number(entry.assignment_grading || 0),
        pending_total: Number(entry.pending_total || 0),
        pending_assignments: Number(entry.pending_assignments || 0),
        pending_quizzes: Number(entry.pending_quizzes || 0)
    }));

    assessmentTeacherTableFiltered = [...assessmentTeacherTableData];
    assessmentTeacherTableCurrentPage = 1;

    if (assessmentTeacherSearchInput) {
        assessmentTeacherSearchInput.addEventListener('input', filterAssessmentTeacherTable);
    }
    if (assessmentTeacherSearchClearBtn) {
        assessmentTeacherSearchClearBtn.addEventListener('click', () => {
            if (assessmentTeacherSearchInput) {
                assessmentTeacherSearchInput.value = '';
            }
            filterAssessmentTeacherTable();
        });
    }
    if (assessmentTeacherPrevBtn) {
        assessmentTeacherPrevBtn.addEventListener('click', () => {
            if (assessmentTeacherTableCurrentPage > 1) {
                assessmentTeacherTableCurrentPage--;
                renderAssessmentTeacherTable();
            }
        });
    }
    if (assessmentTeacherNextBtn) {
        assessmentTeacherNextBtn.addEventListener('click', () => {
            const totalPages = Math.max(1, Math.ceil(assessmentTeacherTableFiltered.length / assessmentTeacherRowsPerPage));
            if (assessmentTeacherTableCurrentPage < totalPages) {
                assessmentTeacherTableCurrentPage++;
                renderAssessmentTeacherTable();
            }
        });
    }

    renderAssessmentTeacherTable();
    assessmentTeacherTableInitialized = true;
}

function filterAssessmentTeacherTable() {
    const query = (assessmentTeacherSearchInput?.value || '').trim().toLowerCase();
    if (!query) {
        assessmentTeacherTableFiltered = [...assessmentTeacherTableData];
    } else {
        assessmentTeacherTableFiltered = assessmentTeacherTableData.filter(entry =>
            entry.name.toLowerCase().includes(query)
        );
    }
    assessmentTeacherTableCurrentPage = 1;
    renderAssessmentTeacherTable();
}
function renderAssessmentTeacherTable() {
    if (!assessmentTeacherTableBody) {
        return;
    }

    const totalEntries = assessmentTeacherTableFiltered.length;
    const totalPages = Math.max(1, Math.ceil(totalEntries / assessmentTeacherRowsPerPage));
    assessmentTeacherTableCurrentPage = Math.min(Math.max(1, assessmentTeacherTableCurrentPage), totalPages);

    const startIndex = (assessmentTeacherTableCurrentPage - 1) * assessmentTeacherRowsPerPage;
    const pageEntries = assessmentTeacherTableFiltered.slice(startIndex, startIndex + assessmentTeacherRowsPerPage);

    if (pageEntries.length) {
        assessmentTeacherTableBody.innerHTML = pageEntries.map(entry => {
            const pendingColor = entry.pending_total > 0 ? '#ef4444' : '#10b981';
            return `
                <tr>
                    <td style="font-weight: 600; color: #1f2937; text-align: left;">${escapeHtml(entry.name)}</td>
                    <td>${formatNumber(entry.quizzes_created)}</td>
                    <td>${formatNumber(entry.assignments_created)}</td>
                    <td>${formatNumber(entry.quiz_grading)}</td>
                    <td>${formatNumber(entry.assignment_attempts)}</td>
                    <td>${formatNumber(entry.assignment_grading)}</td>
                    <td>
                        <span style="display:inline-flex; align-items:center; justify-content:center; min-width:44px; padding:6px 14px; border-radius:999px; font-weight:700; background:${entry.pending_total > 0 ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)'}; color:${pendingColor};">
                            ${formatNumber(entry.pending_total)}
                        </span>
                    </td>
                </tr>
            `;
        }).join('');
    } else {
        assessmentTeacherTableBody.innerHTML = '';
    }

    if (assessmentTeacherEmptyState) {
        assessmentTeacherEmptyState.style.display = pageEntries.length ? 'none' : 'block';
    }

    if (assessmentTeacherPaginationInfo) {
        const startDisplay = totalEntries === 0 ? 0 : startIndex + 1;
        const endDisplay = totalEntries === 0 ? 0 : startIndex + pageEntries.length;
        assessmentTeacherPaginationInfo.textContent = `Showing ${startDisplay} to ${endDisplay} of ${totalEntries} teachers`;
    }

    updateAssessmentTeacherPaginationControls(totalPages);
}
function updateAssessmentTeacherPaginationControls(totalPages) {
    if (assessmentTeacherPrevBtn) {
        assessmentTeacherPrevBtn.disabled = assessmentTeacherTableCurrentPage <= 1;
    }
    if (assessmentTeacherNextBtn) {
        assessmentTeacherNextBtn.disabled = assessmentTeacherTableCurrentPage >= totalPages || totalPages === 0;
    }

    if (!assessmentTeacherPageNumbers) {
        return;
    }

    const maxButtons = 5;
    let startPage = Math.max(1, assessmentTeacherTableCurrentPage - Math.floor(maxButtons / 2));
    let endPage = startPage + maxButtons - 1;

    if (endPage > totalPages) {
        endPage = totalPages;
        startPage = Math.max(1, endPage - maxButtons + 1);
    }

    let buttonsHtml = '';
    for (let page = startPage; page <= endPage; page++) {
        const activeClass = page === assessmentTeacherTableCurrentPage ? 'active' : '';
        buttonsHtml += `<button type="button" class="${activeClass}" data-page="${page}">${page}</button>`;
    }
    assessmentTeacherPageNumbers.innerHTML = buttonsHtml;

    const pageButtons = assessmentTeacherPageNumbers.querySelectorAll('button[data-page]');
    pageButtons.forEach(button => {
        button.addEventListener('click', () => {
            const page = Number(button.getAttribute('data-page'));
            if (!Number.isNaN(page)) {
                assessmentTeacherTableCurrentPage = page;
                renderAssessmentTeacherTable();
            }
        });
    });

    const paginationButtons = assessmentTeacherPageNumbers.querySelectorAll('button');
    paginationButtons.forEach(btn => {
        btn.style.padding = '8px 12px';
        btn.style.borderRadius = '10px';
        btn.style.border = '1px solid #d1d5db';
        btn.style.background = btn.classList.contains('active') ? '#6366f1' : '#ffffff';
        btn.style.color = btn.classList.contains('active') ? '#ffffff' : '#1f2937';
        btn.style.fontWeight = '600';
        btn.style.cursor = 'pointer';
        if (!btn.classList.contains('active')) {
            btn.addEventListener('mouseover', () => btn.style.background = '#f1f5f9');
            btn.addEventListener('mouseout', () => btn.style.background = '#ffffff');
        }
    });
}
function renderTeacherSummary(detail) {
    const summaryContainer = document.getElementById('teacherDetailSummary');
    if (!summaryContainer) {
        return;
    }

    const totals = Object.assign({
        quizzes_created: 0,
        assignments_created: 0,
        quiz_attempts: 0,
        assignment_submissions: 0
    }, detail.assessment_totals || {});

    const totalAssessments = (totals.quizzes_created || 0) + (totals.assignments_created || 0);
    const totalGrading = (totals.quiz_attempts || 0) + (totals.assignment_submissions || 0);

    const summaryCards = [
        {
            label: 'Courses Assigned',
            value: formatNumber(detail.courses_taught || 0),
            note: 'Active courses',
            style: 'background: linear-gradient(135deg, #ede9fe, #e0f2fe);'
        },
        {
            label: 'Total Students',
            value: formatNumber(detail.total_students || 0),
            note: `${formatNumber(detail.completed_students || 0)} completed | ${formatNumber(detail.pending_students || 0)} pending`,
            style: 'background: linear-gradient(135deg, #d1fae5, #bbf7d0);'
        },
        {
            label: 'Completion Rate',
            value: formatPercent(detail.completion_rate ?? 0),
            note: 'Finished vs enrolled',
            style: 'background: linear-gradient(135deg, #fee2e2, #fef3c7);'
        },
        {
            label: 'Avg Course Grade',
            value: detail.avg_course_grade !== null && detail.avg_course_grade !== undefined ? formatPercent(detail.avg_course_grade, 'N/A') : 'N/A',
            note: 'Overall course average',
            style: 'background: linear-gradient(135deg, #dbeafe, #bfdbfe);'
        },
        {
            label: 'Avg Quiz Score',
            value: detail.avg_quiz_score !== null && detail.avg_quiz_score !== undefined ? formatPercent(detail.avg_quiz_score, 'N/A') : 'N/A',
            note: 'All graded quizzes',
            style: 'background: linear-gradient(135deg, #ede9fe, #ddd6fe);'
        },
        {
            label: 'Avg Assignment Grade',
            value: detail.avg_assignment_grade !== null && detail.avg_assignment_grade !== undefined ? formatPercent(detail.avg_assignment_grade, 'N/A') : 'N/A',
            note: 'Graded assignments',
            style: 'background: linear-gradient(135deg, #fbcfe8, #fde2e4);'
        },
        {
            label: 'Assessments Created',
            value: formatNumber(totalAssessments),
            note: `Quizzes ${formatNumber(totals.quizzes_created || 0)} | Assignments ${formatNumber(totals.assignments_created || 0)}`,
            style: 'background: linear-gradient(135deg, #e0f2f1, #c8e6c9);'
        },
        {
            label: 'Graded Submissions',
            value: formatNumber(totalGrading),
            note: `Quiz attempts ${formatNumber(totals.quiz_attempts || 0)} | Assignment grades ${formatNumber(totals.assignment_submissions || 0)}`,
            style: 'background: linear-gradient(135deg, #ffe4e6, #fecdd3);'
        }
    ];

    summaryContainer.innerHTML = summaryCards.map(card => `
        <div class="teacher-detail-summary-card" style="${card.style}">
            <span>${escapeHtml(card.label)}</span>
            <strong>${escapeHtml(card.value)}</strong>
            <small>${escapeHtml(card.note)}</small>
        </div>
    `).join('');
}

function renderTeacherCourseTable(detail) {
    const tableBody = document.getElementById('teacherCourseTableBody');
    const emptyState = document.getElementById('teacherDetailEmptyState');
    if (!tableBody) {
        return;
    }

    const courses = Array.isArray(detail.courses) ? detail.courses : [];
    if (!courses.length) {
        tableBody.innerHTML = '';
        if (emptyState) {
            emptyState.style.display = 'block';
        }
        return;
    }

    if (emptyState) {
        emptyState.style.display = 'none';
    }

    const rowsHtml = courses.map(course => {
        const completionRate = formatPercent(course.completion_rate ?? 0, 'N/A');
        const completionColor = getPerformanceColor(course.completion_rate ?? null);
        const courseGradeDisplay = course.avg_course_grade !== null && course.avg_course_grade !== undefined
            ? formatPercent(course.avg_course_grade, 'N/A')
            : 'N/A';
        const courseGradeColor = getPerformanceColor(course.avg_course_grade ?? null);
        const quizScoreDisplay = course.avg_quiz_score !== null && course.avg_quiz_score !== undefined
            ? formatPercent(course.avg_quiz_score, 'N/A')
            : 'N/A';
        const quizColor = getPerformanceColor(course.avg_quiz_score ?? null);
        const assignmentDisplay = course.avg_assignment_grade !== null && course.avg_assignment_grade !== undefined
            ? formatPercent(course.avg_assignment_grade, 'N/A')
            : 'N/A';
        const assignmentColor = getPerformanceColor(course.avg_assignment_grade ?? null);

        return `
            <tr>
                <td>${escapeHtml(course.name || 'Course')}</td>
                <td style="text-align: center;">${formatNumber(course.students || 0)}</td>
                <td style="text-align: center;">${formatNumber(course.completed || 0)}</td>
                <td style="text-align: center; font-weight: 600; color: ${completionColor};">${completionRate}</td>
                <td style="text-align: center; font-weight: 600; color: ${courseGradeColor};">${courseGradeDisplay}</td>
                <td style="text-align: center; font-weight: 600; color: ${quizColor};">${quizScoreDisplay}</td>
                <td style="text-align: center; font-weight: 600; color: ${assignmentColor};">${assignmentDisplay}</td>
                <td style="text-align: center;">${formatNumber(course.quizzes_created || 0)}</td>
                <td style="text-align: center;">${formatNumber(course.assignments_created || 0)}</td>
                <td style="text-align: center;">${formatNumber(course.quiz_attempts || 0)}</td>
                <td style="text-align: center;">${formatNumber(course.assignment_submissions || 0)}</td>
            </tr>
        `;
    }).join('');

    tableBody.innerHTML = rowsHtml;
}
function renderTeacherCourseChart(detail) {
    const canvas = document.getElementById('teacherCoursePerformanceChart');
    const helper = document.getElementById('teacherCourseChartHelper');
    if (!canvas || !helper) {
        return;
    }

    if (teacherDetailCourseChart) {
        teacherDetailCourseChart.destroy();
        teacherDetailCourseChart = null;
    }

    const courses = Array.isArray(detail.courses) ? detail.courses : [];
    if (!courses.length) {
        helper.textContent = 'No course performance data available for this teacher yet.';
        canvas.style.display = 'none';
        return;
    }

    helper.textContent = teacherCourseHelperDefault;
    canvas.style.display = 'block';

    const labels = courses.map(course => typeof course.name === 'string' ? course.name : 'Course');
    const completion = courses.map(course => Number(course.completion_rate || 0));
    const courseGrades = courses.map(course =>
        course.avg_course_grade !== null && course.avg_course_grade !== undefined ? Number(course.avg_course_grade) : null
    );
    const quizScores = courses.map(course =>
        course.avg_quiz_score !== null && course.avg_quiz_score !== undefined ? Number(course.avg_quiz_score) : null
    );
    const assignmentGrades = courses.map(course =>
        course.avg_assignment_grade !== null && course.avg_assignment_grade !== undefined ? Number(course.avg_assignment_grade) : null
    );

    const datasets = [{
        label: 'Completion %',
        data: completion,
        backgroundColor: '#10b981',
        borderRadius: 6,
        borderSkipped: false
    }];

    if (courseGrades.some(value => value !== null && Number.isFinite(value))) {
        datasets.push({
            label: 'Course Grade %',
            data: courseGrades.map(value => value !== null ? value : null),
            backgroundColor: '#3b82f6',
            borderRadius: 6,
            borderSkipped: false
        });
    }

    if (quizScores.some(value => value !== null && Number.isFinite(value))) {
        datasets.push({
            label: 'Quiz Score %',
            data: quizScores.map(value => value !== null ? value : null),
            backgroundColor: '#6366f1',
            borderRadius: 6,
            borderSkipped: false
        });
    }

    if (assignmentGrades.some(value => value !== null && Number.isFinite(value))) {
        datasets.push({
            label: 'Assignment Grade %',
            data: assignmentGrades.map(value => value !== null ? value : null),
            backgroundColor: '#f97316',
            borderRadius: 6,
            borderSkipped: false
        });
    }

    teacherDetailCourseChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: { color: '#e2e8f0' },
                    ticks: { stepSize: 20, color: '#475569', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#334155',
                        font: { size: 11 },
                        autoSkip: false,
                        maxRotation: 36,
                        minRotation: 36
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed?.y;
                            if (value === null || value === undefined) {
                                return `${context.dataset.label}: N/A`;
                            }
                            return `${context.dataset.label}: ${Number(value).toFixed(1)}%`;
                        }
                    }
                }
            }
        }
    });
}
function renderTeacherAssessmentChart(detail) {
    const canvas = document.getElementById('teacherAssessmentChart');
    const helper = document.getElementById('teacherAssessmentLegend');
    if (!canvas || !helper) {
        return;
    }

    if (teacherDetailAssessmentChart) {
        teacherDetailAssessmentChart.destroy();
        teacherDetailAssessmentChart = null;
    }

    const totals = Object.assign({
        quizzes_created: 0,
        assignments_created: 0,
        quiz_attempts: 0,
        assignment_submissions: 0
    }, detail.assessment_totals || {});

    const chartValues = [
        totals.quizzes_created || 0,
        totals.assignments_created || 0,
        totals.quiz_attempts || 0,
        totals.assignment_submissions || 0
    ];

    const hasData = chartValues.some(value => Number(value) > 0);

    if (!hasData) {
        helper.textContent = 'No assessments created or graded for this teacher yet.';
        canvas.style.display = 'none';
        return;
    }

    helper.textContent = `Quizzes: ${formatNumber(totals.quizzes_created || 0)} | Assignments: ${formatNumber(totals.assignments_created || 0)} | Quiz attempts graded: ${formatNumber(totals.quiz_attempts || 0)} | Assignment grades: ${formatNumber(totals.assignment_submissions || 0)}`;
    canvas.style.display = 'block';

    teacherDetailAssessmentChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Quizzes Created', 'Assignments Created', 'Quiz Attempts Graded', 'Assignment Grades'],
            datasets: [{
                data: chartValues,
                backgroundColor: ['#6366f1', '#22c55e', '#f97316', '#14b8a6'],
                borderWidth: 3,
                borderColor: '#ffffff',
                cutout: '55%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            return `${label}: ${formatNumber(value)}`;
                        }
                    }
                }
            }
        }
    });
}

function renderTeacherDetail(teacherId) {
    const detail = teacherDetailMap ? teacherDetailMap[teacherId] : null;
    const panel = document.getElementById('teacherDetailPanel');
    const overlay = document.getElementById('teacherDetailOverlay');
    if (!detail || !panel || !overlay) {
        return;
    }

    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }

    positionTeacherDetailOverlay();
    
    setTimeout(() => {
        positionTeacherDetailOverlay();
    }, 50);
    
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    overlay.scrollTop = 0;
    panel.style.display = 'block';
    panel.classList.add('active');

    const nameEl = document.getElementById('teacherDetailName');
    const subtitleEl = document.getElementById('teacherDetailSubtitle');
    const subtitleParts = [];

    subtitleParts.push(`${formatNumber(detail.courses_taught || 0)} course${(detail.courses_taught || 0) === 1 ? '' : 's'}`);
    subtitleParts.push(`${formatNumber(detail.total_students || 0)} student${(detail.total_students || 0) === 1 ? '' : 's'}`);
    subtitleParts.push(`${formatPercent(detail.completion_rate ?? 0)} completion`);

    setText(nameEl, detail.name || 'Teacher Detail');
    setText(subtitleEl, subtitleParts.join(' | '));

    renderTeacherSummary(detail);
    renderTeacherCourseTable(detail);
    renderTeacherCourseChart(detail);
    renderTeacherAssessmentChart(detail);

    const resetBtn = document.getElementById('teacherDetailReset');
    if (resetBtn) {
        resetBtn.style.display = 'inline-flex';
    }

}
function clearTeacherSelection() {
    if (selectedTeacherRowElement) {
        selectedTeacherRowElement.classList.remove('selected-teacher-row');
        selectedTeacherRowElement = null;
    }
    selectedTeacherId = null;
}

function hideTeacherDetail() {
    const panel = document.getElementById('teacherDetailPanel');
    if (panel) {
        panel.style.display = 'none';
        panel.classList.remove('active');
    }

    const overlay = document.getElementById('teacherDetailOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        overlay.style.left = '';
        overlay.style.width = '';
        overlay.style.top = '';
        overlay.style.height = '';
    }

    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }

    clearTeacherSelection();

    if (teacherDetailCourseChart) {
        teacherDetailCourseChart.destroy();
        teacherDetailCourseChart = null;
    }
    if (teacherDetailAssessmentChart) {
        teacherDetailAssessmentChart.destroy();
        teacherDetailAssessmentChart = null;
    }

    const summaryContainer = document.getElementById('teacherDetailSummary');
    if (summaryContainer) {
        summaryContainer.innerHTML = '';
    }

    const tableBody = document.getElementById('teacherCourseTableBody');
    if (tableBody) {
        tableBody.innerHTML = '';
    }

    const emptyState = document.getElementById('teacherDetailEmptyState');
    if (emptyState) {
        emptyState.style.display = 'none';
    }

    const nameEl = document.getElementById('teacherDetailName');
    const subtitleEl = document.getElementById('teacherDetailSubtitle');
    setText(nameEl, '');
    setText(subtitleEl, '');

    const courseHelper = document.getElementById('teacherCourseChartHelper');
    if (courseHelper) {
        courseHelper.textContent = teacherCourseHelperDefault;
    }

    const assessmentHelper = document.getElementById('teacherAssessmentLegend');
    if (assessmentHelper) {
        assessmentHelper.textContent = teacherAssessmentHelperDefault;
    }

    const courseCanvas = document.getElementById('teacherCoursePerformanceChart');
    if (courseCanvas) {
        courseCanvas.style.display = 'none';
    }

    const assessmentCanvas = document.getElementById('teacherAssessmentChart');
    if (assessmentCanvas) {
        assessmentCanvas.style.display = 'none';
    }

    const resetBtn = document.getElementById('teacherDetailReset');
    if (resetBtn) {
        resetBtn.style.display = 'none';
    }
}

function selectTeacherRow(row, teacherId) {
    if (!row || !teacherId || !teacherDetailMap[teacherId]) {
        return;
    }

    if (selectedTeacherRowElement) {
        selectedTeacherRowElement.classList.remove('selected-teacher-row');
    }

    selectedTeacherRowElement = row;
    selectedTeacherId = teacherId;
    row.classList.add('selected-teacher-row');

    renderTeacherDetail(teacherId);
}

function initTeacherDetailInteractions() {
    const rows = document.querySelectorAll('.teacher-row');
    rows.forEach(row => {
        row.addEventListener('click', () => {
            const teacherId = row.getAttribute('data-teacher-id');
            if (!teacherId) {
                return;
            }
            if (selectedTeacherId === teacherId && row.classList.contains('selected-teacher-row')) {
                return;
            }
            selectTeacherRow(row, teacherId);
        });
    });

    const resetBtn = document.getElementById('teacherDetailReset');
    if (resetBtn) {
        resetBtn.addEventListener('click', hideTeacherDetail);
    }

    const overlay = document.getElementById('teacherDetailOverlay');
    if (overlay) {
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                hideTeacherDetail();
            }
        });
    }

    const detailPanel = document.getElementById('teacherDetailPanel');
    if (detailPanel) {
        detailPanel.addEventListener('click', (event) => event.stopPropagation());
    }

    if (!teacherDetailOverlayResizeBound) {
        window.addEventListener('resize', positionTeacherDetailOverlay);
        teacherDetailOverlayResizeBound = true;
    }

    if (!document.body.dataset.teacherDetailEscapeBound) {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideTeacherDetail();
            }
        });
        document.body.dataset.teacherDetailEscapeBound = 'true';
    }

    positionTeacherDetailOverlay();
    hideTeacherDetail();
}

function initTeacherOverviewTable() {
    if (teacherOverviewTableInitialized) {
        renderTeacherOverviewTable();
        return;
    }

    teacherOverviewTableBody = document.getElementById('teacherOverviewTableBody');
    if (!teacherOverviewTableBody) {
        return;
    }
    teacherOverviewTableBody.addEventListener('click', handleTeacherOverviewRowClick);

    teacherOverviewEmptyState = document.getElementById('teacherOverviewEmptyState');
    teacherOverviewPaginationInfo = document.getElementById('teacherOverviewPaginationInfo');
    teacherOverviewSearchInput = document.getElementById('teacherOverviewSearch');
    teacherOverviewSearchClearBtn = document.getElementById('teacherOverviewSearchClear');
    teacherOverviewPrevBtn = document.getElementById('teacherOverviewPrev');
    teacherOverviewNextBtn = document.getElementById('teacherOverviewNext');
    teacherOverviewPageNumbers = document.getElementById('teacherOverviewPageNumbers');

    teacherOverviewData = Array.isArray(teacherOverviewDataset)
        ? teacherOverviewDataset.map(entry => ({
            id: Number(entry.id || 0),
            name: entry.name || '',
            courses: Number(entry.courses || 0),
            students: Number(entry.students || 0)
        }))
        : [];
    teacherOverviewFiltered = [...teacherOverviewData];
    teacherOverviewCurrentPage = 1;

    if (teacherOverviewSearchInput) {
        teacherOverviewSearchInput.addEventListener('input', handleTeacherOverviewSearch);
    }
    if (teacherOverviewSearchClearBtn) {
        teacherOverviewSearchClearBtn.addEventListener('click', () => {
            if (teacherOverviewSearchInput) {
                teacherOverviewSearchInput.value = '';
                teacherOverviewSearchInput.focus();
            }
            handleTeacherOverviewSearch();
        });
    }
    if (teacherOverviewPrevBtn) {
        teacherOverviewPrevBtn.addEventListener('click', () => {
            if (teacherOverviewCurrentPage > 1) {
                teacherOverviewCurrentPage--;
                renderTeacherOverviewTable();
            }
        });
    }
    if (teacherOverviewNextBtn) {
        teacherOverviewNextBtn.addEventListener('click', () => {
            const totalPages = Math.max(1, Math.ceil(teacherOverviewFiltered.length / teacherOverviewRowsPerPage));
            if (teacherOverviewCurrentPage < totalPages) {
                teacherOverviewCurrentPage++;
                renderTeacherOverviewTable();
            }
        });
    }

    teacherOverviewTableInitialized = true;
    renderTeacherOverviewTable();
}

function handleTeacherOverviewSearch() {
    const query = (teacherOverviewSearchInput?.value || '').trim().toLowerCase();
    if (!query) {
        teacherOverviewFiltered = [...teacherOverviewData];
    } else {
        teacherOverviewFiltered = teacherOverviewData.filter(entry =>
            entry.name.toLowerCase().includes(query)
        );
    }
    teacherOverviewCurrentPage = 1;
    renderTeacherOverviewTable();
}

function handleTeacherOverviewRowClick(event) {
    const row = event.target.closest('.teacher-overview-row');
    if (!row) {
        return;
    }
    
    // If clicking on the link, let it navigate normally
    if (event.target.closest('a.teacher-name-link')) {
        return;
    }
    
    // Get teacher ID from the row and navigate to courses page
    const teacherId = row.dataset.teacherId || row.getAttribute('data-teacher-id');
    if (!teacherId) {
        // Fallback: try to extract from detail URL
    const url = row.dataset.detailUrl;
        if (url) {
            const match = url.match(/teacherid=(\d+)/);
            if (match && match[1]) {
                const coursesUrl = '<?php echo (new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php'))->out(false); ?>' + '?teacherid=' + encodeURIComponent(match[1]);
                window.location.href = coursesUrl;
        return;
    }
        }
        console.warn('Could not find teacher ID for row');
        return;
    }
    
    // Navigate to the courses page
    const coursesUrl = '<?php echo (new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php'))->out(false); ?>' + '?teacherid=' + encodeURIComponent(teacherId);
    window.location.href = coursesUrl;
}

function openTeacherCourseModal(teacherId) {
    const modal = document.getElementById('teacherCourseModal');
    const loading = document.getElementById('teacherCourseModalLoading');
    const content = document.getElementById('teacherCourseModalContent');
    const empty = document.getElementById('teacherCourseModalEmpty');
    const error = document.getElementById('teacherCourseModalError');
    const tableBody = document.getElementById('teacherCourseTableBody');
    const modalTitle = document.getElementById('teacherCourseModalTitle');
    
    if (!modal) {
        console.error('Teacher course modal not found');
        return;
    }
    
    if (!teacherId || isNaN(teacherId)) {
        console.error('Invalid teacher ID:', teacherId);
        return;
    }
    
    // Show modal and loading state
    modal.style.display = 'flex';
    loading.style.display = 'block';
    content.style.display = 'none';
    empty.style.display = 'none';
    error.style.display = 'none';
    tableBody.innerHTML = '';
    
    // Fetch teacher courses from the endpoint
    const baseUrl = '<?php echo $CFG->wwwroot; ?>';
    const endpointUrl = baseUrl + '/theme/remui_kids/school_manager/teacher_report_get_courses.php';
    const fullUrl = endpointUrl + '?teacherid=' + encodeURIComponent(teacherId);
    
    console.log('Fetching courses for teacher:', teacherId, 'from:', fullUrl);
    
    fetch(fullUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
        },
        credentials: 'same-origin'
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            loading.style.display = 'none';
            
            if (data.error) {
                error.style.display = 'block';
                const errorMsgEl = document.getElementById('teacherCourseModalErrorMessage');
                if (errorMsgEl) {
                    errorMsgEl.textContent = data.error;
                }
                console.error('API Error:', data.error);
                return;
            }
            
            if (data.success && data.teacher) {
                modalTitle.textContent = 'Courses - ' + data.teacher.name;
            }
            
            if (data.courses && Array.isArray(data.courses) && data.courses.length > 0) {
                content.style.display = 'block';
                tableBody.innerHTML = data.courses.map(course => `
                    <tr>
                        <td style="font-weight: 600; color: #1f2937;">${escapeHtml(course.name || 'Unnamed Course')}</td>
                        <td style="text-align: center;">
                            <span class="count-pill success">${formatNumber(course.total_students || 0)}</span>
                        </td>
                    </tr>
                `).join('');
            } else {
                empty.style.display = 'block';
            }
        })
        .catch(err => {
            loading.style.display = 'none';
            error.style.display = 'block';
            const errorMsgEl = document.getElementById('teacherCourseModalErrorMessage');
            if (errorMsgEl) {
                errorMsgEl.textContent = 'Failed to load courses. Please try again.';
            }
            console.error('Error fetching teacher courses:', err);
        });
}

function closeTeacherCourseModal() {
    const modal = document.getElementById('teacherCourseModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTeacherCourseModal();
    }
});

function renderTeacherOverviewTable() {
    if (!teacherOverviewTableBody) {
        return;
    }

    const totalEntries = teacherOverviewFiltered.length;
    const totalPages = totalEntries > 0 ? Math.ceil(totalEntries / teacherOverviewRowsPerPage) : 1;
    teacherOverviewCurrentPage = Math.min(Math.max(1, teacherOverviewCurrentPage), totalPages);
    const startIndex = (teacherOverviewCurrentPage - 1) * teacherOverviewRowsPerPage;
    const pageEntries = totalEntries > 0
        ? teacherOverviewFiltered.slice(startIndex, startIndex + teacherOverviewRowsPerPage)
        : [];

    if (pageEntries.length) {
        teacherOverviewTableBody.innerHTML = pageEntries.map(entry => {
            const initials = getInitialsFromName(entry.name);
        const detailUrl = entry.id
                ? `${teacherOverviewDetailUrlBase}?teacherid=${encodeURIComponent(entry.id)}`
                : '#';
            // Create URL for teacher courses page
            const coursesUrl = '<?php echo (new moodle_url('/theme/remui_kids/school_manager/teacher_report_teacher_courses.php'))->out(false); ?>' + '?teacherid=' + encodeURIComponent(entry.id || 0);
            
            return `
                <tr class="teacher-overview-row" data-detail-url="${detailUrl}" data-teacher-id="${entry.id || ''}">
                    <td>
                        <a class="teacher-name-link" href="${coursesUrl}" onclick="event.stopPropagation();">
                            <div class="teacher-name-cell">
                                <span class="teacher-avatar">${initials}</span>
                                <div>
                                    <div>${escapeHtml(entry.name)}</div>
                                    <small style="color:#64748b; font-size:0.8rem;">Active Teacher</small>
                                </div>
                            </div>
                        </a>
                    </td>
                    <td style="text-align:center;">
                        <span class="count-pill">${formatNumber(entry.courses)}</span>
                    </td>
                    <td style="text-align:center;">
                        <span class="count-pill success">${formatNumber(entry.students)}</span>
                    </td>
                </tr>
            `;
        }).join('');
    } else {
        teacherOverviewTableBody.innerHTML = '';
    }

    if (teacherOverviewEmptyState) {
        teacherOverviewEmptyState.style.display = pageEntries.length ? 'none' : 'block';
    }

    if (teacherOverviewPaginationInfo) {
        const startDisplay = totalEntries === 0 ? 0 : startIndex + 1;
        const endDisplay = totalEntries === 0 ? 0 : startIndex + pageEntries.length;
        teacherOverviewPaginationInfo.textContent = `Showing ${startDisplay} - ${endDisplay} of ${totalEntries} teachers`;
    }

    updateTeacherOverviewPaginationControls(totalPages, totalEntries > 0);
}

function updateTeacherOverviewPaginationControls(totalPages, hasEntries) {
    if (teacherOverviewPrevBtn) {
        teacherOverviewPrevBtn.disabled = !hasEntries || teacherOverviewCurrentPage <= 1;
    }
    if (teacherOverviewNextBtn) {
        teacherOverviewNextBtn.disabled = !hasEntries || teacherOverviewCurrentPage >= totalPages;
    }

    if (!teacherOverviewPageNumbers) {
        return;
    }

    if (!hasEntries) {
        teacherOverviewPageNumbers.innerHTML = '';
        return;
    }

    const maxButtons = 5;
    let startPage = Math.max(1, teacherOverviewCurrentPage - Math.floor(maxButtons / 2));
    let endPage = startPage + maxButtons - 1;

    if (endPage > totalPages) {
        endPage = totalPages;
        startPage = Math.max(1, endPage - maxButtons + 1);
    }

    let buttonsHtml = '';
    for (let page = startPage; page <= endPage; page++) {
        const activeClass = page === teacherOverviewCurrentPage ? 'active' : '';
        buttonsHtml += `<button type="button" class="${activeClass}" data-overview-page="${page}">${page}</button>`;
    }
    teacherOverviewPageNumbers.innerHTML = buttonsHtml;

    const pageButtons = teacherOverviewPageNumbers.querySelectorAll('button[data-overview-page]');
    pageButtons.forEach(button => {
        button.addEventListener('click', () => {
            const page = Number(button.getAttribute('data-overview-page'));
            if (!Number.isNaN(page) && page !== teacherOverviewCurrentPage) {
                teacherOverviewCurrentPage = page;
                renderTeacherOverviewTable();
            }
        });
    });
}
function renderTeacherActivityLogCharts(forceRender = false) {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js unavailable for activity log charts');
        return;
    }
    if (!teacherActivityLogConfig) {
        return;
    }

    // Daily Login Trend Chart (Line Chart)
    const trendConfig = teacherActivityLogConfig.daily_login_trend || {};
    const trendCanvas = document.getElementById('activityLogTrendChart');
    if (trendCanvas) {
        if (activityLogTrendChartInstance) {
            if (forceRender) {
                activityLogTrendChartInstance.destroy();
                activityLogTrendChartInstance = null;
            } else {
                activityLogTrendChartInstance.resize();
            }
        }

        if ((!activityLogTrendChartInstance || forceRender) && Array.isArray(trendConfig.labels) && trendConfig.labels.length) {
            activityLogTrendChartInstance = new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: trendConfig.labels,
                    datasets: [{
                        label: 'Teacher Logins',
                        data: (trendConfig.teacher_logins || []).map(value => Number(value) || 0),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 4,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        x: {
                            ticks: { 
                                color: '#334155', 
                                font: { size: 11 },
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: { 
                                color: '#e2e8f0',
                                display: true
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { 
                                color: '#475569', 
                                font: { size: 11 },
                                stepSize: 1
                            },
                            grid: { 
                                color: '#e2e8f0'
                            },
                            title: {
                                display: true,
                                text: 'Number of Logins',
                                color: '#475569',
                                font: { size: 12, weight: '600' }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: { 
                                font: { size: 11 },
                                usePointStyle: true,
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 13, weight: '600' },
                            bodyFont: { size: 12 },
                            callbacks: {
                                label: function(context) {
                                    const label = context.dataset.label || '';
                                    const value = context.parsed.y;
                                    return label + ': ' + value + ' logins';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    const contentConfig = teacherActivityLogConfig.content_chart || {};
    const contentCanvas = document.getElementById('activityLogContentChart');
    if (contentCanvas) {
        if (activityLogContentChartInstance) {
            if (forceRender) {
                activityLogContentChartInstance.destroy();
                activityLogContentChartInstance = null;
            } else {
                activityLogContentChartInstance.resize();
            }
        }

        if ((!activityLogContentChartInstance || forceRender) && Array.isArray(contentConfig.labels) && contentConfig.labels.length) {
            activityLogContentChartInstance = new Chart(contentCanvas, {
                type: 'bar',
                data: {
                    labels: contentConfig.labels,
                    datasets: [{
                        label: 'Content Updates',
                        data: (contentConfig.updates || []).map(value => Number(value) || 0),
                        backgroundColor: '#f59e0b',
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            ticks: { color: '#334155', font: { size: 11 } },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#475569', font: { size: 11 } },
                            grid: { color: '#e2e8f0' }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
    }

    const messagesConfig = teacherActivityLogConfig.messages_chart || {};
    const messagesCanvas = document.getElementById('activityLogMessagesChart');
    if (messagesCanvas) {
        if (activityLogMessagesChartInstance) {
            if (forceRender) {
                activityLogMessagesChartInstance.destroy();
                activityLogMessagesChartInstance = null;
            } else {
                activityLogMessagesChartInstance.resize();
            }
        }

        if ((!activityLogMessagesChartInstance || forceRender) && Array.isArray(messagesConfig.labels) && messagesConfig.labels.length) {
            activityLogMessagesChartInstance = new Chart(messagesCanvas, {
                type: 'doughnut',
                data: {
                    labels: messagesConfig.labels,
                    datasets: [{
                        data: (messagesConfig.counts || []).map(value => Number(value) || 0),
                        backgroundColor: ['#6366f1', '#34d399', '#f59e0b', '#0ea5e9', '#ef4444', '#8b5cf6', '#14b8a6'],
                        borderWidth: 4,
                        borderColor: '#ffffff',
                        cutout: '60%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: { font: { size: 11 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    return `${label}: ${Number(value).toLocaleString()} messages`;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    initializeTeacherActivityTable(forceRender);

    teacherActivityLogChartsRendered = true;
}
function renderAssessmentCharts(forceRender = false) {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js unavailable for assessment charts');
        return;
    }
    if (!assessmentDashboardConfig) {
        return;
    }

    const activityCanvas = document.getElementById('assessmentActivityChart');
    const activityLabels = (assessmentDashboardConfig.teacher_activity && assessmentDashboardConfig.teacher_activity.labels) || [];
    if (activityCanvas) {
        if (assessmentActivityChartInstance) {
            if (forceRender) {
                assessmentActivityChartInstance.destroy();
                assessmentActivityChartInstance = null;
            } else {
                assessmentActivityChartInstance.resize();
            }
        }

        if ((!assessmentActivityChartInstance || forceRender) && activityLabels.length) {
            const quizzesData = (assessmentDashboardConfig.teacher_activity.quizzes || []).map(value => Number(value) || 0);
            const assignmentsData = (assessmentDashboardConfig.teacher_activity.assignments || []).map(value => Number(value) || 0);

            assessmentActivityChartInstance = new Chart(activityCanvas, {
                type: 'bar',
                data: {
                    labels: activityLabels,
                    datasets: [
                        {
                            label: 'Quizzes Created',
                            data: quizzesData,
                            backgroundColor: '#6366f1',
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'Assignments Created',
                            data: assignmentsData,
                            backgroundColor: '#10b981',
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            ticks: { color: '#334155', font: { size: 11 } },
                            grid: { display: false }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: { color: '#475569', font: { size: 11 } },
                            grid: { color: '#e2e8f0' }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: { font: { size: 11 } }
                        }
                    }
                }
            });
        }
    }

    assessmentChartsRendered = true;
}

window.downloadTeacherReport = function() {
    const formatSelect = document.getElementById('teacherDownloadFormat');
    const format = formatSelect ? formatSelect.value : 'excel';
    const activeTab = teacherActiveTab || '<?php echo $initial_tab; ?>' || 'summary';
    const baseUrl = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/teacher_report_download.php';
    const downloadUrl = baseUrl + '?tab=' + encodeURIComponent(activeTab) + '&format=' + encodeURIComponent(format || 'excel');
    window.location.href = downloadUrl;
};
document.addEventListener('DOMContentLoaded', function() {
    // Teacher Load Chart
    <?php if ($teacher_load_distribution['total'] > 0): ?>
    const teacherLoadCtx = document.getElementById('teacherLoadChart');
    if (teacherLoadCtx) {
        const teacherLoadData = {
            values: [
                        <?php echo $teacher_load_distribution['no_load']; ?>,
                        <?php echo $teacher_load_distribution['low_load']; ?>,
                        <?php echo $teacher_load_distribution['medium_load']; ?>,
                        <?php echo $teacher_load_distribution['high_load']; ?>
                    ],
            labels: ['0 Courses', '1-2 Courses', '3-5 Courses', 'More than 5 Courses'],
            total: <?php echo $teacher_load_distribution['total']; ?>
        };

        const teacherLoadPrimaryColors = ['#312E81', '#8B5CF6', '#38BDF8', '#FB7185'];

        const teacherLoadCenterText = {
            id: 'teacherLoadCenterText',
            afterDraw(chart) {
                const {ctx, chartArea: {width, height, left, top}} = chart;
                const total = teacherLoadData.total || 0;
                const percentageActive = total > 0 ? ((total - teacherLoadData.values[0]) / total) * 100 : 0;

                ctx.save();
                ctx.font = '700 30px "Inter", sans-serif';
                ctx.fillStyle = '#1f2937';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(total, left + width / 2, top + height / 2 - 10);

                ctx.font = '600 13px "Inter", sans-serif';
                ctx.fillStyle = '#6b7280';
                ctx.fillText('Total Teachers', left + width / 2, top + height / 2 + 10);

                ctx.font = '600 12px "Inter", sans-serif';
                ctx.fillStyle = '#4338ca';
                ctx.fillText(`${percentageActive.toFixed(1)}% Assigned`, left + width / 2, top + height / 2 + 28);
                ctx.restore();
            }
        };

        const teacherLoadLeaderLines = {
            id: 'teacherLoadLeaderLines',
            afterDatasetsDraw(chart) {
                const {ctx} = chart;
                const dataset = chart.data.datasets[0];
                const meta = chart.getDatasetMeta(0);
                const total = teacherLoadData.total || 0;

                meta.data.forEach((arc, index) => {
                    const value = teacherLoadData.values[index];
                    if (!value || value <= 0) {
                        return;
                    }

                    const props = arc.getProps(['startAngle', 'endAngle', 'outerRadius', 'x', 'y'], true);
                    const angle = (props.startAngle + props.endAngle) / 2;
                    const startRadius = props.outerRadius + 14;
                    const elbowRadius = props.outerRadius + 40;
                    const endRadius = props.outerRadius + 60;

                    const startX = props.x + Math.cos(angle) * startRadius;
                    const startY = props.y + Math.sin(angle) * startRadius;
                    const elbowX = props.x + Math.cos(angle) * elbowRadius;
                    const elbowY = props.y + Math.sin(angle) * elbowRadius;

                    const isRight = Math.cos(angle) >= 0;
                    const endX = props.x + Math.cos(angle) * endRadius + (isRight ? 34 : -34);
                    const endY = props.y + Math.sin(angle) * endRadius;

                    ctx.save();
                    ctx.strokeStyle = dataset.segmentStrokeColor?.[index] || dataset.backgroundColor[index];
                    ctx.lineWidth = 2;
                    ctx.lineJoin = 'round';
                    ctx.beginPath();
                    ctx.moveTo(startX, startY);
                    ctx.lineTo(elbowX, elbowY);
                    ctx.lineTo(endX, endY);
                    ctx.stroke();

                    const percentage = total > 0 ? ((value / total) * 100).toFixed(0) : '0';
                    ctx.fillStyle = '#1f2937';
                    ctx.font = '600 12px "Inter", sans-serif';
                    ctx.textAlign = isRight ? 'left' : 'right';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(`${percentage}%`, endX + (isRight ? 4 : -4), endY);
                    ctx.restore();
                });
            }
        };
        new Chart(teacherLoadCtx, {
            type: 'doughnut',
            plugins: [teacherLoadCenterText, teacherLoadLeaderLines],
            data: {
                labels: teacherLoadData.labels,
                datasets: [
                    {
                        label: 'Teacher Distribution',
                        data: teacherLoadData.values,
                        backgroundColor: teacherLoadPrimaryColors,
                        borderWidth: 6,
                        borderColor: '#ffffff',
                        hoverOffset: 10,
                        cutout: '68%',
                        rotation: -90,
                        circumference: 360,
                        segmentStrokeColor: ['#9CA3FF', '#6EE7B7', '#FCD34D', '#FCA5A5']
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    delay: 180,
                    animateRotate: true,
                    animateScale: true,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 12,
                            padding: 16,
                            font: {
                                size: 13,
                                family: 'Inter',
                                weight: '600'
                            },
                            color: '#475569'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.92)',
                        padding: 12,
                        borderRadius: 12,
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        borderWidth: 0,
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed;
                                const total = teacherLoadData.total || 0;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0';
                                return `${context.label}: ${value} teachers (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
    // Teacher Performance Chart
    <?php if (!empty($teacher_performance_data)): ?>
    const teacherPerformanceCanvas = document.getElementById('teacherPerformanceChart');
    const teacherPerformanceContainer = document.querySelector('[data-scrollable="teacher-performance"]');
    const teacherPerformanceSearchInput = document.getElementById('teacherPerformanceSearch');
    const teacherPerformanceSearchClear = document.getElementById('teacherPerformanceSearchClear');
    let teacherPerformanceChart = null;

    if (teacherPerformanceCanvas) {
        const teacherNames = <?php echo json_encode(array_column($teacher_performance_data, 'name')); ?>;
        const performanceScores = <?php echo json_encode(array_column($teacher_performance_data, 'performance_score')); ?>;
        const teacherPerformanceDetails = <?php echo json_encode($teacher_performance_data); ?>;

        const palette = ['#7C91FF', '#B09BFF', '#6CC8FF', '#FF9FB6', '#73D5FF', '#93ECB9', '#FFC27A', '#FFAECE'];
        const baseBackgroundColors = teacherNames.map((_, index) => palette[index % palette.length]);
        const baseBorderColors = baseBackgroundColors.map(color => darkenColor(color, 0.12));
        const baseBorderWidths = baseBackgroundColors.map(() => 2);
        const hoverBackgroundColors = baseBackgroundColors.map(color => lightenColor(color, 0.22));
        const hoverBorderColors = baseBackgroundColors.map(color => darkenColor(color, 0.2));

        const ctx = teacherPerformanceCanvas.getContext('2d');
        teacherPerformanceChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: teacherNames,
                datasets: [{
                    label: 'Performance Score',
                    data: performanceScores,
                    backgroundColor: [...baseBackgroundColors],
                    borderColor: [...baseBorderColors],
                    borderWidth: [...baseBorderWidths],
                    hoverBackgroundColor: hoverBackgroundColors,
                    hoverBorderColor: hoverBorderColors,
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness: 50,
                    barPercentage: 0.55,
                    categoryPercentage: 0.65
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 650,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        borderColor: '#1f2937',
                        borderWidth: 1,
                        padding: 14,
                        cornerRadius: 10,
                        titleFont: { family: 'Inter', weight: '600', size: 13 },
                        bodyFont: { family: 'Inter', size: 12 },
                        callbacks: {
                            label: function(context) {
                                const index = context.dataIndex;
                                const teacher = teacherPerformanceDetails[index];
                                return [
                                    `Score: ${context.parsed.y}`,
                                    `Courses: ${teacher.courses_taught}`,
                                    `Students: ${teacher.total_students}`,
                                    `Completion: ${teacher.completion_rate}%`,
                                    `Avg Grade: ${teacher.avg_student_grade}%`,
                                    `Avg Quiz: ${teacher.avg_quiz_score}%`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 36,
                            minRotation: 36,
                            color: '#1f2937',
                            font: { size: 13, weight: '600' }
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            color: '#6b7280',
                            font: { size: 12 }
                        },
                        grid: {
                            color: '#edf2f7',
                            drawBorder: false
                        }
                    }
                },
                layout: {
                    padding: { top: 10, bottom: 40, left: 15, right: 15 }
                }
            }
        });

        enableDragScroll(teacherPerformanceContainer);

        const resetTeacherHighlight = (shouldUpdate = true) => {
            const dataset = teacherPerformanceChart.data.datasets[0];
            dataset.backgroundColor = [...baseBackgroundColors];
            dataset.borderColor = [...baseBorderColors];
            dataset.borderWidth = [...baseBorderWidths];
            if (shouldUpdate) {
                teacherPerformanceChart.update('none');
            }
        };

        const scrollTeacherIntoView = (index) => {
            if (!teacherPerformanceChart || !teacherPerformanceContainer) {
                return;
            }
            const elements = teacherPerformanceChart.getDatasetMeta(0).data;
            const element = elements[index];
            if (!element) return;
            const target = Math.max(0, element.x - teacherPerformanceContainer.clientWidth / 2);
            teacherPerformanceContainer.scrollTo({ left: target, behavior: 'smooth' });
        };

        const highlightTeacherBar = (index, options = {}) => {
            if (!teacherPerformanceChart) return;
            if (index === null || index < 0 || index >= teacherNames.length) {
                resetTeacherHighlight(options.update !== false);
                return;
            }

            const dataset = teacherPerformanceChart.data.datasets[0];
            resetTeacherHighlight(false);

            dataset.backgroundColor[index] = lightenColor(baseBackgroundColors[index], 0.45);
            dataset.borderColor[index] = '#ef4444';
            dataset.borderWidth[index] = 5;

            teacherPerformanceChart.update('none');

            if (options.scroll !== false) {
                scrollTeacherIntoView(index);
            }
        };

        const performSearch = (term) => {
            const query = term.trim().toLowerCase();
            if (!query) {
                resetTeacherHighlight();
                return;
            }
            const index = teacherNames.findIndex(name => name.toLowerCase().includes(query));
            if (index >= 0) {
                highlightTeacherBar(index, { scroll: true });
            } else {
                resetTeacherHighlight(false);
                teacherPerformanceChart.update('none');
            }
        };

        if (teacherPerformanceSearchInput) {
            teacherPerformanceSearchInput.addEventListener('input', (event) => {
                performSearch(event.target.value || '');
            });
        }

        if (teacherPerformanceSearchClear) {
            teacherPerformanceSearchClear.addEventListener('click', () => {
                if (teacherPerformanceSearchInput) {
                    teacherPerformanceSearchInput.value = '';
                    teacherPerformanceSearchInput.focus();
                }
                resetTeacherHighlight();
            });
        }

        if (teacherPerformanceChart.canvas) {
            teacherPerformanceChart.canvas.addEventListener('click', (event) => {
                const points = teacherPerformanceChart.getElementsAtEventForMode(
                    event,
                    'nearest',
                    { intersect: true },
                    true
                );
                if (points.length) {
                    const index = points[0].index;
                    highlightTeacherBar(index, { scroll: true });
                    if (teacherPerformanceSearchInput) {
                        teacherPerformanceSearchInput.value = teacherNames[index];
                    }
                }
            });
        }
    }
    <?php endif; ?>
    // Teacher Performance Radar
    <?php if (!empty($teacher_performance_report)): ?>
    const radarCtx = document.getElementById('teacherPerformanceRadarChart');
    if (radarCtx) {
        const radarData = <?php echo json_encode($teacher_performance_report); ?>;
        const labels = radarData.map(item => item.name);
        const radarChart = new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: ['Quiz Avg', 'Assignment Avg', 'Overall Grade', 'Completion Rate', 'Engagement', 'Performance Score'],
                datasets: radarData.map((teacher, idx) => ({
                    label: teacher.name,
                    data: [
                        teacher.avg_quiz_grade,
                        teacher.avg_assignment_grade,
                        teacher.overall_avg_grade,
                        teacher.completion_rate,
                        teacher.engagement * 10,
                        teacher.performance_score
                    ],
                    fill: true,
                    backgroundColor: `rgba(${50 + idx * 20}, 99, 255, 0.12)`,
                    borderColor: `rgba(${50 + idx * 20}, 99, 255, 0.6)`
                }))
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    r: {
                        angleLines: { color: '#e2e8f0' },
                        grid: { color: '#e2e8f0' },
                        suggestedMin: 0,
                        suggestedMax: 100,
                        pointLabels: { font: { size: 12, family: 'Inter' } },
                        ticks: { backdropColor: '#ffffff' }
                    }
                }
            }
        });
    }

    const barCtx = document.getElementById('teacherPerformanceBarChart');
    if (barCtx) {
        const performanceData = <?php echo json_encode($teacher_performance_report); ?>;
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: performanceData.map(item => item.name),
                datasets: [
                    {
                        label: 'Performance Score',
                        data: performanceData.map(item => item.performance_score),
                        backgroundColor: '#8b5cf6',
                        borderColor: '#7c3aed',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false
                    },
                    {
                        label: 'Overall Grade',
                        data: performanceData.map(item => item.overall_avg_grade),
                        backgroundColor: '#3b82f6',
                        borderColor: '#2563eb',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 36,
                            minRotation: 36,
                            color: '#1f2937',
                            font: { size: 13, weight: '600' }
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#6b7280', stepSize: 20 },
                        grid: { color: '#f1f5f9' }
                    }
                },
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    <?php endif; ?>
    // Teacher Pagination
    <?php if (!empty($teacher_performance_data)): ?>
    let currentTeacherPage = 1;
    const teachersPerPage = 12;
    const totalTeachers = <?php echo count($teacher_performance_data); ?>;
    const totalTeacherPages = Math.ceil(totalTeachers / teachersPerPage);

    window.changeTeacherPage = function(action) {
        if (action === 'prev' && currentTeacherPage > 1) {
            currentTeacherPage--;
        } else if (action === 'next' && currentTeacherPage < totalTeacherPages) {
            currentTeacherPage++;
        } else if (typeof action === 'number') {
            currentTeacherPage = action;
        }
        updateTeacherTableDisplay();
    };
    function updateTeacherTableDisplay() {
        const allRows = document.querySelectorAll('#teacherTableBody tr.teacher-row');
        if (allRows.length === 0) {
            // Try alternative selector
            const altRows = document.querySelectorAll('#teacherTableBody tr[data-page]');
            altRows.forEach(row => {
                const rowPage = parseInt(row.getAttribute('data-page')) || 1;
                row.style.display = rowPage === currentTeacherPage ? '' : 'none';
            });
        } else {
        allRows.forEach(row => {
                const rowPage = parseInt(row.getAttribute('data-page')) || 1;
            row.style.display = rowPage === currentTeacherPage ? '' : 'none';
        });
        }

        const start = (currentTeacherPage - 1) * teachersPerPage + 1;
        const end = Math.min(currentTeacherPage * teachersPerPage, totalTeachers);
        const infoDiv = document.getElementById('teacherPaginationInfo');
        if (infoDiv) {
            infoDiv.innerHTML = `Showing <span style="color: #1f2937; font-weight: 600;">${start}</span> - <span style="color: #1f2937; font-weight: 600;">${end}</span> of <span style="color: #1f2937; font-weight: 600;">${totalTeachers}</span> teachers`;
        }

        const prevBtn = document.getElementById('teacherPrevBtn');
        const nextBtn = document.getElementById('teacherNextBtn');
        const pageNumbers = document.getElementById('teacherPageNumbers');
        
        if (totalTeacherPages > 1) {
            // Show navigation controls
        if (prevBtn) {
                prevBtn.style.display = 'flex';
            if (currentTeacherPage > 1) {
                prevBtn.style.background = '#ffffff';
                prevBtn.style.color = '#3b82f6';
                prevBtn.style.borderColor = '#3b82f6';
                prevBtn.style.cursor = 'pointer';
                prevBtn.disabled = false;
            } else {
                prevBtn.style.background = '#f3f4f6';
                prevBtn.style.color = '#9ca3af';
                prevBtn.style.borderColor = '#e5e7eb';
                prevBtn.style.cursor = 'not-allowed';
                prevBtn.disabled = true;
            }
        }

        if (nextBtn) {
                nextBtn.style.display = 'flex';
            if (currentTeacherPage < totalTeacherPages) {
                nextBtn.style.background = '#ffffff';
                nextBtn.style.color = '#3b82f6';
                nextBtn.style.borderColor = '#3b82f6';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else {
                nextBtn.style.background = '#f3f4f6';
                nextBtn.style.color = '#9ca3af';
                nextBtn.style.borderColor = '#e5e7eb';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }
            
            if (pageNumbers) {
                pageNumbers.style.display = 'flex';
            }

        updateTeacherPageNumbers();
        } else {
            // Hide navigation controls for single page
            if (prevBtn) prevBtn.style.display = 'none';
            if (nextBtn) nextBtn.style.display = 'none';
            if (pageNumbers) pageNumbers.style.display = 'none';
        }
    }
    function updateTeacherPageNumbers() {
        const container = document.getElementById('teacherPageNumbers');
        if (!container) return;

        let html = '';
        const startPage = Math.max(1, currentTeacherPage - 2);
        const endPage = Math.min(totalTeacherPages, currentTeacherPage + 2);

        if (startPage > 1) {
            html += `<button onclick="changeTeacherPage(1)" class="pagination-btn">1</button>`;
            if (startPage > 2) {
                html += `<span style="padding: 8px 4px; color: #9ca3af;">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            if (i === currentTeacherPage) {
                html += `<span style="padding: 8px 12px; background: #3b82f6; color: #ffffff; border-radius: 8px; font-weight: 600;">${i}</span>`;
            } else {
                html += `<button onclick="changeTeacherPage(${i})" class="pagination-btn">${i}</button>`;
            }
        }

        if (endPage < totalTeacherPages) {
            if (endPage < totalTeacherPages - 1) {
                html += `<span style="padding: 8px 4px; color: #9ca3af;">...</span>`;
            }
            html += `<button onclick="changeTeacherPage(${totalTeacherPages})" class="pagination-btn">${totalTeacherPages}</button>`;
        }

        container.innerHTML = html;

        const buttons = container.querySelectorAll('button');
        buttons.forEach(btn => {
            btn.style.padding = '8px 12px';
            btn.style.background = '#ffffff';
            btn.style.color = '#374151';
            btn.style.border = '1px solid #e5e7eb';
            btn.style.borderRadius = '8px';
            btn.style.fontWeight = '600';
            btn.style.fontSize = '0.85rem';
            btn.style.cursor = 'pointer';
            btn.addEventListener('mouseover', () => btn.style.background = '#f3f4f6');
            btn.addEventListener('mouseout', () => btn.style.background = '#ffffff');
        });
    }

    updateTeacherTableDisplay();
    initAssessmentTeacherTable();
    initTeacherOverviewTable();
    initTeacherDetailInteractions();
    <?php endif; ?>

    const teacherDetailOverlayElement = document.getElementById('teacherDetailOverlay');
    if (teacherDetailOverlayElement && teacherDetailOverlayElement.parentElement !== document.body) {
        document.body.appendChild(teacherDetailOverlayElement);
    }

    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.zIndex = '5000';
        sidebar.style.visibility = 'visible';
        sidebar.style.display = 'block';
    }

    window.addEventListener('resize', () => {
        const sidebar = document.querySelector('.school-manager-sidebar');
        if (sidebar) {
            sidebar.style.zIndex = '5000';
            sidebar.style.visibility = 'visible';
            sidebar.style.display = 'block';
        }
        positionTeacherDetailOverlay();
    });

    // Add event listeners to tab buttons to prevent page reload
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabUrlMap = {
        'summary': '<?php echo $tab_urls['summary']->out(false); ?>',
        'performance': '<?php echo $tab_urls['performance']->out(false); ?>',
        'overview': '<?php echo $tab_urls['overview']->out(false); ?>',
        'activitylog': '<?php echo $tab_urls['activitylog']->out(false); ?>',
        'assessment': '<?php echo $tab_urls['assessment']->out(false); ?>',
        'coursewise': '<?php echo $tab_urls['coursewise']->out(false); ?>'
    };

    tabButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabName = this.getAttribute('data-tab');
            if (tabName) {
                switchTeacherTab(tabName);
                // Update URL without page reload
                if (tabUrlMap[tabName]) {
                    window.history.pushState({ tab: tabName }, '', tabUrlMap[tabName]);
                }
            }
        });
    });

    // Handle browser back/forward buttons
    window.addEventListener('popstate', function(e) {
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        const pathname = window.location.pathname;
        
        let tabName = 'summary';
        if (tabParam && ['summary', 'performance', 'overview', 'assessment', 'coursewise', 'activitylog'].includes(tabParam)) {
            tabName = tabParam;
        } else if (pathname.includes('teacher_report_performance.php')) {
            tabName = 'performance';
        } else if (pathname.includes('teacher_report_activitylog.php')) {
            tabName = 'activitylog';
        } else if (pathname.includes('teacher_report_assessment.php')) {
            tabName = 'assessment';
        } else if (pathname.includes('teacher_report_coursewise.php')) {
            tabName = 'coursewise';
        }
        
        switchTeacherTab(tabName);
    });

    const requestedTab = '<?php echo $initial_tab; ?>';
    teacherActiveTab = requestedTab || 'summary';
    if (requestedTab) {
        switchTeacherTab(requestedTab);
    } else {
        switchTeacherTab('summary');
    }

});
</script>

<?php echo $OUTPUT->footer(); ?>
