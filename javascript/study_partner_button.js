/**
 * Study Partner Button Injection
 * Adds Study Partner button to header on course pages, section pages, and activity pages
 */
(function() {
    'use strict';

    /**
     * Check if current page is a course page, section page, or activity page
     */
    function isTargetPage() {
        var path = window.location.pathname;
        var href = window.location.href;
        
        // Course pages
        var isCoursePage = path.indexOf('/course/view.php') !== -1 || href.indexOf('/course/view.php') !== -1;
        
        // Section pages
        var isSectionPage = path.indexOf('/course/section.php') !== -1 || href.indexOf('/course/section.php') !== -1;
        
        // Activity pages (all mod activities)
        var isActivityPage = (path.indexOf('/mod/') !== -1 && path.indexOf('/view.php') !== -1) ||
                             (href.indexOf('/mod/') !== -1 && href.indexOf('/view.php') !== -1);
        
        // Lesson pages specifically
        var isLessonPage = path.indexOf('/mod/lesson/') !== -1 || href.indexOf('/mod/lesson/') !== -1;
        
        var result = isCoursePage || isSectionPage || isActivityPage || isLessonPage;
        
        // Debug logging
        if (window.console && console.log) {
            console.log('[Study Partner] Page check:', {
                path: path,
                href: href,
                isCoursePage: isCoursePage,
                isSectionPage: isSectionPage,
                isActivityPage: isActivityPage,
                isLessonPage: isLessonPage,
                result: result
            });
        }
        
        return result;
    }


    function getBaseUrl() {
        // Try Moodle framework first
        if (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) {
            return M.cfg.wwwroot;
        }
        // Fallback: extract from current location
        var path = window.location.pathname;
        var parts = path.split('/');
        // Remove empty parts and find moodle root
        var moodleRoot = '';
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] === 'course' || parts[i] === 'mod' || parts[i] === 'local') {
                moodleRoot = parts.slice(0, i).join('/');
                break;
            }
        }
        return window.location.origin + (moodleRoot || '');
    }

    /**
     * Create the Study Partner button
     */
    function createStudyPartnerButton() {
        var button = document.createElement('a');
        var baseUrl = getBaseUrl();
        button.href = baseUrl + '/local/studypartner/index.php';
        button.className = 'btn btn-primary';
        button.title = 'Study Partner';
        button.setAttribute('aria-label', 'Study Partner');
        
        var icon = document.createElement('i');
        icon.className = 'fa fa-robot me-2';
        icon.setAttribute('aria-hidden', 'true');
        
        button.appendChild(icon);
        button.appendChild(document.createTextNode(' Study Partner'));
        
        return button;
    }

    /**
     * Inject button into header actions container
     */
    function injectButton() {
        // Check if we're on a target page
        if (!isTargetPage()) {
            if (window.console && console.log) {
                console.log('[Study Partner] Not a target page, skipping injection');
            }
            return;
        }

        // Check if button already exists anywhere
        var existingButton = document.querySelector('a[href*="studypartner"], a[href*="/local/studypartner"]');
        if (existingButton) {
            if (window.console && console.log) {
                console.log('[Study Partner] Button already exists, skipping');
            }
            return; // Button already exists
        }

        // Try multiple strategies to find the header container
        var headerActionsContainer = null;
        var pageHeader = document.querySelector('#page-header');
        
        // Strategy 1: Direct selector
        headerActionsContainer = document.querySelector('[data-region="header-actions-container"]');
        
        // Strategy 2: Class selector
        if (!headerActionsContainer) {
            headerActionsContainer = document.querySelector('.header-actions-container');
        }
        
        // Strategy 3: Find within page header
        if (!headerActionsContainer && pageHeader) {
            headerActionsContainer = pageHeader.querySelector('[data-region="header-actions-container"]');
            if (!headerActionsContainer) {
                headerActionsContainer = pageHeader.querySelector('.header-actions-container');
            }
        }
        
        // Strategy 4: Find header wrapper and create container if needed
        if (!headerActionsContainer && pageHeader) {
            var headerWrapper = pageHeader.querySelector('.d-flex.align-items-center');
            if (!headerWrapper) {
                // Try alternative wrapper selectors
                headerWrapper = pageHeader.querySelector('.header-wrapper .d-flex');
            }
            if (!headerWrapper) {
                headerWrapper = pageHeader.querySelector('.w-100 .d-flex.align-items-center');
            }
            
            if (headerWrapper) {
                // Check if container exists
                headerActionsContainer = headerWrapper.querySelector('[data-region="header-actions-container"]');
                if (!headerActionsContainer) {
                    headerActionsContainer = headerWrapper.querySelector('.header-actions-container');
                }
                
                // Create container if it doesn't exist
                if (!headerActionsContainer) {
                    headerActionsContainer = document.createElement('div');
                    headerActionsContainer.className = 'header-actions-container ms-auto';
                    headerActionsContainer.setAttribute('data-region', 'header-actions-container');
                    headerWrapper.appendChild(headerActionsContainer);
                    if (window.console && console.log) {
                        console.log('[Study Partner] Created header actions container');
                    }
                }
            }
        }
        
        // Strategy 5: If page header exists but no container, try to add to header directly
        if (!headerActionsContainer && pageHeader) {
            // Try to find or create a wrapper div
            var lastChild = pageHeader.lastElementChild;
            if (lastChild) {
                var flexContainer = lastChild.querySelector('.d-flex.align-items-center');
                if (flexContainer) {
                    headerActionsContainer = flexContainer.querySelector('[data-region="header-actions-container"]');
                    if (!headerActionsContainer) {
                        headerActionsContainer = document.createElement('div');
                        headerActionsContainer.className = 'header-actions-container ms-auto';
                        headerActionsContainer.setAttribute('data-region', 'header-actions-container');
                        flexContainer.appendChild(headerActionsContainer);
                    }
                }
            }
        }

        if (headerActionsContainer) {
            // Double-check button doesn't exist in this container
            var existingInContainer = headerActionsContainer.querySelector('a[href*="studypartner"]');
            if (existingInContainer) {
                if (window.console && console.log) {
                    console.log('[Study Partner] Button already exists in container');
                }
                return;
            }

            // Create button wrapper
            var buttonWrapper = document.createElement('div');
            buttonWrapper.className = 'header-action ms-2';
            buttonWrapper.appendChild(createStudyPartnerButton());
            
            // Add to container
            headerActionsContainer.appendChild(buttonWrapper);
            
            if (window.console && console.log) {
                console.log('[Study Partner] Button injected successfully');
            }
        } else {
            if (window.console && console.warn) {
                console.warn('[Study Partner] Could not find header actions container. Page header exists:', !!pageHeader);
                if (pageHeader) {
                    console.warn('[Study Partner] Page header HTML:', pageHeader.outerHTML.substring(0, 500));
                }
            }
        }
    }

    /**
     * Initialize when DOM is ready
     */
    function init() {
        if (window.console && console.log) {
            console.log('[Study Partner] Initializing...');
        }
        
        // Try immediately
        injectButton();
        
        // Also try after delays in case header loads asynchronously
        setTimeout(injectButton, 100);
        setTimeout(injectButton, 300);
        setTimeout(injectButton, 500);
        setTimeout(injectButton, 1000);
        setTimeout(injectButton, 2000);
        
        // Listen for DOM changes (for dynamic content)
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations) {
                // Check if header was added or modified
                var hasHeaderChanges = false;
                for (var i = 0; i < mutations.length; i++) {
                    var mutation = mutations[i];
                    if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                        hasHeaderChanges = true;
                        break;
                    }
                }
                
                if (hasHeaderChanges) {
                    var headerActionsContainer = document.querySelector('[data-region="header-actions-container"]');
                    var existingButton = document.querySelector('a[href*="studypartner"]');
                    if (headerActionsContainer && !existingButton) {
                        if (window.console && console.log) {
                            console.log('[Study Partner] Header changed, attempting injection');
                        }
                        injectButton();
                    }
                }
            });
            
            // Observe the page header for changes
            var pageHeader = document.querySelector('#page-header');
            if (pageHeader) {
                observer.observe(pageHeader, {
                    childList: true,
                    subtree: true,
                    attributes: false
                });
                if (window.console && console.log) {
                    console.log('[Study Partner] MutationObserver attached to page header');
                }
            } else {
                // If page header doesn't exist yet, observe body
                var body = document.body;
                if (body) {
                    observer.observe(body, {
                        childList: true,
                        subtree: true
                    });
                    if (window.console && console.log) {
                        console.log('[Study Partner] MutationObserver attached to body (waiting for header)');
                    }
                }
            }
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Also initialize when YUI/Moodle is ready
    if (typeof Y !== 'undefined') {
        Y.use('moodle-core-event', function() {
            Y.on('domready', init);
        });
    }

    // Fallback: try after page load
    window.addEventListener('load', function() {
        setTimeout(injectButton, 200);
    });
})();


