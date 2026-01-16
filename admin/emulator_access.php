<?php
/**
 * Admin surface to manage emulator access per school and cohort.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

global $DB, $OUTPUT, $PAGE, $SITE;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$companyid = optional_param('companyid', 0, PARAM_INT);
$view = optional_param('view', 'grants', PARAM_ALPHA); // 'access' or 'grants'
$selectedemulator = optional_param('emulator', '', PARAM_ALPHANUMEXT); // Pre-select emulator
$sesskey = sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/admin/emulator_access.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('emulator_access_management', 'theme_remui_kids'));
$PAGE->set_heading($SITE->fullname);

$companies = $DB->get_records('company', null, 'name ASC', 'id, name, shortname');
if ($companyid && !isset($companies[$companyid])) {
    $companyid = 0;
}

$companyoptions = [
    [
        'id' => 0,
        'label' => get_string('emulator_all_schools', 'theme_remui_kids'),
        'selected' => $companyid === 0,
    ],
];

foreach ($companies as $company) {
    $companyoptions[] = [
        'id' => (int)$company->id,
        'label' => format_string($company->name),
        'selected' => ($companyid === (int)$company->id),
    ];
}

$catalog = theme_remui_kids_emulator_catalog();
$emulatorcards = array_map(function(array $definition) {
    return [
        'slug' => $definition['slug'],
        'name' => $definition['name'],
        'icon' => $definition['icon'],
        'summary' => $definition['summary'],
        'launchurl' => $definition['launchurl'] ?? '',
    ];
}, $catalog);

$firstslug = $emulatorcards ? $emulatorcards[array_key_first($emulatorcards)]['slug'] : '';
$matrix = theme_remui_kids_build_emulator_matrix($companyid);
$matrixjson = json_encode($matrix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Get teachers and cohorts for the selected school (if companyid > 0)
$teachers_data = [];
$cohorts_data = [];
if ($companyid > 0) {
    $teachers = theme_remui_kids_get_school_teachers($companyid);
    if (!is_array($teachers)) {
        $teachers = [];
    }
    $teachers_data = array_map(function($teacher) {
        return [
            'id' => (int)$teacher->id,
            'firstname' => $teacher->firstname,
            'lastname' => $teacher->lastname,
            'fullname' => fullname($teacher),
            'email' => $teacher->email,
        ];
    }, $teachers);
    
    $cohorts = theme_remui_kids_get_company_cohorts($companyid);
    if (!is_array($cohorts)) {
        $cohorts = [];
    }
    error_log("admin/emulator_access.php: Loaded " . count($cohorts) . " cohorts for companyid=$companyid");
    $cohorts_data = array_map(function($cohort) {
        return [
            'id' => (int)$cohort->id,
            'name' => format_string($cohort->name),
            'members' => (int)$cohort->members,
        ];
    }, $cohorts);
}
$teachersjson = json_encode($teachers_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$cohortsjson = json_encode($cohorts_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Build school grant matrix for the grants view
$grantmatrix = theme_remui_kids_build_school_grant_matrix();
$grantmatrixjson = json_encode($grantmatrix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$jsstrings = [
    'teachers' => get_string('emulator_role_teachers', 'theme_remui_kids'),
    'students' => get_string('emulator_role_students', 'theme_remui_kids'),
    'enabled' => get_string('emulator_state_enabled', 'theme_remui_kids'),
    'disabled' => get_string('emulator_state_disabled', 'theme_remui_kids'),
    'source_default' => get_string('emulator_source_default', 'theme_remui_kids'),
    'source_global' => get_string('emulator_source_global', 'theme_remui_kids'),
    'source_company' => get_string('emulator_source_company', 'theme_remui_kids'),
    'source_cohort' => get_string('emulator_source_cohort', 'theme_remui_kids'),
    'reset' => get_string('emulator_reset', 'theme_remui_kids'),
    'noCohorts' => get_string('emulator_no_cohorts', 'theme_remui_kids'),
    'instantSave' => get_string('emulator_changes_auto', 'theme_remui_kids'),
    'panelEmpty' => get_string('emulator_panel_empty', 'theme_remui_kids'),
    'updating' => get_string('updating', 'moodle'),
    'loading' => get_string('loading', 'theme_remui_kids'),
];
$stringsjson = json_encode($jsstrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

echo $OUTPUT->header();
?>

<style>
.emulator-access-header h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
}

.admin-main-content {
    padding-top: 90px !important;
    padding-left: 20px !important;
}

.emulator-access-header p {
    margin-top: 8px;
    color: #6b7280;
    font-size: 15px;
}

.emulator-access-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.emulator-access-controls label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.emulator-access-controls select {
    min-width: 260px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    font-size: 15px;
    background: #fff;
}

.emulator-access-body {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
}

.emulator-access-body.single-emulator-view {
    grid-template-columns: 1fr;
}

/* Two Panel Layout for Access Control */
.two-panel-access-layout {
    display: flex;
    gap: 20px;
    margin-top: 20px;
}

.access-panel {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    display: flex;
    flex-direction: column;
}

.access-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.access-panel-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.access-panel-count {
    background: #e3f2fd;
    color: #1976d2;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.access-panel-content {
    flex: 1;
    overflow-y: auto;
    max-height: 600px;
}

.access-panel-search {
    margin-bottom: 15px;
}

.access-panel-search input {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.access-panel-search input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.access-panel-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
}

.access-panel-select-all {
    display: flex;
    align-items: center;
    gap: 8px;
}

.access-panel-select-all input[type='checkbox'] {
    margin: 0;
    transform: scale(1.2);
    cursor: pointer;
}

.access-panel-select-all label {
    font-weight: 600;
    color: #495057;
    cursor: pointer;
    margin: 0;
    font-size: 14px;
}

.access-panel-table {
    width: 100%;
    border-collapse: collapse;
}

.access-panel-table th {
    background: #f8f9fa;
    color: #495057;
    padding: 12px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e0e0e0;
}

.access-panel-table td {
    padding: 12px 8px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.access-panel-table tr:hover {
    background: #f8f9fa;
}

.access-item-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.access-item-name {
    font-weight: 600;
    color: #2c3e50;
}

.access-item-meta {
    font-size: 12px;
    color: #6c757d;
}

.access-item-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
}

.access-item-toggle input[type='checkbox'] {
    margin: 0;
    transform: scale(1.2);
    cursor: pointer;
}

