<?php
/**
 * Debug script to check certificate display for a specific course
 * Usage: http://localhost/kodeit/iomad/theme/remui_kids/lib/debug_certificate.php?courseid=39
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/certificate_completion.php');

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$courseid) {
    $courseid = required_param('courseid', PARAM_INT);
}

$course = get_course($courseid);
$userid = $USER->id;

echo "<h1>Certificate Debug for Course: {$course->fullname} (ID: {$courseid})</h1>";
echo "<h2>User: " . fullname($USER) . " (ID: {$userid})</h2>";
echo "<hr>";

// Check completion
require_once($CFG->libdir . '/completionlib.php');
$completion = new completion_info($course);
echo "<h3>1. Completion Status</h3>";
echo "<p><strong>Completion Enabled:</strong> " . ($completion->is_enabled() ? "YES" : "NO") . "</p>";

if (!$completion->is_enabled()) {
    echo "<p style='color: red;'>Completion is not enabled for this course.</p>";
    exit;
}

// Check database completion
$ccompletion = new completion_completion(array('userid' => $userid, 'course' => $courseid));
echo "<p><strong>Course Marked Complete:</strong> " . ($ccompletion->is_complete() ? "YES" : "NO") . "</p>";
echo "<p><strong>Time Completed:</strong> " . ($ccompletion->timecompleted ? date('Y-m-d H:i:s', $ccompletion->timecompleted) : "NULL") . "</p>";

// Direct DB check
$completion_record = $DB->get_record('course_completions', array(
    'userid' => $userid,
    'course' => $courseid
));
if ($completion_record) {
    echo "<p><strong>DB Record Exists:</strong> YES</p>";
    echo "<p><strong>DB timecompleted:</strong> " . ($completion_record->timecompleted ? date('Y-m-d H:i:s', $completion_record->timecompleted) : "NULL") . "</p>";
} else {
    echo "<p><strong>DB Record Exists:</strong> NO</p>";
}

// Check certificates
echo "<h3>2. Certificate Status</h3>";
require_once($CFG->dirroot . '/local/certificate_approval/classes/certificate_manager.php');
$all_certificates = mod_certificate_approval_certificate_manager::get_user_certificates($userid);
echo "<p><strong>Total Certificates for User:</strong> " . count($all_certificates) . "</p>";

$coursecertificate = null;
foreach ($all_certificates as $cert) {
    if ($cert->course_id == $courseid) {
        $coursecertificate = $cert;
        break;
    }
}

if ($coursecertificate) {
    echo "<p style='color: green;'><strong>Certificate Found:</strong> YES</p>";
    echo "<p><strong>Certificate ID:</strong> {$coursecertificate->id}</p>";
    echo "<p><strong>Status:</strong> {$coursecertificate->status}</p>";
    echo "<p><strong>Created:</strong> " . date('Y-m-d H:i:s', $coursecertificate->created_at) . "</p>";
} else {
    echo "<p style='color: orange;'><strong>Certificate Found:</strong> NO</p>";
}

// Check template
echo "<h3>3. Certificate Template Status</h3>";
$schoolid = null;
if ($DB->get_manager()->table_exists('company_users')) {
    $companyuser = $DB->get_record('company_users', array('userid' => $userid));
    if ($companyuser) {
        $schoolid = $companyuser->companyid;
        echo "<p><strong>School ID:</strong> {$schoolid}</p>";
    }
}

if ($DB->get_manager()->table_exists('mod_certificate_approval_course_templates')) {
    $conditions = array('course_id' => $courseid, 'is_active' => 1);
    if ($schoolid !== null) {
        $conditions['school_id'] = $schoolid;
    }
    $template = $DB->get_record('mod_certificate_approval_course_templates', $conditions);
    
    if (!$template && $schoolid !== null) {
        $template = $DB->get_record('mod_certificate_approval_course_templates', array(
            'school_id' => $schoolid,
            'course_id' => null,
            'is_active' => 1
        ));
    }
    
    if (!$template) {
        $template = $DB->get_record('mod_certificate_approval_course_templates', array(
            'school_id' => null,
            'course_id' => null,
            'is_active' => 1
        ));
    }
    
    if ($template) {
        echo "<p style='color: green;'><strong>Template Exists:</strong> YES</p>";
        echo "<p><strong>Template ID:</strong> {$template->template_id}</p>";
    } else {
        echo "<p style='color: red;'><strong>Template Exists:</strong> NO</p>";
    }
}

// Check activity completion details
echo "<h3>4. Activity Completion Details</h3>";
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$has_activities = false;
$has_completable_activities = false;
$all_completed = true;
$activity_details = array();

foreach ($sections as $section) {
    if ($section->section == 0) {
        continue; // Skip section 0
    }
    
    $cms = $modinfo->get_cms();
    $section_cms = array_filter($cms, function($cm) use ($section) {
        return $cm->sectionnum == $section->section;
    });
    
    if (empty($section_cms)) {
        continue;
    }
    
    $has_activities = true;
    
    foreach ($section_cms as $cm) {
        if ($cm->modname === 'label') {
            continue;
        }
        
        $cmcompletion = $completion->is_enabled($cm);
        if ($cmcompletion == COMPLETION_TRACKING_NONE) {
            continue;
        }
        
        $has_completable_activities = true;
        $completiondata = $completion->get_data($cm, false, $userid);
        
        $activity_details[] = array(
            'name' => $cm->name,
            'type' => $cm->modname,
            'section' => $section->section,
            'completion_state' => $completiondata->completionstate,
            'is_complete' => ($completiondata->completionstate == COMPLETION_COMPLETE || $completiondata->completionstate == COMPLETION_COMPLETE_PASS)
        );
        
        if ($completiondata->completionstate != COMPLETION_COMPLETE && 
            $completiondata->completionstate != COMPLETION_COMPLETE_PASS) {
            $all_completed = false;
        }
    }
}

echo "<p><strong>Has Activities:</strong> " . ($has_activities ? "YES" : "NO") . "</p>";
echo "<p><strong>Has Completable Activities:</strong> " . ($has_completable_activities ? "YES" : "NO") . "</p>";
echo "<p><strong>All Activities Complete:</strong> " . ($all_completed ? "YES" : "NO") . "</p>";

if (!empty($activity_details)) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-top: 10px;'>";
    echo "<tr><th>Activity Name</th><th>Type</th><th>Section</th><th>Completion State</th><th>Is Complete</th></tr>";
    foreach ($activity_details as $act) {
        $state_text = '';
        switch($act['completion_state']) {
            case COMPLETION_INCOMPLETE: $state_text = 'INCOMPLETE'; break;
            case COMPLETION_COMPLETE: $state_text = 'COMPLETE'; break;
            case COMPLETION_COMPLETE_PASS: $state_text = 'COMPLETE_PASS'; break;
            case COMPLETION_COMPLETE_FAIL: $state_text = 'COMPLETE_FAIL'; break;
            default: $state_text = 'UNKNOWN (' . $act['completion_state'] . ')';
        }
        $color = $act['is_complete'] ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$act['name']}</td>";
        echo "<td>{$act['type']}</td>";
        echo "<td>{$act['section']}</td>";
        echo "<td>{$state_text}</td>";
        echo "<td style='color: {$color};'><strong>" . ($act['is_complete'] ? "YES" : "NO") . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check completion criteria
echo "<h3>5. Completion Criteria</h3>";
$criteria = $completion->get_criteria();
echo "<p><strong>Number of Criteria:</strong> " . count($criteria) . "</p>";
if (!empty($criteria)) {
    echo "<ul>";
    foreach ($criteria as $criterion) {
        echo "<li><strong>Type:</strong> " . get_class($criterion) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: orange;'>No completion criteria configured. Course completion may need manual marking.</p>";
}

// Test the check_and_trigger_completion function
echo "<h3>6. Completion Check Function Test</h3>";
$check_result = theme_remui_kids_check_and_trigger_completion($course, $userid);
echo "<p><strong>check_and_trigger_completion Result:</strong> " . ($check_result ? "TRUE (Course is complete)" : "FALSE (Course is not complete)") . "</p>";

// Re-check completion after trigger
$ccompletion_after = new completion_completion(array('userid' => $userid, 'course' => $courseid));
echo "<p><strong>Course Marked Complete (After Trigger):</strong> " . ($ccompletion_after->is_complete() ? "YES" : "NO") . "</p>";
if ($ccompletion_after->is_complete()) {
    echo "<p><strong>Time Completed (After Trigger):</strong> " . ($ccompletion_after->timecompleted ? date('Y-m-d H:i:s', $ccompletion_after->timecompleted) : "NULL") . "</p>";
}

// Test certificate card generation
echo "<h3>7. Certificate Card Generation Test</h3>";
$certificate_card = theme_remui_kids_get_certificate_completion_card($course, $userid);
if (!empty($certificate_card)) {
    echo "<p style='color: green;'><strong>Certificate Card Generated:</strong> YES</p>";
    echo "<p><strong>Card Length:</strong> " . strlen($certificate_card) . " characters</p>";
    echo "<hr>";
    echo "<h4>Generated Card HTML:</h4>";
    echo "<div style='border: 1px solid #ccc; padding: 10px;'>";
    echo $certificate_card;
    echo "</div>";
} else {
    echo "<p style='color: red;'><strong>Certificate Card Generated:</strong> NO (Empty string returned)</p>";
    echo "<p><strong>Reason:</strong> The function returns empty string when course is not marked complete in database.</p>";
    echo "<p><strong>Solution:</strong> Ensure all activities are completed and completion criteria are met, or manually mark the course as complete.</p>";
}

echo "<hr>";
echo "<p><a href='/iomad/course/view.php?id={$courseid}'>Back to Course</a></p>";

