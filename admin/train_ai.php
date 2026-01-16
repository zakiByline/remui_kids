<?php
/**
 * AI Training Interface - Modern UI
 * Interface for training the AI assistant with custom rules and instructions
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT, $CFG;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_rules':
            try {
                // Get all AI training rules from database - use existing table
                $rules = $DB->get_records('local_aiassistant_training', null, 'sortorder ASC, id DESC');
                
                // Convert to array and ensure all fields are present
                $rules_array = [];
                foreach ($rules as $rule) {
                    $rules_array[] = [
                        'id' => (int)$rule->id,
                        'title' => $rule->title,
                        'type' => $rule->type,
                        'content' => $rule->content,
                        'enabled' => (int)$rule->enabled,
                        'sortorder' => (int)$rule->sortorder,
                        'timecreated' => (int)$rule->timecreated,
                        'timemodified' => (int)$rule->timemodified
                    ];
                }
                
                echo json_encode(['status' => 'success', 'rules' => $rules_array]);
            } catch (Exception $e) {
                error_log('Error fetching AI training rules: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to load training rules']);
            }
            exit;
            
        case 'add_rule':
            $title = trim($_POST['title']);
            $rule_type = $_POST['rule_type'];
            $rule_content = trim($_POST['rule_content']);
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            
            try {
                // Get max sortorder
                $max_sortorder = $DB->get_field_sql('SELECT MAX(sortorder) FROM {local_aiassistant_training}');
                $sortorder = $max_sortorder !== false ? $max_sortorder + 1 : 1;
                
                $rule = new stdClass();
                $rule->title = $title;
                $rule->type = $rule_type;
                $rule->content = $rule_content;
                $rule->enabled = $enabled;
                $rule->sortorder = $sortorder;
                $rule->timecreated = time();
                $rule->timemodified = time();
                
                $rule_id = $DB->insert_record('local_aiassistant_training', $rule);
                echo json_encode(['status' => 'success', 'message' => 'Training rule added successfully', 'id' => $rule_id]);
            } catch (Exception $e) {
                error_log('Error adding AI training rule: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to add training rule']);
            }
            exit;
            
        case 'toggle_rule':
            $rule_id = intval($_GET['rule_id']);
            
            try {
                $rule = $DB->get_record('local_aiassistant_training', ['id' => $rule_id]);
                if ($rule) {
                    $rule->enabled = $rule->enabled ? 0 : 1;
                    $rule->timemodified = time();
                    $DB->update_record('local_aiassistant_training', $rule);
                    echo json_encode(['status' => 'success', 'message' => 'Rule status updated']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Rule not found']);
                }
            } catch (Exception $e) {
                error_log('Error toggling AI training rule: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to update rule']);
            }
            exit;
            
        case 'delete_rule':
            $rule_id = intval($_GET['rule_id']);
            
            try {
                $DB->delete_records('local_aiassistant_training', ['id' => $rule_id]);
                echo json_encode(['status' => 'success', 'message' => 'Training rule deleted successfully']);
            } catch (Exception $e) {
                error_log('Error deleting AI training rule: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to delete rule']);
            }
            exit;
            
        case 'update_rule':
            $rule_id = intval($_POST['rule_id']);
            $title = trim($_POST['title']);
            $rule_type = $_POST['rule_type'];
            $rule_content = trim($_POST['rule_content']);
            
            try {
                $rule = $DB->get_record('local_aiassistant_training', ['id' => $rule_id]);
                if ($rule) {
                    $rule->title = $title;
                    $rule->type = $rule_type;
                    $rule->content = $rule_content;
                    $rule->timemodified = time();
                    $DB->update_record('local_aiassistant_training', $rule);
                    echo json_encode(['status' => 'success', 'message' => 'Training rule updated successfully']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Rule not found']);
                }
            } catch (Exception $e) {
                error_log('Error updating AI training rule: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to update rule']);
            }
            exit;
    }
}

// Table mdl_local_aiassistant_training already exists - no need to create

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/train_ai.php');
$PAGE->set_title('AI Training Interface');
$PAGE->set_heading('AI Training Interface');

// Lightweight aggregates for UI summary cards.
$total_rules = $DB->count_records('local_aiassistant_training');
$active_rules = $DB->count_records('local_aiassistant_training', ['enabled' => 1]);
$knowledge_rules = $DB->count_records('local_aiassistant_training', ['type' => 'knowledge']);
$persona_rules = $DB->count_records('local_aiassistant_training', ['type' => 'persona']);
$last_update = $DB->get_field_sql('SELECT MAX(timemodified) FROM {local_aiassistant_training}');
$last_update_display = $last_update ? userdate($last_update, get_string('strftimedaydatetime', 'langconfig')) : 'No updates yet';

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Sidebar toggle button for mobile
echo "<button class='sidebar-toggle' onclick='toggleSidebar()' aria-label='Toggle sidebar'>";
echo "<i class='fa fa-bars'></i>";
echo "</button>";

// Main content wrapper
echo "<div class='admin-main-content'>";
?>

<style>
/* Admin Sidebar Navigation - Same as assign_to_school.php */
.admin-sidebar {
    position: fixed !important;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    background: white;
    border-right: 1px solid #e9ecef;
    z-index: 1000;
    overflow-y: auto;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    will-change: transform;
    backface-visibility: hidden;
}

