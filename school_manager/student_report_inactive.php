<?php
/**
 * Student Report - Inactive Student Tab (AJAX fragment)
 * Based on inactive student report from course_reports.php
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
    
    // Try alternative query if first one fails
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.*
             FROM {company} c
             JOIN {company_users} cu ON c.id = cu.companyid
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'inactive']);
    redirect($target);
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

// Get search and filter parameters
$search_query = optional_param('search', '', PARAM_TEXT);
$cohort_filter = optional_param('cohort', 0, PARAM_INT);

// Get Inactive Students Report (Students with low or no activity)
$inactive_days_threshold = 7; // Default: 7 days
$inactive_students_data = [];
$alert_students_data = []; // Students with critical inactivity (14+ days)

if ($company_info) {
    // Build search condition
    $search_condition = '';
    $search_params = [];
    if ($search_query !== '') {
        $search_condition = " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR uifd.data LIKE ?)";
        $search_term = '%' . $search_query . '%';
        $search_params = [$search_term, $search_term, $search_term, $search_term];
    }
    
    // Build cohort condition
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
    
    // Get all students with their last access time
    $students_activity = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                uifd.data as grade_level
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
         ORDER BY u.lastaccess ASC",
        array_merge([$company_info->id], $search_params, $cohort_params)
    );
    
    $log_table_exists = $DB->get_manager()->table_exists('logstore_standard_log');
    
    foreach ($students_activity as $student) {
        $current_time = time();
        $days_inactive = $student->lastaccess > 0 ? 
            floor(($current_time - $student->lastaccess) / 86400) : 999;
        
        // Get total enrolled courses
        $enrolled_courses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             INNER JOIN {enrol} e ON e.courseid = c.id
             INNER JOIN {user_enrolments} ue ON ue.enrolid = e.id
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE ue.userid = ?
             AND cc.companyid = ?
             AND ue.status = 0
             AND c.id > 1",
            [$student->id, $company_info->id]
        );
        
        // Get login count in last 30 days
        $recent_logins = 0;
        if ($log_table_exists) {
            $recent_logins = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
                 FROM {logstore_standard_log}
                 WHERE userid = ?
                 AND action = 'loggedin'
                 AND timecreated > ?",
                [$student->id, strtotime('-30 days')]
            );
        }
        
        // Get quiz attempts in last 30 days
        $recent_quiz_attempts = $DB->count_records_sql(
            "SELECT COUNT(*)
             FROM {quiz_attempts} qa
             INNER JOIN {quiz} q ON q.id = qa.quiz
             INNER JOIN {course} c ON c.id = q.course
             INNER JOIN {company_course} cc ON cc.courseid = c.id
             WHERE qa.userid = ?
             AND cc.companyid = ?
             AND qa.timestart > ?",
            [$student->id, $company_info->id, strtotime('-30 days')]
        );
        
        // Determine activity level
        $activity_level = 'Active';
        $alert_level = 'success';
        
        if ($days_inactive >= 14) {
            $activity_level = 'CRITICAL';
            $alert_level = 'danger';
            $alert_students_data[] = [
                'id' => $student->id,
                'name' => fullname($student),
                'email' => $student->email,
                'grade_level' => $student->grade_level ?? 'N/A',
                'days_inactive' => $days_inactive,
                'last_access' => $student->lastaccess > 0 ? userdate($student->lastaccess, get_string('strftimedatefullshort')) : 'Never',
                'enrolled_courses' => $enrolled_courses,
                'recent_logins' => $recent_logins,
                'quiz_attempts' => $recent_quiz_attempts,
                'activity_level' => $activity_level
            ];
        } elseif ($days_inactive >= 7) {
            $activity_level = 'WARNING';
            $alert_level = 'warning';
        } elseif ($days_inactive >= 3) {
            $activity_level = 'LOW ACTIVITY';
            $alert_level = 'info';
        }
        
        // Add to inactive students if inactive for 3+ days or low engagement
        if ($days_inactive >= 3 || $recent_logins < 3) {
            $inactive_students_data[] = [
                'id' => $student->id,
                'name' => fullname($student),
                'email' => $student->email,
                'grade_level' => $student->grade_level ?? 'Grade Level',
                'days_inactive' => $days_inactive,
                'last_access' => $student->lastaccess > 0 ? userdate($student->lastaccess, get_string('strftimedatefullshort')) : 'Never',
                'enrolled_courses' => $enrolled_courses,
                'recent_logins' => $recent_logins,
                'quiz_attempts' => $recent_quiz_attempts,
                'activity_level' => $activity_level,
                'alert_level' => $alert_level
            ];
        }
    }
}

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.inactive-students-container {
    padding: 0;
}

.inactive-students-header {
    margin-bottom: 30px;
}

.inactive-students-header h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.inactive-students-header h3 i {
    color: #ef4444;
}

.inactive-students-header p {
    color: #6b7280;
    margin: 0;
    font-size: 0.95rem;
}

.inactive-chart-card {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.inactive-chart-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 25px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.inactive-chart-title i {
    color: #3b82f6;
}

.inactive-chart-wrapper {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 40px;
    align-items: center;
}

.inactive-chart-canvas {
    position: relative;
    height: 350px;
}

.inactive-stats-cards {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.inactive-stat-card {
    padding: 20px;
    border-radius: 10px;
    border-left: 5px solid #6b7280;
}

.inactive-stat-card.total {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
}

.inactive-stat-card.critical {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border-left-color: #ef4444;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.inactive-stat-card.warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-left-color: #f59e0b;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.inactive-stat-card.low {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    border-left-color: #3b82f6;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.inactive-stat-label {
    font-size: 0.85rem;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.inactive-stat-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1f2937;
}

.inactive-stat-subtitle {
    font-size: 0.8rem;
    color: #6b7280;
    margin-top: 5px;
}

.inactive-stat-label-small {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.inactive-stat-value-small {
    font-size: 2rem;
    font-weight: 800;
}

.inactive-chart-info {
    margin-top: 25px;
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    border-left: 4px solid #3b82f6;
}

.inactive-chart-info p {
    font-size: 0.85rem;
    color: #6b7280;
    margin: 0;
}

.inactive-chart-info i {
    color: #3b82f6;
}

.inactive-table-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.inactive-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.inactive-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.inactive-table-title i {
    color: #6b7280;
}

.inactive-filter-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.inactive-filter-label {
    font-size: 0.85rem;
    color: #6b7280;
}

.inactive-filter-select {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #374151;
    cursor: pointer;
    background: white;
}

.inactive-table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.inactive-table {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.inactive-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.inactive-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.inactive-table th.center {
    text-align: center;
}

.inactive-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
}

.inactive-table tbody tr:hover {
    background: #f9fafb;
}

.inactive-table td {
    padding: 12px;
}

.inactive-table td.center {
    text-align: center;
}

.inactive-student-name {
    font-weight: 600;
    color: #1f2937;
}

.inactive-student-email {
    font-size: 0.75rem;
    color: #6b7280;
}

.inactive-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
}

.inactive-badge.critical {
    background: #fee2e2;
    color: #991b1b;
}

.inactive-badge.warning {
    background: #fef3c7;
    color: #92400e;
}

.inactive-badge.low {
    background: #dbeafe;
    color: #1e40af;
}

.inactive-status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.inactive-status-badge.critical {
    background: #ef4444;
    color: white;
}

.inactive-status-badge.warning {
    background: #f59e0b;
    color: white;
}

.inactive-status-badge.low {
    background: #3b82f6;
    color: white;
}

.inactive-activity-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 600;
}

.inactive-table-footer {
    margin-top: 20px;
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    border-left: 4px solid #ef4444;
}

.inactive-table-footer p {
    font-size: 0.85rem;
    color: #6b7280;
    margin: 0;
}

.inactive-empty-state {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    border: 2px solid #10b981;
    border-radius: 12px;
    padding: 40px;
    text-align: center;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
}

.inactive-empty-icon {
    width: 80px;
    height: 80px;
    background: #10b981;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

.inactive-empty-icon i {
    font-size: 2.5rem;
    color: white;
}

.inactive-empty-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #065f46;
    margin: 0 0 10px 0;
}

.inactive-empty-text {
    font-size: 1rem;
    color: #047857;
    margin: 0;
}

.inactive-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.inactive-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9 !important;
    border-color: #9ca3af !important;
}

.inactive-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.inactive-page-number:hover {
    background: #f1f5f9 !important;
    border-color: #9ca3af !important;
}

.inactive-page-number.active {
    background: #3b82f6 !important;
    color: white !important;
    border-color: #3b82f6 !important;
}

@media (max-width: 768px) {
    .inactive-chart-wrapper {
        grid-template-columns: 1fr;
        gap: 25px;
    }
    
    .inactive-chart-canvas {
        height: 250px;
    }
}
</style>

<div class="inactive-students-container">
    <div class="inactive-students-header">
        <h3><i class="fa fa-exclamation-triangle"></i> Inactive Students Report</h3>
        <p>List of students with low or no activity in the last 30 days for <?php echo htmlspecialchars($company_info->name ?? 'your school'); ?>.</p>
    </div>
    
    <?php if (!empty($inactive_students_data)): ?>
        <?php 
        $critical_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'danger'; }));
        $warning_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'warning'; }));
        $low_activity_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'info'; }));
        $total_count = count($inactive_students_data);
        ?>
        
        <!-- Inactive Students Distribution Chart -->
        <div class="inactive-chart-card">
            <h4 class="inactive-chart-title">
                <i class="fa fa-chart-bar"></i> Inactive Students Distribution
            </h4>
            
            <div class="inactive-chart-wrapper">
                <!-- Left: Bar Chart -->
                <div class="inactive-chart-canvas" style="position: relative; height: 350px; width: 100%;">
                    <canvas id="inactiveStudentsChart" style="display: block; width: 100%; height: 100%;"></canvas>
                </div>
                
                <!-- Right: Statistics Summary -->
                <div class="inactive-stats-cards">
                    <!-- Total Inactive -->
                    <div class="inactive-stat-card total">
                        <div class="inactive-stat-label">Total Inactive</div>
                        <div class="inactive-stat-value"><?php echo $total_count; ?></div>
                        <div class="inactive-stat-subtitle">Students</div>
                    </div>
                    
                    <!-- Critical -->
                    <div class="inactive-stat-card critical">
                        <div>
                            <div class="inactive-stat-label-small" style="color: #7f1d1d;">Critical</div>
                            <div style="font-size: 0.7rem; color: #991b1b; margin-top: 2px;">14+ days</div>
                        </div>
                        <div class="inactive-stat-value-small" style="color: #991b1b;"><?php echo $critical_count; ?></div>
                    </div>
                    
                    <!-- Warning -->
                    <div class="inactive-stat-card warning">
                        <div>
                            <div class="inactive-stat-label-small" style="color: #78350f;">Warning</div>
                            <div style="font-size: 0.7rem; color: #92400e; margin-top: 2px;">7-13 days</div>
                        </div>
                        <div class="inactive-stat-value-small" style="color: #92400e;"><?php echo $warning_count; ?></div>
                    </div>
                    
                    <!-- Low Activity -->
                    <div class="inactive-stat-card low">
                        <div>
                            <div class="inactive-stat-label-small" style="color: #1e3a8a;">Low Activity</div>
                            <div style="font-size: 0.7rem; color: #1e40af; margin-top: 2px;">3-6 days</div>
                        </div>
                        <div class="inactive-stat-value-small" style="color: #1e40af;"><?php echo $low_activity_count; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Chart Info -->
            <div class="inactive-chart-info">
                <p>
                    <i class="fa fa-info-circle"></i> 
                    <strong>Chart Overview:</strong> This visualization shows the distribution of inactive students by severity level. The bar chart provides a clear comparison of student inactivity across different time periods, helping identify where intervention is most needed.
                </p>
            </div>
        </div>
        
        <!-- Inactive Students Table -->
        <div class="inactive-table-card">
            <div class="inactive-table-header">
                <h4 class="inactive-table-title">
                    <i class="fa fa-table"></i> Inactive Students Details
                </h4>
                <div class="inactive-filter-container" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div class="inactive-search-container" style="position: relative; flex: 1; min-width: 250px; max-width: 400px;">
                        <i class="fa fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6b7280; pointer-events: none;"></i>
                        <input type="text" id="inactiveStudentSearch" class="inactive-search-input" placeholder="Search students by name or email..." autocomplete="off" value="<?php echo htmlspecialchars($search_query); ?>" style="width: 100%; padding: 8px 12px 8px 40px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #1f2937; background: white;">
                        <button type="button" id="inactiveStudentClear" class="inactive-clear-btn" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); padding: 4px 8px; background: #ef4444; color: white; border: none; border-radius: 6px; font-size: 0.8rem; cursor: pointer; display: none;">
                            <i class="fa fa-times"></i> Clear
                        </button>
                    </div>
                    <div class="inactive-filter-container" style="display: flex; gap: 10px; align-items: center;">
                        <span class="inactive-filter-label">Cohort:</span>
                        <select id="inactiveCohortFilter" class="inactive-filter-select" style="min-width: 180px;">
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
                </div>
            </div>
            
            <div class="inactive-table-wrapper">
                <table class="inactive-table">
                    <thead>
                        <tr>
                            <th style="min-width: 200px;">Student Name</th>
                            <th class="center" style="min-width: 100px;">Grade Level</th>
                            <th class="center" style="min-width: 120px;">Days Inactive</th>
                            <th class="center" style="min-width: 150px;">Last Access</th>
                            <th class="center" style="min-width: 120px;">Logins (30d)</th>
                            <th class="center" style="min-width: 120px;">Quiz Attempts</th>
                            <th class="center" style="min-width: 110px;">Courses</th>
                            <th class="center" style="min-width: 130px;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="inactiveStudentsTableBody">
                        <?php foreach ($inactive_students_data as $student): ?>
                            <tr class="inactive-student-row" 
                                data-days-inactive="<?php echo $student['days_inactive']; ?>"
                                data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                                data-email="<?php echo strtolower(htmlspecialchars($student['email'])); ?>"
                                data-grade="<?php echo strtolower(htmlspecialchars($student['grade_level'])); ?>">
                                <td>
                                    <div class="inactive-student-name">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </div>
                                    <div class="inactive-student-email">
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    </div>
                                </td>
                                <td class="center" style="color: #4b5563; font-weight: 500;">
                                    <?php echo htmlspecialchars($student['grade_level']); ?>
                                </td>
                                <td class="center">
                                    <span class="inactive-badge <?php 
                                        if ($student['days_inactive'] >= 14) {
                                            echo 'critical';
                                        } elseif ($student['days_inactive'] >= 7) {
                                            echo 'warning';
                                        } else {
                                            echo 'low';
                                        }
                                    ?>">
                                        <?php echo $student['days_inactive'] < 999 ? $student['days_inactive'] : 'Never'; ?>
                                    </span>
                                </td>
                                <td class="center" style="color: #6b7280; font-size: 0.85rem;">
                                    <?php echo $student['last_access']; ?>
                                </td>
                                <td class="center">
                                    <span class="inactive-activity-badge" style="background: <?php echo $student['recent_logins'] > 5 ? '#d1fae5' : ($student['recent_logins'] > 2 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $student['recent_logins'] > 5 ? '#065f46' : ($student['recent_logins'] > 2 ? '#92400e' : '#991b1b'); ?>;">
                                        <?php echo $student['recent_logins']; ?>
                                    </span>
                                </td>
                                <td class="center">
                                    <span class="inactive-activity-badge" style="background: <?php echo $student['quiz_attempts'] > 3 ? '#d1fae5' : ($student['quiz_attempts'] > 0 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $student['quiz_attempts'] > 3 ? '#065f46' : ($student['quiz_attempts'] > 0 ? '#92400e' : '#991b1b'); ?>;">
                                        <?php echo $student['quiz_attempts']; ?>
                                    </span>
                                </td>
                                <td class="center" style="color: #4b5563; font-weight: 600;">
                                    <?php echo $student['enrolled_courses']; ?>
                                </td>
                                <td class="center">
                                    <span class="inactive-status-badge <?php echo $student['alert_level']; ?>">
                                        <?php echo $student['activity_level']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div id="inactiveEmptyState" class="inactive-empty-state" style="display: none; margin-top: 20px;">
                <i class="fa fa-info-circle" style="font-size: 2rem; margin-bottom: 10px; display: block; color: #d1d5db;"></i>
                <p style="font-weight: 600; margin: 0; color: #6b7280;">No students found matching your search criteria.</p>
            </div>
            
            <div class="inactive-pagination" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding-top: 20px; border-top: 1px solid #e5e7eb; margin-top: 20px;">
                <div class="inactive-show-entries" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #6b7280;">
                    <span>Show:</span>
                    <select id="inactiveEntriesPerPage" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #1f2937; background: white; cursor: pointer;">
                        <option value="10" selected>10 entries</option>
                        <option value="25">25 entries</option>
                        <option value="50">50 entries</option>
                        <option value="100">100 entries</option>
                    </select>
                </div>
                <div id="inactivePaginationInfo" class="inactive-pagination-info" style="font-size: 0.9rem; color: #6b7280;">
                    Showing 0 to 0 of <?php echo count($inactive_students_data); ?> entries
                </div>
                <div class="inactive-pagination-controls" style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" id="inactivePrev" class="inactive-pagination-btn" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #1f2937; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">&lt; Previous</button>
                    <div id="inactivePageNumbers" class="inactive-page-numbers" style="display: flex; gap: 8px; align-items: center;"></div>
                    <button type="button" id="inactiveNext" class="inactive-pagination-btn" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #1f2937; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">Next &gt;</button>
                </div>
            </div>
            
            <div class="inactive-table-footer" style="margin-top: 20px;">
                <p>
                    <strong>Activity Levels:</strong> 
                    <span style="color: #991b1b;">Critical (14+ days)</span> • 
                    <span style="color: #92400e;">Warning (7-13 days)</span> • 
                    <span style="color: #1e40af;">Low Activity (3-6 days)</span>
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="inactive-empty-state">
            <div class="inactive-empty-icon">
                <i class="fa fa-check-circle"></i>
            </div>
            <h4 class="inactive-empty-title">All Students Active!</h4>
            <p class="inactive-empty-text">No inactive students detected. All students are actively engaged with the LMS.</p>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($inactive_students_data)): ?>
<script>
// Initialize Inactive Students Chart (matching course_reports.php pattern exactly)
console.log('Inactive students chart script loaded');
(function() {
    const criticalCount = <?php echo $critical_count; ?>;
    const warningCount = <?php echo $warning_count; ?>;
    const lowActivityCount = <?php echo $low_activity_count; ?>;
    
    console.log('Chart data:', { criticalCount, warningCount, lowActivityCount });
    
    function createChart() {
        const inactiveStudentsCtx = document.getElementById('inactiveStudentsChart');
        
        if (!inactiveStudentsCtx) {
            console.log('Canvas element not found');
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            console.log('Chart.js not loaded yet');
            return false;
        }
        
        // Check if canvas is in the DOM
        if (!document.body.contains(inactiveStudentsCtx)) {
            console.log('Canvas not in DOM yet');
            return false;
        }
        
        // Check if parent container is visible (but don't block if hidden - chart can still initialize)
        const parentContainer = inactiveStudentsCtx.closest('.inactive-chart-card');
        const isVisible = !parentContainer || parentContainer.offsetParent !== null;
        
        // Ensure canvas has dimensions
        const canvasContainer = inactiveStudentsCtx.parentElement;
        if (canvasContainer) {
            if (!canvasContainer.style.height) {
                canvasContainer.style.height = '350px';
            }
            if (!canvasContainer.style.width) {
                canvasContainer.style.width = '100%';
            }
        }
        
        // Destroy existing chart if any
        if (window.inactiveStudentsChartInstance) {
            try {
                window.inactiveStudentsChartInstance.destroy();
            } catch(e) {}
            window.inactiveStudentsChartInstance = null;
        }
        
        console.log('Creating chart with data:', { criticalCount, warningCount, lowActivityCount });
        
        try {
            window.inactiveStudentsChartInstance = new Chart(inactiveStudentsCtx, {
            type: 'bar',
            data: {
                labels: ['Critical\n(14+ days)', 'Warning\n(7-13 days)', 'Low Activity\n(3-6 days)'],
                datasets: [{
                    label: 'Number of Students',
                    data: [criticalCount, warningCount, lowActivityCount],
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.8)',   // Red for Critical
                        'rgba(245, 158, 11, 0.8)',   // Orange for Warning
                        'rgba(59, 130, 246, 0.8)'    // Blue for Low Activity
                    ],
                    borderColor: [
                        '#ef4444',
                        '#f59e0b',
                        '#3b82f6'
                    ],
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed.y;
                                const total = criticalCount + warningCount + lowActivityCount;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return ` ${value} students (${percentage}%)`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6b7280'
                        },
                        grid: {
                            color: '#e5e7eb',
                            drawBorder: false
                        },
                        title: {
                            display: true,
                            text: 'Number of Students',
                            font: {
                                size: 13,
                                weight: 'bold'
                            },
                            color: '#374151',
                            padding: {
                                top: 0,
                                bottom: 10
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 11,
                                weight: '600'
                            },
                            color: '#6b7280'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
            });
            console.log('Inactive students chart created successfully');
            return true;
        } catch (error) {
            console.error('Error initializing inactive students chart:', error);
            return false;
        }
    }
    
    // Try to create chart - wait for both canvas and Chart.js
    function tryInitChart() {
        if (createChart()) {
            console.log('Inactive students chart initialized successfully');
            return true;
        }
        return false;
    }
    
    // Make function globally accessible for manual triggering
    window.initInactiveStudentsChart = tryInitChart;
    
    // Try immediately
    if (tryInitChart()) {
        // Success - chart created
    } else {
        // Retry for AJAX-loaded content
        let attempts = 0;
        const maxAttempts = 150;
        
        const retryInterval = setInterval(function() {
            attempts++;
            if (tryInitChart() || attempts >= maxAttempts) {
                clearInterval(retryInterval);
            }
        }, 100);
        
        // Multiple delayed attempts as backup
        setTimeout(tryInitChart, 200);
        setTimeout(tryInitChart, 500);
        setTimeout(tryInitChart, 1000);
        setTimeout(tryInitChart, 2000);
        setTimeout(tryInitChart, 3000);
    }
    
    // Trigger when tab becomes visible (for AJAX-loaded tabs)
    setTimeout(function() {
        const chartCard = document.querySelector('.inactive-chart-card');
        if (chartCard) {
            // Use requestAnimationFrame to check visibility
            function checkAndInit() {
                if (!window.inactiveStudentsChartInstance && chartCard.offsetParent !== null) {
                    tryInitChart();
                }
            }
            requestAnimationFrame(checkAndInit);
            setTimeout(checkAndInit, 100);
        }
    }, 100);
})();

// Table search, filter, and pagination
(function() {
    const allRows = document.querySelectorAll('.inactive-student-row');
    let entriesPerPage = 10;
    let currentPage = 1;
    
    const searchInput = document.getElementById('inactiveStudentSearch');
    const clearBtn = document.getElementById('inactiveStudentClear');
    const cohortFilter = document.getElementById('inactiveCohortFilter');
    const tableBody = document.getElementById('inactiveStudentsTableBody');
    const emptyState = document.getElementById('inactiveEmptyState');
    const prevBtn = document.getElementById('inactivePrev');
    const nextBtn = document.getElementById('inactiveNext');
    const pageNumbers = document.getElementById('inactivePageNumbers');
    const paginationInfo = document.getElementById('inactivePaginationInfo');
    const entriesSelect = document.getElementById('inactiveEntriesPerPage');
    
    if (!allRows.length || !tableBody) {
        return;
    }
    
    let filteredRows = Array.from(allRows);
    const totalRows = allRows.length;
    
    function updateDisplay() {
        const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
        
        // Show/hide clear button
        if (clearBtn) {
            clearBtn.style.display = searchTerm ? 'block' : 'none';
        }
        
        // Filter rows by search
        filteredRows = Array.from(allRows).filter(row => {
            // Search filter
            if (searchTerm) {
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const grade = row.getAttribute('data-grade') || '';
                if (!name.includes(searchTerm) && !email.includes(searchTerm) && !grade.includes(searchTerm)) {
                    return false;
                }
            }
            
            return true;
        });
        
        // Hide all rows
        allRows.forEach(row => row.style.display = 'none');
        
        // Calculate pagination
        const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        const pageRows = filteredRows.slice(startIndex, endIndex);
        
        // Show page rows
        pageRows.forEach(row => row.style.display = '');
        
        // Update empty state
        if (emptyState) {
            emptyState.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }
        if (tableBody) {
            tableBody.style.display = filteredRows.length === 0 ? 'none' : '';
        }
        
        // Update pagination info
        if (paginationInfo) {
            const start = filteredRows.length === 0 ? 0 : startIndex + 1;
            const end = Math.min(endIndex, filteredRows.length);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
        }
        
        // Update pagination buttons
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages === 0;
        
        // Update page numbers
        if (pageNumbers) {
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(startPage + maxButtons - 1, totalPages);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            let html = '';
            if (startPage > 1) {
                html += `<button class="inactive-page-number" data-page="1" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; color: #1f2937; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; min-width: 40px; text-align: center; transition: all 0.2s;">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="inactive-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}" style="padding: 8px 12px; border: 1px solid #d1d5db; background: ${i === currentPage ? '#3b82f6' : 'white'}; color: ${i === currentPage ? 'white' : '#1f2937'}; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; min-width: 40px; text-align: center; transition: all 0.2s;">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="inactive-page-number" data-page="${totalPages}" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; color: #1f2937; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; min-width: 40px; text-align: center; transition: all 0.2s;">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            // Add click handlers
            pageNumbers.querySelectorAll('.inactive-page-number').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPage = parseInt(this.getAttribute('data-page'));
                    updateDisplay();
                });
            });
        }
    }
    
    // Event listeners
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentPage = 1;
            updateDisplay();
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                currentPage = 1;
                updateDisplay();
            }
        });
    }
    
    if (cohortFilter) {
        cohortFilter.addEventListener('change', function() {
            const cohortId = parseInt(this.value || '0');
            const currentUrl = new URL(window.location.href);
            
            // Update URL parameters
            if (cohortId === 0) {
                currentUrl.searchParams.delete('cohort');
            } else {
                currentUrl.searchParams.set('cohort', cohortId);
            }
            currentUrl.searchParams.set('tab', 'inactive');
            
            // Update browser URL
            window.history.pushState(
                { tab: 'inactive', cohort: cohortId },
                '',
                currentUrl.toString()
            );
            
            // Find the tab pane and reload its content
            const tabPane = document.querySelector('[data-tab="inactive"]');
            if (tabPane) {
                // Show loading state
                tabPane.innerHTML = '<div style="padding: 40px; text-align: center;"><i class="fa fa-spinner fa-spin" style="font-size: 2rem; color: #3b82f6;"></i><p style="margin-top: 10px; color: #6b7280;">Loading...</p></div>';
                
                // Build the fetch URL - construct from current location
                const currentPath = window.location.pathname;
                // Extract the base path (everything before the filename)
                const pathParts = currentPath.split('/');
                pathParts[pathParts.length - 1] = 'student_report_inactive.php';
                const basePath = pathParts.join('/');
                
                let fetchUrl = basePath + '?ajax=1';
                if (cohortId > 0) {
                    fetchUrl += '&cohort=' + cohortId;
                }
                
                console.log('Fetching from:', fetchUrl);
                
                // Fetch new content
                fetch(fetchUrl, { credentials: 'same-origin' })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Failed to load content');
                        }
                        return response.text();
                    })
                    .then(html => {
                        // Update the pane content
                        tabPane.innerHTML = html;
                        
                        // Execute scripts in the new content
                        const scripts = tabPane.querySelectorAll('script');
                        scripts.forEach(oldScript => {
                            const newScript = document.createElement('script');
                            Array.from(oldScript.attributes).forEach(attr => {
                                newScript.setAttribute(attr.name, attr.value);
                            });
                            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                            oldScript.parentNode.replaceChild(newScript, oldScript);
                        });
                    })
                    .catch(error => {
                        console.error('Error loading cohort filter:', error);
                        tabPane.innerHTML = '<div style="padding: 40px; text-align: center; color: #ef4444;"><i class="fa fa-exclamation-triangle"></i><p>Error loading data. Please try again.</p></div>';
                    });
            } else {
                // Fallback: reload the page
                window.location.href = currentUrl.toString();
            }
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateDisplay();
        });
    }
    
    // Initial display
    updateDisplay();
})();
</script>
<?php endif; ?>

<?php
echo ob_get_clean();
exit;
?>

