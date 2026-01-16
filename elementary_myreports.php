<?php
// Don't clean output buffers at the very start - let Moodle handle it
// Only clean if we're in an error state (after config.php loads)

// Don't display errors before config.php is loaded (prevents output before proper HTML structure)
// Errors will still be logged, but won't break the page structure
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Disable display to prevent Quirks Mode issues
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1'); // But still log them

// Try to load config.php - if it fails, we need to handle it gracefully
try {
    require_once('../../config.php');
} catch (Exception $e) {
    // If config.php fails, output a basic error (but only if we can)
    error_log('Elementary MyReports: Failed to load config.php - ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>Configuration Error</h1>';
    echo '<p>The system configuration could not be loaded. Please contact your administrator.</p>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}

// Now that config.php is loaded, we can respect Moodle's debug settings if needed
// (Moodle handles its own error display through the OUTPUT object)

// Use core_completion\progress for progress calculation
use core_completion\progress;
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

$maintenanceurl = new moodle_url('/theme/remui_kids/pages/maintenance.php');

$remuikids_redirect_to_maintenance = static function() use ($maintenanceurl) {
    $location = $maintenanceurl->out(false);
    if (!headers_sent()) {
        header('Location: ' . $location);
        exit;
    }
    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
    exit;
};

$mockdatafile = __DIR__ . '/mock_competency_data.php';
if (!file_exists($mockdatafile)) {
    error_log('Elementary MyReports: Missing file ' . $mockdatafile);
    // Don't redirect - just log and continue without mock data
    // $remuikids_redirect_to_maintenance();
} else {
require_once($mockdatafile);
}

// Ensure user is logged in - wrap in try-catch to prevent redirects
// Note: require_login() may redirect, which is why view-source shows blank
try {
    require_login();
} catch (Exception $e) {
    error_log('Elementary MyReports: require_login error - ' . $e->getMessage());
    // If not logged in and we're viewing source, show an error instead of redirecting
    if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['REQUEST_URI'], 'view-source') !== false) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Authentication Required</title></head><body>';
        echo '<h1>Authentication Required</h1>';
        echo '<p>You must be logged in to view this page.</p>';
        echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
        exit;
    }
    // If not logged in, Moodle will handle the redirect
    throw $e;
}

global $USER, $DB, $OUTPUT, $PAGE, $CFG;

$remuikids_exception_handler = static function(\Throwable $exception) use ($remuikids_redirect_to_maintenance) {
    error_log('Elementary MyReports: Unhandled exception - ' . $exception->getMessage());
    error_log('Elementary MyReports: Exception trace: ' . $exception->getTraceAsString());
    error_log('Elementary MyReports: Exception file: ' . $exception->getFile() . ':' . $exception->getLine());
    error_log('Elementary MyReports: Exception class: ' . get_class($exception));
    
    if (function_exists('debugging') && debugging('', DEBUG_DEVELOPER)) {
        debugging('Elementary MyReports exception: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        debugging($exception->getTraceAsString(), DEBUG_DEVELOPER);
    }
    
    // Output error page instead of leaving blank
    // Clean any output buffers first
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Try to output error page
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>An Error Occurred</h1>';
    echo '<p><strong>Error:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . ':' . $exception->getLine() . '</p>';
    echo '<p>Error details have been logged. If this problem persists, please contact your administrator.</p>';
    
    // Only show trace in debug mode
    if (function_exists('debugging') && debugging('', DEBUG_DEVELOPER)) {
        echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
    }
    
    echo '</body></html>';
    exit;
};

set_exception_handler($remuikids_exception_handler);

register_shutdown_function(static function() use ($remuikids_redirect_to_maintenance) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('Elementary MyReports: Fatal error detected in shutdown - ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        error_log('Elementary MyReports: Error type: ' . $error['type']);
        error_log('Elementary MyReports: Headers sent: ' . (headers_sent() ? 'YES' : 'NO'));
        
        // TEMPORARILY: Don't redirect - just log so we can see the actual error
        // Only redirect on E_ERROR (fatal runtime errors), not E_PARSE (which might be recoverable)
        if (false && !headers_sent() && $error['type'] == E_ERROR) {
            error_log('Elementary MyReports: Redirecting to maintenance due to fatal error');
        $remuikids_redirect_to_maintenance();
        } else {
            error_log('Elementary MyReports: Not redirecting - error type: ' . $error['type'] . ', headers sent: ' . (headers_sent() ? 'yes' : 'no'));
        }
    }
});

$PAGE->set_url('/theme/remui_kids/elementary_myreports.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Reports - Elementary');
$PAGE->add_body_class('custom-dashboard-page has-student-sidebar elementary-reports-page');
$PAGE->requires->css('/theme/remui_kids/style/elementary_reports.css');

// Initialize grade report objects
require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/grade/report/user/lib.php');

// Initialize all data arrays to prevent undefined variable errors
$dashboard_stats = [];
$grade_analytics = [];
$course_performance = [];
$grade_reports = [];
$reports = [];
$totalassignments = 0;
$completedassignments = 0;
$totalquizzes = 0;
$completedquizzes = 0;
$has_real_data = false;

// Debug: Log that the script is starting
error_log("Elementary MyReports: Script started for user " . $USER->id . " (" . fullname($USER) . ")");

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
    error_log("Elementary MyReports: IOMAD " . ($iomad_installed ? 'DETECTED' : 'NOT FOUND'));
} catch (Exception $e) {
    error_log("Elementary MyReports: IOMAD check failed - " . $e->getMessage());
}

