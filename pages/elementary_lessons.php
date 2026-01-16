<?php
/**
 * Custom Elementary Lessons page for remui_kids theme
 * Specifically designed for Grade 1-3 students
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_lessons.php');
$PAGE->set_pagelayout('elementary_lessons');
$PAGE->set_title('My Lessons');
$PAGE->set_heading('My Lessons');

// Get user's cohort information
$usercohorts = $DB->get_records_sql(
    "SELECT c.name, c.id 
     FROM {cohort} c 
     JOIN {cohort_members} cm ON c.id = cm.cohortid 
     WHERE cm.userid = ?",
    [$USER->id]
);

$usercohortname = '';
$usercohortid = 0;
$dashboardtype = 'elementary'; // Force elementary for this page

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Ensure this is for elementary students
    if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    }
}

// Get elementary student's course sections
$coursesections = [];
try {
    // Get user's enrolled courses
    $courses = enrol_get_all_users_courses($USER->id, true);
    
    // Debug: Log the number of enrolled courses
    error_log("Elementary Lessons: User {$USER->id} has " . count($courses) . " enrolled courses");
    
    foreach ($courses as $course) {
        // Debug: Log course info
        error_log("Elementary Lessons: Processing course: {$course->fullname} (ID: {$course->id})");
        
        // Get course sections with activities
        $sections = $DB->get_records_sql(
            "SELECT cs.id, cs.section, cs.name, cs.summary, cs.sequence,
                    c.id as courseid, c.fullname as coursename, c.shortname as courseshortname
             FROM {course_sections} cs
             JOIN {course} c ON cs.course = c.id
             WHERE cs.course = ? AND cs.section > 0 AND cs.visible = 1
             ORDER BY cs.section",
            [$course->id]
        );
        
        // Debug: Log the number of sections found
        error_log("Elementary Lessons: Found " . count($sections) . " sections in course {$course->fullname}");
        
        foreach ($sections as $section) {
            try {
                // Get section activities count
                $activities_count = 0;
                $completed_activities = 0;
                
                if (!empty($section->sequence)) {
                    $cmids = explode(',', $section->sequence);
                    $activities_count = count($cmids);
                    
                    // Count completed activities
                    foreach ($cmids as $cmid) {
                        if (empty($cmid)) continue;
                        
                        $completion = $DB->get_record('course_modules_completion', [
                            'coursemoduleid' => $cmid,
                            'userid' => $USER->id,
                            'completionstate' => 1
                        ]);
                        
                        if ($completion) {
                            $completed_activities++;
                        }
                    }
                }
                
                // Calculate progress percentage
                $progress_percentage = $activities_count > 0 ? round(($completed_activities / $activities_count) * 100) : 0;
                
                // Create section URL
                $sectionurl = '';
                try {
                    $sectionurl = (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $section->section]))->out();
                } catch (Exception $e) {
                    error_log("Elementary Lessons: Failed to create URL for section '{$section->name}': " . $e->getMessage());
                    continue;
                }
                
                // Clean section summary text
                $summary = $section->summary ? strip_tags($section->summary) : '';
                if (strlen($summary) > 150) {
                    $summary = substr($summary, 0, 150) . '...';
                }
                
                // Fetch section image from course files
                $section_image = '';
                try {
                    // Get course context for file access
                    $coursecontext = context_course::instance($course->id);
                    $fs = get_file_storage();
                    
                    // Priority 1: Look for section-specific images
                    $section_image_files = $fs->get_area_files(
                        $coursecontext->id,
                        'course',
                        'section',
                        $section->id,
                        'filename',
                        false
                    );
                    
                    // Priority 2: Look for course overview images
                    if (empty($section_image_files)) {
                        $section_image_files = $fs->get_area_files(
                            $coursecontext->id,
                            'course',
                            'overviewfiles',
                            0,
                            'filename',
                            false
                        );
                    }
                    
                    // Priority 3: Look for course summary images
                    if (empty($section_image_files)) {
                        $section_image_files = $fs->get_area_files(
                            $coursecontext->id,
                            'course',
                            'summary',
                            0,
                            'filename',
                            false
                        );
                    }
                    
                    // Priority 4: Look for any course files that might be images
                    if (empty($section_image_files)) {
                        $all_course_files = $fs->get_area_files(
                            $coursecontext->id,
                            'course',
                            'legacy',
                            0,
                            'filename',
                            false
                        );
                        
                        // Filter for image files only
                        foreach ($all_course_files as $file) {
                            if ($file->is_valid_image()) {
                                $section_image_files[] = $file;
                            }
                        }
                    }
                    
                    // Get the first valid image file
                    if (!empty($section_image_files)) {
                        foreach ($section_image_files as $image_file) {
                            if ($image_file && $image_file->is_valid_image()) {
                                $section_image = moodle_url::make_pluginfile_url(
                                    $image_file->get_contextid(),
                                    $image_file->get_component(),
                                    $image_file->get_filearea(),
                                    $image_file->get_itemid(),
                                    $image_file->get_filepath(),
                                    $image_file->get_filename()
                                )->out();
                                break; // Use the first valid image found
                            }
                        }
                    }
                    
                    // Debug: Log image fetching results
                    if (!empty($section_image)) {
                        error_log("Elementary Lessons: Found section image for '{$section->name}': {$section_image}");
                    } else {
                        error_log("Elementary Lessons: No section image found for '{$section->name}', will use default");
                    }
                    
                } catch (Exception $e) {
                    error_log("Elementary Lessons: Error fetching section image for section '{$section->name}': " . $e->getMessage());
                }
                
                // Fallback to default section images if no image found
                if (empty($section_image)) {
                    $default_section_images = [
                        'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1513475382585-d06e58bcb0e0?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1523240798034-6c5c0b0b0b0b?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1559757148-5c350d0d3c56?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400&h=300&fit=crop&auto=format',
                        'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=400&h=300&fit=crop&auto=format'
                    ];
                    $section_image = $default_section_images[($section->section - 1) % count($default_section_images)];
                }
                
                $coursesections[] = [
                    'id' => $section->id,
                    'section_number' => $section->section,
                    'name' => $section->name ?: "Section {$section->section}",
                    'summary' => $summary,
                    'courseid' => $section->courseid,
                    'coursename' => $section->coursename,
                    'courseshortname' => $section->courseshortname,
                    'sectionurl' => $sectionurl,
                    'activities_count' => $activities_count,
                    'completed_activities' => $completed_activities,
                    'progress_percentage' => $progress_percentage,
                    'completed' => $progress_percentage >= 100,
                    'in_progress' => $progress_percentage > 0 && $progress_percentage < 100,
                    'not_started' => $progress_percentage == 0,
                    'section_image' => $section_image
                ];
                
                // Debug: Log successful section processing
                error_log("Elementary Lessons: Successfully processed section '{$section->name}' from course '{$section->coursename}'");
                
            } catch (Exception $e) {
                error_log("Elementary Lessons: Error processing section '{$section->name}': " . $e->getMessage());
                continue; // Skip problematic sections
            }
        }
    }
    
    // Debug: Log total sections found
    error_log("Elementary Lessons: Total sections found for user {$USER->id}: " . count($coursesections));
    
} catch (Exception $e) {
    error_log("Elementary Lessons: Error fetching sections for user {$USER->id}: " . $e->getMessage());
    $coursesections = []; // Fallback to empty array
}

// Calculate section statistics
$completed_sections_count = 0;
$in_progress_sections_count = 0;
$total_activities_count = 0;

foreach ($coursesections as $section) {
    if ($section['completed']) {
        $completed_sections_count++;
    } elseif ($section['in_progress']) {
        $in_progress_sections_count++;
    }
    $total_activities_count += $section['activities_count'];
}

// Prepare template context for elementary dashboard integration
$templatecontext = [
    'custom_elementary_lessons' => true,
    'dashboard_type' => 'elementary',
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'course_sections' => $coursesections,
    'has_course_sections' => !empty($coursesections),
    'total_sections_count' => count($coursesections),
    'completed_sections_count' => $completed_sections_count,
    'in_progress_sections_count' => $in_progress_sections_count,
    'total_activities_count' => $total_activities_count,
    
    // URLs for sidebar navigation - pointing to elementary pages
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/calendar/view.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
];

// Set elementary flag
$templatecontext['elementary'] = true;

// Add body class for styling
$templatecontext['bodyattributes'] = 'class="elementary-lessons-page has-student-sidebar"';

// Flag to hide the default navbar
$templatecontext['hide_default_navbar'] = true;

// Render the elementary lessons page
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_lessons_page', $templatecontext);
