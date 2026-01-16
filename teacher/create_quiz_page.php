<?php
/**
 * Quiz Creation Page
 * Dedicated page for creating new quizzes with question management
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access quiz creation page');
}

// Page setup
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/create_quiz_page.php');
$PAGE->set_title('Create Quiz');
$PAGE->set_heading('Create New Quiz');
$PAGE->add_body_class('quiz-creation-page');

// Get teacher's courses limited to their school/company
$teacher_company_id = theme_remui_kids_get_teacher_company_id();
$teacher_courses = theme_remui_kids_get_teacher_school_courses($USER->id, $teacher_company_id);

$defaultaidifficulty = get_config('local_quizai', 'defaultdifficulty');
if (empty($defaultaidifficulty)) {
    $defaultaidifficulty = 'balanced';
}
$aiquizgeneratorenabled = (bool)get_config('local_quizai', 'enabled');

echo $OUTPUT->header();

$quiz_edit_mode = !empty($GLOBALS['quiz_edit_mode']);
$quiz_edit_data = $quiz_edit_mode && !empty($GLOBALS['quiz_edit_data']) ? $GLOBALS['quiz_edit_data'] : null;
if ($quiz_edit_mode && $quiz_edit_data !== null) {
    $encoded = json_encode($quiz_edit_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo "<script>window.isQuizEditMode = true; window.quizEditData = $encoded;</script>";
}
$isquizeditmode = !empty($quiz_edit_mode);

if (!function_exists('remui_kids_render_quiz_questions_panel')) {
    function remui_kids_render_quiz_questions_panel() {
        global $isquizeditmode;
        $paneldescription = $isquizeditmode
            ? 'Review and manage the questions currently included in this quiz.'
            : 'Questions you add (manually or via AI/import) will appear here for review.';
        ?>
        <div class="form-section">
            <p class="form-section-description">
                <?php echo $paneldescription; ?>
            </p>
            <div class="info-box" id="existingQuestionsLoader" style="display: none;">
                <i class="fa fa-spinner fa-spin"></i>
                <span>Loading question details...</span>
            </div>
            <div class="info-box" id="existingQuestionsEmpty" style="display: none;">
                <i class="fa fa-info-circle"></i>
                <span>No questions have been added to this quiz yet.</span>
            </div>
            <div class="quiz-questions-container" id="quizQuestionsContainer" style="display: none;">
                <div class="quiz-questions-header">
                    <div>
                        <span class="quiz-questions-title">Quiz Questions</span>
                        <span class="questions-count-badge" id="questionsCountBadge">0 questions</span>
                    </div>
                    <div>
                        <span style="font-size: 14px; color: #6c757d; margin-right: 16px;">
                            Total Marks: <strong id="totalMarks">0</strong>
                        </span>
                    </div>
                </div>
                <div class="quiz-questions-list" id="quizQuestionsList">
                    <!-- Added questions will appear here -->
                </div>
            </div>
        </div>
        <?php
    }
}
?>

<style>
/* Hide Moodle's default main content area */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Reuse assignment page styles */
.teacher-css-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

.quiz-creation-container {
    background: #fff;
    margin: 0 auto;
    padding: 30px 40px;
    max-width: 100%;
    width: 100%;
}

.page-header {
    background: linear-gradient(135deg, #e8f5f0 0%, #e3f2fd 50%, #e8f8f5 100%);
    padding: 35px 40px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.8);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #20c997, #28a745, #20c997);
    background-size: 200% 100%;
    animation: gradientShift 3s ease infinite;
}

@keyframes gradientShift {
    0%, 100% { background-position: 0% 0%; }
    50% { background-position: 100% 0%; }
}

.page-header-content {
    display: flex;
    align-items: flex-start;
    gap: 24px;
}

.page-header-text {
    flex: 1;
    text-align: left;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
}

.page-title-header {
    font-size: 32px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 8px 0;
    text-align: left;
}

.page-subtitle {
    color: #6c757d;
    font-size: 15px;
    margin: 0;
    line-height: 1.5;
    text-align: left;
    width: 100%;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #495057;
    text-decoration: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.back-button:hover {
    background: #f8f9fa;
    color: #2c3e50;
    text-decoration: none;
    transform: translateX(-3px);
    border-color: #28a745;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.15);
}

.back-button i {
    font-size: 16px;
    transition: transform 0.3s ease;
}

.back-button:hover i {
    transform: translateX(-2px);
}

/* Form Sections */
.creation-form {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    overflow: hidden;
}

.form-section {
    padding: 30px;
    border-bottom: 1px solid #e9ecef;
}

.form-section:last-child {
    border-bottom: none;
}

.tab-panel .form-section:first-of-type {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

.tab-panel .form-section:not(:first-of-type) {
    padding-top: 20px;
}

.form-section-title {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 20px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section-title i {
    color: #28a745;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-label {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
}

.form-label.required::after {
    content: ' *';
    color: #dc3545;
}

.form-input, .form-select, .form-textarea {
    padding: 12px 16px;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    width: 100%;
    box-sizing: border-box;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 120px;
}

.ai-meta-wrapper {
    margin-top: 16px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    background: #f9fbff;
}

.ai-meta-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.ai-meta-header .context {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ai-meta-header .context strong {
    font-size: 15px;
}

.ai-meta-header .context span {
    font-size: 13px;
    color: #555;
}

.ai-meta-generate-btn {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.ai-meta-generate-btn:disabled {
    background: #cbd5f5;
    cursor: not-allowed;
}

.ai-meta-body {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.ai-meta-body.loading::after {
    content: 'Generating suggestions...';
    color: #475467;
    font-size: 14px;
}

.ai-meta-card {
    border: 1px solid #dce6ff;
    background: #fff;
    padding: 12px 14px;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-meta-card h4 {
    margin: 0;
    font-size: 15px;
    color: #1e293b;
}

.ai-meta-card p {
    margin: 0;
    color: #475467;
    font-size: 14px;
    line-height: 1.5;
}

.ai-meta-card .meta-tags {
    font-size: 12px;
    color: #64748b;
}

.ai-meta-card .apply-btn {
    align-self: flex-start;
    margin-top: 4px;
    background: #0f9d58;
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}

.ai-meta-card .apply-btn:hover {
    background: #0b7d45;
}

.ai-meta-placeholder {
    text-align: center;
    font-size: 14px;
    color: #6c757d;
    padding: 12px 0;
}

.form-text {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: #6c757d;
    font-style: italic;
}

/* Tab Navigation */
.form-tabs {
    margin-bottom: 0;
    background: white;
    border-radius: 12px 12px 0 0;
    border: 1px solid #e9ecef;
    border-bottom: none;
}

.tab-nav {
    display: flex;
    background: #f8f9fa;
    border-radius: 12px 12px 0 0;
    padding: 0;
    margin: 0;
    overflow-x: auto;
}

.tab-button {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px 20px;
    border: none;
    background: transparent;
    color: #6c757d;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
    min-width: 120px;
}

.tab-button:hover {
    background: #e9ecef;
    color: #495057;
}

.tab-button.active {
    background: white;
    color: #28a745;
    border-bottom-color: #28a745;
    font-weight: 600;
}

.tab-button i {
    font-size: 16px;
}

/* Tab Content */
.tab-content {
    background: white;
    border: 1px solid #e9ecef;
    border-top: none;
    border-radius: 0 0 12px 12px;
    min-height: 0;
}

.tab-panel {
    display: none;
    padding: 20px 40px;
    animation: fadeIn 0.3s ease-in-out;
}

.tab-panel.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* AI Generator */
.ai-generator-block {
    border: 1px solid #d1e7dd;
    background: #f5fffa;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    position: relative;
}

.ai-generator-block.loading::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.6);
    border-radius: 12px;
    animation: aiPulse 1.2s ease-in-out infinite;
}

@keyframes aiPulse {
    0% { opacity: 0.2; }
    50% { opacity: 0.5; }
    100% { opacity: 0.2; }
}

.ai-generator-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}

.ai-generator-header .ai-generator-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    color: #166534;
    font-size: 16px;
}

.ai-generator-header .ai-generator-title i {
    font-size: 18px;
}

.ai-generator-header p {
    margin: 0;
    color: #365b46;
    font-size: 13px;
}

.ai-generator-inputs {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.ai-input-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-input-group label {
    font-size: 13px;
    font-weight: 600;
    color: #2f4f4f;
}

.ai-topic-input-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ai-topic-reset-btn {
    border: none;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    white-space: nowrap;
}

.ai-topic-reset-btn i {
    font-size: 12px;
}

.ai-topic-reset-btn:hover {
    background: #dbeafe;
}

.gapselect-group-card {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.gapselect-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 12px;
}

.gapselect-row label {
    font-weight: 600;
    font-size: 13px;
    color: #1f2937;
}

.btn-small {
    border: none;
    background: #f3f4f6;
    color: #1f2937;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-small:hover {
    background: #e5e7eb;
}

.btn-small.danger {
    background: #fee2e2;
    color: #b91c1c;
}

.btn-small.danger:hover {
    background: #fecaca;
}

.ai-generator-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}

.btn-ai-generate {
    background: linear-gradient(135deg, #16a34a, #22c55e);
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 999px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.btn-ai-generate:hover {
    box-shadow: 0 10px 25px rgba(34, 197, 94, 0.25);
    transform: translateY(-1px);
}

.btn-ai-generate.disabled,
.btn-ai-generate:disabled {
    background: #94a3b8;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
}

.ai-generator-status {
    font-size: 13px;
    color: #2f4f4f;
}

.ai-generator-status.success {
    color: #16a34a;
}

.ai-generator-status.error {
    color: #dc2626;
}

/* Date Time Groups */
.date-time-group {
    margin-bottom: 24px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    background: #f8f9fa;
}

.date-time-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.enable-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
}

.enable-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
}

.enable-toggle label {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    cursor: pointer;
}

.date-time-fields {
    display: none;
    transition: all 0.3s ease;
}

.date-time-fields.show {
    display: block;
}

.date-time-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: end;
}

.date-fields, .time-fields {
    display: flex;
    gap: 8px;
    align-items: center;
}

.date-fields select, .time-fields select {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    background: white;
}

/* Course Structure Tree */
.course-structure-section {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.course-structure-title {
    font-size: 16px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 15px;
}

.course-structure-tree {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    background: white;
    max-height: 400px;
    overflow-y: auto;
}

.course-tree {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tree-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s ease;
    margin-bottom: 2px;
    border: 1px solid transparent;
}

.tree-item:hover {
    background: #e9ecef;
    border-color: #dee2e6;
}

.tree-item.selected {
    background: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.tree-item.disabled {
    color: #6c757d;
    cursor: not-allowed;
    background: #f8f9fa;
}

.tree-icon {
    margin-right: 10px;
    font-size: 16px;
    width: 20px;
    text-align: center;
}

.tree-label {
    font-size: 14px;
    font-weight: 500;
    flex: 1;
}

.tree-toggle {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: #6c757d;
    font-size: 12px;
}

.tree-children {
    margin-left: 24px;
    border-left: 2px solid #dee2e6;
    padding-left: 16px;
}

.selection-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 12px 16px;
    margin-top: 15px;
    font-size: 14px;
    color: #0c5460;
}

.selection-info.hidden {
    display: none;
}

/* Form Actions */
.form-actions {
    padding: 30px;
    background: #f8f9fa;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 16px;
    border-top: 1px solid #e9ecef;
}

.btn-cancel,
.btn-submit {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    justify-content: center;
}

.btn-cancel {
    background: #ffffff;
    color: #6c757d;
    border: 1px solid #dee2e6;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.btn-cancel:hover {
    background: #f8f9fa;
    color: #495057;
    border-color: #adb5bd;
    text-decoration: none;
}

.btn-submit {
    background: #28a745;
    color: white;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
}

.btn-submit:hover {
    background: #218838;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
    color: white;
}

.btn-submit:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    transform: none;
}

/* Questions Tab Specific */
.question-modes {
    display: flex;
    gap: 12px;
    background: #f8f9fa;
    padding: 6px;
    border-radius: 8px;
    margin-bottom: 24px;
}

.question-inner-tabs {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 12px;
}

.question-inner-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #dfe3e6;
    background: #fff;
    border-radius: 30px;
    padding: 10px 18px;
    font-size: 14px;
    font-weight: 600;
    color: #6c757d;
    cursor: pointer;
    transition: all 0.2s ease;
}

.question-inner-tab-btn.active {
    background: #28a745;
    border-color: #28a745;
    color: #fff;
    box-shadow: 0 4px 10px rgba(40, 167, 69, 0.2);
}

.question-inner-tab-panel {
    display: none;
}

.question-inner-tab-panel.active {
    display: block;
}

.mode-toggle-btn {
    flex: 1;
    padding: 12px 24px;
    border: none;
    background: transparent;
    color: #6c757d;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.mode-toggle-btn.active {
    background: white;
    color: #28a745;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mode-toggle-btn:hover:not(.active) {
    background: #e9ecef;
}

.question-mode-content {
    display: none;
}

.question-mode-content.active {
    display: block;
}

/* Question Bank Import */
.import-filters {
    display: flex;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.question-list {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    max-height: 500px;
    overflow-y: auto;
    background: white;
}

.question-item {
    padding: 16px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.question-item:hover {
    background: #f8f9fa;
}

.question-item.selected {
    background: #d4edda;
    border-left: 4px solid #28a745;
}

.question-checkbox {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: #28a745;
}

.question-details {
    flex: 1;
}

.question-name {
    font-size: 15px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
}

.question-type-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1976d2;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.question-text-preview {
    font-size: 13px;
    color: #6c757d;
    margin-bottom: 4px;
}

.question-marks {
    font-size: 12px;
    color: #28a745;
    font-weight: 600;
}

/* Question Creation Builder */
.question-type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}

.question-type-card {
    padding: 20px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: white;
}

.question-type-card:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.question-type-card.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.05);
}

.question-type-card i {
    font-size: 32px;
    color: #5b9bd5;
    margin-bottom: 8px;
}

.question-type-name {
    font-size: 13px;
    font-weight: 600;
    color: #2c3e50;
}

.question-builder-form {
    display: none;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 24px;
    background: #f8f9fa;
}

.question-builder-form.active {
    display: block;
}

/* Quiz Questions Management */
.quiz-questions-container {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: white;
    margin-top: 24px;
}

.quiz-questions-header {
    padding: 16px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.quiz-questions-title {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
}

.questions-count-badge {
    background: #28a745;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.quiz-questions-list {
    padding: 16px;
}

.quiz-question-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 8px;
}

.question-drag-handle {
    color: #6c757d;
    cursor: move;
}

.question-item-number {
    font-weight: 600;
    color: #6c757d;
    min-width: 30px;
}

.question-item-info {
    flex: 1;
}

.question-item-name {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
}

.question-item-type {
    font-size: 12px;
    color: #6c757d;
}

.question-item-actions {
    display: flex;
    gap: 8px;
}

.btn-icon {
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-icon:hover {
    background: #e9ecef;
    color: #495057;
}

.btn-icon.delete:hover {
    background: #fff5f5;
    color: #dc3545;
}

/* Review Options Grid */
.review-options-grid {
    display: grid;
    grid-template-columns: 1fr repeat(4, auto);
    gap: 0;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}

.review-option-label,
.review-option-header {
    padding: 12px 16px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    font-size: 13px;
    font-weight: 600;
    color: #495057;
}

.review-option-cell {
    padding: 12px 16px;
    border-bottom: 1px solid #e9ecef;
    border-left: 1px solid #e9ecef;
    text-align: center;
    background: white;
}

.review-option-cell input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
}

/* Competencies Styling */
.competency-search-container {
    margin-bottom: 24px;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 16px;
    color: #6c757d;
    font-size: 16px;
    pointer-events: none;
}

.competency-search-input {
    width: 100%;
    padding: 12px 45px 12px 45px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    color: #495057;
    background: white;
    transition: all 0.3s ease;
}

.competency-search-input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.clear-search-btn {
    position: absolute;
    right: 12px;
    width: 28px;
    height: 28px;
    border: none;
    background: #6c757d;
    color: white;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.2s ease;
}

.clear-search-btn:hover {
    background: #495057;
    transform: scale(1.1);
}

.competencies-container {
    padding: 20px;
}

.competencies-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 600px;
    overflow-y: auto;
    padding: 8px;
}

.competency-tree {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.competency-tree-children {
    margin-left: 32px;
    padding-left: 16px;
    border-left: 2px solid #e9ecef;
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.competency-item {
    display: flex;
    flex-direction: row !important;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    border: 1.5px solid #e9ecef;
    border-radius: 6px;
    background: white;
    transition: all 0.2s ease;
    position: relative;
}

.competency-item.has-children {
    padding-left: 40px;
}

.competency-toggle {
    position: absolute;
    left: 12px;
    top: 14px;
    width: 20px;
    height: 20px;
    border: none;
    background: #28a745;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.competency-toggle:hover {
    background: #218838;
    transform: scale(1.1);
}

.competency-toggle.collapsed::before {
    content: '▶';
}

.competency-toggle.expanded::before {
    content: '▼';
}

.competency-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}

.competency-item.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.03);
}

.competency-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
    cursor: pointer;
    margin-top: 3px;
    flex-shrink: 0;
}

.competency-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    margin-bottom: 0 !important;
}

.competency-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.competency-name {
    font-size: 14px;
    font-weight: 600;
    color: #212529;
    margin: 0;
    flex: 1;
}

.competency-idnumber {
    font-size: 11px;
    color: #868e96;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    flex-shrink: 0;
}

.competency-description {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.5;
    margin: 0;
}

.competency-bottom-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.competency-framework {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    background: #e3f2fd;
    color: #1976d2;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Responsive */
@media (max-width: 768px) {
    .tab-nav {
        flex-wrap: wrap;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}

/* Assign to Groups Section */
.assign-to-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.assign-to-options .radio-item {
    padding: 16px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.assign-to-options .radio-item:has(input:checked) {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.05);
}

.assign-to-options .radio-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.assign-to-options .radio-item label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    cursor: pointer;
    margin-left: 8px;
}

.assign-to-options .radio-item strong {
    font-size: 15px;
    color: #212529;
}

.assign-to-options .radio-item small {
    font-size: 13px;
    color: #6c757d;
    font-weight: normal;
}

.group-selection-container {
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.groups-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 400px;
    overflow-y: auto;
}

.group-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.group-item:hover {
    border-color: #28a745;
    background: #f8f9fa;
}

.group-item.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.05);
}

.group-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #28a745;
    cursor: pointer;
    flex-shrink: 0;
}

.group-details {
    flex: 1;
}

.group-name {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    margin: 0 0 4px 0;
}

.group-description {
    font-size: 13px;
    color: #6c757d;
    margin: 0 0 6px 0;
}

.group-member-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1976d2;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.group-member-count i {
    font-size: 11px;
}

.group-member-count:hover {
    background: #d1ecf1;
    color: #0c5460;
}

/* Members Modal */
.members-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    padding: 20px;
    overflow-y: auto;
}

.members-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.members-modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 800px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.members-modal-header {
    padding: 20px 24px;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.members-modal-title {
    font-size: 20px;
    font-weight: 600;
    color: #212529;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.members-modal-title i {
    color: #007bff;
}

.members-count-badge {
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.members-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.members-modal-close:hover {
    background: #e9ecef;
    color: #212529;
}

.members-modal-search {
    padding: 16px 24px;
    border-bottom: 1px solid #dee2e6;
    background: white;
}

.members-search-input {
    width: 100%;
    padding: 10px 12px 10px 40px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.members-search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.members-search-wrapper {
    position: relative;
}

.members-search-wrapper i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.members-list-content {
    flex: 1;
    overflow-y: auto;
    padding: 16px 24px;
}

.member-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 10px;
    transition: all 0.2s ease;
    background: white;
}

.member-item:hover {
    background: #f8f9fa;
    border-color: #007bff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 18px;
    flex-shrink: 0;
}

.member-info {
    flex: 1;
    min-width: 0;
}

.member-name {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    margin-bottom: 4px;
}

.member-email {
    font-size: 13px;
    color: #6c757d;
}

.member-added {
    font-size: 12px;
    color: #adb5bd;
    margin-left: auto;
    white-space: nowrap;
}

.loading-members,
.no-members,
.error-members {
    padding: 40px 20px;
    text-align: center;
    font-size: 14px;
    color: #6c757d;
}

.loading-members i {
    font-size: 24px;
    margin-bottom: 10px;
}

.error-members {
    color: #dc3545;
}

.no-members {
    color: #6c757d;
}

.members-list-empty {
    padding: 60px 20px;
    text-align: center;
}

.members-list-empty i {
    font-size: 48px;
    color: #dee2e6;
    margin-bottom: 16px;
}

/* Create Group Modal */
.create-group-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    padding: 20px;
    overflow-y: auto;
}

.create-group-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.create-group-modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.create-group-modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.create-group-modal-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.create-group-modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    font-size: 28px;
    color: white;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.create-group-modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
}

.create-group-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

.create-group-modal-footer {
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    border-radius: 0 0 12px 12px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Create Group Button */
.btn-create-group {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
}

.btn-create-group:hover {
    background: linear-gradient(135deg, #218838, #1ea085);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
}

.btn-create-group:active {
    transform: translateY(0);
}

.create-group-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.create-group-form .form-col {
    display: flex;
    flex-direction: column;
}

.create-group-form .form-col-full {
    grid-column: 1 / -1;
}

.create-group-form .form-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #495057;
    margin-bottom: 6px;
}

.create-group-form .required {
    color: #dc3545;
}

.create-group-form .form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.create-group-form .form-input:focus {
    outline: none;
    border-color: #80bdff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
}

.student-selector {
    border: 1px solid #ced4da !important;
    border-radius: 4px;
    overflow: hidden;
    background: #ffffff !important;
    box-shadow: none !important;
}

.student-selector * {
    border-top-color: transparent !important;
    border-bottom-color: transparent !important;
}

.student-selector *:not(.student-item):not(.search-input):not(.student-search) {
    border-left-color: transparent !important;
    border-right-color: transparent !important;
}

.student-search {
    position: relative;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.student-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 13px;
}

.search-input {
    width: 100%;
    padding: 10px 10px 10px 36px;
    border: none;
    background: transparent;
    font-size: 13px;
}

.search-input:focus {
    outline: none;
}

.students-list {
    max-height: 300px;
    overflow-y: auto;
    padding: 10px;
    background: #ffffff !important;
    border: none !important;
    box-shadow: none !important;
}

.students-list::before,
.students-list::after {
    display: none !important;
}

.student-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid #dee2e6 !important;
    border-top: 1px solid #dee2e6 !important;
    border-bottom: 1px solid #dee2e6 !important;
    border-left: 1px solid #dee2e6 !important;
    border-right: 1px solid #dee2e6 !important;
    border-radius: 6px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: none !important;
    transform: none !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    min-height: 60px;
    box-shadow: none !important;
    outline: none !important;
}

.student-item::before,
.student-item::after {
    display: none !important;
}

.student-item:hover {
    background: #f8f9fa !important;
    background-color: #f8f9fa !important;
    border: 1px solid #adb5bd !important;
    border-top: 1px solid #adb5bd !important;
    border-bottom: 1px solid #adb5bd !important;
    border-left: 1px solid #adb5bd !important;
    border-right: 1px solid #adb5bd !important;
    transform: none !important;
    transition: none !important;
}

.student-item.selected {
    background: #d4edda !important;
    background-color: #d4edda !important;
    border: 1px solid #28a745 !important;
    border-top: 1px solid #28a745 !important;
    border-bottom: 1px solid #28a745 !important;
    border-left: 1px solid #28a745 !important;
    border-right: 1px solid #28a745 !important;
    box-shadow: 0 2px 6px rgba(40, 167, 69, 0.2) !important;
}

.student-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
    accent-color: #28a745;
}

