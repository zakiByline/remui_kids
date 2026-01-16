<?php
/**
 * Emulators & Plugins Page for Teacher Dashboard
 * 
 * This page showcases available emulators and educational plugins
 * available in the system.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access emulators page');
}

global $USER;
$catalog = theme_remui_kids_emulator_catalog();
$emulatorcards = [];
foreach ($catalog as $slug => $definition) {
    $accessible = theme_remui_kids_user_has_emulator_access($USER->id, $slug, 'teacher');
    $emulatorcards[] = [
        'slug' => $slug,
        'name' => $definition['name'],
        'summary' => $definition['summary'],
        'icon' => $definition['icon'],
        'launchurl' => $definition['launchurl'],
        'accessible' => $accessible,
        'activityonly' => empty($definition['launchurl']),
    ];
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/emulators.php');
$PAGE->set_title('Emulators & Educational Tools');
$PAGE->set_heading('Available Emulators & Tools');

echo $OUTPUT->header();
?>

<style>
/* Hide Moodle's default main content area */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Teacher Dashboard Styles */
.teacher-css-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

/* Emulators Page Specific Styles */
.emulators-header {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.emulators-title {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 10px 0;
}

.emulators-subtitle {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0;
}

.emulators-grid {
    display: flex;
    gap: 30px;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

.emulator-block {
    width: 150px;
    height: 150px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
    position: relative;
}

.emulator-block:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    border-color: #667eea;
    text-decoration: none;
}

.emulator-block i {
    font-size: 50px;
    color: #667eea;
    transition: all 0.3s ease;
}

.emulator-block:hover i {
    transform: scale(1.1);
    color: #764ba2;
}

.emulator-label {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
    text-align: center;
}

.emulator-block:hover .emulator-label {
    color: #667eea;
}

.emulator-block.available {
    cursor: default;
}

.emulator-block.available:hover {
    transform: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: transparent;
}

.emulator-block.available:hover i {
    transform: none;
}

.emulator-block.available:hover .emulator-label {
    color: #2c3e50;
}

.emulator-block.locked {
    cursor: not-allowed;
    opacity: 0.55;
    border-style: dashed;
    border-color: #f87171;
}

.emulator-block .emulator-status {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(79, 70, 229, 0.1);
    color: #4f46e5;
}

.emulator-block.locked .emulator-status {
    background: rgba(248, 113, 113, 0.18);
    color: #b91c1c;
}

.emulator-block.available .emulator-status {
    background: rgba(16, 185, 129, 0.16);
    color: #047857;
}

.emulator-block.active {
    cursor: pointer;
}

@keyframes blockShake {
    0% { transform: translateX(0); }
    25% { transform: translateX(-4px); }
    50% { transform: translateX(4px); }
    75% { transform: translateX(-2px); }
    100% { transform: translateX(0); }
}

.emulator-block.shake {
    animation: blockShake 0.35s ease;
}

.emulator-block.selected {
    border-color: #667eea;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
}

/* Emulator Viewer */
.emulator-viewer {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    margin-bottom: 30px;
    overflow: hidden;
}

.viewer-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: white;
}

.viewer-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-close-viewer {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-close-viewer:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
}

.viewer-iframe-container {
    height: calc(100vh - 200px);
    min-height: 800px;
    position: relative;
    background: #f8f9fa;
}

.emulator-iframe {
    width: 100%;
    height: 100%;
    border: none;
}

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
    border-top-color: #667eea;
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

.info-section {
    background: white;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-top: 30px;
}

