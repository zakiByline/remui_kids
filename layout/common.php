<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A two column layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use theme_remui\utility;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/behat/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

global $PAGE;

// Load Help Widget for all logged-in users
if (isloggedin() && !isguestuser()) {
    $PAGE->requires->css('/theme/remui_kids/style/help_widget.css');
    $PAGE->requires->js('/theme/remui_kids/javascript/help_widget.js', true);
}

// Load Google Translate CSS on ALL pages (hides Google Translate bar and unwanted elements)
$PAGE->requires->css('/theme/remui_kids/style/google_translate.css');

// Suppress Google Translate tracking prevention errors globally
// These errors occur when browsers block Google Translate from accessing storage
// They are harmless but clutter the console
$PAGE->requires->js_init_code('
    (function() {
        // Suppress tracking prevention warnings from Google Translate
        const originalError = console.error;
        const originalWarn = console.warn;
        
        console.error = function(...args) {
            const message = args.join(" ");
            // Suppress Google Translate tracking prevention errors
            if (message.indexOf("Tracking Prevention blocked") !== -1 &&
                (message.indexOf("translate.google.com") !== -1 ||
                 message.indexOf("translate.googleapis.com") !== -1)) {
                return; // Suppress this error
            }
            originalError.apply(console, args);
        };
        
        console.warn = function(...args) {
            const message = args.join(" ");
            // Suppress Google Translate tracking prevention warnings
            if (message.indexOf("Tracking Prevention blocked") !== -1 &&
                (message.indexOf("translate.google.com") !== -1 ||
                 message.indexOf("translate.googleapis.com") !== -1)) {
                return; // Suppress this warning
            }
            originalWarn.apply(console, args);
        };
    })();
', true);

// Load assignment grader translator fix on assignment pages
// This prevents Google Translate popups from interfering with grade submission
$pageurl = $PAGE->url ? $PAGE->url->out(false) : '';
$isassignpage = strpos($pageurl, '/mod/assign/') !== false;
$isgraderpage = $isassignpage && (
    strpos($pageurl, 'action=grader') !== false || 
    strpos($pageurl, 'action=grade') !== false ||
    optional_param('action', '', PARAM_ALPHA) === 'grader' ||
    optional_param('action', '', PARAM_ALPHA) === 'grade'
);

if ($isassignpage) {
    // Always load CSS for assignment pages
    $PAGE->requires->css('/theme/remui_kids/style/assignment_grader_fix.css');
    
    // Add inline CSS to ensure grade-panel has fixed positioning with proper z-index
    // This needs to be inline to override core assign styles.css
    global $CFG;
    if (!isset($CFG->additionalhtmlhead)) {
        $CFG->additionalhtmlhead = '';
    }
    $CFG->additionalhtmlhead .= '<style>
        body.path-mod-assign [data-region="grade-panel"] {
            position: fixed !important;
            z-index: 999 !important;
            top: 85px !important;
            bottom: 60px !important;
            right: 0 !important;
            left: 70% !important;
            width: 30% !important;
        }
    </style>';
    
    // Load JavaScript fix specifically for grader pages
    if ($isgraderpage) {
        // Add inline script that runs IMMEDIATELY in <head> to prevent Google Translate initialization
        $PAGE->requires->js_init_code('
            (function() {
                // CRITICAL: Run immediately, before any other scripts
                var isGrader = window.location.href.indexOf("action=grader") !== -1 || 
                               window.location.href.indexOf("action=grade") !== -1;
                
                if (isGrader) {
                    // 1. Override googleTranslateElementInit IMMEDIATELY
                    window.googleTranslateElementInit = function() {
                        console.log("[Grader Fix] Blocked Google Translate init");
                        return;
                    };
                    
                    // 2. Prevent Google Translate script from loading
                    var originalAppend = Node.prototype.appendChild;
                    Node.prototype.appendChild = function(child) {
                        if (child && child.tagName === "SCRIPT" && child.src && 
                            (child.src.indexOf("translate.google.com") !== -1 || 
                             child.src.indexOf("translate.googleapis.com") !== -1)) {
                            console.log("[Grader Fix] Blocked script:", child.src);
                            return child; // Don\'t append
                        }
                        return originalAppend.call(this, child);
                    };
                    
                    // 3. Hide ALL Google Translate elements aggressively
                    function hideAllGT() {
                        var selectors = [
                            ".goog-te-menu-frame", ".goog-te-banner-frame", ".goog-te-menu",
                            ".VIpgJd-ZVi9od-l4eHX-hSRGPd", ".VIpgJd-ZVi9od-ORHb-OEVmcd",
                            "iframe[src*=\'translate.google.com\']", "iframe[src*=\'translate.googleapis.com\']",
                            "#google_translate_element", ".local-translator-switcher",
                            "[class*=\'goog-te\']", "[id*=\'google_translate\']", "[class*=\'VIpgJd\']"
                        ];
                        
                        selectors.forEach(function(sel) {
                            try {
                                var els = document.querySelectorAll(sel);
                                els.forEach(function(el) {
                                    if (el) {
                                        el.style.cssText = "display:none!important;visibility:hidden!important;opacity:0!important;position:absolute!important;top:-9999px!important;left:-9999px!important;width:0!important;height:0!important;z-index:-9999!important;pointer-events:none!important;";
                                        try { el.parentNode && el.parentNode.removeChild(el); } catch(e) {}
                                    }
                                });
                            } catch(e) {}
                        });
                    }
                    
                    // 4. Run immediately and continuously
                    if (document.body) {
                        document.body.classList.add("skiptranslate");
                        hideAllGT();
                    }
                    if (document.documentElement) {
                        document.documentElement.classList.add("skiptranslate");
                    }
                    
                    // Run every 50ms to catch any popups
                    setInterval(hideAllGT, 50);
                    
                    // Also run on DOM ready
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", hideAllGT);
                    } else {
                        setTimeout(hideAllGT, 0);
                    }
                    
                    // Override again on window load
                    window.addEventListener("load", function() {
                        window.googleTranslateElementInit = function() { return; };
                        hideAllGT();
                    });
                }
            })();
        ');
        
        $PAGE->requires->js('/theme/remui_kids/javascript/assignment_grader_translator_fix.js', true);
    }
}

$loaderimage = false;
if(get_config('theme_remui','enablesiteloader')){
    // Adding loader image before everything else.
    $loaderimage = \theme_remui\utility::get_site_loader();
}

// Add block button in editing mode.
$addblockbutton = $OUTPUT->addblockbutton();

if(!apply_latest_user_pref()){
    user_preference_allow_ajax_update('drawer-open-nav', PARAM_ALPHA);
    user_preference_allow_ajax_update('drawer-open-index', PARAM_BOOL);
    user_preference_allow_ajax_update('drawer-open-block', PARAM_BOOL);
    user_preference_allow_ajax_update('course_view_state', PARAM_ALPHA);
    user_preference_allow_ajax_update('remui_dismised_announcement', PARAM_BOOL);
    user_preference_allow_ajax_update('edw-quick-menu', PARAM_BOOL);
    user_preference_allow_ajax_update('edwiser_inproduct_notification', PARAM_ALPHA);
    user_preference_allow_ajax_update('homepagedepricatedseen', PARAM_BOOL);
    user_preference_allow_ajax_update('darkmodecustomizerwarnnotvisible', PARAM_BOOL);
    user_preference_allow_ajax_update('acs-widget-status', PARAM_BOOL);
    user_preference_allow_ajax_update('acs-feedback-status', PARAM_BOOL);
}

if (isloggedin()) {
    $courseindexopen = (get_user_preferences('drawer-open-index', true) == true);
    $blockdraweropen = (get_user_preferences('drawer-open-block') == true);
    // Always pinned for quiz and book activity.
    $activities = array("book", "quiz");
    if (isset($PAGE->cm->id) && in_array($PAGE->cm->modname, $activities)) {
        $blockdraweropen = true;
    }
} else {
    $courseindexopen = false;
    $blockdraweropen = false;
}

if (defined('BEHAT_SITE_RUNNING')) {
    $blockdraweropen = true;
}

$extraclasses = ['uses-drawers'];
if ($courseindexopen) {
    $extraclasses[] = 'drawer-open-index';
}

if (isguestuser()) {
    $extraclasses[] = 'isguest';
}
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));
if (!$hasblocks) {
    $blockdraweropen = false;
}
$courseindex = core_course_drawer();