.admin-sidebar .sidebar-content {
    padding: 6rem 0 2rem 0;
}

.admin-sidebar .sidebar-section {
    margin-bottom: 2rem;
}

.admin-sidebar .sidebar-category {
    font-size: 0.75rem;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 1rem;
    padding: 0 2rem;
    margin-top: 0;
}

.admin-sidebar .sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.admin-sidebar .sidebar-item {
    margin-bottom: 0.25rem;
}

.admin-sidebar .sidebar-link {
    display: flex;
    align-items: center;
    padding: 1rem 2rem;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
    font-weight: 500;
    font-size: 0.95rem;
}

.admin-sidebar .sidebar-link:hover {
    background: #f8f9fa;
    color: #2196F3;
    padding-left: 2.5rem;
}

.admin-sidebar .sidebar-item.active .sidebar-link {
    background: linear-gradient(90deg, rgba(33, 150, 243, 0.1) 0%, transparent 100%);
    color: #2196F3;
    border-left: 4px solid #2196F3;
    font-weight: 600;
}

.admin-sidebar .sidebar-icon {
    margin-right: 1rem;
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}

/* Main content area with sidebar */
.admin-main-content {
    position: fixed;
    top: 0;
    left: 280px;
    width: calc(100vw - 280px);
    height: 100vh;
    background: linear-gradient(120deg, #fdf7ff 0%, #f2fbff 50%, #f9fbff 100%);
    overflow-y: auto;
    z-index: 99;
    padding-top: 80px;
}

/* Sidebar toggle button for mobile */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1001;
    background: #2196F3;
    color: white;
    border: none;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
    transition: all 0.3s ease;
}

.sidebar-toggle:hover {
    background: #1976D2;
    transform: scale(1.1);
}

/* Mobile responsive */
@media (max-width: 768px) {
    .admin-sidebar {
        position: fixed;
        top: 0;
        left: -280px;
        transition: left 0.3s ease;
    }
    
    .admin-sidebar.sidebar-open {
        left: 0;
    }
    
    .admin-main-content {
        position: relative;
        left: 0;
        width: 100vw;
        height: auto;
        min-height: 100vh;
        padding-top: 20px;
    }
    
    .sidebar-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
}

/* AI Training Interface Styles */
.training-container {
    width: 100%;
    margin: 0;
    padding: 50px;
    min-height: 100vh;
    background: #ffffff;
    border-radius: 0;
    box-shadow: none;
    border: none;
}

.training-hero {
    background: linear-gradient(120deg, #eef2ff 0%, #f8fbff 70%);
    border-radius: 28px;
    padding: 40px;
    margin-bottom: 30px;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 30px;
    box-shadow: 0 35px 70px rgba(15, 23, 42, 0.12);
    border: 1px solid rgba(148, 163, 184, 0.2);
}

.training-hero-icon {
    background: #ffffff;
    width: 90px;
    height: 90px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    box-shadow: inset 0 0 30px rgba(99,102,241,0.15);
    color: #4f46e5;
}

.training-hero-content h1 {
    font-size: 2.4rem;
    font-weight: 800;
    margin-bottom: 12px;
    color: #0f172a;
}

.training-hero-content p {
    font-size: 1rem;
    color: #475569;
    max-width: 720px;
}

.card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 24px;
}

.card-chip {
    padding: 7px 14px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    background: rgba(226, 232, 240, 0.8);
    color: #475569;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.card-chip.live {
    background: rgba(16,185,129,0.15);
    color: #047857;
}

.card-chip.info {
    background: rgba(59,130,246,0.12);
    color: #1d4ed8;
}

