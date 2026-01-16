<?php
/**
 * High School Lessons Page
 * 
 * This file handles the high school lessons page for Grades 9-12 students
 * with Moodle navigation bar integration and enhanced lesson data.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_login();

global $USER, $PAGE, $OUTPUT, $DB;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/highschool_lessons.php');
$PAGE->set_title('My Lessons - High School Dashboard');
$PAGE->set_heading('My Lessons');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

// Get user's course sections (lessons) with error handling
$lessons_data = [];

try {
    // Get enrolled courses using Moodle's standard API
    $courses = enrol_get_users_courses($USER->id, true, ['id', 'fullname', 'shortname', 'summary', 'startdate', 'enddate', 'category']);
} catch (Exception $e) {
    $courses = [];
}

foreach ($courses as $course) {
    try {
        // Get course modinfo - this respects visibility, access control, etc.
        $modinfo = get_fast_modinfo($course);
        $coursecontext = context_course::instance($course->id);
        
        // Get all sections using Moodle's proper API
        $sections = $modinfo->get_section_info_all();
        
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
                    
                    // Skip label modules when counting (they're just text)
                    if ($cm->modname === 'label') {
                        continue;
                    }
                    
                    // Handle subsection modules - count activities INSIDE them
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
                                
                                // Count this activity from within the subsection
                                $total_activities++;
                                $section_modules[] = $activity_cm;
                                
                                // Check completion if enabled
                                if ($completion->is_enabled($activity_cm)) {
                                    try {
                                        $completiondata = $completion->get_data($activity_cm, false, $USER->id);
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
                        // Continue to next module (we've processed all activities inside this subsection)
                        continue;
                    }
                    
                    // Regular activity (not inside a subsection) - count it normally
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
            // Show all sections that are visible to the user, even if they have no activities yet
            // This ensures all enrolled courses are represented
            
            // Calculate section progress
            $progress_percentage = $total_activities > 0 ? round(($completed_activities / $total_activities) * 100) : 0;
            
            // Determine grade level from category or course name
            $grade_level = 'Grade 9';
            if (stripos($categoryname, 'grade 10') !== false || stripos($course->fullname, 'grade 10') !== false) {
                $grade_level = 'Grade 10';
            } elseif (stripos($categoryname, 'grade 11') !== false || stripos($course->fullname, 'grade 11') !== false) {
                $grade_level = 'Grade 11';
            } elseif (stripos($categoryname, 'grade 12') !== false || stripos($course->fullname, 'grade 12') !== false) {
                $grade_level = 'Grade 12';
            }
            
            // Use course cover image directly (no section-specific images)
            $sectionimage = $courseimage;
            
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
        continue;
    }
}

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

// Build sidebar context
$sidebar_context = remui_kids_build_highschool_sidebar_context('lessons', $USER);

// Prepare template context
$templatecontext = array_merge($sidebar_context, [
    'custom_highschool_lessons' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'total_lessons' => $total_lessons,
    'has_lessons' => !empty($lessons_data),
    'lessons' => $lessons_data,
    'courses' => $unique_courses,
    'has_courses' => !empty($unique_courses),
    
    // Page identification flags for sidebar
    'is_lessons_page' => true,
    
    // Navigation URLs
    'lessonsurl' => (new moodle_url('/theme/remui_kids/highschool_lessons.php'))->out(),
]);

// Render the template
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_lessons_page', $templatecontext);
echo $OUTPUT->footer();