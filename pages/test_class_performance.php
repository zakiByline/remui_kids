<?php
// Simple test page for Class Performance Overview
require_once(__DIR__ . '/../../../config.php');
require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/test_class_performance.php'));
$PAGE->set_title('Test Class Performance');
$PAGE->set_heading('Test Class Performance');

echo $OUTPUT->header();
echo '<h1>Class Performance Overview Test</h1>';
echo '<p>If you can see this, the basic page structure is working.</p>';
echo '<p><a href="class_performance_overview.php">Go to Class Performance Overview</a></p>';
echo $OUTPUT->footer();
?>








