/**
 * Sidebar Active Item Tracker
 * Automatically highlights the current page/activity in the course sidebar
 * 
 * @package    theme_remui_kids
 * @copyright  2025 KodeIT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    /**
     * Initialize active item tracking
     */
    function initActiveItemTracking() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', trackActiveItem);
        } else {
            trackActiveItem();
        }
    }

    /**
     * Track and highlight the active item
     */
    function trackActiveItem() {
      
        // Get current URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const currentSection = urlParams.get('section');
        const currentId = urlParams.get('id');
        const currentPath = window.location.pathname;
        // Find the sidebar
        const sidebar = document.querySelector('.theme_remui-drawers-courseindex') || 
                       document.querySelector('.drawer.drawer-left');
        

        // Get all sidebar items
        const allItems = sidebar.querySelectorAll('.courseindex-item');
        // Remove all existing active classes first
        allItems.forEach(item => {
            item.classList.remove('active');
            item.removeAttribute('aria-current');
        });

        // Method 1: Match by section number (for course view)
        if (currentSection) {
            const sectionItems = sidebar.querySelectorAll('[data-number="' + currentSection + '"]');
            if (sectionItems.length > 0) {
                sectionItems.forEach(item => {
                    item.classList.add('active');
                    item.setAttribute('aria-current', 'true');
                    
                    // Expand parent sections
                    expandParentSections(item);
                });
                return;
            }
        }

        // Method 2: Match by activity/module ID
        if (currentId) {
            // Check if we're on a module page
            if (currentPath.includes('/mod/')) {
                const moduleItems = sidebar.querySelectorAll('a[href*="id=' + currentId + '"]');
                if (moduleItems.length > 0) {
                    moduleItems.forEach(link => {
                        const item = link.closest('.courseindex-item');
                        if (item) {
                            item.classList.add('active');
                            item.setAttribute('aria-current', 'true');
                            
                            // Expand parent sections
                            expandParentSections(item);
                        }
                    });
                    return;
                }
            }
        }

        // Method 3: Match by URL
        allItems.forEach(item => {
            const links = item.querySelectorAll('a');
            links.forEach(link => {
                const href = link.getAttribute('href');
                if (href && (window.location.href.includes(href) || href.includes(window.location.pathname))) {
                    // Additional check to ensure it's a real match
                    if (href.length > 10) { // Avoid matching very short URLs
                        item.classList.add('active');
                        item.setAttribute('aria-current', 'true');
                        
                        // Expand parent sections
                        expandParentSections(item);
                    }
                }
            });
        });

        // Method 4: Use Moodle's current indicator
        const currentBadges = sidebar.querySelectorAll('.current-badge');
        if (currentBadges.length > 0) {
            currentBadges.forEach(badge => {
                const item = badge.closest('.courseindex-item');
                if (item) {
                    item.classList.add('active');
                    item.setAttribute('aria-current', 'true');
                    
                    // Expand parent sections
                    expandParentSections(item);
                }
            });
        }
    }

    /**
     * Expand all parent sections of an item
     * @param {Element} item - The active item
     */
    function expandParentSections(item) {
        let parent = item.parentElement;
        
        while (parent && parent !== document.body) {
            // If it's a collapsed section, expand it
            if (parent.classList.contains('collapse') && !parent.classList.contains('show')) {
                parent.classList.add('show');
            }
            
            // Find and update chevron icon
            const chevron = parent.previousElementSibling?.querySelector('.courseindex-chevron');
            if (chevron && chevron.classList.contains('collapsed')) {
                chevron.classList.remove('collapsed');
                chevron.setAttribute('aria-expanded', 'true');
            }
            
            parent = parent.parentElement;
        }
    }

    /**
     * Re-track on navigation (for SPA-like behavior)
     */
    window.addEventListener('popstate', function() {
        setTimeout(trackActiveItem, 100);
    });

    // Handle dynamic content loading
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                // Check if sidebar was added
                const hasDrawer = Array.from(mutation.addedNodes).some(node => 
                    node.nodeType === 1 && (
                        node.classList?.contains('theme_remui-drawers-courseindex') ||
                        node.querySelector?.('.theme_remui-drawers-courseindex')
                    )
                );
                
                if (hasDrawer) {
                    setTimeout(trackActiveItem, 300);
                }
            }
        });
    });

    // Observe body for sidebar injection
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Initialize
    initActiveItemTracking();

    // Re-track every 2 seconds in case of dynamic updates (safety net)
    setInterval(function() {
        const activeItems = document.querySelectorAll('.courseindex-item.active, .courseindex-item[aria-current="true"]');
        if (activeItems.length === 0) {
            trackActiveItem();
        }
    }, 2000);

})();








