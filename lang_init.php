<?php
/**
 * Language Initialization Helper for Custom Theme Pages
 *
 * Include this file AFTER config.php in custom pages to apply
 * the user's selected language.
 *
 * Usage:
 *   require_once(__DIR__ . '/../../config.php');
 *   require_once(__DIR__ . '/lang_init.php');
 *   
 *   // For custom HTML output:
 *   <html <?php echo lang_attr(); ?>>
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CURRENT_LANG, $CFG, $SESSION;

// Skip during AJAX to prevent JSON errors
if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
    $CURRENT_LANG = $CFG->lang ?? 'en';
    return;
}

// Skip during CLI
if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
    $CURRENT_LANG = $CFG->lang ?? 'en';
    return;
}

// Apply language from langswitch plugin if available
$langswitch_lib = $CFG->dirroot . '/local/langswitch/lib.php';
if (file_exists($langswitch_lib)) {
    require_once($langswitch_lib);
    $CURRENT_LANG = local_langswitch_apply_language();
} else {
    // Fallback to Moodle's current_language()
    $CURRENT_LANG = current_language();
}

/**
 * Get HTML lang attribute string
 *
 * @return string lang="xx" xml:lang="xx"
 */
function lang_attr(): string {
    global $CURRENT_LANG;
    $lang = $CURRENT_LANG ?? 'en';
    return 'lang="' . s($lang) . '" xml:lang="' . s($lang) . '"';
}

/**
 * Get current language code
 *
 * @return string Language code
 */
function get_current_lang(): string {
    global $CURRENT_LANG;
    return $CURRENT_LANG ?? 'en';
}
