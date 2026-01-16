<?php
/**
 * Parent Dashboard - Course Detail Page
 * READ-ONLY view of child's course with:
 * - Course overview
 * - Topics/Lessons
 * - Assignments (with child's submissions)
 * - Quizzes (with child's attempts)
 * - Grades & Progress
 * 
 * Parents CAN:
 * - See child's course list
 * - Open the course page
 * - See topics, lessons, assignments, quizzes
 * - See child's grades & progress
 * - See child's attempts & submissions
 * 
 * Parents CANNOT:
 * - Attempt quizzes
 * - Submit assignments
 * - Be enrolled as a student
 * - Interact with course activities
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/lib/modinfolib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');

try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

if (!theme_remui_kids_user_is_parent($USER->id)) {
    redirect(
        new moodle_url('/'),
        get_string('nopermissions', 'error', get_string('access', 'moodle')),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Get parameters - USE SAME AS WORKING FILE
$courseid = required_param('courseid', PARAM_INT);
$requestedchildid = optional_param('child', 0, PARAM_INT); // Note: 'child' not 'childid'

// Get children and verify
$children = get_parent_children($USER->id);
if (empty($children)) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_children.php'), get_string('nopermissions', 'error', 'No linked students'));
}

$childrenbyid = [];
foreach ($children as $child) {
    $childrenbyid[$child['id']] = $child;
}

// Get course - EXACT SAME AS WORKING FILE
$course = $DB->get_record('course', ['id' => $courseid, 'visible' => 1], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid);

// Find eligible children (enrolled in this course)
$eligiblechildren = [];
foreach ($children as $child) {
    $childcourses = enrol_get_users_courses($child['id'], true, 'id');
    if (!empty($childcourses) && array_key_exists($courseid, $childcourses)) {
        $eligiblechildren[$child['id']] = $child;
    }
}

if (empty($eligiblechildren)) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_children.php'), get_string('nopermissions', 'error', 'Course not assigned to your children'));
}

// Select child - EXACT SAME LOGIC AS WORKING FILE
$selectedchildid = $requestedchildid && array_key_exists($requestedchildid, $eligiblechildren)
    ? $requestedchildid
    : array_key_first($eligiblechildren);

set_selected_child($selectedchildid);
$selectedchild = $eligiblechildren[$selectedchildid];
$child_name = $selectedchild['name'];
$childid = $selectedchildid; // For backward compatibility in rest of code

// Set up page context - EXACT SAME AS WORKING FILE
$PAGE->set_context($coursecontext);
$PAGE->set_url('/theme/remui_kids/parent/parent_course_detail.php', ['courseid' => $courseid, 'child' => $selectedchildid]);
$PAGE->set_course($course);
$PAGE->set_title('Course Details - Parent Dashboard');
$PAGE->set_heading('Course Details');
$PAGE->set_pagelayout('base');

// Add CSS files using Moodle's proper method
$PAGE->requires->css('/theme/remui_kids/style/parent_dashboard.css');

// Get course progress
$progress_percentage = 0;
try {
    if (class_exists('completion_info')) {
        $completion = new completion_info($course);
        if ($completion && method_exists($completion, 'is_enabled') && $completion->is_enabled()) {
            try {
                if (class_exists('\core_completion\progress')) {
                    $percentage = \core_completion\progress::get_course_progress_percentage($course, $childid);
                    $progress_percentage = ($percentage !== null && $percentage !== false) ? round((float)$percentage) : 0;
                }
            } catch (Exception $e) {
                $progress_percentage = 0;
            }
        }
    }
} catch (Exception $e) {
    $progress_percentage = 0;
}

// Get course grade
$course_grade = 'N/A';
$course_grade_percentage = 0;
try {
    if (class_exists('grade_item') && method_exists('grade_item', 'fetch_course_item')) {
        $grade_item = grade_item::fetch_course_item($course->id);
        if ($grade_item && isset($grade_item->id)) {
            if (class_exists('grade_grade') && method_exists('grade_grade', 'fetch')) {
                $grade = grade_grade::fetch(['userid' => $childid, 'itemid' => $grade_item->id]);
                if ($grade && isset($grade->id) && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                    if (isset($grade_item->grademax) && $grade_item->grademax > 0) {
                        $course_grade_percentage = round(($grade->finalgrade / $grade_item->grademax) * 100, 1);
                        $course_grade = round($grade->finalgrade, 1) . '/' . round($grade_item->grademax, 1);
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // Ignore grade errors
}

// Get teachers
$teachers = [];
try {
    $context_course = context_course::instance($course->id);
    $teacher_roles = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname
            FROM {user} u
         JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {role} r ON r.id = ra.roleid
         WHERE ra.contextid = ?
         AND r.shortname IN ('editingteacher', 'teacher')
         AND u.deleted = 0
         AND u.id NOT IN (
             SELECT DISTINCT ra_admin.userid
             FROM {role_assignments} ra_admin
             JOIN {role} r_admin ON r_admin.id = ra_admin.roleid
             WHERE r_admin.shortname IN ('manager', 'administrator')
         )
         LIMIT 5",
        [$context_course->id]
    );
    
    foreach ($teacher_roles as $teacher) {
        $teachers[] = fullname($teacher);
    }
} catch (Exception $e) {
    // Ignore teacher errors
}

// Get course sections (topics/lessons) - EXACT COPY FROM WORKING parent_course_view.php
$course_sections = [];
try {
    // Use same approach as parent_course_view.php
    // Wrap modinfo in try-catch to handle invalid modules gracefully
    try {
        $modinfo = get_fast_modinfo($course, $childid);
    } catch (moodle_exception $e) {
        // If modinfo fails, log and return empty sections
        error_log('Failed to get modinfo for course ' . $courseid . ' child ' . $childid . ': ' . $e->getMessage());
        $course_sections = [];
        $modinfo = null;
    } catch (Exception $e) {
        error_log('Error getting modinfo for course ' . $courseid . ': ' . $e->getMessage());
        $course_sections = [];
        $modinfo = null;
    }
    
    // If modinfo is null, skip processing
    if (!$modinfo) {
        $course_sections = [];
    } else {
        $formatoptions = ['context' => $coursecontext, 'para' => false];
        
        foreach ($modinfo->get_section_info_all() as $sectionnum => $sectioninfo) {
        if ($sectionnum == 0) {
            continue; // Skip general section
        }
        
        $sectionvisible = $sectioninfo->uservisible;
        $sectionname = get_section_name($course, $sectioninfo);
        $sectionsummary = format_text($sectioninfo->summary, $sectioninfo->summaryformat, $formatoptions);
        
        $activities = [];
        $sectioncmids = $modinfo->sections[$sectionnum] ?? [];
        
        // Skip if section not visible and no activities
        if (!$sectionvisible && empty($sectioncmids) && trim(strip_tags($sectionsummary)) === '') {
            continue;
        }
        
        foreach ($sectioncmids as $cmid) {
            // WRAP EVERYTHING IN TRY-CATCH TO SKIP INVALID MODULES
            try {
                // Check if module exists in modinfo
                if (!isset($modinfo->cms[$cmid])) {
                    continue; // Skip if module doesn't exist
                }
                
                $cm = $modinfo->cms[$cmid];
                
                // Validate module is not null and has required properties
                if (!$cm || !isset($cm->id) || !isset($cm->modname) || !isset($cm->name)) {
                    continue; // Skip invalid modules
                }
                
                // Check if module is being deleted
                if (isset($cm->deletioninprogress) && $cm->deletioninprogress) {
                    continue; // Skip modules being deleted
                }
                
                // Exact same check as parent_course_view.php
                if (!$cm->is_visible_on_course_page() && !$cm->uservisible) {
                    continue;
                }
                
                // Get completion status - wrap in try-catch
                $is_completed = false;
                try {
                    $completioninfo = new completion_info($course);
                    if ($completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                        $completiondata = $completioninfo->get_data($cm, false, $childid);
                        if ($completiondata) {
                            $completionstate = (int) $completiondata->completionstate;
                            if ($completionstate == COMPLETION_COMPLETE || $completionstate == COMPLETION_COMPLETE_PASS) {
                                $is_completed = true;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Ignore completion errors - just continue
                } catch (Error $e) {
                    // Ignore fatal errors
                }
                
                // Get module name - use same format as parent_course_view.php (no 'mod_' prefix)
                $type_name = ucfirst($cm->modname);
                try {
                    $type_name = get_string('modulename', $cm->modname);
                } catch (Exception $e) {
                    $type_name = ucfirst($cm->modname);
                }
                
                // Validate name exists
                $module_name = !empty($cm->name) ? format_string($cm->name) : 'Unnamed Activity';
                
                // Don't generate URL - just use # to avoid validation issues
                $activities[] = [
                    'id' => $cm->id,
                    'name' => $module_name,
                    'type' => $cm->modname,
                    'type_name' => $type_name,
                    'url' => '#', // Don't generate URL to avoid validation
                    'completed' => $is_completed
                ];
            } catch (moodle_exception $e) {
                // Skip modules that cause moodle_exception (like invalid course module ID)
                error_log('Skipping invalid course module ID ' . $cmid . ' in course ' . $courseid . ': ' . $e->getMessage());
                continue; // Skip this module and continue with next
            } catch (Exception $e) {
                // Skip modules that cause any other exception
                error_log('Skipping course module ' . $cmid . ' in course ' . $courseid . ': ' . $e->getMessage());
                continue; // Skip this module and continue with next
            } catch (Error $e) {
                // Skip modules that cause fatal errors
                error_log('Skipping course module ' . $cmid . ' in course ' . $courseid . ' (fatal error): ' . $e->getMessage());
                continue; // Skip this module and continue with next
            }
        }
        
        $course_sections[] = [
            'id' => $sectioninfo->id,
            'section' => $sectionnum,
            'name' => $sectionname,
            'summary' => $sectionsummary,
            'activities' => $activities
        ];
        }
    }
} catch (moodle_exception $e) {
    // Catch moodle_exception specifically (like invalid course module ID)
    debugging('Moodle exception getting course sections: ' . $e->getMessage());
    error_log('Parent course detail moodle exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    // Don't fail completely - just show empty sections
    $course_sections = [];
} catch (Exception $e) {
    debugging('Error getting course sections: ' . $e->getMessage());
    error_log('Parent course detail error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    // Don't fail completely - just show empty sections
    $course_sections = [];
} catch (Error $e) {
    debugging('Fatal error getting course sections: ' . $e->getMessage());
    error_log('Parent course detail fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    $course_sections = [];
}

// Get assignments with child's submissions - SAFE VERSION
$assignments = [];
try {
    // Get assignments directly without joining course_modules to avoid validation
    $assign_records = $DB->get_records('assign', ['course' => $courseid], 'duedate ASC, name ASC', 
        'id, name, intro, duedate, grade, timemodified');
    
    if (empty($assign_records)) {
        $assign_records = [];
    }
    
    foreach ($assign_records as $assign) {
        // Get child's submission
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $childid,
            'latest' => 1
        ]);
        
        $submission_status = 'not_submitted';
        $submission_date = null;
        $submission_grade = null;
        
        if ($submission) {
            $submission_status = $submission->status;
            $submission_date = $submission->timemodified;
            
            // Get grade if available
            if ($submission->status == 'submitted' || $submission->status == 'graded') {
                $grade = $DB->get_record('assign_grades', [
                    'assignment' => $assign->id,
                    'userid' => $childid
                ]);
                if ($grade && $grade->grade !== null) {
                    $submission_grade = $grade->grade;
                }
            }
        }
        
        // Don't generate URL to avoid course module validation
        $assignments[] = [
            'id' => $assign->id,
            'name' => $assign->name,
            'intro' => $assign->intro,
            'duedate' => $assign->duedate,
            'max_grade' => $assign->grade,
            'submission_status' => $submission_status,
            'submission_date' => $submission_date,
            'submission_grade' => $submission_grade,
            'url' => '#' // Don't generate URL to avoid validation
        ];
    }
} catch (Exception $e) {
    debugging('Error getting assignments: ' . $e->getMessage());
    error_log('Assignment error: ' . $e->getMessage());
    $assignments = [];
} catch (Error $e) {
    error_log('Assignment fatal error: ' . $e->getMessage());
    $assignments = [];
}

// Get quizzes with child's attempts - SAFE VERSION
$quizzes = [];
try {
    // Get quizzes directly without joining course_modules to avoid validation
    $quiz_records = $DB->get_records('quiz', ['course' => $courseid], 'timeclose ASC, name ASC',
        'id, name, intro, timeopen, timeclose, grade, timemodified');
    
    if (empty($quiz_records)) {
        $quiz_records = [];
    }
    
    foreach ($quiz_records as $quiz) {
        // Get child's attempts
        $attempts = $DB->get_records('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $childid
        ], 'attempt DESC', 'id, attempt, state, timestart, timefinish, sumgrades');
        
        $best_grade = null;
        $attempt_count = count($attempts);
        $last_attempt_date = null;
        
        if (!empty($attempts)) {
            foreach ($attempts as $attempt) {
                if ($attempt->state == 'finished') {
                    if ($best_grade === null || $attempt->sumgrades > $best_grade) {
                        $best_grade = $attempt->sumgrades;
                    }
                    if ($last_attempt_date === null || $attempt->timefinish > $last_attempt_date) {
                        $last_attempt_date = $attempt->timefinish;
                    }
                }
            }
        }
        
        // Don't generate URL to avoid course module validation
        $quizzes[] = [
            'id' => $quiz->id,
            'name' => $quiz->name,
            'intro' => $quiz->intro,
            'timeopen' => $quiz->timeopen,
            'timeclose' => $quiz->timeclose,
            'max_grade' => $quiz->grade,
            'attempt_count' => $attempt_count,
            'best_grade' => $best_grade,
            'last_attempt_date' => $last_attempt_date,
            'url' => '#' // Don't generate URL to avoid validation
        ];
    }
} catch (Exception $e) {
    debugging('Error getting quizzes: ' . $e->getMessage());
    error_log('Quiz error: ' . $e->getMessage());
    $quizzes = [];
} catch (Error $e) {
    error_log('Quiz fatal error: ' . $e->getMessage());
    $quizzes = [];
}

// Get all grades for this course
$course_grades = [];
try {
    $sql = "SELECT gg.id, gg.finalgrade, gg.timemodified,
                   gi.itemname, gi.grademax, gi.itemtype, gi.itemmodule
            FROM {grade_grades} gg
            JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gg.userid = ?
            AND gi.courseid = ?
            AND gg.finalgrade IS NOT NULL
            AND gi.itemtype != 'course'
            ORDER BY gg.timemodified DESC";
    
    $grades = $DB->get_records_sql($sql, [$childid, $courseid]);
    
    foreach ($grades as $grade) {
        $finalgrade = $grade->finalgrade !== null ? (float)$grade->finalgrade : 0;
        $maxgrade = $grade->grademax !== null ? (float)$grade->grademax : 0;
        $percentage = $maxgrade > 0 ? round(($finalgrade / $maxgrade) * 100, 1) : 0;
        
        $course_grades[] = [
            'name' => $grade->itemname,
            'type' => $grade->itemmodule ?: $grade->itemtype,
            'grade' => round($finalgrade, 1),
            'max' => round($maxgrade, 1),
            'percentage' => $percentage,
            'date' => userdate($grade->timemodified, '%d %b, %Y'),
            'status' => $percentage >= 70 ? 'good' : ($percentage >= 50 ? 'average' : 'needs-attention')
        ];
    }
} catch (Exception $e) {
    // Ignore errors
}

// Output page
echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">

<style>
/* Force full width */
#page, #page-wrapper, #region-main, #region-main-box, .main-inner, [role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Enhanced Modern Course Detail Page */
.parent-course-detail {
    margin-left: 280px;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
    padding: 24px 28px 32px;
    position: relative;
}

