# Performance Logs for Elementary Dashboard

## Location
Logs are stored in: `{moodledata}/theme_remui_kids_logs/elementary_dashboard_YYYY-MM-DD.log`

Example: `/var/www/moodledata/theme_remui_kids_logs/elementary_dashboard_2025-01-27.log`

## What is Logged

The logging system tracks:

1. **Function Execution Times** - How long each function takes
2. **Database Queries** - All SQL queries with execution times and row counts
3. **Slow Queries** - Queries taking more than 100ms are marked as WARNING
4. **Cache Operations** - Cache hits, misses, and set operations
5. **Slow Course Processing** - Courses taking more than 500ms to process
6. **Slow get_fast_modinfo** - Calls taking more than 100ms
7. **Errors** - All exceptions and errors with stack traces
8. **Memory Usage** - Memory consumption at each log point

## Log Format

Each log entry is a JSON object with:
- `timestamp` - Human-readable timestamp
- `microtime` - Precise microsecond timestamp
- `level` - Log level (INFO, WARNING, ERROR, QUERY, TIMING)
- `message` - Log message
- `data` - Additional context data
- `memory_usage` - Current memory usage in bytes
- `memory_peak` - Peak memory usage in bytes

## Analyzing Logs

### Find Slow Queries
```bash
grep '"level":"WARNING"' elementary_dashboard_*.log | grep '"execution_time_ms"'
```

### Find Errors
```bash
grep '"level":"ERROR"' elementary_dashboard_*.log
```

### Find Slow Course Processing
```bash
grep '"message":"Slow course processing"' elementary_dashboard_*.log
```

### Find Slow get_fast_modinfo Calls
```bash
grep '"message":"Slow get_fast_modinfo"' elementary_dashboard_*.log
```

### Calculate Total Dashboard Load Time
```bash
grep '"message":"Elementary dashboard completed"' elementary_dashboard_*.log | jq '.data.total_time_ms'
```

## Common Issues to Look For

1. **Slow Database Queries** - Look for WARNING level QUERY entries with high execution_time_ms
2. **Missing Indexes** - Queries on large tables without proper indexes
3. **Cache Misses** - If cache is always missing, check cache configuration
4. **Memory Issues** - Check memory_peak values for memory leaks
5. **Slow Course Processing** - Individual courses taking too long to process

## Disabling Logs

To disable logging, comment out or remove the `require_once(__DIR__ . '/lib/performance_logger.php');` lines in:
- `lib.php` (in both stats and courses functions)
- `layout/drawers.php` (in elementary dashboard section)

## Log Rotation

Logs are created daily (one file per day). Old logs should be manually cleaned up or use a log rotation tool.




