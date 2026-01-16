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
 * Super Admin Reports - Data aggregation library
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get date range timestamps based on filter
 */
function superreports_get_date_range($range, $startdate = null, $enddate = null) {
    $now = time();
    
    switch ($range) {
        case 'week':
            $start = strtotime('last monday', $now);
            $end = $now;
            break;
        case 'month':
            $start = strtotime('first day of this month', $now);
            $end = $now;
            break;
        case 'quarter':
            $month = date('n', $now);
            $year = date('Y', $now);
            $quarter = ceil($month / 3);
            $startmonth = (($quarter - 1) * 3) + 1;
            $start = mktime(0, 0, 0, $startmonth, 1, $year);
            $end = $now;
            break;
        case 'year':
            $start = strtotime('first day of january this year', $now);
            $end = $now;
            break;
        case 'custom':
            $start = $startdate ? strtotime($startdate) : strtotime('-30 days', $now);
            $end = $enddate ? strtotime($enddate) : $now;
            break;
        default:
            $start = strtotime('-30 days', $now);
            $end = $now;
    }
    
    return [$start, $end];
}

/**
 * Get overview statistics
 */
function superreports_get_overview_stats($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null, $gradeid = '') {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $stats = [];
    
    // Total Schools (Companies) - check if table exists first
    try {
        if ($DB->get_manager()->table_exists('company')) {
            if ($schoolid > 0) {
                $stats['total_schools'] = 1; // Single school selected
            } else {
                $stats['total_schools'] = $DB->count_records('company');
            }
        } else {
            $stats['total_schools'] = 0;
        }
    } catch (Exception $e) {
        $stats['total_schools'] = 0;
    }
    
    // Total Teachers (exclude deleted users, apply school, cohort, and date range filters)
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if ($teacherroleid) {
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                FROM {role_assignments} ra
                JOIN {user} u ON u.id = ra.userid";
        $params = ['roleid' => $teacherroleid];
        $conditions = ['ra.roleid = :roleid', 'u.deleted = 0'];
        
        // Filter by school
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
            $sql .= " JOIN {company_users} cu ON cu.userid = ra.userid";
            $conditions[] = "cu.companyid = :companyid";
            $params['companyid'] = $schoolid;
        }
        
        // Filter by cohort
        if ($cohortid > 0) {
            $sql .= " JOIN {cohort_members} cm ON cm.userid = ra.userid";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        }
        
        // Filter by date range (active users only)
        if ($start && $end) {
            $conditions[] = "u.lastaccess >= :startdate";
            $conditions[] = "u.lastaccess <= :enddate";
            $params['startdate'] = $start;
            $params['enddate'] = $end;
        }
        
        $sql .= " WHERE " . implode(" AND ", $conditions);
        $stats['total_teachers'] = $DB->count_records_sql($sql, $params);
    } else {
        $stats['total_teachers'] = 0;
    }
    
    // Total Students
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if ($studentroleid) {
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                FROM {role_assignments} ra
                JOIN {user} u ON u.id = ra.userid";
        $params = ['roleid' => $studentroleid];
        $conditions = ['ra.roleid = :roleid', 'u.deleted = 0'];
        
        // Filter by school
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
            $sql .= " JOIN {company_users} cu ON cu.userid = ra.userid";
            $conditions[] = "cu.companyid = :companyid";
            $params['companyid'] = $schoolid;
        }
        
        // Filter by cohort
        if ($cohortid > 0) {
            $sql .= " JOIN {cohort_members} cm ON cm.userid = ra.userid";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        } elseif (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
            // Try to find cohort by name matching grade
            $cohortname = "Grade " . $gradeid;
            $gradecohortid = $DB->get_field_sql(
                "SELECT id FROM {cohort} WHERE " . $DB->sql_like('name', ':cohortname', false),
                ['cohortname' => '%' . $DB->sql_like_escape($cohortname) . '%']
            );
            if ($gradecohortid) {
                $sql .= " JOIN {cohort_members} cm ON cm.userid = ra.userid";
                $conditions[] = "cm.cohortid = :gradecohortid";
                $params['gradecohortid'] = $gradecohortid;
            }
        }
        
        // Filter by date range (active users only)
        if ($start && $end) {
            $conditions[] = "u.lastaccess >= :startdate";
            $conditions[] = "u.lastaccess <= :enddate";
            $params['startdate'] = $start;
            $params['enddate'] = $end;
        }
        
        $sql .= " WHERE " . implode(" AND ", $conditions);
        $stats['total_students'] = $DB->count_records_sql($sql, $params);
    } else {
        $stats['total_students'] = 0;
    }
    
    // Average Course Completion
    $sql = "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as avg_completion
            FROM {course_completions} cc";
    $params = ['start' => $start, 'end' => $end];
    $joins = [];
    $conditions = ['cc.timecompleted >= :start', 'cc.timecompleted <= :end'];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $joins[] = "JOIN {company_course} compc ON compc.courseid = cc.course";
        $conditions[] = "compc.companyid = :companyid";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by grade (cohort-based)
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $cohortname = "Grade " . $gradeid;
        $cohortid = $DB->get_field_sql(
            "SELECT id FROM {cohort} WHERE " . $DB->sql_like('name', ':cohortname', false),
            ['cohortname' => '%' . $DB->sql_like_escape($cohortname) . '%']
        );
        if ($cohortid) {
            $joins[] = "JOIN {cohort_members} cm ON cm.userid = cc.userid";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        }
    }
    
    if (!empty($joins)) {
        $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $conditions);
    
    $result = $DB->get_record_sql($sql, $params);
    $stats['avg_completion'] = $result ? round($result->avg_completion, 1) : 0;
    
    // Total Courses
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        // Filter by school
        $sql = "SELECT COUNT(DISTINCT cc.courseid)
                FROM {company_course} cc
                JOIN {course} c ON c.id = cc.courseid
                WHERE cc.companyid = :companyid AND c.visible = 1";
        $stats['total_courses'] = $DB->count_records_sql($sql, ['companyid' => $schoolid]);
    } else {
        // All schools
        $stats['total_courses'] = $DB->count_records('course', ['visible' => 1]) - 1; // Exclude site course
    }
    
    // Active Users (logged in within date range)
    $sql = "SELECT COUNT(DISTINCT l.userid)
            FROM {logstore_standard_log} l";
    $params = ['start' => $start, 'end' => $end];
    $joins = [];
    $conditions = ['l.timecreated >= :start', 'l.timecreated <= :end'];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $joins[] = "JOIN {company_users} cu ON cu.userid = l.userid";
        $conditions[] = "cu.companyid = :companyid";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by grade
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $cohortname = "Grade " . $gradeid;
        $cohortid = $DB->get_field_sql(
            "SELECT id FROM {cohort} WHERE " . $DB->sql_like('name', ':cohortname', false),
            ['cohortname' => '%' . $DB->sql_like_escape($cohortname) . '%']
        );
        if ($cohortid) {
            $joins[] = "JOIN {cohort_members} cm ON cm.userid = l.userid";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        }
    }
    
    if (!empty($joins)) {
        $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $conditions);
    
    $stats['active_users'] = $DB->count_records_sql($sql, $params);
    
    return $stats;
}

/**
 * Get system activity trend data
 */
function superreports_get_activity_trend($schoolid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Get student role ID
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    // Get school-wise student activity performance
    if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
        
        // If specific school is selected, show only that school
        if ($schoolid > 0) {
            $schools = $DB->get_records('company', ['id' => $schoolid], 'name ASC', 'id, name');
        } else {
            $schools = $DB->get_records('company', null, 'name ASC', 'id, name', 0, 10); // Limit to top 10 schools
        }
        
        $labels = [];
        $activeStudents = [];
        $avgGrade = [];
        $completionRate = [];
        
        foreach ($schools as $school) {
            $labels[] = format_string($school->name);
            
            // Count active students (with role assignments and activity in date range)
            $activesql = "SELECT COUNT(DISTINCT ra.userid)
                         FROM {role_assignments} ra
                         JOIN {company_users} cu ON cu.userid = ra.userid
                         JOIN {user} u ON u.id = ra.userid
                         WHERE ra.roleid = :roleid
                         AND cu.companyid = :companyid
                         AND u.deleted = 0
                         AND u.lastaccess >= :startdate
                         AND u.lastaccess <= :enddate";
            $activecount = $DB->count_records_sql($activesql, [
                'roleid' => $studentroleid,
                'companyid' => $school->id,
                'startdate' => $start,
                'enddate' => $end
            ]);
            $activeStudents[] = $activecount;
            
            // Get average grade for students in this school
            $gradesql = "SELECT AVG(gg.finalgrade / gi.grademax * 100) as avg_grade
                        FROM {grade_grades} gg
                        JOIN {grade_items} gi ON gi.id = gg.itemid
                        JOIN {company_users} cu ON cu.userid = gg.userid
                        WHERE gi.itemtype = 'course'
                        AND cu.companyid = :companyid
                        AND gg.timemodified >= :startdate
                        AND gg.timemodified <= :enddate";
            $avggraderesult = $DB->get_field_sql($gradesql, [
                'companyid' => $school->id,
                'startdate' => $start,
                'enddate' => $end
            ]);
            $avgGrade[] = $avggraderesult ? round($avggraderesult, 1) : 0;
            
            // Get completion rate for students in this school
            $completionsql = "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as completion_rate
                             FROM {course_completions} cc
                             JOIN {company_users} cu ON cu.userid = cc.userid
                             JOIN {company_course} compc ON compc.courseid = cc.course
                             WHERE compc.companyid = :companyid
                             AND cu.companyid = :companyid2
                             AND cc.timecompleted >= :startdate
                             AND cc.timecompleted <= :enddate";
            $completionresult = $DB->get_field_sql($completionsql, [
                'companyid' => $school->id,
                'companyid2' => $school->id,
                'startdate' => $start,
                'enddate' => $end
            ]);
            $completionRate[] = $completionresult ? round($completionresult, 1) : 0;
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Active Students',
                    'data' => $activeStudents,
                    'borderColor' => '#3498db',
                    'backgroundColor' => 'rgba(52, 152, 219, 0.2)',
                    'yAxisID' => 'y'
                ],
                [
                    'label' => 'Avg Grade (%)',
                    'data' => $avgGrade,
                    'borderColor' => '#2ecc71',
                    'backgroundColor' => 'rgba(46, 204, 113, 0.2)',
                    'yAxisID' => 'y1'
                ],
                [
                    'label' => 'Completion Rate (%)',
                    'data' => $completionRate,
                    'borderColor' => '#f39c12',
                    'backgroundColor' => 'rgba(243, 156, 18, 0.2)',
                    'yAxisID' => 'y1'
                ]
            ]
        ];
    }
    
    // Fallback if company tables don't exist
    return [
        'labels' => [],
        'datasets' => []
    ];
}

/**
 * Get course completion by school
 */
function superreports_get_course_completion_by_school($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $labels = [];
    $data = [];
    
    // Check if company tables exist (IOMAD)
    try {
        if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_course')) {
            // If a specific school is selected, only show that school
            if ($schoolid > 0) {
                $companies = $DB->get_records('company', ['id' => $schoolid], 'name ASC', 'id, name');
            } else {
                $companies = $DB->get_records('company', null, 'name ASC', 'id, name');
            }
            
            foreach ($companies as $company) {
                $labels[] = format_string($company->name);
                
                // Get completion rate for this company's courses
                $sql = "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as completion
                        FROM {course_completions} cc
                        JOIN {course} c ON c.id = cc.course
                        JOIN {company_course} compc ON compc.courseid = c.id";
                
                $params = ['companyid' => $company->id];
                $conditions = ["compc.companyid = :companyid"];
                
                // Filter by cohort
                if ($cohortid > 0) {
                    $sql .= " JOIN {cohort_members} cm ON cm.userid = cc.userid";
                    $conditions[] = "cm.cohortid = :cohortid";
                    $params['cohortid'] = $cohortid;
                }
                
                // Filter by date range
                if ($start && $end) {
                    $conditions[] = "cc.timecompleted >= :startdate";
                    $conditions[] = "cc.timecompleted <= :enddate";
                    $params['startdate'] = $start;
                    $params['enddate'] = $end;
                }
                
                $sql .= " WHERE " . implode(" AND ", $conditions);
                
                $result = $DB->get_record_sql($sql, $params);
                $data[] = $result && $result->completion ? round($result->completion, 1) : 0;
            }
        } else {
            // Fallback: show overall completion rate
            $labels[] = 'All Courses';
            $sql = "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as completion
                    FROM {course_completions} cc";
            $params = [];
            $conditions = [];
            
            // Filter by cohort
            if ($cohortid > 0) {
                $sql .= " JOIN {cohort_members} cm ON cm.userid = cc.userid";
                $conditions[] = "cm.cohortid = :cohortid";
                $params['cohortid'] = $cohortid;
            }
            
            // Filter by date range
            if ($start && $end) {
                $conditions[] = "cc.timecompleted >= :startdate";
                $conditions[] = "cc.timecompleted <= :enddate";
                $params['startdate'] = $start;
                $params['enddate'] = $end;
            }
            
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }
            
            $result = $DB->get_record_sql($sql, $params);
            $data[] = $result && $result->completion ? round($result->completion, 1) : 0;
        }
    } catch (Exception $e) {
        // Fallback data
        $labels[] = 'System';
        $data[] = 0;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completion Rate (%)',
                'data' => $data,
                'backgroundColor' => '#3498db'
            ]
        ]
    ];
}

/**
 * Get active users by role
 */
