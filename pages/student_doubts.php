<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once('../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');

use theme_remui_kids\local\doubts\constants;
use theme_remui_kids\local\doubts\student_service;
require_once(__DIR__ . '/../lib/cohort_sidebar_helper.php');
require_once(__DIR__ . '/../lib/sidebar_helper.php');
require_login();

if (!function_exists('theme_remui_kids_student_doubts_collect_uploads')) {
    /**
     * Normalize uploaded files array for multi-file inputs.
     *
     * @param string $key
     * @return array<int,array<string,mixed>>
     */
    function theme_remui_kids_student_doubts_collect_uploads(string $key): array {
        if (empty($_FILES[$key])) {
            return [];
        }

        $uploads = [];
        $filedata = $_FILES[$key];

        if (is_array($filedata['name'])) {
            foreach ($filedata['name'] as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $uploads[] = [
                    'name' => $name,
                    'tmp_name' => $filedata['tmp_name'][$idx],
                    'type' => $filedata['type'][$idx],
                    'error' => $filedata['error'][$idx],
                    'size' => $filedata['size'][$idx],
                ];
            }
        } else if (!empty($filedata['name'])) {
            $uploads[] = [
                'name' => $filedata['name'],
                'tmp_name' => $filedata['tmp_name'],
                'type' => $filedata['type'],
                'error' => $filedata['error'],
                'size' => $filedata['size'],
            ];
        }

        return $uploads;
    }
}

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/student_doubts.php'));
$PAGE->set_title(get_string('student_doubts', 'theme_remui_kids'));
$PAGE->set_heading(get_string('student_doubts', 'theme_remui_kids'));
$PAGE->set_pagelayout('base');

$service = new student_service();

