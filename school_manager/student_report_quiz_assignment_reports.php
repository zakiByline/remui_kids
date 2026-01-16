<?php
/**
 * Student Report - Quiz & Assignment Reports Tab (Parent Tab with Sub-tabs)
 * This tab contains two sub-tabs: Quiz Reports and Assignment Reports
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);
$subtab = optional_param('subtab', 'quiz', PARAM_ALPHANUMEXT);

if (!in_array($subtab, ['quiz', 'assignment'], true)) {
    $subtab = 'quiz';
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'quizassignmentreports', 'subtab' => $subtab]);
    redirect($target);
}

// Generate URLs for sub-tabs
$quiz_url = new moodle_url('/theme/remui_kids/school_manager/student_report_quiz_assignments.php', ['ajax' => 1]);
$assignment_url = new moodle_url('/theme/remui_kids/school_manager/student_report_assignments.php', ['ajax' => 1]);

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.quiz-assignment-reports-container {
    padding: 0;
}

.sub-tabs-container {
    margin-bottom: 35px;
}

.sub-tabs-nav-wrapper {
    display: flex;
    justify-content: center;
}

.sub-tabs-nav {
    display: inline-flex;
    gap: 10px;
    padding: 8px;
    background: #f8fafc;
    border-radius: 999px;
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.35);
    flex-wrap: nowrap;
    white-space: nowrap;
}

.sub-tab-button {
    padding: 10px 24px;
    background: transparent;
    border: none;
    color: #64748b;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

.sub-tab-button i {
    font-size: 0.9rem;
}

.sub-tab-button:hover {
    color: #0f172a;
}

.sub-tab-button.active {
    color: #0f172a;
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(148, 163, 184, 0.35);
}

.sub-tab-pane {
    display: none;
}

.sub-tab-pane.active {
    display: block;
}

.sub-tab-loading {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.sub-tab-loading .spinner {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 4px solid #e5e7eb;
    border-top-color: #3b82f6;
    animation: spin 0.8s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .sub-tab-button {
        padding: 10px 16px;
        font-size: 0.875rem;
    }
    
    .sub-tabs-nav-wrapper {
        justify-content: flex-start;
    }
    
    .sub-tabs-nav {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .sub-tabs-nav::-webkit-scrollbar {
        height: 4px;
    }
}
</style>

<div class="quiz-assignment-reports-container">
    <!-- Sub-tabs Navigation -->
    <div class="sub-tabs-container">
        <div class="sub-tabs-nav-wrapper">
        <div class="sub-tabs-nav">
            <button
                class="sub-tab-button<?php echo $subtab === 'quiz' ? ' active' : ''; ?>"
                type="button"
                data-subtab="quiz"
                aria-selected="<?php echo $subtab === 'quiz' ? 'true' : 'false'; ?>">
                <i class="fa fa-clipboard-check"></i>
                Quiz Reports
            </button>
            <button
                class="sub-tab-button<?php echo $subtab === 'assignment' ? ' active' : ''; ?>"
                type="button"
                data-subtab="assignment"
                aria-selected="<?php echo $subtab === 'assignment' ? 'true' : 'false'; ?>">
                <i class="fa fa-file-alt"></i>
                Assignment Reports
            </button>
        </div>
        </div>
    </div>
    
    <!-- Sub-tab Content -->
    <div id="quiz-assignment-subtab-content">
        <div class="sub-tab-pane<?php echo $subtab === 'quiz' ? ' active' : ''; ?>" data-subtab="quiz">
            <div class="sub-tab-loading">
                <div class="spinner"></div>
                <p>Loading Quiz Reports...</p>
            </div>
        </div>
        <div class="sub-tab-pane<?php echo $subtab === 'assignment' ? ' active' : ''; ?>" data-subtab="assignment">
            <div class="sub-tab-loading">
                <div class="spinner"></div>
                <p>Loading Assignment Reports...</p>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const subTabButtons = document.querySelectorAll('.sub-tab-button');
    const subTabPanes = document.querySelectorAll('.sub-tab-pane');
    const subTabContent = document.getElementById('quiz-assignment-subtab-content');
    
    // Sub-tab URLs
    const subTabUrls = {
        'quiz': '<?php echo $quiz_url->out(false); ?>',
        'assignment': '<?php echo $assignment_url->out(false); ?>'
    };
    
    // Loaded content cache
    const loadedContent = {
        'quiz': null,
        'assignment': null
    };
    
    function setActiveSubTab(subTabName) {
        subTabButtons.forEach(btn => {
            const isActive = btn.getAttribute('data-subtab') === subTabName;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        
        subTabPanes.forEach(pane => {
            const isActive = pane.getAttribute('data-subtab') === subTabName;
            pane.classList.toggle('active', isActive);
            pane.hidden = !isActive;
        });
    }
    
    async function loadSubTabContent(subTabName) {
        const pane = document.querySelector(`.sub-tab-pane[data-subtab="${subTabName}"]`);
        if (!pane) return;
        
        // If already loaded, just show it
        if (loadedContent[subTabName]) {
            pane.innerHTML = loadedContent[subTabName];
            // Re-execute scripts
            const scripts = pane.querySelectorAll('script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                oldScript.parentNode.removeChild(oldScript);
                pane.appendChild(newScript);
            });
            return;
        }
        
        // Show loading state
        pane.innerHTML = '<div class="sub-tab-loading"><div class="spinner"></div><p>Loading ' + (subTabName === 'quiz' ? 'Quiz' : 'Assignment') + ' Reports...</p></div>';
        
        try {
            const url = subTabUrls[subTabName];
            if (!url) {
                throw new Error('No URL defined for this sub-tab');
            }
            
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Failed to load content');
            }
            
            const html = await response.text();
            loadedContent[subTabName] = html;
            pane.innerHTML = html;
            
            // Extract and execute scripts
            const scripts = pane.querySelectorAll('script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                oldScript.parentNode.removeChild(oldScript);
                pane.appendChild(newScript);
            });
            
            // Trigger chart initialization if needed
            setTimeout(function() {
                if (subTabName === 'quiz' && typeof window.initQuizReportsChart === 'function') {
                    window.initQuizReportsChart();
                } else if (subTabName === 'assignment' && typeof window.initAssignmentReportsChart === 'function') {
                    window.initAssignmentReportsChart();
                }
            }, 500);
            
        } catch (error) {
            console.error('Error loading sub-tab content:', error);
            pane.innerHTML = '<div class="sub-tab-loading"><p style="color: #ef4444;">Error loading content. Please try again.</p></div>';
        }
    }
    
    // Handle sub-tab button clicks
    subTabButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const subTabName = this.getAttribute('data-subtab');
            setActiveSubTab(subTabName);
            loadSubTabContent(subTabName);
            
            // Update URL without reload
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('subtab', subTabName);
            window.history.pushState({ subtab: subTabName }, '', currentUrl.toString());
        });
    });
    
    // Load initial sub-tab content
    const initialSubTab = '<?php echo $subtab; ?>';
    setActiveSubTab(initialSubTab);
    loadSubTabContent(initialSubTab);
    
    // Expose function for parent page to trigger reload
    window.reloadQuizAssignmentSubTab = function(subTabName) {
        if (subTabName && loadedContent[subTabName]) {
            loadedContent[subTabName] = null; // Clear cache
        }
        loadSubTabContent(subTabName || initialSubTab);
    };
})();
</script>

<?php
echo ob_get_clean();
exit;
?>

