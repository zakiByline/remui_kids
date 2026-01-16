<?php
/**
 * Parent Activity Preview (clean + minimal)
 *
 * This page shows a read-only summary of a single course module.
 * It avoids heavy styling / complex media previews so it loads quickly
 * and never throws errors when content is missing.
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $PAGE, $OUTPUT;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/get_parent_children.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/child_session.php');
require_once($CFG->libdir . '/completionlib.php');

if (!function_exists('theme_remui_kids_parent_build_fileinfo')) {
    /**
     * Prepare rich file metadata including preview capability info.
     */
function theme_remui_kids_parent_build_fileinfo(stored_file $file, context_module $context, string $component,
    string $filearea, int $itemid, ?int $courseid = null, ?int $childid = null) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $mimetype = $file->get_mimetype();
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        $canpreview = false;
        $previewtype = 'none';

        // Images - all common formats
        if (file_mimetype_in_typegroup($mimetype, 'web_image') || 
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico', 'tiff', 'tif'])) {
            $canpreview = true;
            $previewtype = 'image';
        } 
        // PDF files
        else if ($mimetype === 'application/pdf' || $extension === 'pdf') {
            $canpreview = true;
            $previewtype = 'pdf';
        } 
        // Video files - expanded list
        else if (strpos($mimetype, 'video/') === 0 || 
                in_array($extension, ['mp4', 'webm', 'ogg', 'ogv', 'avi', 'mov', 'wmv', 'flv', 'mkv', '3gp', 'm4v'])) {
            $canpreview = true;
            $previewtype = 'video';
        } 
        // Audio files - expanded list
        else if (strpos($mimetype, 'audio/') === 0 || 
                in_array($extension, ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'wma', 'opus'])) {
            $canpreview = true;
            $previewtype = 'audio';
        } 
        // Text files - expanded list
        else if (in_array($extension, ['html', 'htm', 'txt', 'md', 'markdown', 'csv', 'json', 'xml', 'css', 'js', 'log', 'rtf', 'yaml', 'yml'])) {
            $canpreview = true;
            $previewtype = 'text';
        } 
        // Office documents - can use Google Docs Viewer or Office Online
        else if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'])) {
            $canpreview = true;
            $previewtype = 'office';
        }
        // Code files - show as text
        else if (in_array($extension, ['php', 'py', 'java', 'cpp', 'c', 'h', 'js', 'ts', 'jsx', 'tsx', 'vue', 'rb', 'go', 'rs', 'swift', 'kt', 'scala', 'sh', 'bat', 'ps1', 'sql'])) {
            $canpreview = true;
            $previewtype = 'text';
        }

        $pluginurl = moodle_url::make_pluginfile_url(
            $context->id,
            $component,
            $filearea,
            $itemid,
            $file->get_filepath(),
            $file->get_filename()
        )->out();
        $plugindownloadurl = moodle_url::make_pluginfile_url(
            $context->id,
            $component,
            $filearea,
            $itemid,
            $file->get_filepath(),
            $file->get_filename(),
            true
        )->out();

        $proxyurl = null;
        $proxydownloadurl = null;
        if (!empty($courseid) && !empty($childid)) {
            $proxybase = new moodle_url('/theme/remui_kids/parent/file_preview.php', [
                'fileid' => $file->get_id(),
                'courseid' => $courseid,
                'child' => $childid
            ]);
            $proxyurl = $proxybase->out(false);
            $proxydownloadurl = $proxybase->out(false, ['download' => 1]);
        }

        return [
            'id' => $file->get_id(),
            'filename' => $file->get_filename(),
            'filesize' => $file->get_filesize(),
            'mimetype' => $mimetype,
            'extension' => $extension,
            'fileurl' => $proxyurl ?? $pluginurl,
            'downloadurl' => $proxydownloadurl ?? $plugindownloadurl,
            'pluginurl' => $pluginurl,
            'plugindownloadurl' => $plugindownloadurl,
            'canpreview' => $canpreview,
            'previewtype' => $previewtype
        ];
    }
}

if (!function_exists('remui_kids_parent_get_subsection_child_cms')) {
    /**
     * Retrieve cm_info objects for activities inside a subsection.
     */
    function remui_kids_parent_get_subsection_child_cms(course_modinfo $modinfo, int $courseid, int $subsectioninstanceid): array {
        global $DB;

        $children = [];
        if (!$subsectioninstanceid) {
            return $children;
        }

        try {
            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'component' => 'mod_subsection',
                'itemid' => $subsectioninstanceid
            ], 'id, sequence', IGNORE_MISSING);
        } catch (Exception $e) {
            $section = null;
        }

        if (!$section || empty($section->sequence)) {
            return $children;
        }

        $sequence = array_filter(array_map('intval', explode(',', $section->sequence)));
        foreach ($sequence as $childcmid) {
            if (empty($modinfo->cms[$childcmid])) {
                continue;
            }
            $childcm = $modinfo->cms[$childcmid];
            if (!$childcm->uservisible || $childcm->modname === 'subsection') {
                continue;
            }
            $children[] = $childcm;
        }

        return $children;
    }
}

if (!function_exists('remui_kids_parent_activity_status')) {
    /**
     * Resolve a student's status for a given course module.
     */
    function remui_kids_parent_activity_status(course_modinfo $modinfo, stdClass $course, cm_info $cm, completion_info $completioninfo = null, int $childid = 0): array {
        global $DB;

        $status = [
            'key' => 'notstarted',
            'label' => get_string('notyetstarted', 'completion'),
            'icon' => 'minus-circle',
            'badgeclass' => 'neutral',
        ];

        if (!$cm || !$childid) {
            return $status;
        }

        $completiondata = null;
        if ($completioninfo && $completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
            try {
                $completiondata = $completioninfo->get_data($cm, false, $childid);
            } catch (Exception $e) {
                $completiondata = null;
            }
        }

        if (!$completiondata) {
            try {
                $completiondata = $DB->get_record('course_modules_completion', [
                    'coursemoduleid' => $cm->id,
                    'userid' => $childid
                ], 'completionstate, timestarted');
            } catch (Exception $e) {
                $completiondata = null;
            }
        }

        if ($completiondata) {
            $completionstate = (int) ($completiondata->completionstate ?? COMPLETION_INCOMPLETE);
            $timestarted = (int) ($completiondata->timestarted ?? 0);
            
            if ($completionstate === COMPLETION_COMPLETE || $completionstate === COMPLETION_COMPLETE_PASS) {
                $status['key'] = 'completed';
                $status['label'] = get_string('completion-y', 'completion');
                $status['icon'] = 'check-circle';
                $status['badgeclass'] = 'success';
                return $status;
            }
            if ($completionstate === COMPLETION_COMPLETE_FAIL) {
                $status['key'] = 'completed';
                $status['label'] = get_string('completion-fail', 'completion');
                $status['icon'] = 'exclamation-circle';
                $status['badgeclass'] = 'warning';
                return $status;
            }
            // If started but not completed, show in progress
            if ($timestarted > 0) {
                $status['key'] = 'pending';
                $status['label'] = get_string('inprogress', 'completion');
                $status['icon'] = 'clock';
                $status['badgeclass'] = 'warning';
                return $status;
            }
        }

        // Fallback to module-specific signals.
        switch ($cm->modname) {
            case 'assign':
                $submission = $DB->get_record('assign_submission', [
                    'assignment' => $cm->instance,
                    'userid' => $childid,
                    'latest' => 1
                ], 'status', IGNORE_MISSING);
                if ($submission) {
                    if ($submission->status === 'graded') {
                        $status['key'] = 'completed';
                        $status['label'] = get_string('completion-y', 'completion');
                        $status['icon'] = 'check-circle';
                        $status['badgeclass'] = 'success';
                    } elseif ($submission->status === 'submitted') {
                        $status['key'] = 'pending';
                        $status['label'] = get_string('inprogress', 'completion');
                        $status['icon'] = 'clock';
                        $status['badgeclass'] = 'warning';
                    }
                }
                break;
            case 'quiz':
                $attempt = $DB->get_record('quiz_attempts', [
                    'quiz' => $cm->instance,
                    'userid' => $childid
                ], 'state', IGNORE_MISSING);
                if ($attempt) {
                    if ($attempt->state === 'finished') {
                        $status['key'] = 'completed';
                        $status['label'] = get_string('completion-y', 'completion');
                        $status['icon'] = 'check-circle';
                        $status['badgeclass'] = 'success';
                    } else {
                        $status['key'] = 'pending';
                        $status['label'] = get_string('inprogress', 'completion');
                        $status['icon'] = 'clock';
                        $status['badgeclass'] = 'warning';
                    }
                }
                break;
            case 'subsection':
                if ($modinfo) {
                    $childcms = remui_kids_parent_get_subsection_child_cms($modinfo, $course->id, (int) $cm->instance);
                    $childcount = 0;
                    $completedcount = 0;
                    $startedcount = 0;
                    foreach ($childcms as $childcm) {
                        $childcount++;
                        $childstatus = remui_kids_parent_activity_status($modinfo, $course, $childcm, $completioninfo, $childid);
                        $childstatuskey = $childstatus['key'] ?? 'notstarted';
                        if ($childstatuskey === 'completed') {
                            $completedcount++;
                            $startedcount++;
                        } elseif ($childstatuskey === 'pending') {
                            $startedcount++;
                        }
                    }
                    if ($childcount > 0 && $completedcount === $childcount) {
                        $status['key'] = 'completed';
                        $status['label'] = get_string('completion-y', 'completion');
                        $status['icon'] = 'check-circle';
                        $status['badgeclass'] = 'success';
                    } elseif ($childcount > 0 && $startedcount > 0) {
                        $status['key'] = 'pending';
                        $status['label'] = get_string('inprogress', 'completion');
                        $status['icon'] = 'clock';
                        $status['badgeclass'] = 'warning';
                    } else {
                        // No children started, keep as not started
                        $status['key'] = 'notstarted';
                        $status['label'] = get_string('notyetstarted', 'completion');
                        $status['icon'] = 'minus-circle';
                        $status['badgeclass'] = 'neutral';
                    }
                }
                break;
        }

        return $status;
    }
}

if (!function_exists('remui_kids_parent_get_subsection_children')) {
    /**
     * Fetch activities that live inside a subsection module.
     *
     * @param int $courseid
     * @param int $subsectioninstanceid
     * @param course_modinfo|null $modinfo
     * @param int $childid
     * @param int $currentcmid
     * @return array{title:string, activities:array<int,array>}
     */
    function remui_kids_parent_get_subsection_children(int $courseid, int $subsectioninstanceid, ?course_modinfo $modinfo, int $childid, int $currentcmid = 0): array {
        global $DB, $CFG;

        $result = [
            'title' => '',
            'activities' => []
        ];

        if (!$modinfo || !$subsectioninstanceid) {
            return $result;
        }

        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'component' => 'mod_subsection',
            'itemid' => $subsectioninstanceid
        ], 'id, name, sequence', IGNORE_MISSING);

        if (!$section) {
            return $result;
        }

        $result['title'] = !empty($section->name) ? format_string($section->name) : '';

        if (empty($section->sequence)) {
            return $result;
        }

        $sequence = array_filter(array_map('intval', explode(',', $section->sequence)));
        foreach ($sequence as $subcmid) {
            if (empty($modinfo->cms[$subcmid])) {
                continue;
            }
            $subcm = $modinfo->cms[$subcmid];
            if (!$subcm->uservisible || $subcm->modname === 'subsection') {
                continue;
            }

            $iconurl = '';
            try {
                if ($subcm->get_icon_url()) {
                    $iconurl = $subcm->get_icon_url()->out();
                }
            } catch (Exception $e) {
                $iconurl = '';
            }

            $modlabel = ucfirst($subcm->modname);
            try {
                $modlabel = get_string('modulename', $subcm->modname);
            } catch (Exception $e) {
                $modlabel = ucfirst($subcm->modname);
            }

            $activityurl = '#';
            try {
                $activityurl = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                    'cmid' => $subcm->id,
                    'child' => $childid,
                    'courseid' => $courseid,
                ]))->out();
            } catch (Exception $e) {
                $activityurl = '#';
            }

            $result['activities'][] = [
                'id' => $subcm->id,
                'name' => format_string($subcm->name),
                'modname' => $modlabel,
                'icon' => $iconurl,
                'url' => $activityurl,
                'iscurrent' => ($subcm->id == $currentcmid)
            ];
        }

        return $result;
    }
}

try {
    theme_remui_kids_require_parent(new moodle_url('/theme/remui_kids/parent/parent_dashboard.php'));
} catch (Exception $e) {
    debugging('Parent access error: ' . $e->getMessage());
}

$cmid = optional_param('cmid', 0, PARAM_INT);
$childid = required_param('child', PARAM_INT);
$courseidparam = optional_param('courseid', 0, PARAM_INT);

// Ensure the child belongs to the parent.
$children = get_parent_children($USER->id) ?? [];
$childmap = [];
foreach ($children as $child) {
    $childmap[$child['id']] = $child;
}

if (!isset($childmap[$childid])) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_children.php'),
        get_string('nopermissions', 'error', 'Invalid child selection'));
}
$selectedchild = $childmap[$childid];
set_selected_child($childid);

// Load course/module information - SAFE VERSION
$cm = null;
$courseid = 0;
$course = null;
$coursecontext = null;
$backtocourse = null;

try {
    // Try to get course module if cmid is provided
    $cm = null;
    if ($cmid) {
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, IGNORE_MISSING);
    }
    
    // Get course ID from cm or parameter
    if ($cm && isset($cm->course)) {
        $courseid = $cm->course;
    } else if ($courseidparam) {
        $courseid = $courseidparam;
    }
    
    // If still no course ID, redirect
    if (!$courseid) {
        redirect(new moodle_url('/theme/remui_kids/parent/parent_dashboard.php'),
            'Course not specified. Please select a course first.', 
            null, \core\output\notification::NOTIFY_ERROR);
    }
    
    // Get course
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    $backtocourse = new moodle_url('/theme/remui_kids/parent/parent_course_view.php', [
        'courseid' => $course->id,
        'child' => $childid
    ]);
    
    // Verify the child is enrolled in the course
    $childcourses = enrol_get_users_courses($childid, true, 'id');
    if (empty($childcourses) || !array_key_exists($course->id, $childcourses)) {
        redirect(new moodle_url('/theme/remui_kids/parent/parent_my_courses.php'),
            get_string('nopermissions', 'error', 'Course not assigned to this child'));
    }
    
} catch (Exception $e) {
    // If anything fails, redirect to dashboard
    error_log('Error loading activity preview: ' . $e->getMessage());
    redirect(new moodle_url('/theme/remui_kids/parent/parent_dashboard.php'),
        'Unable to load activity. Please try again.', 
        null, \core\output\notification::NOTIFY_ERROR);
}