if (!$courseindex) {
    $courseindexopen = false;
}

$extraclasses[] = \theme_remui\utility::get_main_bg_class();

// Focus data.
$focusdata = [
    'enabled' => false,
    'on' => 0,
    'sections' => [],
    'active' => null,
    'previous' => null,
    'next' => null,
];

$forceblockdraweropen = $OUTPUT->firstview_fakeblocks();

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $tablistnav = $PAGE->has_tablist_secondary_navigation();
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs', true, $tablistnav);
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $overflow = $overflowdata->export_for_template($OUTPUT);
    }
}

$primary = new core\navigation\output\primary($PAGE);
$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);

// Recent Courses Menu.
if (isloggedin()) {
    $primarymenu = \theme_remui\utility::get_recent_courses_menu($primarymenu);
}

// Skip the default course categories menu injection for the kids header to keep navigation minimal.

// Apply kids-specific primary navigation adjustments (hide Home/Categories, ensure dashboard routing).
if (function_exists('theme_remui_kids_prepare_primary_navigation')) {
    $primarymenu = theme_remui_kids_prepare_primary_navigation($primarymenu, $PAGE);
}

// Login Menu Addition.
if (!isloggedin() && \theme_remui\toolbox::get_setting('navlogin_popup')) {
    $primarymenu = \theme_remui\utility::get_login_menu_data($primarymenu);
}
// Here we Add extra icons to profile dropdown menu.
if (isloggedin() && !isguestuser()) {
    $primarymenu = \theme_remui\utility::add_profile_dropdown_icons($primarymenu);
}

