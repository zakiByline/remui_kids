<?php
/**
 * C Reports - Tab-specific downloads (Excel/PDF)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$tab = required_param('tab', PARAM_ALPHANUMEXT);
$format = optional_param('format', 'excel', PARAM_ALPHA);
$allowedformats = ['excel', 'pdf'];

if (!in_array($format, $allowedformats, true)) {
    $format = 'excel';
}

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    throw new moodle_exception('Access denied. School manager role required.');
}

$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    throw new moodle_exception('Unable to determine company information.');
}

$course_stats_cache = null;
$summarycards = [];
$cardlayout = false;
$school_name = format_string($company_info->name);
$generated_on = userdate(time(), get_string('strftimedatetime', 'langconfig'));

switch ($tab) {
    case 'enrollment':
        $course_stats_cache = $course_stats_cache ?? c_reports_fetch_course_stats($company_info->id);
        $totalcourses = count($course_stats_cache);
        $courseswithstudents = count(array_filter($course_stats_cache, static function($row) {
            return $row['total_enrolled'] > 0;
        }));
        $emptycourses = $totalcourses - $courseswithstudents;
        $totalenrolled = array_sum(array_column($course_stats_cache, 'total_enrolled'));
        $cardlayout = true;
        $columns = [
            'course_name' => 'Course Name',
            'total_enrolled' => 'Total Enrolled',
            'active_students' => 'Active Students (30d)',
            'enrollment_status' => 'Enrollment Status',
            'start_date' => 'Course Start Date'
        ];
        $rows = array_map(function($row) {
            return [
                'course_name' => $row['course_name'],
                'total_enrolled' => $row['total_enrolled'],
                'active_students' => $row['active_students'],
                'enrollment_status' => $row['total_enrolled'] > 0 ? 'Courses with students' : 'Empty course',
                'start_date' => $row['start_date'],
                'completed' => $row['completed'],
                'in_progress' => $row['in_progress'],
                'not_started' => $row['not_started'],
                'completion_rate' => $row['completion_rate'],
                'completed_pct' => $row['completed_pct'],
                'in_progress_pct' => $row['in_progress_pct'],
                'not_started_pct' => $row['not_started_pct']
            ];
        }, $course_stats_cache);
        $avg_students = $totalcourses > 0 ? round($totalenrolled / $totalcourses, 1) : 0;
        $summarycards = [
            ['label' => 'Total Courses', 'value' => $totalcourses, 'color' => '#2563eb'],
            ['label' => 'Courses with Students', 'value' => $courseswithstudents, 'color' => '#10b981'],
            ['label' => 'Empty Courses', 'value' => $emptycourses, 'color' => '#f97316'],
            ['label' => 'Total Enrollments', 'value' => $totalenrolled, 'color' => '#8b5cf6'],
            ['label' => 'Avg Students/Course', 'value' => $avg_students, 'color' => '#0ea5e9']
        ];
        $title = 'Course Enrollment Report';
        $filename = $company_info->name . ' course enrollment report';
        break;

    case 'courses':
        $course_stats_cache = $course_stats_cache ?? c_reports_fetch_course_stats($company_info->id);
        $totalcourses = count($course_stats_cache);
        $cardlayout = true;
        $columns = [
            'course_name' => 'Course Name',
            'short_name' => 'Short Name',
            'total_enrolled' => 'Total Enrolled',
            'completed' => 'Completed',
            'in_progress' => 'In Progress',
            'not_started' => 'Not Started',
            'completion_rate' => 'Completion Rate (%)',
            'cohorts' => 'Cohorts'
        ];
        $rows = array_map(function($row) {
            return [
                'course_name' => $row['course_name'],
                'short_name' => $row['short_name'],
                'total_enrolled' => $row['total_enrolled'],
                'completed' => $row['completed'],
                'in_progress' => $row['in_progress'],
                'not_started' => $row['not_started'],
                'completion_rate' => $row['completion_rate'],
                'cohorts' => $row['cohorts'],
                'completed_pct' => $row['completed_pct'],
                'in_progress_pct' => $row['in_progress_pct'],
                'not_started_pct' => $row['not_started_pct']
            ];
        }, $course_stats_cache);
        $avgcompletion = $totalcourses ? round(array_sum(array_column($course_stats_cache, 'completion_rate')) / $totalcourses, 1) : 0;
        $summarycards = [
            ['label' => 'Total Courses', 'value' => $totalcourses, 'color' => '#2563eb'],
            ['label' => 'Total Students', 'value' => array_sum(array_column($course_stats_cache, 'total_enrolled')), 'color' => '#0ea5e9'],
            ['label' => 'Avg Completion', 'value' => $avgcompletion . '%', 'color' => '#22c55e']
        ];
        $title = 'Courses Report';
        $filename = $company_info->name . ' courses report';
        break;

    case 'completion':
        $course_stats_cache = $course_stats_cache ?? c_reports_fetch_course_stats($company_info->id);
        $totalcourses = count($course_stats_cache);
        $cardlayout = true;
        $columns = [
            'course_name' => 'Course Name',
            'total_enrolled' => 'Total Enrolled',
            'completed' => 'Completed',
            'in_progress' => 'In Progress',
            'not_started' => 'Not Started',
            'completion_rate' => 'Completion Rate (%)'
        ];
        $rows = array_map(function($row) {
            return [
                'course_name' => $row['course_name'],
                'total_enrolled' => $row['total_enrolled'],
                'completed' => $row['completed'],
                'in_progress' => $row['in_progress'],
                'not_started' => $row['not_started'],
                'completion_rate' => $row['completion_rate']
            ];
        }, $course_stats_cache);
        $avgcompletion = $totalcourses ? round(array_sum(array_column($course_stats_cache, 'completion_rate')) / $totalcourses, 1) : 0;
        $summarycards = [
            ['label' => 'Total Courses', 'value' => $totalcourses, 'color' => '#6366f1'],
            ['label' => 'Total Students', 'value' => array_sum(array_column($course_stats_cache, 'total_enrolled')), 'color' => '#0ea5e9'],
            ['label' => 'Average Completion', 'value' => $avgcompletion . '%', 'color' => '#22c55e']
        ];
        $title = 'Course Completion Report';
        $filename = $company_info->name . ' course completion report';
        break;

    case 'distribution':
        $course_stats_cache = $course_stats_cache ?? c_reports_fetch_course_stats($company_info->id);
        $totalcourses = count($course_stats_cache);
        $total_enrollments = array_sum(array_column($course_stats_cache, 'total_enrolled'));
        $columns = [
            'course_name' => 'Course Name',
            'total_enrolled' => 'Total Enrolled',
            'share' => 'Enrollment Share (%)'
        ];
        $rows = array_map(function($row) use ($total_enrollments) {
            $share = $total_enrollments > 0 ? round(($row['total_enrolled'] / $total_enrollments) * 100, 1) : 0;
            return [
                'course_name' => $row['course_name'],
                'total_enrolled' => $row['total_enrolled'],
                'share' => $share
            ];
        }, $course_stats_cache);
        $sorted = $course_stats_cache;
        usort($sorted, static function($a, $b) {
            return $b['total_enrolled'] <=> $a['total_enrolled'];
        });
        $topcourse = $sorted[0] ?? null;
        $summarycards = [
            ['label' => 'Total Enrollments', 'value' => $total_enrollments, 'color' => '#9333ea'],
            ['label' => 'Average Enrollment', 'value' => $totalcourses ? round($total_enrollments / $totalcourses, 1) : 0, 'color' => '#f97316'],
            ['label' => 'Top Course', 'value' => $topcourse ? $topcourse['course_name'] : 'N/A', 'color' => '#3b82f6']
        ];
        $title = 'Course Distribution Report';
        $filename = $company_info->name . ' course distribution report';
        break;

    case 'overview':
        $course_stats_cache = $course_stats_cache ?? c_reports_fetch_course_stats($company_info->id);
        $totalcourses = count($course_stats_cache);
        $cardlayout = true;
        $columns = [
            'course_name' => 'Course Name',
            'short_name' => 'Short Name',
            'completion_rate' => 'Completion Rate (%)',
            'activity_rate' => 'Activity Rate (%)',
            'total_enrolled' => 'Total Enrolled',
            'start_date' => 'Start Date'
        ];
        $rows = array_map(function($row) {
            return [
                'course_name' => $row['course_name'],
                'short_name' => $row['short_name'],
                'completion_rate' => $row['completion_rate'],
                'activity_rate' => $row['activity_rate'],
                'total_enrolled' => $row['total_enrolled'],
                'start_date' => $row['start_date']
            ];
        }, $course_stats_cache);
        $avgcompletion = $totalcourses ? round(array_sum(array_column($course_stats_cache, 'completion_rate')) / $totalcourses, 1) : 0;
        $avgactivity = $totalcourses ? round(array_sum(array_column($course_stats_cache, 'activity_rate')) / $totalcourses, 1) : 0;
        $summarycards = [
            ['label' => 'Total Courses', 'value' => $totalcourses, 'color' => '#2563eb'],
            ['label' => 'Average Completion', 'value' => $avgcompletion . '%', 'color' => '#22c55e'],
            ['label' => 'Average Activity', 'value' => $avgactivity . '%', 'color' => '#f97316']
        ];
        $title = 'Course Overview Report';
        $filename = $company_info->name . ' course overview report';
        break;

    case 'engagement':
        $columns = [
            'student' => 'Student',
            'email' => 'Email',
            'login_days' => 'Login Days (30d)',
            'learning_hours' => 'Learning Hours',
            'forum_posts' => 'Forum Posts',
            'engagement_score' => 'Engagement Score (%)'
        ];
        $rows = c_reports_fetch_engagement_stats($company_info->id);
        $studentcount = count($rows);
        $avghours = $studentcount ? round(array_sum(array_column($rows, 'learning_hours')) / $studentcount, 1) : 0;
        $avgscore = $studentcount ? round(array_sum(array_column($rows, 'engagement_score')) / $studentcount, 1) : 0;
        $summarycards = [
            ['label' => 'Total Students', 'value' => $studentcount, 'color' => '#2563eb'],
            ['label' => 'Avg Learning Hours', 'value' => $avghours . ' hrs', 'color' => '#0ea5e9'],
            ['label' => 'Avg Engagement Score', 'value' => $avgscore . '%', 'color' => '#f97316']
        ];
        $title = 'Student Engagement Report';
        $filename = $company_info->name . ' student engagement report';
        break;

    default:
        throw new moodle_exception('Invalid tab supplied.');
}

c_reports_output_download($format, $filename, $columns, $rows, $title, $summarycards, $school_name, $generated_on, $cardlayout);

exit;

/**
 * Build the base course statistics used across multiple tabs.
 *
 * @param int $companyid
 * @return array
 */
