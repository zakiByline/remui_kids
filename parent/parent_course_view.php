<?php
/**
 * Parent Course Detail Page
 *
 * Allows a parent to mirror the child's course view, drilling into sections
 * and activities with completion information.
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $PAGE, $OUTPUT;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');

if (!function_exists('remui_kids_parent_assign_progress_map')) {
    /**
     * Build activity progress map for assignments.
     */
    function remui_kids_parent_assign_progress_map(int $courseid, int $userid): array {
        global $DB;
        $params = [
            'courseidcm' => $courseid,
            'courseidassign' => $courseid,
            'useridassign' => $userid,
            'useridgrade' => $userid,
        ];
        $sql = "SELECT cm.id AS cmid,
                       asub.status AS submissionstatus,
                       asub.timecreated AS submissiontimestart,
                       asub.timemodified AS submissiontimemodified,
                       agr.grade AS finalgrade,
                       agr.timemodified AS gradetimemodified
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {assign} a ON a.id = cm.instance AND a.course = :courseidassign
             LEFT JOIN {assign_submission} asub
                       ON asub.assignment = a.id
                      AND asub.userid = :useridassign
                      AND asub.latest = 1
             LEFT JOIN {assign_grades} agr
                       ON agr.assignment = a.id
                      AND agr.userid = :useridgrade
                      AND agr.attemptnumber = -1
                 WHERE cm.course = :courseidcm";
        $records = $DB->get_records_sql($sql, $params);
        $map = [];
        foreach ($records as $record) {
            if (empty($record->cmid)) {
                continue;
            }
            $status = [
                'statuskey' => 'notstarted',
                'statuslabel' => get_string('notyetstarted', 'completion'),
                'statusicon' => 'minus-circle',
                'startedon' => 0,
                'completedon' => 0,
                'lastupdated' => 0,
            ];
            if (!empty($record->submissionstatus)) {
                $status['statuskey'] = 'pending';
                $status['statuslabel'] = get_string('inprogress', 'completion');
                $status['statusicon'] = 'clock';
                $status['startedon'] = (int) ($record->submissiontimestart ?? 0);
                $status['lastupdated'] = (int) ($record->submissiontimemodified ?? $record->submissiontimestart ?? 0);

                if ($record->submissionstatus === 'graded' || $record->finalgrade !== null) {
                    $status['statuskey'] = 'completed';
                    $status['statuslabel'] = get_string('completion-y', 'completion');
                    $status['statusicon'] = 'check-circle';
                    $status['completedon'] = (int) ($record->gradetimemodified ?? $status['lastupdated']);
                    if (!$status['startedon']) {
                        $status['startedon'] = $status['lastupdated'];
                    }
                }
            }
            $map[$record->cmid] = $status;
        }
        return $map;
    }
}

if (!function_exists('remui_kids_parent_quiz_progress_map')) {
    /**
     * Build activity progress map for quizzes.
     */
    function remui_kids_parent_quiz_progress_map(int $courseid, int $userid): array {
        global $DB;
        $params = [
            'courseidcm' => $courseid,
            'courseidquiz' => $courseid,
            'useridquiz_join' => $userid,
            'useridquiz_sub' => $userid,
        ];
        $sql = "SELECT cm.id AS cmid,
                       qa.state,
                       qa.timestart,
                       qa.timefinish
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quiz} q ON q.id = cm.instance AND q.course = :courseidquiz
             LEFT JOIN {quiz_attempts} qa
                       ON qa.quiz = q.id
                      AND qa.userid = :useridquiz_join
                      AND qa.id = (
                          SELECT MAX(qa2.id)
                            FROM {quiz_attempts} qa2
                           WHERE qa2.quiz = q.id
                             AND qa2.userid = :useridquiz_sub
                      )
                 WHERE cm.course = :courseidcm";
        $records = $DB->get_records_sql($sql, $params);
        $map = [];
        foreach ($records as $record) {
            if (empty($record->cmid) || empty($record->state)) {
                continue;
            }
            $status = [
                'statuskey' => 'pending',
                'statuslabel' => get_string('inprogress', 'completion'),
                'statusicon' => 'clock',
                'startedon' => (int) ($record->timestart ?? 0),
                'completedon' => 0,
                'lastupdated' => (int) ($record->timefinish ?? $record->timestart ?? 0),
            ];
            if ($record->state === 'finished') {
                $status['statuskey'] = 'completed';
                $status['statuslabel'] = get_string('completion-y', 'completion');
                $status['statusicon'] = 'check-circle';
                $status['completedon'] = (int) ($record->timefinish ?? 0);
            } elseif (in_array($record->state, ['inprogress', 'overdue', 'abandoned'], true)) {
                // keep pending defaults.
            } else {
                $status['statuskey'] = 'notstarted';
                $status['statuslabel'] = get_string('notyetstarted', 'completion');
                $status['statusicon'] = 'minus-circle';
                $status['startedon'] = 0;
                $status['lastupdated'] = 0;
            }
            $map[$record->cmid] = $status;
        }
        return $map;
    }
}

if (!function_exists('remui_kids_parent_activity_progress_map')) {
    /**
     * Aggregate fallback progress across supported modules.
     */
    function remui_kids_parent_activity_progress_map(int $courseid, int $userid): array {
        $map = [];
        $assign = remui_kids_parent_assign_progress_map($courseid, $userid);
        $quiz = remui_kids_parent_quiz_progress_map($courseid, $userid);
        $map = $assign;
        foreach ($quiz as $cmid => $status) {
            $map[$cmid] = $status;
        }
        return $map;
    }
}

if (!theme_remui_kids_user_is_parent($USER->id)) {
    redirect(
        new moodle_url('/'),
        get_string('nopermissions', 'error', get_string('access', 'moodle')),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$courseid = required_param('courseid', PARAM_INT);
$requestedchildid = optional_param('child', 0, PARAM_INT);

$children = get_parent_children($USER->id);
if (empty($children)) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_my_courses.php'), get_string('nopermissions', 'error', 'No linked students'));
}

$childrenbyid = [];
foreach ($children as $child) {
    $childrenbyid[$child['id']] = $child;
}

$course = $DB->get_record('course', ['id' => $courseid, 'visible' => 1], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid);

$eligiblechildren = [];
foreach ($children as $child) {
    $childcourses = enrol_get_users_courses($child['id'], true, 'id');
    if (!empty($childcourses) && array_key_exists($courseid, $childcourses)) {
        $eligiblechildren[$child['id']] = $child;
    }
}

if (empty($eligiblechildren)) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_my_courses.php'), get_string('nopermissions', 'error', 'Course not assigned to your children'));
}

$selectedchildid = $requestedchildid && array_key_exists($requestedchildid, $eligiblechildren)
    ? $requestedchildid
    : array_key_first($eligiblechildren);

set_selected_child($selectedchildid);
$selectedchild = $eligiblechildren[$selectedchildid];

$PAGE->set_context($coursecontext);
$PAGE->set_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $courseid, 'child' => $selectedchildid]);
$PAGE->set_course($course);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(''); // Hide "Course Details" heading

// Get course image safely
$courseimage = '';
try {
    $courseimage = theme_remui_kids_get_course_image($course);
} catch (Exception $e) {
    $courseimage = '';
}

// Get completion info safely
$completioninfo = null;
try {
    $completioninfo = new completion_info($course);
} catch (Exception $e) {
    error_log('Error creating completion_info for course ' . $courseid . ': ' . $e->getMessage());
    $completioninfo = null;
}

