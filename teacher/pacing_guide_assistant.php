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
 * Pacing Guide Assistant landing page for teachers.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);

$coursecontext = context_course::instance($course->id);

require_capability('moodle/course:update', $coursecontext);

$pageurl = new moodle_url('/theme/remui_kids/teacher/pacing_guide_assistant.php', ['courseid' => $course->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title('Pacing Guide Assistant');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('courses'));
$PAGE->navbar->add(format_string($course->fullname), new moodle_url('/course/view.php', ['id' => $course->id]));
$PAGE->navbar->add('Pacing Guide Assistant');

$modinfo = get_fast_modinfo($course);
$sectionsinfo = $modinfo->get_section_info_all();

$format = course_get_format($course);

$visiblesections = [];
foreach ($sectionsinfo as $section) {
    if ((int)$section->section === 0) {
        continue;
    }
    if (empty($section->uservisible)) {
        continue;
    }
    $visiblesections[] = $section;
}

$todaylabel = userdate(time(), get_string('strftimedate'));
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

echo $OUTPUT->header();
?>

<style>
.pga-page {
    min-height: calc(100vh - 120px);
    padding: 2.5rem 0 4rem;
    color: #1f2937;
}
.container{
    max-width: 100%;
}
.pga-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
}
.page-context-header,
.secondary-navigation,
.tertiary-navigation,
.drawer,
.drawer-toggle {
    display: none !important;
}
body.drawer-open-left,
body.drawer-open-right {
    overflow: auto !important;
}
.pga-card {
    background: #ffffff;
    border-radius: 22px;
    box-shadow: 0 24px 60px rgba(107, 114, 128, 0.08);
    border: 1px solid rgba(156, 163, 175, 0.08);
}
.pga-header-card {
    display: flex;
    flex-direction: column;
    gap: 2.2rem;
    padding: 2.5rem 3rem;
}
.pga-header-top {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    justify-content: space-between;
    align-items: center;
}
.pga-header-top + .pga-generator-card {
    margin-top: 0;
}
.pga-header-card .meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-top: 1.5rem;
}
.pga-header-card .meta-item {
    display: inline-flex;
    padding: 0.6rem 1.2rem;
    border-radius: 999px;
    background: rgba(107, 114, 128, 0.08);
    color: #4b5563;
    font-weight: 600;
    font-size: 0.95rem;
    align-items: center;
    gap: 0.75rem;
}
.pga-header-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.pga-header-actions a,
.pga-header-actions button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.9rem 1.6rem;
    border-radius: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
}
.pga-header-actions .btn-back-course {
    background: #f3f4f6;
    color: #4b5563;
    border: 1px solid rgba(107, 114, 128, 0.25);
}
.pga-header-actions .btn-ai-soon {
    background: linear-gradient(135deg, #6b7280, #9ca3af);
    color: #ffffff;
    box-shadow: 0 20px 40px rgba(156, 163, 175, 0.35);
}
.pga-header-actions a:hover {
    transform: translateY(-2px);
}
.pga-header-actions .btn-ai-soon:disabled {
    opacity: 0.75;
    cursor: not-allowed;
}
.pga-meta-item--highlight {
    background: rgba(156, 163, 175, 0.16);
    color: #1e1b4b;
}
.pga-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 1.5rem;
}
.pga-modal-overlay[aria-hidden="false"] {
    display: flex;
}
.pga-modal {
    width: min(580px, 100%);
    background: #ffffff;
    border-radius: 26px;
    box-shadow: 0 40px 80px rgba(15, 23, 42, 0.28);
    padding: 2.8rem 2.6rem;
    display: flex;
    flex-direction: column;
    gap: 1.9rem;
    border: 1px solid rgba(156, 163, 175, 0.15);
    position: relative;
}
.pga-modal h2 {
    margin: 0;
    font-size: 2rem;
    color: #1e1b4b;
}
.pga-modal p {
    margin: 0;
    color: #4b5563;
    font-size: 1.05rem;
}
.pga-modal .prompt-label {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    color: #6b7280;
}
.pga-modal .option-grid {
    display: grid;
    gap: 0.9rem;
}
.pga-modal .option-button {
    border: 1px solid rgba(156, 163, 175, 0.18);
    border-radius: 18px;
    padding: 1.05rem 1.3rem;
    background: rgba(156, 163, 175, 0.06);
    color: #374151;
    font-size: 1.03rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.pga-modal .option-button:hover,
.pga-modal .option-button:focus {
    transform: translateY(-3px);
    border-color: rgba(156, 163, 175, 0.55);
    background: rgba(156, 163, 175, 0.14);
    box-shadow: 0 14px 30px rgba(156, 163, 175, 0.12);
    outline: none;
}
.pga-modal .option-button strong {
    font-size: 1.05rem;
}
.pga-modal .option-button span {
    font-size: 0.92rem;
    font-weight: 500;
    color: #4b5563;
}
.pga-modal .lesson-picker {
    display: none;
    flex-direction: column;
    gap: 0.6rem;
}
.pga-modal .lesson-picker label {
    font-weight: 600;
    color: #4b5563;
}
.pga-modal .lesson-picker select {
    border: 1px solid rgba(156, 163, 175, 0.18);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    transition: border 0.2s ease, box-shadow 0.2s ease;
    background:rgb(240, 240, 240);
}
.pga-modal .lesson-picker select:focus {
    border-color: #6b7280;
    box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.2);
    outline: none;
}
.pga-modal .modal-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.5rem;
}
.pga-modal .modal-actions button {
    border: none;
    background: rgba(148, 148, 148, 0.18);
    color: #4b5563;
    font-weight: 600;
    cursor: pointer;
    padding: 0.5rem 0.9rem;
    border-radius: 20px;
}
.pga-modal .modal-actions button:hover {
    background: rgba(156, 163, 175, 0.12);
}
.pga-modal .summary-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.55rem 0.95rem;
    border-radius: 999px;
    background: rgba(45, 161, 207, 0.18);
    color: #374151;
    font-weight: 600;
    font-size: 0.88rem;
}
.pga-modal .modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: rgba(156, 163, 175, 0.1);
    color: #374151;
    font-size: 1.1rem;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.2s ease;
}
.pga-modal .modal-close:hover {
    background: rgba(156, 163, 175, 0.2);
    transform: scale(1.05);
}
.pga-generator-card {
    margin-top: 2.2rem;
    padding-top: 2rem;
    border-top: 1px solid rgba(156, 163, 175, 0.12);
    background: transparent;
    border-radius: 0;
    box-shadow: none;
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
}
.pga-generator-header h2 {
    margin: 0 0 0.6rem 0;
    font-size: 1.8rem;
    color: #1e1b4b;
}
.pga-generator-header p {
    margin: 0;
    color: #4b5563;
    font-size: 1rem;
}
.pga-generator-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    align-items: flex-end;
}
.pga-generator-field {
    flex: 1 1 260px;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.pga-generator-field label {
    font-weight: 600;
    color: #4b5563;
}
.pga-generator-field input {
    border: 1px solid rgba(156, 163, 175, 0.18);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    transition: border 0.2s ease, box-shadow 0.2s ease;
}
.pga-generator-field input:focus {
    border-color: #6b7280;
    box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.2);
    outline: none;
}
.pga-generator-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: flex-start;
}
.pga-generator-flex {
    display: flex;
    flex-wrap: wrap;
    gap: 1.2rem;
    width: 100%;
}
.pga-generator-flex .pga-generator-field {
    flex: 1 1 240px;
}
.pga-generator-notes {
    margin-top: 1.4rem;
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}
.pga-generator-notes textarea {
    border: 1px solid rgba(156, 163, 175, 0.18);
    border-radius: 14px;
    padding: 0.9rem 1.1rem;
    font-size: 0.95rem;
    min-height: 110px;
    resize: vertical;
    transition: border 0.2s ease, box-shadow 0.2s ease;
}
.pga-generator-notes textarea:focus {
    border-color: #6b7280;
    box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.2);
    outline: none;
}
.pga-download-footer {
    display: none;
    justify-content: flex-end;
}
.pga-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.85rem 1.6rem;
    border-radius: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}
