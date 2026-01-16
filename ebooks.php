<?php
/**
 * E-books Page - Digital Learning Materials
 * Displays available e-books and digital learning resources
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
require_once(__DIR__ . '/lib/sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/ebooks.php');

$PAGE->set_title('E-books', false);

// Get user's cohort information
try {
    $usercohorts = $DB->get_records_sql(
        "SELECT c.name, c.id 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$USER->id]
    );
} catch (Exception $e) {
    $usercohorts = [];
}

$usercohortname = '';
$usercohortid = 0;
$dashboardtype = 'default';

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Determine dashboard type based on cohort
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $dashboardtype = 'highschool';
    } elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    }
}

// Get enrolled courses
try {
    $courses = enrol_get_all_users_courses($USER->id, true);
    $courseids = array_keys($courses);
} catch (Exception $e) {
    $courses = [];
    $courseids = [];
}

// Get e-books data
$ebooks_data = [];
$total_ebooks = 0;

// First, get books from local_ebook plugin based on student's grade level
$usergrade = '';
if (!empty($usercohortname)) {
    // Extract grade from cohort name (e.g., "Grade 11" -> "grade11")
    if (preg_match('/grade\s*(\d+)/i', $usercohortname, $matches)) {
        $usergrade = 'grade' . $matches[1];
    }
}

// Load books from local_ebook plugin if available and user has a grade
if ($usergrade && file_exists($CFG->dirroot . '/local/ebook/classes/book.php')) {
    try {
        require_once($CFG->dirroot . '/local/ebook/classes/book.php');
        
        $localebooks = \local_ebook\book::get_all_books($usergrade, true);
        $context = context_system::instance();
        $fs = get_file_storage();
        
        foreach ($localebooks as $book) {
            // Get cover image URL - always check for files, not just when coverimageitemid is set
            $coverimage = null;
            $hascoverimage = false;
            
            try {
                // Prefer the uploaded coverimage itemid when available; fall back to book id.
                $coveritemid = !empty($book->coverimageitemid) ? $book->coverimageitemid : $book->id;
                // Try to get cover image files for this book
                $coverfiles = $fs->get_area_files($context->id, 'local_ebook', 'coverimage', 
                    $coveritemid, 'itemid, filepath, filename', false);
                if (!empty($coverfiles)) {
                    // Filter out directories
                    $coverfiles = array_filter($coverfiles, function($file) {
                        return !$file->is_directory();
                    });
                    if (!empty($coverfiles)) {
                        $file = reset($coverfiles);
                        // Generate pluginfile URL for the cover image.
                        // Use the exact component stored with the file ('local_ebook');
                        // using 'local/ebook' results in a 404 pluginfile path.
                        $component = $file->get_component();
                        $coverimageurl = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $component,
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        );
                        $coverimage = $coverimageurl->out(false);
                        $hascoverimage = true;
                    }
                }
            } catch (Exception $e) {
                error_log('Error loading cover image for book ' . $book->id . ': ' . $e->getMessage());
            }
            
            // If no cover image found, use default
            if (!$hascoverimage) {
                $coverimage = $CFG->wwwroot . '/local/ebook/pix/default_cover.svg';
            }
            
            $ebooks_data[] = [
                'id' => 'ebook_' . $book->id,
                'name' => $book->title,
                'description' => strip_tags($book->description),
                'course_name' => ucfirst(str_replace('_', ' ', $book->subject)),
                'author' => $book->author,
                'course_id' => 0,
                'url' => (new moodle_url('/local/ebook/preview.php', ['id' => $book->id]))->out(),
                'icon' => 'fa-book',
                'color' => 'purple',
                'is_local_ebook' => true,
                'cover_image' => $coverimage,
                'has_cover_image' => $hascoverimage
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error fetching local e-books: " . $e->getMessage());
    }
}

// Then, get book modules from enrolled courses
if (!empty($courseids)) {
    try {
        // Get book modules from courses
        list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['userid'] = $USER->id;
        
        $books = $DB->get_records_sql(
            "SELECT b.id, b.name, b.intro, b.course, c.fullname as coursename,
                    cm.id as cmid, cm.visible, cm.availability
             FROM {book} b
             JOIN {course} c ON b.course = c.id
             JOIN {course_modules} cm ON cm.instance = b.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'book'
             WHERE b.course $courseids_sql
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             ORDER BY c.fullname ASC, b.name ASC",
            $params
        );
        
        foreach ($books as $book) {
            $ebooks_data[] = [
                'id' => $book->id,
                'name' => $book->name,
                'description' => strip_tags($book->intro),
                'course_name' => $book->coursename,
                'course_id' => $book->course,
                'url' => (new moodle_url('/mod/book/view.php', ['id' => $book->cmid]))->out(),
                'icon' => 'fa-book',
                'color' => 'blue'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error fetching e-books: " . $e->getMessage());
    }
}

$total_ebooks = count($ebooks_data);

// If no real e-books found, add some sample data
if (empty($ebooks_data)) {
    $ebooks_data = [
        [
            'id' => 1,
            'name' => 'Mathematics Fundamentals',
            'description' => 'Comprehensive guide to basic mathematical concepts and problem-solving techniques.',
            'course_name' => 'Mathematics',
            'course_id' => 1,
            'url' => '#',
            'icon' => 'fa-book',
            'color' => 'blue'
        ],
        [
            'id' => 2,
            'name' => 'Science Explorer',
            'description' => 'Interactive science textbook covering physics, chemistry, and biology concepts.',
            'course_name' => 'Science',
            'course_id' => 2,
            'url' => '#',
            'icon' => 'fa-book',
            'color' => 'green'
        ],
        [
            'id' => 3,
            'name' => 'English Literature Guide',
            'description' => 'Collection of classic and contemporary literature with analysis and exercises.',
            'course_name' => 'English Language Arts',
            'course_id' => 3,
            'url' => '#',
            'icon' => 'fa-book',
            'color' => 'purple'
        ],
        [
            'id' => 4,
            'name' => 'History Through Time',
            'description' => 'Chronological exploration of world history with interactive timelines and maps.',
            'course_name' => 'Social Studies',
            'course_id' => 4,
            'url' => '#',
            'icon' => 'fa-book',
            'color' => 'orange'
        ]
    ];
    
    $total_ebooks = count($ebooks_data);
}

// Prepare template context for the E-books page
// Use appropriate sidebar helper based on dashboard type
if ($dashboardtype === 'highschool') {
    require_once(__DIR__ . '/lib/highschool_sidebar.php');
    $templatecontext = remui_kids_build_highschool_sidebar_context('ebooks', $USER, [
        'custom_ebooks' => true,
        'ebooks_data' => $ebooks_data,
        'has_ebooks' => !empty($ebooks_data),
        'total_ebooks' => $total_ebooks,
        'is_ebooks_page' => true,
        'dashboardtype' => $dashboardtype,
        'elementary' => false,
        'middle' => false,
        'highschool' => true,
    ]);
} elseif ($dashboardtype === 'elementary') {
    // For elementary students, use elementary sidebar helper
    $templatecontext = theme_remui_kids_get_elementary_sidebar_context('ebooks', $USER);
    $templatecontext['custom_ebooks'] = true;
    $templatecontext['ebooks_data'] = $ebooks_data;
    $templatecontext['has_ebooks'] = !empty($ebooks_data);
    $templatecontext['total_ebooks'] = $total_ebooks;
    $templatecontext['is_ebooks_page'] = true;
    $templatecontext['ebooksurl'] = (new moodle_url('/theme/remui_kids/ebooks.php'))->out();
    $templatecontext['dashboardtype'] = $dashboardtype;
    $templatecontext['elementary'] = true;
    $templatecontext['middle'] = false;
    $templatecontext['highschool'] = false;
} else {
    // For middle school and default, build context with access flags
    $templatecontext = [
        'custom_ebooks' => true,
        'student_name' => $USER->firstname ?: $USER->username,
        'usercohortname' => $usercohortname,
        'dashboardtype' => $dashboardtype,
        'is_middle_grade' => ($dashboardtype === 'middle'),
        'ebooks_data' => $ebooks_data,
        'has_ebooks' => !empty($ebooks_data),
        'total_ebooks' => $total_ebooks,
        
        // Page identification flags for sidebar
        'is_ebooks_page' => true,
        'is_dashboard_page' => false,
        
        // Navigation URLs
        'wwwroot' => $CFG->wwwroot,
        'mycoursesurl' => new moodle_url('/theme/remui_kids/moodle_mycourses.php'),
        'dashboardurl' => new moodle_url('/my/'),
        'assignmentsurl' => !empty($courses) ? (new moodle_url('/mod/assign/index.php', ['id' => reset($courses)->id]))->out() : (new moodle_url('/my/courses.php'))->out(),
        'lessonsurl' => new moodle_url('/theme/remui_kids/lessons.php'),
        'activitiesurl' => new moodle_url('/mod/quiz/index.php'),
        'achievementsurl' => new moodle_url('/theme/remui_kids/achievements.php'),
        'competenciesurl' => new moodle_url('/theme/remui_kids/competencies.php'),
        'gradesurl' => new moodle_url('/theme/remui_kids/grades.php'),
        'badgesurl' => new moodle_url('/theme/remui_kids/badges.php'),
        'scheduleurl' => new moodle_url('/theme/remui_kids/schedule.php'),
        'calendarurl' => new moodle_url('/calendar/view.php'),
        'settingsurl' => new moodle_url('/user/preferences.php'),
        'treeviewurl' => new moodle_url('/theme/remui_kids/treeview.php'),
        'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
        'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
        'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
        'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
        'messagesurl' => new moodle_url('/message/index.php'),
        'profileurl' => new moodle_url('/user/profile.php', ['id' => $USER->id]),
        'logouturl' => new moodle_url('/login/logout.php', ['sesskey' => sesskey()]),
        'config' => ['wwwroot' => $CFG->wwwroot],
        
        // Sidebar access permissions (based on user's cohort)
        'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
        'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
        'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
        
        // Dashboard type flags for template
        'elementary' => false,
        'middle' => ($dashboardtype === 'middle'),
        'highschool' => ($dashboardtype === 'highschool'),
        
        'currentpage' => [
            'ebooks' => true
        ]
    ];
}

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/ebooks_page', $templatecontext);
echo $OUTPUT->footer();
