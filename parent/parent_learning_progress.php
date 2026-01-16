<?php
/**
 * Parent Dashboard - Learning Progress Hub (UNIFIED)
 * Combines Progress Reports, Activities, and Lessons in one page
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

// Include helper functions for better data fetching
require_once(__DIR__ . '/../dashboard/dashboard_stats_function.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_learning_progress.php');
$PAGE->set_title('Learning Progress - Parent Dashboard');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Course filter parameter
$selected_course_param = optional_param('course', 'all', PARAM_RAW);
$selected_course_id = ($selected_course_param === 'all' || $selected_course_param == 0 || empty($selected_course_param)) ? 'all' : (int)$selected_course_param;

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
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

// Get child courses for filter dropdown
$child_courses = [];
if ($selected_child_id) {
    $enrolled = enrol_get_users_courses($selected_child_id, true, 'id, fullname, shortname');
    foreach ($enrolled as $course) {
        $child_courses[$course->id] = $course;
    }
}

$target_children = [];
if ($selected_child_id) {
    $target_children = [$selected_child_id];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

// ==================== PROGRESS REPORTS ====================
$progress_data = [];
$course_details = [];
$overall_stats = [
    'total_courses' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'completion_rate' => 0,
    'lessons_completed' => 0,
    'activities_completed' => 0,
    'overall_progress' => 0
];

// Use helper functions for better data if single child selected
$enhanced_stats = null;
$detailed_course_stats = [];
$activity_type_stats = [];
if ($selected_child_id && function_exists('get_real_dashboard_stats')) {
    try {
        $enhanced_stats = get_real_dashboard_stats($selected_child_id);
        if (function_exists('get_detailed_course_stats')) {
            $detailed_course_stats = get_detailed_course_stats($selected_child_id);
        }
        if (function_exists('get_activity_type_stats')) {
            $activity_type_stats = get_activity_type_stats($selected_child_id);
        }
    } catch (Exception $e) {
        debugging('Error fetching enhanced stats: ' . $e->getMessage());
    }
}

if (!empty($target_children)) {
    foreach ($target_children as $child_id) {
        $child_info = null;
        foreach ($children as $c) {
            if ($c['id'] == $child_id) {
                $child_info = $c;
                break;
            }
        }
        
        if (!$child_info) {
            continue;
        }
        
        try {
            $courses = enrol_get_users_courses($child_id, true);
            
            if (empty($courses)) {
                continue;
            }
            
            $completed = 0;
            $in_progress = 0;
            $not_started = 0;
            $course_details_list = [];
            
            // Apply course filter if specific course is selected
            $filtered_courses = $courses;
            if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
                $filtered_courses = array_filter($courses, function($course) use ($selected_course_id) {
                    return $course->id == $selected_course_id;
                });
            }
            
            foreach ($filtered_courses as $course) {
                try {
                $completion = new completion_info($course);
                $course_info = [
                    'id' => $course->id,
                        'name' => $course->fullname ?? 'Unnamed Course',
                        'shortname' => $course->shortname ?? '',
                    'is_complete' => false,
                    'progress_percentage' => 0,
                    'completed_activities' => 0,
                    'total_activities' => 0,
                    'completion_enabled' => false
                ];
                
                if ($completion->is_enabled()) {
                    $course_info['completion_enabled'] = true;
                    
                        try {
                    if ($completion->is_course_complete($child_id)) {
                        $completed++;
                        $course_info['is_complete'] = true;
                        $course_info['progress_percentage'] = 100;
                    } else {
                                // Optimized query with better error handling
                        $sql_completion = "SELECT COUNT(*) as total,
                                                 COUNT(CASE WHEN cmc.completionstate > 0 THEN 1 END) as completed
                                          FROM {course_modules} cm
                                          LEFT JOIN {course_modules_completion} cmc 
                                              ON cmc.coursemoduleid = cm.id AND cmc.userid = :userid
                                          WHERE cm.course = :courseid
                                          AND cm.completion > 0
                                                  AND cm.deletioninprogress = 0
                                                  AND cm.visible = 1";
                        
                            $completion_stats = $DB->get_record_sql($sql_completion, [
                                'userid' => $child_id,
                                'courseid' => $course->id
                            ]);
                            
                                if ($completion_stats && isset($completion_stats->total) && $completion_stats->total > 0) {
                                    $total = (int)$completion_stats->total;
                                    $completed_count = (int)($completion_stats->completed ?? 0);
                                    
                                    $course_info['total_activities'] = $total;
                                    $course_info['completed_activities'] = $completed_count;
                                    $course_info['progress_percentage'] = min(100, round(($completed_count / $total) * 100, 1));
                                
                                    if ($completed_count > 0) {
                                    $in_progress++;
                                    } else {
                                        $not_started++;
                                    }
                                } else {
                                    $not_started++;
                                }
                            }
                        } catch (Exception $e) {
                            debugging("Error checking completion for course {$course->id}: " . $e->getMessage());
                            $not_started++;
                    }
                } else {
                    $not_started++;
                }
                
                $course_details_list[] = $course_info;
                } catch (Exception $e) {
                    debugging("Error processing course {$course->id}: " . $e->getMessage());
                    continue;
                }
            }
            
            $total_courses = count($filtered_courses);
            $completion_rate = 0;
            if ($total_courses > 0) {
                $completion_rate = round(($completed / $total_courses) * 100, 1);
            }
            
            $progress_data[] = [
                'child_id' => $child_id,
                'child_name' => $child_info['name'] ?? 'Unknown',
                'total_courses' => $total_courses,
                'completed' => (int)$completed,
                'in_progress' => (int)$in_progress,
                'not_started' => (int)$not_started,
                'completion_rate' => $completion_rate,
                'courses' => $course_details_list
            ];
            
            $overall_stats['total_courses'] += $total_courses;
            $overall_stats['completed'] += $completed;
            $overall_stats['in_progress'] += $in_progress;
            $overall_stats['not_started'] += $not_started;
        } catch (Exception $e) {
            debugging("Error fetching courses for child {$child_id}: " . $e->getMessage());
            continue;
        }
    }
    
    if ($overall_stats['total_courses'] > 0) {
        $overall_stats['completion_rate'] = round(($overall_stats['completed'] / $overall_stats['total_courses']) * 100, 1);
    }
    
    // Enhance stats with helper function data if available
    if ($enhanced_stats && is_array($enhanced_stats)) {
        if (isset($enhanced_stats['lessons_completed'])) {
            $overall_stats['lessons_completed'] = $enhanced_stats['lessons_completed'];
        }
        if (isset($enhanced_stats['activities_completed'])) {
            $overall_stats['activities_completed'] = $enhanced_stats['activities_completed'];
        }
        if (isset($enhanced_stats['overall_progress'])) {
            $overall_stats['overall_progress'] = $enhanced_stats['overall_progress'];
        }
    }
}

// ==================== ACTIVITIES ====================
// EXACT SAME LOGIC AS parent_activities.php
$activities_by_type = [];
$activity_stats = [
    'total' => 0,
    'assignments' => 0,
    'quizzes' => 0,
    'resources' => 0,
    'forums' => 0,
    'other' => 0
];

if (!empty($target_children)) {
    try {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);
    
        // Get recent activity logs for better detail - optimized query
    $params['last_30_days'] = time() - (30 * 24 * 60 * 60);
    
    // Apply course filter to activities query
    $course_filter_sql = '';
    if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
        $course_filter_sql = ' AND l.courseid = :courseid';
        $params['courseid'] = $selected_course_id;
    }
    
    $sql = "SELECT l.id, l.userid, l.timecreated, l.action, l.target, l.courseid,
                   c.fullname as coursename,
                   u.firstname, u.lastname,
                   l.component, l.eventname
            FROM {logstore_standard_log} l
            JOIN {course} c ON c.id = l.courseid
            JOIN {user} u ON u.id = l.userid
            WHERE l.userid $insql
            AND l.courseid > 1
            AND l.timecreated >= :last_30_days
            AND l.action IN ('viewed', 'submitted', 'created', 'updated', 'started', 'answered')
            $course_filter_sql
            ORDER BY l.timecreated DESC
            LIMIT 50";
    
        $activity_records = $DB->get_records_sql($sql, $params);
        
        if (!empty($activity_records)) {
        foreach ($activity_records as $activity) {
                if (!isset($activity->component) || !isset($activity->action)) {
                    continue;
                }
                
            $activity_stats['total']++;
            
                // Determine activity type from component - improved logic
            $type = 'Other';
            $icon = 'fa-circle';
            $color = '#6b7280';
                $component_lower = strtolower($activity->component ?? '');
                $target_lower = strtolower($activity->target ?? '');
            
                if (strpos($component_lower, 'assign') !== false || strpos($target_lower, 'assign') !== false) {
                $type = 'Assignment';
                $icon = 'fa-file-alt';
                $color = '#3b82f6';
                $activity_stats['assignments']++;
                } elseif (strpos($component_lower, 'quiz') !== false || strpos($target_lower, 'quiz') !== false) {
                $type = 'Quiz';
                $icon = 'fa-question-circle';
                $color = '#8b5cf6';
                $activity_stats['quizzes']++;
                } elseif (strpos($component_lower, 'resource') !== false || strpos($target_lower, 'resource') !== false || 
                          strpos($component_lower, 'book') !== false || strpos($component_lower, 'page') !== false) {
                $type = 'Resource';
                $icon = 'fa-book';
                $color = '#10b981';
                $activity_stats['resources']++;
                } elseif (strpos($component_lower, 'forum') !== false || strpos($target_lower, 'forum') !== false) {
                $type = 'Forum';
                $icon = 'fa-comments';
                $color = '#f59e0b';
                $activity_stats['forums']++;
            } else {
                $activity_stats['other']++;
            }
            
                // Format action safely
                $action_text = ucfirst(strtolower($activity->action ?? 'activity'));
            
            if (!isset($activities_by_type[$type])) {
                $activities_by_type[$type] = [];
            }
            
            $activities_by_type[$type][] = [
                    'id' => $activity->id ?? 0,
                    'student' => fullname($activity) ?? 'Unknown',
                    'course' => $activity->coursename ?? 'Unknown Course',
                'action' => $action_text,
                    'time' => $activity->timecreated ?? time(),
                    'component' => $activity->component ?? '',
                'icon' => $icon,
                'color' => $color
            ];
        }
        
        // Sort each type by most recent
        foreach ($activities_by_type as $type => $items) {
            usort($activities_by_type[$type], function($a, $b) {
                    return ($b['time'] ?? 0) - ($a['time'] ?? 0);
            });
        }
        }
    } catch (Exception $e) {
        debugging('Error fetching activities: ' . $e->getMessage());
        error_log("Learning Progress - Activity fetch error: " . $e->getMessage());
    }
}

// ==================== LESSONS ====================
// EXACT SAME LOGIC AS parent_lessons.php - Full section-based fetching
$all_activities = [];
$courses_with_sections = [];
$lessons_stats = [
    'total' => 0,
    'lesson' => 0,
    'assign' => 0,
    'quiz' => 0,
    'resource' => 0,
    'page' => 0,
    'forum' => 0,
    'url' => 0,
    'other' => 0
];

if (!empty($target_children)) {
    foreach ($target_children as $child_id) {
        try {
            // Get enrolled courses with error handling
            $enrolled_courses = enrol_get_users_courses($child_id, true);
            
            if (empty($enrolled_courses)) {
                continue;
            }
            
            // Apply course filter if specific course is selected
            $filtered_enrolled_courses = $enrolled_courses;
            if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
                $filtered_enrolled_courses = array_filter($enrolled_courses, function($course) use ($selected_course_id) {
                    return $course->id == $selected_course_id;
                });
            }
            
            if (empty($filtered_enrolled_courses)) {
                continue;
            }
            
            foreach ($filtered_enrolled_courses as $course) {
                try {
                // Get course sections - FETCH ALL SECTIONS (including hidden ones for parent visibility)
                $sql_sections = "SELECT cs.id, cs.name, cs.section, cs.summary, cs.visible, cs.sequence
                                 FROM {course_sections} cs
                                 WHERE cs.course = :courseid
                                 ORDER BY cs.section ASC";
                
                $sections = $DB->get_records_sql($sql_sections, ['courseid' => $course->id]);
                    
                    if (empty($sections)) {
                        continue;
                    }
                
                $course_data = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'sections' => []
                ];
                
                foreach ($sections as $section) {
                    // Get ALL activities in this section (including hidden - parents should see everything)
                    $sql_activities = "SELECT cm.id, cm.instance, cm.added, cm.completion, cm.visible,
                                             m.name as modname
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                      WHERE cm.course = :courseid
                                      AND cm.section = :sectionnum
                                      AND cm.deletioninprogress = 0
                                      ORDER BY cm.id ASC";
                    
                    $activities = $DB->get_records_sql($sql_activities, [
                        'courseid' => $course->id,
                        'sectionnum' => $section->section
                    ]);
                    
                    // Get details for each activity
                    $activity_details = [];
                    foreach ($activities as $activity) {
                        $detail = null;
                        
                        try {
                            // Get activity-specific details based on type
                            $table_map = [
                                'lesson' => 'lesson',
                                'assign' => 'assign',
                                'quiz' => 'quiz',
                                'resource' => 'resource',
                                'page' => 'page',
                                'forum' => 'forum',
                                'url' => 'url',
                                'book' => 'book',
                                'folder' => 'folder',
                                'label' => 'label'
                            ];
                            
                            if (isset($table_map[$activity->modname])) {
                                $detail = $DB->get_record($table_map[$activity->modname], ['id' => $activity->instance]);
                            }
                            
                            if ($detail) {
                                // Check completion status for this child
                                $completion_status = null;
                                $completion_record = $DB->get_record('course_modules_completion', [
                                    'coursemoduleid' => $activity->id,
                                    'userid' => $child_id
                                ]);
                                
                                if ($completion_record) {
                                    $completion_status = $completion_record->completionstate;
                                }
                                
                                $activity_data = [
                                    'id' => $activity->id,
                                    'name' => $detail->name ?? $detail->title ?? 'Unnamed Activity',
                                    'type' => $activity->modname,
                                    'added' => $activity->added,
                                    'completion' => $activity->completion,
                                    'completion_status' => $completion_status,
                                    'available' => $detail->available ?? null,
                                    'deadline' => $detail->deadline ?? $detail->duedate ?? $detail->timeclose ?? null,
                                    'course' => $course->fullname,
                                    'course_id' => $course->id,
                                    'section' => (!empty($section->name)) ? $section->name : 'Topic ' . $section->section,
                                    'section_summary' => $section->summary,
                                    'visible' => $activity->visible ?? 1,
                                    'section_visible' => $section->visible ?? 1
                                ];
                                
                                $activity_details[] = $activity_data;
                                $all_activities[] = $activity_data;
                                
                                // Update statistics
                                $lessons_stats['total']++;
                                if (isset($lessons_stats[$activity->modname])) {
                                    $lessons_stats[$activity->modname]++;
                                } else {
                                    $lessons_stats['other']++;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Could not fetch {$activity->modname} details: " . $e->getMessage());
                        }
                    }
                    
                    // Add section even if empty
                    if (!empty($activity_details) || $section->section == 0) {
                        $course_data['sections'][] = [
                            'id' => $section->id,
                            'name' => (!empty($section->name)) ? $section->name : 'Topic ' . $section->section,
                            'summary' => $section->summary,
                            'activities' => $activity_details
                        ];
                    }
                }
                
                // Add course if it has sections
                if (!empty($course_data['sections'])) {
                    $courses_with_sections[] = $course_data;
                    }
                } catch (Exception $e) {
                    debugging("Error processing course {$course->id} for child {$child_id}: " . $e->getMessage());
                    error_log("Learning Progress - Course structure error: " . $e->getMessage());
                    continue;
                }
            }
        } catch (Exception $e) {
            debugging("Error fetching courses for child {$child_id}: " . $e->getMessage());
            error_log("Learning Progress - Child courses error: " . $e->getMessage());
            continue;
        }
    }
}

// ==================== ADDITIONAL DATA FETCHING ====================
// Assignment Submissions with Grades
$assignment_submissions = [];
$assignment_stats = [
    'total' => 0,
    'submitted' => 0,
    'graded' => 0,
    'pending' => 0,
    'overdue' => 0,
    'average_grade' => 0
];

// Quiz Attempts with Scores
$quiz_attempts = [];
$quiz_stats = [
    'total_quizzes' => 0,
    'attempts' => 0,
    'completed' => 0,
    'average_score' => 0,
    'best_score' => 0
];

// Course Grades
$course_grades = [];
$gradebook_data = [];

// Time Spent in Courses
$time_spent_data = [];

// Upcoming Deadlines
$upcoming_deadlines = [];

// Recent Activity Details
$recent_activity_details = [];

if (!empty($target_children)) {
    try {
        list($insql_children, $params_children) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED, 'child');
        
        // Apply course filter
        $course_filter_sql = '';
        if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
            $course_filter_sql = ' AND a.course = :filter_courseid';
            $params_children['filter_courseid'] = $selected_course_id;
        }
        
        // 1. FETCH ASSIGNMENT SUBMISSIONS WITH GRADES
        try {
            $sql_assignments = "SELECT asub.id, asub.userid, asub.assignment, asub.timecreated, asub.timemodified, 
                                       asub.status, asub.attemptnumber,
                                       a.id as assignid, a.name as assignname, a.duedate, a.grade as maxgrade, 
                                       a.course as courseid, a.allowsubmissionsfromdate, a.cutoffdate,
                                       c.fullname as coursename, c.shortname as courseshortname,
                                       ag.grade as rawgrade, ag.timemodified as gradedtime,
                                       u.firstname, u.lastname
                                FROM {assign_submission} asub
                                JOIN {assign} a ON a.id = asub.assignment
                                JOIN {course} c ON c.id = a.course
                                JOIN {user} u ON u.id = asub.userid
                                LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = asub.userid AND ag.attemptnumber = asub.attemptnumber
                                WHERE asub.userid $insql_children
                                $course_filter_sql
                                ORDER BY asub.timemodified DESC";
            
            $assignments_raw = $DB->get_records_sql($sql_assignments, $params_children);
            
            foreach ($assignments_raw as $assign) {
                $grade_percentage = null;
                if ($assign->rawgrade !== null && $assign->maxgrade > 0) {
                    $grade_percentage = round(($assign->rawgrade / $assign->maxgrade) * 100, 1);
                }
                
                $is_overdue = false;
                if ($assign->duedate > 0 && $assign->status !== 'submitted' && $assign->duedate < time()) {
                    $is_overdue = true;
                }
                
                $assignment_submissions[] = [
                    'id' => $assign->id,
                    'userid' => $assign->userid,
                    'student_name' => fullname($assign),
                    'assignment_id' => $assign->assignid,
                    'assignment_name' => format_string($assign->assignname),
                    'course_id' => $assign->courseid,
                    'course_name' => format_string($assign->coursename),
                    'status' => $assign->status,
                    'submitted_time' => $assign->timemodified,
                    'submitted_date' => userdate($assign->timemodified, get_string('strftimedatefullshort', 'langconfig')),
                    'due_date' => $assign->duedate > 0 ? userdate($assign->duedate, get_string('strftimedatefullshort', 'langconfig')) : null,
                    'due_timestamp' => $assign->duedate,
                    'is_overdue' => $is_overdue,
                    'grade' => $assign->rawgrade,
                    'max_grade' => $assign->maxgrade,
                    'grade_percentage' => $grade_percentage,
                    'is_graded' => $assign->rawgrade !== null,
                    'graded_time' => $assign->gradedtime
                ];
                
                // Update stats
                $assignment_stats['total']++;
                if ($assign->status === 'submitted') {
                    $assignment_stats['submitted']++;
                    if ($assign->rawgrade !== null) {
                        $assignment_stats['graded']++;
                    } else {
                        $assignment_stats['pending']++;
                    }
                }
                if ($is_overdue) {
                    $assignment_stats['overdue']++;
                }
            }
            
            // Calculate average grade
            $graded_count = 0;
            $total_grade = 0;
            foreach ($assignment_submissions as $sub) {
                if ($sub['is_graded'] && $sub['grade_percentage'] !== null) {
                    $total_grade += $sub['grade_percentage'];
                    $graded_count++;
                }
            }
            if ($graded_count > 0) {
                $assignment_stats['average_grade'] = round($total_grade / $graded_count, 1);
            }
        } catch (Exception $e) {
            debugging('Error fetching assignment submissions: ' . $e->getMessage());
        }
        
        // 2. FETCH QUIZ ATTEMPTS WITH SCORES
        try {
            $sql_quizzes = "SELECT qa.id, qa.quiz, qa.userid, qa.attempt, qa.sumgrades, qa.timestart, 
                                   qa.timefinish, qa.state, qa.timecheckstate,
                                   q.id as quizid, q.name as quizname, q.grade as maxgrade, q.course as courseid,
                                   q.timeopen, q.timeclose,
                                   c.fullname as coursename, c.shortname as courseshortname,
                                   u.firstname, u.lastname
                            FROM {quiz_attempts} qa
                            JOIN {quiz} q ON q.id = qa.quiz
                            JOIN {course} c ON c.id = q.course
                            JOIN {user} u ON u.id = qa.userid
                            WHERE qa.userid $insql_children
                            $course_filter_sql
                            ORDER BY qa.timefinish DESC, qa.timestart DESC";
            
            $quizzes_raw = $DB->get_records_sql($sql_quizzes, $params_children);
            
            $quiz_scores = [];
            foreach ($quizzes_raw as $quiz) {
                $score_percentage = null;
                if ($quiz->sumgrades !== null && $quiz->maxgrade > 0) {
                    $score_percentage = round(($quiz->sumgrades / $quiz->maxgrade) * 100, 1);
                }
                
                $quiz_attempts[] = [
                    'id' => $quiz->id,
                    'userid' => $quiz->userid,
                    'student_name' => fullname($quiz),
                    'quiz_id' => $quiz->quizid,
                    'quiz_name' => format_string($quiz->quizname),
                    'course_id' => $quiz->courseid,
                    'course_name' => format_string($quiz->coursename),
                    'attempt_number' => $quiz->attempt,
                    'state' => $quiz->state,
                    'is_finished' => $quiz->state === 'finished',
                    'start_time' => $quiz->timestart,
                    'finish_time' => $quiz->timefinish,
                    'start_date' => userdate($quiz->timestart, get_string('strftimedatefullshort', 'langconfig')),
                    'finish_date' => $quiz->timefinish > 0 ? userdate($quiz->timefinish, get_string('strftimedatefullshort', 'langconfig')) : null,
                    'score' => $quiz->sumgrades,
                    'max_score' => $quiz->maxgrade,
                    'score_percentage' => $score_percentage,
                    'time_open' => $quiz->timeopen,
                    'time_close' => $quiz->timeclose
                ];
                
                // Update stats
                $quiz_stats['attempts']++;
                if ($quiz->state === 'finished') {
                    $quiz_stats['completed']++;
                    if ($score_percentage !== null) {
                        $quiz_scores[] = $score_percentage;
                    }
                }
            }
            
            // Calculate quiz statistics
            if (!empty($quiz_scores)) {
                $quiz_stats['average_score'] = round(array_sum($quiz_scores) / count($quiz_scores), 1);
                $quiz_stats['best_score'] = max($quiz_scores);
            }
            
            // Count unique quizzes
            $unique_quizzes = array_unique(array_column($quiz_attempts, 'quiz_id'));
            $quiz_stats['total_quizzes'] = count($unique_quizzes);
        } catch (Exception $e) {
            debugging('Error fetching quiz attempts: ' . $e->getMessage());
        }
        
        // 3. FETCH COURSE GRADES
        try {
            foreach ($target_children as $child_id) {
                $child_courses_list = enrol_get_users_courses($child_id, true);
                
                // Apply course filter
                if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses_list[$selected_course_id])) {
                    $child_courses_list = [$selected_course_id => $child_courses_list[$selected_course_id]];
                }
                
                foreach ($child_courses_list as $course) {
                    try {
                        // Get final course grade
                        $grade_item = $DB->get_record('grade_items', [
                            'courseid' => $course->id,
                            'itemtype' => 'course'
                        ]);
                        
                        if ($grade_item) {
                            $final_grade = $DB->get_record('grade_grades', [
                                'itemid' => $grade_item->id,
                                'userid' => $child_id
                            ]);
                            
                            if ($final_grade && $final_grade->finalgrade !== null) {
                                $course_grades[] = [
                                    'userid' => $child_id,
                                    'course_id' => $course->id,
                                    'course_name' => format_string($course->fullname),
                                    'final_grade' => $final_grade->finalgrade,
                                    'max_grade' => $grade_item->grademax,
                                    'grade_percentage' => $grade_item->grademax > 0 ? round(($final_grade->finalgrade / $grade_item->grademax) * 100, 1) : null,
                                    'timemodified' => $final_grade->timemodified
                                ];
                            }
                        }
                        
                        // Get gradebook items (assignments, quizzes, etc.)
                        $grade_items = $DB->get_records_sql(
                            "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax, gi.grademin,
                                    gg.finalgrade, gg.timemodified, gg.feedback,
                                    cm.id as cmid
                             FROM {grade_items} gi
                             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                             LEFT JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.module = (SELECT id FROM {modules} WHERE name = gi.itemmodule)
                             WHERE gi.courseid = :courseid
                             AND gi.itemtype IN ('mod', 'course')
                             AND gi.hidden = 0
                             ORDER BY gi.sortorder ASC",
                            ['userid' => $child_id, 'courseid' => $course->id]
                        );
                        
                        if (!empty($grade_items)) {
                            $gradebook_data[] = [
                                'userid' => $child_id,
                                'course_id' => $course->id,
                                'course_name' => format_string($course->fullname),
                                'items' => array_map(function($item) {
                                    return [
                                        'id' => $item->id,
                                        'name' => $item->itemname,
                                        'type' => $item->itemtype,
                                        'module' => $item->itemmodule,
                                        'grade' => $item->finalgrade,
                                        'max_grade' => $item->grademax,
                                        'min_grade' => $item->grademin,
                                        'percentage' => $item->grademax > 0 && $item->finalgrade !== null ? round(($item->finalgrade / $item->grademax) * 100, 1) : null,
                                        'feedback' => $item->feedback,
                                        'timemodified' => $item->timemodified,
                                        'cmid' => $item->cmid
                                    ];
                                }, array_values($grade_items))
                            ];
                        }
                    } catch (Exception $e) {
                        debugging("Error fetching grades for course {$course->id}: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            debugging('Error fetching course grades: ' . $e->getMessage());
        }
        
        // 4. FETCH TIME SPENT IN COURSES
        try {
            $sql_time = "SELECT ula.courseid, ula.userid, ula.timeaccess,
                                c.fullname as coursename,
                                (SELECT SUM(timecreated) - MIN(timecreated) 
                                 FROM {logstore_standard_log} 
                                 WHERE userid = ula.userid AND courseid = ula.courseid 
                                 AND timecreated >= :last30days) as estimated_time
                         FROM {user_lastaccess} ula
                         JOIN {course} c ON c.id = ula.courseid
                         WHERE ula.userid $insql_children
                         $course_filter_sql
                         ORDER BY ula.timeaccess DESC";
            
            $params_time = array_merge($params_children, ['last30days' => time() - (30 * 24 * 60 * 60)]);
            $time_records = $DB->get_records_sql($sql_time, $params_time);
            
            foreach ($time_records as $time) {
                // Calculate time spent from logs
                $log_time = $DB->get_records_sql(
                    "SELECT MIN(timecreated) as first_access, MAX(timecreated) as last_access,
                            COUNT(*) as access_count
                     FROM {logstore_standard_log}
                     WHERE userid = :userid AND courseid = :courseid
                     AND timecreated >= :last30days",
                    ['userid' => $time->userid, 'courseid' => $time->courseid, 'last30days' => time() - (30 * 24 * 60 * 60)]
                );
                
                $time_spent_hours = 0;
                if (!empty($log_time)) {
                    $log = reset($log_time);
                    if ($log->first_access && $log->last_access) {
                        // Estimate: assume average 5 minutes per log entry
                        $time_spent_hours = round(($log->access_count * 5) / 60, 1);
                    }
                }
                
                $time_spent_data[] = [
                    'userid' => $time->userid,
                    'course_id' => $time->courseid,
                    'course_name' => format_string($time->coursename),
                    'last_access' => $time->timeaccess,
                    'last_access_formatted' => userdate($time->timeaccess, get_string('strftimedatefullshort', 'langconfig')),
                    'time_spent_hours' => $time_spent_hours,
                    'access_count' => $log->access_count ?? 0
                ];
            }
        } catch (Exception $e) {
            debugging('Error fetching time spent data: ' . $e->getMessage());
        }
        
        // 5. FETCH UPCOMING DEADLINES
        try {
            $now = time();
            $future_limit = $now + (90 * 24 * 60 * 60); // Next 90 days
            
            // Assignment deadlines
            $sql_deadlines_assign = "SELECT a.id, a.name, a.duedate, a.course, a.allowsubmissionsfromdate,
                                           c.fullname as coursename,
                                           u.id as userid, u.firstname, u.lastname
                                    FROM {assign} a
                                    JOIN {course} c ON c.id = a.course
                                    JOIN {enrol} e ON e.courseid = c.id
                                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                    JOIN {user} u ON u.id = ue.userid
                                    WHERE u.id $insql_children
                                    AND a.duedate > :now
                                    AND a.duedate <= :future
                                    $course_filter_sql
                                    ORDER BY a.duedate ASC";
            
            $params_deadlines = array_merge($params_children, ['now' => $now, 'future' => $future_limit]);
            $deadlines_assign = $DB->get_records_sql($sql_deadlines_assign, $params_deadlines);
            
            foreach ($deadlines_assign as $deadline) {
                // Check if already submitted
                $submission = $DB->get_record('assign_submission', [
                    'assignment' => $deadline->id,
                    'userid' => $deadline->userid,
                    'status' => 'submitted'
                ]);
                
                $upcoming_deadlines[] = [
                    'id' => $deadline->id,
                    'userid' => $deadline->userid,
                    'student_name' => fullname($deadline),
                    'type' => 'assignment',
                    'name' => format_string($deadline->name),
                    'course_id' => $deadline->course,
                    'course_name' => format_string($deadline->coursename),
                    'deadline' => $deadline->duedate,
                    'deadline_formatted' => userdate($deadline->duedate, get_string('strftimedatefullshort', 'langconfig')),
                    'is_submitted' => !empty($submission),
                    'days_until' => ceil(($deadline->duedate - $now) / (24 * 60 * 60))
                ];
            }
            
            // Quiz close dates
            $sql_deadlines_quiz = "SELECT q.id, q.name, q.timeclose, q.course, q.timeopen,
                                         c.fullname as coursename,
                                         u.id as userid, u.firstname, u.lastname
                                  FROM {quiz} q
                                  JOIN {course} c ON c.id = q.course
                                  JOIN {enrol} e ON e.courseid = c.id
                                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                  JOIN {user} u ON u.id = ue.userid
                                  WHERE u.id $insql_children
                                  AND q.timeclose > :now
                                  AND q.timeclose <= :future
                                  $course_filter_sql
                                  ORDER BY q.timeclose ASC";
            
            $deadlines_quiz = $DB->get_records_sql($sql_deadlines_quiz, $params_deadlines);
            
            foreach ($deadlines_quiz as $deadline) {
                // Check if already completed
                $attempt = $DB->get_record('quiz_attempts', [
                    'quiz' => $deadline->id,
                    'userid' => $deadline->userid,
                    'state' => 'finished'
                ]);
                
                $upcoming_deadlines[] = [
                    'id' => $deadline->id,
                    'userid' => $deadline->userid,
                    'student_name' => fullname($deadline),
                    'type' => 'quiz',
                    'name' => format_string($deadline->name),
                    'course_id' => $deadline->course,
                    'course_name' => format_string($deadline->coursename),
                    'deadline' => $deadline->timeclose,
                    'deadline_formatted' => userdate($deadline->timeclose, get_string('strftimedatefullshort', 'langconfig')),
                    'is_completed' => !empty($attempt),
                    'days_until' => ceil(($deadline->timeclose - $now) / (24 * 60 * 60))
                ];
            }
            
            // Sort by deadline
            usort($upcoming_deadlines, function($a, $b) {
                return ($a['deadline'] ?? 0) <=> ($b['deadline'] ?? 0);
            });
            
            // Limit to next 20 deadlines
            $upcoming_deadlines = array_slice($upcoming_deadlines, 0, 20);
        } catch (Exception $e) {
            debugging('Error fetching upcoming deadlines: ' . $e->getMessage());
        }
        
        // 6. FETCH RECENT ACTIVITY DETAILS (Enhanced)
        try {
            $sql_recent = "SELECT l.id, l.userid, l.timecreated, l.action, l.target, l.courseid, l.component,
                                 l.eventname, l.objectid, l.objecttable,
                                 c.fullname as coursename,
                                 u.firstname, u.lastname
                          FROM {logstore_standard_log} l
                          JOIN {course} c ON c.id = l.courseid
                          JOIN {user} u ON u.id = l.userid
                          WHERE l.userid $insql_children
                          AND l.courseid > 1
                          AND l.timecreated >= :last7days
                          $course_filter_sql
                          ORDER BY l.timecreated DESC
                          LIMIT 100";
            
            $params_recent = array_merge($params_children, ['last7days' => time() - (7 * 24 * 60 * 60)]);
            $recent_logs = $DB->get_records_sql($sql_recent, $params_recent);
            
            foreach ($recent_logs as $log) {
                // Get activity name if available
                $activity_name = '';
                if (!empty($log->objectid) && !empty($log->objecttable)) {
                    try {
                        $activity_name = $DB->get_field($log->objecttable, 'name', ['id' => $log->objectid]);
                    } catch (Exception $e) {
                        // Table might not exist or field might not exist
                    }
                }
                
                $recent_activity_details[] = [
                    'id' => $log->id,
                    'userid' => $log->userid,
                    'student_name' => fullname($log),
                    'course_id' => $log->courseid,
                    'course_name' => format_string($log->coursename),
                    'action' => $log->action,
                    'target' => $log->target,
                    'component' => $log->component,
                    'eventname' => $log->eventname,
                    'activity_name' => $activity_name,
                    'time' => $log->timecreated,
                    'time_formatted' => userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                ];
            }
        } catch (Exception $e) {
            debugging('Error fetching recent activity details: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        debugging('Error in additional data fetching: ' . $e->getMessage());
    }
}

// Prepare lessons data for display (filter for lessons, pages, books)
$lessons_data = [];
$lessons_display_stats = [
    'total' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0
];

foreach ($all_activities as $activity) {
    if (in_array($activity['type'], ['lesson', 'page', 'book'])) {
        $lessons_display_stats['total']++;
        $status = 'not_started';
        
        if ($activity['completion_status'] == 1) {
            $status = 'completed';
            $lessons_display_stats['completed']++;
        } elseif ($activity['completion_status'] == 2) {
            $status = 'in_progress';
            $lessons_display_stats['in_progress']++;
        } else {
            $lessons_display_stats['not_started']++;
        }
        
        $lessons_data[] = [
            'id' => $activity['id'],
            'name' => $activity['name'],
            'course' => $activity['course'],
            'course_id' => $activity['course_id'],
            'type' => $activity['type'],
            'status' => $status,
            'completion_enabled' => $activity['completion'] > 0,
            'section' => $activity['section'],
            'deadline' => $activity['deadline'],
            'available' => $activity['available']
        ];
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Force full width */
#page, #page-wrapper, #region-main, #region-main-box, .main-inner, [role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

