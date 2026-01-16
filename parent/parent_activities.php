<?php
/**
 * Parent Dashboard - Learning Activities Page
 * View all learning activities organized by type
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
$PAGE->set_url('/theme/remui_kids/parent/parent_activities.php');
$PAGE->set_title('Learning Activities - Parent Dashboard');
$PAGE->set_heading('Learning Activities');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get all activities organized by type
$activities_by_type = [];
$activity_stats = [
    'total' => 0,
    'assignments' => 0,
    'quizzes' => 0,
    'resources' => 0,
    'forums' => 0,
    'other' => 0
];

$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);
    
    // Get recent activity logs for better detail
    $params['last_30_days'] = time() - (30 * 24 * 60 * 60);
    
    $sql = "SELECT l.id, l.userid, l.timecreated, l.action, l.target, l.courseid,
                   c.fullname as coursename,
                   u.firstname, u.lastname,
                   l.component, l.eventname
            FROM {logstore_standard_log} l
            JOIN {course} c ON c.id = l.courseid
            JOIN {user} u ON u.id = l.userid
            WHERE l.userid $insql
            AND l.courseid > 1
            AND l.timecreated >= :last_30_days
            AND l.action IN ('viewed', 'submitted', 'created', 'updated', 'started', 'answered')
            ORDER BY l.timecreated DESC
            LIMIT 50";
    
    try {
        $activity_records = $DB->get_records_sql($sql, $params);
        
        foreach ($activity_records as $activity) {
            $activity_stats['total']++;
            
            // Determine activity type from component
            $type = 'Other';
            $icon = 'fa-circle';
            $color = '#6b7280';
            
            if (strpos($activity->component, 'assign') !== false || $activity->target === 'assign') {
                $type = 'Assignment';
                $icon = 'fa-file-alt';
                $color = '#3b82f6';
                $activity_stats['assignments']++;
            } elseif (strpos($activity->component, 'quiz') !== false || $activity->target === 'quiz') {
                $type = 'Quiz';
                $icon = 'fa-question-circle';
                $color = '#8b5cf6';
                $activity_stats['quizzes']++;
            } elseif (strpos($activity->component, 'resource') !== false || $activity->target === 'resource' || 
                      strpos($activity->component, 'book') !== false || strpos($activity->component, 'page') !== false) {
                $type = 'Resource';
                $icon = 'fa-book';
                $color = '#10b981';
                $activity_stats['resources']++;
            } elseif (strpos($activity->component, 'forum') !== false || $activity->target === 'forum') {
                $type = 'Forum';
                $icon = 'fa-comments';
                $color = '#f59e0b';
                $activity_stats['forums']++;
            } else {
                $activity_stats['other']++;
            }
            
            // Format action
            $action_text = ucfirst($activity->action);
            
            if (!isset($activities_by_type[$type])) {
                $activities_by_type[$type] = [];
            }
            
            $activities_by_type[$type][] = [
                'id' => $activity->id,
                'student' => fullname($activity),
                'course' => $activity->coursename,
                'action' => $action_text,
                'time' => $activity->timecreated,
                'component' => $activity->component,
                'icon' => $icon,
                'color' => $color
            ];
        }
        
        // Sort each type by most recent
        foreach ($activities_by_type as $type => $items) {
            usort($activities_by_type[$type], function($a, $b) {
                return $b['time'] - $a['time'];
            });
        }
        
    } catch (Exception $e) {
        debugging('Error: ' . $e->getMessage());
    }
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/parent_dashboard.css">';
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
?>

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

.activity-card {
    background: white;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    transition: all 0.2s ease;
}
.activity-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}
.stat-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    text-align: center;
    transition: transform 0.2s ease;
}
.stat-card:hover {
    transform: translateY(-3px);
}

/* Collapse button styling */
#collapseBtn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4) !important;
}

#collapseBtn:active {
    transform: translateY(0);
}

