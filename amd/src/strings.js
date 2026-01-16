/**
 * Theme strings helper module
 * 
 * Provides easy access to translated strings in JavaScript
 *
 * @module     theme_remui_kids/strings
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/str'], function(Str) {
    'use strict';

    /**
     * Get a translated string
     * @param {string} key The string identifier
     * @param {string} component The component (default: theme_remui_kids)
     * @param {mixed} param Optional parameter for the string
     * @return {Promise} Promise resolving to the translated string
     */
    var getString = function(key, component, param) {
        component = component || 'theme_remui_kids';
        return Str.get_string(key, component, param);
    };

    /**
     * Get multiple translated strings
     * @param {Array} keys Array of string identifiers
     * @param {string} component The component (default: theme_remui_kids)
     * @return {Promise} Promise resolving to array of translated strings
     */
    var getStrings = function(keys, component) {
        component = component || 'theme_remui_kids';
        var requests = keys.map(function(key) {
            return {key: key, component: component};
        });
        return Str.get_strings(requests);
    };

    /**
     * Common navigation strings
     */
    var navStrings = [
        'nav_dashboard',
        'nav_mycourses',
        'nav_lessons',
        'nav_activities',
        'nav_achievements',
        'nav_competencies',
        'nav_grades',
        'nav_badges',
        'nav_schedule',
        'nav_settings',
        'nav_calendar',
        'nav_messages',
        'nav_communities',
        'nav_myreports',
        'nav_assignments',
        'nav_profile'
    ];

    /**
     * Common UI strings
     */
    var uiStrings = [
        'loading',
        'error',
        'success',
        'save',
        'cancel',
        'close',
        'submit',
        'view_all',
        'view_details',
        'search',
        'filter',
        'refresh',
        'no_data',
        'no_results'
    ];

    /**
     * Get all navigation strings
     * @return {Promise} Promise resolving to object with string keys and values
     */
    var getNavStrings = function() {
        return getStrings(navStrings).then(function(results) {
            var obj = {};
            navStrings.forEach(function(key, index) {
                obj[key] = results[index];
            });
            return obj;
        });
    };

    /**
     * Get all UI strings
     * @return {Promise} Promise resolving to object with string keys and values
     */
    var getUIStrings = function() {
        return getStrings(uiStrings).then(function(results) {
            var obj = {};
            uiStrings.forEach(function(key, index) {
                obj[key] = results[index];
            });
            return obj;
        });
    };

    return {
        getString: getString,
        getStrings: getStrings,
        getNavStrings: getNavStrings,
        getUIStrings: getUIStrings
    };
});

