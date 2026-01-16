<?php
/**
 * Parent Dashboard - Reports Page
 * Comprehensive academic and activity reports using student dashboard logic
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_reports.php');
$PAGE->set_title('Reports - Parent Dashboard');
$PAGE->set_heading('Reports');
$PAGE->set_pagelayout('base');

$userid = $USER->id;
$export = optional_param('export', '', PARAM_ALPHA);
$selected_course_param = optional_param('course', 'all', PARAM_RAW);
$selected_course_id = ($selected_course_param === 'all' || $selected_course_param == 0 || empty($selected_course_param)) ? 'all' : (int)$selected_course_param;

// Include child session manager and student dashboard functions
require_once(__DIR__ . '/../lib/child_session.php');
require_once(__DIR__ . '/../lib/get_parent_children.php');
require_once(__DIR__ . '/../lib/grade_data.php');
require_once(__DIR__ . '/../dashboard/dashboard_stats_function.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

$selected_child = get_selected_child();
$children = get_parent_children($userid);

// Get selected child data
$selected_child_data = null;
$selected_child_id = null;
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    foreach ($children as $child) {
        if ($child['id'] == $selected_child) {
            $selected_child_data = $child;
            $selected_child_id = $child['id'];
            break;
        }
    }
}

// Do NOT auto-select first child - user must explicitly select a child
// This matches the behavior of parent_schedule.php

// Get child courses
$child_courses = [];
if ($selected_child_id) {
    $enrolled = enrol_get_users_courses($selected_child_id, true, 'id, fullname, shortname, summary, enablecompletion');
    foreach ($enrolled as $course) {
        $child_courses[$course->id] = $course;
    }
}

// Fetch data using student dashboard functions (same as student reports)
$student_gradebook = null;
$student_dashboard_stats = null;
$student_course_stats = null;
$student_activity_stats = null;

// All data fetched from real Moodle database - no mock data used

// Fetch real data when a child is selected (works even if no courses enrolled)
if ($selected_child_id) {
    try {
    // Get gradebook snapshot (same as student dashboard)
        // This function should handle cases where student has no courses
    $student_gradebook = remui_kids_get_user_gradebook_snapshot($selected_child_id);
        if (!is_array($student_gradebook)) {
            $student_gradebook = ['totals' => [], 'distribution' => [], 'courses' => []];
        }
        // Ensure all required keys exist
        if (!isset($student_gradebook['totals'])) $student_gradebook['totals'] = [];
        if (!isset($student_gradebook['distribution'])) $student_gradebook['distribution'] = [];
        if (!isset($student_gradebook['courses'])) $student_gradebook['courses'] = [];
    } catch (Exception $e) {
        debugging('Error fetching gradebook for child ' . $selected_child_id . ': ' . $e->getMessage());
        $student_gradebook = ['totals' => [], 'distribution' => [], 'courses' => []];
    }
    
    try {
    // Get dashboard statistics (same as student dashboard)
        // This function should handle cases where student has no courses
    $student_dashboard_stats = get_real_dashboard_stats($selected_child_id);
        if (!is_array($student_dashboard_stats)) {
            $student_dashboard_stats = ['total_courses' => 0, 'lessons_completed' => 0, 'activities_completed' => 0, 'overall_progress' => 0];
        }
        // Ensure all required keys exist
        if (!isset($student_dashboard_stats['total_courses'])) $student_dashboard_stats['total_courses'] = 0;
        if (!isset($student_dashboard_stats['lessons_completed'])) $student_dashboard_stats['lessons_completed'] = 0;
        if (!isset($student_dashboard_stats['activities_completed'])) $student_dashboard_stats['activities_completed'] = 0;
        if (!isset($student_dashboard_stats['overall_progress'])) $student_dashboard_stats['overall_progress'] = 0;
    } catch (Exception $e) {
        debugging('Error fetching dashboard stats for child ' . $selected_child_id . ': ' . $e->getMessage());
        $student_dashboard_stats = ['total_courses' => 0, 'lessons_completed' => 0, 'activities_completed' => 0, 'overall_progress' => 0];
    }
    
    try {
    // Get detailed course statistics
        // This function should handle cases where student has no courses
    $student_course_stats = get_detailed_course_stats($selected_child_id);
        if (!is_array($student_course_stats)) {
            $student_course_stats = [];
        }
    } catch (Exception $e) {
        debugging('Error fetching course stats for child ' . $selected_child_id . ': ' . $e->getMessage());
        $student_course_stats = [];
    }
    
    try {
    // Get activity type statistics
        // This function should handle cases where student has no courses
    $student_activity_stats = get_activity_type_stats($selected_child_id);
        if (!is_array($student_activity_stats)) {
            $student_activity_stats = [];
        }
    } catch (Exception $e) {
        debugging('Error fetching activity stats for child ' . $selected_child_id . ': ' . $e->getMessage());
        $student_activity_stats = [];
}

    // Use real data only - no mock data fallback
    // If data is empty, arrays will remain empty and UI will show appropriate empty states
} else {
    // No child selected, use empty arrays
    $student_gradebook = ['totals' => [], 'distribution' => [], 'courses' => []];
    $student_dashboard_stats = ['total_courses' => 0, 'lessons_completed' => 0, 'activities_completed' => 0, 'overall_progress' => 0];
    $student_course_stats = [];
    $student_activity_stats = [];
}

// CSV Export functionality (from parent_grades.php)
if ($export === 'csv' && $selected_child_id) {
    $filename = 'grades_' . $selected_child_id . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $csv = fopen('php://output', 'w');
    
    // Write CSV header
    fputcsv($csv, ['Course', 'Percentage', 'Letter Grade', 'Grade Items', 'Updated']);
    
    // Write grade data
    if ($student_gradebook && !empty($student_gradebook['courses'])) {
        foreach ($student_gradebook['courses'] as $course) {
            if (isset($course['course_grade_percentage'])) {
                $grade_items_count = isset($course['grade_items']) ? count($course['grade_items']) : 0;
                $last_updated = '';
                if (!empty($course['grade_items'])) {
                    $last_item = end($course['grade_items']);
                    $last_updated = isset($last_item['timegraded']) ? date('Y-m-d', $last_item['timegraded']) : '';
                }
                
                fputcsv($csv, [
                    $course['course_name'],
                    number_format($course['course_grade_percentage'], 2) . '%',
                    $course['course_letter_grade'] ?? '-',
                    $grade_items_count,
                    $last_updated
                ]);
            }
        }
    }
    
    fclose($csv);
    exit;
}

// Additional reports data using student reports logic
$reports_data = [
    'attendance' => [],
    'quizzes' => [],
    'assignments' => [],
    'submissions' => [],
    'course_completion' => [],
    'activity_log' => []
];

// All data fetched from real Moodle database - no mock data used

// Fetch real data when a child is selected (even if no courses enrolled)
if ($selected_child_id) {
    // Get course IDs if available, otherwise use empty array
    // Apply course filter if specific course is selected
    if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
        // Filter to only selected course
        $course_ids = [$selected_course_id];
    } else {
        // All courses
        $course_ids = !empty($child_courses) ? array_keys($child_courses) : [];
    }
    $course_ids_sql = !empty($course_ids) ? implode(',', $course_ids) : '0'; // Use '0' to prevent SQL errors
    
    // Attendance reports - based on student login activity (if student logs in, mark as present)
    try {
        // Get daily login activity from logstore_standard_log
        // Each day the student logs in counts as present attendance
        $ninety_days_ago = time() - (DAYSECS * 90);
        
        $login_records = $DB->get_records_sql(
            "SELECT DISTINCT 
                    DATE(FROM_UNIXTIME(timecreated)) as login_date,
                    MIN(timecreated) as first_login_time,
                    COUNT(*) as login_count
             FROM {logstore_standard_log}
             WHERE userid = ?
               AND action = 'loggedin'
               AND timecreated >= ?
             GROUP BY DATE(FROM_UNIXTIME(timecreated))
             ORDER BY login_date DESC
             LIMIT 90",
            [$selected_child_id, $ninety_days_ago]
        );
        
        // Convert login records to attendance format
        $reports_data['attendance'] = [];
        foreach ($login_records as $login) {
            // Create attendance record object
            $attendance_record = new stdClass();
            $attendance_record->id = strtotime($login->login_date);
            $attendance_record->studentid = $selected_child_id;
            $attendance_record->status = 1; // Present (logged in)
            $attendance_record->takenby = 0; // System tracked
            $attendance_record->timetaken = $login->first_login_time;
            $attendance_record->firstname = '';
            $attendance_record->lastname = '';
            $attendance_record->coursename = 'Daily Login'; // General attendance, not course-specific
            $attendance_record->login_count = $login->login_count; // Number of logins that day
            
            $reports_data['attendance'][] = $attendance_record;
        }
        
        // Note: We only store present days (logins) in the attendance array
        // Absent days are calculated dynamically for statistics (weekdays without login)
        // This keeps the data cleaner and more efficient
        
        // Sort by timetaken descending
        usort($reports_data['attendance'], function($a, $b) {
            return $b->timetaken - $a->timetaken;
        });
        
    } catch (Exception $e) {
        debugging('Attendance reports query failed: ' . $e->getMessage());
        $reports_data['attendance'] = [];
    }

    // Quiz Performance reports
    try {
        if (!empty($course_ids)) {
        $reports_data['quizzes'] = $DB->get_records_sql(
            "SELECT qa.id, qa.quiz, qa.userid, qa.sumgrades, qa.timestart, qa.timefinish,
                    q.name as quizname, q.grade as maxgrade, c.fullname AS coursename
             FROM {quiz_attempts} qa
             JOIN {quiz} q ON q.id = qa.quiz
             JOIN {course} c ON c.id = q.course
                 WHERE q.course IN (" . $course_ids_sql . ")
               AND qa.userid = ?
               AND qa.state = 'finished'
               AND qa.timefinish > 0
             ORDER BY qa.timefinish DESC",
            [$selected_child_id],
            0,
            50
        );
        } else {
            $reports_data['quizzes'] = [];
        }
    } catch (Exception $e) {
        debugging('Quiz reports query failed: ' . $e->getMessage());
        $reports_data['quizzes'] = [];
    }

    // Assignments reports
    try {
        if (!empty($course_ids)) {
        $reports_data['assignments'] = $DB->get_records_sql(
            "SELECT a.id, a.name, a.duedate, a.grade, c.fullname AS coursename, c.id as courseid
             FROM {assign} a
             JOIN {course} c ON c.id = a.course
                 WHERE a.course IN (" . $course_ids_sql . ")
               AND a.duedate > 0
             ORDER BY a.duedate DESC",
            [],
            0,
            50
        );
        } else {
            $reports_data['assignments'] = [];
        }
    } catch (Exception $e) {
        debugging('Assignments reports query failed: ' . $e->getMessage());
        $reports_data['assignments'] = [];
    }

    // Assignment Submissions reports
    try {
        if (!empty($course_ids)) {
        $reports_data['submissions'] = $DB->get_records_sql(
            "SELECT s.id, s.assignment, s.userid, s.status, s.timemodified, s.timecreated,
                    a.name as assignmentname, a.duedate, c.fullname AS coursename
             FROM {assign_submission} s
             JOIN {assign} a ON a.id = s.assignment
             JOIN {course} c ON c.id = a.course
                 WHERE a.course IN (" . $course_ids_sql . ")
               AND s.userid = ?
             ORDER BY s.timemodified DESC",
            [$selected_child_id],
            0,
            50
        );
        } else {
            $reports_data['submissions'] = [];
        }
    } catch (Exception $e) {
        debugging('Submissions reports query failed: ' . $e->getMessage());
        $reports_data['submissions'] = [];
    }

    // Course Completion reports
    try {
        if (!empty($course_ids)) {
        $reports_data['course_completion'] = $DB->get_records_sql(
            "SELECT cc.id, cc.course, cc.userid, cc.timecompleted,
                    c.fullname AS coursename
             FROM {course_completions} cc
             JOIN {course} c ON c.id = cc.course
                 WHERE cc.course IN (" . $course_ids_sql . ")
               AND cc.userid = ?
             ORDER BY cc.timecompleted DESC",
            [$selected_child_id],
            0,
            30
        );
        } else {
            $reports_data['course_completion'] = [];
        }
    } catch (Exception $e) {
        debugging('Course completion reports query failed: ' . $e->getMessage());
        $reports_data['course_completion'] = [];
    }

    // Activity Log reports
    try {
        if (!empty($course_ids)) {
        $reports_data['activity_log'] = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.action, l.target, l.objecttable,
                    c.fullname AS coursename
             FROM {logstore_standard_log} l
             JOIN {course} c ON c.id = l.courseid
                 WHERE l.courseid IN (" . $course_ids_sql . ")
               AND l.userid = ?
               AND l.timecreated >= ?
             ORDER BY l.timecreated DESC",
            [$selected_child_id, time() - (DAYSECS * 30)],
            0,
            100
        );
        } else {
            $reports_data['activity_log'] = [];
        }
    } catch (Exception $e) {
        debugging('Activity log reports query failed: ' . $e->getMessage());
        $reports_data['activity_log'] = [];
    }
    
    // Recent Resources (from logstore_standard_log - recently accessed/viewed resources)
    $recent_resources = [];
    try {
        $fs = get_file_storage();
        if (!empty($course_ids)) {
            list($course_in_sql, $course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'course');
            
            $log_params = array_merge($course_params, [
                'userid' => $selected_child_id,
                'recent_time' => time() - (30 * 24 * 60 * 60), // Last 30 days
                'resource_action' => 'viewed',
                'resource_target' => 'resource',
                'folder_target' => 'folder',
                'page_target' => 'page',
                'file_target' => 'file',
                'modlevel' => CONTEXT_MODULE
            ]);
            
            $sql_recent_resources = "SELECT DISTINCT lsl.contextinstanceid as cmid, lsl.timecreated as access_time,
                                            cm.id as cm_id, m.name as modname, 
                                            c.id as courseid, c.fullname as coursename,
                                            CASE 
                                                WHEN m.name = 'resource' THEN r.name
                                                WHEN m.name = 'folder' THEN f.name
                                                WHEN m.name = 'page' THEN p.name
                                                ELSE ''
                                            END as resourcename
                                     FROM {logstore_standard_log} lsl
                                     JOIN {course_modules} cm ON cm.id = lsl.contextinstanceid
                                     JOIN {modules} m ON m.id = cm.module
                                     JOIN {course} c ON c.id = cm.course
                                     LEFT JOIN {resource} r ON r.id = cm.instance AND m.name = 'resource'
                                     LEFT JOIN {folder} f ON f.id = cm.instance AND m.name = 'folder'
                                     LEFT JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
                                     WHERE lsl.userid = :userid
                                     AND lsl.courseid $course_in_sql
                                     AND lsl.timecreated > :recent_time
                                     AND lsl.action = :resource_action
                                     AND lsl.target IN (:resource_target, :folder_target, :page_target, :file_target)
                                     AND lsl.contextlevel = :modlevel
                                     AND cm.visible = 1
                                     AND cm.deletioninprogress = 0
                                     AND m.name IN ('resource', 'folder', 'page', 'file')
                                     ORDER BY lsl.timecreated DESC
                                     LIMIT 100";
            
            $accessed_resources = $DB->get_records_sql($sql_recent_resources, $log_params);
            
            // Process each accessed resource to get file details
            $processed_resource_ids = [];
            foreach ($accessed_resources as $log_entry) {
                $cmid = $log_entry->cmid ?? $log_entry->cm_id;
                if (!$cmid || in_array($cmid, $processed_resource_ids)) {
                    continue;
                }
                $processed_resource_ids[] = $cmid;
                
                try {
                    $modinfo = get_fast_modinfo($log_entry->courseid);
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm || !$cm->uservisible) continue;
                    
                    $file_url = '';
                    $filename = '';
                    $filesize = 0;
                    $mimetype = 'application/octet-stream';
                    
                    // Get file details based on module type
                    if ($cm->modname === 'resource') {
                        // Try to get the actual file first
                        try {
                            $context = context_module::instance($cmid);
                            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id DESC', false);
                            if (!empty($files)) {
                                $file = reset($files);
                                $filename = $file->get_filename();
                                $filesize = $file->get_filesize();
                                $mimetype = $file->get_mimetype();
                                
                                // Use pluginfile URL for actual file downloads
                                $file_url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    $file->get_itemid(),
                                    $file->get_filepath(),
                                    $file->get_filename(),
                                    true
                                )->out(false);
                            } else {
                                $filename = $log_entry->resourcename ?: $cm->name;
                                // Use our custom parent theme page for resource pages
                                if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                    $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                        'cmid' => $cmid,
                                        'child' => $selected_child_id,
                                        'courseid' => $log_entry->courseid
                                    ]))->out();
                                }
                            }
                        } catch (Exception $e) {
                            $filename = $log_entry->resourcename ?: $cm->name;
                            // Use our custom parent theme page for resource pages
                            if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                    'cmid' => $cmid,
                                    'child' => $selected_child_id,
                                    'courseid' => $log_entry->courseid
                                ]))->out();
                            }
                        }
                    } elseif ($cm->modname === 'folder') {
                        if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                            $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                'cmid' => $cmid,
                                'child' => $selected_child_id,
                                'courseid' => $log_entry->courseid
                            ]))->out();
                        }
                        $filename = $log_entry->resourcename ?: $cm->name;
                    } elseif ($cm->modname === 'page') {
                        if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                            $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                'cmid' => $cmid,
                                'child' => $selected_child_id,
                                'courseid' => $log_entry->courseid
                            ]))->out();
                        }
                        $filename = $log_entry->resourcename ?: $cm->name;
                    } elseif ($cm->modname === 'file') {
                        try {
                            $context = context_module::instance($cmid);
                            $files = $fs->get_area_files($context->id, 'mod_file', 'content', 0, 'id DESC', false);
                            if (!empty($files)) {
                                $file = reset($files);
                                $filename = $file->get_filename();
                                $filesize = $file->get_filesize();
                                $mimetype = $file->get_mimetype();
                                
                                // Use pluginfile URL for actual file downloads
                                $file_url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    $file->get_itemid(),
                                    $file->get_filepath(),
                                    $file->get_filename(),
                                    true
                                )->out(false);
                            } else {
                                $filename = $log_entry->resourcename ?: $cm->name;
                                // Use our custom parent theme page for file pages
                                if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                    $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                        'cmid' => $cmid,
                                        'child' => $selected_child_id,
                                        'courseid' => $log_entry->courseid
                                    ]))->out();
                                }
                            }
                        } catch (Exception $e) {
                            $filename = $log_entry->resourcename ?: $cm->name;
                            // Use our custom parent theme page for file pages
                            if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                    'cmid' => $cmid,
                                    'child' => $selected_child_id,
                                    'courseid' => $log_entry->courseid
                                ]))->out();
                            }
                        }
                    } else {
                        continue;
                    }
                    
                    $recent_resources[] = [
                        'filename' => $filename,
                        'filesize' => $filesize,
                        'timecreated' => $log_entry->access_time,
                        'coursename' => $log_entry->coursename,
                        'resourcename' => $log_entry->resourcename ?: $filename,
                        'mimetype' => $mimetype,
                        'cmid' => $cmid,
                        'courseid' => $log_entry->courseid,
                        'file_url' => $file_url,
                        'child_id' => $selected_child_id
                    ];
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        
        // Sort by access time (most recent first)
        usort($recent_resources, function($a, $b) {
            return $b['timecreated'] - $a['timecreated'];
        });
    } catch (Exception $e) {
        debugging('Error fetching recent resources from logstore: ' . $e->getMessage());
        $recent_resources = [];
    }
    
    // Real data fetched - arrays will be empty if no data exists, which is fine
    // No need to fallback to mock data - show empty state instead
} else {
    // No child selected - keep empty arrays
    // Reports will show empty state
}

// Initialize chart variables to prevent JavaScript syntax errors
$present_count = 0;
$attendance_count = 0;
$upcoming = 0;
$past = 0;
$completed_courses = 0;
$total_enrolled = 0;
$submission_status_breakdown = ['submitted' => 0, 'draft' => 0, 'new' => 0];

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<style>
/* ============================================
   🎨 PROFESSIONAL UI SYSTEM - PARENT REPORTS
   Modern, Elegant, and Highly Functional
   ============================================ */

:root {
    /* Professional Light Neutral Color Palette */
    --primary-gradient: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    --secondary-gradient: linear-gradient(135deg, #cbd5e1 0%, #94a3b8 100%);
    --success-gradient: linear-gradient(135deg, #86efac 0%, #4ade80 100%);
    --warning-gradient: linear-gradient(135deg, #fcd34d 0%, #fbbf24 100%);
    --accent-gradient: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
    --info-gradient: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    
    /* Light Professional Color Variants */
    --neutral-light: #f8fafc;
    --neutral-medium: #f1f5f9;
    --neutral-soft: #e2e8f0;
    --warm-light: #fef3c7;
    --warm-medium: #fde68a;
    --warm-soft: #fcd34d;
    --cool-light: #f0fdf4;
    --cool-medium: #dcfce7;
    --cool-soft: #bbf7d0;
    --stone-light: #fafaf9;
    --stone-medium: #f5f5f4;
    --stone-soft: #e7e5e4;
    --cream-light: #fefce8;
    --cream-medium: #fef9c3;
    
    /* Professional Neutral Shadows */
    --shadow-xs: 0 1px 3px rgba(0, 0, 0, 0.08);
    --shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.10);
    --shadow-md: 0 10px 20px rgba(0, 0, 0, 0.12);
    --shadow-lg: 0 15px 30px rgba(0, 0, 0, 0.15);
    --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.18);
    
    /* Text Colors */
    --text-primary: #334155;
    --text-secondary: #64748b;
    --text-light: #94a3b8;
    --text-dark: #1e293b;
    
    /* Background Colors */
    --bg-base: #ffffff;
    --bg-soft: #fafafa;
    --bg-light: #f5f5f5;
    
    /* Border Colors - Neutral */
    --border-light: #e5e7eb;
    --border-soft: rgba(148, 163, 184, 0.2);
    
    /* Modern Typography */
    --font-display: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    --font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    
    /* Smooth Transitions */
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-slow: 350ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-bounce: 500ms cubic-bezier(0.68, -0.55, 0.265, 1.55);
    
    /* Professional Animation Durations */
    --anim-fast: 0.2s;
    --anim-base: 0.3s;
    --anim-slow: 0.5s;
    --anim-slower: 0.8s;
    
    /* Professional Accent Colors - Purple, Blue, Green, Yellow, Orange */
    --color-primary: #8b5cf6;
    --color-secondary: #3b82f6;
    --color-success: #10b981;
    --color-warning: #f59e0b;
    --color-danger: #f97316;
    --color-info: #06b6d4;
    --color-purple: #8b5cf6;
    --color-blue: #3b82f6;
    --color-green: #10b981;
    --color-yellow: #f59e0b;
    --color-orange: #f97316;
}

/* ============================================
   🎬 SMOOTH SCROLLING & GLOBAL EFFECTS
   ============================================ */

html {
    scroll-behavior: smooth;
}

* {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Professional Loading States */
@keyframes shimmer {
    0% {
        background-position: -1000px 0;
    }
    100% {
        background-position: 1000px 0;
    }
}

.loading-shimmer {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 1000px 100%;
    animation: shimmer 2s infinite;
}

/* Professional Focus States */
*:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
    border-radius: 4px;
}

/* Smooth Transitions for All Interactive Elements */
button, a, input, select, textarea {
    transition: all var(--transition-base);
}

/* ============================================
   📐 LAYOUT & STRUCTURE
   ============================================ */

/* Premium Professional Layout - Full Page */
.parent-main-content {
    margin-left: 280px;
    margin-top: 0;
    padding: 0;
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
    }
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0 !important;
        width: 100% !important;
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
    
    body {
        font-size: 14px !important;
    }
}
    min-height: 100vh;
    background: linear-gradient(180deg, #fafbfc 0%, #ffffff 50%, #f8fafc 100%);
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    position: relative;
    overflow-x: hidden;
    animation: fadeInPage 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

.parent-main-content::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 20%, rgba(102, 126, 234, 0.03) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(118, 75, 162, 0.03) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

@keyframes fadeInPage {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.reports-main-container {
    background: transparent;
    margin: 0;
    padding: 56px 72px;
    min-height: 100vh;
    border-radius: 0;
    position: relative;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    animation: slideInUp 1s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes headerSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Light Pattern Overlay - Removed colorful gradients */
.reports-main-container::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    height: 100vh;
    background: transparent;
    pointer-events: none;
    z-index: 0;
}

@keyframes softFloat {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(20px, -20px) scale(1.05); }
    66% { transform: translate(-15px, 15px) scale(0.98); }
}

.reports-main-container > * {
    position: relative;
    z-index: 1;
}

/* ============================================
   🔖 PROFESSIONAL NAVIGATION TABS
   ============================================ */

/* Premium Navigation - Glassmorphism */
.nav-tabs-container {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border-radius: 24px;
    padding: 20px 24px;
    margin: 0 0 40px 0;
    box-shadow: 
        0 12px 32px rgba(0, 0, 0, 0.08),
        0 6px 16px rgba(0, 0, 0, 0.06),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.8);
    overflow-x: auto;
    overflow-y: hidden;
    position: relative;
    z-index: 100;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    animation: slideDown 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.nav-tabs-container:hover {
    box-shadow: 
        0 16px 40px rgba(0, 0, 0, 0.10),
        0 8px 20px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 1);
    border-color: rgba(255, 255, 255, 1);
    background: rgba(255, 255, 255, 0.95);
}