// Get course progress safely
$courseprogress = null;
$courseprogressdisplay = 'N/A';
try {
    if (class_exists('\core_completion\progress')) {
        $courseprogress = \core_completion\progress::get_course_progress_percentage($course, $selectedchildid);
        $courseprogressdisplay = $courseprogress === null ? 'N/A' : round($courseprogress) . '%';
    }
} catch (Exception $e) {
    error_log('Error getting course progress for course ' . $courseid . ': ' . $e->getMessage());
    $courseprogressdisplay = 'N/A';
}

$courseformat = course_get_format($course);

// WRAP modinfo IN TRY-CATCH TO HANDLE INVALID MODULES
$modinfo = null;
try {
    $modinfo = get_fast_modinfo($course, $selectedchildid);
} catch (moodle_exception $e) {
    // If modinfo fails, log and set to null
    error_log('Failed to get modinfo for course ' . $courseid . ' child ' . $selectedchildid . ': ' . $e->getMessage());
    $modinfo = null;
} catch (Exception $e) {
    error_log('Error getting modinfo for course ' . $courseid . ': ' . $e->getMessage());
    $modinfo = null;
} catch (Error $e) {
    error_log('Fatal error getting modinfo for course ' . $courseid . ': ' . $e->getMessage());
    $modinfo = null;
}

$sectionrecords = [];
$totalactivities = 0;
$completedactivities = 0;
$formatoptions = ['context' => $coursecontext, 'para' => false];
$childactivitycompletions = [];
$activityfallbackprogress = remui_kids_parent_activity_progress_map($courseid, $selectedchildid);

// Preload completion rows for every visible activity
if ($modinfo && !empty($modinfo->cms)) {
    $allcmids = array_keys($modinfo->cms);
    if (!empty($allcmids)) {
        list($cmidsql, $cmidparams) = $DB->get_in_or_equal($allcmids, SQL_PARAMS_NAMED);
        $cmidparams['userid'] = $selectedchildid;
        try {
            $completionrecords = $DB->get_records_select(
                'course_modules_completion',
                "coursemoduleid {$cmidsql} AND userid = :userid",
                $cmidparams,
                '',
                'id, coursemoduleid, completionstate, timestarted, timecompleted, timemodified'
            );
            foreach ($completionrecords as $record) {
                if (!empty($record->coursemoduleid)) {
                    $childactivitycompletions[$record->coursemoduleid] = $record;
                }
            }
        } catch (Exception $e) {
            $childactivitycompletions = [];
            error_log('Error preloading activity completions: ' . $e->getMessage());
        }
    }
}

// Only process if modinfo is valid
if ($modinfo) {
    foreach ($modinfo->get_section_info_all() as $sectionnum => $sectioninfo) {
        $sectionvisible = $sectioninfo->uservisible;
        $sectionname = $sectionnum === 0 ? get_string('general') : get_section_name($course, $sectioninfo);
        $sectionsummary = format_text($sectioninfo->summary, $sectioninfo->summaryformat, $formatoptions);

        $activities = [];
        $sectioncmids = $modinfo->sections[$sectionnum] ?? [];
        if (!$sectionvisible && empty($sectioncmids) && trim(strip_tags($sectionsummary)) === '') {
            continue;
        }

        foreach ($sectioncmids as $cmid) {
            // WRAP EVERY MODULE ACCESS IN TRY-CATCH TO SKIP INVALID MODULES
            try {
                // Check if module exists
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
                
                if (!$cm->is_visible_on_course_page() && !$cm->uservisible) {
                    continue;
                }

                // Get module name safely
                $modname = ucfirst($cm->modname);
                try {
                    $modname = get_string('modulename', $cm->modname);
                } catch (Exception $e) {
                    $modname = ucfirst($cm->modname);
                }

                // Get icon URL safely
                $iconurl = '';
                try {
                    if ($cm->get_icon_url()) {
                        $iconurl = $cm->get_icon_url()->out();
                    }
                } catch (Exception $e) {
                    // Keep empty if icon fails
                }

                // Get preview URL safely
                $previewurl = '#';
                try {
                    $previewurl = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                        'cmid' => $cm->id,
                        'child' => $selectedchildid,
                        'courseid' => $courseid
                    ]))->out();
                } catch (Exception $e) {
                    // Keep # if URL generation fails
                }

                $activity = [
                    'id' => $cm->id,
                    'name' => format_string($cm->name),
                    'modname' => $modname,
                    'modnamekey' => $cm->modname,
                    'icon' => $iconurl,
                    'previewurl' => $previewurl,
                    'visible' => $cm->uservisible,
                    'availability' => $cm->availableinfo ? format_string(strip_tags($cm->availableinfo)) : '',
                    'description' => '',
                    'statuskey' => 'notstarted',
                    'statuslabel' => get_string('notyetstarted', 'completion'),
                    'statusicon' => 'minus-circle',
                    'startedon' => 0,
                    'completedon' => 0,
                    'lastupdated' => 0,
                ];

                // Fetch a short intro/snippet when available - wrap in try-catch
                try {
                    $introhtml = format_module_intro($cm->modname, $cm, $cm->course, false);
                    if (!empty($introhtml)) {
                        $activity['description'] = trim(html_entity_decode(strip_tags($introhtml)));
                    }
                } catch (Exception $e) {
                    // Keep empty description if intro fails
                }

                $totalactivities++;
                $trackingenabled = $completioninfo && $completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE;
                $completionrecord = null;
                $completionstate = null;

                if ($trackingenabled) {
                    try {
                        $completionrecord = $completioninfo->get_data($cm, false, $selectedchildid);
                    } catch (Exception $e) {
                        $completionrecord = null;
                    } catch (Error $e) {
                        $completionrecord = null;
                    }
                }

                if (!$completionrecord && isset($childactivitycompletions[$cm->id])) {
                    $completionrecord = $childactivitycompletions[$cm->id];
                }

                $startedts = $completionrecord && !empty($completionrecord->timestarted) ? (int)$completionrecord->timestarted : 0;
                $completedts = $completionrecord && !empty($completionrecord->timecompleted) ? (int)$completionrecord->timecompleted : 0;
                $lastupdated = $completionrecord && !empty($completionrecord->timemodified) ? (int)$completionrecord->timemodified : 0;

                $statuskey = 'notstarted';
                $statuslabel = get_string('notyetstarted', 'completion');
                $statusicon = 'minus-circle';
                $activitycountedascomplete = false;

                if ($completionrecord) {
                    $completionstate = (int) ($completionrecord->completionstate ?? COMPLETION_INCOMPLETE);
                            switch ($completionstate) {
                                case COMPLETION_COMPLETE:
                            $statuskey = 'completed';
                            $statuslabel = get_string('completion-y', 'completion');
                            $statusicon = 'check-circle';
                                    $completedactivities++;
                            $activitycountedascomplete = true;
                                    break;
                                case COMPLETION_COMPLETE_PASS:
                            $statuskey = 'completed';
                            $statuslabel = get_string('completion-pass', 'completion');
                            $statusicon = 'check-circle';
                                    $completedactivities++;
                            $activitycountedascomplete = true;
                                    break;
                                case COMPLETION_COMPLETE_FAIL:
                            $statuskey = 'completed';
                            $statuslabel = get_string('completion-fail', 'completion');
                            $statusicon = 'check-circle';
                                    $completedactivities++;
                            $activitycountedascomplete = true;
                                    break;
                                default:
                            if (!empty($completionrecord->timestarted)) {
                                $statuskey = 'pending';
                                $statuslabel = get_string('inprogress', 'completion');
                                $statusicon = 'clock';
                            } else {
                                $statuskey = 'notstarted';
                                $statuslabel = get_string('notyetstarted', 'completion');
                                $statusicon = 'minus-circle';
                        }
                    }
                }

                $activity['statuskey'] = $statuskey;
                $activity['statuslabel'] = $statuslabel;
                $activity['statusicon'] = $statusicon;
                $activity['startedon'] = $startedts;
                $activity['completedon'] = $completedts;
                $activity['lastupdated'] = $lastupdated;
                // Fallback progress if no completion data yet.
                if ((!$completionrecord || $completionstate === null || $completionstate === COMPLETION_INCOMPLETE)
                    && isset($activityfallbackprogress[$cm->id])) {
                    $fallback = $activityfallbackprogress[$cm->id];
                    if ($fallback) {
                        if ($fallback['statuskey'] === 'completed' && !$activitycountedascomplete) {
                            $completedactivities++;
                            $activitycountedascomplete = true;
                        }
                        $statuskey = $fallback['statuskey'];
                        $statuslabel = $fallback['statuslabel'];
                        $statusicon = $fallback['statusicon'];
                        if (empty($startedts)) {
                            $startedts = $fallback['startedon'];
                        }
                        if (empty($completedts)) {
                            $completedts = $fallback['completedon'];
                        }
                        if (empty($lastupdated)) {
                            $lastupdated = $fallback['lastupdated'];
                        }
                    }
                }

                $activity['statuskey'] = $statuskey;
                $activity['statuslabel'] = $statuslabel;
                $activity['statusicon'] = $statusicon;
                $activity['startedon'] = $startedts;
                $activity['completedon'] = $completedts;
                $activity['lastupdated'] = $lastupdated;

                $activity['completionstate'] = $completionstate;
                $activity['completionlabel'] = $statuslabel;

                $activities[] = $activity;
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

        if (empty($activities) && trim(strip_tags($sectionsummary)) === '' && $sectionnum !== 0) {
            continue;
        }

        $sectionrecords[] = [
            'sectionnum' => $sectionnum,
            'name' => $sectionname,
            'summary' => $sectionsummary,
            'visible' => $sectionvisible,
            'activities' => $activities,
        ];
    }
}

