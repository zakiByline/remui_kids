<?php
/**
 * Database Test Page for Custom Grader Report
 * This page helps debug database connection issues
 */

require_once('../../../config.php');

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/test_database.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Database Test');
$PAGE->set_heading('Database Test');

// Check if user has admin capabilities
require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();

echo '<div class="container">';
echo '<h2>Database Connection Test</h2>';

// Test 1: Basic database connection
echo '<h3>Test 1: Basic Database Connection</h3>';
try {
    $test_query = $DB->get_records_sql("SELECT 1 as test");
    echo '<p style="color: green;">✅ Database connection successful</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">❌ Database connection failed: ' . $e->getMessage() . '</p>';
}

// Test 2: Check if required tables exist
echo '<h3>Test 2: Required Tables Check</h3>';
$required_tables = array(
    'user' => 'mdl_user',
    'cohort' => 'mdl_cohort', 
    'cohort_members' => 'mdl_cohort_members',
    'course' => 'mdl_course',
    'user_enrolments' => 'mdl_user_enrolments',
    'enrol' => 'mdl_enrol',
    'grade_items' => 'mdl_grade_items',
    'grade_grades' => 'mdl_grade_grades'
);

foreach ($required_tables as $table => $description) {
    try {
        $result = $DB->get_records_sql("SELECT COUNT(*) as count FROM {" . $table . "} LIMIT 1");
        echo '<p style="color: green;">✅ Table {' . $table . '} exists</p>';
    } catch (Exception $e) {
        echo '<p style="color: red;">❌ Table {' . $table . '} missing: ' . $e->getMessage() . '</p>';
    }
}

// Test 3: Check for sample data
echo '<h3>Test 3: Sample Data Check</h3>';

// Check users
try {
    $user_count = $DB->count_records('user', array('deleted' => 0, 'suspended' => 0));
    echo '<p>Total active users: ' . $user_count . '</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">❌ Error counting users: ' . $e->getMessage() . '</p>';
}

// Check cohorts
try {
    $cohort_count = $DB->count_records('cohort');
    echo '<p>Total cohorts: ' . $cohort_count . '</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">❌ Error counting cohorts: ' . $e->getMessage() . '</p>';
}

// Check courses
try {
    $course_count = $DB->count_records('course', array('visible' => 1));
    echo '<p>Total visible courses: ' . $course_count . '</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">❌ Error counting courses: ' . $e->getMessage() . '</p>';
}

// Test 4: Test the actual query from custom grader report
echo '<h3>Test 4: Custom Grader Report Query Test</h3>';
try {
    $test_sql = "SELECT 
                    u.id,
                    u.firstname,
                    u.lastname,
                    u.email,
                    COUNT(DISTINCT c.id) as course_count
                FROM {user} u
                LEFT JOIN {user_enrolments} ue ON u.id = ue.userid
                LEFT JOIN {enrol} e ON ue.enrolid = e.id
                LEFT JOIN {course} c ON e.courseid = c.id
                WHERE u.deleted = 0 AND u.suspended = 0
                GROUP BY u.id, u.firstname, u.lastname, u.email
                LIMIT 5";
    
    $test_results = $DB->get_records_sql($test_sql);
    echo '<p style="color: green;">✅ Custom query executed successfully</p>';
    echo '<p>Found ' . count($test_results) . ' users with course enrollments</p>';
    
    if (count($test_results) > 0) {
        echo '<table border="1" style="border-collapse: collapse; margin: 10px 0;">';
        echo '<tr><th>ID</th><th>Name</th><th>Email</th><th>Courses</th></tr>';
        foreach ($test_results as $user) {
            echo '<tr>';
            echo '<td>' . $user->id . '</td>';
            echo '<td>' . $user->firstname . ' ' . $user->lastname . '</td>';
            echo '<td>' . $user->email . '</td>';
            echo '<td>' . $user->course_count . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
} catch (Exception $e) {
    echo '<p style="color: red;">❌ Custom query failed: ' . $e->getMessage() . '</p>';
}

// Test 5: Check database configuration
echo '<h3>Test 5: Database Configuration</h3>';
echo '<p>Database type: ' . $CFG->dbtype . '</p>';
echo '<p>Database host: ' . $CFG->dbhost . '</p>';
echo '<p>Database name: ' . $CFG->dbname . '</p>';

echo '</div>';

echo $OUTPUT->footer();
?>










