.parent-main-content {
    margin-left: 280px;
    margin-top: 0;
    padding: 0;
    min-height: 100vh;
    background: #f8fafc;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    position: relative;
}

.learning-progress-main-container {
    background: transparent;
    margin: 0;
    padding: 0;
    min-height: 100vh;
}

/* Professional Header */
.learning-progress-header {
    background: #ffffff;
    padding: 32px 40px;
    border-bottom: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 0;
}

.learning-progress-header h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 14px;
    letter-spacing: -0.5px;
    color: #0f172a;
}

.learning-progress-header p {
    margin: 0;
    color: #64748b;
    font-size: 15px;
    font-weight: 400;
    line-height: 1.6;
}

/* Professional Stats Dashboard */
.stats-dashboard-progress {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
    padding: 0;
}

.stat-card-progress {
    background: #ffffff;
    border-radius: 20px;
    padding: 36px 32px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 4px rgba(0, 0, 0, 0.04);
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    min-height: 160px;
    cursor: pointer;
    transform: translateY(0);
    animation: cardFadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1) backwards;
    border: 1px solid rgba(226, 232, 240, 0.8);
}

@keyframes cardFadeIn {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.stat-card-progress::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -40%;
    width: 240px;
    height: 240px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 65%);
    opacity: 0.4;
    transition: all 0.4s ease;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}