.student-details {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.student-name {
    font-size: 14px;
    font-weight: 600;
    color: #212529;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.student-email {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.btn-cancel {
    padding: 8px 16px;
    background: #ffffff;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    color: #495057;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-cancel:hover {
    background: #e9ecef;
}

.btn-create {
    padding: 8px 16px;
    background: #28a745;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    color: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-create:hover {
    background: #218838;
}

.btn-create:disabled {
    background: #6c757d;
    cursor: not-allowed;
}

.section-title {
    font-size: 15px;
    font-weight: 600;
    color: #495057;
    margin: 0 0 12px 0;
}

.info-message {
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-size: 13px;
    background: #ffffff !important;
    border: none !important;
}

.info-message i {
    color: #6c757d;
    margin-right: 6px;
}
</style>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        <?php include(__DIR__ . '/includes/sidebar.php'); ?>

        <!-- Main Content -->
        <div class="teacher-main-content">
            <div class="quiz-creation-container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-header-content">
                        <a href="quizzes.php" class="back-button">
                            <i class="fa fa-arrow-left"></i>
                            Back to Quizzes
                        </a>
                        <div class="page-header-text">
                            <h1 class="page-title-header">Create New Quiz</h1>
                            <p class="page-subtitle">Create a quiz with questions from the question bank or create new questions</p>
                        </div>
                    </div>
                </div>

                <!-- Quiz Creation Form -->
                <form id="quizForm" class="creation-form" method="POST" action="create_quiz.php">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    
                    <!-- Tab Navigation -->
                    <div class="form-tabs">
                        <div class="tab-nav">
                            <button type="button" class="tab-button active" data-tab="general" onclick="showTab('general')">
                                <i class="fa fa-info-circle"></i>
                                General
                            </button>
                            <button type="button" class="tab-button" data-tab="questions" onclick="showTab('questions')">
                                <i class="fa fa-question-circle"></i>
                                Questions
                            </button>
                            <button type="button" class="tab-button" data-tab="grade" onclick="showTab('grade')">
                                <i class="fa fa-star"></i>
                                Grade
                            </button>
                            <button type="button" class="tab-button" data-tab="behavior" onclick="showTab('behavior')">
                                <i class="fa fa-cog"></i>
                                Behavior
                            </button>
                            <button type="button" class="tab-button" data-tab="competencies" onclick="showTab('competencies')">
                                <i class="fa fa-trophy"></i>
                                Competencies
                            </button>
                            <button type="button" class="tab-button" data-tab="assignto" onclick="showTab('assignto')">
                                <i class="fa fa-users"></i>
                                Assign to
                            </button>
                        </div>
                    </div>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        
                        <!-- General Tab -->
                        <div id="general-tab" class="tab-panel active">
                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-info-circle"></i>
                                    General Settings
                                </h2>

                                <!-- Course Selection -->
                                <div class="form-group">
                                    <label class="form-label required" for="quizCourse">Course</label>
                                    <select id="quizCourse" name="courseid" class="form-select" required onchange="handleCourseChanged(this); loadCourseStructure(); loadCompetencies(this.value); loadCourseGroups(this.value);">
                                        <option value="">Select a course...</option>
                                        <?php foreach ($teacher_courses as $course): ?>
                                            <option value="<?php echo $course->id; ?>"><?php echo format_string($course->fullname); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Course Structure Placement -->
                                <div class="course-structure-section">
                                    <div class="course-structure-title">Course Structure Placement</div>
                                    <div class="course-structure-tree">
                                        <div id="courseStructureTree">
                                            <div style="text-align: center; padding: 40px; color: #6c757d;">
                                                Please select a course first
                                            </div>
                                        </div>
                                    </div>
                                    <div id="selectionInfo" class="selection-info hidden">
                                        <strong>Selected:</strong> <span id="selectionText"></span>
                                    </div>
                                    <input type="hidden" id="selectedSection" name="section" value="">
                                </div>
                                
                                <div class="ai-meta-wrapper" id="quizMetaAiSection">
                                    <div class="ai-meta-header">
                                        <div class="context">
                                            <strong><i class="fa fa-robot"></i> AI Suggestions</strong>
                                            <span id="quizMetaContextLabel">Select a course placement to enable AI-powered quiz titles and descriptions.</span>
                                        </div>
                                        <button type="button" class="ai-meta-generate-btn" id="quizMetaGenerateBtn" onclick="requestQuizMetaSuggestions(true)" disabled>
                                            Refresh Suggestions
                                        </button>
                                    </div>
                                    <div class="ai-meta-body" id="quizMetaSuggestions">
                                        <div class="ai-meta-placeholder">
                                            Select a lesson or module to generate tailored suggestions.
                                        </div>
                                    </div>
                                </div>

                                <!-- Quiz Name -->
                                <div class="form-group">
                                    <label class="form-label required" for="quizName">Quiz name</label>
                                    <input type="text" id="quizName" name="name" class="form-input" required 
                                           placeholder="Enter quiz name...">
                                </div>

                                <!-- Description -->
                                <div class="form-group">
                                    <label class="form-label" for="quizDescription">Description</label>
                                    <textarea id="quizDescription" name="intro" class="form-textarea" 
                                              placeholder="Enter quiz description..."></textarea>
                                </div>
                            </div>

                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-calendar"></i>
                                    Timing
                                </h2>

                                <!-- Open the quiz -->
                                <div class="date-time-group">
                                    <div class="date-time-header">
                                        <label class="form-label">Open the quiz</label>
                                        <div class="enable-toggle">
                                            <input type="checkbox" id="enableTimeOpen" name="enable_time_open" onchange="toggleDateTime('timeOpen')">
                                            <label for="enableTimeOpen">Enable</label>
                                        </div>
                                    </div>
                                    <div id="timeOpenFields" class="date-time-fields">
                                        <div class="date-time-row">
                                            <div class="date-fields">
                                                <select name="open_day" class="form-select">
                                                    <option value="">Day</option>
                                                    <?php for($i = 1; $i <= 31; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select name="open_month" class="form-select">
                                                    <option value="">Month</option>
                                                    <?php 
                                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                                             'July', 'August', 'September', 'October', 'November', 'December'];
                                                    foreach($months as $index => $month): ?>
                                                        <option value="<?php echo $index + 1; ?>"><?php echo $month; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="open_year" class="form-select">
                                                    <option value="">Year</option>
                                                    <?php for($i = date('Y'); $i <= date('Y') + 5; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="time-fields">
                                                <select name="open_hour" class="form-select">
                                                    <option value="">Hour</option>
                                                    <?php for($i = 0; $i < 24; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select name="open_minute" class="form-select">
                                                    <option value="">Minute</option>
                                                    <?php for($i = 0; $i < 60; $i += 5): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Close the quiz -->
                                <div class="date-time-group">
                                    <div class="date-time-header">
                                        <label class="form-label">Close the quiz</label>
                                        <div class="enable-toggle">
                                            <input type="checkbox" id="enableTimeClose" name="enable_time_close" onchange="toggleDateTime('timeClose')">
                                            <label for="enableTimeClose">Enable</label>
                                        </div>
                                    </div>
                                    <div id="timeCloseFields" class="date-time-fields">
                                        <div class="date-time-row">
                                            <div class="date-fields">
                                                <select name="close_day" class="form-select">
                                                    <option value="">Day</option>
                                                    <?php for($i = 1; $i <= 31; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select name="close_month" class="form-select">
                                                    <option value="">Month</option>
                                                    <?php foreach($months as $index => $month): ?>
                                                        <option value="<?php echo $index + 1; ?>"><?php echo $month; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="close_year" class="form-select">
                                                    <option value="">Year</option>
                                                    <?php for($i = date('Y'); $i <= date('Y') + 5; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="time-fields">
                                                <select name="close_hour" class="form-select">
                                                    <option value="">Hour</option>
                                                    <?php for($i = 0; $i < 24; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select name="close_minute" class="form-select">
                                                    <option value="">Minute</option>
                                                    <?php for($i = 0; $i < 60; $i += 5): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Time Limit -->
                                <div class="form-group">
                                    <label class="form-label" for="timeLimit">Time limit (minutes)</label>
                                    <input type="number" id="timeLimit" name="timelimit" class="form-input" 
                                           placeholder="0 = no time limit" min="0" value="0">
                                    <small class="form-text">Leave as 0 for no time limit</small>
                                </div>
                            </div>
                        </div>

                        <!-- Questions Tab -->
                        <div id="questions-tab" class="tab-panel">
                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-question-circle"></i>
                                    Manage Questions
                                </h2>

                                <!-- Mode Toggle -->
                                <div class="question-modes">
<?php if ($isquizeditmode): ?>
                                    <button type="button" class="mode-toggle-btn active" data-mode="existing" onclick="switchQuestionMode('existing', this, event)">
                                        <i class="fa fa-list-ol"></i>
                                        Existing Questions
                                    </button>
<?php endif; ?>
                                    <button type="button" class="mode-toggle-btn" data-mode="import" onclick="switchQuestionMode('import', this, event)">
                                        <i class="fa fa-download"></i>
                                        Import from Question Bank
                                    </button>
                                    <button type="button" class="mode-toggle-btn<?php echo $isquizeditmode ? '' : ' active'; ?>" data-mode="create" onclick="switchQuestionMode('create', this, event)">
                                        <i class="fa fa-plus-circle"></i>
                                        Create New Question
                                    </button>
                                </div>

<?php if ($isquizeditmode): ?>
                                <!-- Existing Questions Mode -->
                                <div id="existing-mode" class="question-mode-content active">
                                    <?php remui_kids_render_quiz_questions_panel(); ?>
                                </div>
<?php endif; ?>

                                <!-- Import Mode -->
                                <div id="import-mode" class="question-mode-content">
                                        <div class="import-filters">
                                            <div class="filter-group">
                                                <label class="form-label">Category</label>
                                                <select id="questionCategory" class="form-select" onchange="loadQuestionBank()">
                                                    <option value="">Select course first...</option>
                                                </select>
                                            </div>
                                            <div class="filter-group">
                                                <label class="form-label">Question Type</label>
                                                <select id="questionTypeFilter" class="form-select" onchange="filterQuestionsByType()">
                                                    <option value="">All Types</option>
                                                    <option value="multichoice">Multiple Choice</option>
                                                    <option value="truefalse">True/False</option>
                                                    <option value="shortanswer">Short Answer</option>
                                                    <option value="essay">Essay</option>
                                                    <option value="numerical">Numerical</option>
                                                    <option value="match">Matching</option>
                                                    <option value="description">Description</option>
                                                </select>
                                            </div>
                                            <div class="filter-group">
                                                <label class="form-label">Search</label>
                                                <input type="text" id="questionSearch" class="form-input" 
                                                       placeholder="Search questions..." onkeyup="searchQuestions()">
                                            </div>
                                        </div>

                                        <div id="questionBankList" class="question-list">
                                            <div style="text-align: center; padding: 40px; color: #6c757d;">
                                                Select a course and category to view questions
                                            </div>
                                        </div>

                                        <div style="margin-top: 16px; text-align: right;">
                                            <button type="button" class="btn-submit" onclick="addSelectedQuestions()">
                                                <i class="fa fa-plus"></i>
                                                Add Selected Questions
                                            </button>
                                        </div>
                                        <div class="quiz-questions-review" style="margin-top: 32px;">
                                            <?php remui_kids_render_quiz_questions_panel(); ?>
                                        </div>
                                    </div>

                                    <!-- Create Mode -->
                                    <div id="create-mode" class="question-mode-content<?php echo $isquizeditmode ? '' : ' active'; ?>">
                                        <div class="question-type-selector" id="questionTypeSelector">
                                            <!-- Question types will be populated here -->
                                        </div>

                                        <div class="ai-generator-block" id="aiQuestionGenerator" style="display: none;">
                                            <div class="ai-generator-header">
                                                <div class="ai-generator-title">
                                                    <i class="fa fa-robot"></i>
                                                    <span>AI Question Assistant</span>
                                                </div>
                                                <p>Enter a topic and optionally adjust the difficulty. The AI will draft question text and answers for you.</p>
                                            </div>
                                            <div class="ai-generator-inputs">
                                            <div class="ai-input-group">
                                                <label>Topic or learning objective</label>
                                                <div class="ai-topic-input-wrapper">
                                                    <input type="text" class="form-input" id="aiTopicInput" placeholder="e.g., Photosynthesis energy conversion">
                                                    <button type="button" class="ai-topic-reset-btn" onclick="resetAiTopicToQuizName()" title="Use quiz name">
                                                        <i class="fa fa-sync"></i>
                                                        Use quiz name
                                                    </button>
                                                </div>
                                            </div>
                                                <div class="ai-input-group">
                                                    <label>Difficulty</label>
                                                    <select class="form-select" id="aiDifficulty">
                                                        <option value="introductory">Introductory</option>
                                                        <option value="balanced" selected>Balanced</option>
                                                        <option value="advanced">Advanced</option>
                                                        <option value="enrichment">Enrichment / Extension</option>
                                                    </select>
                                                </div>
                                                <div class="ai-input-group">
                                                    <label>Number of questions</label>
                                                    <input type="number" class="form-input" id="aiQuestionCount" min="1" value="1">
                                                    <small class="form-text">Leave blank for 1 question.</small>
                                                </div>
                                                <div class="ai-input-group" id="aiChoiceCountGroup">
                                                    <label>Number of answer options</label>
                                                    <select class="form-select" id="aiChoiceCount">
                                                        <option value="3">3</option>
                                                        <option value="4" selected>4</option>
                                                        <option value="5">5</option>
                                                        <option value="6">6</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="ai-generator-actions">
                                                <button type="button" class="btn-ai-generate" onclick="generateQuestionWithAI()">
                                                    <i class="fa fa-robot"></i>
                                                    Generate with AI
                                                </button>
                                                <span class="ai-generator-status" id="aiGeneratorStatus"></span>
                                            </div>
                                        </div>

                                        <div id="questionBuilderForm" class="question-builder-form">
                                            <!-- Dynamic form based on selected question type -->
                                        </div>
                                        <div class="quiz-questions-review">
                                            <?php remui_kids_render_quiz_questions_panel(); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- Grade Tab -->


                        <!-- Grade Tab -->
                        <div id="grade-tab" class="tab-panel">
                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-star"></i>
                                    Grade Settings
                                </h2>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="gradeMethod">Grade calculation method</label>
                                        <select id="gradeMethod" name="grademethod" class="form-select">
                                            <option value="1" selected>Highest grade</option>
                                            <option value="2">Average grade</option>
                                            <option value="3">First attempt</option>
                                            <option value="4">Last attempt</option>
                                        </select>
                                        <small class="form-text">When multiple attempts are allowed, which grade counts?</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="maxGrade">Maximum grade</label>
                                        <input type="number" id="maxGrade" name="grade" class="form-input" 
                                               value="100" min="0" step="0.01">
                                        <small class="form-text">Grade to scale the final score to</small>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="decimalPoints">Decimal places in grades</label>
                                        <select id="decimalPoints" name="decimalpoints" class="form-select">
                                            <option value="0">0</option>
                                            <option value="1">1</option>
                                            <option value="2" selected>2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="questionDecimalPoints">Decimal places in question grades</label>
                                        <select id="questionDecimalPoints" name="questiondecimalpoints" class="form-select">
                                            <option value="-1" selected>Same as overall</option>
                                            <option value="0">0</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="attempts">Number of attempts allowed</label>
                                    <input type="number" id="attempts" name="attempts" class="form-input" 
                                           value="0" min="0">
                                    <small class="form-text">0 = unlimited attempts</small>
                                </div>
                            </div>
                        </div>

                        <!-- Behavior Tab -->
                        <div id="behavior-tab" class="tab-panel">
                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-cog"></i>
                                    Question Behavior
                                </h2>

                                <div class="form-group">
                                    <label class="form-label" for="preferredBehaviour">How questions behave</label>
                                    <select id="preferredBehaviour" name="preferredbehaviour" class="form-select">
                                        <option value="deferredfeedback" selected>Deferred feedback</option>
                                        <option value="immediatefeedback">Immediate feedback</option>
                                        <option value="interactive">Interactive with multiple tries</option>
                                        <option value="adaptive">Adaptive mode</option>
                                        <option value="deferredcbm">Deferred feedback with CBM</option>
                                        <option value="immediatecbm">Immediate feedback with CBM</option>
                                    </select>
                                    <small class="form-text">Controls when and how feedback is shown to students</small>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">
                                            <input type="checkbox" name="shuffleanswers" value="1" checked style="margin-right: 8px;">
                                            Shuffle answers
                                        </label>
                                        <small class="form-text">Randomly shuffle answer options in questions</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="navMethod">Navigation method</label>
                                        <select id="navMethod" name="navmethod" class="form-select">
                                            <option value="free" selected>Free - questions may be answered in any order</option>
                                            <option value="seq">Sequential - questions must be answered in order</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="questionsPerPage">Questions per page</label>
                                    <input type="number" id="questionsPerPage" name="questionsperpage" 
                                           class="form-input" value="1" min="0">
                                    <small class="form-text">0 = all questions on one page</small>
                                </div>
                            </div>

                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-eye"></i>
                                    Review Options
                                </h2>

                                <p style="color: #6c757d; margin-bottom: 20px;">
                                    Control what information students can see when they review their attempts
                                </p>

                                <div class="review-options-grid">
                                    <div class="review-option-label"></div>
                                    <div class="review-option-header">During attempt</div>
                                    <div class="review-option-header">Immediately after</div>
                                    <div class="review-option-header">Later, while open</div>
                                    <div class="review-option-header">After close</div>

                                    <!-- The attempt -->
                                    <div class="review-option-label">The attempt</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewattempt_during" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewattempt_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewattempt_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewattempt_closed" value="1" checked></div>

                                    <!-- Whether correct -->
                                    <div class="review-option-label">Whether correct</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewcorrectness_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewcorrectness_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewcorrectness_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewcorrectness_closed" value="1" checked></div>

                                    <!-- Marks -->
                                    <div class="review-option-label">Marks</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewmarks_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewmarks_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewmarks_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewmarks_closed" value="1" checked></div>

                                    <!-- Specific feedback -->
                                    <div class="review-option-label">Specific feedback</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewspecificfeedback_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewspecificfeedback_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewspecificfeedback_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewspecificfeedback_closed" value="1" checked></div>

                                    <!-- General feedback -->
                                    <div class="review-option-label">General feedback</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewgeneralfeedback_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewgeneralfeedback_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewgeneralfeedback_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewgeneralfeedback_closed" value="1" checked></div>

                                    <!-- Right answer -->
                                    <div class="review-option-label">Right answer</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewrightanswer_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewrightanswer_immediate" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewrightanswer_open" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewrightanswer_closed" value="1" checked></div>

                                    <!-- Overall feedback -->
                                    <div class="review-option-label">Overall feedback</div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewoverallfeedback_during" value="1"></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewoverallfeedback_immediate" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewoverallfeedback_open" value="1" checked></div>
                                    <div class="review-option-cell"><input type="checkbox" name="reviewoverallfeedback_closed" value="1" checked></div>
                                </div>
                            </div>
                        </div>

                        <!-- Competencies Tab -->
                        <div id="competencies-tab" class="tab-panel">
                            <div class="form-section">
                                <h2 class="form-section-title">
                                    <i class="fa fa-trophy"></i>
                                    Competencies
                                </h2>
                                
                                <!-- Competency Search Bar -->
                                <div class="competency-search-container" id="competencySearchContainer" style="display: none;">
                                    <div class="search-input-wrapper">
                                        <i class="fa fa-search search-icon"></i>
                                        <input type="text" 
                                               id="competencySearchInput" 
                                               class="competency-search-input" 
                                               placeholder="Search competencies by name, ID, or description..."
                                               autocomplete="off">
                                        <button type="button" id="clearSearchBtn" class="clear-search-btn" style="display: none;">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div id="competenciesContainer" class="competencies-container">
                                    <div id="competenciesLoading" class="loading" style="text-align: center; padding: 40px; color: #6c757d;">
                                        Select a course first to view competencies
                                    </div>
                                    <div id="competenciesList" class="competencies-list" style="display: none;">
                                        <!-- Competencies will be loaded here -->
                                    </div>
                                    <div id="noCompetencies" style="display: none; text-align: center; padding: 20px; color: #6c757d;">
                                        No competencies are linked to this course yet.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Assign to Tab -->
                        <div id="assignto-tab" class="tab-panel">
                            <div class="form-section">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                                    <h2 class="form-section-title" style="margin: 0;">
                                        <i class="fa fa-users"></i>
                                        Assign to
                                    </h2>
                                    <button type="button" class="btn-create-group" onclick="openCreateGroupModal()">
                                        <i class="fa fa-plus"></i> Create New Group
                                    </button>
                                </div>
                                
                                <div class="assign-to-options">
                                    <div class="radio-item">
                                        <input type="radio" id="assignToAll" name="assign_to" value="all" checked onchange="toggleGroupSelection()">
                                        <label for="assignToAll">
                                            <strong>All students in the course</strong>
                                            <small>Everyone will see and can submit this quiz</small>
                                        </label>
                                    </div>
                                    <div class="radio-item">
                                        <input type="radio" id="assignToGroups" name="assign_to" value="groups" onchange="toggleGroupSelection()">
                                        <label for="assignToGroups">
                                            <strong>Specific groups only</strong>
                                            <small>Only students in selected groups will see this quiz</small>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Group Selection (hidden by default) -->
                                <div id="groupSelectionContainer" class="group-selection-container" style="display: none; margin-top: 24px;">
                                    <h3 class="section-title">Select Groups</h3>
                                    <div class="loading" id="groupsLoading">
                                        <i class="fa fa-spinner fa-spin"></i> Loading groups... Select a course first if you haven't already.
                                    </div>
                                    <div id="groupsList" class="groups-list" style="display: none;">
                                        <!-- Groups will be loaded here dynamically -->
                                    </div>
                                    <div id="noGroups" class="info-message" style="display: none;">
                                        <i class="fa fa-info-circle"></i>
                                        No groups found. Click "Create New Group" above.
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="quizzes.php" class="btn-cancel" id="cancelBtn">
                            <i class="fa fa-times"></i>
                            Cancel
                        </a>
                        <button type="button" class="btn-submit" onclick="goToNextTab()" id="nextBtn" style="display: none;">
                            <i class="fa fa-arrow-right"></i>
                            Next
                        </button>
                        <button type="button" class="btn-submit" onclick="createQuiz()" id="createBtn" style="display: none;">
                            <i class="fa fa-plus"></i>
                            Create Quiz
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let quizQuestions = []; // Array to store added questions
let questionCounter = 0;
const aiDefaultDifficulty = '<?php echo addslashes($defaultaidifficulty); ?>';
const aiQuizGeneratorEnabled = <?php echo $aiquizgeneratorenabled ? 'true' : 'false'; ?>;
const aiMaxQuestions = Infinity;
let currentQuestionType = null;
let aiGeneratedQueue = [];
let aiGeneratedType = null;
let aiGeneratedTotal = 0;
let aiGeneratedIndex = 0;
let aiGeneratedAccepted = 0;
let aiGeneratedCurrentSuggestion = null;
let editingQuestionIndex = null;
const isQuizEditMode = typeof window !== 'undefined' && !!window.isQuizEditMode;
const quizEditData = typeof window !== 'undefined' && window.quizEditData ? window.quizEditData : null;
const REVIEW_OPTION_BITS = { during: 0x10000, immediate: 0x01, open: 0x100, closed: 0x1000 };
const CURRENT_SESSKEY = document.querySelector('input[name="sesskey"]')?.value || '';
let currentPlacementContext = null;
let quizMetaSuggestions = [];
let quizMetaSuggestionsLoading = false;
let quizMetaRequestToken = 0;
let quizMetaAutoTimer = null;
let aiTopicManualOverride = false;
let aiTopicSuppressListener = false;
let gapselectGroupCount = 0;
// Context values resolve lazily so we still work before quizEditData is ready.
const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
let CURRENT_CMID = null;
let CURRENT_QUIZ_ID = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (!CURRENT_CMID) {
        if (quizEditData && quizEditData.cmid) {
            CURRENT_CMID = parseInt(quizEditData.cmid, 10);
        } else if (urlParams?.get('cmid')) {
            CURRENT_CMID = parseInt(urlParams.get('cmid'), 10) || null;
        } else {
            const cmInput = document.querySelector('input[name="cmid"]');
            CURRENT_CMID = cmInput ? parseInt(cmInput.value, 10) : null;
        }
    }
    if (!CURRENT_QUIZ_ID) {
        if (quizEditData && quizEditData.quizid) {
            CURRENT_QUIZ_ID = parseInt(quizEditData.quizid, 10);
        } else if (urlParams?.get('quizid')) {
            CURRENT_QUIZ_ID = parseInt(urlParams.get('quizid'), 10) || null;
        } else {
            const quizInput = document.querySelector('input[name="quiz_id"]');
            CURRENT_QUIZ_ID = quizInput ? parseInt(quizInput.value, 10) : null;
        }
    }
    initializeQuestionTypes();
    // Initialize form action buttons for the default tab (general)
    updateFormActions('general');
    if (isQuizEditMode) {
        initializeQuizEditMode();
    }
    initializeAiTopicAutofill();
});

// Toggle sidebar
function toggleTeacherSidebar() {
    const sidebar = document.getElementById('teacherSidebar');
    sidebar.classList.toggle('open');
}

// Tab order for navigation
const tabOrder = ['general', 'questions', 'grade', 'behavior', 'competencies', 'assignto'];

// Tab switching
function showTab(tabName) {
    // Hide all tab panels
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab panel
    const selectedPanel = document.getElementById(`${tabName}-tab`);
    if (selectedPanel) {
        selectedPanel.classList.add('active');
    }
    
    // Add active class to the corresponding tab button
    const tabButton = document.querySelector(`.tab-button[data-tab="${tabName}"]`);
    if (tabButton) {
        tabButton.classList.add('active');
    }
    
    // Update form action buttons visibility
    updateFormActions(tabName);
}


// Update form action buttons based on current tab
function updateFormActions(currentTab) {
    const nextBtn = document.getElementById('nextBtn');
    const createBtn = document.getElementById('createBtn');
    
    if (currentTab === 'assignto') {
        // Last tab: show Create Quiz, hide Next
        if (nextBtn) nextBtn.style.display = 'none';
        if (createBtn) createBtn.style.display = 'inline-flex';
    } else {
        // Other tabs: show Next, hide Create Quiz
        if (nextBtn) nextBtn.style.display = 'inline-flex';
        if (createBtn) createBtn.style.display = 'none';
    }
}

// Navigate to next tab
function goToNextTab() {
    const currentTabIndex = tabOrder.findIndex(tab => {
        const panel = document.getElementById(`${tab}-tab`);
        return panel && panel.classList.contains('active');
    });
    
    if (currentTabIndex >= 0 && currentTabIndex < tabOrder.length - 1) {
        const nextTab = tabOrder[currentTabIndex + 1];
        showTab(nextTab);
    }
}

// Toggle date/time fields
function toggleDateTime(type) {
    const checkbox = document.getElementById(`enable${type.charAt(0).toUpperCase() + type.slice(1)}`);
    const fields = document.getElementById(`${type}Fields`);
    
    if (checkbox.checked) {
        fields.classList.add('show');
    } else {
        fields.classList.remove('show');
    }
}

// Load course structure
function loadCourseStructure() {
    const courseId = document.getElementById('quizCourse').value;
    const treeContainer = document.getElementById('courseStructureTree');
    
    if (!courseId) {
        treeContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;">Please select a course first</div>';
        resetQuizMetaSuggestions('Select a course to enable AI suggestions.');
        return;
    }
    
    resetQuizMetaSuggestions('Select a lesson or module to generate tailored suggestions.');
    treeContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch('get_course_structure.php?courseid=' + courseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderCourseTree(data.structure);
                loadQuestionCategories(courseId);
            } else {
                treeContainer.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading course structure</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            treeContainer.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading course structure</div>';
        });
}

function handleCourseChanged(selectEl) {
    currentPlacementContext = null;
    resetQuizMetaSuggestions('Select a lesson or module to generate tailored suggestions.');
    updateQuizMetaContextLabel();
}

function getSelectedCourseInfo() {
    const select = document.getElementById('quizCourse');
    if (!select) {
        return { id: null, name: '' };
    }
    const id = parseInt(select.value, 10);
    const name = id && select.selectedIndex >= 0
        ? select.options[select.selectedIndex].text.trim()
        : '';
    return { id: id || null, name };
}

function resetQuizMetaSuggestions(message) {
    quizMetaSuggestions = [];
    const container = document.getElementById('quizMetaSuggestions');
    if (container) {
        container.classList.remove('loading');
        container.innerHTML = `<div class="ai-meta-placeholder">${message || 'Select a lesson or module to generate tailored suggestions.'}</div>`;
    }
    quizMetaSuggestionsLoading = false;
    updateQuizMetaContextLabel();
}

function updateQuizMetaContextLabel(messageOverride) {
    const label = document.getElementById('quizMetaContextLabel');
    const button = document.getElementById('quizMetaGenerateBtn');
    const courseInfo = getSelectedCourseInfo();
    const hasContext = !!(currentPlacementContext && courseInfo.id);

    if (label) {
        if (messageOverride) {
            label.textContent = messageOverride;
        } else if (hasContext) {
            const locationLabel = currentPlacementContext.displayName || currentPlacementContext.name;
            label.textContent = `Suggestions tailored to ${locationLabel} in ${courseInfo.name}.`;
        } else {
            label.textContent = 'Select a course placement to enable AI-powered quiz titles and descriptions.';
        }
    }

    if (button) {
        button.disabled = !hasContext || quizMetaSuggestionsLoading;
        if (quizMetaSuggestionsLoading) {
            button.textContent = 'Generating...';
        } else {
            button.textContent = 'Refresh Suggestions';
        }
    }
}

function setQuizMetaLoading(isLoading) {
    quizMetaSuggestionsLoading = isLoading;
    const container = document.getElementById('quizMetaSuggestions');
    if (container) {
        container.classList.toggle('loading', isLoading);
    }
    updateQuizMetaContextLabel();
}

function scheduleQuizMetaSuggestions() {
    if (!currentPlacementContext || isQuizEditMode) {
        return;
    }
    clearTimeout(quizMetaAutoTimer);
    quizMetaAutoTimer = setTimeout(() => {
        requestQuizMetaSuggestions(false);
    }, 600);
}

function requestQuizMetaSuggestions(force = false) {
    const courseInfo = getSelectedCourseInfo();
    if (!courseInfo.id || !currentPlacementContext) {
        resetQuizMetaSuggestions('Select a lesson or module to generate tailored suggestions.');
        return;
    }

    if (quizMetaSuggestionsLoading && !force) {
        return;
    }

    if (!CURRENT_SESSKEY) {
        showNotification('Missing session key. Please refresh and try again.', 'error');
        return;
    }

    const token = ++quizMetaRequestToken;
    setQuizMetaLoading(true);

    const payload = new URLSearchParams();
    payload.append('sesskey', CURRENT_SESSKEY);
    payload.append('courseid', courseInfo.id);
    payload.append('coursename', courseInfo.name);
    payload.append('placementname', currentPlacementContext.name || '');
    payload.append('placementpath', currentPlacementContext.displayName || '');
    payload.append('placementtype', currentPlacementContext.type || '');
    payload.append('sectionid', currentPlacementContext.sectionId || 0);
    payload.append('moduleid', currentPlacementContext.moduleId || 0);
    payload.append('existingname', document.getElementById('quizName')?.value || '');
    payload.append('existingdescription', document.getElementById('quizDescription')?.value || '');
    payload.append('count', 3);

    fetch('get_quiz_meta_suggestions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: payload.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (token !== quizMetaRequestToken) {
                return;
            }
            setQuizMetaLoading(false);

            if (data.success && Array.isArray(data.suggestions)) {
                quizMetaSuggestions = data.suggestions;
                renderQuizMetaSuggestions(quizMetaSuggestions);
            } else {
                const message = data.message || 'Unable to generate suggestions.';
                resetQuizMetaSuggestions(message);
                showNotification(message, 'error');
            }
        })
        .catch(error => {
            if (token !== quizMetaRequestToken) {
                return;
            }
            setQuizMetaLoading(false);
            resetQuizMetaSuggestions('Unable to reach AI service. Please try again.');
            showNotification('Unable to generate AI suggestions: ' + error.message, 'error');
        });
}

function renderQuizMetaSuggestions(items) {
    const container = document.getElementById('quizMetaSuggestions');
    if (!container) {
        return;
    }
    container.classList.remove('loading');

    if (!items || items.length === 0) {
        container.innerHTML = '<div class="ai-meta-placeholder">No suggestions available yet. Try refreshing.</div>';
        return;
    }

    container.innerHTML = '';
    items.forEach((item, index) => {
        const card = document.createElement('div');
        card.className = 'ai-meta-card';

        const titleEl = document.createElement('h4');
        titleEl.textContent = item.title || 'Suggested Quiz Title';
        card.appendChild(titleEl);

        const descEl = document.createElement('p');
        descEl.textContent = item.description || '';
        card.appendChild(descEl);

        if (item.tone || item.focus) {
            const meta = document.createElement('div');
            meta.className = 'meta-tags';
            meta.textContent = [item.tone, item.focus].filter(Boolean).join(' • ');
            card.appendChild(meta);
        }

        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'apply-btn';
        applyBtn.textContent = 'Use this';
        applyBtn.onclick = () => applyQuizMetaSuggestion(index);
        card.appendChild(applyBtn);

        container.appendChild(card);
    });
}

function applyQuizMetaSuggestion(index) {
    const suggestion = quizMetaSuggestions[index];
    if (!suggestion) {
        return;
    }
    const nameInput = document.getElementById('quizName');
    const descInput = document.getElementById('quizDescription');

    if (nameInput && suggestion.title) {
        nameInput.value = suggestion.title;
        nameInput.dispatchEvent(new Event('input'));
    }
    if (descInput) {
        descInput.value = suggestion.description || '';
        descInput.dispatchEvent(new Event('input'));
    }
    showNotification('AI suggestion applied to the quiz.', 'success');
}

function setPlacementContext(context, options = {}) {
    currentPlacementContext = context;
    updateQuizMetaContextLabel();

    if (options.skipSuggestions) {
        return;
    }

    if (isQuizEditMode) {
        resetQuizMetaSuggestions('Click "Refresh Suggestions" to load AI suggestions for this placement.');
    } else {
        scheduleQuizMetaSuggestions();
    }
}

function initializeAiTopicAutofill() {
    const quizNameInput = document.getElementById('quizName');
    const aiTopicInput = document.getElementById('aiTopicInput');
    if (!quizNameInput || !aiTopicInput) {
        return;
    }

    quizNameInput.addEventListener('input', () => applyAiTopicFromQuizName());

    aiTopicInput.addEventListener('input', () => {
        if (aiTopicSuppressListener) {
            return;
        }
        aiTopicManualOverride = true;
    });

    applyAiTopicFromQuizName(true);
}

function applyAiTopicFromQuizName(force = false) {
    if (aiTopicManualOverride && !force) {
        return;
    }
    const quizNameInput = document.getElementById('quizName');
    const aiTopicInput = document.getElementById('aiTopicInput');
    if (!quizNameInput || !aiTopicInput) {
        return;
    }
    aiTopicSuppressListener = true;
    aiTopicInput.value = quizNameInput.value || '';
    aiTopicSuppressListener = false;
}

function resetAiTopicToQuizName() {
    aiTopicManualOverride = false;
    applyAiTopicFromQuizName(true);
    const aiTopicInput = document.getElementById('aiTopicInput');
    if (aiTopicInput) {
        aiTopicInput.focus();
        aiTopicInput.select();
    }
}

// Render course tree
function renderCourseTree(structure) {
    const container = document.getElementById('courseStructureTree');
    container.innerHTML = '';
    
    const tree = document.createElement('ul');
    tree.className = 'course-tree';
    
    structure.forEach(section => {
        const sectionItem = createTreeItem(section.name, '📁', section.id, 'section');
        const children = document.createElement('ul');
        children.className = 'tree-children';
        
        if (section.modules && section.modules.length > 0) {
            section.modules.forEach(module => {
                // Use section_id from module if available, otherwise use section.id
                const sectionId = module.section_id || section.id;
                console.log('Rendering module:', module.name, 'Module ID:', module.id, 'Section ID:', sectionId);
                const moduleItem = createTreeItem(module.name, '📂', module.id, 'module', sectionId);
                children.appendChild(moduleItem);
            });
        } else {
            const noModules = document.createElement('li');
            noModules.innerHTML = '<div class="tree-item disabled"><span class="tree-icon">📁</span><span class="tree-label">No modules in this lesson</span></div>';
            children.appendChild(noModules);
        }
        
        sectionItem.appendChild(children);
        tree.appendChild(sectionItem);
    });
    
    container.appendChild(tree);
    
    restorePlacementSelection();
}

// Create tree item
function createTreeItem(name, icon, id, type, parentId = null) {
    const li = document.createElement('li');
    const item = document.createElement('div');
    item.className = 'tree-item';
    item.setAttribute('data-id', id);
    item.setAttribute('data-type', type);
    if (parentId) item.setAttribute('data-parent', parentId);
    
    item.innerHTML = `
        <span class="tree-icon">${icon}</span>
        <span class="tree-label">${name}</span>
        <button class="tree-toggle" onclick="toggleTreeItem(this)">▼</button>
    `;
    
    item.addEventListener('click', function(e) {
        if (e.target.classList.contains('tree-toggle')) return;
        selectTreeItem(this);
    });
    
    li.appendChild(item);
    return li;
}

// Toggle tree item (expand/collapse)
function toggleTreeItem(button) {
    const item = button.closest('.tree-item');
    const children = item.parentElement.querySelector('.tree-children');
    if (children) {
        children.style.display = children.style.display === 'none' ? 'block' : 'none';
        button.textContent = button.textContent === '▼' ? '▶' : '▼';
    }
}

// Select tree item
function selectTreeItem(element) {
    // Remove previous selection
    document.querySelectorAll('.tree-item.selected').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Add selection to clicked item
    element.classList.add('selected');
    
    const type = element.getAttribute('data-type');
    const id = element.getAttribute('data-id');
    const parentId = element.getAttribute('data-parent');
    
    // Update hidden fields
    const label = element.querySelector('.tree-label')?.textContent?.trim() || '';
    if (type === 'section') {
        document.getElementById('selectedSection').value = id;
        console.log('Section selected - ID:', id);
        updateSelectionInfo(label, 'lesson');
        setPlacementContext({
            type,
            id: parseInt(id, 10) || null,
            sectionId: parseInt(id, 10) || null,
            moduleId: null,
            name: label,
            displayName: label
        });
    } else if (type === 'module') {
        document.getElementById('selectedSection').value = parentId;
        console.log('Module selected - Module ID:', id, 'Parent Section ID:', parentId);
        const sectionName = element.closest('.tree-children').previousElementSibling.querySelector('.tree-label').textContent;
        const displayName = `${sectionName} > ${label}`;
        updateSelectionInfo(displayName, 'module');
        setPlacementContext({
            type,
            id: parseInt(id, 10) || null,
            sectionId: parseInt(parentId, 10) || null,
            moduleId: parseInt(id, 10) || null,
            name: label,
            parentName: sectionName,
            displayName
        });
    }
}

function restorePlacementSelection() {
    const treeContainer = document.getElementById('courseStructureTree');
    if (!treeContainer) {
        return;
    }
    const storedSectionField = document.getElementById('selectedSection');
    const storedSectionId = storedSectionField?.value;
    let target = null;

    if (storedSectionId) {
        target = treeContainer.querySelector(`.tree-item[data-type="section"][data-id="${storedSectionId}"]`);
    }

    if (!target && typeof CURRENT_CMID === 'number' && !isNaN(CURRENT_CMID)) {
        target = treeContainer.querySelector(`.tree-item[data-type="module"][data-id="${CURRENT_CMID}"]`);
    }

    if (!target && storedSectionId) {
        target = treeContainer.querySelector(`.tree-item[data-id="${storedSectionId}"]`);
    }

    if (target) {
        selectTreeItem(target);
        ensureTreeItemVisible(target);
    } else if (isQuizEditMode && storedSectionId) {
        document.getElementById('selectedSection').value = storedSectionId;
        if (quizEditData?.sectionname) {
            updateSelectionInfo(quizEditData.sectionname, 'lesson');
        }
    }
}

function ensureTreeItemVisible(element) {
    const treePanel = document.querySelector('.course-structure-tree');
    if (!treePanel || typeof element.scrollIntoView !== 'function') {
        return;
    }
    const panelRect = treePanel.getBoundingClientRect();
    const itemRect = element.getBoundingClientRect();
    if (itemRect.top < panelRect.top || itemRect.bottom > panelRect.bottom) {
        element.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
}

// Update selection info display
function updateSelectionInfo(text, type) {
    const selectionInfo = document.getElementById('selectionInfo');
    const selectionText = document.getElementById('selectionText');
    
    // Get the selected section ID
    const sectionId = document.getElementById('selectedSection').value;
    
    // Display text with section ID
    selectionText.textContent = text + ' (Section ID: ' + sectionId + ')';
    selectionInfo.classList.remove('hidden');
}

// Switch question mode
function switchQuestionMode(mode, buttonEl = null, evt = null) {
    document.querySelectorAll('.mode-toggle-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    let targetBtn = buttonEl;
    if (!targetBtn && evt && evt.currentTarget) {
        targetBtn = evt.currentTarget;
    } else if (!targetBtn && typeof event !== 'undefined' && event.currentTarget) {
        targetBtn = event.currentTarget;
    }
    if (!targetBtn) {
        targetBtn = document.querySelector(`.mode-toggle-btn[data-mode="${mode}"]`);
    }
    if (targetBtn) {
        targetBtn.classList.add('active');
    }
    
    document.querySelectorAll('.question-mode-content').forEach(content => {
        content.classList.remove('active');
    });
    
    if (mode === 'existing') {
        document.getElementById('existing-mode').classList.add('active');
    } else if (mode === 'import') {
        document.getElementById('import-mode').classList.add('active');
    } else {
        document.getElementById('create-mode').classList.add('active');
    }

    if (mode === 'create') {
        setAiGeneratorVisibility(!!currentQuestionType);
    } else {
        setAiGeneratorVisibility(false);
    }
}

// Initialize question types
function initializeQuestionTypes() {
    const questionTypes = [
        // Basic Types
        {id: 'multichoice', name: 'Multiple Choice', icon: 'fa-list-ul'},
        {id: 'truefalse', name: 'True/False', icon: 'fa-check-circle'},
        {id: 'shortanswer', name: 'Short Answer', icon: 'fa-font'},
        {id: 'essay', name: 'Essay', icon: 'fa-align-left'},
        {id: 'numerical', name: 'Numerical', icon: 'fa-calculator'},
        {id: 'match', name: 'Matching', icon: 'fa-exchange-alt'},
        {id: 'description', name: 'Description', icon: 'fa-info-circle'},
        {id: 'multianswer', name: 'Cloze', icon: 'fa-fill-drip'},
        // Advanced Types
        {id: 'ddwtos', name: 'Drag-Drop Words', icon: 'fa-mouse-pointer'},
        {id: 'ddimageortext', name: 'Drag-Drop Image', icon: 'fa-images'},
        {id: 'ddmarker', name: 'Drag-Drop Markers', icon: 'fa-map-marker-alt'},
        {id: 'gapselect', name: 'Select Missing Words', icon: 'fa-grip-lines'},
        {id: 'calculated', name: 'Calculated', icon: 'fa-square-root-alt'},
        {id: 'calculatedsimple', name: 'Calculated Simple', icon: 'fa-calculator'},
        {id: 'ordering', name: 'Ordering', icon: 'fa-sort'}
    ];
    
    const selector = document.getElementById('questionTypeSelector');
    selector.innerHTML = '';
    
    questionTypes.forEach(type => {
        const card = document.createElement('div');
        card.className = 'question-type-card';
        card.dataset.type = type.id;
        card.innerHTML = `
            <i class="fa ${type.icon}"></i>
            <div class="question-type-name">${type.name}</div>
        `;
        card.onclick = (evt) => selectQuestionType(type.id, card, evt);
        selector.appendChild(card);
    });
}

// Select question type
function selectQuestionType(typeId, cardElement = null, evt = null) {
    document.querySelectorAll('.question-type-card').forEach(card => {
        card.classList.remove('selected');
    });

    let targetCard = cardElement;
    if (!targetCard && evt && evt.currentTarget) {
        targetCard = evt.currentTarget;
    } else if (!targetCard && typeof event !== 'undefined' && event.currentTarget) {
        targetCard = event.currentTarget;
    }
    if (!targetCard) {
        targetCard = document.querySelector(`.question-type-card[data-type="${typeId}"]`);
    }
    if (targetCard) {
        targetCard.classList.add('selected');
    }

    if (aiGeneratedQueue.length > 0 && aiGeneratedType && aiGeneratedType !== typeId) {
        clearAiQueue(null);
    }

    switchQuestionMode('create');
    setAiGeneratorVisibility(true);
    showQuestionBuilder(typeId);
}

// Show question builder form
function showQuestionBuilder(typeId) {
    currentQuestionType = typeId;
    const builderForm = document.getElementById('questionBuilderForm');
    if (builderForm) {
        builderForm.classList.add('active');
    }
    
    const typeNames = {
        'multichoice': 'Multiple Choice',
        'truefalse': 'True/False',
        'shortanswer': 'Short Answer',
        'essay': 'Essay',
        'numerical': 'Numerical',
        'match': 'Matching',
        'description': 'Description',
        'multianswer': 'Cloze (Embedded Answers)',
        'ddwtos': 'Drag and Drop onto Text',
        'ddimageortext': 'Drag and Drop onto Image',
        'ddmarker': 'Drag and Drop Markers',
        'gapselect': 'Select Missing Words',
        'calculated': 'Calculated',
        'calculatedsimple': 'Calculated Simple',
        'ordering': 'Ordering'
    };
    
    // Build common fields
    const isEditing = editingQuestionIndex !== null;
    const actionText = isEditing ? 'Edit' : 'Create';
    const actionIcon = isEditing ? 'fa-edit' : 'fa-plus-circle';
    const actionColor = isEditing ? '#007bff' : '#28a745';
    let formHTML = `
        <h3 style="margin-bottom: 20px; color: ${actionColor};">
            <i class="fa ${actionIcon}"></i> ${actionText} ${typeNames[typeId] || typeId} Question
        </h3>
        <div class="form-group">
            <label class="form-label required">Question name</label>
            <input type="text" class="form-input" id="newQuestionName" placeholder="Brief name for the question">
        </div>
        <div class="form-group">
            <label class="form-label required">Question text</label>
            <textarea class="form-textarea" id="newQuestionText" required placeholder="Enter your question here..."></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Default mark</label>
            <input type="number" class="form-input" id="newQuestionMark" value="1" min="0" step="0.1">
        </div>
    `;
    
    // Add type-specific fields placeholder
    formHTML += `<div id="answersSection"></div>`;
    
    formHTML += `
        <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #e9ecef; text-align: right;">
            <button type="button" class="btn-cancel" id="skipAiSuggestionBtn" onclick="skipAiSuggestion()" style="display: none; margin-right: 12px; background: #6c757d;">
                <i class="fa fa-forward"></i> Skip suggestion
            </button>
            <button type="button" class="btn-cancel" onclick="cancelQuestionBuilder()" style="margin-right: 12px;">
                <i class="fa fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-submit" onclick="saveNewQuestion('${typeId}')">
                <i class="fa fa-save"></i> ${isEditing ? 'Save Changes' : 'Save & Add Question'}
            </button>
        </div>
    `;
    
    builderForm.innerHTML = formHTML;
    toggleAiOptionControls(typeId);
    setSkipButtonState();
    
    // Initialize type-specific sections
    switch(typeId) {
        case 'multichoice':
            initializeMultichoiceAnswers();
            break;
        case 'truefalse':
            initializeTrueFalseAnswers();
            break;
        case 'shortanswer':
            initializeShortAnswerFields();
            break;
        case 'essay':
            initializeEssayFields();
            break;
        case 'numerical':
            initializeNumericalFields();
            break;
        case 'match':
            initializeMatchingFields();
            break;
        case 'description':
            initializeDescriptionFields();
            break;
        case 'multianswer':
            initializeClozeFields();
            break;
        case 'ddwtos':
            initializeDdwtosFields();
            break;
        case 'ddimageortext':
            initializeDdimageortextFields();
            break;
        case 'ddmarker':
            initializeDragDropFields(typeId);
            break;
        case 'calculated':
        case 'calculatedsimple':
            initializeCalculatedFields(typeId);
            break;
        case 'ordering':
            initializeOrderingFields();
            break;
        case 'gapselect':
            initializeGapselectFields();
            break;
        default:
            initializeAdvancedPlaceholder(typeId);
    }
}

function toggleAiOptionControls(typeId) {
    const choiceGroup = document.getElementById('aiChoiceCountGroup');
    if (choiceGroup) {
        const label = choiceGroup.querySelector('label');
        const requiresCount = ['multichoice', 'ordering', 'match', 'gapselect', 'ddwtos', 'ddimageortext'];
        choiceGroup.style.display = requiresCount.includes(typeId) ? 'block' : 'none';
        if (label) {
            if (typeId === 'ordering') {
                label.textContent = 'Number of ordering items';
            } else if (typeId === 'match') {
                label.textContent = 'Number of matching pairs';
            } else if (typeId === 'gapselect' || typeId === 'ddwtos') {
                label.textContent = 'Number of blanks';
            } else if (typeId === 'ddimageortext') {
                label.textContent = 'Number of drop zones';
            } else {
                label.textContent = 'Number of answer options';
            }
        }
    }

    const countInput = document.getElementById('aiQuestionCount');
    if (countInput) {
        const value = parseInt(countInput.value, 10);
        if (isNaN(value) || value < 1) {
            countInput.value = 1;
        } else if (value > aiMaxQuestions) {
            countInput.value = aiMaxQuestions;
        }
    }

    const difficultySelect = document.getElementById('aiDifficulty');
    if (difficultySelect && aiDefaultDifficulty) {
        const option = Array.from(difficultySelect.options)
            .find(opt => opt.value === aiDefaultDifficulty);
        if (option) {
            difficultySelect.value = aiDefaultDifficulty;
        }
    }

    const generatorWrapper = document.getElementById('aiQuestionGenerator');
    const statusEl = document.getElementById('aiGeneratorStatus');
    if (!aiQuizGeneratorEnabled && generatorWrapper) {
        generatorWrapper.classList.add('disabled');
        const button = generatorWrapper.querySelector('.btn-ai-generate');
        if (button) {
            button.disabled = true;
            button.classList.add('disabled');
        }
        if (statusEl) {
            statusEl.textContent = 'AI question generation is disabled by the administrator.';
            statusEl.classList.add('error');
        }
    } else if (statusEl) {
        statusEl.textContent = '';
        statusEl.classList.remove('error', 'success');
        const button = generatorWrapper ? generatorWrapper.querySelector('.btn-ai-generate') : null;
        if (button) {
            button.disabled = false;
            button.classList.remove('disabled');
        }
    }
}

function setSkipButtonState() {
    const skipBtn = document.getElementById('skipAiSuggestionBtn');
    if (!skipBtn) {
        return;
    }
    if (!aiGeneratedType || aiGeneratedCurrentSuggestion === null) {
        skipBtn.style.display = 'none';
        return;
    }
    const hasMore = aiGeneratedQueue.length > 0;
    skipBtn.style.display = 'inline-flex';
    skipBtn.innerHTML = `<i class="fa fa-forward"></i> ${hasMore ? 'Skip suggestion' : 'Skip & finish'}`;
}

function setAiGeneratorVisibility(visible) {
    const block = document.getElementById('aiQuestionGenerator');
    if (!block) {
        return;
    }
    block.style.display = visible ? 'block' : 'none';
}

function clearAiQueue(message = '', state = '') {
    aiGeneratedQueue = [];
    aiGeneratedType = null;
    aiGeneratedTotal = 0;
    aiGeneratedIndex = 0;
    aiGeneratedAccepted = 0;
    aiGeneratedCurrentSuggestion = null;
    const skipBtn = document.getElementById('skipAiSuggestionBtn');
    if (skipBtn) {
        skipBtn.style.display = 'none';
        skipBtn.innerHTML = '<i class="fa fa-forward"></i> Skip suggestion';
    }
    if (message !== null) {
        updateAiGeneratorStatus(message, state);
    }
}

function updateAiGeneratorStatus(message, state = '') {
    const statusEl = document.getElementById('aiGeneratorStatus');
    if (!statusEl) {
        return;
    }
    statusEl.textContent = message || '';
    statusEl.classList.remove('error', 'success');
    if (state) {
        statusEl.classList.add(state);
    }
}

function normalizeAiResponse(response) {
    if (!response) {
        return [];
    }

    let questions = [];
    if (response.questions) {
        let payload = response.questions;
        if (typeof payload === 'string') {
            try {
                payload = JSON.parse(payload);
            } catch (error) {
                console.error('Failed to parse questions payload', error);
                return [];
            }
        }
        if (Array.isArray(payload)) {
            questions = payload;
        }
    } else if (response.question) {
        let payload = response.question;
        if (typeof payload === 'string') {
            try {
                payload = JSON.parse(payload);
            } catch (error) {
                console.error('Failed to parse question payload', error);
                return [];
            }
        }
        if (payload) {
            questions = [payload];
        }
    }

    return questions.filter(item => typeof item === 'object' && item !== null);
}

function startAiSuggestionFlow(typeId, suggestions) {
    if (!Array.isArray(suggestions) || suggestions.length === 0) {
        updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
        return;
    }

    aiGeneratedType = typeId;
    aiGeneratedQueue = suggestions.slice();
    aiGeneratedTotal = aiGeneratedQueue.length;
    aiGeneratedIndex = 0;
    aiGeneratedAccepted = 0;
    aiGeneratedCurrentSuggestion = null;

    setAiGeneratorVisibility(true);
    loadNextAiSuggestion();
}

function loadNextAiSuggestion(statusPrefix = '') {
    if (!aiGeneratedQueue || aiGeneratedQueue.length === 0) {
        aiGeneratedCurrentSuggestion = null;
        const baseMessage = statusPrefix ? `${statusPrefix} All AI-generated suggestions processed.` : 'All AI-generated suggestions processed.';
        clearAiQueue(baseMessage.trim(), 'success');
        cancelQuestionBuilder();
        return;
    }

    aiGeneratedCurrentSuggestion = aiGeneratedQueue.shift();
    aiGeneratedIndex++;

    switchQuestionMode('create');
    const card = document.querySelector(`.question-type-card[data-type="${aiGeneratedType}"]`);
    selectQuestionType(aiGeneratedType, card);
    autofillQuestionBuilder(aiGeneratedType, aiGeneratedCurrentSuggestion);

    const prefix = statusPrefix ? `${statusPrefix} ` : '';
    updateAiGeneratorStatus(`${prefix}AI suggestion ${aiGeneratedIndex} of ${aiGeneratedTotal} loaded. Review, edit, and click Save & Add to accept, or use Skip to discard.`, 'success');
    setSkipButtonState();
}

function skipAiSuggestion() {
    if (!aiGeneratedType || aiGeneratedCurrentSuggestion === null) {
        clearAiQueue(null);
        cancelQuestionBuilder();
        return;
    }

    if (aiGeneratedQueue.length > 0) {
        loadNextAiSuggestion('Skipped suggestion.');
    } else {
        clearAiQueue('Skipped final AI suggestion.', 'success');
        cancelQuestionBuilder();
    }
}

function generateQuestionWithAI(typeId = currentQuestionType) {
    if (!aiQuizGeneratorEnabled) {
        return;
    }

    const topicInput = document.getElementById('aiTopicInput');
    const difficultySelect = document.getElementById('aiDifficulty');
    const choiceCountSelect = document.getElementById('aiChoiceCount');
    const wrapper = document.getElementById('aiQuestionGenerator');
    const countInput = document.getElementById('aiQuestionCount');

    if (!topicInput || topicInput.value.trim() === '') {
        alert('Please enter a topic for the AI to work with.');
        return;
    }

    if (!typeId) {
        alert('Please select a question type first.');
        return;
    }

    const requestedCount = countInput ? Math.min(aiMaxQuestions, Math.max(1, parseInt(countInput.value, 10) || 1)) : 1;
    if (countInput) {
        countInput.value = requestedCount;
    }

    const payload = {
        topic: topicInput.value.trim(),
        qtype: typeId,
        difficulty: difficultySelect ? difficultySelect.value : '',
        numoptions: choiceCountSelect ? parseInt(choiceCountSelect.value, 10) || 4 : 4,
        count: requestedCount
    };

    if (wrapper) {
        wrapper.classList.add('loading');
    }
    clearAiQueue(null);
    updateAiGeneratorStatus('Generating question suggestions...', '');

    if (typeId === 'match') {
        generateMatchingQuestionWithAI(payload, wrapper);
        return;
    }
    if (typeId === 'ordering') {
        generateOrderingQuestionWithAI(payload, wrapper);
        return;
    }
    if (typeId === 'gapselect') {
        generateGapselectQuestionWithAI(payload, wrapper);
        return;
    }
    if (typeId === 'ddwtos') {
        generateDdwtosQuestionWithAI(payload, wrapper);
        return;
    }
    if (typeId === 'ddimageortext') {
        generateDdimageortextQuestionWithAI(payload, wrapper);
        return;
    }

    require(['core/ajax', 'core/notification'], function(Ajax, Notification) {
        Ajax.call([{
            methodname: 'local_quizai_generate_question',
            args: payload
        }])[0].then(function(response) {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }

            if (!response || !response.success) {
                updateAiGeneratorStatus(response && response.message ? response.message : 'AI generation failed. Please try again.', 'error');
                return;
            }

            const questionsPayload = normalizeAiResponse(response);
            if (!questionsPayload || questionsPayload.length === 0) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz(payload.qtype || typeId, questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        }).catch(function(error) {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
            Notification.exception(error);
        });
    });
}

function generateMatchingQuestionWithAI(payload, wrapper) {
    const formData = new URLSearchParams();
    formData.append('topic', payload.topic);
    formData.append('difficulty', payload.difficulty || '');
    formData.append('count', payload.count || 1);
    formData.append('pairs', Math.max(3, Math.min(8, payload.numoptions || 5)));
    if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }

    fetch('generate_matching_ai.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            if (!data || !data.success) {
                updateAiGeneratorStatus(data && data.message ? data.message : 'AI generation failed. Please try again.', 'error');
                return;
            }
            const questionsPayload = Array.isArray(data.questions) ? data.questions : [];
            if (!questionsPayload.length) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz('match', questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        })
        .catch(error => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            console.error('Matching AI error:', error);
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
        });
}

