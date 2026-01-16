<?php
/**
 * Get Student Videos API
 * Fetches all video activities for a specific student with their completion status
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

header('Content-Type: application/json');

// Get student ID and optional course ID from request
$student_id = required_param('student_id', PARAM_INT);
$course_id = optional_param('course_id', 0, PARAM_INT);

// Check if user has company manager role
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get company information
$company_info = $DB->get_record_sql(
    "SELECT c.* FROM {company} c JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    echo json_encode(['error' => 'Company not found']);
    exit;
}

// Verify student belongs to this company
$student_company = $DB->get_record_sql(
    "SELECT cu.companyid FROM {company_users} cu 
     WHERE cu.userid = ? AND cu.companyid = ?",
    [$student_id, $company_info->id]
);

if (!$student_company) {
    echo json_encode(['error' => 'Student not found in your company']);
    exit;
}

// Build course filter
$course_filter = "";
$params = [$student_id, $company_info->id];
if ($course_id > 0) {
    $course_filter = "AND c.id = ?";
    $params[] = $course_id;
}

// Get all video activities for this student's enrolled courses
$videos_sql = "SELECT 
                    ev.id as video_id,
                    ev.name as video_name,
                    ev.sourcetype,
                    ev.sourcepath,
                    ev.intro,
                    ev.introformat,
                    ev.timecreated,
                    ev.timemodified,
                    cm.id as cmid,
                    cm.course as courseid,
                    cm.completion,
                    cm.visible,
                    c.fullname as course_name,
                    c.shortname as course_shortname,
                    cmc.completionstate,
                    cmc.timemodified as completion_time,
                    cs.name as section_name
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                JOIN {edwiservideoactivity} ev ON ev.id = cm.instance
                JOIN {course} c ON c.id = cm.course
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                JOIN {company_course} cc_link ON cc_link.courseid = c.id
                LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = ?
                LEFT JOIN {course_sections} cs ON cs.id = cm.section AND cs.course = c.id
                WHERE ue.userid = ?
                AND cc_link.companyid = ?
                AND m.name = 'edwiservideoactivity'
                AND cm.visible = 1
                AND cm.deletioninprogress = 0
                AND ue.status = 0
                AND c.id > 1
                {$course_filter}
                ORDER BY c.fullname, cs.section, cm.id";

$videos = $DB->get_records_sql($videos_sql, $params);

$videos_array = [];
foreach ($videos as $video) {
    // Determine video completion status
    $is_completed = false;
    $completion_time = null;
    
    if ($video->completionstate > 0) {
        $is_completed = true;
        $completion_time = $video->completion_time ? date('M d, Y', $video->completion_time) : null;
    }
    
    // Format video source path
    $video_url = $video->sourcepath;
    $is_embedded = ($video->sourcetype == 'embed' || strpos($video->sourcepath, 'http') === 0);
    
    // If it's a file path, convert to moodle file URL
    if (!$is_embedded && strpos($video->sourcepath, 'http') !== 0) {
        $context = context_module::instance($video->cmid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_edwiservideoactivity', 'mediafile', 0, 'itemid, filepath, filename', false);
        
        if (!empty($files)) {
            $file = reset($files);
            $video_url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out();
        } else {
            // If no file found, try using the sourcepath as-is (might be a relative path)
            // Or construct URL from sourcepath if it's a pluginfile URL pattern
            if (strpos($video->sourcepath, '/pluginfile.php') !== false) {
                $video_url = $CFG->wwwroot . $video->sourcepath;
            } else {
                // Fallback: try to construct the URL
                $video_url = moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_edwiservideoactivity',
                    'mediafile',
                    0,
                    '/',
                    basename($video->sourcepath)
                )->out();
            }
        }
    }
    
    // Format intro text
    $intro_text = '';
    if (!empty($video->intro)) {
        $intro_text = format_text($video->intro, $video->introformat, ['context' => context_module::instance($video->cmid)]);
    }
    
    $videos_array[] = [
        'id' => $video->video_id,
        'cmid' => $video->cmid,
        'name' => $video->video_name,
        'course_id' => $video->courseid,
        'course_name' => $video->course_name,
        'course_shortname' => $video->course_shortname,
        'section_name' => $video->section_name ?: 'General',
        'video_url' => $video_url,
        'sourcetype' => $video->sourcetype,
        'is_embedded' => $is_embedded,
        'intro' => $intro_text,
        'is_completed' => $is_completed,
        'completion_time' => $completion_time,
        'created_date' => date('M d, Y', $video->timecreated),
        'course_url' => new moodle_url('/course/view.php', ['id' => $video->courseid])->out()
    ];
}

// Return JSON response
echo json_encode([
    'success' => true,
    'student_id' => $student_id,
    'videos' => $videos_array,
    'total_videos' => count($videos_array),
    'completed_videos' => count(array_filter($videos_array, function($v) { return $v['is_completed']; })),
    'in_progress_videos' => count(array_filter($videos_array, function($v) { return !$v['is_completed']; }))
]);

