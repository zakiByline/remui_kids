<?php
/**
 * Student Competencies Analytics & Progress Tracking
 * Shows detailed competency progress, linked activities, and next steps
 * 
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/competency/classes/competency_framework.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/competencies.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Competencies & Progress', false);

// Get selected course from URL (0 = all courses)
$selectedcourseid = optional_param('courseid', 0, PARAM_INT);

// Get user's cohort information for dashboard type
$usercohorts = $DB->get_records_sql(
    "SELECT c.name, c.id 
     FROM {cohort} c 
     JOIN {cohort_members} cm ON c.id = cm.cohortid 
     WHERE cm.userid = ?",
    [$USER->id]
);

$usercohortname = '';
$dashboardtype = 'default';

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    
    // Determine dashboard type
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $dashboardtype = 'highschool';
    } elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    }
}

// Add body classes for high school and middle school users
if ($dashboardtype === 'highschool') {
    $PAGE->add_body_class('has-student-sidebar');
    $PAGE->add_body_class('has-enhanced-sidebar');
} elseif ($dashboardtype === 'middle') {
    $PAGE->add_body_class('has-student-sidebar');
}

// Get all enrolled courses
$courses = enrol_get_all_users_courses($USER->id, true);
$courseids = array_keys($courses);

// Validate selected course
if ($selectedcourseid > 0 && !isset($courses[$selectedcourseid])) {
    $selectedcourseid = 0; // Reset if invalid
}

// Build course selector
$coursesforselect = [];
$coursesforselect[] = [
    'id' => 0,
    'label' => 'All Courses (Combined View)',
    'selected' => ($selectedcourseid == 0)
];

foreach ($courses as $course) {
    if ($course->id == SITEID) continue;
    $coursesforselect[] = [
        'id' => (int)$course->id,
        'label' => format_string($course->fullname),
        'selected' => ($selectedcourseid == $course->id)
    ];
}

// ==================== DATA COLLECTION ====================

$competency_progress_data = [];
$next_steps = [];
$overall_stats = [
    'total_competencies' => 0,
    'proficient_count' => 0,
    'in_progress_count' => 0,
    'not_started_count' => 0,
    'total_activities' => 0,
    'completed_activities' => 0,
    'pending_activities' => 0
];

// Determine which courses to analyze
$courses_to_analyze = [];
if ($selectedcourseid > 0) {
    $courses_to_analyze[$selectedcourseid] = $courses[$selectedcourseid];
} else {
    $courses_to_analyze = $courses;
}

// Helper: Get activities linked to a competency
$get_competency_activities = function($competencyid, $courseid) use ($DB) {
    $activities = [];
    
    // Check competency_modulecomp table
    if ($DB->get_manager()->table_exists('competency_modulecomp')) {
        $records = $DB->get_records_sql(
            "SELECT cm.id as cmid, cm.course, cm.instance, m.name as modname,
                    COALESCE(q.name, a.name, f.name, s.name, l.name, p.name, r.name, u.name) AS activityname,
                    cmc.ruleoutcome
             FROM {competency_modulecomp} cmc
             JOIN {course_modules} cm ON cm.id = cmc.cmid
             JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
             LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
             LEFT JOIN {forum} f ON f.id = cm.instance AND m.name = 'forum'
             LEFT JOIN {scorm} s ON s.id = cm.instance AND m.name = 'scorm'
             LEFT JOIN {lesson} l ON l.id = cm.instance AND m.name = 'lesson'
             LEFT JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
             LEFT JOIN {resource} r ON r.id = cm.instance AND m.name = 'resource'
             LEFT JOIN {url} u ON u.id = cm.instance AND m.name = 'url'
             WHERE cmc.competencyid = ? AND cm.course = ? AND cm.visible = 1
             ORDER BY cm.section, activityname",
            [$competencyid, $courseid]
        );
        
        foreach ($records as $record) {
            $activities[] = [
                'cmid' => $record->cmid,
                'name' => $record->activityname ?: 'Activity',
                'type' => $record->modname,
                'url' => (new moodle_url('/mod/' . $record->modname . '/view.php', ['id' => $record->cmid]))->out(false),
                'ruleoutcome' => $record->ruleoutcome
            ];
        }
    }
    
    return $activities;
};

// Helper: Check if activity is completed by user
$is_activity_completed = function($cmid, $userid, $courseid) use ($DB) {
    try {
        $course = get_course($courseid);
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        
        if (!isset($modinfo->cms[$cmid])) {
            return false;
        }
        
        $cm = $modinfo->cms[$cmid];
        
        if (!$completion->is_enabled($cm)) {
            return false;
        }
        
        $completion_data = $completion->get_data($cm, false, $userid);
        
        if ($completion_data && ($completion_data->completionstate == COMPLETION_COMPLETE || 
            $completion_data->completionstate == COMPLETION_COMPLETE_PASS)) {
            return true;
        }
    } catch (Exception $e) {
        error_log("Error checking completion for cmid {$cmid}: " . $e->getMessage());
    }
    
    return false;
};

// Process each course
foreach ($courses_to_analyze as $course) {
    if ($course->id == SITEID) continue;
    
    try {
        // Get competencies for this course
        $course_competencies = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.description, c.idnumber, c.parentid,
                    f.id AS frameworkid, f.shortname AS frameworkname
             FROM {competency_coursecomp} cc
             JOIN {competency} c ON c.id = cc.competencyid
             JOIN {competency_framework} f ON f.id = c.competencyframeworkid
             WHERE cc.courseid = :courseid
             AND (c.parentid IS NULL OR c.parentid = 0)
             ORDER BY f.shortname, c.shortname",
            ['courseid' => $course->id]
        );
        
        foreach ($course_competencies as $comp) {
            $competencyid = (int)$comp->id;
            $overall_stats['total_competencies']++;
            
            // Get user's competency status
            $usercomp = $DB->get_record('competency_usercompcourse', [
                'courseid' => $course->id,
                'userid' => $USER->id,
                'competencyid' => $competencyid
            ]);
            
            $is_proficient = false;
            $proficiency_percent = 0;
            $status_text = 'Not Started';
            $status_class = 'not-started';
            
            if ($usercomp) {
                if (!empty($usercomp->proficiency)) {
                    $is_proficient = true;
                    $proficiency_percent = 100;
                    $status_text = 'Proficient ✓';
                    $status_class = 'proficient';
                    $overall_stats['proficient_count']++;
                } elseif (!empty($usercomp->grade)) {
                    $grade = (float)$usercomp->grade;
                    if ($grade > 1 && $grade <= 100) {
                        $proficiency_percent = $grade;
                    } elseif ($grade > 0 && $grade <= 1) {
                        $proficiency_percent = $grade * 100;
                    }
                    $status_text = 'In Progress (' . round($proficiency_percent) . '%)';
                    $status_class = 'in-progress';
                    $overall_stats['in_progress_count']++;
                } else {
                    $status_text = 'In Progress';
                    $status_class = 'in-progress';
                    $overall_stats['in_progress_count']++;
                }
            } else {
                $overall_stats['not_started_count']++;
            }
            
            // Get linked activities
            $activities = $get_competency_activities($competencyid, $course->id);
            $overall_stats['total_activities'] += count($activities);
            
            // Check completion status for each activity
            $completed_activities = [];
            $pending_activities = [];
            
            foreach ($activities as $activity) {
                $is_completed = $is_activity_completed($activity['cmid'], $USER->id, $course->id);
                
                $activity['completed'] = $is_completed;
                
                if ($is_completed) {
                    $completed_activities[] = $activity;
                    $overall_stats['completed_activities']++;
                } else {
                    $pending_activities[] = $activity;
                    $overall_stats['pending_activities']++;
                }
            }
            
            // Calculate activity-based completion percentage
            $total_activities_count = count($activities);
            $completed_activities_count = count($completed_activities);
            $activity_completion_percent = $total_activities_count > 0 ? 
                round(($completed_activities_count / $total_activities_count) * 100, 1) : 0;
            
            // Create unique key for competency
            $comp_key = $competencyid . '_' . $course->id;
            
            if (!isset($competency_progress_data[$comp_key])) {
                $competency_progress_data[$comp_key] = [
                    'id' => $competencyid,
                    'name' => format_string($comp->shortname),
                    'description' => strip_tags(substr($comp->description ?: '', 0, 200)),
                    'framework_name' => format_string($comp->frameworkname),
                    'framework_id' => $comp->frameworkid,
                    'course_id' => $course->id,
                    'course_name' => format_string($course->fullname),
                    'status_text' => $status_text,
                    'status_class' => $status_class,
                    'is_proficient' => $is_proficient,
                    'proficiency_percent' => $proficiency_percent,
                    'activity_completion_percent' => $activity_completion_percent,
                    'total_activities' => $total_activities_count,
                    'completed_activities_count' => $completed_activities_count,
                    'pending_activities_count' => count($pending_activities),
                    'completed_activities' => $completed_activities,
                    'pending_activities' => $pending_activities,
                    'all_activities' => $activities
                ];
            }
            
            // Add to next steps if not proficient and has pending activities
            if (!$is_proficient && !empty($pending_activities)) {
                // Get first 3 pending activities as next steps
                $next_activities = array_slice($pending_activities, 0, 3);
                
                foreach ($next_activities as $next_activity) {
                    $next_steps[] = [
                        'competency_name' => format_string($comp->shortname),
                        'course_name' => format_string($course->fullname),
                        'activity_name' => $next_activity['name'],
                        'activity_type' => ucfirst($next_activity['type']),
                        'activity_url' => $next_activity['url'],
                        'priority' => $is_proficient ? 'low' : ($proficiency_percent > 0 ? 'medium' : 'high')
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error processing course {$course->id}: " . $e->getMessage());
        continue;
    }
}

// Sort next steps by priority
usort($next_steps, function($a, $b) {
    $priority_order = ['high' => 1, 'medium' => 2, 'low' => 3];
    return $priority_order[$a['priority']] <=> $priority_order[$b['priority']];
});

// Limit to top 10 next steps
$next_steps = array_slice($next_steps, 0, 10);

// Calculate overall completion percentage
$overall_completion_percent = $overall_stats['total_competencies'] > 0 ?
    round(($overall_stats['proficient_count'] / $overall_stats['total_competencies']) * 100, 1) : 0;

$overall_activity_completion = $overall_stats['total_activities'] > 0 ?
    round(($overall_stats['completed_activities'] / $overall_stats['total_activities']) * 100, 1) : 0;

// Group competencies by framework
$competencies_by_framework = [];
foreach ($competency_progress_data as $comp_data) {
    $fid = $comp_data['framework_id'];
    if (!isset($competencies_by_framework[$fid])) {
        $competencies_by_framework[$fid] = [
            'id' => $fid,
            'name' => $comp_data['framework_name'],
            'competencies' => [],
            'stats' => [
                'total' => 0,
                'proficient' => 0,
                'in_progress' => 0,
                'not_started' => 0
            ]
        ];
    }
    $competencies_by_framework[$fid]['competencies'][] = $comp_data;
    $competencies_by_framework[$fid]['stats']['total']++;
    
    if ($comp_data['is_proficient']) {
        $competencies_by_framework[$fid]['stats']['proficient']++;
    } elseif ($comp_data['status_class'] === 'in-progress') {
        $competencies_by_framework[$fid]['stats']['in_progress']++;
    } else {
        $competencies_by_framework[$fid]['stats']['not_started']++;
    }
}

// Prepare chart data
$chart_data = [
    'radar' => [
        'labels' => [],
        'values' => []
    ],
    'donut' => [
        'labels' => ['Proficient', 'In Progress', 'Not Started'],
        'values' => [
            $overall_stats['proficient_count'],
            $overall_stats['in_progress_count'],
            $overall_stats['not_started_count']
        ],
        'colors' => ['#10b981', '#f59e0b', '#94a3b8']
    ],
    'framework_bars' => [
        'labels' => [],
        'proficient' => [],
        'in_progress' => [],
        'not_started' => []
    ],
    'competency_overview' => [] // Detailed competency-level activity tracking per framework
];

// Radar chart - show top 8 competencies by activity completion
$competencies_for_radar = array_slice($competency_progress_data, 0, 8);
foreach ($competencies_for_radar as $comp) {
    $chart_data['radar']['labels'][] = $comp['name'];
    $chart_data['radar']['values'][] = $comp['activity_completion_percent'];
}

// Framework comparison chart
foreach ($competencies_by_framework as $framework) {
    $chart_data['framework_bars']['labels'][] = $framework['name'];
    $chart_data['framework_bars']['proficient'][] = $framework['stats']['proficient'];
    $chart_data['framework_bars']['in_progress'][] = $framework['stats']['in_progress'];
    $chart_data['framework_bars']['not_started'][] = $framework['stats']['not_started'];
}

// Competency Overview Chart Data (Like teacher student_report.php)
// Group by framework with detailed activity counts
foreach ($competencies_by_framework as $framework) {
    $framework_data = [
        'framework_id' => $framework['id'],
        'framework_name' => $framework['name'],
        'competencies' => []
    ];
    
    foreach ($framework['competencies'] as $comp) {
        $framework_data['competencies'][] = [
            'name' => $comp['name'],
            'completed_activities' => $comp['completed_activities_count'],
            'remaining_activities' => $comp['pending_activities_count'],
            'total_activities' => $comp['total_activities']
        ];
    }
    
    $chart_data['competency_overview'][] = $framework_data;
}

// Build framework selector options for chart
$framework_chart_options = [];
foreach ($competencies_by_framework as $framework) {
    $framework_chart_options[] = [
        'id' => $framework['id'],
        'name' => $framework['name']
    ];
}

// Build sidebar context
$sidebar_context = [
    'student_name' => fullname($USER),
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'communityurl' => (new moodle_url('/theme/remui_kids/community.php'))->out(),
    'studypartnerurl' => (new moodle_url('/local/studypartner/index.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
    'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
    'messagesurl' => (new moodle_url('/message/index.php'))->out(),
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'is_competencies_page' => true,
    'wwwroot' => $CFG->wwwroot
];

// ==================== HTML OUTPUT ====================

echo $OUTPUT->header();

// Render sidebar
if ($dashboardtype === 'elementary') {
    echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_sidebar', $sidebar_context);
    echo '<div class="with-sidebar">';
} elseif ($dashboardtype === 'highschool') {
    // Build high school sidebar context
    $highschool_sidebar_context = remui_kids_build_highschool_sidebar_context('competencies', $USER);
    echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $highschool_sidebar_context);
    echo '<div class="with-sidebar">';
} elseif ($dashboardtype === 'middle') {
    // Build G4G7 sidebar context for middle school (Grade 4-7)
    $g4g7_sidebar_context = [
        'dashboardurl' => (new moodle_url('/my/'))->out(),
        'mycoursesurl' => (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out(),
        'achievementsurl' => (new moodle_url('/theme/remui_kids/achievements.php'))->out(),
        'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
        'gradesurl' => (new moodle_url('/theme/remui_kids/grades.php'))->out(),
        'badgesurl' => (new moodle_url('/theme/remui_kids/badges.php'))->out(),
        'scheduleurl' => (new moodle_url('/theme/remui_kids/schedule.php'))->out(),
        'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
        'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
        'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
        'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
        'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
        'config' => ['wwwroot' => $CFG->wwwroot],
        'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
        'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
        'currentpage' => ['competencies' => true],
    ];
    echo $OUTPUT->render_from_template('theme_remui_kids/g4g7_sidebar', $g4g7_sidebar_context);
    echo '<div class="with-sidebar">';
} else {
    echo '<div class="no-sidebar">';
}

?>

<style>
/* ==================== FULL PAGE LAYOUT ==================== */
body, html {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    height: 100% !important;
    overflow-x: hidden;
}

