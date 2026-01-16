document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.lp-library');
    if (!container) {
        return;
    }

    const ajaxUrl = container.dataset.ajax;
    const sesskey = container.dataset.sesskey;
    const gradeFilter = document.getElementById('lp-library-grade');
    const lessonFilter = document.getElementById('lp-library-lesson');
    const refreshTopBtn = document.getElementById('lp-library-refresh-top');
    const savedList = document.getElementById('lp-library-list');
    const emptyState = document.getElementById('lp-library-empty');
    const messageEl = document.getElementById('lp-library-message');
    const detailSection = document.getElementById('lp-library-detail');
    const planTitleEl = document.getElementById('lp-library-plan-title');
    const planContainer = document.getElementById('lp-library-plan');
    const printBtn = document.getElementById('lp-library-print');
    const downloadBtn = document.getElementById('lp-library-download');

    let savedPlans = [];
    let currentPlan = null;
    let currentMeta = null;

    const showMessage = (text, type = 'info') => {
        if (!messageEl) {
            return;
        }
        if (!text) {
            messageEl.hidden = true;
            messageEl.textContent = '';
            return;
        }
        messageEl.hidden = false;
        messageEl.textContent = text;
        messageEl.className = `lp-message ${type}`;
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

    const escapeHtml = (value = '') => {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
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

    const renderCellText = (value) => (value ? escapeHtml(value) : '<span class="lp-empty">Not provided.</span>');
    const wrapBlocks = (content, extra = '') => (content ? `<div class="lp-plan-blocks${extra ? ` ${extra}` : ''}">${content}</div>` : '');

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

    const renderCoursePlan = (plan) => {
        if (!plan || !planContainer || !plan.lessons) {
            return;
        }

        if (planTitleEl) {
            planTitleEl.textContent = `${plan.coursename} • Course Planner`;
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
            html += `<div class="lp-course-lesson-section" data-lesson-id="${lessonId}">`;
            html += `<h3 class="lp-course-lesson-title">${escapeHtml(lesson.lessonname || `Lesson ${lessonIndex + 1}`)}</h3>`;

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

        planContainer.innerHTML = html;

        if (detailSection) {
            detailSection.hidden = false;
        }
        if (printBtn) {
            printBtn.disabled = false;
        }
        if (downloadBtn) {
            downloadBtn.disabled = false;
        }
    };

    const renderPlan = (plan) => {
        if (!plan || !planContainer) {
            return;
        }

        // Handle course planner (multiple lessons)
        if (plan.type === 'course' && plan.lessons && Array.isArray(plan.lessons)) {
            renderCoursePlan(plan);
            return;
        }

        const rows = Array.isArray(plan.rows) ? plan.rows : [];

        if (planTitleEl) {
            planTitleEl.textContent = `${plan.coursename} • ${plan.lessonname}`;
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

        planContainer.innerHTML = `
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

        if (detailSection) {
            detailSection.hidden = false;
        }
        if (printBtn) {
            printBtn.disabled = false;
        }
        if (downloadBtn) {
            downloadBtn.disabled = false;
        }
    };

    const renderGradeGroups = () => {
        if (!savedList) {
            return;
        }
        const gradeValue = gradeFilter ? gradeFilter.value : '';
        const lessonValue = lessonFilter ? lessonFilter.value : '';
        
        // Separate lesson plans and course planners
        const lessonPlans = savedPlans.filter((plan) => (plan.plantype || 'lesson') === 'lesson');
        const coursePlanners = savedPlans.filter((plan) => (plan.plantype || 'lesson') === 'course');
        
        const filteredLessonPlans = lessonPlans.filter((plan) => {
            const gradeMatch = gradeValue ? plan.grade === gradeValue : true;
            const lessonMatch = lessonValue ? plan.lessonname === lessonValue : true;
            return gradeMatch && lessonMatch;
        });
        
        const filteredCoursePlanners = coursePlanners.filter((plan) => {
            const gradeMatch = gradeValue ? plan.grade === gradeValue : true;
            return gradeMatch; // Course planners don't have lesson names to filter by
        });

        if (!filteredLessonPlans.length && !filteredCoursePlanners.length) {
            savedList.innerHTML = '';
            if (emptyState) {
                emptyState.textContent = 'No saved plans match your filters.';
                emptyState.hidden = false;
            }
            return;
        }

        if (emptyState) {
            emptyState.hidden = true;
        }

        let html = '';

        // Render Course Planners section first
        if (filteredCoursePlanners.length > 0) {
            const courseGrouped = filteredCoursePlanners.reduce((acc, plan) => {
                const key = plan.grade || 'General';
                if (!acc[key]) {
                    acc[key] = [];
                }
                acc[key].push(plan);
                return acc;
            }, {});

            html += '<div class="lp-saved-section-header"><h3>Course Planners</h3></div>';
            html += Object.entries(courseGrouped).map(([grade, plans]) => `
                <div class="lp-saved-grade-group">
                    <div class="lp-saved-grade-heading">
                        <i class="fa fa-folder"></i>
                        <span class="lp-saved-grade-name">${escapeHtml(grade)}</span>
                        <span class="lp-saved-grade-count">${plans.length} ${plans.length === 1 ? 'planner' : 'planners'}</span>
                    </div>
                    <div class="lp-saved-items">
                        ${plans.map((plan) => `
                            <article class="lp-saved-card lp-course-plan-card">
                                <div class="lp-saved-card-header">
                                    <div>
                                        <p class="lp-saved-lesson">${escapeHtml(plan.lessonname)}</p>
                                        <p class="lp-saved-course">${escapeHtml(plan.coursename)}</p>
                                    </div>
                                    <div class="lp-saved-card-actions">
                                        <button class="lp-button-ghost" data-plan-id="${plan.id}" data-action="view">
                                            <i class="fa fa-eye"></i> View
                                        </button>
                                        <button class="lp-button-ghost lp-button-danger" data-plan-id="${plan.id}" data-action="delete">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                                <p class="lp-saved-meta">Saved on ${new Date(plan.timecreated * 1000).toLocaleString()}</p>
                            </article>
                        `).join('')}
                    </div>
                </div>
            `).join('');
        }

        // Render Lesson Plans section
        if (filteredLessonPlans.length > 0) {
            const lessonGrouped = filteredLessonPlans.reduce((acc, plan) => {
                const key = plan.grade || 'General';
                if (!acc[key]) {
                    acc[key] = [];
                }
                acc[key].push(plan);
                return acc;
            }, {});

            html += '<div class="lp-saved-section-header"><h3>Lesson Plans</h3></div>';
            html += Object.entries(lessonGrouped).map(([grade, plans]) => `
                <div class="lp-saved-grade-group">
                    <div class="lp-saved-grade-heading">
                        <i class="fa fa-folder"></i>
                        <span class="lp-saved-grade-name">${escapeHtml(grade)}</span>
                        <span class="lp-saved-grade-count">${plans.length} ${plans.length === 1 ? 'plan' : 'plans'}</span>
                    </div>
                    <div class="lp-saved-items">
                        ${plans.map((plan) => `
                            <article class="lp-saved-card">
                                <div class="lp-saved-card-header">
                                    <div>
                                        <p class="lp-saved-lesson">${escapeHtml(plan.lessonname)}</p>
                                        <p class="lp-saved-course">${escapeHtml(plan.coursename)}</p>
                                    </div>
                                    <div class="lp-saved-card-actions">
                                        <button class="lp-button-ghost" data-plan-id="${plan.id}" data-action="view">
                                            <i class="fa fa-eye"></i> View
                                        </button>
                                        <button class="lp-button-ghost lp-button-danger" data-plan-id="${plan.id}" data-action="delete">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                                <p class="lp-saved-meta">Saved on ${new Date(plan.timecreated * 1000).toLocaleString()}</p>
                            </article>
                        `).join('')}
                    </div>
                </div>
            `).join('');
        }

        savedList.innerHTML = html;
    };

    const populateFilters = () => {
        // Only include lesson plans in lesson filter (not course planners)
        const lessonPlans = savedPlans.filter((plan) => (plan.plantype || 'lesson') === 'lesson');
        const grades = Array.from(new Set(savedPlans.map((plan) => plan.grade || 'General')));
        const lessons = Array.from(new Set(lessonPlans.map((plan) => plan.lessonname || 'Lesson')));

        if (gradeFilter) {
            const current = gradeFilter.value;
            gradeFilter.innerHTML = '<option value="">All grades</option>' +
                grades.map((grade) => `<option value="${grade}">${grade}</option>`).join('');
            if (current) {
                gradeFilter.value = current;
            }
        }

        if (lessonFilter) {
            const current = lessonFilter.value;
            lessonFilter.innerHTML = '<option value="">All lessons</option>' +
                lessons.map((lesson) => `<option value="${lesson}">${lesson}</option>`).join('');
            if (current) {
                lessonFilter.value = current;
            }
        }
    };

    const loadSavedPlans = () => {
        showMessage('Loading your saved plans...');
        apiGet({ action: 'getsaved', sesskey })
            .then((response) => {
                if (!response.success) {
                    showMessage(response.message || 'Unable to load saved lesson plans.', 'error');
                    if (emptyState) {
                        emptyState.textContent = response.message || 'Unable to load saved lesson plans.';
                        emptyState.hidden = false;
                    }
                    savedPlans = [];
                    savedList.innerHTML = '';
                    return;
                }
                savedPlans = response.plans || [];
                populateFilters();
                renderGradeGroups();
                showMessage('');
                if (!savedPlans.length && emptyState) {
                    emptyState.textContent = 'No saved plans yet. Generate and save a lesson plan or course planner to begin.';
                    emptyState.hidden = false;
                }
            })
            .catch(() => {
                showMessage('Unable to load saved lesson plans.', 'error');
                if (emptyState) {
                    emptyState.textContent = 'Unable to load saved lesson plans.';
                    emptyState.hidden = false;
                }
            });
    };

    const handleSelectPlan = (planId) => {
        const record = savedPlans.find((plan) => String(plan.id) === String(planId));
        if (!record || !record.plan) {
            showMessage('The selected plan could not be loaded.', 'error');
            return;
        }
        currentPlan = record.plan;
        currentPlan.coursename = record.coursename;
        if (record.plan.type !== 'course') {
            currentPlan.lessonname = record.lessonname;
        }
        currentMeta = {
            courseid: record.courseid,
            lessonid: record.lessonid,
            planid: record.id
        };
        renderPlan(record.plan);
        const planType = (record.plantype || (record.plan.type === 'course' ? 'course' : 'lesson')) === 'course' ? 'course planner' : 'lesson plan';
        showMessage(`Loaded ${planType}: ${record.lessonname}`, 'info');
    };

    const handlePrint = () => {
        if (!currentPlan) {
            return;
        }
        const win = window.open('', '_blank', 'width=900,height=700');
        win.document.write(`
            <html>
                <head>
                    <title>Lesson Plan - ${currentPlan.lessonname}</title>
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
                    <p class="meta"><strong>Course:</strong> ${currentPlan.coursename}<br>
                    <strong>Lesson:</strong> ${currentPlan.lessonname}</p>
                    ${planContainer.innerHTML}
                </body>
            </html>
        `);
        win.document.close();
        win.focus();
        win.print();
    };

    const handleDownload = () => {
        if (!currentPlan) {
            return;
        }
        downloadBtn.disabled = true;
        apiPost({
            action: 'downloadpdf',
            sesskey,
            plan: JSON.stringify(currentPlan)
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

    if (gradeFilter) {
        gradeFilter.addEventListener('change', () => {
            renderGradeGroups();
        });
    }

    if (lessonFilter) {
        lessonFilter.addEventListener('change', () => {
            renderGradeGroups();
        });
    }

    if (refreshTopBtn) {
        refreshTopBtn.addEventListener('click', (event) => {
            event.preventDefault();
            loadSavedPlans();
        });
    }

    const handleDeletePlan = (planId) => {
        if (!confirm('Are you sure you want to delete this lesson plan? This action cannot be undone.')) {
            return;
        }
        apiPost({
            action: 'deletelesson',
            sesskey,
            planid: planId
        }).then((response) => {
            if (!response.success) {
                showMessage(response.message || 'Unable to delete lesson plan.', 'error');
                return;
            }
            showMessage('Lesson plan deleted successfully.', 'info');
            // Remove from local array
            savedPlans = savedPlans.filter((plan) => String(plan.id) !== String(planId));
            // If the deleted plan was currently viewed, hide the detail section
            if (currentMeta && String(currentMeta.planid) === String(planId)) {
                if (detailSection) {
                    detailSection.hidden = true;
                }
                currentPlan = null;
                currentMeta = null;
            }
            populateFilters();
            renderGradeGroups();
        }).catch(() => {
            showMessage('Unable to delete lesson plan.', 'error');
        });
    };

    if (savedList) {
        savedList.addEventListener('click', (event) => {
            const trigger = event.target.closest('button[data-plan-id]');
            if (trigger) {
                const action = trigger.dataset.action || 'view';
                const planId = trigger.dataset.planId;
                if (action === 'delete') {
                    handleDeletePlan(planId);
                } else {
                    handleSelectPlan(planId);
                }
            }
        });
    }

    if (printBtn) {
        printBtn.addEventListener('click', (event) => {
            event.preventDefault();
            handlePrint();
        });
    }

    if (downloadBtn) {
        downloadBtn.addEventListener('click', (event) => {
            event.preventDefault();
            handleDownload();
        });
    }

    // Handle module collapse/expand
    if (planContainer) {
        planContainer.addEventListener('click', (event) => {
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

    loadSavedPlans();
});

