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
 * Super Admin Reports - AJAX data endpoint
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once('../../../../config.php');

global $DB, $CFG;

require_login();

// Verify admin access
if (!is_siteadmin()) {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Verify session key
$sesskey = optional_param('sesskey', '', PARAM_RAW);
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Invalid session key']));
}

require_once($CFG->dirroot . '/theme/remui_kids/admin/superreports/lib.php');

// Get parameters
$tab = required_param('tab', PARAM_ALPHANUMEXT); // Allow hyphens in tab names
$schoolid = optional_param('school', 0, PARAM_INT);
$cohortid = optional_param('cohort', 0, PARAM_INT);
$gradeid = optional_param('grade', '', PARAM_TEXT);
$frameworkid = optional_param('framework', 0, PARAM_INT);
$daterange = optional_param('daterange', 'month', PARAM_ALPHA);
$startdate = optional_param('startdate', '', PARAM_TEXT);
$enddate = optional_param('enddate', '', PARAM_TEXT);

// Convert empty strings to 0 for integer filters
$schoolid = (int)$schoolid;
$cohortid = (int)$cohortid;
$frameworkid = (int)$frameworkid;

// Debug logging
error_log("Super Reports AJAX - Tab: $tab, School: $schoolid (type: " . gettype($schoolid) . "), Cohort: $cohortid (type: " . gettype($cohortid) . "), Grade: $gradeid, DateRange: $daterange");

// Clean any output buffers that might have been started
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors directly
ini_set('log_errors', 1);

try {
    switch ($tab) {
        case 'overview':
            $stats = superreports_get_overview_stats($schoolid, $cohortid, $daterange, $startdate, $enddate, $gradeid);
            $activitytrend = superreports_get_activity_trend($schoolid, $daterange, $startdate, $enddate);
            $coursecompletion = superreports_get_course_completion_by_school($schoolid, $cohortid, $daterange, $startdate, $enddate);
            $usersbyrole = superreports_get_users_by_role($schoolid, $cohortid, $daterange, $startdate, $enddate);
            $recentactivity = superreports_get_recent_activity(10, $schoolid, $cohortid, $daterange, $startdate, $enddate);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'activityTrend' => $activitytrend,
                'courseCompletion' => $coursecompletion,
                'usersByRole' => $usersbyrole,
                'recentActivity' => $recentactivity
            ]);
            break;
            
        case 'assignments':
            $data = superreports_get_assignments_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
            $chartData = superreports_get_assignment_completion_by_course($schoolid, $gradeid, $daterange, $startdate, $enddate);
            $trendData = superreports_get_assignment_grade_trend($schoolid, $gradeid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'chartData' => $chartData,
                'trendData' => $trendData
            ]);
            break;
            
        case 'quizzes':
            $data = superreports_get_quizzes_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
            $chartData = superreports_get_quiz_scores_by_course($schoolid, $gradeid, $daterange, $startdate, $enddate);
            $radarData = superreports_get_quiz_scores_by_competency($schoolid, $gradeid, $daterange, $startdate, $enddate);
            $schoolData = superreports_get_quiz_scores_by_school($schoolid, $gradeid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'chartData' => $chartData,
                'radarData' => $radarData,
                'schoolData' => $schoolData
            ]);
            break;
            
        case 'overall-grades':
            $data = superreports_get_overall_grades($schoolid, $gradeid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
            break;
            
        case 'competencies':
            // Use cohortid if available, otherwise use gradeid
            $cohort_or_grade = $cohortid > 0 ? $cohortid : $gradeid;
            error_log("Competencies AJAX - cohortid: $cohortid, gradeid: $gradeid, using: $cohort_or_grade");
            $data = superreports_get_competency_progress($schoolid, $cohort_or_grade, $frameworkid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
            break;
            
        case 'performance':
        case 'teacher-performance':
            $result = superreports_get_teacher_performance($schoolid, $cohortid, $daterange, $startdate, $enddate);
            $scatterData = superreports_get_teacher_engagement_scatter($schoolid, $cohortid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'data' => $result['teachers'],  // Pass teachers array
                'total_count' => $result['total_count'],  // Debug info
                'displayed_count' => $result['displayed_count'],  // Debug info
                'scatterData' => $scatterData
            ]);
            break;
            
        case 'student-performance':
            try {
                $data = superreports_get_student_performance_detailed($schoolid, $gradeid, $daterange, $startdate, $enddate);
                error_log("Student performance data retrieved successfully");
                
                try {
                    $donutData = superreports_get_course_completion_brackets($schoolid, $gradeid, $daterange, $startdate, $enddate);
                    error_log("Donut data retrieved: " . json_encode($donutData));
                } catch (Exception $e) {
                    error_log("DONUT DATA ERROR: " . $e->getMessage());
                    throw $e;
                }
                
                try {
                    $heatmapData = superreports_get_learning_activity_heatmap($schoolid, $gradeid, $daterange, $startdate, $enddate);
                    error_log("Heatmap data retrieved: labels=" . count($heatmapData['labels']) . ", datasets=" . count($heatmapData['datasets']));
                } catch (Exception $e) {
                    error_log("HEATMAP DATA ERROR: " . $e->getMessage());
                    throw $e;
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $data,
                    'donutData' => $donutData,
                    'heatmapData' => $heatmapData
                ]);
            } catch (Exception $e) {
                error_log("Student performance error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            break;
            
        case 'courses':
            $data = superreports_get_course_analytics($schoolid, $cohortid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'kpi' => $data['kpi'],
                'topCourses' => $data['topCourses'],
                'enrollmentTrend' => $data['enrollmentTrend'],
                'completionVsDropout' => $data['completionVsDropout'],
                'categoryDistribution' => $data['categoryDistribution'],
                'engagementVsCompletion' => $data['engagementVsCompletion'],
                'insights' => $data['insights']
            ]);
            break;
            
        case 'activity':
            $recentactivity = superreports_get_recent_activity(50, $schoolid, $cohortid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'activities' => $recentactivity
            ]);
            break;
            
        case 'attendance':
            // Placeholder for attendance data
            echo json_encode([
                'success' => true,
                'message' => 'Attendance data will be displayed here'
            ]);
            break;
            
        case 'audit':
            // Get audit logs
            $logs = $DB->get_records_sql(
                "SELECT l.id, l.userid, l.eventname, l.action, l.target, l.timecreated,
                        u.firstname, u.lastname
                 FROM {logstore_standard_log} l
                 JOIN {user} u ON u.id = l.userid
                 ORDER BY l.timecreated DESC
                 LIMIT 100"
            );
            
            $auditdata = [];
            foreach ($logs as $log) {
                $auditdata[] = [
                    'id' => $log->id,
                    'user' => fullname($log),
                    'action' => $log->action,
                    'target' => $log->target,
                    'event' => $log->eventname,
                    'time' => userdate($log->timecreated, get_string('strftimedatetime', 'langconfig'))
                ];
            }
            
            echo json_encode([
                'success' => true,
                'logs' => $auditdata
            ]);
            break;
            
        case 'ai-summary':
            // AI-powered insights summary
            $insights = superreports_get_ai_insights($schoolid, $gradeid, $daterange, $startdate, $enddate);
            echo json_encode([
                'success' => true,
                'insights' => $insights
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid tab'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