.btn-add-rule {
    background: linear-gradient(135deg, #6366f1 0%, #38bdf8 100%);
    color: white;
    border: none;
    padding: 13px 28px;
    border-radius: 999px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 15px 30px rgba(56, 189, 248, 0.35);
}

.btn-add-rule:hover {
    transform: translateY(-1px);
    box-shadow: 0 20px 35px rgba(56, 189, 248, 0.4);
}

.training-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
    margin-bottom: 30px;
}

.stat-card {
    background: #ffffff;
    border: 1px solid #e4ebf3;
    border-radius: 22px;
    padding: 22px 24px;
    box-shadow: 0 24px 50px rgba(15, 23, 42, 0.08);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.stat-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(99,102,241,0.08), transparent 60%);
    pointer-events: none;
}

.stat-card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}

.stat-card-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: rgba(99,102,241,0.12);
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}

.stat-card:nth-child(2) .stat-card-icon {
    background: rgba(16,185,129,0.15);
    color: #047857;
}

.stat-card:nth-child(3) .stat-card-icon {
    background: rgba(245,158,11,0.15);
    color: #b45309;
}

.stat-card:nth-child(4) .stat-card-icon {
    background: rgba(59,130,246,0.15);
    color: #1d4ed8;
}

.stat-card:nth-child(5) .stat-card-icon {
    background: rgba(148,163,184,0.2);
    color: #0f172a;
}

.stat-label {
    font-size: 0.85rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    margin-top: 4px;
    position: relative;
    z-index: 1;
}

.stat-note {
    font-size: 0.85rem;
    color: #94a3b8;
    margin-top: 4px;
}

.training-rules-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.rule-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 24px 26px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.07);
    border: 1px solid rgba(226, 232, 240, 0.9);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    position: relative;
    overflow: hidden;
}

.rule-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(59,130,246,0.07), transparent 55%);
    pointer-events: none;
}

.rule-card:hover {
    box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
    transform: translateY(-4px);
}

.rule-card.disabled {
    opacity: 0.6;
    background: #f8f9fa;
    border-color: #dee2e6;
}

.rule-card.disabled::after {
    content: 'DISABLED';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 3rem;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.05);
    pointer-events: none;
    z-index: 1;
}

.rule-card.disabled .rule-card-content {
    filter: grayscale(100%);
}