.access-item-status {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.access-item-status.enabled {
    background: #d4edda;
    color: #155724;
}

.access-item-status.disabled {
    background: #f8d7da;
    color: #721c24;
}

.emulator-access-panel.full-width {
    width: 100%;
    max-width: 100%;
}

.emulator-card-list {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    height: fit-content;
    position: sticky;
    top: 20px;
}

.emulator-card-list h3 {
    margin: 0 0 16px 0;
    font-size: 16px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.emulator-chip-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.emulator-chip {
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 12px;
    padding: 14px 16px;
    flex: 1;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.emulator-chip i {
    font-size: 20px;
    color: #4f46e5;
}

.emulator-chip.active {
    border-color: #4f46e5;
    background: rgba(79, 70, 229, 0.08);
    box-shadow: inset 0 0 0 1px rgba(79, 70, 229, 0.3);
}

.emulator-chip span {
    font-weight: 600;
    color: #1f2937;
}

.emulator-launch-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #4f46e5;
    color: white;
    text-decoration: none;
    transition: all 0.2s;
    flex-shrink: 0;
    font-size: 15px;
}

.emulator-launch-btn:hover {
    background: #4338ca;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
    color: white;
    text-decoration: none;
}

.emulator-access-panel {
    background: #fff;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
    min-height: 520px;
}

.emulator-panel-headline {
    display: flex;
    align-items: center;
    gap: 16px;
}

.emulator-panel-headline .icon {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    background: rgba(79, 70, 229, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #4f46e5;
}

.emulator-panel-headline h2 {
    margin: 0;
    font-size: 24px;
    color: #111827;
}

.emulator-panel-headline p {
    margin: 4px 0 0 0;
    color: #6b7280;
    font-size: 14px;
}

.access-section {
    margin-top: 24px;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 20px;
}

.access-section h4 {
    margin: 0 0 14px 0;
    font-size: 16px;
    text-transform: uppercase;
    color: #6b7280;
    letter-spacing: 0.08em;
}

.access-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    margin-bottom: 12px;
}

.access-toggle strong {
    font-size: 15px;
    color: #111827;
}

.access-toggle small {
    color: #6b7280;
}

.access-tag {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 4px 10px;
    border-radius: 999px;
}

.access-tag.enabled {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}

.access-tag.disabled {
    background: rgba(248, 113, 113, 0.15);
    color: #b91c1c;
}

.access-meta {
    color: #6b7280;
    font-size: 13px;
}

.cohort-table {
    width: 100%;
    border-spacing: 0;
    margin-top: 16px;
}

.cohort-table th,
.cohort-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f3f4f6;
    text-align: left;
}

.cohort-table th {
    text-transform: uppercase;
    font-size: 12px;
    color: #9ca3af;
}

.emulator-panel-empty {
    min-height: 400px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #94a3b8;
    font-size: 16px;
}

.access-reset {
    border: none;
    background: transparent;
    color: #4f46e5;
    font-weight: 600;
    cursor: pointer;
    margin-left: auto;
}

.access-reset:disabled {
    color: #a5b4fc;
    cursor: not-allowed;
}

.access-inline-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.access-loading {
    opacity: 0.6;
    pointer-events: none;
}

.emulator-cohort-note {
    margin-top: 6px;
    color: #6b7280;
    font-size: 13px;
}

.emulator-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0;
}

.emulator-tab {
    padding: 12px 24px;
    border-radius: 8px 8px 0 0;
    background: transparent;
    color: #6b7280;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}

.emulator-tab:hover {
    background: rgba(79, 70, 229, 0.05);
    color: #4f46e5;
    text-decoration: none;
}

.emulator-tab.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
    background: rgba(79, 70, 229, 0.08);
}

.emulator-tab i {
    margin-right: 8px;
}

.grant-matrix {
    margin-top: 24px;
}

.grant-emulator-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 20px;
}

.grant-emulator-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
}

.grant-emulator-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(79, 70, 229, 0.15);
    border-color: #e0e7ff;
}

.grant-emulator-card:hover .grant-card-access-btn {
    background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
}

.grant-emulator-card.granted {
    border-color: #d1fae5;
    background: linear-gradient(to bottom right, #ffffff, #f0fdf4);
}

.grant-emulator-card.denied {
    border-color: #fee2e2;
    background: linear-gradient(to bottom right, #ffffff, #fef2f2);
}

.emulator-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.emulator-info-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(79, 70, 229, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4f46e5;
    font-size: 18px;
}

.emulator-info-text strong {
    display: block;
    color: #111827;
    font-size: 15px;
}

.emulator-info-text small {
    color: #6b7280;
    font-size: 13px;
}

.grant-card-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}

.grant-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

.grant-card-info {
    flex: 1;
}

.grant-card-info h3 {
    margin: 0 0 6px 0;
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}

.grant-card-info p {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
    line-height: 1.5;
}

.grant-card-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: #f9fafb;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.grant-toggle-label {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 8px;
}

.grant-toggle-switch {
    position: relative;
    display: inline-block;
    width: 52px;
    height: 28px;
}

.grant-toggle-switch input[type="checkbox"] {
    opacity: 0;
    width: 0;
    height: 0;
}

.grant-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #e5e7eb;
    transition: .4s;
    border-radius: 28px;
}

.grant-toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.grant-toggle-switch input:checked + .grant-toggle-slider {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.grant-toggle-switch input:checked + .grant-toggle-slider:before {
    transform: translateX(24px);
}

.grant-toggle-switch input:disabled + .grant-toggle-slider {
    opacity: 0.5;
    cursor: not-allowed;
}

.grant-card-launch-btn {
    padding: 10px 16px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
    flex: 1;
}

.grant-card-launch-btn:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    color: white;
    text-decoration: none;
}

.grant-card-launch-btn i {
    font-size: 14px;
}

.grant-card-access-btn {
    margin-top: 0;
    padding: 10px 16px;
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.grant-card-access-btn:hover {
    background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
}

.grant-card-access-btn i {
    font-size: 14px;
}

.grant-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #f3f4f6;
    border-radius: 8px;
    color: #374151;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s;
}

.grant-back-btn:hover {
    background: #e5e7eb;
    color: #1f2937;
    text-decoration: none;
    transform: translateX(-2px);
}

.grant-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
}

.grant-toggle input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.grant-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.grant-status.granted {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}

.grant-status.denied {
    background: rgba(248, 113, 113, 0.15);
    color: #b91c1c;
}

.grant-status.default {
    background: rgba(107, 114, 128, 0.15);
    color: #4b5563;
}
</style>

<?php require_once(__DIR__ . '/includes/admin_sidebar.php'); ?>

