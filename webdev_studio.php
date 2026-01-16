<?php
/**
 * WebDev Studio page for remui_kids theme
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

$defaulthtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>My Web Project</title>
</head>
<body>
    <main class="hero">
        <h1>Welcome to WebDev Studio</h1>
        <p>Edit the HTML, CSS, and JavaScript panels to see your project update live.</p>
        <button class="cta-btn">Let&apos;s build!</button>
    </main>
</body>
</html>
HTML;

$defaultcss = <<<CSS
body {
    font-family: 'Inter', sans-serif;
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background: linear-gradient(135deg, #eef2ff 0%, #e0f2ff 100%);
    display: flex;
    align-items: center;
    justify-content: center;
}

.hero {
    text-align: center;
    background: #ffffff;
    padding: 3rem;
    border-radius: 24px;
    box-shadow: 0 25px 65px rgba(15, 23, 42, 0.15);
}

.hero h1 {
    margin: 0 0 1rem 0;
    color: #0f172a;
    font-size: 2.4rem;
}

.hero p {
    margin-bottom: 1.5rem;
    color: #475569;
}

.cta-btn {
    border: none;
    background: linear-gradient(90deg, #6366f1 0%, #22d3ee 100%);
    color: #fff;
    padding: 0.85rem 1.8rem;
    border-radius: 999px;
    font-size: 1rem;
    cursor: pointer;
}
CSS;

$defaultjs = <<<JS
document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('.cta-btn');
    if (button) {
        button.addEventListener('click', () => {
            document.body.style.background =
                'linear-gradient(135deg, #fed7aa 0%, #fde68a 100%)';
            button.textContent = 'You just changed the vibe!';
        });
    }
});
JS;
?>

<!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebDev Studio</title>
    
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
            background: linear-gradient(135deg, #ff9966 0%, #ff5e62 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 15px rgba(255, 94, 98, 0.2);
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
        .emulator-container {
            margin-top: 80px;
            height: calc(100vh - 80px);
            width: 100%;
            position: relative;
            background: #ffffff;
            overflow-y: auto;
            padding: 1.5rem;
        }
        
        .webdev-workspace {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .editor-panel {
            border-radius: 18px;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            min-height: 340px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
        }
        
        .editor-panel header {
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.12);
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        
        .editor-textarea {
            flex: 1;
            background: transparent;
            color: #e2e8f0;
            border: none;
            padding: 1rem;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.9rem;
            resize: none;
            outline: none;
        }
        
        .preview-panel {
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }
        
        .preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }
        
        .preview-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #0f172a;
        }
        
        .preview-header p {
            margin: 0;
            font-size: 0.9rem;
            color: #64748b;
        }
        
        #webdev-preview {
            width: 100%;
            height: 520px;
            border: none;
            display: block;
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
            
            #webdev-preview {
                height: 420px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="emulator-header">
        <div class="header-left">
            <div class="header-icon">
                <i class="fa fa-html5"></i>
            </div>
            <div>
                <h1 class="header-title">WebDev Studio</h1>
                <p class="header-subtitle">Welcome, <?php echo htmlspecialchars($username); ?>! Prototype HTML, CSS, and JavaScript in real-time.</p>
            </div>
        </div>
        <div class="header-actions">
            <button type="button" class="action-btn" onclick="resetWebDevProject()">
                <i class="fa fa-rotate-left"></i>
                Reset
            </button>
            <button type="button" class="action-btn" onclick="downloadProject()">
                <i class="fa fa-download"></i>
                Download
            </button>
            <a href="<?php echo $dashboardurl->out(); ?>" class="back-btn">
                <i class="fa fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="emulator-container">
        <div class="webdev-workspace">
            <section class="editor-panel">
                <header>
                    <span>HTML</span>
                </header>
                <textarea id="webdev-html" class="editor-textarea" spellcheck="false"><?php echo htmlspecialchars($defaulthtml); ?></textarea>
            </section>
            <section class="editor-panel">
                <header>
                    <span>CSS</span>
                </header>
                <textarea id="webdev-css" class="editor-textarea" spellcheck="false"><?php echo htmlspecialchars($defaultcss); ?></textarea>
            </section>
            <section class="editor-panel">
                <header>
                    <span>JavaScript</span>
                </header>
                <textarea id="webdev-js" class="editor-textarea" spellcheck="false"><?php echo htmlspecialchars($defaultjs); ?></textarea>
            </section>
        </div>

        <div class="preview-panel">
            <div class="preview-header">
                <div>
                    <h2>Live Preview</h2>
                    <p>Updates in real-time as you type.</p>
                </div>
                <button type="button" class="action-btn" onclick="openPreviewInNewTab()" style="background: rgba(255, 255, 255, 0.15);">
                    <i class="fa fa-up-right-from-square"></i>
                    Open in new tab
                </button>
            </div>
            <iframe id="webdev-preview" title="WebDev Preview"></iframe>
        </div>
    </div>

    <script>
        const htmlInput = document.getElementById('webdev-html');
        const cssInput = document.getElementById('webdev-css');
        const jsInput = document.getElementById('webdev-js');
        const previewFrame = document.getElementById('webdev-preview');
        const defaultProject = {
            html: <?php echo json_encode($defaulthtml); ?>,
            css: <?php echo json_encode($defaultcss); ?>,
            js: <?php echo json_encode($defaultjs); ?>,
        };

        function updateWebDevPreview() {
            const doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
            const html = htmlInput.value || '';
            const css = `<style>${cssInput.value || ''}</style>`;
            const js = `<script>${jsInput.value || ''}<\/script>`;
            doc.open();
            doc.write(`${html}${css}${js}`);
            doc.close();
        }

        function resetWebDevProject() {
            htmlInput.value = defaultProject.html;
            cssInput.value = defaultProject.css;
            jsInput.value = defaultProject.js;
            updateWebDevPreview();
        }

        function downloadProject() {
            const zipContent =
`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>WebDev Studio Project</title>
<style>
${cssInput.value}
</style>
</head>
<body>
${htmlInput.value}
<script>
${jsInput.value}
<\/script>
</body>
</html>`;

            const blob = new Blob([zipContent], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'webdev-project.html';
            a.click();
            URL.revokeObjectURL(url);
        }

        function openPreviewInNewTab() {
            const doc = `
${htmlInput.value}
<style>${cssInput.value}</style>
<script>${jsInput.value}<\/script>`;

            const newWindow = window.open();
            if (newWindow) {
                newWindow.document.open();
                newWindow.document.write(doc);
                newWindow.document.close();
            }
        }

        ['keyup', 'change'].forEach(eventName => {
            htmlInput.addEventListener(eventName, updateWebDevPreview);
            cssInput.addEventListener(eventName, updateWebDevPreview);
            jsInput.addEventListener(eventName, updateWebDevPreview);
        });

        updateWebDevPreview();
    </script>
</body>
</html>
