/**
 * AI Code Analysis Module
 *
 * @module     mod_codeeditor/ai_analyze
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    
    return {
        /**
         * Analyze code using AI
         * @param {string} code
         * @param {string} language
         * @param {string} output
         */
        analyze: function(code, language, output) {
            var modal = document.getElementById('aiAnalysisModal');
            var loading = document.getElementById('aiAnalysisLoading');
            var content = document.getElementById('aiAnalysisContent');
            var result = document.getElementById('aiAnalysisResult');
            var error = document.getElementById('aiAnalysisError');
            var errorMsg = document.getElementById('aiAnalysisErrorMsg');
            var btn = document.getElementById('ai-analyze-btn');
            
            if (!modal) {
                Notification.addNotification({
                    message: 'AI Analysis modal not found',
                    type: 'error'
                });
                return;
            }
            
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
            
            // Make AJAX call
            var promises = Ajax.call([{
                methodname: 'mod_codeeditor_analyze_code',
                args: {
                    code: code,
                    language: language,
                    output: output || '',
                    context: 'Code submission for grading'
                }
            }]);
            
            promises[0].then(function(response) {
                if (response.success) {
                    // Show analysis result
                    loading.style.display = 'none';
                    content.style.display = 'block';
                    
                    // Convert markdown-like formatting to HTML
                    var analysisText = response.analysis;
                    
                    // Convert markdown headers to HTML
                    analysisText = analysisText.replace(/^### (.*$)/gim, '<h3 style="color: #495057; margin-top: 20px; margin-bottom: 10px; font-size: 18px; font-weight: bold;">$1</h3>');
                    analysisText = analysisText.replace(/^## (.*$)/gim, '<h2 style="color: #495057; margin-top: 25px; margin-bottom: 15px; font-size: 20px; font-weight: bold; border-bottom: 2px solid #667eea; padding-bottom: 8px;">$1</h2>');
                    analysisText = analysisText.replace(/^# (.*$)/gim, '<h1 style="color: #495057; margin-top: 25px; margin-bottom: 15px; font-size: 24px; font-weight: bold;">$1</h1>');
                    
                    // Convert bold
                    analysisText = analysisText.replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>');
                    
                    // Convert code blocks
                    analysisText = analysisText.replace(/```(\w+)?\n([\s\S]*?)```/gim, '<pre style="background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #667eea; overflow-x: auto; margin: 10px 0;"><code>$2</code></pre>');
                    analysisText = analysisText.replace(/`([^`]+)`/gim, '<code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace;">$1</code>');
                    
                    // Convert lists
                    analysisText = analysisText.replace(/^\- (.*$)/gim, '<li style="margin: 5px 0; padding-left: 10px;">$1</li>');
                    analysisText = analysisText.replace(/(<li.*<\/li>)/s, '<ul style="margin: 10px 0; padding-left: 25px;">$1</ul>');
                    
                    // Convert line breaks
                    analysisText = analysisText.replace(/\n\n/gim, '</p><p style="margin: 10px 0;">');
                    analysisText = '<p style="margin: 10px 0;">' + analysisText + '</p>';
                    
                    result.innerHTML = analysisText;
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
                errorMsg.textContent = err.message || 'Failed to analyze code. Please try again.';
            }).always(function() {
                // Re-enable button
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-robot"></i> AI Analyze';
                }
            });
        }
    };
});