// Get teachers safely
$teacherusers = [];
$teachernames = [];
try {
    $teacherusers = get_enrolled_users(
        $coursecontext,
        'moodle/course:update',
        0,
        'u.id, u.firstname, u.lastname',
        'u.lastname ASC'
    );
    foreach ($teacherusers as $teacheruser) {
        $teachernames[] = fullname($teacheruser);
    }
} catch (Exception $e) {
    error_log('Error getting teachers for course ' . $courseid . ': ' . $e->getMessage());
    $teachernames = [];
}

// Calculate completion ratio safely
$completionratio = 'N/A';
try {
    $completionratio = $totalactivities ? round(($completedactivities / $totalactivities) * 100) . '%' : 'N/A';
} catch (Exception $e) {
    $completionratio = 'N/A';
}

// Get assignments statistics - SAFE VERSION
$assignments_count = 0;
$assignments_submitted = 0;
$assignments_graded = 0;
try {
    $assignments_count = $DB->count_records('assign', ['course' => $courseid]);
    if ($assignments_count > 0) {
        $assignments_submitted = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT asub.assignment)
             FROM {assign_submission} asub
             JOIN {assign} a ON a.id = asub.assignment
             WHERE a.course = ? AND asub.userid = ? AND asub.status IN ('submitted', 'graded')",
            [$courseid, $selectedchildid]
        );
        $assignments_graded = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ag.assignment)
             FROM {assign_grades} ag
             JOIN {assign} a ON a.id = ag.assignment
             WHERE a.course = ? AND ag.userid = ? AND ag.grade IS NOT NULL",
            [$courseid, $selectedchildid]
        );
    }
} catch (Exception $e) {
    error_log('Error getting assignments stats: ' . $e->getMessage());
}

// Get quizzes statistics - SAFE VERSION
$quizzes_count = 0;
$quizzes_attempted = 0;
$quizzes_completed = 0;
try {
    $quizzes_count = $DB->count_records('quiz', ['course' => $courseid]);
    if ($quizzes_count > 0) {
        $quizzes_attempted = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.quiz)
             FROM {quiz_attempts} qa
             JOIN {quiz} q ON q.id = qa.quiz
             WHERE q.course = ? AND qa.userid = ?",
            [$courseid, $selectedchildid]
        );
        $quizzes_completed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.quiz)
             FROM {quiz_attempts} qa
             JOIN {quiz} q ON q.id = qa.quiz
             WHERE q.course = ? AND qa.userid = ? AND qa.state = 'finished'",
            [$courseid, $selectedchildid]
        );
    }
} catch (Exception $e) {
    error_log('Error getting quizzes stats: ' . $e->getMessage());
}

