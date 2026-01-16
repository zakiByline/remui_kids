/**
 * Rubric AI Assistant Module
 *
 * @module     theme_remui_kids/rubric_ai
 * @package    theme_remui_kids
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    return {
        /**
         * Initialize the Rubric AI Assistant
         *
         * @param {Object} config Configuration with installed, enabled, allowed flags
         */
        init: function(config) {
            console.log('Rubric AI Assistant: Initializing with config', config);

            const status = {
                installed: config.installed === '1' || config.installed === 1,
                enabled: config.enabled === '1' || config.enabled === 1,
                allowed: config.allowed === '1' || config.allowed === 1
            };
            status.canUse = status.installed && status.enabled && status.allowed;

            console.log('Rubric AI Assistant: Status', status);

            const modal = $('#rubricAiModal');
            const aiButton = $('#rubricAiButton');
            const closeBtn = $('#rubricAiClose');
            const sendBtn = $('#rubricAiSend');
            const input = $('#rubricAiInput');
            const messages = $('#rubricAiMessages');
            const statusBox = $('#rubricAiStatus');

            if (!aiButton.length) {
                console.error('Rubric AI Assistant: Button not found');
                return;
            }

            /**
             * Open the modal
             */
            function openModal() {
                console.log('Rubric AI Assistant: Opening modal');
                modal.addClass('open');
                if (status.canUse) {
                    if (!messages.children().length) {
                        appendMessage('bot', '<strong>Welcome to Rubric AI Assistant!</strong><br><br>I can help you create assessment rubrics. Just tell me:<br>• What subject or topic<br>• Grade level<br>• Number of criteria you need<br>• Assessment focus (e.g., "essay writing", "science project", "math problem solving")<br><br>I\'ll generate complete rubric criteria with multiple performance levels and scoring.');
                    }
                    input.trigger('focus');
                }
            }

            /**
             * Close the modal
             */
            function closeModal() {
                console.log('Rubric AI Assistant: Closing modal');
                modal.removeClass('open');
            }

            /**
             * Format a message for display
             *
             * @param {string} text - Message text
             * @returns {string} Formatted HTML
             */
            function formatMessage(text) {
                return $('<div>').text(text).html().replace(/\n/g, '<br>');
            }

            /**
             * Append a message to the chat
             *
             * @param {string} type - 'user' or 'bot'
             * @param {string} text - Message text
             * @returns {jQuery} The message element
             */
            function appendMessage(type, text) {
                const message = $('<div>').addClass('rubric-ai-message').addClass(type);
                message.html(formatMessage(text));
                messages.append(message);
                if (messages.length) {
                    messages.scrollTop(messages[0].scrollHeight);
                }
                return message;
            }

            /**
             * Build rubric context from current rubricData
             *
             * @returns {string} Context string
             */
            function buildRubricContext() {
                if (typeof window.rubricData === 'undefined' || !Array.isArray(window.rubricData) || window.rubricData.length === 0) {
                    return 'No rubric criteria have been defined yet.';
                }

                const lines = ['Rubric definition with criteria and levels:'];

                window.rubricData.forEach((criterion, criterionIndex) => {
                    const title = criterion.description && criterion.description.trim().length > 0
                        ? criterion.description.trim()
                        : 'No description provided';
                    lines.push(`Criterion ${criterionIndex + 1}: ${title}`);

                    if (Array.isArray(criterion.levels) && criterion.levels.length > 0) {
                        criterion.levels.forEach((level, levelIndex) => {
                            const score = typeof level.score !== 'undefined' ? level.score : '';
                            const definition = (level.definition || '').trim();
                            lines.push(`  Level ${levelIndex + 1} -> Score: ${score}; Description: ${definition || 'No description provided'}`);
                        });
                    } else {
                        lines.push('  (No levels defined for this criterion)');
                    }
                });

                return lines.join('\n');
            }

            /**
             * Extract JSON rubric block from AI response
             *
             * @param {string} text - AI response text
             * @returns {string|null} JSON string or null
             */
            function extractRubricJsonBlock(text) {
                if (!text) {
                    return null;
                }
                const fencedMatch = text.match(/```json([\s\S]*?)```/i);
                if (fencedMatch && fencedMatch[1]) {
                    return fencedMatch[1].trim();
                }
                const braceMatch = text.match(/\{[\s\S]*\}/);
                return braceMatch ? braceMatch[0] : null;
            }

            /**
             * Apply rubric from AI response
             *
             * @param {string} replyText - AI response text
             * @returns {Object} Result with applied flag and optional reason/criteriaCount
             */
            function applyRubricFromAIResponse(replyText) {
                const jsonBlock = extractRubricJsonBlock(replyText);
                if (!jsonBlock) {
                    return { applied: false, reason: 'No JSON rubric block detected.' };
                }

                let parsed;
                try {
                    parsed = JSON.parse(jsonBlock);
                } catch (error) {
                    console.error('Rubric AI: JSON parse error', error);
                    return { applied: false, reason: 'Unable to parse rubric JSON.' };
                }

                if (!parsed || !Array.isArray(parsed.criteria) || parsed.criteria.length === 0) {
                    return { applied: false, reason: 'JSON rubric did not include criteria.' };
                }

                const newRubricData = [];
                let newCounter = 0;

                parsed.criteria.forEach((criterion) => {
                    const description = (criterion.description || criterion.title || '').toString().trim();
                    const levels = Array.isArray(criterion.levels) ? criterion.levels : [];

                    const formattedLevels = levels.length > 0 ? levels.map((level, levelIndex) => {
                        let score = Number(level.score);
                        if (Number.isNaN(score)) {
                            score = levelIndex + 1;
                        }
                        const definition = (level.definition || level.description || '').toString().trim();
                        return {
                            score,
                            definition
                        };
                    }) : [{
                        score: 1,
                        definition: ''
                    }];

                    newCounter++;
                    newRubricData.push({
                        id: newCounter,
                        description: description,
                        levels: formattedLevels
                    });
                });

                if (newRubricData.length === 0) {
                    return { applied: false, reason: 'Parsed rubric did not contain usable criteria.' };
                }

                // Update global rubric data
                if (typeof window.criterionCounter !== 'undefined') {
                    window.criterionCounter = newCounter;
                }
                window.rubricData = newRubricData;

                // Re-render the rubric table
                if (typeof window.renderRubricTable === 'function') {
                    window.renderRubricTable();
                }

                return { applied: true, criteriaCount: newRubricData.length };
            }

            /**
             * Send a message to the AI
             */
            function sendMessage() {
                if (!status.canUse) {
                    Notification.alert('AI Assistant', statusBox.text(), 'info');
                    return;
                }

                const message = input.val().trim();
                if (!message) {
                    input.focus();
                    return;
                }

                appendMessage('user', message);
                input.val('');

                const typing = appendMessage('bot', 'Thinking...');
                typing.addClass('typing');

                const rubricContext = buildRubricContext();
                const payload = [
                    'You are a rubric design expert helping a teacher create assessment rubrics.',
                    '',
                    'CURRENT RUBRIC STATE:',
                    rubricContext,
                    '',
                    'TEACHER REQUEST:',
                    message,
                    '',
                    'INSTRUCTIONS:',
                    '1. Analyze the teacher\'s request and current rubric',
                    '2. Provide a friendly explanation of your suggestions',
                    '3. MOST IMPORTANTLY: Include a complete rubric in this EXACT JSON format:',
                    '',
                    '```json',
                    '{',
                    '  "criteria": [',
                    '    {',
                    '      "description": "Clear criterion name (e.g., Content Quality)",',
                    '      "levels": [',
                    '        { "score": 1, "definition": "Does not meet expectations - detailed description" },',
                    '        { "score": 2, "definition": "Approaching expectations - detailed description" },',
                    '        { "score": 3, "definition": "Meets expectations - detailed description" },',
                    '        { "score": 4, "definition": "Exceeds expectations - detailed description" }',
                    '      ]',
                    '    }',
                    '  ]',
                    '}',
                    '```',
                    '',
                    '4. Include 3-5 criteria',
                    '5. Each criterion should have 3-5 performance levels with clear descriptions',
                    '6. Score values should increase from lowest to highest performance',
                    '7. Make descriptions specific and observable',
                    '',
                    'Remember: The JSON block is REQUIRED and will be automatically applied to the rubric builder.'
                ].join('\n');

                console.log('Rubric AI Assistant: Sending message', { message, context: rubricContext });

                Ajax.call([{
                    methodname: 'local_aiassistant_send_message',
                    args: { message: message, context: payload },
                    done: function(response) {
                        console.log('Rubric AI Assistant: Received response', response);
                        typing.removeClass('typing');
                        let replyText = '';
                        if (response && response.success) {
                            replyText = response.reply || '';
                        } else if (response && response.reply) {
                            replyText = '⚠ ' + response.reply;
                        } else {
                            replyText = '⚠ No response received from the assistant.';
                        }

                        typing.html(formatMessage(replyText));

                        const result = applyRubricFromAIResponse(replyText);
                        console.log('Rubric AI Assistant: Apply result', result);
                        if (result.applied) {
                            typing.append('<div class="rubric-ai-note">✅ Rubric suggestions applied successfully! The rubric builder above has been updated with ' + result.criteriaCount + ' criteria. You can edit them as needed.</div>');
                        } else if (status.canUse) {
                            typing.append(`<div class="rubric-ai-note">ℹ ${result.reason || 'Ask the assistant to provide a JSON rubric block so it can be applied automatically.'}</div>`);
                        }
                    },
                    fail: function(error) {
                        console.error('Rubric AI Assistant: Error', error);
                        typing.removeClass('typing');
                        typing.html(formatMessage('⚠ Unable to reach the AI Assistant right now. Please try again later.'));
                        if (error && error.message) {
                            Notification.alert('AI Assistant', error.message, 'error');
                        }
                    }
                }]);
            }

            // Event handlers
            aiButton.on('click', function(e) {
                console.log('Rubric AI Assistant: Button clicked');
                e.preventDefault();
                openModal();
            });

            closeBtn.on('click', function(e) {
                console.log('Rubric AI Assistant: Close clicked');
                e.preventDefault();
                closeModal();
            });

            modal.on('click', function(event) {
                if ($(event.target).is(modal)) {
                    console.log('Rubric AI Assistant: Modal backdrop clicked');
                    closeModal();
                }
            });

            sendBtn.on('click', function(e) {
                console.log('Rubric AI Assistant: Send clicked');
                e.preventDefault();
                sendMessage();
            });

            input.on('keydown', function(event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    console.log('Rubric AI Assistant: Enter pressed');
                    sendMessage();
                }
            });

            $(document).on('keydown', function(event) {
                if (event.key === 'Escape' && modal.hasClass('open')) {
                    console.log('Rubric AI Assistant: Escape pressed');
                    closeModal();
                }
            });

            console.log('Rubric AI Assistant: Initialization complete!');
        }
    };
});








