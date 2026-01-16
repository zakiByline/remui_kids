/**
 * Bootstrap Compatibility Fix
 * Ensures Bootstrap dropdowns work properly across different versions
 */

(function() {
    'use strict';

    // Wait for jQuery to be available
    function waitForJQuery(callback) {
        if (typeof jQuery !== 'undefined') {
            callback(jQuery);
        } else {
            setTimeout(function() {
                waitForJQuery(callback);
            }, 100);
        }
    }

    // Initialize when jQuery is available
    waitForJQuery(function($) {
        $(document).ready(function() {
        initializeBootstrapCompatibility();
        fixDropdownConflicts();
        ensureProperEventHandling();
    });

    function initializeBootstrapCompatibility() {
        // Check for Bootstrap version and initialize accordingly
        var bootstrapVersion = detectBootstrapVersion();
        
        if (bootstrapVersion === 5) {
            initializeBootstrap5();
        } else if (bootstrapVersion === 4) {
            initializeBootstrap4();
        } else {
            // Fallback for older versions or missing Bootstrap
            initializeFallbackDropdowns();
        }
    }

    function detectBootstrapVersion() {
        // Detect Bootstrap version
        if (typeof bootstrap !== 'undefined') {
            return 5; // Bootstrap 5
        } else if (typeof $.fn.modal !== 'undefined' && $.fn.modal.Constructor.VERSION) {
            var version = $.fn.modal.Constructor.VERSION;
            if (version.startsWith('4')) return 4;
            if (version.startsWith('3')) return 3;
        }
        return null;
    }

    function initializeBootstrap5() {
        // Bootstrap 5 initialization
        var dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
        var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl);
        });

        // Handle programmatic dropdown control
        window.bootstrapDropdowns = dropdownList;
    }

    function initializeBootstrap4() {
        // Bootstrap 4 initialization
        $('[data-toggle="dropdown"]').dropdown();
        
        // Ensure proper event handling
        $('[data-toggle="dropdown"]').on('click.bs.dropdown', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
    }

    function initializeFallbackDropdowns() {
        // Fallback dropdown implementation for when Bootstrap is not available
        $(document).on('click', '[data-toggle="dropdown"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $this = $(this);
            var $dropdown = $this.closest('.dropdown');
            var $menu = $dropdown.find('.dropdown-menu');
            
            // Close other dropdowns
            $('.dropdown').not($dropdown).removeClass('show');
            $('.dropdown-menu').not($menu).removeClass('show');
            
            // Toggle current dropdown
            $dropdown.toggleClass('show');
            $menu.toggleClass('show');
            
            // Update aria attributes
            $this.attr('aria-expanded', $dropdown.hasClass('show'));
        });

        // Close dropdowns when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.dropdown').length) {
                $('.dropdown').removeClass('show');
                $('.dropdown-menu').removeClass('show');
                $('[data-toggle="dropdown"]').attr('aria-expanded', 'false');
            }
        });
    }

    function fixDropdownConflicts() {
        // Fix common dropdown conflicts
        $('.dropdown-toggle').each(function() {
            var $this = $(this);
            
            // Ensure proper attributes
            if (!$this.attr('data-toggle') && !$this.attr('data-bs-toggle')) {
                $this.attr('data-toggle', 'dropdown');
            }
            
            // Ensure proper classes
            if (!$this.hasClass('dropdown-toggle')) {
                $this.addClass('dropdown-toggle');
            }
            
            // Fix aria attributes
            if (!$this.attr('aria-haspopup')) {
                $this.attr('aria-haspopup', 'true');
            }
            if (!$this.attr('aria-expanded')) {
                $this.attr('aria-expanded', 'false');
            }
        });

        // Fix dropdown menus
        $('.dropdown-menu').each(function() {
            var $this = $(this);
            
            // Ensure proper classes
            if (!$this.hasClass('dropdown-menu')) {
                $this.addClass('dropdown-menu');
            }
            
            // Fix positioning
            if ($this.css('position') === 'static') {
                $this.css('position', 'absolute');
            }
        });
    }

    function ensureProperEventHandling() {
        // Ensure dropdown items work properly
        $('.dropdown-item').on('click', function(e) {
            var $item = $(this);
            var $dropdown = $item.closest('.dropdown');
            var $toggle = $dropdown.find('[data-toggle="dropdown"], [data-bs-toggle="dropdown"]');
            
            // Update button text if needed
            var $activeItemText = $toggle.find('[data-active-item-text]');
            if ($activeItemText.length) {
                $activeItemText.text($item.text().trim());
            }
            
            // Close dropdown
            $dropdown.removeClass('show');
            $dropdown.find('.dropdown-menu').removeClass('show');
            $toggle.attr('aria-expanded', 'false');
            
            // Prevent default if it's a placeholder link
            if ($item.attr('href') === '#') {
                e.preventDefault();
            }
        });

        // Handle keyboard navigation
        $('[data-toggle="dropdown"], [data-bs-toggle="dropdown"]').on('keydown', function(e) {
            var $this = $(this);
            var $dropdown = $this.closest('.dropdown');
            var $menu = $dropdown.find('.dropdown-menu');
            var $items = $menu.find('.dropdown-item');
            
            if (e.key === 'Escape') {
                // Close dropdown
                $dropdown.removeClass('show');
                $menu.removeClass('show');
                $this.attr('aria-expanded', 'false');
                $this.focus();
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                
                if (!$dropdown.hasClass('show')) {
                    // Open dropdown
                    $dropdown.addClass('show');
                    $menu.addClass('show');
                    $this.attr('aria-expanded', 'true');
                }
                
                // Navigate through items
                var currentIndex = $items.index($items.filter(':focus'));
                var newIndex;
                
                if (e.key === 'ArrowDown') {
                    newIndex = (currentIndex + 1) % $items.length;
                } else {
                    newIndex = currentIndex <= 0 ? $items.length - 1 : currentIndex - 1;
                }
                
                $items.eq(newIndex).focus();
            }
        });
    }
    });
})();