function generateOrderingQuestionWithAI(payload, wrapper) {
    const formData = new URLSearchParams();
    formData.append('topic', payload.topic);
    formData.append('difficulty', payload.difficulty || '');
    formData.append('count', payload.count || 1);
    formData.append('items', Math.max(3, Math.min(8, payload.numoptions || 5)));
    if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }

    fetch('generate_ordering_ai.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            if (!data || !data.success) {
                updateAiGeneratorStatus(data && data.message ? data.message : 'AI generation failed. Please try again.', 'error');
                return;
            }
            const questionsPayload = Array.isArray(data.questions) ? data.questions : [];
            if (!questionsPayload.length) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz('ordering', questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        })
        .catch(error => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            console.error('Ordering AI error:', error);
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
        });
}

function generateGapselectQuestionWithAI(payload, wrapper) {
    const formData = new URLSearchParams();
    formData.append('topic', payload.topic);
    formData.append('difficulty', payload.difficulty || '');
    formData.append('count', payload.count || 1);
    formData.append('blanks', Math.max(2, Math.min(6, payload.numoptions || 3)));
    if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }

    fetch('generate_gapselect_ai.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            if (!data || !data.success) {
                updateAiGeneratorStatus(data && data.message ? data.message : 'AI generation failed. Please try again.', 'error');
                return;
            }
            const questionsPayload = Array.isArray(data.questions) ? data.questions : [];
            if (!questionsPayload.length) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz('gapselect', questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        })
        .catch(error => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            console.error('Gapselect AI error:', error);
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
        });
}