#page, #page-wrapper, #page-content, .container, .container-fluid {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
}
#page-header {
    margin-bottom: 0px;
}
.competencies-main-container {
    margin-left: 280px;
    width: calc(100vw - 280px);
    min-height: 100vh;
    padding: 40px 60px 80px 60px;
    background: #f7f8fa;
    box-sizing: border-box;
}

.no-sidebar .competencies-main-container {
    margin-left: 0 !important;
    width: 100vw !important;
}

/* ==================== HEADER (Like Teacher Reports) ==================== */
.competencies-page-header {
    background: transparent;
    margin-bottom: 32px;
    padding: 0;
}

.header-top-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    gap: 40px;
}

.header-title-section h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    letter-spacing: -0.02em;
}

.header-title-section p {
    margin: 0;
    font-size: 0.95rem;
    color: #64748b;
    line-height: 1.5;
}

.header-course-selector {
    min-width: 320px;
}

.header-course-selector label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.header-course-selector label i {
    color: #667eea;
}

.header-course-selector select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #1e293b;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
}

.header-course-selector select:hover {
    border-color: #94a3b8;
}

.header-course-selector select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.header-divider {
    height: 3px;
    background: linear-gradient(90deg, #a855f7 0%, #06b6d4 100%);
    border-radius: 2px;
    margin-bottom: 32px;
}

/* ==================== SUMMARY CARDS (Like Teacher Reports) ==================== */
.summary-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 40px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 24px 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.summary-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.summary-card h3 {
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin: 0;
    font-weight: 600;
}

.summary-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}

