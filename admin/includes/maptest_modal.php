<?php
/**
 * MAP Test Modal Component
 * Reusable modal for MAP Test settings that can be included on any admin page
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $DB, $USER;

// Check if user has admin capability
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context)) {
    return; // Don't show modal if user doesn't have permission
}

// Load MAP Test configuration
try {
    $maptestconfig = get_config('local_maptest');
    $defaulttitle = get_string('default_cardtitle', 'local_maptest');
    $defaultdescription = get_string('default_carddescription', 'local_maptest');
    $defaultbutton = get_string('default_buttontitle', 'local_maptest');
    
    // Fallback if strings not available
    if (empty($defaulttitle)) $defaulttitle = 'MAP Growth Practice';
    if (empty($defaultdescription)) $defaultdescription = 'Boost confidence before assignments';
    if (empty($defaultbutton)) $defaultbutton = 'Start a test';

    $allowedids = [];
    if (!empty($maptestconfig->cohortids)) {
        $allowedids = array_filter(array_map('intval', explode(',', $maptestconfig->cohortids)));
    }

    $cohortoptions = [];
    try {
        $cohortrecords = $DB->get_records('cohort', null, 'name ASC', 'id, name');
        foreach ($cohortrecords as $cohort) {
            $cohortoptions[] = [
                'id' => $cohort->id,
                'name' => format_string($cohort->name),
                'selected' => in_array((int)$cohort->id, $allowedids, true)
            ];
        }
    } catch (Exception $e) {
        $cohortoptions = [];
    }

    $maptest_data = [
        'enabled' => !empty($maptestconfig->enablecard),
        'allowallcohorts' => !empty($maptestconfig->enableallcohorts),
        'cardtitle' => !empty($maptestconfig->cardtitle) ? $maptestconfig->cardtitle : $defaulttitle,
        'carddescription' => !empty($maptestconfig->carddescription) ? $maptestconfig->carddescription : $defaultdescription,
        'buttontitle' => !empty($maptestconfig->buttontitle) ? $maptestconfig->buttontitle : $defaultbutton,
        'hascohortoptions' => !empty($cohortoptions),
        'enablesso' => !empty($maptestconfig->enablesso),
        'maptesturl' => !empty($maptestconfig->maptesturl) ? $maptestconfig->maptesturl : 'https://map-test.bylinelms.com/login',
        'ssosecret' => !empty($maptestconfig->ssosecret) ? $maptestconfig->ssosecret : '',
        'moodle_validation_url' => $CFG->wwwroot . '/local/maptest/validate_sso.php',
        'cohort_options' => $cohortoptions,
        'save_url' => (new moodle_url('/local/maptest/ajax.php'))->out(false),
        'sesskey' => sesskey()
    ];
} catch (Exception $e) {
    return; // If there's an error loading config, don't show modal
}
?>

<style>
/* MAP Test Modal Styles */
.maptest-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: flex-start;
    justify-content: center;
    overflow-y: auto;
    padding: 80px 20px 40px;
    z-index: 1000;
}

.maptest-modal-backdrop.active {
    display: flex;
}

/* Custom Scrollbar for MAP Test Modal */
.maptest-modal-backdrop::-webkit-scrollbar {
    width: 10px;
}

.maptest-modal-backdrop::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.1);
    border-radius: 10px;
}

.maptest-modal-backdrop::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg,rgb(177, 183, 194),rgb(136, 135, 146));
    border-radius: 10px;
    border: 2px solid rgba(15, 23, 42, 0.1);
}

.maptest-modal-backdrop::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg,rgb(176, 180, 187),rgb(228, 228, 241));
}

/* Firefox scrollbar */
.maptest-modal-backdrop {
    scrollbar-width: thin;
    scrollbar-color:rgb(215, 219, 228) rgba(156, 159, 165, 0.1);
}

