<?php
/**
 * AJAX endpoint for getting real-time course header data
 * 
 * @package theme_remui_kids
 * @copyright (c) 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Suppress all output until we're ready
ob_start();

// Disable error display but keep error reporting for logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Clear any output
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Fatal PHP Error',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
            'status' => 'error'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// Include config.php first - it sets up error handling and sessions
$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Configuration file not found',
        'message' => 'config.php not found at: ' . $configpath,
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once($configpath);

// Clear output buffer after config.php loads (it may output warnings)
ob_clean();

// Verify config.php loaded correctly
if (!isset($CFG)) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Configuration error',
        'message' => 'Failed to load Moodle configuration - $CFG not set',
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Now set content type to JSON (clear buffer first)
ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    // Verify database is available
    if (!isset($DB)) {
        throw new Exception('Database object ($DB) not available');
    }
    
    require_once($CFG->dirroot . '/enrol/locallib.php');
    require_once($CFG->dirroot . '/course/lib.php');
    
    // Check if user is logged in
    require_login(null, false);
    
    // Verify user object is available
    if (!isset($USER) || !isset($USER->id)) {
        throw new Exception('User object ($USER) not available or user not logged in');
    }
    
    // Get course ID from request (use optional_param first to check if it exists)
    $courseid = optional_param('courseid', 0, PARAM_INT);
    
    // Validate course ID
    if (empty($courseid) || $courseid <= 0) {
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid course ID',
            'message' => 'Course ID is required and must be a positive integer',
            'status' => 'error'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get course record
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    
    // Check if user can access the course (more lenient than require_capability)
    $context = context_course::instance($course->id, IGNORE_MISSING);
    if (!$context) {
        throw new moodle_exception('invalidcourseid', 'error');
    }
    
    // Check if user is enrolled or has any role in the course
    // This is more lenient than require_capability which requires 'view without participation'
    $isenrolled = is_enrolled($context, $USER->id, '', true);
    $hasrole = false;
    
    if (!$isenrolled) {
        // Check if user has any role assignment in this course
        $hasrole = $DB->record_exists_sql(
            "SELECT 1 FROM {role_assignments} ra 
             JOIN {context} ctx ON ra.contextid = ctx.id 
             WHERE ctx.instanceid = ? AND ctx.contextlevel = ? AND ra.userid = ?",
            [$course->id, CONTEXT_COURSE, $USER->id]
        );
    }
    
    // If user is neither enrolled nor has a role, deny access
    if (!$isenrolled && !$hasrole) {
        ob_clean();
        http_response_code(403);
        echo json_encode([
            'error' => 'Access denied',
            'message' => 'You do not have access to this course',
            'status' => 'error'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Include the theme library
    $libpath = $CFG->dirroot . '/theme/remui_kids/lib.php';
    if (!file_exists($libpath)) {
        throw new Exception('Theme library file not found: ' . $libpath);
    }
    require_once($libpath);
    
    // Verify function exists
    if (!function_exists('theme_remui_kids_get_course_header_data')) {
        throw new Exception('Function theme_remui_kids_get_course_header_data not found in lib.php');
    }
    
    // Get real-time course header data with error handling
    try {
        $headerdata = theme_remui_kids_get_course_header_data($course);
    } catch (Exception $funcError) {
        throw new Exception('Error calling theme_remui_kids_get_course_header_data: ' . $funcError->getMessage());
    } catch (Error $funcError) {
        throw new Exception('Fatal error in theme_remui_kids_get_course_header_data: ' . $funcError->getMessage());
    }
    
    // Verify we got valid data
    if (!is_array($headerdata)) {
        $datatype = gettype($headerdata);
        throw new Exception('Invalid data returned from theme_remui_kids_get_course_header_data. Expected array, got: ' . $datatype);
    }
    
    // Format the data for JSON response
    $response = [
        'enrolledstudentscount' => isset($headerdata['enrolledstudentscount']) ? $headerdata['enrolledstudentscount'] : 0,
        'teacherscount' => isset($headerdata['teacherscount']) ? $headerdata['teacherscount'] : 0,
        'startdate' => isset($headerdata['startdate']) ? $headerdata['startdate'] : 'No Start Date',
        'enddate' => isset($headerdata['enddate']) ? $headerdata['enddate'] : 'No End Date',
        'duration' => isset($headerdata['duration']) ? $headerdata['duration'] : '10 Weeks',
        'sectionscount' => isset($headerdata['sectionscount']) ? $headerdata['sectionscount'] : 0,
        'lessonscount' => isset($headerdata['lessonscount']) ? $headerdata['lessonscount'] : 0,
        'teachers' => [],
        'timestamp' => time(),
        'status' => 'success'
    ];
    
    // Format teachers data safely
    if (isset($headerdata['teachers']) && is_array($headerdata['teachers'])) {
        foreach ($headerdata['teachers'] as $teacher) {
            $profileimageurl = '';
            if (isset($teacher['profileimageurl'])) {
                if (is_object($teacher['profileimageurl']) && method_exists($teacher['profileimageurl'], 'out')) {
                    $profileimageurl = $teacher['profileimageurl']->out();
                } else if (is_string($teacher['profileimageurl'])) {
                    $profileimageurl = $teacher['profileimageurl'];
                }
            }
            
            $response['teachers'][] = [
                'id' => isset($teacher['id']) ? $teacher['id'] : 0,
                'fullname' => isset($teacher['fullname']) ? $teacher['fullname'] : '',
                'profileimageurl' => $profileimageurl
            ];
        }
    }
    
    // Clear any output before sending JSON
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
    
} catch (moodle_exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'error' => 'Failed to fetch course header data',
        'message' => $e->getMessage(),
        'errorcode' => $e->errorcode,
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'error' => 'PHP Error',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>