.summary-card-icon.proficient {
    background: #10b981;
}

.summary-card-icon.in-progress {
    background: #f59e0b;
}

.summary-card-icon.not-started {
    background: #94a3b8;
}

.summary-card-icon.activities {
    background: #6366f1;
}

.summary-card-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 8px;
    line-height: 1;
}

.summary-card-subtitle {
    font-size: 0.875rem;
    color: #475569;
    line-height: 1.4;
}

.summary-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #a855f7 0%, #06b6d4 100%);
    border-radius: 0 0 12px 12px;
}

/* ==================== NEXT STEPS SECTION (30% Column) ==================== */
.next-steps-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    position: relative;
    display: flex;
    flex-direction: column;
    max-height: 580px;
}

.next-steps-section::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #a855f7 0%, #06b6d4 100%);
    border-radius: 0 0 12px 12px;
}

.next-steps-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f1f5f9;
    flex-shrink: 0;
}

.next-steps-icon {
    width: 40px;
    height: 40px;
    background: #fef3c7;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #f59e0b;
    flex-shrink: 0;
}

.next-steps-info h2 {
    margin: 0 0 4px 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e293b;
}

.next-steps-info p {
    margin: 0;
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.4;
}

.next-steps-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    overflow-y: auto;
    flex: 1;
    padding-right: 4px;
}