try {
    // Get user's enrolled courses with comprehensive data
    $courses = [];
    try {
    $courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname, enablecompletion, category, startdate, enddate, summary, timecreated, timemodified');
    } catch (Exception $e) {
        error_log("Elementary MyReports: Error getting enrolled courses: " . $e->getMessage());
        // Use alternative method if enrol_get_users_courses fails
        try {
            $courses = enrol_get_all_users_courses($USER->id, true, 'id, fullname, shortname, enablecompletion, category, startdate, enddate, summary, timecreated, timemodified');
        } catch (Exception $e2) {
            error_log("Elementary MyReports: Error with enrol_get_all_users_courses: " . $e2->getMessage());
            $courses = [];
        }
    }
    
    if (empty($courses)) {
        error_log("Elementary MyReports: No courses found for user " . $USER->id);
    } else {
    error_log("Elementary MyReports: Found " . count($courses) . " courses for user " . $USER->id);
    }
    
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
        'performance_rating' => 'Great',
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
    
    // Initialize activity type tracking
$activity_types = [];
$activity_type_items = [];
    
    // Collect data from each course
    foreach ($courses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        
        error_log("Elementary MyReports: Processing course " . $course->id . " - " . $course->fullname);
        
        try {
            $coursecontext = null;
        try {
            $coursecontext = context_course::instance($course->id);
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error creating context for course {$course->id}: " . $e->getMessage());
                continue; // Skip this course if context creation fails
            }
            
            // Get course completion - safely handle errors
            $completion = null;
            $course_complete = false;
            try {
                $completion = new completion_info($course);
                if ($completion && $completion->is_enabled()) {
                    try {
                $course_complete = $completion->is_course_complete($USER->id);
                if ($course_complete) {
                    $dashboard_stats['completed_courses']++;
                }
                    } catch (Exception $e) {
                        error_log("Elementary MyReports: Error checking course completion for course {$course->id}: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error creating completion_info for course {$course->id}: " . $e->getMessage());
                // Continue without completion tracking for this course
            }
            
            if (!$completion) {
                error_log("Elementary MyReports: Skipping course {$course->id} - completion_info could not be created");
                continue; // Skip this course if completion_info creation failed
            }
            
            // Get course progress percentage
            $progress_percentage = 0;
            try {
                if (class_exists('core_completion\progress')) {
                    $progress_percentage = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
                }
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error getting progress for course {$course->id}: " . $e->getMessage());
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
                error_log("Elementary MyReports: Error fetching course grade for {$course->id}: " . $e->getMessage());
            }
            
            // Get course activities - safely handle errors
            $modinfo = null;
            try {
            $modinfo = get_fast_modinfo($course);
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error getting modinfo for course {$course->id}: " . $e->getMessage());
                continue; // Skip this course if modinfo fails
            }
            
            if (!$modinfo) {
                error_log("Elementary MyReports: modinfo is null for course {$course->id}");
                continue; // Skip this course
            }
            
            $total_activities = 0;
            $completed_activities = 0;
            $in_progress_activities = 0;
            $assignments_count = 0;
            $completed_assignments_count = 0;
            $quizzes_count = 0;
            $completed_quizzes_count = 0;
            $activity_type_breakdown = [];
            
            // Only process activities if completion is enabled for the course
            if (!$completion->is_enabled()) {
                // If course completion is not enabled, skip activity completion tracking
                // but still count activities for totals
                $processed_activity_ids = [];
            foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                
                    if ($cm->modname === 'label') {
                        continue;
                    }
                    
                    // Handle subsection modules
                    if ($cm->modname === 'subsection') {
                        $subsectionsection = $DB->get_record('course_sections', [
                            'component' => 'mod_subsection',
                            'itemid' => $cm->instance
                        ], '*', IGNORE_MISSING);
                        
                        if ($subsectionsection && !empty($subsectionsection->sequence)) {
                            $activity_cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
                            
                            foreach ($activity_cmids as $activity_cmid) {
                                if (!isset($modinfo->cms[$activity_cmid]) || in_array($activity_cmid, $processed_activity_ids)) {
                                    continue;
                                }
                                
                                $activity_cm = $modinfo->cms[$activity_cmid];
                                if (!$activity_cm->uservisible || $activity_cm->deletioninprogress || 
                                    $activity_cm->modname == 'label' || $activity_cm->modname == 'subsection') {
                                    continue;
                                }
                                
                                $processed_activity_ids[] = $activity_cmid;
                                $total_activities++;
                                $modname = $activity_cm->modname;
                
                // Track by activity type
                                if (!isset($activity_types[$modname])) {
                                    $activity_types[$modname] = [
                                        'count' => 0,
                                        'completed' => 0,
                                        'name' => ucwords(str_replace('_', ' ', $modname)),
                                        'modname' => $modname
                                    ];
                                }
                                $activity_types[$modname]['count']++;
                                
                                // Initialize activity type breakdown
                                if (!isset($activity_type_breakdown[$modname])) {
                                    $activity_type_breakdown[$modname] = [
                                        'total' => 0,
                                        'completed' => 0,
                                        'in_progress' => 0,
                                        'pending' => 0
                                    ];
                                }
                                $activity_type_breakdown[$modname]['total']++;
                                $activity_type_breakdown[$modname]['pending']++;
                                
                                if ($modname == 'assign') {
                                    $assignments_count++;
                                } elseif ($modname == 'quiz') {
                                    $quizzes_count++;
                                }
                            }
                        }
                        continue;
                    }
                    
                    // Skip if already processed
                    if (in_array($cm->id, $processed_activity_ids)) {
                        continue;
                    }
                    
                    $processed_activity_ids[] = $cm->id;
                    $total_activities++;
                
                    // Track by activity type (without completion)
                $modname = $cm->modname;
                    if (!isset($activity_types[$modname])) {
                        $activity_types[$modname] = [
                            'count' => 0,
                            'completed' => 0,
                            'name' => ucwords(str_replace('_', ' ', $modname)),
                            'modname' => $modname
                        ];
                    }
                    $activity_types[$modname]['count']++;
                    
                    // Initialize activity type breakdown
                    if (!isset($activity_type_breakdown[$modname])) {
                        $activity_type_breakdown[$modname] = [
                            'total' => 0,
                            'completed' => 0,
                            'in_progress' => 0,
                            'pending' => 0
                        ];
                    }
                    $activity_type_breakdown[$modname]['total']++;
                    $activity_type_breakdown[$modname]['pending']++;
                
                    if (!isset($activity_type_items[$modname])) {
                        $activity_type_items[$modname] = [];
                }
                
                    $activity_url = '';
                    try {
                        if ($cm->url) {
                            $activity_url = $cm->url->out(false);
                } else {
                            $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
                        }
                    } catch (Exception $e) {
                        $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
                    }
                    
                    $activity_type_items[$modname][] = [
                        'name' => format_string($cm->name),
                        'course' => format_string($course->fullname),
                        'course_shortname' => format_string($course->shortname),
                        'completed' => false,
                        'status' => 'Not tracked',
                        'url' => $activity_url
                    ];
                    
                    // Count assignments and quizzes
                if ($cm->modname == 'assign') {
                    $assignments_count++;
                    } elseif ($cm->modname == 'quiz') {
                        $quizzes_count++;
                    }
                    }
                } else {
                // Course completion is enabled - process with completion tracking
                $processed_activity_ids = []; // Track processed activities to avoid duplicates
                foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible || $cm->deletioninprogress) {
                        continue;
                    }
                    
                    // Skip labels - not interactive activities
                    if ($cm->modname == 'label') {
                        continue;
                    }
                    
                    // Handle subsection modules - process activities inside them
                    if ($cm->modname === 'subsection') {
                        $subsectionsection = $DB->get_record('course_sections', [
                            'component' => 'mod_subsection',
                            'itemid' => $cm->instance
                        ], '*', IGNORE_MISSING);
                        
                        if ($subsectionsection && !empty($subsectionsection->sequence)) {
                            $activity_cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
                            
                            foreach ($activity_cmids as $activity_cmid) {
                                if (!isset($modinfo->cms[$activity_cmid]) || in_array($activity_cmid, $processed_activity_ids)) {
                                    continue;
                                }
                                
                                $activity_cm = $modinfo->cms[$activity_cmid];
                                if (!$activity_cm->uservisible || $activity_cm->deletioninprogress || 
                                    $activity_cm->modname == 'label' || $activity_cm->modname == 'subsection') {
                                    continue;
                                }
                                
                                $processed_activity_ids[] = $activity_cmid;
                                $total_activities++;
                                
                                // Process this activity (same logic as below)
                                $modname = $activity_cm->modname;
                                if (!isset($activity_types[$modname])) {
                                    $activity_types[$modname] = [
                                        'count' => 0,
                                        'completed' => 0,
                                        'name' => ucwords(str_replace('_', ' ', $modname)),
                                        'modname' => $modname
                                    ];
                                }
                                $activity_types[$modname]['count']++;
                                
                                if (!isset($activity_type_items[$modname])) {
                                    $activity_type_items[$modname] = [];
                                }
                                
                                // Track assignments/quizzes
                                if ($modname == 'assign') {
                                    $assignments_count++;
                                } elseif ($modname == 'quiz') {
                                    $quizzes_count++;
                                }
                                
                                // Initialize activity type breakdown
                                if (!isset($activity_type_breakdown[$modname])) {
                                    $activity_type_breakdown[$modname] = [
                                        'total' => 0,
                                        'completed' => 0,
                                        'in_progress' => 0,
                                        'pending' => 0
                                    ];
                                }
                                $activity_type_breakdown[$modname]['total']++;
                                
                                // Check completion
                                $module_completion_enabled = $completion->is_enabled($activity_cm);
                                $is_completed = false;
                                $is_in_progress = false;
                                if ($module_completion_enabled) {
                                    try {
                                        $completion_data = $completion->get_data($activity_cm, false, $USER->id);
                                        if ($completion_data) {
                                            if ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                                $completion_data->completionstate == COMPLETION_COMPLETE_PASS) {
                                                $is_completed = true;
                                                $completed_activities++;
                                                $activity_type_breakdown[$modname]['completed']++;
                                                $activity_types[$modname]['completed']++;
                                                
                                                if ($modname == 'assign') {
                        $completed_assignments_count++;
                                                } elseif ($modname == 'quiz') {
                                                    $completed_quizzes_count++;
                                                }
                                            } elseif ($completion_data->completionstate == COMPLETION_INCOMPLETE || 
                                                      ($completion_data->timestarted > 0 && $completion_data->timestarted > 0)) {
                                                $is_in_progress = true;
                                                $in_progress_activities++;
                                                $activity_type_breakdown[$modname]['in_progress']++;
                                            } else {
                                                $activity_type_breakdown[$modname]['pending']++;
                                            }
                                        } else {
                                            $activity_type_breakdown[$modname]['pending']++;
                                        }
                                    } catch (Exception $e) {
                                        $activity_type_breakdown[$modname]['pending']++;
                                    }
                                } else {
                                    $activity_type_breakdown[$modname]['pending']++;
                }
                
                                // Get activity URL
                                $activity_url = '';
                                try {
                                    if ($activity_cm->url) {
                                        $activity_url = $activity_cm->url->out(false);
                                    } else {
                                        $activity_url = (new moodle_url('/mod/' . $activity_cm->modname . '/view.php', ['id' => $activity_cm->id]))->out(false);
                                    }
                                } catch (Exception $e) {
                                    $activity_url = (new moodle_url('/mod/' . $activity_cm->modname . '/view.php', ['id' => $activity_cm->id]))->out(false);
                                }
                                
                                $activity_type_items[$modname][] = [
                                    'name' => format_string($activity_cm->name),
                                    'course' => format_string($course->fullname),
                                    'course_shortname' => format_string($course->shortname),
                                    'completed' => $is_completed,
                                    'status' => $is_completed ? 'Completed' : ($is_in_progress ? 'In progress' : ($module_completion_enabled ? 'Not started' : 'Not tracked')),
                                    'url' => $activity_url
                                ];
                            }
                        }
                        // Continue to next module after processing subsection activities
                        continue;
                    }
                    
                    // Skip if already processed (from subsection)
                    if (in_array($cm->id, $processed_activity_ids)) {
                        continue;
                    }
                    
                    $processed_activity_ids[] = $cm->id;
                    $total_activities++;
                    
                    // Track by activity type
                    $modname = $cm->modname;
                    if (!isset($activity_types[$modname])) {
                        $activity_types[$modname] = [
                            'count' => 0,
                            'completed' => 0,
                            'name' => ucwords(str_replace('_', ' ', $modname)),
                            'modname' => $modname
                        ];
                    }
                    $activity_types[$modname]['count']++;
                    
                    if (!isset($activity_type_items[$modname])) {
                        $activity_type_items[$modname] = [];
                }
                
                    // Track assignments/quizzes regardless of completion tracking
                if ($cm->modname == 'assign') {
                    $assignments_count++;
                    } elseif ($cm->modname == 'quiz') {
                    $quizzes_count++;
                    }
                    
                    $module_completion_enabled = $completion->is_enabled($cm);
                    
                    // Initialize activity type breakdown for this modname
                    if (!isset($activity_type_breakdown[$modname])) {
                        $activity_type_breakdown[$modname] = [
                            'total' => 0,
                            'completed' => 0,
                            'in_progress' => 0,
                            'pending' => 0
                        ];
                    }
                    $activity_type_breakdown[$modname]['total']++;
                    
                    // Check completion - safely handle potential errors
                    $is_completed = false;
                    $is_in_progress = false;
                    if ($module_completion_enabled) {
                        try {
                            $completion_data = $completion->get_data($cm, false, $USER->id);
                            if ($completion_data) {
                                if ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                    $completion_data->completionstate == COMPLETION_COMPLETE_PASS) {
                                    $is_completed = true;
                                    $activity_type_breakdown[$modname]['completed']++;
                                } elseif ($completion_data->completionstate == COMPLETION_INCOMPLETE || 
                                          ($completion_data->timestarted > 0 && $completion_data->timestarted > 0)) {
                                    $is_in_progress = true;
                                    $in_progress_activities++;
                                    $activity_type_breakdown[$modname]['in_progress']++;
                                } else {
                                    $activity_type_breakdown[$modname]['pending']++;
                                }
                            } else {
                                $activity_type_breakdown[$modname]['pending']++;
                            }
                        } catch (Exception $e) {
                            error_log("Elementary MyReports: Error getting completion for activity {$cm->id}: " . $e->getMessage());
                            $activity_type_breakdown[$modname]['pending']++;
                        }
                    } else {
                        $activity_type_breakdown[$modname]['pending']++;
                    }
                    
                    $activity_url = '';
                    try {
                        if ($cm->url) {
                            $activity_url = $cm->url->out(false);
                        } else {
                            $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
                        }
                    } catch (Exception $e) {
                        $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
                    }
                    
                    $activity_type_items[$modname][] = [
                        'name' => format_string($cm->name),
                        'course' => format_string($course->fullname),
                        'course_shortname' => format_string($course->shortname),
                        'completed' => $is_completed,
                        'status' => $is_completed ? 'Completed' : ($is_in_progress ? 'In progress' : ($module_completion_enabled ? 'Not started' : 'Not tracked')),
                        'url' => $activity_url
                    ];
                    
                    if ($is_completed) {
                        $completed_activities++;
                        $activity_types[$modname]['completed']++;
                    }
                    
                    // Count completed assignments/quizzes
                    if ($cm->modname == 'assign' && $is_completed) {
                        $completed_assignments_count++;
                }
                    
                    if ($cm->modname == 'quiz' && $is_completed) {
                        $completed_quizzes_count++;
            }
                } // End foreach loop for course completion enabled
            } // End else block
            
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
                error_log("Elementary MyReports: Error fetching teachers for course {$course->id}: " . $e->getMessage());
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
                error_log("Elementary MyReports: Error fetching last access for course {$course->id}: " . $e->getMessage());
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
                error_log("Elementary MyReports: Error fetching SCORM data for course {$course->id}: " . $e->getMessage());
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
                error_log("Elementary MyReports: Error fetching forum posts for course {$course->id}: " . $e->getMessage());
            }
            
            // Calculate completion percentage
            $completion_percentage = $total_activities > 0 ? round(($completed_activities / $total_activities) * 100, 1) : 0;
            
            // Count sections/lessons (excluding section 0) - only count sections, don't recalculate activities
            $total_sections = 0;
            $completed_sections = 0;
            
            // Build a map of activity completion status for section counting (including activities in subsections)
            $activity_completion_map = [];
            $processed_for_sections = [];
            if ($completion && $completion->is_enabled()) {
                foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible || $cm->deletioninprogress) {
                        continue;
                    }
                    
                    if ($cm->modname == 'label') {
                        continue;
                    }
                    
                    // Handle subsection modules
                    if ($cm->modname === 'subsection') {
                        $subsectionsection = $DB->get_record('course_sections', [
                            'component' => 'mod_subsection',
                            'itemid' => $cm->instance
                        ], '*', IGNORE_MISSING);
                        
                        if ($subsectionsection && !empty($subsectionsection->sequence)) {
                            $activity_cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
                            
                            foreach ($activity_cmids as $activity_cmid) {
                                if (!isset($modinfo->cms[$activity_cmid]) || in_array($activity_cmid, $processed_for_sections)) {
                                    continue;
                                }
                                
                                $activity_cm = $modinfo->cms[$activity_cmid];
                                if (!$activity_cm->uservisible || $activity_cm->deletioninprogress || 
                                    $activity_cm->modname == 'label' || $activity_cm->modname == 'subsection') {
                                    continue;
                                }
                                
                                $processed_for_sections[] = $activity_cmid;
                                $is_completed = false;
                                if ($completion->is_enabled($activity_cm)) {
                                    try {
                                        $completion_data = $completion->get_data($activity_cm, false, $USER->id);
                                        if ($completion_data && ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                                                 $completion_data->completionstate == COMPLETION_COMPLETE_PASS)) {
                                            $is_completed = true;
                                        }
                                    } catch (Exception $e) {
                                        // Continue with is_completed = false
                                    }
                                }
                                $activity_completion_map[$activity_cm->id] = $is_completed;
                            }
                        }
                        continue;
                    }
                    
                    // Skip if already processed
                    if (in_array($cm->id, $processed_for_sections)) {
                        continue;
                    }
                    
                    $processed_for_sections[] = $cm->id;
                    $is_completed = false;
                    if ($completion->is_enabled($cm)) {
                        try {
                            $completion_data = $completion->get_data($cm, false, $USER->id);
                            if ($completion_data && ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                                     $completion_data->completionstate == COMPLETION_COMPLETE_PASS)) {
                                $is_completed = true;
                            }
                        } catch (Exception $e) {
                            // Continue with is_completed = false
                        }
                    }
                    $activity_completion_map[$cm->id] = $is_completed;
                }
            }
            
            try {
                $sections = $modinfo->get_section_info_all();
                foreach ($sections as $sectionnum => $section) {
                    if ($sectionnum == 0) continue; // Skip section 0
                    if (!$section->uservisible) continue;
                    
                    $total_sections++;
                    $section_activities = 0;
                    $section_completed = 0;
                    
                    if (isset($modinfo->sections[$sectionnum])) {
                        foreach ($modinfo->sections[$sectionnum] as $cmid) {
                            $cm = $modinfo->get_cm($cmid);
                            if (!$cm->uservisible || $cm->deletioninprogress || $cm->modname == 'label') {
                                continue;
                            }
                            
                            // Handle subsection modules - count activities inside them
                            if ($cm->modname == 'subsection') {
                                $subsectionsection = $DB->get_record('course_sections', [
                                    'component' => 'mod_subsection',
                                    'itemid' => $cm->instance
                                ], '*', IGNORE_MISSING);
                                
                                if ($subsectionsection && !empty($subsectionsection->sequence)) {
                                    $activity_cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
                                    
                                    foreach ($activity_cmids as $activity_cmid) {
                                        if (!isset($modinfo->cms[$activity_cmid])) {
                                            continue;
                                        }
                                        
                                        $activity_cm = $modinfo->cms[$activity_cmid];
                                        if (!$activity_cm->uservisible || $activity_cm->deletioninprogress || 
                                            $activity_cm->modname == 'label' || $activity_cm->modname == 'subsection') {
                                            continue;
                                        }
                                        
                                        $section_activities++;
                                        // Check if activity is completed using our map
                                        if (isset($activity_completion_map[$activity_cm->id]) && $activity_completion_map[$activity_cm->id]) {
                                            $section_completed++;
                                        }
                                    }
                                }
                                continue;
                            }
                            
                            $section_activities++;
                            // Check if activity is completed using our map
                            if (isset($activity_completion_map[$cm->id]) && $activity_completion_map[$cm->id]) {
                                $section_completed++;
                            }
                        }
                    }
                    
                    // Section is completed if all activities in it are completed
                    if ($section_activities > 0 && $section_activities == $section_completed) {
                        $completed_sections++;
                    }
                }
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error counting sections for course {$course->id}: " . $e->getMessage());
            }
            
            // Calculate pending activities (total - completed - in_progress)
            $pending_activities = $total_activities - $completed_activities - $in_progress_activities;
            
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
                'in_progress_activities' => $in_progress_activities,
                'pending_activities' => $pending_activities > 0 ? $pending_activities : 0,
                'total_sections' => $total_sections,
                'completed_sections' => $completed_sections,
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
                'activity_type_breakdown' => $activity_type_breakdown,
                'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out()
            ];
            
            // Add JSON-encoded course data for JavaScript (add this to the last item in array)
            $last_index = count($course_performance) - 1;
            if ($last_index >= 0) {
                $course_performance[$last_index]['course_data_json'] = htmlspecialchars(json_encode([
                    'course_id' => (int)$course->id,
                    'course_name' => (string)$course->fullname,
                    'total_sections' => $total_sections,
                    'completed_sections' => $completed_sections,
                    'total_activities' => $total_activities,
                    'completed_activities' => $completed_activities,
                    'in_progress_activities' => $in_progress_activities,
                    'pending_activities' => $pending_activities > 0 ? $pending_activities : 0,
                    'assignments' => $assignments_count,
                    'completed_assignments' => $completed_assignments_count,
                    'quizzes' => $quizzes_count,
                    'completed_quizzes' => $completed_quizzes_count,
                    'scorm_activities' => $scorm_count,
                    'completed_scorm' => $completed_scorm_count,
                    'activity_type_breakdown' => $activity_type_breakdown,
                    'completion_percentage' => $completion_percentage
                ]), ENT_QUOTES, 'UTF-8');
            }
            
            // Add to aggregated stats
            $totalassignments += $assignments_count;
            $completedassignments += $completed_assignments_count;
            $totalquizzes += $quizzes_count;
            $completedquizzes += $completed_quizzes_count;
            
        } catch (Exception $e) {
            error_log("Elementary MyReports: Error processing course {$course->id}: " . $e->getMessage());
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
    
    // Determine performance rating (kid-friendly for elementary)
    if ($dashboard_stats['average_grade'] >= 90) {
        $dashboard_stats['performance_rating'] = 'Amazing!';
    } elseif ($dashboard_stats['average_grade'] >= 80) {
        $dashboard_stats['performance_rating'] = 'Great Job!';
    } elseif ($dashboard_stats['average_grade'] >= 70) {
        $dashboard_stats['performance_rating'] = 'Good Work!';
    } else {
        $dashboard_stats['performance_rating'] = 'Keep Trying!';
    }
    
    error_log("Elementary MyReports: ========== COMPREHENSIVE DATA SUMMARY ==========");
    error_log("Elementary MyReports: Total Courses: " . $dashboard_stats['total_courses']);
    error_log("Elementary MyReports: Average Grade: " . $dashboard_stats['average_grade'] . "%");
    error_log("Elementary MyReports: Assignments: " . $dashboard_stats['completed_assignments'] . "/" . $dashboard_stats['total_assignments']);
    error_log("Elementary MyReports: Quizzes: " . $dashboard_stats['completed_quizzes'] . "/" . $dashboard_stats['total_quizzes']);
    error_log("Elementary MyReports: SCORM: " . $dashboard_stats['completed_scorm'] . "/" . $dashboard_stats['total_scorm']);
    error_log("Elementary MyReports: Forum Posts: " . $dashboard_stats['total_forum_posts']);
    error_log("Elementary MyReports: Activities: " . $dashboard_stats['completed_activities'] . "/" . $dashboard_stats['total_activities']);
    error_log("Elementary MyReports: Has Real Data: " . ($has_real_data ? 'YES' : 'NO'));
    error_log("Elementary MyReports: IOMAD Installed: " . ($iomad_installed ? 'YES' : 'NO'));
    error_log("Elementary MyReports: ================================================");
    
    // Remove activity types with zero count
    $activity_types = array_filter($activity_types, function($type) {
        return $type['count'] > 0;
    });
    
    // Calculate percentages for each activity type
    foreach ($activity_types as $key => $type) {
        $activity_types[$key]['percentage'] = $type['count'] > 0 ? 
            round(($type['completed'] / $type['count']) * 100, 1) : 0;
    }
    
    error_log("Elementary MyReports: Activity Types Breakdown:");
    foreach ($activity_types as $key => $type) {
        error_log("Elementary MyReports: - {$type['name']}: {$type['completed']}/{$type['count']} ({$type['percentage']}%)");
    }
    
    $sortedactivitytypes = array_values($activity_types);
    usort($sortedactivitytypes, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    
    $activity_donut_legend = [];
    $activity_donut_style = '#E5E7EB 0 100%';
    
    // Color palette matching competencies page
    $colorpalette = [
        '#667eea',  // Blue/Purple (like competencies total)
        '#10B981',  // Green (like competencies completed/competent)
        '#8B5CF6',  // Purple (like competencies framework)
        '#F59E0B',  // Orange (like competencies in progress)
        '#3B82F6'   // Blue (additional)
    ];
    
    // Framework color mapping (same as competencies page)
    $framework_color_map = [
        'blue' => ['primary' => '#667eea', 'secondary' => '#764ba2', 'icon' => 'fa-book-open'],
        'green' => ['primary' => '#10B981', 'secondary' => '#059669', 'icon' => 'fa-calculator'],
        'purple' => ['primary' => '#8B5CF6', 'secondary' => '#7C3AED', 'icon' => 'fa-flask'],
        'orange' => ['primary' => '#F59E0B', 'secondary' => '#D97706', 'icon' => 'fa-globe']
    ];
    
    // Summary card colors (same as competencies page)
    $summary_card_colors = [
        'total' => ['primary' => '#667eea', 'secondary' => '#764ba2'],
        'completed' => ['primary' => '#10B981', 'secondary' => '#059669'],
        'progress' => ['primary' => '#F59E0B', 'secondary' => '#D97706'],
        'notstarted' => ['primary' => '#94A3B8', 'secondary' => '#64748B']
    ];
    
    $totalactivitycount = array_sum(array_column($sortedactivitytypes, 'count'));
    
    if ($totalactivitycount > 0) {
        $segments = [];
        $start = 0;
        $topTypes = array_slice($sortedactivitytypes, 0, count($colorpalette));
        foreach ($topTypes as $idx => $typeinfo) {
            $percent = round(($typeinfo['count'] / $totalactivitycount) * 100, 2);
            $end = min(100, $start + $percent);
            $color = $colorpalette[$idx % count($colorpalette)];
            $segments[] = "{$color} {$start}% {$end}%";
            $activity_donut_legend[] = [
                'name' => $typeinfo['name'],
                'count' => $typeinfo['count'],
                'completed' => $typeinfo['completed'],
                'percentage' => $percent,
                'color' => $color
            ];
            $start = $end;
        }
        if ($start < 100) {
            $segments[] = "#E5E7EB {$start}% 100%";
        }
        $activity_donut_style = implode(', ', $segments);
    }
    
$grade_star_count = 0;
if (!empty($dashboard_stats['average_grade'])) {
    $grade_star_count = max(0, min(5, (int)round($dashboard_stats['average_grade'] / 20)));
}

$grade_stars = [];
for ($i = 1; $i <= 5; $i++) {
    $grade_stars[] = [
        'filled' => $i <= $grade_star_count
    ];
}

$activity_type_items_json = json_encode($activity_type_items);
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Error in main data collection: " . $e->getMessage());
    error_log("Elementary MyReports: Stack trace: " . $e->getTraceAsString());
    $activity_types = [];
    // Ensure arrays are initialized even on error
    if (!isset($dashboard_stats)) {
        $dashboard_stats = [
            'total_courses' => 0,
            'completed_courses' => 0,
            'total_activities' => 0,
            'completed_activities' => 0,
            'average_grade' => 0,
            'completion_rate' => 0
        ];
    }
    if (!isset($course_performance)) {
        $course_performance = [];
    }
    if (!isset($grade_analytics)) {
        $grade_analytics = [
            'overall_average' => 0,
            'grade_distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
            'highest_grade' => 0,
            'lowest_grade' => 100
        ];
    }
    $activity_type_items_json = json_encode($activity_type_items);
}

// Fetch additional grade reports data with enhanced details
$grade_reports = [
    'assignments' => [],
    'quizzes' => [],
    'activities' => [],
    'all_grades' => [],
    'statistics' => []
];

if (!isset($activity_type_items_json)) {
    $activity_type_items_json = json_encode($activity_type_items);
}

try {
    error_log("Elementary MyReports: Starting comprehensive grade reports collection");
    
    // Get all user's courses for grade reports
    $user_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');
    
    foreach ($user_courses as $course) {
        if ($course->id == SITEID) continue;
        
        try {
            $coursecontext = context_course::instance($course->id);
            
            // Get assignments with grades (matching teacher report method)
            $assignments = $DB->get_records_sql(
                "SELECT ag.id, ag.grade, ag.timemodified, a.id AS assignmentid, a.grade AS maxgrade, 
                        a.course, a.name, c.shortname AS course_shortname
                 FROM {assign_grades} ag
                 JOIN {assign} a ON a.id = ag.assignment
                 JOIN {course} c ON c.id = a.course
                 WHERE ag.userid = ? AND ag.grade IS NOT NULL AND a.course = ?
                 ORDER BY ag.timemodified DESC",
                [(int)$USER->id, (int)$course->id]
            );
            
            foreach ($assignments as $assignment) {
                try {
                                $attempts = 0;
                                $status = 'completed';
                                
                    // Get submission info
                                    try {
                                        // Get count of attempts (use get_records_sql since GROUP BY can return multiple rows)
                                        $sql = "SELECT COUNT(*) as attempts, status
                                                FROM {assign_submission}
                                                WHERE assignment = ? AND userid = ?
                                                GROUP BY status";
                        $assign_submissions = $DB->get_records_sql($sql, [(int)$assignment->assignmentid, (int)$USER->id]);
                                        if (!empty($assign_submissions)) {
                                            // Sum all attempts across statuses
                                            $attempts = 0;
                                            foreach ($assign_submissions as $sub) {
                                                $attempts += (int)$sub->attempts;
                                            }
                                            // Get the status from the first record (or prefer 'submitted' status)
                                            $status = 'completed';
                                            foreach ($assign_submissions as $sub) {
                                                if ($sub->status === 'submitted') {
                                                    $status = 'submitted';
                                                    break;
                                                }
                                                $status = $sub->status;
                                            }
                                        }
                                    } catch (Exception $e) {
                                        error_log("Elementary MyReports: Error getting assignment submissions: " . $e->getMessage());
                                    }
                    
                    // Check if this assignment uses rubric grading
                    $percentage = ($assignment->maxgrade > 0) ? round(($assignment->grade / $assignment->maxgrade) * 100, 1) : 0;
                    
                    try {
                        $cm = get_coursemodule_from_instance('assign', $assignment->assignmentid, $course->id, false, MUST_EXIST);
                        if ($cm) {
                            $rubric_data = theme_remui_kids_get_rubric_by_cmid($cm->id);
                            
                            if ($rubric_data && !empty($rubric_data['criteria'])) {
                                // This is a rubric-graded assignment - calculate percentage from rubric fillings
                                $grading_instance = $DB->get_record('grading_instances', 
                                    ['itemid' => $assignment->id],
                                    '*',
                                    IGNORE_MULTIPLE
                                );
                                
                                if ($grading_instance) {
                                    $total_score = 0;
                                    $max_score = 0;
                                    
                                    // Get rubric fillings and calculate total score
                                    $fillings = $DB->get_records('gradingform_rubric_fillings', 
                                        ['instanceid' => $grading_instance->id]
                                    );
                                    
                                    foreach ($fillings as $filling) {
                                        $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
                                        if ($level) {
                                            $total_score += $level->score;
                                        }
                                    }
                                    
                                    // Calculate max score from rubric criteria
                                    foreach ($rubric_data['criteria'] as $criterion) {
                                        $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
                                        $max_score += max($scores);
                                    }
                                    
                                    // Calculate percentage based on rubric score
                                    if ($max_score > 0 && $total_score > 0) {
                                        $percentage = round(($total_score / $max_score) * 100, 1);
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // If error checking for rubric, use the original percentage
                        error_log("Elementary MyReports: Error checking rubric for assignment {$assignment->assignmentid}: " . $e->getMessage());
                    }
                                
                                $item_data = [
                        'id' => (int)$assignment->assignmentid,
                        'itemname' => (string)$assignment->name,
                        'itemtype' => 'mod',
                        'itemmodule' => 'assign',
                                    'course_id' => (int)$course->id,
                                    'course_name' => (string)$course->fullname,
                        'grademax' => (float)$assignment->maxgrade,
                        'grademin' => 0,
                        'finalgrade' => (float)$assignment->grade,
                                    'percentage' => $percentage,
                        'feedback' => '',
                        'timemodified' => (int)$assignment->timemodified,
                        'date' => userdate($assignment->timemodified, '%d %B %Y'),
                                    'attempts' => $attempts,
                        'time_taken' => 0,
                        'time_taken_formatted' => 'N/A',
                                    'grade_letter' => $percentage >= 90 ? 'A' : ($percentage >= 80 ? 'B' : ($percentage >= 70 ? 'C' : ($percentage >= 60 ? 'D' : 'F'))),
                                    'status' => $status
                                ];
                                
                                    $grade_reports['assignments'][] = $item_data;
                    $grade_reports['all_grades'][] = $item_data;
                } catch (Exception $e) {
                    error_log("Elementary MyReports: Error processing assignment {$assignment->assignmentid}: " . $e->getMessage());
                    continue;
                }
            }
            
            // Get quizzes with grades (matching teacher report method)
            $quizzes = $DB->get_records_sql(
                "SELECT qa.id AS attemptid, qa.sumgrades, qa.attempt, qa.timestart, q.id AS quizid, 
                        q.sumgrades AS maxgrade, q.course, q.name, c.shortname AS course_shortname
                 FROM {quiz_attempts} qa
                 JOIN {quiz} q ON q.id = qa.quiz
                 JOIN {course} c ON c.id = q.course
                 WHERE qa.userid = ? AND qa.state = 'finished' 
                   AND qa.sumgrades IS NOT NULL AND q.course = ?
                 ORDER BY qa.timestart DESC",
                [(int)$USER->id, (int)$course->id]
            );
            
            // Get highest attempt per quiz (standard grading method)
            $uniqueQuizGrades = [];
            foreach ($quizzes as $quiz) {
                $quizkey = $quiz->course . '_' . $quiz->quizid;
                if (!isset($uniqueQuizGrades[$quizkey]) || $quiz->sumgrades > $uniqueQuizGrades[$quizkey]->sumgrades) {
                    $uniqueQuizGrades[$quizkey] = $quiz;
                }
            }
            
            foreach ($uniqueQuizGrades as $quiz) {
                try {
                    $attempts = 0;
                    $time_taken = 0;
                    
                    // Get attempt info
                    try {
                        $sql = "SELECT COUNT(*) as attempts, SUM(timefinish - timestart) as totaltime
                                FROM {quiz_attempts}
                                WHERE quiz = ? AND userid = ? AND state = 'finished'";
                        $quiz_data = $DB->get_record_sql($sql, [(int)$quiz->quizid, (int)$USER->id]);
                        if ($quiz_data) {
                            $attempts = (int)$quiz_data->attempts;
                            $time_taken = (int)$quiz_data->totaltime;
                        }
                    } catch (Exception $e) {
                        error_log("Elementary MyReports: Error getting quiz attempts: " . $e->getMessage());
                    }
                    
                    // Calculate percentage using quiz sumgrades / quiz maxgrade (matching teacher report)
                    $percentage = ($quiz->maxgrade > 0) ? round(($quiz->sumgrades / $quiz->maxgrade) * 100, 1) : 0;
                    
                    $item_data = [
                        'id' => (int)$quiz->quizid,
                        'itemname' => (string)$quiz->name,
                        'itemtype' => 'mod',
                        'itemmodule' => 'quiz',
                        'course_id' => (int)$course->id,
                        'course_name' => (string)$course->fullname,
                        'grademax' => (float)$quiz->maxgrade,
                        'grademin' => 0,
                        'finalgrade' => (float)$quiz->sumgrades,
                        'percentage' => $percentage,
                        'feedback' => '',
                        'timemodified' => (int)$quiz->timestart,
                        'date' => userdate($quiz->timestart, '%d %B %Y'),
                        'attempts' => $attempts,
                        'time_taken' => $time_taken,
                        'time_taken_formatted' => $time_taken > 0 ? gmdate("H:i:s", $time_taken) : 'N/A',
                        'grade_letter' => $percentage >= 90 ? 'A' : ($percentage >= 80 ? 'B' : ($percentage >= 70 ? 'C' : ($percentage >= 60 ? 'D' : 'F'))),
                        'status' => 'completed'
                    ];
                    
                    $grade_reports['quizzes'][] = $item_data;
                                $grade_reports['all_grades'][] = $item_data;
                } catch (Exception $e) {
                    error_log("Elementary MyReports: Error processing quiz {$quiz->quizid}: " . $e->getMessage());
                    continue;
                            }
                        }
                    } catch (Exception $e) {
            error_log("Elementary MyReports: Error processing course {$course->id}: " . $e->getMessage());
                        continue;
        }
    }

    // Align assignment and quiz grade data with teacher analytics reference for consistent percentages
    $analytics_courseids = array_map(function($course) {
        return $course->id;
    }, $user_courses);

    try {
        if (!empty($analytics_courseids)) {
            try {
                $student_analytics = theme_remui_kids_get_student_analytics($USER->id, $analytics_courseids);
            } catch (Exception $analytics_e) {
                error_log("Elementary MyReports: Error calling theme_remui_kids_get_student_analytics: " . $analytics_e->getMessage());
                error_log("Elementary MyReports: Analytics error trace: " . $analytics_e->getTraceAsString());
                $student_analytics = [];
            }
            
            // Handle null return (should return empty array instead)
            if ($student_analytics === null || !is_array($student_analytics)) {
                $student_analytics = [];
            }
            
            // Ensure assignments_detail and quizzes_detail are arrays
            if (!isset($student_analytics['assignments_detail']) || !is_array($student_analytics['assignments_detail'])) {
                $student_analytics['assignments_detail'] = [];
            }
            if (!isset($student_analytics['quizzes_detail']) || !is_array($student_analytics['quizzes_detail'])) {
                $student_analytics['quizzes_detail'] = [];
            }

            if (!empty($student_analytics['assignments_detail'])) {
                $grade_reports['assignments'] = [];
                foreach ($student_analytics['assignments_detail'] as $assignment_detail) {
                    // Convert object to array if needed
                    if (is_object($assignment_detail)) {
                        $assignment_detail = (array)$assignment_detail;
                    }
                    
                    if (empty($assignment_detail['has_grade']) || !isset($assignment_detail['percentage'])) {
                        continue;
                    }

                    $assignment_id = (int)($assignment_detail['id'] ?? 0);
                    $course_id = (int)($assignment_detail['course'] ?? 0);
                    $percentage = (float)$assignment_detail['percentage'];
                    
                    // Check if this assignment uses rubric grading and recalculate percentage if needed
                    try {
                        $cm = get_coursemodule_from_instance('assign', $assignment_id, $course_id, false, MUST_EXIST);
                        if ($cm) {
                            $rubric_data = theme_remui_kids_get_rubric_by_cmid($cm->id);
                            
                            if ($rubric_data && !empty($rubric_data['criteria'])) {
                                // This is a rubric-graded assignment - calculate percentage from rubric fillings
                                $assign_grade = $DB->get_record('assign_grades', 
                                    ['assignment' => $assignment_id, 'userid' => $USER->id],
                                    '*',
                                    IGNORE_MULTIPLE
                                );
                                
                                if ($assign_grade) {
                                    $grading_instance = $DB->get_record('grading_instances', 
                                        ['itemid' => $assign_grade->id],
                                        '*',
                                        IGNORE_MULTIPLE
                                    );
                                    
                                    if ($grading_instance) {
                                        $total_score = 0;
                                        $max_score = 0;
                                        
                                        // Get rubric fillings and calculate total score
                                        $fillings = $DB->get_records('gradingform_rubric_fillings', 
                                            ['instanceid' => $grading_instance->id]
                                        );
                                        
                                        foreach ($fillings as $filling) {
                                            $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
                                            if ($level) {
                                                $total_score += $level->score;
                                            }
                                        }
                                        
                                        // Calculate max score from rubric criteria
                                        foreach ($rubric_data['criteria'] as $criterion) {
                                            $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
                                            $max_score += max($scores);
                                        }
                                        
                                        // Calculate percentage based on rubric score
                                        if ($max_score > 0 && $total_score > 0) {
                                            $percentage = round(($total_score / $max_score) * 100, 1);
                                        }
                                    }
                    }
                }
            }
        } catch (Exception $e) {
                        // If error checking for rubric, use the original percentage
                        error_log("Elementary MyReports: Error checking rubric for assignment {$assignment_id}: " . $e->getMessage());
                    }

                    $grade_reports['assignments'][] = [
                        'id' => $assignment_id,
                        'itemname' => (string)($assignment_detail['name'] ?? ''),
                        'itemtype' => 'mod',
                        'itemmodule' => 'assign',
                        'course_id' => $course_id,
                        'course_name' => (string)($assignment_detail['course_fullname'] ?? $assignment_detail['course_shortname'] ?? ''),
                        'grademax' => (float)($assignment_detail['maxgrade'] ?? 0),
                        'grademin' => 0,
                        'finalgrade' => (float)($assignment_detail['grade'] ?? 0),
                        'percentage' => $percentage,
                        'feedback' => '',
                        'timemodified' => isset($assignment_detail['date']) ? strtotime($assignment_detail['date']) : time(),
                        'date' => $assignment_detail['date'] ?? '',
                        'attempts' => 0,
                        'time_taken' => 0,
                        'time_taken_formatted' => 'N/A',
                        'grade_letter' => $percentage >= 90 ? 'A' : ($percentage >= 80 ? 'B' : ($percentage >= 70 ? 'C' : ($percentage >= 60 ? 'D' : 'F'))),
                        'status' => $assignment_detail['status'] ?? 'completed'
                    ];
                }
            }

            if (!empty($student_analytics['quizzes_detail'])) {
                $grade_reports['quizzes'] = [];
                foreach ($student_analytics['quizzes_detail'] as $quiz_detail) {
                    // Convert object to array if needed
                    if (is_object($quiz_detail)) {
                        $quiz_detail = (array)$quiz_detail;
                    }
                    
                    if (!isset($quiz_detail['percentage'])) {
                        continue;
                    }

                    $grade_reports['quizzes'][] = [
                        'id' => (int)($quiz_detail['id'] ?? 0),
                        'itemname' => (string)($quiz_detail['name'] ?? ''),
                        'itemtype' => 'mod',
                        'itemmodule' => 'quiz',
                        'course_id' => (int)($quiz_detail['course'] ?? 0),
                        'course_name' => (string)($quiz_detail['course_fullname'] ?? $quiz_detail['course_shortname'] ?? ''),
                        'grademax' => (float)($quiz_detail['maxgrade'] ?? 0),
                        'grademin' => 0,
                        'finalgrade' => (float)($quiz_detail['grade'] ?? 0),
                        'percentage' => (float)$quiz_detail['percentage'],
                        'feedback' => '',
                        'timemodified' => isset($quiz_detail['date']) ? strtotime($quiz_detail['date']) : time(),
                        'date' => $quiz_detail['date'] ?? '',
                        'attempts' => (int)($quiz_detail['attempt'] ?? 0),
                        'time_taken' => 0,
                        'time_taken_formatted' => 'N/A',
                        'grade_letter' => $quiz_detail['percentage'] >= 90 ? 'A' : ($quiz_detail['percentage'] >= 80 ? 'B' : ($quiz_detail['percentage'] >= 70 ? 'C' : ($quiz_detail['percentage'] >= 60 ? 'D' : 'F'))),
                        'status' => 'completed'
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Elementary MyReports: Error aligning grade data with analytics reference - " . $e->getMessage());
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
    
    error_log("Elementary MyReports: Grade reports collected - " . 
              (isset($grade_reports['all_grades']) ? count($grade_reports['all_grades']) : 0) . " total grades");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Error fetching grade reports: " . $e->getMessage());
}

// ==================== COMPREHENSIVE MOODLE DATA COLLECTION ====================

// 1. ATTENDANCE DATA
$attendance_data = [];
$total_attendance_sessions = 0;
$attended_sessions = 0;
$attendance_percentage = 0;

try {
    error_log("Elementary MyReports: Fetching attendance data...");
    
    // Check if attendance module exists
    if ($DB->get_manager()->table_exists('attendance')) {
        foreach ($courses as $course) {
            if ($course->id == SITEID) continue;
            
            // Get attendance instances
            $attendances = $DB->get_records('attendance', ['course' => $course->id]);
            
            foreach ($attendances as $attendance) {
                // Get sessions
                $sessions = $DB->get_records('attendance_sessions', ['attendanceid' => $attendance->id]);
                $total_attendance_sessions += count($sessions);
                
                // Get user's attendance logs
                foreach ($sessions as $session) {
                    $log = $DB->get_record_sql(
                        "SELECT al.*, ast.acronym, ast.description 
                         FROM {attendance_log} al
                         JOIN {attendance_statuses} ast ON al.statusid = ast.id
                         WHERE al.sessionid = ? AND al.studentid = ?",
                        [$session->id, $USER->id]
                    );
                    
                    if ($log) {
                        $attendance_data[] = [
                            'course' => $course->fullname,
                            'date' => userdate($session->sessdate, '%d %B %Y'),
                            'status' => $log->acronym,
                            'description' => $log->description,
                            'remarks' => $log->remarks ?: 'None'
                        ];
                        
                        // Count as attended if Present
                        if (strtolower($log->acronym) == 'p' || strtolower($log->description) == 'present') {
                            $attended_sessions++;
                        }
                    }
                }
            }
        }
        
        if ($total_attendance_sessions > 0) {
            $attendance_percentage = round(($attended_sessions / $total_attendance_sessions) * 100, 1);
        }
    }
    
    error_log("Elementary MyReports: Attendance - {$attended_sessions}/{$total_attendance_sessions} sessions ({$attendance_percentage}%)");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Attendance error - " . $e->getMessage());
}

// 2. TIME SPENT / HOURS IN COURSES
$time_spent_data = [];
$time_spent_progress = [];
$total_hours_spent = 0;
$total_activities_accessed = 0;
$most_active_course = '';
$most_active_hours = 0;

try {
    error_log("Elementary MyReports: Calculating time spent...");
    
    foreach ($courses as $course) {
        if ($course->id == SITEID) continue;
        
        // Get user's log entries for time calculation
        $logs = $DB->get_records_sql(
            "SELECT COUNT(*) as actions, MIN(timecreated) as first_access, MAX(timecreated) as last_access
             FROM {logstore_standard_log}
             WHERE userid = ? AND courseid = ?",
            [$USER->id, $course->id]
        );
        
        if ($logs) {
            $log = reset($logs);
            $actions = (int)$log->actions;
            
            // Estimate hours: Each action ≈ 5 minutes average
            $estimated_hours = round(($actions * 5) / 60, 1);
            $total_hours_spent += $estimated_hours;
            $total_activities_accessed += $actions;
            
            if ($estimated_hours > $most_active_hours) {
                $most_active_hours = $estimated_hours;
                $most_active_course = $course->fullname;
            }
            
    // Get action details for this course
    $action_details = [];
    try {
        $action_logs = $DB->get_records_sql(
            "SELECT component, action, target, COUNT(*) as count
             FROM {logstore_standard_log}
             WHERE userid = ? AND courseid = ?
             GROUP BY component, action, target
             ORDER BY count DESC
             LIMIT 10",
            [$USER->id, $course->id]
        );
        
        foreach ($action_logs as $action_log) {
            $action_name = '';
            // Format action name based on component and action
            if (strpos($action_log->component, 'mod_') === 0) {
                $modname = str_replace('mod_', '', $action_log->component);
                // Get human-readable module name
                $modnames = [
                    'assign' => 'Assignment',
                    'quiz' => 'Quiz',
                    'forum' => 'Forum',
                    'lesson' => 'Lesson',
                    'page' => 'Page',
                    'resource' => 'Resource',
                    'url' => 'Link',
                    'scorm' => 'SCORM',
                    'choice' => 'Choice',
                    'feedback' => 'Feedback',
                    'wiki' => 'Wiki',
                    'workshop' => 'Workshop'
                ];
                $mod_display = isset($modnames[$modname]) ? $modnames[$modname] : ucfirst($modname);
                
                // Format action
                $action_display = '';
                switch ($action_log->action) {
                    case 'view':
                        $action_display = 'Viewed';
                        break;
                    case 'submitted':
                        $action_display = 'Submitted';
                        break;
                    case 'attempted':
                        $action_display = 'Attempted';
                        break;
                    case 'created':
                        $action_display = 'Created';
                        break;
                    case 'updated':
                        $action_display = 'Updated';
                        break;
                    case 'deleted':
                        $action_display = 'Deleted';
                        break;
                    default:
                        $action_display = ucfirst(str_replace('_', ' ', $action_log->action));
                }
                
                $action_name = $mod_display . ' - ' . $action_display;
            } elseif ($action_log->component == 'core') {
                // Core system actions
                $action_name = 'Course ' . ucfirst(str_replace('_', ' ', $action_log->action));
            } else {
                $action_name = ucfirst(str_replace('_', ' ', $action_log->component)) . ' - ' . ucfirst(str_replace('_', ' ', $action_log->action));
            }
            
            $action_details[] = [
                'name' => $action_name,
                'count' => (int)$action_log->count,
                'component' => $action_log->component,
                'action' => $action_log->action
            ];
        }
    } catch (Exception $e) {
        error_log("Elementary MyReports: Error fetching action details for course {$course->id}: " . $e->getMessage());
            }
            
            $time_spent_data[] = [
                'course' => $course->fullname,
                'course_id' => $course->id,
                'hours' => $estimated_hours,
                'actions' => $actions,
                'first_access' => $log->first_access > 0 ? userdate($log->first_access, '%d %b %Y') : 'N/A',
                'last_access' => $log->last_access > 0 ? userdate($log->last_access, '%d %b %Y') : 'N/A',
                'action_details' => $action_details
            ];
        }
    }
    
    error_log("Elementary MyReports: Total time spent - {$total_hours_spent} hours across {$total_activities_accessed} actions");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Time tracking error - " . $e->getMessage());
}

// Prepare progress data (top 5 courses by minutes)
if (!empty($time_spent_data)) {
    $progressentries = [];
    $maxminutes = 0;
    foreach ($time_spent_data as $entry) {
        $minutes = round(($entry['hours'] ?? 0) * 60);
        if ($minutes <= 0) {
            continue;
        }
        $progressentries[] = [
            'course' => $entry['course'],
            'minutes' => $minutes,
            'action_details' => $entry['action_details'] ?? []
        ];
        if ($minutes > $maxminutes) {
            $maxminutes = $minutes;
        }
    }
    usort($progressentries, function($a, $b) {
        return $b['minutes'] <=> $a['minutes'];
    });
    $progressentries = array_slice($progressentries, 0, 5);
    foreach ($progressentries as &$progressentry) {
        $progressentry['percent'] = $maxminutes > 0 ? round(($progressentry['minutes'] / $maxminutes) * 100) : 0;
    }
    $time_spent_progress = $progressentries;
}

// 3. BADGES EARNED
$badges_data = [];
$total_badges = 0;

try {
    error_log("Elementary MyReports: Fetching badges...");
    
    $sql = "SELECT b.id, b.name, b.description, b.timecreated, bi.dateissued, bi.uniquehash
            FROM {badge} b
            JOIN {badge_issued} bi ON b.id = bi.badgeid
            WHERE bi.userid = ?
            ORDER BY bi.dateissued DESC";
    
    $badges = $DB->get_records_sql($sql, [$USER->id]);
    
    foreach ($badges as $badge) {
        $badges_data[] = [
            'name' => $badge->name,
            'description' => strip_tags($badge->description),
            'issued_date' => userdate($badge->dateissued, '%d %B %Y'),
            'badge_id' => $badge->id
        ];
        $total_badges++;
    }
    
    error_log("Elementary MyReports: Badges earned - {$total_badges}");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Badges error - " . $e->getMessage());
}

// 4. OUTLINE REPORT - Activity Completion per Course
$outline_report = [];
try {
    error_log("Elementary MyReports: Fetching activity outline...");
    
    foreach ($courses as $course) {
        if ($course->id == SITEID) continue;
        
        $modinfo = get_fast_modinfo($course);
        $completion = new completion_info($course);
        
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || !$cm->has_view() || $cm->deletioninprogress) {
                continue;
            }
            
            // Skip labels
            if ($cm->modname == 'label') {
                continue;
            }
            
            // Get completion data - safely handle if completion not enabled
            $completion_state = 0;
            $is_complete = false;
            try {
                if ($completion->is_enabled($cm)) {
            $completion_data = $completion->get_data($cm, false, $USER->id);
                    if ($completion_data) {
                        $completion_state = $completion_data->completionstate;
                        $is_complete = ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                       $completion_data->completionstate == COMPLETION_COMPLETE_PASS);
                    }
                }
            } catch (Exception $e) {
                error_log("Elementary MyReports: Error getting completion for outline activity {$cm->id}: " . $e->getMessage());
                // Continue with default values
            }
            
            // Get last access
            $last_access = 'Never';
            try {
                $sql = "SELECT MAX(timecreated) as lastaccess
                        FROM {logstore_standard_log}
                        WHERE userid = ? AND contextlevel = 70 AND contextinstanceid = ?";
                $access_data = $DB->get_record_sql($sql, [$USER->id, $cm->id]);
                if ($access_data && $access_data->lastaccess) {
                    $last_access = userdate($access_data->lastaccess, '%d %b %Y %H:%M');
                }
            } catch (Exception $e) {
                // Ignore
            }
            
            $outline_report[] = [
                'course' => $course->fullname,
                'activity' => $cm->name,
                'type' => $cm->modname,
                'completion_status' => $completion_state,
                'is_complete' => $is_complete,
                'last_access' => $last_access
            ];
        }
    }
    
    error_log("Elementary MyReports: Outline report - " . count($outline_report) . " activities tracked");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Outline report error - " . $e->getMessage());
}

// 5. CERTIFICATES EARNED
$certificates_data = [];
$total_certificates = 0;

try {
    error_log("Elementary MyReports: Fetching certificates...");
    
    if ($DB->get_manager()->table_exists('iomadcertificate_issues')) {
        $sql = "SELECT ci.id, ci.code, ci.timecreated, c.name as cert_name, co.fullname as course_name
                FROM {iomadcertificate_issues} ci
                JOIN {iomadcertificate} c ON ci.certificateid = c.id
                JOIN {course} co ON c.course = co.id
                WHERE ci.userid = ?
                ORDER BY ci.timecreated DESC";
        
        $certificates = $DB->get_records_sql($sql, [$USER->id]);
        
        foreach ($certificates as $cert) {
            $certificates_data[] = [
                'name' => $cert->cert_name,
                'course' => $cert->course_name,
                'code' => $cert->code,
                'issued_date' => userdate($cert->timecreated, '%d %B %Y')
            ];
            $total_certificates++;
        }
    }
    
    error_log("Elementary MyReports: Certificates earned - {$total_certificates}");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Certificates error - " . $e->getMessage());
}

// 8. LEARNING STREAK
$learning_streak = 0;
$current_streak = 0;
$last_access_date = null;

try {
    error_log("Elementary MyReports: Calculating learning streak...");
    
    $sql = "SELECT DISTINCT DATE(FROM_UNIXTIME(timecreated)) as access_date
            FROM {logstore_standard_log}
            WHERE userid = ?
            ORDER BY access_date DESC
            LIMIT 90";
    
    $access_dates = $DB->get_records_sql($sql, [$USER->id]);
    
    if ($access_dates) {
        $dates_array = array_values($access_dates);
        $current_streak = 1;
        
        for ($i = 0; $i < count($dates_array) - 1; $i++) {
            $current_date = strtotime($dates_array[$i]->access_date);
            $next_date = strtotime($dates_array[$i + 1]->access_date);
            
            $diff = ($current_date - $next_date) / (60 * 60 * 24);
            
            if ($diff == 1) {
                $current_streak++;
            } else {
                break;
            }
        }
        
        $learning_streak = $current_streak;
        $last_access_date = reset($dates_array)->access_date;
    }
    
    error_log("Elementary MyReports: Learning streak - {$learning_streak} days");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Streak calculation error - " . $e->getMessage());
}

// 9. FORUM PARTICIPATION
$forum_stats = [
    'total_posts' => 0,
    'total_discussions' => 0,
    'total_replies' => 0,
    'most_active_forum' => ''
];

try {
    error_log("Elementary MyReports: Analyzing forum participation...");
    
    // Get all forum posts
    $sql = "SELECT COUNT(DISTINCT fp.id) as total_posts,
                   COUNT(DISTINCT fd.id) as discussions,
                   COUNT(CASE WHEN fp.parent > 0 THEN 1 END) as replies
            FROM {forum_posts} fp
            LEFT JOIN {forum_discussions} fd ON fp.discussion = fd.id AND fp.userid = fd.userid
            WHERE fp.userid = ?";
    
    $forum_data = $DB->get_record_sql($sql, [$USER->id]);
    
    if ($forum_data) {
        $forum_stats['total_posts'] = (int)$forum_data->total_posts;
        $forum_stats['total_discussions'] = (int)$forum_data->discussions;
        $forum_stats['total_replies'] = (int)$forum_data->replies;
    }
    
    error_log("Elementary MyReports: Forum participation - {$forum_stats['total_posts']} posts, {$forum_stats['total_discussions']} discussions");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Forum stats error - " . $e->getMessage());
}

// 10. COMPETENCIES - Enhanced Student Learning Outcomes (Organized by Course)
$competencies_by_course = [];
$competency_stats = ['total' => 0, 'proficient' => 0, 'in_progress' => 0, 'not_rated' => 0];
$competencies_data = []; // Keep for backward compatibility

try {
    error_log("Elementary MyReports: Fetching REAL student competencies from Moodle...");
    
    // Check if competency tables exist
    $has_competency_tables = $DB->get_manager()->table_exists('competency') &&
                            $DB->get_manager()->table_exists('competency_usercomp') &&
                            $DB->get_manager()->table_exists('competency_coursecomp') &&
                            $DB->get_manager()->table_exists('competency_modulecomp');
    
    if ($has_competency_tables) {
        error_log("Elementary MyReports: Competency tables found - fetching data by course (using teacher's approach)");
        
        foreach ($courses as $course) {
            if ($course->id == SITEID) continue;
            
            // Fetch frameworks that have competencies linked in this course (same as teacher's page)
            $frameworks = $DB->get_records_sql(
                "SELECT DISTINCT f.id, f.shortname, f.idnumber
                   FROM {competency_coursecomp} cc
                   JOIN {competency} c ON c.id = cc.competencyid
                   JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                  WHERE cc.courseid = ?
               ORDER BY f.shortname ASC",
                array($course->id)
            );
            
            if (empty($frameworks)) {
                continue;
            }
            
            // Fetch all competencies for those frameworks that are linked to this course (same as teacher's page)
            $comps = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.shortname, c.idnumber, c.parentid, c.competencyframeworkid AS frameworkid, c.description, c.sortorder
                 FROM {competency_coursecomp} cc
                   JOIN {competency} c ON c.id = cc.competencyid
                  WHERE cc.courseid = ?
               ORDER BY c.sortorder, c.shortname",
                array($course->id)
            );
            
            if (empty($comps)) {
                continue;
            }
            
            // Build hierarchy: framework -> parents -> children (same as teacher's page)
            $byframework = array();
            foreach ($frameworks as $f) { 
                $byframework[$f->id] = array('framework' => $f, 'nodes' => array(), 'children' => array()); 
            }
            foreach ($comps as $c) {
                if (!isset($byframework[$c->frameworkid])) { continue; }
                $byframework[$c->frameworkid]['nodes'][$c->id] = $c;
                $byframework[$c->frameworkid]['children'][$c->parentid ?? 0][] = $c->id;
            }
            
            // Helper to count linked activities per competency (same as teacher's page)
            $hasmodulecomp = $DB->get_manager()->table_exists('competency_modulecomp');
            $hasactivity = $DB->get_manager()->table_exists('competency_activity');
            $countlinked = function(int $competencyid) use ($DB, $course, $hasmodulecomp, $hasactivity): int {
                if ($hasmodulecomp) {
                    return (int)$DB->get_field_sql(
                        "SELECT COUNT(1) FROM {competency_modulecomp} mc JOIN {course_modules} cm ON cm.id = mc.cmid WHERE mc.competencyid = ? AND cm.course = ?",
                        array($competencyid, $course->id)
                    );
                }
                if ($hasactivity) {
                    return (int)$DB->get_field_sql(
                        "SELECT COUNT(1) FROM {competency_activity} ca JOIN {course_modules} cm ON cm.id = ca.cmid WHERE ca.competencyid = ? AND cm.course = ?",
                        array($competencyid, $course->id)
                    );
                }
                return 0;
            };
            
            // Group all frameworks for this course
            $course_frameworks = [];
            
            // Process each framework separately
            foreach ($byframework as $fwid => $bundle) {
                $f = $bundle['framework'];
                $framework_name = $f->shortname;
                $framework_id = $f->id;
                
                $course_competencies_list = [];
                
                // Get user competency data for all competencies in this framework
                $comp_ids = array_keys($bundle['nodes']);
                if (!empty($comp_ids)) {
                    list($insql, $params) = $DB->get_in_or_equal($comp_ids, SQL_PARAMS_NAMED);
                    $params['userid'] = $USER->id;
                    $user_comps = $DB->get_records_sql(
                        "SELECT competencyid, proficiency, grade, timemodified 
                         FROM {competency_usercomp} 
                         WHERE userid = :userid AND competencyid $insql",
                        $params
                    );
                } else {
                    $user_comps = array();
                }
                
                // Get competency scale for rating labels
                $scale = null;
                $scale_items = [];
                try {
                    require_once($CFG->dirroot . '/competency/classes/api.php');
                    if (!empty($comp_ids)) {
                        $first_comp_id = reset($comp_ids);
                        $competencyobj = new \core_competency\competency($first_comp_id);
                        $scale = $competencyobj->get_scale();
                        if ($scale) {
                            $scale_items = $scale->scale_items;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Elementary MyReports: Error getting scale: " . $e->getMessage());
                }
                
                // Process all competencies in this framework using the bundle structure
                $process_competency = function($comp_id, $parent_level = 0) use (&$process_competency, &$bundle, &$course_competencies_list, &$countlinked, &$user_comps, &$course, &$DB, &$competency_stats, &$scale_items, &$CFG) {
                    if (!isset($bundle['nodes'][$comp_id])) {
                        return;
                    }
                    
                    $c = $bundle['nodes'][$comp_id];
                    $user_comp = $user_comps[$c->id] ?? null;
                    
                    // Get scale for this specific competency if scale_items is empty
                    $comp_scale_items = $scale_items;
                    if (empty($comp_scale_items)) {
                        try {
                            // Competency API already included above, no need to require again
                            $competencyobj = new \core_competency\competency($c->id);
                            $comp_scale = $competencyobj->get_scale();
                            if ($comp_scale) {
                                $comp_scale_items = $comp_scale->scale_items;
                            }
                        } catch (Exception $e) {
                            // Use empty array if scale cannot be retrieved
                        }
                    }
                    
                    // Get linked activities count
                    $linked_count = $countlinked($c->id);
                    
                    // Get linked activities details
                    $linked_activities = [];
                    if ($DB->get_manager()->table_exists('competency_modulecomp')) {
                        $activities = $DB->get_records_sql(
                            "SELECT cm.id as cmid, cm.course, cm.module, cm.instance,
                                    m.name as modname,
                                    CASE 
                                        WHEN m.name = 'quiz' THEN q.name
                                        WHEN m.name = 'assign' THEN a.name
                                        WHEN m.name = 'forum' THEN f.name
                                        WHEN m.name = 'scorm' THEN s.name
                                        WHEN m.name = 'lesson' THEN l.name
                                        WHEN m.name = 'page' THEN p.name
                                        WHEN m.name = 'resource' THEN r.name
                                        WHEN m.name = 'url' THEN u.name
                                        ELSE CONCAT('Activity ', cm.instance)
                                    END as activityname,
                                    cmc.ruleoutcome
                               FROM {competency_modulecomp} cmc
                               JOIN {course_modules} cm ON cm.id = cmc.cmid
                               JOIN {modules} m ON m.id = cm.module
                               LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
                               LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
                               LEFT JOIN {forum} f ON f.id = cm.instance AND m.name = 'forum'
                               LEFT JOIN {scorm} s ON s.id = cm.instance AND m.name = 'scorm'
                               LEFT JOIN {lesson} l ON l.id = cm.instance AND m.name = 'lesson'
                               LEFT JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
                               LEFT JOIN {resource} r ON r.id = cm.instance AND m.name = 'resource'
                               LEFT JOIN {url} u ON u.id = cm.instance AND m.name = 'url'
                              WHERE cmc.competencyid = ? AND cm.course = ? AND cm.visible = 1
                           ORDER BY cm.section, activityname",
                            [$c->id, $course->id]
                        );
                        
                        // Icon mapping for activity types
                        $type_icons = [
                            'assign' => 'fa-file-alt',
                            'quiz' => 'fa-question-circle',
                            'forum' => 'fa-comments',
                            'scorm' => 'fa-gamepad',
                            'lesson' => 'fa-book',
                            'page' => 'fa-file',
                            'resource' => 'fa-folder',
                            'url' => 'fa-link',
                            'book' => 'fa-book-open',
                            'choice' => 'fa-check-square',
                            'feedback' => 'fa-comment-dots'
                        ];
                        
                        foreach ($activities as $activity) {
                            $icon = $type_icons[$activity->modname] ?? 'fa-circle';
                            
                            // Get activity URL using course module
                            $activity_url = '';
                            try {
                                $cm = get_fast_modinfo($course->id)->get_cm($activity->cmid);
                                if ($cm && $cm->uservisible && isset($cm->url) && $cm->url !== null) {
                                    $activity_url = $cm->url->out(false);
                                } elseif ($cm && $cm->uservisible) {
                                    // If cm exists but has no URL, construct it manually
                                    $activity_url = (new moodle_url('/mod/' . $activity->modname . '/view.php', ['id' => $activity->cmid]))->out(false);
                                }
                            } catch (Exception $e) {
                                error_log("Elementary MyReports: Error getting URL for cmid {$activity->cmid}: " . $e->getMessage());
                                // Fallback: construct URL manually
                                try {
                                    $activity_url = (new moodle_url('/mod/' . $activity->modname . '/view.php', ['id' => $activity->cmid]))->out(false);
                                } catch (Exception $e2) {
                                    error_log("Elementary MyReports: Error constructing fallback URL for cmid {$activity->cmid}: " . $e2->getMessage());
                                    $activity_url = '';
                                }
                            }
                            
                            $linked_activities[] = [
                                'name' => $activity->activityname,
                                'type' => $activity->modname,
                                'type_icon' => $icon,
                                'cmid' => $activity->cmid,
                                'url' => $activity_url,
                                'ruleoutcome' => $activity->ruleoutcome,
                                'has_url' => !empty($activity_url)
                            ];
                        }
                    }
            
                    // Determine proficiency status and get actual rating label
            $proficiency_text = 'Not Rated';
            $proficiency_class = 'not-rated';
            $numeric_grade = 0;
                    $is_achieved = false;
                    $rating_label = '';
            
                    if ($user_comp && $user_comp->grade !== null && $user_comp->grade > 0) {
                        $numeric_grade = (int)$user_comp->grade;
                        
                        // Get the actual rating label from scale items
                        if (!empty($comp_scale_items) && $numeric_grade > 0) {
                            $grade_index = $numeric_grade - 1; // Scale items are 0-indexed
                            if (isset($comp_scale_items[$grade_index])) {
                                $rating_label = $comp_scale_items[$grade_index];
                            }
                        }
                        
                        // Define which ratings are considered "achieved"
                        $achieved_ratings = ['Developing', 'Mastery', 'Proficient', 'Component', 'Competent'];
                        $is_achieved = in_array($rating_label, $achieved_ratings);
                        
                        if ($is_achieved) {
                            // Use the actual rating label (e.g., "Developing", "Mastery", "Component", "Proficient")
                            $proficiency_text = $rating_label ? ($rating_label . ' ✓') : 'Achieved ✓';
                $proficiency_class = 'proficient';
                $competency_stats['proficient']++;
                        } elseif ($user_comp->proficiency == 1) {
                            // Fallback: if proficiency flag is set but rating not in achieved list, still show as proficient
                            $proficiency_text = $rating_label ? ($rating_label . ' ✓') : 'Proficient ✓';
                            $proficiency_class = 'proficient';
                            $competency_stats['proficient']++;
                            $is_achieved = true;
                        } else {
                            // Has grade but not in achieved ratings
                            $proficiency_text = $rating_label ?: 'In Progress';
                $proficiency_class = 'in-progress';
                $competency_stats['in_progress']++;
                        }
            } else {
                $proficiency_text = 'Not Rated';
                $proficiency_class = 'not-rated';
                $competency_stats['not_rated']++;
                $numeric_grade = 0;
            }
            
                    // Get child competencies
                    $children_ids = $bundle['children'][$c->id] ?? array();
                    $children = [];
                    foreach ($children_ids as $child_id) {
                        $child_data = $process_competency($child_id, $parent_level + 1);
                        if ($child_data) {
                            $children[] = $child_data;
                        }
            }
            
                    $comp_data = [
                        'id' => $c->id,
                        'parentid' => (int)($c->parentid ?? 0),
                        'name' => $c->shortname,
                        'description' => strip_tags(substr($c->description ?: '', 0, 200)),
                'proficiency' => $proficiency_text,
                'proficiency_class' => $proficiency_class,
                'grade' => $numeric_grade > 0 ? $numeric_grade : 'Not Rated',
                        'rating_label' => $rating_label ?: '',
                        'is_achieved' => $is_achieved,
                        'date' => ($user_comp && $user_comp->timemodified) ? userdate($user_comp->timemodified, '%d %B %Y') : 'Not Assessed',
                        'linked_activities' => $linked_activities,
                        'has_linked_activities' => !empty($linked_activities),
                        'linked_count' => $linked_count,
                        'children' => $children,
                        'level' => $parent_level
                    ];
                    
                    // Add to flat list
                    $course_competencies_list[] = $comp_data;
            $competency_stats['total']++;
            
                    return $comp_data;
                };
                
                // Process top-level competencies (both parentid = 0 and parentid = null)
                $topLevelIds = array_merge(
                    $bundle['children'][0] ?? array(),
                    $bundle['children'][null] ?? array()
                );
                $topLevelIds = array_unique($topLevelIds);
                
                $root_competencies = [];
                foreach ($topLevelIds as $cid) {
                    $comp_data = $process_competency($cid, 0);
                    if ($comp_data) {
                        $root_competencies[] = $comp_data;
                    }
                }
                
                if (!empty($root_competencies)) {
                
                // Recursive function to count all competencies including children
                $count_all_competencies = function($comps) use (&$count_all_competencies) {
                    $count = count($comps);
                    foreach ($comps as $comp) {
                        if (!empty($comp['children'])) {
                            $count += $count_all_competencies($comp['children']);
                        }
                    }
                    return $count;
                };
                
                // Recursive function to count achieved/proficient including children
                $count_proficient = function($comps) use (&$count_proficient) {
                    $count = 0;
                    foreach ($comps as $comp) {
                        // Check if achieved using is_achieved flag or proficiency_class
                        if (isset($comp['is_achieved']) && $comp['is_achieved']) {
                            $count++;
                        } elseif ($comp['proficiency_class'] === 'proficient') {
                            $count++;
                        }
                        if (!empty($comp['children'])) {
                            $count += $count_proficient($comp['children']);
                        }
                    }
                    return $count;
                };
                
                // Recursive function to clean competency data for JSON encoding
                $clean_competency_recursive = function($comp) use (&$clean_competency_recursive) {
                    // Clean linked activities to ensure URLs are included
                    $linked_activities = [];
                    if (!empty($comp['linked_activities']) && is_array($comp['linked_activities'])) {
                        foreach ($comp['linked_activities'] as $act) {
                            $linked_activities[] = [
                                'name' => $act['name'] ?? '',
                                'type' => $act['type'] ?? '',
                                'type_icon' => $act['type_icon'] ?? 'fa-circle',
                                'cmid' => $act['cmid'] ?? 0,
                                'url' => $act['url'] ?? '',
                                'has_url' => !empty($act['url'] ?? ''),
                                'ruleoutcome' => $act['ruleoutcome'] ?? null
                            ];
                        }
                    }
                    
                    $clean = [
                        'id' => $comp['id'],
                        'name' => $comp['name'] ?? '',
                        'description' => $comp['description'] ?? '',
                        'proficiency' => $comp['proficiency'] ?? 'Not Rated',
                        'proficiency_class' => $comp['proficiency_class'] ?? 'not-rated',
                        'grade' => $comp['grade'] ?? 'Not Rated',
                        'rating_label' => $comp['rating_label'] ?? '',
                        'is_achieved' => isset($comp['is_achieved']) ? (bool)$comp['is_achieved'] : false,
                        'date' => $comp['date'] ?? 'Not Assessed',
                        'level' => $comp['level'] ?? 0,
                        'linked_count' => $comp['linked_count'] ?? 0,
                        'linked_activities' => $linked_activities,
                        'has_linked_activities' => !empty($linked_activities),
                        'children' => []
                    ];
                    
                    if (!empty($comp['children']) && is_array($comp['children'])) {
                        foreach ($comp['children'] as $child) {
                            $clean['children'][] = $clean_competency_recursive($child);
                        }
                    }
                    
                    return $clean;
                };
                
                // Convert to JSON for JavaScript rendering
                $clean_competencies = array_map($clean_competency_recursive, $root_competencies);
                
                // HTML-escape the JSON for safe use in data attributes
                $json_encoded = json_encode($clean_competencies, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                $competencies_json = $json_encoded !== false ? htmlspecialchars($json_encoded, ENT_QUOTES, 'UTF-8') : '';
                
                if ($json_encoded === false) {
                    error_log("Elementary MyReports: JSON encoding failed for course {$course->id}: " . json_last_error_msg());
                    $competencies_json = '[]';
                }
                
                // Ensure competencies_json is always set
                if (empty($competencies_json)) {
                    $competencies_json = '[]';
                }
                
                // Store framework data
                $course_frameworks[] = [
                    'framework_name' => $framework_name,
                    'framework_id' => $framework_id,
                    'competencies' => $root_competencies,
                    'competencies_json' => $competencies_json,
                    'competencies_count' => $count_all_competencies($root_competencies),
                    'proficient_count' => $count_proficient($root_competencies),
                    'in_progress_count' => count(array_filter($course_competencies_list, function($c) { return isset($c['proficiency_class']) && $c['proficiency_class'] === 'in-progress'; })),
                    'not_rated_count' => count(array_filter($course_competencies_list, function($c) { return isset($c['proficiency_class']) && $c['proficiency_class'] === 'not-rated'; }))
                ];
                        }
            } // End foreach framework
            
            // Add single course entry with all frameworks
            if (!empty($course_frameworks)) {
                // Calculate total counts across all frameworks for this course
                $total_competencies = 0;
                $total_proficient = 0;
                $total_in_progress = 0;
                $total_not_rated = 0;
                
                foreach ($course_frameworks as $fw_data) {
                    $total_competencies += $fw_data['competencies_count'];
                    $total_proficient += $fw_data['proficient_count'];
                    $total_in_progress += $fw_data['in_progress_count'];
                    $total_not_rated += $fw_data['not_rated_count'];
                }
                
                $competencies_by_course[] = [
                    'course_id' => $course->id,
                    'course_name' => $course->fullname,
                    'course_shortname' => $course->shortname,
                    'frameworks' => $course_frameworks,
                    'competencies_count' => $total_competencies,
                    'proficient_count' => $total_proficient,
                    'in_progress_count' => $total_in_progress,
                    'not_rated_count' => $total_not_rated
                ];
            }
        } // End foreach course
    } else {
        error_log("Elementary MyReports: Competency tables do not exist in database");
    }
    
    $competency_stats['percentage'] = $competency_stats['total'] > 0 ? 
        round(($competency_stats['proficient'] / $competency_stats['total']) * 100, 1) : 0;
    
    error_log("Elementary MyReports: REAL Competencies fetched - {$competency_stats['proficient']}/{$competency_stats['total']} proficient");
    error_log("Elementary MyReports: Competency breakdown - Proficient: {$competency_stats['proficient']}, In Progress: {$competency_stats['in_progress']}, Not Rated: {$competency_stats['not_rated']}");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Competencies error - " . $e->getMessage());
    error_log("Elementary MyReports: Error trace - " . $e->getTraceAsString());
}

// 11. RUBRIC ASSESSMENTS - Detailed Evaluation Criteria
$rubric_assessments = [];
$rubric_stats = ['total' => 0, 'average_score' => 0, 'criteria_count' => 0];

try {
    error_log("Elementary MyReports: Fetching rubric assessments...");
    
    if ($DB->get_manager()->table_exists('grading_definitions')) {
        foreach ($courses as $course) {
            if ($course->id == SITEID) continue;
            
            // Get assignments with rubric grading - matching teacher report logic
            $sql = "SELECT DISTINCT a.id AS assignment_id, a.name AS assignment_name, a.course, 
                           c.fullname AS course_fullname, c.shortname AS course_shortname,
                           cm.id AS cmid, ag.id AS grade_id, ag.grade AS assignment_grade,
                           gi.id AS grade_item_id, gi.itemname, gi.grademax,
                           gd.id AS definition_id, gd.method, gd.name AS rubric_name
                    FROM {assign} a
                    JOIN {course} c ON c.id = a.course
                    JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = ?
                    JOIN {grading_areas} ga ON ga.contextid = ctx.id
                    JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric' AND gd.status > 0
                    LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = ?
                    LEFT JOIN {grade_items} gi ON gi.courseid = a.course 
                        AND gi.itemmodule = 'assign' AND gi.iteminstance = a.id
                    WHERE a.course = ? AND ag.id IS NOT NULL
                    ORDER BY ag.timemodified DESC";
            
            $rubric_assignments = $DB->get_records_sql($sql, [CONTEXT_MODULE, $USER->id, $course->id]);
            
            foreach ($rubric_assignments as $assignment) {
                // Get rubric data using the helper function (like teacher report does)
                $rubric_data = null;
                if ($assignment->cmid) {
                    $rubric_data = theme_remui_kids_get_rubric_by_cmid($assignment->cmid);
                }
                
                // Get grading instance and fillings (like teacher report does)
                $total_score = 0;
                $max_score = 0;
                $criteria_details = [];
                $criteria_count = 0;
                
                if ($assignment->grade_id && $rubric_data) {
                    // Get grading instance
                    $grading_instance = $DB->get_record('grading_instances', 
                        ['itemid' => $assignment->grade_id],
                        '*',
                        IGNORE_MULTIPLE
                    );
                    
                    if ($grading_instance) {
                        // Get rubric fillings for this grading instance
                        $fillings = $DB->get_records('gradingform_rubric_fillings', 
                            ['instanceid' => $grading_instance->id]
                        );
                        
                        // Calculate total score from fillings
                        foreach ($fillings as $filling) {
                            $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
                            if ($level) {
                                $total_score += $level->score;
                                
                                // Get criterion info
                                $criterion = $DB->get_record('gradingform_rubric_criteria', ['id' => $filling->criterionid]);
                                if ($criterion) {
                                    $criteria_details[] = [
                                        'criterion' => strip_tags($criterion->description),
                                        'score' => $level->score,
                                        'remark' => $filling->remark ?: ''
                                    ];
                                }
                            }
                        }
                    }
                    
                    // Calculate max score from rubric criteria
                    if ($rubric_data && !empty($rubric_data['criteria'])) {
                        foreach ($rubric_data['criteria'] as $criterion) {
                            $scores = array_column(array_map(function($l) { return (array)$l; }, $criterion['levels']), 'score');
                            $max_score += max($scores);
                        $criteria_count++;
                    }
                }
                }
                
                // Calculate percentage based on actual rubric score / max score
                $percentage = 0;
                if ($max_score > 0 && $total_score > 0) {
                    $percentage = round(($total_score / $max_score) * 100, 1);
                }
                
                // Only add if we have a valid grade
                if ($assignment->grade_id && $total_score > 0) {
                $rubric_assessments[] = [
                        'item_name' => $assignment->itemname ?: $assignment->assignment_name,
                    'course' => $course->fullname,
                        'rubric_name' => $assignment->rubric_name ?: 'Rubric Assessment',
                        'score' => round($total_score, 1),
                        'max_score' => $max_score,
                    'percentage' => $percentage,
                        'criteria' => $criteria_details,
                    'criteria_count' => $criteria_count
                ];
                
                $rubric_stats['total']++;
                $rubric_stats['average_score'] += $percentage;
                $rubric_stats['criteria_count'] += $criteria_count;
                }
            }
        }
        
        if ($rubric_stats['total'] > 0) {
            $rubric_stats['average_score'] = round($rubric_stats['average_score'] / $rubric_stats['total'], 1);
        }
    }
    
    error_log("Elementary MyReports: Rubric assessments - {$rubric_stats['total']} rubrics with {$rubric_stats['criteria_count']} criteria");
    
} catch (Exception $e) {
    error_log("Elementary MyReports: Rubric assessment error - " . $e->getMessage());
}

// ==================== END COMPREHENSIVE DATA COLLECTION ====================

// 11. DETAILED ATTENDANCE RECORDS
$detailed_attendance = [];
try {
    if ($DB->get_manager()->table_exists('attendance')) {
        foreach ($courses as $course) {
            if ($course->id == SITEID) continue;
            
            $attendances = $DB->get_records('attendance', ['course' => $course->id]);
            foreach ($attendances as $attendance) {
                $sessions = $DB->get_records_sql(
                    "SELECT ats.*, al.statusid, ast.acronym, ast.description
                     FROM {attendance_sessions} ats
                     LEFT JOIN {attendance_log} al ON ats.id = al.sessionid AND al.studentid = ?
                     LEFT JOIN {attendance_statuses} ast ON al.statusid = ast.id
                     WHERE ats.attendanceid = ?
                     ORDER BY ats.sessdate DESC
                     LIMIT 30",
                    [$USER->id, $attendance->id]
                );
                
                foreach ($sessions as $session) {
                    $status_class = 'unmarked';
                    $status_text = 'Not Marked';
                    
                    if ($session->acronym) {
                        $acronym = strtolower($session->acronym);
                        if ($acronym == 'p') {
                            $status_class = 'present';
                            $status_text = 'Present';
                        } elseif ($acronym == 'a') {
                            $status_class = 'absent';
                            $status_text = 'Absent';
                        } elseif ($acronym == 'l') {
                            $status_class = 'late';
                            $status_text = 'Late';
                        } elseif ($acronym == 'e') {
                            $status_class = 'excused';
                            $status_text = 'Excused';
                        }
                    }
                    
                    $detailed_attendance[] = [
                        'course' => $course->fullname,
                        'date' => userdate($session->sessdate, '%d %B %Y'),
                        'time' => userdate($session->sessdate, '%H:%M'),
                        'duration' => $session->duration ? round($session->duration / 60) . ' min' : 'N/A',
                        'status' => $status_text,
                        'status_class' => $status_class,
                        'description' => $session->description ?: 'Regular session'
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Elementary MyReports: Detailed attendance error - " . $e->getMessage());
}

// 12. PROGRESS SUMMARY - Overall academic progress
$progress_summary = [
    'overall_completion' => 0,
    'activities_completed' => $dashboard_stats['completed_activities'],
    'activities_total' => $dashboard_stats['total_activities'],
    'courses_completed' => $dashboard_stats['completed_courses'],
    'courses_total' => $dashboard_stats['total_courses'],
    'average_grade' => $dashboard_stats['average_grade'],
    'performance_level' => $dashboard_stats['performance_rating']
];

if ($dashboard_stats['total_activities'] > 0) {
    $progress_summary['overall_completion'] = round(
        ($dashboard_stats['completed_activities'] / $dashboard_stats['total_activities']) * 100, 1
    );
}

// 13. COURSE CATEGORIES BREAKDOWN
$category_breakdown = [];
try {
    foreach ($courses as $course) {
        if ($course->id == SITEID) continue;
        
        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $cat_name = $category ? $category->name : 'Uncategorized';
        
        if (!isset($category_breakdown[$cat_name])) {
            $category_breakdown[$cat_name] = [
                'name' => $cat_name,
                'count' => 0,
                'completed' => 0
            ];
        }
        
        $category_breakdown[$cat_name]['count']++;
        
        $completion = new completion_info($course);
        if ($completion->is_enabled() && $completion->is_course_complete($USER->id)) {
            $category_breakdown[$cat_name]['completed']++;
        }
    }
    
    $category_breakdown = array_values($category_breakdown);
} catch (Exception $e) {
    error_log("Elementary MyReports: Category breakdown error - " . $e->getMessage());
}

// 14. WEEKLY ACTIVITY HEATMAP DATA WITH COURSE BREAKDOWNS
$weekly_activity = [];
try {
    $module_labels = [
        'assign' => ['singular' => 'assignment', 'plural' => 'assignments'],
        'quiz' => ['singular' => 'quiz', 'plural' => 'quizzes'],
        'lesson' => ['singular' => 'lesson', 'plural' => 'lessons'],
        'forum' => ['singular' => 'post', 'plural' => 'posts'],
        'resource' => ['singular' => 'resource', 'plural' => 'resources'],
        'page' => ['singular' => 'page', 'plural' => 'pages'],
        'url' => ['singular' => 'link', 'plural' => 'links'],
        'book' => ['singular' => 'chapter', 'plural' => 'chapters'],
        'scorm' => ['singular' => 'game', 'plural' => 'games'],
        'lesson' => ['singular' => 'lesson', 'plural' => 'lessons'],
        'workshop' => ['singular' => 'project', 'plural' => 'projects'],
        'choice' => ['singular' => 'poll', 'plural' => 'polls'],
        'feedback' => ['singular' => 'feedback entry', 'plural' => 'feedback entries'],
        'h5pactivity' => ['singular' => 'H5P activity', 'plural' => 'H5P activities']
    ];
    $action_phrases = [
        'view' => 'completed',
        'submit' => 'submitted',
        'submitted' => 'submitted',
        'attempt' => 'attempted',
        'attempted' => 'attempted',
        'post' => 'posted',
        'posted' => 'posted',
        'reply' => 'replied',
        'replied' => 'replied',
        'create' => 'created',
        'created' => 'created',
        'update' => 'updated'
    ];
    
    // Get the start of the week (Monday)
    $today = time();
    $day_of_week = date('w', $today); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
    // Calculate days to subtract to get to Monday
    // Sunday (0) -> subtract 6 days, Monday (1) -> subtract 0 days, Tuesday (2) -> subtract 1 day, etc.
    $days_to_monday = ($day_of_week == 0) ? 6 : ($day_of_week - 1);
    $monday_timestamp = strtotime("-$days_to_monday days", $today);
    
    // Generate 7 days starting from Monday (Monday to Sunday)
    $today_str = date('Y-m-d', $today);
    
    for ($i = 0; $i < 7; $i++) {
        $date = strtotime("+$i days", $monday_timestamp);
        $date_str = date('Y-m-d', $date);
        $day_name = date('D', $date);
        $is_today = ($date_str === $today_str);
        
        $sql = "SELECT COUNT(*) as actions
                FROM {logstore_standard_log}
                WHERE userid = ? 
                AND DATE(FROM_UNIXTIME(timecreated)) = ?";
        
        $result = $DB->get_record_sql($sql, [$USER->id, $date_str]);
        $actions_count = $result ? (int)$result->actions : 0;
        
        // Gather per-course activity details for the day (top 5)
        $detail_sql = "SELECT l.courseid, c.fullname, l.component, l.action, COUNT(*) as count
                       FROM {logstore_standard_log} l
                       JOIN {course} c ON c.id = l.courseid
                       WHERE l.userid = ? 
                       AND DATE(FROM_UNIXTIME(l.timecreated)) = ?
                       GROUP BY l.courseid, c.fullname, l.component, l.action
                       ORDER BY count DESC
                       LIMIT 5";
        $detail_records = $DB->get_records_sql($detail_sql, [$USER->id, $date_str]);
        $details = [];
        foreach ($detail_records as $detail) {
            $component = $detail->component ?? '';
            $modname = preg_replace('/^mod_/', '', $component);
            $labelset = $module_labels[$modname] ?? ['singular' => 'activity', 'plural' => 'activities'];
            $label = ((int)$detail->count === 1) ? $labelset['singular'] : $labelset['plural'];
            $actionkey = strtolower($detail->action ?? '');
            $actionphrase = $action_phrases[$actionkey] ?? 'completed';
            $course_name = format_string($detail->fullname ?? '');
            
            $details[] = [
                'text' => sprintf(
                    '%d %s %s in %s',
                    (int)$detail->count,
                    $label,
                    $actionphrase,
                    $course_name
                ),
                'count' => (int)$detail->count,
                'course' => $course_name,
                'module_label' => $label,
                'action_phrase' => $actionphrase
            ];
        }
        
        // Determine engagement level based on action counts
        $engagement = [
            'emoji' => '😐',
            'label' => 'Low engagement'
        ];
        if ($actions_count >= 150) {
            $engagement = [
                'emoji' => '😊',
                'label' => 'High engagement'
            ];
        } elseif ($actions_count >= 60) {
            $engagement = [
                'emoji' => '🙂',
                'label' => 'Good engagement'
            ];
        }
        
        $weekly_activity[] = [
            'day' => $day_name,
            'date' => date('M d', $date),
            'actions' => $actions_count,
            'active' => $actions_count > 0,
            'today' => $is_today, // Mark today's date
            'details' => $details,
            'has_details' => !empty($details),
            'engagement_emoji' => $engagement['emoji'],
            'engagement_label' => $engagement['label'],
            'index' => count($weekly_activity) // Add index for modal
        ];
    }
} catch (Exception $e) {
    error_log("Elementary MyReports: Weekly activity error - " . $e->getMessage());
}

// 15. TOP PERFORMING SUBJECTS
$top_subjects = [];
try {
    foreach ($course_performance as $course_perf) {
        if ($course_perf['grade_percentage'] > 0) {
            $top_subjects[] = [
                'name' => $course_perf['course_name'],
                'grade' => $course_perf['grade_percentage'],
                'completion' => $course_perf['progress_percentage']
            ];
        }
    }
    
    // Sort by grade
    usort($top_subjects, function($a, $b) {
        return $b['grade'] - $a['grade'];
    });
    
    $top_subjects = array_slice($top_subjects, 0, 5);
} catch (Exception $e) {
    error_log("Elementary MyReports: Top subjects error - " . $e->getMessage());
}

// Prepare sidebar data for elementary
$sidebar_context = [
    'student_name' => fullname($USER),
    'dashboardurl' => new moodle_url('/my/'),
    'elementary_mycoursesurl' => new moodle_url('/theme/remui_kids/elementary_my_course.php'),
    'lessonsurl' => new moodle_url('/theme/remui_kids/elementary_lessons.php'),
    'activitiesurl' => new moodle_url('/theme/remui_kids/elementary_activities.php'),
    'achievementsurl' => new moodle_url('/theme/remui_kids/elementary_achievements.php'),
    'competenciesurl' => new moodle_url('/theme/remui_kids/elementary_competencies.php'),
    'scheduleurl' => new moodle_url('/theme/remui_kids/elementary_calendar.php'),
    'treeviewurl' => new moodle_url('/theme/remui_kids/elementary_treeview.php'),
    'myreportsurl' => new moodle_url('/theme/remui_kids/elementary_myreports.php'),
    'profileurl' => new moodle_url('/theme/remui_kids/elementary_profile.php'),
    'settingsurl' => new moodle_url('/user/preferences.php'),
    'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
    'scratcheditorurl' => new moodle_url('/theme/remui_kids/scratch_simple.php'),
    'codeeditorurl' => new moodle_url('/theme/remui_kids/code_editor_simple.php'),
    'studypartnerurl' => new moodle_url('/local/studypartner/index.php'),
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'is_myreports_page' => true,
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
    'data_source' => $has_real_data ? ($iomad_installed ? 'Real IOMAD Data' : 'Real Moodle Data') : 'No Data',
    'activity_types' => array_values($activity_types),
    'has_activity_types' => !empty($activity_types),
    'activity_type_items_json' => $activity_type_items_json ?: '{}',
    'activity_donut_style' => $activity_donut_style,
    'activity_donut_legend' => $activity_donut_legend,
    'grade_stars' => $grade_stars,
    
    // Color palette (matching competencies page)
    'color_palette' => $colorpalette,
    'framework_color_map' => $framework_color_map,
    'summary_card_colors' => $summary_card_colors,
    
    // Comprehensive Moodle Data
    'attendance_data' => $attendance_data,
    'has_attendance' => !empty($attendance_data),
    'attendance_stats' => [
        'total_sessions' => $total_attendance_sessions,
        'attended' => $attended_sessions,
        'percentage' => $attendance_percentage
    ],
    'time_spent_data' => $time_spent_data,
    'has_time_spent' => !empty($time_spent_data),
    'time_stats' => [
        'total_hours' => $total_hours_spent,
        'total_actions' => $total_activities_accessed,
        'most_active_course' => $most_active_course,
        'most_active_hours' => $most_active_hours
    ],
    'time_spent_progress' => $time_spent_progress,
    'has_time_spent_progress' => !empty($time_spent_progress),
    'badges_data' => $badges_data,
    'has_badges' => !empty($badges_data),
    'total_badges' => $total_badges,
    'learning_streak' => $learning_streak,
    'last_access_date' => $last_access_date,
    'forum_stats' => $forum_stats,
    'has_forum_activity' => $forum_stats['total_posts'] > 0,
    'detailed_attendance' => $detailed_attendance,
    'has_detailed_attendance' => !empty($detailed_attendance),
    'weekly_activity' => $weekly_activity,
    'has_weekly_activity' => !empty($weekly_activity),
    'weekly_activity_json' => !empty($weekly_activity) ? json_encode($weekly_activity, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) : '[]',
    'top_subjects' => $top_subjects,
    'has_top_subjects' => !empty($top_subjects),
    'category_breakdown' => $category_breakdown,
    'has_category_breakdown' => !empty($category_breakdown),
    'outline_report' => $outline_report,
    'has_outline_report' => !empty($outline_report),
    'progress_summary' => $progress_summary,
    'competencies_data' => $competencies_data,
    'has_competencies' => !empty($competencies_by_course),
    'competency_stats' => $competency_stats,
    'competencies_by_course' => $competencies_by_course,
    'has_competencies_by_course' => !empty($competencies_by_course),
    'rubric_assessments' => $rubric_assessments,
    'has_rubric_assessments' => !empty($rubric_assessments),
    'rubric_stats' => $rubric_stats
];

// Merge sidebar context
$templatecontext = array_merge($templatecontext, $sidebar_context);

// Apply mock data if needed (for testing when competencies/rubrics not enabled)
// Set to true to enable mock data, or it will auto-enable if debug mode is on
// Mock data will only apply if NO real data was found
$use_mock_data = true; // Change to false to disable mock data entirely
if ($use_mock_data && function_exists('apply_mock_data_if_needed')) {
    try {
    $templatecontext = apply_mock_data_if_needed($templatecontext, $use_mock_data);
    } catch (Exception $e) {
        error_log("Elementary MyReports: Error applying mock data: " . $e->getMessage());
        // Continue without mock data
    }
}

// Render the template - wrap in try-catch to prevent redirects
try {
    if (!isset($OUTPUT) || !is_object($OUTPUT)) {
        throw new Exception('OUTPUT object is not available');
    }
    
    // Output the page (Moodle manages its own output buffering)
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('theme_remui_kids/elementary_myreports_page', $templatecontext);
    echo $OUTPUT->footer();
} catch (Exception $e) {
    error_log("Elementary MyReports: Error rendering template: " . $e->getMessage());
    error_log("Elementary MyReports: Template error trace: " . $e->getTraceAsString());
    error_log("Elementary MyReports: Template error file: " . $e->getFile() . ':' . $e->getLine());
    
    // Try to output a basic error page instead of redirecting
    try {
        // Clean output buffers before error output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        if (isset($OUTPUT) && is_object($OUTPUT)) {
            echo $OUTPUT->header();
            echo '<div class="alert alert-danger"><h3>Error Loading Reports Page</h3>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
            echo '<p>Error details have been logged. If this problem persists, please contact your administrator.</p>';
            // Only show detailed trace in debug mode (if Moodle debugging is enabled)
            if (function_exists('debugging') && debugging('', DEBUG_DEVELOPER)) {
                echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            echo '</div>';
            echo $OUTPUT->footer();
        } else {
            // If OUTPUT is not available, output basic HTML with proper DOCTYPE
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
            echo '<h1>Error Loading Reports Page</h1>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
            // Only show detailed trace in debug mode (if Moodle debugging is enabled)
            if (function_exists('debugging') && debugging('', DEBUG_DEVELOPER)) {
                echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            echo '</body></html>';
        }
    } catch (Exception $e2) {
        // If even the error page fails, output raw error with proper DOCTYPE
        error_log("Elementary MyReports: Fatal error rendering page: " . $e2->getMessage());
        // Clean output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fatal Error</title></head><body>';
        echo '<h1>Fatal Error</h1>';
        echo '<p>An error occurred and the page could not be displayed.</p>';
        echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p>Secondary Error: ' . htmlspecialchars($e2->getMessage()) . '</p>';
        echo '</body></html>';
    }
}

restore_exception_handler();
