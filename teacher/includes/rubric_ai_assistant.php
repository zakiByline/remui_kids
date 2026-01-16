<?php
/**
 * Partial: Rubric AI assistant markup.
 *
 * Contains the AI button, diagnostic link, and modal for rubric assistance.
 *
 * Variables expected from parent scope:
 * - $aiassistantinstalled
 * - $aiassistantenabled
 * - $aiassistantpermitted
 * - $aiassistantcanuse
 */
?>

<div class="rubric-builder-header">
    <h3 class="rubric-builder-title">
        <i class="fa fa-table"></i>
        Rubric Design
    </h3>
</div>

<div class="rubric-ai-panel" id="rubricAiPanel">
    <div class="rubric-ai-dialog">
        <div class="rubric-ai-header">
            <h3>
                <i class="fa fa-robot"></i>
                Rubric AI Assistant
            </h3>
        </div>
        <div class="rubric-ai-status" id="rubricAiStatus">
            <?php if (!$aiassistantinstalled): ?>
                The AI Assistant plugin (<code>local_aiassistant</code>) is not installed on this site. Please ask your administrator to install it.
            <?php elseif (!$aiassistantenabled): ?>
                The AI Assistant is currently disabled. Enable it in Site administration → Plugins → Local plugins → AI Assistant.
            <?php elseif (!$aiassistantpermitted): ?>
                You do not have permission to use the AI Assistant yet. Please contact your administrator to request the <code>local/aiassistant:use</code> capability.
            <?php else: ?>
                Ask the assistant to suggest criteria, levels, or improvements for your rubric. Include what your assignment measures and the skills you expect young (Pre-K) learners to demonstrate so the language stays gentle and encouraging.
            <?php endif; ?>
        </div>
        <div class="rubric-ai-config" id="rubricAiConfig" <?php echo $aiassistantcanuse ? '' : 'style="display:none;"'; ?>>
            <div class="rubric-config-title">
                <i class="fa fa-cog"></i> Rubric Configuration
            </div>
            <div class="rubric-config-options">
                <div class="rubric-config-item">
                    <label for="rubricCriteriaCount">
                        <i class="fa fa-list"></i> Number of Criteria (Rows):
                    </label>
                    <select id="rubricCriteriaCount" class="rubric-config-select">
                        <option value="3">3 Criteria</option>
                        <option value="4" selected>4 Criteria</option>
                        <option value="5">5 Criteria</option>
                        <option value="6">6 Criteria</option>
                        <option value="7">7 Criteria</option>
                    </select>
                </div>
                <div class="rubric-config-item">
                    <label for="rubricLevelsCount">
                        <i class="fa fa-columns"></i> Number of Levels (Columns):
                    </label>
                    <select id="rubricLevelsCount" class="rubric-config-select">
                        <option value="3">3 Levels</option>
                        <option value="4" selected>4 Levels</option>
                        <option value="5">5 Levels</option>
                        <option value="6">6 Levels</option>
                    </select>
                </div>
            </div>
            <button type="button" id="rubricGenerateBtn" class="rubric-generate-btn">
                <i class="fa fa-magic"></i> Generate Rubric
            </button>
        </div>
        <div class="rubric-ai-messages" id="rubricAiMessages"></div>
        <div class="rubric-ai-chat-input" id="rubricAiChatInput" <?php echo $aiassistantcanuse ? '' : 'style="display:none;"'; ?>>
            <div class="rubric-ai-input-wrapper">
                <textarea
                    id="rubricAiInput"
                    class="rubric-ai-input-field"
                    placeholder="Ask me to create a rubric, suggest criteria, or improve your existing rubric..."
                    rows="2"></textarea>
                <button type="button" id="rubricAiSendBtn" class="rubric-ai-send-btn">
                    <i class="fa fa-paper-plane"></i>
                </button>
            </div>
            <div class="rubric-ai-quick-actions">
                <button type="button" class="rubric-ai-quick-btn" data-prompt="Create a rubric for this assignment">
                    <i class="fa fa-magic"></i> Create Rubric
                </button>
                <button type="button" class="rubric-ai-quick-btn" data-prompt="Suggest criteria for this assignment">
                    <i class="fa fa-lightbulb"></i> Suggest Criteria
                </button>
                <button type="button" class="rubric-ai-quick-btn" data-prompt="Improve my existing rubric">
                    <i class="fa fa-arrow-up"></i> Improve Rubric
                </button>
            </div>
        </div>
        <div class="rubric-ai-summary" id="rubricAiSummary" style="display:none;"></div>
    </div>
</div>

