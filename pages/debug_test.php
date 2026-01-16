<?php
// Simple debug test page

require_once(__DIR__ . '/../../../config.php');

require_login();

echo "Debug Test Page - PHP is working<br>";
echo "Current user: " . fullname($USER) . "<br>";
echo "User ID: " . $USER->id . "<br>";

// Test if we can load the theme lib
try {
    require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
    echo "Theme lib loaded successfully<br>";
} catch (Exception $e) {
    echo "Error loading theme lib: " . $e->getMessage() . "<br>";
}

// Test basic template rendering
try {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/theme/remui_kids/pages/debug_test.php'));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title('Debug Test');
    
    echo $OUTPUT->header();
    echo "<h1>Debug Test Page</h1>";
    echo "<p>If you can see this, basic Moodle rendering works.</p>";
    echo $OUTPUT->footer();
} catch (Exception $e) {
    echo "Error with Moodle rendering: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString();
}
?>