<main class="maincontent" id="emulatorAccessLayout">
    <div class="admin-main-content">
        <section class="step-card emulator-access-header">
            <h1><?php echo get_string('emulator_access_management', 'theme_remui_kids'); ?></h1>
            <p><?php echo get_string('emulator_access_intro', 'theme_remui_kids'); ?></p>
            
            <!-- Tab Navigation -->
            <div class="emulator-tabs" style="margin-top: 20px;">
                <a href="?view=grants" class="emulator-tab <?php echo $view === 'grants' ? 'active' : ''; ?>">
                    <i class="fa fa-school"></i> School Grants
                </a>
                <?php if ($view === 'access'): ?>
                <a href="?view=access<?php echo $companyid ? '&companyid=' . $companyid : ''; ?>" 
                   class="emulator-tab <?php echo $view === 'access' ? 'active' : ''; ?>">
                    <i class="fa fa-users"></i> Access Control
                    <?php if (!empty($selectedemulator)): ?>
                        <span style="background: #4f46e5; color: white; font-size: 10px; padding: 2px 8px; border-radius: 999px; margin-left: 6px;">
                            <?php 
                            $selected_emu = theme_remui_kids_get_emulator($selectedemulator);
                            echo $selected_emu ? substr($selected_emu['name'], 0, 12) : '';
                            ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($view === 'grants'): ?>
            <!-- SCHOOL GRANTS VIEW -->
            <section class="step-card grant-matrix">
                <div id="grantMatrixApp" 
                     data-sesskey="<?php echo $sesskey; ?>"
                     data-matrix="<?php echo s($grantmatrixjson); ?>">
                    <div class="grant-matrix-intro" style="margin-bottom: 20px; padding: 16px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px;">
                        <strong style="color: #92400e; display: block; margin-bottom: 4px;">
                            <i class="fa fa-info-circle"></i> School Grant Control
                        </strong>
                        <p style="color: #78350f; margin: 0; font-size: 14px;">
                            Use this section to control which emulators are available to each school. 
                            School admins will only see and manage emulators that you grant to their school.
                        </p>
                    </div>

                    <!-- School Selector -->
                    <div class="grant-school-selector" style="margin-bottom: 24px;">
                        <label for="grantSchoolSelect" style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
                            SELECT SCHOOL
                        </label>
                        <select id="grantSchoolSelect" style="min-width: 320px; padding: 12px 16px; border-radius: 10px; border: 1px solid #d1d5db; font-size: 15px; background: #fff; font-weight: 600;">
                            <?php foreach ($grantmatrix['companies'] as $company): ?>
                                <option value="<?php echo (int)$company['id']; ?>">
                                    <?php echo s($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="color: #6b7280; font-size: 13px; margin-top: 6px;">
                            <i class="fa fa-lightbulb"></i> Changes save automatically when you toggle switches
                        </p>
                    </div>

                    <!-- Emulator Cards Grid -->
                    <div class="grant-emulator-grid" id="grantEmulatorGrid">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
            </section>

        <?php else: ?>
            <!-- ACCESS CONTROL VIEW (existing functionality) -->
            <?php if (!empty($selectedemulator)): ?>
                <!-- Context Banner showing which emulator is being configured -->
                <?php 
                $selected_emu_def = theme_remui_kids_get_emulator($selectedemulator);
                if ($selected_emu_def): 
                ?>
                <div style="padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px;">
                    <div style="width: 48px; height: 48px; background: rgba(79, 70, 229, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #4f46e5;">
                        <i class="fa <?php echo $selected_emu_def['icon']; ?>"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">
                            Configuring: <?php echo $selected_emu_def['name']; ?>
                        </h3>
                        <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 13px;">
                            <?php echo $selected_emu_def['summary']; ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <section class="step-card emulator-access-header" style="margin-top: 20px;">
                <div class="emulator-access-controls">
                    <div>
                        <label><?php echo get_string('emulator_changes_auto', 'theme_remui_kids'); ?></label>
                        <p class="access-meta" style="margin:0;"><?php echo get_string('emulator_catalog', 'theme_remui_kids'); ?></p>
                    </div>
                </div>
            </section>

            <section class="step-card">
                <div class="emulator-access-body <?php echo !empty($selectedemulator) ? 'single-emulator-view' : ''; ?>" 
                     id="emulatorAccessApp"
                     data-company="<?php echo $companyid; ?>"
                     data-sesskey="<?php echo $sesskey; ?>"
                     data-initial="<?php echo s($matrixjson); ?>"
                     data-strings="<?php echo s($stringsjson); ?>"
                     data-first-slug="<?php echo s($firstslug); ?>"
                     data-selected-emulator="<?php echo s($selectedemulator); ?>"
                     data-teachers="<?php echo s($teachersjson); ?>"
                     data-cohorts="<?php echo s($cohortsjson); ?>">
                <?php if (empty($selectedemulator)): ?>
                <aside class="emulator-card-list">
                    <h3><?php echo get_string('emulator_catalog', 'theme_remui_kids'); ?></h3>
                    <?php foreach ($emulatorcards as $index => $card): ?>
                        <div class="emulator-chip-wrapper">
                            <button class="emulator-chip <?php echo $index === 0 ? 'active' : ''; ?>"
                                    data-emulator="<?php echo s($card['slug']); ?>">
                                <i class="fa <?php echo s($card['icon']); ?>"></i>
                                <div>
                                    <span><?php echo s($card['name']); ?></span>
                                    <small><?php echo s($card['summary']); ?></small>
                                </div>
                            </button>
                            <?php if (!empty($card['launchurl'])): ?>
                                <a href="<?php echo s($card['launchurl']); ?>" 
                                   class="emulator-launch-btn" 
                                   target="_blank"
                                   title="Launch <?php echo s($card['name']); ?>">
                                    <i class="fa fa-external-link-alt"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </aside>
                <?php endif; ?>

                <section class="emulator-access-panel <?php echo !empty($selectedemulator) ? 'full-width' : ''; ?>">
                    <div class="emulator-panel-empty"><?php echo get_string('emulator_panel_empty', 'theme_remui_kids'); ?></div>
                </section>
            </div>
        </section>
        <?php endif; ?>
    </div>
</main>

<template id="emulatorPanelTemplate">
    <div>
        <div class="emulator-panel-headline">
            <div class="icon"><i class="fa"></i></div>
            <div>
                <h2></h2>
                <p></p>
            </div>
        </div>

        <div class="access-section access-section-cohorts">
            <h4><?php echo get_string('emulator_scope_cohort', 'theme_remui_kids'); ?></h4>
            <div class="cohort-wrapper"></div>
        </div>
    </div>
</template>

<script>
(function() {
    const app = document.getElementById('emulatorAccessApp');
    if (!app) {
        return;
    }

    const panelTemplate = document.getElementById('emulatorPanelTemplate');
    // Get company ID from URL parameter or data attribute
    const urlParams = new URLSearchParams(window.location.search);
    const companyIdFromUrl = urlParams.get('companyid');
    const companyId = companyIdFromUrl ? parseInt(companyIdFromUrl, 10) : parseInt(app.dataset.company, 10) || 0;
    const chips = Array.from(document.querySelectorAll('.emulator-chip'));
    const panel = app.querySelector('.emulator-access-panel');
    const strings = JSON.parse(app.dataset.strings);
    let state = JSON.parse(app.dataset.initial);
    let selectedEmulator = app.dataset.selectedEmulator || '';
    let activeSlug = selectedEmulator || app.dataset.firstSlug;
    let isLoading = false;
    const teachers = JSON.parse(app.dataset.teachers || '[]');
    let cohortsParsed = [];
    try {
        const parsed = JSON.parse(app.dataset.cohorts || '[]');
        cohortsParsed = Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        cohortsParsed = [];
    }
    let cohorts = cohortsParsed; // Changed to let so it can be updated
    console.log('Initial cohorts from dataset:', cohorts, 'Count:', cohorts.length);
    const isSingleEmulatorView = selectedEmulator !== '';

    function setLoading(flag) {
        isLoading = flag;
        if (flag) {
            panel.classList.add('access-loading');
        } else {
            panel.classList.remove('access-loading');
        }
    }

    function renderPanel(slug) {
        if (!slug) {
            panel.innerHTML = `<div class="emulator-panel-empty">${strings.panelEmpty}</div>`;
            return;
        }
        
        activeSlug = slug;
        const emulator = state.emulators.find(e => e.slug === slug);
        if (!emulator) {
            // If emulator not found in state, try to get it from catalog
            const catalogData = <?php echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const emuDef = catalogData[slug];
            if (!emuDef) {
                panel.innerHTML = `<div class="emulator-panel-empty">Emulator "${slug}" not found in catalog</div>`;
                return;
            }
            // Create a minimal emulator object for rendering
            const minimalEmulator = {
                slug: slug,
                name: emuDef.name,
                summary: emuDef.summary,
                icon: emuDef.icon,
                company: {
                    teacher: {value: false, source: 'default', explicit: false},
                    student: {value: false, source: 'default', explicit: false}
                },
                cohorts: (Array.isArray(cohorts) ? cohorts : []).map(c => ({
                    id: c.id,
                    name: c.name,
                    members: c.members,
                    teacher: {value: false, source: 'default', explicit: false},
                    student: {value: false, source: 'default', explicit: false}
                }))
            };
            renderPanelContent(minimalEmulator);
            return;
        }
        
        renderPanelContent(emulator);
    }
    
    function populateTeachersPanel(emulator, companyId, emulatorSlug) {
        const teachersList = document.getElementById('teachersList');
        const teachersCountEl = document.getElementById('teachersCount');
        
        if (!teachersList) return;
        
        // Fetch teachers
        const params = new URLSearchParams();
        params.append('sesskey', app.dataset.sesskey);
        params.append('action', 'get_teachers');
        params.append('emulator', emulatorSlug);
        params.append('companyid', companyId);
        
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.teachers) {
                teachersCountEl.textContent = data.teachers.length;
                renderTeachersList(teachersList, data.teachers, emulatorSlug, companyId);
            } else {
                teachersList.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No teachers found</p>';
                teachersCountEl.textContent = '0';
            }
        })
        .catch(err => {
            console.error('Error loading teachers:', err);
            teachersList.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Error loading teachers</p>';
        });
    }
    
    function renderTeachersList(container, teachers, emulatorSlug, companyId) {
        container.innerHTML = '';
        
        if (teachers.length === 0) {
            container.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No teachers found</p>';
            return;
        }
        
        const table = document.createElement('table');
        table.className = 'access-panel-table';
        table.innerHTML = `
            <thead>
                <tr>
                    <th>SELECT</th>
                    <th>NAME</th>
                    <th>STATUS</th>
                </tr>
            </thead>
            <tbody></tbody>
        `;
        const tbody = table.querySelector('tbody');
        
        teachers.forEach(teacher => {
            const row = document.createElement('tr');
            row.className = 'teacher-row';
            row.dataset.teacherId = teacher.id;
            
            // Checkbox
            const checkboxCell = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'teacher-checkbox';
            checkbox.dataset.teacherId = teacher.id;
            checkbox.checked = teacher.allowed || false;
            
            // Instant toggle on change
            checkbox.addEventListener('change', function() {
                const allowed = this.checked;
                updateTeacherAccess(teacher.id, emulatorSlug, companyId, allowed, row);
            });
            
            checkboxCell.appendChild(checkbox);
            row.appendChild(checkboxCell);
            
            // Name
            const nameCell = document.createElement('td');
            const nameDiv = document.createElement('div');
            nameDiv.className = 'access-item-info';
            const name = document.createElement('div');
            name.className = 'access-item-name';
            name.textContent = teacher.fullname;
            const email = document.createElement('div');
            email.className = 'access-item-meta';
            email.textContent = teacher.email;
            nameDiv.appendChild(name);
            nameDiv.appendChild(email);
            nameCell.appendChild(nameDiv);
            row.appendChild(nameCell);
            
            // Status
            const statusCell = document.createElement('td');
            const statusDiv = document.createElement('div');
            statusDiv.className = 'access-item-status ' + (teacher.allowed ? 'enabled' : 'disabled');
            statusDiv.textContent = teacher.allowed ? 'ENABLED' : 'DISABLED';
            statusDiv.id = 'teacherStatus_' + teacher.id;
            statusCell.appendChild(statusDiv);
            row.appendChild(statusCell);
            
            tbody.appendChild(row);
        });
        
        container.appendChild(table);
        
        // Add select all functionality
        const selectAllCheckbox = document.getElementById('selectAllTeachers');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = container.querySelectorAll('.teacher-checkbox');
                checkboxes.forEach(cb => {
                    if (cb.closest('tr').style.display !== 'none') {
                        cb.checked = this.checked;
                        cb.dispatchEvent(new Event('change'));
                    }
                });
            });
        }
        
        // Add search functionality
        const searchInput = document.getElementById('teachersSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = container.querySelectorAll('.teacher-row');
                let visibleCount = 0;
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const isVisible = text.includes(searchTerm);
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });
            });
        }
    }
    
    function updateTeacherAccess(teacherId, emulatorSlug, companyId, allowed, row) {
        const params = new URLSearchParams();
        params.append('sesskey', app.dataset.sesskey);
        params.append('action', 'update_teacher_access');
        params.append('teacherid', teacherId);
        params.append('emulator', emulatorSlug);
        params.append('companyid', companyId);
        params.append('allowed', allowed ? '1' : '0');
        
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Update status display
                const statusDiv = document.getElementById('teacherStatus_' + teacherId);
                if (statusDiv) {
                    statusDiv.className = 'access-item-status ' + (allowed ? 'enabled' : 'disabled');
                    statusDiv.textContent = allowed ? 'ENABLED' : 'DISABLED';
                }
            } else {
                // Revert checkbox on error
                const checkbox = row.querySelector('.teacher-checkbox');
                if (checkbox) {
                    checkbox.checked = !allowed;
                }
                alert('Failed to update teacher access. Please try again.');
            }
        })
        .catch(err => {
            console.error('Error updating teacher access:', err);
            // Revert checkbox on error
            const checkbox = row.querySelector('.teacher-checkbox');
            if (checkbox) {
                checkbox.checked = !allowed;
            }
            alert('Failed to update teacher access. Please try again.');
        });
    }
    
    function populateCohortsPanel(emulator, cohortsToUse, emulatorSlug, companyId) {
        const cohortsList = document.getElementById('cohortsList');
        const cohortsCountEl = document.getElementById('cohortsCount');
        
        if (!cohortsList) return;
        
        if (!Array.isArray(cohortsToUse) || cohortsToUse.length === 0) {
            cohortsList.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No cohorts found</p>';
            cohortsCountEl.textContent = '0';
            return;
        }
        
        cohortsCountEl.textContent = cohortsToUse.length;
        renderCohortsList(cohortsList, cohortsToUse, emulator, emulatorSlug, companyId);
    }
    
    function renderCohortsList(container, cohorts, emulator, emulatorSlug, companyId) {
        container.innerHTML = '';
        
        if (cohorts.length === 0) {
            container.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No cohorts found</p>';
            return;
        }
        
        const table = document.createElement('table');
        table.className = 'access-panel-table';
        table.innerHTML = `
            <thead>
                <tr>
                    <th>SELECT</th>
                    <th>COHORT</th>
                    <th>STATUS</th>
                </tr>
            </thead>
            <tbody></tbody>
        `;
        const tbody = table.querySelector('tbody');
        
        cohorts.forEach(cohort => {
            // Get access status from emulator
            let cohortData = null;
            if (emulator.cohorts && Array.isArray(emulator.cohorts)) {
                // Try to find by both string and number ID to handle type mismatches
                cohortData = emulator.cohorts.find(c => {
                    const cId = parseInt(c.id) || c.id;
                    const cohortId = parseInt(cohort.id) || cohort.id;
                    return cId === cohortId || String(cId) === String(cohortId);
                });
            }
            
            // Check access - handle both boolean true and truthy values
            let hasAccess = false;
            if (cohortData && cohortData.student) {
                const studentValue = cohortData.student.value;
                hasAccess = studentValue === true || studentValue === 1 || studentValue === '1' || studentValue === 'true';
            }
            
            const row = document.createElement('tr');
            row.className = 'cohort-row';
            row.dataset.cohortId = cohort.id;
            
            // Checkbox
            const checkboxCell = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'cohort-checkbox';
            checkbox.dataset.cohortId = cohort.id;
            checkbox.checked = hasAccess;
            
            // Prevent any default form submission behavior
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            
            // Instant toggle on change
            checkbox.addEventListener('change', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const allowed = this.checked;
                updateCohortAccess(cohort.id, emulatorSlug, companyId, allowed, row);
                return false;
            });
            
            checkboxCell.appendChild(checkbox);
            row.appendChild(checkboxCell);
            
            // Cohort name
            const nameCell = document.createElement('td');
            const nameDiv = document.createElement('div');
            nameDiv.className = 'access-item-info';
            const name = document.createElement('div');
            name.className = 'access-item-name';
            name.textContent = cohort.name;
            const members = document.createElement('div');
            members.className = 'access-item-meta';
            members.textContent = cohort.members + ' <?php echo get_string('emulator_table_members', 'theme_remui_kids'); ?>';
            nameDiv.appendChild(name);
            nameDiv.appendChild(members);
            nameCell.appendChild(nameDiv);
            row.appendChild(nameCell);
            
            // Status
            const statusCell = document.createElement('td');
            const statusDiv = document.createElement('div');
            statusDiv.className = 'access-item-status ' + (hasAccess ? 'enabled' : 'disabled');
            statusDiv.textContent = hasAccess ? 'ENABLED' : 'DISABLED';
            statusDiv.id = 'cohortStatus_' + cohort.id;
            statusCell.appendChild(statusDiv);
            row.appendChild(statusCell);
            
            tbody.appendChild(row);
        });
        
        container.appendChild(table);
        
        // Add select all functionality
        const selectAllCheckbox = document.getElementById('selectAllCohorts');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = container.querySelectorAll('.cohort-checkbox');
                checkboxes.forEach(cb => {
                    if (cb.closest('tr').style.display !== 'none') {
                        cb.checked = this.checked;
                        cb.dispatchEvent(new Event('change'));
                    }
                });
            });
        }
        
        // Add search functionality
        const searchInput = document.getElementById('cohortsSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = container.querySelectorAll('.cohort-row');
                let visibleCount = 0;
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const isVisible = text.includes(searchTerm);
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });
            });
        }
    }
    
    function updateCohortAccess(cohortId, emulatorSlug, companyId, allowed, row) {
        // Update status display immediately (optimistic update)
        const statusDiv = document.getElementById('cohortStatus_' + cohortId);
        if (statusDiv) {
            statusDiv.className = 'access-item-status ' + (allowed ? 'enabled' : 'disabled');
            statusDiv.textContent = allowed ? 'ENABLED' : 'DISABLED';
        }
        
        const params = new URLSearchParams();
        params.append('sesskey', app.dataset.sesskey);
        params.append('action', 'update');
        params.append('emulator', emulatorSlug);
        params.append('companyid', companyId);
        params.append('scope', 'cohort');
        params.append('scopeid', cohortId);
        params.append('field', 'students');
        params.append('value', allowed ? '1' : '0');
        
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    throw new Error('HTTP error! status: ' + res.status + ', body: ' + text);
                });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                // Update local state without full refresh
                const emulator = state.emulators.find(e => e.slug === emulatorSlug);
                if (emulator) {
                    if (!emulator.cohorts) {
                        emulator.cohorts = [];
                    }
                    let cohortData = emulator.cohorts.find(c => c.id === cohortId);
                    if (cohortData) {
                        cohortData.student.value = allowed;
                        cohortData.student.explicit = true;
                        cohortData.student.source = 'cohort';
                    } else {
                        // If cohort data doesn't exist, find it from state cohorts or create minimal entry
                        let cohortInfo = null;
                        if (state && state.cohorts && Array.isArray(state.cohorts)) {
                            cohortInfo = state.cohorts.find(c => c.id === cohortId);
                        }
                        emulator.cohorts.push({
                            id: cohortId,
                            name: cohortInfo ? cohortInfo.name : '',
                            members: cohortInfo ? cohortInfo.members : 0,
                            student: {
                                value: allowed,
                                explicit: true,
                                source: 'cohort'
                            }
                        });
                    }
                }
            } else {
                // Revert checkbox and status on error
                const checkbox = row.querySelector('.cohort-checkbox');
                if (checkbox) {
                    checkbox.checked = !allowed;
                }
                if (statusDiv) {
                    statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                    statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
                }
                const errorMsg = data.message || data.error || 'Unknown error';
                alert('Failed to update cohort access: ' + errorMsg);
            }
        })
        .catch(err => {
            console.error('Error updating cohort access:', err);
            // Revert checkbox and status on error
            const checkbox = row.querySelector('.cohort-checkbox');
            if (checkbox) {
                checkbox.checked = !allowed;
            }
            if (statusDiv) {
                statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
            }
            alert('Failed to update cohort access. Please check console for details.');
        });
    }
    
    function renderPanelContent(emulator) {
        const slug = emulator.slug || activeSlug;

        const fragment = panelTemplate.content.cloneNode(true);
        fragment.querySelector('.fa').classList.add(emulator.icon);
        fragment.querySelector('h2').textContent = emulator.name;
        fragment.querySelector('p').textContent = emulator.summary;
        
        // Clear panel first
        panel.innerHTML = '';

        const cohortWrapper = fragment.querySelector('.cohort-wrapper');
        
        if (isSingleEmulatorView) {
            // Single emulator view: Two-panel layout
            cohortWrapper.innerHTML = '';
            
            const currentCompanyId = companyId;
            
            // Get cohorts from state if available, otherwise use the cohorts variable
            let cohortsToUse = cohorts;
            if (state && state.cohorts && Array.isArray(state.cohorts)) {
                cohortsToUse = state.cohorts;
            } else if (emulator && emulator.cohorts && Array.isArray(emulator.cohorts)) {
                // Extract just the cohort info (id, name, members) from emulator.cohorts
                cohortsToUse = emulator.cohorts.map(c => ({
                    id: c.id,
                    name: c.name,
                    members: c.members
                }));
            }
            
            if (currentCompanyId === 0) {
                cohortWrapper.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No school selected</p>';
            } else {
                // Create two-panel layout: Left = Cohorts, Right = Teachers
                const twoPanelLayout = document.createElement('div');
                twoPanelLayout.className = 'two-panel-access-layout';
                
                // LEFT PANEL: Student Cohorts
                const cohortsPanel = document.createElement('div');
                cohortsPanel.className = 'access-panel';
                
                const cohortsHeader = document.createElement('div');
                cohortsHeader.className = 'access-panel-header';
                const cohortsTitle = document.createElement('h3');
                cohortsTitle.className = 'access-panel-title';
                cohortsTitle.innerHTML = '<i class="fa fa-users"></i> Student Cohorts';
                const cohortsCount = document.createElement('span');
                cohortsCount.className = 'access-panel-count';
                cohortsCount.id = 'cohortsCount';
                cohortsCount.textContent = '0';
                cohortsTitle.appendChild(cohortsCount);
                cohortsHeader.appendChild(cohortsTitle);
                cohortsPanel.appendChild(cohortsHeader);
                
                const cohortsSearch = document.createElement('div');
                cohortsSearch.className = 'access-panel-search';
                const cohortsSearchInput = document.createElement('input');
                cohortsSearchInput.type = 'text';
                cohortsSearchInput.placeholder = 'Search cohorts...';
                cohortsSearchInput.id = 'cohortsSearch';
                cohortsSearch.appendChild(cohortsSearchInput);
                cohortsPanel.appendChild(cohortsSearch);
                
                const cohortsControls = document.createElement('div');
                cohortsControls.className = 'access-panel-controls';
                const cohortsSelectAll = document.createElement('div');
                cohortsSelectAll.className = 'access-panel-select-all';
                const cohortsCheckbox = document.createElement('input');
                cohortsCheckbox.type = 'checkbox';
                cohortsCheckbox.id = 'selectAllCohorts';
                const cohortsLabel = document.createElement('label');
                cohortsLabel.setAttribute('for', 'selectAllCohorts');
                cohortsLabel.textContent = 'Select All';
                cohortsSelectAll.appendChild(cohortsCheckbox);
                cohortsSelectAll.appendChild(cohortsLabel);
                cohortsControls.appendChild(cohortsSelectAll);
                cohortsPanel.appendChild(cohortsControls);
                
                const cohortsContent = document.createElement('div');
                cohortsContent.className = 'access-panel-content';
                cohortsContent.id = 'cohortsList';
                cohortsPanel.appendChild(cohortsContent);
                
                // RIGHT PANEL: Teachers
                const teachersPanel = document.createElement('div');
                teachersPanel.className = 'access-panel';
                
                const teachersHeader = document.createElement('div');
                teachersHeader.className = 'access-panel-header';
                const teachersTitle = document.createElement('h3');
                teachersTitle.className = 'access-panel-title';
                teachersTitle.innerHTML = '<i class="fa fa-user"></i> Teachers';
                const teachersCount = document.createElement('span');
                teachersCount.className = 'access-panel-count';
                teachersCount.id = 'teachersCount';
                teachersCount.textContent = '0';
                teachersTitle.appendChild(teachersCount);
                teachersHeader.appendChild(teachersTitle);
                teachersPanel.appendChild(teachersHeader);
                
                const teachersSearch = document.createElement('div');
                teachersSearch.className = 'access-panel-search';
                const teachersSearchInput = document.createElement('input');
                teachersSearchInput.type = 'text';
                teachersSearchInput.placeholder = 'Search teachers...';
                teachersSearchInput.id = 'teachersSearch';
                teachersSearch.appendChild(teachersSearchInput);
                teachersPanel.appendChild(teachersSearch);
                
                const teachersControls = document.createElement('div');
                teachersControls.className = 'access-panel-controls';
                const teachersSelectAll = document.createElement('div');
                teachersSelectAll.className = 'access-panel-select-all';
                const teachersCheckbox = document.createElement('input');
                teachersCheckbox.type = 'checkbox';
                teachersCheckbox.id = 'selectAllTeachers';
                const teachersLabel = document.createElement('label');
                teachersLabel.setAttribute('for', 'selectAllTeachers');
                teachersLabel.textContent = 'Select All';
                teachersSelectAll.appendChild(teachersCheckbox);
                teachersSelectAll.appendChild(teachersLabel);
                teachersControls.appendChild(teachersSelectAll);
                teachersPanel.appendChild(teachersControls);
                
                const teachersContent = document.createElement('div');
                teachersContent.className = 'access-panel-content';
                teachersContent.id = 'teachersList';
                teachersPanel.appendChild(teachersContent);
                
                twoPanelLayout.appendChild(cohortsPanel);
                twoPanelLayout.appendChild(teachersPanel);
                cohortWrapper.appendChild(twoPanelLayout);
                
                // Populate panels after they're created
                setTimeout(() => {
                    populateCohortsPanel(emulator, cohortsToUse, slug, currentCompanyId);
                    populateTeachersPanel(emulator, currentCompanyId, slug);
                }, 100);
            }
        } else {
            // Normal view: Show both teachers and students in cohorts
            const emulatorCohorts = Array.isArray(emulator.cohorts) ? emulator.cohorts : [];
            if (!emulatorCohorts.length) {
                cohortWrapper.innerHTML = `<p class="emulator-cohort-note">${strings.noCohorts}</p>`;
            } else {
                const table = document.createElement('table');
                table.className = 'cohort-table';
                table.innerHTML = `
                    <thead>
                        <tr>
                            <th><?php echo get_string('emulator_table_cohort', 'theme_remui_kids'); ?></th>
                            <th><?php echo get_string('emulator_role_teachers', 'theme_remui_kids'); ?></th>
                            <th><?php echo get_string('emulator_role_students', 'theme_remui_kids'); ?></th>
                        </tr>
                    </thead>
                `;
                const tbody = document.createElement('tbody');
                emulatorCohorts.forEach(cohort => {
                    const row = document.createElement('tr');
                    const infoCell = document.createElement('td');
                    infoCell.innerHTML = `<strong>${cohort.name}</strong><div class="emulator-cohort-note">${cohort.members} <?php echo get_string('emulator_table_members', 'theme_remui_kids'); ?></div>`;
                    row.appendChild(infoCell);

                    ['teacher', 'student'].forEach(role => {
                        const info = role === 'teacher' ? cohort.teacher : cohort.student;
                        const cell = document.createElement('td');
                        const controls = document.createElement('div');
                        controls.className = 'access-inline-controls';

                        const status = document.createElement('span');
                        status.className = 'access-tag ' + (info.value ? 'enabled' : 'disabled');
                        status.textContent = info.value ? strings.enabled : strings.disabled;
                        controls.appendChild(status);

                        const label = document.createElement('label');
                        const input = document.createElement('input');
                        input.type = 'checkbox';
                        input.dataset.scope = 'cohort';
                        input.dataset.scopeid = cohort.id;
                        input.dataset.field = role === 'teacher' ? 'teachers' : 'students';
                        input.checked = info.value;
                        label.appendChild(input);
                        controls.appendChild(label);

                        const resetBtn = document.createElement('button');
                        resetBtn.className = 'access-reset';
                        resetBtn.type = 'button';
                        resetBtn.dataset.scope = 'cohort';
                        resetBtn.dataset.scopeid = cohort.id;
                        resetBtn.dataset.field = role === 'teacher' ? 'teachers' : 'students';
                        resetBtn.textContent = strings.reset;
                        resetBtn.disabled = !info.explicit;
                        controls.appendChild(resetBtn);

                        const meta = document.createElement('div');
                        meta.className = 'access-meta';
                        meta.textContent = sourceLabel(info.source);
                        controls.appendChild(meta);

                        cell.appendChild(controls);
                        row.appendChild(cell);
                    });
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                cohortWrapper.appendChild(table);
            }
        }

        panel.innerHTML = '';
        panel.appendChild(fragment);
        
        // After fragment is appended, trigger teacher loading if in single-emulator-view
        if (isSingleEmulatorView) {
            // Use setTimeout to ensure DOM is ready
            const currentSlug = emulator.slug || activeSlug;
            setTimeout(() => {
                const teacherListContainer = document.getElementById('adminTeacherList');
                if (teacherListContainer) {
                    const currentCompanyId = companyId;
                    if (currentCompanyId > 0) {
                        // Fetch teachers from AJAX endpoint
                        const params = new URLSearchParams();
                        params.append('sesskey', app.dataset.sesskey);
                        params.append('action', 'get_teachers');
                        params.append('emulator', currentSlug);
                        params.append('companyid', currentCompanyId);

                        console.log('Fetching teachers for company:', currentCompanyId, 'emulator:', currentSlug);
                        console.log('Request params:', params.toString());
                        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: params.toString()
                        })
                        .then(res => {
                            console.log('Response status:', res.status);
                            if (!res.ok) {
                                throw new Error('HTTP error! status: ' + res.status);
                            }
                            return res.json();
                        })
                        .then(data => {
                            console.log('Teachers data received:', data);
                            if (teacherListContainer && teacherListContainer.parentNode) {
                                if (!data.success) {
                                    // Error case
                                    const errorMsg = data.message || 'Unknown error';
                                    const debugInfo = data.debug ? 
                                        ` (companyid: ${data.debug.manager_companyid || 'N/A'}, is_admin: ${data.debug.is_admin || 'N/A'})` : '';
                                    teacherListContainer.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Error: ' + errorMsg + debugInfo + '</p>';
                                    console.error('Error response:', data);
                                } else if (data.teachers && data.teachers.length > 0) {
                                    // Success with teachers
                                    teacherListContainer.innerHTML = '';
                                    data.teachers.forEach(teacher => {
                                        const teacherDiv = createTeacherToggle(teacher, currentSlug, currentCompanyId, teacher.allowed || false);
                                        teacherListContainer.appendChild(teacherDiv);
                                    });
                                    console.log('Rendered', data.teachers.length, 'teachers');
                                } else {
                                    // Success but no teachers
                                    const msg = data.debug ? 
                                        `No teachers found (companyid: ${data.debug.companyid || 'N/A'}, count: ${data.debug.teacher_count || 0})` :
                                        'No teachers found in this school';
                                    teacherListContainer.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">' + msg + '</p>';
                                    console.log('No teachers found:', data);
                                }
                            }
                        })
                        .catch(err => {
                            console.error('Error loading teachers:', err);
                            if (teacherListContainer && teacherListContainer.parentNode) {
                                teacherListContainer.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Error loading teachers: ' + err.message + '. Please check console and refresh the page.</p>';
                            }
                        });
                    } else {
                        if (teacherListContainer && teacherListContainer.parentNode) {
                            teacherListContainer.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No school selected</p>';
                        }
                    }
                }
            }, 100);
        }
    }

    function sourceLabel(source) {
        switch (source) {
            case 'cohort':
                return strings.source_cohort;
            case 'company':
                return strings.source_company;
            case 'global':
                return strings.source_global;
            default:
                return strings.source_default;
        }
    }

    function loadTeacherAccessForAdmin(emulator, companyid) {
        if (companyid === 0) {
            return Promise.resolve({});
        }
        
        const params = new URLSearchParams();
        params.append('sesskey', app.dataset.sesskey);
        params.append('action', 'get_teachers');
        params.append('emulator', emulator);
        params.append('companyid', companyid);

        return fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.teachers) {
                const access = {};
                data.teachers.forEach(teacher => {
                    access[teacher.id] = teacher.allowed;
                });
                return access;
            }
            return {};
        })
        .catch(err => {
            console.error('Error loading teacher access:', err);
            return {};
        });
    }

    function createTeacherToggle(teacher, emulator, companyid, allowed) {
        const div = document.createElement('div');
        div.className = 'access-toggle';
        
        const left = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = teacher.fullname;
        left.appendChild(title);
        const email = document.createElement('div');
        email.className = 'access-meta';
        email.textContent = teacher.email;
        left.appendChild(email);
        
        const controls = document.createElement('div');
        controls.className = 'access-inline-controls';
        
        const status = document.createElement('span');
        status.className = 'access-tag ' + (allowed ? 'enabled' : 'disabled');
        status.textContent = allowed ? strings.enabled : strings.disabled;
        controls.appendChild(status);
        
        const label = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.teacherid = teacher.id;
        input.dataset.emulator = emulator;
        input.dataset.companyid = companyid;
        input.checked = allowed;
        input.addEventListener('change', function() {
            updateTeacherAccessAdmin(teacher.id, emulator, companyid, input.checked);
        });
        label.appendChild(input);
        controls.appendChild(label);
        
        div.appendChild(left);
        div.appendChild(controls);
        return div;
    }

    function updateTeacherAccessAdmin(teacherid, emulator, companyid, allowed) {
        const params = new URLSearchParams();
        params.append('sesskey', app.dataset.sesskey);
        params.append('action', 'update_teacher_access');
        params.append('teacherid', teacherid);
        params.append('emulator', emulator);
        params.append('companyid', companyid);
        params.append('allowed', allowed ? '1' : '0');

        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating teacher access: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error updating teacher access:', err);
            alert('Failed to update teacher access. Please try again.');
        });
    }

    function refreshMatrix(newstate) {
        state = newstate;
        // Update cohorts from state if available
        if (newstate && newstate.cohorts && Array.isArray(newstate.cohorts)) {
            cohorts = newstate.cohorts;
        }
        
        // If in single-emulator-view, update the cohorts panel directly
        if (isSingleEmulatorView) {
            const emulator = state.emulators.find(e => e.slug === activeSlug);
            if (emulator) {
                const cohortsList = document.getElementById('cohortsList');
                if (cohortsList) {
                    const currentCompanyId = companyId;
                    let cohortsToUse = cohorts;
                    if (state && state.cohorts && Array.isArray(state.cohorts)) {
                        cohortsToUse = state.cohorts;
                    } else if (emulator && emulator.cohorts && Array.isArray(emulator.cohorts)) {
                        cohortsToUse = emulator.cohorts.map(c => ({
                            id: c.id,
                            name: c.name,
                            members: c.members
                        }));
                    }
                    populateCohortsPanel(emulator, cohortsToUse, activeSlug, currentCompanyId);
                }
            }
        }
        
        renderPanel(activeSlug);
    }

    function fetchMatrix(newCompanyId) {
        setLoading(true);
        const params = new URLSearchParams({
            action: 'matrix',
            companyid: newCompanyId,
            sesskey: app.dataset.sesskey
        });

        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                refreshMatrix(data.data);
                // Update cohorts if provided (only if we get a valid array)
                if (data.cohorts && Array.isArray(data.cohorts)) {
                    cohorts = data.cohorts;
                    // Re-render panel if we're in single-emulator-view to update cohorts display
                    if (isSingleEmulatorView && activeSlug) {
                        renderPanel(activeSlug);
                    }
                } else {
                    // Don't overwrite existing cohorts if response doesn't have them
                    // Only re-render if we're in single-emulator-view
                    if (isSingleEmulatorView && activeSlug) {
                        renderPanel(activeSlug);
                    }
                }
            }
        })
        .finally(() => setLoading(false));
    }

    function persistChange(scope, scopeid, field, value) {
        const params = new URLSearchParams({
            action: 'update',
            companyid: companyId,
            sesskey: app.dataset.sesskey,
            emulator: activeSlug,
            scope,
            scopeid,
            field,
            value: value ? 1 : 0
        });

        return fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        }).then(res => res.json()).then(data => {
            if (data.success) {
                refreshMatrix(data.data);
            }
        });
    }

    function persistReset(scope, scopeid, field) {
        const params = new URLSearchParams({
            action: 'reset',
            companyid: companyId,
            sesskey: app.dataset.sesskey,
            emulator: activeSlug,
            scope,
            scopeid
        });
        if (field) {
            params.append('field', field);
        }

        return fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        }).then(res => res.json()).then(data => {
            if (data.success) {
                refreshMatrix(data.data);
            }
        });
    }

    panel.addEventListener('change', (event) => {
        const input = event.target;
        if (input.tagName !== 'INPUT') {
            return;
        }
        const scope = input.dataset.scope;
        const scopeid = input.dataset.scopeid;
        const field = input.dataset.field;
        persistChange(scope, scopeid, field, input.checked);
    });

    panel.addEventListener('click', (event) => {
        const reset = event.target.closest('.access-reset');
        if (reset) {
            persistReset(reset.dataset.scope, reset.dataset.scopeid, reset.dataset.field || null);
            return;
        }
    });


    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            renderPanel(chip.dataset.emulator);
        });
        
        // Auto-select the chip if it matches the selected emulator
        if (selectedEmulator && chip.dataset.emulator === selectedEmulator) {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
        }
    });

    // Always render panel first with initial data
    renderPanel(activeSlug);
    
    // Then fetch matrix (and cohorts) if in single-emulator-view and company is selected
    // This ensures we show initial data immediately, then update if needed
    if (isSingleEmulatorView && companyId > 0) {
        fetchMatrix(companyId);
    }
})();
</script>