/* Smooth animations for collapsible container */
.activities-collapsible {
    transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), 
                opacity 0.5s ease, 
                margin-top 0.5s ease !important;
}

/* Badge animation */
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.section-title span {
    animation: pulse 2s infinite;
}

@media (max-width: 1024px) {
    .parent-main-content {
        margin-left: 0;
        width: 100%;
        padding: 24px 20px 40px;
    }
}
</style>

<div class="parent-main-content">
    <div class="parent-content-wrapper">
        
        <nav class="parent-breadcrumb">
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" class="breadcrumb-link">Dashboard</a>
            <i class="fas fa-chevron-right breadcrumb-separator"></i>
            <span class="breadcrumb-current">Learning Activities</span>
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
            <span style="font-size: 14px; font-weight: 600; color: #3b82f6;"><?php echo htmlspecialchars($selected_child_name); ?></span>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
               style="color: #3b82f6; text-decoration: none; font-size: 13px; font-weight: 600; margin-left: 4px;"
               title="Change Child">
                <i class="fas fa-sync-alt"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($children)): ?>

        <!-- Activity Statistics Cards - Compact -->
        <?php if ($activity_stats['total'] > 0): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 25px;">
            <div class="stat-card" style="border-top: 3px solid #3b82f6; padding: 15px;">
                <i class="fas fa-file-alt" style="font-size: 22px; color: #3b82f6; margin-bottom: 8px;"></i>
                <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['assignments']; ?></div>
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Assignments</div>
            </div>
            <div class="stat-card" style="border-top: 3px solid #8b5cf6; padding: 15px;">
                <i class="fas fa-question-circle" style="font-size: 22px; color: #8b5cf6; margin-bottom: 8px;"></i>
                <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['quizzes']; ?></div>
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Quizzes</div>
            </div>
            <div class="stat-card" style="border-top: 3px solid #10b981; padding: 15px;">
                <i class="fas fa-book" style="font-size: 22px; color: #10b981; margin-bottom: 8px;"></i>
                <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['resources']; ?></div>
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Resources</div>
            </div>
            <div class="stat-card" style="border-top: 3px solid #f59e0b; padding: 15px;">
                <i class="fas fa-comments" style="font-size: 22px; color: #f59e0b; margin-bottom: 8px;"></i>
                <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['forums']; ?></div>
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Forums</div>
            </div>
            <div class="stat-card" style="border-top: 3px solid #6b7280; padding: 15px;">
                <i class="fas fa-th" style="font-size: 22px; color: #6b7280; margin-bottom: 8px;"></i>
                <div style="font-size: 24px; font-weight: 700; color: #4b5563;"><?php echo $activity_stats['other']; ?></div>
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Other</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <?php if (!empty($activities_by_type)): ?>
        <div style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="font-weight: 700; color: #4b5563; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-filter" style="color: #3b82f6;"></i>
                    Filter By:
                </div>
                
                <!-- Course Filter -->
                <select id="courseFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                    <option value="all">All Courses</option>
                    <?php
                    $courses = [];
                    foreach ($activities_by_type as $type => $items) {
                        foreach ($items as $activity) {
                            $courses[$activity['course']] = true;
                        }
                    }
                    foreach (array_keys($courses) as $course):
                    ?>
                    <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Type Filter -->
                <select id="typeFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                    <option value="all">All Types</option>
                    <option value="Assignment">Assignments</option>
                    <option value="Quiz">Quizzes</option>
                    <option value="Resource">Resources</option>
                    <option value="Forum">Forums</option>
                    <option value="Other">Other</option>
                </select>
                
                <!-- Action Filter -->
                <select id="actionFilter" onchange="filterActivities()" style="padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 150px;">
                    <option value="all">All Actions</option>
                    <option value="Viewed">Viewed</option>
                    <option value="Submitted">Submitted</option>
                    <option value="Started">Started</option>
                    <option value="Created">Created</option>
                    <option value="Updated">Updated</option>
                    <option value="Answered">Answered</option>
                </select>
                
                <!-- Reset Button -->
                <button onclick="resetFilters()" style="padding: 8px 16px; background: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; color: #374151;">
                    <i class="fas fa-redo"></i> Reset
                </button>
                
                <!-- Results Count -->
                <div id="resultsCount" style="margin-left: auto; font-size: 14px; color: #6b7280; font-weight: 600;"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Activities List (Filterable) -->
        <?php if (!empty($activities_by_type)): ?>
        <div class="parent-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="section-title" style="margin: 0;">
                <i class="fas fa-list-check"></i>
                Recent Activities
                    <span style="background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 13px; margin-left: 10px; font-weight: 700;">
                        <?php echo $activity_stats['total']; ?> total
                    </span>
            </h2>
                <button onclick="toggleActivitiesCollapse()" id="collapseBtn" 
                        style="padding: 10px 20px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-chevron-up" id="collapseIcon"></i>
                    <span id="collapseText">Collapse</span>
                </button>
            </div>
            
            <div id="activitiesContainer" class="activities-collapsible" style="display: grid; gap: 10px; overflow: hidden; transition: all 0.5s ease;">
                <?php foreach ($activities_by_type as $type => $items): ?>
                    <?php foreach ($items as $activity): ?>
                    <div class="activity-card activity-item" 
                         data-course="<?php echo htmlspecialchars($activity['course']); ?>"
                         data-type="<?php echo $type; ?>"
                         data-action="<?php echo $activity['action']; ?>"
                         style="border-left: 3px solid <?php echo $activity['color']; ?>; padding: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">
                            <!-- Left: Activity Info -->
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                                    <i class="fas <?php echo $activity['icon']; ?>" style="color: <?php echo $activity['color']; ?>; font-size: 16px;"></i>
                                    <span style="background: <?php echo $activity['color']; ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                        <?php echo $type; ?>
                                    </span>
                                    <span style="background: #f3f4f6; color: #374151; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                        <?php echo $activity['action']; ?>
                                    </span>
                                </div>
                                <div style="font-weight: 600; color: #4b5563; font-size: 15px; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($activity['course']); ?>
                                </div>
                                <div style="font-size: 12px; color: #6b7280;">
                                    <i class="fas fa-user" style="font-size: 10px;"></i>
                                    <?php echo htmlspecialchars($activity['student']); ?>
                                </div>
                            </div>
                            
                            <!-- Right: Time Info -->
                            <div style="text-align: right; min-width: 100px;">
                                <div style="font-size: 14px; font-weight: 700; color: #4b5563;">
                                    <?php echo date('g:i A', $activity['time']); ?>
                                </div>
                                <div style="font-size: 11px; color: #6b7280;">
                                    <?php echo date('M d', $activity['time']); ?>
                                </div>
                                <?php 
                                $days_ago = floor((time() - $activity['time']) / 86400);
                                if ($days_ago == 0) {
                                    $time_text = 'Today';
                                    $time_color = '#10b981';
                                } elseif ($days_ago == 1) {
                                    $time_text = 'Yesterday';
                                    $time_color = '#3b82f6';
                                } else {
                                    $time_text = $days_ago . 'd ago';
                                    $time_color = '#9ca3af';
                                }
                                ?>
                                <div style="font-size: 10px; color: <?php echo $time_color; ?>; font-weight: 600; margin-top: 4px;">
                                    <?php echo $time_text; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            
            <!-- No Results Message -->
            <div id="noResults" style="display: none; text-align: center; padding: 60px; color: #6b7280;">
                <i class="fas fa-search" style="font-size: 48px; color: #d1d5db; margin-bottom: 15px;"></i>
                <h3 style="margin-bottom: 10px;">No Activities Found</h3>
                <p>Try adjusting your filters to see more results</p>
            </div>
        </div>
        <?php else: ?>
        <div class="parent-section" style="text-align: center; padding: 60px;">
            <i class="fas fa-tasks" style="font-size: 64px; color: #d1d5db; margin-bottom: 15px;"></i>
            <h3 style="color: #6b7280; margin-bottom: 10px;">No Recent Activities</h3>
            <p style="color: #9ca3af;">Activities from the last 30 days will appear here</p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="parent-section" style="text-align: center; padding: 60px;">
            <i class="fas fa-users" style="font-size: 64px; color: #d1d5db;"></i>
            <h2>No Children Found</h2>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white; text-decoration: none; border-radius: 8px;">Setup Now</a>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Filter JavaScript -->