// Get course grade - SAFE VERSION
$course_grade = 'N/A';
$course_grade_percentage = 0;
try {
    if (class_exists('grade_item') && method_exists('grade_item', 'fetch_course_item')) {
        $grade_item = grade_item::fetch_course_item($courseid);
        if ($grade_item && isset($grade_item->id)) {
            if (class_exists('grade_grade') && method_exists('grade_grade', 'fetch')) {
                $grade = grade_grade::fetch(['userid' => $selectedchildid, 'itemid' => $grade_item->id]);
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

$PAGE->set_pagelayout('base');
echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');

// Get navbar HTML to display above logo
$navbarthis = $PAGE->navbar;
$navbarthis->ignore_active(false);
$navbarhtml = '';
if ($navbarthis && method_exists($OUTPUT, 'navbar')) {
    try {
        $navbarhtml = $OUTPUT->navbar();
    } catch (Exception $e) {
        $navbarhtml = '';
    }
}
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" referrerpolicy="no-referrer" />

<style>
/* Hide Moodle course navigation tabs (Course, Participants, etc.) - but keep navbar breadcrumb */
.nav-tabs,
.course-tabs,
.coursenav,
#course-tabs,
.course-header-nav,
[role="navigation"] .nav-tabs:not(.breadcrumb),
.navbar .nav-tabs:not(.breadcrumb),
.coursenav,
.course-header,
.course-navbar,
#course-header,
.course-header-nav,
[data-region="course-header"] {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

/* Hide Moodle navbar from header (default position) */
#page-navbar,
#page-header #page-navbar,
header #page-navbar,
.page-header #page-navbar,
.page-header .navbar {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    opacity: 0 !important;
}

/* Style custom navbar in content area (above logo) */
.parent-custom-navbar {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%) !important;
    padding: 10px 20px !important;
    margin-bottom: 16px !important;
    border-radius: 12px !important;
    border: 1px solid rgba(226, 232, 240, 0.8) !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
}

.parent-custom-navbar .breadcrumb,
.parent-custom-navbar .navbar,
.parent-custom-navbar .breadcrumb-item,
.parent-custom-navbar nav,
.parent-custom-navbar [role="navigation"] {
    display: flex !important;
    visibility: visible !important;
    height: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: visible !important;
    opacity: 1 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    align-items: center !important;
    flex-wrap: wrap !important;
}

.parent-custom-navbar .breadcrumb-item,
.parent-custom-navbar .breadcrumb-item a {
    font-size: 13px !important;
    padding: 4px 8px !important;
    line-height: 1.4 !important;
    color: #64748b !important;
    text-decoration: none !important;
}

.parent-custom-navbar .breadcrumb-item a:hover {
    color: #3b82f6 !important;
}

.parent-custom-navbar .breadcrumb-item + .breadcrumb-item::before {
    padding: 0 8px !important;
    font-size: 12px !important;
    color: #cbd5e1 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .parent-custom-navbar {
        padding: 8px 12px !important;
        margin-bottom: 12px !important;
    }
    
    .parent-custom-navbar .breadcrumb-item,
    .parent-custom-navbar .breadcrumb-item a {
        font-size: 11px !important;
        padding: 2px 4px !important;
    }
    
    .parent-custom-navbar .breadcrumb-item + .breadcrumb-item::before {
        padding: 0 6px !important;
        font-size: 10px !important;
    }
}

/* Hide Participants tab/link */
a[href*="participants"],
.nav-link:contains("Participants"),
.nav-item:contains("Participants"),
.coursenav a[href*="participants"] {
    display: none !important;
    visibility: hidden !important;
}

/* Hide Course tab/link */
a[href*="course/view"]:not([href*="parent_course_view"]),
.nav-link:contains("Course"),
.nav-item:contains("Course"),
.coursenav a[href*="course/view"]:not([href*="parent_course_view"]) {
    display: none !important;
    visibility: hidden !important;
}

/* Hide "Course Details" heading if it appears in page header */
#page-header h1,
#page-header h2,
.page-header-headings h1,
.page-header-headings h2 {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

.parent-main-content.parent-course-view {
    margin-left: 280px;
    padding: 36px 40px;
    background: #f8fafc;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    gap: 28px;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Comprehensive Responsive Design */
@media (max-width: 1024px) {
    .parent-main-content.parent-course-view {
        margin-left: 260px;
        width: calc(100% - 260px);
        padding: 28px 32px;
    }
}

@media (max-width: 768px) {
    .parent-main-content.parent-course-view {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 16px !important;
        gap: 20px !important;
    }
    
    .course-hero {
        padding: 24px 28px !important;
        border-radius: 16px !important;
    }
    
    .course-hero h1 {
        font-size: 24px !important;
    }
    
    /* Make all grids single column */
    [style*="grid-template-columns"],
    [style*="display: grid"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Stack flex containers */
    [style*="display: flex"] {
        flex-direction: column !important;
    }
    
    /* Make tables scrollable */
    table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust font sizes */
    h1, h2, h3 {
        font-size: 1.2em !important;
    }
}

@media (max-width: 480px) {
    .parent-main-content.parent-course-view {
        padding: 12px !important;
        gap: 16px !important;
    }
    
    .course-hero {
        padding: 20px !important;
    }
    
    .course-hero h1 {
        font-size: 20px !important;
    }
    
    body {
        font-size: 14px !important;
    }
}

.parent-content-wrapper {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}


.container,
.container-fluid,
#region-main,
#region-main-box {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
}

.parent-course-view .course-wrapper {
    max-width: 1280px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 28px;
}

.course-hero {
    background: radial-gradient(circle at top right, #3b82f6, #6366f1, #8b5cf6);
    padding: 40px 44px;
    border-radius: 24px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 16px 48px rgba(59, 130, 246, 0.25), 0 8px 24px rgba(0, 0, 0, 0.12);
    color: white;
}

.course-hero::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -40%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(50px);
}

.course-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -30%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.12) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(40px);
}

.course-hero h1 {
    margin: 0 0 16px 0;
    font-size: 36px;
    font-weight: 900;
    color: white;
    letter-spacing: -0.8px;
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 16px;
    line-height: 1.2;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.course-hero h1 i {
    font-size: 32px;
    background: rgba(255, 255, 255, 0.25);
    padding: 12px;
    border-radius: 14px;
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.course-hero p {
    margin: 0;
    font-size: 16px;
    color: rgba(255, 255, 255, 0.95);
    max-width: 750px;
    line-height: 1.7;
    position: relative;
    z-index: 1;
    font-weight: 400;
}

.course-hero-actions {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}

.child-select-form {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 255, 255, 0.9);
    padding: 8px 12px;
    border-radius: 12px;
    box-shadow: 0 14px 24px rgba(59, 130, 246, 0.18);
}

.child-select-form label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    font-weight: 700;
    color: #1d4ed8;
}

.child-select-form select {
    border: none;
    background: transparent;
    font-size: 14px;
    font-weight: 600;
    color: #0f172a;
    outline: none;
}

.back-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 12px;
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 16px 28px rgba(37, 99, 235, 0.2);
}

.overview-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
}

.overview-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 32px 28px;
    border: 1px solid rgba(226, 232, 240, 0.6);
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    min-height: 160px;
}

.overview-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: radial-gradient(circle at left, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.overview-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 28px rgba(59, 130, 246, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: rgba(148, 163, 184, 0.4);
}

.overview-card:hover::before {
    opacity: 1;
}

.overview-card-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 8px;
}

.overview-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    background: radial-gradient(circle at top right, #3b82f6, #2563eb);
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.25);
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.overview-card:hover .overview-card-icon {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35);
}

.overview-card-icon.blue { background: radial-gradient(circle at top right, #dbeafe, #bfdbfe, #93c5fd); color: #1e40af; }
.overview-card-icon.green { background: radial-gradient(circle at top right, #d1fae5, #a7f3d0, #6ee7b7); color: #065f46; }
.overview-card-icon.orange { background: radial-gradient(circle at top right, #fed7aa, #fdba74, #fb923c); color: #92400e; }
.overview-card-icon.purple { background: radial-gradient(circle at top right, #e9d5ff, #d8b4fe, #c084fc); color: #6b21a8; }
.overview-card-icon.red { background: radial-gradient(circle at top right, #fee2e2, #fecaca, #fca5a5); color: #991b1b; }
.overview-card-icon.pink { background: radial-gradient(circle at top right, #fce7f3, #fbcfe8, #f9a8d4); color: #9f1239; }

.overview-card h3 {
    margin: 0 0 8px 0;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #64748b;
    font-weight: 800;
    line-height: 1.4;
}

.overview-card .value {
    font-size: 36px;
    font-weight: 900;
    color: #1e293b;
    margin: 0;
    letter-spacing: -1.5px;
    line-height: 1.1;
}

.overview-card .sub-value {
    font-size: 14px;
    color: #475569;
    font-weight: 600;
    margin-top: 4px;
    line-height: 1.5;
}

.overview-card .progress-mini {
    margin-top: 16px;
    height: 8px;
    background: #f1f5f9;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
}

.overview-card .progress-mini-bar {
    height: 100%;
    background: radial-gradient(circle at left, #10b981, #059669);
    border-radius: 8px;
    transition: width 0.7s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px rgba(16, 185, 129, 0.3);
}

.course-summary-pane {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 20px;
}

.course-info-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid rgba(226, 232, 240, 0.6);
    padding: 32px 36px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.course-info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.08), 0 4px 12px rgba(0, 0, 0, 0.06);
    border-color: rgba(148, 163, 184, 0.4);
}

.course-info-card h2 {
    margin: 0 0 16px;
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.course-info-card h2 i {
    color: #3b82f6;
    font-size: 20px;
}

.course-summary {
    color: #475569;
    line-height: 1.7;
    font-size: 14px;
    margin-bottom: 20px;
}

.course-meta-list {
    margin-top: 16px;
    display: grid;
    gap: 10px;
    font-size: 13px;
    color: #475569;
}

.course-meta-list span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.course-preview {
    border-radius: 20px;
    overflow: hidden;
    background: #0f172a;
    min-height: 220px;
    background-size: cover;
    background-position: center;
    position: relative;
}

.course-preview::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.15), rgba(15, 23, 42, 0.65));
}

.course-preview .preview-label {
    position: absolute;
    bottom: 18px;
    left: 20px;
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
    z-index: 2;
}

.course-summary-pane {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 24px;
}

.course-info-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    padding: 22px 24px;
    box-shadow: 0 16px 28px rgba(15, 23, 42, 0.08);
}

.course-info-card h2 {
    margin: 0 0 12px;
    font-size: 20px;
    color: #0f172a;
}

.course-summary {
    color: #475569;
    line-height: 1.6;
    font-size: 14px;
}

.course-meta-list {
    margin-top: 16px;
    display: grid;
    gap: 10px;
    font-size: 13px;
    color: #475569;
}

.course-meta-list span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.course-preview {
    border-radius: 20px;
    overflow: hidden;
    background: #0f172a;
    min-height: 220px;
    background-size: cover;
    background-position: center;
    position: relative;
}

.course-preview::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.15), rgba(15, 23, 42, 0.65));
}

.course-preview .preview-label {
    position: absolute;
    bottom: 18px;
    left: 20px;
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
    z-index: 2;
}

.course-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: stretch;
}

.course-tools .filter-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    padding: 14px 16px;
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex: 1 1 240px;
}

.course-tools label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 700;
}

.course-search {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 12px;
    padding: 8px 12px;
}

.course-search input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 14px;
    flex: 1;
}

.course-filter-pills {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background: #f8fafc;
    color: #475569;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-pill.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
}

.course-tools .actions-row {
    display: inline-flex;
    gap: 8px;
    flex-wrap: wrap;
}

.course-tools .actions-row button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 10px;
    border: none;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    background: rgba(37, 99, 235, 0.08);
    color: #1d4ed8;
}

