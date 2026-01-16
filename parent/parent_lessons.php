<?php
/**
 * Parent Dashboard - Lessons & Learning Activities Page
 * Modern, clean design with comprehensive activity tracking
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

// ========================================
// PARENT ACCESS CONTROL
// ========================================
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
$system_context = context_system::instance();

$is_parent = false;
if ($parent_role) {
    $is_parent = user_has_role_assignment($USER->id, $parent_role->id, $system_context->id);
    
    if (!$is_parent) {
        $parent_assignments = $DB->get_records_sql(
            "SELECT ra.id 
             FROM {role_assignments} ra
             JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid = ?
             AND ra.roleid = ?
             AND ctx.contextlevel = ?",
            [$USER->id, $parent_role->id, CONTEXT_USER]
        );
        $is_parent = !empty($parent_assignments);
    }
}

if (!$is_parent) {
    redirect(
        new moodle_url('/my/'),
        'You do not have permission to access the parent dashboard.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_lessons.php');
$PAGE->set_title('Learning Activities - Parent Dashboard');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get lessons with activity details
$all_activities = [];
$activity_stats = [
    'total' => 0,
    'lesson' => 0,
    'assign' => 0,
    'quiz' => 0,
    'resource' => 0,
    'page' => 0,
    'forum' => 0,
    'url' => 0,
    'other' => 0
];
$courses_with_sections = [];
$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

// Debug mode
$debug_mode = optional_param('debug', 0, PARAM_INT);

if (!empty($target_children)) {
    foreach ($target_children as $child_id) {
        try {
            // Get enrolled courses
            $enrolled_courses = enrol_get_users_courses($child_id, true);
            
            if ($debug_mode) {
                echo "<div style='background: #dbeafe; padding: 15px; margin: 20px; border-radius: 8px;'>";
                echo "<strong>Debug:</strong> Child $child_id has " . count($enrolled_courses) . " enrolled courses<br>";
                echo "</div>";
            }
            
            foreach ($enrolled_courses as $course) {
                // Get course sections - FETCH ALL SECTIONS (including hidden ones for parent visibility)
                $sql_sections = "SELECT cs.id, cs.name, cs.section, cs.summary, cs.visible, cs.sequence
                                 FROM {course_sections} cs
                                 WHERE cs.course = :courseid
                                 ORDER BY cs.section ASC";
                
                $sections = $DB->get_records_sql($sql_sections, ['courseid' => $course->id]);
                
                if ($debug_mode) {
                    echo "<div style='background: #fef3c7; padding: 10px; margin: 10px 20px; border-radius: 6px;'>";
                    echo "<strong>Course:</strong> {$course->fullname} - Found " . count($sections) . " sections<br>";
                    echo "</div>";
                }
                
                $course_data = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'sections' => []
                ];
                
                foreach ($sections as $section) {
                    // Get ALL activities in this section (including hidden - parents should see everything)
                    $sql_activities = "SELECT cm.id, cm.instance, cm.added, cm.completion, cm.visible,
                                             m.name as modname
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                      WHERE cm.course = :courseid
                                      AND cm.section = :sectionnum
                                      AND cm.deletioninprogress = 0
                                      ORDER BY cm.id ASC";
                    
                    $activities = $DB->get_records_sql($sql_activities, [
                        'courseid' => $course->id,
                        'sectionnum' => $section->section
                    ]);
                    
                    if ($debug_mode && !empty($activities)) {
                        echo "<div style='background: #d1fae5; padding: 8px; margin: 5px 20px 5px 40px; border-radius: 4px; font-size: 12px;'>";
                        echo "<strong>Section {$section->section}:</strong> " . (!empty($section->name) ? $section->name : 'Topic ' . $section->section);
                        echo " - " . count($activities) . " activities<br>";
                        echo "</div>";
                    }
                    
                    // Get details for each activity
                    $activity_details = [];
                    foreach ($activities as $activity) {
                        $detail = null;
                        
                        try {
                            // Get activity-specific details based on type
                            $table_map = [
                                'lesson' => 'lesson',
                                'assign' => 'assign',
                                'quiz' => 'quiz',
                                'resource' => 'resource',
                                'page' => 'page',
                                'forum' => 'forum',
                                'url' => 'url',
                                'book' => 'book',
                                'folder' => 'folder',
                                'label' => 'label'
                            ];
                            
                            if (isset($table_map[$activity->modname])) {
                                $detail = $DB->get_record($table_map[$activity->modname], ['id' => $activity->instance]);
                            }
                            
                            if ($detail) {
                                // Check completion status for this child
                                $completion_status = null;
                                $completion_record = $DB->get_record('course_modules_completion', [
                                    'coursemoduleid' => $activity->id,
                                    'userid' => $child_id
                                ]);
                                
                                if ($completion_record) {
                                    $completion_status = $completion_record->completionstate;
                                }
                                
                                $activity_data = [
                                    'id' => $activity->id,
                                    'name' => $detail->name ?? $detail->title ?? 'Unnamed Activity',
                                    'type' => $activity->modname,
                                    'added' => $activity->added,
                                    'completion' => $activity->completion,
                                    'completion_status' => $completion_status,
                                    'available' => $detail->available ?? null,
                                    'deadline' => $detail->deadline ?? $detail->duedate ?? $detail->timeclose ?? null,
                                    'course' => $course->fullname,
                                    'course_id' => $course->id,
                                    'section' => (!empty($section->name)) ? $section->name : 'Topic ' . $section->section,
                                    'section_summary' => $section->summary,
                                    'visible' => $activity->visible ?? 1,
                                    'section_visible' => $section->visible ?? 1
                                ];
                                
                                if ($debug_mode) {
                                    error_log("Activity added: {$detail->name} (Type: {$activity->modname}, Visible: {$activity->visible})");
                                }
                                
                                $activity_details[] = $activity_data;
                                $all_activities[] = $activity_data;
                                
                                // Update statistics
                                $activity_stats['total']++;
                                if (isset($activity_stats[$activity->modname])) {
                                    $activity_stats[$activity->modname]++;
                                } else {
                                    $activity_stats['other']++;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Could not fetch {$activity->modname} details: " . $e->getMessage());
                        }
                    }
                    
                    // Add section even if empty
                    if (!empty($activity_details) || $section->section == 0) {
                        $course_data['sections'][] = [
                            'id' => $section->id,
                            'name' => (!empty($section->name)) ? $section->name : 'Topic ' . $section->section,
                            'summary' => $section->summary,
                            'activities' => $activity_details
                        ];
                    }
                }
                
                // Add course if it has sections
                if (!empty($course_data['sections'])) {
                    $courses_with_sections[] = $course_data;
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching course structure: " . $e->getMessage());
        }
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Enhanced Modern Styling */
.parent-main-content {
    margin-left: 280px;
    padding: 24px 28px;
    min-height: 100vh;
    background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
}