.stat-card-progress > * {
    position: relative;
    z-index: 1;
}

.stat-card-progress:hover {
    transform: translateY(-6px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1), 0 4px 12px rgba(0, 0, 0, 0.06);
    border-color: rgba(226, 232, 240, 1);
}

.stat-card-progress.blue:hover {
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.2), 0 6px 16px rgba(59, 130, 246, 0.15);
}

.stat-card-progress.green:hover {
    box-shadow: 0 12px 32px rgba(16, 185, 129, 0.2), 0 6px 16px rgba(16, 185, 129, 0.15);
}

.stat-card-progress.orange:hover {
    box-shadow: 0 12px 32px rgba(245, 158, 11, 0.2), 0 6px 16px rgba(245, 158, 11, 0.15);
}

.stat-card-progress.purple:hover {
    box-shadow: 0 12px 32px rgba(139, 92, 246, 0.2), 0 6px 16px rgba(139, 92, 246, 0.15);
}

.stat-card-progress.pink:hover {
    box-shadow: 0 12px 32px rgba(236, 72, 153, 0.2), 0 6px 16px rgba(236, 72, 153, 0.15);
}

.stat-card-progress.teal:hover {
    box-shadow: 0 12px 32px rgba(20, 184, 166, 0.2), 0 6px 16px rgba(20, 184, 166, 0.15);
}

