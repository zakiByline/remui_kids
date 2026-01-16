<?php
/**
 * Parent Dashboard - My Children Page
 * COMPREHENSIVE VIEW with ALL real data parents need
 * 
 * Features:
 * - Detailed child information
 * - Real-time statistics
 * - Course progress
 * - Recent activities
 * - Upcoming assignments
 * - Teacher information
 * - Attendance records
 * - Grade details
 * - Professional design
 */

// Disable error display to prevent output before headers
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $SESSION;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');

try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
    // Continue anyway - let the page try to load
} catch (Error $e) {
    error_log('Fatal error in parent access check: ' . $e->getMessage());
    // Continue anyway
}

// Set up page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_children.php');
$PAGE->set_title('My Children - Parent Dashboard');
$PAGE->set_heading('My Children');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Helper function for time ago
function format_time_ago_helper($seconds) {
    if ($seconds < 60) {
        return $seconds . ' sec ago';
    } elseif ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    } elseif ($seconds < 86400) {
        return floor($seconds / 3600) . ' hours ago';
    } else {
        return floor($seconds / 86400) . ' days ago';
    }
}

// Include helper functions
require_once(__DIR__ . '/../lib/get_parent_children.php');

// Get children with basic info
try {
    $children_basic = get_parent_children($userid);
} catch (Exception $e) {
    debugging('Error getting parent children: ' . $e->getMessage());
    $children_basic = [];
}

$children = [];

// If no children, show empty state early
if (empty($children_basic)) {
    echo $OUTPUT->header();
    include_once(__DIR__ . '/../components/parent_sidebar.php');
    echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/parent_dashboard.css">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
    echo '<div class="parent-main-content">';
    echo '<div class="empty-state">';
    echo '<div class="empty-icon"><i class="fas fa-users"></i></div>';
    echo '<h2 class="empty-title">No Children Found</h2>';
    echo '<p class="empty-text">You don\'t have any children linked to your parent account yet.</p>';
    echo '</div></div>';
    echo $OUTPUT->footer();
    exit;
}