// Init product notification configuration.
if ($notification = \theme_remui\utility::get_inproduct_notification()) {
    $templatecontext['notification'] = $notification;
}

// Customizer fonts.
$customizer = \theme_remui\customizer\customizer::instance();
$fonts = $customizer->get_fonts_to_load();

$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions() && !$PAGE->has_secondary_navigation();
// If the settings menu will be included in the header then don't add it here.
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header->export_for_template($renderer);
$lcontroller = new \theme_remui\controller\LicenseController();

$primarymoremenu = $primarymenu['moremenu'] ?? false;
if ($primarymoremenu) {
    $hasnodearray = isset($primarymoremenu['nodearray']) && !empty($primarymoremenu['nodearray']);
    $hasnodecollection = isset($primarymoremenu['nodecollection']['children']) &&
        !empty($primarymoremenu['nodecollection']['children']);
    if (!$hasnodearray && !$hasnodecollection) {
        $primarymoremenu = false;
    }
}

// Get language switcher data from local_langswitch plugin if available.
$langswitcher_html = '';
$langswitcher_data = ['enabled' => false];
$translator_switcher_html = '';

try {
    // Check for local_langswitch plugin
    if (function_exists('local_langswitch_get_switcher_html')) {
        $langswitcher_html = local_langswitch_get_switcher_html();
    }
    if (function_exists('local_langswitch_get_switcher_data')) {
        $langswitcher_data = local_langswitch_get_switcher_data();
    }
    
    // Check for local_translator plugin - render widget directly for navbar
    $pluginmanager = core_plugin_manager::instance();
    $translator_plugin = $pluginmanager->get_plugin_info('local_translator');
    if ($translator_plugin && $translator_plugin->is_enabled()) {
        // Check if plugin is enabled in settings
        $translator_enabled = get_config('local_translator', 'enabled');
        if ($translator_enabled) {
            // Get source language
            $sourcelang = get_config('local_translator', 'sourcelang') ?: 'en';
            
            // Render the translator widget template directly
            try {
                $translator_switcher_html = $OUTPUT->render_from_template('local_translator/language_switcher', [
                    'sourcelang' => $sourcelang,
                ]);
            } catch (\Exception $e) {
                // If template doesn't exist or fails, try function-based approach
                if (function_exists('local_translator_get_switcher_html')) {
                    $translator_switcher_html = local_translator_get_switcher_html();
                }
            }
        }
    }
} catch (\Exception $e) {
    // Silently fail - language switcher not critical
    $langswitcher_html = '';
    $langswitcher_data = ['enabled' => false];
    $translator_switcher_html = '';
} catch (\Throwable $e) {
    // Silently fail
    $langswitcher_html = '';
    $langswitcher_data = ['enabled' => false];
    $translator_switcher_html = '';
}