.next-steps-list::-webkit-scrollbar {
    width: 6px;
}

.next-steps-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.next-steps-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.next-steps-list::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.next-step-item {
    background: #f8fafc;
    border-radius: 8px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
}

.next-step-item:hover {
    background: white;
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.next-step-top {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.next-step-number {
    width: 28px;
    height: 28px;
    background: #6366f1;
    color: white;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.next-step-number.high-priority {
    background: #ef4444;
}

.next-step-number.medium-priority {
    background: #f59e0b;
}

.next-step-content {
    flex: 1;
    min-width: 0;
}

.next-step-activity {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.875rem;
    margin-bottom: 6px;
    line-height: 1.3;
}

.next-step-details {
    font-size: 0.75rem;
    color: #64748b;
    line-height: 1.4;
}

.next-step-action {
    width: 100%;
}

.next-step-action a {
    padding: 8px 12px;
    background: #1e3a8a;
    color: white;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.8rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
}

.next-step-action a:hover {
    background: #1e40af;
}

/* ==================== CHARTS SECTION ==================== */
.charts-section {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    margin-bottom: 40px;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    position: relative;
}

.chart-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #a855f7 0%, #06b6d4 100%);
    border-radius: 0 0 12px 12px;
}

.chart-header {
    margin-bottom: 20px;
}

.chart-header h3 {
    margin: 0 0 6px 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-header h3 i {
    color: #6366f1;
    font-size: 0.9rem;
}

.chart-header p {
    margin: 0;
    font-size: 0.8rem;
    color: #64748b;
}

.chart-container {
    position: relative;
    height: 280px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-container canvas {
    max-width: 100%;
    max-height: 100%;
}

/* ==================== OVERVIEW & NEXT STEPS LAYOUT (70/30 Split) ==================== */
.overview-nextsteps-container {
    display: grid;
    grid-template-columns: 70% 30%;
    gap: 24px;
    margin-bottom: 40px;
}

.competency-overview-section {
    background: white;
    border-radius: 12px;
    padding: 28px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    position: relative;
}

.competency-overview-section::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #a855f7 0%, #06b6d4 100%);
    border-radius: 0 0 12px 12px;
}

.chart-header-with-controls {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    gap: 20px;
}

.chart-title-area h3 {
    margin: 0 0 6px 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title-area h3 i {
    color: #a855f7;
    font-size: 1rem;
}

.chart-title-area p {
    margin: 0;
    font-size: 0.85rem;
    color: #64748b;
}

.chart-controls {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 12px;
}

.mastery-badges {
    display: flex;
    gap: 10px;
}

.mastery-badge {
    padding: 6px 12px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.count-badge {
    padding: 6px 12px;
    background: #f1f5f9;
    color: #475569;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.framework-selector-inline {
    display: flex;
    align-items: center;
    gap: 8px;
}

.framework-selector-inline label {
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 600;
}

.framework-selector-inline select {
    padding: 6px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.85rem;
    color: #1e293b;
    background: white;
    cursor: pointer;
}

.framework-selector-inline select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.chart-container-large {
    position: relative;
    height: 480px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-container-large canvas {
    max-width: 100%;
    max-height: 100%;
}

/* ==================== RESPONSIVE ==================== */
@media (max-width: 1400px) {
    .summary-cards-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .charts-section {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 1200px) {
    .overview-nextsteps-container {
        grid-template-columns: 65% 35%;
    }
    
    .chart-header-with-controls {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .chart-controls {
        width: 100%;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
    }
}

@media (max-width: 1024px) {
    .competencies-main-container {
        margin-left: 250px;
        width: calc(100vw - 250px);
        padding: 30px 40px;
    }
    
    .header-top-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-course-selector {
        width: 100%;
        min-width: auto;
    }
    
    .charts-section {
        grid-template-columns: 1fr;
    }
    
    .overview-nextsteps-container {
        grid-template-columns: 1fr;
    }
    
    .next-steps-section {
        max-height: none;
    }
}

@media (max-width: 768px) {
    .competencies-main-container {
        margin-left: 0;
        width: 100vw;
        padding: 24px 20px;
    }
    
    .summary-cards-grid {
        grid-template-columns: 1fr;
    }
    
    .header-title-section h1 {
        font-size: 1.5rem;
    }
    
    .header-title-section p {
        font-size: 0.875rem;
    }
    
    .chart-container {
        height: 240px;
    }
    
    .chart-container-large {
        height: 350px;
    }
}
</style>

<div class="competencies-main-container">
    <!-- Page Header (Like Teacher Reports) -->
    <div class="competencies-page-header">
        <div class="header-top-row">
            <div class="header-title-section">
                <h1>My Competencies & Progress</h1>
                <p>Track your skills, see what you've mastered, and discover what to learn next.</p>
            </div>
            <div class="header-course-selector">
                <label for="course-selector">
                    <i class="fa fa-graduation-cap"></i>
                    Select course
                </label>
                <select id="course-selector" onchange="if(this.value || this.value === '0') window.location.href='?courseid='+this.value;">
                    <?php foreach ($coursesforselect as $course_opt): ?>
                    <option value="<?php echo $course_opt['id']; ?>" <?php echo $course_opt['selected'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($course_opt['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="header-divider"></div>
    </div>

    <!-- Summary Cards (Like Teacher Reports) -->
    <div class="summary-cards-grid">
        <div class="summary-card">
            <div class="summary-card-header">
                <h3>Proficient</h3>
                <div class="summary-card-icon proficient">
                    <i class="fa fa-check-circle"></i>
                </div>
            </div>
            <div class="summary-card-value"><?php echo $overall_stats['proficient_count']; ?></div>
            <div class="summary-card-subtitle">Competencies mastered</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-card-header">
                <h3>In Progress</h3>
                <div class="summary-card-icon in-progress">
                    <i class="fa fa-clock-o"></i>
                </div>
            </div>
            <div class="summary-card-value"><?php echo $overall_stats['in_progress_count']; ?></div>
            <div class="summary-card-subtitle">Currently working on</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-card-header">
                <h3>Not Started</h3>
                <div class="summary-card-icon not-started">
                    <i class="fa fa-circle-o"></i>
                </div>
            </div>
            <div class="summary-card-value"><?php echo $overall_stats['not_started_count']; ?></div>
            <div class="summary-card-subtitle">Yet to begin</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-card-header">
                <h3>Activities</h3>
                <div class="summary-card-icon activities">
                    <i class="fa fa-tasks"></i>
                </div>
            </div>
            <div class="summary-card-value"><?php echo $overall_stats['completed_activities']; ?>/<?php echo $overall_stats['total_activities']; ?></div>
            <div class="summary-card-subtitle">Activities completed</div>
        </div>
    </div>

    <!-- Visual Progress Charts (Top Row - 3 Charts) -->
    <?php if (!empty($competency_progress_data)): ?>
    <div class="charts-section">
        <!-- Competency Status Breakdown (Donut Chart) -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fa fa-chart-pie"></i> Status Overview</h3>
                <p>Breakdown of all competencies</p>
            </div>
            <div class="chart-container">
                <canvas id="status-donut-chart"></canvas>
            </div>
        </div>

        <!-- Competency Progress (Radar Chart) -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fa fa-chart-area"></i> Activity Completion</h3>
                <p>Your progress across competencies</p>
            </div>
            <div class="chart-container">
                <canvas id="competency-radar-chart"></canvas>
            </div>
        </div>

        <!-- Framework Comparison (Stacked Bar Chart) -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fa fa-chart-bar"></i> Framework Progress</h3>
                <p>Competencies by framework</p>
            </div>
            <div class="chart-container">
                <canvas id="framework-bar-chart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Competency Overview & Next Steps (70/30 Split Layout) -->
    <?php if (!empty($competency_progress_data)): ?>
    <div class="overview-nextsteps-container">
        <!-- LEFT: Competency Overview Chart (70%) -->
        <div class="competency-overview-section">
            <div class="chart-header-with-controls">
                <div class="chart-title-area">
                    <h3><i class="fa fa-bullseye"></i> Competency Overview</h3>
                    <p>Activities completed vs remaining for each competency</p>
                </div>
                <?php if (!empty($framework_chart_options)): ?>
                <div class="chart-controls">
                    <div class="mastery-badges">
                        <span class="mastery-badge"><?php echo $overall_completion_percent; ?>% mastery</span>
                        <span class="count-badge"><?php echo $overall_stats['proficient_count']; ?>/<?php echo $overall_stats['total_competencies']; ?> proficient</span>
                    </div>
                    <div class="framework-selector-inline">
                        <label for="framework-chart-selector">Framework:</label>
                        <select id="framework-chart-selector" onchange="switchFrameworkChart(this.value)">
                            <?php foreach ($framework_chart_options as $index => $fw_opt): ?>
                            <option value="<?php echo $fw_opt['id']; ?>" <?php echo $index === 0 ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fw_opt['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="chart-container-large">
                <canvas id="competency-overview-chart"></canvas>
            </div>
        </div>

        <!-- RIGHT: Next Steps / What to Do Next (30%) -->
        <?php if (!empty($next_steps)): ?>
        <div class="next-steps-section">
        <div class="next-steps-header">
            <div class="next-steps-icon">
                <i class="fa fa-lightbulb-o"></i>
            </div>
            <div class="next-steps-info">
                <h2>What to Do Next?</h2>
                <p>Complete these to improve</p>
            </div>
        </div>
        <div class="next-steps-list">
            <?php foreach ($next_steps as $index => $step): ?>
            <div class="next-step-item">
                <div class="next-step-top">
                    <div class="next-step-number <?php echo $step['priority']; ?>-priority">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="next-step-content">
                        <div class="next-step-activity"><?php echo htmlspecialchars($step['activity_name']); ?></div>
                        <div class="next-step-details">
                            <i class="fa fa-book"></i> <?php echo htmlspecialchars($step['course_name']); ?><br>
                            <i class="fa fa-bullseye"></i> <?php echo htmlspecialchars($step['competency_name']); ?>
                        </div>
                    </div>
                </div>
                <div class="next-step-action">
                    <a href="<?php echo $step['activity_url']; ?>" target="_blank">
                        <i class="fa fa-play-circle"></i>
                        Start Now
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        </div><!-- End next-steps-section -->
        <?php endif; ?>
    </div><!-- End overview-nextsteps-container -->
    <?php endif; ?>

</div><!-- End competencies-main-container -->

</div><!-- End with-sidebar / no-sidebar wrapper -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Chart Data from PHP
const chartData = <?php echo json_encode($chart_data); ?>;

// Track current selected framework for competency overview chart
let currentFrameworkId = null;

// Initialize all charts on page load
document.addEventListener('DOMContentLoaded', function() {
    initStatusDonutChart();
    initCompetencyRadarChart();
    initFrameworkBarChart();
    initCompetencyOverviewChart();
});

// Switch framework for competency overview chart
function switchFrameworkChart(frameworkId) {
    currentFrameworkId = parseInt(frameworkId);
    initCompetencyOverviewChart();
}

// 1. Status Donut Chart - Shows breakdown of competency statuses
function initStatusDonutChart() {
    const canvas = document.getElementById('status-donut-chart');
    if (!canvas || !chartData.donut) return;
    
    new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: chartData.donut.labels,
            datasets: [{
                data: chartData.donut.values,
                backgroundColor: chartData.donut.colors,
                borderColor: '#ffffff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return label + ': ' + value + ' (' + percentage + '%)';
                        }
                    }
                }
            },
            cutout: '65%'
        }
    });
}

// 2. Competency Radar Chart - Shows activity completion across competencies
function initCompetencyRadarChart() {
    const canvas = document.getElementById('competency-radar-chart');
    if (!canvas || !chartData.radar || chartData.radar.labels.length === 0) return;
    
    new Chart(canvas.getContext('2d'), {
        type: 'radar',
        data: {
            labels: chartData.radar.labels,
            datasets: [{
                label: 'Activity Completion %',
                data: chartData.radar.values,
                fill: true,
                backgroundColor: 'rgba(99, 102, 241, 0.2)',
                borderColor: '#6366f1',
                pointBackgroundColor: '#6366f1',
                pointBorderColor: '#ffffff',
                pointHoverBackgroundColor: '#ffffff',
                pointHoverBorderColor: '#6366f1',
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    angleLines: {
                        color: 'rgba(0, 0, 0, 0.08)'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.08)'
                    },
                    pointLabels: {
                        font: {
                            size: 10,
                            weight: '500'
                        },
                        color: '#475569'
                    },
                    ticks: {
                        beginAtZero: true,
                        max: 100,
                        stepSize: 25,
                        display: false,
                        backdropColor: 'transparent'
                    },
                    suggestedMin: 0,
                    suggestedMax: 100
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.r + '%';
                        }
                    }
                }
            }
        }
    });
}

// 3. Framework Bar Chart - Stacked bar showing competencies per framework
function initFrameworkBarChart() {
    const canvas = document.getElementById('framework-bar-chart');
    if (!canvas || !chartData.framework_bars || chartData.framework_bars.labels.length === 0) return;
    
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartData.framework_bars.labels,
            datasets: [
                {
                    label: 'Proficient',
                    data: chartData.framework_bars.proficient,
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'In Progress',
                    data: chartData.framework_bars.in_progress,
                    backgroundColor: '#f59e0b',
                    borderRadius: 4
                },
                {
                    label: 'Not Started',
                    data: chartData.framework_bars.not_started,
                    backgroundColor: '#94a3b8',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: {
                            size: 10,
                            weight: '600'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + ' competencies';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10
                        },
                        color: '#64748b'
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        font: {
                            size: 10
                        },
                        color: '#64748b'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                }
            }
        }
    });
}

// 4. Competency Overview Chart - Horizontal stacked bar (Like teacher student_report.php)
let competencyOverviewChartInstance = null;

function initCompetencyOverviewChart() {
    const canvas = document.getElementById('competency-overview-chart');
    if (!canvas || !chartData.competency_overview || chartData.competency_overview.length === 0) return;
    
    // Destroy existing chart instance
    if (competencyOverviewChartInstance) {
        competencyOverviewChartInstance.destroy();
    }
    
    // Get selected framework or use first one
    let frameworkId = currentFrameworkId;
    if (!frameworkId && chartData.competency_overview.length > 0) {
        frameworkId = chartData.competency_overview[0].framework_id;
        currentFrameworkId = frameworkId;
    }
    
    // Find framework data
    const frameworkData = chartData.competency_overview.find(fw => fw.framework_id == frameworkId);
    if (!frameworkData || !frameworkData.competencies || frameworkData.competencies.length === 0) {
        return;
    }
    
    // Prepare data for horizontal stacked bar chart
    const labels = frameworkData.competencies.map(c => c.name);
    const completedData = frameworkData.competencies.map(c => c.completed_activities);
    const remainingData = frameworkData.competencies.map(c => c.remaining_activities);
    
    competencyOverviewChartInstance = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Completed',
                    data: completedData,
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'Remaining',
                    data: remainingData,
                    backgroundColor: '#93c5fd',
                    borderRadius: 4
                }
            ]
        },
        options: {
            indexAxis: 'y', // Horizontal bars
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        padding: 15,
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed.x || 0;
                            return label + ': ' + value + ' activities';
                        },
                        footer: function(tooltipItems) {
                            const total = tooltipItems.reduce((sum, item) => sum + (item.parsed.x || 0), 0);
                            return 'Total: ' + total + ' activities';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Activities',
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        color: '#64748b'
                    },
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        font: {
                            size: 10
                        },
                        color: '#64748b'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    stacked: true,
                    ticks: {
                        font: {
                            size: 10
                        },
                        color: '#475569'
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>

<?php
echo $OUTPUT->footer();
?>
