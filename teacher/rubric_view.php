<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

$cmid = required_param('cmid', PARAM_INT);
// Fetch CM and course, but DO NOT bind to $PAGE via set_cm.
$cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$modulecontext = context_module::instance($cmid);

if (!has_capability('moodle/course:update', $modulecontext) && !has_capability('moodle/site:config', $modulecontext)) {
    throw new required_capability_exception($modulecontext, 'moodle/course:update', 'nopermissions', '');
}

// Standalone custom UI (no course tabs) — use system context to avoid CM navigation/tabs.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/rubric_view.php', ['cmid' => $cmid]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Rubric Details');

$rubric = theme_remui_kids_get_rubric_by_cmid($cmid);

echo $OUTPUT->header();

// Minimal custom styling
echo '<style>
.rubric-view-container{max-width:1200px;margin:0 auto;padding:24px}
.rubric-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.rubric-title{font-size:24px;font-weight:700;margin:0}
.rubric-subtitle{color:#6b7280;margin:4px 0 0}
.rubric-table{width:100%;border-collapse:separate;border-spacing:0}
.rubric-table thead th{background:#f7f9fc;border-bottom:1px solid #e5e7eb;padding:12px;text-align:left}
.rubric-table tbody td{border-bottom:1px solid #f0f2f5;padding:12px;vertical-align:top}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px}
.muted{color:#9ca3af}
.btn-back{display:inline-block;padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:#111827;background:#fff}
</style>';

echo '<div class="rubric-view-container">';
echo '<div class="rubric-header">';
echo '<div>';
if ($rubric) {
    echo '<h2 class="rubric-title">' . format_string($rubric['assignment_name']) . ' — <span class="pill">' . format_string($rubric['rubric_name']) . '</span></h2>';
    echo '<p class="rubric-subtitle">' . format_string($rubric['course_name']) . '</p>';
} else {
    echo '<h2 class="rubric-title">Rubric</h2>';
}
echo '</div>';
echo '<div><a class="btn-back" href="' . (new moodle_url('/theme/remui_kids/teacher/rubrics.php'))->out() . '">← Back to Rubrics</a></div>';
echo '</div>';

if (!$rubric) {
    echo '<div class="alert alert-warning">Rubric not found for this activity.</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// Build a table: rows = criteria, columns = levels
$maxlevels = 0;
foreach ($rubric['criteria'] as $c) {
    $maxlevels = max($maxlevels, count($c['levels']));
}

echo '<div class="rubric-table-wrapper">';
echo '<table class="rubric-table">';

// Header row
echo '<thead><tr><th style="width:28%">Criterion</th>';
for ($i = 0; $i < $maxlevels; $i++) {
    echo '<th>Level ' . ($i + 1) . '</th>';
}
echo '</tr></thead>';

echo '<tbody>';
foreach ($rubric['criteria'] as $criterion) {
    echo '<tr>';
    echo '<td>' . format_text($criterion['description'] ?? '', FORMAT_HTML) . '</td>';
    
    $levels = $criterion['levels'];
    for ($i = 0; $i < $maxlevels; $i++) {
        if (isset($levels[$i])) {
            $lvl = $levels[$i];
            $cell = '<div class="rubric-level-score"><strong>' . format_float($lvl->score ?? 0, 2) . '</strong></div>';
            $cell .= '<div class="rubric-level-desc">' . format_text($lvl->definition ?? '', FORMAT_HTML) . '</div>';
        } else {
            $cell = '<span class="muted">—</span>';
        }
        echo '<td>' . $cell . '</td>';
    }
    echo '</tr>';
}
echo '</tbody>';
echo '</table>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();