function superreports_get_users_by_role($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    $roles = ['editingteacher' => 'Teachers', 'student' => 'Students', 'manager' => 'Managers'];
    $data = [];
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    foreach ($roles as $shortname => $label) {
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $data[] = 0;
            continue;
        }
        
        // Build dynamic SQL with filters
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                FROM {role_assignments} ra
                JOIN {user} u ON u.id = ra.userid
                JOIN {context} ctx ON ctx.id = ra.contextid";
        
        $params = ['roleid' => $roleid];
        $conditions = ['ra.roleid = :roleid', 'u.deleted = 0'];
        
        // Filter by school (company)
        if ($schoolid > 0) {
            $sql .= " JOIN {company_users} cu ON cu.userid = ra.userid";
            $conditions[] = "cu.companyid = :schoolid";
            $params['schoolid'] = $schoolid;
        }
        
        // Filter by cohort (grade)
        if ($cohortid > 0) {
            $sql .= " JOIN {cohort_members} cm ON cm.userid = ra.userid";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        }
        
        // Filter by date range (user's last access)
        if ($start && $end) {
            $conditions[] = "u.lastaccess >= :startdate";
            $conditions[] = "u.lastaccess <= :enddate";
            $params['startdate'] = $start;
            $params['enddate'] = $end;
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $count = $DB->count_records_sql($sql, $params);
        $data[] = $count;
    }
    
    return [
        'labels' => array_values($roles),
        'datasets' => [
            [
                'data' => $data,
                'backgroundColor' => ['#3498db', '#2ecc71', '#e74c3c']
            ]
        ]
    ];
}

/**
 * Get recent activity feed
 */
function superreports_get_recent_activity($limit = 10, $schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Build query with filters
    $sql = "SELECT l.id, l.userid, l.action, l.target, l.objecttable, l.objectid, l.timecreated,
                   u.firstname, u.lastname
            FROM {logstore_standard_log} l
            JOIN {user} u ON u.id = l.userid";
    
    $params = [];
    $conditions = ["l.action IN ('created', 'updated', 'graded', 'submitted')"];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $sql .= " JOIN {company_users} cu ON cu.userid = l.userid";
        $conditions[] = "cu.companyid = :schoolid";
        $params['schoolid'] = $schoolid;
    }
    
    // Filter by cohort
    if ($cohortid > 0) {
        $sql .= " JOIN {cohort_members} cm ON cm.userid = l.userid";
        $conditions[] = "cm.cohortid = :cohortid";
        $params['cohortid'] = $cohortid;
    }
    
    // Filter by date range
    if ($start && $end) {
        $conditions[] = "l.timecreated >= :startdate";
        $conditions[] = "l.timecreated <= :enddate";
        $params['startdate'] = $start;
        $params['enddate'] = $end;
    }
    
    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY l.timecreated DESC";
    
    $records = $DB->get_records_sql($sql, $params, 0, $limit);
    
    $activities = [];
    foreach ($records as $record) {
        $activities[] = [
            'user' => fullname($record),
            'action' => $record->action,
            'target' => $record->target,
            'time' => userdate($record->timecreated, get_string('strftimedatetime', 'langconfig')),
            'timeago' => format_time(time() - $record->timecreated)
        ];
    }
    
    return $activities;
}

/**
 * Get teacher report data
 */
function superreports_get_teacher_report($schoolid = 0, $daterange = 'month', $startdate = null, $enddate = null, $gradeid = '') {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if (!$teacherroleid) {
        return [];
    }
    
    // Build dynamic SQL with filters (teachers typically aren't filtered by grade, but by school)
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id";
    
    $params = ['roleid' => $teacherroleid];
    $joins = [];
    $conditions = ['ra.roleid = :roleid'];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $joins[] = "JOIN {company_users} cu ON cu.userid = u.id";
        $conditions[] = "cu.companyid = :companyid";
        $params['companyid'] = $schoolid;
    }
    
    if (!empty($joins)) {
        $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY u.lastname ASC";
    
    $teachers = $DB->get_records_sql($sql, $params);
    
    $data = [];
    foreach ($teachers as $teacher) {
        // Count courses teaching
        $coursescount = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id)
             FROM {course} c
             JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             JOIN {role_assignments} ra ON ra.contextid = ctx.id
             WHERE ra.userid = :userid AND ra.roleid = :roleid",
            ['userid' => $teacher->id, 'roleid' => $teacherroleid]
        );
        
        // Get average grade of students
        $avggrades = $DB->get_field_sql(
            "SELECT AVG(gg.finalgrade / gi.grademax * 100)
             FROM {grade_grades} gg
             JOIN {grade_items} gi ON gi.id = gg.itemid
             JOIN {course} c ON c.id = gi.courseid
             JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
             JOIN {role_assignments} ra ON ra.contextid = ctx.id
             WHERE ra.userid = :userid AND ra.roleid = :roleid
             AND gi.itemtype = 'course'",
            ['userid' => $teacher->id, 'roleid' => $teacherroleid]
        );
        
        // Count activities created
        $activitiescount = $DB->count_records_sql(
            "SELECT COUNT(*)
             FROM {logstore_standard_log}
             WHERE userid = :userid
             AND action = 'created'
             AND target = 'course_module'
             AND timecreated >= :start AND timecreated <= :end",
            ['userid' => $teacher->id, 'start' => $start, 'end' => $end]
        );
        
        $data[] = [
            'id' => $teacher->id,
            'name' => fullname($teacher),
            'email' => $teacher->email,
            'courses' => $coursescount,
            'avg_grade' => $avggrades ? round($avggrades, 1) : 0,
            'activities' => $activitiescount,
            'last_login' => $teacher->lastaccess ? userdate($teacher->lastaccess) : 'Never',
            'last_login_ago' => $teacher->lastaccess ? format_time(time() - $teacher->lastaccess) : 'Never'
        ];
    }
    
    return $data;
}

/**
 * Get student progress data
 */
function superreports_get_student_report($schoolid = 0, $daterange = 'month', $startdate = null, $enddate = null, $gradeid = '') {
    global $DB;
    
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        return [];
    }
    
    // Build dynamic SQL with filters
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id";
    
    $params = ['roleid' => $studentroleid];
    $joins = [];
    $conditions = ['ra.roleid = :roleid'];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $joins[] = "JOIN {company_users} cu ON cu.userid = u.id";
        $conditions[] = "cu.companyid = :companyid";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by grade
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $cohortname = "Grade " . $gradeid;
        $cohortid = $DB->get_field_sql(
            "SELECT id FROM {cohort} WHERE " . $DB->sql_like('name', ':cohortname', false),
            ['cohortname' => '%' . $DB->sql_like_escape($cohortname) . '%']
        );
        if ($cohortid) {
            $joins[] = "JOIN {cohort_members} cm ON cm.userid = u.id";
            $conditions[] = "cm.cohortid = :cohortid";
            $params['cohortid'] = $cohortid;
        }
    }
    
    if (!empty($joins)) {
        $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY u.lastname ASC";
    
    $students = $DB->get_records_sql($sql, $params, 0, 100);
    
    $data = [];
    foreach ($students as $student) {
        // Count enrolled courses
        $enrolledcount = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT e.courseid)
             FROM {enrol} e
             JOIN {user_enrolments} ue ON ue.enrolid = e.id
             WHERE ue.userid = :userid",
            ['userid' => $student->id]
        );
        
        // Get average grade
        $avggrade = $DB->get_field_sql(
            "SELECT AVG(gg.finalgrade / gi.grademax * 100)
             FROM {grade_grades} gg
             JOIN {grade_items} gi ON gi.id = gg.itemid
             WHERE gg.userid = :userid
             AND gi.itemtype = 'course'",
            ['userid' => $student->id]
        );
        
        // Get completion rate
        $completions = $DB->get_record_sql(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN timecompleted IS NOT NULL THEN 1 ELSE 0 END) as completed
             FROM {course_completions}
             WHERE userid = :userid",
            ['userid' => $student->id]
        );
        
        $completionrate = ($completions && $completions->total > 0) 
            ? round(($completions->completed / $completions->total) * 100, 1) 
            : 0;
        
        // Determine status
        $status = 'active';
        if (!$student->lastaccess || (time() - $student->lastaccess) > (30 * 24 * 60 * 60)) {
            $status = 'inactive';
        }
        
        $data[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'email' => $student->email,
            'enrolled' => $enrolledcount,
            'avg_grade' => $avggrade ? round($avggrade, 1) : 0,
            'completion' => $completionrate,
            'status' => $status,
            'last_access' => $student->lastaccess ? userdate($student->lastaccess) : 'Never'
        ];
    }
    
    return $data;
}

/**
 * Get course statistics
 */
function superreports_get_course_report($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    error_log("Course Report - School: $schoolid, Cohort: $cohortid, DateRange: $daterange");
    
    // Build query based on school and cohort filters
    if ($schoolid > 0 && $cohortid > 0 && $DB->get_manager()->table_exists('company_course')) {
        // Filter by both school AND cohort
        error_log("Course Report - Filtering by SCHOOL AND COHORT");
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.timecreated, c.timemodified
                FROM {course} c
                JOIN {company_course} cc ON cc.courseid = c.id
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                JOIN {cohort_members} cm ON cm.userid = ue.userid
                WHERE c.id > 1 
                AND cc.companyid = :companyid
                AND cm.cohortid = :cohortid
                ORDER BY c.fullname ASC";
        $courses = $DB->get_records_sql($sql, ['companyid' => $schoolid, 'cohortid' => $cohortid], 0, 50);
    } elseif ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        // Filter by school only
        error_log("Course Report - Filtering by SCHOOL ONLY");
        $sql = "SELECT c.id, c.fullname, c.shortname, c.timecreated, c.timemodified
                FROM {course} c
                JOIN {company_course} cc ON cc.courseid = c.id
                WHERE c.id > 1 AND cc.companyid = :companyid
                ORDER BY c.fullname ASC";
        $courses = $DB->get_records_sql($sql, ['companyid' => $schoolid], 0, 50);
    } elseif ($cohortid > 0) {
        // Filter by cohort only
        error_log("Course Report - Filtering by COHORT ONLY");
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.timecreated, c.timemodified
                FROM {course} c
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                JOIN {cohort_members} cm ON cm.userid = ue.userid
                WHERE c.id > 1 
                AND cm.cohortid = :cohortid
                ORDER BY c.fullname ASC";
        $courses = $DB->get_records_sql($sql, ['cohortid' => $cohortid], 0, 50);
    } else {
        // No filters - all courses from schools only
        error_log("Course Report - NO FILTERS (all school courses)");
        if ($DB->get_manager()->table_exists('company_course')) {
            $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.timecreated, c.timemodified
                    FROM {course} c
                    JOIN {company_course} cc ON cc.courseid = c.id
                    WHERE c.id > 1
                    ORDER BY c.fullname ASC";
            $courses = $DB->get_records_sql($sql, [], 0, 50);
        } else {
            $sql = "SELECT c.id, c.fullname, c.shortname, c.timecreated, c.timemodified
                    FROM {course} c
                    WHERE c.id > 1
                    ORDER BY c.fullname ASC";
            $courses = $DB->get_records_sql($sql, [], 0, 50);
        }
    }
    
    error_log("Course Report - Found " . count($courses) . " courses");
    
    $data = [];
    foreach ($courses as $course) {
        // Count enrolled students - filter by cohort if specified
        if ($cohortid > 0) {
            $enrolledcount = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT ue.userid)
                 FROM {enrol} e
                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                 JOIN {cohort_members} cm ON cm.userid = ue.userid
                 WHERE e.courseid = :courseid AND cm.cohortid = :cohortid",
                ['courseid' => $course->id, 'cohortid' => $cohortid]
            );
        } else {
            $enrolledcount = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT ue.userid)
                 FROM {enrol} e
                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                 WHERE e.courseid = :courseid",
                ['courseid' => $course->id]
            );
        }
        
        // Get completion rate - filter by cohort and date range if specified
        if ($cohortid > 0) {
            $completionparams = ['courseid' => $course->id, 'cohortid' => $cohortid];
            $datefilter = "";
            if ($start && $end) {
                $datefilter = " AND cc.timecompleted >= :startdate AND cc.timecompleted <= :enddate";
                $completionparams['startdate'] = $start;
                $completionparams['enddate'] = $end;
            }
            $completions = $DB->get_record_sql(
                "SELECT COUNT(*) as total,
                        SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) as completed
                 FROM {course_completions} cc
                 JOIN {cohort_members} cm ON cm.userid = cc.userid
                 WHERE cc.course = :courseid AND cm.cohortid = :cohortid" . $datefilter,
                $completionparams
            );
        } else {
            $completionparams = ['courseid' => $course->id];
            $datefilter = "";
            if ($start && $end) {
                $datefilter = " AND timecompleted >= :startdate AND timecompleted <= :enddate";
                $completionparams['startdate'] = $start;
                $completionparams['enddate'] = $end;
            }
            $completions = $DB->get_record_sql(
                "SELECT COUNT(*) as total,
                        SUM(CASE WHEN timecompleted IS NOT NULL THEN 1 ELSE 0 END) as completed
                 FROM {course_completions}
                 WHERE course = :courseid" . $datefilter,
                $completionparams
            );
        }
        
        $completionrate = ($completions && $completions->total > 0)
            ? round(($completions->completed / $completions->total) * 100, 1)
            : 0;
        
        // Get average grade - filter by cohort if specified
        if ($cohortid > 0) {
            $avggrade = $DB->get_field_sql(
                "SELECT AVG(gg.finalgrade / gi.grademax * 100)
                 FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id = gg.itemid
                 JOIN {cohort_members} cm ON cm.userid = gg.userid
                 WHERE gi.courseid = :courseid
                 AND gi.itemtype = 'course'
                 AND cm.cohortid = :cohortid",
                ['courseid' => $course->id, 'cohortid' => $cohortid]
            );
        } else {
            $avggrade = $DB->get_field_sql(
                "SELECT AVG(gg.finalgrade / gi.grademax * 100)
                 FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.courseid = :courseid
                 AND gi.itemtype = 'course'",
                ['courseid' => $course->id]
            );
        }
        
        $data[] = [
            'id' => $course->id,
            'name' => format_string($course->fullname),
            'shortname' => format_string($course->shortname),
            'enrolled' => $enrolledcount,
            'completion' => $completionrate,
            'avg_grade' => $avggrade ? round($avggrade, 1) : 0,
            'last_update' => userdate($course->timemodified, get_string('strftimedate', 'langconfig'))
        ];
    }
    
    return $data;
}