.parent-course-detail::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 15% 25%, rgba(59, 130, 246, 0.03) 0%, transparent 50%),
        radial-gradient(circle at 85% 75%, rgba(139, 92, 246, 0.02) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

/* Breadcrumb */
.course-breadcrumb {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 12px 20px;
    border-radius: 14px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    font-size: 12px;
    color: #64748b;
    position: relative;
    z-index: 1;
}

.course-breadcrumb a {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
}

.course-breadcrumb a:hover {
    text-decoration: underline;
}

/* Course Header */
.course-header {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    padding: 32px 40px;
    border-radius: 20px;
    margin-bottom: 28px;
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.25), 0 2px 8px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.course-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(40px);
}

.course-title {
    font-size: 32px;
    font-weight: 800;
    margin: 0 0 12px 0;
    letter-spacing: -0.5px;
    position: relative;
    z-index: 1;
}

.course-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 20px;
    position: relative;
    z-index: 1;
}

.course-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    opacity: 0.95;
}

.course-meta-item i {
    font-size: 16px;
}

/* Course Stats */
.course-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
    position: relative;
    z-index: 1;
}

.course-stat-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 20px 24px;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.course-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.course-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.course-stat-card:hover::before {
    opacity: 1;
}

.course-stat-icon {
    font-size: 32px;
    color: #3b82f6;
    margin-bottom: 12px;
    display: inline-block;
    width: 56px;
    height: 56px;
    line-height: 56px;
    text-align: center;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.1));
}

