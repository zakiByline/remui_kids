/**
 * Certificate Completion Module for RemUI Kids Theme
 * 
 * Handles course completion checking and certificate card display
 * 
 * @module theme_remui_kids/certificate_completion
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    
    return {
        /**
         * Initialize certificate completion checking
         * 
         * @param {Object} config Configuration object with courseid and userid
         */
        init: function(config) {
            var courseid = config.courseid;
            var userid = config.userid;
            
            // Check completion status and inject certificate card
            this.checkAndDisplayCertificate(courseid, userid);
        },
        
        /**
         * Check course completion and display certificate card
         * 
         * @param {Number} courseid Course ID
         * @param {Number} userid User ID
         */
        checkAndDisplayCertificate: function(courseid, userid) {
            var self = this;
            
            // Make AJAX call to check completion and get certificate card HTML
            var request = {
                methodname: 'theme_remui_kids_get_certificate_card',
                args: {
                    courseid: courseid,
                    userid: userid
                }
            };
            
            // Use AJAX if available, otherwise use direct PHP call via page load
            // For now, we'll inject via PHP on page load instead of AJAX
            // This is handled in the template via a PHP function call
        }
    };
});