.nav-tabs-custom {
    display: flex;
    gap: 8px;
    min-width: max-content;
    padding: 0;
    background: transparent;
    border-radius: 0;
    box-shadow: none;
    border: none;
}

.nav-tab-item {
    padding: 14px 24px;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 15px;
    font-weight: 600;
    color: #334155;
    background: rgba(255, 255, 255, 0.6);
    border: 1px solid rgba(226, 232, 240, 0.6);
    white-space: nowrap;
    position: relative;
    pointer-events: auto;
    user-select: none;
    letter-spacing: 0.3px;
    font-family: var(--font-display);
    transform: translateY(0);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.nav-tab-item::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    width: 0;
    height: 3px;
    background: var(--primary-gradient);
    border-radius: 3px 3px 0 0;
    transform: translateX(-50%);
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-tab-item i {
    color: #64748b;
    font-size: 16px;
}

/* Hover State - Premium */
.nav-tab-item:hover {
    background: rgba(255, 255, 255, 0.9);
    color: #0f172a;
    transform: translateY(-3px);
    border-color: rgba(226, 232, 240, 1);
    box-shadow: 
        0 8px 20px rgba(0, 0, 0, 0.08),
        0 2px 8px rgba(0, 0, 0, 0.04);
}

.nav-tab-item:hover::before {
    width: 70%;
}

.nav-tab-item:hover i {
    color: #667eea;
    transform: scale(1.15) rotate(5deg);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Active State - Premium */
.nav-tab-item.active {
    background: var(--primary-gradient);
    color: #ffffff;
    border-color: transparent;
    box-shadow: 
        0 12px 32px rgba(102, 126, 234, 0.4),
        0 6px 16px rgba(118, 75, 162, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
    font-weight: 700;
    transform: translateY(-3px);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}

.nav-tab-item.active::before {
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.5);
    box-shadow: 0 2px 8px rgba(255, 255, 255, 0.3);
}

.nav-tab-item.active i {
    color: #ffffff;
    transform: scale(1.1);
}


@keyframes gentleBounce {
    0%, 100% { transform: scale(1.2); }
    50% { transform: scale(1.3); }
}

/* Tab Content Sections with Professional Animations */
.tab-content-section {
    display: none !important;
    margin: 0;
    padding: 0;
    padding-top: 0;
    background: transparent;
    border-radius: 0;
    min-height: 400px;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.tab-content-section.active {
    display: block !important;
    opacity: 1;
    transform: translateY(0);
    animation: tabContentFadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes tabContentFadeIn {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes contentFadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================
   📊 MODERN STAT CARDS WITH GLASSMORPHISM
   ============================================ */

.stats-grid-modern {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 24px;
    margin-bottom: 32px;
    padding: 0;
    width: 100%;
}

/* Second row for 6th card */
.stats-grid-modern.has-sixth-card {
    grid-template-columns: repeat(5, 1fr);
}

.stats-grid-modern .stat-card-modern:nth-child(6) {
    grid-column: 1;
    grid-row: 2;
}

@media (max-width: 1400px) {
    .stats-grid-modern {
        grid-template-columns: repeat(3, 1fr);
    }
    .stats-grid-modern .stat-card-modern:nth-child(6) {
        grid-column: auto;
        grid-row: auto;
    }
}

@media (max-width: 1024px) {
    .stats-grid-modern {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid-modern {
        grid-template-columns: 1fr;
    }
}

/* Premium Stat Card - Enhanced GAME VIP Style with Gradients */
.stat-card-modern {
    background: #ffffff;
    border-radius: 24px;
    padding: 36px 32px;
    box-shadow: 
        0 8px 32px rgba(0, 0, 0, 0.12),
        0 4px 16px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: space-between;
    min-height: 200px;
    width: 100%;
    cursor: pointer;
    transform: translateY(0);
    animation: cardFadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1) backwards;
    border: none;
}

/* Decorative Top Border */
.stat-card-modern {
    position: relative;
}

/* Top border decorative element */
.stat-card-modern {
    position: relative;
}

.stat-card-modern::after {
    content: '';
    position: absolute;
    bottom: -20%;
    left: -20%;
    width: 150px;
    height: 150px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    opacity: 0.4;
    transition: all 0.5s ease;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}

.stat-card-modern:hover::after {
    opacity: 0.7;
    transform: scale(1.3);
}

@keyframes cardFadeIn {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Enhanced Decorative Background Elements */
.stat-card-modern::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -30%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
    opacity: 0.6;
    transition: all 0.5s ease;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}

.stat-card-modern::after {
    content: '';
    position: absolute;
    bottom: -20%;
    left: -20%;
    width: 150px;
    height: 150px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    opacity: 0.4;
    transition: all 0.5s ease;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}

.stat-card-modern:hover::before {
    opacity: 0.9;
    transform: scale(1.2);
}

.stat-card-modern:hover::after {
    opacity: 0.7;
    transform: scale(1.3);
}

.stat-card-modern > * {
    position: relative;
    z-index: 1;
}

@keyframes gentleFloat {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(-20px, -20px); }
}

/* Enhanced Hover State - Premium Effects */
.stat-card-modern:hover {
    transform: translateY(-12px) scale(1.03);
    box-shadow: 
        0 20px 48px rgba(0, 0, 0, 0.18),
        0 12px 24px rgba(0, 0, 0, 0.12),
        0 4px 8px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern:hover::after {
    opacity: 1;
    height: 5px;
    background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.8) 50%, transparent 100%);
}

.stat-card-modern:active {
    transform: translateY(-8px) scale(1.02);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Enhanced GAME VIP Style Gradient Backgrounds with Decorative Elements */
.stat-card-modern.purple { 
    background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 30%, #a78bfa 70%, #c4b5fd 100%);
    box-shadow: 
        0 8px 32px rgba(139, 92, 246, 0.25),
        0 4px 16px rgba(139, 92, 246, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.stat-card-modern.purple::before {
    background: radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.25) 0%, transparent 60%);
}

.stat-card-modern.purple:hover {
    box-shadow: 
        0 20px 48px rgba(139, 92, 246, 0.35),
        0 12px 24px rgba(139, 92, 246, 0.25),
        0 4px 8px rgba(139, 92, 246, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern.purple .stat-icon-modern {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(12px);
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 
        0 6px 20px rgba(139, 92, 246, 0.3),
        0 2px 8px rgba(139, 92, 246, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

.stat-card-modern.blue { 
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 30%, #60a5fa 70%, #93c5fd 100%);
    box-shadow: 
        0 8px 32px rgba(59, 130, 246, 0.25),
        0 4px 16px rgba(59, 130, 246, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.stat-card-modern.blue::before {
    background: radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.25) 0%, transparent 60%);
}

.stat-card-modern.blue:hover {
    box-shadow: 
        0 20px 48px rgba(59, 130, 246, 0.35),
        0 12px 24px rgba(59, 130, 246, 0.25),
        0 4px 8px rgba(59, 130, 246, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern.blue .stat-icon-modern {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(12px);
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 
        0 6px 20px rgba(59, 130, 246, 0.3),
        0 2px 8px rgba(59, 130, 246, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

.stat-card-modern.green { 
    background: linear-gradient(135deg, #059669 0%, #10b981 30%, #34d399 70%, #6ee7b7 100%);
    box-shadow: 
        0 8px 32px rgba(16, 185, 129, 0.25),
        0 4px 16px rgba(16, 185, 129, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.stat-card-modern.green::before {
    background: radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.25) 0%, transparent 60%);
}

.stat-card-modern.green:hover {
    box-shadow: 
        0 20px 48px rgba(16, 185, 129, 0.35),
        0 12px 24px rgba(16, 185, 129, 0.25),
        0 4px 8px rgba(16, 185, 129, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern.green .stat-icon-modern {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(12px);
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 
        0 6px 20px rgba(16, 185, 129, 0.3),
        0 2px 8px rgba(16, 185, 129, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

.stat-card-modern.yellow { 
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 30%, #fbbf24 70%, #fcd34d 100%);
    box-shadow: 
        0 8px 32px rgba(245, 158, 11, 0.25),
        0 4px 16px rgba(245, 158, 11, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.stat-card-modern.yellow::before {
    background: radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.25) 0%, transparent 60%);
}

.stat-card-modern.yellow:hover {
    box-shadow: 
        0 20px 48px rgba(245, 158, 11, 0.35),
        0 12px 24px rgba(245, 158, 11, 0.25),
        0 4px 8px rgba(245, 158, 11, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern.yellow .stat-icon-modern {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(12px);
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 
        0 6px 20px rgba(245, 158, 11, 0.3),
        0 2px 8px rgba(245, 158, 11, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

.stat-card-modern.orange { 
    background: linear-gradient(135deg, #ea580c 0%, #f97316 30%, #fb923c 70%, #fdba74 100%);
    box-shadow: 
        0 8px 32px rgba(249, 115, 22, 0.25),
        0 4px 16px rgba(249, 115, 22, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.stat-card-modern.orange::before {
    background: radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.25) 0%, transparent 60%);
}

.stat-card-modern.orange:hover {
    box-shadow: 
        0 20px 48px rgba(249, 115, 22, 0.35),
        0 12px 24px rgba(249, 115, 22, 0.25),
        0 4px 8px rgba(249, 115, 22, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.stat-card-modern.orange .stat-icon-modern {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(12px);
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 
        0 6px 20px rgba(249, 115, 22, 0.3),
        0 2px 8px rgba(249, 115, 22, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

/* Enhanced GAME VIP Style Icon Container - Premium Rounded Square */
.stat-icon-modern {
    width: 72px;
    height: 72px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    margin-bottom: 24px;
    color: #ffffff;
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
    animation: iconFloat 3s ease-in-out infinite;
}

@keyframes iconFloat {
    0%, 100% { 
        transform: translateY(0) rotate(0deg);
    }
    50% { 
        transform: translateY(-5px) rotate(2deg);
    }
}

@keyframes iconPulse {
    0%, 100% {
        box-shadow: 
            0 8px 24px rgba(102, 126, 234, 0.35),
            0 4px 12px rgba(118, 75, 162, 0.25),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
        transform: scale(1);
    }
    50% {
        box-shadow: 
            0 12px 32px rgba(102, 126, 234, 0.45),
            0 6px 16px rgba(118, 75, 162, 0.35),
            inset 0 1px 0 rgba(255, 255, 255, 0.4);
        transform: scale(1.02);
    }
}

/* Soft Shimmer Effect */
.stat-icon-modern::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        135deg, 
        transparent 0%, 
        rgba(255, 255, 255, 0.4) 50%, 
        transparent 100%
    );
    opacity: 0;
    transition: all var(--transition-base);
    transform: translateX(-100%);
}

/* Enhanced Icon Hover - Premium Effects */
.stat-card-modern:hover .stat-icon-modern {
    transform: scale(1.15) rotate(5deg);
    animation: iconHoverPulse 1s ease-in-out infinite;
}

@keyframes iconHoverPulse {
    0%, 100% { 
        transform: scale(1.15) rotate(5deg);
    }
    50% { 
        transform: scale(1.2) rotate(5deg);
    }
}

.stat-card-modern:hover .stat-icon-modern::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.4) 0%, transparent 100%);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

@keyframes iconHoverPulse {
    0%, 100% {
        transform: scale(1.2) rotate(8deg);
    }
    50% {
        transform: scale(1.25) rotate(8deg);
    }
}

.stat-card-modern:hover .stat-icon-modern::before {
    opacity: 1;
    transform: translateX(100%);
    transition: all 0.8s ease;
}

/* Stat Number - Premium Typography */
.stat-number {
    font-size: 42px;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 10px;
    letter-spacing: -1.5px;
    line-height: 1.2;
    margin-bottom: 12px;
    letter-spacing: -1.5px;
    font-family: var(--font-display);
    position: relative;
    display: block;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    color: #ffffff;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.stat-card-modern:hover .stat-number {
    transform: scale(1.05);
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* Stat Label - GAME VIP Style */
.stat-label-modern {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.95);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-family: var(--font-display);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    margin-top: 0;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.stat-card-modern:hover .stat-label-modern {
    color: rgba(255, 255, 255, 1);
    letter-spacing: 1.2px;
    transform: translateY(-2px);
}

/* Advanced Charts Section */
.charts-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 24px;
    margin-bottom: 40px;
}

.chart-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 32px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.10), 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 2px solid rgba(226, 232, 240, 0.8);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    animation: cardFadeIn 0.8s ease-out backwards;
}

.chart-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--primary-gradient);
    opacity: 0;
    transition: opacity 0.4s ease, height 0.4s ease;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.chart-card:hover {
    transform: translateY(-8px) scale(1.01);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.15), 0 8px 20px rgba(0, 0, 0, 0.10);
    border-color: rgba(203, 213, 225, 1);
}

.chart-card:hover::before {
    opacity: 1;
    transform: scaleX(1);
    height: 5px;
}

.chart-card:hover::before {
    opacity: 1;
}

.chart-card h3 {
    font-size: 15px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 2px solid rgba(226, 232, 240, 0.5);
    letter-spacing: -0.2px;
}

.chart-card h3 i {
    color: #64748b;
    font-size: 16px;
}

.chart-wrapper {
    position: relative;
    height: 240px;
    width: 100%;
}

.chart-wrapper.small {
    height: 200px;
}

.chart-wrapper.donut {
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Analytics Cards */
.analytics-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.05);
    border: 2px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.analytics-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--primary-gradient);
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.analytics-card:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: rgba(203, 213, 225, 0.9);
}

.analytics-card:hover::before {
    width: 8px;
    box-shadow: 0 0 40px rgba(100, 116, 139, 0.4);
}

.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
    gap: 16px;
}

.analytics-title {
    font-size: 10px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 5px;
}

.analytics-value {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
    margin: 4px 0 5px 0;
    letter-spacing: -0.3px;
}

.analytics-change {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 4px;
}

.analytics-change.positive {
    background: linear-gradient(135deg, rgba(240, 253, 244, 0.8) 0%, rgba(220, 252, 231, 0.8) 100%);
    color: #16a34a;
}

.analytics-change.negative {
    background: linear-gradient(135deg, rgba(254, 242, 242, 0.8) 0%, rgba(254, 226, 226, 0.8) 100%);
    color: #dc2626;
}

.analytics-change.neutral {
    background: linear-gradient(135deg, rgba(248, 250, 252, 0.8) 0%, rgba(241, 245, 249, 0.8) 100%);
    color: #64748b;
}

.analytics-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    background: var(--primary-gradient);
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25);
}

.insight-card {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 6px;
    padding: 14px;
    border-left: 3px solid #cbd5e1;
    margin-bottom: 12px;
}

.insight-title {
    font-size: 10px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.insight-text {
    font-size: 9px;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.metric-item {
    text-align: center;
    padding: 12px;
    background: #f8fafc;
    border-radius: 6px;
}

.metric-value {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 3px;
}

.metric-label {
    font-size: 9px;
    color: #64748b;
    font-weight: 600;
}

.trend-indicator {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 600;
    padding: 3px 6px;
    border-radius: 4px;
    margin-left: 6px;
}

.trend-indicator.up {
    background: #f1f5f9;
    color: #475569;
}

.trend-indicator.down {
    background: #f8fafc;
    color: #64748b;
}

.trend-indicator.stable {
    background: #f1f5f9;
    color: #64748b;
}

/* Circular Progress Styles */
.circular-progress-container {
    position: relative;
    width: 100px;
    height: 100px;
    margin: 0 auto;
}

.report-table {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 24px;
    padding: 32px;
    box-shadow: 
        0 12px 32px rgba(0, 0, 0, 0.08),
        0 6px 16px rgba(0, 0, 0, 0.06),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
    margin-bottom: 32px;
    overflow-x: auto;
    border: 1px solid rgba(255, 255, 255, 0.8);
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    animation: tableFadeIn 1s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes tableFadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.report-table:hover {
    box-shadow: 
        0 20px 48px rgba(0, 0, 0, 0.12),
        0 10px 24px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 1);
    border-color: rgba(255, 255, 255, 1);
    transform: translateY(-4px);
    background: rgba(255, 255, 255, 1);
}

.report-table table {
    width: 100%;
    border-collapse: collapse;
}

.report-table th {
    padding: 16px 18px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid rgba(148, 163, 184, 0.2);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: linear-gradient(135deg, rgba(248, 250, 252, 0.9) 0%, rgba(241, 245, 249, 0.9) 100%);
    position: relative;
    transition: all 0.3s ease;
}

.report-table th::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 0;
    height: 2px;
    background: var(--primary-gradient);
    transition: width 0.3s ease;
}

.report-table th:hover::after {
    width: 100%;
}

.report-table td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    color: #475569;
    font-size: 13px;
    transition: all 0.2s ease;
    background: #ffffff;
}

.report-table tr {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.report-table tr:hover {
    background: linear-gradient(90deg, rgba(248, 250, 252, 0.8) 0%, rgba(241, 245, 249, 0.8) 100%);
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.report-table td {
    transition: all 0.3s ease;
}

.report-table tr:hover td {
    color: #1e293b;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 60px 40px;
    color: #64748b;
    background: linear-gradient(135deg, rgba(248, 250, 252, 0.8) 0%, rgba(241, 245, 249, 0.8) 100%);
    border-radius: 20px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    border: 2px dashed rgba(203, 213, 225, 0.6);
    margin: 24px 0;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.8;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    display: inline-block;
}

.empty-state h3 {
    font-size: 20px;
    margin: 0 0 12px 0;
    color: #1e293b;
    font-weight: 700;
    letter-spacing: -0.3px;
}

.empty-state p {
    font-size: 14px;
    margin: 0;
    color: #64748b;
    line-height: 1.6;
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 16px;
    }
    
    .nav-tabs-container {
        padding: 4px;
    }
    
    .nav-tab-item {
        padding: 12px 16px;
        font-size: 12px;
    }
    
    .stats-grid-modern {
        grid-template-columns: 1fr;
    }
}

/* ============================================
   🎯 PROFESSIONAL ENHANCEMENTS
   ============================================ */

/* Custom Scrollbar */
.nav-tabs-container::-webkit-scrollbar {
    height: 8px;
}

.nav-tabs-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 12px;
}

.nav-tabs-container::-webkit-scrollbar-thumb {
    background: linear-gradient(90deg, #cbd5e1, #94a3b8, #cbd5e1);
    border-radius: 12px;
    border: 2px solid rgba(255, 255, 255, 0.5);
    transition: all var(--transition-base);
}

.nav-tabs-container::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(90deg, #94a3b8, #64748b, #94a3b8);
    border-color: rgba(255, 255, 255, 0.8);
}

/* Loading Animation */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stat-card-modern {
    animation: fadeInUp 0.6s ease-out forwards;
    opacity: 0;
}

.stat-card-modern:nth-child(1) { animation-delay: 0.1s; }
.stat-card-modern:nth-child(2) { animation-delay: 0.2s; }
.stat-card-modern:nth-child(3) { animation-delay: 0.3s; }
.stat-card-modern:nth-child(4) { animation-delay: 0.4s; }
.stat-card-modern:nth-child(5) { animation-delay: 0.5s; }
.stat-card-modern:nth-child(6) { animation-delay: 0.6s; }

@keyframes iconFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-8px);
    }
}

/* 🎯 Soft Premium Button */
.btn-premium {
    padding: 14px 28px;
    background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%);
    color: #065f46;
    text-decoration: none;
    border-radius: 16px;
    font-size: 14px;
    font-weight: 700;
    border: 2px solid rgba(255, 255, 255, 0.8);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 
        0 4px 12px rgba(0, 0, 0, 0.1),
        inset 0 1px 0 rgba(255, 255, 255, 0.6);
    letter-spacing: 0.3px;
    transition: all var(--transition-base);
    position: relative;
    overflow: hidden;
    cursor: pointer;
    font-family: var(--font-display);
}

.btn-premium::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), transparent);
    opacity: 0;
    transition: opacity var(--transition-base);
}

.btn-premium:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 
        0 12px 36px rgba(110, 231, 183, 0.4),
        inset 0 1px 0 rgba(255, 255, 255, 0.8);
    background: linear-gradient(135deg, #6ee7b7 0%, #34d399 100%);
}

.btn-premium:hover::before {
    opacity: 1;
}

.btn-premium:active {
    transform: translateY(-1px) scale(0.98);
}

/* Badge Styles */
.badge-modern {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-family: var(--font-display);
}

.badge-success {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.18) 0%, rgba(5, 150, 105, 0.18) 100%);
    color: #3b82f6;
    border: 1px solid rgba(16, 185, 129, 0.3);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
}

.badge-warning {
    background: linear-gradient(135deg, rgba(250, 158, 11, 0.15) 0%, rgba(251, 146, 60, 0.15) 100%);
    color: #f59e0b;
    border: 1px solid rgba(250, 158, 11, 0.3);
    box-shadow: 0 2px 8px rgba(250, 158, 11, 0.15);
}

.badge-info {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(5, 150, 105, 0.15) 100%);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15);
}

/* Professional Table Styles */
.table-modern {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 
        0 4px 12px rgba(0, 0, 0, 0.05),
        0 1px 3px rgba(0, 0, 0, 0.03);
}

.table-modern thead {
    background: var(--primary-gradient);
}

.table-modern th {
    padding: 16px 20px;
    text-align: left;
    font-size: 12px;
    font-weight: 800;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
    font-family: var(--font-display);
}

.table-modern td {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.6);
    font-size: 14px;
    color: var(--gray-700);
    transition: all var(--transition-base);
}

.table-modern tbody tr {
    transition: all var(--transition-base);
}

.table-modern tbody tr:hover {
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.08) 100%);
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1);
}

.table-modern tbody tr:last-child td {
    border-bottom: none;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
    background: linear-gradient(135deg, #fafbfc 0%, #f8fafc 100%);
    border-radius: 20px;
    border: 2px dashed rgba(226, 232, 240, 0.8);
    margin: 20px 0;
}

.empty-state-modern i {
    font-size: 64px;
    color: var(--gray-400);
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state-modern p {
    font-size: 16px;
    color: var(--gray-600);
    font-weight: 600;
    margin: 0;
}

/* 📊 Soft Progress Bar */
.progress-bar-modern {
    height: 10px;
    background: #f8fafc;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    border: 2px solid rgba(255, 255, 255, 0.6);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.03);
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #a7f3d0 0%, #6ee7b7 50%, #34d399 100%);
    border-radius: 10px;
    transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(110, 231, 183, 0.3);
}

.progress-bar-fill::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, 
        transparent, 
        rgba(255, 255, 255, 0.5), 
        transparent);
    animation: softShimmer 2.5s infinite;
}

@keyframes softShimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Tooltip */
.tooltip-modern {
    position: relative;
    display: inline-block;
}

.tooltip-modern::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(5px);
    background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
    color: #ffffff;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: all var(--transition-base);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    z-index: 1000;
}

