<?php
/**
 * Lesson View Page - Shows individual lesson content
 * Displays a single lesson with its activities in a clean, focused layout
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');

// Require login
require_login();

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$sectionid = required_param('sectionid', PARAM_INT);

// Get course and section
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);

// Check if user is enrolled in the course
$context = context_course::instance($course->id);
require_capability('moodle/course:view', $context);

// Set page context
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/lesson_view.php', ['courseid' => $courseid, 'sectionid' => $sectionid]));
$PAGE->set_pagelayout('base');
$PAGE->set_title($course->fullname . ' - ' . ($section->name ?: "Lesson {$section->section}"));
$PAGE->set_heading($section->name ?: "Lesson {$section->section}");

// Determine user's cohort and dashboard type
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'default';

$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;
    
    // Determine dashboard type based on cohort name
    if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    } elseif (preg_match('/grade\s*[4-6]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[7-9]/i', $usercohortname)) {
        $dashboardtype = 'high';
    }
}

// Get course image
$courseimage = '';
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'filename', false);
if ($files) {
    $file = reset($files);
    $courseimage = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
}

// Get category name
$category = $DB->get_record('course_categories', ['id' => $course->category]);
$categoryname = $category ? $category->name : 'General';

// Determine grade level
$grade_level = 'Grade 1';
if (stripos($categoryname, 'grade 2') !== false || stripos($course->fullname, 'grade 2') !== false) {
    $grade_level = 'Grade 2';
} elseif (stripos($categoryname, 'grade 3') !== false || stripos($course->fullname, 'grade 3') !== false) {
    $grade_level = 'Grade 3';
}

// Get lesson subsections (modules) - these are child sections of the main lesson
$subsections = [];
$total_subsections = 0;
$completed_subsections = 0;

try {
    // Get all sections that are children of this lesson section
    $child_sections = $DB->get_records('course_sections', 
        ['course' => $course->id, 'parent' => $section->id, 'visible' => 1], 
        'section ASC'
    );
    
    foreach ($child_sections as $subsection) {
        // Count activities in this subsection for progress
        $subsection_activities = $DB->get_records_sql(
            "SELECT cm.id, cm.completionstate
             FROM {course_modules} cm
             JOIN {modules} m ON cm.module = m.id
             WHERE cm.course = ? AND cm.section = ? AND m.name IN ('assign', 'quiz', 'lesson', 'forum', 'resource')",
            [$course->id, $subsection->id]
        );
        
        $subsection_total = count($subsection_activities);
        $subsection_completed = 0;
        
        foreach ($subsection_activities as $activity) {
            if ($activity->completionstate == 1) {
                $subsection_completed++;
            }
        }
        
        $subsection_progress = $subsection_total > 0 ? round(($subsection_completed / $subsection_total) * 100) : 0;
        
        $subsections[] = [
            'id' => $subsection->id,
            'name' => !empty($subsection->name) ? $subsection->name : "Module {$subsection->section}",
            'summary' => $subsection->summary ? strip_tags($subsection->summary) : 'Complete this module to continue your learning journey!',
            'section' => $subsection->section,
            'total_activities' => $subsection_total,
            'completed_activities' => $subsection_completed,
            'progress_percentage' => $subsection_progress,
            'url' => new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $subsection->section])
        ];
        
        $total_subsections++;
        if ($subsection_progress == 100) {
            $completed_subsections++;
        }
    }
} catch (Exception $e) {
    // Continue with empty subsections if there's an error
}

// Calculate lesson progress based on subsections
$progress_percentage = $total_subsections > 0 ? round(($completed_subsections / $total_subsections) * 100) : 0;

// Prepare template context
$templatecontext = [
    'custom_lesson_view' => true,
    'dashboard_type' => $dashboardtype,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_fullname' => fullname($USER),
    
    // Lesson data
    'lesson_id' => $section->id,
    'lesson_name' => $section->name ?: "Lesson {$section->section}",
    'lesson_summary' => $section->summary ? strip_tags($section->summary) : 'Explore this lesson to learn new concepts.',
    'lesson_section' => $section->section,
    'progress_percentage' => $progress_percentage,
    'total_subsections' => $total_subsections,
    'completed_subsections' => $completed_subsections,
    'has_subsections' => !empty($subsections),
    'subsections' => $subsections,
    
    // Course data
    'course_id' => $course->id,
    'course_name' => $course->fullname,
    'course_shortname' => $course->shortname,
    'course_image' => $courseimage,
    'category_name' => $categoryname,
    'grade_level' => $grade_level,
    
    // URLs for navigation
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out() : 
        (new moodle_url('/my/courses.php'))->out(),
    'lessonsurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out() : 
        (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out() : 
        (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'calendarurl' => (new moodle_url('/calendar/view.php'))->out(),
    
    // Sidebar flags
    'show_elementary_sidebar' => $dashboardtype === 'elementary',
    'show_middle_sidebar' => $dashboardtype === 'middle',
    'show_high_sidebar' => $dashboardtype === 'high',
    'hide_default_navbar' => true,
    
    // Active state for navigation
    'is_lessons_page' => true,
    
    // Body attributes for styling
    'bodyattributes' => $OUTPUT->body_attributes(['class' => 'lesson-view-page ' . $dashboardtype . '-dashboard']),
];

// Render the template
echo $OUTPUT->render_from_template('theme_remui_kids/lesson_view_page', $templatecontext);
