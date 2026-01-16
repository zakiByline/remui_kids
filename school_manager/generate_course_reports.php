<?php
// Generator script to create course_reports.php
$content = <<<'PHPCODE'
<?php
/**
 * Course Reports - School Manager
 * Main course reports menu page with multiple tabs
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
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

// Get all courses for this company with detailed statistics
$courses_data = [];
if ($company_info) {
    $courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.startdate, c.visible, c.timecreated
         FROM {course} c
         INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
         WHERE c.visible = 1 
         AND c.id > 1 
         AND comp_c.companyid = ?
         ORDER BY c.fullname ASC",
        [$company_info->id]
    );
    
    foreach ($courses as $course) {
        // Get enrollment statistics
        $total_enrolled = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid = ? 
             AND ue.status = 0
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [$course->id, $company_info->id]
        );
        
        // Get completed count
        $completed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {course_completions} cc ON cc.userid = u.id
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = cc.course
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cc.course = ? 
             AND cc.timecompleted IS NOT NULL
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [$course->id, $company_info->id]
        );
        
        // Get active students (accessed in last 30 days)
        $thirty_days_ago = strtotime("-30 days");
        $active_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
             WHERE e.courseid = ?
             AND ue.status = 0
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0
             AND ula.timeaccess >= ?",
            [$course->id, $company_info->id, $thirty_days_ago]
        );
        
        // Calculate completion rate
        $completion_rate = $total_enrolled > 0 ? round(($completed / $total_enrolled) * 100, 1) : 0;
        
        $courses_data[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'total_enrolled' => $total_enrolled,
            'completed' => $completed,
            'active_students' => $active_students,
            'completion_rate' => $completion_rate,
            'start_date' => date('M j, Y', $course->startdate)
        ];
    }
}

// Get login trend data (last 30 days)
$login_trend_data = [
    'student_logins' => [],
    'teacher_logins' => [],
    'dates' => []
];

if ($company_info) {
    // Generate last 30 days dates
    $dates = [];
    for ($i = 29; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
    $login_trend_data['dates'] = $dates;
    
    $thirty_days_ago = strtotime("-30 days");
    
    // Get all student logins
    $student_login_records = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND u.lastaccess >= ?",
        [$company_info->id, $thirty_days_ago]
    );
    
    // Get all teacher logins
    $teacher_login_records = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher', 'manager')
         AND u.deleted = 0
         AND u.suspended = 0
         AND u.lastaccess >= ?",
        [$company_info->id, $thirty_days_ago]
    );
    
    // Count logins per day for students
    foreach ($dates as $date) {
        $count = 0;
        $date_start = strtotime($date . ' 00:00:00');
        $date_end = strtotime($date . ' 23:59:59');
        
        foreach ($student_login_records as $record) {
            if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
                $count++;
            }
        }
        $login_trend_data['student_logins'][] = $count;
    }
    
    // Count logins per day for teachers
    foreach ($dates as $date) {
        $count = 0;
        $date_start = strtotime($date . ' 00:00:00');
        $date_end = strtotime($date . ' 23:59:59');
        
        foreach ($teacher_login_records as $record) {
            if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
                $count++;
            }
        }
        $login_trend_data['teacher_logins'][] = $count;
    }
}

// Get user distribution data
$user_distribution = [];
if ($company_info) {
    // Count students
    $total_students = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0",
        [$company_info->id]
    );
    
    // Count teachers
    $total_teachers = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher')
         AND u.deleted = 0
         AND u.suspended = 0",
        [$company_info->id]
    );
    
    $user_distribution = [
        'students' => $total_students,
        'teachers' => $total_teachers
    ];
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/school_manager/course_reports.php');
$page_title = ($company_info ? htmlspecialchars($company_info->name) : 'School') . ' Reports';
$PAGE->set_title($page_title);
$PAGE->set_heading($page_title);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School',
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'course_reports',
    'course_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

echo $OUTPUT->header();

// Render sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>
PHPCODE;

// Now write the content to course_reports.php
file_put_contents(__DIR__ . '/course_reports.php', $content);
echo "File generated successfully!\n";
echo "File size: " . filesize(__DIR__ . '/course_reports.php') . " bytes\n";
?>