.course-stat-value {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 6px 0;
    letter-spacing: -1px;
    line-height: 1;
}

.course-stat-label {
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin: 0;
    font-weight: 700;
}

/* Tabs */
.course-tabs {
    display: flex;
    gap: 4px;
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    padding: 8px;
    border-radius: 12px;
    margin-bottom: 24px;
    overflow-x: auto;
    position: relative;
    z-index: 1;
}

.course-tab {
    padding: 12px 20px;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.course-tab:hover {
    background: rgba(255, 255, 255, 0.6);
    color: #475569;
    transform: translateY(-2px);
}

.course-tab.active {
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15), 0 2px 4px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.2);
}

.course-tab i {
    font-size: 14px;
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
    position: relative;
    z-index: 1;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Sections/Topics */
.section-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 24px;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.section-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1), 0 2px 6px rgba(0, 0, 0, 0.08);
}

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.section-number {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    color: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    flex-shrink: 0;
}

.section-name {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    flex: 1;
}

.activities-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    margin-top: 16px;
}

.activity-item {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    padding: 14px 18px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.2s ease;
}

.activity-item:hover {
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border-color: #cbd5e1;
}

.activity-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.1));
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
    font-size: 16px;
    flex-shrink: 0;
}

.activity-info {
    flex: 1;
}

