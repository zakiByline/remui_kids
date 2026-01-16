<?php
// Use core_completion\progress for progress calculation
use core_completion\progress;

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_login();
require_once(__DIR__ . '/lib/highschool_sidebar.php');

global $USER, $DB, $OUTPUT, $PAGE, $CFG;

$PAGE->set_url('/theme/remui_kids/highschool_myreports.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Reports - High School');
$PAGE->add_body_class('custom-dashboard-page has-student-sidebar highschool-reports-page');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

// Initialize grade report objects
require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/grade/report/user/lib.php');

// Comprehensive data collection for professional dashboard
$reports = [];
$totalassignments = 0;
$completedassignments = 0;
$totalquizzes = 0;
$completedquizzes = 0;
$has_real_data = false;
$grade_report_data = [];

// Professional dashboard data with comprehensive metrics
$dashboard_stats = [
    'total_courses' => 0,
    'completed_courses' => 0,
    'total_assignments' => 0,
    'completed_assignments' => 0,
    'total_quizzes' => 0,
    'completed_quizzes' => 0,
    'average_grade' => 0,
    'total_time_spent' => 0,
    'completion_rate' => 0,
    'assignment_completion_rate' => 0,
    'quiz_completion_rate' => 0,
    'grade_trend' => 'stable', // improving, declining, stable
    'performance_rating' => 'Good', // Excellent, Good, Average, Needs Improvement
    'recent_activities' => [],
    'upcoming_deadlines' => [],
    'grade_trends' => [],
    'subject_performance' => [],
    'attendance_rate' => 0,
    'participation_score' => 0,
    'homework_completion' => 0,
    'test_scores' => [],
    'overall_rank' => 0,
    'class_average' => 0,
    'improvement_areas' => [],
    'strengths' => []
];

// Comprehensive grade analytics
$grade_analytics = [
    'overall_average' => 0,
    'grade_distribution' => [],
    'improvement_areas' => [],
    'strengths' => [],
    'recent_grades' => [],
    'grade_trend' => 'stable',
    'highest_grade' => 0,
    'lowest_grade' => 0,
    'grade_range' => 0,
    'grade_consistency' => 0, // How consistent are the grades
    'subject_breakdown' => [],
    'monthly_progress' => [],
    'grade_predictions' => [],
    'comparison_with_peers' => [],
    'grade_history' => [],
    'improvement_suggestions' => [],
    'excellent_performances' => [],
    'areas_of_concern' => []
];

// Professional course performance data
$course_performance = [];
$subject_grades = [];
$activity_completion = [];

// Professional analytics data
$professional_analytics = [
    'learning_style_analysis' => [],
    'study_habits_score' => 0,
    'engagement_level' => 'Medium', // High, Medium, Low
    'time_management_score' => 0,
    'collaboration_score' => 0,
    'critical_thinking_score' => 0,
    'creativity_score' => 0,
    'communication_score' => 0,
    'leadership_potential' => 0,
    'academic_goals' => [],
    'career_readiness' => [],
    'skill_development' => [],
    'extracurricular_impact' => [],
    'peer_comparison' => [],
    'teacher_feedback' => [],
    'parent_insights' => [],
    'self_assessment' => [],
    'future_recommendations' => []
];

// Real-time data collection
$real_time_data = [
    'current_assignments' => [],
    'upcoming_deadlines' => [],
    'recent_submissions' => [],
    'pending_feedback' => [],
    'study_reminders' => [],
    'achievement_milestones' => [],
    'challenges_faced' => [],
    'success_stories' => []
];

    // Grade reports already initialized above

// Debug: Log that the script is starting
error_log("HS MyReports: Script started for user " . $USER->id . " (" . fullname($USER) . ")");

// Initialize all data arrays to prevent undefined variable errors
$dashboard_stats = [
    'total_courses' => 0,
    'completed_courses' => 0,
    'total_assignments' => 0,
    'completed_assignments' => 0,
    'total_quizzes' => 0,
    'completed_quizzes' => 0,
    'average_grade' => 0,
    'total_time_spent' => 0,
    'completion_rate' => 0,
    'assignment_completion_rate' => 0,
    'quiz_completion_rate' => 0,
    'grade_trend' => 'stable',
    'performance_rating' => 'Good',
    'recent_activities' => [],
    'upcoming_deadlines' => [],
    'grade_trends' => [],
    'subject_performance' => [],
    'attendance_rate' => 0,
    'participation_score' => 0,
    'homework_completion' => 0,
    'test_scores' => [],
    'overall_rank' => 0,
    'class_average' => 0,
    'improvement_areas' => [],
    'strengths' => []
];
$grade_analytics = [];
$course_performance = [];
$professional_analytics = [];
$real_time_data = [];
$grade_reports = [];

try {
    // Get user's enrolled courses with comprehensive data
    $courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname, enablecompletion, category, startdate, enddate, summary, timecreated, timemodified');
    
    error_log("HS MyReports: Found " . count($courses) . " courses for user " . $USER->id);
    
    // Initialize dashboard counters
    $dashboard_stats['total_courses'] = count($courses);
    $total_grades = 0;
    $grade_sum = 0;
    $all_grades = [];
    
    foreach ($courses as $course) {
        if ($course->id == SITEID) {
            continue; // Skip site course
        }
        
        $coursecontext = context_course::instance($course->id);
        
        // Fetch REAL course image from Moodle files (NO fallback)
        $course_image_url = '';
        try {
            // Method 1: Try using course format's get_course_image_url
            if (class_exists('core_courseformat\base')) {
                $courseformat = course_get_format($course->id);
                if (method_exists($courseformat, 'get_course_image_url')) {
                    $course_image_url = $courseformat->get_course_image_url();
                }
            }
            
            // Method 2: If not found, try course overview files directly
            if (empty($course_image_url)) {
                $fs = get_file_storage();
                $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', false, 'itemid, filepath, filename', false);
                
                foreach ($files as $file) {
                    if ($file->is_valid_image()) {
                        $course_image_url = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            null,
                            $file->get_filepath(),
                            $file->get_filename(),
                            false
                        )->out();
                        break; // Use first valid image only
                    }
                }
            }
            
            // Method 3: Try using core_course_list_element
            if (empty($course_image_url)) {
                $course_full = $DB->get_record('course', ['id' => $course->id]);
                if ($course_full) {
                    $course_obj = new core_course_list_element($course_full);
                    $course_files = $course_obj->get_course_overviewfiles();
                    
                    if (!empty($course_files)) {
                        foreach ($course_files as $file) {
                            if ($file->is_valid_image()) {
                                $course_image_url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    null,
                                    $file->get_filepath(),
                                    $file->get_filename(),
                                    false
                                )->out();
                                break;
                            }
                        }
                    }
                }
            }
            
            // Method 4: Check course summary files
            if (empty($course_image_url)) {
                $fs = get_file_storage();
                $files = $fs->get_area_files($coursecontext->id, 'course', 'summary', false, 'itemid, filepath, filename', false);
                
                foreach ($files as $file) {
                    $mimetype = $file->get_mimetype();
                    if (strpos($mimetype, 'image/') === 0) {
                        $course_image_url = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            null,
                            $file->get_filepath(),
                            $file->get_filename(),
                            false
                        )->out();
                        break;
                    }
                }
            }
            
            // Log result for debugging
            if (!empty($course_image_url)) {
                error_log("HS MyReports: Found course image for course {$course->id}: " . $course_image_url);
            } else {
                error_log("HS MyReports: No course image found for course {$course->id} - trying all methods");
            }
            
        } catch (Exception $e) {
            error_log("HS MyReports: Error fetching course image for course {$course->id}: " . $e->getMessage());
            error_log("HS MyReports: Error trace: " . $e->getTraceAsString());
        }
        
        // Professional course data collection
        $course_data = [
            'id' => $course->id,
            'name' => $course->fullname,
            'shortname' => $course->shortname,
            'category' => $course->category,
            'startdate' => $course->startdate,
            'enddate' => $course->enddate,
            'summary' => $course->summary,
            'timecreated' => $course->timecreated,
            'timemodified' => $course->timemodified,
            'courseimage' => $course_image_url, // REAL course image URL (empty if none)
            'has_image' => !empty($course_image_url),
            'completion_percentage' => 0,
            'total_activities' => 0,
            'completed_activities' => 0,
            'assignments' => [],
            'quizzes' => [],
            'grades' => [],
            'recent_activity' => null,
            'teacher_info' => [],
            'time_spent' => 0,
            // Professional analytics
            'performance_rating' => 'Good',
            'grade_trend' => 'stable',
            'engagement_score' => 0,
            'participation_rate' => 0,
            'homework_completion' => 0,
            'test_performance' => [],
            'peer_comparison' => [],
            'teacher_feedback' => [],
            'improvement_suggestions' => [],
            'strengths' => [],
            'challenges' => [],
            'learning_objectives' => [],
            'skill_development' => [],
            'career_relevance' => [],
            'future_goals' => []
        ];
        
        // Get comprehensive course completion info
        $completion = new completion_info($course);
        $course_complete = $completion->is_course_complete($USER->id);
        $progress = 0;
        
        // Calculate detailed progress
        if ($completion->is_enabled()) {
            try {
                $progress = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
            } catch (Exception $e) {
                // Fallback: manual calculation
                $criteria = $completion->get_criteria();
                $total_criteria = count($criteria);
                $completed_criteria = 0;
                
                foreach ($criteria as $criterion) {
                    try {
                        // is_complete() may internally access cm_info properties that don't exist
                        // Use @ to suppress warnings from __get() calling debugging()
                        if (@$criterion->is_complete($USER->id)) {
                            $completed_criteria++;
                        }
                    } catch (Exception $e) {
                        // Skip this criterion if there's an error accessing cm_info properties
                        error_log("HS MyReports: Error checking completion criterion: " . $e->getMessage());
                    }
                }
                
                $progress = $total_criteria > 0 ? ($completed_criteria / $total_criteria) * 100 : 0;
            }
        }
        
        $course_data['completion_percentage'] = round($progress, 1);
        if ($course_complete) {
            $dashboard_stats['completed_courses']++;
        }
        
        // Fetch comprehensive course activities and grades
        $modinfo = get_fast_modinfo($course);
        $activities = $modinfo->get_cms();
        $course_assignments = [];
        $course_quizzes = [];
        $course_grades = [];
        
        // Get all course modules and their completion status
        foreach ($activities as $cm) {
            if (!$cm->uservisible) continue;
            
            // Safely access completionstate to avoid triggering __get() on cm_info
            $completionstate = null;
            try {
                $completionstate = @$cm->completionstate;
            } catch (Exception $e) {
                // Property doesn't exist
            }
            $activity_data = [
                'id' => $cm->id,
                'name' => $cm->name,
                'type' => $cm->modname,
                'completion' => @$cm->completion ?? 0,
                'completionstate' => $completionstate,
                'is_completed' => $completionstate == COMPLETION_COMPLETE,
                'grade' => null,
                'grade_formatted' => null,
                'time_spent' => 0
            ];
            
            // Get grade for this activity
            if ($cm->modname == 'assign' || $cm->modname == 'quiz') {
                $grade_item = grade_item::fetch([
                    'itemtype' => 'mod',
                    'itemmodule' => $cm->modname,
                    'iteminstance' => $cm->instance,
                    'courseid' => $course->id
                ]);
                
                if ($grade_item) {
                    $grade = grade_grade::fetch([
                        'itemid' => $grade_item->id,
                        'userid' => $USER->id
                    ]);
                    
                    if ($grade && $grade->finalgrade !== null) {
                        $activity_data['grade'] = $grade->finalgrade;
                        // Format grade manually
                        $activity_data['grade_formatted'] = number_format($grade->finalgrade, 2) . ' / ' . number_format($grade_item->grademax, 2);
                        
                        // Add to overall grade calculation
                        $all_grades[] = $grade->finalgrade;
                        $grade_sum += $grade->finalgrade;
                        $total_grades++;
                    }
                }
            }
            
            // Categorize activities
            if ($cm->modname == 'assign') {
                $course_assignments[] = $activity_data;
                $dashboard_stats['total_assignments']++;
                if ($activity_data['is_completed']) {
                    $dashboard_stats['completed_assignments']++;
                }
            } elseif ($cm->modname == 'quiz') {
                $course_quizzes[] = $activity_data;
                $dashboard_stats['total_quizzes']++;
                if ($activity_data['is_completed']) {
                    $dashboard_stats['completed_quizzes']++;
                }
            }
            
            $course_data['total_activities']++;
            if ($activity_data['is_completed']) {
                $course_data['completed_activities']++;
            }
        }
        
        $course_data['assignments'] = $course_assignments;
        $course_data['quizzes'] = $course_quizzes;
        $course_data['grades'] = $course_grades;
        
        // Add assignment and quiz counts to course_data
        $course_data['total_assignments'] = count($course_assignments);
        $course_data['completed_assignments'] = count(array_filter($course_assignments, function($a) { return $a['is_completed']; }));
        $course_data['total_quizzes'] = count($course_quizzes);
        $course_data['completed_quizzes'] = count(array_filter($course_quizzes, function($q) { return $q['is_completed']; }));
        
        // Get comprehensive teacher information
        $teachers = get_enrolled_users($coursecontext, 'moodle/course:manageactivities');
        foreach ($teachers as $teacher) {
            $course_data['teacher_info'][] = [
                'id' => $teacher->id,
                'name' => fullname($teacher),
                'email' => $teacher->email,
                'role' => 'Teacher',
                'department' => 'Academic',
                'specialization' => 'Subject Expert',
                'feedback_count' => 0,
                'response_time' => '24 hours',
                'availability' => 'Available'
            ];
        }
        
        // Calculate professional metrics
        $course_data['engagement_score'] = min(100, ($course_data['completed_activities'] / max(1, $course_data['total_activities'])) * 100);
        $course_data['participation_rate'] = $course_data['engagement_score'];
        $course_data['homework_completion'] = isset($course_data['completed_assignments']) && isset($course_data['total_assignments']) && $course_data['total_assignments'] > 0 
            ? ($course_data['completed_assignments'] / $course_data['total_assignments']) * 100 
            : 0;
        
        // Performance rating calculation
        if ($course_data['completion_percentage'] >= 90) {
            $course_data['performance_rating'] = 'Excellent';
        } elseif ($course_data['completion_percentage'] >= 75) {
            $course_data['performance_rating'] = 'Good';
        } elseif ($course_data['completion_percentage'] >= 60) {
            $course_data['performance_rating'] = 'Average';
        } else {
            $course_data['performance_rating'] = 'Needs Improvement';
        }
        
        // Grade trend analysis
        if (!empty($all_grades)) {
            $recent_grades = array_slice($all_grades, -3); // Last 3 grades
            if (count($recent_grades) >= 2) {
                $trend = end($recent_grades) - reset($recent_grades);
                if ($trend > 5) {
                    $course_data['grade_trend'] = 'improving';
                } elseif ($trend < -5) {
                    $course_data['grade_trend'] = 'declining';
                } else {
                    $course_data['grade_trend'] = 'stable';
                }
            }
        }
        
        // Professional feedback simulation
        $course_data['teacher_feedback'] = [
            'overall_comment' => 'Good progress in this course. Continue focusing on assignments.',
            'strengths' => ['Participation', 'Assignment completion'],
            'improvements' => ['Quiz performance', 'Time management'],
            'recommendations' => ['Review materials regularly', 'Ask questions when needed']
        ];
        
        $course_data['improvement_suggestions'] = [
            'Focus on quiz preparation',
            'Improve time management',
            'Participate more in discussions',
            'Review course materials regularly'
        ];
        
        $course_data['strengths'] = [
            'Consistent assignment submission',
            'Good participation in activities',
            'Positive attitude towards learning'
        ];
        
        $course_data['challenges'] = [
            'Quiz performance needs improvement',
            'Time management could be better',
            'Need more active participation'
        ];
        
        // Calculate course average grade
        if (!empty($all_grades)) {
            $course_data['average_grade'] = round(array_sum($all_grades) / count($all_grades), 1);
        }
        
        // Store course performance data
        $course_performance[] = $course_data;
        
        // Calculate progress with multiple fallback methods
        if ($course->enablecompletion && $completion->is_enabled()) {
            // Method 1: Try using core_completion\progress class
            try {
                if (class_exists('core_completion\progress')) {
                    $percentage = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
                    $progress = $percentage ? round($percentage) : 0;
                }
            } catch (Exception $e) {
                error_log("HS MyReports: Progress class method failed: " . $e->getMessage());
            }
            
            // Method 2: Fallback - Calculate manually from completion data
            if ($progress === 0) {
                $modinfo = get_fast_modinfo($course, $USER->id);
                $total_activities = 0;
                $completed_activities = 0;
                
                foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible) {
                        continue;
                    }
                    
                    if ($completion->is_enabled($cm) && $cm->completion != COMPLETION_TRACKING_NONE) {
                        $total_activities++;
                        $data = $completion->get_data($cm, false, $USER->id);
                        if ($data->completionstate == COMPLETION_COMPLETE || 
                            $data->completionstate == COMPLETION_COMPLETE_PASS) {
                            $completed_activities++;
                        }
                    }
                }
                
                if ($total_activities > 0) {
                    $progress = round(($completed_activities / $total_activities) * 100);
                }
            }
        }
        
        // Get assignments
        $assignments = $DB->get_records('assign', ['course' => $course->id]);
        $totalassignments += count($assignments);
        
        $completed_assignments = 0;
        foreach ($assignments as $assignment) {
            $cm = get_coursemodule_from_instance('assign', $assignment->id);
            if ($cm && $completion->is_enabled($cm)) {
                $data = $completion->get_data($cm, false, $USER->id);
                if ($data->completionstate == COMPLETION_COMPLETE || $data->completionstate == COMPLETION_COMPLETE_PASS) {
                    $completed_assignments++;
                    $completedassignments++;
                }
            }
        }
        
        // Get quizzes
        $quizzes = $DB->get_records('quiz', ['course' => $course->id]);
        $totalquizzes += count($quizzes);
        
        $completed_quizzes = 0;
        foreach ($quizzes as $quiz) {
            $cm = get_coursemodule_from_instance('quiz', $quiz->id);
            if ($cm && $completion->is_enabled($cm)) {
                $data = $completion->get_data($cm, false, $USER->id);
                if ($data->completionstate == COMPLETION_COMPLETE || $data->completionstate == COMPLETION_COMPLETE_PASS) {
                    $completed_quizzes++;
                    $completedquizzes++;
                }
            }
        }
        
        // Get overall course grade
        $final_grade = null;
        $grade_percentage = null;
        $grade_letter = null;
        
        // Method 1: Get course-level grade item
        $grade_item = grade_item::fetch_course_item($course->id);
        if ($grade_item) {
            $grade_grade = new grade_grade(array('itemid' => $grade_item->id, 'userid' => $USER->id));
            
            if ($grade_grade && $grade_grade->finalgrade !== null && $grade_item->grademax > 0) {
                // Format grade manually
                $final_grade = number_format($grade_grade->finalgrade, 2) . ' / ' . number_format($grade_item->grademax, 2);
                $grade_percentage = round(($grade_grade->finalgrade / $grade_item->grademax) * 100);
                
                // Calculate letter grade manually
                $grade_letter = '';
                if ($grade_percentage >= 90) $grade_letter = 'A';
                elseif ($grade_percentage >= 80) $grade_letter = 'B';
                elseif ($grade_percentage >= 70) $grade_letter = 'C';
                elseif ($grade_percentage >= 60) $grade_letter = 'D';
                else $grade_letter = 'F';
                
                $has_real_data = true;
            }
        }
        
        // Method 2: Fallback - Calculate from all graded items if no course grade
        if ($grade_percentage === null) {
            $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax, gi.grademin,
                           gg.finalgrade, gg.rawgrade
                    FROM {grade_items} gi
                    LEFT JOIN {grade_grades} gg ON gi.id = gg.itemid AND gg.userid = ?
                    WHERE gi.courseid = ? 
                      AND gi.itemtype IN ('mod', 'manual') 
                      AND gi.hidden = 0
                      AND gg.finalgrade IS NOT NULL
                    ORDER BY gi.sortorder ASC";
            
            $graded_items = $DB->get_records_sql($sql, [$USER->id, $course->id]);
            
            if (!empty($graded_items)) {
                $total_grade = 0;
                $total_max = 0;
                
                foreach ($graded_items as $item) {
                    if ($item->finalgrade !== null && $item->grademax > 0) {
                        $total_grade += $item->finalgrade;
                        $total_max += $item->grademax;
                    }
                }
                
                if ($total_max > 0) {
                    $grade_percentage = round(($total_grade / $total_max) * 100);
                    $final_grade = round($total_grade, 2) . ' / ' . round($total_max, 2);
                    $has_real_data = true;
                }
            }
        }
        
        // Get detailed activity grades (assignments, quizzes, etc.)
        $subject_grades = [];
        
        // Fetch all grade items for this course
        $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax, gi.grademin,
                       gg.finalgrade, gg.rawgrade, gg.feedback, gi.sortorder
                FROM {grade_items} gi
                LEFT JOIN {grade_grades} gg ON gi.id = gg.itemid AND gg.userid = ?
                WHERE gi.courseid = ? 
                  AND gi.itemtype IN ('mod', 'manual')
                  AND gi.hidden = 0
                ORDER BY gi.sortorder ASC";
        
        $all_grade_items = $DB->get_records_sql($sql, [$USER->id, $course->id]);
        
        foreach ($all_grade_items as $item) {
            if ($item->finalgrade !== null && $item->grademax > 0) {
                $item_percentage = round(($item->finalgrade / $item->grademax) * 100);
                
                // Format grade manually to avoid pass-by-reference issues
                $formatted = number_format($item->finalgrade, 2) . ' / ' . number_format($item->grademax, 2);
                
                $subject_grades[] = [
                    'name' => $item->itemname,
                    'type' => $item->itemmodule ? ucfirst($item->itemmodule) : 'Manual',
                    'grade' => $item_percentage,
                    'formatted_grade' => $formatted,
                    'max_grade' => round($item->grademax, 2),
                    'raw_grade' => round($item->finalgrade, 2)
                ];
            }
        }
        
        // Get category name
        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $category_name = $category ? $category->name : 'General';
        
        // Get course start and end dates
        $start_date = $course->startdate ? userdate($course->startdate, get_string('strftimedatefullshort')) : 'Not set';
        $end_date = $course->enddate ? userdate($course->enddate, get_string('strftimedatefullshort')) : 'No end date';
        
        // Get last access time for this course
        $last_access = $DB->get_field('user_lastaccess', 'timeaccess', [
            'userid' => $USER->id,
            'courseid' => $course->id
        ]);
        $last_accessed = $last_access ? userdate($last_access, get_string('strftimerecent')) : 'Never';
        $days_since_access = $last_access ? floor((time() - $last_access) / 86400) : null;
        
        // Get total activities count
        $modinfo = get_fast_modinfo($course, $USER->id);
        $total_activities = 0;
        $completed_all_activities = 0;
        
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->uservisible) {
                $total_activities++;
                if ($completion->is_enabled($cm)) {
                    $data = $completion->get_data($cm, false, $USER->id);
                    if ($data->completionstate == COMPLETION_COMPLETE || 
                        $data->completionstate == COMPLETION_COMPLETE_PASS) {
                        $completed_all_activities++;
                    }
                }
            }
        }
        
        // Calculate time spent (if logs are available)
        $time_spent = 0;
        $sql = "SELECT SUM(timecreated) as total_time
                FROM {logstore_standard_log}
                WHERE userid = ? AND courseid = ? AND action = 'viewed'";
        try {
            $log_data = $DB->get_record_sql($sql, [$USER->id, $course->id]);
            if ($log_data && $log_data->total_time) {
                $time_spent = round($log_data->total_time / 3600, 1); // Convert to hours
            }
        } catch (Exception $e) {
            // Logs might not be available
        }
        
        // Get course teacher names
        $teachers = get_enrolled_users($coursecontext, 'moodle/course:update', 0, 'u.id, u.firstname, u.lastname');
        $teacher_names = [];
        foreach ($teachers as $teacher) {
            $teacher_names[] = fullname($teacher);
        }
        $teacher_list = !empty($teacher_names) ? implode(', ', $teacher_names) : 'No teacher assigned';
        
        $reports[] = [
            'courseid' => $course->id,
            'coursename' => $course->fullname,
            'shortname' => $course->shortname,
            'category' => $category_name,
            'courseimage' => $course_image_url, // REAL course image URL
            'has_image' => !empty($course_image_url),
            'progress' => $progress,
            'is_complete' => $course_complete,
            'total_assignments' => count($assignments),
            'completed_assignments' => $completed_assignments,
            'total_quizzes' => count($quizzes),
            'completed_quizzes' => $completed_quizzes,
            'final_grade' => $final_grade,
            'grade_percentage' => $grade_percentage,
            'grade_letter' => $grade_letter,
            'has_grade' => $final_grade !== null,
            'subject_grades' => $subject_grades,
            'has_subject_grades' => !empty($subject_grades),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'last_accessed' => $last_accessed,
            'days_since_access' => $days_since_access,
            'total_activities' => $total_activities,
            'completed_all_activities' => $completed_all_activities,
            'time_spent_hours' => $time_spent,
            'teacher_list' => $teacher_list,
            'courseurl' => new moodle_url('/course/view.php', ['id' => $course->id]),
            'gradeurl' => new moodle_url('/grade/report/user/index.php', ['id' => $course->id])
        ];
        
        error_log("HS MyReports: Course '{$course->fullname}' - Progress: {$progress}%, Grade: " . ($grade_percentage ?? 'N/A') . "%");
    }
    
} catch (Exception $e) {
    error_log("HS MyReports Error: " . $e->getMessage());
}

