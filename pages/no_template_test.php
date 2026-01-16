<?php
// Test page without templates

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');

require_login();

$studentid = optional_param('id', $USER->id, PARAM_INT);
$student = core_user::get_user($studentid, '*', MUST_EXIST);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/no_template_test.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('No Template Test');
$PAGE->set_heading('No Template Test');

// Include CSS
$PAGE->requires->css('/theme/remui_kids/style/student_overview.css');

echo $OUTPUT->header();

echo "<div class='student-overview-wrapper'>";
echo "<h1>Student Overview - No Template Version</h1>";

echo "<div class='overview-header'>";
echo "<h2>Student: " . fullname($student) . "</h2>";
echo "<p>Email: " . $student->email . "</p>";
echo "<p>ID: " . $student->id . "</p>";
echo "</div>";

echo "<div class='overview-grid'>";
echo "<div class='card simple'>";
echo "<div class='card-title'>Test Card</div>";
echo "<div class='card-body'>This is a test card without templates</div>";
echo "</div>";
echo "</div>";

echo "</div>";

echo $OUTPUT->footer();
?>