.course-tools .actions-row button.primary {
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
}

.course-tools .actions-row button:hover {
    transform: translateY(-1px);
}

.course-outline {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.section-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid rgba(226, 232, 240, 0.6);
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.section-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.08), 0 4px 12px rgba(0, 0, 0, 0.06);
    border-color: rgba(148, 163, 184, 0.4);
}

.section-card.collapsed .activity-list {
    display: none;
}

.section-card.collapsed .section-toggle i {
    transform: rotate(-90deg);
}

.section-toggle {
    transition: all 0.3s ease;
}

.section-toggle i {
    transition: transform 0.3s ease;
}

.section-header {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 28px 32px 24px;
    background: #f8fafc;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    cursor: pointer;
    transition: all 0.3s ease;
}

.section-header:hover {
    background: #f1f5f9;
}

.section-header h3 {
    margin: 0;
    font-size: 22px;
    font-weight: 900;
    color: #1e293b;
    letter-spacing: -0.4px;
    display: flex;
    align-items: center;
    gap: 14px;
    line-height: 1.3;
}

.section-header h3::before {
    content: '';
    width: 5px;
    height: 28px;
    background: radial-gradient(circle at top, #3b82f6, #8b5cf6);
    border-radius: 3px;
    flex-shrink: 0;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
}

.section-summary {
    font-size: 14px;
    color: #475569;
    line-height: 1.6;
}

.activity-list {
    list-style: none;
    margin: 0;
    padding: 24px 28px 28px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
}

.activity-item {
    display: flex;
    flex-direction: column;
    padding: 0;
    border-radius: 18px;
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.6);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    position: relative;
}

.activity-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: radial-gradient(circle at left, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.activity-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: rgba(148, 163, 184, 0.5);
}

.activity-item:hover::before {
    opacity: 1;
}

.activity-item[data-completion="complete"]::before {
    background: linear-gradient(90deg, #10b981, #059669);
    opacity: 1;
}

.activity-item[data-completion="incomplete"]::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
    opacity: 1;
}

.activity-item-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px 20px 16px;
}

.activity-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(37, 99, 235, 0.12));
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(59, 130, 246, 0.2);
    transition: all 0.3s ease;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.activity-item:hover .activity-icon {
    transform: scale(1.08) rotate(5deg);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(37, 99, 235, 0.18));
    border-color: rgba(59, 130, 246, 0.35);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.2);
}

.activity-icon img {
    width: 28px;
    height: 28px;
}

.activity-icon i {
    font-size: 24px;
    color: #3b82f6;
}

.activity-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

.activity-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}

.activity-meta h4 {
    margin: 0 0 10px 0;
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.3px;
    line-height: 1.4;
    word-wrap: break-word;
}

.activity-type-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(59, 130, 246, 0.12));
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    border: 1px solid rgba(37, 99, 235, 0.2);
    align-self: flex-start;
}

.activity-type-pill.assign { 
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(16, 185, 129, 0.15)); 
    color: #15803d; 
    border-color: rgba(34, 197, 94, 0.25);
}
.activity-type-pill.quiz { 
    background: linear-gradient(135deg, rgba(250, 204, 21, 0.2), rgba(245, 158, 11, 0.2)); 
    color: #a16207; 
    border-color: rgba(250, 204, 21, 0.3);
}
.activity-type-pill.forum { 
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(37, 99, 235, 0.15)); 
    color: #1d4ed8; 
    border-color: rgba(59, 130, 246, 0.25);
}
.activity-type-pill.resource { 
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(100, 116, 139, 0.18)); 
    color: #475569; 
    border-color: rgba(148, 163, 184, 0.25);
}

.activity-description {
    font-size: 13px;
    color: #64748b;
    line-height: 1.5;
    margin-top: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
}

.activity-meta span:not(.activity-description) {
    font-size: 12px;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 6px;
}

.activity-footer {
    padding: 16px 20px 20px;
    border-top: 1px solid rgba(226, 232, 240, 0.8);
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
}

.activity-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    flex: 1;
}

.activity-actions .completion-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #1d4ed8;
    border: 1px solid rgba(37, 99, 235, 0.2);
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
}

.activity-item[data-completion="complete"] .completion-pill {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.18), rgba(16, 185, 129, 0.18));
    color: #15803d;
    border-color: rgba(34, 197, 94, 0.3);
    box-shadow: 0 2px 4px rgba(34, 197, 94, 0.15);
}

.activity-item[data-completion="incomplete"] .completion-pill {
    background: linear-gradient(135deg, rgba(250, 204, 21, 0.2), rgba(245, 158, 11, 0.2));
    color: #a16207;
    border-color: rgba(250, 204, 21, 0.3);
    box-shadow: 0 2px 4px rgba(250, 204, 21, 0.15);
}

.activity-item.filtered-out {
    display: none;
}

.activity-actions a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(59, 130, 246, 0.12));
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid rgba(37, 99, 235, 0.2);
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
}

.activity-actions a:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(59, 130, 246, 0.2));
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(37, 99, 235, 0.2);
}

.activity-status-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    text-align: right;
    min-width: 220px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid rgba(148, 163, 184, 0.4);
    color: #475569;
    background: #ffffff;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
}

.status-pill.status-completed {
    border-color: rgba(34, 197, 94, 0.4);
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(16, 185, 129, 0.15));
    color: #15803d;
}

