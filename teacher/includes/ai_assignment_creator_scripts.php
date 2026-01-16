<?php
/**
 * Script partial for AI assignment creator behaviours.
 */
?>
<script>
// Toggle AI Assignment Creator
function toggleAiCreator() {
    const content = document.getElementById('aiCreatorContent');
    const toggle = document.getElementById('aiCreatorToggle');
    if (!content || !toggle) {
        return;
    }

    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        toggle.classList.add('open');
    } else {
        content.style.display = 'none';
        toggle.classList.remove('open');
    }
}

// Make header clickable too
document.addEventListener('DOMContentLoaded', function() {
    const header = document.querySelector('.ai-creator-header');
    if (header) {
        header.addEventListener('click', function(e) {
            if (!e.target.closest('.ai-creator-toggle')) {
                toggleAiCreator();
            }
        });
    }

    watchLessonModuleSelection();
});

// Watch for changes in lesson/module selection and update AI suggestions
function watchLessonModuleSelection() {
    const selectionTextElement = document.getElementById('selectionText');

    if (!selectionTextElement) {
        return;
    }

    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' || mutation.type === 'characterData') {
                updateAISuggestions();
            }
        });
    });

    observer.observe(selectionTextElement, {
        childList: true,
        characterData: true,
        subtree: true
    });

    const selectedSectionInput = document.getElementById('selectedSection');
    const selectedModuleInput = document.getElementById('selectedModule');

    if (selectedSectionInput) {
        selectedSectionInput.addEventListener('change', updateAISuggestions);
    }
    if (selectedModuleInput) {
        selectedModuleInput.addEventListener('change', updateAISuggestions);
    }
}

// Update AI suggestions based on selected lesson/module
function updateAISuggestions() {
    const selectionText = document.getElementById('selectionText')?.textContent || '';
    const suggestionsContainer = document.getElementById('aiSuggestionsContainer');
    const suggestionsList = document.getElementById('aiSuggestionsList');
    const suggestionContext = document.getElementById('aiSuggestionContext');

    if (!selectionText || selectionText.trim() === '') {
        if (suggestionsContainer) {
            suggestionsContainer.style.display = 'none';
        }
        return;
    }

    const contextName = selectionText;

    generateAISuggestions(contextName).then(suggestions => {
        if (suggestions && suggestions.length > 0) {
            if (suggestionContext) {
                suggestionContext.textContent = contextName;
            }

            if (suggestionsList) {
                suggestionsList.innerHTML = '';

                suggestions.forEach(suggestion => {
                    const suggestionItem = document.createElement('div');
                    suggestionItem.className = 'ai-suggestion-item';
                    suggestionItem.innerHTML = `<i class="fa fa-lightbulb"></i><span>${suggestion}</span>`;
                    suggestionItem.onclick = function() {
                        selectAISuggestion(suggestion);
                    };
                    suggestionsList.appendChild(suggestionItem);
                });
            }

            if (suggestionsContainer) {
                suggestionsContainer.style.display = 'block';
            }
        }
    });
}

