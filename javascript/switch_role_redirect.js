/**
 * Redirect "Switch role to..." menu item to Teacher View Student Dashboard
 * This intercepts the default Moodle switch role behavior and redirects to
 * the custom student selection page in remui_kids theme.
 */
(function() {
    'use strict';

    /**
     * Find and modify the switch role link
     */
    function interceptSwitchRoleLink() {
        // Wait for the user menu to be fully loaded
        const userMenu = document.getElementById('user-action-menu');
        if (!userMenu) {
            return;
        }

        // Find all links in the user menu
        const menuLinks = userMenu.querySelectorAll('a');
        
        menuLinks.forEach(function(link) {
            // Check if this is the "Switch role to..." link
            const linkText = link.textContent.trim().toLowerCase();
            const href = link.getAttribute('href') || '';
            const dataTitleIdentifier = link.getAttribute('data-title-identifier') || '';
            
            // Check multiple ways to identify the switch role link:
            // 1. URL contains switchrole
            // 2. Text contains "switch role"
            // 3. Data attribute contains switchroleto
            const isSwitchRoleLink = href.includes('switchrole') || 
                                    linkText.includes('switch role') || 
                                    linkText.includes('switchroleto') ||
                                    dataTitleIdentifier.includes('switchroleto');
            
            if (isSwitchRoleLink) {
                // Check if we've already modified this link
                if (link.hasAttribute('data-switch-role-redirected')) {
                    return;
                }
                
                // Mark as processed
                link.setAttribute('data-switch-role-redirected', 'true');
                
                // Remove any existing click listeners by cloning the node
                const newLink = link.cloneNode(true);
                link.parentNode.replaceChild(newLink, link);
                
                // Add click event listener to the new link
                newLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Redirect to teacher view student dashboard
                    const redirectUrl = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) 
                        ? M.cfg.wwwroot + '/local/teacherviewstudent/index.php'
                        : '/local/teacherviewstudent/index.php';
                    
                    window.location.href = redirectUrl;
                    
                    return false;
                });
                
                // Also update the href directly as a fallback
                const redirectUrl = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) 
                    ? M.cfg.wwwroot + '/local/teacherviewstudent/index.php'
                    : '/local/teacherviewstudent/index.php';
                newLink.href = redirectUrl;
            }
        });
    }

    /**
     * Use MutationObserver to watch for dynamically added menu items
     */
    function observeMenuChanges() {
        const userMenu = document.getElementById('user-action-menu');
        if (!userMenu) {
            return;
        }

        const observer = new MutationObserver(function(mutations) {
            interceptSwitchRoleLink();
        });

        observer.observe(userMenu, {
            childList: true,
            subtree: true
        });
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            interceptSwitchRoleLink();
            observeMenuChanges();
        });
    } else {
        interceptSwitchRoleLink();
        observeMenuChanges();
    }

    // Also run after a short delay to catch late-loading menus
    setTimeout(function() {
        interceptSwitchRoleLink();
    }, 500);

    // Run when user menu is opened (if using Bootstrap dropdown)
    document.addEventListener('click', function(e) {
        const toggle = e.target.closest('#user-menu-toggle');
        if (toggle) {
            setTimeout(function() {
                interceptSwitchRoleLink();
            }, 100);
        }
    });
})();


