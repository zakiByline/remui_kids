<?php
// Minimal test for student overview

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');

require_login();

$studentid = optional_param('id', $USER->id, PARAM_INT);
$student = core_user::get_user($studentid, '*', MUST_EXIST);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/minimal_test.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Minimal Test');
$PAGE->set_heading('Minimal Test');

echo $OUTPUT->header();

echo "<h1>Minimal Student Overview Test</h1>";
echo "<p>Student: " . fullname($student) . "</p>";
echo "<p>Student ID: " . $student->id . "</p>";
echo "<p>Email: " . $student->email . "</p>";

// Test basic HTML structure
echo "<div style='background: #f0f0f0; padding: 20px; margin: 20px 0;'>";
echo "<h2>Test Card</h2>";
echo "<p>This is a test card to verify basic HTML rendering.</p>";
echo "</div>";

echo $OUTPUT->footer();
?>