// Enhance each child's data with comprehensive information
foreach ($children_basic as $child_basic) {
    try {
        $child_id = isset($child_basic['id']) ? (int)$child_basic['id'] : 0;
        if (!$child_id) {
            continue; // Skip if no valid ID
        }
        
        // Get full user record
        $child_user = $DB->get_record('user', ['id' => $child_id]);
        if (!$child_user) {
            continue; // Skip if user not found
        }
    
        // Get profile picture URL from Moodle
        $profile_picture_url = '';
        $has_profile_picture = false;
        if (isset($child_user->picture) && $child_user->picture > 0) {
            try {
                // Ensure we have full user record
                if (!isset($child_user->id) || empty($child_user->id)) {
                    throw new Exception('Invalid user object');
                }
                
                $user_context = context_user::instance($child_id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
                
                if (!empty($files)) {
                    $user_picture = new user_picture($child_user);
                    $user_picture->size = 1; // Full size
                    $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                    if (!empty($profile_picture_url)) {
                        $has_profile_picture = true;
                    }
                }
            } catch (Exception $e) {
                // Profile picture not available - log error for debugging
                debugging('Error getting child profile picture for user ' . $child_id . ': ' . $e->getMessage());
            }
        }
        
        // Get section from cohort name if available
        $section = 'N/A';
        try {
            $cohort_info = $DB->get_record_sql(
                "SELECT c.name, c.description
                 FROM {cohort} c
                 JOIN {cohort_members} cm ON cm.cohortid = c.id
                 WHERE cm.userid = :userid
                 LIMIT 1",
                ['userid' => $child_id]
            );
            
            if ($cohort_info && !empty($cohort_info->name)) {
                // Try to extract section from cohort name (e.g., "Grade 1 Section A" or "Section B")
                if (preg_match('/section\s*([A-Z])/i', $cohort_info->name, $matches)) {
                    $section = strtoupper($matches[1]);
                } elseif (preg_match('/([A-Z])\s*section/i', $cohort_info->name, $matches)) {
                    $section = strtoupper($matches[1]);
                }
            }
        } catch (Exception $e) {
            // Section not available, keep as N/A
        }
    
    // ==========================================
    // 1. ENROLLED COURSES WITH DETAILED INFO
    // ==========================================
    $courses = [];
    try {
        $enrolled_courses = enrol_get_users_courses($child_id, true);
    } catch (Exception $e) {
        debugging('Error getting enrolled courses: ' . $e->getMessage());
        $enrolled_courses = [];
    }
    
    foreach ($enrolled_courses as $course) {
        // Get course progress
        $progress_percentage = 0;
        try {
            if (class_exists('completion_info')) {
                $completion = new completion_info($course);
                if ($completion && method_exists($completion, 'is_enabled') && $completion->is_enabled()) {
                    try {
                        if (class_exists('\core_completion\progress')) {
                            $percentage = \core_completion\progress::get_course_progress_percentage($course, $child_id);
                            $progress_percentage = ($percentage !== null && $percentage !== false) ? round((float)$percentage) : 0;
                        } else {
                            // Fallback for older Moodle versions
                            $progress_percentage = 0;
                        }
                    } catch (Exception $e) {
                        $progress_percentage = 0;
                    } catch (Error $e) {
                        $progress_percentage = 0;
                    }
                }
            }
        } catch (Exception $e) {
            $progress_percentage = 0;
        } catch (Error $e) {
            $progress_percentage = 0;
        }
        
        // Get course grade
        $course_grade = 'N/A';
        $course_grade_percentage = 0;
        try {
            if (class_exists('grade_item') && method_exists('grade_item', 'fetch_course_item')) {
                $grade_item = grade_item::fetch_course_item($course->id);
                if ($grade_item && isset($grade_item->id)) {
                    if (class_exists('grade_grade') && method_exists('grade_grade', 'fetch')) {
                        $grade = grade_grade::fetch(['userid' => $child_id, 'itemid' => $grade_item->id]);
                        if ($grade && isset($grade->id) && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                            if (isset($grade_item->grademax) && $grade_item->grademax > 0) {
                                // Simple calculation - avoid complex grade formatting that might fail
                                $course_grade_percentage = round(($grade->finalgrade / $grade_item->grademax) * 100, 1);
                                $course_grade = round($grade->finalgrade, 1) . '/' . round($grade_item->grademax, 1);
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore grade errors
            debugging('Grade error: ' . $e->getMessage());
        } catch (Error $e) {
            // Catch fatal errors
            debugging('Grade fatal error: ' . $e->getMessage());
        }
        
        // Get teacher names
        $teachers = [];
        try {
            $context_course = context_course::instance($course->id);
            $teacher_roles = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname
                    FROM {user} u
                 JOIN {role_assignments} ra ON ra.userid = u.id
                        JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.contextid = ?
                 AND r.shortname IN ('editingteacher', 'teacher')
                 AND u.deleted = 0
                 AND u.id NOT IN (
                     SELECT DISTINCT ra_admin.userid
                     FROM {role_assignments} ra_admin
                     JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
                     WHERE r_admin.shortname IN ('manager', 'administrator')
                 )
                 LIMIT 3",
                [$context_course->id]
            );
            
            foreach ($teacher_roles as $teacher) {
                $teachers[] = fullname($teacher);
            }
        } catch (Exception $e) {
            // Ignore teacher errors
            debugging('Error getting teachers: ' . $e->getMessage());
        }
        
        $courses[] = [
            'id' => $course->id,
            'name' => $course->fullname,
            'shortname' => $course->shortname,
            'progress' => $progress_percentage,
            'grade' => $course_grade,
            'grade_percentage' => $course_grade_percentage,
            'teachers' => $teachers,
            'url' => (new moodle_url('/theme/remui_kids/parent/parent_course_detail.php', ['courseid' => $course->id, 'child' => $child_id]))->out()
        ];
    }
    
    // ==========================================
    // 2. RECENT GRADES (Last 10)
    // ==========================================
    $recent_grades = [];
    try {
        $sql = "SELECT gg.id, gg.finalgrade, gg.timemodified,
                       gi.itemname, gi.grademax, gi.itemtype, gi.itemmodule,
                       c.fullname as coursename, c.id as courseid
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                JOIN {course} c ON c.id = gi.courseid
                WHERE gg.userid = ?
                AND gg.finalgrade IS NOT NULL
                AND gi.itemtype != 'course'
                ORDER BY gg.timemodified DESC
                LIMIT 10";
        
        $grades = $DB->get_records_sql($sql, [$child_id]);
        
        foreach ($grades as $grade) {
            $finalgrade = $grade->finalgrade !== null ? (float)$grade->finalgrade : 0;
            $maxgrade = $grade->grademax !== null ? (float)$grade->grademax : 0;
            $percentage = $maxgrade > 0 ? round(($finalgrade / $maxgrade) * 100, 1) : 0;
            
            $recent_grades[] = [
                'name' => $grade->itemname,
                'course' => $grade->coursename,
                'grade' => round($finalgrade, 1),
                'max' => round($maxgrade, 1),
                'percentage' => $percentage,
                'date' => userdate($grade->timemodified, '%d %b, %Y'),
                'status' => $percentage >= 70 ? 'good' : ($percentage >= 50 ? 'average' : 'needs-attention')
            ];
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // ==========================================
    // 3. UPCOMING ASSIGNMENTS & DEADLINES
    // ==========================================
    $upcoming_assignments = [];
    try {
        $sql = "SELECT a.id, a.name, a.duedate, c.fullname as coursename, c.id as courseid,
                       asub.id as submission_id, asub.status as submission_status,
                       cm.id as cmid
                FROM {assign} a
                JOIN {course} c ON c.id = a.course
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                LEFT JOIN {course_modules} cm ON cm.instance = a.id 
                    AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                    AND cm.course = c.id
                    AND cm.deletioninprogress = 0
                LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = ?
                WHERE ue.userid = ?
                AND a.duedate > ?
                AND a.duedate < ?
                AND (asub.status IS NULL OR asub.status != 'submitted')
                ORDER BY a.duedate ASC
                LIMIT 10";
        
        $now = time();
        $in_30_days = $now + (30 * 24 * 60 * 60);
        
        $assignments = $DB->get_records_sql($sql, [$child_id, $child_id, $now, $in_30_days]);
        
        foreach ($assignments as $assignment) {
            $days_until = ceil(($assignment->duedate - $now) / (24 * 60 * 60));
            
            // Build URL to our custom parent theme page
            $assignment_url = '';
            if (!empty($assignment->cmid) && !empty($assignment->courseid)) {
                $assignment_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                    'cmid' => $assignment->cmid,
                    'child' => $child_id,
                    'courseid' => $assignment->courseid
                ]))->out();
            }
            
            $upcoming_assignments[] = [
                'name' => $assignment->name,
                'course' => $assignment->coursename,
                'due_date' => userdate($assignment->duedate, '%d %b, %Y'),
                'days_until' => $days_until,
                'urgency' => $days_until <= 3 ? 'urgent' : ($days_until <= 7 ? 'soon' : 'normal'),
                'url' => $assignment_url
            ];
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // ==========================================
    // 4. RECENT ACTIVITY LOG
    // ==========================================
    $recent_activities = [];
    try {
        $sql = "SELECT l.id, l.timecreated, l.action, l.target, l.objecttable, l.objectid,
                       c.fullname as coursename, l.contextinstanceid
                FROM {logstore_standard_log} l
                LEFT JOIN {course} c ON c.id = l.courseid
                WHERE l.userid = ?
                AND l.timecreated > ?
                AND l.action IN ('viewed', 'submitted', 'updated', 'created')
                ORDER BY l.timecreated DESC
                LIMIT 15";
        
        $last_week = time() - (7 * 24 * 60 * 60);
        $logs = $DB->get_records_sql($sql, [$child_id, $last_week]);
        
        foreach ($logs as $log) {
            $activity_icon = 'fa-circle';
            $activity_type = ucfirst($log->action);
            
            if ($log->target == 'course') {
                $activity_icon = 'fa-book';
                $activity_type = 'Viewed Course';
            } elseif ($log->target == 'course_module') {
                $activity_icon = 'fa-file';
                $activity_type = 'Accessed Activity';
            } elseif ($log->action == 'submitted') {
                $activity_icon = 'fa-check-circle';
                $activity_type = 'Submitted Assignment';
            }
            
            $recent_activities[] = [
                'icon' => $activity_icon,
                'type' => $activity_type,
                'course' => $log->coursename ?: 'System',
                'time' => userdate($log->timecreated, '%d %b, %I:%M %p'),
                'time_ago' => format_time_ago_helper(time() - $log->timecreated)
            ];
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // ==========================================
    // 5. ATTENDANCE DETAILS
    // ==========================================
    $attendance_data = [];
    $attendance_summary = [
        'percentage' => 0,
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'excused' => 0,
        'total' => 0
    ];
    
    try {
        if ($DB->get_manager()->table_exists('attendance_log')) {
            $sql = "SELECT al.id, al.sessdate, al.description,
                           atts.acronym, atts.description as status_desc,
                           c.fullname as coursename
                    FROM {attendance_log} al
                    JOIN {attendance_statuses} atts ON atts.id = al.statusid
                    JOIN {attendance_sessions} asess ON asess.id = al.sessionid
                    JOIN {attendance} att ON att.id = asess.attendanceid
                    JOIN {course} c ON c.id = att.course
                    WHERE al.studentid = ?
                    ORDER BY al.sessdate DESC
                    LIMIT 20";
            
            $attendance_records = $DB->get_records_sql($sql, [$child_id]);
            
            foreach ($attendance_records as $record) {
                $status_class = 'present';
                if ($record->acronym == 'A') {
                    $status_class = 'absent';
                    $attendance_summary['absent']++;
                } elseif ($record->acronym == 'P') {
                    $status_class = 'present';
                    $attendance_summary['present']++;
                } elseif ($record->acronym == 'L') {
                    $status_class = 'late';
                    $attendance_summary['late']++;
                } elseif ($record->acronym == 'E') {
                    $status_class = 'excused';
                    $attendance_summary['excused']++;
                }
                
                $attendance_summary['total']++;
                
                $attendance_data[] = [
                    'date' => userdate($record->sessdate, '%d %b, %Y'),
                    'course' => $record->coursename,
                    'status' => $record->status_desc,
                    'status_class' => $status_class,
                    'description' => $record->description
                ];
            }
            
            if ($attendance_summary['total'] > 0) {
                $present = (int)$attendance_summary['present'];
                $total = (int)$attendance_summary['total'];
                $attendance_summary['percentage'] = round(($present / $total) * 100, 1);
            }
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // ==========================================
    // 6. TEACHERS LIST
    // ==========================================
    $child_teachers = [];
    try {
        // Get teachers - database agnostic approach
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.phone1
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {context} ctx ON ctx.id = ra.contextid
                JOIN {role} r ON r.id = ra.roleid
                JOIN {course} c ON c.id = ctx.instanceid
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE ue.userid = ?
                AND ctx.contextlevel = 50
                AND r.shortname IN ('editingteacher', 'teacher')
                AND u.deleted = 0
                AND u.id NOT IN (
                    SELECT DISTINCT ra_admin.userid
                    FROM {role_assignments} ra_admin
                    JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
                    WHERE r_admin.shortname IN ('manager', 'administrator')
                )
                ORDER BY u.firstname, u.lastname";
        
        $teachers = $DB->get_records_sql($sql, [$child_id]);
        
        foreach ($teachers as $teacher) {
            // Get courses for this teacher and child
            $teacher_courses_sql = "SELECT DISTINCT c.fullname
                                   FROM {course} c
                                   JOIN {context} ctx ON ctx.instanceid = c.id
                                   JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                   JOIN {enrol} e ON e.courseid = c.id
                                   JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                   WHERE ra.userid = ?
                                   AND ue.userid = ?
                                   AND ctx.contextlevel = 50
                                   LIMIT 3";
            $teacher_courses = $DB->get_records_sql($teacher_courses_sql, [$teacher->id, $child_id]);
            $courses_array = !empty($teacher_courses) && is_array($teacher_courses) ? array_column($teacher_courses, 'fullname') : [];
            $courses_display = !empty($courses_array) ? implode(', ', array_slice($courses_array, 0, 2)) : 'N/A';
            if (count($courses_array) > 2) {
                $courses_display .= ' +' . (count($courses_array) - 2);
            }
            
            $child_teachers[] = [
                'id' => $teacher->id,
                'name' => fullname($teacher),
                'email' => $teacher->email,
                'phone' => $teacher->phone1 ?: 'N/A',
                'courses' => $courses_display,
                'message_url' => (new moodle_url('/theme/remui_kids/parent/parent_messages.php', ['id' => $teacher->id]))->out()
            ];
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // ==========================================
    // 7. STATISTICS SUMMARY
    // ==========================================
    
    // Calculate completed and pending assignments from data
    $completed_assignments_count = 0;
    $pending_assignments_count = 0;
    
    try {
        $sql_completed = "SELECT COUNT(*) as total
                         FROM {assign_submission} asub
                         JOIN {assign} a ON a.id = asub.assignment
                         JOIN {course} c ON c.id = a.course
                         JOIN {enrol} e ON e.courseid = c.id
                         JOIN {user_enrolments} ue ON ue.enrolid = e.id
                         WHERE ue.userid = :userid
                         AND asub.userid = :userid2
                         AND asub.status = 'submitted'";
        
        $completed_result = $DB->get_record_sql($sql_completed, [
            'userid' => $child_id,
            'userid2' => $child_id
        ]);
        $completed_assignments_count = $completed_result ? (int)$completed_result->total : 0;
        
        $sql_pending = "SELECT COUNT(*) as total
                       FROM {assign} a
                       JOIN {course} c ON c.id = a.course
                       JOIN {enrol} e ON e.courseid = c.id
                       JOIN {user_enrolments} ue ON ue.enrolid = e.id
                       LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = :userid
                       WHERE ue.userid = :userid2
                       AND (asub.id IS NULL OR asub.status != 'submitted')
                       AND a.duedate > :now";
        
        $pending_result = $DB->get_record_sql($sql_pending, [
            'userid' => $child_id,
            'userid2' => $child_id,
            'now' => time()
        ]);
        $pending_assignments_count = $pending_result ? (int)$pending_result->total : 0;
    } catch (Exception $e) {
        error_log("Error calculating assignments: " . $e->getMessage());
    }
    
    $statistics = [
        'total_courses' => count($courses),
        'average_progress' => !empty($courses) && is_array($courses) && count($courses) > 0 ? round(array_sum(array_column($courses, 'progress')) / count($courses), 1) : 0,
        'attendance_percentage' => $attendance_summary['percentage'] ?? 0,
        'completed_assignments' => $completed_assignments_count,
        'pending_assignments' => $pending_assignments_count,
        'total_teachers' => count($child_teachers),
        'recent_activities_count' => count($recent_activities),
        'upcoming_deadlines' => count($upcoming_assignments)
    ];
    
    // Build comprehensive child data array
    $children[] = [
        // Basic Info
        'id' => $child_id,
        'name' => $child_basic['name'],
        'email' => $child_user->email,
        'phone' => $child_user->phone1 ?: $child_user->phone2 ?: 'Not provided',
        'address' => $child_user->address ?: 'Not provided',
        'city' => $child_user->city ?: '',
        'country' => $child_user->country ?: '',
        'class' => $child_basic['class'],
        'section' => $section, // Extracted from cohort name or N/A
        'roll' => str_pad($child_id, 3, '0', STR_PAD_LEFT), // Generated from user ID (no roll field in Moodle)
        'admission_date' => date('d M, Y', $child_user->timecreated),
        'cohort' => $child_basic['cohortname'] ?: 'N/A',
        
        // Statistics
        'statistics' => $statistics,
        
        // Detailed Data
        'courses' => $courses,
        'recent_grades' => $recent_grades,
        'upcoming_assignments' => $upcoming_assignments,
        'recent_activities' => $recent_activities,
        'attendance_data' => $attendance_data,
        'attendance_summary' => $attendance_summary,
        'teachers' => $child_teachers,
        
        // Profile picture
        'profile_picture_url' => $profile_picture_url,
        'has_profile_picture' => $has_profile_picture,
        'avatar_initial' => strtoupper(substr($child_basic['name'] ?? '?', 0, 1)),
        'avatar_color' => ['#60a5fa', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'][$child_id % 6]
    ];
    } catch (Exception $e) {
        debugging('Error processing child ' . ($child_id ?? 'unknown') . ': ' . $e->getMessage());
        continue; // Skip this child and continue with next
    } catch (Error $e) {
        debugging('Fatal error processing child ' . ($child_id ?? 'unknown') . ': ' . $e->getMessage());
        continue; // Skip this child and continue with next
    }
}

// Wrap output in try-catch to prevent 500 errors
try {
    echo $OUTPUT->header();
    include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Enhanced Modern Design */
.parent-main-content {
    margin-left: 280px;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
    padding: 20px 24px;
    position: relative;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Comprehensive Responsive Design */
@media (max-width: 1024px) {
    .parent-main-content {
        margin-left: 260px;
        width: calc(100% - 260px);
        padding: 18px 20px;
    }
    
    .parent-main-content::before {
        left: 260px;
    }
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 16px !important;
    }
    
    .parent-main-content::before {
        left: 0;
    }
    
    .page-header-comprehensive {
        padding: 16px !important;
    }
    
    /* Make all grids single column */
    [style*="grid-template-columns"],
    [style*="display: grid"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Stack flex containers */
    [style*="display: flex"] {
        flex-direction: column !important;
    }
    
    /* Make tables scrollable */
    table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust font sizes */
    h1, h2, h3 {
        font-size: 1.2em !important;
    }
}

@media (max-width: 480px) {
    .parent-main-content {
        padding: 12px !important;
    }
    
    .page-header-comprehensive {
        padding: 12px !important;
    }
    
    body {
        font-size: 14px !important;
    }
}

.parent-main-content::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 15% 25%, rgba(59, 130, 246, 0.03) 0%, transparent 50%),
        radial-gradient(circle at 85% 75%, rgba(139, 92, 246, 0.02) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

/* Enhanced Page Header */
.page-header-comprehensive {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    padding: 20px 24px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25), 0 1px 4px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.page-header-comprehensive::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(40px);
}

.page-title-comprehensive {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 6px 0;
    letter-spacing: -0.3px;
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-title-comprehensive i {
    font-size: 20px;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px;
    border-radius: 6px;
    backdrop-filter: blur(10px);
}

.page-subtitle {
    font-size: 13px;
    opacity: 0.95;
    margin: 0;
    position: relative;
    z-index: 1;
    font-weight: 500;
}

/* Enhanced Child Card */
.child-master-card {
    background: #ffffff;
    border-radius: 8px;
    padding: 0;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    z-index: 1;
}

.child-master-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.15), 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Enhanced Child Header */
.child-header-section {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    overflow: hidden;
}

.child-header-section::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.child-avatar-large {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15), inset 0 1px 2px rgba(255, 255, 255, 0.3);
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.5);
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
}

.child-master-card:hover .child-avatar-large {
    transform: scale(1.05);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25), inset 0 2px 4px rgba(255, 255, 255, 0.4);
}

.child-header-info {
    flex: 1;
}

.child-name-large {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
    position: relative;
    z-index: 1;
}

.child-meta-info {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.child-meta-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.2s ease;
}

.child-meta-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e1;
}

.child-meta-badge i {
    color: #3b82f6;
    font-size: 13px;
}

/* Enhanced Statistics Dashboard */
.statistics-dashboard {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    padding: 16px 20px;
    background: #ffffff;
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    position: relative;
}

.stat-card {
    background: #ffffff;
    padding: 14px 12px;
    border-radius: 6px;
    text-align: left;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    transition: width 0.2s ease;
}

.stat-card.blue::before { background: #3b82f6; }
.stat-card.green::before { background: #10b981; }
.stat-card.orange::before { background: #f59e0b; }
.stat-card.purple::before { background: #8b5cf6; }
.stat-card.red::before { background: #ef4444; }

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #d1d5db;
}

.stat-card:hover::before {
    width: 6px;
}

.stat-icon-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
}

.stat-icon {
    font-size: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.stat-card.blue .stat-icon { 
    color: #3b82f6; 
    background: #eff6ff;
}
.stat-card.green .stat-icon { 
    color: #10b981; 
    background: #f0fdf4;
}
.stat-card.orange .stat-icon { 
    color: #f59e0b; 
    background: #fffbeb;
}
.stat-card.purple .stat-icon { 
    color: #8b5cf6; 
    background: #f5f3ff;
}
.stat-card.red .stat-icon { 
    color: #ef4444; 
    background: #fef2f2;
}

.stat-card:hover .stat-icon {
    transform: scale(1.05);
}

.stat-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    margin: 0;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.stat-label {
    font-size: 10px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 0;
    font-weight: 600;
}

/* Enhanced Tabs */
.child-tabs {
    display: flex;
    gap: 4px;
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    padding: 8px;
    border-bottom: 2px solid #e5e7eb;
    overflow-x: auto;
    position: relative;
}

.child-tab {
    padding: 8px 14px;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    border-radius: 6px;
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
}

.child-tab:hover {
    background: rgba(255, 255, 255, 0.6);
    color: #475569;
    transform: translateY(-2px);
}

.child-tab.active {
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15), 0 2px 4px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.2);
}

.child-tab.active::before {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 40px;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    border-radius: 2px;
}

.child-tab i {
    font-size: 14px;
}

.tab-content-area {
    display: none;
    padding: 20px;
    animation: fadeIn 0.3s ease;
}

.tab-content-area.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Enhanced Courses */
.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.course-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 16px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-left: 3px solid #3b82f6;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.course-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.course-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
    border-left-color: #8b5cf6;
}

.course-card:hover::before {
    opacity: 1;
}

.course-name {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
    letter-spacing: -0.2px;
    line-height: 1.3;
}

.course-teachers {
    font-size: 13px;
    color: #64748b;
    margin: 0 0 16px 0;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

.course-teachers i {
    color: #3b82f6;
    font-size: 14px;
}

.course-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 16px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

.course-stat-item {
    text-align: center;
    flex: 1;
}

.course-stat-value {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 4px 0;
    letter-spacing: -1px;
    line-height: 1;
}

.course-stat-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.6px;
}

.progress-bar-container {
    height: 12px;
    background: #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    margin-top: 16px;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #ef4444, #dc2626);
    border-radius: 12px;
    transition: width 1s cubic-bezier(0.4, 0, 0.2, 1), background 0.3s ease;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 8px;
    min-width: 0;
}

.progress-bar.progress-low {
    background: linear-gradient(90deg, #ef4444, #dc2626);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.progress-bar.progress-medium {
    background: linear-gradient(90deg, #f59e0b, #d97706);
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.progress-bar.progress-high {
    background: linear-gradient(90deg, #10b981, #059669);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.progress-text {
    color: white;
    font-size: 10px;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
}

.progress-bar::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Enhanced Grades */
.grades-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}

.grade-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 14px 16px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.grade-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.grade-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.grade-item:hover::before {
    opacity: 1;
}

.grade-info {
    flex: 1;
}

.grade-name {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 4px 0;
    letter-spacing: -0.1px;
}

.grade-course {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
}

.grade-score {
    text-align: right;
}

.grade-percentage {
    font-size: 24px;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.5px;
    line-height: 1;
}

.grade-percentage.good { 
    color: #10b981; 
    text-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
}
.grade-percentage.average { 
    color: #f59e0b; 
    text-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
}
.grade-percentage.needs-attention { 
    color: #ef4444; 
    text-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
}

.grade-item.good::before { background: linear-gradient(180deg, #10b981, #059669); }
.grade-item.average::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
.grade-item.needs-attention::before { background: linear-gradient(180deg, #ef4444, #dc2626); }

.grade-date {
    font-size: 12px;
    color: #94a3b8;
    margin: 8px 0 0 0;
    font-weight: 600;
}

/* Enhanced Assignments */
.assignments-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}

.assignment-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 14px 16px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-left: 3px solid;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.assignment-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.assignment-item.urgent { 
    border-left-color: #ef4444; 
}
.assignment-item.urgent::before { background: linear-gradient(90deg, #ef4444, #dc2626); }

.assignment-item.soon { 
    border-left-color: #f59e0b; 
}
.assignment-item.soon::before { background: linear-gradient(90deg, #f59e0b, #d97706); }

.assignment-item.normal { 
    border-left-color: #10b981; 
}
.assignment-item.normal::before { background: linear-gradient(90deg, #10b981, #059669); }

.assignment-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.assignment-item:hover::before {
    opacity: 1;
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
}

.assignment-name {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    letter-spacing: -0.2px;
    line-height: 1.3;
}

.assignment-urgency {
    padding: 6px 14px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.assignment-urgency.urgent {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.assignment-urgency.soon {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1px solid #fcd34d;
}

.assignment-urgency.normal {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.assignment-course {
    font-size: 14px;
    color: #64748b;
    margin: 10px 0;
    font-weight: 500;
}

.assignment-due {
    font-size: 15px;
    color: #475569;
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.assignment-due i {
    color: #3b82f6;
}

/* Enhanced Activities Timeline */
.activities-timeline {
    position: relative;
    padding-left: 50px;
}

.activities-timeline::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, #3b82f6, #8b5cf6, #ec4899);
    border-radius: 2px;
}

.activity-item {
    position: relative;
    padding: 20px 24px;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.activity-item:hover {
    transform: translateX(4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.activity-item::before {
    content: '';
    position: absolute;
    left: -35px;
    top: 24px;
    width: 16px;
    height: 16px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border-radius: 50%;
    border: 4px solid white;
    box-shadow: 0 0 0 3px #e5e7eb, 0 2px 8px rgba(59, 130, 246, 0.3);
    z-index: 1;
}

.activity-type {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.2px;
}

.activity-type i {
    color: #3b82f6;
    font-size: 18px;
}

.activity-details {
    font-size: 14px;
    color: #64748b;
    margin: 0 0 8px 0;
    font-weight: 500;
}

.activity-time {
    font-size: 12px;
    color: #94a3b8;
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Enhanced Teachers Grid */
.teachers-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

.teacher-mini-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 28px;
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.teacher-mini-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.teacher-mini-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(0, 0, 0, 0.1);
}

.teacher-mini-card:hover::before {
    opacity: 1;
}

.teacher-mini-header {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 20px;
}

.teacher-mini-avatar {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    font-weight: 800;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);
    border: 3px solid rgba(255, 255, 255, 0.5);
    transition: all 0.3s ease;
}

.teacher-mini-card:hover .teacher-mini-avatar {
    transform: scale(1.1);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
}

.teacher-mini-name {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px 0;
    letter-spacing: -0.3px;
}

.teacher-mini-courses {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
}

.teacher-contact {
    font-size: 14px;
    color: #475569;
    margin: 8px 0;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}

.teacher-contact i {
    color: #3b82f6;
    width: 18px;
    font-size: 16px;
}

.teacher-message-btn {
    width: 100%;
    padding: 14px 20px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    display: inline-block;
    text-align: center;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    margin-top: 16px;
}

.teacher-message-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    text-decoration: none;
    color: white;
}

/* Enhanced Attendance Table */
.attendance-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 12px;
}

.attendance-table th {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    padding: 18px 24px;
    text-align: left;
    font-size: 12px;
    font-weight: 800;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border-radius: 12px 12px 0 0;
}

.attendance-table td {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 18px 24px;
    font-size: 14px;
    color: #475569;
    font-weight: 500;
    border-radius: 0;
}

.attendance-table tr:first-child td:first-child {
    border-top-left-radius: 12px;
}

.attendance-table tr:first-child td:last-child {
    border-top-right-radius: 12px;
}

.attendance-table tr:last-child td:first-child {
    border-bottom-left-radius: 12px;
}

.attendance-table tr:last-child td:last-child {
    border-bottom-right-radius: 12px;
}

.attendance-table tr {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: all 0.2s ease;
}

.attendance-table tr:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.attendance-status {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.attendance-status.present {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.attendance-status.absent {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.attendance-status.late {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1px solid #fcd34d;
}

.attendance-status.excused {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border: 1px solid #93c5fd;
}

/* Enhanced Empty State */
.empty-state {
    text-align: center;
    padding: 80px 40px;
    color: #9ca3af;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 20px;
    border: 2px dashed #cbd5e1;
    position: relative;
    overflow: hidden;
}

.empty-state::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(30px);
}

.empty-icon {
    font-size: 80px;
    color: #cbd5e1;
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
    opacity: 0.7;
}

.empty-title {
    font-size: 28px;
    font-weight: 800;
    color: #475569;
    margin: 0 0 12px 0;
    letter-spacing: -0.5px;
    position: relative;
    z-index: 1;
}

.empty-text {
    font-size: 16px;
    color: #64748b;
    font-weight: 500;
    position: relative;
    z-index: 1;
}

/* Responsive */
@media (max-width: 1024px) {
    .statistics-dashboard {
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        padding: 24px 20px;
    }
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 0;
        width: 100%;
    }
    
    .statistics-dashboard {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        padding: 20px 16px;
    }
    
    .stat-card {
        padding: 20px 16px;
    }
    
    .stat-value {
        font-size: 24px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 20px;
    }
    
    .child-header-section {
        padding: 12px 15px;
    }
    
    .courses-grid,
    .grades-list,
    .assignments-list {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="parent-main-content">
        <!-- Page Header -->
    <div class="page-header-comprehensive">
        <h1 class="page-title-comprehensive">
            <i class="fas fa-users"></i> My Children
            </h1>
        <p class="page-subtitle">Comprehensive view of all information about your children</p>
        </div>

        <?php if (empty($children)): ?>
    <!-- No Children State -->
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-users"></i></div>
        <h2 class="empty-title">No Children Found</h2>
        <p class="empty-text">You don't have any children linked to your parent account yet.</p>
        <div style="margin-top: 30px;">
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" class="teacher-message-btn" style="display: inline-block; width: auto; padding: 12px 30px;">
                <i class="fas fa-plus"></i> Link Children
            </a>
        </div>
    </div>
    <?php else: ?>
    
    <?php foreach ($children as $child): ?>
    <!-- Child Master Card -->
    <div class="child-master-card">
        <!-- Header Section -->
        <div class="child-header-section">
            <div class="child-avatar-large" style="background: <?php echo $child['avatar_color']; ?>; <?php echo $child['has_profile_picture'] ? 'background-image: url(' . htmlspecialchars($child['profile_picture_url']) . '); background-size: cover; background-position: center;' : ''; ?>">
                <?php if ($child['has_profile_picture']): ?>
                    <img src="<?php echo htmlspecialchars($child['profile_picture_url']); ?>" alt="<?php echo htmlspecialchars($child['name']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <?php else: ?>
                <?php echo $child['avatar_initial']; ?>
                <?php endif; ?>
            </div>
            <div class="child-header-info">
                <h2 class="child-name-large"><?php echo htmlspecialchars($child['name']); ?></h2>
                <div class="child-meta-info">
                    <div class="child-meta-badge">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Class <?php echo htmlspecialchars($child['class']); ?></span>
                    </div>
                    <div class="child-meta-badge">
                        <i class="fas fa-id-card"></i>
                        <span>Roll: <?php echo htmlspecialchars($child['roll']); ?></span>
                    </div>
                    <div class="child-meta-badge">
                <i class="fas fa-users"></i>
                        <span><?php echo htmlspecialchars($child['cohort']); ?></span>
            </div>
                    <div class="child-meta-badge">
                        <i class="fas fa-calendar"></i>
                        <span>Enrolled: <?php echo htmlspecialchars($child['admission_date']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Dashboard -->
        <div class="statistics-dashboard">
            <div class="stat-card blue">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['total_courses']; ?></p>
                <p class="stat-label">Total Courses</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['average_progress']; ?>%</p>
                <p class="stat-label">Avg Progress</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['attendance_percentage']; ?>%</p>
                <p class="stat-label">Attendance</p>
                </div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['completed_assignments']; ?></p>
                <p class="stat-label">Completed</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['pending_assignments']; ?></p>
                <p class="stat-label">Pending</p>
                </div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon-wrapper">
                <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                <div class="stat-content">
                <p class="stat-value"><?php echo $child['statistics']['total_teachers']; ?></p>
                <p class="stat-label">Teachers</p>
                </div>
            </div>
            </div>
            
        <!-- Tabs Navigation -->
        <div class="child-tabs">
            <button class="child-tab active" onclick="switchChildTab(event, 'details-<?php echo $child['id']; ?>')">
                <i class="fas fa-info-circle"></i> Details
            </button>
            
            
            </div>
            
        <!-- Tab: Child Details -->
        <div id="details-<?php echo $child['id']; ?>" class="tab-content-area active">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
                <!-- Personal Information -->
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 1px solid rgba(226, 232, 240, 0.8);">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-user" style="color: #3b82f6; font-size: 14px;"></i> Personal Information
                    </h3>
                    <div style="display: grid; gap: 12px;">
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-envelope" style="color: #3b82f6; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Email Address</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['email']); ?></p>
                        </div>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-phone" style="color: #3b82f6; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Phone Number</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['phone']); ?></p>
                    </div>
                    </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-map-marker-alt" style="color: #3b82f6; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Address</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['address']); ?></p>
            </div>
                </div>
                        <?php if (!empty($child['city'])): ?>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-city" style="color: #3b82f6; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">City</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['city']); ?></p>
        </div>
                </div>
            <?php endif; ?>
                        <?php if (!empty($child['country'])): ?>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-globe" style="color: #3b82f6; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Country</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['country']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Academic Information -->
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 1px solid rgba(226, 232, 240, 0.8);">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-graduation-cap" style="color: #10b981; font-size: 14px;"></i> Academic Information
                    </h3>
                    <div style="display: grid; gap: 12px;">
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-chalkboard" style="color: #10b981; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Class</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['class']); ?></p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-id-card" style="color: #10b981; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Roll Number</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['roll']); ?></p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-users" style="color: #10b981; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Cohort/Group</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['cohort']); ?></p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-calendar-alt" style="color: #10b981; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Admission Date</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo htmlspecialchars($child['admission_date']); ?></p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                            <i class="fas fa-book" style="color: #10b981; font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <p style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 3px 0;">Total Courses</p>
                                <p style="font-size: 13px; color: #1e293b; font-weight: 600; margin: 0;"><?php echo $child['statistics']['total_courses']; ?> courses enrolled</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Summary -->
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 1px solid rgba(226, 232, 240, 0.8);">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-chart-line" style="color: #8b5cf6; font-size: 14px;"></i> Performance Summary
                    </h3>
                    <div style="display: grid; gap: 12px;">
                        <div style="padding: 12px; background: linear-gradient(135deg, #eff6ff, #dbeafe); border-radius: 6px; border: 1px solid #bfdbfe;">
                            <p style="font-size: 10px; color: #1e40af; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin: 0 0 6px 0;">Average Progress</p>
                            <p style="font-size: 24px; color: #1e40af; font-weight: 700; margin: 0;"><?php echo $child['statistics']['average_progress']; ?>%</p>
                        </div>
                        <div style="padding: 12px; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-radius: 6px; border: 1px solid #86efac;">
                            <p style="font-size: 10px; color: #166534; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin: 0 0 6px 0;">Completed Assignments</p>
                            <p style="font-size: 24px; color: #166534; font-weight: 700; margin: 0;"><?php echo $child['statistics']['completed_assignments']; ?></p>
                        </div>
                        <div style="padding: 12px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 6px; border: 1px solid #fcd34d;">
                            <p style="font-size: 10px; color: #92400e; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin: 0 0 6px 0;">Pending Assignments</p>
                            <p style="font-size: 24px; color: #92400e; font-weight: 700; margin: 0;"><?php echo $child['statistics']['pending_assignments']; ?></p>
                        </div>
                        <div style="padding: 12px; background: linear-gradient(135deg, #f3f4f6, #e5e7eb); border-radius: 6px; border: 1px solid #d1d5db;">
                            <p style="font-size: 10px; color: #374151; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin: 0 0 6px 0;">Recent Activities</p>
                            <p style="font-size: 24px; color: #374151; font-weight: 700; margin: 0;"><?php echo $child['statistics']['recent_activities_count']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
                        </div>

        <!-- Tab: Recent Grades -->
        <div id="grades-<?php echo $child['id']; ?>" class="tab-content-area">
            <?php if (!empty($child['recent_grades'])): ?>
            <div class="grades-list">
                <?php foreach ($child['recent_grades'] as $grade): ?>
                <div class="grade-item">
                    <div class="grade-info">
                        <h4 class="grade-name"><?php echo htmlspecialchars($grade['name']); ?></h4>
                        <p class="grade-course"><?php echo htmlspecialchars($grade['course']); ?></p>
                        </div>
                    <div class="grade-score">
                        <p class="grade-percentage <?php echo $grade['status']; ?>"><?php echo $grade['percentage']; ?>%</p>
                        <p style="font-size: 13px; color: #6b7280; margin: 5px 0 0 0;">
                            <?php echo $grade['grade']; ?>/<?php echo $grade['max']; ?>
                        </p>
                        <p class="grade-date"><?php echo $grade['date']; ?></p>
                        </div>
                        </div>
                <?php endforeach; ?>
                    </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-chart-bar"></i></div>
                <p class="empty-title">No Grades Yet</p>
                <p class="empty-text">No grades have been posted for this child.</p>
                </div>
            <?php endif; ?>
            </div>

        <!-- Tab: Upcoming Assignments -->
        <div id="assignments-<?php echo $child['id']; ?>" class="tab-content-area">
            <?php if (!empty($child['upcoming_assignments'])): ?>
            <div class="assignments-list">
                <?php foreach ($child['upcoming_assignments'] as $assignment): ?>
                <div class="assignment-item <?php echo $assignment['urgency']; ?>">
                    <div class="assignment-header">
                        <h4 class="assignment-name"><?php echo htmlspecialchars($assignment['name']); ?></h4>
                        <span class="assignment-urgency <?php echo $assignment['urgency']; ?>">
                            <?php 
                            if ($assignment['urgency'] == 'urgent') echo 'Due Soon!';
                            elseif ($assignment['urgency'] == 'soon') echo 'This Week';
                            else echo 'Upcoming';
                            ?>
                        </span>
                </div>
                    <p class="assignment-course"><?php echo htmlspecialchars($assignment['course']); ?></p>
                    <p class="assignment-due">
                        <i class="fas fa-calendar"></i> Due: <?php echo $assignment['due_date']; ?>
                        (<?php echo $assignment['days_until']; ?> days)
                    </p>
                </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-tasks"></i></div>
                <p class="empty-title">No Upcoming Assignments</p>
                <p class="empty-text">All caught up! No pending assignments.</p>
                </div>
            <?php endif; ?>
            </div>

        <!-- Tab: Recent Activity -->
        <div id="activity-<?php echo $child['id']; ?>" class="tab-content-area">
            <?php if (!empty($child['recent_activities'])): ?>
            <div class="activities-timeline">
                <?php foreach ($child['recent_activities'] as $activity): ?>
                <div class="activity-item">
                    <p class="activity-type">
                        <i class="fas <?php echo $activity['icon']; ?>"></i>
                        <?php echo htmlspecialchars($activity['type']); ?>
                    </p>
                    <p class="activity-details"><?php echo htmlspecialchars($activity['course']); ?></p>
                    <p class="activity-time"><?php echo $activity['time']; ?> (<?php echo $activity['time_ago']; ?> ago)</p>
                    </div>
                <?php endforeach; ?>
                    </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-history"></i></div>
                <p class="empty-title">No Recent Activity</p>
                <p class="empty-text">No activity recorded in the last 7 days.</p>
                    </div>
            <?php endif; ?>
                </div>


    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>
</div>

<script>
function switchChildTab(event, tabId) {
    // Get parent card
    const card = event.target.closest('.child-master-card');
    
    // Hide all tabs in this card
    const tabs = card.querySelectorAll('.tab-content-area');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Remove active from all tab buttons in this card
    const tabButtons = card.querySelectorAll('.child-tab');
    tabButtons.forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    
    // Add active to clicked button
    event.target.classList.add('active');
    
}

function animateProgressBars(container) {
    const progressBars = container.querySelectorAll('.progress-bar[data-progress]');
    
    progressBars.forEach(function(bar) {
        const progress = parseFloat(bar.getAttribute('data-progress')) || 0;
        const progressText = bar.querySelector('.progress-text');
        
        // Remove existing color classes
        bar.classList.remove('progress-low', 'progress-medium', 'progress-high');
        
        // Add appropriate color class based on progress
        if (progress < 25) {
            bar.classList.add('progress-low');
        } else if (progress < 50) {
            bar.classList.add('progress-medium');
        } else {
            bar.classList.add('progress-high');
        }
        
        // Reset width to 0 for animation
        bar.style.width = '0%';
        
        // Show text if progress is high enough
        if (progress >= 10 && progressText) {
            progressText.style.display = 'block';
        }
        
        // Animate to target width
        setTimeout(function() {
            bar.style.width = progress + '%';
            
            // Hide text if progress is too low
            if (progress < 10 && progressText) {
                progressText.style.display = 'none';
            }
        }, 50);
    });
}

</script>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php 
    // Output footer at end of successful rendering
    echo $OUTPUT->footer();
} catch (Exception $e) {
    error_log('Fatal error rendering parent_children.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    // Try to output at least a basic error message
    if (!headers_sent()) {
        echo $OUTPUT->header();
        echo '<div class="alert alert-danger">An error occurred while loading this page. Please try again later.</div>';
        echo $OUTPUT->footer();
    }
} catch (Error $e) {
    error_log('Fatal PHP error in parent_children.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    if (!headers_sent()) {
        echo $OUTPUT->header();
        echo '<div class="alert alert-danger">A fatal error occurred. Please contact support.</div>';
        echo $OUTPUT->footer();
    }
}
?>




