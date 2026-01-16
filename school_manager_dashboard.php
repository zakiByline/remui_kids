<?php
/**
 * School Manager Dashboard
 * Dedicated dashboard for school managers/company managers with sidebar navigation
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

// Get current user and check if they are a company manager
global $USER, $DB, $OUTPUT, $CFG;

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

/**
 * Count total parents associated with a company (or overall fallback).
 *
 * @param moodle_database $DB
 * @param int|null $company_id
 * @return int
 */
function theme_remui_kids_get_parent_count($DB, $company_id = null) {
    $parent_role = $DB->get_record('role', ['shortname' => 'parent']);
    if (!$parent_role || !$company_id) {
        return 0;
    }

    $parents = [];
    $contextuserlevel = CONTEXT_USER;

    if ($DB->get_manager()->table_exists('company_users')) {
        // Parents that belong to this company directly (have parent role anywhere).
        $company_parents = $DB->get_records_sql(
            "SELECT DISTINCT u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.phone1,
                    u.firstaccess,
                    u.lastaccess
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {role_assignments} ra ON ra.userid = u.id
              WHERE ra.roleid = :roleid
                AND u.deleted = 0",
            [
                'companyid' => $company_id,
                'roleid' => $parent_role->id
            ]
        );

        foreach ($company_parents as $parent) {
            $parents[$parent->id] = $parent;
        }

        // Parents linked to children in this company via parent role assignments.
        $role_parents = $DB->get_records_sql(
            "SELECT DISTINCT p.id,
                    p.firstname,
                    p.lastname,
                    p.email,
                    p.phone1,
                    p.firstaccess,
                    p.lastaccess
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {user} child ON child.id = ctx.instanceid
               JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = :companyid
               JOIN {user} p ON p.id = ra.userid
              WHERE ra.roleid = :roleid
                AND p.deleted = 0",
            [
                'ctxlevel' => $contextuserlevel,
                'companyid' => $company_id,
                'roleid' => $parent_role->id
            ]
        );

        foreach ($role_parents as $parent) {
            if (!isset($parents[$parent->id])) {
                $parents[$parent->id] = $parent;
            }
        }
    }

    return count($parents);
}

/**
 * Normalize incoming grade names (profile field, cohort, etc.) into clean labels.
 */
function theme_remui_kids_normalize_grade_label(?string $label): string {
    $label = trim((string)$label);
    if ($label === '' || strcasecmp($label, 'Unassigned') === 0) {
        return get_string('notset', 'moodle');
    }

    if (preg_match('/grade\s*(\d{1,2})/i', $label, $match)) {
        return 'Grade ' . (int)$match[1];
    }

    if (preg_match('/kg\s*[- ]*\s*level\s*(\d{1,2})/i', $label, $match)) {
        return 'KG Level ' . (int)$match[1];
    }

    if (preg_match('/kindergarten/i', $label)) {
        return 'Kindergarten';
    }

    return ucwords($label);
}

/**
 * Sort grade buckets so numbered grades stay in order.
 */
function theme_remui_kids_sort_grade_buckets(array $buckets): array {
    uksort($buckets, function ($a, $b) {
        $pattern = '/(\d{1,2})/';
        $hasA = preg_match($pattern, $a, $matchA);
        $hasB = preg_match($pattern, $b, $matchB);

        if ($hasA && $hasB) {
            $cmp = (int)$matchA[1] <=> (int)$matchB[1];
            if ($cmp !== 0) {
                return $cmp;
            }
        } elseif ($hasA) {
            return -1;
        } elseif ($hasB) {
            return 1;
        }

        return strcasecmp($a, $b);
    });

    return $buckets;
}

/**
 * Build grade distribution buckets (0-100) for the given company.
 * Uses cohorts if available, otherwise falls back to grade level profile field.
 */
function theme_remui_kids_get_grade_distribution_data($company_id) {
    global $DB;

    if (!$company_id || !$DB->get_manager()->table_exists('company_users')) {
        return [
            'labels' => [],
            'values' => [],
            'total' => 0,
            'rows' => []
        ];
    }

    // First try: Use cohorts (preferred method)
    $records = $DB->get_records_sql(
        "SELECT co.id AS cohortid,
                co.name AS cohortname,
                COUNT(DISTINCT u.id) AS studentcount
           FROM {company} comp
           JOIN {company_users} cu ON cu.companyid = comp.id
           JOIN {user} u ON u.id = cu.userid
           JOIN {cohort_members} cm ON cm.userid = u.id
           JOIN {cohort} co ON co.id = cm.cohortid AND co.visible = 1
      LEFT JOIN {role_assignments} ra ON ra.userid = u.id
      LEFT JOIN {role} r ON r.id = ra.roleid
          WHERE comp.id = :companyid
            AND u.deleted = 0
            AND u.suspended = 0
            AND (COALESCE(cu.educator, 0) = 0 OR r.shortname = 'student')
       GROUP BY co.id, co.name
       ORDER BY co.name ASC",
        ['companyid' => $company_id]
    );

    $labels = [];
    $values = [];
    $total = 0;
    $rows = [];

    // If cohorts found, use them
    if (!empty($records)) {
        foreach ($records as $record) {
            $labels[] = $record->cohortname;
            $values[] = (int)$record->studentcount;
            $total += (int)$record->studentcount;
            $rows[] = [
                'label' => $record->cohortname,
                'value' => (int)$record->studentcount
            ];
        }
    } else {
        // Fallback: Use grade level profile field
        if ($DB->get_manager()->table_exists('user_info_field') && $DB->get_manager()->table_exists('user_info_data')) {
            $grade_field = $DB->get_record('user_info_field', ['shortname' => 'gradelevel']);
            
            if ($grade_field) {
                $grade_records = $DB->get_records_sql(
                    "SELECT uifd.data AS grade_level,
                            COUNT(DISTINCT u.id) AS studentcount
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
                  LEFT JOIN {role_assignments} ra ON ra.userid = u.id
                  LEFT JOIN {role} r ON r.id = ra.roleid
                  LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id AND uifd.fieldid = :fieldid
                      WHERE u.deleted = 0
                        AND u.suspended = 0
                        AND (COALESCE(cu.educator, 0) = 0 OR r.shortname = 'student')
                        AND uifd.data IS NOT NULL
                        AND uifd.data != ''
                   GROUP BY uifd.data
                   ORDER BY uifd.data ASC",
                    [
                        'companyid' => $company_id,
                        'fieldid' => $grade_field->id
                    ]
                );
                
                if (!empty($grade_records)) {
                    foreach ($grade_records as $record) {
                        $grade_label = theme_remui_kids_normalize_grade_label($record->grade_level);
                        $labels[] = $grade_label;
                        $values[] = (int)$record->studentcount;
                        $total += (int)$record->studentcount;
                        $rows[] = [
                            'label' => $grade_label,
                            'value' => (int)$record->studentcount
                        ];
                    }
                }
            }
        }
        
        // If still no data, show total students as "All Students"
        if (empty($labels)) {
            $total_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                   JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = :companyid
              LEFT JOIN {role_assignments} ra ON u.id = ra.userid
              LEFT JOIN {role} r ON r.id = ra.roleid
                  WHERE u.deleted = 0
                    AND (COALESCE(cu.educator, 0) = 0 OR r.shortname = 'student')",
                ['companyid' => $company_id]
            );
            
            if ($total_students > 0) {
                $labels[] = 'All Students';
                $values[] = $total_students;
                $total = $total_students;
                $rows[] = [
                    'label' => 'All Students',
                    'value' => $total_students
                ];
            }
        }
    }

    return [
        'labels' => $labels,
        'values' => $values,
        'total' => $total,
        'rows' => $rows
    ];
}

/**
 * Login trend data (last 30 days) for students and teachers.
 */
function theme_remui_kids_get_login_trend_data($company_id) {
    global $DB;

    $dates = [];
    $student = [];
    $teacher = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[] = $date;
        $student[$date] = 0;
        $teacher[$date] = 0;
    }

    $starttime = strtotime('-30 days');
    $loginTrendData = [
        'dates' => $dates,
        'student' => array_values($student),
        'teacher' => array_values($teacher),
        'total_student_logins' => 0,
        'total_teacher_logins' => 0,
        'average_daily_logins' => 0
    ];

    $student_records = [];
    $teacher_records = [];
    $hascompanytable = $DB->get_manager()->table_exists('company_users');

    if ($hascompanytable) {
        $studentParams = ['threshold' => $starttime];
        $teacherParams = ['threshold' => $starttime];
        $companyClause = '';

        if ($company_id) {
            $companyClause = 'AND cu.companyid = :companyid';
            $studentParams['companyid'] = $company_id;
            $teacherParams['companyid'] = $company_id;
        }

        $student_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.lastaccess
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id
          LEFT JOIN {role_assignments} ra ON ra.userid = u.id
          LEFT JOIN {role} r ON r.id = ra.roleid
              WHERE u.deleted = 0
                AND u.suspended = 0
                AND u.lastaccess >= :threshold
                $companyClause
                AND (COALESCE(cu.educator, 0) = 0 OR r.shortname = 'student')",
            $studentParams
        );

        $teacherRoles = ['teacher', 'editingteacher', 'manager', 'coursecreator', 'noneditingteacher'];
        list($roleSql, $roleParams) = $DB->get_in_or_equal($teacherRoles, SQL_PARAMS_NAMED, 'trole');
        $teacherParams = array_merge($teacherParams, $roleParams);

        $teacher_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.lastaccess
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id
          LEFT JOIN {role_assignments} ra ON ra.userid = u.id
          LEFT JOIN {role} r ON r.id = ra.roleid
              WHERE u.deleted = 0
                AND u.suspended = 0
                AND u.lastaccess >= :threshold
                $companyClause
                AND (COALESCE(cu.educator, 0) = 1 OR r.shortname $roleSql)",
            $teacherParams
        );
    } else {
        $params = ['threshold' => $starttime];
        $student_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.lastaccess
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid
              WHERE r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0
                AND u.lastaccess >= :threshold",
            $params
        );

        $teacher_records = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.lastaccess
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid
              WHERE r.shortname IN ('teacher', 'editingteacher', 'manager')
                AND u.deleted = 0
                AND u.suspended = 0
                AND u.lastaccess >= :threshold",
            $params
        );
    }

    foreach ($dates as $date) {
        $date_start = strtotime($date . ' 00:00:00');
        $date_end = strtotime($date . ' 23:59:59');

        $student[$date] = array_reduce($student_records, function ($carry, $record) use ($date_start, $date_end) {
            return $carry + (($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) ? 1 : 0);
        }, 0);

        $teacher[$date] = array_reduce($teacher_records, function ($carry, $record) use ($date_start, $date_end) {
            return $carry + (($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) ? 1 : 0);
        }, 0);
    }

    $studentSeries = array_values($student);
    $teacherSeries = array_values($teacher);
    $studentTotal = array_sum($studentSeries);
    $teacherTotal = array_sum($teacherSeries);
    $avgDaily = round(($studentTotal + $teacherTotal) / (count($dates) ?: 1), 1);

    $loginTrendData['student'] = $studentSeries;
    $loginTrendData['teacher'] = $teacherSeries;
    $loginTrendData['total_student_logins'] = $studentTotal;
    $loginTrendData['total_teacher_logins'] = $teacherTotal;
    $loginTrendData['average_daily_logins'] = $avgDaily;

    return $loginTrendData;
}

