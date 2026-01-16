<?php
/**
 * Remix IDE Page
 * A minimal Remix IDE integration for Solidity development
 *
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
// Moodle configuration
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

// Debug: Log that the Remix IDE page is being accessed
error_log("Remix IDE accessed by user: " . $USER->id . " (" . fullname($USER) . ")");
 
global $USER, $CFG, $CURRENT_LANG;
 
// Get user info
$username = fullname($USER);
$dashboardurl = new moodle_url('/my/');
$wwwroot = $CFG->wwwroot;

// Build absolute URLs for all assets
$favicon_url = $wwwroot . '/theme/remui_kids/pix/favicon.ico';
$dashboard_absolute_url = $dashboardurl->out(false);

// Check if local build exists, otherwise use official Remix IDE
$local_remix_build = __DIR__ . '/../../mod/mix/remix-ide/build/index.html';

if (file_exists($local_remix_build)) {
    // Use LOCAL Remix IDE build
    $remix_ide_url = $wwwroot . '/mod/mix/remix-ide/build/index.html';
    // $remix_ide_url = 'https://remix.ethereum.org/';
    error_log("Remix IDE: Using local build");
} else {
    // Fallback to official Remix IDE while building local version
    $remix_ide_url = 'https://remix.ethereum.org/';
    error_log("Remix IDE: Using official online version (local build not found)");
}

// Don't use PAGE->set_url or other PAGE methods for standalone HTML pages
// Just require login and then output HTML
?><!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remix IDE - Solidity Development</title>
   
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
        .remix-header {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 15px rgba(17, 153, 142, 0.2);
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
        .remix-container {
            margin-top: 70px;
            height: calc(100vh - 70px);
            display: flex;
            flex-direction: column;
        }
       
        /* Remix iframe wrapper */
        .remix-iframe-wrapper {
            flex: 1;
            width: 100%;
            position: relative;
        }
       
        .remix-iframe {
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
            border-top-color: #11998e;
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
            .remix-header {
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
    <header class="remix-header">
        <div class="header-left">
            <div class="logo-container">
                <i class="fas fa-code logo-icon"></i>
                <span class="logo-text">Remix IDE</span>
                <span style="font-size: 0.7rem; opacity: 0.8; margin-left: 0.5rem;">(Solidity Development)</span>
            </div>
        </div>
       
        <div class="header-actions">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($username); ?></span>
            </div>
           
            <a href="<?php echo $dashboard_absolute_url; ?>" class="btn-header">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>
   
    <!-- Main Container -->
    <div class="remix-container">
        <!-- Remix IDE -->
        <div class="remix-iframe-wrapper" id="remix-editor">
            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loading-spinner">
                <div class="spinner"></div>
                <div class="loading-text">Loading Remix IDE...</div>
            </div>
           
            <!-- Remix IDE iframe -->
            <iframe
                id="remix-iframe"
                class="remix-iframe"
                src="<?php echo htmlspecialchars($remix_ide_url); ?>"
                allow="fullscreen; clipboard-read; clipboard-write"
                sandbox="allow-same-origin allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads"
                style="display: none;"
            ></iframe>
        </div>
    </div>
   
    <script>
        (function() {
            'use strict';
           
            // Hide loading spinner when iframe loads
            const iframe = document.getElementById('remix-iframe');
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
           
        })();
    </script>
</body>
</html>

