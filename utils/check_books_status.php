<?php
/**
 * Diagnostic Script: Check Books Status
 * 
 * This script helps diagnose why books might not be appearing in the E-books page
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/utils/check_books_status.php');
$PAGE->set_title('Books Diagnostic');

echo $OUTPUT->header();
?>

<style>
.diagnostic-container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
}
.diagnostic-section {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.diagnostic-section h2 {
    color: #3498db;
    margin-top: 0;
}
.status-ok {
    color: #27ae60;
    font-weight: bold;
}
.status-warning {
    color: #f39c12;
    font-weight: bold;
}
.status-error {
    color: #e74c3c;
    font-weight: bold;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
}
table th, table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
table th {
    background: #f8f9fa;
    font-weight: 600;
}
.code-block {
    background: #f4f4f4;
    padding: 15px;
    border-radius: 5px;
    font-family: monospace;
    overflow-x: auto;
    margin: 10px 0;
}
.info-box {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 15px;
    margin: 10px 0;
}
</style>

<div class="diagnostic-container">
    <h1>📊 E-books System Diagnostic</h1>
    <p>This page will help you understand why books might not be showing up in the E-books page.</p>

    <?php
    // 1. Check user enrollment
    echo '<div class="diagnostic-section">';
    echo '<h2>1. User Enrollment Status</h2>';
    
    $courses = enrol_get_all_users_courses($USER->id, true);
    $courseids = array_keys($courses);
    
    echo '<p><strong>User:</strong> ' . fullname($USER) . ' (ID: ' . $USER->id . ')</p>';
    echo '<p><strong>Enrolled Courses:</strong> ' . count($courses) . '</p>';
    
    if (empty($courses)) {
        echo '<p class="status-error">❌ You are not enrolled in any courses!</p>';
        echo '<div class="info-box"><strong>Action Required:</strong> You need to be enrolled in at least one course to see books. Contact your administrator or enroll in a course.</div>';
    } else {
        echo '<p class="status-ok">✓ You are enrolled in courses</p>';
        echo '<table>';
        echo '<tr><th>Course ID</th><th>Course Name</th></tr>';
        foreach ($courses as $course) {
            echo '<tr>';
            echo '<td>' . $course->id . '</td>';
            echo '<td>' . $course->fullname . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 2. Check if book module exists
    echo '<div class="diagnostic-section">';
    echo '<h2>2. Book Module Status</h2>';
    
    $book_module = $DB->get_record('modules', ['name' => 'book']);
    if ($book_module) {
        echo '<p class="status-ok">✓ Book module is installed (ID: ' . $book_module->id . ')</p>';
    } else {
        echo '<p class="status-error">❌ Book module is not installed!</p>';
        echo '<div class="info-box"><strong>Action Required:</strong> The Book module needs to be installed in Moodle.</div>';
    }
    echo '</div>';
    
    // 3. Check for books in system
    echo '<div class="diagnostic-section">';
    echo '<h2>3. Books in System</h2>';
    
    $all_books = $DB->get_records('book', null, 'timecreated DESC', '*', 0, 10);
    echo '<p><strong>Total books in system:</strong> ' . $DB->count_records('book') . '</p>';
    
    if (empty($all_books)) {
        echo '<p class="status-error">❌ No books exist in the system!</p>';
        echo '<div class="info-box">';
        echo '<strong>Action Required:</strong> You need to create books first. You can create books by:<br>';
        echo '• Using Moodle UI: Go to a course → Turn editing on → Add activity → Book<br>';
        echo '• Using our web tool: <a href="' . $CFG->wwwroot . '/theme/remui_kids/utils/book_creator.php">Book Creator</a><br>';
        echo '• Check the documentation in /theme/remui_kids/docs/';
        echo '</div>';
    } else {
        echo '<p class="status-ok">✓ Books exist in the system</p>';
        echo '<table>';
        echo '<tr><th>Book ID</th><th>Name</th><th>Course ID</th><th>Created</th></tr>';
        foreach ($all_books as $book) {
            echo '<tr>';
            echo '<td>' . $book->id . '</td>';
            echo '<td>' . $book->name . '</td>';
            echo '<td>' . $book->course . '</td>';
            echo '<td>' . date('Y-m-d H:i', $book->timecreated) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 4. Check for books in user's courses
    if (!empty($courseids)) {
        echo '<div class="diagnostic-section">';
        echo '<h2>4. Books in Your Enrolled Courses</h2>';
        
        list($courseids_sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        
        $books_in_courses = $DB->get_records_sql(
            "SELECT b.id, b.name, b.course, c.fullname as coursename,
                    cm.id as cmid, cm.visible, cm.deletioninprogress
             FROM {book} b
             JOIN {course} c ON b.course = c.id
             LEFT JOIN {course_modules} cm ON cm.instance = b.id
             LEFT JOIN {modules} m ON m.id = cm.module AND m.name = 'book'
             WHERE b.course $courseids_sql
             ORDER BY c.fullname ASC, b.name ASC",
            $params
        );
        
        echo '<p><strong>Books in your courses:</strong> ' . count($books_in_courses) . '</p>';
        
        if (empty($books_in_courses)) {
            echo '<p class="status-warning">⚠ No books found in your enrolled courses!</p>';
            echo '<div class="info-box">';
            echo '<strong>Possible reasons:</strong><br>';
            echo '• Books haven\'t been added to these courses yet<br>';
            echo '• You need to ask your teacher to add books to the course<br>';
            echo '</div>';
        } else {
            echo '<p class="status-ok">✓ Books found in your courses</p>';
            echo '<table>';
            echo '<tr><th>Book ID</th><th>Book Name</th><th>Course</th><th>CM ID</th><th>Visible</th><th>Status</th></tr>';
            foreach ($books_in_courses as $book) {
                echo '<tr>';
                echo '<td>' . $book->id . '</td>';
                echo '<td>' . $book->name . '</td>';
                echo '<td>' . $book->coursename . '</td>';
                echo '<td>' . ($book->cmid ?: '<span class="status-error">Missing!</span>') . '</td>';
                echo '<td>' . ($book->visible ? '✓ Yes' : '✗ No') . '</td>';
                
                // Status
                if (!$book->cmid) {
                    echo '<td class="status-error">No course module!</td>';
                } elseif ($book->deletioninprogress) {
                    echo '<td class="status-error">Being deleted</td>';
                } elseif (!$book->visible) {
                    echo '<td class="status-warning">Hidden</td>';
                } else {
                    echo '<td class="status-ok">OK</td>';
                }
                
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
        
        // 5. Check visible books (what the E-books page will show)
        echo '<div class="diagnostic-section">';
        echo '<h2>5. Visible Books (What E-books Page Shows)</h2>';
        
        $visible_books = $DB->get_records_sql(
            "SELECT b.id, b.name, b.intro, b.course, c.fullname as coursename,
                    cm.id as cmid
             FROM {book} b
             JOIN {course} c ON b.course = c.id
             JOIN {course_modules} cm ON cm.instance = b.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'book'
             WHERE b.course $courseids_sql
             AND cm.visible = 1
             AND cm.deletioninprogress = 0
             ORDER BY c.fullname ASC, b.name ASC",
            $params
        );
        
        echo '<p><strong>Visible books (will appear on E-books page):</strong> ' . count($visible_books) . '</p>';
        
        if (empty($visible_books)) {
            echo '<p class="status-error">❌ No visible books found!</p>';
            echo '<div class="info-box">';
            echo '<strong>This is why your E-books page is empty.</strong><br><br>';
            echo '<strong>Solutions:</strong><br>';
            echo '1. <strong>If books exist but are hidden:</strong> Ask your teacher to make them visible<br>';
            echo '2. <strong>If no books exist:</strong> Books need to be created. Options:<br>';
            echo '   • Teacher can add via course page (Add activity → Book)<br>';
            echo '   • Use our <a href="' . $CFG->wwwroot . '/theme/remui_kids/utils/book_creator.php">Book Creator tool</a><br>';
            echo '   • See documentation for bulk import methods<br>';
            echo '</div>';
        } else {
            echo '<p class="status-ok">✓ Visible books found - these should appear on your E-books page!</p>';
            echo '<table>';
            echo '<tr><th>Book Name</th><th>Course</th><th>View URL</th></tr>';
            foreach ($visible_books as $book) {
                $url = new moodle_url('/mod/book/view.php', ['id' => $book->cmid]);
                echo '<tr>';
                echo '<td>' . $book->name . '</td>';
                echo '<td>' . $book->coursename . '</td>';
                echo '<td><a href="' . $url->out() . '" target="_blank">View Book</a></td>';
                echo '</tr>';
            }
            echo '</table>';
            
            echo '<p><a href="' . $CFG->wwwroot . '/theme/remui_kids/ebooks.php" class="btn btn-primary">Go to E-books Page</a></p>';
        }
        echo '</div>';
    }
    
    // 6. SQL Query for manual checking
    echo '<div class="diagnostic-section">';
    echo '<h2>6. SQL Queries for Manual Checking</h2>';
    echo '<p>You can run these queries directly in your database to check the status:</p>';
    
    echo '<strong>Check all books:</strong>';
    echo '<div class="code-block">SELECT * FROM mdl_book ORDER BY timecreated DESC LIMIT 10;</div>';
    
    echo '<strong>Check books with course modules:</strong>';
    echo '<div class="code-block">';
    echo 'SELECT b.id, b.name, b.course, cm.id as cmid, cm.visible<br>';
    echo 'FROM mdl_book b<br>';
    echo 'LEFT JOIN mdl_course_modules cm ON cm.instance = b.id<br>';
    echo 'LEFT JOIN mdl_modules m ON m.id = cm.module AND m.name = \'book\'<br>';
    echo 'ORDER BY b.id DESC;';
    echo '</div>';
    
    echo '<strong>Check your enrolled courses:</strong>';
    echo '<div class="code-block">';
    echo 'SELECT c.id, c.fullname<br>';
    echo 'FROM mdl_course c<br>';
    echo 'JOIN mdl_enrol e ON e.courseid = c.id<br>';
    echo 'JOIN mdl_user_enrolments ue ON ue.enrolid = e.id<br>';
    echo 'WHERE ue.userid = ' . $USER->id . ';';
    echo '</div>';
    
    echo '</div>';
    
    // 7. Quick actions
    echo '<div class="diagnostic-section">';
    echo '<h2>7. Quick Actions</h2>';
    echo '<p>Based on the diagnostics above, here are some quick actions:</p>';
    echo '<ul>';
    if (is_siteadmin() || has_capability('moodle/course:update', context_system::instance())) {
        echo '<li><a href="' . $CFG->wwwroot . '/theme/remui_kids/utils/book_creator.php">Create a new book</a></li>';
    }
    echo '<li><a href="' . $CFG->wwwroot . '/theme/remui_kids/ebooks.php">View E-books page</a></li>';
    echo '<li><a href="' . $CFG->wwwroot . '/my/courses.php">View my courses</a></li>';
    echo '<li><a href="' . $CFG->wwwroot . '">Return to dashboard</a></li>';
    echo '</ul>';
    echo '</div>';
    ?>
    
</div>

<?php
echo $OUTPUT->footer();