.tooltip-modern:hover::after {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

/* Print Styles */
@media print {
    .nav-tabs-container,
    .btn-premium,
    .parent-sidebar {
        display: none !important;
    }
    
    .parent-main-content {
        margin-left: 0;
        width: 100%;
    }
    
    .stat-card-modern {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
</style>

<div class="parent-main-content">
    <div class="reports-main-container">
        <!-- Header Section with Professional Effects -->
        <div style="background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); padding: 40px 48px; margin-bottom: 32px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10), 0 4px 12px rgba(0, 0, 0, 0.08); border-radius: 0; position: relative; overflow: hidden; animation: headerSlideIn 0.8s cubic-bezier(0.4, 0, 0.2, 1);">
            <div style="position: absolute; top: 0; right: 0; width: 300px; height: 300px; background: radial-gradient(circle, rgba(148, 163, 184, 0.05) 0%, transparent 70%); pointer-events: none; z-index: 0;"></div>
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 32px; width: 100%; margin: 0; position: relative; z-index: 1;">
                <div style="display: flex; align-items: center; gap: 24px; flex: 1;">
                    <!-- Large Professional Icon with Animation -->
                    <div style="width: 80px; height: 80px; background: var(--primary-gradient); border-radius: 20px; display: flex; align-items: center; justify-content: center; box-shadow: 0 12px 32px rgba(100, 116, 139, 0.3), 0 4px 12px rgba(100, 116, 139, 0.2); flex-shrink: 0; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); animation: iconFloat 3s ease-in-out infinite; cursor: pointer;" onmouseover="this.style.transform='scale(1.1) rotate(5deg)'; this.style.boxShadow='0 16px 40px rgba(100, 116, 139, 0.4), 0 6px 16px rgba(100, 116, 139, 0.3)';" onmouseout="this.style.transform='scale(1) rotate(0deg)'; this.style.boxShadow='0 12px 32px rgba(100, 116, 139, 0.3), 0 4px 12px rgba(100, 116, 139, 0.2)';">
                        <i class="fas fa-chart-line" style="font-size: 36px; color: #ffffff; transition: transform 0.3s ease;"></i>
                    </div>
                    <div>
                        <h1 style="font-size: 40px; font-weight: 900; margin: 0 0 8px 0; color: #1e293b; letter-spacing: -1px; line-height: 1.2;">
                            Academic Reports
                        </h1>
                        <p style="margin: 0; font-size: 15px; color: #64748b; font-weight: 600;">
                            Comprehensive performance analytics and insights
                        </p>
                    </div>
                </div>
                
                <!-- Date Badge -->
                <div style="background: #ffffff; border: 2px solid rgba(226, 232, 240, 0.8); border-radius: 16px; padding: 16px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); flex-shrink: 0;">
                    <div style="width: 44px; height: 44px; background: rgba(241, 245, 249, 0.8); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-calendar-alt" style="color: #64748b; font-size: 20px;"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px;">REPORT DATE</div>
                        <div style="font-size: 16px; font-weight: 800; color: #1e293b;"><?php echo date('M j, Y'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Area - Full Width -->
        <div style="padding: 0; width: 100%;">
    <?php if (!empty($children)): ?>
        <?php if ($selected_child_data && $selected_child_id): ?>
        <?php else: ?>
            <!-- ⚠️ No Child Selected Warning -->
            <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 2px solid rgba(245, 158, 11, 0.4); padding: 32px; border-radius: 20px; margin-bottom: 24px; text-align: center; box-shadow: 0 8px 24px rgba(245, 158, 11, 0.15), 0 2px 8px rgba(245, 158, 11, 0.1); position: relative; overflow: hidden;">
                <!-- Decorative Elements -->
                <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(245, 158, 11, 0.1) 0%, transparent 70%); pointer-events: none;"></div>
                <div style="position: absolute; bottom: -40px; left: -40px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(245, 158, 11, 0.08) 0%, transparent 70%); pointer-events: none;"></div>
                
                <div style="position: relative; z-index: 1;">
                    <!-- Warning Icon -->
                    <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 12px 32px rgba(245, 158, 11, 0.3), inset 0 2px 0 rgba(255, 255, 255, 0.3);">
                        <i class="fas fa-exclamation-triangle" style="color: white; font-size: 36px; filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.2));"></i>
                    </div>
                    
                    <h3 style="color: #92400e; margin: 0 0 12px 0; font-size: 22px; font-weight: 900; letter-spacing: -0.5px; font-family: 'Inter', sans-serif;">No Student Selected</h3>
                    <p style="color: #d97706; margin: 0 0 24px 0; font-size: 14px; font-weight: 600; max-width: 400px; margin-left: auto; margin-right: auto;">Please select a child from your dashboard to view their comprehensive academic reports and performance analytics.</p>
                    
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
                       class="btn-premium"
                       style="display: inline-flex; background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);">
                        <i class="fas fa-arrow-left"></i>
                        <span>Go to Dashboard</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selected_child_id): ?>
        <!-- Filter Section - Select Year and Select Grade -->
        <div style="display: flex; gap: 16px; margin-bottom: 24px; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 14px; font-weight: 600; color: #334155;">Select Year:</label>
                <select id="yearFilter" style="padding: 10px 16px; border: 2px solid rgba(226, 232, 240, 0.8); border-radius: 12px; font-size: 14px; font-weight: 600; color: #334155; background: #ffffff; cursor: pointer; min-width: 150px;">
                    <option value="all">All</option>
                    <option value="2024">2024</option>
                    <option value="2025">2025</option>
                </select>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 14px; font-weight: 600; color: #334155;">Select Grade:</label>
                <select id="gradeFilter" style="padding: 10px 16px; border: 2px solid rgba(226, 232, 240, 0.8); border-radius: 12px; font-size: 14px; font-weight: 600; color: #334155; background: #ffffff; cursor: pointer; min-width: 150px;">
                    <option value="all">All</option>
                    <option value="1">Grade 1</option>
                    <option value="2">Grade 2</option>
                    <option value="3">Grade 3</option>
                    <option value="4">Grade 4</option>
                    <option value="5">Grade 5</option>
                </select>
            </div>
        </div>

        <!-- Student Info & Course Filter Section -->
        <div style="display: flex; gap: 24px; margin-bottom: 32px; align-items: stretch;">
            <!-- Student Profile Card -->
            <div style="background: #ffffff; padding: 28px 32px; border-radius: 20px; border: 2px solid rgba(226, 232, 240, 0.8); box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); display: flex; align-items: center; justify-content: space-between; gap: 24px; flex: 1;">
                <div style="display: flex; align-items: center; gap: 24px; flex: 1; position: relative; z-index: 1;">
                    <!-- Large Student Icon with Animation -->
                    <div style="width: 72px; height: 72px; background: var(--primary-gradient); border-radius: 20px; display: flex; align-items: center; justify-content: center; box-shadow: 0 12px 32px rgba(100, 116, 139, 0.3), 0 4px 12px rgba(100, 116, 139, 0.2); flex-shrink: 0; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); animation: iconFloat 3s ease-in-out infinite;" onmouseover="this.style.transform='scale(1.15) rotate(5deg)'; this.style.boxShadow='0 16px 40px rgba(100, 116, 139, 0.4), 0 6px 16px rgba(100, 116, 139, 0.3)';" onmouseout="this.style.transform='scale(1) rotate(0deg)'; this.style.boxShadow='0 12px 32px rgba(100, 116, 139, 0.3), 0 4px 12px rgba(100, 116, 139, 0.2)';">
                        <i class="fas fa-user" style="color: #ffffff; font-size: 32px; transition: transform 0.3s ease;"></i>
                    </div>
                    <div style="flex: 1; position: relative; z-index: 1;">
                        <?php 
                        $child_name = htmlspecialchars($selected_child_data['name'] ?? '');
                        // Split name into parts if it contains a space
                        $name_parts = explode(' ', $child_name, 2);
                        if (count($name_parts) > 1) {
                            $first_part = $name_parts[0];
                            $second_part = $name_parts[1];
                        } else {
                            $first_part = $child_name;
                            $second_part = 'Student';
                        }
                        ?>
                        <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                            <span style="font-size: 24px; font-weight: 900; color: #1e293b;"><?php echo $first_part; ?></span>
                            <span style="font-size: 32px; font-weight: 900; color: #1e293b;"><?php echo $second_part; ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px;">
                            <span style="display: inline-block; width: 10px; height: 10px; background: #64748b; border-radius: 50%; box-shadow: 0 0 12px rgba(100, 116, 139, 0.5);"></span>
                            Active Student
                        </div>
                    </div>
                </div>
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
                   style="padding: 12px 24px; background: rgba(241, 245, 249, 0.8); border: 2px solid rgba(226, 232, 240, 0.8); border-radius: 12px; color: #334155; text-decoration: none; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 10px; transition: all 0.3s; flex-shrink: 0;"
                   onmouseover="this.style.background='rgba(248, 250, 252, 0.9)'; this.style.borderColor='rgba(203, 213, 225, 0.9)'; this.style.transform='translateY(-2px)';"
                   onmouseout="this.style.background='rgba(241, 245, 249, 0.8)'; this.style.borderColor='rgba(226, 232, 240, 0.8)'; this.style.transform='translateY(0)';">
                    <i class="fas fa-exchange-alt"></i>
                    Change Child
                </a>
            </div>
            
            <!-- Course Filter Card with Professional Effects -->
            <div style="background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); padding: 24px 28px; border-radius: 20px; border: 2px solid rgba(226, 232, 240, 0.8); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10), 0 4px 12px rgba(0, 0, 0, 0.08); width: 380px; flex-shrink: 0; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; animation: cardFadeIn 0.8s ease-out 0.2s backwards;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 32px rgba(0, 0, 0, 0.15), 0 6px 16px rgba(0, 0, 0, 0.12)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 24px rgba(0, 0, 0, 0.10), 0 4px 12px rgba(0, 0, 0, 0.08)';">
                <div style="position: absolute; top: -30px; left: -30px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(148, 163, 184, 0.06) 0%, transparent 70%); pointer-events: none; z-index: 0;"></div>
                <label style="display: block; font-size: 11px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-filter" style="color: #64748b; font-size: 14px;"></i>
                    FILTER BY COURSE
                </label>
                <select id="courseFilter" onchange="filterByCourse(this.value)" 
                        style="width: 100%; padding: 14px 18px; border: 2px solid rgba(226, 232, 240, 0.8); border-radius: 12px; font-size: 15px; font-weight: 600; color: #334155; background: #ffffff; cursor: pointer; transition: all 0.3s; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 16 16\'><path fill=\'%2364748b\' d=\'M8 11L3 6h10z\'/></svg>'); background-repeat: no-repeat; background-position: right 18px center; padding-right: 48px;"
                        onmouseover="this.style.borderColor='rgba(203, 213, 225, 0.9)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.1)';"
                        onmouseout="this.style.borderColor='rgba(226, 232, 240, 0.8)'; this.style.boxShadow='none';">
                    <option value="all" <?php echo ($selected_course_id === 'all' || $selected_course_id == 0) ? 'selected' : ''; ?>>All Courses</option>
                    <?php if (!empty($child_courses)): ?>
                        <?php foreach ($child_courses as $course): ?>
                            <option value="<?php echo $course->id; ?>" <?php echo ($selected_course_id == $course->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <!-- Horizontal Tabs Navigation -->
        <div class="nav-tabs-container" style="margin-bottom: 32px;">
            <div class="nav-tabs-custom">
                <?php 
                // Get current tab from URL or default to overview
                $current_tab = optional_param('tab', 'overview', PARAM_ALPHA);
                $tabs = [
                    'overview' => ['icon' => 'fa-chart-bar', 'label' => 'Overview'],
                    'grades' => ['icon' => 'fa-percent', 'label' => 'Grades'],
                    'attendance' => ['icon' => 'fa-calendar', 'label' => 'Attendance'],
                    'quizzes' => ['icon' => 'fa-question', 'label' => 'Quizzes'],
                    'assignments' => ['icon' => 'fa-file-alt', 'label' => 'Assignments'],
                    'resources' => ['icon' => 'fa-folder', 'label' => 'Recent Resources'],
                    'progress' => ['icon' => 'fa-chart-line', 'label' => 'Course Progress'],
                    'submissions' => ['icon' => 'fa-upload', 'label' => 'Submissions'],
                    'completion' => ['icon' => 'fa-check', 'label' => 'Course Completion'],
                    'activity' => ['icon' => 'fa-clock', 'label' => 'Activity Log']
                ];
                foreach ($tabs as $tabKey => $tabInfo):
                    $isActive = ($current_tab === $tabKey || ($current_tab === '' && $tabKey === 'overview'));
                ?>
                <div class="nav-tab-item <?php echo $isActive ? 'active' : ''; ?>" 
                     data-tab="<?php echo $tabKey; ?>" 
                     onclick="if(typeof window.switchReportTab==='function'){window.switchReportTab('<?php echo $tabKey; ?>');}return false;" 
                     style="cursor: pointer;">
                    <i class="fas <?php echo $tabInfo['icon']; ?>"></i> <?php echo $tabInfo['label']; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- REPORTS CONTENT - Only show when child is selected -->
        <div id="reports-content-wrapper" style="background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); border-radius: 20px; padding: 40px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10), 0 4px 12px rgba(0, 0, 0, 0.08); border: 2px solid rgba(226, 232, 240, 0.8); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); animation: contentFadeIn 0.8s ease-out;">
        <!-- OVERVIEW TAB -->
        <?php $current_tab = optional_param('tab', 'overview', PARAM_ALPHA); ?>
        <div class="tab-content-section <?php echo ($current_tab === 'overview' || $current_tab === '') ? 'active' : ''; ?>" id="tab-overview">
            <?php 
            // Prepare chart data
            $course_names = [];
            $course_grades = [];
            $distribution = [];
            $progress_labels = [];
            $progress_data = [];
            $activity_types = [];
            $activity_completion = [];
            $monthly_labels = [];
            $monthly_progress = [];
            $monthly_grades = [];

            // Safely process gradebook data with course filter
            if (isset($student_gradebook) && is_array($student_gradebook)) {
                if (!empty($student_gradebook['courses']) && is_array($student_gradebook['courses'])) {
                    $filtered_courses = $student_gradebook['courses'];
                    
                    // Apply course filter if specific course is selected
                    if ($selected_course_id !== 'all' && $selected_course_id > 0) {
                        $filtered_courses = array_filter($filtered_courses, function($course) use ($selected_course_id, $child_courses) {
                            // Match by course ID if available in course data
                            if (isset($course['course_id']) && $course['course_id'] == $selected_course_id) {
                                return true;
                            }
                            // Try to match by course name if we have the course info
                            if (isset($child_courses[$selected_course_id])) {
                                $selected_course_name = $child_courses[$selected_course_id]->fullname;
                                $course_name = $course['course_name'] ?? '';
                                return (stripos($course_name, $selected_course_name) !== false || stripos($selected_course_name, $course_name) !== false);
                            }
                            return false;
                        });
                    }
                    
                    foreach (array_slice($filtered_courses, 0, 6) as $course) {
                        if (is_array($course)) {
                            $course_name = substr($course['course_name'] ?? 'Course', 0, 20);
                            $course_grade = isset($course['course_grade_percentage']) ? (float)$course['course_grade_percentage'] : 0;
                            // Only add if we have a valid grade (not null and not 0, or if 0 is a valid grade)
                            if ($course_name && ($course_grade !== null)) {
                                $course_names[] = $course_name;
                                $course_grades[] = $course_grade;
                            }
                        }
                    }
                }
                if (isset($student_gradebook['distribution']) && is_array($student_gradebook['distribution'])) {
                    $distribution = $student_gradebook['distribution'];
                    // Ensure distribution has at least some values
                    $has_distribution = false;
                    foreach (['A', 'B', 'C', 'D', 'F'] as $grade) {
                        if (isset($distribution[$grade]) && $distribution[$grade] > 0) {
                            $has_distribution = true;
                            break;
            }
                    }
                    if (!$has_distribution) {
                        $distribution = []; // Reset if no actual data
                    }
                }
            }

            // Safely process course stats with course filter
            if (isset($student_course_stats) && is_array($student_course_stats) && !empty($student_course_stats)) {
                $filtered_stats = $student_course_stats;
                
                // Apply course filter if specific course is selected
                if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
                    $selected_course_name = $child_courses[$selected_course_id]->fullname;
                    $filtered_stats = array_filter($filtered_stats, function($stat) use ($selected_course_id, $selected_course_name) {
                        if (is_object($stat)) {
                            $stat_course_id = $stat->courseid ?? $stat->id ?? 0;
                            $stat_course_name = $stat->fullname ?? '';
                            return ($stat_course_id == $selected_course_id || stripos($stat_course_name, $selected_course_name) !== false);
                        } elseif (is_array($stat)) {
                            $stat_course_id = $stat['courseid'] ?? $stat['id'] ?? 0;
                            $stat_course_name = $stat['fullname'] ?? '';
                            return ($stat_course_id == $selected_course_id || stripos($stat_course_name, $selected_course_name) !== false);
                        }
                        return false;
                    });
                }
                
                foreach (array_slice($filtered_stats, 0, 6) as $stat) {
                    if (is_object($stat)) {
                        $progress_labels[] = substr($stat->fullname ?? 'Course', 0, 20);
                        $progress_data[] = isset($stat->progress_percentage) ? (float)$stat->progress_percentage : 0;
                    } elseif (is_array($stat)) {
                        $progress_labels[] = substr($stat['fullname'] ?? 'Course', 0, 20);
                        $progress_data[] = isset($stat['progress_percentage']) ? (float)$stat['progress_percentage'] : 0;
                }
            }
            }

            // Safely process activity stats
            if (isset($student_activity_stats) && is_array($student_activity_stats) && !empty($student_activity_stats)) {
                foreach (array_slice($student_activity_stats, 0, 5) as $stat) {
                    if (is_object($stat)) {
                    $activity_types[] = ucwords(str_replace('_', ' ', $stat->modulename ?? ''));
                        $activity_completion[] = isset($stat->completion_rate) ? (float)$stat->completion_rate : 0;
                    } elseif (is_array($stat)) {
                        $activity_types[] = ucwords(str_replace('_', ' ', $stat['modulename'] ?? ''));
                        $activity_completion[] = isset($stat['completion_rate']) ? (float)$stat['completion_rate'] : 0;
                    }
                }
            }

            // Calculate real Examination Results by Branch (Course) from quiz attempts
            $examination_results = [];
            $examination_subjects = [];
            $examination_pass = [];
            $examination_fail = [];
            $examination_not_attended = [];
            
            if ($selected_child_id && !empty($reports_data['quizzes'])) {
                // Group quiz attempts by course/subject
                $course_quiz_stats = [];
                
                foreach ($reports_data['quizzes'] as $quiz) {
                    $course_name = $quiz->coursename ?? 'Unknown';
                    $maxgrade = $quiz->maxgrade ?? 1;
                    $sumgrades = $quiz->sumgrades ?? 0;
                    $percentage = $maxgrade > 0 ? ($sumgrades / $maxgrade) * 100 : 0;
                    
                    if (!isset($course_quiz_stats[$course_name])) {
                        $course_quiz_stats[$course_name] = [
                            'total' => 0,
                            'pass' => 0,
                            'fail' => 0,
                            'not_attended' => 0
                        ];
                    }
                    
                    $course_quiz_stats[$course_name]['total']++;
                    
                    // Consider passed if >= 50%, failed if < 50% and attempted, not attended if no attempt
                    if ($percentage >= 50) {
                        $course_quiz_stats[$course_name]['pass']++;
                    } elseif ($sumgrades > 0 || $quiz->timefinish > 0) {
                        $course_quiz_stats[$course_name]['fail']++;
                    } else {
                        $course_quiz_stats[$course_name]['not_attended']++;
                    }
                }
                
                // Also check for quizzes that exist but have no attempts (not attended)
                if (!empty($course_ids)) {
                    try {
                        $course_ids_sql = implode(',', $course_ids);
                        $all_quizzes = $DB->get_records_sql(
                            "SELECT q.id, q.name, q.course, c.fullname as coursename
                             FROM {quiz} q
                             JOIN {course} c ON c.id = q.course
                             WHERE q.course IN (" . $course_ids_sql . ")
                             ORDER BY c.fullname, q.name",
                            []
                        );
                        
                        foreach ($all_quizzes as $quiz) {
                            $course_name = $quiz->coursename ?? 'Unknown';
                            
                            // Check if student has attempted this quiz
                            $has_attempt = false;
                            foreach ($reports_data['quizzes'] as $attempt) {
                                if ($attempt->quiz == $quiz->id) {
                                    $has_attempt = true;
                                    break;
                                }
                            }
                            
                            if (!$has_attempt) {
                                if (!isset($course_quiz_stats[$course_name])) {
                                    $course_quiz_stats[$course_name] = [
                                        'total' => 0,
                                        'pass' => 0,
                                        'fail' => 0,
                                        'not_attended' => 0
                                    ];
                                }
                                $course_quiz_stats[$course_name]['not_attended']++;
                            }
                        }
                    } catch (Exception $e) {
                        debugging('Error fetching all quizzes for examination results: ' . $e->getMessage());
                    }
                }
                
                // Convert to arrays for chart (limit to top 5 courses)
                $sorted_courses = [];
                foreach ($course_quiz_stats as $course_name => $stats) {
                    $sorted_courses[] = [
                        'name' => $course_name,
                        'pass' => $stats['pass'],
                        'fail' => $stats['fail'],
                        'not_attended' => $stats['not_attended'],
                        'total' => $stats['total']
                    ];
                }
                
                // Sort by total attempts (descending) and take top 5
                usort($sorted_courses, function($a, $b) {
                    return $b['total'] - $a['total'];
                });
                
                foreach (array_slice($sorted_courses, 0, 5) as $course_data) {
                    $examination_subjects[] = substr($course_data['name'], 0, 20);
                    $examination_pass[] = $course_data['pass'];
                    $examination_fail[] = $course_data['fail'];
                    $examination_not_attended[] = $course_data['not_attended'];
                }
            }
            ?>
            <?php 
            // Calculate advanced analytics with safe access
            $total_courses = isset($student_dashboard_stats) && is_array($student_dashboard_stats) ? ($student_dashboard_stats['total_courses'] ?? 0) : 0;
            $lessons_completed = isset($student_dashboard_stats) && is_array($student_dashboard_stats) ? ($student_dashboard_stats['lessons_completed'] ?? 0) : 0;
            $activities_completed = isset($student_dashboard_stats) && is_array($student_dashboard_stats) ? ($student_dashboard_stats['activities_completed'] ?? 0) : 0;
            $overall_progress = isset($student_dashboard_stats) && is_array($student_dashboard_stats) ? ($student_dashboard_stats['overall_progress'] ?? 0) : 0;
            // Calculate average grade - filter by course if selected
            $avg_grade = 0;
            if (isset($student_gradebook) && is_array($student_gradebook) && isset($student_gradebook['courses']) && is_array($student_gradebook['courses'])) {
                $filtered_gradebook_courses = $student_gradebook['courses'];
                
                // Filter by selected course if not "all"
                if ($selected_course_id !== 'all' && $selected_course_id > 0) {
                    $filtered_gradebook_courses = array_filter($filtered_gradebook_courses, function($course) use ($selected_course_id, $child_courses) {
                        if (isset($course['course_id']) && $course['course_id'] == $selected_course_id) {
                            return true;
                        }
                        if (isset($child_courses[$selected_course_id])) {
                            $selected_course_name = $child_courses[$selected_course_id]->fullname;
                            $course_name = $course['course_name'] ?? '';
                            return (stripos($course_name, $selected_course_name) !== false || stripos($selected_course_name, $course_name) !== false);
                        }
                        return false;
                    });
                }
                
                // Calculate average from filtered courses
                $total_grade = 0;
                $grade_count = 0;
                foreach ($filtered_gradebook_courses as $course) {
                    if (isset($course['course_grade_percentage']) && $course['course_grade_percentage'] !== null) {
                        $total_grade += (float)$course['course_grade_percentage'];
                        $grade_count++;
                    }
                }
                $avg_grade = $grade_count > 0 ? ($total_grade / $grade_count) : 0;
            } elseif (isset($student_gradebook) && is_array($student_gradebook) && isset($student_gradebook['totals']) && is_array($student_gradebook['totals']) && isset($student_gradebook['totals']['average_percentage'])) {
                $avg_grade = (float)$student_gradebook['totals']['average_percentage'];
            }
            $completed_courses = isset($reports_data['course_completion']) && is_array($reports_data['course_completion']) ? count($reports_data['course_completion']) : 0;
            
            // Calculate trends from real data
            // Get previous month data for comparison
            $previous_month_progress = 0;
            $previous_month_grade = 0;
            
            // Calculate trends based on historical data if available
            $progress_trend = 'N/A';
            if ($selected_child_id) {
                try {
                    // Get completion data from 30 days ago
                    $thirty_days_ago = time() - (DAYSECS * 30);
                    $previous_completions = $DB->count_records_sql(
                        "SELECT COUNT(*) 
                         FROM {course_modules_completion} cmc 
                         JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id 
                         JOIN {course} c ON cm.course = c.id 
                         WHERE cmc.userid = ? AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1
                         AND cmc.timemodified < ?",
                        [$selected_child_id, $thirty_days_ago]
                    );
                    
                    $current_completions = $activities_completed;
                    if ($previous_completions > 0) {
                        $progress_trend = round((($current_completions - $previous_completions) / $previous_completions) * 100, 1);
                        $progress_trend = ($progress_trend > 0 ? '+' : '') . $progress_trend . '%';
                    } else {
                        $progress_trend = 'N/A';
                    }
                } catch (Exception $e) {
                    $progress_trend = 'N/A';
                }
            }
            
            // Grade trend calculation
            try {
                // Get average grade from gradebook
                $current_avg = $avg_grade;
                // For now, set trend based on current performance
                if ($current_avg >= 90) {
                    $grade_trend = 'Excellent';
                } elseif ($current_avg >= 80) {
                    $grade_trend = 'Very Good';
                } elseif ($current_avg >= 70) {
                    $grade_trend = 'Good';
                } else {
                    $grade_trend = 'Needs Improvement';
                }
            } catch (Exception $e) {
                $grade_trend = 'N/A';
            }
            $completion_rate = $total_courses > 0 ? ($completed_courses / $total_courses) * 100 : 0;
            $activity_rate = $activities_completed > 0 ? ($activities_completed / ($activities_completed + 10)) * 100 : 0;
            
            // Performance insights
            $performance_level = 'Excellent';
            $performance_color = '#64748b';
            if ($avg_grade < 70) {
                $performance_level = 'Needs Improvement';
                $performance_color = '#94a3b8';
            } elseif ($avg_grade < 80) {
                $performance_level = 'Good';
                $performance_color = '#94a3b8';
            } elseif ($avg_grade < 90) {
                $performance_level = 'Very Good';
                $performance_color = '#64748b';
            }
            ?>
            
            <?php if ($student_dashboard_stats): ?>
            <!-- Key Performance Indicators - Professional Stat Cards Layout -->
            <div class="stats-grid-modern <?php echo (($student_gradebook && !empty($student_gradebook['totals'])) || !empty($reports_data['course_completion'])) ? 'has-sixth-card' : ''; ?>" style="margin-bottom: 40px;">
                <!-- Card 1: Total Courses -->
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <div class="stat-number"><?php echo $total_courses; ?></div>
                    <div class="stat-label-modern">Total Courses</div>
                </div>

                <!-- Card 2: Lessons Completed -->
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="stat-number"><?php echo $lessons_completed; ?></div>
                    <div class="stat-label-modern">Lessons Completed</div>
                </div>

                <!-- Card 3: Activities Completed -->
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-number"><?php echo $activities_completed; ?></div>
                    <div class="stat-label-modern">Activities Completed</div>
                </div>

                <!-- Card 4: Overall Progress -->
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($overall_progress, 1); ?>%</div>
                    <div class="stat-label-modern">Overall Progress</div>
                </div>

                <!-- Card 5: Average Grade -->
                <?php if ($student_gradebook && !empty($student_gradebook['totals'])): ?>
                <div class="stat-card-modern yellow">
                    <div class="stat-icon-modern">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($avg_grade, 1); ?>%</div>
                    <div class="stat-label-modern">Average Grade</div>
                </div>
                <?php else: ?>
                <!-- Placeholder if no grade data -->
                <div class="stat-card-modern yellow" style="opacity: 0.6;">
                    <div class="stat-icon-modern">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="stat-number">0.0%</div>
                    <div class="stat-label-modern">Average Grade</div>
                </div>
                <?php endif; ?>

                <!-- Card 6: Completed Courses (Bottom Row) -->
                <?php if (!empty($reports_data['course_completion'])): ?>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div class="stat-number"><?php echo count($reports_data['course_completion']); ?></div>
                    <div class="stat-label-modern">Completed Courses</div>
                </div>
                <?php else: ?>
                <!-- Placeholder if no completion data -->
                <div class="stat-card-modern purple" style="opacity: 0.6;">
                    <div class="stat-icon-modern">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div class="stat-number">0</div>
                    <div class="stat-label-modern">Completed Courses</div>
                </div>
                <?php endif; ?>
            </div>
            

            <!-- Charts Section -->
            <?php if ($selected_child_id): ?>
            <div class="charts-section">
                <!-- Students count by Grade and Gender - Donut Chart -->
                <?php if (!empty($course_names) && !empty($course_grades) && count($course_names) > 0 && count($course_grades) > 0): ?>
                <div class="chart-card" style="border-left: 4px solid rgba(139, 92, 246, 0.3);">
                    <h3 style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid rgba(139, 92, 246, 0.1);">
                        <div style="width: 40px; height: 40px; background: rgba(139, 92, 246, 0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(139, 92, 246, 0.2);">
                            <i class="fas fa-chart-pie" style="color: #8b5cf6; font-size: 16px;"></i>
                    </div>
                        <span style="font-size: 18px; font-weight: 800; color: #1e293b; letter-spacing: -0.3px;">Students count by Grade and Gender</span>
                        <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
                        <span style="font-size: 11px; font-weight: 700; color: #10b981; padding: 6px 12px; background: rgba(16, 185, 129, 0.1); border-radius: 6px; margin-left: auto; border: 1px solid rgba(16, 185, 129, 0.2);">
                            Filtered View
                        </span>
                        <?php endif; ?>
                    </h3>
                    <div class="chart-wrapper" style="height: 280px;">
                        <canvas id="coursePerformanceChart"></canvas>
                </div>
                    </div>
                        <?php else: ?>
                <!-- Show placeholder when no course data -->
                <div class="chart-card" style="background: #f8fafc; border: 2px dashed #e2e8f0;">
                    <h3><i class="fas fa-chart-line" style="color: #94a3b8;"></i> Course Performance</h3>
                    <div class="chart-wrapper" style="display: flex; align-items: center; justify-content: center; min-height: 200px;">
                        <div style="text-align: center; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p style="margin: 0; font-size: 14px;">No course grades available yet</p>
                </div>
                    </div>
                </div>
                <script>console.log('PHP: Course Performance Chart NOT shown - Names count: <?php echo count($course_names); ?>, Grades count: <?php echo count($course_grades); ?>');</script>
                <?php endif; ?>

                <!-- Examination Results by Branch - Bar Chart -->
                        <?php 
                $has_examination_data = !empty($examination_subjects) && !empty($examination_pass);
                ?>
                <?php if ($has_examination_data): ?>
                <div class="chart-card" style="border-left: 4px solid #3b82f6;">
                    <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-chart-bar" style="color: white; font-size: 14px;"></i>
                </div>
                        <span style="font-size: 16px; font-weight: 700; color: #0f172a;">Examination Results by Branch</span>
                    </h3>
                    <div class="chart-wrapper" style="height: 280px;">
                        <canvas id="gradeDistributionChart"></canvas>
            </div>
                    </div>
                <?php else: ?>
                <!-- Show placeholder when no examination data -->
                <div class="chart-card" style="background: #f8fafc; border: 2px dashed #e2e8f0;">
                    <h3><i class="fas fa-chart-bar" style="color: #94a3b8;"></i> Examination Results by Branch</h3>
                    <div class="chart-wrapper" style="display: flex; align-items: center; justify-content: center; min-height: 200px;">
                        <div style="text-align: center; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p style="margin: 0; font-size: 14px;">No examination results data yet</p>
                </div>
                    </div>
                </div>
                <script>console.log('PHP: Examination Results Chart NOT shown - No quiz data available');</script>
                <?php endif; ?>

                <!-- Average Subject Score - Gauge Charts -->
                <?php if (!empty($progress_labels) && !empty($progress_data) && count($progress_labels) > 0 && count($progress_data) > 0): ?>
                <div class="chart-card" style="border-left: 4px solid #10b981;">
                    <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-tachometer-alt" style="color: white; font-size: 14px;"></i>
                        </div>
                        <span style="font-size: 16px; font-weight: 700; color: #0f172a;">Average Subject Score</span>
                    </h3>
                    <div class="chart-wrapper" style="height: 280px;">
                        <canvas id="progressTrendChart"></canvas>
                    </div>
                </div>
                <?php else: ?>
                <!-- Show placeholder when no progress data -->
                <div class="chart-card" style="background: #f8fafc; border: 2px dashed #e2e8f0;">
                    <h3><i class="fas fa-tachometer-alt" style="color: #94a3b8;"></i> Average Subject Score</h3>
                    <div class="chart-wrapper" style="display: flex; align-items: center; justify-content: center; min-height: 200px;">
                        <div style="text-align: center; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <p style="margin: 0; font-size: 12px;">No subject score data available yet</p>
                        </div>
                    </div>
                </div>
                <script>console.log('PHP: Average Subject Score Chart NOT shown - Labels count: <?php echo count($progress_labels); ?>, Data count: <?php echo count($progress_data); ?>');</script>
                <?php endif; ?>

                <!-- Activity Summary Chart - Simplified -->
                <?php if (!empty($activity_types) && !empty($activity_completion) && count($activity_types) > 0 && count($activity_completion) > 0): ?>
                <div class="chart-card" style="border-left: 4px solid #ec4899;">
                    <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #ec4899, #db2777); border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-chart-bar" style="color: white; font-size: 14px;"></i>
                        </div>
                        <span style="font-size: 16px; font-weight: 700; color: #0f172a;">Activity Completion</span>
                    </h3>
                    <div class="chart-wrapper" style="height: 280px;">
                        <canvas id="activityChart"></canvas>
                    </div>
                </div>
                <?php else: ?>
                <script>console.log('PHP: Activity Chart NOT shown - Types count: <?php echo count($activity_types); ?>, Completion count: <?php echo count($activity_completion); ?>');</script>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Students Details Table -->
            <?php if ($selected_child_id && $selected_child_data): ?>
            <div class="chart-card" style="margin-top: 40px; border-left: 4px solid #8b5cf6;">
                <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid rgba(139, 92, 246, 0.1);">
                    <div style="width: 40px; height: 40px; background: rgba(139, 92, 246, 0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(139, 92, 246, 0.2);">
                        <i class="fas fa-table" style="color: #8b5cf6; font-size: 16px;"></i>
                    </div>
                    <span style="font-size: 18px; font-weight: 800; color: #1e293b; letter-spacing: -0.3px;">Students Details</span>
                </h3>
                <div style="overflow-x: auto;">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Gender</th>
                                <th>Grade Name</th>
                                <th>Average Marks</th>
                                <th>GPA</th>
                                <th>Attendance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($selected_child_data['name'] ?? 'N/A'); ?></td>
                                <td><?php 
                                    $gender = '';
                                    if (isset($selected_child_data['gender'])) {
                                        $gender = $selected_child_data['gender'] == 1 ? 'Male' : ($selected_child_data['gender'] == 2 ? 'Female' : 'N/A');
                                    } else {
                                        $gender = 'N/A';
                                    }
                                    echo $gender;
                                ?></td>
                                <td><?php 
                                    // Get grade from courses or default
                                    $grade_name = 'N/A';
                                    if (!empty($child_courses)) {
                                        // Try to get grade from first course
                                        $first_course = reset($child_courses);
                                        if (isset($first_course->fullname)) {
                                            // Extract grade from course name if possible
                                            if (preg_match('/Grade\s+(\d+)/i', $first_course->fullname, $matches)) {
                                                $grade_name = 'Grade ' . $matches[1];
                                            } else {
                                                $grade_name = substr($first_course->fullname, 0, 30);
                                            }
                                        }
                                    }
                                    echo htmlspecialchars($grade_name);
                                ?></td>
                                <td style="font-weight: 600; color: <?php echo ($avg_grade < 50) ? '#ef4444' : '#1e293b'; ?>;"><?php echo number_format($avg_grade, 2); ?></td>
                                <td style="font-weight: 600; color: <?php echo ($avg_grade >= 80) ? '#10b981' : '#1e293b'; ?>;"><?php 
                                    // Calculate GPA (4.0 scale)
                                    $gpa = 0;
                                    if ($avg_grade >= 90) $gpa = 4;
                                    elseif ($avg_grade >= 80) $gpa = 3;
                                    elseif ($avg_grade >= 70) $gpa = 2;
                                    elseif ($avg_grade >= 60) $gpa = 1;
                                    else $gpa = 0;
                                    echo $gpa;
                                ?></td>
                                <td style="font-weight: 600; color: <?php echo ($overall_progress >= 90) ? '#10b981' : '#1e293b'; ?>;"><?php echo number_format($overall_progress, 0); ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Child is selected but has no data yet - show helpful message -->
            <div class="empty-state" style="padding: 60px 20px; text-align: center; background: white; border-radius: 12px; border: 2px dashed #e2e8f0;">
                <i class="fas fa-info-circle" style="font-size: 64px; color: #94a3b8; margin-bottom: 20px;"></i>
                <h3 style="color: #0f172a; margin: 0 0 12px 0; font-size: 24px; font-weight: 700;">No Reports Data Yet</h3>
                <p style="color: #64748b; margin: 0; font-size: 16px; line-height: 1.6;">
                    Reports for <strong><?php echo htmlspecialchars($selected_child_data['name'] ?? 'this student'); ?></strong> will appear here once they start participating in courses and activities.
                </p>
                <div style="margin-top: 24px; padding: 16px; background: #f8fafc; border-radius: 8px; text-align: left; max-width: 500px; margin-left: auto; margin-right: auto;">
                    <p style="margin: 0 0 8px 0; color: #475569; font-size: 14px; font-weight: 600;">What you'll see here:</p>
                    <ul style="margin: 0; padding-left: 20px; color: #64748b; font-size: 14px; line-height: 1.8;">
                        <li>Course grades and performance</li>
                        <li>Attendance records</li>
                        <li>Quiz and assignment results</li>
                        <li>Progress tracking</li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- GRADES TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'grades') ? 'active' : ''; ?>" id="tab-grades">
            <?php 
            // Prepare grades chart data - Initialize all arrays
            $grades_chart_courses = [];
            $grades_chart_percentages = [];
            $grades_chart_letters = [];
            $grade_items_data = [];
            $total_courses_with_grades = 0;
            $average_grade_all = 0;
            $highest_grade = 0;
            $lowest_grade = 100;
            $grade_distribution_data = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
            $top_grades = [];
            $top_courses = [];
            
            if ($student_gradebook && !empty($student_gradebook['courses'])) {
                // Apply course filter to grades tab
                $filtered_gradebook_courses = $student_gradebook['courses'];
                if ($selected_course_id !== 'all' && $selected_course_id > 0) {
                    $filtered_gradebook_courses = array_filter($filtered_gradebook_courses, function($course) use ($selected_course_id, $child_courses) {
                        if (isset($course['course_id']) && $course['course_id'] == $selected_course_id) {
                            return true;
                        }
                        if (isset($child_courses[$selected_course_id])) {
                            $selected_course_name = $child_courses[$selected_course_id]->fullname;
                            $course_name = $course['course_name'] ?? '';
                            return (stripos($course_name, $selected_course_name) !== false || stripos($selected_course_name, $course_name) !== false);
                        }
                        return false;
                    });
                }
                
                $total_grades = 0;
                $grade_count = 0;
                
                foreach ($filtered_gradebook_courses as $course) {
                    if (isset($course['course_grade_percentage'])) {
                        $grades_chart_courses[] = substr($course['course_name'] ?? 'Course', 0, 20);
                        $grades_chart_percentages[] = $course['course_grade_percentage'];
                        $grades_chart_letters[] = $course['course_letter_grade'] ?? '-';
                        
                        $total_courses_with_grades++;
                        $total_grades += $course['course_grade_percentage'];
                        $grade_count++;
                        
                        if ($course['course_grade_percentage'] > $highest_grade) {
                            $highest_grade = $course['course_grade_percentage'];
                        }
                        if ($course['course_grade_percentage'] < $lowest_grade) {
                            $lowest_grade = $course['course_grade_percentage'];
                        }
                        
                        // Count grade distribution
                        $letter = $course['course_letter_grade'] ?? '';
                        if (isset($grade_distribution_data[$letter])) {
                            $grade_distribution_data[$letter]++;
                        }
                    }
                    if (!empty($course['grade_items'])) {
                        foreach ($course['grade_items'] as $item) {
                            if (isset($item['percentage'])) {
                                $grade_items_data[] = [
                                    'name' => substr($item['name'] ?? 'Item', 0, 20),
                                    'percentage' => $item['percentage'],
                                    'course' => substr($course['course_name'] ?? 'Course', 0, 15)
                                ];
                            }
                        }
                    }
                }
                
                $average_grade_all = $grade_count > 0 ? $total_grades / $grade_count : 0;
            }
            ?>
            
            <?php 
            // Apply course filter to grades display
            $display_gradebook_courses = $student_gradebook['courses'] ?? [];
            if ($selected_course_id !== 'all' && $selected_course_id > 0 && !empty($display_gradebook_courses)) {
                $display_gradebook_courses = array_filter($display_gradebook_courses, function($course) use ($selected_course_id, $child_courses) {
                    if (isset($course['course_id']) && $course['course_id'] == $selected_course_id) {
                        return true;
                    }
                    if (isset($child_courses[$selected_course_id])) {
                        $selected_course_name = $child_courses[$selected_course_id]->fullname;
                        $course_name = $course['course_name'] ?? '';
                        return (stripos($course_name, $selected_course_name) !== false || stripos($selected_course_name, $course_name) !== false);
                    }
                    return false;
                });
            }
            ?>
            <?php if ($student_gradebook && !empty($display_gradebook_courses)): ?>
            <!-- Dashboard Style Grades Page -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing grades for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            
            <!-- Advanced Analytics Row -->
            <?php 
            $total_grade_items = 0;
            $total_points_earned = 0;
            $total_points_possible = 0;
            $grade_improvement = [];
            $strongest_subject = '';
            $strongest_grade = 0;
            $needs_attention = [];
            
            foreach ($display_gradebook_courses as $course) {
                if (isset($course['course_grade_percentage'])) {
                    $total_grade_items++;
                    $total_points_earned += $course['course_grade_percentage'];
                    $total_points_possible += 100;
                    
                    if ($course['course_grade_percentage'] > $strongest_grade) {
                        $strongest_grade = $course['course_grade_percentage'];
                        $strongest_subject = $course['course_name'];
                    }
                    
                    if ($course['course_grade_percentage'] < 75) {
                        $needs_attention[] = [
                            'course' => $course['course_name'],
                            'grade' => $course['course_grade_percentage']
                        ];
                    }
                }
            }
            
            $overall_average = $total_grade_items > 0 ? $total_points_earned / $total_grade_items : 0;
            $grade_consistency = 0; // Calculate standard deviation would go here
            
            // Calculate real grade improvement trend from database
            $grade_improvement_trend = 'N/A';
            $grade_improvement_class = 'neutral';
            if ($selected_child_id) {
                try {
                    // Get previous period gradebook for comparison
                    // Compare current average with historical data
                    $thirty_days_ago = time() - (DAYSECS * 30);
                    
                    // Get gradebook data from 30+ days ago by checking grade items modified before that time
                    if (isset($student_gradebook['courses']) && is_array($student_gradebook['courses'])) {
                        // For now, we'll use a simpler approach: compare current average with a baseline
                        // In a full implementation, you'd query historical gradebook snapshots
                        // Since we don't have historical snapshots stored, we'll show N/A or calculate from available data
                        $grade_improvement_trend = 'N/A';
                        $grade_improvement_class = 'neutral';
                    }
                } catch (Exception $e) {
                    debugging('Grade improvement trend calculation failed: ' . $e->getMessage());
                    $grade_improvement_trend = 'N/A';
                    $grade_improvement_class = 'neutral';
                }
            }
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Overall GPA</div>
                            <div class="analytics-value"><?php echo number_format($overall_average, 2); ?>%</div>
                            <?php if ($grade_improvement_trend !== 'N/A'): ?>
                            <div class="analytics-change <?php echo $grade_improvement_class; ?>">
                                <i class="fas fa-<?php echo (strpos($grade_improvement_trend, '+') === 0) ? 'arrow-up' : (strpos($grade_improvement_trend, '-') === 0 ? 'arrow-down' : 'minus'); ?>"></i>
                                <?php echo $grade_improvement_trend; ?> vs previous period
                            </div>
                            <?php else: ?>
                            <div class="analytics-change neutral">
                                <i class="fas fa-minus"></i>
                                Trend data not available
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="metric-grid">
                        <div class="metric-item">
                            <div class="metric-value"><?php echo $total_grade_items; ?></div>
                            <div class="metric-label">Graded Courses</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-value"><?php echo number_format($strongest_grade, 1); ?>%</div>
                            <div class="metric-label">Best Subject</div>
                        </div>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Strongest Subject</div>
                            <div class="analytics-value" style="font-size: 24px;"><?php echo htmlspecialchars($strongest_subject); ?></div>
                            <div class="analytics-change positive">
                                <i class="fas fa-certificate"></i>
                                <?php echo number_format($strongest_grade, 1); ?>% Average
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-percent"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Performance</div>
                        <div style="font-size: 16px; font-weight: 700; color: #10b981;">
                            Excellent Performance
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($needs_attention)): ?>
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Areas for Improvement</div>
                            <div class="analytics-value" style="font-size: 24px;"><?php echo count($needs_attention); ?></div>
                            <div class="analytics-change negative">
                                <i class="fas fa-exclamation-circle"></i>
                                Courses below 75%
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <?php foreach (array_slice($needs_attention, 0, 2) as $item): ?>
                        <div style="padding: 8px; background: #fee2e2; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                            <strong><?php echo htmlspecialchars($item['course']); ?></strong> - <?php echo number_format($item['grade'], 1); ?>%
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Button -->
            <?php if ($selected_child_id): ?>
            
            <?php endif; ?>
            
            <!-- Summary Cards Row -->
            <div class="stats-grid-modern" style="margin-bottom: 24px;">
                <div class="stat-card-modern orange" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: white;">
                    <div class="stat-icon-modern" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-number" style="color: white;"><?php echo $total_courses_with_grades; ?></div>
                    <div class="stat-label-modern" style="color: rgba(255,255,255,0.9);">Courses with Grades</div>
                </div>
                
                <div class="stat-card-modern blue" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                    <div class="stat-icon-modern" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="stat-number" style="color: white;"><?php echo number_format($average_grade_all, 1); ?>%</div>
                    <div class="stat-label-modern" style="color: rgba(255,255,255,0.9);">Average Grade</div>
                </div>
                
                <div class="stat-card-modern green" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                    <div class="stat-icon-modern" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-number" style="color: white;"><?php echo number_format($highest_grade, 1); ?>%</div>
                    <div class="stat-label-modern" style="color: rgba(255,255,255,0.9);">Highest Grade</div>
                </div>
                
                <div class="stat-card-modern purple" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white;">
                    <div class="stat-icon-modern" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div class="stat-number" style="color: white;"><?php echo array_sum($grade_distribution_data); ?></div>
                    <div class="stat-label-modern" style="color: rgba(255,255,255,0.9);">Total Grade Items</div>
                </div>
            </div>
            
            <!-- Circular Progress Indicators Row -->
            <?php if (!empty($grades_chart_percentages) && !empty($grades_chart_courses)): 
                $top_grades = array_slice($grades_chart_percentages, 0, 3);
                $top_courses = array_slice($grades_chart_courses, 0, 3);
                $top_letters = array_slice($grades_chart_letters, 0, 3);
            ?>
            <div class="chart-card" style="margin-bottom: 18px;">
                <h3 style="font-size: 15px; font-weight: 700; margin: 0 0 14px 0; color: #0f172a;">Top Course Performance</h3>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <?php foreach ($top_grades as $index => $grade): 
                        $colors = ['#f97316', '#3b82f6', '#10b981'];
                        $color = $colors[$index] ?? '#8b5cf6';
                        $letter = isset($top_letters[$index]) ? $top_letters[$index] : '-';
                    ?>
                    <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 14px; border-radius: 6px; border: 1px solid #e2e8f0; transition: all 0.3s ease;" 
                         onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'; this.style.borderColor='<?php echo $color; ?>';" 
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#e2e8f0';">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 10px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo htmlspecialchars($top_courses[$index] ?? 'Course'); ?>
                            </div>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <span style="font-size: 18px; font-weight: 700; color: <?php echo $color; ?>;">
                                        <?php echo number_format($grade, 1); ?>%
                                    </span>
                                    <span style="font-size: 11px; font-weight: 600; color: <?php echo $color; ?>; padding: 2px 8px; background: <?php echo $color; ?>20; border-radius: 4px;">
                                        <?php echo $letter; ?>
                                    </span>
                        </div>
                        </div>
                        </div>
                        <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; position: relative;">
                            <div style="width: <?php echo min(100, $grade); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $color; ?>, <?php echo $color; ?>cc); transition: width 0.6s ease; border-radius: 4px; box-shadow: 0 0 8px <?php echo $color; ?>40;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Charts Grid Row -->
            <div class="charts-section" style="margin-bottom: 24px;">
                <?php if (!empty($grades_chart_courses)): ?>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Course Grades Comparison</h3>
                    <div class="chart-wrapper">
                        <canvas id="gradesComparisonChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($grade_distribution_data)): ?>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Grade Distribution</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="gradeDistributionDetailedChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Grade Trend and Items Row -->
            <div class="charts-section" style="margin-bottom: 24px;">
                <?php if (!empty($grades_chart_courses)): ?>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Grade Trend Analysis</h3>
                    <div class="chart-wrapper">
                        <canvas id="gradeTrendChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($grade_items_data)): ?>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-area"></i> Grade Items Performance</h3>
                    <div class="chart-wrapper">
                        <canvas id="gradeItemsChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Detailed Gradebook - Tree View -->
            <style>
            .tree-container {
                background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
                border-radius: 8px;
                padding: 18px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
                border: 1px solid rgba(226, 232, 240, 0.8);
            }
            .tree-node {
                margin-bottom: 6px;
            }
            .tree-node-parent {
                cursor: pointer;
                padding: 12px 16px;
                border-radius: 6px;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                transition: all 0.3s ease;
                position: relative;
                margin-bottom: 3px;
            }
            .tree-node-parent:hover {
                border-color: #cbd5e1;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
                transform: translateX(2px);
            }
            .tree-node-parent.expanded {
                border-color: #3b82f6;
                background: linear-gradient(135deg, #f8fafc, #ffffff);
            }
            .tree-node-children {
                margin-left: 32px;
                margin-top: 6px;
                padding-left: 18px;
                border-left: 2px solid #e2e8f0;
                display: none;
            }
            .tree-node-parent.expanded + .tree-node-children {
                display: block;
            }
            .tree-toggle {
                position: absolute;
                left: 16px;
                top: 50%;
                transform: translateY(-50%);
                width: 20px;
                height: 20px;
                border-radius: 4px;
                background: #f1f5f9;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
                color: #64748b;
                font-size: 10px;
            }
            .tree-node-parent.expanded .tree-toggle {
                background: #3b82f6;
                color: white;
                transform: translateY(-50%) rotate(90deg);
            }
            .tree-content {
                margin-left: 36px;
            }
            </style>
            
            <div class="tree-container" style="margin-bottom: 18px;">
                <h2 style="font-size: 18px; font-weight: 700; margin: 0 0 18px 0; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-sitemap" style="color: #3b82f6; font-size: 16px;"></i>
                    Detailed Gradebook
                </h2>
                
                <div class="tree-view">
                    <?php foreach ($student_gradebook['courses'] as $courseIndex => $course): 
                        $course_percentage = isset($course['course_grade_percentage']) ? (float)$course['course_grade_percentage'] : 0;
                        $course_letter = $course['course_letter_grade'] ?? '-';
                        $course_id = 'course-' . $courseIndex;
                        
                        // Determine color based on grade
                        $grade_color = '#ef4444';
                        $grade_bg = 'rgba(239, 68, 68, 0.1)';
                        if ($course_percentage >= 90) {
                            $grade_color = '#10b981';
                            $grade_bg = 'rgba(16, 185, 129, 0.1)';
                        } elseif ($course_percentage >= 80) {
                            $grade_color = '#3b82f6';
                            $grade_bg = 'rgba(59, 130, 246, 0.1)';
                        } elseif ($course_percentage >= 70) {
                            $grade_color = '#f59e0b';
                            $grade_bg = 'rgba(245, 158, 11, 0.1)';
                        }
                    ?>
                    <div class="tree-node">
                        <!-- Course Parent Node -->
                        <div class="tree-node-parent" onclick="toggleTree('<?php echo $course_id; ?>')" id="<?php echo $course_id; ?>-parent">
                            <div class="tree-toggle">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                            <div class="tree-content">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                    <div style="flex: 1; display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 36px; height: 36px; background: linear-gradient(135deg, <?php echo $grade_color; ?>, <?php echo str_replace('0.1', '0.3', $grade_bg); ?>); border-radius: 6px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px <?php echo str_replace('0.1', '0.2', $grade_bg); ?>;">
                                            <i class="fas fa-book" style="font-size: 16px; color: <?php echo $grade_color; ?>;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 3px; letter-spacing: -0.2px;">
                        <?php echo htmlspecialchars($course['course_name'] ?? ''); ?>
                                            </div>
                        <?php if (isset($course['course_grade_percentage'])): ?>
                                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                                <span style="font-size: 20px; font-weight: 700; color: <?php echo $grade_color; ?>; letter-spacing: -0.4px;">
                                                    <?php echo number_format($course_percentage, 1); ?>%
                        </span>
                                                <span style="font-size: 12px; font-weight: 700; color: <?php echo $grade_color; ?>; padding: 3px 8px; background: <?php echo $grade_bg; ?>; border-radius: 4px;">
                                                    <?php echo $course_letter; ?>
                                                </span>
                                            </div>
                        <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (isset($course['course_grade_percentage'])): 
                                        $circumference = 2 * M_PI * 30;
                                        $offset = $circumference - ($course_percentage / 100) * $circumference;
                                    ?>
                                    <div style="width: 60px; height: 60px; position: relative;">
                                        <svg width="60" height="60" style="transform: rotate(-90deg);">
                                            <circle cx="30" cy="30" r="24" stroke="#e2e8f0" stroke-width="4" fill="none"></circle>
                                            <circle cx="30" cy="30" r="24" stroke="<?php echo $grade_color; ?>" stroke-width="4" fill="none" 
                                                    stroke-dasharray="<?php echo number_format($circumference, 2); ?>" 
                                                    stroke-dashoffset="<?php echo number_format($offset, 2); ?>"
                                                    stroke-linecap="round"></circle>
                                        </svg>
                                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                            <div style="font-size: 14px; font-weight: 700; color: #0f172a;"><?php echo number_format($course_percentage, 0); ?></div>
                                            <div style="font-size: 8px; color: #64748b;">%</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Grade Items Children -->
                    <?php if (!empty($course['grade_items'])): ?>
                        <div class="tree-node-children" id="<?php echo $course_id; ?>-children">
                            <?php foreach ($course['grade_items'] as $itemIndex => $item): 
                                $item_percentage = isset($item['percentage']) ? (float)$item['percentage'] : 0;
                                $item_grade = isset($item['grade']) ? (float)$item['grade'] : 0;
                                $item_max = isset($item['max_grade']) ? (float)$item['max_grade'] : 100;
                                $item_letter = $item['letter_grade'] ?? '-';
                                
                                // Determine item color
                                $item_color = '#ef4444';
                                $item_bg = 'rgba(239, 68, 68, 0.1)';
                                if ($item_percentage >= 90) {
                                    $item_color = '#10b981';
                                    $item_bg = 'rgba(16, 185, 129, 0.1)';
                                } elseif ($item_percentage >= 80) {
                                    $item_color = '#3b82f6';
                                    $item_bg = 'rgba(59, 130, 246, 0.1)';
                                } elseif ($item_percentage >= 70) {
                                    $item_color = '#f59e0b';
                                    $item_bg = 'rgba(245, 158, 11, 0.1)';
                                }
                                
                                $is_last = ($itemIndex === count($course['grade_items']) - 1);
                            ?>
                            <div style="position: relative; margin-bottom: <?php echo $is_last ? '0' : '12px'; ?>;">
                                <!-- Tree connector line -->
                                <?php if (!$is_last): ?>
                                <div style="position: absolute; left: -24px; top: 0; bottom: -12px; width: 3px; background: #e2e8f0;"></div>
                    <?php else: ?>
                                <div style="position: absolute; left: -24px; top: 0; height: 50%; width: 3px; background: #e2e8f0;"></div>
                    <?php endif; ?>
                                
                                <!-- Tree branch connector -->
                                <div style="position: absolute; left: -24px; top: 20px; width: 20px; height: 3px; background: #e2e8f0;"></div>
                                
                                <div style="background: #ffffff; border-radius: 6px; padding: 12px; border: 1px solid #f1f5f9; transition: all 0.3s ease; position: relative;" 
                                     onmouseover="this.style.borderColor='<?php echo $item_color; ?>'; this.style.boxShadow='0 2px 8px <?php echo str_replace('0.1', '0.15', $item_bg); ?>'; this.style.transform='translateX(2px)'" 
                                     onmouseout="this.style.borderColor='#f1f5f9'; this.style.boxShadow='none'; this.style.transform='translateX(0)'">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                        <div style="flex: 1; min-width: 180px;">
                                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                                                <div style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo $item_color; ?>; box-shadow: 0 0 0 2px <?php echo $item_bg; ?>;"></div>
                                                <div style="font-size: 12px; font-weight: 700; color: #0f172a;">
                                                    <?php echo htmlspecialchars($item['name'] ?? ''); ?>
                                                </div>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-left: 16px;">
                                                <div style="display: flex; align-items: baseline; gap: 6px;">
                                                    <span style="font-size: 18px; font-weight: 700; color: <?php echo $item_color; ?>; letter-spacing: -0.3px;">
                                                        <?php echo number_format($item_percentage, 1); ?>%
                                                    </span>
                                                    <span style="font-size: 11px; font-weight: 700; color: <?php echo $item_color; ?>; padding: 2px 8px; background: <?php echo $item_bg; ?>; border-radius: 4px;">
                                                        <?php echo $item_letter; ?>
                                                    </span>
                                                </div>
                                                <div style="font-size: 13px; color: #64748b; font-weight: 600;">
                                                    <?php echo number_format($item_grade, 2); ?> / <?php echo number_format($item_max, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Progress Bar -->
                                    <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 6px; overflow: hidden; position: relative; margin-top: 12px; margin-left: 20px;">
                                        <div style="width: <?php echo min(100, $item_percentage); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $item_color; ?>, <?php echo str_replace('0.1', '0.6', $item_bg); ?>); border-radius: 6px; transition: width 0.6s ease; box-shadow: 0 2px 6px <?php echo str_replace('0.1', '0.3', $item_bg); ?>;"></div>
                                    </div>
                                </div>
                </div>
                <?php endforeach; ?>
            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <script>
            function toggleTree(courseId) {
                const parent = document.getElementById(courseId + '-parent');
                const children = document.getElementById(courseId + '-children');
                
                if (parent && children) {
                    parent.classList.toggle('expanded');
                }
            }
            </script>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h3>No Grades Data</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- ATTENDANCE TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'attendance') ? 'active' : ''; ?>" id="tab-attendance">
            <?php 
            // Note: $reports_data['attendance'] is already filtered by course at the top level
            // Prepare attendance chart data
            $attendance_dates = [];
            $attendance_present = [];
            $attendance_absent = [];
            $attendance_by_course = [];
            
            if (!empty($reports_data['attendance'])) {
                $date_counts = [];
                $course_counts = [];
                
                // Process attendance records (login-based) - group by date
                foreach ($reports_data['attendance'] as $att) {
                    $date = date('M d', $att->timetaken);
                    if (!isset($date_counts[$date])) {
                        $date_counts[$date] = ['present' => 0, 'absent' => 0];
                    }
                    // Only count once per day (present or absent)
                    if ($att->status == 1) {
                        $date_counts[$date]['present'] = 1; // Present if logged in
                    } else {
                        $date_counts[$date]['absent'] = 1; // Absent if no login
                    }
                    
                    // Group by course (though now it's "Daily Login" - login-based attendance)
                    $course = 'Daily Login';
                    if (!isset($course_counts[$course])) {
                        $course_counts[$course] = ['present' => 0, 'absent' => 0];
                    }
                    if ($att->status == 1) {
                        $course_counts[$course]['present']++;
                    } else {
                        $course_counts[$course]['absent']++;
                    }
                }
                
                // Sort dates and get last 10
                ksort($date_counts);
                $sorted_dates = array_slice(array_keys($date_counts), -10, 10, true);
                foreach ($sorted_dates as $date) {
                    $attendance_dates[] = $date;
                    $attendance_present[] = $date_counts[$date]['present'];
                    $attendance_absent[] = $date_counts[$date]['absent'];
                }
                
                foreach ($course_counts as $course => $counts) {
                    $attendance_by_course[] = [
                        'course' => substr($course, 0, 20),
                        'present' => $counts['present'],
                        'absent' => $counts['absent'],
                        'rate' => ($counts['present'] + $counts['absent']) > 0 ? 
                            round(($counts['present'] / ($counts['present'] + $counts['absent'])) * 100, 1) : 0
                    ];
                }
            }
            ?>
            <?php if (!empty($reports_data['attendance'])): ?>
            <!-- Advanced Attendance Analytics -->
            <?php 
            // Calculate attendance based on weekdays only
            $present_count = 0;
            $absent_count = 0;
            $total_weekdays = 0;
            
            // Count only weekdays in the last 30 days for current period
            $thirty_days_ago = time() - (DAYSECS * 30);
            for ($i = 0; $i < 30; $i++) {
                $day_timestamp = time() - ($i * DAYSECS);
                $day_of_week = date('N', $day_timestamp); // 1=Monday, 7=Sunday
                if ($day_of_week <= 5) { // Weekday
                    $total_weekdays++;
                    $day_date = date('Y-m-d', $day_timestamp);
                    
                    // Check if student logged in on this day
                    $logged_in = false;
            foreach ($reports_data['attendance'] as $att) {
                        $att_date = date('Y-m-d', $att->timetaken);
                        if ($att_date == $day_date && $att->status == 1) {
                            $logged_in = true;
                            break;
                        }
                    }
                    
                    if ($logged_in) {
                        $present_count++;
                    } else {
                        $absent_count++;
            }
                }
            }
            
            $attendance_count = $present_count + $absent_count; // Total weekdays
            $attendance_rate = $total_weekdays > 0 ? ($present_count / $total_weekdays) * 100 : 0;
            
            // Calculate real attendance trend from database (based on login activity)
            $attendance_trend = 'N/A';
            if ($selected_child_id) {
                try {
                    // Get attendance from previous month (30 days ago to 60 days ago)
                    $thirty_days_ago = time() - (DAYSECS * 30);
                    $sixty_days_ago = time() - (DAYSECS * 60);
                    
                    $previous_month_logins = $DB->get_records_sql(
                        "SELECT DISTINCT 
                                DATE(FROM_UNIXTIME(timecreated)) as login_date
                         FROM {logstore_standard_log}
                         WHERE userid = ?
                           AND action = 'loggedin'
                           AND timecreated >= ? AND timecreated < ?
                         GROUP BY DATE(FROM_UNIXTIME(timecreated))",
                        [$selected_child_id, $sixty_days_ago, $thirty_days_ago]
                    );
                    
                    // Count weekdays in previous month period
                    $prev_weekdays = 0;
                    for ($i = 30; $i < 60; $i++) {
                        $day_timestamp = time() - ($i * DAYSECS);
                        $day_of_week = date('N', $day_timestamp);
                        if ($day_of_week <= 5) { // Weekday
                            $prev_weekdays++;
                        }
                    }
                    
                    if ($prev_weekdays > 0) {
                        $prev_present = count($previous_month_logins);
                        $prev_rate = ($prev_present / $prev_weekdays) * 100;
                        
                        if ($prev_rate > 0) {
                            $trend_value = round((($attendance_rate - $prev_rate) / $prev_rate) * 100, 1);
                            $attendance_trend = ($trend_value > 0 ? '+' : '') . $trend_value . '%';
                        } else {
                            $attendance_trend = 'N/A';
                        }
                    }
                } catch (Exception $e) {
                    debugging('Attendance trend calculation failed: ' . $e->getMessage());
                    $attendance_trend = 'N/A';
                }
            }
            
            $attendance_status = $attendance_rate >= 90 ? 'Excellent' : ($attendance_rate >= 75 ? 'Good' : 'Needs Improvement');
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Attendance Rate</div>
                            <div class="analytics-value"><?php echo number_format($attendance_rate, 1); ?>%</div>
                            <div class="analytics-change <?php echo $attendance_rate >= 90 ? 'positive' : ($attendance_rate >= 75 ? 'neutral' : 'negative'); ?>">
                                <i class="fas fa-<?php echo $attendance_rate >= 90 ? 'check' : 'exclamation'; ?>-circle"></i>
                                <?php echo $attendance_status; ?>
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo min(100, $attendance_rate); ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #2563eb); transition: width 0.3s;"></div>
                        </div>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Present Sessions</div>
                            <div class="analytics-value"><?php echo $present_count; ?></div>
                            <div class="analytics-change <?php echo ($attendance_trend !== 'N/A' && strpos($attendance_trend, '+') === 0) ? 'positive' : (($attendance_trend !== 'N/A' && strpos($attendance_trend, '-') === 0) ? 'negative' : 'neutral'); ?>">
                                <i class="fas fa-<?php echo ($attendance_trend !== 'N/A' && strpos($attendance_trend, '+') === 0) ? 'arrow-up' : (($attendance_trend !== 'N/A' && strpos($attendance_trend, '-') === 0) ? 'arrow-down' : 'minus'); ?>"></i>
                                <?php echo $attendance_trend; ?> <?php echo ($attendance_trend !== 'N/A') ? 'vs last month' : ''; ?>
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                    <div class="metric-grid">
                        <div class="metric-item">
                            <div class="metric-value"><?php echo $absent_count; ?></div>
                            <div class="metric-label">Absent</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-value"><?php echo $attendance_count; ?></div>
                            <div class="metric-label">Total</div>
                        </div>
                    </div>
                </div>
                
                <div class="insight-card">
                    <div class="insight-title">
                        <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                        Attendance Insight
                    </div>
                    <p class="insight-text">
                        <?php if ($attendance_rate >= 95): ?>
                        Excellent attendance! Your child has maintained <?php echo number_format($attendance_rate, 1); ?>% attendance rate. This consistent presence contributes significantly to academic success.
                        <?php elseif ($attendance_rate >= 85): ?>
                        Good attendance at <?php echo number_format($attendance_rate, 1); ?>%. Continue encouraging regular attendance to maximize learning opportunities.
                        <?php else: ?>
                        Attendance rate of <?php echo number_format($attendance_rate, 1); ?>% needs improvement. Regular attendance is crucial for academic success. Consider discussing any barriers to attendance.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <!-- Attendance Charts -->
            <?php if (!empty($attendance_dates)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Attendance Trend (Last 10 Sessions)</h3>
                    <div class="chart-wrapper">
                        <canvas id="attendanceTrendChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Overall Attendance Status</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="attendanceStatusChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($attendance_by_course)): ?>
            <div class="chart-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-chart-bar"></i> Attendance by Course</h3>
                <div class="chart-wrapper" style="height: 350px;">
                    <canvas id="attendanceByCourseChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <?php 
                // Use already calculated values from above
                // $attendance_count, $present_count, $absent_count, $attendance_rate are already calculated
                ?>
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-number"><?php echo $attendance_count; ?></div>
                    <div class="stat-label-modern">Total Sessions</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $present_count; ?></div>
                    <div class="stat-label-modern">Present</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-times"></i></div>
                    <div class="stat-number"><?php echo $attendance_count - $present_count; ?></div>
                    <div class="stat-label-modern">Absent</div>
                </div>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo number_format($attendance_rate, 1); ?>%</div>
                    <div class="stat-label-modern">Attendance Rate</div>
                </div>
            </div>
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #0f172a;">
                    <i class="fas fa-sign-in-alt" style="color: #3b82f6; margin-right: 8px;"></i>
                    Recent Attendance (Login-Based)
                </h2>
                <p style="font-size: 13px; color: #64748b; margin: 0 0 16px 0; font-style: italic;">
                    <i class="fas fa-info-circle" style="margin-right: 6px;"></i>
                    Attendance is automatically tracked when your child logs into the system. Each login counts as present for that day.
                </p>
                <?php 
                // Get user timezone for display
                require_once($CFG->dirroot . '/lib/classes/date.php');
                $user_timezone = \core_date::get_user_timezone($USER);
                $timezone_name = '';
                try {
                    // Get localized timezone name
                    $timezone_name = \core_date::get_localised_timezone($user_timezone);
                    
                    // Also get timezone offset for display
                    $tz_obj = \core_date::get_user_timezone_object($USER);
                    $now = new DateTime('now', $tz_obj);
                    $offset = $tz_obj->getOffset($now);
                    $offset_hours = $offset / 3600;
                    $sign = $offset_hours >= 0 ? '+' : '';
                    $offset_display = 'GMT' . $sign . number_format($offset_hours, 1);
                } catch (Exception $e) {
                    $timezone_name = 'Local Time';
                    $offset_display = '';
                }
                ?>
                <p style="font-size: 12px; color: #94a3b8; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-globe" style="color: #3b82f6;"></i>
                    <span>Times displayed in your timezone: <strong><?php echo htmlspecialchars($timezone_name); ?></strong></span>
                    <?php if (!empty($offset_display)): ?>
                        <span style="color: #cbd5e1;">•</span>
                        <span style="font-size: 11px;"><?php echo htmlspecialchars($offset_display); ?></span>
                    <?php endif; ?>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Login Time</th>
                            <th>Status</th>
                            <th>Logins</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Group by date to show one row per day
                        $attendance_by_date = [];
                        foreach (array_slice($reports_data['attendance'], 0, 30) as $att) {
                            $date_key = date('Y-m-d', $att->timetaken);
                            if (!isset($attendance_by_date[$date_key])) {
                                $attendance_by_date[$date_key] = [
                                    'date' => $att->timetaken,
                                    'status' => $att->status,
                                    'login_count' => 0,
                                    'first_login' => $att->timetaken
                                ];
                            }
                            if ($att->status == 1) {
                                $attendance_by_date[$date_key]['login_count'] = isset($att->login_count) ? $att->login_count : 1;
                                if ($att->timetaken < $attendance_by_date[$date_key]['first_login']) {
                                    $attendance_by_date[$date_key]['first_login'] = $att->timetaken;
                                }
                            }
                        }
                        // Sort by date descending
                        krsort($attendance_by_date);
                        foreach (array_slice($attendance_by_date, 0, 20) as $date_key => $day_att): 
                        ?>
                        <tr>
                            <td><?php echo userdate($day_att['date'], '%d %b %Y'); ?></td>
                            <td>
                                <?php if ($day_att['status'] == 1): ?>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <i class="fas fa-clock" style="color: #64748b;"></i>
                                        <span style="font-weight: 600; color: #0f172a;">
                                            <?php echo userdate($day_att['first_login'], '%I:%M %p'); ?>
                                </span>
                                        <?php 
                                        // Get timezone abbreviation for this specific date
                                        try {
                                            $tz_obj = \core_date::get_user_timezone_object($USER);
                                            $login_date = new DateTime('@' . $day_att['first_login']);
                                            $login_date->setTimezone($tz_obj);
                                            $tz_abbr = $login_date->format('T'); // Timezone abbreviation (e.g., EST, PST)
                                            if (!empty($tz_abbr) && $tz_abbr != 'UTC'): 
                                        ?>
                                            <span style="font-size: 11px; color: #94a3b8; padding: 2px 6px; background: #f1f5f9; border-radius: 4px;">
                                                <?php echo htmlspecialchars($tz_abbr); ?>
                                            </span>
                                        <?php 
                                            endif;
                                        } catch (Exception $e) {
                                            // Timezone display not available
                                        }
                                        ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">No login</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; <?php echo $day_att['status'] == 1 ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                    <?php echo $day_att['status'] == 1 ? 'Present' : 'Absent'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($day_att['status'] == 1): ?>
                                    <span style="color: #3b82f6; font-weight: 600;">
                                        <i class="fas fa-sign-in-alt" style="margin-right: 4px;"></i>
                                        <?php echo $day_att['login_count']; ?> time<?php echo $day_att['login_count'] > 1 ? 's' : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No Attendance Data</h3>
                <p style="color: #64748b; margin-top: 12px; font-size: 14px;">
                    Attendance is tracked based on student login activity. When your child logs into the system, it counts as present for that day.
                </p>
                <p style="color: #94a3b8; margin-top: 8px; font-size: 13px;">
                    No login activity recorded in the last 90 days.
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- QUIZZES TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'quizzes') ? 'active' : ''; ?>" id="tab-quizzes">
            <?php 
            // Prepare quiz chart data
            $quiz_names = [];
            $quiz_scores = [];
            $quiz_percentages = [];
            $quiz_dates = [];
            $quiz_by_course = [];
            
            if (!empty($reports_data['quizzes'])) {
                $quizzes_count = count($reports_data['quizzes']);
                $total_score = 0;
                $total_max = 0;
                $passed_quizzes = 0;
                $excellent_quizzes = 0;
                $good_quizzes = 0;
                $needs_improvement_quizzes = 0;
                $course_quiz_data = [];
                
                foreach ($reports_data['quizzes'] as $quiz) {
                    $maxgrade = $quiz->maxgrade ?? 1;
                    $score = $quiz->sumgrades ?? 0;
                    $percentage = $maxgrade > 0 ? ($score / $maxgrade) * 100 : 0;
                    
                    $total_score += $score;
                    $total_max += $maxgrade;
                    if ($percentage >= 50) $passed_quizzes++;
                    if ($percentage >= 90) $excellent_quizzes++;
                    elseif ($percentage >= 70) $good_quizzes++;
                    else $needs_improvement_quizzes++;
                    
                    $quiz_names[] = substr($quiz->quizname ?? 'Quiz', 0, 20);
                    $quiz_scores[] = round($score, 2);
                    $quiz_percentages[] = round($percentage, 1);
                    $quiz_dates[] = $quiz->timefinish ? date('M d', $quiz->timefinish) : '-';
                    
                    $course = $quiz->coursename ?? 'Unknown';
                    if (!isset($course_quiz_data[$course])) {
                        $course_quiz_data[$course] = ['scores' => [], 'count' => 0];
                    }
                    $course_quiz_data[$course]['scores'][] = $percentage;
                    $course_quiz_data[$course]['count']++;
                }
                
                foreach ($course_quiz_data as $course => $data) {
                    $avg = count($data['scores']) > 0 ? array_sum($data['scores']) / count($data['scores']) : 0;
                    $quiz_by_course[] = [
                        'course' => substr($course, 0, 20),
                        'average' => round($avg, 1),
                        'count' => $data['count']
                    ];
                }
                
                $avg_score = $total_max > 0 ? ($total_score / $total_max) * 100 : 0;
                $pass_rate = $quizzes_count > 0 ? ($passed_quizzes / $quizzes_count) * 100 : 0;
                
                // Calculate real quiz trend from database
                $quiz_trend = 'N/A';
                if ($selected_child_id && !empty($course_ids)) {
                    try {
                        // Get quiz attempts from previous month (30 days ago to 60 days ago)
                        $thirty_days_ago = time() - (DAYSECS * 30);
                        $sixty_days_ago = time() - (DAYSECS * 60);
                        $course_ids_sql_prev = !empty($course_ids) ? implode(',', $course_ids) : '0';
                        
                        $previous_month_quizzes = $DB->get_records_sql(
                            "SELECT qa.sumgrades, q.grade as maxgrade
                             FROM {quiz_attempts} qa
                             JOIN {quiz} q ON q.id = qa.quiz
                             JOIN {course} c ON c.id = q.course
                             WHERE q.course IN (" . $course_ids_sql_prev . ")
                               AND qa.userid = ?
                               AND qa.state = 'finished'
                               AND qa.timefinish > 0
                               AND qa.timefinish >= ? AND qa.timefinish < ?
                             ORDER BY qa.timefinish DESC",
                            [$selected_child_id, $sixty_days_ago, $thirty_days_ago],
                            0,
                            50
                        );
                        
                        if (!empty($previous_month_quizzes)) {
                            $prev_total_score = 0;
                            $prev_total_max = 0;
                            foreach ($previous_month_quizzes as $prev_quiz) {
                                $prev_max = $prev_quiz->maxgrade ?? 1;
                                $prev_score = $prev_quiz->sumgrades ?? 0;
                                $prev_total_score += $prev_score;
                                $prev_total_max += $prev_max;
                            }
                            $prev_avg = $prev_total_max > 0 ? ($prev_total_score / $prev_total_max) * 100 : 0;
                            
                            if ($prev_avg > 0) {
                                $trend_value = round((($avg_score - $prev_avg) / $prev_avg) * 100, 1);
                                $quiz_trend = ($trend_value > 0 ? '+' : '') . $trend_value . '%';
                            } else {
                                $quiz_trend = 'N/A';
                            }
                        }
                    } catch (Exception $e) {
                        debugging('Quiz trend calculation failed: ' . $e->getMessage());
                        $quiz_trend = 'N/A';
                    }
                }
            }
            ?>
            <?php if (!empty($reports_data['quizzes'])): ?>
            <!-- Advanced Quiz Analytics -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing quizzes for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Average Quiz Score</div>
                            <div class="analytics-value"><?php echo number_format($avg_score, 1); ?>%</div>
                            <div class="analytics-change <?php echo ($quiz_trend !== 'N/A' && strpos($quiz_trend, '+') === 0) ? 'positive' : (($quiz_trend !== 'N/A' && strpos($quiz_trend, '-') === 0) ? 'negative' : 'neutral'); ?>">
                                <i class="fas fa-<?php echo ($quiz_trend !== 'N/A' && strpos($quiz_trend, '+') === 0) ? 'arrow-up' : (($quiz_trend !== 'N/A' && strpos($quiz_trend, '-') === 0) ? 'arrow-down' : 'minus'); ?>"></i>
                                <?php echo $quiz_trend; ?> <?php echo ($quiz_trend !== 'N/A') ? 'vs last month' : ''; ?>
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                            <i class="fas fa-question"></i>
                        </div>
                    </div>
                    <div class="metric-grid">
                        <div class="metric-item">
                            <div class="metric-value"><?php echo $passed_quizzes; ?></div>
                            <div class="metric-label">Passed (≥50%)</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-value"><?php echo number_format($pass_rate, 0); ?>%</div>
                            <div class="metric-label">Pass Rate</div>
                        </div>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Performance Breakdown</div>
                            <div class="analytics-value" style="font-size: 24px;"><?php echo $quizzes_count; ?></div>
                            <div class="analytics-change positive">
                                <i class="fas fa-chart-pie"></i>
                                Total Quizzes
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                            <span style="color: #64748b;">Excellent (≥90%):</span>
                            <span style="font-weight: 700; color: #10b981;"><?php echo $excellent_quizzes; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                            <span style="color: #64748b;">Good (70-89%):</span>
                            <span style="font-weight: 700; color: #3b82f6;"><?php echo $good_quizzes; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 13px;">
                            <span style="color: #64748b;">Needs Improvement (<70%):</span>
                            <span style="font-weight: 700; color: #ef4444;"><?php echo $needs_improvement_quizzes; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="insight-card">
                    <div class="insight-title">
                        <i class="fas fa-lightbulb" style="color: #ec4899;"></i>
                        Quiz Performance Insight
                    </div>
                    <p class="insight-text">
                        <?php if ($avg_score >= 85): ?>
                        Excellent quiz performance! Your child is consistently scoring well on assessments. The average of <?php echo number_format($avg_score, 1); ?>% demonstrates strong understanding of course materials.
                        <?php elseif ($avg_score >= 70): ?>
                        Good quiz performance at <?php echo number_format($avg_score, 1); ?>% average. Focus on reviewing missed questions to improve scores further.
                        <?php else: ?>
                        Quiz scores averaging <?php echo number_format($avg_score, 1); ?>% indicate areas needing more study. Consider additional practice and review sessions.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <!-- Quiz Charts -->
            <?php if (!empty($quiz_names)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Quiz Scores Performance</h3>
                    <div class="chart-wrapper">
                        <canvas id="quizScoresChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Quiz Performance Trend</h3>
                    <div class="chart-wrapper">
                        <canvas id="quizTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($quiz_by_course)): ?>
            <div class="chart-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-chart-area"></i> Average Quiz Scores by Course</h3>
                <div class="chart-wrapper" style="height: 350px;">
                    <canvas id="quizByCourseChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-question"></i></div>
                    <div class="stat-number"><?php echo $quizzes_count; ?></div>
                    <div class="stat-label-modern">Total Quizzes</div>
                </div>
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo number_format($avg_score, 1); ?>%</div>
                    <div class="stat-label-modern">Average Score</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $passed_quizzes; ?></div>
                    <div class="stat-label-modern">Passed (≥50%)</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-certificate"></i></div>
                    <div class="stat-number"><?php echo $quizzes_count > 0 ? number_format(($passed_quizzes / $quizzes_count) * 100, 1) : 0; ?>%</div>
                    <div class="stat-label-modern">Pass Rate</div>
                </div>
            </div>
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #475569;">Quiz Performance</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Quiz Name</th>
                            <th>Course</th>
                            <th>Score</th>
                            <th>Max Grade</th>
                            <th>Percentage</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports_data['quizzes'] as $quiz): 
                            $percentage = ($quiz->maxgrade > 0) ? ($quiz->sumgrades / $quiz->maxgrade) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($quiz->quizname ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($quiz->coursename ?? ''); ?></td>
                            <td><?php echo number_format($quiz->sumgrades ?? 0, 2); ?></td>
                            <td><?php echo number_format($quiz->maxgrade ?? 0, 2); ?></td>
                            <td>
                                <span style="padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; <?php echo $percentage >= 50 ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                    <?php echo number_format($percentage, 1); ?>%
                                </span>
                            </td>
                            <td><?php echo $quiz->timefinish ? userdate($quiz->timefinish, '%d %b %Y') : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-question"></i>
                <h3>No Quiz Data</h3>
                <p>No quiz attempts found for this child.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ASSIGNMENTS TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'assignments') ? 'active' : ''; ?>" id="tab-assignments">
            <?php 
            // Prepare assignment chart data
            $assignment_names = [];
            $assignment_due_dates = [];
            $assignment_status = [];
            $assignment_by_course = [];
            
            // Calculate assignment analytics
            $assignments_count = 0;
            $completed_assignments = 0;
            $pending_assignments = 0;
            $overdue_assignments = 0;
            $upcoming_assignments = 0;
            $current_time = time();
            
            if (!empty($reports_data['assignments'])) {
                $assignments_count = count($reports_data['assignments']);
                $upcoming = 0;
                $past = 0;
                $now = time();
                $course_assign_data = [];
                
                foreach ($reports_data['assignments'] as $assign) {
                    $is_upcoming = $assign->duedate > $now;
                    if ($is_upcoming) {
                        $upcoming++;
                    } else {
                        $past++;
                    }
                    
                    $assignment_names[] = substr($assign->name ?? 'Assignment', 0, 20);
                    $assignment_due_dates[] = $assign->duedate ? date('M d', $assign->duedate) : '-';
                    $assignment_status[] = $is_upcoming ? 'Upcoming' : 'Past Due';
                    
                    $course = $assign->coursename ?? 'Unknown';
                    if (!isset($course_assign_data[$course])) {
                        $course_assign_data[$course] = ['upcoming' => 0, 'past' => 0];
                    }
                    if ($is_upcoming) {
                        $course_assign_data[$course]['upcoming']++;
                    } else {
                        $course_assign_data[$course]['past']++;
                    }
                }
                
                foreach ($course_assign_data as $course => $data) {
                    $assignment_by_course[] = [
                        'course' => substr($course, 0, 20),
                        'upcoming' => $data['upcoming'],
                        'past' => $data['past'],
                        'total' => $data['upcoming'] + $data['past']
                    ];
                }
                
                // Calculate completion metrics
                foreach ($reports_data['assignments'] as $assign) {
                    $duedate = $assign->duedate ?? 0;
                    $grade = $assign->grade ?? null;
                    
                    if ($grade !== null && $grade > 0) {
                        $completed_assignments++;
                    } elseif ($duedate > 0) {
                        if ($duedate < $now) {
                            $overdue_assignments++;
                        } else {
                            $upcoming_assignments++;
                        }
                        $pending_assignments++;
                    }
                }
                
                $completion_rate = $assignments_count > 0 ? ($completed_assignments / $assignments_count) * 100 : 0;
            }
            ?>
            <?php if (!empty($reports_data['assignments'])): ?>
            <!-- Advanced Assignment Analytics -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing assignments for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Completion Rate</div>
                            <div class="analytics-value"><?php echo number_format($completion_rate, 1); ?>%</div>
                            <div class="analytics-change <?php echo $completion_rate >= 80 ? 'positive' : ($completion_rate >= 60 ? 'neutral' : 'negative'); ?>">
                                <i class="fas fa-<?php echo $completion_rate >= 80 ? 'check' : 'exclamation'; ?>-circle"></i>
                                <?php echo $completed_assignments; ?> of <?php echo $assignments_count; ?> completed
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-tasks"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo min(100, $completion_rate); ?>%; height: 100%; background: linear-gradient(90deg, #10b981, #059669); transition: width 0.3s;"></div>
                        </div>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <div class="analytics-title">Assignment Status</div>
                            <div class="analytics-value" style="font-size: 24px;"><?php echo $assignments_count; ?></div>
                            <div class="analytics-change positive">
                                <i class="fas fa-list"></i>
                                Total Assignments
                            </div>
                        </div>
                        <div class="analytics-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                            <span style="color: #64748b;">Completed:</span>
                            <span style="font-weight: 700; color: #10b981;"><?php echo $completed_assignments; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                            <span style="color: #64748b;">Upcoming:</span>
                            <span style="font-weight: 700; color: #3b82f6;"><?php echo $upcoming_assignments; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 13px;">
                            <span style="color: #64748b;">Overdue:</span>
                            <span style="font-weight: 700; color: #ef4444;"><?php echo $overdue_assignments; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="insight-card">
                    <div class="insight-title">
                        <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                        Assignment Insight
                    </div>
                    <p class="insight-text">
                        <?php if ($completion_rate >= 90): ?>
                        Excellent assignment completion rate! Your child has completed <?php echo number_format($completion_rate, 1); ?>% of assignments, demonstrating strong commitment to coursework.
                        <?php elseif ($completion_rate >= 70): ?>
                        Good completion rate at <?php echo number_format($completion_rate, 1); ?>%. Continue encouraging timely submission of assignments.
                        <?php else: ?>
                        Completion rate of <?php echo number_format($completion_rate, 1); ?>% needs improvement. <?php if ($overdue_assignments > 0): ?>There are <?php echo $overdue_assignments; ?> overdue assignment(s) requiring immediate attention.<?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <!-- Assignment Charts -->
            <?php if (!empty($assignment_names)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Assignments Status Breakdown</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="assignmentStatusChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Assignments by Course</h3>
                    <div class="chart-wrapper">
                        <canvas id="assignmentByCourseChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-tasks"></i></div>
                    <div class="stat-number"><?php echo $assignments_count; ?></div>
                    <div class="stat-label-modern">Total Assignments</div>
                </div>
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $upcoming; ?></div>
                    <div class="stat-label-modern">Upcoming</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $past; ?></div>
                    <div class="stat-label-modern">Completed</div>
                </div>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo $assignments_count > 0 ? number_format(($past / $assignments_count) * 100, 1) : 0; ?>%</div>
                    <div class="stat-label-modern">Completion Rate</div>
                </div>
            </div>
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #0f172a;">Assignments</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Assignment Name</th>
                            <th>Course</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports_data['assignments'] as $assign): 
                            $is_upcoming = $assign->duedate > $now;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($assign->name ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($assign->coursename ?? ''); ?></td>
                            <td><?php echo $assign->duedate ? userdate($assign->duedate, '%d %b %Y %H:%M') : '-'; ?></td>
                            <td>
                                <span style="padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; <?php echo $is_upcoming ? 'background: #dbeafe; color: #1e40af;' : 'background: #d1fae5; color: #065f46;'; ?>">
                                    <?php echo $is_upcoming ? 'Upcoming' : 'Past Due'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-tasks"></i>
                <h3>No Assignments Data</h3>
                <p>No assignments found for this child.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- RECENT RESOURCES TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'resources') ? 'active' : ''; ?>" id="tab-resources">
            <div style="margin-bottom: 32px;">
                <h2 style="font-size: 24px; font-weight: 800; margin: 0 0 8px 0; color: #0f172a; display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-folder" style="color: #ec4899; font-size: 28px;"></i>
                    Recent Resources
                </h2>
                <p style="color: #64748b; margin: 0; font-size: 14px;">Resources that your child has recently accessed or viewed</p>
            </div>

            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #fce7f3; border: 1px solid #f9a8d4; border-radius: 12px; padding: 12px 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #be185d; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #831843;">
                    Showing resources for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>

            <?php if (!empty($recent_resources)): ?>
            <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                <?php foreach ($recent_resources as $resource): ?>
                <?php
                $icon_class = 'fa-file';
                $icon_color = '#64748b';
                if (strpos($resource['mimetype'], 'pdf') !== false) {
                    $icon_class = 'fa-file-pdf';
                    $icon_color = '#ef4444';
                } elseif (strpos($resource['mimetype'], 'powerpoint') !== false || strpos($resource['mimetype'], 'presentation') !== false) {
                    $icon_class = 'fa-file-powerpoint';
                    $icon_color = '#f97316';
                } elseif (strpos($resource['mimetype'], 'word') !== false || strpos($resource['mimetype'], 'document') !== false) {
                    $icon_class = 'fa-file-word';
                    $icon_color = '#3b82f6';
                } elseif (strpos($resource['mimetype'], 'image') !== false) {
                    $icon_class = 'fa-file-image';
                    $icon_color = '#10b981';
                } elseif (strpos($resource['mimetype'], 'video') !== false) {
                    $icon_class = 'fa-file-video';
                    $icon_color = '#8b5cf6';
                }
                
                $filesize_display = '-';
                if ($resource['filesize'] > 0) {
                    $filesize_mb = round($resource['filesize'] / 1048576, 1);
                    if ($filesize_mb < 0.1) {
                        $filesize_kb = round($resource['filesize'] / 1024, 1);
                        $filesize_display = $filesize_kb . ' KB';
                    } else {
                        $filesize_display = $filesize_mb . ' MB';
                    }
                }
                ?>
                <div style="background: #ffffff; border: 1px solid #f3f4f6; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; transition: all 0.3s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.1)'; this.style.borderColor='#e9d5ff';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 3px rgba(0, 0, 0, 0.05)'; this.style.borderColor='#f3f4f6';">
                    <div style="width: 56px; height: 56px; background: linear-gradient(135deg, <?php echo $icon_color; ?>20, <?php echo $icon_color; ?>10); border: 2px solid <?php echo $icon_color; ?>40; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="fas <?php echo $icon_class; ?>" style="font-size: 24px; color: <?php echo $icon_color; ?>;"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($resource['filename']); ?>">
                            <?php echo htmlspecialchars($resource['filename']); ?>
                        </div>
                        <div style="font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;" title="<?php echo htmlspecialchars($resource['coursename']); ?>">
                                <i class="fas fa-book" style="margin-right: 4px;"></i><?php echo htmlspecialchars($resource['coursename']); ?>
                            </span>
                            <span style="color: #d1d5db;">•</span>
                            <?php if ($filesize_display !== '-'): ?>
                            <span><i class="fas fa-weight" style="margin-right: 4px;"></i><?php echo $filesize_display; ?></span>
                            <span style="color: #d1d5db;">•</span>
                            <?php endif; ?>
                            <span><i class="fas fa-clock" style="margin-right: 4px;"></i><?php echo userdate($resource['timecreated'], '%d %b %Y, %I:%M %p'); ?></span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; flex-shrink: 0;">
                        <?php if (!empty($resource['file_url'])): ?>
                        <a href="<?php echo htmlspecialchars($resource['file_url']); ?>" target="_blank" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 1px solid #93c5fd; border-radius: 8px; padding: 10px 16px; cursor: pointer; color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(59, 130, 246, 0.2); font-size: 13px; font-weight: 600;" onmouseover="this.style.background='linear-gradient(135deg, #bfdbfe, #93c5fd)'; this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 6px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #dbeafe, #bfdbfe)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(59, 130, 246, 0.2)';">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <?php if ($resource['filesize'] > 0): ?>
                        <a href="<?php echo htmlspecialchars($resource['file_url']); ?>?download=1" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 8px; padding: 10px 16px; cursor: pointer; color: #10b981; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(16, 185, 129, 0.2); font-size: 13px; font-weight: 600;" onmouseover="this.style.background='linear-gradient(135deg, #a7f3d0, #6ee7b7)'; this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 6px rgba(16, 185, 129, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #d1fae5, #a7f3d0)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(16, 185, 129, 0.2)';">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <?php endif; ?>
                        <?php elseif (!empty($resource['cmid']) && !empty($resource['courseid']) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0): ?>
                        <a href="<?php echo (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', ['cmid' => $resource['cmid'], 'child' => $selected_child_id, 'courseid' => $resource['courseid']]))->out(); ?>" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 1px solid #93c5fd; border-radius: 8px; padding: 10px 16px; cursor: pointer; color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(59, 130, 246, 0.2); font-size: 13px; font-weight: 600;" onmouseover="this.style.background='linear-gradient(135deg, #bfdbfe, #93c5fd)'; this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 6px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #dbeafe, #bfdbfe)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(59, 130, 246, 0.2)';">
                            <i class="fas fa-eye"></i> Open
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding: 60px 20px; text-align: center; background: #fafbfc; border-radius: 20px; border: 2px dashed #e2e8f0;">
                <i class="fas fa-folder" style="font-size: 64px; color: #94a3b8; margin-bottom: 20px; opacity: 0.5;"></i>
                <h3 style="color: #0f172a; margin: 0 0 12px 0; font-size: 24px; font-weight: 700;">No Recent Resources</h3>
                <p style="color: #64748b; margin: 0; font-size: 16px; line-height: 1.6;">
                    <?php if ($selected_child_id): ?>
                        Resources that your child accesses will appear here once they start viewing course materials.
                    <?php else: ?>
                        Please select a child to view their recent resources.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- COURSE PROGRESS TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'progress') ? 'active' : ''; ?>" id="tab-progress">
            <?php 
            // Prepare progress chart data - Apply course filter
            $progress_chart_courses = [];
            $progress_chart_percentages = [];
            $progress_chart_completed = [];
            $progress_chart_total = [];
            
            if ($student_course_stats && !empty($student_course_stats)) {
                // Apply course filter if specific course is selected
                $filtered_progress_stats = $student_course_stats;
                if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
                    $selected_course_name = $child_courses[$selected_course_id]->fullname;
                    $filtered_progress_stats = array_filter($filtered_progress_stats, function($stat) use ($selected_course_id, $selected_course_name) {
                        if (is_object($stat)) {
                            $stat_course_id = $stat->courseid ?? $stat->id ?? 0;
                            $stat_course_name = $stat->fullname ?? '';
                            return ($stat_course_id == $selected_course_id || stripos($stat_course_name, $selected_course_name) !== false);
                        } elseif (is_array($stat)) {
                            $stat_course_id = $stat['courseid'] ?? $stat['id'] ?? 0;
                            $stat_course_name = $stat['fullname'] ?? '';
                            return ($stat_course_id == $selected_course_id || stripos($stat_course_name, $selected_course_name) !== false);
                        }
                        return false;
                    });
                }
                
                foreach ($filtered_progress_stats as $stat) {
                    $progress_chart_courses[] = substr($stat->fullname ?? 'Course', 0, 20);
                    $progress_chart_percentages[] = $stat->progress_percentage ?? 0;
                    $progress_chart_completed[] = $stat->completed_activities ?? 0;
                    $progress_chart_total[] = $stat->total_activities ?? 0;
                }
            }
            ?>
            <?php if ($student_course_stats && !empty($student_course_stats)): ?>
            <!-- Progress Charts -->
            <?php if (!empty($progress_chart_courses)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Course Progress Comparison</h3>
                    <div class="chart-wrapper">
                        <canvas id="progressComparisonChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Progress Distribution</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="progressDistributionChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="chart-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-chart-area"></i> Activities Completion by Course</h3>
                <div class="chart-wrapper" style="height: 400px;">
                    <canvas id="activitiesCompletionChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #0f172a;">Course-by-Course Progress</h2>
                <?php foreach ($student_course_stats as $course_stat): 
                    $progress = $course_stat->progress_percentage ?? 0;
                    $completed = $course_stat->completed_activities ?? 0;
                    $total = $course_stat->total_activities ?? 0;
                ?>
                <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h3 style="font-size: 16px; font-weight: 700; margin: 0; color: #0f172a;">
                            <?php echo htmlspecialchars($course_stat->fullname ?? ''); ?>
                        </h3>
                        <span style="font-size: 18px; font-weight: 800; color: #3b82f6;"><?php echo number_format($progress, 1); ?>%</span>
                    </div>
                    <div style="width: 100%; height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; margin-bottom: 8px;">
                        <div style="width: <?php echo min(100, max(0, $progress)); ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); transition: width 0.3s ease;"></div>
                    </div>
                    <div style="display: flex; gap: 16px; font-size: 12px; color: #64748b;">
                        <span><i class="fas fa-check" style="color: #10b981;"></i> <?php echo $completed; ?> completed</span>
                        <span><i class="fas fa-list" style="color: #64748b;"></i> <?php echo $total; ?> total</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($student_dashboard_stats): ?>
            <div class="stats-grid-modern">
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-book"></i></div>
                    <div class="stat-number"><?php echo $student_dashboard_stats['total_courses'] ?? 0; ?></div>
                    <div class="stat-label-modern">Total Courses</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $student_dashboard_stats['lessons_completed'] ?? 0; ?></div>
                    <div class="stat-label-modern">Lessons Completed</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-tasks"></i></div>
                    <div class="stat-number"><?php echo $student_dashboard_stats['activities_completed'] ?? 0; ?></div>
                    <div class="stat-label-modern">Activities Completed</div>
                </div>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-number"><?php echo $student_dashboard_stats['overall_progress'] ?? 0; ?>%</div>
                    <div class="stat-label-modern">Overall Progress</div>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <h3>No Progress Data</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- SUBMISSIONS TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'submissions') ? 'active' : ''; ?>" id="tab-submissions">
            <?php 
            // Prepare submissions chart data
            $submission_dates = [];
            $submission_counts = [];
            $submission_by_course = [];
            $submission_status_breakdown = ['submitted' => 0, 'draft' => 0, 'new' => 0];
            
            if (!empty($reports_data['submissions'])) {
                $submissions_count = count($reports_data['submissions']);
                $submitted = 0;
                $draft = 0;
                $date_counts = [];
                $course_counts = [];
                
                foreach ($reports_data['submissions'] as $sub) {
                    if ($sub->status == 'submitted') {
                        $submitted++;
                        $submission_status_breakdown['submitted']++;
                    } elseif ($sub->status == 'draft') {
                        $draft++;
                        $submission_status_breakdown['draft']++;
                    } else {
                        $submission_status_breakdown['new']++;
                    }
                    
                    $date = $sub->timemodified ? date('M d', $sub->timemodified) : date('M d', $sub->timecreated);
                    if (!isset($date_counts[$date])) {
                        $date_counts[$date] = 0;
                    }
                    $date_counts[$date]++;
                    
                    $course = $sub->coursename ?? 'Unknown';
                    if (!isset($course_counts[$course])) {
                        $course_counts[$course] = 0;
                    }
                    $course_counts[$course]++;
                }
                
                // Get last 10 dates
                $sorted_dates = array_slice(array_keys($date_counts), -10, 10, true);
                foreach ($sorted_dates as $date) {
                    $submission_dates[] = $date;
                    $submission_counts[] = $date_counts[$date];
                }
                
                foreach ($course_counts as $course => $count) {
                    $submission_by_course[] = [
                        'course' => substr($course, 0, 20),
                        'count' => $count
                    ];
                }
            }
            ?>
            <?php if (!empty($reports_data['submissions'])): ?>
            <!-- Submissions Charts -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing submissions for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($submission_dates)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Submission Timeline</h3>
                    <div class="chart-wrapper">
                        <canvas id="submissionTimelineChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Submission Status Breakdown</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="submissionStatusChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($submission_by_course)): ?>
            <div class="chart-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-chart-bar"></i> Submissions by Course</h3>
                <div class="chart-wrapper" style="height: 350px;">
                    <canvas id="submissionByCourseChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-file-upload"></i></div>
                    <div class="stat-number"><?php echo $submissions_count; ?></div>
                    <div class="stat-label-modern">Total Submissions</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $submitted; ?></div>
                    <div class="stat-label-modern">Submitted</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-edit"></i></div>
                    <div class="stat-number"><?php echo $draft; ?></div>
                    <div class="stat-label-modern">Draft</div>
                </div>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo $submissions_count > 0 ? number_format(($submitted / $submissions_count) * 100, 1) : 0; ?>%</div>
                    <div class="stat-label-modern">Submission Rate</div>
                </div>
            </div>
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #0f172a;">Assignment Submissions</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Assignment</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Submitted Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports_data['submissions'] as $sub): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sub->assignmentname ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($sub->coursename ?? ''); ?></td>
                            <td>
                                <span style="padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; <?php echo $sub->status == 'submitted' ? 'background: #d1fae5; color: #065f46;' : 'background: #fef3c7; color: #92400e;'; ?>">
                                    <?php echo ucfirst($sub->status ?? 'draft'); ?>
                                </span>
                            </td>
                            <td><?php echo $sub->duedate ? userdate($sub->duedate, '%d %b %Y') : '-'; ?></td>
                            <td><?php echo $sub->timemodified ? userdate($sub->timemodified, '%d %b %Y') : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-file-upload"></i>
                <h3>No Submissions Data</h3>
                <p>No assignment submissions found for this child.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- COURSE COMPLETION TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'completion') ? 'active' : ''; ?>" id="tab-completion">
            <?php 
            // Prepare completion chart data
            $completion_dates = [];
            $completion_timeline = [];
            $completion_by_month = [];
            
            if (!empty($reports_data['course_completion'])) {
                $completed_courses = count($reports_data['course_completion']);
                $total_enrolled = count($child_courses);
                $completion_rate = $total_enrolled > 0 ? ($completed_courses / $total_enrolled) * 100 : 0;
                
                $month_counts = [];
                foreach ($reports_data['course_completion'] as $completion) {
                    if ($completion->timecompleted) {
                        $month = date('M Y', $completion->timecompleted);
                        if (!isset($month_counts[$month])) {
                            $month_counts[$month] = 0;
                        }
                        $month_counts[$month]++;
                        
                        $completion_dates[] = date('M d', $completion->timecompleted);
                        $completion_timeline[] = 1;
                    }
                }
                
                foreach ($month_counts as $month => $count) {
                    $completion_by_month[] = [
                        'month' => $month,
                        'count' => $count
                    ];
                }
            }
            ?>
            <?php if (!empty($reports_data['course_completion'])): ?>
            <!-- Completion Charts -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing completion for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($completion_by_month)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Course Completions by Month</h3>
                    <div class="chart-wrapper">
                        <canvas id="completionByMonthChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Completion Status</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="completionStatusChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                    <div class="stat-number"><?php echo $completed_courses; ?></div>
                    <div class="stat-label-modern">Completed Courses</div>
                </div>
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-book"></i></div>
                    <div class="stat-number"><?php echo $total_enrolled; ?></div>
                    <div class="stat-label-modern">Total Enrolled</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-spinner"></i></div>
                    <div class="stat-number"><?php echo max(0, $total_enrolled - $completed_courses); ?></div>
                    <div class="stat-label-modern">In Progress</div>
                </div>
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo number_format($completion_rate, 1); ?>%</div>
                    <div class="stat-label-modern">Completion Rate</div>
                </div>
            </div>
            <div class="report-table">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #0f172a;">Completed Courses</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Completion Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports_data['course_completion'] as $completion): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($completion->coursename ?? ''); ?></td>
                            <td><?php echo $completion->timecompleted ? userdate($completion->timecompleted, '%d %b %Y') : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check"></i>
                <h3>No Completion Data</h3>
                <p>No completed courses found for this child.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ACTIVITY LOG TAB -->
        <div class="tab-content-section <?php echo ($current_tab === 'activity') ? 'active' : ''; ?>" id="tab-activity">
            <?php 
            // Prepare activity chart data
            $activity_dates = [];
            $activity_counts = [];
            $activity_by_type = [];
            $activity_by_course = [];
            
            if (!empty($reports_data['activity_log'])) {
                $activity_count = count($reports_data['activity_log']);
                $today = strtotime('today');
                $today_count = 0;
                $week_count = 0;
                $date_counts = [];
                $type_counts = [];
                $course_counts = [];
                
                foreach ($reports_data['activity_log'] as $act) {
                    if ($act->timecreated >= $today) $today_count++;
                    if ($act->timecreated >= ($today - (7 * DAYSECS))) $week_count++;
                    
                    $date = date('M d', $act->timecreated);
                    if (!isset($date_counts[$date])) {
                        $date_counts[$date] = 0;
                    }
                    $date_counts[$date]++;
                    
                    $action = $act->action ?? 'Unknown';
                    if (!isset($type_counts[$action])) {
                        $type_counts[$action] = 0;
                    }
                    $type_counts[$action]++;
                    
                    $course = $act->coursename ?? 'Unknown';
                    if (!isset($course_counts[$course])) {
                        $course_counts[$course] = 0;
                    }
                    $course_counts[$course]++;
                }
                
                // Get last 15 dates
                $sorted_dates = array_slice(array_keys($date_counts), -15, 15, true);
                foreach ($sorted_dates as $date) {
                    $activity_dates[] = $date;
                    $activity_counts[] = $date_counts[$date];
                }
                
                foreach ($type_counts as $type => $count) {
                    $activity_by_type[] = [
                        'type' => substr($type, 0, 20),
                        'count' => $count
                    ];
                }
                
                foreach ($course_counts as $course => $count) {
                    $activity_by_course[] = [
                        'course' => substr($course, 0, 20),
                        'count' => $count
                    ];
                }
            }
            ?>
            <?php if (!empty($reports_data['activity_log'])): ?>
            <!-- Activity Charts -->
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing activity for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($activity_dates)): ?>
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Activity Timeline (Last 15 Days)</h3>
                    <div class="chart-wrapper">
                        <canvas id="activityTimelineChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Activity by Type</h3>
                    <div class="chart-wrapper donut">
                        <canvas id="activityByTypeChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($activity_by_course)): ?>
            <div class="chart-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-chart-bar"></i> Activity by Course</h3>
                <div class="chart-wrapper" style="height: 400px;">
                    <canvas id="activityByCourseChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid-modern">
                <div class="stat-card-modern purple">
                    <div class="stat-icon-modern"><i class="fas fa-history"></i></div>
                    <div class="stat-number"><?php echo $activity_count; ?></div>
                    <div class="stat-label-modern">Total Activities (30 days)</div>
                </div>
                <div class="stat-card-modern green">
                    <div class="stat-icon-modern"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-number"><?php echo $today_count; ?></div>
                    <div class="stat-label-modern">Today</div>
                </div>
                <div class="stat-card-modern blue">
                    <div class="stat-icon-modern"><i class="fas fa-calendar-week"></i></div>
                    <div class="stat-number"><?php echo $week_count; ?></div>
                    <div class="stat-label-modern">This Week</div>
                </div>
                <div class="stat-card-modern orange">
                    <div class="stat-icon-modern"><i class="fas fa-chart-bar"></i></div>
                    <div class="stat-number"><?php echo number_format($activity_count / 30, 1); ?></div>
                    <div class="stat-label-modern">Daily Average</div>
                </div>
            </div>
            <!-- Recent Activity - Professional Timeline UI -->
            <div style="background: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 1px solid rgba(226, 232, 240, 0.8); margin-bottom: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
                    <h2 style="font-size: 18px; font-weight: 700; margin: 0; color: #0f172a; display: flex; align-items: center; gap: 10px; letter-spacing: -0.2px;">
                        <div style="width: 36px; height: 36px; background: #3b82f6; border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-history" style="font-size: 16px; color: white;"></i>
                        </div>
                        Recent Activity
                    </h2>
                    <div style="font-size: 11px; color: #64748b; font-weight: 600; padding: 6px 12px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                        Last 50 activities
                    </div>
                </div>
                
                <div style="position: relative; padding-left: 32px;">
                    <!-- Timeline line -->
                    <div style="position: absolute; left: 16px; top: 0; bottom: 0; width: 2px; background: #e2e8f0;"></div>
                    
                    <div style="display: grid; gap: 12px;">
                        <?php 
                        $activity_count = 0;
                        foreach (array_slice($reports_data['activity_log'], 0, 50) as $act): 
                            $activity_count++;
                            $is_last = ($activity_count === min(50, count($reports_data['activity_log'])));
                            
                            // Determine icon and color based on action
                            $action_lower = strtolower($act->action ?? '');
                            $icon = 'fa-circle';
                            $color = '#3b82f6';
                            $bg_color = 'rgba(59, 130, 246, 0.1)';
                            
                            if (strpos($action_lower, 'view') !== false || strpos($action_lower, 'read') !== false) {
                                $icon = 'fa-eye';
                                $color = '#3b82f6';
                                $bg_color = 'rgba(59, 130, 246, 0.1)';
                            } elseif (strpos($action_lower, 'submit') !== false || strpos($action_lower, 'upload') !== false) {
                                $icon = 'fa-upload';
                                $color = '#10b981';
                                $bg_color = 'rgba(16, 185, 129, 0.1)';
                            } elseif (strpos($action_lower, 'complete') !== false || strpos($action_lower, 'finish') !== false) {
                                $icon = 'fa-check';
                                $color = '#10b981';
                                $bg_color = 'rgba(16, 185, 129, 0.1)';
                            } elseif (strpos($action_lower, 'quiz') !== false || strpos($action_lower, 'attempt') !== false) {
                                $icon = 'fa-question';
                                $color = '#f59e0b';
                                $bg_color = 'rgba(245, 158, 11, 0.1)';
                            } elseif (strpos($action_lower, 'assign') !== false) {
                                $icon = 'fa-file-alt';
                                $color = '#8b5cf6';
                                $bg_color = 'rgba(139, 92, 246, 0.1)';
                            } elseif (strpos($action_lower, 'forum') !== false || strpos($action_lower, 'post') !== false) {
                                $icon = 'fa-comments';
                                $color = '#ec4899';
                                $bg_color = 'rgba(236, 72, 153, 0.1)';
                            }
                            
                            $time_ago = time() - $act->timecreated;
                            $time_text = '';
                            if ($time_ago < 3600) {
                                $minutes = floor($time_ago / 60);
                                $time_text = $minutes <= 1 ? 'Just now' : $minutes . ' min ago';
                            } elseif ($time_ago < 86400) {
                                $hours = floor($time_ago / 3600);
                                $time_text = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                            } elseif ($time_ago < 604800) {
                                $days = floor($time_ago / 86400);
                                $time_text = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                            } else {
                                $time_text = userdate($act->timecreated, '%d %b %Y');
                            }
                        ?>
                        <div style="position: relative; display: flex; align-items: flex-start; gap: 14px;">
                            <!-- Timeline dot -->
                            <div style="position: absolute; left: -24px; top: 6px; width: 12px; height: 12px; background: <?php echo $color; ?>; border-radius: 50%; border: 2px solid #ffffff; box-shadow: 0 0 0 2px <?php echo $bg_color; ?>, 0 1px 3px rgba(0, 0, 0, 0.1); z-index: 2;"></div>
                            
                            <!-- Activity card -->
                            <div style="flex: 1; background: #ffffff; border-radius: 8px; padding: 14px 16px; border: 1px solid #e2e8f0; transition: all 0.2s ease; position: relative; overflow: hidden;" 
                                 onmouseover="this.style.borderColor='<?php echo $color; ?>'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.08)'; this.style.backgroundColor='#f8fafc'" 
                                 onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'; this.style.backgroundColor='#ffffff'">
                                <!-- Color accent bar -->
                                <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: <?php echo $color; ?>;"></div>
                                
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                                    <div style="flex: 1; min-width: 200px;">
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                            <div style="width: 32px; height: 32px; background: <?php echo $bg_color; ?>; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fas <?php echo $icon; ?>" style="font-size: 14px; color: <?php echo $color; ?>;"></i>
                                            </div>
                                            <div style="flex: 1;">
                                                <div style="font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 3px; letter-spacing: -0.1px;">
                                                    <?php echo htmlspecialchars(ucfirst($act->action ?? 'Activity')); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #64748b; font-weight: 500;">
                                                    <?php echo htmlspecialchars($act->coursename ?? 'Course'); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($act->target)): ?>
                                        <div style="margin-left: 42px; padding: 8px 12px; background: #f8fafc; border-radius: 6px; border-left: 2px solid <?php echo $color; ?>;">
                                            <div style="font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600; margin-bottom: 3px;">Target</div>
                                            <div style="font-size: 12px; color: #0f172a; font-weight: 500;">
                                                <?php echo htmlspecialchars($act->target); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="text-align: right; min-width: 100px; flex-shrink: 0;">
                                        <div style="font-size: 12px; font-weight: 600; color: <?php echo $color; ?>; margin-bottom: 3px;">
                                            <?php echo $time_text; ?>
                                        </div>
                                        <div style="font-size: 10px; color: #94a3b8; font-weight: 500;">
                                            <?php echo userdate($act->timecreated, '%H:%M'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h3>No Activity Data</h3>
            </div>
            <?php endif; ?>
        </div>
        </div>
        <!-- End of reports-content-wrapper -->

        <?php else: ?>
        <!-- Empty State: No Child Selected -->
        <div class="empty-state" style="margin-top: 40px;">
            <i class="fas fa-chart-line"></i>
            <h3>No Child Selected</h3>
            <p>Choose a linked student to view their academic reports and performance data.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
               style="display: inline-flex; align-items: center; gap: 10px; margin-top: 24px; padding: 14px 28px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; font-weight: 700; text-decoration: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(59, 130, 246, 0.25); transition: transform 0.2s;"
               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(59, 130, 246, 0.35)'"
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 16px rgba(59, 130, 246, 0.25)'">
                <i class="fas fa-user-check"></i>
                Select Child
            </a>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- Empty State: No Children Found -->
        <div class="empty-state" style="margin-top: 40px;">
            <i class="fas fa-users"></i>
            <h3>No Children Found</h3>
            <p>You don't have any children linked to your parent account yet.</p>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
// Course Filter Function - Preserves tab selection
function filterByCourse(courseId) {
    const currentUrl = new URL(window.location.href);
    const currentTab = currentUrl.searchParams.get('tab') || 'overview';
    
    if (courseId === 'all' || courseId === '0') {
        currentUrl.searchParams.delete('course');
    } else {
        currentUrl.searchParams.set('course', courseId);
    }
    
    // Preserve tab parameter
    if (currentTab && currentTab !== 'overview') {
        currentUrl.searchParams.set('tab', currentTab);
    }
    
    window.location.href = currentUrl.toString();
}

// Make function available globally - define it immediately
(function() {
    'use strict';
    
    window.switchReportTab = function(tabName) {
        try {
            if (!tabName) {
                console.error('Tab name is required');
                return false;
            }
            
            console.log('Switching to tab:', tabName);
            
            // Preserve course filter in URL when switching tabs
            const currentUrl = new URL(window.location.href);
            const currentCourse = currentUrl.searchParams.get('course');
            
            // Update URL with tab parameter while preserving course filter
            if (currentCourse) {
                currentUrl.searchParams.set('tab', tabName);
                currentUrl.searchParams.set('course', currentCourse);
            } else {
                currentUrl.searchParams.set('tab', tabName);
            }
            
            // Update browser history without reloading (for smooth UX)
            window.history.pushState({ tab: tabName, course: currentCourse }, '', currentUrl.toString());
        
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content-section');
            if (tabContents.length === 0) {
                console.error('No tab content sections found');
                // Retry after a short delay in case DOM isn't ready
                setTimeout(function() {
                    window.switchReportTab(tabName);
                }, 100);
                return false;
            }
            
            tabContents.forEach(function(content) {
                if (content) {
        content.classList.remove('active');
        content.style.display = 'none';
                }
    });
    
    // Remove active class from all tabs
    const tabItems = document.querySelectorAll('.nav-tab-item');
            tabItems.forEach(function(item) {
                if (item) {
        item.classList.remove('active');
                }
    });
    
    // Show selected tab content
    const selectedContent = document.getElementById('tab-' + tabName);
            if (!selectedContent) {
                console.error('Tab content not found for:', 'tab-' + tabName);
                // Fallback: try to show overview tab
                const overviewContent = document.getElementById('tab-overview');
                if (overviewContent) {
                    overviewContent.classList.add('active');
                    overviewContent.style.display = 'block';
                    const overviewTab = document.querySelector('.nav-tab-item[data-tab="overview"]');
                    if (overviewTab) {
                        overviewTab.classList.add('active');
                    }
                }
                return false;
            }
        
        // Add fade-in animation
        selectedContent.style.opacity = '0';
        selectedContent.style.transform = 'translateY(10px)';
        selectedContent.classList.add('active');
        selectedContent.style.display = 'block';
        
        // Animate in
        setTimeout(function() {
            selectedContent.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            selectedContent.style.opacity = '1';
            selectedContent.style.transform = 'translateY(0)';
        }, 10);
    
    // Add active class to selected tab
            const selectedTab = document.querySelector('.nav-tab-item[data-tab="' + tabName + '"]');
    if (selectedTab) {
        selectedTab.classList.add('active');
            } else {
                console.warn('Tab item not found for:', tabName, '- content will still be shown');
    }
    
    // Scroll to top
            try {
    window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (e) {
                window.scrollTo(0, 0);
            }
    
            // Initialize charts for the active tab (with error handling)
    setTimeout(function() {
                try {
                    if (typeof initTabCharts === 'function') {
        initTabCharts(tabName);
                    } else {
                        console.warn('initTabCharts function not found - charts may not initialize');
                    }
                } catch (chartError) {
                    console.error('Error initializing charts:', chartError);
                }
    }, 300);
            
            return true;
        } catch (error) {
            console.error('Error in switchReportTab:', error);
            // Try to at least show the overview tab as fallback
            try {
                const overviewContent = document.getElementById('tab-overview');
                const overviewTab = document.querySelector('.nav-tab-item[data-tab="overview"]');
                if (overviewContent) {
                    document.querySelectorAll('.tab-content-section').forEach(function(section) {
                        if (section) {
                            section.classList.remove('active');
                            section.style.display = 'none';
                        }
                    });
                    overviewContent.classList.add('active');
                    overviewContent.style.display = 'block';
                    if (overviewTab) {
                        document.querySelectorAll('.nav-tab-item').forEach(function(item) {
                            if (item) item.classList.remove('active');
                        });
                        overviewTab.classList.add('active');
                    }
                }
            } catch (fallbackError) {
                console.error('Fallback also failed:', fallbackError);
            }
            return false;
        }
    };
})(); // End IIFE - function is now in global scope

// Initialize Charts - Advanced level charts for each tab
function initCharts() {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        return;
    }

    // Initialize charts based on active tab
    const activeTab = document.querySelector('.nav-tab-item.active');
    if (activeTab) {
        const tabName = activeTab.getAttribute('data-tab');
        initTabCharts(tabName);
    }
}

function initTabCharts(tabName) {
    try {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded, skipping chart initialization');
            return;
        }
        
        if (!tabName) {
            console.warn('No tab name provided for chart initialization');
            return;
        }
    
    switch(tabName) {
        case 'overview':
                try {
            initOverviewCharts();
                } catch (e) {
                    console.error('Error initializing overview charts:', e);
                }
            break;
        case 'grades':
                try {
            initGradesCharts();
                } catch (e) {
                    console.error('Error initializing grades charts:', e);
                }
            break;
        case 'attendance':
                try {
            initAttendanceCharts();
                } catch (e) {
                    console.error('Error initializing attendance charts:', e);
                }
            break;
        case 'quizzes':
                try {
            initQuizzesCharts();
                } catch (e) {
                    console.error('Error initializing quizzes charts:', e);
                }
            break;
        case 'assignments':
                try {
            initAssignmentsCharts();
                } catch (e) {
                    console.error('Error initializing assignments charts:', e);
                }
            break;
        case 'progress':
                try {
            initProgressCharts();
                } catch (e) {
                    console.error('Error initializing progress charts:', e);
                }
            break;
        case 'submissions':
                try {
            initSubmissionsCharts();
                } catch (e) {
                    console.error('Error initializing submissions charts:', e);
                }
            break;
        case 'completion':
                try {
            initCompletionCharts();
                } catch (e) {
                    console.error('Error initializing completion charts:', e);
                }
            break;
        case 'activity':
                try {
            initActivityCharts();
                } catch (e) {
                    console.error('Error initializing activity charts:', e);
                }
            break;
            default:
                console.warn('Unknown tab name for charts:', tabName);
        }
    } catch (error) {
        console.error('Error in initTabCharts:', error);
    }
}

