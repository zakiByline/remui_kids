<?php
/**
 * Standalone Scratch Editor Page
 * A completely custom page for Scratch editor without Moodle page structure
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Define Moodle configuration
define('MOODLE_INTERNAL', true);
define('ABORT_AFTER_CONFIG', false);

// Include Moodle configuration
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language

// Include cohort library if available
if (file_exists($CFG->dirroot . '/cohort/lib.php')) {
    require_once($CFG->dirroot . '/cohort/lib.php');
}

// Require login
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $CURRENT_LANG;

// Set up page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/scratch_editor_standalone.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title('Scratch Editor - Custom Page');
$PAGE->set_heading('Scratch Editor');

// Determine user's cohort and dashboard type
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'default';

// Try to get user cohorts with error handling
try {
    if (function_exists('cohort_get_user_cohorts')) {
        $usercohorts = cohort_get_user_cohorts($USER->id);
        if (!empty($usercohorts)) {
            $firstcohort = reset($usercohorts);
            $usercohortid = $firstcohort->id;
            $usercohortname = $firstcohort->name;
            
            // Determine dashboard type based on cohort name
            if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
                $dashboardtype = 'elementary';
            } elseif (preg_match('/grade\s*[4-6]/i', $usercohortname)) {
                $dashboardtype = 'middle';
            } elseif (preg_match('/grade\s*[7-9]/i', $usercohortname)) {
                $dashboardtype = 'high';
            }
        }
    } else {
        // Fallback: determine dashboard type based on user profile or default to middle
        $dashboardtype = 'middle'; // Default to middle school for Scratch editor
    }
} catch (Exception $e) {
    // If cohort function fails, default to middle school
    $dashboardtype = 'middle';
}

// Start output buffering
ob_start();
?>

<!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scratch Editor - Custom Page</title>
    
    <!-- Preserve favicon -->
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
        .scratch-container {
            margin-top: 80px;
            height: calc(100vh - 80px);
            width: 100%;
            position: relative;
            background: #ffffff;
        }
        
        .scratch-iframe-wrapper {
            width: 100%;
            height: 100%;
            position: relative;
        }
        
        .scratch-iframe {
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
            border-top: 5px solid #8b5cf6;
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
            color: #f59e0b;
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
        
        .external-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
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
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
            color: white;
            text-decoration: none;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .scratch-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .scratch-container {
                margin-top: 120px;
                height: calc(100vh - 120px);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="scratch-header">
        <div class="header-left">
            <div class="header-icon">
                <i class="fa fa-play"></i>
            </div>
            <div>
                <h1 class="header-title">Scratch Editor</h1>
                <p class="header-subtitle">Create amazing interactive stories, games, and animations!</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="back-btn">
                <i class="fa fa-arrow-left"></i>
                Back to Dashboard
            </a>
            <button class="action-btn" id="save-project-btn">
                <i class="fa fa-save"></i>
                Save Project
            </button>
            <button class="action-btn" id="share-project-btn">
                <i class="fa fa-share-alt"></i>
                Share
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="scratch-container">
        <div class="scratch-iframe-wrapper">
            <!-- Loading State -->
            <div class="loading-container" id="loading-container">
                <div class="loading-spinner"></div>
                <div class="loading-text">Loading Scratch Editor...</div>
            </div>
            
            <!-- Scratch Iframe -->
            <iframe 
                id="scratch-iframe"
                class="scratch-iframe" 
                src="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/scratch-gui-develop/build/index.html"
                allowtransparency="true"
                allow="camera; microphone; fullscreen"
                allowfullscreen
                style="display: none;">
            </iframe>
            
            <!-- Error State -->
            <div class="error-container" id="error-container" style="display: none;">
                <div class="error-icon">
                    <i class="fa fa-exclamation-circle"></i>
                </div>
                <h3 class="error-title">Scratch Editor Setup Required</h3>
                <p class="error-text">The local Scratch editor needs to be properly configured before it can be used.</p>
                <div style="background: #f3f4f6; padding: 1.5rem; border-radius: 8px; margin: 1.5rem 0; text-align: left;">
                    <h4 style="margin: 0 0 1rem 0; color: #1f2937;">Build Instructions:</h4>
                    <ol style="margin: 0; padding-left: 1.5rem; color: #6b7280; line-height: 1.8;">
                        <li>Open terminal in: <code style="background: #e5e7eb; padding: 0.2rem 0.5rem; border-radius: 4px;">theme/remui_kids/scratch-gui-develop</code></li>
                        <li>Run: <code style="background: #e5e7eb; padding: 0.2rem 0.5rem; border-radius: 4px;">npm install</code></li>
                        <li>Run: <code style="background: #e5e7eb; padding: 0.2rem 0.5rem; border-radius: 4px;">npm run build</code></li>
                        <li>Refresh this page after build completes</li>
                    </ol>
                </div>
                <div style="margin-top: 2rem;">
                    <p style="font-size: 0.9rem; color: #666;">
                        <strong>Alternative:</strong> Use the official Scratch editor while building
                    </p>
                    <a href="https://scratch.mit.edu/projects/editor/" target="_blank" class="external-link-btn">
                        <i class="fa fa-external-link-alt"></i>
                        Open Scratch.mit.edu
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scratch Editor JavaScript
        (function() {
            'use strict';
            
            const scratchIframe = document.getElementById('scratch-iframe');
            const loadingContainer = document.getElementById('loading-container');
            const errorContainer = document.getElementById('error-container');
            let iframeLoaded = false;
            
            // Wait for iframe to load
            if (scratchIframe) {
                scratchIframe.addEventListener('load', function() {
                    // Hide loading, show iframe
                    if (loadingContainer) {
                        loadingContainer.style.display = 'none';
                    }
                    scratchIframe.style.display = 'block';
                    iframeLoaded = true;
                });
                
                // If iframe fails to load after 8 seconds, show error
                setTimeout(function() {
                    if (!iframeLoaded) {
                        if (loadingContainer) {
                            loadingContainer.style.display = 'none';
                        }
                        if (errorContainer) {
                            errorContainer.style.display = 'block';
                        }
                    }
                }, 8000);
            }
            
            // Save project functionality
            const saveProjectBtn = document.getElementById('save-project-btn');
            if (saveProjectBtn) {
                saveProjectBtn.addEventListener('click', function() {
                    alert('Your project is automatically saved in your browser. To save permanently, click "File" > "Save to computer" in the editor.');
                });
            }
            
            // Share project functionality
            const shareProjectBtn = document.getElementById('share-project-btn');
            if (shareProjectBtn) {
                shareProjectBtn.addEventListener('click', function() {
                    alert('To share your project:\n1. Click "File" > "Save to computer" to download\n2. Upload to scratch.mit.edu to share with others');
                });
            }
            
        })();
    </script>
</body>
</html>

<?php
// End output buffering and output the content
$content = ob_get_clean();
echo $content;
?>
