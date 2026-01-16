<?php
/**
 * LibreOffice Test Script
 * Check if LibreOffice/unoconv is available and working
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

// Allow teachers to access this diagnostic page
// Check if user is a teacher in any course
$userid = $USER->id;
$is_teacher = $DB->record_exists_sql(
    "SELECT 1 FROM {course} c
     JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
     JOIN {role_assignments} ra ON ra.contextid = ctx.id
     JOIN {role} r ON r.id = ra.roleid
     WHERE ra.userid = :userid 
     AND r.archetype = 'editingteacher'
     AND c.id != 1",
    ['userid' => $userid]
);

if (!$is_teacher && !has_capability('moodle/site:config', context_system::instance())) {
    print_error('You do not have permission to access this page.');
}

echo "<h1>LibreOffice Configuration Test</h1>";

// Check Moodle config
echo "<h2>1. Moodle Configuration</h2>";
if (!empty($CFG->pathtounoconv)) {
    echo "<p style='color: green;'>✓ pathtounoconv is set: <strong>" . htmlspecialchars($CFG->pathtounoconv) . "</strong></p>";
    $configured_path = $CFG->pathtounoconv;
} else {
    echo "<p style='color: orange;'>⚠ pathtounoconv is not set in Moodle config</p>";
    $configured_path = null;
}

// Check if executable
echo "<h2>2. Executable Check</h2>";
$libreoffice_path = null;
$test_paths = [];

if ($configured_path) {
    $test_paths[] = $configured_path;
}

// Add common paths based on OS
if (strtolower(substr(PHP_OS, 0, 3)) === 'win') {
    // Windows paths
    $test_paths = array_merge($test_paths, [
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files\\LibreOffice 7\\program\\soffice.exe',
        'C:\\Program Files\\LibreOffice 6\\program\\soffice.exe',
    ]);
} else {
    // Linux/Mac paths
    $test_paths = array_merge($test_paths, [
        '/usr/bin/unoconv',
        '/usr/local/bin/unoconv',
        '/opt/local/bin/unoconv',
        'unoconv' // Try system PATH
    ]);
}

foreach ($test_paths as $path) {
    $exists = file_exists($path);
    $executable = is_executable($path);
    
    // On Windows, file_exists is more reliable than is_executable for .exe files
    if (strtolower(substr(PHP_OS, 0, 3)) === 'win') {
        if ($exists) {
            echo "<p style='color: green;'>✓ Found: <strong>" . htmlspecialchars($path) . "</strong></p>";
            if (!$libreoffice_path) {
                $libreoffice_path = $path;
            }
        } else {
            echo "<p style='color: #999;'>✗ Not found: " . htmlspecialchars($path) . "</p>";
        }
    } else {
        if ($executable) {
            echo "<p style='color: green;'>✓ Found executable: <strong>" . htmlspecialchars($path) . "</strong></p>";
            if (!$libreoffice_path) {
                $libreoffice_path = $path;
            }
        } else if ($exists) {
            echo "<p style='color: orange;'>⚠ Found but not executable: " . htmlspecialchars($path) . "</p>";
        } else {
            echo "<p style='color: #999;'>✗ Not found: " . htmlspecialchars($path) . "</p>";
        }
    }
}

if (!$libreoffice_path) {
    echo "<p style='color: red;'><strong>ERROR: LibreOffice/unoconv not found!</strong></p>";
    echo "<h3>Installation Instructions:</h3>";
    
    if (strtolower(substr(PHP_OS, 0, 3)) === 'win') {
        echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0;'>";
        echo "<h4 style='margin-top: 0;'>For Windows (WAMP/XAMPP):</h4>";
        echo "<ol>";
        echo "<li>Download and install LibreOffice from <a href='https://www.libreoffice.org/download/' target='_blank'>https://www.libreoffice.org/download/</a></li>";
        echo "<li>After installation, find the soffice.exe file (usually in <code>C:\\Program Files\\LibreOffice\\program\\soffice.exe</code>)</li>";
        echo "<li>Add this line to your Moodle <code>config.php</code> file:</li>";
        echo "</ol>";
        echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 4px;'>\$CFG->pathtounoconv = 'C:\\\\Program Files\\\\LibreOffice\\\\program\\\\soffice.exe';</pre>";
        echo "<p><strong>Note:</strong> Use double backslashes (\\\\) in the path, or forward slashes (/).</p>";
        echo "<p><strong>Alternative:</strong> You can also install unoconv separately if available for Windows.</p>";
        echo "</div>";
    } else {
        echo "<ul>";
        echo "<li><strong>Ubuntu/Debian:</strong> <code>sudo apt-get install unoconv</code></li>";
        echo "<li><strong>CentOS/RHEL:</strong> <code>sudo yum install unoconv</code></li>";
        echo "<li><strong>macOS:</strong> <code>brew install unoconv</code></li>";
        echo "</ul>";
    }
    
    echo "<p>After installation, set <code>\$CFG->pathtounoconv</code> in your Moodle config.php file.</p>";
    exit;
}

// Test conversion
echo "<h2>3. Conversion Test</h2>";

// Determine if it's unoconv or soffice
$is_unoconv = (basename($libreoffice_path) === 'unoconv' || strpos($libreoffice_path, 'unoconv') !== false);
$is_soffice = (basename($libreoffice_path) === 'soffice.exe' || basename($libreoffice_path) === 'soffice');

if ($is_unoconv) {
    echo "<p>Testing unoconv command...</p>";
    $test_command = escapeshellarg($libreoffice_path) . ' --version 2>&1';
} else if ($is_soffice) {
    echo "<p>Testing LibreOffice soffice command...</p>";
    $test_command = escapeshellarg($libreoffice_path) . ' --version 2>&1';
} else {
    echo "<p>Testing LibreOffice command...</p>";
    $test_command = escapeshellarg($libreoffice_path) . ' --version 2>&1';
}

exec($test_command, $version_output, $version_code);

if ($version_code === 0) {
    echo "<p style='color: green;'>✓ LibreOffice/unoconv is working</p>";
    echo "<pre>" . htmlspecialchars(implode("\n", $version_output)) . "</pre>";
} else {
    echo "<p style='color: orange;'>⚠ Version check returned code: $version_code</p>";
    echo "<pre>" . htmlspecialchars(implode("\n", $version_output)) . "</pre>";
    echo "<p><em>Note: This doesn't necessarily mean it won't work. The command might still function for conversions.</em></p>";
}

// Check covers directory
echo "<h2>4. Covers Directory</h2>";
$covers_dir = $CFG->dataroot . '/theme_remui_kids/covers';
if (!file_exists($covers_dir)) {
    if (@mkdir($covers_dir, 0755, true)) {
        echo "<p style='color: green;'>✓ Created covers directory: " . htmlspecialchars($covers_dir) . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create covers directory: " . htmlspecialchars($covers_dir) . "</p>";
        echo "<p>Please create this directory manually with write permissions.</p>";
    }
} else {
    echo "<p style='color: green;'>✓ Covers directory exists: " . htmlspecialchars($covers_dir) . "</p>";
    if (is_writable($covers_dir)) {
        echo "<p style='color: green;'>✓ Directory is writable</p>";
    } else {
        echo "<p style='color: red;'>✗ Directory is not writable</p>";
        echo "<p>Please set write permissions: <code>chmod 755 " . htmlspecialchars($covers_dir) . "</code></p>";
    }
}

echo "<hr>";
echo "<p><a href='view_course.php'>← Back to Resources</a></p>";

