 <?php
/**
 * School Manager Emulator Access Control
 * Allows company managers to control emulator access for their school's cohorts
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');

global $DB, $OUTPUT, $PAGE, $USER, $CFG;

require_login();

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    // Try alternative query to find company info if first query fails
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.* 
             FROM {company} c 
             JOIN {company_users} cu ON c.id = cu.companyid 
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

$companyid = (int)$company_info->id;
$sesskey = sesskey();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/emulator_access.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Emulator Access Control - ' . format_string($company_info->name));


// Get full catalog
$catalog = theme_remui_kids_emulator_catalog();

// Filter to only show emulators granted to this school
$granted_emulators = theme_remui_kids_get_granted_emulators_for_school($companyid);
error_log("school_manager/emulator_access.php: companyid=$companyid, granted_emulators=" . implode(', ', $granted_emulators));
$filtered_catalog = array_filter($catalog, function($slug) use ($granted_emulators) {
    return in_array($slug, $granted_emulators);
}, ARRAY_FILTER_USE_KEY);
error_log("school_manager/emulator_access.php: filtered_catalog has " . count($filtered_catalog) . " emulators");

$emulatorcards = array_map(function(array $definition) {
    return [
        'slug' => $definition['slug'],
        'name' => $definition['name'],
        'icon' => $definition['icon'],
        'summary' => $definition['summary'],
        'launchurl' => $definition['launchurl'] ?? '',
    ];
}, $filtered_catalog);

// Check if emulator parameter is passed from grant page
$requested_emulator = optional_param('emulator', '', PARAM_ALPHANUMEXT);
$firstslug = '';

// If emulator parameter is provided and it's in the granted emulators list, use it
if ($requested_emulator && in_array($requested_emulator, $granted_emulators)) {
    $firstslug = $requested_emulator;
} else if ($emulatorcards) {
    // Otherwise, use the first emulator from the catalog
    $firstslug = $emulatorcards[array_key_first($emulatorcards)]['slug'];
}

try {
$matrixdata = theme_remui_kids_build_emulator_matrix($companyid);
    error_log("school_manager/emulator_access.php: build_emulator_matrix returned " . count($matrixdata['emulators'] ?? []) . " emulators");
} catch (Exception $e) {
    error_log("school_manager/emulator_access.php: build_emulator_matrix failed: " . $e->getMessage());
    $matrixdata = ['emulators' => [], 'cohorts' => []];
}

// Transform matrix to be keyed by slug for JavaScript
$matrix = [];
foreach ($matrixdata['emulators'] as $emulator) {
    $slug = $emulator['slug'];
    $matrix[$slug] = [
        'catalog' => [
            'name' => $emulator['name'],
            'summary' => $emulator['summary'],
            'icon' => $emulator['icon'],
        ],
        'company_teachers' => [
            'enabled' => (bool)($emulator['company']['teacher']['value'] ?? false),
            'source' => $emulator['company']['teacher']['source'] ?? 'default',
        ],
        'company_students' => [
            'enabled' => (bool)($emulator['company']['student']['value'] ?? false),
            'source' => $emulator['company']['student']['source'] ?? 'default',
        ],
        'cohorts' => array_map(function($cohort) {
            return [
                'id' => $cohort['id'],
                'name' => $cohort['name'],
                'member_count' => $cohort['members'],
                'teachers' => [
                    'enabled' => (bool)($cohort['teacher']['value'] ?? false),
                    'source' => $cohort['teacher']['source'] ?? 'default',
                ],
                'students' => [
                    'enabled' => (bool)($cohort['student']['value'] ?? false),
                    'source' => $cohort['student']['source'] ?? 'default',
                ],
            ];
        }, $emulator['cohorts']),
    ];
}

$matrixjson = json_encode($matrix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

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
    'schoolWideAccess' => 'SCHOOL-WIDE ACCESS',
    'cohortOverrides' => 'COHORT OVERRIDES',
    'cohort' => 'COHORT',
];
$stringsjson = json_encode($jsstrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info ? $company_info->name : 'School Manager',
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'emulator_access_active' => true,
    'certificates_active' => false,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'config' => ['wwwroot' => $CFG->wwwroot]
];

echo $OUTPUT->header();

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    error_log("Error loading sidebar: " . $e->getMessage());
}
?>

<style>
/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Reset and base styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f8f9fa;
    overflow-x: hidden;
}

/* Remove top margin from main content */
#topofscroll.main-inner {
    margin-top: 0 !important;
}