/**
 * Get grade distribution data
 */
function superreports_get_grade_distribution() {
    global $DB;
    
    $sql = "SELECT 
                FLOOR(gg.finalgrade / gi.grademax * 100 / 10) * 10 as grade_range,
                COUNT(*) as count
            FROM {grade_grades} gg
            JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gi.itemtype = 'course'
            AND gg.finalgrade IS NOT NULL
            GROUP BY FLOOR(gg.finalgrade / gi.grademax * 100 / 10) * 10
            ORDER BY grade_range ASC";
    
    $records = $DB->get_records_sql($sql);
    
    $labels = [];
    $data = [];
    
    for ($i = 0; $i <= 100; $i += 10) {
        $labels[] = $i . '-' . ($i + 9) . '%';
        $data[] = 0;
    }
    
    foreach ($records as $record) {
        $index = intval($record->grade_range / 10);
        if ($index >= 0 && $index < count($data)) {
            $data[$index] = $record->count;
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Number of Students',
                'data' => $data,
                'backgroundColor' => '#3498db'
            ]
        ]
    ];
}

/**
 * Get assignments overview data
 */
function superreports_get_assignments_overview($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Calculate previous period for trend comparison
    $duration = $end - $start;
    $prevstart = $start - $duration;
    $prevend = $start;
    
    // Get all assignments for current period
    $sql = "SELECT a.id, a.name, a.duedate, a.course, c.fullname as coursename,
                   COUNT(DISTINCT asub.id) as submissions,
                   COUNT(DISTINCT CASE WHEN asub.status = 'submitted' THEN asub.id END) as submitted
            FROM {assign} a
            JOIN {course} c ON c.id = a.course
            LEFT JOIN {assign_submission} asub ON asub.assignment = a.id
            WHERE a.timemodified >= :start AND a.timemodified <= :end";
    
    $params = ['start' => $start, 'end' => $end];
    
    // Filter by school if specified
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort if specified (filter submissions by cohort members)
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND (asub.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = asub.userid AND cm.cohortid = :cohortid))";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY a.id, a.name, a.duedate, a.course, c.fullname";
    
    $assignments = $DB->get_records_sql($sql, $params);
    
    // Calculate current period statistics
    $totalassignments = count($assignments);
    $totalsubmissions = 0;
    $completedsubmissions = 0;
    $ontimesubmissions = 0;
    $totalgrade = 0;
    $gradecount = 0;
    
    foreach ($assignments as $assignment) {
        $totalsubmissions += $assignment->submissions;
        $completedsubmissions += $assignment->submitted;
        
        // Get average grade for this assignment
        $avggrade = $DB->get_field_sql(
            "SELECT AVG((ag.grade / a.grade) * 100)
             FROM {assign_grades} ag
             JOIN {assign} a ON a.id = ag.assignment
             WHERE ag.assignment = :assignid AND ag.grade >= 0",
            ['assignid' => $assignment->id]
        );
        
        if ($avggrade) {
            $totalgrade += $avggrade;
            $gradecount++;
        }
    }
    
    // Calculate on-time submission rate across all assignments with due dates
    $ontimesql = "SELECT COUNT(DISTINCT asub.id) as ontime_count,
                         COUNT(DISTINCT CASE WHEN a.duedate > 0 THEN asub.id END) as total_with_duedate
                  FROM {assign_submission} asub
                  JOIN {assign} a ON a.id = asub.assignment
                  WHERE asub.status = 'submitted'
                  AND a.timemodified >= :start 
                  AND a.timemodified <= :end
                  AND a.duedate > 0
                  AND asub.timemodified <= a.duedate";
    
    $ontimeparams = ['start' => $start, 'end' => $end];
    
    // Apply same filters as main query
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $ontimesql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = a.course AND cc.companyid = :companyid2)";
        $ontimeparams['companyid2'] = $schoolid;
    }
    
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $ontimesql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = asub.userid AND cm.cohortid = :cohortid2)";
        $ontimeparams['cohortid2'] = $gradeid;
    }
    
    $ontimedata = $DB->get_record_sql($ontimesql, $ontimeparams);
    $ontimesubmissions = $ontimedata ? $ontimedata->ontime_count : 0;
    
    // Calculate rates
    $completionrate = $totalsubmissions > 0 ? round(($completedsubmissions / $totalsubmissions) * 100, 1) : 0;
    $avggrade = $gradecount > 0 ? round($totalgrade / $gradecount, 1) : 0;
    
    // On-time rate: percentage of completed submissions that were on-time (only for assignments with due dates)
    $ontimerate = $completedsubmissions > 0 ? round(($ontimesubmissions / $completedsubmissions) * 100, 1) : 0;
    
    // Cap at 100% to avoid display issues
    $ontimerate = min($ontimerate, 100);
    
    // Calculate previous period statistics for trends
    $prevparams = ['start' => $prevstart, 'end' => $prevend];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $prevparams['companyid'] = $schoolid;
    }
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $prevparams['cohortid'] = $gradeid;
    }
    
    $prevsql = str_replace(':start', ':start', $sql);
    $prevsql = str_replace(':end', ':end', $prevsql);
    $prevassignments = $DB->get_records_sql($prevsql, $prevparams);
    
    // Calculate previous period rates
    $prevtotalsubmissions = 0;
    $prevcompletedsubmissions = 0;
    $prevtotalgrade = 0;
    $prevgradecount = 0;
    
    foreach ($prevassignments as $assignment) {
        $prevtotalsubmissions += $assignment->submissions;
        $prevcompletedsubmissions += $assignment->submitted;
        
        $avggrade = $DB->get_field_sql(
            "SELECT AVG((ag.grade / a.grade) * 100)
             FROM {assign_grades} ag
             JOIN {assign} a ON a.id = ag.assignment
             WHERE ag.assignment = :assignid AND ag.grade >= 0",
            ['assignid' => $assignment->id]
        );
        
        if ($avggrade) {
            $prevtotalgrade += $avggrade;
            $prevgradecount++;
        }
    }
    
    $prevcompletionrate = $prevtotalsubmissions > 0 ? round(($prevcompletedsubmissions / $prevtotalsubmissions) * 100, 1) : 0;
    $prevavggrade = $prevgradecount > 0 ? round($prevtotalgrade / $prevgradecount, 1) : 0;
    
    // Calculate trend percentages
    $completiontrend = $prevcompletionrate > 0 ? round((($completionrate - $prevcompletionrate) / $prevcompletionrate) * 100, 1) : 0;
    $gradetrend = $prevavggrade > 0 ? round((($avggrade - $prevavggrade) / $prevavggrade) * 100, 1) : 0;
    
    return [
        'total_assignments' => $totalassignments,
        'completion_rate' => $completionrate,
        'completion_trend' => $completiontrend,
        'avg_grade' => $avggrade,
        'grade_trend' => $gradetrend,
        'ontime_rate' => $ontimerate,
        'total_submissions' => $totalsubmissions,
        'assignments' => array_values($assignments)
    ];
}

/**
 * Get assignment completion by course for visual representation
 */
function superreports_get_assignment_completion_by_course($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Get assignment completion data by course
    $sql = "SELECT c.id, c.fullname as coursename,
                   COUNT(DISTINCT a.id) as total_assignments,
                   COUNT(DISTINCT asub.id) as total_submissions,
                   COUNT(DISTINCT CASE WHEN asub.status = 'submitted' THEN asub.id END) as completed_submissions,
                   AVG(CASE WHEN asub.status = 'submitted' AND ag.grade >= 0 
                       THEN (ag.grade / a.grade) * 100 END) as avg_grade
            FROM {course} c
            JOIN {assign} a ON a.course = c.id
            LEFT JOIN {assign_submission} asub ON asub.assignment = a.id
            LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = asub.userid
            WHERE a.timemodified >= :start AND a.timemodified <= :end";
    
    $params = ['start' => $start, 'end' => $end];
    
    // Filter by school if specified
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort if specified (filter submissions by cohort members)
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND (asub.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = asub.userid AND cm.cohortid = :cohortid))";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY c.id, c.fullname
              HAVING total_assignments > 0
              ORDER BY completed_submissions DESC, total_submissions DESC";
    
    $courses = $DB->get_records_sql($sql, $params);
    
    // Prepare data for chart
    $labels = [];
    $completionData = [];
    $submissionData = [];
    $gradeData = [];
    
    foreach ($courses as $course) {
        $labels[] = format_string($course->coursename);
        $completionRate = $course->total_submissions > 0 
            ? round(($course->completed_submissions / $course->total_submissions) * 100, 1) 
            : 0;
        $completionData[] = $completionRate;
        $submissionData[] = $course->total_submissions;
        $gradeData[] = $course->avg_grade ? round($course->avg_grade, 1) : 0;
    }
    
    // Generate varied colors for each course (like mockup)
    $colors = [
        'rgba(52, 152, 219, 0.8)',   // Blue
        'rgba(46, 204, 113, 0.8)',   // Green
        'rgba(243, 156, 18, 0.8)',   // Orange
        'rgba(231, 76, 60, 0.8)',    // Red
        'rgba(155, 89, 182, 0.8)',   // Purple
        'rgba(26, 188, 156, 0.8)',   // Turquoise
        'rgba(52, 73, 94, 0.8)',     // Dark Blue
        'rgba(241, 196, 15, 0.8)',   // Yellow
    ];
    
    $backgroundColors = [];
    foreach ($completionData as $index => $value) {
        $backgroundColors[] = $colors[$index % count($colors)];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completion Rate',
                'data' => $completionData,
                'backgroundColor' => $backgroundColors,
                'borderColor' => $backgroundColors,
                'borderWidth' => 1
            ]
        ]
    ];
}

/**
 * Get assignment grade trend over time
 */
function superreports_get_assignment_grade_trend($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Determine time interval based on date range
    $duration = $end - $start;
    $days = round($duration / 86400);
    
    // Get months for the period
    $labels = [];
    $gradeData = [];
    
    // Create 6 data points for the trend
    $intervals = 6;
    $intervalDuration = $duration / $intervals;
    
    for ($i = 0; $i < $intervals; $i++) {
        $intervalStart = $start + ($i * $intervalDuration);
        $intervalEnd = $start + (($i + 1) * $intervalDuration);
        
        // Get month label
        $labels[] = date('M', $intervalStart);
        
        // Get average grade for this interval
        $gradesql = "SELECT AVG((ag.grade / a.grade) * 100) as avg_grade
                    FROM {assign_grades} ag
                    JOIN {assign} a ON a.id = ag.assignment
                    WHERE ag.timemodified >= :intervalstart
                    AND ag.timemodified < :intervalend
                    AND ag.grade >= 0";
        
        $params = [
            'intervalstart' => $intervalStart,
            'intervalend' => $intervalEnd
        ];
        
        // Filter by school if specified
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $gradesql .= " AND EXISTS (SELECT 1 FROM {company_course} cc 
                          WHERE cc.courseid = a.course AND cc.companyid = :companyid)";
            $params['companyid'] = $schoolid;
        }
        
        // Filter by cohort if specified
        if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
            $gradesql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm 
                          WHERE cm.userid = ag.userid AND cm.cohortid = :cohortid)";
            $params['cohortid'] = $gradeid;
        }
        
        $avggrade = $DB->get_field_sql($gradesql, $params);
        $gradeData[] = $avggrade ? round($avggrade, 1) : 0;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Average Grade',
                'data' => $gradeData,
                'borderColor' => '#3498db',
                'backgroundColor' => 'rgba(52, 152, 219, 0.1)',
                'fill' => true,
                'tension' => 0.4
            ]
        ]
    ];
}

/**
 * Get quizzes overview data
 */
function superreports_get_quizzes_overview($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Get quiz statistics
    $sql = "SELECT q.id, q.name, q.course, c.fullname as coursename,
                   COUNT(DISTINCT qa.id) as attempts,
                   COUNT(DISTINCT qa.userid) as students,
                   AVG((qa.sumgrades / q.sumgrades) * 100) as avg_score
            FROM {quiz} q
            JOIN {course} c ON c.id = q.course
            LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
            WHERE q.timemodified >= :start AND q.timemodified <= :end";
    
    $params = ['start' => $start, 'end' => $end];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort (only count attempts from cohort members)
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND (qa.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = qa.userid AND cm.cohortid = :cohortid))";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY q.id, q.name, q.course, c.fullname, q.sumgrades";
    
    $quizzes = $DB->get_records_sql($sql, $params);
    
    // Calculate totals
    $totalquizzes = count($quizzes);
    $totalattempts = 0;
    $totalstudents = 0;
    $totalscores = 0;
    $scorecount = 0;
    
    foreach ($quizzes as $quiz) {
        $totalattempts += $quiz->attempts;
        $totalstudents += $quiz->students;
        if ($quiz->avg_score) {
            $totalscores += $quiz->avg_score;
            $scorecount++;
        }
    }
    
    $avgattemptsper = $totalstudents > 0 ? round($totalattempts / $totalstudents, 1) : 0;
    $avgscore = $scorecount > 0 ? round($totalscores / $scorecount, 1) : 0;
    
    return [
        'total_quizzes' => $totalquizzes,
        'avg_score' => $avgscore,
        'avg_attempts_per_student' => $avgattemptsper,
        'total_attempts' => $totalattempts,
        'quizzes' => array_values($quizzes)
    ];
}

/**
 * Get quiz scores by course for visual representation
 */
