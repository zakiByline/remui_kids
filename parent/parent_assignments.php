<?php
/**
 * Parent Dashboard - Assignments Page
 * View children's assignments and submissions with modern UI
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_assignments.php');
$PAGE->set_title('Assignments - Parent Dashboard');
$PAGE->set_heading('Assignments');
$PAGE->set_pagelayout('base');

$userid = $USER->id;
$export = optional_param('export', '', PARAM_ALPHA);

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

// Get children (reuse query)
require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get assignments with complete information
$assignments = [];
$assignment_stats = [
    'total' => 0,
    'submitted' => 0,
    'pending' => 0,
    'overdue' => 0,
    'graded' => 0,
    'average_grade' => 0
];

$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);
    
    // Enhanced query with grades, feedback, course module IDs, and file counts
    $sql = "SELECT a.id, a.name, a.duedate, a.allowsubmissionsfromdate,
                   a.grade as maxgrade, a.intro,
                   c.fullname as coursename, c.id as courseid,
                   u.firstname, u.lastname, u.id as userid,
                   asub.status, asub.timemodified as submitted_time,
                   asub.attemptnumber, asub.id as submission_id,
                   ag.grade as received_grade, ag.grader, ag.timemodified as graded_time,
                   ag.feedback as grade_feedback,
                   cm.id as cmid,
                   COALESCE(file_sub.numfiles, 0) as file_count
            FROM {assign} a
            JOIN {course} c ON c.id = a.course
            JOIN {user_enrolments} ue ON ue.userid $insql
            JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
            JOIN {user} u ON u.id = ue.userid
            LEFT JOIN {course_modules} cm ON cm.instance = a.id 
                AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                AND cm.course = c.id
                AND cm.deletioninprogress = 0
            LEFT JOIN {assign_submission} asub ON asub.assignment = a.id 
                AND asub.userid = u.id 
                AND asub.latest = 1
            LEFT JOIN {assign_grades} ag ON ag.assignment = a.id 
                AND ag.userid = u.id
                AND ag.attemptnumber = (SELECT MAX(attemptnumber) FROM {assign_grades} WHERE assignment = a.id AND userid = u.id)
            LEFT JOIN {assignsubmission_file} file_sub ON file_sub.submission = asub.id
            WHERE c.visible = 1
            ORDER BY a.duedate DESC";
    
    try {
        $assignment_records = $DB->get_records_sql($sql, $params);
        
        // Process assignments and calculate statistics
        $total_grades = 0;
        $graded_count = 0;
        
        foreach ($assignment_records as $assign) {
            $assignment_stats['total']++;
            
            // Determine status
            $status_label = 'Pending';
            $status_class = 'pending';
            $status_color = '#fef3c7';
            $status_text_color = '#92400e';
            
            if ($assign->status === 'submitted') {
                $status_label = 'Submitted';
                $status_class = 'submitted';
                $status_color = '#d1fae5';
                $status_text_color = '#065f46';
                $assignment_stats['submitted']++;
                
                // Check if late
                if ($assign->duedate > 0 && $assign->submitted_time > $assign->duedate) {
                    $status_label = 'Submitted (Late)';
                    $status_class = 'submitted-late';
                    $status_color = '#fef3c7';
                    $status_text_color = '#92400e';
                }
            } elseif ($assign->status === 'draft') {
                $status_label = 'Draft';
                $status_class = 'draft';
                $status_color = '#e0e7ff';
                $status_text_color = '#3730a3';
                $assignment_stats['pending']++;
            } elseif ($assign->duedate > 0 && $assign->duedate < time()) {
                $status_label = 'Overdue';
                $status_class = 'overdue';
                $status_color = '#fee2e2';
                $status_text_color = '#991b1b';
                $assignment_stats['overdue']++;
            } else {
                $assignment_stats['pending']++;
            }
            
            // Check if graded
            $is_graded = ($assign->received_grade !== null && $assign->received_grade >= 0);
            if ($is_graded) {
                $assignment_stats['graded']++;
                $total_grades += $assign->received_grade;
                $graded_count++;
            }
            
            // Calculate grade percentage
            $grade_percentage = 0;
            if ($is_graded && $assign->maxgrade > 0) {
                $grade_percentage = ($assign->received_grade / $assign->maxgrade) * 100;
            }
            
            // Build assignment URL - Use our custom parent theme page
            $assignment_url = '';
            if ($assign->cmid && $assign->userid && $assign->courseid) {
                $assignment_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                    'cmid' => $assign->cmid,
                    'child' => $assign->userid,
                    'courseid' => $assign->courseid
                ]))->out();
            }
            
            $assignments[] = [
                'id' => $assign->id,
                'cmid' => $assign->cmid,
                'name' => $assign->name,
                'course' => $assign->coursename,
                'courseid' => $assign->courseid,
                'student' => fullname($assign),
                'student_id' => $assign->userid,
                'due_date' => $assign->duedate,
                'submitted_time' => $assign->submitted_time,
                'status' => $status_label,
                'status_class' => $status_class,
                'status_color' => $status_color,
                'status_text_color' => $status_text_color,
                'is_graded' => $is_graded,
                'grade' => $assign->received_grade,
                'maxgrade' => $assign->maxgrade,
                'grade_percentage' => $grade_percentage,
                'feedback' => $assign->grade_feedback,
                'intro' => $assign->intro,
                'file_count' => (int)$assign->file_count,
                'url' => $assignment_url,
                'graded_time' => $assign->graded_time
            ];
        }
        
        // Calculate average grade
        if ($graded_count > 0) {
            $assignment_stats['average_grade'] = $total_grades / $graded_count;
        }
    } catch (Exception $e) {
        debugging('Error fetching assignments: ' . $e->getMessage());
    }
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assignments_' . ($selected_child ?: 'all') . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Course', 'Assignment', 'Due Date', 'Status', 'Grade', 'Submitted On', 'Files']);
    foreach ($assignments as $assign) {
        fputcsv($out, [
            $assign['student'],
            $assign['course'],
            $assign['name'],
            $assign['due_date'] ? date('Y-m-d H:i', $assign['due_date']) : '-',
            $assign['status'],
            $assign['is_graded'] ? number_format($assign['grade'], 1) . '/' . number_format($assign['maxgrade'], 1) : 'Not graded',
            $assign['submitted_time'] ? date('Y-m-d H:i', $assign['submitted_time']) : '-',
            $assign['file_count']
        ]);
    }
    fclose($out);
    exit;
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/parent_unified.css">';
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/parent_dashboard.css">';
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
?>

<style>
/* Modern Assignment Page Styles - Matching parent_reports.php */
.parent-assignments-page {
    padding: 32px;
    max-width: 1600px;
    margin: 0 auto;
    margin-left: 280px;
    width: calc(100% - 280px);
    max-width: calc(100% - 280px);
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Comprehensive Responsive Design */
@media (max-width: 1024px) {
    .parent-assignments-page {
        margin-left: 260px;
        width: calc(100% - 260px);
        max-width: calc(100% - 260px);
        padding: 24px;
    }
}

@media (max-width: 768px) {
    .parent-assignments-page {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 16px !important;
    }
    
    .page-title-modern {
        font-size: 24px !important;
    }
    
    /* Make all grids single column */
    [style*="grid-template-columns"],
    [style*="display: grid"],
    .assignment-grid {
        grid-template-columns: 1fr !important;
    }
    
    /* Stack flex containers */
    [style*="display: flex"],
    .filter-actions {
        flex-direction: column !important;
    }
    
    /* Make tables scrollable */
    table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust font sizes */
    h1, h2, h3 {
        font-size: 1.2em !important;
    }
}

@media (max-width: 480px) {
    .parent-assignments-page {
        padding: 12px !important;
    }
    
    .page-title-modern {
        font-size: 20px !important;
    }
    
    body {
        font-size: 14px !important;
    }
}

.page-header-modern {
    margin-bottom: 32px;
}

.page-title-modern {
    font-size: 32px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title-modern i {
    color: #3b82f6;
}

.page-subtitle-modern {
    font-size: 16px;
    color: #64748b;
    margin: 0;
}

/* Stats Grid - Modern Design */
.stats-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card-modern {
    background: #ffffff;
    border-radius: 24px;
    padding: 32px 28px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), 0 4px 16px rgba(0, 0, 0, 0.08);
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: space-between;
    min-height: 180px;
    cursor: pointer;
    transform: translateY(0);
    animation: cardFadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1) backwards;
}

