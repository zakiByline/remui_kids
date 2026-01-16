<?php
/**
 * Simple grading form for Code Editor submissions
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/codeeditor/lib.php');

// Course module ID
$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm = get_coursemodule_from_id('codeeditor', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$codeeditor = $DB->get_record('codeeditor', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/codeeditor:grade', $context);

// Get the submission
$submission = $DB->get_record('codeeditor_submissions', array(
    'codeeditorid' => $codeeditor->id,
    'userid' => $userid,
    'latest' => 1
), '*', MUST_EXIST);

$user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);

$PAGE->set_url('/mod/codeeditor/grade_submission.php', array('id' => $cm->id, 'userid' => $userid));
$PAGE->set_title('Grade Submission');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $grade = required_param('grade', PARAM_FLOAT);
    $feedback = optional_param_array('feedback', array(), PARAM_RAW);
    
    // Update submission
    $submission->grade = $grade;
    $submission->grader = $USER->id;
    $submission->feedbacktext = $feedback['text'] ?? '';
    $submission->feedbackformat = $feedback['format'] ?? FORMAT_HTML;
    $submission->timegraded = time();
    $submission->timemodified = time();
    
    $DB->update_record('codeeditor_submissions', $submission);
    
    // Update gradebook
    $gradedata = new stdClass();
    $gradedata->userid = $userid;
    $gradedata->rawgrade = $grade;
    $gradedata->dategraded = time();
    $gradedata->datesubmitted = $submission->timecreated;
    
    codeeditor_grade_item_update($codeeditor, $gradedata);
    
    redirect(new moodle_url('/mod/codeeditor/grading.php', array('id' => $cm->id)), 
             'Grade saved successfully', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

// Display assignment question/instructions
if (!empty($codeeditor->intro)) {
    echo '<div style="background: linear-gradient(135deg, #fff9e6 0%, #fffbf0 100%); padding: 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 25px; border: 2px solid #ffc107;">';
    echo '<h3 style="margin: 0 0 15px 0; color: #856404; font-size: 20px; font-weight: bold; display: flex; align-items: center; gap: 10px; background: rgba(255, 193, 7, 0.2); padding: 12px; border-radius: 8px;">';
    echo '<i class="fa fa-question-circle" style="color: #ffc107; font-size: 24px;"></i> Assignment Question';
    echo '</h3>';
    echo '<div style="background: white; padding: 20px; border-radius: 8px; font-size: 15px; line-height: 1.8; color: #212529; border-left: 4px solid #ffc107;">';
    echo format_text($codeeditor->intro, $codeeditor->introformat);
    echo '</div>';
    echo '</div>';
}

?>

<style>
body {
    background: #f5f7fa !important;
}

.grade-form-container {
    max-width: 100%;
    width: 100%;
    margin: 0;
    padding: 0;
    background: transparent;
}

.form-header {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.form-title {
    font-size: 24px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 10px 0;
}

.student-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 20px;
}

.student-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 18px;
}

.student-details h3 {
    margin: 0 0 4px 0;
    font-size: 18px;
    color: #2c3e50;
}

.student-details p {
    margin: 0;
    font-size: 14px;
    color: #6c757d;
}

.code-preview {
    background: white;
    color: #212529;
    padding: 25px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 15px;
    line-height: 1.8;
    overflow-x: auto;
    margin-bottom: 20px;
    min-height: 300px;
    max-height: 600px;
    overflow-y: auto;
    border: 2px solid #28a745;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.1);
}

.output-preview {
    background: white;
    color: #212529;
    padding: 25px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 15px;
    line-height: 1.8;
    overflow-x: auto;
    margin-bottom: 20px;
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
    border: 2px solid #17a2b8;
    box-shadow: 0 2px 8px rgba(23, 162, 184, 0.1);
}

.content-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

@media (max-width: 992px) {
    .content-row {
        grid-template-columns: 1fr;
    }
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.form-input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
}

.form-textarea {
    width: 100%;
    min-height: 120px;
    padding: 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    resize: vertical;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.btn-submit {
    background: #28a745;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-submit:hover {
    background: #218838;
    transform: translateY(-2px);
}

.btn-cancel {
    background: #6c757d;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn-cancel:hover {
    background: #5a6268;
    color: white;
    text-decoration: none;
    transform: translateY(-2px);
}

.submission-meta {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    font-size: 14px;
    color: #6c757d;
}

.submission-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>

<div class="grade-form-container">
    <!-- Header Section -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; margin-bottom: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <h1 style="color: white; margin: 0 0 15px 0; font-size: 28px; font-weight: bold;">
            <i class="fa fa-graduation-cap"></i> Grade Code Submission
        </h1>
        
        <!-- Student Info -->
        <div style="background: rgba(255,255,255,0.95); padding: 20px; border-radius: 10px; display: flex; align-items: center; gap: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div class="student-avatar">
                <?php 
                $initials = substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1);
                echo strtoupper($initials);
                ?>
            </div>
            <div style="flex: 1;">
                <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 20px;"><?php echo fullname($user); ?></h3>
                <div style="display: flex; gap: 25px; flex-wrap: wrap; color: #6c757d; font-size: 14px;">
                    <span><i class="fa fa-envelope"></i> <?php echo $user->email; ?></span>
                    <span><i class="fa fa-calendar"></i> <?php echo userdate($submission->timecreated, get_string('strftimedatetimeshort')); ?></span>
                    <span><i class="fa fa-code"></i> <?php echo htmlspecialchars($submission->language ?: 'Not specified'); ?></span>
                    <span><i class="fa fa-hashtag"></i> Attempt #<?php echo $submission->attemptnumber; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Code and Output - Side by Side -->
    <div class="content-row">
        <!-- Code Section -->
        <div>
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); height: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #28a745; font-size: 18px; font-weight: bold; display: flex; align-items: center; gap: 10px; border-bottom: 3px solid #28a745; padding-bottom: 12px; flex: 1;">
                        <i class="fa fa-code"></i> Submitted Code
                    </h3>
                    <button type="button" 
                            id="ai-analyze-btn" 
                            onclick="analyzeCodeWithAI()" 
                            style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; margin-left: 15px; transition: transform 0.2s;"
                            onmouseover="this.style.transform='scale(1.05)'"
                            onmouseout="this.style.transform='scale(1)'">
                        <i class="fa fa-robot"></i> AI Analyze
                    </button>
                </div>
                <div class="code-preview">
                    <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; color: #212529;"><?php echo htmlspecialchars($submission->code); ?></pre>
                </div>
            </div>
        </div>
        
        <!-- Output Section -->
        <div>
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); height: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #17a2b8; font-size: 18px; font-weight: bold; display: flex; align-items: center; gap: 10px; border-bottom: 3px solid #17a2b8; padding-bottom: 12px; flex: 1;">
                        <i class="fa fa-terminal"></i> Code Output
                    </h3>
                    <?php 
                    // Check if this is HTML/CSS code
                    $ishtmlcss = false;
                    $code_lower = strtolower($submission->code);
                    if (strpos($code_lower, '<html') !== false || 
                        strpos($code_lower, '<!doctype html') !== false || 
                        strpos($code_lower, '<div') !== false || 
                        strpos($code_lower, '<body') !== false ||
                        strpos($code_lower, '<style>') !== false ||
                        $submission->language == 'html' || 
                        $submission->language == 'htmlcss') {
                        $ishtmlcss = true;
                    }
                    ?>
                    <?php if ($ishtmlcss): ?>
                        <button type="button" 
                                onclick="previewWebPage()" 
                                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; margin-left: 15px; transition: transform 0.2s;"
                                onmouseover="this.style.transform='scale(1.05)'"
                                onmouseout="this.style.transform='scale(1)'">
                            <i class="fa fa-eye"></i> Preview Web Page
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($submission->output)): ?>
                    <div class="output-preview">
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; color: #212529;"><?php echo htmlspecialchars($submission->output); ?></pre>
                    </div>
                <?php else: ?>
                    <div style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107; text-align: center;">
                        <i class="fa fa-exclamation-triangle" style="color: #856404; font-size: 24px; margin-bottom: 10px; display: block;"></i>
                        <span style="color: #856404; font-style: italic;">No output captured for this submission.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Grading Form - Full Width -->
    <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
        <h3 style="margin: 0 0 25px 0; color: #6c63ff; font-size: 18px; font-weight: bold; display: flex; align-items: center; gap: 10px; border-bottom: 3px solid #6c63ff; padding-bottom: 12px;">
            <i class="fa fa-star"></i> Grade & Feedback
        </h3>
        
        <form method="post" action="<?php echo $PAGE->url; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="savegrade">
            <input type="hidden" name="submissionid" value="<?php echo $submission->id; ?>">
            <input type="hidden" name="userid" value="<?php echo $userid; ?>">

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 25px; margin-bottom: 25px;">
                <!-- Grade Input -->
                <div class="form-group">
                    <label class="form-label" for="grade" style="font-size: 16px; color: #495057; font-weight: bold; margin-bottom: 10px; display: block;">
                        <i class="fa fa-trophy" style="color: #ffc107;"></i> Grade (out of <?php echo $codeeditor->grade; ?>)
                    </label>
                    <input type="number" 
                           id="grade" 
                           name="grade" 
                           style="width: 100%; padding: 15px; border: 2px solid #ced4da; border-radius: 8px; font-size: 18px; font-weight: bold; text-align: center;" 
                           min="0" 
                           max="<?php echo $codeeditor->grade; ?>" 
                           step="0.01"
                           value="<?php echo $submission->grade !== null ? $submission->grade : ''; ?>"
                           placeholder="0.00"
                           required>
                    <small style="color: #6c757d; margin-top: 8px; display: block; text-align: center;">
                        Maximum: <?php echo $codeeditor->grade; ?> points
                    </small>
                </div>

                <!-- Feedback -->
                <div class="form-group">
                    <label class="form-label" for="feedback" style="font-size: 16px; color: #495057; font-weight: bold; margin-bottom: 10px; display: block;">
                        <i class="fa fa-comment-alt" style="color: #17a2b8;"></i> Feedback Comments
                    </label>
                    <textarea id="feedback" 
                              name="feedback[text]" 
                              style="width: 100%; min-height: 150px; padding: 15px; border: 2px solid #ced4da; border-radius: 8px; font-size: 14px; resize: vertical;"
                              placeholder="Enter helpful feedback for the student..."><?php echo htmlspecialchars($submission->feedbacktext); ?></textarea>
                    <input type="hidden" name="feedback[format]" value="<?php echo FORMAT_HTML; ?>">
                </div>
            </div>

            <!-- Actions -->
            <div style="display: flex; gap: 15px; justify-content: flex-end; padding-top: 20px; border-top: 2px solid #e9ecef;">
                <a href="grading.php?id=<?php echo $cm->id; ?>" class="btn-cancel" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa fa-arrow-left"></i> Back to Submissions
                </a>
                <button type="submit" class="btn-submit" style="display: inline-flex; align-items: center; gap: 8px; font-size: 16px;">
                    <i class="fa fa-save"></i> Save Grade
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal for HTML/CSS -->
<?php if ($ishtmlcss): ?>
<div id="previewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; padding: 20px;">
    <div style="background: white; width: 100%; height: 100%; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column;">
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: white; font-size: 20px; font-weight: bold;">
                <i class="fa fa-eye"></i> Web Page Preview
            </h3>
            <button onclick="closePreview()" style="background: rgba(255,255,255,0.2); color: white; border: 2px solid white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.2s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                <i class="fa fa-times"></i> Close
            </button>
        </div>
        <!-- Modal Content - Iframe for Preview -->
        <div style="flex: 1; padding: 20px; overflow: hidden;">
            <iframe id="previewFrame" 
                    style="width: 100%; height: 100%; border: none; border-radius: 8px; background: white;">
            </iframe>
        </div>
    </div>
</div>

<script>
function previewWebPage() {
    var code = <?php echo json_encode($submission->code); ?>;
    var modal = document.getElementById('previewModal');
    var iframe = document.getElementById('previewFrame');
    
    // Create a complete HTML document
    var htmlContent = code;
    
    // Check if code already has HTML structure
    if (!htmlContent.toLowerCase().includes('<!doctype') && !htmlContent.toLowerCase().includes('<html')) {
        // Wrap in HTML structure if needed
        htmlContent = '<!DOCTYPE html>\n<html>\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width, initial-scale=1.0">\n<title>Preview</title>\n</head>\n<body>\n' + code + '\n</body>\n</html>';
    }
    
    // Method 1: Try using Blob URL (most reliable)
    try {
        var blob = new Blob([htmlContent], { type: 'text/html' });
        var url = URL.createObjectURL(blob);
        iframe.src = url;
        
        // Clean up URL when iframe loads
        iframe.onload = function() {
            // URL will be cleaned up when modal closes
        };
    } catch (e) {
        // Method 2: Fallback to srcdoc (if supported)
        try {
            iframe.srcdoc = htmlContent;
        } catch (e2) {
            // Method 3: Fallback to contentDocument.write
            try {
                iframe.contentDocument.open();
                iframe.contentDocument.write(htmlContent);
                iframe.contentDocument.close();
            } catch (e3) {
                alert('Error: Cannot preview HTML. Please check browser compatibility.');
                console.error('Preview error:', e3);
            }
        }
    }
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

function closePreview() {
    var modal = document.getElementById('previewModal');
    var iframe = document.getElementById('previewFrame');
    
    // Clean up Blob URL if used
    if (iframe.src && iframe.src.startsWith('blob:')) {
        URL.revokeObjectURL(iframe.src);
    }
    
    // Clear iframe
    iframe.src = 'about:blank';
    
    // Hide modal
    modal.style.display = 'none';
    document.body.style.overflow = 'auto'; // Restore scrolling
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePreview();
    }
});

// Close modal when clicking outside
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePreview();
    }
});
</script>
<?php endif; ?>

<!-- AI Analysis Modal -->
<div id="aiAnalysisModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; padding: 20px; overflow-y: auto;">
    <div style="background: white; max-width: 900px; margin: 20px auto; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column; max-height: 90vh;">
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: white; font-size: 20px; font-weight: bold;">
                <i class="fa fa-robot"></i> AI Code Analysis
            </h3>
            <button onclick="closeAIAnalysis()" style="background: rgba(255,255,255,0.2); color: white; border: 2px solid white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.2s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                <i class="fa fa-times"></i> Close
            </button>
        </div>
        <!-- Modal Content -->
        <div style="flex: 1; padding: 30px; overflow-y: auto;">
            <div id="aiAnalysisLoading" style="text-align: center; padding: 40px;">
                <i class="fa fa-spinner fa-spin" style="font-size: 48px; color: #667eea; margin-bottom: 20px;"></i>
                <p style="color: #6c757d; font-size: 16px;">Analyzing code with AI... This may take a few seconds.</p>
            </div>
            <div id="aiAnalysisContent" style="display: none;">
                <div style="background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px;">
                    <div id="aiAnalysisResult" style="color: #212529; line-height: 1.7;">
                        <!-- AI Analysis will be displayed here -->
                    </div>
                </div>
            </div>
            <div id="aiAnalysisError" style="display: none; background: #f8d7da; padding: 20px; border-radius: 8px; border-left: 4px solid #dc3545; color: #721c24;">
                <i class="fa fa-exclamation-triangle"></i> <strong>Error:</strong> <span id="aiAnalysisErrorMsg"></span>
            </div>
        </div>
    </div>
</div>

<script>
function analyzeCodeWithAI() {
    var modal = document.getElementById('aiAnalysisModal');
    var loading = document.getElementById('aiAnalysisLoading');
    var content = document.getElementById('aiAnalysisContent');
    var result = document.getElementById('aiAnalysisResult');
    var error = document.getElementById('aiAnalysisError');
    var errorMsg = document.getElementById('aiAnalysisErrorMsg');
    var btn = document.getElementById('ai-analyze-btn');
    
    var code = <?php echo json_encode($submission->code); ?>;
    var language = <?php echo json_encode($submission->language ?: 'javascript'); ?>;
    var output = <?php echo json_encode($submission->output ?: ''); ?>;
    var maxGrade = <?php echo $codeeditor->grade; ?>;
    var assignmentQuestion = <?php echo json_encode(strip_tags($codeeditor->intro)); ?>;
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Reset UI
    loading.style.display = 'block';
    content.style.display = 'none';
    error.style.display = 'none';
    result.innerHTML = '';
    
    // Disable button
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analyzing...';
    }
    
    // Make AJAX call using Moodle's Ajax API
    require(['core/ajax', 'core/notification'], function(Ajax, Notification) {
        var promises = Ajax.call([{
            methodname: 'mod_codeeditor_analyze_code',
            args: {
                code: code,
                language: language,
                output: output || '',
                context: 'Code submission for grading - maxgrade:' + maxGrade,
                assignment_question: assignmentQuestion || ''
            }
        }]);
        
        promises[0].then(function(response) {
            if (response.success) {
                // Auto-fill grade and feedback fields
                var gradeInput = document.getElementById('grade');
                var feedbackTextarea = document.querySelector('textarea[name="feedback[text]"]');
                
                if (gradeInput && response.suggested_grade) {
                    gradeInput.value = response.suggested_grade;
                    // Highlight the grade field to show it was auto-filled
                    gradeInput.style.background = '#d4edda';
                    gradeInput.style.borderColor = '#28a745';
                    setTimeout(function() {
                        gradeInput.style.background = '';
                        gradeInput.style.borderColor = '';
                    }, 2000);
                }
                
                if (feedbackTextarea && response.brief_feedback) {
                    feedbackTextarea.value = response.brief_feedback;
                    // Highlight the feedback field
                    feedbackTextarea.style.background = '#d4edda';
                    feedbackTextarea.style.borderColor = '#28a745';
                    setTimeout(function() {
                        feedbackTextarea.style.background = '';
                        feedbackTextarea.style.borderColor = '';
                    }, 2000);
                }
                
                // Show analysis result
                loading.style.display = 'none';
                content.style.display = 'block';
                
                // Determine color based on plagiarism/AI risk
                var riskColor = '#d4edda'; // Green (low risk)
                var riskBorderColor = '#28a745';
                var riskTextColor = '#155724';
                var riskIcon = 'check-circle';
                
                var plagiarismLevel = response.plagiarism_risk || 'LOW';
                var aiLevel = response.ai_generated_probability || 'LOW';
                
                if (plagiarismLevel === 'HIGH' || aiLevel === 'HIGH') {
                    riskColor = '#f8d7da'; // Red
                    riskBorderColor = '#dc3545';
                    riskTextColor = '#721c24';
                    riskIcon = 'exclamation-triangle';
                } else if (plagiarismLevel === 'MODERATE' || aiLevel === 'MODERATE') {
                    riskColor = '#fff3cd'; // Yellow
                    riskBorderColor = '#ffc107';
                    riskTextColor = '#856404';
                    riskIcon = 'exclamation-circle';
                }
                
                // Add success message with plagiarism check at top
                var successMsg = '<div style="background: ' + riskColor + '; padding: 15px; border-radius: 8px; border-left: 4px solid ' + riskBorderColor + '; margin-bottom: 20px; color: ' + riskTextColor + ';">';
                successMsg += '<strong><i class="fa fa-' + riskIcon + '"></i> Analysis Complete</strong><br>';
                successMsg += 'Suggested Grade: <strong>' + response.suggested_grade + ' / ' + maxGrade + '</strong><br>';
                successMsg += '<hr style="margin: 10px 0; border: none; border-top: 1px solid ' + riskBorderColor + ';">';
                successMsg += '<i class="fa fa-search"></i> <strong>Plagiarism Risk:</strong> <span style="font-weight: bold;">' + plagiarismLevel + '</span><br>';
                successMsg += '<i class="fa fa-robot"></i> <strong>AI-Generated Probability:</strong> <span style="font-weight: bold;">' + aiLevel + '</span><br>';
                
                if (plagiarismLevel === 'HIGH' || aiLevel === 'HIGH') {
                    successMsg += '<hr style="margin: 10px 0; border: none; border-top: 1px solid ' + riskBorderColor + ';">';
                    successMsg += '<i class="fa fa-exclamation-triangle"></i> <strong>Warning:</strong> High risk detected. Please review the Academic Integrity Assessment section below carefully.';
                } else if (plagiarismLevel === 'MODERATE' || aiLevel === 'MODERATE') {
                    successMsg += '<hr style="margin: 10px 0; border: none; border-top: 1px solid ' + riskBorderColor + ';">';
                    successMsg += '<i class="fa fa-exclamation-circle"></i> <strong>Note:</strong> Some concerns detected. Review the analysis for details.';
                } else {
                    successMsg += '<hr style="margin: 10px 0; border: none; border-top: 1px solid ' + riskBorderColor + ';">';
                    successMsg += '<i class="fa fa-check-circle"></i> Code appears to be original and authentic.';
                }
                
                successMsg += '</div>';
                
                // Convert markdown-like formatting to HTML with highlighting
                var analysisText = response.analysis;
                
                // Define color scheme for different sections
                var sectionColors = {
                    '0': '#ffeaa7',  // Academic Integrity - Yellow
                    '1': '#74b9ff',  // Requirements - Blue
                    '2': '#55efc4',  // Code Quality - Green
                    '3': '#81ecec',  // Functionality - Cyan
                    '4': '#a29bfe',  // Style - Purple
                    '5': '#fd79a8',  // Strengths - Pink
                    '6': '#fab1a0',  // Improvements - Orange
                    '7': '#dfe6e9',  // Grade Breakdown - Gray
                    '8': '#fdcb6e'   // Recommendations - Amber
                };
                
                // Convert markdown headers with colored backgrounds and highlights
                analysisText = analysisText.replace(/^## (\d+)\.\s*(.*$)/gim, function(match, num, title) {
                    var bgColor = sectionColors[num] || '#e9ecef';
                    return '<div style="background: linear-gradient(135deg, ' + bgColor + ' 0%, ' + bgColor + 'cc 100%); padding: 15px 20px; border-radius: 8px; margin: 25px 0 15px 0; border-left: 5px solid ' + bgColor.replace('cc', '') + '; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">' +
                           '<h2 style="margin: 0; color: #2c3e50; font-size: 18px; font-weight: bold; display: flex; align-items: center; gap: 10px;">' +
                           '<span style="background: rgba(255,255,255,0.8); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #2c3e50;">' + num + '</span>' +
                           title +
                           '</h2></div>';
                });
                
                analysisText = analysisText.replace(/^### (.*$)/gim, '<h3 style="color: #2c3e50; margin-top: 15px; margin-bottom: 8px; font-size: 16px; font-weight: bold; background: #f8f9fa; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #6c757d;">$1</h3>');
                analysisText = analysisText.replace(/^# (.*$)/gim, '<h1 style="color: #2c3e50; margin-top: 20px; margin-bottom: 12px; font-size: 20px; font-weight: bold;">$1</h1>');
                
                // Convert bold text with highlight
                analysisText = analysisText.replace(/\*\*(.*?)\*\*/gim, '<strong style="background: #fff3cd; padding: 2px 4px; border-radius: 3px; color: #2c3e50;">$1</strong>');
                
                // Convert code blocks with better styling
                analysisText = analysisText.replace(/```(\w+)?\n([\s\S]*?)```/gim, '<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 6px; border-left: 4px solid #667eea; overflow-x: auto; margin: 10px 0; font-size: 13px;"><code>$2</code></pre>');
                analysisText = analysisText.replace(/`([^`]+)`/gim, '<code style="background: #2c3e50; color: #ecf0f1; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 13px;">$1</code>');
                
                // Convert lists with better formatting
                analysisText = analysisText.replace(/^\- (.*$)/gim, '<li style="margin: 8px 0; padding-left: 10px; font-size: 13px; line-height: 1.6; color: #495057;">$1</li>');
                analysisText = analysisText.replace(/(<li.*<\/li>)/s, '<ul style="margin: 10px 0; padding-left: 25px; background: #f8f9fa; padding: 12px 25px; border-radius: 6px;">$1</ul>');
                
                // Convert line breaks with smaller font for descriptions
                analysisText = analysisText.replace(/\n\n/gim, '</p><p style="margin: 8px 0; font-size: 13px; line-height: 1.6; color: #495057;">');
                analysisText = '<p style="margin: 8px 0; font-size: 13px; line-height: 1.6; color: #495057;">' + analysisText + '</p>';
                
                result.innerHTML = successMsg + analysisText;
            } else {
                // Show error
                loading.style.display = 'none';
                error.style.display = 'block';
                errorMsg.textContent = response.analysis || 'Unknown error occurred';
            }
        }).catch(function(err) {
            // Show error
            loading.style.display = 'none';
            error.style.display = 'block';
            
            // Detailed error message
            var errorText = 'Failed to analyze code. ';
            if (err.message) {
                errorText += err.message;
            }
            if (err.errorcode) {
                errorText += ' (Error code: ' + err.errorcode + ')';
            }
            if (err.debuginfo) {
                errorText += ' Debug: ' + err.debuginfo;
            }
            
            errorMsg.textContent = errorText;
            console.error('AI Analysis Error:', err);
            console.error('Full error object:', JSON.stringify(err, null, 2));
        }).always(function() {
            // Re-enable button
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-robot"></i> AI Analyze';
            }
        });
    });
}

function closeAIAnalysis() {
    document.getElementById('aiAnalysisModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAIAnalysis();
    }
});

// Close modal when clicking outside
document.getElementById('aiAnalysisModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAIAnalysis();
    }
});
</script>

<?php
echo $OUTPUT->footer();