function superreports_get_quiz_scores_by_course($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Get quiz scores grouped by course
    $sql = "SELECT c.id, c.fullname as coursename,
                   COUNT(DISTINCT q.id) as total_quizzes,
                   COUNT(DISTINCT qa.id) as total_attempts,
                   AVG((qa.sumgrades / q.sumgrades) * 100) as avg_score,
                   COUNT(DISTINCT qa.userid) as students
            FROM {course} c
            JOIN {quiz} q ON q.course = c.id
            LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
            WHERE q.timemodified >= :start AND q.timemodified <= :end";
    
    $params = ['start' => $start, 'end' => $end];
    
    // Filter by school if specified
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort if specified
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND (qa.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = qa.userid AND cm.cohortid = :cohortid))";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY c.id, c.fullname
              HAVING total_quizzes > 0
              ORDER BY avg_score DESC, total_attempts DESC";
    
    $courses = $DB->get_records_sql($sql, $params);
    
    // Prepare data for chart
    $labels = [];
    $scoreData = [];
    $attemptData = [];
    
    // Generate varied colors for each course
    $colors = [
        'rgba(52, 152, 219, 0.8)',   // Blue
        'rgba(46, 204, 113, 0.8)',   // Green
        'rgba(243, 156, 18, 0.8)',   // Orange
        'rgba(231, 76, 60, 0.8)',    // Red
        'rgba(155, 89, 182, 0.8)',   // Purple
        'rgba(26, 188, 156, 0.8)',   // Turquoise
        'rgba(52, 73, 94, 0.8)',     // Dark Blue
        'rgba(241, 196, 15, 0.8)',   // Yellow
    ];
    
    $backgroundColors = [];
    $index = 0;
    
    foreach ($courses as $course) {
        $labels[] = format_string($course->coursename);
        $scoreData[] = $course->avg_score ? round($course->avg_score, 1) : 0;
        $attemptData[] = $course->total_attempts;
        $backgroundColors[] = $colors[$index % count($colors)];
        $index++;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Average Score',
                'data' => $scoreData,
                'backgroundColor' => $backgroundColors,
                'borderColor' => $backgroundColors,
                'borderWidth' => 1
            ]
        ]
    ];
}

/**
 * Get quiz scores by competency/topic for radar chart
 */
function superreports_get_quiz_scores_by_competency($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Get quiz scores grouped by course categories (as topics/competencies)
    $sql = "SELECT cc.id, cc.name as category_name,
                   AVG((qa.sumgrades / q.sumgrades) * 100) as avg_score,
                   COUNT(DISTINCT qa.id) as total_attempts
            FROM {course_categories} cc
            JOIN {course} c ON c.category = cc.id
            JOIN {quiz} q ON q.course = c.id
            LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
            WHERE q.timemodified >= :start AND q.timemodified <= :end";
    
    $params = ['start' => $start, 'end' => $end];
    
    // Filter by school if specified
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc2 WHERE cc2.courseid = c.id AND cc2.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort if specified
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND (qa.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = qa.userid AND cm.cohortid = :cohortid))";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY cc.id, cc.name
              HAVING total_attempts > 0
              ORDER BY avg_score DESC
              LIMIT 8";  // Limit to 8 topics for better radar chart readability
    
    $categories = $DB->get_records_sql($sql, $params);
    
    // Prepare data for radar chart
    $labels = [];
    $scoreData = [];
    
    foreach ($categories as $category) {
        $labels[] = format_string($category->category_name);
        $scoreData[] = $category->avg_score ? round($category->avg_score, 1) : 0;
    }
    
    // If no data from categories, use course names as fallback
    if (empty($labels)) {
        $fallbacksql = "SELECT c.id, c.shortname,
                               AVG((qa.sumgrades / q.sumgrades) * 100) as avg_score
                        FROM {course} c
                        JOIN {quiz} q ON q.course = c.id
                        LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
                        WHERE q.timemodified >= :start AND q.timemodified <= :end";
        
        $fallbackparams = ['start' => $start, 'end' => $end];
        
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $fallbacksql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
            $fallbackparams['companyid'] = $schoolid;
        }
        
        if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
            $fallbacksql .= " AND (qa.id IS NULL OR EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = qa.userid AND cm.cohortid = :cohortid))";
            $fallbackparams['cohortid'] = $gradeid;
        }
        
        $fallbacksql .= " GROUP BY c.id, c.shortname
                         HAVING avg_score IS NOT NULL
                         ORDER BY avg_score DESC
                         LIMIT 8";
        
        $courses = $DB->get_records_sql($fallbacksql, $fallbackparams);
        
        foreach ($courses as $course) {
            $labels[] = format_string($course->shortname);
            $scoreData[] = $course->avg_score ? round($course->avg_score, 1) : 0;
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Quiz Score',
                'data' => $scoreData,
                'borderColor' => '#3498db',
                'backgroundColor' => 'rgba(52, 152, 219, 0.2)',
                'borderWidth' => 2,
                'pointBackgroundColor' => '#3498db',
                'pointBorderColor' => '#fff',
                'pointHoverBackgroundColor' => '#fff',
                'pointHoverBorderColor' => '#3498db'
            ]
        ]
    ];
}

/**
 * Get quiz scores by school for column chart
 */
function superreports_get_quiz_scores_by_school($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Check if company tables exist
    if (!$DB->get_manager()->table_exists('company') || !$DB->get_manager()->table_exists('company_course')) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    // Get quiz scores grouped by school
    if ($schoolid > 0) {
        // If specific school selected, show only that school
        $schools = $DB->get_records('company', ['id' => $schoolid], 'name ASC', 'id, name');
    } else {
        // Show all schools (limit to top 10)
        $schools = $DB->get_records('company', null, 'name ASC', 'id, name', 0, 10);
    }
    
    $labels = [];
    $scoreData = [];
    
    // Generate colors for each school
    $colors = [
        'rgba(52, 152, 219, 0.8)',   // Blue
        'rgba(46, 204, 113, 0.8)',   // Green
        'rgba(243, 156, 18, 0.8)',   // Orange
        'rgba(231, 76, 60, 0.8)',    // Red
        'rgba(155, 89, 182, 0.8)',   // Purple
        'rgba(26, 188, 156, 0.8)',   // Turquoise
        'rgba(52, 73, 94, 0.8)',     // Dark Blue
        'rgba(241, 196, 15, 0.8)',   // Yellow
        'rgba(230, 126, 34, 0.8)',   // Carrot
        'rgba(149, 165, 166, 0.8)',  // Concrete
    ];
    
    $backgroundColors = [];
    $index = 0;
    
    foreach ($schools as $school) {
        $labels[] = format_string($school->name);
        
        // Get average quiz score for this school
        $scoresql = "SELECT AVG((qa.sumgrades / q.sumgrades) * 100) as avg_score
                    FROM {quiz_attempts} qa
                    JOIN {quiz} q ON q.id = qa.quiz
                    JOIN {course} c ON c.id = q.course
                    JOIN {company_course} cc ON cc.courseid = c.id
                    WHERE qa.state = 'finished'
                    AND cc.companyid = :companyid
                    AND q.timemodified >= :start
                    AND q.timemodified <= :end";
        
        $scoreparams = [
            'companyid' => $school->id,
            'start' => $start,
            'end' => $end
        ];
        
        // Filter by cohort if specified
        if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
            $scoresql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = qa.userid AND cm.cohortid = :cohortid)";
            $scoreparams['cohortid'] = $gradeid;
        }
        
        $avgscore = $DB->get_field_sql($scoresql, $scoreparams);
        $scoreData[] = $avgscore ? round($avgscore, 1) : 0;
        $backgroundColors[] = $colors[$index % count($colors)];
        $index++;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Average Quiz Score',
                'data' => $scoreData,
                'backgroundColor' => $backgroundColors,
                'borderColor' => $backgroundColors,
                'borderWidth' => 1
            ]
        ]
    ];
}

/**
 * Get overall grades data with school and cohort breakdown
 */
function superreports_get_overall_grades($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // System-wide average grade
    $systemavg = $DB->get_field_sql(
        "SELECT AVG(gg.finalgrade / gi.grademax * 100)
         FROM {grade_grades} gg
         JOIN {grade_items} gi ON gi.id = gg.itemid
         WHERE gi.itemtype = 'course'
         AND gg.timemodified >= :start AND gg.timemodified <= :end",
        ['start' => $start, 'end' => $end]
    );
    
    $systemavg = $systemavg ? round($systemavg, 1) : 0;
    
    // Average by school
    $schoolgrades = [];
    if ($DB->get_manager()->table_exists('company')) {
        $companies = $DB->get_records('company', null, 'name ASC', 'id, name');
        foreach ($companies as $company) {
            $avg = $DB->get_field_sql(
                "SELECT AVG(gg.finalgrade / gi.grademax * 100)
                 FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id = gg.itemid
                 JOIN {user} u ON u.id = gg.userid
                 JOIN {company_users} cu ON cu.userid = u.id
                 WHERE gi.itemtype = 'course'
                 AND cu.companyid = :companyid
                 AND gg.timemodified >= :start AND gg.timemodified <= :end",
                ['companyid' => $company->id, 'start' => $start, 'end' => $end]
            );
            
            $schoolgrades[] = [
                'school' => format_string($company->name),
                'avg_grade' => $avg ? round($avg, 1) : 0
            ];
        }
    }
    
    // Top 5 students
    $topstudentssql = "SELECT u.id, u.firstname, u.lastname,
                AVG(gg.finalgrade / gi.grademax * 100) as avg_grade,
                COUNT(DISTINCT cc.course) as completed
         FROM {user} u
         JOIN {grade_grades} gg ON gg.userid = u.id
         JOIN {grade_items} gi ON gi.id = gg.itemid
         LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.timecompleted IS NOT NULL
         WHERE gi.itemtype = 'course'
         AND gg.timemodified >= :start AND gg.timemodified <= :end";
    
    $topparams = ['start' => $start, 'end' => $end];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $topstudentssql .= " AND EXISTS (SELECT 1 FROM {company_users} cu WHERE cu.userid = u.id AND cu.companyid = :companyid)";
        $topparams['companyid'] = $schoolid;
    }
    
    // Filter by cohort
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $topstudentssql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = u.id AND cm.cohortid = :cohortid)";
        $topparams['cohortid'] = $gradeid;
    }
    
    $topstudentssql .= " GROUP BY u.id, u.firstname, u.lastname ORDER BY avg_grade DESC";
    
    $topstudents = $DB->get_records_sql($topstudentssql, $topparams, 0, 5);
    
    $topstudentsarray = [];
    $rank = 1;
    foreach ($topstudents as $student) {
        $topstudentsarray[] = [
            'rank' => $rank++,
            'name' => fullname($student),
            'avg_grade' => round($student->avg_grade, 1),
            'completed' => $student->completed
        ];
    }
    
    return [
        'system_avg' => $systemavg,
        'school_grades' => $schoolgrades,
        'top_students' => $topstudentsarray
    ];
}

/**
 * Get competency progress data with comprehensive analytics
 */
