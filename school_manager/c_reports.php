<?php
/**
 * C Reports - Course Reports Main Page
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

$ajaxrequest = optional_param('ajax', 0, PARAM_BOOL);

// Ensure the current user has the school manager role.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company information for the current manager.
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
         FROM {company} c
         JOIN {company_users} cu ON c.id = cu.companyid
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

// Page configuration
$context = context_system::instance();
$PAGE->set_context($context);
$page_url = new moodle_url('/theme/remui_kids/school_manager/c_reports.php');
$PAGE->set_url($page_url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('C Reports');
$PAGE->set_heading('C Reports');

$allowed_tabs = ['courses', 'enrollment', 'completion', 'engagement', 'overview', 'distribution'];
$initial_tab = optional_param('tab', 'courses', PARAM_ALPHANUMEXT);

// Redirect old 'activitycompletion' tab to 'enrollment' tab
if ($initial_tab === 'activitycompletion') {
    redirect(new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'enrollment']), 
            'Activity Completion Report tab has been removed. Redirecting to Course Enrollment Report.', 
            null, \core\output\notification::NOTIFY_INFO);
}

if (!in_array($initial_tab, $allowed_tabs, true)) {
    $initial_tab = 'courses';
}

$tab_file_map = [
    'courses' => 'c_reports_courses.php',
    'enrollment' => 'c_reports_enrollment.php',
    'completion' => 'c_reports_completion.php',
    'engagement' => 'c_reports_engagement.php',
    'overview' => 'c_reports_overview.php',
    'distribution' => 'c_reports_distribution.php'
];

$tab_urls = [];

foreach ($tab_file_map as $tab_key => $file_name) {
    $file_path = __DIR__ . '/' . $file_name;
    if (file_exists($file_path)) {
        $tab_url = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => $tab_key]);
        $tab_urls[$tab_key] = $tab_url;
    } else {
        $tab_urls[$tab_key] = null;
    }
}

if ($ajaxrequest) {
    if (!array_key_exists($initial_tab, $tab_file_map)) {
        throw new moodle_exception('invalidparameter', 'error');
    }
    $targetfile = __DIR__ . '/' . $tab_file_map[$initial_tab];
    if (!file_exists($targetfile)) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'Missing tab file: ' . $tab_file_map[$initial_tab]);
    }
    try {
        require($targetfile);
    } catch (Throwable $ajaxerror) {
        error_log('c_reports AJAX error (' . $initial_tab . '): ' . $ajaxerror->getMessage());
        http_response_code(500);
        echo '<div class="tab-error-message"><h4>Error loading tab</h4><p>' .
            format_string($ajaxerror->getMessage()) . '</p></div>';
    }
    exit;
}

$tab_url_map = [];
foreach ($tab_urls as $key => $url_obj) {
    $tab_url_map[$key] = $url_obj instanceof moodle_url ? $url_obj->out(false) : null;
}

$tab_labels = [
    'courses' => 'Courses Report',
    'enrollment' => 'Course Enrollment Report',
    'completion' => 'Course Completion Reports',
    'engagement' => 'Course Engagement',
    'overview' => 'Course Overview Reports',
    'distribution' => 'Course Distribution Reports'
];

$summary_url_string = $tab_urls['courses'] instanceof moodle_url
    ? $tab_urls['courses']->out(false)
    : (new moodle_url('/theme/remui_kids/school_manager/c_reports.php'))->out(false);

$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info ? $company_info->name : 'School',
    'company_logo_url' => '', // Removed logo URL
    'has_logo' => false, // Set has_logo to false
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'c_reports_active' => true,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'parent_management_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'bulk_download_active' => false,
    'bulk_profile_upload_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'user_reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

echo $OUTPUT->header();

try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

html, body {
    overflow: hidden;
    margin: 0;
    padding: 0;
    height: 100vh;
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
}

/* NOTE: Sidebar styling is now handled by the template - do not override background/colors here */
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

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    overflow-x: hidden;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
    padding: 20px;
    box-sizing: border-box;
}

