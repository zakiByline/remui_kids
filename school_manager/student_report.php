<?php
/**
 * Student Report - Default Tab
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

$ajaxrequest = optional_param('ajax', 0, PARAM_BOOL);

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

// Get student summary statistics
$student_stats = [
    'total_students' => 0,
    'active_students' => 0,
    'enrolled_students' => 0,
    'completed_courses' => 0,
    'average_completion_rate' => 0
];

if ($company_info) {
    // Total students
    $student_stats['total_students'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$company_info->id]
    );
    
    // Active students (last 30 days)
    $student_stats['active_students'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND u.lastaccess >= ?",
        [$company_info->id, strtotime('-30 days')]
    );
    
    // Enrolled students
    $student_stats['enrolled_students'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND ue.status = 0
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$company_info->id]
    );
    
    // Completed courses count
    $student_stats['completed_courses'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT cc.id)
         FROM {course_completions} cc
         INNER JOIN {user} u ON u.id = cc.userid
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND cc.timecompleted IS NOT NULL",
        [$company_info->id]
    );
    
    // Course completion status distribution for pie chart
    // Students who have completed at least one course
    $completed_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {course_completions} cc ON cc.userid = u.id
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
         INNER JOIN {company_course} cc_link ON cc_link.courseid = e.courseid
         WHERE cu.companyid = ?
         AND cc_link.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND cc.timecompleted IS NOT NULL",
        [$company_info->id, $company_info->id]
    );
    
    // Students who are enrolled, have activity, but haven't completed any course
    $in_progress_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {company_course} cc_link ON cc_link.courseid = e.courseid
         LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
         WHERE cu.companyid = ?
         AND cc_link.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND ula.timeaccess IS NOT NULL
         AND u.id NOT IN (
             SELECT DISTINCT cc.userid
             FROM {course_completions} cc
             INNER JOIN {user_enrolments} ue2 ON ue2.userid = cc.userid
             INNER JOIN {enrol} e2 ON e2.id = ue2.enrolid AND e2.courseid = cc.course
             WHERE cc.timecompleted IS NOT NULL
             AND ue2.status = 0
         )",
        [$company_info->id, $company_info->id]
    );
    
    // Students who are enrolled but haven't started (no activity)
    $not_started_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {company_course} cc_link ON cc_link.courseid = e.courseid
         LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
         WHERE cu.companyid = ?
         AND cc_link.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND ue.status = 0
         AND ula.timeaccess IS NULL
         AND u.id NOT IN (
             SELECT DISTINCT cc.userid
             FROM {course_completions} cc
             WHERE cc.timecompleted IS NOT NULL
         )",
        [$company_info->id, $company_info->id]
    );
    
    // Calculate percentages
    $total_enrolled = $completed_students + $in_progress_students + $not_started_students;
    $completion_data = [
        'completed' => $completed_students,
        'in_progress' => $in_progress_students,
        'not_started' => $not_started_students,
        'total' => $total_enrolled,
        'completed_percent' => $total_enrolled > 0 ? round(($completed_students / $total_enrolled) * 100, 1) : 0,
        'in_progress_percent' => $total_enrolled > 0 ? round(($in_progress_students / $total_enrolled) * 100, 1) : 0,
        'not_started_percent' => $total_enrolled > 0 ? round(($not_started_students / $total_enrolled) * 100, 1) : 0
    ];
} else {
    $completion_data = [
        'completed' => 0,
        'in_progress' => 0,
        'not_started' => 0,
        'total' => 0,
        'completed_percent' => 0,
        'in_progress_percent' => 0,
        'not_started_percent' => 0
    ];
}

// Fetch cohorts with student counts
$cohorts_list = [];
if ($company_info) {
    $cohorts = $DB->get_records_sql(
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
         ORDER BY c.name ASC",
        [$company_info->id, $company_info->id]
    );
    
    foreach ($cohorts as $cohort) {
        $cohorts_list[] = [
            'id' => (int)$cohort->id,
            'name' => $cohort->name,
            'idnumber' => $cohort->idnumber ?? '',
            'student_count' => (int)$cohort->student_count
        ];
    }
}

// Student performance list (search + pagination + cohort filter)
$students_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$cohort_filter = isset($_GET['cohort']) ? (int)$_GET['cohort'] : 0;
$offset = ($current_page - 1) * $students_per_page;

$students_list = [];
$total_students_count = 0;
$total_unfiltered_students_count = 0;

if ($company_info) {
    // Calculate total unfiltered students count (for "All Students" display)
    $total_unfiltered_sql = "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0";
    $total_unfiltered_students_count = $DB->count_records_sql($total_unfiltered_sql, [$company_info->id]);

    $search_condition = '';
    $search_params = [];
    if ($search_query !== '') {
        $search_condition = " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR uifd.data LIKE ?)";
        $search_term = '%' . $search_query . '%';
        $search_params = [$search_term, $search_term, $search_term, $search_term];
    }

    $cohort_condition = '';
    $cohort_params = [];
    if ($cohort_filter > 0) {
        $cohort_condition = " AND EXISTS (
            SELECT 1
            FROM {cohort_members} cm
            WHERE cm.userid = u.id
            AND cm.cohortid = ?
        )";
        $cohort_params = [$cohort_filter];
    }

    $count_sql = "SELECT COUNT(DISTINCT u.id)
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
         {$search_condition}
         {$cohort_condition}";
    $count_params = array_merge([$company_info->id], $search_params, $cohort_params);
    $total_students_count = $DB->count_records_sql($count_sql, $count_params);

    $students_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                uifd.data AS grade_level
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
         {$search_condition}
         {$cohort_condition}
         ORDER BY u.lastname ASC, u.firstname ASC";
    $students_params = array_merge([$company_info->id], $search_params, $cohort_params);
    $students = $DB->get_records_sql($students_sql, $students_params, $offset, $students_per_page);

    foreach ($students as $student) {
        // Total courses per student
        $course_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {user_enrolments} ue ON ue.userid = ?
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ?
             AND ue.status = 0
             AND c.visible = 1
             AND c.id > 1",
            [$student->id, $company_info->id]
        );

        // Completed courses per student
        $completed_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.course)
             FROM {course_completions} cc
             INNER JOIN {user_enrolments} ue ON ue.userid = cc.userid
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
             INNER JOIN {company_course} cc_link ON cc_link.courseid = cc.course
             WHERE cc.userid = ?
             AND cc_link.companyid = ?
             AND cc.timecompleted IS NOT NULL
             AND ue.status = 0",
            [$student->id, $company_info->id]
        );

        // Average grade
        $avg_grade_record = $DB->get_record_sql(
            "SELECT AVG((gg.finalgrade / gi.grademax) * 100) AS avg_grade
             FROM {grade_grades} gg
             INNER JOIN {grade_items} gi ON gi.id = gg.itemid
             INNER JOIN {course} c ON c.id = gi.courseid
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE gg.userid = ?
             AND cc.companyid = ?
             AND gi.itemtype = 'course'
             AND gg.finalgrade IS NOT NULL
             AND gi.grademax > 0",
            [$student->id, $company_info->id]
        );

        $average_grade = ($avg_grade_record && $avg_grade_record->avg_grade !== null)
            ? round((float)$avg_grade_record->avg_grade, 1)
            : null;

        $completion_rate = $course_count > 0
            ? round(($completed_count / $course_count) * 100, 1)
            : 0;

        if ($course_count === 0) {
            $performance_label = 'N/A';
            $performance_class = 'performance-na';
        } elseif ($completion_rate >= 80) {
            $performance_label = 'Excellent';
            $performance_class = 'performance-excellent';
        } elseif ($completion_rate >= 60) {
            $performance_label = 'Good';
            $performance_class = 'performance-good';
        } elseif ($completion_rate >= 40) {
            $performance_label = 'Average';
            $performance_class = 'performance-average';
        } else {
            $performance_label = 'Needs Improvement';
            $performance_class = 'performance-poor';
        }

        $students_list[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'grade_level' => $student->grade_level ?? 'Not Assigned',
            'course_count' => $course_count,
            'completed_count' => $completed_count,
            'last_access' => $student->lastaccess ? userdate($student->lastaccess, get_string('strftimedatefullshort')) : get_string('never'),
            'completion_rate' => $completion_rate,
            'average_grade' => $average_grade,
            'performance_label' => $performance_label,
            'performance_class' => $performance_class
        ];
    }
}

$total_pages = $total_students_count > 0 ? (int)ceil($total_students_count / $students_per_page) : 1;
$total_pages = max(1, $total_pages);

if (!function_exists('theme_remui_kids_render_student_table')) {
    function theme_remui_kids_render_student_table($students_list, $students_per_page, $total_students_count, $current_page, $total_pages, $offset, $search_query, $cohort_filter = 0, $cohorts_list = [], $total_unfiltered_students_count = 0)
    {
        ob_start();
        ?>
        <div class="student-performance-container">
            <?php if (!empty($students_list)): ?>
            <div class="student-list-card">
                <div style="overflow-x: auto;">
                    <table class="student-performance-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Grade Level</th>
                                <th style="text-align:center;">Total Courses</th>
                                <th style="text-align:center;">Completed</th>
                                <th>Completion Rate</th>
                                <th>Average Grade</th>
                                <th>Performance</th>
                                <th>Last Access</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_list as $student): ?>
                                <?php
                                    $completionRate = (float)$student['completion_rate'];
                                    if ($completionRate >= 80) {
                                        $completionClass = 'high';
                                    } elseif ($completionRate >= 40) {
                                        $completionClass = 'medium';
                                    } elseif ($completionRate > 0) {
                                        $completionClass = 'low';
                                    } else {
                                        $completionClass = 'none';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-name-cell">
                                            <a href="<?php echo new moodle_url('/theme/remui_kids/school_manager/student_report_detail.php', ['studentid' => $student['id']]); ?>" 
                                               style="font-weight:600; color: #3b82f6; text-decoration: none; cursor: pointer;">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                            </a>
                                            <small><?php echo htmlspecialchars($student['email']); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['grade_level']); ?></td>
                                    <td style="text-align:center;">
                                        <span class="average-grade-badge grade-na" style="background: rgba(99,102,241,0.15); color:#3730a3;">
                                            <?php echo $student['course_count']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="average-grade-badge grade-high" style="background: rgba(16,185,129,0.15); color:#047857;">
                                            <?php echo $student['completed_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="completion-progress">
                                            <div class="completion-progress-bar">
                                                <div class="completion-progress-fill <?php echo $completionClass; ?>" style="width: <?php echo min(100, $completionRate); ?>%;"></div>
                                            </div>
                                            <span class="completion-progress-value"><?php echo number_format($completionRate, 1); ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($student['average_grade'] === null): ?>
                                            <span class="average-grade-badge grade-na">No grades</span>
                                        <?php else: ?>
                                            <?php
                                                $avgGrade = $student['average_grade'];
                                                if ($avgGrade >= 80) {
                                                    $gradeBadgeClass = 'grade-high';
                                                } elseif ($avgGrade >= 60) {
                                                    $gradeBadgeClass = 'grade-medium';
                                                } elseif ($avgGrade > 0) {
                                                    $gradeBadgeClass = 'grade-low';
                                                } else {
                                                    $gradeBadgeClass = 'grade-na';
                                                }
                                            ?>
                                            <span class="average-grade-badge <?php echo $gradeBadgeClass; ?>"><?php echo number_format($avgGrade, 1); ?>%</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="performance-badge <?php echo $student['performance_class']; ?>">
                                            <?php echo htmlspecialchars($student['performance_label']); ?>
                                        </span>
                                    </td>
                                    <td style="color: #6b7280;"><?php echo htmlspecialchars($student['last_access']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="student-pagination">
                    <div class="page-info">
                        Showing <?php echo $offset + 1; ?> -
                        <?php echo min($offset + $students_per_page, $total_students_count); ?> of
                        <?php echo number_format($total_students_count); ?> student(s)
                    </div>
                    <div class="page-buttons">
                        <?php 
                        $query_params = [];
                        if ($search_query !== '') {
                            $query_params[] = 'search=' . urlencode($search_query);
                        }
                        if ($cohort_filter > 0) {
                            $query_params[] = 'cohort=' . $cohort_filter;
                        }
                        $query_suffix = !empty($query_params) ? '&' . implode('&', $query_params) : '';
                        ?>
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1 . $query_suffix; ?>">
                                <i class="fa fa-chevron-left"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fa fa-chevron-left"></i> Previous</span>
                        <?php endif; ?>

                        <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            if ($start_page > 1) {
                                echo '<a href="?page=1' . $query_suffix . '">1</a>';
                                if ($start_page > 2) {
                                    echo '<span class="disabled">...</span>';
                                }
                            }
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $current_page) {
                                    echo '<span class="active">' . $i . '</span>';
                                } else {
                                    echo '<a href="?page=' . $i . $query_suffix . '">' . $i . '</a>';
                                }
                            }
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<span class="disabled">...</span>';
                                }
                                echo '<a href="?page=' . $total_pages . $query_suffix . '">' . $total_pages . '</a>';
                            }
                        ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1 . $query_suffix; ?>">
                                Next <i class="fa fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">Next <i class="fa fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="student-empty-state">
                    <i class="fa fa-user-graduate"></i>
                    <p style="color: #6b7280; font-weight: 600; margin: 0;">
                        <?php echo $search_query !== '' ? 'No students found matching your search.' : 'No student performance data available yet.'; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if ($ajaxrequest) {
    echo theme_remui_kids_render_student_table(
        $students_list,
        $students_per_page,
        $total_students_count,
        $current_page,
        $total_pages,
        $offset,
        $search_query,
        $cohort_filter,
        $cohorts_list,
        $total_unfiltered_students_count
    );
    exit;
}

// Page configuration
$context = context_system::instance();
$PAGE->set_context($context);
$page_url = new moodle_url('/theme/remui_kids/school_manager/student_report.php');
if ($search_query !== '') {
    $page_url->param('search', $search_query);
}
if ($current_page > 1) {
    $page_url->param('page', $current_page);
}
$PAGE->set_url($page_url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Student Report');
$PAGE->set_heading('Student Report');

$allowed_student_tabs = ['summary', 'academic', 'engagements', 'inactive', 'quizassignmentreports', 'progress', 'activitylog'];
$initial_tab = optional_param('tab', 'summary', PARAM_ALPHANUMEXT);
if (!in_array($initial_tab, $allowed_student_tabs, true)) {
    $initial_tab = 'summary';
}

// Get subtab parameter for quizassignmentreports
$initial_subtab = optional_param('subtab', 'quiz', PARAM_ALPHANUMEXT);
if (!in_array($initial_subtab, ['quiz', 'assignment'], true)) {
    $initial_subtab = 'quiz';
}

$tab_file_map = [
    'academic' => 'student_report_academic.php',
    'engagements' => 'student_report_engagements.php',
    'inactive' => 'student_report_inactive.php',
    'quizassignmentreports' => 'student_report_quiz_assignment_reports.php',
    'progress' => 'student_report_progress.php',
    'activitylog' => 'student_report_activitylog.php'
];

$tab_urls = [
    'summary' => clone $page_url,
];

foreach ($tab_file_map as $tab_key => $file_name) {
    $file_path = __DIR__ . '/' . $file_name;
    if (file_exists($file_path)) {
        $tab_url = new moodle_url('/theme/remui_kids/school_manager/' . $file_name);
        // Add subtab parameter for quizassignmentreports
        if ($tab_key === 'quizassignmentreports') {
            $tab_url->param('subtab', $initial_subtab);
        }
        $tab_urls[$tab_key] = $tab_url;
    } else {
        $tab_urls[$tab_key] = null;
    }
}

$tab_url_map = [];
foreach ($tab_urls as $key => $url_obj) {
    $tab_url_map[$key] = $url_obj instanceof moodle_url ? $url_obj->out(false) : null;
}

$tab_labels = [
    'summary' => 'Student Report',
    'academic' => 'Academic',
    'engagements' => 'Student Engagement',
    'inactive' => 'Inactive Student',
    'quizassignmentreports' => 'Quiz & Assignment Reports',
    'progress' => 'Progress',
    'activitylog' => 'Student Log Activity'
];

$summary_url_string = $tab_urls['summary'] instanceof moodle_url
    ? $tab_urls['summary']->out(false)
    : (new moodle_url('/theme/remui_kids/school_manager/student_report.php'))->out(false);

$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'student_repo',
    'student_report_active' => true,
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
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 10px 0;
    color: #36454f;
}

.page-subtitle {
    font-size: 1.1rem;
    margin: 0;
    color: #696969;
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
    width: 100%;
}

.tabs-nav {
    display: flex;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 25px;
    flex-wrap: nowrap;
    overflow-x: visible;
    white-space: nowrap;
    width: 100%;
    justify-content: space-around;
    align-items: flex-end;
    gap: 4px;
}

.tab-button {
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    bottom: -2px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
    flex: 1 1 auto;
    min-width: 0;
    max-width: 100%;
    text-align: center;
}

.tab-button i {
    font-size: 0.9rem;
    flex-shrink: 0;
}

.tab-button:hover {
    color: #3b82f6;
    background: #f9fafb;
    border-radius: 8px 8px 0 0;
}

.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 600;
    background: transparent;
}

.tab-button.active:hover {
    background: #f0f9ff;
    border-radius: 8px 8px 0 0;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

#student-tab-content {
    position: relative;
}

.tab-loading-state {
    margin-top: 30px;
    display: none;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: #475569;
}

.tab-loading-state.visible {
    display: inline-flex;
}

.tab-loading-state .spinner {
    width: 26px;
    height: 26px;
    border-radius: 999px;
    border: 3px solid #e2e8f0;
    border-top-color: #6366f1;
    animation: tab-spin 0.85s linear infinite;
}

@keyframes tab-spin {
    to {
        transform: rotate(360deg);
    }
}

.tab-placeholder,
.tab-error-message {
    margin-top: 30px;
    text-align: center;
    padding: 60px 30px;
    border-radius: 16px;
    border: 1px dashed #cbd5f5;
    background: #f8fafc;
    color: #64748b;
}

.tab-placeholder i,
.tab-error-message i {
    font-size: 2.6rem;
    margin-bottom: 12px;
    color: #cbd5f5;
}

.tab-placeholder h4,
.tab-error-message h4 {
    margin: 10px 0 6px;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
}

.tab-placeholder p,
.tab-error-message p {
    margin: 0;
    font-size: 0.95rem;
    color: #6b7280;
}

.report-table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%);
    border-radius: 14px;
    padding: 24px;
    border: 1px solid rgba(59, 130, 246, 0.18);
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.stat-card .stat-label {
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #64748b;
    font-weight: 600;
}

.stat-card .stat-value {
    font-size: 2.4rem;
    font-weight: 800;
    color: #1d4ed8;
    line-height: 1;
}

.stat-card .stat-subtext {
    font-size: 0.85rem;
    color: #475569;
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

.student-list-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
}

.student-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.student-search-form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.student-search-input {
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    min-width: 260px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.student-search-input:focus {
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.student-search-btn,
.student-clear-btn {
    padding: 11px 18px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.student-search-btn {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #ffffff;
}

.student-clear-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff;
}

.student-search-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 16px rgba(139, 92, 246, 0.35);
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
}

.student-clear-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 16px rgba(239, 68, 68, 0.35);
}

.student-performance-container {
    width: 100%;
}

.search-field-dropdown-wrapper {
    position: relative;
}

.search-field-dropdown {
    padding: 12px 16px;
    padding-right: 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    background: #ffffff;
    color: #1f2937;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23334155' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 12px;
    min-width: 180px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.search-field-dropdown:focus {
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.search-field-dropdown:hover {
    border-color: #9ca3af;
}

.student-performance-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    min-width: 1000px;
}

.student-performance-table thead th {
    padding: 14px;
    text-align: left;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    border-bottom: 2px solid #e5e7eb;
}

.student-performance-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #1f2937;
}

.student-name-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.student-name-cell a {
    transition: color 0.2s ease;
}

.student-name-cell a:hover {
    color: #2563eb !important;
    text-decoration: underline !important;
}

.student-name-cell small {
    color: #94a3b8;
    font-size: 0.82rem;
}

.completion-progress {
    display: flex;
    align-items: center;
    gap: 12px;
}

.completion-progress-bar {
    flex: 1;
    height: 10px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
}

.completion-progress-fill {
    height: 100%;
    border-radius: 999px;
}

.completion-progress-fill.high { background: linear-gradient(90deg, #22c55e, #16a34a); }
.completion-progress-fill.medium { background: linear-gradient(90deg, #f97316, #ea580c); }
.completion-progress-fill.low { background: linear-gradient(90deg, #facc15, #eab308); }
.completion-progress-fill.none { background: linear-gradient(90deg, #e5e7eb, #cbd5f5); }

.completion-progress-value {
    font-weight: 700;
    color: #1f2937;
    min-width: 48px;
    text-align: right;
}

.average-grade-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 999px;
    font-weight: 700;
    min-width: 70px;
}

.grade-high { background: rgba(16, 185, 129, 0.15); color: #047857; }
.grade-medium { background: rgba(251, 191, 36, 0.18); color: #b45309; }
.grade-low { background: rgba(248, 113, 113, 0.18); color: #b91c1c; }
.grade-na { background: #f1f5f9; color: #94a3b8; }

.performance-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 700;
}

.performance-excellent { background: rgba(16, 185, 129, 0.15); color: #047857; }
.performance-good { background: rgba(59, 130, 246, 0.15); color: #1d4ed8; }
.performance-average { background: rgba(251, 191, 36, 0.2); color: #b45309; }
.performance-poor { background: rgba(248, 113, 113, 0.2); color: #b91c1c; }
.performance-na { background: #f1f5f9; color: #94a3b8; }

.student-empty-state {
    background: #f8fafc;
    border-radius: 12px;
    padding: 40px 30px;
    text-align: center;
    border: 1px dashed #cbd5f5;
}

.student-empty-state i {
    font-size: 3rem;
    color: #cbd5f5;
    margin-bottom: 12px;
    display: block;
}

#student-performance-table-wrapper {
    position: relative;
    transition: opacity 0.2s ease;
}

#student-performance-table-wrapper.loading {
    opacity: 0.55;
}

#student-performance-table-wrapper.loading::after {
    content: 'Loading students...';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(255, 255, 255, 0.95);
    padding: 12px 26px;
    border-radius: 999px;
    font-weight: 600;
    color: #2563eb;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
    border: 1px solid rgba(37, 99, 235, 0.15);
    letter-spacing: 0.2px;
}

.student-pagination {
    margin-top: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.student-pagination .page-info {
    color: #6b7280;
    font-weight: 600;
}

.student-pagination .page-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.student-pagination a,
.student-pagination span {
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    font-weight: 600;
    text-decoration: none;
    color: #1f2937;
}

.student-pagination span.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #ffffff;
}

.student-pagination span.disabled {
    background: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
    cursor: not-allowed;
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
        overflow-x: hidden;
    }

    .main-content {
        padding: 35px 16px 0 16px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }

    .tabs-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .tabs-container::-webkit-scrollbar {
        display: none;
    }
    
    .tabs-container {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    .tabs-nav {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 5px;
    }
    
    .tabs-nav::-webkit-scrollbar {
        height: 4px;
    }
    
    .tabs-nav::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 2px;
    }
    
    .tabs-nav::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 2px;
    }
    
    .tabs-nav::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    .tab-button {
        padding: 8px 14px;
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    .tab-button.active {
        background: #eff6ff;
        border-color: #3b82f6;
    }
    
    /* Pie chart responsive */
    div[style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
        gap: 30px !important;
    }
    
    div[style*="max-width: 400px"] {
        max-width: 100% !important;
        height: 300px !important;
    }

    .student-table-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .student-search-form {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
    }

    .student-search-input {
        width: 100%;
    }

    .student-pagination {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-text">
                <h1 class="page-title">Student Report</h1>
                <p class="page-subtitle">Comprehensive overview of student statistics and performance for <?php echo htmlspecialchars($company_info ? $company_info->name : 'your school'); ?>.</p>
            </div>

            <div class="header-download-section">
                <span class="download-label">Download reports</span>
                <select class="download-select" id="studentDownloadFormat">
                    <option value="excel">Excel (.csv)</option>
                    <option value="pdf">PDF</option>
                </select>
                <button class="download-btn" type="button" onclick="downloadStudentReport()">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
        </div>

        <div class="tabs-container">
        <div class="tabs-nav">
            <button
                class="tab-button<?php echo $initial_tab === 'summary' ? ' active' : ''; ?>"
                type="button"
                data-tab="summary"
                data-url="<?php echo $tab_urls['summary'] ? $tab_urls['summary']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'summary' ? 'true' : 'false'; ?>">
                <i class="fa fa-user-graduate"></i>
                Student Report
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'academic' ? ' active' : ''; ?>"
                type="button"
                data-tab="academic"
                data-url="<?php echo $tab_urls['academic'] ? $tab_urls['academic']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'academic' ? 'true' : 'false'; ?>">
                <i class="fa fa-book"></i>
                Academic
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'engagements' ? ' active' : ''; ?>"
                type="button"
                data-tab="engagements"
                data-url="<?php echo $tab_urls['engagements'] ? $tab_urls['engagements']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'engagements' ? 'true' : 'false'; ?>">
                <i class="fa fa-chart-line"></i>
                Student Engagements
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'inactive' ? ' active' : ''; ?>"
                type="button"
                data-tab="inactive"
                data-url="<?php echo $tab_urls['inactive'] ? $tab_urls['inactive']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'inactive' ? 'true' : 'false'; ?>">
                <i class="fa fa-user-slash"></i>
                Inactive Student
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'quizassignmentreports' ? ' active' : ''; ?>"
                type="button"
                data-tab="quizassignmentreports"
                data-url="<?php echo $tab_urls['quizassignmentreports'] ? $tab_urls['quizassignmentreports']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'quizassignmentreports' ? 'true' : 'false'; ?>">
                <i class="fa fa-tasks"></i>
                Quiz & Assignment Reports
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'progress' ? ' active' : ''; ?>"
                type="button"
                data-tab="progress"
                data-url="<?php echo $tab_urls['progress'] ? $tab_urls['progress']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'progress' ? 'true' : 'false'; ?>">
                <i class="fa fa-tasks"></i>
                Progress
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'activitylog' ? ' active' : ''; ?>"
                type="button"
                data-tab="activitylog"
                data-url="<?php echo $tab_urls['activitylog'] ? $tab_urls['activitylog']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'activitylog' ? 'true' : 'false'; ?>">
                <i class="fa fa-history"></i>
                Student Log Activity
            </button>
        </div>
        </div>

        <div id="student-tab-content" data-initial-tab="<?php echo $initial_tab; ?>">
            <div id="summary-tab-pane" class="tab-pane active" data-tab="summary">
        <div class="report-table-container">
            <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                <i class="fa fa-user-graduate" style="color: #8b5cf6;"></i> Student Summary
            </h3>
            <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">Overview of student statistics for <?php echo htmlspecialchars($company_info ? $company_info->name : 'the school'); ?>.</p>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value"><?php echo number_format($student_stats['total_students']); ?></div>
                    <div class="stat-subtext">All registered students</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Active Students</div>
                    <div class="stat-value"><?php echo number_format($student_stats['active_students']); ?></div>
                    <div class="stat-subtext">Active in last 30 days</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Enrolled Students</div>
                    <div class="stat-value"><?php echo number_format($student_stats['enrolled_students']); ?></div>
                    <div class="stat-subtext">Students with course enrollments</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Completed Courses</div>
                    <div class="stat-value"><?php echo number_format($student_stats['completed_courses']); ?></div>
                    <div class="stat-subtext">Total course completions</div>
                </div>
            </div>

            <!-- Course Completion Status Pie Chart -->
            <?php if ($completion_data['total'] > 0): ?>
            <div style="margin-top: 40px;">
                <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                    <i class="fa fa-chart-pie" style="color: #8b5cf6;"></i> Overall Course Completion Status
                </h3>
                <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">Distribution of students by course completion status.</p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: center;">
                    <div style="display: flex; justify-content: center; align-items: center; background: #ffffff; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); border: 1px solid #e5e7eb;">
                        <div style="position: relative; width: 100%; max-width: 400px; height: 400px;">
                            <canvas id="completionStatusChart"></canvas>
                        </div>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div style="padding: 20px; background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-radius: 14px; border-left: 5px solid #10b981;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div style="font-size: 0.9rem; color: #065f46; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Completed</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #065f46;"><?php echo $completion_data['completed_percent']; ?>%</div>
                            </div>
                            <div style="font-size: 0.85rem; color: #047857; font-weight: 600;">
                                <?php echo number_format($completion_data['completed']); ?> students
                            </div>
                        </div>
                        
                        <div style="padding: 20px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 14px; border-left: 5px solid #f59e0b;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div style="font-size: 0.9rem; color: #92400e; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">In Progress</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #92400e;"><?php echo $completion_data['in_progress_percent']; ?>%</div>
                            </div>
                            <div style="font-size: 0.85rem; color: #b45309; font-weight: 600;">
                                <?php echo number_format($completion_data['in_progress']); ?> students
                            </div>
                        </div>
                        
                        <div style="padding: 20px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 14px; border-left: 5px solid #ef4444;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div style="font-size: 0.9rem; color: #991b1b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Not Started</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #991b1b;"><?php echo $completion_data['not_started_percent']; ?>%</div>
                            </div>
                            <div style="font-size: 0.85rem; color: #dc2626; font-weight: 600;">
                                <?php echo number_format($completion_data['not_started']); ?> students
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top: 40px; text-align: center; padding: 40px; background: #f9fafb; border-radius: 16px; border: 1px dashed #cbd5f5;">
                <i class="fa fa-chart-pie" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                <p style="color: #6b7280; font-weight: 600;">No course completion data available.</p>
            </div>
            <?php endif; ?>

            <!-- Student Performance List -->
            <div style="margin-top: 40px;">
                <div class="student-table-header">
                    <div>
                        <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin: 0;">
                            <i class="fa fa-users" style="color: #8b5cf6;"></i> Student Performance List
                        </h3>
                        <p style="color: #94a3b8; margin-top: 4px; font-size: 0.9rem;">
                            Detailed performance data for all students enrolled in <?php echo htmlspecialchars($company_info ? $company_info->name : 'your school'); ?>.
                        </p>
                    </div>
                    <form method="get" action="" class="student-search-form">
                        <div class="search-field-dropdown-wrapper">
                            <select name="cohort" id="cohortFilterDropdown" class="search-field-dropdown">
                                <option value="0" <?php echo $cohort_filter == 0 ? 'selected' : ''; ?>>All Cohorts</option>
                                <?php if (!empty($cohorts_list)): ?>
                                    <?php foreach ($cohorts_list as $cohort): ?>
                                        <option value="<?php echo $cohort['id']; ?>" <?php echo $cohort_filter == $cohort['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cohort['name']); ?> (<?php echo number_format($cohort['student_count']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                               placeholder="Enter search term..."
                               class="student-search-input">
                        <button type="submit" class="student-search-btn">
                            <i class="fa fa-search"></i> Search
                        </button>
                        <?php if ($search_query !== '' || $cohort_filter > 0): ?>
                        <a href="<?php echo new moodle_url('/theme/remui_kids/school_manager/student_report.php'); ?>" class="student-clear-btn">
                            <i class="fa fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div id="student-performance-table-wrapper" data-current-page="<?php echo $current_page; ?>">
                    <?php
                        echo theme_remui_kids_render_student_table(
                            $students_list,
                            $students_per_page,
                            $total_students_count,
                            $current_page,
                            $total_pages,
                            $offset,
                            $search_query,
                            $cohort_filter,
                            $cohorts_list,
                            $total_unfiltered_students_count
                        );
                    ?>
                </div>
            </div>
        </div>
            </div>
        </div>
        <div id="tab-loading-state" class="tab-loading-state" role="status" aria-live="polite">
            <div class="spinner"></div>
            <span>Loading tab data...</span>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
window.downloadStudentReport = function() {
    const formatSelect = document.getElementById('studentDownloadFormat');
    const format = formatSelect ? formatSelect.value : 'excel';
    const activeTab = studentActiveTab || studentInitialTab || 'summary';
    const baseUrl = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/student_report_download.php';
    const downloadUrl = baseUrl + '?tab=' + encodeURIComponent(activeTab) + '&format=' + encodeURIComponent(format || 'excel');
    window.location.href = downloadUrl;
};

// Initialize Course Completion Status Pie Chart
<?php if ($completion_data['total'] > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    const completionCtx = document.getElementById('completionStatusChart');
    if (completionCtx && typeof Chart !== 'undefined') {
        const completionChart = new Chart(completionCtx, {
            type: 'pie',
            data: {
                labels: ['Completed', 'In Progress', 'Not Started'],
                datasets: [{
                    data: [
                        <?php echo $completion_data['completed']; ?>,
                        <?php echo $completion_data['in_progress']; ?>,
                        <?php echo $completion_data['not_started']; ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 4,
                    borderColor: '#ffffff',
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 13,
                                family: 'Inter',
                                weight: '600'
                            },
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 15,
                        titleFont: {
                            size: 15,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 14
                        },
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = <?php echo $completion_data['total']; ?>;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return label + ': ' + value.toLocaleString() + ' students (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
});
<?php endif; ?>

const studentTabUrls = <?php echo json_encode($tab_url_map, JSON_UNESCAPED_SLASHES); ?>;
const studentTabLabels = <?php echo json_encode($tab_labels, JSON_UNESCAPED_SLASHES); ?>;
const studentInitialTab = <?php echo json_encode($initial_tab); ?>;
const studentSummaryUrl = <?php echo json_encode($summary_url_string, JSON_UNESCAPED_SLASHES); ?>;
const studentInitialPageUrl = window.location.href;
let studentActiveTab = studentInitialTab;

function mergeStudentHistoryState(overrides = {}) {
    const currentState = (window.history.state && typeof window.history.state === 'object') ? window.history.state : {};
    const nextState = Object.assign({}, currentState, overrides);
    if (!('studentPageUrl' in nextState) || !nextState.studentPageUrl) {
        nextState.studentPageUrl = currentState.studentPageUrl || studentInitialPageUrl;
    }
    if (!('tab' in nextState) || !nextState.tab) {
        nextState.tab = studentInitialTab;
    }
    return nextState;
}
window.__studentReportMergeState = mergeStudentHistoryState;

document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContentWrapper = document.getElementById('student-tab-content');
    const summaryPane = document.getElementById('summary-tab-pane');
    const loadingState = document.getElementById('tab-loading-state');

    if (!tabButtons.length || !tabContentWrapper || !summaryPane) {
        return;
    }

    const tabPanes = new Map();
    tabPanes.set('summary', summaryPane);

    function setActiveButton(tabName) {
        tabButtons.forEach(button => {
            const isActive = button.dataset.tab === tabName;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        studentActiveTab = tabName;
    }

    function showPane(tabName) {
        tabPanes.forEach((pane, key) => {
            const isActive = key === tabName;
            pane.classList.toggle('active', isActive);
            pane.hidden = !isActive;
        });
        
        // Trigger chart initialization after pane is shown (for AJAX-loaded tabs)
        if (tabName === 'activitylog' || tabName === 'inactive' || tabName === 'engagements' || tabName === 'quizassignmentreports' || tabName === 'academic') {
            setTimeout(function() {
                // Trigger chart initialization functions if they exist
                if (tabName === 'activitylog' && typeof window.initStudentLoginChart === 'function') {
                    window.initStudentLoginChart();
                }
                if (tabName === 'inactive' && typeof window.initInactiveStudentsChart === 'function') {
                    window.initInactiveStudentsChart();
                }
                if (tabName === 'academic' && typeof window.initAcademicCharts === 'function') {
                    window.initAcademicCharts();
                }
                // Charts in quizassignmentreports and engagements are initialized automatically via script execution
            }, 100);
        }
    }

    function showLoading() {
        if (loadingState) {
            loadingState.classList.add('visible');
        }
    }

    function hideLoading() {
        if (loadingState) {
            loadingState.classList.remove('visible');
        }
    }

    function resolveTabUrl(tabName) {
        if (studentTabUrls[tabName]) {
            return studentTabUrls[tabName];
        }
        const separator = studentSummaryUrl.includes('?') ? '&' : '?';
        return studentSummaryUrl + separator + 'tab=' + encodeURIComponent(tabName);
    }

    function buildPlaceholder(tabName) {
        const label = studentTabLabels[tabName] || tabName;
        return `
            <div class="tab-placeholder">
                <i class="fa fa-clipboard"></i>
                <h4>${label} coming soon</h4>
                <p>We're finalizing data for the ${label} tab. Please check back shortly.</p>
            </div>
        `;
    }

    function buildError(message) {
        return `
            <div class="tab-error-message">
                <i class="fa fa-exclamation-triangle"></i>
                <h4>Unable to load tab</h4>
                <p>${message || 'Please try again in a moment.'}</p>
            </div>
        `;
    }

    function createPane(tabName, html) {
        let pane = tabPanes.get(tabName);
        if (!pane) {
            pane = document.createElement('div');
            pane.className = 'tab-pane';
            pane.dataset.tab = tabName;
            pane.hidden = true;
            tabContentWrapper.appendChild(pane);
            tabPanes.set(tabName, pane);
        }
        pane.innerHTML = html;
        
        // Execute scripts in the loaded content (scripts don't run automatically with innerHTML)
        const scripts = pane.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
        
        return pane;
    }

    async function activateTab(tabName, options = {}) {
        const { pushState = true, bypassCache = false } = options;
        const targetTab = (tabName && (Object.prototype.hasOwnProperty.call(studentTabUrls, tabName) || Object.prototype.hasOwnProperty.call(studentTabLabels, tabName)))
            ? tabName
            : 'summary';
        const targetUrl = resolveTabUrl(targetTab);

        if (targetTab === 'summary') {
            showPane('summary');
            setActiveButton('summary');
            hideLoading();
            if (pushState) {
                window.history.pushState(
                    mergeStudentHistoryState({ tab: 'summary', studentPageUrl: targetUrl }),
                    '',
                    targetUrl
                );
            }
            return;
        }

        if (!bypassCache && tabPanes.has(targetTab) && tabPanes.get(targetTab).innerHTML.trim() !== '') {
            showPane(targetTab);
            setActiveButton(targetTab);
            hideLoading();
            if (pushState) {
                window.history.pushState(
                    mergeStudentHistoryState({ tab: targetTab }),
                    '',
                    targetUrl
                );
            }
            return;
        }

        showLoading();

        try {
            let htmlContent;
            if (studentTabUrls[targetTab]) {
                const fetchUrl = studentTabUrls[targetTab] + (studentTabUrls[targetTab].includes('?') ? '&' : '?') + 'ajax=1';
                const response = await fetch(fetchUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Server returned an error while loading this tab.');
                }
                htmlContent = await response.text();
            } else {
                htmlContent = buildPlaceholder(targetTab);
            }

            createPane(targetTab, htmlContent);
            showPane(targetTab);
            setActiveButton(targetTab);
            
            // Additional trigger for chart initialization after a short delay
            if (targetTab === 'activitylog' || targetTab === 'inactive' || targetTab === 'engagements' || targetTab === 'quizassignmentreports' || targetTab === 'academic') {
                setTimeout(function() {
                    const pane = tabPanes.get(targetTab);
                    if (pane && !pane.hidden) {
                        if (targetTab === 'activitylog' && typeof window.initStudentLoginChart === 'function') {
                            window.initStudentLoginChart();
                        }
                        if (targetTab === 'inactive' && typeof window.initInactiveStudentsChart === 'function') {
                            window.initInactiveStudentsChart();
                        }
                        if (targetTab === 'academic' && typeof window.initAcademicCharts === 'function') {
                            window.initAcademicCharts();
                        }
                        // Charts in quizassignmentreports and engagements are initialized automatically via script execution
                    }
                }, 300);
            }
            if (pushState) {
                window.history.pushState(
                    mergeStudentHistoryState({ tab: targetTab }),
                    '',
                    targetUrl
                );
            }
        } catch (error) {
            createPane(targetTab, buildError(error.message));
            showPane(targetTab);
            setActiveButton(targetTab);
        } finally {
            hideLoading();
        }
    }

    tabButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            const tabName = this.dataset.tab || 'summary';
            activateTab(tabName);
        });
    });

    window.addEventListener('popstate', function(event) {
        const newTab = event.state && event.state.tab ? event.state.tab : 'summary';
        activateTab(newTab, { pushState: false });
    });

    window.history.replaceState(
        mergeStudentHistoryState({ tab: studentInitialTab, studentPageUrl: window.location.href }),
        '',
        window.location.href
    );

    if (studentInitialTab !== 'summary') {
        activateTab(studentInitialTab, { pushState: false });
    } else {
        showPane('summary');
        setActiveButton('summary');
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tableWrapper = document.getElementById('student-performance-table-wrapper');
    if (!tableWrapper) {
        return;
    }

    const mergeHistoryState = window.__studentReportMergeState || function(overrides = {}) {
        const current = (window.history.state && typeof window.history.state === 'object') ? window.history.state : {};
        return Object.assign({}, current, overrides);
    };

    function normalizeUrl(rawUrl) {
        const parsed = new URL(rawUrl, window.location.href);
        parsed.searchParams.delete('ajax');
        return parsed.pathname + parsed.search + parsed.hash;
    }

    async function loadStudentPage(url, shouldPushState = false) {
        const fetchUrl = url.includes('?') ? `${url}&ajax=1` : `${url}?ajax=1`;
        tableWrapper.classList.add('loading');
        try {
            const response = await fetch(fetchUrl, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Unable to load student data');
            }
            const html = await response.text();
            tableWrapper.innerHTML = html;
            attachPaginationHandlers();
            attachCohortFilterHandlers();
            if (shouldPushState) {
                const cleanUrl = normalizeUrl(url);
                window.history.pushState(
                    mergeHistoryState({ tab: 'summary', studentPageUrl: cleanUrl }),
                    '',
                    cleanUrl
                );
            }
        } catch (error) {
            console.error('Student pagination failed', error);
        } finally {
            tableWrapper.classList.remove('loading');
        }
    }

    function attachPaginationHandlers() {
        const links = tableWrapper.querySelectorAll('.student-pagination a');
        links.forEach(link => {
            link.addEventListener('click', function(event) {
                const href = link.getAttribute('href');
                if (!href || link.classList.contains('disabled') || link.parentElement.classList.contains('disabled')) {
                    event.preventDefault();
                    return;
                }
                event.preventDefault();
                loadStudentPage(href, true);
            });
        });
    }

    attachPaginationHandlers();

    // Cohort filter functionality
    function attachCohortFilterHandlers() {
        // Handle dropdown in table wrapper (AJAX loaded content)
        const cohortDropdown = tableWrapper.querySelector('#cohortFilterDropdown');
        
        // Cohort dropdown change handler
        if (cohortDropdown) {
            cohortDropdown.addEventListener('change', function() {
                const cohortId = parseInt(this.value || '0');
                const currentUrl = new URL(window.location.href);
                
                if (cohortId === 0) {
                    currentUrl.searchParams.delete('cohort');
                } else {
                    currentUrl.searchParams.set('cohort', cohortId);
                }
                currentUrl.searchParams.delete('page'); // Reset to page 1 when filtering
                
                loadStudentPage(currentUrl.pathname + currentUrl.search, true);
            });
        }
    }

    // Handle dropdown in main page search form (outside table wrapper)
    function attachMainCohortFilterHandler() {
        const mainCohortDropdown = document.querySelector('#cohortFilterDropdown');
        
        if (mainCohortDropdown && !mainCohortDropdown.hasAttribute('data-handler-attached')) {
            mainCohortDropdown.setAttribute('data-handler-attached', 'true');
            mainCohortDropdown.addEventListener('change', function() {
                const cohortId = parseInt(this.value || '0');
                const currentUrl = new URL(window.location.href);
                
                if (cohortId === 0) {
                    currentUrl.searchParams.delete('cohort');
                } else {
                    currentUrl.searchParams.set('cohort', cohortId);
                }
                currentUrl.searchParams.delete('page'); // Reset to page 1 when filtering
                
                loadStudentPage(currentUrl.pathname + currentUrl.search, true);
            });
        }
    }

    attachCohortFilterHandlers();
    attachMainCohortFilterHandler();

    window.addEventListener('popstate', function(event) {
        const state = event.state || {};
        if (state.tab && state.tab !== 'summary') {
            return;
        }
        if (state.studentPageUrl) {
            loadStudentPage(state.studentPageUrl, false);
        }
    });
});
</script>

<?php echo $OUTPUT->footer(); ?>
