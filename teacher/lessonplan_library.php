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
 * Lesson Plan Library page for teachers.
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();

$context = context_system::instance();
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access lesson plan library');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/teacher/lessonplan_library.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Lesson Plan Library');
$PAGE->set_heading('Lesson Plan Library');

$PAGE->requires->css(new moodle_url('/theme/remui_kids/teacher/styles/lessonplan.css'));
$PAGE->requires->js(new moodle_url('/theme/remui_kids/teacher/js/lessonplan_library.js'), true);

$templatecontext = [
    'ajaxurl' => (new moodle_url('/theme/remui_kids/teacher/ajax/lessonplan_ai.php'))->out(false),
    'sesskey' => sesskey(),
    'builderurl' => (new moodle_url('/theme/remui_kids/teacher/lessonplan.php'))->out(false),
];

echo $OUTPUT->header();
?>
<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>
        <div class="teacher-main-content" data-shell="wide">
            <?php echo $OUTPUT->render_from_template('theme_remui_kids/teacher/lessonplan_library', $templatecontext); ?>
        </div>
    </div>
</div>
<?php
echo $OUTPUT->footer();