function superreports_get_competency_progress($schoolid = 0, $gradeid = '', $frameworkid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    // Debug logging (gradeid is actually cohortid from gradeFilter)
    error_log("Competency Progress - School: $schoolid, Cohort/Grade: $gradeid, Framework: $frameworkid, DateRange: $daterange");
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Check if competency system is available
    if (!$DB->get_manager()->table_exists('competency') || 
        !$DB->get_manager()->table_exists('competency_coursecomp')) {
        return [
            'total_competencies' => 0,
            'total_mapped_competencies' => 0,
            'unmapped_competencies' => 0,
            'total_courses_with_competencies' => 0,
            'overall_completion_rate' => 0,
            'frameworks' => [],
            'competencies' => [],
            'competency_coverage_by_course' => [],
            'completion_by_cohort' => [],
            'summary' => [
                'message' => 'Competency system is not enabled or no data available'
            ]
        ];
    }
    
    // Get all competency frameworks
    $frameworks = [];
    if ($DB->get_manager()->table_exists('competency_framework')) {
        $frameworkrecords = $DB->get_records('competency_framework', null, 'shortname ASC');
        foreach ($frameworkrecords as $fw) {
            $frameworks[] = [
                'id' => $fw->id,
                'name' => format_string($fw->shortname),
                'description' => format_string($fw->description)
            ];
        }
    }
    
    // Build parameters for filtering
    $params = [];
    $userjoin = "";
    $userwhere = "";
    $frameworkwhere = "";
    $coursejoin = "";
    $coursewhere = "";
    
    // Date range filter for competency completion
    if ($start && $end && $DB->get_manager()->table_exists('competency_usercompcourse')) {
        $userwhere .= " AND ucc.timecreated >= :startdate AND ucc.timecreated <= :enddate";
        $params['startdate'] = $start;
        $params['enddate'] = $end;
    }
    
    // Framework filter
    if ($frameworkid > 0) {
        error_log("Competency Progress - Adding FRAMEWORK filter for frameworkid: $frameworkid");
        $frameworkwhere = " AND c.competencyframeworkid = :frameworkid";
        $params['frameworkid'] = $frameworkid;
    }
    
    // Prepare course filtering based on school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        error_log("Competency Progress - Adding SCHOOL filter for courses");
        $coursejoin .= " INNER JOIN {company_course} cc_school ON cc_school.courseid = cc.courseid";
        $coursewhere .= " AND cc_school.companyid = :companyid_course";
        $params['companyid_course'] = $schoolid;
    }
    
    // Prepare user filtering based on school or cohort
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        error_log("Competency Progress - Adding SCHOOL filter for users");
        $userjoin .= " INNER JOIN {company_users} cu ON cu.userid = ucc.userid";
        $userwhere .= " AND cu.companyid = :companyid_user";
        $params['companyid_user'] = $schoolid;
    }
    
    // Filter by cohort (grade) - handle both string and integer values
    $gradeid_int = is_numeric($gradeid) ? (int)$gradeid : 0;
    if ($gradeid_int > 0 && $DB->get_manager()->table_exists('cohort_members')) {
        error_log("Competency Progress - Adding COHORT filter for cohortid: $gradeid_int");
        $userjoin .= " INNER JOIN {cohort_members} cm ON cm.userid = ucc.userid";
        $userwhere .= " AND cm.cohortid = :cohortid";
        $params['cohortid'] = $gradeid_int;
    }
    
    // Get ALL competencies with framework info
    $totalcompsql = "SELECT COUNT(c.id) as total FROM {competency} c WHERE 1=1 $frameworkwhere";
    $totalcompresult = $DB->get_record_sql($totalcompsql, $params);
    $totalcompetencies = $totalcompresult ? $totalcompresult->total : 0;
    
    // Get MAPPED competencies with their statistics
    $sql = "SELECT c.id,
                   c.shortname,
                   c.description,
                   c.idnumber,
                   c.competencyframeworkid,
                   cf.shortname as framework_name,
                   COUNT(DISTINCT cc.courseid) as courses_count,
                   COUNT(DISTINCT ucc.userid) as total_users,
                   COUNT(DISTINCT CASE WHEN ucc.proficiency = 1 THEN ucc.userid END) as proficient_users,
                   AVG(CASE WHEN ucc.grade IS NOT NULL THEN ucc.grade END) as avg_grade
            FROM {competency} c
            LEFT JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
            INNER JOIN {competency_coursecomp} cc ON cc.competencyid = c.id
            $coursejoin
            LEFT JOIN {competency_usercompcourse} ucc ON ucc.competencyid = c.id AND ucc.courseid = cc.courseid
            $userjoin
            WHERE 1=1 $coursewhere $userwhere $frameworkwhere
            GROUP BY c.id, c.shortname, c.description, c.idnumber, c.competencyframeworkid, cf.shortname
            ORDER BY courses_count DESC, c.shortname ASC";
    
    $competencies = $DB->get_records_sql($sql, $params);
    
    $totalmappedcompetencies = count($competencies);
    $unmappedcompetencies = $totalcompetencies - $totalmappedcompetencies;
    
    error_log("Competency Progress - Found $totalmappedcompetencies mapped competencies (Total: $totalcompetencies, Unmapped: $unmappedcompetencies)");
    
    // Get total courses with competencies
    $coursessql = "SELECT COUNT(DISTINCT cc.courseid) as total FROM {competency_coursecomp} cc";
    $courseparams = [];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $coursessql .= " INNER JOIN {company_course} cmpc ON cmpc.courseid = cc.courseid
                        WHERE cmpc.companyid = :companyid2";
        $courseparams['companyid2'] = $schoolid;
    }
    $coursesresult = $DB->get_record_sql($coursessql, $courseparams);
    $totalcourseswithcompetencies = $coursesresult ? $coursesresult->total : 0;
    
    // Build competency array with framework info
    $comparray = [];
    $totalusersacross = 0;
    $totalproficientacross = 0;
    $completiondistribution = []; // For radar chart
    
    foreach ($competencies as $comp) {
        $completionrate = 0;
        if ($comp->total_users > 0) {
            $completionrate = round(($comp->proficient_users / $comp->total_users) * 100, 1);
        }
        
        $totalusersacross += $comp->total_users;
        $totalproficientacross += $comp->proficient_users;
        
        $comparray[] = [
            'id' => $comp->id,
            'name' => format_string($comp->shortname),
            'description' => strip_tags($comp->description),
            'idnumber' => $comp->idnumber,
            'framework' => $comp->framework_name ? format_string($comp->framework_name) : 'N/A',
            'courses_mapped' => (int)$comp->courses_count,
            'total_users' => (int)$comp->total_users,
            'proficient_users' => (int)$comp->proficient_users,
            'completion_rate' => $completionrate,
            'avg_grade' => $comp->avg_grade ? round($comp->avg_grade, 1) : 0
        ];
        
        // Store for radar chart - show all competencies
        $completiondistribution[] = [
            'label' => format_string($comp->shortname),
            'value' => $completionrate
        ];
    }
    
    // Calculate overall completion rate
    $overallcompletionrate = 0;
    if ($totalusersacross > 0) {
        $overallcompletionrate = round(($totalproficientacross / $totalusersacross) * 100, 1);
    }
    
    // Get Competency Coverage by Course (for bar chart)
    $coveragesql = "SELECT co.id, co.fullname, co.shortname, COUNT(DISTINCT cc.competencyid) as comp_count
                    FROM {course} co
                    INNER JOIN {competency_coursecomp} cc ON cc.courseid = co.id";
    
    $coverageparams = [];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $coveragesql .= " INNER JOIN {company_course} cmc ON cmc.courseid = co.id
                          WHERE cmc.companyid = :companyid3";
        $coverageparams['companyid3'] = $schoolid;
    }
    
    $coveragesql .= " GROUP BY co.id, co.fullname, co.shortname ORDER BY comp_count DESC LIMIT 20";
    $coveragedata = $DB->get_records_sql($coveragesql, $coverageparams);
    
    $competencycoverage = [];
    foreach ($coveragedata as $coursedata) {
        $competencycoverage[] = [
            'course' => format_string($coursedata->shortname),
            'count' => (int)$coursedata->comp_count
        ];
    }
    
    // Get Completion by Cohort (for stacked bar chart)
    $completionbycohort = [];
    if ($DB->get_manager()->table_exists('cohort') && $DB->get_manager()->table_exists('cohort_members')) {
        $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name', 0, 10);
        foreach ($cohorts as $cohort) {
            $cohortsql = "SELECT c.id, c.shortname,
                                 COUNT(DISTINCT ucc.userid) as users,
                                 COUNT(DISTINCT CASE WHEN ucc.proficiency = 1 THEN ucc.userid END) as proficient
                          FROM {competency} c
                          INNER JOIN {competency_coursecomp} cc ON cc.competencyid = c.id
                          LEFT JOIN {competency_usercompcourse} ucc ON ucc.competencyid = c.id
                          INNER JOIN {cohort_members} cm ON cm.userid = ucc.userid
                          WHERE cm.cohortid = :cohortid $frameworkwhere
                          GROUP BY c.id, c.shortname
                          LIMIT 5";
            
            $cohortparams = array_merge($params, ['cohortid' => $cohort->id]);
            $cohortcomps = $DB->get_records_sql($cohortsql, $cohortparams);
            
            foreach ($cohortcomps as $ccomp) {
                $rate = $ccomp->users > 0 ? round(($ccomp->proficient / $ccomp->users) * 100, 1) : 0;
                $completionbycohort[] = [
                    'cohort' => format_string($cohort->name),
                    'competency' => format_string($ccomp->shortname),
                    'completion_rate' => $rate
                ];
            }
        }
    }
    
    // Generate insights
    $insights = [];
    if ($unmappedcompetencies > 0) {
        $pct = round(($unmappedcompetencies / $totalcompetencies) * 100, 0);
        $insights[] = "$unmappedcompetencies competencies ($pct%) remain unmapped — consider linking to relevant courses.";
    }
    if ($overallcompletionrate >= 75) {
        $insights[] = "Overall competency achievement is excellent at $overallcompletionrate%.";
    } else if ($overallcompletionrate < 50 && $overallcompletionrate > 0) {
        $insights[] = "Competency completion rate is below 50% — review learning pathways and assessment methods.";
    }
    
    // Find best and worst performing competencies
    if (count($comparray) > 0) {
        usort($comparray, function($a, $b) {
            return $b['completion_rate'] - $a['completion_rate'];
        });
        
        $best = $comparray[0];
        $worst = $comparray[count($comparray) - 1];
        
        if ($best['completion_rate'] >= 80) {
            $insights[] = "'{$best['name']}' shows highest mastery at {$best['completion_rate']}% completion.";
        }
        if ($worst['completion_rate'] < 60 && $worst['completion_rate'] > 0) {
            $insights[] = "'{$worst['name']}' needs attention with only {$worst['completion_rate']}% completion.";
        }
    }
    
    return [
        'total_competencies' => $totalcompetencies,
        'total_mapped_competencies' => $totalmappedcompetencies,
        'unmapped_competencies' => $unmappedcompetencies,
        'unmapped_percentage' => $totalcompetencies > 0 ? round(($unmappedcompetencies / $totalcompetencies) * 100, 1) : 0,
        'total_courses_with_competencies' => $totalcourseswithcompetencies,
        'overall_completion_rate' => $overallcompletionrate,
        'total_users_tracked' => $totalusersacross,
        'total_proficient_users' => $totalproficientacross,
        'frameworks' => $frameworks,
        'competencies' => $comparray,
        'competency_coverage_by_course' => $competencycoverage,
        'completion_distribution' => $completiondistribution,
        'completion_by_cohort' => $completionbycohort,
        'insights' => $insights,
        'summary' => [
            'message' => "Showing $totalmappedcompetencies competencies mapped to $totalcourseswithcompetencies courses"
        ]
    ];
}

/**
 * Get teacher performance with month comparison - Fixed calculation
 */
function superreports_get_teacher_performance($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    // Debug logging
    error_log("Teacher Performance - School: $schoolid (type: " . gettype($schoolid) . "), Cohort: $cohortid (type: " . gettype($cohortid) . "), DateRange: $daterange");
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // Calculate previous period
    $duration = $end - $start;
    $prevstart = $start - $duration;
    $prevend = $start;
    
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if (!$teacherroleid) {
        error_log("Teacher Performance - ERROR: editingteacher role not found in database");
        return [
            'total_count' => 0,
            'displayed_count' => 0,
            'teachers' => []
        ];
    }
    
    // Step 1: Get teacher IDs using EXACT same query as overview
    $teacheridsql = "SELECT DISTINCT ra.userid";
    
    // Apply both school and cohort filters if needed
    if ($schoolid > 0 && $cohortid > 0 && $DB->get_manager()->table_exists('company_users')) {
        // Filter by both school AND cohort
        error_log("Teacher Performance - Using BOTH school AND cohort filter");
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {company_users} cu ON cu.userid = ra.userid
                          JOIN {cohort_members} cm ON cm.userid = ra.userid
                          WHERE ra.roleid = :roleid 
                          AND cu.companyid = :companyid
                          AND cm.cohortid = :cohortid";
        $teacheridparams = ['roleid' => $teacherroleid, 'companyid' => $schoolid, 'cohortid' => $cohortid];
    } elseif ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        // Filter by school only
        error_log("Teacher Performance - Using SCHOOL ONLY filter");
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {company_users} cu ON cu.userid = ra.userid
                          WHERE ra.roleid = :roleid AND cu.companyid = :companyid";
        $teacheridparams = ['roleid' => $teacherroleid, 'companyid' => $schoolid];
    } elseif ($cohortid > 0) {
        // Filter by cohort only
        error_log("Teacher Performance - Using COHORT ONLY filter");
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {cohort_members} cm ON cm.userid = ra.userid
                          WHERE ra.roleid = :roleid AND cm.cohortid = :cohortid";
        $teacheridparams = ['roleid' => $teacherroleid, 'cohortid' => $cohortid];
    } else {
        // No school/cohort filter - show only teachers who ARE assigned to at least one school
        error_log("Teacher Performance - Using ALL SCHOOLS filter (only teachers assigned to schools)");
        if ($DB->get_manager()->table_exists('company_users')) {
            $teacheridsql .= " FROM {role_assignments} ra
                              JOIN {company_users} cu ON cu.userid = ra.userid
                              WHERE ra.roleid = :roleid";
            $teacheridparams = ['roleid' => $teacherroleid];
        } else {
            // Fallback if company_users doesn't exist
            $teacheridsql .= " FROM {role_assignments} ra
                              WHERE ra.roleid = :roleid";
            $teacheridparams = ['roleid' => $teacherroleid];
        }
    }
    
    $teacherids = $DB->get_fieldset_sql($teacheridsql, $teacheridparams);
    
    // Debug: Log teacher IDs found
    error_log("Teacher Performance - Found " . count($teacherids) . " teacher IDs with filters");
    
    if (empty($teacherids)) {
        return [
            'total_count' => 0,
            'displayed_count' => 0,
            'teachers' => []
        ];
    }
    
    // Step 2: Get performance metrics for these teachers
    list($insql, $inparams) = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED);
    
    // Build the performance query - filter courses by school if needed
    $courseschoolfilter = '';
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $courseschoolfilter = " AND EXISTS (
            SELECT 1 FROM {company_course} cc 
            WHERE cc.courseid = c.id AND cc.companyid = :schoolfilter
        )";
    }
    
    $sql = "SELECT u.id, u.firstname, u.lastname,
                   COUNT(DISTINCT CASE 
                       WHEN ctx.contextlevel = 50 THEN c.id 
                   END) as courses,
                   COUNT(DISTINCT CASE 
                       WHEN l.timecreated >= :start AND l.timecreated <= :end 
                       AND l.action IN ('viewed', 'graded', 'submitted', 'created', 'updated')
                       THEN DATE(FROM_UNIXTIME(l.timecreated)) 
                   END) as current_active_days,
                   COUNT(DISTINCT CASE 
                       WHEN l.timecreated >= :prevstart AND l.timecreated < :prevend 
                       AND l.action IN ('viewed', 'graded', 'submitted', 'created', 'updated')
                       THEN DATE(FROM_UNIXTIME(l.timecreated)) 
                   END) as prev_active_days
            FROM {user} u
            LEFT JOIN {role_assignments} ra ON ra.userid = u.id
            LEFT JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
            LEFT JOIN {course} c ON c.id = ctx.instanceid $courseschoolfilter
            LEFT JOIN {logstore_standard_log} l ON l.userid = u.id AND l.courseid = c.id
            WHERE u.id $insql
            AND u.deleted = 0
            GROUP BY u.id, u.firstname, u.lastname";
    
    $params = array_merge($inparams, [
        'start' => $start,
        'end' => $end,
        'prevstart' => $prevstart,
        'prevend' => $prevend
    ]);
    
    // Add school filter parameter if needed
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $params['schoolfilter'] = $schoolid;
        error_log("Teacher Performance - Adding school filter for courses: $schoolid");
    }
    
    $teachers = $DB->get_records_sql($sql, $params);
    
    error_log("Teacher Performance - Retrieved " . count($teachers) . " teachers with performance data");
    
    $data = [];
    $teachers_with_courses = 0;
    $teachers_with_engagement = 0;
    
    foreach ($teachers as $teacher) {
        // Calculate engagement score (active days)
        $currentengagement = (int)$teacher->current_active_days;
        $prevengagement = (int)$teacher->prev_active_days;
        
        // Debug: Count teachers with actual data
        if ((int)$teacher->courses > 0) {
            $teachers_with_courses++;
        }
        if ($currentengagement > 0) {
            $teachers_with_engagement++;
        }
        
        // Calculate percentage change with safeguards
        $change = 0;
        if ($prevengagement > 0) {
            $change = round((($currentengagement - $prevengagement) / $prevengagement) * 100, 1);
            // Cap at reasonable limits
            $change = max(-100, min(500, $change)); // Between -100% and 500%
        } elseif ($currentengagement > 0) {
            // New activity where there was none before
            $change = 100;
        }
        
        $data[] = [
            'name' => fullname($teacher),
            'courses' => (int)$teacher->courses,
            'engagement' => $currentengagement,
            'prev_engagement' => $prevengagement,
            'change' => $change
        ];
    }
    
    // Debug: Log summary
    error_log("Teacher Performance - Summary: $teachers_with_courses teachers have courses, $teachers_with_engagement have engagement");
    error_log("Teacher Performance - Returning " . count($data) . " teacher records");
    
    // Debug: Return total count to verify
    return [
        'total_count' => count($teacherids),  // Should match overview count
        'displayed_count' => count($data),    // Should equal total_count
        'teachers' => $data
    ];
}