/**
 * Assignment and quiz summary metrics.
 */
function theme_remui_kids_get_assessment_summary_data($company_id) {
    global $DB;

    $default = [
        'assignments' => [
            'total' => 0,
            'submitted' => 0,
            'graded' => 0,
            'avg_grade' => 0
        ],
        'quizzes' => [
            'total' => 0,
            'attempts' => 0,
            'completed' => 0,
            'avg_score' => 0
        ]
    ];

    $conditions = '';
    $params = [];
    if ($company_id) {
        $conditions = 'JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = :companyid';
        $params['companyid'] = $company_id;
    } else {
        $conditions = 'JOIN {company_users} cu ON cu.userid = ue.userid';
    }

    $assignmentstats = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT a.id) AS total,
                COUNT(DISTINCT CASE WHEN asub.status = 'submitted' THEN asub.id END) AS submitted,
                COUNT(DISTINCT CASE WHEN ag.grade IS NOT NULL AND ag.grade >= 0 THEN ag.id END) AS graded
           FROM {assign} a
           JOIN {course} c ON c.id = a.course
           JOIN {enrol} e ON e.courseid = c.id
           JOIN {user_enrolments} ue ON ue.enrolid = e.id
           $conditions
      LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = cu.userid
      LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = cu.userid",
        $params
    );

    $assignment_avg = $DB->get_field_sql(
        "SELECT AVG((ag.grade / NULLIF(a.grade, 0)) * 100)
           FROM {assign_grades} ag
           JOIN {assign} a ON a.id = ag.assignment
           JOIN {company_users} cu ON cu.userid = ag.userid " . ($company_id ? "AND cu.companyid = :companyid" : "") . "
          WHERE ag.grade IS NOT NULL
            AND ag.grade >= 0",
        $params
    );

    $quizstats = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT q.id) AS total,
                COUNT(DISTINCT qa.id) AS attempts,
                COUNT(DISTINCT CASE WHEN qa.state = 'finished' THEN qa.id END) AS completed
           FROM {quiz} q
           JOIN {course} c ON c.id = q.course
           JOIN {enrol} e ON e.courseid = c.id
           JOIN {user_enrolments} ue ON ue.enrolid = e.id
           $conditions
      LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = cu.userid",
        $params
    );

    $quiz_avg = $DB->get_field_sql(
        "SELECT AVG((qa.sumgrades / NULLIF(q.sumgrades, 0)) * 100)
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
           JOIN {company_users} cu ON cu.userid = qa.userid AND cu.companyid = :companyid
          WHERE qa.state = 'finished'
            AND qa.sumgrades IS NOT NULL",
        $params
    );

    return [
        'assignments' => [
            'total' => $assignmentstats ? (int)$assignmentstats->total : 0,
            'submitted' => $assignmentstats ? (int)$assignmentstats->submitted : 0,
            'graded' => $assignmentstats ? (int)$assignmentstats->graded : 0,
            'avg_grade' => $assignment_avg ? round($assignment_avg, 1) : 0
        ],
        'quizzes' => [
            'total' => $quizstats ? (int)$quizstats->total : 0,
            'attempts' => $quizstats ? (int)$quizstats->attempts : 0,
            'completed' => $quizstats ? (int)$quizstats->completed : 0,
            'avg_score' => $quiz_avg ? round($quiz_avg, 1) : 0
        ]
    ];
}

/**
 * Course completion summary for company.
 */
function theme_remui_kids_get_course_completion_summary($company_id) {
    global $DB;

    if (!$company_id) {
        return [
            'total' => 0,
            'completed' => 0,
            'inprogress' => 0,
            'completion_rate' => 0
        ];
    }

    $completion = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT cc.id) AS total,
                SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) AS completed
           FROM {course_completions} cc
           JOIN {company_users} cu ON cu.userid = cc.userid AND cu.companyid = :companyid",
        ['companyid' => $company_id]
    );

    $total = $completion ? (int)$completion->total : 0;
    $completed = $completion ? (int)$completion->completed : 0;
    $inprogress = max(0, $total - $completed);
    $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

    return [
        'total' => $total,
        'completed' => $completed,
        'inprogress' => $inprogress,
        'completion_rate' => $rate
    ];
}

/**
 * Aggregated overview metrics (enrollment trend, login access, etc.).
 */
function theme_remui_kids_get_school_overview_data($company_id) {
    global $DB;

    $data = [
        'overall_academic' => [
            'avg_grade' => 0,
            'pass_rate' => 0
        ],
        'enrollment_summary' => [
            'total' => 0,
            'new' => 0
        ],
        'active_users' => [
            'active' => 0,
            'inactive' => 0,
            'active_percent' => 0,
            'inactive_percent' => 0
        ],
        'growth_report' => [
            'labels' => [],
            'values' => []
        ],
        'login_access' => [
            'labels' => ['Students', 'Teachers', 'Parents'],
            'values' => [0, 0, 0]
        ]
    ];

    if (!$company_id) {
        return $data;
    }

    $avg_grade = $DB->get_field_sql(
        "SELECT AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100)
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
           JOIN {company_users} cu ON cu.userid = gg.userid AND cu.companyid = :companyid
          WHERE gg.finalgrade IS NOT NULL
            AND gi.grademax > 0",
        ['companyid' => $company_id]
    );

    $completion_summary = theme_remui_kids_get_course_completion_summary($company_id);

    $data['overall_academic']['avg_grade'] = $avg_grade ? round($avg_grade, 1) : 0;
    $data['overall_academic']['pass_rate'] = $completion_summary['completion_rate'];

    $thirty_days = strtotime('-30 days');
    $enrollments = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT ue.id) AS total,
                SUM(CASE WHEN ue.timecreated >= :recent THEN 1 ELSE 0 END) AS recent
           FROM {user_enrolments} ue
           JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = :companyid",
        ['recent' => $thirty_days, 'companyid' => $company_id]
    );

    if ($enrollments) {
        $data['enrollment_summary']['total'] = (int)$enrollments->total;
        $data['enrollment_summary']['new'] = (int)$enrollments->recent;
    }

    $active_threshold = strtotime('-30 days');
    $useractivity = $DB->get_record_sql(
        "SELECT SUM(CASE WHEN u.lastaccess >= :threshold THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN u.lastaccess < :threshold OR u.lastaccess IS NULL THEN 1 ELSE 0 END) AS inactive
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
          WHERE u.deleted = 0",
        ['threshold' => $active_threshold, 'companyid' => $company_id]
    );

    if ($useractivity) {
        $data['active_users']['active'] = (int)$useractivity->active;
        $data['active_users']['inactive'] = (int)$useractivity->inactive;
        $totalusers = ((int)$useractivity->active) + ((int)$useractivity->inactive);
        $data['active_users']['active_percent'] = $totalusers ? round($useractivity->active / $totalusers * 100, 1) : 0;
        $data['active_users']['inactive_percent'] = $totalusers ? round($useractivity->inactive / $totalusers * 100, 1) : 0;
    }

    $growth_labels = [];
    $growth_values = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = strtotime("first day of -" . $i . " month");
        $monthEnd = strtotime("first day of -" . ($i - 1) . " month");
        $label = date('M', $monthStart);
        $growth_labels[] = $label;
        $count = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT ue.id)
               FROM {user_enrolments} ue
               JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = :companyid
              WHERE ue.timecreated >= :start AND ue.timecreated < :end",
            [
                'companyid' => $company_id,
                'start' => $monthStart,
                'end' => $monthEnd
            ]
        );
        $growth_values[] = (int)$count;
    }

    $data['growth_report']['labels'] = $growth_labels;
    $data['growth_report']['values'] = $growth_values;

    $roleMap = [
        'Students' => ['student'],
        'Teachers' => ['teacher', 'editingteacher'],
        'Parents' => ['parent']
    ];
    $roleValues = [];
    foreach ($roleMap as $roles) {
        list($insql, $params) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
        $params += [
            'companyid' => $company_id,
            'start' => $thirty_days,
            'syscontext' => CONTEXT_SYSTEM
        ];
        $count = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT l.userid)
               FROM {logstore_standard_log} l
               JOIN {company_users} cu ON cu.userid = l.userid AND cu.companyid = :companyid
               JOIN {role_assignments} ra ON ra.userid = l.userid
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
              WHERE l.action = 'loggedin'
                AND l.timecreated >= :start
                AND ctx.contextlevel = :syscontext
                AND r.shortname $insql",
            $params
        );
        $roleValues[] = (int)$count;
    }
    $data['login_access']['values'] = $roleValues;

    return $data;
}

