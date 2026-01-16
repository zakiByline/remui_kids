/**
 * Fix for Google Translate popup interfering with assignment grader
 * Prevents Google Translate from creating popups/modals when applying grades
 * 
 * @package    theme_remui_kids
 * @copyright  2025
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// CRITICAL: Run immediately, even before DOM is ready
(function() {
    'use strict';

    // Check URL immediately - don't wait for body
    var isGraderPage = window.location.href.indexOf('action=grader') !== -1 ||
        window.location.href.indexOf('action=grade') !== -1;

    if (isGraderPage) {
        // Override googleTranslateElementInit IMMEDIATELY before Google Translate can use it
        window.googleTranslateElementInit = function() {
            // Completely prevent initialization - do nothing
            console.log('[Assignment Grader] Google Translate initialization blocked');
            return;
        };

        // Prevent Google Translate script from loading
        var originalAppendChild = Node.prototype.appendChild;
        Node.prototype.appendChild = function(child) {
            if (child && child.tagName === 'SCRIPT' && child.src &&
                (child.src.indexOf('translate.google.com') !== -1 ||
                    child.src.indexOf('translate.googleapis.com') !== -1)) {
                console.log('[Assignment Grader] Blocked Google Translate script:', child.src);
                return child; // Return without appending
            }
            return originalAppendChild.call(this, child);
        };

        // Prevent iframe creation for Google Translate
        var originalCreateElement = Document.prototype.createElement;
        Document.prototype.createElement = function(tagName) {
            var element = originalCreateElement.call(this, tagName);
            if (tagName.toLowerCase() === 'iframe' && isGraderPage) {
                var originalSetAttribute = element.setAttribute;
                element.setAttribute = function(name, value) {
                    if (name === 'src' && value &&
                        (value.indexOf('translate.google.com') !== -1 ||
                            value.indexOf('translate.googleapis.com') !== -1)) {
                        console.log('[Assignment Grader] Blocked Google Translate iframe:', value);
                        return; // Don't set src
                    }
                    return originalSetAttribute.call(this, name, value);
                };
            }
            return element;
        };
    }
})();

(function() {
    'use strict';

    // Check if we're on the assignment grader page
    function isAssignmentGraderPage() {
        // Check body class if available, otherwise check URL
        var bodyCheck = document.body ? document.body.classList.contains('path-mod-assign') : false;
        var urlCheck = window.location.href.indexOf('action=grader') !== -1 ||
            window.location.href.indexOf('action=grade') !== -1;
        return bodyCheck && urlCheck || urlCheck;
    }

    // Hide any Google Translate popups/modals that appear
    function hideGoogleTranslatePopups() {
        var selectors = [
            '.goog-te-menu-frame',
            '.goog-te-banner-frame',
            '.goog-te-menu',
            '.VIpgJd-ZVi9od-l4eHX-hSRGPd',
            '.VIpgJd-ZVi9od-ORHb-OEVmcd',
            '.goog-te-spinner-pos',
            '.goog-te-balloon-frame',
            '.goog-te-balloon',
            '.goog-te-ftab',
            '.goog-te-ftab-link',
            'iframe[src*="translate.google.com"]',
            'iframe[src*="translate.googleapis.com"]',
            'div[id*="google_translate"]',
            'div[class*="goog-te"]',
            '[class*="VIpgJd"]'
        ];

        selectors.forEach(function(selector) {
            try {
                var elements = document.querySelectorAll(selector);
                elements.forEach(function(el) {
                    if (el) {
                        el.style.display = 'none';
                        el.style.visibility = 'hidden';
                        el.style.opacity = '0';
                        el.style.pointerEvents = 'none';
                        el.style.position = 'absolute';
                        el.style.top = '-9999px';
                        el.style.left = '-9999px';
                        el.style.zIndex = '-9999';
                        el.style.width = '0';
                        el.style.height = '0';
                        el.style.maxWidth = '0';
                        el.style.maxHeight = '0';
                        el.style.margin = '0';
                        el.style.padding = '0';
                        el.style.border = 'none';
                        el.style.background = 'transparent';

                        // Also try to remove from DOM
                        try {
                            if (el.parentNode) {
                                el.parentNode.removeChild(el);
                            }
                        } catch (e) {
                            // Ignore removal errors
                        }
                    }
                });
            } catch (e) {
                // Ignore selector errors
            }
        });

        // Also check all iframes
        try {
            var iframes = document.querySelectorAll('iframe');
            iframes.forEach(function(iframe) {
                if (iframe.src && (iframe.src.indexOf('translate.google.com') !== -1 ||
                        iframe.src.indexOf('translate.googleapis.com') !== -1)) {
                    iframe.style.display = 'none';
                    iframe.style.visibility = 'hidden';
                    iframe.style.width = '0';
                    iframe.style.height = '0';
                    try {
                        if (iframe.parentNode) {
                            iframe.parentNode.removeChild(iframe);
                        }
                    } catch (e) {}
                }
            });
        } catch (e) {}
    }

    // Prevent Google Translate from intercepting form submissions
    function preventTranslatorFormInterference() {
        if (!isAssignmentGraderPage()) {
            return;
        }

        // Find all forms in the grader panel
        var graderForms = document.querySelectorAll('[data-region="grade-panel"] form');

        graderForms.forEach(function(form) {
            // Prevent Google Translate from modifying form elements
            form.addEventListener('submit', function(e) {
                // Hide any Google Translate popups before submission
                hideGoogleTranslatePopups();

                // Ensure form submission proceeds normally
                return true;
            }, true); // Use capture phase to intercept early

            // Prevent Google Translate from intercepting button clicks
            var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"], button[data-action]');
            buttons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    // Hide any Google Translate popups
                    hideGoogleTranslatePopups();

                    // Stop propagation if Google Translate tries to intercept
                    if (e.target.closest('.goog-te-menu-frame') ||
                        e.target.closest('.goog-te-banner-frame')) {
                        e.stopPropagation();
                        e.preventDefault();
                        return false;
                    }
                }, true); // Use capture phase
            });
        });
    }

    // Monitor for Google Translate popups and hide them immediately
    function monitorAndHidePopups() {
        if (!isAssignmentGraderPage()) {
            return;
        }

        // Use MutationObserver to detect when Google Translate creates popups
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        // Check if it's a Google Translate popup
                        if (node.classList && (
                                node.classList.contains('goog-te-menu-frame') ||
                                node.classList.contains('goog-te-banner-frame') ||
                                node.classList.contains('goog-te-menu') ||
                                node.classList.contains('VIpgJd-ZVi9od-l4eHX-hSRGPd') ||
                                node.classList.contains('VIpgJd-ZVi9od-ORHb-OEVmcd')
                            )) {
                            hideGoogleTranslatePopups();
                        }

                        // Also check children
                        var popups = node.querySelectorAll ? node.querySelectorAll('.goog-te-menu-frame, .goog-te-banner-frame, .goog-te-menu') : [];
                        if (popups.length > 0) {
                            hideGoogleTranslatePopups();
                        }
                    }
                });
            });
        });

        // Start observing
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Also periodically check and hide popups (fallback) - more frequent
        setInterval(function() {
            if (isAssignmentGraderPage()) {
                hideGoogleTranslatePopups();
                disableTranslatorOnForms();
            }
        }, 100); // Check every 100ms for faster response
    }

    // Disable Google Translate on grader page forms
    function disableTranslatorOnForms() {
        if (!isAssignmentGraderPage()) {
            return;
        }

        // Add class to body to help CSS targeting
        document.body.classList.add('assignment-grader-active');

        // Add skiptranslate class to entire body to prevent Google Translate
        document.body.classList.add('skiptranslate');
        document.documentElement.classList.add('skiptranslate');

        // Prevent Google Translate from translating form content
        var graderPanel = document.querySelector('[data-region="grade-panel"]');
        if (graderPanel) {
            // Add skip translation class to prevent Google Translate from processing
            graderPanel.classList.add('skiptranslate');

            // Also add to all form elements
            var formElements = graderPanel.querySelectorAll('form, input, textarea, select, button, label, div, span, p, h1, h2, h3, h4, h5, h6');
            formElements.forEach(function(el) {
                el.classList.add('skiptranslate');
            });
        }

        // Completely disable Google Translate widget on the entire page
        var translatorSwitcher = document.querySelector('.local-translator-switcher');
        if (translatorSwitcher) {
            translatorSwitcher.style.display = 'none';
            translatorSwitcher.style.visibility = 'hidden';
            translatorSwitcher.style.position = 'absolute';
            translatorSwitcher.style.top = '-9999px';
            translatorSwitcher.style.left = '-9999px';
        }

        var googleTranslateElement = document.getElementById('google_translate_element');
        if (googleTranslateElement) {
            googleTranslateElement.style.display = 'none';
            googleTranslateElement.style.visibility = 'hidden';
            googleTranslateElement.style.position = 'absolute';
            googleTranslateElement.style.top = '-9999px';
            googleTranslateElement.style.left = '-9999px';
        }

        // Disable Google Translate combo box and prevent clicks
        var comboBox = document.querySelector('.goog-te-combo');
        if (comboBox) {
            comboBox.style.display = 'none';
            comboBox.style.visibility = 'hidden';
            comboBox.disabled = true;
            comboBox.style.pointerEvents = 'none';
            comboBox.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            };
        }

        // Prevent Google Translate initialization if it hasn't happened yet
        if (window.google && window.google.translate) {
            // Override the TranslateElement to prevent initialization
            try {
                var originalTranslateElement = window.google.translate.TranslateElement;
                window.google.translate.TranslateElement = function() {
                    // Do nothing - prevent initialization
                    return null;
                };
            } catch (e) {
                // Ignore errors
            }
        }

        // Override googleTranslateElementInit if it exists
        if (typeof window.googleTranslateElementInit !== 'undefined') {
            window.googleTranslateElementInit = function() {
                // Do nothing - prevent initialization
            };
        }
    }

    // Initialize when DOM is ready
    function init() {
        if (!isAssignmentGraderPage()) {
            return;
        }

        // Run immediately
        hideGoogleTranslatePopups();
        preventTranslatorFormInterference();
        disableTranslatorOnForms();
        monitorAndHidePopups();

        // Run multiple times with delays to catch all popups
        var delays = [100, 300, 500, 1000, 2000, 3000];
        delays.forEach(function(delay) {
            setTimeout(function() {
                hideGoogleTranslatePopups();
                preventTranslatorFormInterference();
                disableTranslatorOnForms();
            }, delay);
        });

        // Run when page becomes visible (in case of tab switching)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && isAssignmentGraderPage()) {
                hideGoogleTranslatePopups();
                disableTranslatorOnForms();
            }
        });

        // Also listen for any click events to immediately hide popups
        document.addEventListener('click', function(e) {
            if (isAssignmentGraderPage()) {
                // Small delay to let Google Translate try to show popup, then hide it
                setTimeout(function() {
                    hideGoogleTranslatePopups();
                }, 50);
            }
        }, true); // Use capture phase

        // Listen for focus events (Google Translate might trigger on focus)
        document.addEventListener('focus', function(e) {
            if (isAssignmentGraderPage()) {
                setTimeout(function() {
                    hideGoogleTranslatePopups();
                }, 50);
            }
        }, true);
    }

    // Run IMMEDIATELY - don't wait for DOM
    var urlCheck = window.location.href.indexOf('action=grader') !== -1 ||
        window.location.href.indexOf('action=grade') !== -1;

    if (urlCheck) {
        // Override googleTranslateElementInit again (in case it was reset)
        window.googleTranslateElementInit = function() {
            console.log('[Assignment Grader] Google Translate initialization blocked');
            return;
        };

        // Run immediately
        try {
            init();
        } catch (e) {
            console.log('[Assignment Grader] Early init error:', e);
        }
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (isAssignmentGraderPage()) {
                init();
            }
        });
    } else {
        if (isAssignmentGraderPage()) {
            init();
        }
    }

    // Also run on page load (for dynamically loaded content)
    window.addEventListener('load', function() {
        if (isAssignmentGraderPage()) {
            // Override again on load
            window.googleTranslateElementInit = function() {
                console.log('[Assignment Grader] Google Translate initialization blocked (on load)');
                return;
            };
            setTimeout(init, 100);
            setTimeout(init, 500);
            setTimeout(init, 1000);
        }
    });

    // Continuous monitoring - run every 50ms to catch any popups
    if (urlCheck) {
        setInterval(function() {
            if (isAssignmentGraderPage()) {
                hideGoogleTranslatePopups();
                disableTranslatorOnForms();

                // Keep overriding the init function
                window.googleTranslateElementInit = function() {
                    return;
                };
            }
        }, 50);
    }

})();