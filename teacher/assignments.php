<?php
/**
 * Custom Assignments Page for Teacher Dashboard
 * 
 * This page provides a comprehensive view of all assignments
 * with advanced filtering, search, and management capabilities.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

// Security checks
require_login();
$context = context_system::instance();

// Get teacher's school for filtering
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$school_name = theme_remui_kids_get_teacher_school_name($teacher_company_id);

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher assignments page');
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/assignments.php');
$PAGE->set_title('Assignments & Code Activities');
$PAGE->set_heading('Assignments & Code Activities Management');

// Get parameters for filtering
$courseid = optional_param('courseid', 0, PARAM_INT);
$status = optional_param('status', 'all', PARAM_ALPHA);
$search = optional_param('search', '', PARAM_TEXT);
$sort = optional_param('sort', 'duedate', PARAM_ALPHA);
$activity_types = optional_param_array('activity_types', [], PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 5;
if ($page < 0) {
    $page = 0;
}

$pagination_baseurl = new moodle_url('/theme/remui_kids/teacher/assignments.php');
if (!empty($courseid)) {
    $pagination_baseurl->param('courseid', $courseid);
}
if (!empty($status) && $status !== 'all') {
    $pagination_baseurl->param('status', $status);
}
if (!empty($search)) {
    $pagination_baseurl->param('search', $search);
}
if (!empty($sort)) {
    $pagination_baseurl->param('sort', $sort);
}
if (!empty($activity_types)) {
    foreach ($activity_types as $type) {
        $pagination_baseurl->param('activity_types[]', $type);
    }
}

// Get teacher's courses
$teacher_courses = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate
    FROM {course} c
    JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
    JOIN {role_assignments} ra ON ra.contextid = ctx.id
    JOIN {role} r ON r.id = ra.roleid
    WHERE ra.userid = ? AND r.shortname IN ('teacher', 'editingteacher', 'manager')
    AND c.id > 1
    ORDER BY c.fullname
", [$USER->id]);

// Get assignments and code editor activities based on filters
// Build WHERE clauses and parameters separately for each part of UNION
$where_assign = [];
$where_codeeditor = [];
$params_assign = [$USER->id];
$params_codeeditor = [$USER->id];

// Apply course filter
if ($courseid > 0) {
    $where_assign[] = "a.course = ?";
    $where_codeeditor[] = "ce.course = ?";
    $params_assign[] = $courseid;
    $params_codeeditor[] = $courseid;
}

// Apply search filter
if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $where_assign[] = "(a.name LIKE ? OR c.fullname LIKE ?)";
    $where_codeeditor[] = "(ce.name LIKE ? OR c.fullname LIKE ?)";
    $params_assign[] = $search_param;
    $params_assign[] = $search_param;
    $params_codeeditor[] = $search_param;
    $params_codeeditor[] = $search_param;
}

// Apply status filter
$now = time();
switch ($status) {
    case 'active':
        $where_assign[] = "a.allowsubmissionsfromdate <= ? AND a.duedate >= ?";
        $where_codeeditor[] = "ce.duedate >= ?";
        $params_assign[] = $now;
        $params_assign[] = $now;
        $params_codeeditor[] = $now;
        break;
    case 'overdue':
        $where_assign[] = "a.duedate < ?";
        $where_codeeditor[] = "ce.duedate < ?";
        $params_assign[] = $now;
        $params_codeeditor[] = $now;
        break;
    case 'upcoming':
        $where_assign[] = "a.allowsubmissionsfromdate > ?";
        $where_codeeditor[] = "ce.duedate > ?";
        $params_assign[] = $now;
        $params_codeeditor[] = $now;
        break;
}

// Build the SQL with proper parameter ordering
$where_assign_sql = !empty($where_assign) ? " AND " . implode(" AND ", $where_assign) : "";
$where_codeeditor_sql = !empty($where_codeeditor) ? " AND " . implode(" AND ", $where_codeeditor) : "";

$assignments_sql = "
    SELECT 
        a.id,
        a.name,
        a.intro,
        a.course,
        a.allowsubmissionsfromdate,
        a.duedate,
        a.grade,
        'assign' as activity_type,
        c.fullname as course_name,
        c.shortname as course_shortname,
        cm.id as cmid,
        cm.visible,
        cm.availability,
        (SELECT COUNT(DISTINCT s.userid) 
         FROM {assign_submission} s"
         . ($teacher_company_id ? " JOIN {company_users} cu ON cu.userid = s.userid AND cu.companyid = {$teacher_company_id}" : "") . "
         WHERE s.assignment = a.id 
         AND s.status = 'submitted'
         AND s.latest = 1) as submission_count,
        (SELECT COUNT(*) FROM {assign_grades} g WHERE g.assignment = a.id AND g.grade >= 0) as graded_count,
        (SELECT COUNT(*) FROM {grading_definitions} gd 
         JOIN {grading_areas} ga ON ga.id = gd.areaid 
         WHERE ga.contextid = ctx.id AND gd.method = 'rubric') as has_rubric
    FROM {assign} a
    JOIN {course} c ON c.id = a.course
    JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
    JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
    JOIN {role_assignments} ra ON ra.contextid = ctx.id
    JOIN {role} r ON r.id = ra.roleid
    WHERE ra.userid = ? AND r.shortname IN ('teacher', 'editingteacher', 'manager')
    AND c.id > 1
    AND cm.deletioninprogress = 0" . $where_assign_sql . "
    
    UNION ALL
    
    SELECT 
        ce.id,
        ce.name,
        ce.intro,
        ce.course,
        0 as allowsubmissionsfromdate,
        ce.duedate,
        ce.grade,
        'codeeditor' as activity_type,
        c.fullname as course_name,
        c.shortname as course_shortname,
        cm.id as cmid,
        cm.visible,
        cm.availability,
        (SELECT COUNT(DISTINCT s.userid) 
         FROM {codeeditor_submissions} s 
         WHERE s.codeeditorid = ce.id 
         AND s.status = 'submitted'
         AND s.latest = 1) as submission_count,
        (SELECT COUNT(DISTINCT s.userid) FROM {codeeditor_submissions} s WHERE s.codeeditorid = ce.id AND s.latest = 1 AND s.grade IS NOT NULL AND s.grade >= 0) as graded_count,
        (SELECT COUNT(*) FROM {grading_definitions} gd 
         JOIN {grading_areas} ga ON ga.id = gd.areaid 
         WHERE ga.contextid = ctx.id AND gd.method = 'rubric') as has_rubric
    FROM {codeeditor} ce
    JOIN {course} c ON c.id = ce.course
    JOIN {course_modules} cm ON cm.instance = ce.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'codeeditor')
    JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
    JOIN {role_assignments} ra ON ra.contextid = ctx.id
    JOIN {role} r ON r.id = ra.roleid
    WHERE ra.userid = ? AND r.shortname IN ('teacher', 'editingteacher', 'manager')
    AND c.id > 1
    AND cm.deletioninprogress = 0" . $where_codeeditor_sql . "
";

// Combine parameters in the correct order (assign params first, then codeeditor params)
$params = array_merge($params_assign, $params_codeeditor);

// Wrap UNION in subquery for sorting and activity type filtering
$assignments_sql = "SELECT * FROM ($assignments_sql) AS combined_activities";

// Apply activity type filter (after UNION)
if (!empty($activity_types)) {
    $type_conditions = [];
    foreach ($activity_types as $type) {
        if ($type === 'assign' || $type === 'codeeditor') {
            $type_conditions[] = "activity_type = ?";
            $params[] = $type;
        }
    }
    if (!empty($type_conditions)) {
        $assignments_sql .= " WHERE " . implode(" OR ", $type_conditions);
    }
}

// Apply sorting
switch ($sort) {
    case 'name':
        $assignments_sql .= " ORDER BY name ASC";
        break;
    case 'course':
        $assignments_sql .= " ORDER BY course_name ASC, name ASC";
        break;
    case 'duedate':
    default:
        $assignments_sql .= " ORDER BY duedate ASC, name ASC";
        break;
}

$all_assignments = $DB->get_records_sql($assignments_sql, $params);
$assignments_list = array_values($all_assignments);
$total_assignments = count($assignments_list);
$total_pages = $total_assignments > 0 ? (int)ceil($total_assignments / $perpage) : 1;
if ($page >= $total_pages) {
    $page = max(0, $total_pages - 1);
}
$assignments = array_slice($assignments_list, $page * $perpage, $perpage);

// Calculate statistics
$active_assignments = 0;
$overdue_assignments = 0;
$total_submissions = 0;
$total_graded = 0;

foreach ($assignments_list as $assignment) {
    $now = time();
    
    // Handle both assign and codeeditor types
    if ($assignment->activity_type == 'codeeditor') {
        // For codeeditor, we only have duedate
        if ($assignment->duedate >= $now) {
            $active_assignments++;
        } elseif ($assignment->duedate < $now) {
            $overdue_assignments++;
        }
    } else {
        // For assignments, check both dates
        if ($assignment->allowsubmissionsfromdate <= $now && $assignment->duedate >= $now) {
            $active_assignments++;
        } elseif ($assignment->duedate < $now) {
            $overdue_assignments++;
        }
    }
    
    $total_submissions += $assignment->submission_count;
    $total_graded += $assignment->graded_count;
}

// Check for support videos in 'teachers' category
require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('teachers');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

echo $OUTPUT->header();
?>

<style>
/* Hide Moodle's default main content area */