/**
 * Distribution across major roles for charting.
 */
function theme_remui_kids_get_user_role_distribution($company_id, $total_students = 0, $total_teachers = 0, $total_parents = 0) {
    global $DB;

    $managercount = 0;
    if ($company_id && $DB->get_manager()->table_exists('company_users')) {
        $managercount = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT cu.userid)
               FROM {company_users} cu
               JOIN {role_assignments} ra ON ra.userid = cu.userid
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
              WHERE cu.companyid = :companyid
                AND ctx.contextlevel = :syscontext
                AND r.shortname IN ('companymanager', 'manager', 'schoolmanager')",
            ['companyid' => $company_id, 'syscontext' => CONTEXT_SYSTEM]
        );
    }

    $total = (int)$total_students + (int)$total_teachers + (int)$total_parents + (int)$managercount;

    return [
        'labels' => ['Students', 'Teachers', 'Parents', 'Managers'],
        'values' => [
            (int)$total_students,
            (int)$total_teachers,
            (int)$total_parents,
            (int)$managercount
        ],
        'students' => (int)$total_students,
        'teachers' => (int)$total_teachers,
        'parents' => (int)$total_parents,
        'managers' => (int)$managercount,
        'total' => $total
    ];
}

// If not a company manager, redirect to appropriate page (unless in diagnostic mode)
if (!$is_company_manager && !defined('DIAGNOSTIC_MODE')) {
    redirect($CFG->wwwroot . '/my/', 'You do not have permission to access the School Manager Dashboard.', null, \core\output\notification::NOTIFY_ERROR);
}

// If in diagnostic mode, just return after defining functions
if (defined('DIAGNOSTIC_MODE')) {
    return;
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/school_manager_dashboard.php');
$PAGE->set_title('School Manager Dashboard');
$PAGE->set_heading('School Manager Dashboard');
$PAGE->set_pagelayout('mydashboard');
$PAGE->set_pagetype('my-index');
$PAGE->add_body_class('limitedwidth');

// Set up blocks for default Moodle dashboard
require_once($CFG->dirroot . '/my/lib.php');
if (class_exists('company_user')) {
    company_user::check_dashboard_page();
}
$currentpage = my_get_page($USER->id, MY_PAGE_PRIVATE);
if ($currentpage) {
    $PAGE->set_subpage($currentpage->id);
    $PAGE->blocks->add_region('content');
    $PAGE->blocks->set_default_region('content');
}

// Get school/company information for the current user
$company_info = null;
error_log("🔍 FETCHING COMPANY INFO - User ID: " . $USER->id . ", Is Company Manager: " . ($is_company_manager ? 'YES' : 'NO'));

if ($is_company_manager) {
    // Check if company tables exist
    if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
        error_log("✅ Company tables exist, fetching company info...");
        // Get company information for the current user - EXACT SAME QUERY AS drawers.php
        $company_info = $DB->get_record_sql(
            "SELECT c.*, u.firstname, u.lastname, u.email 
             FROM {company} c 
             JOIN {company_users} cu ON c.id = cu.companyid 
             JOIN {user} u ON cu.userid = u.id 
             WHERE cu.userid = ? AND cu.managertype = 1",
            [$USER->id]
        );
        
        if ($company_info) {
            error_log("✅ COMPANY INFO FOUND - ID: " . $company_info->id . ", Name: " . $company_info->name);
        } else {
            error_log("⚠️ COMPANY INFO NOT FOUND for user " . $USER->id . " with managertype = 1");
            // Try to find ANY company for this user (even if not manager)
            $any_company = $DB->get_record_sql(
                "SELECT c.* 
                 FROM {company} c 
                 JOIN {company_users} cu ON c.id = cu.companyid 
                 WHERE cu.userid = ? 
                 LIMIT 1",
                [$USER->id]
            );
            if ($any_company) {
                error_log("  Found company as regular user: ID=" . $any_company->id . ", Name=" . $any_company->name);
                // Use this company anyway for calendar data
                $company_info = $any_company;
            }
        }
    } else {
        error_log("❌ Company tables do not exist!");
    }
    
    // Get company logo if exists
    if ($company_info) {
        // Check if company_logo table exists
        if ($DB->get_manager()->table_exists('company_logo')) {
            $company_logo = $DB->get_record('company_logo', ['companyid' => $company_info->id]);
            if ($company_logo) {
                $company_info->logo_filename = $company_logo->filename;
                $company_info->logo_filepath = $CFG->dataroot . '/company/' . $company_info->id . '/' . $company_logo->filename;
            }
        }
    }
} else {
    error_log("❌ User is NOT a company manager - cannot fetch company info");
}

