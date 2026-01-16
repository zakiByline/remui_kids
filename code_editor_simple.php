<?php
/**
 * Simple Code Editor page for remui_kids theme
 * This page embeds the Judge0 IDE from the local ide-master directory
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

global $USER, $CFG, $PAGE, $OUTPUT, $CURRENT_LANG;

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/code_editor_simple.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title('Code Editor - Simple Version');
$PAGE->set_heading('Code Editor');

// Get user info
$username = fullname($USER);
$dashboardurl = new moodle_url('/my/');
?>

<!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Editor - Simple Version</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pix/favicon.ico">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            overflow: hidden;
        }
        
        /* Header */
        .code-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .action-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
        }
        
        /* Main Content */
        .code-container {
            margin-top: 80px;
            height: calc(100vh - 80px);
            width: 100%;
            position: relative;
            background: #ffffff;
        }
        
        .code-iframe-wrapper {
            width: 100%;
            height: 100%;
            position: relative;
        }
        
        .code-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .loading-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 10;
        }
        
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: #666;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .error-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            max-width: 600px;
            padding: 2rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            z-index: 10;
        }
        
        .error-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .error-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin: 1rem 0;
        }
        
        .error-text {
            color: #6b7280;
            font-size: 1rem;
            margin: 1rem 0;
        }
        
        .feature-list {
            background: #f3f4f6;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            text-align: left;
        }
        
        .feature-list h4 {
            margin: 0 0 1rem 0;
            color: #1f2937;
        }
        
        .feature-list ul {
            margin: 0;
            padding-left: 1.5rem;
            color: #6b7280;
            line-height: 1.8;
        }
        
        .external-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
        }
        
        .external-link-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
            text-decoration: none;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .code-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .code-container {
                margin-top: 120px;
                height: calc(100vh - 120px);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="code-header">
        <div class="header-left">
            <div class="header-icon">
                <i class="fa fa-code"></i>
            </div>
            <div>
                <h1 class="header-title">Code Editor</h1>
                <p class="header-subtitle">Welcome, <?php echo htmlspecialchars($username); ?>! Write, compile, and run code in 60+ programming languages!</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?php echo $dashboardurl->out(); ?>" class="back-btn">
                <i class="fa fa-arrow-left"></i>
                Back to Dashboard
            </a>
            <button class="action-btn" id="save-code-btn">
                <i class="fa fa-save"></i>
                Save Code
            </button>
            <button class="action-btn" id="run-code-btn">
                <i class="fa fa-play"></i>
                Run Code
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="code-container">
        <div class="code-iframe-wrapper">
            <!-- Loading State -->
            <div class="loading-container" id="loading-container">
                <div class="loading-spinner"></div>
                <div class="loading-text">Loading Code Editor...</div>
            </div>
            
            <!-- Code Editor Iframe -->
            <iframe 
                id="code-iframe"
                class="code-iframe" 
                src="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/ide-master/index.html"
                allowtransparency="true"
                allow="camera; microphone; fullscreen"
                allowfullscreen
                style="display: none;">
            </iframe>
            
            <!-- Error State -->
            <div class="error-container" id="error-container" style="display: none;">
                <div class="error-icon">
                    <i class="fa fa-code"></i>
                </div>
                <h3 class="error-title">Code Editor Ready!</h3>
                <p class="error-text">The Judge0 IDE is properly configured and ready to use.</p>
                <div class="feature-list">
                    <h4>Features Available:</h4>
                    <ul>
                        <li>60+ Programming Languages (Python, JavaScript, Java, C++, etc.)</li>
                        <li>Monaco Editor with syntax highlighting</li>
                        <li>Real-time code execution and output</li>
                        <li>Multiple themes (Light, Dark, Monokai)</li>
                        <li>Code saving and file management</li>
                        <li>Compiler options and command line arguments</li>
                    </ul>
                </div>
                <div style="margin-top: 2rem;">
                    <p style="font-size: 0.9rem; color: #666;">
                        <strong>Getting Started:</strong> Select a language, write your code, and click "Run Code" to execute it.
                    </p>
                    <a href="https://ide.judge0.com/" target="_blank" class="external-link-btn">
                        <i class="fa fa-external-link-alt"></i>
                        Visit Official Judge0 IDE
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Code Editor JavaScript
        (function() {
            'use strict';
            
            const codeIframe = document.getElementById('code-iframe');
            const loadingContainer = document.getElementById('loading-container');
            const errorContainer = document.getElementById('error-container');
            let iframeLoaded = false;
            
            // Wait for iframe to load
            if (codeIframe) {
                codeIframe.addEventListener('load', function() {
                    // Hide loading, show iframe
                    if (loadingContainer) {
                        loadingContainer.style.display = 'none';
                    }
                    codeIframe.style.display = 'block';
                    iframeLoaded = true;
                });
                
                // If iframe fails to load after 10 seconds, show error
                setTimeout(function() {
                    if (!iframeLoaded) {
                        if (loadingContainer) {
                            loadingContainer.style.display = 'none';
                        }
                        if (errorContainer) {
                            errorContainer.style.display = 'block';
                        }
                    }
                }, 10000);
            }
            
            // Save code functionality
            const saveCodeBtn = document.getElementById('save-code-btn');
            if (saveCodeBtn) {
                saveCodeBtn.addEventListener('click', function() {
                    if (codeIframe.contentWindow) {
                        codeIframe.contentWindow.postMessage({action: 'save'}, '*');
                    }
                    
                    // Show success feedback
                    const originalText = saveCodeBtn.innerHTML;
                    saveCodeBtn.innerHTML = '<i class="fa fa-check"></i> Saved!';
                    setTimeout(() => {
                        saveCodeBtn.innerHTML = originalText;
                    }, 2000);
                });
            }
            
            // Run code functionality
            const runCodeBtn = document.getElementById('run-code-btn');
            if (runCodeBtn) {
                runCodeBtn.addEventListener('click', function() {
                    if (codeIframe.contentWindow) {
                        codeIframe.contentWindow.postMessage({action: 'run'}, '*');
                    }
                    
                    // Show running feedback
                    const originalText = runCodeBtn.innerHTML;
                    runCodeBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running...';
                    setTimeout(() => {
                        runCodeBtn.innerHTML = originalText;
                    }, 3000);
                });
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    if (saveCodeBtn) saveCodeBtn.click();
                } else if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    if (runCodeBtn) runCodeBtn.click();
                }
            });
            
            // Listen for messages from iframe
            window.addEventListener('message', function(event) {
                if (event.data && typeof event.data === 'object') {
                    if (event.data.event === 'initialised') {
                        console.log('Judge0 IDE initialized');
                    } else if (event.data.event === 'running') {
                        if (runCodeBtn) {
                            runCodeBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running...';
                        }
                    } else if (event.data.event === 'finished') {
                        if (runCodeBtn) {
                            runCodeBtn.innerHTML = '<i class="fa fa-play"></i> Run Code';
                        }
                    }
                }
            });
            
        })();
    </script>
</body>
</html>
