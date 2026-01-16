/**
 * Navbar Scroll Fix - Ensures navbar stays visible and adds scroll effects
 * 
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const navbar = document.querySelector('nav.navbar');
        
        if (!navbar) {
            console.warn('Navbar not found - scroll fix not applied');
            return;
        }

        // Force navbar to be fixed
        ensureNavbarFixed(navbar);
        
        // Add scroll effect
        addScrollEffect(navbar);
        
        // Adjust page padding
        adjustPagePadding(navbar);
    }

    /**
     * Ensure navbar has proper fixed positioning
     */
    function ensureNavbarFixed(navbar) {
        // Force fixed positioning
        navbar.style.position = 'fixed';
        navbar.style.top = '0';
        navbar.style.left = '0';
        navbar.style.right = '0';
        navbar.style.width = '100%';
        navbar.style.zIndex = '1100';
        
        console.log('Navbar positioning fixed');
    }

    /**
     * Add scroll effect to navbar
     */
    function addScrollEffect(navbar) {
        let lastScrollTop = 0;
        let ticking = false;

        window.addEventListener('scroll', function() {
            lastScrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    updateNavbar(navbar, lastScrollTop);
                    ticking = false;
                });
                
                ticking = true;
            }
        });
    }

    /**
     * Update navbar based on scroll position
     */
    function updateNavbar(navbar, scrollTop) {
        if (scrollTop > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    }

    /**
     * Adjust page padding to prevent content hiding under navbar
     * Only applies to custom layout pages (admin/teacher pages)
     */
    function adjustPagePadding(navbar) {
        const navbarHeight = navbar.offsetHeight;
        
        // Check if this is a custom layout page (admin/teacher pages)
        const isCustomLayoutPage = document.querySelector('.teacher-main-content, .admin-main-content, .teacher-css-wrapper, .admin-sidebar-wrapper');
        
        // Only add 60px padding to custom layout pages (admin/teacher)
        if (isCustomLayoutPage) {
            // Adjust custom content areas for admin/teacher pages
            const customAreas = document.querySelectorAll('.teacher-main-content, .admin-main-content');
            customAreas.forEach(function(area) {
                const currentPaddingTop = parseInt(window.getComputedStyle(area).paddingTop) || 0;
                if (currentPaddingTop < 60) {
                    area.style.paddingTop = '60px';
                    console.log('Custom layout page padding adjusted to: 60px');
                }
            });
        } else {
            console.log('Standard/Dashboard page - no additional padding needed');
        }
    }

    /**
     * Handle window resize
     */
    window.addEventListener('resize', function() {
        const navbar = document.querySelector('nav.navbar');
        if (navbar) {
            adjustPagePadding(navbar);
        }
    });

    /**
     * High School Dashboard Specific Fix
     */
    function fixHighSchoolDashboard() {
        // Target all high school dashboard elements
        const highSchoolDashboards = document.querySelectorAll('.highschool-dashboard, .analytics-dashboard, .dashboard-metrics');
        highSchoolDashboards.forEach(dashboard => {
            dashboard.style.marginTop = '60px';
            dashboard.style.paddingTop = '0px';
            console.log('Applied high school dashboard margin fix to:', dashboard.className);
        });
        
        // Also target enhanced sidebar specific elements
        const enhancedSidebarDashboards = document.querySelectorAll('body.has-student-sidebar.has-enhanced-sidebar .highschool-dashboard, body.has-student-sidebar.has-enhanced-sidebar .analytics-dashboard, body.has-student-sidebar.has-enhanced-sidebar .dashboard-metrics');
        enhancedSidebarDashboards.forEach(dashboard => {
            dashboard.style.marginTop = '60px';
            dashboard.style.paddingTop = '0px';
            console.log('Applied enhanced sidebar high school dashboard margin fix to:', dashboard.className);
        });
        
        // Fix sidebar cutting from top
        const sidebars = document.querySelectorAll('.student-sidebar.enhanced-sidebar, body.has-student-sidebar.has-enhanced-sidebar .student-sidebar, body.has-enhanced-sidebar.student-sidebar .student-sidebar');
        sidebars.forEach(sidebar => {
            sidebar.style.marginTop = '60px';
            sidebar.style.paddingTop = '10px';
            console.log('Applied sidebar margin fix to:', sidebar.className);
        });
    }

    // Apply fix on page load
    document.addEventListener('DOMContentLoaded', fixHighSchoolDashboard);
    
    // Apply fix after a short delay to ensure all CSS is loaded
    setTimeout(fixHighSchoolDashboard, 100);

})();

