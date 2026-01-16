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
 * Delete submission page
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Require login and admin capability
require_login();
require_capability('moodle/site:config', context_system::instance());

// Check if submission exists
$submission = $DB->get_record('codeeditor_submissions', array('id' => $id));

if (!$submission) {
    throw new moodle_exception('submissionnotfound', 'mod_codeeditor');
}

if ($confirm) {
    // Delete the submission
    $result = $DB->delete_records('codeeditor_submissions', array('id' => $id));
    
    if ($result) {
        // Redirect back to admin page with success message
        redirect(
            new moodle_url('/admin/tool/codeeditor_submissions/index.php'),
            'Submission deleted successfully.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        // Redirect back with error message
        redirect(
            new moodle_url('/admin/tool/codeeditor_submissions/index.php'),
            'Error deleting submission. Please try again.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
} else {
    // Show confirmation page
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url('/mod/codeeditor/delete_submission.php', array('id' => $id));
    $PAGE->set_title('Delete Submission');
    $PAGE->set_heading('Delete Submission');
    
    echo $OUTPUT->header();
    
    echo $OUTPUT->heading('Delete Submission');
    
    echo '<div class="alert alert-warning">';
    echo '<h4>Are you sure you want to delete this submission?</h4>';
    echo '<p>This action cannot be undone. The submission will be permanently removed from the database.</p>';
    echo '<p><strong>Submission ID:</strong> ' . $submission->id . '</p>';
    echo '<p><strong>User ID:</strong> ' . $submission->userid . '</p>';
    echo '<p><strong>Activity ID:</strong> ' . $submission->codeeditorid . '</p>';
    echo '</div>';
    
    echo '<div class="row">';
    echo '<div class="col-md-6">';
    echo '<a href="' . $CFG->wwwroot . '/mod/codeeditor/delete_submission.php?id=' . $id . '&confirm=1" class="btn btn-danger btn-block">Yes, Delete Submission</a>';
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<a href="' . $CFG->wwwroot . '/admin/tool/codeeditor_submissions/index.php" class="btn btn-secondary btn-block">Cancel</a>';
    echo '</div>';
    echo '</div>';
    
    echo $OUTPUT->footer();
}