/**
 * Get teacher engagement vs student completion for scatter chart
 */
function superreports_get_teacher_engagement_scatter($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    
    if (!$teacherroleid || !$studentroleid) {
        return [
            'datasets' => []
        ];
    }
    
    // Get teacher IDs with filters
    $teacheridsql = "SELECT DISTINCT ra.userid";
    
    if ($schoolid > 0 && $cohortid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {company_users} cu ON cu.userid = ra.userid
                          JOIN {cohort_members} cm ON cm.userid = ra.userid
                          WHERE ra.roleid = :roleid 
                          AND cu.companyid = :companyid
                          AND cm.cohortid = :cohortid";
        $teacheridparams = ['roleid' => $teacherroleid, 'companyid' => $schoolid, 'cohortid' => $cohortid];
    } elseif ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {company_users} cu ON cu.userid = ra.userid
                          WHERE ra.roleid = :roleid AND cu.companyid = :companyid";
        $teacheridparams = ['roleid' => $teacherroleid, 'companyid' => $schoolid];
    } elseif ($cohortid > 0) {
        $teacheridsql .= " FROM {role_assignments} ra
                          JOIN {cohort_members} cm ON cm.userid = ra.userid
                          WHERE ra.roleid = :roleid AND cm.cohortid = :cohortid";
        $teacheridparams = ['roleid' => $teacherroleid, 'cohortid' => $cohortid];
    } else {
        if ($DB->get_manager()->table_exists('company_users')) {
            $teacheridsql .= " FROM {role_assignments} ra
                              JOIN {company_users} cu ON cu.userid = ra.userid
                              WHERE ra.roleid = :roleid";
            $teacheridparams = ['roleid' => $teacherroleid];
        } else {
            $teacheridsql .= " FROM {role_assignments} ra
                              WHERE ra.roleid = :roleid";
            $teacheridparams = ['roleid' => $teacherroleid];
        }
    }
    
    $teacherids = $DB->get_fieldset_sql($teacheridsql, $teacheridparams);
    
    if (empty($teacherids)) {
        return [
            'datasets' => []
        ];
    }
    
    $scatterData = [];
    
    foreach ($teacherids as $teacherid) {
        $teacher = $DB->get_record('user', ['id' => $teacherid], 'id, firstname, lastname');
        if (!$teacher) {
            continue;
        }
        
        // Calculate teacher engagement (activity count)
        $engagementsql = "SELECT COUNT(DISTINCT l.id) as activities
                         FROM {logstore_standard_log} l
                         JOIN {context} ctx ON ctx.id = l.contextid
                         JOIN {course} c ON c.id = ctx.instanceid
                         WHERE l.userid = :userid
                         AND l.timecreated >= :start 
                         AND l.timecreated <= :end
                         AND ctx.contextlevel = 50
                         AND l.action IN ('viewed', 'graded', 'submitted', 'created', 'updated')";
        
        $engagementparams = [
            'userid' => $teacherid,
            'start' => $start,
            'end' => $end
        ];
        
        // Add school filter if needed
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $engagementsql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
            $engagementparams['companyid'] = $schoolid;
        }
        
        $engagement = $DB->get_field_sql($engagementsql, $engagementparams);
        $engagement = $engagement ? (int)$engagement : 0;
        
        // Calculate average student completion rate for this teacher's courses
        $completionsql = "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as completion_rate
                         FROM {course} c
                         JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                         JOIN {role_assignments} ra ON ra.contextid = ctx.id
                         LEFT JOIN {course_completions} cc ON cc.course = c.id
                         LEFT JOIN {role_assignments} ra2 ON ra2.userid = cc.userid AND ra2.contextid = ctx.id
                         WHERE ra.userid = :teacherid
                         AND ra.roleid = :teacherroleid
                         AND (cc.userid IS NULL OR ra2.roleid = :studentroleid)";
        
        $completionparams = [
            'teacherid' => $teacherid,
            'teacherroleid' => $teacherroleid,
            'studentroleid' => $studentroleid
        ];
        
        // Add school filter if needed
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $completionsql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid2)";
            $completionparams['companyid2'] = $schoolid;
        }
        
        $completion = $DB->get_field_sql($completionsql, $completionparams);
        $completion = $completion ? round($completion, 1) : 0;
        
        // Only include teachers with some data
        if ($engagement > 0 || $completion > 0) {
            $scatterData[] = [
                'x' => $engagement,
                'y' => $completion,
                'label' => fullname($teacher)
            ];
        }
    }
    
    return [
        'datasets' => [
            [
                'label' => 'Teachers',
                'data' => $scatterData,
                'backgroundColor' => 'rgba(52, 152, 219, 0.6)',
                'borderColor' => '#3498db',
                'borderWidth' => 2,
                'pointRadius' => 6,
                'pointHoverRadius' => 8
            ]
        ]
    ];
}

/**
 * Get comprehensive course analytics data
 */
function superreports_get_course_analytics($schoolid = 0, $cohortid = 0, $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    // KPI Summary
    $kpi = superreports_get_course_kpis($schoolid, $cohortid, $start, $end);
    
    // Top 10 Performing Courses
    $topCourses = superreports_get_top_performing_courses($schoolid, $cohortid, $start, $end);
    
    // Enrollment Trend (Line Chart)
    $enrollmentTrend = superreports_get_enrollment_trend($schoolid, $cohortid, $start, $end);
    
    // Completion vs Dropout (Stacked Bar)
    $completionVsDropout = superreports_get_completion_vs_dropout($schoolid, $cohortid, $start, $end);
    
    // Category Distribution (Donut)
    $categoryDistribution = superreports_get_category_distribution($schoolid, $cohortid);
    
    // Engagement vs Completion (Bubble)
    $engagementVsCompletion = superreports_get_engagement_vs_completion($schoolid, $cohortid, $start, $end);
    
    // Insights
    $insights = superreports_get_course_insights($schoolid, $cohortid, $start, $end);
    
    return [
        'kpi' => $kpi,
        'topCourses' => $topCourses,
        'enrollmentTrend' => $enrollmentTrend,
        'completionVsDropout' => $completionVsDropout,
        'categoryDistribution' => $categoryDistribution,
        'engagementVsCompletion' => $engagementVsCompletion,
        'insights' => $insights
    ];
}

/**
 * Get course KPIs
 */
function superreports_get_course_kpis($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    // Count active courses
    $coursesql = "SELECT COUNT(DISTINCT c.id) as count
                  FROM {course} c
                  WHERE c.id > 1";
    $courseparams = [];
    
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $coursesql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $courseparams['companyid'] = $schoolid;
    }
    
    $activeCourses = $DB->get_field_sql($coursesql, $courseparams);
    
    // Average enrollment per course
    $enrollsql = "SELECT AVG(enrollment_count) as avg_enrollment
                  FROM (
                      SELECT c.id, COUNT(DISTINCT ue.userid) as enrollment_count
                      FROM {course} c
                      JOIN {enrol} e ON e.courseid = c.id
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      WHERE c.id > 1";
    
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $enrollsql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = {$schoolid})";
    }
    
    $enrollsql .= " GROUP BY c.id
                  ) as enrollments";
    
    $avgEnrollment = $DB->get_field_sql($enrollsql, []);
    $avgEnrollment = $avgEnrollment ? round($avgEnrollment) : 0;
    
    // Average completion rate
    $completionsql = "SELECT AVG(completion_rate) as avg_completion
                      FROM (
                          SELECT c.id,
                                 CASE WHEN COUNT(cc.id) > 0 
                                 THEN (SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) / COUNT(cc.id)) * 100
                                 ELSE 0 END as completion_rate
                          FROM {course} c
                          LEFT JOIN {course_completions} cc ON cc.course = c.id
                          WHERE c.id > 1
                          GROUP BY c.id
                      ) as completions";
    
    $avgCompletion = $DB->get_field_sql($completionsql, []);
    $avgCompletion = $avgCompletion ? round($avgCompletion) : 0;
    
    // Average time to complete (in days)
    $timeSql = "SELECT AVG(DATEDIFF(FROM_UNIXTIME(cc.timecompleted), FROM_UNIXTIME(ue.timecreated))) as avg_days
                FROM {course_completions} cc
                JOIN {user_enrolments} ue ON ue.userid = cc.userid
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
                JOIN {course} c ON c.id = cc.course
                WHERE cc.timecompleted IS NOT NULL
                AND c.id > 1";
    
    $timeParams = [];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $timeSql .= " AND EXISTS (SELECT 1 FROM {company_course} ccc WHERE ccc.courseid = c.id AND ccc.companyid = :companyid)";
        $timeParams['companyid'] = $schoolid;
    }
    
    $avgTimeToComplete = $DB->get_field_sql($timeSql, $timeParams);
    $avgTimeToComplete = $avgTimeToComplete ? round($avgTimeToComplete) : 0;
    
    // Dropout rate (suspended enrollments / total enrollments)
    $totalEnrollSql = "SELECT COUNT(DISTINCT ue.id) as total
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                       JOIN {course} c ON c.id = e.courseid
                       WHERE c.id > 1";
    
    $droppedEnrollSql = "SELECT COUNT(DISTINCT ue.id) as dropped
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course} c ON c.id = e.courseid
                        WHERE c.id > 1
                        AND ue.status = 1";
    
    $dropParams = [];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $totalEnrollSql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $droppedEnrollSql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid2)";
        $dropParams['companyid'] = $schoolid;
        $dropParams['companyid2'] = $schoolid;
    }
    
    $totalEnroll = $DB->get_field_sql($totalEnrollSql, $dropParams);
    $droppedEnroll = $DB->get_field_sql($droppedEnrollSql, $dropParams);
    
    $dropoutRate = ($totalEnroll > 0) ? round(($droppedEnroll / $totalEnroll) * 100, 1) : 0;
    
    return [
        'active_courses' => $activeCourses ? $activeCourses : 0,
        'avg_enrollment' => $avgEnrollment,
        'avg_completion' => $avgCompletion,
        'avg_time_to_complete' => $avgTimeToComplete,
        'dropout_rate' => $dropoutRate
    ];
}

/**
 * Get top performing courses
 */
function superreports_get_top_performing_courses($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    $sql = "SELECT c.id, c.fullname as name,
                   AVG(gg.finalgrade / gi.grademax * 100) as avg_grade,
                   COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN cc.userid END) * 100.0 / NULLIF(COUNT(DISTINCT cc.userid), 0) as completion
            FROM {course} c
            LEFT JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
            LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id
            LEFT JOIN {course_completions} cc ON cc.course = c.id
            WHERE c.id > 1";
    
    $params = [];
    
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} ccc WHERE ccc.courseid = c.id AND ccc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    $sql .= " GROUP BY c.id, c.fullname
              HAVING AVG(gg.finalgrade / gi.grademax * 100) IS NOT NULL
              ORDER BY avg_grade DESC, completion DESC
              LIMIT 10";
    
    $courses = $DB->get_records_sql($sql, $params);
    
    $topCourses = [];
    foreach ($courses as $course) {
        $avgGrade = $course->avg_grade ? round($course->avg_grade) : 0;
        $completion = $course->completion ? round($course->completion) : 0;
        $score = round(($avgGrade + $completion) / 2); // Combined score
        
        $topCourses[] = [
            'name' => format_string($course->name),
            'avg_grade' => $avgGrade,
            'completion' => $completion,
            'score' => $score
        ];
    }
    
    return $topCourses;
}

/**
 * Get enrollment trend data
 */
