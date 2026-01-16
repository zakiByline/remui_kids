/**
 * Admin Dashboard Dropdown Fix
 * Fixes dropdown functionality issues in the admin dashboard
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
        initializeDropdowns();
        fixBootstrapDropdowns();
        initializeSelectElements();
    });

    function initializeDropdowns() {
        // Handle dropdown toggle clicks
        $(document).on('click', '[data-toggle="dropdown"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $dropdown = $(this).closest('.dropdown');
            var $menu = $dropdown.find('.dropdown-menu');
            
            // Close other dropdowns
            $('.dropdown').not($dropdown).removeClass('show');
            $('.dropdown-menu').not($menu).removeClass('show');
            
            // Toggle current dropdown
            $dropdown.toggleClass('show');
            $menu.toggleClass('show');
            
            // Update aria-expanded
            $(this).attr('aria-expanded', $dropdown.hasClass('show'));
        });

        // Close dropdowns when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.dropdown').length) {
                $('.dropdown').removeClass('show');
                $('.dropdown-menu').removeClass('show');
                $('[data-toggle="dropdown"]').attr('aria-expanded', 'false');
            }
        });

        // Handle dropdown item clicks
        $(document).on('click', '.dropdown-item', function(e) {
            var $item = $(this);
            var $dropdown = $item.closest('.dropdown');
            var $toggle = $dropdown.find('[data-toggle="dropdown"]');
            
            // Update button text if data-active-item-text is present
            var $activeItemText = $toggle.find('[data-active-item-text]');
            if ($activeItemText.length) {
                $activeItemText.text($item.text());
            }
            
            // Close dropdown
            $dropdown.removeClass('show');
            $dropdown.find('.dropdown-menu').removeClass('show');
            $toggle.attr('aria-expanded', 'false');
        });
    }

    function fixBootstrapDropdowns() {
        // Check if Bootstrap 5 is loaded and initialize dropdowns
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            // Initialize Bootstrap 5 dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
        }

        // Handle Bootstrap 4 to 5 migration for dropdowns
        $('[data-toggle="dropdown"]').each(function() {
            var $this = $(this);
            
            // Add Bootstrap 5 attributes if not present
            if (!$this.attr('data-bs-toggle')) {
                $this.attr('data-bs-toggle', 'dropdown');
            }
            
            // Ensure proper classes
            if (!$this.hasClass('dropdown-toggle')) {
                $this.addClass('dropdown-toggle');
            }
        });
    }

    function initializeSelectElements() {
        // Fix select elements with custom styling
        $('select.form-control, select.custom-select').each(function() {
            var $select = $(this);
            
            // Add proper classes
            if (!$select.hasClass('form-control')) {
                $select.addClass('form-control');
            }
            
            // Ensure proper styling
            $select.css({
                'appearance': 'none',
                '-webkit-appearance': 'none',
                '-moz-appearance': 'none',
                'background-image': 'url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 4 5\'%3E%3Cpath fill=\'%23666\' d=\'m2 0-2 2h4zm0 5 2-2h-4z\'%3E%3C/path%3E%3C/svg%3E")',
                'background-repeat': 'no-repeat',
                'background-position': 'right 0.75rem center',
                'background-size': '16px 12px',
                'padding-right': '2.25rem'
            });
        });
    }
    });

})();

