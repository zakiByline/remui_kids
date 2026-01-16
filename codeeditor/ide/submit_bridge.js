/**
 * Bridge script for Code Editor IDE to communicate with Moodle parent page
 * This script should be included in complete-ide.html
 */

(function() {
    console.log('Code Editor Submit Bridge v2.0 loaded');
    console.log('Editor available:', typeof editor !== 'undefined');
    console.log('Monaco available:', typeof monaco !== 'undefined');
    
    // Function to get current code from editor
    function getEditorCode() {
        try {
            // Method 1: Try global editor variable (Monaco Editor)
            if (typeof editor !== 'undefined' && editor && typeof editor.getValue === 'function') {
                var code = editor.getValue();
                console.log('Got code from editor.getValue():', code.length + ' characters');
                return code;
            }
            
            // Method 2: Try window.editor
            if (window.editor && typeof window.editor.getValue === 'function') {
                var code = window.editor.getValue();
                console.log('Got code from window.editor.getValue():', code.length + ' characters');
                return code;
            }
            
            // Method 3: Try Monaco models
            if (window.monaco && window.monaco.editor) {
                var models = window.monaco.editor.getModels();
                if (models && models.length > 0) {
                    var code = models[0].getValue();
                    console.log('Got code from Monaco model:', code.length + ' characters');
                    return code;
                }
            }
            
            // Method 4: Try all Monaco editor instances
            if (window.monaco && window.monaco.editor) {
                var editors = window.monaco.editor.getEditors && window.monaco.editor.getEditors();
                if (editors && editors.length > 0) {
                    var code = editors[0].getValue();
                    console.log('Got code from Monaco editors array:', code.length + ' characters');
                    return code;
                }
            }
            
            // Method 5: Try CodeMirror
            if (window.CodeMirror && window.cm && typeof window.cm.getValue === 'function') {
                var code = window.cm.getValue();
                console.log('Got code from CodeMirror:', code.length + ' characters');
                return code;
            }
            
            // Method 6: Fallback to textarea
            var textarea = document.querySelector('textarea.code-editor, #code-editor, textarea');
            if (textarea && textarea.value) {
                console.log('Got code from textarea:', textarea.value.length + ' characters');
                return textarea.value;
            }
            
            console.warn('Could not find editor - no code extracted');
            return '';
            
        } catch (e) {
            console.error('Error getting editor code:', e);
            return '';
        }
    }
    
    // Function to get terminal output
    function getTerminalOutput() {
        try {
            // Method 1: Try terminal element by ID or class
            var selectors = [
                '#terminal-output',
                '.terminal-output',
                '#output',
                '.output-content',
                '.terminal-content',
                '#console-output',
                '.console-output',
                '[data-terminal-output]'
            ];
            
            for (var i = 0; i < selectors.length; i++) {
                var terminal = document.querySelector(selectors[i]);
                if (terminal) {
                    var output = terminal.textContent || terminal.innerText || terminal.innerHTML;
                    if (output && output.trim()) {
                        console.log('Got output from selector ' + selectors[i] + ':', output.length + ' characters');
                        return output;
                    }
                }
            }
            
            // Method 2: Try global variables
            if (window.terminalOutput && window.terminalOutput.trim()) {
                console.log('Got output from window.terminalOutput');
                return window.terminalOutput;
            }
            
            if (window.lastOutput && window.lastOutput.trim()) {
                console.log('Got output from window.lastOutput');
                return window.lastOutput;
            }
            
            if (window.output && window.output.trim()) {
                console.log('Got output from window.output');
                return window.output;
            }
            
            // Method 3: Look for any element with "terminal" or "output" in class/id
            var allOutputElements = document.querySelectorAll('[class*="terminal"], [id*="terminal"], [class*="output"], [id*="output"]');
            for (var j = 0; j < allOutputElements.length; j++) {
                var elem = allOutputElements[j];
                var text = elem.textContent || elem.innerText;
                if (text && text.trim() && text.length > 5) {
                    console.log('Got output from element:', elem.className || elem.id, text.length + ' characters');
                    return text;
                }
            }
            
            console.warn('Could not find terminal output');
            return 'No output captured yet';
            
        } catch (e) {
            console.error('Error getting terminal output:', e);
            return 'Error capturing output';
        }
    }
    
    // Function to get selected language
    function getSelectedLanguage() {
        try {
            // Method 1: Try language selector dropdown
            var selectors = [
                'select#language-selector',
                'select.language-selector',
                'select#languageSelect',
                '#language',
                '.language-select',
                '[data-language-selector]'
            ];
            
            for (var i = 0; i < selectors.length; i++) {
                var langSelect = document.querySelector(selectors[i]);
                if (langSelect && langSelect.value) {
                    console.log('Got language from selector ' + selectors[i] + ':', langSelect.value);
                    return langSelect.value;
                }
            }
            
            // Method 2: Try Monaco Editor language
            if (typeof editor !== 'undefined' && editor && editor.getModel) {
                var model = editor.getModel();
                if (model && model.getLanguageId) {
                    var lang = model.getLanguageId();
                    console.log('Got language from Monaco model:', lang);
                    return lang;
                }
            }
            
            // Method 3: Try stored language variables
            if (window.selectedLanguage) {
                console.log('Got language from window.selectedLanguage:', window.selectedLanguage);
                return window.selectedLanguage;
            }
            
            if (window.currentLanguage) {
                console.log('Got language from window.currentLanguage:', window.currentLanguage);
                return window.currentLanguage;
            }
            
            // Method 4: Check URL parameters
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('language')) {
                console.log('Got language from URL:', urlParams.get('language'));
                return urlParams.get('language');
            }
            
            console.warn('Could not detect language, defaulting to javascript');
            return 'javascript';
            
        } catch (e) {
            console.error('Error getting language:', e);
            return 'javascript';
        }
    }
    
    // Expose functions globally for parent window access
    window.getEditorCode = getEditorCode;
    window.getTerminalOutput = getTerminalOutput;
    window.getSelectedLanguage = getSelectedLanguage;
    
    // Listen for requests from parent window
    window.addEventListener('message', function(event) {
        console.log('IDE received message:', event.data);
        
        if (event.data && event.data.type === 'request-code-data') {
            // Parent is requesting code data
            var codeData = {
                type: 'code-data',
                code: getEditorCode(),
                output: getTerminalOutput(),
                language: getSelectedLanguage(),
                timestamp: new Date().toISOString()
            };
            
            console.log('IDE sending code data:', codeData);
            
            // Send back to parent
            window.parent.postMessage(codeData, event.origin);
        }
    });
    
    // Also send code data whenever it changes
    var lastCode = '';
    var lastOutput = '';
    
    function monitorChanges() {
        try {
            var currentCode = getEditorCode();
            var currentOutput = getTerminalOutput();
            
            if (currentCode !== lastCode || currentOutput !== lastOutput) {
                lastCode = currentCode;
                lastOutput = currentOutput;
                
                console.log('Code/Output changed - sending update to parent');
                
                // Send updated data to parent
                var codeData = {
                    type: 'code-data',
                    code: currentCode,
                    output: currentOutput,
                    language: getSelectedLanguage(),
                    timestamp: new Date().toISOString()
                };
                
                window.parent.postMessage(codeData, '*');
            }
        } catch (e) {
            console.error('Error in monitorChanges:', e);
        }
    }
    
    // Wait for editor to be ready before monitoring
    function startMonitoring() {
        // Check if editor is ready
        if (typeof editor !== 'undefined' && editor) {
            console.log('Editor is ready, starting monitoring');
            setInterval(monitorChanges, 2000);
        } else {
            console.log('Editor not ready yet, retrying in 1 second...');
            setTimeout(startMonitoring, 1000);
        }
    }
    
    // Start monitoring after page loads
    if (document.readyState === 'complete') {
        startMonitoring();
    } else {
        window.addEventListener('load', startMonitoring);
    }
    
    // Also monitor when user runs code
    document.addEventListener('click', function(e) {
        // If run button clicked, send update after a delay
        if (e.target.matches('.run-button, #run-code, [data-action="run"]') || 
            e.target.closest('.run-button, #run-code, [data-action="run"]')) {
            setTimeout(monitorChanges, 1000); // Wait for code to execute
        }
    });
    
    console.log('Code Editor Submit Bridge ready - functions exposed globally');
    
})();