function c_reports_fetch_course_stats(int $companyid): array {
    global $DB;

    $courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.startdate
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
          WHERE c.visible = 1
            AND c.id > 1
       ORDER BY c.fullname ASC",
        [$companyid]
    );

    $course_stats = [];

    foreach ($courses as $course) {
        $total_enrolled = $DB->count_records_sql(
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
            [CONTEXT_COURSE, $course->id, $companyid]
        );

        $completed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
               FROM {user} u
               JOIN {course_completions} cc ON cc.userid = u.id
               JOIN {company_users} cu ON cu.userid = u.id
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = cc.course
               JOIN {role} r ON r.id = ra.roleid
              WHERE cc.course = ?
                AND cc.timecompleted IS NOT NULL
                AND cu.companyid = ?
                AND r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0",
            [CONTEXT_COURSE, $course->id, $companyid]
        );

        $in_progress = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
               FROM {user} u
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {company_users} cu ON cu.userid = u.id
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
              WHERE e.courseid = ?
                AND ue.status = 0
                AND cu.companyid = ?
                AND r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0
                AND u.id NOT IN (
                    SELECT cc.userid
                      FROM {course_completions} cc
                     WHERE cc.course = ? AND cc.timecompleted IS NOT NULL
                )",
            [CONTEXT_COURSE, $course->id, $companyid, $course->id]
        );

        $not_started = max(0, $total_enrolled - ($completed + $in_progress));
        $completion_rate = $total_enrolled > 0 ? round(($completed / max(1, $total_enrolled)) * 100, 1) : 0;

        $active_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
               FROM {user} u
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {company_users} cu ON cu.userid = u.id
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
              WHERE e.courseid = ?
                AND ue.status = 0
                AND cu.companyid = ?
                AND r.shortname = 'student'
                AND u.deleted = 0
                AND u.suspended = 0
                AND ula.timeaccess > ?",
            [CONTEXT_COURSE, $course->id, $companyid, strtotime('-30 days')]
        );

        $activity_rate = $total_enrolled > 0 ? round(($active_students / max(1, $total_enrolled)) * 100, 1) : 0;

        $course_cohorts = $DB->get_records_sql(
            "SELECT DISTINCT coh.name
               FROM {cohort} coh
               JOIN {enrol} e ON e.customint1 = coh.id AND e.enrol = 'cohort'
              WHERE e.courseid = ? AND coh.visible = 1",
            [$course->id]
        );

        $cohort_names = array_map(function($cohort) {
            return $cohort->name;
        }, $course_cohorts);

        $course_stats[] = [
            'course_name' => $course->fullname,
            'short_name' => $course->shortname,
            'total_enrolled' => $total_enrolled,
            'completed' => $completed,
            'in_progress' => $in_progress,
            'not_started' => $not_started,
            'completion_rate' => $completion_rate,
            'active_students' => $active_students,
            'activity_rate' => $activity_rate,
            'start_date' => $course->startdate > 0 ? date('Y-m-d', $course->startdate) : '-',
            'cohorts' => implode(', ', $cohort_names) ?: '-',
            'completed_pct' => $total_enrolled > 0 ? round(($completed / $total_enrolled) * 100, 1) : 0,
            'in_progress_pct' => $total_enrolled > 0 ? round(($in_progress / $total_enrolled) * 100, 1) : 0,
            'not_started_pct' => $total_enrolled > 0 ? round(($not_started / $total_enrolled) * 100, 1) : 0
        ];
    }

    return $course_stats;
}

