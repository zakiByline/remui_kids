<?php
/**
 * Child Session Manager
 * Manages parent's selected child across page navigation
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the currently selected child ID from session or URL parameter
 *
 * @return int|string|null Selected child ID, 'all' for all children, or null if not set
 */
function get_selected_child() {
    global $SESSION;
    
    // Ensure session is initialized
    if (!isset($SESSION)) {
        // Session not initialized yet, return null
        return null;
    }
    
    // Check URL parameter first (takes precedence)
    $child_param = optional_param('child', null, PARAM_RAW);
    
    if ($child_param !== null) {
        // Validate and set in session
        if ($child_param === 'all' || $child_param === '0' || $child_param === '') {
            $selected = 'all';
        } else {
            $selected = (int)$child_param;
            if ($selected <= 0) {
                $selected = 'all';
            }
        }
        // Only set in session if it's initialized
        if (isset($SESSION)) {
            $SESSION->parent_selected_child = $selected;
        }
        return $selected;
    }
    
    // Return from session if set
    if (isset($SESSION) && isset($SESSION->parent_selected_child)) {
        return $SESSION->parent_selected_child;
    }
    
    // Default: no selection
    return null;
}

/**
 * Set the selected child ID in session
 *
 * @param int|string $child_id Child ID or 'all' for all children
 * @return void
 */
function set_selected_child($child_id) {
    global $SESSION;
    
    // Ensure session is initialized before accessing it
    if (!isset($SESSION)) {
        return;
    }
    
    if ($child_id === 'all' || $child_id === '0' || $child_id === '' || $child_id === null) {
        $SESSION->parent_selected_child = 'all';
    } else {
        $child_id = (int)$child_id;
        if ($child_id > 0) {
            $SESSION->parent_selected_child = $child_id;
        } else {
            $SESSION->parent_selected_child = 'all';
        }
    }
}

/**
 * Clear the selected child (reset to 'all')
 *
 * @return void
 */
function clear_selected_child() {
    global $SESSION;
    
    // Ensure session is initialized before accessing it
    if (!isset($SESSION)) {
        return;
    }
    
    $SESSION->parent_selected_child = 'all';
}

/**
 * Check if a specific child is currently selected
 *
 * @param int $child_id Child ID to check
 * @return bool True if this child is selected
 */
function is_child_selected($child_id) {
    $selected = get_selected_child();
    return ($selected !== null && $selected !== 'all' && (int)$selected === (int)$child_id);
}

/**
 * Check if any specific child is selected (not 'all')
 *
 * @return bool True if a specific child is selected
 */
function has_specific_child_selected() {
    $selected = get_selected_child();
    return ($selected !== null && $selected !== 'all' && $selected !== '0');
}







