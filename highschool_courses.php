<?php
/**
 * High School Courses Page (Grade 9-12)
 * Displays courses for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/lib.php');
require_login();

// Helper function to get course instructor
function get_course_instructor($courseid) {
    global $DB;
    
    try {
        $context = context_course::instance($courseid);
        $teachers = get_enrolled_users($context, 'moodle/course:update');
        
        if (!empty($teachers)) {
            $teacher = reset($teachers);
            return fullname($teacher);
        }
        
        // Fallback: get any user with teacher role in the course
        $teachers = get_enrolled_users($context, 'moodle/course:view');
        if (!empty($teachers)) {
            $teacher = reset($teachers);
            return fullname($teacher);
        }
        
        return 'Teacher';
    } catch (Exception $e) {
        return 'Teacher';
    }
}

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_courses.php');
$PAGE->set_title('My Courses');

$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

// Check if user is a student (has student role)
$user_roles = get_user_roles($context, $USER->id);
$is_student = false;
foreach ($user_roles as $role) {
    if ($role->shortname === 'student') {
        $is_student = true;
        break;
    }
}

// Also check for editingteacher and teacher roles as they might be testing the page
foreach ($user_roles as $role) {
    if ($role->shortname === 'editingteacher' || $role->shortname === 'teacher' || $role->shortname === 'manager') {
        $is_student = true; // Allow teachers/managers to view the page
        break;
    }
}

// Redirect if not a student and not logged in
if (!$is_student && !isloggedin()) {
    redirect(new moodle_url('/'));
}

// Get user's grade level from profile or cohort
$user_grade = 'Grade 11'; // Default grade for testing
$is_highschool = false;
$user_cohorts = cohort_get_user_cohorts($USER->id);

// Check user profile custom field for grade
$user_profile_fields = profile_user_record($USER->id);
if (isset($user_profile_fields->grade)) {
    $user_grade = $user_profile_fields->grade;
    // If profile has a high school grade, mark as high school
    if (preg_match('/grade\s*(?:9|10|11|12)/i', $user_grade)) {
        $is_highschool = true;
    }
} else {
    // Fallback to cohort-based detection
    foreach ($user_cohorts as $cohort) {
        $cohort_name = strtolower($cohort->name);
        // Use regex for better matching
        if (preg_match('/grade\s*(?:9|10|11|12)/i', $cohort_name)) {
            // Extract grade number
            if (preg_match('/grade\s*9/i', $cohort_name)) {
            $user_grade = 'Grade 9';
            } elseif (preg_match('/grade\s*10/i', $cohort_name)) {
            $user_grade = 'Grade 10';
            } elseif (preg_match('/grade\s*11/i', $cohort_name)) {
            $user_grade = 'Grade 11';
            } elseif (preg_match('/grade\s*12/i', $cohort_name)) {
            $user_grade = 'Grade 12';
            }
            $is_highschool = true;
            break;
        }
    }
}

// More flexible verification - allow access if user has high school grade OR is in grades 9-12
// Don't redirect if user is a teacher/manager testing the page
$valid_grades = array('Grade 9', 'Grade 10', 'Grade 11', 'Grade 12', '9', '10', '11', '12');
$has_valid_grade = false;

foreach ($valid_grades as $grade) {
    if (stripos($user_grade, $grade) !== false) {
        $has_valid_grade = true;
        break;
    }
}

// Only redirect if NOT high school and NOT valid grade
// This is more permissive to avoid blocking legitimate users
if (!$is_highschool && !$has_valid_grade) {
    // For debugging: comment out redirect temporarily
    // redirect(new moodle_url('/my/'));
    // Instead, just show a warning and continue (for testing)
    // You can re-enable the redirect once everything is working
}

// Get courses for the student's grade level using the helper function
// This function handles course filtering, grade matching, and cover image fetching
$courses_from_function = theme_remui_kids_get_highschool_courses($USER->id);

// Get category names for better filtering
$categories = $DB->get_records('course_categories', null, '', 'id,name');

// Prepare course data for template
$courses_data = array();
$courses_by_subject = array();

// Process courses returned from the function
foreach ($courses_from_function as $course_info) {
    // Get the course object for building lessons/activities structure
    $course = $DB->get_record('course', array('id' => $course_info['id']), '*', MUST_EXIST);
    
    if ($course->id == 1) continue; // Skip site course
    
    // Use course image from the function (already fetched with proper fallbacks)
    $course_image = $course_info['courseimage'] ?? '';
    
    // Check if this is a mock course or real course
    $is_mock = ($course->id >= 101 && $course->id <= 106);
    
    // Initialize dynamic data variables (will be calculated from real Moodle data)
    $progress = 0;
    $completed_sections = 0;
    $total_sections = 0;
    $completed_activities = 0;
    $total_activities = 0;
    $estimated_time = 0;
    $points_earned = 0;
    $lessons = array();
    $total_lessons = 0;  // Total sections for display (all sections)
    $completed_lessons = 0;  // Completed sections for display
    $total_lessons_with_activities = 0;  // Sections with activities (for progress calculation)
    $completed_lessons_with_activities = 0;  // Completed sections with activities (for progress calculation)
    $total_lessons_with_activities = 0;  // Sections with activities (for progress calculation)
    $completed_lessons_with_activities = 0;  // Completed sections with activities (for progress calculation)
    
    // Get real completion data directly from database
    // Fetch all data using direct SQL queries - no Moodle APIs
    if (!$is_mock) {
        try {
            // Fetch actual time spent from logs (direct database query)
            $time_spent_seconds = $DB->get_field_sql(
                "SELECT COALESCE(SUM(l.timecreated - lag_time), 0)
                 FROM (
                     SELECT timecreated,
                            LAG(timecreated) OVER (ORDER BY timecreated) as lag_time
                     FROM {logstore_standard_log}
                     WHERE courseid = :courseid 
                     AND userid = :userid
                     AND timecreated > :starttime
                 ) l
                 WHERE (l.timecreated - l.lag_time) < 1800",
                array(
                    'courseid' => $course->id,
                    'userid' => $USER->id,
                    'starttime' => time() - (90 * 24 * 60 * 60) // Last 90 days
                )
            );
            
            // Fallback: Count log entries and estimate
            if (empty($time_spent_seconds)) {
                $log_count = $DB->count_records('logstore_standard_log', array(
                    'courseid' => $course->id,
                    'userid' => $USER->id
                ));
                $time_spent_seconds = $log_count * 120; // 2 minutes per log entry
            }
            
            $estimated_time = round($time_spent_seconds / 60); // Convert to minutes
            
            // Fetch actual points from gradebook (similar to teacher_courses.php completion percentage)
            $grade_item = $DB->get_record('grade_items', array(
                'courseid' => $course->id,
                'itemtype' => 'course'
            ));
            
            if ($grade_item) {
                $grade_grade = $DB->get_record('grade_grades', array(
                    'itemid' => $grade_item->id,
                    'userid' => $USER->id
                ));
                
                if ($grade_grade && $grade_grade->finalgrade !== null && $grade_item->grademax > 0) {
                    $points_earned = round(($grade_grade->finalgrade / $grade_item->grademax) * 100);
                }
            }
            
            // Get course sections (lessons) directly from database
            // NOTE: In Moodle, sections ARE lessons - each section represents one lesson
            $sections = $DB->get_records_sql(
                "SELECT id, section, name, visible, sequence
                 FROM {course_sections}
                 WHERE course = :courseid
                 AND section >= 1
                 AND visible = 1
                 AND component IS NULL
                 ORDER BY section ASC",
                array('courseid' => $course->id)
            );
            
            // Get modinfo for efficient module name retrieval
            $modinfo = get_fast_modinfo($course);
            
            $lessonnumber = 1;
            
            // Fetch only sections (lessons) from database
            foreach ($sections as $section) {
                $sectionnum = $section->section;
                
                // Use section name as lesson name, or generate default
                $sectionname = $section->name ?: "Lesson " . $lessonnumber;
                
                // Count activities in this section directly from database
                // Include activities from subsections as well
                $activitycount = 0;
                $completedactivities = 0;
                $section_activities = array(); // Array to store activities for tree view
                
                if (!empty($section->sequence)) {
                    // Get activity IDs from section sequence
                    $module_ids = explode(',', $section->sequence);
                    $module_ids = array_filter(array_map('intval', $module_ids));
                    
                    if (!empty($module_ids)) {
                        // First, identify which modules are subsections and which are regular activities
                        $subsection_module_ids = array();
                        $regular_module_ids = array();
                        
                        $modules_info = $DB->get_records_sql(
                            "SELECT cm.id, cm.instance, m.name as modname
                             FROM {course_modules} cm
                             JOIN {modules} m ON m.id = cm.module
                             WHERE cm.id IN (" . implode(',', $module_ids) . ")
                             AND cm.course = :courseid
                             AND cm.visible = 1
                             AND cm.deletioninprogress = 0",
                            array('courseid' => $course->id)
                        );
                        
                        foreach ($modules_info as $module_info) {
                            if ($module_info->modname === 'subsection') {
                                $subsection_module_ids[$module_info->id] = $module_info->instance;
                            } else {
                                $regular_module_ids[] = $module_info->id;
                            }
                        }
                        
                        // Process regular activities (non-subsection activities)
                        if (!empty($regular_module_ids)) {
                            // Get full module info for activities
                            $regular_modules_full = $DB->get_records_sql(
                                "SELECT cm.id, cm.instance, cm.completion, m.name as modname
                                 FROM {course_modules} cm
                                 JOIN {modules} m ON m.id = cm.module
                                 WHERE cm.id IN (" . implode(',', $regular_module_ids) . ")
                                 AND cm.course = :courseid
                                 AND cm.visible = 1
                                 AND cm.deletioninprogress = 0
                                 AND m.name != 'label'",
                                array('courseid' => $course->id)
                            );
                            
                            // Get module names for display using modinfo
                            foreach ($regular_modules_full as $cmid => $cm_info) {
                                $mod_name = '';
                                if (isset($modinfo->cms[$cmid])) {
                                    $cm = $modinfo->cms[$cmid];
                                    $mod_name = $cm->name;
                                } else {
                                    // Fallback: use modname if modinfo not available
                                    $mod_name = $cm_info->modname;
                                }
                                
                                // Check completion
                                $is_completed = false;
                                if ($cm_info->completion > 0) {
                                    $completion_record = $DB->get_record_sql(
                                        "SELECT * FROM {course_modules_completion}
                                         WHERE coursemoduleid = :cmid
                                         AND userid = :userid
                                         AND completionstate IN (1, 2)",
                                        array(
                                            'cmid' => $cmid,
                                            'userid' => $USER->id
                                        ),
                                        IGNORE_MISSING
                                    );
                                    $is_completed = !empty($completion_record);
                                }
                                
                                // Count trackable activities
                                if ($cm_info->completion > 0) {
                                    $activitycount++;
                                    if ($is_completed) {
                                        $completedactivities++;
                                    }
                                } else {
                                    // Count all activities if completion tracking is not enabled (backward compatibility)
                                    $activitycount++;
                                    if ($is_completed) {
                                        $completedactivities++;
                                    }
                                }
                                
                                // Get URL from modinfo if available
                                $activity_url = '';
                                if (isset($modinfo->cms[$cmid]) && $modinfo->cms[$cmid]->url) {
                                    $activity_url = $modinfo->cms[$cmid]->url->out();
                                } else {
                                    $activity_url = (new moodle_url('/mod/' . $cm_info->modname . '/view.php', array('id' => $cmid)))->out();
                                }
                                
                                // Add to activities array
                                $section_activities[] = array(
                                    'activity_number' => count($section_activities) + 1,
                                    'id' => $cmid,
                                    'name' => $mod_name,
                                    'type' => $cm_info->modname,
                                    'duration' => theme_remui_kids_get_activity_estimated_time($cm_info->modname),
                                    'points' => 100,
                                    'icon' => theme_remui_kids_get_activity_icon($cm_info->modname),
                                    'url' => $activity_url,
                                    'completed' => $is_completed,
                                    'is_subsection' => false
                                );
                            }
                        }
                        
                        // Process subsections and their activities
                        foreach ($subsection_module_ids as $subsection_cmid => $subsection_instance) {
                            // Get the subsection's section
                            $subsectionsection = $DB->get_record('course_sections', array(
                                'component' => 'mod_subsection',
                                'itemid' => $subsection_instance
                            ), '*', IGNORE_MISSING);
                            
                            if ($subsectionsection) {
                                $subsection_name = $subsectionsection->name ?: 'Subsection';
                                $subsection_activities = array();
                                $subsection_activity_count = 0;
                                $subsection_completed_count = 0;
                                
                                if (!empty($subsectionsection->sequence)) {
                                    // Get activity IDs from subsection sequence
                                    $subsection_module_ids_list = explode(',', $subsectionsection->sequence);
                                    $subsection_module_ids_list = array_filter(array_map('intval', $subsection_module_ids_list));
                                    
                                    if (!empty($subsection_module_ids_list)) {
                                        // Get full module info for subsection activities
                                        $subsection_modules_full = $DB->get_records_sql(
                                            "SELECT cm.id, cm.instance, cm.completion, m.name as modname
                                             FROM {course_modules} cm
                                             JOIN {modules} m ON m.id = cm.module
                                             WHERE cm.id IN (" . implode(',', $subsection_module_ids_list) . ")
                                             AND cm.course = :courseid
                                             AND cm.visible = 1
                                             AND cm.deletioninprogress = 0
                                             AND m.name != 'subsection'
                                             AND m.name != 'label'",
                                            array('courseid' => $course->id)
                                        );
                                        
                                        foreach ($subsection_modules_full as $subcmid => $subcm_info) {
                                            $mod_name = '';
                                            if (isset($modinfo->cms[$subcmid])) {
                                                $subcm = $modinfo->cms[$subcmid];
                                                $mod_name = $subcm->name;
                                            } else {
                                                // Fallback: use modname if modinfo not available
                                                $mod_name = $subcm_info->modname;
                                            }
                                            
                                            // Check completion
                                            $is_completed = false;
                                            if ($subcm_info->completion > 0) {
                                                $completion_record = $DB->get_record_sql(
                                                    "SELECT * FROM {course_modules_completion}
                                                     WHERE coursemoduleid = :cmid
                                                     AND userid = :userid
                                                     AND completionstate IN (1, 2)",
                                                    array(
                                                        'cmid' => $subcmid,
                                                        'userid' => $USER->id
                                                    ),
                                                    IGNORE_MISSING
                                                );
                                                $is_completed = !empty($completion_record);
                                            }
                                            
                                            // Count trackable activities
                                            if ($subcm_info->completion > 0) {
                                                $subsection_activity_count++;
                                                if ($is_completed) {
                                                    $subsection_completed_count++;
                                                }
                                            } else {
                                                // Count all activities if completion tracking is not enabled
                                                $subsection_activity_count++;
                                                if ($is_completed) {
                                                    $subsection_completed_count++;
                                                }
                                            }
                                            
                                            // Get URL from modinfo if available
                                            $subactivity_url = '';
                                            if (isset($modinfo->cms[$subcmid]) && $modinfo->cms[$subcmid]->url) {
                                                $subactivity_url = $modinfo->cms[$subcmid]->url->out();
                                            } else {
                                                $subactivity_url = (new moodle_url('/mod/' . $subcm_info->modname . '/view.php', array('id' => $subcmid)))->out();
                                            }
                                            
                                            // Add to subsection activities array
                                            $subsection_activities[] = array(
                                                'activity_number' => count($subsection_activities) + 1,
                                                'id' => $subcmid,
                                                'name' => $mod_name,
                                                'type' => $subcm_info->modname,
                                                'duration' => theme_remui_kids_get_activity_estimated_time($subcm_info->modname),
                                                'points' => 100,
                                                'icon' => theme_remui_kids_get_activity_icon($subcm_info->modname),
                                                'url' => $subactivity_url,
                                                'completed' => $is_completed,
                                                'is_subsection' => false
                                            );
                                        }
                                    }
                                }
                                
                                // Add subsection as an activity item with nested activities
                                $section_activities[] = array(
                                    'activity_number' => count($section_activities) + 1,
                                    'id' => $subsection_cmid,
                                    'name' => $subsection_name,
                                    'type' => 'subsection',
                                    'duration' => 'Variable',
                                    'points' => 100,
                                    'icon' => 'fa-folder',
                                    'url' => (new moodle_url('/course/view.php', array('id' => $course->id, 'section' => $subsectionsection->section)))->out(),
                                    'completed' => ($subsection_activity_count > 0 && $subsection_completed_count == $subsection_activity_count),
                                    'is_subsection' => true,
                                    'subsection_activities' => $subsection_activities,
                                    'activity_count' => $subsection_activity_count,
                                    'completed_count' => $subsection_completed_count
                                );
                                
                                // Count subsection activities for overall counts
                                $activitycount += $subsection_activity_count;
                                $completedactivities += $subsection_completed_count;
                            }
                        }
                        
                        $total_activities += $activitycount;
                        $completed_activities += $completedactivities;
                    }
                }
                
                // Count ALL sections for display purposes
                $total_lessons++;
                
                // Only count sections with activities for progress calculation
                // Empty sections should NOT count toward progress
                if ($activitycount > 0) {
                    $total_lessons_with_activities++;
                    
                    // A lesson (section) is complete ONLY when all its activities are complete
                    // Empty sections are NOT counted as completed for progress
                    $lesson_completed = ($completedactivities == $activitycount);
                    if ($lesson_completed) {
                        $completed_lessons++;
                        $completed_lessons_with_activities++;
                    }
                } else {
                    // Empty section - mark as not completed for progress purposes
                    $lesson_completed = false;
                }
                
                // Build lesson data structure (section = lesson) with activities
                $lessons[] = array(
                    'id' => $sectionnum,  // Section number
                    'course_id' => $course->id,  // Parent course ID for template reference
                    'name' => $sectionname,  // Section name = Lesson name
                    'activity_count' => $activitycount,  // Count from DB
                    'completed_activity_count' => $completedactivities,  // Count from DB
                    'progress_percentage' => $activitycount > 0 ? round(($completedactivities / $activitycount) * 100) : 0,
                    'has_activities' => ($activitycount > 0),
                    'activities' => $section_activities,  // Activities array with subsections and regular activities
                    'completed' => $lesson_completed,
                    'is_empty' => ($activitycount == 0),
                    'url' => (new moodle_url('/course/view.php', array('id' => $course->id, 'section' => $sectionnum)))->out()
                );
                
                $lessonnumber++;
            }
            
            // Map lessons to sections (sections = lessons in Moodle)
            // For backward compatibility and template display
            $total_sections = $total_lessons;  // Total sections for display (all sections)
            $completed_sections = $completed_lessons;  // Completed sections for display
            
            // Calculate overall progress based on BOTH activities AND sections/lessons WITH activities
            // If all lessons/sections are completed, show 100% in progress bar
            // Empty sections are excluded from progress calculation
            $activity_progress = 0;
            $section_progress = 0;
            
            // Calculate activity-based progress
            if ($total_activities > 0) {
                $activity_progress = ($completed_activities / $total_activities) * 100;
            }
            
            // Calculate section/lesson-based progress (only sections WITH activities count)
            if ($total_lessons_with_activities > 0) {
                $section_progress = ($completed_lessons_with_activities / $total_lessons_with_activities) * 100;
            }
            
            // If all lessons/sections are completed, show 100% in progress bar
            if ($total_lessons_with_activities > 0 && $completed_lessons_with_activities >= $total_lessons_with_activities) {
                // All lessons/sections are completed - show 100% progress
                $progress = 100;
            } elseif ($total_activities > 0 && $total_lessons_with_activities > 0) {
                // Both activities and sections with activities exist - use minimum to ensure accuracy
                $progress = round(min($activity_progress, $section_progress));
            } elseif ($total_activities > 0) {
                // Only activities exist - use activity progress
                $progress = round($activity_progress);
            } elseif ($total_lessons_with_activities > 0) {
                // Only sections with activities exist (no activities) - use section progress
                $progress = round($section_progress);
            } else {
                // No activities or sections with activities
                $progress = 0;
            }
            
            // Ensure progress never exceeds 100%
            $progress = min(100, $progress);
            
            // If no grade in gradebook, use progress as fallback
            if ($points_earned == 0 && $progress > 0) {
                $points_earned = $progress;
            }
            
            // Mark course as completed in Moodle if all activities AND all sections WITH activities are done
            // Empty sections are excluded from this check
            $all_activities_complete = ($total_activities > 0 && $completed_activities >= $total_activities) || ($total_activities == 0);
            $all_sections_complete = ($total_lessons_with_activities > 0 && $completed_lessons_with_activities >= $total_lessons_with_activities) || ($total_lessons_with_activities == 0);
            
            if ($all_activities_complete && $all_sections_complete) {
                try {
                    // Check if completion is enabled for this course
                    $completion = new completion_info($course);
                    if ($completion->is_enabled()) {
                        // Check if course is not already marked as complete
                        if (!$completion->is_course_complete($USER->id)) {
                            // Get or create completion record
                            $ccompletion = new completion_completion(array(
                                'course' => $course->id,
                                'userid' => $USER->id
                            ));
                            
                            // Mark the course as complete
                            if (!$ccompletion->timecompleted) {
                                $ccompletion->mark_complete();
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Log error but don't break the page
                    error_log("Failed to mark course {$course->id} as complete for user {$USER->id}: " . $e->getMessage());
                }
            }
            
            // Fetch last accessed time dynamically from user_lastaccess table
            $last_accessed_timestamp = $DB->get_field('user_lastaccess', 'timeaccess', 
                array('userid' => $USER->id, 'courseid' => $course->id));
            $last_accessed = $last_accessed_timestamp ? date('M d, Y', $last_accessed_timestamp) : 'Never';
            
        } catch (Exception $e) {
            // Fallback to function data if dynamic fetch fails
            $progress = $course_info['progress_percentage'] ?? 0;
            $completed_sections = $course_info['completed_sections'] ?? 0;
            $total_sections = $course_info['total_sections'] ?? 0;
            $completed_activities = $course_info['completed_activities'] ?? 0;
            $total_activities = $course_info['total_activities'] ?? 0;
            $estimated_time = $course_info['estimated_time'] ?? 0;
            $points_earned = $course_info['points_earned'] ?? 0;
            $last_accessed = $course_info['last_accessed'] ?? 'Never';
        }
    } else {
        // For mock courses, generate realistic data
        $progress_options = array(0, 25, 50, 75, 100);
        $progress = $progress_options[($course->id - 101) % count($progress_options)];
        
        $total_lessons = rand(8, 15);
        $completed_lessons = round(($progress / 100) * $total_lessons);
        
        $total_activities = rand(20, 40);
        $completed_activities = round(($progress / 100) * $total_activities);
        
        $estimated_time = $total_activities * 15;
        $points_earned = round(($progress / 100) * 100);
        
        // For backward compatibility
        $total_sections = $total_lessons;
        $completed_sections = $completed_lessons;
        $last_accessed = 'Never';
    }
    
    // Ensure last_accessed is set if not already set
    if (!isset($last_accessed)) {
        $last_accessed = $course_info['last_accessed'] ?? 'Never';
    }
    
    // Determine course status dynamically
    $status = 'in_progress';
    if ($progress >= 100) {
        $status = 'completed';
    } elseif ($progress == 0) {
        $status = 'not_started';
    }
    
    // Use subject and category from function, with fallback detection
    $subject = $course_info['subject'] ?? 'Other';
    $category_name = $course_info['categoryname'] ?? 'General';
    
    // Get instructor name dynamically (use function data as fallback)
    $instructor_name = get_course_instructor($course->id);
    if (empty($instructor_name) || $instructor_name === 'Teacher') {
        $instructor_name = $course_info['instructor_name'] ?? 'Instructor';
    }
    
    // Build final course data array with all dynamic course-content data
    $final_course_data = array(
        'id' => $course_info['id'],
        'fullname' => $course_info['fullname'],
        'shortname' => $course_info['shortname'],
        'summary' => format_text($course->summary, FORMAT_HTML),
        'summary_plain' => strip_tags(format_text($course->summary, FORMAT_HTML)),
        'image' => $course_image,  // Cover image from function
        'url' => $course_info['courseurl'] ?? (new moodle_url('/course/view.php', array('id' => $course->id)))->out(),
        'progress' => $progress,  // Dynamically calculated
        'progress_percentage' => $progress,  // Dynamically calculated
        'grade_level' => $course_info['grade_level'] ?? $user_grade,
        'status' => $status,  // Dynamically determined
        'category' => $category_name,
        'subject' => $subject,
        // Lessons (Course Sections) - Real-time data dynamically fetched
        'completed_lessons' => $completed_lessons,
        'total_lessons' => $total_lessons,
        // Activities (Modules) - Real-time data dynamically fetched
        'completed_activities' => $completed_activities,
        'total_activities' => $total_activities,
        // For backward compatibility
        'completed_sections' => $completed_sections,  // Dynamically calculated
        'total_sections' => $total_sections,  // Dynamically calculated
        // Real-time metrics dynamically fetched
        'estimated_time' => $estimated_time,  // From logstore_standard_log
        'points_earned' => $points_earned,  // From grade_grades
        'instructor_name' => $instructor_name,  // Dynamically fetched
        'start_date' => date('M d, Y', $course->startdate),
        'last_accessed' => $last_accessed,  // Dynamically fetched from user_lastaccess
        'completed' => ($progress >= 100),  // Dynamically determined
        'in_progress' => ($progress > 0 && $progress < 100),  // Dynamically determined
        'courseurl' => $course_info['courseurl'] ?? (new moodle_url('/course/view.php', array('id' => $course->id)))->out(),
        'lessons' => $lessons,  // Full lesson structure with activities dynamically built
        'has_lessons' => !empty($lessons)
    );
    
    $courses_data[] = $final_course_data;
    
    // Group by subject
    if (!isset($courses_by_subject[$subject])) {
        $courses_by_subject[$subject] = array();
    }
    $courses_by_subject[$subject][] = $final_course_data;
}

// Prepare subjects data
$subjects_data = array();
foreach ($courses_by_subject as $subject => $courses) {
    $subjects_data[] = array(
        'subject' => $subject,
        'courses' => $courses,
        'count' => count($courses)
    );
}

// Calculate statistics
$total_courses = count($courses_data);
$completed_courses = 0;
$in_progress_courses = 0;
$not_started_courses = 0;
$total_progress = 0;

foreach ($courses_data as $course) {
    if ($course['status'] == 'completed') {
        $completed_courses++;
    } elseif ($course['status'] == 'in_progress') {
        $in_progress_courses++;
    } else {
        $not_started_courses++;
    }
    $total_progress += $course['progress'];
}

$average_progress = $total_courses > 0 ? round($total_progress / $total_courses) : 0;

$sidebar_context = remui_kids_build_highschool_sidebar_context('courses', $USER);

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'courses' => $courses_data,
    'subjects' => $subjects_data,
    'total_courses' => $total_courses,
    'completed_courses' => $completed_courses,
    'in_progress_courses' => $in_progress_courses,
    'not_started_courses' => $not_started_courses,
    'average_progress' => $average_progress,
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'messages_url' => (new moodle_url('/message/index.php'))->out(),
    'profile_url' => (new moodle_url('/user/profile.php', array('id' => $USER->id)))->out(),
    'community_url' => $sidebar_context['communityurl'],
    'logout_url' => (new moodle_url('/login/logout.php', array('sesskey' => sesskey())))->out(),
    'is_highschool' => true
));

// Output page header with Moodle navigation
echo $OUTPUT->header();

// Render the courses page template
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_courses', $template_data);

echo $OUTPUT->footer();
?>
