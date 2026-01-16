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
 * Test Admin Tool Access
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

// Require login and admin capability
require_login();
require_capability('moodle/site:config', context_system::instance());

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/mod/codeeditor/test_admin_tool.php');
$PAGE->set_title('Test Admin Tool Access');
$PAGE->set_heading('Test Admin Tool Access');

echo $OUTPUT->header();

echo $OUTPUT->heading('Code Editor Admin Tool Test');

echo '<div class="alert alert-info">';
echo '<h4>Admin Tool Status Check</h4>';
echo '<p>This page tests the admin tool setup and accessibility.</p>';
echo '</div>';

// Check if admin tool files exist
$admin_tool_exists = file_exists($CFG->dirroot . '/admin/tool/codeeditor_submissions/index.php');
$settings_exists = file_exists($CFG->dirroot . '/admin/tool/codeeditor_submissions/settings.php');
$lang_exists = file_exists($CFG->dirroot . '/admin/tool/codeeditor_submissions/lang/en/tool_codeeditor_submissions.php');

echo '<div class="card mb-4">';
echo '<div class="card-header"><h5>File Status Check</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-borderless">';
echo '<tr><td><strong>Admin Tool Index:</strong></td><td>' . ($admin_tool_exists ? '<span class="badge badge-success">✓ Exists</span>' : '<span class="badge badge-danger">✗ Missing</span>') . '</td></tr>';
echo '<tr><td><strong>Admin Settings:</strong></td><td>' . ($settings_exists ? '<span class="badge badge-success">✓ Exists</span>' : '<span class="badge badge-danger">✗ Missing</span>') . '</td></tr>';
echo '<tr><td><strong>Language File:</strong></td><td>' . ($lang_exists ? '<span class="badge badge-success">✓ Exists</span>' : '<span class="badge badge-danger">✗ Missing</span>') . '</td></tr>';
echo '</table>';
echo '</div>';
echo '</div>';

// Check database table
$table_exists = $DB->get_manager()->table_exists('codeeditor_submissions');

echo '<div class="card mb-4">';
echo '<div class="card-header"><h5>Database Status Check</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-borderless">';
echo '<tr><td><strong>Submissions Table:</strong></td><td>' . ($table_exists ? '<span class="badge badge-success">✓ Exists</span>' : '<span class="badge badge-danger">✗ Missing</span>') . '</td></tr>';

if ($table_exists) {
    $submission_count = $DB->count_records('codeeditor_submissions');
    echo '<tr><td><strong>Total Submissions:</strong></td><td>' . $submission_count . '</td></tr>';
    
    $activity_count = $DB->count_records('codeeditor');
    echo '<tr><td><strong>Code Editor Activities:</strong></td><td>' . $activity_count . '</td></tr>';
}
echo '</table>';
echo '</div>';
echo '</div>';

// Test admin tool access
echo '<div class="card mb-4">';
echo '<div class="card-header"><h5>Admin Tool Access Test</h5></div>';
echo '<div class="card-body">';

if ($admin_tool_exists && $settings_exists && $lang_exists && $table_exists) {
    echo '<div class="alert alert-success">';
    echo '<h5>✓ All Components Ready!</h5>';
    echo '<p>The admin tool is properly set up and ready to use.</p>';
    echo '</div>';
    
    echo '<div class="row">';
    echo '<div class="col-md-6">';
    echo '<a href="' . $CFG->wwwroot . '/admin/tool/codeeditor_submissions/index.php" class="btn btn-primary btn-block">Access Admin Dashboard</a>';
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=tools" class="btn btn-secondary btn-block">Admin Settings Page</a>';
    echo '</div>';
    echo '</div>';
    
    echo '<hr>';
    echo '<h6>How to Access the Admin Tool:</h6>';
    echo '<ol>';
    echo '<li>Go to <strong>Site Administration</strong></li>';
    echo '<li>Navigate to <strong>Tools</strong></li>';
    echo '<li>Click on <strong>Code Editor Submissions</strong></li>';
    echo '</ol>';
    
} else {
    echo '<div class="alert alert-warning">';
    echo '<h5>⚠ Setup Issues Detected</h5>';
    echo '<p>Some components are missing. Please check the file status above.</p>';
    echo '</div>';
}

echo '</div>';
echo '</div>';

// Show current user info
echo '<div class="card">';
echo '<div class="card-header"><h5>Current User Info</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-borderless">';
echo '<tr><td><strong>User ID:</strong></td><td>' . $USER->id . '</td></tr>';
echo '<tr><td><strong>Username:</strong></td><td>' . htmlspecialchars($USER->username) . '</td></tr>';
echo '<tr><td><strong>Full Name:</strong></td><td>' . htmlspecialchars(fullname($USER)) . '</td></tr>';
echo '<tr><td><strong>Admin Status:</strong></td><td>' . (is_siteadmin() ? '<span class="badge badge-success">Site Administrator</span>' : '<span class="badge badge-warning">Regular User</span>') . '</td></tr>';
echo '</table>';
echo '</div>';
echo '</div>';

echo '<hr>';
echo '<a href="' . $CFG->wwwroot . '/mod/codeeditor/view.php" class="btn btn-outline-primary">← Back to Code Editor</a>';

echo $OUTPUT->footer();
