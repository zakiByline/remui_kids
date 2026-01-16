/**
 * Google Translate Widget Initialization
 * Loads and initializes Google Translate widget on all pages (except login)
 */

(function() {
    'use strict';
    
    // Suppress tracking prevention warnings from Google Translate
    // These are harmless - browser is just blocking storage access
    (function() {
        const originalError = console.error;
        const originalWarn = console.warn;
        
        console.error = function(...args) {
            const message = args.join(' ');
            // Suppress Google Translate tracking prevention errors
            if (message.indexOf('Tracking Prevention blocked') !== -1 &&
                (message.indexOf('translate.google.com') !== -1 ||
                 message.indexOf('translate.googleapis.com') !== -1)) {
                return; // Suppress this error
            }
            originalError.apply(console, args);
        };
        
        console.warn = function(...args) {
            const message = args.join(' ');
            // Suppress Google Translate tracking prevention warnings
            if (message.indexOf('Tracking Prevention blocked') !== -1 &&
                (message.indexOf('translate.google.com') !== -1 ||
                 message.indexOf('translate.googleapis.com') !== -1)) {
                return; // Suppress this warning
            }
            originalWarn.apply(console, args);
        };
    })();
    
    // Function to initialize Google Translate widget
    function initGoogleTranslate() {
        // Check if we're on login page - don't initialize
        var isLoginPage = document.body.classList.contains('pagelayout-login') ||
                         window.location.pathname.indexOf('/login/') !== -1 ||
                         window.location.pathname.indexOf('login.php') !== -1;
        
        if (isLoginPage) {
            return; // Don't initialize on login page
        }
        
        // Create the container div if it doesn't exist
        var container = document.getElementById('google_translate_element');
        if (!container) {
            container = document.createElement('div');
            container.id = 'google_translate_element';
            container.style.display = 'inline-block';
            
            // Try to find navbar or header to insert the translator
            var navbar = document.querySelector('.navbar-nav, .navbar-header, header nav, [role="navigation"]');
            if (navbar) {
                // Insert at the end of navbar
                navbar.appendChild(container);
            } else {
                // Insert in body if navbar not found
                document.body.insertBefore(container, document.body.firstChild);
            }
        }
        
        // Load Google Translate script if not already loaded
        if (typeof google === 'undefined' || !google.translate) {
            var script = document.createElement('script');
            script.type = 'text/javascript';
            script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
            script.async = true;
            
            // Define the callback function
            window.googleTranslateElementInit = function() {
                if (container && typeof google !== 'undefined' && google.translate) {
                    new google.translate.TranslateElement({
                        pageLanguage: 'en',
                        includedLanguages: 'ar,en,es,fr,de,it,pt,ru,zh-CN,ja,ko,hi,tr,vi',
                        layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
                        autoDisplay: false,
                        multilanguagePage: true
                    }, 'google_translate_element');
                }
            };
            
            // Append script to head
            var head = document.getElementsByTagName('head')[0] || document.body;
            head.appendChild(script);
        } else {
            // Google Translate already loaded, initialize directly
            if (container) {
                try {
                    new google.translate.TranslateElement({
                        pageLanguage: 'en',
                        includedLanguages: 'ar,en,es,fr,de,it,pt,ru,zh-CN,ja,ko,hi,tr,vi',
                        layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
                        autoDisplay: false,
                        multilanguagePage: true
                    }, 'google_translate_element');
                } catch (e) {
                    console.error('Error initializing Google Translate:', e);
                }
            }
        }
        
        // Ensure the widget is visible (not hidden by CSS)
        if (container) {
            container.style.display = 'inline-block';
            container.style.visibility = 'visible';
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initGoogleTranslate, 500);
        });
    } else {
        setTimeout(initGoogleTranslate, 500);
    }
    
    // Also try after window load
    window.addEventListener('load', function() {
        setTimeout(initGoogleTranslate, 1000);
    });
})();
