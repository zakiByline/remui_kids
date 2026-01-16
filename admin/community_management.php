<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Community Management Page
 * Allows super admin to manage blocked words and custom AI moderation prompts
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// Check if user is logged in
require_login();

// Check if user is super admin
$context = context_system::instance();
if (!is_siteadmin()) {
    throw new moodle_exception('nopermission', 'error');
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_sesskey();
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_blocked_words':
            $words = $DB->get_records('communityhub_blocked_words', [], 'timecreated DESC', 'id, word, case_sensitive, timecreated');
            echo json_encode(['success' => true, 'data' => array_values($words)]);
            exit;
            
        case 'add_blocked_word':
            $word = trim(required_param('word', PARAM_TEXT));
            $case_sensitive = optional_param('case_sensitive', 0, PARAM_INT);
            
            if (empty($word)) {
                echo json_encode(['success' => false, 'error' => 'Word cannot be empty']);
                exit;
            }
            
            // Check if word already exists
            $existing = $DB->get_record('communityhub_blocked_words', ['word' => $word]);
            if ($existing) {
                echo json_encode(['success' => false, 'error' => 'This word is already blocked']);
                exit;
            }
            
            $record = (object) [
                'word' => $word,
                'case_sensitive' => $case_sensitive ? 1 : 0,
                'timecreated' => time(),
                'createdby' => $USER->id
            ];
            
            $id = $DB->insert_record('communityhub_blocked_words', $record);
            echo json_encode(['success' => true, 'data' => ['id' => $id, 'word' => $word, 'case_sensitive' => $case_sensitive]]);
            exit;
            
        case 'delete_blocked_word':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('communityhub_blocked_words', ['id' => $id]);
            echo json_encode(['success' => true]);
            exit;
            
        case 'get_custom_prompt':
            $setting = $DB->get_record('communityhub_moderation_settings', ['setting_key' => 'custom_prompt']);
            $prompt = $setting ? $setting->setting_value : '';
            
            // Clean the prompt before sending to frontend (remove content insertion section)
            // This ensures admin never sees the "Content to analyze:" section
            if (!empty($prompt)) {
                $prompt = preg_replace('/\n*Content to analyze:\s*\n*---\s*\n*\{CONTENT\}\s*\n*---\s*/i', '', $prompt);
                $prompt = str_replace('{CONTENT}', '', $prompt);
                $prompt = trim($prompt);
            }
            
            echo json_encode(['success' => true, 'data' => $prompt]);
            exit;
            
        case 'save_custom_prompt':
            $prompt = required_param('prompt', PARAM_RAW);
            
            // Clean the prompt - remove any "Content to analyze:" sections and {CONTENT} placeholders
            // (These are automatically added by the system and shouldn't be saved)
            $prompt = preg_replace('/\n*Content to analyze:\s*\n*---\s*\n*\{CONTENT\}\s*\n*---\s*/i', '', $prompt);
            $prompt = str_replace('{CONTENT}', '', $prompt);
            $prompt = trim($prompt);
            
            $existing = $DB->get_record('communityhub_moderation_settings', ['setting_key' => 'custom_prompt']);
            if ($existing) {
                $existing->setting_value = $prompt;
                $existing->timemodified = time();
                $DB->update_record('communityhub_moderation_settings', $existing);
            } else {
                $record = (object) [
                    'setting_key' => 'custom_prompt',
                    'setting_value' => $prompt,
                    'timecreated' => time(),
                    'timemodified' => time(),
                    'createdby' => $USER->id
                ];
                $DB->insert_record('communityhub_moderation_settings', $record);
            }
            
            echo json_encode(['success' => true]);
            exit;
    }
}

// Set up page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/community_management.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Community Management - Content Moderation');
$PAGE->set_heading('Community Management');

echo $OUTPUT->header();

// Include admin sidebar
require_once(__DIR__ . '/includes/admin_sidebar.php');
?>

<style>
.admin-main-content {
    padding: 2rem;
    margin-top: 80px !important;
}

.community-management-container {
    width: 100%;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 2rem;
    text-align: center;
}

.page-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.5rem;
}

.page-header p {
    color: #6b7280;
    font-size: 0.95rem;
    max-width: 700px;
    margin: 0 auto;
}

.settings-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    padding: 2rem;
    margin-bottom: 2rem;
    width: 100%;
    box-sizing: border-box;
}

.section-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.section-title i {
    color: #3b82f6;
}