function initOverviewCharts() {
    console.log('=== INITIALIZING OVERVIEW CHARTS ===');
    try {
        // Check if Chart.js is available
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded! Cannot initialize charts.');
            return;
        }
        
        console.log('Chart.js is available, proceeding with chart initialization...');
        
    // Overall Performance Trend Chart (Like Total Revenue in Marketing Dashboard)
    const overallTrendCtx = document.getElementById('overallPerformanceTrendChart');
    if (overallTrendCtx) {
            const monthlyLabels = <?php echo isset($monthly_labels) && is_array($monthly_labels) ? json_encode($monthly_labels) : '[]'; ?>;
            const monthlyProgress = <?php echo isset($monthly_progress) && is_array($monthly_progress) ? json_encode($monthly_progress) : '[]'; ?>;
            const monthlyGrades = <?php echo isset($monthly_grades) && is_array($monthly_grades) ? json_encode($monthly_grades) : '[]'; ?>;
            
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }
        
        new Chart(overallTrendCtx, {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Progress (%)',
                    data: monthlyProgress,
                    borderColor: 'rgba(59, 130, 246, 1)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3
                }, {
                    label: 'Average Grade (%)',
                    data: monthlyGrades,
                    borderColor: 'rgba(16, 185, 129, 1)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: { size: 12, weight: '600' },
                            padding: 15
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 60,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            },
                            font: { size: 11 }
                        },
                        grid: {
                            color: 'rgba(226, 232, 240, 0.5)'
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11 }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Course Performance Chart (Bar Chart)
    const coursePerfCtx = document.getElementById('coursePerformanceChart');
    if (coursePerfCtx) {
        const courseNames = <?php echo (isset($course_names) && is_array($course_names) && !empty($course_names)) ? json_encode($course_names) : '[]'; ?>;
        const courseGrades = <?php echo (isset($course_grades) && is_array($course_grades) && !empty($course_grades)) ? json_encode($course_grades) : '[]'; ?>;
        
        // Only initialize chart if we have data
        if (courseNames.length > 0 && courseGrades.length > 0 && typeof Chart !== 'undefined') {
            const coursePerfData = {
                labels: courseNames,
            datasets: [{
                label: 'Grade (%)',
                    data: courseGrades,
                backgroundColor: [
                    'rgba(139, 92, 246, 0.8)',  // Purple
                    'rgba(59, 130, 246, 0.8)',   // Blue
                    'rgba(16, 185, 129, 0.8)',   // Green
                    'rgba(245, 158, 11, 0.8)',   // Yellow
                    'rgba(249, 115, 22, 0.8)'    // Orange
                ],
                borderColor: [
                    'rgba(139, 92, 246, 1)',
                    'rgba(59, 130, 246, 1)',
                    'rgba(16, 185, 129, 1)',
                    'rgba(245, 158, 11, 1)',
                    'rgba(249, 115, 22, 1)'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        };
            try {
        new Chart(coursePerfCtx, {
            type: 'bar',
            data: coursePerfData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                            tooltip: { 
                                enabled: true,
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 13, weight: '600' },
                                bodyFont: { size: 14, weight: '700' },
                                cornerRadius: 8,
                                displayColors: false,
                                callbacks: {
                                    label: function(context) {
                                        return 'Grade: ' + context.parsed.y.toFixed(1) + '%';
                                    }
                                }
                            }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                                ticks: { 
                                    callback: function(value) { return value + '%'; },
                                    font: { size: 12, weight: '500' },
                                    color: '#64748b',
                                    stepSize: 20
                                },
                                grid: {
                                    color: 'rgba(226, 232, 240, 0.6)',
                                    lineWidth: 1
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 11, weight: '500' },
                                    color: '#475569'
                                },
                                grid: {
                                    display: false
                                }
                    }
                }
            }
        });
            } catch (e) {
                console.error('Error creating Course Performance chart:', e);
            }
        } else {
            console.warn('Course Performance chart: No data available or Chart.js not loaded');
        }
    }

    // Examination Results by Branch Chart (Grouped Bar Chart)
    const gradeDistCtx = document.getElementById('gradeDistributionChart');
    console.log('Examination Results Chart canvas found:', !!gradeDistCtx);
    if (gradeDistCtx) {
            // Real Examination Results data from database
            const examinationSubjects = <?php echo json_encode($examination_subjects); ?>;
            const examinationPass = <?php echo json_encode($examination_pass); ?>;
            const examinationFail = <?php echo json_encode($examination_fail); ?>;
            const examinationNotAttended = <?php echo json_encode($examination_not_attended); ?>;
            
            // Only initialize chart if we have real examination data
            if (examinationSubjects.length > 0 && typeof Chart !== 'undefined') {
                console.log('Initializing Examination Results chart with real data');
                const examResultsData = {
                    labels: examinationSubjects,
                    datasets: [
                        {
                            label: 'Pass',
                            data: examinationPass,
                            backgroundColor: 'rgba(139, 92, 246, 0.8)',  // Purple
                            borderColor: 'rgba(139, 92, 246, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Fail',
                            data: examinationFail,
                            backgroundColor: 'rgba(59, 130, 246, 0.6)',  // Light Blue
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Not Attended',
                            data: examinationNotAttended,
                            backgroundColor: 'rgba(6, 182, 212, 0.6)',  // Teal
                            borderColor: 'rgba(6, 182, 212, 1)',
                            borderWidth: 1
                        }
                    ]
                };
            
            try {
        new Chart(gradeDistCtx, {
            type: 'bar',
            data: examResultsData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                                labels: { 
                                    padding: 12, 
                                    font: { size: 12, weight: '600' },
                                    color: '#475569',
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                    },
                    tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 13, weight: '600' },
                                bodyFont: { size: 14, weight: '700' },
                                cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: { size: 12, weight: '500' },
                            color: '#64748b'
                        },
                        grid: {
                            color: 'rgba(226, 232, 240, 0.6)',
                            lineWidth: 1
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11, weight: '500' },
                            color: '#475569'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
            } catch (e) {
                console.error('Error creating Examination Results chart:', e);
            }
            } else {
                console.warn('Examination Results chart: No data available or Chart.js not loaded');
            }
    }

    // Progress Trend Chart (Line Chart)
    const progressTrendCtx = document.getElementById('progressTrendChart');
    console.log('Progress Trend Chart canvas found:', !!progressTrendCtx);
    if (progressTrendCtx) {
        const progressLabels = <?php echo (isset($progress_labels) && is_array($progress_labels) && !empty($progress_labels)) ? json_encode($progress_labels) : '[]'; ?>;
        const progressData = <?php echo (isset($progress_data) && is_array($progress_data) && !empty($progress_data)) ? json_encode($progress_data) : '[]'; ?>;
        
        console.log('Progress Trend data - Labels:', progressLabels, 'Data:', progressData);
        
        // Only initialize chart if we have data
        if (progressLabels.length > 0 && progressData.length > 0 && typeof Chart !== 'undefined') {
            console.log('Initializing Progress Trend chart with', progressLabels.length, 'data points');
        const progressTrendData = {
                labels: progressLabels,
            datasets: [{
                label: 'Score (%)',
                    data: progressData,
                borderColor: [
                    'rgba(139, 92, 246, 1)',  // Purple
                    'rgba(59, 130, 246, 1)',   // Blue
                    'rgba(6, 182, 212, 1)'     // Teal
                ],
                backgroundColor: [
                    'rgba(139, 92, 246, 0.1)',
                    'rgba(59, 130, 246, 0.1)',
                    'rgba(6, 182, 212, 0.1)'
                ],
                fill: true,
                tension: 0.4,
                borderWidth: 3,
                pointRadius: 5,
                pointBackgroundColor: [
                    'rgba(139, 92, 246, 1)',
                    'rgba(59, 130, 246, 1)',
                    'rgba(6, 182, 212, 1)'
                ],
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        };
            try {
        new Chart(progressTrendCtx, {
            type: 'line',
            data: progressTrendData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 13, weight: '600' },
                                bodyFont: { size: 14, weight: '700' },
                                cornerRadius: 8,
                                displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Progress: ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                                ticks: { 
                                    callback: function(value) { return value + '%'; },
                                    font: { size: 12, weight: '500' },
                                    color: '#64748b',
                                    stepSize: 20
                                },
                                grid: {
                                    color: 'rgba(226, 232, 240, 0.6)',
                                    lineWidth: 1
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 11, weight: '500' },
                                    color: '#475569'
                                },
                                grid: {
                                    display: false
                                }
                    }
                }
            }
        });
            } catch (e) {
                console.error('Error creating Progress Trend chart:', e);
            }
        } else {
            console.warn('Progress Trend chart: No data available or Chart.js not loaded');
        }
    }

    // Activity Completion Chart (Bar Chart)
    const activityCtx = document.getElementById('activityChart');
    console.log('Activity Chart canvas found:', !!activityCtx);
    if (activityCtx) {
        const activityTypes = <?php echo (isset($activity_types) && is_array($activity_types) && !empty($activity_types)) ? json_encode($activity_types) : '[]'; ?>;
        const activityCompletion = <?php echo (isset($activity_completion) && is_array($activity_completion) && !empty($activity_completion)) ? json_encode($activity_completion) : '[]'; ?>;
        
        console.log('Activity Chart data - Types:', activityTypes, 'Completion:', activityCompletion);
        
        // Only initialize chart if we have data
        if (activityTypes.length > 0 && activityCompletion.length > 0 && typeof Chart !== 'undefined') {
            console.log('Initializing Activity Chart with', activityTypes.length, 'activity types');
        const activityData = {
                labels: activityTypes,
            datasets: [{
                label: 'Completion Rate (%)',
                    data: activityCompletion,
                backgroundColor: [
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(236, 72, 153, 0.8)'
                ],
                borderColor: [
                    'rgba(59, 130, 246, 1)',
                    'rgba(16, 185, 129, 1)',
                    'rgba(245, 158, 11, 1)',
                    'rgba(139, 92, 246, 1)',
                    'rgba(236, 72, 153, 1)'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        };
            try {
        new Chart(activityCtx, {
            type: 'bar',
            data: activityData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 13, weight: '600' },
                                bodyFont: { size: 14, weight: '700' },
                                cornerRadius: 8,
                                displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Completion: ' + context.parsed.x.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                                ticks: { 
                                    callback: function(value) { return value + '%'; },
                                    font: { size: 12, weight: '500' },
                                    color: '#64748b',
                                    stepSize: 20
                                },
                                grid: {
                                    color: 'rgba(226, 232, 240, 0.6)',
                                    lineWidth: 1
                                }
                            },
                            y: {
                                ticks: {
                                    font: { size: 11, weight: '500' },
                                    color: '#475569'
                                },
                                grid: {
                                    display: false
                                }
                    }
                }
            }
        });
            } catch (e) {
                console.error('Error creating Activity Chart:', e);
            }
        } else {
            console.warn('Activity Chart: No data available or Chart.js not loaded');
        }
    }
    
    console.log('=== OVERVIEW CHARTS INITIALIZATION COMPLETE ===');
    } catch (error) {
        console.error('Error in initOverviewCharts:', error);
    }
}

