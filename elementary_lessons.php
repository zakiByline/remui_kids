<?php
/**
 * Elementary Lessons Page
 * 
 * This file handles the elementary lessons page for Grades 1-3 students
 * with Moodle navigation bar integration and enhanced lesson data.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
require_login();

global $USER, $PAGE, $OUTPUT, $DB;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_lessons.php');
$PAGE->set_title('My Lessons - Elementary Dashboard');
$PAGE->set_heading('My Amazing Lessons');
$PAGE->set_pagelayout('mydashboard');

// Check if user is elementary student (Grades 1-3) - simplified check
try {
    $user_cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name, c.idnumber 
         FROM {cohort} c
         JOIN {cohort_members} cm ON c.id = cm.cohortid
         WHERE cm.userid = ? AND (c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ?)",
        [$USER->id, 'grade1%', 'grade2%', 'grade3%', 'elementary%']
    );
} catch (Exception $e) {
    // If cohort check fails, just set empty array and continue
    $user_cohorts = [];
}

// For now, allow all logged-in users to access this page
// You can uncomment the redirect below if you want to restrict access
/*
if (empty($user_cohorts)) {
    // Redirect to regular dashboard if not elementary student
    redirect(new moodle_url('/my/'));
}
*/

// Get user's course sections (lessons) with error handling
$lessons_data = [];

try {
    // Get enrolled courses using Moodle's standard API
    $courses = enrol_get_users_courses($USER->id, true, ['id', 'fullname', 'shortname', 'summary', 'startdate', 'enddate', 'category']);
    error_log("ELEMENTARY_LESSONS: Found " . count($courses) . " enrolled courses for user {$USER->id}");
} catch (Exception $e) {
    error_log("ELEMENTARY_LESSONS: Error getting enrolled courses: " . $e->getMessage());
    $courses = [];
}