.main-content {
    max-width: 1800px;
    margin: 0 auto;
    padding: 35px 20px 0 20px;
    overflow-x: hidden;
}

.page-header {
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
    padding: 1.75rem 2rem;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid;
    border-image: linear-gradient(180deg, #60a5fa, #34d399) 1;
    margin-bottom: 1.5rem;
    margin-top: 0;
    position: relative;
}

.page-header-text {
    flex: 1;
    min-width: 260px;
}

.page-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.page-subtitle {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}

.header-download-section {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.9);
    padding: 10px 18px;
    border-radius: 12px;
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.12);
}

.download-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: #475569;
    white-space: nowrap;
}

.download-select {
    border: 1px solid #cbd5f5;
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 0.9rem;
    font-weight: 500;
    color: #1f2937;
    min-width: 150px;
}

.download-btn {
    border: none;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    font-weight: 600;
    padding: 9px 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.download-btn:hover {
    background: #1d4ed8;
}

.tabs-container {
    margin-bottom: 30px;
    width: 100%;
}

.tabs-nav {
    display: flex;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 25px;
    flex-wrap: nowrap;
    overflow-x: visible;
    white-space: nowrap;
    width: 100%;
    justify-content: space-around;
    align-items: flex-end;
    gap: 4px;
}

.tab-button {
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    bottom: -2px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
    flex: 1 1 auto;
    min-width: 0;
    max-width: 100%;
    text-align: center;
}

.tab-button i {
    font-size: 0.9rem;
    flex-shrink: 0;
}

.tab-button:hover {
    color: #3b82f6;
    background: #f9fafb;
    border-radius: 8px 8px 0 0;
}

.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 600;
    background: transparent;
}

.tab-button.active:hover {
    background: #f0f9ff;
    border-radius: 8px 8px 0 0;
}

.tab-pane {
    display: none;
    width: 100%;
    min-height: 0;
    max-width: 100%;
    box-sizing: border-box;
}

.tab-pane.active {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.tab-pane[hidden] {
    display: none !important;
}

/* Ensure active panes are always visible */
.tab-pane.active:not([hidden]) {
    display: block !important;
}

/* Prevent style accumulation and ensure consistent sizing */
.tab-pane > style {
    display: none;
}

.tab-pane style {
    display: none;
}

/* Ensure summary cards maintain consistent size */
.tab-pane .enrollment-summary-grid,
.tab-pane .summary-card {
    box-sizing: border-box;
    width: 100%;
}

#c-reports-tab-content {
    position: relative;
}

.tab-loading-state {
    margin-top: 30px;
    display: none;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: #475569;
}

.tab-loading-state.visible {
    display: inline-flex;
}

.tab-loading-state .spinner {
    width: 26px;
    height: 26px;
    border-radius: 999px;
    border: 3px solid #e2e8f0;
    border-top-color: #6366f1;
    animation: tab-spin 0.85s linear infinite;
}

@keyframes tab-spin {
    to {
        transform: rotate(360deg);
    }
}

.tab-placeholder,
.tab-error-message {
    margin-top: 30px;
    text-align: center;
    padding: 60px 30px;
    border-radius: 16px;
    border: 1px dashed #cbd5f5;
    background: #f8fafc;
    color: #64748b;
}

.tab-placeholder i,
.tab-error-message i {
    font-size: 2.6rem;
    margin-bottom: 12px;
    color: #cbd5f5;
}

.tab-placeholder h4,
.tab-error-message h4 {
    margin: 10px 0 6px;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
}

.tab-placeholder p,
.tab-error-message p {
    margin: 0;
    font-size: 0.95rem;
    color: #6b7280;
}