function superreports_get_enrollment_trend($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    // Get last 10 months of data
    $months = [];
    $monthLabels = [];
    for ($i = 9; $i >= 0; $i--) {
        $monthTimestamp = strtotime("-$i months", $end);
        $months[] = [
            'start' => strtotime(date('Y-m-01', $monthTimestamp)),
            'end' => strtotime(date('Y-m-t', $monthTimestamp)) + 86399,
            'label' => date('M', $monthTimestamp)
        ];
        $monthLabels[] = date('M', $monthTimestamp);
    }
    
    $datasets = [];
    $colors = [
        ['border' => '#3498db', 'bg' => 'rgba(52, 152, 219, 0.1)'],
        ['border' => '#2ecc71', 'bg' => 'rgba(46, 204, 113, 0.1)'],
        ['border' => '#e67e22', 'bg' => 'rgba(230, 126, 34, 0.1)'],
        ['border' => '#9b59b6', 'bg' => 'rgba(155, 89, 182, 0.1)'],
        ['border' => '#f39c12', 'bg' => 'rgba(243, 156, 18, 0.1)']
    ];
    
    if ($schoolid > 0) {
        // Single school - show total enrollments
        $schoolName = $DB->get_field('company', 'name', ['id' => $schoolid]);
        $enrollmentData = [];
        
        foreach ($months as $month) {
            $sql = "SELECT COUNT(DISTINCT ue.id) as enrollment_count
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {course} c ON c.id = e.courseid
                    JOIN {company_course} cc ON cc.courseid = c.id
                    WHERE ue.timecreated >= :start AND ue.timecreated <= :end
                    AND cc.companyid = :companyid";
            
            $count = $DB->get_field_sql($sql, [
                'start' => $month['start'],
                'end' => $month['end'],
                'companyid' => $schoolid
            ]);
            
            $enrollmentData[] = $count ? (int)$count : 0;
        }
        
        $datasets[] = [
            'label' => format_string($schoolName),
            'data' => $enrollmentData,
            'borderColor' => $colors[0]['border'],
            'backgroundColor' => $colors[0]['bg'],
            'tension' => 0.4
        ];
    } else {
        // Multiple schools - show top 5 schools
        $schoolsql = "SELECT DISTINCT c.id, c.name
                     FROM {company} c
                     JOIN {company_course} cc ON cc.companyid = c.id
                     ORDER BY c.name
                     LIMIT 5";
        
        $schools = $DB->get_records_sql($schoolsql);
        $colorIndex = 0;
        
        foreach ($schools as $school) {
            $enrollmentData = [];
            
            foreach ($months as $month) {
                $sql = "SELECT COUNT(DISTINCT ue.id) as enrollment_count
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course} c ON c.id = e.courseid
                        JOIN {company_course} cc ON cc.courseid = c.id
                        WHERE ue.timecreated >= :start AND ue.timecreated <= :end
                        AND cc.companyid = :companyid";
                
                $count = $DB->get_field_sql($sql, [
                    'start' => $month['start'],
                    'end' => $month['end'],
                    'companyid' => $school->id
                ]);
                
                $enrollmentData[] = $count ? (int)$count : 0;
            }
            
            $datasets[] = [
                'label' => format_string($school->name),
                'data' => $enrollmentData,
                'borderColor' => $colors[$colorIndex]['border'],
                'backgroundColor' => $colors[$colorIndex]['bg'],
                'tension' => 0.4
            ];
            
            $colorIndex = ($colorIndex + 1) % count($colors);
        }
    }
    
    return [
        'labels' => $monthLabels,
        'datasets' => $datasets
    ];
}

/**
 * Get completion vs dropout data
 */
function superreports_get_completion_vs_dropout($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    $labels = [];
    $completedData = [];
    $inProgressData = [];
    $droppedData = [];
    
    if ($schoolid > 0) {
        // Single school
        $school = $DB->get_record('company', ['id' => $schoolid], 'id, name');
        if ($school) {
            $labels[] = format_string($school->name);
            
            // Get total enrollments for this school
            $totalSql = "SELECT COUNT(DISTINCT ue.userid) as total
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course} c ON c.id = e.courseid
                        JOIN {company_course} cc ON cc.courseid = c.id
                        WHERE cc.companyid = :companyid
                        AND c.id > 1";
            
            $total = $DB->get_field_sql($totalSql, ['companyid' => $schoolid]);
            $total = $total ? (int)$total : 1; // Avoid division by zero
            
            // Completed
            $completedSql = "SELECT COUNT(DISTINCT cc.userid) as completed
                            FROM {course_completions} cc
                            JOIN {course} c ON c.id = cc.course
                            JOIN {company_course} ccc ON ccc.courseid = c.id
                            WHERE cc.timecompleted IS NOT NULL
                            AND ccc.companyid = :companyid";
            
            $completed = $DB->get_field_sql($completedSql, ['companyid' => $schoolid]);
            $completedData[] = round(($completed / $total) * 100, 1);
            
            // In Progress (enrolled but not completed)
            $inProgressSql = "SELECT COUNT(DISTINCT ue.userid) as inprogress
                             FROM {user_enrolments} ue
                             JOIN {enrol} e ON e.id = ue.enrolid
                             JOIN {course} c ON c.id = e.courseid
                             JOIN {company_course} cc ON cc.courseid = c.id
                             WHERE cc.companyid = :companyid
                             AND c.id > 1
                             AND NOT EXISTS (
                                 SELECT 1 FROM {course_completions} ccc
                                 WHERE ccc.userid = ue.userid
                                 AND ccc.course = c.id
                                 AND ccc.timecompleted IS NOT NULL
                             )
                             AND ue.status = 0";
            
            $inProgress = $DB->get_field_sql($inProgressSql, ['companyid' => $schoolid]);
            $inProgressData[] = round(($inProgress / $total) * 100, 1);
            
            // Dropped (suspended enrollments)
            $droppedSql = "SELECT COUNT(DISTINCT ue.userid) as dropped
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          JOIN {company_course} cc ON cc.courseid = c.id
                          WHERE cc.companyid = :companyid
                          AND c.id > 1
                          AND ue.status = 1";
            
            $dropped = $DB->get_field_sql($droppedSql, ['companyid' => $schoolid]);
            $droppedData[] = round(($dropped / $total) * 100, 1);
        }
    } else {
        // Multiple schools - show top 5
        $schoolsql = "SELECT DISTINCT c.id, c.name
                     FROM {company} c
                     JOIN {company_course} cc ON cc.companyid = c.id
                     ORDER BY c.name
                     LIMIT 5";
        
        $schools = $DB->get_records_sql($schoolsql);
        
        foreach ($schools as $school) {
            $labels[] = format_string($school->name);
            
            // Get total enrollments for this school
            $totalSql = "SELECT COUNT(DISTINCT ue.userid) as total
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course} c ON c.id = e.courseid
                        JOIN {company_course} cc ON cc.courseid = c.id
                        WHERE cc.companyid = :companyid
                        AND c.id > 1";
            
            $total = $DB->get_field_sql($totalSql, ['companyid' => $school->id]);
            $total = $total ? (int)$total : 1;
            
            // Completed
            $completedSql = "SELECT COUNT(DISTINCT cc.userid) as completed
                            FROM {course_completions} cc
                            JOIN {course} c ON c.id = cc.course
                            JOIN {company_course} ccc ON ccc.courseid = c.id
                            WHERE cc.timecompleted IS NOT NULL
                            AND ccc.companyid = :companyid";
            
            $completed = $DB->get_field_sql($completedSql, ['companyid' => $school->id]);
            $completedData[] = round(($completed / $total) * 100, 1);
            
            // In Progress
            $inProgressSql = "SELECT COUNT(DISTINCT ue.userid) as inprogress
                             FROM {user_enrolments} ue
                             JOIN {enrol} e ON e.id = ue.enrolid
                             JOIN {course} c ON c.id = e.courseid
                             JOIN {company_course} cc ON cc.courseid = c.id
                             WHERE cc.companyid = :companyid
                             AND c.id > 1
                             AND NOT EXISTS (
                                 SELECT 1 FROM {course_completions} ccc
                                 WHERE ccc.userid = ue.userid
                                 AND ccc.course = c.id
                                 AND ccc.timecompleted IS NOT NULL
                             )
                             AND ue.status = 0";
            
            $inProgress = $DB->get_field_sql($inProgressSql, ['companyid' => $school->id]);
            $inProgressData[] = round(($inProgress / $total) * 100, 1);
            
            // Dropped
            $droppedSql = "SELECT COUNT(DISTINCT ue.userid) as dropped
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          JOIN {company_course} cc ON cc.courseid = c.id
                          WHERE cc.companyid = :companyid
                          AND c.id > 1
                          AND ue.status = 1";
            
            $dropped = $DB->get_field_sql($droppedSql, ['companyid' => $school->id]);
            $droppedData[] = round(($dropped / $total) * 100, 1);
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completed',
                'data' => $completedData,
                'backgroundColor' => '#3498db'
            ],
            [
                'label' => 'In Progress',
                'data' => $inProgressData,
                'backgroundColor' => '#e67e22'
            ],
            [
                'label' => 'Dropped',
                'data' => $droppedData,
                'backgroundColor' => '#e74c3c'
            ]
        ]
    ];
}

/**
 * Get category distribution
 */
function superreports_get_category_distribution($schoolid, $cohortid) {
    global $DB;
    
    $sql = "SELECT cat.name, COUNT(c.id) as course_count
            FROM {course} c
            JOIN {course_categories} cat ON cat.id = c.category
            WHERE c.id > 1";
    
    $params = [];
    
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    $sql .= " GROUP BY cat.id, cat.name
              ORDER BY course_count DESC
              LIMIT 5";
    
    $categories = $DB->get_records_sql($sql, $params);
    
    $labels = [];
    $data = [];
    $total = 0;
    
    foreach ($categories as $cat) {
        $labels[] = format_string($cat->name);
        $data[] = (int)$cat->course_count;
        $total += (int)$cat->course_count;
    }
    
    // Convert to percentages
    $percentages = [];
    foreach ($data as $value) {
        $percentages[] = $total > 0 ? round(($value / $total) * 100) : 0;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'data' => $percentages,
                'backgroundColor' => [
                    'rgba(52, 152, 219, 0.8)',
                    'rgba(26, 188, 156, 0.8)',
                    'rgba(46, 204, 113, 0.8)',
                    'rgba(230, 126, 34, 0.8)',
                    'rgba(231, 76, 60, 0.8)'
                ],
                'borderColor' => ['#3498db', '#1abc9c', '#2ecc71', '#e67e22', '#e74c3c'],
                'borderWidth' => 2
            ]
        ]
    ];
}

/**
 * Get engagement vs completion bubble data
 */
function superreports_get_engagement_vs_completion($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    $sql = "SELECT c.id, c.fullname,
                   COUNT(DISTINCT l.userid) as engagement_count,
                   COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN cc.userid END) * 100.0 / NULLIF(COUNT(DISTINCT cc.userid), 0) as completion_rate,
                   COUNT(DISTINCT ue.userid) as enrolled
            FROM {course} c
            LEFT JOIN {logstore_standard_log} l ON l.courseid = c.id
            LEFT JOIN {course_completions} cc ON cc.course = c.id
            LEFT JOIN {enrol} e ON e.courseid = c.id
            LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
            WHERE c.id > 1";
    
    $params = [];
    
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} ccc WHERE ccc.courseid = c.id AND ccc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    $sql .= " GROUP BY c.id, c.fullname
              HAVING COUNT(DISTINCT ue.userid) > 0
              ORDER BY RAND()
              LIMIT 10";
    
    $courses = $DB->get_records_sql($sql, $params);
    
    $bubbles = [];
    $colors = ['#3498db', '#2ecc71', '#e67e22', '#9b59b6', '#1abc9c', '#e74c3c', '#f39c12', '#34495e'];
    $colorIndex = 0;
    
    foreach ($courses as $course) {
        $enrolled = (int)$course->enrolled;
        $engagement = $enrolled > 0 ? round((($course->engagement_count / $enrolled) * 100) / 10) : 0; // Scaled down
        $completion = $course->completion_rate ? round($course->completion_rate) : 0;
        
        if ($engagement > 0 && $completion > 0) {
            $bubbles[] = [
                'x' => min($engagement, 100),
                'y' => $completion,
                'r' => max(5, min($enrolled / 5, 20)), // Bubble size
                'label' => format_string($course->fullname)
            ];
        }
        
        $colorIndex = ($colorIndex + 1) % count($colors);
    }
    
    return [
        'datasets' => [
            [
                'label' => 'Courses',
                'data' => $bubbles,
                'backgroundColor' => array_map(function($color) {
                    return $color . '80'; // Add transparency
                }, array_slice($colors, 0, count($bubbles)))
            ]
        ]
    ];
}

/**
 * Get course insights
 */
