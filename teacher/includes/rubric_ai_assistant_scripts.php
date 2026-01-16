<?php
/**
 * Script partial for the Rubric AI assistant initialization and handlers.
 */
?>
<script>
// Initialize Rubric AI Assistant - Full Chat Interface with Diagnostics
(function() {
    console.log('=== Rubric AI Assistant: Starting Initialization ===');

    const config = {
        installed: '<?php echo $aiassistantinstalled ? '1' : '0'; ?>',
        enabled: '<?php echo $aiassistantenabled ? '1' : '0'; ?>',
        allowed: '<?php echo $aiassistantpermitted ? '1' : '0'; ?>'
    };

    console.log('Config:', config);

    function initializeRubricAi() {
        if (typeof require === 'undefined') {
            console.error('✗ Moodle AMD loader (require) not available. Waiting for it...');
            var checkRequire = setInterval(function() {
                if (typeof require !== 'undefined') {
                    clearInterval(checkRequire);
                    console.log('✓ AMD loader now available, initializing...');
                    doInitialize();
                }
            }, 100);

            setTimeout(function() {
                if (typeof require === 'undefined') {
                    clearInterval(checkRequire);
                    console.error('✗ AMD loader not available after timeout.');
                    console.error('Please ensure Moodle is fully loaded before using the AI Assistant.');
                }
            }, 5000);
            return;
        }

        doInitialize();
    }

    function doInitialize() {
        require(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
        console.log('✓ jQuery, Ajax, and Notification modules loaded');

        const status = {
            installed: config.installed === '1',
            enabled: config.enabled === '1',
            allowed: config.allowed === '1'
        };
        status.canUse = status.installed && status.enabled && status.allowed;

        console.log('Status:', status);
        console.log('Can Use AI:', status.canUse);

        setTimeout(function() {
            const panel = $('#rubricAiPanel');
            const messages = $('#rubricAiMessages');
            const statusBox = $('#rubricAiStatus');
            const chatInput = $('#rubricAiChatInput');
            const inputField = $('#rubricAiInput');
            const sendBtn = $('#rubricAiSendBtn');
            const quickBtns = $('.rubric-ai-quick-btn');
            const summaryBox = $('#rubricAiSummary');
            const generateBtn = $('#rubricGenerateBtn');

            let conversationHistory = [];
            let isGenerating = false;

            console.log('=== DIAGNOSTIC: Element Check ===');
            console.log('- Panel:', panel.length > 0 ? '✓ Found (ID: ' + panel.attr('id') + ')' : '✗ NOT FOUND');
            console.log('- Messages:', messages.length > 0 ? '✓ Found' : '✗ NOT FOUND');
            console.log('- Chat Input:', chatInput.length > 0 ? '✓ Found' : '✗ NOT FOUND');
            console.log('- Input Field:', inputField.length > 0 ? '✓ Found (ID: ' + inputField.attr('id') + ')' : '✗ NOT FOUND');
            console.log('- Send Button:', sendBtn.length > 0 ? '✓ Found (ID: ' + sendBtn.attr('id') + ')' : '✗ NOT FOUND');
            console.log('- Quick Buttons:', quickBtns.length > 0 ? '✓ Found (' + quickBtns.length + ' buttons)' : '✗ NOT FOUND');
            console.log('- Summary box:', summaryBox.length > 0 ? '✓ Found' : '✗ NOT FOUND');

            const rubricBuilder = $('#rubricBuilder');
            console.log('- Rubric Builder:', rubricBuilder.length > 0 ? '✓ Found' : '✗ NOT FOUND');
            console.log('- Rubric Builder Visible:', rubricBuilder.is(':visible') ? '✓ Visible' : '✗ Hidden');

            if (!panel.length) {
                console.error('✗ Rubric AI panel not found!');
                return;
            }

            if (!inputField.length) {
                console.error('✗ Input field not found!');
                return;
            }

            if (!sendBtn.length) {
                console.error('✗ Send button not found!');
                return;
            }

            console.log('=== All required elements found! ===');

            function escapeHtml(text) {
                return $('<div>').text(text || '').html();
            }

            function formatBotMessage(text) {
                let formatted = escapeHtml(text || '');
                formatted = formatted.replace(/\[\[collapse\|(.*?)\]\]([\s\S]*?)\[\[\/collapse\]\]/gi, function(match, title, content) {
                    const safeTitle = title.trim() || 'Details';
                    const safeContent = content.trim();
                    return `<details class="rubric-ai-help"><summary>${safeTitle}</summary><div class="rubric-ai-help-content">${safeContent}</div></details>`;
                });
                formatted = formatted.replace(/```json([\s\S]*?)```/gi, '<pre><code class="language-json">$1</code></pre>');
                formatted = formatted.replace(/```([\s\S]*?)```/gi, '<pre><code>$1</code></pre>');
                formatted = formatted.replace(/\n/g, '<br>');
                formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                formatted = formatted.replace(/\*(.*?)\*/g, '<em>$1</em>');
                return formatted;
            }

            function addMessage(sender, text) {
                if (!messages.length) return;

                const messageEl = $('<div>').addClass('rubric-ai-message').addClass(sender);
                const formattedText = sender === 'bot' ? formatBotMessage(text) : escapeHtml(text).replace(/\n/g, '<br>');
                messageEl.html(formattedText);
                messages.append(messageEl);
                scrollToBottom();
            }

            function addTypingIndicator() {
                const typingEl = $('<div>').addClass('rubric-ai-message bot typing');
                typingEl.html('<i class="fa fa-circle"></i><i class="fa fa-circle"></i><i class="fa fa-circle"></i>');
                messages.append(typingEl);
                scrollToBottom();
                return typingEl;
            }

            function removeTypingIndicator() {
                messages.find('.typing').remove();
            }

            function scrollToBottom() {
                const container = messages.get(0);
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            }

            function buildRubricContext() {
                if (typeof window.rubricData === 'undefined' || !Array.isArray(window.rubricData) || window.rubricData.length === 0) {
                    return 'No rubric criteria defined yet.';
                }
                const lines = ['Current rubric:'];
                window.rubricData.forEach((c, i) => {
                    lines.push(`${i + 1}. ${c.description || 'Untitled'} (${c.levels.length} levels)`);
                });
                return lines.join('\n');
            }

            function getRubricSystemPrompt(customCriteria = null, customLevels = null) {
                const rubricContext = buildRubricContext();
                const assignmentName = $('#assignmentName').val() || 'this assignment';
                const assignmentDescription = $('#assignmentDescription').val() || '';
                const activityInstructions = $('#activityInstructions').val() || '';
                const criteriaCount = $('#rubricCriteriaCount').val() || '4';
                const levelsCount = $('#rubricLevelsCount').val() || '4';
                const finalCriteria = customCriteria || criteriaCount;
                const finalLevels = customLevels || levelsCount;

                return `You are a rubric design assistant helping a teacher create rubrics in a Moodle assignment builder.

Assignment Context:
- Title: ${assignmentName}
- Description: ${assignmentDescription || 'Not provided'}
- Activity Instructions: ${activityInstructions || 'Not provided'}

Rubric Requirements:
- Number of Criteria (Rows): ${finalCriteria}
- Number of Performance Levels (Columns): ${finalLevels}

Current Rubric State:
${rubricContext}

Your role:
- Help create, improve, or suggest rubrics for Pre-K / early childhood learners (ages 4-5)
- ALWAYS use gentle, strengths-based language that encourages growth
- Avoid negative or harsh phrasing (e.g., "failing", "poor", "insufficient"); instead describe what support helps the child progress
- When asked to create a rubric, provide JSON in this exact format:
\`\`\`json
{"criteria":[{"description":"Criterion name","levels":[{"score":1,"definition":"Description"},{"score":2,"definition":"Description"}]}]}
\`\`\`
- You MUST provide exactly ${criteriaCount} criteria
- Each criterion MUST have exactly ${levelsCount} performance levels
- Use consecutive integer scores starting at 1
- Definitions should be clear, supportive, and no longer than 45 words
- Emphasize observable behaviors (e.g., "shares materials", "expresses ideas with pictures") suitable for Pre-K
- Always include the JSON code block when generating a rubric
- You can also provide general advice, suggest criteria, or help improve existing rubrics

Be conversational, helpful, and ask clarifying questions when needed.`;
            }

            function extractJsonBlock(text) {
                if (!text) return null;
                const match = text.match(/```json([\s\S]*?)```/i);
                if (match) return match[1].trim();
                const braceMatch = text.match(/\{[\s\S]*\}/);
                return braceMatch ? braceMatch[0] : null;
            }

            function parseFrameworkPayloadFromResponse(replyText) {
                if (!replyText || replyText.indexOf('ai-generated-framework') === -1) {
                    return null;
                }
                try {
                    const temp = document.createElement('div');
                    temp.innerHTML = replyText;
                    const frameworkBtn = temp.querySelector('.btn-add-framework[data-framework]');
                    if (!frameworkBtn) {
                        return null;
                    }
                    const payload = frameworkBtn.getAttribute('data-framework');
                    return payload ? JSON.parse(payload) : null;
                } catch (error) {
                    console.error('Failed to parse framework payload from AI response:', error);
                    return null;
                }
            }

            function getFrameworkScaleLevels(frameworkMeta) {
                if (frameworkMeta) {
                    const rawConfig = frameworkMeta.scaleconfiguration || frameworkMeta.scaleConfiguration;
                    if (rawConfig) {
                        try {
                            const parsedConfig = typeof rawConfig === 'string' ? JSON.parse(rawConfig) : rawConfig;
                            if (Array.isArray(parsedConfig) && parsedConfig.length) {
                                return parsedConfig.map(item => item.name || item.label || `Level ${item.id || ''}`.trim());
                            }
                        } catch (error) {
                            console.warn('Unable to parse framework scale configuration:', error);
                        }
                    }
                }
                return ['Emergent', 'Developing', 'Proficient', 'Exemplary'];
            }

            function buildLevelDefinition(levelName, competencyDescription) {
                const description = competencyDescription || 'this competency';
                const lowerLevel = (levelName || '').toLowerCase();
                const normalizedDesc = description.charAt(0).toLowerCase() + description.slice(1);

                if (/emergent|novice|beginner|foundation/.test(lowerLevel)) {
                    return `${levelName}: With warm guidance, beginning to ${normalizedDesc} in playful ways.`;
                }
                if (/developing|basic|progressing|growing/.test(lowerLevel)) {
                    return `${levelName}: Shows growing confidence and often ${normalizedDesc} with gentle reminders.`;
                }
                if (/proficient|competent|meets|satisfactory/.test(lowerLevel)) {
                    return `${levelName}: Consistently ${normalizedDesc} with minimal support and a positive attitude.`;
                }
                if (/exemplary|advanced|master|expert/.test(lowerLevel)) {
                    return `${levelName}: Joyfully leads and inspires peers while ${normalizedDesc}.`;
                }
                return `${levelName}: Demonstrates caring progress toward ${normalizedDesc}.`;
            }

            function persistRubricData(newData, counter) {
                if (!Array.isArray(newData) || newData.length === 0) {
                    return { applied: false, reason: 'No criteria generated', criteria: [] };
                }

                if (typeof updateGlobalRubricData === 'function') {
                    updateGlobalRubricData(newData, counter);
                    console.log('✓ Rubric data updated via updateGlobalRubricData function');
                } else {
                    window.rubricData = newData;
                    window.criterionCounter = counter;
                    console.log('✓ Rubric data updated via window (fallback)');
                }

                setTimeout(function() {
                    console.log('Attempting to render rubric table...');
                    if (typeof renderRubricTable === 'function') {
                        try {
                            renderRubricTable();
                            console.log('✓ Rubric table rendered successfully');
                        } catch (e) {
                            console.error('Error calling renderRubricTable:', e);
                        }
                    } else if (typeof window.renderRubricTable === 'function') {
                        try {
                            window.renderRubricTable();
                            console.log('✓ Rubric table rendered successfully (via window)');
                        } catch (e) {
                            console.error('Error calling window.renderRubricTable:', e);
                        }
                    } else {
                        console.error('✗ renderRubricTable function not found!');
                    }
                }, 100);

                return { applied: true, count: newData.length, criteria: newData };
            }

            function applyFrameworkPayloadAsRubric(payload) {
                const competencies = Array.isArray(payload?.competencies) ? payload.competencies : [];
                if (!competencies.length) {
                    return { applied: false, reason: 'Framework payload had no competencies', criteria: [] };
                }

                const scaleLevels = getFrameworkScaleLevels(payload.framework || {});
                let counter = window.criterionCounter || 0;
                const newData = [];

                competencies.forEach((comp, idx) => {
                    counter++;
                    const descriptionParts = [comp.shortname, comp.description].filter(Boolean);
                    const description = descriptionParts.length ? descriptionParts.join(' — ') : `Criterion ${idx + 1}`;
                    const compDesc = comp.description || comp.shortname || `criterion ${idx + 1}`;
                    const levels = scaleLevels.map((levelName, levelIdx) => ({
                        score: levelIdx + 1,
                        definition: buildLevelDefinition(levelName || `Level ${levelIdx + 1}`, compDesc)
                    }));
                    newData.push({
                        id: counter,
                        description,
                        levels
                    });
                });

                return persistRubricData(newData, counter);
            }

            function applyRubric(replyText, desiredCriteria = null, desiredLevels = null) {
                console.log('=== applyRubric called ===');
                console.log('Reply text length:', replyText ? replyText.length : 0);

                if (!replyText) {
                    console.warn('No reply text provided');
                    return { applied: false, reason: 'No reply text', criteria: [] };
                }

                const jsonStr = extractJsonBlock(replyText);
                console.log('Extracted JSON:', jsonStr ? 'Found' : 'Not found');

                if (!jsonStr) {
                    console.warn('No JSON block found in response');
                    const frameworkPayload = parseFrameworkPayloadFromResponse(replyText);
                    if (frameworkPayload) {
                        return applyFrameworkPayloadAsRubric(frameworkPayload);
                    }
                    return { applied: false, reason: 'No JSON found in response. The AI should provide a JSON code block with rubric data.', criteria: [] };
                }

                try {
                    const parsed = JSON.parse(jsonStr);
                    console.log('JSON parsed successfully');
                    console.log('Parsed data:', parsed);

                    if (!parsed.criteria || parsed.criteria.length === 0) {
                        console.warn('No criteria in parsed JSON');
                        return { applied: false, reason: 'No criteria in JSON', criteria: [] };
                    }

                    console.log('Found', parsed.criteria.length, 'criteria');

                    const newData = [];
                    let counter = window.criterionCounter || 0;
                    console.log('Starting counter:', counter);

                    const targetCriteria = desiredCriteria ? parseInt(desiredCriteria, 10) : parsed.criteria.length;
                    const targetLevels = desiredLevels ? parseInt(desiredLevels, 10) : null;

                    parsed.criteria.slice(0, targetCriteria).forEach((c, idx) => {
                        counter++;
                        let levels = (c.levels || []).map((l, i) => ({
                            score: Number(l.score) || (i + 1),
                            definition: (l.definition || l.description || '').trim()
                        }));
                        if (targetLevels) {
                            levels = levels.slice(0, targetLevels);
                            while (levels.length < targetLevels) {
                                levels.push({ score: levels.length + 1, definition: '' });
                            }
                        }
                        const criterion = {
                            id: counter,
                            description: (c.description || c.title || '').trim(),
                            levels: levels.length > 0 ? levels : [{ score: 1, definition: '' }]
                        };

                        console.log('Criterion', idx + 1, ':', criterion.description, '-', levels.length, 'levels');
                        newData.push(criterion);
                    });

                    return persistRubricData(newData, counter);
                } catch (e) {
                    console.error('=== JSON Parse Error ===');
                    console.error('Error:', e);
                    console.error('Error message:', e.message);
                    console.error('JSON string:', jsonStr);
                    return { applied: false, reason: 'Invalid JSON: ' + e.message, criteria: [] };
                }
            }

            function sendMessage(userMessage, displayText, overrideCriteria = null, overrideLevels = null) {
                console.log('=== sendMessage called ===');
                console.log('User message:', userMessage);
                console.log('Status canUse:', status.canUse);
                console.log('Is generating:', isGenerating);

                if (!status.canUse) {
                    console.error('AI Assistant not available');
                    Notification.alert('AI Assistant', statusBox.text(), 'info');
                    return;
                }

                if (isGenerating) {
                    console.warn('Already generating, ignoring request');
                    return;
                }

                if (!userMessage || !userMessage.trim()) {
                    console.warn('Empty message, not sending');
                    return;
                }

                isGenerating = true;
                sendBtn.prop('disabled', true);
                console.log('Send button disabled');

                const messageForDisplay = (typeof displayText === 'string' && displayText.length > 0)
                    ? displayText
                    : userMessage;
                addMessage('user', messageForDisplay);
                conversationHistory.push({ role: 'user', content: messageForDisplay });
                console.log('User message added to chat');

                const typingEl = addTypingIndicator();
                console.log('Typing indicator added');

                const systemPrompt = getRubricSystemPrompt(overrideCriteria, overrideLevels);
                console.log('System prompt built, length:', systemPrompt.length);

                console.log('Calling AI service...');
                Ajax.call([{
                    methodname: 'local_aiassistant_send_message',
                    args: {
                        message: userMessage,
                        context: systemPrompt
                    },
                    done: function(response) {
                        console.log('=== AI Response Received ===');
                        console.log('Response:', response);
                        removeTypingIndicator();
                        sendBtn.prop('disabled', false);
                        isGenerating = false;

                        if (response && response.success) {
                            const reply = response.reply || '';
                            console.log('AI Reply length:', reply ? reply.length : 0);
                            const hasStructuredRubric = /```json[\s\S]*```/i.test(reply) || reply.indexOf('ai-generated-framework') !== -1;
                            const addAssistantMessage = (text) => {
                                addMessage('bot', text);
                                conversationHistory.push({ role: 'assistant', content: text });
                            };
                            if (!hasStructuredRubric) {
                                addAssistantMessage(reply);
                            }

                            console.log('Checking for rubric JSON in response...');
                            const result = applyRubric(reply, overrideCriteria, overrideLevels);
                            console.log('Apply rubric result:', result);

                            if (result.applied) {
                                if (hasStructuredRubric) {
                                    addAssistantMessage('✅ Rubric generated and applied automatically. Check the Rubric tab for details.');
                                }
                                console.log('✅ Rubric applied successfully!', result.count, 'criteria added');
                                const targetTab = document.querySelector(`.tab-button[onclick*="grade"]`);
                                const gradePanel = document.getElementById('grade-tab');
                                if (targetTab && gradePanel) {
                                    setTimeout(() => {
                                        showTab('grade');
                                    }, 150);
                                }
                            } else {
                                console.log('ℹ Rubric not applied:', result.reason);
                                if (hasStructuredRubric) {
                                    addAssistantMessage(reply || 'AI response received, but no rubric could be applied. Please review and try again.');
                                }
                            }
                        } else {
                            console.error('AI response failed:', response);
                            addMessage('bot', '⚠️ ' + (response ? response.reply : 'No response from AI Assistant'));
                        }
                    },
                    fail: function(error) {
                        console.error('=== AI Service Error ===');
                        console.error('Error object:', error);
                        console.error('Error message:', error ? error.message : 'Unknown error');
                        removeTypingIndicator();
                        sendBtn.prop('disabled', false);
                        isGenerating = false;

                        addMessage('bot', '⚠️ Connection error. Please try again. Check console for details.');
                    }
                }]);
            }

            function updateRubricAssignmentSummary() {
                const values = {
                    name: ($('#assignmentName').val() || '').trim(),
                    description: ($('#assignmentDescription').val() || '').trim(),
                    instructions: ($('#activityInstructions').val() || '').trim()
                };

                const targets = [
                    { key: 'name', selector: '#rubricAiAssignmentName', emptyClass: 'rubric-ai-assignment-summary-empty' },
                    { key: 'description', selector: '#rubricAiAssignmentDescription', emptyClass: 'rubric-ai-assignment-summary-empty' },
                    { key: 'instructions', selector: '#rubricAiActivityInstructions', emptyClass: 'rubric-ai-assignment-summary-empty' },
                    { key: 'name', selector: '#rubricAssignmentNameValue', emptyClass: 'empty' },
                    { key: 'description', selector: '#rubricAssignmentDescriptionValue', emptyClass: 'empty' },
                    { key: 'instructions', selector: '#rubricAssignmentInstructionsValue', emptyClass: 'empty' }
                ];

                targets.forEach(target => {
                    const el = $(target.selector);
                    if (!el.length) {
                        return;
                    }
                    const textValue = values[target.key];
                    if (textValue) {
                        const safeHtml = $('<div>').text(textValue).html().replace(/\n/g, '<br>');
                        el.html(safeHtml);
                        el.removeClass(target.emptyClass);
                    } else {
                        el.text('Not provided yet.');
                        el.addClass(target.emptyClass);
                    }
                });
            }
            window.updateRubricAssignmentSummary = updateRubricAssignmentSummary;

            function initializeAssistantUI() {
                console.log('=== Initializing Rubric AI panel ===');
                updateRubricAssignmentSummary();

                if (status.canUse) {
                    if (conversationHistory.length === 0 && messages.children().length === 0) {
                        messages.empty();
                        addMessage('bot', '[[collapse|Need help getting started?]]Hello! I\'m your Rubric AI Assistant.\n\n📋 **To get started:**\n1. Select the number of criteria (rows) and performance levels (columns)\n2. Click "Generate Rubric" to create a rubric based on your assignment details\n\nI can also help you:\n• Suggest criteria and improvements\n• Answer questions about rubric design\n• Refine your existing rubric[[/collapse]]');
                    }
                    chatInput.show();
                    setTimeout(function() {
                        inputField.focus();
                    }, 150);
                } else {
                    messages.empty();
                    addMessage('bot', statusBox.text());
                    chatInput.hide();
                }
                summaryBox.hide().empty();
            }

            if (sendBtn.length) {
                sendBtn.off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Send button clicked!');
                    const message = inputField.val().trim();
                    console.log('Message:', message);
                    if (message) {
                        inputField.val('');
                        sendMessage(message);
                    } else {
                        console.warn('Empty message, not sending');
                    }
                });
                console.log('✓ Send button handler attached');
            } else {
                console.error('✗ Cannot attach Send button handler - button not found');
            }

            if (inputField.length) {
                inputField.off('keydown').on('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Enter key pressed in input field');
                        const message = inputField.val().trim();
                        if (message) {
                            inputField.val('');
                            sendMessage(message);
                        }
                    }
                });

                inputField.off('input').on('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                });
                console.log('✓ Input field handlers attached');
            } else {
                console.error('✗ Cannot attach Input field handlers - field not found');
            }

            if (quickBtns.length) {
                quickBtns.off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const $btn = $(this);
                    const prompt = $btn.data('prompt');
                    console.log('Quick button clicked:', prompt);
                    if (prompt) {
                        inputField.val(prompt);
                        sendMessage(prompt);
                    } else {
                        console.warn('No prompt data found on button');
                    }
                });
                console.log('✓ Quick buttons handlers attached (' + quickBtns.length + ' buttons)');
            } else {
                console.error('✗ Cannot attach Quick buttons handlers - buttons not found');
            }

            if (generateBtn.length) {
                generateBtn.off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Generate Rubric button clicked');

                    const assignmentName = $('#assignmentName').val() || '';
                    const assignmentDescription = $('#assignmentDescription').val() || '';
                    const activityInstructions = $('#activityInstructions').val() || '';
                    if (!assignmentName.trim() && !assignmentDescription.trim() && !activityInstructions.trim()) {
                        addMessage('bot', '⚠️ Please fill in at least the Assignment Name, Description, or Activity Instructions to generate a relevant rubric.');
                        return;
                    }

                    const safeName = assignmentName.trim() || 'Current Assignment';

                    let autoPrompt = `Use the assignment context below to produce a Pre-K friendly rubric JSON (4 criteria, each with 4 performance levels). Return JSON only and keep tone nurturing.\n\n`;
                    const criteriaCount = $('#rubricCriteriaCount').val() || '4';
                    const levelsCount = $('#rubricLevelsCount').val() || '4';
                    autoPrompt += `Assignment Name: ${safeName}\n`;
                    if (assignmentDescription.trim()) {
                        autoPrompt += `Assignment Description: ${assignmentDescription.trim()}\n`;
                    }
                    if (activityInstructions.trim()) {
                        autoPrompt += `Activity Instructions: ${activityInstructions.trim()}\n`;
                    }

                    autoPrompt += `\nEach criterion should include ${levelsCount} clearly differentiated levels with integer scores starting at 1 and increasing by 1. Keep descriptions encouraging and age-appropriate for 4-5 year olds.`;

                    sendMessage(autoPrompt, `Generate rubric for "${safeName}" using ${criteriaCount} criteria and ${levelsCount} levels.`, criteriaCount, levelsCount);
                });
                console.log('✓ Generate Rubric button handler attached');
            } else {
                console.error('✗ Cannot attach Generate Rubric button handler - button not found');
            }

            initializeAssistantUI();

            console.log('=== Testing assistant controls ===');
            console.log('Send Button is clickable:', sendBtn.is(':visible') && !sendBtn.prop('disabled'));
            console.log('Quick Buttons clickable:', quickBtns.filter(':visible').length);

            if (typeof window.rubricData === 'undefined') {
                window.rubricData = [];
                window.criterionCounter = 0;
                console.log('✓ Rubric data initialized');
            } else {
                console.log('✓ Rubric data already exists:', window.rubricData.length, 'criteria');
            }

            const gradingMethod = $('#gradingMethod');
            if (gradingMethod.length) {
                gradingMethod.on('change', function() {
                    if ($(this).val() === 'rubric') {
                        setTimeout(initializeAssistantUI, 150);
                    }
                });
            }

            console.log('=== Rubric AI Assistant: ✓ Ready! ===');
            console.log('=== You can now use the AI Assistant to create rubrics ===');
        }, 500);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initializeRubricAi, 500);
        });
    } else {
        setTimeout(initializeRubricAi, 500);
    }
})();
</script>

