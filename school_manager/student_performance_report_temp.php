<?php
/**
 * Student Performance Report - School Manager
 * Comprehensive student performance analysis with grades, completion rates, and test scores
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

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get all courses for this company
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
        
        // Get activity count (recent access)
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
             AND ula.timeaccess > ?",
            [$course->id, $company_info->id, time() - (30 * 24 * 60 * 60)]
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
            'startdate' => $course->startdate
        ];
    }
}

// Get detailed course enrollment data for enrollment details section
$enrollment_courses_data = [];
if ($company_info) {
    $enrollment_courses_data = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.startdate, c.visible,
               COUNT(DISTINCT CASE WHEN r.shortname = 'student' AND u.deleted = 0 AND u.suspended = 0 THEN ue.userid END) as total_students,
               COUNT(DISTINCT CASE WHEN r.shortname = 'student' AND cc.timecompleted IS NOT NULL AND u.deleted = 0 AND u.suspended = 0 THEN ue.userid END) as completed_students,
               COUNT(DISTINCT CASE WHEN r.shortname = 'student' AND cc.timecompleted IS NULL AND ula.timeaccess IS NOT NULL AND u.deleted = 0 AND u.suspended = 0 THEN ue.userid END) as in_progress_students,
               COUNT(DISTINCT CASE WHEN r.shortname = 'student' AND cc.timecompleted IS NULL AND (ula.timeaccess IS NULL OR ula.timeaccess = 0) AND u.deleted = 0 AND u.suspended = 0 THEN ue.userid END) as not_started_students
        FROM {course} c
        INNER JOIN {company_course} cc_link ON cc_link.courseid = c.id
        LEFT JOIN {enrol} e ON e.courseid = c.id
        LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
        LEFT JOIN {user} u ON u.id = ue.userid
        LEFT JOIN {course_completions} cc ON cc.course = c.id AND cc.userid = ue.userid
        LEFT JOIN {user_lastaccess} ula ON ula.courseid = c.id AND ula.userid = ue.userid
        LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
        LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
        LEFT JOIN {role} r ON r.id = ra.roleid
        WHERE cc_link.companyid = :company_id
        AND c.id != 1
        AND c.visible = 1
        GROUP BY c.id, c.fullname, c.shortname, c.startdate, c.visible
        ORDER BY total_students DESC, c.fullname ASC",
        ['company_id' => $company_info->id]
    );
}

// Calculate enrollment statistics using the same data source as pie chart ($courses_data)
// This ensures consistency between the pie chart and summary cards
$enrollment_total_courses = count($courses_data);
$enrollment_courses_with_students = 0;
$enrollment_empty_courses = 0;
$enrollment_total_enrollments = 0;

foreach ($courses_data as $course) {
    if ($course['total_enrolled'] > 0) {
        $enrollment_courses_with_students++;
        $enrollment_total_enrollments += $course['total_enrolled'];
    } else {
        $enrollment_empty_courses++;
    }
}

$enrollment_avg_students_per_course = $enrollment_total_courses > 0 ? round($enrollment_total_enrollments / $enrollment_total_courses, 1) : 0;

// Get detailed student data for Student Performance Report
$detailed_students_data = [];
if ($company_info) {
    // Get all students with enrollment details
    $students_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
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
                     GROUP BY u.id, u.firstname, u.lastname, u.email, uifd.data
                     ORDER BY u.firstname ASC, u.lastname ASC";
    
    $students = $DB->get_records_sql($students_sql, [$company_info->id]);
    
    foreach ($students as $student) {
        // Get student's courses
        $student_courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, cc.timecompleted,
                    gg.finalgrade, gg.rawgrademax
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             JOIN {course} c ON c.id = e.courseid
             JOIN {company_course} cc_link ON cc_link.courseid = c.id
             LEFT JOIN {course_completions} cc ON cc.userid = ue.userid AND cc.course = c.id
             LEFT JOIN {grade_grades} gg ON gg.userid = ue.userid
             LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = c.id AND gi.itemtype = 'course'
             WHERE ue.userid = ?
             AND cc_link.companyid = ?
             AND ue.status = 0",
            [$student->id, $company_info->id]
        );
        
        $total_courses = count($student_courses);
        $completed = 0;
        $in_progress = 0;
        $not_started = 0;
        $total_grade = 0;
        $graded_courses = 0;
        $course_details = [];
        
        foreach ($student_courses as $course) {
            $course_status = 'not_started';
            $course_grade = 0;
            
            if ($course->timecompleted) {
                $completed++;
                $course_status = 'completed';
            } elseif ($course->finalgrade || $course->rawgrademax) {
                $in_progress++;
                $course_status = 'in_progress';
            } else {
                $not_started++;
                $course_status = 'not_started';
            }
            
            if ($course->finalgrade && $course->rawgrademax > 0) {
                $course_grade = round(($course->finalgrade / $course->rawgrademax) * 100, 1);
                $total_grade += $course_grade;
                $graded_courses++;
            }
            
            // Store course details
            $course_details[] = [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'status' => $course_status,
                'grade' => $course_grade
            ];
        }
        
        // Calculate averages
        $average_grade = $graded_courses > 0 ? round($total_grade / $graded_courses, 1) : 0;
        $completion_rate = $total_courses > 0 ? round(($completed / $total_courses) * 100, 1) : 0;
        
        // Store student data
        $detailed_students_data[] = [
            'user_id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'grade_level' => $student->grade_level ?? '',
            'total_courses' => $total_courses,
            'completed' => $completed,
            'in_progress' => $in_progress,
            'not_started' => $not_started,
            'average_grade' => $average_grade,
            'completion_rate' => $completion_rate,
            'course_details' => $course_details
        ];
    }
}

// Output HTML after data processing
?>
<!-- Sub-Tab Content: Overview (Default - Contains existing content) -->
<div id="studentperf-subtab-overview" class="studentperf-subtab-content active">
    <?php if (!empty($detailed_students_data)): ?>
                        <!-- Summary Statistics Cards -->
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
                            <div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid #3b82f6;">
                                <div style="font-size: 2rem; font-weight: 700; color: #1e40af; margin-bottom: 8px;">
                                    <?php echo count($detailed_students_data); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #374151; font-weight: 600; text-transform: uppercase;">Total Students</div>
                            </div>
                            
                            <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid #10b981;">
                                <div style="font-size: 2rem; font-weight: 700; color: #047857; margin-bottom: 8px;">
                                    <?php 
                                    $avg_completion = array_sum(array_column($detailed_students_data, 'completion_rate')) / count($detailed_students_data);
                                    echo round($avg_completion, 1); 
                                    ?>%
                                </div>
                                <div style="font-size: 0.75rem; color: #374151; font-weight: 600; text-transform: uppercase;">Avg Completion Rate</div>
                            </div>
                            
                            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid #f59e0b;">
                                <div style="font-size: 2rem; font-weight: 700; color: #b45309; margin-bottom: 8px;">
                                    <?php 
                                    $grades_available = array_filter($detailed_students_data, function($s) { return $s['average_grade'] > 0; });
                                    $avg_grade = !empty($grades_available) ? round(array_sum(array_column($grades_available, 'average_grade')) / count($grades_available), 1) : 0;
                                    echo $avg_grade; 
                                    ?>%
                                </div>
                                <div style="font-size: 0.75rem; color: #374151; font-weight: 600; text-transform: uppercase;">Average Grade</div>
                            </div>
                            
                            <div style="background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid #8b5cf6;">
                                <div style="font-size: 2rem; font-weight: 700; color: #6d28d9; margin-bottom: 8px;">
                                    <?php 
                                    $high_performers = count(array_filter($detailed_students_data, function($s) { 
                                        return $s['completion_rate'] >= 80; 
                                    }));
                                    echo $high_performers; 
                                    ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #374151; font-weight: 600; text-transform: uppercase;">High Performers</div>
                            </div>
                        </div>
                        
                        <!-- Charts Section -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 35px;">
                            <!-- Performance Distribution Doughnut Chart -->
                            <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h4 style="font-size: 1rem; font-weight: 600; color: #1f2937; margin: 0; flex: 1; text-align: center;">
                                        <i class="fa fa-star" style="color: #f59e0b;"></i> Performance Distribution
                                    </h4>
                                    <button id="performance-toggle-btn" onclick="toggleStudentPerformanceList()" style="background: #f59e0b; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; white-space: nowrap; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);" onmouseover="this.style.background='#d97706'; this.style.boxShadow='0 2px 4px rgba(245, 158, 11, 0.3)'" onmouseout="this.style.background='#f59e0b'; this.style.boxShadow='0 1px 2px rgba(0, 0, 0, 0.05)'">
                                        <i class="fa fa-eye-slash"></i> Hide Detail
                                    </button>
                                </div>
                                <div style="position: relative; height: 350px; display: flex; justify-content: center; align-items: center;">
                                    <canvas id="performanceDistributionChart"></canvas>
                        </div>
                            </div>
                            
                            <!-- Student Engagement Levels Pie Chart -->
                            <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h4 style="font-size: 1rem; font-weight: 600; color: #1f2937; margin: 0; flex: 1; text-align: center;">
                                        <i class="fa fa-running" style="color: #10b981;"></i> Student Engagement Levels
                                    </h4>
                                    <button id="engagement-toggle-btn" onclick="toggleEngagementDetails()" style="background: #10b981; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; white-space: nowrap; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);" onmouseover="this.style.background='#059669'; this.style.boxShadow='0 2px 4px rgba(16, 185, 129, 0.3)'" onmouseout="this.style.background='#10b981'; this.style.boxShadow='0 1px 2px rgba(0, 0, 0, 0.05)'">
                                        <i class="fa fa-eye"></i> View Detail
                                    </button>
                                </div>
                                <div style="position: relative; height: 350px; display: flex; justify-content: center; align-items: center;">
                                    <canvas id="studentEngagementChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Course Enrollment Status Pie Chart -->
                            <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h4 style="font-size: 1rem; font-weight: 600; color: #1f2937; margin: 0; flex: 1; text-align: center;">
                                        <i class="fa fa-chart-pie" style="color: #3b82f6;"></i> Course Enrollment Status
                                    </h4>
                                    <button id="enrollment-toggle-btn" onclick="toggleEnrollmentDetails()" style="background: #3b82f6; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; white-space: nowrap; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.background='#2563eb'; this.style.boxShadow='0 2px 4px rgba(59, 130, 246, 0.3)'" onmouseout="this.style.background='#3b82f6'; this.style.boxShadow='0 1px 2px rgba(0, 0, 0, 0.05)'">
                                        <i class="fa fa-eye"></i> View Detail
                                    </button>
                                </div>
                                <div style="position: relative; height: 350px; display: flex; justify-content: center; align-items: center;">
                                    <canvas id="studentPerfCourseStatusChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Student Engagement Report Section (hidden by default) -->
                        <div id="student-engagement-details" style="margin-top: 50px; display: none;">
                            <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                                <i class="fa fa-chart-line" style="color: #667eea;"></i> Student Engagement Report
                            </h3>
                            <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">Measures login frequency, time spent on LMS, and forum participation over the last 30 days for students in <?php echo htmlspecialchars($company_info->name); ?></p>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 35px;">
                                <?php
                                // Compute engagement metrics (last 30 days)
                                $time_range = time() - (30 * 24 * 60 * 60);
                                $total_logins = 0; $total_time_on_lms = 0; $total_forum_posts = 0; $engagement_scores = [];
                                $student_engagement_chart_data = [];
                                if (!empty($detailed_students_data)) {
                                    foreach ($detailed_students_data as $student) {
                                        $login_count = $DB->count_records_select('logstore_standard_log', "userid = ? AND action = 'loggedin' AND timecreated > ?", [$student['user_id'], $time_range]);
                                        $log_time_sql = "SELECT COUNT(*) * 5 as estimated_minutes FROM {logstore_standard_log} WHERE userid = ? AND timecreated > ? AND action IN ('viewed','submitted','updated')";
                                        $log_time = $DB->get_record_sql($log_time_sql, [$student['user_id'], $time_range]);
                                        $time_spent_hours = $log_time ? round(($log_time->estimated_minutes / 60), 1) : 0;
                                        $forum_posts = $DB->count_records_select('forum_posts', "userid = ? AND created > ?", [$student['user_id'], $time_range]);
                                        $engagement_score = min(100, round(($login_count * 5) + ($time_spent_hours * 2) + ($forum_posts * 10)));
                                        $total_logins += $login_count; $total_time_on_lms += $time_spent_hours; $total_forum_posts += $forum_posts; $engagement_scores[] = $engagement_score;
                                        $student_engagement_chart_data[] = [
                                            'name' => $student['name'],
                                            'login_count' => $login_count,
                                            'time_spent' => $time_spent_hours,
                                            'forum_posts' => $forum_posts,
                                            'engagement_score' => $engagement_score
                                        ];
                                    }
                                }
                                $avg_engagement_score = count($engagement_scores) > 0 ? round(array_sum($engagement_scores)/count($engagement_scores), 1) : 0;
                                usort($student_engagement_chart_data, function($a,$b){ return $b['engagement_score'] <=> $a['engagement_score']; });
                                ?>

                                <div style="background:#fff; padding:28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); border-left:4px solid #3b82f6;">
                                    <div style="font-size:3rem; font-weight:800; line-height:1; color:#3b82f6;">&nbsp;<?php echo $total_logins; ?></div>
                                    <div style="font-size:.8rem; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:600;">Total Logins (30 Days)</div>
                                </div>

                                <div style="background:#fff; padding:28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); border-left:4px solid #10b981;">
                                    <div style="font-size:3rem; font-weight:800; line-height:1; color:#10b981;">&nbsp;<?php echo $total_time_on_lms; ?>h</div>
                                    <div style="font-size:.8rem; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:600;">Total Time on LMS</div>
                                </div>

                                <div style="background:#fff; padding:28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); border-left:4px solid #f59e0b;">
                                    <div style="font-size:3rem; font-weight:800; line-height:1; color:#f59e0b;">&nbsp;<?php echo $total_forum_posts; ?></div>
                                    <div style="font-size:.8rem; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:600;">Forum Posts</div>
                                </div>

                                <div style="background:#fff; padding:28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); border-left:4px solid #ec4899;">
                                    <div style="font-size:3rem; font-weight:800; line-height:1; color:#ec4899;">&nbsp;<?php echo $avg_engagement_score; ?></div>
                                    <div style="font-size:.8rem; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:600;">Avg Engagement Score</div>
                                </div>
                            </div>

                            <div style="background:#fff; padding:30px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:30px;">
                                <div style="margin-bottom:25px;">
                                    <h4 style="font-size:1.2rem; font-weight:600; color:#1f2937; margin:0 0 8px 0; display:flex; align-items:center; gap:10px;"><i class="fa fa-fire" style="color:#f59e0b;"></i> Engagement Heatmap by Student</h4>
                                    <p style="color:#6b7280; font-size:.9rem; margin:0;">Student Engagement Heatmap (30 Days)</p>
                                </div>
                                <div style="position:relative; height:400px;"><canvas id="engagementHeatmapChart"></canvas></div>
                            </div>

                            <div style="background:#fff; padding:30px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08);">
                                <div style="margin-bottom:25px;">
                                    <h4 style="font-size:1.2rem; font-weight:600; color:#1f2937; margin:0 0 8px 0; display:flex; align-items:center; gap:10px;"><i class="fa fa-chart-bar" style="color:#3b82f6;"></i> Engagement Metrics Comparison</h4>
                                    <p style="color:#6b7280; font-size:.9rem; margin:0;">Student Engagement Metrics (Last 30 Days)</p>
                                </div>
                                <div style="position:relative; height:400px;"><canvas id="engagementMetricsChart"></canvas></div>
                            </div>
                        </div>

                        <!-- Course Enrollment Details Section -->
                        <div id="course-enrollment-details" style="margin-top: 50px; display: none;">
                            <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                                <i class="fa fa-chart-pie" style="color: #667eea;"></i> Course Enrollment Details
                            </h3>
                            <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">Comprehensive overview of course enrollments for <?php echo htmlspecialchars($company_info->name); ?></p>
                            
                            <!-- Statistics Grid -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 35px;">
                                <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #3b82f6; transition: all 0.3s ease;">
                                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 8px; color: #3b82f6;"><?php echo $enrollment_total_courses; ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Total Courses</div>
                                </div>

                                <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #10b981; transition: all 0.3s ease;">
                                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 8px; color: #10b981;"><?php echo $enrollment_courses_with_students; ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Courses with Students</div>
                                </div>

                                <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #ef4444; transition: all 0.3s ease;">
                                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 8px; color: #ef4444;"><?php echo $enrollment_empty_courses; ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Empty Courses</div>
                                </div>

                                <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #f59e0b; transition: all 0.3s ease;">
                                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 8px; color: #f59e0b;"><?php echo $enrollment_total_enrollments; ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Total Enrollments</div>
                                </div>

                                <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #8b5cf6; transition: all 0.3s ease;">
                                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 8px; color: #8b5cf6;"><?php echo $enrollment_avg_students_per_course; ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Avg Students/Course</div>
                                </div>
                            </div>

                            <!-- Courses Table -->
                            <div style="background: #ffffff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); overflow: hidden;">
                                <div style="padding: 25px; background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                        <div>
                                            <h4 style="margin: 0 0 8px 0; font-size: 1.2rem; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                                                <i class="fa fa-list"></i> All Courses
                                            </h4>
                                            <p style="margin: 0; color: #6b7280; font-size: 0.9rem;">Detailed breakdown of student enrollment across all courses</p>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <label style="color: #6b7280; font-size: 0.875rem; font-weight: 500; white-space: nowrap;">Show:</label>
                                            <select id="coursesPerPageSelect" onchange="changeCoursesPerPage(this.value)" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.875rem; font-weight: 500; cursor: pointer; background: white; color: #374151;">
                                                <option value="10" selected>10</option>
                                                <option value="20">20</option>
                                                <option value="50">50</option>
                                                <option value="all">All</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($enrollment_courses_data)): ?>
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
                                            <thead>
                                                <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                                    <th style="padding: 15px 20px; text-align: left; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">#</th>
                                                    <th style="padding: 15px 20px; text-align: left; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Course Name</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Total Students</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Completed</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">In Progress</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Not Started</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Completion Rate</th>
                                                    <th style="padding: 15px 20px; text-align: center; font-size: 0.8rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="coursesTableBody">
                                                <?php 
                                                $counter = 1;
                                                foreach ($enrollment_courses_data as $course): 
                                                    $completion_rate = $course->total_students > 0 
                                                        ? round(($course->completed_students / $course->total_students) * 100, 1) 
                                                        : 0;
                                                    
                                                    // Determine student count class
                                                    if ($course->total_students == 0) {
                                                        $count_class = 'zero';
                                                        $count_color = '#ef4444';
                                                    } elseif ($course->total_students >= 20) {
                                                        $count_class = 'high';
                                                        $count_color = '#10b981';
                                                    } elseif ($course->total_students >= 10) {
                                                        $count_class = 'medium';
                                                        $count_color = '#f59e0b';
                                                    } else {
                                                        $count_class = 'low';
                                                        $count_color = '#6b7280';
                                                    }
                                                    
                                                    // Calculate page number for pagination (10 items per page by default)
                                                    $page_number = ceil($counter / 10);
                                                ?>
                                                    <tr class="course-row" data-page="<?php echo $page_number; ?>" style="border-bottom: 1px solid #e5e7eb; transition: background 0.2s ease; <?php echo $counter > 10 ? 'display: none;' : ''; ?>" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">
                                                        <td style="padding: 18px 20px; color: #9ca3af; font-weight: 600;"><?php echo $counter++; ?></td>
                                                        <td style="padding: 18px 20px;">
                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                <span style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($course->fullname); ?></span>
                                                                <span style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($course->shortname); ?></span>
                                                            </div>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center;">
                                                            <div style="font-size: 1.3rem; font-weight: 700; color: <?php echo $count_color; ?>;">
                                                                <?php echo $course->total_students; ?>
                                                            </div>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center; color: #10b981; font-weight: 600;">
                                                            <?php echo $course->completed_students; ?>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center; color: #3b82f6; font-weight: 600;">
                                                            <?php echo $course->in_progress_students; ?>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center; color: #ef4444; font-weight: 600;">
                                                            <?php echo $course->not_started_students; ?>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center;">
                                                            <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                                                                <span style="font-weight: 600; color: #1f2937;"><?php echo $completion_rate; ?>%</span>
                                                                <div style="width: 80px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                                                    <div style="height: 100%; background: linear-gradient(90deg, #10b981, #3b82f6); width: <?php echo $completion_rate; ?>%; transition: width 0.3s ease;"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td style="padding: 18px 20px; text-align: center;">
                                                            <?php if ($course->visible == 1): ?>
                                                                <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: #d1fae5; color: #065f46;">
                                                                    <i class="fa fa-check-circle"></i> Active
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: #fee2e2; color: #991b1b;">
                                                                    <i class="fa fa-eye-slash"></i> Hidden
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- Pagination Controls -->
                                    <?php 
                                    $courses_per_page = 10;
                                    $total_courses = count($enrollment_courses_data);
                                    $total_pages = ceil($total_courses / $courses_per_page);
                                    if ($total_pages > 1): 
                                    ?>
                                    <div id="coursesPaginationControls" style="display: flex; justify-content: center; align-items: center; gap: 10px; padding: 25px; border-top: 2px solid #e5e7eb; background: #f9fafb;">
                                        <!-- Previous Button -->
                                        <button id="coursesPrevBtn" onclick="changeCoursesPage('prev')" 
                                           style="padding: 10px 16px; background: #f3f4f6; color: #9ca3af; border: 2px solid #e5e7eb; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: not-allowed; transition: all 0.3s; display: flex; align-items: center; gap: 6px;">
                                            <i class="fa fa-chevron-left"></i> Previous
                                        </button>
                                        
                                        <!-- Page Numbers Container -->
                                        <div id="coursesPageNumbers" style="display: flex; gap: 6px;">
                                            <!-- Generated by JavaScript -->
                                        </div>
                                        
                                        <!-- Next Button -->
                                        <button id="coursesNextBtn" onclick="changeCoursesPage('next')" 
                                           style="padding: 10px 16px; background: #ffffff; color: #3b82f6; border: 2px solid #3b82f6; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 6px;"
                                           onmouseover="if(this.style.cursor!='not-allowed'){this.style.background='#3b82f6'; this.style.color='#ffffff';}" 
                                           onmouseout="if(this.style.cursor!='not-allowed'){this.style.background='#ffffff'; this.style.color='#3b82f6';}">
                                            Next <i class="fa fa-chevron-right"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Info Display -->
                                    <div id="coursesInfoDisplay" style="padding: 12px 25px; background: #f0f9ff; border-top: 2px solid #bfdbfe; text-align: center;">
                                        <p style="margin: 0; color: #1e40af; font-weight: 600; font-size: 0.875rem;">
                                            Showing <span id="coursesStart">1</span>-<span id="coursesEnd"><?php echo min($courses_per_page, $total_courses); ?></span> of <span id="coursesTotal"><?php echo $total_courses; ?></span> courses
                                        </p>
                                    </div>
                                    <?php else: ?>
                                    <!-- Show info even for single page -->
                                    <div style="padding: 12px 25px; background: #f0f9ff; border-top: 2px solid #bfdbfe; text-align: center;">
                                        <p style="margin: 0; color: #1e40af; font-weight: 600; font-size: 0.875rem;">
                                            Showing all <?php echo $total_courses; ?> course<?php echo $total_courses != 1 ? 's' : ''; ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 80px 20px; color: #9ca3af;">
                                        <i class="fa fa-inbox" style="font-size: 4rem; margin-bottom: 20px; color: #d1d5db;"></i>
                                        <h3 style="font-size: 1.5rem; font-weight: 600; color: #6b7280; margin-bottom: 10px;">No Courses Found</h3>
                                        <p style="font-size: 1rem; color: #9ca3af;">There are no courses available for this company yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Student Performance List -->
                        <div id="student-performance-list" style="margin-top: 50px;">
                            <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                                <i class="fa fa-list" style="color: #8b5cf6;"></i> Student Performance List
                            </h3>
                            <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">Detailed performance data for all students enrolled in courses.</p>
                            <!-- Search bar for Student Performance List -->
                            <div style="display: flex; align-items: center; gap: 10px; margin: 0 0 15px 0;">
                                <div style="position: relative; flex: 1; max-width: 420px;">
                                    <i class="fa fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                                    <input id="studentPerfSearch" type="text" placeholder="Search students by name, email, or grade..." 
                                        style="width: 100%; padding: 10px 42px 10px 38px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #374151; outline: none;" 
                                        onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#d1d5db'" />
                                    <button id="studentPerfSearchClear" title="Clear" 
                                        style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 8px; color: #6b7280; font-weight: 600; cursor: pointer; display: none;">Clear</button>
                                </div>
                            </div>
                            
                            <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 0.9rem;">
                                        <thead>
                                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                                <th style="padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 200px;">Student Name</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 100px;">Grade Level</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 110px;">Total Courses</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 110px;">Completed</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 140px;">Completion Rate</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Average Grade</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody id="studentPerfTableBody">
                                            <?php 
                                            // Sort students by completion rate (descending)
                                            usort($detailed_students_data, function($a, $b) {
                                                if ($a['completion_rate'] != $b['completion_rate']) {
                                                    return $b['completion_rate'] <=> $a['completion_rate'];
                                                }
                                                return $b['average_grade'] <=> $a['average_grade'];
                                            });
                                            
                                            $student_perf_index = 0;
                                            foreach ($detailed_students_data as $student): 
                                            $student_perf_index++;
                                            ?>
                                            <tr class="student-perf-row" data-page="<?php echo ceil($student_perf_index / 10); ?>" style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; <?php echo $student_perf_index > 10 ? 'display: none;' : ''; ?>" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                <td style="padding: 12px; text-align: left; white-space: nowrap; cursor: pointer;" onclick='openStudentCourseModal(<?php echo json_encode([
                                                    "id" => $student["id"],
                                                    "firstname" => $student["firstname"],
                                                    "lastname" => $student["lastname"],
                                                    "email" => $student["email"],
                                                    "grade_level" => $student["grade_level"],
                                                    "total_courses" => $student["total_courses"],
                                                    "completed" => $student["completed"],
                                                    "completion_rate" => $student["completion_rate"],
                                                    "average_grade" => $student["average_grade"]
                                                ]); ?>)'>
                                                    <div style="font-weight: 600; color: #3b82f6; text-decoration: underline;">
                                                        <?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: #6b7280;">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </div>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 500; white-space: nowrap;">
                                                    <?php echo $student['grade_level'] ? htmlspecialchars($student['grade_level']) : '<span style="color: #9ca3af;">N/A</span>'; ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600; white-space: nowrap;">
                                                    <?php echo $student['total_courses']; ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="padding: 4px 12px; background: #d1fae5; color: #065f46; border-radius: 12px; font-weight: 600; font-size: 0.85rem;">
                                                        <?php echo $student['completed']; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                                        <div style="flex: 1; max-width: 80px; background: #e5e7eb; border-radius: 10px; height: 8px; overflow: hidden;">
                                                            <div style="height: 100%; background: <?php 
                                                                echo $student['completion_rate'] >= 80 ? '#10b981' : 
                                                                     ($student['completion_rate'] >= 60 ? '#3b82f6' : 
                                                                     ($student['completion_rate'] >= 40 ? '#f59e0b' : '#ef4444')); 
                                                            ?>; width: <?php echo $student['completion_rate']; ?>%;"></div>
                                                        </div>
                                                        <span style="font-weight: 600; color: #1f2937; min-width: 40px;">
                                                            <?php echo round($student['completion_rate'], 1); ?>%
                                                        </span>
                                                    </div>
                                                </td>
                                                <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <?php if ($student['average_grade'] > 0): ?>
                                                        <span style="font-weight: 700; font-size: 1.1rem; color: <?php 
                                                            echo $student['average_grade'] >= 80 ? '#10b981' : 
                                                                 ($student['average_grade'] >= 70 ? '#3b82f6' : 
                                                                 ($student['average_grade'] >= 60 ? '#f59e0b' : '#ef4444')); 
                                                        ?>;">
                                                            <?php echo round($student['average_grade'], 1); ?>%
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #9ca3af; font-size: 0.85rem;">No grades</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 0.8rem; white-space: nowrap; <?php 
                                                        echo $student['performance_class'] === 'excellent' ? 'background: #d1fae5; color: #065f46;' :
                                                             ($student['performance_class'] === 'good' ? 'background: #dbeafe; color: #1e40af;' :
                                                             ($student['performance_class'] === 'average' ? 'background: #fef3c7; color: #92400e;' :
                                                             ($student['performance_class'] === 'poor' ? 'background: #fee2e2; color: #991b1b;' : 'background: #f3f4f6; color: #6b7280;')));
                                                    ?>">
                                                        <?php echo htmlspecialchars($student['performance_rating']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination Controls -->
                                <?php 
                                $students_perf_per_page = 10;
                                $total_students_perf = count($detailed_students_data);
                                $total_perf_pages = ceil($total_students_perf / $students_perf_per_page);
                                if ($total_perf_pages > 1): 
                                ?>
                                <div id="studentPerfPaginationControls" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                    <!-- Previous Button -->
                                    <button id="studentPerfPrevBtn" onclick="changeStudentPerfPage('prev')" 
                                       style="padding: 8px 16px; background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: not-allowed; transition: all 0.3s;">
                                        <i class="fa fa-chevron-left"></i> Previous
                                    </button>
                                    
                                    <!-- Page Numbers Container -->
                                    <div id="studentPerfPageNumbers" style="display: flex; gap: 5px;">
                                        <!-- Generated by JavaScript -->
                                    </div>
                                    
                                    <!-- Next Button -->
                                    <button id="studentPerfNextBtn" onclick="changeStudentPerfPage('next')" 
                                       style="padding: 8px 16px; background: #ffffff; color: #3b82f6; border: 1px solid #3b82f6; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.3s;"
                                       onmouseover="if(this.style.cursor!='not-allowed'){this.style.background='#3b82f6'; this.style.color='#ffffff';}" 
                                       onmouseout="if(this.style.cursor!='not-allowed'){this.style.background='#ffffff'; this.style.color='#3b82f6';}">
                                        Next <i class="fa fa-chevron-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <div class="no-data-row">
                            <i class="fa fa-user-graduate" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                            <p>No student performance data available.</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Engagement Report Section -->
                    <?php
                    // Get Student Engagement Data (Login Frequency, Time Spent, Forum Participation)
                    $student_engagement_data = [];
                    
                    if ($company_info) {
                        // Get all students in this company
                        $students = $DB->get_records_sql(
                            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                             FROM {user} u
                             INNER JOIN {company_users} cu ON cu.userid = u.id
                             INNER JOIN {role_assignments} ra ON ra.userid = u.id
                             INNER JOIN {role} r ON r.id = ra.roleid
                             WHERE cu.companyid = ?
                             AND r.shortname = 'student'
                             AND u.deleted = 0
                             AND u.suspended = 0
                             ORDER BY u.lastname, u.firstname",
                            [$company_info->id]
                        );
                        
                        foreach ($students as $student) {
                            // Get login frequency (last 30 days)
                            $login_count = $DB->count_records_sql(
                                "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
                                 FROM {logstore_standard_log}
                                 WHERE userid = ?
                                 AND action = 'loggedin'
                                 AND timecreated > ?",
                                [$student->id, strtotime('-30 days')]
                            );
                            
                            // Get time spent on LMS (estimate based on log entries - last 30 days)
                            $time_logs = $DB->get_records_sql(
                                "SELECT timecreated
                                 FROM {logstore_standard_log}
                                 WHERE userid = ?
                                 AND timecreated > ?
                                 ORDER BY timecreated ASC",
                                [$student->id, strtotime('-30 days')]
                            );
                            
                            $total_time_minutes = 0;
                            $prev_time = null;
                            foreach ($time_logs as $log) {
                                if ($prev_time !== null) {
                                    $diff = $log->timecreated - $prev_time;
                                    // Only count gaps less than 30 minutes as active time
                                    if ($diff > 0 && $diff < 1800) {
                                        $total_time_minutes += $diff / 60;
                                    }
                                }
                                $prev_time = $log->timecreated;
                            }
                            
                            // Get forum participation (posts + replies)
                            $forum_posts = $DB->count_records_sql(
                                "SELECT COUNT(*)
                                 FROM {forum_posts} fp
                                 INNER JOIN {forum_discussions} fd ON fd.id = fp.discussion
                                 INNER JOIN {forum} f ON f.id = fd.forum
                                 INNER JOIN {course} c ON c.id = f.course
                                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                                 WHERE fp.userid = ?
                                 AND cc.companyid = ?
                                 AND fp.created > ?",
                                [$student->id, $company_info->id, strtotime('-30 days')]
                            );
                            
                            // Engagement Score (0-100)
                            // Formula: (login_frequency * 30) + (time_hours * 5) + (forum_posts * 10)
                            $engagement_score = min(100, ($login_count * 3) + (min(10, $total_time_minutes / 60) * 5) + ($forum_posts * 5));
                            
                            $student_engagement_data[] = [
                                'id' => $student->id,
                                'name' => fullname($student),
                                'email' => $student->email,
                                'login_frequency' => $login_count,
                                'time_spent_hours' => round($total_time_minutes / 60, 1),
                                'forum_posts' => $forum_posts,
                                'engagement_score' => round($engagement_score, 1)
                            ];
                        }
                    }
                    ?>
                    
                </div>
            </div>
            
            <!-- Student Course Detail Modal -->
            <div id="studentCourseModal" style="display: none; position: fixed; top: 55px; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.85); z-index: 99999; overflow-y: auto; padding: 30px; backdrop-filter: blur(5px);">
                <div style="background: white; max-width: 1400px; margin: 0 auto; border-radius: 20px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4); position: relative; animation: slideDown 0.3s ease;">
                    <!-- Modal Header -->
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 35px 45px; border-radius: 20px 20px 0 0; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h2 id="modalStudentName" style="font-size: 2rem; font-weight: 700; margin: 0;"></h2>
                            <p id="modalStudentInfo" style="font-size: 1rem; opacity: 0.9; margin: 8px 0 0 0;"></p>
                        </div>
                        <button onclick="closeStudentCourseModal()" style="background: rgba(255, 255, 255, 0.2); border: none; width: 45px; height: 45px; border-radius: 50%; color: white; font-size: 26px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'; this.style.transform='rotate(90deg)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'; this.style.transform='rotate(0deg)';">×</button>
                    </div>
                    
                    <!-- Modal Body -->
                    <div style="padding: 45px;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 40px;">
                            
                            <!-- Left: Course List -->
                            <div>
                                <h3 style="font-size: 1.4rem; font-weight: 700; color: #1f2937; margin: 0 0 25px 0; display: flex; align-items: center; gap: 12px;">
                                    <i class="fa fa-book" style="color: #667eea;"></i>
                                    Enrolled Courses
                                </h3>
                                <div id="modalCoursesList" style="display: flex; flex-direction: column; gap: 12px; max-height: 600px; overflow-y: auto; padding-right: 10px;">
                                    <!-- Courses will be loaded here -->
                                </div>
                            </div>
                            
                            <!-- Right: Chart -->
                            <div>
                                <h3 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0 0 25px 0; text-align: center;">
                                    Course Completion Status
                                </h3>
                                <div style="position: relative; width: 100%; max-width: 350px; margin: 0 auto;">
                                    <canvas id="studentCourseChart" style="max-height: 350px;"></canvas>
                                </div>
                                
                                <!-- Legend -->
                                <div style="margin-top: 30px; padding: 25px; background: #f9fafb; border-radius: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 15px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 18px; height: 18px; background: #10b981; border-radius: 4px;"></div>
                                            <span style="font-size: 0.95rem; color: #374151; font-weight: 500;">Completed (<span id="legendCompleted">0</span>)</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 18px; height: 18px; background: #3b82f6; border-radius: 4px;"></div>
                                            <span style="font-size: 0.95rem; color: #374151; font-weight: 500;">Still in progress (<span id="legendInProgress">0</span>)</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 18px; height: 18px; background: #ef4444; border-radius: 4px;"></div>
                                            <span style="font-size: 0.95rem; color: #374151; font-weight: 500;">Not started (<span id="legendNotStarted">0</span>)</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Completion Rate Display -->
                                <div style="margin-top: 25px; text-align: center;">
                                    <div id="modalCompletionRate" style="font-size: 3rem; font-weight: 800; color: #667eea;">0%</div>
                                    <div style="font-size: 1rem; color: #6b7280; font-weight: 600;">Completion Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            </div> <!-- Close studentperf-subtab-overview -->
            
            <!-- Sub-Tab Content: Overview Summary -->
            <div id="studentperf-subtab-overviewsummary" class="studentperf-subtab-content" style="display: none;">
                <?php
                // Calculate overview metrics
                $total_students_overview = count($detailed_students_data);
                $total_enrollments_overview = 0;
                $total_completed_overview = 0;
                $grades_sum_overview = 0;
                $grades_count_overview = 0;
                $inactive_students_overview = 0;
                $seven_days_ago_overview = time() - (7 * 24 * 60 * 60);
                
                foreach ($detailed_students_data as $student) {
                    $total_enrollments_overview += $student['enrolled_courses'];
                    $total_completed_overview += $student['completed_courses'];
                    if ($student['average_grade'] > 0) {
                        $grades_sum_overview += $student['average_grade'];
                        $grades_count_overview++;
                    }
                    // Check if student is inactive (last access more than 7 days ago)
                    $student_record = $DB->get_record('user', ['id' => $student['user_id']], 'lastaccess');
                    if ($student_record && $student_record->lastaccess < $seven_days_ago_overview) {
                        $inactive_students_overview++;
                    }
                }
                
                $avg_completion_overview = $total_enrollments_overview > 0 ? round(($total_completed_overview / $total_enrollments_overview) * 100, 1) : 0;
                $avg_grade_overview = $grades_count_overview > 0 ? round($grades_sum_overview / $grades_count_overview, 1) : 0;
                
                // Get grade distribution
                $grade_distribution = [];
                foreach ($detailed_students_data as $student) {
                    $grade = $student['grade_level'] ?? 'N/A';
                    if (!isset($grade_distribution[$grade])) {
                        $grade_distribution[$grade] = 0;
                    }
                    $grade_distribution[$grade]++;
                }
                ?>
                
                <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                    <i class="fa fa-th-large" style="color: #0ea5e9;"></i> Student Overview Summary
                </h2>
                <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">A quick snapshot of key metrics for all students</p>
                
                <!-- Overview Metrics Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 35px;">
                    <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #3b82f6; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0, 0, 0, 0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'">
                        <div style="font-size: 2.8rem; font-weight: 800; margin-bottom: 10px; line-height: 1; color: #3b82f6;"><?php echo $total_students_overview; ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                            <i class="fa fa-users"></i> Total Students
                        </div>
                    </div>
                    
                    <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #10b981; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0, 0, 0, 0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'">
                        <div style="font-size: 2.8rem; font-weight: 800; margin-bottom: 10px; line-height: 1; color: #10b981;"><?php echo $total_enrollments_overview; ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                            <i class="fa fa-book"></i> Total Courses Enrolled
                        </div>
                    </div>
                    
                    <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #8b5cf6; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0, 0, 0, 0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'">
                        <div style="font-size: 2.8rem; font-weight: 800; margin-bottom: 10px; line-height: 1; color: #8b5cf6;"><?php echo $avg_completion_overview; ?>%</div>
                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                            <i class="fa fa-check-circle"></i> Avg Course Completion
                        </div>
                    </div>
                    
                    <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #f59e0b; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0, 0, 0, 0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'">
                        <div style="font-size: 2.8rem; font-weight: 800; margin-bottom: 10px; line-height: 1; color: #f59e0b;"><?php echo $avg_grade_overview; ?>%</div>
                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                            <i class="fa fa-chart-line"></i> Average Grade
                        </div>
                    </div>
                    
                    <div style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid #ef4444; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0, 0, 0, 0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'">
                        <div style="font-size: 2.8rem; font-weight: 800; margin-bottom: 10px; line-height: 1; color: #ef4444;"><?php echo $inactive_students_overview; ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                            <i class="fa fa-bell-slash"></i> Inactive Students (7+ days)
                        </div>
                    </div>
                </div>
                
                <!-- Charts Section -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                    <!-- Grade Distribution Pie Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-chart-pie" style="color: #3b82f6;"></i> Grade Distribution
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Number of students per grade level</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="gradeDistributionChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Completion vs Incomplete Courses Donut Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-check-circle" style="color: #10b981;"></i> Completion vs Incomplete Courses
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Overall course completion breakdown</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="completionStatusChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Weekly/Monthly Login Activity Trend -->
                <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                    <div style="margin-bottom: 20px;">
                        <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa fa-chart-line" style="color: #0ea5e9;"></i> Weekly/Monthly Login Activity Trend
                        </h3>
                        <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Track student login patterns over time</p>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="overviewLoginTrendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Academic -->
            <div id="studentperf-subtab-academic" class="studentperf-subtab-content" style="display: none;">
                <?php
                // Calculate grade-wise performance for Academic tab using real available data
                $grade_performance = [];
                foreach ($detailed_students_data as $student) {
                    $grade = $student['grade_level'] ?? 'N/A';
                    
                    if (!isset($grade_performance[$grade])) {
                        $grade_performance[$grade] = [
                            'count' => 0,
                            'grades' => [],
                            'activity_scores' => [],
                            'completed' => 0,
                            'total_courses' => 0
                        ];
                    }
                    
                    $grade_performance[$grade]['count']++;
                    
                    // Calculate activity-based academic score
                    $completion_rate = $student['enrolled_courses'] > 0 ? ($student['completed_courses'] / $student['enrolled_courses']) * 100 : 0;
                    
                    // Get quiz attempts for this student
                    $quiz_attempts = $DB->count_records_sql(
                        "SELECT COUNT(*) FROM {quiz_attempts} qa
                         INNER JOIN {quiz} q ON q.id = qa.quiz
                         INNER JOIN {course} c ON c.id = q.course
                         WHERE qa.userid = ?",
                        [$student['user_id']]
                    );
                    
                    // Calculate academic performance score (0-100) based on available data
                    $activity_score = min(100, round(
                        ($completion_rate * 0.5) +  // 50% weight on completion
                        (min($quiz_attempts * 10, 30)) + // Up to 30 points for quiz attempts
                        (min($student['enrolled_courses'] * 5, 20)) // Up to 20 points for enrollments
                    ));
                    
                    // Use actual grade if available, otherwise use activity score
                    $final_score = $student['average_grade'] > 0 ? $student['average_grade'] : $activity_score;
                    
                    $grade_performance[$grade]['grades'][] = $final_score;
                    $grade_performance[$grade]['activity_scores'][] = $activity_score;
                    $grade_performance[$grade]['completed'] += $student['completed_courses'];
                    $grade_performance[$grade]['total_courses'] += $student['enrolled_courses'];
                }
                
                // Calculate grade averages
                foreach ($grade_performance as $grade => $data) {
                    $grade_performance[$grade]['avg_score'] = count($data['grades']) > 0 ? round(array_sum($data['grades']) / count($data['grades']), 1) : 0;
                    $grade_performance[$grade]['highest'] = count($data['grades']) > 0 ? round(max($data['grades']), 1) : 0;
                    $grade_performance[$grade]['lowest'] = count($data['grades']) > 0 ? round(min($data['grades']), 1) : 0;
                    $grade_performance[$grade]['pass_rate'] = $data['total_courses'] > 0 ? round(($data['completed'] / $data['total_courses']) * 100, 1) : 0;
                }
                
                // Get top and bottom performers based on real activity data
                $student_performances = [];
                foreach ($detailed_students_data as $student) {
                    // Calculate performance score
                    $completion_rate = $student['enrolled_courses'] > 0 ? ($student['completed_courses'] / $student['enrolled_courses']) * 100 : 0;
                    
                    $quiz_attempts = $DB->count_records_sql(
                        "SELECT COUNT(*) FROM {quiz_attempts} qa
                         INNER JOIN {quiz} q ON q.id = qa.quiz
                         WHERE qa.userid = ?",
                        [$student['user_id']]
                    );
                    
                    $activity_score = min(100, round(
                        ($completion_rate * 0.5) +
                        (min($quiz_attempts * 10, 30)) +
                        (min($student['enrolled_courses'] * 5, 20))
                    ));
                    
                    $final_score = $student['average_grade'] > 0 ? $student['average_grade'] : $activity_score;
                    
                    // Include ALL students to ensure we have data
                    $student_performances[] = [
                        'name' => $student['name'],
                        'email' => $student['email'],
                        'grade' => $student['grade_level'] ?? 'N/A',
                        'avg_grade' => $final_score,
                        'completed' => $student['completed_courses'],
                        'total' => $student['enrolled_courses'],
                        'quiz_attempts' => $quiz_attempts
                    ];
                }
                
                // Sort by performance score
                usort($student_performances, function($a, $b) {
                    return $b['avg_grade'] <=> $a['avg_grade'];
                });
                
                $top_performers = array_slice($student_performances, 0, 10);
                $bottom_performers = array_reverse(array_slice($student_performances, -10));
                ?>
                
                <h2 style="font-size: 1.5rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                    <i class="fa fa-graduation-cap" style="color: #3b82f6;"></i> Academic Performance Report
                </h2>
                <p style="color: #6b7280; margin-bottom: 20px; font-size: 0.9rem;">Student academic performance across courses and grades</p>
                
                <!-- Performance Calculation Info -->
                <div style="background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%); padding: 15px 20px; border-radius: 10px; margin-bottom: 30px; border-left: 4px solid #3b82f6;">
                    <p style="margin: 0; font-size: 0.85rem; color: #1f2937;">
                        <i class="fa fa-info-circle" style="color: #3b82f6;"></i> 
                        <strong>Performance Score:</strong> Calculated from course completion rate (50%), quiz attempts (30%), and course enrollment activity (20%). Actual grades are displayed when available.
                    </p>
                </div>
                
                <!-- Grade-wise Performance Table -->
                <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); margin-bottom: 25px;">
                    <div style="margin-bottom: 20px;">
                        <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa fa-table" style="color: #3b82f6;"></i> Grade-wise Performance Summary
                        </h3>
                        <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Average marks per grade level</p>
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Grade</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Students</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Performance Score</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Highest</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Lowest</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Completion %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grade_performance as $grade => $data): ?>
                                <tr style="border-bottom: 1px solid #e5e7eb; transition: background 0.2s ease;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                    <td style="padding: 15px; font-weight: 600;"><?php echo htmlspecialchars($grade); ?></td>
                                    <td style="padding: 15px; text-align: center; color: #3b82f6; font-weight: 600;"><?php echo $data['count']; ?></td>
                                    <td style="padding: 15px; text-align: center; font-weight: 600; color: #10b981;"><?php echo $data['avg_score']; ?>%</td>
                                    <td style="padding: 15px; text-align: center; color: #059669;"><?php echo $data['highest']; ?>%</td>
                                    <td style="padding: 15px; text-align: center; color: #ef4444;"><?php echo $data['lowest']; ?>%</td>
                                    <td style="padding: 15px; text-align: center;">
                                        <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; <?php echo $data['pass_rate'] >= 80 ? 'background: #d1fae5; color: #065f46;' : ($data['pass_rate'] >= 60 ? 'background: #fed7aa; color: #92400e;' : 'background: #fee2e2; color: #991b1b;'); ?>">
                                            <?php echo $data['pass_rate']; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Charts Row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                    <!-- Grade Performance Bar Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-chart-bar" style="color: #f59e0b;"></i> Grade Performance Comparison
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Average scores across grade levels</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="gradePerformanceChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Subject-wise Radar Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-bullseye" style="color: #8b5cf6;"></i> Subject-wise Performance Radar
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Average scores across core subjects</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="subjectRadarChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Improvement Trend & Pass/Fail -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-bottom: 25px;">
                    <!-- Improvement Trend Line Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-chart-line" style="color: #10b981;"></i> Improvement Trend (Last 3 Terms)
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Academic progress over terms</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="improvementTrendChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Pass/Fail Pie Chart -->
                    <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <div style="margin-bottom: 20px;">
                            <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                                <i class="fa fa-chart-pie" style="color: #ef4444;"></i> Pass / Fail Ratio
                            </h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Overall pass rate</p>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="passFailChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Top Performers -->
                <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); margin-bottom: 25px;">
                    <div style="margin-bottom: 20px;">
                        <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa fa-trophy" style="color: #f59e0b;"></i> Top 10 Performing Students
                        </h3>
                        <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Students with highest average grades</p>
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">#</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Student Name</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Grade Level</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Performance Score</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Courses Completed</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Total Courses</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (empty($top_performers)):
                            ?>
                                <tr>
                                    <td colspan="6" style="padding: 40px; text-align: center; color: #6b7280;">
                                        <i class="fa fa-info-circle" style="font-size: 2rem; display: block; margin-bottom: 10px; color: #d1d5db;"></i>
                                        <p style="font-size: 1rem; font-weight: 600; margin: 0;">No student data available</p>
                                        <p style="font-size: 0.85rem; margin-top: 5px;">Students will appear here once they have enrolled in courses and completed activities.</p>
                                    </td>
                                </tr>
                            <?php 
                            else:
                                $rank = 1;
                                foreach ($top_performers as $student): 
                            ?>
                                <tr style="border-bottom: 1px solid #e5e7eb; transition: background 0.2s ease;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                    <td style="padding: 15px; font-weight: 600; color: #f59e0b;">#<?php echo $rank++; ?></td>
                                    <td style="padding: 15px;">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($student['email']); ?></div>
                                    </td>
                                    <td style="padding: 15px;"><?php echo htmlspecialchars($student['grade']); ?></td>
                                    <td style="padding: 15px; text-align: center; font-weight: 700; color: <?php echo $student['avg_grade'] >= 70 ? '#10b981' : ($student['avg_grade'] >= 40 ? '#f59e0b' : '#ef4444'); ?>; font-size: 1.1rem;">
                                        <?php echo $student['avg_grade']; ?>
                                    </td>
                                    <td style="padding: 15px; text-align: center; color: #3b82f6; font-weight: 600;">
                                        <?php echo $student['completed']; ?>
                                    </td>
                                    <td style="padding: 15px; text-align: center; color: #6b7280;">
                                        <?php echo $student['total']; ?>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bottom Performers -->
                <div style="background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                    <div style="margin-bottom: 20px;">
                        <h3 style="font-size: 1.2rem; font-weight: 600; color: #1f2937; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa fa-user-clock" style="color: #ef4444;"></i> Bottom 10 Performing Students
                        </h3>
                        <p style="color: #6b7280; font-size: 0.9rem; margin: 0;">Students requiring attention and support</p>
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">#</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Student Name</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Grade Level</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Performance Score</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Courses Completed</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 0.75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (empty($bottom_performers)):
                            ?>
                                <tr>
                                    <td colspan="6" style="padding: 40px; text-align: center; color: #6b7280;">
                                        <i class="fa fa-info-circle" style="font-size: 2rem; display: block; margin-bottom: 10px; color: #d1d5db;"></i>
                                        <p style="font-size: 1rem; font-weight: 600; margin: 0;">No student data available</p>
                                        <p style="font-size: 0.85rem; margin-top: 5px;">Students will appear here once they have enrolled in courses and completed activities.</p>
                                    </td>
                                </tr>
                            <?php 
                            else:
                                $rank = 1;
                                foreach ($bottom_performers as $student): 
                            ?>
                                <tr style="border-bottom: 1px solid #e5e7eb; transition: background 0.2s ease;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                    <td style="padding: 15px; font-weight: 600; color: #9ca3af;">#<?php echo $rank++; ?></td>
                                    <td style="padding: 15px;">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo htmlspecialchars($student['email']); ?></div>
                                    </td>
                                    <td style="padding: 15px;"><?php echo htmlspecialchars($student['grade']); ?></td>
                                    <td style="padding: 15px; text-align: center; font-weight: 700; color: <?php echo $student['avg_grade'] >= 70 ? '#10b981' : ($student['avg_grade'] >= 40 ? '#f59e0b' : '#ef4444'); ?>; font-size: 1.1rem;">
                                        <?php echo $student['avg_grade']; ?>
                                    </td>
                                    <td style="padding: 15px; text-align: center; color: #3b82f6; font-weight: 600;">
                                        <?php echo $student['completed']; ?> / <?php echo $student['total']; ?>
                                    </td>
                                    <td style="padding: 15px; text-align: center;">
                                        <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; <?php echo $student['avg_grade'] >= 70 ? 'background: #d1fae5; color: #065f46;' : ($student['avg_grade'] >= 40 ? 'background: #fed7aa; color: #92400e;' : 'background: #fee2e2; color: #991b1b;'); ?>">
                                            <?php if ($student['avg_grade'] >= 70): ?>
                                                <i class="fa fa-check-circle"></i> Good
                                            <?php elseif ($student['avg_grade'] >= 40): ?>
                                                <i class="fa fa-exclamation-triangle"></i> Needs Improvement
                                            <?php else: ?>
                                                <i class="fa fa-exclamation-circle"></i> Needs Support
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Inactive student report -->
            <div id="studentperf-subtab-inactive" class="studentperf-subtab-content" style="display: none;">
                <?php
                // Get Inactive Students Report (Students with low or no activity)
                $inactive_days_threshold = 7; // Default: 7 days
                $inactive_students_data = [];
                $alert_students_data = []; // Students with critical inactivity (14+ days)
                
                if ($company_info) {
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
                         ORDER BY u.lastaccess ASC",
                        [$company_info->id]
                    );
                    
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
                        $recent_logins = $DB->count_records_sql(
                            "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
                             FROM {logstore_standard_log}
                             WHERE userid = ?
                             AND action = 'loggedin'
                             AND timecreated > ?",
                            [$student->id, strtotime('-30 days')]
                        );
                        
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
                            $activity_level = 'Critical';
                            $alert_level = 'danger';
                            $alert_students_data[] = [
                                'id' => $student->id,
                                'name' => fullname($student),
                                'email' => $student->email,
                                'grade_level' => $student->grade_level ?? 'N/A',
                                'days_inactive' => $days_inactive,
                                'last_access' => $student->lastaccess > 0 ? date('M d, Y', $student->lastaccess) : 'Never',
                                'enrolled_courses' => $enrolled_courses,
                                'recent_logins' => $recent_logins,
                                'quiz_attempts' => $recent_quiz_attempts,
                                'activity_level' => $activity_level
                            ];
                        } elseif ($days_inactive >= 7) {
                            $activity_level = 'Warning';
                            $alert_level = 'warning';
                        } elseif ($days_inactive >= 3) {
                            $activity_level = 'Low Activity';
                            $alert_level = 'info';
                        }
                        
                        // Add to inactive students if inactive for 3+ days or low engagement
                        if ($days_inactive >= 3 || $recent_logins < 3) {
                            $inactive_students_data[] = [
                                'id' => $student->id,
                                'name' => fullname($student),
                                'email' => $student->email,
                                'grade_level' => $student->grade_level ?? 'N/A',
                                'days_inactive' => $days_inactive,
                                'last_access' => $student->lastaccess > 0 ? date('M d, Y g:i A', $student->lastaccess) : 'Never',
                                'enrolled_courses' => $enrolled_courses,
                                'recent_logins' => $recent_logins,
                                'quiz_attempts' => $recent_quiz_attempts,
                                'activity_level' => $activity_level,
                                'alert_level' => $alert_level
                            ];
                        }
                    }
                }
                ?>
                
                <div style="padding-top: 20px;">
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                        <i class="fa fa-exclamation-triangle" style="color: #ef4444;"></i> Inactive Students Report
                    </h3>
                    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">List of students with low or no activity in the last 30 days for <?php echo htmlspecialchars($company_info->name); ?>.</p>
                    
                    <?php if (!empty($inactive_students_data)): ?>
                        <!-- Inactive Students Distribution Chart -->
                        <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; margin-bottom: 30px;">
                            <h4 style="font-size: 1.3rem; font-weight: 700; color: #1f2937; margin: 0 0 25px 0; display: flex; align-items: center; gap: 12px;">
                                <i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Inactive Students Distribution
                            </h4>
                            
                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 40px; align-items: center;">
                                <!-- Left: Bar Chart -->
                                <div style="position: relative; height: 350px;">
                                    <canvas id="inactiveStudentsChart"></canvas>
                                </div>
                                
                                <!-- Right: Statistics Summary -->
                                <div style="display: flex; flex-direction: column; gap: 20px;">
                                    <?php 
                                    $critical_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'danger'; }));
                                    $warning_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'warning'; }));
                                    $low_activity_count = count(array_filter($inactive_students_data, function($s) { return $s['alert_level'] === 'info'; }));
                                    $total_count = count($inactive_students_data);
                                    ?>
                                    
                                    <!-- Total Inactive -->
                                    <div style="background: linear-gradient(135deg, #f3f4f6, #e5e7eb); padding: 20px; border-radius: 10px; border-left: 5px solid #6b7280;">
                                        <div style="font-size: 0.85rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Total Inactive</div>
                                        <div style="font-size: 2.5rem; font-weight: 800; color: #1f2937;"><?php echo $total_count; ?></div>
                                        <div style="font-size: 0.8rem; color: #6b7280; margin-top: 5px;">Students</div>
                                    </div>
                                    
                                    <!-- Critical -->
                                    <div style="background: linear-gradient(135deg, #fee2e2, #fecaca); padding: 15px; border-radius: 8px; border-left: 4px solid #ef4444; display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-size: 0.75rem; color: #7f1d1d; font-weight: 600; text-transform: uppercase;">Critical</div>
                                            <div style="font-size: 0.7rem; color: #991b1b; margin-top: 2px;">14+ days</div>
                                        </div>
                                        <div style="font-size: 2rem; font-weight: 800; color: #991b1b;"><?php echo $critical_count; ?></div>
                                    </div>
                                    
                                    <!-- Warning -->
                                    <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b; display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-size: 0.75rem; color: #78350f; font-weight: 600; text-transform: uppercase;">Warning</div>
                                            <div style="font-size: 0.7rem; color: #92400e; margin-top: 2px;">7-13 days</div>
                                        </div>
                                        <div style="font-size: 2rem; font-weight: 800; color: #92400e;"><?php echo $warning_count; ?></div>
                                    </div>
                                    
                                    <!-- Low Activity -->
                                    <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-size: 0.75rem; color: #1e3a8a; font-weight: 600; text-transform: uppercase;">Low Activity</div>
                                            <div style="font-size: 0.7rem; color: #1e40af; margin-top: 2px;">3-6 days</div>
                                        </div>
                                        <div style="font-size: 2rem; font-weight: 800; color: #1e40af;"><?php echo $low_activity_count; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Chart Info -->
                            <div style="margin-top: 25px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                    <i class="fa fa-info-circle" style="color: #3b82f6;"></i> 
                                    <strong>Chart Overview:</strong> This visualization shows the distribution of inactive students by severity level. The bar chart provides a clear comparison of student inactivity across different time periods, helping identify where intervention is most needed.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Inactive Students Table -->
                        <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0;">
                                    <i class="fa fa-table" style="color: #6b7280;"></i> Inactive Students Details
                                </h4>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-size: 0.85rem; color: #6b7280;">Filter by days:</span>
                                    <select id="inactiveDaysFilter" onchange="filterInactiveStudents(this.value)" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #374151; cursor: pointer;">
                                        <option value="3">3+ days</option>
                                        <option value="7" selected>7+ days</option>
                                        <option value="14">14+ days</option>
                                        <option value="30">30+ days</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                <table style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 0.9rem;">
                                    <thead>
                                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 200px;">Student Name</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 100px;">Grade Level</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Days Inactive</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">Last Access</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Logins (30d)</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Quiz Attempts</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 110px;">Courses</th>
                                            <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="inactiveStudentsTableBody">
                                        <?php foreach ($inactive_students_data as $student): ?>
                                            <tr class="inactive-student-row" data-days-inactive="<?php echo $student['days_inactive']; ?>" style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                <td style="padding: 12px; text-align: left;">
                                                    <div style="font-weight: 600; color: #1f2937;">
                                                        <?php echo htmlspecialchars($student['name']); ?>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: #6b7280;">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </div>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 500;">
                                                    <?php echo htmlspecialchars($student['grade_level']); ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: 1rem; <?php 
                                                        if ($student['days_inactive'] >= 14) {
                                                            echo 'background: #fee2e2; color: #991b1b;';
                                                        } elseif ($student['days_inactive'] >= 7) {
                                                            echo 'background: #fef3c7; color: #92400e;';
                                                        } else {
                                                            echo 'background: #dbeafe; color: #1e40af;';
                                                        }
                                                    ?>">
                                                        <?php echo $student['days_inactive'] < 999 ? $student['days_inactive'] : 'Never'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #6b7280; font-size: 0.85rem;">
                                                    <?php echo $student['last_access']; ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 6px 12px; background: <?php echo $student['recent_logins'] > 5 ? '#d1fae5' : ($student['recent_logins'] > 2 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $student['recent_logins'] > 5 ? '#065f46' : ($student['recent_logins'] > 2 ? '#92400e' : '#991b1b'); ?>; border-radius: 8px; font-weight: 600;">
                                                        <?php echo $student['recent_logins']; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 6px 12px; background: <?php echo $student['quiz_attempts'] > 3 ? '#d1fae5' : ($student['quiz_attempts'] > 0 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $student['quiz_attempts'] > 3 ? '#065f46' : ($student['quiz_attempts'] > 0 ? '#92400e' : '#991b1b'); ?>; border-radius: 8px; font-weight: 600;">
                                                        <?php echo $student['quiz_attempts']; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600;">
                                                    <?php echo $student['enrolled_courses']; ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 6px 14px; border-radius: 20px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; <?php 
                                                        if ($student['alert_level'] === 'danger') {
                                                            echo 'background: #ef4444; color: white;';
                                                        } elseif ($student['alert_level'] === 'warning') {
                                                            echo 'background: #f59e0b; color: white;';
                                                        } else {
                                                            echo 'background: #3b82f6; color: white;';
                                                        }
                                                    ?>">
                                                        <?php echo $student['activity_level']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #ef4444;">
                                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                    <strong>Activity Levels:</strong> <span style="color: #991b1b;">Critical (14+ days)</span> • <span style="color: #92400e;">Warning (7-13 days)</span> • <span style="color: #1e40af;">Low Activity (3-6 days)</span>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 2px solid #10b981; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);">
                            <div style="width: 80px; height: 80px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);">
                                <i class="fa fa-check-circle" style="font-size: 2.5rem; color: white;"></i>
                            </div>
                            <h4 style="font-size: 1.5rem; font-weight: 700; color: #065f46; margin: 0 0 10px 0;">
                                All Students Active!
                            </h4>
                            <p style="font-size: 1rem; color: #047857; margin: 0;">
                                No inactive students detected. All students are actively engaged with the LMS.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Attendance -->
            <div id="studentperf-subtab-attendance" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #d1fae5 0%, #dbeafe 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-calendar-check" style="font-size: 4rem; color: #10b981; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Attendance Report</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Comprehensive attendance tracking with daily, weekly, and monthly views. Monitor student presence, absences, and punctuality patterns.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #10b981; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Engagement -->
            <div id="studentperf-subtab-engagement" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-comments" style="font-size: 4rem; color: #f59e0b; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Engagement Analysis</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Track student participation in discussions, forum posts, assignment submissions, and overall platform interaction metrics.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #f59e0b; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Progress -->
            <div id="studentperf-subtab-progress" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-chart-line" style="font-size: 4rem; color: #3b82f6; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Progress Tracking</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Visualize student progress over time with trend analysis, milestone achievements, and learning curve insights.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #3b82f6; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Comparison -->
            <div id="studentperf-subtab-comparison" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-balance-scale" style="font-size: 4rem; color: #ec4899; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Performance Comparison</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Compare student performance metrics across different cohorts, classes, and time periods to identify patterns and trends.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #ec4899; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Alerts -->
            <div id="studentperf-subtab-alerts" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-exclamation-triangle" style="font-size: 4rem; color: #ef4444; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Performance Alerts</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Receive automated alerts for students at risk, low performance indicators, and required interventions.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #ef4444; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            <!-- Sub-Tab Content: Dashboard -->
            <div id="studentperf-subtab-dashboard" class="studentperf-subtab-content" style="display: none;">
                <div style="text-align: center; padding: 80px 40px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <i class="fa fa-tachometer-alt" style="font-size: 4rem; color: #10b981; margin-bottom: 20px; display: block;"></i>
                    <h4 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 12px;">Performance Dashboard</h4>
                    <p style="color: #6b7280; font-size: 1rem; max-width: 600px; margin: 0 auto;">
                        Comprehensive dashboard with real-time metrics, KPIs, and visual analytics for quick decision-making.
                    </p>
                    <div style="margin-top: 25px; display: inline-block; padding: 12px 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <span style="color: #10b981; font-weight: 600;"><i class="fa fa-info-circle"></i> Coming Soon</span>
                    </div>
                </div>
            </div>
            
            </div> <!-- Close report-table-container -->
            </div> <!-- Close tab-studentperformance -->
            
            <style>
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            /* Custom scrollbar for course list */
            #modalCoursesList::-webkit-scrollbar {
                width: 8px;
            }
            
            #modalCoursesList::-webkit-scrollbar-track {
                background: #f1f5f9;
                border-radius: 4px;
            }
            
            #modalCoursesList::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 4px;
            }
            
            #modalCoursesList::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
            </style>
            
            <!-- Tab Content: Teacher Report -->
            <div id="tab-teacherload" class="tab-content">
                <div class="report-table-container">
                    <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                        <i class="fa fa-chalkboard-teacher" style="color: #8b5cf6;"></i> Teacher Summary
                    </h3>
                    <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">View the distribution of teachers by course assignments in <?php echo htmlspecialchars($company_info->name); ?>.</p>
                    
                    <?php if ($teacher_load_distribution['total'] > 0): ?>
                        <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 35px; align-items: center;">
                            <!-- Left: Donut Chart Area (60% Space, Normal Chart Size) -->
                            <div style="display: flex; justify-content: center; align-items: center; background: #ffffff; padding: 40px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); position: relative; transition: all 0.3s ease;">
                                <div style="position: relative; width: 340px; height: 340px;">
                                    <canvas id="teacherLoadChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Right: Statistics (Compact - 40%) -->
                            <div>
                                <div style="display: flex; flex-direction: column; gap: 14px;">
                                    <div style="padding: 14px 18px; background: #f9fafb; border-radius: 11px; text-align: center; border-left: 4px solid #9ca3af;">
                                        <div style="font-size: 1.8rem; font-weight: 800; color: #9ca3af; line-height: 1; margin-bottom: 5px;"><?php echo $teacher_load_distribution['no_load']; ?></div>
                                        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">0 Courses (Not Assigned)</div>
                                        <div style="font-size: 0.65rem; color: #9ca3af; margin-top: 4px; font-weight: 500;">
                                            <?php echo $teacher_load_distribution['total'] > 0 ? round(($teacher_load_distribution['no_load'] / $teacher_load_distribution['total']) * 100, 1) : 0; ?>% of teachers
                                        </div>
                                    </div>
                                    
                                    <div style="padding: 14px 18px; background: #f0fdf4; border-radius: 11px; text-align: center; border-left: 4px solid #10b981;">
                                        <div style="font-size: 1.8rem; font-weight: 800; color: #10b981; line-height: 1; margin-bottom: 5px;"><?php echo $teacher_load_distribution['low_load']; ?></div>
                                        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">1-2 Courses</div>
                                        <div style="font-size: 0.65rem; color: #059669; margin-top: 4px; font-weight: 500;">
                                            <?php echo $teacher_load_distribution['total'] > 0 ? round(($teacher_load_distribution['low_load'] / $teacher_load_distribution['total']) * 100, 1) : 0; ?>% of teachers
                                        </div>
                                    </div>
                                    
                                    <div style="padding: 14px 18px; background: #fefce8; border-radius: 11px; text-align: center; border-left: 4px solid #eab308;">
                                        <div style="font-size: 1.8rem; font-weight: 800; color: #eab308; line-height: 1; margin-bottom: 5px;"><?php echo $teacher_load_distribution['medium_load']; ?></div>
                                        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">3-5 Courses</div>
                                        <div style="font-size: 0.65rem; color: #ca8a04; margin-top: 4px; font-weight: 500;">
                                            <?php echo $teacher_load_distribution['total'] > 0 ? round(($teacher_load_distribution['medium_load'] / $teacher_load_distribution['total']) * 100, 1) : 0; ?>% of teachers
                                        </div>
                                    </div>
                                    
                                    <div style="padding: 14px 18px; background: #fef3f2; border-radius: 11px; text-align: center; border-left: 4px solid #ef4444;">
                                        <div style="font-size: 1.8rem; font-weight: 800; color: #ef4444; line-height: 1; margin-bottom: 5px;"><?php echo $teacher_load_distribution['high_load']; ?></div>
                                        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">More than 5 Courses</div>
                                        <div style="font-size: 0.65rem; color: #dc2626; margin-top: 4px; font-weight: 500;">
                                            <?php echo $teacher_load_distribution['total'] > 0 ? round(($teacher_load_distribution['high_load'] / $teacher_load_distribution['total']) * 100, 1) : 0; ?>% of teachers
                                        </div>
                                    </div>
                                    
                                    <div style="padding: 16px 20px; background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); border-radius: 12px; text-align: center; border: 2px solid #8b5cf6; margin-top: 4px; box-shadow: 0 4px 14px rgba(139, 92, 246, 0.18);">
                                        <div style="font-size: 2rem; font-weight: 800; color: #6d28d9; line-height: 1; margin-bottom: 5px;"><?php echo $teacher_load_distribution['total']; ?></div>
                                        <div style="font-size: 0.75rem; color: #5b21b6; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Total Teachers</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data-row">
                            <i class="fa fa-chalkboard-teacher" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                            <p>No teachers found in your school.</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Teacher Performance Section -->
                    <div style="margin-top: 50px;">
                        <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                            <i class="fa fa-star" style="color: #f59e0b;"></i> Teacher Performance
                        </h3>
                        <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">View teacher performance metrics based on courses taught, completion rates, and student engagement in <?php echo htmlspecialchars($company_info->name); ?>.</p>
                        
                        <?php if (!empty($teacher_performance_data)): ?>
                            <div class="scrollable-chart-container" style="background: transparent; padding: 30px 0; overflow-x: auto; overflow-y: hidden;">
                                <div style="position: relative; height: 550px; width: <?php echo count($teacher_performance_data) * 140; ?>px;">
                                    <canvas id="teacherPerformanceChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Performance Metrics Table -->
                            <div style="margin-top: 30px; background: white; border-radius: 12px; padding: 25px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h4 style="font-size: 1.1rem; font-weight: 600; color: #1f2937; margin: 0;">
                                    <i class="fa fa-table" style="color: #6b7280;"></i> Performance Breakdown
                                </h4>
                                    <div id="teacherPaginationInfo" style="font-size: 0.85rem; color: #6b7280;">
                                        Showing 1 - <?php echo min(12, count($teacher_performance_data)); ?> of <?php echo count($teacher_performance_data); ?> teachers
                                    </div>
                                </div>
                                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table style="width: 100%; min-width: 1200px; border-collapse: collapse; font-size: 0.9rem;">
                                    <thead>
                                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                                <th style="padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 200px;">Teacher Name</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Courses Taught</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Total Students</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 110px;">Completed</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 140px;">Completion Rate</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">Avg Student Grade</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 140px;">Avg Quiz Score</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 160px;">Performance Score</th>
                                        </tr>
                                    </thead>
                                        <tbody id="teacherTableBody">
                                            <?php 
                                            $teacher_index = 0;
                                            foreach ($teacher_performance_data as $teacher): 
                                            $teacher_index++;
                                            ?>
                                                <tr class="teacher-row" data-page="<?php echo ceil($teacher_index / 12); ?>" style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; <?php echo $teacher_index > 12 ? 'display: none;' : ''; ?>" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                    <td style="padding: 12px; text-align: left; white-space: nowrap;"><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                                    <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600; white-space: nowrap;"><?php echo $teacher['courses_taught']; ?></td>
                                                    <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600; white-space: nowrap;"><?php echo $teacher['total_students']; ?></td>
                                                    <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600; white-space: nowrap;"><?php echo $teacher['completed_students']; ?></td>
                                                    <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="color: <?php echo $teacher['completion_rate'] >= 70 ? '#10b981' : ($teacher['completion_rate'] >= 50 ? '#f59e0b' : '#ef4444'); ?>; font-weight: 600;">
                                                        <?php echo $teacher['completion_rate']; ?>%
                                                    </span>
                                                </td>
                                                    <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="color: <?php echo $teacher['avg_student_grade'] >= 70 ? '#10b981' : ($teacher['avg_student_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>; font-weight: 600;">
                                                        <?php echo $teacher['avg_student_grade']; ?>%
                                                    </span>
                                                </td>
                                                    <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="color: <?php echo $teacher['avg_quiz_score'] >= 70 ? '#10b981' : ($teacher['avg_quiz_score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>; font-weight: 600;">
                                                        <?php echo $teacher['avg_quiz_score']; ?>%
                                                    </span>
                                                </td>
                                                    <td style="padding: 12px; text-align: center; white-space: nowrap;">
                                                    <span style="color: <?php echo $teacher['performance_score'] >= 70 ? '#10b981' : ($teacher['performance_score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>; font-weight: 700; font-size: 1.1rem;">
                                                        <?php echo $teacher['performance_score']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                
                                <!-- Pagination Controls -->
                                <?php 
                                $teachers_per_page = 12;
                                $total_teachers = count($teacher_performance_data);
                                $total_teacher_pages = ceil($total_teachers / $teachers_per_page);
                                if ($total_teacher_pages > 1): 
                                ?>
                                <div id="teacherPaginationControls" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                    <!-- Previous Button -->
                                    <button id="teacherPrevBtn" onclick="changeTeacherPage('prev')" 
                                       style="padding: 8px 16px; background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: not-allowed; transition: all 0.3s;">
                                        <i class="fa fa-chevron-left"></i> Previous
                                    </button>
                                    
                                    <!-- Page Numbers Container -->
                                    <div id="teacherPageNumbers" style="display: flex; gap: 5px;">
                                        <!-- Generated by JavaScript -->
                                    </div>
                                    
                                    <!-- Next Button -->
                                    <button id="teacherNextBtn" onclick="changeTeacherPage('next')" 
                                       style="padding: 8px 16px; background: #ffffff; color: #3b82f6; border: 1px solid #3b82f6; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.3s;"
                                       onmouseover="if(this.style.cursor!='not-allowed'){this.style.background='#3b82f6'; this.style.color='#ffffff';}" 
                                       onmouseout="if(this.style.cursor!='not-allowed'){this.style.background='#ffffff'; this.style.color='#3b82f6';}">
                                        Next <i class="fa fa-chevron-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                    <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                        <strong>Performance Score Calculation:</strong> Based on courses taught (30%), student completion rate (40%), and student engagement (30%). Maximum score: 100 points.
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-data-row">
                                <i class="fa fa-star" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                                <p>No teacher performance data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Teacher Activity Report Section -->
                    <div style="margin-top: 50px;">
                        <h3 style="font-size: 1.3rem; font-weight: 600; color: #1f2937; margin-bottom: 10px;">
                            <i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Teacher Activity Report
                        </h3>
                        <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.9rem;">Tracks number of courses managed, activities created, and grading done by each teacher in <?php echo htmlspecialchars($company_info->name); ?>.</p>
                        
                        <?php if (!empty($teacher_activity_data)): ?>
                            <!-- Summary Statistics Cards -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 40px;">
                                <?php
                                $total_courses_managed = array_sum(array_column($teacher_activity_data, 'courses_managed'));
                                $total_activities_created = array_sum(array_column($teacher_activity_data, 'activities_created'));
                                $total_grading_done = array_sum(array_column($teacher_activity_data, 'grading_done'));
                                $avg_courses_per_teacher = count($teacher_activity_data) > 0 ? round($total_courses_managed / count($teacher_activity_data), 1) : 0;
                                $avg_activities_per_teacher = count($teacher_activity_data) > 0 ? round($total_activities_created / count($teacher_activity_data), 1) : 0;
                                $avg_grading_per_teacher = count($teacher_activity_data) > 0 ? round($total_grading_done / count($teacher_activity_data), 1) : 0;
                                ?>
                                
                                <div style="background: linear-gradient(135deg, #3b82f615, #2563eb15); padding: 25px; border-radius: 12px; border: 1px solid #3b82f630; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                                            <i class="fa fa-book" style="color: white; font-size: 1.4rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Total Courses</div>
                                            <div style="font-size: 2.2rem; font-weight: 800; color: #3b82f6; line-height: 1;"><?php echo $total_courses_managed; ?></div>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 8px;">
                                        <i class="fa fa-chart-line" style="color: #3b82f6;"></i> <?php echo $avg_courses_per_teacher; ?> per teacher
                                    </div>
                                </div>
                                
                                <div style="background: linear-gradient(135deg, #10b98115, #05966915); padding: 25px; border-radius: 12px; border: 1px solid #10b98130; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1);">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                                            <i class="fa fa-tasks" style="color: white; font-size: 1.4rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Total Activities</div>
                                            <div style="font-size: 2.2rem; font-weight: 800; color: #10b981; line-height: 1;"><?php echo $total_activities_created; ?></div>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 8px;">
                                        <i class="fa fa-chart-line" style="color: #10b981;"></i> <?php echo $avg_activities_per_teacher; ?> per teacher
                                    </div>
                                </div>
                                
                                <div style="background: linear-gradient(135deg, #f59e0b15, #d9790615); padding: 25px; border-radius: 12px; border: 1px solid #f59e0b30; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.1);">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #f59e0b, #d97906); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);">
                                            <i class="fa fa-check-circle" style="color: white; font-size: 1.4rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Total Gradings</div>
                                            <div style="font-size: 2.2rem; font-weight: 800; color: #f59e0b; line-height: 1;"><?php echo $total_grading_done; ?></div>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 8px;">
                                        <i class="fa fa-chart-line" style="color: #f59e0b;"></i> <?php echo $avg_grading_per_teacher; ?> per teacher
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bar Chart Visualization -->
                            <div style="background: white; border-radius: 12px; padding: 30px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); margin-bottom: 30px;">
                                <h4 style="font-size: 1.1rem; font-weight: 600; color: #1f2937; margin: 0 0 25px 0;">
                                    <i class="fa fa-chart-bar" style="color: #6b7280;"></i> Teacher Activity Comparison
                                </h4>
                                <div class="scrollable-chart-container" style="background: transparent; padding: 0; overflow-x: auto; overflow-y: hidden;">
                                    <div style="position: relative; height: 500px; min-width: <?php echo max(900, count($teacher_activity_data) * 80); ?>px;">
                                        <canvas id="teacherActivityChart"></canvas>
                                    </div>
                                </div>
                                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                    <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                        <strong>Note:</strong> The bar chart shows three key metrics for each teacher: Courses Managed (blue), Activities Created (green), and Grading Done (orange). Hover over the bars for detailed information.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Detailed Teacher Activity Table -->
                            <div style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h4 style="font-size: 1.1rem; font-weight: 600; color: #1f2937; margin: 0;">
                                        <i class="fa fa-table" style="color: #6b7280;"></i> Detailed Activity Breakdown
                                    </h4>
                                    <div id="activityPaginationInfo" style="font-size: 0.85rem; color: #6b7280;">
                                        Showing 1 - <?php echo min(10, count($teacher_activity_data)); ?> of <?php echo count($teacher_activity_data); ?> teachers
                                    </div>
                                </div>
                                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table style="width: 100%; min-width: 700px; border-collapse: collapse; font-size: 0.9rem;">
                                        <thead>
                                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                                <th style="padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 200px;">Teacher Name</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">
                                                    <i class="fa fa-book" style="color: #3b82f6;"></i> Courses Managed
                                                </th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">
                                                    <i class="fa fa-tasks" style="color: #10b981;"></i> Activities Created
                                                </th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">
                                                    <i class="fa fa-check-circle" style="color: #f59e0b;"></i> Grading Done
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody id="activityTableBody">
                                            <?php 
                                            $activity_index = 0;
                                            foreach ($teacher_activity_data as $teacher): 
                                            $activity_index++;
                                            ?>
                                                <tr class="activity-row" data-page="<?php echo ceil($activity_index / 10); ?>" style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; <?php echo $activity_index > 10 ? 'display: none;' : ''; ?>" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                    <td style="padding: 12px; text-align: left;">
                                                        <strong style="color: #1f2937;"><?php echo htmlspecialchars($teacher['name']); ?></strong>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; background: linear-gradient(135deg, #3b82f615, #2563eb15); color: #3b82f6; font-weight: 700; font-size: 1.1rem; padding: 8px 16px; border-radius: 8px; min-width: 50px;">
                                                            <?php echo $teacher['courses_managed']; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; background: linear-gradient(135deg, #10b98115, #05966915); color: #10b981; font-weight: 700; font-size: 1.1rem; padding: 8px 16px; border-radius: 8px; min-width: 50px;">
                                                            <?php echo $teacher['activities_created']; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; background: linear-gradient(135deg, #f59e0b15, #d9790615); color: #f59e0b; font-weight: 700; font-size: 1.1rem; padding: 8px 16px; border-radius: 8px; min-width: 50px;">
                                                            <?php echo $teacher['grading_done']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination Controls for Activity Table -->
                                <?php 
                                $activities_per_page = 10;
                                $total_activity_records = count($teacher_activity_data);
                                $total_activity_pages = ceil($total_activity_records / $activities_per_page);
                                if ($total_activity_pages > 1): 
                                ?>
                                <div id="activityPaginationControls" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                    <button id="activityPrevBtn" onclick="changeActivityPage('prev')" 
                                       style="padding: 8px 16px; background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: not-allowed; transition: all 0.3s;">
                                        <i class="fa fa-chevron-left"></i> Previous
                                    </button>
                                    
                                    <div id="activityPageNumbers" style="display: flex; gap: 5px;">
                                        <!-- Generated by JavaScript -->
                                    </div>
                                    
                                    <button id="activityNextBtn" onclick="changeActivityPage('next')" 
                                       style="padding: 8px 16px; background: #ffffff; color: #3b82f6; border: 1px solid #3b82f6; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.3s;"
                                       onmouseover="if(this.style.cursor!='not-allowed'){this.style.background='#3b82f6'; this.style.color='#ffffff';}" 
                                       onmouseout="if(this.style.cursor!='not-allowed'){this.style.background='#ffffff'; this.style.color='#3b82f6';}">
                                        Next <i class="fa fa-chevron-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data-row">
                                <i class="fa fa-chart-bar" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                                <p>No teacher activity data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Teacher Performance Report Section -->
                    <?php
                    // Get Teacher Performance Data (Average Student Results and Feedback)
                    $teacher_performance_report = [];
                    
                    if ($company_info) {
                        // Get all teachers in this company
                        $teachers = $DB->get_records_sql(
                            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                             FROM {user} u
                             INNER JOIN {company_users} cu ON cu.userid = u.id
                             INNER JOIN {role_assignments} ra ON ra.userid = u.id
                             INNER JOIN {role} r ON r.id = ra.roleid
                             WHERE cu.companyid = ?
                             AND r.shortname IN ('teacher', 'editingteacher')
                             AND u.deleted = 0
                             AND u.suspended = 0
                             ORDER BY u.lastname, u.firstname",
                            [$company_info->id]
                        );
                        
                        foreach ($teachers as $teacher) {
                            // Get courses taught by this teacher
                            $courses = $DB->get_records_sql(
                                "SELECT DISTINCT c.id
                                 FROM {course} c
                                 INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                                 INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                 INNER JOIN {role} r ON r.id = ra.roleid
                                 INNER JOIN {company_course} cc ON cc.courseid = c.id
                                 WHERE ra.userid = ?
                                 AND r.shortname IN ('teacher', 'editingteacher')
                                 AND cc.companyid = ?
                                 AND c.visible = 1
                                 AND c.id > 1",
                                [$teacher->id, $company_info->id]
                            );
                            
                            if (empty($courses)) {
                                continue; // Skip teachers with no courses
                            }
                            
                            $course_ids = array_keys($courses);
                            list($insql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
                            $params['companyid'] = $company_info->id;
                            
                            // Get average quiz grades for students in teacher's courses
                            $avg_quiz_grade = $DB->get_record_sql(
                                "SELECT AVG((qa.sumgrades / q.sumgrades) * 100) as avg_grade
                                 FROM {quiz_attempts} qa
                                 INNER JOIN {quiz} q ON q.id = qa.quiz
                                 INNER JOIN {user} u ON u.id = qa.userid
                                 INNER JOIN {company_users} cu ON cu.userid = u.id
                                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = q.course
                                 INNER JOIN {role} r ON r.id = ra.roleid
                                 WHERE q.course $insql
                                 AND cu.companyid = :companyid
                                 AND qa.state = 'finished'
                                 AND r.shortname = 'student'
                                 AND q.sumgrades > 0",
                                $params
                            );
                            
                            // Get average assignment grades
                            $avg_assignment_grade = $DB->get_record_sql(
                                "SELECT AVG((ag.grade / a.grade) * 100) as avg_grade
                                 FROM {assign_grades} ag
                                 INNER JOIN {assign} a ON a.id = ag.assignment
                                 INNER JOIN {user} u ON u.id = ag.userid
                                 INNER JOIN {company_users} cu ON cu.userid = u.id
                                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = a.course
                                 INNER JOIN {role} r ON r.id = ra.roleid
                                 WHERE a.course $insql
                                 AND cu.companyid = :companyid
                                 AND ag.grade IS NOT NULL
                                 AND ag.grade >= 0
                                 AND a.grade > 0
                                 AND r.shortname = 'student'",
                                $params
                            );
                            
                            // Get student completion rate
                            $completion_stats = $DB->get_record_sql(
                                "SELECT COUNT(DISTINCT u.id) as total_students,
                                        COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN u.id END) as completed_students
                                 FROM {user} u
                                 INNER JOIN {company_users} cu ON cu.userid = u.id
                                 INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                                 INNER JOIN {enrol} e ON e.id = ue.enrolid
                                 INNER JOIN {role_assignments} ra ON ra.userid = u.id
                                 INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.instanceid = e.courseid
                                 INNER JOIN {role} r ON r.id = ra.roleid
                                 LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = e.courseid
                                 WHERE e.courseid $insql
                                 AND cu.companyid = :companyid
                                 AND r.shortname = 'student'
                                 AND u.deleted = 0
                                 AND u.suspended = 0",
                                $params
                            );
                            
                            // Get student engagement (average logins per student)
                            $avg_engagement = $DB->get_record_sql(
                                "SELECT AVG(login_count) as avg_logins
                                 FROM (
                                     SELECT u.id, COUNT(DISTINCT DATE(FROM_UNIXTIME(l.timecreated))) as login_count
                                     FROM {user} u
                                     INNER JOIN {company_users} cu ON cu.userid = u.id
                                     INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                                     INNER JOIN {enrol} e ON e.id = ue.enrolid
                                     INNER JOIN {role_assignments} ra ON ra.userid = u.id
                                     INNER JOIN {role} r ON r.id = ra.roleid
                                     LEFT JOIN {logstore_standard_log} l ON l.userid = u.id AND l.action = 'loggedin' AND l.timecreated > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
                                     WHERE e.courseid $insql
                                     AND cu.companyid = :companyid
                                     AND r.shortname = 'student'
                                     AND u.deleted = 0
                                     GROUP BY u.id
                                 ) as subquery",
                                $params
                            );
                            
                            // Calculate metrics
                            $quiz_avg = $avg_quiz_grade && $avg_quiz_grade->avg_grade ? round($avg_quiz_grade->avg_grade, 1) : 0;
                            $assignment_avg = $avg_assignment_grade && $avg_assignment_grade->avg_grade ? round($avg_assignment_grade->avg_grade, 1) : 0;
                            
                            $overall_avg_grade = 0;
                            $count = 0;
                            if ($quiz_avg > 0) { $overall_avg_grade += $quiz_avg; $count++; }
                            if ($assignment_avg > 0) { $overall_avg_grade += $assignment_avg; $count++; }
                            $overall_avg_grade = $count > 0 ? round($overall_avg_grade / $count, 1) : 0;
                            
                            $total_students = $completion_stats ? $completion_stats->total_students : 0;
                            $completed_students = $completion_stats ? $completion_stats->completed_students : 0;
                            $completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 1) : 0;
                            $engagement = $avg_engagement && $avg_engagement->avg_logins ? round($avg_engagement->avg_logins, 1) : 0;
                            
                            // Performance score (0-100)
                            $performance_score = round(
                                ($overall_avg_grade * 0.4) + // 40% weight on grades
                                ($completion_rate * 0.3) + // 30% weight on completion
                                (min($engagement * 3, 30)) // 30% weight on engagement (max 10 logins = 30 points)
                            , 1);
                            
                            // Only add teachers with student data
                            if ($total_students > 0 || $overall_avg_grade > 0) {
                                $teacher_performance_report[] = [
                                    'id' => $teacher->id,
                                    'name' => fullname($teacher),
                                    'email' => $teacher->email,
                                    'avg_quiz_grade' => $quiz_avg,
                                    'avg_assignment_grade' => $assignment_avg,
                                    'overall_avg_grade' => $overall_avg_grade,
                                    'total_students' => $total_students,
                                    'completion_rate' => $completion_rate,
                                    'engagement' => $engagement,
                                    'performance_score' => $performance_score
                                ];
                            }
                        }
                    }
                    ?>
                    
                    <div style="margin-top: 60px; padding-top: 40px; border-top: 3px solid #e5e7eb;">
                        <h3 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                            <i class="fa fa-user-tie" style="color: #8b5cf6;"></i> Teacher Performance Report
                        </h3>
                        <p style="color: #6b7280; margin-bottom: 30px; font-size: 0.95rem;">Shows average student results and feedback per teacher in <?php echo htmlspecialchars($company_info->name); ?>.</p>
                        
                        <?php if (!empty($teacher_performance_report)): ?>
                            <!-- Summary Cards -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px;">
                                <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 25px; border-radius: 12px; text-align: center; border-left: 4px solid #3b82f6; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);">
                                    <div style="font-size: 2.5rem; font-weight: 800; color: #1e40af; margin-bottom: 8px;">
                                        <?php 
                                        $avg_overall = count($teacher_performance_report) > 0 ? round(array_sum(array_column($teacher_performance_report, 'overall_avg_grade')) / count($teacher_performance_report), 1) : 0;
                                        echo $avg_overall . '%'; 
                                        ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #1e3a8a; font-weight: 600; text-transform: uppercase;">Avg Student Grade</div>
                                </div>
                                
                                <div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); padding: 25px; border-radius: 12px; text-align: center; border-left: 4px solid #10b981; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);">
                                    <div style="font-size: 2.5rem; font-weight: 800; color: #047857; margin-bottom: 8px;">
                                        <?php 
                                        $avg_completion = count($teacher_performance_report) > 0 ? round(array_sum(array_column($teacher_performance_report, 'completion_rate')) / count($teacher_performance_report), 1) : 0;
                                        echo $avg_completion . '%'; 
                                        ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #065f46; font-weight: 600; text-transform: uppercase;">Avg Completion Rate</div>
                                </div>
                                
                                <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 25px; border-radius: 12px; text-align: center; border-left: 4px solid #f59e0b; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);">
                                    <div style="font-size: 2.5rem; font-weight: 800; color: #b45309; margin-bottom: 8px;">
                                        <?php 
                                        $avg_engagement = count($teacher_performance_report) > 0 ? round(array_sum(array_column($teacher_performance_report, 'engagement')) / count($teacher_performance_report), 1) : 0;
                                        echo $avg_engagement; 
                                        ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #78350f; font-weight: 600; text-transform: uppercase;">Avg Student Engagement</div>
                                </div>
                                
                                <div style="background: linear-gradient(135deg, #fce7f3, #fbcfe8); padding: 25px; border-radius: 12px; text-align: center; border-left: 4px solid #ec4899; box-shadow: 0 2px 8px rgba(236, 72, 153, 0.2);">
                                    <div style="font-size: 2.5rem; font-weight: 800; color: #be185d; margin-bottom: 8px;">
                                        <?php 
                                        $avg_performance = count($teacher_performance_report) > 0 ? round(array_sum(array_column($teacher_performance_report, 'performance_score')) / count($teacher_performance_report), 1) : 0;
                                        echo $avg_performance; 
                                        ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #9f1239; font-weight: 600; text-transform: uppercase;">Avg Performance Score</div>
                                </div>
                            </div>
                            
                            <!-- Radar Chart Visualization -->
                            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); margin-bottom: 30px;">
                                <h4 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0 0 25px 0;">
                                    <i class="fa fa-chart-area" style="color: #8b5cf6;"></i> Teacher Performance Radar Chart
                                </h4>
                                <div style="position: relative; height: 500px; max-width: 900px; margin: 0 auto;">
                                    <canvas id="teacherPerformanceRadarChart"></canvas>
                                </div>
                                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #8b5cf6;">
                                    <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                        <strong>Radar Chart:</strong> Displays multi-dimensional performance metrics for each teacher including student grades, completion rates, engagement, and quiz/assignment performance.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Bar Graph Comparison -->
                            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); margin-bottom: 30px;">
                                <h4 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0 0 25px 0;">
                                    <i class="fa fa-chart-bar" style="color: #3b82f6;"></i> Teacher Performance Comparison
                                </h4>
                                <div class="scrollable-chart-container" style="background: transparent; padding: 0; overflow-x: auto; overflow-y: hidden;">
                                    <div style="position: relative; height: 500px; min-width: <?php echo max(900, count($teacher_performance_report) * 100); ?>px;">
                                        <canvas id="teacherPerformanceBarChart"></canvas>
                                    </div>
                                </div>
                                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                    <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">
                                        <strong>Performance Score:</strong> Calculated as (Average Grade × 40%) + (Completion Rate × 30%) + (Student Engagement × 30%). Maximum score: 100 points.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Detailed Performance Table -->
                            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                                <h4 style="font-size: 1.2rem; font-weight: 700; color: #1f2937; margin: 0 0 20px 0;">
                                    <i class="fa fa-table" style="color: #6b7280;"></i> Teacher Performance Breakdown
                                </h4>
                                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table style="width: 100%; min-width: 1000px; border-collapse: collapse; font-size: 0.9rem;">
                                        <thead>
                                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                                <th style="padding: 12px; text-align: left; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 180px;">Teacher Name</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Quiz Avg</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Assignment Avg</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Overall Grade</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Students</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 130px;">Completion</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 120px;">Engagement</th>
                                                <th style="padding: 12px; text-align: center; color: #374151; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; min-width: 150px;">Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($teacher_performance_report as $teacher): ?>
                                                <tr style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='#ffffff'">
                                                    <td style="padding: 12px; text-align: left;">
                                                        <div style="font-weight: 600; color: #1f2937;">
                                                            <?php echo htmlspecialchars($teacher['name']); ?>
                                                        </div>
                                                        <div style="font-size: 0.75rem; color: #6b7280;">
                                                            <?php echo htmlspecialchars($teacher['email']); ?>
                                                        </div>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="font-weight: 700; font-size: 1.1rem; color: <?php echo $teacher['avg_quiz_grade'] >= 70 ? '#10b981' : ($teacher['avg_quiz_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                            <?php echo $teacher['avg_quiz_grade'] > 0 ? $teacher['avg_quiz_grade'] . '%' : 'N/A'; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="font-weight: 700; font-size: 1.1rem; color: <?php echo $teacher['avg_assignment_grade'] >= 70 ? '#10b981' : ($teacher['avg_assignment_grade'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                            <?php echo $teacher['avg_assignment_grade'] > 0 ? $teacher['avg_assignment_grade'] . '%' : 'N/A'; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; padding: 8px 14px; background: linear-gradient(135deg, <?php 
                                                            echo $teacher['overall_avg_grade'] >= 80 ? '#10b981, #059669' : 
                                                                 ($teacher['overall_avg_grade'] >= 70 ? '#3b82f6, #2563eb' : 
                                                                 ($teacher['overall_avg_grade'] >= 60 ? '#f59e0b, #d97906' : '#ef4444, #dc2626')); 
                                                        ?>); color: white; border-radius: 8px; font-weight: 700; font-size: 1.1rem;">
                                                            <?php echo $teacher['overall_avg_grade'] . '%'; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center; color: #4b5563; font-weight: 600; font-size: 1rem;">
                                                        <?php echo $teacher['total_students']; ?>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="font-weight: 700; color: <?php echo $teacher['completion_rate'] >= 70 ? '#10b981' : ($teacher['completion_rate'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                                            <?php echo $teacher['completion_rate'] . '%'; ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; padding: 6px 12px; background: <?php echo $teacher['engagement'] >= 10 ? '#d1fae5' : ($teacher['engagement'] >= 5 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $teacher['engagement'] >= 10 ? '#065f46' : ($teacher['engagement'] >= 5 ? '#92400e' : '#991b1b'); ?>; border-radius: 8px; font-weight: 600;">
                                                            <?php echo $teacher['engagement']; ?> logins
                                                        </span>
                                                    </td>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <span style="display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 1rem; <?php 
                                                            if ($teacher['performance_score'] >= 80) {
                                                                echo 'background: linear-gradient(135deg, #10b981, #059669); color: white;';
                                                            } elseif ($teacher['performance_score'] >= 70) {
                                                                echo 'background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;';
                                                            } elseif ($teacher['performance_score'] >= 60) {
                                                                echo 'background: linear-gradient(135deg, #f59e0b, #d97906); color: white;';
                                                            } else {
                                                                echo 'background: linear-gradient(135deg, #ef4444, #dc2626); color: white;';
                                                            }
                                                        ?>">
                                                            <?php echo $teacher['performance_score']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-data-row">
                                <i class="fa fa-user-tie" style="font-size: 3rem; margin-bottom: 15px; color: #d1d5db;"></i>
                                <p>No teacher performance data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
</div>