.main-inner {
    margin-top: 0 !important;
}

/* Make the page content full width minus sidebar */
body.path-theme-remui_kids-school_manager #page-content,
body.path-theme-remui_kids-school_manager .container-fluid,
body.path-theme-remui_kids-school_manager #region-main {
    max-width: 100% !important;
    width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

body.path-theme-remui_kids-school_manager .container {
    max-width: 100% !important;
    width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* Remove container constraints */
#page-wrapper .container {
    max-width: none !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

/* Prevent horizontal overflow */
body.path-theme-remui_kids-school_manager {
    overflow-x: hidden !important;
}

#page,
#page-wrapper {
    overflow-x: hidden !important;
    max-width: 100vw !important;
}

.emulator-access-header {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    margin-top: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 0.75rem;
}

.emulator-access-header h1 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
}

/* IMPORTANT: Position sidebar BELOW the navbar (top: 55px) */
.school-manager-sidebar {
    position: fixed !important;
    top: 55px !important; /* Below navbar */
    left: 0 !important;
    height: calc(100vh - 55px) !important; /* Full height minus navbar */
    z-index: 1000 !important; /* Below navbar (navbar uses 1100) */
    visibility: visible !important;
    display: flex !important;
}

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px; /* Below navbar */
    left: 280px; /* Sidebar width */
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background-color: #f8f9fa;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 30px 20px 20px 20px;
    box-sizing: border-box;
}

/* Adjust for sidebar - assuming sidebar is 280px */
@media (min-width: 768px) {
    .school-manager-main-content {
        left: 280px !important;
        width: calc(100vw - 280px) !important;
    }
}

/* Mobile: remove left margin when sidebar is hidden/collapsed */
@media (max-width: 767px) {
    .school-manager-main-content {
        left: 0 !important;
        width: 100vw !important;
    }
}

.emulator-access-header p {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
    max-width: 600px;
}


.emulator-access-body {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100%;
    box-sizing: border-box;
}

.maincontent {
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    padding-top: 20px;
    max-width: 100%;
    box-sizing: border-box;
}

@media (max-width: 1200px) {
    .emulator-access-body {
        grid-template-columns: 1fr;
    }
}

.emulator-card-list {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    height: fit-content;
    position: sticky;
    top: 20px;
    max-width: 280px;
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
    border-radius: 10px;
    padding: 10px 12px;
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.emulator-chip i {
    font-size: 18px;
    color: #4f46e5;
    flex-shrink: 0;
}

.emulator-chip.active {
    border-color: #4f46e5;
    background: rgba(79, 70, 229, 0.08);
    box-shadow: inset 0 0 0 1px rgba(79, 70, 229, 0.3);
}

.emulator-chip span {
    font-weight: 600;
    color: #1f2937;
    font-size: 14px;
}

.emulator-chip small {
    display: block;
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
    line-height: 1.3;
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
    padding: 32px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
    min-height: 520px;
    max-width: 100%;
    margin-left: 0 !important;
    margin-right: 0 !important;
    box-sizing: border-box;
}

.emulator-access-header,
.emulator-access-controls {
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100%;
    box-sizing: border-box;
}

/* Ensure all elements stay within bounds */
.school-manager-main-content * {
    max-width: 100%;
    box-sizing: border-box;
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
    flex-direction: column;
    color: #9ca3af;
}

.emulator-panel-empty i {
    font-size: 48px;
    margin-bottom: 12px;
}

.access-reset {
    color: #4f46e5;
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
    background: none;
    border: none;
    padding: 0;
}

.access-reset:hover {
    color: #4338ca;
}
</style>

<div class="school-manager-main-content">
    <?php if (empty($emulatorcards)): ?>
        <!-- No emulators granted notice -->
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 8px 0; color: #92400e;">
                <i class="fa fa-exclamation-triangle"></i> No Emulators Available
            </h3>
            <p style="margin: 0; color: #78350f;">
                Your school has not been granted access to any emulators yet. 
                Please contact your system administrator to request access to specific emulators.
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($emulatorcards)): ?>
    <!-- Main Content -->
    <div class="maincontent">
        <!-- Header -->
        <div class="emulator-access-header">
            <h1><?php echo get_string('emulator_access_management', 'theme_remui_kids'); ?></h1>
            <p>Choose an emulator, then decide which cohorts can launch it in your school.</p>
        </div>

        <!-- Body -->
        <div class="emulator-access-body">
            <aside class="emulator-card-list">
                <h3><?php echo get_string('emulator_catalog', 'theme_remui_kids'); ?></h3>
                <?php foreach ($emulatorcards as $index => $card): ?>
                    <div class="emulator-chip-wrapper">
                        <button class="emulator-chip <?php echo ($card['slug'] === $firstslug) ? 'active' : ''; ?>"
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

            <section class="emulator-access-panel" id="access-panel">
                <div class="emulator-panel-empty">
                    <i class="fa fa-circle-notch fa-spin"></i>
                    <p><?php echo get_string('loading', 'theme_remui_kids'); ?></p>
                </div>
            </section>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($emulatorcards)): ?>