function autofillQuestionBuilder(typeId, payload) {
    if (!payload || typeof payload !== 'object') {
        return;
    }

    const nameField = document.getElementById('newQuestionName');
    if (nameField && payload.name) {
        nameField.value = payload.name;
    }

    const textField = document.getElementById('newQuestionText');
    if (textField && payload.text) {
        textField.value = payload.text;
    }

    const markField = document.getElementById('newQuestionMark');
    if (markField && payload.defaultmark) {
        markField.value = payload.defaultmark;
    }

    switch (typeId) {
        case 'multichoice':
            populateMultichoiceAnswersFromAI(payload.answers);
            break;
        case 'truefalse':
            applyTrueFalseFromAI(payload.answers);
            break;
        case 'shortanswer':
            populateShortAnswersFromAI(payload.answers, payload.settings);
            break;
        case 'essay':
            applyEssaySettingsFromAI(payload.settings);
            break;
        case 'numerical':
            applyNumericalAnswerFromAI(payload.answers);
            break;
        case 'match':
            populateMatchPairsFromAI(payload.pairs || payload.matches || payload.answers);
            break;
        case 'ordering':
            populateOrderingFromAI(payload.items || payload.sequence || payload.steps);
            break;
        case 'gapselect':
            populateGapselectFromAI(payload);
            break;
        case 'ddwtos':
            populateDdwtosFromAI(payload);
            break;
        case 'ddimageortext':
            populateDdimageortextFromAI(payload);
            break;
    }
}

function addAiQuestionToQuiz(typeId, payload) {
    if (!payload || typeof payload !== 'object') {
        return false;
    }

    const questionName = payload.name || (payload.text ? payload.text.substring(0, 50) : 'AI Question');
    const question = {
        id: 'ai_' + (++questionCounter),
        name: questionName,
        qtype: typeId,
        questiontext: payload.text || '',
        defaultmark: payload.defaultmark || 1,
        isNew: true,
        aiGenerated: true,
        questionbankentryid: null,
        slot: editingQuestionIndex !== null && quizQuestions[editingQuestionIndex]
            ? quizQuestions[editingQuestionIndex].slot
            : quizQuestions.length + 1,
        page: Math.ceil((quizQuestions.length + 1) / (parseInt(document.getElementById('questionsPerPage')?.value) || 1))
    };

    switch (typeId) {
        case 'multichoice':
            question.answers = Array.isArray(payload.answers) ? payload.answers.map(ans => ({
                text: ans.text || '',
                fraction: ans.iscorrect ? 1 : 0,
                feedback: ans.feedback || ''
            })) : [];
            break;
        case 'truefalse':
            question.answers = Array.isArray(payload.answers) ? payload.answers.map(ans => ({
                text: ans.text,
                fraction: ans.iscorrect ? 1 : 0
            })) : [
                { text: 'True', fraction: 1 },
                { text: 'False', fraction: 0 }
            ];
            break;
        case 'shortanswer':
            question.answers = Array.isArray(payload.answers) ? payload.answers.map(ans => ({
                text: ans.text || '',
                fraction: ans.grade ? parseFloat(ans.grade) : 1
            })) : [];
            question.caseSensitive = payload.settings && payload.settings.case_sensitive ? 1 : 0;
            break;
        case 'essay':
            question.minWords = payload.settings?.minwords ?? 0;
            question.maxWords = payload.settings?.maxwords ?? 0;
            question.attachments = payload.settings?.attachments ?? 0;
            break;
        case 'numerical':
            const answer = Array.isArray(payload.answers) ? payload.answers[0] : null;
            question.answer = answer?.value ?? '';
            question.tolerance = answer?.tolerance ?? 0;
            question.unit = answer?.unit ?? '';
            break;
        case 'match':
            question.pairs = Array.isArray(payload.pairs)
                ? payload.pairs
                    .map(pair => ({
                        question: (pair.question || pair.prompt || '').toString(),
                        answer: (pair.answer || pair.match || '').toString()
                    }))
                    .filter(pair => pair.question.trim() && pair.answer.trim())
                : [];
            break;
        case 'ordering':
            question.items = Array.isArray(payload.items)
                ? payload.items
                    .map((item, index) => ({
                        text: (typeof item === 'string' ? item : (item?.text || '')).toString(),
                        order: index + 1
                    }))
                    .filter(item => item.text.trim())
                : [];
            break;
        case 'gapselect':
            const gaps = Array.isArray(payload.gaps) ? payload.gaps : [];
            question.gapselect = {
                shuffle: payload.shuffleanswers !== false,
                groups: gaps.map((gap, index) => ({
                    slot: index + 1,
                    correct: gap.answer || '',
                    distractors: Array.isArray(gap.distractors) ? gap.distractors : []
                }))
            };
            break;
        case 'ddwtos':
            const ddwtosGaps = Array.isArray(payload.gaps) ? payload.gaps : [];
            question.ddwtos = {
                shuffle: payload.shuffleanswers !== false,
                groups: ddwtosGaps.map((gap, index) => ({
                    slot: index + 1,
                    correct: gap.answer || '',
                    distractors: Array.isArray(gap.distractors) ? gap.distractors : []
                }))
            };
            break;
        case 'ddimageortext':
            const drops = Array.isArray(payload.drops) ? payload.drops : [];
            question.ddimageortext = {
                shuffle: payload.shuffleanswers !== false,
                drops: drops.map((drop, index) => ({
                    label: drop.label || `Zone ${index + 1}`,
                    correct: drop.correct || '',
                    distractors: Array.isArray(drop.distractors) ? drop.distractors : []
                }))
            };
            break;
    }

    quizQuestions.push(question);
    updateQuizQuestionsList();
    return true;
}

function loadQuestionIntoBuilder(question, index) {
    editingQuestionIndex = index;
    const typeId = question.qtype;
    switchQuestionMode('create');
    const card = document.querySelector(`.question-type-card[data-type="${typeId}"]`);
    selectQuestionType(typeId, card);
    const builderForm = document.getElementById('questionBuilderForm');
    if (!builderForm) {
        return;
    }
    document.getElementById('newQuestionName').value = question.name || '';
    document.getElementById('newQuestionText').value = question.questiontext || '';
    document.getElementById('newQuestionMark').value = question.defaultmark || 1;
    switch (typeId) {
        case 'multichoice':
            populateMultichoiceAnswersFromExisting(question);
            break;
        case 'truefalse':
            applyTrueFalseFromExisting(question);
            break;
        case 'shortanswer':
            populateShortAnswersFromExisting(question);
            break;
        case 'essay':
            applyEssayFromExisting(question);
            break;
        case 'numerical':
            applyNumericalFromExisting(question);
            break;
        case 'match':
            populateMatchPairsFromExisting(question);
            break;
        case 'ordering':
            populateOrderingFromExisting(question);
            break;
        case 'gapselect':
            populateGapselectFromExisting(question);
            break;
        case 'ddwtos':
            populateDdwtosFromExisting(question);
            break;
        case 'ddimageortext':
            populateDdimageortextFromExisting(question);
            break;
    }
    updateAiGeneratorStatus('Editing question. Save to apply changes.', '');

    if (!isQuizEditMode) {
        focusQuestionBuilderForm();
    }
}

function focusQuestionBuilderForm() {
    const builderForm = document.getElementById('questionBuilderForm');
    if (!builderForm) {
        return;
    }

    builderForm.classList.add('active');

    const scrollTarget = builderForm.closest('.question-mode-content') || builderForm;
    if (scrollTarget && typeof scrollTarget.scrollIntoView === 'function') {
        window.requestAnimationFrame(() => {
            scrollTarget.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    }

    const firstField = builderForm.querySelector('input, textarea, select');
    if (firstField && typeof firstField.focus === 'function') {
        setTimeout(() => {
            firstField.focus();
        }, 300);
    }
}

function cancelQuestionBuilder() {
    const builderForm = document.getElementById('questionBuilderForm');
    if (builderForm) {
        builderForm.classList.remove('active');
        builderForm.innerHTML = '';
    }
    document.querySelectorAll('.question-type-card').forEach(card => card.classList.remove('selected'));
    clearAiQueue(null);
    setAiGeneratorVisibility(false);
    currentQuestionType = null;
    editingQuestionIndex = null;
}

// Initialize multichoice answers
function initializeMultichoiceAnswers() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Answers</h4>
        <div id="answersList"></div>
        <button type="button" onclick="addMultichoiceAnswer()" style="margin-top: 12px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fa fa-plus"></i> Add Answer
        </button>
    `;
    
    // Add 4 default answers
    for (let i = 0; i < 4; i++) {
        addMultichoiceAnswer(i === 0);
    }
}

let answerCount = 0;
function addMultichoiceAnswer(isCorrect = false, prefillData) {
    const answersList = document.getElementById('answersList');
    if (!answersList) {
        return;
    }
    const answerId = answerCount++;
    
    const answerDiv = document.createElement('div');
    answerDiv.style.cssText = 'display: flex; gap: 12px; align-items: center; margin-bottom: 12px;';
    
    const answerInput = document.createElement('input');
    answerInput.type = 'text';
    answerInput.className = 'form-input';
    answerInput.placeholder = 'Answer text';
    answerInput.style.flex = '1';
    answerInput.id = `answer_${answerId}`;
    answerInput.value = prefillData && prefillData.text ? prefillData.text : '';
    
    const label = document.createElement('label');
    label.style.cssText = 'display: flex; align-items: center; gap: 6px; white-space: nowrap;';
    
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'correct_answer';
    radio.value = answerId;
    const prefillCorrect = prefillData && Object.prototype.hasOwnProperty.call(prefillData, 'iscorrect') ? !!prefillData.iscorrect : isCorrect;
    radio.checked = prefillCorrect;
    
    const correctSpan = document.createElement('span');
    correctSpan.textContent = 'Correct';
    
    label.appendChild(radio);
    label.appendChild(correctSpan);
    
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.style.cssText = 'padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;';
    removeButton.innerHTML = '<i class="fa fa-trash"></i>';
    removeButton.addEventListener('click', () => {
        answerDiv.remove();
    });
    
    answerDiv.appendChild(answerInput);
    answerDiv.appendChild(label);
    answerDiv.appendChild(removeButton);
    answersList.appendChild(answerDiv);
}

function populateMultichoiceAnswersFromAI(answers) {
    const answersList = document.getElementById('answersList');
    if (!answersList) {
        return;
    }
    answersList.innerHTML = '';
    answerCount = 0;

    if (!Array.isArray(answers) || answers.length === 0) {
        for (let i = 0; i < 4; i++) {
            addMultichoiceAnswer(i === 0);
        }
        updateAiGeneratorStatus('AI did not return answer options, default fields restored.', 'error');
        return;
    }

    let hasCorrect = false;
    answers.forEach((answer, index) => {
        const isCorrect = !!answer.iscorrect;
        if (isCorrect) {
            hasCorrect = true;
        }
        addMultichoiceAnswer(index === 0, answer);
    });

    if (!hasCorrect) {
        const firstRadio = answersList.querySelector('input[name="correct_answer"]');
        if (firstRadio) {
            firstRadio.checked = true;
        }
    }
}

function populateMultichoiceAnswersFromExisting(question) {
    const answersList = document.getElementById('answersList');
    if (!answersList) {
        return;
    }
    answersList.innerHTML = '';
    answerCount = 0;
    const answers = Array.isArray(question.answers) ? question.answers : [];
    if (answers.length === 0) {
        for (let i = 0; i < 4; i++) {
            addMultichoiceAnswer(i === 0);
        }
        return;
    }
    let hasCorrect = false;
    answers.forEach((answer, index) => {
        const isCorrect = !!answer.fraction;
        if (isCorrect) {
            hasCorrect = true;
        }
        addMultichoiceAnswer(isCorrect || (!hasCorrect && index === 0), {
            text: answer.text,
            iscorrect: isCorrect
        });
    });
    if (!hasCorrect) {
        const firstRadio = answersList.querySelector('input[name="correct_answer"]');
        if (firstRadio) {
            firstRadio.checked = true;
        }
    }
}

// Initialize true/false answers
function initializeTrueFalseAnswers() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Correct Answer</h4>
        <div>
            <label style="display: block; margin-bottom: 8px;">
                <input type="radio" name="tf_answer" value="1" checked> True
            </label>
            <label style="display: block;">
                <input type="radio" name="tf_answer" value="0"> False
            </label>
        </div>
    `;
}

function applyTrueFalseFromAI(answers) {
    if (!Array.isArray(answers)) {
        return;
    }
    let trueIsCorrect = null;
    let falseIsCorrect = null;
    answers.forEach(answer => {
        if (!answer || typeof answer.text === 'undefined') {
            return;
        }
        const text = String(answer.text).toLowerCase();
        if (text.includes('true')) {
            trueIsCorrect = !!answer.iscorrect;
        }
        if (text.includes('false')) {
            falseIsCorrect = !!answer.iscorrect;
        }
    });

    const trueRadio = document.querySelector('input[name="tf_answer"][value="1"]');
    const falseRadio = document.querySelector('input[name="tf_answer"][value="0"]');

    if (trueIsCorrect === null && falseIsCorrect === null) {
        if (trueRadio) trueRadio.checked = true;
        if (falseRadio) falseRadio.checked = false;
        return;
    }

    if (trueRadio) {
        trueRadio.checked = trueIsCorrect !== null ? trueIsCorrect : !(falseIsCorrect || false);
    }
    if (falseRadio) {
        falseRadio.checked = falseIsCorrect !== null ? falseIsCorrect : !(trueIsCorrect || false);
    }
}

function applyTrueFalseFromExisting(question) {
    const value = question.answer !== undefined ? String(question.answer) : '1';
    const trueRadio = document.querySelector('input[name="tf_answer"][value="1"]');
    const falseRadio = document.querySelector('input[name="tf_answer"][value="0"]');
    if (trueRadio) {
        trueRadio.checked = value === '1';
    }
    if (falseRadio) {
        falseRadio.checked = value === '0';
    }
}
// Initialize short answer fields
function initializeShortAnswerFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Correct Answers</h4>
        <p style="font-size: 13px; color: #6c757d; margin-bottom: 12px;">Add one or more acceptable answers</p>
        <div id="shortAnswersList"></div>
        <button type="button" onclick="addShortAnswer()" style="margin-top: 12px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fa fa-plus"></i> Add Answer
        </button>
        <div style="margin-top: 16px;">
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="shortAnswerCaseSensitive" value="1">
                Case sensitive
            </label>
        </div>
    `;
    addShortAnswer();
}

let shortAnswerCount = 0;
function addShortAnswer(prefillText = '') {
    const answersList = document.getElementById('shortAnswersList');
    const answerId = shortAnswerCount++;
    
    const answerDiv = document.createElement('div');
    answerDiv.style.cssText = 'display: flex; gap: 12px; align-items: center; margin-bottom: 12px;';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-input';
    input.placeholder = 'Acceptable answer';
    input.style.flex = '1';
    input.id = `sa_answer_${answerId}`;
    input.value = prefillText || '';

    const button = document.createElement('button');
    button.type = 'button';
    button.style.cssText = 'padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;';
    button.innerHTML = '<i class="fa fa-trash"></i>';
    button.addEventListener('click', () => answerDiv.remove());

    answerDiv.appendChild(input);
    answerDiv.appendChild(button);
    answersList.appendChild(answerDiv);
}

function populateShortAnswersFromAI(answers, settings) {
    const answersList = document.getElementById('shortAnswersList');
    if (!answersList) {
        return;
    }
    answersList.innerHTML = '';
    shortAnswerCount = 0;

    if (!Array.isArray(answers) || answers.length === 0) {
        addShortAnswer('');
        updateAiGeneratorStatus('AI did not return short answer suggestions, default field restored.', 'error');
    } else {
    answers.forEach(answer => {
            const text = answer && answer.text ? answer.text : '';
            addShortAnswer(text);
        });
    }

    const caseSensitive = settings && Object.prototype.hasOwnProperty.call(settings, 'case_sensitive')
        ? !!settings.case_sensitive
        : false;
    const checkbox = document.getElementById('shortAnswerCaseSensitive');
    if (checkbox) {
        checkbox.checked = caseSensitive;
    }
}

function populateShortAnswersFromExisting(question) {
    const answersList = document.getElementById('shortAnswersList');
    if (!answersList) {
        return;
    }
    answersList.innerHTML = '';
    shortAnswerCount = 0;
    const answers = Array.isArray(question.answers) ? question.answers : [];
    if (answers.length === 0) {
        addShortAnswer('');
    } else {
        answers.forEach(answer => addShortAnswer(answer.text || ''));
    }
    const checkbox = document.getElementById('shortAnswerCaseSensitive');
    if (checkbox) {
        checkbox.checked = !!question.caseSensitive;
    }
}

// Initialize essay fields
function initializeEssayFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Essay Settings</h4>
        <div class="form-group">
            <label class="form-label">Minimum word limit</label>
            <input type="number" class="form-input" id="essayMinWords" min="0" placeholder="0 = no minimum">
        </div>
        <div class="form-group">
            <label class="form-label">Maximum word limit</label>
            <input type="number" class="form-input" id="essayMaxWords" min="0" placeholder="0 = no maximum">
        </div>
        <div class="form-group">
            <label class="form-label">File attachments allowed</label>
            <select class="form-select" id="essayAttachments">
                <option value="0" selected>No</option>
                <option value="1">1 file</option>
                <option value="2">2 files</option>
                <option value="3">3 files</option>
                <option value="5">5 files</option>
            </select>
        </div>
    `;
}