.status-pill.status-pending {
    border-color: rgba(249, 115, 22, 0.4);
    background: linear-gradient(135deg, rgba(251, 191, 36, 0.18), rgba(249, 115, 22, 0.15));
    color: #92400e;
}

.status-pill.status-notstarted {
    border-color: rgba(148, 163, 184, 0.4);
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.12), rgba(100, 116, 139, 0.08));
    color: #475569;
}

.status-pill.status-nottracked {
    border-color: rgba(148, 163, 184, 0.4);
    background: linear-gradient(135deg, rgba(226, 232, 240, 0.4), rgba(203, 213, 225, 0.3));
    color: #475569;
}

.status-timestamps {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: #64748b;
}

.status-timestamps span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.section-empty {
    padding: 0 24px 24px;
    font-size: 13px;
    color: #94a3b8;
}

@media (max-width: 1200px) {
    .course-summary-pane {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 992px) {
    .parent-main-content.parent-course-view {
        margin-left: 0;
        padding: 24px 18px 48px;
    }

    .activity-item {
        grid-template-columns: 48px minmax(0, 1fr);
        gap: 14px;
    }

    .activity-actions {
        width: 100%;
        flex-direction: row;
        justify-content: flex-start;
        gap: 12px;
    }

    .course-tools .filter-card {
        padding: 16px 18px;
    }
}

@media (max-width: 768px) {
    .course-hero {
        padding: 24px;
    }

    .course-hero h1 {
        font-size: 24px;
    }

    .course-hero-actions {
        flex-direction: column;
        align-items: flex-start;
    }

    .activity-item {
        grid-template-columns: 40px minmax(0, 1fr);
    }

    .activity-actions {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="parent-main-content parent-course-view">
    <!-- Custom Navigation Bar - Above Logo Container -->
    <?php if (!empty($navbarhtml)): ?>
    <div class="parent-custom-navbar">
        <?php echo $navbarhtml; ?>
    </div>
    <?php endif; ?>
    
    <div class="course-wrapper">
        
        <section class="course-hero">
            <h1>
                <i class="fas fa-book"></i>
                <?php echo s(format_string($course->fullname)); ?>
            </h1>
            <p><?php echo s(format_string($course->summary ? strip_tags($course->summary) : 'Course overview and learning materials')); ?></p>
            <div class="course-hero-actions" style="position: relative; z-index: 1; margin-top: 20px;">
                <form method="get" class="child-select-form">
                    <input type="hidden" name="courseid" value="<?php echo $courseid; ?>" />
                    <label for="child-selector">Child</label>
                    <select id="child-selector" name="child" onchange="this.form.submit()">
                        <?php foreach ($eligiblechildren as $childid => $childinfo) : ?>
                            <option value="<?php echo (int) $childid; ?>" <?php echo $childid === $selectedchildid ? 'selected' : ''; ?>>
                                <?php echo s($childinfo['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a class="back-link-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_my_courses.php?child=<?php echo $selectedchildid; ?>">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <span>Back to My Courses</span>
                </a>
            </div>
        </section>

        <!-- Enhanced Overview Statistics Dashboard -->
        <section class="overview-cards" aria-label="Course overview statistics">
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon blue">
                        <i class="fas fa-user"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Current Child</h3>
                        <span class="value" style="font-size: 20px;"><?php echo s($selectedchild['name']); ?></span>
                    </div>
                </div>
                <span class="sub-value"><?php echo !empty($selectedchild['class']) ? 'Grade ' . s($selectedchild['class']) : 'Grade not set'; ?></span>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon green">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Course Progress</h3>
                        <span class="value"><?php echo s($courseprogressdisplay); ?></span>
                    </div>
                </div>
                <div class="progress-mini">
                    <div class="progress-mini-bar" style="width: <?php echo is_numeric(str_replace('%', '', $courseprogressdisplay)) ? str_replace('%', '', $courseprogressdisplay) : 0; ?>%;"></div>
                </div>
                <span class="sub-value">Overall completion status</span>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon purple">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Activities</h3>
                        <span class="value"><?php echo number_format($totalactivities); ?></span>
                    </div>
                </div>
                <span class="sub-value"><?php echo number_format($completedactivities); ?> completed</span>
                <div class="progress-mini">
                    <div class="progress-mini-bar" style="width: <?php echo $completionratio !== 'N/A' ? str_replace('%', '', $completionratio) : 0; ?>%;"></div>
                </div>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon orange">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Assignments</h3>
                        <span class="value"><?php echo number_format($assignments_count); ?></span>
                    </div>
                </div>
                <span class="sub-value"><?php echo number_format($assignments_submitted); ?> submitted</span>
                <?php if ($assignments_count > 0): ?>
                <div class="progress-mini">
                    <div class="progress-mini-bar" style="width: <?php echo round(($assignments_submitted / $assignments_count) * 100); ?>%;"></div>
                </div>
                <?php endif; ?>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon pink">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Quizzes</h3>
                        <span class="value"><?php echo number_format($quizzes_count); ?></span>
                    </div>
                </div>
                <span class="sub-value"><?php echo number_format($quizzes_completed); ?> completed</span>
                <?php if ($quizzes_count > 0): ?>
                <div class="progress-mini">
                    <div class="progress-mini-bar" style="width: <?php echo round(($quizzes_completed / $quizzes_count) * 100); ?>%;"></div>
                </div>
                <?php endif; ?>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon red">
                        <i class="fas fa-star"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Course Grade</h3>
                        <span class="value" style="font-size: 24px;"><?php echo s($course_grade); ?></span>
                    </div>
                </div>
                <span class="sub-value"><?php echo $course_grade_percentage > 0 ? $course_grade_percentage . '%' : 'No grade yet'; ?></span>
                <?php if ($course_grade_percentage > 0): ?>
                <div class="progress-mini">
                    <div class="progress-mini-bar" style="width: <?php echo $course_grade_percentage; ?>%;"></div>
                </div>
                <?php endif; ?>
            </article>
            
            <article class="overview-card">
                <div class="overview-card-header">
                    <div class="overview-card-icon blue">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3>Instructors</h3>
                        <span class="value" style="font-size: 18px; line-height: 1.3;">
                            <?php echo !empty($teachernames) ? s(implode(', ', array_slice($teachernames, 0, 2))) . (count($teachernames) > 2 ? ' +' . (count($teachernames) - 2) : '') : 'N/A'; ?>
                        </span>
                    </div>
                </div>
                <span class="sub-value"><?php echo count($teachernames); ?> teacher<?php echo count($teachernames) != 1 ? 's' : ''; ?></span>
            </article>
        </section>

        <section class="course-summary-pane">
            <article class="course-info-card">
                <h2>
                    <i class="fas fa-info-circle"></i>
                    Course Summary
                </h2>
                <div class="course-summary">
                    <?php echo format_text($course->summary ? $course->summary : 'This course provides comprehensive learning materials and activities for your child.', $course->summaryformat, ['context' => $coursecontext]); ?>
                </div>
                <div class="course-meta-list">
                    <span><i class="fas fa-calendar-plus" aria-hidden="true"></i><strong>Start Date:</strong> <?php echo $course->startdate ? userdate($course->startdate, get_string('strftimedate', 'langconfig')) : get_string('notavailable', 'moodle'); ?></span>
                    <span><i class="fas fa-flag-checkered" aria-hidden="true"></i><strong>End Date:</strong> <?php echo $course->enddate ? userdate($course->enddate, get_string('strftimedate', 'langconfig')) : get_string('notavailable', 'moodle'); ?></span>
                    <span><i class="fas fa-layer-group" aria-hidden="true"></i><strong>Sections:</strong> <?php echo count($sectionrecords); ?> topics/lessons</span>
                    <span><i class="fas fa-clock" aria-hidden="true"></i><strong>Last Updated:</strong> <?php echo $course->timemodified ? userdate($course->timemodified, get_string('strftimedate', 'langconfig')) : 'N/A'; ?></span>
                </div>
            </article>
            <div class="course-preview" style="background-image: url('<?php echo s($courseimage); ?>');">
                <div class="preview-label">Student view preview</div>
            </div>
        </section>

        <!-- Quick Summary Banner -->
        <section class="quick-summary-banner" style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 24px 28px; border-radius: 16px; border: 1px solid rgba(59, 130, 246, 0.2); margin-bottom: 24px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="text-align: center;">
                    <div style="font-size: 28px; font-weight: 800; color: #3b82f6; margin-bottom: 4px;"><?php echo s($courseprogressdisplay); ?></div>
                    <div style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Course Progress</div>
                </div>
                <div style="text-align: center; border-left: 1px solid rgba(59, 130, 246, 0.2); border-right: 1px solid rgba(59, 130, 246, 0.2); padding: 0 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #10b981; margin-bottom: 4px;"><?php echo number_format($completedactivities); ?>/<?php echo number_format($totalactivities); ?></div>
                    <div style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Activities Done</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 28px; font-weight: 800; color: #f59e0b; margin-bottom: 4px;"><?php echo number_format($assignments_submitted); ?>/<?php echo number_format($assignments_count); ?></div>
                    <div style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Assignments</div>
                </div>
            </div>
        </section>

        <section class="course-tools" aria-label="Course tools">
            <div class="filter-card">
                <label for="activity-search">Search activities</label>
                <div class="course-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="activity-search" placeholder="Search by activity name..." autocomplete="off">
                </div>
            </div>
            <div class="filter-card">
                <label>Filter completion state</label>
                <div class="course-filter-pills" id="completionFilters">
                    <button type="button" class="filter-pill active" data-filter="all">
                        <i class="fas fa-list" aria-hidden="true"></i> All
                    </button>
                    <button type="button" class="filter-pill" data-filter="complete">
                        <i class="fas fa-check-circle" aria-hidden="true"></i> Completed
                    </button>
                    <button type="button" class="filter-pill" data-filter="incomplete">
                        <i class="fas fa-circle-notch" aria-hidden="true"></i> In Progress
                    </button>
                </div>
            </div>
            <div class="filter-card">
                <label>Filter by activity type</label>
                <div class="course-filter-pills" id="moduleFilters">
                    <button type="button" class="filter-pill active" data-mod="all">
                        <i class="fas fa-border-all" aria-hidden="true"></i> All Types
                    </button>
                    <button type="button" class="filter-pill" data-mod="assign">
                        <i class="fas fa-file-signature" aria-hidden="true"></i> Assignments
                    </button>
                    <button type="button" class="filter-pill" data-mod="quiz">
                        <i class="fas fa-question-circle" aria-hidden="true"></i> Quizzes
                    </button>
                    <button type="button" class="filter-pill" data-mod="forum">
                        <i class="fas fa-comments" aria-hidden="true"></i> Forums
                    </button>
                    <button type="button" class="filter-pill" data-mod="resource">
                        <i class="fas fa-file-alt" aria-hidden="true"></i> Resources
                    </button>
                    <button type="button" class="filter-pill" data-mod="url">
                        <i class="fas fa-link" aria-hidden="true"></i> URLs
                    </button>
                    <button type="button" class="filter-pill" data-mod="page">
                        <i class="fas fa-file" aria-hidden="true"></i> Pages
                    </button>
                    <button type="button" class="filter-pill" data-mod="folder">
                        <i class="fas fa-folder" aria-hidden="true"></i> Folders
                    </button>
                    <button type="button" class="filter-pill" data-mod="book">
                        <i class="fas fa-book" aria-hidden="true"></i> Books
                    </button>
                    <button type="button" class="filter-pill" data-mod="lesson">
                        <i class="fas fa-graduation-cap" aria-hidden="true"></i> Lessons
                    </button>
                </div>
            </div>
            <div class="filter-card">
                <label>Quick actions</label>
                <div class="actions-row">
                    <button type="button" class="primary" id="expandAllSections"><i class="fas fa-plus-square" aria-hidden="true"></i> Expand All</button>
                    <button type="button" id="collapseAllSections"><i class="fas fa-minus-square" aria-hidden="true"></i> Collapse All</button>
                    <button type="button" id="resetFilters"><i class="fas fa-rotate" aria-hidden="true"></i> Reset</button>
                </div>
            </div>
        </section>

        <section class="course-outline" aria-label="Course outline">
            <?php foreach ($sectionrecords as $section) : ?>
                <article class="section-card" id="section-<?php echo (int) $section['sectionnum']; ?>">
                    <div class="section-header">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                            <div style="flex: 1;">
                                <h3><?php echo s($section['name']); ?></h3>
                                <?php if (!empty($section['summary'])) : ?>
                                    <div class="section-summary"><?php echo $section['summary']; ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($section['activities'])) : ?>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(37, 99, 235, 0.12)); color: #1d4ed8; padding: 6px 14px; border-radius: 12px; font-size: 13px; font-weight: 700; border: 1px solid rgba(59, 130, 246, 0.2);">
                                        <i class="fas fa-tasks" style="margin-right: 6px;"></i>
                                        <?php echo count($section['activities']); ?> <?php echo count($section['activities']) == 1 ? 'Activity' : 'Activities'; ?>
                                    </span>
                                    <button class="section-toggle" type="button" style="background: transparent; border: none; color: #64748b; font-size: 18px; cursor: pointer; padding: 4px; transition: all 0.3s ease;" aria-label="Toggle section">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($section['activities'])) : ?>
                        <ul class="activity-list">
                            <?php foreach ($section['activities'] as $activity) : ?>
                                <?php
                                    $filterstate = 'none';
                                    if ($activity['statuskey'] === 'completed') {
                                        $filterstate = 'complete';
                                    } elseif (in_array($activity['statuskey'], ['pending', 'notstarted'])) {
                                        $filterstate = 'incomplete';
                                    }
                                ?>
                                <li class="activity-item"
                                    data-name="<?php echo s(strtolower($activity['name'])); ?>"
                                    data-completion="<?php echo s($filterstate); ?>"
                                    data-status="<?php echo s($activity['statuskey']); ?>"
                                    data-modname="<?php echo s($activity['modnamekey']); ?>">
                                    <div class="activity-item-header">
                                        <span class="activity-icon">
                                            <?php if (!empty($activity['icon'])) : ?>
                                                <img src="<?php echo $activity['icon']; ?>" alt="" />
                                            <?php else : ?>
                                                <i class="fas fa-shapes" aria-hidden="true"></i>
                                            <?php endif; ?>
                                        </span>
                                        <div class="activity-body">
                                            <div class="activity-meta">
                                                <h4><?php echo s($activity['name']); ?></h4>
                                                <span class="activity-type-pill <?php echo s($activity['modnamekey']); ?>">
                                                    <i class="fas fa-tag" aria-hidden="true"></i> <?php echo s($activity['modname']); ?>
                                                </span>
                                                <?php if (!empty($activity['description'])) : ?>
                                                    <span class="activity-description"><?php echo s($activity['description']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($activity['availability'])) : ?>
                                                    <span><i class="fas fa-info-circle" aria-hidden="true"></i> <?php echo s($activity['availability']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!$activity['visible']) : ?>
                                                    <span><i class="fas fa-eye-slash" aria-hidden="true"></i> Hidden Activity</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="activity-footer">
                        <div class="activity-actions">
                            <a href="<?php echo (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                'cmid' => $activity['id'],
                                'child' => $selectedchildid,
                                'courseid' => $courseid
                            ]))->out(); ?>">
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                                View Details
                                            </a>
                                        </div>
                                        <div class="activity-status-meta">
                                            <span class="status-pill status-<?php echo s($activity['statuskey']); ?>">
                                                <i class="fas fa-<?php echo s($activity['statusicon']); ?>" aria-hidden="true"></i>
                                                <?php echo s($activity['statuslabel']); ?>
                                            </span>
                                            <div class="status-timestamps">
                                                <span>
                                                    <i class="fas fa-play" aria-hidden="true"></i>
                                                    <?php echo $activity['startedon'] ? userdate($activity['startedon'], get_string('strftimedatetimeshort', 'langconfig')) : get_string('notyetstarted', 'completion'); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-flag-checkered" aria-hidden="true"></i>
                                                    <?php echo $activity['completedon'] ? userdate($activity['completedon'], get_string('strftimedatetimeshort', 'langconfig')) : get_string('completion-n', 'completion'); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <div class="section-empty" style="padding: 24px 28px; text-align: center; color: #94a3b8; font-size: 14px;">
                            <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <div>No visible activities in this section for <?php echo s($selectedchild['name']); ?>.</div>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sectionCards = Array.from(document.querySelectorAll('.section-card'));
    const activityItems = Array.from(document.querySelectorAll('.activity-item'));
    const searchInput = document.getElementById('activity-search');
    const filterButtons = Array.from(document.querySelectorAll('#completionFilters .filter-pill'));
    const moduleButtons = Array.from(document.querySelectorAll('#moduleFilters .filter-pill'));
    const expandBtn = document.getElementById('expandAllSections');
    const collapseBtn = document.getElementById('collapseAllSections');
    const resetBtn = document.getElementById('resetFilters');

    const applyFilters = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const activeCompletion = filterButtons.find(btn => btn.classList.contains('active'))?.dataset.filter || 'all';
        const activeModule = moduleButtons.find(btn => btn.classList.contains('active'))?.dataset.mod || 'all';

        activityItems.forEach(item => {
            const name = item.dataset.name || '';
            const completion = item.dataset.completion || 'none';
            const modname = item.dataset.modname || 'resource';
            const matchesQuery = !query || name.includes(query);
            const matchesCompletion = activeCompletion === 'all' || completion === activeCompletion;
            const matchesModule = activeModule === 'all' || modname === activeModule;

            item.classList.toggle('filtered-out', !(matchesQuery && matchesCompletion && matchesModule));
        });

        sectionCards.forEach(card => {
            const visibleActivities = card.querySelectorAll('.activity-item:not(.filtered-out)');
            card.classList.toggle('collapsed', visibleActivities.length === 0);
        });
    };

    searchInput?.addEventListener('input', applyFilters);

    filterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            filterButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyFilters();
        });
    });

    moduleButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            moduleButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyFilters();
        });
    });

    // Toggle sections on header click
    sectionCards.forEach(card => {
        const header = card.querySelector('.section-header');
        const toggle = card.querySelector('.section-toggle');
        if (header) {
            header.addEventListener('click', (e) => {
                if (e.target !== toggle && !toggle?.contains(e.target)) {
                    card.classList.toggle('collapsed');
                }
            });
        }
        if (toggle) {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                card.classList.toggle('collapsed');
            });
        }
    });

    expandBtn?.addEventListener('click', () => {
        sectionCards.forEach(card => card.classList.remove('collapsed'));
    });

    collapseBtn?.addEventListener('click', () => {
        sectionCards.forEach(card => card.classList.add('collapsed'));
    });

    resetBtn?.addEventListener('click', () => {
        searchInput.value = '';
        filterButtons.forEach((b, idx) => b.classList.toggle('active', idx === 0));
        moduleButtons.forEach((b, idx) => b.classList.toggle('active', idx === 0));
        sectionCards.forEach(card => card.classList.remove('collapsed'));
        applyFilters();
    });

    applyFilters();
});
</script>