// Calculate statistics
$total_courses = count($reports);
$completed_courses = 0;
$average_progress = 0;
$average_grade = 0;
$courses_with_grades = 0;

foreach ($reports as $report) {
    if ($report['is_complete']) {
        $completed_courses++;
    }
    $average_progress += $report['progress'];
    
    if ($report['has_grade']) {
        $average_grade += $report['grade_percentage'];
        $courses_with_grades++;
    }
}

if ($total_courses > 0) {
    $average_progress = round($average_progress / $total_courses);
}

if ($courses_with_grades > 0) {
    $average_grade = round($average_grade / $courses_with_grades);
}

// Sort reports by course name
usort($reports, function($a, $b) {
    return strcmp($a['coursename'], $b['coursename']);
});

// Fetch real sidebar data
$sidebar_data = [];

// Get user's enrolled courses for sidebar with REAL images
$sidebar_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');
$sidebar_courses_list = [];
foreach ($sidebar_courses as $course) {
    if ($course->id == SITEID) {
        continue; // Skip site course
    }
    
    // Fetch REAL course image (NO fallback) - Multiple methods
    $sidebar_course_image = '';
    try {
        // Method 1: Try course overview files directly
        $fs = get_file_storage();
        $coursecontext = context_course::instance($course->id);
        $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', false, 'itemid, filepath, filename', false);
        
        foreach ($files as $file) {
            if ($file->is_valid_image()) {
                $sidebar_course_image = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    null,
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                )->out();
                break;
            }
        }
        
        // Method 2: Try core_course_list_element
        if (empty($sidebar_course_image)) {
            $course_full = $DB->get_record('course', ['id' => $course->id]);
            if ($course_full) {
                $course_obj = new core_course_list_element($course_full);
                $course_files = $course_obj->get_course_overviewfiles();
                
                if (!empty($course_files)) {
                    foreach ($course_files as $file) {
                        if ($file->is_valid_image()) {
                            $sidebar_course_image = moodle_url::make_pluginfile_url(
                                $file->get_contextid(),
                                $file->get_component(),
                                $file->get_filearea(),
                                null,
                                $file->get_filepath(),
                                $file->get_filename(),
                                false
                            )->out();
                            break;
                        }
                    }
                }
            }
        }
        
        // Method 3: Check course summary files
        if (empty($sidebar_course_image)) {
            $files = $fs->get_area_files($coursecontext->id, 'course', 'summary', false, 'itemid, filepath, filename', false);
            foreach ($files as $file) {
                $mimetype = $file->get_mimetype();
                if (strpos($mimetype, 'image/') === 0) {
                    $sidebar_course_image = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        null,
                        $file->get_filepath(),
                        $file->get_filename(),
                        false
                    )->out();
                    break;
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("HS MyReports: Error fetching sidebar course image for course {$course->id}: " . $e->getMessage());
    }
    
    $sidebar_courses_list[] = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'url' => new moodle_url('/course/view.php', ['id' => $course->id]),
        'courseimage' => $sidebar_course_image, // REAL course image URL (empty if none)
        'has_image' => !empty($sidebar_course_image)
    ];
}

// Get user's recent activities
$recent_activities = [];
try {
    $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, MAX(ula.timeaccess) as last_access
            FROM {user_lastaccess} ula
            JOIN {course} c ON c.id = ula.courseid
            WHERE ula.userid = ? AND c.visible = 1
            GROUP BY c.id, c.fullname, c.shortname
            ORDER BY last_access DESC
            LIMIT 5";
    $recent_courses = $DB->get_records_sql($sql, [$USER->id]);
    
    foreach ($recent_courses as $course) {
        $recent_activities[] = [
            'name' => $course->fullname,
            'shortname' => $course->shortname,
            'url' => new moodle_url('/course/view.php', ['id' => $course->id]),
            'last_access' => userdate($course->last_access, get_string('strftimerecent'))
        ];
    }
} catch (Exception $e) {
    error_log("HS MyReports: Error fetching recent activities: " . $e->getMessage());
}

// Get user's grade overview
$grade_overview = [];
try {
    $sql = "SELECT c.id, c.fullname, c.shortname, 
                   AVG(gg.finalgrade/gi.grademax*100) as avg_grade
            FROM {course} c
            JOIN {grade_items} gi ON gi.courseid = c.id
            JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ?
            WHERE c.id IN (SELECT DISTINCT courseid FROM {user_enrolments} ue 
                          JOIN {enrol} e ON e.id = ue.enrolid 
                          WHERE ue.userid = ? AND e.status = 0)
            AND gi.itemtype = 'course'
            AND gg.finalgrade IS NOT NULL
            GROUP BY c.id, c.fullname, c.shortname
            ORDER BY avg_grade DESC
            LIMIT 3";
    $top_grades = $DB->get_records_sql($sql, [$USER->id, $USER->id]);
    
    foreach ($top_grades as $course) {
        $grade_overview[] = [
            'name' => $course->fullname,
            'shortname' => $course->shortname,
            'grade' => round($course->avg_grade),
            'url' => new moodle_url('/course/view.php', ['id' => $course->id])
        ];
    }
    
    // Calculate comprehensive dashboard statistics
    $dashboard_stats['average_grade'] = $total_grades > 0 ? round($grade_sum / $total_grades, 1) : 0;
    $dashboard_stats['completion_rate'] = isset($dashboard_stats['total_courses']) && $dashboard_stats['total_courses'] > 0 ? 
        round(($dashboard_stats['completed_courses'] / $dashboard_stats['total_courses']) * 100, 1) : 0;
    $dashboard_stats['assignment_completion_rate'] = isset($dashboard_stats['total_assignments']) && $dashboard_stats['total_assignments'] > 0 ? 
        round((isset($dashboard_stats['completed_assignments']) ? $dashboard_stats['completed_assignments'] : 0) / $dashboard_stats['total_assignments'] * 100, 1) : 0;
    $dashboard_stats['quiz_completion_rate'] = isset($dashboard_stats['total_quizzes']) && $dashboard_stats['total_quizzes'] > 0 ? 
        round((isset($dashboard_stats['completed_quizzes']) ? $dashboard_stats['completed_quizzes'] : 0) / $dashboard_stats['total_quizzes'] * 100, 1) : 0;
    
    // Calculate grade analytics
    if (!empty($all_grades)) {
        $grade_analytics['overall_average'] = round(array_sum($all_grades) / count($all_grades), 1);
        $grade_analytics['highest_grade'] = max($all_grades);
        $grade_analytics['lowest_grade'] = min($all_grades);
        $grade_analytics['grade_range'] = $grade_analytics['highest_grade'] - $grade_analytics['lowest_grade'];
        
        // Grade distribution
        $grade_analytics['grade_distribution'] = [
            'A' => count(array_filter($all_grades, function($g) { return $g >= 90; })),
            'B' => count(array_filter($all_grades, function($g) { return $g >= 80 && $g < 90; })),
            'C' => count(array_filter($all_grades, function($g) { return $g >= 70 && $g < 80; })),
            'D' => count(array_filter($all_grades, function($g) { return $g >= 60 && $g < 70; })),
            'F' => count(array_filter($all_grades, function($g) { return $g < 60; }))
        ];
    }
    
    // Set real data flag
    $has_real_data = !empty($course_performance) || !empty($all_grades);
    
    error_log("HS MyReports: Dashboard stats calculated - Courses: {$dashboard_stats['total_courses']}, " .
              "Assignments: {$dashboard_stats['total_assignments']}, " .
              "Quizzes: {$dashboard_stats['total_quizzes']}, " .
              "Average Grade: {$dashboard_stats['average_grade']}");
    
} catch (Exception $e) {
    error_log("HS MyReports: Error fetching grade overview: " . $e->getMessage());
}