<script>
// Collapse/Expand functionality
function toggleActivitiesCollapse() {
    const container = document.getElementById('activitiesContainer');
    const btn = document.getElementById('collapseBtn');
    const icon = document.getElementById('collapseIcon');
    const text = document.getElementById('collapseText');
    const noResults = document.getElementById('noResults');
    
    if (container.style.maxHeight === '0px' || container.style.maxHeight === '') {
        // Expand
        container.style.maxHeight = container.scrollHeight + 'px';
        container.style.opacity = '1';
        container.style.marginTop = '0px';
        icon.className = 'fas fa-chevron-up';
        text.textContent = 'Collapse';
        btn.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
        
        // Show noResults if it was visible
        if (noResults && noResults.dataset.wasVisible === 'true') {
            noResults.style.display = 'block';
        }
    } else {
        // Collapse
        container.style.maxHeight = '0px';
        container.style.opacity = '0';
        container.style.marginTop = '-20px';
        icon.className = 'fas fa-chevron-down';
        text.textContent = 'Expand';
        btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        
        // Hide noResults and remember its state
        if (noResults && noResults.style.display === 'block') {
            noResults.dataset.wasVisible = 'true';
            noResults.style.display = 'none';
        }
    }
}

// Initialize container height
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('activitiesContainer');
    if (container) {
        container.style.maxHeight = container.scrollHeight + 'px';
    }
});