// OPTIMIZATION: Add AJAX endpoint for loading calendar form data (teachers, students, cohorts, courses)
if (isset($_GET['action']) && $_GET['action'] === 'get_calendar_form_data') {
    header('Content-Type: application/json');
    require_sesskey();
    
    try {
        $company_id = $company_info ? $company_info->id : 0;
        
        if (!$company_id) {
            echo json_encode(['status' => 'error', 'message' => 'Company ID not found']);
            exit;
        }
        
        // Get teachers
        $calendar_teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
             FROM {user} u
             INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
             INNER JOIN {role_assignments} ra ON u.id = ra.userid
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
             WHERE u.deleted = 0
             ORDER BY u.firstname, u.lastname
             LIMIT 500",
            [$company_id]
        );
        
        // Get students
        $calendar_students = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator,
                    GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                    uifd.data AS grade_level
             FROM {user} u
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
             LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
             LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
             WHERE u.deleted = 0
             GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator, uifd.data
             ORDER BY u.firstname, u.lastname
             LIMIT 1000",
            [$company_id]
        );
        
        if (empty($calendar_students)) {
            $calendar_students = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator,
                        'student' as roles, uifd.data AS grade_level
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                 LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                 LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                 WHERE u.deleted = 0 AND cu.educator = 0
                 GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator, uifd.data
                 ORDER BY u.firstname, u.lastname
                 LIMIT 1000",
                [$company_id]
            );
        }
        
        // Get cohorts
        $calendar_cohorts = [];
        if ($DB->get_manager()->table_exists('cohort') && $DB->get_manager()->table_exists('cohort_members')) {
            $calendar_cohorts = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.name, c.idnumber,
                        (SELECT COUNT(DISTINCT cm.userid)
                         FROM {cohort_members} cm
                         INNER JOIN {user} u ON u.id = cm.userid
                         INNER JOIN {company_users} cu ON cu.userid = u.id
                         INNER JOIN {role_assignments} ra ON ra.userid = u.id
                         INNER JOIN {role} r ON r.id = ra.roleid
                         WHERE cm.cohortid = c.id
                         AND cu.companyid = ?
                         AND r.shortname = 'student'
                         AND u.deleted = 0
                         AND u.suspended = 0) AS student_count
                 FROM {cohort} c
                 WHERE c.visible = 1
                 AND EXISTS (
                     SELECT 1
                     FROM {cohort_members} cm
                     INNER JOIN {user} u ON u.id = cm.userid
                     INNER JOIN {company_users} cu ON cu.userid = u.id
                     INNER JOIN {role_assignments} ra ON ra.userid = u.id
                     INNER JOIN {role} r ON r.id = ra.roleid
                     WHERE cm.cohortid = c.id
                     AND cu.companyid = ?
                     AND r.shortname = 'student'
                     AND u.deleted = 0
                     AND u.suspended = 0
                 )
                 ORDER BY c.name ASC
                 LIMIT 200",
                [$company_id, $company_id]
            );
        }
        
        // Get courses
        $calendar_courses = [];
        if ($DB->get_manager()->table_exists('company_course')) {
            $calendar_courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber
                 FROM {course} c
                 INNER JOIN {company_course} cc ON c.id = cc.courseid
                 WHERE cc.companyid = ? AND c.visible = 1 AND c.id > 1
                 ORDER BY c.fullname
                 LIMIT 500",
                [$company_id]
            );
        } else {
            $calendar_courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber
                 FROM {course} c
                 INNER JOIN {enrol} e ON c.id = e.courseid
                 INNER JOIN {user_enrolments} ue ON e.id = ue.enrolid
                 INNER JOIN {company_users} cu ON ue.userid = cu.userid AND cu.companyid = ?
                 WHERE c.visible = 1 AND c.id > 1
                 GROUP BY c.id, c.fullname, c.shortname, c.idnumber
                 ORDER BY c.fullname
                 LIMIT 500",
                [$company_id]
            );
        }
        
        // Build JSON strings
        $calendar_teachers_json = json_encode(array_map(function($t) {
            return [
                'id' => (int)$t->id,
                'firstname' => isset($t->firstname) ? $t->firstname : '',
                'lastname' => isset($t->lastname) ? $t->lastname : '',
                'email' => isset($t->email) ? $t->email : '',
                'fullname' => trim((isset($t->firstname) ? $t->firstname : '') . ' ' . (isset($t->lastname) ? $t->lastname : ''))
            ];
        }, array_values($calendar_teachers)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        
        $calendar_students_json = json_encode(array_map(function($s) {
            return [
                'id' => (int)$s->id,
                'firstname' => isset($s->firstname) ? $s->firstname : '',
                'lastname' => isset($s->lastname) ? $s->lastname : '',
                'email' => isset($s->email) ? $s->email : '',
                'fullname' => trim((isset($s->firstname) ? $s->firstname : '') . ' ' . (isset($s->lastname) ? $s->lastname : ''))
            ];
        }, array_values($calendar_students)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        
        $calendar_cohorts_json = json_encode(array_map(function($c) {
            return [
                'id' => (int)$c->id,
                'name' => isset($c->name) ? $c->name : '',
                'idnumber' => isset($c->idnumber) ? $c->idnumber : '',
                'student_count' => isset($c->student_count) ? (int)$c->student_count : 0
            ];
        }, array_values($calendar_cohorts)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        
        $calendar_courses_json = json_encode(array_map(function($c) {
            return [
                'id' => (int)$c->id,
                'fullname' => isset($c->fullname) ? $c->fullname : '',
                'shortname' => isset($c->shortname) ? $c->shortname : '',
                'idnumber' => isset($c->idnumber) ? $c->idnumber : ''
            ];
        }, array_values($calendar_courses)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        
        echo json_encode([
            'status' => 'success',
            'calendar_teachers' => array_values($calendar_teachers),
            'calendar_students' => array_values($calendar_students),
            'calendar_cohorts' => array_values($calendar_cohorts),
            'calendar_courses' => array_values($calendar_courses),
            'calendar_teachers_json' => $calendar_teachers_json,
            'calendar_students_json' => $calendar_students_json,
            'calendar_cohorts_json' => $calendar_cohorts_json,
            'calendar_courses_json' => $calendar_courses_json,
            'timestamp' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// OPTIMIZATION: Add AJAX endpoint for loading chart data asynchronously
if (isset($_GET['action']) && $_GET['action'] === 'get_chart_data') {
    header('Content-Type: application/json');
    require_sesskey();
    
    try {
        $company_id = $company_info ? $company_info->id : 0;
        
        $chart_data = [
            'grade_distribution' => theme_remui_kids_get_grade_distribution_data($company_id),
            'login_trend' => theme_remui_kids_get_login_trend_data($company_id),
            'assessment_summary' => theme_remui_kids_get_assessment_summary_data($company_id),
            'course_completion' => theme_remui_kids_get_course_completion_summary($company_id),
            'school_overview' => theme_remui_kids_get_school_overview_data($company_id),
            'role_distribution' => theme_remui_kids_get_user_role_distribution($company_id, 0, 0, 0) // Counts will be passed separately if needed
        ];
        
        echo json_encode([
            'status' => 'success',
            'data' => $chart_data,
            'timestamp' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// OPTIMIZATION: Add AJAX endpoint for loading calendar data asynchronously
if (isset($_GET['action']) && $_GET['action'] === 'get_calendar_data') {
    header('Content-Type: application/json');
    require_sesskey();
    
    try {
        $calendar_events_ajax = [];
        $upcoming_sessions_ajax = [];
        $all_calendar_data_ajax = [];
        
        if ($company_info) {
            $time_start = time();
            $time_end = time() + (90 * 24 * 60 * 60); // Next 90 days
            
            // Get calendar events (same queries as before but only when requested)
            $events = $DB->get_records_sql(
                "SELECT e.id, e.name, e.description, e.timestart, e.timeduration, e.eventtype, e.courseid, c.fullname as coursename
                 FROM {event} e
                 LEFT JOIN {course} c ON c.id = e.courseid
                 LEFT JOIN {company_course} cc ON cc.courseid = e.courseid
                 WHERE (cc.companyid = ? OR e.courseid = 0 OR e.courseid = 1)
                 AND e.timestart >= ?
                 AND e.timestart <= ?
                 AND e.visible = 1
                 ORDER BY e.timestart ASC
                 LIMIT 100",
                [$company_info->id, $time_start, $time_end]
            );
            
            foreach ($events as $event) {
                $event_date = new DateTime('@' . $event->timestart);
                $event_end = new DateTime('@' . ($event->timestart + $event->timeduration));
                
                $all_calendar_data_ajax[] = [
                    'id' => 'event-' . $event->id,
                    'title' => $event->name,
                    'start' => $event_date->format('Y-m-d\TH:i:s'),
                    'end' => $event_end->format('Y-m-d\TH:i:s'),
                    'description' => strip_tags($event->description),
                    'type' => 'Event',
                    'course' => $event->coursename ?? 'General Event',
                    'color' => '#3b82f6',
                    'textColor' => '#ffffff'
                ];
            }
            
            // Get quiz deadlines (limit to first 50)
            $quizzes = $DB->get_records_sql(
                "SELECT q.id, q.name, q.timeclose, q.timeopen, c.fullname as coursename
                 FROM {quiz} q
                 INNER JOIN {course} c ON c.id = q.course
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE cc.companyid = ?
                 AND q.timeclose > ?
                 AND q.timeclose <= ?
                 ORDER BY q.timeclose ASC
                 LIMIT 50",
                [$company_info->id, $time_start, $time_end]
            );
            
            foreach ($quizzes as $quiz) {
                $quiz_date = new DateTime('@' . $quiz->timeclose);
                $all_calendar_data_ajax[] = [
                    'id' => 'quiz-' . $quiz->id,
                    'title' => '📝 Quiz: ' . $quiz->name,
                    'start' => $quiz_date->format('Y-m-d\TH:i:s'),
                    'description' => 'Quiz deadline',
                    'type' => 'Quiz',
                    'course' => $quiz->coursename,
                    'color' => '#f59e0b',
                    'textColor' => '#ffffff'
                ];
            }
            
            // Get assignment deadlines (limit to first 50)
            $assignments = $DB->get_records_sql(
                "SELECT a.id, a.name, a.duedate, c.fullname as coursename
                 FROM {assign} a
                 INNER JOIN {course} c ON c.id = a.course
                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                 WHERE cc.companyid = ?
                 AND a.duedate > ?
                 AND a.duedate <= ?
                 ORDER BY a.duedate ASC
                 LIMIT 50",
                [$company_info->id, $time_start, $time_end]
            );
            
            foreach ($assignments as $assignment) {
                $assign_date = new DateTime('@' . $assignment->duedate);
                $all_calendar_data_ajax[] = [
                    'id' => 'assign-' . $assignment->id,
                    'title' => '📄 Assignment: ' . $assignment->name,
                    'start' => $assign_date->format('Y-m-d\TH:i:s'),
                    'description' => 'Assignment due date',
                    'type' => 'Assignment',
                    'course' => $assignment->coursename,
                    'color' => '#10b981',
                    'textColor' => '#ffffff'
                ];
            }
            
            // Build calendar_events array (limit to 50)
            foreach ($all_calendar_data_ajax as $item) {
                if (count($calendar_events_ajax) < 50) {
                    $date_obj = new DateTime($item['start']);
                    $calendar_events_ajax[] = [
                        'id' => $item['id'],
                        'name' => $item['title'],
                        'description' => $item['description'],
                        'start_time' => $date_obj->format('H:i'),
                        'date' => $date_obj->format('Y-m-d'),
                        'formatted_date' => $date_obj->format('M d, Y'),
                        'color' => $item['color'],
                        'course_name' => $item['course'],
                        'event_type' => $item['type']
                    ];
                }
                
                if (count($upcoming_sessions_ajax) < 10) {
                    $date_obj = new DateTime($item['start']);
                    $upcoming_sessions_ajax[] = [
                        'title' => $item['title'],
                        'date' => $date_obj->format('M d, Y'),
                        'time' => $date_obj->format('H:i'),
                        'location' => $item['course'],
                        'color' => $item['color'],
                        'type' => $item['type']
                    ];
                }
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'calendar_events' => $calendar_events_ajax,
            'upcoming_sessions' => $upcoming_sessions_ajax,
            'all_calendar_data' => $all_calendar_data_ajax,
            'timestamp' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle AJAX requests for dashboard stats
if (isset($_GET['action']) && $_GET['action'] === 'get_dashboard_stats') {
    header('Content-Type: application/json');
    
    try {
        $company_id = $company_info ? $company_info->id : null;
        
        // Get statistics for the school/company
        $total_teachers = 0;
        $total_students = 0;
        $total_courses = 0;
        $active_enrollments = 0;
        $total_parents = 0;
        
        if ($company_id && $DB->get_manager()->table_exists('company_users')) {
            // EXACT SAME QUERY AS teacher_management.php (line 262-275) for AJAX
            $teachers_array = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email,
                        u.phone1, u.city, u.country, u.suspended, u.lastaccess, u.timecreated, u.picture,
                        GROUP_CONCAT(DISTINCT r.shortname) AS roles
                 FROM {user} u
                 INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
                 INNER JOIN {role_assignments} ra ON u.id = ra.userid
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
                 WHERE u.deleted = 0
                 GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.phone1, u.city, u.country, 
                          u.suspended, u.lastaccess, u.timecreated, u.picture
                 ORDER BY u.firstname, u.lastname",
                [$company_id]
            );
            $total_teachers = count($teachers_array);

            // Preserve original teacher role list for enrolled teacher calculations
            $teacher_roles = ['teacher', 'editingteacher', 'coursecreator'];
            
            // Debug: Log the AJAX count for verification
            error_log("Dashboard AJAX Teacher Count: " . $total_teachers . " for company_id: " . $company_id);
            
            // Count enrolled/active teachers (educators who are actively enrolled in courses)
            $enrolled_teachers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) 
                 FROM {user} u 
                 JOIN {company_users} cu ON u.id = cu.userid 
                 JOIN {user_enrolments} ue ON u.id = ue.userid 
                 JOIN {enrol} e ON ue.enrolid = e.id 
                 JOIN {course} c ON e.courseid = c.id
                 WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
                 AND ue.status = 0 AND e.status = 0 AND c.visible = 1",
                [$company_id]
            );
            
            // EXACT SAME QUERY AS student_management.php (line 299-324) for AJAX
            try {
                if ($DB->get_manager()->table_exists('company_users')) {
                    // Primary query: Students in company_users with student role (EXACT SAME AS student_management.php)
                    $students_array = $DB->get_records_sql(
                        "SELECT u.id,
                                u.firstname,
                                u.lastname,
                                u.email,
                                u.phone1,
                                u.username,
                                u.suspended,
                                u.lastaccess,
                                cu.educator,
                                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                                uifd.data AS grade_level,
                                GROUP_CONCAT(DISTINCT coh.id SEPARATOR ',') AS cohort_ids,
                                GROUP_CONCAT(DISTINCT coh.name SEPARATOR ', ') AS cohort_names
                           FROM {user} u
                           INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                           INNER JOIN {role_assignments} ra ON ra.userid = u.id
                           INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                           LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                           LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                           LEFT JOIN {cohort} coh ON coh.id = cm.cohortid AND coh.visible = 1
                          WHERE u.deleted = 0
                        GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
                        [$company_id]
                    );
                    $total_students = count($students_array);
                    error_log("Dashboard AJAX: Found " . $total_students . " students (SAME AS student_management.php) for company ID: " . $company_id);
                    
                    // If no students found, try alternative approach (same as student_management.php)
                    if (empty($students_array)) {
                        error_log("Dashboard AJAX: No students found with student role, trying alternative query...");
                        $alternative_students = $DB->get_records_sql(
                            "SELECT u.id,
                                    u.firstname,
                                    u.lastname,
                                    u.email,
                                    u.phone1,
                                    u.username,
                                    u.suspended,
                                    u.lastaccess,
                                    cu.educator,
                                    'student' as roles,
                                    uifd.data AS grade_level
                               FROM {user} u
                               INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                               LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                               LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                              WHERE u.deleted = 0 AND cu.educator = 0
                            GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, cu.educator, uifd.data",
                            [$company_id]
                        );
                        
                        if (!empty($alternative_students)) {
                            error_log("Dashboard AJAX: Found " . count($alternative_students) . " students using alternative approach (company_users only)");
                            $total_students = count($alternative_students);
                        }
                    }
                } else {
                    // Fallback: Get all users with student role (no company association)
                    $students_array = $DB->get_records_sql(
                        "SELECT u.id,
                                u.firstname,
                                u.lastname,
                                u.email,
                                u.phone1,
                                u.username,
                                u.suspended,
                                u.lastaccess,
                                '0' as educator,
                                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                                uifd.data AS grade_level
                           FROM {user} u
                           INNER JOIN {role_assignments} ra ON ra.userid = u.id
                           INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                           LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                          WHERE u.deleted = 0
                        GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.username, u.suspended, u.lastaccess, uifd.data",
                        []
                    );
                    $total_students = count($students_array);
                    error_log("Dashboard AJAX: Found " . $total_students . " students using fallback approach (no company association)");
                }
            } catch (Exception $e) {
                error_log("Dashboard AJAX: Error getting students: " . $e->getMessage());
                $total_students = 0;
            }
            
            // Count enrolled/active teachers (teachers who are actively enrolled in courses)
            $enrolled_teachers = 0;
            foreach ($teacher_roles as $role_shortname) {
                $count = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id) 
                     FROM {user} u 
                     JOIN {company_users} cu ON u.id = cu.userid 
                     JOIN {role_assignments} ra ON u.id = ra.userid 
                     JOIN {role} r ON ra.roleid = r.id 
                     JOIN {user_enrolments} ue ON u.id = ue.userid 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     WHERE cu.companyid = ? AND r.shortname = ? AND u.deleted = 0 
                     AND ue.status = 0 AND e.status = 0",
                    [$company_id, $role_shortname]
                );
                $enrolled_teachers += $count;
            }
            
             // Count ONLY courses explicitly assigned to this company (NOT courses available to all schools)
             if ($DB->get_manager()->table_exists('company_course')) {
                 $total_courses = $DB->count_records_sql(
                     "SELECT COUNT(DISTINCT c.id) 
                      FROM {course} c
                      INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
                      WHERE c.visible = 1 
                      AND c.id > 1 
                      AND comp_c.companyid = ?",
                     [$company_id]
                 );
                 error_log("Dashboard AJAX: Found " . $total_courses . " courses explicitly assigned to company ID: " . $company_id);
             } else {
                 // Fallback: count all visible courses if company_course table doesn't exist
                 $total_courses = $DB->count_records_sql(
                     "SELECT COUNT(*) FROM {course} WHERE visible = 1 AND id > 1"
                 );
                 error_log("Dashboard AJAX: Company_course table not found, counting all visible courses: " . $total_courses);
             }
            
            // Count active enrollments - EXACT SAME QUERY AS enrollments.php (EXCLUDE school admins)
            if ($DB->get_manager()->table_exists('company_course')) {
                $active_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.id) 
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON ue.enrolid = e.id
                     JOIN {course} c ON e.courseid = c.id
                     JOIN {company_course} cc ON c.id = cc.courseid
                     JOIN {company_users} cu ON ue.userid = cu.userid
                     LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                     LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
                     LEFT JOIN {role} r ON ra.roleid = r.id
                     WHERE cc.companyid = ? 
                       AND cu.companyid = ? 
                       AND ue.status = 0 
                       AND e.status = 0 
                       AND c.visible = 1
                       AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
                    [$company_id, $company_id]
                );
                error_log("Dashboard AJAX: Found " . $active_enrollments . " active enrollments (excluding school admins) for company ID: " . $company_id);
            } else {
                // Fallback: count all active enrollments if company_course table doesn't exist
                $active_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.id) 
                     FROM {user_enrolments} ue 
                     JOIN {enrol} e ON ue.enrolid = e.id 
                     JOIN {course} c ON e.courseid = c.id 
                     WHERE ue.status = 0 AND e.status = 0 AND c.visible = 1",
                    []
                );
                error_log("Dashboard AJAX: Company_course table not found, counting all active enrollments: " . $active_enrollments);
            }

            $total_parents = theme_remui_kids_get_parent_count($DB, $company_id);
        } else {
            $total_parents = theme_remui_kids_get_parent_count($DB, null);
        }
        
        echo json_encode([
            'status' => 'success',
            'total_teachers' => $total_teachers,
            'enrolled_teachers' => $enrolled_teachers,
            'total_students' => $total_students,
            'total_courses' => $total_courses,
            'active_enrollments' => $active_enrollments,
            'total_parents' => $total_parents,
            'company_name' => $company_info ? $company_info->name : 'Unknown School',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get initial dashboard statistics
$total_teachers = 0;
$total_students = 0;
$total_courses = 0;
$active_enrollments = 0;
$total_parents = 0;

if ($company_info && $DB->get_manager()->table_exists('company_users')) {
    $company_id = $company_info->id;
    
    // OPTIMIZATION: Use COUNT query instead of fetching all records for faster loading
    // Count teachers using the same logic as Teacher Management page but optimized
    $total_teachers = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
         INNER JOIN {role_assignments} ra ON u.id = ra.userid
         INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
         WHERE u.deleted = 0",
        [$company_id]
    );
    
    // Count enrolled/active teachers (educators who are actively enrolled in courses)
    $enrolled_teachers = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id) 
         FROM {user} u 
         JOIN {company_users} cu ON u.id = cu.userid 
         JOIN {user_enrolments} ue ON u.id = ue.userid 
         JOIN {enrol} e ON ue.enrolid = e.id 
         JOIN {course} c ON e.courseid = c.id
         WHERE cu.companyid = ? AND cu.educator = 1 AND u.deleted = 0 AND u.suspended = 0 
         AND ue.status = 0 AND e.status = 0 AND c.visible = 1",
        [$company_id]
    );
    
    // OPTIMIZATION: Use COUNT query instead of fetching all records for faster loading
    // Count students using the same logic but optimized
    try {
        if ($DB->get_manager()->table_exists('company_users')) {
            // Primary query: Count students in company_users with student role (optimized COUNT)
            $total_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id)
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                 WHERE u.deleted = 0",
                [$company_id]
            );
            
            // If no students found, try alternative approach (count only)
            if ($total_students == 0) {
                $total_students = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT u.id)
                     FROM {user} u
                     INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                     WHERE u.deleted = 0 AND cu.educator = 0",
                    [$company_id]
                );
            }
        } else {
            // Fallback: Count all users with student role
            $total_students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id)
                 FROM {user} u
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                 WHERE u.deleted = 0",
                []
            );
        }
    } catch (Exception $e) {
        error_log("Dashboard: Error getting students: " . $e->getMessage());
        $total_students = 0;
    }
    
    
    // OPTIMIZATION: Count enrolled/active teachers in single query instead of loop
    $enrolled_teachers = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id) 
         FROM {user} u 
         JOIN {company_users} cu ON u.id = cu.userid 
         JOIN {role_assignments} ra ON u.id = ra.userid 
         JOIN {role} r ON ra.roleid = r.id 
         JOIN {user_enrolments} ue ON u.id = ue.userid 
         JOIN {enrol} e ON ue.enrolid = e.id 
         WHERE cu.companyid = ? 
         AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
         AND u.deleted = 0 
         AND ue.status = 0 
         AND e.status = 0",
        [$company_id]
    );
    
     // Count ONLY courses explicitly assigned to this company (NOT courses available to all schools)
     if ($DB->get_manager()->table_exists('company_course')) {
         $total_courses = $DB->count_records_sql(
             "SELECT COUNT(DISTINCT c.id) 
              FROM {course} c
              INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
              WHERE c.visible = 1 
              AND c.id > 1 
              AND comp_c.companyid = ?",
             [$company_id]
         );
      } else {
         // Fallback: count all visible courses if company_course table doesn't exist
         $total_courses = $DB->count_records_sql(
             "SELECT COUNT(*) FROM {course} WHERE visible = 1 AND id > 1"
         );
     }
    
    // Count active enrollments - EXACT SAME QUERY AS enrollments.php (EXCLUDE school admins)
    if ($DB->get_manager()->table_exists('company_course')) {
        $active_enrollments = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.id) 
             FROM {user_enrolments} ue
             JOIN {enrol} e ON ue.enrolid = e.id
             JOIN {course} c ON e.courseid = c.id
             JOIN {company_course} cc ON c.id = cc.courseid
             JOIN {company_users} cu ON ue.userid = cu.userid
             LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             LEFT JOIN {role_assignments} ra ON ue.userid = ra.userid AND ra.contextid = ctx.id
             LEFT JOIN {role} r ON ra.roleid = r.id
             WHERE cc.companyid = ? 
               AND cu.companyid = ? 
               AND ue.status = 0 
               AND e.status = 0 
               AND c.visible = 1
               AND (r.shortname IS NULL OR r.shortname NOT IN ('companymanager', 'companycoursenoneditor'))",
            [$company_id, $company_id]
        );
    } else {
        // Fallback: count all active enrollments if company_course table doesn't exist
        $active_enrollments = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.id) 
             FROM {user_enrolments} ue 
             JOIN {enrol} e ON ue.enrolid = e.id 
             JOIN {course} c ON e.courseid = c.id 
             WHERE ue.status = 0 AND e.status = 0 AND c.visible = 1",
            []
        );
    }

    $total_parents = theme_remui_kids_get_parent_count($DB, $company_id);
    
    // ============================================================================
    // OPTIMIZATION: Calendar form data (teachers, students, cohorts, courses)
    // Loaded via AJAX when calendar form is opened - saves 2-3 seconds on page load
    // ============================================================================
    $calendar_teachers = [];
    $calendar_students = [];
    $calendar_cohorts = [];
    $calendar_courses = [];
    
    // Only load calendar form data if explicitly requested (when calendar form opens)
    $load_calendar_form_data = isset($_GET['load_calendar_form']) && $_GET['load_calendar_form'] == '1';
    
    if ($load_calendar_form_data) {
        // GET TEACHERS - Optimized query
        try {
            $calendar_teachers = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
                 FROM {user} u
                 INNER JOIN {company_users} cu ON u.id = cu.userid AND cu.companyid = ? AND cu.managertype = 0
                 INNER JOIN {role_assignments} ra ON u.id = ra.userid
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
                 WHERE u.deleted = 0
                 ORDER BY u.firstname, u.lastname
                 LIMIT 500",
                [$company_id]
            );
        } catch (Exception $e) {
            $calendar_teachers = [];
        }
        
        // GET STUDENTS - Optimized query
        try {
            $calendar_students = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator,
                        GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                        uifd.data AS grade_level
                 FROM {user} u
                 INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                 LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                 LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                 WHERE u.deleted = 0
                 GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator, uifd.data
                 ORDER BY u.firstname, u.lastname
                 LIMIT 1000",
                [$company_id]
            );
            
            // Fallback if no students found
            if (empty($calendar_students)) {
                $calendar_students = $DB->get_records_sql(
                    "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator,
                            'student' as roles, uifd.data AS grade_level
                     FROM {user} u
                     INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
                     LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
                     LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
                     WHERE u.deleted = 0 AND cu.educator = 0
                     GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.phone1, cu.educator, uifd.data
                     ORDER BY u.firstname, u.lastname
                     LIMIT 1000",
                    [$company_id]
                );
            }
        } catch (Exception $e) {
            $calendar_students = [];
        }
        
        // GET COHORTS - Optimized query
        try {
            if ($DB->get_manager()->table_exists('cohort') && $DB->get_manager()->table_exists('cohort_members')) {
                $calendar_cohorts = $DB->get_records_sql(
                    "SELECT DISTINCT c.id, c.name, c.idnumber,
                            (SELECT COUNT(DISTINCT cm.userid)
                             FROM {cohort_members} cm
                             INNER JOIN {user} u ON u.id = cm.userid
                             INNER JOIN {company_users} cu ON cu.userid = u.id
                             INNER JOIN {role_assignments} ra ON ra.userid = u.id
                             INNER JOIN {role} r ON r.id = ra.roleid
                             WHERE cm.cohortid = c.id
                             AND cu.companyid = ?
                             AND r.shortname = 'student'
                             AND u.deleted = 0
                             AND u.suspended = 0) AS student_count
                     FROM {cohort} c
                     WHERE c.visible = 1
                     AND EXISTS (
                         SELECT 1
                         FROM {cohort_members} cm
                         INNER JOIN {user} u ON u.id = cm.userid
                         INNER JOIN {company_users} cu ON cu.userid = u.id
                         INNER JOIN {role_assignments} ra ON ra.userid = u.id
                         INNER JOIN {role} r ON r.id = ra.roleid
                         WHERE cm.cohortid = c.id
                         AND cu.companyid = ?
                         AND r.shortname = 'student'
                         AND u.deleted = 0
                         AND u.suspended = 0
                     )
                     ORDER BY c.name ASC
                     LIMIT 200",
                    [$company_id, $company_id]
                );
            }
        } catch (Exception $e) {
            $calendar_cohorts = [];
        }
        
        // GET COURSES - Optimized query
        try {
            if ($DB->get_manager()->table_exists('company_course')) {
                $calendar_courses = $DB->get_records_sql(
                    "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber
                     FROM {course} c
                     INNER JOIN {company_course} cc ON c.id = cc.courseid
                     WHERE cc.companyid = ? AND c.visible = 1 AND c.id > 1
                     ORDER BY c.fullname
                     LIMIT 500",
                    [$company_id]
                );
            } else {
                $calendar_courses = $DB->get_records_sql(
                    "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber
                     FROM {course} c
                     INNER JOIN {enrol} e ON c.id = e.courseid
                     INNER JOIN {user_enrolments} ue ON e.id = ue.enrolid
                     INNER JOIN {company_users} cu ON ue.userid = cu.userid AND cu.companyid = ?
                     WHERE c.visible = 1 AND c.id > 1
                     GROUP BY c.id, c.fullname, c.shortname, c.idnumber
                     ORDER BY c.fullname
                     LIMIT 500",
                    [$company_id]
                );
            }
        } catch (Exception $e) {
            $calendar_courses = [];
        }
    }
    
} else {
    $company_id = 0;
    $total_parents = 0;
    // Initialize empty arrays for calendar data when company_info is not available
    $calendar_teachers = [];
    $calendar_students = [];
    $calendar_cohorts = [];
    $calendar_courses = [];
}

