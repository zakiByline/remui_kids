<?php
// Debug version of Class Performance Overview
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting debug...<br>";

try {
    require_once(__DIR__ . '/../../../config.php');
    echo "Config loaded successfully<br>";
} catch (Exception $e) {
    echo "Error loading config: " . $e->getMessage() . "<br>";
    exit;
}

try {
    require_login();
    echo "Login successful<br>";
} catch (Exception $e) {
    echo "Error with login: " . $e->getMessage() . "<br>";
    exit;
}

try {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/theme/remui_kids/pages/debug_class_performance.php'));
    $PAGE->set_title('Debug Class Performance');
    $PAGE->set_heading('Debug Class Performance');
    echo "PAGE setup successful<br>";
} catch (Exception $e) {
    echo "Error setting up PAGE: " . $e->getMessage() . "<br>";
    exit;
}

try {
    echo $OUTPUT->header();
    echo "Header output successful<br>";
} catch (Exception $e) {
    echo "Error with header: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h1>Debug Class Performance Overview</h1>";
echo "<p>If you can see this, the basic structure is working.</p>";
echo "<p>Current user: " . $USER->firstname . " " . $USER->lastname . "</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";

try {
    echo $OUTPUT->footer();
    echo "Footer output successful<br>";
} catch (Exception $e) {
    echo "Error with footer: " . $e->getMessage() . "<br>";
}

echo "Debug complete!<br>";
?>