function applyEssaySettingsFromAI(settings) {
    if (!settings) {
        return;
    }
    const minInput = document.getElementById('essayMinWords');
    const maxInput = document.getElementById('essayMaxWords');
    const attachmentsSelect = document.getElementById('essayAttachments');

    if (minInput && Object.prototype.hasOwnProperty.call(settings, 'minwords')) {
        minInput.value = parseInt(settings.minwords, 10) || 0;
    }
    if (maxInput && Object.prototype.hasOwnProperty.call(settings, 'maxwords')) {
        maxInput.value = parseInt(settings.maxwords, 10) || 0;
    }
    if (attachmentsSelect && Object.prototype.hasOwnProperty.call(settings, 'attachments')) {
        attachmentsSelect.value = parseInt(settings.attachments, 10) || 0;
    }
}

function applyEssayFromExisting(question) {
    const minInput = document.getElementById('essayMinWords');
    const maxInput = document.getElementById('essayMaxWords');
    const attachmentsSelect = document.getElementById('essayAttachments');
    if (minInput) {
        minInput.value = question.minWords || 0;
    }
    if (maxInput) {
        maxInput.value = question.maxWords || 0;
    }
    if (attachmentsSelect) {
        attachmentsSelect.value = question.attachments || 0;
    }
}

// Initialize numerical fields
function initializeNumericalFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Correct Answer</h4>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label required">Answer</label>
                <input type="number" class="form-input" id="numericalAnswer" step="any" placeholder="e.g., 3.14" required>
            </div>
            <div class="form-group">
                <label class="form-label">Accepted error (tolerance)</label>
                <input type="number" class="form-input" id="numericalTolerance" step="any" value="0" min="0" placeholder="e.g., 0.01">
            </div>
        </div>
        <p style="font-size: 13px; color: #6c757d; margin-top: 8px;">
            <i class="fa fa-info-circle"></i> Example: Answer 3.14 with tolerance 0.01 accepts 3.13 to 3.15
        </p>
    `;
}

function applyNumericalAnswerFromAI(answers) {
    if (!Array.isArray(answers) || answers.length === 0) {
        return;
    }
    const answer = answers[0];
    const answerField = document.getElementById('numericalAnswer');
    const toleranceField = document.getElementById('numericalTolerance');

    if (answerField && Object.prototype.hasOwnProperty.call(answer, 'value')) {
        answerField.value = answer.value;
    }
    if (toleranceField && Object.prototype.hasOwnProperty.call(answer, 'tolerance')) {
        toleranceField.value = answer.tolerance;
    }
}

function applyNumericalFromExisting(question) {
    const answerField = document.getElementById('numericalAnswer');
    const toleranceField = document.getElementById('numericalTolerance');
    if (answerField) {
        answerField.value = question.answer ?? '';
    }
    if (toleranceField) {
        toleranceField.value = question.tolerance ?? 0;
    }
}
// Initialize matching fields
let matchPairCount = 0;
function initializeMatchingFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Question and Answer Pairs</h4>
        <p style="font-size: 13px; color: #6c757d; margin-bottom: 12px;">Create pairs of questions and their matching answers</p>
        <div id="matchPairsList"></div>
        <button type="button" onclick="addMatchPair()" style="margin-top: 12px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fa fa-plus"></i> Add Pair
        </button>
    `;
    // Add 3 default pairs
    for (let i = 0; i < 3; i++) {
        addMatchPair();
    }
}

function addMatchPair(prefillData = null) {
    const pairsList = document.getElementById('matchPairsList');
    const pairId = matchPairCount++;
    
    const pairDiv = document.createElement('div');
    pairDiv.style.cssText = 'padding: 16px; background: white; border: 1px solid #e9ecef; border-radius: 6px; margin-bottom: 12px;';
    pairDiv.innerHTML = `
        <div style="display: flex; gap: 12px; align-items: flex-start;">
            <div style="flex: 1;">
                <label style="font-size: 13px; font-weight: 600; color: #495057; margin-bottom: 6px; display: block;">Question ${pairId + 1}</label>
                <input type="text" class="form-input" id="match_question_${pairId}" placeholder="Enter question/prompt">
            </div>
            <div style="flex: 1;">
                <label style="font-size: 13px; font-weight: 600; color: #495057; margin-bottom: 6px; display: block;">Answer</label>
                <input type="text" class="form-input" id="match_answer_${pairId}" placeholder="Enter matching answer">
            </div>
            <button type="button" onclick="this.parentElement.parentElement.remove()" style="padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 28px;">
                <i class="fa fa-trash"></i>
            </button>
        </div>
    `;
    pairsList.appendChild(pairDiv);
    if (prefillData) {
        const questionInput = document.getElementById(`match_question_${pairId}`);
        const answerInput = document.getElementById(`match_answer_${pairId}`);
        if (questionInput) {
            questionInput.value = prefillData.question || '';
        }
        if (answerInput) {
            answerInput.value = prefillData.answer || '';
        }
    }
}

function populateMatchPairsFromAI(pairs) {
    const pairsList = document.getElementById('matchPairsList');
    if (!pairsList) {
        return;
    }
    pairsList.innerHTML = '';
    matchPairCount = 0;

    if (!Array.isArray(pairs) || pairs.length === 0) {
        for (let i = 0; i < 3; i++) {
            addMatchPair();
        }
        updateAiGeneratorStatus('AI did not return matching pairs. Default fields provided.', 'error');
        return;
    }

    pairs.forEach(pair => {
        addMatchPair({
            question: pair.question || pair.prompt || '',
            answer: pair.answer || pair.match || ''
        });
    });
}

// Initialize description fields
function initializeDescriptionFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 16px; margin-top: 16px;">
            <strong style="color: #856404;"><i class="fa fa-info-circle"></i> Note:</strong>
            <p style="color: #856404; margin: 8px 0 0 0; font-size: 13px;">
                Description questions don't require answers. They are used to display information to students.
            </p>
        </div>
    `;
}

// Initialize cloze fields
function initializeClozeFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Cloze (Embedded Answers) Format</h4>
        <p style="font-size: 13px; color: #6c757d; margin-bottom: 12px;">
            Use special notation in question text. Example:<br>
            <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 8px;">
                What is {1:SHORTANSWER:=correct answer}?
            </code>
        </p>
        <div style="background: #e3f2fd; border: 1px solid #5b9bd5; border-radius: 6px; padding: 16px; margin-top: 16px;">
            <strong style="color: #1976d2;"><i class="fa fa-book"></i> Quick Reference:</strong>
            <ul style="color: #1976d2; margin: 8px 0 0 20px; font-size: 13px;">
                <li>{1:SHORTANSWER:=answer} - Short answer</li>
                <li>{1:MULTICHOICE:=Correct~Wrong1~Wrong2} - Multiple choice</li>
                <li>{1:NUMERICAL:=3.14:0.01} - Numerical with tolerance</li>
            </ul>
        </div>
    `;
}

// Initialize drag-drop fields
function initializeDragDropFields(typeId) {
    const typeNames = {
        'ddwtos': 'Drag and Drop onto Text',
        'ddimageortext': 'Drag and Drop onto Image',
        'ddmarker': 'Drag and Drop Markers'
    };
    
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 16px; margin-top: 16px;">
            <strong style="color: #856404;"><i class="fa fa-info-circle"></i> Advanced Question Type:</strong>
            <p style="color: #856404; margin: 8px 0 0 0; font-size: 13px;">
                ${typeNames[typeId] || typeId} questions are complex and best created through Moodle's native question bank interface.
                For now, you can use the Question Bank import feature to add existing ${typeNames[typeId] || typeId} questions to your quiz.
            </p>
            <p style="color: #856404; margin: 8px 0 0 0; font-size: 13px;">
                <strong>Tip:</strong> Create this question type in the standard Moodle question bank, then import it here.
            </p>
        </div>
    `;
}

// Initialize calculated fields
function initializeCalculatedFields(typeId) {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Calculated Question Settings</h4>
        <p style="font-size: 13px; color: #6c757d; margin-bottom: 12px;">
            Use variables in your question text and formula for the answer
        </p>
        <div class="form-group">
            <label class="form-label">Formula (use {x}, {y}, etc.)</label>
            <input type="text" class="form-input" id="calculatedFormula" placeholder="e.g., {x} + {y}">
        </div>
        <div class="form-group">
            <label class="form-label">Tolerance</label>
            <input type="number" class="form-input" id="calculatedTolerance" value="0.01" step="any">
        </div>
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 16px; margin-top: 16px;">
            <strong style="color: #856404;"><i class="fa fa-info-circle"></i> Note:</strong>
            <p style="color: #856404; margin: 8px 0 0 0; font-size: 13px;">
                Calculated questions require dataset definitions. For full functionality, create these in the Moodle question bank, then import them.
            </p>
        </div>
    `;
}

// Initialize ordering fields
let orderItemCount = 0;
function initializeOrderingFields() {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <h4 style="margin: 20px 0 12px 0;">Items to Order</h4>
        <p style="font-size: 13px; color: #6c757d; margin-bottom: 12px;">
            Add items in the correct order (students will see them shuffled)
        </p>
        <div id="orderItemsList"></div>
        <button type="button" onclick="addOrderItem()" style="margin-top: 12px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fa fa-plus"></i> Add Item
        </button>
    `;
    // Add 4 default items
    for (let i = 0; i < 4; i++) {
        addOrderItem();
    }
}

function addOrderItem() {
    const itemsList = document.getElementById('orderItemsList');
    const itemId = orderItemCount++;
    
    const itemDiv = document.createElement('div');
    itemDiv.style.cssText = 'display: flex; gap: 12px; align-items: center; margin-bottom: 12px;';
    itemDiv.innerHTML = `
        <span style="font-weight: 600; color: #6c757d; min-width: 30px;">${itemId + 1}.</span>
        <input type="text" class="form-input" id="order_item_${itemId}" placeholder="Item text" style="flex: 1;">
        <button type="button" onclick="this.parentElement.remove()" style="padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fa fa-trash"></i>
        </button>
    `;
    itemsList.appendChild(itemDiv);
}

function populateOrderingFromExisting(question) {
    const itemsList = document.getElementById('orderItemsList');
    if (!itemsList) {
        return;
    }
    itemsList.innerHTML = '';
    orderItemCount = 0;
    const items = Array.isArray(question.items) ? question.items : [];
    if (items.length === 0) {
        addOrderItem();
        addOrderItem();
        return;
    }
    items.forEach((item, idx) => {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display: flex; gap: 12px; align-items: center; margin-bottom: 12px;';
        wrapper.innerHTML = `
            <span style="font-weight: 600; color: #6c757d; min-width: 30px;">${idx + 1}.</span>
            <input type="text" class="form-input" id="order_item_${orderItemCount}" value="${item.text || ''}" placeholder="Item text" style="flex: 1;">
            <button type="button" onclick="this.parentElement.remove()" style="padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">
                <i class="fa fa-trash"></i>
            </button>
        `;
        itemsList.appendChild(wrapper);
        orderItemCount++;
    });
}

function populateOrderingFromAI(items) {
    const itemsList = document.getElementById('orderItemsList');
    if (!itemsList) {
        return;
    }
    itemsList.innerHTML = '';
    orderItemCount = 0;

    if (!Array.isArray(items) || items.length === 0) {
        for (let i = 0; i < 4; i++) {
            addOrderItem();
        }
        updateAiGeneratorStatus('AI did not return ordering items. Default fields provided.', 'error');
        return;
    }

    items.forEach((item, idx) => {
        const text = typeof item === 'string' ? item : (item?.text ?? '');
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display: flex; gap: 12px; align-items: center; margin-bottom: 12px;';
        wrapper.innerHTML = `
            <span style="font-weight: 600; color: #6c757d; min-width: 30px;">${idx + 1}.</span>
            <input type="text" class="form-input" id="order_item_${orderItemCount}" value="${text}" placeholder="Item text" style="flex: 1;">
            <button type="button" onclick="this.parentElement.remove()" style="padding: 8px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">
                <i class="fa fa-trash"></i>
            </button>
        `;
        itemsList.appendChild(wrapper);
        orderItemCount++;
    });
}

// Initialize placeholder for advanced types
function initializeAdvancedPlaceholder(typeId) {
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div style="background: #e3f2fd; border: 1px solid #5b9bd5; border-radius: 6px; padding: 16px; margin-top: 16px;">
            <strong style="color: #1976d2;"><i class="fa fa-lightbulb"></i> Advanced Question Type:</strong>
            <p style="color: #1976d2; margin: 8px 0 0 0; font-size: 13px;">
                This question type (${typeId}) is available in Moodle but requires complex configuration.
                You can create it using Moodle's standard question bank interface, then import it here using the "Import from Question Bank" tab.
            </p>
        </div>
    `;
}

// Save new question and add to quiz
function saveNewQuestion(typeId) {
    const questionName = document.getElementById('newQuestionName')?.value || '';
    const questionText = document.getElementById('newQuestionText').value;
    const mark = document.getElementById('newQuestionMark').value;
    const existingQuestion = editingQuestionIndex !== null ? quizQuestions[editingQuestionIndex] : null;
    const isEditingExistingPersistent = !!(existingQuestion && !existingQuestion.isNew && !String(existingQuestion.id || '').startsWith('new_'));
    const existingOriginalId = existingQuestion
        ? (existingQuestion.originalQuestionId
            || (!String(existingQuestion.id || '').startsWith('new_') ? existingQuestion.id : null)
            || existingQuestion.metadata?.id
            || null)
        : null;
    
    if (!questionText) {
        alert('Please enter question text');
        return;
    }
    
    // Create question object
    const question = {
        id: existingQuestion ? existingQuestion.id : 'new_' + (++questionCounter),
        name: questionName || questionText.substring(0, 50) + (questionText.length > 50 ? '...' : ''),
        qtype: typeId,
        questiontext: questionText,
        defaultmark: mark,
        isNew: existingQuestion ? !!existingQuestion.isNew : true,
        questionbankentryid: existingQuestion?.questionbankentryid || null,
        slot: existingQuestion?.slot || quizQuestions.length + 1,
        detailsLoaded: true,
        page: existingQuestion?.page || Math.ceil((quizQuestions.length + 1) / (parseInt(document.getElementById('questionsPerPage')?.value) || 1))
    };
    
    // Collect type-specific data
    switch(typeId) {
        case 'multichoice':
            question.answers = collectMultichoiceAnswers();
            if (question.answers.length === 0) {
                alert('Please add at least one answer');
                return;
            }
            break;
            
        case 'truefalse':
            question.answer = document.querySelector('input[name="tf_answer"]:checked')?.value || '1';
            break;
            
        case 'shortanswer':
            question.answers = collectShortAnswers();
            question.caseSensitive = document.getElementById('shortAnswerCaseSensitive')?.checked || false;
            if (question.answers.length === 0) {
                alert('Please add at least one correct answer');
                return;
            }
            break;
            
        case 'essay':
            question.minWords = document.getElementById('essayMinWords')?.value || 0;
            question.maxWords = document.getElementById('essayMaxWords')?.value || 0;
            question.attachments = document.getElementById('essayAttachments')?.value || 0;
            break;
            
        case 'numerical':
            question.answer = document.getElementById('numericalAnswer')?.value;
            question.tolerance = document.getElementById('numericalTolerance')?.value || 0;
            if (!question.answer) {
                alert('Please enter the correct answer');
                return;
            }
            break;
            
        case 'match':
            question.pairs = collectMatchPairs();
            if (question.pairs.length < 2) {
                alert('Please add at least 2 matching pairs');
                return;
            }
            break;
            
        case 'description':
            // No additional data needed
            break;
            
        case 'multianswer':
            // Cloze questions use embedded syntax in questiontext
            break;
            
        case 'gapselect':
            const gapGroups = collectGapselectGroups();
            if (gapGroups.length === 0) {
                alert('Please add at least one blank with a correct answer');
                return;
            }
            question.gapselect = {
                shuffle: document.getElementById('gapselectShuffle')?.checked ?? true,
                groups: gapGroups
            };
            break;
            
        case 'ddwtos':
            const ddwtosGroups = collectDdwtosGroups();
            if (ddwtosGroups.length === 0) {
                alert('Please add at least one blank with a correct answer');
                return;
            }
            question.ddwtos = {
                shuffle: document.getElementById('ddwtosShuffle')?.checked ?? true,
                groups: ddwtosGroups
            };
            break;
            
        case 'ddimageortext':
            const ddimageDrops = collectDdimageortextDrops();
            if (ddimageDrops.length === 0) {
                alert('Please add at least one drop zone with a correct answer');
                return;
            }
            question.ddimageortext = {
                shuffle: document.getElementById('ddimageortextShuffle')?.checked ?? true,
                drops: ddimageDrops
            };
            break;
            
        case 'ordering':
            question.items = collectOrderingItems();
            if (question.items.length < 2) {
                alert('Please add at least 2 items to order');
                return;
            }
            break;
            
        case 'calculated':
        case 'calculatedsimple':
            question.formula = document.getElementById('calculatedFormula')?.value;
            question.tolerance = document.getElementById('calculatedTolerance')?.value || 0.01;
            break;
            
        default:
            // For advanced types not yet fully supported
            break;
    }

    if (isEditingExistingPersistent || existingOriginalId) {
        question.isNew = true;
        question.questionbankentryid = null;
        question.originalQuestionId = existingOriginalId;
    }
    
    if (editingQuestionIndex !== null) {
        quizQuestions[editingQuestionIndex] = question;
        editingQuestionIndex = null;
    } else {
        quizQuestions.push(question);
    }
    updateQuizQuestionsList();
    
    const isAiFlow = aiGeneratedType === typeId && aiGeneratedCurrentSuggestion !== null;
    if (isAiFlow) {
        aiGeneratedAccepted++;
        if (aiGeneratedQueue.length > 0) {
            loadNextAiSuggestion('Saved suggestion.');
            return;
        } else {
            clearAiQueue('All AI-generated suggestions processed.', 'success');
        }
    } else {
        clearAiQueue(null);
    }
    
    // Reset form
    document.getElementById('questionBuilderForm').classList.remove('active');
    document.querySelectorAll('.question-type-card').forEach(card => card.classList.remove('selected'));
    updateAiGeneratorStatus('Question saved to quiz.', 'success');
}

// Collect ordering items
function collectOrderingItems() {
    const items = [];
    
    for (let i = 0; i < orderItemCount; i++) {
        const itemInput = document.getElementById(`order_item_${i}`);
        
        if (itemInput && itemInput.value.trim()) {
            items.push({
                text: itemInput.value,
                order: i + 1
            });
        }
    }
    
    return items;
}

function initializeGapselectFields(prefill) {
    gapselectGroupCount = 0;
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div class="gapselect-controls" style="margin-bottom: 12px;">
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="gapselectShuffle" checked>
                Shuffle choices inside each dropdown
            </label>
            <p style="font-size: 13px; color: #6b7280; margin-top: 8px;">
                Use placeholders like <code>[[1]]</code>, <code>[[2]]</code> inside the question text above to mark each blank.
            </p>
        </div>
        <div id="gapselectGroupsContainer"></div>
        <button type="button" class="btn-cancel" onclick="addGapselectGroup()" style="margin-top: 12px;">
            <i class="fa fa-plus"></i> Add Blank
        </button>
    `;

    if (prefill && Array.isArray(prefill.groups) && prefill.groups.length > 0) {
        document.getElementById('gapselectShuffle').checked = !!prefill.shuffle;
        prefill.groups.forEach(group => addGapselectGroup(group));
    } else {
        addGapselectGroup();
        addGapselectGroup();
    }
}

function addGapselectGroup(prefill) {
    gapselectGroupCount++;
    const slot = gapselectGroupCount;
    const container = document.getElementById('gapselectGroupsContainer');
    if (!container) {
        return;
    }

    const groupDiv = document.createElement('div');
    groupDiv.className = 'gapselect-group-card';
    groupDiv.dataset.slot = slot;
    groupDiv.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <strong>Blank [[${slot}]]</strong>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn-small" onclick="insertGapPlaceholder(${slot})" title="Insert [[${slot}]] into the question text">
                    <i class="fa fa-level-down-alt"></i> Insert placeholder
                </button>
                ${slot > 1 ? `<button type="button" class="btn-small danger" onclick="removeGapselectGroup(${slot})"><i class="fa fa-trash"></i></button>` : ''}
            </div>
        </div>
        <div class="gapselect-row">
            <label>Correct answer</label>
            <input type="text" class="form-input gapselect-correct" placeholder="Correct word or phrase">
        </div>
        <div class="gapselect-row">
            <label>Distractors (comma-separated)</label>
            <input type="text" class="form-input gapselect-distractors" placeholder="e.g., option 1, option 2, option 3">
            <small style="color:#6b7280;">Leave blank to auto-generate later.</small>
        </div>
    `;

    container.appendChild(groupDiv);

    if (prefill) {
        const correctInput = groupDiv.querySelector('.gapselect-correct');
        const distractorInput = groupDiv.querySelector('.gapselect-distractors');
        if (correctInput) {
            correctInput.value = prefill.correct || '';
        }
        if (distractorInput && Array.isArray(prefill.distractors)) {
            distractorInput.value = prefill.distractors.join(', ');
        }
    }
}

function removeGapselectGroup(slot) {
    const remaining = collectGapselectGroups(true).filter(group => group.slot !== slot);
    initializeGapselectFields({
        shuffle: document.getElementById('gapselectShuffle')?.checked,
        groups: remaining
    });
}

function collectGapselectGroups(includeEmpty = false) {
    const groups = [];
    document.querySelectorAll('.gapselect-group-card').forEach(card => {
        const slot = parseInt(card.dataset.slot, 10);
        const correctInput = card.querySelector('.gapselect-correct');
        const distractorInput = card.querySelector('.gapselect-distractors');
        const correct = correctInput?.value.trim() || '';
        const distractors = (distractorInput?.value || '')
            .split(',')
            .map(item => item.trim())
            .filter(Boolean);
        if (correct || includeEmpty) {
            groups.push({
                slot,
                correct,
                distractors
            });
        }
    });
    return groups;
}

