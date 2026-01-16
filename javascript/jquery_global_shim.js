/**
 * jQuery Global Shim
 * 
 * This script ensures that jQuery's $ is available globally even after
 * jquery-private calls noConflict(true). This fixes the "$ is not a function"
 * error that occurs when AMD modules try to use $ directly.
 * 
 * This script MUST be loaded synchronously in the <head> before RequireJS loads.
 */
(function() {
    'use strict';
    
    // Ensure $ is available globally if jQuery is loaded but $ is not defined
    // This handles cases where jQuery is loaded via AMD/RequireJS but $ is not exposed
    function ensureJQueryGlobal() {
        // Check if jQuery exists in global scope
        if (typeof window.jQuery !== 'undefined' && window.jQuery !== null && typeof window.jQuery === 'function') {
            // Always restore $ to jQuery, regardless of current state
            // This is aggressive but necessary to fix the "$ is not a function" error
            window.$ = window.jQuery;
        }
    }
    
    // Try immediately (in case jQuery is already loaded)
    ensureJQueryGlobal();
    
    // Hook into RequireJS to ensure jQuery exposes $ globally when loaded
    // This runs BEFORE jquery-private calls noConflict
    if (typeof require !== 'undefined' && typeof require.config === 'function') {
        // Pre-load jQuery to ensure it's available early
        try {
            require(['jquery'], function(jq) {
                if (jq && typeof jq === 'function') {
                    // Set both jQuery and $ BEFORE jquery-private can remove them
                    window.jQuery = window.jQuery || jq;
                    window.$ = window.$ || jq;
                }
            });
        } catch(e) {
            // RequireJS not ready yet, will try again
        }
    }
    
    // Also try after DOM is ready (in case jQuery loads later)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureJQueryGlobal);
    } else {
        // DOM already loaded, try immediately
        ensureJQueryGlobal();
    }
    
    // Try multiple times with increasing delays to catch late-loading jQuery
    setTimeout(ensureJQueryGlobal, 10);
    setTimeout(ensureJQueryGlobal, 50);
    setTimeout(ensureJQueryGlobal, 100);
    setTimeout(ensureJQueryGlobal, 200);
    setTimeout(ensureJQueryGlobal, 500);
    
    // Also try after window load
    if (window.addEventListener) {
        window.addEventListener('load', ensureJQueryGlobal);
    }
    
    // Continuously monitor for jQuery availability (last resort)
    // This catches cases where jQuery loads but $ gets removed by noConflict
    // CRITICAL: Keep checking even after noConflict because some code needs $
    // Use a faster interval (20ms) to catch removal quickly (matching inline shim)
    var checkCount = 0;
    var maxChecks = 500; // Check for 10 seconds (500 * 20ms = 10s)
    var checkInterval = setInterval(function() {
        checkCount++;
        ensureJQueryGlobal();
        // Keep checking as long as jQuery exists, even if $ keeps getting removed
        // Only stop if jQuery itself is gone or we've checked enough times
        if (checkCount >= maxChecks || (typeof window.jQuery === 'undefined' && typeof window.$ === 'undefined')) {
            clearInterval(checkInterval);
        }
    }, 20); // Check every 20ms for faster response (matching inline shim)
    
    // Aggressively intercept jQuery's noConflict to preserve $ even after it's called
    // This is critical because jquery-private calls noConflict(true) which removes $
    function interceptNoConflict() {
        if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && window.jQuery.fn.jquery) {
            if (!window.jQuery.noConflict._intercepted) {
                var originalNoConflict = window.jQuery.noConflict;
                window.jQuery.noConflict = function(removeAll) {
                    var result = originalNoConflict.apply(this, arguments);
                    // Immediately restore $ after noConflict if jQuery still exists
                    if (typeof window.jQuery !== 'undefined') {
                        window.$ = window.jQuery;
                    }
                    // Also restore it after a microtask to catch any async removal
                    setTimeout(function() {
                        if (typeof window.jQuery !== 'undefined') {
                            window.$ = window.jQuery;
                        }
                    }, 0);
                    return result;
                };
                // Mark as intercepted to prevent infinite loops
                window.jQuery.noConflict._intercepted = true;
            }
        }
    }
    
    // Try to intercept noConflict immediately
    interceptNoConflict();
    
    // Also try after a short delay in case jQuery loads later
    setTimeout(interceptNoConflict, 10);
    setTimeout(interceptNoConflict, 50);
    setTimeout(interceptNoConflict, 100);
    
    // Use Object.defineProperty to make $ non-configurable after jQuery loads
    // This prevents jquery-private from deleting it
    function makeDollarNonConfigurable() {
        if (typeof window.jQuery !== 'undefined' && typeof window.$ === 'undefined') {
            try {
                Object.defineProperty(window, '$', {
                    value: window.jQuery,
                    writable: true,
                    enumerable: true,
                    configurable: false  // Prevent deletion by noConflict
                });
            } catch(e) {
                // If defineProperty fails, just assign normally
                window.$ = window.jQuery;
            }
        } else if (typeof window.jQuery !== 'undefined' && typeof window.$ !== 'undefined' && window.$ !== window.jQuery) {
            // $ exists but is not jQuery, update it
            try {
                Object.defineProperty(window, '$', {
                    value: window.jQuery,
                    writable: true,
                    enumerable: true,
                    configurable: false
                });
            } catch(e) {
                window.$ = window.jQuery;
            }
        }
    }
    
    // Try to make $ non-configurable after jQuery loads
    setTimeout(makeDollarNonConfigurable, 200);
    setTimeout(makeDollarNonConfigurable, 500);
    setTimeout(makeDollarNonConfigurable, 1000);
})();

