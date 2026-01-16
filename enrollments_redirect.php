<?php
/**
 * Simple redirect to enrollments page for testing
 */

require_once('../../config.php');
require_login();

global $CFG;

// Simple redirect to the enrollments page
redirect($CFG->wwwroot . '/theme/remui_kids/school_manager/enrollments.php');
?>