$coursefilter = optional_param('course', 0, PARAM_INT);
$doubtid = optional_param('doubtid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);

if ($action === 'reply' && confirm_sesskey()) {
    require_sesskey();
    $replydoubtid = required_param('doubtid', PARAM_INT);
    $message = optional_param('message', '', PARAM_RAW);
    $uploads = theme_remui_kids_student_doubts_collect_uploads('replyattachments');

    try {
        $service->reply($replydoubtid, $USER->id, $message, $uploads);
        $redirecturl = new moodle_url('/theme/remui_kids/pages/student_doubts.php', ['doubtid' => $replydoubtid]);
        if ($coursefilter) {
            $redirecturl->param('course', $coursefilter);
        }
        redirect($redirecturl, get_string('student_doubt_reply_sent', 'theme_remui_kids'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $ex) {
        \core\notification::add(get_string($ex->errorcode, $ex->module, $ex->a ?? null), \core\output\notification::NOTIFY_ERROR);
        $doubtid = $replydoubtid;
    }
}

if ($action === 'create' && confirm_sesskey()) {
    require_sesskey();
    $subject = optional_param('subject', '', PARAM_TEXT);
    $details = optional_param('details', '', PARAM_RAW);
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $priority = optional_param('priority', constants::PRIORITY_NORMAL, PARAM_ALPHA);

    $uploads = theme_remui_kids_student_doubts_collect_uploads('attachments');

    try {
        $newid = $service->create($USER->id, $courseid, $subject, $details, $priority, $uploads);
        $redirecturl = new moodle_url('/theme/remui_kids/pages/student_doubts.php');
        if ($coursefilter) {
            $redirecturl->param('course', $coursefilter);
        }
        redirect($redirecturl, get_string('student_doubt_created', 'theme_remui_kids'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $ex) {
        \core\notification::add(get_string($ex->errorcode, $ex->module, $ex->a ?? null), \core\output\notification::NOTIFY_ERROR);
    }
}

$selectedcourseid = $coursefilter ?: null;

$offset = optional_param('page', 0, PARAM_INT);
$doubts = $service->list($USER->id, $selectedcourseid);

if (!$doubtid && !empty($doubts)) {
    $first = reset($doubts);
    $doubtid = $first['id'];
    reset($doubts);
}

require_once($CFG->libdir . '/enrollib.php');
$courses = enrol_get_users_courses($USER->id, true, 'id, fullname');

$courseoptions = [];
$courseoptions[] = [
    'id' => 0,
    'name' => get_string('student_doubts_filter_allcourses', 'theme_remui_kids'),
    'selectedfilter' => empty($coursefilter),
];

$cancreate = !empty($courses);
$createcourseid = 0;
if ($cancreate) {
    if ($coursefilter && isset($courses[$coursefilter])) {
        $createcourseid = $coursefilter;
    } else {
        $firstcourse = reset($courses);
        $createcourseid = $firstcourse->id;
    }
    reset($courses);
}

foreach ($courses as $course) {
    $courseoptions[] = [
        'id' => (int) $course->id,
        'name' => format_string($course->fullname, true),
        'selectedfilter' => ((int) $course->id === (int) $coursefilter),
        'selectedcreate' => ((int) $course->id === (int) $createcourseid),
    ];
}

$baseurl = new moodle_url('/theme/remui_kids/pages/student_doubts.php');
if ($coursefilter) {
    $baseurl->param('course', $coursefilter);
}

foreach ($doubts as $index => &$row) {
    $detailurl = clone $baseurl;
    $detailurl->param('doubtid', $row['id']);
    $row['url'] = $detailurl->out(false);
    $row['iscurrent'] = ((int) $row['id'] === (int) $doubtid);
}
unset($row);

$detail = null;
if ($doubtid) {
    try {
        $detail = $service->get_detail($doubtid, $USER->id);
    } catch (moodle_exception $ex) {
        debugging($ex->getMessage(), DEBUG_DEVELOPER);
    }
}

$priorityoptions = array_map(function(string $priority) {
    return [
        'value' => $priority,
        'label' => get_string('doubtpriority:' . $priority, 'theme_remui_kids'),
        'selected' => $priority === constants::PRIORITY_NORMAL,
    ];
}, constants::priorities());

// Determine dashboard type based on user's cohort
$dashboardtype = 'default';
try {
    $usercohorts = cohort_get_user_cohorts($USER->id);
    if (!empty($usercohorts)) {
        $cohort = reset($usercohorts);
        $cohortname = $cohort->name;
        // Check for Grade 8-12 (High School) - Check this first to avoid conflicts
        if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $cohortname)) {
            $dashboardtype = 'highschool';
        } elseif (preg_match('/grade\s*[4-7]/i', $cohortname)) {
            $dashboardtype = 'middle';
        } elseif (preg_match('/grade\s*[1-3]/i', $cohortname)) {
            $dashboardtype = 'elementary';
        }
    }
} catch (Exception $e) {
    // Default to 'default' if cohort check fails
}

// Use appropriate sidebar helper based on dashboard type
if ($dashboardtype === 'highschool') {
    require_once(__DIR__ . '/../lib/highschool_sidebar.php');
    $templatecontext = remui_kids_build_highschool_sidebar_context('askteacher', $USER, [
    'dashboardtype' => $dashboardtype,
    'elementary' => false,
    'middle' => false,
    'highschool' => true,
    'heading' => get_string('student_doubts', 'theme_remui_kids'),
    'subheading' => get_string('student_doubts_subheading', 'theme_remui_kids'),
    'formaction' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(false),
    'sesskey' => sesskey(),
    'courseoptions' => $courseoptions,
    'priorityoptions' => $priorityoptions,
    'doubts' => $doubts,
    'hasdoubts' => !empty($doubts),
    'detail' => $detail,
    'listheading' => get_string('student_doubts_list_heading', 'theme_remui_kids'),
    'student_doubts_create' => get_string('student_doubts_create', 'theme_remui_kids'),
    'student_doubts_subject' => get_string('student_doubts_subject', 'theme_remui_kids'),
    'student_doubts_course' => get_string('student_doubts_course', 'theme_remui_kids'),
    'student_doubts_priority' => get_string('student_doubts_priority', 'theme_remui_kids'),
    'student_doubts_details' => get_string('student_doubts_details', 'theme_remui_kids'),
    'student_doubts_submit' => get_string('student_doubts_submit', 'theme_remui_kids'),
    'student_doubts_table_subject' => get_string('student_doubts_table_subject', 'theme_remui_kids'),
    'student_doubts_table_course' => get_string('student_doubts_table_course', 'theme_remui_kids'),
    'student_doubts_table_status' => get_string('student_doubts_table_status', 'theme_remui_kids'),
    'student_doubts_table_priority' => get_string('student_doubts_table_priority', 'theme_remui_kids'),
    'student_doubts_table_lastupdate' => get_string('student_doubts_table_lastupdate', 'theme_remui_kids'),
    'student_doubts_none' => get_string('student_doubts_none', 'theme_remui_kids'),
    'student_doubts_no_courses' => get_string('student_doubts_no_courses', 'theme_remui_kids'),
    'student_doubts_no_messages' => get_string('student_doubts_no_messages', 'theme_remui_kids'),
    'student_doubts_conversation' => get_string('student_doubts_conversation', 'theme_remui_kids'),
    'student_doubts_reply_heading' => get_string('student_doubts_reply_heading', 'theme_remui_kids'),
    'student_doubts_reply_placeholder' => get_string('student_doubts_reply_placeholder', 'theme_remui_kids'),
    'student_doubts_reply_submit' => get_string('student_doubts_reply_submit', 'theme_remui_kids'),
    'student_doubts_reply_attachments' => get_string('student_doubts_reply_attachments', 'theme_remui_kids'),
    'detailstatuslabel' => get_string('doubt_status_label', 'theme_remui_kids'),
    'detailprioritylabel' => get_string('doubt_priority_label', 'theme_remui_kids'),
    'detailcourselabel' => get_string('doubt_course_label', 'theme_remui_kids'),
    'detailupdatedlabel' => get_string('student_doubts_table_lastupdate', 'theme_remui_kids'),
    'detaildescriptionlabel' => get_string('student_doubts_details', 'theme_remui_kids'),
    'student_doubts_attachments' => get_string('student_doubts_attachments', 'theme_remui_kids'),
    'student_doubts_attachments_help' => get_string('student_doubts_attachments_help', 'theme_remui_kids'),
    'attachmentslabel' => get_string('doubt_uploaded_files', 'theme_remui_kids'),
    'cancreate' => $cancreate,
    'hasdetail' => !empty($detail),
    ]);
} elseif ($dashboardtype === 'elementary') {
    // For elementary students, use elementary sidebar helper
    $templatecontext = theme_remui_kids_get_elementary_sidebar_context('askteacher', $USER);
    $templatecontext['heading'] = get_string('student_doubts', 'theme_remui_kids');
    $templatecontext['subheading'] = get_string('student_doubts_subheading', 'theme_remui_kids');
    $templatecontext['formaction'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(false);
    $templatecontext['sesskey'] = sesskey();
    $templatecontext['courseoptions'] = $courseoptions;
    $templatecontext['priorityoptions'] = $priorityoptions;
    $templatecontext['doubts'] = $doubts;
    $templatecontext['hasdoubts'] = !empty($doubts);
    $templatecontext['detail'] = $detail;
    $templatecontext['listheading'] = get_string('student_doubts_list_heading', 'theme_remui_kids');
    $templatecontext['student_doubts_create'] = get_string('student_doubts_create', 'theme_remui_kids');
    $templatecontext['student_doubts_subject'] = get_string('student_doubts_subject', 'theme_remui_kids');
    $templatecontext['student_doubts_course'] = get_string('student_doubts_course', 'theme_remui_kids');
    $templatecontext['student_doubts_priority'] = get_string('student_doubts_priority', 'theme_remui_kids');
    $templatecontext['student_doubts_details'] = get_string('student_doubts_details', 'theme_remui_kids');
    $templatecontext['student_doubts_submit'] = get_string('student_doubts_submit', 'theme_remui_kids');
    $templatecontext['student_doubts_table_subject'] = get_string('student_doubts_table_subject', 'theme_remui_kids');
    $templatecontext['student_doubts_table_course'] = get_string('student_doubts_table_course', 'theme_remui_kids');
    $templatecontext['student_doubts_table_status'] = get_string('student_doubts_table_status', 'theme_remui_kids');
    $templatecontext['student_doubts_table_priority'] = get_string('student_doubts_table_priority', 'theme_remui_kids');
    $templatecontext['student_doubts_table_lastupdate'] = get_string('student_doubts_table_lastupdate', 'theme_remui_kids');
    $templatecontext['student_doubts_none'] = get_string('student_doubts_none', 'theme_remui_kids');
    $templatecontext['student_doubts_no_courses'] = get_string('student_doubts_no_courses', 'theme_remui_kids');
    $templatecontext['student_doubts_no_messages'] = get_string('student_doubts_no_messages', 'theme_remui_kids');
    $templatecontext['student_doubts_conversation'] = get_string('student_doubts_conversation', 'theme_remui_kids');
    $templatecontext['student_doubts_reply_heading'] = get_string('student_doubts_reply_heading', 'theme_remui_kids');
    $templatecontext['student_doubts_reply_placeholder'] = get_string('student_doubts_reply_placeholder', 'theme_remui_kids');
    $templatecontext['student_doubts_reply_submit'] = get_string('student_doubts_reply_submit', 'theme_remui_kids');
    $templatecontext['student_doubts_reply_attachments'] = get_string('student_doubts_reply_attachments', 'theme_remui_kids');
    $templatecontext['detailstatuslabel'] = get_string('doubt_status_label', 'theme_remui_kids');
    $templatecontext['detailprioritylabel'] = get_string('doubt_priority_label', 'theme_remui_kids');
    $templatecontext['detailcourselabel'] = get_string('doubt_course_label', 'theme_remui_kids');
    $templatecontext['detailupdatedlabel'] = get_string('student_doubts_table_lastupdate', 'theme_remui_kids');
    $templatecontext['detaildescriptionlabel'] = get_string('student_doubts_details', 'theme_remui_kids');
    $templatecontext['student_doubts_attachments'] = get_string('student_doubts_attachments', 'theme_remui_kids');
    $templatecontext['student_doubts_attachments_help'] = get_string('student_doubts_attachments_help', 'theme_remui_kids');
    $templatecontext['attachmentslabel'] = get_string('doubt_uploaded_files', 'theme_remui_kids');
    $templatecontext['cancreate'] = $cancreate;
    $templatecontext['hasdetail'] = !empty($detail);
    $templatecontext['askteacherurl'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out();
    $templatecontext['dashboardtype'] = $dashboardtype;
    $templatecontext['elementary'] = true;
    $templatecontext['middle'] = false;
    $templatecontext['highschool'] = false;
} else {
    // For middle school and default, build context manually
    $templatecontext = [
    'heading' => get_string('student_doubts', 'theme_remui_kids'),
    'subheading' => get_string('student_doubts_subheading', 'theme_remui_kids'),
    'formaction' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(false),
    'sesskey' => sesskey(),
    'courseoptions' => $courseoptions,
    'priorityoptions' => $priorityoptions,
    'doubts' => $doubts,
    'hasdoubts' => !empty($doubts),
    'detail' => $detail,
    'listheading' => get_string('student_doubts_list_heading', 'theme_remui_kids'),
    'student_doubts_create' => get_string('student_doubts_create', 'theme_remui_kids'),
    'student_doubts_subject' => get_string('student_doubts_subject', 'theme_remui_kids'),
    'student_doubts_course' => get_string('student_doubts_course', 'theme_remui_kids'),
    'student_doubts_priority' => get_string('student_doubts_priority', 'theme_remui_kids'),
    'student_doubts_details' => get_string('student_doubts_details', 'theme_remui_kids'),
    'student_doubts_submit' => get_string('student_doubts_submit', 'theme_remui_kids'),
    'student_doubts_table_subject' => get_string('student_doubts_table_subject', 'theme_remui_kids'),
    'student_doubts_table_course' => get_string('student_doubts_table_course', 'theme_remui_kids'),
    'student_doubts_table_status' => get_string('student_doubts_table_status', 'theme_remui_kids'),
    'student_doubts_table_priority' => get_string('student_doubts_table_priority', 'theme_remui_kids'),
    'student_doubts_table_lastupdate' => get_string('student_doubts_table_lastupdate', 'theme_remui_kids'),
    'student_doubts_none' => get_string('student_doubts_none', 'theme_remui_kids'),
    'student_doubts_no_courses' => get_string('student_doubts_no_courses', 'theme_remui_kids'),
    'student_doubts_no_messages' => get_string('student_doubts_no_messages', 'theme_remui_kids'),
    'student_doubts_conversation' => get_string('student_doubts_conversation', 'theme_remui_kids'),
    'student_doubts_reply_heading' => get_string('student_doubts_reply_heading', 'theme_remui_kids'),
    'student_doubts_reply_placeholder' => get_string('student_doubts_reply_placeholder', 'theme_remui_kids'),
    'student_doubts_reply_submit' => get_string('student_doubts_reply_submit', 'theme_remui_kids'),
    'student_doubts_reply_attachments' => get_string('student_doubts_reply_attachments', 'theme_remui_kids'),
    'detailstatuslabel' => get_string('doubt_status_label', 'theme_remui_kids'),
    'detailprioritylabel' => get_string('doubt_priority_label', 'theme_remui_kids'),
    'detailcourselabel' => get_string('doubt_course_label', 'theme_remui_kids'),
    'detailupdatedlabel' => get_string('student_doubts_table_lastupdate', 'theme_remui_kids'),
    'detaildescriptionlabel' => get_string('student_doubts_details', 'theme_remui_kids'),
    'student_doubts_attachments' => get_string('student_doubts_attachments', 'theme_remui_kids'),
    'student_doubts_attachments_help' => get_string('student_doubts_attachments_help', 'theme_remui_kids'),
    'attachmentslabel' => get_string('doubt_uploaded_files', 'theme_remui_kids'),
    'cancreate' => $cancreate,
    'hasdetail' => !empty($detail),
    
    // Navigation URLs for sidebar (required for g4g7_sidebar template)
    'wwwroot' => $CFG->wwwroot,
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out(),
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
    'gradesurl' => (new moodle_url('/theme/remui_kids/grades.php'))->out(),
    'badgesurl' => (new moodle_url('/theme/remui_kids/badges.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/schedule.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'config' => ['wwwroot' => $CFG->wwwroot],
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    
    // Dashboard type flags for template
    'dashboardtype' => $dashboardtype,
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'elementary' => false,
    'middle' => ($dashboardtype === 'middle'),
    'highschool' => ($dashboardtype === 'highschool'),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/student_doubts', $templatecontext);
echo $OUTPUT->footer();