// ============================================================================
// PERFORMANCE OPTIMIZATION: Lazy Loading Strategy
// ============================================================================
// To improve page load time from 10-15 seconds to < 2 seconds:
// 1. Critical stats (summary cards) load immediately - optimized COUNT queries
// 2. Chart data loads via AJAX after page renders - saves 3-5 seconds
// 3. Calendar events load via AJAX - saves 2-3 seconds  
// 4. Optimized queries use COUNT instead of fetching full records
// ============================================================================

// OPTIMIZATION: Defer heavy chart data loading to AJAX for faster initial page load
$load_charts_immediately = false; // Set to false to enable lazy loading via AJAX
$grade_distribution_data = ['labels' => [], 'data' => [], 'total' => 0, 'rows' => []];
$login_trend_data = ['labels' => [], 'student_logins' => [], 'teacher_logins' => [], 'total_student_logins' => 0, 'total_teacher_logins' => 0, 'avg_per_day' => 0];
$assessment_summary_data = ['assignments' => ['total' => 0, 'graded' => 0, 'pending' => 0], 'quizzes' => ['total' => 0, 'completed' => 0, 'pending' => 0]];
$course_completion_summary = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0, 'percentage' => 0];
$school_overview_data = ['enrollment_summary' => ['total' => 0], 'active_users' => ['active' => 0]];
$role_distribution_data = ['students' => 0, 'teachers' => 0, 'parents' => 0, 'total' => 0];