// Grades Charts
function initGradesCharts() {
    try {
    // Grades Comparison Chart
    const gradesCompCtx = document.getElementById('gradesComparisonChart');
    if (gradesCompCtx) {
        const data = {
            labels: <?php echo (isset($grades_chart_courses) && is_array($grades_chart_courses)) ? json_encode($grades_chart_courses) : '[]'; ?>,
            datasets: [{
                label: 'Grade (%)',
                data: <?php echo (isset($grades_chart_percentages) && is_array($grades_chart_percentages)) ? json_encode($grades_chart_percentages) : '[]'; ?>,
                backgroundColor: [
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(236, 72, 153, 0.8)'
                ],
                borderColor: [
                    'rgba(59, 130, 246, 1)',
                    'rgba(16, 185, 129, 1)',
                    'rgba(245, 158, 11, 1)',
                    'rgba(139, 92, 246, 1)',
                    'rgba(236, 72, 153, 1)'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        };
        new Chart(gradesCompCtx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    
    // Grade Distribution Detailed Chart
    const gradeDistDetailedCtx = document.getElementById('gradeDistributionDetailedChart');
    if (gradeDistDetailedCtx) {
        const distribution = <?php echo isset($grade_distribution_data) ? json_encode($grade_distribution_data) : '{}'; ?>;
        const gradeDistData = {
            labels: ['A', 'B', 'C', 'D', 'F'],
            datasets: [{
                data: [
                    distribution.A || 0,
                    distribution.B || 0,
                    distribution.C || 0,
                    distribution.D || 0,
                    distribution.F || 0
                ],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(251, 146, 60, 0.8)',
                    'rgba(239, 68, 68, 0.8)'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        };
        new Chart(gradeDistDetailedCtx, {
            type: 'doughnut',
            data: gradeDistData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + ' courses';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Grade Trend Chart
    const gradeTrendCtx = document.getElementById('gradeTrendChart');
    if (gradeTrendCtx) {
        const data = {
            labels: <?php echo isset($grades_chart_courses) ? json_encode($grades_chart_courses) : '[]'; ?>,
            datasets: [{
                label: 'Grade Trend',
                data: <?php echo isset($grades_chart_percentages) ? json_encode($grades_chart_percentages) : '[]'; ?>,
                borderColor: 'rgba(139, 92, 246, 1)',
                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 3,
                pointRadius: 5,
                pointBackgroundColor: 'rgba(139, 92, 246, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        };
        new Chart(gradeTrendCtx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    
    // Grade Items Chart
    const gradeItemsCtx = document.getElementById('gradeItemsChart');
    if (gradeItemsCtx) {
        const itemsData = <?php echo isset($grade_items_data) ? json_encode($grade_items_data) : '[]'; ?>;
        const labels = itemsData.slice(0, 10).map(i => i.name);
        const percentages = itemsData.slice(0, 10).map(i => i.percentage);
        new Chart(gradeItemsCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Percentage',
                    data: percentages,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } }
                }
            }
        });
    }
    } catch (error) {
        console.error('Error in initGradesCharts:', error);
    }
}

// Attendance Charts
function initAttendanceCharts() {
    try {
    const trendCtx = document.getElementById('attendanceTrendChart');
    if (trendCtx) {
        const dates = <?php echo isset($attendance_dates) && is_array($attendance_dates) ? json_encode($attendance_dates) : '[]'; ?>;
        const present = <?php echo isset($attendance_present) && is_array($attendance_present) ? json_encode($attendance_present) : '[]'; ?>;
        const absent = <?php echo isset($attendance_absent) && is_array($attendance_absent) ? json_encode($attendance_absent) : '[]'; ?>;
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Present',
                    data: present,
                    borderColor: 'rgba(16, 185, 129, 1)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true
                }, {
                    label: 'Absent',
                    data: absent,
                    borderColor: 'rgba(239, 68, 68, 1)',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });
    }
    
    const statusCtx = document.getElementById('attendanceStatusChart');
    if (statusCtx) {
        const presentTotal = <?php echo isset($present_count) ? (int)$present_count : 0; ?>;
        const absentTotal = <?php echo isset($attendance_count) && isset($present_count) ? (int)($attendance_count - $present_count) : 0; ?>;
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Absent'],
                datasets: [{
                    data: [presentTotal, absentTotal],
                    backgroundColor: ['rgba(16, 185, 129, 0.8)', 'rgba(239, 68, 68, 0.8)']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    
    const byCourseCtx = document.getElementById('attendanceByCourseChart');
    if (byCourseCtx) {
        const courseData = <?php echo isset($attendance_by_course) ? json_encode($attendance_by_course) : '[]'; ?>;
        const courses = courseData.map(c => c.course);
        const rates = courseData.map(c => c.rate);
        new Chart(byCourseCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: rates,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    } catch (error) {
        console.error('Error in initAttendanceCharts:', error);
    }
}

// Quizzes Charts
function initQuizzesCharts() {
    try {
    const scoresCtx = document.getElementById('quizScoresChart');
    if (scoresCtx) {
        const names = <?php echo isset($quiz_names) ? json_encode($quiz_names) : '[]'; ?>;
        const percentages = <?php echo isset($quiz_percentages) ? json_encode($quiz_percentages) : '[]'; ?>;
        new Chart(scoresCtx, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [{
                    label: 'Score (%)',
                    data: percentages,
                    backgroundColor: 'rgba(139, 92, 246, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    
    const trendCtx = document.getElementById('quizTrendChart');
    if (trendCtx) {
        const dates = <?php echo isset($quiz_dates) ? json_encode($quiz_dates) : '[]'; ?>;
        const percentages = <?php echo isset($quiz_percentages) ? json_encode($quiz_percentages) : '[]'; ?>;
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Performance',
                    data: percentages,
                    borderColor: 'rgba(236, 72, 153, 1)',
                    backgroundColor: 'rgba(236, 72, 153, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    
    const byCourseCtx = document.getElementById('quizByCourseChart');
    if (byCourseCtx) {
        const courseData = <?php echo isset($quiz_by_course) ? json_encode($quiz_by_course) : '[]'; ?>;
        const courses = courseData.map(c => c.course);
        const averages = courseData.map(c => c.average);
        new Chart(byCourseCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Average Score (%)',
                    data: averages,
                    backgroundColor: 'rgba(245, 158, 11, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    } catch (error) {
        console.error('Error in initQuizzesCharts:', error);
    }
}

// Assignments Charts
function initAssignmentsCharts() {
    try {
    const statusCtx = document.getElementById('assignmentStatusChart');
    if (statusCtx) {
        const upcoming = <?php echo isset($upcoming) ? (int)$upcoming : 0; ?>;
        const past = <?php echo isset($past) ? (int)$past : 0; ?>;
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Upcoming', 'Past Due'],
                datasets: [{
                    data: [upcoming, past],
                    backgroundColor: ['rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    
    const byCourseCtx = document.getElementById('assignmentByCourseChart');
    if (byCourseCtx) {
        const courseData = <?php echo isset($assignment_by_course) ? json_encode($assignment_by_course) : '[]'; ?>;
        const courses = courseData.map(c => c.course);
        const totals = courseData.map(c => c.total);
        new Chart(byCourseCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Total Assignments',
                    data: totals,
                    backgroundColor: 'rgba(14, 184, 166, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }
    } catch (error) {
        console.error('Error in initAssignmentsCharts:', error);
    }
}

// Progress Charts
function initProgressCharts() {
    try {
    // Progress Comparison Chart
    const progressCompCtx = document.getElementById('progressComparisonChart');
    if (progressCompCtx) {
        const courses = <?php echo isset($progress_chart_courses) ? json_encode($progress_chart_courses) : '[]'; ?>;
        const percentages = <?php echo isset($progress_chart_percentages) ? json_encode($progress_chart_percentages) : '[]'; ?>;
        new Chart(progressCompCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Progress (%)',
                    data: percentages,
                    backgroundColor: 'rgba(6, 182, 212, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
    
    // Progress Distribution Chart
    const progressDistCtx = document.getElementById('progressDistributionChart');
    if (progressDistCtx) {
        const percentages = <?php echo isset($progress_chart_percentages) ? json_encode($progress_chart_percentages) : '[]'; ?>;
        const completed = percentages.filter(p => p >= 100).length;
        const inProgress = percentages.filter(p => p > 0 && p < 100).length;
        const notStarted = percentages.filter(p => p === 0).length;
        new Chart(progressDistCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress', 'Not Started'],
                datasets: [{
                    data: [completed, inProgress, notStarted],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(148, 163, 184, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    
    // Activities Completion Chart
    const activitiesCtx = document.getElementById('activitiesCompletionChart');
    if (activitiesCtx) {
        const courses = <?php echo isset($progress_chart_courses) ? json_encode($progress_chart_courses) : '[]'; ?>;
        const completed = <?php echo isset($progress_chart_completed) ? json_encode($progress_chart_completed) : '[]'; ?>;
        const total = <?php echo isset($progress_chart_total) ? json_encode($progress_chart_total) : '[]'; ?>;
        new Chart(activitiesCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Completed',
                    data: completed,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)'
                }, {
                    label: 'Total',
                    data: total,
                    backgroundColor: 'rgba(148, 163, 184, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            }
        });
    }
    } catch (error) {
        console.error('Error in initProgressCharts:', error);
    }
}

// Submissions Charts
function initSubmissionsCharts() {
    try {
    // Submission Timeline Chart
    const timelineCtx = document.getElementById('submissionTimelineChart');
    if (timelineCtx) {
        const dates = <?php echo isset($submission_dates) ? json_encode($submission_dates) : '[]'; ?>;
        const counts = <?php echo isset($submission_counts) ? json_encode($submission_counts) : '[]'; ?>;
        new Chart(timelineCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Submissions',
                    data: counts,
                    borderColor: 'rgba(14, 184, 166, 1)',
                    backgroundColor: 'rgba(14, 184, 166, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }
    
    // Submission Status Chart
    const statusCtx = document.getElementById('submissionStatusChart');
    if (statusCtx) {
        const submitted = <?php echo isset($submission_status_breakdown['submitted']) ? (int)$submission_status_breakdown['submitted'] : 0; ?>;
        const draft = <?php echo isset($submission_status_breakdown['draft']) ? (int)$submission_status_breakdown['draft'] : 0; ?>;
        const newCount = <?php echo isset($submission_status_breakdown['new']) ? (int)$submission_status_breakdown['new'] : 0; ?>;
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Submitted', 'Draft', 'New'],
                datasets: [{
                    data: [submitted, draft, newCount],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    
    // Submission by Course Chart
    const byCourseCtx = document.getElementById('submissionByCourseChart');
    if (byCourseCtx) {
        const courseData = <?php echo isset($submission_by_course) ? json_encode($submission_by_course) : '[]'; ?>;
        const courses = courseData.map(c => c.course);
        const counts = courseData.map(c => c.count);
        new Chart(byCourseCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Submissions',
                    data: counts,
                    backgroundColor: 'rgba(139, 92, 246, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } }
            }
        });
    }
    } catch (error) {
        console.error('Error in initSubmissionsCharts:', error);
    }
}

// Completion Charts
function initCompletionCharts() {
    try {
    // Completion by Month Chart
    const byMonthCtx = document.getElementById('completionByMonthChart');
    if (byMonthCtx) {
        const monthData = <?php echo isset($completion_by_month) ? json_encode($completion_by_month) : '[]'; ?>;
        const months = monthData.map(m => m.month);
        const counts = monthData.map(m => m.count);
        new Chart(byMonthCtx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    label: 'Completions',
                    data: counts,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }
    
    // Completion Status Chart
    const statusCtx = document.getElementById('completionStatusChart');
    if (statusCtx) {
        const completed = <?php echo isset($completed_courses) ? (int)$completed_courses : 0; ?>;
        const total = <?php echo isset($total_enrolled) ? (int)$total_enrolled : 0; ?>;
        const inProgress = total - completed;
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress'],
                datasets: [{
                    data: [completed, inProgress],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    } catch (error) {
        console.error('Error in initCompletionCharts:', error);
    }
}

// Activity Charts
function initActivityCharts() {
    try {
    // Activity Timeline Chart
    const timelineCtx = document.getElementById('activityTimelineChart');
    if (timelineCtx) {
        const dates = <?php echo isset($activity_dates) ? json_encode($activity_dates) : '[]'; ?>;
        const counts = <?php echo isset($activity_counts) ? json_encode($activity_counts) : '[]'; ?>;
        new Chart(timelineCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Activities',
                    data: counts,
                    borderColor: 'rgba(139, 92, 246, 1)',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }
    
    // Activity by Type Chart
    const byTypeCtx = document.getElementById('activityByTypeChart');
    if (byTypeCtx) {
        const typeData = <?php echo isset($activity_by_type) ? json_encode($activity_by_type) : '[]'; ?>;
        const types = typeData.map(t => t.type);
        const counts = typeData.map(t => t.count);
        new Chart(byTypeCtx, {
            type: 'doughnut',
            data: {
                labels: types,
                datasets: [{
                    data: counts,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(236, 72, 153, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
    
    // Activity by Course Chart
    const byCourseCtx = document.getElementById('activityByCourseChart');
    if (byCourseCtx) {
        const courseData = <?php echo isset($activity_by_course) ? json_encode($activity_by_course) : '[]'; ?>;
        const courses = courseData.map(c => c.course);
        const counts = courseData.map(c => c.count);
        new Chart(byCourseCtx, {
            type: 'bar',
            data: {
                labels: courses,
                datasets: [{
                    label: 'Activities',
                    data: counts,
                    backgroundColor: 'rgba(8, 145, 178, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } }
            }
        });
    }
    } catch (error) {
        console.error('Error in initActivityCharts:', error);
    }
}

// Initialize tabs function
function initializeReportTabs() {
    console.log('Initializing report tabs...');
    
    // Ensure switchReportTab function is available
    if (typeof window.switchReportTab !== 'function') {
        console.error('switchReportTab function is not defined!');
        return;
    }
    
    // Attach click event listeners to all tab items
    const tabItems = document.querySelectorAll('.nav-tab-item');
    console.log('Found tab items:', tabItems.length);
    
    if (tabItems.length === 0) {
        console.error('No tab items found! Check if tabs are rendered in HTML.');
        return;
    }
    
    // Simple, direct approach - attach listeners to each tab
    tabItems.forEach(function(tabItem, index) {
        // Set cursor and ensure it's clickable
        tabItem.style.cursor = 'pointer';
        tabItem.style.userSelect = 'none';
        
        // Make child elements non-interactive so clicks go to parent
        const icon = tabItem.querySelector('i');
        if (icon) {
            icon.style.pointerEvents = 'none';
        }
        
        // Add click listener
        tabItem.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const tabName = this.getAttribute('data-tab');
            console.log('Tab clicked - Name:', tabName, 'Index:', index);
            
            if (tabName) {
                if (typeof window.switchReportTab === 'function') {
                    window.switchReportTab(tabName);
                } else {
                    console.error('switchReportTab is not a function');
                    // Fallback: manual tab switching
                    document.querySelectorAll('.tab-content-section').forEach(function(section) {
                        section.classList.remove('active');
                        section.style.display = 'none';
                    });
                    document.querySelectorAll('.nav-tab-item').forEach(function(item) {
                        item.classList.remove('active');
                    });
                    
                    const targetSection = document.getElementById('tab-' + tabName);
                    if (targetSection) {
                        targetSection.classList.add('active');
                        targetSection.style.display = 'block';
                        this.classList.add('active');
                    }
                }
            }
            
            return false;
        };
    });
    
    // Ensure overview tab is visible on load
    const overviewTab = document.getElementById('tab-overview');
    const overviewTabItem = document.querySelector('.nav-tab-item[data-tab="overview"]');
    
    if (overviewTab) {
        overviewTab.classList.add('active');
        overviewTab.style.display = 'block';
        console.log('Overview tab activated');
    } else {
        console.error('Overview tab element not found!');
    }
    
    if (overviewTabItem) {
        overviewTabItem.classList.add('active');
        console.log('Overview tab item activated');
    } else {
        console.error('Overview tab item not found!');
    }
    
    // Hide all other tabs
    const allTabs = document.querySelectorAll('.tab-content-section');
    console.log('Total tab content sections found:', allTabs.length);
    
    // Verify all expected tabs exist
    const expectedTabs = ['overview', 'grades', 'attendance', 'quizzes', 'assignments', 'progress', 'submissions', 'completion', 'activity'];
    expectedTabs.forEach(function(tabName) {
        const tabElement = document.getElementById('tab-' + tabName);
        if (!tabElement) {
            console.warn('Tab content section not found:', 'tab-' + tabName);
        }
    });
    
    // Retry initialization if tabs weren't found (for dynamically loaded content)
    if (allTabs.length === 0) {
        console.log('No tabs found, will retry in 500ms...');
        setTimeout(function() {
            const retryTabs = document.querySelectorAll('.tab-content-section');
            if (retryTabs.length > 0) {
                console.log('Tabs found on retry:', retryTabs.length);
                // Re-initialize
                const overviewTab = document.getElementById('tab-overview');
                if (overviewTab) {
                    overviewTab.classList.add('active');
                    overviewTab.style.display = 'block';
                }
            }
        }, 500);
    }
    
    for (let i = 0; i < allTabs.length; i++) {
        if (allTabs[i].id !== 'tab-overview') {
            allTabs[i].classList.remove('active');
            allTabs[i].style.display = 'none';
        }
    }
    
    // Initialize charts after a short delay to ensure Chart.js is loaded
    setTimeout(function() {
        console.log('Attempting to initialize charts...');
        console.log('Chart.js available:', typeof Chart !== 'undefined');
        console.log('initOverviewCharts function available:', typeof initOverviewCharts === 'function');
        
        if (typeof Chart === 'undefined') {
            console.error('Chart.js library not loaded! Check if CDN script is included.');
            // Try to load Chart.js dynamically
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js';
            script.onload = function() {
                console.log('Chart.js loaded dynamically, initializing charts...');
                if (typeof initOverviewCharts === 'function') {
        initOverviewCharts();
                }
            };
            document.head.appendChild(script);
            return;
        }
        
        if (typeof initOverviewCharts === 'function') {
            try {
                initOverviewCharts();
                console.log('Overview charts initialized successfully');
            } catch (e) {
                console.error('Error initializing overview charts:', e);
            }
        } else {
            console.error('initOverviewCharts function not found');
        }
    }, 500);
}

// Initialize immediately if DOM is ready, otherwise wait
(function initTabs() {
    function safeInit() {
        try {
            initializeReportTabs();
            
            // Initialize tab from URL parameter on page load
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'overview';
            const course = urlParams.get('course');
            
            // Update course filter dropdown if course parameter exists
            if (course) {
                const courseFilter = document.getElementById('courseFilter');
                if (courseFilter) {
                    courseFilter.value = course;
                }
            }
            
            // Switch to the correct tab from URL
            if (tab && typeof window.switchReportTab === 'function') {
                setTimeout(function() {
                    window.switchReportTab(tab);
                }, 100);
            }
        } catch (error) {
            console.error('Error initializing tabs:', error);
            // Retry after a delay
            setTimeout(function() {
                try {
                    initializeReportTabs();
                } catch (retryError) {
                    console.error('Retry also failed:', retryError);
                    // Manual fallback - ensure overview tab is shown
                    const overviewTab = document.getElementById('tab-overview');
                    const overviewTabItem = document.querySelector('.nav-tab-item[data-tab="overview"]');
                    if (overviewTab) {
                        document.querySelectorAll('.tab-content-section').forEach(function(section) {
                            if (section) {
                                section.style.display = 'none';
                                section.classList.remove('active');
                            }
                        });
                        overviewTab.style.display = 'block';
                        overviewTab.classList.add('active');
                    }
                    if (overviewTabItem) {
                        document.querySelectorAll('.nav-tab-item').forEach(function(item) {
                            if (item) item.classList.remove('active');
                        });
                        overviewTabItem.classList.add('active');
                    }
                }
            }, 500);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', safeInit);
        window.addEventListener('load', safeInit);
    } else {
        safeInit();
    }
    
    // Also try after a delay as a backup
    setTimeout(safeInit, 1000);
})();

// Also try after a short delay as backup
setTimeout(function() {
    const tabItems = document.querySelectorAll('.nav-tab-item');
    if (tabItems.length > 0) {
        console.log('Backup initialization - tabs found:', tabItems.length);
        // Just ensure the function exists and tabs are clickable
        tabItems.forEach(function(item) {
            if (!item.onclick && !item.getAttribute('onclick')) {
                const tabName = item.getAttribute('data-tab');
                if (tabName) {
                    item.onclick = function() {
                        if (typeof window.switchReportTab === 'function') {
                            window.switchReportTab(tabName);
                        }
                        return false;
                    };
                }
            }
        });
    }
}, 1000);

// Handle browser back/forward buttons to maintain tab and course filter state
window.addEventListener('popstate', function(event) {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'overview';
    const course = urlParams.get('course');
    
    // Update course filter dropdown if course parameter exists
    const courseFilter = document.getElementById('courseFilter');
    if (courseFilter) {
        if (course) {
            courseFilter.value = course;
        } else {
            courseFilter.value = 'all';
        }
    }
    
    // Switch to the correct tab
    if (typeof window.switchReportTab === 'function') {
        // Use a small delay to ensure DOM is ready
        setTimeout(function() {
            window.switchReportTab(tab);
        }, 50);
    }
});

// Initialize tab from URL parameter on page load
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'overview';
    
    if (tab && typeof window.switchReportTab === 'function') {
        // Wait for DOM to be fully ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    window.switchReportTab(tab);
                }, 200);
            });
        } else {
            setTimeout(function() {
                window.switchReportTab(tab);
            }, 200);
        }
    }
})();
</script>

        </div>
    </div>
</div>

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

<?php echo $OUTPUT->footer(); ?>
