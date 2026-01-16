<?php
/**
 * Grading interface for Code Editor submissions
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/codeeditor/lib.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/grade/grading/lib.php');

// Course module ID
$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('codeeditor', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$codeeditor = $DB->get_record('codeeditor', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

// Allow both teachers (grade capability) and admins (site config) to view submissions
$isteacher = has_capability('mod/codeeditor:grade', $context);
$isadmin = has_capability('moodle/site:config', context_system::instance());

if (!$isteacher && !$isadmin) {
    print_error('nopermissions', 'error', '', 'view submissions');
}

$PAGE->set_url('/mod/codeeditor/grading.php', array('id' => $cm->id));
$PAGE->set_title(format_string($codeeditor->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Handle grading actions
if ($action === 'savegrade' && $userid) {
    require_sesskey();
    
    $submissionid = required_param('submissionid', PARAM_INT);
    $grade = required_param('grade', PARAM_FLOAT);
    $feedback = optional_param('feedback', '', PARAM_RAW);
    
    // Update submission with grade
    $submission = $DB->get_record('codeeditor_submissions', array('id' => $submissionid), '*', MUST_EXIST);
    
    $submission->grade = $grade;
    $submission->grader = $USER->id;
    $submission->feedbacktext = $feedback;
    $submission->feedbackformat = FORMAT_HTML;
    $submission->timegraded = time();
    $submission->timemodified = time();
    
    $DB->update_record('codeeditor_submissions', $submission);
    
    // Update gradebook
    $gradedata = new stdClass();
    $gradedata->userid = $userid;
    $gradedata->rawgrade = $grade;
    $gradedata->dategraded = time();
    $gradedata->datesubmitted = $submission->timecreated;
    
    codeeditor_grade_item_update($codeeditor, $gradedata);
    
    redirect($PAGE->url, get_string('gradessaved', 'grades'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

// Display role info
if ($isadmin) {
    echo '<div class="alert alert-info">';
    echo '<i class="fa fa-shield-alt"></i> <strong>Admin View:</strong> You are viewing submissions as an administrator.';
    echo '</div>';
} else if ($isteacher) {
    echo '<div class="alert alert-success">';
    echo '<i class="fa fa-chalkboard-teacher"></i> <strong>Teacher View:</strong> You can view and grade student submissions.';
    echo '</div>';
}

// Get all submissions for this activity
$sql = "SELECT s.*, u.firstname, u.lastname, u.email,
               CONCAT(u.firstname, ' ', u.lastname) as fullname
        FROM {codeeditor_submissions} s
        JOIN {user} u ON u.id = s.userid
        WHERE s.codeeditorid = ? AND s.latest = 1 AND s.status = 'submitted'
        ORDER BY u.lastname ASC, u.firstname ASC";

$submissions = $DB->get_records_sql($sql, array($codeeditor->id));

// Check if rubric grading is enabled
$gradingmanager = get_grading_manager($context, 'mod_codeeditor', 'submissions');
$gradingmethod = $gradingmanager->get_active_method();
$hasrubric = ($gradingmethod == 'rubric');

?>

<style>
.grading-container {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.grading-header {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.grading-title {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 10px 0;
}

.grading-subtitle {
    color: #7f8c8d;
    font-size: 16px;
}

.submissions-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.submissions-table th {
    background: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
}

.submissions-table td {
    padding: 15px;
    border-bottom: 1px solid #f1f3f4;
}

.submissions-table tr:hover {
    background: #f8f9fa;
}

.student-name {
    font-weight: 600;
    color: #2c3e50;
}

.student-email {
    font-size: 13px;
    color: #6c757d;
}

.grade-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

.grade-badge.graded {
    background: #d4edda;
    color: #155724;
}

.grade-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.btn-grade {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-grade:hover {
    background: #218838;
    color: white;
    transform: translateY(-1px);
    text-decoration: none;
}

.btn-view-code {
    background: #17a2b8;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-view-code:hover {
    background: #138496;
    color: white;
    transform: translateY(-1px);
    text-decoration: none;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.no-submissions {
    text-align: center;
    padding: 60px;
    color: #7f8c8d;
}

.no-submissions i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}
</style>

<div class="grading-container">
    <div class="grading-header">
        <h1 class="grading-title">Grade Submissions</h1>
        <p class="grading-subtitle"><?php echo format_string($codeeditor->name); ?></p>
        <?php if ($hasrubric): ?>
            <div style="background: #e3f2fd; padding: 12px; border-radius: 6px; margin-top: 15px;">
                <i class="fa fa-info-circle" style="color: #1976d2;"></i>
                <strong style="color: #1976d2;">Rubric Grading Enabled</strong> - Grades will be calculated based on rubric criteria
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($submissions)): ?>
        <div class="no-submissions">
            <i class="fa fa-inbox"></i>
            <h3>No submissions yet</h3>
            <p>Students haven't submitted any code for grading.</p>
        </div>
    <?php else: ?>
        <table class="submissions-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Submitted</th>
                    <th>Grade</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $submission): ?>
                    <tr>
                        <td>
                            <div class="student-name"><?php echo $submission->fullname; ?></div>
                            <div class="student-email"><?php echo $submission->email; ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($submission->language ?: 'Not specified'); ?></td>
                        <td><?php echo userdate($submission->timecreated, get_string('strftimedatetimeshort')); ?></td>
                        <td>
                            <?php if ($submission->grade !== null && $submission->grade >= 0): ?>
                                <span class="grade-badge graded">
                                    <i class="fa fa-check-circle"></i>
                                    <?php echo round($submission->grade, 2); ?> / <?php echo $codeeditor->grade; ?>
                                </span>
                            <?php else: ?>
                                <span class="grade-badge pending">
                                    <i class="fa fa-clock"></i>
                                    Not graded
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="view_single_submission.php?id=<?php echo $submission->id; ?>&returnurl=<?php echo urlencode($PAGE->url); ?>" 
                                   class="btn-view-code" title="View Code and Output">
                                    <i class="fa fa-code"></i> View Code
                                </a>
                                <?php if ($hasrubric): ?>
                                    <a href="<?php echo $CFG->wwwroot; ?>/grade/grading/form/rubric/edit.php?contextid=<?php echo $context->id; ?>&component=mod_codeeditor&ratingarea=submissions&itemid=<?php echo $submission->id; ?>&returnurl=<?php echo urlencode($PAGE->url); ?>" 
                                       class="btn-grade" title="Grade with Rubric">
                                        <i class="fa fa-list-alt"></i> Grade
                                    </a>
                                <?php else: ?>
                                    <a href="grade_submission.php?id=<?php echo $cm->id; ?>&userid=<?php echo $submission->userid; ?>" 
                                       class="btn-grade" title="Grade Submission">
                                        <i class="fa fa-star"></i> Grade
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();