function superreports_get_course_insights($schoolid, $cohortid, $start, $end) {
    global $DB;
    
    $insights = [];
    
    // Insight 1: Category with highest completion rate
    $categorySql = "SELECT cat.name, 
                           AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END) as avg_completion
                    FROM {course} c
                    JOIN {course_categories} cat ON cat.id = c.category
                    LEFT JOIN {course_completions} cc ON cc.course = c.id
                    WHERE c.id > 1";
    
    $categoryParams = [];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $categorySql .= " AND EXISTS (SELECT 1 FROM {company_course} ccc WHERE ccc.courseid = c.id AND ccc.companyid = :companyid)";
        $categoryParams['companyid'] = $schoolid;
    }
    
    $categorySql .= " GROUP BY cat.id, cat.name
                     HAVING COUNT(cc.id) > 0
                     ORDER BY avg_completion DESC
                     LIMIT 1";
    
    $topCategory = $DB->get_record_sql($categorySql, $categoryParams);
    if ($topCategory && $topCategory->avg_completion > 0) {
        $avgAll = $DB->get_field_sql(
            "SELECT AVG(CASE WHEN cc.timecompleted IS NOT NULL THEN 100 ELSE 0 END)
             FROM {course_completions} cc
             JOIN {course} c ON c.id = cc.course
             WHERE c.id > 1",
            []
        );
        $difference = round($topCategory->avg_completion - $avgAll, 0);
        if ($difference > 0) {
            $insights[] = format_string($topCategory->name) . " courses show {$difference}% higher completion than average.";
        }
    }
    
    // Insight 2: Course with most new enrollments
    $enrollmentSql = "SELECT c.fullname, COUNT(DISTINCT ue.id) as enrollment_count
                      FROM {course} c
                      JOIN {enrol} e ON e.courseid = c.id
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      WHERE c.id > 1
                      AND ue.timecreated >= :start AND ue.timecreated <= :end";
    
    $enrollmentParams = ['start' => $start, 'end' => $end];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $enrollmentSql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $enrollmentParams['companyid'] = $schoolid;
    }
    
    $enrollmentSql .= " GROUP BY c.id, c.fullname
                       ORDER BY enrollment_count DESC
                       LIMIT 1";
    
    $topEnrollment = $DB->get_record_sql($enrollmentSql, $enrollmentParams);
    if ($topEnrollment && $topEnrollment->enrollment_count > 0) {
        $insights[] = 'Course "' . format_string($topEnrollment->fullname) . '" recorded ' . $topEnrollment->enrollment_count . ' new enrollments this period.';
    }
    
    // Insight 3: Dropout trend analysis
    $dropoutCurrentSql = "SELECT COUNT(DISTINCT ue.userid) as dropped
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          WHERE c.id > 1
                          AND ue.status = 1
                          AND ue.timemodified >= :start AND ue.timemodified <= :end";
    
    $dropoutParams = ['start' => $start, 'end' => $end];
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $dropoutCurrentSql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $dropoutParams['companyid'] = $schoolid;
    }
    
    $currentDropouts = $DB->get_field_sql($dropoutCurrentSql, $dropoutParams);
    
    // Previous period
    $duration = $end - $start;
    $prevStart = $start - $duration;
    $prevEnd = $start;
    
    $dropoutPrevSql = str_replace([':start', ':end'], [':prevstart', ':prevend'], $dropoutCurrentSql);
    $dropoutPrevParams = $dropoutParams;
    $dropoutPrevParams['prevstart'] = $prevStart;
    $dropoutPrevParams['prevend'] = $prevEnd;
    unset($dropoutPrevParams['start']);
    unset($dropoutPrevParams['end']);
    
    $prevDropouts = $DB->get_field_sql($dropoutPrevSql, $dropoutPrevParams);
    
    if ($prevDropouts > 0 && $currentDropouts > 0) {
        $dropoutChange = round((($currentDropouts - $prevDropouts) / $prevDropouts) * 100, 0);
        if ($dropoutChange > 0) {
            $insights[] = "Dropouts increased by +{$dropoutChange}% compared to previous period.";
        } elseif ($dropoutChange < 0) {
            $insights[] = "Dropouts decreased by " . abs($dropoutChange) . "% compared to previous period.";
        } else {
            $insights[] = "Dropout rates remain stable across the selected period.";
        }
    }
    
    // If no insights generated, add a default message
    if (empty($insights)) {
        $insights[] = "Insufficient data available for insights generation.";
    }
    
    return $insights;
}

/**
 * Get course completion brackets for donut chart
 */
function superreports_get_course_completion_brackets($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        return [
            'labels' => ['0-50%', '50-80%', '80-100%'],
            'datasets' => [
                [
                    'data' => [0, 0, 0],
                    'backgroundColor' => [
                        'rgba(231, 76, 60, 0.8)',
                        'rgba(243, 156, 18, 0.8)',
                        'rgba(46, 204, 113, 0.8)'
                    ],
                    'borderColor' => ['#e74c3c', '#f39c12', '#2ecc71'],
                    'borderWidth' => 2
                ]
            ]
        ];
    }
    
    // Get students with role assignments
    $studentsql = "SELECT DISTINCT ra.userid
                   FROM {role_assignments} ra
                   WHERE ra.roleid = :roleid";
    $studentparams = ['roleid' => $studentroleid];
    
    // Apply cohort filter
    if (!empty($gradeid)) {
        $studentsql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = ra.userid AND cm.cohortid = :cohortid)";
        $studentparams['cohortid'] = $gradeid;
    }
    
    $studentids = $DB->get_fieldset_sql($studentsql, $studentparams);
    
    if (empty($studentids)) {
        return [
            'labels' => ['0-50%', '50-80%', '80-100%'],
            'datasets' => [
                [
                    'data' => [0, 0, 0],
                    'backgroundColor' => [
                        'rgba(231, 76, 60, 0.8)',
                        'rgba(243, 156, 18, 0.8)',
                        'rgba(46, 204, 113, 0.8)'
                    ],
                    'borderColor' => ['#e74c3c', '#f39c12', '#2ecc71'],
                    'borderWidth' => 2
                ]
            ]
        ];
    }
    
    // Count students in each bracket
    $bracket0_50 = 0;
    $bracket50_80 = 0;
    $bracket80_100 = 0;
    
    // Process each student
    foreach ($studentids as $userid) {
        // Get total enrolled courses
        $enrollsql = "SELECT COUNT(DISTINCT c.id) as total
                      FROM {course} c
                      JOIN {enrol} e ON e.courseid = c.id
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      WHERE ue.userid = :userid
                      AND ue.status = 0
                      AND c.id != 1";
        $enrollparams = ['userid' => $userid];
        
        if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $enrollsql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
            $enrollparams['companyid'] = $schoolid;
        }
        
        $totalcourses = $DB->get_field_sql($enrollsql, $enrollparams);
        
        if ($totalcourses > 0) {
            // Get completed courses
            $completesql = "SELECT COUNT(DISTINCT cc.course) as completed
                           FROM {course_completions} cc
                           JOIN {course} c ON c.id = cc.course
                           WHERE cc.userid = :userid
                           AND cc.timecompleted IS NOT NULL";
            $completeparams = ['userid' => $userid];
            
            if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
                $completesql .= " AND EXISTS (SELECT 1 FROM {company_course} cc2 WHERE cc2.courseid = c.id AND cc2.companyid = :companyid)";
                $completeparams['companyid'] = $schoolid;
            }
            
            $completedcourses = $DB->get_field_sql($completesql, $completeparams);
            $completedcourses = $completedcourses ? $completedcourses : 0;
            
            $completion_percentage = ($completedcourses / $totalcourses) * 100;
            
            if ($completion_percentage < 50) {
                $bracket0_50++;
            } elseif ($completion_percentage < 80) {
                $bracket50_80++;
            } else {
                $bracket80_100++;
            }
        }
    }
    
    return [
        'labels' => ['0-50%', '50-80%', '80-100%'],
        'datasets' => [
            [
                'data' => [$bracket0_50, $bracket50_80, $bracket80_100],
                'backgroundColor' => [
                    'rgba(231, 76, 60, 0.8)',   // Red for low completion
                    'rgba(243, 156, 18, 0.8)',   // Orange for medium completion
                    'rgba(46, 204, 113, 0.8)'    // Green for high completion
                ],
                'borderColor' => [
                    '#e74c3c',
                    '#f39c12',
                    '#2ecc71'
                ],
                'borderWidth' => 2
            ]
        ]
    ];
}

/**
 * Get learning activity heatmap data by day/time
 */
function superreports_get_learning_activity_heatmap($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    // Get learning activity data grouped by day of week and hour
    $sql = "SELECT 
                DAYOFWEEK(FROM_UNIXTIME(l.timecreated)) as day_num,
                HOUR(FROM_UNIXTIME(l.timecreated)) as activity_hour,
                COUNT(l.id) as activity_count
            FROM {logstore_standard_log} l
            JOIN {context} ctx ON ctx.id = l.contextid
            JOIN {course} c ON c.id = ctx.instanceid
            WHERE l.timecreated >= :start AND l.timecreated <= :end
            AND ctx.contextlevel = 50
            AND l.action IN ('viewed', 'submitted', 'created', 'updated')
            AND EXISTS (SELECT 1 FROM {role_assignments} ra 
                       WHERE ra.userid = l.userid AND ra.roleid = :studentroleid)";
    
    $params = [
        'start' => $start,
        'end' => $end,
        'studentroleid' => $studentroleid
    ];
    
    // Filter by school if specified
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_course')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc WHERE cc.courseid = c.id AND cc.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort if specified
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = l.userid AND cm.cohortid = :cohortid)";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY day_num, activity_hour
              ORDER BY activity_hour";
    
    $activities = $DB->get_records_sql($sql, $params);
    
    // Prepare heatmap data
    // DAYOFWEEK returns 1=Sunday, 2=Monday, 3=Tuesday, 4=Wednesday, 5=Thursday, 6=Friday, 7=Saturday
    $dayNumMapping = [
        1 => 'Sun',
        2 => 'Mon',
        3 => 'Tue',
        4 => 'Wed',
        5 => 'Thu',
        6 => 'Fri',
        7 => 'Sat'
    ];
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $hours = [];
    for ($i = 0; $i < 24; $i++) {
        $hours[] = sprintf('%02d:00', $i);
    }
    
    // Initialize heatmap data
    $heatmapData = [];
    foreach ($days as $day) {
        $heatmapData[$day] = [];
        foreach ($hours as $hour) {
            $heatmapData[$day][$hour] = 0;
        }
    }
    
    // Fill in actual data
    foreach ($activities as $activity) {
        $dayNum = (int)$activity->day_num;
        $dayShort = isset($dayNumMapping[$dayNum]) ? $dayNumMapping[$dayNum] : 'Mon';
        $hour = sprintf('%02d:00', $activity->activity_hour);
        
        if (isset($heatmapData[$dayShort][$hour])) {
            $heatmapData[$dayShort][$hour] += (int)$activity->activity_count;
        }
    }
    
    // Convert to Chart.js format
    $datasets = [];
    foreach ($days as $day) {
        $data = [];
        foreach ($hours as $hour) {
            $data[] = $heatmapData[$day][$hour];
        }
        $datasets[] = [
            'label' => $day,
            'data' => $data,
            'backgroundColor' => 'rgba(52, 152, 219, 0.6)',
            'borderColor' => '#3498db',
            'borderWidth' => 1
        ];
    }
    
    return [
        'labels' => $hours,
        'datasets' => $datasets
    ];
}

/**
 * Get detailed student performance data
 */
function superreports_get_student_performance_detailed($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        return [];
    }
    
    // Get student statistics
    $sql = "SELECT u.id, u.firstname, u.lastname,
                   COUNT(DISTINCT ue.enrolid) as enrolled,
                   AVG(gg.finalgrade / gi.grademax * 100) as avg_grade,
                   COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN cc.course END) as completed,
                   COUNT(DISTINCT cc.course) as total_courses
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            LEFT JOIN {user_enrolments} ue ON ue.userid = u.id
            LEFT JOIN {grade_grades} gg ON gg.userid = u.id
            LEFT JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
            LEFT JOIN {course_completions} cc ON cc.userid = u.id
            WHERE ra.roleid = :roleid";
    
    $params = ['roleid' => $studentroleid];
    
    // Filter by school
    if ($schoolid > 0 && $DB->get_manager()->table_exists('company_users')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {company_users} cu WHERE cu.userid = u.id AND cu.companyid = :companyid)";
        $params['companyid'] = $schoolid;
    }
    
    // Filter by cohort
    if (!empty($gradeid) && $DB->get_manager()->table_exists('cohort_members')) {
        $sql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = u.id AND cm.cohortid = :cohortid)";
        $params['cohortid'] = $gradeid;
    }
    
    $sql .= " GROUP BY u.id, u.firstname, u.lastname LIMIT 100";
    
    $students = $DB->get_records_sql($sql, $params);
    
    $data = [];
    foreach ($students as $student) {
        $completionrate = $student->total_courses > 0 ? round(($student->completed / $student->total_courses) * 100, 1) : 0;
        $status = $completionrate >= 80 ? 'active' : ($completionrate >= 50 ? 'warning' : 'inactive');
        
        $data[] = [
            'name' => fullname($student),
            'enrolled' => $student->enrolled,
            'avg_grade' => $student->avg_grade ? round($student->avg_grade, 1) : 0,
            'completion' => $completionrate,
            'status' => $status
        ];
    }
    
    return $data;
}

/**
 * Generate AI-powered insights summary
 */
function superreports_get_ai_insights($schoolid = 0, $gradeid = '', $daterange = 'month', $startdate = null, $enddate = null) {
    global $DB;
    
    list($start, $end) = superreports_get_date_range($daterange, $startdate, $enddate);
    
    $insights = [];
    
    // Get assignment completion trend
    $assignments = superreports_get_assignments_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
    if ($assignments['completion_rate'] > 0) {
        if ($assignments['completion_rate'] >= 80) {
            $insights[] = "📈 Excellent assignment completion rate of {$assignments['completion_rate']}% indicates strong student engagement.";
        } else if ($assignments['completion_rate'] >= 60) {
            $insights[] = "📊 Assignment completion rate is {$assignments['completion_rate']}%. Consider additional support strategies.";
        } else {
            $insights[] = "⚠️ Assignment completion rate of {$assignments['completion_rate']}% requires immediate attention.";
        }
    }
    
    // Get quiz performance
    $quizzes = superreports_get_quizzes_overview($schoolid, $gradeid, $daterange, $startdate, $enddate);
    if ($quizzes['avg_score'] > 0) {
        if ($quizzes['avg_score'] >= 80) {
            $insights[] = "🎯 Outstanding quiz performance with an average score of {$quizzes['avg_score']}%.";
        } else {
            $insights[] = "📝 Average quiz score is {$quizzes['avg_score']}%. Focus on targeted interventions.";
        }
    }
    
    // Get overall grades
    $grades = superreports_get_overall_grades($schoolid, $gradeid, $daterange, $startdate, $enddate);
    if ($grades['system_avg'] > 0) {
        $insights[] = "🎓 System-wide average grade stands at {$grades['system_avg']}%.";
    }
    
    // Get teacher engagement changes
    $teachers = superreports_get_teacher_performance($schoolid, $daterange, $startdate, $enddate);
    $improvedteachers = 0;
    foreach ($teachers as $teacher) {
        if ($teacher['change'] > 10) {
            $improvedteachers++;
        }
    }
    if ($improvedteachers > 0) {
        $insights[] = "👨‍🏫 {$improvedteachers} teachers showed significant improvement in engagement this period.";
    }
    
    // Activity trends
    $stats = superreports_get_overview_stats($schoolid, $daterange, $startdate, $enddate);
    if ($stats['active_users'] > 0) {
        $engagementrate = $stats['total_students'] > 0 ? round(($stats['active_users'] / $stats['total_students']) * 100, 1) : 0;
        $insights[] = "💡 {$engagementrate}% of students have been active during the selected period.";
    }
    
    return $insights;
}

