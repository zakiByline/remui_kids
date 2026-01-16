<?php
/**
 * AI Assistant Settings - Modern UI
 * Beautiful interface for managing AI Assistant settings
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT, $CFG;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // Get AI Assistant settings
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $api_key = trim($_POST['api_key']);
        $model = $_POST['model'];
        $show_floating = isset($_POST['show_floating']) ? 1 : 0;
        
        // Save to database - match exact column names from mdl_config_plugins
        set_config('enabled', $enabled, 'local_aiassistant');
        set_config('apikey', $api_key, 'local_aiassistant');
        set_config('model', $model, 'local_aiassistant');
        set_config('showfloatingchat', $show_floating, 'local_aiassistant');
        
        // Show success message
        $success_message = 'Settings saved successfully!';
    } catch (Exception $e) {
        error_log('Error saving AI Assistant settings: ' . $e->getMessage());
        $error_message = 'Failed to save settings: ' . $e->getMessage();
    }
}

// Get current settings from database (mdl_config_plugins table)
// Note: Exact column names from database - 'apikey' and 'showfloatingchat'
$current_enabled = get_config('local_aiassistant', 'enabled');
$current_api_key = get_config('local_aiassistant', 'apikey');
$current_model = get_config('local_aiassistant', 'model');
$current_show_floating = get_config('local_aiassistant', 'showfloatingchat');

// If not in config_plugins, check if settings exist in the plugin's classes
if ($current_api_key === false) {
    // Try to load from gemini_api.php class if it exists
    $gemini_class = $CFG->dirroot . '/local/aiassistant/classes/gemini_api.php';
    if (file_exists($gemini_class)) {
        require_once($gemini_class);
        if (class_exists('local_aiassistant\gemini_api')) {
            $reflection = new ReflectionClass('local_aiassistant\gemini_api');
            $constants = $reflection->getConstants();
            if (isset($constants['API_KEY'])) {
                $current_api_key = $constants['API_KEY'];
            } elseif (isset($constants['GEMINI_API_KEY'])) {
                $current_api_key = $constants['GEMINI_API_KEY'];
            }
        }
    }
}

// Set safe defaults if still not found
if ($current_enabled === false) {
    $current_enabled = 1;
}
if ($current_api_key === false || empty($current_api_key)) {
    $current_api_key = '';
}
if ($current_model === false) {
    $current_model = 'gemini-2.5-flash-lite';
}
if ($current_show_floating === false) {
    $current_show_floating = 1;
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/ai_assistant.php');
$PAGE->set_title('AI Assistant Settings');
$PAGE->set_heading('AI Assistant Settings');

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

echo "<div class='admin-main-content ai-assistant-layout'>";
?>

<style>
.ai-assistant-layout {
    background: linear-gradient(135deg, #fdf7ff 0%, #f2fbff 100%);
    min-height: 100vh;
    color: #1f2933;
    overflow-y: auto;
}

.ai-glow {
    position: absolute;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(175, 216, 248, 0.45) 0%, rgba(255,255,255,0) 70%);
    filter: blur(60px);
    z-index: 0;
}

.ai-glow.one {
    top: 5%;
    left: 10%;
}

.ai-glow.two {
    bottom: 0;
    right: 8%;
}

.ai-settings-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 100%;
    margin: 0;
    background: #ffffff;
    border-radius: 0;
    padding: 40px 60px;
    box-shadow: none;
    border: none;
    box-sizing: border-box;
}

.settings-header {
    margin-bottom: 40px;
    text-align: left;
}

.settings-header h1 {
    font-weight: 800;
    font-size: 2.6rem;
    margin: 0;
    color: #1f2933;
}

.settings-header p {
    margin-top: 12px;
    color: #5f6c80;
    font-size: 1.05rem;
}

.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 30px;
}

.settings-card {
    background: #ffffff;
    border: 1px solid #e1eaf3;
    border-radius: 22px;
    padding: 34px;
    box-shadow: 0 26px 70px rgba(15, 23, 42, 0.12);
    position: relative;
    overflow: hidden;
}

.settings-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(99,102,241,0.08), transparent 60%);
    pointer-events: none;
}

.settings-card h2 {
    color: #0f172a;
    font-size: 1.25rem;
    letter-spacing: 0.08em;
    margin-bottom: 18px;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 800;
    position: relative;
    z-index: 1;
}

.card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 26px;
    position: relative;
    z-index: 1;
}

.card-chip {
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.card-chip.live {
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
}

.card-chip.info {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

.accent-badge {
    background: rgba(59,130,246,0.12);
    color: #2563eb;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    letter-spacing: 0.08em;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.form-group {
    margin-bottom: 22px;
    padding: 18px 20px;
    border-radius: 16px;
    border: 1px solid #e2e9f2;
    background: #f8fbff;
    position: relative;
}

.form-group::before {
    content: '';
    position: absolute;
    inset: 12px;
    border-radius: 12px;
    border: 1px dashed rgba(148, 163, 184, 0.4);
    pointer-events: none;
}

.form-group label {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1f2933;
    display: block;
    margin-bottom: 8px;
}

.form-group .help-text {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 8px;
}

.form-control {
    width: 100%;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid #dbe3ec;
    background: #fefefe;
    color: #111827;
    font-size: 1rem;
    transition: border 0.2s, background 0.2s;
}

.form-control:focus {
    border-color: #7c3aed;
    background: #ffffff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
}

.checkbox-wrapper {
    background: #f8fbff;
    border-radius: 14px;
    padding: 18px;
    border: 1px solid #e2e8f0;
}

.checkbox-wrapper label {
    color: #1f2933;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 20px;
    height: 20px;
    border-radius: 4px;
}

.button-row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.btn-save {
    background: linear-gradient(135deg, #6366f1 0%, #38bdf8 100%);
    border: none;
    padding: 14px 32px;
    border-radius: 999px;
    color: #ffffff;
    font-weight: 600;
    letter-spacing: 0.05em;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.btn-save:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 30px rgba(14, 165, 233, 0.35);
}

.btn-secondary {
    border: 1px solid rgba(99, 102, 241, 0.35);
    color: #4c1d95;
    padding: 14px 28px;
    border-radius: 999px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.stat-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.stat-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px solid #edf2f7;
}

.stat-label {
    color: #475569;
    font-weight: 600;
}

.stat-value {
    font-weight: 700;
    color: #0f172a;
}

.stat-value.badge {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.85rem;
}

.badge-success {
    background: rgba(16,185,129,0.15);
    color: #047857;
}

.badge-warning {
    background: rgba(251,191,36,0.2);
    color: #92400e;
}

.badge-info {
    background: rgba(59,130,246,0.15);
    color: #1d4ed8;
}

.stat-note {
    display: block;
    font-size: 0.8rem;
    color: #94a3b8;
    margin-top: 4px;
}

.message {
    border-radius: 12px;
    padding: 14px 20px;
    font-weight: 600;
    margin-bottom: 20px;
}

.message-success {
    background: rgba(16,185,129,0.15);
    color: #047857;
    border: 1px solid rgba(16,185,129,0.3);
}

.message-error {
    background: rgba(248,113,113,0.15);
    color: #b91c1c;
    border: 1px solid rgba(248,113,113,0.3);
}

.insight-strip {
    margin-top: 40px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 18px;
}

.insight-card {
    background: linear-gradient(135deg, #fef6f0 0%, #f1f9ff 100%);
    border: 1px solid rgba(226,232,240,0.8);
    border-radius: 18px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: 0 15px 35px rgba(15,23,42,0.08);
}

.insight-card h3 {
    margin: 0;
    font-size: 0.95rem;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.insight-card p {
    margin: 0;
    color: #475569;
    font-size: 0.88rem;
}

.insight-card a {
    margin-top: auto;
    font-weight: 600;
    color: #2563eb;
    text-decoration: none;
}

.insight-card a:hover {
    text-decoration: underline;
}

@media (max-width: 1024px) {
    .ai-assistant-layout {
        padding: 0;
    }
}

@media (max-width: 720px) {
    .ai-assistant-layout {
        padding: 0;
    }
    .settings-grid {
        grid-template-columns: 1fr;
    }
    .button-row {
        flex-direction: column;
    }
}
</style>

<div class="ai-glow one"></div>
<div class="ai-glow two"></div>

<div class="ai-settings-container">
    <div class="settings-header">
        <div class="accent-badge">
            <i class="fa fa-bolt"></i> Gemini Control Center
        </div>
        <h1>AI Assistant Console</h1>
        <p>Manage access, keys, and behavior for the Gemini-powered assistant.</p>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="message message-success">
            <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="message message-error">
            <i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <div class="settings-grid">
    <form method="POST" action="">
        <div class="settings-card">
                <h2><i class="fa fa-sliders-h"></i> Runtime Controls</h2>
                <div class="card-meta">
                    <span class="card-chip live"><i class="fa fa-signal"></i> Live</span>
                    <span class="card-chip info"><i class="fa fa-cloud"></i> Gemini Cloud</span>
                </div>
            
            <div class="form-group">
                <div class="checkbox-wrapper">
                    <input type="checkbox" id="enabled" name="enabled" value="1" <?php echo $current_enabled ? 'checked' : ''; ?>>
                        <label for="enabled">Enable AI Assistant</label>
                </div>
                    <div class="help-text">Toggle global availability of the assistant.</div>
            </div>

            <div class="form-group">
                <label for="api_key">Gemini API Key</label>
                <input type="text" id="api_key" name="api_key" class="form-control" 
                       value="<?php echo htmlspecialchars($current_api_key); ?>" 
                       placeholder="Enter your Google Gemini API key">
                    <div class="help-text">Ensure the key has access to the selected model.</div>
            </div>

            <div class="form-group">
                    <label for="model">Model Selection</label>
                <select id="model" name="model" class="form-control">
                    <?php
                    $model_options = [
                            'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite · Fast responses',
                            'gemini-2.5-flash' => 'Gemini 2.5 Flash · Balanced output',
                            'gemini-2.5-pro' => 'Gemini 2.5 Pro · Most capable',
                    ];
                    
                    foreach ($model_options as $value => $label) {
                        $selected = ($current_model === $value) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>';
                        echo htmlspecialchars($label);
                        echo '</option>';
                    }
                    ?>
                </select>
                    <div class="help-text">Match models to the latency vs. accuracy needs of your schools.</div>
            </div>

            <div class="form-group">
                <div class="checkbox-wrapper">
                    <input type="checkbox" id="show_floating" name="show_floating" value="1" <?php echo $current_show_floating ? 'checked' : ''; ?>>
                        <label for="show_floating">Display floating chat launcher</label>
                </div>
                    <div class="help-text">Adds a persistent chat bubble across the site.</div>
            </div>

                <div class="button-row">
                <button type="submit" name="save_settings" class="btn-save">
                    <i class="fa fa-save"></i> Save Changes
                </button>
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/train_ai.php" class="btn-secondary">
                    <i class="fa fa-graduation-cap"></i> Train AI Assistant
                </a>
            </div>
        </div>
    </form>

        <div class="settings-card">
            <h2><i class="fa fa-robot"></i> Assistant Snapshot</h2>
            <p class="help-text">Quick visibility into the assistant’s deployment state.</p>
            <ul class="stat-list">
                <li>
                    <div>
                        <span class="stat-label">Status</span>
                        <span class="stat-note">Overall availability</span>
                    </div>
                    <span class="stat-value badge <?php echo $current_enabled ? 'badge-success' : 'badge-warning'; ?>">
                        <?php echo $current_enabled ? 'Active' : 'Disabled'; ?>
                    </span>
                </li>
                <li>
                    <div>
                        <span class="stat-label">Current Model</span>
                        <span class="stat-note">Performance profile</span>
                    </div>
                    <span class="stat-value badge badge-info"><?php echo htmlspecialchars($model_options[$current_model] ?? $current_model); ?></span>
                </li>
                <li>
                    <div>
                        <span class="stat-label">Floating Button</span>
                        <span class="stat-note">User entry point</span>
                    </div>
                    <span class="stat-value badge <?php echo $current_show_floating ? 'badge-info' : 'badge-warning'; ?>">
                        <?php echo $current_show_floating ? 'Visible' : 'Hidden'; ?>
                    </span>
                </li>
                <li>
                    <div>
                        <span class="stat-label">API Key</span>
                        <span class="stat-note">Credential status</span>
                    </div>
                    <span class="stat-value badge <?php echo !empty($current_api_key) ? 'badge-success' : 'badge-warning'; ?>">
                        <?php echo !empty($current_api_key) ? 'Configured' : 'Missing'; ?>
                    </span>
                </li>
            </ul>
            <div class="help-text" style="margin-top:20px;">
                Need rollout guidance? Visit the <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ai_assistant_docs.php" style="color:#2563eb;">deployment guide</a>.
            </div>
        </div>
    </div>

    <div class="insight-strip">
        <div class="insight-card">
            <h3><i class="fa fa-shield-alt"></i> Security Checklist</h3>
            <p>Rotate the Gemini API key quarterly and restrict usage to trusted environments to keep chats secure.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ai_assistant_docs.php#security">View guide</a>
        </div>
        <div class="insight-card">
            <h3><i class="fa fa-comments"></i> Rollout Strategy</h3>
            <p>Test with a pilot group while you gather prompts and FAQs, then enable the floating launcher site-wide.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/train_ai.php">Plan training</a>
        </div>
        <div class="insight-card">
            <h3><i class="fa fa-lightbulb"></i> Knowledge Boost</h3>
            <p>Attach curated knowledge bases (handbooks, policy docs) so the assistant can deliver verified answers.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ai_assistant_docs.php#knowledge">Add knowledge</a>
        </div>
    </div>
</div>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>


<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>

