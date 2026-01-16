/**
 * AI Assistant Button Injector - JavaScript IDE Only
 * This script adds the AI Assistant button to your code editor
 * ONLY when JavaScript is selected as the language
 */

(function() {
    'use strict';

    let aiButton = null;
    let aiPanel = null;

    // Function to check if current language is JavaScript
    function isJavaScriptLanguage() {
        try {
            // Method 1: Check language selector dropdown value
            const langSelect = document.querySelector('select[id*="language"], select[class*="language"], select.language-selector');
            if (langSelect) {
                const selectedValue = langSelect.value || langSelect.options[langSelect.selectedIndex]?.value;
                const selectedText = langSelect.options[langSelect.selectedIndex]?.text || '';
                
                if (selectedValue && (
                    selectedValue.toLowerCase().includes('javascript') ||
                    selectedValue.toLowerCase().includes('js') ||
                    selectedValue.toLowerCase() === '63' || // JavaScript language ID
                    selectedText.toLowerCase().includes('javascript')
                )) {
                    return true;
                }
            }
            
            // Method 2: Check for JavaScript in window variables
            if (window.getSelectedLanguage) {
                const lang = window.getSelectedLanguage();
                if (lang && (lang.toLowerCase().includes('javascript') || lang.toLowerCase().includes('js'))) {
                    return true;
                }
            }
            
            if (window.selectedLanguage) {
                const lang = window.selectedLanguage;
                if (lang && (lang.toLowerCase().includes('javascript') || lang.toLowerCase().includes('js'))) {
                    return true;
                }
            }
            
            // Method 3: Check URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('language')) {
                const lang = urlParams.get('language');
                if (lang && (lang.toLowerCase().includes('javascript') || lang.toLowerCase().includes('js'))) {
                    return true;
                }
            }
            
            // Method 4: Check for JavaScript indicators in page
            const pageText = document.body ? document.body.innerText.toLowerCase() : '';
            if (pageText.includes('javascript') && !pageText.includes('typescript')) {
                const jsIndicators = document.querySelectorAll('[class*="javascript"], [id*="javascript"], [data-language*="javascript"]');
                if (jsIndicators.length > 0) {
                    return true;
                }
            }
            
            return false;
        } catch(e) {
            console.log('Error checking language:', e);
            return false;
        }
    }
    
    // Function to show/hide AI button based on language
    function updateAIButtonVisibility() {
        const isJS = isJavaScriptLanguage();
        
        if (aiButton) {
            if (isJS) {
                aiButton.style.display = 'flex';
                console.log('✅ JavaScript detected - AI Assistant shown');
            } else {
                aiButton.style.display = 'none';
                if (aiPanel) {
                    aiPanel.classList.remove('active');
                }
                console.log('ℹ️ Non-JavaScript language - AI Assistant hidden');
            }
        }
    }

    // Wait for the page to load
    function injectAIButton() {
        // Find the button container (look for Run Code, Clear Output buttons)
        const buttonContainers = [
            '.editor-controls',
            '.editor-header-actions',
            '.toolbar',
            '.header-actions',
            '[class*="button"]',
            '[class*="control"]'
        ];

        let container = null;
        for (const selector of buttonContainers) {
            container = document.querySelector(selector);
            if (container) break;
        }

        // If we can't find the container, look for any button and use its parent
        if (!container) {
            const anyButton = document.querySelector('button');
            if (anyButton) {
                container = anyButton.parentElement;
            }
        }

        if (!container) {
            console.log('Could not find button container, retrying...');
            setTimeout(injectAIButton, 1000);
            return;
        }

        // Check if AI button already exists
        if (document.getElementById('ai-assistant-btn')) {
            aiButton = document.getElementById('ai-assistant-btn');
            updateAIButtonVisibility();
            return;
        }

        // Check if JavaScript is selected before creating button
        if (!isJavaScriptLanguage()) {
            console.log('⚠️ Not JavaScript - AI Assistant will not be shown');
        }

        console.log('Injecting AI Assistant button (JavaScript IDE only)...');

        // Create AI Assistant button
        aiButton = document.createElement('button');
        aiButton.id = 'ai-assistant-btn';
        aiButton.className = 'ai-assistant-toggle';
        aiButton.title = 'AI Assistant (JavaScript Only)';
        aiButton.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                      d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
            <span>AI Assistant</span>
            <span class="ai-pulse"></span>
        `;
        // Initially hide if not JavaScript
        aiButton.style.display = isJavaScriptLanguage() ? 'flex' : 'none';

        // Add styles
        const style = document.createElement('style');
        style.textContent = `
            .ai-assistant-toggle {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 500;
                font-size: 14px;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
                position: relative;
                margin-right: 12px;
            }

            .ai-assistant-toggle:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }

            .ai-pulse {
                position: absolute;
                top: 5px;
                right: 5px;
                width: 8px;
                height: 8px;
                background: #4ade80;
                border-radius: 50%;
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0%, 100% {
                    opacity: 1;
                    transform: scale(1);
                }
                50% {
                    opacity: 0.5;
                    transform: scale(1.2);
                }
            }

            .ai-assistant-panel {
                position: fixed;
                left: 0;
                top: 0;
                width: 400px;
                height: 100vh;
                background: #1e1e1e;
                border-right: 1px solid #3e3e42;
                display: none;
                flex-direction: column;
                z-index: 10000;
                box-shadow: 4px 0 16px rgba(0, 0, 0, 0.3);
            }

            .ai-assistant-panel.active {
                display: flex;
                animation: slideInFromLeft 0.3s ease;
            }

            @keyframes slideInFromLeft {
                from {
                    opacity: 0;
                    transform: translateX(-100%);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }

            .ai-panel-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                background: #2d2d30;
                border-bottom: 1px solid #3e3e42;
                color: #cccccc;
            }

            .ai-panel-title {
                display: flex;
                align-items: center;
                gap: 10px;
                font-weight: 600;
            }

            .ai-close-btn {
                background: rgba(255, 255, 255, 0.2);
                border: none;
                color: white;
                font-size: 24px;
                width: 32px;
                height: 32px;
                border-radius: 8px;
                cursor: pointer;
            }

            .ai-panel-content {
                flex: 1;
                padding: 20px;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                background: #1e1e1e;
                color: #cccccc;
            }

            .ai-welcome {
                font-size: 18px;
                color: #667eea;
                margin-bottom: 20px;
                text-align: center;
            }

            .ai-instructions {
                color: #cccccc;
                line-height: 1.6;
            }
            
            .ai-instructions p {
                color: #cccccc;
            }
            
            .ai-instructions ul {
                color: #cccccc;
            }

            .ai-quick-actions {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-top: 20px;
                width: 100%;
            }

            .ai-action-btn {
                padding: 12px;
                background: #3c3c3c;
                border: 1px solid #555;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                font-size: 14px;
                color: #cccccc;
            }

            .ai-action-btn:hover {
                background: #667eea;
                color: white;
                border-color: #667eea;
                transform: translateY(-2px);
            }
        `;

        document.head.appendChild(style);

        // Insert button BEFORE the first button in container
        const firstButton = container.querySelector('button');
        if (firstButton) {
            container.insertBefore(aiButton, firstButton);
        } else {
            container.appendChild(aiButton);
        }

        // Create AI Panel
        aiPanel = document.createElement('div');
        aiPanel.className = 'ai-assistant-panel';
        aiPanel.innerHTML = `
            <div class="ai-panel-header">
                <div class="ai-panel-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                    AI Coding Assistant
                </div>
                <button class="ai-close-btn">×</button>
            </div>
            <div class="ai-panel-content">
                <div class="ai-welcome"> AI Assistant Ready!</div>
                <div class="ai-instructions">
                    <p><strong>I can help you with your JavaScript code:</strong></p>
                    <ul style="text-align: left; margin: 20px auto; max-width: 300px;">
                        <li>Explaining your code</li>
                        <li>Finding and fixing bugs</li>
                        <li>Optimizing performance</li>
                        <li>Adding documentation</li>
                        <li>Suggesting improvements</li>
                    </ul>
                    <p style="margin-top: 20px; font-size: 12px; color: #999; text-align: center;">
                        <strong>Note:</strong> AI Assistant is available for JavaScript only.<br>
                        To fully activate AI features, complete the backend setup:<br>
                        See <code style="background: #2d2d30; padding: 2px 6px; border-radius: 4px; color: #cccccc;">BUILD_AND_DEPLOY_INSTRUCTIONS.md</code>
                    </p>
                </div>
                <div class="ai-quick-actions">
                    <button class="ai-action-btn"> Explain Code</button>
                    <button class="ai-action-btn"> Find Bugs</button>
                    <button class="ai-action-btn"> Optimize</button>
                    <button class="ai-action-btn"> Add Docs</button>
                </div>
            </div>
        `;

        document.body.appendChild(aiPanel);

        // Add click handlers
        aiButton.addEventListener('click', () => {
            // Double-check JavaScript before opening
            if (isJavaScriptLanguage()) {
                aiPanel.classList.toggle('active');
                console.log('AI Assistant panel toggled');
            } else {
                alert('AI Assistant is only available for JavaScript. Please select JavaScript from the language dropdown.');
            }
        });

        aiPanel.querySelector('.ai-close-btn').addEventListener('click', () => {
            aiPanel.classList.remove('active');
        });

        // Quick action handlers
        aiPanel.querySelectorAll('.ai-action-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                alert('AI Feature: ' + btn.textContent + '\n\nTo enable full AI features, complete the backend setup.\nSee: BUILD_AND_DEPLOY_INSTRUCTIONS.md');
            });
        });

        // Check visibility on initial load
        updateAIButtonVisibility();
        
        console.log('✅ AI Assistant button injected (JavaScript IDE only)!');
    }

    // Monitor language changes
    function monitorLanguageChanges() {
        // Check periodically for language changes
        setInterval(function() {
            if (aiButton) {
                updateAIButtonVisibility();
            }
        }, 2000);
        
        // Listen for language selector changes
        const langSelect = document.querySelector('select[id*="language"], select[class*="language"], select.language-selector');
        if (langSelect) {
            langSelect.addEventListener('change', function() {
                setTimeout(updateAIButtonVisibility, 300);
            });
        }
        
        // Monitor DOM changes for language updates
        const observer = new MutationObserver(function() {
            updateAIButtonVisibility();
        });
        
        observer.observe(document.body || document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['value', 'selected']
        });
    }

    // Try to inject immediately
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            injectAIButton();
            setTimeout(monitorLanguageChanges, 1000);
        });
    } else {
        injectAIButton();
        setTimeout(monitorLanguageChanges, 1000);
    }

    // Also try after a delay (for iframe/dynamic content)
    setTimeout(injectAIButton, 2000);
    setTimeout(injectAIButton, 5000);
})();