.stat-card-progress.blue {
    background: radial-gradient(circle at top right, #dbeafe 0%, #bfdbfe 50%, #93c5fd 100%);
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.15), 0 4px 16px rgba(59, 130, 246, 0.1);
}

.stat-card-progress.green {
    background: radial-gradient(circle at top right, #d1fae5 0%, #a7f3d0 50%, #6ee7b7 100%);
    box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15), 0 4px 16px rgba(16, 185, 129, 0.1);
}

.stat-card-progress.orange {
    background: radial-gradient(circle at top right, #fef3c7 0%, #fde68a 50%, #fcd34d 100%);
    box-shadow: 0 8px 32px rgba(245, 158, 11, 0.15), 0 4px 16px rgba(245, 158, 11, 0.1);
}

.stat-card-progress.purple {
    background: radial-gradient(circle at top right, #e9d5ff 0%, #ddd6fe 50%, #c4b5fd 100%);
    box-shadow: 0 8px 32px rgba(139, 92, 246, 0.15), 0 4px 16px rgba(139, 92, 246, 0.1);
}

.stat-card-progress.pink {
    background: radial-gradient(circle at top right, #fce7f3 0%, #fbcfe8 50%, #f9a8d4 100%);
    box-shadow: 0 8px 32px rgba(236, 72, 153, 0.15), 0 4px 16px rgba(236, 72, 153, 0.1);
}

.stat-card-progress.teal {
    background: radial-gradient(circle at top right, #ccfbf1 0%, #99f6e4 50%, #5eead4 100%);
    box-shadow: 0 8px 32px rgba(20, 184, 166, 0.15), 0 4px 16px rgba(20, 184, 166, 0.1);
}

.stat-icon-progress {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #ffffff;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.stat-value-progress {
    font-size: 38px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    line-height: 1.1;
    letter-spacing: -1px;
}

.stat-label-progress {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-top: 12px;
}

/* Professional Tabs */
.tabs-container-progress {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-top: 0;
}

.tabs-header-progress {
    display: flex;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 0;
    gap: 0;
}

.tab-button-progress {
    flex: 1;
    padding: 12px 20px;
    border: none;
    background: transparent;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-bottom: 3px solid transparent;
    position: relative;
}

.tab-button-progress:hover {
    background: #f1f5f9;
    color: #334155;
}

.tab-button-progress.active {
    color: #3b82f6;
    background: #ffffff;
    border-bottom-color: #3b82f6;
    font-weight: 700;
}

.tab-content-progress {
    display: none;
    padding: 20px;
    min-height: 400px;
}

.tab-content-progress.active {
    display: block;
}

/* Professional Progress Cards */
.course-card-progress {
    background: #ffffff;
    border-radius: 8px;
    padding: 18px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border-left: 3px solid #3b82f6;
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s ease;
}

.course-card-progress:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-left-color: #2563eb;
    border-color: #cbd5e1;
}

.progress-bar-container-progress {
    background: #f1f5f9;
    border-radius: 4px;
    height: 6px;
    overflow: hidden;
    margin: 12px 0;
}

.progress-bar-fill-progress {
    height: 100%;
    border-radius: 4px;
    background: #3b82f6;
    transition: width 0.6s ease;
    position: relative;
}

/* Professional Activity Cards */
.activity-card {
    background: #ffffff;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 10px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border-left: 3px solid;
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s ease;
}

.activity-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

/* Lesson Cards */
.lesson-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-left: 4px solid #10b981;
    transition: all 0.3s;
}

.lesson-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}

.status-badge-progress {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.badge-complete-progress {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-progress-progress {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.badge-not-started-progress {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.empty-state-progress {
    text-align: center;
    padding: 50px 30px;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 2px dashed #e2e8f0;
}

.empty-icon-progress {
    font-size: 48px;
    color: #cbd5e1;
    margin-bottom: 16px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    display: inline-block;
}

.empty-title-progress {
    font-size: 18px;
    font-weight: 700;
    color: #334155;
    margin: 0 0 8px 0;
}

.empty-text-progress {
    font-size: 13px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

@media (max-width: 1200px) {
    .stats-dashboard-progress {
        grid-template-columns: repeat(2, 1fr);
        gap: 18px;
    }
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 16px;
    }
    
    .learning-progress-header {
        padding: 24px 20px;
    }
    
    .learning-progress-header h1 {
        font-size: 24px;
    }
    
    .stats-dashboard-progress {
        grid-template-columns: 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .stat-card-progress {
        padding: 28px 24px;
        min-height: 140px;
    }
    
    .stat-value-progress {
        font-size: 32px;
    }
    
    div[style*="max-width: 1600px"] {
        padding: 24px 20px !important;
    }
    
    div[style*="padding: 24px 32px"] {
        padding: 20px 24px !important;
    }
}
</style>

<div class="parent-main-content">
    <div class="learning-progress-main-container">
        <!-- Professional Header -->
        <div class="learning-progress-header">
            <div style="max-width: 1600px; margin: 0 auto;">
                <h1>
                    <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);">
                        <i class="fas fa-chart-line" style="font-size: 20px; color: white;"></i>
                    </div>
                    Learning Progress Dashboard
                </h1>
                <p>Comprehensive academic tracking and performance analytics for your child's educational journey</p>
            </div>
        </div>
        
        <div style="max-width: 1600px; margin: 0 auto; padding: 40px 48px;">

    <?php if ($selected_child_id): ?>
    <!-- Course Filter Bar (Similar to parent_reports.php) -->
    <div style="background: #ffffff; padding: 24px 32px; border-radius: 16px; margin: 0 0 32px 0; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
            <div style="display: flex; align-items: center; gap: 16px; flex: 1; min-width: 280px;">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.3); position: relative; overflow: hidden;">
                    <div style="position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), transparent);"></div>
                    <i class="fas fa-filter" style="color: white; font-size: 18px; position: relative; z-index: 1; filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));"></i>
                </div>
                <div style="flex: 1; min-width: 240px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;">Filter by Course</label>
                    <select id="courseFilter" onchange="filterByCourse(this.value)" style="width: 100%; padding: 12px 16px; border: 2px solid rgba(226, 232, 240, 0.6); border-radius: 12px; font-size: 14px; font-weight: 700; color: #0f172a; background: rgba(255, 255, 255, 0.9); cursor: pointer; transition: all 0.3s ease; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'14\' height=\'14\' viewBox=\'0 0 14 14\'><path fill=\'%2364748b\' d=\'M7 10L2 5h10z\'/></svg>'); background-repeat: no-repeat; background-position: right 16px center; padding-right: 44px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);" onmouseover="this.style.borderColor='#667eea'; this.style.boxShadow='0 0 0 4px rgba(102, 126, 234, 0.15)'; this.style.transform='translateY(-1px)'" onmouseout="this.style.borderColor='rgba(226, 232, 240, 0.6)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.04)'; this.style.transform='translateY(0)'">
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
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <?php 
                $selected_course_name = 'All Courses';
                if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])) {
                    $selected_course_name = $child_courses[$selected_course_id]->fullname;
                }
                ?>
                <div style="display: flex; align-items: center; gap: 10px; font-size: 13px; color: #64748b; padding: 10px 18px; background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(10px); border-radius: 12px; border: 1px solid rgba(226, 232, 240, 0.5); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);">
                    <i class="fas fa-book-open" style="color: #667eea; font-size: 16px;"></i>
                    <span style="font-weight: 700; letter-spacing: 0.2px;"><?php echo htmlspecialchars($selected_course_name); ?></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: #64748b; padding: 10px 18px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1)); backdrop-filter: blur(10px); border-radius: 12px; border: 1px solid rgba(102, 126, 234, 0.2); box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);">
                    <i class="fas fa-graduation-cap" style="color: #667eea; font-size: 16px;"></i>
                    <span style="font-weight: 700; letter-spacing: 0.2px;"><?php echo count($child_courses); ?> course<?php echo count($child_courses) != 1 ? 's' : ''; ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Dashboard - Modern Gradient Cards -->
    <div class="stats-dashboard-progress">
        <div class="stat-card-progress blue" style="animation-delay: 0.1s">
            <div class="stat-value-progress"><?php echo $overall_stats['total_courses']; ?></div>
            <div class="stat-label-progress">Total Courses</div>
        </div>
        <div class="stat-card-progress green" style="animation-delay: 0.2s">
            <div class="stat-value-progress"><?php echo $overall_stats['completed']; ?></div>
            <div class="stat-label-progress">Completed</div>
        </div>
        <div class="stat-card-progress orange" style="animation-delay: 0.3s">
            <div class="stat-value-progress"><?php echo $overall_stats['in_progress']; ?></div>
            <div class="stat-label-progress">In Progress</div>
        </div>
        <div class="stat-card-progress purple" style="animation-delay: 0.4s">
            <div class="stat-value-progress"><?php echo $overall_stats['activities_completed'] > 0 ? $overall_stats['activities_completed'] : $activity_stats['total']; ?></div>
            <div class="stat-label-progress">Activities Completed</div>
        </div>
        <div class="stat-card-progress pink" style="animation-delay: 0.5s">
            <div class="stat-value-progress"><?php echo $overall_stats['lessons_completed'] > 0 ? $overall_stats['lessons_completed'] : $lessons_display_stats['total']; ?></div>
            <div class="stat-label-progress">Lessons Completed</div>
        </div>
        <div class="stat-card-progress teal" style="animation-delay: 0.6s">
            <div class="stat-value-progress"><?php echo $overall_stats['overall_progress'] > 0 ? $overall_stats['overall_progress'] : $overall_stats['completion_rate']; ?>%</div>
            <div class="stat-label-progress">Overall Progress</div>
        </div>
    </div>

    <!-- Tabs Container -->
    <div class="tabs-container-progress">
        <!-- Tabs Header -->
        <div class="tabs-header-progress">
            <button class="tab-button-progress active" onclick="switchProgressTab('progress', this)">
                <i class="fas fa-chart-bar"></i>
                <span>Progress Reports</span>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('activities', this)">
                <i class="fas fa-tasks"></i>
                <span>Activities</span>
                <?php if ($activity_stats['total'] > 0): ?>
                <span style="background: #ef4444; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo $activity_stats['total']; ?>
                </span>
                <?php endif; ?>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('lessons', this)">
                <i class="fas fa-book"></i>
                <span>Lessons</span>
                <?php if ($lessons_display_stats['total'] > 0): ?>
                <span style="background: #ef4444; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo $lessons_display_stats['total']; ?>
                </span>
                <?php endif; ?>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('assignments', this)">
                <i class="fas fa-file-alt"></i>
                <span>Assignments</span>
                <?php if ($assignment_stats['total'] > 0): ?>
                <span style="background: #3b82f6; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo $assignment_stats['total']; ?>
                </span>
                <?php endif; ?>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('quizzes', this)">
                <i class="fas fa-question-circle"></i>
                <span>Quizzes</span>
                <?php if ($quiz_stats['attempts'] > 0): ?>
                <span style="background: #8b5cf6; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo $quiz_stats['attempts']; ?>
                </span>
                <?php endif; ?>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('grades', this)">
                <i class="fas fa-star"></i>
                <span>Grades</span>
                <?php if (!empty($course_grades)): ?>
                <span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo count($course_grades); ?>
                </span>
                <?php endif; ?>
            </button>
            <button class="tab-button-progress" onclick="switchProgressTab('deadlines', this)">
                <i class="fas fa-calendar-alt"></i>
                <span>Deadlines</span>
                <?php if (!empty($upcoming_deadlines)): ?>
                <span style="background: #f59e0b; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-left: 8px;">
                    <?php echo count($upcoming_deadlines); ?>
                </span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Progress Reports Tab -->
        <div id="progress-tab" class="tab-content-progress active">
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing progress for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($progress_data)): ?>
                <?php foreach ($progress_data as $child_data): ?>
                <div style="margin-bottom: 32px;">
                    <h2 style="color: #1e293b; margin-bottom: 20px; font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                        <i class="fas fa-user-graduate" style="color: #3b82f6;"></i>
                        <?php echo htmlspecialchars($child_data['child_name']); ?>
                    </h2>
                    
                    <div style="background: #ffffff; padding: 24px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px;">
                            <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 6px;">
                                <div style="font-size: 28px; font-weight: 700; color: #1e293b; margin-bottom: 4px;"><?php echo $child_data['total_courses']; ?></div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Courses</div>
                            </div>
                            <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 6px;">
                                <div style="font-size: 28px; font-weight: 700; color: #10b981; margin-bottom: 4px;"><?php echo $child_data['completed']; ?></div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Completed</div>
                            </div>
                            <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 6px;">
                                <div style="font-size: 28px; font-weight: 700; color: #f59e0b; margin-bottom: 4px;"><?php echo $child_data['in_progress']; ?></div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">In Progress</div>
                            </div>
                            <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 6px;">
                                <div style="font-size: 28px; font-weight: 700; color: #3b82f6; margin-bottom: 4px;"><?php echo $child_data['completion_rate']; ?>%</div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Completion Rate</div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($child_data['courses'])): ?>
                        <?php foreach ($child_data['courses'] as $course): ?>
                        <div class="course-card-progress">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                                <div style="flex: 1;">
                                    <h3 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 700; color: #0f172a; line-height: 1.4;">
                                        <?php echo htmlspecialchars($course['name']); ?>
                                    </h3>
                                    <p style="margin: 0; color: #64748b; font-size: 13px; font-weight: 500;">
                                        <?php echo htmlspecialchars($course['shortname']); ?>
                                    </p>
                                </div>
                                <?php if ($course['is_complete']): ?>
                                <span class="status-badge-progress badge-complete-progress">
                                    <i class="fas fa-check-circle"></i> Completed
                                </span>
                                <?php elseif ($course['progress_percentage'] > 0): ?>
                                <span class="status-badge-progress badge-progress-progress">
                                    <i class="fas fa-spinner"></i> In Progress
                                </span>
                                <?php else: ?>
                                <span class="status-badge-progress badge-not-started-progress">
                                    <i class="fas fa-clock"></i> Not Started
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($course['completion_enabled']): ?>
                            <div class="progress-bar-container-progress">
                                <div class="progress-bar-fill-progress" style="width: <?php echo $course['progress_percentage']; ?>%;" data-percent="<?php echo $course['progress_percentage']; ?>%"></div>
                                </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 13px; color: #64748b;">
                                <span style="font-weight: 500;"><i class="fas fa-check-circle" style="color: #10b981; margin-right: 6px;"></i><?php echo $course['completed_activities']; ?> of <?php echo $course['total_activities']; ?> activities completed</span>
                                <span style="font-weight: 700; color: #3b82f6; font-size: 14px;"><?php echo $course['progress_percentage']; ?>%</span>
                            </div>
                            <?php else: ?>
                            <p style="margin: 0; color: #6b7280; font-size: 13px; font-style: italic;">
                                <i class="fas fa-info-circle"></i> Completion tracking not enabled for this course
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-chart-bar"></i></div>
                    <h3 class="empty-title-progress">No Progress Data</h3>
                    <p class="empty-text-progress">Progress information will appear here once your child starts courses.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Activities Tab -->
        <!-- EXACT SAME DISPLAY AS parent_activities.php -->
        <div id="activities-tab" class="tab-content-progress">
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing activities for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($children)): ?>
                <!-- Activity Statistics Cards -->
                <?php if ($activity_stats['total'] > 0): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 25px;">
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); text-align: center; border-top: 3px solid #3b82f6;">
                        <i class="fas fa-file-alt" style="font-size: 22px; color: #3b82f6; margin-bottom: 8px;"></i>
                        <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['assignments']; ?></div>
                        <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Assignments</div>
                    </div>
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); text-align: center; border-top: 3px solid #8b5cf6;">
                        <i class="fas fa-question-circle" style="font-size: 22px; color: #8b5cf6; margin-bottom: 8px;"></i>
                        <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['quizzes']; ?></div>
                        <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Quizzes</div>
                    </div>
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); text-align: center; border-top: 3px solid #10b981;">
                        <i class="fas fa-book" style="font-size: 22px; color: #10b981; margin-bottom: 8px;"></i>
                        <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['resources']; ?></div>
                        <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Resources</div>
                    </div>
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); text-align: center; border-top: 3px solid #f59e0b;">
                        <i class="fas fa-comments" style="font-size: 22px; color: #f59e0b; margin-bottom: 8px;"></i>
                        <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['forums']; ?></div>
                        <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Forums</div>
                    </div>
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); text-align: center; border-top: 3px solid #6b7280;">
                        <i class="fas fa-th" style="font-size: 22px; color: #6b7280; margin-bottom: 8px;"></i>
                        <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['other']; ?></div>
                        <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Other</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <?php if (!empty($activities_by_type)): ?>
                <div style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <div style="font-weight: 700; color: #4b5563; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-filter" style="color: #3b82f6;"></i>
                            Filter By:
                        </div>
                        
                        <!-- Course Filter -->
                        <select id="courseFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                            <option value="all">All Courses</option>
                            <?php
                            $courses = [];
                            foreach ($activities_by_type as $type => $items) {
                                foreach ($items as $activity) {
                                    $courses[$activity['course']] = true;
                                }
                            }
                            foreach (array_keys($courses) as $course):
                            ?>
                            <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- Type Filter -->
                        <select id="typeFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                            <option value="all">All Types</option>
                            <option value="Assignment">Assignments</option>
                            <option value="Quiz">Quizzes</option>
                            <option value="Resource">Resources</option>
                            <option value="Forum">Forums</option>
                            <option value="Other">Other</option>
                        </select>
                        
                        <!-- Action Filter -->
                        <select id="actionFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                            <option value="all">All Actions</option>
                            <option value="Viewed">Viewed</option>
                            <option value="Submitted">Submitted</option>
                            <option value="Started">Started</option>
                            <option value="Created">Created</option>
                            <option value="Updated">Updated</option>
                            <option value="Answered">Answered</option>
                        </select>
                        
                        <!-- Reset Button -->
                        <button onclick="resetFilters()" style="padding: 8px 16px; background: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; color: #374151;">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        
                        <!-- Results Count -->
                        <div id="resultsCount" style="margin-left: auto; font-size: 14px; color: #6b7280; font-weight: 600;"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- All Activities List (Filterable) -->
                <?php if (!empty($activities_by_type)): ?>
                <div style="margin-bottom: 40px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0; font-size: 24px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-list-check"></i>
                            Recent Activities
                            <span style="background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 13px; margin-left: 10px; font-weight: 700;">
                                <?php echo $activity_stats['total']; ?> total
                            </span>
                        </h2>
                    </div>
                    
                    <div id="activitiesContainer" style="display: grid; gap: 10px;">
                        <?php foreach ($activities_by_type as $type => $items): ?>
                            <?php foreach ($items as $activity): ?>
                            <div class="activity-card activity-item" 
                                 data-course="<?php echo htmlspecialchars($activity['course']); ?>"
                                 data-type="<?php echo $type; ?>"
                                 data-action="<?php echo $activity['action']; ?>"
                                 style="border-left: 3px solid <?php echo $activity['color']; ?>; padding: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">
                                    <!-- Left: Activity Info -->
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                                            <i class="fas <?php echo $activity['icon']; ?>" style="color: <?php echo $activity['color']; ?>; font-size: 16px;"></i>
                                            <span style="background: <?php echo $activity['color']; ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                                <?php echo $type; ?>
                                            </span>
                                            <span style="background: #f3f4f6; color: #374151; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                                <?php echo $activity['action']; ?>
                                            </span>
                                        </div>
                                        <div style="font-weight: 600; color: #4b5563; font-size: 15px; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($activity['course']); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            <i class="fas fa-user" style="font-size: 10px;"></i>
                                            <?php echo htmlspecialchars($activity['student']); ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Right: Time Info -->
                                    <div style="text-align: right; min-width: 100px;">
                                        <div style="font-size: 14px; font-weight: 700; color: #4b5563;">
                                            <?php echo date('g:i A', $activity['time']); ?>
                                        </div>
                                        <div style="font-size: 11px; color: #6b7280;">
                                            <?php echo date('M d', $activity['time']); ?>
                                        </div>
                                        <?php 
                                        $days_ago = floor((time() - $activity['time']) / 86400);
                                        if ($days_ago == 0) {
                                            $time_text = 'Today';
                                            $time_color = '#10b981';
                                        } elseif ($days_ago == 1) {
                                            $time_text = 'Yesterday';
                                            $time_color = '#3b82f6';
                                        } else {
                                            $time_text = $days_ago . 'd ago';
                                            $time_color = '#9ca3af';
                                        }
                                        ?>
                                        <div style="font-size: 10px; color: <?php echo $time_color; ?>; font-weight: 600; margin-top: 4px;">
                                            <?php echo $time_text; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="noResults" style="display: none; text-align: center; padding: 60px; color: #6b7280;">
                        <i class="fas fa-search" style="font-size: 48px; color: #d1d5db; margin-bottom: 15px;"></i>
                        <h3 style="margin-bottom: 10px;">No Activities Found</h3>
                        <p>Try adjusting your filters to see more results</p>
                    </div>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 60px;">
                    <i class="fas fa-tasks" style="font-size: 64px; color: #d1d5db; margin-bottom: 15px;"></i>
                    <h3 style="color: #6b7280; margin-bottom: 10px;">No Recent Activities</h3>
                    <p style="color: #9ca3af;">Activities from the last 30 days will appear here</p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-users"></i></div>
                    <h3 class="empty-title-progress">No Children Found</h3>
                    <p class="empty-text-progress">You don't have any children linked to your parent account yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Lessons Tab -->
        <!-- EXACT SAME DISPLAY AS parent_lessons.php - Shows ALL activities organized by course & section -->
        <div id="lessons-tab" class="tab-content-progress">
            <?php if ($selected_course_id !== 'all' && $selected_course_id > 0 && isset($child_courses[$selected_course_id])): ?>
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #0284c7; font-size: 16px;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #0c4a6e;">
                    Showing lessons for: <strong><?php echo htmlspecialchars($child_courses[$selected_course_id]->fullname ?? ''); ?></strong>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($children)): ?>
                <!-- Professional Statistics Cards -->
                <?php if ($lessons_stats['total'] > 0): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #3b82f6;">
                        <div style="font-size: 24px; color: #3b82f6; margin-bottom: 8px;"><i class="fas fa-book-reader"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['lesson']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Lessons</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #10b981;">
                        <div style="font-size: 24px; color: #10b981; margin-bottom: 8px;"><i class="fas fa-file-alt"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['assign']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Assignments</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #f59e0b;">
                        <div style="font-size: 24px; color: #f59e0b; margin-bottom: 8px;"><i class="fas fa-clipboard-check"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['quiz']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Quizzes</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #8b5cf6;">
                        <div style="font-size: 24px; color: #8b5cf6; margin-bottom: 8px;"><i class="fas fa-file"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['resource']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Resources</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #ec4899;">
                        <div style="font-size: 24px; color: #ec4899; margin-bottom: 8px;"><i class="fas fa-comments"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['forum']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Forums</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; border-top: 3px solid #14b8a6;">
                        <div style="font-size: 24px; color: #14b8a6; margin-bottom: 8px;"><i class="fas fa-th"></i></div>
                        <div style="font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo $lessons_stats['total']; ?></div>
                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px;">Total</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Enhanced Filters -->
                <?php if (!empty($all_activities)): ?>
                <div style="background: linear-gradient(135deg, #ffffff, #f8fafc); padding: 20px; border-radius: 16px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(59, 130, 246, 0.1); border: 2px solid #e0f2fe;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 3px solid #e0f2fe;">
                        <div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-sliders-h"></i>
                            FILTER ACTIVITIES
                        </div>
                        <div id="resultsCountLessons" style="font-size: 14px; color: #3b82f6; font-weight: 700; background: #eff6ff; padding: 8px 16px; border-radius: 20px;"></div>
                    </div>
                    
                    <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
                        <!-- Course Filter -->
                        <select id="courseFilterLessons" onchange="filterLessons()" style="padding: 10px 16px; border: 2px solid #e0f2fe; border-radius: 8px; font-size: 14px; font-weight: 600; min-width: 180px; cursor: pointer; background: white;">
                            <option value="all">All Courses</option>
                            <?php
                            $courses_list = array_unique(array_column($all_activities, 'course'));
                            sort($courses_list);
                            foreach ($courses_list as $course_name):
                            ?>
                            <option value="<?php echo htmlspecialchars($course_name); ?>"><?php echo htmlspecialchars($course_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- Type Filter -->
                        <select id="typeFilterLessons" onchange="filterLessons()" style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; font-weight: 500; min-width: 200px; cursor: pointer; background: #ffffff; color: #1e293b;">
                            <option value="all">All Types</option>
                            <option value="lesson">Lessons</option>
                            <option value="assign">Assignments</option>
                            <option value="quiz">Quizzes</option>
                            <option value="resource">Resources</option>
                            <option value="page">Pages</option>
                            <option value="forum">Forums</option>
                        </select>
                        
                        <!-- Completion Filter -->
                        <select id="completionFilterLessons" onchange="filterLessons()" style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; font-weight: 500; min-width: 200px; cursor: pointer; background: #ffffff; color: #1e293b;">
                            <option value="all">All Status</option>
                            <option value="1">Tracked Only</option>
                            <option value="0">Not Tracked</option>
                        </select>
                        
                        <!-- Reset Button -->
                        <button onclick="resetLessonFilters()" style="padding: 10px 18px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; background: #f8fafc; color: #475569; transition: all 0.2s;">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                    
                    <!-- Quick Filter Buttons -->
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                        <span style="font-size: 12px; color: #64748b; font-weight: 600; padding: 8px 0; text-transform: uppercase; letter-spacing: 0.5px;">Quick Filters:</span>
                        <button onclick="quickFilterLessons('lesson')" style="padding: 8px 16px; border: 1px solid #3b82f6; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #eff6ff; color: #3b82f6; transition: all 0.2s;">
                            <i class="fas fa-book-reader"></i> Lessons
                        </button>
                        <button onclick="quickFilterLessons('assign')" style="padding: 8px 16px; border: 1px solid #10b981; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #f0fdf4; color: #10b981; transition: all 0.2s;">
                            <i class="fas fa-file-alt"></i> Assignments
                        </button>
                        <button onclick="quickFilterLessons('quiz')" style="padding: 8px 16px; border: 1px solid #f59e0b; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #fffbeb; color: #f59e0b; transition: all 0.2s;">
                            <i class="fas fa-clipboard-check"></i> Quizzes
                        </button>
                        <button onclick="quickFilterLessons('tracked')" style="padding: 8px 16px; border: 1px solid #8b5cf6; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #faf5ff; color: #8b5cf6; transition: all 0.2s;">
                            <i class="fas fa-check-circle"></i> Tracked
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- All Activities List (Filterable) -->
                <?php if (!empty($all_activities)): ?>
                <div style="margin-bottom: 40px;">
                    <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 20px 28px; border-radius: 16px; margin-bottom: 24px; border-left: 6px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);">
                        <h2 style="font-size: 26px; font-weight: 800; color: #3b82f6; margin: 0; display: flex; align-items: center; gap: 14px;">
                            <i class="fas fa-list-check"></i>
                            All Learning Activities
                            <span style="background: white; color: #3b82f6; padding: 6px 16px; border-radius: 20px; font-size: 14px; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);">
                                <?php echo count($all_activities); ?> Total
                            </span>
                        </h2>
                    </div>
                    
                    <div id="lessonsContainer" style="display: grid; gap: 12px;">
                        <?php foreach ($all_activities as $activity): 
                            // Activity type configuration
                            $config = [
                                'lesson' => ['icon' => 'book-reader', 'color' => '#3b82f6'],
                                'assign' => ['icon' => 'file-alt', 'color' => '#10b981'],
                                'quiz' => ['icon' => 'clipboard-check', 'color' => '#f59e0b'],
                                'forum' => ['icon' => 'comments', 'color' => '#ec4899'],
                                'resource' => ['icon' => 'file', 'color' => '#8b5cf6'],
                                'page' => ['icon' => 'file-lines', 'color' => '#14b8a6'],
                                'url' => ['icon' => 'link', 'color' => '#f97316'],
                            ];
                            
                            $icon = $config[$activity['type']]['icon'] ?? 'puzzle-piece';
                            $color = $config[$activity['type']]['color'] ?? '#60a5fa';
                            
                            // Completion status
                            $completion_html = '';
                            if ($activity['completion_status'] !== null) {
                                if ($activity['completion_status'] == 1) {
                                    $completion_html = '<span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46;"><i class="fas fa-check-circle"></i> Complete</span>';
                                } else if ($activity['completion_status'] == 2) {
                                    $completion_html = '<span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e;"><i class="fas fa-spinner"></i> In Progress</span>';
                                } else {
                                    $completion_html = '<span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b;"><i class="fas fa-times-circle"></i> Not Started</span>';
                                }
                            } else if ($activity['completion']) {
                                $completion_html = '<span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; background: #e0f2fe; color: #0369a1;"><i class="fas fa-chart-line"></i> Tracked</span>';
                            }
                        ?>
                        <div class="activity-card lesson-item" 
                             data-course="<?php echo htmlspecialchars($activity['course']); ?>"
                             data-type="<?php echo $activity['type']; ?>"
                             data-completion="<?php echo $activity['completion']; ?>"
                             style="border-left-color: <?php echo $color; ?>; background: white; border-radius: 12px; padding: 16px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border-left: 4px solid;">
                            <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 16px; align-items: center;">
                                <!-- Icon -->
                                <div style="width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; background: <?php echo $color; ?>; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);">
                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                </div>
                                
                                <!-- Content -->
                                <div style="min-width: 0;">
                                    <div style="font-weight: 700; color: #4b5563; font-size: 15px; margin-bottom: 8px; line-height: 1.3;">
                                        <?php echo htmlspecialchars($activity['name']); ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px;">
                                        <span style="background: <?php echo $color; ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                            <?php echo strtoupper($activity['type']); ?>
                                        </span>
                                        <span style="color: #3b82f6; font-weight: 600;">
                                            <i class="fas fa-book" style="font-size: 10px;"></i> <?php echo htmlspecialchars($activity['course']); ?>
                                        </span>
                                        <?php if (isset($activity['visible']) && $activity['visible'] == 0): ?>
                                        <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                            <i class="fas fa-eye-slash"></i> Hidden
                                        </span>
                                        <?php endif; ?>
                                        <?php echo $completion_html; ?>
                                    </div>
                                </div>
                                
                                <!-- Due Date / Status -->
                                <div style="text-align: right; min-width: 120px;">
                                    <?php if ($activity['deadline']): ?>
                                        <?php 
                                        $days_until = floor(($activity['deadline'] - time()) / 86400);
                                        if ($days_until < 0) {
                                            $due_style = 'background: #fee2e2; color: #991b1b;';
                                            $due_text = 'Overdue';
                                            $due_icon = 'exclamation-circle';
                                        } elseif ($days_until == 0) {
                                            $due_style = 'background: #fef3c7; color: #92400e;';
                                            $due_text = 'Due Today';
                                            $due_icon = 'clock';
                                        } elseif ($days_until <= 3) {
                                            $due_style = 'background: #fffbeb; color: #b45309;';
                                            $due_text = $days_until . ' days';
                                            $due_icon = 'exclamation-triangle';
                                        } else {
                                            $due_style = 'background: #d1fae5; color: #065f46;';
                                            $due_text = $days_until . ' days';
                                            $due_icon = 'calendar-check';
                                        }
                                        ?>
                                        <div style="<?php echo $due_style; ?> padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; margin-bottom: 6px;">
                                            <i class="fas fa-<?php echo $due_icon; ?>"></i> <?php echo $due_text; ?>
                                        </div>
                                        <div style="font-size: 11px; color: #6b7280; font-weight: 600;">
                                            <?php echo date('M d, Y', $activity['deadline']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="background: #f3f4f6; color: #6b7280; padding: 8px 14px; border-radius: 8px; font-size: 11px; font-weight: 700;">
                                            <i class="fas fa-calendar-plus"></i> Added
                                        </div>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 6px; font-weight: 600;">
                                            <?php echo date('M d, Y', $activity['added']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="noResultsLessons" style="display: none; text-align: center; padding: 60px; background: white; border-radius: 20px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);">
                        <div style="font-size: 60px; color: #d1d5db; margin-bottom: 20px;"><i class="fas fa-search"></i></div>
                        <h3 style="font-size: 20px; font-weight: 700; color: #4b5563; margin: 0 0 10px 0;">No Activities Found</h3>
                        <p style="font-size: 14px; color: #6b7280; margin: 0;">Try adjusting your filters to see more results</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Course Sections View -->
                <?php if (!empty($courses_with_sections)): ?>
                <div>
                    <div style="background: #ffffff; padding: 20px 24px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                        <h2 style="font-size: 20px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 12px;">
                            <i class="fas fa-layer-group" style="color: #10b981;"></i>
                            Organized by Course & Section
                            <span style="background: #f8fafc; color: #475569; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; border: 1px solid #e2e8f0;">
                                <?php echo count($courses_with_sections); ?> Courses
                            </span>
                        </h2>
                    </div>
                    
                    <?php foreach ($courses_with_sections as $course): ?>
                    <div style="background: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 20px; border: 1px solid #e2e8f0;">
                        <!-- Professional Course Header -->
                        <div style="background: #1e293b; padding: 20px 24px; color: white;">
                            <div style="display: flex; align-items: center; gap: 14px;">
                                <div style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.15); border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <div style="flex: 1;">
                                    <h3 style="font-size: 16px; font-weight: 700; margin: 0; line-height: 1.4; color: #ffffff;">
                                        <?php echo htmlspecialchars($course['fullname']); ?>
                                    </h3>
                                    <p style="font-size: 13px; opacity: 0.85; margin: 6px 0 0 0; font-weight: 500; color: #cbd5e1;">
                                        <?php echo htmlspecialchars($course['shortname']); ?> • 
                                        <?php echo count($course['sections']); ?> section<?php echo count($course['sections']) != 1 ? 's' : ''; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sections -->
                        <div style="padding: 20px;">
                            <?php foreach ($course['sections'] as $section_index => $section): ?>
                            <div style="margin-bottom: <?php echo ($section_index < count($course['sections']) - 1) ? '30px' : '0'; ?>;">
                                <!-- Professional Section Header -->
                                <div style="background: #f8fafc; padding: 14px 18px; border-radius: 6px; margin-bottom: 12px; border-left: 3px solid #3b82f6; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                                    <div style="font-size: 14px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                                        <i class="fas fa-folder-open" style="color: #3b82f6; font-size: 13px;"></i>
                                        <?php echo htmlspecialchars($section['name']); ?>
                                    </div>
                                    <span style="background: #ffffff; padding: 4px 10px; border-radius: 4px; font-size: 12px; color: #64748b; font-weight: 600; border: 1px solid #e2e8f0;">
                                        <?php echo count($section['activities']); ?> item<?php echo count($section['activities']) != 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                                
                                <!-- Activities in Section -->
                                <div style="display: grid; gap: 12px; margin-left: 24px;">
                                    <?php if (!empty($section['activities'])): ?>
                                    <?php foreach ($section['activities'] as $activity): 
                                        $config = [
                                            'lesson' => ['icon' => 'book-reader', 'color' => '#3b82f6'],
                                            'assign' => ['icon' => 'file-alt', 'color' => '#10b981'],
                                            'quiz' => ['icon' => 'clipboard-check', 'color' => '#f59e0b'],
                                            'forum' => ['icon' => 'comments', 'color' => '#ec4899'],
                                            'resource' => ['icon' => 'file', 'color' => '#8b5cf6'],
                                            'page' => ['icon' => 'file-lines', 'color' => '#14b8a6'],
                                            'url' => ['icon' => 'link', 'color' => '#f97316'],
                                        ];
                                        
                                        $icon = $config[$activity['type']]['icon'] ?? 'puzzle-piece';
                                        $color = $config[$activity['type']]['color'] ?? '#60a5fa';
                                    ?>
                                    <div style="background: #fafbfc; padding: 16px; border-radius: 10px; border: 2px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center;">
                                        <div style="display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0;">
                                            <div style="width: 40px; height: 40px; background: <?php echo $color; ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);">
                                                <i class="fas fa-<?php echo $icon; ?>" style="font-size: 16px;"></i>
                                            </div>
                                            <div style="flex: 1; min-width: 0;">
                                                <h4 style="margin: 0; color: #1f2937; font-size: 15px; font-weight: 700;">
                                                    <?php echo htmlspecialchars($activity['name']); ?>
                                                </h4>
                                                <p style="margin: 6px 0 0 0; font-size: 12px; color: #6b7280; font-weight: 600;">
                                                    <span style="background: white; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;">
                                                        <?php echo htmlspecialchars($activity['type']); ?>
                                                    </span>
                                                    <?php if (isset($activity['visible']) && $activity['visible'] == 0): ?>
                                                    <span style="margin-left: 8px; color: #f59e0b;">
                                                        <i class="fas fa-eye-slash"></i> Hidden
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($activity['completion']): ?>
                                                    <span style="margin-left: 8px; color: #10b981;">
                                                        <i class="fas fa-check-circle"></i> Tracked
                                                    </span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <?php if ($activity['deadline']): ?>
                                        <div style="text-align: right; font-size: 12px; color: #f59e0b; font-weight: 700; min-width: 100px;">
                                            <i class="fas fa-clock"></i> <?php echo date('M d, Y', $activity['deadline']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <div style="padding: 24px; background: #f9fafb; border-radius: 12px; text-align: center; color: #9ca3af; font-size: 14px; font-weight: 600;">
                                        <i class="fas fa-info-circle"></i> No activities in this section
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 50px 30px; background: linear-gradient(135deg, #ffffff, #f8fafc); border-radius: 16px; border: 2px dashed #e0f2fe;">
                    <div style="font-size: 60px; color: #bfdbfe; margin-bottom: 20px;"><i class="fas fa-book-open"></i></div>
                    <h3 style="font-size: 20px; font-weight: 700; color: #4b5563; margin: 0 0 10px 0;">No Learning Content Found</h3>
                    <p style="font-size: 14px; color: #6b7280; margin: 0;">
                        <?php echo ($selected_child && $selected_child !== 'all' && $selected_child != 0) 
                            ? 'The selected child has no courses with learning activities yet.' 
                            : 'Please select a child from the main dashboard to view their learning activities.'; ?>
                    </p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-users"></i></div>
                    <h3 class="empty-title-progress">No Children Found</h3>
                    <p class="empty-text-progress">You don't have any children linked to your parent account yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assignments Tab -->
        <div id="assignments-tab" class="tab-content-progress">
            <?php if (!empty($assignment_submissions) || $assignment_stats['total'] > 0): ?>
                <!-- Assignment Statistics -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #3b82f6; margin-bottom: 8px;"><?php echo $assignment_stats['total']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Assignments</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #10b981; margin-bottom: 8px;"><?php echo $assignment_stats['submitted']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Submitted</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #8b5cf6; margin-bottom: 8px;"><?php echo $assignment_stats['graded']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Graded</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #f59e0b; margin-bottom: 8px;"><?php echo $assignment_stats['pending']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Pending</div>
                    </div>
                    <?php if ($assignment_stats['average_grade'] > 0): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #14b8a6; margin-bottom: 8px;"><?php echo $assignment_stats['average_grade']; ?>%</div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Average Grade</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Assignment Submissions List -->
                <div style="display: grid; gap: 16px;">
                    <?php foreach (array_slice($assignment_submissions, 0, 50) as $submission): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); transition: all 0.3s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.06)';">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 300px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 4px 0;"><?php echo htmlspecialchars($submission['assignment_name']); ?></h3>
                                        <div style="font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-book" style="font-size: 11px;"></i>
                                            <span><?php echo htmlspecialchars($submission['course_name']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px;">
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 8px;">
                                        <i class="fas fa-calendar-alt" style="color: #64748b; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #475569; font-weight: 600;">Submitted: <?php echo $submission['submitted_date']; ?></span>
                                    </div>
                                    <?php if ($submission['due_date']): ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: <?php echo $submission['is_overdue'] ? '#fef2f2' : '#f8fafc'; ?>; border-radius: 8px; border: 1px solid <?php echo $submission['is_overdue'] ? '#fecaca' : '#e2e8f0'; ?>;">
                                        <i class="fas fa-clock" style="color: <?php echo $submission['is_overdue'] ? '#ef4444' : '#64748b'; ?>; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: <?php echo $submission['is_overdue'] ? '#dc2626' : '#475569'; ?>; font-weight: 600;">Due: <?php echo $submission['due_date']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                                <?php if ($submission['is_graded']): ?>
                                <div style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 18px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                                    <?php echo $submission['grade_percentage']; ?>%
                                </div>
                                <div style="font-size: 11px; color: #64748b; text-align: right;">
                                    Grade: <?php echo $submission['grade']; ?>/<?php echo $submission['max_grade']; ?>
                                </div>
                                <?php else: ?>
                                <div style="background: #f1f5f9; color: #64748b; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 14px; border: 1px solid #e2e8f0;">
                                    <i class="fas fa-hourglass-half"></i> Pending
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($submission['is_overdue']): ?>
                                <div style="background: #fef2f2; color: #dc2626; padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; border: 1px solid #fecaca;">
                                    <i class="fas fa-exclamation-triangle"></i> Overdue
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-file-alt"></i></div>
                    <h3 class="empty-title-progress">No Assignments Found</h3>
                    <p class="empty-text-progress">No assignment submissions found for the selected child and course filter.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quizzes Tab -->
        <div id="quizzes-tab" class="tab-content-progress">
            <?php if (!empty($quiz_attempts) || $quiz_stats['attempts'] > 0): ?>
                <!-- Quiz Statistics -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #8b5cf6; margin-bottom: 8px;"><?php echo $quiz_stats['total_quizzes']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Quizzes</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #3b82f6; margin-bottom: 8px;"><?php echo $quiz_stats['attempts']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Attempts</div>
                    </div>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #10b981; margin-bottom: 8px;"><?php echo $quiz_stats['completed']; ?></div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Completed</div>
                    </div>
                    <?php if ($quiz_stats['average_score'] > 0): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #14b8a6; margin-bottom: 8px;"><?php echo $quiz_stats['average_score']; ?>%</div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Average Score</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($quiz_stats['best_score'] > 0): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); text-align: center;">
                        <div style="font-size: 32px; font-weight: 800; color: #f59e0b; margin-bottom: 8px;"><?php echo $quiz_stats['best_score']; ?>%</div>
                        <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Best Score</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quiz Attempts List -->
                <div style="display: grid; gap: 16px;">
                    <?php foreach (array_slice($quiz_attempts, 0, 50) as $quiz): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); transition: all 0.3s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.06)';">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 300px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);">
                                        <i class="fas fa-question-circle"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 4px 0;"><?php echo htmlspecialchars($quiz['quiz_name']); ?></h3>
                                        <div style="font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-book" style="font-size: 11px;"></i>
                                            <span><?php echo htmlspecialchars($quiz['course_name']); ?></span>
                                            <span style="margin-left: 8px; padding: 2px 8px; background: #f1f5f9; border-radius: 4px; font-size: 11px; font-weight: 600;">Attempt #<?php echo $quiz['attempt_number']; ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px;">
                                    <?php if ($quiz['is_finished']): ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0;">
                                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #166534; font-weight: 600;">Completed: <?php echo $quiz['finish_date']; ?></span>
                                    </div>
                                    <?php else: ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fef3c7; border-radius: 8px; border: 1px solid #fde68a;">
                                        <i class="fas fa-clock" style="color: #f59e0b; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #92400e; font-weight: 600;">In Progress</span>
                                    </div>
                                    <?php endif; ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 8px;">
                                        <i class="fas fa-calendar-alt" style="color: #64748b; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #475569; font-weight: 600;">Started: <?php echo $quiz['start_date']; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($quiz['is_finished'] && $quiz['score_percentage'] !== null): ?>
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                                <div style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 18px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);">
                                    <?php echo $quiz['score_percentage']; ?>%
                                </div>
                                <div style="font-size: 11px; color: #64748b; text-align: right;">
                                    Score: <?php echo $quiz['score']; ?>/<?php echo $quiz['max_score']; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-question-circle"></i></div>
                    <h3 class="empty-title-progress">No Quiz Attempts Found</h3>
                    <p class="empty-text-progress">No quiz attempts found for the selected child and course filter.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Grades Tab -->
        <div id="grades-tab" class="tab-content-progress">
            <?php if (!empty($course_grades) || !empty($gradebook_data)): ?>
                <!-- Course Grades Overview -->
                <?php if (!empty($course_grades)): ?>
                <div style="margin-bottom: 32px;">
                    <h2 style="font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-star" style="color: #f59e0b;"></i>
                        Course Final Grades
                    </h2>
                    <div style="display: grid; gap: 16px;">
                        <?php foreach ($course_grades as $grade): ?>
                        <div style="background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 300px;">
                                    <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px 0;"><?php echo htmlspecialchars($grade['course_name']); ?></h3>
                                    <div style="font-size: 13px; color: #64748b;">Last updated: <?php echo userdate($grade['timemodified'], get_string('strftimedatefullshort', 'langconfig')); ?></div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 16px;">
                                    <div style="text-align: right;">
                                        <div style="font-size: 32px; font-weight: 800; color: #10b981; line-height: 1;">
                                            <?php echo $grade['grade_percentage'] ?? 'N/A'; ?><?php echo $grade['grade_percentage'] !== null ? '%' : ''; ?>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                            <?php echo $grade['final_grade']; ?>/<?php echo $grade['max_grade']; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Gradebook Details -->
                <?php if (!empty($gradebook_data)): ?>
                <div>
                    <h2 style="font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-list-alt" style="color: #3b82f6;"></i>
                        Gradebook Details
                    </h2>
                    <?php foreach ($gradebook_data as $gradebook): ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); margin-bottom: 24px;">
                        <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9;">
                            <?php echo htmlspecialchars($gradebook['course_name']); ?>
                        </h3>
                        <div style="display: grid; gap: 12px;">
                            <?php foreach ($gradebook['items'] as $item): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <div style="flex: 1;">
                                    <div style="font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 4px;"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div style="font-size: 12px; color: #64748b;">
                                        <span style="text-transform: uppercase; font-weight: 600;"><?php echo htmlspecialchars($item['module'] ?? $item['type']); ?></span>
                                        <?php if ($item['feedback']): ?>
                                        <span style="margin-left: 12px; color: #475569;">
                                            <i class="fas fa-comment" style="font-size: 11px;"></i> Has feedback
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <?php if ($item['percentage'] !== null): ?>
                                    <div style="font-size: 24px; font-weight: 800; color: #10b981;"><?php echo $item['percentage']; ?>%</div>
                                    <div style="font-size: 12px; color: #64748b;"><?php echo $item['grade']; ?>/<?php echo $item['max_grade']; ?></div>
                                    <?php else: ?>
                                    <div style="font-size: 14px; color: #94a3b8; font-weight: 600;">Not Graded</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-star"></i></div>
                    <h3 class="empty-title-progress">No Grades Available</h3>
                    <p class="empty-text-progress">No grades found for the selected child and course filter.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Deadlines Tab -->
        <div id="deadlines-tab" class="tab-content-progress">
            <?php if (!empty($upcoming_deadlines)): ?>
                <div style="display: grid; gap: 16px;">
                    <?php foreach ($upcoming_deadlines as $deadline): 
                        $urgency_color = '#10b981';
                        $urgency_bg = '#f0fdf4';
                        if ($deadline['days_until'] <= 3) {
                            $urgency_color = '#ef4444';
                            $urgency_bg = '#fef2f2';
                        } elseif ($deadline['days_until'] <= 7) {
                            $urgency_color = '#f59e0b';
                            $urgency_bg = '#fffbeb';
                        }
                    ?>
                    <div style="background: #ffffff; border-radius: 12px; padding: 20px; border-left: 4px solid <?php echo $urgency_color; ?>; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); transition: all 0.3s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.06)';">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 300px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, <?php echo $urgency_color; ?>, <?php echo $urgency_color; ?>dd); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 12px <?php echo $urgency_color; ?>40;">
                                        <i class="fas fa-<?php echo $deadline['type'] === 'assignment' ? 'file-alt' : 'question-circle'; ?>"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 4px 0;"><?php echo htmlspecialchars($deadline['name']); ?></h3>
                                        <div style="font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-book" style="font-size: 11px;"></i>
                                            <span><?php echo htmlspecialchars($deadline['course_name']); ?></span>
                                            <span style="margin-left: 8px; padding: 2px 8px; background: <?php echo $urgency_bg; ?>; color: <?php echo $urgency_color; ?>; border-radius: 4px; font-size: 11px; font-weight: 700; border: 1px solid <?php echo $urgency_color; ?>20;">
                                                <?php echo $deadline['days_until']; ?> day<?php echo $deadline['days_until'] != 1 ? 's' : ''; ?> left
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px;">
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 8px;">
                                        <i class="fas fa-calendar-alt" style="color: #64748b; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #475569; font-weight: 600;"><?php echo $deadline['deadline_formatted']; ?></span>
                                    </div>
                                    <?php if ($deadline['type'] === 'assignment' && $deadline['is_submitted']): ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0;">
                                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #166534; font-weight: 600;">Submitted</span>
                                    </div>
                                    <?php elseif ($deadline['type'] === 'quiz' && $deadline['is_completed']): ?>
                                    <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0;">
                                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 12px;"></i>
                                        <span style="font-size: 12px; color: #166534; font-weight: 600;">Completed</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-progress">
                    <div class="empty-icon-progress"><i class="fas fa-calendar-alt"></i></div>
                    <h3 class="empty-title-progress">No Upcoming Deadlines</h3>
                    <p class="empty-text-progress">No upcoming assignment or quiz deadlines found for the selected child and course filter.</p>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>

