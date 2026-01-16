<?php
/**
 * Performance Logger for Elementary Dashboard
 * 
 * Logs database queries, execution times, and potential bottlenecks
 * to help diagnose performance issues on VPS servers.
 * 
 * @package theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the log file path
 * 
 * @return string Log file path
 */
function theme_remui_kids_get_log_path() {
    global $CFG;
    $logdir = $CFG->dataroot . '/theme_remui_kids_logs';
    
    // Create directory if it doesn't exist
    if (!file_exists($logdir)) {
        @mkdir($logdir, 0755, true);
    }
    
    return $logdir . '/elementary_dashboard_' . date('Y-m-d') . '.log';
}

/**
 * Write log entry
 * 
 * @param string $message Log message
 * @param array $data Additional data to log
 * @param string $level Log level (INFO, WARNING, ERROR, QUERY, TIMING)
 */
function theme_remui_kids_log($message, $data = [], $level = 'INFO') {
    // Try to get log path, but handle case where $CFG might not be available yet
    try {
        global $CFG;
        if (isset($CFG) && isset($CFG->dataroot)) {
            $logfile = theme_remui_kids_get_log_path();
        } else {
            // Fallback if $CFG not available (very early in Moodle init)
            $logfile = sys_get_temp_dir() . '/moodle_init_' . date('Y-m-d') . '.log';
        }
    } catch (Exception $e) {
        // If we can't get log path, skip logging
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $microtime = microtime(true);
    
    $logentry = [
        'timestamp' => $timestamp,
        'microtime' => $microtime,
        'level' => $level,
        'message' => $message,
        'data' => $data,
        'memory_usage' => function_exists('memory_get_usage') ? memory_get_usage(true) : 0,
        'memory_peak' => function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0
    ];
    
    $logline = json_encode($logentry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    
    // Use file_put_contents with LOCK_EX for thread safety
    @file_put_contents($logfile, $logline, FILE_APPEND | LOCK_EX);
}

/**
 * Log database query with timing
 * 
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param float $start_time Start time (microtime)
 * @param float $end_time End time (microtime)
 * @param int $rows_affected Number of rows affected/returned
 * @param bool $error Whether query had an error
 * @param string $error_message Error message if any
 */
function theme_remui_kids_log_query($query, $params = [], $start_time = null, $end_time = null, $rows_affected = 0, $error = false, $error_message = '') {
    $execution_time = $end_time && $start_time ? ($end_time - $start_time) * 1000 : 0;
    
    // Log slow queries (over 100ms) as warnings
    $level = $error ? 'ERROR' : ($execution_time > 100 ? 'WARNING' : 'QUERY');
    
    // Truncate very long queries for readability
    $query_short = strlen($query) > 500 ? substr($query, 0, 500) . '... [truncated]' : $query;
    
    $data = [
        'query' => $query_short,
        'params' => $params,
        'execution_time_ms' => round($execution_time, 2),
        'rows_affected' => $rows_affected,
        'error' => $error,
        'error_message' => $error_message
    ];
    
    theme_remui_kids_log('Database Query', $data, $level);
}

/**
 * Log function execution timing
 * 
 * @param string $function_name Function name
 * @param float $start_time Start time (microtime)
 * @param float $end_time End time (microtime)
 * @param array $context Additional context data
 */
function theme_remui_kids_log_timing($function_name, $start_time, $end_time, $context = []) {
    $execution_time = ($end_time - $start_time) * 1000;
    
    $data = array_merge($context, [
        'function' => $function_name,
        'execution_time_ms' => round($execution_time, 2)
    ]);
    
    $level = $execution_time > 1000 ? 'WARNING' : 'TIMING';
    
    theme_remui_kids_log('Function Execution', $data, $level);
}

/**
 * Log cache operations
 * 
 * @param string $operation Cache operation (hit, miss, set)
 * @param string $key Cache key
 * @param float $time Time taken (optional)
 */
function theme_remui_kids_log_cache($operation, $key, $time = null) {
    $data = [
        'operation' => $operation,
        'key' => $key,
        'time_ms' => $time ? round($time * 1000, 2) : null
    ];
    
    theme_remui_kids_log('Cache Operation', $data, 'INFO');
}

