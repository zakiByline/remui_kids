<?php
/**
 * Parent access guard helpers for RemUI Kids parent area.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (!function_exists('theme_remui_kids_user_has_role')) {
    /**
     * Checks if a user has a specific role in the system context or any user context.
     *
     * @param int $userid The ID of the user.
     * @param string $roleshortname The shortname of the role to check.
     * @return bool True if the user has the role, false otherwise.
     */
    function theme_remui_kids_user_has_role(int $userid, string $roleshortname): bool {
        global $DB;
        static $rolecache = [];

        try {
            if (isset($rolecache[$userid][$roleshortname])) {
                return $rolecache[$userid][$roleshortname];
            }

            $role = $DB->get_record('role', ['shortname' => $roleshortname]);
            if (!$role) {
                $rolecache[$userid][$roleshortname] = false;
                return false;
            }

            $systemcontext = context_system::instance();
            $hasrole = user_has_role_assignment($userid, $role->id, $systemcontext->id);

            if (!$hasrole) {
                // Check if the user has the role in any user context (e.g., assigned to themselves).
                $hasrole = $DB->record_exists_sql(
                    "SELECT ra.id
                     FROM {role_assignments} ra
                     JOIN {context} ctx ON ctx.id = ra.contextid
                     WHERE ra.userid = :userid
                     AND ra.roleid = :roleid
                     AND ctx.contextlevel = :ctxlevel",
                    [
                        'userid' => $userid,
                        'roleid' => $role->id,
                        'ctxlevel' => CONTEXT_USER
                    ]
                );
            }

            $rolecache[$userid][$roleshortname] = $hasrole;
            return $hasrole;
        } catch (Exception $e) {
            debugging('Error in theme_remui_kids_user_has_role: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('theme_remui_kids_user_is_parent')) {
    /**
     * Convenience wrapper to check if a user is a parent.
     *
     * @param int $userid
     * @return bool
     */
    function theme_remui_kids_user_is_parent(int $userid): bool {
        return theme_remui_kids_user_has_role($userid, 'parent');
    }
}

/**
 * Ensure the logged-in user has the parent role.
 * Redirects away with an error notification when access is denied.
 *
 * @param moodle_url|null $fallbackurl Optional redirect destination.
 * @throws moodle_exception When the parent role itself is missing.
 */
function theme_remui_kids_require_parent(?moodle_url $fallbackurl = null): void {
    global $DB, $USER;

    // Ensure user is logged in (but don't call require_login if already called)
    if (!isloggedin() || isguestuser()) {
        require_login();
    }

    try {
        $parentrole = $DB->get_record('role', ['shortname' => 'parent']);
        if (!$parentrole) {
            // If parent role doesn't exist, allow access (might be using different role system)
            debugging('Parent role not found in database');
            return;
        }

        if (theme_remui_kids_user_has_role($USER->id, 'parent')) {
            return;
        }

        if (!$fallbackurl) {
            $fallbackurl = new moodle_url('/');
            try {
                $studentrole = $DB->get_record('role', ['shortname' => 'student']);
                $systemcontext = context_system::instance();
                if ($studentrole && user_has_role_assignment($USER->id, $studentrole->id, $systemcontext->id)) {
                    $fallbackurl = new moodle_url('/my/');
                }
            } catch (Exception $e) {
                // Ignore errors in fallback URL determination
            }
        }

        redirect(
            $fallbackurl,
            get_string('nopermissions', 'error', 'Access parent dashboard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    } catch (Exception $e) {
        debugging('Error in theme_remui_kids_require_parent: ' . $e->getMessage());
        // Don't block access if there's an error - let the page try to load
        // This prevents 500 errors from blocking all parent pages
    }
}