.info-section h3 {
    color: #2c3e50;
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-section h3 i {
    color: #667eea;
}

.info-section p {
    color: #5d6d7e;
    font-size: 15px;
    line-height: 1.7;
    margin: 0 0 15px 0;
}

.info-section ul {
    color: #5d6d7e;
    font-size: 15px;
    line-height: 1.7;
    margin: 0 0 15px 0;
    padding-left: 20px;
}

.info-section ul li {
    margin-bottom: 10px;
}

@media (max-width: 768px) {
    .emulators-grid {
        justify-content: center;
    }
    
    .emulator-block {
        width: 130px;
        height: 130px;
    }
    
    .emulator-block i {
        font-size: 40px;
    }
    
    .emulator-label {
        font-size: 12px;
    }
    
    .viewer-iframe-container {
        height: calc(100vh - 250px);
        min-height: 600px;
    }
    
    .viewer-header {
        padding: 15px 20px;
    }
    
    .viewer-title {
        font-size: 16px;
    }
    
    .btn-close-viewer {
        padding: 8px 16px;
        font-size: 12px;
    }
}
</style>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>

        <!-- Main Content -->
        <div class="teacher-main-content">


            <!-- Emulators Grid -->
            <div class="emulators-grid" id="emulatorGrid">
                <?php foreach ($emulatorcards as $card):
                    $classes = ['emulator-block'];
                    if ($card['accessible']) {
                        $classes[] = $card['activityonly'] ? 'available' : 'active';
                    } else {
                        $classes[] = 'locked';
                    }
                    $statusstring = !$card['accessible']
                        ? get_string('emulator_tag_locked', 'theme_remui_kids')
                        : ($card['activityonly']
                            ? get_string('emulator_tag_activity', 'theme_remui_kids')
                            : get_string('emulator_tag_launch', 'theme_remui_kids'));
                ?>
                    <div id="emulator-<?php echo s($card['slug']); ?>" class="<?php echo implode(' ', $classes); ?>"
                         data-emulator="<?php echo s($card['slug']); ?>"
                         data-access="<?php echo $card['accessible'] ? 1 : 0; ?>"
                         data-activity="<?php echo $card['activityonly'] ? 1 : 0; ?>"
                         data-launch="<?php echo ($card['accessible'] && !$card['activityonly'] && !empty($card['launchurl'])) ? s($card['launchurl']) : ''; ?>"
                         data-name="<?php echo s($card['name']); ?>">
                        <i class="fa <?php echo s($card['icon']); ?>"></i>
                        <span class="emulator-label"><?php echo s($card['name']); ?></span>
                        <span class="emulator-status"><?php echo $statusstring; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Emulator Viewer (Hidden by default) -->
            <div id="emulatorViewer" class="emulator-viewer" style="display: none;">
                <div class="viewer-header">
                    <h3 id="emulatorTitle" class="viewer-title">
                        <i class="fa fa-laptop-code"></i>
                        <span></span>
                    </h3>
                    <button class="btn-close-viewer" onclick="closeEmulator()">
                        <i class="fa fa-times"></i> Close
                    </button>
                </div>
                <div class="viewer-iframe-container">
                    <div class="loading-spinner" id="emulatorLoading">
                        <div class="spinner"></div>
                        <div class="loading-text">Loading emulator...</div>
                    </div>
                    <iframe id="emulatorFrame" class="emulator-iframe" style="display: none;"></iframe>
                </div>
            </div>

            <!-- How to Use Section -->
            <div class="info-section" id="infoSection">
                <h3><i class="fa fa-question-circle"></i> How to Use These Tools</h3>
                <p>These emulators are seamlessly integrated into your teaching workflow:</p>
                <ul>
                    <li><strong>Assign Activities:</strong> Create assignments using these emulators and track student submissions</li>
                    <li><strong>Live Demonstrations:</strong> Use during class to demonstrate coding concepts in real-time</li>
                    <li><strong>Student Practice:</strong> Students can access these tools from their course pages to practice</li>
                    <li><strong>Progress Tracking:</strong> Monitor student work and provide feedback directly in the platform</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Sidebar functions are now in includes/sidebar.php

// Emulator Loading Functions
function loadEmulator(url, title, trigger = null) {
    const viewer = document.getElementById('emulatorViewer');
    const iframe = document.getElementById('emulatorFrame');
    const loading = document.getElementById('emulatorLoading');
    const titleElement = document.getElementById('emulatorTitle').querySelector('span');
    const infoSection = document.getElementById('infoSection');
    
    // Update title
    titleElement.textContent = title;
    
    // Show viewer, hide info section
    viewer.style.display = 'block';
    infoSection.style.display = 'none';
    
    // Show loading spinner
    loading.style.display = 'flex';
    iframe.style.display = 'none';
    
    // Load iframe
    iframe.src = url;
    
    // Remove previous selection
    document.querySelectorAll('.emulator-block.selected').forEach(block => {
        block.classList.remove('selected');
    });
    
    // Mark current block as selected
    if (trigger) {
        trigger.classList.add('selected');
    }
    
    // Hide loading when iframe loads
    iframe.onload = function() {
        loading.style.display = 'none';
        iframe.style.display = 'block';
    };
    
    // Fallback to show iframe after 3 seconds even if onload doesn't fire
    setTimeout(function() {
        loading.style.display = 'none';
        iframe.style.display = 'block';
    }, 3000);
    
    // Smooth scroll to viewer
    viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeEmulator() {
    const viewer = document.getElementById('emulatorViewer');
    const iframe = document.getElementById('emulatorFrame');
    const infoSection = document.getElementById('infoSection');
    
    // Hide viewer, show info section
    viewer.style.display = 'none';
    infoSection.style.display = 'block';
    
    // Clear iframe source to stop any running content
    iframe.src = '';
    
    // Remove selection from blocks
    document.querySelectorAll('.emulator-block.selected').forEach(block => {
        block.classList.remove('selected');
    });
    
    // Scroll to top of emulators grid
    document.querySelector('.emulators-grid').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.emulator-block').forEach(block => {
        block.addEventListener('click', function() {
            const isAllowed = block.dataset.access === '1';
            const launchUrl = block.dataset.launch;

            if (!isAllowed) {
                block.classList.add('shake');
                setTimeout(() => block.classList.remove('shake'), 400);
                return;
            }

            if (!launchUrl) {
                block.classList.add('selected');
                setTimeout(() => block.classList.remove('selected'), 800);
                return;
            }

            loadEmulator(launchUrl, block.dataset.name, block);
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>