function populateGapselectFromAI(payload) {
    const groups = Array.isArray(payload?.gaps)
        ? payload.gaps.map((gap, index) => ({
            slot: index + 1,
            correct: gap.answer || '',
            distractors: Array.isArray(gap.distractors) ? gap.distractors : []
        }))
        : [];
    initializeGapselectFields({
        shuffle: payload?.shuffleanswers ?? true,
        groups
    });
}

function populateGapselectFromExisting(question) {
    if (!question || !question.gapselect) {
        initializeGapselectFields();
        return;
    }
    initializeGapselectFields({
        shuffle: question.gapselect.shuffle,
        groups: question.gapselect.groups || []
    });
}

function insertGapPlaceholder(slot) {
    const textarea = document.getElementById('newQuestionText');
    if (!textarea) {
        return;
    }
    const placeholder = ` [[${slot}]] `;
    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? textarea.value.length;
    const text = textarea.value;
    textarea.value = text.slice(0, start) + placeholder + text.slice(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
}

// DDWTOS (Drag-Drop Words) functions
function generateDdwtosQuestionWithAI(payload, wrapper) {
    const formData = new URLSearchParams();
    formData.append('topic', payload.topic);
    formData.append('difficulty', payload.difficulty || '');
    formData.append('count', payload.count || 1);
    formData.append('blanks', Math.max(2, Math.min(6, payload.numoptions || 3)));
    if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }

    fetch('generate_ddwtos_ai.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            if (!data || !data.success) {
                updateAiGeneratorStatus(data && data.message ? data.message : 'AI generation failed. Please try again.', 'error');
                return;
            }
            const questionsPayload = Array.isArray(data.questions) ? data.questions : [];
            if (!questionsPayload.length) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz('ddwtos', questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        })
        .catch(error => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            console.error('DDWTOS AI error:', error);
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
        });
}

function generateDdimageortextQuestionWithAI(payload, wrapper) {
    const formData = new URLSearchParams();
    formData.append('topic', payload.topic);
    formData.append('difficulty', payload.difficulty || '');
    formData.append('count', payload.count || 1);
    formData.append('drops', Math.max(2, Math.min(5, payload.numoptions || 3)));
    if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }

    fetch('generate_ddimageortext_ai.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            if (!data || !data.success) {
                updateAiGeneratorStatus(data && data.message ? data.message : 'AI generation failed. Please try again.', 'error');
                return;
            }
            const questionsPayload = Array.isArray(data.questions) ? data.questions : [];
            if (!questionsPayload.length) {
                updateAiGeneratorStatus('AI did not return any questions. Please refine the topic and try again.', 'error');
                return;
            }
            let addedCount = 0;
            questionsPayload.forEach(questionPayload => {
                if (addAiQuestionToQuiz('ddimageortext', questionPayload)) {
                    addedCount++;
                }
            });
            updateQuizQuestionsList();
            const message = addedCount > 0
                ? `${addedCount} AI-generated question${addedCount > 1 ? 's were' : ' was'} added to the quiz.`
                : 'AI did not produce any usable questions. Please refine the prompt.';
            updateAiGeneratorStatus(message, addedCount > 0 ? 'success' : 'error');
        })
        .catch(error => {
            if (wrapper) {
                wrapper.classList.remove('loading');
            }
            console.error('DDImageOrText AI error:', error);
            updateAiGeneratorStatus('AI generation failed. Please try again later.', 'error');
        });
}

let ddwtosGroupCount = 0;
function initializeDdwtosFields(prefill) {
    ddwtosGroupCount = 0;
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div class="gapselect-controls" style="margin-bottom: 12px;">
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="ddwtosShuffle" checked>
                Shuffle draggable items
            </label>
            <p style="font-size: 13px; color: #6b7280; margin-top: 8px;">
                Use placeholders like <code>[[1]]</code>, <code>[[2]]</code> inside the question text above to mark each blank.
            </p>
            <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px;">
                <button type="button" class="btn-small secondary" onclick="diagnoseDdwtosQuestion()">
                    <i class="fa fa-stethoscope"></i> Diagnose placeholders
                </button>
                <button type="button" class="btn-small" onclick="addDdwtosGroup()">
                    <i class="fa fa-plus"></i> Add Blank
                </button>
            </div>
            <div id="ddwtosDiagnostics" class="ddwtos-diagnostics" style="display:none;margin-top:8px;padding:8px;border:1px dashed #cbd5f5;border-radius:6px;background:#f8fafc;"></div>
        </div>
        <div id="ddwtosGroupsContainer"></div>
    `;

    if (prefill && Array.isArray(prefill.groups) && prefill.groups.length > 0) {
        document.getElementById('ddwtosShuffle').checked = !!prefill.shuffle;
        prefill.groups.forEach(group => addDdwtosGroup(group));
    } else {
        addDdwtosGroup();
        addDdwtosGroup();
    }
}

function addDdwtosGroup(prefill) {
    let slot;
    if (prefill && typeof prefill.slot === 'number' && prefill.slot > 0) {
        slot = prefill.slot;
        ddwtosGroupCount = Math.max(ddwtosGroupCount, slot);
    } else {
        slot = ++ddwtosGroupCount;
    }
    const container = document.getElementById('ddwtosGroupsContainer');
    if (!container) {
        return;
    }

    const groupDiv = document.createElement('div');
    groupDiv.className = 'gapselect-group-card';
    groupDiv.dataset.slot = slot;
    groupDiv.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <strong>Blank [[${slot}]]</strong>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn-small" onclick="insertGapPlaceholder(${slot})" title="Insert [[${slot}]] into the question text">
                    <i class="fa fa-level-down-alt"></i> Insert placeholder
                </button>
                ${slot > 1 ? `<button type="button" class="btn-small danger" onclick="removeDdwtosGroup(${slot})"><i class="fa fa-trash"></i></button>` : ''}
            </div>
        </div>
        <div class="gapselect-row">
            <label>Correct answer</label>
            <input type="text" class="form-input gapselect-correct" placeholder="Correct word or phrase">
        </div>
        <div class="gapselect-row">
            <label>Distractors (comma-separated)</label>
            <input type="text" class="form-input gapselect-distractors" placeholder="e.g., option 1, option 2, option 3">
            <small style="color:#6b7280;">Leave blank to auto-generate later.</small>
        </div>
    `;

    container.appendChild(groupDiv);

    if (prefill) {
        const correctInput = groupDiv.querySelector('.gapselect-correct');
        const distractorInput = groupDiv.querySelector('.gapselect-distractors');
        if (correctInput) {
            correctInput.value = prefill.correct || '';
        }
        if (distractorInput && Array.isArray(prefill.distractors)) {
            distractorInput.value = prefill.distractors.join(', ');
        }
    }
}

function removeDdwtosGroup(slot) {
    const remaining = collectDdwtosGroups(true).filter(group => group.slot !== slot);
    initializeDdwtosFields({
        shuffle: document.getElementById('ddwtosShuffle')?.checked,
        groups: remaining
    });
}

function collectDdwtosGroups(includeEmpty = false) {
    const groups = [];
    const container = document.getElementById('ddwtosGroupsContainer');
    if (!container) {
        return groups;
    }
    container.querySelectorAll('.gapselect-group-card').forEach(card => {
        const slot = parseInt(card.dataset.slot, 10);
        const correctInput = card.querySelector('.gapselect-correct');
        const distractorInput = card.querySelector('.gapselect-distractors');
        const correct = correctInput?.value.trim() || '';
        const distractors = (distractorInput?.value || '')
            .split(',')
            .map(item => item.trim())
            .filter(Boolean);
        if (correct || includeEmpty) {
            groups.push({
                slot,
                correct,
                distractors
            });
        }
    });
    return groups;
}

function diagnoseDdwtosQuestion() {
    const diagnosticsBox = document.getElementById('ddwtosDiagnostics');
    if (!diagnosticsBox) {
        return;
    }
    const questionTextField = document.getElementById('newQuestionText');
    const questionText = questionTextField ? questionTextField.value : '';
    const placeholderMatches = [...questionText.matchAll(/\[\[(\d+)]]/g)];
    const placeholders = placeholderMatches.map(match => parseInt(match[1], 10)).filter(Number.isFinite);
    const groups = collectDdwtosGroups(true);
    const groupSlots = groups
        .map(group => (typeof group.slot === 'number' ? group.slot : parseInt(group.slot, 10)))
        .filter(slot => Number.isFinite(slot) && slot > 0);

    const placeholderSet = new Set(placeholders);
    const groupSlotSet = new Set(groupSlots);

    const missingGroups = placeholders.filter(slot => !groupSlotSet.has(slot));
    const unusedGroups = groupSlots.filter(slot => !placeholderSet.has(slot));

    const lines = [];
    lines.push(`<strong>Question placeholders:</strong> ${placeholders.length ? placeholders.join(', ') : 'none detected'}`);
    lines.push(`<strong>Groups defined in builder:</strong> ${groups.length}`);
    groups.forEach(group => {
        const slotLabel = group.slot ? `[[${group.slot}]]` : '[[?]]';
        const correctLabel = group.correct ? group.correct : '<em>(missing correct answer)</em>';
        const distractorCount = Array.isArray(group.distractors) ? group.distractors.length : 0;
        lines.push(`${slotLabel} → ${correctLabel} (${distractorCount} distractor${distractorCount === 1 ? '' : 's'})`);
    });
    if (missingGroups.length) {
        lines.push(`<strong>Missing groups for placeholders:</strong> ${missingGroups.join(', ')}`);
    }
    if (unusedGroups.length) {
        lines.push(`<strong>Groups without matching placeholder:</strong> ${unusedGroups.join(', ')}`);
    }
    if (!missingGroups.length && !unusedGroups.length && placeholders.length === groups.length) {
        lines.push('<span style="color:#059669;">All placeholders have matching groups.</span>');
    }

    diagnosticsBox.innerHTML = lines.map(line => `<div>${line}</div>`).join('');
    diagnosticsBox.style.display = 'block';
}

function populateDdwtosFromAI(payload) {
    const groups = Array.isArray(payload?.gaps)
        ? payload.gaps.map((gap, index) => ({
            slot: index + 1,
            correct: gap.answer || '',
            distractors: Array.isArray(gap.distractors) ? gap.distractors : []
        }))
        : [];
    initializeDdwtosFields({
        shuffle: payload?.shuffleanswers ?? true,
        groups
    });
}

function populateDdwtosFromExisting(question) {
    if (!question || !question.ddwtos) {
        initializeDdwtosFields();
        return;
    }
    initializeDdwtosFields({
        shuffle: question.ddwtos.shuffle,
        groups: question.ddwtos.groups || []
    });
}

// DDImageOrText functions
let ddimageortextDropCount = 0;
function initializeDdimageortextFields(prefill) {
    ddimageortextDropCount = 0;
    const answersSection = document.getElementById('answersSection');
    answersSection.innerHTML = `
        <div class="gapselect-controls" style="margin-bottom: 12px;">
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="ddimageortextShuffle" checked>
                Shuffle draggable items
            </label>
            <p style="font-size: 13px; color: #6b7280; margin-top: 8px;">
                <strong>Note:</strong> This question type requires a background image to be uploaded separately in Moodle's question bank interface.
                The AI will generate the text labels and drop zones, but you'll need to position them manually on the image.
            </p>
        </div>
        <div id="ddimageortextDropsContainer"></div>
        <button type="button" class="btn-cancel" onclick="addDdimageortextDrop()" style="margin-top: 12px;">
            <i class="fa fa-plus"></i> Add Drop Zone
        </button>
    `;

    if (prefill && Array.isArray(prefill.drops) && prefill.drops.length > 0) {
        document.getElementById('ddimageortextShuffle').checked = !!prefill.shuffle;
        prefill.drops.forEach(drop => addDdimageortextDrop(drop));
    } else {
        addDdimageortextDrop();
        addDdimageortextDrop();
    }
}