// Only load chart data if explicitly requested via AJAX parameter
if (isset($_GET['load_charts']) && $_GET['load_charts'] == '1') {
    $grade_distribution_data = theme_remui_kids_get_grade_distribution_data($company_id);
    $login_trend_data = theme_remui_kids_get_login_trend_data($company_id);
    $assessment_summary_data = theme_remui_kids_get_assessment_summary_data($company_id);
    $course_completion_summary = theme_remui_kids_get_course_completion_summary($company_id);
    $school_overview_data = theme_remui_kids_get_school_overview_data($company_id);
    $role_distribution_data = theme_remui_kids_get_user_role_distribution($company_id, $total_students, $total_teachers, $total_parents);
}

// OPTIMIZATION: Removed excessive debug logging and echo statements for better performance

// OPTIMIZATION: Defer calendar data loading to AJAX for faster initial page load
// Calendar data will be loaded asynchronously after page renders
$calendar_events = [];
$upcoming_sessions = [];
$all_calendar_data = [];

// Only load calendar data if explicitly requested (AJAX call)
$load_calendar_immediately = isset($_GET['load_calendar']) && $_GET['load_calendar'] == '1';

if ($company_info && $load_calendar_immediately) {
    $time_start = time();
    $time_end = time() + (90 * 24 * 60 * 60); // Next 90 days
    
    // OPTIMIZATION: Limit calendar events query to prevent overload
    // 1. Get calendar events for courses in this company (limit to 100 most recent)
    $events = $DB->get_records_sql(
        "SELECT e.id, e.name, e.description, e.timestart, e.timeduration, e.eventtype, e.courseid, c.fullname as coursename
         FROM {event} e
         LEFT JOIN {course} c ON c.id = e.courseid
         LEFT JOIN {company_course} cc ON cc.courseid = e.courseid
         WHERE (cc.companyid = ? OR e.courseid = 0 OR e.courseid = 1)
         AND e.timestart >= ?
         AND e.timestart <= ?
         AND e.visible = 1
         ORDER BY e.timestart ASC
         LIMIT 100",
        [$company_info->id, $time_start, $time_end]
    );
    
    foreach ($events as $event) {
        $event_date = new DateTime('@' . $event->timestart);
        $event_end = new DateTime('@' . ($event->timestart + $event->timeduration));
        
        $all_calendar_data[] = [
            'id' => 'event-' . $event->id,
            'title' => $event->name,
            'start' => $event_date->format('Y-m-d\TH:i:s'),
            'end' => $event_end->format('Y-m-d\TH:i:s'),
            'description' => strip_tags($event->description),
            'type' => 'Event',
            'course' => $event->coursename ?? 'General Event',
            'color' => '#3b82f6',
            'textColor' => '#ffffff'
        ];
    }
    
    // OPTIMIZATION: Limit quiz deadlines query
    // 2. Get quiz deadlines (limit to 50)
    $quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, q.timeclose, q.timeopen, c.fullname as coursename
         FROM {quiz} q
         INNER JOIN {course} c ON c.id = q.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ?
         AND q.timeclose > ?
         AND q.timeclose <= ?
         ORDER BY q.timeclose ASC
         LIMIT 50",
        [$company_info->id, $time_start, $time_end]
    );
    
    foreach ($quizzes as $quiz) {
        $quiz_date = new DateTime('@' . $quiz->timeclose);
        
        $all_calendar_data[] = [
            'id' => 'quiz-' . $quiz->id,
            'title' => '📝 Quiz: ' . $quiz->name,
            'start' => $quiz_date->format('Y-m-d\TH:i:s'),
            'description' => 'Quiz deadline',
            'type' => 'Quiz',
            'course' => $quiz->coursename,
            'color' => '#f59e0b',
            'textColor' => '#ffffff'
        ];
    }
    
    // OPTIMIZATION: Limit assignment deadlines query
    // 3. Get assignment deadlines (limit to 50)
    $assignments = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, c.fullname as coursename
         FROM {assign} a
         INNER JOIN {course} c ON c.id = a.course
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ?
         AND a.duedate > ?
         AND a.duedate <= ?
         ORDER BY a.duedate ASC
         LIMIT 50",
        [$company_info->id, $time_start, $time_end]
    );
    
    foreach ($assignments as $assignment) {
        $assign_date = new DateTime('@' . $assignment->duedate);
        
        $all_calendar_data[] = [
            'id' => 'assign-' . $assignment->id,
            'title' => '📄 Assignment: ' . $assignment->name,
            'start' => $assign_date->format('Y-m-d\TH:i:s'),
            'description' => 'Assignment due date',
            'type' => 'Assignment',
            'course' => $assignment->coursename,
            'color' => '#10b981',
            'textColor' => '#ffffff'
        ];
    }
    
    // OPTIMIZATION: Limit course start dates query
    // 4. Get course start dates (limit to 30)
    $course_starts = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.startdate, c.enddate
         FROM {course} c
         INNER JOIN {company_course} cc ON cc.courseid = c.id
         WHERE cc.companyid = ?
         AND c.startdate > ?
         AND c.startdate <= ?
         AND c.visible = 1
         ORDER BY c.startdate ASC
         LIMIT 30",
        [$company_info->id, $time_start, $time_end]
    );
    
    foreach ($course_starts as $course) {
        $course_date = new DateTime('@' . $course->startdate);
        
        $all_calendar_data[] = [
            'id' => 'course-start-' . $course->id,
            'title' => '🎓 Course Start: ' . $course->fullname,
            'start' => $course_date->format('Y-m-d'),
            'description' => 'Course begins',
            'type' => 'Course Start',
            'course' => $course->fullname,
            'color' => '#8b5cf6',
            'textColor' => '#ffffff',
            'allDay' => true
        ];
    }
    
    // Build legacy calendar_events array for backward compatibility
    foreach ($all_calendar_data as $item) {
        if (count($calendar_events) < 50) {
            $date_obj = new DateTime($item['start']);
            $calendar_events[] = [
                'id' => $item['id'],
                'name' => $item['title'],
                'description' => $item['description'],
                'start_time' => $date_obj->format('H:i'),
                'date' => $date_obj->format('Y-m-d'),
                'formatted_date' => $date_obj->format('M d, Y'),
                'color' => $item['color'],
                'course_name' => $item['course'],
                'event_type' => $item['type']
            ];
        }
        
        // Add to upcoming sessions (first 10)
        if (count($upcoming_sessions) < 10) {
            $date_obj = new DateTime($item['start']);
            $upcoming_sessions[] = [
                'title' => $item['title'],
                'date' => $date_obj->format('M d, Y'),
                'time' => $date_obj->format('H:i'),
                'location' => $item['course'],
                'color' => $item['color'],
                'type' => $item['type']
            ];
        }
    }
}