.pga-btn-primary {
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    color: #ffffff;
    box-shadow: 0 16px 32px rgba(107, 114, 128, 0.25);
}
.pga-btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 22px 36px rgba(37, 99, 235, 0.3);
}
.pga-btn-secondary {
    background: rgba(190, 230, 253, 0.45);
    color: #0369a1;
    border: 1px solid rgba(56, 189, 248, 0.45);
}
.pga-btn-secondary i {
    color: #0ea5e9;
}
.pga-btn-secondary:hover {
    transform: translateY(-1px);
    background: rgba(190, 230, 253, 0.65);
}
.pga-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
.pga-generator-status {
    display: none;
    padding: 0.9rem 1.2rem;
    border-radius: 14px;
    font-weight: 500;
    font-size: 0.95rem;
}
.pga-generator-status[data-status="loading"] {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
    border: 1px solid rgba(59, 130, 246, 0.2);
}
.pga-generator-status[data-status="error"] {
    display: block;
    background: rgba(248, 113, 113, 0.12);
    color: #b91c1c;
    border: 1px solid rgba(248, 113, 113, 0.3);
}
.pga-generator-status[data-status="success"] {
    display: block;
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
    border: 1px solid rgba(16, 185, 129, 0.25);
}
.pga-spinner {
    width: 1.1rem;
    height: 1.1rem;
    border-radius: 50%;
    border: 2px solid transparent;
    border-top-color: currentColor;
    animation: pga-spin 0.9s linear infinite;
}
@keyframes pga-spin {
    to { transform: rotate(360deg); }
}
.pga-generator-result {
    display: none;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid rgba(156, 163, 175, 0.12);
    padding: 1.8rem;
    box-shadow: inset 0 0 0 1px rgba(156, 163, 175, 0.08);
    overflow-x: auto;
}
.pga-generator-result table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
}
.pga-generator-result th,
.pga-generator-result td {
    border: 1px solid rgba(148, 163, 184, 0.45);
    padding: 0.75rem 0.9rem;
    text-align: left;
    vertical-align: top;
}
.pga-generator-result thead th {
    background: rgba(107, 114, 128, 0.12);
    color: #374151;
    font-weight: 700;
}
.pga-generator-result p {
    margin: 0.5rem 0;
}
.pga-generator-result ul,
.pga-generator-result ol {
    padding-left: 1.25rem;
}
.pga-generator-result ul {
    list-style: disc;
}
.pga-generator-result ol {
    list-style: decimal;
}
.pga-generator-result .pga-pacing-table .segment-cell {
    background: #ecfdf5;
    font-weight: 600;
}
.pga-generator-result .week-header td {
    background: var(--week-bg, #f3f4f6);
    font-weight: 700;
    text-transform: uppercase;
    color: #374151;
    letter-spacing: 0.04em;
    text-align: center;
}
.pga-generator-result .month-header td {
    background: #e5e7eb;
    font-weight: 700;
    text-transform: uppercase;
    color: #374151;
    letter-spacing: 0.04em;
}
.pga-generator-result .pga-pacing-table .segment-cell div {
    font-weight: 500;
    color: #475569;
    margin-top: 0.25rem;
}
@media (max-width: 1024px) {
    .pga-header-card {
        padding: 2rem;
    }
}
@media (max-width: 640px) {
    .pga-header-top {
        flex-direction: column;
        align-items: flex-start;
    }
    .pga-header-actions {
        width: 100%;
    }
    .pga-header-actions a,
    .pga-header-actions button {
        width: 100%;
    }
    .pga-header-card {
        padding: 1.8rem;
    }
}
</style>

<div class="pga-modal-overlay" id="pga-intake-modal" aria-hidden="true">
    <div class="pga-modal" role="dialog" aria-modal="true" aria-labelledby="pga-modal-title">
        <button type="button" class="modal-close" data-action="close-modal" aria-label="Close modal">&times;</button>
        <div class="modal-step" data-step="1">
            <div class="prompt-label">Planning focus</div>
            <br>
            <h2 id="pga-modal-title">How wide is the roadmap you need?</h2>
            <br>
            <p>Pick an option that matches the scope of the pacing support you want to assemble today.</p>
            <br>
            <div class="option-grid">
                <button class="option-button" type="button" data-option="scope" data-value="course">
                    <strong>Entire course journey</strong><br>
                    Blueprint every Lesson and Module from opener to wrap-up.
                </button>
                <button class="option-button" type="button" data-option="scope" data-value="lesson">
                    <strong>Single lesson spotlight</strong><br>
                    Focus on one main Lesson and everything nested inside it.
                </button>
            </div>
        </div>
        <div class="modal-step" data-step="2" hidden>
            <div class="prompt-label">Planning window</div>
            <br>
            <h2>How long should your roadmap run?</h2>
            <p>Choose the time horizon you want your pacing milestones to cover.</p>
            <div class="lesson-picker" data-lesson-picker>
                <label for="pga-lesson-select">Select the lesson you want to map</label>
                <select id="pga-lesson-select" data-lesson-select>
                    <option value="">Choose a lesson section</option>
                    <?php foreach ($visiblesections as $section) { ?>
                        <option value="<?php echo $section->id; ?>">
                            <?php echo format_string(get_section_name($course, $section)); ?>
                        </option>
                    <?php } ?>
                </select>
                <br>
            </div>
            <div class="option-grid">
                <button class="option-button" type="button" data-option="timeframe" data-value="year">
                    <strong>Full year arc</strong><br>
                    Stretch pacing across the academic year.
                </button>
                <button class="option-button" type="button" data-option="timeframe" data-value="month">
                    <strong>Multi-week plan</strong><br>
                    Map a multi-week or unit-length window.
                </button>
                <button class="option-button" type="button" data-option="timeframe" data-value="week">
                    <strong>Weekly sprint</strong><br>
                    Organize a single week with clarity.
                </button>
            </div>
            <div class="modal-actions">
                <button type="button" data-action="prev-step">
                    &larr; Back
                </button>
                <div class="summary-chip" data-summary></div>
            </div>
        </div>
    </div>
</div>

<div class="pga-page">
    <div class="pga-container">
        <div class="pga-card pga-header-card">
            
            <div class="pga-header-top">
            <div class="pga-header-actions">
                    <a class="btn-back-course" href="<?php echo $courseurl->out(false); ?>">
                        <span class="fa fa-arrow-left" aria-hidden="true"></span>
                        Back to Course
                    </a>
                </div>
                <div>
                    <h1 style="font-size: 2.4rem; margin: 0; color: #1e1b4b; background-color:rgb(243, 247, 255); padding: 0.5rem 1rem; border-radius: 1px;">Pacing Guide Assistant</h1>
                    <p style="margin-top: 0.75rem; font-size: 1.05rem; color: #4b5563;">
                        <br>
                        Draft, refine, and share a week-by-week pacing roadmap for <strong><?php echo format_string($course->fullname); ?></strong>.
                        AI-powered suggestions are coming soon, so you can align instruction, interventions, and enrichment with just a few prompts.
                    </p>
                    <div class="meta">
                        <div class="meta-item">
                            <span class="fa fa-calendar" aria-hidden="true"></span>
                            Today: <?php echo $todaylabel; ?>
                        </div>
                        <div class="meta-item">
                            <span class="fa fa-user-shield" aria-hidden="true"></span>
                            Planner: <?php echo fullname($USER); ?>
                        </div>
                        <div class="meta-item">
                            <span class="fa fa-school" aria-hidden="true"></span>
                            Course Name: <?php echo $course->fullname; ?>
                        </div>
                        <div class="meta-item pga-meta-item--highlight" id="pga-selected-focus" style="display: none;">
                            <span class="fa fa-route" aria-hidden="true"></span>
                            <span data-focus-summary></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pga-generator-card" data-courseid="<?php echo (int)$course->id; ?>">
                <div class="pga-generator-header">
                    <h2>Generate pacing guide</h2>
                    <p>Use the selections above to draft a top-level pacing roadmap. The assistant reviews course resources and suggests learning focuses, practice, and assessment rhythms for the chosen timeframe.</p>
                </div>
                <div class="pga-generator-controls">
                    <div class="pga-generator-flex">
                        <div class="pga-generator-field">
                        <label for="pga-hours-input">Estimated total instructional hours (optional)</label>
                        <input type="number" id="pga-hours-input" name="estimatedhours" placeholder="e.g. 6" min="0" step="0.5" data-hours-input>
                    </div>
                        <div class="pga-generator-field" style="display:none;" data-role="weeks-field">
                            <label for="pga-weeks-input">Estimated number of weeks (optional)</label>
                            <input type="number" id="pga-weeks-input" name="estimatedweeks" placeholder="e.g. 6" min="1" step="1" data-weeks-input>
                        </div>
                        <div class="pga-generator-field" style="display:none;" data-role="years-field">
                            <label for="pga-years-input">Estimated number of years (optional)</label>
                            <input type="number" id="pga-years-input" name="estimatedyears" placeholder="e.g. 1" min="1" step="1" data-years-input>
                        </div>
                    </div>
                    <div class="pga-generator-notes">
                        <label for="pga-notes-input">Additional guidance for the assistant (optional)</label>
                        <textarea id="pga-notes-input" name="pacingnotes" placeholder="E.g. highlight project-based learning, reinforce vocabulary practice, etc." data-notes-input></textarea>
                    </div>
                    <div class="pga-generator-actions" style="margin-top: 1.2rem;">
                        <button type="button" class="pga-btn pga-btn-secondary" data-action="adjust-planning-inputs">
                            <span class="fa fa-sliders-h" aria-hidden="true"></span>
                            Adjust planning inputs
                        </button>
                <button type="button" class="pga-btn pga-btn-primary" data-action="generate-pacing-guide" disabled>
                            <span class="fa fa-wand-magic-sparkles" aria-hidden="true"></span>
                            Generate pacing guide
                        </button>
                    </div>
                </div>
                <div class="pga-generator-status" data-generator-status></div>
                <div class="pga-generator-result" data-generator-result></div>
        <div class="pga-download-footer" data-download-footer>
            <button type="button" class="pga-btn pga-btn-secondary" data-action="download-pdf" disabled>
                <span class="fa fa-file-pdf" aria-hidden="true"></span>
                Download PDF
            </button>
        </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const courseId = <?php echo (int)$course->id; ?>;
    const generateEndpoint = M.cfg.wwwroot + '/theme/remui_kids/teacher/pacing_guide_generate.php';

    const intakeModal = document.getElementById('pga-intake-modal');
    const modalSteps = intakeModal ? intakeModal.querySelectorAll('.modal-step') : [];
    const summaryChip = intakeModal ? intakeModal.querySelector('[data-summary]') : null;
    const lessonPicker = intakeModal ? intakeModal.querySelector('[data-lesson-picker]') : null;
    const lessonSelect = intakeModal ? intakeModal.querySelector('[data-lesson-select]') : null;
    const focusDisplay = document.querySelector('#pga-selected-focus');
    const focusDisplayText = focusDisplay ? focusDisplay.querySelector('[data-focus-summary]') : null;

    const generatorCard = document.querySelector('.pga-generator-card');
    const generateBtn = generatorCard ? generatorCard.querySelector('[data-action="generate-pacing-guide"]') : null;
    const downloadFooter = generatorCard ? generatorCard.querySelector('[data-download-footer]') : null;
    const downloadBtn = downloadFooter ? downloadFooter.querySelector('[data-action="download-pdf"]') : null;
    const adjustBtn = generatorCard ? generatorCard.querySelector('[data-action="adjust-planning-inputs"]') : null;
    const statusEl = generatorCard ? generatorCard.querySelector('[data-generator-status]') : null;
    const resultEl = generatorCard ? generatorCard.querySelector('[data-generator-result]') : null;
    const hoursInput = generatorCard ? generatorCard.querySelector('[data-hours-input]') : null;
    const weeksField = generatorCard ? generatorCard.querySelector('[data-role="weeks-field"]') : null;
    const weeksInput = weeksField ? weeksField.querySelector('[data-weeks-input]') : null;
    const yearsField = generatorCard ? generatorCard.querySelector('[data-role="years-field"]') : null;
    const yearsInput = yearsField ? yearsField.querySelector('[data-years-input]') : null;
    const notesInput = generatorCard ? generatorCard.querySelector('[data-notes-input]') : null;

    let selectedScope = '';
    let selectedTimeframe = '';
    let selectedLessonId = '';
    let selectedLessonLabel = '';
    let selectedHours = '';
    let latestGuideHtml = '';
    let selectedWeeks = '';
    let selectedYears = '';
    let additionalNotes = '';

    function showModalStep(stepIndex) {
        modalSteps.forEach(function(step) {
            const desired = parseInt(step.getAttribute('data-step'), 10) === stepIndex;
            step.hidden = !desired;
        });
    }

    function openIntakeModal() {
        if (!intakeModal) {
            return;
        }
        intakeModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        showModalStep(1);
        if (summaryChip) {
            summaryChip.textContent = '';
        }
    }

    function closeIntakeModal() {
        if (!intakeModal) {
            return;
        }
        intakeModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function updateSummary(scopeValue, timeframeValue, lessonLabel) {
        if (summaryChip) {
            const readableScope = scopeValue === 'lesson' ? 'Lesson guide' : 'Course guide';
            const readableTimeframeMap = {
                year: 'full year',
                month: 'month(s)',
                week: 'week',
                day: 'day'
            };
            const timeframeText = readableTimeframeMap[timeframeValue] || '';
            const lessonText = scopeValue === 'lesson' && lessonLabel ? ' • ' + lessonLabel : '';
            const hoursText = selectedHours ? ' • ~' + selectedHours + ' hours' : '';
            const weeksText = (scopeValue !== '' && timeframeValue === 'month' && selectedWeeks) ? ' • ~' + selectedWeeks + ' week(s)' : '';
            const yearsText = (scopeValue !== '' && timeframeValue === 'year' && selectedYears) ? ' • ~' + selectedYears + ' year(s)' : '';
            summaryChip.textContent = timeframeText
                ? readableScope + ' • ' + timeframeText + lessonText + hoursText + weeksText + yearsText
                : readableScope + lessonText + hoursText + weeksText + yearsText;
        }

        if (focusDisplay && focusDisplayText && scopeValue && timeframeValue) {
            const readableScope = scopeValue === 'lesson' ? 'Lesson guide' : 'Course guide';
            const readableTimeframeMap = {
                year: 'year',
                month: 'month(s)',
                week: 'week',
                day: 'day'
            };
            const timeframeText = readableTimeframeMap[timeframeValue] || '';
            const lessonSuffix = scopeValue === 'lesson' && lessonLabel ? ' • ' + lessonLabel : '';
            const hoursSuffix = selectedHours ? ' • ~' + selectedHours + 'h' : '';
            const weeksSuffix = timeframeValue === 'month' && selectedWeeks ? ' • ~' + selectedWeeks + 'wk' : '';
            const yearsSuffix = timeframeValue === 'year' && selectedYears ? ' • ~' + selectedYears + 'yr' : '';
            focusDisplay.style.display = '';
            focusDisplayText.textContent = readableScope + ' | ' + timeframeText + lessonSuffix + hoursSuffix + weeksSuffix + yearsSuffix;
        }
    }

    function updateGenerateAvailability() {
        if (!generateBtn) {
            return;
        }
        const scopeReady = !!selectedScope;
        const timeframeReady = !!selectedTimeframe;
        const lessonReady = selectedScope !== 'lesson' || !!selectedLessonId;
        const ready = scopeReady && timeframeReady && lessonReady;
        generateBtn.disabled = !ready;
        if (!ready && downloadBtn) {
            downloadBtn.disabled = true;
            if (downloadFooter) {
                downloadFooter.style.display = 'none';
            }
        }
    }

    function setStatus(state, message) {
        if (!statusEl) {
            return;
        }
        if (!message) {
            statusEl.style.display = 'none';
            statusEl.removeAttribute('data-status');
            statusEl.textContent = '';
            return;
        }
        statusEl.style.display = 'flex';
        statusEl.setAttribute('data-status', state);
        if (state === 'loading') {
            statusEl.innerHTML = '<span class="pga-spinner" aria-hidden="true"></span><span>' + message + '</span>';
        } else {
            statusEl.textContent = message;
        }
    }

    function sanitizeHtml(input) {
        if (!input) {
            return '';
        }
        const parser = new DOMParser();
        const doc = parser.parseFromString(input, 'text/html');
        const allowedTags = new Set(['table', 'thead', 'tbody', 'tr', 'th', 'td', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'span', 'br', 'h2', 'h3', 'h4']);

        const traverse = (node) => {
            const children = Array.from(node.childNodes);
            for (const child of children) {
                if (child.nodeType === Node.ELEMENT_NODE) {
                    const tag = child.tagName.toLowerCase();
                    if (!allowedTags.has(tag)) {
                        const fragment = document.createDocumentFragment();
                        while (child.firstChild) {
                            fragment.appendChild(child.firstChild);
                        }
                        child.replaceWith(fragment);
                        continue;
                    }
                    Array.from(child.attributes).forEach(attr => {
                        child.removeAttribute(attr.name);
                    });
                }
                traverse(child);
            }
        };

        traverse(doc.body);
        return doc.body.innerHTML;
    }

    function enhancePacingGuideTable(container, timeframe) {
        const table = container.querySelector('table');
        if (!table) {
            return;
        }
        table.classList.add('pga-pacing-table');

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const rows = Array.from(tbody.querySelectorAll('tr'));
        const newBody = document.createElement('tbody');
        let currentMonth = '';
        let currentWeek = '';
        let weekIndex = 0;
        let monthIndex = 0;
        const isYearView = timeframe === 'year';

        const createHeaderRow = (label, cls, span) => {
            const headerRow = document.createElement('tr');
            headerRow.className = cls;
            const cell = document.createElement('td');
            cell.colSpan = span;
            cell.textContent = label;
            headerRow.appendChild(cell);
            return headerRow;
        };

        const parseSegmentLabel = (rawText, view) => {
            const cleaned = rawText.trim();
            let monthLabel = '';
            let weekLabel = '';
            let dayLabel = '';
            let descriptor = '';

            if (view === 'year') {
                const monthWeekDayMatch = cleaned.match(/^Month\s*(\d+)\s*(?:-|–|:)?\s*Week\s*(\d+)(?:\s*(?:-|–|:)?\s*Day\s*(\d+))?(?:\s*(?:-|–|:)?\s*(.*))?$/i);
                if (monthWeekDayMatch) {
                    monthLabel = `Month ${monthWeekDayMatch[1]}`;
                    weekLabel = `Week ${monthWeekDayMatch[2]}`;
                    if (monthWeekDayMatch[3]) {
                        const extra = monthWeekDayMatch[4]?.trim() || '';
                        descriptor = `Day ${monthWeekDayMatch[3]}${extra ? ' - ' + extra : ''}`;
                    } else {
                        descriptor = monthWeekDayMatch[4]?.trim() || '';
                    }
                } else {
                    const monthMatch = cleaned.match(/Month\s*(\d+)/i);
                    const weekMatch = cleaned.match(/Week\s*(\d+)/i);
                    if (monthMatch) {
                        monthLabel = `Month ${monthMatch[1]}`;
                    }
                    if (weekMatch) {
                        weekLabel = `Week ${weekMatch[1]}`;
                    }
                    const split = cleaned.split(/[:\-–]/);
                    descriptor = split.length > 1 ? split.slice(1).join(' - ').trim() : '';
                }
            } else {
                const weekDayMatch = cleaned.match(/^Week\s*(\d+)\s*(?:-|–|:)?\s*Day\s*(\d+)(?:\s*(?:-|–|:)?\s*(.*))?$/i);
                if (weekDayMatch) {
                    weekLabel = `Week ${weekDayMatch[1]}`;
                    dayLabel = `Day ${weekDayMatch[2]}`;
                    descriptor = weekDayMatch[3]?.trim() || '';
                } else {
                    const dayMatch = cleaned.match(/^Day\s*(\d+)(?:\s*(?:-|–|:)?\s*(.*))?$/i);
                    if (dayMatch) {
                        dayLabel = `Day ${dayMatch[1]}`;
                        descriptor = dayMatch[2]?.trim() || '';
                    } else {
                        const genericWeekMatch = cleaned.match(/Week\s*(\d+)/i);
                        if (genericWeekMatch) {
                            weekLabel = `Week ${genericWeekMatch[1]}`;
                        }
                        descriptor = cleaned;
                    }
                }
            }

            return {
                monthLabel,
                weekLabel,
                dayLabel,
                descriptor,
                raw: cleaned,
            };
        };

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (!cells.length) {
                return;
            }
            const segmentCell = cells[0];
            const segmentInfo = parseSegmentLabel(segmentCell.textContent, timeframe);

            if (segmentInfo.monthLabel && segmentInfo.monthLabel !== currentMonth) {
                const monthRow = createHeaderRow(segmentInfo.monthLabel, 'month-header', cells.length);
                const monthColors = ['#ede9fe', '#dbeafe', '#fee2f2', '#dcfce7', '#fef3c7'];
                monthRow.firstChild.style.backgroundColor = monthColors[monthIndex % monthColors.length];
                newBody.appendChild(monthRow);
                currentMonth = segmentInfo.monthLabel;
                currentWeek = '';
                monthIndex += 1;
                weekIndex = 0;
            }

            if (segmentInfo.weekLabel && segmentInfo.weekLabel !== currentWeek) {
                const weekRow = createHeaderRow(segmentInfo.weekLabel, 'week-header', cells.length);
                const colors = ['#ede9fe', '#dbeafe', '#fee2f2', '#dcfce7', '#fef3c7'];
                weekRow.firstChild.style.setProperty('--week-bg', colors[weekIndex % colors.length]);
                newBody.appendChild(weekRow);
                currentWeek = segmentInfo.weekLabel;
                weekIndex += 1;
            }

            segmentCell.classList.add('segment-cell');
            segmentCell.innerHTML = '';
            const strongLabel = document.createElement('strong');
            if (isYearView) {
                strongLabel.textContent = segmentInfo.weekLabel || segmentInfo.raw;
            } else {
                strongLabel.textContent = segmentInfo.dayLabel || segmentInfo.weekLabel || segmentInfo.raw;
            }
            segmentCell.appendChild(strongLabel);

            const detailText = segmentInfo.descriptor || (!isYearView ? segmentInfo.weekLabel : '');
            if (detailText) {
                const detailDiv = document.createElement('div');
                detailDiv.textContent = detailText;
                segmentCell.appendChild(detailDiv);
            }

            newBody.appendChild(row);
        });

        tbody.replaceWith(newBody);
    }

    if (intakeModal) {
        intakeModal.addEventListener('click', function(event) {
            if (event.target === intakeModal) {
                event.stopPropagation();
            }
        });

        const closeButton = intakeModal.querySelector('[data-action="close-modal"]');
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                closeIntakeModal();
            });
        }

        intakeModal.querySelectorAll('.option-button').forEach(function(button) {
            button.addEventListener('click', function() {
                const optionType = button.getAttribute('data-option');
                const optionValue = button.getAttribute('data-value');

                if (optionType === 'scope') {
                    selectedScope = optionValue;
                    selectedTimeframe = '';
                    selectedLessonId = '';
                    selectedLessonLabel = '';

                    if (lessonPicker) {
                        if (optionValue === 'lesson') {
                            lessonPicker.style.display = 'flex';
                        } else {
                            lessonPicker.style.display = 'none';
                            if (lessonSelect) {
                                lessonSelect.value = '';
                            }
                        }
                    }
                    showModalStep(2);
                    updateSummary(selectedScope, selectedTimeframe, selectedLessonLabel);
                    updateGenerateAvailability();
                }

                if (optionType === 'timeframe') {
                    if (selectedScope === 'lesson' && lessonSelect && !lessonSelect.value) {
                        lessonSelect.focus();
                        return;
                    }

                    selectedTimeframe = optionValue;

                    if (selectedScope === 'lesson' && lessonSelect) {
                        const selectedOption = lessonSelect.options[lessonSelect.selectedIndex];
                        selectedLessonLabel = selectedOption ? selectedOption.text : '';
                        selectedLessonId = lessonSelect.value;
                    }

                    updateSummary(selectedScope, selectedTimeframe, selectedLessonLabel);
                    updateGenerateAvailability();
                    closeIntakeModal();

                    if (weeksField) {
                        weeksField.style.display = optionValue === 'month' ? 'flex' : 'none';
                        if (optionValue !== 'month' && weeksInput) {
                            weeksInput.value = '';
                            selectedWeeks = '';
                        }
                    }
                    if (yearsField) {
                        yearsField.style.display = optionValue === 'year' ? 'flex' : 'none';
                        if (optionValue !== 'year' && yearsInput) {
                            yearsInput.value = '';
                            selectedYears = '';
                        }
                    }
                    updateSummary(selectedScope, selectedTimeframe, selectedLessonLabel);
                }
            });
        });

        const prevStepButton = intakeModal.querySelector('[data-action="prev-step"]');
        if (prevStepButton) {
            prevStepButton.addEventListener('click', function() {
                showModalStep(1);
            });
        }

        if (lessonSelect) {
            lessonSelect.addEventListener('change', function() {
                selectedLessonId = lessonSelect.value;
                selectedLessonLabel = lessonSelect.options[lessonSelect.selectedIndex]?.text || '';
                updateSummary(selectedScope, selectedTimeframe, selectedLessonLabel);
                updateGenerateAvailability();
            });
        }

        openIntakeModal();
    }

    if (hoursInput) {
        hoursInput.addEventListener('input', function() {
            const value = hoursInput.value.trim();
            selectedHours = value;
            updateSummary(selectedScope, selectedTimeframe, selectedLessonLabel);
        });
    }

    if (weeksInput) {
        weeksInput.addEventListener('input', function() {
            selectedWeeks = weeksInput.value.trim();
        });
    }

    if (yearsInput) {
        yearsInput.addEventListener('input', function() {
            selectedYears = yearsInput.value.trim();
        });
    }

    if (notesInput) {
        notesInput.addEventListener('input', function() {
            additionalNotes = notesInput.value;
        });
    }

    if (adjustBtn) {
        adjustBtn.addEventListener('click', function() {
            openIntakeModal();
        });
    }

    async function generatePacingGuide() {
        if (!generateBtn) {
            return;
        }

        if (!selectedScope || !selectedTimeframe || (selectedScope === 'lesson' && !selectedLessonId)) {
            setStatus('error', 'Please finish selecting scope and timeframe first.');
            return;
        }

        setStatus('loading', 'Asking AI to craft your pacing roadmap…');
        if (resultEl) {
            resultEl.style.display = 'none';
            resultEl.innerHTML = '';
        }
        if (downloadBtn) {
            downloadBtn.disabled = true;
        }
        if (downloadFooter) {
            downloadFooter.style.display = 'none';
        }
        latestGuideHtml = '';

        generateBtn.disabled = true;

        const payload = new URLSearchParams();
        payload.append('sesskey', M.cfg.sesskey);
        payload.append('courseid', courseId);
        payload.append('scope', selectedScope);
        payload.append('timeframe', selectedTimeframe);
        if (selectedScope === 'lesson') {
            payload.append('lessonid', selectedLessonId);
            if (selectedLessonLabel) {
                payload.append('lessonname', selectedLessonLabel);
            }
        }
        if (selectedHours) {
            payload.append('hours', selectedHours);
        }
        if (selectedTimeframe === 'month' && selectedWeeks) {
            payload.append('weeks', selectedWeeks);
        }
        if (selectedTimeframe === 'year' && selectedYears) {
            payload.append('years', selectedYears);
        }
        if (additionalNotes) {
            payload.append('notes', additionalNotes);
        }

        try {
            const response = await fetch(generateEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: payload.toString(),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Request failed with status ' + response.status);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'AI could not produce a pacing guide right now.');
            }

            const safeHtml = sanitizeHtml(data.guide || '');
            if (resultEl) {
                resultEl.innerHTML = safeHtml || '<p>No pacing guide returned.</p>';
                resultEl.style.display = safeHtml ? 'block' : 'none';
                if (safeHtml) {
                    setTimeout(() => enhancePacingGuideTable(resultEl, selectedTimeframe), 0);
                }
            }
            latestGuideHtml = safeHtml;
            if (downloadBtn) {
                downloadBtn.disabled = !safeHtml;
            }
            if (downloadFooter) {
                downloadFooter.style.display = safeHtml ? 'flex' : 'none';
            }

            setStatus('success', data.statusmessage || 'Pacing guide generated. Review and refine as needed.');
        } catch (error) {
            console.error('Pacing guide error:', error);
            setStatus('error', error.message || 'Unexpected error while generating pacing guide.');
            latestGuideHtml = '';
            if (downloadBtn) {
                downloadBtn.disabled = true;
            }
            if (downloadFooter) {
                downloadFooter.style.display = 'none';
            }
        } finally {
            updateGenerateAvailability();
        }
    }

    if (generateBtn) {
        generateBtn.addEventListener('click', generatePacingGuide);
    }

    async function downloadPdf() {
        if (!latestGuideHtml || !downloadBtn) {
            return;
        }

        downloadBtn.disabled = true;
        const payload = new URLSearchParams();
        payload.append('sesskey', M.cfg.sesskey);
        payload.append('courseid', courseId);
        payload.append('html', latestGuideHtml);
        payload.append('scope', selectedScope);
        payload.append('timeframe', selectedTimeframe);
        if (selectedScope === 'lesson') {
            payload.append('lessonname', selectedLessonLabel);
        }

        try {
            const response = await fetch(M.cfg.wwwroot + '/theme/remui_kids/teacher/pacing_guide_download.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: payload.toString(),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Download failed with status ' + response.status);
            }

            const blob = await response.blob();
            if (blob.size === 0) {
                throw new Error('Empty PDF response');
            }

            const filename = 'pacing_guide_' + courseId + '_' + Date.now() + '.pdf';
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (error) {
            console.error('PDF download error:', error);
            setStatus('error', error.message || 'Unable to download PDF right now.');
        } finally {
            if (latestGuideHtml) {
                downloadBtn.disabled = false;
            }
        }
    }

    if (downloadBtn) {
        downloadBtn.addEventListener('click', downloadPdf);
    }
});
</script>

<?php
echo $OUTPUT->footer();