<script>
(function() {
    'use strict';

    const matrix = <?php echo $matrixjson; ?>;
    const strings = <?php echo $stringsjson; ?>;
    const panel = document.getElementById('access-panel');
    const chips = document.querySelectorAll('.emulator-chip');
    const companyId = <?php echo $companyid; ?>;
    const sesskey = '<?php echo $sesskey; ?>';
    const wwwroot = M.cfg.wwwroot;
    let activeSlug = '<?php echo $firstslug; ?>';

    // Check URL parameters for emulator
    const urlParams = new URLSearchParams(window.location.search);
    const urlEmulator = urlParams.get('emulator');
    
    // If URL has emulator parameter and it exists in matrix, use it
    if (urlEmulator && urlEmulator !== activeSlug && matrix[urlEmulator]) {
        activeSlug = urlEmulator;
        
        // Update the active chip visually
        chips.forEach(chip => {
            if (chip.dataset.emulator === urlEmulator) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        });
    }

    function renderPanel(slug) {
        const data = matrix[slug];
        if (!data) {
            panel.innerHTML = '<div class="emulator-panel-empty"><i class="fa fa-exclamation-circle"></i><p>No data available for this emulator</p></div>';
            return;
        }
        activeSlug = slug;
        const cat = data.catalog || {};

        let html = '<div class="emulator-panel-headline">';
        html += '<div class="icon"><i class="fa ' + (cat.icon || 'fa-cube') + '"></i></div>';
        html += '<div><h2>' + (cat.name || 'Unknown') + '</h2><p>' + (cat.summary || '') + '</p></div>';
        html += '</div>';

        // Two-panel layout: Student Cohorts (left) and Teachers (right)
        html += '<div class="two-panel-access-layout">';
        
        // LEFT PANEL: Student Cohorts
        html += '<div class="access-panel">';
        html += '<div class="access-panel-header">';
        html += '<h3 class="access-panel-title"><i class="fa fa-users"></i> Student Cohorts <span class="access-panel-count" id="cohortsCount">0</span></h3>';
        html += '</div>';
        html += '<div class="access-panel-search">';
        html += '<input type="text" id="cohortsSearch" placeholder="Search cohorts...">';
        html += '</div>';
        html += '<div class="access-panel-controls">';
        html += '<div class="access-panel-select-all">';
        html += '<input type="checkbox" id="selectAllCohorts">';
        html += '<label for="selectAllCohorts">Select All</label>';
        html += '</div>';
        html += '</div>';
        html += '<div class="access-panel-content" id="cohortsList"></div>';
        html += '</div>';

        // RIGHT PANEL: Teachers
        html += '<div class="access-panel">';
        html += '<div class="access-panel-header">';
        html += '<h3 class="access-panel-title"><i class="fa fa-user"></i> Teachers <span class="access-panel-count" id="teachersCount">0</span></h3>';
        html += '</div>';
        html += '<div class="access-panel-search">';
        html += '<input type="text" id="teachersSearch" placeholder="Search teachers...">';
        html += '</div>';
        html += '<div class="access-panel-controls">';
        html += '<div class="access-panel-select-all">';
        html += '<input type="checkbox" id="selectAllTeachers">';
        html += '<label for="selectAllTeachers">Select All</label>';
        html += '</div>';
        html += '</div>';
        html += '<div class="access-panel-content" id="teachersList"></div>';
        html += '</div>';

        html += '</div>'; // End two-panel-access-layout

        panel.innerHTML = html;
        
        // Render cohorts and teachers
        renderCohortsList(data.cohorts || [], slug);
        loadTeachersForEmulator(activeSlug);
    }

    function renderRole(scope, scopeid, field, data) {
        // Explicitly check enabled state - handle boolean, string, number, or undefined
        const isEnabled = data && (data.enabled === true || data.enabled === 1 || data.enabled === '1' || data.enabled === 'true');
        const checked = isEnabled ? 'checked' : '';
        const tag = isEnabled ? 'enabled' : 'disabled';
        const source = (data && data.source) || 'default';

        let html = '<div class="access-toggle">';
        html += '<div>';
        html += '<strong>' + (field === 'teachers' ? strings.teachers : strings.students) + '</strong> ';
        html += '<small class="access-meta">' + (strings['source_' + source] || source) + '</small>';
        html += '</div>';
        html += '<div style="display: flex; align-items: center; gap: 12px;">';
        html += '<span class="access-tag ' + tag + '">' + (isEnabled ? strings.enabled : strings.disabled) + '</span>';
        html += '<input type="checkbox" ' + checked;
        html += ' data-scope="' + scope + '" data-scopeid="' + String(scopeid) + '" data-field="' + field + '"';
        html += ' class="emulator-access-checkbox">';
        html += '</div>';
        html += '</div>';

        if (source !== 'default') {
            html += '<div style="text-align: right; margin-top: -8px; margin-bottom: 12px;">';
            html += '<button class="access-reset" data-scope="' + scope + '" data-scopeid="' + scopeid + '" data-field="' + field + '">';
            html += strings.reset + ' to inherit ' + (scope === 'cohort' ? (strings.source_company || 'School override') : (strings.source_global || 'Platform default'));
            html += '</button></div>';
        }

        return html;
    }

    function refreshMatrix(newMatrixData) {
        // Transform the matrix data to match the expected structure (keyed by slug)
        // newMatrixData has structure: { emulators: [...], cohorts: [...] }
        if (newMatrixData && newMatrixData.emulators) {
            const transformed = {};
            newMatrixData.emulators.forEach(function(emulator) {
                const slug = emulator.slug;
                
                // Ensure cohorts array exists and has the correct structure
                let cohortsData = [];
                if (emulator.cohorts && Array.isArray(emulator.cohorts)) {
                    cohortsData = emulator.cohorts.map(function(cohort) {
                        // Check if cohort has teacher/student data or if it needs to be extracted
                        // The PHP returns: {teacher: {value: bool, source: string}, student: {value: bool, source: string}}
                        let teacherData = null;
                        let studentData = null;
                        
                        if (cohort.teacher && typeof cohort.teacher === 'object') {
                            teacherData = cohort.teacher;
                        } else if (cohort.teachers && typeof cohort.teachers === 'object') {
                            teacherData = {value: cohort.teachers.enabled, source: cohort.teachers.source};
                        } else {
                            teacherData = {value: false, source: 'default'};
                        }
                        
                        if (cohort.student && typeof cohort.student === 'object') {
                            studentData = cohort.student;
                        } else if (cohort.students && typeof cohort.students === 'object') {
                            studentData = {value: cohort.students.enabled, source: cohort.students.source};
                        } else {
                            studentData = {value: false, source: 'default'};
                        }
                        
                        return {
                            id: parseInt(cohort.id) || cohort.id,
                            name: cohort.name,
                            member_count: cohort.members || cohort.member_count || 0,
                            teachers: {
                                enabled: Boolean(teacherData.value),
                                source: teacherData.source || 'default',
                            },
                            students: {
                                enabled: Boolean(studentData.value),
                                source: studentData.source || 'default',
                            },
                        };
                    });
                }
                
                transformed[slug] = {
                    catalog: {
                        name: emulator.name,
                        summary: emulator.summary,
                        icon: emulator.icon,
                    },
                    company_teachers: {
                        enabled: Boolean(emulator.company && emulator.company.teacher && emulator.company.teacher.value),
                        source: (emulator.company && emulator.company.teacher && emulator.company.teacher.source) || 'default',
                    },
                    company_students: {
                        enabled: Boolean(emulator.company && emulator.company.student && emulator.company.student.value),
                        source: (emulator.company && emulator.company.student && emulator.company.student.source) || 'default',
                    },
                    cohorts: cohortsData,
                };
            });
            // Update the matrix with transformed data
            Object.keys(transformed).forEach(function(slug) {
                matrix[slug] = transformed[slug];
            });
        }
        renderPanel(activeSlug);
    }

    function renderCohortsList(cohorts, emulatorSlug) {
        const cohortsList = document.getElementById('cohortsList');
        const cohortsCountEl = document.getElementById('cohortsCount');
        
        if (!cohortsList) return;
        
        if (!Array.isArray(cohorts) || cohorts.length === 0) {
            cohortsList.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No cohorts found</p>';
            cohortsCountEl.textContent = '0';
            return;
        }
        
        cohortsCountEl.textContent = cohorts.length;
        
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
            const hasAccess = cohort.students && (cohort.students.enabled === true || cohort.students.enabled === 1 || cohort.students.enabled === '1' || cohort.students.enabled === 'true');
            
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
            members.textContent = (cohort.member_count || cohort.members || 0) + ' Members';
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
        
        cohortsList.appendChild(table);
        
        // Add select all functionality
        const selectAllCheckbox = document.getElementById('selectAllCohorts');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = cohortsList.querySelectorAll('.cohort-checkbox');
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
                const rows = cohortsList.querySelectorAll('.cohort-row');
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
        // Optimistic update - update UI immediately
        const statusDiv = document.getElementById('cohortStatus_' + cohortId);
        if (statusDiv) {
            statusDiv.className = 'access-item-status ' + (allowed ? 'enabled' : 'disabled');
            statusDiv.textContent = allowed ? 'ENABLED' : 'DISABLED';
        }
        
        // Update local matrix data
        if (matrix[emulatorSlug] && matrix[emulatorSlug].cohorts) {
            const cohort = matrix[emulatorSlug].cohorts.find(c => c.id == cohortId);
            if (cohort) {
                cohort.students.enabled = allowed;
            }
        }
        
        const params = new URLSearchParams();
        params.append('sesskey', sesskey);
        params.append('action', 'update');
        params.append('emulator', emulatorSlug);
        params.append('companyid', companyId);
        params.append('scope', 'cohort');
        params.append('scopeid', cohortId);
        params.append('field', 'students');
        params.append('value', allowed ? '1' : '0');
        
        fetch(wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                // Revert on error
                const checkbox = row.querySelector('.cohort-checkbox');
                if (checkbox) {
                    checkbox.checked = !allowed;
                }
                if (statusDiv) {
                    statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                    statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
                }
                // Revert matrix
                if (matrix[emulatorSlug] && matrix[emulatorSlug].cohorts) {
                    const cohort = matrix[emulatorSlug].cohorts.find(c => c.id == cohortId);
                    if (cohort) {
                        cohort.students.enabled = !allowed;
                    }
                }
            }
            // Don't call refreshMatrix - we already updated the UI optimistically
        })
        .catch(err => {
            console.error('Error updating cohort access:', err);
            // Revert on error
            const checkbox = row.querySelector('.cohort-checkbox');
            if (checkbox) {
                checkbox.checked = !allowed;
            }
            if (statusDiv) {
                statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
            }
            // Revert matrix
            if (matrix[emulatorSlug] && matrix[emulatorSlug].cohorts) {
                const cohort = matrix[emulatorSlug].cohorts.find(c => c.id == cohortId);
                if (cohort) {
                    cohort.students.enabled = !allowed;
                }
            }
        });
    }

    function loadTeachersForEmulator(emulator) {
        const teachersList = document.getElementById('teachersList');
        const teachersCountEl = document.getElementById('teachersCount');
        
        if (!teachersList) return;
        
        const params = new URLSearchParams();
        params.append('sesskey', sesskey);
        params.append('action', 'get_teachers');
        params.append('emulator', emulator);
        params.append('companyid', companyId);

        fetch(wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.teachers) {
                teachersCountEl.textContent = data.teachers.length;
                renderTeachersList(teachersList, data.teachers, emulator, companyId);
            } else {
                teachersList.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No teachers found</p>';
                teachersCountEl.textContent = '0';
            }
        })
        .catch(err => {
            console.error('Error loading teachers:', err);
            teachersList.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Error loading teachers</p>';
            teachersCountEl.textContent = '0';
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
        // Optimistic update - update UI immediately
        const statusDiv = document.getElementById('teacherStatus_' + teacherId);
        if (statusDiv) {
            statusDiv.className = 'access-item-status ' + (allowed ? 'enabled' : 'disabled');
            statusDiv.textContent = allowed ? 'ENABLED' : 'DISABLED';
        }
        
        const params = new URLSearchParams();
        params.append('sesskey', sesskey);
        params.append('action', 'update_teacher_access');
        params.append('teacherid', teacherId);
        params.append('emulator', emulatorSlug);
        params.append('companyid', companyId);
        params.append('allowed', allowed ? '1' : '0');

        fetch(wwwroot + '/theme/remui_kids/ajax/teacher_emulator_access.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                // Revert on error
                const checkbox = row.querySelector('.teacher-checkbox');
                if (checkbox) {
                    checkbox.checked = !allowed;
                }
                if (statusDiv) {
                    statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                    statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
                }
                alert('Failed to update teacher access. Please try again.');
            }
            // Don't refresh - we already updated the UI optimistically
        })
        .catch(err => {
            console.error('Error updating teacher access:', err);
            // Revert on error
            const checkbox = row.querySelector('.teacher-checkbox');
            if (checkbox) {
                checkbox.checked = !allowed;
            }
            if (statusDiv) {
                statusDiv.className = 'access-item-status ' + (!allowed ? 'enabled' : 'disabled');
                statusDiv.textContent = !allowed ? 'ENABLED' : 'DISABLED';
            }
            alert('Failed to update teacher access. Please try again.');
        });
    }

    function persistChange(scope, scopeid, field, enabled) {
        const params = new URLSearchParams();
        params.append('sesskey', sesskey);
        params.append('action', 'update');
        params.append('companyid', companyId);
        params.append('emulator', activeSlug);
        params.append('scope', scope);
        params.append('scopeid', scopeid);
        params.append('field', field);
        params.append('enabled', enabled ? '1' : '0');

        return fetch(wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
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
        const params = new URLSearchParams();
        params.append('sesskey', sesskey);
        params.append('action', 'reset');
        params.append('companyid', companyId);
        params.append('emulator', activeSlug);
        params.append('scope', scope);
        params.append('scopeid', scopeid);
        if (field) {
            params.append('field', field);
        }

        return fetch(wwwroot + '/theme/remui_kids/ajax/emulator_access.php', {
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
        if (input.tagName !== 'INPUT' || input.type !== 'checkbox') {
            return;
        }
        
        const scope = input.dataset.scope;
        const scopeid = input.dataset.scopeid;
        const field = input.dataset.field;
        
        if (!scope || !scopeid || !field) {
            return;
        }
        
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
    });

    renderPanel(activeSlug);
})();
</script>
<?php endif; ?>

<?php
echo $OUTPUT->footer();