.parent-content-wrapper {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.container,
.container-fluid,
#region-main,
#region-main-box {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
}

.page-header-lessons {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    padding: 25px 30px;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
    position: relative;
    overflow: hidden;
}

.page-header-lessons::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.page-header-lessons h1 {
    color: white;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px 0;
    position: relative;
    z-index: 2;
}

.page-header-lessons p {
    color: rgba(255, 255, 255, 0.95);
    font-size: 14px;
    margin: 0;
    position: relative;
    z-index: 2;
}

/* Enhanced Statistics Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card-modern {
    background: white;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border: 2px solid #f0f4f8;
    border-top: 3px solid;
    position: relative;
    overflow: hidden;
}

.stat-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, transparent 0%, rgba(0, 0, 0, 0.02) 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.stat-card-modern:hover::before {
    opacity: 1;
}

.stat-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.12);
    border-color: var(--card-color);
}

.stat-icon-modern {
    font-size: 28px;
    margin-bottom: 8px;
    display: inline-block;
    position: relative;
    z-index: 1;
}

.stat-value-modern {
    font-size: 28px;
    font-weight: 700;
    color: #4b5563;
    margin: 5px 0;
    line-height: 1;
    position: relative;
    z-index: 1;
}

.stat-label-modern {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 800;
    position: relative;
    z-index: 1;
}

/* Enhanced Filter Section */
.filter-section {
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    padding: 20px;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(59, 130, 246, 0.1);
    border: 2px solid #e0f2fe;
    position: relative;
    overflow: hidden;
}

.filter-section::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
    pointer-events: none;
}

.filter-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 3px solid #e0f2fe;
    position: relative;
    z-index: 1;
}

.filter-badge {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 8px 16px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.filter-controls {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: center;
    position: relative;
    z-index: 1;
}

.filter-select {
    padding: 10px 16px;
    border: 2px solid #e0f2fe;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    min-width: 180px;
    cursor: pointer;
    background: white;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.filter-select:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.15);
    transform: translateY(-2px);
}

.filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 5px rgba(59, 130, 246, 0.2);
}

.filter-button {
    padding: 12px 22px;
    border: 2px solid #e0f2fe;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #3b82f6;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.filter-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.2);
    border-color: #3b82f6;
}

.quick-filters {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #e0f2fe;
    position: relative;
    z-index: 1;
}