@media (max-width: 768px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
        overflow-x: hidden;
    }

    .main-content {
        padding: 35px 16px 0 16px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }

    .tabs-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .tabs-container::-webkit-scrollbar {
        display: none;
    }
    
    .tabs-container {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    .tabs-nav {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 5px;
    }
    
    .tabs-nav::-webkit-scrollbar {
        height: 4px;
    }
    
    .tabs-nav::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 2px;
    }
    
    .tabs-nav::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 2px;
    }
    
    .tabs-nav::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    .tab-button {
        padding: 8px 14px;
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    .tab-button.active {
        background: #eff6ff;
        border-color: #3b82f6;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-text">
                <h1 class="page-title">C Reports</h1>
                <p class="page-subtitle">Comprehensive course reports and analytics for <?php echo htmlspecialchars($company_info ? $company_info->name : 'your school'); ?>.</p>
            </div>
            <div class="header-download-section">
                <span class="download-label">Download reports</span>
                <select class="download-select" id="cReportsDownloadFormat">
                    <option value="excel">Excel (.csv)</option>
                    <option value="pdf">PDF</option>
                </select>
                <button class="download-btn" type="button" onclick="downloadCReportsTab()">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
        </div>

        <div class="tabs-container">
        <div class="tabs-nav">
            <button
                class="tab-button<?php echo $initial_tab === 'courses' ? ' active' : ''; ?>"
                type="button"
                data-tab="courses"
                data-url="<?php echo $tab_urls['courses'] ? $tab_urls['courses']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'courses' ? 'true' : 'false'; ?>">
                <i class="fa fa-book"></i>
                Courses Report
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'enrollment' ? ' active' : ''; ?>"
                type="button"
                data-tab="enrollment"
                data-url="<?php echo $tab_urls['enrollment'] ? $tab_urls['enrollment']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'enrollment' ? 'true' : 'false'; ?>">
                <i class="fa fa-user-plus"></i>
                Course Enrollment Report
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'completion' ? ' active' : ''; ?>"
                type="button"
                data-tab="completion"
                data-url="<?php echo $tab_urls['completion'] ? $tab_urls['completion']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'completion' ? 'true' : 'false'; ?>">
                <i class="fa fa-check-circle"></i>
                Course Completion Reports
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'engagement' ? ' active' : ''; ?>"
                type="button"
                data-tab="engagement"
                data-url="<?php echo $tab_urls['engagement'] ? $tab_urls['engagement']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'engagement' ? 'true' : 'false'; ?>">
                <i class="fa fa-user-friends"></i>
                Course Engagement
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'overview' ? ' active' : ''; ?>"
                type="button"
                data-tab="overview"
                data-url="<?php echo $tab_urls['overview'] ? $tab_urls['overview']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'overview' ? 'true' : 'false'; ?>">
                <i class="fa fa-dashboard"></i>
                Course Overview Reports
            </button>
            <button
                class="tab-button<?php echo $initial_tab === 'distribution' ? ' active' : ''; ?>"
                type="button"
                data-tab="distribution"
                data-url="<?php echo $tab_urls['distribution'] ? $tab_urls['distribution']->out(false) : ''; ?>"
                aria-selected="<?php echo $initial_tab === 'distribution' ? 'true' : 'false'; ?>">
                <i class="fa fa-chart-pie"></i>
                Course Distribution Reports
            </button>
        </div>
        </div>

        <div id="c-reports-tab-content" data-initial-tab="<?php echo $initial_tab; ?>">
        </div>
        <div id="tab-loading-state" class="tab-loading-state" role="status" aria-live="polite">
            <div class="spinner"></div>
            <span>Loading tab data...</span>
        </div>
    </div>
</div>

<script>
const cReportsTabUrls = <?php echo json_encode($tab_url_map, JSON_UNESCAPED_SLASHES); ?>;
const cReportsTabLabels = <?php echo json_encode($tab_labels, JSON_UNESCAPED_SLASHES); ?>;
const cReportsInitialTab = <?php echo json_encode($initial_tab); ?>;
const cReportsSummaryUrl = <?php echo json_encode($summary_url_string, JSON_UNESCAPED_SLASHES); ?>;
const cReportsInitialPageUrl = window.location.href;
let cReportsActiveTab = cReportsInitialTab;

function mergeCReportsHistoryState(overrides = {}) {
    const currentState = (window.history.state && typeof window.history.state === 'object') ? window.history.state : {};
    const nextState = Object.assign({}, currentState, overrides);
    if (!('cReportsPageUrl' in nextState) || !nextState.cReportsPageUrl) {
        nextState.cReportsPageUrl = currentState.cReportsPageUrl || cReportsInitialPageUrl;
    }
    if (!('tab' in nextState) || !nextState.tab) {
        nextState.tab = cReportsInitialTab;
    }
    return nextState;
}
window.__cReportsMergeState = mergeCReportsHistoryState;

document.addEventListener('DOMContentLoaded', function() {
    // Remove any old 'activitycompletion' tab buttons that might exist from cached pages
    const oldTabButtons = document.querySelectorAll('.tab-button[data-tab="activitycompletion"]');
    oldTabButtons.forEach(button => {
        button.remove();
        console.log('Removed old Activity Completion Report tab button');
    });
    
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContentWrapper = document.getElementById('c-reports-tab-content');
    const loadingState = document.getElementById('tab-loading-state');

    if (!tabButtons.length || !tabContentWrapper) {
        return;
    }

    const tabPanes = new Map();

    function setActiveButton(tabName) {
        tabButtons.forEach(button => {
            const isActive = button.dataset.tab === tabName;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        cReportsActiveTab = tabName;
    }

    function showPane(tabName) {
        tabPanes.forEach((pane, key) => {
            const isActive = key === tabName;
            if (isActive) {
                pane.classList.add('active');
                pane.removeAttribute('hidden');
                // Force reflow to ensure styles are recalculated
                void pane.offsetHeight;
                
                // Trigger chart re-initialization for courses tab
                if (key === 'courses') {
                    // Call the global chart initialization function if it exists
                    setTimeout(function() {
                        if (typeof window.initCourseCharts === 'function') {
                            window.initCourseCharts();
                        }
                        
                        // Also dispatch event for any listeners
                        const event = new CustomEvent('cReportsTabShown', { 
                            detail: { tab: key },
                            bubbles: true 
                        });
                        pane.dispatchEvent(event);
                        document.dispatchEvent(event);
                    }, 150);
                }
            } else {
                pane.classList.remove('active');
                pane.setAttribute('hidden', '');
            }
        });
    }

    function showLoading() {
        if (loadingState) {
            loadingState.classList.add('visible');
        }
    }

    function hideLoading() {
        if (loadingState) {
            loadingState.classList.remove('visible');
        }
    }

    function resolveTabUrl(tabName) {
        if (cReportsTabUrls[tabName]) {
            return cReportsTabUrls[tabName];
        }
        const separator = cReportsSummaryUrl.includes('?') ? '&' : '?';
        return cReportsSummaryUrl + separator + 'tab=' + encodeURIComponent(tabName);
    }

    function buildAjaxUrl(tabName) {
        const base = resolveTabUrl(tabName);
        try {
            const url = new URL(base, window.location.origin);
            url.searchParams.set('ajax', '1');
            return url.toString();
        } catch (error) {
            console.error('Failed to build AJAX URL for tab', tabName, base, error);
            return base + (base.includes('?') ? '&' : '?') + 'ajax=1';
        }
    }

    function buildPlaceholder(tabName) {
        const label = cReportsTabLabels[tabName] || tabName;
        return `
            <div class="tab-placeholder">
                <i class="fa fa-clipboard"></i>
                <h4>${label} coming soon</h4>
                <p>We're finalizing data for the ${label} tab. Please check back shortly.</p>
            </div>
        `;
    }

    function buildError(message) {
        return `
            <div class="tab-error-message">
                <i class="fa fa-exclamation-triangle"></i>
                <h4>Unable to load tab</h4>
                <p>${message || 'Please try again in a moment.'}</p>
            </div>
        `;
    }

    function createPane(tabName, html) {
        let pane = tabPanes.get(tabName);
        if (!pane) {
            pane = document.createElement('div');
            pane.className = 'tab-pane';
            pane.dataset.tab = tabName;
            pane.setAttribute('hidden', '');
            tabContentWrapper.appendChild(pane);
            tabPanes.set(tabName, pane);
        }
        
        // Remove existing style tags from this pane to prevent duplication
        const existingStyles = pane.querySelectorAll('style');
        existingStyles.forEach(style => {
            // Remove from DOM
            if (style.parentNode) {
                style.parentNode.removeChild(style);
            }
        });
        
        // Create a temporary container to parse the HTML
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        
        // Extract style tags and deduplicate them by content
        const styleTags = tempDiv.querySelectorAll('style');
        const uniqueStyles = new Map();
        styleTags.forEach(style => {
            const styleContent = style.innerHTML.trim();
            if (styleContent && !uniqueStyles.has(styleContent)) {
                const newStyle = document.createElement('style');
                newStyle.innerHTML = styleContent;
                newStyle.dataset.tab = tabName;
                uniqueStyles.set(styleContent, newStyle);
            }
        });
        
        // Remove style tags from tempDiv
        styleTags.forEach(style => style.remove());
        
        // Set the content without style tags (this clears and sets in one operation)
        pane.innerHTML = tempDiv.innerHTML;
        
        // Add unique styles back to the pane (only if not already present)
        uniqueStyles.forEach((style, content) => {
            const existing = Array.from(pane.querySelectorAll('style')).find(s => s.innerHTML.trim() === content);
            if (!existing) {
                pane.appendChild(style);
            }
        });
        
        const inlineScripts = [];
        const externalScripts = new Set();
        const scriptNodes = pane.querySelectorAll('script');

        scriptNodes.forEach(script => {
            const src = script.getAttribute('src');
            if (src) {
                externalScripts.add(src);
            } else {
                inlineScripts.push(script.innerHTML);
            }
            script.parentNode.removeChild(script);
        });

        const loadExternalScript = (src) => new Promise((resolve, reject) => {
            if (!src) {
                resolve();
                return;
            }
            const existing = document.querySelector(`script[src="${src}"]`);
            if (existing) {
                if (existing.dataset.loaded === 'true' || existing.readyState === 'complete') {
                    resolve();
                } else {
                    existing.addEventListener('load', () => {
                        existing.dataset.loaded = 'true';
                        resolve();
                    }, { once: true });
                    existing.addEventListener('error', reject, { once: true });
                }
                return;
            }
            const tag = document.createElement('script');
            tag.src = src;
            tag.async = true;
            tag.dataset.loaded = 'false';
            tag.onload = () => {
                tag.dataset.loaded = 'true';
                resolve();
            };
            tag.onerror = reject;
            document.body.appendChild(tag);
        });

        const ensureScripts = Array.from(externalScripts).reduce((promise, src) => {
            return promise.then(() => loadExternalScript(src));
        }, Promise.resolve());

        ensureScripts.then(() => {
            inlineScripts.forEach(code => {
                const newScript = document.createElement('script');
                newScript.appendChild(document.createTextNode(code));
                pane.appendChild(newScript);
            });
        }).catch(error => console.error('Failed to load scripts for tab', tabName, error));
        
        return pane;
    }

    async function activateTab(tabName, options = {}) {
        const { pushState = true, bypassCache = false, fetchUrlOverride = null } = options;
        
        // Redirect old 'activitycompletion' tab to 'enrollment' tab
        if (tabName === 'activitycompletion') {
            console.warn('Activity Completion Report tab has been removed. Redirecting to Course Enrollment Report.');
            tabName = 'enrollment';
        }
        
        const targetTab = (tabName && (Object.prototype.hasOwnProperty.call(cReportsTabUrls, tabName) || Object.prototype.hasOwnProperty.call(cReportsTabLabels, tabName)))
            ? tabName
            : 'courses';
        const targetUrl = resolveTabUrl(targetTab);

        if (!bypassCache && tabPanes.has(targetTab) && tabPanes.get(targetTab).innerHTML.trim() !== '') {
            showPane(targetTab);
            setActiveButton(targetTab);
            hideLoading();
            
            // Re-initialize charts for courses tab when shown from cache
            if (targetTab === 'courses') {
                setTimeout(function() {
                    // Call the global chart initialization function if it exists
                    if (typeof window.initCourseCharts === 'function') {
                        window.initCourseCharts();
                    }
                    
                    // Also dispatch event for any listeners
                    const pane = tabPanes.get(targetTab);
                    if (pane) {
                        const event = new CustomEvent('cReportsTabShown', { 
                            detail: { tab: targetTab },
                            bubbles: true 
                        });
                        pane.dispatchEvent(event);
                        document.dispatchEvent(event);
                    }
                }, 150);
            }
            
            if (pushState) {
                window.history.pushState(
                    mergeCReportsHistoryState({ tab: targetTab }),
                    '',
                    targetUrl
                );
            }
            return;
        }

        showLoading();

        try {
            let htmlContent;
            if (cReportsTabUrls[targetTab]) {
                const fetchUrl = fetchUrlOverride || buildAjaxUrl(targetTab);
                const response = await fetch(fetchUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Server returned an error while loading this tab.');
                }
                htmlContent = await response.text();
            } else {
                htmlContent = buildPlaceholder(targetTab);
            }

            createPane(targetTab, htmlContent);
            showPane(targetTab);
            setActiveButton(targetTab);
            
            if (pushState) {
                window.history.pushState(
                    mergeCReportsHistoryState({ tab: targetTab }),
                    '',
                    targetUrl
                );
            }
        } catch (error) {
            createPane(targetTab, buildError(error.message));
            showPane(targetTab);
            setActiveButton(targetTab);
        } finally {
            hideLoading();
        }
    }

    tabButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            const tabName = this.dataset.tab || 'courses';
            activateTab(tabName);
        });
    });

    window.addEventListener('popstate', function(event) {
        const newTab = event.state && event.state.tab ? event.state.tab : 'courses';
        activateTab(newTab, { pushState: false });
    });

    window.history.replaceState(
        mergeCReportsHistoryState({ tab: cReportsInitialTab, cReportsPageUrl: window.location.href }),
        '',
        window.location.href
    );

    // Load initial tab content
    activateTab(cReportsInitialTab, { pushState: false });
});

