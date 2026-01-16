<?php
/**
 * Diagnostic script to test Rubric AI Assistant integration
 */

require_once(__DIR__ . '/../../../config.php');

echo "<!DOCTYPE html><html><head><title>Rubric AI Test</title></head><body>";
echo "<h1>Rubric AI Assistant Diagnostic</h1>";

// Check AMD files
echo "<h2>1. AMD Files</h2>";
$src_file = __DIR__ . '/amd/src/rubric_ai.js';
$build_file = __DIR__ . '/amd/build/rubric_ai.min.js';

echo "<p><strong>Source file:</strong> " . ($src_file) . "</p>";
echo "<p>Exists: " . (file_exists($src_file) ? '✓ Yes' : '✗ No') . "</p>";
if (file_exists($src_file)) {
    echo "<p>Size: " . filesize($src_file) . " bytes</p>";
}

echo "<p><strong>Build file:</strong> " . ($build_file) . "</p>";
echo "<p>Exists: " . (file_exists($build_file) ? '✓ Yes' : '✗ No') . "</p>";
if (file_exists($build_file)) {
    echo "<p>Size: " . filesize($build_file) . " bytes</p>";
}

// Check AI Assistant plugin
echo "<h2>2. AI Assistant Plugin</h2>";
$aiassistantinstalled = class_exists('core_component') && core_component::get_component_directory('local_aiassistant');
echo "<p>Installed: " . ($aiassistantinstalled ? '✓ Yes' : '✗ No') . "</p>";

if ($aiassistantinstalled) {
    $aiassistantenabled = (bool)get_config('local_aiassistant', 'enabled');
    echo "<p>Enabled: " . ($aiassistantenabled ? '✓ Yes' : '✗ No') . "</p>";
    
    $context = context_system::instance();
    $aiassistantpermitted = has_capability('local/aiassistant:use', $context);
    echo "<p>User has capability: " . ($aiassistantpermitted ? '✓ Yes' : '✗ No') . "</p>";
    
    $apikey = get_config('local_aiassistant', 'apikey');
    echo "<p>API Key configured: " . (!empty($apikey) ? '✓ Yes' : '✗ No') . "</p>";
}

// Test AMD loading
echo "<h2>3. AMD Module Test</h2>";
echo "<button id='testButton' style='padding: 10px 20px; background: #0d6efd; color: white; border: none; border-radius: 5px; cursor: pointer;'>Click to Test AMD Loading</button>";
echo "<div id='testResult' style='margin-top: 20px; padding: 20px; background: #f0f0f0; border-radius: 5px;'></div>";

$PAGE->requires->js_call_amd('theme_remui_kids/rubric_ai', 'init', [[
    'installed' => $aiassistantinstalled ? '1' : '0',
    'enabled' => $aiassistantenabled ? '1' : '0',
    'allowed' => $aiassistantpermitted ? '1' : '0'
]]);

?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const button = document.getElementById('testButton');
    const result = document.getElementById('testResult');
    
    button.addEventListener('click', function() {
        result.innerHTML = '<p>Attempting to load AMD module...</p>';
        
        require(['theme_remui_kids/rubric_ai'], function(RubricAI) {
            result.innerHTML = '<p style="color: green;">✓ AMD module loaded successfully!</p>';
            result.innerHTML += '<p>Module object: ' + JSON.stringify(Object.keys(RubricAI)) + '</p>';
            
            // Try to initialize
            try {
                RubricAI.init({
                    installed: '<?php echo $aiassistantinstalled ? '1' : '0'; ?>',
                    enabled: '<?php echo $aiassistantenabled ? '1' : '0'; ?>',
                    allowed: '<?php echo $aiassistantpermitted ? '1' : '0'; ?>'
                });
                result.innerHTML += '<p style="color: green;">✓ Module initialized successfully!</p>';
                result.innerHTML += '<p>Check browser console for detailed logs.</p>';
            } catch (e) {
                result.innerHTML += '<p style="color: red;">✗ Error during initialization: ' + e.message + '</p>';
            }
        }, function(err) {
            result.innerHTML = '<p style="color: red;">✗ Failed to load AMD module!</p>';
            result.innerHTML += '<p>Error: ' + err.message + '</p>';
            result.innerHTML += '<p>Check browser console for details.</p>';
        });
    });
    
    // Auto-run on load
    console.log('=== Rubric AI Diagnostic Started ===');
    console.log('Testing AMD module loading...');
    button.click();
});
</script>

<h2>4. Browser Console</h2>
<p>Open your browser's Developer Tools (F12) and check the Console tab for detailed logging.</p>

<h2>5. Next Steps</h2>
<ul>
    <li>If AMD files are missing: They need to be created</li>
    <li>If plugin is not installed: Install local_aiassistant plugin</li>
    <li>If plugin is disabled: Enable it in Site Administration</li>
    <li>If no capability: Assign local/aiassistant:use to your role</li>
    <li>If AMD loading fails: Check Moodle caching (purge all caches)</li>
</ul>

<hr>
<p><a href="create_assignment_page.php">← Back to Create Assignment</a></p>

</body></html>
<?php
echo $OUTPUT->footer();
?>