foreach ($courses as $course) {
    try {
        error_log("ELEMENTARY_LESSONS: Processing course ID {$course->id} - {$course->fullname}");
        
        // Get course modinfo - this respects visibility, access control, etc.
        $modinfo = get_fast_modinfo($course);
        $coursecontext = context_course::instance($course->id);
        
        // Get all sections using Moodle's proper API
        $sections = $modinfo->get_section_info_all();
        error_log("ELEMENTARY_LESSONS: Course {$course->id} has " . count($sections) . " sections");
        
        // PERFORMANCE: Get course-level data ONCE per course (not per section)
        // Get parent category name instead of direct category
        $categoryname = 'General';
        if ($course->category) {
            try {
                $category = $DB->get_record('course_categories', ['id' => $course->category], '*', IGNORE_MISSING);
                if ($category) {
                    if ($category->parent > 0) {
                        // Get parent category
                        $parent_category = $DB->get_record('course_categories', ['id' => $category->parent], '*', IGNORE_MISSING);
                        if ($parent_category) {
                            $categoryname = $parent_category->name;
                        } else {
                            $categoryname = $category->name;
                        }
                    } else {
                        // No parent, use current category name
                        $categoryname = $category->name;
                    }
                }
            } catch (Exception $e) {
                // Fallback if category lookup fails
                $categoryname = 'General';
            }
        }
        
        // Get course image - try multiple methods
        $courseimage = '';
        $fs = get_file_storage();
        
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
            
            // Default images from pix folder - cycle through available images based on course ID
            $default_images = [
                'download (2).jpg'
            ];
            
            // Use course ID to consistently select the same image for the same course
            $image_index = $course->id % count($default_images);
            $image_filename = $default_images[$image_index];
            
            // Default education image from pix folder
            $courseimage = (new moodle_url('/theme/remui_kids/pix/' . $image_filename))->out(false);
        }
        
        // Initialize completion info for progress tracking
        $completion = new completion_info($course);
        
        // Process each section using proper Moodle APIs
        foreach ($sections as $section) {
            // Skip general section (section 0)
            if ($section->section == 0) {
                continue;
            }
            
            // Skip sections that are not visible to user
            if (!$section->uservisible || !$section->visible) {
                continue;
            }
            
            // Skip sections that are subsections/modules - they should only be accessible within their parent sections
            if (isset($section->component) && $section->component === 'mod_subsection') {
                continue;
            }
            
            // Get section name
            $section_name = get_section_name($course, $section);
            $section_name_lower = strtolower($section_name);
            
            // Get activities in this section using modinfo
            $total_activities = 0;
            $completed_activities = 0;
            $section_modules = [];
            
            // Count activities in this section
            if (isset($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->cms[$cmid];
                    
                    // Only count visible activities
                    if (!$cm->uservisible || $cm->deletioninprogress) {
                        continue;
                    }
                    
                    // Skip subsection modules themselves - we want activities inside them
                    if ($cm->modname === 'subsection') {
                        continue;
                    }
                    
                    // Skip label modules when counting (they're just text)
                    if ($cm->modname === 'label') {
                        continue;
                    }
                    
                    $total_activities++;
                    $section_modules[] = $cm;
                    
                    // Check completion if enabled
                    if ($completion->is_enabled($cm)) {
                        try {
                            $completiondata = $completion->get_data($cm, false, $USER->id);
                            if ($completiondata->completionstate == COMPLETION_COMPLETE || 
                                $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                $completed_activities++;
                            }
                        } catch (Exception $e) {
                            // Continue if completion check fails
                        }
                    }
                }
            }
            
            // Log section info for debugging
            error_log("ELEMENTARY_LESSONS: Course {$course->id}, Section {$section->section} ({$section_name}) - Total activities: {$total_activities}");
            
            // Show all sections that are visible to the user, even if they have no activities yet
            // This ensures all enrolled courses are represented
            
            // Calculate section progress
            $progress_percentage = $total_activities > 0 ? round(($completed_activities / $total_activities) * 100) : 0;
            
            // Determine grade level from category or course name
            $grade_level = 'Grade 1';
            if (stripos($categoryname, 'grade 2') !== false || stripos($course->fullname, 'grade 2') !== false) {
                $grade_level = 'Grade 2';
            } elseif (stripos($categoryname, 'grade 3') !== false || stripos($course->fullname, 'grade 3') !== false) {
                $grade_level = 'Grade 3';
            }
            
            // Get section-specific image (lesson image)
            $sectionimage = '';
            try {
                // Try to get section/lesson image from course section files
                $sectionfiles = $fs->get_area_files($coursecontext->id, 'course', 'section', $section->id, 'timemodified DESC', false);
                
                if (!empty($sectionfiles)) {
                    foreach ($sectionfiles as $file) {
                        // Skip directories
                        if ($file->is_directory()) {
                            continue;
                        }
                        
                        // Check if it's an image file
                        $mimetype = $file->get_mimetype();
                        if (strpos($mimetype, 'image/') === 0) {
                            $sectionimage = moodle_url::make_pluginfile_url(
                                $file->get_contextid(),
                                $file->get_component(),
                                $file->get_filearea(),
                                $file->get_itemid(),
                                $file->get_filepath(),
                                $file->get_filename()
                            )->out();
                            break; // Use first image found
                        }
                    }
                }
                
                // Fallback to course image if no section image
                if (empty($sectionimage)) {
                    $sectionimage = $courseimage;
                }
                
            } catch (Exception $e) {
                $sectionimage = $courseimage; // Fallback to course image
            }
            
            // Generate URL for lesson modules page
            $courseurl_obj = new moodle_url('/theme/remui_kids/lesson_modules.php', [
                'courseid' => $course->id, 
                'lessonid' => $section->id
            ]);
            $courseurl = $courseurl_obj->out(false);
            
            // Add section as lesson with progress data
            $lessons_data[] = [
                'id' => $section->id,
                'name' => $section_name ?: "Section {$section->section}",
                'summary' => $section->summary ? strip_tags($section->summary) : 'Explore this lesson to learn new concepts.',
                'courseid' => $course->id,
                'coursename' => $course->fullname,
                'courseshortname' => $course->shortname,
                'courseimage' => $sectionimage,
                'categoryname' => $categoryname,
                'grade_level' => $grade_level,
                'total_activities' => $total_activities,
                'completed_activities' => $completed_activities,
                'progress_percentage' => $progress_percentage,
                'courseurl' => $courseurl
            ];
        }
    } catch (Exception $e) {
        // If course processing fails, skip this course
        error_log("ELEMENTARY_LESSONS: ERROR processing course {$course->id} ({$course->fullname}): " . $e->getMessage());
        error_log("ELEMENTARY_LESSONS: Stack trace: " . $e->getTraceAsString());
        continue;
    }
}

error_log("ELEMENTARY_LESSONS: Total lessons found: " . count($lessons_data) . " from " . count($courses) . " courses");

// Calculate statistics (direct DB sections only - NO modules)
$total_lessons = count($lessons_data);

// Extract unique courses for the filter dropdown
$unique_courses = [];
$seen_courses = [];
foreach ($lessons_data as $lesson) {
    if (!in_array($lesson['courseid'], $seen_courses)) {
        $unique_courses[] = [
            'courseid' => $lesson['courseid'],
            'coursename' => $lesson['coursename']
        ];
        $seen_courses[] = $lesson['courseid'];
    }
}

// Sort courses alphabetically by name
usort($unique_courses, function($a, $b) {
    return strcmp($a['coursename'], $b['coursename']);
});

// Prepare template context (pure sections from DB - NO module data)
$templatecontext = [
    'custom_elementary_lessons' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'total_lessons' => $total_lessons,
    'has_lessons' => !empty($lessons_data),
    'lessons' => $lessons_data,
    'courses' => $unique_courses,
    'has_courses' => !empty($unique_courses),
    
    // Page identification flags for sidebar
    'is_lessons_page' => true,
    'is_mycourses_page' => false,
    'is_activities_page' => false,
    
    // Navigation URLs
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'currentactivityurl' => (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'allcoursesurl' => (new moodle_url('/course/index.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
];

// Render the template
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_lessons_page_clean', $templatecontext);
echo $OUTPUT->footer();
