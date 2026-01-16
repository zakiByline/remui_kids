<?php
/**
 * Parent Dashboard - Progress Reports Page
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_once($CFG->libdir . '/completionlib.php');  // Required for completion_info class
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
$PAGE->set_url('/theme/remui_kids/parent/parent_progress.php');
$PAGE->set_title('Progress Reports - Parent Dashboard');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get progress data with detailed course information
$progress_data = [];
$course_details = [];
$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
    foreach ($target_children as $child_id) {
        // Find child info
        $child_info = null;
        foreach ($children as $c) {
            if ($c['id'] == $child_id) {
                $child_info = $c;
                break;
            }
        }
        
        if ($child_info) {
            // Get course completion
            $courses = enrol_get_users_courses($child_id, true);
            $completed = 0;
            $in_progress = 0;
            $not_started = 0;
            
            foreach ($courses as $course) {
                $completion = new completion_info($course);
                
                $course_info = [
                    'id' => $course->id,
                    'name' => $course->fullname,
                    'shortname' => $course->shortname,
                    'is_complete' => false,
                    'progress_percentage' => 0,
                    'completed_activities' => 0,
                    'total_activities' => 0,
                    'completion_enabled' => false
                ];
                
                if ($completion->is_enabled()) {
                    $course_info['completion_enabled'] = true;
                    
                    // Check if course is complete
                    if ($completion->is_course_complete($child_id)) {
                        $completed++;
                        $course_info['is_complete'] = true;
                        $course_info['progress_percentage'] = 100;
                    } else {
                        // Get activity-level completion
                        $sql_completion = "SELECT COUNT(*) as total,
                                                 COUNT(CASE WHEN cmc.completionstate > 0 THEN 1 END) as completed
                                          FROM {course_modules} cm
                                          LEFT JOIN {course_modules_completion} cmc 
                                              ON cmc.coursemoduleid = cm.id AND cmc.userid = :userid
                                          WHERE cm.course = :courseid
                                          AND cm.completion > 0
                                          AND cm.deletioninprogress = 0";
                        
                        try {
                            $completion_stats = $DB->get_record_sql($sql_completion, [
                                'userid' => $child_id,
                                'courseid' => $course->id
                            ]);
                            
                            if ($completion_stats && $completion_stats->total > 0) {
                                $course_info['total_activities'] = (int)$completion_stats->total;
                                $course_info['completed_activities'] = (int)$completion_stats->completed;
                                $completed_count = (int)$completion_stats->completed;
                                $total_count = (int)$completion_stats->total;
                                $course_info['progress_percentage'] = round(($completed_count / $total_count) * 100, 1);
                                
                                if ($completion_stats->completed > 0) {
                                    $in_progress++;
                                } else {
                                    $not_started++;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error getting completion: " . $e->getMessage());
                        }
                    }
                } else {
                    // Completion not enabled for this course
                    $not_started++;
                }
                
                $course_details[] = $course_info;
            }
            
            $total_courses = count($courses);
            $completion_rate = 0;
            if ($total_courses > 0) {
                $completion_rate = round(((int)$completed / (int)$total_courses) * 100, 1);
            }
            
            $progress_data[] = [
                'child_id' => $child_id,
                'child_name' => $child_info['name'],
                'total_courses' => $total_courses,
                'completed' => (int)$completed,
                'in_progress' => (int)$in_progress,
                'not_started' => (int)$not_started,
                'completion_rate' => $completion_rate,
                'courses' => $course_details
            ];
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

.parent-main-content {
    margin-left: 280px;
    padding: 0;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
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

.parent-section {
    margin-bottom: 24px;
}

.stat-card {
    background: #dbeafe;
    border: 1px solid #93c5fd;
    padding: 16px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(59,130,246,0.1);
}
.stat-number {
    font-size: 28px;
    font-weight: 700;
    color: #3b82f6;
    margin-bottom: 5px;
}
.stat-label {
    font-size: 11px;
    color: #1e40af;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.course-card {
    background: white;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 2px 8px rgba(59,130,246,0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s;
}
.course-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(59,130,246,0.12);
}
.progress-bar-container {
    background: #e5e7eb;
    border-radius: 12px;
    height: 12px;
    overflow: hidden;
    position: relative;
}
.progress-bar-fill {
    height: 100%;
    border-radius: 12px;
    transition: width 0.6s ease;
    position: relative;
}
.course-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 18px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f3f4f6;
}
.course-title {
    margin: 0 0 6px 0;
    color: #3b82f6;
    font-size: 18px;
    font-weight: 700;
}
.course-code {
    margin: 0;
    color: #6b7280;
    font-size: 13px;
}
.status-badge {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.badge-complete {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #93c5fd;
}
.badge-progress {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #93c5fd;
}
.badge-not-started {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
}
.activity-stats {
    background: #f0f9ff;
    padding: 18px;
    border-radius: 10px;
    margin-bottom: 18px;
    border: 1px solid #bfdbfe;
}
.overall-progress {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    padding: 20px 25px;
    border-radius: 12px;
    color: white;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(59,130,246,0.2);
}
.course-progress-wrapper {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 18px;
}

@media (max-width: 1024px) {
    .parent-main-content {
        margin-left: 0;
        width: 100%;
    }
}
</style>

<div class="parent-main-content">
    <div class="parent-content-wrapper">
        
        <nav class="parent-breadcrumb">
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" class="breadcrumb-link">Dashboard</a>
            <i class="fas fa-chevron-right breadcrumb-separator"></i>
            <span class="breadcrumb-current">Progress Reports</span>
        </nav>

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
        <div style="display: inline-flex; align-items: center; gap: 8px; background: #dbeafe; padding: 8px 14px; border-radius: 20px; margin-bottom: 15px; border: 1px solid #93c5fd;">
            <i class="fas fa-user-check" style="color: #3b82f6; font-size: 14px;"></i>
            <span style="font-size: 14px; font-weight: 600; color: #1e3a8a;"><?php echo htmlspecialchars($selected_child_name); ?></span>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
               style="color: #3b82f6; text-decoration: none; font-size: 13px; font-weight: 600; margin-left: 4px;"
               title="Change Child">
                <i class="fas fa-sync-alt"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($children)): ?>
        <?php if (!empty($progress_data)): ?>
        <?php foreach ($progress_data as $progress): ?>
        
        <!-- Overall Progress Header -->
        <div class="overall-progress">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0 0 5px 0; font-size: 28px; font-weight: 700;">
                        <?php echo htmlspecialchars($progress['child_name']); ?>
                    </h2>
                    <p style="margin: 0; opacity: 0.9; font-size: 15px;">Academic Progress Report</p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 48px; font-weight: 700;"><?php echo number_format($progress['completion_rate'], 1); ?>%</div>
                    <div style="opacity: 0.9; font-size: 13px;">Overall Completion</div>
                </div>
            </div>
            <div class="progress-bar-container" style="background: rgba(255,255,255,0.3); height: 16px;">
                <div class="progress-bar-fill" style="background: white; width: <?php echo $progress['completion_rate']; ?>%; display: flex; align-items: center; justify-content: flex-end; padding-right: 12px;">
                    <span style="font-size: 12px; font-weight: 700; color: #1e3a8a;"><?php echo number_format($progress['completion_rate'], 1); ?>%</span>
                </div>
            </div>
        </div>
        
        <div class="parent-section">
            <!-- Statistics Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $progress['total_courses']; ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $progress['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $progress['in_progress']; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $progress['not_started']; ?></div>
                    <div class="stat-label">Not Started</div>
                </div>
            </div>
            
            <!-- Course Details Section -->
            <div style="margin-top: 35px;">
                <h3 style="color: #1e3a8a; margin: 0 0 20px 0; font-size: 20px; font-weight: 700;">
                    <i class="fas fa-book-open"></i> Course Details
                </h3>
            
                <div style="display: grid; gap: 18px;">
                <?php foreach ($progress['courses'] as $course): 
                    $progress_color = $course['progress_percentage'] >= 75 ? '#3b82f6' : 
                                     ($course['progress_percentage'] >= 50 ? '#60a5fa' : 
                                     ($course['progress_percentage'] >= 25 ? '#93c5fd' : '#d1d5db'));
                ?>
                <div class="course-card">
                    <div class="course-header">
                        <div style="flex: 1;">
                            <h4 class="course-title">
                                <?php echo htmlspecialchars($course['name']); ?>
                            </h4>
                            <p class="course-code">
                                <i class="fas fa-code"></i> <?php echo htmlspecialchars($course['shortname']); ?>
                            </p>
                        </div>
                        <div>
                            <?php if ($course['is_complete']): ?>
                            <span class="status-badge badge-complete">
                                <i class="fas fa-check-circle"></i> Completed
                            </span>
                            <?php elseif ($course['completion_enabled']): ?>
                            <span class="status-badge badge-progress">
                                <i class="fas fa-spinner fa-spin"></i> In Progress
                            </span>
                            <?php else: ?>
                            <span class="status-badge badge-not-started">
                                <i class="fas fa-info-circle"></i> No Tracking
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($course['completion_enabled']): ?>
                    <!-- Activity Completion Details -->
                    <div class="activity-stats">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <span style="color: #1e40af; font-size: 14px; font-weight: 600;">
                                <i class="fas fa-tasks"></i> Activities Completed
                            </span>
                            <span style="color: #1e3a8a; font-weight: 700; font-size: 18px;">
                                <?php echo $course['completed_activities']; ?> / <?php echo $course['total_activities']; ?>
                            </span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="background: <?php echo $progress_color; ?>; width: <?php echo $course['progress_percentage']; ?>%;">
                            </div>
                        </div>
                        <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #6b7280; font-size: 12px;">Progress</span>
                            <span style="color: <?php echo $progress_color; ?>; font-weight: 700; font-size: 20px;">
                                <?php echo $course['progress_percentage']; ?>%
                            </span>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Completion Not Enabled -->
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <p style="margin: 0; color: #6b7280; font-size: 13px;">
                            <i class="fas fa-info-circle"></i> Activity completion tracking is not enabled for this course.
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 18px; display: flex; gap: 10px;">
                        <a href="<?php echo (new moodle_url('/theme/remui_kids/parent/parent_course_view.php', ['courseid' => $course['id'], 'child' => $selected_child]))->out(); ?>" 
                           style="display: inline-flex; align-items: center; gap: 8px; background: #3b82f6; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s;"
                           onmouseover="this.style.background='#2563eb';"
                           onmouseout="this.style.background='#3b82f6';">
                            <i class="fas fa-eye"></i> View Course
                        </a>
                        <?php if ($course['completion_enabled']): ?>
                        <span style="display: inline-flex; align-items: center; gap: 8px; background: #f0f9ff; color: #1e40af; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; border: 1px solid #bfdbfe;">
                            <i class="fas fa-chart-line"></i> <?php echo $course['total_activities']; ?> Activities
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php else: ?>
        <!-- No progress data -->
        <div style="text-align: center; padding: 80px 40px; background: #f0f9ff; border-radius: 16px; border: 2px dashed #bfdbfe;">
            <i class="fas fa-chart-line" style="font-size: 72px; color: #bfdbfe; margin-bottom: 20px;"></i>
            <h3 style="color: #1e3a8a; margin: 0 0 10px 0; font-size: 24px; font-weight: 700;">No Progress Data Available</h3>
            <p style="color: #6b7280; margin: 0 0 25px 0; font-size: 15px;">Select a child from the dashboard to view their learning progress.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
               style="display: inline-block; background: #3b82f6; color: white; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s;"
               onmouseover="this.style.background='#2563eb';"
               onmouseout="this.style.background='#3b82f6';">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- No children found -->
        <div style="text-align: center; padding: 80px 40px; background: #f0f9ff; border-radius: 16px; border: 2px dashed #bfdbfe;">
            <i class="fas fa-users-slash" style="font-size: 72px; color: #bfdbfe; margin-bottom: 20px;"></i>
            <h3 style="color: #1e3a8a; margin: 0 0 10px 0; font-size: 24px; font-weight: 700;">No Children Found</h3>
            <p style="color: #6b7280; margin: 0 0 25px 0; font-size: 15px;">You don't have any children linked to your parent account.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" 
               style="display: inline-block; background: #3b82f6; color: white; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s;"
               onmouseover="this.style.background='#2563eb';"
               onmouseout="this.style.background='#3b82f6';">
                <i class="fas fa-plus"></i> Setup Now
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

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