#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}
/* Teacher Dashboard Styles */
.teacher-css-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

.teacher-dashboard-wrapper .teacher-main-content {
    padding: 0 0 40px;
}

@media (max-width: 768px) {
    .teacher-dashboard-wrapper .teacher-main-content {
        padding: 0 0 30px;
    }
}

.assignments-header {
    background: white;
    padding: 20px 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin: 12px 0 24px;
}

.assignments-title {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 10px 0;
}

.assignments-subtitle {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0 0 20px 0;
}

.assignments-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

@media (max-width: 768px) {
    .assignments-header-top {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
}

.assignments-stats {
    background: white;
    padding: 20px 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 24px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 12px;
}

.stat-card {
    background: white;
    padding: 16px;
    border-radius: 10px;
    box-shadow: 0 1px 6px rgba(0,0,0,0.07);
    border-top: 4px solid #a5b4fc;
    text-align: center;
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0;
}


.stat-label {
    color: #7f8c8d;
    font-size: 14px;
    margin: 5px 0 0 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filters-section {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    border: 1px solid #e9ecef;
}

.filters-row {
    display: grid;
    grid-template-columns: 1fr 0.9fr 0.8fr 0.9fr 0.9fr auto;
    gap: 16px;
    align-items: end;
}

@media (max-width: 1400px) {
    .filters-row {
        grid-template-columns: 1fr 1fr 1fr;
        gap: 16px;
    }
    
    .filter-group:nth-child(1) {
        grid-column: span 3; /* Search takes full width */
    }
    
    .filter-actions {
        grid-column: span 3;
        justify-content: flex-end;
    }
}

@media (max-width: 992px) {
    .filters-row {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    
    .filter-group:nth-child(1) {
        grid-column: span 2; /* Search takes full width */
    }
    
    .filter-actions {
        grid-column: span 2;
    }
}

@media (max-width: 576px) {
    .filters-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .filter-group:nth-child(1),
    .filter-actions {
        grid-column: span 1;
    }
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-actions {
    grid-column: span 1;
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.filter-label i {
    color: #3498db;
    font-size: 14px;
}

.filter-input, .filter-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

/* Enhanced Search Input */
.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-input-icon {
    position: absolute;
    left: 12px;
    color: #3498db;
    font-size: 16px;
    pointer-events: none;
    z-index: 1;
}

.filter-search {
    padding-left: 40px !important;
    padding-right: 40px !important;
    border: 2px solid #3498db !important;
    font-weight: 500;
}

.filter-search:focus {
    background: white;
    border-color: #2980b9 !important;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15) !important;
}

.filter-search::placeholder {
    color: #7f8c8d;
    font-weight: 400;
}

.clear-search-btn {
    position: absolute;
    right: 8px;
    background: #dc3545;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    font-size: 12px;
}

.clear-search-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

/* Multi-Select Dropdown */
.multi-select-wrapper {
    position: relative;
    width: 100%;
}

.multi-select-display {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    user-select: none;
}

.multi-select-display:hover {
    border-color: #3498db;
    background: #f8f9fa;
}

.multi-select-display.active {
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.multi-select-display i {
    font-size: 12px;
    transition: transform 0.3s ease;
    color: #6c757d;
}

.multi-select-display.active i {
    transform: rotate(180deg);
}

.multi-select-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    overflow: hidden;
}

.multi-select-dropdown.active {
    display: block;
    animation: dropdownSlideIn 0.2s ease-out;
}

@keyframes dropdownSlideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.multi-select-option {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: background 0.2s ease;
    border-bottom: 1px solid #f1f3f4;
}

.multi-select-option:last-child {
    border-bottom: none;
}

.multi-select-option:hover {
    background: #f8f9fa;
}

.multi-select-option input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #3498db;
}

.multi-select-option label {
    flex: 1;
    cursor: pointer;
    font-size: 14px;
    color: #2c3e50;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.btn-primary {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
    text-decoration: none;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2980b9, #21618c);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
    color: white;
}

.btn-secondary {
    background: white;
    color: #6c757d;
    border: 2px solid #dee2e6;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-secondary:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
    color: #495057;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Assignments Table Styles */
.assignments-table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    overflow: hidden;
}

.table-responsive {
    overflow-x: auto;
}

.assignments-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.assignments-table th {
    background: #f1f5f9;
    color: #1f2937;
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px 12px;
    text-align: left;
    border: none;
    border-bottom: 2px solid #e2e8f0;
}

.assignments-table th:first-child {
    border-top-left-radius: 12px;
}

.assignments-table th:last-child {
    border-top-right-radius: 12px;
}

.assignments-table td {
    padding: 18px 14px;
    border-bottom: 1px solid #edf1f7;
    vertical-align: top;
}

.assignment-row {
    background: #ffffff;
    transition: background 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
}

.assignment-row:nth-child(even) {
    background: #f9fbff;
}

.assignment-row:hover {
    background-color: #eef4ff;
    box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.15);
}

.assignment-row:last-child td {
    border-bottom: none;
}

/* Column Widths */
.col-assignment {
    width: 30%;
    min-width: 250px;
}

.col-course {
    width: 15%;
    min-width: 120px;
}

.col-status {
    width: 12%;
    min-width: 100px;
}

.col-due-date {
    width: 15%;
    min-width: 120px;
}

.col-submissions,
.col-graded {
    width: 10%;
    min-width: 80px;
}

.col-progress {
    width: 12%;
    min-width: 100px;
}

.col-actions {
    width: 16%;
    min-width: 120px;
    white-space: nowrap;
}

/* Assignment Info */
.assignment-info {
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.assignment-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    flex-shrink: 0;
}

.assignment-icon.assign {
    background: linear-gradient(135deg, #e0f2ff, #c7d2fe);
    color: #1e3a8a;
    box-shadow: none;
}

.assignment-icon.codeeditor {
    background: linear-gradient(135deg, #fef3c7, #ffd6a5);
    color: #92400e;
    box-shadow: none;
}

.assignment-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.assignment-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.assignment-title {
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
    margin: 0;
    line-height: 1.3;
    flex: 1;
    min-width: 200px;
}

/* Group Indicator */
.group-indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: #3b82f6;
    color: white;
    border-radius: 4px;
    border: none;
    font-size: 12px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    cursor: pointer;
    padding: 0;
}

.group-indicator:hover {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.4);
    color: white;
}

/* Rubric Indicator */
.rubric-indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: #6f42c1;
    color: white;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.rubric-indicator:hover {
    background: #5a32a3;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(111, 66, 193, 0.3);
    color: white;
    text-decoration: none;
}

.assignment-description {
    font-size: 13px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Course Name */
.course-name {
    font-size: 14px;
    font-weight: 500;
    color: #5d6d7e;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.status-active {
    background: #dbeafe;
    color: #1e3a8a;
}

.status-overdue {
    background: #f8d7da;
    color: #721c24;
}

.status-upcoming {
    background: #d1ecf1;
    color: #0c5460;
}

.status-completed {
    background: #e2e3e5;
    color: #383d41;
}

/* Due Date */
.due-date-info {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #5d6d7e;
}

.due-date-info i {
    color: #95a5a6;
}

/* Submission and Graded Counts */
.submission-count,
.graded-count {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.count-number {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1;
}

.count-label {
    font-size: 11px;
    color: #7f8c8d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Progress Bar */
.progress-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-bar {
    flex: 1;
    height: 8px;
    background: #ecf0f1;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 12px;
    font-weight: 600;
    color: #2c3e50;
    min-width: 35px;
    text-align: right;
}

/* Action Buttons */
.action-buttons {
    display: inline-flex;
    gap: 4px;
    align-items: center;
    flex-wrap: nowrap;
}

.action-btn,
.btn-view,
.btn-edit,
.btn-grade,
.btn-rubric,
.btn-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    padding: 0;
    line-height: 1;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 14px;
    border: none;
    font-weight: 600;
}

.btn-view {
    background: #e0edff;
    color: #1d4ed8;
}

.btn-view:hover {
    background: #c7dcff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.25);
}

.btn-grade {
    background: #dbeafe;
    color: #1e40af;
}

.btn-grade:hover {
    background: #bfdbfe;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-edit {
    background: #efe4ff;
    color: #6b21a8;
}

.btn-edit:hover {
    background: #e0ccff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(107, 33, 168, 0.25);
    color: #591c94;
}

.btn-rubric {
    background: #fef3c7;
    color: #b45309;
}

.btn-rubric:hover {
    background: #fde68a;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(180, 83, 9, 0.25);
}

.btn-delete {
    background: #fee2e2;
    color: #b91c1c;
    border: none;
    cursor: pointer;
}

.btn-delete:hover {
    background: #fecaca;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(185, 28, 28, 0.25);
    color: #991b1b;
}

/* Notification Toast */
.global-toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    z-index: 12000;
}

.global-toast {
    min-width: 280px;
    max-width: 360px;
    background: #ffffff;
    border-left: 4px solid #3b82f6;
    border-radius: 10px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.15);
    padding: 14px 18px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    animation: toastSlideIn 0.3s ease;
    font-size: 14px;
    color: #2b2d42;
}

.global-toast.error {
    border-left-color: #dc3545;
}

.global-toast i {
    font-size: 18px;
    margin-top: 2px;
}

.global-toast.success i {
    color: #3b82f6;
}

.global-toast.error i {
    color: #dc3545;
}

.global-toast strong {
    display: block;
    font-weight: 700;
    margin-bottom: 2px;
}

.toast-close-btn {
    background: none;
    border: none;
    color: #adb5bd;
    font-size: 16px;
    cursor: pointer;
    position: absolute;
    top: 8px;
    right: 10px;
}

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Delete Confirmation Modal */
.delete-confirm-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 11000;
    padding: 20px;
}

.delete-confirm-modal.active {
    display: flex;
}

.delete-confirm-content {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
    animation: modalPop 0.25s ease-out;
    overflow: hidden;
}

.delete-confirm-header {
    padding: 20px 24px 12px;
    border-bottom: 1px solid #f1f3f5;
}

.delete-confirm-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #dc3545;
    display: flex;
    align-items: center;
    gap: 10px;
}

.delete-confirm-body {
    padding: 12px 24px 20px;
    color: #495057;
    font-size: 14px;
    line-height: 1.6;
}

.delete-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px 24px;
    border-top: 1px solid #f1f3f5;
    background: #f8f9fa;
}