function addDdimageortextDrop(prefill) {
    ddimageortextDropCount++;
    const dropIndex = ddimageortextDropCount;
    const container = document.getElementById('ddimageortextDropsContainer');
    if (!container) {
        return;
    }

    const dropDiv = document.createElement('div');
    dropDiv.className = 'gapselect-group-card';
    dropDiv.dataset.index = dropIndex;
    dropDiv.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <strong>Drop Zone ${dropIndex}</strong>
            ${dropIndex > 1 ? `<button type="button" class="btn-small danger" onclick="removeDdimageortextDrop(${dropIndex})"><i class="fa fa-trash"></i></button>` : ''}
        </div>
        <div class="gapselect-row">
            <label>Zone Label</label>
            <input type="text" class="form-input ddimage-label" placeholder="e.g., Zone 1, Location A">
        </div>
        <div class="gapselect-row">
            <label>Correct answer (text to drag here)</label>
            <input type="text" class="form-input gapselect-correct" placeholder="Correct word or phrase">
        </div>
        <div class="gapselect-row">
            <label>Distractors (comma-separated)</label>
            <input type="text" class="form-input gapselect-distractors" placeholder="e.g., option 1, option 2, option 3">
            <small style="color:#6b7280;">Leave blank to auto-generate later.</small>
        </div>
    `;

    container.appendChild(dropDiv);

    if (prefill) {
        const labelInput = dropDiv.querySelector('.ddimage-label');
        const correctInput = dropDiv.querySelector('.gapselect-correct');
        const distractorInput = dropDiv.querySelector('.gapselect-distractors');
        if (labelInput) {
            labelInput.value = prefill.label || '';
        }
        if (correctInput) {
            correctInput.value = prefill.correct || '';
        }
        if (distractorInput && Array.isArray(prefill.distractors)) {
            distractorInput.value = prefill.distractors.join(', ');
        }
    }
}

function removeDdimageortextDrop(index) {
    const remaining = collectDdimageortextDrops(true).filter(drop => drop.index !== index);
    initializeDdimageortextFields({
        shuffle: document.getElementById('ddimageortextShuffle')?.checked,
        drops: remaining
    });
}

function collectDdimageortextDrops(includeEmpty = false) {
    const drops = [];
    const container = document.getElementById('ddimageortextDropsContainer');
    if (!container) {
        return drops;
    }
    container.querySelectorAll('.gapselect-group-card').forEach(card => {
        const index = parseInt(card.dataset.index, 10);
        const labelInput = card.querySelector('.ddimage-label');
        const correctInput = card.querySelector('.gapselect-correct');
        const distractorInput = card.querySelector('.gapselect-distractors');
        const label = labelInput?.value.trim() || `Zone ${index}`;
        const correct = correctInput?.value.trim() || '';
        const distractors = (distractorInput?.value || '')
            .split(',')
            .map(item => item.trim())
            .filter(Boolean);
        if (correct || includeEmpty) {
            drops.push({
                index,
                label,
                correct,
                distractors
            });
        }
    });
    return drops;
}

function populateDdimageortextFromAI(payload) {
    const drops = Array.isArray(payload?.drops)
        ? payload.drops.map((drop, index) => ({
            index: index + 1,
            label: drop.label || `Zone ${index + 1}`,
            correct: drop.correct || '',
            distractors: Array.isArray(drop.distractors) ? drop.distractors : []
        }))
        : [];
    initializeDdimageortextFields({
        shuffle: payload?.shuffleanswers ?? true,
        drops
    });
}

function populateDdimageortextFromExisting(question) {
    if (!question || !question.ddimageortext) {
        initializeDdimageortextFields();
        return;
    }
    initializeDdimageortextFields({
        shuffle: question.ddimageortext.shuffle,
        drops: question.ddimageortext.drops || []
    });
}

// Collect short answers
function collectShortAnswers() {
    const answers = [];
    
    document.querySelectorAll('[id^="sa_answer_"]').forEach(input => {
        if (input.value.trim()) {
            answers.push({
                text: input.value,
                fraction: 1 // All are correct answers
            });
        }
    });
    
    return answers;
}

// Collect match pairs
function collectMatchPairs() {
    const pairs = [];
    
    for (let i = 0; i < matchPairCount; i++) {
        const questionInput = document.getElementById(`match_question_${i}`);
        const answerInput = document.getElementById(`match_answer_${i}`);
        
        if (questionInput && answerInput && questionInput.value.trim() && answerInput.value.trim()) {
            pairs.push({
                question: questionInput.value,
                answer: answerInput.value
            });
        }
    }
    
    return pairs;
}

function populateMatchPairsFromExisting(question) {
    const pairsList = document.getElementById('matchPairsList');
    if (!pairsList) {
        return;
    }
    pairsList.innerHTML = '';
    matchPairCount = 0;
    const pairs = Array.isArray(question.pairs) ? question.pairs : [];
    if (pairs.length === 0) {
        addMatchPair();
        addMatchPair();
        return;
    }
    pairs.forEach(pair => {
        addMatchPair({
            question: pair.question || '',
            answer: pair.answer || ''
        });
    });
}

// Collect multichoice answers
function collectMultichoiceAnswers() {
    const answers = [];
    const correctAnswerId = document.querySelector('input[name="correct_answer"]:checked')?.value;
    
    document.querySelectorAll('[id^="answer_"]').forEach(input => {
        const answerId = input.id.replace('answer_', '');
        if (input.value.trim()) {
            answers.push({
                text: input.value,
                fraction: answerId === correctAnswerId ? 1 : 0
            });
        }
    });
    
    return answers;
}

// Load question categories
function loadQuestionCategories(courseid) {
    fetch('get_question_categories.php?courseid=' + courseid + '&sesskey=' + document.querySelector('input[name="sesskey"]').value)
        .then(response => response.json())
        .then(data => {
            const categorySelect = document.getElementById('questionCategory');
            categorySelect.innerHTML = '<option value="">All categories</option>';
            
            if (data.success && data.categories) {
                data.categories.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat.id;
                    option.textContent = cat.name;
                    categorySelect.appendChild(option);
                });
            }
            
            // Automatically load questions with "All categories" selected
            loadQuestionBank();
        })
        .catch(error => {
            console.error('Error loading categories:', error);
        });
}

// Load question bank
function loadQuestionBank() {
    const courseid = document.getElementById('quizCourse').value;
    const categoryid = document.getElementById('questionCategory').value;
    
    if (!courseid) {
        return;
    }
    
    const listContainer = document.getElementById('questionBankList');
    listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;"><i class="fa fa-spinner fa-spin"></i> Loading questions...</div>';
    
    let url = 'get_question_bank.php?courseid=' + courseid + '&sesskey=' + document.querySelector('input[name="sesskey"]').value;
    if (categoryid) {
        url += '&categoryid=' + categoryid;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.questions && data.questions.length > 0) {
                renderQuestionBank(data.questions);
            } else {
                listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;"><i class="fa fa-info-circle"></i><br><br>No questions found in this course.<br><small>Create questions first or select a different category.</small></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;"><i class="fa fa-exclamation-circle"></i><br><br>Error loading questions<br><small>' + error.message + '</small></div>';
        });
}

// Render question bank
function renderQuestionBank(questions) {
    const listContainer = document.getElementById('questionBankList');
    listContainer.innerHTML = '';
    
    questions.forEach(q => {
        const item = document.createElement('div');
        item.className = 'question-item';
        item.dataset.questionId = q.id;
        item.dataset.questionType = q.qtype;
        item.dataset.questionBankEntryId = q.questionbankentryid || '';
        
        const textPreview = q.questiontext.replace(/<[^>]*>/g, '').substring(0, 100);
        
        item.innerHTML = `
            <input type="checkbox" class="question-checkbox" value="${q.id}">
            <div class="question-details">
                <div class="question-name">${q.name}</div>
                <span class="question-type-badge">${q.qtype}</span>
                <div class="question-text-preview">${textPreview}${textPreview.length >= 100 ? '...' : ''}</div>
                <span class="question-marks">${q.defaultmark} mark(s)</span>
            </div>
        `;
        
        item.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.question-checkbox');
                checkbox.checked = !checkbox.checked;
            }
            this.classList.toggle('selected', this.querySelector('.question-checkbox').checked);
        };
        
        listContainer.appendChild(item);
    });
}

// Filter questions by type
function filterQuestionsByType() {
    const selectedType = document.getElementById('questionTypeFilter').value;
    document.querySelectorAll('.question-item').forEach(item => {
        if (!selectedType || item.dataset.questionType === selectedType) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Search questions
function searchQuestions() {
    const query = document.getElementById('questionSearch').value.toLowerCase();
    document.querySelectorAll('.question-item').forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(query) ? '' : 'none';
    });
}

// Add selected questions to quiz
function addSelectedQuestions() {
    const selected = document.querySelectorAll('.question-checkbox:checked');
    
    if (selected.length === 0) {
        alert('Please select at least one question');
        return;
    }
    
    selected.forEach(checkbox => {
        const item = checkbox.closest('.question-item');
        const questionId = item.dataset.questionId;
        const questionBankEntryId = item.dataset.questionBankEntryId ? parseInt(item.dataset.questionBankEntryId, 10) : null;
        
        // Check if already added
        if (!quizQuestions.find(q => q.id == questionId)) {
            const questionName = item.querySelector('.question-name').textContent;
            const questionType = item.dataset.questionType;
            const questionMark = item.querySelector('.question-marks').textContent.split(' ')[0];
            
            quizQuestions.push({
                id: questionId,
                name: questionName,
                qtype: questionType,
                defaultmark: questionMark,
                isNew: false,
                detailsLoaded: false,
                questionbankentryid: questionBankEntryId,
                slot: quizQuestions.length + 1,
                page: Math.ceil(quizQuestions.length / (document.getElementById('questionsPerPage').value || 1))
            });
        }
        
        checkbox.checked = false;
        item.classList.remove('selected');
    });
    
    updateQuizQuestionsList();
}

// Update quiz questions list
function updateQuizQuestionsList() {
    // Find all instances of the questions panel (in Import, Create, and Existing modes) using classes
    const containers = document.querySelectorAll('.quiz-questions-container');
    const lists = document.querySelectorAll('.quiz-questions-list');
    const badges = document.querySelectorAll('.questions-count-badge');
    const totalMarksEls = document.querySelectorAll('#totalMarks');
    const emptyMessages = document.querySelectorAll('#existingQuestionsEmpty');
    
    if (quizQuestions.length === 0) {
        containers.forEach(container => {
            if (container) container.style.display = 'none';
        });
        emptyMessages.forEach(emptyMessage => {
            if (emptyMessage) emptyMessage.style.display = 'flex';
        });
        return;
    }
    
    containers.forEach(container => {
        if (container) container.style.display = 'block';
    });
    emptyMessages.forEach(emptyMessage => {
        if (emptyMessage) emptyMessage.style.display = 'none';
    });
    
    let totalMarks = 0;
    quizQuestions.forEach((q, index) => {
        totalMarks += parseFloat(q.defaultmark);
    });
    
    // Update all lists with the same content
    lists.forEach(list => {
        if (list) {
            list.innerHTML = '';
            quizQuestions.forEach((q, index) => {
                const questionTitle = getQuestionDisplayName(q, index);
                
                const item = document.createElement('div');
                item.className = 'quiz-question-item';
                item.innerHTML = `
                    <span class="question-drag-handle"><i class="fa fa-grip-vertical"></i></span>
                    <span class="question-item-number">${index + 1}.</span>
                    <div class="question-item-info">
                        <div class="question-item-name">${questionTitle}</div>
                        <div class="question-item-type">${getQuestionMetaDescription(q)}</div>
                    </div>
                    <div class="question-item-actions">
                        <button type="button" class="btn-icon" onclick="editQuestion(${index})" title="Edit">
                            <i class="fa fa-edit"></i>
                            <span>Edit</span>
                        </button>
                        <button type="button" class="btn-icon" onclick="removeQuestion(${index})" title="Remove">
                            <i class="fa fa-trash delete"></i>
                            <span>Delete</span>
                        </button>
                    </div>
                `;
                list.appendChild(item);
            });
        }
    });
    
    // Update all badges and total marks
    badges.forEach(badge => {
        if (badge) badge.textContent = `${quizQuestions.length} question${quizQuestions.length !== 1 ? 's' : ''}`;
    });
    totalMarksEls.forEach(totalMarksEl => {
        if (totalMarksEl) totalMarksEl.textContent = totalMarks.toFixed(1);
    });
}

// Remove question
function removeQuestion(index) {
    if (confirm('Remove this question from the quiz?')) {
        quizQuestions.splice(index, 1);
        updateQuizQuestionsList();
    }
}

function editQuestion(index) {
    const question = quizQuestions[index];
    if (!question) {
        return;
    }
    if (shouldFetchQuestionDetails(question)) {
        fetchQuestionDetails(question, index);
        return;
    }
    loadQuestionIntoBuilder(question, index);
}

function shouldFetchQuestionDetails(question) {
    if (!question) {
        return false;
    }
    if ((question.isNew && !question.originalQuestionId) || question.detailsLoaded) {
        return false;
    }
    if ((!question.id || String(question.id).startsWith('new_')) && !question.originalQuestionId && !question.slot) {
        return false;
    }
    return true;
}

function toggleExistingQuestionsLoader(show) {
    const loader = document.getElementById('existingQuestionsLoader');
    if (!loader) {
        return;
    }
    loader.style.display = show ? 'flex' : 'none';
}

function fetchQuestionDetails(question, index) {
    if (!question || question.detailsLoading) {
        return;
    }
    if (!CURRENT_SESSKEY || !CURRENT_CMID || !CURRENT_QUIZ_ID) {
        console.error('Missing context data for fetchQuestionDetails', {
            CURRENT_SESSKEY,
            CURRENT_CMID,
            CURRENT_QUIZ_ID
        });
        showNotification('Missing context data. Please refresh and try again.', 'error');
        return;
    }
    const questionIdForFetch = (() => {
        if (question.id && !String(question.id).startsWith('new_')) {
            return question.id;
        }
        if (question.originalQuestionId) {
            return question.originalQuestionId;
        }
        return null;
    })();

    if (!questionIdForFetch && !question.slot) {
        console.error('Cannot fetch question details: missing both questionid and slot', question);
        showNotification('Cannot load question details: missing question identifier.', 'error');
        return;
    }

    question.detailsLoading = true;
    toggleExistingQuestionsLoader(true);
    const url = new URL('get_question_details.php', window.location.href);
    url.searchParams.set('sesskey', CURRENT_SESSKEY);
    url.searchParams.set('cmid', CURRENT_CMID);
    url.searchParams.set('quizid', CURRENT_QUIZ_ID);
    url.searchParams.set('debug', 1); // remove or set to 0 after debugging

    if (questionIdForFetch) {
        url.searchParams.set('questionid', questionIdForFetch);
    }
    
    if (question.slot) {
        url.searchParams.set('slot', question.slot);
    }
    fetch(url.toString())
        .then(response => response.json())
        .then(data => {
            question.detailsLoading = false;
            toggleExistingQuestionsLoader(false);
            if (data.success && data.question) {
                if (data.question.editable === false) {
                    showNotification(data.question.message || 'This question type must be edited from the standard Question Bank.', 'info');
                    return;
                }
                applyQuestionData(question, data.question, {detailsLoaded: true, triggerRefresh: true});
                loadQuestionIntoBuilder(question, index);
            } else {
                const errorMsg = data.message || 'Unable to load question details.';
                const errorCode = data.errorcode ? ` (${data.errorcode})` : '';
                console.error('get_question_details failed:', data);
                if (data.debug) {
                    console.error('Debug info:', data.debug);
                    console.error('Exception:', data.debug.exception, 'at', data.debug.file + ':' + data.debug.line);
                    console.error('Params:', data.debug.params);
                }
                showNotification(errorMsg + errorCode, 'error');
            }
        })
        .catch(error => {
            question.detailsLoading = false;
            toggleExistingQuestionsLoader(false);
            showNotification('Unable to load question details: ' + error.message, 'error');
        });
}

function prefetchQuestionSummaries() {
    if (!Array.isArray(quizQuestions) || quizQuestions.length === 0) {
        return;
    }
    quizQuestions.forEach((question, index) => {
        if (shouldFetchQuestionSummary(question)) {
            fetchQuestionSummary(question, index);
        }
    });
}

function shouldFetchQuestionSummary(question) {
    if (!question) {
        return false;
    }
    const hasName = typeof question.name === 'string' && question.name.trim().length > 0;
    const hasText = typeof question.questiontext === 'string' && question.questiontext.trim().length > 0;
    const hasType = (typeof question.qtype === 'string' && question.qtype.trim().length > 0 && question.qtype !== 'Unknown type')
        || (question.metadata && typeof question.metadata.qtype === 'string' && question.metadata.qtype.trim().length > 0);
    return !(hasName && hasText && hasType);
}

function fetchQuestionSummary(question, index) {
    if (!question || question.summaryLoading || !CURRENT_SESSKEY || !CURRENT_CMID || !CURRENT_QUIZ_ID) {
        return;
    }
    question.summaryLoading = true;
    const url = new URL('get_question_details.php', window.location.href);
    url.searchParams.set('questionid', question.id);
    url.searchParams.set('sesskey', CURRENT_SESSKEY);
    url.searchParams.set('cmid', CURRENT_CMID);
    url.searchParams.set('quizid', CURRENT_QUIZ_ID);
    if (question.slot) {
        url.searchParams.set('slot', question.slot);
    }
    fetch(url.toString())
        .then(response => response.json())
        .then(data => {
            question.summaryLoading = false;
            if (data.success && data.question) {
                applyQuestionData(question, data.question, {triggerRefresh: true});
            }
        })
        .catch(() => {
            question.summaryLoading = false;
        });
}

function applyQuestionData(targetQuestion, sourceData, options = {}) {
    if (!targetQuestion || !sourceData) {
        return;
    }
    targetQuestion.metadata = sourceData;
    if ((!targetQuestion.name || !targetQuestion.name.trim()) && sourceData.name) {
        targetQuestion.name = sourceData.name;
    }
    if ((!targetQuestion.questiontext || !targetQuestion.questiontext.trim()) && sourceData.questiontext) {
        targetQuestion.questiontext = sourceData.questiontext;
    }
    if (!targetQuestion.qtype && sourceData.qtype) {
        targetQuestion.qtype = sourceData.qtype;
    }
    if ((targetQuestion.defaultmark === undefined || targetQuestion.defaultmark === null) && typeof sourceData.defaultmark !== 'undefined') {
        targetQuestion.defaultmark = sourceData.defaultmark;
    }
    if ((!targetQuestion.questionbankentryid || targetQuestion.questionbankentryid === null) && sourceData.questionbankentryid) {
        targetQuestion.questionbankentryid = sourceData.questionbankentryid;
    }
    if (Array.isArray(sourceData.answers)) {
        targetQuestion.answers = sourceData.answers;
    }
    if (sourceData.answer !== undefined) {
        targetQuestion.answer = sourceData.answer;
    }
    if (Array.isArray(sourceData.items)) {
        targetQuestion.items = sourceData.items;
    }
    if (sourceData.pairs !== undefined) {
        targetQuestion.pairs = sourceData.pairs;
    }
    if (sourceData.gapselect !== undefined) {
        targetQuestion.gapselect = sourceData.gapselect;
    }
    if (sourceData.ddwtos !== undefined) {
        targetQuestion.ddwtos = sourceData.ddwtos;
    }
    if (sourceData.ddimageortext !== undefined) {
        targetQuestion.ddimageortext = sourceData.ddimageortext;
    }
    if (!targetQuestion.originalQuestionId && sourceData.id) {
        targetQuestion.originalQuestionId = sourceData.id;
    }
    if (options.detailsLoaded) {
        targetQuestion.detailsLoaded = true;
    }
    if (options.triggerRefresh) {
        updateQuizQuestionsList();
    }
}

// Load competencies
function loadCompetencies(courseid) {
    console.log('loadCompetencies called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('competenciesLoading');
    const listDiv = document.getElementById('competenciesList');
    const noCompDiv = document.getElementById('noCompetencies');
    
    // Show loading
    loadingDiv.style.display = 'flex';
    listDiv.style.display = 'none';
    noCompDiv.style.display = 'none';
    listDiv.innerHTML = '';
    
    if (!courseid) {
        console.log('No courseid provided, showing no competencies message');
        loadingDiv.style.display = 'none';
        noCompDiv.style.display = 'flex';
        return;
    }
    
    const apiUrl = `get_course_competencies.php?courseid=${courseid}`;
    console.log('Fetching competencies from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => {
            console.log('Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Competencies data:', data);
            loadingDiv.style.display = 'none';
            
            if (data.success && data.competencies && data.competencies.length > 0) {
                console.log('Rendering', data.competencies.length, 'competencies');
                // Store competencies globally for searching
                window.allCompetencies = data.competencies;
                renderCompetencies(data.competencies);
                listDiv.style.display = 'flex';
                // Show the search bar
                document.getElementById('competencySearchContainer').style.display = 'block';
                // Initialize search functionality
                initializeCompetencySearch();
            } else {
                console.log('No competencies found or error occurred');
                noCompDiv.style.display = 'flex';
                // Hide the search bar
                document.getElementById('competencySearchContainer').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading competencies:', error);
            loadingDiv.style.display = 'none';
            noCompDiv.style.display = 'flex';
        });
}

// Render competencies as tree structure
function renderCompetencies(competencies) {
    const listDiv = document.getElementById('competenciesList');
    listDiv.innerHTML = '';
    
    const tree = document.createElement('div');
    tree.className = 'competency-tree';
    
    competencies.forEach(comp => {
        const itemContainer = createCompetencyItem(comp);
        tree.appendChild(itemContainer);
    });
    
    listDiv.appendChild(tree);
    
    if (isQuizEditMode && quizEditData && Array.isArray(quizEditData.competencies) && quizEditData.competencies.length > 0) {
        setTimeout(() => {
            quizEditData.competencies.forEach(compId => {
                const checkbox = document.getElementById(`comp_${compId}`);
                if (checkbox && !checkbox.checked) {
                    checkbox.checked = true;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        }, 0);
    }
}

// Create a competency item with potential children
function createCompetencyItem(comp, level = 0) {
    const container = document.createElement('div');
    
    const item = document.createElement('div');
    item.className = 'competency-item';
    item.dataset.competencyId = comp.id;
    
    // Add toggle button if has children
    if (comp.children && comp.children.length > 0) {
        item.classList.add('has-children');
        
        const toggle = document.createElement('button');
        toggle.className = 'competency-toggle expanded';
        toggle.type = 'button';
        toggle.onclick = function() {
            toggleCompetencyChildren(comp.id);
        };
        item.appendChild(toggle);
    }
    
    // Checkbox
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'competencies[]';
    checkbox.value = comp.id;
    checkbox.className = 'competency-checkbox';
    checkbox.id = `comp_${comp.id}`;
    checkbox.dataset.competencyId = comp.id;
    
    // When parent is checked, check all children
    checkbox.addEventListener('change', function() {
        if (this.checked) {
            item.classList.add('selected');
            // Check all children recursively
            checkAllChildren(comp.id, true);
        } else {
            item.classList.remove('selected');
            // Uncheck all children recursively
            checkAllChildren(comp.id, false);
        }
    });
    
    item.appendChild(checkbox);
    
    // Details
    const details = document.createElement('div');
    details.className = 'competency-details';
    
    // Top row: Name and ID
    const topRow = document.createElement('div');
    topRow.className = 'competency-top-row';
    
    const nameLabel = document.createElement('label');
    nameLabel.className = 'competency-name';
    nameLabel.htmlFor = `comp_${comp.id}`;
    nameLabel.textContent = comp.shortname;
    nameLabel.style.cursor = 'pointer';
    
    // Show children count if parent
    if (comp.children && comp.children.length > 0) {
        nameLabel.textContent += ` (${comp.children.length} sub-competencies)`;
    }
    
    // Allow clicking on label to toggle checkbox
    nameLabel.addEventListener('click', function() {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
    });
    
    topRow.appendChild(nameLabel);
    
    if (comp.idnumber) {
        const idnumber = document.createElement('span');
        idnumber.className = 'competency-idnumber';
        idnumber.textContent = `ID: ${comp.idnumber}`;
        topRow.appendChild(idnumber);
    }
    
    details.appendChild(topRow);
    
    // Description (shortened)
    if (comp.description) {
        const description = document.createElement('div');
        description.className = 'competency-description';
        // Strip HTML tags for display
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = comp.description;
        const descText = tempDiv.textContent || tempDiv.innerText || '';
        // Truncate to 200 characters for parents, 150 for children
        const maxLength = (comp.children && comp.children.length > 0) ? 200 : 150;
        description.textContent = descText.length > maxLength ? descText.substring(0, maxLength) + '...' : descText;
        details.appendChild(description);
    }
    
    // Bottom row: Framework
    const bottomRow = document.createElement('div');
    bottomRow.className = 'competency-bottom-row';
    
    if (comp.framework) {
        const framework = document.createElement('span');
        framework.className = 'competency-framework';
        framework.textContent = comp.framework;
        bottomRow.appendChild(framework);
    }
    
    details.appendChild(bottomRow);
    item.appendChild(details);
    container.appendChild(item);
    
    // Render children if exists
    if (comp.children && comp.children.length > 0) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'competency-tree-children';
        childrenContainer.id = `children_${comp.id}`;
        
        comp.children.forEach(child => {
            const childItem = createCompetencyItem(child, level + 1);
            childrenContainer.appendChild(childItem);
        });
        
        container.appendChild(childrenContainer);
    }
    
    return container;
}

// Toggle competency children visibility
function toggleCompetencyChildren(competencyId) {
    const childrenContainer = document.getElementById(`children_${competencyId}`);
    const toggle = document.querySelector(`[data-competency-id="${competencyId}"] .competency-toggle`);
    
    if (childrenContainer && toggle) {
        if (childrenContainer.style.display === 'none') {
            childrenContainer.style.display = 'flex';
            toggle.classList.remove('collapsed');
            toggle.classList.add('expanded');
        } else {
            childrenContainer.style.display = 'none';
            toggle.classList.remove('expanded');
            toggle.classList.add('collapsed');
        }
    }
}

// Check/uncheck all children recursively
function checkAllChildren(parentId, checked) {
    const childrenContainer = document.getElementById(`children_${parentId}`);
    if (!childrenContainer) return;
    
    const childCheckboxes = childrenContainer.querySelectorAll('.competency-checkbox');
    childCheckboxes.forEach(checkbox => {
        checkbox.checked = checked;
        const item = checkbox.closest('.competency-item');
        if (checked) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
        
        // Recursively check children of this child
        const childId = checkbox.dataset.competencyId;
        checkAllChildren(childId, checked);
    });
}

// Initialize competency search functionality
function initializeCompetencySearch() {
    const searchInput = document.getElementById('competencySearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    
    if (!searchInput) return;
    
    // Search as user types (with debounce)
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        // Show/hide clear button
        clearBtn.style.display = query ? 'flex' : 'none';
        
        searchTimeout = setTimeout(() => {
            searchCompetencies(query);
        }, 300); // 300ms debounce
    });
    
    // Clear search
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        searchCompetencies(''); // Reset to show all
    });
    
    // Search on Enter key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchCompetencies(this.value.trim());
        }
    });
}

// Search competencies by name, ID, or description
function searchCompetencies(query) {
    if (!query) {
        // Show all competencies
        renderCompetencies(window.allCompetencies);
        return;
    }
    
    query = query.toLowerCase();
    
    // Recursively search competencies and their children
    function searchInCompetency(comp) {
        const matchesName = comp.shortname && comp.shortname.toLowerCase().includes(query);
        const matchesId = comp.idnumber && comp.idnumber.toLowerCase().includes(query);
        const matchesDesc = comp.description && stripHtml(comp.description).toLowerCase().includes(query);
        
        const matches = matchesName || matchesId || matchesDesc;
        
        let filteredChildren = [];
        if (comp.children && comp.children.length > 0) {
            filteredChildren = comp.children
                .map(child => searchInCompetency(child))
                .filter(child => child !== null);
        }
        
        // Include parent if it matches OR if any children match
        if (matches || filteredChildren.length > 0) {
            return {
                ...comp,
                children: filteredChildren
            };
        }
        
        return null;
    }
    
    // Filter all competencies
    const filteredCompetencies = window.allCompetencies
        .map(comp => searchInCompetency(comp))
        .filter(comp => comp !== null);
    
    // Update UI
    if (filteredCompetencies.length > 0) {
        renderCompetencies(filteredCompetencies);
    } else {
        document.getElementById('competenciesList').innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;"><i class="fa fa-search"></i><br>No competencies found matching "' + query + '"</div>';
    }
}

// Strip HTML tags from string
function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

function getQuestionDisplayName(question, index) {
    if (question) {
        if (typeof question.name === 'string' && question.name.trim().length > 0) {
            return question.name.trim();
        }
        if (question.metadata && typeof question.metadata.name === 'string' && question.metadata.name.trim().length > 0) {
            return question.metadata.name.trim();
        }
        if (typeof question.questiontext === 'string') {
            const sanitized = stripHtml(question.questiontext).trim();
            if (sanitized.length > 0) {
                return sanitized.substring(0, 80);
            }
        }
    }
    return `Question ${index + 1}`;
}

function getQuestionMetaDescription(question) {
    const type = question.qtype || question.metadata?.qtype || 'Unknown type';
    const marks = question.defaultmark !== undefined ? `${question.defaultmark} mark(s)` : (question.metadata?.defaultmark ? `${question.metadata.defaultmark} mark(s)` : '');
    const pageInfo = question.page ? `Page ${question.page}` : '';
    const text = question.metadata?.questiontext || question.questiontext;
    const description = text ? stripHtml(text).substr(0, 100) : '';
    return [type, marks, pageInfo, description].filter(Boolean).join(' • ');
}

// Toggle group selection visibility
function toggleGroupSelection() {
    const groupsRadio = document.getElementById('assignToGroups');
    const groupSelectionContainer = document.getElementById('groupSelectionContainer');
    
    if (groupsRadio && groupsRadio.checked) {
        groupSelectionContainer.style.display = 'block';
        // Load groups when container becomes visible
        const courseSelect = document.getElementById('quizCourse');
        if (courseSelect.value) {
            loadCourseGroups(courseSelect.value);
            loadCourseStudents(courseSelect.value);
        }
    } else {
        groupSelectionContainer.style.display = 'none';
    }
}

// Load course groups
function loadCourseGroups(courseid) {
    console.log('loadCourseGroups called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('groupsLoading');
    const listDiv = document.getElementById('groupsList');
    const noGroupsDiv = document.getElementById('noGroups');
    
    if (!courseid) {
        loadingDiv.style.display = 'none';
        listDiv.style.display = 'none';
        noGroupsDiv.style.display = 'none';
        return;
    }
    
    // Show loading
    loadingDiv.style.display = 'flex';
    listDiv.style.display = 'none';
    noGroupsDiv.style.display = 'none';
    listDiv.innerHTML = '';
    
    const apiUrl = `get_course_groups.php?courseid=${courseid}`;
    console.log('Fetching groups from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => {
            console.log('Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Groups data:', data);
            loadingDiv.style.display = 'none';
            
            if (data.success && data.groups && data.groups.length > 0) {
                console.log('Rendering', data.groups.length, 'groups');
                renderGroups(data.groups);
                listDiv.style.display = 'flex';
            } else {
                console.log('No groups found');
                noGroupsDiv.style.display = 'flex';
            }
        })
        .catch(error => {
            console.error('Error loading groups:', error);
            loadingDiv.style.display = 'none';
            noGroupsDiv.style.display = 'flex';
        });
}

// Render groups list
function renderGroups(groups) {
    const listDiv = document.getElementById('groupsList');
    listDiv.innerHTML = '';
    
    groups.forEach(group => {
        const item = document.createElement('div');
        item.className = 'group-item';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'group_ids[]';
        checkbox.value = group.id;
        checkbox.className = 'group-checkbox';
        checkbox.id = `group_${group.id}`;
        
        // Toggle item selection
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });
        
        const details = document.createElement('div');
        details.className = 'group-details';
        
        const nameLabel = document.createElement('label');
        nameLabel.className = 'group-name';
        nameLabel.htmlFor = `group_${group.id}`;
        nameLabel.textContent = group.name;
        nameLabel.style.cursor = 'pointer';
        
        // Allow clicking on label to toggle checkbox
        nameLabel.addEventListener('click', function() {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change'));
        });
        
        details.appendChild(nameLabel);
        
        // Description
        if (group.description) {
            const description = document.createElement('div');
            description.className = 'group-description';
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = group.description;
            const descText = tempDiv.textContent || tempDiv.innerText || '';
            description.textContent = descText.length > 100 ? descText.substring(0, 100) + '...' : descText;
            details.appendChild(description);
        }
        
        // Member count badge - clickable to open modal
        const memberCount = document.createElement('span');
        memberCount.className = 'group-member-count';
        memberCount.innerHTML = `<i class="fa fa-users"></i> ${group.membercount} member${group.membercount !== 1 ? 's' : ''}`;
        memberCount.style.cursor = 'pointer';
        memberCount.title = 'Click to view members';
        memberCount.dataset.groupId = group.id;
        memberCount.dataset.groupName = group.name;
        memberCount.dataset.memberCount = group.membercount;
        
        // Click on member count to open modal
        memberCount.addEventListener('click', function(e) {
            e.stopPropagation();
            openMembersModal(group.id, group.name, group.membercount);
        });
        
        details.appendChild(memberCount);
        
        item.appendChild(checkbox);
        item.appendChild(details);
        listDiv.appendChild(item);
    });
    
    if (isQuizEditMode && quizEditData && Array.isArray(quizEditData.assigned_groups) && quizEditData.assigned_groups.length > 0) {
        quizEditData.assigned_groups.forEach(groupId => {
            const checkbox = document.getElementById(`group_${groupId}`);
            if (checkbox && !checkbox.checked) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
        listDiv.style.display = 'flex';
        const container = document.getElementById('groupSelectionContainer');
        if (container) {
            container.style.display = 'block';
        }
    }
}

// Create quiz
function createQuiz() {
    const form = document.getElementById('quizForm');
    const formData = new FormData(form);
    const createBtn = document.getElementById('createBtn');
    
    // Validate
    if (!formData.get('name')) {
        alert('Please enter a quiz name');
        return;
    }
    
    if (!formData.get('courseid')) {
        alert('Please select a course');
        return;
    }
    
    if (!document.getElementById('selectedSection').value) {
        alert('Please select a section for the quiz');
        return;
    }
    
    if (quizQuestions.length === 0) {
        if (!confirm('You haven\'t added any questions yet. Create an empty quiz?')) {
            return;
        }
    }
    
    // Add questions data
    formData.append('questions_data', JSON.stringify(quizQuestions));
    
    // Add sesskey if not already in formData
    const sesskeyInput = document.querySelector('input[name="sesskey"]');
    if (sesskeyInput && sesskeyInput.value) {
        formData.append('sesskey', sesskeyInput.value);
    } else if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }
    
    // Show loading
    createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    createBtn.disabled = true;
    
    // Submit
    fetch('create_quiz.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Log response for debugging
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        // Get the text first to see if it's valid JSON
        return response.text().then(text => {
            console.log('Response text:', text);
            
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response was:', text);
                throw new Error('Server returned invalid JSON. Check console for details.');
            }
        });
    })
    .then(data => {
        if (data.success) {
            alert('Quiz created successfully!');
            window.location.href = 'quizzes.php';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating quiz: ' + error.message + '\n\nCheck browser console for details.');
    })
    .finally(() => {
        createBtn.innerHTML = '<i class="fa fa-plus"></i> Create Quiz';
        createBtn.disabled = false;
    });
}

function updateQuiz() {
    const form = document.getElementById('quizForm');
    if (!form) {
        alert('Quiz form not found. Please refresh the page.');
        return;
    }
    
    const formData = new FormData(form);
    const submitBtn = document.getElementById('createBtn');
    
    if (!formData.get('name')) {
        alert('Please enter a quiz name');
        return;
    }
    
    if (!formData.get('courseid')) {
        alert('Please select a course');
        return;
    }
    
    const selectedSection = document.getElementById('selectedSection');
    if (!selectedSection || !selectedSection.value) {
        alert('Please select a section for the quiz');
        return;
    }
    
    if (!Array.isArray(quizQuestions)) {
        console.error('quizQuestions is not an array:', quizQuestions);
        alert('Error: Questions data is invalid. Please refresh the page.');
        return;
    }
    
    if (quizQuestions.length === 0) {
        if (!confirm('You haven\'t added any questions yet. Update the quiz without questions?')) {
            return;
        }
    }
    
    try {
        // Clean up questions data before stringifying (remove any circular references or functions)
        const cleanQuestions = quizQuestions.map((q, index) => {
            const serialized = {
                id: q.id || null,
                name: q.name || '',
                qtype: q.qtype || '',
                defaultmark: typeof q.defaultmark === 'number' ? q.defaultmark : parseFloat(q.defaultmark) || 0,
                isNew: q.isNew !== undefined ? !!q.isNew : false,
                questionbankentryid: q.questionbankentryid || null,
                slot: q.slot || (index + 1),
                page: q.page || 1,
                questiontext: q.questiontext || '',
                originalQuestionId: q.originalQuestionId || null,
                metadata: q.metadata || null
            };

            if (Array.isArray(q.answers)) {
                serialized.answers = q.answers;
            }
            if (q.answer !== undefined) {
                serialized.answer = q.answer;
            }
            if (Array.isArray(q.pairs)) {
                serialized.pairs = q.pairs;
            }
            if (Array.isArray(q.items)) {
                serialized.items = q.items;
            }
            if (q.gapselect) {
                serialized.gapselect = q.gapselect;
            }
            if (q.ddwtos) {
                serialized.ddwtos = q.ddwtos;
            }
            if (q.ddimageortext) {
                serialized.ddimageortext = q.ddimageortext;
            }
            if (q.caseSensitive !== undefined) {
                serialized.caseSensitive = q.caseSensitive;
            }
            if (q.minWords !== undefined) {
                serialized.minWords = q.minWords;
            }
            if (q.maxWords !== undefined) {
                serialized.maxWords = q.maxWords;
            }
            if (q.attachments !== undefined) {
                serialized.attachments = q.attachments;
            }
            if (q.tolerance !== undefined) {
                serialized.tolerance = q.tolerance;
            }
            if (q.unit !== undefined) {
                serialized.unit = q.unit;
            }
            if (q.formula !== undefined) {
                serialized.formula = q.formula;
            }
            if (q.aiGenerated !== undefined) {
                serialized.aiGenerated = q.aiGenerated;
            }

            return serialized;
        });
        formData.append('questions_data', JSON.stringify(cleanQuestions));
    } catch (error) {
        console.error('Error serializing questions:', error);
        alert('Error preparing questions data: ' + error.message);
        return;
    }
    
    // Add sesskey if not already in formData
    const sesskeyInput = document.querySelector('input[name="sesskey"]');
    if (sesskeyInput && sesskeyInput.value) {
        formData.append('sesskey', sesskeyInput.value);
    } else if (CURRENT_SESSKEY) {
        formData.append('sesskey', CURRENT_SESSKEY);
    }
    
    const originalLabel = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating...';
        submitBtn.disabled = true;
    }
    
    fetch('update_quiz.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (error) {
            console.error('JSON parse error:', error);
            console.error('Response was:', text);
            throw new Error('Server returned invalid JSON. Check console for details.');
        }
    })
    .then(data => {
        console.log('Update quiz response:', data);
        if (data && data.success) {
            alert('Quiz updated successfully!');
            const redirectUrl = (quizEditData && quizEditData.courseid) ? `quizzes.php?courseid=${quizEditData.courseid}` : 'quizzes.php';
            window.location.href = redirectUrl;
        } else {
            let errorMsg = 'Unknown error occurred';
            if (data) {
                if (data.message) {
                    errorMsg = data.message;
                } else if (data.error) {
                    errorMsg = data.error;
                } else if (data.debug) {
                    errorMsg = data.debug;
                } else {
                    try {
                        errorMsg = JSON.stringify(data);
                    } catch (e) {
                        errorMsg = String(data);
                    }
                }
            }
            alert('Error: ' + errorMsg);
        }
    })
    .catch(error => {
        console.error('Error updating quiz:', error);
        const errorMsg = error && error.message ? error.message : 'Unknown error occurred';
        alert('Error updating quiz: ' + errorMsg + '\n\nCheck browser console for details.');
    })
    .finally(() => {
        if (submitBtn && originalLabel) {
            submitBtn.innerHTML = originalLabel;
            submitBtn.disabled = false;
        }
    });
}

function appendOrUpdateHiddenField(form, name, value) {
    if (!form) {
        return;
    }
    let field = form.querySelector(`input[name=\"${name}\"]`);
    if (!field) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        form.appendChild(field);
    }
    field.value = value !== undefined && value !== null ? value : '';
}

