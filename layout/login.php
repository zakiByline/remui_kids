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
 * A login page layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$extraclasses = array();

$extraclasses[] = \theme_remui\utility::get_main_bg_class();

$extraclasses[] = get_config('theme_remui', 'loginpagelayout');

$bodyattributes = $OUTPUT->body_attributes($extraclasses);

// Customizer fonts.
$customizer = \theme_remui\customizer\customizer::instance();
$fonts = $customizer->get_fonts_to_load();

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
    'fonts' => $fonts,
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes
];

$templatecontext['logocontext'] = $this->get_branding_context();
$templatecontext['signuptextcolor'] = get_config('theme_remui', 'signuptextcolor');
if (get_config('theme_remui', 'loginpagelayout') != 'logincenter') {
    $templatecontext['canshowdesc'] = true;
    $templatecontext['brandlogotext'] = format_text(get_config('theme_remui', 'brandlogotext'),FORMAT_HTML,array("noclean" => true));
}

// Enable accessibility widgets
\theme_remui\utility::enable_edw_aw_menu();

// Include custom login providers CSS
$PAGE->requires->css('/theme/remui_kids/style/login_providers.css');

// Prevent Google Translate from initializing on login page
$PAGE->requires->js_init_code('
    (function() {
        // Override googleTranslateElementInit to prevent initialization
        window.googleTranslateElementInit = function() {
            console.log("[Login Page] Google Translate initialization blocked");
            return;
        };
        
        // Hide any Google Translate elements that might appear
        function hideGoogleTranslate() {
            var elements = document.querySelectorAll(
                "#google_translate_element, " +
                ".goog-te-banner-frame, " +
                "iframe[src*=\"translate.google.com\"], " +
                "iframe[src*=\"translate.googleapis.com\"], " +
                "[class*=\"goog-te\"], " +
                "[id*=\"google_translate\"], " +
                ".VIpgJd-ZVi9od-ORHb-OEVmcd"
            );
            elements.forEach(function(el) {
                if (el) {
                    el.style.display = "none";
                    el.style.visibility = "hidden";
                    el.style.height = "0";
                    el.style.width = "0";
                    el.style.position = "absolute";
                    el.style.top = "-9999px";
                }
            });
            
            // Reset body top position
            if (document.body) {
                document.body.style.top = "0";
            }
        }
        
        // Hide immediately
        hideGoogleTranslate();
        
        // Hide on DOMContentLoaded
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", hideGoogleTranslate);
        } else {
            hideGoogleTranslate();
        }
        
        // Hide on any new elements added (MutationObserver)
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations) {
                hideGoogleTranslate();
            });
            observer.observe(document.body || document.documentElement, {
                childList: true,
                subtree: true
            });
        }
    })();
');

// Ensure doctype() is called to set contenttype (required by Moodle core renderer).
$OUTPUT->doctype();

echo $OUTPUT->render_from_template('theme_remui/login', $templatecontext);