function filterActivities() {
    const courseFilter = document.getElementById('courseFilter').value;
    const typeFilter = document.getElementById('typeFilter').value;
    const actionFilter = document.getElementById('actionFilter').value;
    
    const activities = document.querySelectorAll('.activity-item');
    const noResults = document.getElementById('noResults');
    const resultsCount = document.getElementById('resultsCount');
    
    let visibleCount = 0;
    
    activities.forEach(activity => {
        const activityCourse = activity.getAttribute('data-course');
        const activityType = activity.getAttribute('data-type');
        const activityAction = activity.getAttribute('data-action');
        
        let showActivity = true;
        
        // Course filter
        if (courseFilter !== 'all' && activityCourse !== courseFilter) {
            showActivity = false;
        }
        
        // Type filter
        if (typeFilter !== 'all' && activityType !== typeFilter) {
            showActivity = false;
        }
        
        // Action filter
        if (actionFilter !== 'all' && activityAction !== actionFilter) {
            showActivity = false;
        }
        
        if (showActivity) {
            activity.style.display = 'block';
            visibleCount++;
        } else {
            activity.style.display = 'none';
        }
    });
    
    // Update results count
    resultsCount.textContent = `Showing ${visibleCount} of ${activities.length} activities`;
    
    // Show/hide no results message
    if (visibleCount === 0) {
        noResults.style.display = 'block';
    } else {
        noResults.style.display = 'none';
    }
    
    // Update container height for collapse/expand
    const container = document.getElementById('activitiesContainer');
    if (container && container.style.maxHeight !== '0px') {
        container.style.maxHeight = container.scrollHeight + 'px';
    }
}

function resetFilters() {
    document.getElementById('courseFilter').value = 'all';
    document.getElementById('typeFilter').value = 'all';
    document.getElementById('actionFilter').value = 'all';
    filterActivities();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    filterActivities();
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




