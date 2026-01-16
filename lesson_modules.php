<?php
/**
 * Lesson Modules Page for Elementary Students
 * Shows modules within a specific lesson
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

// Require login
require_login();

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$lessonid = required_param('lessonid', PARAM_INT);

// Get course and lesson details
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$lesson = $DB->get_record('course_sections', ['id' => $lessonid], '*', MUST_EXIST);

// For elementary students, allow viewing modules without strict enrollment checks
// This allows students to view lesson modules even if not enrolled in the specific course
$context = context_course::instance($course->id);

// Check if user is logged in (basic check)
if (!isloggedin()) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You must be logged in to view this content.</p>";
    echo "<p><a href='" . $CFG->wwwroot . "/login/'>Login</a></p>";
    exit;
}

// Set up page
$PAGE->set_url('/theme/remui_kids/lesson_modules.php', ['courseid' => $courseid, 'lessonid' => $lessonid]);
$PAGE->set_title($lesson->name . ' - Modules');
$PAGE->set_heading($lesson->name . ' - Modules');
$PAGE->set_pagelayout('base');

// Get user cohort for dashboard type
$usercohorts = $DB->get_records_sql(
    "SELECT c.* FROM {cohort} c 
     JOIN {cohort_members} cm ON c.id = cm.cohortid 
     WHERE cm.userid = ?", 
    [$USER->id]
);

$dashboardtype = 'elementary';
$usercohortname = '';
$usercohortid = 0;

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    if (stripos($usercohortname, 'elementary') !== false || 
        stripos($usercohortname, 'grade 1') !== false || 
        stripos($usercohortname, 'grade 2') !== false || 
        stripos($usercohortname, 'grade 3') !== false) {
        $dashboardtype = 'elementary';
    }
}

// Get course category for grade level
$category = $DB->get_record('course_categories', ['id' => $course->category]);
$categoryname = $category ? $category->name : '';

$grade_level = 'Grade 1';
if (stripos($categoryname, 'grade 2') !== false || stripos($course->fullname, 'grade 2') !== false) {
    $grade_level = 'Grade 2';
} elseif (stripos($categoryname, 'grade 3') !== false || stripos($course->fullname, 'grade 3') !== false) {
    $grade_level = 'Grade 3';
}

// Get course image
$courseimage = '';
$fs = get_file_storage();
$coursecontext = context_course::instance($course->id);

// Method 1: Try course overview files
$files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'filename', false);
if ($files) {
    $file = reset($files);
    $courseimage = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename())->out();
}

// Method 2: Try course summary files if overview files not found
if (empty($courseimage)) {
    $files = $fs->get_area_files($coursecontext->id, 'course', 'summary', 0, 'filename', false);
    if ($files) {
        $file = reset($files);
        $courseimage = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename())->out();
    }
}

// Method 3: Try course image field
if (empty($courseimage) && !empty($course->courseimage)) {
    $courseimage = $course->courseimage;
}

// Method 4: Use default images based on course name/category
if (empty($courseimage)) {
    $course_name_lower = strtolower($course->fullname);
    $category_lower = strtolower($categoryname);
    
    if (strpos($course_name_lower, 'math') !== false || strpos($category_lower, 'math') !== false) {
        $courseimage = 'https://images.unsplash.com/photo-1635070041078-e363dbe005cb?w=400&h=300&fit=crop';
    } elseif (strpos($course_name_lower, 'science') !== false || strpos($category_lower, 'science') !== false) {
        $courseimage = 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?w=400&h=300&fit=crop';
    } elseif (strpos($course_name_lower, 'english') !== false || strpos($category_lower, 'english') !== false) {
        $courseimage = 'https://images.unsplash.com/photo-1481627834876-b7833e8f5570?w=400&h=300&fit=crop';
    } elseif (strpos($course_name_lower, 'art') !== false || strpos($category_lower, 'art') !== false) {
        $courseimage = 'https://images.unsplash.com/photo-1541961017774-22349e4a1262?w=400&h=300&fit=crop';
    } elseif (strpos($course_name_lower, 'music') !== false || strpos($category_lower, 'music') !== false) {
        $courseimage = 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=400&h=300&fit=crop';
    } else {
        $courseimage = 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&h=300&fit=crop';
    }
}

// Get all items (modules/subsection and activities) from the specific lesson
$items = [];
$total_items = 0;
$completed_items = 0;

try {
    // Use Moodle's standard method to get course modules info
    $modinfo = get_fast_modinfo($course);
    
    // Get completion info for progress tracking
    $completion = new completion_info($course);
    
    // Get section info to access its sequence
    $section_info = $modinfo->get_section_info($lesson->section);
    
    // Get all items in this section
    if (isset($modinfo->sections[$lesson->section])) {
        foreach ($modinfo->sections[$lesson->section] as $cmid) {
            $cm = $modinfo->cms[$cmid];
            
            // Skip if not visible to user
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }
            
            // Skip labels (they're just text)
            if ($cm->modname === 'label') {
                continue;
            }
            
            // Check if this is a subsection module or a regular activity
            $is_subsection_module = ($cm->modname === 'subsection');
            
            if ($is_subsection_module) {
                // This is a subsection module - get activities inside it
                $subsectionsection = $DB->get_record('course_sections', [
                    'component' => 'mod_subsection',
                    'itemid' => $cm->instance
                ], '*', IGNORE_MISSING);
                
                $module_activities = [];
                $total_module_activities = 0;
                $completed_module_activities = 0;
                
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
                        
                        $total_module_activities++;
                        
                        // Check completion
                        $is_activity_completed = false;
                        if ($completion->is_enabled($activity_cm)) {
                            try {
                                $activity_completiondata = $completion->get_data($activity_cm, false, $USER->id);
                                if ($activity_completiondata->completionstate == COMPLETION_COMPLETE || 
                                    $activity_completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                    $is_activity_completed = true;
                                    $completed_module_activities++;
                                }
                            } catch (Exception $e) {
                                // Continue if completion check fails
                            }
                        }
                        
                        $module_activities[] = [
                            'id' => $activity_cm->id,
                            'name' => $activity_cm->name,
                            'type' => $activity_cm->modname,
                            'completed' => $is_activity_completed
                        ];
                    }
                }
                
                // Calculate module progress
                $module_progress = $total_module_activities > 0 ? 
                    round(($completed_module_activities / $total_module_activities) * 100) : 0;
                
                // Get subsection module description
                $module_summary = '';
                $module_name = $cm->name ?: 'Module';
                
                if (!empty($cm->content)) {
                    $module_summary = strip_tags($cm->content);
                } else if (!empty($subsectionsection->summary)) {
                    $module_summary = strip_tags($subsectionsection->summary);
                } else {
                    $module_summary = "This module contains {$total_module_activities} activities.";
                }
                
                // Remove module name from summary if it appears at the start (to avoid duplication)
                if (!empty($module_summary) && !empty($module_name)) {
                    $module_name_trimmed = trim($module_name);
                    $summary_trimmed = trim($module_summary);
                    
                    // Check if summary starts with the module name
                    if (stripos($summary_trimmed, $module_name_trimmed) === 0) {
                        // Remove the module name from the beginning
                        $module_summary = trim(substr($summary_trimmed, strlen($module_name_trimmed)));
                        // Remove any leading punctuation (colon, dash, etc.)
                        $module_summary = preg_replace('/^[\s:,\-–—]+/', '', $module_summary);
                    }
                }
                
                // Generate URL for subsection module
                $module_url = '';
                
                // Get subsection's section number for the URL
                // The subsection has its own section number that should be used for navigation
                $subsection_section_number = null;
                if ($subsectionsection && isset($subsectionsection->section)) {
                    $subsection_section_number = $subsectionsection->section;
                }
                
                if ($cm->url) {
                    try {
                        $module_url = $cm->url->out(false);
                    } catch (Exception $e) {
                        // If URL generation fails, create a course section URL for the subsection
                        if ($subsection_section_number !== null) {
                            $module_url = (new moodle_url('/course/view.php', [
                                'id' => $course->id,
                                'section' => $subsection_section_number
                            ]))->out(false);
                        } else {
                            // Fallback to lesson section if subsection section is not available
                            $module_url = (new moodle_url('/course/view.php', [
                                'id' => $course->id,
                                'section' => $lesson->section
                            ]))->out(false);
                        }
                    }
                } else {
                    // If no URL, create a course section URL that shows this subsection
                    // Use the subsection's section number to navigate directly to the subsection
                    if ($subsection_section_number !== null) {
                        $module_url = (new moodle_url('/course/view.php', [
                            'id' => $course->id,
                            'section' => $subsection_section_number
                        ]))->out(false);
                    } else {
                        // Fallback to lesson section if subsection section is not available
                        $module_url = (new moodle_url('/course/view.php', [
                            'id' => $course->id,
                            'section' => $lesson->section
                        ]))->out(false);
                    }
                }
                
                // Create module item data
                $items[] = [
                    'id' => $cm->id,
                    'name' => $module_name,
                    'summary' => $module_summary,
                    'section' => $lesson->section,
                    'item_type' => 'module', // This is a subsection module
                    'is_module' => true, // Boolean flag for template
                    'activity_type' => 'module', // Display as "Module"
                    'total_activities' => $total_module_activities,
                    'completed_activities' => $completed_module_activities,
                    'progress_percentage' => $module_progress,
                    'is_completed' => ($module_progress == 100),
                    'has_activities' => !empty($module_activities),
                    'activities' => $module_activities,
                    'url' => $module_url // URL is always set (either cm->url or course section URL)
                ];
                
            } else {
                // This is a regular activity (not a subsection module)
                $is_completed = false;
                if ($completion->is_enabled($cm)) {
                    try {
                        $completiondata = $completion->get_data($cm, false, $USER->id);
                        if ($completiondata->completionstate == COMPLETION_COMPLETE || 
                            $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                            $is_completed = true;
                        }
                    } catch (Exception $e) {
                        // Continue if completion check fails
                    }
                }
                
                // Get activity description/summary
                $activity_summary = '';
                $activity_name = $cm->name ?: ucfirst($cm->modname) . " Activity";
                
                if (!empty($cm->content)) {
                    $activity_summary = strip_tags($cm->content);
                } else {
                    $activity_summary = "Complete this " . ucfirst($cm->modname) . " activity to continue your learning journey!";
                }
                
                // Remove activity name from summary if it appears at the start (to avoid duplication)
                if (!empty($activity_summary) && !empty($activity_name)) {
                    $activity_name_trimmed = trim($activity_name);
                    $summary_trimmed = trim($activity_summary);
                    
                    // Check if summary starts with the activity name
                    if (stripos($summary_trimmed, $activity_name_trimmed) === 0) {
                        // Remove the activity name from the beginning
                        $activity_summary = trim(substr($summary_trimmed, strlen($activity_name_trimmed)));
                        // Remove any leading punctuation (colon, dash, etc.)
                        $activity_summary = preg_replace('/^[\s:,\-–—]+/', '', $activity_summary);
                    }
                }
                
                // Create activity item data
                $items[] = [
                    'id' => $cm->id,
                    'name' => $activity_name,
                    'summary' => $activity_summary,
                    'section' => $cm->sectionnum,
                    'item_type' => 'activity', // This is a regular activity
                    'is_module' => false, // Boolean flag for template
                    'activity_type' => $cm->modname,
                    'total_activities' => 1,
                    'completed_activities' => $is_completed ? 1 : 0,
                    'progress_percentage' => $is_completed ? 100 : 0,
                    'is_completed' => $is_completed,
                    'activities' => [], // Regular activities don't have nested activities
                    'url' => $cm->url ? $cm->url->out(false) : ''
                ];
            }
            
            $total_items++;
        }
    }
    
    // Calculate completed items count
    foreach ($items as $item) {
        if ($item['is_completed']) {
            $completed_items++;
        }
    }
    
} catch (Exception $e) {
    // Log error and continue with empty items
    error_log("LESSON_MODULES: Error fetching lesson items: " . $e->getMessage());
    error_log("LESSON_MODULES: Stack trace: " . $e->getTraceAsString());
}

// Calculate overall progress based on all items
$overall_progress = $total_items > 0 ? round(($completed_items / $total_items) * 100) : 0;

// Get student picture URL for elementary students
$student_picture_url_only = '';
$is_elementary_grade = ($dashboardtype === 'elementary');

if ($is_elementary_grade && $USER && $USER->picture > 0) {
    // Verify the file actually exists in file storage
    $user_context = context_user::instance($USER->id);
    $fs = get_file_storage();
    
    // Check if file exists in 'icon' area (where Moodle stores profile pics)
    $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
    
    if (!empty($files)) {
        try {
            // Generate user picture URL using Moodle's standard method
            $user_picture = new user_picture($USER);
            $user_picture->size = 1; // Full size
            $profile_url = $user_picture->get_url($PAGE)->out(false);
            
            // If URL is generated and not empty, use it
            if (!empty($profile_url)) {
                $student_picture_url_only = $profile_url;
            }
        } catch (Exception $e) {
            error_log("Error generating student picture URL for lesson modules: " . $e->getMessage());
            $student_picture_url_only = '';
        }
    }
}

// Prepare template context
$templatecontext = [
    'custom_lesson_modules' => true,
    'dashboard_type' => $dashboardtype,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_fullname' => fullname($USER),
    'is_elementary_grade' => $is_elementary_grade,
    'student_picture_url_only' => $student_picture_url_only,
    
    // Lesson data
    'lesson_id' => $lesson->id,
    'lesson_name' => $lesson->name ?: "Lesson {$lesson->section}",
    'lesson_summary' => $lesson->summary ? strip_tags($lesson->summary) : 'Explore this lesson to learn new concepts.',
    'lesson_section' => $lesson->section,
    'overall_progress' => $overall_progress,
    'total_modules' => $total_items,
    'completed_modules' => $completed_items,
    'has_modules' => !empty($items),
    'modules' => $items,
    
    // Course data
    'course_id' => $course->id,
    'course_name' => $course->fullname,
    'course_shortname' => $course->shortname,
    'course_image' => $courseimage,
    'category_name' => $categoryname,
    'grade_level' => $grade_level,
    
    // URLs for navigation
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'allcoursesurl' => (new moodle_url('/course/index.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    
    // Page identification
    'is_lessons_page' => true,
    'is_mycourses_page' => false,
    'is_activities_page' => false,
    'is_treeview_active' => false,
    
    // Cache busting
    'cache_bust' => time() . '-specific-lesson-modules-only'
];

// Render the template
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/lesson_modules_page', $templatecontext);
echo $OUTPUT->footer();
