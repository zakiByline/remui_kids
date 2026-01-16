// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Submit handler for code editor
 *
 * @module     mod_codeeditor/submit_handler
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    
    return {
        init: function(cmid, userid, wwwroot) {
            
            // Add submit button to IDE if not already there
            function addSubmitButton() {
                // Check if submit button already exists
                if ($('#submit-code-btn').length > 0) {
                    return;
                }
                
                // Find IDE container or toolbar
                var toolbar = $('.monaco-editor-toolbar, .editor-toolbar, #editor-controls');
                
                if (toolbar.length === 0) {
                    // Try to find any suitable container
                    toolbar = $('body');
                }
                
                // Create submit button
                var submitBtn = $('<button>')
                    .attr('id', 'submit-code-btn')
                    .attr('type', 'button')
                    .css({
                        'position': 'fixed',
                        'bottom': '30px',
                        'right': '30px',
                        'background': 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                        'color': 'white',
                        'border': 'none',
                        'padding': '15px 30px',
                        'border-radius': '8px',
                        'font-size': '16px',
                        'font-weight': 'bold',
                        'cursor': 'pointer',
                        'box-shadow': '0 4px 15px rgba(0,0,0,0.2)',
                        'z-index': '9999',
                        'transition': 'transform 0.2s',
                        'display': 'flex',
                        'align-items': 'center',
                        'gap': '10px'
                    })
                    .html('<i class="fa fa-paper-plane"></i> Submit Code')
                    .hover(
                        function() { $(this).css('transform', 'scale(1.05)'); },
                        function() { $(this).css('transform', 'scale(1)'); }
                    );
                
                $('body').append(submitBtn);
                
                // Handle click
                submitBtn.on('click', function() {
                    submitCode();
                });
            }
            
            // Submit code function
            function submitCode() {
                // Get code from IDE (this depends on your IDE implementation)
                var code = getCodeFromEditor();
                var output = getOutputFromTerminal();
                var language = getSelectedLanguage();
                
                if (!code || code.trim() === '') {
                    Notification.alert('Error', 'Please write some code before submitting!', 'OK');
                    return;
                }
                
                // Disable button during submission
                $('#submit-code-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Submitting...');
                
                // Submit via AJAX
                $.ajax({
                    url: wwwroot + '/mod/codeeditor/submit_code.php',
                    method: 'POST',
                    data: {
                        cmid: cmid,
                        code: code,
                        language: language,
                        output: output,
                        sesskey: M.cfg.sesskey
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Notification.alert('Success', 'Code submitted successfully! ' + response.message, 'OK');
                            // Reload page to show submission status
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            Notification.alert('Error', response.error || 'Submission failed', 'OK');
                            $('#submit-code-btn').prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Submit Code');
                        }
                    },
                    error: function(xhr, status, error) {
                        Notification.alert('Error', 'Failed to submit code. Please try again.', 'OK');
                        $('#submit-code-btn').prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Submit Code');
                    }
                });
            }
            
            // Helper functions to get data from IDE
            // These need to be adapted based on your IDE implementation
            function getCodeFromEditor() {
                // Try different methods to get code
                // Monaco Editor
                if (window.monacoEditor) {
                    return window.monacoEditor.getValue();
                }
                // CodeMirror
                if (window.codeMirrorEditor) {
                    return window.codeMirrorEditor.getValue();
                }
                // From iframe content
                var iframe = $('.codeeditor-iframe')[0];
                if (iframe && iframe.contentWindow) {
                    try {
                        if (iframe.contentWindow.getEditorCode) {
                            return iframe.contentWindow.getEditorCode();
                        }
                        if (iframe.contentWindow.editor) {
                            return iframe.contentWindow.editor.getValue();
                        }
                    } catch (e) {
                        console.error('Cannot access iframe content:', e);
                    }
                }
                // From textarea
                var textarea = $('textarea#code-editor, textarea.code-editor').val();
                if (textarea) {
                    return textarea;
                }
                return '';
            }
            
            function getOutputFromTerminal() {
                // Try to get output from terminal
                var iframe = $('.codeeditor-iframe')[0];
                if (iframe && iframe.contentWindow) {
                    try {
                        if (iframe.contentWindow.getTerminalOutput) {
                            return iframe.contentWindow.getTerminalOutput();
                        }
                        if (iframe.contentWindow.terminalOutput) {
                            return iframe.contentWindow.terminalOutput;
                        }
                    } catch (e) {
                        console.error('Cannot access iframe output:', e);
                    }
                }
                var output = $('.output-terminal, .terminal-output, #output').text();
                return output || 'No output captured';
            }
            
            function getSelectedLanguage() {
                // Try to get selected language
                var iframe = $('.codeeditor-iframe')[0];
                if (iframe && iframe.contentWindow) {
                    try {
                        if (iframe.contentWindow.getSelectedLanguage) {
                            return iframe.contentWindow.getSelectedLanguage();
                        }
                        if (iframe.contentWindow.currentLanguage) {
                            return iframe.contentWindow.currentLanguage;
                        }
                    } catch (e) {
                        console.error('Cannot access iframe language:', e);
                    }
                }
                var lang = $('select#language-selector, select.language-selector').val();
                return lang || 'python';
            }
            
            // Initialize when page loads
            $(document).ready(function() {
                // Wait a bit for IDE to load
                setTimeout(addSubmitButton, 1000);
            });
        }
    };
});




