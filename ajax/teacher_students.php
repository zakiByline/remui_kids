<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_login();
header('Content-Type: application/json');

global $DB, $USER;

// Debug logging
error_log("Teacher Students AJAX - Starting request");

try {
    $page = max(1, optional_param('page', 1, PARAM_INT));
    $perpage = min(50, max(5, optional_param('perpage', 10, PARAM_INT)));
    $search = trim(optional_param('q', '', PARAM_RAW_TRIMMED));
    $onlymanagers = optional_param('onlymanagers', 0, PARAM_BOOL);

    // Get teacher course ids
    $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
    if (!is_array($teacherroles)) {
        error_log("Teacher roles query returned non-array: " . gettype($teacherroles));
        $teacherroles = [];
    }
    $roleids = (is_array($teacherroles) && !empty($teacherroles)) ? array_keys($teacherroles) : [];
    if (empty($roleids)) {
        echo json_encode(['total' => 0, 'pages' => 0, 'page' => $page, 'students' => []]);
        exit;
    }

    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;

    $courseids = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}",
        $params
    );

    if (empty($courseids)) {
        echo json_encode(['total' => 0, 'pages' => 0, 'page' => $page, 'students' => []]);
        exit;
    }

    $ids = array_map(function($r){return $r->courseid;}, $courseids);
    list($coursesql, $courseparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');

    $where = " WHERE e.courseid {$coursesql} ";
    $sqlparams = $courseparams;

    if (!empty($search)) {
        $where .= " AND (u.firstname LIKE :q OR u.lastname LIKE :q OR u.email LIKE :q) ";
        $sqlparams['q'] = "%{$search}%";
    }

    if ($onlymanagers) {
        // Only users with role manager in system or course manager capability.
        $where .= " AND EXISTS (SELECT 1 FROM {role_assignments} ra2 JOIN {context} cx2 ON ra2.contextid = cx2.id
                                 JOIN {role} r2 ON r2.id = ra2.roleid
                                 WHERE ra2.userid = u.id AND (r2.shortname IN ('manager'))) ";
    } else {
        // By default, exclude managers and show only students
        $where .= " AND NOT EXISTS (SELECT 1 FROM {role_assignments} ra2 JOIN {context} cx2 ON ra2.contextid = cx2.id
                                     JOIN {role} r2 ON r2.id = ra2.roleid
                                     WHERE ra2.userid = u.id AND (r2.shortname IN ('manager', 'editingteacher', 'teacher'))) ";
    }

    $countsql = "SELECT COUNT(DISTINCT u.id)
                 FROM {user} u
                 JOIN {user_enrolments} ue ON ue.userid = u.id
                 JOIN {enrol} e ON e.id = ue.enrolid
                 $where";
    $total = (int)$DB->get_field_sql($countsql, $sqlparams);

    $pages = $perpage > 0 ? (int)ceil($total / $perpage) : 1;
    $offset = ($page - 1) * $perpage;

    $listsql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                       (SELECT COUNT(DISTINCT e2.courseid) FROM {user_enrolments} ue2 JOIN {enrol} e2 ON ue2.enrolid = e2.id WHERE ue2.userid = u.id) AS course_count
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                $where
                ORDER BY u.lastname ASC, u.firstname ASC";

    $students = $DB->get_records_sql($listsql, $sqlparams, $offset, $perpage);
    
    // Debug logging
    error_log("Teacher Students AJAX - Found " . count($students) . " students");
    error_log("Teacher Students AJAX - Total: " . $total);

    $out = [];
    foreach ($students as $s) {
        // Generate avatar URL using Moodle's standard approach
        $avatar_url = (new moodle_url('/user/pix.php/' . $s->id . '/f1.jpg'))->out();
        
        // Alternative approach using gravatar or default
        if (empty($avatar_url)) {
            $avatar_url = (new moodle_url('/user/pix.php/0/f1'))->out();
        }
        
        // Get course progress data for this student
        $course_progress = get_student_course_progress($s->id, $ids);
        
        // Debug logging for course progress
        error_log("AJAX - Student {$s->id} ({$s->firstname} {$s->lastname}) - Course Progress: " . json_encode($course_progress));
        
        $out[] = [
            'id' => (int)$s->id,
            'first_name' => $s->firstname,
            'last_name' => $s->lastname,
            'name' => trim($s->firstname . ' ' . $s->lastname),
            'email' => $s->email,
            'last_access' => $s->lastaccess ? userdate($s->lastaccess, '%b %e, %Y') : 'Never',
            'course_count' => (int)$s->course_count,
            'courses_not_started' => $course_progress['not_started'],
            'courses_in_progress' => $course_progress['in_progress'],
            'enrolled_courses' => $course_progress['total_enrolled'],
            'finished_courses' => $course_progress['completed'],
            'profile_url' => (new moodle_url('/user/profile.php', ['id' => $s->id]))->out(),
            'avatar_url' => $avatar_url
        ];
    }

    echo json_encode([
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'students' => array_values($out)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}

