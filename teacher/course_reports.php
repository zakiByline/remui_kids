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

/**
 * Teacher reports hub.
 *
 * @package   theme_remui_kids
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();
$context = context_system::instance();

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access teacher reports page');
}

require_once($CFG->dirroot . '/lib/enrollib.php');

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/course_reports.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Course Reports');
$PAGE->add_body_class('teacher-reports');
$PAGE->navbar->add('Course Reports');

$selectedcourseid = optional_param('courseid', 0, PARAM_INT);
$teachercourses = enrol_get_my_courses('id, fullname, shortname', 'fullname ASC');

if ($selectedcourseid && !array_key_exists($selectedcourseid, $teachercourses)) {
    $selectedcourseid = 0;
}

$coursesforselect = [];
foreach ($teachercourses as $course) {
    $coursesforselect[] = [
        'id' => (int)$course->id,
        'label' => format_string($course->fullname),
        'selected' => ($selectedcourseid == $course->id)
    ];
}

$reportdata = null;
if ($selectedcourseid) {
    $reportdata = theme_remui_kids_get_teacher_competency_report($selectedcourseid);
}

$chartRadar = $reportdata['radar'] ?? ['labels' => [], 'values' => []];
$chartStudent = $reportdata['student_chart'] ?? ['labels' => [], 'student_values' => [], 'class_values' => []];
$frameworkOptions = [];
if (!empty($reportdata['framework_competencies'])) {
    foreach ($reportdata['framework_competencies'] as $index => $framework) {
        $frameworkOptions[] = [
            'id' => $framework['id'],
            'name' => $framework['name'],
            'selected' => $index === 0
        ];
    }
}

// Capture sidebar HTML from sidebar.php
ob_start();
include($CFG->dirroot . '/theme/remui_kids/teacher/includes/sidebar.php');
$sidebar_html = ob_get_clean();

$templatecontext = [
    'config' => ['wwwroot' => $CFG->wwwroot],
    'sidebar_html' => $sidebar_html,
    'has_courses' => !empty($coursesforselect),
    'courses' => $coursesforselect,
    'course_select_action' => (new moodle_url('/theme/remui_kids/teacher/course_reports.php'))->out(false),
    'has_course_selected' => !empty($reportdata),
    'report' => $reportdata ?: [],
    'chartdata_radar' => json_encode($chartRadar),
    'chartdata_student' => json_encode($chartStudent),
    'framework_competency_json' => json_encode($reportdata['framework_competencies'] ?? []),
    'framework_options' => $frameworkOptions,
    'has_framework_options' => !empty($frameworkOptions),
    'framework_student_chart_json' => json_encode($reportdata['framework_student_charts'] ?? []),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/teacher_reports', $templatecontext);
echo $OUTPUT->footer();