<?php
/**
 * Certificate Check Page for Students
 * 
 * This page shows students detailed information about why their certificate
 * is available for download or why it's not available yet.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/certificate_completion.php');

// Require login - allows all logged-in users including students
require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Get course ID from parameter
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

// Get course context
$context = context_course::instance($courseid);

// Check if user is enrolled in the course first (students are enrolled)
// This is the primary check - enrolled students can access the page
if (!is_enrolled($context, $USER->id)) {
    // If not enrolled, check if they have teacher or admin capabilities
    $isteacher = has_capability('moodle/course:update', $context);
    $isadmin = has_capability('moodle/site:config', context_system::instance());
    
    if (!$isteacher && !$isadmin) {
        // User is not enrolled and not a teacher/admin - deny access
        throw new moodle_exception('notenrolled', 'core', '', null, 
            'You must be enrolled in this course to view certificate status.');
    }
}

// For enrolled users (students), we don't need to check course:view capability
// Enrollment already grants them access to view course content

// Set up the page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/check_certificate.php', array('courseid' => $courseid));
$PAGE->set_title('Certificate Status Check - ' . format_string($course->fullname));
$PAGE->set_heading('Certificate Status Check');
$PAGE->set_pagelayout('base');

// Start output
echo $OUTPUT->header();

// Initialize check results
$checks = array();
$all_passed = true;

// Check 1: Course completion enabled
$completion = new completion_info($course);
$completion_enabled = $completion->is_enabled();
$checks[] = array(
    'name' => 'Course Completion Enabled',
    'status' => $completion_enabled ? 'pass' : 'fail',
    'message' => $completion_enabled ? 'Course completion tracking is enabled' : 'Course completion tracking is NOT enabled',
    'required' => true
);
if (!$completion_enabled) {
    $all_passed = false;
}

// Check 2: Course has activities
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$has_any_activities = false;
$has_completable_activities = false;
$total_activities = 0;
$total_completable_activities = 0;
$completed_completable_activities = 0;
$section_details = array();

foreach ($sections as $section) {
    if ($section->section == 0) {
        continue; // Skip section 0
    }
    
    $cms = $modinfo->get_cms();
    $section_cms = array_filter($cms, function($cm) use ($section) {
        return $cm->sectionnum == $section->section;
    });
    
    $section_activity_count = 0;
    $section_completable_count = 0;
    $section_completed_count = 0;
    $section_activities = array();
    
    foreach ($section_cms as $cm) {
        if ($cm->modname === 'label') {
            continue;
        }
        
        $section_activity_count++;
        $total_activities++;
        $has_any_activities = true;
        
        $cmcompletion = $completion->is_enabled($cm);
        $requires_completion = ($cmcompletion != COMPLETION_TRACKING_NONE);
        
        if ($requires_completion) {
            $has_completable_activities = true;
            $section_completable_count++;
            $total_completable_activities++;
            
            $completiondata = $completion->get_data($cm, false, $USER->id);
            $is_completed = ($completiondata->completionstate == COMPLETION_COMPLETE || 
                           $completiondata->completionstate == COMPLETION_COMPLETE_PASS);
            
            if ($is_completed) {
                $section_completed_count++;
                $completed_completable_activities++;
            }
            
            $section_activities[] = array(
                'name' => $cm->name,
                'type' => $cm->modname,
                'requires_completion' => true,
                'completed' => $is_completed,
                'completion_state' => $completiondata->completionstate
            );
        } else {
            $section_activities[] = array(
                'name' => $cm->name,
                'type' => $cm->modname,
                'requires_completion' => false,
                'completed' => null,
                'completion_state' => null
            );
        }
    }
    
    if ($section_activity_count > 0 || $section->section > 0) {
        $section_details[] = array(
            'section_number' => $section->section,
            'section_name' => $section->name ?: "Section " . $section->section,
            'total_activities' => $section_activity_count,
            'completable_activities' => $section_completable_count,
            'completed_activities' => $section_completed_count,
            'is_complete' => ($section_completable_count == 0 || $section_completed_count == $section_completable_count),
            'activities' => $section_activities
        );
    }
}

$checks[] = array(
    'name' => 'Course Has Activities',
    'status' => $has_any_activities ? 'pass' : 'fail',
    'message' => $has_any_activities ? "Course has {$total_activities} activities" : 'Course has ZERO activities',
    'required' => true,
    'details' => $has_any_activities ? "Total activities: {$total_activities}" : null
);
if (!$has_any_activities) {
    $all_passed = false;
}

$checks[] = array(
    'name' => 'Course Has Completable Activities',
    'status' => $has_completable_activities ? 'pass' : 'fail',
    'message' => $has_completable_activities ? "Course has {$total_completable_activities} activities requiring completion" : 'Course has ZERO activities requiring completion',
    'required' => true,
    'details' => $has_completable_activities ? "Completable activities: {$total_completable_activities}" : null
);
if (!$has_completable_activities) {
    $all_passed = false;
}

// Check 3: All activities completed
$all_activities_completed = ($has_completable_activities && 
                             $total_completable_activities > 0 && 
                             $completed_completable_activities == $total_completable_activities);

$checks[] = array(
    'name' => 'All Activities Completed',
    'status' => $all_activities_completed ? 'pass' : 'fail',
    'message' => $all_activities_completed ? 
        "All {$total_completable_activities} activities are completed" : 
        "Only {$completed_completable_activities} of {$total_completable_activities} activities completed",
    'required' => true,
    'details' => $all_activities_completed ? 
        "Progress: {$completed_completable_activities}/{$total_completable_activities} (100%)" : 
        "Progress: {$completed_completable_activities}/{$total_completable_activities} (" . 
        round(($completed_completable_activities / $total_completable_activities) * 100, 1) . "%)"
);
if (!$all_activities_completed) {
    $all_passed = false;
}

// Check 4: Course marked as complete in database
$course_completion_record = $DB->get_record('course_completions', array(
    'userid' => $USER->id,
    'course' => $courseid
));
$course_marked_complete = ($course_completion_record && $course_completion_record->timecompleted !== null);

$checks[] = array(
    'name' => 'Course Marked Complete in Database',
    'status' => $course_marked_complete ? 'pass' : 'warning',
    'message' => $course_marked_complete ? 
        'Course is marked as complete in the system' : 
        'Course is NOT yet marked as complete in the system',
    'required' => false,
    'details' => $course_marked_complete ? 
        'Completion date: ' . userdate($course_completion_record->timecompleted) : 
        'This will be updated automatically when all activities are completed'
);

// Check 5: Certificate assigned (CustomCert)
$customcert_cmid = null;
$customcert_issue = null;
if ($DB->get_manager()->table_exists('customcert')) {
    $cms = $modinfo->get_cms();
    foreach ($cms as $cm) {
        if ($cm->modname === 'customcert' && $cm->uservisible) {
            $customcert_cmid = $cm->id;
            $customcert_instance = $DB->get_record('customcert', array('id' => $cm->instance));
            if ($customcert_instance) {
                $customcert_issue = $DB->get_record('customcert_issues', array(
                    'customcertid' => $customcert_instance->id,
                    'userid' => $USER->id
                ));
            }
            break;
        }
    }
}

$checks[] = array(
    'name' => 'CustomCert Certificate Available',
    'status' => ($customcert_cmid && $customcert_issue) ? 'pass' : 'info',
    'message' => ($customcert_cmid && $customcert_issue) ? 
        'CustomCert certificate is available' : 
        'No CustomCert certificate found in this course',
    'required' => false,
    'details' => ($customcert_cmid && $customcert_issue) ? 
        'Certificate ID: ' . $customcert_issue->id : 
        'This course does not use CustomCert certificates'
);

// Check 6: Certificate assigned (Certificate Approval System)
$approval_certificate = null;
if ($DB->get_manager()->table_exists('mod_certificate_approval_instances')) {
    $approval_certificate = $DB->get_record('mod_certificate_approval_instances', array(
        'user_id' => $USER->id,
        'course_id' => $courseid,
        'status' => 'PUBLISHED'
    ));
}

$checks[] = array(
    'name' => 'Certificate Approval System Certificate',
    'status' => $approval_certificate ? 'pass' : 'info',
    'message' => $approval_certificate ? 
        'Certificate is assigned and published' : 
        'No certificate assigned from Certificate Approval System',
    'required' => false,
    'details' => $approval_certificate ? 
        'Certificate ID: ' . $approval_certificate->id . ' | Status: ' . $approval_certificate->status : 
        'A certificate must be assigned to this course/school for you to receive it'
);

// Final check: Will certificate card show?
$certificate_card_will_show = ($all_activities_completed && 
                               ($customcert_cmid && $customcert_issue) || $approval_certificate);

$checks[] = array(
    'name' => 'Certificate Card Will Display',
    'status' => $certificate_card_will_show ? 'pass' : 'fail',
    'message' => $certificate_card_will_show ? 
        'Certificate card will appear on course page' : 
        'Certificate card will NOT appear on course page',
    'required' => true,
    'details' => $certificate_card_will_show ? 
        'You can download your certificate from the course page' : 
        'Complete all required activities to see your certificate'
);

// Display results
?>
<style>
.cert-check-container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
}

.cert-check-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
}

.cert-check-header h1 {
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 700;
}

.cert-check-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 16px;
}

.cert-check-summary {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    border-left: 4px solid #667eea;
}

.cert-check-summary h2 {
    margin: 0 0 15px 0;
    font-size: 20px;
    color: #333;
}

.cert-check-summary .summary-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    font-size: 16px;
}

.cert-check-summary .summary-item:last-child {
    margin-bottom: 0;
}

.status-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
}

.status-icon.pass {
    background: #10b981;
    color: white;
}

.status-icon.fail {
    background: #ef4444;
    color: white;
}

.status-icon.warning {
    background: #f59e0b;
    color: white;
}

.status-icon.info {
    background: #3b82f6;
    color: white;
}

.check-item {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-left: 4px solid #e5e7eb;
}

.check-item.pass {
    border-left-color: #10b981;
}

.check-item.fail {
    border-left-color: #ef4444;
}

.check-item.warning {
    border-left-color: #f59e0b;
}

.check-item.info {
    border-left-color: #3b82f6;
}

.check-item-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 10px;
}

.check-item-name {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    flex: 1;
}

.check-item-message {
    color: #6b7280;
    margin-bottom: 5px;
}

.check-item-details {
    color: #9ca3af;
    font-size: 14px;
    margin-top: 5px;
}

.section-details {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.section-card {
    background: #f9fafb;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 3px solid #667eea;
}

.section-card h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    color: #111827;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    margin-bottom: 5px;
    background: white;
    border-radius: 4px;
}

.activity-item.completed {
    border-left: 3px solid #10b981;
}

.activity-item.incomplete {
    border-left: 3px solid #ef4444;
}

.activity-item.no-completion {
    border-left: 3px solid #9ca3af;
}

.back-button {
    display: inline-block;
    margin-bottom: 20px;
    padding: 10px 20px;
    background: #667eea;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: background 0.3s;
}

.back-button:hover {
    background: #5568d3;
    color: white;
    text-decoration: none;
}
</style>

<div class="cert-check-container">
    <a href="<?php echo new moodle_url('/course/view.php', array('id' => $courseid)); ?>" class="back-button">
        ← Back to Course
    </a>

    <div class="cert-check-header">
        <h1>Certificate Status Check</h1>
        <p><?php echo format_string($course->fullname); ?></p>
    </div>

    <div class="cert-check-summary">
        <h2>Summary</h2>
        <div class="summary-item">
            <span class="status-icon <?php echo $all_passed ? 'pass' : 'fail'; ?>">
                <?php echo $all_passed ? '✓' : '✗'; ?>
            </span>
            <strong>Overall Status:</strong> 
            <?php echo $all_passed ? 'Certificate is available for download' : 'Certificate is NOT yet available'; ?>
        </div>
        <div class="summary-item">
            <span class="status-icon <?php echo $has_any_activities ? 'pass' : 'fail'; ?>">
                <?php echo $has_any_activities ? '✓' : '✗'; ?>
            </span>
            <strong>Activities:</strong> <?php echo $total_activities; ?> total, <?php echo $total_completable_activities; ?> require completion
        </div>
        <div class="summary-item">
            <span class="status-icon <?php echo $all_activities_completed ? 'pass' : 'fail'; ?>">
                <?php echo $all_activities_completed ? '✓' : '✗'; ?>
            </span>
            <strong>Completion:</strong> <?php echo $completed_completable_activities; ?> of <?php echo $total_completable_activities; ?> completed
        </div>
        <div class="summary-item">
            <span class="status-icon <?php echo ($customcert_cmid && $customcert_issue) || $approval_certificate ? 'pass' : 'info'; ?>">
                <?php echo ($customcert_cmid && $customcert_issue) || $approval_certificate ? '✓' : 'i'; ?>
            </span>
            <strong>Certificate:</strong> <?php echo ($customcert_cmid && $customcert_issue) || $approval_certificate ? 'Assigned' : 'Not assigned'; ?>
        </div>
    </div>

    <h2 style="margin-bottom: 20px; color: #111827;">Detailed Checks</h2>

    <?php foreach ($checks as $check): ?>
        <div class="check-item <?php echo $check['status']; ?>">
            <div class="check-item-header">
                <span class="status-icon <?php echo $check['status']; ?>">
                    <?php 
                    if ($check['status'] == 'pass') echo '✓';
                    elseif ($check['status'] == 'fail') echo '✗';
                    elseif ($check['status'] == 'warning') echo '⚠';
                    else echo 'i';
                    ?>
                </span>
                <div class="check-item-name"><?php echo $check['name']; ?></div>
            </div>
            <div class="check-item-message"><?php echo $check['message']; ?></div>
            <?php if (!empty($check['details'])): ?>
                <div class="check-item-details"><?php echo $check['details']; ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($section_details)): ?>
        <div class="section-details">
            <h2 style="margin-bottom: 20px; color: #111827;">Section Details</h2>
            <?php foreach ($section_details as $section): ?>
                <div class="section-card">
                    <h3>
                        <?php echo htmlspecialchars($section['section_name']); ?>
                        <span style="font-size: 14px; color: #6b7280; font-weight: normal;">
                            (<?php echo $section['completed_activities']; ?>/<?php echo $section['completable_activities']; ?> completed)
                        </span>
                    </h3>
                    <?php if ($section['total_activities'] == 0): ?>
                        <div style="color: #ef4444; font-weight: 600;">⚠ This section has 0 activities</div>
                    <?php elseif ($section['completable_activities'] == 0): ?>
                        <div style="color: #9ca3af;">No activities require completion in this section</div>
                    <?php else: ?>
                        <?php foreach ($section['activities'] as $activity): ?>
                            <div class="activity-item <?php 
                                echo $activity['requires_completion'] ? 
                                    ($activity['completed'] ? 'completed' : 'incomplete') : 
                                    'no-completion'; 
                            ?>">
                                <span style="font-weight: <?php echo $activity['requires_completion'] ? '600' : '400'; ?>;">
                                    <?php echo htmlspecialchars($activity['name']); ?>
                                </span>
                                <span style="color: #9ca3af; font-size: 12px;">
                                    (<?php echo $activity['type']; ?>)
                                </span>
                                <?php if ($activity['requires_completion']): ?>
                                    <span style="margin-left: auto; font-weight: 600; color: <?php echo $activity['completed'] ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $activity['completed'] ? '✓ Completed' : '✗ Incomplete'; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="margin-left: auto; color: #9ca3af; font-size: 12px;">
                                        No completion required
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="margin-top: 30px; padding: 20px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #3b82f6;">
        <h3 style="margin: 0 0 10px 0; color: #1e40af;">What This Means</h3>
        <p style="margin: 0; color: #1e3a8a;">
            <?php if ($certificate_card_will_show): ?>
                <strong>Great news!</strong> Your certificate is ready. You can download it from the course page. 
                All required activities have been completed and your certificate has been assigned.
            <?php else: ?>
                <strong>Certificate not yet available.</strong> To receive your certificate, you need to:
                <ul style="margin: 10px 0 0 20px; color: #1e3a8a;">
                    <?php if (!$has_any_activities): ?>
                        <li>Course must have activities (currently: 0 activities)</li>
                    <?php endif; ?>
                    <?php if (!$has_completable_activities): ?>
                        <li>Course must have activities that require completion (currently: 0 completable activities)</li>
                    <?php endif; ?>
                    <?php if (!$all_activities_completed): ?>
                        <li>Complete all <?php echo $total_completable_activities; ?> required activities (<?php echo $completed_completable_activities; ?> completed so far)</li>
                    <?php endif; ?>
                    <?php if (!$approval_certificate && !($customcert_cmid && $customcert_issue)): ?>
                        <li>A certificate must be assigned to this course by your teacher or administrator</li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php
echo $OUTPUT->footer();