/**
 * Fetch engagement statistics for students.
 *
 * @param int $companyid
 * @return array
 */
function c_reports_fetch_engagement_stats(int $companyid): array {
    global $DB;

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE cu.companyid = ?
            AND r.shortname = 'student'
            AND u.deleted = 0
            AND u.suspended = 0
       ORDER BY u.lastname, u.firstname",
        [$companyid]
    );

    $thirty_days_ago = strtotime('-30 days');
    $ninety_days_ago = time() - (90 * 24 * 3600);

    $engagement_rows = [];

    foreach ($students as $student) {
        $login_days = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
               FROM {logstore_standard_log}
              WHERE userid = ?
                AND action = 'loggedin'
                AND timecreated > ?",
            [$student->id, $thirty_days_ago]
        );

        $logs = $DB->get_records_sql(
            "SELECT timecreated
               FROM {logstore_standard_log}
              WHERE userid = ?
                AND timecreated > ?
           ORDER BY timecreated ASC",
            [$student->id, $thirty_days_ago]
        );

        $total_minutes = 0;
        $prev = null;
        foreach ($logs as $log) {
            if ($prev !== null) {
                $diff = $log->timecreated - $prev;
                if ($diff > 0 && $diff < 1800) {
                    $total_minutes += ($diff / 60);
                }
            }
            $prev = $log->timecreated;
        }
        $learning_hours = round($total_minutes / 60, 1);

        $forum_posts = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
               JOIN {forum} f ON f.id = fd.forum
               JOIN {course} c ON c.id = f.course
               JOIN {company_course} cc ON cc.courseid = c.id
              WHERE fp.userid = ?
                AND cc.companyid = ?
                AND fp.created > ?",
            [$student->id, $companyid, $thirty_days_ago]
        );

        $engagement_score = min(
            100,
            ($login_days * 3) + (min(12, $learning_hours) * 4) + ($forum_posts * 5)
        );

        $engagement_rows[] = [
            'student' => fullname($student),
            'email' => $student->email,
            'login_days' => $login_days,
            'learning_hours' => $learning_hours,
            'forum_posts' => $forum_posts,
            'engagement_score' => round($engagement_score, 1)
        ];
    }

    return $engagement_rows;
}

