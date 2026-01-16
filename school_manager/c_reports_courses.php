<?php
/**
 * C Reports - Courses Report Tab (AJAX fragment)
 * Based on course_reports.php logic - exact same queries and structure
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

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

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'courses']);
    redirect($target);
}

if (!$company_info) {
    echo '<div class="tab-error-message"><h4>Error</h4><p>Company information not found. Please contact your administrator.</p></div>';
    exit;
}

// Handle pagination
$per_page = optional_param('per_page', 10, PARAM_INT);
$per_page = max(5, min(50, $per_page));
$current_page = optional_param('page', 1, PARAM_INT);
$current_page = max(1, $current_page);
$offset = ($current_page - 1) * $per_page;

$course_stats = [];
$total_courses = 0;

// Get all unique cohorts/grades for filter dropdown
$all_cohorts = [];
if ($company_info) {
    try {
        $cohorts_from_students = $DB->get_records_sql(
            "SELECT DISTINCT coh.id, coh.name
             FROM {cohort} coh
             INNER JOIN {cohort_members} cm ON cm.cohortid = coh.id
             INNER JOIN {company_users} cu ON cu.userid = cm.userid
             WHERE coh.visible = 1 AND cu.companyid = ?",
            [$company_info->id]
        );
        
        foreach ($cohorts_from_students as $cohort) {
            $all_cohorts[$cohort->id] = $cohort;
        }
    } catch (Exception $e) {
        error_log("Error fetching cohorts: " . $e->getMessage());
    }
}

if ($company_info) {
    try {
        // Get total count first
        $total_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
             WHERE c.visible = 1 
             AND c.id > 1 
             AND comp_c.companyid = ?",
            [$company_info->id]
        );
        
        // Get paginated courses - using same query as course_reports.php
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname 
             FROM {course} c
             INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
             WHERE c.visible = 1 
             AND c.id > 1 
             AND comp_c.companyid = ?
             ORDER BY c.fullname ASC
             LIMIT " . (int)$per_page . " OFFSET " . (int)$offset,
            [$company_info->id]
        );
        
        if (!$courses) {
            $courses = [];
        }
        
        foreach ($courses as $course) {
            // Get total enrolled students (students only, no teachers/managers) - EXACT SAME QUERY
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
            
            // Get completed count (students only) - EXACT SAME QUERY
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
            
            // Get users who have accessed the course (in progress - students only) - EXACT SAME QUERY
            $in_progress = $DB->count_records_sql(
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
                 AND u.id NOT IN (
                     SELECT cc.userid 
                     FROM {course_completions} cc 
                     WHERE cc.course = ? AND cc.timecompleted IS NOT NULL
                 )",
                [$course->id, $company_info->id, $course->id]
            );
            
            // Calculate not started
            $not_started = $total_enrolled - ($completed + $in_progress);
            if ($not_started < 0) $not_started = 0;
            
            // Get active students for this course (last 30 days) - EXACT SAME QUERY
            $active_students_count = $DB->count_records_sql(
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
                 AND ula.timeaccess > ?",
                [$course->id, $company_info->id, strtotime('-30 days')]
            );
            
            // Calculate activity rate
            $activity_rate = $total_enrolled > 0 ? round(($active_students_count / $total_enrolled) * 100, 1) : 0;
            
            // Calculate completion rate based on actual student progress - EXACT SAME LOGIC
            $enrolled_students_data = $DB->get_records_sql(
                "SELECT DISTINCT u.id, cc.timecompleted, cc.timestarted
                 FROM {user} u
                 INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                 INNER JOIN {role} r ON r.id = ra.roleid
                 LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
                 WHERE e.courseid = ? 
                 AND ue.status = 0
                 AND cu.companyid = ?
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 AND u.suspended = 0",
                [$course->id, $company_info->id]
            );
            
            // Get total number of course modules/activities with completion tracking enabled
            $total_modules = $DB->count_records_sql(
                "SELECT COUNT(id)
                 FROM {course_modules}
                 WHERE course = ? AND visible = 1 AND deletioninprogress = 0 AND completion > 0",
                [$course->id]
            );
            
            $total_progress = 0;
            $students_count = count($enrolled_students_data);
            
            foreach ($enrolled_students_data as $student_data) {
                $student_progress = 0;
                
                // Check if student has fully completed the course
                if ($student_data->timecompleted) {
                    $student_progress = 100;
                } else {
                    // Calculate progress based on completed modules/activities
                    if ($total_modules > 0) {
                        $completed_modules = $DB->count_records_sql(
                            "SELECT COUNT(DISTINCT cmc.coursemoduleid)
                             FROM {course_modules_completion} cmc
                             INNER JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                             WHERE cmc.userid = ? 
                             AND cm.course = ? 
                             AND cm.visible = 1 
                             AND cm.deletioninprogress = 0
                             AND cm.completion > 0
                             AND (cmc.completionstate = 1 OR cmc.completionstate = 2 OR cmc.completionstate = 3)",
                            [$student_data->id, $course->id]
                        );
                        
                        $student_progress = round(($completed_modules / $total_modules) * 100, 1);
                    } else {
                        // If no modules with completion tracking, check if student has started
                        if ($student_data->timestarted) {
                            $student_progress = 5;
                        }
                    }
                }
                
                $total_progress += $student_progress;
            }
            
            // Calculate average completion rate across all enrolled students
            $completion_rate = $students_count > 0 ? round($total_progress / $students_count, 1) : 0;
            
            // Get assigned cohorts/grades for this course - EXACT SAME QUERY
            $course_cohorts = $DB->get_records_sql(
                "SELECT DISTINCT coh.id, coh.name
                 FROM {cohort} coh
                 INNER JOIN {enrol} e ON e.customint1 = coh.id AND e.enrol = 'cohort'
                 WHERE e.courseid = ? AND coh.visible = 1",
                [$course->id]
            );
            
            $cohort_names = [];
            foreach ($course_cohorts as $cohort) {
                $cohort_names[] = $cohort->name;
            }
            
            // Sort cohort names naturally
            natsort($cohort_names);
            $cohort_names = array_values($cohort_names);
            
            $course_stats[] = [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'completed' => $completed,
                'in_progress' => $in_progress,
                'not_started' => $not_started,
                'total_enrolled' => $total_enrolled,
                'active_students' => $active_students_count,
                'activity_rate' => $activity_rate,
                'completion_rate' => $completion_rate,
                'cohorts' => $cohort_names,
                'cohorts_text' => implode(', ', $cohort_names)
            ];
        }
    } catch (Exception $e) {
        error_log("Error in c_reports_courses.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo '<div class="tab-error-message"><h4>Error loading courses</h4><p>' . htmlspecialchars($e->getMessage()) . '</p><p style="font-size: 0.85rem; color: #6b7280;">Please check the server error logs for more details.</p></div>';
        exit;
    }
}

$total_pages = $total_courses > 0 ? max(1, ceil($total_courses / $per_page)) : 0;
$start_item = $total_courses > 0 ? $offset + 1 : 0;
$end_item = $total_courses > 0 ? min($offset + $per_page, $total_courses) : 0;

// Helper function to build pagination URL
$buildPaginationUrl = function($page_num) use ($CFG, $per_page) {
    $params = $_GET;
    $params['page'] = $page_num;
    $params['per_page'] = $per_page;
    unset($params['ajax']);
    return $CFG->wwwroot . "/theme/remui_kids/school_manager/c_reports.php?tab=courses&" . http_build_query($params);
};

?>
<style>
/* Course Summary Cards Styles - EXACT SAME AS course_reports.php */
.course-summary-card {
    background: white;
    border-radius: 12px;
    padding: 25px 30px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    display: flex;
    gap: 35px;
    align-items: center;
    min-height: 180px;
    transition: all 0.3s ease;
    overflow: visible;
    position: relative;
}

