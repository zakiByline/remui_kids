<?php
/**
 * Test AI Analysis Web Service
 *
 * @package    mod_codeeditor
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/mod/codeeditor/test_ai_service.php');
$PAGE->set_title('Test AI Analysis Service');
$PAGE->set_heading('Test AI Analysis Service');

echo $OUTPUT->header();

echo '<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">';
echo '<h2>AI Analysis Service Test</h2>';

// Test 1: Check if AI Assistant is configured
echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
echo '<h3>1. Check AI Assistant Configuration</h3>';

$apikey = get_config('local_aiassistant', 'apikey');
$model = get_config('local_aiassistant', 'model');

if (empty($apikey)) {
    echo '<p style="color: red;"><strong>❌ FAILED:</strong> AI Assistant API key is not configured.</p>';
    echo '<p>Go to: Site Administration → Plugins → Local Plugins → AI Assistant</p>';
} else {
    echo '<p style="color: green;"><strong>✅ PASSED:</strong> API key is configured (length: ' . strlen($apikey) . ')</p>';
    echo '<p><strong>Model:</strong> ' . ($model ?: 'gemini-2.0-flash-exp (default)') . '</p>';
}
echo '</div>';

// Test 2: Check if external function exists
echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
echo '<h3>2. Check External Function</h3>';

try {
    require_once($CFG->dirroot . '/mod/codeeditor/classes/external/analyze_code.php');
    echo '<p style="color: green;"><strong>✅ PASSED:</strong> External function class exists</p>';
    
    if (class_exists('\mod_codeeditor\external\analyze_code')) {
        echo '<p style="color: green;"><strong>✅ PASSED:</strong> Class is properly namespaced</p>';
    } else {
        echo '<p style="color: red;"><strong>❌ FAILED:</strong> Class not found</p>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;"><strong>❌ FAILED:</strong> ' . $e->getMessage() . '</p>';
}
echo '</div>';

// Test 3: Check web service registration
echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
echo '<h3>3. Check Web Service Registration</h3>';

$service = $DB->get_record('external_functions', ['name' => 'mod_codeeditor_analyze_code']);
if ($service) {
    echo '<p style="color: green;"><strong>✅ PASSED:</strong> Web service is registered</p>';
    echo '<pre style="background: #f8f9fa; padding: 10px; border-radius: 4px;">';
    echo 'Function: ' . $service->name . "\n";
    echo 'Class: ' . $service->classname . "\n";
    echo 'Method: ' . $service->methodname . "\n";
    echo '</pre>';
} else {
    echo '<p style="color: red;"><strong>❌ FAILED:</strong> Web service is not registered</p>';
    echo '<p><strong>Solution:</strong> Go to Site Administration → Notifications and upgrade the database</p>';
}
echo '</div>';

// Test 4: Check permissions
echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
echo '<h3>4. Check User Permissions</h3>';

$systemcontext = context_system::instance();
$cangrade = has_capability('mod/codeeditor:grade', $systemcontext);

if ($cangrade || is_siteadmin()) {
    echo '<p style="color: green;"><strong>✅ PASSED:</strong> You have grading capability</p>';
} else {
    echo '<p style="color: orange;"><strong>⚠ WARNING:</strong> You may not have permission to use AI analysis</p>';
    echo '<p>This feature requires mod/codeeditor:grade capability</p>';
}
echo '</div>';

// Test 5: Try a simple API call
if (!empty($apikey) && $service) {
    echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
    echo '<h3>5. Test API Call</h3>';
    
    try {
        $testcode = 'console.log("Hello, World!");';
        $testlanguage = 'javascript';
        $testoutput = 'Hello, World!';
        $testquestion = 'Create a program that prints "Hello, World!" to the console.';
        
        $result = \mod_codeeditor\external\analyze_code::analyze_code($testcode, $testlanguage, $testoutput, 'Test - maxgrade:100', $testquestion);
        
        if ($result['success']) {
            echo '<p style="color: green;"><strong>✅ PASSED:</strong> AI Analysis is working!</p>';
            echo '<details style="margin-top: 10px;">';
            echo '<summary style="cursor: pointer; color: #007bff;">View Analysis Result</summary>';
            echo '<div style="background: #f8f9fa; padding: 15px; margin-top: 10px; border-radius: 4px; max-height: 400px; overflow-y: auto;">';
            echo htmlspecialchars($result['analysis']);
            echo '</div>';
            echo '</details>';
        } else {
            echo '<p style="color: red;"><strong>❌ FAILED:</strong> ' . htmlspecialchars($result['analysis']) . '</p>';
            if (!empty($result['error'])) {
                echo '<p><strong>Error code:</strong> ' . $result['error'] . '</p>';
            }
        }
    } catch (Exception $e) {
        echo '<p style="color: red;"><strong>❌ FAILED:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre style="background: #f8d7da; padding: 10px; border-radius: 4px; color: #721c24;">';
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    }
    echo '</div>';
}

// Summary
echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 8px; color: white;">';
echo '<h3 style="margin: 0; color: white;">Summary</h3>';
echo '<p style="margin: 10px 0 0 0;">If all tests pass, the AI Analysis feature should work correctly.</p>';
echo '<p style="margin: 5px 0 0 0;"><strong>Next step:</strong> Try using the "AI Analyze" button on the grading page.</p>';
echo '</div>';

echo '</div>';

echo $OUTPUT->footer();