function setElementValueById(id, value) {
    const element = document.getElementById(id);
    if (!element || value === undefined || value === null) {
        return;
    }
    element.value = String(value);
}

function setSelectByName(name, value) {
    const select = document.querySelector(`select[name=\"${name}\"]`);
    if (!select || value === undefined || value === null) {
        return;
    }
    let targetValue = String(value);
    if (!Array.from(select.options).some(opt => opt.value === targetValue)) {
        if (name.endsWith('_minute')) {
            const numeric = Number(value);
            if (!Number.isNaN(numeric)) {
                const nearest = Math.round(numeric / 5) * 5;
                const normalized = Math.max(0, Math.min(55, nearest));
                const normalizedStr = String(normalized);
                if (Array.from(select.options).some(opt => opt.value === normalizedStr)) {
                    targetValue = normalizedStr;
                }
            }
        }
    }
    select.value = targetValue;
}

function setDateTimeFromTimestamp(prefix, timestamp) {
    if (!timestamp) {
        return;
    }
    const numeric = Number(timestamp);
    if (!numeric) {
        return;
    }
    const date = new Date(numeric * 1000);
    if (Number.isNaN(date.getTime())) {
        return;
    }
    setSelectByName(`${prefix}_day`, date.getDate());
    setSelectByName(`${prefix}_month`, date.getMonth() + 1);
    setSelectByName(`${prefix}_year`, date.getFullYear());
    setSelectByName(`${prefix}_hour`, date.getHours());
    setSelectByName(`${prefix}_minute`, date.getMinutes());
}

function applyDateSetting(prefix, timestamp, checkboxId, toggleKey) {
    const checkbox = document.getElementById(checkboxId);
    if (!checkbox) {
        return;
    }
    if (timestamp && Number(timestamp) > 0) {
        checkbox.checked = true;
        toggleDateTime(toggleKey);
        setDateTimeFromTimestamp(prefix, timestamp);
    } else {
        checkbox.checked = false;
        toggleDateTime(toggleKey);
    }
}

function setReviewOptionsFromBits(optionBits) {
    if (!optionBits || typeof optionBits !== 'object') {
        return;
    }
    Object.entries(optionBits).forEach(([optionName, bitsValue]) => {
        const bits = parseInt(bitsValue, 10) || 0;
        Object.entries(REVIEW_OPTION_BITS).forEach(([timing, bitMask]) => {
            const checkbox = document.querySelector(`input[name=\"${optionName}_${timing}\"]`);
            if (checkbox) {
                checkbox.checked = !!(bits & bitMask);
            }
        });
    });
}

function populateQuizQuestionsFromEditData() {
    if (!quizEditData || !quizEditData.questions) {
        return;
    }
    const questionsSource = Array.isArray(quizEditData.questions)
        ? quizEditData.questions
        : (typeof quizEditData.questions === 'object' ? Object.values(quizEditData.questions) : []);
    if (!questionsSource.length) {
        return;
    }
    quizQuestions = questionsSource.map((question, idx) => ({
        id: question.id,
        name: question.name,
        qtype: question.qtype,
        defaultmark: question.defaultmark,
        isNew: false,
        detailsLoaded: false,
        questionbankentryid: question.questionbankentryid || null,
        slot: question.slot || (idx + 1),
        page: question.page || 1,
        questiontext: question.questiontext || ''
    }));
    questionCounter = quizQuestions.length;
    updateQuizQuestionsList();
    prefetchQuestionSummaries();
}

function initializeQuizEditMode() {
    if (!quizEditData) {
        return;
    }
    
    const title = document.querySelector('.page-title-header');
    if (title) {
        title.textContent = 'Edit Quiz';
    }
    
    const subtitle = document.querySelector('.page-subtitle');
    if (subtitle) {
        if (quizEditData.coursename) {
            subtitle.textContent = `Course: ${quizEditData.coursename}`;
        } else {
            subtitle.textContent = 'Update quiz details and settings';
        }
    }
    
    const backButton = document.querySelector('.back-button');
    if (backButton) {
        const backUrl = quizEditData.courseid ? `quizzes.php?courseid=${quizEditData.courseid}` : 'quizzes.php';
        backButton.href = backUrl;
        backButton.innerHTML = '<i class=\"fa fa-arrow-left\"></i> Back to Quizzes';
    }
    
    const cancelBtn = document.getElementById('cancelBtn');
    if (cancelBtn) {
        cancelBtn.href = quizEditData.courseid ? `quizzes.php?courseid=${quizEditData.courseid}` : 'quizzes.php';
    }
    
    const form = document.getElementById('quizForm');
    if (form) {
        form.action = 'update_quiz.php';
        appendOrUpdateHiddenField(form, 'quiz_id', quizEditData.quizid);
        appendOrUpdateHiddenField(form, 'cmid', quizEditData.cmid);
    }
    
    const createBtn = document.getElementById('createBtn');
    if (createBtn) {
        createBtn.innerHTML = '<i class=\"fa fa-save\"></i> Update Quiz';
        createBtn.onclick = updateQuiz;
    }
    
    const courseSelect = document.getElementById('quizCourse');
    if (courseSelect && quizEditData.courseid) {
        courseSelect.value = quizEditData.courseid;
    }
    
    if (quizEditData.courseid) {
        loadCourseStructure();
        loadCompetencies(quizEditData.courseid);
        loadCourseGroups(quizEditData.courseid);
        loadCourseStudents(quizEditData.courseid);
    }
    
    setElementValueById('quizName', quizEditData.name || '');
    const description = document.getElementById('quizDescription');
    if (description) {
        description.value = stripHtml(quizEditData.intro || '');
    }
    
    if (quizEditData.sectionid) {
        document.getElementById('selectedSection').value = quizEditData.sectionid;
        if (quizEditData.sectionname) {
            updateSelectionInfo(quizEditData.sectionname, 'lesson');
        }
    }
    
    applyDateSetting('open', quizEditData.timeopen, 'enableTimeOpen', 'timeOpen');
    applyDateSetting('close', quizEditData.timeclose, 'enableTimeClose', 'timeClose');
    
    const timeLimitInput = document.getElementById('timeLimit');
    if (timeLimitInput) {
        timeLimitInput.value = quizEditData.timelimit ? Math.round(Number(quizEditData.timelimit) / 60) : '';
    }
    
    setElementValueById('gradeMethod', quizEditData.grademethod);
    setElementValueById('maxGrade', quizEditData.grade);
    setElementValueById('decimalPoints', quizEditData.decimalpoints);
    setElementValueById('questionDecimalPoints', quizEditData.questiondecimalpoints);
    setElementValueById('attempts', quizEditData.attempts);
    setElementValueById('preferredBehaviour', quizEditData.preferredbehaviour);
    setElementValueById('navMethod', quizEditData.navmethod);
    setElementValueById('questionsPerPage', quizEditData.questionsperpage);
    
    const shuffleCheckbox = document.querySelector('input[name=\"shuffleanswers\"]');
    if (shuffleCheckbox) {
        shuffleCheckbox.checked = !!quizEditData.shuffleanswers;
    }
    
    setReviewOptionsFromBits(quizEditData.reviewoptions);
    
    if (quizEditData.assign_to === 'groups') {
        const groupsRadio = document.getElementById('assignToGroups');
        if (groupsRadio) {
            groupsRadio.checked = true;
        }
        const allRadio = document.getElementById('assignToAll');
        if (allRadio) {
            allRadio.checked = false;
        }
        toggleGroupSelection();
    } else {
        const allRadio = document.getElementById('assignToAll');
        if (allRadio) {
            allRadio.checked = true;
        }
        const groupsRadio = document.getElementById('assignToGroups');
        if (groupsRadio) {
            groupsRadio.checked = false;
        }
        toggleGroupSelection();
    }
    
    populateQuizQuestionsFromEditData();
}

// Show notification function
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fa fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Add styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
        color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
        border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
        border-radius: 8px;
        padding: 12px 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;
    
    // Add animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .notification-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    `;
    document.head.appendChild(style);
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Open create group modal
function openCreateGroupModal() {
    const courseSelect = document.getElementById('quizCourse');
    if (!courseSelect.value) {
        alert('Please select a course first.');
        return;
    }
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('createGroupModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'createGroupModal';
        modal.className = 'create-group-modal';
        modal.innerHTML = `
            <div class="create-group-modal-content">
                <div class="create-group-modal-header">
                    <h3 class="create-group-modal-title">
                        <i class="fa fa-users"></i>
                        Create New Group
                    </h3>
                    <button class="create-group-modal-close" onclick="closeCreateGroupModal()">×</button>
                </div>
                <div class="create-group-modal-body">
                    <div class="create-group-form">
                        <div class="form-row">
                            <div class="form-col">
                                <label class="form-label">Group Name <span class="required">*</span></label>
                                <input type="text" id="modalGroupName" class="form-input" placeholder="e.g., Advanced Learners" maxlength="100">
                            </div>
                            <div class="form-col">
                                <label class="form-label">Description (Optional)</label>
                                <input type="text" id="modalGroupDescription" class="form-input" placeholder="Brief description..." maxlength="255">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-col-full">
                                <label class="form-label">Select Students</label>
                                <div class="student-selector">
                                    <div class="student-search">
                                        <i class="fa fa-search"></i>
                                        <input type="text" id="modalStudentSearchInput" class="search-input" placeholder="Search by name or email..." onkeyup="filterModalStudents()">
                                    </div>
                                    <div class="loading" id="modalStudentsLoading" style="display: none;">
                                        <i class="fa fa-spinner fa-spin"></i> Loading students...
                                    </div>
                                    <div id="modalStudentsList" class="students-list">
                                        <div class="info-message">
                                            <i class="fa fa-info-circle"></i>
                                            Loading students...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="create-group-modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeCreateGroupModal()">Cancel</button>
                    <button type="button" id="modalCreateGroupBtn" class="btn-create" onclick="createNewGroupFromModal()">
                        <i class="fa fa-check"></i> Create Group
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateGroupModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeCreateGroupModal();
            }
        });
    }
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Load students
    loadCourseStudentsForModal(courseSelect.value);
}

// Close create group modal
function closeCreateGroupModal() {
    const modal = document.getElementById('createGroupModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Clear form
        document.getElementById('modalGroupName').value = '';
        document.getElementById('modalGroupDescription').value = '';
        document.getElementById('modalStudentSearchInput').value = '';
        
        // Clear selections
        document.querySelectorAll('#modalStudentsList .student-checkbox:checked').forEach(cb => {
            cb.checked = false;
            cb.closest('.student-item').classList.remove('selected');
        });
    }
}

// Load course students for modal
function loadCourseStudentsForModal(courseid) {
    const loadingDiv = document.getElementById('modalStudentsLoading');
    const listDiv = document.getElementById('modalStudentsList');
    
    if (!courseid) {
        listDiv.innerHTML = '<div class="info-message"><i class="fa fa-info-circle"></i>Select a course first to see students</div>';
        return;
    }
    
    loadingDiv.style.display = 'block';
    listDiv.innerHTML = '';
    
    fetch('get_course_students.php?courseid=' + courseid)
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';
            
            if (data.success && data.students && data.students.length > 0) {
                renderModalStudents(data.students);
            } else {
                listDiv.innerHTML = '<div class="info-message"><i class="fa fa-info-circle"></i>No students found in this course</div>';
            }
        })
        .catch(error => {
            console.error('Error loading students:', error);
            loadingDiv.style.display = 'none';
            listDiv.innerHTML = '<div class="info-message" style="color: #dc3545;"><i class="fa fa-exclamation-circle"></i>Error loading students</div>';
        });
}

// Render students in modal
function renderModalStudents(students) {
    const listDiv = document.getElementById('modalStudentsList');
    listDiv.innerHTML = '';
    
    students.forEach(student => {
        const studentItem = document.createElement('div');
        studentItem.className = 'student-item';
        studentItem.dataset.studentName = student.fullname.toLowerCase();
        studentItem.dataset.studentEmail = student.email.toLowerCase();
        studentItem.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.student-checkbox');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
            } else {
                this.classList.toggle('selected', e.target.checked);
            }
        };
        
        studentItem.innerHTML = `
            <input type="checkbox" class="student-checkbox" value="${student.id}" onclick="event.stopPropagation();">
            <div class="student-details">
                <div class="student-name">${student.fullname}</div>
                <div class="student-email">${student.email}</div>
            </div>
        `;
        
        listDiv.appendChild(studentItem);
    });
}

// Filter students in modal
function filterModalStudents() {
    const searchInput = document.getElementById('modalStudentSearchInput');
    const query = searchInput.value.toLowerCase().trim();
    const studentItems = document.querySelectorAll('#modalStudentsList .student-item');
    
    studentItems.forEach(item => {
        const name = item.dataset.studentName || '';
        const email = item.dataset.studentEmail || '';
        
        if (name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Create group from modal
function createNewGroupFromModal() {
    const courseSelect = document.getElementById('quizCourse');
    const groupNameInput = document.getElementById('modalGroupName');
    const groupDescInput = document.getElementById('modalGroupDescription');
    const createBtn = document.getElementById('modalCreateGroupBtn');
    
    const groupName = groupNameInput.value.trim();
    if (!groupName) {
        alert('Please enter a group name.');
        groupNameInput.focus();
        return;
    }
    
    if (groupName.length < 2) {
        alert('Group name must be at least 2 characters long.');
        groupNameInput.focus();
        return;
    }
    
    createBtn.disabled = true;
    createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    
    const selectedCheckboxes = document.querySelectorAll('#modalStudentsList .student-checkbox:checked');
    const selectedStudentIds = Array.from(selectedCheckboxes).map(cb => cb.value);
    
    const formData = new FormData();
    formData.append('courseid', courseSelect.value);
    formData.append('name', groupName);
    formData.append('description', groupDescInput.value.trim());
    formData.append('sesskey', document.querySelector('input[name="sesskey"]').value);
    
    selectedStudentIds.forEach(studentId => {
        formData.append('student_ids[]', studentId);
    });
    
    fetch('create_group.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Group "' + groupName + '" created successfully with ' + (data.member_count || 0) + ' student(s)!', 'success');
            closeCreateGroupModal();
            loadCourseGroups(courseSelect.value);
            
            setTimeout(() => {
                const newGroupCheckbox = document.querySelector(`input[value="${data.group_id}"]`);
                if (newGroupCheckbox) {
                    newGroupCheckbox.checked = true;
                }
            }, 500);
        } else {
            showNotification('Error creating group: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error creating group. Please try again.', 'error');
    })
    .finally(() => {
        createBtn.disabled = false;
        createBtn.innerHTML = '<i class="fa fa-check"></i> Create Group';
    });
}

// Load course students
function loadCourseStudents(courseid) {
    console.log('loadCourseStudents called with courseid:', courseid);
    
    const loadingDiv = document.getElementById('studentsLoading');
    const listDiv = document.getElementById('studentsList');
    
    if (!loadingDiv || !listDiv) {
        console.warn('Student containers not found; skipping loadCourseStudents');
        return;
    }
    
    if (!courseid) {
        listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px;"><i class="fa fa-info-circle"></i><span>Select a course first to see students</span></div>';
        return;
    }
    
    // Show loading
    loadingDiv.style.display = 'block';
    listDiv.innerHTML = '';
    
    // Fetch students
    fetch('get_course_students.php?courseid=' + courseid)
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';
            
            if (data.success && data.students && data.students.length > 0) {
                renderStudents(data.students);
            } else {
                listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px;"><i class="fa fa-info-circle"></i><span>No students found in this course</span></div>';
            }
        })
        .catch(error => {
            console.error('Error loading students:', error);
            loadingDiv.style.display = 'none';
            listDiv.innerHTML = '<div class="info-box" style="text-align: center; padding: 12px; color: #dc3545;"><i class="fa fa-exclamation-circle"></i><span>Error loading students</span></div>';
        });
}

// Render students list
function renderStudents(students) {
    const listDiv = document.getElementById('studentsList');
    listDiv.innerHTML = '';
    
    // Store students globally for filtering
    window.courseStudents = students;
    
    students.forEach(student => {
        const studentItem = document.createElement('div');
        studentItem.className = 'student-item';
        studentItem.dataset.studentId = student.id;
        studentItem.dataset.studentName = student.fullname.toLowerCase();
        studentItem.dataset.studentEmail = student.email.toLowerCase();
        studentItem.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.student-checkbox');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
            } else {
                this.classList.toggle('selected', e.target.checked);
            }
        };
        
        studentItem.innerHTML = `
            <input type="checkbox" class="student-checkbox" value="${student.id}" onclick="event.stopPropagation();">
            <div class="student-details">
                <div class="student-name">${student.fullname}</div>
                <div class="student-email">${student.email}</div>
            </div>
        `;
        
        listDiv.appendChild(studentItem);
    });
}

// Filter students
function filterStudents() {
    const searchInput = document.getElementById('studentSearchInput');
    const query = searchInput.value.toLowerCase().trim();
    const studentItems = document.querySelectorAll('.student-item');
    
    let visibleCount = 0;
    
    studentItems.forEach(item => {
        const name = item.dataset.studentName || '';
        const email = item.dataset.studentEmail || '';
        
        if (name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show no results message if needed
    if (visibleCount === 0 && studentItems.length > 0 && query) {
        const listDiv = document.getElementById('studentsList');
        const existingMsg = listDiv.querySelector('.no-results-msg');
        if (!existingMsg) {
            const noResults = document.createElement('div');
            noResults.className = 'info-box no-results-msg';
            noResults.style.cssText = 'text-align: center; padding: 12px;';
            noResults.innerHTML = '<i class="fa fa-search"></i><span>No students found matching "' + query + '"</span>';
            listDiv.appendChild(noResults);
        }
    } else {
        const existingMsg = document.querySelector('.no-results-msg');
        if (existingMsg) {
            existingMsg.remove();
        }
    }
}

// Open members modal
function openMembersModal(groupId, groupName, memberCount) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('membersModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'membersModal';
        modal.className = 'members-modal';
        modal.innerHTML = `
            <div class="members-modal-content">
                <div class="members-modal-header">
                    <h3 class="members-modal-title">
                        <i class="fa fa-users"></i>
                        <span id="modalGroupName">${groupName}</span>
                        <span class="members-count-badge" id="modalMemberCount">${memberCount}</span>
                    </h3>
                    <button class="members-modal-close" onclick="closeMembersModal()">×</button>
                </div>
                <div class="members-modal-search">
                    <div class="members-search-wrapper">
                        <i class="fa fa-search"></i>
                        <input type="text" class="members-search-input" id="memberSearchInput" placeholder="Search members by name or email...">
                    </div>
                </div>
                <div class="members-list-content" id="modalMembersList">
                    <div class="loading-members"><i class="fa fa-spinner fa-spin"></i><br>Loading members...</div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeMembersModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeMembersModal();
            }
        });
        
        // Search functionality
        document.getElementById('memberSearchInput').addEventListener('input', function() {
            filterMembers(this.value);
        });
    } else {
        // Update modal title if reusing
        document.getElementById('modalGroupName').textContent = groupName;
        document.getElementById('modalMemberCount').textContent = memberCount;
        document.getElementById('memberSearchInput').value = '';
    }
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    
    // Load members
    loadGroupMembers(groupId);
}

// Close members modal
function closeMembersModal() {
    const modal = document.getElementById('membersModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    }
}

// Load group members into modal
function loadGroupMembers(groupId) {
    const listContainer = document.getElementById('modalMembersList');
    listContainer.innerHTML = '<div class="loading-members"><i class="fa fa-spinner fa-spin"></i><br>Loading members...</div>';
    
    fetch(`get_group_members.php?groupid=${groupId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.members && data.members.length > 0) {
                window.currentMembers = data.members; // Store for search
                renderMembers(data.members);
            } else {
                listContainer.innerHTML = `
                    <div class="members-list-empty">
                        <i class="fa fa-users"></i><br>
                        <strong>No members in this group</strong>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading members:', error);
            listContainer.innerHTML = `
                <div class="error-members">
                    <i class="fa fa-exclamation-circle"></i><br>
                    Error loading members. Please try again.
                </div>
            `;
        });
}

// Render members list
function renderMembers(members) {
    const listContainer = document.getElementById('modalMembersList');
    let html = '';
    
    members.forEach(member => {
        const initials = member.fullname.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        html += `
            <div class="member-item" data-name="${member.fullname.toLowerCase()}" data-email="${member.email.toLowerCase()}">
                <div class="member-avatar">${initials}</div>
                <div class="member-info">
                    <div class="member-name">${member.fullname}</div>
                    <div class="member-email">${member.email}</div>
                </div>
                <div class="member-added">Added: ${member.added}</div>
            </div>
        `;
    });
    
    listContainer.innerHTML = html || '<div class="no-members">No members found</div>';
}

// Filter members by search query
function filterMembers(query) {
    query = query.toLowerCase().trim();
    const memberItems = document.querySelectorAll('#modalMembersList .member-item');
    let visibleCount = 0;
    
    memberItems.forEach(item => {
        const name = item.dataset.name || '';
        const email = item.dataset.email || '';
        
        if (query === '' || name.includes(query) || email.includes(query)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show "no results" if all filtered out
    if (visibleCount === 0 && query !== '') {
        const listContainer = document.getElementById('modalMembersList');
        if (!listContainer.querySelector('.no-members')) {
            listContainer.innerHTML += '<div class="no-members">No members found matching your search</div>';
        }
    } else {
        // Remove "no results" message if exists
        const noResults = document.querySelector('#modalMembersList .no-members');
        if (noResults) {
            noResults.remove();
        }
    }
}
</script>

<?php
echo $OUTPUT->footer();
?>

