<?php
/**
 * AJAX endpoint to fetch teacher courses with student counts
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

// Set JSON header
header('Content-Type: application/json');

// Ensure the current user has the school manager role.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    echo json_encode(['error' => 'Access denied']);
    exit;
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

if (!$company_info) {
    echo json_encode(['error' => 'Company not found']);
    exit;
}

// Get teacher ID from request
$teacherid = required_param('teacherid', PARAM_INT);

// Verify teacher belongs to the company
$teacher = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname
     FROM {user} u
     INNER JOIN {company_users} cu ON cu.userid = u.id
     WHERE u.id = ? AND cu.companyid = ? AND u.deleted = 0",
    [$teacherid, $company_info->id]
);

if (!$teacher) {
    echo json_encode(['error' => 'Teacher not found']);
    exit;
}

// Fetch courses assigned to this teacher with student counts
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname,
            (SELECT COUNT(DISTINCT ue.userid)
             FROM {user_enrolments} ue
             INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
             INNER JOIN {user} u ON u.id = ue.userid
             INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = c.id
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE ue.status = 0
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0) AS total_students
     FROM {course} c
     INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
     INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
     INNER JOIN {role} r ON r.id = ra.roleid
     INNER JOIN {company_course} cc ON cc.courseid = c.id
     WHERE ra.userid = ?
     AND r.shortname IN ('teacher', 'editingteacher')
     AND cc.companyid = ?
     AND c.visible = 1
     AND c.id > 1
     ORDER BY c.fullname ASC",
    [$company_info->id, $teacherid, $company_info->id]
);

$courses_data = [];
foreach ($courses as $course) {
    $courses_data[] = [
        'id' => (int)$course->id,
        'name' => $course->fullname,
        'shortname' => $course->shortname,
        'total_students' => (int)($course->total_students ?? 0)
    ];
}

echo json_encode([
    'success' => true,
    'teacher' => [
        'id' => (int)$teacher->id,
        'name' => fullname($teacher)
    ],
    'courses' => $courses_data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);