<script>
// Course Filter Function - Similar to parent_reports.php
function filterByCourse(courseId) {
    const currentUrl = new URL(window.location.href);
    
    if (courseId === 'all' || courseId === '0') {
        currentUrl.searchParams.delete('course');
    } else {
        currentUrl.searchParams.set('course', courseId);
    }
    
    window.location.href = currentUrl.toString();
}

function switchProgressTab(tabName, buttonElement) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content-progress').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button-progress').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show the selected tab content
    const selectedTab = document.getElementById(tabName + '-tab');
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    
    // Add active class to the clicked button
    if (buttonElement) {
        buttonElement.classList.add('active');
    } else {
        // Fallback: find button by tab name
        const buttons = document.querySelectorAll('.tab-button-progress');
        buttons.forEach(btn => {
            if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes("'" + tabName + "'")) {
                btn.classList.add('active');
            }
        });
    }
}

// Activities Tab Filtering (from parent_activities.php)
function filterActivities() {
    const courseFilter = document.getElementById('courseFilter');
    const typeFilter = document.getElementById('typeFilter');
    const actionFilter = document.getElementById('actionFilter');
    const noResults = document.getElementById('noResults');
    const resultsCount = document.getElementById('resultsCount');
    
    if (!courseFilter || !typeFilter || !actionFilter) {
        return;
    }
    
    const courseValue = courseFilter.value;
    const typeValue = typeFilter.value;
    const actionValue = actionFilter.value;
    
    const activities = document.querySelectorAll('.activity-item');
    
    if (activities.length === 0) {
        return;
    }
    
    let visibleCount = 0;
    
    activities.forEach(activity => {
        const activityCourse = activity.getAttribute('data-course');
        const activityType = activity.getAttribute('data-type');
        const activityAction = activity.getAttribute('data-action');
        
        let showActivity = true;
        
        // Course filter
        if (courseValue !== 'all' && activityCourse !== courseValue) {
            showActivity = false;
        }
        
        // Type filter
        if (typeValue !== 'all' && activityType !== typeValue) {
            showActivity = false;
        }
        
        // Action filter
        if (actionValue !== 'all' && activityAction !== actionValue) {
            showActivity = false;
        }
        
        if (showActivity) {
            activity.style.display = 'block';
            visibleCount++;
        } else {
            activity.style.display = 'none';
        }
    });
    
    // Update results count
    if (resultsCount) {
        resultsCount.textContent = `Showing ${visibleCount} of ${activities.length} activities`;
    }
    
    // Show/hide no results message
    if (visibleCount === 0) {
        if (noResults) noResults.style.display = 'block';
    } else {
        if (noResults) noResults.style.display = 'none';
    }
}