.delete-modal-btn {
    border: none;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.delete-modal-btn.cancel {
    background: #e9ecef;
    color: #495057;
}

.delete-modal-btn.cancel:hover {
    background: #dde2e6;
}

.delete-modal-btn.delete {
    background: #dc3545;
    color: #ffffff;
    box-shadow: 0 8px 16px rgba(220, 53, 69, 0.25);
}

.delete-modal-btn.delete:hover {
    background: #c82333;
}

@keyframes modalPop {
    from {
        opacity: 0;
        transform: translateY(15px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: #dbeafe;
    color: #1e3a8a;
}

.status-overdue {
    background: #f8d7da;
    color: #721c24;
}

.status-upcoming {
    background: #d1ecf1;
    color: #0c5460;
}

.status-completed {
    background: #e2e3e5;
    color: #383d41;
}

@media (max-width: 768px) {
    .filters-row {
        flex-direction: column;
    }
    
    .assignments-table-container {
        overflow-x: auto;
    }
    
    .assignments-table {
        min-width: 800px;
    }
    
    .assignment-title {
        font-size: 14px;
    }
    
    .assignment-description {
        font-size: 12px;
    }
    
    .rubric-indicator {
        width: 20px;
        height: 20px;
        font-size: 10px;
    }
    
    .status-badge {
        font-size: 10px;
        padding: 4px 8px;
    }
    
    .action-btn {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .count-number {
        font-size: 16px;
    }
    
    .count-label {
        font-size: 10px;
    }
}

/* Create Assignment Button */
.btn-create-assignment {
    background: linear-gradient(135deg, #c7d2fe, #a5b4fc);
    color: #1f235a;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
}

.btn-create-assignment:hover {
    background: linear-gradient(135deg, #b4c1fb, #94a3ff);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(148, 163, 184, 0.35);
    color: #1f235a;
    text-decoration: none;
}

.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin: 24px 0;
    flex-wrap: wrap;
}

.pagination-link {
    padding: 8px 14px;
    border: 1px solid #dce0e5;
    border-radius: 6px;
    color: #495057;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
    background: white;
}

.pagination-link:hover {
    border-color: #0d6efd;
    color: #0d6efd;
}

.pagination-link.active {
    background: #0d6efd;
    color: white;
    border-color: #0d6efd;
    cursor: default;
}

.pagination-link.disabled {
    opacity: 0.5;
    pointer-events: none;
    cursor: default;
}

</style>

<div class="global-toast-container" id="globalToastContainer"></div>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>

        <div class="teacher-main-content">
            <!-- Header Section -->
            <div class="assignments-header">
                <div class="assignments-header-top">
                    <div>
                        <h1 class="assignments-title">Assignments & Code Activities</h1>
                        <p class="assignments-subtitle">Manage and track all your course assignments and code editor activities</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if ($has_help_videos): ?>
                        <a class="teacher-help-button" id="teacherHelpButton" style="text-decoration: none; display: inline-flex;">
                            <i class="fa fa-question-circle"></i>
                            <span>Need Help?</span>
                            <span class="help-badge-count"><?php echo $help_videos_count; ?></span>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn-create-assignment" onclick="openActivityTypeModal()" style="border: none; cursor: pointer;">
                            <i class="fa fa-plus"></i> Create Activity
                        </button>
                    </div>
                </div>
            </div>

            <div class="assignments-stats">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3 class="stat-number"><?php echo $total_assignments; ?></h3>
                        <p class="stat-label">Total Activities</p>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-number"><?php echo $active_assignments; ?></h3>
                        <p class="stat-label">Active</p>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-number"><?php echo $overdue_assignments; ?></h3>
                        <p class="stat-label">Overdue</p>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-number"><?php echo $total_submissions; ?></h3>
                        <p class="stat-label">Total Submissions</p>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-number"><?php echo $total_graded; ?></h3>
                        <p class="stat-label">Graded</p>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" class="filters-row">
                    <!-- 1. Search (Leftmost) -->
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <div class="search-input-wrapper">
                            <i class="fa fa-search search-input-icon"></i>
                            <input type="text" name="search" id="searchInput" class="filter-input filter-search" placeholder="Search by name or course..." value="<?php echo htmlspecialchars($search); ?>">
                            <?php if (!empty($search)): ?>
                                <button type="button" class="clear-search-btn" onclick="clearSearch()">
                                    <i class="fa fa-times"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- 2. Course -->
                    <div class="filter-group">
                        <label class="filter-label">Course</label>
                        <select name="courseid" id="courseSelect" class="filter-select" onchange="autoApplyFilters()">
                            <option value="0">All Courses</option>
                            <?php foreach ($teacher_courses as $course): ?>
                                <option value="<?php echo $course->id; ?>" <?php echo ($courseid == $course->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($course->fullname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- 3. Activity Type -->
                    <div class="filter-group">
                        <label class="filter-label">Activity Type</label>
                        <div class="multi-select-wrapper">
                            <div class="multi-select-display" onclick="toggleActivityTypeDropdown()">
                                <span id="activityTypeDisplay">All Types</span>
                                <i class="fa fa-chevron-down"></i>
                            </div>
                            <div class="multi-select-dropdown" id="activityTypeDropdown">
                                <div class="multi-select-option">
                                    <input type="checkbox" id="type_assign" name="activity_types[]" value="assign" 
                                           <?php echo in_array('assign', $activity_types) ? 'checked' : ''; ?>
                                           onchange="updateActivityTypeDisplay(); autoApplyFilters();">
                                    <label for="type_assign">
                                        Assignment
                                    </label>
                                </div>
                                <div class="multi-select-option">
                                    <input type="checkbox" id="type_codeeditor" name="activity_types[]" value="codeeditor"
                                           <?php echo in_array('codeeditor', $activity_types) ? 'checked' : ''; ?>
                                           onchange="updateActivityTypeDisplay(); autoApplyFilters();">
                                    <label for="type_codeeditor">
                                        Code Editor
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 4. Status -->
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" id="statusSelect" class="filter-select" onchange="autoApplyFilters()">
                            <option value="all" <?php echo ($status == 'all') ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo ($status == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="overdue" <?php echo ($status == 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                            <option value="upcoming" <?php echo ($status == 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                        </select>
                    </div>
                    
                    <!-- 5. Sort By -->
                    <div class="filter-group">
                        <label class="filter-label">Sort By</label>
                        <select name="sort" id="sortSelect" class="filter-select" onchange="autoApplyFilters()">
                            <option value="duedate" <?php echo ($sort == 'duedate') ? 'selected' : ''; ?>>Due Date</option>
                            <option value="name" <?php echo ($sort == 'name') ? 'selected' : ''; ?>>Name</option>
                            <option value="course" <?php echo ($sort == 'course') ? 'selected' : ''; ?>>Course</option>
                        </select>
                    </div>
                    
                    <!-- 6. Clear Button -->
                    <div class="filter-group filter-actions">
                        <a href="<?php echo $PAGE->url; ?>" class="btn-secondary">
                            <i class="fa fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Assignments Table -->
            <div class="assignments-table-container">
                <?php if (empty($assignments)): ?>
                    <div class="no-assignments" style="text-align: center; padding: 60px; color: #7f8c8d; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <i class="fa fa-tasks" style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;"></i>
                        <h3 style="margin: 0 0 10px 0; color: #5d6d7e;">No assignments found</h3>
                        <p style="margin: 0; font-size: 16px;">Try adjusting your filters or create a new assignment.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="assignments-table">
                            <thead>
                                <tr>
                                    <th class="col-assignment">Assignment</th>
                                    <th class="col-course">Course</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-due-date">Due Date</th>
                                    <th class="col-submissions">Submissions</th>
                                    <th class="col-graded">Graded</th>
                                    <th class="col-progress">Progress</th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <?php
                                    // Determine status
                                    $now = time();
                                    $status_class = 'status-completed';
                                    $status_text = 'Completed';
                                    $status_icon = 'fa-check-circle';
                                    
                                    if ($assignment->activity_type == 'codeeditor') {
                                        // For codeeditor, simpler status
                                        if ($assignment->duedate < $now) {
                                            $status_class = 'status-overdue';
                                            $status_text = 'Overdue';
                                            $status_icon = 'fa-exclamation-triangle';
                                        } elseif ($assignment->duedate >= $now) {
                                            $status_class = 'status-active';
                                            $status_text = 'Active';
                                            $status_icon = 'fa-play-circle';
                                        }
                                    } else {
                                        // For assignments, check both dates
                                        if ($assignment->duedate < $now) {
                                            $status_class = 'status-overdue';
                                            $status_text = 'Overdue';
                                            $status_icon = 'fa-exclamation-triangle';
                                        } elseif ($assignment->allowsubmissionsfromdate > $now) {
                                            $status_class = 'status-upcoming';
                                            $status_text = 'Upcoming';
                                            $status_icon = 'fa-clock-o';
                                        } elseif ($assignment->allowsubmissionsfromdate <= $now && $assignment->duedate >= $now) {
                                            $status_class = 'status-active';
                                            $status_text = 'Active';
                                            $status_icon = 'fa-play-circle';
                                        }
                                    }
                                    
                                    // Calculate completion rate
                                    $completion_rate = $assignment->submission_count > 0 ? round(($assignment->graded_count / $assignment->submission_count) * 100) : 0;
                                    $has_rubric = $assignment->has_rubric > 0;
                                    
                                    // Check if assignment has group restrictions
                                    $group_ids = [];
                                    if (!empty($assignment->availability)) {
                                        $availability = json_decode($assignment->availability, true);
                                        if ($availability && isset($availability['c']) && is_array($availability['c'])) {
                                            foreach ($availability['c'] as $condition) {
                                                if (isset($condition['type']) && $condition['type'] === 'group' && isset($condition['id'])) {
                                                    $group_ids[] = $condition['id'];
                                                }
                                            }
                                        }
                                    }
                                    $has_group_restriction = !empty($group_ids);
                                    ?>
                                    <?php
                                        $viewurl = ($assignment->activity_type == 'codeeditor')
                                            ? $CFG->wwwroot . '/mod/codeeditor/view.php?id=' . $assignment->cmid
                                            : $CFG->wwwroot . '/mod/assign/view.php?id=' . $assignment->cmid;
                                    ?>
                                    <tr class="assignment-row" data-view-url="<?php echo $viewurl; ?>">
                                        <td class="col-assignment">
                                            <div class="assignment-info">
                                                <div class="assignment-title-row">
                                                    <!-- Activity Type Icon Badge (Before Title) -->
                                                     <div class="assignment-icon <?php echo $assignment->activity_type == 'codeeditor' ? 'codeeditor' : 'assign'; ?>" title="<?php echo $assignment->activity_type == 'codeeditor' ? 'Code Editor Activity' : 'Assignment Activity'; ?>">
                                                         <i class="fa <?php echo $assignment->activity_type == 'codeeditor' ? 'fa-code' : 'fa-file-alt'; ?>"></i>
                                                     </div>
                                                     <div class="assignment-details">
                                                         <div class="assignment-title-row">
                                                             <h4 class="assignment-title"><?php echo format_string($assignment->name); ?></h4>
                                                             <?php if ($has_group_restriction): ?>
                                                                 <button type="button" class="group-indicator" 
                                                                         title="Assigned to specific groups - Click to view"
                                                                         onclick="showGroupMembers(<?php echo $assignment->cmid; ?>, <?php echo htmlspecialchars(json_encode($group_ids), ENT_QUOTES, 'UTF-8'); ?>)">
                                                                     <i class="fa fa-users"></i>
                                                                 </button>
                                                             <?php endif; ?>
                                                             <?php if ($has_rubric): ?>
                                                                 <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/rubric_grading.php?assignmentid=<?php echo $assignment->id; ?>&courseid=<?php echo $assignment->course; ?>" 
                                                                    class="rubric-indicator" title="Rubric Grading Available">
                                                                     <i class="fa fa-list-alt"></i>
                                                                 </a>
                                                             <?php endif; ?>
                                                         </div>
                                                         <p class="assignment-description">
                                                             <?php echo format_string(substr(strip_tags($assignment->intro), 0, 120)); ?>
                                                             <?php if (strlen(strip_tags($assignment->intro)) > 120): ?>...<?php endif; ?>
                                                         </p>
                                                     </div>
                                            </div>
                                        </td>
                                        <td class="col-course">
                                            <span class="course-name"><?php echo format_string($assignment->course_name); ?></span>
                                        </td>
                                        <td class="col-status">
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <i class="fa <?php echo $status_icon; ?>"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="col-due-date">
                                            <div class="due-date-info">
                                                <i class="fa fa-calendar"></i>
                                                <span><?php echo userdate($assignment->duedate, get_string('strftimedatefullshort')); ?></span>
                                            </div>
                                        </td>
                                        <td class="col-submissions">
                                            <div class="submission-count">
                                                <span class="count-number"><?php echo $assignment->submission_count; ?></span>
                                                <span class="count-label">submissions</span>
                                            </div>
                                        </td>
                                        <td class="col-graded">
                                            <div class="graded-count">
                                                <span class="count-number"><?php echo $assignment->graded_count; ?></span>
                                                <span class="count-label">graded</span>
                                            </div>
                                        </td>
                                        <td class="col-progress">
                                            <div class="progress-container">
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $completion_rate; ?>%"></div>
                                                </div>
                                                <span class="progress-text"><?php echo $completion_rate; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="col-actions">
                                            <div class="action-buttons">
                                                <?php if ($assignment->activity_type == 'codeeditor'): ?>
                                                    <!-- Code Editor Actions -->
                                                    <a href="<?php echo $CFG->wwwroot; ?>/course/modedit.php?update=<?php echo $assignment->cmid; ?>" 
                                                       class="action-btn btn-edit" title="Edit Code Editor">
                                                        <i class="fa fa-pencil-alt"></i>
                                                    </a>
                                                    <a href="<?php echo $CFG->wwwroot; ?>/mod/codeeditor/grading.php?id=<?php echo $assignment->cmid; ?>" 
                                                       class="action-btn btn-grade" title="Grade Code Submissions">
                                                        <i class="fa fa-check-circle"></i>
                                                    </a>
                                                    <button type="button" 
                                                            class="action-btn btn-delete" 
                                                            title="Delete Code Editor"
                                                            onclick="promptDeleteAssignment(<?php echo $assignment->cmid; ?>, '<?php echo $assignment->activity_type; ?>', '<?php echo htmlspecialchars(addslashes($assignment->name), ENT_QUOTES); ?>', this)">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Assignment Actions -->
                                                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/edit_assignment_page.php?id=<?php echo $assignment->cmid; ?>&courseid=<?php echo $assignment->course; ?>" 
                                                       class="action-btn btn-edit" title="Edit Assignment">
                                                        <i class="fa fa-pencil-alt"></i>
                                                    </a>
                                                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/grade_assignment.php?id=<?php echo $assignment->cmid; ?>" 
                                                       class="action-btn btn-grade" title="Grade Submissions">
                                                        <i class="fa fa-check-circle"></i>
                                                    </a>
                                                    <button type="button" 
                                                            class="action-btn btn-delete" 
                                                            title="Delete Assignment"
                                                            onclick="promptDeleteAssignment(<?php echo $assignment->cmid; ?>, '<?php echo $assignment->activity_type; ?>', '<?php echo htmlspecialchars(addslashes($assignment->name), ENT_QUOTES); ?>', this)">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_assignments > 0 && $total_pages > 1): ?>
                <?php
                    $prevurl = new moodle_url($pagination_baseurl);
                    $prevurl->param('page', max(0, $page - 1));
                    $nexturl = new moodle_url($pagination_baseurl);
                    $nexturl->param('page', min($total_pages - 1, $page + 1));
                ?>
                <div class="pagination-container">
                    <a class="pagination-link <?php echo $page == 0 ? 'disabled' : ''; ?>" href="<?php echo $page == 0 ? '#' : $prevurl->out(); ?>">
                        &laquo; Prev
                    </a>
                    <?php for ($i = 0; $i < $total_pages; $i++): 
                        $pageurl = new moodle_url($pagination_baseurl);
                        $pageurl->param('page', $i);
                    ?>
                        <a class="pagination-link <?php echo $i == $page ? 'active' : ''; ?>" href="<?php echo $pageurl->out(); ?>">
                            <?php echo $i + 1; ?>
                        </a>
                    <?php endfor; ?>
                    <a class="pagination-link <?php echo $page >= $total_pages - 1 ? 'disabled' : ''; ?>" href="<?php echo $page >= $total_pages - 1 ? '#' : $nexturl->out(); ?>">
                        Next &raquo;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="delete-confirm-modal">
    <div class="delete-confirm-content">
        <div class="delete-confirm-header">
            <h3 class="delete-confirm-title">
                <i class="fa fa-trash"></i>
                Delete Activity
            </h3>
        </div>
        <div class="delete-confirm-body">
            <p>
                Are you sure you want to delete 
                <strong id="deleteAssignmentName">this activity</strong>?
            </p>
            <p style="margin: 10px 0 0; color: #868e96;">
                This action cannot be undone and will permanently remove the activity
                along with all submissions, grades, and associated data.
            </p>
        </div>
        <div class="delete-confirm-actions">
            <button type="button" class="delete-modal-btn cancel" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="delete-modal-btn delete" onclick="confirmDeleteAssignment()">
                <i class="fa fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- Group Members Modal -->
<div id="groupMembersModal" class="group-members-modal">
    <div class="group-members-modal-content">
        <div class="group-members-modal-header">
            <h3 class="group-members-modal-title">
                <i class="fa fa-users"></i>
                <span id="modalGroupTitle">Group Members</span>
            </h3>
            <button class="group-members-modal-close" onclick="closeGroupMembersModal()">&times;</button>
        </div>
        <div class="group-members-modal-body">
            <div id="groupMembersLoading" class="loading-spinner">
                <i class="fa fa-spinner fa-spin"></i> Loading group members...
            </div>
            <div id="groupMembersContent" style="display: none;">
                <!-- Groups and members will be loaded here -->
            </div>
        </div>
    </div>
</div>

<style>
/* Group Members Modal */
.group-members-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 10000;
    padding: 20px;
    overflow-y: auto;
}

.group-members-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.group-members-modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 800px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.group-members-modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    color: white;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.group-members-modal-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.group-members-modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    font-size: 28px;
    color: white;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.group-members-modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
}

.group-members-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

.loading-spinner {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
    font-size: 16px;
}

.loading-spinner i {
    font-size: 24px;
    margin-right: 10px;
}

.group-section {
    margin-bottom: 30px;
}

.group-section:last-child {
    margin-bottom: 0;
}

.group-header {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.group-name {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.group-member-count {
    background: #3b82f6;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.members-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.member-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.member-card:hover {
    background: #f8f9fa;
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 18px;
    flex-shrink: 0;
}

.member-info {
    flex: 1;
    min-width: 0;
}

.member-name {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    margin-bottom: 4px;
}

.member-email {
    font-size: 13px;
    color: #6c757d;
}

.no-members {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}

.no-members i {
    font-size: 48px;
    color: #dee2e6;
    margin-bottom: 16px;
    display: block;
}

.error-message {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}

.error-message i {
    margin-right: 8px;
}
</style>

<!-- Activity Type Selection Modal -->
<div id="activityTypeModal" class="activity-type-modal">
    <div class="activity-type-modal-content">
        <div class="activity-type-modal-header">
            <h3 class="activity-type-modal-title">
                <i class="fa fa-plus-circle"></i>
                Create New Activity
            </h3>
            <button class="activity-type-modal-close" onclick="closeActivityTypeModal()">&times;</button>
        </div>
        <div class="activity-type-modal-body">
            <p style="text-align: center; color: #6c757d; margin-bottom: 30px; font-size: 15px;">
                Choose the type of activity you want to create
            </p>
            <div class="activity-type-options" style="justify-content:center;">
                <a href="create_assignment_page.php" class="activity-type-card" style="max-width:320px;">
                    <div class="activity-type-icon">
                        <i class="fa fa-file-alt"></i>
                    </div>
                    <h4 class="activity-type-name">Assignment</h4>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Activity Type Modal */
.activity-type-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 10000;
    padding: 20px;
    overflow-y: auto;
}

.activity-type-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.activity-type-modal-content {
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.activity-type-modal-header {
    padding: 24px 30px;
    background: #ffffff;
    border-radius: 16px 16px 0 0;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #1f2937;
}

.activity-type-modal-title {
    font-size: 22px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    color: #111827;
}

.activity-type-modal-title i {
    color: #0d6efd;
}

.activity-type-modal-close {
    background: transparent;
    border: 1px solid #d1d5db;
    font-size: 24px;
    color: #4b5563;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.activity-type-modal-close:hover {
    background: #f1f5f9;
    color: #111827;
}

.activity-type-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 40px 30px;
}

.activity-type-options {
    display: flex;
    gap: 24px;
    justify-content: center;
    flex-wrap: wrap;
}

.activity-type-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 30px 24px;
    text-decoration: none;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.activity-type-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: #0d6efd;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.activity-type-card:hover {
    border-color: #d1d5db;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    transform: translateY(-4px);
}

.activity-type-card:hover::before {
    opacity: 1;
}

.activity-type-icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #198754;
    font-size: 36px;
    margin-bottom: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    box-shadow: none;
}

.activity-type-name {
    font-size: 22px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 12px 0;
}

.activity-type-description {
    font-size: 14px;
    color: #6c757d;
    margin: 0 0 20px 0;
    line-height: 1.6;
}

.activity-type-features {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
    width: 100%;
}

.activity-type-features span {
    font-size: 13px;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 8px;
}

.activity-type-features i {
    color: #3b82f6;
    font-size: 12px;
}

@media (max-width: 768px) {
    .activity-type-options {
        grid-template-columns: 1fr;
    }
}

/* Teacher Help Button Styles */
.teacher-help-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.teacher-help-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.5);
}

.teacher-help-button i {
    font-size: 16px;
}

.help-badge-count {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
    min-width: 20px;
    text-align: center;
}

/* Teacher Help Modal Styles */
.teacher-help-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 10000;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.3s ease;
}

.teacher-help-modal.active {
    display: flex;
}

.teacher-help-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    animation: slideUp 0.3s ease;
}

.teacher-help-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    border-bottom: 2px solid #f0f0f0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.teacher-help-modal-header h2 {
    margin: 0;
    font-size: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.teacher-help-modal-close {
    background: none;
    border: none;
    font-size: 32px;
    cursor: pointer;
    color: white;
    transition: transform 0.3s ease;
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.teacher-help-modal-close:hover {
    transform: rotate(90deg);
}

.teacher-help-modal-body {
    padding: 25px;
    overflow-y: auto;
    flex: 1;
}

.teacher-help-videos-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.teacher-help-video-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.teacher-help-video-item:hover {
    background: #e9ecef;
    border-color: #667eea;
    transform: translateX(5px);
}

.teacher-help-video-item h4 {
    margin: 0 0 8px 0;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.teacher-help-video-item p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.teacher-back-to-list-btn {
    background: #667eea;
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    margin-bottom: 15px;
}

.teacher-back-to-list-btn:hover {
    background: #5568d3;
    transform: translateX(-3px);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .teacher-help-button span:not(.help-badge-count) {
        display: none;
    }
    
    .teacher-help-modal-content {
        width: 95%;
        max-height: 90vh;
    }
}
</style>

<script>
function toggleTeacherSidebar() {
    const sidebar = document.getElementById('teacherSidebar');
    sidebar.classList.toggle('collapsed');
}

// Activity Type Modal Functions
function openActivityTypeModal() {
    const modal = document.getElementById('activityTypeModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeActivityTypeModal() {
    const modal = document.getElementById('activityTypeModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Multi-Select Activity Type Dropdown Functions
function toggleActivityTypeDropdown() {
    const dropdown = document.getElementById('activityTypeDropdown');
    const display = document.querySelector('.multi-select-display');
    
    dropdown.classList.toggle('active');
    display.classList.toggle('active');
}

function updateActivityTypeDisplay() {
    const assignCheckbox = document.getElementById('type_assign');
    const codeeditorCheckbox = document.getElementById('type_codeeditor');
    const displayText = document.getElementById('activityTypeDisplay');
    
    const selectedTypes = [];
    if (assignCheckbox && assignCheckbox.checked) selectedTypes.push('Assignment');
    if (codeeditorCheckbox && codeeditorCheckbox.checked) selectedTypes.push('Code Editor');
    
    if (selectedTypes.length === 0) {
        displayText.textContent = 'All Types';
        displayText.style.color = '#495057';
    } else if (selectedTypes.length === 1) {
        displayText.textContent = selectedTypes[0];
        displayText.style.color = '#2c3e50';
        displayText.style.fontWeight = '600';
    } else {
        displayText.textContent = selectedTypes.length + ' selected';
        displayText.style.color = '#3498db';
        displayText.style.fontWeight = '600';
    }
}

// Auto-apply filters when any filter changes
function autoApplyFilters() {
    const form = document.querySelector('.filters-row');
    if (form) {
        form.submit();
    }
}

// Debounce function for search input
let searchTimeout;
function handleSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        autoApplyFilters();
    }, 500); // Wait 500ms after user stops typing
}

function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = '';
        autoApplyFilters();
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrapper = e.target.closest('.multi-select-wrapper');
    if (!wrapper) {
        const dropdown = document.getElementById('activityTypeDropdown');
        const display = document.querySelector('.multi-select-display');
        if (dropdown && display) {
            dropdown.classList.remove('active');
            display.classList.remove('active');
        }
    }
});

// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.getElementById('teacherSidebar');
    
    if (window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
    }
    
    window.addEventListener('resize', function() {
        if (window.innerWidth <= 768) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    });
    
    // Initialize activity type display on page load
    updateActivityTypeDisplay();
    
    // Add event listener for search input with debounce
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearchInput);
    }

    document.querySelectorAll('.assignment-row').forEach(row => {
        const url = row.dataset.viewUrl;
        if (!url) {
            return;
        }
        row.addEventListener('click', function(event) {
            if (event.target.closest('.action-buttons') || event.target.closest('.group-indicator') || event.target.closest('.rubric-indicator')) {
                return;
            }
            window.location.href = url;
        });
    });
});

