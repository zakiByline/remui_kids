<?php
/**
 * Student Courses Page
 * Displays courses for Grade 1-3 students in the student dashboard
 */

require_once('../../config.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/student_courses.php');
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

// Get courses for the student's grade level
$grade_courses = array();

// Define grade-specific course patterns and categories
$grade_course_patterns = array(
    'Grade 1' => array(
        'patterns' => array('grade 1', 'g1', 'first grade', 'elementary grade 1', 'beginner', 'foundation', 'level 1'),
        'categories' => array('Grade 1', 'Elementary', 'Foundation', 'Beginner')
    ),
    'Grade 2' => array(
        'patterns' => array('grade 2', 'g2', 'second grade', 'elementary grade 2', 'intermediate', 'level 2'),
        'categories' => array('Grade 2', 'Elementary', 'Intermediate')
    ),
    'Grade 3' => array(
        'patterns' => array('grade 3', 'g3', 'third grade', 'elementary grade 3', 'advanced', 'level 3'),
        'categories' => array('Grade 3', 'Elementary', 'Advanced')
    )
);

// Get all courses the user is enrolled in
$enrolled_courses = enrol_get_users_courses($USER->id, true, array('id', 'fullname', 'shortname', 'summary', 'category'));

// Get category names for better filtering
$categories = $DB->get_records('course_categories', null, '', 'id,name');

// Filter courses based on grade level
foreach ($enrolled_courses as $course) {
    if ($course->id == 1) continue; // Skip site course
    
    $course_name = strtolower($course->fullname);
    $course_summary = strtolower($course->summary);
    $course_category = isset($categories[$course->category]) ? strtolower($categories[$course->category]->name) : '';
    
    $matches_grade = false;
    
    // Check patterns in course name and summary
    foreach ($grade_course_patterns[$user_grade]['patterns'] as $pattern) {
        if (strpos($course_name, $pattern) !== false || strpos($course_summary, $pattern) !== false) {
            $matches_grade = true;
            break;
        }
    }
    
    // Check category name
    if (!$matches_grade) {
        foreach ($grade_course_patterns[$user_grade]['categories'] as $category_pattern) {
            if (strpos($course_category, strtolower($category_pattern)) !== false) {
                $matches_grade = true;
                break;
            }
        }
    }
    
    if ($matches_grade) {
        $grade_courses[] = $course;
    }
}

// If no grade-specific courses found, show all enrolled courses for elementary students
if (empty($grade_courses) && in_array($user_grade, array('Grade 1', 'Grade 2', 'Grade 3'))) {
    // Filter out system courses and show only user-enrolled courses
    foreach ($enrolled_courses as $course) {
        if ($course->id != 1 && $course->visible) {
            $grade_courses[] = $course;
        }
    }
}

// Prepare course data for template
$courses_data = array();
foreach ($grade_courses as $course) {
    if ($course->id == 1) continue; // Skip site course
    
    // Get course image
    $course_image = '';
    $course_context = context_course::instance($course->id);
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
    $completion = new completion_info($course);
    if ($completion->is_enabled()) {
        $progress = (int) \core_completion\progress::get_course_progress_percentage($course, $USER->id);
    }
    
    // Get course URL
    $course_url = new moodle_url('/course/view.php', array('id' => $course->id));
    
    $courses_data[] = array(
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'summary' => format_text($course->summary, FORMAT_HTML),
        'image' => $course_image,
        'url' => $course_url->out(),
        'progress' => $progress,
        'grade_level' => $user_grade
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
    'grades_url' => new moodle_url('/grade/report/overview/index.php'),
    'assignments_url' => !empty($grade_courses) ? new moodle_url('/mod/assign/index.php', ['id' => reset($grade_courses)->id]) : new moodle_url('/my/courses.php'),
    'messages_url' => new moodle_url('/message/index.php'),
    'profile_url' => new moodle_url('/user/profile.php', array('id' => $USER->id)),
    'logout_url' => new moodle_url('/login/logout.php', array('sesskey' => sesskey()))
);

// Render the page
echo $OUTPUT->header();

// Use the theme's course template or create a custom one
$renderer = $PAGE->get_renderer('theme_remui_kids');
echo $renderer->render_from_template('theme_remui_kids/student_courses', $template_data);

echo $OUTPUT->footer();
