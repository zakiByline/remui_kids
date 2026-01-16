document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.lp-container');
    if (!container) {
        return;
    }

    const ajaxUrl = container.dataset.ajax;
    const sesskey = container.dataset.sesskey;
    const courseSelect = document.getElementById('lp-course');
    const lessonSelect = document.getElementById('lp-lesson');
    const generateBtn = document.getElementById('lp-generate');
    const generateCourseBtn = document.getElementById('lp-generate-course');
    const loader = document.getElementById('lp-loader');
    const loaderMessage = document.getElementById('lp-loader-message');
    const messageEl = document.getElementById('lp-message');
    const resultEl = document.getElementById('lessonplan-result');
    const printBtn = document.getElementById('lp-print');
    const downloadBtn = document.getElementById('lp-download');
    const saveBtn = document.getElementById('lp-save');
    const heroInspirationBtn = document.getElementById('lp-hero-inspiration');
    const inputsCard = document.getElementById('lp-inputs-card');
    const contextPanel = document.getElementById('lp-lesson-context');
    const contextTitle = document.getElementById('lp-context-title');
    const contextSummary = document.getElementById('lp-context-summary');
    const contextActivities = document.getElementById('lp-context-activities');
    const contextExportBtn = document.getElementById('lp-context-export');
    const planTitle = document.getElementById('lp-plan-title');
    let lastPlan = null;
    let lastPlanMeta = null;
    let lastContextCSV = '';

    const showMessage = (text, type = 'info') => {
        if (!text) {
            messageEl.hidden = true;
            messageEl.textContent = '';
            return;
        }
        messageEl.hidden = false;
        messageEl.textContent = text;
        messageEl.className = `lp-message ${type}`;
    };

    const toggleLoader = (state, message = 'Generating a tailored lesson plan...') => {
        loader.hidden = !state;
        if (loaderMessage) {
            loaderMessage.textContent = message;
        }
    };

    const setGenerateState = (enabled) => {
        generateBtn.disabled = !enabled;
        // Course planner only needs a course selected, not a lesson
        if (generateCourseBtn) {
            generateCourseBtn.disabled = !courseSelect.value;
        }
    };

    const scrollToElement = (element) => {
        if (!element) {
            return;
        }
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
        element.classList.add('lp-scroll-highlight');
        window.setTimeout(() => {
            element.classList.remove('lp-scroll-highlight');
        }, 1500);
    };

    const clearPlan = () => {
        lastPlan = null;
        lastPlanMeta = null;
        resultEl.innerHTML = '';
        if (planTitle) {
            planTitle.textContent = '';
        }
        printBtn.disabled = true;
        downloadBtn.disabled = true;
        if (saveBtn) {
            saveBtn.disabled = true;
            // Reset save button text to default
            const saveIcon = saveBtn.querySelector('i');
            // Remove existing text nodes
            Array.from(saveBtn.childNodes).forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.remove();
                }
            });
            // Add default text
            if (saveIcon) {
                saveBtn.insertBefore(document.createTextNode(' Save Lesson Plan'), saveIcon.nextSibling);
            }
        }
    };

    const apiGet = (params) => {
        const url = new URL(ajaxUrl, window.location.origin);
        Object.entries(params).forEach(([key, value]) => url.searchParams.append(key, value));
        return fetch(url, { credentials: 'same-origin' })
            .then((response) => response.json());
    };

    const apiPost = (body) => {
        const formData = new FormData();
        Object.entries(body).forEach(([key, value]) => formData.append(key, value));
        return fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then((response) => response.json());
    };

    const resetContext = () => {
        if (!contextPanel) {
            return;
        }
        contextPanel.hidden = true;
        contextTitle.textContent = '';
        contextSummary.textContent = '';
        contextActivities.innerHTML = '';
        lastContextCSV = '';
        if (contextExportBtn) {
            contextExportBtn.hidden = true;
            contextExportBtn.disabled = true;
        }
    };

    const escapeHtml = (value = '') => {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    };

    const normalizeActivityType = (type = '') => {
        const normalized = type.toLowerCase();
        const mapping = {
            'edwiservideoactivity': 'Video Activity',
            'edwiservideo': 'Video Activity',
            'scorm': 'SCORM',
            'assign': 'Assignment',
            'quiz': 'Quiz',
            'forum': 'Forum',
            'page': 'Page',
            'url': 'URL',
            'resource': 'Resource',
            'h5pactivity': 'H5P Activity',
            'workshop': 'Workshop'
        };
        return mapping[normalized] || (type ? type.charAt(0).toUpperCase() + type.slice(1).toLowerCase() : '');
    };

    const extractStandardCode = (standard) => {
        if (!standard || typeof standard !== 'string') {
            return standard;
        }
        const trimmed = standard.trim();
        if (!trimmed) {
            return standard;
        }
        
        // Extract competency code from various formats:
        // - "ISTE-Students-1.1 1.1 Empowered Learner" → "ISTE-Students-1.1 1.1"
        // - "G1.IT.1.1 Description" → "G1.IT.1.1"
        // - "1.1 Description" → "1.1"
        // - "1.1.2 Description" → "1.1.2"
        // - "1.1a Description" → "1.1a"
        // - "CCSS.ELA-LITERACY.RL.1.1" → "CCSS.ELA-LITERACY.RL.1.1"
        // - "NGSS-1-LS1-1" → "NGSS-1-LS1-1"
        // Pattern: Match everything up to the last number pattern (X.X, X.X.X, X.Xa, etc.) before descriptive text
        
        // Try pattern 1: Full format with prefix, space, and number pattern (e.g., "ISTE-Students-1.1 1.1")
        // Matches: prefix (letters/numbers/dots/dashes), space, then number pattern
        const fullPatternWithSpace = /^([A-Za-z0-9\-\.]+)\s+(\d+(?:\.\d+)+(?:[a-z])?)/;
        let match = trimmed.match(fullPatternWithSpace);
        if (match) {
            return `${match[1]} ${match[2]}`.trim();
        }
        
        // Try pattern 2: Full format without space (e.g., "G1.IT.1.1" or "Standard-1.1" or "CCSS.ELA-LITERACY.RL.1.1")
        // Matches: prefix ending with number pattern (allows dots, dashes, colons in prefix)
        const fullPatternNoSpace = /^([A-Za-z0-9\-\.:]*\d+(?:\.\d+)+(?:[a-z])?)/;
        match = trimmed.match(fullPatternNoSpace);
        if (match) {
            return match[1].trim();
        }
        
        // Try pattern 3: Simple number pattern at start (e.g., "1.1 Description" or "1.1.2 Description")
        const numberPattern = /^(\d+(?:\.\d+)+(?:[a-z])?)/;
        match = trimmed.match(numberPattern);
        if (match) {
            return match[1].trim();
        }
        
        // Try pattern 4: Code with colons (e.g., "Standard: 1.1 Description")
        const colonPattern = /^([A-Za-z0-9\-\.\s]+:\s*\d+(?:\.\d+)+(?:[a-z])?)/;
        match = trimmed.match(colonPattern);
        if (match) {
            return match[1].trim();
        }
        
        // Try pattern 5: Alphanumeric codes (e.g., "A1", "B2.3", "C-1.2")
        const alphanumericPattern = /^([A-Za-z][A-Za-z0-9\-\.]*\d+(?:\.\d+)*(?:[a-z])?)/;
        match = trimmed.match(alphanumericPattern);
        if (match) {
            return match[1].trim();
        }
        
        // If no pattern matches, try to extract first meaningful part (before first space or common separators)
        const firstPart = trimmed.split(/[\s\-–—]/)[0];
        if (firstPart && firstPart.length > 0) {
            return firstPart.trim();
        }
        
        // If no pattern matches, return the original
        return trimmed;
    };

    const renderList = (items, variant = 'list', extractCode = false) => {
        if (!items || !items.length) {
            return '<p class="lp-empty">Not provided.</p>';
        }
        // Ensure items is an array
        const itemsArray = Array.isArray(items) ? items : [items];
        if (extractCode) {
            const processedItems = itemsArray.map(item => {
                const original = String(item);
                const code = extractStandardCode(original);
                return { code, fullName: original };
            });
            if (variant === 'pill') {
                return `<ul class="lp-pill-list">${processedItems.map((item) => 
                    `<li class="lp-standard-code" title="${escapeHtml(item.fullName)}">${escapeHtml(item.code)}</li>`
                ).join('')}</ul>`;
            }
            return `<ul>${processedItems.map((item) => 
                `<li class="lp-standard-code" title="${escapeHtml(item.fullName)}">${escapeHtml(item.code)}</li>`
            ).join('')}</ul>`;
        }
        if (variant === 'pill') {
            return `<ul class="lp-pill-list">${itemsArray.map((item) => `<li>${escapeHtml(String(item))}</li>`).join('')}</ul>`;
        }
        return `<ul>${itemsArray.map((item) => `<li>${escapeHtml(String(item))}</li>`).join('')}</ul>`;
    };

    const normalizeHierarchy = (lessonTitle = '', modules = [], fallbackActivities = []) => {
        const normalizedLesson = lessonTitle || 'Selected Lesson';
        const normalizedModules = [];

        const moduleTypeLabel = (module) => {
            if (!module || !module.type) {
                return 'Module';
            }
            if (module.type === 'subsection') {
                return 'Module';
            }
            return module.type.charAt(0).toUpperCase() + module.type.slice(1);
        };

        const normalizeActivity = (activity) => ({
            name: activity.name || 'Untitled activity',
            summary: activity.summary || '',
            type: activity.type || ''
        });

        if (modules.length) {
            modules.forEach((module) => {
                const activityList = (module.activities && module.activities.length)
                    ? module.activities.map(normalizeActivity)
                    : [normalizeActivity({ name: 'No activities added yet' })];
                normalizedModules.push({
                    name: module.name || 'Module',
                    summary: module.summary || '',
                    typeLabel: moduleTypeLabel(module),
                    activities: activityList
                });
            });
        } else {
            const activities = fallbackActivities.length
                ? fallbackActivities.map(normalizeActivity)
                : [normalizeActivity({ name: 'No activities added yet' })];
            normalizedModules.push({
                name: 'Activities',
                summary: '',
                typeLabel: 'Standalone',
                activities
            });
        }

        return {
            lesson: normalizedLesson,
            modules: normalizedModules
        };
    };

    const renderContextBlocks = (hierarchy) => {
        const lessonTitle = escapeHtml(hierarchy.lesson);
        const moduleCards = hierarchy.modules.map((module) => {
            const activitiesMarkup = module.activities.map((activity) => `
                <li class="lp-activity-item">
                    <div class="lp-activity-info">
                        <span class="lp-activity-name">${escapeHtml(activity.name)}</span>
                        ${activity.summary ? `<p>${escapeHtml(activity.summary)}</p>` : ''}
                    </div>
                    ${activity.type ? `<span class="lp-activity-chip">${escapeHtml(normalizeActivityType(activity.type))}</span>` : ''}
                </li>
            `).join('');

            return `
                <article class="lp-module-card">
                    <header class="lp-module-card-header">
                        <div>
                            <p class="lp-module-type">${escapeHtml(module.typeLabel)}</p>
                            <h4>${escapeHtml(module.name)}</h4>
                        </div>
                    </header>
                    ${module.summary ? `<p class="lp-module-summary">${escapeHtml(module.summary)}</p>` : ''}
                    <ul class="lp-module-activities">
                        ${activitiesMarkup}
                    </ul>
                </article>
            `;
        }).join('');

        return `
            <div class="lp-context-block">
                <div class="lp-lesson-banner">
                    <span class="lp-lesson-pill">Lesson Focus</span>
                    <h4>${lessonTitle}</h4>
                </div>
                <div class="lp-context-module-grid">
                    ${moduleCards}
                </div>
            </div>
        `;
    };

    const csvEscape = (value) => {
        const stringValue = value || '';
        const needsQuotes = /[",\n]/.test(stringValue);
        const sanitized = stringValue.replace(/"/g, '""');
        return needsQuotes ? `"${sanitized}"` : sanitized;
    };

    const buildContextCsv = (hierarchy) => {
        const header = ['Lesson', 'Module', 'Module Type', 'Activity', 'Activity Type', 'Activity Summary'];
        const rows = [header];

        hierarchy.modules.forEach((module) => {
            module.activities.forEach((activity) => {
                rows.push([
                    hierarchy.lesson,
                    module.name,
                    module.typeLabel,
                    activity.name,
                    activity.type,
                    activity.summary
                ]);
            });
        });

        return rows.map((row) => row.map((cell) => csvEscape(cell)).join(',')).join('\r\n');
    };

    const renderPlanCard = (block, label, accentClass = '') => {
        if (!block || (Object.keys(block).length === 0) ||
            ((!block.description && (!block.items || !block.items.length)) && !block.title)) {
            return '';
        }
        const title = block.title || label;
        const description = block.description ? `<p>${escapeHtml(block.description)}</p>` : '';
        const items = block.items && block.items.length ? renderList(block.items) : '';
        return `
            <article class="lp-plan-card ${accentClass}">
                <p class="lp-plan-card-label">${label}</p>
                <h4>${escapeHtml(title)}</h4>
                ${description}
                ${items}
            </article>
        `;
    };

    const renderTeacherSuggestions = (suggestions) => {
        if (!suggestions || typeof suggestions !== 'object') {
            return '';
        }

        const activitySuggestions = suggestions.activitySuggestions || [];
        const assessmentSuggestions = suggestions.assessmentSuggestions || [];
        const assignmentSuggestions = suggestions.assignmentSuggestions || [];

        if (!activitySuggestions.length && !assessmentSuggestions.length && !assignmentSuggestions.length) {
            return '';
        }

        let html = '<div class="lp-teacher-suggestions">';
        html += '<h3 class="lp-teacher-suggestions-title">Teacher Suggestions</h3>';
        html += '<div class="lp-teacher-suggestions-grid">';

        if (activitySuggestions.length) {
            html += `
                <article class="lp-plan-card accent-probe">
                    <p class="lp-plan-card-label">Activity Ideas</p>
                    <h4>Activity Suggestions</h4>
                    ${renderList(activitySuggestions)}
                </article>
            `;
        }

        if (assessmentSuggestions.length) {
            html += `
                <article class="lp-plan-card accent-assessment">
                    <p class="lp-plan-card-label">Assessment Ideas</p>
                    <h4>Assessment Suggestions</h4>
                    ${renderList(assessmentSuggestions)}
                </article>
            `;
        }

        if (assignmentSuggestions.length) {
            html += `
                <article class="lp-plan-card accent-review">
                    <p class="lp-plan-card-label">Assignment Ideas</p>
                    <h4>Assignment Suggestions</h4>
                    ${renderList(assignmentSuggestions)}
                </article>
            `;
        }

        html += '</div></div>';
        return html;
    };

    const renderCellText = (value) => (value ? escapeHtml(value) : '<span class="lp-empty">Not provided.</span>');

    const wrapBlocks = (content, extra = '') => (content ? `<div class="lp-plan-blocks${extra ? ` ${extra}` : ''}">${content}</div>` : '');

    const renderCoursePlan = (plan) => {
        if (!plan || !plan.lessons) {
            clearPlan();
            return;
        }

        if (planTitle) {
            planTitle.textContent = `${plan.coursename} • Course Planner`;
        }

        let html = '';

        // Course Overview
        if (plan.overview) {
            html += `<div class="lp-course-overview"><p>${escapeHtml(plan.overview)}</p></div>`;
        }

        // Learning Objectives
        if (plan.learningobjectives && plan.learningobjectives.length) {
            html += renderPlanCard({ items: plan.learningobjectives, title: 'Course Learning Objectives' }, 'Learning Objectives', 'accent-opener');
        }

        // Key Concepts
        if (plan.keyconcepts && plan.keyconcepts.length) {
            html += renderPlanCard({ items: plan.keyconcepts, title: 'Key Concepts' }, 'Key Concepts', 'accent-probe');
        }

        // Render each lesson
        plan.lessons.forEach((lesson, lessonIndex) => {
            const lessonId = `course-lesson-${lessonIndex}`;
            html += `<div class="lp-course-lesson-wrapper" data-lesson-wrapper-id="${lessonId}">`;
            html += `<div class="lp-course-lesson-header lp-lesson-header-clickable" data-lesson-toggle="${lessonId}">`;
            html += `<h3 class="lp-course-lesson-title">`;
            html += `<i class="fa fa-chevron-down lp-lesson-toggle-icon"></i>`;
            html += `<span>${escapeHtml(lesson.lessonname || `Lesson ${lessonIndex + 1}`)}</span>`;
            html += `</h3>`;
            html += `</div>`;
            html += `<div class="lp-course-lesson-content" data-lesson-content="${lessonId}">`;
            html += `<div class="lp-course-lesson-section" data-lesson-id="${lessonId}">`;

            const opener = renderPlanCard(lesson.unitopener, 'Lesson Opener', 'accent-opener');
            const probe = renderPlanCard(lesson.probe, 'Mid-Lesson Probe', 'accent-probe single');
            const review = renderPlanCard(lesson.lessonreview, 'Lesson Review', 'accent-review');
            const assessment = renderPlanCard(lesson.lessonassessment, 'Lesson Assessment', 'accent-assessment');

            const rows = Array.isArray(lesson.rows) ? lesson.rows : [];
            const groupedRows = rows.reduce((acc, row) => {
                const key = (row.module && row.module.trim()) ? row.module : 'Module';
                if (!acc[key]) {
                    acc[key] = [];
                }
                acc[key].push(row);
                return acc;
            }, {});

            const moduleSections = rows.length ? Object.entries(groupedRows).map(([moduleName, moduleRows], index) => {
                const moduleId = `lp-module-${lessonId}-${index}`;
                const activityRows = moduleRows.map((row) => `
                    <tr class="lp-module-activity-row" data-module-id="${moduleId}">
                        <td class="lp-plan-activity-col">
                            <div class="lp-plan-activity-name">${escapeHtml(row.activity || 'Activity')}</div>
                        </td>
                        <td class="lp-highlight">${renderCellText(row.objective)}</td>
                        <td class="lp-highlight">${renderCellText(row.languageobjective)}</td>
                        <td class="lp-highlight">${renderCellText(row.selobjective)}</td>
                        <td>${renderList(row.keyvocabulary, 'pill')}</td>
                        <td>${renderList(row.materials)}</td>
                        <td class="lp-highlight">${renderCellText(row.rigorfocus)}</td>
                        <td>${renderList(row.standards, 'pill', true)}</td>
                    </tr>
                `).join('');
                return `
                    <tbody class="lp-plan-module-group" data-module-id="${moduleId}">
                        <tr class="lp-plan-module-header lp-module-header-clickable" data-module-toggle="${moduleId}">
                            <td colspan="8">
                                <div class="lp-plan-module-title">
                                    <i class="fa fa-chevron-down lp-module-toggle-icon"></i>
                                    <span>${escapeHtml(moduleName)}</span>
                                </div>
                            </td>
                        </tr>
                        ${activityRows}
                    </tbody>
                `;
            }).join('') : `<tbody><tr><td colspan="8"><p class="lp-empty">No activities available for this lesson.</p></td></tr></tbody>`;

            html += wrapBlocks(opener);
            if (rows.length) {
                html += `
                    <div class="lp-table-wrapper lp-plan-table-wrapper">
                        <table class="lp-plan-table">
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>Objective</th>
                                    <th>Language Objective</th>
                                    <th>Social and Emotional Learning Objective</th>
                                    <th>Key Vocabulary</th>
                                    <th>Materials to Gather</th>
                                    <th>Rigor Focus</th>
                                    <th>Standards</th>
                                </tr>
                            </thead>
                            ${moduleSections}
                        </table>
                    </div>
                `;
            }
            html += wrapBlocks(probe, 'lp-plan-blocks-center');
            html += wrapBlocks(`${review}${assessment}`, 'lp-plan-blocks-row');
            html += `</div>`;
            html += `</div>`;
            html += `</div>`;
        });

        // Course-wide resources, assessments, differentiation, duration
        if (plan.resources && plan.resources.length) {
            html += renderPlanCard({ items: plan.resources, title: 'Course Resources' }, 'Resources', 'accent-review');
        }
        if (plan.assessments && plan.assessments.length) {
            html += renderPlanCard({ items: plan.assessments, title: 'Course Assessments' }, 'Assessments', 'accent-assessment');
        }
        if (plan.differentiation && plan.differentiation.length) {
            html += renderPlanCard({ items: plan.differentiation, title: 'Differentiation & Support' }, 'Differentiation', 'accent-probe');
        }
        if (plan.duration) {
            html += `<div class="lp-course-duration"><p><strong>Course Duration:</strong> ${escapeHtml(plan.duration)}</p></div>`;
        }

        // Add teacher suggestions for course planner
        html += renderTeacherSuggestions(plan.teachersuggestions);

        resultEl.innerHTML = html;

        printBtn.disabled = false;
        downloadBtn.disabled = false;
        if (saveBtn) {
            saveBtn.disabled = false;
            // Update save button text for course planner
            const saveIcon = saveBtn.querySelector('i');
            // Remove existing text nodes
            Array.from(saveBtn.childNodes).forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.remove();
                }
            });
            // Add new text
            if (saveIcon && saveIcon.nextSibling) {
                saveIcon.nextSibling.textContent = ' Save Course Planner';
            } else if (saveIcon) {
                saveBtn.insertBefore(document.createTextNode(' Save Course Planner'), saveIcon.nextSibling);
            } else {
                saveBtn.appendChild(document.createTextNode(' Save Course Planner'));
            }
        }

        // Add event listeners for lesson collapse/expand
        resultEl.addEventListener('click', (event) => {
            const lessonHeader = event.target.closest('.lp-lesson-header-clickable');
            if (lessonHeader) {
                const lessonId = lessonHeader.dataset.lessonToggle;
                const lessonContent = resultEl.querySelector(`[data-lesson-content="${lessonId}"]`);
                const icon = lessonHeader.querySelector('.lp-lesson-toggle-icon');
                const lessonWrapper = lessonHeader.closest('.lp-course-lesson-wrapper');
                
                if (lessonWrapper && lessonWrapper.classList.contains('lp-lesson-collapsed')) {
                    // Expand
                    lessonWrapper.classList.remove('lp-lesson-collapsed');
                    if (lessonContent) {
                        lessonContent.style.display = '';
                    }
                    if (icon) {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-chevron-down');
                    }
                } else {
                    // Collapse
                    if (lessonWrapper) {
                        lessonWrapper.classList.add('lp-lesson-collapsed');
                    }
                    if (lessonContent) {
                        lessonContent.style.display = 'none';
                    }
                    if (icon) {
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-right');
                    }
                }
            }
        });
    };

    const renderPlan = (plan) => {
        if (!plan) {
            clearPlan();
            return;
        }

        // Handle course planner (multiple lessons)
        if (plan.type === 'course' && plan.lessons && Array.isArray(plan.lessons)) {
            renderCoursePlan(plan);
            return;
        }

        const rows = Array.isArray(plan.rows) ? plan.rows : [];

        if (planTitle) {
            planTitle.textContent = `${plan.coursename} • ${plan.lessonname}`;
        }

        const opener = renderPlanCard(plan.unitopener, 'Lesson Opener', 'accent-opener');
        const probe = renderPlanCard(plan.probe, 'Mid-Lesson Probe', 'accent-probe single');
        const review = renderPlanCard(plan.lessonreview, 'Lesson Review', 'accent-review');
        const assessment = renderPlanCard(plan.lessonassessment, 'Lesson Assessment', 'accent-assessment');

        const groupedRows = rows.reduce((acc, row) => {
            const key = (row.module && row.module.trim()) ? row.module : 'Module';
            if (!acc[key]) {
                acc[key] = [];
            }
            acc[key].push(row);
            return acc;
        }, {});

        const moduleSections = rows.length ? Object.entries(groupedRows).map(([moduleName, moduleRows], index) => {
            const moduleId = `lp-module-${index}`;
            const activityRows = moduleRows.map((row) => `
                <tr class="lp-module-activity-row" data-module-id="${moduleId}">
                    <td class="lp-plan-activity-col">
                        <div class="lp-plan-activity-name">${escapeHtml(row.activity || 'Activity')}</div>
                    </td>
                    <td class="lp-highlight">${renderCellText(row.objective)}</td>
                    <td class="lp-highlight">${renderCellText(row.languageobjective)}</td>
                    <td class="lp-highlight">${renderCellText(row.selobjective)}</td>
                    <td>${renderList(row.keyvocabulary, 'pill')}</td>
                    <td>${renderList(row.materials)}</td>
                    <td class="lp-highlight">${renderCellText(row.rigorfocus)}</td>
                    <td>${renderList(row.standards, 'pill', true)}</td>
                </tr>
            `).join('');
            return `
                <tbody class="lp-plan-module-group" data-module-id="${moduleId}">
                    <tr class="lp-plan-module-header lp-module-header-clickable" data-module-toggle="${moduleId}">
                        <td colspan="8">
                            <div class="lp-plan-module-title">
                                <i class="fa fa-chevron-down lp-module-toggle-icon"></i>
                                <span>${escapeHtml(moduleName)}</span>
                            </div>
                        </td>
                    </tr>
                    ${activityRows}
                </tbody>
            `;
        }).join('') : `<tbody><tr><td colspan="8"><p class="lp-empty">No activities available for this lesson.</p></td></tr></tbody>`;

        resultEl.innerHTML = `
            ${wrapBlocks(opener)}
            <div class="lp-table-wrapper lp-plan-table-wrapper">
                <table class="lp-plan-table">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Objective</th>
                            <th>Language Objective</th>
                            <th>Social and Emotional Learning Objective</th>
                            <th>Key Vocabulary</th>
                            <th>Materials to Gather</th>
                            <th>Rigor Focus</th>
                            <th>Standards</th>
                        </tr>
                    </thead>
                    ${moduleSections}
                </table>
            </div>
            ${wrapBlocks(probe, 'lp-plan-blocks-center')}
            ${wrapBlocks(`${review}${assessment}`, 'lp-plan-blocks-row')}
            ${renderTeacherSuggestions(plan.teachersuggestions)}
        `;

        printBtn.disabled = false;
        downloadBtn.disabled = false;
        if (saveBtn) {
            saveBtn.disabled = false;
        }
    };

    const handlePrint = () => {
        if (!lastPlan) {
            return;
        }
        const win = window.open('', '_blank', 'width=900,height=700');
        win.document.write(`
            <html>
                <head>
                    <title>Lesson Plan - ${lastPlan.lessonname}</title>
                    <style>
                        body { font-family: "Segoe UI", sans-serif; margin: 40px; }
                        h1 { margin-bottom: 4px; }
                        p.meta { margin: 0 0 16px; color: #475569; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #cbd5f5; padding: 12px 14px; text-align: left; vertical-align: top; }
                        th { background: #eef2ff; width: 28%; }
                    </style>
                </head>
                <body>
                    <h1>Lesson Plan</h1>
                    <p class="meta"><strong>Course:</strong> ${lastPlan.coursename}<br>
                    <strong>Lesson:</strong> ${lastPlan.lessonname}</p>
                    ${resultEl.innerHTML}
                </body>
            </html>
        `);
        win.document.close();
        win.focus();
        win.print();
    };

    const handleDownload = () => {
        if (!lastPlan) {
            return;
        }
        downloadBtn.disabled = true;
        apiPost({
            action: 'downloadpdf',
            sesskey,
            plan: JSON.stringify(lastPlan)
        }).then((response) => {
            downloadBtn.disabled = false;
            if (!response.success) {
                showMessage(response.message || 'Unable to create PDF.', 'error');
                return;
            }
            const link = document.createElement('a');
            link.href = `data:application/pdf;base64,${response.filedata}`;
            link.download = response.filename || 'lesson-plan.pdf';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }).catch(() => {
            downloadBtn.disabled = false;
            showMessage('Unexpected error while generating PDF.', 'error');
        });
    };

    const loadCourses = () => {
        showMessage('Loading your courses...');
        courseSelect.disabled = true;
        lessonSelect.disabled = true;
        setGenerateState(false);
        clearPlan();
        resetContext();

        apiGet({ action: 'courses', sesskey })
            .then((response) => {
                if (!response.success) {
                    showMessage(response.message || 'Unable to load courses.', 'error');
                    return;
                }
                courseSelect.innerHTML = '<option value="">Select a course</option>';
                response.courses.forEach((course) => {
                    const option = document.createElement('option');
                    option.value = course.id;
                    option.textContent = course.fullname;
                    courseSelect.appendChild(option);
                });
                courseSelect.disabled = false;
                // Ensure course planner button is disabled when no course is selected initially
                if (generateCourseBtn) {
                    generateCourseBtn.disabled = true;
                }
                showMessage('');
            })
            .catch(() => {
                showMessage('Unable to load courses at the moment.', 'error');
            });
    };

    const loadLessons = (courseid) => {
        lessonSelect.innerHTML = '<option value="">Loading lessons...</option>';
        lessonSelect.disabled = true;
        setGenerateState(false);
        clearPlan();
        resetContext();

        apiGet({ action: 'lessons', courseid, sesskey })
            .then((response) => {
                if (!response.success) {
                    showMessage(response.message || 'Unable to load lessons.', 'error');
                    lessonSelect.innerHTML = '<option value="">No lessons available</option>';
                    return;
                }
                lessonSelect.innerHTML = '<option value="">Select a lesson or topic</option>';
                response.lessons.forEach((lesson) => {
                    const option = document.createElement('option');
                    option.value = lesson.id;
                    option.dataset.type = lesson.type;
                    option.textContent = lesson.name;
                    lessonSelect.appendChild(option);
                });
                lessonSelect.disabled = false;
                showMessage('');
            })
            .catch(() => {
                showMessage('Unable to load lessons.', 'error');
                lessonSelect.innerHTML = '<option value="">No lessons available</option>';
            });
    };

    const loadLessonContext = (courseid, lessonid) => {
        if (!contextPanel || !courseid || !lessonid) {
            return;
        }
        resetContext();
        apiGet({ action: 'lessoncontext', courseid, lessonid, sesskey })
            .then((response) => {
                if (!response.success || !response.context) {
                    return;
                }
                const { sectiontitle, sectionsummary, activities = [], modules = [] } = response.context;
                const hierarchy = normalizeHierarchy(sectiontitle, modules, activities);
                contextTitle.textContent = sectiontitle || '';
                contextSummary.textContent = sectionsummary || 'No summary available.';
                contextActivities.innerHTML = renderContextBlocks(hierarchy);
                lastContextCSV = buildContextCsv(hierarchy);
                if (contextExportBtn) {
                    contextExportBtn.hidden = false;
                    contextExportBtn.disabled = false;
                }
                contextPanel.hidden = false;
            })
            .catch(() => {
                // Ignore errors for context load.
            });
    };

    const handleSavePlan = () => {
        if (!saveBtn) {
            return;
        }
        if (!lastPlan || !lastPlanMeta) {
            showMessage('Generate a lesson plan or course planner before saving.', 'error');
            return;
        }
        saveBtn.disabled = true;
        
        // Determine if it's a course plan or lesson plan
        const isCoursePlan = lastPlan.type === 'course';
        const action = isCoursePlan ? 'savecourseplan' : 'savelesson';
        const planName = isCoursePlan ? 'Course Planner' : lastPlanMeta.lessonname;
        
        apiPost({
            action: action,
            sesskey,
            courseid: lastPlanMeta.courseid,
            lessonid: lastPlanMeta.lessonid,
            lessonname: planName,
            plan: JSON.stringify(lastPlan)
        }).then((response) => {
            saveBtn.disabled = false;
            if (!response.success) {
                showMessage(response.message || `Unable to save ${isCoursePlan ? 'course planner' : 'lesson plan'}.`, 'error');
                return;
            }
            showMessage(response.message || `${isCoursePlan ? 'Course planner' : 'Lesson plan'} saved.`, 'info');
        }).catch(() => {
            saveBtn.disabled = false;
            showMessage(`Unable to save ${isCoursePlan ? 'course planner' : 'lesson plan'}.`, 'error');
        });
    };

    const handleContextExport = () => {
        if (!lastContextCSV) {
            return;
        }
        const blob = new Blob([lastContextCSV], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'lesson-context.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    const generatePlan = () => {
        const courseid = courseSelect.value;
        const lessonOption = lessonSelect.options[lessonSelect.selectedIndex];
        const lessonid = lessonOption ? lessonOption.value : '';
        const lessonname = lessonOption ? lessonOption.textContent : '';

        if (!courseid || !lessonid) {
            return;
        }

        toggleLoader(true);
        setGenerateState(false);
        clearPlan();
        showMessage('');

        apiPost({
            action: 'generate',
            sesskey,
            courseid,
            lessonid,
            lessonname
        }).then((response) => {
            toggleLoader(false);
            setGenerateState(true);

            if (!response.success) {
                showMessage(response.message || 'Unable to generate lesson plan.', 'error');
                return;
            }

            lastPlan = response.plan;
            lastPlanMeta = {
                courseid,
                lessonid,
                lessonname,
                coursename: courseSelect.options[courseSelect.selectedIndex]?.textContent || ''
            };
            renderPlan(response.plan);
            // Update save button text for lesson plan
            if (saveBtn) {
                const saveIcon = saveBtn.querySelector('i');
                // Remove existing text nodes
                Array.from(saveBtn.childNodes).forEach(node => {
                    if (node.nodeType === Node.TEXT_NODE) {
                        node.remove();
                    }
                });
                // Add new text
                if (saveIcon && saveIcon.nextSibling) {
                    saveIcon.nextSibling.textContent = ' Save Lesson Plan';
                } else if (saveIcon) {
                    saveBtn.insertBefore(document.createTextNode(' Save Lesson Plan'), saveIcon.nextSibling);
                } else {
                    saveBtn.appendChild(document.createTextNode(' Save Lesson Plan'));
                }
            }
            showMessage('Lesson plan ready.', 'info');
        }).catch(() => {
            toggleLoader(false);
            setGenerateState(true);
            showMessage('Unexpected error while generating lesson plan.', 'error');
        });
    };

    const generateCoursePlan = () => {
        const courseid = courseSelect.value;
        if (!courseid) {
            return;
        }

        toggleLoader(true, 'Generating comprehensive course planner... This may take a few moments.');
        if (generateCourseBtn) {
            generateCourseBtn.disabled = true;
        }
        setGenerateState(false);
        clearPlan();
        showMessage('Generating comprehensive course planner...');

        apiPost({
            action: 'generatecourseplan',
            sesskey,
            courseid
        }).then((response) => {
            toggleLoader(false);
            setGenerateState(true);

            if (!response.success) {
                showMessage(response.message || 'Unable to generate course planner.', 'error');
                return;
            }

            lastPlan = response.plan;
            lastPlanMeta = {
                courseid,
                lessonid: 0,
                lessonname: 'Course Planner',
                coursename: courseSelect.options[courseSelect.selectedIndex]?.textContent || ''
            };
            renderPlan(response.plan);
            showMessage('Course planner ready.', 'info');
        }).catch(() => {
            toggleLoader(false);
            setGenerateState(true);
            showMessage('Unexpected error while generating course planner.', 'error');
        });
    };

    courseSelect.addEventListener('change', () => {
        const courseid = courseSelect.value;
        lessonSelect.innerHTML = '<option value="">Select a course first</option>';
        lessonSelect.disabled = true;
        clearPlan();
        resetContext();
        setGenerateState(false);
        // Enable course planner button immediately when course is selected
        if (generateCourseBtn) {
            generateCourseBtn.disabled = !courseid;
        }
        if (courseid) {
            loadLessons(courseid);
        }
    });

    lessonSelect.addEventListener('change', () => {
        clearPlan();
        resetContext();
        const courseid = courseSelect.value;
        const lessonid = lessonSelect.value;
        if (courseid && lessonid) {
            loadLessonContext(courseid, lessonid);
        }
        if (lessonSelect.value) {
            setGenerateState(true);
        } else {
            setGenerateState(false);
        }
    });

    generateBtn.addEventListener('click', (event) => {
        event.preventDefault();
        if (!generateBtn.disabled) {
            generatePlan();
        }
    });

    if (generateCourseBtn) {
        generateCourseBtn.addEventListener('click', (event) => {
            event.preventDefault();
            if (!generateCourseBtn.disabled) {
                generateCoursePlan();
            }
        });
    }

    if (heroInspirationBtn) {
        heroInspirationBtn.addEventListener('click', (event) => {
            event.preventDefault();
            scrollToElement(inputsCard || container);
        });
    }

    printBtn.addEventListener('click', (event) => {
        event.preventDefault();
        handlePrint();
    });

    downloadBtn.addEventListener('click', (event) => {
        event.preventDefault();
        handleDownload();
    });

    if (contextExportBtn) {
        contextExportBtn.addEventListener('click', (event) => {
            event.preventDefault();
            handleContextExport();
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', (event) => {
            event.preventDefault();
            handleSavePlan();
        });
    }

    // Handle module collapse/expand
    if (resultEl) {
        resultEl.addEventListener('click', (event) => {
            const header = event.target.closest('.lp-module-header-clickable');
            if (header) {
                const moduleId = header.dataset.moduleToggle;
                const moduleGroup = header.closest('.lp-plan-module-group');
                const activities = moduleGroup.querySelectorAll(`.lp-module-activity-row[data-module-id="${moduleId}"]`);
                const icon = header.querySelector('.lp-module-toggle-icon');
                
                if (moduleGroup.classList.contains('lp-module-collapsed')) {
                    // Expand
                    moduleGroup.classList.remove('lp-module-collapsed');
                    activities.forEach(row => {
                        row.style.display = '';
                    });
                    if (icon) {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-chevron-down');
                    }
                } else {
                    // Collapse
                    moduleGroup.classList.add('lp-module-collapsed');
                    activities.forEach(row => {
                        row.style.display = 'none';
                    });
                    if (icon) {
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-right');
                    }
                }
            }
        });
    }

    loadCourses();
});

