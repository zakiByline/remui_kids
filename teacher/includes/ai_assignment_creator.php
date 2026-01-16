<?php
/**
 * Partial: AI Assignment Creator block.
 *
 * Contains the markup for the teacher assignment AI helper used on the
 * assignment creation page.
 */
?>

<!-- AI Assistant for Assignment Creation -->
<div class="ai-assignment-creator" id="aiAssignmentCreator">
    <div class="ai-creator-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa fa-magic" style="color: #0dcaf0; font-size: 20px;"></i>
            <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #334155;">
                AI Assignment Creator
            </h3>
        </div>
        <button type="button" class="ai-creator-toggle" id="aiCreatorToggle" onclick="toggleAiCreator()">
            <i class="fa fa-chevron-down"></i>
        </button>
    </div>
    <div class="ai-creator-content" id="aiCreatorContent" style="display: none;">
        <p class="ai-creator-description">
            <i class="fa fa-info-circle"></i>
            Enter a topic name and let AI generate a complete assignment with description and instructions.
        </p>

        <!-- AI Suggestions based on selected lesson/module -->
        <div class="ai-suggestions-container" id="aiSuggestionsContainer" style="display: none;">
            <div class="ai-suggestions-header">
                <i class="fa fa-sparkles"></i>
                <span>Suggested assignments for <strong id="aiSuggestionContext"></strong></span>
            </div>
            <div class="ai-suggestions-list" id="aiSuggestionsList">
                <!-- Suggestions will be populated here -->
            </div>
        </div>

        <div class="ai-creator-input-group">
            <label class="form-label" for="aiTopicInput">
                <i class="fa fa-lightbulb"></i> Assignment Topic
            </label>
            <input type="text" id="aiTopicInput" class="form-input"
                   placeholder="e.g., Introduction to Digital Art, World War II, Photosynthesis..."
                   style="margin-bottom: 12px;">
            <button type="button" class="btn-generate-assignment" id="btnGenerateAssignment" onclick="generateAssignmentWithAI()">
                <i class="fa fa-magic"></i>
                Generate Assignment Details
            </button>
        </div>
        <div class="ai-generation-status" id="aiGenerationStatus" style="display: none;">
            <i class="fa fa-spinner fa-spin"></i>
            <span>AI is generating your assignment details...</span>
        </div>
    </div>
</div>