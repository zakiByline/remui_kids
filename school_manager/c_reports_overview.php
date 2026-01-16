<?php
/**
 * C Reports - Course Overview Reports Tab (AJAX fragment)
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
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'overview']);
    redirect($target);
}

$overview_stats = [
    'total_courses' => 0,
    'total_enrollments' => 0,
    'total_completions' => 0,
    'active_courses' => 0,
    'average_completion_rate' => 0
];

$course_details = [];

if ($company_info) {
    // Get all courses for this company
    $courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate, c.category
         FROM {course} c
         INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
         WHERE c.visible = 1 AND c.id > 1
         ORDER BY c.fullname ASC",
        [$company_info->id]
    );
    
    // Get teacher role IDs
    $teacher_role_ids = $DB->get_records_sql(
        "SELECT id FROM {role} WHERE shortname IN ('teacher', 'editingteacher')"
    );
    $teacher_role_ids = array_keys($teacher_role_ids);
    $teacher_role_ids_sql = implode(',', $teacher_role_ids);
    
    // Get quiz module ID
    $quiz_module_id = $DB->get_field('modules', 'id', ['name' => 'quiz']);
    // Get assignment module ID
    $assign_module_id = $DB->get_field('modules', 'id', ['name' => 'assign']);
    
    foreach ($courses as $course) {
        // Get course category name
        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $category_name = $category ? format_string($category->name) : 'Uncategorized';
        
        // Get assigned teachers
        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
             FROM {user} u
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid
             INNER JOIN {role} r ON r.id = ra.roleid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             WHERE ctx.contextlevel = ? 
             AND ctx.instanceid = ?
             AND r.shortname IN ('teacher', 'editingteacher')
             AND cu.companyid = ?
             AND COALESCE(cu.educator, 0) = 1
             AND u.deleted = 0",
            [CONTEXT_COURSE, $course->id, $company_info->id]
    );
    
        $assigned_teacher_count = count($teachers);
        
        // Format dates
        $start_date = $course->startdate ? date('d/m/Y', $course->startdate) : 'Not set';
        $end_date = $course->enddate ? date('d/m/Y', $course->enddate) : 'Not set';
        
        // Get total enrolled students (students only)
        $total_enrolled = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE e.courseid = ? 
             AND ue.status = 0
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [CONTEXT_COURSE, $course->id, $company_info->id]
    );
    
        // Get completed students
        $completed_students = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id)
             FROM {user} u
             INNER JOIN {course_completions} cc ON cc.userid = u.id
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = cc.course
             INNER JOIN {role} r ON r.id = ra.roleid
             WHERE cc.course = ? 
         AND cc.timecompleted IS NOT NULL
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [CONTEXT_COURSE, $course->id, $company_info->id]
        );
        
        // Not completed = total enrolled - completed
        $not_completed = max(0, $total_enrolled - $completed_students);
        
        // Get total quizzes
        $total_quizzes = 0;
        if ($quiz_module_id) {
            $total_quizzes = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cm.id)
                 FROM {course_modules} cm
                 INNER JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = ?
                 AND cm.module = ?
                 AND cm.visible = 1
                 AND cm.deletioninprogress = 0",
                [$course->id, $quiz_module_id]
            );
        }
        
        // Get total assignments
        $total_assignments = 0;
        if ($assign_module_id) {
            $total_assignments = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cm.id)
                 FROM {course_modules} cm
                 INNER JOIN {assign} a ON a.id = cm.instance
                 WHERE cm.course = ?
                 AND cm.module = ?
                 AND cm.visible = 1
                 AND cm.deletioninprogress = 0",
                [$course->id, $assign_module_id]
            );
        }
        
        // Get completed quizzes (quizzes with finished attempts)
        $completed_quizzes = 0;
        if ($quiz_module_id && $total_quizzes > 0) {
            $completed_quizzes = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT qa.quiz)
                 FROM {quiz_attempts} qa
                 INNER JOIN {quiz} q ON q.id = qa.quiz
                 INNER JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                 INNER JOIN {user} u ON u.id = qa.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE qa.quiz IN (
                     SELECT q2.id FROM {quiz} q2
                     INNER JOIN {course_modules} cm2 ON cm2.instance = q2.id
                     WHERE cm2.course = ? AND cm2.module = ? AND cm2.visible = 1
                 )
                 AND qa.state = 'finished'
                 AND cu.companyid = ?
                 AND u.deleted = 0
                 AND u.suspended = 0",
                [$quiz_module_id, $course->id, $quiz_module_id, $company_info->id]
            );
        }
        
        // Get completed assignments (assignments with submitted submissions)
        $completed_assignments = 0;
        if ($assign_module_id && $total_assignments > 0) {
            $completed_assignments = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT s.assignment)
                 FROM {assign_submission} s
                 INNER JOIN {assign} a ON a.id = s.assignment
                 INNER JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = ?
                 INNER JOIN {user} u ON u.id = s.userid
                 INNER JOIN {company_users} cu ON cu.userid = u.id
                 WHERE s.assignment IN (
                     SELECT a2.id FROM {assign} a2
                     INNER JOIN {course_modules} cm2 ON cm2.instance = a2.id
                     WHERE cm2.course = ? AND cm2.module = ? AND cm2.visible = 1
                 )
                 AND s.status = 'submitted'
                 AND s.latest = 1
                 AND cu.companyid = ?
                 AND u.deleted = 0
                 AND u.suspended = 0",
                [$assign_module_id, $course->id, $assign_module_id, $company_info->id]
            );
        }
        
        // Calculate average course completion rate
        $enrolled_students_data = $DB->get_records_sql(
            "SELECT DISTINCT u.id, cc.timecompleted, cc.timestarted
             FROM {user} u
             INNER JOIN {user_enrolments} ue ON ue.userid = u.id
             INNER JOIN {enrol} e ON e.id = ue.enrolid
             INNER JOIN {company_users} cu ON cu.userid = u.id
             INNER JOIN {role_assignments} ra ON ra.userid = u.id
             INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ? AND ctx.instanceid = e.courseid
             INNER JOIN {role} r ON r.id = ra.roleid
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
             WHERE e.courseid = ? 
             AND ue.status = 0
             AND cu.companyid = ?
             AND r.shortname = 'student'
             AND u.deleted = 0
             AND u.suspended = 0",
            [CONTEXT_COURSE, $course->id, $company_info->id]
    );
        
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
            if ($student_data->timecompleted) {
                $student_progress = 100;
            } else {
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
                    if ($student_data->timestarted) {
                        $student_progress = 5;
                    }
                }
            }
            $total_progress += $student_progress;
        }
        
        $average_completion_rate = $students_count > 0 ? round($total_progress / $students_count, 1) : 0;
        
        $course_details[] = [
            'id' => $course->id,
            'name' => $course->fullname,
            'category' => $category_name,
            'assigned_teacher_count' => $assigned_teacher_count,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_enrolled' => $total_enrolled,
            'completed' => $completed_students,
            'not_completed' => $not_completed,
            'total_quizzes' => $total_quizzes,
            'total_assignments' => $total_assignments,
            'completed_quizzes' => $completed_quizzes,
            'completed_assignments' => $completed_assignments,
            'average_completion_rate' => $average_completion_rate
        ];
    }
    
    // Calculate overview stats
    $overview_stats['total_courses'] = count($course_details);
    $overview_stats['total_enrollments'] = array_sum(array_column($course_details, 'total_enrolled'));
    $overview_stats['total_completions'] = array_sum(array_column($course_details, 'completed'));
    
    // Active courses (with activity in last 30 days)
    $overview_stats['active_courses'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id)
         FROM {course} c
         INNER JOIN {company_course} cc_link ON cc_link.courseid = c.id AND cc_link.companyid = ?
         LEFT JOIN {logstore_standard_log} l ON l.courseid = c.id AND l.timecreated >= ?
         WHERE c.visible = 1 AND c.id > 1
         AND l.id IS NOT NULL",
        [$company_info->id, strtotime('-30 days')]
    );
    
    // Average completion rate across all courses
    $total_completion_rates = array_sum(array_column($course_details, 'average_completion_rate'));
    $overview_stats['average_completion_rate'] = $overview_stats['total_courses'] > 0 
        ? round($total_completion_rates / $overview_stats['total_courses'], 1) 
        : 0;
}

?>

<div class="report-table-container">
    <h3 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
        <i class="fa fa-dashboard" style="color: #8b5cf6;"></i> Course Overview Reports
    </h3>
    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">Overall course statistics and metrics for <?php echo htmlspecialchars($company_info ? $company_info->name : 'the school'); ?>.</p>

    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="stat-card" style="background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(59, 130, 246, 0.18); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);">
            <div class="stat-label" style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 600;">Total Courses</div>
            <div class="stat-value" style="font-size: 2.4rem; font-weight: 800; color: #1d4ed8; line-height: 1;"><?php echo number_format($overview_stats['total_courses']); ?></div>
            <div class="stat-subtext" style="font-size: 0.85rem; color: #475569;">All available courses</div>
        </div>

        <div class="stat-card" style="background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(59, 130, 246, 0.18); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);">
            <div class="stat-label" style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 600;">Total Enrollments</div>
            <div class="stat-value" style="font-size: 2.4rem; font-weight: 800; color: #1d4ed8; line-height: 1;"><?php echo number_format($overview_stats['total_enrollments']); ?></div>
            <div class="stat-subtext" style="font-size: 0.85rem; color: #475569;">Student enrollments</div>
        </div>

        <div class="stat-card" style="background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(59, 130, 246, 0.18); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);">
            <div class="stat-label" style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 600;">Total Completions</div>
            <div class="stat-value" style="font-size: 2.4rem; font-weight: 800; color: #1d4ed8; line-height: 1;"><?php echo number_format($overview_stats['total_completions']); ?></div>
            <div class="stat-subtext" style="font-size: 0.85rem; color: #475569;">Completed courses</div>
        </div>

        <div class="stat-card" style="background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(59, 130, 246, 0.18); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);">
            <div class="stat-label" style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 600;">Active Courses</div>
            <div class="stat-value" style="font-size: 2.4rem; font-weight: 800; color: #1d4ed8; line-height: 1;"><?php echo number_format($overview_stats['active_courses']); ?></div>
            <div class="stat-subtext" style="font-size: 0.85rem; color: #475569;">Active in last 30 days</div>
        </div>

        <div class="stat-card" style="background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(59, 130, 246, 0.18); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);">
            <div class="stat-label" style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 600;">Avg Completion Rate</div>
            <div class="stat-value" style="font-size: 2.4rem; font-weight: 800; color: #1d4ed8; line-height: 1;"><?php echo number_format($overview_stats['average_completion_rate'], 1); ?>%</div>
            <div class="stat-subtext" style="font-size: 0.85rem; color: #475569;">Overall completion rate</div>
        </div>
    </div>

    <!-- Course Details Table -->
    <div class="course-details-section" style="margin-top: 40px;">
        <h4 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin-bottom: 20px;">
            <i class="fa fa-list" style="color: #8b5cf6;"></i> Course Details List
        </h4>
        
        <?php if (!empty($course_details)): ?>
            <div class="table-responsive" style="overflow-x: auto;">
                <table class="course-overview-table" id="courseOverviewTable" style="width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); border: 1px solid #e5e7eb;">
                    <thead>
                        <tr style="background: #f3f4f6; color: #1f2937; border-bottom: 1px solid #e5e7eb;">
                            <th>Name</th>
                            <th>Category</th>
                            <th style="text-align:center;">Teachers</th>
                            <th style="text-align:center;">Student</th>
                            <th style="text-align:center;">Completed</th>
                            <th style="text-align:center;">Not-Completed</th>
                            <th style="text-align:center;">Quizzes</th>
                            <th style="text-align:center;">Assignments</th>
                            <th style="text-align:center;">Completion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_details as $index => $course): ?>
                            <tr style="border-bottom: 1px solid #e5e7eb; transition: background 0.2s;">
                                <td style="padding: 12px 15px; color: #1f2937; font-weight: 600;">
                                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/course_overview_detail.php?courseid=<?php echo $course['id']; ?>" 
                                       style="color: #667eea; text-decoration: none;">
                                        <?php echo htmlspecialchars($course['name']); ?>
                                    </a>
                                </td>
                                <td style="padding: 12px 15px; color: #374151;"><?php echo htmlspecialchars($course['category']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #374151; font-weight: 600;"><?php echo number_format($course['assigned_teacher_count']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #1f2937; font-weight: 600;"><?php echo number_format($course['total_enrolled']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #10b981; font-weight: 600;"><?php echo number_format($course['completed']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #ef4444; font-weight: 600;"><?php echo number_format($course['not_completed']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #374151; font-weight: 600;"><?php echo number_format($course['total_quizzes']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #374151; font-weight: 600;"><?php echo number_format($course['total_assignments']); ?></td>
                                <td style="padding: 12px 15px; text-align: center; color: #667eea; font-weight: 700;"><?php echo number_format($course['average_completion_rate'], 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="course-pagination" id="coursePaginationWrapper">
                    <div class="pagination-info" id="coursePaginationInfo">Showing 0-0 of 0 courses</div>
                    <div class="pagination-controls">
                        <button type="button" class="pagination-btn" id="coursePaginationPrev">Previous</button>
                        <div class="pagination-numbers" id="coursePaginationNumbers"></div>
                        <button type="button" class="pagination-btn" id="coursePaginationNext">Next</button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                <i class="fa fa-book" style="font-size: 4rem; color: #9ca3af; margin-bottom: 20px;"></i>
                <h3 style="color: #374151; margin: 0 0 10px 0;">No Courses Found</h3>
                <p style="color: #6b7280; margin: 0;">There are no courses available at the moment.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.report-table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
    margin-bottom: 30px;
}

.course-overview-table {
    font-size: 0.9rem;
}

.course-overview-table th {
    padding: 15px;
    font-weight: 700;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    text-transform: none;
    text-align: left;
}

.course-overview-table th.th-multiline {
    white-space: normal;
    line-height: 1.1;
}

.course-overview-table th.th-multiline span {
    display: block;
    font-size: 0.9rem;
    line-height: 1.2;
}

.course-overview-table tbody tr:hover {
    background: #f9fafb;
}

.course-overview-table tbody tr:last-child {
    border-bottom: none;
}

.course-overview-table a {
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
}

.course-overview-table a:hover {
    text-decoration: underline;
}

.course-pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
    gap: 15px;
    margin-top: 0;
}

.course-pagination .pagination-info {
    font-size: 0.9rem;
    color: #4b5563;
    font-weight: 500;
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination-btn {
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.pagination-btn:hover:not(:disabled) {
    border-color: #6366f1;
    color: #4338ca;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-numbers {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.pagination-number-btn {
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    min-width: 34px;
    text-align: center;
    transition: all 0.2s ease;
}

.pagination-number-btn.active {
    background: #4f46e5;
    color: white;
    border-color: #4338ca;
}

@media (max-width: 1400px) {
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .course-overview-table {
        min-width: 1400px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('courseOverviewTable');
    if (!table) {
        return;
    }

    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const rowsPerPage = 10;
    const totalRows = rows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
    let currentPage = 1;

    const wrapper = document.getElementById('coursePaginationWrapper');
    const info = document.getElementById('coursePaginationInfo');
    const prevBtn = document.getElementById('coursePaginationPrev');
    const nextBtn = document.getElementById('coursePaginationNext');
    const numbersWrap = document.getElementById('coursePaginationNumbers');

    if (!wrapper || totalRows === 0) {
        if (wrapper) {
            wrapper.style.display = 'none';
        }
        return;
    }

    // Show pagination only if there are more than 10 courses
    if (totalRows <= rowsPerPage) {
        wrapper.style.display = 'none';
        // Show all rows if there are 10 or fewer
        rows.forEach((row) => {
            row.style.display = 'table-row';
        });
        return;
    }

    // Initially hide all rows except the first page
    rows.forEach((row, idx) => {
        row.style.display = idx < rowsPerPage ? 'table-row' : 'none';
    });

    const renderPage = (page) => {
        currentPage = page;
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = startIndex + rowsPerPage;

        rows.forEach((row, idx) => {
            row.style.display = (idx >= startIndex && idx < endIndex) ? 'table-row' : 'none';
        });

        const visibleStart = totalRows === 0 ? 0 : startIndex + 1;
        const visibleEnd = Math.min(endIndex, totalRows);
        if (info) {
            info.textContent = `Showing ${visibleStart}-${visibleEnd} of ${totalRows} courses`;
        }

        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage === totalPages;

        if (numbersWrap) {
            Array.from(numbersWrap.children).forEach(btn => btn.classList.remove('active'));
            const activeBtn = numbersWrap.querySelector(`[data-page="${currentPage}"]`);
            if (activeBtn) {
                activeBtn.classList.add('active');
            }
        }
    };

    const buildPaginationNumbers = () => {
        if (!numbersWrap) {
            return;
        }
        numbersWrap.innerHTML = '';
        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pagination-number-btn';
            btn.dataset.page = i;
            btn.textContent = i;
            btn.addEventListener('click', () => {
                if (i !== currentPage) {
                    renderPage(i);
                }
            });
            numbersWrap.appendChild(btn);
        }
    };

    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                renderPage(currentPage - 1);
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                renderPage(currentPage + 1);
            }
        });
    }

    buildPaginationNumbers();
    renderPage(1);
});
</script>