.maptest-modal {
    background: linear-gradient(135deg, #fef3f2 0%, #f0f9ff 50%, #f5f3ff 100%);
    border-radius: 24px;
    width: min(960px, 100%);
    box-shadow: 0 40px 70px rgba(15, 23, 42, 0.25);
    position: relative;
    margin-top: 20px;
    animation: maptestModalIn 0.25s ease;
    border: 1px solid rgba(255, 255, 255, 0.8);
}

@keyframes maptestModalIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.maptest-modal-header {
    padding: 24px 32px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    background: linear-gradient(135deg, rgba(255, 182, 193, 0.3) 0%, rgba(173, 216, 230, 0.3) 50%, rgba(221, 160, 221, 0.3) 100%);
    border-radius: 24px 24px 0 0;
    margin: -1px -1px 0 -1px;
    border-bottom: 1px solid rgba(221, 160, 221, 0.2);
}

.maptest-modal-header > div:first-child {
    flex: 1;
}

.maptest-modal-header p {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    margin: 0 0 4px 0;
    letter-spacing: 0.1em;
}

.maptest-modal-header h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.maptest-modal-close {
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 1.75rem;
    cursor: pointer;
    line-height: 1;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.maptest-modal-close:hover {
    background: rgba(15, 23, 42, 0.1);
    color: #0f172a;
}

.maptest-modal-body {
    padding: 24px 32px 32px;
    max-height: calc(100vh - 240px);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color:rgb(215, 223, 240) rgba(15, 23, 42, 0.05);
}

/* Custom Scrollbar for Modal Body */
.maptest-modal-body::-webkit-scrollbar {
    width: 8px;
}

.maptest-modal-body::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.05);
    border-radius: 10px;
}

.maptest-modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    border-radius: 10px;
}

.maptest-modal-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #3b82f6, #6366f1);
}

.maptest-admin-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(250, 245, 255, 0.9) 100%);
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
}

.maptest-admin-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

