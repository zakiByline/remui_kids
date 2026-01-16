<?php
/**
 * Wick Editor page for remui_kids theme
 * Standalone full-screen emulator page
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

global $USER, $CFG, $CURRENT_LANG;

// Get user info
$username = fullname($USER);
$dashboardurl = new moodle_url('/my/');
?>

<!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wick Editor</title>
    
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
        .emulator-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 15px rgba(245, 87, 108, 0.2);
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
        .emulator-container {
            margin-top: 80px;
            height: calc(100vh - 80px);
            width: 100%;
            position: relative;
            background: #ffffff;
        }
        
        .emulator-iframe-wrapper {
            width: 100%;
            height: 100%;
            position: relative;
        }
        
        .emulator-iframe {
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
            border-top: 5px solid #f5576c;
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .emulator-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .emulator-container {
                margin-top: 120px;
                height: calc(100vh - 120px);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="emulator-header">
        <div class="header-left">
            <div class="header-icon">
                <i class="fa fa-clone"></i>
            </div>
            <div>
                <h1 class="header-title">Wick Editor</h1>
                <p class="header-subtitle">Welcome, <?php echo htmlspecialchars($username); ?>! Create animations and interactive projects.</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?php echo $dashboardurl->out(); ?>" class="back-btn">
                <i class="fa fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="emulator-container">
        <div class="emulator-iframe-wrapper">
            <!-- Loading State -->
            <div class="loading-container" id="loading-container">
                <div class="loading-spinner"></div>
                <div class="loading-text">Loading Wick Editor...</div>
            </div>
            
            <!-- Emulator Iframe -->
            <iframe 
                id="emulator-iframe"
                class="emulator-iframe" 
                src="https://www.wickeditor.com/editor/"
                allowtransparency="true"
                allow="camera; microphone; fullscreen"
                allowfullscreen
                style="display: none;">
            </iframe>
        </div>
    </div>

    <script>
        (function() {
            'use strict';
            
            const emulatorIframe = document.getElementById('emulator-iframe');
            const loadingContainer = document.getElementById('loading-container');
            let iframeLoaded = false;
            
            // Wait for iframe to load
            if (emulatorIframe) {
                emulatorIframe.addEventListener('load', function() {
                    // Hide loading, show iframe
                    if (loadingContainer) {
                        loadingContainer.style.display = 'none';
                    }
                    emulatorIframe.style.display = 'block';
                    iframeLoaded = true;
                });
                
                // If iframe fails to load after 10 seconds, hide loading
                setTimeout(function() {
                    if (!iframeLoaded) {
                        if (loadingContainer) {
                            loadingContainer.style.display = 'none';
                        }
                        emulatorIframe.style.display = 'block';
                    }
                }, 10000);
            }
        })();
    </script>
</body>
</html>