// Group Members Modal Functions
function showGroupMembers(cmid, groupIds) {
    const modal = document.getElementById('groupMembersModal');
    const loading = document.getElementById('groupMembersLoading');
    const content = document.getElementById('groupMembersContent');
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Show loading, hide content
    loading.style.display = 'block';
    content.style.display = 'none';
    content.innerHTML = '';
    
    // Fetch group members via AJAX
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/get_group_members.php?groupids=' + JSON.stringify(groupIds))
        .then(response => response.json())
        .then(data => {
            loading.style.display = 'none';
            
            if (data.success && data.groups && data.groups.length > 0) {
                renderGroupMembers(data.groups);
                content.style.display = 'block';
            } else {
                content.innerHTML = '<div class="no-members"><i class="fa fa-users"></i><p>No members found in the selected groups.</p></div>';
                content.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error fetching group members:', error);
            loading.style.display = 'none';
            content.innerHTML = '<div class="error-message"><i class="fa fa-exclamation-circle"></i>Error loading group members. Please try again.</div>';
            content.style.display = 'block';
        });
}

function renderGroupMembers(groups) {
    const content = document.getElementById('groupMembersContent');
    let html = '';
    
    groups.forEach(group => {
        html += '<div class="group-section">';
        html += '<div class="group-header">';
        html += '<h4 class="group-name">' + escapeHtml(group.name) + '</h4>';
        html += '<span class="group-member-count">' + group.members.length + ' member' + (group.members.length !== 1 ? 's' : '') + '</span>';
        html += '</div>';
        
        if (group.members.length > 0) {
            html += '<div class="members-list">';
            group.members.forEach(member => {
                const initials = member.fullname.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
                html += '<div class="member-card">';
                html += '<div class="member-avatar">' + initials + '</div>';
                html += '<div class="member-info">';
                html += '<div class="member-name">' + escapeHtml(member.fullname) + '</div>';
                html += '<div class="member-email">' + escapeHtml(member.email) + '</div>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            html += '<div class="no-members" style="padding: 20px;"><p>No members in this group yet.</p></div>';
        }
        
        html += '</div>';
    });
    
    content.innerHTML = html;
}

function closeGroupMembersModal() {
    const modal = document.getElementById('groupMembersModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// Close modal on background click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('groupMembersModal');
    if (e.target === modal) {
        closeGroupMembersModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('groupMembersModal');
        if (modal.classList.contains('active')) {
            closeGroupMembersModal();
        }
        const activityModal = document.getElementById('activityTypeModal');
        if (activityModal && activityModal.classList.contains('active')) {
            closeActivityTypeModal();
        }
    }
});

// Close activity type modal on background click
document.addEventListener('click', function(e) {
    const activityModal = document.getElementById('activityTypeModal');
    if (e.target === activityModal) {
        closeActivityTypeModal();
    }
});

let pendingDeleteState = null;

function promptDeleteAssignment(cmid, activityType, assignmentName, triggerBtn) {
    pendingDeleteState = {
        cmid,
        activityType,
        assignmentName,
        button: triggerBtn
    };
    
    const modal = document.getElementById('deleteConfirmModal');
    const nameEl = document.getElementById('deleteAssignmentName');
    
    if (nameEl) {
        nameEl.textContent = assignmentName;
    }
    
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeDeleteModal(resetButton = true) {
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    if (resetButton && pendingDeleteState && pendingDeleteState.button) {
        pendingDeleteState.button.disabled = false;
        pendingDeleteState.button.innerHTML = '<i class="fa fa-trash"></i>';
    }
    
    pendingDeleteState = null;
}

function confirmDeleteAssignment() {
    if (!pendingDeleteState) {
        return;
    }
    executeDeleteAssignment(pendingDeleteState);
}

function executeDeleteAssignment(state) {
    const { cmid, activityType, assignmentName, button } = state;
    const modalDeleteBtn = document.querySelector('.delete-modal-btn.delete');
    const modalCancelBtn = document.querySelector('.delete-modal-btn.cancel');
    
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    }
    if (modalDeleteBtn) modalDeleteBtn.disabled = true;
    if (modalCancelBtn) modalCancelBtn.disabled = true;
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/delete_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'cmid=' + cmid + '&activity_type=' + activityType + '&sesskey=<?php echo sesskey(); ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeDeleteModal(false);
            showToast('Success', 'File deleted successfully.', 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast('Error', data.message || 'Error deleting activity.', 'error');
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fa fa-trash"></i>';
            }
            if (modalDeleteBtn) modalDeleteBtn.disabled = false;
            if (modalCancelBtn) modalCancelBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error', 'Error deleting activity. Please try again.', 'error');
        if (button) {
            button.disabled = false;
            button.innerHTML = '<i class="fa fa-trash"></i>';
        }
        if (modalDeleteBtn) modalDeleteBtn.disabled = false;
        if (modalCancelBtn) modalCancelBtn.disabled = false;
    });
}

// Close delete modal when clicking outside content
document.addEventListener('click', function(e) {
    const modal = document.getElementById('deleteConfirmModal');
    if (modal && e.target === modal) {
        closeDeleteModal();
    }
});

function showToast(title, message, type = 'success') {
    const container = document.getElementById('globalToastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `global-toast ${type}`;
    toast.innerHTML = `
        <i class="fa ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
        <div>
            <strong>${title}</strong>
            <span>${message}</span>
        </div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(10px)';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 200);
    }, 3000);
}

// ===== TEACHER SUPPORT/HELP BUTTON FUNCTIONALITY =====
<?php if ($has_help_videos): ?>
document.addEventListener('DOMContentLoaded', function() {
    const helpButton = document.getElementById('teacherHelpButton');
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const closeModal = document.getElementById('closeTeacherHelpModal');
    
    // Open modal
    if (helpButton) {
        helpButton.addEventListener('click', function() {
            if (helpModal) {
                helpModal.classList.add('active');
                document.body.style.overflow = 'hidden';
                loadTeacherHelpVideos();
            }
        });
    }
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            closeTeacherHelpModal();
        });
    }
    
    // Close on outside click
    if (helpModal) {
        helpModal.addEventListener('click', function(e) {
            if (e.target === helpModal) {
                closeTeacherHelpModal();
            }
        });
    }
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && helpModal && helpModal.classList.contains('active')) {
            closeTeacherHelpModal();
        }
    });
    
    // Back to list button
    const backToListBtn = document.getElementById('teacherBackToListBtn');
    if (backToListBtn) {
        backToListBtn.addEventListener('click', function() {
            const videosListContainer = document.querySelector('.teacher-help-videos-list');
            const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
            const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
            
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.currentTime = 0;
                videoPlayer.src = '';
            }
            
            if (videoPlayerContainer) {
                videoPlayerContainer.style.display = 'none';
            }
            
            if (videosListContainer) {
                videosListContainer.style.display = 'block';
            }
        });
    }
});

function closeTeacherHelpModal() {
    const helpModal = document.getElementById('teacherHelpVideoModal');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (helpModal) {
        helpModal.classList.remove('active');
    }
    
    if (videoPlayer) {
        videoPlayer.pause();
        videoPlayer.currentTime = 0;
        videoPlayer.src = '';
    }
    
    document.body.style.overflow = 'auto';
}

// Load help videos function
function loadTeacherHelpVideos() {
    const videosListContainer = document.querySelector('.teacher-help-videos-list');
    const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
    
    if (!videosListContainer) return;
    
    // Show loading
    videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;"><i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>Loading help videos...</p>';
    
    // Fetch videos from plugin endpoint for 'teachers' category
    fetch(M.cfg.wwwroot + '/local/support/get_videos.php?category=teachers')
        .then(response => response.json())
        .then(data => {
            console.log('Teacher Support Videos Response:', data);
            
            if (data.success && data.videos && data.videos.length > 0) {
                let html = '';
                data.videos.forEach(function(video) {
                    html += '<div class="teacher-help-video-item" ';
                    html += 'data-video-id="' + video.id + '" ';
                    html += 'data-video-url="' + escapeHtml(video.video_url) + '" ';
                    html += 'data-embed-url="' + escapeHtml(video.embed_url) + '" ';
                    html += 'data-video-type="' + video.videotype + '" ';
                    html += 'data-has-captions="' + video.has_captions + '" ';
                    html += 'data-caption-url="' + escapeHtml(video.caption_url) + '">';
                    html += '  <h4><i class="fa fa-play-circle"></i> ' + escapeHtml(video.title) + '</h4>';
                    if (video.description) {
                        html += '  <p>' + escapeHtml(video.description) + '</p>';
                    }
                    if (video.duration) {
                        html += '  <small style="color: #999;"><i class="fa fa-clock-o"></i> ' + escapeHtml(video.duration) + ' &middot; <i class="fa fa-eye"></i> ' + video.views + ' views</small>';
                    }
                    html += '</div>';
                });
                videosListContainer.innerHTML = html;
                
                // Add click handlers to video items
                document.querySelectorAll('.teacher-help-video-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        const videoId = this.getAttribute('data-video-id');
                        const videoUrl = this.getAttribute('data-video-url');
                        const embedUrl = this.getAttribute('data-embed-url');
                        const videoType = this.getAttribute('data-video-type');
                        const hasCaptions = this.getAttribute('data-has-captions') === 'true';
                        const captionUrl = this.getAttribute('data-caption-url');
                        
                        playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl);
                    });
                });
            } else {
                videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">No help videos available for teachers.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading help videos:', error);
            videosListContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: #d9534f;">Error loading videos. Please try again.</p>';
        });
}

function playTeacherHelpVideo(videoId, videoUrl, embedUrl, videoType, hasCaptions, captionUrl) {
    const videosListContainer = document.querySelector('.teacher-help-videos-list');
    const videoPlayerContainer = document.querySelector('.teacher-help-video-player');
    const videoPlayer = document.getElementById('teacherHelpVideoPlayer');
    
    if (!videoPlayerContainer || !videoPlayer) return;
    
    // Clear previous video
    videoPlayer.innerHTML = '';
    videoPlayer.src = '';
    
    // Remove any existing iframe
    const existingIframe = document.getElementById('teacherTempIframe');
    if (existingIframe) {
        existingIframe.remove();
    }
    
    if (videoType === 'youtube' || videoType === 'vimeo' || videoType === 'external') {
        // For external videos, use iframe
        videoPlayer.style.display = 'none';
        const iframe = document.createElement('iframe');
        iframe.src = embedUrl || videoUrl;
        iframe.width = '100%';
        iframe.style.height = '450px';
        iframe.style.borderRadius = '8px';
        iframe.frameBorder = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.id = 'teacherTempIframe';
        videoPlayer.parentNode.insertBefore(iframe, videoPlayer);
    } else {
        // For uploaded videos, use HTML5 video player
        videoPlayer.style.display = 'block';
        videoPlayer.src = videoUrl;
        
        // Add captions if available
        if (hasCaptions && captionUrl) {
            const track = document.createElement('track');
            track.kind = 'captions';
            track.src = captionUrl;
            track.srclang = 'en';
            track.label = 'English';
            track.default = true;
            videoPlayer.appendChild(track);
        }
        
        videoPlayer.load();
    }
    
    // Show player, hide list
    videosListContainer.style.display = 'none';
    videoPlayerContainer.style.display = 'block';
    
    // Record view
    fetch(M.cfg.wwwroot + '/local/support/record_view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'videoid=' + videoId + '&sesskey=' + M.cfg.sesskey
    });
}
<?php endif; ?>
</script>

<!-- Teacher Help/Support Video Modal -->
<?php if ($has_help_videos): ?>
<div id="teacherHelpVideoModal" class="teacher-help-modal">
    <div class="teacher-help-modal-content">
        <div class="teacher-help-modal-header">
            <h2><i class="fa fa-video"></i> Teacher Help Videos</h2>
            <button class="teacher-help-modal-close" id="closeTeacherHelpModal">&times;</button>
        </div>
        
        <div class="teacher-help-modal-body">
            <div class="teacher-help-videos-list">
                <p style="text-align: center; padding: 20px; color: #666;">
                    <i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i><br>
                    Loading help videos...
                </p>
            </div>
            
            <div class="teacher-help-video-player" style="display: none;">
                <button class="teacher-back-to-list-btn" id="teacherBackToListBtn">
                    <i class="fa fa-arrow-left"></i> Back to List
                </button>
                <video id="teacherHelpVideoPlayer" controls style="width: 100%; border-radius: 8px;">
                    <source src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
echo $OUTPUT->footer();
?>