@media (max-width: 768px) {
    .maptest-admin-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

.maptest-switch {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.maptest-switch span {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.95rem;
    user-select: none;
}


.maptest-settings-field {
    margin-bottom: 1.25rem;
}

.maptest-settings-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #0f172a;
    font-size: 0.95rem;
}

.maptest-settings-field input[type="text"],
.maptest-settings-field input[type="url"],
.maptest-settings-field textarea,
.maptest-settings-field select {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    background: #ffffff;
    color: #0f172a;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.maptest-settings-field input[type="text"]:focus,
.maptest-settings-field input[type="url"]:focus,
.maptest-settings-field textarea:focus,
.maptest-settings-field select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.maptest-settings-field textarea {
    min-height: 100px;
    resize: vertical;
    font-family: inherit;
    line-height: 1.5;
}

.maptest-settings-field select[multiple] {
    min-height: 140px;
    padding: 0.5rem;
}

.maptest-settings-field select[multiple] option {
    padding: 0.5rem;
    margin: 2px 0;
    border-radius: 4px;
}

.maptest-settings-field select[multiple] option:checked {
    background: #2563eb;
    color: white;
}

.maptest-admin-actions {
    margin-top: 2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.maptest-admin-save {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.maptest-admin-save:hover:not(.disabled) {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.maptest-admin-save.disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.maptest-admin-feedback {
    font-size: 0.9rem;
    font-weight: 600;
}

.maptest-admin-feedback.success {
    color: #059669;
}

.maptest-admin-feedback.error {
    color: #dc2626;
}
</style>

<div class="maptest-modal-backdrop" id="maptest-modal" role="dialog" aria-modal="true" aria-labelledby="maptest-modal-title" aria-hidden="true" tabindex="-1">
    <div class="maptest-modal">
        <div class="maptest-modal-header">
            <div>
                <p>MAP TEST LAUNCHER</p>
                <h3 id="maptest-modal-title">Student card visibility</h3>
            </div>
            <button type="button" class="maptest-modal-close" id="maptest-modal-close" aria-label="Close modal">&times;</button>
        </div>
        <div class="maptest-modal-body">
            <div class="maptest-admin-card">
                <div class="maptest-admin-grid">
                    <div>
                        <div class="maptest-switch">
                            <input type="checkbox" id="maptest-enable-toggle" <?php echo $maptest_data['enabled'] ? 'checked' : ''; ?>>
                            <span>Enable MAP Test card</span>
                        </div>
                        <p class="text-muted" style="font-size: 0.85rem; margin-top: 0.5rem; margin-bottom: 0; color: #64748b; line-height: 1.5;">
                            Toggle the launch card for all eligible students.
                        </p>
                    </div>
                    <div>
                        <div class="maptest-switch">
                            <input type="checkbox" id="maptest-allowall-toggle" <?php echo $maptest_data['allowallcohorts'] ? 'checked' : ''; ?>>
                            <span>Allow every cohort</span>
                        </div>
                        <p class="text-muted" style="font-size: 0.85rem; margin-top: 0.5rem; margin-bottom: 0; color: #64748b; line-height: 1.5;">
                            When enabled, the card ignores cohort restrictions.
                        </p>
                    </div>
                </div>

                <div class="maptest-settings-field" style="margin-top: 1.5rem;">
                    <label for="maptest-cohorts">Allowed cohorts</label>
                    <select id="maptest-cohorts" multiple>
                        <?php foreach ($maptest_data['cohort_options'] as $cohort): ?>
                        <option value="<?php echo $cohort['id']; ?>" <?php echo $cohort['selected'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cohort['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$maptest_data['hascohortoptions']): ?>
                    <small class="text-muted" style="display: block; margin-top: 0.5rem; font-size: 0.85rem; color: #64748b;">No cohorts found yet. Create cohorts under Site administration to limit access.</small>
                    <?php else: ?>
                    <small class="text-muted" style="display: block; margin-top: 0.5rem; font-size: 0.85rem; color: #64748b;">Hold Ctrl (or Cmd on Mac) to select multiple cohorts</small>
                    <?php endif; ?>
                </div>

                <div class="maptest-admin-grid" style="margin-top: 1.5rem;">
                    <div class="maptest-settings-field">
                        <label for="maptest-title">Card title</label>
                        <input type="text" id="maptest-title" value="<?php echo htmlspecialchars($maptest_data['cardtitle']); ?>" maxlength="120">
                    </div>
                    <div class="maptest-settings-field">
                        <label for="maptest-button">Button label</label>
                        <input type="text" id="maptest-button" value="<?php echo htmlspecialchars($maptest_data['buttontitle']); ?>" maxlength="60">
                    </div>
                </div>

                <div class="maptest-settings-field" style="margin-top: 1.2rem;">
                    <label for="maptest-description">Card description</label>
                    <textarea id="maptest-description"><?php echo htmlspecialchars($maptest_data['carddescription']); ?></textarea>
                </div>

                <!-- SSO Settings Section -->
                <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid rgba(221, 160, 221, 0.3);">
                    <h4 style="margin: 0 0 1rem 0; font-size: 1.1rem; font-weight: 600; color: #0f172a;">SSO Integration</h4>
                    
                    <div class="maptest-switch" style="margin-bottom: 1rem;">
                        <input type="checkbox" id="maptest-enablesso" <?php echo $maptest_data['enablesso'] ? 'checked' : ''; ?>>
                        <span>Enable Single Sign-On</span>
                    </div>
                    <p class="text-muted" style="font-size: 0.85rem; margin-top: 0.5rem; margin-bottom: 1rem; color: #64748b; line-height: 1.5;">
                        Automatically log users into MAP Test without entering credentials.
                    </p>

                    <div class="maptest-settings-field" style="margin-top: 1rem;">
                        <label for="maptest-url">MAP Test Platform URL</label>
                        <input type="url" id="maptest-url" value="<?php echo htmlspecialchars($maptest_data['maptesturl']); ?>" placeholder="https://map-test.bylinelms.com/login">
                    </div>

                    <div class="maptest-settings-field" style="margin-top: 1rem;">
                        <label for="maptest-ssosecret">SSO Secret Key</label>
                        <input type="text" id="maptest-ssosecret" value="<?php echo htmlspecialchars($maptest_data['ssosecret']); ?>" placeholder="Enter shared secret key">
                        <small class="text-muted" style="display: block; margin-top: 0.5rem; font-size: 0.8rem;">
                            Must match the secret configured on the MAP Test platform. Leave empty to use default.
                        </small>
                    </div>

                    <!-- Information Box: What to Share with MAP Test -->
                    <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(173, 216, 230, 0.2); border-radius: 8px; border-left: 3px solid #4fc3f7;">
                        <h5 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; font-weight: 600; color: #0f172a;">
                            <i class="fa fa-info-circle"></i> Share with MAP Test Platform
                        </h5>
                        <p style="margin: 0 0 0.75rem 0; font-size: 0.85rem; color: #475569;">
                            Provide the following information to the MAP Test platform team:
                        </p>
                        <div style="background: white; padding: 0.75rem; border-radius: 6px; margin-bottom: 0.5rem;">
                            <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.25rem;">Validation Endpoint URL:</div>
                            <div style="font-size: 0.85rem; font-family: monospace; color: #0f172a; word-break: break-all; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                                <?php echo htmlspecialchars($maptest_data['moodle_validation_url']); ?>
                            </div>
                            <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($maptest_data['moodle_validation_url'], ENT_QUOTES); ?>', this)" style="margin-top: 0.5rem; padding: 0.25rem 0.75rem; font-size: 0.75rem; background: #4fc3f7; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="fa fa-copy"></i> Copy URL
                            </button>
                        </div>
                        <div style="background: white; padding: 0.75rem; border-radius: 6px;">
                            <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.25rem;">Shared Secret Key:</div>
                            <div style="font-size: 0.85rem; font-family: monospace; color: #0f172a; word-break: break-all; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                                <?php if (!empty($maptest_data['ssosecret'])): ?>
                                    <?php echo htmlspecialchars($maptest_data['ssosecret']); ?>
                                <?php else: ?>
                                    <em style="color: #94a3b8;">Not set - using default</em>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($maptest_data['ssosecret'])): ?>
                            <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($maptest_data['ssosecret'], ENT_QUOTES); ?>', this)" style="margin-top: 0.5rem; padding: 0.25rem 0.75rem; font-size: 0.75rem; background: #4fc3f7; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="fa fa-copy"></i> Copy Secret
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="maptest-admin-actions">
                    <button class="maptest-admin-save" id="maptest-admin-save"
                        data-endpoint="<?php echo htmlspecialchars($maptest_data['save_url']); ?>"
                        data-sesskey="<?php echo htmlspecialchars($maptest_data['sesskey']); ?>">
                        <i class="fa fa-save"></i>
                        Save MAP settings
                    </button>
                    <div class="maptest-admin-feedback" id="maptest-admin-feedback" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(function() {
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fa fa-check"></i> Copied!';
        button.style.background = '#059669';
        setTimeout(function() {
            button.innerHTML = originalText;
            button.style.background = '#4fc3f7';
        }, 2000);
    }).catch(function() {
        alert('Failed to copy to clipboard');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebarLink = document.getElementById('maptest-sidebar-link');
    const modal = document.getElementById('maptest-modal');
    const closeBtn = document.getElementById('maptest-modal-close');

    const setModalState = (open) => {
        if (!modal) {
            return;
        }
        modal.classList.toggle('active', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open && typeof modal.focus === 'function') {
            modal.focus();
        }
    };

    // Handle sidebar link
    if (sidebarLink && modal) {
        sidebarLink.addEventListener('click', (e) => {
            e.preventDefault();
            setModalState(true);
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', () => setModalState(false));
    }

    if (modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                setModalState(false);
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && modal.classList.contains('active')) {
            setModalState(false);
        }
    });

    const saveButton = document.getElementById('maptest-admin-save');
    if (!saveButton) {
        return;
    }

    const enableToggle = document.getElementById('maptest-enable-toggle');
    const allowAllToggle = document.getElementById('maptest-allowall-toggle');
    const cohortSelect = document.getElementById('maptest-cohorts');
    const titleInput = document.getElementById('maptest-title');
    const descriptionInput = document.getElementById('maptest-description');
    const buttonInput = document.getElementById('maptest-button');
    const enablessoToggle = document.getElementById('maptest-enablesso');
    const maptestUrlInput = document.getElementById('maptest-url');
    const ssosecretInput = document.getElementById('maptest-ssosecret');
    const feedbackEl = document.getElementById('maptest-admin-feedback');
    const endpoint = saveButton.dataset.endpoint;
    const sesskey = saveButton.dataset.sesskey;

    const setFeedback = (message, type) => {
        if (!feedbackEl) {
            return;
        }
        feedbackEl.textContent = message || '';
        feedbackEl.classList.remove('success', 'error');
        if (type) {
            feedbackEl.classList.add(type);
        }
    };

    const setLoading = (isLoading) => {
        saveButton.classList.toggle('disabled', isLoading);
        saveButton.disabled = isLoading;
        if (isLoading) {
            setFeedback('Saving...', null);
        }
    };

    const syncCohortState = () => {
        if (!cohortSelect || !allowAllToggle) {
            return;
        }
        cohortSelect.disabled = allowAllToggle.checked;
    };

    if (allowAllToggle) {
        allowAllToggle.addEventListener('change', syncCohortState);
    }
    syncCohortState();

    saveButton.addEventListener('click', () => {
        if (!endpoint || !sesskey) {
            setFeedback('Missing endpoint configuration.', 'error');
            return;
        }

        const cohortIds = (!cohortSelect || (allowAllToggle && allowAllToggle.checked))
            ? []
            : Array.from(cohortSelect.selectedOptions).map(option => parseInt(option.value, 10)).filter(Boolean);

        const payload = {
            action: 'save',
            enablecard: enableToggle ? (enableToggle.checked ? 1 : 0) : 0,
            enableallcohorts: allowAllToggle ? (allowAllToggle.checked ? 1 : 0) : 0,
            cohortids: cohortIds,
            cardtitle: titleInput ? titleInput.value.trim() : '',
            carddescription: descriptionInput ? descriptionInput.value.trim() : '',
            buttontitle: buttonInput ? buttonInput.value.trim() : '',
            enablesso: enablessoToggle ? (enablessoToggle.checked ? 1 : 0) : 0,
            maptesturl: maptestUrlInput ? maptestUrlInput.value.trim() : '',
            ssosecret: ssosecretInput ? ssosecretInput.value.trim() : ''
        };

        // Add sesskey to URL as Moodle requires it as a GET/POST parameter
        const urlWithSesskey = endpoint + (endpoint.includes('?') ? '&' : '?') + 'sesskey=' + encodeURIComponent(sesskey);
        
        console.log('Sending payload:', payload);
        setLoading(true);

        fetch(urlWithSesskey, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON:', text);
                    throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                }
            });
        })
        .then(data => {
            setLoading(false);
            console.log('Save response:', data);
            // Check for both possible response formats
            if (data.status === 'success' || data.success === true) {
                // Update form fields with saved values from response
                if (data.config) {
                    const config = data.config;
                    
                    // Update checkboxes
                    if (enableToggle) {
                        enableToggle.checked = config.enablecard || false;
                    }
                    if (allowAllToggle) {
                        allowAllToggle.checked = config.enableallcohorts || false;
                    }
                    if (enablessoToggle) {
                        enablessoToggle.checked = config.enablesso || false;
                    }
                    
                    // Update text inputs
                    if (titleInput) {
                        titleInput.value = config.cardtitle || '';
                    }
                    if (buttonInput) {
                        buttonInput.value = config.buttontitle || '';
                    }
                    if (descriptionInput) {
                        descriptionInput.value = config.carddescription || '';
                    }
                    if (maptestUrlInput) {
                        maptestUrlInput.value = config.maptesturl || '';
                    }
                    if (ssosecretInput) {
                        // Don't update SSO secret if it's masked (***)
                        if (config.ssosecret && config.ssosecret !== '***') {
                            ssosecretInput.value = config.ssosecret;
                        }
                    }
                    
                    // Update cohort select
                    if (cohortSelect && config.cohortids) {
                        // Clear all selections first
                        Array.from(cohortSelect.options).forEach(option => {
                            option.selected = false;
                        });
                        
                        // Select the cohorts from response
                        if (Array.isArray(config.cohortids)) {
                            config.cohortids.forEach(cohortId => {
                                const option = cohortSelect.querySelector(`option[value="${cohortId}"]`);
                                if (option) {
                                    option.selected = true;
                                }
                            });
                        }
                    }
                    
                    // Sync cohort state after update
                    syncCohortState();
                }
                
                setFeedback('Settings saved successfully!', 'success');
                setTimeout(() => setFeedback('', null), 3000);
            } else {
                const errorMsg = data.message || data.error || 'Failed to save settings.';
                setFeedback(errorMsg, 'error');
                console.error('Save failed:', data);
            }
        })
        .catch(error => {
            setLoading(false);
            console.error('MAP Test save error:', error);
            setFeedback('Error: ' + (error.message || 'Please try again.'), 'error');
        });
    });
});
</script>