@keyframes cardFadeIn {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.stat-card-modern::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -30%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
    opacity: 0.6;
    transition: all 0.5s ease;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}

.stat-card-modern > * {
    position: relative;
    z-index: 1;
}

.stat-card-modern:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 20px 48px rgba(0, 0, 0, 0.18), 0 12px 24px rgba(0, 0, 0, 0.12);
}

.stat-card-modern.blue {
    background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 30%, #93c5fd 70%, #dbeafe 100%);
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.25), 0 4px 16px rgba(59, 130, 246, 0.15);
}

.stat-card-modern.green {
    background: linear-gradient(135deg, #10b981 0%, #34d399 30%, #6ee7b7 70%, #d1fae5 100%);
    box-shadow: 0 8px 32px rgba(16, 185, 129, 0.25), 0 4px 16px rgba(16, 185, 129, 0.15);
}

.stat-card-modern.orange {
    background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 30%, #fcd34d 70%, #fef3c7 100%);
    box-shadow: 0 8px 32px rgba(245, 158, 11, 0.25), 0 4px 16px rgba(245, 158, 11, 0.15);
}

.stat-card-modern.red {
    background: linear-gradient(135deg, #ef4444 0%, #f87171 30%, #fca5a5 70%, #fee2e2 100%);
    box-shadow: 0 8px 32px rgba(239, 68, 68, 0.25), 0 4px 16px rgba(239, 68, 68, 0.15);
}

.stat-card-modern.purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 30%, #c4b5fd 70%, #e9d5ff 100%);
    box-shadow: 0 8px 32px rgba(139, 92, 246, 0.25), 0 4px 16px rgba(139, 92, 246, 0.15);
}

.stat-icon-modern {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.25);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #ffffff;
    margin-bottom: 16px;
}