$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_url('/theme/remui_kids/parent/parent_activity_preview.php', [
    'cmid' => $cmid,
            'child' => $childid,
    'courseid' => $course->id,
]);
$PAGE->set_title('Activity Preview');
$PAGE->set_heading('Activity Preview');
$PAGE->set_pagelayout('base');

// Gather comprehensive data - SAFE VERSION
$activityavailable = false;
$modname = 'Activity';
$iconurl = '';
$cminfo = null;
$sectionname = '';
$intro = '';
$modinfo = null;
$activityname = 'Activity Not Found';

// Always try to get modinfo for the course (needed for showing all activities)
try {
    $modinfo = get_fast_modinfo($course, $childid);
} catch (Exception $e) {
    error_log('Error getting modinfo: ' . $e->getMessage());
    $modinfo = null;
}

// Check if course module exists and is valid
if ($cm && isset($cm->id) && isset($cm->modname)) {
    try {
        // Check if module is being deleted
        if (isset($cm->deletioninprogress) && $cm->deletioninprogress) {
            $activityavailable = false;
        } else {
            $activityavailable = true;
            $activityname = format_string($cm->name);
            
            // Get modinfo safely (already loaded above)
            if ($modinfo && isset($modinfo->cms[$cmid])) {
                $cminfo = $modinfo->cms[$cmid];
                
                // Check again if module is being deleted
                if (!empty($cminfo->deletioninprogress)) {
                    $activityavailable = false;
                } else {
                    // Get icon
                    try {
                        if ($cminfo->get_icon_url()) {
                            $iconurl = $cminfo->get_icon_url()->out();
                        }
                    } catch (Exception $e) {
                        // Keep empty icon
                    }
                    
                    // Get section name
                    if (isset($cminfo->sectionnum)) {
                        try {
                            $sectioninfo = $modinfo->get_section_info($cminfo->sectionnum);
                            if ($sectioninfo) {
                                $sectionname = get_section_name($course, $sectioninfo);
                            }
                        } catch (Exception $e) {
                            $sectionname = '';
                        }
                    }
                }
            } else {
                $activityavailable = false;
            }
            
            // Get module name
            if ($activityavailable) {
                try {
                    $modname = get_string('modulename', $cm->modname);
                } catch (Exception $e) {
                    $modname = ucfirst($cm->modname);
                }
                
                // Get intro
                try {
                    $intro = format_module_intro($cm->modname, $cm, $cm->course, false);
                } catch (Exception $e) {
                    $intro = '';
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error processing course module: ' . $e->getMessage());
        $activityavailable = false;
    }
}

// Always get all activities from course to show in sidebar
$allactivities = [];
if ($course && $modinfo) {
    try {
        foreach ($modinfo->get_section_info_all() as $sectionnum => $sectioninfo) {
            if ($sectionnum == 0) {
                continue; // Skip general section
            }
            
            $sectioncmids = $modinfo->sections[$sectionnum] ?? [];
            foreach ($sectioncmids as $sectioncmid) {
                try {
                    if (!isset($modinfo->cms[$sectioncmid])) {
                        continue;
                    }
                    
                    $sectioncm = $modinfo->cms[$sectioncmid];
                    
                    // Skip if module is invalid or being deleted
                    if (!$sectioncm || !isset($sectioncm->id) || !isset($sectioncm->modname) || !isset($sectioncm->name)) {
                        continue;
                    }
                    
                    if (isset($sectioncm->deletioninprogress) && $sectioncm->deletioninprogress) {
                        continue;
                    }
                    
                    // Check visibility
                    if (!$sectioncm->is_visible_on_course_page() && !$sectioncm->uservisible) {
                        continue;
                    }
                    
                    // Get module name
                    $sectionmodname = ucfirst($sectioncm->modname);
                    try {
                        $sectionmodname = get_string('modulename', $sectioncm->modname);
                    } catch (Exception $e) {
                        $sectionmodname = ucfirst($sectioncm->modname);
                    }
                    
                    // Get icon
                    $sectioniconurl = '';
                    try {
                        if ($sectioncm->get_icon_url()) {
                            $sectioniconurl = $sectioncm->get_icon_url()->out();
                        }
                    } catch (Exception $e) {
                        // Keep empty
                    }
                    
                    // Get preview URL
                    $sectionpreviewurl = '#';
                    try {
                        $sectionpreviewurl = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                            'cmid' => $sectioncm->id,
                            'child' => $childid,
                            'courseid' => $course->id,
                        ]))->out();
                    } catch (Exception $e) {
                        // Keep #
                    }
                    
                    // Get section name
                    $sectionname = '';
                    try {
                        $sectioninfoobj = $modinfo->get_section_info($sectionnum);
                        if ($sectioninfoobj) {
                            $sectionname = get_section_name($course, $sectioninfoobj);
                        }
                    } catch (Exception $e) {
                        $sectionname = 'Section ' . $sectionnum;
                    }
                    
                    // Fetch activity-specific data based on module type
                    $activity_data = null;
                    try {
                        switch ($sectioncm->modname) {
                            case 'url':
                                $urlrecord = $DB->get_record('url', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($urlrecord && !empty($urlrecord->externalurl)) {
                                    $resourceurl = trim($urlrecord->externalurl);
                                    $safeurl = clean_param($resourceurl, PARAM_URL);
                                    $host = '';
                                    try {
                                        $host = parse_url($safeurl, PHP_URL_HOST) ?: '';
                                    } catch (Exception $e) {
                                        $host = '';
                                    }
                                    $activity_data = [
                                        'type' => 'url',
                                        'url' => $safeurl,
                                        'host' => $host,
                                        'name' => format_string($urlrecord->name ?? $sectioncm->name),
                                        'description' => !empty($urlrecord->intro) ? $urlrecord->intro : '',
                                        'display' => $urlrecord->display ?? 0
                                    ];
                                }
                                break;
                                
                            case 'assign':
                                $assign = $DB->get_record('assign', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($assign) {
                                    $submission = $DB->get_record('assign_submission', [
                                        'assignment' => $assign->id,
                                        'userid' => $childid,
                                        'latest' => 1
                                    ], '*', IGNORE_MISSING);
                                    
                                    $activity_data = [
                                        'type' => 'assign',
                                        'name' => format_string($assign->name),
                                        'intro' => $assign->intro ?? '',
                                        'duedate' => $assign->duedate ?? 0,
                                        'grade' => $assign->grade ?? 0,
                                        'submission_status' => $submission ? $submission->status : 'not_submitted',
                                        'submission_date' => $submission ? $submission->timemodified : null,
                                        'submission_grade' => null
                                    ];
                                    
                                    if ($submission && ($submission->status == 'submitted' || $submission->status == 'graded')) {
                                        $grade = $DB->get_record('assign_grades', [
                                            'assignment' => $assign->id,
                                            'userid' => $childid
                                        ], '*', IGNORE_MISSING);
                                        if ($grade && $grade->grade !== null) {
                                            $activity_data['submission_grade'] = $grade->grade;
                                        }
                                    }
                                }
                                break;
                                
                            case 'quiz':
                                $quiz = $DB->get_record('quiz', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($quiz) {
                                    $attempts = $DB->get_records('quiz_attempts', [
                                        'quiz' => $quiz->id,
                                        'userid' => $childid,
                                        'state' => 'finished'
                                    ], 'timefinish DESC', 'id, attempt, state, timestart, timefinish, sumgrades');
                                    
                                    $attempt_count = count($attempts);
                                    $best_grade = null;
                                    $last_attempt_date = null;
                                    
                                    if (!empty($attempts)) {
                                        foreach ($attempts as $attempt) {
                                            if ($best_grade === null || $attempt->sumgrades > $best_grade) {
                                                $best_grade = $attempt->sumgrades;
                                            }
                                            if ($last_attempt_date === null || $attempt->timefinish > $last_attempt_date) {
                                                $last_attempt_date = $attempt->timefinish;
                                            }
                                        }
                                    }
                                    
                                    $activity_data = [
                                        'type' => 'quiz',
                                        'name' => format_string($quiz->name),
                                        'intro' => $quiz->intro ?? '',
                                        'timeopen' => $quiz->timeopen ?? 0,
                                        'timeclose' => $quiz->timeclose ?? 0,
                                        'grade' => $quiz->grade ?? 0,
                                        'attempt_count' => $attempt_count,
                                        'best_grade' => $best_grade,
                                        'last_attempt_date' => $last_attempt_date
                                    ];
                                }
                                break;
                                
    case 'page':
                                $page = $DB->get_record('page', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($page) {
                                    $activity_data = [
                                        'type' => 'page',
                                        'name' => format_string($page->name),
                                        'intro' => $page->intro ?? '',
                                        'content' => $page->content ?? '',
                                        'contentformat' => $page->contentformat ?? FORMAT_HTML
            ];
        }
        break;
                                
    case 'resource':
                            case 'file':
                                $resource = $DB->get_record('resource', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($resource) {
                                    $activity_data = [
                                        'type' => 'resource',
                                        'name' => format_string($resource->name),
                                        'intro' => $resource->intro ?? '',
                                        'display' => $resource->display ?? 0,
                                        'showdescription' => $resource->showdescription ?? 0
                                    ];
        }
        break;
                                
    case 'folder':
                                $folder = $DB->get_record('folder', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($folder) {
                                    $activity_data = [
                                        'type' => 'folder',
                                        'name' => format_string($folder->name),
                                        'intro' => $folder->intro ?? '',
                                        'showdownloadfolder' => $folder->showdownloadfolder ?? 0
            ];
        }
        break;
                                
                            case 'book':
                                $book = $DB->get_record('book', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($book) {
                                    $activity_data = [
                                        'type' => 'book',
                                        'name' => format_string($book->name),
                                        'intro' => $book->intro ?? '',
                                        'numbering' => $book->numbering ?? 0,
                                        'navstyle' => $book->navstyle ?? 0
                                    ];
                                }
                                break;
                                
                            case 'forum':
                                $forum = $DB->get_record('forum', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($forum) {
                                    $activity_data = [
                                        'type' => 'forum',
                                        'name' => format_string($forum->name),
                                        'intro' => $forum->intro ?? '',
                                        'type_forum' => $forum->type ?? 'general'
            ];
        }
        break;
                                
                            case 'lesson':
                                $lesson = $DB->get_record('lesson', ['id' => $sectioncm->instance], '*', IGNORE_MISSING);
                                if ($lesson) {
                                    $activity_data = [
                                        'type' => 'lesson',
                                        'name' => format_string($lesson->name),
                                        'intro' => $lesson->intro ?? '',
                                        'grade' => $lesson->grade ?? 0,
                                        'maxattempts' => $lesson->maxattempts ?? 0
                                    ];
                                }
                                break;
                                
                            default:
                                // For other activity types, fetch basic info if table exists
                                try {
                                    if ($DB->get_manager()->table_exists($sectioncm->modname)) {
                                        $record = $DB->get_record($sectioncm->modname, ['id' => $sectioncm->instance], 'id, name, intro', IGNORE_MISSING);
                                        if ($record) {
                                            $activity_data = [
                                                'type' => $sectioncm->modname,
                                                'name' => format_string($record->name ?? $sectioncm->name),
                                                'intro' => $record->intro ?? ''
                                            ];
                                        }
                                    }
                                } catch (Exception $e) {
                                    // Table doesn't exist or error - skip
        }
        break;
                        }
                    } catch (Exception $e) {
                        // Ignore errors fetching activity data
                        error_log('Error fetching activity data for ' . $sectioncm->modname . ': ' . $e->getMessage());
                    }
                    
                    $allactivities[] = [
                        'id' => $sectioncm->id,
                        'name' => format_string($sectioncm->name),
                        'modname' => $sectionmodname,
                        'icon' => $sectioniconurl,
                        'url' => $sectionpreviewurl,
                        'section' => $sectionname,
                        'sectionnum' => $sectionnum,
                        'activity_data' => $activity_data // Store all activity-specific data
                    ];
                } catch (Exception $e) {
                    // Skip this activity
                    continue;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error getting all activities: ' . $e->getMessage());
    }
}

$subsectionchildren = [
    'title' => '',
    'activities' => []
];
if ($activityavailable && $cm && $cm->modname === 'subsection') {
    $subsectionchildren = remui_kids_parent_get_subsection_children(
        $course->id,
        $cm->instance,
        $modinfo,
        $childid,
        $cmid
    );
}

// If no cmid provided, select first activity automatically
if (!$cmid && !empty($allactivities)) {
    $firstactivity = reset($allactivities);
    if ($firstactivity && isset($firstactivity['id'])) {
        redirect(new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
            'cmid' => $firstactivity['id'],
            'child' => $childid,
            'courseid' => $course->id,
        ]));
    }
}

// If activity is not available, show error message but don't redirect
if (!$activityavailable) {
    $PAGE->set_title('Activity Not Available');
}

// Get completion status
$completioninfo = new completion_info($course);
$completionstate = null;
$completionlabel = 'Not tracked';
$completionclass = 'neutral';
$activitystatus = [
    'key' => 'notstarted',
    'label' => get_string('notyetstarted', 'completion'),
    'icon' => 'minus-circle',
    'helper' => get_string('notyetstarted', 'completion'),
    'timestarted' => 0,
    'timecompleted' => 0,
    'lastupdated' => 0,
];
$rawcompletion = null;
if ($activityavailable) {
    try {
        if ($completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
            $completiondata = $completioninfo->get_data($cm, false, $childid);
            if ($completiondata) {
                $completionstate = (int) $completiondata->completionstate;
                $rawcompletion = $completiondata;
                
                // Extract timestamps from completion data object
                if (isset($completiondata->timestarted)) {
                    $activitystatus['timestarted'] = (int) $completiondata->timestarted;
                }
                if (isset($completiondata->timecompleted)) {
                    $activitystatus['timecompleted'] = (int) $completiondata->timecompleted;
                }
                if (isset($completiondata->timemodified)) {
                    $activitystatus['lastupdated'] = (int) $completiondata->timemodified;
                }
                
                switch ($completionstate) {
    case COMPLETION_COMPLETE:
        $completionlabel = get_string('completion_complete', 'completion');
        $completionclass = 'complete';
        break;
    case COMPLETION_COMPLETE_PASS:
        $completionlabel = get_string('completion_complete_pass', 'completion');
        $completionclass = 'complete';
        break;
    case COMPLETION_COMPLETE_FAIL:
        $completionlabel = get_string('completion_complete_fail', 'completion');
        $completionclass = 'incomplete';
        break;
    case COMPLETION_INCOMPLETE:
        $completionlabel = get_string('completion_incomplete', 'completion');
        $completionclass = 'incomplete';
        break;
                }
            }
        }
    } catch (Exception $e) {
        // Ignore completion errors
    }
    if (!$rawcompletion) {
        try {
            $rawcompletion = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $cm->id,
                'userid' => $childid
            ], 'coursemoduleid, completionstate, timestarted, timecompleted, timemodified', IGNORE_MISSING);
        } catch (Exception $e) {
            $rawcompletion = null;
        }
    }
    
    // Always try to get timestamps from completion record if it exists (only if not already set from API)
    if ($rawcompletion) {
        // Only set timestamps if they weren't already extracted from completion_info API
        if ($activitystatus['timestarted'] === 0 && isset($rawcompletion->timestarted)) {
            $activitystatus['timestarted'] = (int) $rawcompletion->timestarted;
        }
        if ($activitystatus['timecompleted'] === 0 && isset($rawcompletion->timecompleted)) {
            $activitystatus['timecompleted'] = (int) $rawcompletion->timecompleted;
        }
        if ($activitystatus['lastupdated'] === 0 && isset($rawcompletion->timemodified)) {
            $activitystatus['lastupdated'] = (int) $rawcompletion->timemodified;
        }
        
        // If timemodified is set but timestarted is not, use timemodified as started time
        if ($activitystatus['timestarted'] === 0 && $activitystatus['lastupdated'] > 0) {
            $activitystatus['timestarted'] = $activitystatus['lastupdated'];
        }

        if (!empty($rawcompletion->completionstate)) {
            $activitystatus['key'] = 'completed';
            $activitystatus['label'] = get_string('completion-y', 'completion');
            $activitystatus['icon'] = 'check-circle';
            $activitystatus['helper'] = $activitystatus['timecompleted']
                ? 'Completed on ' . userdate($activitystatus['timecompleted'], get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('completion-y', 'completion');
        } elseif ($activitystatus['timestarted']) {
            $activitystatus['key'] = 'pending';
            $activitystatus['label'] = get_string('inprogress', 'completion');
            $activitystatus['icon'] = 'clock';
            $activitystatus['helper'] = get_string('inprogress', 'completion') . ' – ' . userdate($activitystatus['timestarted'], get_string('strftimedatetimeshort', 'langconfig'));
        } else {
            // Check if there's a timestarted even without completion state
            if ($activitystatus['timestarted'] > 0) {
                $activitystatus['key'] = 'pending';
                $activitystatus['label'] = get_string('inprogress', 'completion');
                $activitystatus['icon'] = 'clock';
                $activitystatus['helper'] = get_string('inprogress', 'completion') . ' – ' . userdate($activitystatus['timestarted'], get_string('strftimedatetimeshort', 'langconfig'));
            } else {
                $activitystatus['key'] = 'notstarted';
                $activitystatus['label'] = get_string('notyetstarted', 'completion');
                $activitystatus['icon'] = 'minus-circle';
                $activitystatus['helper'] = get_string('notyetstarted', 'completion');
            }
        }
    } else {
        // No completion record found, check module-specific data for timestamps
        // This will be handled below
    }
    
    // For activities without completion tracking, try to get timestamps from module-specific data
    // Also check if we have any timestamps from completion data but they're still 0
    if ($activityavailable && $cm && ($activitystatus['timestarted'] === 0 || $activitystatus['timecompleted'] === 0)) {
        if ($cm->modname === 'assign') {
            try {
                $submission = $DB->get_record('assign_submission', [
                    'assignment' => $cm->instance,
                    'userid' => $childid,
                    'latest' => 1
                ], 'timemodified, status', IGNORE_MISSING);
                if ($submission && $submission->timemodified > 0) {
                    $activitystatus['timestarted'] = (int) $submission->timemodified;
                    $activitystatus['lastupdated'] = (int) $submission->timemodified;
                    if ($submission->status === 'graded') {
                        $activitystatus['timecompleted'] = (int) $submission->timemodified;
                        $activitystatus['key'] = 'completed';
                        $activitystatus['label'] = get_string('completion-y', 'completion');
                        $activitystatus['icon'] = 'check-circle';
                    } elseif ($submission->status === 'submitted') {
                        $activitystatus['key'] = 'pending';
                        $activitystatus['label'] = get_string('inprogress', 'completion');
                        $activitystatus['icon'] = 'clock';
                    }
                }
            } catch (Exception $e) {
                // Ignore errors
            }
        } elseif ($cm->modname === 'quiz') {
            try {
                $attempt = $DB->get_record('quiz_attempts', [
                    'quiz' => $cm->instance,
                    'userid' => $childid
                ], 'timestart, timefinish, state', IGNORE_MISSING, IGNORE_MULTIPLE);
                if ($attempt) {
                    if ($attempt->timestart > 0) {
                        $activitystatus['timestarted'] = (int) $attempt->timestart;
                        $activitystatus['lastupdated'] = (int) $attempt->timestart;
                    }
                    if ($attempt->timefinish > 0) {
                        $activitystatus['timecompleted'] = (int) $attempt->timefinish;
                        $activitystatus['lastupdated'] = (int) $attempt->timefinish;
                        $activitystatus['key'] = 'completed';
                        $activitystatus['label'] = get_string('completion-y', 'completion');
                        $activitystatus['icon'] = 'check-circle';
                    } elseif ($attempt->timestart > 0) {
                        $activitystatus['key'] = 'pending';
                        $activitystatus['label'] = get_string('inprogress', 'completion');
                        $activitystatus['icon'] = 'clock';
                    }
                }
            } catch (Exception $e) {
                // Ignore errors
            }
        }
    }
    
    // Final fallback: Check logstore for first view time if we still don't have timestarted
    if ($activityavailable && $cm && $activitystatus['timestarted'] === 0) {
        try {
            $firstview = $DB->get_record_sql(
                "SELECT MIN(timecreated) as firsttime
                 FROM {logstore_standard_log}
                 WHERE contextinstanceid = :cmid
                   AND userid = :userid
                   AND action = 'viewed'
                   AND component = :component",
                [
                    'cmid' => $cm->id,
                    'userid' => $childid,
                    'component' => 'mod_' . $cm->modname
                ],
                IGNORE_MISSING
            );
            if ($firstview && $firstview->firsttime > 0) {
                $activitystatus['timestarted'] = (int) $firstview->firsttime;
                if ($activitystatus['lastupdated'] === 0) {
                    $activitystatus['lastupdated'] = (int) $firstview->firsttime;
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
    }
    
    // For subsections, use the helper function to get accurate status based on child activities
    if ($activityavailable && $cminfo && $cminfo->modname === 'subsection' && $modinfo) {
        $subsectionstatus = remui_kids_parent_activity_status($modinfo, $course, $cminfo, $completioninfo, $childid);
        $activitystatus['key'] = $subsectionstatus['key'];
        $activitystatus['label'] = $subsectionstatus['label'];
        $activitystatus['icon'] = $subsectionstatus['icon'];
        
        // Calculate timestamps from child activities
        $childcms = remui_kids_parent_get_subsection_child_cms($modinfo, $course->id, (int) $cminfo->instance);
        $earliest_started = 0;
        $latest_completed = 0;
        $latest_updated = 0;
        
        foreach ($childcms as $childcm) {
            try {
                $childcompletion = $DB->get_record('course_modules_completion', [
                    'coursemoduleid' => $childcm->id,
                    'userid' => $childid
                ], 'timestarted, timecompleted, timemodified', IGNORE_MISSING);
                
                if ($childcompletion) {
                    $childstarted = (int) ($childcompletion->timestarted ?? 0);
                    $childcompleted = (int) ($childcompletion->timecompleted ?? 0);
                    $childupdated = (int) ($childcompletion->timemodified ?? 0);
                    
                    if ($childstarted > 0) {
                        if ($earliest_started === 0 || $childstarted < $earliest_started) {
                            $earliest_started = $childstarted;
                        }
                    }
                    
                    if ($childcompleted > 0) {
                        if ($childcompleted > $latest_completed) {
                            $latest_completed = $childcompleted;
                        }
                    }
                    
                    if ($childupdated > 0) {
                        if ($childupdated > $latest_updated) {
                            $latest_updated = $childupdated;
                        }
                    }
                }
            } catch (Exception $e) {
                // Skip errors for individual child activities
                continue;
            }
        }
        
        // Update timestamps
        if ($earliest_started > 0) {
            $activitystatus['timestarted'] = $earliest_started;
        }
        if ($latest_completed > 0) {
            $activitystatus['timecompleted'] = $latest_completed;
        }
        if ($latest_updated > 0) {
            $activitystatus['lastupdated'] = $latest_updated;
        }
        
        // Update helper text based on status
        if ($subsectionstatus['key'] === 'completed') {
            $activitystatus['helper'] = $activitystatus['timecompleted']
                ? 'Completed on ' . userdate($activitystatus['timecompleted'], get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('completion-y', 'completion');
        } elseif ($subsectionstatus['key'] === 'pending') {
            $activitystatus['helper'] = $activitystatus['timestarted']
                ? get_string('inprogress', 'completion') . ' – Started on ' . userdate($activitystatus['timestarted'], get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('inprogress', 'completion');
        } else {
            $activitystatus['helper'] = get_string('notyetstarted', 'completion');
        }
    }
}

// Get activity-specific details
$activitydetails = [];
$availabilityinfo = $cminfo && $cminfo->availableinfo ? format_string(strip_tags($cminfo->availableinfo)) : '';
$quickstats = [];
$timelineevents = [];
$urlresourceinfo = null;
$activitynotice = $activityavailable ? '' : 'This activity may have been removed or is no longer accessible for your child.';
$activitytitle = $activityavailable ? format_string($cm->name) : 'Activity unavailable';

$quickstats[] = [
    'label' => 'Module Type',
    'value' => $modname,
    'icon' => 'layer-group'
];
$quickstats[] = [
    'label' => 'Course',
    'value' => format_string($course->shortname ?: $course->fullname),
    'icon' => 'book'
];
if (!empty($sectionname)) {
    $quickstats[] = [
        'label' => 'Section',
        'value' => $sectionname,
        'icon' => 'list'
    ];
}
if (!empty($cm->added)) {
    $quickstats[] = [
        'label' => 'Added On',
        'value' => userdate($cm->added, get_string('strftimedatetimeshort', 'langconfig')),
        'icon' => 'circle-plus'
    ];
    $timelineevents[] = [
        'label' => 'Added to course',
        'date' => $cm->added,
        'icon' => 'circle-plus'
    ];
}

if ($activitystatus['timestarted']) {
    $timelineevents[] = [
        'label' => 'Started by child',
        'date' => $activitystatus['timestarted'],
        'icon' => 'play'
    ];
}

if ($activitystatus['timecompleted']) {
    $timelineevents[] = [
        'label' => 'Completed',
        'date' => $activitystatus['timecompleted'],
        'icon' => 'flag-checkered'
    ];
} elseif ($activitystatus['lastupdated'] && $activitystatus['key'] === 'pending') {
    $timelineevents[] = [
        'label' => 'Last activity update',
        'date' => $activitystatus['lastupdated'],
        'icon' => 'clock'
    ];
}

$currentsectionactivities = [];
if ($cminfo && isset($cminfo->sectionnum) && $modinfo) {
    foreach ($allactivities as $activityentry) {
        if ((int) ($activityentry['sectionnum'] ?? -1) === (int) $cminfo->sectionnum) {
            $activityentry['iscurrent'] = (!empty($cmid) && $activityentry['id'] == $cmid);
            if (!empty($modinfo->cms[$activityentry['id']])) {
                $activityentry['status'] = remui_kids_parent_activity_status($modinfo, $course, $modinfo->cms[$activityentry['id']], $completioninfo, $childid);
            }
            $currentsectionactivities[] = $activityentry;
        }
    }
}

if (!empty($subsectionchildren['activities']) && $modinfo) {
    foreach ($subsectionchildren['activities'] as $idx => $subchildentry) {
        if (!empty($modinfo->cms[$subchildentry['id']])) {
            $subsectionchildren['activities'][$idx]['status'] = remui_kids_parent_activity_status($modinfo, $course, $modinfo->cms[$subchildentry['id']], $completioninfo, $childid);
        }
    }
}

// Assignment details
if ($activityavailable && $cm->modname === 'assign') {
    try {
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($assign) {
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $childid,
                'latest' => 1
            ]);
            
            $submission_status = 'Not submitted';
            $submission_date = null;
            $submission_grade = null;
            
            if ($submission) {
                $submission_status = ucfirst($submission->status);
                $submission_date = $submission->timemodified;
                
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
            
            $activitydetails[] = [
                'label' => 'Due Date',
                'value' => $assign->duedate ? userdate($assign->duedate, get_string('strftimedatetimeshort', 'langconfig')) : 'No due date',
                'icon' => 'calendar'
            ];
            if ($assign->duedate) {
                $timelineevents[] = [
                    'label' => 'Due date',
                    'date' => $assign->duedate,
                    'icon' => 'calendar-check'
                ];
            }
            $activitydetails[] = [
                'label' => 'Submission Status',
                'value' => $submission_status,
                'icon' => 'file-signature',
                'class' => $submission_status === 'Submitted' || $submission_status === 'Graded' ? 'success' : 'warning'
            ];
            if ($submission_date) {
                $activitydetails[] = [
                    'label' => 'Submitted',
                    'value' => userdate($submission_date, get_string('strftimedatetimeshort', 'langconfig')),
                    'icon' => 'clock'
                ];
                $timelineevents[] = [
                    'label' => 'Submitted',
                    'date' => $submission_date,
                    'icon' => 'check-circle'
                ];
            }
            if ($submission_grade !== null) {
                $activitydetails[] = [
                    'label' => 'Grade',
                    'value' => round($submission_grade, 1) . '/' . round($assign->grade, 1),
                    'icon' => 'star',
                    'class' => 'success'
                ];
            }
        }
    } catch (Exception $e) {
        // Ignore errors
    }
}

// Quiz details - Enhanced with detailed attempt information
$quizattempts = [];
$quizinfo = null;
if ($activityavailable && $cm->modname === 'quiz') {
    try {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($quiz) {
            $quizinfo = $quiz;
            
            // Get all attempts with more details
            $attempts = $DB->get_records('quiz_attempts', [
                'quiz' => $quiz->id,
                'userid' => $childid
            ], 'attempt DESC', 'id, uniqueid, attempt, state, timestart, timefinish, sumgrades, preview');
            
            $attempt_count = count($attempts);
            $best_grade = null;
            $last_attempt_date = null;
            $finished_attempts = 0;
            
            // Process attempts for detailed display
            foreach ($attempts as $attempt) {
                $duration = null;
                if ($attempt->timefinish && $attempt->timestart) {
                    $duration = $attempt->timefinish - $attempt->timestart;
                }
                
                $attempt_grade = null;
                $attempt_percentage = null;
                if ($attempt->state == 'finished' && $attempt->sumgrades !== null && $quiz->sumgrades > 0) {
                    $attempt_grade = $attempt->sumgrades;
                    $attempt_percentage = round(($attempt_grade / $quiz->sumgrades) * 100, 1);
                }
                
                if ($attempt->state == 'finished') {
                    $finished_attempts++;
                    if ($best_grade === null || ($attempt_grade !== null && $attempt_grade > $best_grade)) {
                        $best_grade = $attempt_grade;
                    }
                    if ($last_attempt_date === null || $attempt->timefinish > $last_attempt_date) {
                        $last_attempt_date = $attempt->timefinish;
                    }
                }
                
                // Get state display name
                $state_display = 'In progress';
                if ($attempt->state == 'finished') {
                    $state_display = 'Finished';
                } elseif ($attempt->state == 'inprogress') {
                    $state_display = 'In progress';
                } elseif ($attempt->state == 'overdue') {
                    $state_display = 'Overdue';
                } elseif ($attempt->state == 'abandoned') {
                    $state_display = 'Abandoned';
                }
                
                $quizattempts[] = [
                    'id' => $attempt->id,
                    'uniqueid' => $attempt->uniqueid,
                    'attempt' => $attempt->attempt,
                    'state' => $attempt->state,
                    'state_display' => $state_display,
                    'timestart' => $attempt->timestart,
                    'timefinish' => $attempt->timefinish,
                    'duration' => $duration,
                    'sumgrades' => $attempt_grade,
                    'percentage' => $attempt_percentage,
                    'maxgrade' => $quiz->sumgrades,
                    'quizgrade' => $quiz->grade,
                    'preview' => $attempt->preview
                ];
            }
            
            // Add quiz details
            if ($quiz->timeopen) {
                $activitydetails[] = [
                    'label' => 'Opens',
                    'value' => userdate($quiz->timeopen, get_string('strftimedatetimeshort', 'langconfig')),
                    'icon' => 'unlock'
                ];
                $timelineevents[] = [
                    'label' => 'Opens',
                    'date' => $quiz->timeopen,
                    'icon' => 'unlock'
                ];
            }
            if ($quiz->timeclose) {
                $activitydetails[] = [
                    'label' => 'Closes',
                    'value' => userdate($quiz->timeclose, get_string('strftimedatetimeshort', 'langconfig')),
                    'icon' => 'lock'
                ];
                $timelineevents[] = [
                    'label' => 'Closes',
                    'date' => $quiz->timeclose,
                    'icon' => 'lock'
                ];
            }
            
            $activitydetails[] = [
                'label' => 'Total Attempts',
                'value' => $attempt_count . ($attempt_count == 1 ? ' attempt' : ' attempts'),
                'icon' => 'redo'
            ];
            
            if ($finished_attempts > 0) {
                $activitydetails[] = [
                    'label' => 'Finished Attempts',
                    'value' => $finished_attempts,
                    'icon' => 'check-circle',
                    'class' => 'success'
                ];
            }
            
            if ($quiz->attempts > 0) {
                $remaining = max(0, $quiz->attempts - $attempt_count);
                $activitydetails[] = [
                    'label' => 'Attempts Allowed',
                    'value' => $quiz->attempts,
                    'icon' => 'hashtag'
                ];
                if ($remaining > 0) {
                    $activitydetails[] = [
                        'label' => 'Remaining Attempts',
                        'value' => $remaining,
                        'icon' => 'redo',
                        'class' => 'warning'
                    ];
                }
            }
            
            if ($best_grade !== null && $quiz->sumgrades > 0) {
                $best_percentage = round(($best_grade / $quiz->sumgrades) * 100, 1);
                $activitydetails[] = [
                    'label' => 'Best Grade',
                    'value' => round($best_grade, 1) . '/' . round($quiz->sumgrades, 1) . ' (' . $best_percentage . '%)',
                    'icon' => 'trophy',
                    'class' => 'success'
                ];
            }
            
            if ($last_attempt_date) {
                $activitydetails[] = [
                    'label' => 'Last Attempt',
                    'value' => userdate($last_attempt_date, get_string('strftimedatetimeshort', 'langconfig')),
                    'icon' => 'clock'
                ];
                $timelineevents[] = [
                    'label' => 'Last attempt',
                    'date' => $last_attempt_date,
                    'icon' => 'history'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Error getting quiz details: ' . $e->getMessage());
    }
}

// Resource/File details - Enhanced with file information and preview support
$resourceinfo = null;
$fileinfo = null;
if ($activityavailable && in_array($cm->modname, ['resource', 'file'])) {
    try {
        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($resource) {
            $context = context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            
            if (!empty($files)) {
                $file = reset($files);
                $fileinfo = theme_remui_kids_parent_build_fileinfo(
                    $file,
                    $context,
                    'mod_resource',
                    'content',
                    $resource->revision,
                    $courseid,
                    $selectedchildid
                );
                
                $resourceinfo = [
                    'name' => format_string($resource->name ?? $cm->name),
                    'intro' => !empty($resource->intro) ? $resource->intro : '',
                    'display' => $resource->display ?? 0,
                    'showdescription' => $resource->showdescription ?? 0,
                    'file' => $fileinfo
                ];
                
                // Add file details
                $activitydetails[] = [
                    'label' => 'File Name',
                    'value' => $fileinfo['filename'],
                    'icon' => 'file'
                ];
                
                $activitydetails[] = [
                    'label' => 'File Size',
                    'value' => display_size($fileinfo['filesize']),
                    'icon' => 'hdd'
                ];
                
                $activitydetails[] = [
                    'label' => 'File Type',
                    'value' => $fileinfo['mimetype'],
                    'icon' => 'file-alt'
                ];
                
            }
        }
    } catch (Exception $e) {
        error_log('Error getting resource details: ' . $e->getMessage());
    }
}

// URL resource details
$urlresourceinfo = null;
if ($activityavailable && $cm->modname === 'url') {
    try {
        $urlrecord = $DB->get_record('url', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($urlrecord && !empty($urlrecord->externalurl)) {
            $resourceurl = trim($urlrecord->externalurl);
            $safeurl = clean_param($resourceurl, PARAM_URL);
            $host = '';
            try {
                $host = parse_url($safeurl, PHP_URL_HOST) ?: '';
            } catch (Exception $e) {
                $host = '';
            }
            $urlresourceinfo = [
                'url' => $safeurl,
                'host' => $host,
                'name' => format_string($urlrecord->name ?? $cm->name),
                'description' => !empty($urlrecord->intro) ? $urlrecord->intro : '',
                'display' => $urlrecord->display ?? 0
            ];

            if (!empty($urlrecord->timemodified)) {
                $timelineevents[] = [
                    'label' => 'Updated',
                    'date' => $urlrecord->timemodified,
                    'icon' => 'refresh'
                ];
            }
            
            // Add URL details
            if (!empty($host)) {
                $activitydetails[] = [
                    'label' => 'Website',
                    'value' => $host,
                    'icon' => 'globe'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Error getting URL details: ' . $e->getMessage());
    }
}

// Page resource details
$pageinfo = null;
if ($activityavailable && $cm->modname === 'page') {
    try {
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($page) {
            $pageinfo = [
                'name' => format_string($page->name),
                'intro' => !empty($page->intro) ? $page->intro : '',
                'content' => $page->content ?? '',
                'contentformat' => $page->contentformat ?? FORMAT_HTML
            ];
        }
    } catch (Exception $e) {
        error_log('Error getting page details: ' . $e->getMessage());
    }
}

// Folder resource details
$folderinfo = null;
if ($activityavailable && $cm->modname === 'folder') {
    try {
        $folder = $DB->get_record('folder', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if ($folder) {
            $context = context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'sortorder DESC, id ASC', false);
            $folderfiles = [];
            foreach ($files as $folderfile) {
                if ($folderfile->is_directory()) {
                    continue;
                }
                $folderfiles[] = theme_remui_kids_parent_build_fileinfo(
                    $folderfile,
                    $context,
                    'mod_folder',
                    'content',
                    0,
                    $courseid,
                    $selectedchildid
                );
            }
            $filecount = count($folderfiles);
            
            $folderinfo = [
                'name' => format_string($folder->name),
                'intro' => !empty($folder->intro) ? $folder->intro : '',
                'showdownloadfolder' => $folder->showdownloadfolder ?? 0,
                'filecount' => $filecount,
                'files' => $folderfiles
            ];
            
            $activitydetails[] = [
                'label' => 'Files in Folder',
                'value' => $filecount . ($filecount == 1 ? ' file' : ' files'),
                'icon' => 'folder'
            ];
        }
    } catch (Exception $e) {
        error_log('Error getting folder details: ' . $e->getMessage());
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<style>
/* Hide parent sidebar completely on activity preview page - this page has its own sidebar */
#parent-sidebar,
.parent-sidebar,
.parent-sidebar-toggle {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    transform: translateX(-100%) !important;
    left: -9999px !important;
    position: absolute !important;
    z-index: -1 !important;
}

/* Adjust main content to full width since sidebar is hidden */
.parent-main-content,
[class*="parent-main-content"],
body .parent-main-content {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* Hide sidebar overlay if it exists */
.sidebar-overlay {
    display: none !important;
}

/* Responsive Design - Hide activity sidebar on mobile/tablet, show with menu button */
@media screen and (max-width: 1024px) {
    /* Hide the activity sidebar by default on mobile/tablet */
    .parent-activity-container {
        left: 0 !important;
        width: 100% !important;
    }
    
    /* Show menu button for activity sidebar */
    .activity-sidebar-toggle {
        display: flex !important;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1002;
        background: #1976d2;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 12px 15px;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
        align-items: center;
        justify-content: center;
        font-size: 18px;
        transition: all 0.3s ease;
    }
    
    .activity-sidebar-toggle:hover {
        background: #1565c0;
        transform: scale(1.05);
        box-shadow: 0 6px 16px rgba(25, 118, 210, 0.4);
    }
    
    .activity-sidebar-toggle:active {
        transform: scale(0.95);
    }
    
    #sidebar-toggle-icon {
        transition: transform 0.3s ease;
    }
    
    /* Activity sidebar - hidden by default */
    #activity-sidebar,
    .activity-sidebar {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 280px !important;
        height: 100vh !important;
        background: white !important;
        z-index: 1001 !important;
        transform: translateX(-100%) !important;
        -webkit-transform: translateX(-100%) !important;
        -moz-transform: translateX(-100%) !important;
        -ms-transform: translateX(-100%) !important;
        transition: transform 0.3s ease !important;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1) !important;
        overflow-y: auto !important;
    }
    
    /* Show activity sidebar when open */
    #activity-sidebar.open,
    .activity-sidebar.open {
        transform: translateX(0) !important;
        -webkit-transform: translateX(0) !important;
        -moz-transform: translateX(0) !important;
        -ms-transform: translateX(0) !important;
    }
    
    /* Overlay when activity sidebar is open */
    .activity-sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .activity-sidebar-overlay.active {
        display: block;
        opacity: 1;
    }
    
    /* Main activity content takes full width */
    .parent-activity-wrap,
    .activity-main-wrapper,
    .parent-activity-main,
    .activity-main-content {
        width: 100% !important;
        margin-left: 0 !important;
        padding: 0 !important;
    }
    
    /* Hide global sidebar toggle on mobile */
    .global-sidebar-toggle-btn {
        display: none !important;
    }
}

/* Desktop: Always show activity sidebar */
@media screen and (min-width: 1025px) {
    .activity-sidebar-toggle {
        display: none !important;
    }
    
    .activity-sidebar-overlay {
        display: none !important;
    }
    
    /* Hide close button on desktop */
    .sidebar-close-btn {
        display: none !important;
    }
}
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<style>
    body {
        overflow: hidden;
    }
    
    /* Global Parent Sidebar Toggle Button */
    .global-sidebar-toggle-btn {
        position: fixed;
        top: 100px;
        left: 280px;
        width: 42px;
        height: 42px;
        background: rgba(15, 23, 42, 0.05);
        color: #0f172a;
        border: 1px solid rgba(148, 163, 184, 0.4);
        border-radius: 0 14px 14px 0;
        cursor: pointer;
        z-index: 201;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .global-sidebar-toggle-btn::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(135deg, rgba(148, 163, 184, 0.1), rgba(203, 213, 225, 0.1));
        opacity: 0;
        transition: opacity 0.3s ease;
        z-index: -1;
    }
    
    .global-sidebar-toggle-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        color: #0f172a;
        border-color: rgba(99, 102, 241, 0.5);
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
        transform: translateX(2px);
    }
    
    .global-sidebar-toggle-btn:hover::after {
        opacity: 1;
    }
    
    .global-sidebar-toggle-btn i {
        font-size: 16px;
        transition: transform 0.3s;
    }
    
    /* When global sidebar is collapsed, button moves to left edge */
    body.global-sidebar-collapsed .global-sidebar-toggle-btn {
        left: 0;
    }
    
    body.global-sidebar-collapsed .global-sidebar-toggle-btn i {
        transform: rotate(180deg);
    }
    
    /* Parent Sidebar Collapse Styles */
    body.global-sidebar-collapsed .parent-sidebar {
        transform: translateX(-100%);
        width: 0;
        border-right: none;
    }
    
    .parent-activity-container {
        position: fixed;
        top: 0;
        left: 0 !important; /* Always start from left since sidebar is hidden */
        right: 0;
        bottom: 0;
        display: flex;
        min-height: 100vh;
        background: linear-gradient(135deg, #f0f9ff 0%, #f5f3ff 50%, #fef3f2 100%);
        font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        padding: 0;
        padding-bottom: 40px;
        z-index: 1;
        margin-top: 80px;
        transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        width: 100% !important;
    }
    
    /* When global sidebar is collapsed, activity container expands to full screen */
    body.global-sidebar-collapsed .parent-activity-container {
        left: 0 !important;
    }
    
    /* Hide global sidebar toggle button since sidebar is completely hidden */
    .global-sidebar-toggle-btn {
        display: none !important;
    }
    
    
    .activity-main-wrapper {
    display: flex;
        flex: 1;
        overflow: hidden;
        width: 100%;
        position: relative;
    }
    
    
    /* Sidebar */
    .activity-sidebar {
        width: 360px;
        background: linear-gradient(180deg, #ffffff 0%, #fefefe 100%);
        border-right: 1px solid rgba(226, 232, 240, 0.6);
        overflow-y: auto;
        overflow-x: hidden;
        height: 100vh;
        position: relative;
        box-shadow: 2px 0 20px rgba(14, 165, 233, 0.08);
    display: flex;
    flex-direction: column;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        transform: translateX(0);
    }
    
    
    .activity-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .activity-sidebar::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .activity-sidebar::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.4);
        border-radius: 3px;
    }
    
    .activity-sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(148, 163, 184, 0.6);
    }
    
    .sidebar-header {
        padding: 28px 24px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
        color: #1e293b;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    /* Close button inside sidebar - Hidden on desktop */
    .sidebar-close-btn {
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(148, 163, 184, 0.1);
        border: none;
        border-radius: 8px;
        padding: 8px 10px;
        cursor: pointer;
        color: #64748b;
        transition: all 0.3s ease;
        font-size: 16px;
        flex-shrink: 0;
    }
    
    .sidebar-close-btn:hover {
        background: rgba(148, 163, 184, 0.2);
        color: #475569;
        transform: scale(1.1);
    }
    
    .sidebar-close-btn:active {
        transform: scale(0.95);
    }
    
    .sidebar-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.3) 0%, transparent 100%);
        pointer-events: none;
    }
    
    .sidebar-header h2 {
    margin: 0;
        font-size: 20px;
    font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
        position: relative;
        z-index: 1;
        text-shadow: none;
    }
    
    .sidebar-header .course-name {
        font-size: 13px;
        opacity: 0.95;
        margin-top: 6px;
        font-weight: 400;
        position: relative;
        z-index: 1;
    }
    
    .sidebar-content {
        padding: 20px 0;
        flex: 1;
        overflow-y: auto;
    }
    
    .sidebar-section {
        margin-bottom: 32px;
    }
    
    .sidebar-section-title {
        padding: 10px 24px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.15em;
        color: #64748b;
        font-weight: 800;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom: 2px solid #e2e8f0;
        position: sticky;
        top: 0;
        z-index: 5;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
    }
    
    .activity-sidebar-item {
        display: block;
        padding: 14px 24px;
        text-decoration: none;
    color: #0f172a;
        border-left: 4px solid transparent;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
        margin: 2px 0;
    }
    
    .activity-sidebar-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 0;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .activity-sidebar-item:hover {
        background: linear-gradient(135deg, rgba(241, 245, 249, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%);
        border-left-color: #94a3b8;
        color: #475569;
        transform: translateX(4px);
    }
    
    .activity-sidebar-item:hover::before {
        width: 100%;
    }
    
    .activity-sidebar-item.active {
        background: linear-gradient(135deg, rgba(226, 232, 240, 0.6) 0%, rgba(203, 213, 225, 0.6) 100%);
        border-left-color: #64748b;
        color: #1e293b;
        font-weight: 600;
        box-shadow: inset 4px 0 0 #64748b, 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .activity-sidebar-item.active::before {
        width: 100%;
    }
    
    .activity-sidebar-item-content {
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
        z-index: 1;
    }
    
    .activity-sidebar-item-icon {
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        color: #64748b;
        font-size: 16px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        border: 1px solid #e2e8f0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .activity-sidebar-item:hover .activity-sidebar-item-icon {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
        color: #475569;
    }
    
    .activity-sidebar-item.active .activity-sidebar-item-icon {
        background: linear-gradient(135deg, #cbd5e1 0%, #94a3b8 100%);
        color: #1e293b;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border-color: #94a3b8;
    }
    
    .activity-sidebar-item-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 10px;
    }
    
    .activity-sidebar-item-text {
        flex: 1;
        min-width: 0;
    }
    
    .activity-sidebar-item-name {
    font-size: 14px;
        font-weight: 600;
        line-height: 1.5;
        margin-bottom: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        transition: color 0.3s;
    }
    
    .activity-sidebar-item-type {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 2px;
    display: flex;
        align-items: center;
        gap: 4px;
        font-weight: 500;
    }
    
    .activity-sidebar-item.active .activity-sidebar-item-type {
        color: #64748b;
    }
    
    /* Top navigation */
    .activity-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 24px 48px 16px;
        position: sticky;
        top: 0;
        z-index: 5;
        background: rgba(248, 250, 252, 0.85);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid rgba(226, 232, 240, 0.7);
    }

    .topbar-left {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .topbar-breadcrumbs {
        display: flex;
        align-items: center;
        gap: 8px;
    font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        font-weight: 700;
        color: #94a3b8;
    }

    .topbar-breadcrumbs a {
        text-decoration: none;
        color: #64748b;
    }

    .topbar-title {
        font-size: 22px;
        font-weight: 700;
        color: #0f172a;
    }

    .topbar-actions {
        display: flex;
        align-items: center;
    gap: 12px;
}

    .topbar-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
        padding: 10px 16px;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
        background: white;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        transition: all 0.2s;
    }

    .topbar-button.primary {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        color: white;
        border: none;
    }

    .topbar-button:hover {
        transform: translateY(-1px);
    }

    .topbar-badges {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .topbar-badge {
    display: inline-flex;
    align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0369a1;
    font-size: 12px;
    font-weight: 600;
    }

    /* Top Navigation Bar */
    .activity-topbar {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        padding: 20px 32px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        box-shadow: 0 2px 12px rgba(14, 165, 233, 0.06);
        position: sticky;
        top: 0;
        z-index: 100;
        flex-shrink: 0;
    }
    
    .topbar-left {
        flex: 1;
        min-width: 0;
    }
    
    .topbar-breadcrumbs {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #94a3b8;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    
    .topbar-breadcrumbs a {
        color: #64748b;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s;
    }
    
    .topbar-breadcrumbs a:hover {
        color: #475569;
        text-decoration: underline;
    }
    
    .topbar-breadcrumbs span {
        color: #cbd5e1;
    }
    
    .topbar-title {
        font-size: 24px;
    font-weight: 700;
    color: #0f172a;
        margin-bottom: 16px;
        line-height: 1.3;
    }
    
    .topbar-badges {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .topbar-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: linear-gradient(135deg, rgba(241, 245, 249, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%);
        border: 1px solid rgba(148, 163, 184, 0.3);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    
    .topbar-badge i {
        font-size: 11px;
    }
    
    .topbar-actions {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-shrink: 0;
    }
    
    .topbar-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
        padding: 10px 18px;
        border-radius: 10px;
        font-size: 13px;
    font-weight: 600;
    text-decoration: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #64748b;
    }
    
    .topbar-button:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    
    .topbar-button.primary {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        color: white;
        border-color: transparent;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .topbar-button.primary:hover {
        background: linear-gradient(135deg, #475569 0%, #334155 100%);
        color: white;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
    
    /* Main Content */
    .parent-activity-wrap {
        flex: 1;
        padding: 0;
        overflow: hidden;
        background: transparent;
        position: relative;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    
    .parent-activity-wrap::-webkit-scrollbar {
        width: 8px;
    }
    
    .parent-activity-wrap::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .parent-activity-wrap::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.4);
        border-radius: 4px;
    }
    
    .parent-activity-wrap::-webkit-scrollbar-thumb:hover {
        background: rgba(148, 163, 184, 0.6);
    }
    
    .activity-card {
    background: #ffffff;
        border-radius: 0;
        border: none;
        box-shadow: none;
        max-width: 100%;
        margin: 0;
        margin-bottom: 40px;
        padding: 40px 48px 48px;
        flex: 1;
        position: relative;
        overflow-y: auto;
    }
    
    .activity-card::-webkit-scrollbar {
        width: 6px;
    }
    
    .activity-card::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .activity-card::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.4);
        border-radius: 3px;
    }
    
    .activity-card::-webkit-scrollbar-thumb:hover {
        background: rgba(148, 163, 184, 0.6);
    }
    
    .activity-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 180px;
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);
        z-index: 0;
    }
    
    .activity-card > * {
        position: relative;
        z-index: 1;
    }

    .activity-status-panel {
        margin-top: 24px;
        padding: 22px 24px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
    }

    .activity-status-panel .status-lead {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .activity-status-panel .status-lead span {
        font-size: 13px;
        color: #64748b;
    }

    .activity-status-panel .status-lead small {
        font-size: 12px;
        color: #475569;
        font-weight: 500;
    }

    .activity-status-panel .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid rgba(148, 163, 184, 0.4);
        color: #475569;
        background: #ffffff;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
        width: fit-content;
    }

    .activity-status-panel .status-pill.status-completed {
        border-color: rgba(34, 197, 94, 0.5);
        background: linear-gradient(135deg, rgba(34, 197, 94, 0.18), rgba(16, 185, 129, 0.18));
        color: #15803d;
    }

    .activity-status-panel .status-pill.status-pending {
        border-color: rgba(249, 115, 22, 0.4);
        background: linear-gradient(135deg, rgba(251, 191, 36, 0.18), rgba(249, 115, 22, 0.15));
        color: #92400e;
    }

    .activity-status-panel .status-pill.status-notstarted {
        border-color: rgba(148, 163, 184, 0.4);
        background: linear-gradient(135deg, rgba(148, 163, 184, 0.12), rgba(100, 116, 139, 0.08));
        color: #475569;
    }

    .status-metrics {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .status-metric {
        min-width: 160px;
        padding: 12px 16px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.7);
        background: #ffffff;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    }

    .status-metric span {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #94a3b8;
        margin-bottom: 4px;
        font-weight: 700;
    }

    .status-metric strong {
        display: block;
        font-size: 14px;
        color: #0f172a;
        font-weight: 700;
    }

    .activity-content {
        border-top: 1px solid rgba(226, 232, 240, 0.6);
        padding-top: 28px;
        margin-top: 28px;
        font-size: 14px;
        color: #334155;
        line-height: 1.7;
    }
    
    .activity-content p {
        margin-bottom: 16px;
    }
    
    .notice {
        margin-top: 32px;
        padding: 18px 20px;
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(241, 245, 249, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%);
        border: 1px solid rgba(148, 163, 184, 0.3);
        color: #64748b;
    font-size: 13px;
        font-weight: 500;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .notice strong {
        color: #475569;
        font-weight: 700;
    }
    .actions {
        margin-top: 24px;
    display: flex;
    gap: 12px;
        flex-wrap: wrap;
}
    .actions a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
        border-radius: 10px;
    font-size: 13px;
    text-decoration: none;
    font-weight: 600;
    }
    .actions a.primary {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    .actions a.secondary {
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .url-resource-card {
        margin: 30px 0;
        padding: 0;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.3);
        background: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .url-resource-embed {
        width: 100%;
        min-height: 600px;
        border-radius: 16px;
        overflow: hidden;
        background: #ffffff;
    }

    .url-resource-embed iframe {
    width: 100%;
        height: 600px;
        border: none;
        background: #ffffff;
    }
    .completion-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
        padding: 8px 14px;
    border-radius: 12px;
    font-size: 13px;
        font-weight: 700;
        margin-top: 16px;
    }
    .completion-status.complete {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.15));
        color: #047857;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .completion-status.incomplete {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.15));
        color: #b45309;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .completion-status.neutral {
        background: linear-gradient(135deg, rgba(148, 163, 184, 0.1), rgba(100, 116, 139, 0.1));
        color: #64748b;
        border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .details-grid {
    display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid #e2e8f0;
    }
    .detail-item {
    display: flex;
    flex-direction: column;
        gap: 6px;
        padding: 14px;
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    .detail-item .label {
        font-size: 11px;
    text-transform: uppercase;
        letter-spacing: 0.15em;
        color: #64748b;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .detail-item .label i {
        color: #06b6d4;
        font-size: 12px;
    }
    
    .detail-item .value {
        font-size: 15px;
        color: #0f172a;
        font-weight: 600;
        line-height: 1.4;
    }
    .detail-item.success .value {
        color: #15803d;
    }
    .detail-item.warning .value {
        color: #a16207;
    }
    .availability-info {
        margin-top: 16px;
        padding: 12px 16px;
        background: rgba(254, 240, 138, 0.3);
        border-left: 3px solid #fbbf24;
        border-radius: 8px;
    font-size: 13px;
        color: #854d0e;
    }
    .timeline-section {
        margin-top: 32px;
    }
    .timeline-section h3 {
        margin: 0 0 14px;
        font-size: 16px;
        color: #334155;
    font-weight: 700;
    }
    .timeline-list {
        list-style: none;
        margin: 0;
        padding: 0;
        position: relative;
    }
    .timeline-list::before {
        content: '';
        position: absolute;
        left: 18px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: rgba(148, 163, 184, 0.4);
    }
    .timeline-item {
        display: flex;
        gap: 16px;
        margin-bottom: 18px;
        position: relative;
        padding-left: 44px;
    }
    .timeline-icon {
        position: absolute;
        left: 0;
        top: 0;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #cbd5e1 0%, #94a3b8 100%);
        border: 2px solid rgba(148, 163, 184, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e293b;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    .timeline-content {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 12px 14px;
        flex: 1;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    .timeline-content .label {
    font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
        font-size: 14px;
}
    .timeline-content .date {
        font-size: 12px;
        color: #64748b;
    }
    
    /* Quiz Attempts Table */
    .quiz-attempts-section {
        margin-top: 32px;
        padding-top: 28px;
        border-top: 2px solid #e2e8f0;
    }
    
    .quiz-attempts-section h3 {
        margin: 0 0 20px;
        font-size: 18px;
        color: #0f172a;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .quiz-attempts-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .quiz-attempts-table thead {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }
    
    .quiz-attempts-table th {
        padding: 14px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #64748b;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .quiz-attempts-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 14px;
        color: #334155;
    }
    
    .quiz-attempts-table tbody tr:hover {
        background: #f8fafc;
    }
    
    .quiz-attempts-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .attempt-state {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .attempt-state.finished {
        background: rgba(16, 185, 129, 0.15);
        color: #047857;
    }
    
    .attempt-state.inprogress {
        background: rgba(250, 204, 21, 0.15);
        color: #a16207;
    }
    
    .attempt-state.overdue {
        background: rgba(239, 68, 68, 0.15);
        color: #b91c1c;
    }
    
    .attempt-state.abandoned {
        background: rgba(148, 163, 184, 0.15);
        color: #475569;
    }
    
    .attempt-grade {
        font-weight: 700;
        color: #0f172a;
    }
    
    .attempt-grade.good {
        color: #047857;
    }
    
    .attempt-grade.average {
        color: #a16207;
    }
    
    .attempt-grade.poor {
        color: #b91c1c;
    }
    
    /* Resource Display */
    .resource-display-section {
        margin-top: 32px;
        padding-top: 28px;
        border-top: 2px solid #e2e8f0;
    }
    
    .resource-display-section h3 {
        margin: 0 0 20px;
        font-size: 18px;
        color: #0f172a;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .resource-file-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .resource-file-info {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .resource-file-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(241, 245, 249, 0.8), rgba(226, 232, 240, 0.8));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #64748b;
        flex-shrink: 0;
    }
    
    .resource-file-details {
        flex: 1;
        min-width: 0;
    }
    
    .resource-file-name {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 6px;
        word-break: break-word;
    }
    
    .resource-file-meta {
        font-size: 13px;
        color: #64748b;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    
    .resource-file-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .resource-file-actions a {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .resource-file-actions a.primary {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .resource-file-actions a.secondary {
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    
    .resource-file-actions a:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }
    
    .page-content-display {
        margin-top: 24px;
        padding: 24px;
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .folder-files-list {
        display: grid;
        gap: 12px;
        margin-top: 16px;
    }
    
    .folder-file-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    
    .folder-file-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: linear-gradient(135deg, rgba(241, 245, 249, 0.8), rgba(226, 232, 240, 0.8));
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        flex-shrink: 0;
    }
    
    .folder-file-name {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
    }

    .section-subactivities {
        margin-top: 32px;
        padding: 24px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
    }

    .section-subactivities h3 {
        margin: 0 0 16px;
        font-size: 18px;
        color: #0f172a;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .section-subactivities h3 i {
        color: #2563eb;
    }

    .subactivity-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 16px;
    }

    .subactivity-card {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 14px;
        padding: 16px;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        gap: 10px;
        box-shadow: 0 3px 12px rgba(15, 23, 42, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .subactivity-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(37, 99, 235, 0.12);
    }

    .subactivity-card.current {
        border-color: rgba(148, 163, 184, 0.4);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    .subactivity-name {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .subactivity-type {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .subactivity-actions {
        margin-top: auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .subactivity-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        border: 1px solid rgba(148, 163, 184, 0.4);
        color: #475569;
        background: rgba(148, 163, 184, 0.12);
    }

    .subactivity-status.success {
        border-color: rgba(34, 197, 94, 0.4);
        background: rgba(34, 197, 94, 0.15);
        color: #15803d;
    }

    .subactivity-status.warning {
        border-color: rgba(249, 115, 22, 0.4);
        background: rgba(249, 115, 22, 0.15);
        color: #92400e;
    }

    .subactivity-actions a {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        border: 1px solid rgba(148, 163, 184, 0.3);
        color: #2563eb;
        background: rgba(37, 99, 235, 0.08);
    }

    .subactivity-actions a:hover {
        background: rgba(226, 232, 240, 0.8);
    }

    .current-pill {
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        color: #16a34a;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    /* ============================================
       COMPREHENSIVE RESPONSIVE DESIGN
       Adjust all containers, layouts, text, and UI elements
       ============================================ */
    
    /* Tablet View (768px - 1024px) */
    @media screen and (max-width: 1024px) and (min-width: 769px) {
        .parent-activity-container {
            margin-top: 60px !important;
        }
        
        .activity-topbar {
            padding: 18px 24px !important;
        }
        
        .topbar-title {
            font-size: 22px !important;
        }
        
        .activity-card {
            padding: 28px 32px !important;
        }
        
        .details-grid {
            grid-template-columns: repeat(2, 1fr) !important;
        }
    }
    
    /* Mobile/Tablet Combined (≤1024px) */
    @media screen and (max-width: 1024px) {
        .parent-activity-container {
            flex-direction: column;
            margin-top: 60px !important;
        }
        
        /* Activity Sidebar - Hidden by default */
        #activity-sidebar,
        .activity-sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 280px !important;
            height: 100vh !important;
            transform: translateX(-100%) !important;
            -webkit-transform: translateX(-100%) !important;
            -moz-transform: translateX(-100%) !important;
            -ms-transform: translateX(-100%) !important;
            z-index: 1001 !important;
        }
        
        #activity-sidebar.open,
        .activity-sidebar.open {
            transform: translateX(0) !important;
            -webkit-transform: translateX(0) !important;
            -moz-transform: translateX(0) !important;
            -ms-transform: translateX(0) !important;
        }
        
        /* Sidebar Header */
        .sidebar-header {
            padding: 20px 18px !important;
        }
        
        .sidebar-header h2 {
            font-size: 18px !important;
        }
        
        .course-name {
            font-size: 13px !important;
        }
        
        /* Close button inside sidebar - Show on mobile/tablet */
        .sidebar-close-btn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        
        .sidebar-close-btn:hover {
            background: rgba(148, 163, 184, 0.2) !important;
            color: #475569 !important;
            transform: scale(1.1);
        }
        
        /* Sidebar Content */
        .sidebar-content {
            padding: 16px 0 !important;
        }
        
        .sidebar-section {
            margin-bottom: 24px !important;
        }
        
        .sidebar-section-title {
            padding: 8px 18px !important;
            font-size: 10px !important;
        }
        
        /* Sidebar Items */
        .activity-sidebar-item {
            padding: 12px 18px !important;
        }
        
        .activity-sidebar-item-icon {
            width: 36px !important;
            height: 36px !important;
            font-size: 14px !important;
        }
        
        .activity-sidebar-item-name {
            font-size: 13px !important;
        }
        
        .activity-sidebar-item-type {
            font-size: 11px !important;
        }
        
        /* Activity Sidebar Toggle Button - Ensure visible on mobile/tablet */
        .activity-sidebar-toggle {
            display: flex !important;
            position: fixed !important;
            top: 20px !important;
            left: 20px !important;
            z-index: 1002 !important;
            background: #1976d2 !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 12px 15px !important;
            cursor: pointer !important;
            box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3) !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 18px !important;
            transition: all 0.3s ease !important;
        }
        
        .activity-sidebar-toggle:hover {
            background: #1565c0 !important;
            transform: scale(1.05) !important;
            box-shadow: 0 6px 16px rgba(25, 118, 210, 0.4) !important;
        }
        
        .activity-sidebar-toggle:active {
            transform: scale(0.95) !important;
        }
        
        /* Main Content */
        .parent-activity-wrap {
            width: 100% !important;
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .activity-main-wrapper {
            width: 100% !important;
            flex-direction: column !important;
        }
        
        /* Ensure all containers are responsive */
        .activity-card,
        .parent-activity-wrap,
        .activity-main-wrapper {
            box-sizing: border-box !important;
        }
        
        /* Make all text responsive */
        p, span, div, a {
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
        }
        
        /* Responsive images */
        img, video, iframe {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Responsive tables */
        table {
            display: block !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            width: 100% !important;
        }
        
        /* Topbar */
        .activity-topbar {
            padding: 16px 20px !important;
            flex-direction: column !important;
            gap: 12px !important;
        }
        
        .topbar-title {
            font-size: 20px !important;
            margin-bottom: 12px !important;
        }
        
        .topbar-breadcrumbs {
            font-size: 11px !important;
            margin-bottom: 8px !important;
        }
        
        .topbar-actions {
            width: 100% !important;
            flex-direction: column !important;
            gap: 8px !important;
        }
        
        .topbar-button {
            width: 100% !important;
            justify-content: center !important;
            padding: 10px 16px !important;
            font-size: 12px !important;
        }
        
        /* Activity Card */
        .activity-card {
            padding: 24px 20px !important;
            margin-bottom: 20px !important;
        }
        
        /* Status Panel */
        .activity-status-panel {
            padding: 16px 18px !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 12px !important;
        }
        
        /* Details Grid */
        .details-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)) !important;
            gap: 12px !important;
        }
        
        .detail-item {
            padding: 12px !important;
        }
        
        .detail-item .value {
            font-size: 13px !important;
        }
        
        /* Actions */
        .actions {
            flex-direction: column !important;
            gap: 8px !important;
        }
        
        .actions a {
            width: 100% !important;
            justify-content: center !important;
            padding: 12px 16px !important;
        }
        
        /* Subactivity Grid */
        .subactivity-grid {
            grid-template-columns: 1fr !important;
            gap: 12px !important;
        }
    }
    
    /* Mobile View (480px - 768px) */
    @media screen and (max-width: 768px) {
        .parent-activity-container {
            margin-top: 50px !important;
        }
        
        /* Sidebar */
        #activity-sidebar,
        .activity-sidebar {
            width: 100% !important;
            max-width: 280px !important;
        }
        
        .sidebar-header {
            padding: 16px !important;
        }
        
        .sidebar-header h2 {
            font-size: 16px !important;
        }
        
        .course-name {
            font-size: 12px !important;
        }
        
        .sidebar-content {
            padding: 12px 0 !important;
        }
        
        .sidebar-section {
            margin-bottom: 20px !important;
        }
        
        .sidebar-section-title {
            padding: 6px 16px !important;
            font-size: 9px !important;
        }
        
        /* Close button inside sidebar */
        .sidebar-close-btn {
            display: flex !important;
            padding: 6px 8px !important;
            font-size: 14px !important;
        }
        
        /* Sidebar Items */
        .activity-sidebar-item {
            padding: 10px 16px !important;
            margin: 1px 0 !important;
        }
        
        .activity-sidebar-item-icon {
            width: 32px !important;
            height: 32px !important;
            font-size: 12px !important;
        }
        
        .activity-sidebar-item-name {
            font-size: 12px !important;
            margin-bottom: 2px !important;
        }
        
        .activity-sidebar-item-type {
            font-size: 10px !important;
        }
        
        .activity-sidebar-item-content {
            gap: 10px !important;
        }
        
        /* Topbar */
        .activity-topbar {
            padding: 12px 16px !important;
        }
        
        .topbar-title {
            font-size: 18px !important;
            margin-bottom: 10px !important;
        }
        
        .topbar-breadcrumbs {
            font-size: 10px !important;
            gap: 4px !important;
        }
        
        .topbar-badges {
            flex-direction: column !important;
            width: 100% !important;
        }
        
        .topbar-badge {
            width: 100% !important;
            justify-content: center !important;
            padding: 8px 12px !important;
            font-size: 11px !important;
        }
        
        /* Activity Card */
        .activity-card {
            padding: 16px !important;
            margin-bottom: 16px !important;
        }
        
        .activity-card::before {
            height: 120px !important;
        }
        
        /* Status Panel */
        .activity-status-panel {
            padding: 12px 14px !important;
            border-radius: 12px !important;
        }
        
        .status-metric {
            font-size: 10px !important;
        }
        
        .status-metric strong {
            font-size: 12px !important;
        }
        
        /* Details Grid */
        .details-grid {
            grid-template-columns: 1fr !important;
            gap: 10px !important;
            padding-top: 16px !important;
            margin-top: 16px !important;
        }
        
        .detail-item {
            padding: 10px !important;
        }
        
        .detail-item .label {
            font-size: 10px !important;
        }
        
        .detail-item .value {
            font-size: 12px !important;
        }
        
        /* Activity Content */
        .activity-content {
            padding-top: 20px !important;
            margin-top: 20px !important;
            font-size: 13px !important;
        }
        
        /* Notice */
        .notice {
            padding: 14px 16px !important;
            font-size: 12px !important;
            margin-top: 20px !important;
        }
        
        /* URL Resource */
        .url-resource-embed,
        .url-resource-embed iframe {
            min-height: 400px !important;
            height: 400px !important;
        }
        
        /* Timeline */
        .timeline-section {
            margin-top: 24px !important;
        }
        
        .timeline-section h3 {
            font-size: 14px !important;
        }
        
        .timeline-item {
            padding-left: 36px !important;
            gap: 12px !important;
            margin-bottom: 14px !important;
        }
        
        .timeline-icon {
            width: 28px !important;
            height: 28px !important;
            font-size: 12px !important;
        }
        
        .timeline-content {
            padding: 10px 12px !important;
        }
        
        .timeline-content .label {
            font-size: 12px !important;
        }
        
        .timeline-content .date {
            font-size: 11px !important;
        }
        
        /* Quiz Attempts Table */
        .quiz-attempts-section {
            margin-top: 24px !important;
            padding-top: 20px !important;
        }
        
        .quiz-attempts-section h3 {
            font-size: 16px !important;
            margin-bottom: 16px !important;
        }
        
        .quiz-attempts-table {
            display: block !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
        }
        
        .quiz-attempts-table th,
        .quiz-attempts-table td {
            padding: 10px 12px !important;
            font-size: 12px !important;
            white-space: nowrap !important;
        }
        
        .quiz-attempts-table th {
            font-size: 10px !important;
        }
        
        /* File Lists */
        .file-list,
        .folder-list {
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
        }
        
        .file-item,
        .folder-item {
            padding: 12px 14px !important;
        }
        
        /* Subactivity Cards */
        .subactivity-card {
            padding: 12px !important;
        }
        
        .subactivity-name {
            font-size: 13px !important;
        }
        
        .subactivity-type {
            font-size: 10px !important;
        }
        
        /* Completion Status */
        .completion-status {
            padding: 6px 12px !important;
            font-size: 11px !important;
        }
        
        /* Availability Info */
        .availability-info {
            padding: 10px 14px !important;
            font-size: 11px !important;
        }
        
        /* Toggle Button */
        .activity-sidebar-toggle {
            top: 15px !important;
            left: 15px !important;
            padding: 10px 12px !important;
            font-size: 16px !important;
        }
    }
    
    /* Small Mobile View (≤480px) */
    @media screen and (max-width: 480px) {
        .parent-activity-container {
            margin-top: 40px !important;
        }
        
        /* Sidebar */
        .sidebar-header {
            padding: 12px !important;
        }
        
        .sidebar-header h2 {
            font-size: 14px !important;
        }
        
        .course-name {
            font-size: 11px !important;
        }
        
        .sidebar-content {
            padding: 10px 0 !important;
        }
        
        .sidebar-section {
            margin-bottom: 16px !important;
        }
        
        .sidebar-section-title {
            padding: 6px 12px !important;
            font-size: 8px !important;
        }
        
        /* Sidebar Items */
        .activity-sidebar-item {
            padding: 8px 12px !important;
        }
        
        .activity-sidebar-item-icon {
            width: 28px !important;
            height: 28px !important;
            font-size: 11px !important;
        }
        
        .activity-sidebar-item-name {
            font-size: 11px !important;
        }
        
        .activity-sidebar-item-type {
            font-size: 9px !important;
        }
        
        .activity-sidebar-item-content {
            gap: 8px !important;
        }
        
        /* Close button inside sidebar */
        .sidebar-close-btn {
            display: flex !important;
            padding: 5px 7px !important;
            font-size: 12px !important;
        }
        
        /* Topbar */
        .activity-topbar {
            padding: 10px 12px !important;
        }
        
        .topbar-title {
            font-size: 16px !important;
        }
        
        .topbar-breadcrumbs {
            font-size: 9px !important;
        }
        
        /* Activity Card */
        .activity-card {
            padding: 12px !important;
        }
        
        .activity-card::before {
            height: 100px !important;
        }
        
        /* Status Panel */
        .activity-status-panel {
            padding: 10px 12px !important;
        }
        
        /* Details Grid */
        .details-grid {
            gap: 8px !important;
        }
        
        .detail-item {
            padding: 8px !important;
        }
        
        /* Activity Content */
        .activity-content {
            font-size: 12px !important;
            line-height: 1.6 !important;
        }
        
        /* URL Resource */
        .url-resource-embed,
        .url-resource-embed iframe {
            min-height: 300px !important;
            height: 300px !important;
        }
        
        /* Timeline */
        .timeline-item {
            padding-left: 32px !important;
            gap: 10px !important;
            margin-bottom: 12px !important;
        }
        
        .timeline-icon {
            width: 24px !important;
            height: 24px !important;
            font-size: 10px !important;
        }
        
        .timeline-content {
            padding: 8px 10px !important;
        }
        
        .timeline-content .label {
            font-size: 11px !important;
        }
        
        .timeline-content .date {
            font-size: 10px !important;
        }
        
        /* Quiz Attempts Table */
        .quiz-attempts-section h3 {
            font-size: 14px !important;
        }
        
        .quiz-attempts-table th,
        .quiz-attempts-table td {
            padding: 8px 10px !important;
            font-size: 11px !important;
        }
        
        .quiz-attempts-table th {
            font-size: 9px !important;
        }
        
        /* Toggle Button */
        .activity-sidebar-toggle {
            top: 12px !important;
            left: 12px !important;
            padding: 8px 10px !important;
            font-size: 14px !important;
        }
        
        /* General Text Adjustments */
        body {
            font-size: 14px !important;
        }
        
        h1, h2, h3, h4 {
            font-size: 1.1em !important;
        }
        
        /* Images and Media */
        img {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* All Grids to Single Column */
        [style*="grid-template-columns"],
        [class*="grid"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<!-- Global Parent Sidebar Toggle Button -->
<button class="global-sidebar-toggle-btn" id="global-sidebar-toggle" aria-label="Toggle global sidebar" title="Toggle navigation sidebar">
    <i class="fas fa-chevron-left"></i>
</button>

<!-- Activity Sidebar Toggle Button (Mobile/Tablet) -->
<button class="activity-sidebar-toggle" id="activity-sidebar-toggle" onclick="toggleActivitySidebar()" aria-label="Toggle Activity Sidebar" title="Show/Hide Menu">
    <i class="fas fa-arrow-right" id="sidebar-toggle-icon"></i>
</button>

<!-- Activity Sidebar Overlay -->
<div class="activity-sidebar-overlay" id="activity-sidebar-overlay" onclick="toggleActivitySidebar()"></div>

<div class="parent-activity-container" id="activity-container">
    <div class="activity-main-wrapper">
    <!-- Sidebar with all activities -->
    <div class="activity-sidebar" id="activity-sidebar">
        <div class="sidebar-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <h2 style="margin: 0; flex: 1;">
                    <i class="fas fa-list"></i>
                    Course Activities
                </h2>
                <!-- Close button inside sidebar (visible on mobile/tablet) -->
                <button class="sidebar-close-btn" onclick="toggleActivitySidebar()" aria-label="Close Sidebar" title="Close Sidebar" style="display: none; background: rgba(148, 163, 184, 0.1); border: none; border-radius: 8px; padding: 8px 10px; cursor: pointer; color: #64748b; transition: all 0.3s ease; margin-left: 12px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="course-name"><?php echo s(format_string($course->fullname)); ?></div>
        </div>
        <div class="sidebar-content">
            <?php
            // Group activities by section
            $activities_by_section = [];
            foreach ($allactivities as $act) {
                $sectionkey = $act['section'] ?: 'Other';
                if (!isset($activities_by_section[$sectionkey])) {
                    $activities_by_section[$sectionkey] = [];
                }
                $activities_by_section[$sectionkey][] = $act;
            }
            
            // Display activities grouped by section
            foreach ($activities_by_section as $sectionname => $sectionactivities):
            ?>
                <div class="sidebar-section">
                    <div class="sidebar-section-title"><?php echo s($sectionname); ?></div>
                    <?php foreach ($sectionactivities as $act): ?>
                        <a href="<?php echo $act['url']; ?>" 
                           class="activity-sidebar-item <?php echo ($cmid && $act['id'] == $cmid) ? 'active' : ''; ?>">
                            <div class="activity-sidebar-item-content">
                                <div class="activity-sidebar-item-icon">
                                    <?php if (!empty($act['icon'])): ?>
                                        <img src="<?php echo $act['icon']; ?>" alt="" />
                                    <?php else: ?>
                                        <i class="fas fa-shapes"></i>
                <?php endif; ?>
            </div>
                                <div class="activity-sidebar-item-text">
                                    <div class="activity-sidebar-item-name"><?php echo s($act['name']); ?></div>
                                    <div class="activity-sidebar-item-type">
                                        <i class="fas fa-tag"></i> <?php echo s($act['modname']); ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($allactivities)): ?>
                <div style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                    <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
                    <p style="margin: 0; font-size: 13px;">No activities available</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="parent-activity-wrap">
        <div class="activity-topbar">
            <div class="topbar-left">
                <div class="topbar-breadcrumbs">
                    <a href="<?php echo (new moodle_url('/theme/remui_kids/parent/parent_children.php'))->out(); ?>">My Children</a>
                    <span>›</span>
                    <a href="<?php echo $backtocourse->out(); ?>"><?php echo s(format_string($course->shortname ?: $course->fullname)); ?></a>
                    <span>›</span>
                    <span>Activity Preview</span>
                </div>
                <div class="topbar-title"><?php echo s($activitytitle ?: 'Select an activity'); ?></div>
                <div class="topbar-badges">
                    <span class="topbar-badge">
                        <i class="fas fa-child"></i>
                        <?php echo s($selectedchild['name']); ?>
            </span>
                    <span class="topbar-badge">
                        <i class="fas fa-layer-group"></i>
                        <?php echo s($modname); ?>
                    </span>
                    <?php if (!empty($sectionname)): ?>
                        <span class="topbar-badge">
                            <i class="fas fa-bookmark"></i>
                            <?php echo s($sectionname); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="topbar-actions">
                
                <a class="topbar-button primary" href="<?php echo $backtocourse->out(); ?>">
                    <i class="fas fa-arrow-left"></i>
                    Back to course
                </a>
            </div>
        </div>
        <?php if (!$activityavailable && empty($allactivities)): ?>
            <div class="activity-card">
                <div style="text-align: center; padding: 60px 40px;">
                    <i class="fas fa-inbox" style="font-size: 64px; color: #cbd5e1; margin-bottom: 20px;"></i>
                    <h2 style="margin: 0 0 12px; color: #0f172a; font-size: 20px;">No Activities Available</h2>
                    <p style="margin: 0 0 24px; color: #64748b; font-size: 14px;">This course doesn't have any activities yet.</p>
                    <a href="<?php echo $backtocourse->out(); ?>" class="primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 13px; text-decoration: none; font-weight: 600; background: #64748b; color: #ffffff;">
                        <i class="fas fa-arrow-left"></i>Back to course
                    </a>
                </div>
            </div>
        <?php elseif (!$activityavailable): ?>
            <div class="activity-card">
                <div style="text-align: center; padding: 60px 40px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #f59e0b; margin-bottom: 20px;"></i>
                    <h2 style="margin: 0 0 12px; color: #0f172a; font-size: 20px;">Activity Not Found</h2>
                    <p style="margin: 0 0 24px; color: #64748b; font-size: 14px;">The selected activity may have been removed or is no longer accessible.</p>
                    <p style="margin: 0 0 24px; color: #64748b; font-size: 14px;">Please select an activity from the sidebar to view its details.</p>
                    <a href="<?php echo $backtocourse->out(); ?>" class="primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 13px; text-decoration: none; font-weight: 600; background: #64748b; color: #ffffff;">
                        <i class="fas fa-arrow-left"></i>Back to course
                    </a>
                </div>
            </div>
        <?php else: ?>
    <div class="activity-card">
        <?php if ($activitynotice): ?>
            <div class="notice" style="background: rgba(248, 113, 113, 0.12); border-color: rgba(239, 68, 68, 0.4); color: #b91c1c;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo s($activitynotice); ?>
            </div>
                <?php endif; ?>

        <div class="activity-status-panel">
            <div class="status-lead">
                <span>Activity status</span>
                <span class="status-pill status-<?php echo s($activitystatus['key']); ?>">
                    <i class="fas fa-<?php echo s($activitystatus['icon']); ?>"></i>
                    <?php echo s($activitystatus['label']); ?>
                </span>
                <small><?php echo s($activitystatus['helper']); ?></small>
                    </div>
            <div class="status-metrics">
                <div class="status-metric">
                    <span>Started</span>
                    <strong>
                        <?php echo $activitystatus['timestarted']
                            ? userdate($activitystatus['timestarted'], get_string('strftimedatetimeshort', 'langconfig'))
                            : 'Not started yet'; ?>
                    </strong>
            </div>
                <div class="status-metric">
                    <span>Completion</span>
                    <strong>
                        <?php echo $activitystatus['timecompleted']
                            ? userdate($activitystatus['timecompleted'], get_string('strftimedatetimeshort', 'langconfig'))
                            : 'Awaiting completion'; ?>
                    </strong>
                </div>
                <div class="status-metric">
                    <span>Last update</span>
                    <strong>
                        <?php echo $activitystatus['lastupdated']
                            ? userdate($activitystatus['lastupdated'], get_string('strftimedatetimeshort', 'langconfig'))
                            : 'No updates yet'; ?>
                    </strong>
                </div>
            </div>
        </div>


        

        

        

        <?php if (!empty($currentsectionactivities)): ?>
            <div class="section-subactivities">
                <h3>
                    <i class="fas fa-layer-group"></i>
            <?php
                        $sectionheading = $sectionname ?: get_string('section');
                        echo get_string('activities') . ' in ' . s($sectionheading);
                    ?>
                </h3>
                <div class="subactivity-grid">
                    <?php foreach ($currentsectionactivities as $relatedactivity): ?>
                        <?php
                            $relatedurl = $relatedactivity['url'];
                            $iscurrent = !empty($relatedactivity['iscurrent']);
                            $statusmeta = $relatedactivity['status'] ?? null;
                        ?>
                        <div class="subactivity-card <?php echo $iscurrent ? 'current' : ''; ?>">
                            <div class="subactivity-name">
                                <?php if (!empty($relatedactivity['icon'])): ?>
                                    <img src="<?php echo $relatedactivity['icon']; ?>" alt="" style="width:20px;height:20px;"/>
                                <?php else: ?>
                                    <i class="fas fa-shapes"></i>
                                <?php endif; ?>
                                <span><?php echo s($relatedactivity['name']); ?></span>
                </div>
                            <div class="subactivity-type">
                                <i class="fas fa-tag"></i>
                                <?php echo s($relatedactivity['modname']); ?>
            </div>
                            <?php if ($statusmeta): ?>
                                <span class="subactivity-status <?php echo s($statusmeta['badgeclass']); ?>">
                                    <i class="fas fa-<?php echo s($statusmeta['icon']); ?>"></i>
                                    <?php echo s($statusmeta['label']); ?>
                                </span>
        <?php endif; ?>
                            <div class="subactivity-actions">
                                <?php if ($iscurrent): ?>
                                    <span class="current-pill"><?php echo get_string('current'); ?></span>
        <?php endif; ?>
                                <a href="<?php echo $relatedurl; ?>">
                                    <i class="fas fa-eye"></i>
                                    <?php echo get_string('view'); ?>
                                </a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($subsectionchildren['activities'])): ?>
            <div class="section-subactivities">
                <h3>
                    <i class="fas fa-sitemap"></i>
                    <?php 
                        $subheading = $subsectionchildren['title'] ?: $activityname;
                        echo get_string('activities') . ' – ' . s($subheading);
                    ?>
                </h3>
                <div class="subactivity-grid">
                    <?php foreach ($subsectionchildren['activities'] as $subchild): ?>
                        <div class="subactivity-card <?php echo !empty($subchild['iscurrent']) ? 'current' : ''; ?>">
                            <div class="subactivity-name">
                                <?php if (!empty($subchild['icon'])): ?>
                                    <img src="<?php echo $subchild['icon']; ?>" alt="" style="width:20px;height:20px;">
                                <?php else: ?>
                                    <i class="fas fa-shapes"></i>
                                <?php endif; ?>
                                <span><?php echo s($subchild['name']); ?></span>
                            </div>
                            <div class="subactivity-type">
                                <i class="fas fa-layer-group"></i>
                                <?php echo s($subchild['modname']); ?>
                            </div>
                            <?php if (!empty($subchild['status'])): ?>
                                <span class="subactivity-status <?php echo s($subchild['status']['badgeclass']); ?>">
                                    <i class="fas fa-<?php echo s($subchild['status']['icon']); ?>"></i>
                                    <?php echo s($subchild['status']['label']); ?>
                                </span>
                            <?php endif; ?>
                            <div class="subactivity-actions">
                                <?php if (!empty($subchild['iscurrent'])): ?>
                                    <span class="current-pill"><?php echo get_string('current'); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo $subchild['url']; ?>">
                                    <i class="fas fa-eye"></i>
                                    <?php echo get_string('view'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($quizattempts) && $quizinfo): ?>
            <div class="quiz-attempts-section">
                <h3>
                    <i class="fas fa-list-alt"></i>
                    Quiz Attempts History
                </h3>
                <?php if (count($quizattempts) > 0): ?>
                    <table class="quiz-attempts-table">
                        <thead>
                            <tr>
                                <th>Attempt</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Completed</th>
                                <th>Duration</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizattempts as $attempt): ?>
                                <tr>
                                    <td><strong>Attempt <?php echo $attempt['attempt']; ?></strong></td>
                                    <td>
                                        <span class="attempt-state <?php echo $attempt['state']; ?>">
                                            <i class="fas fa-<?php 
                                                echo $attempt['state'] == 'finished' ? 'check-circle' : 
                                                    ($attempt['state'] == 'inprogress' ? 'clock' : 
                                                    ($attempt['state'] == 'overdue' ? 'exclamation-triangle' : 'times')); 
                                            ?>"></i>
                                            <?php echo s($attempt['state_display']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($attempt['timestart']): ?>
                                            <?php echo userdate($attempt['timestart'], get_string('strftimedatetimeshort', 'langconfig')); ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($attempt['timefinish']): ?>
                                            <?php echo userdate($attempt['timefinish'], get_string('strftimedatetimeshort', 'langconfig')); ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($attempt['duration']): ?>
                                            <?php 
                                                $minutes = floor($attempt['duration'] / 60);
                                                $seconds = $attempt['duration'] % 60;
                                                echo $minutes . 'm ' . $seconds . 's';
                                            ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($attempt['sumgrades'] !== null && $attempt['maxgrade'] > 0): ?>
                                            <span class="attempt-grade <?php 
                                                echo $attempt['percentage'] >= 80 ? 'good' : 
                                                    ($attempt['percentage'] >= 50 ? 'average' : 'poor'); 
                                            ?>">
                                                <?php echo round($attempt['sumgrades'], 1); ?>/<?php echo round($attempt['maxgrade'], 1); ?>
                                                <?php if ($attempt['percentage'] !== null): ?>
                                                    (<?php echo $attempt['percentage']; ?>%)
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">Not graded</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #64748b; font-size: 14px; padding: 20px; text-align: center; background: #f8fafc; border-radius: 8px;">
                        <i class="fas fa-inbox" style="margin-right: 8px;"></i>
                        No attempts have been made yet.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($resourceinfo) && !empty($resourceinfo['file'])): ?>
            <div class="resource-display-section">
                <h3>
                    <i class="fas fa-file"></i>
                    Resource File
                </h3>
                <div class="resource-file-card">
                    <div class="resource-file-info">
                        <div class="resource-file-icon">
                            <?php 
                            $fileicon = 'file-alt';
                            if ($resourceinfo['file']['previewtype'] == 'image') $fileicon = 'image';
                            elseif ($resourceinfo['file']['previewtype'] == 'pdf') $fileicon = 'file-pdf';
                            elseif ($resourceinfo['file']['previewtype'] == 'video') $fileicon = 'video';
                            elseif ($resourceinfo['file']['previewtype'] == 'audio') $fileicon = 'music';
                            elseif ($resourceinfo['file']['previewtype'] == 'office') $fileicon = 'file-word';
                            ?>
                            <i class="fas fa-<?php echo $fileicon; ?>"></i>
                        </div>
                        <div class="resource-file-details">
                            <div class="resource-file-name"><?php echo s($resourceinfo['file']['filename']); ?></div>
                            <div class="resource-file-meta">
                                <span><i class="fas fa-hdd"></i> <?php echo display_size($resourceinfo['file']['filesize']); ?></span>
                                <span><i class="fas fa-file-alt"></i> <?php echo s($resourceinfo['file']['mimetype']); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="resource-file-actions">
                        <a href="<?php echo s($resourceinfo['file']['fileurl']); ?>" target="_blank" class="primary">
                            <i class="fas fa-external-link-alt"></i> Open in New Tab
                        </a>
                        <a href="<?php echo s($resourceinfo['file']['downloadurl']); ?>" class="secondary">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                
                
            </div>
        <?php endif; ?>

        <?php if (!empty($pageinfo)): ?>
            <div class="resource-display-section">
                <h3>
                    <i class="fas fa-file-alt"></i>
                    Page Content
                </h3>
                <div class="page-content-display">
                    <?php echo format_text($pageinfo['content'], $pageinfo['contentformat'], ['context' => $coursecontext]); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($folderinfo)): ?>
            <div class="resource-display-section">
                <h3>
                    <i class="fas fa-folder"></i>
                    Folder Contents
                </h3>
                <?php if (!empty($folderinfo['files'])): ?>
                    <p style="color: #64748b; font-size: 14px; margin-bottom: 16px;">
                        This folder contains <?php echo $folderinfo['filecount']; ?> file<?php echo $folderinfo['filecount'] != 1 ? 's' : ''; ?>.
                    </p>
                    <div class="folder-files-list">
                        <?php foreach ($folderinfo['files'] as $folderfile): ?>
                            <div class="folder-file-item">
                                <div class="folder-file-icon">
                                    <?php
                                    $folderfileicon = 'file-alt';
                                    if ($folderfile['previewtype'] === 'image') {
                                        $folderfileicon = 'image';
                                    } else if ($folderfile['previewtype'] === 'pdf') {
                                        $folderfileicon = 'file-pdf';
                                    } else if ($folderfile['previewtype'] === 'video') {
                                        $folderfileicon = 'video';
                                    } else if ($folderfile['previewtype'] === 'audio') {
                                        $folderfileicon = 'music';
                                    } else if ($folderfile['previewtype'] === 'office') {
                                        $folderfileicon = 'file-word';
                                    }
                                    ?>
                                    <i class="fas fa-<?php echo $folderfileicon; ?>"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="folder-file-name">
                                        <?php echo s($folderfile['filename']); ?>
                                    </div>
                                    <div class="resource-file-meta" style="margin-top: 4px;">
                                        <span><i class="fas fa-hdd"></i> <?php echo display_size($folderfile['filesize']); ?></span>
                                        <span><i class="fas fa-file-alt"></i> <?php echo s($folderfile['mimetype']); ?></span>
                                    </div>
                                </div>
                                <div class="resource-file-actions" style="padding: 0;">
                                    <a href="<?php echo s($folderfile['fileurl']); ?>" target="_blank" class="secondary" style="padding: 6px 12px;">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                    <a href="<?php echo s($folderfile['downloadurl']); ?>" class="secondary" style="padding: 6px 12px;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: #64748b; font-size: 14px; padding: 20px; text-align: center; background: #f8fafc; border-radius: 8px;">
                        <i class="fas fa-inbox" style="margin-right: 8px;"></i>
                        This folder is empty.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$activityavailable && !empty($allactivities)): ?>
            <div style="margin-top: 32px; padding-top: 24px; border-top: 2px solid #e2e8f0;">
                <h3 style="margin: 0 0 20px; font-size: 18px; color: #0f172a; font-weight: 700;">
                    <i class="fas fa-list"></i> All Activities in This Course
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                    <?php foreach ($allactivities as $act): ?>
                        <a href="<?php echo $act['url']; ?>" style="display: block; padding: 16px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 12px; border: 1px solid #e2e8f0; text-decoration: none; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
                            <div style="display: flex; align-items: start; gap: 12px;">
                                <?php if (!empty($act['icon'])): ?>
                                    <img src="<?php echo $act['icon']; ?>" alt="" style="width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px;" />
                                <?php else: ?>
                                    <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid #e2e8f0;">
                                        <i class="fas fa-shapes" style="color: #64748b; font-size: 14px;"></i>
                                    </div>
                                <?php endif; ?>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 4px; line-height: 1.4;">
                                        <?php echo s($act['name']); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; margin-bottom: 6px;">
                                        <i class="fas fa-tag"></i> <?php echo s($act['modname']); ?>
                                    </div>
                                    <?php if (!empty($act['section'])): ?>
                                        <div style="font-size: 11px; color: #94a3b8;">
                                            <i class="fas fa-layer-group"></i> <?php echo s($act['section']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        
    </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Toggle Activity Sidebar Function
function toggleActivitySidebar() {
    var sidebar = document.getElementById('activity-sidebar');
    var overlay = document.getElementById('activity-sidebar-overlay');
    var toggleIcon = document.getElementById('sidebar-toggle-icon');
    
    if (sidebar) {
        var isOpen = sidebar.classList.toggle('open');
        
        // Change arrow direction based on sidebar state
        if (toggleIcon) {
            if (isOpen) {
                toggleIcon.className = 'fas fa-arrow-left';
            } else {
                toggleIcon.className = 'fas fa-arrow-right';
            }
        }
        
        // Toggle overlay on mobile/tablet
        if (overlay && window.innerWidth <= 1024) {
            overlay.classList.toggle('active');
        }
    }
}

(function() {
    // Global Parent Sidebar Toggle
    const globalToggleBtn = document.getElementById('global-sidebar-toggle');
    const globalSidebar = document.getElementById('parent-sidebar');
    
    if (globalToggleBtn) {
        // Load saved global sidebar state
        const savedGlobalState = localStorage.getItem('global-sidebar-collapsed');
        if (savedGlobalState === 'true') {
            document.body.classList.add('global-sidebar-collapsed');
        }
        
        // Toggle global sidebar
        globalToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            document.body.classList.toggle('global-sidebar-collapsed');
            const isCollapsed = document.body.classList.contains('global-sidebar-collapsed');
            localStorage.setItem('global-sidebar-collapsed', isCollapsed ? 'true' : 'false');
        });
    }
    
    // Initialize activity sidebar on page load
    var activitySidebar = document.getElementById('activity-sidebar');
    var activityOverlay = document.getElementById('activity-sidebar-overlay');
    
    // Ensure activity sidebar is hidden on mobile/tablet on page load
    if (activitySidebar && window.innerWidth <= 1024) {
        activitySidebar.classList.remove('open');
        // Force hide with inline style
        activitySidebar.style.transform = 'translateX(-100%)';
        activitySidebar.style.webkitTransform = 'translateX(-100%)';
        activitySidebar.style.mozTransform = 'translateX(-100%)';
        activitySidebar.style.msTransform = 'translateX(-100%)';
    }
    
    // Close activity sidebar when clicking overlay
    if (activityOverlay) {
        activityOverlay.addEventListener('click', function() {
            if (activitySidebar && activitySidebar.classList.contains('open')) {
                toggleActivitySidebar();
            }
        });
    }
    
    // Handle window resize
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            var toggleIcon = document.getElementById('sidebar-toggle-icon');
            if (window.innerWidth > 1024) {
                // Desktop: show activity sidebar
                if (activitySidebar) {
                    activitySidebar.classList.remove('open');
                    activitySidebar.style.transform = 'translateX(0)';
                    activitySidebar.style.webkitTransform = 'translateX(0)';
                    activitySidebar.style.mozTransform = 'translateX(0)';
                    activitySidebar.style.msTransform = 'translateX(0)';
                }
                // Reset arrow icon
                if (toggleIcon) {
                    toggleIcon.className = 'fas fa-arrow-right';
                }
            } else {
                // Mobile/Tablet: hide sidebar by default
                if (activitySidebar && !activitySidebar.classList.contains('open')) {
                    activitySidebar.style.transform = 'translateX(-100%)';
                    activitySidebar.style.webkitTransform = 'translateX(-100%)';
                    activitySidebar.style.mozTransform = 'translateX(-100%)';
                    activitySidebar.style.msTransform = 'translateX(-100%)';
                }
                // Reset arrow icon
                if (toggleIcon && !activitySidebar.classList.contains('open')) {
                    toggleIcon.className = 'fas fa-arrow-right';
                }
            }
        }, 100);
            if (activityOverlay) {
                activityOverlay.classList.remove('active');
            }
        } else {
            // Mobile/Tablet: hide activity sidebar if not manually opened
            if (activitySidebar && !activitySidebar.classList.contains('open')) {
                activitySidebar.style.transform = 'translateX(-100%)';
                activitySidebar.style.webkitTransform = 'translateX(-100%)';
                activitySidebar.style.mozTransform = 'translateX(-100%)';
                activitySidebar.style.msTransform = 'translateX(-100%)';
            }
            if (activityOverlay) {
                activityOverlay.classList.remove('active');
            }
        }
    });
    
})();

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