function resetFilters() {
    const courseFilter = document.getElementById('courseFilter');
    const typeFilter = document.getElementById('typeFilter');
    const actionFilter = document.getElementById('actionFilter');
    
    if (courseFilter) courseFilter.value = 'all';
    if (typeFilter) typeFilter.value = 'all';
    if (actionFilter) actionFilter.value = 'all';
    
    filterActivities();
}

// Lessons Tab Filtering (from parent_lessons.php)
function filterLessons() {
    const courseFilterEl = document.getElementById('courseFilterLessons');
    const typeFilterEl = document.getElementById('typeFilterLessons');
    const completionFilterEl = document.getElementById('completionFilterLessons');
    const noResults = document.getElementById('noResultsLessons');
    const resultsCount = document.getElementById('resultsCountLessons');
    
    if (!courseFilterEl || !typeFilterEl || !completionFilterEl) {
        return;
    }
    
    const courseFilter = courseFilterEl.value;
    const typeFilter = typeFilterEl.value;
    const completionFilter = completionFilterEl.value;
    
    const lessons = document.querySelectorAll('.lesson-item');
    
    if (lessons.length === 0) {
        return;
    }
    
    let visibleCount = 0;
    
    lessons.forEach(lesson => {
        const lessonCourse = lesson.getAttribute('data-course');
        const lessonType = lesson.getAttribute('data-type');
        const lessonCompletion = lesson.getAttribute('data-completion');
        
        let showLesson = true;
        
        if (courseFilter !== 'all' && lessonCourse !== courseFilter) {
            showLesson = false;
        }
        
        if (typeFilter !== 'all' && lessonType !== typeFilter) {
            showLesson = false;
        }
        
        if (completionFilter !== 'all' && lessonCompletion !== completionFilter) {
            showLesson = false;
        }
        
        if (showLesson) {
            lesson.style.display = 'block';
            visibleCount++;
        } else {
            lesson.style.display = 'none';
        }
    });
    
    const total = lessons.length;
    const percentage = total > 0 ? Math.round((visibleCount / total) * 100) : 0;
    
    if (resultsCount) {
        resultsCount.innerHTML = `Showing <strong style="color: #1d4ed8; font-size: 16px;">${visibleCount}</strong> of ${total} <span style="color: #60a5fa;">(${percentage}%)</span>`;
    }
    
    const lessonsContainer = document.getElementById('lessonsContainer');
    
    if (visibleCount === 0) {
        if (noResults) noResults.style.display = 'block';
        if (lessonsContainer) lessonsContainer.style.display = 'none';
    } else {
        if (noResults) noResults.style.display = 'none';
        if (lessonsContainer) lessonsContainer.style.display = 'grid';
    }
}

