<?php
// Use core_completion\progress for progress calculation
use core_completion\progress;

require_once('../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
require_login();

global $USER, $DB, $OUTPUT, $PAGE, $CFG;

$PAGE->set_url('/theme/remui_kids/middleschool_myreports.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Reports - Middle School');
$PAGE->add_body_class('custom-dashboard-page has-student-sidebar middleschool-reports-page');
$PAGE->requires->css('/theme/remui_kids/style/middleschool_reports.css');

// Initialize grade report objects
require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/grade/report/user/lib.php');

// Initialize all data arrays to prevent undefined variable errors
$dashboard_stats = [];
$grade_analytics = [];
$course_performance = [];
$professional_analytics = [];
$real_time_data = [];
$grade_reports = [];
$reports = [];
$totalassignments = 0;
$completedassignments = 0;
$totalquizzes = 0;
$completedquizzes = 0;
$has_real_data = false;

// Debug: Log that the script is starting
error_log("Middle School MyReports: Script started for user " . $USER->id . " (" . fullname($USER) . ")");

// Check if IOMAD is installed
$iomad_installed = false;
try {
    $iomad_tables = ['iomad_courses', 'iomad_company', 'iomad_company_users'];
    $iomad_installed = true;
    foreach ($iomad_tables as $table) {
        if (!$DB->get_manager()->table_exists($table)) {
            $iomad_installed = false;
            break;
        }
    }
    error_log("Middle School MyReports: IOMAD " . ($iomad_installed ? 'DETECTED' : 'NOT FOUND'));
} catch (Exception $e) {
    error_log("Middle School MyReports: IOMAD check failed - " . $e->getMessage());
}

try {
    // Get user's enrolled courses with comprehensive data
    $courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname, enablecompletion, category, startdate, enddate, summary, timecreated, timemodified');
    
    error_log("Middle School MyReports: Found " . count($courses) . " courses for user " . $USER->id);
    
    // Initialize comprehensive dashboard statistics
    $dashboard_stats = [
        'total_courses' => count($courses),
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
        'total_activities' => 0,
        'completed_activities' => 0
    ];
    
    // Initialize grade analytics
    $grade_analytics = [
        'overall_average' => 0,
        'grade_distribution' => [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'F' => 0
        ],
        'highest_grade' => 0,
        'lowest_grade' => 100,
        'recent_grades' => []
    ];
    
    // Initialize course performance array
    $course_performance = [];
    
    $total_grades = 0;
    $grade_count = 0;
    
    // Collect data from each course
    foreach ($courses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        
        error_log("Middle School MyReports: Processing course " . $course->id . " - " . $course->fullname);
        
        try {
            $coursecontext = context_course::instance($course->id);
            
            // Get course completion
            $completion = new completion_info($course);
            $course_complete = false;
            
            if ($completion->is_enabled()) {
                $course_complete = $completion->is_course_complete($USER->id);
                if ($course_complete) {
                    $dashboard_stats['completed_courses']++;
                }
            }
            
            // Get course progress percentage
            $progress_percentage = 0;
            try {
                if (class_exists('core_completion\progress')) {
                    $progress_percentage = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
                }
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error getting progress for course {$course->id}: " . $e->getMessage());
            }
            
            // Get course grade
            $course_grade = 0;
            $course_grade_percentage = 0;
            $course_grade_letter = '';
            
            try {
                $sql = "SELECT * FROM {grade_items} WHERE courseid = ? AND itemtype = 'course'";
                $course_grade_item = $DB->get_record_sql($sql, [(int)$course->id]);
                
                if ($course_grade_item) {
                    $sql = "SELECT * FROM {grade_grades} WHERE itemid = ? AND userid = ?";
                    $grade_record = $DB->get_record_sql($sql, [(int)$course_grade_item->id, (int)$USER->id]);
                    
                    if ($grade_record && $grade_record->finalgrade !== null) {
                        $course_grade = (float)$grade_record->finalgrade;
                        if ($course_grade_item->grademax > 0) {
                            $course_grade_percentage = round(($course_grade / $course_grade_item->grademax) * 100, 1);
                            $total_grades += $course_grade_percentage;
                            $grade_count++;
                            
                            // Update highest/lowest grades
                            if ($course_grade_percentage > $grade_analytics['highest_grade']) {
                                $grade_analytics['highest_grade'] = $course_grade_percentage;
                            }
                            if ($course_grade_percentage < $grade_analytics['lowest_grade']) {
                                $grade_analytics['lowest_grade'] = $course_grade_percentage;
                            }
                            
                            // Calculate grade distribution
                            if ($course_grade_percentage >= 90) {
                                $grade_analytics['grade_distribution']['A']++;
                            } elseif ($course_grade_percentage >= 80) {
                                $grade_analytics['grade_distribution']['B']++;
                            } elseif ($course_grade_percentage >= 70) {
                                $grade_analytics['grade_distribution']['C']++;
                            } elseif ($course_grade_percentage >= 60) {
                                $grade_analytics['grade_distribution']['D']++;
                            } else {
                                $grade_analytics['grade_distribution']['F']++;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error fetching course grade for {$course->id}: " . $e->getMessage());
            }
            
            // Get course activities
            $modinfo = get_fast_modinfo($course);
            $total_activities = 0;
            $completed_activities = 0;
            $assignments_count = 0;
            $completed_assignments_count = 0;
            $quizzes_count = 0;
            $completed_quizzes_count = 0;
            
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                
                $total_activities++;
                
                // Check completion
                $completion_data = $completion->get_data($cm, false, $USER->id);
                if ($completion_data->completionstate == COMPLETION_COMPLETE || 
                    $completion_data->completionstate == COMPLETION_COMPLETE_PASS) {
                    $completed_activities++;
                }
                
                // Count assignments
                if ($cm->modname == 'assign') {
                    $assignments_count++;
                    if ($completion_data->completionstate == COMPLETION_COMPLETE || 
                        $completion_data->completionstate == COMPLETION_COMPLETE_PASS) {
                        $completed_assignments_count++;
                    }
                }
                
                // Count quizzes
                if ($cm->modname == 'quiz') {
                    $quizzes_count++;
                    if ($completion_data->completionstate == COMPLETION_COMPLETE || 
                        $completion_data->completionstate == COMPLETION_COMPLETE_PASS) {
                        $completed_quizzes_count++;
                    }
                }
            }
            
            // Add to dashboard stats
            $dashboard_stats['total_activities'] += $total_activities;
            $dashboard_stats['completed_activities'] += $completed_activities;
            $dashboard_stats['total_assignments'] += $assignments_count;
            $dashboard_stats['completed_assignments'] += $completed_assignments_count;
            $dashboard_stats['total_quizzes'] += $quizzes_count;
            $dashboard_stats['completed_quizzes'] += $completed_quizzes_count;
            
            // Get course teachers
            $teachers = [];
            try {
                $enrolled_users = get_enrolled_users($coursecontext, 'moodle/course:update');
                foreach ($enrolled_users as $teacher) {
                    $teachers[] = fullname($teacher);
                }
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error fetching teachers for course {$course->id}: " . $e->getMessage());
            }
            
            // Get last access time
            $last_access = '';
            try {
                $sql = "SELECT timeaccess FROM {user_lastaccess} WHERE userid = ? AND courseid = ?";
                $last_access_record = $DB->get_record_sql($sql, [(int)$USER->id, (int)$course->id]);
                if ($last_access_record) {
                    $last_access = userdate($last_access_record->timeaccess, '%d %B %Y');
                }
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error fetching last access for course {$course->id}: " . $e->getMessage());
            }
            
            // Count SCORM activities
            $scorm_count = 0;
            $completed_scorm_count = 0;
            try {
                $scorm_count = $DB->count_records('scorm', ['course' => $course->id]);
                
                $sql = "SELECT COUNT(DISTINCT s.id) as completed
                        FROM {scorm} s
                        JOIN {scorm_scoes_track} sst ON sst.scormid = s.id
                        WHERE s.course = ? AND sst.userid = ? 
                        AND sst.element = 'cmi.core.lesson_status'
                        AND sst.value IN ('completed', 'passed')";
                $completed_scorm = $DB->get_record_sql($sql, [(int)$course->id, (int)$USER->id]);
                $completed_scorm_count = $completed_scorm ? $completed_scorm->completed : 0;
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error fetching SCORM data for course {$course->id}: " . $e->getMessage());
            }
            
            // Count forum posts
            $forum_posts = 0;
            try {
                $sql = "SELECT COUNT(*) as posts
                        FROM {forum_posts} fp
                        JOIN {forum_discussions} fd ON fd.id = fp.discussion
                        JOIN {forum} f ON f.id = fd.forum
                        WHERE f.course = ? AND fp.userid = ?";
                $forum_data = $DB->get_record_sql($sql, [(int)$course->id, (int)$USER->id]);
                $forum_posts = $forum_data ? $forum_data->posts : 0;
            } catch (Exception $e) {
                error_log("Middle School MyReports: Error fetching forum posts for course {$course->id}: " . $e->getMessage());
            }
            
            // Get IOMAD-specific course data
            $iomad_data = [];
            if ($iomad_installed) {
                try {
                    $sql = "SELECT ic.name as company_name
                            FROM {iomad_company} ic
                            JOIN {iomad_courses} icc ON ic.id = icc.companyid
                            WHERE icc.courseid = ?";
                    $company_info = $DB->get_record_sql($sql, [(int)$course->id]);
                    if ($company_info) {
                        $iomad_data['company'] = $company_info->company_name;
                    }
                    
                    $sql = "SELECT coursetype FROM {iomad_courses} WHERE courseid = ?";
                    $course_type = $DB->get_record_sql($sql, [(int)$course->id]);
                    if ($course_type) {
                        $iomad_data['course_type'] = $course_type->coursetype;
                    }
                } catch (Exception $e) {
                    error_log("Middle School MyReports: IOMAD data fetch error for course {$course->id}: " . $e->getMessage());
                }
            }
            
            // Calculate completion percentage
            $completion_percentage = $total_activities > 0 ? round(($completed_activities / $total_activities) * 100, 1) : 0;
            
            $has_real_data = true;
            
            // Add course performance data with all real data
            $course_performance[] = [
                'course_id' => (int)$course->id,
                'course_name' => (string)$course->fullname,
                'course_shortname' => (string)$course->shortname,
                'progress_percentage' => $progress_percentage !== null ? round($progress_percentage, 1) : 0,
                'grade_percentage' => $course_grade_percentage,
                'total_activities' => $total_activities,
                'completed_activities' => $completed_activities,
                'assignments' => $assignments_count,
                'completed_assignments' => $completed_assignments_count,
                'quizzes' => $quizzes_count,
                'completed_quizzes' => $completed_quizzes_count,
                'scorm_activities' => $scorm_count,
                'completed_scorm' => $completed_scorm_count,
                'forum_posts' => $forum_posts,
                'is_complete' => $course_complete,
                'teachers' => implode(', ', $teachers),
                'last_access' => $last_access,
                'start_date' => $course->startdate > 0 ? userdate($course->startdate, '%d %B %Y') : 'Not set',
                'completion_percentage' => $completion_percentage,
                'iomad_company' => isset($iomad_data['company']) ? $iomad_data['company'] : null,
                'iomad_course_type' => isset($iomad_data['course_type']) ? $iomad_data['course_type'] : null,
                'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out()
            ];
            
            // Add to aggregated stats
            $totalassignments += $assignments_count;
            $completedassignments += $completed_assignments_count;
            $totalquizzes += $quizzes_count;
            $completedquizzes += $completed_quizzes_count;
            
        } catch (Exception $e) {
            error_log("Middle School MyReports: Error processing course {$course->id}: " . $e->getMessage());
            continue;
        }
    }
    
    // Calculate averages
    if ($grade_count > 0) {
        $dashboard_stats['average_grade'] = round($total_grades / $grade_count, 1);
        $grade_analytics['overall_average'] = round($total_grades / $grade_count, 1);
    }
    
    if ($dashboard_stats['total_activities'] > 0) {
        $dashboard_stats['completion_rate'] = round(($dashboard_stats['completed_activities'] / $dashboard_stats['total_activities']) * 100, 1);
    }
    
    if ($dashboard_stats['total_assignments'] > 0) {
        $dashboard_stats['assignment_completion_rate'] = round(($dashboard_stats['completed_assignments'] / $dashboard_stats['total_assignments']) * 100, 1);
    }
    
    if ($dashboard_stats['total_quizzes'] > 0) {
        $dashboard_stats['quiz_completion_rate'] = round(($dashboard_stats['completed_quizzes'] / $dashboard_stats['total_quizzes']) * 100, 1);
    }
    
    // Add SCORM and forum data to dashboard stats
    $dashboard_stats['total_scorm'] = array_sum(array_column($course_performance, 'scorm_activities'));
    $dashboard_stats['completed_scorm'] = array_sum(array_column($course_performance, 'completed_scorm'));
    $dashboard_stats['total_forum_posts'] = array_sum(array_column($course_performance, 'forum_posts'));
    
    // Calculate SCORM completion rate
    if ($dashboard_stats['total_scorm'] > 0) {
        $dashboard_stats['scorm_completion_rate'] = round(($dashboard_stats['completed_scorm'] / $dashboard_stats['total_scorm']) * 100, 1);
    } else {
        $dashboard_stats['scorm_completion_rate'] = 0;
    }
    
    // Determine performance rating
    if ($dashboard_stats['average_grade'] >= 90) {
        $dashboard_stats['performance_rating'] = 'Excellent';
    } elseif ($dashboard_stats['average_grade'] >= 80) {
        $dashboard_stats['performance_rating'] = 'Good';
    } elseif ($dashboard_stats['average_grade'] >= 70) {
        $dashboard_stats['performance_rating'] = 'Average';
    } else {
        $dashboard_stats['performance_rating'] = 'Needs Improvement';
    }
    
    error_log("Middle School MyReports: ========== COMPREHENSIVE DATA SUMMARY ==========");
    error_log("Middle School MyReports: Total Courses: " . $dashboard_stats['total_courses']);
    error_log("Middle School MyReports: Average Grade: " . $dashboard_stats['average_grade'] . "%");
    error_log("Middle School MyReports: Assignments: " . $dashboard_stats['completed_assignments'] . "/" . $dashboard_stats['total_assignments']);
    error_log("Middle School MyReports: Quizzes: " . $dashboard_stats['completed_quizzes'] . "/" . $dashboard_stats['total_quizzes']);
    error_log("Middle School MyReports: SCORM: " . $dashboard_stats['completed_scorm'] . "/" . $dashboard_stats['total_scorm']);
    error_log("Middle School MyReports: Forum Posts: " . $dashboard_stats['total_forum_posts']);
    error_log("Middle School MyReports: Activities: " . $dashboard_stats['completed_activities'] . "/" . $dashboard_stats['total_activities']);
    error_log("Middle School MyReports: Has Real Data: " . ($has_real_data ? 'YES' : 'NO'));
    error_log("Middle School MyReports: IOMAD Installed: " . ($iomad_installed ? 'YES' : 'NO'));
    error_log("Middle School MyReports: ================================================");
    
} catch (Exception $e) {
    error_log("Middle School MyReports: Error in main data collection: " . $e->getMessage());
}

// Fetch additional grade reports data
$grade_reports = [
    'assignments' => [],
    'quizzes' => [],
    'activities' => [],
    'all_grades' => [],
    'statistics' => []
];

try {
    error_log("Middle School MyReports: Starting grade reports collection");
    
    // Get all user's courses for grade reports
    $user_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');
    
    foreach ($user_courses as $course) {
        if ($course->id == SITEID) continue;
        
        try {
            $coursecontext = context_course::instance($course->id);
            
            // Get grade items for this course
            $sql = "SELECT * FROM {grade_items} WHERE courseid = ? AND itemtype = 'mod'";
            $grade_items_records = $DB->get_records_sql($sql, [(int)$course->id]);
            
            if ($grade_items_records) {
                foreach ($grade_items_records as $grade_item) {
                    try {
                        // Get the grade for this item
                        $sql = "SELECT * FROM {grade_grades} WHERE itemid = ? AND userid = ?";
                        $grade_records = $DB->get_records_sql($sql, [(int)$grade_item->id, (int)$USER->id]);
                        
                        if ($grade_records) {
                            $grade = reset($grade_records);
                            
                            if ($grade && $grade->finalgrade !== null) {
                                $item_data = [
                                    'id' => (int)$grade_item->id,
                                    'itemname' => (string)$grade_item->itemname,
                                    'itemtype' => (string)$grade_item->itemtype,
                                    'itemmodule' => (string)$grade_item->itemmodule,
                                    'course_id' => (int)$course->id,
                                    'course_name' => (string)$course->fullname,
                                    'grademax' => (float)$grade_item->grademax,
                                    'grademin' => (float)$grade_item->grademin,
                                    'finalgrade' => (float)$grade->finalgrade,
                                    'percentage' => round(($grade->finalgrade / $grade_item->grademax) * 100, 1),
                                    'feedback' => (string)$grade->feedback,
                                    'timemodified' => (int)$grade->timemodified,
                                    'date' => userdate($grade->timemodified, '%d %B %Y')
                                ];
                                
                                // Categorize by module type
                                if ($grade_item->itemmodule == 'assign') {
                                    $grade_reports['assignments'][] = $item_data;
                                } elseif ($grade_item->itemmodule == 'quiz') {
                                    $grade_reports['quizzes'][] = $item_data;
                                } else {
                                    $grade_reports['activities'][] = $item_data;
                                }
                                
                                $grade_reports['all_grades'][] = $item_data;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Middle School MyReports: Error fetching grade for item {$grade_item->id}: " . $e->getMessage());
                        continue;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Middle School MyReports: Error processing course {$course->id}: " . $e->getMessage());
            continue;
        }
    }
    
    // Calculate grade statistics
    if (!empty($grade_reports['all_grades'])) {
        $all_percentages = array_column($grade_reports['all_grades'], 'percentage');
        $grade_reports['statistics'] = [
            'total_grades' => count($all_percentages),
            'average' => round(array_sum($all_percentages) / count($all_percentages), 1),
            'highest' => max($all_percentages),
            'lowest' => min($all_percentages),
            'total_assignments' => isset($grade_reports['assignments']) ? count($grade_reports['assignments']) : 0,
            'total_quizzes' => isset($grade_reports['quizzes']) ? count($grade_reports['quizzes']) : 0,
            'total_activities' => isset($grade_reports['activities']) ? count($grade_reports['activities']) : 0
        ];
    }
    
    error_log("Middle School MyReports: Grade reports collected - " . 
              (isset($grade_reports['all_grades']) ? count($grade_reports['all_grades']) : 0) . " total grades");
    
} catch (Exception $e) {
    error_log("Middle School MyReports: Error fetching grade reports: " . $e->getMessage());
}

// Prepare sidebar data
$sidebar_context = [
    'student_name' => fullname($USER),
    'dashboardurl' => new moodle_url('/'),
    'mycoursesurl' => new moodle_url('/my/courses.php'),
    'assignmentsurl' => new moodle_url('/mod/assign/index.php'),
    'gradesurl' => new moodle_url('/grade/report/overview/index.php'),
    'reportsurl' => new moodle_url('/theme/remui_kids/middleschool_myreports.php'),
    'calendarurl' => new moodle_url('/calendar/view.php'),
    'messagesurl' => new moodle_url('/message/index.php'),
    'profileurl' => new moodle_url('/user/profile.php'),
    'achievementsurl' => new moodle_url('/badges/mybadges.php'),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
    'ebooksurl' => new moodle_url('/theme/remui_kids/ebooks.php'),
    'treeviewurl' => new moodle_url('/theme/remui_kids/treeview.php'),
    'scheduleurl' => new moodle_url('/theme/remui_kids/schedule.php'),
    'competenciesurl' => new moodle_url('/theme/remui_kids/competencies.php'),
    'badgesurl' => new moodle_url('/theme/remui_kids/badges.php'),
    'settingsurl' => new moodle_url('/user/preferences.php'),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'currentpage' => ['reports' => true],
    'config' => ['wwwroot' => $CFG->wwwroot]
];

// Prepare template context with comprehensive data
$templatecontext = [
    'student_name' => fullname($USER),
    'wwwroot' => $CFG->wwwroot,
    'has_reports' => !empty($course_performance),
    'has_real_data' => $has_real_data,
    'iomad_installed' => $iomad_installed,
    'has_dashboard_stats' => !empty($dashboard_stats),
    'has_grade_analytics' => !empty($grade_analytics),
    'has_course_performance' => !empty($course_performance),
    'has_grade_reports' => !empty($grade_reports),
    'has_assignment_grades' => isset($grade_reports['assignments']) && !empty($grade_reports['assignments']),
    'has_quiz_grades' => isset($grade_reports['quizzes']) && !empty($grade_reports['quizzes']),
    'has_grade_statistics' => isset($grade_reports['statistics']),
    'course_performance' => $course_performance,
    'dashboard_stats' => $dashboard_stats,
    'grade_analytics' => $grade_analytics,
    'grade_reports' => $grade_reports,
    'data_source' => $has_real_data ? ($iomad_installed ? 'Real IOMAD Data' : 'Real Moodle Data') : 'No Data'
];

// Merge sidebar context
$templatecontext = array_merge($templatecontext, $sidebar_context);

// Add detailed grade report for each course using elementary's method
$detailed_grade_report = [];
$unique_courses_for_filter = [];

foreach ($course_performance as $course_perf) {
    if ($course_perf['course_id'] > 1) {
        try {
            $course_obj = $DB->get_record('course', ['id' => $course_perf['course_id']]);
            if ($course_obj) {
                error_log("Middle School MyReports: Fetching detailed grades for {$course_obj->fullname}");
                
                // Get grade items with grades
                $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                               gi.grademax, gi.grademin, gi.aggregationcoef2,
                               gg.finalgrade, gg.feedback, gg.timemodified
                        FROM {grade_items} gi
                        LEFT JOIN {grade_grades} gg ON gi.id = gg.itemid AND gg.userid = ?
                        WHERE gi.courseid = ? AND gi.itemtype IN ('mod', 'manual') AND gi.hidden = 0
                        ORDER BY gi.sortorder ASC";
                
                $grade_items = $DB->get_records_sql($sql, [(int)$USER->id, (int)$course_obj->id]);
                
                foreach ($grade_items as $item) {
                    if ($item->finalgrade !== null && $item->grademax > 0) {
                        $percentage = round(($item->finalgrade / $item->grademax) * 100, 1);
                        $weight = $item->aggregationcoef2 > 0 ? round($item->aggregationcoef2 * 100, 1) : 10;
                        
                        // Determine status
                        $status_text = 'Not Attempted';
                        if ($percentage >= 80) {
                            $status_text = 'Complete';
                        } elseif ($percentage >= 50) {
                            $status_text = 'In Progress';
                        } elseif ($percentage > 0) {
                            $status_text = 'Pending';
                        }
                        
                        $detailed_grade_report[] = [
                            'course_id' => (int)$course_obj->id,
                            'course_name' => (string)$course_obj->fullname,
                            'course_shortname' => (string)$course_obj->shortname,
                            'name' => (string)$item->itemname,
                            'type' => (string)$item->itemmodule,
                            'grade' => round($item->finalgrade, 2),
                            'max_grade' => (float)$item->grademax,
                            'percentage' => $percentage,
                            'weight' => $weight,
                            'contribution' => round(($percentage * $weight) / 100, 1),
                            'status_text' => $status_text,
                            'feedback' => (string)$item->feedback,
                            'date' => $item->timemodified > 0 ? userdate($item->timemodified, '%d %B %Y') : 'Not graded'
                        ];
                    }
                }
                
                // Add to unique courses for filter
                if (!isset($unique_courses_for_filter[$course_obj->id])) {
                    $unique_courses_for_filter[$course_obj->id] = [
                        'course_id' => $course_obj->id,
                        'course_name' => $course_obj->fullname,
                        'course_shortname' => $course_obj->shortname
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Middle School MyReports: Error fetching detailed grades for course {$course_perf['course_id']}: " . $e->getMessage());
        }
    }
}

// Add detailed grade report to template context
$templatecontext['grade_report'] = $detailed_grade_report;
$templatecontext['has_detailed_grades'] = !empty($detailed_grade_report);
$templatecontext['unique_courses'] = array_values($unique_courses_for_filter);
$templatecontext['total_grade_items'] = count($detailed_grade_report);

error_log("Middle School MyReports: Detailed grade report has " . count($detailed_grade_report) . " items from " . count($unique_courses_for_filter) . " courses");

// Render the template
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/middleschool_myreports_page', $templatecontext);
echo $OUTPUT->footer();