<style>
/* Hide Moodle course navigation tabs (Course, Participants, etc.) - but keep navbar breadcrumb */
.nav-tabs,
.course-tabs,
.coursenav,
#course-tabs,
.course-header-nav,
[role="navigation"] .nav-tabs:not(.breadcrumb),
.navbar .nav-tabs:not(.breadcrumb),
.coursenav,
.course-header,
.course-navbar,
#course-header,
.course-header-nav,
[data-region="course-header"] {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

/* Hide Moodle navbar from header (default position) */
#page-navbar,
#page-header #page-navbar,
header #page-navbar,
.page-header #page-navbar,
.page-header .navbar {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    opacity: 0 !important;
}

/* Style custom navbar in content area (above logo) */
.parent-custom-navbar {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%) !important;
    padding: 10px 20px !important;
    margin-bottom: 16px !important;
    border-radius: 12px !important;
    border: 1px solid rgba(226, 232, 240, 0.8) !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
}

.parent-custom-navbar .breadcrumb,
.parent-custom-navbar .navbar,
.parent-custom-navbar .breadcrumb-item,
.parent-custom-navbar nav,
.parent-custom-navbar [role="navigation"] {
    display: flex !important;
    visibility: visible !important;
    height: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: visible !important;
    opacity: 1 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    align-items: center !important;
    flex-wrap: wrap !important;
}

