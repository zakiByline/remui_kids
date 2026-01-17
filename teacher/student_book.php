<?php
/**
 * Student Book Page for Teachers
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lang_init.php');

global $CURRENT_LANG;

if (!isloggedin()) {
    redirect(get_login_url());
}

// Check if user is teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;

    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
         LIMIT 1",
        $params
    );

    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

if (!$isteacher) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You must be a teacher to access this page.</p>";
    echo "<p><a href='" . $CFG->wwwroot . "'>Go Back</a></p>";
    exit;
}

$PAGE->set_url('/theme/remui_kids/teacher/student_book.php');
$PAGE->set_title('Student Book');
$PAGE->set_heading('Student Book');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <?php include(__DIR__ . '/includes/sidebar.php'); ?>

            <div class="main-content">
                <div class="student-book-header">
                    <h1><i class="fa fa-book"></i> Student Book</h1>
                    <p class="text-muted">Access and manage student learning materials</p>
                </div>

                <div class="student-book-content">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Student Learning Resources</h5>
                            <p class="card-text">This section contains learning materials and resources designed for students.</p>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="resource-card">
                                        <i class="fa fa-file-pdf-o fa-3x text-danger"></i>
                                        <h6>PDF Documents</h6>
                                        <p>Access student PDF materials</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="resource-card">
                                        <i class="fa fa-video-camera fa-3x text-primary"></i>
                                        <h6>Video Tutorials</h6>
                                        <p>Educational videos for students</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="resource-card">
                                        <i class="fa fa-question-circle fa-3x text-success"></i>
                                        <h6>Practice Exercises</h6>
                                        <p>Interactive exercises and quizzes</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.student-book-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    margin-bottom: 30px;
    border-radius: 10px;
}

.student-book-header h1 {
    margin: 0;
    font-size: 2.5rem;
}

.resource-card {
    text-align: center;
    padding: 30px 20px;
    background: #f8f9fa;
    border-radius: 10px;
    margin-bottom: 20px;
    transition: transform 0.3s ease;
}

.resource-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.main-content {
    margin-left: 260px;
    padding: 20px;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        width: 100%;
    }
}
</style>

<?php
echo $OUTPUT->footer();
?>