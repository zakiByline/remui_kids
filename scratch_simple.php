<?php
/**
 * Simple Scratch Editor Page
 * A minimal Scratch editor without complex Moodle integration
 *
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
// Moodle configuration
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

// Debug: Log that the Scratch Editor page is being accessed
error_log("Scratch Editor accessed by user: " . $USER->id . " (" . fullname($USER) . ")");
 
global $USER, $CFG, $CURRENT_LANG;
 
// Get user info
$username = fullname($USER);
$dashboardurl = new moodle_url('/my/');
$wwwroot = $CFG->wwwroot;

// Build absolute URLs for all assets
$favicon_url = $wwwroot . '/theme/remui_kids/pix/favicon.ico';
$dashboard_absolute_url = $dashboardurl->out(false);

// Check if local build exists, otherwise use external fallback
$local_scratch_build = __DIR__ . '/scratch-gui-develop/build/index.html';

if (file_exists($local_scratch_build)) {
    // Use LOCAL Scratch GUI build from scratch-gui-develop folder
    $scratch_editor_url = $wwwroot . '/theme/remui_kids/scratch-gui-develop/build/index.html';
} else {
    // Fallback to Sheeptester's Scratch GUI while building local version
    $scratch_editor_url = 'https://sheeptester.github.io/scratch-gui/';
}

// Don't use PAGE->set_url or other PAGE methods for standalone HTML pages
// Just require login and then output HTML
?><!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scratch Editor - Simple Version</title>
   
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $favicon_url; ?>">
   
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
        .scratch-header {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.2);
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
            gap: 1.5rem;
        }
       
        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
       
        .logo-icon {
            font-size: 1.8rem;
        }
       
        .logo-text {
            font-size: 1.3rem;
            font-weight: 700;
        }
       
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
       
        .btn-header {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }
       
        .btn-header:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
       
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
        }
       
        /* Main container */
        .scratch-container {
            margin-top: 70px;
            height: calc(100vh - 70px);
            display: flex;
            flex-direction: column;
        }
       
        /* Scratch iframe wrapper */
        .scratch-iframe-wrapper {
            flex: 1;
            width: 100%;
            position: relative;
        }
       
        .scratch-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
       
        /* Loading spinner */
        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
       
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e5e7eb;
            border-top-color: #8b5cf6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
       
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
       
        .loading-text {
            color: #6b7280;
            font-size: 0.9rem;
        }
       
        /* Responsive design */
        @media (max-width: 768px) {
            .scratch-header {
                padding: 0.8rem 1rem;
            }
           
            .logo-text {
                font-size: 1rem;
            }
           
            .btn-header {
                padding: 0.5rem 0.8rem;
                font-size: 0.8rem;
            }
           
            .user-info {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="scratch-header">
        <div class="header-left">
            <div class="logo-container">
                <i class="fas fa-puzzle-piece logo-icon"></i>
                <span class="logo-text">Scratch Editor</span>
                <span style="font-size: 0.7rem; opacity: 0.8; margin-left: 0.5rem;">(Official Scratch 3.0)</span>
            </div>
        </div>
       
        <div class="header-actions">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($username); ?></span>
            </div>
           
            <button class="btn-header" id="save-project-btn">
                <i class="fas fa-save"></i>
                <span>Save</span>
            </button>
           
            <button class="btn-header" id="share-project-btn">
                <i class="fas fa-share-alt"></i>
                <span>Share</span>
            </button>
           
            <a href="<?php echo $dashboard_absolute_url; ?>" class="btn-header">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>
   
    <!-- Main Container -->
    <div class="scratch-container">
        <!-- Scratch Editor -->
        <div class="scratch-iframe-wrapper" id="scratch-editor">
            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loading-spinner">
                <div class="spinner"></div>
                <div class="loading-text">Loading Scratch Editor...</div>
            </div>
           
            <!-- Official Scratch 3.0 Editor (Local Build) -->
            <iframe
                id="scratch-iframe"
                class="scratch-iframe"
                src="<?php echo htmlspecialchars($scratch_editor_url); ?>"
                allow="microphone; camera; fullscreen; clipboard-read; clipboard-write"
                sandbox="allow-same-origin allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads"
                style="display: none;"
            ></iframe>
        </div>
    </div>
   
    <script>
        (function() {
            'use strict';
           
            // Hide loading spinner when iframe loads
            const iframe = document.getElementById('scratch-iframe');
            const spinner = document.getElementById('loading-spinner');
           
            iframe.addEventListener('load', function() {
                spinner.style.display = 'none';
                iframe.style.display = 'block';
            });
           
            // Show iframe even if load event doesn't fire (fallback)
            setTimeout(function() {
                spinner.style.display = 'none';
                iframe.style.display = 'block';
            }, 5000);
           
            // Save project functionality
            const saveProjectBtn = document.getElementById('save-project-btn');
            if (saveProjectBtn) {
                saveProjectBtn.addEventListener('click', function() {
                    alert('To save your Scratch project:\n\n1. Click "File" > "Save to your computer" inside the editor\n2. Your project will be downloaded as a .sb3 file\n3. You can load it later by clicking "File" > "Load from your computer"\n\nTip: Your projects are compatible with Scratch 3.0!');
                });
            }
           
            // Share project functionality
            const shareProjectBtn = document.getElementById('share-project-btn');
            if (shareProjectBtn) {
                shareProjectBtn.addEventListener('click', function() {
                    alert('To share your Scratch project:\n\n1. Click "File" menu in the editor\n2. Select "Save to your computer" to download your project\n3. You can upload it to scratch.mit.edu to share with the world\n4. Or share the .sb3 file directly with classmates!');
                });
            }
           
        })();
    </script>
</body>
</html>