.parent-custom-navbar .breadcrumb-item,
.parent-custom-navbar .breadcrumb-item a {
    font-size: 13px !important;
    padding: 4px 8px !important;
    line-height: 1.4 !important;
    color: #64748b !important;
    text-decoration: none !important;
}

.parent-custom-navbar .breadcrumb-item a:hover {
    color: #3b82f6 !important;
}

.parent-custom-navbar .breadcrumb-item + .breadcrumb-item::before {
    padding: 0 8px !important;
    font-size: 12px !important;
    color: #cbd5e1 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .parent-custom-navbar {
        padding: 8px 12px !important;
        margin-bottom: 12px !important;
    }
    
    .parent-custom-navbar .breadcrumb-item,
    .parent-custom-navbar .breadcrumb-item a {
        font-size: 11px !important;
        padding: 2px 4px !important;
    }
    
    .parent-custom-navbar .breadcrumb-item + .breadcrumb-item::before {
        padding: 0 6px !important;
        font-size: 10px !important;
    }
}

/* Hide Participants tab/link */
a[href*="participants"],
.nav-link:contains("Participants"),
.nav-item:contains("Participants"),
.coursenav a[href*="participants"] {
    display: none !important;
    visibility: hidden !important;
}

/* Hide Course tab/link */
a[href*="course/view"]:not([href*="parent_course_view"]),
.nav-link:contains("Course"),
.nav-item:contains("Course"),
.coursenav a[href*="course/view"]:not([href*="parent_course_view"]) {
    display: none !important;
    visibility: hidden !important;
}

/* Hide "Course Details" heading if it appears in page header */
#page-header h1,
#page-header h2,
.page-header-headings h1,
.page-header-headings h2 {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}

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




