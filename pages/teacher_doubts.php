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
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

use theme_remui_kids\local\doubts\constants;
use theme_remui_kids\local\doubts\service;

require_login();

$context = context_system::instance();

$capabilities = [
    'theme/remui_kids:viewdoubts',
    'theme/remui_kids:replydoubts',
    'theme/remui_kids:managedoubts'
];

$hasglobalpermission = has_any_capability($capabilities, $context);

if (!$hasglobalpermission) {
    $courseswithcap = get_user_capability_course('theme/remui_kids:viewdoubts', $USER->id, false);
    if (empty($courseswithcap)) {
        throw new required_capability_exception($context, 'theme/remui_kids:viewdoubts', 'nopermissions', '');
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/teacher_doubts.php'));
$PAGE->set_title(get_string('teacher_doubts', 'theme_remui_kids'));
$PAGE->set_heading(get_string('teacher_doubts', 'theme_remui_kids'));
$PAGE->set_pagelayout('base');

// Highlight sidebar entry.
$currentpage = ['teacher_doubts' => true];

$service = new service();
$listdata = $service->list_for_teacher($USER->id, [], 0, 20);
$summary = $service->get_summary($USER->id);

$initialdetail = null;
if (!empty($listdata['records'])) {
    $first = reset($listdata['records']);
    $initialdetail = $service->get_detail($first['id'], $USER->id);
}

$selectedid = $initialdetail['doubt']['id'] ?? null;
if (!empty($listdata['records'])) {
    foreach ($listdata['records'] as &$record) {
        $record['iscurrent'] = ($selectedid !== null && $record['id'] === $selectedid);
    }
    unset($record);
}

$statusoptions = array_map(function(string $status) {
    return [
        'value' => $status,
        'label' => get_string('doubtstatus:' . $status, 'theme_remui_kids'),
    ];
}, constants::statuses());

$priorityoptions = array_map(function(string $priority) {
    return [
        'value' => $priority,
        'label' => get_string('doubtpriority:' . $priority, 'theme_remui_kids'),
    ];
}, constants::priorities());

$summarycards = [
    [
        'key' => 'total',
        'label' => get_string('doubt_summary_total', 'theme_remui_kids'),
        'value' => (int) ($summary['total'] ?? 0),
    ],
    [
        'key' => 'open',
        'label' => get_string('doubt_summary_open', 'theme_remui_kids'),
        'value' => (int) ($summary['open'] ?? 0),
    ],
    [
        'key' => 'inprogress',
        'label' => get_string('doubt_summary_inprogress', 'theme_remui_kids'),
        'value' => (int) ($summary['inprogress'] ?? 0),
    ],
    [
        'key' => 'resolved',
        'label' => get_string('doubt_summary_resolved', 'theme_remui_kids'),
        'value' => (int) ($summary['resolved'] ?? 0),
    ],
];

$detailstrings = [
    'doubt_status_label' => get_string('doubt_status_label', 'theme_remui_kids'),
    'doubt_priority_label' => get_string('doubt_priority_label', 'theme_remui_kids'),
    'doubt_course_label' => get_string('doubt_course_label', 'theme_remui_kids'),
    'doubt_student_label' => get_string('doubt_student_label', 'theme_remui_kids'),
    'doubt_created_label' => get_string('doubt_created_label', 'theme_remui_kids'),
    'doubt_due_label' => get_string('doubt_due_label', 'theme_remui_kids'),
    'doubt_messages_title' => get_string('doubt_messages_title', 'theme_remui_kids'),
    'doubt_history_title' => get_string('doubt_history_title', 'theme_remui_kids'),
    'doubt_uploaded_files' => get_string('doubt_uploaded_files', 'theme_remui_kids'),
    'doubt_add_attachments' => get_string('doubt_add_attachments', 'theme_remui_kids'),
    'doubt_remove_files' => get_string('doubt_remove_files', 'theme_remui_kids'),
    'doubt_send_reply' => get_string('doubt_send_reply', 'theme_remui_kids'),
    'doubt_reply_placeholder' => get_string('doubt_reply_placeholder', 'theme_remui_kids'),
    'doubt_reply_internal_placeholder' => get_string('doubt_reply_internal_placeholder', 'theme_remui_kids'),
    'doubt_visibility_public' => get_string('doubt_visibility_public', 'theme_remui_kids'),
    'doubt_visibility_internal' => get_string('doubt_visibility_internal', 'theme_remui_kids'),
    'doubt_resolution_toggle' => get_string('doubt_resolution_toggle', 'theme_remui_kids'),
    'doubt_assign_to_me' => get_string('doubt_assign_to_me', 'theme_remui_kids'),
    'doubt_unassign' => get_string('doubt_unassign', 'theme_remui_kids'),
    'doubt_last_activity' => get_string('doubt_last_activity', 'theme_remui_kids'),
    'messagesempty' => get_string('doubt_messages_empty', 'theme_remui_kids'),
    'historyempty' => get_string('doubt_history_empty', 'theme_remui_kids'),
];

$ajaxurl = new moodle_url('/theme/remui_kids/ajax/doubts.php');

$jsconfig = [
    'ajaxurl' => $ajaxurl->out(false),
    'sesskey' => sesskey(),
    'statuses' => $statusoptions,
    'priorities' => $priorityoptions,
    'strings' => array_merge([
        'noResults' => get_string('doubt_no_results', 'theme_remui_kids'),
        'selectPrompt' => get_string('doubt_select_prompt', 'theme_remui_kids'),
        'messagesEmpty' => $detailstrings['messagesempty'],
        'historyEmpty' => $detailstrings['historyempty'],
        'uploadLabel' => $detailstrings['doubt_uploaded_files'],
        'attachments' => $detailstrings['doubt_add_attachments'],
        'removeFiles' => $detailstrings['doubt_remove_files'],
        'sendReply' => $detailstrings['doubt_send_reply'],
        'assignToMe' => $detailstrings['doubt_assign_to_me'],
        'unassign' => $detailstrings['doubt_unassign'],
        'resolutionToggle' => $detailstrings['doubt_resolution_toggle'],
        'refresh' => get_string('doubt_refresh', 'theme_remui_kids'),
        'resetFilters' => get_string('doubt_filters_reset', 'theme_remui_kids'),
        'unassigned' => get_string('doubt_filter_unassigned', 'theme_remui_kids'),
        'replyPlaceholder' => $detailstrings['doubt_reply_placeholder'],
        'replyInternalPlaceholder' => $detailstrings['doubt_reply_internal_placeholder'],
        'visibilityPublic' => $detailstrings['doubt_visibility_public'],
        'visibilityInternal' => $detailstrings['doubt_visibility_internal'],
    ], $detailstrings),
    'initialFilters' => [],
    'initialDetailId' => $initialdetail['doubt']['id'] ?? null,
    'userid' => $USER->id,
    'perpage' => 20,
];

$PAGE->requires->js_call_amd('theme_remui_kids/teacher_doubts', 'init', [$jsconfig]);

$templatecontext = array_merge([
    'summarycards' => $summarycards,
    'records' => $listdata['records'],
    'statusoptions' => $statusoptions,
    'priorityoptions' => $priorityoptions,
    'initial' => $listdata,
    'initialdetail' => $initialdetail,
    'teacher_doubts_heading' => get_string('teacher_doubts', 'theme_remui_kids'),
    'teacher_doubts_subheading' => get_string('teacher_doubts_subheading', 'theme_remui_kids'),
    'searchplaceholder' => get_string('doubt_search_placeholder', 'theme_remui_kids'),
    'statuslabel' => get_string('doubt_filter_status', 'theme_remui_kids'),
    'prioritylabel' => get_string('doubt_filter_priority', 'theme_remui_kids'),
    'assignmentlabel' => get_string('doubt_filter_assigned', 'theme_remui_kids'),
    'alllabel' => get_string('doubt_filter_all', 'theme_remui_kids'),
    'unassignedlabel' => get_string('doubt_filter_unassigned', 'theme_remui_kids'),
    'noresults' => get_string('doubt_no_results', 'theme_remui_kids'),
    'selectprompt' => get_string('doubt_select_prompt', 'theme_remui_kids'),
    'doubt_filters_reset' => get_string('doubt_filters_reset', 'theme_remui_kids'),
    'doubt_refresh' => get_string('doubt_refresh', 'theme_remui_kids'),
], $detailstrings);

echo $OUTPUT->header();
?>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/../teacher/includes/sidebar.php'); ?>
        <div class="teacher-main-content">
            <div class="container-fluid">
                <?php echo $OUTPUT->render_from_template('theme_remui_kids/teacher_doubts', $templatecontext); ?>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();