.quick-filter-btn {
    padding: 10px 18px;
    border: 2px solid;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.quick-filter-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
}

/* Enhanced Activity Cards */
.activity-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border-left: 4px solid;
    position: relative;
    overflow: hidden;
    border: 2px solid #f8fafc;
}

.activity-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 120px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0, 0, 0, 0.03));
    pointer-events: none;
}

.activity-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
    background: linear-gradient(180deg, var(--card-color-light) 0%, var(--card-color) 100%);
    opacity: 0.5;
}

.activity-card:hover {
    transform: translateX(10px) translateY(-4px);
    box-shadow: 0 12px 32px rgba(59, 130, 246, 0.15);
    border-color: #e0f2fe;
    border-left-width: 8px;
}

.activity-icon-badge {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
    position: relative;
}

.activity-icon-badge::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.3), transparent);
    border-radius: 14px 14px 0 0;
}

.activity-title {
    font-weight: 700;
    color: #4b5563;
    font-size: 15px;
    margin-bottom: 8px;
    line-height: 1.3;
}

.activity-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 13px;
}

.activity-type-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.completion-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.completion-complete {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

.completion-incomplete {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

.completion-in-progress {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

/* Enhanced Course Section Card */
.course-section-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    border: 2px solid #f0f4f8;
}

.course-section-card:hover {
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.15);
    transform: translateY(-3px);
    border-color: #e0f2fe;
}

.course-header-modern {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    padding: 20px 25px;
    color: white;
    position: relative;
    overflow: hidden;
}

.course-header-modern::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.course-icon-badge {
    width: 42px;
    height: 42px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    backdrop-filter: blur(10px);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.12);
}

.course-title-modern {
    font-size: 17px;
    font-weight: 700;
    margin: 0;
    line-height: 1.3;
}

.course-subtitle {
    font-size: 14px;
    opacity: 0.95;
    margin: 10px 0 0 0;
    font-weight: 600;
}

.section-header-modern {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 14px;
    border-left: 4px solid #10b981;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    transition: all 0.3s ease;
}

.section-header-modern:hover {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    transform: translateX(4px);
}

.section-title-modern {
    font-size: 15px;
    font-weight: 700;
    color: #4b5563;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-count-badge {
    background: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    color: #3b82f6;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
}

/* Enhanced Empty State */
.empty-state-modern {
    text-align: center;
    padding: 50px 30px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border-radius: 16px;
    border: 2px dashed #e0f2fe;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
}

.empty-icon {
    font-size: 60px;
    color: #bfdbfe;
    margin-bottom: 20px;
    display: inline-block;
    animation: float 3s ease-in-out infinite;
    filter: drop-shadow(0 2px 8px rgba(59, 130, 246, 0.15));
}

@keyframes float {
    0%, 100% { 
        transform: translateY(0) rotate(0deg); 
    }
    50% { 
        transform: translateY(-15px) rotate(2deg); 
    }
}

.empty-title {
    font-size: 20px;
    font-weight: 700;
    color: #4b5563;
    margin: 0 0 10px 0;
}

.empty-text {
    font-size: 14px;
    color: #6b7280;
    margin: 0 0 20px 0;
    line-height: 1.5;
}

/* Responsive */
@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 20px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filter-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-select {
        width: 100%;
    }
}
</style>

