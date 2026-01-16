<?php
// Final test for the exact dashboard matching second image

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

$studentid = optional_param('id', $USER->id, PARAM_INT);
$student = core_user::get_user($studentid, '*', MUST_EXIST);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/final_dashboard_test.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Final Dashboard Test - ' . fullname($student));
$PAGE->set_heading('Final Dashboard Test');

echo $OUTPUT->header();

// Get the exact dashboard data
$dashboard_data = theme_remui_kids_get_exact_student_dashboard($student->id);

// Template context
$templatecontext = array_merge([
    'student' => [
        'id' => $student->id,
        'fullname' => fullname($student),
        'firstname' => $student->firstname,
        'lastname' => $student->lastname,
        'email' => $student->email,
        'avatar_url' => (new moodle_url('/user/pix.php/' . $student->id . '/f1.jpg'))->out(),
        'profile_url' => (new moodle_url('/user/profile.php', ['id' => $student->id]))->out()
    ]
], $dashboard_data);

try {
    $html = $OUTPUT->render_from_template('theme_remui_kids/exact_student_dashboard', $templatecontext);
    echo $html;
} catch (Throwable $e) {
    echo html_writer::div('Dashboard Error: ' . $e->getMessage(), 'alert alert-danger');
    echo html_writer::div('Stack trace: ' . $e->getTraceAsString(), 'text-muted small');
}

echo $OUTPUT->footer();
?>






