<?php
/**
 * My Courses Page - Dedicated page for viewing all student courses
 * This page displays courses in a dedicated layout separate from the dashboard
 */

require_once('../../config.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/my_courses.php');
$PAGE->set_title('My Courses');
$PAGE->set_heading('My Courses');

// Check if user is a student (has student role)
$user_roles = get_user_roles($context, $USER->id);
$is_student = false;
foreach ($user_roles as $role) {
    if ($role->shortname === 'student') {
        $is_student = true;
        break;
    }
}

// Redirect if not a student
if (!$is_student) {
    redirect(new moodle_url('/'));
}

// Get user's grade level from profile or cohort
$user_grade = 'Grade 1'; // Default grade
$user_cohorts = cohort_get_user_cohorts($USER->id);

// Check user profile custom field for grade
$user_profile_fields = profile_user_record($USER->id);
if (isset($user_profile_fields->grade)) {
    $user_grade = $user_profile_fields->grade;
} else {
    // Fallback to cohort-based detection
    foreach ($user_cohorts as $cohort) {
        $cohort_name = strtolower($cohort->name);
        if (strpos($cohort_name, 'grade 1') !== false || strpos($cohort_name, 'elementary grade 1') !== false) {
            $user_grade = 'Grade 1';
            break;
        } elseif (strpos($cohort_name, 'grade 2') !== false || strpos($cohort_name, 'elementary grade 2') !== false) {
            $user_grade = 'Grade 2';
            break;
        } elseif (strpos($cohort_name, 'grade 3') !== false || strpos($cohort_name, 'elementary grade 3') !== false) {
            $user_grade = 'Grade 3';
            break;
        }
    }
}

// Get courses for the student's grade level using the existing function
$elementary_courses = theme_remui_kids_get_elementary_courses($USER->id);

// Prepare course data for template
$courses_data = array();
foreach ($elementary_courses as $course) {
    // Get course image
    $course_image = '';
    $course_context = context_course::instance($course['id']);
    $fs = get_file_storage();
    $files = $fs->get_area_files($course_context->id, 'course', 'overviewfiles', 0, 'itemid, filepath, filename', false);
    if ($files) {
        foreach ($files as $file) {
            $course_image = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out();
            break;
        }
    }
    
    // Default course image if none found
    if (empty($course_image)) {
        $course_image = $OUTPUT->image_url('default_course', 'theme');
    }
    
    // Get course progress
    $progress = 0;
    $completion = new completion_info($DB->get_record('course', array('id' => $course['id'])));
    if ($completion->is_enabled()) {
        $progress = (int) \core_completion\progress::get_course_progress_percentage(
            $DB->get_record('course', array('id' => $course['id'])), 
            $USER->id
        );
    }
    
    // Get course URL
    $course_url = new moodle_url('/course/view.php', array('id' => $course['id']));
    
    $courses_data[] = array(
        'id' => $course['id'],
        'fullname' => $course['fullname'],
        'shortname' => $course['shortname'],
        'summary' => format_text($course['summary'], FORMAT_HTML),
        'image' => $course_image,
        'url' => $course_url->out(),
        'progress' => $course['progress_percentage'] ?? $progress,
        'grade_level' => $user_grade,
        'categoryname' => $course['categoryname'] ?? 'General',
        'completed' => $course['progress_percentage'] >= 100,
        'in_progress' => $course['progress_percentage'] > 0 && $course['progress_percentage'] < 100,
        'completed_sections' => $course['completed_sections'] ?? 0,
        'total_sections' => $course['total_sections'] ?? 0,
        'completed_activities' => $course['completed_activities'] ?? 0,
        'total_activities' => $course['total_activities'] ?? 0,
        'points_earned' => $course['points_earned'] ?? 0
    );
}

// Prepare template data
$template_data = array(
    'user_grade' => $user_grade,
    'courses' => $courses_data,
    'total_courses' => count($courses_data),
    'user_name' => fullname($USER),
    'dashboard_url' => new moodle_url('/my/'),
    'current_url' => $PAGE->url->out(),
    'show_admin_sidebar' => false, // Don't show admin sidebar on this page
    'customhomepage' => true // Use custom layout
);

// Render the page
echo $OUTPUT->header();

// Use the theme's course template
$renderer = $PAGE->get_renderer('theme_remui_kids');
echo $renderer->render_from_template('theme_remui_kids/my_courses', $template_data);

echo $OUTPUT->footer();