// Generate AI suggestions based on lesson/module name
async function generateAISuggestions(contextName) {
    return new Promise((resolve) => {
        const prompt = `For the lesson/module: "${contextName}"

Generate exactly 6 specific assignment topics that students can complete. Each topic should be:
- Directly related to "${contextName}"
- A complete assignment title (3-7 words)
- Actionable and clear
- Suitable for students

Format: Provide ONLY the 6 assignment titles, one per line, with no numbers, bullets, greetings, or explanations.

Example format:
Mouse Movement Practice
Click and Drag Exercise
Double-Click Speed Test
Right-Click Menu Exploration
Scroll Wheel Navigation
Mouse Accuracy Challenge`;

        require(['core/ajax'], function(Ajax) {
            Ajax.call([{
                methodname: 'local_aiassistant_send_message',
                args: {
                    message: prompt,
                    context: 'You are an assignment topic generator. Return ONLY assignment titles, nothing else. No greetings, no explanations, no numbering.'
                },
                done: function(response) {
                    if (response && response.success && response.reply) {
                        let suggestions = response.reply
                            .split('\n')
                            .map(s => s.trim())
                            .filter(s => s.length > 0)
                            .filter(s => !s.match(/^(hello|hi|sure|certainly|i can|here are|of course)/i))
                            .filter(s => s.split(' ').length <= 10)
                            .map(s => s.replace(/^[\d\.\-\*\#]+\s*/, ''))
                            .map(s => s.replace(/^\**|\**$/g, ''))
                            .map(s => s.replace(/^\_+|\_+$/g, ''))
                            .map(s => s.replace(/^["']|["']$/g, ''))
                            .filter(s => !s.match(/^(assignment|topics?|suggestions?|here|module|lesson)[\s:]/i))
                            .map(s => s.replace(/^#+\s*/, ''))
                            .filter(s => s.length > 5)
                            .slice(0, 6);
                        resolve(suggestions);
                    } else {
                        resolve([]);
                    }
                },
                fail: function(error) {
                    console.error('Failed to generate AI suggestions:', error);
                    resolve([]);
                }
            }]);
        });
    });
}

// Handle clicking on a suggestion
function selectAISuggestion(suggestion) {
    const topicInput = document.getElementById('aiTopicInput');
    if (topicInput) {
        topicInput.value = suggestion;
        topicInput.focus();
        topicInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        topicInput.style.background = '#e0f2fe';
        setTimeout(() => {
            topicInput.style.background = '';
        }, 1000);
    }
}

// Generate Assignment with AI
function generateAssignmentWithAI() {
    const topicInput = document.getElementById('aiTopicInput');
    const topic = topicInput.value.trim();
    const generateBtn = document.getElementById('btnGenerateAssignment');
    const statusDiv = document.getElementById('aiGenerationStatus');

    if (!topic) {
        showNotification('Please enter an assignment topic first.', 'error');
        topicInput.focus();
        return;
    }

    statusDiv.style.display = 'flex';
    generateBtn.disabled = true;

    const prompt = `Create a comprehensive educational assignment for the topic: "${topic}".

Submission constraints:
- The assignment must be completable through ONLY these submission types: Online Text entry inside the LMS and/or uploading a digital file.
- Do NOT rely on physical hand-ins, quizzes, forums, or any other tool.
- Explicitly state in the instructions whether students should provide an in-platform written response, upload a file, or do both.

Please provide:
1. A clear and concise assignment title/name
2. A detailed description (2-3 paragraphs) explaining what the assignment is about, its learning objectives, and what students will achieve
3. Step-by-step activity instructions for students to complete the assignment that follow the submission constraints above

Format your response as:
TITLE: [assignment title]
DESCRIPTION: [detailed description]
INSTRUCTIONS: [step-by-step instructions]`;

    const systemPrompt = 'You are an educational assignment creator. Create clear, engaging, and well-structured assignments suitable for students. Be specific and practical in your instructions.';

    require(['core/ajax'], function(Ajax) {
        Ajax.call([{
            methodname: 'local_aiassistant_send_message',
            args: {
                message: prompt,
                context: systemPrompt
            },
            done: function(response) {
                if (response && response.success && response.reply) {
                    parseAndFillAssignmentDetails(response.reply);
                    statusDiv.style.display = 'none';
                    generateBtn.disabled = false;
                    showNotification('Assignment details generated successfully! Review and adjust as needed.', 'success');
                    topicInput.value = '';
                } else {
                    statusDiv.style.display = 'none';
                    generateBtn.disabled = false;
                    // Provide more specific error message
                    let errorMsg = 'Could not generate assignment details.';
                    if (response && response.reply) {
                        errorMsg += ' ' + response.reply;
                    } else if (response && response.error) {
                        errorMsg += ' Error: ' + response.error;
                    }
                    errorMsg += ' Please try again or fill in manually.';
                    showNotification(errorMsg, 'error');
                }
            },
            fail: function(error) {
                console.error('AI Generation Error:', error);
                statusDiv.style.display = 'none';
                generateBtn.disabled = false;
                
                // Provide more detailed error message
                let errorMsg = 'Error connecting to AI service.';
                if (error && error.errorcode) {
                    if (error.errorcode === 'nopermission') {
                        errorMsg = 'You do not have permission to use AI Assistant. Please contact your administrator.';
                    } else if (error.errorcode === 'invalidparameter') {
                        errorMsg = 'Invalid request. Please try again.';
                    } else if (error.message) {
                        errorMsg += ' ' + error.message;
                    }
                } else if (error && typeof error === 'string') {
                    errorMsg += ' ' + error;
                }
                errorMsg += ' Please try again or fill in manually.';
                showNotification(errorMsg, 'error');
            }
        }]);
    });
}

// Parse AI response and fill form fields
function parseAndFillAssignmentDetails(aiResponse) {
    if (!aiResponse || typeof aiResponse !== 'string') {
        showNotification('AI response was empty. Please try generating again.', 'error');
        return;
    }

    const normalizedResponse = aiResponse.replace(/\r\n/g, '\n').trim();

    function extractSection(text, label, stopLabels = []) {
        if (!text) {
            return '';
        }
        const stopPattern = stopLabels.length
            ? `(?=\\n\\s*(?:${stopLabels.join('|')})\\s*[:\\-]|$)`
            : `$`;
        const regex = new RegExp(`${label}\\s*[:\\-]\\s*(.+?)${stopPattern}`, 'is');
        const match = text.match(regex);
        return match && match[1] ? match[1].trim() : '';
    }

    function cleanAndFormatText(text) {
        if (!text) return '';
        text = text.replace(/\*\*(.*?)\*\*/g, '$1');
        text = text.replace(/__(.*?)__/g, '$1');
        text = text.replace(/\*(.*?)\*/g, '$1');
        text = text.replace(/_(.*?)_/g, '$1');
        text = text.replace(/^#{1,6}\s+/gm, '');
        text = text.replace(/^[\*\-\+]\s+/gm, '• ');
        text = text.replace(/^\d+\.\s+/gm, '');
        text = text.replace(/\n{3,}/g, '\n\n');
        text = text.replace(/[ \t]+/g, ' ');
        return text.trim();
    }

    function removeGenericClosings(text) {
        if (!text) return '';
        const lines = text.split('\n');
        const filtered = lines.filter(line => {
            const trimmed = line.trim().toLowerCase();
            if (!trimmed) {
                return true;
            }
            const genericPatterns = [
                /^i hope\b/,
                /^please let me know\b/,
                /^feel free\b/,
                /^reach out\b/,
                /^thank you\b/,
                /^thanks\b/,
                /^best regards\b/,
                /^if you need\b/,
                /^should you need\b/
            ];
            return !genericPatterns.some(pattern => pattern.test(trimmed));
        });
        return filtered.join('\n').trim();
    }

    function formatInstructions(text) {
        if (!text) return '';
        
        // Remove summary sections and teacher suggestions (everything after "---")
        const summaryMatch = text.match(/---[\s\S]*$/);
        if (summaryMatch) {
            text = text.substring(0, text.indexOf('---'));
        }
        
        // Remove blockquote markers but keep content
        text = text.replace(/^>\s*/gm, '');
        
        // Remove asterisk markers from summary items that might have leaked through
        text = text.replace(/^>\s*\*\s*/gm, '');
        
        text = removeGenericClosings(text);
        
        const lines = text.split('\n');
        const formatted = [];
        let inList = false;
        let listLevel = 0;
        let currentSection = null;
        
        lines.forEach((line, index) => {
            const originalLine = line;
            const trimmed = line.trim();
            
            if (!trimmed) {
                if (inList && index < lines.length - 1) {
                    // Only add blank line if not at end
                    const nextLine = lines[index + 1]?.trim();
                    if (nextLine && !nextLine.match(/^[-•*>\d]/)) {
                        formatted.push('');
                        inList = false;
                        listLevel = 0;
                    }
                }
                return;
            }
            
            // Remove any leading > markers
            let cleanLine = trimmed.replace(/^>\s*/, '');
            
            // Detect section headers (lines ending with colon, usually capitalized)
            const sectionMatch = cleanLine.match(/^([A-Z][^:]*):\s*$/);
            if (sectionMatch) {
                if (inList) {
                    formatted.push('');
                    inList = false;
                    listLevel = 0;
                }
                currentSection = sectionMatch[1];
                formatted.push(cleanLine);
                return;
            }
            
            // Detect list items with various formats
            const bulletMatch = cleanLine.match(/^[-•*]\s+(.+)$/);
            const numberedMatch = cleanLine.match(/^(\d+)[\.\)]\s+(.+)$/);
            const subBulletMatch = originalLine.match(/^(\s{2,})[-•*]\s+(.+)$/);
            const blockquoteBullet = cleanLine.match(/^>\s*[-•*]\s+(.+)$/);
            
            if (subBulletMatch) {
                // Nested list item (indented)
                const indent = subBulletMatch[1].length;
                const content = subBulletMatch[2].trim();
                const level = Math.floor(indent / 2);
                
                if (!inList || listLevel !== level) {
                    if (inList && listLevel < level) {
                        // Starting nested list
                    } else if (inList) {
                        formatted.push('');
                    }
                    inList = true;
                    listLevel = level;
                }
                formatted.push('  '.repeat(level) + '- ' + content);
            } else if (blockquoteBullet) {
                // Bullet point from blockquote format
                if (!inList || listLevel !== 0) {
                    if (inList) formatted.push('');
                    inList = true;
                    listLevel = 0;
                }
                formatted.push('- ' + blockquoteBullet[1]);
            } else if (bulletMatch) {
                // Regular bullet point
                if (!inList || listLevel !== 0) {
                    if (inList) formatted.push('');
                    inList = true;
                    listLevel = 0;
                }
                formatted.push('- ' + bulletMatch[1]);
            } else if (numberedMatch) {
                // Numbered list item
                if (!inList || listLevel !== 0) {
                    if (inList) formatted.push('');
                    inList = true;
                    listLevel = 0;
                }
                formatted.push(numberedMatch[1] + '. ' + numberedMatch[2]);
            } else {
                // Regular text line
                if (inList) {
                    formatted.push('');
                    inList = false;
                    listLevel = 0;
                }
                
                // Clean up markdown formatting but preserve structure
                cleanLine = cleanLine
                    .replace(/^\*\s*/, '')
                    .replace(/\*\*(.+?)\*\*/g, '$1')
                    .replace(/__(.+?)__/g, '$1')
                    .replace(/\*(.+?)\*/g, '$1')
                    .replace(/_(.+?)_/g, '$1')
                    .replace(/^#{1,6}\s+/, '');
                
                // If it's a section-like line (ends with colon), keep it as-is
                if (/^[A-Z][^:]*:\s*$/.test(cleanLine)) {
                    formatted.push(cleanLine);
                } else if (cleanLine.length > 0) {
                    formatted.push(cleanLine);
                }
            }
        });
        
        // Clean up: remove excessive blank lines and trim
        const cleaned = formatted
            .filter((line, idx) => {
                // Remove consecutive blank lines
                if (line === '' && idx > 0 && formatted[idx - 1] === '') {
                    return false;
                }
                // Remove trailing blank lines
                if (line === '' && idx === formatted.length - 1) {
                    return false;
                }
                return true;
            })
            .join('\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
        
        return cleaned;
    }

    function formatDescription(text) {
        if (!text) return '';
        text = cleanAndFormatText(text);
        text = removeGenericClosings(text);
        let paragraphs = text.split('\n\n');
        paragraphs = paragraphs.map(p => p.trim()).filter(p => p.length > 0);
        return paragraphs.join('\n\n');
    }

    const lines = normalizedResponse
        .split('\n')
        .map(line => line.trim())
        .filter(line => line.length > 0);

    let rawTitle = extractSection(normalizedResponse, 'TITLE', ['DESCRIPTION', 'INSTRUCTIONS']);
    if (!rawTitle) {
        const titleCandidate = lines.find(line => !/^(description|instructions)\s*[:\-]/i.test(line));
        if (titleCandidate) {
            rawTitle = titleCandidate.replace(/^title\s*[:\-]\s*/i, '');
        }
    }

    let rawDescription = extractSection(normalizedResponse, 'DESCRIPTION', ['INSTRUCTIONS']);
    if (!rawDescription) {
        const descriptionSplit = normalizedResponse.split(/description\s*[:\-]/i);
        if (descriptionSplit.length > 1) {
            rawDescription = descriptionSplit[1].split(/instructions\s*[:\-]/i)[0];
        } else {
            rawDescription = normalizedResponse.replace(rawTitle || '', '').split(/instructions\s*[:\-]/i)[0];
        }
    }

    let rawInstructions = extractSection(normalizedResponse, 'INSTRUCTIONS');
    if (!rawInstructions) {
        const instructionSplit = normalizedResponse.split(/instructions\s*[:\-]/i);
        if (instructionSplit.length > 1) {
            rawInstructions = instructionSplit.slice(1).join('\n');
        } else {
            const bulletLines = lines.filter(line => /^(-|\*|•|\d+\.)\s*/.test(line));
            rawInstructions = bulletLines.join('\n');
        }
    }

    const assignmentNameInput = document.getElementById('assignmentName');
    const assignmentDescriptionInput = document.getElementById('assignmentDescription');
    const activityInstructionsInput = document.getElementById('activityInstructions');

    if (assignmentNameInput && rawTitle) {
        let title = cleanAndFormatText(rawTitle);
        title = title.replace(/^["']|["']$/g, '');
        assignmentNameInput.value = title;
    }

    if (assignmentDescriptionInput && rawDescription) {
        const description = formatDescription(rawDescription);
        assignmentDescriptionInput.value = description;
    }

    if (activityInstructionsInput && rawInstructions) {
        let instructions = cleanAndFormatText(rawInstructions);
        instructions = removeGenericClosings(instructions);
        instructions = formatInstructions(instructions);
        activityInstructionsInput.value = instructions;
    }

    if (assignmentNameInput) {
        assignmentNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (typeof window.updateRubricAssignmentSummary === 'function') {
        window.updateRubricAssignmentSummary();
    }
}
</script>

