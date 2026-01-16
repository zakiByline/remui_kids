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
 * A two column_end layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$bodyattributes = $OUTPUT->body_attributes($extraclasses);

// Adding a version based class on the body which will be used for adding version based css
$extraclasses[] = 'edw-m'.get_moodle_release_version_branch();
$bodyattributes = $OUTPUT->body_attributes($extraclasses);

if (get_config('theme_remui', 'pagewidth') == 'fullwidth') {
    $bodyattributes = str_replace("limitedwidth", "", $bodyattributes);
}

// Main content Top Region.
if (in_array("side-bottom", $this->page->blocks->get_regions())) {
    $addblockbuttonbottom = $OUTPUT->addblockbutton('side-bottom');
    $sidebottomblocks = $OUTPUT->blocks('side-bottom');
    // Strlen Calculation is total jugad.
    if (trim($addblockbuttonbottom) != '' || (trim($sidebottomblocks) != '' && strlen($sidebottomblocks) > 117)) {
        $templatecontext['addblockbuttonbottom'] = $addblockbuttonbottom;
        $templatecontext['sidebottomblocks'] = $sidebottomblocks;
        $templatecontext['canaddbottomblocks'] = true;
    }
}

// Main content Bottom Region.
if (in_array("side-top", $this->page->blocks->get_regions())) {
    $addblockbuttontop = $OUTPUT->addblockbutton('side-top');
    $sidetopblocks = $OUTPUT->blocks('side-top');
    // Strlen Calculation is total jugad.
    if (trim($addblockbuttontop) != '' || (trim($sidetopblocks) != '' && strlen($sidetopblocks) > 117)) {
        $templatecontext['addblockbuttontop'] = $addblockbuttontop;
        $templatecontext['sidetopblocks'] = $sidetopblocks;
        $templatecontext['canaddtopblocks'] = true;
    }
}

// Top region full width.
if (in_array("full-width-top", $this->page->blocks->get_regions())) {
    $addblockbuttonfwtop = $OUTPUT->addblockbutton('full-width-top');
    $sidefwtopblocks = $OUTPUT->blocks('full-width-top');
    // Strlen Calculation is total jugad.
    if (trim($addblockbuttonfwtop) != '' || (trim($sidefwtopblocks) != '' && strlen($sidefwtopblocks) > 117)) {
        $templatecontext['addblockbuttonfwtop'] = $addblockbuttonfwtop;
        $templatecontext['sidefwtopblocks'] = $sidefwtopblocks;
        $templatecontext['canaddfwtopblocks'] = true;
    }
}

// bottom region full width.
if (in_array("full-bottom", $this->page->blocks->get_regions())) {
    $addblockbuttonfwbottom = $OUTPUT->addblockbutton('full-bottom');
    $sidefwbottomblocks = $OUTPUT->blocks('full-bottom');
    // Strlen Calculation is total jugad.
    if (trim($addblockbuttonfwbottom) != '' || (trim($sidefwbottomblocks) != '' && strlen($sidefwbottomblocks) > 117)) {
        $templatecontext['addblockbuttonfullwidthbottom'] = $addblockbuttonfwbottom;
        $templatecontext['sidefullwidthbottomblocks'] = $sidefwbottomblocks;
        $templatecontext['canaddfullwidthbottomblocks'] = true;
    }
}

// if ($PAGE->pagetype == 'site-index' &&  \theme_remui\toolbox::get_setting('frontpagechooser') == 1) {
// $templatecontext['canaddfwtopblocks'] = false;
// $templatecontext['canaddfullwidthbottomblocks'] = false;
// }
// Edwiser Quick Menu.
if (\theme_remui\toolbox::get_setting('enablequickmenu') && isloggedin()) {
    $templatecontext['edw_quick_menu'] = \theme_remui\utility::edw_quick_menu();

}

$templatecontext['siteinnerloader'] = $CFG->wwwroot.'/theme/remui/pix/siteinnerloader.svg';
// Add a block floating button - Disabled for remui_kids theme
$templatecontext['addblockfloatmenu'] = '';

$edwpagebuilderavailable = is_plugin_available('local_edwiserpagebuilder');
if ($edwpagebuilderavailable) {
    $pagebuilderreleasedata = get_theme_req_plugin_release_info("local_edwiserpagebuilder");
    $pagebuilderrelease = $pagebuilderreleasedata->release;
    $pagebuiderverson = $pagebuilderreleasedata->versiondb;
    if (version_compare($pagebuilderrelease, "4.1.2") > 0) {
        $templatecontext['pagebuilderfileexist'] = true;
        if (\theme_remui\toolbox::get_setting('frontpagechooser') == 3) {
            $PAGE->requires->js_call_amd("local_edwiserpagebuilder/homepage_frontpage", 'init');
        }
    }
}
$templatecontext['showloader'] = true;
if ($PAGE->pagetype == 'site-index' && \theme_remui\toolbox::get_setting('frontpagechooser') == 1) {
    $templatecontext['pagebuilderfileexist'] = false;
    $templatecontext['showloader'] = false;
    $templatecontext['canaddfullwidthbottomblocks'] = false;
    $templatecontext['canaddfwtopblocks'] = false;
}

//It handles the visibility of secondary navigation in edwiserpagebuilder add block modal.
$PAGE->requires->data_for_js('edwremuithemeinfo', 'available');
$PAGE->requires->data_for_js('currentpagesubtype', $PAGE->subpage);