<div class="parent-main-content">
    <!-- Page Header -->
    <div class="page-header-lessons">
        <h1><i class="fas fa-book-open"></i> Learning Activities</h1>
        <p>View all lessons, assignments, quizzes, and resources across courses</p>
    </div>

    <?php 
    // Show selected child banner
    if ($selected_child && $selected_child !== 'all' && $selected_child != 0):
        $selected_child_name = '';
        foreach ($children as $child) {
            if ($child['id'] == $selected_child) {
                $selected_child_name = $child['name'];
                break;
            }
        }
    ?>
    <div style="display: inline-flex; align-items: center; gap: 10px; background: #dbeafe; padding: 12px 20px; border-radius: 30px; margin-bottom: 25px; border: 2px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);">
        <i class="fas fa-user-check" style="color: #3b82f6; font-size: 18px;"></i>
        <span style="font-size: 15px; font-weight: 700; color: #3b82f6;">Viewing: <?php echo htmlspecialchars($selected_child_name); ?></span>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
           style="color: #3b82f6; text-decoration: none; font-size: 14px; font-weight: 700; margin-left: 5px; transition: all 0.2s;"
           title="Change Child"
           onmouseover="this.style.transform='scale(1.2)'"
           onmouseout="this.style.transform='scale(1)'">
            <i class="fas fa-sync-alt"></i>
        </a>
    </div>
    <?php endif; ?>

    <?php if (!empty($children)): ?>

    <!-- Statistics Cards -->
    <?php if ($activity_stats['total'] > 0): ?>
    <div class="stats-grid">
        <div class="stat-card-modern" style="--card-color: #3b82f6; --card-color-light: #93c5fd; border-top-color: #3b82f6;">
            <div class="stat-icon-modern" style="color: #3b82f6;"><i class="fas fa-book-reader"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['lesson']; ?></div>
            <div class="stat-label-modern">Lessons</div>
        </div>
        <div class="stat-card-modern" style="--card-color: #10b981; --card-color-light: #6ee7b7; border-top-color: #10b981;">
            <div class="stat-icon-modern" style="color: #10b981;"><i class="fas fa-file-alt"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['assign']; ?></div>
            <div class="stat-label-modern">Assignments</div>
        </div>
        <div class="stat-card-modern" style="--card-color: #f59e0b; --card-color-light: #fbbf24; border-top-color: #f59e0b;">
            <div class="stat-icon-modern" style="color: #f59e0b;"><i class="fas fa-clipboard-check"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['quiz']; ?></div>
            <div class="stat-label-modern">Quizzes</div>
        </div>
        <div class="stat-card-modern" style="--card-color: #8b5cf6; --card-color-light: #c4b5fd; border-top-color: #8b5cf6;">
            <div class="stat-icon-modern" style="color: #8b5cf6;"><i class="fas fa-file"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['resource']; ?></div>
            <div class="stat-label-modern">Resources</div>
        </div>
        <div class="stat-card-modern" style="--card-color: #ec4899; --card-color-light: #f9a8d4; border-top-color: #ec4899;">
            <div class="stat-icon-modern" style="color: #ec4899;"><i class="fas fa-comments"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['forum']; ?></div>
            <div class="stat-label-modern">Forums</div>
        </div>
        <div class="stat-card-modern" style="--card-color: #14b8a6; --card-color-light: #5eead4; border-top-color: #14b8a6;">
            <div class="stat-icon-modern" style="color: #14b8a6;"><i class="fas fa-th"></i></div>
            <div class="stat-value-modern"><?php echo $activity_stats['total']; ?></div>
            <div class="stat-label-modern">Total</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enhanced Filters -->
    <?php if (!empty($all_activities)): ?>
    <div class="filter-section">
        <div class="filter-header">
            <div class="filter-badge">
                <i class="fas fa-sliders-h"></i>
                FILTER ACTIVITIES
            </div>
            <div id="resultsCount" style="margin-left: auto; font-size: 14px; color: #3b82f6; font-weight: 700; background: #eff6ff; padding: 8px 16px; border-radius: 20px;"></div>
        </div>
        
        <div class="filter-controls">
            <!-- Course Filter -->
            <select id="courseFilter" onchange="filterLessons()" class="filter-select">
                <option value="all">ðŸ“š All Courses</option>
                <?php
                $courses_list = array_unique(array_column($all_activities, 'course'));
                sort($courses_list);
                foreach ($courses_list as $course_name):
                ?>
                <option value="<?php echo htmlspecialchars($course_name); ?>"><?php echo htmlspecialchars($course_name); ?></option>
                <?php endforeach; ?>
            </select>
            
            <!-- Type Filter -->
            <select id="typeFilter" onchange="filterLessons()" class="filter-select">
                <option value="all">ðŸŽ¯ All Types</option>
                <option value="lesson">ðŸ“– Lessons</option>
                <option value="assign">ðŸ“ Assignments</option>
                <option value="quiz">âœ… Quizzes</option>
                <option value="resource">ðŸ“„ Resources</option>
                <option value="page">ðŸ“ƒ Pages</option>
                <option value="forum">ðŸ’¬ Forums</option>
            </select>
            
            <!-- Completion Filter -->
            <select id="completionFilter" onchange="filterLessons()" class="filter-select">
                <option value="all">ðŸ“Š All Status</option>
                <option value="1">âœ“ Tracked Only</option>
                <option value="0">â—‹ Not Tracked</option>
            </select>
            
            <!-- Reset Button -->
            <button onclick="resetLessonFilters()" class="filter-button" style="background: #f3f4f6; color: #374151; border-color: #d1d5db;">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
        
        <!-- Quick Filter Buttons -->
        <div class="quick-filters">
            <span style="font-size: 12px; color: #6b7280; font-weight: 700; padding: 8px 0;">Quick Filters:</span>
            <button onclick="quickFilter('lesson')" class="quick-filter-btn" style="background: #eff6ff; border-color: #3b82f6; color: #3b82f6;">
                <i class="fas fa-book-reader"></i> Lessons
            </button>
            <button onclick="quickFilter('assign')" class="quick-filter-btn" style="background: #f0fdf4; border-color: #10b981; color: #10b981;">
                <i class="fas fa-file-alt"></i> Assignments
            </button>
            <button onclick="quickFilter('quiz')" class="quick-filter-btn" style="background: #fffbeb; border-color: #f59e0b; color: #f59e0b;">
                <i class="fas fa-clipboard-check"></i> Quizzes
            </button>
            <button onclick="quickFilter('tracked')" class="quick-filter-btn" style="background: #faf5ff; border-color: #8b5cf6; color: #8b5cf6;">
                <i class="fas fa-check-circle"></i> Tracked
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Activities List (Filterable) -->
    <?php if (!empty($all_activities)): ?>
    <div style="margin-bottom: 40px;">
        <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 20px 28px; border-radius: 16px; margin-bottom: 24px; border-left: 6px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);">
            <h2 style="font-size: 26px; font-weight: 800; color: #3b82f6; margin: 0; display: flex; align-items: center; gap: 14px;">
                <i class="fas fa-list-check" style="color: #3b82f6;"></i>
                All Learning Activities
                <span style="background: white; color: #3b82f6; padding: 6px 16px; border-radius: 20px; font-size: 14px; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);">
                    <?php echo count($all_activities); ?> Total
                </span>
            </h2>
        </div>
        
        <div id="lessonsContainer" style="display: grid; gap: 12px;">
            <?php foreach ($all_activities as $activity): 
                // Activity type configuration
                $config = [
                    'lesson' => ['icon' => 'book-reader', 'color' => '#3b82f6'],
                    'assign' => ['icon' => 'file-alt', 'color' => '#10b981'],
                    'quiz' => ['icon' => 'clipboard-check', 'color' => '#f59e0b'],
                    'forum' => ['icon' => 'comments', 'color' => '#ec4899'],
                    'resource' => ['icon' => 'file', 'color' => '#8b5cf6'],
                    'page' => ['icon' => 'file-lines', 'color' => '#14b8a6'],
                    'url' => ['icon' => 'link', 'color' => '#f97316'],
                ];
                
                $icon = $config[$activity['type']]['icon'] ?? 'puzzle-piece';
                $color = $config[$activity['type']]['color'] ?? '#60a5fa';
                
                // Completion status
                $completion_html = '';
                if ($activity['completion_status'] !== null) {
                    if ($activity['completion_status'] == 1) {
                        $completion_html = '<span class="completion-badge completion-complete"><i class="fas fa-check-circle"></i> Complete</span>';
                    } else if ($activity['completion_status'] == 2) {
                        $completion_html = '<span class="completion-badge completion-in-progress"><i class="fas fa-spinner"></i> In Progress</span>';
                    } else {
                        $completion_html = '<span class="completion-badge completion-incomplete"><i class="fas fa-times-circle"></i> Not Started</span>';
                    }
                } else if ($activity['completion']) {
                    $completion_html = '<span class="completion-badge" style="background: #e0f2fe; color: #0369a1;"><i class="fas fa-chart-line"></i> Tracked</span>';
                }
            ?>
            <div class="activity-card lesson-item" 
                 data-course="<?php echo htmlspecialchars($activity['course']); ?>"
                 data-type="<?php echo $activity['type']; ?>"
                 data-completion="<?php echo $activity['completion']; ?>"
                 style="border-left-color: <?php echo $color; ?>;">
                <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 16px; align-items: center;">
                    <!-- Icon -->
                    <div class="activity-icon-badge" style="background: <?php echo $color; ?>;">
                        <i class="fas fa-<?php echo $icon; ?>"></i>
                    </div>
                    
                    <!-- Content -->
                    <div style="min-width: 0;">
                        <div class="activity-title">
                            <?php echo htmlspecialchars($activity['name']); ?>
                        </div>
                        <div class="activity-meta">
                            <span class="activity-type-badge" style="background: <?php echo $color; ?>; color: white;">
                                <?php echo strtoupper($activity['type']); ?>
                            </span>
                            <span style="color: #3b82f6; font-weight: 600;">
                                <i class="fas fa-book" style="font-size: 10px;"></i> <?php echo htmlspecialchars($activity['course']); ?>
                            </span>
                            <?php if (isset($activity['visible']) && $activity['visible'] == 0): ?>
                            <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                <i class="fas fa-eye-slash"></i> Hidden
                            </span>
                            <?php endif; ?>
                            <?php echo $completion_html; ?>
                        </div>
                    </div>
                    
                    <!-- Due Date / Status -->
                    <div style="text-align: right; min-width: 120px;">
                        <?php if ($activity['deadline']): ?>
                            <?php 
                            $days_until = floor(($activity['deadline'] - time()) / 86400);
                            if ($days_until < 0) {
                                $due_style = 'background: #fee2e2; color: #991b1b;';
                                $due_text = 'Overdue';
                                $due_icon = 'exclamation-circle';
                            } elseif ($days_until == 0) {
                                $due_style = 'background: #fef3c7; color: #92400e;';
                                $due_text = 'Due Today';
                                $due_icon = 'clock';
                            } elseif ($days_until <= 3) {
                                $due_style = 'background: #fffbeb; color: #b45309;';
                                $due_text = $days_until . ' days';
                                $due_icon = 'exclamation-triangle';
                            } else {
                                $due_style = 'background: #d1fae5; color: #065f46;';
                                $due_text = $days_until . ' days';
                                $due_icon = 'calendar-check';
                            }
                            ?>
                            <div style="<?php echo $due_style; ?> padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; margin-bottom: 6px;">
                                <i class="fas fa-<?php echo $due_icon; ?>"></i> <?php echo $due_text; ?>
                            </div>
                            <div style="font-size: 11px; color: #6b7280; font-weight: 600;">
                                <?php echo date('M d, Y', $activity['deadline']); ?>
                            </div>
                        <?php else: ?>
                            <div style="background: #f3f4f6; color: #6b7280; padding: 8px 14px; border-radius: 8px; font-size: 11px; font-weight: 700;">
                                <i class="fas fa-calendar-plus"></i> Added
                            </div>
                            <div style="font-size: 11px; color: #9ca3af; margin-top: 6px; font-weight: 600;">
                                <?php echo date('M d, Y', $activity['added']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- No Results Message -->
        <div id="noResults" class="empty-state-modern" style="display: none;">
            <div class="empty-icon"><i class="fas fa-search"></i></div>
            <h3 class="empty-title">No Activities Found</h3>
            <p class="empty-text">Try adjusting your filters to see more results</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Course Sections View -->
    <?php if (!empty($courses_with_sections)): ?>
    <div>
        <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 20px 28px; border-radius: 16px; margin-bottom: 24px; border-left: 6px solid #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);">
            <h2 style="font-size: 26px; font-weight: 800; color: #065f46; margin: 0; display: flex; align-items: center; gap: 14px;">
                <i class="fas fa-layer-group" style="color: #10b981;"></i>
                Organized by Course & Section
                <span style="background: white; color: #10b981; padding: 6px 16px; border-radius: 20px; font-size: 14px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);">
                    <?php echo count($courses_with_sections); ?> Courses
                </span>
            </h2>
        </div>
        
        <?php foreach ($courses_with_sections as $course): ?>
        <div class="course-section-card">
            <!-- Course Header -->
            <div class="course-header-modern">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div class="course-icon-badge">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3 class="course-title-modern">
                            <?php echo htmlspecialchars($course['fullname']); ?>
                        </h3>
                        <p class="course-subtitle">
                            <?php echo htmlspecialchars($course['shortname']); ?> â€¢ 
                            <?php echo count($course['sections']); ?> section<?php echo count($course['sections']) != 1 ? 's' : ''; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Sections -->
            <div style="padding: 20px;">
                <?php foreach ($course['sections'] as $section_index => $section): ?>
                <div style="margin-bottom: <?php echo ($section_index < count($course['sections']) - 1) ? '30px' : '0'; ?>;">
                    <!-- Section Header -->
                    <div class="section-header-modern">
                        <div class="section-title-modern">
                            <i class="fas fa-folder-open" style="color: #10b981;"></i>
                            <?php echo htmlspecialchars($section['name']); ?>
                        </div>
                        <span class="section-count-badge">
                            <?php echo count($section['activities']); ?> item<?php echo count($section['activities']) != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    
                    <!-- Activities in Section -->
                    <div style="display: grid; gap: 12px; margin-left: 24px;">
                        <?php if (!empty($section['activities'])): ?>
                        <?php foreach ($section['activities'] as $activity): 
                            $config = [
                                'lesson' => ['icon' => 'book-reader', 'color' => '#3b82f6'],
                                'assign' => ['icon' => 'file-alt', 'color' => '#10b981'],
                                'quiz' => ['icon' => 'clipboard-check', 'color' => '#f59e0b'],
                                'forum' => ['icon' => 'comments', 'color' => '#ec4899'],
                                'resource' => ['icon' => 'file', 'color' => '#8b5cf6'],
                                'page' => ['icon' => 'file-lines', 'color' => '#14b8a6'],
                                'url' => ['icon' => 'link', 'color' => '#f97316'],
                            ];
                            
                            $icon = $config[$activity['type']]['icon'] ?? 'puzzle-piece';
                            $color = $config[$activity['type']]['color'] ?? '#60a5fa';
                        ?>
                        <div style="background: #fafbfc; padding: 16px; border-radius: 10px; border: 2px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease;">
                            <div style="display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0;">
                                <div style="width: 40px; height: 40px; background: <?php echo $color; ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);">
                                    <i class="fas fa-<?php echo $icon; ?>" style="font-size: 16px;"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <h4 style="margin: 0; color: #1f2937; font-size: 15px; font-weight: 700;">
                                        <?php echo htmlspecialchars($activity['name']); ?>
                                    </h4>
                                    <p style="margin: 6px 0 0 0; font-size: 12px; color: #6b7280; font-weight: 600;">
                                        <span style="background: white; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($activity['type']); ?>
                                        </span>
                                        <?php if (isset($activity['visible']) && $activity['visible'] == 0): ?>
                                        <span style="margin-left: 8px; color: #f59e0b;">
                                            <i class="fas fa-eye-slash"></i> Hidden
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($activity['completion']): ?>
                                        <span style="margin-left: 8px; color: #10b981;">
                                            <i class="fas fa-check-circle"></i> Tracked
                                        </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php if ($activity['deadline']): ?>
                            <div style="text-align: right; font-size: 12px; color: #f59e0b; font-weight: 700; min-width: 100px;">
                                <i class="fas fa-clock"></i> <?php echo date('M d, Y', $activity['deadline']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div style="padding: 24px; background: #f9fafb; border-radius: 12px; text-align: center; color: #9ca3af; font-size: 14px; font-weight: 600;">
                            <i class="fas fa-info-circle"></i> No activities in this section
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state-modern">
        <div class="empty-icon"><i class="fas fa-book-open"></i></div>
        <h3 class="empty-title">No Learning Content Found</h3>
        <p class="empty-text">
            <?php echo ($selected_child && $selected_child !== 'all' && $selected_child != 0) 
                ? 'The selected child has no courses with learning activities yet.' 
                : 'Please select a child from the main dashboard to view their learning activities.'; ?>
        </p>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state-modern">
        <div class="empty-icon"><i class="fas fa-users"></i></div>
        <h3 class="empty-title">No Children Found</h3>
        <p class="empty-text">You don't have any children linked to your parent account yet.</p>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" 
           style="display: inline-block; margin-top: 24px; padding: 14px 32px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; text-decoration: none; border-radius: 12px; font-weight: 700; font-size: 16px; box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);">
            <i class="fas fa-plus-circle"></i> Setup Now
        </a>
    </div>
    <?php endif; ?>
    
    <?php if ($debug_mode): ?>
    <!-- Debug Summary -->
    <div style="background: #1f2937; color: white; padding: 30px; border-radius: 16px; margin-top: 40px; font-family: monospace;">
        <h3 style="color: #60a5fa; margin: 0 0 20px 0; font-size: 20px; font-weight: 800;">
            <i class="fas fa-bug"></i> DEBUG SUMMARY
        </h3>
        
        <div style="display: grid; gap: 15px;">
            <div style="background: rgba(59, 130, 246, 0.2); padding: 15px; border-radius: 8px;">
                <strong style="color: #93c5fd;">Total Children:</strong> <?php echo count($children); ?><br>
                <strong style="color: #93c5fd;">Selected Child ID:</strong> <?php echo $selected_child ?: 'None'; ?><br>
                <strong style="color: #93c5fd;">Target Children:</strong> <?php echo implode(', ', $target_children); ?>
            </div>
            
            <div style="background: rgba(16, 185, 129, 0.2); padding: 15px; border-radius: 8px;">
                <strong style="color: #6ee7b7;">Total Courses:</strong> <?php echo count($courses_with_sections); ?><br>
                <strong style="color: #6ee7b7;">Total Activities:</strong> <?php echo $activity_stats['total']; ?><br>
                <strong style="color: #6ee7b7;">Total Sections:</strong> <?php 
                    $total_sections = 0;
                    foreach ($courses_with_sections as $c) {
                        $total_sections += count($c['sections']);
                    }
                    echo $total_sections;
                ?>
            </div>
            
            <div style="background: rgba(245, 158, 11, 0.2); padding: 15px; border-radius: 8px;">
                <strong style="color: #fbbf24;">Activity Breakdown:</strong><br>
                <?php foreach ($activity_stats as $type => $count): ?>
                    <?php if ($type !== 'total' && $count > 0): ?>
                        - <?php echo ucfirst($type); ?>: <?php echo $count; ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($courses_with_sections)): ?>
            <div style="background: rgba(139, 92, 246, 0.2); padding: 15px; border-radius: 8px;">
                <strong style="color: #c4b5fd;">Courses Details:</strong><br>
                <?php foreach ($courses_with_sections as $c): ?>
                    - <?php echo $c['fullname']; ?> (<?php echo count($c['sections']); ?> sections)<br>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: rgba(236, 72, 153, 0.2); border-radius: 8px; border-left: 4px solid #ec4899;">
            <strong style="color: #f9a8d4;"><i class="fas fa-info-circle"></i> Debug Mode Active</strong><br>
            <span style="color: #fce7f3; font-size: 13px;">
                Remove <code>?debug=1</code> from URL to hide this debug information.
            </span>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Filter JavaScript -->
<script>
function filterLessons() {
    const courseFilterEl = document.getElementById('courseFilter');
    const typeFilterEl = document.getElementById('typeFilter');
    const completionFilterEl = document.getElementById('completionFilter');
    const noResults = document.getElementById('noResults');
    const resultsCount = document.getElementById('resultsCount');
    
    if (!courseFilterEl || !typeFilterEl || !completionFilterEl) {
        return;
    }
    
    const courseFilter = courseFilterEl.value;
    const typeFilter = typeFilterEl.value;
    const completionFilter = completionFilterEl.value;
    
    const lessons = document.querySelectorAll('.lesson-item');
    
    if (lessons.length === 0) {
        return;
    }
    
    let visibleCount = 0;
    
    lessons.forEach(lesson => {
        const lessonCourse = lesson.getAttribute('data-course');
        const lessonType = lesson.getAttribute('data-type');
        const lessonCompletion = lesson.getAttribute('data-completion');
        
        let showLesson = true;
        
        if (courseFilter !== 'all' && lessonCourse !== courseFilter) {
            showLesson = false;
        }
        
        if (typeFilter !== 'all' && lessonType !== typeFilter) {
            showLesson = false;
        }
        
        if (completionFilter !== 'all' && lessonCompletion !== completionFilter) {
            showLesson = false;
        }
        
        if (showLesson) {
            lesson.style.display = 'block';
            visibleCount++;
        } else {
            lesson.style.display = 'none';
        }
    });
    
    const total = lessons.length;
    const percentage = total > 0 ? Math.round((visibleCount / total) * 100) : 0;
    
    if (resultsCount) {
        resultsCount.innerHTML = `Showing <strong style="color: #1d4ed8; font-size: 16px;">${visibleCount}</strong> of ${total} <span style="color: #60a5fa;">(${percentage}%)</span>`;
    }
    
    const lessonsContainer = document.getElementById('lessonsContainer');
    
    if (visibleCount === 0) {
        if (noResults) noResults.style.display = 'block';
        if (lessonsContainer) lessonsContainer.style.display = 'none';
    } else {
        if (noResults) noResults.style.display = 'none';
        if (lessonsContainer) lessonsContainer.style.display = 'grid';
    }
}

function resetLessonFilters() {
    const courseFilterEl = document.getElementById('courseFilter');
    const typeFilterEl = document.getElementById('typeFilter');
    const completionFilterEl = document.getElementById('completionFilter');
    
    if (courseFilterEl) courseFilterEl.value = 'all';
    if (typeFilterEl) typeFilterEl.value = 'all';
    if (completionFilterEl) completionFilterEl.value = 'all';
    
    filterLessons();
}

function quickFilter(type) {
    const courseFilterEl = document.getElementById('courseFilter');
    const typeFilterEl = document.getElementById('typeFilter');
    const completionFilterEl = document.getElementById('completionFilter');
    
    if (!typeFilterEl || !completionFilterEl) {
        return;
    }
    
    if (courseFilterEl) courseFilterEl.value = 'all';
    if (typeFilterEl) typeFilterEl.value = 'all';
    if (completionFilterEl) completionFilterEl.value = 'all';
    
    switch(type) {
        case 'lesson':
        case 'assign':
        case 'quiz':
            typeFilterEl.value = type;
            break;
        case 'tracked':
            completionFilterEl.value = '1';
            break;
    }
    
    filterLessons();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const lessonsCount = document.querySelectorAll('.lesson-item').length;
    
    if (lessonsCount > 0) {
        filterLessons();
    }
    
    // Add hover effects
    document.querySelectorAll('.quick-filter-btn').forEach(btn => {
        btn.addEventListener('mouseover', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
        });
        btn.addEventListener('mouseout', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });
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