.section-description {
    color: #6b7280;
    font-size: 0.9rem;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.form-group textarea {
    width: 100%;
    min-height: 200px;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    font-family: 'Courier New', monospace;
    resize: vertical;
}

.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input[type="text"] {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
}

.form-group input[type="text"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-group label {
    margin: 0;
    font-weight: 400;
    cursor: pointer;
}

.btn {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.words-list {
    margin-top: 1.5rem;
}

.word-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.word-item-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex: 1;
}

.word-text {
    font-weight: 500;
    color: #1f2937;
    font-size: 0.95rem;
}

.word-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-case-sensitive {
    background: #fef3c7;
    color: #92400e;
}

.badge-case-insensitive {
    background: #dbeafe;
    color: #1e40af;
}

.word-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.info-box p {
    margin: 0;
    color: #1e40af;
    font-size: 0.9rem;
    line-height: 1.6;
}

.info-box strong {
    font-weight: 600;
}

.hidden-section {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1rem;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #6b7280;
}

.hidden-section-title {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.loading {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.success-message {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    color: #065f46;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    width: 100%;
    display: none;
}

.error-message {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    width: 100%;
    display: none;
}
</style>

<div class="admin-main-content">
    <div class="community-management-container">
        <div class="page-header">
            <h1><i class="fa fa-shield-alt"></i> Community Management</h1>
            <p>Manage content moderation settings, blocked words, and AI prompts to keep the community clean and safe for all users.</p>
        </div>

        <div id="successMessage" class="success-message"></div>
        <div id="errorMessage" class="error-message"></div>

        <!-- Blocked Words Section -->
        <div class="settings-section">
            <h2 class="section-title">
                <i class="fa fa-ban"></i>
                Blocked Words & Phrases
            </h2>
            <p class="section-description">
                Add words or phrases that should be automatically flagged. These will be checked before AI analysis, ensuring immediate blocking of prohibited content. 
                 </p>

            <div class="form-group">
                <label for="blockedWordInput">Add Blocked Word or Phrase</label>
                <input type="text" id="blockedWordInput" placeholder="Enter word or phrase to block..." />
                <div class="checkbox-group">
                    <input type="checkbox" id="caseSensitiveCheck" />
                    <label for="caseSensitiveCheck">Case sensitive</label>
                </div>
            </div>

            <button class="btn btn-primary" onclick="addBlockedWord()">
                <i class="fa fa-plus"></i>
                Add Word
            </button>

            <div class="words-list" id="blockedWordsList">
                <div style="text-align: center; padding: 2rem; color: #9ca3af;">
                    <i class="fa fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
        </div>

        <!-- Custom AI Prompt Section -->
        <div class="settings-section">
            <h2 class="section-title">
                <i class="fa fa-robot"></i>
                Custom AI Moderation Prompt
            </h2>
            <p class="section-description">
                Customize the AI prompt that analyzes content. You can add specific instructions about what content should be flagged. 
                The system will automatically append the content to analyze and the required JSON format instructions.
            </p>

            <div class="form-group">
                <label for="customPromptTextarea">AI Moderation Prompt</label>
                <textarea id="customPromptTextarea" placeholder="Enter your custom AI moderation prompt..."></textarea>
            </div>

            <div class="hidden-section">
                <div class="hidden-section-title">System-Required Instructions (Automatically Added - Not Editable):</div>
                <div>
                    <strong>1. Content Insertion:</strong> The system automatically appends "Content to analyze:" section with the actual post content.<br><br>
                    <strong>2. JSON Format Requirement:</strong> Respond ONLY with a JSON object in this exact format:<br>
                    {"flagged": true/false, "reason": "brief explanation"}<br><br>
                    If flagged is true, provide a clear reason. If false, reason can be empty.
                </div>
            </div>

            <button class="btn btn-primary" onclick="saveCustomPrompt()">
                <i class="fa fa-save"></i>
                Save Prompt
            </button>
        </div>
    </div>
</div>

<script>
let blockedWords = [];

// Load blocked words on page load
document.addEventListener('DOMContentLoaded', function() {
    loadBlockedWords();
    loadCustomPrompt();
});

function showMessage(message, type) {
    const successEl = document.getElementById('successMessage');
    const errorEl = document.getElementById('errorMessage');
    
    if (type === 'success') {
        successEl.textContent = message;
        successEl.style.display = 'block';
        errorEl.style.display = 'none';
        setTimeout(() => {
            successEl.style.display = 'none';
        }, 5000);
    } else {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
        successEl.style.display = 'none';
        setTimeout(() => {
            errorEl.style.display = 'none';
        }, 5000);
    }
}

function loadBlockedWords() {
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/community_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_blocked_words&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            blockedWords = data.data;
            renderBlockedWords();
        } else {
            showMessage('Failed to load blocked words', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Failed to load blocked words', 'error');
    });
}

function renderBlockedWords() {
    const container = document.getElementById('blockedWordsList');
    
    if (blockedWords.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #9ca3af;">No blocked words yet. Add words above to get started.</div>';
        return;
    }
    
    let html = '';
    blockedWords.forEach(word => {
        html += `
            <div class="word-item">
                <div class="word-item-content">
                    <span class="word-text">${escapeHtml(word.word)}</span>
                    <span class="word-badge ${word.case_sensitive ? 'badge-case-sensitive' : 'badge-case-insensitive'}">
                        ${word.case_sensitive ? 'Case Sensitive' : 'Case Insensitive'}
                    </span>
                </div>
                <div class="word-actions">
                    <button class="btn btn-danger btn-sm" onclick="deleteBlockedWord(${word.id})">
                        <i class="fa fa-trash"></i>
                        Delete
                    </button>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function addBlockedWord() {
    const wordInput = document.getElementById('blockedWordInput');
    const caseSensitiveCheck = document.getElementById('caseSensitiveCheck');
    const word = wordInput.value.trim();
    
    if (!word) {
        showMessage('Please enter a word or phrase', 'error');
        return;
    }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="loading"></span> Adding...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/community_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=add_blocked_word&word=${encodeURIComponent(word)}&case_sensitive=${caseSensitiveCheck.checked ? 1 : 0}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            wordInput.value = '';
            caseSensitiveCheck.checked = false;
            showMessage('Word added successfully', 'success');
            loadBlockedWords();
        } else {
            showMessage(data.error || 'Failed to add word', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Error:', error);
        showMessage('Failed to add word', 'error');
    });
}

function deleteBlockedWord(id) {
    if (!confirm('Are you sure you want to delete this blocked word?')) {
        return;
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/community_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete_blocked_word&id=${id}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('Word deleted successfully', 'success');
            loadBlockedWords();
        } else {
            showMessage('Failed to delete word', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Failed to delete word', 'error');
    });
}

function loadCustomPrompt() {
    const textarea = document.getElementById('customPromptTextarea');
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/community_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_custom_prompt&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            // Remove the "Content to analyze:" section and {CONTENT} placeholder from saved prompt
            // (These are automatically added by the system and hidden from admin)
            let prompt = data.data;
            prompt = prompt.replace(/\n*Content to analyze:\s*\n*---\s*\n*\{CONTENT\}\s*\n*---\s*/gi, '');
            prompt = prompt.replace(/\{CONTENT\}/g, '');
            prompt = prompt.trim();
            textarea.value = prompt;
        } else {
            // Default prompt (without content insertion section - hidden from admin)
            textarea.value = `You are a content moderation system for an educational community platform. Analyze the following post content and determine if it should be flagged for moderation.

Flag the content if it contains:
1. Vile language, profanity, or offensive words
2. Hate speech, discrimination, racism, or harassment
3. Links to inappropriate websites (pornography, violence, illegal content)
4. Spam or malicious content
5. Personal attacks or bullying
6. Political agendas or political content
7. Content that discriminates against any group of people

Do NOT flag:
- Educational discussions
- Constructive criticism
- Legitimate questions or help requests
- Appropriate links to educational resources`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function saveCustomPrompt() {
    const textarea = document.getElementById('customPromptTextarea');
    let prompt = textarea.value.trim();
    
    if (!prompt) {
        showMessage('Please enter a prompt', 'error');
        return;
    }
    
    // Clean the prompt - remove any "Content to analyze:" sections and {CONTENT} placeholders
    // (These are automatically added by the system and shouldn't be saved)
    prompt = prompt.replace(/\n*Content to analyze:\s*\n*---\s*\n*\{CONTENT\}\s*\n*---\s*/gi, '');
    prompt = prompt.replace(/\{CONTENT\}/g, '');
    prompt = prompt.trim();
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="loading"></span> Saving...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/community_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=save_custom_prompt&prompt=${encodeURIComponent(prompt)}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showMessage('Prompt saved successfully', 'success');
        } else {
            showMessage('Failed to save prompt', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Error:', error);
        showMessage('Failed to save prompt', 'error');
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Allow Enter key to add word
document.getElementById('blockedWordInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        addBlockedWord();
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>