//Strings used in block move up and down controls
$blockregions = [];
$regionnamearray = [];
foreach ($PAGE->blocks->get_regions() as $region) {
    $regionnamearray[$region] = get_string($region, 'theme_remui');
    if (empty($OUTPUT->addblockbutton($region)) || $region == 'side-pre') {
        continue;
    }
    $blockregions[] = $region;
}
if ($PAGE->user_is_editing()) {

    // Important  code used at multiple places.
    $PAGE->requires->data_for_js('availableblockregions', $blockregions);

    // Used in add a block modal pagelayout modals.
    $PAGE->requires->data_for_js('regionsnamearray', $regionnamearray);

    $PAGE->requires->js_call_amd('theme_remui/blockmovehandler', 'init');
}

// Edwiser navbar layout.
$templatecontext['navlayout'] = \theme_remui\toolbox::get_setting('header-primary-layout-desktop');

// If this is true then new user preferences will be applied else old user(M.util based)  preferences  will be applied.
$templatecontext['applylatestuserpref'] = apply_latest_user_pref();


if(get_moodle_release_version_branch() > '402'){
    $templatecontext['applylatestdrawerjs'] = true;
}

$PAGE->requires->data_for_js('applylatestuserpref', $templatecontext['applylatestuserpref']);
$templatecontext['bodyattributes'] = $bodyattributes;

$PAGE->requires->strings_for_js(array(
    'searchcatplaceholdertext',
    'footersettings',
    'coursesettings',
    'noresutssearchmsg',
    'searchtotalcount',
    'searchresultdesctext',
    'floataddblockbtnregionselectionmsg',
    'focusmodeactivestatetext',
    'focusmodenormalstatetext',
), 'theme_remui');

// RemUI Usage Tracking (RemUI Analytics).
// It will not work if curl is not istalled.
$ranalytics = new \theme_remui\usage_tracking();
$ranalytics->send_usage_analytics();

// Dark Mode injection.
$dmhandler = new theme_remui_darkmodehandler(true);
$templatecontext['canenabledm'] = $dmhandler->init();
$templatecontext['dmanimate'] = $dmhandler->show_icon_animation();

$questionname = \theme_remui\feedbackcollection::get_current_feedback_questionname();
$PAGE->requires->js_call_amd('theme_remui/feedbackcollection', 'init', [$questionname]);

// Enable accessibility widgets in theme - Disabled for remui_kids theme
// \theme_remui\utility::enable_edw_aw_menu();

// Include RemUI Alert Modal System (reusable across all pages)
require_once($CFG->dirroot . '/theme/remui_kids/components/alert_modal.php');
$PAGE->requires->js(new moodle_url('/theme/remui_kids/javascript/alert_modal.js'));

// Fix for jQuery $ alias not being available in admin search and other pages
// This ensures that if jQuery is loaded, the $ alias is available
// This is a fallback that runs after the main shim in lib.php
$PAGE->requires->js_init_code('
(function() {
    "use strict";
    
    // Function to aggressively ensure $ alias is available
    function ensureJQueryAlias() {
        // Check if jQuery exists and $ is not set or not a function
        if (typeof jQuery !== "undefined" && jQuery !== null) {
            // Always restore $ and jQuery
            window.$ = jQuery;
            window.jQuery = jQuery;
            
            // Intercept noConflict to prevent $ from being removed
            if (jQuery.noConflict && !jQuery.noConflict._intercepted) {
                var originalNoConflict = jQuery.noConflict;
                jQuery.noConflict = function(removeAll) {
                    var result = originalNoConflict.call(this, removeAll);
                    // Immediately restore $ after noConflict
                    if (typeof window.jQuery !== "undefined" && window.jQuery !== null) {
                        window.$ = window.jQuery;
                    }
                    return result;
                };
                jQuery.noConflict._intercepted = true;
            }
            
            // Make $ non-configurable if possible to prevent deletion
            try {
                if (typeof Object.defineProperty !== "undefined") {
                    Object.defineProperty(window, "$", {
                        value: jQuery,
                        writable: true,
                        configurable: false,
                        enumerable: true
                    });
                }
            } catch (e) {
                // Ignore if we can make it non-configurable
            }
        }
        
        // Also check if $ exists but is not jQuery
        if (typeof window.$ === "function" && window.$.fn && window.$.fn.jquery) {
            window.jQuery = window.$;
        }
    }
    
    // Try immediately
    ensureJQueryAlias();
    
    // Also try when DOM is ready (jQuery might load after)
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", ensureJQueryAlias);
    }
    
    // Also try on window load (fallback)
    window.addEventListener("load", ensureJQueryAlias);
    
    // Handle AMD/jQuery loaded asynchronously via RequireJS
    if (typeof require !== "undefined" && typeof require.config === "function") {
        try {
            require(["jquery"], function(jq) {
                if (jq && typeof jq === "function") {
                    window.$ = jq;
                    window.jQuery = jq;
                    ensureJQueryAlias();
                }
            }, function(err) {
                // If require fails, try global jQuery
                ensureJQueryAlias();
            });
        } catch (e) {
            // jQuery not available via AMD, fallback to global
            ensureJQueryAlias();
        }
    }
    
    // Periodic check as final fallback
    var checkCount = 0;
    var maxChecks = 30;
    var checkInterval = setInterval(function() {
        checkCount++;
        ensureJQueryAlias();
        if (checkCount >= maxChecks) {
            clearInterval(checkInterval);
        }
    }, 50);
})();
');