// Ensure language menu is always available for all users (including logged-in users)
// Moodle by default only shows language menu for non-logged-in users
$langmenu = $primarymenu['lang'];

// Always generate language menu for logged-in users (Moodle doesn't provide it by default)
// Or if it's empty for any user
if (empty($langmenu) || (isloggedin() && !isguestuser())) {
    // Get available languages directly
    global $CFG;
    $langs = get_string_manager()->get_list_of_translations();
    
    // Only show if we have at least 2 languages
    if (count($langs) >= 2) {
        $currentlang = current_language();
        $nodes = [];
        $activelanguage = '';
        
        foreach ($langs as $langtype => $langname) {
            $isactive = $langtype == $currentlang;
            $attributes = [];
            if (!$isactive) {
                $attributes[] = [
                    'key' => 'lang',
                    'value' => get_html_lang_attribute_value($langtype),
                ];
            }
            $node = [
                'title' => $langname,
                'text' => $langname,
                'link' => true,
                'isactive' => $isactive,
                'url' => $isactive ? new \moodle_url('#') : new \moodle_url($PAGE->url, ['lang' => $langtype]),
            ];
            if (!empty($attributes)) {
                $node['attributes'] = $attributes;
            }
            $nodes[] = $node;
            
            if ($isactive) {
                $activelanguage = $langname;
            }
        }
        
        if (!empty($nodes)) {
            $langmenu = [
                'title' => $activelanguage ?: get_string('language'),
                'items' => $nodes,
                'hasitems' => true, // Flag to indicate items exist
            ];
        } else {
            $langmenu = [];
        }
    } else {
        $langmenu = [];
    }
} else {
    // For non-logged-in users, add hasitems flag if items exist
    if (!empty($langmenu) && !empty($langmenu['items'])) {
        $langmenu['hasitems'] = true;
    }
}

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
    'fonts' => $fonts,
    'output' => $OUTPUT,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
    'show_license_notice' => \theme_remui\utility::show_license_notice(),
    'courseindexopen' => $courseindexopen,
    'blockdraweropen' => $blockdraweropen,
    'courseindex' => $courseindex,
    'primarymoremenu' => $primarymoremenu,
    'secondarymoremenu' => $secondarynavigation ?: false,
    'mobileprimarynav' => $primarymenu['mobileprimarynav'],
    'usermenu' => $primarymenu['user'],
    'langmenu' => $langmenu,
    'langswitcher_html' => $langswitcher_html,
    'langswitcher' => $langswitcher_data,
    'translator_switcher_html' => $translator_switcher_html,
    'translator_enabled' => !empty($translator_switcher_html),
    'forceblockdraweropen' => $forceblockdraweropen,
    'regionmainsettingsmenu' => $regionmainsettingsmenu,
    'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
    'overflow' => $overflow,
    'headercontent' => $headercontent,
    'addblockbutton' => $addblockbutton,
    'isloggedin' => isloggedin(),
    'footerdata' => \theme_remui\utility::get_footer_data(),
    'cansendfeedback' => (is_siteadmin()) ? true : false,
    'feedbacksender_emailid' => isset($USER->email) ? $USER->email : '',
    'feedback_loading_image' => new moodle_url('/theme/remui/pix/siteinnerloader.svg'),
    'loaderimage' => $loaderimage
];

$templatecontext['sections'] = $templatecontext['footerdata']['sections'];
$templatecontext['focusdata'] = $focusdata;

if (\theme_remui\toolbox::get_setting('enableannouncement') && !get_user_preferences('remui_dismised_announcement')) {
    $extraclasses[] = 'remui-notification';
    $templatecontext['sitenotification'] = \theme_remui\utility::render_site_announcement();
}

if (\theme_remui\toolbox::get_setting('enabledictionary') && !$PAGE->user_is_editing()) {
    // Enable dictionary only when editing is off.
    $templatecontext['enabledictionary'] = true;
}

if ("admin-setting-themesettingremui" == $PAGE->pagetype) {
    $templatecontext['enablebeacon'] = true;
}
