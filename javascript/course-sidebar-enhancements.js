/**
 * Course Sidebar Enhancements
 * Clean implementation for status badges, progress indicators, and module counts
 * 
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 */

(function() {
    'use strict';

    let isProcessing = false;
    let observer = null;

    /**
     * Initialize course sidebar enhancements
     */
    function init() {
        // Run multiple times to catch content as it loads
        function runEnhancement() {
            enhanceSidebar();
        }

        // Enhanced initialization with MutationObserver
        function setupEnhancements() {
            // Initial runs
            runEnhancement();
            setTimeout(runEnhancement, 200);
            setTimeout(runEnhancement, 500);
            setTimeout(runEnhancement, 1000);
            setTimeout(runEnhancement, 2000);
            setTimeout(runEnhancement, 3000); // Extra delay for activity pages
            
            // Watch for sidebar changes (for dynamic loading on activity pages)
            if (observer) {
                observer.disconnect();
            }
            
            observer = new MutationObserver(function(mutations) {
                let shouldEnhance = false;
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        // Check if sidebar or section content was added
                        Array.from(mutation.addedNodes).forEach(function(node) {
                            if (node.nodeType === 1) { // Element node
                                if (node.classList && (
                                    node.classList.contains('theme_remui-drawers-courseindex') ||
                                    node.classList.contains('courseindex-section') ||
                                    node.classList.contains('courseindex-item-content') ||
                                    node.querySelector?.('.courseindex-section') ||
                                    node.querySelector?.('.courseindex-item-content')
                                )) {
                                    shouldEnhance = true;
                                }
                            }
                        });
                    }
                });
                
                if (shouldEnhance) {
                    // Debounce to avoid too many calls
                    clearTimeout(window.sidebarEnhanceTimeout);
                    window.sidebarEnhanceTimeout = setTimeout(function() {
                        runEnhancement();
                    }, 300);
                }
            });
            
            // Start observing the document body for sidebar changes
            const body = document.body;
            if (body) {
                observer.observe(body, {
                    childList: true,
                    subtree: true
                });
            }
            
            // Also observe drawer element specifically
            const drawerElement = document.querySelector('.drawers') || document.body;
            observer.observe(drawerElement, {
                childList: true,
                subtree: true
            });
            
            // Watch for sidebar toggle/open events
            const sidebar = document.querySelector('.theme_remui-drawers-courseindex') || 
                           document.querySelector('#theme_remui-drawers-courseindex');
            if (sidebar) {
                // Re-run when sidebar is toggled
                const checkSidebar = setInterval(function() {
                    if (sidebar.offsetParent !== null) {
                        runEnhancement();
                    }
                }, 1000);
                
                // Clear interval after 30 seconds
                setTimeout(function() {
                    clearInterval(checkSidebar);
                }, 30000);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupEnhancements);
        } else {
            setupEnhancements();
        }
    }

    /**
     * Enhance sidebar with status badges and progress indicators
     */
    function enhanceSidebar() {
        if (isProcessing) {
            return;
        }

        const sidebar = document.querySelector('.theme_remui-drawers-courseindex') || 
                       document.querySelector('#theme_remui-drawers-courseindex');
        
        if (!sidebar) {
            return;
        }

        isProcessing = true;
        addStatusBadgesAndProgress(sidebar);
        isProcessing = false;
    }

    /**
     * Add status badges and progress indicators to sections
     */
    function addStatusBadgesAndProgress(sidebar) {
        const sections = sidebar.querySelectorAll('.courseindex-section');
        
        sections.forEach((section, index) => {
            // Skip first section (Introduction/General) - it's hidden
            if (index === 0) {
                return;
            }

            const sectionTitle = section.querySelector('.courseindex-section-title');
            if (!sectionTitle) {
                return;
            }

            // Get section number
            let sectionNumber = sectionTitle.getAttribute('data-section-number');
            if (!sectionNumber || sectionNumber === '0' || sectionNumber === '1') {
                sectionNumber = section.getAttribute('data-number');
            }
            if (!sectionNumber || sectionNumber === '0' || sectionNumber === '1') {
                sectionNumber = (index + 1).toString();
            }
            
            // Count activities - search in sectionContent (works even if collapsed)
            // IMPORTANT: On activity pages, sectionContent might be in a collapsed div, so we need to search the entire section
            let totalActivities = 0;
            let completedActivities = 0;
            let moduleCount = 0;

            // Try multiple approaches to find section content (for both course view and activity pages)
            let sectionContent = section.querySelector('.courseindex-item-content');
            let sectionContentList = null;
            
            if (sectionContent) {
                sectionContentList = sectionContent.querySelector('.courseindex-sectioncontent');
            }
            
            // Fallback: try finding sectioncontent directly in section (for activity pages)
            if (!sectionContentList) {
                sectionContentList = section.querySelector('.courseindex-sectioncontent');
            }
            
            // Another fallback: look for any ul with courseindex-sectioncontent class anywhere in section
            if (!sectionContentList) {
                const allLists = section.querySelectorAll('ul');
                for (let i = 0; i < allLists.length; i++) {
                    if (allLists[i].classList && allLists[i].classList.contains('courseindex-sectioncontent')) {
                        sectionContentList = allLists[i];
                        break;
                    }
                }
            }
            
            if (sectionContentList) {
                // Get direct children only (top-level items in this section)
                const childNodes = Array.from(sectionContentList.children);
                const activityItems = childNodes.filter(node => {
                    // Check if it's an li element with courseindex-item class
                    return node.tagName === 'LI' && node.classList && node.classList.contains('courseindex-item');
                });

                activityItems.forEach(activity => {
                        // Check if this activity is a subsection/module
                        // Subsections have the 'hasdelegatedsection' class OR contain a nested section
                        const hasDelegatedClass = activity.classList.contains('hasdelegatedsection');
                        const hasNestedSection = activity.querySelector('.courseindex-section') !== null;
                        const hasSectionDataAttr = activity.querySelector('[data-for="section"]') !== null;
                        const isSubsection = hasDelegatedClass || hasNestedSection || hasSectionDataAttr;
                        
                        if (isSubsection) {
                            moduleCount++;
                            
                            // Count activities inside this subsection
                            const subsectionSectionContent = activity.querySelector('.courseindex-item-content .courseindex-sectioncontent');
                            if (subsectionSectionContent) {
                                const subsectionActivities = subsectionSectionContent.querySelectorAll('li.courseindex-item');
                                totalActivities += subsectionActivities.length;
                                
                                // Count completed activities in subsection
                                subsectionActivities.forEach(subActivity => {
                                    const completionInfo = subActivity.querySelector('.completioninfo');
                                    if (completionInfo) {
                                        const completionValue = completionInfo.getAttribute('data-value');
                                        if (completionValue === '1' || completionValue === '2') {
                                            completedActivities++;
                                        }
                                    }
                                });
                            }
                        } else {
                            // Regular activity (not a subsection)
                            totalActivities++;
                            
                            // Check completion status
                            const completionInfo = activity.querySelector('.completioninfo');
                            if (completionInfo) {
                                const completionValue = completionInfo.getAttribute('data-value');
                                if (completionValue === '1' || completionValue === '2') {
                                    completedActivities++;
                                }
                            }
                        }
                });
            } else if (sectionContent) {
                // Fallback: count all activities including nested ones
                const allActivityItems = sectionContent.querySelectorAll('li.courseindex-item');
                
                // Filter to only top-level activities (direct children of .courseindex-sectioncontent)
                const topLevelActivities = Array.from(allActivityItems).filter(activity => {
                    const parent = activity.parentElement;
                    return parent && parent.classList && parent.classList.contains('courseindex-sectioncontent');
                });

                topLevelActivities.forEach(activity => {
                    // Check if this activity is a subsection/module
                    const hasDelegatedClass = activity.classList.contains('hasdelegatedsection');
                    const hasNestedSection = activity.querySelector('.courseindex-section') !== null;
                    const hasSectionDataAttr = activity.querySelector('[data-for="section"]') !== null;
                    const isSubsection = hasDelegatedClass || hasNestedSection || hasSectionDataAttr;
                    
                    if (isSubsection) {
                        moduleCount++;
                        
                        // Count activities inside this subsection
                        const subsectionSectionContent = activity.querySelector('.courseindex-item-content .courseindex-sectioncontent');
                        if (subsectionSectionContent) {
                            const subsectionActivities = subsectionSectionContent.querySelectorAll('li.courseindex-item');
                            totalActivities += subsectionActivities.length;
                            
                            // Count completed activities in subsection
                            subsectionActivities.forEach(subActivity => {
                                const completionInfo = subActivity.querySelector('.completioninfo');
                                if (completionInfo) {
                                    const completionValue = completionInfo.getAttribute('data-value');
                                    if (completionValue === '1' || completionValue === '2') {
                                        completedActivities++;
                                    }
                                }
                            });
                        }
                    } else {
                        // Regular activity (not a subsection)
                        totalActivities++;
                        
                        // Check completion status
                        const completionInfo = activity.querySelector('.completioninfo');
                        if (completionInfo) {
                            const completionValue = completionInfo.getAttribute('data-value');
                            if (completionValue === '1' || completionValue === '2') {
                                completedActivities++;
                            }
                        }
                    }
                });
            }

            // If still 0, try a more comprehensive search (needed for activity pages)
            if (totalActivities === 0 && moduleCount === 0) {
                // Try searching directly in section without requiring sectionContent
                const directSectionContent = section.querySelector('.courseindex-sectioncontent');
                if (directSectionContent) {
                    const childNodes = Array.from(directSectionContent.children);
                    const activityItems = childNodes.filter(node => {
                        return node.tagName === 'LI' && node.classList && node.classList.contains('courseindex-item');
                    });
                    
                    activityItems.forEach(activity => {
                        const hasDelegatedClass = activity.classList.contains('hasdelegatedsection');
                        const hasNestedSection = activity.querySelector('.courseindex-section') !== null;
                        const hasSectionDataAttr = activity.querySelector('[data-for="section"]') !== null;
                        const isSubsection = hasDelegatedClass || hasNestedSection || hasSectionDataAttr;
                        
                        if (isSubsection) {
                            moduleCount++;
                            
                            // Count activities inside this subsection - try multiple selectors
                            let subsectionSectionContent = activity.querySelector('.courseindex-item-content .courseindex-sectioncontent');
                            if (!subsectionSectionContent) {
                                subsectionSectionContent = activity.querySelector('.courseindex-sectioncontent');
                            }
                            if (!subsectionSectionContent) {
                                subsectionSectionContent = activity.querySelector('ul[class*="sectioncontent"]');
                            }
                            
                            if (subsectionSectionContent) {
                                const subsectionActivities = subsectionSectionContent.querySelectorAll('li.courseindex-item');
                                totalActivities += subsectionActivities.length;
                                
                                subsectionActivities.forEach(subActivity => {
                                    const completionInfo = subActivity.querySelector('.completioninfo');
                                    if (completionInfo) {
                                        const completionValue = completionInfo.getAttribute('data-value');
                                        if (completionValue === '1' || completionValue === '2') {
                                            completedActivities++;
                                        }
                                    }
                                });
                            }
                        } else {
                            totalActivities++;
                            const completionInfo = activity.querySelector('.completioninfo');
                            if (completionInfo) {
                                const completionValue = completionInfo.getAttribute('data-value');
                                if (completionValue === '1' || completionValue === '2') {
                                    completedActivities++;
                                }
                            }
                        }
                    });
                }
            }

            // Check if this is a subsection (nested section)
            const isSubsection = section.closest('.courseindex-sectioncontent') !== null ||
                               section.closest('.courseindex-item.hasdelegatedsection') !== null ||
                               section.parentElement?.classList.contains('courseindex-sectioncontent');
            
            // Always show section number in badge (only for main sections, not subsections)
            if (!isSubsection) {
                const statusBadge = sectionTitle.querySelector('.courseindex-section-status-badge');
                if (statusBadge) {
                    statusBadge.innerHTML = `<div class="status-badge status-incomplete">${sectionNumber}</div>`;
                }
            }
            
            // For subsections, change book icon to stacked layers icon (fa-layer-group)
            // For main sections, ensure book icon is correct (fa-book-open)
            const bookIconContainer = sectionTitle.querySelector('.courseindex-section-book-icon');
            if (bookIconContainer) {
                bookIconContainer.style.display = 'flex'; // Ensure it's visible
                let bookIcon = bookIconContainer.querySelector('i');
                
                if (isSubsection) {
                    // Subsections get layer-group icon
                    if (!bookIcon) {
                        bookIcon = document.createElement('i');
                        bookIconContainer.appendChild(bookIcon);
                    }
                    bookIcon.className = 'fa fa-layer-group';
                    bookIcon.setAttribute('aria-hidden', 'true');
                } else {
                    // Main sections get book-open icon
                    if (!bookIcon) {
                        bookIcon = document.createElement('i');
                        bookIconContainer.appendChild(bookIcon);
                    }
                    bookIcon.className = 'fa fa-book-open';
                    bookIcon.setAttribute('aria-hidden', 'true');
                }
            }

            // Add progress info - activities on left, modules on right
            const progressElement = sectionTitle.querySelector('.courseindex-section-progress');
            if (progressElement) {
                // Check if this is a subsection (nested section)
                const isSubsection = section.closest('.courseindex-sectioncontent') !== null ||
                                   section.closest('.courseindex-item.hasdelegatedsection') !== null ||
                                   section.parentElement?.classList.contains('courseindex-sectioncontent');
                
                if (isSubsection) {
                    // For subsections: show only activities count (no modules)
                    const activitiesText = `${completedActivities}/${totalActivities} activities`;
                    progressElement.innerHTML = `
                        <span class="progress-activities">${activitiesText}</span>
                    `;
                } else {
                    // For main sections: show activities and modules
                    const activitiesText = `${completedActivities}/${totalActivities} activities`;
                    const modulesText = `${moduleCount} ${moduleCount === 1 ? 'module' : 'modules'}`;
                    progressElement.innerHTML = `
                        <span class="progress-activities">${activitiesText}</span>
                        <span class="progress-modules">${modulesText}</span>
                    `;
                }
                progressElement.style.display = 'flex';
                progressElement.style.visibility = 'visible';
                progressElement.style.opacity = '1';
            } else {
                // Try to create it if it doesn't exist
                const titleContent = sectionTitle.querySelector('.courseindex-section-title-content');
                if (titleContent) {
                    const newProgressElement = document.createElement('div');
                    newProgressElement.className = 'courseindex-section-progress';
                    newProgressElement.setAttribute('data-section-id', section.getAttribute('data-id') || '');
                    titleContent.appendChild(newProgressElement);
                    
                    // Check if this is a subsection (nested section)
                    const isSubsectionForNew = section.closest('.courseindex-sectioncontent') !== null ||
                                             section.closest('.courseindex-item.hasdelegatedsection') !== null ||
                                             section.parentElement?.classList.contains('courseindex-sectioncontent');
                    
                    if (isSubsectionForNew) {
                        // For subsections: show only activities count (no modules)
                        const activitiesText = `${completedActivities}/${totalActivities} activities`;
                        newProgressElement.innerHTML = `
                            <span class="progress-activities">${activitiesText}</span>
                        `;
                    } else {
                        // For main sections: show activities and modules
                        const activitiesText = `${completedActivities}/${totalActivities} activities`;
                        const modulesText = `${moduleCount} ${moduleCount === 1 ? 'module' : 'modules'}`;
                        newProgressElement.innerHTML = `
                            <span class="progress-activities">${activitiesText}</span>
                            <span class="progress-modules">${modulesText}</span>
                        `;
                    }
                    newProgressElement.style.display = 'flex';
                }
            }
        });
    }

    /**
     * Add completion classes to activity items based on completioninfo
     */
    function addCompletionClasses() {
        const activities = document.querySelectorAll('.courseindex-sectioncontent .courseindex-item');
        activities.forEach(activity => {
            const completionInfo = activity.querySelector('.completioninfo');
            if (completionInfo) {
                const completionValue = completionInfo.getAttribute('data-value');
                if (completionValue === '1' || completionValue === '2') {
                    activity.classList.add('completed');
                    activity.setAttribute('data-completion-state', completionValue);
                } else {
                    activity.classList.remove('completed');
                    activity.removeAttribute('data-completion-state');
                }
            }
        });
    }

    // Run completion class updates
    function runCompletionUpdates() {
        addCompletionClasses();
        setTimeout(addCompletionClasses, 500);
        setTimeout(addCompletionClasses, 1000);
        setTimeout(addCompletionClasses, 2000);
    }

    // Initialize
    init();
    
    // Add completion classes on load and periodically
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runCompletionUpdates);
    } else {
        runCompletionUpdates();
    }
    
    // Update completion classes when sidebar is enhanced
    const originalEnhanceSidebar = window.enhanceSidebar || enhanceSidebar;
    window.enhanceSidebar = function() {
        if (typeof originalEnhanceSidebar === 'function') {
            originalEnhanceSidebar();
        }
        addCompletionClasses();
    };
    
    // Re-run on page navigation (for Moodle's AJAX navigation)
    window.addEventListener('popstate', function() {
        setTimeout(function() {
            enhanceSidebar();
            runCompletionUpdates();
        }, 500);
    });
    
    // Re-run after page load completes (for activity pages)
    window.addEventListener('load', function() {
        setTimeout(function() {
            enhanceSidebar();
            runCompletionUpdates();
        }, 1000);
    });
    
    // Expose function globally for manual triggering if needed
    window.refreshCourseSidebar = function() {
        enhanceSidebar();
        runCompletionUpdates();
    };
})();
