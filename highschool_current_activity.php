<?php
/**
 * High School Current Activity Page
 * 
 * This file handles the current activity page for Grades 8-12 students
 * showing activities organized by tabs: Quiz, Assignment, Upcoming Activity, General
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/highschool_current_activity.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Actions');
$PAGE->add_body_class('custom-dashboard-page has-student-sidebar highschool-current-activity-page');

// Get active tab from URL parameter
$active_tab = optional_param('tab', 'quiz', PARAM_ALPHA);

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
    // If there's an error, set empty array and continue
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
} catch (Exception $e) {
    error_log("HIGHSCHOOL_CURRENT_ACTIVITY: Error getting enrolled courses: " . $e->getMessage());
    $courses = [];
}

// Initialize activity data arrays
$quiz_activities = [];
$assignment_activities = [];
$upcoming_activities = [];
$general_activities = [];
$completed_activities = [];

// Process activities from enrolled courses
foreach ($courses as $course) {
    try {
        $modinfo = get_fast_modinfo($course);
        $coursecontext = context_course::instance($course->id);
        $completion = new completion_info($course);
        
        // Get all course modules
        $cms = $modinfo->get_cms();
        
        foreach ($cms as $cm) {
            // Skip if not visible or being deleted
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }
            
            // Skip labels
            if ($cm->modname === 'label') {
                continue;
            }
            
            // Get module URL
            $module_url = $cm->url;
            if (!$module_url) {
                $module_url = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
            }
            
            // Check completion status
            $completion_status = 'not_started';
            $completion_percentage = 0;
            $completion_time = 0;
            $is_completed = false;
            
            // Helper function to add completed activity
            $add_completed_activity = function($time = null) use (&$completed_activities, &$is_completed, $cm, $course, $module_url, $modinfo) {
                $activity_type_map = [
                    'quiz' => 'Quiz',
                    'assign' => 'Assignment',
                    'lesson' => 'Lesson',
                    'forum' => 'Forum',
                    'scorm' => 'SCORM',
                    'resource' => 'Resource',
                    'page' => 'Page',
                    'url' => 'URL',
                    'folder' => 'Folder',
                    'book' => 'Book',
                    'glossary' => 'Glossary',
                    'wiki' => 'Wiki',
                    'choice' => 'Choice',
                    'feedback' => 'Feedback',
                    'survey' => 'Survey',
                    'workshop' => 'Workshop',
                    'hvp' => 'H5P',
                    'lti' => 'LTI',
                    'videofile' => 'Video',
                    'videotime' => 'Video'
                ];
                
                $activity_type = isset($activity_type_map[$cm->modname]) ? 
                    $activity_type_map[$cm->modname] : ucfirst($cm->modname);
                
                // Get section name if available
                $section_name = '';
                if (isset($modinfo->sections[$cm->sectionnum])) {
                    $section = $modinfo->get_section_info($cm->sectionnum);
                    if ($section && $section->name) {
                        $section_name = $section->name;
                    }
                }
                
                // Format completion time
                $completion_time_val = $time ?: time();
                $completion_time_formatted = userdate($completion_time_val, '%d %B %Y, %I:%M %p');
                
                // Check if already added (avoid duplicates)
                foreach ($completed_activities as $existing) {
                    if ($existing['id'] == $cm->id && $existing['course_id'] == $course->id) {
                        return; // Already added
                    }
                }
                
                $completed_activities[] = [
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'activity_type' => $activity_type,
                    'modname' => $cm->modname,
                    'course_name' => $course->fullname,
                    'course_id' => $course->id,
                    'section_name' => $section_name,
                    'url' => $module_url->out(false),
                    'completion_time' => $completion_time_val,
                    'completion_time_formatted' => $completion_time_formatted,
                    'sort_time' => $completion_time_val,
                    'is_quiz' => ($cm->modname === 'quiz'),
                    'is_assign' => ($cm->modname === 'assign'),
                    'is_lesson' => ($cm->modname === 'lesson'),
                    'is_video' => ($cm->modname === 'videofile' || $cm->modname === 'videotime'),
                    'is_course' => false
                ];
                $is_completed = true;
            };
            
            // Method 1: Check via completion tracking (if enabled)
            if ($completion->is_enabled($cm)) {
                try {
                    $completiondata = $completion->get_data($cm, false, $USER->id);
                    if ($completiondata->completionstate == COMPLETION_COMPLETE || 
                        $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                        $completion_status = 'completed';
                        $completion_percentage = 100;
                        $completion_time = $completiondata->timemodified ?: time();
                        $add_completed_activity($completion_time);
                    } elseif ($completiondata->completionstate == COMPLETION_INCOMPLETE ||
                              $completiondata->completionstate == COMPLETION_COMPLETE_FAIL) {
                        $completion_status = 'in_progress';
                        $completion_percentage = 50;
                    }
                } catch (Exception $e) {
                    // Continue if completion check fails
                }
            }
            
            // Method 2: Check via activity-specific completion (even if completion tracking not enabled)
            if (!$is_completed) {
                try {
                    switch ($cm->modname) {
                        case 'quiz':
                            // Check if quiz has finished attempts
                            $quiz_attempts = $DB->get_records('quiz_attempts', [
                                'quiz' => $cm->instance,
                                'userid' => $USER->id,
                                'state' => 'finished'
                            ], 'timefinish DESC', '*', 0, 1);
                            if (!empty($quiz_attempts)) {
                                $attempt = reset($quiz_attempts);
                                $completion_status = 'completed';
                                $completion_percentage = 100;
                                $completion_time = $attempt->timefinish;
                                $add_completed_activity($completion_time);
                            }
                            break;
                            
                        case 'assign':
                            // Check if assignment has been submitted
                            $submission = $DB->get_record('assign_submission', [
                                'assignment' => $cm->instance,
                                'userid' => $USER->id,
                                'latest' => 1,
                                'status' => 'submitted'
                            ]);
                            if ($submission) {
                                $completion_status = 'completed';
                                $completion_percentage = 100;
                                $completion_time = $submission->timemodified;
                                $add_completed_activity($completion_time);
                            }
                            break;
                            
                        case 'lesson':
                            // Check if lesson is completed
                            $lesson_attempts = $DB->get_records('lesson_attempts', [
                                'lessonid' => $cm->instance,
                                'userid' => $USER->id
                            ], 'timeseen DESC', '*', 0, 1);
                            if (!empty($lesson_attempts)) {
                                $attempt = reset($lesson_attempts);
                                $completion_status = 'completed';
                                $completion_percentage = 100;
                                $completion_time = $attempt->timeseen;
                                $add_completed_activity($completion_time);
                            }
                            break;
                            
                        case 'scorm':
                            // Check if SCORM is completed
                            $scorm_attempt = $DB->get_record_sql(
                                "SELECT MAX(sa.timemodified) as timemodified
                                 FROM {scorm_scoes_track} st
                                 JOIN {scorm_attempt} sa ON sa.id = st.attemptid
                                 WHERE sa.scormid = ? AND sa.userid = ?
                                   AND st.element IN ('cmi.core.lesson_status', 'cmi.completion_status')
                                   AND st.value IN ('completed', 'passed')",
                                [$cm->instance, $USER->id]
                            );
                            if ($scorm_attempt && $scorm_attempt->timemodified) {
                                $completion_status = 'completed';
                                $completion_percentage = 100;
                                $completion_time = $scorm_attempt->timemodified;
                                $add_completed_activity($completion_time);
                            }
                            break;
                    }
                } catch (Exception $e) {
                    // Continue if activity-specific check fails
                }
            }
            
            // Get course image
            $courseimage = '';
            $fs = get_file_storage();
            $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'filename', false);
            if ($files) {
                $file = reset($files);
                $courseimage = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename())->out();
            }
            
            $activity_data = [
                'id' => $cm->id,
                'name' => $cm->name,
                'description' => $cm->get_formatted_content(['overflowdiv' => true]),
                'courseid' => $course->id,
                'coursename' => $course->fullname,
                'module_type' => $cm->modname,
                'module_name' => get_string('modulename', 'mod_' . $cm->modname),
                'url' => $module_url->out(false),
                'course_image' => $courseimage ?: '',
                'completion_status' => $completion_status,
                'completion_percentage' => $completion_percentage,
                'available' => $cm->available,
                'visible' => $cm->visible
            ];
            
            // Categorize by module type
            switch ($cm->modname) {
                case 'quiz':
                    // Get detailed quiz information
                    try {
                        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', IGNORE_MISSING);
                        if ($quiz) {
                            // Get quiz attempts for this user
                            $attempts = $DB->get_records('quiz_attempts', [
                                'quiz' => $quiz->id,
                                'userid' => $USER->id
                            ], 'attempt DESC');
                            
                            $attempt_count = count($attempts);
                            $best_score = null;
                            $status = 'Not started';
                            
                            // Calculate best score
                            if (!empty($attempts)) {
                                foreach ($attempts as $attempt) {
                                    if ($attempt->state == 'finished' && $attempt->sumgrades !== null) {
                                        if ($quiz->sumgrades > 0) {
                                            $percentage = round(($attempt->sumgrades / $quiz->sumgrades) * 100, 1);
                                            if ($best_score === null || $percentage > $best_score) {
                                                $best_score = $percentage;
                                            }
                                        }
                                    }
                                }
                                
                                // Determine status
                                $last_attempt = reset($attempts);
                                if ($last_attempt->state == 'finished') {
                                    $status = 'Completed';
                                    $status_class = 'completed';
                                } elseif ($last_attempt->state == 'inprogress') {
                                    $status = 'In Progress';
                                    $status_class = 'in-progress';
                                } else {
                                    $status = 'Started';
                                    $status_class = 'started';
                                }
                            }
                            
                            // Determine status based on time open/close and attempts
                            $now = time();
                            if ($quiz->timeopen && $now < $quiz->timeopen) {
                                $status = 'Not yet available';
                                $status_class = 'not-yet-available';
                            } elseif ($quiz->timeclose && $now > $quiz->timeclose) {
                                if ($attempt_count == 0) {
                                    $status = 'Closed';
                                    $status_class = 'closed';
                                } else {
                                    $status = 'Completed';
                                    $status_class = 'completed';
                                }
                            } elseif ($attempt_count == 0 && !isset($status_class)) {
                                $status = 'Not started';
                                $status_class = 'not-started';
                            } elseif (!isset($status_class)) {
                                $status = 'Not started';
                                $status_class = 'not-started';
                            }
                            
                            $activity_data['status_class'] = $status_class;
                            $activity_data['is_closed'] = ($status_class === 'closed' || strtolower($status) === 'closed');
                            
                            // Format open and close times
                            $timeopen_formatted = '';
                            $timeclose_formatted = '';
                            
                            if ($quiz->timeopen) {
                                $timeopen_formatted = userdate($quiz->timeopen, '%d %B %Y, %I:%M %p');
                            }
                            
                            if ($quiz->timeclose) {
                                $timeclose_formatted = userdate($quiz->timeclose, '%d %B %Y, %I:%M %p');
                            }
                            
                            // Add detailed quiz information
                            $activity_data['quiz_id'] = $quiz->id;
                            $activity_data['timeopen'] = $quiz->timeopen;
                            $activity_data['timeclose'] = $quiz->timeclose;
                            $activity_data['timeopen_formatted'] = $timeopen_formatted;
                            $activity_data['timeclose_formatted'] = $timeclose_formatted;
                            $activity_data['attempt_count'] = $attempt_count;
                            $activity_data['best_score'] = $best_score;
                            $activity_data['status'] = $status;
                            $activity_data['max_grade'] = $quiz->grade;
                        }
                    } catch (Exception $e) {
                        error_log("HIGHSCHOOL_CURRENT_ACTIVITY: Error getting quiz details: " . $e->getMessage());
                    }
                    $quiz_activities[] = $activity_data;
                    break;
                case 'assign':
                    // Get detailed assignment information
                    try {
                        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', IGNORE_MISSING);
                        if ($assign) {
                            // Get submission status
                            $submission = $DB->get_record('assign_submission', [
                                'assignment' => $assign->id,
                                'userid' => $USER->id,
                                'latest' => 1
                            ]);
                            
                            $submission_status = 'Not submitted';
                            $submission_status_class = 'not-submitted';
                            $submission_time_formatted = '';
                            if ($submission) {
                                $submission_status = ucfirst($submission->status);
                                if ($submission->status == 'submitted' || $submission->status == 'graded') {
                                    $submission_status_class = 'submitted';
                                    if ($submission->timemodified) {
                                        $submission_time_formatted = userdate($submission->timemodified, '%d %B %Y, %I:%M %p');
                                    }
                                } elseif ($submission->status == 'draft') {
                                    $submission_status_class = 'draft';
                                }
                            }
                            
                            // Check if overdue
                            $now = time();
                            if ($assign->duedate > 0 && $assign->duedate < $now && $submission_status != 'Submitted' && $submission_status != 'Graded') {
                                $submission_status = 'Overdue';
                                $submission_status_class = 'overdue';
                            }
                            
                            // Get grading status
                            $grade = $DB->get_record('assign_grades', [
                                'assignment' => $assign->id,
                                'userid' => $USER->id
                            ], '*', IGNORE_MISSING);
                            
                            $grading_status = 'Not graded';
                            $grading_status_class = 'not-graded';
                            $score_display = null;
                            if ($grade && $grade->grade !== null) {
                                $grading_status = 'Graded';
                                $grading_status_class = 'graded';
                                if ($assign->grade > 0) {
                                    $score_percentage = round(($grade->grade / $assign->grade) * 100, 1);
                                    $score_display = $score_percentage . '%';
                                } else {
                                    $score_display = $grade->grade;
                                }
                            }
                            
                            // Format open and close times
                            $timeopen_formatted = '';
                            $timeclose_formatted = '';
                            
                            if ($assign->allowsubmissionsfromdate) {
                                $timeopen_formatted = userdate($assign->allowsubmissionsfromdate, '%d %B %Y, %I:%M %p');
                            }
                            
                            if ($assign->duedate) {
                                $timeclose_formatted = userdate($assign->duedate, '%d %B %Y, %I:%M %p');
                            }
                            
                            // Check if assignment is closed
                            $is_closed = false;
                            if ($assign->cutoffdate > 0 && $assign->cutoffdate < $now) {
                                if ($submission_status != 'Submitted' && $submission_status != 'Graded') {
                                    $is_closed = true;
                                }
                            }
                            
                            // Add detailed assignment information
                            $activity_data['assignment_id'] = $assign->id;
                            $activity_data['timeopen'] = $assign->allowsubmissionsfromdate;
                            $activity_data['timeclose'] = $assign->duedate;
                            $activity_data['timeopen_formatted'] = $timeopen_formatted;
                            $activity_data['timeclose_formatted'] = $timeclose_formatted;
                            $activity_data['submission_status'] = $submission_status;
                            $activity_data['submission_status_class'] = $submission_status_class;
                            $activity_data['submission_time_formatted'] = $submission_time_formatted;
                            $activity_data['grading_status'] = $grading_status;
                            $activity_data['grading_status_class'] = $grading_status_class;
                            $activity_data['score_display'] = $score_display;
                            $activity_data['is_closed'] = $is_closed;
                            $activity_data['grade_value'] = $grade && $grade->grade !== null ? $grade->grade : null;
                            $activity_data['max_grade'] = $assign->grade;
                        }
                    } catch (Exception $e) {
                        error_log("HIGHSCHOOL_CURRENT_ACTIVITY: Error getting assignment details: " . $e->getMessage());
                    }
                    $assignment_activities[] = $activity_data;
                    break;
                case 'lesson':
                    // Lessons go to upcoming if not completed
                    if ($completion_status !== 'completed') {
                        $upcoming_activities[] = $activity_data;
                    }
                    break;
                default:
                    // Other activities go to upcoming if not completed
                    if ($completion_status !== 'completed') {
                        $upcoming_activities[] = $activity_data;
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("HIGHSCHOOL_CURRENT_ACTIVITY: Error processing course {$course->id}: " . $e->getMessage());
        continue;
    }
}

// Also check for completed courses
foreach ($courses as $course) {
    try {
        $completion = new completion_info($course);
        if ($completion->is_enabled()) {
            $course_completion = $completion->is_course_complete($USER->id);
            if ($course_completion) {
                $course_completion_record = $DB->get_record('course_completions', [
                    'userid' => $USER->id,
                    'course' => $course->id
                ]);
                
                if ($course_completion_record && $course_completion_record->timecompleted) {
                    $completed_activities[] = [
                        'id' => 'course_' . $course->id,
                        'name' => $course->fullname,
                        'activity_type' => 'Course',
                        'modname' => 'course',
                        'course_name' => $course->fullname,
                        'course_id' => $course->id,
                        'section_name' => '',
                        'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
                        'completion_time' => $course_completion_record->timecompleted,
                        'completion_time_formatted' => userdate($course_completion_record->timecompleted, '%d %B %Y, %I:%M %p'),
                        'sort_time' => $course_completion_record->timecompleted,
                        'is_quiz' => false,
                        'is_assign' => false,
                        'is_lesson' => false,
                        'is_video' => false,
                        'is_course' => true
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("HIGHSCHOOL_CURRENT_ACTIVITY: Error checking course completion for course {$course->id}: " . $e->getMessage());
        continue;
    }
}

// Sort quizzes by time (most recent first)
if (!empty($quiz_activities)) {
    usort($quiz_activities, function($a, $b) {
        $time_a = isset($a['timeopen']) && $a['timeopen'] > 0 ? $a['timeopen'] : 
                  (isset($a['timeclose']) && $a['timeclose'] > 0 ? $a['timeclose'] : 0);
        $time_b = isset($b['timeopen']) && $b['timeopen'] > 0 ? $b['timeopen'] : 
                  (isset($b['timeclose']) && $b['timeclose'] > 0 ? $b['timeclose'] : 0);
        return $time_b - $time_a;
    });
}

// Sort assignments by time (most recent first)
if (!empty($assignment_activities)) {
    usort($assignment_activities, function($a, $b) {
        $time_a = isset($a['timeopen']) && $a['timeopen'] > 0 ? $a['timeopen'] : 
                  (isset($a['timeclose']) && $a['timeclose'] > 0 ? $a['timeclose'] : 0);
        $time_b = isset($b['timeopen']) && $b['timeopen'] > 0 ? $b['timeopen'] : 
                  (isset($b['timeclose']) && $b['timeclose'] > 0 ? $b['timeclose'] : 0);
        return $time_b - $time_a;
    });
}

// Sort completed activities by completion time (most recent first)
if (!empty($completed_activities)) {
    usort($completed_activities, function($a, $b) {
        $time_a = isset($a['sort_time']) ? $a['sort_time'] : 0;
        $time_b = isset($b['sort_time']) ? $b['sort_time'] : 0;
        return $time_b - $time_a;
    });
}

// Calculate statistics
$total_quiz = count($quiz_activities);
$total_assignment = count($assignment_activities);
$total_upcoming = count($upcoming_activities);
$total_general = count($completed_activities);
$total_activities = $total_quiz + $total_assignment + $total_upcoming + $total_general;

// Prepare template context
$templatecontext = [
    'custom_highschool_current_activity' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_elementary_grade' => ($dashboardtype === 'elementary'),
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'is_highschool_grade' => ($dashboardtype === 'highschool'),
    'page_title' => 'My Actions',
    
    // Tab data
    'active_tab' => $active_tab,
    'is_tab_quiz' => ($active_tab === 'quiz'),
    'is_tab_assignment' => ($active_tab === 'assignment'),
    'is_tab_upcoming' => ($active_tab === 'upcoming'),
    'is_tab_general' => ($active_tab === 'general'),
    'quiz_activities' => $quiz_activities,
    'assignment_activities' => $assignment_activities,
    'upcoming_activities' => $upcoming_activities,
    'general_activities' => $general_activities,
    'completed_activities' => $completed_activities,
    
    // Statistics
    'total_quiz' => $total_quiz,
    'total_assignment' => $total_assignment,
    'total_upcoming' => $total_upcoming,
    'total_general' => $total_general,
    'total_activities' => $total_activities,
    
    // Flags
    'has_quiz_activities' => !empty($quiz_activities),
    'has_assignment_activities' => !empty($assignment_activities),
    'has_upcoming_activities' => !empty($upcoming_activities),
    'has_general_activities' => !empty($general_activities),
    'has_completed_activities' => !empty($completed_activities),
    
    // Page identification flags for sidebar
    'is_currentactivity_page' => true,
    'is_dashboard_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'currentpage' => [
        'currentactivity' => true
    ]
];

// Set up sidebar context and URLs based on dashboard type
$sidebarcontext = remui_kids_build_highschool_sidebar_context('currentactivity', $USER);
$templatecontext = array_merge($templatecontext, $sidebarcontext);

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_current_activity_page', $templatecontext);
echo $OUTPUT->footer();