.rule-card.disabled .rule-card-actions .btn-edit,
.rule-card.disabled .rule-card-actions .btn-delete {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.rule-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.rule-card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.rule-badge {
    background: #e0e7ff;
    color: #3730a3;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.08em;
}

.rule-badge.instruction {
    background: #e0f2fe;
    color: #1d4ed8;
}

.rule-badge.doallow {
    background: #dcfce7;
    color: #047857;
}

.rule-badge.dontallow {
    background: #fee2e2;
    color: #b91c1c;
}

.rule-badge.rule {
    background: #fef3c7;
    color: #b45309;
}

.rule-badge.knowledge {
    background: #f3e8ff;
    color: #6d28d9;
}

.rule-card-content {
    background: #f8fbff;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 15px;
    color: #475569;
    line-height: 1.6;
    border: 1px solid #e2e9f2;
}

.rule-card-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-action {
    padding: 9px 18px;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-edit {
    background: rgba(59,130,246,0.12);
    color: #1d4ed8;
    border: 1px solid rgba(59,130,246,0.3);
}

.btn-edit:hover {
    background: rgba(59,130,246,0.18);
}

.btn-toggle {
    background: rgba(16,185,129,0.15);
    color: #047857;
    position: relative;
    padding-left: 40px;
}

.btn-toggle:hover {
    background: #16a34a;
}

.btn-toggle.disabled-state {
    background: #94a3b8;
}

.btn-toggle.disabled-state:hover {
    background: #64748b;
}

/* Toggle switch style */
.btn-toggle::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 24px;
    height: 14px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 7px;
    transition: all 0.3s ease;
}

.btn-toggle::after {
    content: '';
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    background: white;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.btn-toggle:not(.disabled-state)::after {
    left: 20px;
}

.btn-delete {
    background: #ef4444;
    color: white;
}

.btn-delete:hover {
    background: #dc2626;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 16px;
    padding: 30px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    color: #374151;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}

.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.btn-cancel {
    background: #e5e7eb;
    color: #374151;
}

.btn-cancel:hover {
    background: #d1d5db;
}

.btn-submit {
    background: #667eea;
    color: white;
}

.btn-submit:hover {
    background: #5a6fd8;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
}

.empty-state h3 {
    font-size: 1.5rem;
    margin-bottom: 10px;
}

.message {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.message-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.message-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}
</style>

<div class="training-container">
    <div class="training-hero">
        
        <div class="training-hero-content">
            <h1>AI Training Interface</h1>
            <p>Curate persona, policy, and knowledge rules so the assistant mirrors your district’s tone and safeguards.</p>
        </div>
    </div>

    <div class="training-stats">
        <div class="stat-card">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fa fa-layer-group"></i></div>
                <div>
                    <span class="stat-label">Total Rules</span>
                    <span class="stat-note">Across all categories</span>
                </div>
            </div>
            <span class="stat-value"><?php echo number_format($total_rules); ?></span>
        </div>
        <div class="stat-card">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fa fa-toggle-on"></i></div>
                <div>
                    <span class="stat-label">Active Rules</span>
                    <span class="stat-note">Enabled & enforced</span>
                </div>
            </div>
            <span class="stat-value"><?php echo number_format($active_rules); ?></span>
        </div>
        <div class="stat-card">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fa fa-book-open"></i></div>
                <div>
                    <span class="stat-label">Knowledge Entries</span>
                    <span class="stat-note">Linked resources</span>
                </div>
            </div>
            <span class="stat-value"><?php echo number_format($knowledge_rules); ?></span>
        </div>
        <div class="stat-card">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fa fa-user-circle"></i></div>
                <div>
                    <span class="stat-label">Persona Rules</span>
                    <span class="stat-note">Voice & tone</span>
                </div>
            </div>
            <span class="stat-value"><?php echo number_format($persona_rules); ?></span>
        </div>
        <div class="stat-card">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fa fa-clock"></i></div>
                <div>
                    <span class="stat-label">Last Update</span>
                    <span class="stat-note">Most recent change</span>
                </div>
            </div>
            <span class="stat-value" style="font-size:1rem;"><?php echo htmlspecialchars($last_update_display); ?></span>
        </div>
    </div>

    <div style="text-align: center; margin-bottom: 30px;">
        <button class="btn-add-rule" onclick="openAddRuleModal()">
            <i class="fa fa-plus"></i>
            Add Training Rule
        </button>
    </div>

    <div id="messageContainer"></div>
    <div id="trainingRulesList" class="training-rules-list">
        <div class="loading" style="text-align: center; padding: 40px;">
            <i class="fa fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
            <p>Loading training rules...</p>
        </div>
    </div>

    <!-- Add Rule Modal -->
    <div id="addRuleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Training Rule</h3>
                <button class="modal-close" onclick="closeAddRuleModal()">×</button>
            </div>
            <form id="addRuleForm">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" class="form-control" required placeholder="e.g., Be Friendly and Professional">
                </div>
                <div class="form-group">
                    <label for="rule_type">Rule Type</label>
                    <select id="rule_type" name="rule_type" class="form-control" required>
                        <option value="instruction">Instruction</option>
                        <option value="doallow">Do Allow</option>
                        <option value="dontallow">Don't Allow</option>
                        <option value="rule">Rule</option>
                        <option value="knowledge">Knowledge</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule_content">Rule Content</label>
                    <textarea id="rule_content" name="rule_content" required placeholder="Enter the training rule..."></textarea>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="enabled_check" name="enabled" checked>
                        <label for="enabled_check" style="margin: 0;">Enabled</label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-action btn-cancel" onclick="closeAddRuleModal()">Cancel</button>
                    <button type="submit" class="btn-action btn-submit">Add Rule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Rule Modal -->
    <div id="editRuleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Training Rule</h3>
                <button class="modal-close" onclick="closeEditRuleModal()">×</button>
            </div>
            <form id="editRuleForm">
                <input type="hidden" id="edit_rule_id" name="rule_id">
                <div class="form-group">
                    <label for="edit_title">Title</label>
                    <input type="text" id="edit_title" name="title" class="form-control" required placeholder="e.g., Be Friendly and Professional">
                </div>
                <div class="form-group">
                    <label for="edit_rule_type">Rule Type</label>
                    <select id="edit_rule_type" name="rule_type" class="form-control" required>
                        <option value="instruction">Instruction</option>
                        <option value="doallow">Do Allow</option>
                        <option value="dontallow">Don't Allow</option>
                        <option value="rule">Rule</option>
                        <option value="knowledge">Knowledge</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_rule_content">Rule Content</label>
                    <textarea id="edit_rule_content" name="rule_content" required placeholder="Enter the training rule..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-action btn-cancel" onclick="closeEditRuleModal()">Cancel</button>
                    <button type="submit" class="btn-action btn-submit">Update Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let trainingRules = [];

// Load training rules on page load
document.addEventListener('DOMContentLoaded', function() {
    loadTrainingRules();
    
    // Form submission handlers
    document.getElementById('addRuleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        addRule();
    });
    
    document.getElementById('editRuleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        updateRule();
    });
});

function loadTrainingRules() {
    fetch('?action=get_rules')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                trainingRules = data.rules;
                renderRules();
            } else {
                showMessage('Failed to load training rules', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading rules:', error);
            showMessage('Error loading training rules', 'error');
        });
}

