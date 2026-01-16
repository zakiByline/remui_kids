<?php
/**
 * Parent Dashboard - Events/Calendar Page
 * Shows upcoming events, assignments, and important dates
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
$PAGE->set_url('/theme/remui_kids/parent/parent_events.php');
$PAGE->set_title('Events - Parent Dashboard');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);

// Get events for children
$events = [];
$events_by_type = [
    'course' => [],
    'user' => [],
    'site' => [],
    'category' => []
];
$events_by_timerange = [
    'today' => [],
    'tomorrow' => [],
    'this_week' => [],
    'next_week' => [],
    'this_month' => [],
    'later' => []
];
$event_stats = [
    'total' => 0,
    'today' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'course' => 0,
    'assignment_due' => 0,
    'quiz_due' => 0
];

$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
        list($insql1, $params1) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED, 'child1');
        list($insql2, $params2) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED, 'child2');
        
        // Get calendar events with more details
        $sql = "SELECT DISTINCT e.*, c.fullname as coursename, c.shortname as courseshort
                FROM {event} e
                LEFT JOIN {course} c ON c.id = e.courseid
                WHERE ((e.userid $insql1) OR (e.courseid IN (
                    SELECT DISTINCT c2.id 
                    FROM {course} c2
                    JOIN {enrol} en ON en.courseid = c2.id
                    JOIN {user_enrolments} ue ON ue.enrolid = en.id
                    WHERE ue.userid $insql2
                )))
                AND e.timestart >= :now
                AND e.visible = 1
                ORDER BY e.timestart ASC
                LIMIT 50";
        
        $events = $DB->get_records_sql($sql, array_merge(
            $params1,
            $params2,
            ['now' => time()]
        ));
        
        // Organize events by type and time range
        $today_start = strtotime('today');
        $today_end = strtotime('tomorrow') - 1;
        $tomorrow_start = strtotime('tomorrow');
        $tomorrow_end = strtotime('+2 days') - 1;
        $week_end = strtotime('+7 days');
        $next_week_end = strtotime('+14 days');
        $month_end = strtotime('+30 days');
        
        foreach ($events as $event) {
            $event_stats['total']++;
            $events_by_type[$event->eventtype][] = $event;
            
            // Categorize by time range
            if ($event->timestart >= $today_start && $event->timestart <= $today_end) {
                $events_by_timerange['today'][] = $event;
                $event_stats['today']++;
                $event_stats['this_week']++;
                $event_stats['this_month']++;
            } elseif ($event->timestart >= $tomorrow_start && $event->timestart <= $tomorrow_end) {
                $events_by_timerange['tomorrow'][] = $event;
                $event_stats['this_week']++;
                $event_stats['this_month']++;
            } elseif ($event->timestart <= $week_end) {
                $events_by_timerange['this_week'][] = $event;
                $event_stats['this_week']++;
                $event_stats['this_month']++;
            } elseif ($event->timestart <= $next_week_end) {
                $events_by_timerange['next_week'][] = $event;
                $event_stats['this_month']++;
            } elseif ($event->timestart <= $month_end) {
                $events_by_timerange['this_month'][] = $event;
                $event_stats['this_month']++;
            } else {
                $events_by_timerange['later'][] = $event;
            }
            
            // Count event types
            if ($event->eventtype === 'course') {
                $event_stats['course']++;
            }
            if (stripos($event->name, 'assignment') !== false || $event->modulename === 'assign') {
                $event_stats['assignment_due']++;
            }
            if (stripos($event->name, 'quiz') !== false || $event->modulename === 'quiz') {
                $event_stats['quiz_due']++;
            }
        }
}

// Get upcoming assignments
$assignments = [];
if (!empty($children) && is_array($children)) {
    $child_ids = array_column($children, 'id');
    if (!empty($child_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED, 'assign');
        
        $sql = "SELECT a.id, a.name, a.duedate, a.course,
                       c.fullname as coursename,
                       asub.id as submitted
                FROM {assign} a
                JOIN {course} c ON c.id = a.course
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = ue.userid
                WHERE ue.userid $insql
                AND a.duedate >= :now
                AND a.duedate < :nextmonth
                ORDER BY a.duedate ASC
                LIMIT 20";
        
        $assignments = $DB->get_records_sql($sql, array_merge($params, [
            'now' => time(),
            'nextmonth' => time() + (30 * 24 * 60 * 60)
        ]));
    }
}

// Additional calculations already done in event loop above

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
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    min-height: 100vh;
    background: linear-gradient(135deg, #f5f9ff 0%, #ffffff 100%);
    padding: 0;
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

.events-header {
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
    color: white;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(96, 165, 250, 0.3);
}

.events-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.15);
    padding: 15px;
    border-radius: 12px;
    text-align: center;
}

.stat-number {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 11px;
    opacity: 0.9;
}

.timeline-container {
    position: relative;
    padding-left: 40px;
    margin-top: 30px;
}

.timeline-line {
    position: absolute;
    left: 16px;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(to bottom, #60a5fa, #3b82f6);
}

.timeline-item {
    position: relative;
    margin-bottom: 25px;
    padding-left: 0;
}

.timeline-dot {
    position: absolute;
    left: -32px;
    top: 8px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: white;
    border: 3px solid #60a5fa;
    z-index: 2;
}

.event-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border-left: 4px solid;
    border: 1px solid #e5e7eb;
}

.event-card:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.event-card.course { 
    border-left-color: #3b82f6;
    background: linear-gradient(to right, #eff6ff 0%, white 8%);
}

.event-card.user { 
    border-left-color: #10b981;
    background: linear-gradient(to right, #ecfdf5 0%, white 8%);
}

.event-card.site { 
    border-left-color: #f59e0b;
    background: linear-gradient(to right, #fffbeb 0%, white 8%);
}

.event-card.category { 
    border-left-color: #8b5cf6;
    background: linear-gradient(to right, #f3e5f5 0%, white 8%);
}

.event-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.event-date-box {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
    padding: 12px;
    border-radius: 10px;
    text-align: center;
    min-width: 70px;
    margin-right: 15px;
}

.event-day {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
}

.event-month {
    font-size: 12px;
    opacity: 0.9;
    margin-top: 3px;
}

.event-content {
    flex: 1;
}

.event-title {
    font-weight: 700;
    color: #4b5563;
    font-size: 16px;
    margin-bottom: 8px;
    line-height: 1.3;
}

.event-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 10px;
    font-weight: 500;
}

.event-description {
    color: #374151;
    font-size: 13px;
    line-height: 1.5;
    font-weight: 400;
}

.event-type-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-course { background: #dbeafe; color: #1e40af; }
.badge-user { background: #d1fae5; color: #065f46; }
.badge-site { background: #fef3c7; color: #92400e; }
.badge-category { background: #f3e5f5; color: #6b21a8; }

.assignments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

.assignment-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-top: 4px solid #60a5fa;
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
}

.assignment-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
}

.assignment-card.submitted {
    border-top-color: #10b981;
    background: linear-gradient(to bottom, #ecfdf5 0%, white 15%);
}

.assignment-card.urgent {
    border-top-color: #ef4444;
    background: linear-gradient(to bottom, #fef2f2 0%, white 15%);
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.assignment-title {
    font-weight: 700;
    color: #4b5563;
    font-size: 16px;
    margin-bottom: 8px;
    line-height: 1.4;
}

.assignment-course {
    font-size: 13px;
    color: #6b7280;
}

.assignment-due {
    text-align: right;
    font-size: 12px;
    color: #6b7280;
}

.days-left {
    font-size: 18px;
    font-weight: 700;
    color: #60a5fa;
}

.assignment-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 10px;
}

.status-pending { background: #fef3c7; color: #92400e; }
.status-submitted { background: #d1fae5; color: #065f46; }
.status-urgent { background: #fee2e2; color: #991b1b; }

@media (max-width: 768px) {
    .assignments-grid {
        grid-template-columns: 1fr;
    }
    
    .events-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
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
            <span class="breadcrumb-current">Events & Calendar</span>
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

        <!-- Header -->
        <div class="events-header">
            <h1 style="margin: 0 0 10px 0; font-size: 28px; display: flex; align-items: center; gap: 15px;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    <i class="fas fa-calendar-day"></i>
                </div>
                Events & Important Dates
            </h1>
            <p style="margin: 0 0 20px 65px; opacity: 0.9; font-size: 14px;">
                Track upcoming events, assignments, and school activities
            </p>
            
            <div class="events-stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $event_stats['total']; ?></div>
                    <div class="stat-label">Total Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $event_stats['today']; ?></div>
                    <div class="stat-label">Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $event_stats['this_week']; ?></div>
                    <div class="stat-label">This Week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($assignments); ?></div>
                    <div class="stat-label">Assignments Due</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $event_stats['this_month']; ?></div>
                    <div class="stat-label">This Month</div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="<?php echo $CFG->wwwroot; ?>/calendar/view.php" 
                   style="display: inline-block; padding: 12px 24px; background: rgba(255, 255, 255, 0.2); border: 2px solid rgba(255, 255, 255, 0.3); color: white; text-decoration: none; border-radius: 10px; font-weight: 600;">
                    <i class="fas fa-external-link-alt"></i> Open Full Calendar
                </a>
            </div>
        </div>

        <!-- Upcoming Assignments -->
        <?php if (!empty($assignments)): ?>
        <div style="margin-bottom: 40px;">
            <h2 style="color: #4b5563; margin-bottom: 20px; font-size: 22px;">
                <i class="fas fa-tasks"></i> Upcoming Assignment Deadlines
            </h2>
            <div class="assignments-grid">
                <?php foreach ($assignments as $assignment): 
                    $days_left = ceil(($assignment->duedate - time()) / (24 * 60 * 60));
                    $is_urgent = $days_left <= 3;
                    $is_submitted = !empty($assignment->submitted);
                ?>
                <div class="assignment-card <?php echo $is_submitted ? 'submitted' : ($is_urgent ? 'urgent' : ''); ?>">
                    <div class="assignment-header">
                        <div style="flex: 1;">
                            <div class="assignment-title"><?php echo htmlspecialchars($assignment->name); ?></div>
                            <div class="assignment-course">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment->coursename); ?>
                            </div>
                        </div>
                        <div class="assignment-due">
                            <div class="days-left"><?php echo $days_left; ?></div>
                            <div>days left</div>
                        </div>
                    </div>
                    <div style="padding-top: 10px; border-top: 1px solid #f0f0f0;">
                        <div style="font-size: 13px; color: #6b7280; margin-bottom: 8px;">
                            <i class="fas fa-calendar"></i> Due: <?php echo userdate($assignment->duedate, '%d %B, %Y %H:%M'); ?>
                        </div>
                        <?php if ($is_submitted): ?>
                            <span class="assignment-status status-submitted">
                                <i class="fas fa-check-circle"></i> Submitted
                            </span>
                        <?php elseif ($is_urgent): ?>
                            <span class="assignment-status status-urgent">
                                <i class="fas fa-exclamation-circle"></i> Urgent
                            </span>
                        <?php else: ?>
                            <span class="assignment-status status-pending">
                                <i class="fas fa-clock"></i> Pending
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Events Timeline - Organized by Time Range -->
        <div>
            <h2 style="color: #4b5563; margin-bottom: 20px; font-size: 22px;">
                <i class="fas fa-calendar-alt"></i> Upcoming Events
            </h2>
            
            <?php if (!empty($events)): ?>
            
            <!-- Events by Time Range -->
            <?php 
            $timerange_labels = [
                'today' => ['label' => 'Today', 'icon' => 'fa-calendar-day', 'color' => '#ef4444'],
                'tomorrow' => ['label' => 'Tomorrow', 'icon' => 'fa-calendar-plus', 'color' => '#f59e0b'],
                'this_week' => ['label' => 'This Week', 'icon' => 'fa-calendar-week', 'color' => '#3b82f6'],
                'next_week' => ['label' => 'Next Week', 'icon' => 'fa-calendar', 'color' => '#8b5cf6'],
                'this_month' => ['label' => 'Later This Month', 'icon' => 'fa-calendar-alt', 'color' => '#10b981'],
                'later' => ['label' => 'Later', 'icon' => 'fa-calendar-check', 'color' => '#6b7280']
            ];
            
            foreach ($timerange_labels as $range_key => $range_info):
                if (!empty($events_by_timerange[$range_key])):
            ?>
            <div style="margin-bottom: 30px;">
                <!-- Time Range Header -->
                <div style="background: linear-gradient(135deg, <?php echo $range_info['color']; ?>20, <?php echo $range_info['color']; ?>10); padding: 12px 20px; border-radius: 10px; margin-bottom: 15px; border-left: 4px solid <?php echo $range_info['color']; ?>;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas <?php echo $range_info['icon']; ?>" style="color: <?php echo $range_info['color']; ?>; font-size: 18px;"></i>
                        <strong style="color: #4b5563; font-size: 16px;"><?php echo $range_info['label']; ?></strong>
                        <span style="background: <?php echo $range_info['color']; ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                            <?php echo count($events_by_timerange[$range_key]); ?> events
                        </span>
                    </div>
                </div>
                
                <!-- Events in this time range -->
                <div style="display: grid; gap: 12px; margin-left: 20px;">
                    <?php foreach ($events_by_timerange[$range_key] as $event): 
                        $time_until = $event->timestart - time();
                        $hours_until = floor($time_until / 3600);
                        $days_until = floor($hours_until / 24);
                    ?>
                    <div class="event-card <?php echo $event->eventtype; ?>" style="border-left: 4px solid <?php echo $range_info['color']; ?>;">
                        <div style="display: flex; gap: 15px; align-items: start;">
                            <!-- Date Box -->
                            <div class="event-date-box">
                                <div class="event-day"><?php echo date('d', $event->timestart); ?></div>
                                <div class="event-month"><?php echo date('M', $event->timestart); ?></div>
                                <div style="font-size: 10px; margin-top: 4px; opacity: 0.8;">
                                    <?php echo date('D', $event->timestart); ?>
                                </div>
                            </div>
                            
                            <!-- Event Content -->
                            <div class="event-content">
                                <div class="event-title"><?php echo htmlspecialchars($event->name); ?></div>
                                
                                <!-- Event Meta Information -->
                                <div class="event-meta">
                                    <span style="font-weight: 700; color: #4b5563;">
                                        <i class="fas fa-clock"></i> <?php echo date('g:i A', $event->timestart); ?>
                                    </span>
                                    
                                    <?php if ($event->timeduration > 0): ?>
                                        <span>
                                            <i class="fas fa-hourglass-half"></i> <?php echo floor($event->timeduration / 60); ?> minutes
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($event->coursename): ?>
                                        <span style="font-weight: 600; color: #3b82f6;">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($event->coursename); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="event-type-badge badge-<?php echo $event->eventtype; ?>">
                                        <?php echo ucfirst($event->eventtype); ?>
                                    </span>
                                    
                                    <?php if ($event->location): ?>
                                        <span style="color: #8b5cf6;">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event->location); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Countdown -->
                                <div style="margin: 10px 0; padding: 8px 12px; background: #f0f9ff; border-radius: 6px; display: inline-block;">
                                    <i class="fas fa-stopwatch" style="color: #3b82f6;"></i>
                                    <strong style="color: #1e40af; font-size: 13px;">
                                        <?php 
                                        if ($days_until > 0) {
                                            echo $days_until . ' days, ' . ($hours_until % 24) . ' hours';
                                        } elseif ($hours_until > 0) {
                                            echo $hours_until . ' hours, ' . floor(($time_until % 3600) / 60) . ' minutes';
                                        } else {
                                            echo floor($time_until / 60) . ' minutes away';
                                        }
                                        ?>
                                    </strong>
                                </div>
                                
                                <?php if ($event->description): ?>
                                    <div class="event-description" style="margin-top: 10px;">
                                        <?php echo format_text($event->description, FORMAT_HTML); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php 
                endif;
            endforeach; 
            ?>
            
            <?php else: ?>
            <div style="background: white; border-radius: 16px; padding: 60px; text-align: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);">
                <div style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <h3 style="color: #4b5563; margin: 0 0 10px 0;">No Upcoming Events</h3>
                <p style="color: #6b7280;">There are no events scheduled at the moment.</p>
            </div>
            <?php endif; ?>
        </div>

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



