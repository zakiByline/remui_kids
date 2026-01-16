<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Prints a particular instance of codeeditor
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/codeeditor/lib.php');

// Course module ID.
$id = optional_param('id', 0, PARAM_INT);
// Activity instance ID.
$c = optional_param('c', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('codeeditor', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $codeeditor = $DB->get_record('codeeditor', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($c) {
    $codeeditor = $DB->get_record('codeeditor', array('id' => $c), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $codeeditor->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('codeeditor', $codeeditor->id, $course->id, false, MUST_EXIST);
} else {
    print_error('missingidandcmid', 'codeeditor');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/codeeditor:view', $context);

// Add navigation links for admin and teachers
$PAGE->navbar->add(get_string('codeeditor', 'codeeditor'), new moodle_url('/mod/codeeditor/view.php', array('id' => $cm->id)));

// Add admin/teacher dashboard links
if (has_capability('moodle/site:config', context_system::instance())) {
    $PAGE->navbar->add(get_string('admin_submissions', 'codeeditor'), new moodle_url('/mod/codeeditor/admin_submissions.php'));
} else if (has_capability('mod/codeeditor:addinstance', context_system::instance())) {
    $PAGE->navbar->add(get_string('teacher_dashboard', 'codeeditor'), new moodle_url('/mod/codeeditor/teacher_dashboard.php'));
}

// Completion and trigger events.
codeeditor_view($codeeditor, $course, $cm, $context);

// Print the page header.
$PAGE->set_url('/mod/codeeditor/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($codeeditor->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Output starts here.
echo $OUTPUT->header();

// Display activity name and description.
echo $OUTPUT->heading(format_string($codeeditor->name));

// Add admin tool test link for administrators
if (has_capability('moodle/site:config', context_system::instance())) {
    echo '<div class="alert alert-info mb-3">';
    echo '<strong>Admin Notice:</strong> ';
    echo '<a href="' . $CFG->wwwroot . '/mod/codeeditor/test_admin_tool.php" class="btn btn-sm btn-outline-primary">Test Admin Tool</a> ';
    echo '<a href="' . $CFG->wwwroot . '/admin/tool/codeeditor_submissions/index.php" class="btn btn-sm btn-outline-success">View Submissions</a>';
    echo '</div>';
}

// Display intro if available.
if ($codeeditor->intro) {
    echo $OUTPUT->box(format_module_intro('codeeditor', $codeeditor, $cm->id), 'generalbox mod_introbox', 'codeeditorintro');
}

// Display additional description if available.
if (!empty($codeeditor->description)) {
    $descriptionformat = isset($codeeditor->descriptionformat) ? $codeeditor->descriptionformat : FORMAT_HTML;
    echo $OUTPUT->box(format_text($codeeditor->description, $descriptionformat, array('context' => $context)), 'generalbox', 'codeeditordescription');
}

// Display grading information and buttons
echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';

// For teachers and admins - show submissions button
if (has_capability('mod/codeeditor:grade', $context) || has_capability('moodle/site:config', context_system::instance())) {
    // Get submission statistics
    $total_students = count(get_enrolled_users($context, 'mod/codeeditor:submit'));
    $submissions = $DB->get_records('codeeditor_submissions', array('codeeditorid' => $codeeditor->id, 'latest' => 1, 'status' => 'submitted'));
    $graded = $DB->count_records_select('codeeditor_submissions', 'codeeditorid = ? AND latest = 1 AND status = ? AND grade IS NOT NULL', array($codeeditor->id, 'submitted'));
    
    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">';
    echo '<div>';
    echo '<h3 style="margin: 0 0 8px 0; color: #2c3e50;"><i class="fa fa-chart-bar" style="color: #6c757d;"></i> Submissions Overview</h3>';
    echo '<div style="display: flex; gap: 20px; font-size: 14px; color: #6c757d; flex-wrap: wrap;">';
    echo '<span><i class="fa fa-users" style="color: #6c757d;"></i> <strong>' . count($submissions) . '</strong> submissions</span>';
    echo '<span><i class="fa fa-check-circle" style="color: #6c757d;"></i> <strong>' . $graded . '</strong> graded</span>';
    echo '<span><i class="fa fa-clock" style="color: #6c757d;"></i> <strong>' . (count($submissions) - $graded) . '</strong> pending</span>';
    echo '</div>';
    echo '</div>';
    
    // Buttons for viewing/grading submissions
    echo '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';
    
    // View Submissions button (for both admin and teacher)
    $gradingurl = new moodle_url('/mod/codeeditor/grading.php', array('id' => $cm->id));
    echo '<a href="' . $gradingurl . '" class="btn btn-primary" style="background: #007bff; padding: 10px 20px; color: white; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; border: none;">';
    echo '<i class="fa fa-list"></i> View Submissions (' . count($submissions) . ')';
    echo '</a>';
    
    // Grade Submissions button (only for teachers with grading permission)
    if (has_capability('mod/codeeditor:grade', $context)) {
        echo '<a href="' . $gradingurl . '" class="btn btn-success" style="background: #28a745; padding: 10px 20px; color: white; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; border: none;">';
        echo '<i class="fa fa-star"></i> Grade Submissions';
        echo '</a>';
    }
    
    echo '</div>';
    echo '</div>';
    
    // Show rubric info if available
    require_once($CFG->dirroot.'/grade/grading/lib.php');
    $gradingmanager = get_grading_manager($context, 'mod_codeeditor', 'submissions');
    $gradingmethod = $gradingmanager->get_active_method();
    
    if ($gradingmethod == 'rubric') {
        echo '<div style="background: #e3f2fd; padding: 12px; border-radius: 6px; margin-bottom: 15px;">';
        echo '<i class="fa fa-info-circle" style="color: #1976d2;"></i> ';
        echo '<strong style="color: #1976d2;">Rubric Grading Enabled</strong> - Grades will be calculated based on rubric criteria';
        echo '</div>';
    }
}

// For students AND admins - show submission status
$cansubmit = has_capability('mod/codeeditor:submit', $context) || has_capability('moodle/site:config', context_system::instance());
$isgrader = has_capability('mod/codeeditor:grade', $context);

// Show submission status for students and also for admin/teachers if they want to test
if ($cansubmit) {
    $submission = $DB->get_record('codeeditor_submissions', array(
        'codeeditorid' => $codeeditor->id,
        'userid' => $USER->id,
        'latest' => 1
    ));
    
    // Only show submission status section if not already shown above (for non-graders or when admin wants to test)
    if (!$isgrader || has_capability('moodle/site:config', context_system::instance())) {
        echo '<h3 style="margin: 0 0 15px 0; color: #2c3e50;">
              <i class="fa fa-user-circle" style="color: #6c757d;"></i> ' . (has_capability('moodle/site:config', context_system::instance()) ? 'Your Test Submission' : 'Submission Status') . '
              </h3>';
    
    if ($submission) {
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';
        
        // Submission status
        echo '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
        echo '<div style="font-size: 12px; color: #6c757d; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">STATUS</div>';
        if ($submission->status === 'submitted') {
            echo '<div style="font-size: 16px; font-weight: 600; color: #495057;"><i class="fa fa-check-circle" style="color: #6c757d;"></i> Submitted</div>';
        } else {
            echo '<div style="font-size: 16px; font-weight: 600; color: #495057;"><i class="fa fa-edit" style="color: #6c757d;"></i> Draft</div>';
        }
        echo '</div>';
        
        // Grade status
        echo '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
        echo '<div style="font-size: 12px; color: #6c757d; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">GRADE</div>';
        if ($submission->grade !== null && $submission->grade >= 0) {
            echo '<div style="font-size: 16px; font-weight: 600; color: #495057;"><i class="fa fa-star" style="color: #6c757d;"></i> ' . round($submission->grade, 2) . ' / ' . $codeeditor->grade . '</div>';
        } else {
            echo '<div style="font-size: 16px; font-weight: 600; color: #6c757d;"><i class="fa fa-minus-circle" style="color: #adb5bd;"></i> Not graded yet</div>';
        }
        echo '</div>';
        
        // Submission date
        echo '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
        echo '<div style="font-size: 12px; color: #6c757d; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">SUBMITTED</div>';
        echo '<div style="font-size: 16px; font-weight: 600; color: #495057;"><i class="fa fa-calendar" style="color: #6c757d;"></i> ' . userdate($submission->timecreated, '%d %b %Y') . '</div>';
        echo '</div>';
        
        // Submission ID
        echo '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
        echo '<div style="font-size: 12px; color: #6c757d; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">SUBMISSION ID</div>';
        echo '<div style="font-size: 16px; font-weight: 600; color: #495057;"><i class="fa fa-hashtag" style="color: #6c757d;"></i> ' . $submission->id . '</div>';
        echo '</div>';
        
        echo '</div>';
        
        // View full submission link
        echo '<div style="margin-top: 15px;">';
        echo '<a href="view_single_submission.php?id=' . $submission->id . '" class="btn btn-primary" style="background: #007bff; padding: 10px 20px; color: white; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px;">';
        echo '<i class="fa fa-external-link-alt"></i> View Full Submission';
        echo '</a>';
        echo '</div>';
        
        // Show feedback if available
        if (!empty($submission->feedbacktext)) {
            echo '<div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px;">';
            echo '<strong style="color: #495057;"><i class="fa fa-comment-alt" style="color: #6c757d;"></i> Teacher Feedback:</strong>';
            echo '<div style="margin-top: 8px; color: #495057;">' . format_text($submission->feedbacktext, $submission->feedbackformat) . '</div>';
            echo '</div>';
        }
    } else {
        $usertype = has_capability('moodle/site:config', context_system::instance()) ? 'admin' : 'student';
        echo '<div style="text-align: center; padding: 20px; color: #6c757d;">';
        echo '<i class="fa fa-info-circle" style="color: #adb5bd; font-size: 24px; margin-bottom: 10px; display: block;"></i> ';
        echo '<p style="margin: 0; color: #6c757d;">';
        if ($usertype == 'admin') {
            echo 'No submission yet. Use the code editor below to test code submission.';
        } else {
            echo 'No submission yet. Use the code editor below to write and submit your code.';
        }
        echo '</p>';
        echo '</div>';
    }
    }
}

// Show due date information
if ($codeeditor->duedate > 0) {
    $now = time();
    $isoverdue = $codeeditor->duedate < $now;
    
    echo '<div style="background: #f8f9fa; padding: 12px; border-radius: 6px; margin-top: 15px; border-left: 4px solid ' . ($isoverdue ? '#dc3545' : '#6c757d') . ';">';
    echo '<i class="fa fa-calendar-alt" style="color: #6c757d;"></i> ';
    echo '<strong style="color: #495057;">Due: ' . userdate($codeeditor->duedate, get_string('strftimedatetimeshort')) . '</strong>';
    if ($isoverdue) {
        echo ' <span style="color: #dc3545; font-weight: bold;"><i class="fa fa-exclamation-circle" style="color: #dc3545;"></i> Overdue</span>';
    }
    echo '</div>';
}

echo '</div>';

// Display the Code Editor - Available for students AND admins (for testing)
$canuseeditor = has_capability('mod/codeeditor:submit', $context) || has_capability('moodle/site:config', context_system::instance());

if ($canuseeditor) {
    ?>

    <style>
        .codeeditor-container {
            width: 100%;
            height: 90vh;
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .codeeditor-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
    </style>

    <div class="codeeditor-container">
        <iframe 
            src="<?php echo $CFG->wwwroot; ?>/mod/codeeditor/ide/complete-ide.html?v=<?php echo time(); ?>&userid=<?php echo $USER->id; ?>&cmid=<?php echo $cm->id; ?>&role=<?php echo (has_capability('moodle/site:config', context_system::instance()) ? 'admin' : 'student'); ?>" 
            class="codeeditor-iframe"
            id="codeeditor-frame"
            allowfullscreen
            allow="camera; microphone; fullscreen"
            title="<?php echo get_string('codeeditor', 'codeeditor'); ?>">
            <p>Your browser does not support iframes. Please update your browser or try a different one.</p>
        </iframe>
    </div>
    
    <!-- Submit Button (Floating) -->
    <button id="submit-code-btn" type="button" style="position: fixed; bottom: 30px; right: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 30px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 9999; display: flex; align-items: center; gap: 10px; transition: transform 0.2s;">
        <i class="fa fa-paper-plane"></i> Submit Code
    </button>
    
    <script>
    (function() {
        'use strict';
        
        // Prevent duplicate variable declarations
        if (typeof window.codeEditorInitialized !== 'undefined') {
            console.warn('Code editor script already initialized, skipping duplicate initialization');
            return;
        }
        window.codeEditorInitialized = true;
        
        // Global variables to store code data from IDE
        window.codeEditorData = {
            code: '',
            output: '',
            language: 'python'
        };
        
        // Listen for messages from IDE iframe
        window.addEventListener('message', function(event) {
            // Verify origin for security
            var expectedOrigin = '<?php echo $CFG->wwwroot; ?>';
            if (event.origin !== expectedOrigin) {
                console.log('Ignoring message from unexpected origin:', event.origin);
                return;
            }
            
            // Update code data when received from IDE
            if (event.data && event.data.type === 'code-data') {
                console.log('Received code data from IDE:', event.data);
                window.codeEditorData = {
                    code: event.data.code || '',
                    output: event.data.output || '',
                    language: event.data.language || 'python'
                };
            }
        });
        
        // Request code data from IDE periodically
        setInterval(function() {
            var iframe = document.getElementById('codeeditor-frame');
            if (iframe && iframe.contentWindow) {
                try {
                    // Try direct access first
                    if (iframe.contentWindow.editor && typeof iframe.contentWindow.editor.getValue === 'function') {
                        window.codeEditorData.code = iframe.contentWindow.editor.getValue();
                    }
                    var termEl = iframe.contentWindow.document.querySelector('#terminal-output, .terminal-output');
                    if (termEl) {
                        window.codeEditorData.output = termEl.textContent || termEl.innerText;
                    }
                } catch (e) {
                    // Fallback to postMessage if direct access fails
                    iframe.contentWindow.postMessage({ type: 'request-code-data' }, '<?php echo $CFG->wwwroot; ?>');
                }
            }
        }, 2000); // Every 2 seconds
        
        // Submit code handler
        var submitBtn = document.getElementById('submit-code-btn');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                var btn = this;
                var iframe = document.getElementById('codeeditor-frame');
                
                console.log('Submit button clicked!');
                
                // SIMPLE METHOD: Get code from global variables exposed by IDE
                var code = '';
                var output = '';
                var language = 'javascript';
                
                try {
                    console.log('Attempting to get code from iframe...');
                    
                    if (iframe && iframe.contentWindow) {
                        // Method 1: Try global variables (EASIEST - IDE exposes these)
                        if (iframe.contentWindow.submitCode) {
                            code = iframe.contentWindow.submitCode;
                            console.log('✅ Got code from window.submitCode:', code.length + ' characters');
                        }
                        
                        if (iframe.contentWindow.submitOutput) {
                            output = iframe.contentWindow.submitOutput;
                            console.log('✅ Got output from window.submitOutput:', output.length + ' characters');
                        }
                        
                        if (iframe.contentWindow.submitLanguage) {
                            language = iframe.contentWindow.submitLanguage;
                            console.log('✅ Got language from window.submitLanguage:', language);
                        }
                        
                        // Method 2: Try direct editor access (fallback)
                        if (!code && iframe.contentWindow.editor && typeof iframe.contentWindow.editor.getValue === 'function') {
                            code = iframe.contentWindow.editor.getValue();
                            console.log('✅ Got code from editor.getValue():', code.length + ' characters');
                        }
                        
                        // Method 3: Try output element (fallback)
                        if (!output) {
                            try {
                                var outputEl = iframe.contentWindow.document.getElementById('output-content');
                                if (outputEl) {
                                    output = outputEl.textContent || outputEl.innerText || outputEl.innerHTML;
                                    console.log('✅ Got output from #output-content:', output.length + ' characters');
                                }
                            } catch (e) {
                                console.log('Cannot access output element:', e.message);
                            }
                        }
                        
                        // Method 4: Try language from editor model (fallback)
                        if (!language || language === 'javascript') {
                            try {
                                if (iframe.contentWindow.editor && iframe.contentWindow.editor.getModel) {
                                    var model = iframe.contentWindow.editor.getModel();
                                    if (model && model.getLanguageId) {
                                        language = model.getLanguageId();
                                        console.log('✅ Got language from Monaco model:', language);
                                    }
                                }
                            } catch (e) {
                                console.log('Cannot access language:', e.message);
                            }
                        }
                    }
                } catch (e) {
                    console.error('Error accessing iframe:', e);
                    // Use stored data as last resort
                    code = window.codeEditorData.code || '';
                    output = window.codeEditorData.output || '';
                    language = window.codeEditorData.language || 'javascript';
                }
                
                console.log('Final extracted data:', {
                    codeLength: code.length,
                    outputLength: output.length,
                    language: language,
                    codePreview: code.substring(0, 100)
                });
                
                if (!code || code.trim() === '') {
                    alert('⚠️ No code detected!\n\nDebugging info:\n- Code length: ' + code.length + '\n- Check browser console for details\n\nPlease:\n1. Write code in the editor\n2. Run your code\n3. Then click Submit');
                    return;
                }
                
                // Disable button
                btn.disabled = true;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';
                
                console.log('Sending submission to server...');
                
                // Submit via AJAX
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo $CFG->wwwroot; ?>/mod/codeeditor/submit_code.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    console.log('Response received. Status:', xhr.status);
                    console.log('Response text:', xhr.responseText);
                    
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            console.log('Parsed response:', response);
                            
                            if (response.success) {
                                alert('✅ CODE SUBMITTED SUCCESSFULLY!\n\n' +
                                      'Submission ID: ' + response.submissionid + '\n' +
                                      'Time: ' + response.timestamp + '\n\n' +
                                      'Your submission is now visible in the submissions list!');
                                
                                // Reload page to show submission status
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                alert('❌ Submission failed: ' + (response.error || 'Unknown error'));
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Code';
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            alert('Error processing server response: ' + e.message + '\n\nResponse: ' + xhr.responseText);
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Code';
                        }
                    } else {
                        console.error('HTTP Error:', xhr.status);
                        alert('❌ Submission failed (HTTP ' + xhr.status + ')\n\nPlease try again or contact administrator.');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Code';
                    }
                };
                
                xhr.onerror = function() {
                    console.error('Network error during submission');
                    alert('❌ Network error. Please check your connection and try again.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Code';
                };
                
                var params = 'cmid=<?php echo $cm->id; ?>' +
                            '&code=' + encodeURIComponent(code) +
                            '&language=' + encodeURIComponent(language) +
                            '&output=' + encodeURIComponent(output) +
                            '&sesskey=' + M.cfg.sesskey;
                
                console.log('Sending params (code/output truncated):', {
                    cmid: <?php echo $cm->id; ?>,
                    codeLength: code.length,
                    outputLength: output.length,
                    language: language
                });
                
                xhr.send(params);
            });
            
            // Hover effect
            submitBtn.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
            });
            submitBtn.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        }
    })();
    </script>

    <?php
} else {
    echo '<div class="alert alert-warning">';
    echo '<i class="fa fa-exclamation-triangle"></i> You do not have permission to use the code editor.';
    echo '</div>';
}
// Note: Footer intentionally removed for full-screen IDE experience