function resetLessonFilters() {
    const courseFilterEl = document.getElementById('courseFilterLessons');
    const typeFilterEl = document.getElementById('typeFilterLessons');
    const completionFilterEl = document.getElementById('completionFilterLessons');
    
    if (courseFilterEl) courseFilterEl.value = 'all';
    if (typeFilterEl) typeFilterEl.value = 'all';
    if (completionFilterEl) completionFilterEl.value = 'all';
    
    filterLessons();
}

function quickFilterLessons(type) {
    const courseFilterEl = document.getElementById('courseFilterLessons');
    const typeFilterEl = document.getElementById('typeFilterLessons');
    const completionFilterEl = document.getElementById('completionFilterLessons');
    
    if (!typeFilterEl || !completionFilterEl) {
        return;
    }
    
    if (courseFilterEl) courseFilterEl.value = 'all';
    if (typeFilterEl) typeFilterEl.value = 'all';
    if (completionFilterEl) completionFilterEl.value = 'all';
    
    switch(type) {
        case 'lesson':
        case 'assign':
        case 'quiz':
            typeFilterEl.value = type;
            break;
        case 'tracked':
            completionFilterEl.value = '1';
            break;
    }
    
    filterLessons();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tab system - ensure first tab is active
    const firstTabButton = document.querySelector('.tab-button-progress.active');
    const firstTabContent = document.querySelector('.tab-content-progress.active');
    
    if (!firstTabButton || !firstTabContent) {
        // If no active tab, activate the first one
        const firstButton = document.querySelector('.tab-button-progress');
        const firstTabId = firstButton ? firstButton.getAttribute('onclick').match(/'([^']+)'/)[1] : 'progress';
        if (firstButton) {
            switchProgressTab(firstTabId, firstButton);
        }
    }
    
    // Check for hash in URL to open specific tab
    if (window.location.hash) {
        const hash = window.location.hash.substring(1); // Remove #
        const validTabs = ['progress', 'activities', 'lessons', 'assignments', 'quizzes', 'grades', 'deadlines'];
        if (validTabs.includes(hash)) {
            const tabButton = Array.from(document.querySelectorAll('.tab-button-progress')).find(btn => {
                const onclick = btn.getAttribute('onclick') || '';
                return onclick.includes("'" + hash + "'");
            });
            if (tabButton) {
                switchProgressTab(hash, tabButton);
            }
        }
    }
    
    // Initialize activities filter
    const activitiesCount = document.querySelectorAll('.activity-item').length;
    if (activitiesCount > 0) {
        filterActivities();
    }
    
    // Initialize lessons filter
    const lessonsCount = document.querySelectorAll('.lesson-item').length;
    if (lessonsCount > 0) {
        filterLessons();
    }
    
    // Add click handlers for better compatibility
    document.querySelectorAll('.tab-button-progress').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const onclick = this.getAttribute('onclick');
            if (onclick) {
                const match = onclick.match(/switchProgressTab\('([^']+)'/);
                if (match) {
                    switchProgressTab(match[1], this);
                    // Update URL hash without scrolling
                    history.pushState(null, null, '#' + match[1]);
                }
            }
        });
    });
});
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

<?php echo $OUTPUT->footer(); ?>

