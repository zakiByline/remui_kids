<?php
// Simple diagnostic page to debug Study Partner CTA/floating button visibility.

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/study_partner_diagnostic.php'));
$PAGE->set_pagelayout('report');
$PAGE->set_title('Study Partner Diagnostics');
$PAGE->set_heading('Study Partner Diagnostics');

$setting = get_config('local_studypartner', 'showstudentnav');
$settingstatus = ($setting === null) ? 'Not set (null)' : (($setting) ? 'Enabled (1)' : 'Disabled (0)');

$plugininstalled = file_exists($CFG->dirroot . '/local/studypartner/version.php') ? 'Yes' : 'No';

    $ctaVisible = ($setting === null) ? 'Yes (default true)' : (($setting) ? 'Yes' : 'No');
    
    // Check capability
    $context = context_system::instance();
    $hasCapability = has_capability('local/studypartner:view', $context);
    $capabilityStatus = $hasCapability ? 'YES ✓' : 'NO ✗';
$studypartnerurl = (new moodle_url('/local/studypartner/'))->out(false);

echo $OUTPUT->header();
echo html_writer::tag('h2', 'Study Partner Diagnostics');
echo html_writer::start_tag('ul');
echo html_writer::tag('li', 'Plugin directory present: ' . s($plugininstalled));
echo html_writer::tag('li', 'Config showstudentnav value: ' . s($settingstatus));

// Detailed capability check
$hasCapability = has_capability('local/studypartner:view', $context);
$capabilityStatus = $hasCapability ? 'YES ✓' : 'NO ✗ (This is why you get the error!)';
echo html_writer::tag('li', 'Current user capability local/studypartner:view: ' . $capabilityStatus);

// Check user roles
global $USER;
$roles = get_user_roles($context, $USER->id);
$roleNames = array();
foreach ($roles as $role) {
    $roleNames[] = $role->shortname;
}
echo html_writer::tag('li', 'User roles in system context: ' . (empty($roleNames) ? 'None (This is the problem!)' : implode(', ', $roleNames)));

// Check if capability exists in any role
$allroles = role_get_names($context);
$rolesWithCapability = array();
foreach ($allroles as $role) {
    $rolecontext = $context;
    if (has_capability('local/studypartner:view', $rolecontext, $USER->id, false)) {
        $rolesWithCapability[] = $role->shortname;
    }
}
echo html_writer::tag('li', 'Roles with local/studypartner:view capability: ' . (empty($rolesWithCapability) ? 'None - You need to enable this in Define Roles!' : implode(', ', $rolesWithCapability)));

echo html_writer::tag('li', 'CTA supposed to render: ' . s($ctaVisible) . ($hasCapability ? ' (and user has capability)' : ' (but user lacks capability - button will be hidden)'));
echo html_writer::tag('li', 'Expected floating button link: ' . html_writer::link($studypartnerurl, $studypartnerurl));
echo html_writer::end_tag('ul');

// Instructions
if (!$hasCapability) {
    echo html_writer::tag('div', html_writer::tag('h3', 'How to Fix:') . 
        html_writer::tag('ol', 
            html_writer::tag('li', 'Go to Site administration → Users → Permissions → Define roles') .
            html_writer::tag('li', 'Find your role (e.g., "Student") and click Edit') .
            html_writer::tag('li', 'Search for "local/studypartner:view" and set it to "Allow"') .
            html_writer::tag('li', 'Save changes') .
            html_writer::tag('li', 'Go to Site administration → Users → Permissions → Assign system roles') .
            html_writer::tag('li', 'Make sure your role is assigned at the System level') .
            html_writer::tag('li', 'Purge all caches (Site administration → Development → Purge all caches') .
            html_writer::tag('li', 'Refresh this page to verify')
        ), array('style' => 'background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border: 2px solid #ffc107;'));
}

echo html_writer::tag('p', 'If the floating button is still missing, ensure CSS is not overridden and inspect the DOM for .g4g7-study-partner-fab.');

echo $OUTPUT->footer();