.stat-number {
    font-size: 36px;
    font-weight: 700;
    color: #ffffff;
    margin: 8px 0;
    line-height: 1.2;
}

.stat-label-modern {
    font-size: 14px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Filters Section */
.filters-section {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-input, .filter-select {
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #ffffff;
    color: #0f172a;
}

.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.filter-count {
    font-size: 14px;
    color: #64748b;
    font-weight: 600;
}

.filter-count strong {
    color: #0f172a;
    font-size: 18px;
}

.export-btn {
    padding: 12px 24px;
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.export-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);
}

/* Report Table - Modern Design */
.report-table {
    background: #ffffff;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow-x: auto;
}

.report-table h2 {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 24px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-table h2 i {
    color: #3b82f6;
}

.report-table table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.report-table thead {
    background: #f8fafc;
}

.report-table th {
    padding: 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}

.report-table td {
    padding: 20px 16px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
    color: #0f172a;
    vertical-align: middle;
}

.report-table tbody tr {
    transition: all 0.2s ease;
}

.report-table tbody tr:hover {
    background: #f8fafc;
    transform: scale(1.01);
}

.report-table tbody tr:last-child td {
    border-bottom: none;
}

.assignment-name {
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 4px;
}

.assignment-name a {
    color: #3b82f6;
    text-decoration: none;
    transition: color 0.2s ease;
}

.assignment-name a:hover {
    color: #2563eb;
    text-decoration: underline;
}

.assignment-intro {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.5;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.submitted {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.overdue {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.draft {
    background: #e0e7ff;
    color: #3730a3;
}

.status-badge.submitted-late {
    background: #fef3c7;
    color: #92400e;
}

.grade-display {
    font-weight: 700;
    color: #10b981;
    font-size: 16px;
}

.grade-percentage {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}

.date-display {
    font-weight: 600;
    color: #0f172a;
}

.date-meta {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}

.file-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f1f5f9;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}

.feedback-row {
    background: #f8fafc !important;
}

.feedback-content {
    padding: 16px;
    background: #ffffff;
    border-left: 4px solid #3b82f6;
    border-radius: 8px;
    margin-top: 8px;
}

.feedback-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 12px;
}

.empty-state {
    text-align: center;
    padding: 80px 32px;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.empty-state i {
    font-size: 64px;
    color: #cbd5e1;
    margin-bottom: 24px;
}

.empty-state h3 {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 12px 0;
}

.empty-state p {
    font-size: 16px;
    color: #64748b;
    margin: 0 0 24px 0;
}

.context-chip-modern {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.context-chip-modern i {
    color: #0284c7;
    font-size: 16px;
}

.context-chip-modern span {
    font-size: 14px;
    font-weight: 600;
    color: #0c4a6e;
}

.context-chip-modern a {
    margin-left: auto;
    color: #0284c7;
    text-decoration: none;
}

@media (max-width: 768px) {
    .parent-assignments-page {
        padding: 16px;
    }
    
    .stats-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .report-table {
        padding: 16px;
    }
    
    .report-table table {
        font-size: 12px;
    }
    
    .report-table th,
    .report-table td {
        padding: 12px 8px;
    }
}
</style>

<div class="parent-assignments-page">
    <div class="page-header-modern">
        <h1 class="page-title-modern">
            <i class="fas fa-clipboard-list"></i>
            Assignments & Deadlines
        </h1>
        <p class="page-subtitle-modern">Monitor every assignment, submission, and teacher feedback in one place.</p>
    </div>

    <?php 
    if ($selected_child && $selected_child !== 'all' && $selected_child != 0):
        $selected_child_name = '';
        foreach ($children as $child) {
            if ($child['id'] == $selected_child) {
                $selected_child_name = $child['name'];
                break;
            }
        }
        if ($selected_child_name):
    ?>
    <div class="context-chip-modern">
        <i class="fas fa-user-graduate"></i>
        <span>Viewing: <strong><?php echo s($selected_child_name); ?></strong></span>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" title="Change child">
            <i class="fas fa-sync-alt"></i>
        </a>
    </div>
    <?php endif; endif; ?>

    <?php if (!empty($children)): ?>
    <?php if ($assignment_stats['total'] > 0): ?>
    <!-- Statistics Cards -->
    <div class="stats-grid-modern">
        <div class="stat-card-modern blue" style="animation-delay: 0.1s">
            <div class="stat-icon-modern"><i class="fas fa-file-alt"></i></div>
            <div class="stat-number"><?php echo $assignment_stats['total']; ?></div>
            <div class="stat-label-modern">Total Assignments</div>
        </div>
        
        <div class="stat-card-modern green" style="animation-delay: 0.2s">
            <div class="stat-icon-modern"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number"><?php echo $assignment_stats['submitted']; ?></div>
            <div class="stat-label-modern">Submitted</div>
        </div>
        
        <div class="stat-card-modern orange" style="animation-delay: 0.3s">
            <div class="stat-icon-modern"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-number"><?php echo $assignment_stats['pending']; ?></div>
            <div class="stat-label-modern">Pending</div>
        </div>
        
        <div class="stat-card-modern red" style="animation-delay: 0.4s">
            <div class="stat-icon-modern"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-number"><?php echo $assignment_stats['overdue']; ?></div>
            <div class="stat-label-modern">Overdue</div>
        </div>
        
        <div class="stat-card-modern purple" style="animation-delay: 0.5s">
            <div class="stat-icon-modern"><i class="fas fa-star"></i></div>
            <div class="stat-number"><?php echo $assignment_stats['graded']; ?></div>
            <div class="stat-label-modern">Graded</div>
        </div>
        
        <?php if ($assignment_stats['graded'] > 0): ?>
        <div class="stat-card-modern blue" style="animation-delay: 0.6s">
            <div class="stat-icon-modern"><i class="fas fa-percentage"></i></div>
            <div class="stat-number"><?php echo number_format($assignment_stats['average_grade'], 1); ?></div>
            <div class="stat-label-modern">Average Grade</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="assignmentSearch">Search Assignments</label>
                <input type="text" id="assignmentSearch" class="filter-input" placeholder="Search by assignment, course, or student...">
            </div>
            
            <div class="filter-group">
                <label for="assignmentStatusFilter">Status</label>
                <select id="assignmentStatusFilter" class="filter-select">
                    <option value="all">All Statuses</option>
                    <option value="submitted">Submitted</option>
                    <option value="submitted-late">Submitted (Late)</option>
                    <option value="draft">Draft</option>
                    <option value="pending">Pending</option>
                    <option value="overdue">Overdue</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="assignmentDueFilter">Due Date</label>
                <select id="assignmentDueFilter" class="filter-select">
                    <option value="all">Any Time</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="soon">Due in 7 Days</option>
                    <option value="overdue">Overdue</option>
                    <option value="past">Past Due</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="assignmentSort">Sort By</label>
                <select id="assignmentSort" class="filter-select">
                    <option value="due-asc">Due Soonest</option>
                    <option value="due-desc">Due Latest</option>
                    <option value="course-az">Course A → Z</option>
                    <option value="course-za">Course Z → A</option>
                    <option value="status">Status</option>
                    <option value="grade-desc">Grade (High to Low)</option>
                    <option value="grade-asc">Grade (Low to High)</option>
                </select>
            </div>
        </div>
        
        <div class="filter-actions">
            <div class="filter-count">
                Showing <strong id="assignmentVisibleCount"><?php echo count($assignments); ?></strong> assignments
            </div>
            <a href="?export=csv" class="export-btn">
                <i class="fas fa-download"></i>
                Export CSV
            </a>
        </div>
    </div>

    <!-- Assignments Table -->
    <div class="report-table">
        <h2><i class="fas fa-list"></i> Assignment Overview</h2>
        
        <?php if (!empty($assignments)): ?>
        <table>
            <thead>
                <tr>
                    <th>Assignment</th>
                    <th>Course</th>
                    <th>Student</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Grade</th>
                    <th>Submitted</th>
                    <th>Files</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $assign):
                    $days_until = $assign['due_date'] > 0 ? floor(($assign['due_date'] - time()) / 86400) : 999;
                    $due_label = $assign['due_date'] > 0 ? userdate($assign['due_date'], '%d %b %Y') : 'No due date';
                    $due_meta = '';
                    if ($assign['due_date'] > 0) {
                        $due_meta = $days_until >= 0 ? $days_until . ' days left' : abs($days_until) . ' days overdue';
                    }
                ?>
                <tr class="assignment-row"
                    data-id="<?php echo $assign['id']; ?>"
                    data-status="<?php echo $assign['status_class']; ?>"
                    data-course="<?php echo strtolower(s($assign['course'])); ?>"
                    data-assignment="<?php echo strtolower(s($assign['name'])); ?>"
                    data-student="<?php echo strtolower(s($assign['student'])); ?>"
                    data-due="<?php echo (int)$assign['due_date']; ?>"
                    data-graded="<?php echo $assign['is_graded'] ? 'yes' : 'no'; ?>"
                    data-grade="<?php echo $assign['is_graded'] ? $assign['grade_percentage'] : 0; ?>">
                    
                    <td>
                        <div class="assignment-name">
                            <?php if ($assign['url']): ?>
                                <a href="<?php echo $assign['url']; ?>">
                                    <?php echo s($assign['name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo s($assign['name']); ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($assign['intro']): ?>
                            <div class="assignment-intro">
                                <?php echo strip_tags(format_text($assign['intro'], FORMAT_HTML, ['para' => false])); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    
                    <td><?php echo s($assign['course']); ?></td>
                    
                    <td><?php echo s($assign['student']); ?></td>
                    
                    <td>
                        <div class="date-display"><?php echo $due_label; ?></div>
                        <?php if ($due_meta): ?>
                            <div class="date-meta"><?php echo $due_meta; ?></div>
                        <?php endif; ?>
                    </td>
                    
                    <td>
                        <span class="status-badge <?php echo $assign['status_class']; ?>">
                            <?php echo s($assign['status']); ?>
                        </span>
                    </td>
                    
                    <td>
                        <?php if ($assign['is_graded']): ?>
                            <div class="grade-display">
                                <?php echo number_format($assign['grade'], 1); ?>/<?php echo number_format($assign['maxgrade'], 1); ?>
                            </div>
                            <div class="grade-percentage">
                                <?php echo number_format($assign['grade_percentage'], 1); ?>%
                            </div>
                        <?php else: ?>
                            <span style="color: #94a3b8;">Not graded</span>
                        <?php endif; ?>
                    </td>
                    
                    <td>
                        <?php if ($assign['submitted_time']): ?>
                            <div class="date-display"><?php echo userdate($assign['submitted_time'], '%d %b %Y'); ?></div>
                            <div class="date-meta"><?php echo userdate($assign['submitted_time'], '%H:%i'); ?></div>
                        <?php else: ?>
                            <span style="color: #94a3b8;">-</span>
                        <?php endif; ?>
                    </td>
                    
                    <td>
                        <?php if ($assign['file_count'] > 0): ?>
                            <span class="file-count">
                                <i class="fas fa-paperclip"></i>
                                <?php echo $assign['file_count']; ?> file<?php echo $assign['file_count'] > 1 ? 's' : ''; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #94a3b8;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if ($assign['is_graded'] && !empty($assign['feedback'])): ?>
                <tr class="feedback-row" data-parent="<?php echo $assign['id']; ?>">
                    <td colspan="8">
                        <div class="feedback-content">
                            <div class="feedback-label">
                                <i class="fas fa-comment-dots"></i>
                                Teacher Feedback
                            </div>
                            <div><?php echo format_text($assign['feedback'], FORMAT_HTML); ?></div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-tasks"></i>
            <h3>No assignments found</h3>
            <p>When teachers publish assignments, they will appear here with live status.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-tasks"></i>
        <h3>No assignments found</h3>
        <p>No assignments have been assigned to your children yet.</p>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-users"></i>
        <h3>No children linked</h3>
        <p>Link a student account to monitor their assignments.</p>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" class="export-btn" style="display: inline-flex;">
            Setup Now
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rows = Array.from(document.querySelectorAll('.assignment-row'));
    const feedbackRows = Array.from(document.querySelectorAll('.feedback-row'));
    const feedbackMap = {};
    
    feedbackRows.forEach(row => {
        const parentId = row.dataset.parent;
        if (parentId) {
            feedbackMap[parentId] = row;
        }
    });
    
    const searchInput = document.getElementById('assignmentSearch');
    const statusFilter = document.getElementById('assignmentStatusFilter');
    const dueFilter = document.getElementById('assignmentDueFilter');
    const sortSelect = document.getElementById('assignmentSort');
    const countNode = document.getElementById('assignmentVisibleCount');
    const tbody = document.querySelector('.report-table tbody');
    
    if (!rows.length || !tbody) {
        return;
    }
    
    function applyFilters() {
        const query = searchInput.value.toLowerCase().trim();
        const statusValue = statusFilter.value;
        const dueValue = dueFilter.value;
        const sortMode = sortSelect.value;
        const now = Math.floor(Date.now() / 1000);
        const sevenDays = now + (7 * 86400);
        
        let filtered = rows.filter(row => {
            const haystack = (row.dataset.course + ' ' + row.dataset.assignment + ' ' + row.dataset.student).toLowerCase();
            if (query && haystack.indexOf(query) === -1) {
                return false;
            }
            
            if (statusValue !== 'all' && row.dataset.status !== statusValue) {
                return false;
            }
            
            const due = parseInt(row.dataset.due, 10);
            if (dueValue === 'upcoming' && (due === 0 || due < now)) return false;
            if (dueValue === 'soon' && (due === 0 || due < now || due > sevenDays)) return false;
            if (dueValue === 'overdue' && (due === 0 || due >= now)) return false;
            if (dueValue === 'past' && (due === 0 || due >= now)) return false;
            
            return true;
        });
        
        // Sort
        filtered.sort((a, b) => {
            const dueA = parseInt(a.dataset.due, 10);
            const dueB = parseInt(b.dataset.due, 10);
            const gradeA = parseFloat(a.dataset.grade || 0);
            const gradeB = parseFloat(b.dataset.grade || 0);
            
            switch (sortMode) {
                case 'due-desc':
                    return (dueB || 999999999) - (dueA || 999999999);
                case 'course-az':
                    return (a.dataset.course || '').localeCompare(b.dataset.course || '');
                case 'course-za':
                    return (b.dataset.course || '').localeCompare(a.dataset.course || '');
                case 'status':
                    return (a.dataset.status || '').localeCompare(b.dataset.status || '');
                case 'grade-desc':
                    return gradeB - gradeA;
                case 'grade-asc':
                    return gradeA - gradeB;
                case 'due-asc':
                default:
                    return (dueA || 999999999) - (dueB || 999999999);
            }
        });
        
        // Clear and rebuild table
        tbody.innerHTML = '';
        filtered.forEach(row => {
            tbody.appendChild(row.cloneNode(true));
            const feedback = feedbackMap[row.dataset.id];
            if (feedback) {
                tbody.appendChild(feedback.cloneNode(true));
            }
        });
        
        if (countNode) {
            countNode.textContent = filtered.length;
        }
    }
    
    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
    dueFilter.addEventListener('change', applyFilters);
    sortSelect.addEventListener('change', applyFilters);
    
    applyFilters();
});
</script>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php echo $OUTPUT->footer(); ?>