/**
 * Output the report in the requested format.
 *
 * @param string $format
 * @param string $filenamebase
 * @param array $columns
 * @param array $rows
 * @param string $title
 */
function c_reports_output_download(string $format, string $filenamebase, array $columns, array $rows, string $title, array $summarycards, string $school_name, string $generated_on, bool $cardlayout): void {
    global $CFG;

    $cleanname = preg_replace('/[^a-zA-Z0-9\s-]/', '', $filenamebase);
    $cleanname = trim(preg_replace('/\s+/', ' ', $cleanname));
    if ($cleanname === '') {
        $cleanname = 'report';
    }

    if ($format === 'pdf') {
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new pdf('L', 'mm', 'A4');
        $pdf->SetTitle($title);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);
        $headerhtml = '<div style="text-align:center;">
            <h2 style="margin-bottom:4px;">' . format_string($title) . '</h2>
            <p style="margin:2px 0;font-size:10px;color:#475569;">' . s($school_name) . ' &middot; ' . s($generated_on) . '</p>
        </div>';
        $pdf->writeHTML($headerhtml, true, false, true, false, '');

        if (!empty($summarycards)) {
            $cardhtml = '<table cellpadding="8" cellspacing="6" width="100%"><tr>';
            foreach ($summarycards as $card) {
                $cardhtml .= '<td style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                    <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:9px;font-weight:600;background:' . s($card['color']) . '20;color:' . s($card['color']) . ';text-transform:uppercase;">' . s($card['label']) . '</span>
                    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;">' . s($card['value']) . '</div>
                </td>';
            }
            $cardhtml .= '</tr></table>';
            $pdf->writeHTML($cardhtml, true, false, true, false, '');
        }

        // Add pie chart for enrollment report
        if ($tab === 'enrollment' && !empty($rows)) {
            // Calculate totals from rows
            $total = count($rows);
            $with_students = 0;
            $empty = 0;
            foreach ($rows as $row) {
                if (isset($row['total_enrolled']) && $row['total_enrolled'] > 0) {
                    $with_students++;
                } else {
                    $empty++;
                }
            }
            
            if ($total > 0) {
                $with_students_pct = round(($with_students / $total) * 100, 1);
                $empty_pct = round(($empty / $total) * 100, 1);
                
                // Calculate angles for pie chart (in degrees)
                $with_students_angle = ($with_students / $total) * 360;
                $empty_angle = ($empty / $total) * 360;
                
                // Calculate SVG path coordinates
                $cx = 100;
                $cy = 100;
                $r = 80;
                $start_x = $cx;
                $start_y = $cy - $r;
                
                // End point for first segment
                $end1_x = $cx + $r * sin(deg2rad($with_students_angle));
                $end1_y = $cy - $r * cos(deg2rad($with_students_angle));
                
                $large_arc1 = $with_students_angle > 180 ? 1 : 0;
                $large_arc2 = $empty_angle > 180 ? 1 : 0;
                
                $piehtml = '<div style="margin:20px 0;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;">
                    <h3 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#0f172a;">Course Enrollment Status</h3>
                    <p style="margin:0 0 16px 0;font-size:10px;color:#64748b;">Distribution of courses with students versus empty courses.</p>
                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
                        <tr>
                            <td width="50%" style="vertical-align:top;padding-right:12px;">
                                <div style="text-align:center;">
                                    <svg width="180" height="180" viewBox="0 0 200 200" style="max-width:180px;">
                                        <circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#e5e7eb" stroke="#fff" stroke-width="2"/>
                                        <path d="M ' . $cx . ' ' . $cy . ' L ' . $start_x . ' ' . $start_y . ' A ' . $r . ' ' . $r . ' 0 ' . $large_arc1 . ' 1 ' . $end1_x . ' ' . $end1_y . ' Z" fill="#10b981" stroke="#fff" stroke-width="2"/>
                                        <path d="M ' . $cx . ' ' . $cy . ' L ' . $end1_x . ' ' . $end1_y . ' A ' . $r . ' ' . $r . ' 0 ' . $large_arc2 . ' 1 ' . $start_x . ' ' . $start_y . ' Z" fill="#ef4444" stroke="#fff" stroke-width="2"/>
                                    </svg>
                                </div>
                            </td>
                            <td width="50%" style="vertical-align:middle;padding-left:12px;">
                                <table width="100%" cellpadding="4" cellspacing="0" style="font-size:10px;">
                                    <tr>
                                        <td style="padding:6px 0;">
                                            <span style="display:inline-block;width:12px;height:12px;background:#10b981;border-radius:50%;margin-right:8px;vertical-align:middle;"></span>
                                            <span style="font-weight:600;color:#0f172a;">Courses with Students</span>
                                        </td>
                                        <td style="text-align:right;font-weight:700;color:#0f172a;">' . $with_students . '</td>
                                        <td style="text-align:right;color:#64748b;padding-left:8px;">' . $with_students_pct . '% of total courses</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:6px 0;">
                                            <span style="display:inline-block;width:12px;height:12px;background:#ef4444;border-radius:50%;margin-right:8px;vertical-align:middle;"></span>
                                            <span style="font-weight:600;color:#0f172a;">Empty Courses</span>
                                        </td>
                                        <td style="text-align:right;font-weight:700;color:#0f172a;">' . $empty . '</td>
                                        <td style="text-align:right;color:#64748b;padding-left:8px;">' . $empty_pct . '% of total courses</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </div>';
                $pdf->writeHTML($piehtml, true, false, true, false, '');
            }
        }

        $cardcss = '<style>
            .pdf-course-card {border:1px solid #e2e8f0;border-radius:16px;padding:14px 16px;margin-bottom:16px;background:#fff;}
            .pdf-course-header {font-size:13px;font-weight:700;color:#0f172a;margin-bottom:6px;}
            .pdf-progress-bar {width:100%;height:8px;background:#e5e7eb;border-radius:999px;margin:10px 0;overflow:hidden;}
            .pdf-progress-fill {height:100%;border-radius:999px;background:linear-gradient(90deg,#38bdf8,#6366f1);}
            .pdf-chart-table {width:100%;border-collapse:collapse;margin-top:6px;font-size:10px;}
            .pdf-chart-table td {padding:2px 0;border:none;}
            .pdf-dot {display:inline-block;width:8px;height:8px;border-radius:999px;margin-right:6px;}
        </style>';
        $pdf->writeHTML($cardcss, true, false, true, false, '');

        if ($cardlayout && !empty($rows)) {
            foreach ($rows as $row) {
                $completed = isset($row['completed']) ? (float)$row['completed'] : 0;
                $progress = isset($row['in_progress']) ? (float)$row['in_progress'] : 0;
                $notstarted = isset($row['not_started']) ? (float)$row['not_started'] : 0;
                $total = $completed + $progress + $notstarted;
                if ($total <= 0) {
                    $total = isset($row['total_enrolled']) ? (float)$row['total_enrolled'] : 1;
                }
                $completionrate = isset($row['completion_rate']) ? (float)$row['completion_rate'] : ($total > 0 ? round(($completed / $total) * 100, 1) : 0);
                $completed_pct = isset($row['completed_pct']) ? $row['completed_pct'] : ($total > 0 ? round(($completed / $total) * 100, 1) : 0);
                $progress_pct = isset($row['in_progress_pct']) ? $row['in_progress_pct'] : ($total > 0 ? round(($progress / $total) * 100, 1) : 0);
                $notstarted_pct = isset($row['not_started_pct']) ? $row['not_started_pct'] : ($total > 0 ? round(($notstarted / $total) * 100, 1) : 0);
                $total_enrolled = isset($row['total_enrolled']) ? $row['total_enrolled'] : $total;
                $cohorts = isset($row['cohorts']) ? $row['cohorts'] : '';
                $course_name = $row['course_name'] ?? $row['student'] ?? 'Record';
                
                // For courses report, use the exact web page format
                if ($tab === 'courses') {
                    // Calculate donut chart angles (in degrees)
                    $total_for_chart = max(1, $total_enrolled);
                    $completed_angle = ($completed / $total_for_chart) * 360;
                    $progress_angle = ($progress / $total_for_chart) * 360;
                    $notstarted_angle = ($notstarted / $total_for_chart) * 360;
                    
                    // Calculate SVG path coordinates
                    $cx = 75;
                    $cy = 75;
                    $r = 60;
                    $inner_r = 35;
                    
                    // Start point (top of circle)
                    $start_x = $cx;
                    $start_y = $cy - $r;
                    
                    // End point for completed segment
                    $end1_x = $cx + $r * sin(deg2rad($completed_angle));
                    $end1_y = $cy - $r * cos(deg2rad($completed_angle));
                    
                    // End point for progress segment
                    $end2_x = $cx + $r * sin(deg2rad($completed_angle + $progress_angle));
                    $end2_y = $cy - $r * cos(deg2rad($completed_angle + $progress_angle));
                    
                    $large_arc1 = $completed_angle > 180 ? 1 : 0;
                    $large_arc2 = $progress_angle > 180 ? 1 : 0;
                    $large_arc3 = $notstarted_angle > 180 ? 1 : 0;
                    
                    $cohorts_html = '';
                    if ($cohorts && $cohorts !== '-') {
                        $cohort_list = explode(', ', $cohorts);
                        foreach ($cohort_list as $cohort) {
                            $cohorts_html .= '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:9px;font-weight:600;margin-right:8px;margin-bottom:4px;">' . s(trim($cohort)) . '</span>';
                        }
                    }
                    
                    $card = '<div style="background:#fff;border-radius:24px;padding:28px 32px;margin-bottom:24px;box-shadow:0 12px 40px rgba(15,23,42,0.07);border:1px solid #eef2ff;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="60%" style="vertical-align:top;padding-right:32px;">
                                    <h3 style="margin:0 0 12px 0;font-size:20px;font-weight:700;color:#0f172a;">' . s($course_name) . '</h3>
                                    ' . ($cohorts_html ? '<div style="margin-bottom:12px;">' . $cohorts_html . '</div>' : '') . '
                                    <div style="margin-top:6px;width:55%;">
                                        <div style="height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-bottom:8px;">
                                            <div style="height:100%;background:linear-gradient(90deg,#38bdf8,#6366f1);width:' . round(min(100, max(0, $completionrate)), 1) . '%;"></div>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:10px;font-weight:700;color:#1f2937;font-size:11px;">
                                            <span>' . round(min(100, max(0, $completionrate)), 1) . '% completion rate</span>
                                            <span>' . $total_enrolled . ' students</span>
                                        </div>
                                    </div>
                                    <div style="margin-top:16px;display:flex;gap:12px;">
                                        <span style="border:1px solid #6366f1;color:#6366f1;padding:9px 16px;border-radius:12px;font-weight:600;font-size:11px;background:#f8faff;">Course summary</span>
                                        <span style="border:1px solid #6366f1;color:#6366f1;padding:9px 16px;border-radius:12px;font-weight:600;font-size:11px;background:#f8faff;">Completion report by month</span>
                                    </div>
                                </td>
                                <td width="40%" style="vertical-align:top;border-left:1px solid #eef2ff;padding-left:28px;">
                                    <div style="display:flex;align-items:flex-start;gap:20px;background:#f9fbff;border-radius:20px;padding:18px 20px;border:1px solid #e2e8f0;">
                                        <div style="display:flex;flex-direction:column;gap:8px;font-size:11px;color:#475569;text-transform:uppercase;font-weight:600;">
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#22c55e;"></span>
                                                <span style="color:#111827;font-size:10px;text-transform:none;">Completed (' . $completed . ')</span>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#3b82f6;"></span>
                                                <span style="color:#111827;font-size:10px;text-transform:none;">Still in progress (' . $progress . ')</span>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ef4444;"></span>
                                                <span style="color:#111827;font-size:10px;text-transform:none;">Not started (' . $notstarted . ')</span>
                                            </div>
                                        </div>
                                        <div style="width:150px;height:150px;position:relative;text-align:center;">
                                            <svg width="150" height="150" viewBox="0 0 150 150">
                                                <circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#e5e7eb"/>
                                                ' . ($completed > 0 ? '<path d="M ' . $cx . ' ' . $cy . ' L ' . $start_x . ' ' . $start_y . ' A ' . $r . ' ' . $r . ' 0 ' . $large_arc1 . ' 1 ' . $end1_x . ' ' . $end1_y . ' Z" fill="#22c55e"/>' : '') . '
                                                ' . ($progress > 0 ? '<path d="M ' . $cx . ' ' . $cy . ' L ' . $end1_x . ' ' . $end1_y . ' A ' . $r . ' ' . $r . ' 0 ' . $large_arc2 . ' 1 ' . $end2_x . ' ' . $end2_y . ' Z" fill="#3b82f6"/>' : '') . '
                                                ' . ($notstarted > 0 ? '<path d="M ' . $cx . ' ' . $cy . ' L ' . $end2_x . ' ' . $end2_y . ' A ' . $r . ' ' . $r . ' 0 ' . $large_arc3 . ' 1 ' . $start_x . ' ' . $start_y . ' Z" fill="#ef4444"/>' : '') . '
                                                <circle cx="' . $cx . '" cy="' . $cy . '" r="' . $inner_r . '" fill="#fff"/>
                                            </svg>
                                        </div>
                                    </div>
                                    <div style="margin-top:8px;text-align:center;">
                                        <span style="color:#4f46e5;font-weight:600;font-size:11px;">Show chart data</span>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>';
                } else {
                    // For other reports, use the simpler format
                    $card = '<div class="pdf-course-card">
                        <div class="pdf-course-header">' . s($course_name) . '</div>
                        <div style="font-size:10px;color:#475569;margin-bottom:4px;">Total Enrolled: ' . s($total_enrolled) . ' &middot; Completion rate ' . round(min(100, max(0, $completionrate)), 1) . '%</div>
                        <div class="pdf-progress-bar"><div class="pdf-progress-fill" style="width:' . round(min(100, max(0, $completionrate)), 1) . '%;"></div></div>
                        <div class="pdf-chart-table">
                            <table width="100%" style="font-size:9px;">
                                <tr>
                                    <td><span class="pdf-dot" style="background:#22c55e;"></span>Completed</td>
                                    <td style="text-align:right;font-weight:600;">' . s($completed) . '</td>
                                    <td style="text-align:right;color:#475569;">(' . $completed_pct . '%)</td>
                                </tr>
                                <tr>
                                    <td><span class="pdf-dot" style="background:#3b82f6;"></span>In Progress</td>
                                    <td style="text-align:right;font-weight:600;">' . s($progress) . '</td>
                                    <td style="text-align:right;color:#475569;">(' . $progress_pct . '%)</td>
                                </tr>
                                <tr>
                                    <td><span class="pdf-dot" style="background:#ef4444;"></span>Not Started</td>
                                    <td style="text-align:right;font-weight:600;">' . s($notstarted) . '</td>
                                    <td style="text-align:right;color:#475569;">(' . $notstarted_pct . '%)</td>
                                </tr>
                            </table>
                            <div style="margin-top:6px;font-size:9px;color:#4f46e5;">Show chart data</div>
                        </div>
                    </div>';
                }
                $pdf->writeHTML($card, true, false, true, false, '');
            }
        } else {
            $table = '<table border="1" cellpadding="4" cellspacing="0"><thead><tr>';
            foreach ($columns as $header) {
                $table .= '<th style="background-color:#f1f5f9;font-weight:bold;">' . s($header) . '</th>';
            }
            $table .= '</tr></thead><tbody>';
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $table .= '<tr>';
                    foreach (array_keys($columns) as $key) {
                        $value = isset($row[$key]) ? $row[$key] : '';
                        $table .= '<td>' . s((string)$value) . '</td>';
                    }
                    $table .= '</tr>';
                }
            } else {
                $table .= '<tr><td colspan="' . count($columns) . '" style="text-align:center;">No data available</td></tr>';
            }
            $table .= '</tbody></table>';
            $pdf->writeHTML($table, true, false, true, false, '');
        }

        $pdf->Output($cleanname . '.pdf', 'D');
        return;
    }

    $extension = '.csv';
    $mimetype = 'text/csv; charset=utf-8';

    header('Content-Type: ' . $mimetype);
    header('Content-Disposition: attachment; filename="' . $cleanname . $extension . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [$title]);
    fputcsv($output, ['School', $school_name]);
    fputcsv($output, ['Generated', $generated_on]);

    if (!empty($summarycards)) {
        fputcsv($output, []); // blank line
        fputcsv($output, ['Summary']);
        foreach ($summarycards as $card) {
            fputcsv($output, [$card['label'], $card['value']]);
        }
    }

    fputcsv($output, []); // blank line before table
    fputcsv($output, array_values($columns));

    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = isset($row[$key]) ? $row[$key] : '';
        }
        fputcsv($output, $line);
    }

    if (empty($rows)) {
        $empty = array_fill(0, count($columns), 'No data available');
        fputcsv($output, $empty);
    }

    fclose($output);
}

