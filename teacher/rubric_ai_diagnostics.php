<?php
/**
 * Rubric AI Assistant Diagnostics Page
 * Standalone diagnostic page for troubleshooting Rubric AI Assistant issues
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access diagnostic page');
}

// Get course ID from parameter
$courseid = optional_param('courseid', 0, PARAM_INT);

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/rubric_ai_diagnostics.php', ['courseid' => $courseid]);
$PAGE->set_title('Rubric AI Assistant - Diagnostics');
$PAGE->set_heading('Rubric AI Assistant Diagnostics');
$PAGE->add_body_class('rubric-diagnostics-page');

// Check AI Assistant status
$aiassistantinstalled = class_exists('core_component') && core_component::get_component_directory('local_aiassistant');
$aiassistantenabled = $aiassistantinstalled ? (bool)get_config('local_aiassistant', 'enabled') : false;
$aiassistantpermitted = $aiassistantenabled && has_capability('local/aiassistant:use', $context);
$aiassistantcanuse = $aiassistantinstalled && $aiassistantenabled && $aiassistantpermitted;

echo $OUTPUT->header();
?>

<style>
.rubric-diagnostics-page {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
    padding: 20px;
}

.diagnostics-container {
    max-width: 1200px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.diagnostics-header {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
    color: white;
    padding: 25px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.diagnostics-header h1 {
    margin: 0;
    font-size: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.diagnostics-content {
    padding: 30px;
}

.diagnostics-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border-left: 4px solid #6c757d;
}

.diagnostics-section h2 {
    margin: 0 0 15px 0;
    color: #495057;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.diagnostics-item {
    padding: 12px 0;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.diagnostics-item:last-child {
    border-bottom: none;
}

.diagnostics-label {
    font-weight: 500;
    color: #6c757d;
    font-size: 14px;
}

.diagnostics-value {
    color: #212529;
    font-size: 14px;
    font-family: 'Courier New', monospace;
    background: white;
    padding: 6px 12px;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

.diagnostics-value.success {
    color: #198754;
    background: #d1e7dd;
    border-color: #badbcc;
}

.diagnostics-value.error {
    color: #dc3545;
    background: #f8d7da;
    border-color: #f5c2c7;
}

.diagnostics-value.warning {
    color: #856404;
    background: #fff3cd;
    border-color: #ffeaa7;
}

.diagnostics-actions {
    padding: 20px 30px;
    border-top: 2px solid #e9ecef;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    background: #f8f9fa;
}

.diagnostics-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}

.diagnostics-btn-primary {
    background: #0d6efd;
    color: white;
}

.diagnostics-btn-primary:hover {
    background: #0b5ed7;
    color: white;
    text-decoration: none;
}

.diagnostics-btn-secondary {
    background: #6c757d;
    color: white;
}

.diagnostics-btn-secondary:hover {
    background: #5a6268;
    color: white;
    text-decoration: none;
}

.refresh-btn {
    background: #198754;
    color: white;
}

.refresh-btn:hover {
    background: #157347;
    color: white;
    text-decoration: none;
}
</style>

<div class="rubric-diagnostics-page">
    <div class="diagnostics-container">
        <div class="diagnostics-header">
            <h1>
                <i class="fa fa-bug"></i>
                Rubric AI Assistant - Diagnostics
            </h1>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/create_assignment_page.php" class="diagnostics-btn diagnostics-btn-secondary">
                <i class="fa fa-arrow-left"></i> Back to Assignment Creation
            </a>
        </div>
        
        <div class="diagnostics-content">
            <div class="diagnostics-section">
                <h2><i class="fa fa-info-circle"></i> System Status</h2>
                <div id="systemStatus">
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">AI Plugin Installed:</span>
                        <span class="diagnostics-value <?php echo $aiassistantinstalled ? 'success' : 'error'; ?>">
                            <?php echo $aiassistantinstalled ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">AI Plugin Enabled:</span>
                        <span class="diagnostics-value <?php echo $aiassistantenabled ? 'success' : 'error'; ?>">
                            <?php echo $aiassistantenabled ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">User Permissions:</span>
                        <span class="diagnostics-value <?php echo $aiassistantpermitted ? 'success' : 'error'; ?>">
                            <?php echo $aiassistantpermitted ? '✓ Allowed' : '✗ Not Allowed'; ?>
                        </span>
                    </div>
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">Can Use AI:</span>
                        <span class="diagnostics-value <?php echo $aiassistantcanuse ? 'success' : 'error'; ?>">
                            <?php echo $aiassistantcanuse ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="diagnostics-section">
                <h2><i class="fa fa-check-circle"></i> Element Detection</h2>
                <div id="elementDetection">
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">Status:</span>
                        <span class="diagnostics-value warning">Check browser console for element detection</span>
                    </div>
                </div>
            </div>
            
            <div class="diagnostics-section">
                <h2><i class="fa fa-plug"></i> Event Handlers</h2>
                <div id="eventHandlers">
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">Status:</span>
                        <span class="diagnostics-value warning">Check browser console for handler status</span>
                    </div>
                </div>
            </div>
            
            <div class="diagnostics-section">
                <h2><i class="fa fa-database"></i> Rubric Data</h2>
                <div id="rubricData">
                    <div class="diagnostics-item">
                        <span class="diagnostics-label">Status:</span>
                        <span class="diagnostics-value warning">Available on assignment creation page</span>
                    </div>
                </div>
            </div>
            
            <div class="diagnostics-section">
                <h2><i class="fa fa-exclamation-triangle"></i> Troubleshooting</h2>
                <div class="diagnostics-item">
                    <span class="diagnostics-label">Instructions:</span>
                    <span class="diagnostics-value">
                        <ol style="margin: 10px 0; padding-left: 20px;">
                            <li>Open the assignment creation page</li>
                            <li>Select "Rubric" as the grading method</li>
                            <li>Open browser console (F12) to see detailed diagnostics</li>
                            <li>Check for any error messages in the console</li>
                            <li>Verify that all required elements are found</li>
                        </ol>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="diagnostics-actions">
            <button type="button" class="diagnostics-btn refresh-btn" onclick="location.reload()">
                <i class="fa fa-refresh"></i> Refresh Page
            </button>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/create_assignment_page.php" class="diagnostics-btn diagnostics-btn-primary">
                <i class="fa fa-arrow-left"></i> Back to Assignment Creation
            </a>
        </div>
    </div>
</div>

<script>
// Additional client-side diagnostics
// Use jQuery directly if available, or wait for Moodle AMD loader
(function() {
    function initDiagnostics() {
        console.log('=== Rubric AI Diagnostics Page Loaded ===');
        
        // Use jQuery if available (either from Moodle or direct)
        const $ = window.jQuery || window.$;
        
        if (!$) {
            console.warn('jQuery not available, skipping some diagnostics');
            return;
        }
        
        // Check if we can access the parent window (if opened from assignment page)
        try {
            if (window.opener && window.opener.document) {
                console.log('✓ Can access parent window');
                
                // Try to get diagnostic info from parent
                const parentWindow = window.opener;
                if (parentWindow.window && parentWindow.window.rubricData) {
                    console.log('✓ Found rubric data in parent window');
                    $('#rubricData').html(`
                        <div class="diagnostics-item">
                            <span class="diagnostics-label">Rubric Data Available:</span>
                            <span class="diagnostics-value success">✓ Yes (${parentWindow.window.rubricData.length || 0} criteria)</span>
                        </div>
                    `);
                }
            } else {
                console.log('ℹ Not opened from parent window');
            }
        } catch (e) {
            console.log('ℹ Cannot access parent window (cross-origin or not opened from parent)');
        }
        
        // Check for AI Assistant functions
        console.log('Checking for AI Assistant functions...');
        console.log('- window.initRubricAiButtons:', typeof window.initRubricAiButtons);
        console.log('- window.openRubricDiagnosticModal:', typeof window.openRubricDiagnosticModal);
    }
    
    // Try to use Moodle AMD loader if available
    if (typeof require !== 'undefined' && typeof define !== 'undefined') {
        require(['jquery'], function($) {
            initDiagnostics();
        });
    } else {
        // Fallback: wait for jQuery to load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initDiagnostics, 100);
            });
        } else {
            setTimeout(initDiagnostics, 100);
        }
    }
})();
</script>

<?php
echo $OUTPUT->footer();
?>

