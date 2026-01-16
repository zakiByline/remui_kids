<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * View single submission page
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

// Require login
require_login();

// Get submission details
$sql = "
    SELECT s.*, u.firstname, u.lastname, u.email, ce.name as activity_name, 
           ce.intro as activity_intro, ce.introformat as activity_introformat,
           c.fullname as course_name, c.id as course_id, cat.name as category_name,
           cm.id as cmid
    FROM {codeeditor_submissions} s
    LEFT JOIN {user} u ON s.userid = u.id
    LEFT JOIN {codeeditor} ce ON s.codeeditorid = ce.id
    LEFT JOIN {course} c ON ce.course = c.id
    LEFT JOIN {course_categories} cat ON c.category = cat.id
    LEFT JOIN {course_modules} cm ON cm.instance = ce.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'codeeditor')
    WHERE s.id = ?
";

$submission = $DB->get_record_sql($sql, array($id));

if (!$submission) {
    throw new moodle_exception('submissionnotfound', 'mod_codeeditor');
}

// Check permissions - admin OR teacher of this course OR the student who submitted
$coursecontext = context_course::instance($submission->course_id);
$isadmin = has_capability('moodle/site:config', context_system::instance());
$isteacher = has_capability('mod/codeeditor:grade', $coursecontext);
$isownsubmission = ($submission->userid == $USER->id);

if (!$isadmin && !$isteacher && !$isownsubmission) {
    throw new moodle_exception('nopermissions', 'error', '', 'view this submission');
}

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/mod/codeeditor/view_single_submission.php', array('id' => $id));
$PAGE->set_title('View Submission - ' . $submission->activity_name);
$PAGE->set_heading('View Submission');

echo $OUTPUT->header();

echo $OUTPUT->heading('View Submission Details');

// Display submission information
echo '<div class="row">';
echo '<div class="col-md-6">';
echo '<div class="card">';
echo '<div class="card-header"><h5>Submission Information</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-borderless">';
echo '<tr><td><strong>Submission ID:</strong></td><td>' . $submission->id . '</td></tr>';
echo '<tr><td><strong>Student:</strong></td><td>' . htmlspecialchars($submission->firstname . ' ' . $submission->lastname) . '</td></tr>';
echo '<tr><td><strong>Email:</strong></td><td>' . htmlspecialchars($submission->email) . '</td></tr>';
echo '<tr><td><strong>Course:</strong></td><td>' . htmlspecialchars($submission->course_name) . '</td></tr>';
echo '<tr><td><strong>Category:</strong></td><td>' . htmlspecialchars($submission->category_name ?: 'Uncategorized') . '</td></tr>';
echo '<tr><td><strong>Activity:</strong></td><td>' . htmlspecialchars($submission->activity_name) . '</td></tr>';
echo '<tr><td><strong>Language:</strong></td><td>' . htmlspecialchars($submission->language) . '</td></tr>';
echo '<tr><td><strong>Submitted:</strong></td><td>' . userdate($submission->timecreated, '%Y-%m-%d %H:%M:%S') . '</td></tr>';
echo '<tr><td><strong>Status:</strong></td><td>' . htmlspecialchars($submission->status) . '</td></tr>';
echo '</table>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<div class="card">';
echo '<div class="card-header"><h5>Actions</h5></div>';
echo '<div class="card-body">';

// Determine back URL
if (!empty($returnurl)) {
    $backurl = $returnurl;
} else if (!empty($submission->cmid)) {
    // Go back to grading page
    $backurl = $CFG->wwwroot . '/mod/codeeditor/grading.php?id=' . $submission->cmid;
} else {
    // Fallback to admin submissions
    $backurl = $CFG->wwwroot . '/admin/tool/codeeditor_submissions/index.php';
}

// Determine back button URL based on user role
if ($isownsubmission && !$isadmin && !$isteacher) {
    // For students, go back to the activity view
    $backurl = $CFG->wwwroot . '/mod/codeeditor/view.php?id=' . $submission->cmid;
    echo '<a href="' . $backurl . '" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back to Activity</a><br><br>';
} else {
    // For admin/teacher, go back to grading
    echo '<a href="' . $backurl . '" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back to Submissions</a><br><br>';
    
    if ($isadmin) {
        echo '<a href="' . $CFG->wwwroot . '/mod/codeeditor/delete_submission.php?id=' . $id . '&confirm=1" class="btn btn-danger" onclick="return confirm(\'Are you sure you want to delete this submission? This action cannot be undone.\')"><i class="fa fa-trash"></i> Delete Submission</a>';
    }
}

echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Display the assignment question/instructions if available
if (!empty($submission->activity_intro)) {
    echo '<div class="row mt-4">';
    echo '<div class="col-12">';
    echo '<div class="card">';
    echo '<div class="card-header" style="background: #ffc107; color: #000;"><h5 style="margin: 0;"><i class="fa fa-question-circle"></i> Assignment Question</h5></div>';
    echo '<div class="card-body" style="background: #fffbf0; padding: 20px;">';
    echo '<div style="font-size: 14px; line-height: 1.8;">';
    echo format_text($submission->activity_intro, $submission->activity_introformat);
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Check if this is HTML/CSS code
$ishtmlcss = false;
$code_lower = strtolower($submission->code);
if (strpos($code_lower, '<html') !== false || 
    strpos($code_lower, '<!doctype html') !== false || 
    strpos($code_lower, '<div') !== false || 
    strpos($code_lower, '<body') !== false ||
    strpos($code_lower, '<style>') !== false ||
    $submission->language == 'html' || 
    $submission->language == 'htmlcss') {
    $ishtmlcss = true;
}

// Display the code
echo '<div class="row mt-4">';
echo '<div class="col-12">';
echo '<div class="card">';
echo '<div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">';
echo '<h5 style="margin: 0;">Code Submission</h5>';
if ($ishtmlcss) {
    echo '<button type="button" onclick="previewWebPage()" class="btn btn-primary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 8px 16px; border-radius: 6px; color: white; font-weight: bold; cursor: pointer;">';
    echo '<i class="fa fa-eye"></i> Preview Web Page';
    echo '</button>';
}
echo '</div>';
echo '<div class="card-body">';
echo '<pre class="bg-light p-3" style="background: white; color: #212529; max-height: 500px; overflow-y: auto; font-family: monospace; font-size: 14px; border: 2px solid #28a745; border-radius: 8px; padding: 20px;">';
echo htmlspecialchars($submission->code);
echo '</pre>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Display output if available
if (!empty($submission->output)) {
    echo '<div class="row mt-4">';
    echo '<div class="col-12">';
    echo '<div class="card">';
    echo '<div class="card-header"><h5>Output</h5></div>';
    echo '<div class="card-body">';
    echo '<pre class="bg-light p-3" style="background: white; color: #212529; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 14px; border: 2px solid #17a2b8; border-radius: 8px; padding: 20px;">';
    echo htmlspecialchars($submission->output);
    echo '</pre>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Preview Modal for HTML/CSS
if ($ishtmlcss) {
    echo '<!-- Preview Modal for HTML/CSS -->';
    echo '<div id="previewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; padding: 20px;">';
    echo '<div style="background: white; width: 100%; height: 100%; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column;">';
    echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">';
    echo '<h3 style="margin: 0; color: white; font-size: 20px; font-weight: bold;"><i class="fa fa-eye"></i> Web Page Preview</h3>';
    echo '<button onclick="closePreview()" style="background: rgba(255,255,255,0.2); color: white; border: 2px solid white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold;"><i class="fa fa-times"></i> Close</button>';
    echo '</div>';
    echo '<div style="flex: 1; padding: 20px; overflow: hidden;">';
    echo '<iframe id="previewFrame" style="width: 100%; height: 100%; border: none; border-radius: 8px; background: white;"></iframe>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>';
    echo 'function previewWebPage() {';
    echo '    var code = ' . json_encode($submission->code) . ';';
    echo '    var modal = document.getElementById("previewModal");';
    echo '    var iframe = document.getElementById("previewFrame");';
    echo '    var htmlContent = code;';
    echo '    if (!htmlContent.toLowerCase().includes("<!doctype") && !htmlContent.toLowerCase().includes("<html")) {';
    echo '        htmlContent = "<!DOCTYPE html>\\n<html>\\n<head>\\n<meta charset=\\"UTF-8\\">\\n<meta name=\\"viewport\\" content=\\"width=device-width, initial-scale=1.0\\">\\n<title>Preview</title>\\n</head>\\n<body>\\n" + code + "\\n</body>\\n</html>";';
    echo '    }';
    echo '    try {';
    echo '        var blob = new Blob([htmlContent], { type: "text/html" });';
    echo '        var url = URL.createObjectURL(blob);';
    echo '        iframe.src = url;';
    echo '    } catch (e) {';
    echo '        try { iframe.srcdoc = htmlContent; } catch (e2) {';
    echo '            try {';
    echo '                iframe.contentDocument.open();';
    echo '                iframe.contentDocument.write(htmlContent);';
    echo '                iframe.contentDocument.close();';
    echo '            } catch (e3) { alert("Error previewing HTML"); }';
    echo '        }';
    echo '    }';
    echo '    modal.style.display = "block";';
    echo '    document.body.style.overflow = "hidden";';
    echo '}';
    echo 'function closePreview() {';
    echo '    var modal = document.getElementById("previewModal");';
    echo '    var iframe = document.getElementById("previewFrame");';
    echo '    if (iframe.src && iframe.src.startsWith("blob:")) { URL.revokeObjectURL(iframe.src); }';
    echo '    iframe.src = "about:blank";';
    echo '    modal.style.display = "none";';
    echo '    document.body.style.overflow = "auto";';
    echo '}';
    echo 'document.addEventListener("keydown", function(e) { if (e.key === "Escape") closePreview(); });';
    echo 'document.getElementById("previewModal").addEventListener("click", function(e) { if (e.target === this) closePreview(); });';
    echo '</script>';
}

echo $OUTPUT->footer();