window.downloadCReportsTab = function() {
    const formatSelect = document.getElementById('cReportsDownloadFormat');
    const format = formatSelect ? formatSelect.value : 'excel';
    const activeTab = cReportsActiveTab || cReportsInitialTab || 'courses';
    const baseUrl = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/c_reports_download.php';
    const downloadUrl = baseUrl + '?tab=' + encodeURIComponent(activeTab) + '&format=' + encodeURIComponent(format || 'excel');
    window.location.href = downloadUrl;
};
</script>

<!-- Include Chart.js with AMD guard to avoid Moodle require.js conflicts -->
<script>
(function() {
    if (typeof window !== 'undefined' && window.define && window.define.amd) {
        window.__cReportsSavedAmd = window.define.amd;
        window.define.amd = undefined;
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js" data-c-reports-chartjs></script>
<script>
(function() {
    if (typeof window !== 'undefined' && window.__cReportsSavedAmd) {
        if (window.define) {
            window.define.amd = window.__cReportsSavedAmd;
        }
        delete window.__cReportsSavedAmd;
    }
})();
</script>

<?php echo $OUTPUT->footer(); ?>
<script>
(function() {
    if (typeof window !== 'undefined' && window.__cReportsSavedAmd) {
        if (window.define) {
            window.define.amd = window.__cReportsSavedAmd;
        }
        delete window.__cReportsSavedAmd;
    }
})();
</script>

<?php echo $OUTPUT->footer(); ?>