.course-summary-card.hidden {
    display: none;
}

.course-summary-card:hover {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.course-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.course-name {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 15px 0;
    line-height: 1.3;
}

.course-cohorts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin-bottom: 10px;
}

.cohort-badge {
    background: #e0e7ff;
    color: #4f46e5;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.activity-rate-display {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.activity-progress-bar {
    width: 100px;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.activity-progress-fill {
    height: 100%;
    background: #3b82f6;
    border-radius: 4px;
    transition: width 0.3s ease;
}

.activity-percentage {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
    white-space: nowrap;
}

.course-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.course-btn {
    padding: 8px 16px;
    border: 2px solid #667eea;
    background: white;
    color: #667eea;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    white-space: nowrap;
}

.course-btn:hover {
    background: #667eea;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.25);
}

.course-chart-section {
    flex: 0 0 280px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.chart-display-wrapper {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
}

.chart-legend {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #4b5563;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.legend-color.completed {
    background: #10b981;
}

.legend-color.in-progress {
    background: #3b82f6;
}

.legend-color.not-started {
    background: #ef4444;
}

.chart-container {
    width: 150px;
    height: 150px;
    position: relative;
}

.show-data-link {
    color: #3b82f6;
    font-size: 0.85rem;
    text-decoration: none;
    font-weight: 500;
    cursor: pointer;
    margin-top: 10px;
}

.show-data-link:hover {
    text-decoration: underline;
}

.chart-data-table {
    width: 100%;
    font-size: 0.85rem;
    margin-top: 15px;
    background: #f9fafb;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

/* Search and Filter Section */
.course-search-section {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.search-filters {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-group {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 0.9rem;
}

.search-input {
    width: 100%;
    padding: 10px 15px 10px 40px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-group {
    display: flex;
    align-items: center;
}

.filter-select {
    padding: 10px 15px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.9rem;
    background: white;
    cursor: pointer;
    min-width: 150px;
}

.clear-filters-btn {
    padding: 10px 18px;
    background: #f3f4f6;
    color: #6b7280;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.clear-filters-btn:hover {
    background: #e5e7eb;
    color: #374151;
}

.filter-results-info {
    margin-top: 15px;
    padding: 12px;
    background: #f0f9ff;
    border-left: 4px solid #3b82f6;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #1e40af;
}

.courses-section {
    margin-top: 20px;
}

.section-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 20px;
}

.no-courses-message {
    text-align: center;
    padding: 60px 30px;
    color: #6b7280;
}

/* Pagination */
.pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 15px;
    margin-top: 25px;
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.pagination-info {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
    text-align: center;
    order: 2;
}

.pagination-info span {
    color: #1f2937;
    font-weight: 600;
}

.pagination-controls {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 8px !important;
    order: 1;
}

.pagination-btn,
.pagination-number {
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
    text-decoration: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #e5e7eb;
    background: #f3f4f6;
    color: #374151;
    cursor: pointer;
    font-family: inherit;
}

.pagination-btn:hover,
.pagination-number:hover {
    background: #e5e7eb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-number.active {
    background: #3b82f6 !important;
    color: white !important;
    border-color: #3b82f6 !important;
}

.pagination-per-page {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}

.pagination-per-page select {
    padding: 6px 10px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.875rem;
    cursor: pointer;
}
</style>

<!-- Search and Filter Section -->
<div class="course-search-section">
    <div class="search-filters">
        <div class="search-input-group">
            <i class="fa fa-search search-icon"></i>
            <input type="text" 
                   id="courseSearchInput" 
                   class="search-input" 
                   placeholder="Search courses by name..." 
                   onkeyup="filterCourses()">
        </div>
        <div class="filter-group">
            <select id="gradeFilter" class="filter-select" onchange="filterCourses()">
                <option value="">All Grades</option>
                <?php foreach ($all_cohorts as $cohort): ?>
                    <option value="<?php echo htmlspecialchars($cohort->name); ?>">
                        <?php echo htmlspecialchars($cohort->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button onclick="clearFilters()" class="clear-filters-btn" title="Clear all filters">
            <i class="fa fa-times"></i> Clear
        </button>
    </div>
    <div id="filterResultsInfo" class="filter-results-info" style="display: none;">
        <i class="fa fa-info-circle"></i>
        <span id="filterResultsText"></span>
    </div>
</div>

<div class="courses-section">
    <h2 class="section-title" style="text-transform: uppercase; letter-spacing: 0.5px;">Course Summary Reports</h2>
    
    <?php if (empty($course_stats)): ?>
        <div class="no-courses-message">
            <i class="fa fa-book" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
            <p>No courses assigned to this school yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($course_stats as $course): ?>
            <div class="course-summary-card" 
                 data-course-name="<?php echo strtolower(htmlspecialchars($course['fullname'])); ?>"
                 data-course-cohorts="<?php echo strtolower(htmlspecialchars($course['cohorts_text'])); ?>">
                <!-- Left side: Course Info -->
                <div class="course-info">
                    <h3 class="course-name" style="margin-bottom: 10px;">
                        <?php echo htmlspecialchars($course['fullname']); ?>
                    </h3>
                    <?php if (!empty($course['cohorts'])): ?>
                        <div class="course-cohorts" style="margin-bottom: 10px;">
                            <i class="fa fa-users" style="color: #6b7280; font-size: 0.85rem;"></i>
                            <?php foreach ($course['cohorts'] as $cohort): ?>
                                <span class="cohort-badge"><?php echo htmlspecialchars($cohort); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Completion Rate Progress Bar -->
                    <div class="activity-rate-display" style="margin-bottom: 15px;">
                        <div class="activity-progress-bar">
                            <div class="activity-progress-fill" style="width: <?php echo max(0, min(100, $course['completion_rate'])); ?>%; min-width: <?php echo $course['completion_rate'] > 0 ? '2px' : '0px'; ?>;"></div>
                        </div>
                        <span class="activity-percentage"><?php 
                            $rate = $course['completion_rate'];
                            echo ($rate == 0 || $rate == (int)$rate) ? (int)$rate : number_format($rate, 1);
                        ?>%</span>
                    </div>
                    
                    <div class="course-buttons">
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/course_summary_detail.php?courseid=<?php echo $course['id']; ?>" 
                           class="course-btn">
                            Course summary
                        </a>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/course_completion_monthly.php?courseid=<?php echo $course['id']; ?>" 
                           class="course-btn">
                            Completion report by month
                        </a>
                    </div>
                </div>
                
                <!-- Right side: Chart -->
                <div class="course-chart-section">
                    <div class="chart-display-wrapper">
                        <div class="chart-legend">
                            <div class="legend-item">
                                <div class="legend-color completed"></div>
                                <span>Completed (<?php echo $course['completed']; ?>)</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color in-progress"></div>
                                <span>Still in progress (<?php echo $course['in_progress']; ?>)</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color not-started"></div>
                                <span>Not started (<?php echo $course['not_started']; ?>)</span>
                            </div>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="chart-<?php echo $course['id']; ?>"></canvas>
                        </div>
                        
                        <?php
                        $total = $course['total_enrolled'];
                        $completed_pct = $total > 0 ? round(($course['completed'] / $total) * 100, 1) : 0;
                        $progress_pct = $total > 0 ? round(($course['in_progress'] / $total) * 100, 1) : 0;
                        $notstarted_pct = $total > 0 ? round(($course['not_started'] / $total) * 100, 1) : 0;
                        ?>
                        <div id="chart-data-<?php echo $course['id']; ?>" class="chart-data-table" style="display: block; margin-top: 15px;">
                            <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; color: #374151;"><strong>Total Enrolled:</strong></td>
                                    <td style="padding: 8px 10px; text-align: right; border-bottom: 1px solid #e5e7eb; color: #1f2937; font-weight: 600;"><?php echo number_format($course['total_enrolled']); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; color: #374151;"><strong>Completed:</strong></td>
                                    <td style="padding: 8px 10px; text-align: right; border-bottom: 1px solid #e5e7eb; color: #1f2937; font-weight: 600;"><?php echo number_format($course['completed']); ?> (<?php echo $completed_pct; ?>%)</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; color: #374151;"><strong>In Progress:</strong></td>
                                    <td style="padding: 8px 10px; text-align: right; border-bottom: 1px solid #e5e7eb; color: #1f2937; font-weight: 600;"><?php echo number_format($course['in_progress']); ?> (<?php echo $progress_pct; ?>%)</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 10px; color: #374151;"><strong>Not Started:</strong></td>
                                    <td style="padding: 8px 10px; text-align: right; color: #1f2937; font-weight: 600;"><?php echo number_format($course['not_started']); ?> (<?php echo $notstarted_pct; ?>%)</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <a href="#" class="show-data-link" onclick="toggleChartData(<?php echo $course['id']; ?>); return false;" style="margin-top: 10px;">
                        Hide chart data
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-controls">
                    <?php if ($current_page > 1): ?>
                        <a href="<?php echo $buildPaginationUrl($current_page - 1); ?>" class="pagination-btn">
                            <i class="fa fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn" style="opacity: 0.5; cursor: not-allowed;">
                            <i class="fa fa-chevron-left"></i> Previous
                        </span>
                    <?php endif; ?>
                    
                    <div class="pagination-numbers" style="display: flex; gap: 5px;">
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="<?php echo $buildPaginationUrl(1); ?>" class="pagination-number">1</a>
                            <?php if ($start_page > 2): ?>
                                <span style="color: #6b7280; padding: 8px 4px;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $current_page): ?>
                                <span class="pagination-number active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo $buildPaginationUrl($i); ?>" class="pagination-number"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span style="color: #6b7280; padding: 8px 4px;">...</span>
                            <?php endif; ?>
                            <a href="<?php echo $buildPaginationUrl($total_pages); ?>" class="pagination-number"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="<?php echo $buildPaginationUrl($current_page + 1); ?>" class="pagination-btn">
                            Next <i class="fa fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn" style="opacity: 0.5; cursor: not-allowed;">
                            Next <i class="fa fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="pagination-info">
                    Showing <span><?php echo $start_item; ?></span>-<span><?php echo $end_item; ?></span> of <span><?php echo $total_courses; ?></span> courses
                    <?php if ($total_pages > 1): ?>
                        - Page <span><?php echo $current_page; ?></span> of <span><?php echo $total_pages; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="pagination-per-page">
                    <label style="font-size: 0.875rem; color: #6b7280;">Show per page:</label>
                    <select id="courses-per-page-select" onchange="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/c_reports.php?tab=courses&per_page=' + this.value + '&page=1'">
                        <option value="5" <?php echo $per_page == 5 ? 'selected' : ''; ?>>5</option>
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                    </select>
                </div>
            </div>
        <?php else: ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <span><?php echo $start_item; ?></span>-<span><?php echo $end_item; ?></span> of <span><?php echo $total_courses; ?></span> courses
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Toggle chart data display
function toggleChartData(courseId) {
    const dataTable = document.getElementById('chart-data-' + courseId);
    const link = event.target;
    
    if (dataTable && dataTable.style.display === 'none') {
        dataTable.style.display = 'block';
        link.textContent = 'Hide chart data';
    } else if (dataTable) {
        dataTable.style.display = 'none';
        link.textContent = 'Show chart data';
    }
}

// Filter courses function
function filterCourses() {
    const searchInput = document.getElementById('courseSearchInput');
    const gradeFilter = document.getElementById('gradeFilter');
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
    const selectedGrade = gradeFilter ? gradeFilter.value.toLowerCase() : '';
    const cards = document.querySelectorAll('.course-summary-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
        const courseName = card.getAttribute('data-course-name') || '';
        const courseCohorts = card.getAttribute('data-course-cohorts') || '';
        
        const matchesSearch = !searchTerm || courseName.includes(searchTerm);
        const matchesGrade = !selectedGrade || courseCohorts.includes(selectedGrade);
        
        if (matchesSearch && matchesGrade) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });
    
    // Update filter results info
    const filterInfo = document.getElementById('filterResultsInfo');
    const filterText = document.getElementById('filterResultsText');
    if (filterInfo && filterText) {
        if (searchTerm || selectedGrade) {
            filterInfo.style.display = 'flex';
            filterText.textContent = `Showing ${visibleCount} of ${cards.length} courses`;
        } else {
            filterInfo.style.display = 'none';
        }
    }
}

// Clear filters function
function clearFilters() {
    const searchInput = document.getElementById('courseSearchInput');
    const gradeFilter = document.getElementById('gradeFilter');
    if (searchInput) searchInput.value = '';
    if (gradeFilter) gradeFilter.value = '';
    filterCourses();
}

// Initialize charts for all courses
<?php if (!empty($course_stats)): ?>
    // Wait for Chart.js to be available
    function initCourseCharts() {
        if (typeof Chart === 'undefined') {
            setTimeout(initCourseCharts, 100);
            return;
        }
        
        <?php foreach ($course_stats as $course): ?>
        (function() {
            const ctx = document.getElementById('chart-<?php echo $course['id']; ?>');
            if (!ctx) return;
            
            const completed = <?php echo $course['completed']; ?>;
            const inProgress = <?php echo $course['in_progress']; ?>;
            const notStarted = <?php echo $course['not_started']; ?>;
            const total = completed + inProgress + notStarted;
            
            // Only create chart if there's data
            if (total > 0) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Completed', 'Still in progress', 'Not started'],
                        datasets: [{
                            data: [completed, inProgress, notStarted],
                            backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                            borderWidth: 0,
                            cutout: '70%'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                enabled: true,
                                position: 'average',
                                backgroundColor: 'rgba(0, 0, 0, 0.85)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                titleFont: {
                                    size: 13,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 12,
                                    weight: 'normal'
                                },
                                padding: 12,
                                cornerRadius: 8,
                                displayColors: true,
                                boxWidth: 12,
                                boxHeight: 12,
                                boxPadding: 6,
                                usePointStyle: true,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                // Display "No Data" message in canvas
                const canvas = ctx;
                const context = canvas.getContext('2d');
                
                canvas.width = 150;
                canvas.height = 150;
                
                context.clearRect(0, 0, canvas.width, canvas.height);
                
                context.beginPath();
                context.arc(75, 75, 72, 0, 2 * Math.PI);
                context.fillStyle = '#f9fafb';
                context.fill();
                context.strokeStyle = '#e5e7eb';
                context.lineWidth = 3;
                context.stroke();
                
                context.beginPath();
                context.arc(75, 65, 32, 0, 2 * Math.PI);
                context.fillStyle = '#ffffff';
                context.fill();
                
                context.fillStyle = '#9ca3af';
                context.font = 'bold 10px Arial';
                context.textAlign = 'center';
                context.fillText('No Data', 75, 70);
            }
        })();
        <?php endforeach; ?>
    }
    
    // Call initialization when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCourseCharts);
    } else {
        // DOM is already ready, but wait a bit for AJAX content to be inserted
        setTimeout(initCourseCharts, 100);
    }
    
    // Global function to reinitialize charts (for tab switching)
    window.initCourseCharts = initCourseCharts;
<?php endif; ?>
</script>