// OPTIMIZATION: Build calendar JSON strings BEFORE template context (only if data is loaded)
// Initialize as empty JSON arrays if calendar form data wasn't loaded
$calendar_teachers_json = '[]';
$calendar_students_json = '[]';
$calendar_cohorts_json = '[]';
$calendar_courses_json = '[]';

if (!empty($calendar_teachers)) {
    $calendar_teachers_json = json_encode(array_map(function($t) {
        return [
            'id' => (int)$t->id,
            'firstname' => isset($t->firstname) ? $t->firstname : '',
            'lastname' => isset($t->lastname) ? $t->lastname : '',
            'email' => isset($t->email) ? $t->email : '',
            'fullname' => trim((isset($t->firstname) ? $t->firstname : '') . ' ' . (isset($t->lastname) ? $t->lastname : ''))
        ];
    }, array_values($calendar_teachers)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
}

if (!empty($calendar_students)) {
    $calendar_students_json = json_encode(array_map(function($s) {
        return [
            'id' => (int)$s->id,
            'firstname' => isset($s->firstname) ? $s->firstname : '',
            'lastname' => isset($s->lastname) ? $s->lastname : '',
            'email' => isset($s->email) ? $s->email : '',
            'fullname' => trim((isset($s->firstname) ? $s->firstname : '') . ' ' . (isset($s->lastname) ? $s->lastname : ''))
        ];
    }, array_values($calendar_students)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
}

if (!empty($calendar_cohorts)) {
    $calendar_cohorts_json = json_encode(array_map(function($c) {
        return [
            'id' => (int)$c->id,
            'name' => isset($c->name) ? $c->name : '',
            'idnumber' => isset($c->idnumber) ? $c->idnumber : '',
            'student_count' => isset($c->student_count) ? (int)$c->student_count : 0
        ];
    }, array_values($calendar_cohorts)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
}

if (!empty($calendar_courses)) {
    $calendar_courses_json = json_encode(array_map(function($c) {
        return [
            'id' => (int)$c->id,
            'fullname' => isset($c->fullname) ? $c->fullname : '',
            'shortname' => isset($c->shortname) ? $c->shortname : '',
            'idnumber' => isset($c->idnumber) ? $c->idnumber : ''
        ];
    }, array_values($calendar_courses)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
}

// Prepare template context with default Moodle output
$templatecontext = [
    'company_name' => $company_info ? $company_info->name : 'School Dashboard',
    'company_info' => $company_info,
    'company_logo_url' => $company_info && isset($company_info->logo_filename) 
        ? $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id 
        : null,
    'has_logo' => $company_info && isset($company_info->logo_filename),
    'total_teachers' => $total_teachers,
    'enrolled_teachers' => $enrolled_teachers,
    'total_students' => $total_students,
    'total_courses' => $total_courses,
    'active_enrollments' => $active_enrollments,
    'total_parents' => $total_parents,
    'calendar_events' => $calendar_events,
    'calendar_events_json' => json_encode($calendar_events),
    'all_calendar_data_json' => json_encode($all_calendar_data),
    'upcoming_sessions' => $upcoming_sessions,
    'has_events' => !empty($all_calendar_data),
    'timestamp' => time(),
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'user_info' => [
        'fullname' => fullname($USER),
        'email' => $USER->email
    ],
    'grade_distribution' => $grade_distribution_data,
    'grade_distribution_rows' => $grade_distribution_data['rows'] ?? [],
    'grade_distribution_json' => json_encode($grade_distribution_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'login_trend' => $login_trend_data,
    'login_trend_json' => json_encode($login_trend_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'assessment_summary' => $assessment_summary_data,
    'assessment_summary_json' => json_encode($assessment_summary_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'course_completion_summary' => $course_completion_summary,
    'course_completion_json' => json_encode($course_completion_summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'school_overview' => $school_overview_data,
    'school_overview_json' => json_encode($school_overview_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'role_distribution' => $role_distribution_data,
    'role_distribution_json' => json_encode($role_distribution_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'calendar_teachers' => array_values($calendar_teachers),
    'calendar_students' => array_values($calendar_students),
    'calendar_cohorts' => array_values($calendar_cohorts),
    'calendar_courses' => array_values($calendar_courses),
    'calendar_teachers_count' => count($calendar_teachers),
    'calendar_students_count' => count($calendar_students),
    'calendar_cohorts_count' => count($calendar_cohorts),
    'calendar_courses_count' => count($calendar_courses),
    'calendar_teachers_json' => $calendar_teachers_json,
    'calendar_students_json' => $calendar_students_json,
    'calendar_cohorts_json' => $calendar_cohorts_json,
    'calendar_courses_json' => $calendar_courses_json,
    'sesskey' => sesskey(),
    // OPTIMIZATION: Flags to enable lazy loading
    'lazy_load_charts' => true, // Enable AJAX loading of charts
    'lazy_load_calendar' => true, // Enable AJAX loading of calendar events
    'lazy_load_calendar_form' => true, // Enable AJAX loading of calendar form data (teachers, students, cohorts, courses)
    'company_id' => $company_id ?? 0
];

echo $OUTPUT->header();

// Generate default Moodle content after header is called
// The output object will be available in the template through the renderer

// School Manager Sidebar Navigation
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School Dashboard',
    'company_logo_url' => $company_info && isset($company_info->logo_filename) 
        ? $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id 
        : null,
    'has_logo' => $company_info && isset($company_info->logo_filename),
    'user_info' => [
        'fullname' => fullname($USER),
        'email' => $USER->email,
        'id' => $USER->id
    ],
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'dashboard_active' => true,
    'sesskey' => sesskey()
];

echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);

// Main content area
echo "<div class='school-manager-main-content'>";

// Dashboard content will be rendered here
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_dashboard', $templatecontext);

// Add Interactive Calendar Section
echo "<div style='max-width: 1600px; margin: 0 auto; padding: 30px;'>";
echo "<div style='background: white; border-radius: 20px; padding: 35px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); margin-top: 30px;'>";
echo "<h2 style='font-size: 1.8rem; font-weight: 700; color: #1f2937; margin: 0 0 10px 0; display: flex; align-items: center; gap: 12px;'>";
echo "<i class='fa fa-calendar-alt' style='color: #8b5cf6;'></i> School Schedule Calendar";
echo "</h2>";
echo "<p style='color: #6b7280; margin-bottom: 30px; font-size: 1rem;'>View all upcoming events, quizzes, assignments, and course schedules</p>";

// Calendar Legend
echo "<div style='display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap; padding: 15px; background: #f9fafb; border-radius: 10px;'>";
echo "<div style='display: flex; align-items: center; gap: 8px;'>";
echo "<div style='width: 18px; height: 18px; background: #3b82f6; border-radius: 4px;'></div>";
echo "<span style='font-size: 0.9rem; color: #374151; font-weight: 500;'>Events</span>";
echo "</div>";
echo "<div style='display: flex; align-items: center; gap: 8px;'>";
echo "<div style='width: 18px; height: 18px; background: #f59e0b; border-radius: 4px;'></div>";
echo "<span style='font-size: 0.9rem; color: #374151; font-weight: 500;'>Quizzes</span>";
echo "</div>";
echo "<div style='display: flex; align-items: center; gap: 8px;'>";
echo "<div style='width: 18px; height: 18px; background: #10b981; border-radius: 4px;'></div>";
echo "<span style='font-size: 0.9rem; color: #374151; font-weight: 500;'>Assignments</span>";
echo "</div>";
echo "<div style='display: flex; align-items: center; gap: 8px;'>";
echo "<div style='width: 18px; height: 18px; background: #8b5cf6; border-radius: 4px;'></div>";
echo "<span style='font-size: 0.9rem; color: #374151; font-weight: 500;'>Course Starts</span>";
echo "</div>";
echo "</div>";

// Calendar Container
echo "<div id='calendar'></div>";
echo "</div>";

// Upcoming Events Sidebar
if (!empty($upcoming_sessions)) {
    echo "<div style='background: white; border-radius: 20px; padding: 30px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); margin-top: 30px;'>";
    echo "<h3 style='font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px;'>";
    echo "<i class='fa fa-clock' style='color: #3b82f6;'></i> Upcoming Events";
    echo "</h3>";
    echo "<div style='display: flex; flex-direction: column; gap: 15px;'>";
    foreach (array_slice($upcoming_sessions, 0, 5) as $session) {
        echo "<div style='padding: 15px; background: #f9fafb; border-radius: 12px; border-left: 4px solid " . htmlspecialchars($session['color']) . "; transition: all 0.3s ease; cursor: pointer;' onmouseover='this.style.background=\"#f1f5f9\"; this.style.transform=\"translateX(5px)\";' onmouseout='this.style.background=\"#f9fafb\"; this.style.transform=\"translateX(0)\";'>";
        echo "<div style='font-weight: 600; font-size: 1rem; color: #1f2937; margin-bottom: 5px;'>" . htmlspecialchars($session['title']) . "</div>";
        echo "<div style='display: flex; gap: 15px; font-size: 0.85rem; color: #6b7280;'>";
        echo "<span><i class='fa fa-calendar'></i> " . htmlspecialchars($session['date']) . "</span>";
        echo "<span><i class='fa fa-clock'></i> " . htmlspecialchars($session['time']) . "</span>";
        echo "</div>";
        echo "<div style='font-size: 0.8rem; color: #9ca3af; margin-top: 5px;'>";
        echo "<i class='fa fa-book'></i> " . htmlspecialchars($session['location']);
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";
    echo "</div>";
}
echo "</div>";

echo "</div>"; // End school-manager-main-content

// Add FullCalendar CSS and JS
echo "<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css' rel='stylesheet' />";
echo "<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>";

// Add CSS for school manager main content
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #f8f9fa;
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Main content area */
    .school-manager-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #f8f9fa;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px;
        margin-top: 0;
        padding-left: 0;
        padding-right: 0;
    }
    
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .school-manager-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }
    
    /* FullCalendar Customization */
    .fc {
        font-family: 'Inter', sans-serif;
    }
    
    .fc .fc-toolbar-title {
        font-size: 1.5rem !important;
        font-weight: 700 !important;
        color: #1f2937 !important;
    }
    
    .fc .fc-button-primary {
        background: linear-gradient(135deg, #667eea, #764ba2) !important;
        border: none !important;
        padding: 10px 20px !important;
        font-weight: 600 !important;
        transition: all 0.3s ease !important;
    }
    
    .fc .fc-button-primary:hover {
        background: linear-gradient(135deg, #764ba2, #667eea) !important;
        transform: translateY(-2px) !important;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4) !important;
    }
    
    .fc .fc-button-primary:not(:disabled):active,
    .fc .fc-button-primary:not(:disabled).fc-button-active {
        background: linear-gradient(135deg, #5a67d8, #6b46c1) !important;
    }
    
    .fc .fc-daygrid-day-number {
        font-weight: 600 !important;
        color: #374151 !important;
    }
    
    .fc .fc-col-header-cell-cushion {
        font-weight: 700 !important;
        color: #1f2937 !important;
        text-transform: uppercase !important;
        font-size: 0.85rem !important;
    }
    
    .fc .fc-daygrid-day.fc-day-today {
        background: rgba(102, 126, 234, 0.08) !important;
    }
    
    .fc .fc-event {
        border-radius: 6px !important;
        padding: 2px 6px !important;
        font-weight: 600 !important;
        font-size: 0.85rem !important;
        border: none !important;
        cursor: pointer !important;
        transition: all 0.2s ease !important;
    }
    
    .fc .fc-event:hover {
        transform: translateY(-1px) !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
    }
</style>";

echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    
    if (calendarEl) {
        const calendarEvents = " . $templatecontext['all_calendar_data_json'] . ";
        
        console.log('Initializing calendar with ' + calendarEvents.length + ' events');
        
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek,listWeek'
            },
            height: 'auto',
            events: calendarEvents,
            eventClick: function(info) {
                var event = info.event;
                var details = 'Title: ' + event.title + '\\n';
                details += 'Type: ' + (event.extendedProps.type || 'Event') + '\\n';
                details += 'Course: ' + (event.extendedProps.course || 'N/A') + '\\n';
                details += 'Date: ' + event.start.toLocaleDateString() + '\\n';
                if (!event.allDay) {
                    details += 'Time: ' + event.start.toLocaleTimeString();
                }
                if (event.extendedProps.description) {
                    details += '\\n\\nDescription: ' + event.extendedProps.description;
                }
                
                alert(details);
            },
            eventDidMount: function(info) {
                info.el.title = info.event.extendedProps.course + ' - ' + info.event.title;
            },
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            },
            slotLabelFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            },
            views: {
                dayGridMonth: {
                    titleFormat: { year: 'numeric', month: 'long' }
                },
                dayGridWeek: {
                    titleFormat: { month: 'long', day: 'numeric', year: 'numeric' }
                }
            },
            buttonText: {
                today: 'Today',
                month: 'Month',
                week: 'Week',
                list: 'List'
            },
            noEventsContent: 'No scheduled events to display',
            eventDisplay: 'block',
            displayEventTime: true,
            displayEventEnd: false,
            firstDay: 1,
            weekNumbers: true,
            weekText: 'W',
            dayMaxEvents: 3,
            moreLinkText: function(num) {
                return '+' + num + ' more';
            },
            moreLinkClick: 'popover'
        });
        
        calendar.render();
        
        console.log('Calendar initialized successfully with ' + calendarEvents.length + ' events');
    } else {
        console.error('Calendar element not found');
    }
});
</script>";

// Add default Moodle dashboard content (blocks and main content)
echo "<div class='default-moodle-content' style='padding: 20px; background: #f8fafc; margin-top: 30px; border-radius: 12px; max-width: 1600px; margin-left: auto; margin-right: auto;'>";
echo "<div id='region-main-box' class='d-print-block'>";
echo "<section id='region-main' class='d-print-block' aria-label='Content'>";
echo $OUTPUT->course_content_header();
echo "<div class='rui-course-content'>";
// Output the main content area - this will show default Moodle dashboard blocks
echo $OUTPUT->custom_block_region('content');
echo "</div>";
echo $OUTPUT->course_content_footer();
echo "</section>";
echo "</div>";
echo "</div>"; // Close default-moodle-content

echo $OUTPUT->footer();
?>