.activity-name {
    font-size: 14px;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 4px 0;
}

.activity-type {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}

.activity-status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #cbd5e1;
    flex-shrink: 0;
}

.activity-status.completed {
    background: #10b981;
}

/* Assignments */
.assignments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}

.assignment-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 20px 24px;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-left: 4px solid #3b82f6;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.assignment-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.assignment-name {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 12px 0;
}

.assignment-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.assignment-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #64748b;
}

.assignment-meta-item i {
    color: #3b82f6;
    width: 16px;
}

.assignment-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.assignment-status.submitted {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.assignment-status.graded {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border: 1px solid #93c5fd;
}

.assignment-status.not_submitted {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}

/* Quizzes */
.quizzes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}

.quiz-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 20px 24px;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-left: 4px solid #8b5cf6;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.quiz-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.quiz-name {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 12px 0;
}

.quiz-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.quiz-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #64748b;
}

.quiz-meta-item i {
    color: #8b5cf6;
    width: 16px;
}

.quiz-grade {
    display: inline-block;
    padding: 8px 14px;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(124, 58, 237, 0.1));
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    color: #7c3aed;
}

/* Grades */
.grades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.grade-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 18px 22px;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.grade-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.grade-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.grade-item:hover::before {
    opacity: 1;
}

.grade-item.good::before { background: linear-gradient(180deg, #10b981, #059669); }
.grade-item.average::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
.grade-item.needs-attention::before { background: linear-gradient(180deg, #ef4444, #dc2626); }

.grade-info {
    flex: 1;
}

.grade-name {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px 0;
}

.grade-type {
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.grade-score {
    text-align: right;
}

.grade-percentage {
    font-size: 28px;
    font-weight: 800;
    margin: 0;
    letter-spacing: -1px;
    line-height: 1;
}

.grade-percentage.good { color: #10b981; }
.grade-percentage.average { color: #f59e0b; }
.grade-percentage.needs-attention { color: #ef4444; }

.grade-value {
    font-size: 13px;
    color: #6b7280;
    margin: 4px 0 0 0;
}

.grade-date {
    font-size: 11px;
    color: #94a3b8;
    margin: 6px 0 0 0;
    font-weight: 600;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 40px;
    color: #9ca3af;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 16px;
    border: 2px dashed #cbd5e1;
    position: relative;
    overflow: hidden;
}

.empty-icon {
    font-size: 70px;
    color: #cbd5e1;
    margin-bottom: 20px;
    opacity: 0.7;
}

.empty-title {
    font-size: 24px;
    font-weight: 800;
    color: #475569;
    margin: 0 0 12px 0;
}

.empty-text {
    font-size: 14px;
    color: #64748b;
    font-weight: 500;
}

/* Read-only badge */
.read-only-badge {
    display: inline-block;
    padding: 4px 10px;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 8px;
}

/* Responsive */
@media (max-width: 768px) {
    .parent-course-detail {
        margin-left: 0;
        padding: 16px;
    }
    
    .course-stats-grid,
    .assignments-grid,
    .quizzes-grid,
    .grades-grid,
    .activities-list {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="parent-course-detail">
    <!-- Breadcrumb -->
    <div class="course-breadcrumb">
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_children.php">
            <i class="fas fa-arrow-left"></i> Back to My Children
        </a>
        <span style="margin: 0 8px;">/</span>
        <span><?php echo htmlspecialchars($child_name); ?></span>
        <span style="margin: 0 8px;">/</span>
        <span><?php echo htmlspecialchars($course->fullname); ?></span>
    </div>

    <!-- Course Header -->
    <div class="course-header">
        <h1 class="course-title">
            <i class="fas fa-book"></i> <?php echo htmlspecialchars($course->fullname); ?>
            <span class="read-only-badge">READ-ONLY</span>
        </h1>
        <p style="margin: 0; opacity: 0.95; font-size: 15px; position: relative; z-index: 1;">
            <?php echo htmlspecialchars($course->shortname); ?>
        </p>
        <div class="course-meta">
            <div class="course-meta-item">
                <i class="fas fa-user"></i>
                <span>Child: <?php echo htmlspecialchars($child_name); ?></span>
            </div>
            <?php if (!empty($teachers)): ?>
            <div class="course-meta-item">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Teachers: <?php echo htmlspecialchars(implode(', ', $teachers)); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Course Stats -->
    <div class="course-stats-grid">
        <div class="course-stat-card">
            <div class="course-stat-icon"><i class="fas fa-chart-line"></i></div>
            <p class="course-stat-value"><?php echo $progress_percentage; ?>%</p>
            <p class="course-stat-label">Progress</p>
        </div>
        <div class="course-stat-card">
            <div class="course-stat-icon"><i class="fas fa-star"></i></div>
            <p class="course-stat-value"><?php echo $course_grade; ?></p>
            <p class="course-stat-label">Course Grade</p>
        </div>
        <div class="course-stat-card">
            <div class="course-stat-icon"><i class="fas fa-tasks"></i></div>
            <p class="course-stat-value"><?php echo count($assignments); ?></p>
            <p class="course-stat-label">Assignments</p>
        </div>
        <div class="course-stat-card">
            <div class="course-stat-icon"><i class="fas fa-question-circle"></i></div>
            <p class="course-stat-value"><?php echo count($quizzes); ?></p>
            <p class="course-stat-label">Quizzes</p>
        </div>
        <div class="course-stat-card">
            <div class="course-stat-icon"><i class="fas fa-list"></i></div>
            <p class="course-stat-value"><?php echo count($course_sections); ?></p>
            <p class="course-stat-label">Topics</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="course-tabs">
        <button class="course-tab active" onclick="switchTab(event, 'topics-tab')">
            <i class="fas fa-list"></i> Topics & Lessons
        </button>
        <button class="course-tab" onclick="switchTab(event, 'assignments-tab')">
            <i class="fas fa-tasks"></i> Assignments
        </button>
        <button class="course-tab" onclick="switchTab(event, 'quizzes-tab')">
            <i class="fas fa-question-circle"></i> Quizzes
        </button>
        <button class="course-tab" onclick="switchTab(event, 'grades-tab')">
            <i class="fas fa-chart-bar"></i> Grades
        </button>
    </div>

    <!-- Tab: Topics & Lessons -->
    <div id="topics-tab" class="tab-content active">
        <?php if (!empty($course_sections)): ?>
            <?php foreach ($course_sections as $section): ?>
            <div class="section-card">
                <div class="section-header">
                    <div class="section-number"><?php echo $section['section']; ?></div>
                    <h3 class="section-name"><?php echo htmlspecialchars($section['name']); ?></h3>
                </div>
                <?php if (!empty($section['activities'])): ?>
                <div class="activities-list">
                    <?php foreach ($section['activities'] as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <?php
                            $icon = 'fa-file';
                            if ($activity['type'] == 'assign') $icon = 'fa-tasks';
                            elseif ($activity['type'] == 'quiz') $icon = 'fa-question-circle';
                            elseif ($activity['type'] == 'forum') $icon = 'fa-comments';
                            elseif ($activity['type'] == 'resource') $icon = 'fa-file-alt';
                            ?>
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="activity-info">
                            <p class="activity-name"><?php echo htmlspecialchars($activity['name']); ?></p>
                            <p class="activity-type"><?php echo htmlspecialchars($activity['type_name']); ?></p>
                        </div>
                        <div class="activity-status <?php echo $activity['completed'] ? 'completed' : ''; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color: #94a3b8; font-size: 13px; margin: 0;">No activities in this section</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-list"></i></div>
            <h3 class="empty-title">No Topics Available</h3>
            <p class="empty-text">This course doesn't have any topics or lessons yet.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Assignments -->
    <div id="assignments-tab" class="tab-content">
        <?php if (!empty($assignments)): ?>
        <div class="assignments-grid">
            <?php foreach ($assignments as $assignment): ?>
            <div class="assignment-card">
                <h4 class="assignment-name"><?php echo htmlspecialchars($assignment['name']); ?></h4>
                <div class="assignment-meta">
                    <?php if ($assignment['duedate']): ?>
                    <div class="assignment-meta-item">
                        <i class="fas fa-calendar"></i>
                        <span>Due: <?php echo userdate($assignment['duedate'], '%d %b, %Y %I:%M %p'); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($assignment['max_grade']): ?>
                    <div class="assignment-meta-item">
                        <i class="fas fa-star"></i>
                        <span>Max Grade: <?php echo $assignment['max_grade']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="margin-bottom: 12px;">
                    <span class="assignment-status <?php echo $assignment['submission_status']; ?>">
                        <?php
                        if ($assignment['submission_status'] == 'submitted') echo 'Submitted';
                        elseif ($assignment['submission_status'] == 'graded') echo 'Graded';
                        else echo 'Not Submitted';
                        ?>
                    </span>
                </div>
                <?php if ($assignment['submission_date']): ?>
                <div class="assignment-meta-item">
                    <i class="fas fa-clock"></i>
                    <span>Submitted: <?php echo userdate($assignment['submission_date'], '%d %b, %Y %I:%M %p'); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($assignment['submission_grade'] !== null): ?>
                <div class="assignment-meta-item">
                    <i class="fas fa-check-circle"></i>
                    <span><strong>Grade: <?php echo $assignment['submission_grade']; ?>/<?php echo $assignment['max_grade']; ?></strong></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-tasks"></i></div>
            <h3 class="empty-title">No Assignments</h3>
            <p class="empty-text">This course doesn't have any assignments yet.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Quizzes -->
    <div id="quizzes-tab" class="tab-content">
        <?php if (!empty($quizzes)): ?>
        <div class="quizzes-grid">
            <?php foreach ($quizzes as $quiz): ?>
            <div class="quiz-card">
                <h4 class="quiz-name"><?php echo htmlspecialchars($quiz['name']); ?></h4>
                <div class="quiz-meta">
                    <?php if ($quiz['timeopen']): ?>
                    <div class="quiz-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Opens: <?php echo userdate($quiz['timeopen'], '%d %b, %Y %I:%M %p'); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($quiz['timeclose']): ?>
                    <div class="quiz-meta-item">
                        <i class="fas fa-calendar-times"></i>
                        <span>Closes: <?php echo userdate($quiz['timeclose'], '%d %b, %Y %I:%M %p'); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($quiz['max_grade']): ?>
                    <div class="quiz-meta-item">
                        <i class="fas fa-star"></i>
                        <span>Max Grade: <?php echo $quiz['max_grade']; ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="quiz-meta-item">
                        <i class="fas fa-redo"></i>
                        <span>Attempts: <?php echo $quiz['attempt_count']; ?></span>
                    </div>
                </div>
                <?php if ($quiz['best_grade'] !== null): ?>
                <div style="margin-top: 12px;">
                    <span class="quiz-grade">
                        Best Grade: <?php echo round($quiz['best_grade'], 1); ?>/<?php echo $quiz['max_grade']; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($quiz['last_attempt_date']): ?>
                <div class="quiz-meta-item" style="margin-top: 8px;">
                    <i class="fas fa-clock"></i>
                    <span>Last Attempt: <?php echo userdate($quiz['last_attempt_date'], '%d %b, %Y %I:%M %p'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-question-circle"></i></div>
            <h3 class="empty-title">No Quizzes</h3>
            <p class="empty-text">This course doesn't have any quizzes yet.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Grades -->
    <div id="grades-tab" class="tab-content">
        <?php if (!empty($course_grades)): ?>
        <div class="grades-grid">
            <?php foreach ($course_grades as $grade): ?>
            <div class="grade-item <?php echo $grade['status']; ?>">
                <div class="grade-info">
                    <h4 class="grade-name"><?php echo htmlspecialchars($grade['name']); ?></h4>
                    <p class="grade-type"><?php echo htmlspecialchars($grade['type']); ?></p>
                </div>
                <div class="grade-score">
                    <p class="grade-percentage <?php echo $grade['status']; ?>"><?php echo $grade['percentage']; ?>%</p>
                    <p class="grade-value"><?php echo $grade['grade']; ?>/<?php echo $grade['max']; ?></p>
                    <p class="grade-date"><?php echo $grade['date']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-chart-bar"></i></div>
            <h3 class="empty-title">No Grades Yet</h3>
            <p class="empty-text">No grades have been posted for this course yet.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tabs
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Remove active from all tab buttons
    const tabButtons = document.querySelectorAll('.course-tab');
    tabButtons.forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    
    // Add active to clicked button
    event.target.classList.add('active');
}
</script>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php
echo $OUTPUT->footer();
?>

