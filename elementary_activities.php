<?php
/**
 * Elementary Activities Page
 * Professional UI showing all real activities from enrolled courses
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

require_login();

global $USER, $PAGE, $OUTPUT, $DB;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_activities.php');
$PAGE->set_title('My Activities');
$PAGE->set_heading('My Activities');
$PAGE->set_pagelayout('mydashboard');

// Get filter parameter
$filter = optional_param('filter', 'all', PARAM_ALPHA);

// Get all enrolled courses
$courses = enrol_get_all_users_courses($USER->id, true);

$activities_data = [];
$total_activities = 0;
$completed_count = 0;
$in_progress_count = 0;
$not_started_count = 0;
$processed_activity_ids = []; // Track processed activity IDs to prevent duplicates

// Debug: Log the start
error_log("Activities page: Starting with " . count($courses) . " courses");

foreach ($courses as $course) {
    try {
        error_log("Activities page: Processing course " . $course->id . " - " . $course->fullname);
        $coursecontext = context_course::instance($course->id);
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        error_log("Activities page: Found " . count($cms) . " course modules in course " . $course->id);
        
        foreach ($cms as $cm) {
            try {
                // Only show activities that are visible and user can access
                if (!$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                
                // Skip labels and other non-interactive modules
                if ($cm->modname == 'label') {
                    continue;
                }
                
                // Skip subsection modules themselves - we want activities INSIDE them
                if ($cm->modname === 'subsection') {
                    // Get the subsection section that contains activities
                    $subsectionsection = $DB->get_record('course_sections', [
                        'component' => 'mod_subsection',
                        'itemid' => $cm->instance
                    ], '*', IGNORE_MISSING);
                    
                    if ($subsectionsection && !empty($subsectionsection->sequence)) {
                        // Get activities from inside this subsection module
                        $activity_cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
                        
                        foreach ($activity_cmids as $activity_cmid) {
                            if (!isset($modinfo->cms[$activity_cmid])) {
                                continue;
                            }
                            
                            $activity_cm = $modinfo->cms[$activity_cmid];
                            
                            // Skip if not visible, is another subsection, or is a label
                            if (!$activity_cm->uservisible || 
                                $activity_cm->modname === 'subsection' || 
                                $activity_cm->modname == 'label' ||
                                $activity_cm->deletioninprogress) {
                                continue;
                            }
                            
                            // Process this activity from within the subsection
                            process_activity($activity_cm, $course, $coursecontext, $completion, $modinfo, $DB, $USER, 
                                            $filter, $activities_data, $total_activities, $completed_count, 
                                            $in_progress_count, $not_started_count);
                        }
                    }
                    // Continue to next module (we've processed all activities inside this subsection)
                    continue;
                }
                
                // Regular activity (not inside a subsection) - process it normally
                process_activity($cm, $course, $coursecontext, $completion, $modinfo, $DB, $USER, 
                                $filter, $activities_data, $total_activities, $completed_count, 
                                $in_progress_count, $not_started_count);
            } catch (Exception $e) {
                // Log error and skip this activity
                error_log("Error processing activity: " . $e->getMessage());
                continue;
            }
        }
    } catch (Exception $e) {
        // Skip course if error
        continue;
    }
}

// Helper function to process an activity (used for both regular and subsection activities)
function process_activity($cm, $course, $coursecontext, $completion, $modinfo, $DB, $USER, 
                         $filter, &$activities_data, &$total_activities, &$completed_count, 
                         &$in_progress_count, &$not_started_count) {
    try {
        // Get completion data safely
        $iscompleted = false;
        $isinprogress = false;
        $isnotstarted = true;
        $progress = 0;
        $completion_enabled_for_module = isset($cm->completion) && $cm->completion != COMPLETION_TRACKING_NONE;
        
        try {
                $completiondata = $completion->get_data($cm, false, $USER->id);
                if ($completiondata) {
                    // Check both COMPLETION_COMPLETE and COMPLETION_COMPLETE_PASS (same as dashboard)
                    $iscompleted = ($completiondata->completionstate == COMPLETION_COMPLETE || 
                                   $completiondata->completionstate == COMPLETION_COMPLETE_PASS);
                }
        } catch (Exception $e) {
            error_log("Completion data error for activity {$cm->id}: " . $e->getMessage());
            // Continue with default values
        }
        
        if ($iscompleted) {
            $completed_count++;
            $isnotstarted = false;
            $progress = 100;
        } else {
            // Check if user has viewed/interacted with activity safely
                $hasviewed = false;
                try {
                    $hasviewed = $DB->record_exists('logstore_standard_log', [
                        'courseid' => $course->id,
                        'contextinstanceid' => $cm->id,
                        'userid' => $USER->id
                    ]);
                } catch (Exception $e) {
                    error_log("View log check error for activity {$cm->id}: " . $e->getMessage());
                    // Continue with default value (false)
                }
                    
            if ($hasviewed || $completion_enabled_for_module) {
                $in_progress_count++;
                $isinprogress = true;
                $isnotstarted = false;
                
                try {
                    if ($completion_enabled_for_module) {
                        $completiondata = $completion->get_data($cm, false, $USER->id);
                        if ($completiondata) {
                            $completiontype = $DB->get_field('course_modules', 'completion', ['id' => $cm->id], IGNORE_MISSING);
                            if ($completiontype == COMPLETION_TRACKING_AUTOMATIC && $hasviewed) {
                                $completionview = $DB->get_field('course_modules', 'completionview', ['id' => $cm->id], IGNORE_MISSING);
                                if ($completionview == 1) {
                                    $progress = 100;
                                    $iscompleted = true;
                                    $isinprogress = false;
                                    $completed_count++;
                                    $in_progress_count--;
                                } elseif ($completiondata->timestarted > 0) {
                                    $progress = calculate_activity_progress($cm, $completiondata, $DB, $USER);
                                }
                            } elseif ($completiondata->timestarted > 0) {
                                $progress = calculate_activity_progress($cm, $completiondata, $DB, $USER);
                            }
                        } else {
                            $progress = calculate_activity_progress($cm, null, $DB, $USER);
                        }
                    } else {
                        if ($cm->modname == 'lesson') {
                            $attempts = $DB->get_records_sql(
                                "SELECT * FROM {lesson_attempts} WHERE lessonid = ? AND userid = ? ORDER BY timeseen DESC LIMIT 1",
                                [$cm->instance, $USER->id]
                            );
                            $progress = $attempts ? 50 : 15;
                        } elseif ($cm->modname == 'forum') {
                            $discussion_id = $DB->get_field('forum_discussions', 'id', ['forum' => $cm->instance], IGNORE_MISSING);
                            if ($discussion_id) {
                                $posts = $DB->count_records('forum_posts', ['userid' => $USER->id, 'discussion' => $discussion_id]);
                                $progress = $posts > 0 ? 60 : 20;
                            } else {
                                $progress = 20;
                            }
                        } elseif (in_array($cm->modname, ['resource', 'file', 'url', 'page'])) {
                            $progress = 30;
                        } else {
                            $progress = 30;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Progress calculation error for activity {$cm->id}: " . $e->getMessage());
                    $progress = 25;
                }
            } else {
                $not_started_count++;
                $progress = 0;
            }
        }
            
            // Get activity icon and color
            $icon = theme_remui_kids_get_activity_icon($cm->modname);
            $type_display = ucfirst($cm->modname);
            
            // Get activity due date if available
            $duedate = '';
            $is_overdue = false;
            try {
                if ($cm->modname == 'assign') {
                    $assign = $DB->get_record('assign', ['id' => $cm->instance], 'duedate', IGNORE_MISSING);
                    if ($assign && $assign->duedate > 0) {
                        $duedate = userdate($assign->duedate, '%d %b %Y');
                        $is_overdue = $assign->duedate < time() && !$iscompleted;
                    }
                } elseif ($cm->modname == 'quiz') {
                    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'timeclose', IGNORE_MISSING);
                    if ($quiz && $quiz->timeclose > 0) {
                        $duedate = userdate($quiz->timeclose, '%d %b %Y');
                        $is_overdue = $quiz->timeclose < time() && !$iscompleted;
                    }
                } elseif ($cm->modname == 'lesson') {
                    $lesson = $DB->get_record('lesson', ['id' => $cm->instance], 'deadline', IGNORE_MISSING);
                    if ($lesson && $lesson->deadline > 0) {
                        $duedate = userdate($lesson->deadline, '%d %b %Y');
                        $is_overdue = $lesson->deadline < time() && !$iscompleted;
                    }
                }
        } catch (Exception $e) {
            // Log error but don't crash
            error_log("Due date error for activity {$cm->id}: " . $e->getMessage());
        }
        
        // Get estimated time
        $estimated_time = theme_remui_kids_get_activity_estimated_time($cm->modname);
        
        // Get course category safely
        $category = $DB->get_record('course_categories', ['id' => $course->category], '*', IGNORE_MISSING);
        $categoryname = $category ? $category->name : 'General';
        
        // Get course image safely
        $courseimage = '';
        try {
            $fs = get_file_storage();
            $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'filename', false);
            if ($files) {
                $file = reset($files);
                $courseimage = moodle_url::make_pluginfile_url(
                    $file->get_contextid(), 
                    $file->get_component(), 
                    $file->get_filearea(), 
                    $file->get_itemid(), 
                    $file->get_filepath(), 
                    $file->get_filename()
                )->out();
            }
        } catch (Exception $e) {
            error_log("Course image error for course {$course->id}: " . $e->getMessage());
            // Continue with empty course image
        }
        
        // Get activity URL safely - convert moodle_url object to string
        $activity_url = '';
        try {
            if ($cm->url) {
                $activity_url = $cm->url->out(false);
            } else {
                // Fallback URL construction - use course module id (standard Moodle view parameter)
                $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
            }
        } catch (Exception $e) {
            // If URL generation fails, try alternative approaches
            try {
                // Try with instance id
                $activity_url = (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->instance]))->out(false);
            } catch (Exception $e2) {
                // Last resort: use course URL
                $activity_url = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            }
        }
        
        // Get intro text safely
        $intro_text = '';
        try {
            if (method_exists($cm, 'get_formatted_content')) {
                $intro_text = $cm->get_formatted_content(['overflowdiv' => false, 'noclean' => false]);
            }
        } catch (Exception $e) {
            $intro_text = '';
        }
        
        // Get section name safely
        $section_name = 'Section';
        try {
            $section_info = $cm->get_section_info();
            $section_name = $section_info->name ?: 'Section ' . $cm->sectionnum;
        } catch (Exception $e) {
            $section_name = 'Section ' . $cm->sectionnum;
        }
        
        // Determine special activity type based on name
        $activity_name_lower = strtolower(format_string($cm->name));
        $special_type = $cm->modname; // Default to module name
        
        // Check for special activity types
        if (strpos($activity_name_lower, 'wrap-up') !== false || strpos($activity_name_lower, 'wrap up') !== false) {
            $special_type = 'wrapup';
        } elseif (strpos($activity_name_lower, 'module') !== false) {
            $special_type = 'module';
        } elseif (strpos($activity_name_lower, 'summary') !== false) {
            $special_type = 'summary';
        } elseif (strpos($activity_name_lower, 'review') !== false) {
            $special_type = 'review';
        }
        
        $activity_data = [
            'id' => $cm->id,
            'name' => format_string($cm->name),
            'courseid' => $course->id,
            'coursename' => format_string($course->fullname),
            'courseshortname' => format_string($course->shortname),
            'courseimage' => $courseimage,
            'categoryname' => $categoryname,
            'modulename' => $cm->modname,
            'type' => $special_type, // Use special type for CSS styling
            'type_display' => $type_display,
            'icon' => $icon,
            'url' => $activity_url,
            'completed' => $iscompleted,
            'in_progress' => $isinprogress,
            'not_started' => $isnotstarted,
            'progress' => $progress,
            'duedate' => $duedate,
            'has_duedate' => !empty($duedate),
            'is_overdue' => $is_overdue,
            'estimated_time' => $estimated_time,
            'intro' => $intro_text,
            'section_name' => $section_name,
            'section_id' => $cm->section,
            'section_num' => $cm->sectionnum,
            'can_access' => $cm->available
        ];
        
        // Apply filter
        if ($filter == 'pending' && $iscompleted) {
            return; // Skip this activity
        } elseif ($filter == 'completed' && !$iscompleted) {
            return; // Skip this activity
        }
        
        $activities_data[] = $activity_data;
            
    } catch (Exception $e) {
        error_log("Error processing activity {$cm->id} ({$cm->modname}): " . $e->getMessage());
        // Continue without adding this activity
    }
}

// Sort activities by status (overdue, in progress, not started, completed)
usort($activities_data, function($a, $b) {
    if ($a['is_overdue'] != $b['is_overdue']) {
        return $b['is_overdue'] - $a['is_overdue'];
    }
    if ($a['completed'] != $b['completed']) {
        return $a['completed'] - $b['completed'];
    }
    if ($a['in_progress'] != $b['in_progress']) {
        return $b['in_progress'] - $a['in_progress'];
    }
    return 0;
});

// Calculate overall progress (including partial progress)
$total_progress_points = 0;
foreach ($activities_data as $activity) {
    $total_progress_points += $activity['progress'];
}
$overall_progress = $total_activities > 0 ? round($total_progress_points / $total_activities) : 0;

// Prepare course filter data (unique courses)
$filter_courses = [];
$seen_courses = [];
foreach ($activities_data as $activity) {
    if (!in_array($activity['courseid'], $seen_courses)) {
        $filter_courses[] = [
            'courseid' => $activity['courseid'],
            'coursename' => $activity['coursename']
        ];
        $seen_courses[] = $activity['courseid'];
    }
}

// Sort courses alphabetically
usort($filter_courses, function($a, $b) {
    return strcmp($a['coursename'], $b['coursename']);
});

// Prepare lessons/sections data grouped by course for cascading filter
$lessons_by_course = [];
foreach ($activities_data as $activity) {
    $courseid = $activity['courseid'];
    $section_key = $activity['courseid'] . '_' . $activity['section_id'];
    
    if (!isset($lessons_by_course[$courseid])) {
        $lessons_by_course[$courseid] = [];
    }
    
    // Add section if not already added
    $section_exists = false;
    foreach ($lessons_by_course[$courseid] as $lesson) {
        if ($lesson['section_id'] == $activity['section_id']) {
            $section_exists = true;
            break;
        }
    }
    
    if (!$section_exists) {
        $lessons_by_course[$courseid][] = [
            'section_id' => $activity['section_id'],
            'section_name' => $activity['section_name'],
            'courseid' => $courseid
        ];
    }
}

// Sort lessons within each course
foreach ($lessons_by_course as $courseid => $lessons) {
    usort($lessons_by_course[$courseid], function($a, $b) {
        return strcmp($a['section_name'], $b['section_name']);
    });
}

// Convert to JSON for JavaScript
$lessons_by_course_json = json_encode($lessons_by_course);

// Prepare template context
$templatecontext = [
    'student_name' => $USER->firstname ?: $USER->username,
    'total_activities' => $total_activities,
    'completed_activities' => $completed_count,
    'in_progress_activities' => $in_progress_count,
    'not_started_activities' => $not_started_count,
    'overall_progress' => $overall_progress,
    'has_activities' => !empty($activities_data),
    'activities' => $activities_data,
    'no_activities' => empty($activities_data),
    
    // Cascading filter data
    'filter_courses' => $filter_courses,
    'has_filter_courses' => !empty($filter_courses),
    'lessons_by_course_json' => $lessons_by_course_json,
    
    // Filter states
    'filter_all' => ($filter == 'all'),
    'filter_pending' => ($filter == 'pending'),
    'filter_completed' => ($filter == 'completed'),
    
    // Page identification for sidebar
    'is_activities_page' => true,
    'is_mycourses_page' => false,
    'is_lessons_page' => false,
    
    // Navigation URLs
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'currentactivityurl' => (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    
    // User data
    'userid' => $USER->id,
    'username' => fullname($USER),
    'useremail' => $USER->email,
    'userprofileimageurl' => $OUTPUT->user_picture($USER, ['size' => 100]),
    
    'wwwroot' => $CFG->wwwroot
];

// Render the page
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_activities_page_clean', $templatecontext);
echo $OUTPUT->footer();