// Fetch comprehensive grade reports with complete error handling
try {
    error_log("HS MyReports: Starting comprehensive grade reports collection");
    
    // Initialize grade reports arrays
    $grade_reports = [
        'overall_gradebook' => [],
        'course_grades' => [],
        'assignment_grades' => [],
        'quiz_grades' => [],
        'activity_grades' => [],
        'grade_statistics' => [],
        'grade_distribution' => [],
        'grade_categories' => [],
        'grade_timeline' => []
    ];
    
    // Get all user's courses for grade reports with error handling
    try {
        $user_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');
        if (!$user_courses) {
            $user_courses = [];
        }
    } catch (Exception $e) {
        error_log("HS MyReports: Error fetching user courses: " . $e->getMessage());
        $user_courses = [];
    }
    
    // Process each course with comprehensive error handling
    foreach ($user_courses as $course) {
        if ($course->id == SITEID) continue;
        
        try {
            $coursecontext = context_course::instance($course->id);
            
            // Get grade items for this course with comprehensive error handling
            $grade_items = [];
            // Use direct database query to avoid array issues
            $sql = "SELECT * FROM {grade_items} WHERE courseid = ? AND itemtype = 'mod'";
            $grade_items_records = $DB->get_records_sql($sql, [(int)$course->id]);
            
            if ($grade_items_records) {
                foreach ($grade_items_records as $record) {
                    $grade_items[] = $record;
                }
            }
        
            $course_grade_data = [
                'course_id' => (int)$course->id,
                'course_name' => (string)$course->fullname,
                'course_shortname' => (string)$course->shortname,
                'grade_items' => [],
                'total_grade' => 0,
                'grade_percentage' => 0,
                'grade_letter' => '',
                'grade_feedback' => '',
                'last_updated' => 0
            ];
            
            foreach ($grade_items as $grade_item) {
                try {
                    // Get the grade for this item using direct database query
                    $grade = null;
                    $sql = "SELECT * FROM {grade_grades} WHERE itemid = ? AND userid = ?";
                    $grade_records = $DB->get_records_sql($sql, [(int)$grade_item->id, (int)$USER->id]);
                    
                    if ($grade_records) {
                        $grade = reset($grade_records); // Get first record
                    }
                } catch (Exception $e) {
                    error_log("HS MyReports: Error fetching grade for item {$grade_item->id}: " . $e->getMessage());
                    continue;
                }
                
                if ($grade && $grade->finalgrade !== null) {
                    try {
                        // Ensure all values are properly typed to prevent database errors
                        $item_data = [
                            'id' => (int)$grade_item->id,
                            'itemname' => (string)$grade_item->itemname,
                            'itemtype' => (string)$grade_item->itemtype,
                            'itemmodule' => (string)$grade_item->itemmodule,
                            'iteminstance' => (int)$grade_item->iteminstance,
                            'grademax' => (float)$grade_item->grademax,
                            'grademin' => (float)$grade_item->grademin,
                            'finalgrade' => (float)$grade->finalgrade,
                            'rawgrade' => (float)$grade->rawgrade,
                            'feedback' => (string)$grade->feedback,
                            'feedbackformat' => (int)$grade->feedbackformat,
                            'timecreated' => (int)$grade->timecreated,
                            'timemodified' => (int)$grade->timemodified,
                            'percentage' => round(($grade->finalgrade / $grade_item->grademax) * 100, 1),
                            'letter_grade' => '', 
                            'category' => (int)$grade_item->categoryid,
                            'weight' => (float)$grade_item->aggregationcoef2
                        ];
                        
                        // Calculate letter grade based on percentage
                        $perc = $item_data['percentage'];
                        if ($perc >= 90) $item_data['letter_grade'] = 'A';
                        elseif ($perc >= 80) $item_data['letter_grade'] = 'B';
                        elseif ($perc >= 70) $item_data['letter_grade'] = 'C';
                        elseif ($perc >= 60) $item_data['letter_grade'] = 'D';
                        else $item_data['letter_grade'] = 'F';
                    } catch (Exception $e) {
                        error_log("HS MyReports: Error processing grade item {$grade_item->id}: " . $e->getMessage());
                        continue;
                    }
                    
                    $course_grade_data['grade_items'][] = $item_data;
                    
                    // Add to overall grade calculation
                    if ($grade_item->itemtype == 'course') {
                        $course_grade_data['total_grade'] = $grade->finalgrade;
                        $course_grade_data['grade_percentage'] = $item_data['percentage'];
                        $course_grade_data['grade_letter'] = $item_data['letter_grade'];
                        $course_grade_data['last_updated'] = $grade->timemodified;
                    }
                }
            }
            
            // Categorize grades by type
            foreach ($course_grade_data['grade_items'] as $item) {
                if ($item['itemmodule'] == 'assign') {
                    $grade_reports['assignment_grades'][] = $item;
                } elseif ($item['itemmodule'] == 'quiz') {
                    $grade_reports['quiz_grades'][] = $item;
                } else {
                    $grade_reports['activity_grades'][] = $item;
                }
            }
        
            $grade_reports['course_grades'][] = $course_grade_data;
            $grade_reports['overall_gradebook'][] = $course_grade_data;
        } catch (Exception $e) {
            error_log("HS MyReports: Error processing course {$course->id}: " . $e->getMessage());
            continue;
        }
    }
    
    // Get grade categories using direct database query to avoid array issues
    $grade_reports['grade_categories'] = [];
    try {
        $course_ids = array_keys($user_courses);
        if (!empty($course_ids)) {
            $placeholders = str_repeat('?,', count($course_ids) - 1) . '?';
            $sql = "SELECT * FROM {grade_categories} WHERE courseid IN ($placeholders)";
            $grade_categories = $DB->get_records_sql($sql, $course_ids);
            
            if ($grade_categories) {
                foreach ($grade_categories as $category) {
                    $grade_reports['grade_categories'][] = [
                        'id' => (int)$category->id,
                        'courseid' => (int)$category->courseid,
                        'fullname' => (string)$category->fullname,
                        'aggregation' => (int)$category->aggregation,
                        'keephigh' => (int)$category->keephigh,
                        'droplow' => (int)$category->droplow,
                        'aggregateonlygraded' => (int)$category->aggregateonlygraded,
                        'aggregatesubcats' => isset($category->aggregatesubcats) ? (int)$category->aggregatesubcats : 0,
                        'timecreated' => (int)$category->timecreated,
                        'timemodified' => (int)$category->timemodified
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("HS MyReports: Error fetching grade categories: " . $e->getMessage());
        $grade_reports['grade_categories'] = [];
    }
    
    // Calculate grade statistics with proper validation
    $all_final_grades = [];
    if (!empty($grade_reports['overall_gradebook'])) {
        foreach ($grade_reports['overall_gradebook'] as $course_grade) {
            if (isset($course_grade['grade_items']) && is_array($course_grade['grade_items'])) {
                foreach ($course_grade['grade_items'] as $item) {
                    if (isset($item['finalgrade']) && is_numeric($item['finalgrade']) && $item['finalgrade'] !== null) {
                        $all_final_grades[] = (float)$item['finalgrade'];
                    }
                }
            }
        }
    }
    
    if (!empty($all_final_grades)) {
        $grade_reports['grade_statistics'] = [
            'total_grades' => count($all_final_grades),
            'average_grade' => round(array_sum($all_final_grades) / count($all_final_grades), 2),
            'highest_grade' => max($all_final_grades),
            'lowest_grade' => min($all_final_grades),
            'grade_range' => max($all_final_grades) - min($all_final_grades),
            'grade_std_dev' => round(sqrt(array_sum(array_map(function($x) use ($all_final_grades) { 
                return pow($x - array_sum($all_final_grades) / count($all_final_grades), 2); 
            }, $all_final_grades)) / count($all_final_grades)), 2)
        ];
        
        // Grade distribution
        $grade_reports['grade_distribution'] = [
            'A' => count(array_filter($all_final_grades, function($g) { return $g >= 90; })),
            'B' => count(array_filter($all_final_grades, function($g) { return $g >= 80 && $g < 90; })),
            'C' => count(array_filter($all_final_grades, function($g) { return $g >= 70 && $g < 80; })),
            'D' => count(array_filter($all_final_grades, function($g) { return $g >= 60 && $g < 70; })),
            'F' => count(array_filter($all_final_grades, function($g) { return $g < 60; }))
        ];
    }
    
    // Generate grade timeline (last 30 days) with proper validation
    $grade_reports['grade_timeline'] = [];
    try {
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $day_grades = [];
            
            if (!empty($grade_reports['overall_gradebook'])) {
                foreach ($grade_reports['overall_gradebook'] as $course_grade) {
                    if (isset($course_grade['grade_items']) && is_array($course_grade['grade_items'])) {
                        foreach ($course_grade['grade_items'] as $item) {
                            if (isset($item['timemodified']) && is_numeric($item['timemodified'])) {
                                if (date('Y-m-d', (int)$item['timemodified']) == $date) {
                                    $day_grades[] = $item;
                                }
                            }
                        }
                    }
                }
            }
            
            $average_grade = 0;
            if (!empty($day_grades)) {
                $final_grades = array_filter(array_column($day_grades, 'finalgrade'), function($grade) {
                    return is_numeric($grade) && $grade !== null;
                });
                if (!empty($final_grades)) {
                    $average_grade = round(array_sum($final_grades) / count($final_grades), 1);
                }
            }
            
            $grade_reports['grade_timeline'][] = [
                'date' => $date,
                'grades_count' => count($day_grades),
                'average_grade' => $average_grade,
                'grades' => $day_grades
            ];
        }
    } catch (Exception $e) {
        error_log("HS MyReports: Error generating grade timeline: " . $e->getMessage());
        $grade_reports['grade_timeline'] = [];
    }
    
    error_log("HS MyReports: Grade reports collected - " . count($grade_reports['overall_gradebook']) . " courses, " . 
              count($grade_reports['assignment_grades']) . " assignments, " . 
              count($grade_reports['quiz_grades']) . " quizzes");
              
} catch (Exception $e) {
    error_log("HS MyReports: Critical error in grade reports collection: " . $e->getMessage());
    // Initialize empty grade reports to prevent template errors
    $grade_reports = [
        'overall_gradebook' => [],
        'course_grades' => [],
        'assignment_grades' => [],
        'quiz_grades' => [],
        'activity_grades' => [],
        'grade_statistics' => [],
        'grade_distribution' => [],
        'grade_categories' => [],
        'grade_timeline' => []
    ];
    
} catch (Exception $e) {
    error_log("HS MyReports: Error fetching grade reports: " . $e->getMessage());
}

// ============================================================================
// FETCH REAL DATA FOR ALL SECTIONS
// ============================================================================

// Fetch Real Competency Data from Moodle - TREE VIEW with hierarchy
$competency_data = [];
$competency_frameworks = [];
$competency_tree = [];
$has_competencies = false;

// Check if competency tables exist
$has_competency_tables = $DB->get_manager()->table_exists('competency') &&
                        $DB->get_manager()->table_exists('competency_usercomp') &&
                        $DB->get_manager()->table_exists('competency_framework');

if ($has_competency_tables) {
    try {
        // Get ALL competencies with framework info, parent relationships, and paths for TREE VIEW
        $sql = "SELECT DISTINCT c.id, c.shortname, c.idnumber, c.description, c.path,
                       c.parentid, c.sortorder,
                       cf.id as framework_id, cf.shortname as framework_name,
                       ucc.proficiency, ucc.grade, ucc.courseid, ucc.timemodified as date_assessed,
                       co.fullname as coursename,
                       s.name as scale_name, s.scale as scale_items
                FROM {competency} c
                JOIN {competency_coursecomp} ccc ON c.id = ccc.competencyid
                JOIN {competency_framework} cf ON c.competencyframeworkid = cf.id
                LEFT JOIN {competency_usercompcourse} ucc ON c.id = ucc.competencyid 
                    AND ucc.userid = ? AND ucc.courseid = ccc.courseid
                LEFT JOIN {course} co ON ccc.courseid = co.id
                LEFT JOIN {scale} s ON c.scaleid = s.id
                WHERE ccc.courseid IN (
                    SELECT DISTINCT courseid 
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE ue.userid = ? AND e.status = 0
                )
                ORDER BY cf.shortname, c.path, c.sortorder
                LIMIT 100";
        
        $competencies = $DB->get_records_sql($sql, [$USER->id, $USER->id]);
        
        if (empty($competencies)) {
            // Fallback: Get user's assessed competencies only
            $sql = "SELECT c.id, c.shortname, c.idnumber, c.description, c.path,
                           c.parentid, c.sortorder,
                           cf.id as framework_id, cf.shortname as framework_name,
                           uc.proficiency, uc.grade, uc.timemodified as date_assessed,
                           s.name as scale_name, s.scale as scale_items
                    FROM {competency_usercomp} uc
                    JOIN {competency} c ON c.id = uc.competencyid
                    JOIN {competency_framework} cf ON c.competencyframeworkid = cf.id
                    LEFT JOIN {scale} s ON c.scaleid = s.id
                    WHERE uc.userid = ?
                    ORDER BY cf.shortname, c.path, c.sortorder
                    LIMIT 50";
            $competencies = $DB->get_records_sql($sql, [$USER->id]);
        }
        
        // Build tree structure and group by framework
        $frameworks_map = [];
        $comp_by_id = [];
        
        foreach ($competencies as $comp) {
            $framework_name = $comp->framework_name ?? 'General Framework';
            $framework_id = $comp->framework_id ?? 0;
            
            if (!isset($frameworks_map[$framework_id])) {
                $frameworks_map[$framework_id] = [
                    'id' => $framework_id,
                    'name' => $framework_name,
                    'competencies' => [],
                    'tree' => []
                ];
            }
            
            // Determine scale max (from scale items or default to 4)
            $scale_max = 4;
            if (!empty($comp->scale_items)) {
                $scale_items_array = explode(',', $comp->scale_items);
                $scale_max = count($scale_items_array);
            }
            
            // Convert grade to percentage
            $grade_value = $comp->grade ?? 0;
            $percentage = $grade_value > 0 ? round(($grade_value / $scale_max) * 100) : 0;
            
            // Determine assessment status
            $status = 'Not Yet Assessed';
            $status_color = '#94a3b8';
            if ($comp->proficiency == 1) {
                $status = 'Proficient';
                $status_color = '#10b981';
            } elseif ($grade_value > 0) {
                $status = 'In Progress';
                $status_color = '#f59e0b';
            }
            
            // Calculate depth level from path
            $path_parts = explode('/', trim($comp->path ?? '', '/'));
            $depth = count($path_parts) - 1; // 0 = root, 1 = child, 2 = grandchild, etc.
            
            $competency_entry = [
                'id' => $comp->id,
                'name' => $comp->shortname,
                'idnumber' => $comp->idnumber ?? '',
                'description' => strip_tags($comp->description ?? ''),
                'proficiency' => $comp->proficiency ?? 0,
                'grade' => $grade_value,
                'percentage' => max(0, min(100, $percentage)),
                'course' => $comp->coursename ?? '',
                'framework_name' => $framework_name,
                'framework_id' => $framework_id,
                'is_proficient' => ($comp->proficiency == 1),
                'parentid' => $comp->parentid ?? 0,
                'path' => $comp->path ?? '',
                'depth' => $depth,
                'status' => $status,
                'status_color' => $status_color,
                'scale_name' => $comp->scale_name ?? 'Proficiency Scale',
                'date_assessed' => $comp->date_assessed ? date('M d, Y', $comp->date_assessed) : 'Pending',
                'children' => []
            ];
            
            $frameworks_map[$framework_id]['competencies'][] = $competency_entry;
            $competency_data[] = $competency_entry;
            $comp_by_id[$comp->id] = &$competency_entry;
        }
        
        // Build tree structure for each framework
        foreach ($frameworks_map as $fid => &$framework) {
            $tree_items = [];
            
            // First pass: organize by parent-child relationships
            foreach ($framework['competencies'] as &$comp) {
                if (empty($comp['parentid']) || $comp['parentid'] == 0) {
                    // Root level competency
                    $tree_items[$comp['id']] = $comp;
                }
            }
            
            // Second pass: attach children to parents
            foreach ($framework['competencies'] as &$comp) {
                if (!empty($comp['parentid']) && $comp['parentid'] != 0) {
                    // Find parent and add as child
                    $parent_found = false;
                    foreach ($tree_items as &$root) {
                        if ($root['id'] == $comp['parentid']) {
                            $root['children'][] = $comp;
                            $parent_found = true;
                            break;
                        } else {
                            // Check if parent is in children
                            foreach ($root['children'] as &$child) {
                                if ($child['id'] == $comp['parentid']) {
                                    $child['children'][] = $comp;
                                    $parent_found = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    
                    // If no parent found, treat as root
                    if (!$parent_found) {
                        $tree_items[$comp['id']] = $comp;
                    }
                }
            }
            
            $framework['tree'] = array_values($tree_items);
        }
        
        $competency_frameworks = array_values($frameworks_map);
        $has_competencies = !empty($competency_data);
        
        error_log("HS MyReports: Found " . count($competency_data) . " competencies across " . count($competency_frameworks) . " frameworks");
        
    } catch (Exception $e) {
        error_log("HS MyReports: Competency data error: " . $e->getMessage());
        error_log("HS MyReports: SQL Error: " . $e->getTraceAsString());
    }
} else {
    error_log("HS MyReports: Competency tables not found in database");
}

// Fetch Comprehensive Rubric Assessment Data
$rubric_data = [];
$rubric_summary = [];
$rubric_by_assignment = [];
$has_rubrics = false;
$rubric_records = [];

try {
    // Get all rubric assessments for the user
    // Note: grading_instances.userid is the GRADER, not the student
    // We need to find grading instances via assign_grades where the student is the user
    $sql = "SELECT grf.id as filling_id,
                   grf.criterionid, grf.levelid, grf.remark,
                   grc.description as criterion_name,
                   grc.sortorder as criterion_order,
                   grl.definition as level_name, 
                   grl.score,
                   gi.id as instance_id,
                   gi.rawgrade,
                   gi.timemodified,
                   gd.name as rubric_name,
                   c.fullname as course_name,
                   cm.id as cmid,
                   m.name as module_name,
                   COALESCE(a.name, q.name, 'Activity') as assignment_name,
                   ag.id as grade_id,
                   ag.grade as final_grade,
                   ag.timemodified as grade_timemodified
            FROM {assign_grades} ag
            JOIN {assign} a ON a.id = ag.assignment
            JOIN {course} c ON c.id = a.course
            JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
            JOIN {modules} m ON m.id = cm.module
            LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = 70
            JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign' AND ga.areaname = 'submissions'
            JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric'
            JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = ag.id
            JOIN {gradingform_rubric_fillings} grf ON grf.instanceid = gi.id
            JOIN {gradingform_rubric_criteria} grc ON grc.id = grf.criterionid
            JOIN {gradingform_rubric_levels} grl ON grl.id = grf.levelid
            WHERE ag.userid = ?
            ORDER BY ag.timemodified DESC, grc.sortorder ASC
            LIMIT 100";
    
    $rubric_records = $DB->get_records_sql($sql, [$USER->id]);
    
    // If no records found, try alternative query (via grade_items and grade_grades)
    if (empty($rubric_records)) {
        error_log("HS MyReports: First rubric query returned no results, trying alternative...");
        
        $sql2 = "SELECT grf.id as filling_id,
                       grf.criterionid, grf.levelid, grf.remark,
                       grc.description as criterion_name,
                       grc.sortorder as criterion_order,
                       grl.definition as level_name, 
                       grl.score,
                       gi.id as instance_id,
                       gi.rawgrade,
                       gi.timemodified,
                       gd.name as rubric_name,
                       cm.id as cmid,
                       m.name as module_name,
                       COALESCE(a.name, 'Assignment') as assignment_name,
                       c.fullname as course_name,
                       gg.id as grade_id,
                       gg.finalgrade as final_grade
                FROM {grade_grades} gg
                JOIN {grade_items} gi_item ON gi_item.id = gg.itemid
                JOIN {course_modules} cm ON cm.id = gi_item.iteminstance AND gi_item.itemmodule = 'assign'
                JOIN {course} c ON c.id = cm.course
                JOIN {modules} m ON m.id = cm.module
                LEFT JOIN {assign} a ON a.id = cm.instance
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = 70
                JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
                JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric'
                JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = gg.id
                JOIN {gradingform_rubric_fillings} grf ON grf.instanceid = gi.id
                JOIN {gradingform_rubric_criteria} grc ON grc.id = grf.criterionid
                JOIN {gradingform_rubric_levels} grl ON grl.id = grf.levelid
                WHERE gg.userid = ? AND gg.finalgrade IS NOT NULL
                ORDER BY gg.timemodified DESC, grc.sortorder ASC
                LIMIT 100";
        
        $rubric_records = $DB->get_records_sql($sql2, [$USER->id]);
    }
    
    // If still no records, try codeeditor assignments
    if (empty($rubric_records) && $DB->get_manager()->table_exists('codeeditor_submissions')) {
        error_log("HS MyReports: Trying codeeditor rubric query...");
        
        $sql3 = "SELECT grf.id as filling_id,
                       grf.criterionid, grf.levelid, grf.remark,
                       grc.description as criterion_name,
                       grc.sortorder as criterion_order,
                       grl.definition as level_name, 
                       grl.score,
                       gi.id as instance_id,
                       gi.rawgrade,
                       gi.timemodified,
                       gd.name as rubric_name,
                       cm.id as cmid,
                       m.name as module_name,
                       ce.name as assignment_name,
                       c.fullname as course_name,
                       cs.id as grade_id,
                       cs.grade as final_grade,
                       cs.timemodified as grade_timemodified
                FROM {codeeditor_submissions} cs
                JOIN {codeeditor} ce ON ce.id = cs.codeeditorid
                JOIN {course} c ON c.id = ce.course
                JOIN {course_modules} cm ON cm.instance = ce.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'codeeditor')
                JOIN {modules} m ON m.id = cm.module
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = 70
                JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_codeeditor' AND ga.areaname = 'submissions'
                JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric'
                JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = cs.id
                JOIN {gradingform_rubric_fillings} grf ON grf.instanceid = gi.id
                JOIN {gradingform_rubric_criteria} grc ON grc.id = grf.criterionid
                JOIN {gradingform_rubric_levels} grl ON grl.id = grf.levelid
                WHERE cs.userid = ? AND cs.grade IS NOT NULL AND cs.latest = 1
                ORDER BY cs.timemodified DESC, grc.sortorder ASC
                LIMIT 100";
        
        $rubric_records = $DB->get_records_sql($sql3, [$USER->id]);
    }
    
    // Log results
    if (empty($rubric_records)) {
        error_log("HS MyReports: No rubric assessments found for user " . $USER->id);
        
        // Check if rubric system exists at all
        $has_rubrics_in_system = $DB->record_exists('gradingform_rubric_fillings', []);
        if ($has_rubrics_in_system) {
            error_log("HS MyReports: Rubric system exists but no assessments for this user");
            
            // Debug: Check if user has any grades
            $user_grades_count = $DB->count_records('assign_grades', ['userid' => $USER->id]);
            error_log("HS MyReports: User has {$user_grades_count} assignment grades");
            
            // Debug: Check if any assignments use rubrics
            $rubric_assignments = $DB->get_records_sql(
                "SELECT COUNT(DISTINCT cm.id) as count
                 FROM {course_modules} cm
                 JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = 70
                 JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
                 JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric'
                 WHERE cm.course IN (SELECT DISTINCT courseid FROM {user_enrolments} ue 
                                     JOIN {enrol} e ON e.id = ue.enrolid 
                                     WHERE ue.userid = ? AND e.status = 0)",
                [$USER->id]
            );
            $rubric_count = reset($rubric_assignments)->count ?? 0;
            error_log("HS MyReports: Found {$rubric_count} assignments with rubrics in user's courses");
        } else {
            error_log("HS MyReports: No rubrics found in entire system");
        }
    } else {
        error_log("HS MyReports: Found " . count($rubric_records) . " rubric records for user " . $USER->id);
    }
    
    // Process rubric data
    $criteria_scores = [];
    $assignment_groups = [];
    $rubric_data_groups = [];
    
    foreach ($rubric_records as $record) {
        // Use grade timestamp if available, otherwise use instance timestamp
        $grade_timestamp = isset($record->grade_timemodified) && $record->grade_timemodified > 0 
            ? $record->grade_timemodified 
            : $record->timemodified;
        $assignment_key = $record->cmid ?? $record->instance_id;
        
        // Ensure we only keep the latest grading attempt per assignment
        if (!isset($assignment_groups[$assignment_key]) || $grade_timestamp > $assignment_groups[$assignment_key]['timestamp']) {
            $assignment_groups[$assignment_key] = [
                'assignment_name' => $record->assignment_name ?? $record->module_name ?? 'Activity',
                'course_name' => $record->course_name ?? 'Unknown Course',
                'rubric_name' => $record->rubric_name,
                'date' => date('M d, Y', $grade_timestamp),
                'criteria' => [],
                'total_score' => 0,
                'max_score' => 0,
                'cmid' => $record->cmid ?? null,
                'instance_id' => $record->instance_id,
                'timestamp' => $grade_timestamp
            ];
        } elseif ($grade_timestamp < $assignment_groups[$assignment_key]['timestamp']) {
            // Older grading record for this assignment; skip it
            continue;
        }
        
        if (!isset($rubric_data_groups[$assignment_key]) || $grade_timestamp > $rubric_data_groups[$assignment_key]['timestamp']) {
            $rubric_data_groups[$assignment_key] = [
                'timestamp' => $grade_timestamp,
                'entries' => []
            ];
        } elseif ($grade_timestamp < $rubric_data_groups[$assignment_key]['timestamp']) {
            // Older grading record; ignore
            continue;
        }
        
        // Individual rubric filling (only latest per assignment)
        $rubric_data_groups[$assignment_key]['entries'][] = [
            'id' => $record->filling_id,
            'criterion' => strip_tags($record->criterion_name),
            'level' => strip_tags($record->level_name),
            'score' => $record->score,
            'course' => $record->course_name ?? '',
            'assignment' => $record->assignment_name ?? $record->module_name ?? 'Activity',
            'rubric_name' => $record->rubric_name,
            'remark' => strip_tags($record->remark ?? ''),
            'date' => date('M d, Y', $grade_timestamp)
        ];

        // Add criterion to assignment
        $assignment_groups[$assignment_key]['criteria'][] = [
            'name' => strip_tags($record->criterion_name),
            'level' => strip_tags($record->level_name),
            'score' => $record->score,
            'remark' => strip_tags($record->remark ?? '')
        ];
        
        $assignment_groups[$assignment_key]['total_score'] += $record->score;
        
        // Track criterion performance for summary
        $criterion_clean = substr(strip_tags($record->criterion_name), 0, 30);
        if (!isset($criteria_scores[$criterion_clean])) {
            $criteria_scores[$criterion_clean] = [
                'total' => 0,
                'count' => 0,
                'scores' => []
            ];
        }
        $criteria_scores[$criterion_clean]['total'] += $record->score;
        $criteria_scores[$criterion_clean]['count']++;
        $criteria_scores[$criterion_clean]['scores'][] = $record->score;
    }
    
    // Calculate max possible scores and percentages
    if (!empty($assignment_groups)) {
        // Get all unique criterion IDs from the records
        $criterion_ids = array_unique(array_column((array)$rubric_records, 'criterionid'));
        $max_scores_per_criterion = [];
        
        foreach ($criterion_ids as $crit_id) {
            $max_sql = "SELECT MAX(score) as max_score
                       FROM {gradingform_rubric_levels}
                       WHERE criterionid = ?";
            $max_result = $DB->get_record_sql($max_sql, [$crit_id]);
            if ($max_result) {
                $max_scores_per_criterion[$crit_id] = $max_result->max_score;
            }
        }
        
        foreach ($assignment_groups as $key => $assignment) {
            // Calculate max score for this assignment based on its criteria count
            $criteria_count = count($assignment['criteria']);
            $avg_max = !empty($max_scores_per_criterion) ? 
                      array_sum($max_scores_per_criterion) / count($max_scores_per_criterion) : 5;
            $assignment_groups[$key]['max_score'] = $avg_max * $criteria_count;
            
            // Calculate percentage
            if ($assignment_groups[$key]['max_score'] > 0) {
                $assignment_groups[$key]['percentage'] = round(
                    ($assignment_groups[$key]['total_score'] / $assignment_groups[$key]['max_score']) * 100
                );
            } else {
                $assignment_groups[$key]['percentage'] = 0;
            }
        }
    }
    
    $rubric_by_assignment = array_values($assignment_groups);
    
    // Flatten rubric data groups (latest attempt only)
    $rubric_data = [];
    foreach ($rubric_data_groups as $group) {
        foreach ($group['entries'] as $entry) {
            $rubric_data[] = $entry;
        }
    }
    
    // Create summary statistics
    foreach ($criteria_scores as $criterion => $data) {
        $average = $data['count'] > 0 ? round($data['total'] / $data['count'], 1) : 0;
        $rubric_summary[] = [
            'criterion' => $criterion,
            'average_score' => $average,
            'count' => $data['count'],
            'min_score' => !empty($data['scores']) ? min($data['scores']) : 0,
            'max_score' => !empty($data['scores']) ? max($data['scores']) : 0
        ];
    }
    
    // Sort summary by average score descending
    usort($rubric_summary, function($a, $b) {
        return $b['average_score'] <=> $a['average_score'];
    });
    
    $has_rubrics = !empty($rubric_data);
    error_log("HS MyReports: Found " . count($rubric_data) . " rubric assessments across " . 
              count($rubric_by_assignment) . " assignments");
    
    // Additional diagnostics
    $rubric_diagnostics = [
        'table_grading_instances' => $DB->count_records('grading_instances', ['userid' => $USER->id]),
        'table_rubric_fillings' => $DB->count_records('gradingform_rubric_fillings', []),
        'table_rubric_criteria' => $DB->count_records('gradingform_rubric_criteria', []),
        'table_grading_definitions' => $DB->count_records('grading_definitions', [])
    ];
    error_log("HS MyReports: Rubric diagnostics - " . json_encode($rubric_diagnostics));
              
} catch (Exception $e) {
    error_log("HS MyReports: Rubric data error: " . $e->getMessage());
    error_log("HS MyReports: Rubric trace: " . $e->getTraceAsString());
    $rubric_diagnostics = ['error' => $e->getMessage()];
}

// Fetch Comprehensive Attendance Data - Daily, Weekly, Monthly
$attendance_daily = [];
$attendance_weekly = [];
$attendance_monthly = [];
$attendance_stats = [
    'rate' => 0,
    'present' => 0,
    'absent' => 0,
    'total' => 0
];

try {
    // ========== DAILY ATTENDANCE (Last 30 days) ==========
    $sql = "SELECT DATE(FROM_UNIXTIME(timecreated)) as attendance_date, 
                   COUNT(DISTINCT courseid) as courses_accessed,
                   COUNT(*) as total_actions,
                   MIN(timecreated) as first_login,
                   MAX(timecreated) as last_action
            FROM {logstore_standard_log}
            WHERE userid = ? 
            AND action = 'viewed'
            AND target = 'course'
            AND timecreated > ?
            GROUP BY DATE(FROM_UNIXTIME(timecreated))
            ORDER BY attendance_date DESC";
    
    $thirty_days_ago = time() - (30 * 24 * 60 * 60);
    $daily_records = $DB->get_records_sql($sql, [$USER->id, $thirty_days_ago]);
    
    foreach ($daily_records as $record) {
        $attendance_daily[] = [
            'date' => $record->attendance_date,
            'date_formatted' => date('M d, Y', strtotime($record->attendance_date)),
            'day_name' => date('l', strtotime($record->attendance_date)),
            'courses_accessed' => $record->courses_accessed,
            'total_actions' => $record->total_actions,
            'present' => true,
            'percentage' => 100,
            'first_login' => date('H:i', $record->first_login),
            'last_action' => date('H:i', $record->last_action)
        ];
    }
    
    // ========== HOURLY ATTENDANCE (Today's activity by hour) ==========
    $attendance_hourly = [];
    $sql = "SELECT HOUR(FROM_UNIXTIME(timecreated)) as hour,
                   COUNT(*) as activity_count,
                   COUNT(DISTINCT courseid) as courses
            FROM {logstore_standard_log}
            WHERE userid = ?
            AND action = 'viewed'
            AND timecreated >= ?
            AND timecreated <= ?
            GROUP BY HOUR(FROM_UNIXTIME(timecreated))
            ORDER BY hour";
    
    $today_start = strtotime('today midnight');
    $today_end = strtotime('tomorrow midnight') - 1;
    $hourly_records = $DB->get_records_sql($sql, [$USER->id, $today_start, $today_end]);
    
    // Initialize all 24 hours
    for ($h = 0; $h < 24; $h++) {
        $attendance_hourly[$h] = [
            'hour' => $h,
            'hour_label' => sprintf('%02d:00', $h),
            'activity_count' => 0,
            'courses' => 0
        ];
    }
    
    // Fill in actual data
    foreach ($hourly_records as $record) {
        $attendance_hourly[$record->hour] = [
            'hour' => $record->hour,
            'hour_label' => sprintf('%02d:00', $record->hour),
            'activity_count' => $record->activity_count,
            'courses' => $record->courses
        ];
    }
    
    // Calculate daily stats
    $attendance_stats['present'] = count($daily_records);
    $attendance_stats['total'] = 30;
    $attendance_stats['absent'] = $attendance_stats['total'] - $attendance_stats['present'];
    $attendance_stats['rate'] = $attendance_stats['total'] > 0 ? 
        round(($attendance_stats['present'] / $attendance_stats['total']) * 100) : 0;
    
    // ========== WEEKLY ATTENDANCE (Last 7 days - Day by Day) ==========
    // Get attendance for each day of the last 7 days
    $seven_days_ago = time() - (7 * 24 * 60 * 60);
    $sql = "SELECT DATE(FROM_UNIXTIME(timecreated)) as attendance_date,
                   DAYNAME(FROM_UNIXTIME(timecreated)) as day_name,
                   COUNT(DISTINCT courseid) as courses_accessed,
                   COUNT(*) as total_actions,
                   MIN(timecreated) as first_login,
                   MAX(timecreated) as last_action
            FROM {logstore_standard_log}
            WHERE userid = ? 
            AND action = 'viewed'
            AND target = 'course'
            AND timecreated > ?
            GROUP BY DATE(FROM_UNIXTIME(timecreated)), DAYNAME(FROM_UNIXTIME(timecreated))
            ORDER BY attendance_date DESC
            LIMIT 7";
    
    $weekly_records = $DB->get_records_sql($sql, [$USER->id, $seven_days_ago]);
    
    // Initialize all 7 days
    $day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $weekly_temp = [];
    
    foreach ($weekly_records as $record) {
        $weekly_temp[$record->day_name] = [
            'date' => $record->attendance_date,
            'date_formatted' => date('M d', strtotime($record->attendance_date)),
            'day_name' => $record->day_name,
            'courses_accessed' => $record->courses_accessed,
            'total_actions' => $record->total_actions,
            'present' => true,
            'percentage' => 100,
            'first_login' => date('H:i', $record->first_login),
            'last_action' => date('H:i', $record->last_action)
        ];
    }
    
    // Build final array with all days (fill missing days with 0)
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $day_name = date('l', strtotime($date));
        
        if (isset($weekly_temp[$day_name]) && $weekly_temp[$day_name]['date'] == $date) {
            $attendance_weekly[] = $weekly_temp[$day_name];
        } else {
            $attendance_weekly[] = [
                'date' => $date,
                'date_formatted' => date('M d', strtotime($date)),
                'day_name' => $day_name,
                'courses_accessed' => 0,
                'total_actions' => 0,
                'present' => false,
                'percentage' => 0,
                'first_login' => '--',
                'last_action' => '--'
            ];
        }
    }
    
    // ========== MONTHLY ATTENDANCE (Last 6 months) ==========
    $sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y-%m') as month,
                   COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated))) as days_present,
                   COUNT(DISTINCT courseid) as courses_accessed,
                   COUNT(*) as total_actions
            FROM {logstore_standard_log}
            WHERE userid = ? 
            AND action = 'viewed'
            AND target = 'course'
            AND timecreated > ?
            GROUP BY DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y-%m')
            ORDER BY month DESC
            LIMIT 6";
    
    $six_months_ago = time() - (6 * 30 * 24 * 60 * 60);
    $monthly_records = $DB->get_records_sql($sql, [$USER->id, $six_months_ago]);
    
    foreach ($monthly_records as $record) {
        $month_name = date('F Y', strtotime($record->month . '-01'));
        $days_in_month = date('t', strtotime($record->month . '-01'));
        $school_days = round($days_in_month * 0.7); // Approximate school days
        
        $attendance_monthly[] = [
            'month' => $record->month,
            'month_name' => $month_name,
            'days_present' => $record->days_present,
            'days_total' => $school_days,
            'percentage' => round(($record->days_present / $school_days) * 100),
            'courses_accessed' => $record->courses_accessed,
            'total_actions' => $record->total_actions
        ];
    }
    
    error_log("HS MyReports: Attendance - Daily: " . count($attendance_daily) . ", Weekly: " . count($attendance_weekly) . ", Monthly: " . count($attendance_monthly));
    
} catch (Exception $e) {
    error_log("HS MyReports: Attendance data error: " . $e->getMessage());
}

// ============================================================================
// FETCH REAL LEARNING INSIGHTS DATA
// ============================================================================

$learning_insights = [
    'strengths' => [],
    'improvements' => [],
    'recommendations' => []
];

try {
    // Analyze strengths based on real performance
    $strengths = [];
    
    // 1. Check attendance rate
    if ($attendance_stats['rate'] >= 90) {
        $strengths[] = "Excellent attendance: {$attendance_stats['rate']}% demonstrates strong commitment";
    } elseif ($attendance_stats['rate'] >= 75) {
        $strengths[] = "Good attendance: {$attendance_stats['rate']}% participation rate";
    }
    
    // 2. Check grade performance
    if ($average_grade >= 85) {
        $strengths[] = "Outstanding academic performance: {$average_grade}% average";
    } elseif ($average_grade >= 75) {
        $strengths[] = "Strong academic performance: {$average_grade}% average";
    } elseif ($average_grade >= 60) {
        $strengths[] = "Satisfactory performance: {$average_grade}% average";
    }
    
    // 3. Check assignment completion
    $total_assignments_count = 0;
    $completed_assignments_count = 0;
    foreach ($reports as $report) {
        $total_assignments_count += $report['total_assignments'];
        $completed_assignments_count += $report['completed_assignments'];
    }
    if ($total_assignments_count > 0) {
        $assignment_rate = round(($completed_assignments_count / $total_assignments_count) * 100);
        if ($assignment_rate >= 90) {
            $strengths[] = "Exceptional assignment completion: {$assignment_rate}% ({$completed_assignments_count}/{$total_assignments_count})";
        } elseif ($assignment_rate >= 75) {
            $strengths[] = "Good assignment completion: {$assignment_rate}% rate";
        }
    }
    
    // 4. Check engagement from logs (last 30 days)
    $sql = "SELECT COUNT(*) as activity_count
            FROM {logstore_standard_log}
            WHERE userid = ? AND timecreated > ?";
    $activity_result = $DB->get_record_sql($sql, [$USER->id, time() - (30 * 24 * 60 * 60)]);
    $activity_count = $activity_result->activity_count ?? 0;
    
    if ($activity_count > 500) {
        $strengths[] = "High engagement: {$activity_count} activities in last 30 days";
    } elseif ($activity_count > 200) {
        $strengths[] = "Active participation: {$activity_count} course interactions";
    }
    
    // 5. Check competency achievement
    if ($has_competencies) {
        $proficient_count = 0;
        foreach ($competency_data as $comp) {
            if ($comp['is_proficient']) {
                $proficient_count++;
            }
        }
        if ($proficient_count > 0) {
            $comp_word = $proficient_count == 1 ? 'competency' : 'competencies';
            $strengths[] = "Proficient in {$proficient_count} {$comp_word}";
        }
    }
    
    $learning_insights['strengths'] = !empty($strengths) ? $strengths : ['Making steady progress in your learning journey'];
    
    // Analyze areas for improvement
    $improvements = [];
    
    // 1. Low quiz scores
    $low_quiz_count = 0;
    foreach ($reports as $report) {
        if (isset($report['has_quiz_grades']) && $report['has_quiz_grades'] && isset($report['quiz_avg']) && $report['quiz_avg'] < 70) {
            $low_quiz_count++;
        }
    }
    if ($low_quiz_count > 0) {
        $course_word = $low_quiz_count == 1 ? 'course' : 'courses';
        $improvements[] = "Review quiz strategies in {$low_quiz_count} {$course_word} with scores below 70%";
    }
    
    // 2. Incomplete assignments
    if ($total_assignments_count > 0 && ($completed_assignments_count / $total_assignments_count) < 0.8) {
        $missing = $total_assignments_count - $completed_assignments_count;
        $assignment_word = $missing == 1 ? 'assignment' : 'assignments';
        $improvements[] = "Complete {$missing} pending {$assignment_word}";
    }
    
    // 3. Low attendance
    if ($attendance_stats['rate'] < 75) {
        $improvements[] = "Improve attendance from current {$attendance_stats['rate']}% rate";
    }
    
    // 4. Course progress below 50%
    $low_progress_courses = [];
    foreach ($reports as $report) {
        if ($report['progress'] < 50) {
            $low_progress_courses[] = $report['coursename'];
        }
    }
    if (!empty($low_progress_courses)) {
        $count = count($low_progress_courses);
        if ($count == 1) {
            $improvements[] = "Accelerate progress in " . $low_progress_courses[0];
        } elseif ($count == 2) {
            $improvements[] = "Accelerate progress in " . implode(' and ', $low_progress_courses);
        } else {
            $course_list = implode(', ', array_slice($low_progress_courses, 0, 2));
            $improvements[] = "Accelerate progress in {$course_list} and " . ($count - 2) . " more";
        }
    }
    
    // 5. Low activity engagement
    if ($activity_count < 100) {
        $improvements[] = "Increase engagement with course materials";
    }
    
    $learning_insights['improvements'] = !empty($improvements) ? $improvements : ['Continue maintaining your current learning pace'];
    
    // Generate recommendations
    $recommendations = [];
    
    // Based on grade performance
    if ($average_grade < 70) {
        $recommendations[] = "Schedule regular study sessions to improve performance";
        $recommendations[] = "Reach out to instructors for support";
    } elseif ($average_grade < 85) {
        $recommendations[] = "Review materials regularly to strengthen understanding";
    }
    
    // Based on assignment completion
    if ($total_assignments_count > 0 && ($completed_assignments_count / $total_assignments_count) < 0.9) {
        $recommendations[] = "Set deadline reminders to improve completion rate";
    }
    
    // Based on quiz performance
    if ($low_quiz_count > 0) {
        $recommendations[] = "Practice with sample questions before quizzes";
        $recommendations[] = "Review quiz feedback to identify knowledge gaps";
    }
    
    // Based on activity level
    if ($activity_count < 200) {
        $recommendations[] = "Engage more frequently with course content";
        $recommendations[] = "Participate in discussion forums";
    }
    
    // Based on time management - find peak learning hour
    $sql = "SELECT HOUR(FROM_UNIXTIME(timecreated)) as hour, COUNT(*) as count
            FROM {logstore_standard_log}
            WHERE userid = ? AND timecreated > ? AND action = 'viewed'
            GROUP BY HOUR(FROM_UNIXTIME(timecreated))
            ORDER BY count DESC
            LIMIT 1";
    $peak_hour = $DB->get_record_sql($sql, [$USER->id, time() - (30 * 24 * 60 * 60)]);
    if ($peak_hour && $peak_hour->count > 10) {
        $hour_formatted = sprintf('%02d:00', $peak_hour->hour);
        $recommendations[] = "Peak learning time: {$hour_formatted} - schedule tasks then";
    }
    
    // General recommendations
    if (empty($recommendations)) {
        $recommendations[] = "Continue maintaining excellent learning habits";
        $recommendations[] = "Set weekly learning goals to track progress";
        $recommendations[] = "Collaborate with peers for better understanding";
    }
    
    $learning_insights['recommendations'] = $recommendations;
    
    error_log("HS MyReports: Generated learning insights - Strengths: " . count($strengths) . 
              ", Improvements: " . count($improvements) . ", Recommendations: " . count($recommendations));
    
} catch (Exception $e) {
    error_log("HS MyReports: Learning insights error: " . $e->getMessage());
    $learning_insights = [
        'strengths' => ['Continue your learning journey with dedication'],
        'improvements' => ['Focus on consistent effort across all courses'],
        'recommendations' => ['Engage actively with course materials and instructors']
    ];
}

// ============================================================================
// FETCH REAL SCORM DATA
// ============================================================================

$scorm_data = [];
$scorm_stats = [
    'total' => 0,
    'started' => 0,
    'completed' => 0,
    'completion_rate' => 0,
    'average_score' => 0
];

try {
    // Fetch SCORM modules from enrolled courses
    $sql = "SELECT sc.id, sc.name, sc.intro, sc.course,
                   c.fullname as course_name,
                   sst.value as status,
                   ssg.value as score_raw,
                   sst2.value as total_time
            FROM {scorm} sc
            JOIN {course} c ON c.id = sc.course
            LEFT JOIN {scorm_scoes_track} sst ON sst.scormid = sc.id 
                AND sst.userid = ? 
                AND sst.element = 'cmi.core.lesson_status'
            LEFT JOIN {scorm_scoes_track} ssg ON ssg.scormid = sc.id 
                AND ssg.userid = ? 
                AND ssg.element = 'cmi.core.score.raw'
            LEFT JOIN {scorm_scoes_track} sst2 ON sst2.scormid = sc.id 
                AND sst2.userid = ? 
                AND sst2.element = 'cmi.core.total_time'
            WHERE sc.course IN (
                SELECT DISTINCT e.courseid 
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE ue.userid = ? AND ue.status = 0
            )
            ORDER BY c.fullname, sc.name";
    
    $scorm_records = $DB->get_records_sql($sql, [$USER->id, $USER->id, $USER->id, $USER->id]);
    
    $total_score_sum = 0;
    $scorm_with_score = 0;
    
    foreach ($scorm_records as $scorm) {
        $status = strtolower($scorm->status ?? 'not attempted');
        $score = !empty($scorm->score_raw) ? floatval($scorm->score_raw) : 0;
        $is_completed = in_array($status, ['completed', 'passed']);
        $is_started = !empty($status) && $status != 'not attempted';
        
        $scorm_data[] = [
            'id' => $scorm->id,
            'name' => $scorm->name,
            'description' => strip_tags($scorm->intro ?? ''),
            'course_name' => $scorm->course_name,
            'status' => ucfirst($status),
            'score' => $score,
            'total_time' => $scorm->total_time ?? '0',
            'is_completed' => $is_completed,
            'is_started' => $is_started,
            'completion_percentage' => $is_completed ? 100 : ($is_started ? 50 : 0)
        ];
        
        $scorm_stats['total']++;
        if ($is_started) $scorm_stats['started']++;
        if ($is_completed) $scorm_stats['completed']++;
        if ($score > 0) {
            $total_score_sum += $score;
            $scorm_with_score++;
        }
    }
    
    $scorm_stats['completion_rate'] = $scorm_stats['total'] > 0 ? 
        round(($scorm_stats['completed'] / $scorm_stats['total']) * 100) : 0;
    $scorm_stats['average_score'] = $scorm_with_score > 0 ? 
        round($total_score_sum / $scorm_with_score) : 0;
    
    error_log("HS MyReports: Found " . count($scorm_data) . " SCORM modules");
    
} catch (Exception $e) {
    error_log("HS MyReports: SCORM data error: " . $e->getMessage());
}

// Calculate Skills from Real Grades and Activities - NO RANDOM VALUES
$skills_data = [
    'critical_thinking' => 0,
    'problem_solving' => 0,
    'communication' => 0,
    'collaboration' => 0,
    'creativity' => 0,
    'leadership' => 0
];

// Calculate skills intelligently from actual performance data
if (!empty($reports)) {
    $total_assignments = 0;
    $total_quizzes = 0;
    $assignment_avg = 0;
    $quiz_avg = 0;
    $overall_grade = $average_grade;
    
    foreach ($reports as $report) {
        if ($report['total_assignments'] > 0) {
            $total_assignments += $report['total_assignments'];
            $assignment_completion = $report['total_assignments'] > 0 ? 
                ($report['completed_assignments'] / $report['total_assignments']) * 100 : 0;
            $assignment_avg += $assignment_completion;
        }
        if ($report['total_quizzes'] > 0) {
            $total_quizzes += $report['total_quizzes'];
            $quiz_completion = $report['total_quizzes'] > 0 ? 
                ($report['completed_quizzes'] / $report['total_quizzes']) * 100 : 0;
            $quiz_avg += $quiz_completion;
        }
    }
    
    $assignment_avg = $total_assignments > 0 ? round($assignment_avg / count($reports)) : 0;
    $quiz_avg = $total_quizzes > 0 ? round($quiz_avg / count($reports)) : 0;
    
    // Map to skills based on actual data (no random numbers!)
    $skills_data['critical_thinking'] = min(100, max(0, round($quiz_avg * 0.8 + $overall_grade * 0.2)));
    $skills_data['problem_solving'] = min(100, max(0, round($assignment_avg * 0.7 + $quiz_avg * 0.3)));
    $skills_data['communication'] = min(100, max(0, round($assignment_avg * 0.9 + 10)));
    $skills_data['collaboration'] = min(100, max(0, round($overall_grade * 0.85)));
    $skills_data['creativity'] = min(100, max(0, round($assignment_avg * 0.95)));
    $skills_data['leadership'] = min(100, max(0, round($overall_grade * 0.75 + 10)));
} else {
    // No data - set all to 0
    $skills_data = array_fill_keys(array_keys($skills_data), 0);
}

// Harmonize report structures to power advanced charts similar to elementary template
try {
    // Build quiz data list compatible with elementary graphs
    $elementary_quizzes = [];
    if (!empty($grade_reports['quiz_grades'])) {
        foreach ($grade_reports['quiz_grades'] as $q) {
            $elementary_quizzes[] = [
                'itemname' => isset($q['itemname']) ? (string)$q['itemname'] : '',
                'percentage' => isset($q['percentage']) ? (float)$q['percentage'] : 0,
                'finalgrade' => isset($q['finalgrade']) ? (float)$q['finalgrade'] : 0,
                'grademax' => isset($q['grademax']) ? (float)$q['grademax'] : 0,
                'letter_grade' => isset($q['letter_grade']) ? (string)$q['letter_grade'] : '',
                // Optional fields used by elementary charts (fallbacks provided)
                'attempts' => isset($q['attempts']) ? (int)$q['attempts'] : 0,
                'time_taken_formatted' => isset($q['time_taken_formatted']) ? (string)$q['time_taken_formatted'] : '',
                'date' => isset($q['timemodified']) && is_numeric($q['timemodified']) ? userdate((int)$q['timemodified']) : ''
            ];
        }
    }

    // Build assignment data list compatible with elementary graphs
    $elementary_assignments = [];
    if (!empty($grade_reports['assignment_grades'])) {
        foreach ($grade_reports['assignment_grades'] as $a) {
            $elementary_assignments[] = [
                'itemname' => isset($a['itemname']) ? (string)$a['itemname'] : '',
                'percentage' => isset($a['percentage']) ? (float)$a['percentage'] : 0,
                'finalgrade' => isset($a['finalgrade']) ? (float)$a['finalgrade'] : 0,
                'grademax' => isset($a['grademax']) ? (float)$a['grademax'] : 0,
                'letter_grade' => isset($a['letter_grade']) ? (string)$a['letter_grade'] : '',
                // Optional extras with safe fallbacks
                'status' => isset($a['status']) ? (string)$a['status'] : '',
                'attempts' => isset($a['attempts']) ? (int)$a['attempts'] : 0,
                'date' => isset($a['timemodified']) && is_numeric($a['timemodified']) ? userdate((int)$a['timemodified']) : ''
            ];
        }
    }

    // Unified flat list of all grades for generic charts/tables
    $all_grades_flat = [];
    $sources = ['assignment_grades', 'quiz_grades', 'activity_grades'];
    foreach ($sources as $src) {
        if (!empty($grade_reports[$src]) && is_array($grade_reports[$src])) {
            foreach ($grade_reports[$src] as $g) {
                $all_grades_flat[] = [
                    'itemname' => isset($g['itemname']) ? (string)$g['itemname'] : '',
                    'finalgrade' => isset($g['finalgrade']) ? (float)$g['finalgrade'] : 0,
                    'grademax' => isset($g['grademax']) ? (float)$g['grademax'] : 0,
                    'percentage' => isset($g['percentage']) ? (float)$g['percentage'] : (
                        (isset($g['finalgrade'], $g['grademax']) && (float)$g['grademax'] > 0)
                            ? round(((float)$g['finalgrade'] / (float)$g['grademax']) * 100, 1)
                            : 0
                    ),
                    'letter_grade' => isset($g['letter_grade']) ? (string)$g['letter_grade'] : '',
                    'date' => isset($g['timemodified']) && is_numeric($g['timemodified']) ? userdate((int)$g['timemodified']) : ''
                ];
            }
        }
    }

    // Attach in elementary-compatible keys without breaking existing consumers
    $grade_reports['quizzes'] = $elementary_quizzes;
    $grade_reports['assignments'] = $elementary_assignments;
    $grade_reports['all_grades'] = $all_grades_flat;

    // Convenience flags used by elementary charts
    $has_quiz_grades = !empty($elementary_quizzes);
    $has_assignment_grades = !empty($elementary_assignments);
    $has_grade_reports = !empty($all_grades_flat);

} catch (Exception $e) {
    // Do not fail the page for visualization prep issues
    $has_quiz_grades = false;
    $has_assignment_grades = false;
    $has_grade_reports = false;
}

// Add comprehensive error handling for the entire script
try {
    // Ensure all required variables are initialized
    if (!isset($dashboard_stats)) $dashboard_stats = [];
    if (!isset($grade_analytics)) $grade_analytics = [];
    if (!isset($course_performance)) $course_performance = [];
    if (!isset($professional_analytics)) $professional_analytics = [];
    if (!isset($real_time_data)) $real_time_data = [];
    if (!isset($grade_reports)) $grade_reports = [];
    
    // Initialize template context if not already set
    if (!isset($templatecontext)) {
        $templatecontext = [];
    }
    
    // Merge all data into template context
    $templatecontext = array_merge($templatecontext, [
        'dashboard_stats' => $dashboard_stats,
        'grade_analytics' => $grade_analytics,
        'course_performance' => $course_performance,
        'professional_analytics' => $professional_analytics,
        'real_time_data' => $real_time_data,
        'grade_reports' => $grade_reports,
        
        // Real data for new sections
        'competency_data' => $competency_data,
        'competency_frameworks' => $competency_frameworks,
        'has_competencies' => $has_competencies,
        'rubric_data' => $rubric_data,
        'rubric_summary' => $rubric_summary,
        'rubric_by_assignment' => $rubric_by_assignment,
        'has_rubrics' => $has_rubrics,
        'rubric_diagnostics' => $rubric_diagnostics ?? [],
        'attendance_hourly' => $attendance_hourly,
        'attendance_daily' => $attendance_daily,
        'attendance_weekly' => $attendance_weekly,
        'attendance_monthly' => $attendance_monthly,
        'attendance_stats' => $attendance_stats,
        'learning_insights' => $learning_insights,
        'scorm_data' => $scorm_data,
        'scorm_stats' => $scorm_stats,
        'has_scorm' => !empty($scorm_data),
        'skills_data' => $skills_data,
        
        // Elementary-style flags for charts/tables
        'has_quiz_grades' => isset($has_quiz_grades) ? (bool)$has_quiz_grades : !empty($grade_reports['quiz_grades']),
        'has_assignment_grades' => isset($has_assignment_grades) ? (bool)$has_assignment_grades : !empty($grade_reports['assignment_grades']),
        'has_grade_reports' => isset($has_grade_reports) ? (bool)$has_grade_reports : (!empty($grade_reports['assignment_grades']) || !empty($grade_reports['quiz_grades'])),
        'show_professional_dashboard' => true,
        'has_dashboard_stats' => !empty($dashboard_stats),
        'has_grade_analytics' => !empty($grade_analytics),
        'has_course_performance' => !empty($course_performance),
        'has_professional_analytics' => !empty($professional_analytics),
        'has_real_time_data' => !empty($real_time_data)
    ]);
    
} catch (Exception $e) {
    error_log("HS MyReports: Critical error in template context: " . $e->getMessage());
    
    // Initialize template context if not already set
    if (!isset($templatecontext)) {
        $templatecontext = [];
    }
    
    // Provide minimal template context to prevent template errors
    $templatecontext = array_merge($templatecontext, [
        'dashboard_stats' => [],
        'grade_analytics' => [],
        'course_performance' => [],
        'professional_analytics' => [],
        'real_time_data' => [],
        'grade_reports' => [],
        'show_professional_dashboard' => false,
        'has_dashboard_stats' => false,
        'has_grade_analytics' => false,
        'has_course_performance' => false,
        'has_professional_analytics' => false,
        'has_real_time_data' => false,
        'has_grade_reports' => false
    ]);
}

$sidebar_context = remui_kids_build_highschool_sidebar_context('reports', $USER);

// Prepare comprehensive template data for professional dashboard
$templatecontext = [
    // User information
    'user_fullname' => fullname($USER),
    'user_email' => $USER->email,
    'user_firstname' => $USER->firstname,
    'user_lastname' => $USER->lastname,
    'user_id' => $USER->id,
    
    // Professional dashboard data
    'dashboard_stats' => $dashboard_stats,
    'grade_analytics' => $grade_analytics,
    'course_performance' => $course_performance,
    'professional_analytics' => $professional_analytics,
    'real_time_data' => $real_time_data,
    'grade_reports' => $grade_reports,
    
    // Legacy data for compatibility
    'reports' => $reports,
    'has_reports' => !empty($reports),
    'has_real_data' => $has_real_data,
    'total_courses' => $total_courses,
    'completed_courses' => $completed_courses,
    'average_progress' => $average_progress,
    'average_grade' => $average_grade,
    'total_assignments' => $totalassignments,
    'completed_assignments' => $completedassignments,
    'total_quizzes' => $totalquizzes,
    'completed_quizzes' => $completedquizzes,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'dashboardurl' => $sidebar_context['dashboardurl'],
    'gradesurl' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'coursesurl' => $sidebar_context['mycoursesurl'],
    'reportsurl' => $sidebar_context['reportsurl'],
    
    // Sidebar data
    'sidebar_courses' => $sidebar_courses_list,
    'has_sidebar_courses' => !empty($sidebar_courses_list),
    'recent_activities' => $recent_activities,
    'has_recent_activities' => !empty($recent_activities),
    
    // Professional dashboard flags
    'has_dashboard_stats' => !empty($dashboard_stats),
    'has_grade_analytics' => !empty($grade_analytics),
    'has_course_performance' => !empty($course_performance),
    'show_professional_dashboard' => true,
    'grade_overview' => $grade_overview,
    'has_grade_overview' => !empty($grade_overview)
];

// Merge sidebar context with main context
$templatecontext = array_merge($templatecontext, $sidebar_context);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $sidebar_context);

// ============================================================================
// BRAND NEW REPORTS PAGE - Reference: Super Admin Reports Design
// ============================================================================
?>
<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/highschool_reports.css">
<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/highschool_myreports_clean.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<style>
    .footer-copyright-wrapper ,.footer-mainsection-wrapper{
        display: none !important;
     }
</style>
<!-- Main Content -->
<div class="modern-reports-page">
    
    <!-- Navigation Tabs - Complete Set -->
    <div class="nav-tabs-container">
        <div class="nav-tabs-custom">
            <div class="nav-tab-item active" data-tab="overview">
                <i class="fas fa-chart-bar"></i> Overview
            </div>
            <div class="nav-tab-item" data-tab="general">
                <i class="fas fa-graduation-cap"></i> General Assessment
            </div>
            <div class="nav-tab-item" data-tab="quizzes">
                <i class="fas fa-question-circle"></i> Quizzes
            </div>
            <div class="nav-tab-item" data-tab="assignments">
                <i class="fas fa-clipboard-list"></i> Assignments
            </div>
            <div class="nav-tab-item" data-tab="competencies">
                <i class="fas fa-bullseye"></i> Competencies
            </div>
            <div class="nav-tab-item" data-tab="rubrics">
                <i class="fas fa-check-square"></i> Rubric Assessments
            </div>
            <div class="nav-tab-item" data-tab="attendance">
                <i class="fas fa-calendar-check"></i> Attendance
            </div>
            <div class="nav-tab-item" data-tab="insights">
                <i class="fas fa-lightbulb"></i> Learning Insights
            </div>
        </div>
    </div>

    <!-- Tab Content Sections -->
    
    <!-- OVERVIEW TAB -->
    <div class="tab-content-section" id="tab-overview" style="display: block;">
        <!-- Key Statistics Cards -->
        <div class="stats-grid-modern">
        <div class="stat-card-modern purple">
            <div class="stat-icon-modern">
                <i class="fas fa-book"></i>
            </div>
            <div class="stat-number"><?php echo $total_courses; ?></div>
            <div class="stat-label-modern">Total Courses</div>
        </div>

        <div class="stat-card-modern pink">
            <div class="stat-icon-modern">
                <i class="fas fa-clipboard-check"></i>
            </div>
            <div class="stat-number"><?php echo $completedassignments; ?>/<?php echo $totalassignments; ?></div>
            <div class="stat-label-modern">Assignments Done</div>
        </div>

        <div class="stat-card-modern blue">
            <div class="stat-icon-modern">
                <i class="fas fa-question-circle"></i>
            </div>
            <div class="stat-number"><?php echo $completedquizzes; ?>/<?php echo $totalquizzes; ?></div>
            <div class="stat-label-modern">Quizzes Completed</div>
        </div>

        <div class="stat-card-modern green">
            <div class="stat-icon-modern">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-number"><?php echo round($average_progress); ?>%</div>
            <div class="stat-label-modern">Avg Completion</div>
        </div>

        <div class="stat-card-modern orange">
            <div class="stat-icon-modern">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-number"><?php echo $average_grade; ?>%</div>
            <div class="stat-label-modern">Average Grade</div>
        </div>

        <div class="stat-card-modern teal">
            <div class="stat-icon-modern">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stat-number"><?php echo $completed_courses; ?></div>
            <div class="stat-label-modern">Courses Completed</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section-modern">
        <!-- Performance Trend Chart -->
        <div class="chart-card-modern">
            <div class="chart-header-modern">
                <h3>Performance Trend</h3>
                <p>Your grade progression over time</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="performanceTrendChart"></canvas>
            </div>
        </div>

        <!-- Course Progress Chart -->
        <div class="chart-card-modern">
            <div class="chart-header-modern">
                <h3>Course Completion Status</h3>
                <p>Progress across all enrolled courses</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="courseProgressChart"></canvas>
            </div>
        </div>

        <!-- Grade Distribution Chart -->
        <div class="chart-card-modern">
            <div class="chart-header-modern">
                <h3>Grade Distribution</h3>
                <p>Breakdown of your grades</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="gradeDistributionChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Competency & Rubric Assessment Section -->
    <div class="charts-section-modern">
        <!-- Competency Progress -->
        <div class="chart-card-modern">
            <div class="chart-header-modern">
                <h3><i class="fas fa-bullseye"></i> Competency Progress</h3>
                <p>Skills and competencies development</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="competencyProgressChart"></canvas>
            </div>
            <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; text-align: center;">
                <p style="color: #64748b; font-size: 0.9rem; margin: 0;">
                    <i class="fas fa-info-circle"></i> Competencies track your skill development across courses
                </p>
            </div>
        </div>

        <!-- Rubric Assessments -->
        <div class="chart-card-modern">
            <div class="chart-header-modern">
                <h3><i class="fas fa-clipboard-check"></i> Rubric Assessments</h3>
                <p>Detailed evaluation criteria scores</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="rubricAssessmentChart"></canvas>
            </div>
            <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; text-align: center;">
                <p style="color: #64748b; font-size: 0.9rem; margin: 0;">
                    <i class="fas fa-info-circle"></i> Rubrics provide detailed feedback on your work quality
                </p>
            </div>
        </div>
    </div>

    <!-- Skills Assessment Grid - REAL DATA -->
    <div class="stats-grid-modern" style="margin-bottom: 30px;">
        <div class="stat-card-modern purple">
            <div class="stat-icon-modern">
                <i class="fas fa-brain"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['critical_thinking']; ?>%</div>
            <div class="stat-label-modern">Critical Thinking</div>
        </div>

        <div class="stat-card-modern blue">
            <div class="stat-icon-modern">
                <i class="fas fa-lightbulb"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['problem_solving']; ?>%</div>
            <div class="stat-label-modern">Problem Solving</div>
        </div>

        <div class="stat-card-modern green">
            <div class="stat-icon-modern">
                <i class="fas fa-comments"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['communication']; ?>%</div>
            <div class="stat-label-modern">Communication</div>
        </div>

        <div class="stat-card-modern orange">
            <div class="stat-icon-modern">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['collaboration']; ?>%</div>
            <div class="stat-label-modern">Collaboration</div>
        </div>

        <div class="stat-card-modern pink">
            <div class="stat-icon-modern">
                <i class="fas fa-star"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['creativity']; ?>%</div>
            <div class="stat-label-modern">Creativity</div>
        </div>

        <div class="stat-card-modern teal">
            <div class="stat-icon-modern">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stat-number"><?php echo $skills_data['leadership']; ?>%</div>
            <div class="stat-label-modern">Leadership</div>
        </div>
    </div>

    <!-- My Courses Section with REAL Images -->
    <?php if (!empty($reports)): ?>
    <?php
        $latestcourses = $reports;
        usort($latestcourses, function($a, $b) {
            $adays = isset($a['days_since_access']) ? $a['days_since_access'] : PHP_INT_MAX;
            $bdays = isset($b['days_since_access']) ? $b['days_since_access'] : PHP_INT_MAX;
            if ($adays === $bdays) {
                return ($b['grade_percentage'] ?? 0) <=> ($a['grade_percentage'] ?? 0);
            }
            return $adays <=> $bdays;
        });
        $displaycourses = array_slice($latestcourses, 0, 8);
    ?>
    <div class="chart-card-modern full-width" style="margin-top: 30px;">
        <div class="chart-header-modern">
            <h3><i class="fas fa-book-open"></i> My Courses</h3>
            <p>Your enrolled courses with real course images</p>
        </div>
        <div class="stats-grid-modern" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
            <?php foreach ($displaycourses as $course): ?>
            <div style="background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;" 
                 onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 4px 20px rgba(0, 0, 0, 0.12)';" 
                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 10px rgba(0, 0, 0, 0.08)';"
                 onclick="window.location.href='<?php echo $course['courseurl']->out(); ?>';">
                <!-- Course Image - ONLY REAL IMAGES, NO FALLBACK -->
                <?php 
                $course_img_url = !empty($course['courseimage']) ? trim($course['courseimage']) : '';
                // Check if we have a real image URL (Moodle pluginfile URLs are valid even if relative)
                $has_real_image = !empty($course_img_url) && 
                                  (strpos($course_img_url, '/pluginfile.php') !== false || 
                                   strpos($course_img_url, 'http') === 0 ||
                                   strpos($course_img_url, '/theme/') === 0 ||
                                   strpos($course_img_url, '/course/') === 0);
                if ($has_real_image): 
                ?>
                <div style="width: 100%; height: 160px; background-image: url('<?php echo htmlspecialchars($course_img_url, ENT_QUOTES); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat; position: relative;">
                    <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(0,0,0,0.5), transparent); height: 60px;"></div>
                </div>
                <?php else: ?>
                <!-- No image placeholder - Simple icon, NO gradient background, NO image -->
                <div style="width: 100%; height: 160px; background: #f8fafc; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #e2e8f0;">
                    <i class="fas fa-book" style="font-size: 3rem; color: #cbd5e1;"></i>
                </div>
                <?php endif; ?>
                
                <!-- Course Info -->
                <div style="padding: 20px;">
                    <h4 style="margin: 0 0 8px 0; color: #1e293b; font-size: 1.1rem; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                        <?php echo htmlspecialchars($course['coursename']); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; color: #64748b; font-size: 0.85rem;">
                        <?php echo htmlspecialchars($course['shortname']); ?>
                    </p>
                    
                    <!-- Progress Bar -->
                    <div style="margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span style="font-size: 0.85rem; color: #64748b; font-weight: 600;">Progress</span>
                            <span style="font-size: 0.85rem; color: #3b82f6; font-weight: 700;"><?php echo round($course['progress']); ?>%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                            <div style="height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); width: <?php echo min(100, round($course['progress'])); ?>%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                    
                    <!-- Grade Info -->
                    <?php if ($course['has_grade']): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px solid #f1f5f9;">
                        <div>
                            <span style="font-size: 0.8rem; color: #94a3b8;">Grade</span>
                            <div style="font-size: 1.3rem; font-weight: 700; color: <?php echo $course['grade_percentage'] >= 80 ? '#10b981' : ($course['grade_percentage'] >= 60 ? '#f59e0b' : '#ef4444'); ?>;">
                                <?php echo htmlspecialchars($course['grade_letter']); ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 0.8rem; color: #94a3b8;">Score</span>
                            <div style="font-size: 1.1rem; font-weight: 600; color: #475569;">
                                <?php echo round($course['grade_percentage']); ?>%
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

        <!-- Recent Activity -->
        <div class="recent-activity-card">
            <div class="chart-header-modern">
                <h3><i class="fas fa-history"></i> Recent Activity</h3>
                <p>Your latest academic actions</p>
            </div>
            <?php if (!empty($reports)): ?>
            <?php foreach (array_slice($reports, 0, 5) as $report): ?>
            <div class="activity-item">
                <div class="activity-text">
                    <i class="fas fa-book"></i> Accessed <strong><?php echo htmlspecialchars($report['coursename']); ?></strong>
                </div>
                <div class="activity-time"><?php echo $report['last_accessed']; ?></div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="activity-item">
                <div class="activity-text">
                    <i class="fas fa-info-circle"></i> No recent activity recorded
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- END OVERVIEW TAB -->

    <!-- GENERAL ASSESSMENT TAB -->
    <div class="tab-content-section" id="tab-general" style="display: none;">
        <div class="chart-card-modern full-width">
            <div class="chart-header-modern">
                <h3><i class="fas fa-graduation-cap"></i> General Assessment Overview</h3>
                <p>Comprehensive evaluation of your overall academic performance</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="generalAssessmentChart"></canvas>
            </div>
        </div>
        
        <div class="stats-grid-modern">
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-percent"></i></div>
                <div class="stat-number"><?php echo $average_grade; ?>%</div>
                <div class="stat-label-modern">Overall Average</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-number"><?php echo $average_progress; ?>%</div>
                <div class="stat-label-modern">Avg Progress</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-star"></i></div>
                <div class="stat-number"><?php 
                    // Calculate current letter grade
                    if ($average_grade >= 90) echo 'A';
                    elseif ($average_grade >= 80) echo 'B';
                    elseif ($average_grade >= 70) echo 'C';
                    elseif ($average_grade >= 60) echo 'D';
                    else echo 'F';
                ?></div>
                <div class="stat-label-modern">Current Grade</div>
            </div>
        </div>
    </div>

    <!-- QUIZZES TAB -->
    <div class="tab-content-section" id="tab-quizzes" style="display: none;">
        <div class="stats-grid-modern">
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-question-circle"></i></div>
                <div class="stat-number"><?php echo $totalquizzes; ?></div>
                <div class="stat-label-modern">Total Quizzes</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                <div class="stat-number"><?php echo $completedquizzes; ?></div>
                <div class="stat-label-modern">Completed</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-percent"></i></div>
                <div class="stat-number"><?php echo $totalquizzes > 0 ? round(($completedquizzes / $totalquizzes) * 100) : 0; ?>%</div>
                <div class="stat-label-modern">Completion Rate</div>
            </div>
        </div>
        
        <div class="chart-card-modern full-width">
            <div class="chart-header-modern">
                <h3><i class="fas fa-question-circle"></i> Quiz Performance Analysis</h3>
                <p>Your quiz scores and trends over time</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="quizAnalysisChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ASSIGNMENTS TAB -->
    <div class="tab-content-section" id="tab-assignments" style="display: none;">
        <div class="stats-grid-modern">
            <div class="stat-card-modern purple">
                <div class="stat-icon-modern"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-number"><?php echo $totalassignments; ?></div>
                <div class="stat-label-modern">Total Assignments</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $completedassignments; ?></div>
                <div class="stat-label-modern">Submitted</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-percent"></i></div>
                <div class="stat-number"><?php echo $totalassignments > 0 ? round(($completedassignments / $totalassignments) * 100) : 0; ?>%</div>
                <div class="stat-label-modern">Completion Rate</div>
            </div>
        </div>
        
        <div class="chart-card-modern full-width">
            <div class="chart-header-modern">
                <h3><i class="fas fa-clipboard-list"></i> Assignment Performance Tracking</h3>
                <p>Track your assignment submissions and grades</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="assignmentAnalysisChart"></canvas>
            </div>
        </div>
    </div>

    <!-- SCORM MODULES TAB -->
    <div class="tab-content-section" id="tab-scorm" style="display: none;">
        <?php if ($has_scorm && !empty($scorm_data)): ?>
        
        <!-- SCORM Statistics Cards -->
        <div class="stats-grid-modern">
            <div class="stat-card-modern purple">
                <div class="stat-icon-modern"><i class="fas fa-play-circle"></i></div>
                <div class="stat-number"><?php echo $scorm_stats['total']; ?></div>
                <div class="stat-label-modern">Total SCORM Modules</div>
            </div>
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-play"></i></div>
                <div class="stat-number"><?php echo $scorm_stats['started']; ?></div>
                <div class="stat-label-modern">Started</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $scorm_stats['completed']; ?></div>
                <div class="stat-label-modern">Completed</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-percent"></i></div>
                <div class="stat-number"><?php echo $scorm_stats['completion_rate']; ?>%</div>
                <div class="stat-label-modern">Completion Rate</div>
            </div>
            <div class="stat-card-modern pink">
                <div class="stat-icon-modern"><i class="fas fa-star"></i></div>
                <div class="stat-number"><?php echo $scorm_stats['average_score']; ?></div>
                <div class="stat-label-modern">Average Score</div>
            </div>
        </div>
        
        <!-- SCORM Progress Chart -->
        <div class="chart-card-modern full-width" style="margin-top: 25px;">
            <div class="chart-header-modern">
                <h3><i class="fas fa-chart-pie"></i> SCORM Completion Overview</h3>
                <p><?php echo count($scorm_data); ?> SCORM modules across your courses</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="scormProgressChart"></canvas>
            </div>
        </div>
        
        <!-- SCORM Modules Table -->
        <div class="chart-card-modern full-width" style="margin-top: 25px;">
            <div class="chart-header-modern">
                <h3><i class="fas fa-table"></i> SCORM Modules Details</h3>
            </div>
            <div style="overflow-x: auto; padding: 0 20px 20px 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <tr>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Module Name</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Course</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Status</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Score</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Time Spent</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scorm_data as $scorm): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #475569; font-weight: 600;">
                                <?php echo htmlspecialchars($scorm['name']); ?>
                            </td>
                            <td style="padding: 12px; color: #64748b;">
                                <?php echo htmlspecialchars($scorm['course_name']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($scorm['is_completed']): ?>
                                <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                    <i class="fas fa-check-circle"></i> <?php echo $scorm['status']; ?>
                                </span>
                                <?php elseif ($scorm['is_started']): ?>
                                <span style="background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                    <i class="fas fa-play-circle"></i> <?php echo $scorm['status']; ?>
                                </span>
                                <?php else: ?>
                                <span style="background: #f1f5f9; color: #64748b; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                    <i class="fas fa-circle"></i> <?php echo $scorm['status']; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 700; color: <?php echo $scorm['score'] >= 80 ? '#10b981' : ($scorm['score'] >= 60 ? '#f59e0b' : '#64748b'); ?>;">
                                <?php echo $scorm['score'] > 0 ? round($scorm['score']) : '--'; ?>
                            </td>
                            <td style="padding: 12px; text-align: center; color: #64748b;">
                                <?php echo htmlspecialchars($scorm['total_time']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                                    <div style="flex: 1; max-width: 100px; height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                                        <div style="height: 100%; background: <?php echo $scorm['is_completed'] ? '#10b981' : ($scorm['is_started'] ? '#f59e0b' : '#e2e8f0'); ?>; width: <?php echo $scorm['completion_percentage']; ?>%;"></div>
                                    </div>
                                    <span style="font-weight: 600; color: #475569;"><?php echo $scorm['completion_percentage']; ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php else: ?>
        <!-- No SCORM Data -->
        <div class="chart-card-modern full-width">
            <div style="padding: 60px 40px; text-align: center;">
                <i class="fas fa-box-open" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 15px; display: block; opacity: 0.3;"></i>
                <h4 style="color: #64748b; margin: 0 0 10px 0;">No SCORM Modules Found</h4>
                <p style="color: #94a3b8; font-size: 0.95rem; margin: 0;">SCORM learning modules will appear here when added to your courses.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- COMPETENCIES TAB -->
    <div class="tab-content-section" id="tab-competencies" style="display: none;">
        
        <!-- Frameworks & Competencies TREE VIEW - AT THE TOP -->
        <?php if ($has_competencies && !empty($competency_frameworks)): ?>
        
        <!-- Tree View Controls -->
        <div style="background: white; border-radius: 12px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); display: flex; gap: 10px; align-items: center;">
            <span style="font-weight: 600; color: #64748b; margin-right: 10px;">
                <i class="fas fa-layer-group"></i> Tree View:
            </span>
            <button onclick="expandAllCompetencies()" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                <i class="fas fa-plus-circle"></i> Expand All
            </button>
            <button onclick="collapseAllCompetencies()" style="padding: 8px 16px; background: #64748b; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#475569'" onmouseout="this.style.background='#64748b'">
                <i class="fas fa-minus-circle"></i> Collapse All
            </button>
            <div style="margin-left: auto; color: #94a3b8; font-size: 0.85rem;">
                <i class="fas fa-info-circle"></i> Click chevrons to expand/collapse
            </div>
        </div>
        
        <?php foreach ($competency_frameworks as $framework): ?>
        <div class="chart-card-modern full-width" style="margin-bottom: 25px;">
            <div class="chart-header-modern">
                <h3><i class="fas fa-sitemap"></i> <?php echo htmlspecialchars($framework['name']); ?></h3>
                <p><?php echo count($framework['competencies']); ?> competencies in hierarchical tree structure</p>
            </div>
            
            <!-- Tree View Container -->
            <div style="padding: 20px;">
                <?php 
                // Function to render tree recursively
                function render_competency_tree($competency, $level = 0) {
                    $indent = $level * 30; // 30px per level
                    $has_children = !empty($competency['children']);
                    $unique_id = 'comp_' . $competency['id'];
                    ?>
                    
                    <!-- Competency Row -->
                    <div class="competency-tree-item" data-level="<?php echo $level; ?>" style="margin-left: <?php echo $indent; ?>px; margin-bottom: 8px;">
                        <div style="background: white; border: 1px solid #e2e8f0; border-left: 3px solid <?php echo $competency['status_color']; ?>; border-radius: 8px; padding: 15px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); transition: all 0.2s;" 
                             onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'; this.style.transform='translateX(5px)';" 
                             onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,0.05)'; this.style.transform='translateX(0)';">
                            
                            <div style="display: flex; align-items: flex-start; gap: 15px;">
                                <!-- Expand/Collapse Icon (if has children) -->
                                <?php if ($has_children): ?>
                                <div onclick="toggleCompetency('<?php echo $unique_id; ?>')" style="cursor: pointer; flex-shrink: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; border-radius: 4px; transition: background 0.2s;">
                                    <i class="fas fa-chevron-right" id="icon_<?php echo $unique_id; ?>" style="font-size: 0.75rem; color: #64748b; transition: transform 0.2s;"></i>
                                </div>
                                <?php else: ?>
                                <div style="width: 24px; flex-shrink: 0;"></div>
                                <?php endif; ?>
                                
                                <!-- Competency Info -->
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <h5 style="margin: 0; color: #1e293b; font-size: 1rem; font-weight: 600;">
                                            <i class="fas fa-bullseye" style="color: <?php echo $competency['status_color']; ?>; margin-right: 6px;"></i>
                                            <?php echo htmlspecialchars($competency['name']); ?>
                                        </h5>
                                        <?php if ($has_children): ?>
                                        <span style="background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600;">
                                            <?php echo count($competency['children']); ?> sub-items
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($competency['description'])): ?>
                                    <p style="margin: 0 0 10px 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">
                                        <?php echo htmlspecialchars(substr($competency['description'], 0, 150)); ?><?php echo strlen($competency['description']) > 150 ? '...' : ''; ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                                        <!-- Status Badge -->
                                        <span style="background: <?php echo $competency['status_color']; ?>20; color: <?php echo $competency['status_color']; ?>; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                            <?php echo $competency['status']; ?>
                                        </span>
                                        
                                        <!-- Grade -->
                                        <?php if ($competency['grade'] > 0): ?>
                                        <span style="background: #f8fafc; color: #475569; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                            Grade: <?php echo $competency['grade']; ?> / <?php echo $scale_max ?? 4; ?>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- Percentage -->
                                        <?php if ($competency['percentage'] > 0): ?>
                                        <span style="background: #ecfdf5; color: #059669; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                            <?php echo $competency['percentage']; ?>%
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- Course -->
                                        <?php if (!empty($competency['course'])): ?>
                                        <span style="color: #94a3b8; font-size: 0.85rem;">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars(substr($competency['course'], 0, 20)); ?>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- Date -->
                                        <span style="color: #94a3b8; font-size: 0.85rem;">
                                            <i class="fas fa-calendar"></i> <?php echo $competency['date_assessed']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Children (initially hidden) -->
                        <?php if ($has_children): ?>
                        <div id="<?php echo $unique_id; ?>" class="competency-children" style="display: none; margin-top: 8px;">
                            <?php foreach ($competency['children'] as $child): ?>
                                <?php render_competency_tree($child, $level + 1); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php
                }
                
                // Render tree for this framework
                foreach ($framework['tree'] as $root_comp) {
                    render_competency_tree($root_comp, 0);
                }
                ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Competency Chart - AT THE BOTTOM WITH 4 CHART TYPES -->
        <div class="charts-section-modern" style="margin-top: 30px;">
            <div class="chart-card-modern">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-bullseye"></i> Competency Progress Overview</h3>
                    <p><?php echo count($competency_data) . ' competencies across ' . count($competency_frameworks) . ' frameworks'; ?></p>
                </div>
                
                <!-- Chart Type Selector Buttons -->
                <div style="padding: 15px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <span style="font-weight: 600; color: #64748b; margin-right: 10px;">
                        <i class="fas fa-chart-bar"></i> Chart Type:
                    </span>
                    <button onclick="switchCompetencyChart('bar')" id="btnCompBar" class="chart-type-btn active" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-chart-bar"></i> Bar Chart
                    </button>
                    <button onclick="switchCompetencyChart('doughnut')" id="btnCompDoughnut" class="chart-type-btn" style="padding: 8px 16px; background: white; color: #64748b; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-chart-pie"></i> Doughnut Chart
                    </button>
                    <button onclick="switchCompetencyChart('radar')" id="btnCompRadar" class="chart-type-btn" style="padding: 8px 16px; background: white; color: #64748b; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-chart-area"></i> Radar Chart
                    </button>
                    <button onclick="switchCompetencyChart('line')" id="btnCompLine" class="chart-type-btn" style="padding: 8px 16px; background: white; color: #64748b; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-chart-line"></i> Line Chart
                    </button>
                </div>
                
                <!-- Chart Canvases (4 different types, only one visible at a time) -->
                <div class="chart-canvas-wrapper" id="compChartBar" style="display: block;">
                    <canvas id="competencyProgressChartBar"></canvas>
                </div>
                <div class="chart-canvas-wrapper" id="compChartDoughnut" style="display: none;">
                    <canvas id="competencyProgressChartDoughnut"></canvas>
                </div>
                <div class="chart-canvas-wrapper" id="compChartRadar" style="display: none;">
                    <canvas id="competencyProgressChartRadar"></canvas>
                </div>
                <div class="chart-canvas-wrapper" id="compChartLine" style="display: none;">
                    <canvas id="competencyProgressChartLine"></canvas>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- No Competencies Found -->
        <div class="chart-card-modern full-width">
            <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                <i class="fas fa-graduation-cap" style="font-size: 4rem; display: block; margin-bottom: 20px; opacity: 0.3;"></i>
                <h3 style="color: #64748b; margin: 0 0 10px 0;">No Competencies Found</h3>
                <p style="margin: 0; font-size: 0.95rem;">Competency assessments will appear here once your courses have competency frameworks assigned and you've been evaluated.</p>
                <p style="margin: 10px 0 0 0; font-size: 0.85rem; color: #cbd5e1;">Contact your administrator to set up competency frameworks for your courses.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RUBRIC ASSESSMENTS TAB -->
    <div class="tab-content-section" id="tab-rubrics" style="display: none;">
        <?php if ($has_rubrics && !empty($rubric_by_assignment)): ?>
        
        <?php
        // Calculate summary statistics
        $total_attempted = count($rubric_by_assignment);
        $graded_count = 0;
        $total_percentage = 0;
        foreach ($rubric_by_assignment as $assign) {
            if ($assign['percentage'] > 0) {
                $graded_count++;
                $total_percentage += $assign['percentage'];
            }
        }
        $ungraded_count = $total_attempted - $graded_count;
        $overall_average = $graded_count > 0 ? round($total_percentage / $graded_count, 1) : 0;
        ?>
        
        <!-- Rubric Summary Stats Cards -->
        <div class="stats-grid-modern">
            <div class="stat-card-modern purple">
                <div class="stat-icon-modern"><i class="fas fa-list-ul"></i></div>
                <div class="stat-number"><?php echo $total_attempted; ?></div>
                <div class="stat-label-modern">Total Attempted</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $graded_count; ?></div>
                <div class="stat-label-modern">Graded</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-clock-o"></i></div>
                <div class="stat-number"><?php echo $ungraded_count; ?></div>
                <div class="stat-label-modern">Pending</div>
            </div>
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-bar-chart"></i></div>
                <div class="stat-number"><?php echo $overall_average > 0 ? $overall_average . '%' : '-'; ?></div>
                <div class="stat-label-modern">Average Score</div>
            </div>
        </div>
        
        <!-- Rubric Summary Chart -->
        <div class="chart-card-modern full-width" style="margin-top: 25px;">
            <div class="chart-header-modern">
                <h3><i class="fas fa-chart-bar"></i> Rubric Criteria Performance</h3>
                <p><?php echo count($rubric_data); ?> assessments across <?php echo count($rubric_by_assignment); ?> assignments</p>
            </div>
            <div class="chart-canvas-wrapper">
                <canvas id="rubricAssessmentChart2"></canvas>
            </div>
        </div>
        
        <!-- Rubric Cards Container (Similar to Teacher Report) -->
        <div style="margin-top: 25px;">
            <?php 
            foreach ($rubric_by_assignment as $idx => $assignment): 
                // Get cmid and instance_id from assignment array
                $cmid = $assignment['cmid'] ?? null;
                $instance_id = $assignment['instance_id'] ?? null;
                
                // Get full rubric definition if cmid is available
                $full_rubric = null;
                if ($cmid) {
                    require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
                    $full_rubric = theme_remui_kids_get_rubric_by_cmid($cmid);
                }
                
                // Get existing fillings for this assignment from rubric_records
                $existing_fillings = [];
                if ($instance_id && isset($rubric_records)) {
                    foreach ($rubric_records as $record) {
                        if (isset($record->instance_id) && $record->instance_id == $instance_id) {
                            $existing_fillings[$record->criterionid] = [
                                'levelid' => $record->levelid,
                                'remark' => $record->remark ?? '',
                                'level_name' => $record->level_name,
                                'score' => $record->score
                            ];
                        }
                    }
                }
                
                // Calculate max score
                $max_score = $assignment['max_score'];
                $total_score = $assignment['total_score'];
                $percentage = $assignment['percentage'];
                
                $cardId = 'rubric-card-body-' . $idx;
                $toggleIconId = 'toggle-icon-' . $idx;
                $is_graded = $percentage > 0;
            ?>
            <div class="chart-card-modern full-width" style="margin-bottom: 25px; padding: 0; overflow: hidden;">
                <!-- Rubric Card Header -->
                <div style="background: white; padding: 25px; border-bottom: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div style="flex: 1; min-width: 250px;">
                            <h3 style="margin: 0 0 8px 0; color: #1e293b; font-size: 1.25rem; font-weight: 700;">
                                <?php echo htmlspecialchars($assignment['assignment_name']); ?>
                            </h3>
                            <p style="margin: 0; color: #64748b; font-size: 0.95rem;">
                                <?php echo htmlspecialchars($assignment['course_name']); ?>
                            </p>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                            <!-- Grade Badge -->
                            <div style="text-align: right;">
                                <?php if ($is_graded): ?>
                                    <?php
                                    $gradeClass = 'grade-excellent';
                                    if ($percentage < 75) $gradeClass = 'grade-good';
                                    if ($percentage < 50) $gradeClass = 'grade-fair';
                                    if ($percentage < 30) $gradeClass = 'grade-poor';
                                    ?>
                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                                        <span style="background: <?php echo $percentage >= 80 ? '#d1fae5' : ($percentage >= 60 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $percentage >= 80 ? '#065f46' : ($percentage >= 60 ? '#92400e' : '#991b1b'); ?>; padding: 8px 16px; border-radius: 12px; font-size: 1.1rem; font-weight: 700;">
                                            <?php echo $percentage; ?>%
                                        </span>
                                        <span style="color: #94a3b8; font-size: 0.85rem;">
                                            Graded: <?php echo $assignment['date']; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                                        <span style="background: #f1f5f9; color: #64748b; padding: 8px 16px; border-radius: 12px; font-size: 0.95rem; font-weight: 600;">
                                            Pending
                                        </span>
                                        <span style="color: #94a3b8; font-size: 0.85rem;">
                                            Submitted: <?php echo $assignment['date']; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- View Rubric Toggle Button -->
                            <?php if ($full_rubric && !empty($full_rubric['criteria'])): ?>
                            <button type="button" onclick="toggleRubricCard('<?php echo $cardId; ?>', '<?php echo $toggleIconId; ?>')" 
                                    style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;"
                                    onmouseover="this.style.background='#2563eb'" 
                                    onmouseout="this.style.background='#3b82f6'">
                                <i class="fas fa-chevron-down" id="<?php echo $toggleIconId; ?>"></i>
                                <span>View Rubric</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Rubric Card Body (Expandable) -->
                <?php if ($full_rubric && !empty($full_rubric['criteria'])): ?>
                <div id="<?php echo $cardId; ?>" style="display: none; background: #f8fafc; padding: 25px;">
                    <!-- Rubric Table -->
                    <div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                <tr>
                                    <th style="padding: 15px; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.9rem; width: 25%;">Criterion</th>
                                    <?php 
                                    // Get all levels from first criterion to determine column count
                                    $first_criterion = $full_rubric['criteria'][0];
                                    if (!empty($first_criterion['levels'])) {
                                        foreach ($first_criterion['levels'] as $level) {
                                            echo '<th style="padding: 15px; text-align: center; font-weight: 700; color: #1e293b; font-size: 0.9rem;">' . format_float($level->score, 0) . ' Points</th>';
                                        }
                                    }
                                    ?>
                                    <th style="padding: 15px; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.9rem; width: 20%;">Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($full_rubric['criteria'] as $criterion): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 15px; color: #475569; font-weight: 600; vertical-align: top;">
                                        <strong><?php echo format_string($criterion['description']); ?></strong>
                                    </td>
                                    
                                    <?php
                                    $selected_level_id = null;
                                    $criterion_comment = '';
                                    if (isset($existing_fillings[$criterion['id']])) {
                                        $selected_level_id = $existing_fillings[$criterion['id']]['levelid'];
                                        $criterion_comment = $existing_fillings[$criterion['id']]['remark'];
                                    }
                                    
                                    foreach ($criterion['levels'] as $level) {
                                        $is_selected = ($selected_level_id == $level->id);
                                        $cell_style = 'padding: 15px; text-align: center; vertical-align: top;';
                                        if ($is_selected) {
                                            $cell_style .= ' background: #dbeafe; border: 2px solid #3b82f6;';
                                        } else {
                                            $cell_style .= ' background: #f8fafc;';
                                        }
                                        echo '<td style="' . $cell_style . '">';
                                        echo '<div style="color: ' . ($is_selected ? '#1e40af' : '#64748b') . '; font-size: 0.9rem; line-height: 1.5;">';
                                        echo format_string($level->definition);
                                        if ($is_selected) {
                                            echo '<div style="margin-top: 8px; padding: 4px 8px; background: #3b82f6; color: white; border-radius: 6px; font-size: 0.8rem; font-weight: 600; display: inline-block;">Selected</div>';
                                        }
                                        echo '</div>';
                                        echo '</td>';
                                    }
                                    ?>
                                    
                                    <td style="padding: 15px; vertical-align: top;">
                                        <?php if ($criterion_comment): ?>
                                            <div style="color: #475569; font-size: 0.9rem; line-height: 1.5; background: #f8fafc; padding: 10px; border-radius: 8px;">
                                                <?php echo format_text($criterion_comment, FORMAT_HTML); ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="color: #cbd5e1; font-size: 0.85rem; font-style: italic;">No comments</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Score Summary -->
                    <?php if ($is_graded && $max_score > 0): ?>
                    <div style="margin-top: 20px; background: white; padding: 20px; border-radius: 12px; display: flex; justify-content: space-around; align-items: center; flex-wrap: wrap; gap: 20px;">
                        <div style="text-align: center;">
                            <div style="color: #64748b; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px;">Score</div>
                            <div style="color: #1e293b; font-size: 1.5rem; font-weight: 700;">
                                <?php echo number_format($total_score, 1); ?> / <?php echo number_format($max_score, 1); ?>
                            </div>
                        </div>
                        <div style="text-align: center;">
                            <div style="color: #64748b; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px;">Percentage</div>
                            <div style="color: <?php echo $percentage >= 80 ? '#10b981' : ($percentage >= 60 ? '#f59e0b' : '#ef4444'); ?>; font-size: 1.5rem; font-weight: 700;">
                                <?php echo $percentage; ?>%
                            </div>
                        </div>
                        <div style="flex: 1; max-width: 300px;">
                            <div style="width: 100%; height: 12px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                                <div style="height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); width: <?php echo $percentage; ?>%; transition: width 0.3s;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Fallback: Simple Criteria List -->
                <div id="<?php echo $cardId; ?>" style="display: none; background: #f8fafc; padding: 25px;">
                    <div style="background: white; border-radius: 12px; padding: 20px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                <tr>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Criterion</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Level Achieved</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Score</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Feedback</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignment['criteria'] as $criterion): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 12px; color: #475569; font-weight: 500;">
                                        <?php echo htmlspecialchars($criterion['name']); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 8px; font-size: 0.85rem; font-weight: 600;">
                                            <?php echo htmlspecialchars($criterion['level']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: center; font-weight: 700; color: #3b82f6;">
                                        <?php echo number_format($criterion['score'], 1); ?>
                                    </td>
                                    <td style="padding: 12px; color: #64748b; font-size: 0.9rem;">
                                        <?php echo !empty($criterion['remark']) ? htmlspecialchars($criterion['remark']) : '<span style="color: #cbd5e1;">No feedback</span>'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot style="background: #f8fafc; border-top: 2px solid #e2e8f0;">
                                <tr>
                                    <td colspan="2" style="padding: 12px; font-weight: 700; color: #1e293b;">Total</td>
                                    <td style="padding: 12px; text-align: center; font-weight: 700; color: #3b82f6; font-size: 1.3rem;">
                                        <?php echo number_format($total_score, 1); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1; height: 10px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                                                <div style="height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); width: <?php echo $percentage; ?>%;"></div>
                                            </div>
                                            <span style="font-weight: 700; color: #10b981;"><?php echo $percentage; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php else: ?>
        <!-- No Rubric Data -->
        <div class="chart-card-modern full-width">
            <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                <i class="fas fa-clipboard-list" style="font-size: 4rem; display: block; margin-bottom: 20px; opacity: 0.3;"></i>
                <h3 style="color: #64748b; margin: 0 0 10px 0;">No Rubric Assessments Found</h3>
                <p style="margin: 0; font-size: 0.95rem;">Rubric-based assessments will appear here once your assignments are graded using rubrics.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ATTENDANCE TAB -->
    <div class="tab-content-section" id="tab-attendance" style="display: none;">
        <!-- Attendance Summary Stats -->
        <div class="stats-grid-modern">
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-number"><?php echo $attendance_stats['rate']; ?>%</div>
                <div class="stat-label-modern">Attendance Rate</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-check"></i></div>
                <div class="stat-number"><?php echo $attendance_stats['present']; ?></div>
                <div class="stat-label-modern">Days Present</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-times"></i></div>
                <div class="stat-number"><?php echo $attendance_stats['absent']; ?></div>
                <div class="stat-label-modern">Days Absent</div>
            </div>
            <div class="stat-card-modern purple">
                <div class="stat-icon-modern"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-number"><?php echo count($attendance_weekly); ?></div>
                <div class="stat-label-modern">Weeks Tracked</div>
            </div>
            <div class="stat-card-modern pink">
                <div class="stat-icon-modern"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-number"><?php echo count($attendance_monthly); ?></div>
                <div class="stat-label-modern">Months Tracked</div>
            </div>
        </div>

        <!-- Attendance View Selector -->
        <div style="background: white; border-radius: 12px; padding: 15px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);">
            <div style="display: flex; gap: 10px;">
                <button class="attendance-view-btn active" data-view="daily" style="padding: 10px 20px; border-radius: 8px; background: #3b82f6; color: white; border: none; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-calendar-day"></i> Daily View
                </button>
                <button class="attendance-view-btn" data-view="weekly" style="padding: 10px 20px; border-radius: 8px; background: #f8fafc; color: #64748b; border: none; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-calendar-week"></i> Weekly View
                </button>
                <button class="attendance-view-btn" data-view="monthly" style="padding: 10px 20px; border-radius: 8px; background: #f8fafc; color: #64748b; border: none; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-calendar-alt"></i> Monthly View
                </button>
            </div>
        </div>
        
        <!-- DAILY ATTENDANCE VIEW (HOURLY) -->
        <div class="attendance-view-section" id="attendance-daily" style="display: block;">
            <div class="chart-card-modern full-width">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-clock"></i> Today's Activity by Hour</h3>
                    <p>Hourly breakdown of your activity today (24-hour view)</p>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="attendanceDailyChart"></canvas>
                </div>
            </div>
            
            <!-- Daily Attendance Table -->
            <div class="chart-card-modern full-width" style="margin-top: 25px;">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-table"></i> Daily Attendance Details</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <tr>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Date</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Day</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Status</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">First Login</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Last Action</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Courses</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($attendance_daily)): ?>
                            <?php foreach ($attendance_daily as $day): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; color: #475569;"><?php echo $day['date_formatted']; ?></td>
                                <td style="padding: 12px; color: #475569;"><?php echo $day['day_name']; ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                        <i class="fas fa-check-circle"></i> Present
                                    </span>
                                </td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['first_login']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['last_action']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #0284c7; font-weight: 600;"><?php echo $day['courses_accessed']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['total_actions']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" style="padding: 40px; text-align: center; color: #94a3b8;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                                    No daily attendance data available
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- WEEKLY ATTENDANCE VIEW (DAY BY DAY) -->
        <div class="attendance-view-section" id="attendance-weekly" style="display: none;">
            <div class="chart-card-modern full-width">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-calendar-week"></i> Last 7 Days Attendance</h3>
                    <p>Day-by-day breakdown for the past week</p>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="attendanceWeeklyChart"></canvas>
                </div>
            </div>
            
            <!-- Weekly Attendance Table -->
            <div class="chart-card-modern full-width" style="margin-top: 25px;">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-table"></i> Day-by-Day Details</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <tr>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Date</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Day</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Status</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">First Login</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Last Action</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Courses</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($attendance_weekly)): ?>
                            <?php foreach ($attendance_weekly as $day): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; color: #475569;"><?php echo $day['date_formatted']; ?></td>
                                <td style="padding: 12px; color: #475569; font-weight: 600;"><?php echo $day['day_name']; ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <?php if ($day['present']): ?>
                                    <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                        <i class="fas fa-check-circle"></i> Present
                                    </span>
                                    <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                        <i class="fas fa-times-circle"></i> Absent
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['first_login']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['last_action']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #0284c7; font-weight: 600;"><?php echo $day['courses_accessed']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $day['total_actions']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" style="padding: 40px; text-align: center; color: #94a3b8;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                                    No attendance data for the last 7 days
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- MONTHLY ATTENDANCE VIEW -->
        <div class="attendance-view-section" id="attendance-monthly" style="display: none;">
            <div class="chart-card-modern full-width">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-calendar-alt"></i> Monthly Attendance (Last 6 Months)</h3>
                    <p>Month-by-month attendance overview</p>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="attendanceMonthlyChart"></canvas>
                </div>
            </div>
            
            <!-- Monthly Attendance Table -->
            <div class="chart-card-modern full-width" style="margin-top: 25px;">
                <div class="chart-header-modern">
                    <h3><i class="fas fa-table"></i> Monthly Attendance Summary</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <tr>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #1e293b;">Month</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Days Present</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Attendance %</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Courses Accessed</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #1e293b;">Total Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($attendance_monthly)): ?>
                            <?php foreach ($attendance_monthly as $month): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; color: #475569; font-weight: 600;"><?php echo $month['month_name']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #0284c7; font-weight: 600;"><?php echo $month['days_present']; ?>/<?php echo $month['days_total']; ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                                        <div style="flex: 1; max-width: 120px; height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                                            <div style="height: 100%; background: linear-gradient(90deg, #10b981, #059669); width: <?php echo min(100, $month['percentage']); ?>%;"></div>
                                        </div>
                                        <span style="font-weight: 600; color: #10b981;"><?php echo $month['percentage']; ?>%</span>
                                    </div>
                                </td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $month['courses_accessed']; ?></td>
                                <td style="padding: 12px; text-align: center; color: #64748b;"><?php echo $month['total_actions']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" style="padding: 40px; text-align: center; color: #94a3b8;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                                    No monthly attendance data available
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- LEARNING INSIGHTS TAB -->
    <div class="tab-content-section" id="tab-insights" style="display: none;">
        <!-- Strengths -->
        <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #10b981; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(16, 185, 129, 0.15);">
            <h4 style="color: #059669; margin: 0 0 15px 0; font-size: 1.15rem; font-weight: 600;">
                <i class="fas fa-check-circle"></i> Your Strengths
            </h4>
            <?php if (!empty($learning_insights['strengths'])): ?>
            <ul style="margin: 0; padding-left: 25px; color: #065f46; line-height: 1.8; font-size: 0.95rem;">
                <?php foreach ($learning_insights['strengths'] as $strength): ?>
                <li style="margin-bottom: 10px;"><?php echo htmlspecialchars($strength); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p style="margin: 0; color: #065f46;">Keep building your strengths through consistent effort!</p>
            <?php endif; ?>
        </div>
        
        <!-- Areas for Growth -->
        <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(245, 158, 11, 0.15);">
            <h4 style="color: #d97706; margin: 0 0 15px 0; font-size: 1.15rem; font-weight: 600;">
                <i class="fas fa-exclamation-triangle"></i> Areas for Growth
            </h4>
            <?php if (!empty($learning_insights['improvements'])): ?>
            <ul style="margin: 0; padding-left: 25px; color: #92400e; line-height: 1.8; font-size: 0.95rem;">
                <?php foreach ($learning_insights['improvements'] as $improvement): ?>
                <li style="margin-bottom: 10px;"><?php echo htmlspecialchars($improvement); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p style="margin: 0; color: #92400e;">You're doing well! Keep maintaining your current pace.</p>
            <?php endif; ?>
        </div>
        
        <!-- Personalized Recommendations -->
        <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-left: 4px solid #3b82f6; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(59, 130, 246, 0.15);">
            <h4 style="color: #1d4ed8; margin: 0 0 15px 0; font-size: 1.15rem; font-weight: 600;">
                <i class="fas fa-star"></i> Personalized Recommendations
            </h4>
            <?php if (!empty($learning_insights['recommendations'])): ?>
            <ul style="margin: 0; padding-left: 25px; color: #1e3a8a; line-height: 1.8; font-size: 0.95rem;">
                <?php foreach ($learning_insights['recommendations'] as $recommendation): ?>
                <li style="margin-bottom: 10px;"><?php echo htmlspecialchars($recommendation); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p style="margin: 0; color: #1e3a8a;">Continue your excellent learning habits!</p>
            <?php endif; ?>
        </div>
        
        <!-- Learning Analytics Summary -->
        <div class="stats-grid-modern" style="margin-top: 25px;">
            <div class="stat-card-modern blue">
                <div class="stat-icon-modern"><i class="fas fa-chart-line"></i></div>
                <div class="stat-number"><?php echo count($learning_insights['strengths']); ?></div>
                <div class="stat-label-modern">Identified Strengths</div>
            </div>
            <div class="stat-card-modern orange">
                <div class="stat-icon-modern"><i class="fas fa-target"></i></div>
                <div class="stat-number"><?php echo count($learning_insights['improvements']); ?></div>
                <div class="stat-label-modern">Growth Areas</div>
            </div>
            <div class="stat-card-modern purple">
                <div class="stat-icon-modern"><i class="fas fa-lightbulb"></i></div>
                <div class="stat-number"><?php echo count($learning_insights['recommendations']); ?></div>
                <div class="stat-label-modern">Recommendations</div>
            </div>
            <div class="stat-card-modern green">
                <div class="stat-icon-modern"><i class="fas fa-trophy"></i></div>
                <div class="stat-number"><?php echo $average_grade; ?>%</div>
                <div class="stat-label-modern">Overall Average</div>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('🎨 Initializing modern reports charts...');

    // Performance Trend Chart (Line)
    const perfCtx = document.getElementById('performanceTrendChart');
    if (perfCtx) {
        new Chart(perfCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: [<?php 
                    if (!empty($reports)) {
                        $labels = array_map(function($r) { 
                            return '"' . htmlspecialchars(substr($r['coursename'], 0, 15), ENT_QUOTES) . '"'; 
                        }, $reports);
                        echo implode(',', $labels);
                    }
                ?>],
                datasets: [{
                    label: 'Course Progress (%)',
                    data: [<?php 
                        if (!empty($reports)) {
                            $data = array_map(function($r) { return $r['progress']; }, $reports);
                            echo implode(',', $data);
                        }
                    ?>],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                },
                {
                    label: 'Grade Percentage (%)',
                    data: [<?php 
                        if (!empty($reports)) {
                            $data = array_map(function($r) { return $r['grade_percentage'] ?? 0; }, $reports);
                            echo implode(',', $data);
                        }
                    ?>],
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 12 }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        max: 100,
                        ticks: { callback: function(value) { return value + '%'; } }
                    }
                }
            }
        });
    }

    // Course Progress Chart (Bar)
    const courseCtx = document.getElementById('courseProgressChart');
    if (courseCtx) {
        new Chart(courseCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: [<?php 
                    if (!empty($reports)) {
                        $labels = array_map(function($r) { 
                            return '"' . htmlspecialchars(substr($r['coursename'], 0, 20), ENT_QUOTES) . '"'; 
                        }, $reports);
                        echo implode(',', $labels);
                    }
                ?>],
                datasets: [{
                    label: 'Completion %',
                    data: [<?php 
                        if (!empty($reports)) {
                            $data = array_map(function($r) { return $r['progress']; }, $reports);
                            echo implode(',', $data);
                        }
                    ?>],
                    backgroundColor: ['#8b5cf6', '#ec4899', '#3b82f6', '#10b981', '#f59e0b', '#14b8a6'],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        max: 100,
                        ticks: { callback: function(value) { return value + '%'; } }
                    }
                }
            }
        });
    }

    // Grade Distribution Chart (Pie)
    const gradeCtx = document.getElementById('gradeDistributionChart');
    if (gradeCtx) {
        new Chart(gradeCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress', 'Not Started'],
                datasets: [{
                    data: [
                        <?php echo $completed_courses; ?>,
                        <?php echo ($total_courses - $completed_courses > 0 ? $total_courses - $completed_courses : 0); ?>,
                        0
                    ],
                    backgroundColor: ['#10b981', '#3b82f6', '#94a3b8'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { 
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
    }

    // Competency Progress Chart (Radar) - REAL DATA
    const compCtx = document.getElementById('competencyProgressChart');
    if (compCtx) {
        <?php if ($has_competencies && count($competency_data) >= 3): ?>
        // Use real competency data
        new Chart(compCtx.getContext('2d'), {
            type: 'radar',
            data: {
                labels: [<?php 
                    $comp_labels = array_map(function($c) { 
                        return '"' . htmlspecialchars(substr($c['name'], 0, 20), ENT_QUOTES) . '"'; 
                    }, array_slice($competency_data, 0, 6));
                    echo implode(',', $comp_labels);
                ?>],
                datasets: [{
                    label: 'Your Competencies',
                    data: [<?php 
                        $comp_values = array_map(function($c) { return $c['percentage']; }, array_slice($competency_data, 0, 6));
                        echo implode(',', $comp_values);
                    ?>],
                    backgroundColor: 'rgba(139, 92, 246, 0.2)',
                    borderColor: '#8b5cf6',
                    borderWidth: 3,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            callback: function(value) { return value + '%'; }
                        }
                    }
                }
            }
        });
        <?php else: ?>
        // No competency data - use calculated skills from performance
        new Chart(compCtx.getContext('2d'), {
            type: 'radar',
            data: {
                labels: ['Critical Thinking', 'Problem Solving', 'Communication', 'Collaboration', 'Creativity', 'Leadership'],
                datasets: [{
                    label: 'Your Skills (Calculated)',
                    data: [
                        <?php echo $skills_data['critical_thinking']; ?>,
                        <?php echo $skills_data['problem_solving']; ?>,
                        <?php echo $skills_data['communication']; ?>,
                        <?php echo $skills_data['collaboration']; ?>,
                        <?php echo $skills_data['creativity']; ?>,
                        <?php echo $skills_data['leadership']; ?>
                    ],
                    backgroundColor: 'rgba(139, 92, 246, 0.2)',
                    borderColor: '#8b5cf6',
                    borderWidth: 3,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            callback: function(value) { return value + '%'; }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        console.log('✅ Competency chart created');
    }

    // Rubric Assessment Chart (Horizontal Bar) - REAL DATA
    const rubricCtx = document.getElementById('rubricAssessmentChart');
    if (rubricCtx) {
        <?php if ($has_rubrics && !empty($rubric_summary)): ?>
        // Use real rubric summary data
        new Chart(rubricCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: [<?php 
                    $labels = array_map(function($r) { 
                        return '"' . htmlspecialchars(substr($r['criterion'], 0, 20), ENT_QUOTES) . '"'; 
                    }, array_slice($rubric_summary, 0, 6));
                    echo implode(',', $labels);
                ?>],
                datasets: [{
                    label: 'Average Score',
                    data: [<?php 
                        $scores = array_map(function($r) { return $r['average_score']; }, array_slice($rubric_summary, 0, 6));
                        echo implode(',', $scores);
                    ?>],
                    backgroundColor: [
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(236, 72, 153, 0.8)',
                        'rgba(20, 184, 166, 0.8)'
                    ],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { 
                        beginAtZero: true,
                        ticks: { callback: function(value) { return value; } }
                    }
                }
            }
        });
        <?php else: ?>
        // No rubric data - show empty state
        new Chart(rubricCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['No Data'],
                datasets: [{
                    label: 'Score',
                    data: [0],
                    backgroundColor: ['rgba(203, 213, 225, 0.8)'],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 10 }
                }
            }
        });
        <?php endif; ?>
        console.log('✅ Rubric chart created (real data)');
    }

    console.log('✅ All charts initialized!');

    // Competency Tree View Toggle Function
    window.toggleCompetency = function(compId) {
        const childrenDiv = document.getElementById(compId);
        const icon = document.getElementById('icon_' + compId);
        
        if (childrenDiv && icon) {
            if (childrenDiv.style.display === 'none' || childrenDiv.style.display === '') {
                // Expand
                childrenDiv.style.display = 'block';
                icon.style.transform = 'rotate(90deg)';
                icon.parentElement.style.background = '#e0e7ff';
            } else {
                // Collapse
                childrenDiv.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
                icon.parentElement.style.background = '#f1f5f9';
            }
        }
    };
    
    // Expand/Collapse All Competencies
    window.expandAllCompetencies = function() {
        document.querySelectorAll('.competency-children').forEach(el => {
            el.style.display = 'block';
        });
        document.querySelectorAll('[id^="icon_comp_"]').forEach(icon => {
            icon.style.transform = 'rotate(90deg)';
            if (icon.parentElement) {
                icon.parentElement.style.background = '#e0e7ff';
            }
        });
    };
    
    window.collapseAllCompetencies = function() {
        document.querySelectorAll('.competency-children').forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll('[id^="icon_comp_"]').forEach(icon => {
            icon.style.transform = 'rotate(0deg)';
            if (icon.parentElement) {
                icon.parentElement.style.background = '#f1f5f9';
            }
        });
    };

    // Tab switching functionality
    const tabItems = document.querySelectorAll('.nav-tab-item');
    const tabSections = document.querySelectorAll('.tab-content-section');
    
    tabItems.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs
            tabItems.forEach(t => t.classList.remove('active'));
            // Add active class to clicked tab
            this.classList.add('active');
            
            const tabName = this.getAttribute('data-tab');
            console.log('Switched to tab:', tabName);
            
            // Hide all tab content sections
            tabSections.forEach(section => {
                section.style.display = 'none';
            });
            
            // Show the selected tab content
            const targetSection = document.getElementById('tab-' + tabName);
            if (targetSection) {
                targetSection.style.display = 'block';
                
                // Add fade-in animation
                targetSection.style.opacity = '0';
                setTimeout(() => {
                    targetSection.style.transition = 'opacity 0.4s ease';
                    targetSection.style.opacity = '1';
                }, 50);
            }
            
            // Initialize charts specific to this tab if needed
            if (tabName === 'general' && !window.generalChartInit) {
                initGeneralAssessmentChart();
                window.generalChartInit = true;
            } else if (tabName === 'quizzes' && !window.quizChartInit) {
                initQuizAnalysisChart();
                window.quizChartInit = true;
            } else if (tabName === 'assignments' && !window.assignmentChartInit) {
                initAssignmentAnalysisChart();
                window.assignmentChartInit = true;
            } else if (tabName === 'scorm' && !window.scormChartInit) {
                initScormProgressChart();
                window.scormChartInit = true;
            } else if (tabName === 'competencies' && !window.compChart2Init) {
                initCompetencyChart2();
                window.compChart2Init = true;
            } else if (tabName === 'rubrics' && !window.rubricChart2Init) {
                initRubricChart2();
                window.rubricChart2Init = true;
            } else if (tabName === 'attendance' && !window.attendanceChartInit) {
                initAttendanceChart();
                window.attendanceChartInit = true;
            }
        });
    });

    // Chart initialization functions for lazy loading - ALL USE REAL DATA
    function initGeneralAssessmentChart() {
        const ctx = document.getElementById('generalAssessmentChart');
        if (ctx) {
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [<?php 
                        if (!empty($reports)) {
                            $labels = array_map(function($r) { 
                                return '"' . htmlspecialchars(substr($r['coursename'], 0, 12), ENT_QUOTES) . '"'; 
                            }, $reports);
                            echo implode(',', $labels);
} else {
                            echo '"No Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Performance Trend (%)',
                        data: [<?php 
                            if (!empty($reports)) {
                                $data = array_map(function($r) { return $r['grade_percentage'] ?? $r['progress']; }, $reports);
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.15)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    scales: {
                        y: { beginAtZero: true, max: 100 }
                    }
                }
            });
        }
    }

    function initQuizAnalysisChart() {
        const ctx = document.getElementById('quizAnalysisChart');
        if (ctx) {
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php 
                        if (!empty($grade_reports['quizzes'])) {
                            $labels = array_map(function($q) { 
                                return '"' . htmlspecialchars(substr($q['itemname'], 0, 15), ENT_QUOTES) . '"'; 
                            }, $grade_reports['quizzes']);
                            echo implode(',', $labels);
                        } else {
                            echo '"No Quiz Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Quiz Scores (%)',
                        data: [<?php 
                            if (!empty($grade_reports['quizzes'])) {
                                $data = array_map(function($q) { return $q['percentage']; }, $grade_reports['quizzes']);
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        backgroundColor: ['#8b5cf6', '#ec4899', '#3b82f6', '#10b981', '#f59e0b', '#14b8a6'],
                        borderRadius: 8
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    }

    function initAssignmentAnalysisChart() {
        const ctx = document.getElementById('assignmentAnalysisChart');
        if (ctx) {
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [<?php 
                        if (!empty($grade_reports['assignments'])) {
                            $labels = array_map(function($a) { 
                                return '"' . htmlspecialchars(substr($a['itemname'], 0, 15), ENT_QUOTES) . '"'; 
                            }, $grade_reports['assignments']);
                            echo implode(',', $labels);
                        } else {
                            echo '"No Assignment Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Assignment Scores (%)',
                        data: [<?php 
                            if (!empty($grade_reports['assignments'])) {
                                $data = array_map(function($a) { return $a['percentage']; }, $grade_reports['assignments']);
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.15)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    }

    function initScormProgressChart() {
        const ctx = document.getElementById('scormProgressChart');
        if (ctx) {
            <?php if ($has_scorm && !empty($scorm_data)): ?>
            // Real SCORM data - Doughnut chart showing completion status
            const completed = <?php echo $scorm_stats['completed']; ?>;
            const started = <?php echo $scorm_stats['started'] - $scorm_stats['completed']; ?>;
            const notStarted = <?php echo $scorm_stats['total'] - $scorm_stats['started']; ?>;
            
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'In Progress', 'Not Started'],
                    datasets: [{
                        data: [completed, started, notStarted],
                        backgroundColor: ['#10b981', '#f59e0b', '#e2e8f0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 15, font: { size: 13 } }
                        }
                    }
                }
            });
            <?php else: ?>
            // No SCORM data - empty chart
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['No Data'],
                    datasets: [{
                        data: [1],
                        backgroundColor: ['#f1f5f9'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } }
                }
            });
            <?php endif; ?>
        }
    }

    // 4 DIFFERENT COMPETENCY CHARTS WITH SWITCH FUNCTION
    function initCompetencyChart2() {
        <?php if ($has_competencies && count($competency_data) >= 3): ?>
        // Prepare competency data for all chart types
        const compLabels = [<?php 
            $comp_labels = array_map(function($c) { 
                return '"' . htmlspecialchars(substr($c['name'], 0, 20), ENT_QUOTES) . '"'; 
            }, array_slice($competency_data, 0, 8));
            echo implode(',', $comp_labels);
        ?>];
        
        const compValues = [<?php 
            $comp_values = array_map(function($c) { return $c['percentage']; }, array_slice($competency_data, 0, 8));
            echo implode(',', $comp_values);
        ?>];
        
        // Color palette for charts
        const compColors = [
            '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', 
            '#10b981', '#06b6d4', '#6366f1', '#14b8a6'
        ];
        
        // 1. BAR CHART
        const ctxBar = document.getElementById('competencyProgressChartBar');
        if (ctxBar) {
            new Chart(ctxBar.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: compLabels,
                    datasets: [{
                        label: 'Proficiency (%)',
                        data: compValues,
                        backgroundColor: compColors,
                        borderColor: compColors,
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: true },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Proficiency: ' + context.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            ticks: {
                                callback: function(value) { return value + '%'; }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        }
        
        // 2. DOUGHNUT CHART
        const ctxDoughnut = document.getElementById('competencyProgressChartDoughnut');
        if (ctxDoughnut) {
            new Chart(ctxDoughnut.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: compLabels,
                    datasets: [{
                        label: 'Proficiency',
                        data: compValues,
                        backgroundColor: compColors,
                        borderColor: '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { 
                            display: true,
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // 3. RADAR CHART
        const ctxRadar = document.getElementById('competencyProgressChartRadar');
        if (ctxRadar) {
            new Chart(ctxRadar.getContext('2d'), {
                type: 'radar',
                data: {
                    labels: compLabels,
                    datasets: [{
                        label: 'Your Competencies',
                        data: compValues,
                        backgroundColor: 'rgba(139, 92, 246, 0.2)',
                        borderColor: '#8b5cf6',
                        borderWidth: 3,
                        pointBackgroundColor: compColors,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: { 
                        r: { 
                            beginAtZero: true, 
                            max: 100,
                            ticks: {
                                stepSize: 20,
                                callback: function(value) { return value + '%'; }
                            }
                        } 
                    }
                }
            });
        }
        
        // 4. LINE CHART
        const ctxLine = document.getElementById('competencyProgressChartLine');
        if (ctxLine) {
            new Chart(ctxLine.getContext('2d'), {
                type: 'line',
                data: {
                    labels: compLabels,
                    datasets: [{
                        label: 'Proficiency Trend',
                        data: compValues,
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderColor: '#3b82f6',
                        borderWidth: 3,
                        pointBackgroundColor: compColors,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            ticks: {
                                callback: function(value) { return value + '%'; }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        }
        <?php else: ?>
        // No competency data - show empty state
        console.log('No competency data available');
        <?php endif; ?>
        
        console.log('✅ All 4 competency charts created (real data)');
    }
    
    // Switch between competency chart types
    window.switchCompetencyChart = function(chartType) {
        // Hide all charts
        document.getElementById('compChartBar').style.display = 'none';
        document.getElementById('compChartDoughnut').style.display = 'none';
        document.getElementById('compChartRadar').style.display = 'none';
        document.getElementById('compChartLine').style.display = 'none';
        
        // Reset all buttons
        const allBtns = document.querySelectorAll('.chart-type-btn');
        allBtns.forEach(btn => {
            btn.style.background = 'white';
            btn.style.color = '#64748b';
            btn.style.border = '1px solid #e2e8f0';
        });
        
        // Show selected chart and highlight button
        switch(chartType) {
            case 'bar':
                document.getElementById('compChartBar').style.display = 'block';
                document.getElementById('btnCompBar').style.background = '#3b82f6';
                document.getElementById('btnCompBar').style.color = 'white';
                document.getElementById('btnCompBar').style.border = 'none';
                break;
            case 'doughnut':
                document.getElementById('compChartDoughnut').style.display = 'block';
                document.getElementById('btnCompDoughnut').style.background = '#8b5cf6';
                document.getElementById('btnCompDoughnut').style.color = 'white';
                document.getElementById('btnCompDoughnut').style.border = 'none';
                break;
            case 'radar':
                document.getElementById('compChartRadar').style.display = 'block';
                document.getElementById('btnCompRadar').style.background = '#ec4899';
                document.getElementById('btnCompRadar').style.color = 'white';
                document.getElementById('btnCompRadar').style.border = 'none';
                break;
            case 'line':
                document.getElementById('compChartLine').style.display = 'block';
                document.getElementById('btnCompLine').style.background = '#10b981';
                document.getElementById('btnCompLine').style.color = 'white';
                document.getElementById('btnCompLine').style.border = 'none';
                break;
        }
    };

    function initRubricChart2() {
        const ctx = document.getElementById('rubricAssessmentChart2');
        if (ctx) {
            <?php if ($has_rubrics && !empty($rubric_summary)): ?>
            // Use real rubric data from Moodle
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php 
                        $rubric_labels = array_map(function($r) { 
                            return '"' . htmlspecialchars(substr($r['criterion'], 0, 25), ENT_QUOTES) . '"'; 
                        }, array_slice($rubric_summary, 0, 8));
                        echo implode(',', $rubric_labels);
                    ?>],
                    datasets: [{
                        label: 'Average Score',
                        data: [<?php 
                            $rubric_scores = array_map(function($r) { return $r['average_score']; }, array_slice($rubric_summary, 0, 8));
                            echo implode(',', $rubric_scores);
                        ?>],
                        backgroundColor: ['#8b5cf6', '#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#14b8a6', '#8b5cf6', '#3b82f6'],
                        borderRadius: 8
                    }]
                },
                options: { 
                    indexAxis: 'y',
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    const scores = [<?php 
                                        echo implode(',', array_map(function($r) { return $r['average_score']; }, array_slice($rubric_summary, 0, 8)));
                                    ?>];
                                    return 'Average: ' + scores[index] + ' points';
                                }
                            }
                        }
                    },
                    scales: { 
                        x: { 
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Average Score'
                            }
                        }
                    }
                }
            });
            <?php else: ?>
            // No rubric data - show placeholder
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['No Data'],
                    datasets: [{
                        label: 'Score',
                        data: [0],
                        backgroundColor: ['#e2e8f0'],
                        borderRadius: 8
                    }]
                },
                options: { 
                    indexAxis: 'y',
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, max: 10 } }
                }
            });
            <?php endif; ?>
        }
    }

    function initAttendanceChart() {
        // Initialize Daily Attendance Chart (HOURLY - 24 hours)
        const dailyCtx = document.getElementById('attendanceDailyChart');
        if (dailyCtx) {
            new Chart(dailyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php 
                        if (!empty($attendance_hourly)) {
                            $labels = array_map(function($h) { 
                                return '"' . $h['hour_label'] . '"'; 
                            }, $attendance_hourly);
                            echo implode(',', $labels);
                        } else {
                            echo '"No Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Activity Count',
                        data: [<?php 
                            if (!empty($attendance_hourly)) {
                                $data = array_map(function($h) { return $h['activity_count']; }, $attendance_hourly);
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        backgroundColor: function(context) {
                            const value = context.parsed.y;
                            if (value === 0) return '#e2e8f0';
                            if (value < 5) return '#93c5fd';
                            if (value < 15) return '#60a5fa';
                            return '#3b82f6';
                        },
                        borderRadius: 6,
                        barThickness: 20
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Activities: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            ticks: { 
                                stepSize: 5,
                                callback: function(value) { return value; }
                            },
                            title: {
                                display: true,
                                text: 'Number of Activities'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Hour of Day'
                            }
                        }
                    }
                }
            });
        }

        // Initialize Weekly Attendance Chart (7 DAYS)
        const weeklyCtx = document.getElementById('attendanceWeeklyChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php 
                        if (!empty($attendance_weekly)) {
                            $labels = array_map(function($d) { 
                                return '"' . htmlspecialchars($d['day_name'], ENT_QUOTES) . '"'; 
                            }, $attendance_weekly);
                            echo implode(',', $labels);
                        } else {
                            echo '"No Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Attendance Status',
                        data: [<?php 
                            if (!empty($attendance_weekly)) {
                                $data = array_map(function($d) { return $d['present'] ? 1 : 0; }, $attendance_weekly);
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        backgroundColor: function(context) {
                            const value = context.parsed.y;
                            return value === 1 ? '#10b981' : '#ef4444';
                        },
                        borderRadius: 8,
                        barThickness: 50
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y === 1 ? 'Present' : 'Absent';
                                }
                            }
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            max: 1.2,
                            ticks: { 
                                stepSize: 1,
                                callback: function(value) { 
                                    return value === 1 ? 'Present' : value === 0 ? 'Absent' : '';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Attendance Status'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Day of Week'
                            }
                        }
                    }
                }
            });
        }

        // Initialize Monthly Attendance Chart
        const monthlyCtx = document.getElementById('attendanceMonthlyChart');
        if (monthlyCtx) {
            new Chart(monthlyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php 
                        if (!empty($attendance_monthly)) {
                            $labels = array_map(function($m) { 
                                return '"' . htmlspecialchars($m['month_name'], ENT_QUOTES) . '"'; 
                            }, array_reverse($attendance_monthly));
                            echo implode(',', $labels);
                        } else {
                            echo '"No Data"';
                        }
                    ?>],
                    datasets: [{
                        label: 'Monthly Attendance %',
                        data: [<?php 
                            if (!empty($attendance_monthly)) {
                                $data = array_map(function($m) { return $m['percentage']; }, array_reverse($attendance_monthly));
                                echo implode(',', $data);
                            } else {
                                echo '0';
                            }
                        ?>],
                        backgroundColor: ['#8b5cf6', '#ec4899', '#3b82f6', '#10b981', '#f59e0b', '#14b8a6'],
                        borderRadius: 8
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            ticks: { callback: function(value) { return value + '%'; } }
                        } 
                    }
                }
            });
        }
        
        // Attendance view switching
        const attendanceViewBtns = document.querySelectorAll('.attendance-view-btn');
        const attendanceViews = document.querySelectorAll('.attendance-view-section');
        
        attendanceViewBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Update button styles
                attendanceViewBtns.forEach(b => {
                    b.style.background = '#f8fafc';
                    b.style.color = '#64748b';
                    b.classList.remove('active');
                });
                this.style.background = '#3b82f6';
                this.style.color = 'white';
                this.classList.add('active');
                
                // Show selected view
                const viewType = this.getAttribute('data-view');
                attendanceViews.forEach(view => {
                    view.style.display = 'none';
                });
                const targetView = document.getElementById('attendance-' + viewType);
                if (targetView) {
                    targetView.style.display = 'block';
                }
            });
        });
    }

    // Add animation to stat cards
    const statCards = document.querySelectorAll('.stat-card-modern');
    statCards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50);
        }, index * 100);
    });
    
    // Toggle rubric card visibility
    window.toggleRubricCard = function(cardBodyId, iconId) {
        const cardBody = document.getElementById(cardBodyId);
        const icon = document.getElementById(iconId);
        
        if (!cardBody || !icon) return;
        
        const isExpanded = cardBody.style.display !== 'none';
        
        if (isExpanded) {
            cardBody.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            // Update button text
            const btn = icon.closest('button');
            if (btn) {
                const span = btn.querySelector('span');
                if (span) span.textContent = 'View Rubric';
            }
        } else {
            cardBody.style.display = 'block';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
            // Update button text
            const btn = icon.closest('button');
            if (btn) {
                const span = btn.querySelector('span');
                if (span) span.textContent = 'Hide Rubric';
            }
        }
    };
});
</script>
<?php
echo $OUTPUT->footer();
?>