<?php if ($view === 'grants'): ?>
<script>
(function() {
    'use strict';

    const app = document.getElementById('grantMatrixApp');
    if (!app) return;

    const matrix = JSON.parse(app.dataset.matrix);
    const catalog = <?php echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const sesskey = app.dataset.sesskey;
    const grid = document.getElementById('grantEmulatorGrid');
    const schoolSelect = document.getElementById('grantSchoolSelect');
    const wwwroot = M.cfg.wwwroot;

    console.log('Grant Matrix:', matrix);

    let currentSchoolId = schoolSelect.value ? parseInt(schoolSelect.value) : (matrix.companies[0] ? matrix.companies[0].id : 0);

    // Emulator icon gradients
    const iconGradients = {
        'fa-code': 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'fa-puzzle-piece': 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'fa-code-branch': 'linear-gradient(135deg, #fc5c7d 0%, #6a82fb 100%)',
        'fa-image': 'linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%)',
        'fa-database': 'linear-gradient(135deg, #00b09b 0%, #96c93d 100%)',
        'fa-html5': 'linear-gradient(135deg, #ff9966 0%, #ff5e62 100%)',
        'fa-clone': 'linear-gradient(135deg, #f857a6 0%, #ff5858 100%)',
        'fa-microchip': 'linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%)'
    };

    function getGradient(iconClass) {
        for (let key in iconGradients) {
            if (iconClass.includes(key)) {
                return iconGradients[key];
            }
        }
        return 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
    }

    function renderCards() {
        grid.innerHTML = '';

        if (!matrix.emulators || matrix.emulators.length === 0) {
            grid.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 40px;">No emulators available.</p>';
            return;
        }

        matrix.emulators.forEach(function(emulator) {
            // Find grant status for current school
            const schoolGrant = emulator.schools.find(s => s.companyid === currentSchoolId);
            if (!schoolGrant) return;

            const card = document.createElement('div');
            card.className = 'grant-emulator-card ' + (schoolGrant.granted ? 'granted' : 'denied');

            const gradient = getGradient(emulator.icon);

            card.innerHTML = `
                <div class="grant-card-header">
                    <div class="grant-card-icon" style="background: ${gradient};">
                        <i class="fa ${emulator.icon}"></i>
                    </div>
                    <div class="grant-card-info">
                        <h3>${emulator.name}</h3>
                        <p>${emulator.summary}</p>
                    </div>
                </div>

                <div class="grant-card-toggle">
                    <div class="grant-toggle-label">
                        <i class="fa fa-${schoolGrant.granted ? 'check-circle' : 'times-circle'}" 
                           style="color: ${schoolGrant.granted ? '#10b981' : '#ef4444'};"></i>
                        <span>${schoolGrant.granted ? 'Granted' : 'Denied'}</span>
                        ${!schoolGrant.explicit ? '<span style="font-size: 11px; color: #9ca3af;">(Default)</span>' : ''}
                    </div>
                    <label class="grant-toggle-switch">
                        <input type="checkbox" 
                               ${schoolGrant.granted ? 'checked' : ''}
                               data-emulator="${emulator.slug}"
                               data-companyid="${currentSchoolId}">
                        <span class="grant-toggle-slider"></span>
                    </label>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 16px;">
                    ${catalog[emulator.slug] && catalog[emulator.slug].launchurl ? `
                        <a href="${catalog[emulator.slug].launchurl}" 
                           target="_blank"
                           class="grant-card-launch-btn"
                           title="Launch ${emulator.name}">
                            <i class="fa fa-external-link-alt"></i> Launch
                        </a>
                    ` : ''}
                    ${schoolGrant.granted ? `
                <button class="grant-card-access-btn" 
                        data-emulator="${emulator.slug}" 
                        data-company="${currentSchoolId}"
                                title="Configure access control for this emulator"
                                style="${catalog[emulator.slug] && catalog[emulator.slug].launchurl ? 'flex: 1;' : 'width: 100%;'}">
                    <i class="fa fa-cog"></i> Access Control
                </button>
                    ` : ''}
                </div>
            `;

            // Add change event listener for toggle
            const checkbox = card.querySelector('input[type="checkbox"]');
            if (checkbox) {
            checkbox.addEventListener('change', function(e) {
                e.stopPropagation(); // Prevent card click
                updateGrant(emulator.slug, currentSchoolId, checkbox.checked);
            });
            }

            // Add click event listener for access control button (only if it exists)
            const accessBtn = card.querySelector('.grant-card-access-btn');
            if (accessBtn) {
            accessBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent card click
                navigateToAccessControl(emulator.slug, currentSchoolId);
            });
            }

            grid.appendChild(card);
        });
    }

    function navigateToAccessControl(emulatorSlug, companyId) {
        // Build URL with emulator and company parameters
        const url = new URL(window.location.href);
        url.searchParams.set('view', 'access');
        url.searchParams.set('companyid', companyId);
        url.searchParams.set('emulator', emulatorSlug);
        
        // Navigate to access control view
        window.location.href = url.toString();
    }

    function updateGrant(emulator, companyid, granted) {
        const params = new URLSearchParams({
            action: 'grant',
            emulator: emulator,
            companyid: companyid,
            granted: granted ? '1' : '0',
            sesskey: sesskey
        });

        // Show loading state
        grid.style.opacity = '0.6';
        grid.style.pointerEvents = 'none';

        fetch(wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                // Update matrix with new data
                Object.assign(matrix, data.data);
                renderCards();
            } else {
                alert('Error updating grant: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Grant update error:', err);
            alert('Failed to update grant. Please try again.');
        })
        .finally(() => {
            grid.style.opacity = '1';
            grid.style.pointerEvents = 'auto';
        });
    }

    // School selection change
    schoolSelect.addEventListener('change', function() {
        currentSchoolId = parseInt(schoolSelect.value);
        renderCards();
    });

    // Initial render
    renderCards();
})();
</script>
<?php endif; ?>

<?php
echo $OUTPUT->footer();