function renderRules() {
    const container = document.getElementById('trainingRulesList');
    
    if (trainingRules.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fa fa-inbox"></i>
                <h3>No Training Rules</h3>
                <p>Click "Add Training Rule" to create your first rule</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = trainingRules.map(rule => `
        <div class="rule-card ${rule.enabled ? '' : 'disabled'}">
            <div class="rule-card-header">
                <h3 class="rule-card-title">${escapeHtml(rule.title || rule.type)}</h3>
                <span class="rule-badge ${rule.type}">${rule.type.toUpperCase()}</span>
            </div>
            <div class="rule-card-content">
                ${escapeHtml(rule.content)}
            </div>
            <div class="rule-card-actions">
                <button class="btn-action btn-toggle ${rule.enabled ? '' : 'disabled-state'}" onclick="toggleRule(${rule.id})">
                    <i class="fa fa-power-off"></i>
                    ${rule.enabled ? 'Enabled' : 'Disabled'}
                </button>
                <button class="btn-action btn-edit" onclick="editRule(${rule.id})">
                    <i class="fa fa-edit"></i>
                    Edit
                </button>
                <button class="btn-action btn-delete" onclick="deleteRule(${rule.id})">
                    <i class="fa fa-trash"></i>
                    Delete
                </button>
            </div>
        </div>
    `).join('');
}

function openAddRuleModal() {
    document.getElementById('addRuleModal').classList.add('show');
}

function closeAddRuleModal() {
    document.getElementById('addRuleModal').classList.remove('show');
    document.getElementById('addRuleForm').reset();
}

function openEditRuleModal(rule) {
    document.getElementById('edit_rule_id').value = rule.id;
    document.getElementById('edit_title').value = rule.title || '';
    document.getElementById('edit_rule_type').value = rule.type;
    document.getElementById('edit_rule_content').value = rule.content;
    document.getElementById('editRuleModal').classList.add('show');
}

function closeEditRuleModal() {
    document.getElementById('editRuleModal').classList.remove('show');
}

function addRule() {
    const formData = new FormData(document.getElementById('addRuleForm'));
    formData.append('enabled', document.getElementById('enabled_check').checked ? 1 : 0);
    
    fetch('?action=add_rule', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            closeAddRuleModal();
            loadTrainingRules();
            showMessage('Training rule added successfully!', 'success');
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error adding rule:', error);
        showMessage('Failed to add training rule', 'error');
    });
}

function toggleRule(ruleId) {
    // Find the rule card and add loading state
    const ruleCard = document.querySelector(`.rule-card`);
    
    fetch(`?action=toggle_rule&rule_id=${ruleId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Update the specific rule in the array
                const rule = trainingRules.find(r => r.id == ruleId);
                if (rule) {
                    rule.enabled = rule.enabled ? 0 : 1;
                }
                // Re-render to show the changes with animation
                renderRules();
                showMessage(`Rule ${rule.enabled ? 'enabled' : 'disabled'} successfully`, 'success');
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error toggling rule:', error);
            showMessage('Failed to update rule', 'error');
        });
}

function editRule(ruleId) {
    const rule = trainingRules.find(r => r.id == ruleId);
    if (rule) {
        openEditRuleModal(rule);
    }
}

function updateRule() {
    const formData = new FormData(document.getElementById('editRuleForm'));
    
    fetch('?action=update_rule', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            closeEditRuleModal();
            loadTrainingRules();
            showMessage('Training rule updated successfully!', 'success');
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error updating rule:', error);
        showMessage('Failed to update training rule', 'error');
    });
}

function deleteRule(ruleId) {
    if (!confirm('Are you sure you want to delete this training rule?')) {
        return;
    }
    
    fetch(`?action=delete_rule&rule_id=${ruleId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                loadTrainingRules();
                showMessage('Training rule deleted successfully', 'success');
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error deleting rule:', error);
            showMessage('Failed to delete rule', 'error');
        });
}

function showMessage(message, type) {
    const container = document.getElementById('messageContainer');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    container.innerHTML = '';
    container.appendChild(messageDiv);
    
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

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

