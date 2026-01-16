<?php
/**
 * AJAX endpoint for fetching high school dashboard chart data (live).
 *
 * Returns:
 * - weekly_activity: completed activities grouped by day (Mon..Sun)
 * - performance_trend: progress/grade trend labels + data
 *
 * Usage: GET /theme/remui_kids/dashboard/ajax_highschool_stats.php?userid=123
 *
 * @package    theme_remui_kids
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

header('Content-Type: application/json; charset=utf-8');

require_login();

global $CFG, $USER;

$userid = optional_param('userid', 0, PARAM_INT);
if (!$userid) {
    $userid = $USER->id;
}

// Only allow users to fetch their own data unless they are site admins.
if ((int)$userid !== (int)$USER->id && !is_siteadmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Access denied',
        'timestamp' => time(),
    ]);
    exit;
}

require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

try {
    $weekly = function_exists('theme_remui_kids_get_weekly_activity_data')
        ? theme_remui_kids_get_weekly_activity_data($userid)
        : null;

    $trend = function_exists('theme_remui_kids_get_highschool_performance_trend')
        ? theme_remui_kids_get_highschool_performance_trend($userid)
        : null;

    echo json_encode([
        'success' => true,
        'userid' => $userid,
        'timestamp' => time(),
        'data' => [
            'weekly_activity' => $weekly,
            'performance_trend' => $trend,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'userid' => $userid,
        'timestamp' => time(),
    ]);
}







